<?php
/**
 * 翻译模块
 * DeepSeek API 调用 + 术语后处理 + REST API 推送到远程站
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Translate {

    use Lisun_Manual_Nonce_Trait;

    const API_URL = 'https://api.deepseek.com/chat/completions';
    const MAX_RETRIES = 3;
    const SEGMENT_MAX_CHARS = 2000;
    // processing 任务超过此时长（且期间无心跳续租）才判定为卡死。必须大于单篇最长翻译时间，
    // 否则长文档翻译还没结束就被误判重置，导致同一篇被反复/并发翻译，耗尽 FPM 打崩源站。
    const WORKER_STALE_SECONDS = 1800;
    // 同一时刻最多并发翻译的 worker 数（MySQL advisory lock 限流，失败时自动降级为不限流）。
    const WORKER_MAX_CONCURRENCY = 3;
    // Worker 触发请求只等待“已收到并开始处理”的快速响应；真正翻译在响应后继续执行。
    const WORKER_TRIGGER_TIMEOUT = 1;
    // DeepL 文本翻译请求体的安全上限。HTML 模式下不能为了凑长度硬切标签，
    // 因此把 128 KiB 官方上限留出 JSON/参数余量，超出时严格报错。
    const DEEPL_HTML_REQUEST_MAX_BYTES = 120000;
    // 一个请求中的占位锚点数量预算。超过预算时只在完整子节点边界拆分，
    // 防止大段高密度内联标签让翻译引擎丢失 x 标签。
    // DeepL may silently drop one of several protected XML tags even when the
    // request is HTTP 200.  A request containing more than one plugin anchor
    // is therefore never sent: the splitter must isolate anchors first.
    const DEEPL_HTML_MAX_ANCHORS = 1;
    const DEEPL_HTML_INLINE_ANCHORS_PER_REQUEST = 1;
    const DEEPL_HTML_MAX_RETRY_DEPTH = 12;

    private static $progress_post_id = 0;
    private static $force_retranslate = false;
    private static $current_job_id = 0;   // 当前队列任务 id，用于翻译期间心跳续租
    private static $last_heartbeat = 0;    // 心跳节流时间戳
    private static $worker_slot = null;    // 当前占用的并发槽位（advisory lock 名）
    private static $deepl_chars_used = 0;  // 本次翻译累计的 DeepL 字符数（token 引擎不计）
    private static $last_anchored_title = '';
    private static $last_anchored_seo = [];
    private static $last_llm_error = '';
    private static $last_llm_response = '';
    private static $queue_schema_checked = false;

    /**
     * V2 的源文指纹包含标题和正文；不能只依赖旧版 content_hash。
     * 返回 null 表示当前文章没有可比较的 V2 活跃版本。
     */
    private static function v2_source_matches_active_revision($post, $allow_semantic_compare = false) {
        if (!self::translation_v2_enabled() || !is_object($post)) {
            return null;
        }

        // save_post 触发频率高，先只查文档/版本表的 hash；只有调用方明确
        // 允许语义兜底时，才在 raw hash 不一致的情况下加载翻译单元。
        // 这样正常保存仍是一次轻量查询，格式化误差也不会误降级状态。
        global $wpdb;
        $documents = $wpdb->prefix . 'lisun_translation_documents';
        $revisions = $wpdb->prefix . 'lisun_translation_revisions';
        $active_hash = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT r.source_hash
             FROM `{$documents}` d
             INNER JOIN `{$revisions}` r ON r.id = d.active_revision_id
             WHERE d.source_post_id = %d AND r.status = 'active'
             LIMIT 1",
            (int) $post->ID
        ));
        if ($active_hash === '') return null;

        $current_hash = hash(
            'sha256',
            (string) $post->post_content . "\nTITLE\n" . (string) $post->post_title
        );
        if (hash_equals($active_hash, $current_hash)) return true;
        if (!$allow_semantic_compare || !class_exists('Lisun_Manual_V2_Runner')) {
            self::debug_log('V2 source raw mismatch post=' . (int) $post->ID
                . ' reason=semantic_compare_skipped active_hash=' . $active_hash
                . ' current_hash=' . $current_hash);
            return false;
        }

        $comparison = Lisun_Manual_V2_Runner::compare_source_to_active_revision(
            (int) $post->ID,
            (string) $post->post_content,
            (string) $post->post_title
        );
        if (!empty($comparison['matches'])) {
            self::debug_log('V2 source accepted as semantically equivalent post=' . (int) $post->ID
                . ' reason=' . (string) ($comparison['reason'] ?? 'format_only')
                . ' active_hash=' . $active_hash
                . ' current_hash=' . $current_hash);
            return true;
        }
        self::debug_log('V2 source mismatch post=' . (int) $post->ID
            . ' reason=' . (string) ($comparison['reason'] ?? 'source_changed')
            . ' active_hash=' . $active_hash
            . ' current_hash=' . $current_hash);
        return false;
    }

    /** 当前文章是否还有未完成的队列任务。 */
    private static function has_active_translation_job($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';
        $job = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE post_id = %d AND status IN ('pending', 'processing') LIMIT 1",
            (int) $post_id
        ));
        return !empty($job);
    }

    /**
     * Return the page status that must survive a failed later attempt.
     * Queue failure belongs to the attempt, not automatically to the page.
     */
    private static function status_after_translation_failure($post_id, $failure_code = '', $failure_message = '') {
        $failure_code = (string) $failure_code;
        $failure_message = (string) $failure_message;
        if ($failure_message === '') {
            $failure_message = (string) get_post_meta($post_id, '_lisun_manual_last_error', true);
        }
        if ($failure_code === 'v2_source_changed'
            || strpos($failure_message, '中文源文已变化') !== false) {
            return 'failed';
        }
        $current = (string) get_post_meta($post_id, '_lisun_manual_sync_status', true);
        if (in_array($current, ['synced', 'translated', 'push_failed'], true)) {
            return $current;
        }

        if (class_exists('Lisun_Manual_V2_Repository')) {
            $active = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
            if (is_array($active) && !empty($active['revision']['id'])) {
                return 'synced';
            }
        }

        $backup = self::get_translation_backup($post_id);
        if (is_array($backup)
            && ((string) ($backup['en_content'] ?? '') !== ''
                || (string) ($backup['en_title'] ?? '') !== '')) {
            return 'synced';
        }

        $target_id = absint(get_post_meta($post_id, '_lisun_manual_target_id', true));
        if ($target_id && get_post($target_id)) {
            return 'synced';
        }

        return 'failed';
    }

    /**
     * Repair old queue failures that incorrectly hid an already valid translation.
     * The queue attempt remains failed; only the page-level display status is restored.
     */
    public static function repair_existing_translation_status($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_meta($post_id, '_lisun_manual_sync_status', true) !== 'failed') {
            return false;
        }

        $page_status = self::status_after_translation_failure($post_id);
        if ($page_status === 'failed') return false;

        update_post_meta($post_id, '_lisun_manual_sync_status', $page_status);
        if ($page_status === 'synced' && class_exists('Lisun_Manual_V2_Repository')) {
            Lisun_Manual_V2_Repository::update_document_state($post_id, 'synced', null, '');
        }
        self::debug_log('repaired old translation failure post=' . $post_id
            . ' restored_status=' . $page_status);
        return true;
    }

    /**
     * Repair the page-level status written by an older glossary worker.
     *
     * A glossary audit can be incomplete after the English revision was
     * already committed and pushed. Older workers stored that audit as a
     * generic translation failure, which wrongly removed the article from
     * the translated list. Only a committed partial result is repaired;
     * source-change, API, local-save, and push failures remain failures.
     */
    public static function repair_glossary_warning_status($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_meta($post_id, '_lisun_manual_sync_status', true) !== 'failed') {
            return false;
        }
        $post = get_post($post_id);
        if (!$post) return false;

        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT result_json, error_msg FROM `{$table}`
             WHERE post_id = %d AND action = 'glossary_apply'
             ORDER BY id DESC LIMIT 1",
            $post_id
        ), ARRAY_A);
        if (!$row) return false;
        $result = json_decode((string) ($row['result_json'] ?? ''), true);
        $result = is_array($result) ? $result : [];
        $source_matches = self::v2_source_matches_active_revision($post);
        if ($source_matches !== true) {
            // Do not reuse an old partial-application warning after the source
            // changed. The latest queue error is the authoritative reason and
            // must replace the stale post-meta message shown in the editor.
            $latest_code = (string) ($result['code'] ?? '');
            $latest_message = (string) (($result['message'] ?? '') ?: ($row['error_msg'] ?? ''));
            $old_error = (string) get_post_meta($post_id, '_lisun_manual_last_error', true);
            $old_partial = [];
            preg_match('/实际只应用了\s*(\d+)\s*\/\s*(\d+)/u', $old_error, $old_partial);
            if ($latest_code === 'v2_source_changed'
                || strpos($latest_message, '中文源文已变化') !== false) {
                // Use the queue's exact error when available. If an older
                // record only left the 25/36 wording in post meta, retain the
                // counts as context but never present them as the current
                // article status after the source changed.
                if ($latest_message === '') {
                    $latest_message = '中文源文已变化，请先重新翻译';
                }
                if ($old_partial) {
                    $latest_message .= '（旧术语应用记录 ' . (int) $old_partial[1]
                        . '/' . (int) $old_partial[2] . '，不代表当前译文状态）';
                }
                if ($old_error !== $latest_message) {
                    update_post_meta($post_id, '_lisun_manual_last_error', $latest_message);
                    delete_post_meta($post_id, '_lisun_manual_glossary_warning');
                }
            } elseif ($old_partial) {
                // Even if an old queue row has incomplete JSON, the stored
                // 25/36 failure text is stale once the V2 source no longer
                // matches. Replace it instead of leaving a misleading error
                // forever in the editor metabox.
                $normalized = '中文源文已变化，请先重新翻译（旧术语应用记录 '
                    . (int) $old_partial[1] . '/' . (int) $old_partial[2]
                    . '，不代表当前译文状态）';
                if ($old_error !== $normalized) {
                    update_post_meta($post_id, '_lisun_manual_last_error', $normalized);
                    delete_post_meta($post_id, '_lisun_manual_glossary_warning');
                }
            }
            return false;
        }
        $audit = is_array($result['post_apply_audit'] ?? null) ? $result['post_apply_audit'] : [];
        $expected = (int) ($result['expected_occurrences'] ?? ($audit['hit_count'] ?? 0));
        $visible = (int) ($result['visible_applied_occurrences']
            ?? ($audit['visible_applied_count'] ?? ($result['applied_occurrences'] ?? 0)));
        $missing = (int) ($audit['visible_missing_count'] ?? ($audit['unapplied_count'] ?? 0));
        $hard_failure = !empty($audit['term_count_failure'])
            || ($expected > 0 && $visible === 0 && $missing >= $expected);
        $partial_warning = $expected > 0 && $visible > 0 && $visible < $expected;
        $committed = !empty($result['pushed'])
            || (!empty($result['revision_id']) && (!empty($result['attempted']) || $partial_warning));
        if ($hard_failure || !$partial_warning || !$committed) return false;

        $stable_anchor = (int) ($audit['stable_anchor_count'] ?? ($result['stable_anchor_occurrences'] ?? 0));
        $warning = [
            'message' => '术语应用已完成：可见英文术语 ' . $visible . '/' . $expected
                . '；稳定锚点 ' . $stable_anchor . '/' . $expected
                . '；另有 ' . max(0, $expected - $visible) . ' 处需人工核对（不等同于翻译失败）',
            'expected' => $expected,
            'visible' => $visible,
            'stable_anchor' => $stable_anchor,
            'updated_at' => current_time('mysql'),
        ];
        update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
        delete_post_meta($post_id, '_lisun_manual_last_error');
        update_post_meta($post_id, '_lisun_manual_glossary_warning', $warning);
        self::debug_log('repaired glossary warning status post=' . $post_id
            . ' visible=' . $visible . '/' . $expected);
        return true;
    }

    /**
     * 诊断日志：写入 uploads/lisun-debug.log，不依赖 PHP error_log 配置。
     * 宝塔上 error_log 常写到 PHP-FPM 日志而非站点 .log，导致排查困难；
     * 这里固定写到 uploads 目录，路径稳定可查。
     */
    private static function debug_log($msg) {
        static $dir = null;
        if ($dir === null) {
            $up = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
            $dir = !empty($up['basedir']) ? $up['basedir'] : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : sys_get_temp_dir());
        }
        $path = rtrim($dir, '/\\') . '/lisun-debug.log';
        $msg = self::sanitize_debug_utf8((string) $msg);
        $line = '[' . current_time('Y-m-d H:i:s') . '] ' . $msg . "\n";

        // 旧日志可能已经被非法字节污染。写入新日志时顺便修复，
        // 否则宝塔/文件管理器仍会提示“文件编码不被兼容”。
        $existing = @file_get_contents($path);
        if (is_string($existing) && $existing !== '' && preg_match('//u', $existing) !== 1) {
            $existing = self::sanitize_debug_utf8($existing);
            @file_put_contents($path, $existing, LOCK_EX);
        }
        // 不再静默吞掉写入失败。翻译进程可能正常完成，但如果 uploads
        // 不可写，之前所有诊断信息都会消失，导致只能看到“翻译日志”而看不到
        // Worker/DeepL 中间进度。
        $written = false;
        $attempted = [$path];
        if (defined('WP_CONTENT_DIR')) {
            $attempted[] = rtrim(WP_CONTENT_DIR, '/\\') . '/lisun-debug.log';
        }
        $attempted[] = rtrim(sys_get_temp_dir(), '/\\') . '/lisun-debug.log';

        foreach (array_unique($attempted) as $candidate) {
            $bytes = @file_put_contents($candidate, $line, FILE_APPEND | LOCK_EX);
            if ($bytes !== false) {
                $written = true;
                break;
            }
        }

        // 文件日志全部不可写时，交给 PHP-FPM error_log，至少保留诊断信息，
        // 并明确打印尝试过的路径和当前进程身份，避免再次“无输出”。
        if (!$written) {
            $identity = function_exists('posix_geteuid') ? (' uid=' . (int) posix_geteuid()) : '';
            error_log('LISUN Manual debug_log WRITE_FAILED paths=' . implode(',', array_unique($attempted))
                . $identity . ' message=' . trim($line));
        }
    }

    /** 供同步模块写入同一份诊断日志。 */
    public static function diagnostic_log($msg) {
        self::debug_log($msg);
    }

    /**
     * 写入前端可消费的翻译阶段进度。
     *
     * progress 是“整篇任务的阶段进度”，不是 DeepL 的精确百分比；
     * completed/total_units（如果有）才表示已经处理的翻译单元数量。
     * 这样提取、规划、翻译、保存、推送期间都能持续反馈，不会从 0 直接跳到 100。
     */
    public static function update_translation_progress($post_id, $stage, $progress, $message, $extra = []) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !function_exists('set_transient')) return;

        // V2 reports progress from its own runner, so the legacy
        // translate_one_segment() heartbeat is not reached there. Refresh
        // the queue lease from this shared progress hook; otherwise a live
        // V2 job looks abandoned and an interrupted worker leaves it stuck
        // in `processing` until stale recovery.
        self::heartbeat();

        $data = [
            'step'     => 'processing',
            'stage'    => sanitize_key((string) $stage),
            'progress' => max(1, min(99, (int) $progress)),
            'msg'      => (string) $message,
            'time'     => time(),
        ];
        foreach (['completed_units', 'total_units', 'completed_batches', 'total_batches'] as $key) {
            if (array_key_exists($key, (array) $extra)) {
                $data[$key] = max(0, (int) $extra[$key]);
            }
        }
        set_transient("lisun_trans_{$post_id}", $data, 600);
    }

    /** 确保诊断日志始终是可读取的 UTF-8 文本。 */
    private static function sanitize_debug_utf8($value) {
        if ($value === '' || preg_match('//u', $value) === 1) {
            return $value;
        }

        // 先尝试“仅删除少量坏字节”。如果整段正文其实是 GBK/GB18030，
        // 这个结果会几乎没有中文，此时再尝试中文编码转换，避免把正文汉字丢光。
        $best = '';
        $best_cjk = 0;
        $score_cjk = function ($text) {
            preg_match_all('/[\x{4e00}-\x{9fff}]/u', (string) $text, $m);
            return count($m[0] ?? []);
        };
        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            if (is_string($clean) && preg_match('//u', $clean) === 1) {
                // 绝大部分内容本来就是 UTF-8、只夹杂少量坏字节时，直接采用
                // 删除坏字节后的结果。不能再按“中文字符更多”改猜 GBK，
                // 否则一枚坏字节会让整篇正常 UTF-8 中文变成另一串合法乱码。
                if (strlen($value) === 0 || (strlen($clean) / strlen($value)) >= 0.85) {
                    return $clean;
                }
                $best = $clean;
                $best_cjk = $score_cjk($clean);
            }
        }

        foreach (['GB18030', 'GBK', 'BIG5'] as $encoding) {
            $candidate = false;
            if (function_exists('mb_convert_encoding')) {
                $candidate = @mb_convert_encoding($value, 'UTF-8', $encoding);
            } elseif (function_exists('iconv')) {
                $candidate = @iconv($encoding, 'UTF-8//IGNORE', $value);
            }
            if (!is_string($candidate) || preg_match('//u', $candidate) !== 1) continue;
            $candidate_cjk = $score_cjk($candidate);
            if ($candidate_cjk >= 2 && $candidate_cjk > ($best_cjk + 1)) {
                $best = $candidate;
                $best_cjk = $candidate_cjk;
            }
        }

        return $best;
    }

    public static function init() {
        add_action('wp_ajax_lisun_translate_single', [__CLASS__, 'ajax_translate_single']);
        add_action('wp_ajax_lisun_translate_batch', [__CLASS__, 'ajax_translate_batch']);
        add_action('wp_ajax_lisun_retranslate', [__CLASS__, 'ajax_retranslate']);
        add_action('wp_ajax_lisun_delete_trans_ajax', [__CLASS__, 'ajax_delete_translation']);
        add_action('wp_ajax_nopriv_lisun_bg_translate', [__CLASS__, 'ajax_bg_translate']);
        add_action('wp_ajax_lisun_bg_translate', [__CLASS__, 'ajax_bg_translate']);

        // 中文内容变更时，自动标记翻译过期
        add_action('save_post_manual', [__CLASS__, 'detect_content_change'], 5, 3);

        // 文章编辑页「翻译 & 推送」侧边栏 + 内联 JS
        add_action('add_meta_boxes', [__CLASS__, 'add_translate_metabox']);
        add_action('admin_footer', [__CLASS__, 'render_translate_metabox_js']);
        add_action('admin_notices', [__CLASS__, 'render_global_translation_notices']);
        add_action('admin_footer', [__CLASS__, 'render_global_translation_notice_js']);
        add_action('wp_ajax_lisun_dismiss_translation_notice', [__CLASS__, 'ajax_dismiss_translation_notice']);

        // REST API 端点
        add_action('rest_api_init', function () {
            register_rest_route('lisun-manual/v1', '/translate', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_translate_single'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/retranslate', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_retranslate'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/retry-push', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_retry_push'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/reapply-glossary', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_reapply_glossary'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/delete-backup', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_delete_translation_backup'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/(?P<id>\d+)', [
                'methods'  => 'DELETE',
                'callback' => [__CLASS__, 'rest_delete_translation'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/progress/(?P<id>\d+)', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'rest_translate_progress'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/batch-progress', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'rest_batch_progress'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/translate/batch-progress', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_update_batch_progress'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            // Worker 端点：处理队列中的任务（fire-and-forget 触发）
            register_rest_route('lisun-manual/v1', '/translate/worker', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_translate_worker'],
                'permission_callback' => '__return_true',
            ]);
            // Worker 循环端点：持续处理队列直到清空或超时（用于并行批量翻译）
            register_rest_route('lisun-manual/v1', '/translate/worker-loop', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_translate_worker_loop'],
                'permission_callback' => '__return_true',
            ]);
            // 批量入队：将所有 pending 中文页面加入翻译队列
            register_rest_route('lisun-manual/v1', '/translate/enqueue-all', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_enqueue_all_pending'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            // 编辑锁：切换单个段落的 auto/manual 状态
            register_rest_route('lisun-manual/v1', '/translate/block-lock', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_toggle_block_lock'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
            // 页面级锁定：整篇英文锁定/解锁（方案 A）
            register_rest_route('lisun-manual/v1', '/translate/page-lock', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_toggle_page_lock'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
        });
    }

    // ==================== 内容变更检测 ====================

    /**
     * 计算用于"内容是否变更"的统一哈希基准。
     *
     * 必须先 strip_inline_styles 再 md5，否则 detect_content_change 用原始
     * post_content 算出的哈希永远对不上 translate_post 存的清洗后哈希，
     * 每次保存都被误判为"内容已变更"→ 反复重译（白白消耗 API + 加重服务器负载）。
     *
     * @param string $content 原始 post_content
     * @return string md5 哈希
     */
    private static function get_content_hash($content) {
        return md5(self::strip_inline_styles(self::sanitize_debug_utf8((string) $content)));
    }

    /**
     * 页面级英文锁定（方案 A）：判断某篇中文页面是否已锁定英文。
     *
     * 锁定后跳过所有翻译/重译/重推/术语重刷，保护远程站上的人工英文编辑不被覆盖。
     * 适用于"英文已在远程站定稿，不要动它"的场景。
     */
    public static function is_en_locked($post_id) {
        return get_post_meta($post_id, '_lisun_manual_en_lock', true) === 'locked';
    }

    private static function set_en_locked($post_id, $locked) {
        if ($locked) {
            update_post_meta($post_id, '_lisun_manual_en_lock', 'locked');
        } else {
            delete_post_meta($post_id, '_lisun_manual_en_lock');
        }
    }

    /**
     * save_post_manual 钩子：检测中文内容是否被修改，自动标记翻译过期
     *
     * 仅处理中文页面（lang=zh），对比当前 post_content 与上次翻译时保存的哈希。
     * 如果内容发生变化，重置 sync_status 为 pending，清除旧的翻译缓存，
     * 让页面重新出现在"待翻译"列表中。
     *
     * @param int      $post_id
     * @param WP_Post  $post
     * @param bool     $update  是否更新（false=新建）
     */
    public static function detect_content_change($post_id, $post, $update) {
        // 仅处理更新操作（非新建）
        if (!$update) return;
        // 跳过自动保存和修订版本
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;

        // [修改时间：2026-11-04 15:31:00 +08:00] 保存成功后由服务端直接入持久化队列，页面关闭不影响任务。
        if (isset($_POST['lisun_auto_translate_settings_present'])) {
            $auto_mode = !empty($_POST['lisun_auto_translate_retranslate'])
                ? 'retranslate'
                : (!empty($_POST['lisun_auto_translate_incremental']) ? 'incremental' : '');
            if (in_array($auto_mode, ['incremental', 'retranslate'], true)) {
                update_post_meta($post_id, '_lisun_auto_translate_mode', $auto_mode);
            } else {
                delete_post_meta($post_id, '_lisun_auto_translate_mode');
            }
        }
        // 仅中文页面
        $lang = get_post_meta($post_id, '_lisun_manual_lang', true);
        if ($lang && $lang !== 'zh') return;

        // 英文被锁定：中文变更也不自动标记 pending，避免误触发重译覆盖人工英文
        if (self::is_en_locked($post_id)) {
            error_log("LISUN Manual: post {$post_id} content changed but EN is locked — skipping status reset");
            return;
        }

        // 计算当前内容哈希（与 translate_post 存哈希时使用同一基准）
        $current_hash = self::get_content_hash($post->post_content);
        $stored_hash  = get_post_meta($post_id, '_lisun_manual_content_hash', true);

        if ($stored_hash && $stored_hash === $current_hash) {
            return; // 内容未变
        }

        $old_status = get_post_meta($post_id, '_lisun_manual_sync_status', true);

        // V2 是权威判断。旧版 content_hash 缺失、历史清洗差异或后台保存
        // 只改变了 HTML 表现时，不得把仍对应当前源文的已完成译文降级成未翻译。
        $v2_matches = self::v2_source_matches_active_revision($post, true);
        if ($v2_matches === true) {
            update_post_meta($post_id, '_lisun_manual_content_hash', $current_hash);
            if ($old_status === 'pending' && !self::has_active_translation_job($post_id)) {
                update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
                error_log("LISUN Manual: post {$post_id} status repaired to synced; V2 source revision still matches");
            }
            return;
        }

        // 内容确实已变更 → 重置翻译状态
        if (in_array($old_status, ['synced', 'push_failed', 'failed', 'translated'], true)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'pending');
            error_log("LISUN Manual: post {$post_id} content changed, translation status reset from '{$old_status}' to 'pending'");
        }

        // 保存新的内容哈希
        update_post_meta($post_id, '_lisun_manual_content_hash', $current_hash);

        // 清除旧的分段翻译缓存（内容变了，旧译文失效）
        delete_transient("lisun_trans_cp_{$post_id}");

        // ★ 内容被手动编辑后，原始 .docx 已过期。删除 docx 路径标记，让下次翻译走
        // HTML 增量管线（以 post_content 为准），而不是重复全量翻译原始 docx ——
        // 否则手动编辑会被忽略，且每次都是从头全量重译。
        delete_post_meta($post_id, '_lisun_manual_docx_path');

        // 保留旧备份用于增量翻译（内容变了但大部分段落可能没变）
        // 不清除备份；translate_post 会对比段 hash 只翻译变化的段

        $auto_mode = (string) get_post_meta($post_id, '_lisun_auto_translate_mode', true);
        if (in_array($auto_mode, ['incremental', 'retranslate'], true)) {
            $job_id = $auto_mode === 'retranslate'
                ? self::enqueue_retranslate_translation_job($post_id)
                : self::enqueue_incremental_translation_job($post_id);
            if ($job_id) {
                self::trigger_queue_worker($post_id);
                self::debug_log('auto translation queued post=' . (int) $post_id
                    . ' mode=' . $auto_mode . ' job=' . (int) $job_id);
            } else {
                update_post_meta($post_id, '_lisun_manual_last_error', '自动翻译任务入队失败，请打开文章后手动重试');
                self::debug_log('auto translation queue FAILED post=' . (int) $post_id . ' mode=' . $auto_mode);
            }
        }
    }

    // ==================== 文章编辑页「翻译 & 推送」Meta Box ====================

    /**
     * 在 manual 文章编辑页添加「翻译 & 推送」侧边栏
     */
    public static function add_translate_metabox() {
        add_meta_box(
            'lisun-manual-translate',
            __('翻译 & 推送', 'lisun-manual'),
            [__CLASS__, 'render_translate_metabox'],
            'manual',
            'side',
            'high'
        );
    }

    /**
     * 渲染「翻译 & 推送」侧边栏内容（仅中文页显示操作按钮）
     */
    public static function render_translate_metabox($post) {
        $lang = get_post_meta($post->ID, '_lisun_manual_lang', true);
        if ($lang === 'en') {
            // 英文页：直接在这里锁定/解锁（锁存到对应的中文原文上，翻译/推送以中文为准）
            $source_id = intval(get_post_meta($post->ID, '_lisun_manual_source_id', true));
            if (!$source_id) {
                echo '<p style="color:#999;">' . __('未找到对应的中文原文。', 'lisun-manual') . '</p>';
                return;
            }
            $locked = self::is_en_locked($source_id);
            ?>
            <div id="lisun-mb-wrap" data-id="<?php echo $source_id; ?>">
                <p style="margin:2px 0 8px;">
                    <?php if ($locked): ?>
                        <span style="color:#d63638;font-weight:600;">🔒 <?php _e('英文已锁定', 'lisun-manual'); ?></span>
                    <?php else: ?>
                        <span style="color:#999;"><?php _e('英文未锁定', 'lisun-manual'); ?></span>
                    <?php endif; ?>
                </p>
                <p style="font-size:12px;color:#999;margin:2px 0 8px;">
                    <?php _e('中文原文', 'lisun-manual'); ?>:
                    <a href="<?php echo get_edit_post_link($source_id); ?>"><?php echo esc_html(get_the_title($source_id) ?: '#' . $source_id); ?></a>
                </p>
                <p>
                    <button type="button" class="button button-small lisun-mb-lock" data-lock="<?php echo $locked ? 'unlocked' : 'locked'; ?>">
                        <?php echo $locked ? '🔓 ' . __('解锁', 'lisun-manual') : '🔒 ' . __('锁定英文', 'lisun-manual'); ?>
                    </button>
                </p>
                <p id="lisun-mb-progress" style="font-size:12px;color:#2271b1;margin:6px 0 0;"></p>
            </div>
            <?php
            return;
        }
        if (!$post->ID) {
            echo '<p style="color:#999;">' . __('保存文章后即可在此翻译。', 'lisun-manual') . '</p>';
            return;
        }

        self::repair_glossary_warning_status($post->ID);
        $status     = get_post_meta($post->ID, '_lisun_manual_sync_status', true);
        $locked     = self::is_en_locked($post->ID);
        $has_backup = (bool) self::get_translation_backup($post->ID);
        $remote_id  = get_post_meta($post->ID, '_lisun_manual_remote_id', true);
        $last_sync  = get_post_meta($post->ID, '_lisun_manual_last_sync', true);
        $last_error = (string) get_post_meta($post->ID, '_lisun_manual_last_error', true);
        $term_anomalies = get_post_meta($post->ID, '_lisun_manual_v2_term_anomalies', true);
        $term_anomalies = is_array($term_anomalies) ? $term_anomalies : [];
        $glossary_warning = get_post_meta($post->ID, '_lisun_manual_glossary_warning', true);
        $glossary_warning = is_array($glossary_warning) ? $glossary_warning : [];

        $status_map = [
            'synced'      => ['label' => __('✓ 已推送至远程站', 'lisun-manual'), 'color' => '#00a32a'],
            'push_failed' => ['label' => __('⚠ 翻译完成、推送失败', 'lisun-manual'), 'color' => '#d63638'],
            'translated'  => ['label' => __('已翻译（待推送）', 'lisun-manual'), 'color' => '#d63638'],
            'failed'      => ['label' => __('✗ 翻译失败', 'lisun-manual'), 'color' => '#d63638'],
            'pending'     => ['label' => __('待翻译', 'lisun-manual'), 'color' => '#999'],
        ];
        $st = $status_map[$status] ?? ['label' => __('待翻译', 'lisun-manual'), 'color' => '#999'];
        ?>
        <div id="lisun-mb-wrap" data-id="<?php echo (int) $post->ID; ?>">
            <p style="margin:2px 0 8px;">
                <span id="lisun-mb-status" style="color:<?php echo $st['color']; ?>;font-weight:600;"><?php echo $st['label']; ?></span>
                <?php if ($locked): ?>
                    <span style="color:#d63638;font-weight:600;">🔒 <?php _e('已锁定', 'lisun-manual'); ?></span>
                <?php endif; ?>
            </p>
            <?php if ($last_error && in_array($status, ['failed', 'push_failed', 'synced', 'translated'], true)): ?>
                <p style="margin:2px 0 8px;padding:6px 8px;color:#8a2424;background:#fff1f1;border-left:3px solid #d63638;font-size:12px;line-height:1.45;">
                    <strong><?php echo in_array($status, ['synced', 'translated'], true) ? '本次翻译失败，已保留上次译文：' : '失败原因：'; ?></strong><?php echo esc_html($last_error); ?>
                </p>
            <?php endif; ?>
            <?php if ($glossary_warning): ?>
                <div style="margin:6px 0 8px;padding:7px 9px;color:#754b12;background:#fff8e5;border-left:3px solid #dba617;font-size:12px;line-height:1.45;">
                    <strong>⚠ 术语应用已完成：</strong><?php echo esc_html((string) ($glossary_warning['message'] ?? '有术语数量或锚点提示，请人工核对。')); ?>
                    <?php if (!empty($glossary_warning['expected'])): ?>
                        <span>可见译文 <?php echo (int) ($glossary_warning['visible'] ?? 0); ?>/<?php echo (int) $glossary_warning['expected']; ?>，稳定锚点 <?php echo (int) ($glossary_warning['stable_anchor'] ?? 0); ?>/<?php echo (int) $glossary_warning['expected']; ?>。</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($term_anomalies): ?>
                <div style="margin:6px 0 8px;padding:7px 9px;color:#754b12;background:#fff8e5;border-left:3px solid #dba617;font-size:12px;line-height:1.45;">
                    <strong>⚠ 术语表检查异常：</strong>有 <?php echo count($term_anomalies); ?> 个段落的英文目标术语数量与中文原文不一致，请人工核对。
                    <?php $target_id = (int) get_post_meta($post->ID, '_lisun_manual_target_id', true); ?>
                    <?php if ($target_id): ?>
                        <a href="<?php echo esc_url(get_edit_post_link($target_id)); ?>" target="_blank" rel="noopener">打开英文译文</a>
                    <?php endif; ?>
                    <ul style="margin:4px 0 0 16px;">
                        <?php foreach (array_slice($term_anomalies, 0, 5) as $anomaly): ?>
                            <li><?php echo esc_html((string) ($anomaly['source_path'] ?? '段落')); ?>：
                                <?php foreach ((array) ($anomaly['checks'] ?? []) as $check): ?>
                                    <?php echo esc_html((string) ($check['target_text'] ?? '目标术语')); ?> <?php echo (int) ($check['actual_count'] ?? 0); ?>/<?php echo (int) ($check['expected_count'] ?? 0); ?>
                                <?php endforeach; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($remote_id): ?>
                <p style="font-size:12px;color:#999;margin:2px 0 8px;"><?php _e('远程 ID', 'lisun-manual'); ?>: <code><?php echo (int) $remote_id; ?></code></p>
            <?php endif; ?>
            <?php if ($last_sync): ?>
                <p style="font-size:12px;color:#999;margin:2px 0 8px;"><?php _e('上次同步', 'lisun-manual'); ?>: <?php echo esc_html($last_sync); ?></p>
            <?php endif; ?>
            <p style="margin:8px 0 6px;font-size:12px;line-height:1.4;">
                <input type="hidden" name="lisun_auto_translate_settings_present" value="1">
                <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="lisun_auto_translate_incremental" value="1" <?php checked(get_post_meta($post->ID, '_lisun_auto_translate_mode', true), 'incremental'); ?>> 保存后自动增量翻译并推送</label>
                <label style="display:block;"><input type="checkbox" name="lisun_auto_translate_retranslate" value="1" <?php checked(get_post_meta($post->ID, '_lisun_auto_translate_mode', true), 'retranslate'); ?>> 保存后自动全量重译并推送</label>
                <span style="display:block;color:#777;margin-top:3px;">保存成功后任务进入后台队列，关闭页面不影响执行。</span>
            </p>

            <p>
                <button type="button" class="button button-primary button-small lisun-mb-translate" <?php echo $locked ? 'disabled' : ''; ?>>
                    <?php _e('增量翻译并推送', 'lisun-manual'); ?>
                </button>
            </p>
            <p>
                <button type="button" class="button button-small lisun-mb-retranslate" <?php echo $locked ? 'disabled' : ''; ?>>
                    <?php _e('全量重译', 'lisun-manual'); ?>
                </button>
                <?php if ($has_backup): ?>
                <button type="button" class="button button-small lisun-mb-retry-push" <?php echo $locked ? 'disabled' : ''; ?>>
                    <?php _e('重推', 'lisun-manual'); ?>
                </button>
                <?php endif; ?>
            </p>
            <p>
                <button type="button" class="button button-small lisun-mb-lock" data-lock="<?php echo $locked ? 'unlocked' : 'locked'; ?>">
                    <?php echo $locked ? '🔓 ' . __('解锁', 'lisun-manual') : '🔒 ' . __('锁定英文', 'lisun-manual'); ?>
                </button>
            </p>
            <p id="lisun-mb-progress" style="font-size:12px;color:#2271b1;margin:6px 0 0;"></p>
        </div>
        <?php
    }

    /**
     * 文章编辑页内联 JS：把「翻译 & 推送」按钮接到 REST + 轮询进度
     */
    public static function render_translate_metabox_js() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'manual' || $screen->base !== 'post') {
            return;
        }
        // 中文页有翻译/重译按钮，英文页只有锁定按钮——都渲染 JS，
        // 由 $wrap 的 data-id（中文页=自身ID，英文页=对应中文原文ID）统一驱动。
        ?>
        <script>
        jQuery(function ($) {
            var $wrap = $('#lisun-mb-wrap');
            if (!$wrap.length) return;
            var postId = $wrap.data('id');
            if (!postId) return;
            var restBase = '<?php echo esc_js(rest_url('lisun-manual/v1')); ?>';
            var restNonce = '<?php echo wp_create_nonce('wp_rest'); ?>';

            function restPost(path, data) {
                return $.ajax({
                    url: restBase + path,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(data),
                    beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); }
                });
            }
            function setProgress(msg) { $('#lisun-mb-progress').text(msg); }

            // [修改时间：2026-11-04 15:31:00 +08:00] 两种自动翻译模式互斥，避免一次保存同时排入增量和全量任务。
            $('input[name="lisun_auto_translate_incremental"], input[name="lisun_auto_translate_retranslate"]').on('change', function () {
                if (this.checked) $(this).closest('p').find('input[type="checkbox"]').not(this).prop('checked', false);
            });

            function pollDone() {
                var timer = setInterval(function () {
                    $.ajax({
                        url: restBase + '/translate/progress/' + postId,
                        method: 'GET',
                        beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); }
                    }).done(function (p) {
                        if (!p || !p.active) { clearInterval(timer); return; }
                        if (p.step === 'done') {
                            clearInterval(timer);
                            var doneMsg = '✓ 完成';
                            if (p.result) {
                                var cost = (p.result.chars && p.result.chars > 0)
                                    ? ('，' + p.result.chars + ' 字符')
                                    : ('，' + ((p.result.prompt_tokens || 0) + (p.result.completion_tokens || 0)) + ' tokens');
                                doneMsg += '（耗时 ' + (p.result.duration || '?') + ' 秒' + cost + '）';
                            }
                            setProgress(doneMsg);
                            setTimeout(function () { location.reload(); }, 1500);
                        } else if (p.step === 'error') {
                            clearInterval(timer);
                            setProgress('✗ ' + (p.error || p.msg || '失败'));
                        } else {
                            setProgress('⏳ ' + (p.msg || p.step || '翻译中...'));
                        }
                    });
                }, 2000);
                setTimeout(function () { clearInterval(timer); }, 600000);
            }

            $('.lisun-mb-translate').on('click', function () {
                var $btn = $(this).prop('disabled', true);
                setProgress('⏳ 排队中...');
                restPost('/translate', { post_id: postId }).done(function (res) {
                    if (res.success) { pollDone(); }
                    else { $btn.prop('disabled', false); setProgress('✗ ' + (res.data.message || '')); }
                }).fail(function () { $btn.prop('disabled', false); setProgress('✗ 请求失败'); });
            });

            $('.lisun-mb-retranslate').on('click', function () {
                if (!confirm('确定「全量」重新翻译？会忽略已有译文，从头翻译并推送。')) return;
                var $btn = $(this).prop('disabled', true);
                setProgress('⏳ 排队中...');
                restPost('/translate/retranslate', { post_id: postId }).done(function (res) {
                    if (res.success) { pollDone(); }
                    else { $btn.prop('disabled', false); setProgress('✗ ' + (res.data.message || '')); }
                }).fail(function () { $btn.prop('disabled', false); setProgress('✗ 请求失败'); });
            });

            $('.lisun-mb-retry-push').on('click', function () {
                var $btn = $(this).prop('disabled', true);
                setProgress('⏳ 推送中...');
                restPost('/translate/retry-push', { post_id: postId }).done(function (res) {
                    $btn.prop('disabled', false);
                    if (res.success) { setProgress('✓ 已推送'); setTimeout(function () { location.reload(); }, 1000); }
                    else { setProgress('✗ ' + (res.data.message || '')); }
                }).fail(function () { $btn.prop('disabled', false); setProgress('✗ 请求失败'); });
            });

            $('.lisun-mb-lock').on('click', function () {
                var $btn = $(this).prop('disabled', true);
                var target = $btn.data('lock');
                restPost('/translate/page-lock', { post_id: postId, lock: target }).done(function (res) {
                    if (res.success) { setProgress(res.data.locked ? '🔒 已锁定' : '🔓 已解锁'); setTimeout(function () { location.reload(); }, 800); }
                    else { $btn.prop('disabled', false); setProgress('✗ ' + (res.data.message || '')); }
                }).fail(function () { $btn.prop('disabled', false); setProgress('✗ 请求失败'); });
            });
        });
        </script>
        <?php
    }

    /**
     * 在任意 WordPress 后台页面提示最近完成或失败的后台翻译任务。
     * [修改时间：2026-11-04 15:31:00 +08:00] 页面关闭后仍可在下次打开后台时看到结果。
     */
    public static function render_global_translation_notices() {
        if (!current_user_can('edit_posts')) return;
        global $wpdb;
        $dismissed = get_user_meta(get_current_user_id(), '_lisun_translation_notice_dismissed', true);
        $dismissed = is_array($dismissed) ? array_fill_keys(array_map('strval', $dismissed), true) : [];
        $table = $wpdb->prefix . 'lisun_translate_queue';
        $rows = $wpdb->get_results("SELECT id, post_id, action, status, error_msg, updated_at
            FROM `{$table}` WHERE status IN ('done', 'failed')
            AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY id DESC LIMIT 5");
        if (empty($rows)) return;
        foreach ($rows as $row) {
            if (isset($dismissed[(string) $row->id])) continue;
            $post = get_post((int) $row->post_id);
            if (!$post) continue;
            $url = get_edit_post_link($post->ID);
            $is_failed = $row->status === 'failed';
            $class = $is_failed ? 'notice-error' : 'notice-success';
            $label = $row->action === 'retranslate' ? '全量重译' : '增量翻译';
            $message = $is_failed
                ? $label . '失败：' . ((string) $row->error_msg ?: '请打开文章重试')
                : $label . '并推送完成。';
            echo '<div class="notice lisun-translation-notice ' . esc_attr($class) . ' is-dismissible" data-job-id="' . (int) $row->id . '"><p><strong>说明书 #' . (int) $post->ID . '</strong>：'
                . esc_html($message) . ' <a href="' . esc_url($url) . '">' . ($is_failed ? '打开文章重试' : '查看文章') . '</a></p><button type="button" class="notice-dismiss" aria-label="关闭通知"></button></div>';
        }
    }

    /** [修改时间：2026-11-04 15:35:00 +08:00] 持久化后台用户已关闭的翻译任务通知。 */
    public static function ajax_dismiss_translation_notice() {
        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => '无权限'], 403);
        check_ajax_referer('lisun_translation_notice', '_nonce');
        $job_id = absint($_POST['job_id'] ?? 0);
        if (!$job_id) wp_send_json_error(['message' => '缺少任务 ID']);
        $dismissed = get_user_meta(get_current_user_id(), '_lisun_translation_notice_dismissed', true);
        $dismissed = is_array($dismissed) ? array_values(array_unique(array_map('intval', $dismissed))) : [];
        array_unshift($dismissed, $job_id);
        update_user_meta(get_current_user_id(), '_lisun_translation_notice_dismissed', array_slice($dismissed, 0, 100));
        wp_send_json_success();
    }

    /** [修改时间：2026-11-04 15:35:00 +08:00] 关闭通知后写入当前后台用户的已读记录。 */
    public static function render_global_translation_notice_js() {
        if (!current_user_can('edit_posts')) return;
        ?>
        <script>
        jQuery(function($) {
            // [修改时间：2026-11-04 15:38:00 +08:00] 先立即隐藏，再异步保存已读状态，不依赖 WP core dismiss 脚本。
            $(document).on('click', '.lisun-translation-notice .notice-dismiss', function(e) {
                e.preventDefault();
                var $notice = $(this).closest('.lisun-translation-notice');
                var id = $notice.data('job-id');
                $notice.stop(true, true).fadeOut(120, function() { $(this).remove(); });
                $.post(ajaxurl, {action:'lisun_dismiss_translation_notice', job_id:id, _nonce:'<?php echo esc_js(wp_create_nonce('lisun_translation_notice')); ?>'});
            });
        });
        </script>
        <?php
    }

    // ==================== API 配置 ====================

    /**
     * 获取 DeepSeek API 配置
     */
    private static function get_config() {
        $provider = get_option('lisun_llm_provider', 'deepseek');
        $api_url  = get_option('lisun_llm_api_url', '');
        $api_key  = get_option('lisun_deepseek_key', '');
        $model    = get_option('lisun_deepseek_model', 'deepseek-chat');

        // ★ Relay 中转：国内服务器通过美国 relay 访问被墙 API
        $relay_url    = get_option('lisun_relay_url', '');
        $relay_secret = get_option('lisun_relay_secret', '');

        if (!empty($relay_url) && !empty($relay_secret)) {
            $relay_url = rtrim($relay_url, '/');

            if ($provider === 'gemini') {
                // Gemini 通过 relay 中转：relay → generativelanguage.googleapis.com
                $api_url = $relay_url . '/gemini/openai/chat/completions';
                // API key 由 relay 服务器注入，本地可不传
            } elseif ($provider === 'deepl') {
                // DeepL 通过 relay 中转
                $api_url = $relay_url . '/deepl/translate';
            }
            // DeepSeek 等国内可直接访问的 API 不需要走 relay
        }

        // 默认 API URL
        if (empty($api_url)) {
            $defaults = [
                'deepseek' => 'https://api.deepseek.com/chat/completions',
                'openai'   => 'https://api.openai.com/v1/chat/completions',
                'gemini'   => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'deepl'    => 'https://api.deepl.com/v2/translate',
            ];
            $api_url = $defaults[$provider] ?? 'https://api.deepseek.com/chat/completions';
        }

        // 模型默认值
        if (empty($model)) {
            $default_models = [
                'deepseek' => 'deepseek-chat',
                'openai'   => 'gpt-4o',
                'gemini'   => 'gemini-3.6-flash',
                'deepl'    => '',
            ];
            $model = $default_models[$provider] ?? 'deepseek-chat';
        }

        return [
            'api_key'       => $api_key,
            'api_url'       => $api_url,
            'model'         => $model,
            'temperature'   => floatval(get_option('lisun_deepseek_temperature', 0.1)),
            'max_tokens'    => intval(get_option('lisun_deepseek_max_tokens', 16384)),
            'timeout'       => intval(get_option('lisun_deepseek_timeout', 120)),
            'relay_url'     => $relay_url,
            'relay_secret'  => $relay_secret,
        ];
    }

    /**
     * 获取 SEO 生成专用的 LLM 配置。
     *
     * SEO 不再复用翻译提供商/密钥。为兼容升级前使用 DeepSeek 的站点，
     * 仅在 SEO 配置尚未创建且旧翻译提供商不是 DeepL 时临时回退旧配置；
     * 旧配置为 DeepL 时绝不会把 DeepL Key 当作 LLM Key 使用。
     */
    private static function get_seo_config() {
        $saved_provider = get_option('lisun_seo_provider', null);
        $legacy_provider = get_option('lisun_llm_provider', 'deepseek');
        if ($saved_provider === null) {
            $provider = $legacy_provider === 'deepl' ? 'openai' : $legacy_provider;
        } else {
            $provider = $saved_provider ?: 'openai';
        }

        $api_url = get_option('lisun_seo_api_url', '');
        $api_key = get_option('lisun_seo_api_key', '');
        $model = get_option('lisun_seo_model', '');
        $relay_url = get_option('lisun_seo_relay_url', '');
        $relay_secret = get_option('lisun_seo_relay_secret', '');

        // 旧站点仍使用 LLM 翻译配置时，保持 SEO 自动生成可用。
        if ($saved_provider === null && $legacy_provider !== 'deepl' && empty($api_key)) {
            $api_key = get_option('lisun_deepseek_key', '');
        }

        if (!empty($relay_url) && !empty($relay_secret)) {
            $relay_url = rtrim($relay_url, '/');
            $api_url = $provider === 'gemini'
                ? $relay_url . '/gemini/openai/chat/completions'
                : $relay_url . '/chat/completions';
        }

        if (empty($api_url)) {
            $defaults = [
                'deepseek'   => 'https://api.deepseek.com/chat/completions',
                'openai'     => 'https://api.openai.com/v1/chat/completions',
                'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/models',
                'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            ];
            $api_url = $defaults[$provider] ?? $defaults['openai'];
        }

        if (empty($model)) {
            $default_models = [
                'deepseek'   => 'deepseek-chat',
                'openai'     => 'gpt-4o',
                'gemini'     => 'gemini-3.6-flash',
                'openrouter' => 'openrouter/auto',
            ];
            $model = $default_models[$provider] ?? 'gpt-4o';
        }

        // Google 已对新用户限制旧版 2.5 Flash；兼容已保存的旧设置，
        // 避免用户必须先找到并手动修改模型名称才能生成 SEO。
        if ($provider === 'gemini' && in_array($model, ['gemini-2.5-flash', 'models/gemini-2.5-flash'], true)) {
            $model = 'gemini-3.6-flash';
        }

        return [
            'provider'     => $provider,
            'api_key'      => $api_key,
            'api_url'      => $api_url,
            'model'        => $model,
            'temperature'  => floatval(get_option('lisun_seo_temperature', 0.3)),
            'max_tokens'   => intval(get_option('lisun_seo_max_tokens', 500)),
            'timeout'      => intval(get_option('lisun_seo_timeout', 120)),
            'relay_url'    => $relay_url,
            'relay_secret' => $relay_secret,
        ];
    }

    /**
     * 构建 API 请求参数（统一鉴权 + Relay 中转）
     *
     * @param array $config get_config() 返回值
     * @param mixed $body   请求体（数组或 JSON 字符串）
     * @return array wp_remote_post 参数
     */
    private static function api_request_args($config, $body) {
        $headers = ['Content-Type' => 'application/json'];

        // Relay 中转鉴权（必须先设，后续判断 relay 模式）
        $is_relay = !empty($config['relay_secret']);
        if ($is_relay) {
            $headers['X-Relay-Secret'] = $config['relay_secret'];
        }

        // API 密钥鉴权：走 relay 时不发本地 key（relay 会注入真正的 key）
        if (!$is_relay && !empty($config['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $config['api_key'];
        }

        if (is_string($body)) {
            $encoded_body = $body;
        } else {
            $json_flags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $encoded_body = function_exists('wp_json_encode')
                ? wp_json_encode($body, $json_flags)
                : json_encode($body, $json_flags);
            if (!is_string($encoded_body)) {
                error_log('LISUN Manual API request JSON encoding failed');
                $encoded_body = '{}';
            }
        }

        return [
            'headers' => $headers,
            'body'    => $encoded_body,
            'timeout' => $config['timeout'] ?? 120,
        ];
    }

    /**
     * 检查 API 配置是否就绪
     */
    public static function is_configured() {
        $provider = get_option('lisun_llm_provider', 'deepseek');
        if ($provider === 'deepl') {
            return !empty(get_option('lisun_deepseek_key', ''));
        }
        $config = self::get_config();
        return !empty($config['api_key'])
            || (!empty($config['relay_url']) && !empty($config['relay_secret']));
    }

    /**
     * 判断当前是否配置了可用于生成内容的 LLM。
     *
     * DeepL 是翻译服务，不支持 chat/completions；它不能用于 SEO 生成。
     * Relay 模式下本地 API key 可以为空，由 Relay 注入上游 key。
     */
    public static function is_llm_configured() {
        $config = self::get_seo_config();
        $provider = $config['provider'] ?? 'openai';
        if ($provider === 'deepl') {
            return false;
        }

        return !empty($config['api_key'])
            || (!empty($config['relay_url']) && !empty($config['relay_secret']));
    }

    // ==================== 通用 LLM 调用 ====================

    /**
     * 轻量 LLM API 调用，供外部模块复用（如 SEO 生成、分类等）
     *
     * @param string $system_prompt 系统提示词
     * @param string $user_prompt   用户提示词
     * @param array  $opts          可选覆盖：temperature / max_tokens / model
     * @return string|null          LLM 返回的文本内容，失败返回 null
     */
    public static function call_llm($system_prompt, $user_prompt, $opts = []) {
        self::$last_llm_error = '';
        self::$last_llm_response = '';
        $config = self::get_seo_config();
        // DeepL endpoint 不是 OpenAI-compatible chat endpoint。
        if (($config['provider'] ?? '') === 'deepl') {
            self::$last_llm_error = 'SEO 不能使用 DeepL，请配置 OpenAI、DeepSeek 或 Gemini';
            return null;
        }
        if (empty($config['api_key'])
            && (empty($config['relay_url']) || empty($config['relay_secret']))) {
            self::$last_llm_error = 'SEO API Key 未配置';
            return null;
        }

        $temperature = $opts['temperature'] ?? $config['temperature'];
        $max_tokens  = $opts['max_tokens']  ?? $config['max_tokens'];
        $model       = $opts['model']       ?? $config['model'];
        // Gemini 的 models.list 返回值带有 models/ 前缀，
        // OpenAI 兼容 Chat Completions 的 model 参数不需要此前缀。
        if (($config['provider'] ?? '') === 'gemini') {
            $model = preg_replace('#^models/#', '', (string) $model);
        }

        $is_gemini = ($config['provider'] ?? '') === 'gemini';
        $is_google_gemini = $is_gemini
            && empty($config['relay_secret'])
            && strtolower((string) wp_parse_url($config['api_url'], PHP_URL_HOST)) === 'generativelanguage.googleapis.com';
        // Gemini 同时支持 OpenAI 兼容接口和原生 generateContent 接口。
        // 如果用户填的是 /models 或 :generateContent 地址，按原生格式发送，
        // 避免把 Chat Completions 请求体发到另一种接口导致 404/400。
        $is_gemini_native = $is_gemini && ($is_google_gemini || (bool) preg_match('#/models(?:/|$)|:generateContent(?:$|\?)#i', $config['api_url']));

        $messages = [];
        if (!empty($system_prompt)) {
            $messages[] = ['role' => 'system', 'content' => $system_prompt];
        }
        $messages[] = ['role' => 'user', 'content' => $user_prompt];

        if ($is_gemini_native) {
            $api_body = [
                'contents' => [[
                    'role'  => 'user',
                    'parts' => [['text' => (string) $user_prompt]],
                ]],
                'generationConfig' => [
                    'temperature'     => (float) $temperature,
                    'maxOutputTokens' => (int) $max_tokens,
                    'responseMimeType' => 'application/json',
                    // SEO 是简单结构化输出，Gemini 3 使用最低思考级别，避免思考占满输出预算。
                    'thinkingConfig'  => self::gemini_thinking_config($model),
                    'responseSchema'  => self::seo_response_schema(),
                ],
            ];
            if (!empty($system_prompt)) {
                $api_body['systemInstruction'] = [
                    'parts' => [['text' => (string) $system_prompt]],
                ];
            }
        } else {
            $api_body = [
                'model'       => $model,
                'temperature' => $temperature,
                'max_tokens'  => $max_tokens,
                'messages'    => $messages,
            ];
        }

        // 复用插件现有的本机 HTTP 代理设置。代理只负责网络转发，
        // 直连 API 仍必须提供 API key；Relay 模式不叠加本机代理。
        // 优先使用专门的 API/DeepL 代理；如果未启用，则复用已经能访问
        // Google Sheets 的代理配置，避免两套开关造成 SEO 请求直连。
        $proxy = '';
        if (empty($config['relay_secret'])) {
            if (get_option('lisun_deepl_proxy_enabled', false)) {
                $proxy = trim((string) get_option('lisun_deepl_proxy_url', 'http://127.0.0.1:7890'));
            } else {
                $sheets_proxy_enabled = get_option('lisun_glossary_proxy_enabled', null);
                if ($sheets_proxy_enabled === null && defined('LISUN_GLOSSARY_SHEETS_PROXY')) {
                    $proxy = trim((string) LISUN_GLOSSARY_SHEETS_PROXY);
                } elseif ($sheets_proxy_enabled) {
                    $proxy = trim((string) get_option('lisun_glossary_proxy_url', 'http://127.0.0.1:7890'));
                }
            }
        }
        if ($proxy !== '' && !function_exists('curl_init')) {
            error_log('LISUN Manual call_llm: local proxy enabled but PHP cURL is unavailable');
            self::$last_llm_error = '已启用网络代理，但 PHP cURL 不可用';
            return null;
        }

        $request_url = $config['api_url'];
        if ($is_gemini_native) {
            $request_url = self::gemini_native_url($request_url, $model);
        }

        $proxy_filter = null;
        if ($proxy !== '') {
            $target_host = (string) wp_parse_url($request_url, PHP_URL_HOST);
            $proxy_filter = function ($handle, $request, $request_url) use ($proxy, $target_host) {
                $request_host = (string) wp_parse_url($request_url, PHP_URL_HOST);
                if ($target_host !== '' && $request_host === $target_host && defined('CURLOPT_PROXY')) {
                    curl_setopt($handle, CURLOPT_PROXY, $proxy);
                }
                return $handle;
            };
            add_filter('http_api_curl', $proxy_filter, 10, 3);
        }

        $request_args = self::api_request_args($config, $api_body);
        if ($is_gemini_native) {
            // 原生 Gemini API 使用 x-goog-api-key，不使用 OpenAI Bearer 鉴权。
            $request_args['headers']['X-Goog-Api-Key'] = $config['api_key'];
            unset($request_args['headers']['Authorization']);
        }
        $response = wp_remote_post($request_url, $request_args);

        if (is_wp_error($response)) {
            error_log('LISUN Manual call_llm: HTTP error - ' . $response->get_error_message());
            // 重试一次
            $response = wp_remote_post($request_url, $request_args);
            if (is_wp_error($response)) {
                error_log('LISUN Manual call_llm: HTTP error on retry - ' . $response->get_error_message());
                self::$last_llm_error = '网络请求失败：' . $response->get_error_message();
                if ($proxy_filter !== null) {
                    remove_filter('http_api_curl', $proxy_filter, 10);
                }
                return null;
            }
        }

        if ($proxy_filter !== null) {
            remove_filter('http_api_curl', $proxy_filter, 10);
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_code = (int) wp_remote_retrieve_response_code($response);

        // 有些环境对 Gemini 的 OpenAI 兼容路径返回 404，但原生接口可用。
        // 仅对 Google Gemini 直连请求自动再试一次原生端点；Relay 不猜测其内部路由。
        if ($is_gemini && !$is_gemini_native && empty($config['relay_secret']) && $response_code === 404) {
            $native_url = self::gemini_native_url($request_url, $model);
            if ($native_url !== $request_url) {
                $native_body = [
                    'contents' => [[
                        'role'  => 'user',
                        'parts' => [['text' => (string) $user_prompt]],
                    ]],
                    'generationConfig' => [
                        'temperature'     => (float) $temperature,
                        'maxOutputTokens' => (int) $max_tokens,
                        'responseMimeType' => 'application/json',
                        'thinkingConfig'  => self::gemini_thinking_config($model),
                        'responseSchema'  => self::seo_response_schema(),
                    ],
                ];
                if (!empty($system_prompt)) {
                    $native_body['systemInstruction'] = [
                        'parts' => [['text' => (string) $system_prompt]],
                    ];
                }
                $native_args = self::api_request_args($config, $native_body);
                $native_args['headers']['X-Goog-Api-Key'] = $config['api_key'];
                unset($native_args['headers']['Authorization']);
                $native_response = wp_remote_post($native_url, $native_args);
                if (!is_wp_error($native_response)) {
                    $response = $native_response;
                    $request_url = $native_url;
                    $response_body = wp_remote_retrieve_body($response);
                    $response_code = (int) wp_remote_retrieve_response_code($response);
                    $is_gemini_native = true;
                }
            }
        }

        $data = json_decode($response_body, true);
        if ($response_code < 200 || $response_code >= 300) {
            $api_message = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? '') : '';
            $api_message = trim((string) $api_message);
            if ($api_message === '') {
                $api_message = 'HTTP ' . $response_code;
            }
            if (stripos($api_message, 'User location is not supported') !== false) {
                $api_message = $proxy !== ''
                    ? 'Gemini 区域限制：当前网络代理的出口地区不在 Google AI 支持范围内，请更换支持地区的代理出口'
                    : 'Gemini 区域限制：当前服务器出口地区不在 Google AI 支持范围内，请启用本机网络代理并确保代理出口位于支持地区';
            }
            $endpoint = (string) wp_parse_url($request_url, PHP_URL_PATH);
            self::$last_llm_error = 'API 返回错误：' . mb_substr($api_message, 0, 300)
                . '（端点：' . ($endpoint ?: $request_url) . '）';
            error_log('LISUN Manual call_llm: ' . self::$last_llm_error . ' body=' . substr($response_body, 0, 500));
            return null;
        }
        if ($is_gemini_native) {
            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $raw = '';
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (isset($part['text']) && is_scalar($part['text'])) {
                        // Gemini 3 可能返回带 thought 标记的思考摘要；SEO 只取最终答案。
                        if (!empty($part['thought'])) continue;
                        $raw .= (string) $part['text'];
                    }
                }
            }
            $raw = trim($raw);
        } else {
            $raw = trim(is_array($data) ? ($data['choices'][0]['message']['content'] ?? '') : '');
        }

        if (empty($raw)) {
            $body_preview = substr($response_body, 0, 500);
            if ($is_gemini_native && is_array($data)) {
                $finish_reason = $data['candidates'][0]['finishReason'] ?? '';
                $block_reason = $data['promptFeedback']['blockReason'] ?? '';
                $reason = $block_reason !== ''
                    ? 'promptFeedback=' . $block_reason
                    : ($finish_reason !== '' ? 'finishReason=' . $finish_reason : '响应结构中没有 candidates 文本');
                self::$last_llm_error = 'Gemini 未返回可用文本（' . $reason . '）';
            } else {
                self::$last_llm_error = 'API 返回中没有可用的文本内容';
            }
            error_log('LISUN Manual call_llm: empty or missing content. HTTP body preview: ' . $body_preview);
            return null;
        }

        // 暂存模型原始文本，SEO 解析失败时只向管理员显示有限预览，便于按真实返回格式排错。
        self::$last_llm_response = (string) $raw;

        return $raw;
    }

    /** Gemini 原生接口的 SEO 结构化输出约束。 */
    private static function seo_response_schema() {
        return [
            'type'       => 'OBJECT',
            'properties' => [
                'title'       => ['type' => 'STRING'],
                'description' => ['type' => 'STRING'],
                'keywords'    => ['type' => 'STRING'],
            ],
            'required' => ['title', 'description', 'keywords'],
        ];
    }

    /** Gemini 3/2.5 的结构化短文本使用低思考预算，给最终 JSON 留出输出空间。 */
    private static function gemini_thinking_config($model) {
        $model = strtolower((string) $model);
        if (strpos($model, 'gemini-3') === 0) {
            return ['thinkingLevel' => 'minimal'];
        }
        if (strpos($model, 'gemini-2.5') === 0) {
            return ['thinkingBudget' => 0];
        }
        return [];
    }

    /**
     * 根据 Gemini 配置生成原生 generateContent 端点。
     * 允许用户填写完整端点、/v1beta/models，或 /v1beta 基础地址。
     */
    private static function gemini_native_url($url, $model) {
        $url = trim((string) $url);
        $model = rawurlencode(preg_replace('#^models/#', '', (string) $model));
        if ($url === '') return $url;

        if (strpos($url, ':generateContent') !== false) {
            return $url;
        }
        if (preg_match('#/models/[^/?]+$#i', $url)) {
            return rtrim($url, '/') . ':generateContent';
        }
        if (preg_match('#/models/?$#i', $url)) {
            return rtrim($url, '/') . '/' . $model . ':generateContent';
        }
        if (preg_match('#/openai/chat/completions/?$#i', $url)) {
            return preg_replace('#/openai/chat/completions/?$#i', '/models/' . $model . ':generateContent', $url);
        }
        return rtrim($url, '/') . '/models/' . $model . ':generateContent';
    }

    /** 获取最近一次 SEO LLM 请求的可读错误。 */
    public static function get_last_llm_error() {
        return self::$last_llm_error;
    }

    /** 供 SEO 生成模块补充解析类错误，避免后台只显示通用失败提示。 */
    public static function set_last_llm_error($message) {
        self::$last_llm_error = sanitize_text_field((string) $message);
    }

    /** 获取最近一次 LLM 返回的诊断预览，不包含请求密钥。 */
    public static function get_last_llm_response_preview($length = 800) {
        $raw = (string) self::$last_llm_response;
        $max = max(200, (int) $length);
        $total = mb_strlen($raw, 'UTF-8');
        if ($total <= $max) return '长度=' . $total . '；完整返回：' . $raw;

        $head_length = (int) floor($max / 2);
        $tail_length = $max - $head_length;
        return '长度=' . $total
            . '；开头：' . mb_substr($raw, 0, $head_length, 'UTF-8')
            . '；结尾：' . mb_substr($raw, -$tail_length, $tail_length, 'UTF-8');
    }

    // ==================== AJAX 处理 ====================

    /**
     * AJAX: 翻译单个页面
     */
    public static function ajax_translate_single() {
        // 防止任何 PHP 输出污染 JSON
        ob_start();

        self::check_nonce();

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => __('缺少页面 ID', 'lisun-manual')]);
        }

        try {
            $result = self::translate_post($post_id);

            // 清理可能的输出污染
            ob_clean();

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }

            wp_send_json_success($result);
        } catch (\Exception $e) {
            ob_clean();
            error_log('LISUN Manual translate error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX: 批量翻译
     */
    public static function ajax_translate_batch() {
        self::check_nonce();

        $post_ids = array_map('intval', $_POST['post_ids'] ?? []);
        if (empty($post_ids)) {
            wp_send_json_error(['message' => __('请选择要翻译的页面', 'lisun-manual')]);
        }

        $results = [];
        foreach ($post_ids as $post_id) {
            $result = self::translate_post($post_id);
            $results[$post_id] = is_wp_error($result)
                ? ['status' => 'failed', 'message' => $result->get_error_message()]
                : array_merge($result, ['status' => 'success']);
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * AJAX: 重译
     */
    public static function ajax_retranslate() {
        self::check_nonce();

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => __('缺少页面 ID', 'lisun-manual')]);
        }

        // 清除之前的目标关联，强制重新翻译
        $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);
        if ($target_id) {
            delete_post_meta($post_id, '_lisun_manual_target_id');
        }

        // 清除分段翻译缓存；force_retranslate 会强制走全量翻译（不清备份，成功后覆盖）
        delete_transient("lisun_trans_cp_{$post_id}");
        self::$force_retranslate = true;

        $result = self::translate_post($post_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    // ==================== 翻译备份（按帖独立存储，避免并发竞态） ====================

    private static function backup_key($zh_post_id) {
        return 'lisun_trans_bak_' . intval($zh_post_id);
    }

    /**
     * 保存翻译备份（推送失败时使用）
     */
    private static function save_translation_backup($zh_post_id, $data) {
        // 第 3 个参数 false = 不 autoload。备份可能几 MB（增量翻译还重复存了
        // zh_segments/en_parts），若 autoload='yes' 会在每次请求被 wp_load_alloptions()
        // 全量加载进内存，翻译数百篇后 PHP-FPM OOM，表现为 Cloudflare 525。
        $result = update_option(self::backup_key($zh_post_id), array_merge($data, ['saved_at' => current_time('mysql')]), false);
        $content_anchor_count = preg_match_all('/data-lisun-term-id\s*=\s*(["\'])[^"\']+\1/i', (string) ($data['en_content'] ?? ''), $unused_content_anchor_matches);
        $title_anchor_count = preg_match_all('/data-lisun-term-id\s*=\s*(["\'])[^"\']+\1/i', (string) ($data['en_title_anchored'] ?? ''), $unused_title_anchor_matches);
        self::debug_log('save_translation_backup post=' . $zh_post_id
            . ' result=' . var_export($result, true)
            . ' content_anchors=' . max(0, (int) $content_anchor_count)
            . ' title_anchors=' . max(0, (int) $title_anchor_count));
    }

    /**
     * 获取翻译备份
     */
    public static function get_translation_backup($zh_post_id) {
        return get_option(self::backup_key($zh_post_id));
    }

    /**
     * 删除翻译备份
     */
    public static function delete_translation_backup($zh_post_id) {
        delete_option(self::backup_key($zh_post_id));
        self::debug_log('delete_translation_backup post=' . $zh_post_id);
    }

    /**
     * 获取所有备份（管理页面用）
     */
    public static function get_all_backups() {
        global $wpdb;
        $prefix = 'lisun_trans_bak_';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix . '%'
            )
        );
        $backups = [];
        foreach ($results as $row) {
            $zh_id = intval(str_replace($prefix, '', $row->option_name));
            $backups[$zh_id] = maybe_unserialize($row->option_value);
        }
        return $backups;
    }

    /**
     * 重新应用术语表到已有译文（不调 LLM，仅文本替换 + 推送）
     *
     * @param int $zh_post_id 中文 manual 页面 ID
     * @return array|WP_Error
     */
    public static function reapply_glossary($zh_post_id, $only_term_id = '') {
        if (!Lisun_Manual_Glossary::is_enabled()) {
            return new WP_Error('glossary_disabled', '术语表功能已关闭，未执行术语表应用');
        }
        // V2 的术语身份保存在 occurrence 表中，不依赖英文文章里的 span。
        // 已有术语改译法时本地重组（0 次 DeepL）；新增术语只重译命中单元。
        if (self::translation_v2_enabled()
            && Lisun_Manual_V2_Repository::get_active_snapshot($zh_post_id)) {
            return self::reapply_glossary_v2($zh_post_id);
        }
        if (!self::term_anchors_enabled()) {
            return new WP_Error('glossary_anchors_disabled', '正文术语锚点已关闭，未执行本地术语应用');
        }
        $start_time = microtime(true);
        $backup = self::get_translation_backup($zh_post_id);
        if (!$backup || empty($backup['en_content'])) {
            return new WP_Error('no_backup', __('没有找到备份数据，请先翻译', 'lisun-manual'));
        }
        $backup_content_for_format = (string) ($backup['en_content_anchored'] ?? ($backup['en_content'] ?? ''));
        $backup_has_source_html_anchors = preg_match('/<span\b[^>]*\bdata-lisun-term-id\s*=\s*["\'][^"\']+["\'][^>]*>[\s\S]*?<\/span\s*>/i', $backup_content_for_format)
            && preg_match('/<span\b[^>]*\bdata-lisun-term-id\s*=\s*["\'][^"\']+["\'][^>]*>[\s\S]*?<\/span\s*>/i', (string) ($backup['zh_content_anchored'] ?? ''));
        if (self::uses_strict_deepl_html()
            && ($backup['anchor_mode'] ?? '') !== 'source_html_v1'
            && !$backup_has_source_html_anchors) {
            return new WP_Error('glossary_anchor_migration_required',
                '该文章使用旧版译文格式，需要重新翻译一次以生成中文源锚点');
        }

        $all_glossary = Lisun_Manual_Glossary::get_all();
        $current_glossary = $all_glossary;
        if ($only_term_id !== '') {
            $current_glossary = array_values(array_filter($all_glossary, function ($term) use ($only_term_id) {
                return (string) ($term['term_id'] ?? '') === $only_term_id;
            }));
            if (empty($current_glossary)) {
                return new WP_Error('glossary_term_missing', '术语不存在或已被删除');
            }
        }
        $current_hash = md5(json_encode($all_glossary));
        $old_hash = $backup['glossary_hash'] ?? '';
        $backup_anchored_content = $backup['en_content_anchored'] ?? ($backup['en_content'] ?? '');
        // 兼容锚点功能上线前产生的旧备份：此前分段器可能把独立的
        // </td>、</tr> 等标签当成文本，保存成了 &lt;/td&gt;。
        // 应用术语表不能把这类历史损坏内容再次推回远程站。
        $backup_anchored_content = self::restore_escaped_html_structure($backup_anchored_content);

        // 备份可能在旧流程中被覆盖成不完整内容；本地英文副本才是用户
        // 当前实际看到、可继续编辑的译文。若它包含更多当前术语锚点，
        // 优先使用它，避免“请求成功但仍推送旧内容”。
        $local_en_id = get_post_meta($zh_post_id, '_lisun_manual_target_id', true);
        $local_en_post = $local_en_id ? get_post($local_en_id) : null;
        $anchored_content = $backup_anchored_content;
        $content_source = 'backup';
        $backup_known_anchor_count = 0;
        $local_known_anchor_count = 0;
        if ($local_en_post) {
            $local_content = self::restore_escaped_html_structure((string) $local_en_post->post_content);
            $known_ids = array_keys(Lisun_Manual_Glossary::get_by_id());
            $count_known_ids = function ($html) use ($known_ids) {
                if (empty($known_ids)) return 0;
                preg_match_all('/data-lisun-term-id\s*=\s*(["\'])([^"\']+)\1/i', (string) $html, $matches);
                return count(array_intersect(array_unique($matches[2] ?? []), $known_ids));
            };
            $backup_known_anchor_count = $count_known_ids($backup_anchored_content);
            $local_known_anchor_count = $count_known_ids($local_content);
            // 本地英文 Post 是用户当前实际看到和编辑的版本。只要它包含
            // 当前术语表的合法锚点，就始终优先使用它；不能因为备份里的
            // 锚点数量更多而把旧备份覆盖回本地和远程。
            if ($local_known_anchor_count > 0) {
                $anchored_content = $local_content;
                $content_source = 'local_en_post';
            }
        }
        // 只有正文/富文本参与锚点跟踪；标题与 SEO 是纯文本字段。
        $anchor_scan_content = $anchored_content;
        $has_anchor = (bool) preg_match('/data-lisun-term-id\s*=\s*["\'][^"\']+["\']/i', $anchor_scan_content);
        self::debug_log('glossary reapply scan post=' . $zh_post_id
            . ' source=' . $content_source
            . ' has_anchor=' . ($has_anchor ? 'yes' : 'no')
            . ' backup_known=' . $backup_known_anchor_count
            . ' local_known=' . $local_known_anchor_count
            . ' current_hash=' . $current_hash
            . ' old_hash=' . $old_hash);

        if (!$has_anchor && !empty($current_glossary)) {
            return new WP_Error('glossary_anchor_missing', '该文章没有术语锚点，需要重新翻译一次以生成术语锚点');
        }
        preg_match_all('/data-lisun-term-id\s*=\s*(["\'])([^"\']+)\1/i', $anchor_scan_content, $anchor_matches);
        $all_anchor_ids = array_values(array_unique($anchor_matches[2] ?? []));
        $known_id_map = Lisun_Manual_Glossary::get_by_id();
        $unknown_anchor_ids = array_values(array_diff($all_anchor_ids, array_keys($known_id_map)));
        // 如果术语表曾被旧版本导入流程重建过，备份里的中文源锚点仍然
        // 可以按“中文术语”恢复旧 ID。绝不从英文内容猜测术语。
        $recovery_map = self::build_reapply_anchor_recovery_map($backup['zh_content_anchored'] ?? '');
        $recoverable_unknown_ids = array_values(array_intersect($unknown_anchor_ids, array_keys($recovery_map)));
        $unresolved_unknown_ids = array_values(array_diff($unknown_anchor_ids, array_keys($recovery_map)));
        self::debug_log('glossary reapply anchor ids post=' . $zh_post_id
            . ' total=' . count($anchor_matches[2] ?? [])
            . ' unique=' . count($all_anchor_ids)
            . ' unknown=' . count($unknown_anchor_ids)
            . ' recoverable=' . count($recoverable_unknown_ids)
            . ' unresolved=' . count($unresolved_unknown_ids)
            . ' unknown_ids=' . wp_json_encode($unknown_anchor_ids, JSON_UNESCAPED_UNICODE));

        // 少量真正被删除的术语仍按旧规则移除跟踪壳；但如果大批锚点
        // 同时变成未知，通常是术语表 ID 被整体重建。此时必须安全停止，
        // 不能再把数百个正常锚点当作“已删除术语”处理。
        $unknown_threshold = max(10, (int) ceil(max(1, count($all_anchor_ids)) * 0.20));
        if (count($unresolved_unknown_ids) > $unknown_threshold) {
            return new WP_Error(
                'glossary_anchor_ids_mismatch',
                '当前术语表与文章锚点 ID 大量不一致（未匹配 ' . count($unresolved_unknown_ids)
                    . ' 个，共 ' . count($all_anchor_ids) . ' 个）。为避免删除正常译文，已停止应用和推送；请先恢复术语表 ID 或重新翻译一次'
            );
        }
        $replacement = self::replace_glossary_anchors($anchored_content, $recovery_map, $only_term_id);
        $en_content = self::post_process_translation($replacement['text']);
        $stats = $replacement['stats'];
        self::debug_log('glossary reapply post=' . $zh_post_id
            . ' anchors=' . $stats['anchors']
            . ' updated=' . $stats['updated']
            . ' orphaned=' . $stats['orphaned']
            . ' changed_terms=' . wp_json_encode($stats['changed_terms'] ?? [], JSON_UNESCAPED_UNICODE));

        // 只要当前内容中确实有锚点，就允许“相同译法”的幂等应用；
        // 但一个锚点都没找到时必须报错，不能把旧内容伪装成成功。
        $total_anchor_count = count($anchor_matches[2] ?? []);
        if (!empty($current_glossary) && $total_anchor_count === 0) {
            return new WP_Error('glossary_anchor_not_updated', '没有找到可更新的术语锚点，未保存或推送，请先重新翻译一次');
        }

        $en_title = sanitize_text_field(self::strip_glossary_anchors((string) ($backup['en_title'] ?? '')));
        $en_title_anchored = $en_title;
        $en_seo = (array) ($backup['en_seo'] ?? []);
        $en_seo_anchored = $en_seo;

        // 保存更新后的备份（保留段数据供增量翻译使用）
        $en_parts = [];
        foreach ((array) ($backup['en_parts'] ?? []) as $part_idx => $part) {
            $part_result = self::replace_glossary_anchors(self::restore_escaped_html_structure((string) $part), $recovery_map, $only_term_id);
            $en_parts[$part_idx] = $part_result['text'];
        }
        self::save_translation_backup($zh_post_id, [
            'anchor_mode'    => $backup['anchor_mode'] ?? '',
            'en_title'       => $en_title,
            'en_title_anchored' => $en_title_anchored,
            'zh_content_anchored' => $backup['zh_content_anchored'] ?? '',
            'en_content'     => $en_content,
            'en_slug'        => $backup['en_slug'],
            'en_seo'         => $en_seo,
            'en_seo_anchored' => $en_seo_anchored,
            'tags'           => $backup['tags'] ?? [],
            'glossary_hash'  => $current_hash,
            'zh_segments'    => $backup['zh_segments'] ?? [],
            'en_parts'       => $en_parts,
            'segment_hashes' => $backup['segment_hashes'] ?? [],
            'block_locks'    => $backup['block_locks'] ?? [],
        ]);

        // 推送到远程站。备份/本地内容保留锚点，但公开远程正文必须是干净 HTML。
        $push_content = self::strip_glossary_anchors($en_content);
        $source_post = get_post($zh_post_id);
        if ($source_post) {
            if (!$local_en_id || !get_post($local_en_id)) {
                self::debug_log('glossary reapply local target missing post=' . $zh_post_id
                    . ' target_id=' . (int) $local_en_id);
                return new WP_Error('local_translation_missing', '本地英文副本不存在，未保存或推送，请先重新翻译一次');
            }

            if ($local_en_id && get_post($local_en_id)) {
                // 直接更新已有本地副本，严禁通过 create_local_en_page 间接触发 SEO 翻译 API。
                $local_update = wp_update_post([
                    'ID'           => intval($local_en_id),
                    'post_title'   => $en_title,
                    'post_content' => $en_content,
                ], true);
                if (is_wp_error($local_update)) {
                    self::debug_log('glossary reapply local update failed post=' . $zh_post_id
                        . ' target_id=' . (int) $local_en_id
                        . ' error="' . $local_update->get_error_message() . '"');
                    return new WP_Error('local_translation_update_failed', '本地英文副本更新失败，未推送：' . $local_update->get_error_message());
                }
                $local_after_update = get_post($local_en_id);
                if (!$local_after_update || (string) $local_after_update->post_content !== (string) $en_content) {
                    self::debug_log('glossary reapply local update verification failed post=' . $zh_post_id
                        . ' target_id=' . (int) $local_en_id);
                    return new WP_Error('local_translation_update_failed', '本地英文副本写入校验失败，未推送，请重试');
                }
                self::debug_log('glossary reapply local updated post=' . $zh_post_id
                    . ' target_id=' . (int) $local_en_id
                    . ' bytes=' . strlen((string) $en_content));
                $seo_meta = [
                    'title' => 'title', 'description' => 'description', 'keywords' => 'keywords',
                ];
                foreach ($seo_meta as $key => $meta_key) {
                    if (isset($en_seo[$key])) {
                        update_post_meta($local_en_id, $meta_key, $en_seo[$key]);
                    }
                }
            }
        }
        self::debug_log('glossary reapply remote push start post=' . $zh_post_id
            . ' target_id=' . (int) $local_en_id
            . ' content_bytes=' . strlen((string) $push_content)
            . ' title_chars=' . mb_strlen((string) $en_title));
        $push_result = Lisun_Manual_Sync::push_translation(
            $zh_post_id, $en_title, $push_content, $backup['en_slug'], $en_seo
        );

        if (is_wp_error($push_result)) {
            self::debug_log('glossary reapply remote push failed post=' . $zh_post_id
                . ' target_id=' . (int) $local_en_id
                . ' code=' . $push_result->get_error_code()
                . ' error="' . $push_result->get_error_message() . '"');
            update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'push_failed');
            Lisun_Manual_Translate_Log::add([
                'zh_page_id' => $zh_post_id, 'en_page_id' => 0,
                'status' => 'glossary_push_failed', 'duration' => microtime(true) - $start_time,
                'error_msg' => $push_result->get_error_message(),
            ]);
            return new WP_Error(
                'glossary_push_failed_after_local_update',
                '本地英文译文已更新，但远程推送失败：' . $push_result->get_error_message(),
                [
                    'local_updated' => true,
                    'updated' => $stats['updated'],
                    'orphaned' => $stats['orphaned'],
                    'anchors' => $stats['anchors'],
                    'target_id' => (int) $local_en_id,
                ]
            );
        }

        self::debug_log('glossary reapply remote push ok post=' . $zh_post_id
            . ' target_id=' . (int) $local_en_id
            . ' remote_id=' . (int) $push_result);

        update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($zh_post_id, '_lisun_manual_last_sync', current_time('mysql'));

        Lisun_Manual_Translate_Log::add([
            'zh_page_id' => $zh_post_id, 'en_page_id' => 0,
            'status' => 'glossary_reapply', 'duration' => microtime(true) - $start_time,
        ]);

        return [
            'changed' => true,
            'message' => '术语表已应用并推送',
            'updated' => $stats['updated'],
            'orphaned' => $stats['orphaned'],
            'anchors' => $stats['anchors'],
        ];
    }

    /** V2 术语应用：只刷新已存在锚点的英文，不重新请求 DeepL。 */
    private static function reapply_glossary_v2($zh_post_id) {
        $post = get_post($zh_post_id);
        if (!$post || $post->post_type !== 'manual') {
            return new WP_Error('invalid_post', '无效的说明书页面');
        }
        if (self::is_en_locked($zh_post_id)) {
            return new WP_Error('en_locked', '该页面英文已锁定，请先解锁再应用术语表');
        }

        $active = Lisun_Manual_V2_Repository::get_active_snapshot($zh_post_id);
        $apply_summary = Lisun_Manual_V2_Runner::summarize_glossary_apply(
            $active,
            Lisun_Manual_Glossary::get_all()
        );
        $result = Lisun_Manual_V2_Runner::apply_glossary_only(
            $zh_post_id,
            (string) $post->post_content,
            (string) $post->post_title,
            Lisun_Manual_Glossary::get_all()
        );
        if (is_wp_error($result)) return $result;
        $publication = self::publish_v2_result($post, $result, true);
        if (is_wp_error($publication)) return $publication;

        $translated_units = (int) ($publication['translated_units'] ?? 0);
        $record = [
            'time' => current_time('mysql'),
            'revision_id' => (int) ($result['revision_id'] ?? 0),
            'updated' => (int) ($apply_summary['updated'] ?? 0),
            'changed_terms' => array_values((array) ($apply_summary['changed_terms'] ?? [])),
            'translated_units' => $translated_units,
            'reused_units' => (int) ($publication['reused_units'] ?? 0),
            'requests' => (int) ($publication['requests'] ?? 0),
            'term_split_units' => (int) ($result['term_split_units'] ?? $apply_summary['term_split_units'] ?? 0),
            'term_unknown_units' => (int) ($result['term_unknown_units'] ?? $apply_summary['term_unknown_units'] ?? 0),
            'term_anomaly_units' => (int) ($result['term_anomaly_units'] ?? 0),
            'boundary_repaired' => (int) ($result['boundary_repaired'] ?? 0),
            'pushed' => !empty($publication['pushed']),
            'push_msg' => (string) ($publication['push_msg'] ?? ''),
        ];
        $history = get_post_meta($zh_post_id, '_lisun_manual_v2_glossary_apply_history', true);
        $history = is_array($history) ? $history : [];
        array_unshift($history, $record);
        update_post_meta($zh_post_id, '_lisun_manual_v2_glossary_apply_history', array_slice($history, 0, 10));
        update_post_meta($zh_post_id, '_lisun_manual_v2_glossary_apply_last', $record);

        $publication['changed'] = $record['updated'] > 0 || $translated_units > 0;
        $publication['updated'] = $record['updated'];
        $publication['changed_terms'] = $record['changed_terms'];
        $publication['apply_record'] = $record;
        $publication['message'] = $record['pushed']
            ? 'V2 术语表已应用并推送'
            : 'V2 术语表已在本地应用，远程推送失败';

        self::diagnostic_log(
            'V2 glossary apply post=' . (int) $zh_post_id
            . ' updated=' . $record['updated']
            . ' translated_units=' . $translated_units
            . ' reused_units=' . $record['reused_units']
            . ' requests=' . $record['requests']
            . ' pushed=' . ($record['pushed'] ? 'yes' : 'no')
        );
        return $publication;
    }

    /**
     * Apply one glossary term to one source manual.  This is intentionally
     * term-scoped: unlike the legacy Translation tab action it never refreshes
     * unrelated glossary entries that happen to be stale in the same document.
     */
    public static function apply_glossary_term_to_post($zh_post_id, $term_id, $mode = 'direct', $expected_revision = 0, $expected_term_revision = 0) {
        if (!Lisun_Manual_Glossary::is_enabled()) {
            return new WP_Error('glossary_disabled', '术语表功能已关闭');
        }
        if ($expected_term_revision > 0
            && $expected_term_revision !== Lisun_Manual_Glossary::get_term_revision($term_id)) {
            return new WP_Error('glossary_revision_changed', '术语库已变化，请重新查看影响范围后再执行');
        }
        if (self::is_en_locked($zh_post_id)) {
            return new WP_Error('en_locked', '该页面英文已锁定，已跳过');
        }
        $term = Lisun_Manual_Glossary::get_by_id()[$term_id] ?? null;
        if (!$term) return new WP_Error('glossary_term_missing', '术语不存在或已被删除');

        $post = get_post($zh_post_id);
        if (!$post || $post->post_type !== 'manual') {
            return new WP_Error('invalid_post', '无效的说明书页面');
        }
        $active = Lisun_Manual_V2_Repository::get_active_snapshot($zh_post_id);
        if (!$active) {
            // V1 only changes spans that prove this term's location; no English
            // guessing or whole-document replacement is permitted.
            return self::reapply_glossary($zh_post_id, $term_id);
        }
        $source_check = class_exists('Lisun_Manual_V2_Runner')
            ? Lisun_Manual_V2_Runner::compare_source_to_active_revision(
                $zh_post_id,
                (string) $post->post_content,
                (string) $post->post_title,
                $active
            )
            : ['matches' => false, 'reason' => 'source_changed'];
        $source_change_rerouted = false;
        if (empty($source_check['matches'])) {
            $reason = (string) ($source_check['reason'] ?? 'source_changed');
            // A stale source snapshot invalidates direct glossary materialize,
            // not the whole translation. Re-run the V2 planner incrementally;
            // unchanged units are reused and only changed units call DeepL.
            $result = Lisun_Manual_V2_Runner::translate_document(
                $zh_post_id,
                (string) $post->post_content,
                (string) $post->post_title,
                false
            );
            if (is_wp_error($result)) return $result;
            $result['source_change_rerouted'] = true;
            $result['source_change_reason'] = $reason;
            $source_change_rerouted = true;
        }

        if (!$source_change_rerouted && $mode === 'incremental') {
            // A newly added term changes the occurrence signature only in
            // matching units, so V2 reuses every other unit automatically.
            $result = Lisun_Manual_V2_Runner::translate_document(
                $zh_post_id, (string) $post->post_content, (string) $post->post_title, false
            );
        } elseif (!$source_change_rerouted) {
            $result = Lisun_Manual_V2_Runner::apply_glossary_only(
                $zh_post_id, (string) $post->post_content, (string) $post->post_title, [$term]
            );
        }
        if (is_wp_error($result)) return $result;

        // Glossary actions must not trigger unrelated SEO translation requests.
        $publication = self::publish_v2_result($post, $result, true);
        if (is_wp_error($publication)) return $publication;
        if (empty($publication['pushed'])) {
            return new WP_Error(
                'glossary_push_failed_after_local_update',
                '本地英语副本已更新，但远程推送失败：' . (string) ($publication['push_msg'] ?? '请重试'),
                [
                    'local_updated' => true,
                    'updated' => (int) ($result['updated'] ?? 0),
                    'requests' => (int) ($result['requests'] ?? 0),
                ]
            );
        }
        $publication['updated'] = (int) ($result['updated'] ?? 0);
        $publication['term_id'] = $term_id;
        $publication['mode'] = $mode;
        // 应用成功后，把本篇记入该术语的“指定文章”适用范围，详情页才会默认勾选。
        // 这样“应用记录”与“适用配置”保持一致；全局术语无需记录（no-op）。
        Lisun_Manual_Glossary::add_article_to_term_scope($term_id, $zh_post_id);
        return $publication;
    }

    /**
     * Undo a single glossary apply: restore the V2 revision that was active
     * before the apply, re-materialize the English page, and re-push remote.
     *
     * If a newer translation happened after the apply, the caller must warn
     * the user before continuing because restoring the previous revision will
     * overwrite that newer translation. Locked pages still cannot be changed.
     */
    public static function undo_glossary_apply($zh_post_id, $applied_revision_id) {
        $zh_post_id = (int) $zh_post_id;
        $applied_revision_id = (int) $applied_revision_id;
        if ($zh_post_id <= 0 || $applied_revision_id <= 0) {
            return new WP_Error('bad_input', '无效的撤销请求');
        }
        $post = get_post($zh_post_id);
        if (!$post || $post->post_type !== 'manual') {
            return new WP_Error('invalid_post', '无效的说明书页面');
        }
        if (self::is_en_locked($zh_post_id)) {
            return new WP_Error('en_locked', '该页面英文已锁定，不能撤销');
        }

        $active = Lisun_Manual_V2_Repository::get_active_snapshot($zh_post_id);
        if (!$active) {
            return new WP_Error('no_v2', '没有找到 V2 翻译版本');
        }
        $previous_id = Lisun_Manual_V2_Repository::previous_revision_id($zh_post_id, $applied_revision_id);
        if ($previous_id <= 0) {
            return new WP_Error('no_previous', '找不到应用之前的版本，无法撤销');
        }

        $previous = Lisun_Manual_V2_Repository::get_snapshot_by_revision($previous_id);
        if (!$previous) {
            return new WP_Error('previous_missing', '应用前的版本数据损坏，无法撤销');
        }

        // Re-materialize English content from the previous snapshot.
        $glossary = Lisun_Manual_Glossary::is_enabled() ? Lisun_Manual_Glossary::get_all() : [];
        $content_units = [];
        $title_unit = null;
        foreach ((array) $previous['units'] as $unit) {
            $unit['field'] = $unit['field_name'];
            if ($unit['field_name'] === 'content') $content_units[] = $unit;
            if ($unit['field_name'] === 'title') $title_unit = $unit;
        }
        $materializer = 'Lisun_Manual_V2_Materializer';
        $content = $materializer::materialize_document(
            (string) ($previous['revision']['source_skeleton'] ?? ''),
            $content_units,
            (array) $previous['occurrences'],
            [],
            $materializer::MODE_SNAPSHOT
        );
        if (is_wp_error($content)) return $content;
        if (!$title_unit) return new WP_Error('v2_title_missing', '旧版本缺少标题单元');
        $title_occurrences = array_values(array_filter((array) $previous['occurrences'], function ($occ) use ($title_unit) {
            return (string) ($occ['unit_key'] ?? '') === (string) $title_unit['unit_key'];
        }));
        $title = $materializer::materialize_unit(
            (string) $title_unit['target_template'],
            $title_occurrences,
            [],
            'title',
            $materializer::MODE_SNAPSHOT
        );
        if (is_wp_error($title)) return $title;
        if (trim((string) $content) === '' || trim((string) $title) === '') {
            return new WP_Error('empty_restore', '旧版本译文为空，已放弃撤销');
        }

        // Flip active pointer to the previous revision BEFORE touching the post.
        Lisun_Manual_V2_Repository::switch_active_revision($zh_post_id, $previous_id);

        // Update local English post.
        $local_en_id = self::create_local_en_page($post, (string) $content, (string) $title);
        if (is_wp_error($local_en_id) || !$local_en_id) {
            return new WP_Error('local_failed', '本地英文副本更新失败，版本已回滚但页面未更新');
        }

        // Save backup with restored content.
        $tags = wp_get_post_terms($zh_post_id, 'post_tag', ['fields' => 'names']);
        self::save_translation_backup($zh_post_id, [
            'anchor_mode' => 'v2_units',
            'v2_revision_id' => $previous_id,
            'en_title' => $title,
            'en_title_anchored' => $title,
            'en_content' => $content,
            'en_slug' => (string) $post->post_name,
            'en_seo' => [],
            'en_seo_anchored' => [],
            'tags' => $tags ?: [],
            'glossary_hash' => md5(json_encode(Lisun_Manual_Glossary::get_all())),
            'zh_segments' => [],
            'en_parts' => [],
            'segment_hashes' => [],
            'block_locks' => [],
        ]);

        Lisun_Manual_V2_Repository::update_document_state($zh_post_id, 'ready', $local_en_id, '');

        // Re-push to remote.
        $push = Lisun_Manual_Sync::push_translation($zh_post_id, $title, $content, (string) $post->post_name, []);
        if (is_wp_error($push)) {
            update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'push_failed');
            return new WP_Error('push_failed', '本地已回滚，但远程英文站推送失败：' . $push->get_error_message(), [
                'restored' => true,
                'previous_revision_id' => $previous_id,
            ]);
        }

        update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($zh_post_id, '_lisun_manual_last_sync', current_time('mysql'));

        return [
            'restored' => true,
            'previous_revision_id' => $previous_id,
            'applied_revision_id' => $applied_revision_id,
            'local_en_id' => $local_en_id,
            'pushed' => true,
        ];
    }

    /**
     * 增量翻译：只翻译修改过的段落，复用未改动的译文
     *
     * 与上次完整的 translate_post 产生的备份对比：重新切分当前中文内容，
     * 用 md5 哈希匹配未改动的段直接复用译文，只对变更段调 LLM。
     *
     * @param int $post_id 中文 manual 页面 ID
     * @return array|false 组装后的完整英文译文数组，或 false 表示增量不可用需全量翻译
     */
    private static function try_incremental_translate($post_id) {
        // 重译时强制走全量翻译（不清备份，翻译成功后再覆盖，避免失败导致备份丢失）
        if (self::$force_retranslate) {
            error_log('LISUN Manual: incremental — force_retranslate set, fallback to full');
            return false;
        }

        $backup = self::get_translation_backup($post_id);
        if (empty($backup)) {
            error_log('LISUN Manual: incremental — no backup found for post ' . $post_id);
            return false;
        }

        // 严格 HTML 模式只复用已经按中文源锚点保存的备份。旧版英文反查锚点
        // 没有可靠的源文映射，不能拿来增量拼接，否则会把旧格式带回新流程。
        if (self::uses_strict_deepl_html()
            && ($backup['anchor_mode'] ?? '') !== 'source_html_v1') {
            error_log('LISUN Manual: incremental — backup is not source_html_v1, fallback to full for post ' . $post_id);
            return false;
        }

        $old_hashes   = $backup['segment_hashes'] ?? [];
        $old_en_parts = $backup['en_parts'] ?? [];
        $old_zh_segs  = $backup['zh_segments'] ?? [];
        $block_locks  = $backup['block_locks'] ?? [];

        if (empty($old_zh_segs) || empty($old_en_parts)) {
            error_log('LISUN Manual: incremental — backup has no segment data for post ' . $post_id);
            return false;
        }

        // 获取当前中文内容
        $post = get_post($post_id);
        if (!$post) return false;

        // ★ 必须与全量翻译 translate_with_segmentation 用同一套预处理（strip_inline_styles）
        //   + 拆分策略：HTML 模式使用安全分段器，关闭术语表时保留旧 segment_content。
        //   否则增量对比的段哈希与备份里的段哈希粒度/内容不一致，
        //   永远对不上 → 每次都把所有段判为「新增」而全量重译。
        $source_content = self::sanitize_debug_utf8((string) $post->post_content);
        $source_content = self::strip_inline_styles($source_content);

        $max_chars    = self::SEGMENT_MAX_CHARS;
        $new_segments = self::segment_for_translation($source_content, $max_chars);
        if (is_wp_error($new_segments)) return $new_segments;

        // 短文档：只有一个段，直接对比哈希（与旧版逻辑一致）
        if (count($new_segments) <= 1 && count($old_zh_segs) <= 1) {
            $new_segments = [$source_content];
            $new_hash = md5($source_content);
            $old_hash = reset($old_hashes);
            if ($new_hash === $old_hash) {
                $old_en = reset($old_en_parts);
                error_log('LISUN Manual: incremental — no changes detected for post ' . $post_id . ', reusing existing translation');
                return [
                    'content'           => $old_en,
                    'prompt_tokens'     => 0,
                    'completion_tokens' => 0,
                    'from_cache'        => true,
                ];
            }
            error_log('LISUN Manual: incremental — content changed but doc is short, fallback to full');
            return false;
        }

        // 新段哈希
        $new_hashes = [];
        foreach ($new_segments as $seg) {
            $new_hashes[] = md5($seg);
        }

        // LCS 对齐：将旧段数组与新段数组做最长公共子序列对齐
        // 替代原来的位置索引一一对比，支持段落的插入/删除/重排
        $ops = self::lcs_align(array_values($old_hashes), array_values($old_en_parts), $new_hashes);

        // 预统计：新增/变更段数（用于进度提示）+ 变更占比预判
        $total_insert  = 0;
        $total_replace = 0;
        $pre_changed   = 0;
        foreach ($ops as $op) {
            if ($op['action'] === 'insert') {
                $total_insert++;
                $pre_changed++;
            } elseif ($op['action'] === 'replace') {
                $total_replace++;
                $pre_changed++;
            } elseif ($op['action'] === 'delete') {
                $pre_changed++;
            }
        }

        // 预判：变更占比过高时直接回退全量，避免「先逐个翻译几百段、再发现改太多而丢弃」的浪费。
        // 典型场景：旧备份由 docx 管线（纯文本段落哈希）产生，与 HTML 块哈希粒度不一致 → 100% 判为变更。
        if (count($ops) > 0) {
            $pre_ratio = $pre_changed / count($ops);
            if ($pre_ratio > 0.6 && count($new_segments) > 3) {
                error_log('LISUN Manual: incremental — pre-check too many ops changed ('
                    . $pre_changed . '/' . count($ops) . ' = ' . round($pre_ratio * 100) . '%), fallback to full before translating');
                return false;
            }
        }

        // 统计操作类型（循环中实时累加）
        $keep_count    = 0;
        $replace_count = 0;
        $insert_count  = 0;
        $delete_count  = 0;
        $locked_count  = 0;
        $total_prompt  = 0;
        $total_completion = 0;

        self::$progress_post_id = $post_id;

        // 处理每个对齐操作
        $new_en_parts  = [];
        $new_zh_segs   = [];
        $out_idx       = 0;

        foreach ($ops as $op) {
            $action = $op['action'];

            if ($action === 'keep') {
                $old_idx = $op['old_idx'];
                // 复用的旧译文若残留中文（历史静默回退产生的脏数据），改为重新翻译
                if (isset($op['en']) && preg_match('/[\x{4e00}-\x{9fff}]/u', (string) $op['en'])) {
                    error_log('LISUN Manual: incremental — kept segment contains residual Chinese, retranslating');
                    $action = 'replace'; // 落到下面的 replace 分支重新翻译
                } else {
                    $keep_count++;
                    // keep 的段是匹配的，直接复用
                    $new_en_parts[$out_idx] = $op['en'];
                    $new_zh_segs[$out_idx]  = $new_segments[$op['new_idx']];
                    $out_idx++;
                    continue;
                }
            }

            if ($action === 'delete') {
                $delete_count++;
                // 删除的段不进入输出
                continue;
            }

            if ($action === 'insert') {
                $insert_count++;
                $new_idx        = $op['new_idx'];
                $segment_zh     = $new_segments[$new_idx];
                $new_zh_segs[$out_idx] = $segment_zh;

                // 报告进度
                set_transient("lisun_trans_{$post_id}", [
                    'step' => ($out_idx + 1) . '/' . count($ops) . ' (增量)',
                    'msg'  => "正在翻译新增段 " . $insert_count . "/" . $total_insert . "（全文共 " . count($new_segments) . " 段）",
                    'time' => time(),
                ], 300);

                @set_time_limit(600);
                $result = self::translate_with_overflow_protection($segment_zh, $out_idx, count($new_segments));

                if (is_wp_error($result)) {
                    error_log('LISUN Manual: incremental insert segment FAILED: ' . $result->get_error_message());
                    if (self::uses_strict_deepl_html()) return $result;
                    $new_en_parts[$out_idx] = "\n<!-- ⚠ TRANSLATION FAILED — original Chinese below -->\n" . $segment_zh . "\n<!-- /TRANSLATION FAILED -->\n";
                } else {
                    $new_en_parts[$out_idx] = $result['text'];
                    $total_prompt     += $result['prompt_tokens'] ?? 0;
                    $total_completion += $result['completion_tokens'] ?? 0;
                    self::handle_length_truncation($new_segments, $new_en_parts, $out_idx, $total_prompt, $total_completion, $result);
                }
                $out_idx++;
                continue;
            }

            if ($action === 'replace') {
                $new_idx        = $op['new_idx'];
                $old_idx        = $op['old_idx'];
                $segment_zh     = $new_segments[$new_idx];
                $new_zh_segs[$out_idx] = $segment_zh;

                // E7/E8 编辑锁：manual 锁定的段即使中文变更也不覆盖
                if (isset($block_locks[$old_idx]) && $block_locks[$old_idx] === 'manual') {
                    $locked_count++;
                    $new_en_parts[$out_idx] = $op['en'] ?? ($old_en_parts[$old_idx] ?? '');
                    error_log('LISUN Manual: incremental — block ' . $old_idx . ' is locked (manual), reusing old translation');
                    $out_idx++;
                    continue;
                }

                $replace_count++;

                // 报告进度
                set_transient("lisun_trans_{$post_id}", [
                    'step' => ($out_idx + 1) . '/' . count($ops) . ' (增量)',
                    'msg'  => "正在翻译变更段 " . $replace_count . "/" . $total_replace . "（全文共 " . count($new_segments) . " 段）",
                    'time' => time(),
                ], 300);

                @set_time_limit(600);
                $result = self::translate_with_overflow_protection($segment_zh, $out_idx, count($new_segments));

                if (is_wp_error($result)) {
                    error_log('LISUN Manual: incremental replace segment FAILED: ' . $result->get_error_message());
                    if (self::uses_strict_deepl_html()) return $result;
                    $new_en_parts[$out_idx] = "\n<!-- ⚠ TRANSLATION FAILED — original Chinese below -->\n" . $segment_zh . "\n<!-- /TRANSLATION FAILED -->\n";
                } else {
                    $new_en_parts[$out_idx] = $result['text'];
                    $total_prompt     += $result['prompt_tokens'] ?? 0;
                    $total_completion += $result['completion_tokens'] ?? 0;
                    self::handle_length_truncation($new_segments, $new_en_parts, $out_idx, $total_prompt, $total_completion, $result);
                }
                $out_idx++;
                continue;
            }
        }

        delete_transient("lisun_trans_{$post_id}");

        // 变更段数占比超过 60% → 全量翻译更完整（避免新旧译文风格不一致）
        $changed = $replace_count + $insert_count + $delete_count;
        if (count($ops) > 0) {
            $change_ratio = $changed / count($ops);
            if ($change_ratio > 0.6 && count($new_segments) > 3) {
                error_log('LISUN Manual: incremental — too many ops changed (' . $changed . '/' . count($ops) . ' = ' . round($change_ratio*100) . '%), fallback to full');
                return false;
            }
        }

        // 没有变更
        if ($changed === 0) {
            error_log('LISUN Manual: incremental — no changes detected for post ' . $post_id);
        } else {
            $lock_info = $locked_count > 0 ? " ({$locked_count} locked)" : "";
            error_log('LISUN Manual: incremental — k=' . $keep_count . ' r=' . $replace_count . ' i=' . $insert_count . ' d=' . $delete_count . $lock_info . ' for post ' . $post_id);
        }

        // 组装：按输出索引排序
        ksort($new_en_parts);
        ksort($new_zh_segs);
        $assembled = implode("\n", $new_en_parts);

        // 兜底：残留中文的段重新翻译（覆盖 insert/replace 后引擎仍返回中文的情况）
        $rechecked = 0;
        foreach ($new_en_parts as $idx => $en_part) {
            if (!preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', (string) $en_part)) {
                continue;
            }
            $src_seg = $new_zh_segs[$idx] ?? '';
            if ($src_seg === '') {
                continue;
            }
            error_log('LISUN Manual: incremental — segment ' . $idx . ' has residual Chinese, re-translating');
            $retry = self::translate_with_overflow_protection($src_seg, $idx, count($new_en_parts));
            if (!is_wp_error($retry) && !preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $retry['text'] ?? '')) {
                $new_en_parts[$idx] = $retry['text'];
                $total_prompt     += $retry['prompt_tokens'] ?? 0;
                $total_completion += $retry['completion_tokens'] ?? 0;
                $rechecked++;
            } else {
                if (self::uses_strict_deepl_html()) {
                    return new WP_Error('deepl_html_residual_chinese', 'HTML 译文仍包含中文，已停止保存和推送，请重试');
                }
                $new_en_parts[$idx] = "\n<!-- ⚠ TRANSLATION FAILED — original Chinese below -->\n" . $src_seg . "\n<!-- /TRANSLATION FAILED -->\n";
            }
        }
        if ($rechecked > 0) {
            $assembled = implode("\n", $new_en_parts);
        }

        // HTML 模式不修复或重写结构；关闭术语表时保留旧清洗逻辑。
        if (!self::uses_strict_deepl_html()) {
            $assembled = self::clean_gutenberg_comments($assembled);
        }
        if (!self::uses_strict_deepl_html()) {
            $assembled = self::auto_close_html_tags($assembled);
        }
        $assembled = self::apply_glossary($assembled);
        $assembled = self::post_process_translation($assembled);

        // 重映射 block_locks：旧索引 → 新索引（通过 LCS 对齐）
        $remapped_locks = [];
        foreach ($ops as $op) {
            if ($op['action'] === 'keep') {
                $old_i = $op['old_idx'];
                $new_i = $op['new_idx'];
                if (isset($block_locks[$old_i])) {
                    $remapped_locks[$new_i] = $block_locks[$old_i];
                }
            } elseif ($op['action'] === 'replace') {
                $old_i = $op['old_idx'];
                // 如果旧段被手动锁定，保持锁到新位置
                if (isset($block_locks[$old_i]) && $block_locks[$old_i] === 'manual') {
                    // locked replace: old_idx 的锁转移到对应的 new_idx
                    // 注意：new_idx 不在 ops 中直接标明，但 replace 操作中 old_idx→new_idx 是对应的
                    // 从 lcs_align 返回的 replace 操作同时有 old_idx 和 new_idx
                    $new_i = $op['new_idx'];
                    $remapped_locks[$new_i] = 'manual';
                }
            }
            // insert: 不继承锁（新段默认 auto）
            // delete: 丢弃锁
        }

        return [
            'content'           => $assembled,
            'prompt_tokens'     => $total_prompt,
            'completion_tokens' => $total_completion,
            'from_cache'        => ($changed === 0),
            'zh_segments'       => array_values($new_zh_segs),
            'en_parts'          => array_values($new_en_parts),
            'block_locks'       => $remapped_locks,
        ];
    }

    /**
     * 处理翻译结果中的 length truncation，强制对半拆分重试
     *
     * @param array $new_segments    新段数组
     * @param array &$new_en_parts   输出英文段数组
     * @param int   $idx             当前索引
     * @param int   &$total_prompt   累计 prompt tokens
     * @param int   &$total_completion 累计 completion tokens
     * @param array $result          翻译结果
     */
    private static function handle_length_truncation($new_segments, &$new_en_parts, $idx, &$total_prompt, &$total_completion, $result) {
        if (($result['finish_reason'] ?? 'stop') !== 'length') return;

        error_log('LISUN Manual: incremental segment ' . ($idx+1) . ' still truncated, force-splitting...');
        $half = intval(mb_strlen($new_segments[$idx]) / 2);
        if ($half < 100) return;

        $left  = self::translate_with_overflow_protection(
            mb_substr($new_segments[$idx], 0, $half), $idx, count($new_segments)
        );
        $right = self::translate_with_overflow_protection(
            mb_substr($new_segments[$idx], $half), $idx, count($new_segments)
        );
        if (!is_wp_error($left) && !is_wp_error($right)) {
            $new_en_parts[$idx] = $left['text'] . "\n" . $right['text'];
            $total_prompt     += ($left['prompt_tokens'] ?? 0) + ($right['prompt_tokens'] ?? 0);
            $total_completion += ($left['completion_tokens'] ?? 0) + ($right['completion_tokens'] ?? 0);
            error_log('LISUN Manual: incremental force-split succeeded for segment ' . ($idx+1));
        }
    }

    // ==================== 核心翻译流程 ====================

    /**
     * 翻译一篇 manual 页面
     *
     * @param int $post_id 中文 manual 页面 ID
     * @return array|WP_Error
     */
    public static function translate_post($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'manual') {
            return new WP_Error('invalid_post', __('无效的说明书页面', 'lisun-manual'));
        }

        // 页面级锁定：英文已人工定稿，跳过翻译（保护远程站英文不被覆盖）
        if (self::is_en_locked($post_id)) {
            return new WP_Error('en_locked', __('该页面英文已锁定，请先解锁再翻译', 'lisun-manual'));
        }

        // 重置本次翻译的 DeepL 字符累计（每篇独立统计）
        self::$deepl_chars_used = 0;

        // 翻译只使用当前 WordPress 文章的标题和正文。
        // 文章导入完成后，DOCX 只是历史附件，不再参与翻译，避免旧文档标题覆盖当前文章标题。
        self::debug_log('translate_post START post=' . $post_id . ' source=wordpress_post');

        // V2 is deliberately routed before every V1 segmentation/anchor method.
        // Turning the setting off returns immediately to the untouched V1 path.
        if (self::translation_v2_enabled()) {
            return self::translate_post_v2($post);
        }

        // DeepL 术语表必须在任何增量缓存或正文翻译前准备完成；失败时整篇停止，
        // 避免生成一篇没有使用当前术语表的译文。
        if (get_option('lisun_llm_provider', 'deepseek') === 'deepl'
            && Lisun_Manual_Glossary::is_enabled()) {
            $deepl_glossary = Lisun_Manual_Glossary::ensure_deepl_glossary();
            if (is_wp_error($deepl_glossary)) {
                return $deepl_glossary;
            }
        }

        $start_time = microtime(true);
        $result = []; // 全量翻译结果，增量路径可能不设置

        // 1. 提取原文
        $source_content = self::sanitize_debug_utf8((string) $post->post_content);

        // 1b. 预清洗：剥离内联排版样式，减少 LLM 噪声，统一字号
        $source_content = self::strip_inline_styles($source_content);

        // 2. 设置进度追踪
        self::$progress_post_id = $post_id;
        set_transient("lisun_trans_{$post_id}", [
            'step' => '...',
            'msg'  => '正在分析文档结构...',
            'time' => time(),
        ], 300);

        // 3. 尝试增量翻译（只翻译修改过的段落）
        $backup       = self::get_translation_backup($post_id);
        $incremental  = self::try_incremental_translate($post_id);
        $from_cache   = false;
        $verification = [];

        // 增量 HTML 翻译失败必须直接终止，不能把 WP_Error 当成“增量不可用”
        // 再落入旧的全量流程，否则会绕过严格的完整 HTML 校验。
        if (is_wp_error($incremental)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
            Lisun_Manual_Translate_Log::add([
                'zh_page_id' => $post_id, 'en_page_id' => 0,
                'status' => 'failed', 'duration' => microtime(true) - $start_time,
                'chars' => self::$deepl_chars_used,
                'error_msg' => $incremental->get_error_message(),
            ]);
            return $incremental;
        }

        if ($incremental && !empty($incremental['content'])) {
            // 增量翻译成功：复用未改动的译文，只翻译变更段
            $translated_content = $incremental['content'];
            $prompt_tokens      = $incremental['prompt_tokens'] ?? 0;
            $completion_tokens  = $incremental['completion_tokens'] ?? 0;
            $from_cache         = $incremental['from_cache'] ?? false;

            // 标题始终根据中文网站当前文章标题重新翻译，不能复用旧标题缓存。
            // 正文没有变化时也可能只是中文标题被修改，因此标题必须单独刷新。
            $en_title = self::translate_title($post->post_title);
            if (is_wp_error($en_title)) return $en_title;
            // 标题只允许来自中文网站文章标题的翻译。
            // 不再从英文正文第一行兜底，避免把正文中的旧标题写入英文副本标题。
            if (empty($en_title)) {
                $en_title = $post->post_title;
            }

            $tags   = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
            $en_slug = $post->post_name;

            // SEO：内容没变则复用备份，否则重新翻译
            if ($from_cache && !empty($backup['en_seo'])) {
                $en_seo = $backup['en_seo'];
                self::$last_anchored_seo = $backup['en_seo_anchored'] ?? $en_seo;
            } else {
                $en_seo = self::translate_seo_meta($post_id);
                if (is_wp_error($en_seo)) return $en_seo;
            }

            // 合并图片和图注
            $translated_content = self::merge_figure_captions_in_html($translated_content);

            // 增量翻译验证：block 校验 + 漏译自动修复（critical / missing_blocks / 偏短 均触发）
            $verification = self::verify_translation($source_content, $translated_content);
            if (!empty($verification['warnings'])) {
                error_log('LISUN Manual incremental verify: ' . implode('; ', $verification['warnings']));
            }
            $needs_repair = !empty($verification['critical'])
                || $verification['missing_blocks'] > 0
                || (mb_strlen($translated_content) / max(1, mb_strlen($source_content))) < 0.85;
            if ($needs_repair) {
                error_log('LISUN Manual: Issues in incremental translation, attempting repair...');
                $repair = self::self_check($source_content, $translated_content);
                if ($repair && $repair !== $translated_content) {
                    // 验证修复是否真的更好（防止 self_check 反而引入问题）
                    $repair_verification = self::verify_translation($source_content, $repair);
                    $repair_worse = ($repair_verification['missing_blocks'] > $verification['missing_blocks'])
                        || ($repair_verification['trans_blocks'] < $verification['trans_blocks']);
                    if ($repair_worse) {
                        error_log('LISUN Manual: Incremental repair rejected (made things worse)');
                    } else {
                        $translated_content = $repair;
                        $verification = $repair_verification;
                        error_log('LISUN Manual: Incremental repair accepted');
                    }
                }
            }
        } else {
            // 全量翻译
            // 4. 调用 DeepSeek 翻译（长文档自动分段）
            $result = self::translate_with_segmentation($source_content);

            if (is_wp_error($result)) {
                Lisun_Manual_Translate_Log::add([
                    'zh_page_id'    => $post_id,
                    'en_page_id'    => 0,
                    'status'        => 'failed',
                    'duration'      => microtime(true) - $start_time,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'chars'         => self::$deepl_chars_used,
                    'error_msg'     => $result->get_error_message(),
                ]);
                update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
                return $result;
            }

            $translated_content = $result['text'];
            self::debug_numeric_format('after_translate_api', $translated_content, $post_id);
            $prompt_tokens = $result['prompt_tokens'];
            $completion_tokens = $result['completion_tokens'];

            // 5. 验证翻译完整性
            $verification = self::verify_translation($source_content, $translated_content);
            if (!empty($verification['warnings'])) {
                error_log('LISUN Manual translation verify: ' . implode('; ', $verification['warnings']));
            }

            // 漏译 → 再用 DeepSeek 补全一次（critical / missing_blocks / 偏短 均触发）
            $needs_repair = !empty($verification['critical'])
                || $verification['missing_blocks'] > 0
                || (mb_strlen($translated_content) / max(1, mb_strlen($source_content))) < 0.85;
            if ($needs_repair) {
                error_log('LISUN Manual: Translation issues detected, attempting repair...');
                $repair = self::self_check($source_content, $translated_content);
                if ($repair && $repair !== $translated_content) {
                    // 验证修复是否真的更好（防止 self_check 反而引入问题）
                    $repair_verification = self::verify_translation($source_content, $repair);
                    $repair_worse = ($repair_verification['missing_blocks'] > $verification['missing_blocks'])
                        || ($repair_verification['trans_blocks'] < $verification['trans_blocks']);
                    if ($repair_worse) {
                        error_log('LISUN Manual: Repair rejected (made things worse)');
                    } else {
                        $translated_content = $repair;
                        $verification = $repair_verification;
                        error_log('LISUN Manual: Repair accepted, critical=' . ($verification['critical'] ? 'still' : 'fixed'));
                    }
                }
            }

            // 6. 合并图片和图注（兼容之前导入的未合并内容）
            $translated_content = self::merge_figure_captions_in_html($translated_content);

            // 7. 术语表后处理 + 标点清理 + 空格大小写规范化
            $translated_content = self::apply_glossary($translated_content);
            $translated_content = self::post_process_translation($translated_content);
            self::debug_numeric_format('after_post_process', $translated_content, $post_id);

            // 8. 翻译标题 + 生成 slug
            $en_title = self::translate_title($post->post_title);
            if (is_wp_error($en_title)) return $en_title;
            // 标题只允许来自中文网站文章标题的翻译。
            // 翻译失败时保留当前中文标题，不能从正文第一行提取旧标题。
            if (empty($en_title)) {
                $en_title = $post->post_title;
            }
            $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
            $en_slug = $post->post_name;

            // 9. 翻译 SEO 元字段（title / description / keywords）
            $en_seo = self::translate_seo_meta($post_id);
            if (is_wp_error($en_seo)) return $en_seo;
        }

        if (self::term_anchors_enabled()) {
            // 严格 HTML 模式的锚点来自中文源片段，全文再次核对 ID 多重集合。
            $anchor_check = self::validate_glossary_anchors(
                self::add_glossary_anchors($source_content),
                $translated_content
            );
            if (is_wp_error($anchor_check)) {
                update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
                Lisun_Manual_Translate_Log::add([
                    'zh_page_id' => $post_id, 'en_page_id' => 0,
                    'status' => 'failed', 'duration' => microtime(true) - $start_time,
                    'error_msg' => $anchor_check->get_error_message(),
                ]);
                return $anchor_check;
            }
            if (self::uses_strict_deepl_html()) {
                $html_check = self::validate_html_fragment_structure($translated_content, '完整英文译文');
                if (is_wp_error($html_check)) return $html_check;
            }
        } else {
            // 开关关闭时，增量缓存中可能仍有旧锚点；本次结果和新备份均不再保留。
            $translated_content = self::strip_glossary_anchors($translated_content);
        }

        // 绝不能把“只有 HTML 框架、正文文字已丢失”的结果标记为成功并推送。
        $content_guard = self::validate_translation_content($source_content, $translated_content);
        if (is_wp_error($content_guard)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
            self::debug_log('translation content guard failed post=' . $post_id
                . ' error="' . $content_guard->get_error_message() . '"');
            Lisun_Manual_Translate_Log::add([
                'zh_page_id' => $post_id, 'en_page_id' => 0,
                'status' => 'failed', 'duration' => microtime(true) - $start_time,
                'prompt_tokens' => $prompt_tokens, 'completion_tokens' => $completion_tokens,
                'chars' => self::$deepl_chars_used,
                'error_msg' => $content_guard->get_error_message(),
            ]);
            return $content_guard;
        }
        // 后续本地保存与远程推送统一使用已经清洗过的有效 UTF-8 内容。
        $translated_content = self::sanitize_debug_utf8($translated_content);

        // post_title 是纯文本字段：确保异常字节不会进入 wp_insert/update_post，
        // 翻译返回空值或非法值时回退到已经清洗过的中文标题。
        $en_title = self::sanitize_debug_utf8((string) $en_title);
        if (trim($en_title) === '') {
            $en_title = self::sanitize_debug_utf8((string) $post->post_title);
        }
        self::debug_log('local English title payload post=' . $post_id
            . ' chars=' . mb_strlen($en_title)
            . ' utf8=' . (preg_match('//u', $en_title) === 1 ? 'yes' : 'no')
            . ' value="' . mb_substr($en_title, 0, 120) . '"');

        // 标题和 SEO 是纯文本字段；兼容保留旧备份键，但值中绝不保存 span。
        $en_title_anchored = self::strip_glossary_anchors($en_title);
        $en_seo_anchored = $en_seo;

        if (self::term_anchors_enabled()) {
            $content_anchor_count = preg_match_all('/data-lisun-term-id\s*=\s*(["\'])[^"\']+\1/i', $translated_content, $unused_content_anchor_matches);
            $title_anchor_count = preg_match_all('/data-lisun-term-id\s*=\s*(["\'])[^"\']+\1/i', (string) $en_title_anchored, $unused_title_anchor_matches);
            $seo_anchor_count = 0;
            foreach ((array) $en_seo_anchored as $seo_value) {
                if (is_string($seo_value)) {
                    $seo_anchor_count += (int) preg_match_all('/data-lisun-term-id\s*=\s*(["\'])[^"\']+\1/i', $seo_value, $unused_seo_anchor_matches);
                }
            }
            self::debug_log('glossary translation anchor summary post=' . $post_id
                . ' content=' . max(0, (int) $content_anchor_count)
                . ' title=' . max(0, (int) $title_anchor_count)
                . ' seo=' . $seo_anchor_count);
        }

        // 7. TOC 和附录由远程站自行生成，仅推送翻译后的正文
        $push_content = self::strip_glossary_anchors($translated_content);

        // 7.5 在中文站本地创建英文 Post（备份，draft），供重试推送/预览使用
        $local_en_id = self::create_local_en_page($post, $translated_content, $en_title);
        if (is_wp_error($local_en_id)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
            return $local_en_id;
        }
        if (!$local_en_id) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
            return new WP_Error('local_translation_missing', '本地英文备份文章创建失败，已停止远程推送');
        }

        // 8. 推送到远程站
        $push_result = Lisun_Manual_Sync::push_translation($post_id, $en_title, $push_content, $en_slug, $en_seo);

        $duration = microtime(true) - $start_time;

        // 始终保存 JSON 备份（成功或失败都保留，直到手动删除）
        // 同时保存段数据，用于后续增量翻译（修改部分内容只翻译变化的段）
        // 如果没有变化（from_cache=true），保留旧备份不更新以提高保存效率
        if (!$from_cache) {
            $zh_segments = $incremental['zh_segments'] ?? ($result['zh_segments'] ?? []);
            $en_parts    = $incremental['en_parts'] ?? ($result['en_parts'] ?? []);
            $segment_hashes = [];
            foreach ($zh_segments as $idx => $seg) {
                $segment_hashes[$idx] = md5($seg);
            }
            // 增量翻译产生的新锁映射（保留旧手动锁 + 新段默认 auto）
            $new_block_locks = $incremental['block_locks'] ?? ($result['block_locks'] ?? []);
            // 如果既没有增量也没有全量返回 locks，保留旧锁
            if (empty($new_block_locks) && !empty($backup['block_locks'])) {
                $new_block_locks = $backup['block_locks'];
            }

            if (!self::term_anchors_enabled()) {
                foreach ($en_parts as $part_idx => $part) {
                    $en_parts[$part_idx] = self::strip_glossary_anchors($part);
                }
            }
            self::save_translation_backup($post_id, [
                'anchor_mode'    => self::uses_strict_deepl_html() ? 'source_html_v1' : '',
                'en_title'       => $en_title,
                'en_title_anchored' => $en_title_anchored,
                'zh_content_anchored' => self::term_anchors_enabled()
                    ? self::add_glossary_anchors($source_content) : $source_content,
                'en_content'     => $translated_content,
                'en_slug'        => $en_slug,
                'en_seo'         => $en_seo,
                'en_seo_anchored' => $en_seo_anchored,
                'tags'           => $tags ?: [],
                'glossary_hash'  => md5(json_encode(Lisun_Manual_Glossary::get_all())),
                'zh_segments'    => $zh_segments,
                'en_parts'       => $en_parts,
                'segment_hashes' => $segment_hashes,
                'block_locks'    => $new_block_locks,
            ]);
        } else {
            // 无正文变更时仍要刷新锚点格式元数据。旧代码只刷新时间戳，
            // 导致正文已经有新锚点但 anchor_mode 仍为空，应用术语表会
            // 错误提示“旧版译文格式”。
            $existing = self::get_translation_backup($post_id);
            if ($existing) {
                if (self::term_anchors_enabled()) {
                    // 只要本次通过了全文锚点校验，就把当前缓存结果明确
                    // 标记为新格式，并保存中文源锚点快照。
                    $existing['anchor_mode'] = 'source_html_v1';
                    $existing['zh_content_anchored'] = self::add_glossary_anchors($source_content);
                    $existing['en_content'] = $translated_content;
                    $existing['en_title'] = $en_title;
                    $existing['en_title_anchored'] = $en_title_anchored;
                    $existing['en_seo'] = $en_seo;
                    $existing['en_seo_anchored'] = $en_seo_anchored;
                    $existing['glossary_hash'] = md5(json_encode(Lisun_Manual_Glossary::get_all()));
                } else {
                    $existing['anchor_mode'] = '';
                }
                self::save_translation_backup($post_id, $existing);
            }
        }
        if (is_wp_error($push_result)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'push_failed');
            error_log('LISUN Manual push failed (backup saved): ' . $push_result->get_error_message());

            Lisun_Manual_Translate_Log::add([
                'zh_page_id'    => $post_id,
                'en_page_id'    => 0,
                'status'        => 'translated_no_push',
                'duration'      => $duration,
                'prompt_tokens' => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'chars'         => self::$deepl_chars_used,
                'error_msg'     => $push_result->get_error_message(),
            ]);

            $segments_count = !empty($zh_segments) ? count($zh_segments) : ($result['segments'] ?? 1);
            return [
                'duration'          => round($duration, 2),
                'prompt_tokens'     => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
                'chars'             => self::$deepl_chars_used,
                'segments'          => $segments_count,
                'verification'      => $verification,
                'pushed'            => false,
                'push_msg'          => $push_result->get_error_message(),
            ];
        }

        // 推送成功
        update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($post_id, '_lisun_manual_last_sync', current_time('mysql'));
        // 记录翻译时的内容哈希，用于后续变更检测（统一用 get_content_hash 基准）
        update_post_meta($post_id, '_lisun_manual_content_hash', self::get_content_hash($post->post_content));

        Lisun_Manual_Translate_Log::add([
            'zh_page_id'    => $post_id,
            'en_page_id'    => 0,
            'status'        => 'success',
            'duration'      => $duration,
            'prompt_tokens' => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'chars'         => self::$deepl_chars_used,
            'error_msg'     => '',
        ]);

        $segments_count = !empty($zh_segments) ? count($zh_segments) : ($result['segments'] ?? 1);
        return [
            'duration'          => round($duration, 2),
            'prompt_tokens'     => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'chars'             => self::$deepl_chars_used,
            'segments'          => $segments_count,
            'verification'      => $verification,
            'pushed'            => true,
            'push_msg'          => '',
        ];
    }

    /**
     * Translation V2 publication wrapper. The runner commits only a complete
     * unit revision; local/remote posts always receive marker-free public HTML.
     */
    private static function translate_post_v2($post) {
        $post_id = (int) $post->ID;
        $start_time = microtime(true);
        self::$progress_post_id = $post_id;
        set_transient("lisun_trans_{$post_id}", [
            'step' => 'processing',
            'stage' => 'extracting',
            'progress' => 8,
            'msg' => '正在提取翻译单元…',
            'time' => time(),
        ], 600);

        $result = Lisun_Manual_V2_Runner::translate_document(
            $post_id,
            (string) $post->post_content,
            (string) $post->post_title,
            (bool) self::$force_retranslate
        );
        if (is_wp_error($result)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
            Lisun_Manual_V2_Repository::update_document_state($post_id, 'failed', null, $result->get_error_message());
            Lisun_Manual_Translate_Log::add([
                'zh_page_id' => $post_id,
                'status' => 'failed',
                'duration' => microtime(true) - $start_time,
                'chars' => 0,
                'error_msg' => '[V2] ' . $result->get_error_message(),
            ]);
            return $result;
        }
        return self::publish_v2_result($post, $result, false, $start_time);
    }

    /** Save clean local content, keep a retry backup, then push the same content. */
    private static function publish_v2_result($post, $result, $is_glossary_apply = false, $start_time = null) {
        $post_id = (int) $post->ID;
        $start_time = $start_time ?: microtime(true);
        $content = (string) ($result['content'] ?? '');
        $title = sanitize_text_field((string) ($result['title'] ?? ''));
        if ($content === '' || $title === '') {
            return new WP_Error('v2_empty_publication', 'V2 生成的公开标题或正文为空，未保存和推送');
        }
        if (strpos($content, '{{LISUN_TERM:') !== false
            || strpos($content, 'lisun-v2-unit:') !== false
            || preg_match('/data-lisun-(?:term|occurrence)-id/i', $content)) {
            return new WP_Error('v2_internal_marker_leak', 'V2 内部标记未完全移除，未保存和推送');
        }

        $backup = self::get_translation_backup($post_id);
        if ($is_glossary_apply) {
            // 术语表应用是纯本地锚点替换：不能因为旧备份没有 SEO 英文值
            // 又进入 translate_seo_meta()，否则一次“应用术语表”会偷偷调用
            // DeepL，并可能在远程请求前触发 524 超时。
            $en_seo = (array) ($backup['en_seo'] ?? []);
            self::diagnostic_log('V2 glossary publish post=' . $post_id . ' SEO translation skipped');
        } else {
            $en_seo = self::translate_seo_meta($post_id);
            if (is_wp_error($en_seo)) return $en_seo;
        }
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $slug = (string) $post->post_name;

        $local_en_id = self::create_local_en_page($post, $content, $title);
        if (is_wp_error($local_en_id) || !$local_en_id) {
            $message = is_wp_error($local_en_id) ? $local_en_id->get_error_message() : '本地英文副本创建失败';
            Lisun_Manual_V2_Repository::update_document_state($post_id, 'local_failed', null, $message);
            return new WP_Error('v2_local_save', $message . '，远程未推送');
        }

        self::save_translation_backup($post_id, [
            'anchor_mode' => 'v2_units',
            'v2_revision_id' => (int) ($result['revision_id'] ?? 0),
            'en_title' => $title,
            'en_title_anchored' => $title,
            'en_content' => $content,
            'en_slug' => $slug,
            'en_seo' => $en_seo,
            'en_seo_anchored' => $en_seo,
            'tags' => $tags ?: [],
            'glossary_hash' => md5(json_encode(Lisun_Manual_Glossary::get_all())),
            'zh_segments' => [],
            'en_parts' => [],
            'segment_hashes' => [],
            'block_locks' => [],
        ]);
        $term_anomalies = array_values((array) ($result['term_anomalies'] ?? []));
        if ($term_anomalies) {
            update_post_meta($post_id, '_lisun_manual_v2_term_anomalies', $term_anomalies);
        } else {
            delete_post_meta($post_id, '_lisun_manual_v2_term_anomalies');
        }
        Lisun_Manual_V2_Repository::update_document_state($post_id, 'ready_to_push', $local_en_id, '');

        self::update_translation_progress($post_id, 'pushing', 93, '译文已保存，正在推送到远程站…');
        $push = Lisun_Manual_Sync::push_translation($post_id, $title, $content, $slug, $en_seo);
        $duration = microtime(true) - $start_time;
        self::$deepl_chars_used = (int) ($result['characters'] ?? 0);
        if (is_wp_error($push)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'push_failed');
            Lisun_Manual_V2_Repository::update_document_state($post_id, 'ready_to_push', $local_en_id, $push->get_error_message());
            Lisun_Manual_Translate_Log::add([
                'zh_page_id' => $post_id,
                'en_page_id' => $local_en_id,
                'status' => 'translated_no_push',
                'duration' => $duration,
                'chars' => self::$deepl_chars_used,
                'error_msg' => '[V2] ' . $push->get_error_message(),
            ]);
            return [
                'v2' => true,
                'local_updated' => true,
                'pushed' => false,
                'push_msg' => $push->get_error_message(),
                'translated_units' => (int) ($result['translated_units'] ?? 0),
                'reused_units' => (int) ($result['reused_units'] ?? 0),
                'occurrences' => (int) ($result['occurrences'] ?? 0),
                'requests' => (int) ($result['requests'] ?? 0),
                'term_anomaly_units' => count($term_anomalies),
                'term_anomalies' => $term_anomalies,
            ];
        }

        update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($post_id, '_lisun_manual_last_sync', current_time('mysql'));
        update_post_meta($post_id, '_lisun_manual_content_hash', self::get_content_hash($post->post_content));
        Lisun_Manual_V2_Repository::update_document_state($post_id, 'synced', $local_en_id, '');
        Lisun_Manual_Translate_Log::add([
            'zh_page_id' => $post_id,
            'en_page_id' => $local_en_id,
            'status' => $is_glossary_apply ? 'glossary_reapply' : 'success',
            'duration' => $duration,
            'chars' => self::$deepl_chars_used,
        ]);
        if (!$is_glossary_apply
            && class_exists('Lisun_Manual_Glossary_Apply')
            && !empty($result['glossary_term_ids'])) {
            Lisun_Manual_Glossary_Apply::record_full_translation_applications(
                $post_id,
                (int) ($result['revision_id'] ?? 0),
                (array) $result['glossary_term_ids']
            );
        }
        return [
            'v2' => true,
            'message' => $is_glossary_apply ? 'V2 术语表已应用并推送' : 'V2 翻译完成并推送',
            'remote_id' => (int) $push,
            'pushed' => true,
            'revision_id' => (int) ($result['revision_id'] ?? 0),
            'translated_units' => (int) ($result['translated_units'] ?? 0),
            'reused_units' => (int) ($result['reused_units'] ?? 0),
            'occurrences' => (int) ($result['occurrences'] ?? 0),
            'requests' => (int) ($result['requests'] ?? 0),
            'chars' => self::$deepl_chars_used,
            'duration' => round($duration, 2),
            'term_anomaly_units' => count($term_anomalies),
            'term_anomalies' => $term_anomalies,
        ];
    }

    /**
     */
    private static function verify_translation($source, $translated) {
        $warnings = [];

        // 1. 段落/块元素数量对比（HTML 闭合标签）
        $src_blocks = preg_match_all('/<\/(?:p|h[1-6]|table|li|figcaption)\s*>/', $source);
        $tgt_blocks = preg_match_all('/<\/(?:p|h[1-6]|table|li|figcaption)\s*>/', $translated);
        $missing_blocks = max(0, $src_blocks - $tgt_blocks);
        if ($missing_blocks > 0) {
            $warnings[] = sprintf('缺少 %d 个块元素（原文 %d → 译文 %d）',
                $missing_blocks, $src_blocks, $tgt_blocks);
        }

        // 2. 标题数量对比
        $src_h = preg_match_all('/<h[1-6]\b[^>]*>/i', $source);
        $tgt_h = preg_match_all('/<h[1-6]\b[^>]*>/i', $translated);
        if ($tgt_h < $src_h) {
            $warnings[] = sprintf('缺少标题：原文 %d 个 → 译文 %d 个', $src_h, $tgt_h);
        }

        // 3. 图片数量对比
        $src_img = substr_count($source, '<img ');
        $tgt_img = substr_count($translated, '<img ');
        if ($tgt_img < $src_img) {
            $warnings[] = sprintf('缺少图片标签：原文 %d 个 → 译文 %d 个', $src_img, $tgt_img);
        }

        // 4. 表格数量对比（同时检查开标签和闭标签）
        $src_tbl = substr_count($source, '<table');
        $tgt_tbl = substr_count($translated, '<table');
        if ($tgt_tbl < $src_tbl) {
            $warnings[] = sprintf('缺少表格：原文 %d 个 → 译文 %d 个', $src_tbl, $tgt_tbl);
        }
        // 4b. 表格闭合标签检查（防止表格未闭合吞噬页脚）
        $tgt_tbl_close = substr_count($translated, '</table>');
        if ($tgt_tbl_close < $tgt_tbl) {
            $warnings[] = sprintf('缺少表格闭合标签：开 %d 个 → 闭 %d 个', $tgt_tbl, $tgt_tbl_close);
        }

        // 5. 长度比：中译英通常 1.2~2.0x，低于 0.8x 说明漏译
        $ratio = mb_strlen($translated) / max(1, mb_strlen($source));
        if ($ratio < 0.7) {
            $warnings[] = sprintf('译文长度仅原文 %d%%，严重漏译（原文 %d → 译文 %d 字符）',
                round($ratio * 100), mb_strlen($source), mb_strlen($translated));
        } elseif ($ratio < 0.9) {
            $warnings[] = sprintf('译文偏短（原文 %d%%，原文 %d → 译文 %d 字符），可能漏译',
                round($ratio * 100), mb_strlen($source), mb_strlen($translated));
        }

        // 6. HTML 块元素保留率（兼容旧文章的 Gutenberg 标记）
        $wp_open = substr_count($source, '<!-- wp:');
        $wp_open_trans = substr_count($translated, '<!-- wp:');
        if ($wp_open > 0 && $wp_open_trans < $wp_open * 0.95) {
            $warnings[] = sprintf('块标记丢失（原文 %d → 译文 %d）',
                $wp_open, $wp_open_trans);
        }

        // 7. 残留中文
        if (preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $translated)) {
            $warnings[] = '译文中仍残留中文字符';
        }

        $is_critical = ($ratio < 0.8 || $missing_blocks > 0 || $tgt_h < $src_h || $tgt_img < $src_img);
        return [
            'ok'             => empty($warnings),
            'critical'       => $is_critical,
            'source_blocks'  => $src_blocks,
            'trans_blocks'   => $tgt_blocks,
            'missing_blocks' => $missing_blocks,
            'warnings'       => $warnings,
        ];
    }

    /**
     * 翻译结果硬性内容保护：有可见原文时，译文不能只有空 HTML 框架。
     * verify_translation 只产生 warning，不能单独阻止远程推送；这里负责最终拦截。
     */
    private static function validate_translation_content($source, $translated) {
        $source = self::sanitize_debug_utf8((string) $source);
        $translated = self::sanitize_debug_utf8((string) $translated);
        $source_plain = trim(wp_strip_all_tags($source));
        $translated_plain = trim(wp_strip_all_tags($translated));
        if ($source_plain === '') return true;

        $source_chars = preg_match_all('/[\p{L}\p{N}]/u', $source_plain, $unused);
        $translated_chars = preg_match_all('/[\p{L}\p{N}]/u', $translated_plain, $unused);
        $source_chars = max(0, (int) $source_chars);
        $translated_chars = max(0, (int) $translated_chars);
        $ratio = $translated_chars / max(1, $source_chars);
        if ($translated_chars === 0 || ($source_chars >= 30 && $ratio < 0.15)) {
            self::debug_log('translation content metrics source_visible_chars=' . $source_chars
                . ' translated_visible_chars=' . $translated_chars
                . ' ratio=' . round($ratio, 3));
            return new WP_Error('translation_content_empty', '翻译结果正文为空或严重缺失，已停止保存和远程推送，请重试');
        }
        return true;
    }

    // ==================== REST API 处理 ====================

    // ==================== 队列系统 ====================

    /**
     * 创建翻译队列表
     */
    public static function create_queue_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `post_id` BIGINT UNSIGNED NOT NULL,
            `action` VARCHAR(20) NOT NULL DEFAULT 'translate',
            `batch_id` VARCHAR(64) NOT NULL DEFAULT '',
            `payload` LONGTEXT NULL,
            `result_json` LONGTEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `error_msg` VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_status` (`status`),
            INDEX `idx_post_id` (`post_id`),
            INDEX `idx_batch_id` (`batch_id`)
        ) ENGINE=InnoDB {$charset}";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // dbDelta 不会可靠地从带有 IF NOT EXISTS 的旧建表语句中识别新增列。
        // 已安装插件的旧队列表没有术语批次所需字段时，必须在正常请求中补齐，
        // 否则 INSERT 会失败却被前端误显示为“已排队”。
        if (self::$queue_schema_checked) return;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        $required_columns = [
            'batch_id'    => "ADD COLUMN `batch_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `action`",
            'payload'     => "ADD COLUMN `payload` LONGTEXT NULL AFTER `batch_id`",
            'result_json' => "ADD COLUMN `result_json` LONGTEXT NULL AFTER `payload`",
        ];
        foreach ($required_columns as $column => $alter) {
            if (in_array($column, (array) $columns, true)) continue;
            $ok = $wpdb->query("ALTER TABLE `{$table}` {$alter}");
            if ($ok === false) {
                self::debug_log('queue schema upgrade FAILED column=' . $column . ' db_error=' . $wpdb->last_error);
                return; // 下次请求继续尝试，不能把失败缓存起来。
            }
            self::debug_log('queue schema upgrade added column=' . $column);
        }
        $has_batch_index = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", 'idx_batch_id'));
        if (!$has_batch_index) {
            $ok = $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `idx_batch_id` (`batch_id`)");
            if ($ok === false) {
                self::debug_log('queue schema upgrade FAILED index=idx_batch_id db_error=' . $wpdb->last_error);
                return;
            }
        }
        self::$queue_schema_checked = true;
    }

    /**
     * 入队：将一个翻译任务加入队列
     *
     * @param int    $post_id
     * @param string $action  'translate' | 'retranslate'
     */
    private static function enqueue_job($post_id, $action = 'translate', $payload = [], $batch_id = '') {
        // 页面级锁定：不入队，跳过翻译
        if (self::is_en_locked($post_id)) {
            self::debug_log("queue enqueue skipped post={$post_id} action={$action} reason=en_locked");
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';

        // 确保表存在（插件更新后激活钩子不会重新执行）
        self::create_queue_table();

        // A worker can be terminated after claiming a row. Reclaim an old
        // processing row before deciding that this article is already busy;
        // otherwise a later retry only receives the dead job's ID and keeps
        // polling "processing" forever.
        //
        // The recovery is scoped by post_id: passing the post id as a *job id*
        // (the previous code did) could flip an unrelated article's running job
        // back to pending, which started a second worker on the same document.
        self::recover_stale_queue_jobs([], null, (int) $post_id);

        // 同一文章不能同时执行翻译和术语应用，否则两个任务可能互相覆盖。
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE post_id = %d AND status IN ('pending', 'processing') LIMIT 1",
            $post_id
        ));
        if ($existing) {
            // 全量重译必须覆盖尚未开始的旧增量任务；否则前端虽请求
            // retranslate，worker 仍会执行旧的 translate action。
            if ($action === 'retranslate') {
                $existing_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, action, status FROM `{$table}` WHERE id = %d LIMIT 1",
                    (int) $existing
                ));
                if ($existing_row && $existing_row->status === 'pending') {
                    $wpdb->update($table, ['action' => 'retranslate', 'updated_at' => current_time('mysql')], ['id' => (int) $existing]);
                    self::debug_log("queue upgraded post={$post_id} job={$existing} action=retranslate");
                }
            }
            self::debug_log("queue enqueue skipped post={$post_id} action={$action} existing_job={$existing}");
            return (int) $existing;
        }

        $inserted = $wpdb->insert($table, [
            'post_id'    => $post_id,
            'action'     => $action,
            'batch_id'   => sanitize_text_field($batch_id),
            'payload'    => !empty($payload) ? wp_json_encode($payload) : null,
            'status'     => 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        if (!$inserted) {
            self::debug_log("queue enqueue FAILED post={$post_id} action={$action} db_error=" . $wpdb->last_error);
            return 0;
        }

        $job_id = (int) $wpdb->insert_id;
        // A new incremental/full translation supersedes the previous page
        // status. Clear the stale failure before the worker starts so the
        // metabox cannot show "failed" together with current progress.
        if (in_array($action, ['translate', 'retranslate'], true)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'pending');
            delete_post_meta($post_id, '_lisun_manual_last_error');
        }
        self::debug_log("queue enqueue post={$post_id} action={$action} job={$job_id} status=pending");
        return $job_id;
    }

    /** Requeue processing jobs whose worker stopped renewing its heartbeat. */
    public static function recover_stale_queue_jobs($job_ids = [], $max_age = null, $post_id = 0) {
        global $wpdb;
        self::create_queue_table();
        $table = $wpdb->prefix . 'lisun_translate_queue';
        $stale_seconds = $max_age === null
            ? self::WORKER_STALE_SECONDS
            : max(60, (int) $max_age);
        $where = "status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL " . $stale_seconds . " SECOND)";
        $job_ids = array_values(array_unique(array_filter(array_map('absint', (array) $job_ids))));
        if ($job_ids) {
            $where .= ' AND id IN (' . implode(',', $job_ids) . ')';
        }
        // Callers that only know the article must not pass its id as a job id.
        if ((int) $post_id > 0) {
            $where .= $wpdb->prepare(' AND post_id = %d', (int) $post_id);
        }
        $changed = $wpdb->query(
            "UPDATE `{$table}` SET status = 'pending', error_msg = '', updated_at = NOW() WHERE {$where}"
        );
        if ($changed) {
            self::debug_log('queue stale recovery requeued=' . (int) $changed
                . ($job_ids ? ' ids=' . implode(',', $job_ids) : ' scope=' . ((int) $post_id > 0 ? 'post ' . (int) $post_id : 'all'))
                . ' max_age=' . $stale_seconds);
        }
        return max(0, (int) $changed);
    }

    /** Queue one term-scoped glossary application from the Glossary screen. */
    public static function enqueue_glossary_apply_job($post_id, $batch_id, $term_id, $mode, $glossary_revision, $term_revision = 0) {
        return self::enqueue_job((int) $post_id, 'glossary_apply', [
            'term_id' => sanitize_text_field($term_id),
            'mode' => in_array($mode, ['direct', 'incremental', 'legacy'], true) ? $mode : 'direct',
            'glossary_revision' => (int) $glossary_revision,
            'term_revision' => max(0, (int) $term_revision),
        ], $batch_id);
    }

    /**
     * Queue one normal (non-forced) translation from another admin screen.
     *
     * The V2 planner will reuse every unchanged unit, so this is the safe
     * recovery path for a document whose page-level source hash is stale.
     */
    public static function enqueue_incremental_translation_job($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || self::is_en_locked($post_id)) return 0;

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'manual') return 0;

        $job_id = self::enqueue_job($post_id, 'translate');
        if (!$job_id) return 0;

        set_transient("lisun_trans_{$post_id}", [
            'step' => 'queued', 'stage' => 'queued', 'progress' => 2,
            'msg' => '已进入增量翻译队列，等待开始…', 'time' => time(),
        ], 3600);
        return (int) $job_id;
    }

    /**
     * 保存后自动全量重译使用的持久化入队入口。
     * [修改时间：2026-11-04 15:31:00 +08:00] 与增量队列共用幂等、锁定和 Worker 机制。
     */
    public static function enqueue_retranslate_translation_job($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || self::is_en_locked($post_id)) return 0;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'manual') return 0;
        $job_id = self::enqueue_job($post_id, 'retranslate');
        if (!$job_id) return 0;
        set_transient("lisun_trans_{$post_id}", [
            'step' => 'queued', 'stage' => 'queued', 'progress' => 2,
            'msg' => '已进入全量重译队列，等待开始…', 'time' => time(),
        ], 3600);
        return (int) $job_id;
    }

    /**
     * 获取一个翻译并发槽位（MySQL advisory lock）。
     *
     * 批量翻译/进度轮询会 fire-and-forget 触发大量 worker，若不加限流会耗尽
     * FPM 与 MySQL 连接导致源站打崩（Cloudflare 525）。锁随连接释放，进程被
     * kill/OOM 时 MySQL 会自动释放，不会残留死锁。
     *
     * @param int $wait_seconds 等待秒数（0=非阻塞，拿不到立即返回 false）
     * @return bool 是否拿到槽位（GET_LOCK 异常时降级为不限流，避免翻译整体失效）
     */
    private static function acquire_worker_slot($wait_seconds = 0) {
        global $wpdb;
        $max = max(1, intval(self::WORKER_MAX_CONCURRENCY));
        $deadline = ($wait_seconds > 0) ? (microtime(true) + $wait_seconds) : 0.0;

        while (true) {
            $saw_error = false;
            for ($i = 0; $i < $max; $i++) {
                $name = $wpdb->prefix . 'lisun_worker_' . $i;
                $got  = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
                if ($got === '1' || $got === 1) {
                    self::$worker_slot = $name;
                    return true;
                }
                if ($got === null) {
                    $saw_error = true;
                }
            }
            if ($saw_error) {
                // GET_LOCK 不可用（被禁用/权限不足等）：降级为不限流，优先保证能翻译
                error_log('LISUN Manual: GET_LOCK unavailable, concurrency limit disabled');
                self::$worker_slot = null;
                return true;
            }
            if ($deadline <= 0 || microtime(true) >= $deadline) {
                return false; // 非阻塞或已超时
            }
            usleep(500000); // 0.5s 后再试
        }
    }

    /**
     * 释放当前翻译并发槽位
     */
    private static function release_worker_slot() {
        if (empty(self::$worker_slot)) return;
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::$worker_slot));
        self::$worker_slot = null;
    }

    /**
     * 翻译期间心跳：定时刷新当前队列任务的 updated_at，避免被 stale 阈值误判为卡死。
     * 由 translate_one_segment / translate_raw_paragraphs 等高频点调用，按 30 秒节流。
     */
    private static function heartbeat() {
        if (self::$current_job_id <= 0) return;
        if (time() - self::$last_heartbeat < 30) return;
        self::$last_heartbeat = time();
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET updated_at = NOW() WHERE id = %d AND status = 'processing'",
            self::$current_job_id
        ));

    }

    /**
     * 处理队列中的下一个待处理任务
     *
     * 由 Worker 端点调用（通过 localhost 绕过 Cloudflare）。
     * 每次调用只处理一个任务，耗时可能 2-5 分钟。
     *
     * @return array|null 处理结果，队列空时返回 null
     */
    public static function process_next_job($preferred_post_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';
        self::create_queue_table(); // 确保表存在
        self::debug_log('worker process_next_job ENTER');

        // 并发限流：拿不到槽位则本次不翻译，pending 任务留待后续 worker
        if (!self::acquire_worker_slot()) {
            self::debug_log('worker process_next_job SKIP reason=concurrency_full');
            return null;
        }

        // 用户刚点击的文章优先处理。否则队列前面一篇旧 pending 任务会
        // 把当前文章挡住，前端只能一直显示“排队中”。没有指定文章时
        // 仍按创建顺序处理批量队列。
        $preferred_post_id = absint($preferred_post_id);
        if ($preferred_post_id > 0) {
            $job = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE status = 'pending'
                 ORDER BY (post_id = %d) DESC, id ASC
                 LIMIT 1",
                $preferred_post_id
            ));
        } else {
            $job = $wpdb->get_row("SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        }
        if (!$job) {
            self::debug_log('worker process_next_job EMPTY');
            self::release_worker_slot();
            return null;
        }

        // 原子认领，避免多个 worker 同时读到同一条 pending 记录后重复执行。
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = 'processing', updated_at = NOW() WHERE id = %d AND status = 'pending'",
            (int) $job->id
        ));
        if ((int) $claimed !== 1) {
            self::debug_log('worker process_next_job SKIP reason=claim_lost job=' . (int) $job->id);
            self::release_worker_slot();
            return null;
        }

        // 记录当前任务 id，供心跳续租使用
        self::$current_job_id = intval($job->id);

        $post_id = intval($job->post_id);
        self::debug_log('worker job START job=' . intval($job->id)
            . ' post=' . $post_id . ' action=' . (string) $job->action);

        // 页面级锁定：跳过该任务（不重译，保护远程站英文），标记完成避免反复重试
        if (self::is_en_locked($post_id)) {
            $wpdb->update($table, [
                'status'     => 'done',
                'error_msg'  => 'EN locked, skipped',
                'result_json'=> wp_json_encode(['skipped' => true, 'reason' => 'EN locked']),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job->id]);
            error_log("LISUN Manual: post {$post_id} is EN-locked, skipping queued job");
            self::release_worker_slot();
            self::$current_job_id = 0;
            return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'skipped'];
        }

        // 每个任务先重置重译标志，避免 worker-loop 连续处理多篇时标志串到下一篇
        self::$force_retranslate = false;

        // 重译时清除旧数据（不清备份：force_retranslate 会强制走全量翻译，成功后覆盖备份）
        if ($job->action === 'retranslate') {
            $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);
            if ($target_id) delete_post_meta($post_id, '_lisun_manual_target_id');
            delete_transient("lisun_trans_cp_{$post_id}");
            self::$force_retranslate = true;
        }

        delete_transient("lisun_trans_result_{$post_id}");
        set_transient("lisun_trans_{$post_id}", [
            'step' => 'processing',
            'stage' => 'starting',
            'progress' => 5,
            'msg'  => '正在启动翻译…',
            'time' => time(),
        ], 600);

        @set_time_limit(600);
        @ignore_user_abort(true);

        try {
            if ($job->action === 'glossary_apply') {
                $payload = json_decode((string) ($job->payload ?? ''), true);
                $payload = is_array($payload) ? $payload : [];
                $result = self::apply_glossary_term_to_post(
                    $post_id,
                    (string) ($payload['term_id'] ?? ''),
                    (string) ($payload['mode'] ?? 'direct'),
                    (int) ($payload['glossary_revision'] ?? 0),
                    (int) ($payload['term_revision'] ?? 0)
                );
            } else {
                $result = self::translate_post($post_id);
            }

            // A successful DeepL response is not enough for a glossary apply:
            // verify the visible English wording after the committed revision.
            // Stable V2 anchors are reported separately because an anchor can
            // be missing while the visible target wording is still correct.
            if (!is_wp_error($result)
                && $job->action === 'glossary_apply'
                && class_exists('Lisun_Manual_Glossary_Apply')) {
                $verification = Lisun_Manual_Glossary_Apply::verify_post_application(
                    $post_id,
                    (string) ($payload['term_id'] ?? '')
                );
                if (is_wp_error($verification)) {
                    $verification_data = $verification->get_error_data();
                    $verification_data = is_array($verification_data) ? $verification_data : [];
                    $verification_data['revision_id'] = (int) ($result['revision_id'] ?? 0);
                    $verification_data['pushed'] = !empty($result['pushed']);
                    $verification_data['updated'] = (int) ($result['updated'] ?? 0);
                    $verification_data['requests'] = (int) ($result['requests'] ?? 0);
                    $verification_data['term_anomaly_units'] = (int) ($result['term_anomaly_units'] ?? 0);
                    $verification_data['term_anomalies'] = is_array($result['term_anomalies'] ?? null)
                        ? $result['term_anomalies'] : [];
                    $result = new WP_Error(
                        $verification->get_error_code(),
                        $verification->get_error_message(),
                        $verification_data
                    );
                } elseif (is_array($verification)) {
                    // Keep the post-commit audit with the successful result as
                    // well. The UI must be able to distinguish visible target
                    // wording from stable V2 anchors after a successful job.
                    $result['post_apply_audit'] = $verification;
                    $result['expected_occurrences'] = (int) ($verification['hit_count'] ?? 0);
                    $result['applied_occurrences'] = (int) ($verification['visible_applied_count'] ?? 0);
                    $result['visible_applied_occurrences'] = (int) ($verification['visible_applied_count'] ?? 0);
                    $result['stable_anchor_occurrences'] = (int) ($verification['stable_anchor_count'] ?? 0);
                    // A partial visible count or an anchor shortfall is a
                    // review warning after the revision was committed and
                    // pushed. It is not a translation failure.
                    $result['warning_only'] = !empty($verification['term_count_warning'])
                        || !empty($verification['anchor_incomplete']);
                }
            }

            if (is_wp_error($result)) {
                $error_data = $result->get_error_data();
                $error_data = is_array($error_data) ? $error_data : [];
                $error_data['code'] = $result->get_error_code();
                $display_error = $result->get_error_message();
                // Older V2 glossary workers returned a WP_Error after the
                // revision had already been committed and pushed. A partial
                // visible result is a warning; only a zero-visible result is
                // a retryable glossary failure.
                $warning_audit = is_array($error_data['post_apply_audit'] ?? null)
                    ? $error_data['post_apply_audit'] : [];
                $warning_expected = (int) ($error_data['expected_occurrences']
                    ?? ($warning_audit['hit_count'] ?? 0));
                $warning_visible = (int) ($error_data['visible_applied_occurrences']
                    ?? ($warning_audit['visible_applied_count']
                    ?? ($error_data['applied_occurrences'] ?? 0)));
                $warning_missing = (int) ($warning_audit['visible_missing_count']
                    ?? ($warning_audit['unapplied_count'] ?? 0));
                $warning_hard_failure = !empty($warning_audit['term_count_failure'])
                    || ($warning_expected > 0 && $warning_visible === 0
                        && $warning_missing >= $warning_expected);
                $committed_glossary_warning = $job->action === 'glossary_apply'
                    && !$warning_hard_failure
                    && $warning_expected > 0
                    && $warning_visible > 0
                    && $warning_visible < $warning_expected
                    && (!empty($error_data['pushed'])
                        || !empty($error_data['revision_id'])
                        || !empty($error_data['attempted']));
                if ($committed_glossary_warning) {
                    $warning_result = $error_data;
                    $warning_result['warning_only'] = true;
                    $warning_result['pushed'] = true;
                    $warning_stable = (int) ($warning_audit['stable_anchor_count']
                        ?? ($error_data['stable_anchor_occurrences'] ?? 0));
                    $warning_result['message'] = '术语应用已完成：可见英文术语 ' . $warning_visible . '/' . $warning_expected
                        . '；稳定锚点 ' . $warning_stable . '/' . $warning_expected
                        . '；另有 ' . max(0, $warning_expected - $warning_visible)
                        . ' 处需人工核对（不等同于翻译失败）';
                    $warning_result['warning_message'] = $warning_result['message'];
                    $wpdb->update($table, [
                        'status'      => 'done',
                        'error_msg'   => '',
                        'result_json' => wp_json_encode($warning_result),
                        'updated_at'  => current_time('mysql'),
                    ], ['id' => $job->id]);
                    update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
                    delete_post_meta($post_id, '_lisun_manual_last_error');
                    update_post_meta($post_id, '_lisun_manual_glossary_warning', [
                        'message' => $warning_result['message'],
                        'expected' => $warning_expected,
                        'visible' => $warning_visible,
                        'stable_anchor' => $warning_stable,
                        'updated_at' => current_time('mysql'),
                    ]);
                    if (class_exists('Lisun_Manual_Glossary_Apply')) {
                        Lisun_Manual_Glossary_Apply::record_job_result(
                            (string) $job->batch_id,
                            $post_id,
                            (string) ($payload['term_id'] ?? ''),
                            (string) ($payload['mode'] ?? 'direct'),
                            'done',
                            $warning_result
                        );
                    }
                    set_transient("lisun_trans_{$post_id}", [
                        'step' => 'done', 'stage' => 'done', 'progress' => 100,
                        'msg' => $warning_result['message'], 'time' => time(),
                    ], 300);
                    set_transient("lisun_trans_result_{$post_id}", $warning_result, 300);
                    self::debug_log('worker job DONE_WITH_WARNING job=' . intval($job->id)
                        . ' post=' . $post_id . ' message=' . $warning_result['message']);
                    return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'done'];
                }
                // Surface the exact local-vs-DeepL term mismatch in the page
                // error instead of forcing the operator to open trace files.
                $occurrence_details = [];
                foreach ((array) ($error_data['occurrences'] ?? []) as $occurrence) {
                    $source_text = (string) ($occurrence['source_text'] ?? '');
                    $expected = (string) ($occurrence['expected'] ?? '');
                    $actual = (string) ($occurrence['actual'] ?? '');
                    if ($expected === '' && $actual === '') continue;
                    $occurrence_details[] = '术语“' . ($source_text !== '' ? $source_text : '未知')
                        . '”：期望“' . $expected . '”，DeepL 返回“' . $actual . '”';
                }
                if ($occurrence_details) {
                    $display_error .= '；' . implode('；', $occurrence_details);
                }
                $error_data['message'] = $display_error;
                $wpdb->update($table, [
                    'status'     => 'failed',
                    'error_msg'  => $display_error,
                    'result_json'=> wp_json_encode($error_data),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $job->id]);
                $page_status = self::status_after_translation_failure(
                    $post_id,
                    (string) ($error_data['code'] ?? ''),
                    $display_error
                );
                update_post_meta($post_id, '_lisun_manual_sync_status', $page_status);
                if ($page_status === 'synced' && class_exists('Lisun_Manual_V2_Repository')) {
                    Lisun_Manual_V2_Repository::update_document_state($post_id, 'synced', null, '');
                }
                update_post_meta($post_id, '_lisun_manual_last_error', $display_error);
        if ($job->action === 'glossary_apply' && class_exists('Lisun_Manual_Glossary_Apply')) {
                    Lisun_Manual_Glossary_Apply::record_job_result(
                        (string) $job->batch_id,
                        $post_id,
                        (string) ($payload['term_id'] ?? ''),
                        (string) ($payload['mode'] ?? 'direct'),
                        'failed',
                        $error_data,
                        $display_error
                    );
                }
                set_transient("lisun_trans_{$post_id}", [
                    'step' => 'error', 'stage' => 'error', 'progress' => 0,
                    'msg' => $display_error, 'time' => time(),
                ], 300);
                set_transient("lisun_trans_result_{$post_id}", ['error' => $display_error], 300);
                self::debug_log('worker job FAILED job=' . intval($job->id)
                    . ' post=' . $post_id . ' page_status=' . $page_status
                    . ' error=' . $display_error);
                return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'failed'];
            }

            // publish_v2_result() can finish the local English update while
            // the remote push fails. It returns an array in that case, so it
            // must be converted into a queue failure instead of being marked
            // done (otherwise the glossary UI treats it as applied).
            $result_array = is_array($result) ? $result : [];
            $local_only = !empty($result_array['local_updated']) && empty($result_array['pushed']);
            if ($local_only) {
                $push_message = (string) (($result_array['push_msg'] ?? '') ?: ($result_array['message'] ?? '远程英文站推送失败，本地译文已更新但尚未推送'));
                $result_array['code'] = 'push_failed';
                $result_array['message'] = '本地译文已更新，但远程推送失败：' . $push_message;
                $wpdb->update($table, [
                    'status'      => 'failed',
                    'error_msg'   => $result_array['message'],
                    'result_json' => wp_json_encode($result_array),
                    'updated_at'  => current_time('mysql'),
                ], ['id' => $job->id]);
                update_post_meta($post_id, '_lisun_manual_sync_status', 'push_failed');
                update_post_meta($post_id, '_lisun_manual_last_error', $result_array['message']);
                if ($job->action === 'glossary_apply' && class_exists('Lisun_Manual_Glossary_Apply')) {
                    Lisun_Manual_Glossary_Apply::record_job_result(
                        (string) $job->batch_id,
                        $post_id,
                        (string) ($payload['term_id'] ?? ''),
                        (string) ($payload['mode'] ?? 'direct'),
                        'failed',
                        $result_array,
                        $result_array['message']
                    );
                }
                set_transient("lisun_trans_{$post_id}", [
                    'step' => 'error', 'stage' => 'push_failed', 'progress' => 100,
                    'msg' => $result_array['message'], 'time' => time(),
                ], 300);
                set_transient("lisun_trans_result_{$post_id}", $result_array, 300);
                self::debug_log('worker job PUSH FAILED job=' . intval($job->id)
                    . ' post=' . $post_id . ' error=' . $result_array['message']);
                return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'failed'];
            }

            $wpdb->update($table, [
                'status'     => 'done',
                'result_json'=> wp_json_encode($result_array),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job->id]);
            update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
            delete_post_meta($post_id, '_lisun_manual_last_error');
            if ($job->action === 'glossary_apply' && class_exists('Lisun_Manual_Glossary_Apply')) {
                Lisun_Manual_Glossary_Apply::record_job_result(
                    (string) $job->batch_id,
                    $post_id,
                    (string) ($payload['term_id'] ?? ''),
                    (string) ($payload['mode'] ?? 'direct'),
                    'done',
                    $result_array
                );
            }
            if ($job->action === 'glossary_apply' && !empty($result_array['warning_only'])) {
                update_post_meta($post_id, '_lisun_manual_glossary_warning', [
                    'message' => (string) ($result_array['warning_message'] ?? $result_array['message'] ?? '术语应用完成，但有术语数量或锚点提示'),
                    'expected' => (int) ($result_array['expected_occurrences'] ?? ($result_array['post_apply_audit']['hit_count'] ?? 0)),
                    'visible' => (int) ($result_array['visible_applied_occurrences'] ?? ($result_array['post_apply_audit']['visible_applied_count'] ?? 0)),
                    'stable_anchor' => (int) ($result_array['stable_anchor_occurrences'] ?? ($result_array['post_apply_audit']['stable_anchor_count'] ?? 0)),
                    'updated_at' => current_time('mysql'),
                ]);
            } elseif ($job->action === 'glossary_apply') {
                delete_post_meta($post_id, '_lisun_manual_glossary_warning');
            }
            set_transient("lisun_trans_{$post_id}", [
                'step' => 'done', 'stage' => 'done', 'progress' => 100,
                'msg' => '翻译完成', 'time' => time(),
            ], 300);
            set_transient("lisun_trans_result_{$post_id}", $result_array, 300);
            self::debug_log('worker job DONE job=' . intval($job->id) . ' post=' . $post_id);
            return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'done'];
        } catch (\Throwable $e) {
            self::debug_log('worker job EXCEPTION job=' . intval($job->id)
                . ' post=' . $post_id . ' error=' . $e->getMessage());
            $wpdb->update($table, [
                'status'     => 'failed',
                'error_msg'  => $e->getMessage(),
                'result_json'=> wp_json_encode(['message' => $e->getMessage()]),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job->id]);
            $page_status = self::status_after_translation_failure($post_id);
            update_post_meta($post_id, '_lisun_manual_sync_status', $page_status);
            if ($page_status === 'synced' && class_exists('Lisun_Manual_V2_Repository')) {
                Lisun_Manual_V2_Repository::update_document_state($post_id, 'synced', null, '');
            }
            update_post_meta($post_id, '_lisun_manual_last_error', $e->getMessage());
            if ($job->action === 'glossary_apply' && class_exists('Lisun_Manual_Glossary_Apply')) {
                $exception_payload = isset($payload) && is_array($payload) ? $payload : [];
                Lisun_Manual_Glossary_Apply::record_job_result(
                    (string) $job->batch_id,
                    $post_id,
                    (string) ($exception_payload['term_id'] ?? ''),
                    (string) ($exception_payload['mode'] ?? 'direct'),
                    'failed',
                    ['message' => $e->getMessage()],
                    $e->getMessage()
                );
            }
            // 队列任务仍然记录 failed，但已有成功译文不能被本次失败撤销。
            self::debug_log('worker job EXCEPTION status_restored=' . $page_status
                . ' job=' . intval($job->id) . ' post=' . $post_id);
            set_transient("lisun_trans_{$post_id}", [
                'step' => 'error', 'stage' => 'error', 'progress' => 0,
                'msg' => $e->getMessage(), 'time' => time(),
            ], 300);
            return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'failed'];
        } finally {
            self::release_worker_slot();
            self::$current_job_id = 0;
        }
    }

    /**
     * 循环处理队列中的任务（用于并行批量翻译）
     *
     * 持续从队列取 pending 任务处理，直到队列空或超时。
     * 多个并行 Worker 各自调用此方法，通过 MySQL UPDATE LIMIT 1 天然互斥取任务。
     *
     * @param int $max_jobs    最多处理几个任务（0=不限）
     * @param int $max_seconds 最长运行秒数（默认 25 分钟，给 PHP 留余量）
     * @return array ['processed' => int, 'elapsed' => int]
     */
    public static function process_queue_loop($max_jobs = 0, $max_seconds = 1500, $preferred_post_id = 0) {
        $started = time();
        $processed = 0;

        while (true) {
            if ($max_jobs > 0 && $processed >= $max_jobs) break;
            if (time() - $started >= $max_seconds) break;

            $result = self::process_next_job($preferred_post_id);
            if ($result === null) break; // 队列空

            $processed++;

            // 短暂休息，让其他并行 Worker 有机会抢到任务
            usleep(300000); // 0.3s
        }

        return ['processed' => $processed, 'elapsed' => time() - $started];
    }

    /**
     * 触发 Worker（fire-and-forget，不等待响应）
     *
     * 发送独立 HTTP 请求到 Worker 端点，blocking=false 不等待结果。
     */
    private static function trigger_worker($loop = false, $preferred_post_id = 0, $force = false) {
        // 进度接口每 2 秒轮询一次；没有冷却锁时，每次轮询都会发起一个
        // 同步 Worker 请求，造成大量重复 22034 请求并拖慢整个后台。
        // 单篇、批量和进度轮询共用一个冷却锁；否则 single/loop 两把锁会
        // 在同一秒内各发一个 Worker，抵消限流效果。
        $cooldown_key = 'lisun_worker_trigger';
        // 用户明确点击翻译时不能因为另一个页面刚触发过 worker 而
        // 跳过本次启动；后台轮询仍使用冷却锁，避免每 2 秒刷爆 FPM。
        if (!$force && get_transient($cooldown_key)) {
            return true;
        }
        set_transient($cooldown_key, 1, 8);

        /*
         * 不能用 rest_url() 触发 Worker：它通常是公网域名，会再次经过 ESA/CDN，
         * fire-and-forget 请求可能在到达 PHP-FPM 前被丢弃。这里明确走本机 Web
         * 服务器，并用 Host 头选择正确的 WordPress 虚拟主机。
         * 可在特殊部署中用 LISUN_MANUAL_WORKER_URL 覆盖，例如固定内网地址。
         */
        $site = wp_parse_url(home_url('/'));
        $site_host = (string) ($site['host'] ?? 'localhost');
        $site_port = isset($site['port']) ? ':' . intval($site['port']) : '';
        $site_scheme = strtolower((string) ($site['scheme'] ?? 'https'));
        $worker_path = $loop
            ? '/wp-json/lisun-manual/v1/translate/worker-loop'
            : '/wp-json/lisun-manual/v1/translate/worker';

        if (defined('LISUN_MANUAL_WORKER_URL') && LISUN_MANUAL_WORKER_URL) {
            $worker_url = (string) LISUN_MANUAL_WORKER_URL;
            $trigger_route = 'configured';
        } else {
            $worker_url = $site_scheme . '://127.0.0.1' . $site_port . $worker_path;
            $trigger_route = 'loopback';
        }

        $headers = ['Content-Type' => 'application/json'];
        if ($trigger_route === 'loopback') {
            $headers['Host'] = $site_host . $site_port;
            // 某些 HTTPS 虚拟主机会根据此头生成正确的站点协议/重定向行为。
            $headers['X-Forwarded-Proto'] = $site_scheme;
        }

        self::debug_log('worker trigger START route=' . $trigger_route
            . ' preferred_post=' . absint($preferred_post_id)
            . ' force=' . ($force ? 'yes' : 'no')
            . ' url=' . $worker_url);
        $body = ['_wpnonce' => wp_create_nonce('wp_rest')];
        if ($loop) $body['max_jobs'] = 0;
        if ($loop) $body['max_minutes'] = 25;
        if (absint($preferred_post_id) > 0) $body['post_id'] = absint($preferred_post_id);
        $response = wp_remote_post($worker_url, [
            'body'      => wp_json_encode($body),
            'headers'   => $headers,
            // Worker 会自行处理队列；后台轮询不能等待翻译 Worker 返回。
            'timeout'   => self::WORKER_TRIGGER_TIMEOUT,
            'blocking'  => false,
            'redirection'=> 0,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            delete_transient($cooldown_key);
            self::debug_log('worker trigger FAILED route=' . $trigger_route
                . ' error=' . $response->get_error_message());
            return false;
        }

        self::debug_log('worker trigger DISPATCHED route=' . $trigger_route . ' cooldown=8s');
        return true;
    }

    /** Public entry point for glossary batches; use the loop worker, not one-job worker. */
    public static function trigger_queue_worker($preferred_post_id = 0) {
        return self::trigger_worker(true, $preferred_post_id, true);
    }

    // ==================== REST: 翻译接口 ====================

    /**
     * REST: 翻译单个页面
     *
     * 入队（持久化到 DB）+ fire-and-forget 触发独立 Worker 请求。
     * 本接口 <100ms 返回，不会触发 Cloudflare 超时。
     * Worker 在独立 PHP 进程中跑翻译，即使 524 也会继续执行。
     */
    public static function rest_translate_single($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        self::debug_log('REST translate START post=' . $post_id);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }
        if (self::is_en_locked($post_id)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '该页面英文已锁定，请先解锁']], 200);
        }

        $job_id = self::enqueue_job($post_id, 'translate');
        if (!$job_id) {
            self::debug_log('REST translate QUEUE_FAILED post=' . $post_id);
            return new WP_REST_Response(['success' => false, 'data' => [
                'message' => '无法加入翻译队列，请查看 lisun-debug.log',
            ]], 200);
        }
        set_transient("lisun_trans_{$post_id}", [
            'step' => 'queued', 'stage' => 'queued', 'progress' => 2,
            'msg' => '已进入翻译队列，等待开始…', 'time' => time(),
        ], 600);

        // 直接点击必须启动循环 worker，并优先处理这篇文章；不能被另一个
        // 页面的 8 秒冷却锁吞掉，否则任务会永久停留在 pending/queued。
        $worker_triggered = self::trigger_worker(true, $post_id, true);
        self::debug_log('REST translate QUEUED post=' . $post_id
            . ' worker_triggered=' . ($worker_triggered ? 'yes' : 'no'));

        return new WP_REST_Response(['success' => true, 'data' => [
            'status' => 'queued', 'worker_triggered' => $worker_triggered,
        ]], 200);
    }

    /**
     * REST: 批量入队 — 将所有 status=pending 的中文页面加入翻译队列
     *
     * 用于批量翻译场景。入队后需通过 worker-loop 端点并行处理。
     */
    public static function rest_enqueue_all_pending($request) {
        $posts = Lisun_Manual_CPT::get_zh_pages('untranslated');
        $count = 0;
        $failed = 0;
        foreach ($posts as $post) {
            if (self::is_en_locked($post->ID)) continue; // 锁定篇跳过
            $job_id = self::enqueue_job($post->ID, 'translate');
            if (!$job_id) {
                $failed++;
                continue;
            }
            set_transient("lisun_trans_{$post->ID}", [
                'step' => 'queued', 'stage' => 'queued', 'progress' => 2,
                'msg' => '已进入翻译队列，等待开始…', 'time' => time(),
            ], 3600);
            $count++;
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['enqueued' => $count, 'failed' => $failed, 'message' => "已入队 {$count} 篇" . ($failed ? "，{$failed} 篇入队失败，请查看日志" : '，请触发 Worker Loop 开始翻译')],
        ], 200);
    }

    /**
     * REST: 重译
     */
    public static function rest_retranslate($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        self::debug_log('REST retranslate START post=' . $post_id);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }
        if (self::is_en_locked($post_id)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '该页面英文已锁定，请先解锁']], 200);
        }

        $job_id = self::enqueue_job($post_id, 'retranslate');
        if (!$job_id) {
            self::debug_log('REST retranslate QUEUE_FAILED post=' . $post_id);
            return new WP_REST_Response(['success' => false, 'data' => [
                'message' => '无法加入重译队列，请查看 lisun-debug.log',
            ]], 200);
        }
        set_transient("lisun_trans_{$post_id}", [
            'step' => 'queued', 'stage' => 'queued', 'progress' => 2,
            'msg' => '重译已进入队列，等待开始…', 'time' => time(),
        ], 600);

        $worker_triggered = self::trigger_worker(true, $post_id, true);
        self::debug_log('REST retranslate QUEUED post=' . $post_id
            . ' worker_triggered=' . ($worker_triggered ? 'yes' : 'no'));

        return new WP_REST_Response(['success' => true, 'data' => [
            'status' => 'queued', 'worker_triggered' => $worker_triggered,
        ]], 200);
    }

    /**
     * REST: 切换段落编辑锁（auto ↔ manual）
     *
     * manual 锁定的段落在增量翻译时即使中文内容变更也不会被覆盖，
     * 保留人工编辑的英文译文。
     *
     * POST /wp-json/lisun-manual/v1/translate/block-lock
     * Body: { post_id: int, block_index: int, lock: 'auto'|'manual' }
     */
    public static function rest_toggle_block_lock($request) {
        $post_id    = intval($request->get_param('post_id') ?? 0);
        $block_idx  = intval($request->get_param('block_index') ?? -1);
        $lock_state = $request->get_param('lock') ?? 'manual';

        if (!$post_id || $block_idx < 0) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少 post_id 或 block_index']], 200);
        }

        if (!in_array($lock_state, ['auto', 'manual'], true)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => 'lock 值必须是 auto 或 manual']], 200);
        }

        $backup = self::get_translation_backup($post_id);
        if (!$backup) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '没有找到备份数据，请先翻译']], 200);
        }

        $block_locks = $backup['block_locks'] ?? [];
        if ($lock_state === 'auto') {
            unset($block_locks[$block_idx]);
        } else {
            $block_locks[$block_idx] = 'manual';
        }

        // 更新锁状态到备份
        self::save_translation_backup($post_id, array_merge($backup, ['block_locks' => $block_locks]));

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'message'     => $lock_state === 'manual' ? '段落已锁定' : '段落已解锁',
                'block_index' => $block_idx,
                'lock'        => $lock_state,
                'block_locks' => $block_locks,
            ],
        ], 200);
    }

    /**
     * REST: 页面级英文锁定（方案 A）
     *
     * POST /wp-json/lisun-manual/v1/translate/page-lock
     * Body: { post_id: int, lock: 'locked'|'unlocked' }
     *
     * 锁定后该篇跳过所有翻译/重译/重推/术语重刷，保护远程站人工英文。
     */
    public static function rest_toggle_page_lock($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        $lock    = $request->get_param('lock');

        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }

        $locked = ($lock === 'locked');
        self::set_en_locked($post_id, $locked);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'post_id' => $post_id,
                'locked'  => $locked,
                'message' => $locked
                    ? '英文已锁定：后续翻译/重译/重推将跳过此篇，保护远程站人工编辑'
                    : '已解锁：恢复自动翻译',
            ],
        ], 200);
    }

    /**
     * REST: 重试推送（从备份读取数据，直接推送，不重新翻译）
     */
    public static function rest_retry_push($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }
        if (self::is_en_locked($post_id)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '该页面英文已锁定，重推会覆盖远程站人工编辑，请先解锁']], 200);
        }

        // ★ 统一推送语义：推「本地英文 post」（含人工编辑），而不是备份里的旧机器译文。
        // push_existing_translation 成功后会调用 resync_backup_from_local_en 同步更新备份。
        $remote_id = Lisun_Manual_Sync::push_existing_translation($post_id);

        if (is_wp_error($remote_id)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => $remote_id->get_error_message()]], 200);
        }

        return new WP_REST_Response(['success' => true, 'data' => ['remote_id' => intval($remote_id), 'message' => '推送成功']], 200);
    }

    /**
     * 推送本地英文 post 成功后，把这份英文同步回备份，并按 HTML 块重新对齐到当前中文分段，
     * 让后续增量翻译能复用人工编辑过的英文。
     *
     * @param int $zh_post_id 中文页面 ID
     * @return bool
     */
    public static function resync_backup_from_local_en($zh_post_id) {
        $zh_post    = get_post($zh_post_id);
        $en_post_id = get_post_meta($zh_post_id, '_lisun_manual_target_id', true);
        $en_post    = $en_post_id ? get_post($en_post_id) : null;
        if (!$zh_post || !$en_post) return false;

        $backup = self::get_translation_backup($zh_post_id);
        if (!$backup) return false;

        $zh_content = self::sanitize_debug_utf8((string) $zh_post->post_content);
        $zh_content = self::strip_inline_styles($zh_content);
        $en_content = $en_post->post_content;
        $max_chars  = self::SEGMENT_MAX_CHARS;

        // 权威分段（与全量/增量完全一致）。严格 HTML 模式下不能使用旧的
        // 字符/闭合标签硬切逻辑，否则重同步会再次制造错误段哈希。
        $zh_segments = self::segment_for_translation($zh_content, $max_chars);
        if (is_wp_error($zh_segments)) return false;

        // 按 HTML 块把英文对齐到中文分段
        $en_parts  = null;
        $zh_blocks = self::split_blocks_at_close_tags($zh_content);
        $en_blocks = self::split_blocks_at_close_tags($en_content);
        if (!empty($zh_blocks) && count($zh_blocks) === count($en_blocks)) {
            $aligned = self::align_zh_en_blocks($zh_blocks, $en_blocks, $max_chars);
            // 仅当块级分组与权威分段严格一致时，对齐才有效
            if ($aligned && $aligned[0] === $zh_segments) {
                $en_parts = $aligned[1];
            }
        }

        $segment_hashes = [];
        foreach ($zh_segments as $i => $seg) {
            $segment_hashes[$i] = md5($seg);
        }

        // 对齐失败（例如超长单块被进一步拆分）：清空段数据，下次翻译安全回退全量
        if ($en_parts === null) {
            $zh_segments    = [];
            $en_parts       = [];
            $segment_hashes = [];
        }

        self::save_translation_backup($zh_post_id, [
            'anchor_mode'    => $backup['anchor_mode'] ?? '',
            'en_title'       => $en_post->post_title,
            'en_title_anchored' => self::strip_glossary_anchors($en_post->post_title),
            // 中文源文是锚点身份的权威来源；推送/重同步英文副本时不能丢掉
            // 这份带 term_id 的源文快照，否则下一次术语重刷无法判断 ID 是否仍对应。
            'zh_content_anchored' => self::term_anchors_enabled()
                ? self::add_glossary_anchors($zh_content)
                : $zh_content,
            'en_content'     => $en_content,
            'en_slug'        => $backup['en_slug'] ?? $en_post->post_name,
            'en_seo'         => $backup['en_seo'] ?? [],
            'en_seo_anchored' => $backup['en_seo'] ?? [],
            'tags'           => $backup['tags'] ?? [],
            'glossary_hash'  => $backup['glossary_hash'] ?? md5(json_encode(Lisun_Manual_Glossary::get_all())),
            'zh_segments'    => $zh_segments,
            'en_parts'       => $en_parts,
            'segment_hashes' => $segment_hashes,
            'block_locks'    => $backup['block_locks'] ?? [],
        ]);

        return true;
    }

    /**
     * 按块级闭合标签切块（保留闭合标签）。中英 HTML 结构一致时块数与顺序一致。
     */
    private static function split_blocks_at_close_tags($html) {
        $blocks       = [];
        $close_pattern = '/^<\/(?:p|h[1-6]|table|figure|li|figcaption|blockquote|div)\s*>$/';
        $parts        = preg_split('/(<\/(?:p|h[1-6]|table|figure|li|figcaption|blockquote|div)\s*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $current      = '';
        foreach ($parts as $part) {
            $current .= $part;
            if (preg_match($close_pattern, $part)) {
                $blocks[] = $current;
                $current  = '';
            }
        }
        // 保留尾部残余（含纯空白），与 segment_content 的分段口径一致
        if ($current !== '') {
            $blocks[] = $current;
        }
        return $blocks;
    }

    /**
     * 用中文块的 2000 字分组边界，把英文块按相同边界分组，得到与中文分段对齐的英文 parts。
     * 返回 [zh_segments, en_parts]；结构不一致返回 null。
     */
    private static function align_zh_en_blocks($zh_blocks, $en_blocks, $max_chars) {
        if (count($zh_blocks) !== count($en_blocks)) {
            return null;
        }

        $zh_segments = [];
        $ranges      = []; // 每个段对应的块索引区间 [start, end)
        $current     = '';
        $start       = 0;

        foreach ($zh_blocks as $i => $block) {
            if ($current === '') {
                $current = $block;
            } elseif (mb_strlen($current . $block) <= $max_chars) {
                $current .= $block;
            } else {
                $zh_segments[] = $current;
                $ranges[]      = [$start, $i];
                $current       = $block;
                $start         = $i;
            }
        }
        if ($current !== '') {
            $zh_segments[] = $current;
            $ranges[]      = [$start, count($zh_blocks)];
        }

        $en_parts = [];
        foreach ($ranges as $r) {
            $part = '';
            for ($i = $r[0]; $i < $r[1]; $i++) {
                $part .= $en_blocks[$i];
            }
            $en_parts[] = $part;
        }

        return [$zh_segments, $en_parts];
    }

    /**
     * REST: 重新应用术语表到已有译文（不调 LLM）
     */
    public static function rest_reapply_glossary($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }
        if (self::is_en_locked($post_id)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '该页面英文已锁定，请先解锁']], 200);
        }

        $result = self::reapply_glossary($post_id);

        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            if (!is_array($error_data)) $error_data = [];
            $error_data['message'] = $result->get_error_message();
            return new WP_REST_Response(['success' => false, 'data' => $error_data], 200);
        }

        return new WP_REST_Response(['success' => true, 'data' => $result], 200);
    }

    /**
     * REST: 删除指定中文页面的本地翻译备份。
     * 只删除 lisun_trans_bak_{post_id}，不删除中文文章、本地英文草稿或远程英文文章。
     */
    public static function rest_delete_translation_backup($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'manual') {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '页面不存在或类型错误']], 200);
        }
        if (!self::get_translation_backup($post_id)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '没有找到本地翻译备份']], 200);
        }

        self::delete_translation_backup($post_id);
        return new WP_REST_Response(['success' => true, 'data' => ['message' => '本地翻译备份已删除']], 200);
    }

    /**
     * REST: Worker 端点
     *
     * 立即响应"started"，然后断开客户端继续执行翻译。
     * 这样 fire-and-forget 调用不会阻塞，Worker 在后台跑。
     */
    public static function rest_translate_worker($request) {
        self::debug_log('worker HTTP START route=/translate/worker');
        $preferred_post_id = absint($request->get_param('post_id') ?? 0);
        // 先发送响应
        status_header(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo wp_json_encode(['success' => true, 'data' => ['status' => 'started']]);
        while (ob_get_level()) ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) {
            session_write_close();
            fastcgi_finish_request();
        }

        // 客户端断开后处理队列
        @set_time_limit(600);
        @ignore_user_abort(true);
        $result = self::process_next_job($preferred_post_id);
        self::debug_log('worker HTTP END result=' . wp_json_encode($result, JSON_UNESCAPED_UNICODE));

        die();
    }

    /**
     * REST: Worker 循环端点（用于并行批量翻译）
     *
     * 持续从队列取任务处理，直到队列空或超时。
     * 多个并行 curl 调用此端点，各自抢任务处理，互不冲突。
     *
     * 参数（可选）：
     * - max_jobs: 最多处理几个（0=不限，直到队列空）
     * - max_minutes: 最长运行分钟数（默认 25）
     */
    public static function rest_translate_worker_loop($request) {
        $max_jobs = intval($request->get_param('max_jobs') ?? 0);
        $max_minutes = intval($request->get_param('max_minutes') ?? 25);
        $preferred_post_id = absint($request->get_param('post_id') ?? 0);
        $max_seconds = min($max_minutes * 60, 1500); // 最多 25 分钟

        // 先发送响应
        status_header(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo wp_json_encode(['success' => true, 'data' => ['status' => 'started']]);
        while (ob_get_level()) ob_end_flush();
        flush();
        if (function_exists('fastcgi_finish_request')) {
            session_write_close();
            fastcgi_finish_request();
        }

        // 客户端断开后持续处理
        @set_time_limit($max_seconds + 60);
        @ignore_user_abort(true);
        $result = self::process_queue_loop($max_jobs, $max_seconds, $preferred_post_id);

        error_log('LISUN Manual worker-loop finished: ' . json_encode($result));
        die();
    }


    /**
     * REST: 删除译文
     */
    public static function rest_delete_translation($request) {
        $post_id = intval($request->get_param('id') ?? 0);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }

        $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);
        if ($target_id) {
            wp_delete_post($target_id, true);
        }

        delete_post_meta($post_id, '_lisun_manual_target_id');
        delete_post_meta($post_id, '_lisun_manual_sync_status');
        delete_post_meta($post_id, '_lisun_manual_last_sync');

        $remote_id = get_post_meta($post_id, '_lisun_manual_remote_id', true);
        if ($remote_id) {
            Lisun_Manual_Sync::delete_remote($remote_id, true);
            delete_post_meta($post_id, '_lisun_manual_remote_id');
        }

        return new WP_REST_Response(['success' => true, 'data' => ['message' => '译文已删除']], 200);
    }

    /**
     * REST: 查询翻译进度
     *
     * 附带触发 Worker：如果当前没有活跃翻译且队列非空，自动触发处理下一个任务。
     */
    public static function rest_translate_progress($request) {
        $post_id = intval($request->get_param('id') ?? 0);
        $progress = get_transient("lisun_trans_{$post_id}");

        if (!$progress) return new WP_REST_Response(['active' => false], 200);

        $fallback_progress = 0;
        if (preg_match('/^(\d+)\/(\d+)/', (string) ($progress['step'] ?? ''), $parts)) {
            $fallback_progress = (int) round(((int) $parts[1] / max(1, (int) $parts[2])) * 100);
        }
        $response = [
            'active' => true,
            'step' => $progress['step'],
            'stage' => $progress['stage'] ?? $progress['step'],
            'progress' => isset($progress['progress'])
                ? max(0, min(100, (int) $progress['progress']))
                : $fallback_progress,
            'msg' => $progress['msg'],
        ];
        foreach (['completed_units', 'total_units', 'completed_batches', 'total_batches'] as $key) {
            if (isset($progress[$key])) $response[$key] = (int) $progress[$key];
        }

        if ($progress['step'] === 'done') {
            $result = get_transient("lisun_trans_result_{$post_id}");
            if ($result) {
                $response['result'] = $result;
            }
            self::maybe_trigger_worker();
        } elseif ($progress['step'] === 'error') {
            $response['error'] = $progress['msg'];
            self::maybe_trigger_worker();
        } elseif ($progress['step'] === 'queued') {
            // 排队中：立即尝试触发 Worker
            self::maybe_trigger_worker();
        } elseif ($progress['step'] === 'processing') {
            // 处理中超过阈值（且无心跳续租）才认为进程可能已死，重置并重试
            if (time() - ($progress['time'] ?? 0) > self::WORKER_STALE_SECONDS) {
                self::maybe_trigger_worker();
            }
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * 如果队列非空，fire-and-forget 触发 Worker
     */
    private static function maybe_trigger_worker() {
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';

        // 清理：真正卡死（超过 WORKER_STALE_SECONDS 且期间无心跳续租）的 processing 重置为 pending
        $stale = intval(self::WORKER_STALE_SECONDS);
        $wpdb->query("UPDATE `{$table}` SET status = 'pending', updated_at = NOW()
            WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL {$stale} SECOND)");

        $pending = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'");
        if ($pending > 0) {
            // 一次循环 Worker 负责把当前队列持续取空，避免每篇文章再发一
            // 次同步触发请求，也避免单篇 worker 完成后队列无人接手。
            self::trigger_worker(true);
        }
    }

    /**
     * 批量翻译进度的 transient key
     */
    private static function batch_progress_key() {
        $user_id = get_current_user_id();
        return "lisun_batch_progress_{$user_id}";
    }

    /**
     * REST: 查询批量翻译进度
     */
    public static function rest_batch_progress($request) {
        $progress = get_transient(self::batch_progress_key());
        if (!$progress) {
            return new WP_REST_Response(['active' => false, 'done' => [], 'total' => 0], 200);
        }
        return new WP_REST_Response([
            'active'  => true,
            'ids'     => $progress['ids'] ?? [],
            'total'   => $progress['total'] ?? count($progress['ids'] ?? []),
            'done'    => $progress['done'] ?? [],
            'current' => $progress['current'] ?? 0,
            'failed'  => $progress['failed'] ?? [],
            'started' => $progress['started'] ?? 0,
        ], 200);
    }

    /**
     * REST: 更新批量翻译进度（前端每完成/失败一篇时上报）
     */
    public static function rest_update_batch_progress($request) {
        $ids    = array_map('intval', $request->get_param('ids') ?? []);
        $done   = array_map('intval', $request->get_param('done') ?? []);
        $failed = array_map('intval', $request->get_param('failed') ?? []);
        $current = intval($request->get_param('current') ?? 0);
        $total   = intval($request->get_param('total') ?? count($ids));

        if (empty($ids)) {
            return new WP_REST_Response(['success' => false, 'message' => '缺少 ids'], 200);
        }

        set_transient(self::batch_progress_key(), [
            'ids'     => $ids,
            'total'   => $total,
            'done'    => $done,
            'failed'  => $failed,
            'current' => $current,
            'started' => time(),
        ], 3600);

        return new WP_REST_Response(['success' => true], 200);
    }

    // ==================== 翻译引擎 ====================

    /**
     * 分段翻译：将长文档按 Gutenberg 块边界智能拆分后逐段翻译，再拼接
     *
     * @param string $text 待翻译文本
     * @return array|WP_Error 同 call_deepseek_api 返回格式
     */

    /**
     * 翻译单个段：根据提供商配置选择翻译引擎
     *
     * 优先级：DeepL > 豆包→DeepSeek 双轮 > DeepSeek 单轮
     */
    private static function translate_one_segment($text, $index = 0, $total = 1, $with_anchors = true) {
        self::heartbeat(); // 续租队列任务，防止长翻译被误判为卡死
        $with_anchors = (bool) $with_anchors && self::term_anchors_enabled();
        // DeepL 引擎（独立于 OpenAI 兼容接口）
        $provider = get_option('lisun_llm_provider', 'deepseek');
        // DeepL 的术语模式使用完整 HTML 片段；关闭术语表时保持原有文本节点流程。
        if ($provider !== 'deepl' && $with_anchors) {
            $text = self::add_glossary_anchors($text);
        }
        // 诊断日志（每次翻译只记一次）：确认实际使用的引擎
        static $logged_provider = null;
        if ($logged_provider !== $provider) {
            $logged_provider = $provider;
            $msg = 'translate engine provider = ' . $provider
                . ' (deepl_key=' . (empty(get_option('lisun_deepseek_key', '')) ? 'empty' : 'set')
                . ', doubao=' . (get_option('lisun_doubao_enabled', false) ? 'on' : 'off') . ')';
            error_log('LISUN Manual: ' . $msg);
            self::debug_log($msg);
        }
        if ($provider === 'deepl') {
            if ($with_anchors && Lisun_Manual_Glossary::is_enabled()) {
                return self::call_deepl_html_api($text, $index, $total);
            }
            return self::call_deepl_api($text, $index, $total, $with_anchors);
        }

        if (get_option('lisun_doubao_enabled', false) && !empty(get_option('lisun_doubao_key', ''))) {
            // 第一轮：豆包翻译
            $r1 = self::call_doubao_api($text, $index, $total);
            if (is_wp_error($r1)) {
                static $doubao_fail_count = 0;
                $doubao_fail_count++;
                if ($doubao_fail_count <= 3) {
                    error_log('LISUN Manual: Doubao failed, fallback to DeepSeek — ' . $r1->get_error_message());
                } elseif ($doubao_fail_count === 4) {
                    error_log('LISUN Manual: Doubao failures continue, suppressing further logs for this run');
                }
                $fallback = self::call_deepseek_api($text, $index, $total);
                return $with_anchors ? self::validate_segment_anchors($text, $fallback) : $fallback;
            }
            // 第二轮：DeepSeek 润色
            $r2 = self::polish_with_deepseek($text, $r1['text'], $index, $total);
            if (is_wp_error($r2)) {
                error_log('LISUN Manual: Polish failed, using Doubao raw — ' . $r2->get_error_message());
                    return $with_anchors ? self::validate_segment_anchors($text, $r1) : $r1;
            }
            // 合并 finish_reason：任一步 truncate 就上报 length，防止 Doubao 截断被润色掩盖
            $combined_reason = (($r1['finish_reason'] ?? 'stop') === 'length' || ($r2['finish_reason'] ?? 'stop') === 'length') ? 'length' : 'stop';
            $combined = [
                'text'              => $r2['text'],
                'prompt_tokens'     => ($r1['prompt_tokens'] ?? 0) + ($r2['prompt_tokens'] ?? 0),
                'completion_tokens' => ($r1['completion_tokens'] ?? 0) + ($r2['completion_tokens'] ?? 0),
                'finish_reason'     => $combined_reason,
            ];
            return $with_anchors ? self::validate_segment_anchors($text, $combined) : $combined;
        }
        $result = self::call_deepseek_api($text, $index, $total);
        return $with_anchors ? self::validate_segment_anchors($text, $result) : $result;
    }

    /** 当前是否必须使用严格的 DeepL 完整 HTML + 中文源锚点流程。 */
    private static function uses_strict_deepl_html() {
        return get_option('lisun_llm_provider', 'deepseek') === 'deepl'
            && self::term_anchors_enabled();
    }

    /** V2 runs for machine-translation engines (DeepL / Google) and can be disabled instantly for V1 rollback. */
    private static function translation_v2_enabled() {
        if (!self::v2_capable_provider()) return false;
        if (!class_exists('Lisun_Manual_V2_Runner')) return false;
        // Google has no V1 prompt path: there is no fallback engine to degrade
        // to, so the glossary / anchor / V2 toggles must not be able to disable
        // translation for it. For DeepL the original conditions apply unchanged.
        if ((string) get_option('lisun_llm_provider', 'deepseek') === 'google') return true;
        return Lisun_Manual_Glossary::is_enabled()
            && self::term_anchors_enabled()
            && (bool) get_option('lisun_translation_engine_v2', true);
    }

    /** Engines that have a V2 unit adapter (professional MT, no prompt building). */
    private static function v2_capable_provider() {
        $provider = (string) get_option('lisun_llm_provider', 'deepseek');
        if ($provider === 'deepl') return true;
        return $provider === 'google' && class_exists('Lisun_Manual_V2_Google_Adapter');
    }

    /** 锚点是独立诊断开关；关闭锚点不等于关闭 DeepL Glossary。 */
    private static function term_anchors_enabled() {
        return Lisun_Manual_Glossary::is_enabled()
            && (bool) get_option('lisun_term_anchors_enabled', true);
    }

    private static function validate_segment_anchors($source, $result) {
        if (is_wp_error($result) || !self::term_anchors_enabled()) return $result;
        // DeepL 路径不把锚点送进引擎，而是在英文译文返回后本地添加。
        // 因此原文可能还没有 span；校验时先生成预期的源文锚点。
        $source_for_check = (preg_match('/data-lisun-term-id\s*=/i', (string) $source) || !Lisun_Manual_Glossary::is_enabled())
            ? $source
            : self::add_glossary_anchors($source);
        $check = self::validate_glossary_anchors($source_for_check, $result['text'] ?? '');
        return is_wp_error($check) ? $check : $result;
    }

    private static function translate_with_segmentation($text) {
        $max_chars = self::SEGMENT_MAX_CHARS;
        $post_id = self::$progress_post_id ?? 0;

        // 全量和增量必须共用同一个入口；启用术语表的 DeepL 使用完整 HTML 分段。
        $segments = self::segment_for_translation($text, $max_chars);
        if (is_wp_error($segments)) return $segments;

        // 短文档 / 无法再拆分：整篇作为单段直接翻译
        if (count($segments) <= 1) {
            if ($post_id) {
                set_transient("lisun_trans_{$post_id}", [
                    'step' => '1/1', 'msg' => '正在翻译（短文档）...', 'time' => time(),
                ], 300);
            }
            $result = self::translate_with_overflow_protection($text, 0, 1);
            delete_transient("lisun_trans_{$post_id}");
            // 短文档也填充段数据，供增量翻译使用
            if (!is_wp_error($result)) {
                $result['zh_segments'] = [$text];
                $result['en_parts'] = [0 => $result['text']];
            }
            return $result;
        }

        $total = count($segments);
        $total_prompt = 0;
        $total_completion = 0;

        // 断点续传：检查是否有上次已翻译的缓存
        $checkpoint_key = $post_id ? "lisun_trans_cp_{$post_id}" : '';
        $checkpoint = ($checkpoint_key && !self::$force_retranslate) ? get_transient($checkpoint_key) : false;
        $translated_parts = [];
        $start_from = 0;
        $checkpoint_valid = false;

        if ($checkpoint && is_array($checkpoint) && count($checkpoint) === $total) {
            // 校验：段数 + 每段源内容哈希都必须匹配（文档没变才能复用译文）
            $checkpoint_valid = true;
            foreach ($checkpoint as $idx => $cached) {
                if (empty($cached['text'])) continue;
                // 用源内容哈希验证这段中文有没有被修改过
                $current_hash = md5($segments[$idx]);
                $cached_hash = $cached['source_hash'] ?? '';
                if ($cached_hash === '' || $cached_hash !== $current_hash) {
                    // 源内容已变更 → 整个 checkpoint 失效
                    $checkpoint_valid = false;
                    error_log("LISUN Manual: checkpoint invalidated for post {$post_id} segment {$idx} — content changed");
                    break;
                }
                $translated_parts[$idx] = $cached['text'];
                $total_prompt += ($cached['prompt_tokens'] ?? 0);
                $total_completion += ($cached['completion_tokens'] ?? 0);
                $start_from = max($start_from, $idx + 1);
            }
        }

        if (!$checkpoint_valid) {
            // 新翻译 / 内容变了 / 段数变了 → 清掉旧缓存
            if ($checkpoint_key) delete_transient($checkpoint_key);
            $checkpoint = array_fill(0, $total, null);
        }

        // 逐段翻译（跳过已完成的）
        for ($i = 0; $i < $total; $i++) {
            if (isset($translated_parts[$i]) && !empty($translated_parts[$i])) {
                continue; // 断点续传：跳过已完成的段
            }

            // 报告进度
            if ($post_id) {
                set_transient("lisun_trans_{$post_id}", [
                    'step' => ($i + 1) . '/' . $total . ($start_from > 0 ? ' (续传)' : ''),
                    'msg'  => "正在翻译第 " . ($i + 1) . " 段，共 {$total} 段",
                    'time' => time(),
                ], 300);
            }

            // 每段前重置超时，避免长文档（100+ 段）被 PHP 超时杀死
            @set_time_limit(600);

            $result = self::translate_with_overflow_protection($segments[$i], $i, $total);

            if (is_wp_error($result)) {
                // 严格 HTML 模式不能把失败段落替换成中文失败标记。
                error_log('LISUN Manual: segment ' . ($i+1) . '/' . $total . ' FAILED: ' . $result->get_error_message());
                if (self::uses_strict_deepl_html()) return $result;
                $translated_parts[$i] = "\n<!-- ⚠ TRANSLATION FAILED — original Chinese below -->\n" . $segments[$i] . "\n<!-- /TRANSLATION FAILED -->\n";
                continue;
            }

            $translated_parts[$i] = $result['text'];
            $total_prompt += $result['prompt_tokens'];
            $total_completion += $result['completion_tokens'];

            // finish_reason=length → 溢出保护未解决，强制对半拆分重试
            if (($result['finish_reason'] ?? 'stop') === 'length') {
                if (self::uses_strict_deepl_html()) {
                    return new WP_Error('deepl_html_oversize', 'HTML 分段超过 DeepL 可处理大小，未进行非法硬切，请缩短该 HTML 块后重试');
                }
                error_log('LISUN Manual: segment ' . ($i+1) . '/' . $total . ' still truncated after overflow protection, force-splitting...');
                $half = intval(mb_strlen($segments[$i]) / 2);
                if ($half >= 100) {
                    $left  = self::translate_with_overflow_protection(mb_substr($segments[$i], 0, $half), $i, $total);
                    $right = self::translate_with_overflow_protection(mb_substr($segments[$i], $half), $i, $total);
                    if (!is_wp_error($left) && !is_wp_error($right)) {
                        $translated_parts[$i] = $left['text'] . "\n" . $right['text'];
                        $total_prompt     += ($left['prompt_tokens'] ?? 0) + ($right['prompt_tokens'] ?? 0);
                        $total_completion += ($left['completion_tokens'] ?? 0) + ($right['completion_tokens'] ?? 0);
                        error_log('LISUN Manual: force-split succeeded for segment ' . ($i+1));
                    }
                }
            }

            // 每完成一段立即保存断点（含源内容哈希，供续传时校验）
            if ($checkpoint_key) {
                $checkpoint[$i] = [
                    'text'              => $translated_parts[$i],
                    'source_hash'       => md5($segments[$i]),
                    'prompt_tokens'     => $result['prompt_tokens'],
                    'completion_tokens' => $result['completion_tokens'],
                ];
                set_transient($checkpoint_key, $checkpoint, 3600);
            }
        }

        // 全部完成，清理缓存
        if ($checkpoint_key) delete_transient($checkpoint_key);
        if ($post_id) delete_transient("lisun_trans_{$post_id}");

        // 按索引排序组装
        ksort($translated_parts);
        $assembled = implode("\n", $translated_parts);

        // HTML 模式不清理/修复 Gutenberg 结构，避免掩盖分段或引擎返回问题。
        if (!self::uses_strict_deepl_html()) {
            $assembled = self::clean_gutenberg_comments($assembled);
        }
        // HTML 模式禁止自动补标签；每个分段已单独校验，拼接后只做严格检查。
        if (self::uses_strict_deepl_html()) {
            $html_check = self::validate_html_fragment_structure($assembled, '拼接后的英文译文');
            if (is_wp_error($html_check)) return $html_check;
        } else {
            $assembled = self::auto_close_html_tags($assembled);
        }

        // 第三轮：残留中文自检 + 打回重译（对所有引擎生效，含 DeepL/DeepSeek）
        // 检测每段译文是否残留中文或严重偏短，是则用正确引擎重新翻译该段。
        // 注意：不能用 self_check —— 它对 DeepL 走的是 OpenAI 格式，必然失败；
        // 必须走 translate_with_overflow_protection 才能路由到当前引擎。
        $repaired = 0;
        for ($i = 0; $i < $total; $i++) {
            if (empty($translated_parts[$i]) || empty($segments[$i])) {
                continue;
            }
            $has_chinese = preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $translated_parts[$i]);
            $len_ratio = mb_strlen($translated_parts[$i]) / max(1, mb_strlen($segments[$i]));
            if (!$has_chinese && $len_ratio >= 0.7) {
                continue;
            }

            error_log('LISUN Manual: segment ' . ($i + 1) . '/' . $total . ' re-translating'
                . ' (chinese=' . ($has_chinese ? 'yes' : 'no') . ' ratio=' . round($len_ratio, 2) . ')');
            $retry = self::translate_with_overflow_protection($segments[$i], $i, $total);

            if (is_wp_error($retry) || preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $retry['text'] ?? '')) {
                if (self::uses_strict_deepl_html()) {
                    return is_wp_error($retry)
                        ? $retry
                        : new WP_Error('deepl_html_residual_chinese', 'HTML 译文仍包含中文，已停止保存和推送，请重试');
                }
                // 重译仍失败或仍残留中文：打可见标记，不再静默
                $translated_parts[$i] = "\n<!-- ⚠ TRANSLATION FAILED — original Chinese below -->\n" . $segments[$i] . "\n<!-- /TRANSLATION FAILED -->\n";
            } else {
                $translated_parts[$i] = $retry['text'];
                $total_prompt     += $retry['prompt_tokens'] ?? 0;
                $total_completion += $retry['completion_tokens'] ?? 0;
                $repaired++;
            }
        }
        if ($repaired > 0) {
            error_log("LISUN Manual: re-translated {$repaired}/{$total} segments (residual Chinese / too-short)");
            $assembled = implode("\n", $translated_parts);
        }

        if ($post_id) delete_transient("lisun_trans_{$post_id}");

        return [
            'text'              => $assembled,
            'prompt_tokens'     => $total_prompt,
            'completion_tokens' => $total_completion,
            'segments'          => $total,
            'zh_segments'       => $segments,
            'en_parts'          => $translated_parts,
        ];
    }

    // ==================== 段落拆分（多级降级策略） ====================

    /**
     * LCS 对齐算法：将旧段哈希数组与新段哈希数组做最长公共子序列对齐
     *
     * 替代原有的位置索引一一对比（中间插入一段会导致后面全部重译）。
     * 返回操作列表，每个操作为 'keep' | 'replace' | 'insert' | 'delete'。
     *
     * 时间复杂度 O(m×n)，对于 100 段以内的文档实测 < 5ms。
     *
     * @param array $old_hashes 旧段哈希数组 [idx => md5]
     * @param array $old_en    旧段英文译文数组 [idx => en_text]
     * @param array $new_hashes 新段哈希数组 [idx => md5]
     * @return array 对齐操作列表，每项含 action + 上下文
     */
    private static function lcs_align($old_hashes, $old_en, $new_hashes) {
        $m = count($old_hashes);
        $n = count($new_hashes);

        // 小文档：直接构造简单的对齐（避免 DP 开销）
        if ($m === 0) {
            $ops = [];
            for ($j = 0; $j < $n; $j++) {
                $ops[] = ['action' => 'insert', 'new_idx' => $j, 'hash' => $new_hashes[$j]];
            }
            return $ops;
        }
        if ($n === 0) {
            $ops = [];
            for ($i = 0; $i < $m; $i++) {
                $ops[] = ['action' => 'delete', 'old_idx' => $i, 'en' => $old_en[$i] ?? ''];
            }
            return $ops;
        }
        if ($m === 1 && $n === 1) {
            if ($old_hashes[0] === $new_hashes[0]) {
                return [['action' => 'keep', 'old_idx' => 0, 'new_idx' => 0, 'hash' => $new_hashes[0], 'en' => $old_en[0] ?? '']];
            }
            return [['action' => 'replace', 'old_idx' => 0, 'new_idx' => 0, 'hash' => $new_hashes[0], 'old_hash' => $old_hashes[0], 'en' => $old_en[0] ?? '']];
        }

        // DP 建表：只保留两行以节约内存
        $prev = array_fill(0, $n + 1, 0);
        $curr = array_fill(0, $n + 1, 0);

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($old_hashes[$i - 1] === $new_hashes[$j - 1]) {
                    $curr[$j] = $prev[$j - 1] + 1;
                } else {
                    $curr[$j] = max($prev[$j], $curr[$j - 1]);
                }
            }
            $prev = $curr;
            $curr = array_fill(0, $n + 1, 0);
        }

        // 回溯找出匹配对（old_idx => new_idx）
        $matches = [];
        $i = $m;
        $j = $n;

        // 重建完整 DP 表（回溯需要，用两个数组交替）
        // 简化：用递归回溯重建
        // 由于只保留了两行，需要另建匹配表。这里用贪心+验证的方式：
        // 收集所有 hash 值相同的 (old_idx, new_idx) 对，然后按顺序配对

        // 构建 hash → old_idx[] 和 hash → new_idx[]
        $old_positions = [];
        foreach ($old_hashes as $idx => $h) {
            $old_positions[$h][] = $idx;
        }
        $new_positions = [];
        foreach ($new_hashes as $idx => $h) {
            $new_positions[$h][] = $idx;
        }

        // 找出公共 hash 并按 old_idx 排序
        $pairs = [];
        foreach ($old_positions as $h => $old_list) {
            if (!isset($new_positions[$h])) continue;
            $new_list = $new_positions[$h];
            $oi = 0;
            $ni = 0;
            // 按出现顺序贪心配对（对于重复 hash，保持相对顺序）
            while ($oi < count($old_list) && $ni < count($new_list)) {
                $pairs[] = ['old' => $old_list[$oi], 'new' => $new_list[$ni], 'hash' => $h];
                $oi++;
                $ni++;
            }
        }
        usort($pairs, function ($a, $b) {
            return $a['old'] - $b['old'];
        });

        // 过滤非递增的 new_idx（保证匹配是单调的，对应 LCS 约束）
        $filtered = [];
        $last_new = -1;
        foreach ($pairs as $p) {
            if ($p['new'] > $last_new) {
                $filtered[] = $p;
                $last_new = $p['new'];
            }
        }

        // 从匹配对生成操作列表
        $ops = [];
        $old_cursor = 0;
        $new_cursor = 0;

        foreach ($filtered as $pair) {
            // 处理 old_cursor 到 pair['old'] 之间的删除
            while ($old_cursor < $pair['old']) {
                $ops[] = ['action' => 'delete', 'old_idx' => $old_cursor, 'en' => $old_en[$old_cursor] ?? ''];
                $old_cursor++;
            }
            // 处理 new_cursor 到 pair['new'] 之间的插入
            while ($new_cursor < $pair['new']) {
                $ops[] = ['action' => 'insert', 'new_idx' => $new_cursor, 'hash' => $new_hashes[$new_cursor]];
                $new_cursor++;
            }
            // 匹配（keep）
            $ops[] = [
                'action'  => 'keep',
                'old_idx' => $old_cursor,
                'new_idx' => $new_cursor,
                'hash'    => $pair['hash'],
                'en'      => $old_en[$old_cursor] ?? '',
            ];
            $old_cursor++;
            $new_cursor++;
        }

        // 尾部剩余的删除
        while ($old_cursor < $m) {
            $ops[] = ['action' => 'delete', 'old_idx' => $old_cursor, 'en' => $old_en[$old_cursor] ?? ''];
            $old_cursor++;
        }
        // 尾部剩余的插入
        while ($new_cursor < $n) {
            $ops[] = ['action' => 'insert', 'new_idx' => $new_cursor, 'hash' => $new_hashes[$new_cursor]];
            $new_cursor++;
        }

        // 后处理：合并相邻的 delete+insert 为 replace
        // 这样 edit_lock 可以正确地将旧锁转移到新位置
        $merged = [];
        $i = 0;
        while ($i < count($ops)) {
            if ($ops[$i]['action'] === 'delete' && isset($ops[$i + 1]) && $ops[$i + 1]['action'] === 'insert') {
                $merged[] = [
                    'action'   => 'replace',
                    'old_idx'  => $ops[$i]['old_idx'],
                    'new_idx'  => $ops[$i + 1]['new_idx'],
                    'hash'     => $ops[$i + 1]['hash'],
                    'old_hash' => $old_hashes[$ops[$i]['old_idx']] ?? '',
                    'en'       => $ops[$i]['en'],
                ];
                $i += 2;
            } else {
                $merged[] = $ops[$i];
                $i++;
            }
        }

        return $merged;
    }

    /**
     * 按 HTML 块级元素（p/li/td/th/h1-h6/figcaption）细粒度拆分文本
     *
     * 在 Gutenberg 块边界拆分的基础上，进一步在块级 HTML 标签处切分，
     * 使增量翻译的追踪粒度精细到段落/列表项/表格单元格级别。
     *
     * 这是 E2 的核心方法，替代原来粗粒度的 Gutenberg 块级拆分用于增量追踪。
     *
     * @param string $html      HTML 内容
     * @param int    $max_chars 每段最大字符数（0=不限制）
     * @return array 拆分后的 HTML 段数组
     */
    private static function split_at_html_blocks($html, $max_chars = 0) {
        // 保护 Gutenberg 注释（兼容旧文章）和 <style>/<script> 块，不在其中切分
        $protected = [];
        $html = preg_replace_callback(
            '/<!--\s+(?:\/?)wp:[\w\/-]+\s*(?:\{[^}]*\})?\s*-->/',
            function ($m) use (&$protected) {
                $key = "⟨⟨GC" . count($protected) . "⟩⟩";
                $protected[$key] = $m[0];
                return $key;
            },
            $html
        );
        // 保护内联标签包裹的内容（不在 strong/em/a/span 内切分）
        $html = preg_replace_callback(
            '/<(strong|em|a|span|sup|sub)\b[^>]*>.*?<\/\1>/is',
            function ($m) use (&$protected) {
                $key = "⟨⟨IL" . count($protected) . "⟩⟩";
                $protected[$key] = $m[0];
                return $key;
            },
            $html
        );

        // 块级标签列表（开标签）
        $block_tags = 'p|li|td|th|h[1-6]|figcaption|div';
        // 在块级开标签之前切分
        $parts = preg_split(
            '/(<' . $block_tags . '\b[^>]*>)/i',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $segments = [];
        $current = '';

        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];

            // 标签 → 粘到下一段开头
            if (preg_match('/^<' . $block_tags . '\b[^>]*>/i', $part)) {
                if ($current !== '' && trim(strip_tags($current)) !== '') {
                    $segments[] = $current;
                }
                $current = $part;
                continue;
            }

            $combined = $current . $part;

            if ($max_chars <= 0 || mb_strlen($combined) <= $max_chars) {
                $current = $combined;
            } else {
                if ($current !== '' && trim(strip_tags($current)) !== '') {
                    $segments[] = $current;
                }
                $current = $part;
            }
        }

        if ($current !== '' && trim(strip_tags($current)) !== '') {
            $segments[] = $current;
        }

        // 恢复被保护的注释和内联标签
        foreach ($segments as $k => $seg) {
            $segments[$k] = str_replace(array_keys($protected), array_values($protected), $seg);
        }

        // 如果拆分后只有一段（没找到块级标签），返回原文本
        if (count($segments) <= 1) {
            return [$html];
        }

        return $segments;
    }

    /**
     * 按 HTML 块元素边界智能拆分文本
     *
     * 优先在块级闭合标签（</p>, </h1>-</h6>, </table>, </figure>, </li>）处拆分，
     * 确保每个 HTML 块不被截断。
     * 如果块本身超过限制，则在段落边界（双换行）处降级拆分。
     *
     * @param string $text     待拆分文本
     * @param int    $max_chars 每段最大字符数
     * @return array 拆分后的文本段数组
     */
    private static function split_at_block_boundaries($text, $max_chars) {
        $segments = [];
        $close_pattern = '/^<\/(?:p|h[1-6]|table|figure|li|figcaption|blockquote|div)\s*>$/';

        // 在 HTML 块级闭合标签处拆分
        $blocks = preg_split('/(<\/(?:p|h[1-6]|table|figure|li|figcaption|blockquote|div)\s*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $current = '';
        foreach ($blocks as $i => $block) {
            $combined = $current . $block;

            if (mb_strlen($combined) <= $max_chars) {
                $current = $combined;
            } else {
                // 当前 block 是闭标签 → 宁可超一点也粘在前一段（避免下段以无头闭标签开头）
                if (preg_match($close_pattern, $block)) {
                    $current .= $block;
                    // 保存（略微超限，但保证块完整）
                    $segments[] = $current;
                    $current = '';
                    continue;
                }

                // 保存前一段
                if ($current !== '') {
                    $segments[] = $current;
                }

                // 如果单个块就超过限制，在段落边界降级拆分
                if (mb_strlen($block) > $max_chars) {
                    $subs = self::split_at_paragraphs($block, $max_chars);
                    $segments = array_merge($segments, $subs);
                    $current = '';
                } else {
                    $current = $block;
                }
            }
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * 降级：在段落边界（双换行 / 块内标记）拆分
     */
    private static function split_at_paragraphs($text, $max_chars) {
        // 先尝试双换行拆分
        $paragraphs = preg_split('/(\n\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $segments = [];
        $current = '';
        $has_large_part = false;

        foreach ($paragraphs as $part) {
            $combined = $current . $part;
            if (mb_strlen($combined) <= $max_chars) {
                $current = $combined;
            } else {
                if ($current !== '') {
                    $segments[] = $current;
                }
                // 如果单个段落仍超限，优先按 HTML 标题拆分
                if (mb_strlen($part) > $max_chars) {
                    $has_large_part = true;
                    $subs = self::split_at_html_headings($part, $max_chars);
                    if (count($subs) <= 1) {
                        // 标题拆分也失败 → 硬切（最终兜底）
                        $subs = self::hard_split($part, $max_chars);
                    }
                    // 最后一个子段作为 current，前面的加入 segments
                    $last = array_pop($subs);
                    $segments = array_merge($segments, $subs);
                    $current = $last;
                } else {
                    $current = $part;
                }
            }
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        // 如果双换行拆分完全没效果（整个文本一个段落），直接用标题拆分
        if (count($segments) <= 1 && $has_large_part) {
            return self::split_at_html_headings($text, $max_chars);
        }

        return $segments;
    }

    /**
     * 降级：按 HTML 标题标签（h1-h6）拆分大段内容
     *
     * 在 <h1>, <h2>... 等标题前切开，保持每个章节的完整性。
     * 如果单个章节仍超限，硬切兜底。
     *
     * @param string $text      待拆分文本
     * @param int    $max_chars 每段最大字符数
     * @return array 拆分后的文本段数组
     */
    private static function split_at_html_headings($text, $max_chars) {
        // 在所有标题标签前切开（保留标签本身）
        $parts = preg_split('/(<h[1-6]\b[^>]*>)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $segments = [];
        $current = '';

        foreach ($parts as $i => $part) {
            // 标题标签粘到后面的内容上
            if (preg_match('/^<h[1-6]\b[^>]*>/i', $part)) {
                if ($current !== '' && mb_strlen($current) > 0) {
                    $segments[] = $current;
                }
                $current = $part;
                continue;
            }

            $combined = $current . $part;
            if (mb_strlen($combined) <= $max_chars) {
                $current = $combined;
            } else {
                if ($current !== '') {
                    $segments[] = $current;
                }
                if (mb_strlen($part) > $max_chars) {
                    // 单个章节仍然超限，硬切兜底
                    $subs = self::hard_split($part, $max_chars);
                    $last = array_pop($subs);
                    $segments = array_merge($segments, $subs);
                    $current = $last;
                } else {
                    $current = $part;
                }
            }
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        // 如果标题拆分也没效果，硬切兜底
        if (count($segments) <= 1 && mb_strlen($text) > $max_chars) {
            return self::hard_split($text, $max_chars);
        }

        return $segments;
    }

    /**
     * 最终兜底：按 HTML 安全边界拆分文本
     *
     * 不在 HTML 标签中间切断。优先在 </tr>、</li>、</figure>、</p>、</div> 等
     * 闭合标签之后拆分，保护表格和列表的完整性。实在找不到安全点才硬切。
     *
     * @param string $text      待拆分文本
     * @param int    $max_chars 每段最大字符数
     * @return array 拆分后的文本段数组
     */
    private static function hard_split($text, $max_chars) {
        $segments = [];
        $remaining = $text;

        // 安全拆分点：按优先级从高到低
        // --> 保护 Gutenberg 注释，</tr> 保护表格行，</li> 保护列表项等
        $safe_patterns = [
            '-->', '</tr>', '</li>', '</figure>', '</figcaption>',
            '</p>', '</div>', '</td>', '</th>', '</table>',
        ];

        while (mb_strlen($remaining) > $max_chars) {
            $cut_at = $max_chars;
            $found = false;

            // 在 max_chars 位置附近寻找安全拆分点（只向前找，避免段太短）
            $window = mb_substr($remaining, 0, $max_chars);
            $min_pos = intval($max_chars * 0.5); // 不低于 50%，保证每段不会太短

            foreach ($safe_patterns as $tag) {
                $pos = mb_strrpos($window, $tag);
                if ($pos !== false && $pos >= $min_pos) {
                    $cut_at = $pos + mb_strlen($tag);
                    $found = true;
                    break;
                }
            }

            // 如果找不到安全点，尝试在任意 > 之后拆分（HTML 标签闭合处）
            if (!$found) {
                $pos = mb_strrpos($window, '>');
                if ($pos !== false && $pos >= $min_pos) {
                    $cut_at = $pos + 1;
                    $found = true;
                }
            }

            $segments[] = mb_substr($remaining, 0, $cut_at);
            $remaining = mb_substr($remaining, $cut_at);
        }

        if (mb_strlen($remaining) > 0) {
            $segments[] = $remaining;
        }

        return $segments;
    }

    /**
     * DeepL HTML 专用安全分段器。
     *
     * 每个返回项都是一个完整的 HTML 片段；表格、列表、figure 以及 Gutenberg
     * 块不会被硬切。$max_chars 只是合并多个顶层块时的软限制，单个完整块即使
     * 超过该限制也保持完整，交给请求体大小检查处理。
     *
     * @return array|WP_Error
     */
    private static function segment_html_for_deepl($html, $max_chars) {
        $html = self::sanitize_debug_utf8((string) $html);
        if ($html === '') return [''];

        // 旧版 WordPress 编辑器会把 center 包成两组交叉的 p 标签：
        // <p><center></p>...<p></center></p>。浏览器会自动纠正，但它不是
        // 可独立发送给 DeepL 的平衡 HTML。只移除这层空 p 包装，不碰文字。
        $legacy_center_open = 0;
        $legacy_center_close = 0;
        $legacy_center_switch = 0;
        $html = preg_replace(
            '/<p\b[^>]*>\s*<\/center\s*>\s*<center\b([^>]*)>\s*<\/p\s*>/i',
            '</center><center$1>',
            $html,
            -1,
            $legacy_center_switch
        );
        $html = preg_replace('/<p\b[^>]*>\s*<center\b([^>]*)>\s*<\/p\s*>/i', '<center$1>', $html, -1, $legacy_center_open);
        $html = preg_replace('/<p\b[^>]*>\s*<\/center\s*>\s*<\/p\s*>/i', '</center>', $html, -1, $legacy_center_close);
        if ($legacy_center_open || $legacy_center_close || $legacy_center_switch) {
            self::debug_log('DeepL HTML normalized legacy center wrappers post=' . (self::$progress_post_id ?: 0)
                . ' open=' . $legacy_center_open . ' close=' . $legacy_center_close
                . ' switch=' . $legacy_center_switch);
        }

        $void_tags = [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ];
        // 历史文章中偶尔会残留没有对应开标签的格式闭合标签。
        // 这些标签不影响块结构，可以忽略但必须留日志；结构标签和 span 仍严格报错。
        $ignorable_orphan_closes = ['b', 'strong', 'em', 'i', 'u', 's', 'sub', 'sup', 'small', 'mark'];
        $tokens = preg_split(
            '/(<!--[\s\S]*?-->|<\s*script\b[^>]*>[\s\S]*?<\/\s*script\s*>|<\s*style\b[^>]*>[\s\S]*?<\/\s*style\s*>|<![^>]*>|<[^>]+>|[^<]+)/i',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($tokens) || empty($tokens)) return [$html];

        $stack = [];
        $gutenberg_depth = 0;
        $units = [];
        $unit = '';
        foreach ($tokens as $token) {
            $unit .= $token;

            if (strpos($token, '<!--') === 0) {
                if (preg_match('/<!--\s*\/wp:[^>]+-->/i', $token)) {
                    $gutenberg_depth = max(0, $gutenberg_depth - 1);
                } elseif (preg_match('/<!--\s*wp:[^>]+-->/i', $token)) {
                    $gutenberg_depth++;
                }
                if ($gutenberg_depth === 0 && $unit !== '' && preg_match('/-->' . '$/', $token)) {
                    $units[] = $unit;
                    $unit = '';
                }
                continue;
            }
            if (!preg_match('/^<\s*(\/?)\s*([a-z][a-z0-9:-]*)\b[^>]*>$/is', trim($token), $tag_match)) {
                continue;
            }

            $tag = strtolower($tag_match[2]);
            $is_close = $tag_match[1] === '/';
            if ($is_close) {
                if (empty($stack) || end($stack) !== $tag) {
                    if (in_array($tag, $ignorable_orphan_closes, true) && !in_array($tag, $stack, true)) {
                        self::debug_log('DeepL HTML ignored orphan inline close post=' . (self::$progress_post_id ?: 0)
                            . ' tag=</' . $tag . '>');
                        continue;
                    }
                    return new WP_Error('deepl_html_unbalanced_source',
                        '中文源文 HTML 不完整，无法安全分段：闭合标签 </' . $tag . '> 没有对应的开标签');
                }
                array_pop($stack);
                if (empty($stack) && $gutenberg_depth === 0) {
                    $units[] = $unit;
                    $unit = '';
                }
            } elseif (!in_array($tag, $void_tags, true) && !preg_match('/\/\s*>$/', trim($token))) {
                $stack[] = $tag;
            }
        }

        if (!empty($stack)) {
            return new WP_Error('deepl_html_unbalanced_source',
                '中文源文 HTML 不完整，无法安全分段：未闭合标签 <' . end($stack) . '>');
        }
        if ($gutenberg_depth !== 0) {
            return new WP_Error('deepl_html_unbalanced_source', '中文源文 Gutenberg 块注释不完整，无法安全分段');
        }
        if ($unit !== '') $units[] = $unit;
        if (empty($units)) $units = [$html];

        $segments = [];
        $current = '';
        foreach ($units as $item) {
            if ($item === '') continue;
            if ($current !== '' && mb_strlen($current . $item) > $max_chars) {
                $segments[] = $current;
                $current = '';
            }
            // 单个完整块不再硬切，避免产生以 </td>、</tr> 开头的非法请求。
            if ($current === '' && mb_strlen($item) > $max_chars) {
                $segments[] = $item;
                continue;
            }
            $current .= $item;
        }
        if ($current !== '') $segments[] = $current;
        if (empty($segments)) $segments = [$html];

        foreach ($segments as $idx => $fragment) {
            $check = self::validate_html_fragment_structure($fragment, '中文源文分段 ' . ($idx + 1));
            if (is_wp_error($check)) return $check;
        }
        return array_values($segments);
    }

    /** 根据当前引擎选择唯一的分段器。 */
    private static function segment_for_translation($text, $max_chars) {
        if (self::uses_strict_deepl_html()) {
            return self::segment_html_for_deepl($text, $max_chars);
        }
        return self::segment_content($text, $max_chars);
    }

    /**
     * 只校验 HTML 结构，不自动补标签。HTML 模式保存/推送前必须通过此检查。
     *
     * @return true|WP_Error
     */
    private static function validate_html_fragment_structure($html, $label = 'HTML') {
        $html = (string) $html;
        $void_tags = [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ];
        $ignorable_orphan_closes = ['b', 'strong', 'em', 'i', 'u', 's', 'sub', 'sup', 'small', 'mark'];
        $tokens = preg_split(
            '/(<!--[\s\S]*?-->|<\s*script\b[^>]*>[\s\S]*?<\/\s*script\s*>|<\s*style\b[^>]*>[\s\S]*?<\/\s*style\s*>|<![^>]*>|<[^>]+>)/i',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        $stack = [];
        foreach ((array) $tokens as $token) {
            if (strpos($token, '<!--') === 0 || strpos($token, '<!') === 0) continue;
            if (!preg_match('/^<\s*(\/?)\s*([a-z][a-z0-9:-]*)\b[^>]*>$/is', trim($token), $m)) continue;
            $tag = strtolower($m[2]);
            if ($m[1] === '/') {
                if (empty($stack) || end($stack) !== $tag) {
                    if (in_array($tag, $ignorable_orphan_closes, true) && !in_array($tag, $stack, true)) {
                        self::debug_log('DeepL HTML ignored orphan inline close label=' . $label
                            . ' tag=</' . $tag . '>');
                        continue;
                    }
                    self::debug_log('DeepL HTML validation failed label=' . $label . ' reason=unbalanced_html tag=' . $tag);
                    return new WP_Error('deepl_html_unbalanced', $label . ' HTML 标签不成对，已停止保存和推送');
                }
                array_pop($stack);
            } elseif (!in_array($tag, $void_tags, true) && !preg_match('/\/\s*>$/', trim($token))) {
                $stack[] = $tag;
            }
        }
        if (!empty($stack)) {
            self::debug_log('DeepL HTML validation failed label=' . $label . ' reason=unbalanced_html open=' . implode(',', $stack));
            return new WP_Error('deepl_html_unbalanced', $label . ' HTML 存在未闭合标签，已停止保存和推送');
        }
        return true;
    }

    /**
     * 统一的段落拆分入口：短文档整篇一段，长文档按「Gutenberg 块边界 → HTML 标题 → 段落」降级拆分。
     *
     * 全量翻译（translate_with_segmentation）与增量翻译（try_incremental_translate）必须共用此方法，
     * 否则增量对比的段哈希与备份里的段哈希粒度不一致，永远对不上 → 每次全量重译。
     *
     * @param string $text      已 strip_inline_styles 的源内容
     * @param int    $max_chars 每段最大字符数
     * @return array 拆分后的段数组（至少 1 段）
     */
    private static function segment_content($text, $max_chars) {
        // 短文档：整篇作为单段（与旧版全量翻译一致，保证增量哈希可匹配）
        if (mb_strlen($text) <= $max_chars) {
            return [$text];
        }

        $segments = self::split_at_block_boundaries($text, $max_chars);

        if (count($segments) <= 1) {
            // 无 Gutenberg 块标记（纯 HTML / 原始富文本）→ 降级用 HTML 标题拆分
            $segments = self::split_at_html_headings($text, $max_chars);
        }
        if (count($segments) <= 1) {
            // 标题拆分也失败（连 h1-h6 都没有）→ 段落拆分
            $segments = self::split_at_paragraphs($text, $max_chars);
        }
        if (count($segments) <= 1) {
            // 最终兜底：整篇一段
            $segments = [$text];
        }

        return $segments;
    }

    /**
     * 调用豆包 API（第一轮：保证完整性）
     */
    private static function call_doubao_api($text, $index = 0, $total = 1) {
        $api_key = get_option('lisun_doubao_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_doubao_key', '豆包 API 未配置');
        }
        $model = get_option('lisun_doubao_model', 'Doubao-Seed-2.1-pro');
        $ep_id = get_option('lisun_doubao_endpoint', '');
        $api_url = $ep_id
            ? 'https://ark.cn-beijing.volces.com/api/v3/endpoints/' . $ep_id . '/chat/completions'
            : 'https://ark.cn-beijing.volces.com/api/v3/chat/completions';

        // 型号保护
        $protection = self::protect_model_numbers($text);
        $text = $protection['text'];
        $model_map = $protection['map'];

        // 格式保护：<strong>/<em> → Unicode 占位符，防止句子被切碎
        list($text, $fmt_map) = self::protect_inline_formatting($text);

        // ★ 抽取纯文本节点
        list($placeholder_html, $text_map) = self::extract_text_nodes($text);

        if (empty($text_map)) {
            return [
                'text'              => $text,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        $text_array = [];
        $id_order = [];
        foreach ($text_map as $id => $t) {
            $decoded = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
            $decoded = trim($decoded);
            if ($decoded === '') continue;
            $id_order[] = $id;
            $text_array[] = $decoded;
        }

        if (empty($text_array)) {
            return [
                'text'              => $text,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        $seg = ($total > 1) ? "[Segment " . ($index+1) . "/" . $total . "]\n" : '';

        $system_doubao = 'You are a technical manual translator. Translate Chinese to English. '
            . 'Input is a JSON array of text segments. Translate each and return a JSON array with the same number of elements. '
            . 'Output ONLY the JSON array — no explanations. ZERO Chinese characters in output. '
            . 'Use proper English spacing between all words and after punctuation. '
            . 'CRITICAL: Preserve ALL markers in ⟨...⟩ brackets (like ⟨0⟩, ⟨FMTB0⟩, ⟨FMT/B0⟩, ⟨FMTI0⟩, ⟨FMT/I0⟩) EXACTLY as-is — do NOT drop, translate, or modify them.';

        $json_flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json_input = function_exists('wp_json_encode')
            ? wp_json_encode($text_array, $json_flags)
            : json_encode($text_array, $json_flags);
        if (!is_string($json_input)) {
            return new WP_Error('doubao_invalid_utf8', '豆包请求内容包含无法读取的文件编码');
        }

        $body = [
            'model'       => $model,
            'temperature' => 0.1,
            'max_tokens'  => 16384,
            'messages'    => [
                ['role' => 'system', 'content' => $system_doubao],
                ['role' => 'user', 'content' => $seg . $json_input],
            ],
        ];

        $encoded_body = function_exists('wp_json_encode')
            ? wp_json_encode($body, $json_flags)
            : json_encode($body, $json_flags);
        if (!is_string($encoded_body)) {
            return new WP_Error('doubao_invalid_utf8', '豆包请求内容包含无法读取的文件编码');
        }

        $response = wp_remote_post($api_url, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body'    => $encoded_body,
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) return $response;

        $http_code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code !== 200) {
            return new WP_Error('doubao_error', $data['error']['message'] ?? ('HTTP ' . $http_code));
        }

        $raw_response = trim($data['choices'][0]['message']['content'] ?? '');
        if (empty($raw_response)) return new WP_Error('doubao_empty', '豆包返回空内容');

        // 解析 JSON 数组
        $translated_array = json_decode($raw_response, true);
        if (!is_array($translated_array)) {
            $raw_response = preg_replace('/^```(?:json)?\s*\n?/i', '', $raw_response);
            $raw_response = preg_replace('/\n?```\s*$/i', '', $raw_response);
            $translated_array = json_decode($raw_response, true);
        }
        if (!is_array($translated_array) || count($translated_array) !== count($text_array)) {
            error_log('LISUN Manual: Doubao JSON parse failed or count mismatch. Expected ' . count($text_array) . ' got ' . (is_array($translated_array) ? count($translated_array) : 'non-array'));
            // 不再静默回退中文：返回错误，上游 translate_one_segment 会降级到 DeepSeek
            return new WP_Error('doubao_parse_error', __('豆包返回格式异常（JSON 解析失败或数量不匹配）', 'lisun-manual'));
        }

        $translated_map = [];
        foreach ($id_order as $i => $id) {
            $translated_map[$id] = $translated_array[$i] ?? ''; // 单元素为 null 时置空，绝不回退中文
        }

        $translated_html = self::restore_text_nodes($placeholder_html, $translated_map);

        // 还原型号
        if (!empty($model_map)) {
            $translated_html = self::restore_glossary_terms($translated_html, $model_map);
        }

        // 格式还原：Unicode 占位符 → <strong>/<em>
        $translated_html = self::restore_inline_formatting($translated_html);

        return [
            'text'              => $translated_html,
            'finish_reason'     => $data['choices'][0]['finish_reason'] ?? 'stop',
            'prompt_tokens'     => $data['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
        ];
    }

    /**
     * 在安全边界处拆分文本（用于溢出时对半拆分）
     */
    private static function find_split_point($text) {
        $mid = intval(mb_strlen($text) / 2);
        // 优先在 HTML 块级闭合标签处拆分
        // PREG_OFFSET_CAPTURE 返回字节偏移，需转为字符偏移（中文 UTF-8 3字节/字）
        if (preg_match('/<\/(?:p|h[1-6]|table|figure|li|figcaption)\s*>/', $text, $m, PREG_OFFSET_CAPTURE, max(0, $mid - 800))) {
            $byte_pos = $m[0][1] + strlen($m[0][0]);
            $pos = mb_strlen(substr($text, 0, $byte_pos)); // byte → char
            if ($pos > $mid * 0.3 && $pos < mb_strlen($text) * 0.7) return $pos;
        }
        // 退而求段落边界
        $nl = mb_strpos($text, "\n\n", max(0, $mid - 500));
        if ($nl !== false && $nl > $mid * 0.3) return $nl + 2;
        // 最后手段：硬切
        return $mid;
    }

    /**
     * 翻译 + 递归溢出保护：
     * 当 API 返回 finish_reason=length 时，对半拆分后分别翻译，确保完整性。
     */
    private static function translate_with_overflow_protection($text, $index = 0, $total = 1) {
        // DeepL 的 XML 标签校验失败不能靠重复发送同一个大请求解决。
        // 先按锚点密度和完整 HTML 子节点自动细分；每个子请求成功后再
        // 拼回原来的容器。任何一个子请求仍失败，都不会向上层返回半成品。
        if (self::uses_strict_deepl_html()) {
            return self::translate_deepl_html_with_retry($text, $index, $total, 0);
        }

        $result = self::translate_one_segment($text, $index, $total);

        if (is_wp_error($result)) {
            // 兜底重试：仅在 OpenAI 兼容引擎（DeepSeek/Gemini）下才回退到 call_deepseek_api。
            // DeepL 用的是完全不同的 key/端点/请求格式，强行回退会请求错误端点必然失败，
            // 因此 DeepL 失败时直接上抛错误，让上层重译机制接管。
            $provider = get_option('lisun_llm_provider', 'deepseek');
            if ($provider !== 'deepl') {
                $result = self::call_deepseek_api($text, $index, $total);
            }
        }

        if (is_wp_error($result)) return $result;

        if (($result['finish_reason'] ?? 'stop') === 'length') {
            if (self::uses_strict_deepl_html()) {
                return new WP_Error('deepl_html_oversize', 'HTML 分段超过 DeepL 可处理大小，未进行非法硬切，请缩短该 HTML 块后重试');
            }
            error_log('LISUN Manual: segment overflow (finish_reason=length), splitting...');
            $mid = self::find_split_point($text);
            if ($mid < 100 || $mid > mb_strlen($text) - 100) {
                error_log('LISUN Manual: cannot split segment, returning truncated');
                return $result;
            }

            $left  = self::translate_with_overflow_protection(mb_substr($text, 0, $mid), $index, $total);
            $right = self::translate_with_overflow_protection(mb_substr($text, $mid), $index, $total);

            if (is_wp_error($left) || is_wp_error($right)) return $result;

            return [
                'text'              => $left['text'] . "\n" . $right['text'],
                'prompt_tokens'     => ($left['prompt_tokens'] ?? 0) + ($right['prompt_tokens'] ?? 0),
                'completion_tokens' => ($left['completion_tokens'] ?? 0) + ($right['completion_tokens'] ?? 0),
                'finish_reason'     => 'stop',
            ];
        }

        return $result;
    }

    /**
     * DeepL 严格 HTML 路径的锚点密度拆分与递归重试。
     *
     * 分拆只发生在完整节点边界：表格会按 tr/td 层级拆分，容器会按
     * p/h/figure 等子节点拆分。请求中不带外层容器，返回后由原始容器
     * 重新包回，因此不会把一个 table 拼成多个 table，也不会丢结构。
     */
    private static function translate_deepl_html_with_retry($text, $index, $total, $depth = 0) {
        $text = (string) $text;
        $anchored = self::add_glossary_anchors($text);
        preg_match_all('/data-lisun-term-id\s*=\s*(?:["\'])term_[a-f0-9]{16,64}(?:["\'])/i', $anchored, $anchor_matches);
        $anchor_count = count($anchor_matches[0] ?? []);

        $plan = [];
        if ($anchor_count > self::DEEPL_HTML_MAX_ANCHORS && $depth < self::DEEPL_HTML_MAX_RETRY_DEPTH) {
            $plan = self::find_deepl_html_split_plan($anchored);
        }

        if (!empty($plan['parts'])) {
            self::debug_log('DeepL HTML split post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index
                . ' depth=' . $depth
                . ' reason=anchor_budget'
                . ' anchors=' . $anchor_count
                . ' parts=' . count($plan['parts']));
            return self::translate_deepl_html_split_plan($plan, $index, $total, $depth);
        }

        // Never fall through to a multi-anchor DeepL request.  A missing split
        // plan is a local parser limitation, not a reason to send a request
        // that can silently lose one of the protected terms.
        if ($anchor_count > self::DEEPL_HTML_MAX_ANCHORS) {
            self::debug_log('DeepL HTML split unavailable post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index
                . ' depth=' . $depth
                . ' anchors=' . $anchor_count);
            return new WP_Error(
                'deepl_html_unsplittable',
                '包含多个术语锚点的 HTML 分段无法安全拆分，未发送多锚点请求；已停止保存和推送，请查看 lisun-debug.log'
            );
        }
        $result = self::translate_one_segment($text, $index, $total);
        if (!is_wp_error($result)) return $result;

        $retryable = [
            'glossary_anchor_broken',
            'deepl_html_empty',
            'deepl_xml_root_missing',
            'deepl_xml_restore_failed',
        ];
        $error_code = (string) $result->get_error_code();
        if ($depth >= self::DEEPL_HTML_MAX_RETRY_DEPTH
            || $anchor_count < 2
            || !in_array($error_code, $retryable, true)) {
            return $result;
        }

        $plan = self::find_deepl_html_split_plan($anchored);
        if (empty($plan['parts'])) return $result;

        self::debug_log('DeepL HTML split post=' . (self::$progress_post_id ?: 0)
            . ' index=' . $index
            . ' depth=' . $depth
            . ' reason=' . $error_code
            . ' anchors=' . $anchor_count
            . ' parts=' . count($plan['parts']));
        return self::translate_deepl_html_split_plan($plan, $index, $total, $depth);
    }

    /** 翻译拆分计划中的每个完整子节点，并恢复原容器。 */
    private static function translate_deepl_html_split_plan($plan, $index, $total, $depth) {
        $translated = [];
        $prompt = 0;
        $completion = 0;
        $parts = (array) ($plan['parts'] ?? []);
        $part_total = count($parts);

        foreach ($parts as $part_index => $part) {
            $child = self::translate_deepl_html_with_retry($part, $index, $total, $depth + 1);
            if (is_wp_error($child)) return $child;
            $translated[] = (string) ($child['text'] ?? '');
            $prompt += (int) ($child['prompt_tokens'] ?? 0);
            $completion += (int) ($child['completion_tokens'] ?? 0);
            self::debug_log('DeepL HTML split part post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index
                . ' depth=' . ($depth + 1)
                . ' part=' . ($part_index + 1) . '/' . $part_total);
        }

        $assembled = (string) ($plan['prefix'] ?? '')
            . implode("\n", $translated)
            . (string) ($plan['suffix'] ?? '');
        $structure = self::validate_html_fragment_structure($assembled, 'DeepL 自动拆分后的译文');
        if (is_wp_error($structure)) return $structure;

        $source = self::remove_orphan_inline_closing_tags(
            self::add_glossary_anchors((string) ($plan['source'] ?? '')),
            'DeepL 自动拆分源文'
        );
        $anchors = self::validate_glossary_anchors($source, $assembled);
        if (is_wp_error($anchors)) {
            self::debug_log('DeepL HTML split validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index
                . ' depth=' . $depth
                . ' reason=anchor_mismatch_after_split');
            return $anchors;
        }

        return [
            'text'              => $assembled,
            'prompt_tokens'     => $prompt,
            'completion_tokens' => $completion,
            'finish_reason'     => 'stop',
        ];
    }

    /**
     * 取 HTML 的顶层完整节点。返回 false 表示源文无法作为拆分树解析。
     */
    private static function get_deepl_html_top_level_nodes($html) {
        $tokens = preg_split(
            '/(<!--[\\s\\S]*?-->|<![^>]*>|<[^>]+>|[^<]+)/i',
            (string) $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($tokens)) return false;

        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
        $nodes = [];
        $residual = '';
        $current = '';
        $stack = [];

        foreach ($tokens as $token) {
            $trimmed = trim((string) $token);
            $is_tag = $trimmed !== '' && $trimmed[0] === '<'
                && substr($trimmed, 0, 4) !== '<!--'
                && substr($trimmed, 0, 2) !== '<!';
            if (!$is_tag || !preg_match('/^<\\s*(\\/?)\\s*([a-z][a-z0-9:-]*)\\b([^>]*)>$/is', $trimmed, $match)) {
                if (!empty($stack)) $current .= $token;
                else $residual .= $token;
                continue;
            }

            $tag = strtolower($match[2]);
            $close = $match[1] === '/';
            $self_closing = in_array($tag, $void, true) || preg_match('/\\/\\s*>$/', $trimmed);

            if (empty($stack)) {
                if ($close) return false;
                if ($self_closing) {
                    $nodes[] = $token;
                } else {
                    $current = $token;
                    $stack = [$tag];
                }
                continue;
            }

            $current .= $token;
            if ($close) {
                if (end($stack) !== $tag) return false;
                array_pop($stack);
                if (empty($stack)) {
                    $nodes[] = $current;
                    $current = '';
                }
            } elseif (!$self_closing) {
                $stack[] = $tag;
            }
        }

        if (!empty($stack) || $current !== '') return false;
        return ['nodes' => $nodes, 'residual' => $residual];
    }

    /** 从一个完整元素中取得外层开标签、内部 HTML、闭标签。 */
    private static function split_deepl_html_node($node) {
        if (!preg_match('/^\\s*(<\\s*([a-z][a-z0-9:-]*)\\b[^>]*>)([\\s\\S]*)(<\\s*\\/\\s*\\2\\s*>)\\s*$/i', (string) $node, $match)) {
            return false;
        }
        return [
            'tag'    => strtolower($match[2]),
            'open'   => $match[1],
            'inner'  => $match[3],
            'close'  => $match[4],
        ];
    }

    /**
     * 找到可以安全拆分的子节点计划。prefix/suffix 只用于最终拼回，
     * DeepL 请求本身发送 parts，避免同一容器被重复发送。
     */
    private static function find_deepl_html_split_plan($html, $depth = 0) {
        if ($depth > 8) return [];
        $top = self::get_deepl_html_top_level_nodes($html);
        if ($top === false || trim((string) ($top['residual'] ?? '')) !== '') return [];

        $safe_tags = [
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'figcaption',
            'li', 'div', 'blockquote', 'ul', 'ol', 'table', 'tbody', 'thead',
            'tfoot', 'tr', 'td', 'th', 'center', 'img',
        ];
        $nodes = array_values(array_filter((array) ($top['nodes'] ?? []), static function ($node) use ($safe_tags) {
            if (!preg_match('/^\\s*<\\s*([a-z][a-z0-9:-]*)\\b/i', (string) $node, $m)) return false;
            return in_array(strtolower($m[1]), $safe_tags, true);
        }));
        if (count($nodes) >= 2 && count($nodes) === count($top['nodes'])) {
            return ['parts' => $nodes, 'prefix' => '', 'suffix' => '', 'source' => (string) $html];
        }

        if (count($top['nodes']) !== 1) return [];
        $outer = self::split_deepl_html_node($top['nodes'][0]);
        if ($outer === false || $outer['inner'] === '') return [];

        // 最后一层特殊情况：一个 p/td 中只有普通文本和多个术语 span。
        // 这时没有可用的块级兄弟节点，但把 9 个以上 x 标签放在同一
        // DeepL 单元中仍可能丢标签。按单个 span 拆请求，最终只把原来的
        // 外层容器包回去；不拆 span 内部，也不改变中文源文锚点。
        $inline_plan = self::find_deepl_inline_anchor_split_plan(
            $outer['inner'],
            self::DEEPL_HTML_INLINE_ANCHORS_PER_REQUEST
        );
        if (!empty($inline_plan['parts'])) {
            return [
                'parts'  => $inline_plan['parts'],
                'prefix' => $outer['open'] . ($inline_plan['prefix'] ?? ''),
                'suffix' => ($inline_plan['suffix'] ?? '') . $outer['close'],
                'source' => (string) $html,
            ];
        }

        $children = self::find_deepl_html_split_plan($outer['inner'], $depth + 1);
        if (empty($children['parts'])) return [];
        return [
            'parts'   => $children['parts'],
            'prefix'  => $outer['open'] . ($children['prefix'] ?? ''),
            'suffix'  => ($children['suffix'] ?? '') . $outer['close'],
            'source'  => (string) $html,
        ];
    }

    /**
     * 将“普通文本 + 多个完整术语 span”的内联内容拆成若干独立请求。
     * 术语 span 之外允许普通文字和 void 标签（如 br/img），但不切断
     * strong/a 等有开闭关系的格式标签。返回的 parts 本身可以直接交给 DeepL。
     */
    private static function find_deepl_inline_anchor_split_plan($inner, $anchors_per_part = 1) {
        $inner = (string) $inner;
        preg_match_all(
            '/<span\b[^>]*\bdata-lisun-term-id\s*=\s*(["\'])term_[a-f0-9]{16,64}\1[^>]*>[\s\S]*?<\/span\s*>/i',
            $inner,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $anchor_matches = $matches[0] ?? [];
        if (count($anchor_matches) < 2) return [];

        // 锚点 span 之外只能有普通文字/实体和 HTML void 标签。
        // <br /> 是参数列表中常见的换行符，不会打开一个跨分段的标签，
        // 因此可以随所在 part 一起发送；真正不能在这里拆开的是有开闭
        // 标签的 strong/em/a 等 inline 格式标签。
        $void_tags = [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ];
        $has_unsafe_tag = static function ($html) use ($void_tags) {
            preg_match_all(
                '/<\s*(\/?)\s*([a-z][a-z0-9:-]*)\b[^>]*>/i',
                (string) $html,
                $tag_matches,
                PREG_SET_ORDER
            );
            foreach ((array) ($tag_matches ?? []) as $tag_match) {
                $tag = strtolower((string) ($tag_match[2] ?? ''));
                if (!in_array($tag, $void_tags, true)) return true;
            }
            return false;
        };
        $cursor = 0;
        foreach ($anchor_matches as $match) {
            $offset = (int) ($match[1] ?? 0);
            $outside = substr($inner, $cursor, max(0, $offset - $cursor));
            if ($has_unsafe_tag($outside)) return [];
            $cursor = $offset + strlen((string) ($match[0] ?? ''));
        }
        $outside = substr($inner, $cursor);
        if ($has_unsafe_tag($outside)) return [];

        $anchors_per_part = max(1, (int) $anchors_per_part);
        $parts = [];
        $part = '';
        $count = 0;
        $cursor = 0;
        foreach ($anchor_matches as $match) {
            $offset = (int) ($match[1] ?? 0);
            $length = strlen((string) ($match[0] ?? ''));
            $part .= substr($inner, $cursor, max(0, $offset - $cursor));
            $part .= (string) $match[0];
            $count++;
            $cursor = $offset + $length;
            if ($count >= $anchors_per_part) {
                if (trim(wp_strip_all_tags($part)) !== '') $parts[] = $part;
                $part = '';
                $count = 0;
            }
        }
        $part .= substr($inner, $cursor);
        if (trim(wp_strip_all_tags($part)) !== '') $parts[] = $part;
        if (count($parts) < 2) return [];

        return ['parts' => $parts, 'prefix' => '', 'suffix' => ''];
    }

    /**
     * DeepSeek 润色（第二轮：看原文 + 机翻 → 自然英文）
     */
    private static function polish_with_deepseek($original_zh, $draft_en, $index = 0, $total = 1) {
        $config = self::get_config();
        if (empty($config['api_key'])) return new WP_Error('no_key', 'DeepSeek API 未配置');

        // 格式保护：确保格式化标签不会切碎句子，zh 和 en 同步保护保持 ID 对齐
        list($original_zh, $zh_fmt_map) = self::protect_inline_formatting($original_zh);
        list($draft_en, $en_fmt_map) = self::protect_inline_formatting($draft_en);

        // 从原文和草稿中分别抽取文本节点
        list($placeholder_html, $zh_text_map) = self::extract_text_nodes($original_zh);
        list(, $en_text_map) = self::extract_text_nodes($draft_en);

        if (empty($zh_text_map) || empty($en_text_map)) {
            return [
                'text'              => $draft_en,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        // 对齐两个文本映射表（取并集 ID）
        $all_ids = array_unique(array_merge(array_keys($zh_text_map), array_keys($en_text_map)));
        sort($all_ids, SORT_NUMERIC);

        $zh_array = [];
        $en_array = [];
        $id_order = [];
        foreach ($all_ids as $id) {
            $zh = html_entity_decode($zh_text_map[$id] ?? '', ENT_QUOTES, 'UTF-8');
            $en = html_entity_decode($en_text_map[$id] ?? '', ENT_QUOTES, 'UTF-8');
            if (trim($zh) === '' && trim($en) === '') continue;
            $id_order[] = $id;
            $zh_array[] = trim($zh);
            $en_array[] = trim($en);
        }

        if (empty($zh_array)) {
            return [
                'text'              => $draft_en,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        $seg = ($total > 1) ? "[Segment " . ($index+1) . "/" . $total . "]\n" : '';

        $system_prompt = 'You polish technical manual translations. '
            . 'You receive a JSON of Chinese original segments and a JSON of English draft segments. '
            . 'Return a JSON array of polished English segments. Same number of elements, same order. '
            . 'CRITICAL: Fix missing spaces, concatenated words, grammar, and flow. '
            . 'Do NOT add/remove content. Do NOT change technical terms or numbers. '
            . 'Preserve ALL ⟨...⟩ markers (like ⟨FMTB0⟩, ⟨FMT/B0⟩, ⟨FMTI0⟩, ⟨FMT/I0⟩) EXACTLY as-is. '
            . 'Output ONLY the JSON array.';

        $user_prompt = $seg
            . "CHINESE:\n" . json_encode($zh_array, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "ENGLISH_DRAFT:\n" . json_encode($en_array, JSON_UNESCAPED_UNICODE);

        $body = [
            'model'       => $config['model'],
            'temperature' => 0.2,
            'max_tokens'  => $config['max_tokens'],
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt],
            ],
        ];

        $response = wp_remote_post($config['api_url'], self::api_request_args($config, $body));

        if (is_wp_error($response)) return $response;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200) {
            return new WP_Error('polish_error', $data['error']['message'] ?? ('HTTP ' . $http_code));
        }

        $raw = trim($data['choices'][0]['message']['content'] ?? '');
        if (empty($raw)) return new WP_Error('polish_empty', '润色返回空内容');

        $polished_array = json_decode($raw, true);
        if (!is_array($polished_array)) {
            $raw = preg_replace('/^```(?:json)?\s*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```\s*$/i', '', $raw);
            $polished_array = json_decode($raw, true);
        }
        if (!is_array($polished_array) || count($polished_array) !== count($zh_array)) {
            $polished_array = $en_array;
        }

        $translated_map = [];
        foreach ($id_order as $i => $id) {
            $translated_map[$id] = $polished_array[$i] ?? ($en_text_map[$id] ?? $zh_text_map[$id]);
        }

        $polished_html = self::restore_text_nodes($placeholder_html, $translated_map);

        // 格式还原：Unicode 占位符 → <strong>/<em>
        $polished_html = self::restore_inline_formatting($polished_html);

        return [
            'text'              => $polished_html,
            'finish_reason'     => $data['choices'][0]['finish_reason'] ?? 'stop',
            'prompt_tokens'     => $data['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
        ];
    }

    /**
     * 三轮自检：对比原文，修复遗漏
     */
    private static function self_check($original_zh, $english_text) {
        $config = self::get_config();
        if (empty($config['api_key'])) return $english_text; // 无 API 直接返回

        $system_prompt = 'You verify technical translations. Compare the Chinese original with the English translation.
Find and fix: missing sentences, untranslated Chinese, incorrect numbers, missing technical details, missing spaces between English words, punctuation without trailing spaces, concatenated words that should be separate.
CRITICAL: Check spacing carefully — Chinese text has no spaces, so the translation often loses spaces between words or after punctuation. Add missing spaces.
Output the FIXED English translation with all issues corrected.
If the translation is already perfect, output it unchanged.
Output ONLY the fixed translation, no explanations.';

        $user_prompt = "=== CHINESE ORIGINAL ===\n" . $original_zh . "\n"
            . "=== ENGLISH TRANSLATION ===\n" . $english_text . "\n"
            . "=== FIXED TRANSLATION (or unchanged if perfect) ===";

        $body = [
            'model'       => $config['model'],
            'temperature' => 0.05,
            'max_tokens'  => $config['max_tokens'],
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt],
            ],
        ];

        $response = wp_remote_post($config['api_url'], self::api_request_args($config, $body));

        if (is_wp_error($response)) return $english_text;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = trim($data['choices'][0]['message']['content'] ?? '');

        return $text ?: $english_text;
    }

    // ── docx 管线翻译（extractor → raw text → LLM → build_en_docx → extractor → HTML）──

    /**
     * 通过原始 .docx 文件翻译一篇 manual 页面
     *
     * 新管线（不经过 HTML，避免标签干扰 LLM）：
     * 1. extractor.py 提取中文纯文本（带【b】占位符）
     * 2. translate_raw_paragraphs() 翻译英文（占位符保护）
     * 3. build_en_docx.py 构建英文 .docx
     * 4. extractor.py 解析英文 .docx → 英文 HTML
     * 5. 创建英文 Post + 保存 .docx + 推远程
     *
     * @param int    $post_id   中文 post ID
     * @param string $docx_path 备份的原始 .docx 路径
     * @return array|WP_Error
     */
    private static function translate_from_docx($post_id, $docx_path) {
        $post = get_post($post_id);
        $start_time = microtime(true);

        error_log("LISUN Manual: translate_from_docx for post {$post_id}, docx={$docx_path}");

        // ── 1. extractor.py 提取中文 ──
        $python = self::find_python_binary();
        if (!$python) {
            error_log('LISUN Manual: Python not available for docx pipeline, fallback to HTML mode.');
            return self::translate_post_fallback($post_id);  // 回退旧管线
        }

        $extractor = LISUN_MANUAL_PATH . 'extractor.py';
        if (!file_exists($extractor)) {
            return self::translate_post_fallback($post_id);
        }

        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($extractor)
             . ' ' . escapeshellarg($docx_path) . ' 2>&1';
        $zh_json_raw = @shell_exec($cmd);

        if (empty($zh_json_raw)) {
            error_log('LISUN Manual: extractor returned empty for zh docx, fallback.');
            return self::translate_post_fallback($post_id);
        }

        $zh_data = json_decode($zh_json_raw, true);
        if (empty($zh_data['ok'])) {
            error_log('LISUN Manual: extractor failed for zh docx: ' . ($zh_data['error'] ?? 'unknown'));
            return self::translate_post_fallback($post_id);
        }

        $zh_paragraphs = [];
        // 标题以中文网站当前文章标题为准。
        // DOCX 内部的第一行可能是旧标题或文件标题，不能覆盖后台已经修改好的标题。
        $zh_title = !empty($post->post_title)
            ? $post->post_title
            : ($zh_data['title'] ?? '');
        foreach ($zh_data['paragraphs'] as $p) {
            $zh_paragraphs[] = $p['text'] ?? '';
        }

        if (empty($zh_paragraphs)) {
            error_log('LISUN Manual: no paragraphs extracted from zh docx');
            return self::translate_post_fallback($post_id);
        }

        error_log("LISUN Manual: extractor got " . count($zh_paragraphs) . " zh paragraphs");

        // ── 2. 翻译纯文本 → 英文段落 ──
        $trans_result = self::translate_raw_paragraphs($zh_paragraphs, $zh_title);

        if (is_wp_error($trans_result)) {
            error_log('LISUN Manual: translate_raw_paragraphs failed: ' . $trans_result->get_error_message());
            return $trans_result;
        }

        $en_paragraphs = $trans_result['paragraphs'];
        $en_title = $trans_result['title'] ?: $zh_title;
        $prompt_tokens = $trans_result['prompt_tokens'];
        $completion_tokens = $trans_result['completion_tokens'];

        error_log("LISUN Manual: translation OK, " . count($en_paragraphs) . " en paragraphs");

        // ── 3. build_en_docx.py 构建英文 .docx ──
        $builder = LISUN_MANUAL_PATH . 'build_en_docx.py';
        $tmp_dir = LISUN_MANUAL_PATH . 'temp/';
        wp_mkdir_p($tmp_dir);

        $trans_json_file = $tmp_dir . 'trans_' . $post_id . '_' . uniqid() . '.json';
        $en_docx_file    = $tmp_dir . 'en_' . $post_id . '_' . uniqid() . '.docx';
        file_put_contents($trans_json_file, json_encode([
            'paragraphs' => $en_paragraphs,
            'title'      => $en_title,
        ], JSON_UNESCAPED_UNICODE));

        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($builder) . ' '
             . escapeshellarg($docx_path) . ' ' . escapeshellarg($trans_json_file) . ' '
             . escapeshellarg($en_docx_file) . ' 2>&1';
        $build_output = @shell_exec($cmd);
        @unlink($trans_json_file);

        if (!$build_output || strpos($build_output, 'OK:') !== 0) {
            error_log('LISUN Manual: build_en_docx failed: ' . ($build_output ?: 'no output'));
            if (file_exists($en_docx_file)) @unlink($en_docx_file);
            // 回退：用翻译结果直接拼 HTML
            return self::translate_from_docx_fallback($post_id, $post, $en_title, $en_paragraphs, $zh_data);
        }

        error_log("LISUN Manual: build_en_docx OK: {$en_docx_file}");

        // ── 4. extractor.py 解析英文 .docx → 英文 HTML ──
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($extractor)
             . ' ' . escapeshellarg($en_docx_file) . ' 2>&1';
        $en_json_raw = @shell_exec($cmd);

        $en_blocks = [];
        if ($en_json_raw) {
            $en_data = json_decode($en_json_raw, true);
            if (!empty($en_data['ok'])) {
                $en_blocks = self::build_html_blocks_from_extractor($en_data);
            }
        }

        // 如果 extractor 解析英文 docx 失败，回退到直接拼 HTML
        if (empty($en_blocks)) {
            error_log('LISUN Manual: extractor failed on en docx, falling back to direct HTML assembly.');
            @unlink($en_docx_file);
            return self::translate_from_docx_fallback($post_id, $post, $en_title, $en_paragraphs, $zh_data);
        }

        $en_content = implode("\n\n", $en_blocks);
        $en_content = self::inject_anchor_units($en_content, $en_paragraphs);
        if (self::term_anchors_enabled()) {
            $anchor_check = self::validate_glossary_anchors(
                self::add_glossary_anchors(implode("\n", $zh_paragraphs)),
                $en_content
            );
            if (is_wp_error($anchor_check)) return $anchor_check;
        } else {
            $en_content = self::strip_glossary_anchors($en_content);
        }

        // ── 5. 同步更新本地英文副本 ──
        // DOCX 管线也必须更新本地英文 post，否则后台查看副本时仍会看到旧标题。
        $local_en_id = self::create_local_en_page($post, $en_content, $en_title);
        if (is_wp_error($local_en_id)) {
            error_log('LISUN Manual: local English post update failed: ' . $local_en_id->get_error_message());
        }

        // ── 6. 英文 .docx 附加到中文 post ──
        self::attach_docx_to_post($post_id, $en_docx_file, $en_title);
        @unlink($en_docx_file);

        // ── 7. SEO + slug ──
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $en_slug = !empty($tags) ? sanitize_title($tags[0]) : $post->post_name;
        $en_seo = self::translate_seo_meta($post_id);
        if (is_wp_error($en_seo)) return $en_seo;
        $en_title_anchored = self::strip_glossary_anchors($en_title);
        $en_seo_anchored = $en_seo;

        // ── 8. 推送到远程站 ──
        $push_result = Lisun_Manual_Sync::push_translation($post_id, $en_title, $en_content, $en_slug, $en_seo);

        // ── 9. 保存备份 ──
        $duration = microtime(true) - $start_time;
        self::save_translation_backup($post_id, [
            'en_title'       => $en_title,
            'en_title_anchored' => $en_title_anchored,
            'en_content'     => $en_content,
            'en_slug'        => $en_slug,
            'en_seo'         => $en_seo,
            'en_seo_anchored' => $en_seo_anchored,
            'tags'           => $tags ?: [],
            'glossary_hash'  => md5(json_encode(Lisun_Manual_Glossary::get_all())),
            'zh_segments'    => $zh_paragraphs,
            'en_parts'       => $en_paragraphs,
            'segment_hashes' => array_map('md5', $zh_paragraphs),
            'block_locks'    => array_fill(0, count($zh_paragraphs), 'auto'),
        ]);

        Lisun_Manual_Translate_Log::add([
            'zh_page_id'       => $post_id,
            'en_page_id'       => 0,
            'status'           => is_wp_error($push_result) ? 'translated_no_push' : 'success',
            'duration'         => $duration,
            'prompt_tokens'    => $prompt_tokens,
            'completion_tokens'=> $completion_tokens,
            'chars'            => self::$deepl_chars_used,
        ]);

        if (is_wp_error($push_result)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'push_failed');
            error_log('LISUN Manual: push failed (docx pipeline): ' . $push_result->get_error_message());
        } else {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
            update_post_meta($post_id, '_lisun_manual_remote_id', $push_result);
        }

        return [
            'en_title'         => $en_title,
            'remote_id'        => is_wp_error($push_result) ? 0 : $push_result,
            'prompt_tokens'    => $prompt_tokens,
            'completion_tokens'=> $completion_tokens,
        ];
    }

    /**
     * 从 extractor JSON 数据构建 Gutenberg block HTML 数组
     */
    private static function build_html_blocks_from_extractor($data) {
        $blocks = [];
        foreach ($data['paragraphs'] as $p) {
            $text = $p['text'] ?? '';
            $ptype = $p['type'] ?? 'paragraph';
            // 占位符 → HTML 标签
            $html = self::placeholders_to_html_static($text);
            switch ($ptype) {
                case 'heading':
                    $level = min(6, max(1, $p['level'] ?? 1));
                    $blocks[] = "<h{$level}>{$html}</h{$level}>";
                    break;
                case 'list_item':
                    $blocks[] = "<li>{$html}</li>";
                    break;
                default:
                    if (!empty(trim(strip_tags($html)))) {
                        $blocks[] = "<p>{$html}</p>";
                    }
                    break;
            }
        }
        // 表格
        foreach ($data['tables'] as $table) {
            $rows_html = '';
            foreach ($table['rows'] as $row_cells) {
                $cells_html = '';
                foreach ($row_cells as $cell) {
                    $cell_text = self::placeholders_to_html_static($cell['text'] ?? '');
                    $tag = !empty($cell['is_header']) ? 'th' : 'td';
                    $attrs = '';
                    if (($cell['colspan'] ?? 1) > 1) $attrs .= ' colspan="' . intval($cell['colspan']) . '"';
                    if (($cell['rowspan'] ?? 1) > 1) $attrs .= ' rowspan="' . intval($cell['rowspan']) . '"';
                    $cells_html .= "<{$tag}{$attrs}>{$cell_text}</{$tag}>";
                }
                $rows_html .= "<tr>{$cells_html}</tr>";
            }
            $blocks[] = "<figure><table>{$rows_html}</table></figure>";
        }
        return $blocks;
    }

    /**
     * 占位符 → HTML 标签（静态版本，供 translate_from_docx 使用）
     */
    private static function placeholders_to_html_static($text) {
        $map = [
            '/【b】/'      => '<strong>',
            '/【\/b】/'    => '</strong>',
            '/【i】/'      => '<em>',
            '/【\/i】/'    => '</em>',
            '/【u】/'      => '<u>',
            '/【\/u】/'    => '</u>',
            '/【sub】/'    => '<sub>',
            '/【\/sub】/'  => '</sub>',
            '/【sup】/'    => '<sup>',
            '/【\/sup】/'  => '</sup>',
        ];
        $text = preg_replace(array_keys($map), array_values($map), $text);
        $text = preg_replace('/【link:(https?:\/\/[^】]+)】(.*?)【\/link】/u',
            '<a href="$1" target="_blank" rel="noopener">$2</a>', $text);
        $text = preg_replace('/【color:([0-9a-fA-F]{6})】(.*?)【\/color】/u',
            '<span style="color:#$1">$2</span>', $text);
        return $text;
    }

    /**
     * 将 .docx 文件上传到 WP 媒体库并关联到指定 post
     */
    private static function attach_docx_to_post($post_id, $docx_path, $title) {
        if (!file_exists($docx_path)) return false;
        $safe_name = sanitize_file_name(sanitize_title($title) . '-en.docx');
        $upload = wp_upload_bits($safe_name, null, file_get_contents($docx_path));
        if (!empty($upload['error'])) return false;

        $attachment = [
            'post_title'     => $title . ' (English .docx)',
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $attach_id = wp_insert_attachment($attachment, $upload['file'], $post_id);
        if (is_wp_error($attach_id)) return false;

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        update_post_meta($post_id, '_lisun_en_docx_attachment', $attach_id);
        return $attach_id;
    }

    /**
     * docx 管线失败时的回退方案
     */
    private static function translate_post_fallback($post_id) {
        // 清除 docx 路径标记，确保下次走旧管线时不会再次尝试
        // 实际上我们保留标记，但直接走旧管线
        error_log("LISUN Manual: Falling back to HTML-based translation for post {$post_id}");
        // 移除 docx 路径标记避免死循环
        $docx_path = get_post_meta($post_id, '_lisun_manual_docx_path', true);
        delete_post_meta($post_id, '_lisun_manual_docx_path');

        // 调用原来的 translate_post 逻辑（通过递归，但这次没有 docx_path）
        $result = self::translate_post($post_id);

        // 恢复 docx 路径
        if ($docx_path) {
            update_post_meta($post_id, '_lisun_manual_docx_path', $docx_path);
        }
        return $result;
    }

    /**
     * build_en_docx 成功但 extractor 解析英文 docx 失败时的回退
     */
    private static function translate_from_docx_fallback($post_id, $post, $en_title, $en_paragraphs, $zh_data) {
        error_log("LISUN Manual: Falling back to direct HTML assembly for post {$post_id}");

        // 直接用英文段落 + 中文 extractor 的结构信息拼 HTML
        $blocks = [];
        $en_idx = 0;
        foreach ($zh_data['paragraphs'] as $p) {
            $en_text = $en_paragraphs[$en_idx] ?? '';
            $en_idx++;
            $html = self::placeholders_to_html_static($en_text);
            $ptype = $p['type'] ?? 'paragraph';
            switch ($ptype) {
                case 'heading':
                    $level = min(6, max(1, $p['level'] ?? 1));
                    $blocks[] = "<h{$level}>{$html}</h{$level}>";
                    break;
                case 'list_item':
                    $blocks[] = "<li>{$html}</li>";
                    break;
                default:
                    if (!empty(trim(strip_tags($html)))) {
                        $blocks[] = "<p>{$html}</p>";
                    }
                    break;
            }
        }

        $en_content = implode("\n\n", $blocks);
        if (self::term_anchors_enabled()) {
            $zh_text = [];
            foreach ((array) ($zh_data['paragraphs'] ?? []) as $p) $zh_text[] = $p['text'] ?? '';
            $anchor_check = self::validate_glossary_anchors(self::add_glossary_anchors(implode("\n", $zh_text)), $en_content);
            if (is_wp_error($anchor_check)) return $anchor_check;
        } else {
            $en_content = self::strip_glossary_anchors($en_content);
        }

        // 回退路径也要更新本地英文副本，保持与 DOCX 正常路径一致。
        $local_en_id = self::create_local_en_page($post, $en_content, $en_title);
        if (is_wp_error($local_en_id)) {
            error_log('LISUN Manual: local English post update failed (fallback): ' . $local_en_id->get_error_message());
        }

        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $en_slug = !empty($tags) ? sanitize_title($tags[0]) : $post->post_name;
        $en_seo = self::translate_seo_meta($post_id);
        if (is_wp_error($en_seo)) return $en_seo;
        $en_title_anchored = self::strip_glossary_anchors($en_title);
        $en_seo_anchored = $en_seo;
        $push_result = Lisun_Manual_Sync::push_translation($post_id, $en_title, $en_content, $en_slug, $en_seo);

        self::save_translation_backup($post_id, [
            'en_title' => $en_title,
            'en_title_anchored' => $en_title_anchored,
            'en_content' => $en_content,
            'en_slug' => $en_slug,
            'en_seo' => $en_seo,
            'en_seo_anchored' => $en_seo_anchored,
            'tags' => $tags ?: [],
            'glossary_hash' => md5(json_encode(Lisun_Manual_Glossary::get_all())),
            'zh_segments' => $zh_text ?? [],
            'en_parts' => $en_paragraphs,
            'segment_hashes' => array_map('md5', $zh_text ?? []),
            'block_locks' => array_fill(0, count($en_paragraphs), 'auto'),
        ]);

        return [
            'en_title'         => $en_title,
            'remote_id'        => is_wp_error($push_result) ? 0 : $push_result,
        ];
    }

    /**
     * 翻译纯文本段落数组（带【b】占位符，不经过 HTML）
     *
     * 与 call_deepseek_api 的区别：输入是 extractor 产出的原始文本数组，
     * 不含任何 HTML 标签，只有 【b】等内联格式占位符。
     * 占位符被保护后原样传递给 LLM，翻译完成后恢复。
     *
     * @param array  $paragraphs 段落文本数组，如 ["请【b】布置【/b】设备", ...]
     * @param string $title      文档标题（单独翻译，因为标题不含占位符）
     * @return array|WP_Error ['title' => ..., 'paragraphs' => [en_text, ...],
     *                         'prompt_tokens' => int, 'completion_tokens' => int]
     */
    public static function translate_raw_paragraphs($paragraphs, $title = '') {
        self::heartbeat(); // 续租队列任务（docx 管线也可能很长）

        // DOCX 原始段落也必须走 DeepL Glossary，而不是旧的 DeepSeek 占位符流程。
        if (get_option('lisun_llm_provider', 'deepseek') === 'deepl') {
            return self::translate_raw_paragraphs_deepl($paragraphs, $title);
        }

        $config = self::get_config();
        if (empty($config['api_key'])) {
            return new WP_Error('no_key', __('DeepSeek API 未配置', 'lisun-manual'));
        }

        // ── 1. 保护占位符：【b】→ ⟨B⟩, 【/b】→ ⟨/B⟩ ──
        $placeholder_map = [
            '【b】'     => '⟨B⟩',
            '【/b】'   => '⟨/B⟩',
            '【i】'     => '⟨I⟩',
            '【/i】'   => '⟨/I⟩',
            '【u】'     => '⟨U⟩',
            '【/u】'   => '⟨/U⟩',
            '【sub】'   => '⟨SUB⟩',
            '【/sub】' => '⟨/SUB⟩',
            '【sup】'   => '⟨SUP⟩',
            '【/sup】' => '⟨/SUP⟩',
        ];
        // 链接占位符需要特殊处理（含 URL）
        $link_pattern = '/【link:([^】]+)】(.*?)【\/link】/u';

        $protected_paragraphs = [];
        $link_maps = [];  // index → [{placeholder, url, text}]

        foreach ($paragraphs as $idx => $text) {
            // 先保护链接：提取出来，避免 URL 被翻译
            $link_maps[$idx] = [];
            $link_counter = 0;
            $text = preg_replace_callback($link_pattern, function ($m) use ($idx, &$link_counter) {
                $key = "⟨LINK{$link_counter}⟩";
                $link_maps[$idx][$key] = [
                    'url'  => $m[1],
                    'text' => $m[2],
                ];
                $link_counter++;
                return $key;
            }, $text);

            // 保护内联格式占位符
            $text = str_replace(
                array_keys($placeholder_map),
                array_values($placeholder_map),
                $text
            );
            $text = self::add_glossary_anchors($text);
            $protected_paragraphs[] = $text;
        }

        // ── 2. 术语保护 + 型号保护 ──
        $all_text = implode("\n", $protected_paragraphs);
        $protection = self::protect_glossary_terms($all_text);
        $protected_text = $protection['text'];
        $glossary_map = $protection['map'];

        $model_protection = self::protect_model_numbers($protected_text);
        $protected_text = $model_protection['text'];
        $glossary_map = array_merge($glossary_map, $model_protection['map']);

        // 拆分回数组
        $text_array = explode("\n", $protected_text);

        // 过滤纯空白
        $non_empty_indices = [];
        $filtered = [];
        foreach ($text_array as $i => $t) {
            $t = trim($t);
            if ($t === '') {
                $filtered[] = '';
            } else {
                $non_empty_indices[] = $i;
                $filtered[] = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
            }
        }

        // ── 3. 翻译标题 ──
        $en_title = '';
        if (!empty($title)) {
            $title_result = self::translate_title($title);
            $en_title = !empty($title_result) ? $title_result : $title;
        }

        // ── 4. 调用 DeepSeek API ──
        $source_lang = self::get_lang_name(get_option('lisun_translate_source_lang', 'zh'));
        $target_lang = self::get_lang_name(get_option('lisun_translate_target_lang', 'en'));

        $system_prompt = 'You are translating LISUN Group laboratory testing / lighting measurement equipment manuals from ' . $source_lang . ' to ' . $target_lang . '.
Input is a JSON array of text segments. Translate each segment and return a JSON array with EXACTLY the same number of elements in the same order.

RULES:
1. Translate ALL Chinese text to natural technical English. Keep existing English text, numbers, symbols exactly as-is.
2. Return ONLY a valid JSON array. No markdown fences, no explanations, no chat.
3. Do NOT skip or drop any elements. Output array length MUST equal input array length.
4. Output must contain ZERO Chinese characters.
5. SPACING IS CRITICAL: Insert proper spaces between EVERY English word.
6. TERMINOLOGY: Use consistent, industry-standard technical English.
7. Empty segments → return empty string "".
8. CRITICAL: Preserve ALL markers in ⟨...⟩ brackets EXACTLY as-is — do NOT drop, translate, or modify them.
   Examples: ⟨B⟩text⟨/B⟩ → ⟨B⟩text⟨/B⟩, ⟨LINK0⟩text⟨/LINK⟩ stays as-is.
9. When a term anchor appears, preserve the complete <span data-lisun-term-id="...">...</span> structure and translate only its inner text.';

        $json_input = json_encode($filtered, JSON_UNESCAPED_UNICODE);
        $body = [
            'model'       => $config['model'],
            'temperature' => $config['temperature'],
            'max_tokens'  => $config['max_tokens'],
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $json_input],
            ],
        ];

        $response = wp_remote_post($config['api_url'], self::api_request_args($config, $body));

        if (is_wp_error($response)) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_text  = wp_remote_retrieve_body($response);
        $data       = json_decode($body_text, true);

        if ($http_code !== 200 || empty($data['choices'][0]['message']['content'])) {
            $error_msg = $data['error']['message'] ?? ('HTTP ' . $http_code);
            error_log('LISUN Manual translate_raw: API error - ' . $error_msg);
            return new WP_Error('api_error', $error_msg);
        }

        $raw_response = trim($data['choices'][0]['message']['content'] ?? '');

        // 尝试解析 JSON
        $translated = json_decode($raw_response, true);
        if (!is_array($translated)) {
            // 有时 LLM 返回的不是纯 JSON，尝试提取
            if (preg_match('/\[.*\]/s', $raw_response, $m)) {
                $translated = json_decode($m[0], true);
            }
        }
        if (!is_array($translated) || count($translated) !== count($filtered)) {
            error_log('LISUN Manual translate_raw: invalid response, expected ' . count($filtered) . ' items, got ' . (is_array($translated) ? count($translated) : 'non-array'));
            return new WP_Error('parse_error', 'Translation response parsing failed');
        }

        // ── 5. 恢复占位符 + 术语 ──
        $en_paragraphs = [];
        foreach ($text_array as $i => $original) {
            if (trim($original) === '') {
                $en_paragraphs[] = '';
                continue;
            }
            $en = $translated[$i] ?? $original;

            // 恢复术语
            if (!empty($glossary_map)) {
                $en = str_replace(array_keys($glossary_map), array_values($glossary_map), $en);
            }

            // 恢复内联格式占位符
            $en = str_replace(
                array_values($placeholder_map),
                array_keys($placeholder_map),
                $en
            );

            // 恢复链接
            if (!empty($link_maps[$i])) {
                foreach ($link_maps[$i] as $key => $info) {
                    $link_placeholder = str_replace('⟨LINK', '⟨/LINK', $key); // 找对应的结束标记
                    // 重建链接占位符
                    $replacement = '【link:' . $info['url'] . '】' . $info['text'] . '【/link】';
                    // 尝试匹配 LLM 可能保留的 LINK 标记
                    $en = str_replace($key, $replacement, $en);
                }
            }

            if (self::term_anchors_enabled()) {
                $check = self::validate_glossary_anchors($protected_paragraphs[$i] ?? '', $en);
                if (is_wp_error($check)) return $check;
            }

            $en_paragraphs[] = $en;
        }

        $prompt_tokens     = $data['usage']['prompt_tokens'] ?? 0;
        $completion_tokens = $data['usage']['completion_tokens'] ?? 0;

        return [
            'title'            => $en_title,
            'paragraphs'       => $en_paragraphs,
            'prompt_tokens'    => $prompt_tokens,
            'completion_tokens'=> $completion_tokens,
        ];
    }

    /**
     * DOCX 原始段落的 DeepL 翻译路径。
     * 格式标记在发送前转换为 HTML 标签，普通术语交给 DeepL Glossary 处理。
     */
    private static function translate_raw_paragraphs_deepl($paragraphs, $title = '') {
        $prepared = [];
        $model_maps = [];
        foreach ((array) $paragraphs as $text) {
            $text = (string) $text;
            $text = preg_replace_callback(
                '/【link:([^】]+)】(.*?)【\/link】/us',
                function ($m) {
                    return '<a href="' . esc_attr($m[1]) . '">' . $m[2] . '</a>';
                },
                $text
            );
            $text = str_replace(
                ['【b】', '【/b】', '【i】', '【/i】', '【u】', '【/u】', '【sub】', '【/sub】', '【sup】', '【/sup】'],
                ['<strong>', '</strong>', '<em>', '</em>', '<u>', '</u>', '<sub>', '</sub>', '<sup>', '</sup>'],
                $text
            );
            $text = self::add_glossary_anchors($text);
            $model_protection = self::protect_model_numbers($text);
            $prepared[] = $model_protection['text'];
            $model_maps[] = $model_protection['map'];
        }

        $translated = self::call_deepl_text_array($prepared);
        if (is_wp_error($translated)) {
            return $translated;
        }

        $en_paragraphs = [];
        foreach ($translated as $idx => $text) {
            if (!empty($model_maps[$idx])) {
                $text = str_replace(array_keys($model_maps[$idx]), array_values($model_maps[$idx]), $text);
            }
            $text = preg_replace_callback(
                '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
                function ($m) {
                    return '【link:' . $m[1] . '】' . $m[2] . '【/link】';
                },
                $text
            );
            $text = str_replace(
                ['<strong>', '</strong>', '<em>', '</em>', '<u>', '</u>', '<sub>', '</sub>', '<sup>', '</sup>'],
                ['【b】', '【/b】', '【i】', '【/i】', '【u】', '【/u】', '【sub】', '【/sub】', '【sup】', '【/sup】'],
                $text
            );
            if (self::term_anchors_enabled()) {
                $check = self::validate_glossary_anchors($prepared[$idx] ?? '', $text);
                if (is_wp_error($check)) return $check;
            }
            $en_paragraphs[] = $text;
        }

        $en_title = '';
        if ($title !== '') {
            $title_result = self::translate_title($title);
            $en_title = $title_result ?: $title;
        }

        return [
            'title' => $en_title,
            'paragraphs' => $en_paragraphs,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
        ];
    }

    /**
     * 使用同一个 DeepL Glossary 批量翻译纯文本数组。
     */
    private static function call_deepl_text_array($texts) {
        $glossary_id = Lisun_Manual_Glossary::is_enabled()
            ? Lisun_Manual_Glossary::ensure_deepl_glossary()
            : '';
        if (is_wp_error($glossary_id)) {
            return $glossary_id;
        }

        $formality = get_option('lisun_deepl_formality', 'default');
        $all = [];
        foreach (array_chunk((array) $texts, 50) as $chunk) {
            $body = [
                'text' => array_values($chunk),
                'source_lang' => 'ZH',
                'target_lang' => 'EN-US',
                'formality' => $formality,
            ];
            if ($glossary_id !== '') {
                $body['glossary_id'] = $glossary_id;
            }
            if (strpos(implode('', $chunk), '<') !== false) {
                $body['tag_handling'] = 'html';
                $body['tag_handling_version'] = 'v2';
                $body['split_sentences'] = 'nonewlines';
            }
            $response = Lisun_Manual_Glossary::deepl_api_request('/v2/translate', 'POST', $body);
            if (is_wp_error($response)) {
                return $response;
            }
            $items = $response['data']['translations'] ?? [];
            if (count($items) !== count($chunk)) {
                return new WP_Error('deepl_incomplete', __('DeepL 返回条数不完整，请重试', 'lisun-manual'));
            }
            foreach ($items as $item) {
                $text = (string) ($item['text'] ?? '');
                if ($text === '') {
                    return new WP_Error('deepl_empty', __('DeepL 返回了空译文，请重试', 'lisun-manual'));
                }
                $all[] = $text;
            }
        }
        return $all;
    }

    /**
     * 调用 DeepSeek API（单轮，兜底用）
     *
     * @param string $text 待翻译文本
     * @param int $index 段序号
     * @param int $total 总段数
     * @return array|WP_Error ['text' => string, 'prompt_tokens' => int, 'completion_tokens' => int]
     */
    private static function call_deepseek_api($text, $index = 0, $total = 1) {
        $config = self::get_config();

        if (empty($config['api_key'])) {
            return new WP_Error('no_key', __('未配置 DeepSeek API 密钥', 'lisun-manual'));
        }

        // === 格式保护：<strong>/<em> → Unicode 占位符，防止被 extract_text_nodes 切碎句子 ===
        list($text, $fmt_map) = self::protect_inline_formatting($text);

        // === 术语保护：中文术语 → 占位符 ===
        $protection = self::protect_glossary_terms($text);
        $text = $protection['text'];
        $glossary_map = $protection['map'];

        // === 型号保护 ===
        $model_protection = self::protect_model_numbers($text);
        $text = $model_protection['text'];
        $glossary_map = array_merge($glossary_map, $model_protection['map']);

        // ★ 抽取纯文本节点，LLM 不碰 HTML 结构
        // 格式保护后，整句不会被 <strong>/<em> 标签切分，LLM 收到完整语义句
        list($placeholder_html, $text_map) = self::extract_text_nodes($text);

        if (empty($text_map)) {
            return [
                'text'              => $text,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        // 构建纯文本数组（解码 HTML 实体，过滤空值）
        $text_array = [];
        $id_order = [];
        foreach ($text_map as $id => $t) {
            $decoded = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
            $decoded = trim($decoded);
            if ($decoded === '') continue;
            $id_order[] = $id;
            $text_array[] = $decoded;
        }

        if (empty($text_array)) {
            return [
                'text'              => $text,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        // 构建 system prompt — 现在要求输出 JSON 数组
        $source_lang = self::get_lang_name(get_option('lisun_translate_source_lang', 'zh'));
        $target_lang = self::get_lang_name(get_option('lisun_translate_target_lang', 'en'));

        $system_prompt = 'You are translating LISUN Group laboratory testing / lighting measurement equipment manuals from ' . $source_lang . ' to ' . $target_lang . '.
Input is a JSON array of text segments extracted from the document. Translate each segment and return a JSON array with EXACTLY the same number of elements in the same order.

RULES:
1. Translate ALL Chinese text to natural technical English. Keep existing English text, numbers, symbols exactly as-is.
2. Return ONLY a valid JSON array. No markdown fences, no explanations, no chat.
3. Do NOT skip or drop any elements. Output array length MUST equal input array length.
4. Output must contain ZERO Chinese characters.
5. SPACING IS CRITICAL: Insert proper spaces between EVERY English word. Never output concatenated words.
6. TERMINOLOGY: Use consistent, industry-standard technical English for lighting/testing equipment.
7. Empty or whitespace-only segments → return empty string "".
8. CRITICAL: Preserve ALL markers in ⟨...⟩ brackets (like ⟨0⟩, ⟨FMTB0⟩, ⟨FMT/B0⟩, ⟨FMTI0⟩, ⟨FMT/I0⟩) EXACTLY as-is — do NOT drop, translate, or add spaces inside them.';

        // 用户消息：JSON 数组
        $json_input = json_encode($text_array, JSON_UNESCAPED_UNICODE);
        $seg_header = ($total > 1) ? "[Segment " . ($index + 1) . " of " . $total . "]\n" : '';
        $user_prompt = $seg_header . $json_input;

        $body = [
            'model'       => $config['model'],
            'temperature' => $config['temperature'],
            'max_tokens'  => $config['max_tokens'],
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt],
            ],
        ];

        // 重试机制
        $last_error = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_post($config['api_url'], self::api_request_args($config, $body));

            if (is_wp_error($response)) {
                $last_error = $response;
                continue;
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $body_text = wp_remote_retrieve_body($response);
            $data = json_decode($body_text, true);

            if ($http_code !== 200) {
                $error_msg = $data['error']['message'] ?? ('HTTP ' . $http_code);
                error_log('LISUN Manual DeepSeek API error: HTTP ' . $http_code . ' - ' . $error_msg);
                $last_error = new WP_Error('api_error', $error_msg);

                if ($http_code === 429 || $http_code >= 500) {
                    sleep($attempt * 2);
                    continue;
                }
                break;
            }

            $raw_response = trim($data['choices'][0]['message']['content'] ?? '');

            if (empty($raw_response)) {
                $last_error = new WP_Error('empty_response', __('API 返回空内容', 'lisun-manual'));
                continue;
            }

            // 解析 JSON 数组响应
            $translated_array = json_decode($raw_response, true);
            if (!is_array($translated_array)) {
                // 尝试清理 markdown 围栏
                $raw_response = preg_replace('/^```(?:json)?\s*\n?/i', '', $raw_response);
                $raw_response = preg_replace('/\n?```\s*$/i', '', $raw_response);
                $translated_array = json_decode($raw_response, true);
            }

            if (!is_array($translated_array) || count($translated_array) !== count($text_array)) {
                error_log('LISUN Manual: LLM JSON parse failed or count mismatch. Expected ' . count($text_array) . ' got ' . (is_array($translated_array) ? count($translated_array) : 'non-array'));
                // 不再静默回退中文：记错误并重试，重试耗尽后由调用方感知失败
                $last_error = new WP_Error('parse_error', __('翻译返回格式异常（JSON 解析失败或数量不匹配）', 'lisun-manual'));
                continue;
            }

            // 重建翻译映射表
            $translated_map = [];
            foreach ($id_order as $i => $id) {
                $translated_map[$id] = $translated_array[$i] ?? ''; // 单元素为 null 时置空，绝不回退中文
            }

            // 将翻译后的文本填回 HTML 占位符
            $translated_html = self::restore_text_nodes($placeholder_html, $translated_map);

            // 术语还原
            if (!empty($glossary_map)) {
                $translated_html = self::restore_glossary_terms($translated_html, $glossary_map);
            }

            // 格式还原：Unicode 占位符 → <strong>/<em> HTML 标签
            $translated_html = self::restore_inline_formatting($translated_html);

            // 残留中文记日志
            if (preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $translated_html)) {
                error_log('LISUN Manual: Chinese chars remain in output, will be cleaned by post-processing');
            }

            $finish_reason = $data['choices'][0]['finish_reason'] ?? 'stop';
            if ($finish_reason === 'length') {
                error_log('LISUN Manual: finish_reason=length, will trigger overflow split');
            }

            return [
                'text'              => $translated_html,
                'prompt_tokens'     => $data['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                'finish_reason'     => $finish_reason,
            ];
        }

        return $last_error ?: new WP_Error('max_retries', __('翻译重试已达上限', 'lisun-manual'));
    }

    /**
     * 调用 DeepL 翻译 API
     *
     * DeepL 使用独立的 REST API 格式（非 OpenAI 兼容），端点：
     * - Pro:  https://api.deepl.com/v2/translate
     * - Free: https://api-free.deepl.com/v2/translate
     *
     * 认证方式：Authorization: DeepL-Auth-Key <key>（非 Bearer）
     *
     * @param string $text  待翻译文本
     * @param int    $index 段序号
     * @param int    $total 总段数
     * @return array|WP_Error
     */
    private static function call_deepl_html_api($text, $index = 0, $total = 1) {
        $api_key = get_option('lisun_deepseek_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_deepl_key', 'DeepL API 未配置（请在 API 密钥字段填写 DeepL Key）');
        }

        // 普通术语交给 DeepL Glossary，避免用不可理解的占位符打断句法推理。
        $glossary_id = Lisun_Manual_Glossary::is_enabled()
            ? Lisun_Manual_Glossary::ensure_deepl_glossary()
            : '';
        if (is_wp_error($glossary_id)) {
            return $glossary_id;
        }
        $formality = get_option('lisun_deepl_formality', 'default');

        $original_text = (string) $text;
        $text = self::sanitize_debug_utf8($original_text);
        if ($text !== $original_text) {
            self::debug_log('DeepL HTML sanitized invalid UTF-8 post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' removed_bytes=' . max(0, strlen($original_text) - strlen($text)));
        }
        $source_html = self::add_glossary_anchors($text);
        $model_protection = self::protect_model_numbers($source_html);
        $source_html = $model_protection['text'];
        // 旧 HTML 兼容校验允许历史内容中的孤立 </b>/<em> 等结束标签，
        // 但 XML tag handling 会在请求解析阶段直接拒绝它们。删除孤立的
        // 行内结束标签不会改变可见文字或正常标签结构，且只作用于严格
        // DeepL XML 请求的本地副本。
        $source_html = self::remove_orphan_inline_closing_tags($source_html, 'DeepL 中文源文分段');
        $anchor_source_html = $source_html;
        // DeepL HTML 模式会按英文语序合并、拆分或复制普通 inline span。
        // 术语锚点改用 DeepL 官方 XML inline tag 作为传输标记；返回后
        // 立即还原为本地 span，再按中文源文 term_id 写入固定英文。
        $source_xml = self::prepare_deepl_xml_markup($source_html);

        $source_check = self::validate_html_fragment_structure($source_html, 'DeepL 中文源文分段');
        if (is_wp_error($source_check)) {
            self::debug_log('DeepL HTML validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=unbalanced_html');
            return $source_check;
        }
        preg_match_all('/data-lisun-term-id\s*=\s*(["\'])([^"\']+)\1/i', $source_html, $preflight_ids);
        $preflight_ids = array_values($preflight_ids[2] ?? []);
        preg_match_all('/<\s*(?![\/!?])([a-z][a-z0-9:-]*)\b[^>]*>/i', $source_html, $preflight_open_matches);
        preg_match_all('/<\s*\/\s*([a-z][a-z0-9:-]*)\b[^>]*>/i', $source_html, $preflight_close_matches);
        $preflight_open_count = 0;
        $preflight_void_tags = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
        foreach (($preflight_open_matches[0] ?? []) as $match_index => $full_tag) {
            $tag_name = strtolower($preflight_open_matches[1][$match_index] ?? '');
            if (!in_array($tag_name, $preflight_void_tags, true) && !preg_match('/\/\s*>$/', $full_tag)) {
                $preflight_open_count++;
            }
        }
        self::debug_log('DeepL HTML preflight post=' . (self::$progress_post_id ?: 0)
            . ' index=' . $index
            . ' source_bytes=' . strlen($source_html)
            . ' source_open_tags=' . $preflight_open_count
            . ' source_close_tags=' . count($preflight_close_matches[0] ?? [])
            . ' balanced=yes anchor_count=' . count($preflight_ids)
            . ' anchor_ids=' . wp_json_encode($preflight_ids, JSON_UNESCAPED_UNICODE));

        // 先分别计算原始输入和锚点/型号预处理后的可见文本。两者应基本一致；
        // 若预处理阶段丢字，必须在调用 DeepL 前停止，不能再把责任误报给 API。
        $input_plain = self::visible_html_text_for_integrity((string) $text);
        $source_plain = self::visible_html_text_for_integrity($source_html);
        // 型号保护只是在发送给 DeepL 前的临时占位符。完整性检查要把
        // ⟨1000⟩ 等占位符还原回原型号后再比较，否则会把正常的型号保护
        // 误判为“正文丢失”（例如 LMS-9000C/LMS-9500C 系列）。
        foreach ((array) ($model_protection['map'] ?? []) as $placeholder => $model) {
            $source_plain = str_replace((string) $placeholder, (string) $model, $source_plain);
        }
        $input_visible_chars = mb_strlen($input_plain);
        $source_visible_chars = mb_strlen($source_plain);
        if ($input_visible_chars > 0
            && ($source_visible_chars === 0 || ($source_visible_chars / $input_visible_chars) < 0.8)) {
            self::debug_log('DeepL HTML preprocessing lost text post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index
                . ' input_visible_chars=' . $input_visible_chars
                . ' processed_visible_chars=' . $source_visible_chars
                . ' input_preview=' . wp_json_encode(mb_substr($input_plain, 0, 200), JSON_UNESCAPED_UNICODE)
                . ' processed_preview=' . wp_json_encode(mb_substr($source_plain, 0, 200), JSON_UNESCAPED_UNICODE));
            return new WP_Error('deepl_html_preprocess_lost_text',
                '插件在生成中文术语锚点时丢失了正文（原文可见字符 ' . $input_visible_chars
                . '，处理后 ' . $source_visible_chars . '），请求尚未发送到 DeepL。已停止保存和推送');
        }
        // 只有属性、图片或空标签的片段不含可见正文，不应发送给 DeepL。
        if ($source_plain === '') {
            $skip_diag = 'DeepL [' . $index . '/' . $total . '] HTML skipped empty markup'
                . ' post=' . (self::$progress_post_id ?: 0)
                . ' input="' . mb_substr(wp_strip_all_tags((string) $text), 0, 80) . '"';
            error_log('LISUN Manual: ' . $skip_diag);
            self::debug_log($skip_diag . ' raw=' . wp_json_encode(mb_substr((string) $text, 0, 300), JSON_UNESCAPED_UNICODE));
            return [
                'text'              => $text,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        $plain_length = mb_strlen($source_plain);
        self::$deepl_chars_used += $plain_length;
        $body = [
            'text'                  => [$source_xml],
            'source_lang'           => 'ZH',
            'target_lang'           => 'EN-US',
            'formality'             => $formality,
            'tag_handling'          => 'xml',
            'tag_handling_version'  => 'v2',
            'split_sentences'       => 'nonewlines',
            // 保留源文块边界，避免 DeepL 在表格单元格/图注的开头补入
            // 不属于源文的冒号、逗号或句首小写连接词。
            'preserve_formatting'   => true,
            'non_splitting_tags'    => ['x'],
        ];
        if ($glossary_id !== '') $body['glossary_id'] = $glossary_id;

        $request_json = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen((string) $request_json) > self::DEEPL_HTML_REQUEST_MAX_BYTES) {
            self::debug_log('DeepL HTML validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=request_too_large bytes=' . strlen((string) $request_json));
            return new WP_Error('deepl_html_oversize', 'HTML 分段过大，未进行非法硬切，请缩短该 HTML 块后重试');
        }

        $response = Lisun_Manual_Glossary::deepl_api_request('/v2/translate', 'POST', $body, [
            'post_id' => (int) (self::$progress_post_id ?: 0),
            'index' => (int) $index,
            // 严格 HTML 路径一旦发生锚点差异，必须能核对 DeepL 的逐字节原始响应。
            // 不依赖后台诊断开关，避免失败后才发现没有留下取证文件。
            'force_trace' => true,
        ]);
        if (is_wp_error($response)) {
            error_log('LISUN Manual DeepL HTML API error: ' . $response->get_error_message());
            return $response;
        }
        $translations = $response['data']['translations'] ?? [];
        if (count($translations) !== 1 || empty($translations[0]['text'])) {
            $payload = $response['data'] ?? [];
            self::debug_log('DeepL HTML malformed response post=' . (self::$progress_post_id ?: 0)
                . ' http=' . ($response['code'] ?? 0)
                . ' content_type=' . ($response['content_type'] ?? '')
                . ' request=' . wp_json_encode([
                    'source_lang' => 'ZH',
                    'target_lang' => 'EN-US',
                    'glossary_id' => $glossary_id !== '' ? 'set' : 'empty',
                    'tag_handling' => 'xml',
                    'tag_handling_version' => 'v2',
                    'source_chars' => $plain_length,
                    'source_preview' => mb_substr($source_plain, 0, 200),
                ], JSON_UNESCAPED_UNICODE)
                . ' response_keys=' . wp_json_encode(is_array($payload) ? array_keys($payload) : [])
                . ' translations_count=' . count($translations)
                . ' raw_preview=' . wp_json_encode($response['raw_preview'] ?? '', JSON_UNESCAPED_UNICODE));
            self::debug_log('DeepL HTML validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=empty_translation');
            $response_reason = count($translations) !== 1
                ? '服务器返回的 translations 数量为 ' . count($translations) . '（预期 1）'
                : '服务器返回的 translations[0].text 为空';
            return new WP_Error('deepl_html_empty',
                'DeepL HTML 请求已发出，但' . $response_reason
                . '。原始请求和响应追踪 ID：' . (($response['trace_id'] ?? '') ?: '未启用原始报文记录')
                . '。已停止保存和推送，请查看 lisun-debug.log 后重试');
        }
        $translated_html = self::restore_deepl_xml_markup((string) $translations[0]['text']);
        if (is_wp_error($translated_html)) {
            self::debug_log('DeepL XML validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=missing_xml_root'
                . ' trace_id=' . ((string) ($response['trace_id'] ?? 'trace_write_failed')));
            return $translated_html;
        }
        // DeepL 的 XML tag handling 可能把图注、表格和表格单元格的边界
        // 当成句子边界处理。修复必须以源文结构为依据，只处理受影响的
        // figure/table 局部，不能对整篇英文做全局标点删除。
        $translated_html = self::repair_deepl_structural_boundaries($text, $translated_html);
        // HTTP 200 但只返回空标签（例如 <p></p>）同样属于失败；严格 HTML
        // 模式不回退旧文本节点流程，避免生成无锚点或结构不一致的译文。
        $translated_plain = trim(html_entity_decode(strip_tags((string) $translated_html), ENT_QUOTES, 'UTF-8'));
        if ($translated_plain === '') {
            self::debug_log('DeepL HTML validation failed post='
                . (self::$progress_post_id ?: 0)
                . ' index=' . $index
                . ' reason=empty_translation'
                . ' source_chars=' . $plain_length
                . ' response_bytes=' . strlen((string) $translated_html)
                . ' response_preview=' . wp_json_encode(mb_substr((string) $translated_html, 0, 300), JSON_UNESCAPED_UNICODE));
            return new WP_Error('deepl_html_empty',
                'DeepL 返回 HTTP 成功，但译文 text 为空或只有空 HTML 标签。原始请求和响应追踪 ID：'
                . (($response['trace_id'] ?? '') ?: '未启用原始报文记录')
                . '。已停止保存和推送，请查看 lisun-debug.log 后重试');
        }
        $output_check = self::validate_html_fragment_structure($translated_html, 'DeepL 英文译文分段');
        if (is_wp_error($output_check)) return $output_check;
        // 诊断锚点的唯一身份链路：源文中文 span -> DeepL 返回的同一 term_id。
        // 英文译法只存在于 span 内部，绝不能参与 ID 反查或生成。
        $anchor_ids = function ($html) {
            preg_match_all('/data-lisun-term-id\s*=\s*(["\'])([^"\']+)\1/i', (string) $html, $matches);
            return array_values($matches[2] ?? []);
        };
        // 校验源必须是“发送前的中文 HTML 锚点稿”，不能是 DeepL XML 线稿。
        // 某些已部署的旧请求链路可能把 <lisun-deepl-root>/<x> 传进来；
        // 先转换回本地 span，避免源锚点被数成 0，继而把返回的全部 ID
        // 误报为“多出”。这一步只还原 ID 外壳，不从英文译文反查术语。
        $validation_source_html = self::normalize_glossary_anchor_source($anchor_source_html);
        if (is_wp_error($validation_source_html)) {
            self::debug_log('DeepL HTML validation source normalization failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=' . $validation_source_html->get_error_code());
            return $validation_source_html;
        }
        $source_anchor_ids = $anchor_ids($validation_source_html);
        $output_anchor_ids = $anchor_ids($translated_html);
        self::debug_log('DeepL HTML result post=' . (self::$progress_post_id ?: 0)
            . ' index=' . $index
            . ' response_bytes=' . strlen((string) $translated_html)
            . ' visible_chars=' . mb_strlen($translated_plain)
            . ' source_anchor_count=' . count($source_anchor_ids)
            . ' output_anchor_count=' . count($output_anchor_ids)
            . ' source_ids=' . wp_json_encode($source_anchor_ids, JSON_UNESCAPED_UNICODE)
            . ' output_ids=' . wp_json_encode($output_anchor_ids, JSON_UNESCAPED_UNICODE));
        self::debug_log('DeepL source/output anchors post=' . (self::$progress_post_id ?: 0)
            . ' index=' . $index
            . ' source_count=' . count($source_anchor_ids)
            . ' output_count=' . count($output_anchor_ids)
            . ' source_ids=' . wp_json_encode($source_anchor_ids, JSON_UNESCAPED_UNICODE)
            . ' output_ids=' . wp_json_encode($output_anchor_ids, JSON_UNESCAPED_UNICODE));
        $anchor_check = self::validate_glossary_anchors($validation_source_html, $translated_html);
        if (is_wp_error($anchor_check)) {
            error_log('LISUN Manual: ' . $anchor_check->get_error_message());
            $trace_id = (string) ($response['trace_id'] ?? '');
            self::debug_log('DeepL HTML validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=anchor_mismatch'
                . ' trace_id=' . ($trace_id !== '' ? $trace_id : 'trace_write_failed'));
            return new WP_Error(
                $anchor_check->get_error_code(),
                $anchor_check->get_error_message()
                    . ' 原始报文追踪 ID：' . ($trace_id !== '' ? $trace_id : '写入失败，请查看 lisun-debug.log 中的 trace create/write failed'),
                $anchor_check->get_error_data()
            );
        }
        $anchor_translation = self::apply_source_glossary_anchor_translations($translated_html);
        if (is_wp_error($anchor_translation)) {
            self::debug_log('DeepL HTML validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=anchor_local_translation_failed'
                . ' trace_id=' . ((string) ($response['trace_id'] ?? 'trace_write_failed')));
            return $anchor_translation;
        }
        $translated_html = $anchor_translation['text'];
        self::debug_log('DeepL HTML source anchors locally translated post='
            . (self::$progress_post_id ?: 0) . ' index=' . $index
            . ' count=' . ($anchor_translation['count'] ?? 0));
        if (!empty($model_protection['map'])) {
            $translated_html = self::restore_glossary_terms($translated_html, $model_protection['map']);
        }
        $translated_html = self::post_process_translation($translated_html);
        $anchor_check = self::validate_glossary_anchors($validation_source_html, $translated_html);
        if (is_wp_error($anchor_check)) {
            $trace_id = (string) ($response['trace_id'] ?? '');
            self::debug_log('DeepL HTML validation failed post=' . (self::$progress_post_id ?: 0)
                . ' index=' . $index . ' reason=anchor_mismatch_after_post_process'
                . ' trace_id=' . ($trace_id !== '' ? $trace_id : 'trace_write_failed'));
            return new WP_Error(
                $anchor_check->get_error_code(),
                $anchor_check->get_error_message()
                    . ' 原始报文追踪 ID：' . ($trace_id !== '' ? $trace_id : '写入失败，请查看 lisun-debug.log 中的 trace create/write failed'),
                $anchor_check->get_error_data()
            );
        }
        $final_html_check = self::validate_html_fragment_structure($translated_html, 'DeepL 英文译文分段');
        if (is_wp_error($final_html_check)) return $final_html_check;

        $diag = 'DeepL [' . $index . '/' . $total . '] HTML chars=' . $plain_length
            . ' | in="' . mb_substr(wp_strip_all_tags((string) $text), 0, 80) . '"'
            . ' | out="' . mb_substr(wp_strip_all_tags((string) $translated_html), 0, 80) . '"';
        error_log('LISUN Manual: ' . $diag);
        self::debug_log($diag);

        return [
            'text'              => $translated_html,
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'finish_reason'     => 'stop',
        ];
    }

    private static function call_deepl_api($text, $index = 0, $total = 1, $with_anchors = true) {
        $api_key = get_option('lisun_deepseek_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_deepl_key', 'DeepL API 未配置（请在 API 密钥字段填写 DeepL Key）');
        }

        // 恢复原有的文本节点翻译流程。术语表仍通过 DeepL Glossary 生效，
        // 但稳定锚点只在译文返回后本地插入，不再改变 DeepL 的输入结构。
        $glossary_id = Lisun_Manual_Glossary::is_enabled()
            ? Lisun_Manual_Glossary::ensure_deepl_glossary()
            : '';
        if (is_wp_error($glossary_id)) return $glossary_id;
        $formality = get_option('lisun_deepl_formality', 'default');
        $source_for_anchors = self::sanitize_debug_utf8((string) $text);
        $text = $source_for_anchors;

        // 格式保护：<strong>/<em> → Unicode 占位符（DeepL 也需要合并句子）
        list($text, $fmt_map) = self::protect_inline_formatting($text);

        // 保存"未做术语/型号保护"的原始文本节点，用于 DeepL 返回脏字符时回退直译
        list(, $fallback_text_map) = self::extract_text_nodes($text);

        // 型号保护
        $model_protection = self::protect_model_numbers($text);
        $text = $model_protection['text'];
        $model_map = $model_protection['map'];

        // ★ 核心改进：抽取纯文本节点，不让 LLM 碰 HTML 结构
        list($placeholder_html, $text_map) = self::extract_text_nodes($text);

        // 纯文本（如标题，无 HTML 标签）抽不到节点 → 把整段当作单个文本节点翻译
        if (empty($text_map)) {
            if (trim((string) $text) === '') {
                return [
                    'text'              => $text,
                    'prompt_tokens'     => 0,
                    'completion_tokens' => 0,
                    'finish_reason'     => 'stop',
                ];
            }
            $placeholder_html = '__TR0__';
            $text_map = [0 => $text];
        }

        // 构建纯文本数组（解码 HTML 实体）
        $text_array = [];
        $id_order   = [];
        $total_chars = 0;
        foreach ($text_map as $id => $t) {
            $decoded = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
            $decoded = trim($decoded);
            if ($decoded === '') continue;
            $id_order[] = $id;
            $text_array[] = $decoded;
            $total_chars += mb_strlen($decoded);
        }

        // 累计 DeepL 消耗字符数（供翻译日志展示；token 引擎不受影响）
        self::$deepl_chars_used += $total_chars;

        if (empty($text_array)) {
            return [
                'text'              => $text,
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'finish_reason'     => 'stop',
            ];
        }

        // DeepL 文本大小限制
        $truncated = false;
        if ($total_chars > 4000) {
            $truncated = true;
            error_log('LISUN Manual: DeepL text array total ' . $total_chars . ' chars (may be truncated)');
        }

        // 发送纯文本数组 — 不发送 HTML，不使用 tag_handling
        // DeepL 单次最多 50 条文本：大表格一个段可能有上百个文本节点（每格=1条），
        // 超过 50 条会返回 400/空响应。按 50 条一批切块分批请求，再合并结果。
        $chunks = array_chunk($text_array, 50);
        $all_translations = [];

        foreach ($chunks as $chunk) {
            $body = [
                'text'        => $chunk,
                'source_lang' => 'ZH',
                'target_lang' => 'EN-US',
                'formality'   => $formality,
            ];
            if ($glossary_id !== '') {
                $body['glossary_id'] = $glossary_id;
            }

            $response = Lisun_Manual_Glossary::deepl_api_request('/v2/translate', 'POST', $body);
            if (is_wp_error($response)) {
                error_log('LISUN Manual DeepL API error: ' . $response->get_error_message());
                return $response;
            }
            $data = $response['data'];

            $chunk_translations = $data['translations'] ?? [];
            if (count($chunk_translations) !== count($chunk)) {
                error_log('LISUN Manual: DeepL returned ' . count($chunk_translations) . ' translations for ' . count($chunk) . ' segments');
                return new WP_Error('deepl_incomplete', __('DeepL 返回条数不完整，请重试', 'lisun-manual'));
            }

            $all_translations = array_merge($all_translations, $chunk_translations);
        }

        $translations = $all_translations;

        // 诊断日志：记录 DeepL 收到/返回的样本，用于定位残留中文根因。
        // 若日志里根本看不到这一行，说明实际没走 DeepL（provider 配置不是 deepl）。
        $diag = 'DeepL [' . $index . '/' . $total . '] ' . count($text_array) . ' texts'
            . ' | in="' . mb_substr((string) ($text_array[0] ?? ''), 0, 80) . '"'
            . ' | out="' . mb_substr((string) ($translations[0]['text'] ?? ''), 0, 80) . '"';
        error_log('LISUN Manual: ' . $diag);
        self::debug_log($diag);

        // 条数必须与输入一致，否则视为不完整响应（超长被截断/部分失败）。
        // 不再静默用中文原文顶替，改为报错让上层感知，重译后可得到干净结果。
        if (count($translations) !== count($text_array)) {
            error_log('LISUN Manual: DeepL returned ' . count($translations) . ' translations for ' . count($text_array) . ' segments');
            return new WP_Error('deepl_incomplete', __('DeepL 返回条数不完整，请重试', 'lisun-manual'));
        }

        // 回退直译：DeepL 若把"占位符+中文"组合翻成 U+FFFD/U+202F 脏字符，
        // 用未做术语保护的原文直接重译该条，绕过会出错的占位符组合，避免内容丢失。
        foreach ($translations as $i => $tr) {
            if (!preg_match('/[\x{FFFD}\x{202F}]/u', (string) ($tr['text'] ?? ''))) {
                continue;
            }
            $orig = $fallback_text_map[$id_order[$i]] ?? '';
            $orig_decoded = trim(html_entity_decode((string) $orig, ENT_QUOTES, 'UTF-8'));
            if ($orig_decoded === '') continue;

            $fb_body = [
                'text'        => [$orig_decoded],
                'source_lang' => 'ZH',
                'target_lang' => 'EN-US',
                'formality'   => $formality,
            ];
            if ($glossary_id !== '') {
                $fb_body['glossary_id'] = $glossary_id;
            }
            // 回退直译也会消耗字符，一并累计
            self::$deepl_chars_used += mb_strlen($orig_decoded);
            $fb_resp = Lisun_Manual_Glossary::deepl_api_request('/v2/translate', 'POST', $fb_body);
            if (is_wp_error($fb_resp)) continue;

            $fb_text = $fb_resp['data']['translations'][0]['text'] ?? '';
            if ($fb_text !== '' && !preg_match('/[\x{FFFD}\x{202F}]/u', $fb_text)) {
                self::debug_log('fallback retranslated dirty: "' . mb_substr($orig_decoded, 0, 40) . '" -> "' . mb_substr($fb_text, 0, 40) . '"');
                $translations[$i] = ['text' => $fb_text];
            }
        }

        // 按 ID 顺序重建翻译映射表（逐条校验，不允许空译文静默回退中文）
        $translated_map = [];
        foreach ($id_order as $i => $id) {
            $txt = $translations[$i]['text'] ?? '';
            if ($txt === '') {
                error_log('LISUN Manual: DeepL returned empty text for segment ' . ($i + 1));
                return new WP_Error('deepl_empty', __('DeepL 返回了空译文（第 ' . ($i + 1) . ' 段），请重试', 'lisun-manual'));
            }
            // 纯文本路径永远不从英文译文反查锚点。正文锚点只允许由
            // call_deepl_html_api() 在中文源文中生成。
            $translated_map[$id] = $txt;
        }

        // 将翻译后的文本填回 HTML 占位符
        $translated_html = self::restore_text_nodes($placeholder_html, $translated_map);

        // 仅还原型号；普通术语已由 DeepL Glossary 在解码阶段处理。
        if (!empty($model_map)) {
            $translated_html = self::restore_glossary_terms($translated_html, $model_map);
        }

        // 格式还原：Unicode 占位符 → <strong>/<em>
        $translated_html = self::restore_inline_formatting($translated_html);

        // 后处理：空格 + 大小写 + 标点
        $translated_html = self::post_process_translation($translated_html);

        // 残留中文记日志
        if (preg_match('/[\x{4e00}-\x{9fff}]{2,}/u', $translated_html)) {
            error_log('LISUN Manual: DeepL output contains residual Chinese characters');
        }

        $finish_reason = $truncated ? 'length' : 'stop';

        return [
            'text'              => $translated_html,
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'finish_reason'     => $finish_reason,
        ];
    }

    // ==================== 术语处理 ====================

    /**
     * 在源 HTML 的可翻译文本节点中插入稳定术语锚点。
     * 只处理文本，不触碰属性；术语不能跨越现有 HTML 标签。
     */
    private static function add_glossary_anchors($html) {
        if (!self::term_anchors_enabled() || !is_string($html) || $html === '') {
            return $html;
        }
        // 锚点匹配使用 Unicode 正则。若源文含非法 UTF-8，preg_replace_callback
        // 会返回 null，旧实现随后把整段内容拼成空字符串；必须在这里先清洗。
        $html = self::sanitize_debug_utf8($html);
        if ($html === '') return $html;
        $terms = array_values(array_filter(Lisun_Manual_Glossary::get_all(), function ($item) {
            return !empty($item['term_id']) && !empty($item['zh']) && !empty($item['en']);
        }));
        usort($terms, function ($a, $b) {
            return mb_strlen($b['zh']) <=> mb_strlen($a['zh']);
        });
        if (empty($terms)) return $html;

        // 术语可能有数千条，不能把它们拼成一个巨型正则。PCRE 在表达式
        // 过大时会返回 null，旧代码随后会把整个文本节点清空。这里使用
        // Unicode Trie 做精确、最长优先匹配，数量再多也不会丢失原文。
        $trie = [];
        $terminal = "\0";
        $seen_text = [];
        foreach ($terms as $item) {
            $zh = (string) $item['zh'];
            if ($zh === '' || isset($seen_text[$zh])) continue;
            $seen_text[$zh] = true;
            $chars = preg_split('//u', $zh, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars) || empty($chars)) continue;
            $node =& $trie;
            foreach ($chars as $char) {
                if (!isset($node[$char]) || !is_array($node[$char])) {
                    $node[$char] = [];
                }
                $node =& $node[$char];
            }
            $node[$terminal] = $item;
            unset($node);
        }
        if (empty($trie)) return $html;
        $generated = [];
        $skipped = [];

        $process_text = function ($text, $wrap, $skip_reason = '') use (&$trie, $terminal, &$generated, &$skipped) {
            $chars = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars) || empty($chars)) return $text;

            $result = '';
            $count = count($chars);
            for ($i = 0; $i < $count;) {
                $node =& $trie;
                $best_item = null;
                $best_end = -1;
                for ($j = $i; $j < $count; $j++) {
                    $char = $chars[$j];
                    if (!isset($node[$char]) || !is_array($node[$char])) break;
                    $node =& $node[$char];
                    if (array_key_exists($terminal, $node)) {
                        $best_item = $node[$terminal];
                        $best_end = $j;
                    }
                }
                unset($node);

                if ($best_item !== null) {
                    $matched = implode('', array_slice($chars, $i, $best_end - $i + 1));
                    if ($wrap) {
                        $generated[] = [
                            'term_id' => $best_item['term_id'],
                            'zh'      => $best_item['zh'],
                            'en'      => $best_item['en'],
                        ];
                        $result .= '<span data-lisun-term-id="' . esc_attr($best_item['term_id']) . '">' . $matched . '</span>';
                    } else {
                        $skipped[] = ['zh' => $matched, 'reason' => $skip_reason ?: 'protected'];
                        $result .= $matched;
                    }
                    $i = $best_end + 1;
                    continue;
                }

                $result .= $chars[$i];
                $i++;
            }
            return $result;
        };

        $parts = preg_split('/(<!--[\\s\\S]*?-->|<![^>]*>|<[^>]+>)/', $html, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) return $html;

        $stack = [];
        $skip_tags = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($part[0] !== '<') {
                $skip = false;
                $skip_reason = '';
                foreach ($stack as $entry) {
                    if (!empty($entry['skip'])) {
                        $skip = true;
                        $skip_reason = $entry['tag'];
                        break;
                    }
                }
                if (!$skip) {
                    $part = $process_text($part, true);
                } else {
                    $part = $process_text($part, false, $skip_reason);
                }
                $out .= $part;
                continue;
            }

            $out .= $part;
            if (preg_match('/^<\\s*([a-z][a-z0-9:-]*)\\b([^>]*)>/i', $part, $m)) {
                $tag = strtolower($m[1]);
                if (in_array($tag, ['br', 'hr', 'img', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'param', 'source', 'track', 'wbr'], true)
                    || preg_match('/\\/\\s*>$/', $part)) {
                    continue;
                }
                $attrs = $m[2] ?? '';
                $is_skip = in_array($tag, $skip_tags, true)
                    || preg_match('/(?:^|\\s)class\\s*=\\s*["\'][^"\']*\\bnotranslate\\b/i', $attrs)
                    || preg_match('/(?:^|\\s)data-(?:no-translation|no-auto-translation)\\s*(?:=|\\s|>)/i', $attrs)
                    || ($tag === 'span' && preg_match('/\\bdata-lisun-term-id\\s*=\\s*["\'][^"\']+["\']/i', $attrs));
                $stack[] = ['tag' => $tag, 'skip' => (bool) $is_skip];
            } elseif (preg_match('/^<\\s*\\/\\s*([a-z][a-z0-9:-]*)/i', $part, $m)) {
                $tag = strtolower($m[1]);
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] === $tag) {
                        array_splice($stack, $i, 1);
                        break;
                    }
                }
            }
        }
        if (!empty($generated) || !empty($skipped)) {
            self::debug_log('glossary anchors post=' . (self::$progress_post_id ?: 0)
                . ' generated count=' . count($generated)
                . ' details=' . wp_json_encode($generated, JSON_UNESCAPED_UNICODE)
                . ' skipped=' . wp_json_encode($skipped, JSON_UNESCAPED_UNICODE));
        }
        return $out;
    }

    /**
     * 将带中文源锚点的完整 HTML 转成 DeepL XML 传输结构。
     *
     * DeepL 的 XML tag handling 对带属性的 inline <x> 标签有明确的结构
     * 保留语义；term_id 仅作为插件内部 ID 放在标签属性中，Glossary API
     * 仍只接收中文到英文的词条映射。
     */
    private static function prepare_deepl_xml_markup($html) {
        $xml = preg_replace_callback(
            '/<span\b([^>]*\bdata-lisun-term-id\s*=\s*(["\'])(term_[a-f0-9]{16,64})\2[^>]*)>([\s\S]*?)<\/span\s*>/i',
            function ($m) {
                return '<x id="' . esc_attr($m[3]) . '">' . $m[4] . '</x>';
            },
            (string) $html
        );
        if ($xml === null) return '<lisun-deepl-root>' . (string) $html . '</lisun-deepl-root>';

        // XML 不接受 HTML 的命名 nbsp 实体；其余已有 XML 实体保持不变。
        $xml = preg_replace('/&nbsp;/i', '&#160;', $xml);
        $void_tags = 'area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr';
        $xml = preg_replace_callback(
            '/<\s*(' . $void_tags . ')\b([^>]*?)(?:\/\s*)?>/i',
            function ($m) {
                $attrs = rtrim((string) ($m[2] ?? ''));
                $attrs = rtrim($attrs, '/');
                return '<' . strtolower($m[1]) . $attrs . ' />';
            },
            $xml
        );
        return '<lisun-deepl-root>' . $xml . '</lisun-deepl-root>';
    }

    /**
     * 计算 HTML 片段的可见文字，不使用 strip_tags() 的宽松误判行为。
     *
     * 旧说明书中可能出现孤立标签或正文里的尖括号。strip_tags() 会把
     * 某些这类文字一起吞掉，导致预处理完整性检查误报“插件丢失正文”。
     * 这里只移除可识别的 HTML 标签/注释，其余内容按正文保留。
     */
    private static function visible_html_text_for_integrity($html) {
        $tokens = preg_split(
            '/(<!--[\s\S]*?-->|<![^>]*>|<\s*\/?\s*[a-z][a-z0-9:-]*\b[^>]*>)/i',
            (string) $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($tokens)) {
            return trim(html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8'));
        }

        $visible = '';
        foreach ($tokens as $token) {
            $trimmed = trim((string) $token);
            if ($trimmed !== '' && (
                preg_match('/^<!--[\s\S]*-->$/', $trimmed)
                || preg_match('/^<![^>]*>$/i', $trimmed)
                || preg_match('/^<\s*\/?\s*[a-z][a-z0-9:-]*\b[^>]*>$/i', $trimmed)
            )) {
                continue;
            }
            $visible .= (string) $token;
        }

        return trim(html_entity_decode($visible, ENT_QUOTES, 'UTF-8'));
    }

    /** 删除历史 HTML 中没有对应开标签的行内闭合标签，避免 XML 请求解析失败。 */
    private static function remove_orphan_inline_closing_tags($html, $label = 'HTML') {
        $html = (string) $html;
        $tokens = preg_split(
            '/(<!--[\s\S]*?-->|<\s*script\b[^>]*>[\s\S]*?<\/\s*script\s*>|<\s*style\b[^>]*>[\s\S]*?<\/\s*style\s*>|<![^>]*>|<[^>]+>)/i',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($tokens)) return $html;

        $void_tags = [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ];
        $inline_tags = ['b', 'strong', 'em', 'i', 'u', 's', 'sub', 'sup', 'small', 'mark'];
        $stack = [];
        $removed = 0;
        $out = '';

        foreach ($tokens as $token) {
            $trimmed = trim((string) $token);
            if (preg_match('/^<\s*\/\s*([a-z][a-z0-9:-]*)\b[^>]*>$/i', $trimmed, $close)) {
                $tag = strtolower($close[1]);
                if (!empty($stack) && end($stack) === $tag) {
                    array_pop($stack);
                    $out .= $token;
                    continue;
                }
                if (in_array($tag, $inline_tags, true) && !in_array($tag, $stack, true)) {
                    $removed++;
                    self::debug_log('DeepL XML removed orphan inline close label=' . $label
                        . ' tag=</' . $tag . '>');
                    continue;
                }
                // 非行内或疑似错嵌套标签不擅自修复，交给严格结构校验报错。
                $out .= $token;
                continue;
            }

            if (preg_match('/^<\s*([a-z][a-z0-9:-]*)\b([^>]*)>$/i', $trimmed, $open)) {
                $tag = strtolower($open[1]);
                if (!in_array($tag, $void_tags, true) && !preg_match('/\/\s*>$/', $trimmed)) {
                    $stack[] = $tag;
                }
            }
            $out .= $token;
        }
        if ($removed > 0) {
            self::debug_log('DeepL XML orphan inline closes removed label=' . $label . ' count=' . $removed);
        }
        return $out;
    }

    /** 将 DeepL XML 根节点和术语 x 标签恢复为本地 HTML 结构。 */
    private static function restore_deepl_xml_markup($xml) {
        $xml = (string) $xml;
        if (!preg_match('/^\s*<lisun-deepl-root\b[^>]*>([\s\S]*)<\/lisun-deepl-root\s*>\s*$/i', $xml, $root)) {
            return new WP_Error('deepl_xml_root_missing', 'DeepL XML 返回缺少插件根标签，已停止保存和推送');
        }
        $html = $root[1];
        $html = preg_replace_callback(
            '/<x\b[^>]*\bid\s*=\s*(["\'])(term_[a-f0-9]{16,64})\1[^>]*>([\s\S]*?)<\/x\s*>/i',
            function ($m) {
                return '<span data-lisun-term-id="' . esc_attr($m[2]) . '">' . $m[3] . '</span>';
            },
            $html
        );
        if ($html === null) {
            return new WP_Error('deepl_xml_restore_failed', 'DeepL XML 术语标签恢复失败，已停止保存和推送');
        }
        return $html;
    }

    /**
     * 规范化锚点校验的源文格式。
     *
     * 正常情况下源文是中文 HTML + data-lisun-term-id span。为兼容混合部署
     * 或旧断点中误存的 DeepL XML 线稿，允许把插件自己的 XML 包装还原为
     * 本地 span；不接受任意 XML，也不从英文内容推断术语。
     */
    private static function normalize_glossary_anchor_source($source) {
        $source = (string) $source;
        if (preg_match('/data-lisun-term-id\s*=/i', $source)) {
            return $source;
        }
        if (preg_match('/<lisun-deepl-root\b/i', $source)
            && preg_match('/<x\b[^>]*\bid\s*=\s*["\']term_[a-f0-9]{16,64}["\']/i', $source)) {
            return self::restore_deepl_xml_markup($source);
        }
        return $source;
    }

    /**
     * 按 DeepL 返回的中文源锚点 ID 写入当前术语库英文。
     * 不扫描英文、不猜测英文位置；缺少术语映射时直接失败。
     */
    private static function apply_source_glossary_anchor_translations($html) {
        $map = Lisun_Manual_Glossary::get_by_id();
        $missing = [];
        $count = 0;
        $result = preg_replace_callback(
            '/<span\b([^>]*\bdata-lisun-term-id\s*=\s*)(["\'])(term_[a-f0-9]{16,64})\2([^>]*)>([\s\S]*?)<\/span\s*>/i',
            function ($m) use (&$missing, &$count, $map) {
                $id = $m[3];
                if (!isset($map[$id]) || trim((string) ($map[$id]['en'] ?? '')) === '') {
                    $missing[] = $id;
                    return $m[0];
                }
                $count++;
                return '<span' . $m[1] . $m[2] . esc_attr($id) . $m[2] . $m[4] . '>'
                    . esc_html($map[$id]['en']) . '</span>';
            },
            (string) $html
        );
        if ($result === null) {
            return new WP_Error('glossary_anchor_translation_failed', '术语锚点本地译法写入失败，未保存和推送');
        }
        if (!empty($missing)) {
            return new WP_Error(
                'glossary_anchor_translation_failed',
                '术语锚点缺少本地译法：' . implode('、', array_unique($missing)) . '，未保存和推送'
            );
        }
        return ['text' => $result, 'count' => $count];
    }

    /**
     * 在 DeepL 已完成翻译后，按源文命中的术语把英文固定译法包成锚点。
     * 这一步只处理本地译文，不把任何 span/占位结构发送给 DeepL。
     */
    private static function add_translated_glossary_anchors($html, $source = '') {
        if (!Lisun_Manual_Glossary::is_enabled() || !is_string($html) || $html === '') {
            return $html;
        }
        $source = self::sanitize_debug_utf8((string) $source);
        $source_plain = wp_strip_all_tags($source);
        $terms = [];
        foreach (Lisun_Manual_Glossary::get_all() as $item) {
            if (empty($item['term_id']) || empty($item['zh']) || empty($item['en'])) continue;
            if (mb_strpos($source_plain, (string) $item['zh']) === false) continue;
            $terms[] = $item;
        }
        usort($terms, function ($a, $b) {
            return mb_strlen($b['en']) <=> mb_strlen($a['en']);
        });
        if (empty($terms)) return $html;

        $pattern_items = [];
        $expected_ids = [];
        foreach ($terms as $item) {
            $en = trim((string) $item['en']);
            $expected_ids[] = $item['term_id'];

            // Glossary 的固定译法仍是首选，但 DeepL 可能按英文语法返回
            // 复数/词尾变体。只允许有限、可解释的名词复数变体，避免
            // 使用模糊匹配把普通英文误标成术语。
            $variants = [preg_quote($en, '/')];
            if (preg_match('/^(.*?)([A-Za-z]+)$/', $en, $last_word)) {
                $prefix = $last_word[1];
                $word = $last_word[2];
                $lower_word = strtolower($word);
                if (preg_match('/[^aeiou]y$/i', $word)) {
                    $variants[] = preg_quote($prefix . substr($word, 0, -1), '/') . '(?:y|ies)';
                } elseif (preg_match('/(?:s|x|z|ch|sh)$/i', $word)) {
                    $variants[] = preg_quote($en, '/') . 'es';
                } elseif (!preg_match('/s$/i', $word)) {
                    $variants[] = preg_quote($en, '/') . 's';
                }
            }
            $variants = array_values(array_unique($variants));
            $pattern_items[] = [
                'item' => $item,
                'pattern' => '(?:' . implode('|', $variants) . ')',
            ];
        }
        if (empty($pattern_items)) return $html;

        $parts = preg_split('/(<!--[\\s\\S]*?-->|<![^>]*>|<[^>]+>)/', $html, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) return $html;
        // 每个术语占一个捕获组，回调可准确知道命中了哪条术语。
        $term_patterns = [];
        foreach ($pattern_items as $pattern_item) {
            $term_patterns[] = '(' . $pattern_item['pattern'] . ')';
        }
        $pattern = '/(?<![\\p{L}\\p{N}])(?:' . implode('|', $term_patterns) . ')(?![\\p{L}\\p{N}])/iu';
        $skip_tags = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];
        $stack = [];
        $generated = [];
        $matched_ids = [];
        $out = '';

        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($part[0] !== '<') {
                $skip = false;
                foreach ($stack as $entry) {
                    if (!empty($entry['skip'])) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $part = preg_replace_callback($pattern, function ($m) use ($pattern_items, &$generated, &$matched_ids) {
                        $item = null;
                        $matched_text = '';
                        foreach ($pattern_items as $idx => $pattern_item) {
                            $group = $idx + 1;
                            if (isset($m[$group]) && $m[$group] !== '') {
                                $item = $pattern_item['item'];
                                $matched_text = $m[$group];
                                break;
                            }
                        }
                        if (!$item) return $m[0];
                        $generated[] = [
                            'term_id' => $item['term_id'],
                            'zh' => $item['zh'],
                            'en' => $item['en'],
                            'matched' => $matched_text,
                        ];
                        $matched_ids[] = $item['term_id'];
                        return '<span data-lisun-term-id="' . esc_attr($item['term_id']) . '">' . $matched_text . '</span>';
                    }, $part);
                    if ($part === null) return $html;
                }
                $out .= $part;
                continue;
            }

            $out .= $part;
            if (preg_match('/^<\\s*([a-z][a-z0-9:-]*)\\b([^>]*)>/i', $part, $m)) {
                $tag = strtolower($m[1]);
                if (in_array($tag, ['br', 'hr', 'img', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'param', 'source', 'track', 'wbr'], true)
                    || preg_match('/\\/\\s*>$/', $part)) continue;
                $attrs = $m[2] ?? '';
                $skip = in_array($tag, $skip_tags, true)
                    || ($tag === 'span' && preg_match('/\\bdata-lisun-term-id\\s*=\\s*["\'][^"\']+["\']/i', $attrs));
                $stack[] = ['tag' => $tag, 'skip' => (bool) $skip];
            } elseif (preg_match('/^<\\s*\\/\\s*([a-z][a-z0-9:-]*)/i', $part, $m)) {
                $tag = strtolower($m[1]);
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] === $tag) {
                        array_splice($stack, $i, 1);
                        break;
                    }
                }
            }
        }

        if (!empty($generated)) {
            self::debug_log('glossary output anchors post=' . (self::$progress_post_id ?: 0)
                . ' generated count=' . count($generated)
                . ' details=' . wp_json_encode($generated, JSON_UNESCAPED_UNICODE));
        }
        $expected_counts = array_count_values($expected_ids);
        $matched_counts = array_count_values($matched_ids);
        $missing = [];
        foreach ($expected_counts as $id => $count) {
            $found = $matched_counts[$id] ?? 0;
            if ($found < $count) {
                $missing[] = ['term_id' => $id, 'missing' => $count - $found];
            }
        }
        if (!empty($missing)) {
            self::debug_log('glossary output anchors unmatched post=' . (self::$progress_post_id ?: 0)
                . ' missing=' . wp_json_encode($missing, JSON_UNESCAPED_UNICODE)
                . ' expected_count=' . count($expected_ids)
                . ' matched_count=' . count($matched_ids));
        }
        return $out;
    }

    /**
     * 在英文文本节点中生成安全锚点标记。
     *
     * 文本节点稍后会统一经过 htmlspecialchars()，因此这里不能直接写入
     * <span>，否则会被保存成 &lt;span&gt;。标记在节点恢复为 HTML 后再还原。
     */
    private static function mark_translated_glossary_anchors($text, $source = '') {
        $anchored = self::add_translated_glossary_anchors($text, $source);
        $marked = preg_replace_callback(
            '/<span\b[^>]*\bdata-lisun-term-id\s*=\s*(["\'])([^"\']+)\1[^>]*>([\s\S]*?)<\/span\s*>/i',
            function ($m) {
                return '__LISUN_TERM_OPEN_' . $m[2] . '__' . $m[3] . '__LISUN_TERM_CLOSE__';
            },
            (string) $anchored
        );
        return $marked === null ? (string) $text : $marked;
    }

    /** 将文本节点恢复后的安全锚点标记还原成真正的 HTML span。 */
    private static function restore_glossary_anchor_markers($html) {
        $restored = preg_replace_callback(
            // term_id 由术语表生成，格式固定为 term_ + 16~64 位十六进制字符。
            // 必须限制 ID 形态，否则贪婪匹配会把“__英文译文__LISUN_TERM_CLOSE”
            // 一并吞进 data-lisun-term-id。
            '/__LISUN_TERM_OPEN_(term_[a-f0-9]{16,64})__(.*?)__LISUN_TERM_CLOSE__/is',
            function ($m) {
                return '<span data-lisun-term-id="' . esc_attr($m[1]) . '">' . $m[2] . '</span>';
            },
            (string) $html
        );
        return $restored === null ? (string) $html : $restored;
    }

    /** 移除本地术语锚点，供远程公开 HTML 使用。 */
    public static function strip_glossary_anchors($html) {
        if (!is_string($html) || $html === '') return $html;
        $html = self::sanitize_debug_utf8($html);
        // 术语锚点只包裹纯文本；循环一次兼容异常嵌套的旧数据。
        for ($i = 0; $i < 5; $i++) {
            $old = $html;
            $cleaned = preg_replace(
                '/<span\\b[^>]*\\bdata-lisun-term-id\\s*=\\s*(?:"[^"]*"|\'[^\']*\')[^>]*>([\\s\\S]*?)<\\/span\\s*>/i',
                '$1',
                $html
            );
            if ($cleaned === null) break;
            $html = $cleaned;
            if ($html === $old) break;
        }
        return $html;
    }

    /**
     * 修复旧备份中被当作文本保存的 HTML 结构标签。
     *
     * 只还原已确认会被分段器误提取的闭合标签，不对普通 &lt; / &gt;
     * 文本做全量实体解码，避免误伤正文中的比较符号或示例代码。
     */
    private static function restore_escaped_html_structure($html) {
        if (!is_string($html) || $html === '') return $html;
        return preg_replace(
            '/&lt;\s*\/\s*(td|th|tr|tbody|thead|tfoot|table)\s*&gt;/i',
            '</$1>',
            $html
        );
    }

    /**
     * 校验翻译引擎是否完整保留了术语锚点。
     * 返回 true 或包含可直接展示的错误信息的 WP_Error。
     */
    private static function validate_glossary_anchors($source, $translated) {
        if (!self::term_anchors_enabled()) return true;
        $source = self::normalize_glossary_anchor_source($source);
        if (is_wp_error($source)) return $source;
        $get_ids = function ($value) {
            preg_match_all('/data-lisun-term-id\\s*=\\s*(["\'])([^"\']+)\\1/i', (string) $value, $m);
            return $m[2] ?? [];
        };
        $source_ids = $get_ids($source);
        $output_ids = $get_ids($translated);
        // 英文翻译可能因语序调整移动 inline 标签；锚点身份校验必须比较
        // ID 的多重集合，而不是 PHP 严格数组比较（后者还会比较出现顺序）。
        $source_counts = array_count_values($source_ids);
        $output_counts = array_count_values($output_ids);
        ksort($source_counts, SORT_STRING);
        ksort($output_counts, SORT_STRING);
        if (count($source_ids) !== count($output_ids) || $source_counts !== $output_counts) {
            $valid_ids = Lisun_Manual_Glossary::get_by_id();
            $missing = [];
            $extra = [];
            foreach ($source_counts as $id => $count) {
                $delta = $count - ($output_counts[$id] ?? 0);
                if ($delta > 0) {
                    $missing[] = [
                        'term_id' => $id,
                        'zh' => $valid_ids[$id]['zh'] ?? '',
                        'en' => $valid_ids[$id]['en'] ?? '',
                        'count' => $delta,
                    ];
                }
            }
            foreach ($output_counts as $id => $count) {
                $delta = $count - ($source_counts[$id] ?? 0);
                if ($delta > 0) {
                    $extra[] = [
                        'term_id' => $id,
                        'zh' => $valid_ids[$id]['zh'] ?? '',
                        'en' => $valid_ids[$id]['en'] ?? '',
                        'count' => $delta,
                    ];
                }
            }
            self::debug_log('glossary anchor validation failed: expected=' . wp_json_encode($source_ids)
                . ' output=' . wp_json_encode($output_ids)
                . ' missing=' . wp_json_encode($missing, JSON_UNESCAPED_UNICODE)
                . ' extra=' . wp_json_encode($extra, JSON_UNESCAPED_UNICODE));
            $detail = [];
            if (!empty($missing)) {
                $detail[] = '缺少：' . implode('、', array_map(function ($item) {
                    return ($item['zh'] ?: $item['term_id']) . ($item['en'] ? '（' . $item['en'] . '）' : '');
                }, $missing));
            }
            if (!empty($extra)) {
                $detail[] = '多出：' . implode('、', array_map(function ($item) {
                    return $item['term_id'];
                }, $extra));
            }
            return new WP_Error(
                'glossary_anchor_broken',
                '术语锚点数量不一致，已停止保存和推送。' . (!empty($detail) ? ' ' . implode('；', $detail) : ' 请查看 debug log')
            );
        }
        if (count($source_ids) === 0) return true;
        $valid_ids = Lisun_Manual_Glossary::get_by_id();
        foreach ($output_ids as $id) {
            if (!isset($valid_ids[$id])) {
                self::debug_log('glossary anchor validation failed: unknown term_id=' . $id);
                return new WP_Error('glossary_anchor_broken', '术语锚点 ID 被翻译引擎修改，需要重试');
            }
        }
        $open = preg_match_all('/<span\\b[^>]*\\bdata-lisun-term-id\\s*=\\s*["\'][^"\']+["\'][^>]*>/i', (string) $translated);
        $complete = preg_match_all('/<span\\b[^>]*\\bdata-lisun-term-id\\s*=\\s*["\'][^"\']+["\'][^>]*>[\\s\\S]*?<\\/span\\s*>/i', (string) $translated);
        if ($open !== $complete) {
            self::debug_log('glossary anchor validation failed: unclosed tags open=' . $open . ' complete=' . $complete);
            return new WP_Error('glossary_anchor_broken', '术语锚点标签未成对闭合，需要重试');
        }
        foreach ($output_ids as $id) {
            if (!preg_match('/<span\\b[^>]*\\bdata-lisun-term-id\\s*=\\s*["\']' . preg_quote($id, '/') . '["\'][^>]*>\\s*([\\s\\S]*?)\\s*<\\/span\\s*>/i', (string) $translated, $m)
                || trim(wp_strip_all_tags($m[1])) === ''
                || preg_match('/<span\\b/i', $m[1])) {
                self::debug_log('glossary anchor validation failed: empty/nested term_id=' . $id);
                return new WP_Error('glossary_anchor_broken', '术语锚点内部没有译文，需要重试');
            }
        }
        return true;
    }

    /**
     * 从中文源文快照恢复“旧锚点 ID -> 当前术语”的映射。
     * 只按中文源锚点内容匹配，不读取或猜测英文译文。
     */
    private static function build_reapply_anchor_recovery_map($source_html) {
        $source_html = (string) $source_html;
        if ($source_html === '') return [];
        $current_by_zh = [];
        foreach (Lisun_Manual_Glossary::get_all() as $item) {
            $zh = trim((string) ($item['zh'] ?? ''));
            if ($zh !== '' && !empty($item['term_id'])) $current_by_zh[$zh] = $item;
        }
        if (empty($current_by_zh)) return [];
        $recovery = [];
        preg_match_all(
            '/<span\b[^>]*\bdata-lisun-term-id\s*=\s*(["\'])(term_[a-f0-9]{16,64})\1[^>]*>([\s\S]*?)<\/span\s*>/i',
            $source_html,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $old_id = (string) ($match[2] ?? '');
            $zh = trim(html_entity_decode(wp_strip_all_tags((string) ($match[3] ?? '')), ENT_QUOTES, 'UTF-8'));
            if ($old_id !== '' && $zh !== '' && isset($current_by_zh[$zh])) {
                $recovery[$old_id] = $current_by_zh[$zh];
            }
        }
        return $recovery;
    }

    /** 按当前术语表固定译法替换锚点内容，不调用翻译 API。 */
    private static function replace_glossary_anchors($html, $recovery_map = [], $only_term_id = '') {
        $current_map = Lisun_Manual_Glossary::get_by_id();
        if ($only_term_id !== '') {
            $current_map = isset($current_map[$only_term_id]) ? [$only_term_id => $current_map[$only_term_id]] : [];
            $recovery_map = isset($recovery_map[$only_term_id]) ? [$only_term_id => $recovery_map[$only_term_id]] : [];
        }
        $map = array_merge((array) $recovery_map, $current_map);
        $stats = ['updated' => 0, 'orphaned' => 0, 'recovered' => 0, 'anchors' => 0, 'changed_terms' => []];
        $result = preg_replace_callback(
            '/<span\\b([^>]*\\bdata-lisun-term-id\\s*=\\s*)(["\'])([^"\']+)\\2([^>]*)>([\\s\\S]*?)<\\/span\\s*>/i',
            function ($m) use (&$stats, $map) {
                $stats['anchors']++;
                $id = $m[3];
                if (!isset($map[$id])) {
                    $stats['orphaned']++;
                    // 未知 ID 绝不能被当作“已删除术语”而剥离锚点。
                    // 保留完整 span，避免一次术语表不同步破坏整篇译文。
                    return $m[0];
                }
                if (!isset(Lisun_Manual_Glossary::get_by_id()[$id])) {
                    $stats['recovered']++;
                }
                $new_text = esc_html($map[$id]['en']);
                if ($m[5] !== $new_text) {
                    $stats['updated']++;
                    $stats['changed_terms'][] = [
                        'term_id' => $id,
                        'zh'      => $map[$id]['zh'] ?? '',
                        'from'    => wp_strip_all_tags($m[5]),
                        'to'      => $map[$id]['en'],
                    ];
                }
                return '<span' . $m[1] . $m[2] . esc_attr($id) . $m[2] . $m[4] . '>'
                    . $new_text . '</span>';
            },
            (string) $html
        );
        return ['text' => $result === null ? $html : $result, 'stats' => $stats];
    }

    /**
     * DOCX 提取器会丢弃 HTML span；用已翻译段落中的锚点按顺序补回本地 HTML。
     * 找不到时不猜测，保留原文，后续后台会提示需要重译/检查。
     */
    private static function inject_anchor_units($html, $units) {
        if (!self::term_anchors_enabled() || !is_string($html)) return $html;
        foreach ((array) $units as $unit) {
            if (!preg_match_all('/<span\\b[^>]*\\bdata-lisun-term-id\\s*=\\s*(["\'])([^"\']+)\\1[^>]*>([\\s\\S]*?)<\\/span\\s*>/i', (string) $unit, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $inner = trim(wp_strip_all_tags($match[3]));
                $inner = preg_replace('/【\\/?(?:b|i|u|sub|sup)】/u', '', $inner);
                if ($inner === '') continue;
                $whole = self::placeholders_to_html_static($match[0]);
                $pattern = '/' . preg_quote($inner, '/') . '/u';
                $updated = preg_replace($pattern, $whole, $html, 1, $count);
                if ($count > 0) $html = $updated;
            }
        }
        return $html;
    }

    /**
     * 翻译前：将中文文本中的术语库词条替换为占位符
     *
     * 这是确保术语翻译一致的核心机制。把"静电放电发生器"替换为 ⟨0⟩，
     * 模型无法改译它，翻译后再还原为 "ESD Simulator Guns"。
     *
     * 按术语长度降序处理，避免短术语在长术语中误匹配。
     *
     * @param string $chinese_text 中文源文本
     * @return array ['text' => string, 'map' => array] 带占位符的文本 + 占位符→英文映射
     */
    private static function protect_glossary_terms($chinese_text) {
        if (!Lisun_Manual_Glossary::is_enabled()) {
            return ['text' => $chinese_text, 'map' => []];
        }
        $glossary = Lisun_Manual_Glossary::get_all();
        if (empty($glossary)) {
            return ['text' => $chinese_text, 'map' => []];
        }

        // 诊断：每次翻译首次执行时，扫描整个术语库，把含脏字符（U+FFFD/U+202F）的词条记到 debug 日志
        static $glossary_scanned = false;
        if (!$glossary_scanned) {
            $glossary_scanned = true;
            foreach ($glossary as $item) {
                $gzh = trim((string) ($item['zh'] ?? ''));
                $gen = trim((string) ($item['en'] ?? ''));
                if ($gen !== '' && preg_match('/[\x{FFFD}\x{202F}]/u', $gen)) {
                    self::debug_log('CORRUPTED GLOSSARY: zh="' . $gzh . '" en="' . $gen . '"');
                }
            }
        }

        // 按术语长度降序，避免短术语破坏长术语（如 "积分球" 先于 "积分球光谱辐射计" 匹配）
        usort($glossary, function ($a, $b) {
            return mb_strlen($b['zh']) - mb_strlen($a['zh']);
        });

        $map = [];
        $counter = 0;

        foreach ($glossary as $item) {
            $zh = trim($item['zh']);
            $en = trim($item['en']);
            if (empty($zh) || empty($en)) continue;

            if (mb_strpos($chinese_text, $zh) !== false) {
                // 使用 Unicode 数学角括号 ⟨ ⟩ (U+27E8/U+27E9)，不会出现在正常文本中
                $placeholder = '⟨' . $counter . '⟩';
                $chinese_text = str_replace($zh, $placeholder, $chinese_text);
                $map[$placeholder] = $en;
                $counter++;
            }
        }

        return ['text' => $chinese_text, 'map' => $map];
    }

    /**
     * 翻译前：检测并保护字母数字型号（如 EMI-9KB, LSG-1800, GDJS-015B）
     *
     * LLM 容易在翻译过程中污染英文型号字符（如 EMI → EMl, O0O → 000）。
     * 此方法用占位符替换型号，翻译后再还原，从根本上杜绝字符错乱。
     *
     * @param string $text 源文本
     * @return array ['text' => string, 'map' => array]
     */
    private static function protect_model_numbers($text) {
        $map = [];
        $counter = 1000; // 从 1000 开始，避免与 glossary 占位符冲突

        // 匹配模式：字母数字型号（含连字符）
        // 如 EMI-9KB, LSG-1800, GDJS-015B, HP-300, YF-1000, PVC-50
        // 要求：至少包含一个字母和一个数字，长度 3-30 字符
        // 排除常见英文单词（长度 < 3 或纯字母组合 that look like 型号）
        if (preg_match_all('/\b([A-Z]{2,6}[-_]?\d{1,4}[A-Z]?\d*)\b/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $model = $m[1];
                // 跳过已保护的占位符
                if (strpos($model, '⟨') !== false) continue;
                // 跳过看起来像英文单词的（纯大写短词，但带数字 → 型号）
                if (!preg_match('/\d/', $model)) continue; // 没数字 → 可能是缩写

                $placeholder = '⟨' . $counter . '⟩';
                // 只替换整个型号，不替换部分片段
                $text = preg_replace('/' . preg_quote($model, '/') . '/', $placeholder, $text, 1);
                $map[$placeholder] = $model;
                $counter++;
            }
        }

        // 带圈/带括号序号是版式字符，不是可翻译的数字。否则翻译引擎
        // 可能把源文的 ⑩ 改写成 (10)、10. 等另一种视觉样式。
        // 使用单独的前缀，恢复时禁止“占位符粘连”空格修复，保证原字符原位返回。
        if (preg_match_all('/[\x{2460}-\x{2473}\x{2474}-\x{2487}\x{24EA}]/u', $text, $matches)) {
            foreach ($matches[0] as $marker) {
                $placeholder = '⟨SEQ' . count($map) . '⟩';
                $text = preg_replace('/' . preg_quote($marker, '/') . '/', $placeholder, $text, 1);
                $map[$placeholder] = $marker;
            }
        }

        return ['text' => $text, 'map' => $map];
    }

    /**
     * 翻译后：将占位符还原为英文术语
     *
     * 同时智能修复「占位符粘连」：LLM 常把占位符与相邻英文单词连写
     * （如 ⟨0⟩Gamma），或把两个相邻占位符连写（如 ⟨0⟩⟨1⟩），还原后会出现
     * goniophotometerGamma、IlluminanceTransmittance 这类粘连。
     * 此处检测占位符左右是否紧挨字母/数字或另一个占位符，是则补一个空格。
     *
     * @param string $translated_text 模型返回的译文
     * @param array  $map             占位符→英文术语的映射（由 protect_glossary_terms 生成，含术语与型号）
     * @return string 还原后的译文
     */
    private static function restore_glossary_terms($translated_text, $map) {
        if (empty($map)) {
            return $translated_text;
        }

        // 诊断：定位 U+FFFD/U+202F 脏字符是 DeepL 带来的，还是还原环节产生的
        $dirty_before = (bool) preg_match('/[\x{FFFD}\x{202F}]/u', $translated_text);
        if ($dirty_before) {
            // 找到第一个脏字符，展示其前后 50 字符上下文，便于看出是哪段文本被污染
            if (preg_match('/[\x{FFFD}\x{202F}]/u', $translated_text, $dm, PREG_OFFSET_CAPTURE)) {
                $byte = $dm[0][1];
                $char = mb_strlen(substr($translated_text, 0, $byte));
                $ctx  = mb_substr($translated_text, max(0, $char - 50), 100);
                self::debug_log('INPUT dirty context: ...' . str_replace("\n", ' ', $ctx) . '...');
            }
        }

        // 保护 URL/数据属性，避免下面的"粘连修复"给 URL 里的型号占位符加空格
        // （例如 href=".../LSG-1800A-manual.pdf" 里的 LSG-1800A 被还原时误加空格导致 404）
        $url_map = [];
        $translated_text = preg_replace_callback(
            '/\b(src|href|srcset|data-[a-z0-9_-]+)\s*=\s*(["\'])(.*?)\2/is',
            function ($m) use (&$url_map) {
                $key = '⟨⟨URL' . count($url_map) . '⟩⟩';
                $url_map[$key] = $m[3];
                return $m[1] . '=' . $m[2] . $key . $m[2];
            },
            $translated_text
        );

        foreach ($map as $placeholder => $en) {
            if (strpos($translated_text, $placeholder) === false) {
                continue; // 该占位符未出现，跳过（省一次正则）
            }

            // 序号必须原样恢复，不能套用术语/型号的粘连空格规则。
            if (strpos((string) $placeholder, '⟨SEQ') === 0) {
                $translated_text = str_replace($placeholder, $en, $translated_text);
                continue;
            }

            $q = preg_quote($placeholder, '/');
            // 分支顺序：两侧粘连 → 仅左粘连 → 仅右粘连 → 正常
            $translated_text = preg_replace_callback(
                '/([a-zA-Z0-9⟩])' . $q . '([a-zA-Z0-9⟨])|([a-zA-Z0-9⟩])' . $q . '(?![a-zA-Z0-9⟨])|(?<![a-zA-Z0-9⟩])' . $q . '([a-zA-Z0-9⟨])|' . $q . '/u',
                function ($m) use ($en) {
                    // 两侧都粘连：X⟨0⟩Y → X term Y
                    if (isset($m[1]) && $m[1] !== '' && isset($m[2]) && $m[2] !== '') {
                        return $m[1] . ' ' . $en . ' ' . $m[2];
                    }
                    // 仅左侧粘连：X⟨0⟩ → X term
                    if (isset($m[3]) && $m[3] !== '') {
                        return $m[3] . ' ' . $en;
                    }
                    // 仅右侧粘连：⟨0⟩Y → term Y
                    if (isset($m[4]) && $m[4] !== '') {
                        return $en . ' ' . $m[4];
                    }
                    // 正常：⟨0⟩ → term
                    return $en;
                },
                $translated_text
            );
        }

        // 还原 URL 属性：URL 内的占位符用普通 str_replace 还原（不加空格）
        if ($url_map) {
            foreach ($url_map as $key => $url) {
                foreach ($map as $placeholder => $en) {
                    $url = str_replace($placeholder, $en, $url);
                }
                $translated_text = str_replace($key, $url, $translated_text);
            }
        }

        // 诊断：输入本来干净、输出却变脏 → 是还原环节（正则/术语值）引入的
        if (!$dirty_before && preg_match('/[\x{FFFD}\x{202F}]/u', $translated_text)) {
            self::debug_log('restore_glossary_terms OUTPUT dirty (introduced here): ' . mb_substr($translated_text, 0, 120));
        }

        return $translated_text;
    }

    /**
     * 在源文本中查找实际出现的术语表词条
     *
     * @param string $chinese_text 中文源文本
     * @return array 匹配的术语条目列表
     */
    private static function find_relevant_glossary_terms($chinese_text) {
        if (!Lisun_Manual_Glossary::is_enabled()) {
            return [];
        }
        $glossary = Lisun_Manual_Glossary::get_all();
        $relevant = [];
        foreach ($glossary as $item) {
            if (mb_strpos($chinese_text, $item['zh']) !== false) {
                $relevant[] = $item;
            }
        }
        return $relevant;
    }

    /**
     * 保护内联格式化标签（<strong>, <em>），替换为 Unicode 占位符
     *
     * 核心目的：让格式化标签在 extract_text_nodes() 阶段不会被切分，
     * 使整个句子作为一个完整语义单元送给 LLM 翻译，避免出现
     * "积分球"、"必须"、"定期校准" 被拆成 3 个独立片段分别翻译的问题。
     *
     * <strong>必须</strong> → ⟨FMTB0⟩必须⟨FMT/B0⟩
     * <em>重要</em>       → ⟨FMTI0⟩重要⟨FMT/I0⟩
     *
     * ⟨ 和 ⟩ 是 Unicode 字符 U+27E8/U+27E9，不会被 extract_text_nodes()
     * 的正则 />([^<]+)</ 切分（因为不是 ASCII < >），从而保证整个句子
     * 作为一个翻译单元送给 LLM。
     *
     * @param string $html
     * @return array [string $html, array $map]  map 仅用于调试，还原时不需要
     */
    private static function protect_inline_formatting($html) {
        $map = [];
        $counter = 0;

        // 先处理 <em>（内层），支持 <strong><em>text</em></strong> 嵌套
        $html = preg_replace_callback(
            '/<em\b[^>]*>(.*?)<\/em>/is',
            function ($m) use (&$map, &$counter) {
                $id = $counter++;
                $map['FMTI' . $id] = '<em>';
                $map['FMT/I' . $id] = '</em>';
                return '⟨FMTI' . $id . '⟩' . $m[1] . '⟨FMT/I' . $id . '⟩';
            },
            $html
        );

        // 再处理 <strong>（外层）
        $html = preg_replace_callback(
            '/<strong\b[^>]*>(.*?)<\/strong>/is',
            function ($m) use (&$map, &$counter) {
                $id = $counter++;
                $map['FMTB' . $id] = '<strong>';
                $map['FMT/B' . $id] = '</strong>';
                return '⟨FMTB' . $id . '⟩' . $m[1] . '⟨FMT/B' . $id . '⟩';
            },
            $html
        );

        return [$html, $map];
    }

    /**
     * 还原内联格式化占位符 → HTML 标签
     *
     * LLM 输出可能在占位符前后插入空格（如 ⟨FMTB0⟩ must ⟨FMT/B0⟩），
     * 这里用正则去除周边的多余空格。
     *
     * @param string $html
     * @return string
     */
    private static function restore_inline_formatting($html) {
        // 还原 <strong> 标签（含前后空格清理）
        $html = preg_replace('/\s*⟨FMTB\d+⟩\s*/u', '<strong>', $html);
        $html = preg_replace('/\s*⟨FMT\/B\d+⟩\s*/u', '</strong>', $html);
        // 还原 <em> 标签
        $html = preg_replace('/\s*⟨FMTI\d+⟩\s*/u', '<em>', $html);
        $html = preg_replace('/\s*⟨FMT\/I\d+⟩\s*/u', '</em>', $html);
        return $html;
    }

    /**
     * 对翻译结果应用术语表后处理
     *
     * 修复两种典型问题：
     * 1. LLM 未翻译的中文术语 → 直接替换为英文术语
     * 2. LLM 翻译了但未使用术语表指定译法 → 尽可能检测并纠正
     */
    private static function apply_glossary($english_text) {
        if (!Lisun_Manual_Glossary::is_enabled()) {
            return $english_text;
        }
        $glossary = Lisun_Manual_Glossary::get_all();

        foreach ($glossary as $item) {
            $zh = $item['zh'];
            $en = $item['en'];

            // 情况1: LLM 在输出中保留了中文术语（直接替换）
            if (mb_strpos($english_text, $zh) !== false) {
                $english_text = str_replace($zh, $en, $english_text);
            }

            // 情况2: 模糊纠正已禁用（过于激进，误伤太多）
            // 术语统一主要依靠 protect_glossary_terms 的预翻译保护机制
        }

        return $english_text;
    }

    // ==================== HTML 清洗 ====================

    /**
     * 剥离 HTML 内联样式中的排版属性
     *
     * Word 导入的内容可能携带内联 font-size / font-family / line-height 等，
     * 这些属性会覆盖统一 CSS，导致字号不一、字体不一致。
     *
     * 此方法剥离排版相关属性，保留功能性属性（width, color, text-align,
     * border, background-color, vertical-align 等）。
     *
     * @param string $html 带内联样式的 HTML
     * @return string 清洗后的 HTML
     */
    public static function strip_inline_styles($html) {
        // 需要剥离的排版属性（不区分大小写）
        $strip_props = [
            'font-size',
            'font-family',
            'font-weight',
            'line-height',
            'letter-spacing',
            'word-spacing',
            'text-indent',
        ];

        $pattern_parts = array_map(function ($prop) {
            return preg_quote($prop, '/');
        }, $strip_props);
        $pattern = '/(;?\s*)(' . implode('|', $pattern_parts) . ')\s*:\s*[^;"\']+;?\s*/i';

        $html = preg_replace($pattern, '$1', $html);

        // 清洗后可能留下空的 style=""，清理掉
        $html = preg_replace('/\s+style\s*=\s*""\s*/i', ' ', $html);
        $html = preg_replace('/\s+style\s*=\s*\'\'\s*/i', ' ', $html);

        // 展开无意义的 <span> 壳（剥离样式后只剩空壳的 span 会截断中文词语）
        // 循环从内向外展开，确保嵌套 span 也被处理
        do {
            $before = $html;
            // span 无任何属性 → 直接展开
            $html = preg_replace('/<span\s*>\s*([^<]*?)\s*<\/span>/is', '$1', $html);
            // span 只有空的 style 属性 → 展开
            $html = preg_replace('/<span\s+style\s*=\s*""\s*>\s*([^<]*?)\s*<\/span>/is', '$1', $html);
        } while ($html !== $before);

        return $html;
    }

    /**
     * 解析 HTML 为 bundled simple_html_dom（对标 TranslatePress 的 str_get_html）。
     * 保留大小写、保留换行；解析失败返回 false（调用方回退正则）。
     */
    private static function shd_parse($html) {
        if (!function_exists('LisunManualHtmlDom\str_get_html')) {
            $lib = defined('LISUN_MANUAL_PATH')
                ? LISUN_MANUAL_PATH . 'includes/lib/simple-html-dom.php'
                : __DIR__ . '/lib/simple-html-dom.php';
            if (file_exists($lib)) {
                require_once $lib;
            }
        }
        if (!function_exists('LisunManualHtmlDom\str_get_html')) {
            return false;
        }
        // makeup() 读这个全局决定无引号属性的序列化引号；必须设成双引号。
        global $LISUN_HDOM_QUOTE_DEFAULT;
        $LISUN_HDOM_QUOTE_DEFAULT = '"';
        $html = (string) $html;
        if ($html === '') {
            return false;
        }
        // lower=false 保留原始大小写；stripRN=false 保留 <pre>/文本节点内的换行。
        return \LisunManualHtmlDom\str_get_html($html, false, true, 'UTF-8', false);
    }

    /**
     * 判断 simple_html_dom 节点是否位于 notranslate / data-no-translation /
     * data-no-auto-translation 祖先内（对标 TranslatePress 的 has_ancestor_attribute）。
     */
    private static function shd_skip_ancestor($node) {
        $n = $node;
        while ($n) {
            $tag = (string) $n->tag;
            if ($tag !== '' && $tag !== 'trptext' && $tag !== 'root') {
                $class = (string) $n->getAttribute('class');
                if ($class !== '' && stripos($class, 'notranslate') !== false) return true;
                if ($n->hasAttribute('data-no-translation')) return true;
                if ($n->hasAttribute('data-no-auto-translation')) return true;
            }
            $n = $n->parent();
        }
        return false;
    }

    private static function extract_text_nodes($html) {
        $map = [];
        $counter = 0;
        $dom = self::shd_parse($html);
        if ($dom === false) {
            return self::extract_text_nodes_regex($html);
        }

        $skip_tags = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];

        // 1) 文本节点：bundled fork 把每个文本节点包成 <trptext>。
        foreach ($dom->find('trptext') as $node) {
            $raw = $node->innertext;
            if ($raw === '' || trim($raw) === '') continue;
            // simple_html_dom exposes an unmatched closing tag in a fragment as
            // a synthetic text node. It is HTML structure, not translatable text.
            // If it is sent to DeepL and restored with htmlspecialchars(),
            // </td> becomes &lt;/td&gt; and corrupts the remote table markup.
            if (self::is_html_fragment_token($raw)) continue;
            $parent = $node->parent();
            if ($parent && in_array(strtolower((string) $parent->tag), $skip_tags, true)) continue;
            if (self::shd_skip_ancestor($node)) continue;
            // 实体解码后再交给翻译引擎（还原时 restore_text_nodes 会 htmlspecialchars 重新编码）
            $map[$counter] = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
            $node->innertext = '__TR' . $counter . '__';
            $counter++;
        }

        // 2) 可翻译属性值（对标 TranslatePress get_node_accessors）。
        //    只翻译包含中文的属性，绝不碰 src/srcset/href/data-* 等 URL/内部数据。
        $attr_selectors = [
            'img[alt]'                => 'alt',
            '[title]'                 => 'title',
            '[placeholder]'           => 'placeholder',
            '[aria-label]'            => 'aria-label',
            'input[type=submit]'      => 'value',
            'input[type=button]'      => 'value',
            'input[type=reset]'       => 'value',
            'meta[name=description]'  => 'content',
            'meta[name=keywords]'     => 'content',
        ];
        foreach ($attr_selectors as $selector => $attr) {
            foreach ($dom->find($selector) as $node) {
                $raw = (string) $node->getAttribute($attr);
                if ($raw === '' || trim($raw) === '') continue;
                if (self::shd_skip_ancestor($node)) continue;
                if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $raw)) continue; // 无中文不动
                $map[$counter] = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
                $node->setAttribute($attr, '__TR' . $counter . '__');
                $counter++;
            }
        }

        return [$dom->save(), $map];
    }

    /**
     * Detect a standalone HTML tag accidentally exposed as a text node by the
     * fragment parser. Only tag-shaped values are filtered; normal text that
     * contains angle brackets remains translatable.
     */
    private static function is_html_fragment_token($value) {
        $value = trim((string) $value);
        if ($value === '') return false;
        return preg_match('/^<\s*\/?\s*[a-z][a-z0-9:-]*(?:\s+[^>]*)?\s*\/?>$/i', $value) === 1;
    }

    /**
     * simple_html_dom 解析失败时的正则回退（保留旧行为）。
     */
    private static function extract_text_nodes_regex($html) {
        $map = [];
        $counter = 0;
        $skip = [];

        $html = preg_replace_callback(
            '~<(script|style|pre|code)\b[^>]*>.*?</\1\s*>|<([a-z][a-z0-9]*)\b[^>]*(?:class\s*=\s*"[^"]*notranslate[^"]*"|data-no-translation\b)[^>]*>.*?</\2\s*>~is',
            function ($m) use (&$skip) {
                $key = '<!--LISUNSKIP' . count($skip) . '-->';
                $skip[$key] = $m[0];
                return $key;
            },
            $html
        );

        $html = preg_replace_callback(
            '/>([^<]+)</',
            function ($matches) use (&$map, &$counter) {
                $text = $matches[1];
                if (trim($text) === '') return $matches[0];
                if (self::is_html_fragment_token($text)) return $matches[0];
                $id = $counter++;
                $map[$id] = $text;
                return '>__TR' . $id . '__<';
            },
            $html
        );

        if ($skip) {
            $html = str_replace(array_keys($skip), array_values($skip), $html);
        }

        return [$html, $map];
    }

    /**
     * 将翻译后的文本按 ID 填回 HTML 占位符位置
     */
    private static function restore_text_nodes($html, $map) {
        foreach ($map as $id => $text) {
            // 翻译结果中有 HTML 特殊字符的做转义
            $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $html = str_replace('__TR' . $id . '__', $safe, $html);
        }
        return $html;
    }

    // ==================== 后处理 ====================

    /**
     * 按中文源文修复 DeepL XML 返回的局部结构伪影。
     *
     * DeepL 负责翻译，插件只在能和源文逐单元格对齐时修复两类确定性问题：
     * 1. 源单元格没有标点，但返回英文单元格以逗号/冒号/分号开头；
     * 2. 图注中的 table 被 DeepL 留在 figcaption 内。
     * 对齐失败时保持原响应，不猜测、不全局改写。
     */
    public static function repair_deepl_structural_boundaries($source_html, $translated_html) {
        $translated_html = self::repair_table_cell_boundaries($source_html, $translated_html);
        return self::repair_figure_table_nesting($translated_html);
    }

    /**
     * 仅按同序 td/th 对比源文，移除 DeepL 在单元格开头额外生成的标点。
     */
    private static function repair_table_cell_boundaries($source_html, $translated_html) {
        preg_match_all('/<t[dh]\b[^>]*>.*?<\/t[dh]\s*>/isu', (string) $source_html, $source_matches, PREG_OFFSET_CAPTURE);
        preg_match_all('/<t[dh]\b[^>]*>.*?<\/t[dh]\s*>/isu', (string) $translated_html, $translated_matches, PREG_OFFSET_CAPTURE);
        $source_cells = $source_matches[0] ?? [];
        $translated_cells = $translated_matches[0] ?? [];
        if (!$source_cells || count($source_cells) !== count($translated_cells)) {
            return $translated_html;
        }

        $replacements = [];
        foreach ($translated_cells as $index => $translated_match) {
            $source_cell = (string) ($source_cells[$index][0] ?? '');
            $translated_cell = (string) ($translated_match[0] ?? '');
            if (!preg_match('/^<t[dh]\b[^>]*>(.*?)<\/t[dh]\s*>$/is', $source_cell, $source_inner_match)
                || !preg_match('/^<t[dh]\b[^>]*>(.*?)<\/t[dh]\s*>$/is', $translated_cell, $translated_inner_match)) {
                continue;
            }

            $source_inner = (string) $source_inner_match[1];
            $translated_inner = (string) $translated_inner_match[1];
            $source_plain = trim(html_entity_decode(wp_strip_all_tags($source_inner), ENT_QUOTES, 'UTF-8'));
            $translated_plain = trim(html_entity_decode(wp_strip_all_tags($translated_inner), ENT_QUOTES, 'UTF-8'));
            if ($source_plain === '' || $translated_plain === '') {
                continue;
            }

            $repaired_inner = $translated_inner;
            // 只有源文没有同类前导标点时才移除，避免改变原本合法的英文标点。
            if (!preg_match('/^[,;:：；，]/u', $source_plain)
                && preg_match('/^\s*[,;:]/u', $translated_plain)) {
                $repaired_inner = preg_replace(
                    '/^(\s*(?:(?:<[^>]+>)\s*)*)(?:[,;:]+\s*)+/u',
                    '$1',
                    $repaired_inner,
                    1
                );
            }

            // 中文“然后/接着/随后/之后/最后”位于表格步骤新单元格开头时，
            // DeepL 有时返回小写 then/next/finally；恢复块级句首大写。
            if (preg_match('/^(?:然后|接着|随后|之后|最后)/u', $source_plain)
                && preg_match('/^\s*(?:<[^>]+>\s*)*(then|next|after|finally)\b/i', $repaired_inner, $word_match)
                && strtolower((string) $word_match[1]) === (string) $word_match[1]) {
                $repaired_inner = preg_replace_callback(
                    '/^(\s*(?:(?:<[^>]+>)\s*)*)([a-z])/u',
                    function ($m) {
                        return $m[1] . strtoupper($m[2]);
                    },
                    $repaired_inner,
                    1
                );
            }

            if ($repaired_inner !== $translated_inner) {
                $open_tag = substr($translated_cell, 0, strpos($translated_cell, '>') + 1);
                $close_tag = preg_match('/<\/t[dh]\s*>$/i', $translated_cell, $close_match)
                    ? $close_match[0]
                    : '';
                $replacements[] = [
                    'start' => (int) $translated_match[1],
                    'length' => strlen($translated_cell),
                    'value' => $open_tag . $repaired_inner . $close_tag,
                ];
            }
        }

        // 按原字符串偏移量倒序替换，避免前面替换改变后续位置。
        for ($i = count($replacements) - 1; $i >= 0; $i--) {
            $replacement = $replacements[$i];
            $translated_html = substr_replace(
                $translated_html,
                $replacement['value'],
                $replacement['start'],
                $replacement['length']
            );
        }
        if ($replacements) {
            self::debug_log('DeepL structural repair table_cells=' . count($replacements)
                . ' post=' . (self::$progress_post_id ?: 0));
        }
        return $translated_html;
    }

    /**
     * 把错误落入 figcaption 的 table 移到 figure 外，并保留标准 Figure N 图注。
     */
    private static function repair_figure_table_nesting($content) {
        return preg_replace_callback(
            '/(<figure\b[^>]*>)(.*?)(<figcaption\b[^>]*>)(.*?)(<\/figcaption>)(.*?)(<\/figure>)/isu',
            function ($m) {
                if (stripos($m[4], '<table') === false
                    || !preg_match('/<table\b[\s\S]*?<\/table\s*>/isu', $m[4], $table_match, PREG_OFFSET_CAPTURE)) {
                    return $m[0];
                }
                $table_html = (string) $table_match[0][0];
                $table_offset = (int) $table_match[0][1];
                $caption_before_table = substr($m[4], 0, $table_offset);
                $caption_label = self::normalize_figure_caption_label($caption_before_table);
                if ($caption_label === '') {
                    return $m[0];
                }
                $caption_after_table = substr($m[4], $table_offset + strlen($table_html));
                $figure = $m[1] . $m[2]
                    . '<figcaption>' . esc_html($caption_label) . '</figcaption>'
                    . $m[6] . $m[7];
                return $figure . $table_html . $caption_after_table;
            },
            (string) $content
        );
    }

    /**
     * 将中文/英文图注统一成 Figure N；无法确认是图注时返回空字符串。
     */
    private static function normalize_figure_caption_label($caption) {
        $caption = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $caption)));
        if ($caption === '') {
            return '';
        }
        if (preg_match('/^(?:图|图片)\s*([0-9]+(?:[-.:][0-9]+)*)/iu', $caption, $match)) {
            return 'Figure ' . $match[1];
        }
        if (preg_match('/^(?:Figure|Figures|Fig\.?|Figs\.?|Image|Images)\s*([0-9]+(?:[-.:][0-9]+)*)/iu', $caption, $match)) {
            return 'Figure ' . $match[1];
        }
        return '';
    }

    /**
     * 翻译后清理：中文标点 → 英文标点
     */
    private static function clean_punctuation($text) {
        $punct_map = [
            '：' => ': ', '；' => '; ', '，' => ', ', '。' => '. ',
            '（' => ' (', '）' => ') ', '“' => '"', '”' => '"',
            '‘' => "'", '’' => "'", '—' => '-', '～' => '~', '…' => '...',
        ];
        return str_replace(array_keys($punct_map), array_values($punct_map), $text);
    }

    /**
     * 翻译后空格规范化
     *
     * LLM 从无空格的中文翻译到英文时，经常在以下位置漏掉空格：
     * - 英文单词和后续单词之间 (wordnext → word next)
     * - 标点符号后 (word,next → word, next)
     * - 括号前后 (word(text → word (text)
     *
     * 此方法作为后处理补丁修复这些问题，不依赖 LLM 的输出质量。
     */
    private static function normalize_spaces($text) {

        // ---- Step 2: 中英文交界处自动加空格 ----
        // 中文 + 英文/数字：确保至少一个空格
        $text = preg_replace('/([\x{4e00}-\x{9fff}])([a-zA-Z\d])/u', '$1 $2', $text);
        $text = preg_replace('/([a-zA-Z\d])([\x{4e00}-\x{9fff}])/u', '$1 $2', $text);

        // ---- Step 3: 标点后补空格 ----
        // 逗号后：,word → , word
        $text = preg_replace('/,([a-zA-Z\d])/', ', $1', $text);
        // 分号后：;word → ; word
        $text = preg_replace('/;([a-zA-Z\d])/', '; $1', $text);
        // 冒号后：:word → : word（跳过 URL schema 如 https://）
        $text = preg_replace('/(?<!http)(?<!https)(?<!ftp):([a-zA-Z])/', ': $1', $text);
        // 句号后：.Word → . Word（跳过小数点如 3.14、版本号如 v2.0、缩写如 U.S.）
        $text = preg_replace('/(?<![A-Z])\.([A-Z][a-z])/', '. $1', $text);
        // 右括号后紧跟字母：)word → ) word
        $text = preg_replace('/\)([a-zA-Z])/', ') $1', $text);

        // ---- Step 4: 去除多余空格 ----
        // 多个连续空格或 tab → 一个空格
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        // DeepL 常把小数、千位数字或英文所有格拆开：1. 050、10, 000、LISUN' s。
        // 仅针对明确的数字/所有格模式修复，避免影响普通句子中的空格。
        // 同时覆盖普通空格、不换行空格和窄不换行空格（DeepL 常返回 U+202F）。
        $num_space = '[\\s\\x{00A0}\\x{202F}]+';
        // 中文源文档通常不写千位分隔符，统一移除翻译引擎自动添加的逗号。
        $text = preg_replace('/(?<![\\d,])(\\d{1,3}),[\\s\\x{00A0}\\x{202F}]*(\\d{3})(?!\\d)/u', '$1$2', $text);
        $text = preg_replace('/(\d)\.'.$num_space.'(\d)/u', '$1.$2', $text);
        $text = preg_replace('/(\d),'.$num_space.'(\d{3})(?=\D|$)/u', '$1,$2', $text);
        $text = preg_replace('/'.$num_space.'([\'’])'.$num_space.'([sS])\b/u', '$1$2', $text);
        // 不要对每个 HTML 文本节点执行 trim()。
        // 锚点/span 会把一句英文拆成多个文本节点；节点边界上的空格
        // （例如 "; " 和 " ") 是英文词间空格，trim() 会把它们删掉，
        // 最终产生 "Material:Resolution"、"toStandard" 这类黏连。
        // 这里只清理换行本身两侧的缩进，保留水平空格作为 inline 标签边界。
        $text = implode("\n", array_map(static function ($line) {
            return preg_replace('/\n[ \t]+/u', "\n", (string) $line);
        }, explode("\n", $text)));

        // ---- Step 5: 标点前多余空格清理 + 恢复标点后空格 ----
        // "word ." → "word."，但 "word . Next" → "word. Next"
        $text = preg_replace('/\s+([,.;:!?])(\s+|$)/', '$1 ', $text);
        // 确保标点后紧跟字母时有空格
        $text = preg_replace('/([,.;:!?])([a-zA-Z\d])/', '$1 $2', $text);

        // ---- Step 6: HTML 块元素闭合标签后补空格 ----
        // </p>word → </p> word
        $text = preg_replace('/(<\/td>|<\/th>|<\/li>|<\/p>|<\/figcaption>|<\/h[1-6]>)([a-zA-Z])/i', '$1 $2', $text);

        // ---- Step 7: 术语表粘连单词修复 ----
        // LLM 有时会将多词英文术语合并（如 "integratingsphere"），用术语表检测并拆分
        if (Lisun_Manual_Glossary::is_enabled()) {
            $text = self::fix_concatenated_glossary_terms($text);
        }

        return $text;
    }

    /**
     * 用术语表检测粘连的英文单词并拆分
     *
     * 当 LLM 输出了 "integratingsphere" 而非 "integrating sphere" 时，
     * 用术语表中的多词英文术语匹配粘连版本并修复。
     *
     * @param string $text 英文文本
     * @return string 修复后的文本
     */
    private static function fix_concatenated_glossary_terms($text) {
        if (!Lisun_Manual_Glossary::is_enabled()) {
            return $text;
        }
        $glossary = Lisun_Manual_Glossary::get_all();
        $replacements = [];

        foreach ($glossary as $item) {
            $en = trim($item['en']);
            // 只处理包含空格的多词术语（单字术语不需要拆分）
            if (strpos($en, ' ') === false) continue;

            // 生成粘连版本：去掉所有空格并转小写
            $concatenated = strtolower(str_replace(' ', '', $en));

            // 跳过太短的（< 6 字符，误伤风险高）
            if (strlen($concatenated) < 6) continue;

            // 在文本中搜索粘连版本（大小写不敏感）
            if (stripos($text, $concatenated) !== false) {
                // 保留原文的大小写上下文：用粘连词周围字符判断
                // 保守策略：仅当粘连词作为独立"单词"出现时才替换
                //（前后非字母，避免拆分正常复合词）
                $pattern = '/(?<![a-zA-Z])(' . preg_quote($concatenated, '/') . ')(?![a-zA-Z])/i';
                if (preg_match($pattern, $text)) {
                    $replacements[$concatenated] = $en;
                }
            }
        }

        // 去重：按长度降序，避免短词干扰长词
        uksort($replacements, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($replacements as $concat => $proper) {
            $pattern = '/(?<![a-zA-Z])' . preg_quote($concat, '/') . '(?![a-zA-Z])/i';
            $text = preg_replace($pattern, $proper, $text);
        }

        return $text;
    }

    /**
     * 翻译后大小写规范化
     *
     * LLM 翻译时常出现的大小写问题：
     * - 标题中随意大写 (This Is A Title → This is a Title)
     * - 技术名词全大写丢失
     * - 句号后首字母未大写
     *
     * 此方法做保守修复：不破坏已知的技术名词和型号。
     */
    private static function normalize_case($text) {
        // 保护已知的全大写模式（型号、单位缩写等）
        $protected = [];

        // 保护全大写缩写词（至少 2 个字母 → 可能是技术缩写）
        // 如 LED, USB, AC, DC, IP, EMC, EMI, LVD, IEC, ISO, CE, ROHS
        if (preg_match_all('/\b[A-Z]{2,6}\b/', $text, $m)) {
            foreach ($m[0] as $idx => $word) {
                $key = "⟨⟨PROTECT{$idx}⟩⟩";
                $protected[$key] = $word;
            }
            $text = str_replace(array_values($protected), array_keys($protected), $text);
        }

        // 修复：句号/问号/感叹号后的首字母大写
        $text = preg_replace_callback(
            '/([.!?]\s+)([a-z])/',
            function ($m) {
                return $m[1] . strtoupper($m[2]);
            },
            $text
        );

        // 还原保护的词汇
        $text = str_replace(array_keys($protected), array_values($protected), $text);

        // 注意：不做全局 Title Case 转换，会误伤技术名词
        // 不做小写化，保持 LLM 输出的原始大小写（除非明确错误）

        return $text;
    }

    /**
     * 标题使用英文标题式大小写：每个英文单词首字母大写。
     * 保留全大写缩写、型号和数字，正文不经过此规则。
     */
    private static function normalize_heading_case($text) {
        return preg_replace_callback('/[A-Za-z]+/', function ($m) {
            $word = $m[0];
            if (preg_match('/^[A-Z]{2,6}$/', $word) || preg_match('/\d/', $word)) {
                return $word;
            }
            return strtoupper($word[0]) . strtolower(substr($word, 1));
        }, $text);
    }

    /**
     * 翻译后综合处理：空格 + 大小写 + 标点
     *
     * 统一的翻译后处理入口，确保所有翻译路径（全量、增量、术语重刷）
     * 都经过一致的清洗流程。
     */
    private static function post_process_translation($text) {
        // 清理编码损坏：移除 U+FFFD 替换符，折叠异常连续窄不换行空格（U+202F）
        // 这类字符通常来自术语库中损坏的英文词条或 LLM 返回的脏字符，不应出现在成品里。
        $text = preg_replace('/\x{FFFD}+/u', '', $text);
        $text = preg_replace('/\x{202F}{3,}/u', ' ', $text);

        // 用 bundled simple_html_dom 只遍历文本节点做清理（对标 TranslatePress）：
        // 标点/空格/大小写只作用于文本，绝不碰 <a href>、<img src> 等属性。
        $dom = self::shd_parse($text);
        if ($dom === false) {
            // 解析失败回退：整段处理，URL 属性用占位保护兜底
            $url_map = [];
            $text = preg_replace_callback(
                '/\b(src|href|srcset|data-[a-z0-9_-]+)\s*=\s*(["\'])(.*?)\2/is',
                function ($m) use (&$url_map) {
                    $key = '⟨⟨URL' . count($url_map) . '⟩⟩';
                    $url_map[$key] = $m[3];
                    return $m[1] . '=' . $m[2] . $key . $m[2];
                },
                $text
            );
            $text = self::normalize_spaces($text);
            $text = self::normalize_case($text);
            $text = self::clean_punctuation($text);
            $text = self::normalize_spaces($text);
            if ($url_map) {
                $text = str_replace(array_keys($url_map), array_values($url_map), $text);
            }
            return $text;
        }

        $skip_tags = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];
        foreach ($dom->find('trptext') as $node) {
            $val = $node->innertext;
            if ($val === '' || trim($val) === '') continue;
            $parent = $node->parent();
            if ($parent && in_array(strtolower((string) $parent->tag), $skip_tags, true)) continue;
            if (self::shd_skip_ancestor($node)) continue;
            $val = self::clean_punctuation($val);
            $val = self::normalize_spaces($val);
            $val = self::normalize_case($val);
            // DeepL 与术语占位符拼接时，偶尔会把复数后缀拆成“sphere s”等形式。
            // 仅修复明确的专业术语，避免影响普通英文单词或 HTML 属性。
            $val = preg_replace('/\bIntegrating\s+sphere\s+s\b/iu', 'Integrating spheres', $val);
            $val = preg_replace('/\bIntegrating\s+sphere\s+es\b/iu', 'Integrating spheres', $val);
            // A term anchor is a child of <span>, so checking only its direct
            // parent misses heading context. Walk upward and apply the same
            // title-case rule to text inside any h1-h6 ancestor.
            $heading_ancestor = $node;
            $heading_tag = '';
            while ($heading_ancestor) {
                $candidate_tag = strtolower((string) $heading_ancestor->tag);
                if (in_array($candidate_tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                    $heading_tag = $candidate_tag;
                    break;
                }
                $heading_ancestor = $heading_ancestor->parent();
            }
            if ($heading_tag !== '') {
                $val = self::normalize_heading_case($val);
            }
            $node->innertext = $val;
        }

        return $dom->save();
    }

    /**
     * 诊断数字格式在翻译链路中的变化，只记录命中片段，不记录整篇正文。
     */
    public static function debug_numeric_format($stage, $html, $post_id = 0) {
        if (!preg_match_all('/\d{1,4}(?:[,.][\s\x{00A0}\x{202F}]*)?\d{3}\s*(?:nm|ms|m|W|V|A)\b/iu', (string) $html, $matches)) {
            error_log('LISUN numeric trace [' . $stage . '] post=' . intval($post_id) . ' no suspicious numeric unit found');
            return;
        }
        $items = array_slice(array_values(array_unique($matches[0])), 0, 20);
        error_log('LISUN numeric trace [' . $stage . '] post=' . intval($post_id) . ': ' . implode(' | ', $items));
    }

    /**
     * 合并 Gutenberg 内容中图片和图注段落（兼容旧数据）
     */
    public static function merge_figure_captions_in_html($content) {
        // 先修复 DeepL 曾经把 table 放进 figcaption 的旧译文，避免后面的
        // 图注规范化把整张表格误当成图注文本。
        $content = self::repair_figure_table_nesting($content);
        // 把紧跟在 <figure>...</figure> 之后的图注 <p>图N</p> / <p>Figure N</p> 收进 figure 作为 <figcaption>，
        // 并清理翻译过程中可能产生的多余 </p>。
        $content = preg_replace_callback(
            '/(<figure\b[^>]*>)(.*?)(<\/figure>)\s*(?:<\/p>)?\s*<p\b[^>]*>(.*?)<\/p>/isu',
            function ($m) {
                // 已有 figcaption 则不重复处理
                if (stripos($m[2], '<figcaption') !== false) {
                    return $m[0];
                }
                $caption = trim(wp_strip_all_tags($m[4]));
                // 兼容图4-5、图片 4.5、Figure/Figures 4-5、Fig./Figs. 4:5、Image/Images 4-5 等写法。
                if (!preg_match('/^(?:(?:图|图片)\s*\d+|Figures?\s*\.?\s*\d+|Figs?\s*\.?\s*\d+|Images?\s*\.?\s*\d+)/iu', $caption)) {
                    return $m[0];
                }
                // 统一英文图注格式：始终使用单数 Figure，避免同一页面出现 Figure/Figures/Fig. 混用。
                $caption = preg_replace('/^(?:图|图片)\s*/iu', 'Figure ', $caption);
                $caption = preg_replace('/^(?:Figures?|Figs?\.?|Images?)\s*/iu', 'Figure ', $caption);
                return $m[1] . $m[2] . '<figcaption>' . esc_html($caption) . '</figcaption>' . $m[3];
            },
            $content
        );

        // 同时规范原本已经存在的 figcaption，确保整篇文章的图注风格一致。
        return preg_replace_callback(
            '/(<figcaption\b[^>]*>)(.*?)(<\/figcaption>)/isu',
            function ($m) {
                $caption = trim(wp_strip_all_tags($m[2]));
                if (!preg_match('/^(?:(?:图|图片)\s*\d+|Figures?\s*\.?\s*\d+|Figs?\s*\.?\s*\d+|Images?\s*\.?\s*\d+)/iu', $caption)) {
                    return $m[0];
                }
                $caption = preg_replace('/^(?:图|图片)\s*/iu', 'Figure ', $caption);
                $caption = preg_replace('/^(?:Figures?|Figs?\.?|Images?)\s*/iu', 'Figure ', $caption);
                return $m[1] . esc_html($caption) . $m[3];
            },
            $content
        );
    }

    /**
     * 单独翻译标题（调用 DeepSeek API）
     *
     * @param string $chinese_title 中文标题
     * @return string 英文标题，失败返回原中文
     */
    private static function translate_title($chinese_title) {
        $provider = get_option('lisun_llm_provider', 'deepseek');
        self::$last_anchored_title = '';
        $source_title = trim((string) $chinese_title);
        // 后台界面会隐藏这些后缀，但数据库中的 post_title 可能仍保留它们。
        // 翻译时使用与中文网站显示一致的标题，避免把“使用说明书”再次交给翻译引擎。
        $clean_source_title = preg_replace('/(?:操作使用说明书|使用说明书|操作使用说明)\s*$/u', '', $source_title);
        if ($clean_source_title !== '') {
            $source_title = trim($clean_source_title);
        }
        $chinese_title = $source_title;

        self::debug_log('title translation START provider=' . $provider
            . ' source="' . mb_substr($source_title, 0, 200) . '"');

        // DeepL 标题翻译
        if ($provider === 'deepl' && !empty(get_option('lisun_deepseek_key', ''))) {
            // post_title 是纯文本字段：只走纯文本翻译，绝不生成或保存 HTML 锚点。
            $result = self::call_deepl_api($chinese_title, 0, 1, false);
            if (!is_wp_error($result) && !empty($result['text'])) {
                $title_text = self::strip_glossary_anchors($result['text']);
                self::$last_anchored_title = $title_text;
                // 标题只取第一句（去掉多余标点）
                $title = preg_replace('/[\.。！!].*$/s', '', trim($title_text));
                self::debug_log('title translation OK provider=deepl output="' . mb_substr($title, 0, 200) . '" anchors=0');
                return $title ?: $chinese_title;
            }
            self::debug_log('title translation FAILED provider=deepl error="'
                . (is_wp_error($result) ? $result->get_error_message() : 'empty response') . '"');
            return $chinese_title;
        }

        $config = self::get_config();
        if (empty($config['api_key'])) {
            return $chinese_title;
        }

        $system_prompt = 'You are a technical translator for lighting/testing equipment manuals.
Translate the given Chinese title to English.
- Treat the input as the exact product/manual title from the Chinese website.
- Keep product model numbers unchanged.
- Do not add words that are not present in the Chinese title, especially "User Manual", "Operation Manual", "Instruction Manual", or "Specification".
- Do not turn a product name into a document-type title.
- Return ONLY the translated title, no quotes, no explanation';

        $body = [
            'model'       => $config['model'],
            'temperature' => 0.1,
            'max_tokens'  => 200,
            'messages'    => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => 'Translate this title to English: ' . $chinese_title],
            ],
        ];

        $response = wp_remote_post($config['api_url'], self::api_request_args($config, $body));

        if (is_wp_error($response)) {
            self::debug_log('title translation FAILED provider=' . $provider
                . ' error="' . $response->get_error_message() . '"');
            return $chinese_title;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_text = wp_remote_retrieve_body($response);
        $data = json_decode($body_text, true);
        if ($http_code < 200 || $http_code >= 300 || !is_array($data)) {
            $error_msg = $data['error']['message'] ?? ('HTTP ' . $http_code);
            self::debug_log('title translation FAILED provider=' . $provider
                . ' http=' . $http_code . ' error="' . mb_substr($error_msg, 0, 300) . '"');
            return $chinese_title;
        }

        $translated = trim($data['choices'][0]['message']['content'] ?? '');
        if ($translated === '') {
            self::debug_log('title translation FAILED provider=' . $provider
                . ' http=' . $http_code . ' error="empty choices content"');
            return $chinese_title;
        }

        self::debug_log('title translation OK provider=' . $provider
            . ' http=' . $http_code . ' output="' . mb_substr($translated, 0, 200) . '"');
        return $translated;
    }

    /**
     * 从 HTML 内容中移除第一个非空块级元素（用作标题后避免正文重复）
     */
    private static function strip_first_block($content) {
        // 去掉开头的空行
        $content = ltrim($content);
        // 匹配第一个块级 HTML 元素：<p>...</p>, <hN>...</hN>, <li>...</li>, <figure>...</figure>, <table>...</table>
        $tag_names = 'p|h[1-6]|li|figure|table';
        if (preg_match('/^\s*(<(' . $tag_names . ')\b[^>]*>)(.*?)(<\/\2\s*>)/s', $content, $m, PREG_OFFSET_CAPTURE)) {
            $end = $m[0][1] + strlen($m[0][0]);
            $after = substr($content, $end);
            $after = ltrim($after, "\r\n");
            return $after;
        }
        return $content;
    }

    private static function extract_title($content, $fallback = '') {
        // 提取第一个 h1-h6 文本
        if (preg_match('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/i', $content, $m)) {
            return trim(wp_strip_all_tags($m[1]));
        }
        // 提取第一个段落文本
        if (preg_match('/<(?:p|div|li)\b[^>]*>(.*?)<\/(?:p|div|li)>/is', $content, $m)) {
            $title = trim(wp_strip_all_tags($m[1]));
            if ($title) return $title;
        }
        // 纯文本第一行
        $plain = trim(wp_strip_all_tags($content));
        $lines = preg_split('/\r\n|\r|\n/', $plain);
        if (!empty($lines[0])) {
            return trim($lines[0]);
        }
        return $fallback;
    }

    /**
     * 在本地创建英文 manual 页面
     *
     * @param WP_Post $source_post 中文源页面
     * @param string  $english_gutenberg 翻译后的 HTML 内容
     * @return int|WP_Error 英文页面 ID
     */
    private static function create_local_en_page($source_post, $english_gutenberg, $en_title = '') {
        // 多种方式查找已存在的英文页面（防止重复创建）
        $existing_id = get_post_meta($source_post->ID, '_lisun_manual_target_id', true);
        $existing_id = ($existing_id && get_post($existing_id)) ? intval($existing_id) : 0;

        // 如果 meta 丢了，通过 source_id 反查
        if (!$existing_id) {
            $found = get_posts([
                'post_type'      => 'manual',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'   => '_lisun_manual_source_id',
                        'value' => $source_post->ID,
                    ],
                    [
                        'key'   => '_lisun_manual_lang',
                        'value' => 'en',
                    ],
                ],
            ]);
            if (!empty($found)) {
                $existing_id = $found[0]->ID;
                // 补回丢失的 meta
                update_post_meta($source_post->ID, '_lisun_manual_target_id', $existing_id);
            }
        }

        // 再通过标题 + slug 反查（最后的兜底）
        if (!$existing_id) {
            $slug = sanitize_title($source_post->post_title . '-en');
            $by_slug = get_page_by_path($slug, OBJECT, 'manual');
            if ($by_slug && get_post_meta($by_slug->ID, '_lisun_manual_lang', true) === 'en') {
                $existing_id = $by_slug->ID;
                update_post_meta($source_post->ID, '_lisun_manual_target_id', $existing_id);
            }
        }

        // 标题若未由调用方传入，则自行翻译
        if (empty($en_title)) {
            $en_title = self::translate_title($source_post->post_title);
        }
        // 标题翻译失败时保留中文网站标题，不能从正文第一行提取旧标题。
        if (empty($en_title)) {
            $en_title = $source_post->post_title;
        }

        // 生成英文 slug：直接使用型号
        $tags = wp_get_post_terms($source_post->ID, 'post_tag', ['fields' => 'names']);
        if (!empty($tags)) {
            $en_slug = sanitize_title($tags[0]);
        } else {
            $en_slug = sanitize_title($en_title);
        }

        $post_data = [
            'post_title'   => $en_title,
            'post_content' => $english_gutenberg,
            'post_status'  => 'draft',
            'post_type'    => 'manual',
            'post_name'    => $en_slug,
            'meta_input'   => [
                '_lisun_manual_lang'        => 'en',
                '_lisun_manual_source_id'   => $source_post->ID,
                '_lisun_manual_sync_status' => 'synced',
            ],
        ];

        if ($existing_id) {
            // 覆盖更新已有译文
            $post_data['ID'] = $existing_id;
            $post_data['post_name'] = get_post_field('post_name', $existing_id);
            $post_data['post_status'] = get_post_status($existing_id) ?: 'draft'; // 保持原状态
            unset($post_data['meta_input']); // update 时不能设 meta_input
            $result = wp_update_post($post_data, true);
            if (!is_wp_error($result)) {
                update_post_meta($result, '_lisun_manual_lang', 'en');
                update_post_meta($result, '_lisun_manual_source_id', $source_post->ID);
                update_post_meta($result, '_lisun_manual_sync_status', 'synced');
            }
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            global $wpdb;
            $db_error = isset($wpdb) ? trim((string) $wpdb->last_error) : '';
            self::debug_log('local English post database write failed post=' . (int) $source_post->ID
                . ' target_id=' . (int) $existing_id
                . ' operation=' . ($existing_id ? 'update' : 'insert')
                . ' title_chars=' . mb_strlen((string) $en_title)
                . ' content_bytes=' . strlen((string) $english_gutenberg)
                . ' utf8=' . (preg_match('//u', (string) $english_gutenberg) === 1 ? 'yes' : 'no')
                . ' wp_error="' . $result->get_error_message() . '"'
                . ' db_error="' . $db_error . '"');
            if ($db_error !== '') {
                return new WP_Error('local_post_db_update_failed', '本地英文文章写入数据库失败：' . $db_error);
            }
            return $result;
        }

        // 无论是新建还是更新，都保存中文源文章到本地英文备份的关联。
        // 缺少这个 meta 会导致后台找不到“本地备份 Post”，下一次翻译还会重复创建。
        update_post_meta($source_post->ID, '_lisun_manual_target_id', intval($result));

        // 复制特色图片
        $thumb_id = get_post_thumbnail_id($source_post->ID);
        if ($thumb_id) {
            set_post_thumbnail($result, $thumb_id);
        }

        // 翻译并同步 SEO meta 到英文页面（已有则跳过，强制重译时覆盖）
        $has_seo = get_post_meta($result, 'title', true) || get_post_meta($result, 'description', true);
        if (!$has_seo || self::$force_retranslate) {
            self::translate_seo_meta($source_post->ID, $result);
        }

        return $result;
    }

    /**
     * 生成英文 .docx 备份并附加到英文 manual 页面
     *
     * 调用 html_to_docx.py 将翻译后的 HTML 转为 .docx，
     * 上传到 WP 媒体库并关联到英文页面。
     *
     * @param int    $en_post_id  英文页面 ID
     * @param string $en_title    英文标题（用作文件名）
     * @param string $en_html     翻译后的 HTML 内容
     * @return int|false 附件 ID 或 false
     */
    private static function save_en_docx_backup($en_post_id, $en_title, $en_html) {
        $script = LISUN_MANUAL_PATH . 'html_to_docx.py';
        if (!file_exists($script)) {
            error_log('LISUN Manual: html_to_docx.py not found, skip docx backup.');
            return false;
        }

        $python = self::find_python_binary();
        if (!$python) {
            error_log('LISUN Manual: Python not available, skip docx backup.');
            return false;
        }

        $tmp_dir = LISUN_MANUAL_PATH . 'temp/';
        if (!wp_mkdir_p($tmp_dir)) {
            error_log('LISUN Manual: Failed to create temp dir for docx backup.');
            return false;
        }

        // 写入 HTML 到临时文件
        $html_file = $tmp_dir . 'en_backup_' . $en_post_id . '_' . uniqid() . '.html';
        $docx_file = $tmp_dir . 'en_backup_' . $en_post_id . '_' . uniqid() . '.docx';
        file_put_contents($html_file, $en_html);

        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' '
             . escapeshellarg($html_file) . ' ' . escapeshellarg($docx_file) . ' 2>&1';
        $output = @shell_exec($cmd);

        @unlink($html_file);

        if (!$output || strpos($output, 'OK:') !== 0) {
            error_log('LISUN Manual: html_to_docx failed: ' . ($output ?: 'no output'));
            if (file_exists($docx_file)) @unlink($docx_file);
            return false;
        }

        if (!file_exists($docx_file) || filesize($docx_file) < 1000) {
            error_log('LISUN Manual: docx backup file too small or missing.');
            return false;
        }

        // 上传到 WP 媒体库
        $safe_name = sanitize_file_name(sanitize_title($en_title) . '-en.docx');
        $upload = wp_upload_bits($safe_name, null, file_get_contents($docx_file));
        @unlink($docx_file);

        if (!empty($upload['error'])) {
            error_log('LISUN Manual: docx upload failed: ' . $upload['error']);
            return false;
        }

        $attachment = [
            'post_title'     => $en_title . ' (English .docx)',
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $attach_id = wp_insert_attachment($attachment, $upload['file'], $en_post_id);

        if (is_wp_error($attach_id)) {
            error_log('LISUN Manual: wp_insert_attachment failed: ' . $attach_id->get_error_message());
            return false;
        }

        // 生成缩略图/元数据
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // 保存引用
        update_post_meta($en_post_id, '_lisun_en_docx_attachment', $attach_id);

        error_log("LISUN Manual: docx backup saved, attachment ID={$attach_id} for EN post={$en_post_id}");
        return $attach_id;
    }

    /**
     * 查找可用的 Python 解释器
     */
    private static function find_python_binary() {
        static $cached = null;
        if ($cached !== null) return $cached;

        if (!function_exists('shell_exec')) {
            $cached = false;
            return false;
        }
        $candidates = ['python3', 'python'];
        foreach ($candidates as $py) {
            $test = @shell_exec(escapeshellcmd($py) . ' -c "print(1)" 2>&1');
            if (trim($test) === '1') {
                $cached = $py;
                return $py;
            }
        }
        $cached = false;
        return false;
    }

    /**
     * 翻译中文 SEO meta 并写入英文页面
     * 同时写入 Rank Math SEO 字段，供远程站直接使用
     *
     * @param int $zh_post_id 中文源页面 ID
     * @param int $en_post_id 英文页面 ID
     */
    private static function normalize_seo_brand($text) {
        // 官方英文品牌只使用 LISUN；禁止 Lishan/Li Shan/LISUN Group 等变体。
        $text = preg_replace('/\bLi[\s-]*Shan\b/i', 'LISUN', (string) $text);
        return preg_replace('/\bLISUN\s+Group\b/i', 'LISUN', $text);
    }

    public static function translate_seo_meta($zh_post_id, $en_post_id = null) {
        $provider = get_option('lisun_llm_provider', 'deepseek');
        self::$last_anchored_seo = [];
        $config = self::get_config();
        if (empty($config['api_key'])) return [];

        $seo_title       = get_post_meta($zh_post_id, 'title', true);
        $seo_description = get_post_meta($zh_post_id, 'description', true);
        $seo_keywords    = get_post_meta($zh_post_id, 'keywords', true);
        $product_no      = get_post_meta($zh_post_id, 'product-no', true);

        // 没有任何 SEO 数据则跳过
        if (empty($seo_title) && empty($seo_description) && empty($seo_keywords) && empty($product_no)) {
            return [];
        }

        // 产品型号直接复制（无需翻译）
        if (!empty($product_no) && $en_post_id) {
            update_post_meta($en_post_id, 'product-no', $product_no);
        }

        // 没有任何需要翻译的文本字段则跳过
        if (empty($seo_title) && empty($seo_description) && empty($seo_keywords)) {
            return [];
        }

        // DeepL 不支持结构化 JSON 输出 → 逐字段单独翻译（call_deepl_api 已支持纯文本）
        if ($provider === 'deepl') {
            $result = [];

            if (!empty($seo_title)) {
                $r = self::call_deepl_api($seo_title, 0, 1, false);
                if (is_wp_error($r)) return $r;
                if (!is_wp_error($r) && !empty($r['text'])) {
                    $translated = trim((string) $r['text']);
                    $result['title'] = self::normalize_seo_brand(sanitize_text_field(self::strip_glossary_anchors($translated)));
                    self::$last_anchored_seo['title'] = $result['title'];
                }
            }
            if (!empty($seo_description)) {
                $r = self::call_deepl_api($seo_description, 0, 1, false);
                if (is_wp_error($r)) return $r;
                if (!is_wp_error($r) && !empty($r['text'])) {
                    $translated = trim((string) $r['text']);
                    $result['description'] = self::normalize_seo_brand(sanitize_text_field(self::strip_glossary_anchors($translated)));
                    self::$last_anchored_seo['description'] = $result['description'];
                }
            }
            if (!empty($seo_keywords)) {
                // 关键词：按逗号/空格拆分逐条翻译，失败保留原文
                $kws = preg_split('/[,，、\s]+/u', $seo_keywords);
                $translated = [];
                foreach ($kws as $kw) {
                    $kw = trim($kw);
                    if ($kw === '') continue;
                    $r = self::call_deepl_api($kw, 0, 1, false);
                    if (is_wp_error($r)) return $r;
                    if (!is_wp_error($r) && !empty($r['text'])) {
                        $translated[] = self::normalize_seo_brand(trim(self::strip_glossary_anchors((string) $r['text'])));
                    } else {
                        $translated[] = $kw;
                    }
                }
                if (!empty($translated)) {
                    $result['keywords'] = sanitize_text_field(implode(', ', $translated));
                    self::$last_anchored_seo['keywords'] = $result['keywords'];
                }
            }

            // 写回 en_post_id（若提供）
            if ($en_post_id) {
                if (isset($result['title'])) {
                    update_post_meta($en_post_id, 'title', $result['title']);
                    update_post_meta($en_post_id, 'rank_math_title', $result['title']);
                }
                if (isset($result['description'])) {
                    update_post_meta($en_post_id, 'description', $result['description']);
                    update_post_meta($en_post_id, 'rank_math_description', $result['description']);
                }
                if (isset($result['keywords'])) {
                    update_post_meta($en_post_id, 'keywords', $result['keywords']);
                    update_post_meta($en_post_id, 'rank_math_focus_keyword', $result['keywords']);
                }
            }

            return $result;
        }

        // 一次 API 调用翻译所有三个字段
        // SEO 字段是纯文本 meta，不生成或保存 HTML 锚点。
        $seo_title_input = (string) $seo_title;
        $seo_description_input = (string) $seo_description;
        $seo_keywords_input = (string) $seo_keywords;
        $fields_text = '';
        if (!empty($seo_title))       $fields_text .= "SEO_TITLE: {$seo_title_input}\n";
        if (!empty($seo_description)) $fields_text .= "SEO_DESCRIPTION: {$seo_description_input}\n";
        if (!empty($seo_keywords))    $fields_text .= "SEO_KEYWORDS: {$seo_keywords_input}\n";

        $system = <<<EOT
You are a technical SEO translator for LISUN Group laboratory testing equipment.
Translate the given Chinese SEO fields to English.

RULES:
- Output ONLY a valid JSON object: {"title":"...","description":"...","keywords":"..."}
- Include ONLY fields that were provided in the input
- SEO title: ≤70 characters, keep product model numbers unchanged
- SEO description: ≤160 characters, informative technical English
- Keywords: translate each keyword to English, keep as comma-separated list
- Brand naming: use the official English brand name "LISUN" only
- Do not add the word "Group" after LISUN
- Never write "Lishan", "Li Shan", "LiShan", or "lishan"; do not romanize the Chinese brand 力汕
- Do NOT add markdown fences, quotes around JSON, or any explanation
EOT;

        $api_body = [
            'model'       => $config['model'],
            'temperature' => 0.1,
            'max_tokens'  => 300,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $fields_text],
            ],
        ];

        $response = wp_remote_post($config['api_url'], self::api_request_args($config, $api_body));

        if (is_wp_error($response)) {
            error_log('LISUN Manual SEO translate: HTTP error - ' . $response->get_error_message());
            // 重试一次
            $response = wp_remote_post($config['api_url'], self::api_request_args($config, $api_body));
            if (is_wp_error($response)) {
                error_log('LISUN Manual SEO translate: HTTP error on retry - ' . $response->get_error_message());
                return [];
            }
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $raw  = trim($data['choices'][0]['message']['content'] ?? '');

        // 健壮的 JSON 提取：先去掉 markdown fences，再提取 JSON 对象
        $raw  = trim(preg_replace('/```(?:json)?\s*|\s*```/', '', $raw));
        // 尝试用正则提取第一个完整 JSON 对象（处理 LLM 在 JSON 前后加说明文字的情况）
        if (preg_match('/\{[^{}]*"title"\s*:\s*"[^"]*"[^{}]*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $seo  = json_decode($raw, true);

        if (!$seo) {
            error_log('LISUN Manual SEO translate: JSON parse failed. Raw response: ' . substr($raw, 0, 300));
            // 重试一次：可能是 LLM 临时波动，再发一次请求
            error_log('LISUN Manual SEO translate: retrying API call...');
            $response = wp_remote_post($config['api_url'], self::api_request_args($config, $api_body));
            if (is_wp_error($response)) {
                error_log('LISUN Manual SEO translate: HTTP error on retry - ' . $response->get_error_message());
                return [];
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $raw  = trim($data['choices'][0]['message']['content'] ?? '');
            $raw  = trim(preg_replace('/```(?:json)?\s*|\s*```/', '', $raw));
            if (preg_match('/\{[^{}]*"title"\s*:\s*"[^"]*"[^{}]*\}/s', $raw, $m)) {
                $raw = $m[0];
            }
            $seo  = json_decode($raw, true);
            if (!$seo) {
                error_log('LISUN Manual SEO translate: JSON parse failed on retry. Raw: ' . substr($raw, 0, 300));
                return [];
            }
        }

        $result = [];
        if (!empty($seo['title'])) {
            $en_title = self::normalize_seo_brand(sanitize_text_field((string) $seo['title']));
            self::$last_anchored_seo['title'] = $en_title;
            $result['title'] = $en_title;
            if ($en_post_id) {
                update_post_meta($en_post_id, 'title', $en_title);
                update_post_meta($en_post_id, 'rank_math_title', $en_title);
            }
        }
        if (!empty($seo['description'])) {
            $en_desc = self::normalize_seo_brand(sanitize_text_field((string) $seo['description']));
            self::$last_anchored_seo['description'] = $en_desc;
            $result['description'] = $en_desc;
            if ($en_post_id) {
                update_post_meta($en_post_id, 'description', $en_desc);
                update_post_meta($en_post_id, 'rank_math_description', $en_desc);
            }
        }
        if (!empty($seo['keywords'])) {
            $en_kw = self::normalize_seo_brand(sanitize_text_field((string) $seo['keywords']));
            self::$last_anchored_seo['keywords'] = $en_kw;
            $result['keywords'] = $en_kw;
            if ($en_post_id) {
                update_post_meta($en_post_id, 'keywords', $en_kw);
                update_post_meta($en_post_id, 'rank_math_focus_keyword', $en_kw);
            }
        }

        return $result;
    }

    // ==================== 配置 ====================

    /**
     * 获取远程站连接配置
     */
    private static function get_en_site_config() {
        return [
            'url'      => get_option('lisun_en_site_url', ''),
            'username' => get_option('lisun_en_site_username', ''),
            'password' => get_option('lisun_en_site_password', ''),
        ];
    }

    /**
     * AJAX: 删除本地译文
     */
    public static function ajax_delete_translation() {
        self::check_nonce();

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => __('缺少页面 ID', 'lisun-manual')]);
        }

        $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);
        if ($target_id) {
            wp_delete_post($target_id, true); // 永久删除本地英文页面
        }

        delete_post_meta($post_id, '_lisun_manual_target_id');
        delete_post_meta($post_id, '_lisun_manual_sync_status');
        delete_post_meta($post_id, '_lisun_manual_last_sync');

        // 如果有远程站也尝试删除（传远程站 ID，非本地 ID）
        $remote_id = get_post_meta($post_id, '_lisun_manual_remote_id', true);
        if ($remote_id) {
            Lisun_Manual_Sync::delete_remote($remote_id, true);
            delete_post_meta($post_id, '_lisun_manual_remote_id');
        }

        wp_send_json_success(['message' => __('译文已删除', 'lisun-manual')]);
    }

    /**
     * AJAX: 后台翻译（fire-and-forget 调用，不依赖用户 session）
     *
     * 由 class-import.php 在导入完成后通过 HTTP 请求触发，
     * 确保翻译在独立的 FPM worker 中运行，避免被 request_terminate_timeout 杀死。
     */
    public static function ajax_bg_translate() {
        @set_time_limit(0);
        @ignore_user_abort(true);

        // 不检查用户 nonce — 使用 hash-based token 验证
        $post_id = intval($_POST['post_id'] ?? 0);
        $token   = sanitize_text_field($_POST['token'] ?? '');
        $job_id  = sanitize_text_field($_POST['job_id'] ?? '');

        if (!$post_id || empty($token)) {
            wp_send_json_error(['message' => 'Missing params']);
        }

        // 验证内部 token
        $expected = wp_hash('lisun_bg_translate_' . $post_id);
        if (!hash_equals($expected, $token)) {
            wp_send_json_error(['message' => 'Invalid token']);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'manual') {
            wp_send_json_error(['message' => 'Invalid post']);
        }

        // 如果提供了 job_id，更新 transient 表示开始翻译
        if (!empty($job_id)) {
            $current = get_transient("lisun_import_{$job_id}");
            if ($current) {
                set_transient("lisun_import_{$job_id}", array_merge($current, [
                    'translating' => true,
                ]), 600);
            }
        }

        // 并发限流：导入路径也受同一把锁约束，避免和批量翻译同时抢满 FPM。
        // 等待最多 300 秒（约等于一篇的正常翻译时长），通常很快能等到空位。
        if (!self::acquire_worker_slot(300)) {
            error_log('LISUN Manual: bg_translate concurrency full after wait for post ' . $post_id);
            wp_send_json_error(['message' => '翻译并发已满，请稍后重试']);
        }

        try {
            $result = self::translate_post($post_id);

            if (is_wp_error($result)) {
                if (!empty($job_id)) {
                    set_transient("lisun_import_{$job_id}", [
                        'status'       => 'done',
                        'post_id'      => $post_id,
                        'post_title'   => $post->post_title,
                        'post_url'     => get_edit_post_link($post_id, 'raw'),
                        'translating'  => false,
                        'error'        => $result->get_error_message(),
                        'finished_at'  => current_time('mysql'),
                    ], 600);
                }
                wp_send_json_error(['message' => $result->get_error_message()]);
            }

            if (!empty($job_id)) {
                set_transient("lisun_import_{$job_id}", [
                    'status'       => 'done',
                    'post_id'      => $post_id,
                    'post_title'   => $post->post_title,
                    'post_url'     => get_edit_post_link($post_id, 'raw'),
                    'en_title'     => $result['en_title'] ?? '',
                    'remote_id'    => $result['remote_id'] ?? 0,
                    'translating'  => false,
                    'finished_at'  => current_time('mysql'),
                ], 600);
            }

            wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('LISUN Manual: bg_translate exception: ' . $e->getMessage());
            if (!empty($job_id)) {
                set_transient("lisun_import_{$job_id}", [
                    'status'       => 'done',
                    'post_id'      => $post_id,
                    'post_title'   => $post->post_title,
                    'post_url'     => get_edit_post_link($post_id, 'raw'),
                    'translating'  => false,
                    'error'        => $e->getMessage(),
                    'finished_at'  => current_time('mysql'),
                ], 600);
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        } finally {
            self::release_worker_slot();
        }
    }

    /**
     * 语言代码 → 可读名称
     */
    public static function get_lang_name($code) {
        $map = [
            'zh' => 'Chinese',
            'en' => 'English',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'ru' => 'Russian',
        ];
        return $map[$code] ?? 'English';
    }

    /**
     * 获取当前配置的语言对
     */
    public static function get_language_pair() {
        return [
            'source' => get_option('lisun_translate_source_lang', 'zh'),
            'target' => get_option('lisun_translate_target_lang', 'en'),
        ];
    }

    /**
     * 清理孤立的 Gutenberg 注释片段
     *
     * 当日志翻译段在 <!-- wp:... --> 或 <!-- /wp:... --> 标记中间被截断时，
     * 会产生孤立的注释片段（如末尾的 <!-- /、多余的 -->、不完整的 <!-- wp:...）。
     * 此方法在组装后清理这些碎片，确保输出的 Gutenberg 块是完整的。
     *
     * @param string $text 翻译后的内容
     * @return string 清理后的内容
     */
    /**
     * 清理 LLM 翻译输出中的孤立 HTML 注释片段
     * （兼容旧文章的 Gutenberg 注释和新文章的普通注释）
     */
    private static function clean_gutenberg_comments($text) {
        // 1. 移除末尾孤立的 "<!-- ..." (没有配对的 -->)
        $last_comment_open = mb_strrpos($text, '<!--');
        if ($last_comment_open !== false) {
            $after = mb_substr($text, $last_comment_open);
            if (mb_strpos($after, '-->') === false) {
                $before = mb_substr($text, 0, $last_comment_open);
                $text = rtrim($before);
                if (!empty($after)) {
                    error_log('LISUN Manual: stripped orphaned comment fragment at end: ' . trim($after));
                }
            }
        }

        // 2. 多余的 --> (没有配对的 <!--)
        $open_count  = substr_count($text, '<!--');
        $close_count = substr_count($text, '-->');
        while ($close_count > $open_count) {
            $pos = mb_strrpos($text, '-->');
            if ($pos !== false) {
                $text = mb_substr($text, 0, $pos) . mb_substr($text, $pos + 3);
                $close_count--;
            } else {
                break;
            }
        }

        return $text;
    }

    /**
     * 自动闭合未闭合的结构化 HTML 标签
     *
     * 翻译过程中 LLM 可能丢弃闭合标签（如 </table>, </div> 等），
     * 导致浏览器将后续内容（包括页脚）解析为表格/容器的一部分。
     * 此方法追踪需要成对出现的块级标签，自动补全未闭合的标签。
     *
     * 追踪的标签：table, tr, td, th, thead, tbody, tfoot,
     *              div, ul, ol, li, figure, figcaption
     *
     * @param string $text 翻译后的 HTML 内容
     * @return string 补全闭合标签后的内容
     */
    private static function auto_close_html_tags($text) {
        // 需要成对出现的块级标签（未闭合会导致布局错乱）
        $track_tags = [
            'table', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot',
            'div', 'ul', 'ol', 'li', 'figure', 'figcaption',
        ];

        $stack = [];

        // 匹配所有开闭标签（排除自闭合标签如 <br/> <img ... />）
        if (preg_match_all('/<\/?([a-z][a-z0-9]*)\b[^>]*>/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $full_match) {
                $tag = strtolower($matches[1][$i][0]);
                if (!in_array($tag, $track_tags, true)) {
                    continue;
                }

                $is_close = (strpos($full_match[0], '</') === 0);

                if (!$is_close) {
                    // 跳过自闭合标签（<br/>, <img ... />, <hr/> 等）
                    if (preg_match('/\/\s*>$/', $full_match[0])) {
                        continue;
                    }
                    $stack[] = $tag;
                } else {
                    // 闭合标签：从栈中找到匹配的开标签并移除
                    // 从栈顶向下搜索（处理嵌套情况）
                    for ($j = count($stack) - 1; $j >= 0; $j--) {
                        if ($stack[$j] === $tag) {
                            array_splice($stack, $j, 1);
                            break;
                        }
                    }
                }
            }
        }

        // 如果栈中还有未闭合的标签，按逆序补全
        if (!empty($stack)) {
            $closing = '';
            while ($tag = array_pop($stack)) {
                $closing .= '</' . $tag . '>';
            }
            error_log('LISUN Manual: auto-closed unclosed HTML tags: ' . $closing);
            $text .= "\n" . $closing;
        }

        return $text;
    }
}
