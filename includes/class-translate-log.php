<?php
/**
 * 翻译日志模块
 * 独立 DB 表记录翻译历史
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Translate_Log {

    const TABLE_NAME = 'lisun_translate_logs';

    public static function init() {
        add_action('wp_ajax_lisun_get_translate_logs', [__CLASS__, 'ajax_get_logs']);
        add_action('wp_ajax_lisun_clear_translate_logs', [__CLASS__, 'ajax_clear_logs']);
        // 定期清理超过 90 天的旧日志
        add_action('admin_init', [__CLASS__, 'cleanup_old_logs']);
        // 表结构升级：存量表补 chars 列（dbDelta 幂等）
        add_action('admin_init', [__CLASS__, 'maybe_upgrade_table']);
    }

    /**
     * 表结构版本升级：旧表缺 chars 列时自动补上（只跑一次）。
     */
    public static function maybe_upgrade_table() {
        if (get_option('lisun_translate_log_db_version') === '2') {
            return;
        }
        self::create_table(); // dbDelta 会给存量表加缺失的 chars 列
        update_option('lisun_translate_log_db_version', '2');
    }

    /**
     * 创建日志表
     */
    public static function create_table() {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `zh_page_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '中文页面ID',
            `en_page_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '英文页面ID',
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'success / failed',
            `duration` FLOAT NOT NULL DEFAULT 0 COMMENT '耗时(秒)',
            `prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '输入 tokens',
            `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '输出 tokens',
            `chars` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'DeepL 消耗字符数（token 引擎为 0）',
            `error_msg` TEXT COMMENT '错误信息',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `zh_page_id` (`zh_page_id`),
            KEY `en_page_id` (`en_page_id`),
            KEY `status` (`status`),
            KEY `created_at` (`created_at`)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * 添加日志
     */
    public static function add($data) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // 兼容插件更新后日志表尚未创建/升级的情况，避免日志静默丢失。
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            self::create_table();
        }
        // 某些旧站的版本号已是 2，但实际表结构仍缺少 chars，dbDelta 不一定会被触发。
        // 每次写入前兜底检查，避免因未知字段导致整条日志插入失败。
        $has_chars = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE 'chars'");
        if (!$has_chars) {
            $wpdb->query("ALTER TABLE `{$table}` ADD `chars` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'DeepL 消耗字符数（token 引擎为 0）' AFTER `completion_tokens`");
        }

        $ok = $wpdb->insert($table, [
            'zh_page_id'        => intval($data['zh_page_id'] ?? 0),
            'en_page_id'        => intval($data['en_page_id'] ?? 0),
            'status'            => sanitize_text_field($data['status'] ?? 'pending'),
            'duration'          => floatval($data['duration'] ?? 0),
            'prompt_tokens'     => intval($data['prompt_tokens'] ?? 0),
            'completion_tokens' => intval($data['completion_tokens'] ?? 0),
            'chars'             => intval($data['chars'] ?? 0),
            'error_msg'         => sanitize_textarea_field($data['error_msg'] ?? ''),
            'created_at'        => current_time('mysql'),
        ]);

        if ($ok === false) {
            error_log('LISUN Manual: translation log insert failed: ' . $wpdb->last_error);
            return 0;
        }

        return $wpdb->insert_id;
    }

    /**
     * 获取日志列表
     */
    public static function get_logs($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $defaults = [
            'per_page' => 50,
            'page'     => 1,
            'status'   => '',
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $where = [];

        if ($args['status']) {
            $where[] = $wpdb->prepare('status = %s', $args['status']);
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $offset = ($args['page'] - 1) * $args['per_page'];

        $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` {$where_sql}");

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` {$where_sql} ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d",
                $args['per_page'],
                $offset
            ),
            ARRAY_A
        );

        return [
            'total' => intval($total),
            'logs'  => $logs ?: [],
        ];
    }

    /**
     * AJAX: 获取日志
     */
    public static function ajax_get_logs() {
        if (!wp_verify_nonce($_POST['_nonce'] ?? '', 'lisun_manual_nonce')) {
            wp_send_json_error(['message' => __('安全验证失败', 'lisun-manual')]);
        }

        $result = self::get_logs([
            'per_page' => intval($_POST['per_page'] ?? 50),
            'page'     => intval($_POST['page'] ?? 1),
        ]);

        wp_send_json_success($result);
    }

    /**
     * AJAX: 清空日志
     */
    public static function ajax_clear_logs() {
        if (!wp_verify_nonce($_POST['_nonce'] ?? '', 'lisun_manual_nonce')) {
            wp_send_json_error(['message' => __('安全验证失败', 'lisun-manual')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query("TRUNCATE TABLE `{$table}`");

        wp_send_json_success();
    }

    /**
     * 自动清理超过 90 天的旧日志（每天最多执行一次）
     */
    public static function cleanup_old_logs() {
        $last_cleanup = get_transient('lisun_translate_log_cleanup');
        if ($last_cleanup) {
            return;
        }
        set_transient('lisun_translate_log_cleanup', true, DAY_IN_SECONDS);

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // 检查表是否存在
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$exists) {
            return;
        }

        $retention_days = apply_filters('lisun_translate_log_retention_days', 90);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE `created_at` < %s",
                $cutoff
            )
        );
    }

    /**
     * 删除日志表（卸载时调用）
     */
    public static function drop_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}
