<?php
/**
 * 分享管理模块 — 客户账号 CRUD + CSV 批量导入 + 标签权限
 * 存储于 wp_options，支持售前/售后分类、产品型号关联、访问统计
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Customer {

    use Lisun_Manual_Nonce_Trait;

    public static function init() {
        add_action('wp_ajax_lisun_add_customer', [__CLASS__, 'ajax_add']);
        add_action('wp_ajax_lisun_save_customer', [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_lisun_delete_customer', [__CLASS__, 'ajax_delete']);
        add_action('wp_ajax_lisun_reset_customer_password', [__CLASS__, 'ajax_reset_password']);
        add_action('wp_ajax_lisun_import_customers_csv', [__CLASS__, 'ajax_import_csv']);
        add_action('wp_ajax_lisun_delete_access_logs', [__CLASS__, 'ajax_delete_logs']);
        add_action('wp_ajax_lisun_get_manual_tags', [__CLASS__, 'ajax_get_manual_tags']);
        add_action('wp_ajax_lisun_search_customers', [__CLASS__, 'ajax_search_customers']);
    }

    // ==================== 辅助方法 ====================

    /**
     * 获取所有客户
     */
    public static function get_all() {
        return get_option('lisun_customers', []);
    }

    /**
     * 保存所有客户
     */
    private static function save_all($customers) {
        update_option('lisun_customers', $customers);
    }

    /**
     * 获取语言前缀（用于密码开头标识）
     * 中文站 → CN，英文站 → EN
     */
    private static function get_lang_prefix() {
        $lang = get_option('lisun_page_lang', 'zh');
        return $lang === 'en' ? 'EN' : 'CN';
    }

    /**
     * 生成随机密码（带语言前缀）
     */
    public static function generate_password($length = 12) {
        $prefix = self::get_lang_prefix();
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        $remaining = max(1, $length - strlen($prefix));
        for ($i = 0; $i < $remaining; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $prefix . $password;
    }

    /**
     * 获取下一个序号流水号
     */
    private static function get_next_serial() {
        $year = date('Y');
        $key  = 'lisun_customer_serial_' . $year;
        $current = get_option($key, 0);
        $next = $current + 1;
        update_option($key, $next);
        return 'SRLS' . $year . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * 获取 post_tag 所有术语（供产品型号下拉选择，只读不可新建）
     */
    public static function get_manual_tags() {
        $tags = get_terms([
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'orderby'    => 'name',
        ]);

        if (is_wp_error($tags)) {
            return [];
        }

        $result = [];
        foreach ($tags as $tag) {
            $result[] = ['id' => $tag->term_id, 'name' => strtoupper($tag->name), 'slug' => $tag->slug];
        }
        return $result;
    }

    /**
     * 按稳定的标签 ID 解析产品型号。
     *
     * 新数据优先保存 term_id；slug/name 和旧的连字符数据仅作为兼容输入。
     */
    public static function resolve_model_term($value) {
        if ($value instanceof WP_Term) {
            return $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            $term = get_term((int) $raw, 'post_tag');
            if ($term && !is_wp_error($term)) {
                return $term;
            }
        }

        $term = get_term_by('slug', $raw, 'post_tag');
        if (!$term) {
            $term = get_term_by('name', $raw, 'post_tag');
        }
        if ($term && !is_wp_error($term)) {
            return $term;
        }

        // 兼容旧数据：LPCE-2-LMS-9000C 与 LPCE-2 (LMS-9000C) 归一化后比较。
        $needle = self::model_match_key($raw);
        if ($needle === '') {
            return null;
        }

        $terms = get_terms([
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) {
            return null;
        }
        foreach ($terms as $candidate) {
            if (self::model_match_key($candidate->name) === $needle ||
                self::model_match_key($candidate->slug) === $needle) {
                return $candidate;
            }
        }

        return null;
    }

    private static function model_match_key($value) {
        return strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', (string) $value));
    }

    /**
     * 把前端、CSV 或旧数据中的型号引用统一解析成标签 ID 和规范 slug。
     */
    public static function normalize_model_values($values) {
        $ids = [];
        $slugs = [];
        foreach ((array) $values as $value) {
            $raw = sanitize_text_field((string) $value);
            if ($raw === '') {
                continue;
            }
            $term = self::resolve_model_term($raw);
            if ($term && !in_array((int) $term->term_id, $ids, true)) {
                $ids[] = (int) $term->term_id;
                $slugs[] = (string) $term->slug;
            } elseif (!$term && !in_array($raw, $slugs, true)) {
                // 未匹配的旧值不丢失，后续仍可提示或人工修复。
                $slugs[] = $raw;
            }
        }
        return ['ids' => $ids, 'slugs' => $slugs];
    }

    public static function get_model_terms($data) {
        $values = !empty($data['product_model_ids'])
            ? $data['product_model_ids']
            : ($data['product_models'] ?? []);
        $terms = [];
        foreach ((array) $values as $value) {
            $term = self::resolve_model_term($value);
            if ($term && !isset($terms[$term->term_id])) {
                $terms[$term->term_id] = $term;
            }
        }
        return array_values($terms);
    }

    public static function get_model_ids($data) {
        $terms = self::get_model_terms($data);
        $ids = [];
        foreach ($terms as $term) {
            foreach (self::get_equivalent_model_ids($term) as $term_id) {
                if (!in_array($term_id, $ids, true)) {
                    $ids[] = $term_id;
                }
            }
        }
        return $ids;
    }

    /**
     * 获取同一型号的所有标签 ID。
     * 不同站点/历史导入可能同时存在带空格和不带空格的标签，
     * 例如 LPCE-2 (LMS-9000C) 与 LPCE-2(LMS-9000C)。
     */
    public static function get_equivalent_model_ids($value) {
        $term = self::resolve_model_term($value);
        if (!$term) {
            return [];
        }

        $needle = self::model_match_key($term->name);
        $ids = [];
        $terms = get_terms([
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) {
            return [(int) $term->term_id];
        }

        foreach ($terms as $candidate) {
            if (self::model_match_key($candidate->name) === $needle ||
                self::model_match_key($candidate->slug) === $needle) {
                $ids[] = (int) $candidate->term_id;
            }
        }

        return !empty($ids) ? array_values(array_unique($ids)) : [(int) $term->term_id];
    }

    /**
     * 获取当前用户可管理的客户类型
     * @return string 'pre_sale' | 'after_sale' | 'both' | '' (无权限)
     */
    public static function get_user_customer_type() {
        if (current_user_can('administrator')) {
            return 'both';
        }
        $perms = get_option('lisun_customer_perms', []);
        $uid = get_current_user_id();
        return $perms[$uid] ?? '';
    }

    /**
     * 校验权限（所有 AJAX 共用）
     */
    private static function check_permission() {
        if (!current_user_can('edit_pages')) {
            wp_send_json_error(['message' => '权限不足。']);
        }
        self::check_nonce();
    }

    // ==================== AJAX 处理器 ====================

    /**
     * AJAX: 添加客户
     */
    public static function ajax_add() {
        self::check_permission();

        $type           = sanitize_text_field($_POST['type'] ?? 'after_sale');
        $model_refs = self::normalize_model_values($_POST['product_models'] ?? []);
        $product_models = $model_refs['slugs'];
        $doc_type       = sanitize_text_field($_POST['doc_type'] ?? '');
        $doc_number     = sanitize_text_field($_POST['doc_number'] ?? '');
        $password       = sanitize_text_field($_POST['password'] ?? '');
        $note           = sanitize_textarea_field($_POST['note'] ?? '');

        // 校验类型权限
        $user_type = self::get_user_customer_type();
        if ($user_type && $user_type !== 'both' && $type !== $user_type) {
            wp_send_json_error(['message' => '您没有权限创建此类型客户。']);
        }
        // 校验
        if (!in_array($type, ['pre_sale', 'after_sale'], true)) {
            wp_send_json_error(['message' => '请选择有效类型。']);
        }
        if (empty($doc_number)) {
            wp_send_json_error(['message' => '请输入单据号。']);
        }

        // 单据号格式校验
        $doc_prefixes = ['quotation' => 'QTLS', 'contract' => 'PILS'];
        if (isset($doc_prefixes[$doc_type])) {
            if (strlen($doc_number) !== 12) {
                wp_send_json_error(['message' => '单据号必须为 12 位。']);
            }
            if (substr($doc_number, 0, 4) !== $doc_prefixes[$doc_type]) {
                wp_send_json_error(['message' => '前 4 位必须为 ' . $doc_prefixes[$doc_type] . '。']);
            }
            if ($doc_number[4] !== '2' || $doc_number[5] !== '0') {
                wp_send_json_error(['message' => '第 5 位必须为 2，第 6 位必须为 0。']);
            }
        } elseif ($doc_type === 'shipping') {
            // 出运明细号：EXLS/ZKLS/LS/AL033 开头，最大 14 位
            if (strlen($doc_number) > 14) {
                wp_send_json_error(['message' => '出运明细号最大 14 位。']);
            }
            $shipping_prefixes = ['AL033', 'EXLS', 'ZKLS', 'LS'];
            $matched = false;
            foreach ($shipping_prefixes as $pref) {
                if (strpos($doc_number, $pref) === 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                wp_send_json_error(['message' => '出运明细号必须以 EXLS、ZKLS、LS 或 AL033 开头。']);
            }
        }

        if ($type === 'pre_sale' && empty($model_refs['ids'])) {
            wp_send_json_error(['message' => '售前客户至少选择一个产品型号。']);
        }

        $username = $doc_number;

        if (empty($password)) {
            $password = self::generate_password();
        }

        $customers = self::get_all();

        if (isset($customers[$username])) {
            wp_send_json_error(['message' => '用户名已存在。']);
        }

        $customers[$username] = [
            'serial'         => self::get_next_serial(),
            'type'           => $type,
            'product_models' => $product_models,
            'product_model_ids' => $model_refs['ids'],
            'doc_type'       => $doc_type,
            'hash'           => password_hash($password, PASSWORD_DEFAULT),
            'password_plain' => $password,   // 明文存储以便管理员查看和复制给客户
            'created'        => current_time('mysql'),
            'note'           => $note,
            'first_visit'    => null,
            'visit_count'    => 0,
        ];

        self::save_all($customers);

        wp_send_json_success([
            'username' => $username,
            'password' => $password,
            'message'  => sprintf('客户 "%s" 创建成功。', $username),
        ]);
    }

    /**
     * AJAX: 保存编辑
     */
    public static function ajax_save() {
        self::check_permission();

        $old_username   = sanitize_text_field($_POST['old_username'] ?? '');
        $type           = sanitize_text_field($_POST['type'] ?? '');
        $model_refs = self::normalize_model_values($_POST['product_models'] ?? []);
        $product_models = $model_refs['slugs'];
        $doc_type       = sanitize_text_field($_POST['doc_type'] ?? '');
        $doc_number     = sanitize_text_field($_POST['doc_number'] ?? '');
        $note           = sanitize_textarea_field($_POST['note'] ?? '');

        $new_username = !empty($doc_number) ? $doc_number : $old_username;

        if (empty($old_username)) {
            wp_send_json_error(['message' => '请输入用户名。']);
        }

        $customers = self::get_all();

        if (!isset($customers[$old_username])) {
            wp_send_json_error(['message' => '用户不存在。']);
        }

        if ($old_username !== $new_username && isset($customers[$new_username])) {
            wp_send_json_error(['message' => '新用户名已存在。']);
        }

        $data = $customers[$old_username];
        if ($type) {
            $data['type'] = $type;
        }
        $data['product_models'] = $product_models;
        $data['product_model_ids'] = $model_refs['ids'];
        if ($doc_type) {
            $data['doc_type'] = $doc_type;
        }
        $data['note'] = $note;

        unset($customers[$old_username]);
        $customers[$new_username] = $data;

        self::save_all($customers);

        wp_send_json_success(['message' => '客户已更新。']);
    }

    /**
     * AJAX: 删除客户
     */
    public static function ajax_delete() {
        self::check_permission();

        $username = sanitize_text_field($_POST['username'] ?? '');

        $customers = self::get_all();

        if (!isset($customers[$username])) {
            wp_send_json_error(['message' => '用户不存在。']);
        }

        unset($customers[$username]);
        self::save_all($customers);

        wp_send_json_success();
    }

    /**
     * AJAX: 重置密码
     */
    public static function ajax_reset_password() {
        self::check_permission();

        $username = sanitize_text_field($_POST['username'] ?? '');

        $customers = self::get_all();

        if (!isset($customers[$username])) {
            wp_send_json_error(['message' => '用户不存在。']);
        }

        $new_password = self::generate_password();
        $customers[$username]['hash'] = password_hash($new_password, PASSWORD_DEFAULT);
        $customers[$username]['password_plain'] = $new_password;
        self::save_all($customers);

        wp_send_json_success([
            'username' => $username,
            'password' => $new_password,
        ]);
    }

    /**
     * AJAX: CSV 批量导入客户
     *
     * CSV 格式: doc_number, password(可选), type(pre_sale/after_sale), doc_type(quotation/contract/shipping), product_models(分号分隔), note
     */
    public static function ajax_import_csv() {
        self::check_permission();

        if (empty($_FILES['csv_file'])) {
            wp_send_json_error(['message' => '未收到CSV文件。']);
        }

        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => '文件上传失败。']);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error(['message' => '无法打开文件。']);
        }

        // 处理 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $customers = self::get_all();
        $imported = 0;
        $skipped = 0;
        $results = [];

        $line_num = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $line_num++;

            $username       = trim($row[0] ?? ''); // doc_number = username
            $password       = trim($row[1] ?? '');
            $type           = trim($row[2] ?? 'after_sale');
            $doc_type       = trim($row[3] ?? '');
            $models_raw     = trim($row[4] ?? '');
            $note           = trim($row[5] ?? '');

            // 跳过头行
            if ($line_num === 1 && (stripos($username, 'user') !== false || stripos($username, '用户名') !== false)) {
                continue;
            }

            if (empty($username)) {
                $skipped++;
                continue;
            }

            if (!in_array($type, ['pre_sale', 'after_sale'], true)) {
                $type = 'after_sale';
            }

            $product_models = [];
            if (!empty($models_raw)) {
                $product_models = array_filter(array_map('trim', explode(';', $models_raw)));
            }
            $model_refs = self::normalize_model_values($product_models);

            if (empty($password)) {
                $password = self::generate_password();
            }

            $is_new = !isset($customers[$username]);
            $existing = $customers[$username] ?? [];

            $customers[$username] = [
                'serial'         => $existing['serial'] ?? ($is_new ? self::get_next_serial() : ''),
                'type'           => $type,
                'product_models' => $model_refs['slugs'],
                'product_model_ids' => $model_refs['ids'],
                'doc_type'       => $doc_type,
                'hash'           => password_hash($password, PASSWORD_DEFAULT),
                'password_plain' => $password,   // 明文存储以便管理员查看
                'created'        => $existing['created'] ?? current_time('mysql'),
                'note'           => $note,
                'first_visit'    => $existing['first_visit'] ?? null,
                'visit_count'    => $existing['visit_count'] ?? 0,
            ];

            $results[] = [
                'username' => $username,
                'password' => $password,
                'is_new'   => $is_new,
            ];

            $imported++;
        }

        fclose($handle);
        self::save_all($customers);

        wp_send_json_success([
            'imported' => $imported,
            'skipped'  => $skipped,
            'results'  => $results,
        ]);
    }

    /**
     * AJAX: 清空访问日志
     */
    public static function ajax_delete_logs() {
        self::check_permission();
        update_option('lisun_access_logs', []);
        wp_send_json_success();
    }

    /**
     * AJAX: 获取 manual CPT 所有标签（供产品型号下拉选择，只读不可新建）
     */
    public static function ajax_get_manual_tags() {
        self::check_permission();
        wp_send_json_success(self::get_manual_tags());
    }

    /**
     * AJAX: 搜索/筛选客户
     */
    public static function ajax_search_customers() {
        self::check_permission();

        $filter_type   = sanitize_text_field($_POST['filter_type'] ?? '');
        $search        = sanitize_text_field($_POST['search'] ?? '');

        $customers = self::get_all();
        $filtered = [];

        foreach ($customers as $username => $data) {
            // 类型筛选
            if ($filter_type && ($data['type'] ?? '') !== $filter_type) {
                continue;
            }

            // 搜索：匹配用户名或产品型号
            if ($search) {
                $match_user = (stripos($username, $search) !== false);
                $match_model = false;
                $models = $data['product_models'] ?? [];
                foreach ($models as $m) {
                    if (stripos($m, $search) !== false) {
                        $match_model = true;
                        break;
                    }
                }
                if (!$match_user && !$match_model) {
                    continue;
                }
            }

            $filtered[$username] = $data;
        }

        wp_send_json_success($filtered);
    }

}
