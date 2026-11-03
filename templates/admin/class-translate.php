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

    private static $progress_post_id = 0;
    private static $force_retranslate = false;
    private static $current_job_id = 0;   // 当前队列任务 id，用于翻译期间心跳续租
    private static $last_heartbeat = 0;    // 心跳节流时间戳
    private static $worker_slot = null;    // 当前占用的并发槽位（advisory lock 名）

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
        @file_put_contents(rtrim($dir, '/\\') . '/lisun-debug.log', '[' . current_time('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
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
        return md5(self::strip_inline_styles($content));
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
        // 仅中文页面
        $lang = get_post_meta($post_id, '_lisun_manual_lang', true);
        if ($lang && $lang !== 'zh') return;

        // 计算当前内容哈希（与 translate_post 存哈希时使用同一基准）
        $current_hash = self::get_content_hash($post->post_content);
        $stored_hash  = get_post_meta($post_id, '_lisun_manual_content_hash', true);

        if ($stored_hash && $stored_hash === $current_hash) {
            return; // 内容未变
        }

        // 内容已变更 → 重置翻译状态
        $old_status = get_post_meta($post_id, '_lisun_manual_sync_status', true);
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
        // 否则手动编辑会被忽略，且每次都是 2000+ 段从头全量重译。
        delete_post_meta($post_id, '_lisun_manual_docx_path');

        // 保留旧备份用于增量翻译（内容变了但大部分段落可能没变）
        // 不清除备份；translate_post 会对比段 hash 只翻译变化的段
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
                'gemini'   => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
                'deepl'    => 'https://api.deepl.com/v2/translate',
            ];
            $api_url = $defaults[$provider] ?? 'https://api.deepseek.com/chat/completions';
        }

        // 模型默认值
        if (empty($model)) {
            $default_models = [
                'deepseek' => 'deepseek-chat',
                'gemini'   => 'gemini-2.5-flash',
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

        return [
            'headers' => $headers,
            'body'    => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE),
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
        return !empty($config['api_key']);
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
        $config = self::get_config();
        if (empty($config['api_key'])) return null;

        $temperature = $opts['temperature'] ?? $config['temperature'];
        $max_tokens  = $opts['max_tokens']  ?? $config['max_tokens'];
        $model       = $opts['model']       ?? $config['model'];

        $messages = [];
        if (!empty($system_prompt)) {
            $messages[] = ['role' => 'system', 'content' => $system_prompt];
        }
        $messages[] = ['role' => 'user', 'content' => $user_prompt];

        $api_body = [
            'model'       => $model,
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
            'messages'    => $messages,
        ];

        $response = wp_remote_post($config['api_url'], self::api_request_args($config, $api_body));

        if (is_wp_error($response)) {
            error_log('LISUN Manual call_llm: HTTP error - ' . $response->get_error_message());
            // 重试一次
            $response = wp_remote_post($config['api_url'], self::api_request_args($config, $api_body));
            if (is_wp_error($response)) {
                error_log('LISUN Manual call_llm: HTTP error on retry - ' . $response->get_error_message());
                return null;
            }
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $raw  = trim($data['choices'][0]['message']['content'] ?? '');

        if (empty($raw)) {
            $body_preview = substr(wp_remote_retrieve_body($response), 0, 500);
            error_log('LISUN Manual call_llm: empty or missing content. HTTP body preview: ' . $body_preview);
            return null;
        }

        return $raw;
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
        self::debug_log('save_translation_backup post=' . $zh_post_id . ' result=' . var_export($result, true));
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
    public static function reapply_glossary($zh_post_id) {
        $backup = self::get_translation_backup($zh_post_id);
        if (!$backup || empty($backup['en_content'])) {
            return new WP_Error('no_backup', __('没有找到备份数据，请先翻译', 'lisun-manual'));
        }

        $current_hash = md5(json_encode(Lisun_Manual_Glossary::get_all()));
        $old_hash = $backup['glossary_hash'] ?? '';

        // 术语表无变化则跳过
        if ($current_hash === $old_hash && !empty($old_hash)) {
            return ['changed' => false, 'message' => '术语表无变化，无需更新'];
        }

        // 对已有英文内容重新应用术语表后处理 + 标点清理 + 空格大小写规范化
        $en_content = self::apply_glossary($backup['en_content']);
        $en_content = self::post_process_translation($en_content);

        // 保存更新后的备份（保留段数据供增量翻译使用）
        self::save_translation_backup($zh_post_id, [
            'en_title'       => $backup['en_title'],
            'en_content'     => $en_content,
            'en_slug'        => $backup['en_slug'],
            'en_seo'         => $backup['en_seo'] ?? [],
            'tags'           => $backup['tags'] ?? [],
            'glossary_hash'  => $current_hash,
            'zh_segments'    => $backup['zh_segments'] ?? [],
            'en_parts'       => $backup['en_parts'] ?? [],
            'segment_hashes' => $backup['segment_hashes'] ?? [],
        ]);

        // 推送到远程站
        $en_seo = $backup['en_seo'] ?? [];
        $push_result = Lisun_Manual_Sync::push_translation(
            $zh_post_id, $backup['en_title'], $en_content, $backup['en_slug'], $en_seo
        );

        if (is_wp_error($push_result)) {
            update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'push_failed');
            return $push_result;
        }

        update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($zh_post_id, '_lisun_manual_last_sync', current_time('mysql'));

        return ['changed' => true, 'message' => '术语表已应用并推送'];
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

        $old_hashes   = $backup['segment_hashes'] ?? [];
        $old_en_parts = $backup['en_parts'] ?? [];
        $old_zh_segs  = $backup['zh_segments'] ?? [];
        $block_locks  = $backup['block_locks'] ?? [];

        if (empty($old_zh_segs) || empty($old_en_parts)) {
            error_log('LISUN Manual: incremental — backup has no segment data for post ' . $post_id);
            return false;
        }

        // 获取当前中文内容（与全量 translate_with_segmentation 保持一致：先 strip_inline_styles）
        $post = get_post($post_id);
        if (!$post) return false;
        $source_content = self::strip_inline_styles($post->post_content);

        // ★ 必须与全量翻译 translate_with_segmentation 用同一套拆分策略，
        // 否则增量对比的段哈希与备份里的段哈希粒度不一致，永远对不上 → 每次回退全量。
        //   L1: split_at_block_boundaries — 按 Gutenberg 块边界切分
        //   L2: split_at_html_headings — 按 h1-h6 标题切分
        //   L3: split_at_paragraphs — 按双换行切分
        $max_chars    = self::SEGMENT_MAX_CHARS;
        $new_segments = self::split_at_block_boundaries($source_content, $max_chars);

        if (count($new_segments) <= 1) {
            $new_segments = self::split_at_html_headings($source_content, $max_chars);
        }
        if (count($new_segments) <= 1) {
            $new_segments = self::split_at_paragraphs($source_content, $max_chars);
        }

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

        // 先预统计对齐结果（不翻译）：变更占比过高时直接回退全量，避免「先逐个翻译
        // 2000+ 段，再发现改太多而丢弃」的浪费。docx 导入的文章首次切到 HTML 增量时，
        // 备份里是 docx 段落哈希、与 HTML 块对不上，会 100% 判定为变更 → 这里提前回退。
        $pre_changed = 0;
        foreach ($ops as $op) {
            if ($op['action'] !== 'keep') $pre_changed++;
        }
        if (count($ops) > 0) {
            $pre_ratio = $pre_changed / count($ops);
            if ($pre_ratio > 0.6 && count($new_segments) > 3) {
                error_log('LISUN Manual: incremental — too many ops changed (' . $pre_changed . '/' . count($ops) . ' = ' . round($pre_ratio * 100) . '%), fallback to full before translating');
                return false;
            }
        }

        // 统计操作类型
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
                    'msg'  => "正在翻译新增段（共 " . count($new_segments) . " 段）",
                    'time' => time(),
                ], 300);

                @set_time_limit(600);
                $result = self::translate_with_overflow_protection($segment_zh, $out_idx, count($new_segments));

                if (is_wp_error($result)) {
                    error_log('LISUN Manual: incremental insert segment FAILED: ' . $result->get_error_message());
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
                    'msg'  => "正在翻译变更段（共 " . count($ops) . " 个操作，已变更 " . $replace_count . " 段）",
                    'time' => time(),
                ], 300);

                @set_time_limit(600);
                $result = self::translate_with_overflow_protection($segment_zh, $out_idx, count($new_segments));

                if (is_wp_error($result)) {
                    error_log('LISUN Manual: incremental replace segment FAILED: ' . $result->get_error_message());
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

        // 变更段数（循环后重新统计，含 keep→replace 的残留中文转换）
        $changed = $replace_count + $insert_count + $delete_count;

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
                $new_en_parts[$idx] = "\n<!-- ⚠ TRANSLATION FAILED — original Chinese below -->\n" . $src_seg . "\n<!-- /TRANSLATION FAILED -->\n";
            }
        }
        if ($rechecked > 0) {
            $assembled = implode("\n", $new_en_parts);
        }

        // 后处理
        $assembled = self::clean_gutenberg_comments($assembled);
        $assembled = self::auto_close_html_tags($assembled);
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

        // ★ 优先走 docx 管线：extractor → raw text → LLM → build_en_docx → extractor → HTML
        $docx_path = get_post_meta($post_id, '_lisun_manual_docx_path', true);
        self::debug_log('translate_post START post=' . $post_id
            . ' docx=' . (empty($docx_path) ? 'none' : ($docx_path . (file_exists($docx_path) ? '(exists)' : '(missing)'))));

        if (!empty($docx_path) && file_exists($docx_path)) {
            return self::translate_from_docx($post_id, $docx_path);
        }

        $start_time = microtime(true);
        $result = []; // 全量翻译结果，增量路径可能不设置

        // 1. 提取原文
        $source_content = $post->post_content;

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

        if ($incremental && !empty($incremental['content'])) {
            // 增量翻译成功：复用未改动的译文，只翻译变更段
            $translated_content = $incremental['content'];
            $prompt_tokens      = $incremental['prompt_tokens'] ?? 0;
            $completion_tokens  = $incremental['completion_tokens'] ?? 0;
            $from_cache         = $incremental['from_cache'] ?? false;

            // 标题：如果内容完全没变，复用备份标题；否则重新翻译
            if ($from_cache && !empty($backup['en_title'])) {
                $en_title = $backup['en_title'];
            } else {
                $en_title = self::translate_title($post->post_title);
                if (empty($en_title) || $en_title === $post->post_title) {
                    $en_title = self::extract_title($translated_content, $post->post_title);
                }
            }

            $tags   = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
            $en_slug = $post->post_name;

            // SEO：内容没变则复用备份，否则重新翻译
            if ($from_cache && !empty($backup['en_seo'])) {
                $en_seo = $backup['en_seo'];
            } else {
                $en_seo = self::translate_seo_meta($post_id);
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
                    'error_msg'     => $result->get_error_message(),
                ]);
                update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
                return $result;
            }

            $translated_content = $result['text'];
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

            // 8. 翻译标题 + 生成 slug
            $en_title = self::translate_title($post->post_title);
            if (empty($en_title) || $en_title === $post->post_title) {
                $en_title = self::extract_title($translated_content, $post->post_title);
            }
            $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
            $en_slug = $post->post_name;

            // 9. 翻译 SEO 元字段（title / description / keywords）
            $en_seo = self::translate_seo_meta($post_id);
        }

        // 7. TOC 和附录由远程站自行生成，仅推送翻译后的正文
        $push_content = $translated_content;

        // 7.5 在中文站本地创建英文 Post（备份，draft），供重试推送/预览使用
        $local_en_id = self::create_local_en_page($post, $translated_content, $en_title);

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

            self::save_translation_backup($post_id, [
                'en_title'       => $en_title,
                'en_content'     => $push_content,
                'en_slug'        => $en_slug,
                'en_seo'         => $en_seo,
                'tags'           => $tags ?: [],
                'glossary_hash'  => md5(json_encode(Lisun_Manual_Glossary::get_all())),
                'zh_segments'    => $zh_segments,
                'en_parts'       => $en_parts,
                'segment_hashes' => $segment_hashes,
                'block_locks'    => $new_block_locks,
            ]);
        } else {
            // 无变更，仅刷新备份时间戳以延长保留期
            $existing = self::get_translation_backup($post_id);
            if ($existing) {
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
                'error_msg'     => $push_result->get_error_message(),
            ]);

            $segments_count = !empty($zh_segments) ? count($zh_segments) : ($result['segments'] ?? 1);
            return [
                'duration'          => round($duration, 2),
                'prompt_tokens'     => $prompt_tokens,
                'completion_tokens' => $completion_tokens,
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
            'error_msg'     => '',
        ]);

        $segments_count = !empty($zh_segments) ? count($zh_segments) : ($result['segments'] ?? 1);
        return [
            'duration'          => round($duration, 2),
            'prompt_tokens'     => $prompt_tokens,
            'completion_tokens' => $completion_tokens,
            'segments'          => $segments_count,
            'verification'      => $verification,
            'pushed'            => true,
            'push_msg'          => '',
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
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `error_msg` VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_status` (`status`),
            INDEX `idx_post_id` (`post_id`)
        ) ENGINE=InnoDB {$charset}";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * 入队：将一个翻译任务加入队列
     *
     * @param int    $post_id
     * @param string $action  'translate' | 'retranslate'
     */
    private static function enqueue_job($post_id, $action = 'translate') {
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';

        // 确保表存在（插件更新后激活钩子不会重新执行）
        self::create_queue_table();

        // 避免重复入队（同一 post_id + action 且未完成）
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE post_id = %d AND action = %s AND status IN ('pending', 'processing') LIMIT 1",
            $post_id, $action
        ));
        if ($existing) return;

        $wpdb->insert($table, [
            'post_id'    => $post_id,
            'action'     => $action,
            'status'     => 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
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
    public static function process_next_job() {
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translate_queue';
        self::create_queue_table(); // 确保表存在

        // 并发限流：拿不到槽位则本次不翻译，pending 任务留待后续 worker
        if (!self::acquire_worker_slot()) {
            error_log('LISUN Manual: worker concurrency full, skipping this run');
            return null;
        }

        // 取最早的一个 pending 任务
        $job = $wpdb->get_row("SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        if (!$job) {
            self::release_worker_slot();
            return null;
        }

        // 记录当前任务 id，供心跳续租使用
        self::$current_job_id = intval($job->id);

        // 标记为处理中
        $wpdb->update($table, ['status' => 'processing', 'updated_at' => current_time('mysql')], ['id' => $job->id]);

        $post_id = intval($job->post_id);

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
            'step' => 'starting',
            'msg'  => '正在启动翻译...',
            'time' => time(),
        ], 600);

        @set_time_limit(600);
        @ignore_user_abort(true);

        try {
            $result = self::translate_post($post_id);

            if (is_wp_error($result)) {
                $wpdb->update($table, [
                    'status'     => 'failed',
                    'error_msg'  => $result->get_error_message(),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $job->id]);
                set_transient("lisun_trans_{$post_id}", [
                    'step' => 'error', 'msg' => $result->get_error_message(), 'time' => time(),
                ], 300);
                set_transient("lisun_trans_result_{$post_id}", ['error' => $result->get_error_message()], 300);
                return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'failed'];
            }

            $wpdb->update($table, [
                'status'     => 'done',
                'updated_at' => current_time('mysql'),
            ], ['id' => $job->id]);
            set_transient("lisun_trans_{$post_id}", [
                'step' => 'done', 'msg' => '翻译完成', 'time' => time(),
            ], 300);
            set_transient("lisun_trans_result_{$post_id}", $result, 300);
            return ['job_id' => $job->id, 'post_id' => $post_id, 'status' => 'done'];
        } catch (\Exception $e) {
            error_log('LISUN Manual worker error: ' . $e->getMessage());
            $wpdb->update($table, [
                'status'     => 'failed',
                'error_msg'  => $e->getMessage(),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job->id]);
            // 确保 post meta 也被更新，否则前端永远看不到失败状态
            update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
            set_transient("lisun_trans_{$post_id}", [
                'step' => 'error', 'msg' => $e->getMessage(), 'time' => time(),
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
    public static function process_queue_loop($max_jobs = 0, $max_seconds = 1500) {
        $started = time();
        $processed = 0;

        while (true) {
            if ($max_jobs > 0 && $processed >= $max_jobs) break;
            if (time() - $started >= $max_seconds) break;

            $result = self::process_next_job();
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
    private static function trigger_worker() {
        $worker_url = rest_url('lisun-manual/v1/translate/worker');
        $response = wp_remote_post($worker_url, [
            'body'      => wp_json_encode(['_wpnonce' => wp_create_nonce('wp_rest')]),
            'headers'   => ['Content-Type' => 'application/json'],
            'timeout'   => 5,       // 足够完成 TCP 握手 + 发送请求
            'blocking'  => false,   // 不等待响应，立即返回
            'sslverify' => false,   // 避免自签名证书导致 curl 静默失败
        ]);

        if (is_wp_error($response)) {
            error_log('LISUN Manual: trigger_worker failed — ' . $response->get_error_message());
        }
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
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }

        self::enqueue_job($post_id, 'translate');
        set_transient("lisun_trans_{$post_id}", [
            'step' => 'queued', 'msg' => '翻译已启动...', 'time' => time(),
        ], 600);

        self::trigger_worker();

        return new WP_REST_Response(['success' => true, 'data' => ['status' => 'queued']], 200);
    }

    /**
     * REST: 批量入队 — 将所有 status=pending 的中文页面加入翻译队列
     *
     * 用于批量翻译场景。入队后需通过 worker-loop 端点并行处理。
     */
    public static function rest_enqueue_all_pending($request) {
        $posts = Lisun_Manual_CPT::get_zh_pages('untranslated');
        $count = 0;
        foreach ($posts as $post) {
            self::enqueue_job($post->ID, 'translate');
            set_transient("lisun_trans_{$post->ID}", [
                'step' => 'queued', 'msg' => '批量翻译已入队...', 'time' => time(),
            ], 3600);
            $count++;
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['enqueued' => $count, 'message' => "已入队 {$count} 篇，请触发 Worker Loop 开始翻译"],
        ], 200);
    }

    /**
     * REST: 重译
     */
    public static function rest_retranslate($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }

        self::enqueue_job($post_id, 'retranslate');
        set_transient("lisun_trans_{$post_id}", [
            'step' => 'queued', 'msg' => '重译已启动...', 'time' => time(),
        ], 600);

        self::trigger_worker();

        return new WP_REST_Response(['success' => true, 'data' => ['status' => 'queued']], 200);
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
     * REST: 重试推送（从备份读取数据，直接推送，不重新翻译）
     */
    public static function rest_retry_push($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }

        $backup = self::get_translation_backup($post_id);
        if (!$backup) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '没有找到备份数据，请重新翻译']], 200);
        }

        $en_seo = $backup['en_seo'] ?? [];

        // 如果备份中没有 SEO 翻译（之前翻译失败），重新翻译 SEO
        if (empty($en_seo)) {
            $en_seo = self::translate_seo_meta($post_id);
            if (!empty($en_seo)) {
                error_log('LISUN Manual retry push: SEO re-translated for post ' . $post_id);
            }
        }

        $push_result = Lisun_Manual_Sync::push_translation(
            $post_id, $backup['en_title'], $backup['en_content'], $backup['en_slug'], $en_seo
        );

        if (is_wp_error($push_result)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => $push_result->get_error_message()]], 200);
        }

        // 推送成功，刷新备份时间戳（备份长期保留）
        // 同时保留段数据供后续增量翻译
        if ($backup) {
            self::save_translation_backup($post_id, [
                'en_title'       => $backup['en_title'],
                'en_content'     => $backup['en_content'],
                'en_slug'        => $backup['en_slug'],
                'en_seo'         => $en_seo,
                'tags'           => $backup['tags'] ?? [],
                'zh_segments'    => $backup['zh_segments'] ?? [],
                'en_parts'       => $backup['en_parts'] ?? [],
                'segment_hashes' => $backup['segment_hashes'] ?? [],
                'block_locks'    => $backup['block_locks'] ?? [],
            ]);
        }
        update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($post_id, '_lisun_manual_last_sync', current_time('mysql'));

        return new WP_REST_Response(['success' => true, 'data' => ['message' => '推送成功']], 200);
    }

    /**
     * REST: 重新应用术语表到已有译文（不调 LLM）
     */
    public static function rest_reapply_glossary($request) {
        $post_id = intval($request->get_param('post_id') ?? 0);
        if (!$post_id) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '缺少页面 ID']], 200);
        }

        $result = self::reapply_glossary($post_id);

        if (is_wp_error($result)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => $result->get_error_message()]], 200);
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
        self::process_next_job();

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
        $result = self::process_queue_loop($max_jobs, $max_seconds);

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
        if (!$progress) {
            return new WP_REST_Response(['active' => false], 200);
        }

        $response = ['active' => true, 'step' => $progress['step'], 'msg' => $progress['msg']];

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
            self::trigger_worker();
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
    private static function translate_one_segment($text, $index = 0, $total = 1) {
        self::heartbeat(); // 续租队列任务，防止长翻译被误判为卡死

        // DeepL 引擎（独立于 OpenAI 兼容接口）
        $provider = get_option('lisun_llm_provider', 'deepseek');
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
        if ($provider === 'deepl' && !empty(get_option('lisun_deepseek_key', ''))) {
            return self::call_deepl_api($text, $index, $total);
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
                return self::call_deepseek_api($text, $index, $total);
            }
            // 第二轮：DeepSeek 润色
            $r2 = self::polish_with_deepseek($text, $r1['text'], $index, $total);
            if (is_wp_error($r2)) {
                error_log('LISUN Manual: Polish failed, using Doubao raw — ' . $r2->get_error_message());
                return $r1;
            }
            // 合并 finish_reason：任一步 truncate 就上报 length，防止 Doubao 截断被润色掩盖
            $combined_reason = (($r1['finish_reason'] ?? 'stop') === 'length' || ($r2['finish_reason'] ?? 'stop') === 'length') ? 'length' : 'stop';
            return [
                'text'              => $r2['text'],
                'prompt_tokens'     => ($r1['prompt_tokens'] ?? 0) + ($r2['prompt_tokens'] ?? 0),
                'completion_tokens' => ($r1['completion_tokens'] ?? 0) + ($r2['completion_tokens'] ?? 0),
                'finish_reason'     => $combined_reason,
            ];
        }
        return self::call_deepseek_api($text, $index, $total);
    }

    private static function translate_with_segmentation($text) {
        $max_chars = self::SEGMENT_MAX_CHARS;
        $post_id = self::$progress_post_id ?? 0;

        // 短文档直接翻译
        if (mb_strlen($text) <= $max_chars) {
            if ($post_id) {
                set_transient("lisun_trans_{$post_id}", [
                    'step' => '1/1', 'msg' => '正在翻译（短文档）...', 'time' => time(),
                ], 300);
            }
            $result = self::translate_with_overflow_protection($text, 0, 1);
            if ($post_id) delete_transient("lisun_trans_{$post_id}");
            // 短文档也填充段数据，供增量翻译使用
            if (!is_wp_error($result)) {
                $result['zh_segments'] = [$text];
                $result['en_parts'] = [0 => $result['text']];
            }
            return $result;
        }

        // 长文档：按 Gutenberg 块边界拆分
        $segments = self::split_at_block_boundaries($text, $max_chars);

        if (count($segments) <= 1) {
            // 无 Gutenberg 块标记（纯 HTML / 原始富文本）→ 降级用 HTML 标题拆分
            $segments = self::split_at_html_headings($text, $max_chars);
        }

        // 标题拆分也失败（连 h1-h6 都没有）→ 段落拆分
        if (count($segments) <= 1) {
            $segments = self::split_at_paragraphs($text, $max_chars);
        }

        if (count($segments) <= 1) {
            // 最终兜底：溢写保护递归拆分
            if ($post_id) {
                set_transient("lisun_trans_{$post_id}", [
                    'step' => '1/1', 'msg' => '正在翻译...', 'time' => time(),
                ], 300);
            }
            $result = self::translate_with_overflow_protection($text, 0, 1);
            if ($post_id) delete_transient("lisun_trans_{$post_id}");
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
                // 溢出保护已内置兜底，这里只处理最终失败 → 保留原文并标记
                error_log('LISUN Manual: segment ' . ($i+1) . '/' . $total . ' FAILED: ' . $result->get_error_message());
                $translated_parts[$i] = "\n<!-- ⚠ TRANSLATION FAILED — original Chinese below -->\n" . $segments[$i] . "\n<!-- /TRANSLATION FAILED -->\n";
                continue;
            }

            $translated_parts[$i] = $result['text'];
            $total_prompt += $result['prompt_tokens'];
            $total_completion += $result['completion_tokens'];

            // finish_reason=length → 溢出保护未解决，强制对半拆分重试
            if (($result['finish_reason'] ?? 'stop') === 'length') {
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

        // 后处理：清理孤立的 Gutenberg 注释碎片（截断的 <!-- / ... 等）
        $assembled = self::clean_gutenberg_comments($assembled);
        // 后处理：自动闭合未闭合的 HTML 标签（防止表格吞噬页脚等）
        $assembled = self::auto_close_html_tags($assembled);

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

        $json_input = json_encode($text_array, JSON_UNESCAPED_UNICODE);

        $body = [
            'model'       => $model,
            'temperature' => 0.1,
            'max_tokens'  => 16384,
            'messages'    => [
                ['role' => 'system', 'content' => $system_doubao],
                ['role' => 'user', 'content' => $seg . $json_input],
            ],
        ];

        $response = wp_remote_post($api_url, [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body'    => json_encode($body),
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
        $zh_title = $zh_data['title'] ?? '';
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

        // ── 5. 英文 .docx 附加到中文 post（本地不创建英文 post，只存 docx + JSON）──
        self::attach_docx_to_post($post_id, $en_docx_file, $en_title);
        @unlink($en_docx_file);

        // ── 6. SEO + slug ──
        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $en_slug = !empty($tags) ? sanitize_title($tags[0]) : $post->post_name;
        $en_seo = self::translate_seo_meta($post_id);

        // ── 7. 推送到远程站 ──
        $push_result = Lisun_Manual_Sync::push_translation($post_id, $en_title, $en_content, $en_slug, $en_seo);

        // ── 8. 保存备份 ──
        $duration = microtime(true) - $start_time;
        self::save_translation_backup($post_id, [
            'en_title'       => $en_title,
            'en_content'     => $en_content,
            'en_slug'        => $en_slug,
            'en_seo'         => $en_seo,
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

        $tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']);
        $en_slug = !empty($tags) ? sanitize_title($tags[0]) : $post->post_name;
        $en_seo = self::translate_seo_meta($post_id);
        $push_result = Lisun_Manual_Sync::push_translation($post_id, $en_title, $en_content, $en_slug, $en_seo);

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
   Examples: ⟨B⟩text⟨/B⟩ → ⟨B⟩text⟨/B⟩, ⟨LINK0⟩text⟨/LINK⟩ stays as-is.';

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
    private static function call_deepl_api($text, $index = 0, $total = 1) {
        $api_key = get_option('lisun_deepseek_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_deepl_key', 'DeepL API 未配置（请在 API 密钥字段填写 DeepL Key）');
        }

        $is_pro     = get_option('lisun_deepl_pro', false);
        $formality  = get_option('lisun_deepl_formality', 'default');
        $api_url    = $is_pro
            ? 'https://api.deepl.com/v2/translate'
            : 'https://api-free.deepl.com/v2/translate';

        // 格式保护：<strong>/<em> → Unicode 占位符（DeepL 也需要合并句子）
        list($text, $fmt_map) = self::protect_inline_formatting($text);

        // 保存"未做术语/型号保护"的原始文本节点，用于 DeepL 返回脏字符时回退直译
        list(, $fallback_text_map) = self::extract_text_nodes($text);

        // 术语保护：中文术语 → 占位符
        $protection = self::protect_glossary_terms($text);
        $text = $protection['text'];
        $glossary_map = $protection['map'];

        // 型号保护
        $model_protection = self::protect_model_numbers($text);
        $text = $model_protection['text'];
        $glossary_map = array_merge($glossary_map, $model_protection['map']);

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

            $response = wp_remote_post($api_url, [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => json_encode($body),
                'timeout' => 60,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('deepl_http', $response->get_error_message());
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $body_text = wp_remote_retrieve_body($response);
            $data = json_decode($body_text, true);

            if ($http_code !== 200) {
                $error_msg = $data['message'] ?? ('HTTP ' . $http_code);
                error_log('LISUN Manual DeepL API error: ' . $error_msg);
                return new WP_Error('deepl_error', $error_msg);
            }

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
            $fb_resp = wp_remote_post($api_url, [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => json_encode($fb_body),
                'timeout' => 60,
            ]);
            if (is_wp_error($fb_resp)) continue;

            $fb_data = json_decode(wp_remote_retrieve_body($fb_resp), true);
            $fb_text = $fb_data['translations'][0]['text'] ?? '';
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
            $translated_map[$id] = $txt;
        }

        // 将翻译后的文本填回 HTML 占位符
        $translated_html = self::restore_text_nodes($placeholder_html, $translated_map);

        // 术语还原
        if (!empty($glossary_map)) {
            $translated_html = self::restore_glossary_terms($translated_html, $glossary_map);
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

        // 表格高度必须由内容自然撑开。编辑器/TinyMCE 可能会把计算后的
        // height 写入 table、tr、th、td 的 style 或 height 属性；如果保留，
        // 固定布局会把短内容强行拉伸成很高的行。只处理表格元素，避免误删
        // 图片等非表格元素自身需要的 height。
        $table_tags = 'table|thead|tbody|tfoot|tr|th|td';
        $html = preg_replace_callback(
            '/<(' . $table_tags . ')\b([^>]*)>/i',
            static function ($match) {
                $attrs = $match[2];

                // 同时兼容 height="24px"、height='24' 和无引号属性。
                $attrs = preg_replace(
                    '/\s+height\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
                    '',
                    $attrs
                );

                $attrs = preg_replace_callback(
                    '/(\s+style\s*=\s*)(["\'])(.*?)\2/is',
                    static function ($style_match) {
                        $style = preg_replace(
                            '/(^|;)\s*height\s*:\s*[^;]+;?/i',
                            '$1',
                            $style_match[3]
                        );
                        $style = trim(preg_replace('/;{2,}/', ';', $style));
                        $style = trim($style, " ;\t\r\n");

                        if ($style === '') {
                            return '';
                        }

                        return $style_match[1] . $style_match[2] . $style . $style_match[2];
                    },
                    $attrs
                );

                return '<' . $match[1] . $attrs . '>';
            },
            $html
        );

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
        // 行首尾空格清理
        $text = implode("\n", array_map('trim', explode("\n", $text)));

        // ---- Step 5: 标点前多余空格清理 + 恢复标点后空格 ----
        // "word ." → "word."，但 "word . Next" → "word. Next"
        $text = preg_replace('/\s+([,.;:!?])(\s+|$)/', '$1 ', $text);
        // 确保标点后紧跟字母/数字时有空格。
        // 注意：句点（.）不在此列 —— 否则会把章节号 1.1、小数 3.14、版本 v2.0、规格 6.1A
        // 全部拆成 "1. 1" / "6. 1A"。句点后跟字母的情况已由上面 Step 3 处理。
        $text = preg_replace('/([,;:!?])([a-zA-Z\d])/', '$1 $2', $text);

        // ---- Step 6: HTML 块元素闭合标签后补空格 ----
        // </p>word → </p> word
        $text = preg_replace('/(<\/td>|<\/th>|<\/li>|<\/p>|<\/figcaption>|<\/h[1-6]>)([a-zA-Z])/i', '$1 $2', $text);

        // ---- Step 7: 术语表粘连单词修复 ----
        // LLM 有时会将多词英文术语合并（如 "integratingsphere"），用术语表检测并拆分
        $text = self::fix_concatenated_glossary_terms($text);

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
            $node->innertext = $val;
        }

        return $dom->save();
    }

    /**
     * 合并 Gutenberg 内容中图片和图注段落（兼容旧数据）
     */
    public static function merge_figure_captions_in_html($content) {
        // 把紧跟在 <figure>...</figure> 之后的图注 <p>图N</p> / <p>Figure N</p> 收进 figure 作为 <figcaption>，
        // 并清理翻译过程中可能产生的多余 </p>。
        return preg_replace_callback(
            '/(<figure\b[^>]*>)(.*?)(<\/figure>)\s*(?:<\/p>)?\s*<p\b[^>]*>(.*?)<\/p>/isu',
            function ($m) {
                // 已有 figcaption 则不重复处理
                if (stripos($m[2], '<figcaption') !== false) {
                    return $m[0];
                }
                $caption = trim(wp_strip_all_tags($m[4]));
                // 只处理"图 N / 图片 N / Figure N / Fig N"这类图注，避免误吞正常段落
                if (!preg_match('/^(?:图|图片)\s*\d|^(?:Figure|Fig\.?)\s+\d/iu', $caption)) {
                    return $m[0];
                }
                return $m[1] . $m[2] . '<figcaption>' . esc_html($caption) . '</figcaption>' . $m[3];
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

        // DeepL 标题翻译
        if ($provider === 'deepl' && !empty(get_option('lisun_deepseek_key', ''))) {
            $result = self::call_deepl_api($chinese_title, 0, 1);
            if (!is_wp_error($result) && !empty($result['text'])) {
                // 标题只取第一句（去掉多余标点）
                $title = preg_replace('/[\.。！!].*$/s', '', trim($result['text']));
                return $title ?: $chinese_title;
            }
            return $chinese_title;
        }

        $config = self::get_config();
        if (empty($config['api_key'])) {
            return $chinese_title;
        }

        $system_prompt = 'You are a technical translator for lighting/testing equipment manuals.
Translate the given Chinese title to English.
- Keep product model numbers unchanged
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

        if (is_wp_error($response)) return $chinese_title;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $translated = trim($data['choices'][0]['message']['content'] ?? '');
        return $translated ?: $chinese_title;
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

        $title_from_content = false;
        // 标题若未由调用方传入，则自行翻译
        if (empty($en_title)) {
            $en_title = self::translate_title($source_post->post_title);
        }
        // 如果标题没有翻译成功，退而取译文第一行
        if (empty($en_title) || $en_title === $source_post->post_title) {
            $en_title = self::extract_title($english_gutenberg, $source_post->post_title);
            $title_from_content = true;
        }
        // 只有标题是从正文提取的才移除第一个 block，避免误删正文一级标题
        if ($title_from_content) {
            $english_gutenberg = self::strip_first_block($english_gutenberg);
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
            return $result;
        }

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
    public static function translate_seo_meta($zh_post_id, $en_post_id = null) {
        $provider = get_option('lisun_llm_provider', 'deepseek');
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
                $r = self::call_deepl_api($seo_title, 0, 1);
                if (!is_wp_error($r) && !empty($r['text'])) {
                    $result['title'] = sanitize_text_field(trim($r['text']));
                }
            }
            if (!empty($seo_description)) {
                $r = self::call_deepl_api($seo_description, 0, 1);
                if (!is_wp_error($r) && !empty($r['text'])) {
                    $result['description'] = sanitize_text_field(trim($r['text']));
                }
            }
            if (!empty($seo_keywords)) {
                // 关键词：按逗号/空格拆分逐条翻译，失败保留原文
                $kws = preg_split('/[,，、\s]+/u', $seo_keywords);
                $translated = [];
                foreach ($kws as $kw) {
                    $kw = trim($kw);
                    if ($kw === '') continue;
                    $r = self::call_deepl_api($kw, 0, 1);
                    if (!is_wp_error($r) && !empty($r['text'])) {
                        $translated[] = trim($r['text']);
                    } else {
                        $translated[] = $kw;
                    }
                }
                if (!empty($translated)) {
                    $result['keywords'] = sanitize_text_field(implode(', ', $translated));
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
        $fields_text = '';
        if (!empty($seo_title))       $fields_text .= "SEO_TITLE: {$seo_title}\n";
        if (!empty($seo_description)) $fields_text .= "SEO_DESCRIPTION: {$seo_description}\n";
        if (!empty($seo_keywords))    $fields_text .= "SEO_KEYWORDS: {$seo_keywords}\n";

        $system = <<<EOT
You are a technical SEO translator for LISUN Group laboratory testing equipment.
Translate the given Chinese SEO fields to English.

RULES:
- Output ONLY a valid JSON object: {"title":"...","description":"...","keywords":"..."}
- Include ONLY fields that were provided in the input
- SEO title: ≤70 characters, keep product model numbers unchanged
- SEO description: ≤160 characters, informative technical English
- Keywords: translate each keyword to English, keep as comma-separated list
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
            $en_title = sanitize_text_field($seo['title']);
            $result['title'] = $en_title;
            if ($en_post_id) {
                update_post_meta($en_post_id, 'title', $en_title);
                update_post_meta($en_post_id, 'rank_math_title', $en_title);
            }
        }
        if (!empty($seo['description'])) {
            $en_desc = sanitize_text_field($seo['description']);
            $result['description'] = $en_desc;
            if ($en_post_id) {
                update_post_meta($en_post_id, 'description', $en_desc);
                update_post_meta($en_post_id, 'rank_math_description', $en_desc);
            }
        }
        if (!empty($seo['keywords'])) {
            $en_kw = sanitize_text_field($seo['keywords']);
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
