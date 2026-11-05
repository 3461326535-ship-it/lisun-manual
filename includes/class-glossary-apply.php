<?php
/**
 * Term-centred review and background application for existing translations.
 * Saving a glossary entry never calls this class automatically: an admin must
 * review the affected documents and explicitly start a batch.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Glossary_Apply {

    const TABLE_VERSION = '2';
    private static $latest_cache = [];
    private static $list_state_cache = null;

    public static function init() {
        add_action('wp_ajax_lisun_glossary_impact_preview', [__CLASS__, 'ajax_preview']);
        add_action('wp_ajax_lisun_glossary_manual_unlock', [__CLASS__, 'ajax_manual_unlock']);
        add_action('wp_ajax_lisun_glossary_apply_start', [__CLASS__, 'ajax_start']);
        add_action('wp_ajax_lisun_glossary_apply_single', [__CLASS__, 'ajax_start_single']);
        add_action('wp_ajax_lisun_glossary_apply_status', [__CLASS__, 'ajax_status']);
        add_action('wp_ajax_lisun_glossary_apply_retry', [__CLASS__, 'ajax_retry']);
        add_action('wp_ajax_lisun_glossary_apply_undo', [__CLASS__, 'ajax_undo']);
        add_action('wp_ajax_lisun_glossary_apply_history', [__CLASS__, 'ajax_history']);
        add_action('wp_ajax_lisun_glossary_apply_post_history', [__CLASS__, 'ajax_post_history']);
        add_action('wp_ajax_lisun_glossary_retranslate_stale', [__CLASS__, 'ajax_retranslate_stale']);
        add_action('wp_ajax_lisun_glossary_retranslate_stale_status', [__CLASS__, 'ajax_retranslate_stale_status']);
        // 一次性回填：把历史「应用」成功的文章写回术语适用范围，详情页才默认勾选。
        add_action('admin_init', [__CLASS__, 'maybe_backfill_applied_scopes']);
        self::maybe_create_table();
    }

    public static function maybe_create_table() {
        if ((string) get_option('lisun_glossary_apply_schema_version', '') === self::TABLE_VERSION) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            batch_id VARCHAR(64) NOT NULL,
            term_id VARCHAR(80) NOT NULL,
            glossary_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            total_jobs INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_jobs INT UNSIGNED NOT NULL DEFAULT 0,
            details_json LONGTEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (batch_id),
            KEY term_id (term_id),
            KEY status (status)
        ) {$charset}");
        update_option('lisun_glossary_apply_schema_version', self::TABLE_VERSION, false);
    }

    /**
     * 一次性回填：把历史「应用」成功的 (term, 文章) 写回术语适用范围。
     *
     * 旧版「应用」动作只写入应用记录（queue / 批次表），没把被应用的文章记进
     * 该术语的「指定文章」适用范围，导致详情页把已应用术语显示成未勾选。此
     * 方法在后台首次加载时自动补一次，之后用 option 标记跳过，无需人工跑脚本。
     */
    public static function maybe_backfill_applied_scopes() {
        if (get_option('lisun_glossary_apply_scope_backfill_done')) return;
        if (!Lisun_Manual_Glossary::is_enabled()) return; // 术语表关闭时不写范围

        $pairs = self::applied_term_post_pairs();
        if (!empty($pairs)) {
            Lisun_Manual_Glossary::add_articles_to_term_scopes($pairs);
            error_log('LISUN Manual: backfilled ' . count($pairs) . ' applied term→article scopes');
        }
        // 没有历史记录也记完成标记，避免每个后台请求重复查表。
        update_option('lisun_glossary_apply_scope_backfill_done', 1, false);
    }

    /**
     * 从队列表取所有「应用」成功且已推送远程的 (term, 文章) 对。
     * 真实 term_id 在 payload 里，不受批次表 term_id 历史遗留为 "0" 影响。
     */
    private static function applied_term_post_pairs() {
        global $wpdb;
        $queue = $wpdb->prefix . 'lisun_translate_queue';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $queue));
        if (!$exists) return [];

        $rows = $wpdb->get_results(
            "SELECT post_id, payload, result_json FROM {$queue} WHERE action = 'glossary_apply' AND status = 'done'",
            ARRAY_A
        );
        $pairs = [];
        foreach ((array) $rows as $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $result = json_decode((string) ($row['result_json'] ?? ''), true);
            $tid = (string) ($payload['term_id'] ?? '');
            $pid = (int) $row['post_id'];
            if ($tid === '' || $pid <= 0) continue;
            // 本地英文已更新但未推送远程的不算成功应用，不写范围。
            if (empty($result['pushed'])) continue;
            $pairs[$tid . '|' . $pid] = ['term_id' => $tid, 'post_id' => $pid];
        }
        return array_values($pairs);
    }

    public static function ajax_preview() {
        self::authorize();
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        $preview = self::preview($term_id);
        if (is_wp_error($preview)) {
            wp_send_json_error(['message' => $preview->get_error_message()]);
        }
        wp_send_json_success($preview);
    }

    /** Temporarily unlock an already-applied term for a second, normal apply confirmation. */
    public static function ajax_manual_unlock() {
        self::authorize();
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        $expected_term_revision = max(0, intval($_POST['term_revision'] ?? 0));
        $phrase = sanitize_text_field($_POST['unlock_phrase'] ?? '');
        if (empty($_POST['unlock_confirmed']) || $phrase !== '解锁并重新应用') {
            wp_send_json_error(['message' => '人工解锁未确认：请输入“解锁并重新应用”并完成确认。']);
        }
        $preview = self::preview($term_id);
        if (is_wp_error($preview)) {
            wp_send_json_error(['message' => $preview->get_error_message()]);
        }
        if ($expected_term_revision !== (int) ($preview['term_revision'] ?? 0)) {
            wp_send_json_error(['message' => '术语库已变化，请重新查看影响范围后再解锁']);
        }
        if (($preview['application_state']['status'] ?? '') !== 'applied') {
            wp_send_json_error(['message' => '当前术语不是“已应用”状态，无需人工解锁']);
        }

        $unlocks = get_option('lisun_glossary_manual_unlocks', []);
        $unlocks = is_array($unlocks) ? $unlocks : [];
        $unlocks[$term_id] = [
            'revision' => (int) $preview['glossary_revision'],
            'user_id' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ];
        update_option('lisun_glossary_manual_unlocks', $unlocks, false);
        wp_send_json_success(['message' => '人工解锁成功，请再次确认后应用当前命中文章。']);
    }

    public static function ajax_start() {
        self::authorize();
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        $expected_term_revision = max(0, intval($_POST['term_revision'] ?? 0));
        $preview = self::preview($term_id);
        if (is_wp_error($preview)) {
            wp_send_json_error(['message' => $preview->get_error_message()]);
        }
        if ($expected_term_revision !== (int) ($preview['term_revision'] ?? 0)) {
            wp_send_json_error(['message' => '术语库已变化，请重新查看影响范围后再确认']);
        }

        $manual_unlock = self::has_manual_unlock($term_id, (int) ($preview['term_revision'] ?? 0));
        $batch_id = 'gapply_' . str_replace('-', '', wp_generate_uuid4());

        // 前端可勾选部分文章；未传 post_ids 时保持“全局应用全部待处理文章”。
        $requested_ids = [];
        if (isset($_POST['post_ids']) && is_array($_POST['post_ids'])) {
            foreach ($_POST['post_ids'] as $pid) {
                $pid = absint($pid);
                if ($pid) $requested_ids[$pid] = true;
            }
        }
        $is_selected = !empty($requested_ids);

        $jobs = array_values(array_filter($preview['candidates'], function ($item) use ($requested_ids, $is_selected) {
            if (($item['status'] ?? 'pending') === 'applied') return false;
            if (!in_array($item['mode'], ['direct', 'incremental', 'legacy'], true)) return false;
            // 选中模式下只入队客户端勾选且服务端仍安全的文章。
            if ($is_selected && empty($requested_ids[(int) ($item['post_id'] ?? 0)])) return false;
            return true;
        }));
        $inserted = self::insert_batch($batch_id, $preview, count($jobs), [
            'manual_unlock' => $manual_unlock,
            'scope' => $is_selected ? 'selected' : 'all',
            'processed_post_ids' => array_map('intval', array_column($jobs, 'post_id')),
        ]);
        if (is_wp_error($inserted)) {
            wp_send_json_error(['message' => $inserted->get_error_message()]);
        }
        if ($manual_unlock) self::clear_manual_unlock($term_id);

        $queued = 0;
        foreach ($jobs as $job) {
            $job_id = Lisun_Manual_Translate::enqueue_glossary_apply_job(
                (int) $job['post_id'], $batch_id, $term_id, (string) $job['mode'],
                (int) $preview['glossary_revision'],
                (int) ($preview['term_revision'] ?? 0)
            );
            if ($job_id) $queued++;
        }
        if ($queued === 0) {
            self::set_batch_status($batch_id, 'done');
        } else {
            Lisun_Manual_Translate::trigger_queue_worker();
        }

        wp_send_json_success([
            'batch_id' => $batch_id,
            'queued' => $queued,
            'skipped' => count($preview['skipped']),
            'scope' => $is_selected ? 'selected' : 'all',
            'message' => $queued
                ? ($manual_unlock
                    ? '已将人工解锁后的 ' . $queued . ' 篇文章加入重新应用队列'
                    : ($is_selected
                        ? '已将选中的 ' . $queued . ' 篇文章加入术语更新队列'
                        : '已将 ' . $queued . ' 篇文章加入术语更新队列'))
                : ($manual_unlock
                    ? '人工解锁已使用，但没有可安全重新应用的文章'
                    : ($is_selected
                        ? '选中的文章当前没有可安全更新的项，请重新查看影响范围'
                        : '没有可安全更新的文章，已保留预览结果')),
        ]);
    }

    /** Queue one selected article from the impact preview. */
    public static function ajax_start_single() {
        self::authorize();
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        $post_id = absint($_POST['post_id'] ?? 0);
        $expected_term_revision = max(0, intval($_POST['term_revision'] ?? 0));
        $preview = self::preview($term_id);
        if (is_wp_error($preview)) {
            wp_send_json_error(['message' => $preview->get_error_message()]);
        }
        if ($expected_term_revision !== (int) ($preview['term_revision'] ?? 0)) {
            wp_send_json_error(['message' => '术语库已变化，请重新查看影响范围后再试']);
        }

        $selected = null;
        foreach ((array) $preview['candidates'] as $candidate) {
            if ((int) ($candidate['post_id'] ?? 0) === $post_id) {
                $selected = $candidate;
                break;
            }
        }
        if (!$selected) {
            wp_send_json_error(['message' => '该文章当前没有可安全应用的术语命中，可能已变化或被锁定']);
        }
        if (($selected['status'] ?? 'pending') === 'applied') {
            wp_send_json_error(['message' => '该文章当前术语已经应用，无需重复处理']);
        }

        $batch_id = 'gapply_' . str_replace('-', '', wp_generate_uuid4());
        $single_preview = $preview;
        $single_preview['candidates'] = [$selected];
        $single_preview['skipped'] = [];
        $single_preview['scope'] = 'single';
        $single_preview['post_id'] = $post_id;
        $inserted = self::insert_batch($batch_id, $single_preview, 1, [
            'scope' => 'single',
            'post_id' => $post_id,
        ]);
        if (is_wp_error($inserted)) {
            wp_send_json_error(['message' => $inserted->get_error_message()]);
        }

        $job_id = Lisun_Manual_Translate::enqueue_glossary_apply_job(
            $post_id,
            $batch_id,
            $term_id,
            (string) ($selected['mode'] ?? 'direct'),
            (int) $preview['glossary_revision'],
            (int) ($preview['term_revision'] ?? 0)
        );
        if (!$job_id) {
            self::set_batch_status($batch_id, 'failed');
            wp_send_json_error(['message' => '单篇应用任务入队失败']);
        }
        Lisun_Manual_Translate::trigger_queue_worker();

        wp_send_json_success([
            'batch_id' => $batch_id,
            'queued' => 1,
            'message' => '已将《' . (string) ($selected['title'] ?? ('#' . $post_id)) . '》加入单篇应用队列',
        ]);
    }

    public static function ajax_status() {
        self::authorize();
        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        $status = self::get_batch_status($batch_id);
        if (is_wp_error($status)) {
            wp_send_json_error(['message' => $status->get_error_message()]);
        }
        // 兼容旧批次：旧版本只触发了“单任务 worker”，会留下 pending 任务并
        // 永远保持 running。轮询发现没有 processing 但仍有 pending 时，补触发
        // 循环 worker，让历史批次也能继续完成。
        $counts = (array) ($status['counts'] ?? []);
        if (($status['status'] ?? '') === 'running'
            && (int) ($counts['pending'] ?? 0) > 0
            && (int) ($counts['processing'] ?? 0) === 0) {
            Lisun_Manual_Translate::trigger_queue_worker();
        }
        wp_send_json_success($status);
    }

    public static function ajax_retry() {
        self::authorize();
        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        $batch = self::get_batch($batch_id);
        if (!$batch) wp_send_json_error(['message' => '批次不存在']);

        $details = json_decode((string) ($batch['details_json'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        $term_id = (string) ($batch['term_id'] ?? '');
        $revision = (int) ($batch['glossary_revision'] ?? 0);
        $batch_term_revision = (int) ($details['term']['term_revision'] ?? 0);
        $has_term_revision = is_array($details['term'] ?? null)
            && array_key_exists('term_revision', $details['term']);
        if (($has_term_revision && $batch_term_revision !== Lisun_Manual_Glossary::get_term_revision($term_id))
            || (!$has_term_revision && $revision !== (int) get_option('lisun_glossary_revision', 0))) {
            wp_send_json_error(['message' => '术语库已变化，请重新查看影响范围后创建新批次']);
        }

        global $wpdb;
        $queue = $wpdb->prefix . 'lisun_translate_queue';
        $failed = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, payload, result_json FROM {$queue} WHERE batch_id = %s AND action = 'glossary_apply' AND status = 'failed'",
            $batch_id
        ), ARRAY_A);
        $queued = 0;
        foreach ((array) $failed as $row) {
            $old_result = json_decode((string) ($row['result_json'] ?? ''), true);
            if (self::is_success_with_warning((string) ($row['status'] ?? 'failed'), $old_result)) {
                // This was an old false failure: the revision was pushed and
                // only the term-count/anchor audit raised a warning.
                continue;
            }
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $mode = (string) ($payload['mode'] ?? 'direct');
            if (Lisun_Manual_Translate::enqueue_glossary_apply_job((int) $row['post_id'], $batch_id, $term_id, $mode, $revision)) {
                $wpdb->update($queue, ['status' => 'retried', 'updated_at' => current_time('mysql')], ['id' => (int) $row['id']]);
                $queued++;
            }
        }
        if ($queued) {
            self::set_batch_status($batch_id, 'queued');
            Lisun_Manual_Translate::trigger_queue_worker();
        }
        wp_send_json_success(['queued' => $queued, 'message' => $queued ? '失败任务已重新入队' : '没有可重试的失败任务']);
    }

    /** Undo one post's glossary application. */
    public static function ajax_undo() {
        self::authorize();
        $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
        $post_id = absint($_POST['post_id'] ?? 0);
        $applied_revision_id = absint($_POST['applied_revision_id'] ?? 0);
        if ($batch_id === '' || $post_id <= 0 || $applied_revision_id <= 0) {
            wp_send_json_error(['message' => '参数不完整']);
        }
        $batch = self::get_batch($batch_id);
        if (!$batch) wp_send_json_error(['message' => '批次不存在']);

        // Run the actual rollback.
        $result = Lisun_Manual_Translate::undo_glossary_apply($post_id, $applied_revision_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $undone_at = current_time('mysql');
        $restored_revision_id = (int) ($result['previous_revision_id'] ?? 0);
        $undo_marker = [
            'undone' => true,
            'undone_at' => $undone_at,
            'batch_id' => $batch_id,
            'applied_revision_id' => $applied_revision_id,
            'restored_revision_id' => $restored_revision_id,
        ];

        // The UI may be showing an application from an older batch while the
        // newest batch has no per-article application row. Persist the marker
        // on both the requested batch and the actual source batch so a later
        // history read cannot resurrect the old application.
        $source_batch_ids = [$batch_id];
        foreach ((array) self::history('', 100) as $history_batch) {
            foreach ((array) ($history_batch['applications'] ?? []) as $application) {
                if ((int) ($application['post_id'] ?? 0) !== $post_id) continue;
                $same_revision = (int) ($application['applied_revision_id'] ?? 0) === $applied_revision_id;
                $same_batch = (string) ($application['batch_id'] ?? '') === $batch_id;
                if ($same_revision || $same_batch) {
                    $candidate_batch_id = (string) ($application['batch_id'] ?? '');
                    if ($candidate_batch_id !== '') $source_batch_ids[] = $candidate_batch_id;
                }
            }
        }
        $source_batch_ids = array_values(array_unique(array_filter($source_batch_ids)));

        global $wpdb;
        $persisted_batch_ids = [];
        $persistence_errors = [];
        foreach ($source_batch_ids as $source_batch_id) {
            $source_batch = self::get_batch($source_batch_id);
            if (!$source_batch) continue;
            $details = json_decode((string) ($source_batch['details_json'] ?? ''), true);
            $details = is_array($details) ? $details : [];
            $applications = is_array($details['applications'] ?? null) ? $details['applications'] : [];
            foreach ($applications as $i => $app) {
                if ((int) ($app['post_id'] ?? 0) !== $post_id) continue;
                $app_revision = (int) ($app['applied_revision_id'] ?? 0);
                if ($app_revision > 0 && $app_revision !== $applied_revision_id) continue;
                $applications[$i]['undone'] = true;
                $applications[$i]['undone_at'] = $undone_at;
                $applications[$i]['restored_revision_id'] = $restored_revision_id;
                break;
            }
            $details['undone_posts'] = is_array($details['undone_posts'] ?? null)
                ? $details['undone_posts'] : [];
            $details['undone_posts'][(string) $post_id] = $undo_marker;
            $details['applications'] = array_values($applications);
            $updated = $wpdb->update(self::table_name(), [
                'details_json' => wp_json_encode($details),
                'updated_at' => current_time('mysql'),
            ], ['batch_id' => $source_batch_id]);
            if ($updated === false) {
                $persistence_errors[] = '批次 ' . $source_batch_id . ' 写入失败：' . ($wpdb->last_error ?: '数据库未返回错误');
                continue;
            }
            $verify_batch = self::get_batch($source_batch_id);
            $verify_details = json_decode((string) ($verify_batch['details_json'] ?? ''), true);
            if (!is_array($verify_details)
                || empty($verify_details['undone_posts'][(string) $post_id]['undone'])) {
                $persistence_errors[] = '批次 ' . $source_batch_id . ' 写入后未读到撤销标记';
                continue;
            }
            $persisted_batch_ids[] = $source_batch_id;
        }

        // Mirror into per-post history, even when the old row did not contain
        // an application record. This is the final compatibility source for
        // old batches and prevents a successful undo from becoming invisible.
        $post_history_key = '_lisun_manual_glossary_apply_history';
        $post_history = get_post_meta($post_id, $post_history_key, true);
        $post_history = is_array($post_history) ? $post_history : [];
        $history_updated = false;
        foreach ($post_history as $i => $row) {
            if ((int) ($row['applied_revision_id'] ?? 0) === $applied_revision_id
                && ((string) ($row['batch_id'] ?? '') === $batch_id
                    || in_array((string) ($row['batch_id'] ?? ''), $source_batch_ids, true))) {
                $post_history[$i]['undone'] = true;
                $post_history[$i]['undone_at'] = $undone_at;
                $post_history[$i]['restored_revision_id'] = $restored_revision_id;
                $history_updated = true;
            }
        }
        if (!$history_updated) {
            array_unshift($post_history, [
                'post_id' => $post_id,
                'title' => get_the_title($post_id) ?: ('#' . $post_id),
                'term_id' => (string) ($batch['term_id'] ?? ''),
                'status' => 'done',
                'batch_id' => $batch_id,
                'applied_revision_id' => $applied_revision_id,
                'restored_revision_id' => $restored_revision_id,
                'undone' => true,
                'undone_at' => $undone_at,
                'time' => $undone_at,
            ]);
        }
        $post_history_value = array_slice($post_history, 0, 20);
        $history_meta_updated = update_post_meta($post_id, $post_history_key, $post_history_value);
        $verified_post_history = get_post_meta($post_id, $post_history_key, true);
        $history_marker_verified = false;
        foreach ((array) $verified_post_history as $verified_record) {
            if (is_array($verified_record)
                && (int) ($verified_record['applied_revision_id'] ?? 0) === $applied_revision_id
                && !empty($verified_record['undone'])) {
                $history_marker_verified = true;
                break;
            }
        }
        if (!$history_marker_verified) {
            $persistence_errors[] = '文章撤销历史写入后未读到撤销标记';
        }

        // Keep an article-level undo index separate from batch JSON. Batch
        // details can be rewritten later by queue reconciliation; this index
        // is the authoritative per-article state used by history/statistics.
        $undo_index_key = '_lisun_manual_glossary_undo_index';
        $undo_index = get_post_meta($post_id, $undo_index_key, true);
        $undo_index = is_array($undo_index) ? $undo_index : [];
        $undo_term_id = (string) ($batch['term_id'] ?? '');
        if ($undo_term_id === '') $undo_term_id = '_unknown';
        if (!isset($undo_index[$undo_term_id]) || !is_array($undo_index[$undo_term_id])) {
            $undo_index[$undo_term_id] = [];
        }
        $undo_index[$undo_term_id][(string) $applied_revision_id] = $undo_marker;
        $undo_index_updated = update_post_meta($post_id, $undo_index_key, $undo_index);
        $verified_undo_index = get_post_meta($post_id, $undo_index_key, true);
        if (empty($verified_undo_index[$undo_term_id][(string) $applied_revision_id]['undone'])) {
            $persistence_errors[] = '文章撤销索引写入后未读到撤销标记';
        }

        if ($persistence_errors) {
            wp_send_json_error(['message' => implode('；', array_unique($persistence_errors))]);
        }

        wp_send_json_success([
            'message' => '已撤销并恢复到应用前的译文，远程站已更新',
            'previous_revision_id' => (int) ($result['previous_revision_id'] ?? 0),
        ]);
    }

    public static function ajax_history() {
        self::authorize();
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        wp_send_json_success(['history' => self::history($term_id)]);
    }

    /** Return application records for one term on one source article. */
    public static function ajax_post_history() {
        self::authorize();
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        $post_id = absint($_POST['post_id'] ?? 0);
        wp_send_json_success(['history' => self::post_history($term_id, $post_id)]);
    }

    /** Queue incremental V2 translation for source-changed rows shown in the impact preview. */
    public static function ajax_retranslate_stale() {
        self::authorize();
        self::watch_fatal('lisun_glossary_retranslate_stale');
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        $requested = array_values(array_unique(array_filter(array_map(
            'absint',
            is_array($_POST['post_ids'] ?? null) ? $_POST['post_ids'] : []
        ))));
        if ($term_id === '' || empty($requested)) {
            wp_send_json_error(['message' => '没有选择需要增量翻译的文章']);
        }

        try {
            // Rebuild the preview server-side. A client may only queue rows that
            // are explicitly recoverable; locked pages remain excluded.
            $preview = self::preview($term_id);
            if (is_wp_error($preview)) {
                wp_send_json_error(['message' => $preview->get_error_message()]);
            }
            $allowed = [];
            foreach ((array) ($preview['skipped'] ?? []) as $item) {
                $reason = (string) ($item['reason'] ?? '');
                $retryable = in_array($reason, [
                    '中文源文已变化，需先重新翻译',
                    '旧版译文无稳定术语锚点，需重新翻译',
                    '当前译文单元已降级或没有稳定术语锚点',
                ], true)
                    || strpos($reason, '无法定位对应翻译单元') !== false
                    || strpos($reason, '没有稳定术语锚点') !== false;
                if (!$retryable) continue;
                $allowed[(int) ($item['post_id'] ?? 0)] = true;
            }

            $job_ids = [];
            $rejected = 0;
            foreach (array_slice($requested, 0, 200) as $post_id) {
                if (empty($allowed[$post_id])) {
                    $rejected++;
                    continue;
                }
                $job_id = Lisun_Manual_Translate::enqueue_incremental_translation_job($post_id);
                if ($job_id) {
                    $job_ids[] = (int) $job_id;
                } else {
                    $rejected++;
                }
            }

            if (!empty($job_ids)) Lisun_Manual_Translate::trigger_queue_worker();
            wp_send_json_success([
                'queued' => count($job_ids),
                'rejected' => $rejected,
                'job_ids' => $job_ids,
                'message' => !empty($job_ids)
                    ? '已将 ' . count($job_ids) . ' 篇加入增量翻译队列；完成后会自动推送英文站'
                    : '没有文章成功加入增量翻译队列',
            ]);
        } catch (\Throwable $e) {
            self::log('retranslate_stale exception: ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error([
                'message' => '增量翻译入队失败：' . $e->getMessage(),
                'reason'  => 'exception',
            ], 200);
        }
    }

    /** Return compact progress for source-change recovery jobs queued from this modal. */
    public static function ajax_retranslate_stale_status() {
        self::authorize();
        $job_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            is_array($_POST['job_ids'] ?? null) ? $_POST['job_ids'] : []
        ))));
        $job_ids = array_slice($job_ids, 0, 200);
        if (empty($job_ids)) wp_send_json_error(['message' => '缺少翻译任务 ID']);

        // A worker may have died after claiming a job. These are short,
        // source-change recovery tasks, so ten minutes without a heartbeat is
        // enough to requeue them; normal queue jobs keep the longer safeguard.
        $recovered = Lisun_Manual_Translate::recover_stale_queue_jobs($job_ids, 600);

        global $wpdb;
        $queue = $wpdb->prefix . 'lisun_translate_queue';
        $in = implode(',', array_map('intval', $job_ids));
        $rows = $wpdb->get_results(
            "SELECT id, status FROM {$queue} WHERE id IN ({$in})",
            ARRAY_A
        );
        $counts = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'other' => 0];
        foreach ((array) $rows as $row) {
            $status = (string) ($row['status'] ?? 'other');
            if (isset($counts[$status])) $counts[$status]++;
            else $counts['other']++;
        }
        // Missing rows are not silently counted as success.
        $counts['other'] += max(0, count($job_ids) - count((array) $rows));
        if ($counts['pending'] > 0 && $counts['processing'] === 0) {
            Lisun_Manual_Translate::trigger_queue_worker();
        }
        wp_send_json_success([
            'counts' => $counts,
            'recovered' => $recovered,
            'finished' => ($counts['pending'] + $counts['processing']) === 0,
        ]);
    }

    public static function post_history($term_id, $post_id, $limit = 30) {
        $post_id = absint($post_id);
        if ($post_id <= 0) return [];
        $collect = function ($batches) use ($post_id) {
            $records = [];
            foreach ($batches as $batch) {
            $matched_application = false;
            foreach ((array) ($batch['applications'] ?? []) as $application) {
                if ((int) ($application['post_id'] ?? 0) !== $post_id) continue;
                $application['batch_id'] = (string) ($batch['batch_id'] ?? '');
                $application['scope'] = (string) ($batch['scope'] ?? 'global');
                $application['batch_status'] = (string) ($batch['status'] ?? '');
                $records[] = $application;
                $matched_application = true;
            }

            $details = is_array($batch['details'] ?? null) ? $batch['details'] : [];
            $candidate_match = false;
            foreach ((array) ($details['candidates'] ?? []) as $candidate) {
                if ((int) ($candidate['post_id'] ?? 0) === $post_id) {
                    $candidate_match = true;
                    break;
                }
            }
            $single_match = ($batch['scope'] ?? '') === 'single'
                && (int) ($batch['post_id'] ?? 0) === $post_id;

            // A batch created before per-job details existed can still be
            // identified from its candidate metadata. This also makes old
            // completed batches visible when queue rows were already pruned.
            if (!$matched_application && ($candidate_match || $single_match)) {
                $records[] = [
                    'post_id' => $post_id,
                    'title' => get_the_title($post_id) ?: ('#' . $post_id),
                    'status' => (string) ($batch['status'] ?? 'queued'),
                    'scope' => ($batch['scope'] ?? '') === 'single' ? 'single' : 'global',
                    'batch_id' => (string) ($batch['batch_id'] ?? ''),
                    'time' => (string) ($batch['updated_at'] ?? $batch['created_at'] ?? ''),
                ];
            }
            }
            return $records;
        };

        // First prefer the current term. If an older glossary revision used a
        // different term ID, search all batches and match by article ID.
        $records = $collect(self::history($term_id, $limit));
        if (!$records) $records = $collect(self::history('', 100));

        // Last fallback: the queue is the authoritative execution ledger and
        // may still contain the result even when an older batch has no audit
        // details or was created before the history table was fixed.
        if (!$records) {
            global $wpdb;
            $queue = $wpdb->prefix . 'lisun_translate_queue';
            $batches = self::table_name();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT q.batch_id, q.status, q.payload, q.result_json, q.error_msg,
                        q.created_at, q.updated_at, b.term_id AS batch_term_id,
                        b.details_json AS batch_details, b.status AS batch_status
                 FROM {$queue} q
                 LEFT JOIN {$batches} b ON b.batch_id = q.batch_id
                 WHERE q.action = 'glossary_apply' AND q.post_id = %d
                 ORDER BY q.id DESC LIMIT %d",
                $post_id,
                max(1, min(100, (int) $limit))
            ), ARRAY_A);
            $all = [];
            $term_matches = [];
            foreach ((array) $rows as $row) {
                $payload = json_decode((string) ($row['payload'] ?? ''), true);
                $payload = is_array($payload) ? $payload : [];
                $result = json_decode((string) ($row['result_json'] ?? ''), true);
                $result = is_array($result) ? $result : [];
                $details = json_decode((string) ($row['batch_details'] ?? ''), true);
                $details = is_array($details) ? $details : [];
                $status = (string) ($row['status'] ?? 'failed');
                $warning_only = self::is_success_with_warning($status, $result);
                if ($warning_only) $status = 'done';
                $local_only = !empty($result['local_updated']) && empty($result['pushed']);
                if ($local_only && $status === 'done') $status = 'failed';
                $record = [
                    'post_id' => $post_id,
                    'title' => get_the_title($post_id) ?: ('#' . $post_id),
                    'term_id' => (string) ($payload['term_id'] ?? ($row['batch_term_id'] ?? '')),
                    'mode' => (string) ($payload['mode'] ?? 'direct'),
                    'status' => $status === 'done' ? 'done' : (($status === 'failed') ? 'failed' : 'processing'),
                    'scope' => (($details['scope'] ?? '') === 'single') ? 'single' : 'global',
                    'batch_id' => (string) ($row['batch_id'] ?? ''),
                    'updated' => (int) ($result['updated'] ?? 0),
                    'requests' => (int) ($result['requests'] ?? 0),
                    'error' => (string) (($result['message'] ?? '') ?: ($row['error_msg'] ?? '')),
                    'time' => (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
                ];
                if ($warning_only) {
                    $record['warning_only'] = true;
                    $record['pushed'] = true;
                    if (is_array($result['post_apply_audit'] ?? null)) $record['post_apply_audit'] = $result['post_apply_audit'];
                    if (isset($result['revision_id'])) $record['applied_revision_id'] = (int) $result['revision_id'];
                    $record['error'] = '';
                }
                if ($local_only) {
                    $record['local_only'] = true;
                    $record['error_code'] = 'push_failed';
                    if ($record['error'] === '') $record['error'] = (string) (($result['push_msg'] ?? '') ?: '远程英文站推送失败，本地译文已更新但尚未推送');
                }
                $all[] = $record;
                if ($term_id === '' || $record['term_id'] === $term_id) $term_matches[] = $record;
            }
            $records = $term_matches ?: $all;
        }

        // Very old V2 glossary runs recorded their result on the source post
        // but did not have a batch/audit row. Include those records as the
        // final compatibility source.
        if (!$records) {
            $meta_records = [];
            $meta_sources = [
                get_post_meta($post_id, '_lisun_manual_glossary_apply_history', true),
                get_post_meta($post_id, '_lisun_manual_v2_glossary_apply_history', true),
            ];
            foreach ($meta_sources as $source) {
                foreach (is_array($source) ? $source : [] as $item) {
                    $item = is_array($item) ? $item : [];
                    $item['post_id'] = $post_id;
                    $item['title'] = $item['title'] ?? (get_the_title($post_id) ?: ('#' . $post_id));
                    $item['status'] = $item['status'] ?? (!empty($item['pushed']) ? 'done' : 'failed');
                    $item['scope'] = $item['scope'] ?? 'single';
                    $item['time'] = $item['time'] ?? ($item['created_at'] ?? '');
                    $meta_records[] = $item;
                }
            }
            $term_meta_records = array_values(array_filter($meta_records, function ($item) use ($term_id) {
                if ($term_id === '') return true;
                if (!empty($item['term_id']) && (string) $item['term_id'] === $term_id) return true;
                return in_array($term_id, (array) ($item['changed_terms'] ?? []), true);
            }));
            $records = $term_meta_records ?: $meta_records;
        }
        return array_slice($records, 0, max(1, min(100, (int) $limit)));
    }

    /**
     * Persist the per-article result inside the batch record.  The queue is
     * deliberately the source of truth for status; this detail is the audit
     * trail explaining what each article did.
     */
    public static function record_job_result($batch_id, $post_id, $term_id, $mode, $status, $result = [], $error = '') {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') return;
        $batch = self::get_batch($batch_id);
        if (!$batch) return;

        $details = json_decode((string) ($batch['details_json'] ?? ''), true);
        $details = is_array($details) ? $details : [];
        $applications = is_array($details['applications'] ?? null) ? $details['applications'] : [];
        $result = is_array($result) ? $result : [];
        // A V2 translation may have updated the local English copy but failed
        // while pushing it to the remote site.  That is not a successful
        // glossary application: keep it visible as a failure so it cannot be
        // mistaken for an applied/direct replacement in the next preview.
        $local_only = !empty($result['local_updated']) && empty($result['pushed']);
        if ($local_only) {
            $status = 'failed';
            if ($error === '') {
                $error = (string) (($result['message'] ?? '') ?: ($result['push_msg'] ?? '远程英文站推送失败，本地译文已更新但尚未推送'));
            }
        }
        $record = [
            'post_id' => (int) $post_id,
            'title' => get_the_title((int) $post_id) ?: ('#' . (int) $post_id),
            'term_id' => (string) $term_id,
            'mode' => (string) $mode,
            'status' => in_array($status, ['done', 'failed'], true) ? $status : 'failed',
            'updated' => (int) ($result['updated'] ?? 0),
            'translated_units' => (int) ($result['translated_units'] ?? 0),
            'reused_units' => (int) ($result['reused_units'] ?? 0),
            'occurrences' => (int) ($result['occurrences'] ?? ($result['anchors'] ?? 0)),
            'requests' => (int) ($result['requests'] ?? 0),
            // Keep the real push result even when the application is marked
            // incomplete after post-commit verification. The revision may
            // already have reached the remote site; the visible English
            // coverage and the stable-anchor coverage are recorded separately.
            'pushed' => !empty($result['pushed']),
            'error' => (string) $error,
            'time' => current_time('mysql'),
        ];
        $applied_revision = (int) ($result['revision_id'] ?? 0);
        if ($applied_revision > 0) {
            $record['applied_revision_id'] = $applied_revision;
        }
        if (!empty($result['undone'])) {
            $record['undone'] = true;
            $record['undone_at'] = (string) ($result['undone_at'] ?? current_time('mysql'));
        }
        if ($local_only) $record['local_only'] = true;
        if (is_array($result['post_apply_audit'] ?? null)) {
            $post_audit = $result['post_apply_audit'];
        } elseif ($record['status'] === 'done' && !empty($record['pushed'])) {
            $post_audit = self::audit_current_term_application($post_id, $term_id);
        } else {
            $post_audit = null;
        }
        if (is_array($post_audit)) {
            $record['post_apply_audit'] = $post_audit;
            $record['applied_occurrences'] = (int) ($result['visible_applied_occurrences']
                ?? $result['applied_occurrences']
                ?? ($post_audit['visible_applied_count'] ?? ($post_audit['stable_anchor_count'] ?? 0)));
            $record['visible_applied_occurrences'] = (int) ($result['visible_applied_occurrences']
                ?? $post_audit['visible_applied_count']
                ?? $record['applied_occurrences']);
            $record['stable_anchor_occurrences'] = (int) ($result['stable_anchor_occurrences']
                ?? ($post_audit['stable_anchor_count'] ?? 0));
            $record['expected_occurrences'] = (int) ($result['expected_occurrences'] ?? ($post_audit['hit_count'] ?? 0));
            $record['post_apply_unapplied_count'] = (int) ($result['unapplied_count'] ?? ($post_audit['unapplied_count'] ?? 0));
            $record['post_apply_unanchored_count'] = (int) ($post_audit['unanchored_count'] ?? 0);
            if (!empty($result['attempted'])) $record['attempted'] = true;
            if (!empty($result['incomplete'])) $record['incomplete'] = true;
            if (!empty($result['missing_occurrences']) && is_array($result['missing_occurrences'])) {
                $record['missing_occurrences'] = $result['missing_occurrences'];
            }
        }
        if (!empty($result['warning_only']) && $record['status'] === 'done' && !$local_only) {
            // A partial visible-term match is a completed application with a
            // review warning, not a failed translation. Preserve that fact in
            // both the batch history and the per-post history.
            $record['warning_only'] = true;
            $record['warning_message'] = (string) ($result['warning_message']
                ?? ($result['message'] ?? '术语应用完成，但有术语数量或锚点提示'));
            $record['error'] = '';
        }
        if (!empty($result['term_anomalies']) && is_array($result['term_anomalies'])) {
            $record['term_anomaly_units'] = count($result['term_anomalies']);
            $record['term_anomalies'] = array_values($result['term_anomalies']);
        }
        if ($status === 'failed') {
            if ($local_only) $record['error_code'] = 'push_failed';
            if (!empty($result['code'])) $record['error_code'] = (string) $result['code'];
            if (!empty($result['unit_key'])) $record['unit_key'] = (string) $result['unit_key'];
            if (!empty($result['batch_index'])) $record['batch_index'] = (int) $result['batch_index'];
            if (!empty($result['trace_id'])) $record['trace_id'] = (string) $result['trace_id'];
            $error_occurrences = !empty($result['occurrences']) && is_array($result['occurrences'])
                ? $result['occurrences']
                : ((array) ($result['missing_occurrences'] ?? []));
            if (!empty($error_occurrences)) {
                $record['error_occurrences'] = array_values(array_map(function ($item) {
                    return [
                        'occurrence_id' => (string) ($item['occurrence_id'] ?? ''),
                        'source_text' => (string) ($item['source_text'] ?? ''),
                        'expected' => (string) ($item['expected'] ?? ''),
                        'actual' => (string) ($item['actual'] ?? ''),
                    ];
                }, $error_occurrences));
            }
        }
        // Preserve the preview-time hit audit in the immutable application
        // record so later reviews can see which hits were not applied.
        foreach ((array) ($details['candidates'] ?? []) as $candidate) {
            if ((int) ($candidate['post_id'] ?? 0) !== (int) $post_id) continue;
            if (is_array($candidate['audit'] ?? null)) {
                $record['audit'] = $candidate['audit'];
            }
            break;
        }
        $batch_term = self::batch_term_snapshot($batch);
        if ($batch_term) $record['term_snapshot'] = $batch_term;

        $replaced = false;
        foreach ($applications as $index => $application) {
            if ((int) ($application['post_id'] ?? 0) === (int) $post_id) {
                $applications[$index] = $record;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) $applications[] = $record;
        $details['applications'] = array_values($applications);

        global $wpdb;
        $wpdb->update(self::table_name(), [
            'details_json' => wp_json_encode($details),
            'updated_at' => current_time('mysql'),
        ], ['batch_id' => $batch_id]);

        // Keep a per-source-page history as well, so the result is visible
        // even when the user later opens a different glossary term.
        $post_history_key = '_lisun_manual_glossary_apply_history';
        $post_history = get_post_meta((int) $post_id, $post_history_key, true);
        $post_history = is_array($post_history) ? $post_history : [];
        $record['batch_id'] = $batch_id;
        $record['scope'] = (($details['scope'] ?? 'global') === 'single') ? 'single' : 'global';
        array_unshift($post_history, $record);
        update_post_meta((int) $post_id, $post_history_key, array_slice($post_history, 0, 20));
    }

    /** Return recent global and single-article application records for a term. */
    public static function history($term_id, $limit = 30) {
        global $wpdb;
        $limit = max(1, min(100, (int) $limit));
        $term_id = (string) $term_id;
        $legacy_term_fallback = false;
        if ((string) $term_id === '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT batch_id, status, total_jobs, skipped_jobs, details_json, created_at, updated_at
                 FROM ' . self::table_name() . ' ORDER BY created_at DESC LIMIT %d',
                $limit
            ), ARRAY_A);
        } else {
            // 先按 term_id 精确查，再按中英文快照补查旧批次（术语重新导入后 term_id 会变）。
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT batch_id, term_id, status, total_jobs, skipped_jobs, details_json, created_at, updated_at
                 FROM ' . self::table_name() . ' WHERE term_id = %s ORDER BY created_at DESC LIMIT %d',
                (string) $term_id,
                $limit
            ), ARRAY_A);
            $exact_ids = [];
            foreach ($rows as $row) $exact_ids[(string) $row['batch_id']] = true;
            // 拉最近的批次做快照匹配，补齐 term_id 对不上但中英文一致的旧记录。
            $snapshot_rows = $wpdb->get_results($wpdb->prepare(
                'SELECT batch_id, term_id, status, total_jobs, skipped_jobs, details_json, created_at, updated_at
                 FROM ' . self::table_name() . ' ORDER BY created_at DESC LIMIT %d',
                max(200, $limit)
            ), ARRAY_A);
            foreach ($snapshot_rows as $row) {
                if (isset($exact_ids[(string) $row['batch_id']])) continue;
                if (self::batch_matches_term_snapshot($row, $term_id)) $rows[] = $row;
            }
            // 按时间倒序重排
            usort($rows, function ($a, $b) {
                return strcmp((string) $b['created_at'], (string) $a['created_at']);
            });
            $legacy_term_fallback = false;
        }
        // Undo may be recorded in a newer empty batch while the application
        // itself lives in an older batch. Collect all persisted undo markers
        // first, then apply them to the matching historical application rows.
        $global_undo_markers = [];
        foreach ((array) $rows as $undo_row) {
            $undo_details = json_decode((string) ($undo_row['details_json'] ?? ''), true);
            if (!is_array($undo_details) || !is_array($undo_details['undone_posts'] ?? null)) continue;
            foreach ($undo_details['undone_posts'] as $undo_post_id => $undo_marker) {
                if (!is_array($undo_marker) || empty($undo_marker['undone'])) continue;
                $undo_post_id = (string) absint($undo_post_id);
                if ($undo_post_id === '0') continue;
                $undo_marker['batch_id'] = (string) ($undo_marker['batch_id'] ?? ($undo_row['batch_id'] ?? ''));
                $global_undo_markers[$undo_post_id][] = $undo_marker;
            }
        }

        $history = [];
        foreach ((array) $rows as $row) {
            if ($legacy_term_fallback && !self::batch_matches_term_snapshot($row, $term_id)) {
                continue;
            }
            self::sync_batch_status((string) ($row['batch_id'] ?? ''));
            $row = self::get_batch((string) ($row['batch_id'] ?? '')) ?: $row;
            $details = json_decode((string) ($row['details_json'] ?? ''), true);
            $details = is_array($details) ? $details : [];
            $details['applications'] = self::hydrate_applications(
                (string) ($row['batch_id'] ?? ''),
                $details
            );
            // Older batches may not have a persisted applications array. In
            // that case rebuild it from the actually processed candidates
            // before applying undo markers.  The old order rebuilt these
            // records afterwards, which discarded a valid undo marker and
            // made the article appear applied again after refresh.
            if (empty($details['applications']) && !empty($details['candidates'])) {
                $processed_ids = !empty($details['processed_post_ids'])
                    ? array_map('intval', $details['processed_post_ids'])
                    : [];
                foreach ((array) $details['candidates'] as $cand) {
                    $cp = (int) ($cand['post_id'] ?? 0);
                    if ($cp <= 0) continue;
                    // With a processed list, only reconstruct jobs selected
                    // in that batch. Without one, retain the legacy fallback
                    // for old batches whose candidate list is the audit trail.
                    if (!empty($processed_ids) && !in_array($cp, $processed_ids, true)) continue;
                    $details['applications'][] = [
                        'post_id' => $cp,
                        'title' => (string) ($cand['title'] ?? ''),
                        'status' => 'done',
                        'batch_id' => (string) ($row['batch_id'] ?? ''),
                    ];
                }
            }
            $undone_posts = is_array($details['undone_posts'] ?? null) ? $details['undone_posts'] : [];
            foreach ($details['applications'] as &$application) {
                $application_post_id = (string) ((int) ($application['post_id'] ?? 0));
                if ($application_post_id !== '0' && isset($undone_posts[$application_post_id])) {
                    $application['undone'] = true;
                    $application['undone_at'] = (string) ($undone_posts[$application_post_id]['undone_at'] ?? '');
                }
                foreach ((array) ($global_undo_markers[$application_post_id] ?? []) as $undo_marker) {
                    $marker_batch_id = (string) ($undo_marker['batch_id'] ?? '');
                    $application_batch_id = (string) ($row['batch_id'] ?? '');
                    $marker_revision_id = (int) ($undo_marker['applied_revision_id'] ?? 0);
                    $application_revision_id = (int) ($application['applied_revision_id'] ?? 0);
                    $same_batch = $marker_batch_id !== '' && $marker_batch_id === $application_batch_id;
                    $same_revision = $marker_revision_id > 0
                        && $application_revision_id > 0
                        && $marker_revision_id === $application_revision_id;
                    // A marker from a different batch is allowed to target an
                    // old application only when its revision matches. If the
                    // old record has no revision id, the source batch match is
                    // the safe fallback.
                    if (!$same_batch && !$same_revision) continue;
                    $application['undone'] = true;
                    $application['undone_at'] = (string) ($undo_marker['undone_at'] ?? '');
                    $application['restored_revision_id'] = (int) ($undo_marker['restored_revision_id'] ?? 0);
                }
                // Article-level undo index is authoritative when a later
                // queue write has replaced the batch details JSON.
                if ($application_post_id !== '0') {
                    $undo_index = get_post_meta((int) $application_post_id, '_lisun_manual_glossary_undo_index', true);
                    foreach ((array) $undo_index as $term_markers) {
                        foreach ((array) $term_markers as $undo_marker) {
                            if (!is_array($undo_marker) || empty($undo_marker['undone'])) continue;
                            $marker_revision_id = (int) ($undo_marker['applied_revision_id'] ?? 0);
                            $application_revision_id = (int) ($application['applied_revision_id'] ?? 0);
                            $same_revision = $marker_revision_id > 0
                                && $application_revision_id > 0
                                && $marker_revision_id === $application_revision_id;
                            $same_batch = (string) ($undo_marker['batch_id'] ?? '') !== ''
                                && (string) ($undo_marker['batch_id'] ?? '') === (string) ($row['batch_id'] ?? '');
                            if (!$same_revision && !$same_batch) continue;
                            $application['undone'] = true;
                            $application['undone_at'] = (string) ($undo_marker['undone_at'] ?? '');
                            $application['restored_revision_id'] = (int) ($undo_marker['restored_revision_id'] ?? 0);
                        }
                    }

                    // Older undo requests persisted only the per-post
                    // application history. Read that history too; otherwise
                    // those already-successful undos are still counted from
                    // the batch JSON as active applications.
                    foreach (['_lisun_manual_glossary_apply_history', '_lisun_manual_v2_glossary_apply_history'] as $history_key) {
                        $legacy_history = get_post_meta((int) $application_post_id, $history_key, true);
                        foreach ((array) $legacy_history as $legacy_record) {
                            if (!is_array($legacy_record) || empty($legacy_record['undone'])) continue;
                            $marker_revision_id = (int) ($legacy_record['applied_revision_id'] ?? 0);
                            $application_revision_id = (int) ($application['applied_revision_id'] ?? 0);
                            $same_revision = $marker_revision_id > 0
                                && $application_revision_id > 0
                                && $marker_revision_id === $application_revision_id;
                            $same_batch = (string) ($legacy_record['batch_id'] ?? '') !== ''
                                && (string) ($legacy_record['batch_id'] ?? '') === (string) ($row['batch_id'] ?? '');
                            if (!$same_revision && !$same_batch) continue;
                            $application['undone'] = true;
                            $application['undone_at'] = (string) ($legacy_record['undone_at'] ?? '');
                            $application['restored_revision_id'] = (int) ($legacy_record['restored_revision_id'] ?? 0);
                        }
                    }
                }
            }
            unset($application);
            $details['applications'] = array_map(
                [self::class, 'normalize_warning_application_record'],
                $details['applications']
            );
            $details['applications'] = self::enrich_undo_state($details['applications']);
            $scope = (string) ($details['scope'] ?? 'global');
            $post_id = absint($details['post_id'] ?? 0);
            $candidates = (array) ($details['candidates'] ?? []);
            if (!$post_id && count($candidates) === 1) {
                $post_id = absint($candidates[0]['post_id'] ?? 0);
            }
            $history[] = [
                'batch_id' => (string) ($row['batch_id'] ?? ''),
                'scope' => $scope === 'single' ? 'single' : 'global',
                'post_id' => $post_id,
                'title' => $post_id ? (get_the_title($post_id) ?: ('#' . $post_id)) : '',
                'status' => (string) ($row['status'] ?? ''),
                'total_jobs' => (int) ($row['total_jobs'] ?? 0),
                'skipped_jobs' => (int) ($row['skipped_jobs'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'applications' => is_array($details['applications'] ?? null) ? $details['applications'] : [],
                // Used by the per-article history endpoint to match an
                // article in a global batch even when queue detail rows are
                // unavailable or were created by an older plugin version.
                'details' => $details,
            ];
        }
        return $history;
    }

    /** Build a conservative, term-specific impact report. */
    public static function preview($term_id) {
        $term = Lisun_Manual_Glossary::get_by_id()[$term_id] ?? null;
        if (!$term) return new WP_Error('glossary_term_missing', '术语不存在或已被删除');

        $glossary = Lisun_Manual_Glossary::get_all();
        $matcher = new Lisun_Manual_V2_Term_Matcher($glossary);
        $candidates = [];
        $skipped = [];
        $direct_occurrences = 0;
        $incremental_units = 0;
        $estimated_characters = 0;

        foreach (Lisun_Manual_CPT::get_zh_pages() as $post) {
            $post_id = (int) $post->ID;
            $base = ['post_id' => $post_id, 'title' => get_the_title($post_id) ?: ('#' . $post_id)];
            // Do not report every locked/stale translation as an affected article.
            // The current Chinese source must still contain this exact term first.
            $scan = self::scan_post_term($post, $matcher, $term_id);
            $source_contains_term = !empty($scan['matches']);
            if (Lisun_Manual_Translate::is_en_locked($post_id)) {
                if ($source_contains_term) {
                    $skipped[] = $base + ['reason' => '已锁定', 'contexts' => $scan['matches']];
                }
                continue;
            }

            $active = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
            if (!$active) {
                $backup = Lisun_Manual_Translate::get_translation_backup($post_id);
                $html = (string) (($backup['en_content'] ?? '') ?: '');
                if ($html !== '' && preg_match('/data-lisun-term-id\s*=\s*(["\'])' . preg_quote($term_id, '/') . '\\1/i', $html)) {
                    $candidates[] = $base + [
                        'mode' => 'legacy',
                        'occurrences' => 1,
                        'units' => 0,
                        'contexts' => $scan['matches'],
                    ];
                } elseif ($source_contains_term) {
                    $skipped[] = $base + [
                        'reason' => '旧版译文无稳定术语锚点，需重新翻译',
                        'contexts' => $scan['matches'],
                    ];
                }
                continue;
            }

            if (!$source_contains_term) {
                continue;
            }
            if (!self::source_hash_matches($post, $active)) {
                $skipped[] = $base + [
                    'reason' => '中文源文已变化，需先重新翻译',
                    'contexts' => $scan['matches'],
                ];
                continue;
            }

            $unit_status = [];
            $units_by_key = [];
            foreach ((array) $active['units'] as $unit) {
                $unit_status[(string) $unit['unit_key']] = (string) ($unit['status'] ?? 'translated');
                $units_by_key[(string) $unit['unit_key']] = $unit;
            }
            // V2 glossary application already supports recovering a historical
            // occurrence by its Chinese source text when a legacy import or
            // sync changed the term_id. The preview must use the same rule;
            // otherwise a successfully repaired article keeps being reported
            // as "incremental" forever even though no DeepL request is needed.
            $current_source_key = self::normalize_source_key($term['zh'] ?? '');
            $occurrences = array_values(array_filter((array) $active['occurrences'], function ($occurrence) use ($term_id, $unit_status) {
                return ($unit_status[(string) ($occurrence['unit_key'] ?? '')] ?? '') !== 'degraded';
            }));
            $occurrences = array_values(array_filter($occurrences, function ($occurrence) use ($term_id, $current_source_key) {
                return (string) ($occurrence['term_id'] ?? '') === $term_id
                    || self::normalize_source_key($occurrence['source_text'] ?? '') === $current_source_key;
            }));
            $audit = self::audit_term_matches($scan['matches'], $occurrences, $unit_status, $units_by_key);
            // A source hit without a stable occurrence anchor cannot be
            // safely handled as a direct replacement. Route those units to
            // incremental translation; otherwise "hit 20 / applied 13"
            // would silently leave seven source hits untouched.
            $invalid_unit_keys = self::invalid_term_units($active, $term_id, $glossary, $current_source_key);
            $missing_unit_keys = [];
            foreach ((array) ($audit['unapplied'] ?? []) as $unapplied_hit) {
                $key = (string) ($unapplied_hit['unit_key'] ?? '');
                if ($key !== '') $missing_unit_keys[$key] = true;
            }
            if (!empty($missing_unit_keys)) {
                $retranslation_unit_keys = array_fill_keys(array_merge(
                    $invalid_unit_keys,
                    array_keys($missing_unit_keys)
                ), true);
                $matching_units = array_values(array_filter((array) ($active['units'] ?? []), function ($unit) use ($retranslation_unit_keys, $unit_status) {
                    $key = (string) ($unit['unit_key'] ?? '');
                    // Degraded units are exactly the units that need a new
                    // DeepL pass; excluding them made completed-but-incomplete
                    // articles fall into a dead-end "跳过" list.
                    return isset($retranslation_unit_keys[$key]);
                }));
                if (!empty($matching_units)) {
                    $chars = array_sum(array_map(function ($unit) {
                        return mb_strlen((string) ($unit['source_text'] ?? ''));
                    }, $matching_units));
                    $application_counts = self::term_application_counts(
                        $scan['matches'],
                        $occurrences,
                        $unit_status,
                        $units_by_key,
                        array_keys($retranslation_unit_keys)
                    );
                    $reason = !empty($invalid_unit_keys)
                        ? '术语槽位不完整，需增量翻译'
                        : '当前译文缺少稳定术语锚点，需增量翻译';
                    $candidates[] = $base + [
                        'mode' => 'incremental',
                        'reason' => '部分术语命中需增量翻译',
                        'occurrences' => 0,
                        'units' => count($matching_units),
                        'direct_occurrences' => (int) $application_counts['direct_occurrences'],
                        'incremental_occurrences' => (int) $application_counts['incremental_occurrences'],
                        'incremental_units' => count($matching_units),
                        'estimated_characters' => $chars,
                        'audit' => self::audit_incremental_matches($scan['matches'], array_keys($retranslation_unit_keys), $reason),
                        'contexts' => $scan['matches'],
                    ];
                    $incremental_units += count($matching_units);
                    $estimated_characters += $chars;
                    continue;
                }
                $skipped[] = $base + [
                    'reason' => '部分术语命中无法定位对应翻译单元，需查看审计明细后重试',
                    'audit' => $audit,
                    'contexts' => $scan['matches'],
                ];
                continue;
            }
            if (!empty($occurrences)) {
                // An occurrence can exist while its stored <t> slot contains
                // only an article or another fragment returned by DeepL. It
                // must never be presented as a safe local replacement: that
                // would recreate the duplicate-term problem. Reclassify only
                // the affected units as incremental translation work.
                $invalid_unit_keys = self::invalid_term_units($active, $term_id, $glossary, $current_source_key);
                if (!empty($invalid_unit_keys)) {
                    $matching_units = self::matching_units($active, $matcher, $term_id, true);
                    $matching_units = array_values(array_filter($matching_units, function ($unit) use ($invalid_unit_keys) {
                        return in_array((string) ($unit['unit_key'] ?? ''), $invalid_unit_keys, true);
                    }));
                    if (!empty($matching_units)) {
                        $chars = array_sum(array_map(function ($unit) {
                            return mb_strlen((string) ($unit['source_text'] ?? ''));
                        }, $matching_units));
                        $retranslation_unit_keys = array_map(function ($unit) {
                            return (string) ($unit['unit_key'] ?? '');
                        }, $matching_units);
                        $application_counts = self::term_application_counts(
                            $scan['matches'],
                            $occurrences,
                            $unit_status,
                            $units_by_key,
                            $retranslation_unit_keys
                        );
                        $candidates[] = $base + [
                            'mode' => 'incremental',
                            'reason' => '术语标签不是完整术语，需增量翻译',
                            'occurrences' => 0,
                            'units' => count($matching_units),
                            'direct_occurrences' => (int) $application_counts['direct_occurrences'],
                            'incremental_occurrences' => (int) $application_counts['incremental_occurrences'],
                            'incremental_units' => count($matching_units),
                            'estimated_characters' => $chars,
                            'audit' => self::audit_incremental_matches($scan['matches'], $retranslation_unit_keys, '术语槽位不完整，需增量翻译'),
                            'contexts' => $scan['matches'],
                        ];
                        $incremental_units += count($matching_units);
                        $estimated_characters += $chars;
                        continue;
                    }
                    $skipped[] = $base + [
                        'reason' => '术语标签不是完整术语，但无法定位对应翻译单元，需查看失败明细后重译',
                        'contexts' => $scan['matches'],
                    ];
                    continue;
                }
                $count = count($occurrences);
                $candidates[] = $base + [
                    'mode' => 'direct',
                    'occurrences' => $count,
                    'units' => 0,
                    'direct_occurrences' => $count,
                    'incremental_occurrences' => 0,
                    'incremental_units' => 0,
                    'audit' => $audit,
                    'contexts' => $scan['matches'],
                ];
                $direct_occurrences += $count;
                continue;
            }

            $matching_units = self::matching_units($active, $matcher, $term_id, true);
            if (!empty($matching_units)) {
                $chars = array_sum(array_map(function ($unit) { return mb_strlen((string) ($unit['source_text'] ?? '')); }, $matching_units));
                $retranslation_unit_keys = array_map(function ($unit) {
                    return (string) ($unit['unit_key'] ?? '');
                }, $matching_units);
                $application_counts = self::term_application_counts(
                    $scan['matches'],
                    $occurrences,
                    $unit_status,
                    $units_by_key,
                    $retranslation_unit_keys
                );
                $candidates[] = $base + [
                    'mode' => 'incremental', 'occurrences' => 0,
                    'units' => count($matching_units), 'estimated_characters' => $chars,
                    'direct_occurrences' => (int) $application_counts['direct_occurrences'],
                    'incremental_occurrences' => (int) $application_counts['incremental_occurrences'],
                    'incremental_units' => count($matching_units),
                    'audit' => self::audit_incremental_matches($scan['matches'], array_map(function ($unit) {
                        return (string) ($unit['unit_key'] ?? '');
                    }, $matching_units), '当前译文没有稳定术语锚点，需增量翻译'),
                    'contexts' => $scan['matches'],
                ];
                $incremental_units += count($matching_units);
                $estimated_characters += $chars;
            } else {
                $skipped[] = $base + [
                    'reason' => '当前译文单元已降级或没有稳定术语锚点',
                    'contexts' => $scan['matches'],
                ];
            }
        }

        // The scan above answers “where does the term occur now?”. It does
        // not answer “which of those occurrences were already applied?”.
        // Resolve that second question from successful per-article records and
        // the immutable term snapshot stored in each batch.
        $history = self::history($term_id, 100);
        $state = self::classify_candidates($candidates, $term, $history);
        $term_revision = Lisun_Manual_Glossary::get_term_revision($term_id);
        if (self::has_manual_unlock($term_id, $term_revision)) {
            foreach ($state['candidates'] as &$candidate) {
                if (($candidate['status'] ?? '') === 'applied') $candidate['status'] = 'pending';
            }
            unset($candidate);
            $state['state']['status'] = 'unlocked';
            $state['state']['term_changed'] = false;
            $state['state']['manual_unlocked'] = true;
            $state['state']['applied_articles'] = 0;
            $state['state']['pending_articles'] = count((array) $state['candidates']);
        }
        $candidates = $state['candidates'];
        $pending = array_values(array_filter($candidates, function ($candidate) {
            return ($candidate['status'] ?? 'pending') !== 'applied';
        }));
        $pending_direct = array_values(array_filter($pending, function ($c) {
            return ($c['mode'] ?? '') === 'direct' && empty($c['local_only']);
        }));
        $pending_incremental = array_values(array_filter($pending, function ($c) { return ($c['mode'] ?? '') === 'incremental'; }));
        $pending_legacy = array_values(array_filter($pending, function ($c) { return ($c['mode'] ?? '') === 'legacy'; }));
        $pending_direct_occurrences = array_sum(array_map(function ($c) { return (int) ($c['occurrences'] ?? 0); }, $pending_direct));
        $pending_incremental_units = array_sum(array_map(function ($c) { return (int) ($c['units'] ?? 0); }, $pending_incremental));
        $pending_characters = array_sum(array_map(function ($c) { return (int) ($c['estimated_characters'] ?? 0); }, $pending_incremental));
        $local_only_articles = count(array_filter($candidates, function ($c) {
            return ($c['status'] ?? '') === 'local_only';
        }));

        return [
            'term' => self::storage_term_snapshot($term),
            'glossary_revision' => (int) get_option('lisun_glossary_revision', 0),
            'term_revision' => $term_revision,
            'candidates' => $candidates,
            'skipped' => $skipped,
            'summary' => [
                // These counters intentionally describe pending work only.
                // Applied articles remain in the list for auditability, but
                // must not make the next preview look like new work.
                'direct_articles' => count($pending_direct),
                'direct_occurrences' => $pending_direct_occurrences,
                'incremental_articles' => count($pending_incremental),
                'incremental_units' => $pending_incremental_units,
                'estimated_characters' => $pending_characters,
                'legacy_articles' => count($pending_legacy),
                'local_only_articles' => $local_only_articles,
                'skipped_articles' => count($skipped),
                'applied_articles' => count($candidates) - count($pending),
            ],
            'application_state' => $state['state'],
            'history' => $history,
        ];
    }

    /**
     * Mark current candidates from the last successful article applications.
     *
     * A term batch stores the zh/en pair used when it was created. For each
     * article we use its newest successful application record, not merely the
     * existence of a batch. This is what distinguishes an already-applied old
     * article from a newly matching article after the batch was run.
     */
    private static function classify_candidates($candidates, $term, $history) {
        $current_snapshot = self::normalize_term_snapshot($term);
        $successful_by_post = [];
        $local_only_by_post = [];
        $incomplete_by_post = [];
        $seen_latest_application = [];
        $latest_success = null;

        foreach ((array) $history as $batch) {
            $batch_snapshot = self::normalize_term_snapshot(
                is_array($batch['details']['term'] ?? null) ? $batch['details']['term'] : [],
                false
            );
            foreach ((array) ($batch['applications'] ?? []) as $application) {
                $application_local_only = !empty($application['local_only'])
                    || (($application['status'] ?? '') === 'done'
                        && array_key_exists('pushed', $application)
                        && empty($application['pushed']))
                    || (($application['status'] ?? '') === 'failed' && !empty($application['local_updated']));
                $post_id = absint($application['post_id'] ?? 0);
                // history() is newest-first; only the newest application
                // record for an article may decide its current state.
                if ($post_id > 0 && isset($seen_latest_application[$post_id])) continue;
                if ($post_id > 0) $seen_latest_application[$post_id] = true;
                // An undo is the newest state for this article.  Keep it
                // authoritative so a refreshed preview cannot fall back to
                // an older successful application and show the article as
                // applied again.
                if ($post_id > 0 && !empty($application['undone'])) continue;
                if ($post_id > 0 && $application_local_only && !isset($local_only_by_post[$post_id])) {
                    $local_only_by_post[$post_id] = $application;
                }
                // A pushed revision with a partial visible count is a warning,
                // but a zero-match result is a real application failure and
                // must remain retryable.
                $hard_visible_failure = self::is_hard_visible_failure(
                    is_array($application['post_apply_audit'] ?? null)
                        ? $application['post_apply_audit'] : []
                );
                $committed_application = (($application['status'] ?? '') === 'done' && !$hard_visible_failure)
                    || (!$hard_visible_failure
                        && !empty($application['pushed'])
                        && is_array($application['post_apply_audit'] ?? null));
                if ($post_id > 0
                    && !$committed_application
                    && (!empty($application['incomplete']) || $hard_visible_failure)
                    && !isset($incomplete_by_post[$post_id])) {
                    if ($hard_visible_failure) {
                        $application['incomplete'] = true;
                        $application['error'] = '应用后英文译文中未发现目标术语，需重新增量翻译';
                        $application['error_code'] = 'glossary_apply_incomplete';
                    }
                    $incomplete_by_post[$post_id] = $application;
                }
                if (!$committed_application) continue;
                // Older audit rows may say done even though the remote push
                // failed. Only an actually pushed result proves application.
                if (array_key_exists('pushed', $application) && empty($application['pushed'])) continue;
                if ($post_id <= 0 || isset($successful_by_post[$post_id])) continue;
                $application_snapshot = self::normalize_term_snapshot(
                    is_array($application['term_snapshot'] ?? null)
                        ? $application['term_snapshot']
                        : $batch_snapshot,
                    false
                );
                // An old audit row without a snapshot is deliberately not
                // considered applied: it cannot prove which term text ran.
                if (!$application_snapshot) continue;
                $successful_by_post[$post_id] = [
                    'snapshot' => $application_snapshot,
                    'record' => $application,
                    'batch' => $batch,
                ];
                if (!$latest_success) {
                    $latest_success = [
                        'snapshot' => $application_snapshot,
                        'batch' => $batch,
                    ];
                }
            }
        }

        $term_changed = (bool) ($latest_success
            && !self::same_term_snapshot($current_snapshot, $latest_success['snapshot']));
        $applied = 0;
        $candidates = is_array($candidates) ? $candidates : [];
        foreach ($candidates as &$candidate) {
            $post_id = absint($candidate['post_id'] ?? 0);
            if (isset($local_only_by_post[$post_id])) {
                $failed_record = $local_only_by_post[$post_id];
                $candidate['status'] = 'local_only';
                $candidate['local_only'] = true;
                $candidate['last_error'] = (string) ($failed_record['error'] ?? '本地译文已更新，但远程推送失败');
                continue;
            }
            // Before visible coverage was persisted, the old audit used the
            // stable-anchor shortfall as "unapplied". Recheck that historical
            // record now so a page whose English is actually complete is not
            // kept in the incomplete queue forever.
            if (isset($incomplete_by_post[$post_id])) {
                $historical_audit = is_array($incomplete_by_post[$post_id]['post_apply_audit'] ?? null)
                    ? $incomplete_by_post[$post_id]['post_apply_audit'] : [];
                if (self::needs_visible_comparison_audit($historical_audit)) {
                    $fresh_audit = self::audit_current_term_application($post_id, (string) ($term['term_id'] ?? ''));
                    if (is_array($fresh_audit)) {
                        $incomplete_by_post[$post_id]['post_apply_audit'] = $fresh_audit;
                        if ((int) ($fresh_audit['visible_missing_count'] ?? $fresh_audit['unapplied_count'] ?? 0) === 0) {
                            unset($incomplete_by_post[$post_id]);
                        }
                    }
                }
            }
            if (isset($incomplete_by_post[$post_id])) {
                $incomplete_record = $incomplete_by_post[$post_id];
                $candidate['status'] = 'incomplete';
                $candidate['incomplete'] = true;
                $incomplete_audit = is_array($incomplete_record['post_apply_audit'] ?? null)
                    ? $incomplete_record['post_apply_audit'] : [];
                if (self::needs_visible_comparison_audit($incomplete_audit)) {
                    $fresh_audit = self::audit_current_term_application($post_id, (string) ($term['term_id'] ?? ''));
                    if (is_array($fresh_audit)) {
                        $incomplete_audit = $fresh_audit;
                        $incomplete_record['post_apply_audit'] = $fresh_audit;
                    }
                }
                $candidate['attempted_expected_occurrences'] = (int) ($incomplete_audit['hit_count']
                    ?? $incomplete_record['expected_occurrences'] ?? 0);
                $candidate['attempted_applied_occurrences'] = (int) ($incomplete_audit['visible_applied_count']
                    ?? $incomplete_record['visible_applied_occurrences']
                    ?? $incomplete_record['applied_occurrences']
                    ?? ($incomplete_audit['stable_anchor_count'] ?? 0));
                $candidate['attempted_stable_anchor_occurrences'] = (int) ($incomplete_audit['stable_anchor_count']
                    ?? $incomplete_record['stable_anchor_occurrences'] ?? 0);
                $candidate['attempted_unapplied_count'] = (int) ($incomplete_record['post_apply_unapplied_count'] ?? ($incomplete_audit['unapplied_count'] ?? 0));
                $hard_visible_failure = self::is_hard_visible_failure($incomplete_audit);
                if ($hard_visible_failure) {
                    // A zero-visible result is retryable incremental work,
                    // even when the old persisted record was labelled done.
                    // Make the next single-article action use the recovery
                    // path instead of repeating direct replacement.
                    $candidate['mode'] = 'incremental';
                    $incomplete_audit['term_count_failure'] = true;
                    $incomplete_audit['term_count_warning'] = false;
                    foreach ((array) ($incomplete_audit['unapplied'] ?? []) as &$unapplied_hit) {
                        $unapplied_hit['reason'] = '应用后英文译文中未发现目标术语，需重新增量翻译';
                    }
                    unset($unapplied_hit);
                    $incomplete_record['error'] = '应用后英文译文中未发现目标术语，需重新增量翻译';
                }
                $candidate['post_apply_audit'] = $incomplete_audit;
                $candidate['last_error'] = (string) ($incomplete_record['error'] ?? '增量翻译后仍有术语命中未形成稳定锚点');
            }
            $match = $successful_by_post[$post_id] ?? null;
            // Preserve historical successful applications unless their own
            // immutable post-apply audit explicitly recorded an incomplete
            // result.  Otherwise opening the preview after an older batch
            // would incorrectly turn all completed rows into new work.
            $is_applied = !$term_changed
                && $match
                && self::same_term_snapshot($current_snapshot, $match['snapshot'])
                && !isset($incomplete_by_post[$post_id]);
            if ($is_applied) {
                $candidate['status'] = 'applied';
                $applied_audit = is_array($match['record']['post_apply_audit'] ?? null)
                    ? $match['record']['post_apply_audit'] : [];
                // Backfill the visible-result audit for historical records
                // created before visible coverage was stored. This prevents
                // an old stable-anchor number such as 16/19 from being shown
                // as the current English result when the page is actually
                // 18/19 visible.
                if (self::needs_visible_comparison_audit($applied_audit)) {
                    $fresh_audit = self::audit_current_term_application($post_id, (string) ($term['term_id'] ?? ''));
                    if (is_array($fresh_audit)) $applied_audit = $fresh_audit;
                }
                $candidate['visible_applied_occurrences'] = (int) ($applied_audit['visible_applied_count']
                    ?? $match['record']['visible_applied_occurrences']
                    ?? $match['record']['applied_occurrences']
                    ?? ($applied_audit['stable_anchor_count'] ?? 0));
                $candidate['stable_anchor_occurrences'] = (int) ($applied_audit['stable_anchor_count']
                    ?? $match['record']['stable_anchor_occurrences']
                    ?? 0);
                $candidate['post_apply_audit'] = $applied_audit;
                $candidate['applied_at'] = (string) ($match['record']['time'] ?? ($match['batch']['updated_at'] ?? ''));
                $candidate['applied_batch_id'] = (string) ($match['batch']['batch_id'] ?? '');
                $candidate['applied_scope'] = (string) ($match['batch']['scope'] ?? 'global');
                $candidate['term_anomaly_units'] = (int) ($match['record']['term_anomaly_units'] ?? 0);
                $candidate['term_anomalies'] = is_array($match['record']['term_anomalies'] ?? null)
                    ? $match['record']['term_anomalies'] : [];
                $applied++;
            } elseif (!isset($incomplete_by_post[$post_id])) {
                $candidate['status'] = 'pending';
            }
        }
        unset($candidate);

        $pending = count((array) $candidates) - $applied;
        $latest = $latest_success ? $latest_success['batch'] : null;
        $state_status = $pending === 0 && ($applied > 0 || $latest_success)
            ? 'applied'
            : ($applied > 0 ? 'partial' : 'pending');
        if ($term_changed) $state_status = 'changed';

        return [
            'candidates' => $candidates,
            'state' => [
                'status' => $state_status,
                'term_changed' => $term_changed,
                'applied_articles' => $applied,
                'pending_articles' => $pending,
                'latest' => $latest ? [
                    'batch_id' => (string) ($latest['batch_id'] ?? ''),
                    'scope' => (string) ($latest['scope'] ?? 'global'),
                    'status' => (string) ($latest['status'] ?? ''),
                    'created_at' => (string) ($latest['created_at'] ?? ''),
                    'updated_at' => (string) ($latest['updated_at'] ?? ''),
                    'total_jobs' => (int) ($latest['total_jobs'] ?? 0),
                ] : null,
            ],
        ];
    }

    private static function normalize_term_snapshot($term, $include_current_revision = true) {
        if (!is_array($term) || !array_key_exists('zh', $term) || !array_key_exists('en', $term)) return null;
        $snapshot = [
            'zh' => (string) $term['zh'],
            'en' => (string) $term['en'],
        ];
        $term_id = (string) ($term['term_id'] ?? '');
        if ($include_current_revision) {
            $snapshot['term_revision'] = $term_id !== ''
                ? Lisun_Manual_Glossary::get_term_revision($term_id)
                : 0;
        }
        // 将来批次会带 term_revision；旧批次没有该字段时仍按 zh/en
        // 兼容比较，避免历史应用记录全部被误判为失效。
        if (isset($term['term_revision']) && (int) $term['term_revision'] > 0) {
            $snapshot['term_revision'] = (int) $term['term_revision'];
        }
        return $snapshot;
    }

    /** Snapshot persisted in a new application batch, including its term version. */
    private static function storage_term_snapshot($term) {
        $snapshot = [
            'term_id' => (string) ($term['term_id'] ?? ''),
            'zh' => (string) ($term['zh'] ?? ''),
            'en' => (string) ($term['en'] ?? ''),
        ];
        $term_revisions = get_option('lisun_glossary_term_revisions', []);
        $snapshot['term_revision'] = $snapshot['term_id'] !== ''
            ? Lisun_Manual_Glossary::get_term_revision($snapshot['term_id'])
            : 0;
        return $snapshot;
    }

    /** Whether an old audit needs the visible source/English comparison data. */
    private static function needs_visible_comparison_audit($audit) {
        if (!is_array($audit) || !array_key_exists('visible_applied_count', $audit)) return true;
        foreach ((array) ($audit['unapplied'] ?? []) as $row) {
            if (!array_key_exists('source_text', (array) $row)
                || !array_key_exists('english_text', (array) $row)) {
                return true;
            }
        }
        return false;
    }

    /** A zero-match result is a hard application failure, not a warning. */
    private static function is_hard_visible_failure($audit) {
        if (!is_array($audit)) return false;
        $expected = (int) ($audit['hit_count'] ?? 0);
        $visible = (int) ($audit['visible_applied_count'] ?? 0);
        $missing = (int) ($audit['visible_missing_count'] ?? $audit['unapplied_count'] ?? 0);
        return !empty($audit['term_count_failure'])
            || ($expected > 0 && $visible === 0 && $missing >= $expected);
    }

    private static function same_term_snapshot($left, $right) {
        if (!is_array($left) || !is_array($right)
            || (string) ($left['zh'] ?? '') !== (string) ($right['zh'] ?? '')
            || (string) ($left['en'] ?? '') !== (string) ($right['en'] ?? '')) {
            return false;
        }
        $left_revision = (int) ($left['term_revision'] ?? 0);
        $right_revision = (int) ($right['term_revision'] ?? 0);
        return $left_revision <= 0 || $right_revision <= 0 || $left_revision === $right_revision;
    }

    private static function has_manual_unlock($term_id, $revision) {
        $unlocks = get_option('lisun_glossary_manual_unlocks', []);
        if (!is_array($unlocks) || !is_array($unlocks[(string) $term_id] ?? null)) return false;
        $entry = $unlocks[(string) $term_id];
        $created = strtotime((string) ($entry['created_at'] ?? ''));
        return (int) ($entry['revision'] ?? -1) === (int) $revision
            && $created > 0
            && $created >= (time() - 1800);
    }

    private static function clear_manual_unlock($term_id) {
        $unlocks = get_option('lisun_glossary_manual_unlocks', []);
        if (!is_array($unlocks) || !array_key_exists((string) $term_id, $unlocks)) return;
        unset($unlocks[(string) $term_id]);
        update_option('lisun_glossary_manual_unlocks', $unlocks, false);
    }

    public static function get_batch_status($batch_id) {
        $batch = self::get_batch($batch_id);
        if (!$batch) return new WP_Error('glossary_batch_missing', '批次不存在');
        // Reclaim a dead worker before calculating the batch status, so the
        // glossary modal cannot remain in "处理中" forever.
        Lisun_Manual_Translate::recover_stale_queue_jobs();
        global $wpdb;
        $queue = $wpdb->prefix . 'lisun_translate_queue';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, error_msg, result_json FROM {$queue} WHERE batch_id = %s AND action = 'glossary_apply' ORDER BY id ASC",
            $batch_id
        ), ARRAY_A);
        $counts = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'warnings' => 0];
        $local_only = 0;
        foreach ((array) $rows as $row) {
            $status = (string) ($row['status'] ?? 'failed');
            $result = json_decode((string) ($row['result_json'] ?? ''), true);
            $result = is_array($result) ? $result : [];
            if (self::is_success_with_warning($status, $result)) {
                $status = 'done';
                $counts['warnings']++;
            }
            if ($status === 'done' && self::is_hard_visible_failure($result['post_apply_audit'] ?? null)) {
                $status = 'failed';
            }
            // Older workers marked a local-update/remote-push failure as
            // done. Reconcile that persisted result as failed immediately.
            $is_local_only = !empty($result['local_updated']) && empty($result['pushed']);
            if ($is_local_only && $status === 'done') $status = 'failed';
            if (isset($counts[$status])) $counts[$status]++;
            if ($is_local_only) $local_only++;
        }
        $status = self::sync_batch_status($batch_id, $counts);
        $details = json_decode((string) ($batch['details_json'] ?? ''), true) ?: [];
        $details['applications'] = self::hydrate_applications($batch_id, $details);
        return [
            'batch_id' => $batch_id,
            'status' => $status,
            'counts' => $counts + ['skipped' => (int) $batch['skipped_jobs'], 'local_only' => $local_only],
            'details' => $details,
        ];
    }

    /** Lightweight per-term status for the Glossary list. */
    public static function latest_for_term($term_id) {
        if (isset(self::$latest_cache[$term_id])) return self::$latest_cache[$term_id];
        $list_cache = self::get_list_state_cache();
        $row = $list_cache['latest_by_term'][(string) $term_id] ?? null;
        if (!$row) {
            $term = Lisun_Manual_Glossary::get_by_id()[(string) $term_id] ?? null;
            if (is_array($term) && !empty($term['zh']) && !empty($term['en'])) {
                $snapshot_key = self::list_snapshot_key($term);
                $row = $list_cache['latest_by_snapshot'][$snapshot_key] ?? null;
            }
        }
        if ($row && !empty($row['batch_id'])
            && in_array((string) ($row['status'] ?? ''), ['queued', 'processing', 'running'], true)) {
            // Reconcile old false-failure batches before the glossary list
            // reads their status, so pushed warning-only work is not shown as
            // a partial failure.
            self::sync_batch_status((string) $row['batch_id']);
            $row = self::get_batch((string) $row['batch_id']) ?: $row;
        }
        // 列表上的“最近应用”不能只看批次状态。术语中文/英文已变化时，
        // 旧批次仍然是历史记录，但不再代表当前术语已应用；显示为待重新
        // 应用，避免用户看到“已完成”却无法判断为什么需要再次处理。
        if ($row && in_array((string) ($row['status'] ?? ''), ['done', 'finished_with_errors'], true)) {
            $current = Lisun_Manual_Glossary::get_by_id()[(string) $term_id] ?? null;
            $snapshot = self::batch_term_snapshot($row);
            if (is_array($current) && $snapshot
                && !self::same_term_snapshot(
                    self::normalize_term_snapshot($current),
                    self::normalize_term_snapshot($snapshot, false)
                )) {
                $row['status_before_term_change'] = (string) $row['status'];
                $row['status'] = 'stale';
                $row['term_changed'] = true;
            }
        }
        // 历史批次可能因早期版本 bug 把 total_jobs 写成 0，但队列里实际有任务。
        // 这里用队列行数 + details_json 里的候选文章数回填，避免列表上显示
        // “已完成 · 0 篇”而展开后有 N 篇。
        if ($row && (int) ($row['total_jobs'] ?? 0) === 0 && !empty($row['batch_id'])) {
            // Do not query the translation queue for every glossary row just
            // to repair a display count. The list can derive this fallback
            // from the batch JSON; the worker remains responsible for the
            // persisted total.
            $details = json_decode((string) ($row['details_json'] ?? ''), true);
            if (is_array($details)) {
                $real = is_array($details['applications'] ?? null)
                    ? count($details['applications'])
                    : (is_array($details['candidates'] ?? null) ? count($details['candidates']) : 0);
                if ($real > 0) $row['total_jobs'] = $real;
            }
        }

        // The latest batch is not necessarily the current state: an undo can
        // target one article in that batch, and old batches may only contain
        // candidates instead of application rows. Expose the same
        // post-deduplicated state used by the expanded history so the AJAX
        // list cannot resurrect the batch total (for example, 4 after one
        // of 4 articles was undone).
        if ($row) {
            $current_applied_count = self::list_current_applied_count((string) $term_id, $row);
            $row['current_applied_count'] = $current_applied_count;
            if ($current_applied_count === 0
                && in_array((string) ($row['status'] ?? ''), ['done', 'finished_with_errors'], true)) {
                $row['current_status'] = 'stale';
            } else {
                $row['current_status'] = (string) ($row['status'] ?? '');
            }
        }

        self::$latest_cache[$term_id] = $row ?: null;
        return self::$latest_cache[$term_id];
    }

    /** 统计该术语所有批次累计去重后的文章数。 */
    public static function count_applied_posts($term_id) {
        $history = self::history((string) $term_id, 100);
        $posts = [];
        $seen = [];
        foreach ($history as $h) {
            foreach (($h['applications'] ?? []) as $app) {
                $pid = (int) ($app['post_id'] ?? 0);
                if ($pid <= 0 || isset($seen[$pid])) continue;
                // history() is newest-first; an undone newest record is the
                // current state and must hide older successful records.
                $seen[$pid] = true;
                // An undone application remains in the audit trail, but it
                // must not count as a currently applied article.
                if (!empty($app['undone'])) continue;
                $posts[$pid] = true;
            }
        }
        return count($posts);
    }

    /**
     * Cheap current-state count for the glossary list.
     *
     * The expanded history intentionally performs a full audit. The list
     * must not do that once per term, otherwise one page creates an N+1 query
     * storm. This index reads batch JSON once per request and applies the
     * persisted batch-level undo markers without loading V2 revisions.
     */
    private static function list_current_applied_count($term_id, $latest_row = null) {
        $cache = self::get_list_state_cache();
        $term_id = (string) $term_id;
        $group = '';
        if (is_array($latest_row)) {
            $snapshot = self::batch_term_snapshot($latest_row);
            if ($snapshot) $group = self::list_snapshot_key($snapshot);
        }
        if ($group === '') $group = 'id:' . $term_id;
        if (isset($cache['counts'][$group])) return (int) $cache['counts'][$group];
        if (isset($cache['counts']['id:' . $term_id])) return (int) $cache['counts']['id:' . $term_id];
        return 0;
    }

    /** Build the lightweight list index once per request. */
    private static function get_list_state_cache() {
        if (is_array(self::$list_state_cache)) return self::$list_state_cache;

        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT batch_id, term_id, status, total_jobs, skipped_jobs, details_json, created_at, updated_at
             FROM ' . self::table_name() . ' ORDER BY created_at DESC LIMIT 1000',
            ARRAY_A
        );
        $global_undo = [];
        $groups = [];
        $latest_by_term = [];
        $latest_by_snapshot = [];

        foreach ((array) $rows as $row) {
            $term_id = (string) ($row['term_id'] ?? '');
            $details = json_decode((string) ($row['details_json'] ?? ''), true);
            $details = is_array($details) ? $details : [];
            $snapshot = self::batch_term_snapshot($row);
            $group = $snapshot ? self::list_snapshot_key($snapshot) : ('id:' . $term_id);
            if ($group === '') $group = 'id:' . $term_id;

            if ($term_id !== '' && !isset($latest_by_term[$term_id])) {
                $latest_by_term[$term_id] = $row;
            }
            if ($snapshot && !isset($latest_by_snapshot[$group])) {
                $latest_by_snapshot[$group] = $row;
            }
            $groups[$group] = true;

            foreach ((array) ($details['undone_posts'] ?? []) as $post_id => $marker) {
                if (!is_array($marker) || empty($marker['undone'])) continue;
                $post_id = (string) absint($post_id);
                if ($post_id === '0') continue;
                $global_undo[$group][$post_id][] = $marker + [
                    'batch_id' => (string) ($marker['batch_id'] ?? ($row['batch_id'] ?? '')),
                ];
            }
        }

        $seen = [];
        $counts = [];
        foreach ((array) $rows as $row) {
            $details = json_decode((string) ($row['details_json'] ?? ''), true);
            $details = is_array($details) ? $details : [];
            $snapshot = self::batch_term_snapshot($row);
            $group = $snapshot ? self::list_snapshot_key($snapshot) : ('id:' . (string) ($row['term_id'] ?? ''));
            if ($group === '') $group = 'id:' . (string) ($row['term_id'] ?? '');
            $apps = is_array($details['applications'] ?? null) ? $details['applications'] : [];
            if (!$apps && !empty($details['candidates'])) {
                $processed = !empty($details['processed_post_ids'])
                    ? array_fill_keys(array_map('intval', (array) $details['processed_post_ids']), true)
                    : [];
                foreach ((array) $details['candidates'] as $candidate) {
                    $post_id = (int) ($candidate['post_id'] ?? 0);
                    if ($post_id <= 0 || ($processed && !isset($processed[$post_id]))) continue;
                    $apps[] = [
                        'post_id' => $post_id,
                        'status' => 'done',
                    ];
                }
            }
            foreach ($apps as $app) {
                $post_id = (int) ($app['post_id'] ?? 0);
                if ($post_id <= 0 || isset($seen[$group][$post_id])) continue;
                $seen[$group][$post_id] = true;
                $undone = !empty($app['undone'])
                    || !empty($details['undone_posts'][(string) $post_id]['undone']);
                foreach ((array) ($global_undo[$group][$post_id] ?? []) as $marker) {
                    $same_batch = (string) ($marker['batch_id'] ?? '') !== ''
                        && (string) $marker['batch_id'] === (string) ($row['batch_id'] ?? '');
                    $marker_revision = (int) ($marker['applied_revision_id'] ?? 0);
                    $app_revision = (int) ($app['applied_revision_id'] ?? 0);
                    $same_revision = $marker_revision > 0 && $app_revision > 0 && $marker_revision === $app_revision;
                    if ($same_batch || $same_revision) $undone = true;
                }
                $status = (string) ($app['status'] ?? 'done');
                $local_only = !empty($app['local_only'])
                    || ($status === 'done' && array_key_exists('pushed', $app) && empty($app['pushed']));
                if (!$undone && $status !== 'failed' && !$local_only) {
                    $counts[$group] = (int) ($counts[$group] ?? 0) + 1;
                }
            }
        }

        self::$list_state_cache = [
            'counts' => $counts,
            'latest_by_term' => $latest_by_term,
            'latest_by_snapshot' => $latest_by_snapshot,
        ];
        return self::$list_state_cache;
    }

    private static function list_snapshot_key($snapshot) {
        if (!is_array($snapshot)) return '';
        $zh = trim((string) ($snapshot['zh'] ?? ''));
        $en = trim((string) ($snapshot['en'] ?? ''));
        return $zh !== '' || $en !== '' ? 'snapshot:' . $zh . "\n" . $en : '';
    }

    /** Match a legacy batch by the immutable human-readable term snapshot. */
    private static function batch_matches_term_snapshot($row, $term_id) {
        if ((string) ($row['term_id'] ?? '') === (string) $term_id) return true;
        $current = Lisun_Manual_Glossary::get_by_id()[(string) $term_id] ?? null;
        $snapshot = self::batch_term_snapshot($row);
        if (!is_array($current) || !$snapshot) return false;
        return (string) ($snapshot['zh'] ?? '') === (string) ($current['zh'] ?? '')
            && (string) ($snapshot['en'] ?? '') === (string) ($current['en'] ?? '');
    }

    private static function batch_term_snapshot($row) {
        $details = json_decode((string) ($row['details_json'] ?? ''), true);
        if (!is_array($details) || !is_array($details['term'] ?? null)) return null;
        return $details['term'];
    }

    private static function matching_units($active, $matcher, $term_id, $include_degraded = false) {
        $matches = [];
        foreach ((array) $active['units'] as $unit) {
            if (!$include_degraded && (string) ($unit['status'] ?? '') === 'degraded') continue;
            $sequence = 0;
            $parts = $matcher->match((string) ($unit['source_text'] ?? ''), (string) ($unit['unit_key'] ?? ''), $sequence,
                (string) (($unit['field_name'] ?? '') === 'title' ? 'title' : 'body'));
            foreach ($parts as $part) {
                if (($part['type'] ?? '') === 'term' && (string) ($part['term_id'] ?? '') === $term_id) {
                    $matches[] = $unit;
                    break;
                }
            }
        }
        return $matches;
    }

    /**
     * Reconcile current source hits with the V2 occurrence anchors that can
     * actually be applied. The old preview only counted both sides separately,
     * which made unmatched hits disappear without an explanation.
     */
    private static function audit_term_matches($scan_matches, $occurrences, $unit_status, $units_by_key) {
        $available = [];
        foreach ((array) $occurrences as $occurrence) {
            $key = (string) ($occurrence['unit_key'] ?? '') . '|' . self::normalize_source_key($occurrence['source_text'] ?? '');
            $available[$key] = (int) ($available[$key] ?? 0) + 1;
        }
        $unapplied = [];
        $matched = 0;
        foreach ((array) $scan_matches as $hit) {
            $unit_key = (string) ($hit['unit_key'] ?? '');
            $key = $unit_key . '|' . self::normalize_source_key($hit['matched'] ?? '');
            if (!empty($available[$key])) {
                $available[$key]--;
                $matched++;
                continue;
            }
            if (!array_key_exists($unit_key, $units_by_key)) {
                $reason = '当前译文没有对应的 V2 翻译单元';
            } elseif (($unit_status[$unit_key] ?? '') === 'degraded') {
                $reason = '翻译单元已降级，缺少稳定术语锚点';
            } else {
                $reason = '当前翻译单元没有保存该术语锚点，需增量翻译';
            }
            $unapplied[] = [
                'unit_key' => $unit_key,
                'unit_type' => (string) ($hit['unit_type'] ?? 'text'),
                'source_path' => (string) ($hit['source_path'] ?? ''),
                'matched' => (string) ($hit['matched'] ?? ''),
                'context' => (string) ($hit['context'] ?? ''),
                'reason' => $reason,
            ];
        }
        return [
            'hit_count' => count((array) $scan_matches),
            'stable_anchor_count' => $matched,
            'unapplied_count' => count($unapplied),
            'unapplied' => $unapplied,
        ];
    }

    /**
     * Split current term hits into safe direct replacements and hits covered
     * by units that will be incrementally retranslated.  A unit may contain
     * several hits, so the two counts must not be inferred from unit count.
     */
    private static function term_application_counts($scan_matches, $occurrences, $unit_status, $units_by_key, $retranslation_unit_keys) {
        $retranslation_unit_keys = array_fill_keys(array_filter(array_map('strval', (array) $retranslation_unit_keys)), true);
        $direct_scan_matches = [];
        $incremental_occurrences = 0;
        foreach ((array) $scan_matches as $hit) {
            if (isset($retranslation_unit_keys[(string) ($hit['unit_key'] ?? '')])) {
                $incremental_occurrences++;
            } else {
                $direct_scan_matches[] = $hit;
            }
        }

        $direct_occurrences = array_values(array_filter((array) $occurrences, function ($occurrence) use ($retranslation_unit_keys) {
            return !isset($retranslation_unit_keys[(string) ($occurrence['unit_key'] ?? '')]);
        }));
        $direct_audit = self::audit_term_matches(
            $direct_scan_matches,
            $direct_occurrences,
            $unit_status,
            $units_by_key
        );

        return [
            'direct_occurrences' => (int) ($direct_audit['stable_anchor_count'] ?? 0),
            'incremental_occurrences' => $incremental_occurrences,
        ];
    }

    /** Build the same trace shape for units that must be incrementally translated. */
    private static function audit_incremental_matches($scan_matches, $unit_keys, $reason) {
        $unit_keys = array_fill_keys(array_filter(array_map('strval', (array) $unit_keys)), true);
        $unapplied = [];
        foreach ((array) $scan_matches as $hit) {
            if (!isset($unit_keys[(string) ($hit['unit_key'] ?? '')])) continue;
            $unapplied[] = [
                'unit_key' => (string) ($hit['unit_key'] ?? ''),
                'unit_type' => (string) ($hit['unit_type'] ?? 'text'),
                'source_path' => (string) ($hit['source_path'] ?? ''),
                'matched' => (string) ($hit['matched'] ?? ''),
                'context' => (string) ($hit['context'] ?? ''),
                'reason' => (string) $reason,
            ];
        }
        return [
            'hit_count' => count((array) $scan_matches),
            'stable_anchor_count' => 0,
            'unapplied_count' => count($unapplied),
            'unapplied' => $unapplied,
        ];
    }

    /** Verify the term occurrence count after a job has committed its revision. */
    private static function audit_current_term_application($post_id, $term_id) {
        $post = get_post((int) $post_id);
        $term = Lisun_Manual_Glossary::get_by_id()[(string) $term_id] ?? null;
        $active = Lisun_Manual_V2_Repository::get_active_snapshot((int) $post_id);
        if (!$post || !is_array($term) || !$active) return null;

        $matcher = new Lisun_Manual_V2_Term_Matcher(Lisun_Manual_Glossary::get_all());
        $scan = self::scan_post_term($post, $matcher, (string) $term_id);
        $unit_status = [];
        $units_by_key = [];
        foreach ((array) ($active['units'] ?? []) as $unit) {
            $key = (string) ($unit['unit_key'] ?? '');
            $unit_status[$key] = (string) ($unit['status'] ?? 'translated');
            $units_by_key[$key] = $unit;
        }
        $source_key = self::normalize_source_key($term['zh'] ?? '');
        $occurrences = array_values(array_filter((array) ($active['occurrences'] ?? []), function ($occurrence) use ($term_id, $source_key, $unit_status) {
            $key = (string) ($occurrence['unit_key'] ?? '');
            return ($unit_status[$key] ?? '') !== 'degraded'
                && ((string) ($occurrence['term_id'] ?? '') === (string) $term_id
                    || self::normalize_source_key($occurrence['source_text'] ?? '') === $source_key);
        }));
        $audit = self::audit_term_matches($scan['matches'], $occurrences, $unit_status, $units_by_key);
        $visible = self::audit_visible_term_application($active, $term, $scan['matches'], (int) $post_id);
        $audit['visible_applied_count'] = (int) ($visible['visible_applied_count'] ?? 0);
        $audit['visible_missing_count'] = (int) ($visible['visible_missing_count'] ?? 0);
        $audit['unanchored_count'] = (int) ($audit['unapplied_count'] ?? 0);
        $audit['unanchored'] = (array) ($audit['unapplied'] ?? []);
        // The user-facing "unapplied" list must describe visible English
        // omissions, not merely occurrences that lost a database anchor.
        $audit['unapplied'] = (array) ($visible['unapplied'] ?? []);
        $audit['unapplied_count'] = (int) ($visible['visible_missing_count'] ?? 0);
        // Keep the same warning fields for newly audited historical records
        // as for a just-finished application; otherwise 19/20 can be shown
        // without the corresponding review prompt.
        $audit['term_count_warning'] = $audit['visible_missing_count'] > 0;
        $audit['term_count_warning_count'] = (int) $audit['visible_missing_count'];
        return $audit;
    }

    /**
     * Count the current target wording in each translated unit.  This is the
     * visible-result check: an occurrence may have lost its V2 anchor while
     * the English wording is still present in the published paragraph.
     */
    private static function audit_visible_term_application($active, $term, $scan_matches, $post_id = 0) {
        $hits_by_unit = [];
        foreach ((array) $scan_matches as $hit) {
            $key = (string) ($hit['unit_key'] ?? '');
            if ($key !== '') $hits_by_unit[$key][] = $hit;
        }

        $occurrences_by_unit = [];
        foreach ((array) ($active['occurrences'] ?? []) as $occurrence) {
            $key = (string) ($occurrence['unit_key'] ?? '');
            if ($key !== '') $occurrences_by_unit[$key][] = $occurrence;
        }

        $target = (string) ($term['en'] ?? '');
        $remote_edit_url = self::remote_edit_url($post_id);
        $visible_applied = 0;
        $visible_missing = 0;
        $unapplied = [];
        foreach ($hits_by_unit as $unit_key => $hits) {
            $unit = null;
            foreach ((array) ($active['units'] ?? []) as $candidate) {
                if ((string) ($candidate['unit_key'] ?? '') === $unit_key) {
                    $unit = $candidate;
                    break;
                }
            }

            $actual = 0;
            $english_text = '';
            if (is_array($unit)) {
                $field = (string) ($unit['field_name'] ?? 'content');
                $rendered = Lisun_Manual_V2_Materializer::materialize_unit(
                    (string) ($unit['target_template'] ?? ''),
                    $occurrences_by_unit[$unit_key] ?? [],
                    [],
                    $field,
                    Lisun_Manual_V2_Materializer::MODE_SNAPSHOT
                );
                if (!is_wp_error($rendered)) {
                    $english_text = html_entity_decode(
                        wp_strip_all_tags((string) $rendered),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    );
                    $actual = Lisun_Manual_V2_Term_Boundary::count_target_occurrences($english_text, $target);
                }
            }

            $expected = count($hits);
            $applied = min($expected, max(0, (int) $actual));
            $missing = max(0, $expected - $applied);
            $visible_applied += $applied;
            $visible_missing += $missing;
            if ($missing > 0) {
                foreach (array_slice($hits, -$missing) as $hit) {
                    $hit['source_text'] = is_array($unit) ? (string) ($unit['source_text'] ?? '') : '';
                    $hit['english_text'] = $english_text;
                    $hit['target_term'] = $target;
                    $hit['expected_count'] = $expected;
                    $hit['actual_count'] = $actual;
                    $hit['missing_count'] = $missing;
                    $hit['remote_edit_url'] = $remote_edit_url;
                    $hit['reason'] = '英文段落中目标术语出现 ' . $actual . '/' . $expected
                        . ' 次，可能是自然表达差异，请人工核对（不等同于漏译）';
                    $unapplied[] = $hit;
                }
            }
        }

        return [
            'visible_applied_count' => $visible_applied,
            'visible_missing_count' => $visible_missing,
            'unapplied' => $unapplied,
        ];
    }

    /** Build the remote English site's backend edit URL for a source article. */
    private static function remote_edit_url($post_id) {
        $post_id = absint($post_id);
        $remote_id = $post_id > 0 ? absint(get_post_meta($post_id, '_lisun_manual_remote_id', true)) : 0;
        $site_url = trim((string) get_option('lisun_en_site_url', ''));
        if (!$remote_id || $site_url === '') return '';
        return trailingslashit(untrailingslashit($site_url))
            . 'wp-admin/post.php?post=' . $remote_id . '&action=edit';
    }

    /**
     * Verify the actual committed V2 revision, not merely the queue result.
     * A zero-visible result is an incomplete application; partial visible
     * coverage remains a warning because English may naturally omit repeats.
     */
    public static function verify_post_application($post_id, $term_id) {
        $active = Lisun_Manual_V2_Repository::get_active_snapshot((int) $post_id);
        if (!$active) return null;
        $audit = self::audit_current_term_application((int) $post_id, (string) $term_id);
        if (!is_array($audit)) return null;

        $expected = (int) ($audit['hit_count'] ?? 0);
        $visible_applied = (int) ($audit['visible_applied_count'] ?? 0);
        $stable_anchors = (int) ($audit['stable_anchor_count'] ?? 0);
        $missing = (int) ($audit['visible_missing_count'] ?? $audit['unapplied_count'] ?? 0);
        // If none of the expected target terms is present, this is not a
        // natural wording difference: the application produced no usable
        // glossary result and must be retried as an incremental translation.
        if ($expected > 0 && $visible_applied === 0 && $missing >= $expected) {
            $audit['term_count_failure'] = true;
            $audit['term_count_warning'] = false;
            $audit['term_count_warning_count'] = $missing;
            $audit['incomplete'] = true;
            foreach ((array) ($audit['unapplied'] ?? []) as &$unapplied_hit) {
                $unapplied_hit['reason'] = '应用后英文译文中未发现目标术语，需重新增量翻译';
            }
            unset($unapplied_hit);
            return new WP_Error(
                'glossary_apply_incomplete',
                '应用后英文译文中未发现目标术语，需重新增量翻译',
                ['post_apply_audit' => $audit, 'incomplete' => true]
            );
        }
        // A wording/count difference is not proof of a failed translation:
        // English may naturally omit a repeated term. Keep the application
        // successful and expose this only as a review warning.
        $audit['term_count_warning'] = $expected > 0 && $missing > 0;
        $audit['term_count_warning_count'] = $missing;
        $audit['anchor_incomplete'] = $expected > 0 && $stable_anchors < $expected;
        return $audit;
    }

    /** Return active units whose local term slot does not contain the term. */
    private static function invalid_term_units($active, $term_id, $glossary, $source_key = '') {
        $by_unit = [];
        foreach ((array) ($active['occurrences'] ?? []) as $occurrence) {
            $key = (string) ($occurrence['unit_key'] ?? '');
            if ($key !== '') $by_unit[$key][] = $occurrence;
        }
        $invalid = [];
        foreach ((array) ($active['units'] ?? []) as $unit) {
            if ((string) ($unit['status'] ?? '') === 'degraded') continue;
            $key = (string) ($unit['unit_key'] ?? '');
            $unit['occurrences'] = $by_unit[$key] ?? [];
            $analysis = Lisun_Manual_V2_Term_Boundary::analyze_unit($unit, $glossary);
            if (empty($analysis['retranslation_required'])) continue;
            foreach ((array) $unit['occurrences'] as $occurrence) {
                if ((string) ($occurrence['term_id'] ?? '') === (string) $term_id
                    || ($source_key !== '' && self::normalize_source_key($occurrence['source_text'] ?? '') === $source_key)) {
                    $invalid[$key] = true;
                    break;
                }
            }
        }
        return array_keys($invalid);
    }

    private static function source_contains_term($post, $matcher, $term_id) {
        $scan = self::scan_post_term($post, $matcher, $term_id);
        return !empty($scan['matches']);
    }

    /** Scan current Chinese source and return human-readable hit contexts. */
    private static function scan_post_term($post, $matcher, $term_id) {
        $units = [Lisun_Manual_V2_Unit_Extractor::extract_plain((string) $post->post_title, 'title', 'title')];
        $content = Lisun_Manual_V2_Unit_Extractor::extract((string) $post->post_content, 'content');
        if (!is_wp_error($content)) {
            $units = array_merge($units, (array) ($content['units'] ?? []));
        }

        $matches = [];
        foreach ($units as $unit) {
            $source = (string) ($unit['source_text'] ?? '');
            $sequence = 0;
            $context = (string) (($unit['field'] ?? '') === 'title' ? 'title' : 'body');
            foreach ($matcher->match($source, (string) ($unit['unit_key'] ?? ''), $sequence, $context) as $part) {
                if (($part['type'] ?? '') !== 'term' || (string) ($part['term_id'] ?? '') !== $term_id) continue;
                $offset = max(0, (int) ($part['source_offset'] ?? 0));
                $matched = (string) ($part['source_text'] ?? '');
                $before_len = 70;
                $after_len = 90;
                $before = mb_substr($source, max(0, $offset - $before_len), $before_len);
                $after = mb_substr($source, $offset + mb_strlen($matched), $after_len);
                $prefix = $offset > $before_len ? '…' : '';
                $suffix = mb_strlen($source) > $offset + mb_strlen($matched) + $after_len ? '…' : '';
                $matches[] = [
                    'unit_key' => (string) ($unit['unit_key'] ?? ''),
                    'unit_type' => (string) ($unit['unit_type'] ?? 'text'),
                    'source_path' => (string) ($unit['source_path'] ?? ''),
                    'matched' => $matched,
                    'context' => $prefix . $before . '【' . $matched . '】' . $after . $suffix,
                ];
            }
        }
        return ['matches' => $matches];
    }

    private static function source_hash_matches($post, $active) {
        $current = hash('sha256', (string) $post->post_content . "\nTITLE\n" . (string) $post->post_title);
        return hash_equals((string) ($active['revision']['source_hash'] ?? ''), $current);
    }

    /** Normalize source text consistently with the V2 glossary recovery path. */
    private static function normalize_source_key($text) {
        $normalized = preg_replace('/\s+/u', ' ', (string) $text);
        return trim($normalized === null ? (string) $text : $normalized);
    }

    private static function insert_batch($batch_id, $preview, $total_jobs, $meta = []) {
        global $wpdb;
        $inserted = $wpdb->insert(self::table_name(), [
            'batch_id' => $batch_id,
            'term_id' => (string) $preview['term']['term_id'],
            'glossary_revision' => (int) $preview['glossary_revision'],
            'status' => $total_jobs ? 'queued' : 'done',
            'total_jobs' => $total_jobs,
            'skipped_jobs' => count($preview['skipped']),
            'details_json' => wp_json_encode([
                'term' => $preview['term'],
                'skipped' => $preview['skipped'],
                'candidates' => $preview['candidates'],
                'scope' => (string) ($meta['scope'] ?? ($preview['scope'] ?? 'global')),
                'post_id' => absint($meta['post_id'] ?? ($preview['post_id'] ?? 0)),
                'manual_unlock' => !empty($meta['manual_unlock']),
                'processed_post_ids' => isset($meta['processed_post_ids']) ? array_map('intval', $meta['processed_post_ids']) : [],
            ]),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        if (!$inserted) {
            return new WP_Error(
                'glossary_batch_insert_failed',
                '应用记录创建失败：' . ($wpdb->last_error ?: '术语应用记录表不可用，请重新加载插件')
            );
        }
        return true;
    }

    /** Mark each application with undo availability and any overwrite warning. */
    private static function enrich_undo_state($applications) {
        if (!is_array($applications)) return [];
        foreach ($applications as $i => $app) {
            $post_id = (int) ($app['post_id'] ?? 0);
            $applied_rev = (int) ($app['applied_revision_id'] ?? 0);
            $status = (string) ($app['status'] ?? '');
            $applications[$i]['undo_available'] = false;
            $applications[$i]['undo_reason'] = '';
            $applications[$i]['undo_warning'] = '';
            if ($status !== 'done') {
                $applications[$i]['undo_reason'] = '未成功应用';
                continue;
            }
            if (!empty($app['undone'])) {
                $applications[$i]['undo_reason'] = '已撤销';
                continue;
            }
            if ($applied_rev <= 0) {
                // 旧批次没存 applied_revision_id：兜底用当前 active revision。
                // 旧批次没有应用版本号时，仍以当前活动版本作为兜底。
                $snapshot = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
                $applied_rev = $snapshot ? (int) ($snapshot['revision']['id'] ?? 0) : 0;
                $applications[$i]['applied_revision_id'] = $applied_rev;
                if ($applied_rev <= 0) {
                    $applications[$i]['undo_reason'] = '无版本数据';
                    continue;
                }
            }
            if (Lisun_Manual_Translate::is_en_locked($post_id)) {
                $applications[$i]['undo_reason'] = '页面已锁定';
                continue;
            }
            $snapshot = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
            $current_active = $snapshot ? (int) ($snapshot['revision']['id'] ?? 0) : 0;
            if ($current_active !== $applied_rev) {
                // 不再禁止撤销：用户可能正是要撤回错误应用的术语。
                // 但必须在前端明确提示，恢复前置版本会覆盖应用后的译文。
                $applications[$i]['undo_warning'] = '应用后已有新翻译，撤销会恢复应用前版本并覆盖之后的译文';
            }
            $prev = Lisun_Manual_V2_Repository::previous_revision_id($post_id, $applied_rev);
            if ($prev <= 0) {
                $applications[$i]['undo_reason'] = '无前置版本';
                continue;
            }
            $applications[$i]['undo_available'] = true;
        }
        return $applications;
    }

    /** Rebuild details for batches created before per-job auditing was added. */
    private static function hydrate_applications($batch_id, $details) {
        $existing = is_array($details['applications'] ?? null) ? $details['applications'] : [];
        global $wpdb;
        $queue = $wpdb->prefix . 'lisun_translate_queue';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, status, payload, result_json, error_msg, updated_at
             FROM {$queue} WHERE batch_id = %s AND action = 'glossary_apply' ORDER BY id ASC",
            (string) $batch_id
        ), ARRAY_A);
        if (!$rows) return $existing;

        $by_post = [];
        foreach ($existing as $application) {
            $application = self::normalize_warning_application_record($application);
            $by_post[(int) ($application['post_id'] ?? 0)] = $application;
        }
        foreach ($rows as $row) {
            $post_id = (int) ($row['post_id'] ?? 0);
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $result = json_decode((string) ($row['result_json'] ?? ''), true);
            $result = is_array($result) ? $result : [];
            $status = (string) ($row['status'] ?? 'failed');
            $warning_only = self::is_success_with_warning($status, $result);
            if ($warning_only) $status = 'done';
            $hard_visible_failure = $status === 'done'
                && self::is_hard_visible_failure($result['post_apply_audit'] ?? null);
            if ($hard_visible_failure) $status = 'failed';
            $is_local_only = !empty($result['local_updated']) && empty($result['pushed']);
            if ($is_local_only && $status === 'done') $status = 'failed';
            if (isset($by_post[$post_id]) && !empty($by_post[$post_id]['time'])) {
                // Existing audit records may predate structured error fields;
                // enrich them from the queue row without replacing the rest
                // of the historical record.
                if ($is_local_only) {
                    $by_post[$post_id]['status'] = 'failed';
                    $by_post[$post_id]['local_only'] = true;
                    $by_post[$post_id]['pushed'] = false;
                    $by_post[$post_id]['error_code'] = 'push_failed';
                    $by_post[$post_id]['error'] = (string) (($result['message'] ?? '') ?: ($result['push_msg'] ?? ($row['error_msg'] ?? '远程英文站推送失败，本地译文已更新但尚未推送')));
                }
                if ($warning_only) {
                    $by_post[$post_id]['status'] = 'done';
                    $by_post[$post_id]['pushed'] = true;
                    $by_post[$post_id]['warning_only'] = true;
                    $by_post[$post_id]['error'] = '';
                    if (is_array($result['post_apply_audit'] ?? null)) {
                        $by_post[$post_id]['post_apply_audit'] = $result['post_apply_audit'];
                    }
                    if (isset($result['revision_id'])) $by_post[$post_id]['applied_revision_id'] = (int) $result['revision_id'];
                    if (isset($result['visible_applied_occurrences'])) $by_post[$post_id]['visible_applied_occurrences'] = (int) $result['visible_applied_occurrences'];
                    if (isset($result['stable_anchor_occurrences'])) $by_post[$post_id]['stable_anchor_occurrences'] = (int) $result['stable_anchor_occurrences'];
                }
                if (is_array($result['post_apply_audit'] ?? null)) {
                    $by_post[$post_id]['post_apply_audit'] = $result['post_apply_audit'];
                }
                if ($hard_visible_failure) {
                    $by_post[$post_id]['status'] = 'failed';
                    $by_post[$post_id]['pushed'] = false;
                    $by_post[$post_id]['warning_only'] = false;
                    $by_post[$post_id]['error_code'] = 'glossary_apply_incomplete';
                    $by_post[$post_id]['error'] = '应用后英文译文中未发现目标术语，需重新增量翻译';
                }
                if ($status === 'failed' && !$warning_only) {
                    if (!empty($result['code'])) $by_post[$post_id]['error_code'] = (string) $result['code'];
                    if (!empty($result['unit_key'])) $by_post[$post_id]['unit_key'] = (string) $result['unit_key'];
                    if (array_key_exists('batch_index', $result)) $by_post[$post_id]['batch_index'] = (int) $result['batch_index'];
                    if (!empty($result['trace_id'])) $by_post[$post_id]['trace_id'] = (string) $result['trace_id'];
                    if (!empty($result['occurrences']) && is_array($result['occurrences'])) {
                        $by_post[$post_id]['error_occurrences'] = $result['occurrences'];
                    }
                }
                continue;
            }
            $by_post[$post_id] = [
                'post_id' => $post_id,
                'title' => get_the_title($post_id) ?: ('#' . $post_id),
                'term_id' => (string) ($payload['term_id'] ?? ''),
                'mode' => (string) ($payload['mode'] ?? 'direct'),
                'status' => $status === 'done' ? 'done' : (($status === 'failed') ? 'failed' : 'processing'),
                'updated' => (int) ($result['updated'] ?? 0),
                'translated_units' => (int) ($result['translated_units'] ?? 0),
                'reused_units' => (int) ($result['reused_units'] ?? 0),
                'occurrences' => (int) ($result['occurrences'] ?? ($result['anchors'] ?? 0)),
                'requests' => (int) ($result['requests'] ?? 0),
                'pushed' => !empty($result['pushed']) && $status === 'done',
                'error' => (string) (($result['message'] ?? '') ?: ($row['error_msg'] ?? '')),
                'time' => (string) ($row['updated_at'] ?? ''),
            ];
            if (is_array($result['post_apply_audit'] ?? null)) {
                $by_post[$post_id]['post_apply_audit'] = $result['post_apply_audit'];
            }
            if ($warning_only) {
                $by_post[$post_id]['warning_only'] = true;
                $by_post[$post_id]['error'] = '';
                if (is_array($result['post_apply_audit'] ?? null)) {
                    $by_post[$post_id]['post_apply_audit'] = $result['post_apply_audit'];
                }
                if (isset($result['revision_id'])) $by_post[$post_id]['applied_revision_id'] = (int) $result['revision_id'];
                if (isset($result['visible_applied_occurrences'])) $by_post[$post_id]['visible_applied_occurrences'] = (int) $result['visible_applied_occurrences'];
                if (isset($result['stable_anchor_occurrences'])) $by_post[$post_id]['stable_anchor_occurrences'] = (int) $result['stable_anchor_occurrences'];
            }
            if ($hard_visible_failure) {
                $by_post[$post_id]['status'] = 'failed';
                $by_post[$post_id]['pushed'] = false;
                $by_post[$post_id]['warning_only'] = false;
                $by_post[$post_id]['error_code'] = 'glossary_apply_incomplete';
                $by_post[$post_id]['error'] = '应用后英文译文中未发现目标术语，需重新增量翻译';
            }
            foreach ((array) ($details['candidates'] ?? []) as $candidate) {
                if ((int) ($candidate['post_id'] ?? 0) !== $post_id) continue;
                if (is_array($candidate['audit'] ?? null)) {
                    $by_post[$post_id]['audit'] = $candidate['audit'];
                }
                break;
            }
            if ($is_local_only) {
                $by_post[$post_id]['local_only'] = true;
                $by_post[$post_id]['error_code'] = 'push_failed';
                $by_post[$post_id]['error'] = (string) (($result['message'] ?? '') ?: ($result['push_msg'] ?? ($row['error_msg'] ?? '远程英文站推送失败，本地译文已更新但尚未推送')));
            }
            if ($status === 'failed' && !$warning_only) {
                if (!empty($result['code'])) $by_post[$post_id]['error_code'] = (string) $result['code'];
                if (!empty($result['unit_key'])) $by_post[$post_id]['unit_key'] = (string) $result['unit_key'];
                if (!empty($result['batch_index'])) $by_post[$post_id]['batch_index'] = (int) $result['batch_index'];
                if (!empty($result['trace_id'])) $by_post[$post_id]['trace_id'] = (string) $result['trace_id'];
                if (!empty($result['occurrences']) && is_array($result['occurrences'])) {
                    $by_post[$post_id]['error_occurrences'] = $result['occurrences'];
                }
            }
        }
        return array_values($by_post);
    }

    /** Reconcile the human-readable batch status with the queue rows. */
    private static function sync_batch_status($batch_id, $known_counts = null) {
        $batch_id = sanitize_text_field($batch_id);
        if ($batch_id === '') return 'queued';
        global $wpdb;
        $queue = $wpdb->prefix . 'lisun_translate_queue';
        if (!is_array($known_counts)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT status, result_json FROM {$queue} WHERE batch_id = %s AND action = 'glossary_apply'",
                $batch_id
            ), ARRAY_A);
            $known_counts = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'warnings' => 0];
            foreach ((array) $rows as $row) {
                $status = (string) ($row['status'] ?? 'failed');
                $result = json_decode((string) ($row['result_json'] ?? ''), true);
                if (self::is_success_with_warning($status, $result)) {
                    $status = 'done';
                    $known_counts['warnings']++;
                }
                if ($status === 'done' && self::is_hard_visible_failure($result['post_apply_audit'] ?? null)) {
                    $status = 'failed';
                }
                if (is_array($result) && !empty($result['local_updated']) && empty($result['pushed']) && $status === 'done') {
                    $status = 'failed';
                }
                if (isset($known_counts[$status])) $known_counts[$status]++;
            }
        }
        $status = ($known_counts['pending'] || $known_counts['processing'])
            ? 'running'
            : (($known_counts['failed']) ? 'finished_with_errors' : 'done');
        self::set_batch_status($batch_id, $status);
        return $status;
    }

    /**
     * Older workers marked post-commit audit warnings as failed. Once the
     * local revision and remote push both succeeded, that result is complete
     * and should only carry a warning, never enter the retry/failed counts.
     */
    private static function is_success_with_warning($status, $result) {
        $status = (string) $status;
        $result = is_array($result) ? $result : [];
        if ($status !== 'failed'
            || (!empty($result['local_updated']) && empty($result['pushed']))) {
            return false;
        }
        $audit = is_array($result['post_apply_audit'] ?? null)
            ? $result['post_apply_audit'] : [];
        if (self::is_hard_visible_failure($audit)) return false;
        $expected = (int) ($result['expected_occurrences'] ?? ($audit['hit_count'] ?? 0));
        $visible = (int) ($result['visible_applied_occurrences']
            ?? ($audit['visible_applied_count'] ?? ($result['applied_occurrences'] ?? 0)));
        $partial = $expected > 0 && $visible > 0 && $visible < $expected;
        $committed = !empty($result['pushed'])
            || (!empty($result['revision_id']) && (!empty($result['attempted']) || $partial));
        if (!$committed) return false;
        return !empty($result['warning_only'])
            || $partial
            || !empty($audit)
            || !empty($result['revision_id'])
            || (($result['code'] ?? '') === 'glossary_apply_incomplete');
    }

    /** Normalize an old persisted application row without rewriting history. */
    private static function normalize_warning_application_record($application) {
        $application = is_array($application) ? $application : [];
        if (($application['status'] ?? '') === 'done'
            && self::is_hard_visible_failure($application['post_apply_audit'] ?? null)) {
            $application['status'] = 'failed';
            $application['warning_only'] = false;
            $application['error_code'] = 'glossary_apply_incomplete';
            $application['error'] = '应用后英文译文中未发现目标术语，需重新增量翻译';
        }
        if (($application['status'] ?? '') === 'failed'
            && !empty($application['pushed'])
            && !self::is_hard_visible_failure($application['post_apply_audit'] ?? null)
            && ( !empty($application['warning_only'])
                || is_array($application['post_apply_audit'] ?? null)
                || (($application['error_code'] ?? '') === 'glossary_apply_incomplete'))) {
            $application['status'] = 'done';
            $application['warning_only'] = true;
            $application['error'] = '';
        }
        return $application;
    }

    private static function get_batch($batch_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE batch_id = %s', $batch_id), ARRAY_A);
    }

    private static function set_batch_status($batch_id, $status) {
        global $wpdb;
        $wpdb->update(self::table_name(), ['status' => $status, 'updated_at' => current_time('mysql')], ['batch_id' => $batch_id]);
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'lisun_glossary_apply_batches';
    }

    private static function authorize() {
        // Both checks must answer with readable JSON: a hard 403 died as an
        // empty body, so the admin UI could only show a generic 「请求失败」 and
        // nobody could tell an expired nonce from a real server error.
        if (!check_ajax_referer('lisun_manual_nonce', '_nonce', false)) {
            wp_send_json_error([
                'message' => '安全令牌已失效（页面打开过久或已在别处重新登录），请刷新页面后重试',
                'reason'  => 'nonce_invalid',
            ], 200);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => '当前账号没有管理术语库的权限（需要管理员账号）',
                'reason'  => 'capability',
            ], 200);
        }
    }

    /** Plugin-side log that also captures a true PHP fatal from an AJAX action. */
    private static function log($message) {
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log('[glossary-apply] ' . (string) $message);
        }
    }

    /**
     * A real PHP fatal (E_ERROR) cannot be caught, so register a shutdown hook
     * that records the last error with the AJAX action name. Without this the
     * only trace was an opaque 500 in the browser.
     */
    private static function watch_fatal($action) {
        static $registered = [];
        $action = (string) $action;
        if (isset($registered[$action])) return;
        $registered[$action] = true;
        register_shutdown_function(static function () use ($action) {
            $error = error_get_last();
            if (!$error) return;
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array((int) ($error['type'] ?? 0), $fatal, true)) return;
            if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
                Lisun_Manual_Translate::diagnostic_log('[glossary-apply] FATAL action=' . $action
                    . ' message=' . (string) ($error['message'] ?? '')
                    . ' file=' . (string) ($error['file'] ?? '')
                    . ' line=' . (int) ($error['line'] ?? 0));
            }
        });
    }
}
