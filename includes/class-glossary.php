<?php
/**
 * 术语库模块
 * CRUD + CSV 导入/导出 + 冲突检测
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Glossary {

    use Lisun_Manual_Nonce_Trait;

    /** Deleted terms remain recoverable for 30 days. */
    private const TRASH_RETENTION_DAYS = 30;

    // 术语类别（稳定 slug，UI 层映射中文；不再复用 note 字段）。
    const CATEGORY_MANUAL = 'manual';
    const CATEGORY_ERP    = 'erp';
    const CATEGORY_CUSTOM = 'custom';

    // 适用范围：全局 or 指定文章。
    const SCOPE_GLOBAL  = 'global';
    const SCOPE_ARTICLE = 'article';

    /** 术语↔文章 关联的 zh 本地 option 名。文章 ID 是中文站概念，不随跨站同步。 */
    const ARTICLE_SCOPES_OPTION = 'lisun_glossary_article_scopes';

    /** 详情页“仅本次翻译使用”的临时术语选择，transient 前缀。 */
    const POST_TERM_OVERRIDE_PREFIX = 'lisun_post_term_tmp_';

    /** 将术语同步诊断写入与翻译诊断相同的 uploads/lisun-debug.log。 */
    private static function debug_log($message) {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = !empty($uploads['basedir'])
            ? $uploads['basedir']
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : sys_get_temp_dir());
        $path = rtrim($base, '/\\') . '/lisun-debug.log';
        $line = '[' . current_time('mysql') . '] ' . (string) $message . PHP_EOL;
        if (function_exists('wp_json_encode')) {
            $line = function_exists('iconv') ? (@iconv('UTF-8', 'UTF-8//IGNORE', $line) ?: $line) : $line;
        }
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * 是否在翻译链路中启用术语表。
     * 默认开启，兼容已有安装；关闭后不上传、不传入也不做本地术语替换。
     */
    public static function is_enabled() {
        return (bool) get_option('lisun_glossary_enabled', true);
    }

    /** 类别中文标签（用于后台 UI 与 CSV 显示）。 */
    public static function category_label($category) {
        $map = [
            self::CATEGORY_MANUAL => '手动添加专业名词',
            self::CATEGORY_ERP    => 'ERP产品名称',
            self::CATEGORY_CUSTOM => '自定义',
        ];
        return isset($map[$category]) ? $map[$category] : (string) $category;
    }

    /** 把中文标签（含旧写法）归一化成稳定 slug；无法识别返回空串。 */
    public static function category_from_label($label) {
        $label = trim((string) $label);
        $map = [
            '手动添加专业名词' => self::CATEGORY_MANUAL,
            'ERP产品名称'      => self::CATEGORY_ERP,
            'ERP 产品名称'     => self::CATEGORY_ERP,
            '自定义'           => self::CATEGORY_CUSTOM,
        ];
        return isset($map[$label]) ? $map[$label] : '';
    }

    /** 适用范围中文标签。 */
    public static function scope_label($scope) {
        return $scope === self::SCOPE_GLOBAL ? '全局使用' : '指定文章';
    }

    /** 类别决定默认适用范围：ERP 产品名称全局，其余默认指定文章。 */
    public static function default_scope_for_category($category) {
        return $category === self::CATEGORY_ERP ? self::SCOPE_GLOBAL : self::SCOPE_ARTICLE;
    }

    /**
     * 归一化一条术语的类别与适用范围，同时处理旧数据迁移。
     *
     * 旧版本只有 note（备注）字段，三种类别就是 note 的下拉预设值。迁移规则：
     * note 为两个预设串时归入对应类别并清空备注；其余任意文本视为“自定义”
     * 类别、备注原样保留。返回 [category, scope]，并就地清掉 note 里的旧类别。
     */
    private static function normalize_category_scope($item, &$note) {
        $note = sanitize_textarea_field($item['note'] ?? '');
        $category = self::normalize_category($item['category'] ?? '');
        if ($category !== '') {
            $scope = self::normalize_scope($item['scope'] ?? '');
            if ($scope === '') $scope = self::default_scope_for_category($category);
            return [$category, $scope];
        }

        // 旧数据迁移：category 缺失时按 note 归类。
        if ($note === 'ERP产品名称') {
            $note = '';
            return [self::CATEGORY_ERP, self::SCOPE_GLOBAL];
        }
        if ($note === '手动添加专业名词') {
            $note = '';
            return [self::CATEGORY_MANUAL, self::SCOPE_ARTICLE];
        }
        return [self::CATEGORY_CUSTOM, self::SCOPE_ARTICLE];
    }

    private static function normalize_category($category) {
        $category = trim((string) $category);
        // Remote/CSV sources often carry the human label rather than the
        // internal slug. Treat it as an explicit value, not as "missing".
        $label_category = self::category_from_label($category);
        if ($label_category !== '') $category = $label_category;
        return in_array($category, [self::CATEGORY_MANUAL, self::CATEGORY_ERP, self::CATEGORY_CUSTOM], true)
            ? $category : '';
    }

    private static function normalize_scope($scope) {
        $scope = trim((string) $scope);
        $labels = [
            '全局' => self::SCOPE_GLOBAL,
            '全局使用' => self::SCOPE_GLOBAL,
            'global use' => self::SCOPE_GLOBAL,
            '指定文章' => self::SCOPE_ARTICLE,
            '文章' => self::SCOPE_ARTICLE,
            'article specific' => self::SCOPE_ARTICLE,
        ];
        $lookup = function_exists('mb_strtolower') ? mb_strtolower($scope, 'UTF-8') : strtolower($scope);
        if (isset($labels[$lookup])) $scope = $labels[$lookup];
        return in_array($scope, [self::SCOPE_GLOBAL, self::SCOPE_ARTICLE], true) ? $scope : '';
    }

    /** 根据 CSV 表头行建立列索引映射；无法定位中文/英文列时返回空数组。 */
    private static function csv_column_map($header) {
        $map = [];
        foreach ((array) $header as $index => $name) {
            $name = trim((string) $name);
            $key = '';
            if ($name === '中文术语' || strtolower($name) === 'zh') $key = 'zh';
            elseif ($name === '英文译法' || strtolower($name) === 'en') $key = 'en';
            elseif ($name === '类别' || strtolower($name) === 'category') $key = 'category';
            elseif ($name === '适用范围' || strtolower($name) === 'scope') $key = 'scope';
            elseif ($name === '备注' || strtolower($name) === 'note') $key = 'note';
            elseif ($name === '导入日期' || strtolower($name) === 'date') $key = 'import_date';
            if ($key !== '' && !isset($map[$key])) $map[$key] = $index;
        }
        return (isset($map['zh']) && isset($map['en'])) ? $map : [];
    }

    public static function init() {
        add_action('wp_ajax_lisun_add_glossary', [__CLASS__, 'ajax_add']);
        add_action('wp_ajax_lisun_save_glossary', [__CLASS__, 'ajax_save']);
        add_action('wp_ajax_lisun_delete_glossary', [__CLASS__, 'ajax_delete']);
        add_action('wp_ajax_lisun_get_glossary_trash', [__CLASS__, 'ajax_get_trash']);
        add_action('wp_ajax_lisun_restore_glossary', [__CLASS__, 'ajax_restore']);
        add_action('wp_ajax_lisun_permanently_delete_glossary', [__CLASS__, 'ajax_permanently_delete']);
        add_action('wp_ajax_lisun_import_glossary_csv', [__CLASS__, 'ajax_import_csv']);
        add_action('wp_ajax_lisun_export_glossary', [__CLASS__, 'ajax_export']);
        add_action('wp_ajax_lisun_search_glossary', [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_lisun_push_glossary_en', [__CLASS__, 'ajax_push_to_en']);
        add_action('wp_ajax_lisun_pull_glossary_en', [__CLASS__, 'ajax_pull_from_en']);
        add_action('wp_ajax_lisun_sync_glossary_sheets', [__CLASS__, 'ajax_sync_from_sheets']);
        add_action('wp_ajax_lisun_sync_glossary_deepl', [__CLASS__, 'ajax_sync_to_deepl']);
        add_action('wp_ajax_lisun_check_deepl_connection', [__CLASS__, 'ajax_check_deepl_connection']);
        add_action('wp_ajax_lisun_retry_glossary_sync', [__CLASS__, 'ajax_retry_glossary_sync']);
        add_action('wp_ajax_lisun_get_glossary_page', [__CLASS__, 'ajax_get_page']);
        add_action('init', [__CLASS__, 'purge_expired_trash']);
        add_action('wp_ajax_lisun_post_term_config', [__CLASS__, 'ajax_post_term_config']);
        add_action('wp_ajax_lisun_save_post_term_config', [__CLASS__, 'ajax_save_post_term_config']);
        add_action('wp_ajax_lisun_set_post_term_override', [__CLASS__, 'ajax_set_post_term_override']);

        // 文章编辑页“本篇术语”侧边栏。设置项只控制显示，不影响翻译筛选逻辑。
        add_action('add_meta_boxes', [__CLASS__, 'add_post_term_metabox']);

        // REST API 端点：供本地脚本推送术语库 + 双站共享
        add_action('rest_api_init', function () {
            register_rest_route('lisun-manual/v1', '/glossary', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_update_glossary'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/glossary', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'rest_get_glossary'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]);
        });
    }

    /** 在中文 manual 编辑页显示本篇术语配置。 */
    public static function add_post_term_metabox() {
        if (!get_option('lisun_show_post_term_panel', true)) return;
        add_meta_box(
            'lisun-manual-post-terms',
            __('本篇术语', 'lisun-manual'),
            [__CLASS__, 'render_post_term_metabox'],
            'manual',
            'side',
            'high'
        );
    }

    /** 渲染术语选择，并把选择分别写入默认配置或本次翻译临时覆盖。 */
    public static function render_post_term_metabox($post) {
        if (get_post_meta($post->ID, '_lisun_manual_lang', true) === 'en') {
            echo '<p style="color:#777;">' . esc_html__('术语配置请在中文原文中调整。', 'lisun-manual') . '</p>';
            return;
        }
        $terms = self::post_term_config($post->ID);
        wp_nonce_field('lisun_manual_post_terms', 'lisun_post_terms_nonce');
        ?>
        <div id="lisun-post-terms" data-post-id="<?php echo (int) $post->ID; ?>">
            <p style="margin:0 0 8px;color:#666;font-size:12px;line-height:1.45;">勾选后可保存为本篇默认术语，或只应用于下一次翻译。</p>
            <input type="search" class="widefat" id="lisun-post-terms-search" placeholder="搜索中文或英文术语…" style="margin-bottom:8px;">
            <div id="lisun-post-terms-list" style="max-height:300px;overflow:auto;border:1px solid #dcdcde;background:#fff;padding:4px 6px;">
                <?php foreach ($terms as $term): ?>
                    <label class="lisun-post-term-row" data-search="<?php echo esc_attr(strtolower($term['zh'] . ' ' . $term['en'])); ?>" style="display:block;padding:4px 0;font-size:12px;line-height:1.3;">
                        <input type="checkbox" value="<?php echo esc_attr($term['term_id']); ?>" <?php checked($term['enabled']); ?>>
                        <span><?php echo esc_html($term['zh']); ?> → <?php echo esc_html($term['en']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p style="margin:8px 0 4px;display:flex;gap:4px;flex-wrap:wrap;">
                <button type="button" class="button button-small" id="lisun-post-terms-save">保存为本篇默认</button>
                <button type="button" class="button button-primary button-small" id="lisun-post-terms-once">仅本次翻译使用</button>
            </p>
            <p id="lisun-post-terms-status" style="margin:4px 0 0;font-size:12px;color:#2271b1;"></p>
        </div>
        <script>
        jQuery(function($) {
            var box = $('#lisun-post-terms'); if (!box.length) return;
            var nonce = $('#lisun_post_terms_nonce').val();
            function ids() { var out=[]; box.find('input[type=checkbox]:checked').each(function(){out.push($(this).val());}); return out; }
            function request(action, success) {
                var data = {_nonce: nonce, action: action, post_id: box.data('post-id'), enabled_ids: ids()};
                var status = $('#lisun-post-terms-status').text('保存中…');
                $.post(ajaxurl, data).done(function(resp) {
                    if (resp && resp.success) { status.text(resp.data.message || '已保存'); }
                    else { status.css('color','#d63638').text((resp && resp.data && resp.data.message) || '保存失败'); }
                    if (success) success(resp);
                }).fail(function(){ status.css('color','#d63638').text('请求失败，请刷新后重试'); });
            }
            $('#lisun-post-terms-save').on('click', function(){ request('lisun_save_post_term_config'); });
            $('#lisun-post-terms-once').on('click', function(){ request('lisun_set_post_term_override'); });
            $('#lisun-post-terms-search').on('input', function(){
                var q = String($(this).val() || '').toLowerCase();
                box.find('.lisun-post-term-row').each(function(){ $(this).toggle(!q || String($(this).data('search')).indexOf(q) >= 0); });
            });
        });
        </script>
        <?php
    }

    /**
     * REST API: 批量更新术语库（本地脚本推送）
     */
    public static function rest_update_glossary($request) {
        $glossary = $request->get_param('glossary');
        if (!is_array($glossary)) {
            return new WP_REST_Response(['error' => 'Invalid glossary data'], 400);
        }

        $existing = self::get_all();
        $by_zh = [];
        foreach ($existing as $old) {
            if (!empty($old['zh']) && !empty($old['term_id'])) {
                $by_zh[$old['zh']] = $old['term_id'];
            }
        }
        $clean = [];
        $used_ids = [];
        foreach ($glossary as $item) {
            $zh = sanitize_text_field($item['zh'] ?? '');
            $en = sanitize_text_field($item['en'] ?? '');
            $note = sanitize_textarea_field($item['note'] ?? '');
            if ($zh && $en) {
                // 同步数据里的 term_id 不是可信来源：旧版 CSV/远程站可能没有
                // 该字段，或带有旧格式。相同中文术语必须优先沿用本地 ID，
                // 否则已有译文中的锚点会在同步后全部变成未知 ID。
                $incoming_id = self::normalize_term_id($item['term_id'] ?? '');
                $term_id = $by_zh[$zh] ?? $incoming_id;
                if (!self::normalize_term_id($term_id) || isset($used_ids[$term_id])) {
                    $term_id = self::new_term_id($used_ids);
                }
                $used_ids[$term_id] = true;
                $clean[] = [
                    'term_id' => $term_id,
                    'zh' => $zh,
                    'en' => $en,
                    'note' => $note,
                    'category' => sanitize_text_field($item['category'] ?? ''),
                    'scope' => sanitize_text_field($item['scope'] ?? ''),
                    'import_date' => self::normalize_import_date($item['import_date'] ?? ''),
                ];
            }
        }

        $issues = self::validate_glossary_entries($clean, $existing);
        if (!empty($issues)) {
            return new WP_REST_Response([
                'error' => '术语表存在需要确认或修正的项目',
                'issues' => $issues,
            ], 409);
        }

        self::save_all($clean);

        return new WP_REST_Response(['total' => count($clean)], 200);
    }

    /**
     * REST API: 返回当前术语库（供其他站拉取）
     */
    public static function rest_get_glossary($request) {
        $glossary = self::get_all();
        return new WP_REST_Response(['glossary' => $glossary, 'total' => count($glossary)], 200);
    }

    /**
     * 获取整个术语库（强制返回数组，自动修复坏数据）
     */
    public static function get_all() {
        $data = get_option('lisun_glossary', []);

        // 修复：如果不是数组，尝试 JSON 解码，否则置空
        if (!is_array($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
            // 修复数据库中的错误数据
            update_option('lisun_glossary', $data, false);
        }

        // 旧数据没有导入日期，按约定统一标记为当年 09/21；已有日期保持不变。
        $normalized = self::normalize_entries($data, [], self::legacy_import_date());
        if ($normalized !== $data) {
            self::persist_entries($normalized, $data);
        }
        return $normalized;
    }

    /** Return valid, non-expired trash rows. Trashed terms never participate in translation. */
    public static function get_trash() {
        $data = get_option('lisun_glossary_trash', []);
        if (!is_array($data)) {
            $decoded = json_decode((string) $data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        $now = function_exists('current_time') ? current_time('timestamp') : time();
        $retention = self::TRASH_RETENTION_DAYS * (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
        $changed = false;
        $trash = [];
        foreach ($data as $item) {
            if (!is_array($item)) { $changed = true; continue; }
            $term_id = self::normalize_term_id($item['term_id'] ?? '');
            $zh = sanitize_text_field($item['zh'] ?? '');
            $en = sanitize_text_field($item['en'] ?? '');
            if ($term_id === '' || $zh === '' || $en === '') { $changed = true; continue; }
            $deleted_at = max(0, (int) ($item['deleted_at'] ?? 0));
            if ($deleted_at > 0 && $deleted_at <= $now - $retention) { $changed = true; continue; }
            $normalized = [
                'term_id' => $term_id,
                'zh' => $zh,
                'en' => $en,
                'note' => sanitize_textarea_field($item['note'] ?? ''),
                'category' => self::normalize_category($item['category'] ?? '') ?: self::CATEGORY_MANUAL,
                'scope' => self::normalize_scope($item['scope'] ?? '') ?: self::SCOPE_ARTICLE,
                'import_date' => self::normalize_import_date($item['import_date'] ?? '', self::legacy_import_date()),
                'deleted_at' => $deleted_at > 0 ? $deleted_at : $now,
            ];
            if ($normalized !== $item) $changed = true;
            $trash[] = $normalized;
        }
        if ($changed || !is_array(get_option('lisun_glossary_trash', []))) self::save_trash($trash);
        return $trash;
    }

    public static function purge_expired_trash() {
        self::get_trash();
    }

    private static function save_trash($trash) {
        update_option('lisun_glossary_trash', is_array($trash) ? array_values($trash) : [], false);
    }

    private static function move_to_trash($item) {
        $trash = self::get_trash();
        $term_id = (string) ($item['term_id'] ?? '');
        $trash = array_values(array_filter($trash, function ($row) use ($term_id) {
            return (string) ($row['term_id'] ?? '') !== $term_id;
        }));
        $item['deleted_at'] = function_exists('current_time') ? current_time('timestamp') : time();
        $trash[] = $item;
        self::save_trash($trash);
    }

    /**
     * 保存术语库（确保存入的是数组）
     */
    private static function save_all($glossary) {
        if (!is_array($glossary)) {
            $glossary = [];
        }

        // 任何“整表替换”入口（尤其是从远程站拉取）都可能来自旧版本，
        // 旧版本没有 term_id 或传回了不同的 ID。按中文术语继承当前本地
        // ID，保证已有译文的锚点不会因同步术语表而失效。
        $raw_existing = get_option('lisun_glossary', []);
        if (!is_array($raw_existing)) {
            $decoded = json_decode((string) $raw_existing, true);
            $raw_existing = is_array($decoded) ? $decoded : [];
        }
        $existing_by_zh = [];
        $existing_dates_by_zh = [];
        $existing_meta_by_zh = [];
        foreach ($raw_existing as $item) {
            if (!is_array($item)) continue;
            $zh = sanitize_text_field($item['zh'] ?? '');
            $id = self::normalize_term_id($item['term_id'] ?? '');
            if ($zh !== '' && $id !== '') {
                $existing_by_zh[$zh] = $id;
                $existing_dates_by_zh[$zh] = self::normalize_import_date($item['import_date'] ?? '', self::legacy_import_date());
                $existing_meta_by_zh[$zh] = [
                    'category' => self::normalize_category($item['category'] ?? ''),
                    'scope' => self::normalize_scope($item['scope'] ?? ''),
                ];
            }
        }

        // An old remote site or sheet may omit category/scope entirely. In
        // that case retain the user's previously saved local choice instead
        // of recalculating it from the category default. Explicit incoming
        // values (including Chinese labels) still take precedence.
        foreach ($glossary as &$incoming_item) {
            if (!is_array($incoming_item)) continue;
            $zh = sanitize_text_field($incoming_item['zh'] ?? '');
            $existing_meta = $existing_meta_by_zh[$zh] ?? [];
            if (self::normalize_category($incoming_item['category'] ?? '') === ''
                && !empty($existing_meta['category'])) {
                $incoming_item['category'] = $existing_meta['category'];
            }
            if (self::normalize_scope($incoming_item['scope'] ?? '') === ''
                && !empty($existing_meta['scope'])) {
                $incoming_item['scope'] = $existing_meta['scope'];
            }
        }
        unset($incoming_item);

        self::persist_entries(
            self::normalize_entries($glossary, $existing_by_zh, self::current_import_date(), $existing_dates_by_zh),
            $raw_existing
        );
    }

    /** Persist once, increment a glossary revision, and mirror rows for V2. */
    private static function persist_entries($entries, $previous = []) {
        $entries = is_array($entries) ? array_values($entries) : [];
        $previous = is_array($previous) ? array_values($previous) : [];
        $old = [];
        $new = [];
        foreach ($previous as $item) {
            if (!is_array($item) || empty($item['term_id'])) continue;
            $old[(string) $item['term_id']] = [
                (string) ($item['zh'] ?? ''),
                (string) ($item['en'] ?? ''),
                (string) ($item['note'] ?? ''),
                (string) ($item['import_date'] ?? ''),
                (string) ($item['category'] ?? ''),
                (string) ($item['scope'] ?? ''),
            ];
        }
        foreach ($entries as $item) {
            if (!is_array($item) || empty($item['term_id'])) continue;
            $new[(string) $item['term_id']] = [
                (string) ($item['zh'] ?? ''),
                (string) ($item['en'] ?? ''),
                (string) ($item['note'] ?? ''),
                (string) ($item['import_date'] ?? ''),
                (string) ($item['category'] ?? ''),
                (string) ($item['scope'] ?? ''),
            ];
        }
        $changed_ids = [];
        $semantic_changed_ids = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $id) {
            if (($old[$id] ?? null) !== ($new[$id] ?? null)) {
                $changed_ids[] = $id;
                // 备注和导入日期属于管理信息，不应让已经翻译好的
                // 文章重新进入术语应用；只有中文/英文术语本身变化
                // 才会使旧的应用快照失效。
                $old_semantic = array_slice((array) ($old[$id] ?? []), 0, 2);
                $new_semantic = array_slice((array) ($new[$id] ?? []), 0, 2);
                if ($old_semantic !== $new_semantic) $semantic_changed_ids[] = $id;
            }
        }

        update_option('lisun_glossary', $entries, false);
        $revision = (int) get_option('lisun_glossary_revision', 0);
        if (!empty($changed_ids)) {
            $revision++;
            update_option('lisun_glossary_revision', $revision, false);
            update_option('lisun_glossary_last_changes', [
                'revision' => $revision,
                'term_ids' => $changed_ids,
                'changed_at' => current_time('mysql'),
            ], false);

            // 为每个术语保留独立版本。全局 revision 会因其他术语的
            // 备注/日期修改而变化，不能单独用它判断某个术语是否变过。
            $term_revisions = get_option('lisun_glossary_term_revisions', []);
            $term_revisions = is_array($term_revisions) ? $term_revisions : [];
            foreach ($semantic_changed_ids as $id) {
                if (isset($new[$id])) {
                    $term_revisions[(string) $id] = $revision;
                } else {
                    unset($term_revisions[(string) $id]);
                }
            }
            update_option('lisun_glossary_term_revisions', $term_revisions, false);
        }
        if (class_exists('Lisun_Manual_V2_Schema')) {
            Lisun_Manual_V2_Schema::sync_glossary_terms($entries, $revision);
        }
    }

    /**
     * 为术语生成并保留稳定的内部 ID。ID 不作为普通编辑字段展示，
     * 但会随 REST/JSON 同步传输，供本地译文锚点长期定位。
     */
    private static function normalize_entries($entries, $fallback_by_zh = [], $default_import_date = null, $fallback_dates_by_zh = []) {
        if (!is_array($entries)) return [];
        $default_import_date = self::normalize_import_date(
            $default_import_date,
            self::current_import_date()
        );
        $used = [];
        $result = [];
        foreach ($entries as $item) {
            if (!is_array($item)) continue;
            $zh = sanitize_text_field($item['zh'] ?? '');
            $en = sanitize_text_field($item['en'] ?? '');
            if ($zh === '' || $en === '') continue;
            $term_id = self::normalize_term_id($item['term_id'] ?? '');
            if (isset($fallback_by_zh[$zh])) {
                // 本地已有 ID 优先于同步载荷中的 ID；这也是旧译文锚点
                // 能够跨站同步继续工作的关键。
                $term_id = self::normalize_term_id($fallback_by_zh[$zh]);
            }
            if ($term_id === '' || isset($used[$term_id])) {
                $term_id = self::new_term_id($used);
            }
            $used[$term_id] = true;
            $import_date = self::normalize_import_date($item['import_date'] ?? '');
            if ($import_date === '' && isset($fallback_dates_by_zh[$zh])) {
                $import_date = self::normalize_import_date($fallback_dates_by_zh[$zh]);
            }
            if ($import_date === '') $import_date = $default_import_date;
            $note = '';
            list($category, $scope) = self::normalize_category_scope($item, $note);
            $result[] = [
                'term_id' => $term_id,
                'zh'      => $zh,
                'en'      => $en,
                'note'    => $note,
                'category' => $category,
                'scope'   => $scope,
                'import_date' => $import_date,
            ];
        }
        return $result;
    }

    private static function normalize_term_id($term_id) {
        $term_id = is_scalar($term_id) ? (string) $term_id : '';
        return preg_match('/^term_[a-f0-9]{16,64}$/i', $term_id) ? $term_id : '';
    }

    private static function new_term_id($used = []) {
        do {
            $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true);
            $term_id = 'term_' . substr(strtolower(preg_replace('/[^a-f0-9]/i', '', $uuid)), 0, 24);
        } while (empty($term_id) || isset($used[$term_id]));
        return $term_id;
    }

    private static function current_import_date() {
        return function_exists('current_time') ? current_time('Y/m/d') : date('Y/m/d');
    }

    private static function legacy_import_date() {
        $year = function_exists('current_time') ? current_time('Y') : date('Y');
        return $year . '/09/21';
    }

    private static function normalize_import_date($value, $fallback = '') {
        $value = sanitize_text_field($value);
        if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $value, $matches)) {
            return sprintf('%04d/%02d/%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $value, $matches)) {
            $year = function_exists('current_time') ? current_time('Y') : date('Y');
            return sprintf('%04d/%02d/%02d', (int) $year, (int) $matches[1], (int) $matches[2]);
        }
        if ($fallback !== '') {
            return self::normalize_import_date($fallback);
        }
        return '';
    }

    public static function get_by_id() {
        $map = [];
        foreach (self::get_all() as $item) {
            if (!empty($item['term_id'])) $map[$item['term_id']] = $item;
        }
        return $map;
    }

    /** 取某条术语的适用范围（兼容尚未迁移的旧数据）。 */
    public static function term_scope($term) {
        $scope = self::normalize_scope($term['scope'] ?? '');
        if ($scope !== '') return $scope;
        $category = self::normalize_category($term['category'] ?? '');
        if ($category === '') $category = self::CATEGORY_MANUAL;
        return self::default_scope_for_category($category);
    }

    /** 读取术语↔文章关联（zh 本地概念，不随跨站同步）。 */
    public static function article_scopes() {
        $data = get_option(self::ARTICLE_SCOPES_OPTION, []);
        return is_array($data) ? $data : [];
    }

    private static function save_article_scopes($scopes) {
        update_option(self::ARTICLE_SCOPES_OPTION, is_array($scopes) ? $scopes : [], false);
    }

    /**
     * 计算某篇文章当前生效的术语集合。
     * 全局术语（减去本篇排除）+ 指定文章术语（本篇已勾选）。
     */
    public static function terms_for_post($post_id) {
        if (!self::is_enabled()) return [];
        $post_id = absint($post_id);

        // 详情页“仅本次翻译使用”的临时选择优先于保存的默认配置。
        $override = self::post_term_override($post_id);
        if (is_array($override)) {
            $enabled = array_flip($override);
            $out = [];
            foreach (self::get_all() as $term) {
                $tid = (string) ($term['term_id'] ?? '');
                if ($tid !== '' && isset($enabled[$tid])) $out[] = $term;
            }
            return $out;
        }

        $scopes = self::article_scopes();
        $out = [];
        foreach (self::get_all() as $term) {
            $tid = (string) ($term['term_id'] ?? '');
            if ($tid === '') continue;
            $assoc = isset($scopes[$tid]) && is_array($scopes[$tid]) ? $scopes[$tid] : [];
            $article_ids = array_map('intval', (array) ($assoc['article_ids'] ?? []));
            $excluded = array_map('intval', (array) ($assoc['excluded'] ?? []));
            if (self::term_scope($term) === self::SCOPE_GLOBAL) {
                if (in_array($post_id, $excluded, true)) continue;
                $out[] = $term;
            } else {
                if (in_array($post_id, $article_ids, true)) $out[] = $term;
            }
        }
        return $out;
    }

    /** 全部“全局使用”术语（供远程术语库等需要固定集合的场合）。 */
    public static function global_terms() {
        if (!self::is_enabled()) return [];
        $out = [];
        foreach (self::get_all() as $term) {
            if (self::term_scope($term) === self::SCOPE_GLOBAL) $out[] = $term;
        }
        return $out;
    }

    /**
     * 保存某篇文章的默认术语选择（详情页“保存为本篇默认术语”）。
     * $enabled_ids 是本次确认启用的完整 term_id 集合。
     */
    public static function save_post_term_selection($post_id, $enabled_ids) {
        $post_id = absint($post_id);
        if ($post_id <= 0) return false;
        $enabled = [];
        foreach ((array) $enabled_ids as $tid) {
            $tid = self::normalize_term_id($tid);
            if ($tid !== '') $enabled[$tid] = true;
        }
        $scopes = self::article_scopes();
        foreach (self::get_all() as $term) {
            $tid = (string) ($term['term_id'] ?? '');
            if ($tid === '') continue;
            $assoc = isset($scopes[$tid]) && is_array($scopes[$tid]) ? $scopes[$tid] : ['article_ids' => [], 'excluded' => []];
            $article_ids = array_map('intval', (array) ($assoc['article_ids'] ?? []));
            $excluded = array_map('intval', (array) ($assoc['excluded'] ?? []));
            $enabled_now = isset($enabled[$tid]);
            if (self::term_scope($term) === self::SCOPE_GLOBAL) {
                if ($enabled_now) {
                    $excluded = array_values(array_diff($excluded, [$post_id]));
                } elseif (!in_array($post_id, $excluded, true)) {
                    $excluded[] = $post_id;
                }
                $scopes[$tid] = ['article_ids' => $article_ids, 'excluded' => array_values(array_unique($excluded))];
            } else {
                if ($enabled_now) {
                    if (!in_array($post_id, $article_ids, true)) $article_ids[] = $post_id;
                } else {
                    $article_ids = array_values(array_diff($article_ids, [$post_id]));
                }
                $scopes[$tid] = ['article_ids' => array_values(array_unique($article_ids)), 'excluded' => $excluded];
            }
        }
        self::save_article_scopes($scopes);
        return true;
    }

    /**
     * 把某篇文章加入一条“指定文章”术语的适用范围（article_ids）。
     *
     * “应用”动作成功后调用，使详情页默认勾选该术语——避免“应用记录”已存在
     * 但“适用配置”仍为空，导致详情页显示未勾选的脱节。全局术语无需记录，
     * 本方法对全局术语为 no-op。
     */
    public static function add_article_to_term_scope($term_id, $post_id) {
        $post_id = absint($post_id);
        $term_id = self::normalize_term_id($term_id);
        if ($term_id === '' || $post_id <= 0) return false;
        $term = self::get_by_id()[$term_id] ?? null;
        if (!$term) return false;
        if (self::term_scope($term) === self::SCOPE_GLOBAL) return true;

        self::add_articles_to_term_scopes([['term_id' => $term_id, 'post_id' => $post_id]]);
        return true;
    }

    /**
     * 批量把 (term_id, post_id) 合并进“指定文章”术语的适用范围，只写一次 option。
     * 全局术语与已删除术语自动跳过；已存在的文章不重复写入。
     */
    public static function add_articles_to_term_scopes($pairs) {
        $by_id = self::get_by_id();
        $scopes = self::article_scopes();
        $changed = false;
        foreach ((array) $pairs as $pair) {
            $term_id = self::normalize_term_id($pair['term_id'] ?? '');
            $post_id = absint($pair['post_id'] ?? 0);
            if ($term_id === '' || $post_id <= 0) continue;
            $term = $by_id[$term_id] ?? null;
            if (!$term || self::term_scope($term) === self::SCOPE_GLOBAL) continue;

            $assoc = isset($scopes[$term_id]) && is_array($scopes[$term_id])
                ? $scopes[$term_id]
                : ['article_ids' => [], 'excluded' => []];
            $article_ids = array_map('intval', (array) ($assoc['article_ids'] ?? []));
            $excluded = array_map('intval', (array) ($assoc['excluded'] ?? []));
            if (in_array($post_id, $article_ids, true)) continue;
            $article_ids[] = $post_id;
            $scopes[$term_id] = [
                'article_ids' => array_values(array_unique($article_ids)),
                'excluded' => $excluded,
            ];
            $changed = true;
        }
        if ($changed) self::save_article_scopes($scopes);
        return $changed;
    }

    /**
     * 详情页术语面板所需数据：全库 + 本篇默认启用/排除状态。
     */
    public static function post_term_config($post_id) {
        $post_id = absint($post_id);
        $scopes = self::article_scopes();
        $terms = [];
        foreach (self::get_all() as $term) {
            $tid = (string) ($term['term_id'] ?? '');
            if ($tid === '') continue;
            $assoc = isset($scopes[$tid]) && is_array($scopes[$tid]) ? $scopes[$tid] : [];
            $article_ids = array_map('intval', (array) ($assoc['article_ids'] ?? []));
            $excluded = array_map('intval', (array) ($assoc['excluded'] ?? []));
            $scope = self::term_scope($term);
            $enabled = $scope === self::SCOPE_GLOBAL
                ? !in_array($post_id, $excluded, true)
                : in_array($post_id, $article_ids, true);
            $terms[] = [
                'term_id' => $tid,
                'zh' => $term['zh'],
                'en' => $term['en'],
                'category' => $term['category'] ?? '',
                'scope' => $scope,
                'enabled' => $enabled,
                'import_date' => (string) ($term['import_date'] ?? ''),
            ];
        }
        return $terms;
    }

    /** 读取详情页“仅本次翻译使用”的临时术语选择；无则返回 null。 */
    public static function post_term_override($post_id) {
        $val = get_transient(self::POST_TERM_OVERRIDE_PREFIX . absint($post_id));
        return is_array($val) ? $val : null;
    }

    public static function set_post_term_override($post_id, $enabled_ids) {
        $post_id = absint($post_id);
        if ($post_id <= 0) return false;
        $enabled = [];
        foreach ((array) $enabled_ids as $tid) {
            $tid = self::normalize_term_id($tid);
            if ($tid !== '') $enabled[] = $tid;
        }
        set_transient(self::POST_TERM_OVERRIDE_PREFIX . $post_id, array_values(array_unique($enabled)), 12 * HOUR_IN_SECONDS);
        return true;
    }

    public static function clear_post_term_override($post_id) {
        delete_transient(self::POST_TERM_OVERRIDE_PREFIX . absint($post_id));
    }

    /** AJAX: 详情页读取本篇术语配置。 */
    public static function ajax_post_term_config() {
        self::check_nonce();
        $post_id = absint($_POST['post_id'] ?? 0);
        if ($post_id <= 0) wp_send_json_error(['message' => '缺少文章 ID']);
        wp_send_json_success([
            'enabled' => self::is_enabled(),
            'terms' => self::post_term_config($post_id),
        ]);
    }

    /** AJAX: 详情页保存“本篇默认术语”。 */
    public static function ajax_save_post_term_config() {
        self::check_nonce();
        $post_id = absint($_POST['post_id'] ?? 0);
        $enabled_ids = is_array($_POST['enabled_ids'] ?? null) ? $_POST['enabled_ids'] : [];
        if ($post_id <= 0) wp_send_json_error(['message' => '缺少文章 ID']);
        self::save_post_term_selection($post_id, $enabled_ids);
        wp_send_json_success(['terms' => self::post_term_config($post_id)]);
    }

    /** AJAX: 详情页保存“仅本次翻译使用”的临时术语选择（不写默认配置）。 */
    public static function ajax_set_post_term_override() {
        self::check_nonce();
        $post_id = absint($_POST['post_id'] ?? 0);
        $enabled_ids = is_array($_POST['enabled_ids'] ?? null) ? $_POST['enabled_ids'] : [];
        if ($post_id <= 0) wp_send_json_error(['message' => '缺少文章 ID']);
        self::set_post_term_override($post_id, $enabled_ids);
        wp_send_json_success([
            'message' => '已记录本次翻译的临时术语选择（不影响本篇默认配置）',
        ]);
    }

    /**
     * Return the revision of one term, without treating unrelated glossary
     * edits as a change to this term.
     */
    public static function get_term_revision($term_id) {
        $revisions = get_option('lisun_glossary_term_revisions', []);
        if (!is_array($revisions)) return 0;
        return max(0, (int) ($revisions[(string) $term_id] ?? 0));
    }

    /**
     * AJAX: 添加术语
     */
    public static function ajax_add() {
        self::check_nonce();

        $zh = sanitize_text_field($_POST['zh'] ?? '');
        $en = sanitize_text_field($_POST['en'] ?? '');
        $note = sanitize_textarea_field($_POST['note'] ?? '');
        $category = self::normalize_category($_POST['category'] ?? '');
        if ($category === '') $category = self::CATEGORY_MANUAL;
        $scope = self::normalize_scope($_POST['scope'] ?? '');
        if ($scope === '') $scope = self::default_scope_for_category($category);

        if (empty($zh) || empty($en)) {
            wp_send_json_error(['message' => __('中英文术语不能为空', 'lisun-manual')]);
        }

        $glossary = self::get_all();
        $issues = self::validate_term_candidate($zh, $en, $glossary);
        $hard_issue = self::first_hard_issue($issues);
        if ($hard_issue) {
            $overwrite_confirmed = !empty($_POST['confirm_warnings']) || !empty($_POST['overwrite_existing']);
            if (($hard_issue['type'] ?? '') === 'exact_update' && !$overwrite_confirmed) {
                wp_send_json_error([
                    'message' => $hard_issue['message'],
                    'requires_confirmation' => true,
                    'issues' => [$hard_issue],
                ]);
            }
            // exact_update 在确认后继续向下执行覆盖逻辑；其他硬冲突仍然拒绝保存。
            if (($hard_issue['type'] ?? '') !== 'exact_update') {
                wp_send_json_error([
                    'message' => $hard_issue['message'],
                    'conflict' => true,
                ]);
            }
        }

        if (!empty($issues) && empty($_POST['confirm_warnings']) && empty($_POST['overwrite_existing'])) {
            wp_send_json_error([
                'message' => self::format_issue_message($issues),
                'requires_confirmation' => true,
                'issues' => $issues,
            ]);
        }

        // 添加页面明确确认覆盖时，更新已有中文术语，不创建第二条同名术语。
        if ($hard_issue && ($hard_issue['type'] ?? '') === 'exact_update') {
            foreach ($glossary as $i => $item) {
                if ((string) ($item['zh'] ?? '') === $zh) {
                    $glossary[$i]['en'] = $en;
                    $glossary[$i]['note'] = $note;
                    $glossary[$i]['category'] = $category;
                    $glossary[$i]['scope'] = $scope;
                    self::save_all($glossary);
                    wp_send_json_success([
                        'total' => count($glossary),
                        'term_id' => $glossary[$i]['term_id'] ?? '',
                        'change_type' => 'target_changed',
                        'message' => '已有中文术语的英文译法已覆盖更新；需要修正历史译文时，请在该术语行选择“查看影响”。',
                    ]);
                }
            }
        }

        $glossary[] = [
            'term_id' => self::new_term_id(self::get_by_id()),
            'zh'   => $zh,
            'en'   => $en,
            'note' => $note,
            'category' => $category,
            'scope' => $scope,
            'import_date' => self::current_import_date(),
        ];

        self::save_all($glossary);

        wp_send_json_success([
            'total' => count($glossary),
            'term_id' => $glossary[count($glossary) - 1]['term_id'],
            'change_type' => 'new',
            'message' => '术语已保存；仅影响后续翻译。需要修正历史译文时，请在该术语行选择“查看影响”。',
        ]);
    }

    /**
     * AJAX: 保存编辑
     */
    public static function ajax_save() {
        self::check_nonce();

        $index = intval($_POST['index'] ?? -1);
        $term_id = sanitize_text_field($_POST['term_id'] ?? '');
        $zh = sanitize_text_field($_POST['zh'] ?? '');
        $en = sanitize_text_field($_POST['en'] ?? '');
        $note = sanitize_textarea_field($_POST['note'] ?? '');
        $category = self::normalize_category($_POST['category'] ?? '');
        $scope = self::normalize_scope($_POST['scope'] ?? '');

        $glossary = self::get_all();

        // 列表按日期排序后，页面行号不再等于本地数组下标；
        // 编辑必须优先使用稳定的 term_id，避免把另一条术语当成当前术语。
        if ($term_id !== '') {
            foreach ($glossary as $stored_index => $item) {
                if ((string) ($item['term_id'] ?? '') === $term_id) {
                    $index = (int) $stored_index;
                    break;
                }
            }
        }

        if (!isset($glossary[$index])) {
            wp_send_json_error(['message' => __('术语不存在', 'lisun-manual')]);
        }

        if ($zh === '' || $en === '') {
            wp_send_json_error(['message' => __('中英文术语不能为空', 'lisun-manual')]);
        }

        $old = $glossary[$index];
        $old_zh = (string) ($old['zh'] ?? '');
        $old_en = (string) ($old['en'] ?? '');
        $old_note = (string) ($old['note'] ?? '');

        // 编辑表单可能不传类别/范围；缺省时沿用已存值。
        if ($category === '') $category = self::normalize_category($old['category'] ?? '');
        if ($category === '') $category = self::CATEGORY_MANUAL;
        if ($scope === '') $scope = self::normalize_scope($old['scope'] ?? '');
        if ($scope === '') $scope = self::default_scope_for_category($category);

        $comparison = $glossary;
        unset($comparison[$index]);
        $comparison = array_values($comparison);
        $issues = self::validate_term_candidate($zh, $en, $comparison);
        $hard_issue = self::first_hard_issue($issues);
        if ($hard_issue) {
            // 编辑已有中文术语时，英文译法变化属于“确认后覆盖”，
            // 不能直接当作不可处理的冲突返回，否则前端无法进入确认流程。
            $overwrite_confirmed = !empty($_POST['confirm_warnings']);
            if (($hard_issue['type'] ?? '') === 'exact_update' && !$overwrite_confirmed) {
                wp_send_json_error([
                    'message' => $hard_issue['message'],
                    'requires_confirmation' => true,
                    'issues' => [$hard_issue],
                ]);
            }
            // 已确认 exact_update 时继续执行下面的覆盖保存；其他硬冲突才拒绝。
            if (($hard_issue['type'] ?? '') !== 'exact_update') {
                wp_send_json_error([
                    'message' => $hard_issue['message'],
                    'conflict' => true,
                ]);
            }
        }
        if (!empty($issues) && empty($_POST['confirm_warnings'])) {
            wp_send_json_error([
                'message' => self::format_issue_message($issues),
                'requires_confirmation' => true,
                'issues' => $issues,
            ]);
        }

        // 中文源词是既有译文锚点的语义身份。改中文不能复用旧 term_id，
        // 否则会让历史文章把旧中文概念误认为新术语。
        if ($zh !== $old_zh) {
            foreach ($glossary as $item) {
                if ((string) ($item['zh'] ?? '') === $zh) {
                    wp_send_json_error(['message' => '该中文术语已存在；请直接编辑该术语的英文译法']);
                }
            }
            $new_id = self::new_term_id(self::get_by_id());
            $glossary[] = [
                'term_id' => $new_id,
                'zh' => $zh,
                'en' => $en,
                'note' => $note,
                'category' => $category,
                'scope' => $scope,
                'import_date' => self::current_import_date(),
            ];
            self::save_all($glossary);
            wp_send_json_success([
                'term_id' => $new_id,
                'change_type' => 'new_source_term',
                'message' => '中文术语已作为新术语保存；原术语和历史关联均已保留。',
            ]);
        }

        $glossary[$index] = [
            'term_id' => $old['term_id'] ?? self::new_term_id(self::get_by_id()),
            'zh' => $zh,
            'en' => $en,
            'note' => $note,
            'category' => $category,
            'scope' => $scope,
        ];
        self::save_all($glossary);

        $change_type = $en !== $old_en ? 'target_changed' : ($note !== $old_note ? 'note_only' : 'unchanged');
        wp_send_json_success([
            'term_id' => $glossary[$index]['term_id'],
            'change_type' => $change_type,
            'message' => $change_type === 'note_only'
                ? '备注已保存。'
                : '术语已保存；需要修正历史译文时，请在该术语行选择“查看影响”。',
        ]);
    }

    /**
     * AJAX: 删除术语
     */
    public static function ajax_delete() {
        self::check_nonce();

        $term_id = sanitize_text_field(wp_unslash($_POST['term_id'] ?? ''));
        $glossary = self::get_all();
        $index = -1;
        foreach ($glossary as $stored_index => $item) {
            if ((string) ($item['term_id'] ?? '') === $term_id) {
                $index = (int) $stored_index;
                break;
            }
        }
        if ($index < 0 || !isset($glossary[$index])) {
            wp_send_json_error(['message' => __('术语不存在', 'lisun-manual')]);
        }

        $deleted = $glossary[$index];
        array_splice($glossary, $index, 1);
        self::save_all($glossary);
        self::move_to_trash($deleted);

        wp_send_json_success([
            'total' => count($glossary),
            'term_id' => $term_id,
            'message' => '术语已移入回收站，可在 30 天内恢复。',
        ]);
    }

    public static function ajax_get_trash() {
        self::check_nonce();
        $items = self::get_trash();
        usort($items, function ($a, $b) {
            return (int) ($b['deleted_at'] ?? 0) <=> (int) ($a['deleted_at'] ?? 0);
        });
        $now = function_exists('current_time') ? current_time('timestamp') : time();
        $retention = self::TRASH_RETENTION_DAYS * (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400);
        foreach ($items as &$item) {
            $deleted_at = (int) ($item['deleted_at'] ?? $now);
            $item['deleted_at_text'] = function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $deleted_at) : date('Y-m-d H:i:s', $deleted_at);
            $item['expires_at'] = $deleted_at + $retention;
            $item['expires_at_text'] = function_exists('wp_date') ? wp_date('Y-m-d', $item['expires_at']) : date('Y-m-d', $item['expires_at']);
        }
        unset($item);
        wp_send_json_success(['items' => $items, 'total' => count($items), 'retention_days' => self::TRASH_RETENTION_DAYS]);
    }

    public static function ajax_restore() {
        self::check_nonce();
        $term_id = sanitize_text_field(wp_unslash($_POST['term_id'] ?? ''));
        $trash = self::get_trash();
        $trash_index = -1;
        foreach ($trash as $index => $item) {
            if ((string) ($item['term_id'] ?? '') === $term_id) { $trash_index = (int) $index; break; }
        }
        if ($trash_index < 0) wp_send_json_error(['message' => '回收站中找不到该术语，可能已过期删除。']);
        $item = $trash[$trash_index];
        $glossary = self::get_all();
        foreach ($glossary as $active) {
            if ((string) ($active['term_id'] ?? '') === $term_id) wp_send_json_error(['message' => '该术语已经存在于当前术语库中。']);
            if ((string) ($active['zh'] ?? '') === (string) ($item['zh'] ?? '')) wp_send_json_error(['message' => '当前术语库已存在相同中文术语，请先处理冲突后再恢复。']);
        }
        unset($item['deleted_at']);
        $glossary[] = $item;
        self::save_all($glossary);
        array_splice($trash, $trash_index, 1);
        self::save_trash($trash);
        wp_send_json_success(['term_id' => $term_id, 'total' => count($glossary), 'message' => '术语已恢复。']);
    }

    public static function ajax_permanently_delete() {
        self::check_nonce();
        $term_id = sanitize_text_field(wp_unslash($_POST['term_id'] ?? ''));
        $trash = self::get_trash();
        $before = count($trash);
        $trash = array_values(array_filter($trash, function ($item) use ($term_id) {
            return (string) ($item['term_id'] ?? '') !== $term_id;
        }));
        if (count($trash) === $before) wp_send_json_error(['message' => '回收站中找不到该术语。']);
        self::save_trash($trash);

        wp_send_json_success(['term_id' => $term_id, 'total' => count($trash)]);
    }

    /**
     * AJAX: CSV 批量导入
     */
    public static function ajax_import_csv() {
        self::check_nonce();

        if (empty($_FILES['csv_file'])) {
            wp_send_json_error(['message' => __('未收到 CSV 文件', 'lisun-manual')]);
        }

        $file = $_FILES['csv_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('文件上传失败', 'lisun-manual')]);
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error(['message' => __('无法打开文件', 'lisun-manual')]);
        }

        $glossary = self::get_all();
        $incoming = [];
        $skipped = 0;
        $errors = [];

        // 读取 BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // 默认旧格式列位置：中文, 英文, 备注, 导入日期。
        $cols = ['zh' => 0, 'en' => 1, 'note' => 2, 'import_date' => 3, 'category' => -1, 'scope' => -1];
        $header_seen = false;

        $line_num = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $line_num++;

            // 跳过空行
            if (empty($row[0]) && empty($row[1])) {
                continue;
            }

            // 识别表头：首行包含“中文/zh”时按列名映射，兼容旧/新两种格式。
            if (!$header_seen && (stripos($row[0], '中文') !== false || stripos($row[0], 'zh') !== false)) {
                $header_seen = true;
                $map = self::csv_column_map($row);
                if (!empty($map)) $cols = $map;
                continue;
            }
            $header_seen = true;

            $zh = trim($row[$cols['zh']] ?? '');
            $en = trim($row[$cols['en']] ?? '');
            $note = trim($row[$cols['note']] ?? '');
            $category = $cols['category'] >= 0 ? self::category_from_label($row[$cols['category']] ?? '') : '';
            $scope = $cols['scope'] >= 0 ? self::normalize_scope($row[$cols['scope']] ?? '') : '';

            if (empty($zh) || empty($en)) {
                $skipped++;
                $errors[] = ['type' => 'invalid_row', 'severity' => 'error', 'row' => $line_num, 'message' => '第 ' . $line_num . ' 行缺少中文或英文术语'];
                continue;
            }

            if (isset($incoming[$zh])) {
                if ($incoming[$zh]['en'] !== $en || $incoming[$zh]['note'] !== $note) {
                    $errors[] = [
                        'type' => 'duplicate_import', 'severity' => 'error', 'row' => $line_num,
                        'term' => $zh, 'message' => '本次导入文件中重复出现中文术语「' . $zh . '」，但英文或备注不同，已阻止导入',
                    ];
                }
                continue;
            }
            $incoming[$zh] = [
                'zh' => $zh,
                'en' => $en,
                'note' => $note,
                'category' => $category,
                'scope' => $scope,
                'import_date' => self::current_import_date(),
                '_row' => $line_num,
            ];
        }

        fclose($handle);

        $incoming_entries = array_values($incoming);
        $issues = $errors;
        foreach ($incoming_entries as $candidate) {
            $candidate_issues = self::validate_term_candidate($candidate['zh'], $candidate['en'], $glossary, true);
            foreach ($candidate_issues as $issue) {
                $issue['row'] = $candidate['_row'];
                $issues[] = $issue;
            }
            $other_incoming = array_values(array_filter($incoming_entries, function ($other) use ($candidate) {
                return (string) ($other['zh'] ?? '') !== (string) ($candidate['zh'] ?? '');
            }));
            $candidate_issues = self::validate_term_candidate($candidate['zh'], $candidate['en'], $other_incoming);
            foreach ($candidate_issues as $issue) {
                $issue['row'] = $candidate['_row'];
                $issue['scope'] = 'import';
                $issues[] = $issue;
            }
        }

        $hard_issues = array_values(array_filter($issues, function ($issue) {
            return ($issue['severity'] ?? '') === 'error';
        }));
        if (!empty($hard_issues)) {
            wp_send_json_error([
                'message' => 'CSV 导入存在必须修正的问题，未保存任何术语。',
                'issues' => $issues,
                'requires_confirmation' => false,
            ]);
        }

        $warnings = array_values(array_filter($issues, function ($issue) {
            return ($issue['severity'] ?? '') === 'warning';
        }));
        if (!empty($warnings) && empty($_POST['confirm_warnings'])) {
            wp_send_json_error([
                'message' => self::format_issue_message($warnings),
                'issues' => $issues,
                'requires_confirmation' => true,
            ]);
        }

        $imported = 0;
        foreach ($incoming_entries as $candidate) {
            $exists = false;
            foreach ($glossary as $i => $item) {
                if ($item['zh'] === $candidate['zh']) {
                    $glossary[$i] = [
                        'term_id' => $item['term_id'] ?? self::new_term_id(self::get_by_id()),
                        'zh' => $candidate['zh'], 'en' => $candidate['en'], 'note' => $candidate['note'],
                        'category' => $candidate['category'] ?? ($item['category'] ?? ''),
                        'scope' => $candidate['scope'] ?? ($item['scope'] ?? ''),
                    ];
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $glossary[] = [
                    'term_id' => self::new_term_id(self::get_by_id()),
                    'zh' => $candidate['zh'], 'en' => $candidate['en'], 'note' => $candidate['note'],
                    'category' => $candidate['category'] ?? '',
                    'scope' => $candidate['scope'] ?? '',
                    'import_date' => $candidate['import_date'],
                ];
            }
            $imported++;
        }

        self::save_all($glossary);

        wp_send_json_success([
            'imported' => $imported,
            'skipped'  => $skipped,
            'total'    => count($glossary),
            'warnings' => $warnings,
        ]);
    }

    /**
     * AJAX: 导出
     */
    public static function ajax_export() {
        self::check_nonce();

        $format = $_POST['format'] ?? 'csv';
        $glossary = self::get_all();

        if ($format === 'json') {
            wp_send_json_success(['data' => $glossary]);
        }

        // CSV 输出
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="lisun-glossary-' . date('Y-m-d') . '.csv"');

        // BOM for Excel
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');
        fputcsv($output, ['中文术语', '英文译法', '类别', '适用范围', '备注', '导入日期']);

        foreach ($glossary as $item) {
            fputcsv($output, [
                $item['zh'],
                $item['en'],
                self::category_label($item['category'] ?? ''),
                self::scope_label($item['scope'] ?? ''),
                $item['note'] ?? '',
                $item['import_date'] ?? self::legacy_import_date(),
            ]);
        }

        fclose($output);
        wp_die();
    }

    /**
     * AJAX: 搜索
     */
    public static function ajax_search() {
        self::check_nonce();

        $keyword = sanitize_text_field($_POST['keyword'] ?? '');
        $glossary = self::get_all();

        if (empty($keyword)) {
            wp_send_json_success(['results' => $glossary]);
        }

        $results = array_filter($glossary, function ($item) use ($keyword) {
            return stripos($item['zh'], $keyword) !== false
                || stripos($item['en'], $keyword) !== false
                || stripos($item['note'] ?? '', $keyword) !== false;
        });

        wp_send_json_success(['results' => array_values($results)]);
    }

    /**
     * 检查一个候选术语与已有术语的关系。
     *
     * 包含关系只产生 warning：最长匹配允许短术语和长术语同时存在，
     * 但导入时必须让管理员明确知道它们会竞争同一段中文。
     */
    private static function validate_term_candidate($zh, $en, $glossary, $ignore_exact_same = false) {
        $zh = trim((string) $zh);
        $en = trim((string) $en);
        $issues = [];
        if (self::is_short_chinese_term($zh)) {
            $issues[] = [
                'type' => 'short_term_risk', 'severity' => 'warning',
                'term' => $zh,
                'message' => '中文术语「' . $zh . '」较短，可能命中较多正文，请确认是否确实要作为全局术语。',
            ];
        }
        foreach ((array) $glossary as $item) {
            if (!is_array($item)) continue;
            $other_zh = trim((string) ($item['zh'] ?? ''));
            $other_en = trim((string) ($item['en'] ?? ''));
            if ($other_zh === '') continue;

            if ($other_zh === $zh) {
                if ($other_en === $en) {
                    if ($ignore_exact_same) continue;
                    $issues[] = [
                        'type' => 'exact_duplicate', 'severity' => 'error',
                        'term' => $zh, 'related' => $other_zh, 'related_en' => $other_en,
                        'message' => '中文术语「' . $zh . '」已经存在，英文译法为「' . $other_en . '」。',
                    ];
                } else {
                    $issues[] = [
                        'type' => 'exact_update', 'severity' => 'warning',
                        'term' => $zh, 'related' => $other_zh, 'related_en' => $other_en,
                        'message' => '中文术语「' . $zh . '」已有英文译法「' . $other_en . '」，当前译法为「' . $en . '」，请确认是否覆盖。',
                    ];
                }
                continue;
            }

            if (self::text_contains($zh, $other_zh)) {
                $issues[] = [
                    'type' => 'contains_existing_shorter', 'severity' => 'warning',
                    'term' => $zh, 'related' => $other_zh, 'related_en' => $other_en,
                    'message' => '新增中文术语「' . $zh . '」包含已有较短术语「' . $other_zh . '」。当前匹配会优先使用较长术语「' . $zh . '」。',
                ];
            } elseif (self::text_contains($other_zh, $zh)) {
                $issues[] = [
                    'type' => 'contained_by_existing_longer', 'severity' => 'warning',
                    'term' => $zh, 'related' => $other_zh, 'related_en' => $other_en,
                    'message' => '新增中文术语「' . $zh . '」是已有较长术语「' . $other_zh . '」的一部分。当前匹配会优先使用较长术语「' . $other_zh . '」。',
                ];
            }

            if ($other_en !== '' && $other_en === $en) {
                $issues[] = [
                    'type' => 'english_collision', 'severity' => 'warning',
                    'term' => $zh, 'related' => $other_zh, 'related_en' => $other_en,
                    'message' => '中文术语「' . $zh . '」与「' . $other_zh . '」使用相同英文译法「' . $en . '」，请确认是否为同义词。',
                ];
            }
        }
        return self::unique_issues($issues);
    }

    /** 对完整术语表做校验，供 REST/整表同步使用。 */
    public static function validate_glossary_entries($entries, $existing = null) {
        $existing = $existing === null ? self::get_all() : (array) $existing;
        $clean = [];
        $issues = [];
        foreach ((array) $entries as $index => $entry) {
            if (!is_array($entry)) continue;
            $zh = trim((string) ($entry['zh'] ?? ''));
            $en = trim((string) ($entry['en'] ?? ''));
            if ($zh === '' || $en === '') {
                $issues[] = [
                    'type' => 'invalid_row', 'severity' => 'error', 'row' => $index + 1,
                    'message' => '第 ' . ($index + 1) . ' 条术语缺少中文或英文内容',
                ];
                continue;
            }
            foreach (self::validate_term_candidate($zh, $en, $existing, true) as $issue) {
                $issue['row'] = $index + 1;
                $issues[] = $issue;
            }
            foreach (self::validate_term_candidate($zh, $en, $clean) as $issue) {
                $issue['row'] = $index + 1;
                $issues[] = $issue;
            }
            $clean[] = ['zh' => $zh, 'en' => $en];
        }
        return self::unique_issues($issues);
    }

    private static function first_hard_issue($issues) {
        foreach ((array) $issues as $issue) {
            if (in_array(($issue['type'] ?? ''), ['exact_duplicate', 'exact_update'], true)) {
                return $issue;
            }
        }
        return null;
    }

    private static function format_issue_message($issues) {
        $lines = [];
        foreach (self::unique_issues($issues) as $issue) {
            $prefix = !empty($issue['row']) ? '第 ' . (int) $issue['row'] . ' 行：' : '';
            $lines[] = $prefix . (string) ($issue['message'] ?? '术语存在关系冲突');
        }
        return "导入/保存前需要确认以下术语关系：\n\n" . implode("\n", array_slice($lines, 0, 30))
            . (count($lines) > 30 ? "\n……其余 " . (count($lines) - 30) . " 项未显示" : '');
    }

    private static function unique_issues($issues) {
        $seen = [];
        $result = [];
        foreach ((array) $issues as $issue) {
            $key = implode('|', [
                (string) ($issue['type'] ?? ''), (string) ($issue['term'] ?? ''),
                (string) ($issue['related'] ?? ''), (string) ($issue['row'] ?? ''),
            ]);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $issue;
        }
        return $result;
    }

    private static function text_contains($haystack, $needle) {
        if ($needle === '') return false;
        if (function_exists('mb_strpos')) return mb_strpos((string) $haystack, (string) $needle) !== false;
        return strpos((string) $haystack, (string) $needle) !== false;
    }

    private static function is_short_chinese_term($text) {
        $text = trim((string) $text);
        if ($text === '' || !preg_match('/\p{Han}/u', $text)) return false;
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        return $length <= 2;
    }

    /**
     * AJAX: 分页获取术语
     */
    public static function ajax_get_page() {
        self::check_nonce();

        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = max(1, intval($_POST['per_page'] ?? 50));
        $keyword  = sanitize_text_field($_POST['keyword'] ?? '');

        $glossary = self::get_all();

        // 关键词过滤
        if (!empty($keyword)) {
            $glossary = array_values(array_filter($glossary, function ($item) use ($keyword) {
                return stripos($item['zh'], $keyword) !== false
                    || stripos($item['en'], $keyword) !== false
                    || stripos($item['note'] ?? '', $keyword) !== false;
            }));
        }

        // 默认按添加时间倒序：新添加的排第一页，方便直接调试。
        // 同一天内后加的（数组靠后）排前面。
        $indexed = [];
        foreach ($glossary as $idx => $item) {
            $indexed[] = ['idx' => $idx, 'item' => $item];
        }
        usort($indexed, function ($a, $b) {
            $da = (string) ($a['item']['import_date'] ?? '');
            $db = (string) ($b['item']['import_date'] ?? '');
            if ($da === $db) {
                return $b['idx'] <=> $a['idx'];
            }
            return strcmp($db, $da);
        });
        $glossary = array_map(function ($row) { return $row['item']; }, $indexed);

        $total       = count($glossary);
        $total_pages = (int) ceil($total / $per_page);
        $offset      = ($page - 1) * $per_page;
        $items       = array_slice($glossary, $offset, $per_page);

        // 分页列表也返回最近一次术语应用，避免全局/单篇应用完成后仍显示“—”。
        foreach ($items as &$item) {
            $item['latest_apply'] = class_exists('Lisun_Manual_Glossary_Apply')
                ? (Lisun_Manual_Glossary_Apply::latest_for_term($item['term_id'] ?? '') ?: null)
                : null;
        }
        unset($item);

        wp_send_json_success([
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $total_pages,
            'page'        => $page,
            'per_page'    => $per_page,
        ]);
    }

    /**
     * 冲突检测
     */
    private static function check_conflict($zh, $en) {
        $glossary = self::get_all();
        foreach ($glossary as $item) {
            if ($item['zh'] === $zh && $item['en'] !== $en) {
                return $item['en'];
            }
        }
        return false;
    }

    /**
     * 格式化术语表为 prompt 文本
     */
    public static function get_prompt_text() {
        if (!self::is_enabled()) {
            return '';
        }
        $glossary = self::get_all();
        $lines = [];
        foreach ($glossary as $item) {
            $lines[] = $item['zh'] . ' → ' . $item['en'];
        }
        return implode("\n", $lines);
    }

    /**
     * 获取 DeepL Glossary 同步状态。
     */
    public static function get_deepl_state() {
        $state = get_option('lisun_deepl_glossary_state', []);
        return is_array($state) ? $state : [];
    }

    /**
     * 返回给后台页面使用的状态，不暴露 API 密钥或完整凭据。
     */
    public static function get_deepl_status() {
        $state = self::get_deepl_state();
        $entries = self::get_deepl_entries();
        if (!self::is_enabled()) {
            return array_merge($state, [
                'status' => 'disabled',
                'enabled' => false,
                'entry_count' => is_wp_error($entries) ? 0 : count($entries),
                'pending' => false,
                'last_error' => '',
            ]);
        }
        if (is_wp_error($entries)) {
            return array_merge($state, [
                'status' => 'error',
                'entry_count' => 0,
                'last_error' => $entries->get_error_message(),
            ]);
        }
        if (empty($entries)) {
            return array_merge($state, [
                'status' => 'empty',
                'entry_count' => 0,
                'last_error' => '',
            ]);
        }
        $hash = md5(wp_json_encode($entries, JSON_UNESCAPED_UNICODE));
        $api_key = (string) get_option('lisun_deepseek_key', '');
        $relay_url = rtrim((string) get_option('lisun_relay_url', ''), '/');
        $relay_secret = (string) get_option('lisun_relay_secret', '');
        $fingerprint = hash('sha256', $api_key . '|' . $relay_url . '|' . $relay_secret);
        $pending = empty($state['glossary_id'])
            || ($state['glossary_hash'] ?? '') !== $hash
            || ($state['key_fingerprint'] ?? '') !== $fingerprint
            || ($state['route_mode'] ?? '') !== self::deepl_route_mode();
        return array_merge($state, [
            'status' => $pending ? 'pending' : (($state['last_error'] ?? '') !== '' ? 'error' : 'synced'),
            'entry_count' => count($entries),
            'pending' => $pending,
        ]);
    }

    /**
     * 将本地术语表规范化为 DeepL 可接受的 zh -> en 词条。
     * note 字段只属于本地管理信息，不会上传给 DeepL。
     */
    private static function get_deepl_entries($terms = null) {
        $entries = [];
        $seen = [];
        $terms = is_array($terms) ? $terms : self::get_all();

        foreach ($terms as $index => $item) {
            $zh = trim((string) ($item['zh'] ?? ''));
            $en = trim((string) ($item['en'] ?? ''));
            if ($zh === '' && $en === '') continue;
            if ($zh === '' || $en === '') {
                return new WP_Error('deepl_glossary_invalid', sprintf('第 %d 条术语缺少中文或英文内容', $index + 1));
            }
            if (preg_match('/[\r\n\t]/u', $zh . $en)) {
                return new WP_Error('deepl_glossary_invalid', sprintf('第 %d 条术语包含换行或制表符', $index + 1));
            }
            if (isset($seen[$zh])) {
                if ($seen[$zh] !== $en) {
                    return new WP_Error('deepl_glossary_conflict', '术语表存在相同中文术语但英文译法不同：' . $zh);
                }
                continue;
            }
            $seen[$zh] = $en;
            $entries[] = ['zh' => $zh, 'en' => $en];
        }

        usort($entries, function ($a, $b) {
            return strcmp($a['zh'], $b['zh']);
        });
        return $entries;
    }

    /**
     * 按名称找回当前 DeepL 账户中的术语库。
     *
     * 数据库回滚后，DeepL 云端术语库仍然存在，但本地 option 中的
     * glossary_id 可能已经丢失。同步前先查询并复用同名术语库，避免
     * 每次回滚后都重新创建术语库并触发创建额度限制。
     */
    private static function find_deepl_glossary_id($name) {
        $response = self::deepl_api_request('/v3/glossaries', 'GET');
        if (is_wp_error($response)) {
            return $response;
        }

        $glossaries = $response['data']['glossaries'] ?? [];
        foreach ((array) $glossaries as $glossary) {
            if (!is_array($glossary)) {
                continue;
            }
            if ((string) ($glossary['name'] ?? '') !== (string) $name) {
                continue;
            }
            $glossary_id = trim((string) ($glossary['glossary_id'] ?? ''));
            if ($glossary_id !== '') {
                return $glossary_id;
            }
        }

        return '';
    }

    /**
     * 确保本地术语表已同步为 DeepL Glossary，并返回 glossary_id。
     * 空术语表返回空字符串；同步失败返回 WP_Error。
     */
    public static function ensure_deepl_glossary($force = false, $terms = null) {
        if (!self::is_enabled()) {
            return '';
        }
        $entries = self::get_deepl_entries($terms);
        if (is_wp_error($entries)) {
            self::save_deepl_error($entries->get_error_message());
            return $entries;
        }
        if (empty($entries)) {
            // 文章级子集为空时（$terms 是空数组），本文不附加术语表，也
            // 不要覆盖共享的 glossary 状态，以免干扰其他文章正在使用的
            // 固定 glossary_id。只有整库路径（$terms 为 null）才重置状态。
            if (!is_array($terms)) {
                update_option('lisun_deepl_glossary_state', [
                    'glossary_id' => '',
                    'glossary_hash' => md5('[]'),
                    'key_fingerprint' => '',
                    'route_mode' => self::deepl_route_mode(),
                    'source_lang' => 'zh',
                    'target_lang' => 'en',
                    'entry_count' => 0,
                    'synced_at' => time(),
                    'last_error' => '',
                ], false);
            }
            return '';
        }

        $hash = md5(wp_json_encode($entries, JSON_UNESCAPED_UNICODE));
        $api_key = (string) get_option('lisun_deepseek_key', '');
        $relay_url = rtrim((string) get_option('lisun_relay_url', ''), '/');
        $relay_secret = (string) get_option('lisun_relay_secret', '');
        // Relay 模式下本地可能没有真实 API Key，因此同时纳入 Relay 配置指纹。
        $key_fingerprint = hash('sha256', $api_key . '|' . $relay_url . '|' . $relay_secret);
        $route_mode = self::deepl_route_mode();
        $state = self::get_deepl_state();

        $id_samples = [];
        $terms_for_samples = is_array($terms) ? $terms : self::get_all();
        foreach (array_slice($terms_for_samples, 0, 3) as $sample) {
            if (!empty($sample['zh']) && !empty($sample['term_id'])) {
                $id_samples[$sample['zh']] = $sample['term_id'];
            }
        }

        if (!$force
            && !empty($state['glossary_id'])
            && ($state['glossary_hash'] ?? '') === $hash
            && ($state['key_fingerprint'] ?? '') === $key_fingerprint
            && ($state['route_mode'] ?? '') === $route_mode
            && ($state['source_lang'] ?? '') === 'zh'
            && ($state['target_lang'] ?? '') === 'en'
        ) {
            return (string) $state['glossary_id'];
        }

        if ($api_key === '' && $route_mode !== 'relay') {
            $error = new WP_Error('deepl_glossary_no_key', 'DeepL API 未配置，无法同步术语表');
            self::save_deepl_error($error->get_error_message());
            return $error;
        }

        self::debug_log('glossary sync API start entries=' . count($entries)
            . ' hash=' . $hash
            . ' old_hash=' . (string) ($state['glossary_hash'] ?? '')
            . ' route=' . $route_mode
            . ' glossary_id=' . (string) ($state['glossary_id'] ?? '')
            . ' local_id_samples=' . wp_json_encode($id_samples, JSON_UNESCAPED_UNICODE));

        global $wpdb;
        $lock_name = 'lisun_deepl_glossary_sync';
        $lock_acquired = false;
        if (isset($wpdb) && method_exists($wpdb, 'get_var')) {
            $lock_acquired = (bool) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 30)', $lock_name));
        }
        if (!$lock_acquired) {
            $error = new WP_Error('deepl_glossary_lock', '无法取得 DeepL 术语表同步锁，请稍后重试');
            self::save_deepl_error($error->get_error_message());
            return $error;
        }

        try {
            // 重新读取状态，避免等待锁期间其他 worker 已完成同步。
            $state = self::get_deepl_state();
            if (!$force
                && !empty($state['glossary_id'])
                && ($state['glossary_hash'] ?? '') === $hash
                && ($state['key_fingerprint'] ?? '') === $key_fingerprint
                && ($state['route_mode'] ?? '') === $route_mode
            ) {
                return (string) $state['glossary_id'];
            }

            $tsv = '';
            foreach ($entries as $entry) {
                $tsv .= $entry['zh'] . "\t" . $entry['en'] . "\n";
            }
            $tsv = rtrim($tsv, "\n");

            $glossary_name = 'LISUN Manual ZH-EN';
            $glossary_id = (string) ($state['glossary_id'] ?? '');

            // 数据库回滚可能只恢复了本地术语，却丢失了云端 glossary_id。
            // 先按固定名称找回已有术语库，再决定是否创建新的。
            if ($glossary_id === '') {
                $recovered_id = self::find_deepl_glossary_id($glossary_name);
                if (is_wp_error($recovered_id)) {
                    self::debug_log('glossary recovery lookup failed: ' . $recovered_id->get_error_message());
                } elseif ($recovered_id !== '') {
                    $glossary_id = $recovered_id;
                    self::debug_log('glossary recovered by name id=' . $glossary_id);
                }
            }

            $response = null;
            if ($glossary_id !== '') {
                $response = self::deepl_api_request(
                    '/v3/glossaries/' . rawurlencode($glossary_id) . '/dictionaries',
                    'PUT',
                    [
                        'source_lang' => 'zh',
                        'target_lang' => 'en',
                        'entries' => $tsv,
                        'entries_format' => 'tsv',
                    ]
                );
                if (is_wp_error($response)) {
                    // Since API errors now retain both HTTP status and raw
                    // trace id, error data can be either the old integer or
                    // the new associative array.  A glossary from a previous
                    // DeepL account legitimately returns 404 here and must be
                    // recreated, not surfaced as a translation failure.
                    $error_data = $response->get_error_data();
                    $http_code = is_array($error_data)
                        ? (int) ($error_data['http_code'] ?? 0)
                        : (int) $error_data;
                    if ($http_code === 404) {
                        // 本地 ID 可能来自回滚前的另一个云端状态。
                        // 再查一次当前账户的同名术语库，找到后直接复用。
                        $replacement_id = self::find_deepl_glossary_id($glossary_name);
                        if (!is_wp_error($replacement_id) && $replacement_id !== '' && $replacement_id !== $glossary_id) {
                            $glossary_id = $replacement_id;
                            self::debug_log('glossary id replaced after 404 id=' . $glossary_id);
                            $response = self::deepl_api_request(
                                '/v3/glossaries/' . rawurlencode($glossary_id) . '/dictionaries',
                                'PUT',
                                [
                                    'source_lang' => 'zh',
                                    'target_lang' => 'en',
                                    'entries' => $tsv,
                                    'entries_format' => 'tsv',
                                ]
                            );
                        } else {
                            $glossary_id = '';
                            $response = null;
                        }
                    }
                }
                if (is_wp_error($response)) {
                    self::save_deepl_error($response->get_error_message());
                    self::record_glossary_sync_failure($terms, self::deepl_error_http_code($response), $response->get_error_message());
                    return $response;
                }
            }

            if ($glossary_id === '') {
                $response = self::deepl_api_request('/v3/glossaries', 'POST', [
                    'name' => $glossary_name,
                    'dictionaries' => [[
                        'source_lang' => 'zh',
                        'target_lang' => 'en',
                        'entries' => $tsv,
                        'entries_format' => 'tsv',
                    ]],
                ]);
                if (is_wp_error($response)) {
                    self::save_deepl_error($response->get_error_message());
                    self::record_glossary_sync_failure($terms, self::deepl_error_http_code($response), $response->get_error_message());
                    return $response;
                }
                $glossary_id = (string) ($response['data']['glossary_id'] ?? '');
            }

            if ($glossary_id === '') {
                $error = new WP_Error('deepl_glossary_no_id', 'DeepL 未返回 glossary_id');
                self::save_deepl_error($error->get_error_message());
                self::record_glossary_sync_failure($terms, 0, $error->get_error_message());
                return $error;
            }

            update_option('lisun_deepl_glossary_state', [
                'glossary_id' => $glossary_id,
                'glossary_hash' => $hash,
                'key_fingerprint' => $key_fingerprint,
                'route_mode' => $route_mode,
                'source_lang' => 'zh',
                'target_lang' => 'en',
                'entry_count' => count($entries),
                'synced_at' => time(),
                'last_error' => '',
            ], false);

            // DeepL 只保存 zh→en 词典，不接收也不返回插件 term_id；
            // 该日志用于确认 API 同步没有改写本地锚点 ID。
            self::debug_log('glossary sync API done entries=' . count($entries)
                . ' glossary_id=' . $glossary_id
                . ' local_id_samples=' . wp_json_encode($id_samples, JSON_UNESCAPED_UNICODE));

            return $glossary_id;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * 翻译任务互斥锁。DeepL 术语表只有一个固定的 glossary_id，每次翻译前都要
     * 用当前文章的术语子集覆盖它。若两个文章同时翻译，后者的 PUT 会污染前者
     * 尚未发出的 translate 请求。因此同一 glossary_id 的所有翻译任务必须排队：
     * 拿到锁的任务完整跑完（同步 + 所有批次）后才释放。
     */
    public static function acquire_translate_glossary_lock() {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_var')) return false;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 120)', 'lisun_deepl_glossary_translate'));
    }

    public static function release_translate_glossary_lock() {
        global $wpdb;
        if (!isset($wpdb) || !method_exists($wpdb, 'get_var')) return;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'lisun_deepl_glossary_translate'));
    }

    /**
     * 记录一次 DeepL 术语表同步失败。同步失败绝不静默降级到旧术语表，而是
     * 让翻译任务失败，并把 HTTP 状态码、DeepL 错误、术语集合哈希与重试次数
     * 写入日志，供「重试术语同步」按钮读取与恢复。
     */
    public static function record_glossary_sync_failure($terms, $http_code, $deepl_error) {
        $entries = self::get_deepl_entries($terms);
        $hash = is_wp_error($entries) ? '' : md5(wp_json_encode($entries, JSON_UNESCAPED_UNICODE));
        $previous = (array) get_option('lisun_deepl_glossary_fail_log', []);
        $retry_count = (($previous['hash'] ?? '') === $hash && $hash !== '')
            ? (int) ($previous['retry_count'] ?? 0) + 1
            : 1;
        $entry = [
            'hash' => $hash,
            'http_code' => (int) $http_code,
            'deepl_error' => (string) $deepl_error,
            'retry_count' => $retry_count,
            'entry_count' => is_array($terms) ? count($terms) : 0,
            'failed_at' => time(),
            // 保留失败时的 zh/en 子集，供「重试术语同步」精确重放同一套术语，
            // 避免拿整库去重试而污染共享 glossary_id。
            'entries' => is_array($entries) ? $entries : [],
        ];
        update_option('lisun_deepl_glossary_fail_log', $entry, false);
        return $entry;
    }

    /** AJAX：重试上一次失败的术语表同步（精确重放失败时的术语子集）。 */
    public static function ajax_retry_glossary_sync() {
        self::check_nonce();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限'], 403);
        }
        $log = (array) get_option('lisun_deepl_glossary_fail_log', []);
        $entries = is_array($log['entries'] ?? null) ? $log['entries'] : [];
        if (empty($entries)) {
            wp_send_json_error(['message' => '没有可重试的术语同步失败记录']);
        }
        $result = self::ensure_deepl_glossary(true, $entries);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        delete_option('lisun_deepl_glossary_fail_log');
        wp_send_json_success([
            'message' => $result === '' ? '术语子集为空，无需同步' : '术语同步已恢复，glossary_id 尾号 ' . substr((string) $result, -8),
            'glossary_id' => $result,
            'entry_count' => count($entries),
        ]);
    }

    private static function deepl_error_http_code($error) {
        if (!is_wp_error($error)) return 0;
        $data = $error->get_error_data($error->get_error_code());
        if (is_array($data)) return (int) ($data['http_code'] ?? 0);
        if (is_numeric($data)) return (int) $data;
        return 0;
    }

    /**
     * 手动同步 DeepL Glossary。
     */
    public static function ajax_sync_to_deepl() {
        self::check_nonce();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限'], 403);
        }

        if (!self::is_enabled()) {
            wp_send_json_success([
                'message' => '术语表功能已关闭，未同步到 DeepL',
                'glossary_id' => '',
                'entry_count' => 0,
                'synced_at' => 0,
            ]);
        }

        $result = self::ensure_deepl_glossary(true);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $state = self::get_deepl_state();
        wp_send_json_success([
            'message' => $result === '' ? '本地术语表为空，无需同步' : '已同步到 DeepL Glossary',
            'glossary_id' => $result,
            'entry_count' => (int) ($state['entry_count'] ?? 0),
            'synced_at' => (int) ($state['synced_at'] ?? 0),
        ]);
    }

    /**
     * 检查 DeepL 连接与术语库 ID 是否有效，并在 ID 丢失时按名称找回。
     * 这里只做「连接检测 / 修复」，绝不把全库术语重新上传——全量上传已经
     * 被按文章动态筛选取代，上传全库反而会把共享 glossary_id 污染成整库。
     */
    public static function check_deepl_connection() {
        $result = [
            'enabled' => self::is_enabled(),
            'route_mode' => self::deepl_route_mode(),
            'api_key_ok' => false,
            'glossary_id' => '',
            'glossary_found' => false,
            'entry_count' => 0,
            'message' => '',
        ];

        if (!$result['enabled']) {
            $result['message'] = '术语表功能已关闭，翻译时不会使用任何术语表';
            return $result;
        }

        if ($result['route_mode'] !== 'relay' && (string) get_option('lisun_deepl_pro', false) !== '1'
            && (string) get_option('lisun_deepseek_key', '') === '') {
            $result['message'] = '未配置 DeepL API Key，无法连接';
            return $result;
        }

        $list = self::deepl_api_request('/v3/glossaries', 'GET');
        if (is_wp_error($list)) {
            $result['message'] = 'DeepL 连接失败：' . $list->get_error_message();
            return $result;
        }
        $result['api_key_ok'] = true;

        $state = self::get_deepl_state();
        $glossary_id = (string) ($state['glossary_id'] ?? '');
        // 找回丢失的 ID：数据库回滚后云端术语库仍在，按固定名称复用。
        if ($glossary_id === '') {
            $recovered = self::find_deepl_glossary_id('LISUN Manual ZH-EN');
            if (!is_wp_error($recovered) && $recovered !== '') {
                $glossary_id = $recovered;
                $state['glossary_id'] = $recovered;
                update_option('lisun_deepl_glossary_state', $state, false);
            }
        }

        if ($glossary_id !== '') {
            $result['glossary_id'] = $glossary_id;
            $result['glossary_found'] = true;
            $result['entry_count'] = (int) ($state['entry_count'] ?? 0);
            $result['message'] = 'DeepL 连接正常，术语库 ID 尾号 ' . substr($glossary_id, -8)
                . '（' . $result['entry_count'] . ' 条）';
        } else {
            $result['message'] = 'DeepL 连接正常，但术语库尚不存在；将在下次翻译时按文章术语自动创建';
        }
        return $result;
    }

    public static function ajax_check_deepl_connection() {
        self::check_nonce();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限'], 403);
        }
        $result = self::check_deepl_connection();
        wp_send_json_success($result);
    }

    /**
     * 向 DeepL 或现有 Relay 发起请求。代理仅作用于本次 DeepL 请求。
     * @return array|WP_Error ['code' => int, 'data' => array]
     */
    public static function deepl_api_request($path, $method = 'POST', $body = null, $trace_context = []) {
        $api_key = (string) get_option('lisun_deepseek_key', '');
        $relay_url = rtrim((string) get_option('lisun_relay_url', ''), '/');
        $relay_secret = (string) get_option('lisun_relay_secret', '');
        $is_relay = $relay_url !== '' && $relay_secret !== '';

        if ($is_relay) {
            if ($path === '/v2/translate') {
                $url = $relay_url . '/deepl/translate';
            } else {
                $url = $relay_url . '/deepl' . preg_replace('#^/v3#', '', $path);
            }
            $headers = [
                'Content-Type' => 'application/json',
                'X-Relay-Secret' => $relay_secret,
            ];
        } else {
            if ($api_key === '') {
                return new WP_Error('deepl_no_key', 'DeepL API 未配置');
            }
            $base = get_option('lisun_deepl_pro', false)
                ? 'https://api.deepl.com'
                : 'https://api-free.deepl.com';
            $url = $base . $path;
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'DeepL-Auth-Key ' . $api_key,
            ];
        }

        $args = [
            'method' => strtoupper($method),
            'headers' => $headers,
            'timeout' => 60,
        ];
        $trace_id = '';
        if ($body !== null && strtoupper($method) !== 'GET') {
            $invalid_utf8 = false;
            $body = self::sanitize_utf8_value($body, $invalid_utf8);
            $json_flags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $encoded_body = wp_json_encode($body, $json_flags);
            if (!is_string($encoded_body)) {
                error_log('LISUN Manual DeepL request encoding failed path=' . $path
                    . ' body_type=' . gettype($body));
                return new WP_Error('deepl_invalid_utf8', 'DeepL 请求内容包含无法读取的文件编码，请检查原文文件编码后重试');
            }
            if ($invalid_utf8) {
                error_log('LISUN Manual DeepL request removed invalid UTF-8 bytes path=' . $path);
            }
            $args['body'] = $encoded_body;
            $force_trace = !empty($trace_context['force_trace']);
            if ($path === '/v2/translate'
                && ($force_trace || get_option('lisun_deepl_trace_enabled', false))) {
                $trace_id = self::write_deepl_trace('request.json', $encoded_body, $trace_context);
            }
        }

        $proxy = '';
        if (!$is_relay && get_option('lisun_deepl_proxy_enabled', false)) {
            $proxy = trim((string) get_option('lisun_deepl_proxy_url', 'http://127.0.0.1:7890'));
            if ($proxy !== '' && !function_exists('curl_init')) {
                return new WP_Error('deepl_proxy_unavailable', '已启用 DeepL 本机代理，但 PHP cURL 不可用');
            }
        }

        $proxy_filter = null;
        if ($proxy !== '') {
            $proxy_filter = function ($handle, $request, $request_url) use ($proxy) {
                $host = (string) wp_parse_url($request_url, PHP_URL_HOST);
                if (!in_array($host, ['api.deepl.com', 'api-free.deepl.com'], true)) {
                    return $handle;
                }
                if (defined('CURLOPT_PROXY')) {
                    curl_setopt($handle, CURLOPT_PROXY, $proxy);
                }
                return $handle;
            };
            add_filter('http_api_curl', $proxy_filter, 10, 3);
        }

        $response = wp_remote_request($url, $args);

        if ($proxy_filter !== null) {
            remove_filter('http_api_curl', $proxy_filter, 10);
        }
        if (is_wp_error($response)) {
            return new WP_Error('deepl_http', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        if ($trace_id !== '') {
            self::write_deepl_trace('response.json', (string) $raw, $trace_context, $trace_id);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];
        if ($code < 200 || $code >= 300) {
            $error_detail = is_array($data['error'] ?? null)
                ? ($data['error']['message'] ?? '') : ($data['error'] ?? '');
            $message = $data['message'] ?? ($data['detail'] ?? ($error_detail ?: ('HTTP ' . $code)));
            if (!empty($data['code'])) $message .= ' (code: ' . sanitize_text_field((string) $data['code']) . ')';
            if ($trace_id !== '') $message .= ' 原始报文追踪 ID：' . $trace_id;
            error_log('LISUN Manual DeepL request failed path=' . $path . ' http=' . $code . ' message=' . $message);
            return new WP_Error('deepl_api_error', $message, [
                'http_code' => $code,
                'trace_id' => $trace_id,
            ]);
        }

        return [
            'code'         => $code,
            'data'         => $data,
            // 仅用于诊断 HTTP 200 但响应结构异常的情况；调用方只在异常时记录，且已截断。
            'content_type' => (string) wp_remote_retrieve_header($response, 'content-type'),
            'raw_preview'  => substr((string) $raw, 0, 1200),
            'trace_id'     => $trace_id,
        ];
    }

    /**
     * 逐字节保存 DeepL 翻译请求/响应正文。文件不包含 Authorization 或代理密钥。
     * request 与 response 共用同一 trace id，便于一一核对。
     */
    private static function write_deepl_trace($suffix, $bytes, $context = [], $trace_id = '') {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = !empty($uploads['basedir'])
            ? $uploads['basedir']
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : sys_get_temp_dir());
        $dir = rtrim($base, '/\\') . '/lisun-deepl-trace';
        $created = function_exists('wp_mkdir_p') ? wp_mkdir_p($dir) : (@mkdir($dir, 0755, true) || is_dir($dir));
        if (!$created) {
            self::debug_log('DeepL raw trace create failed dir=' . $dir
                . ' parent_writable=' . (is_writable(dirname($dir)) ? 'yes' : 'no'));
            return '';
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php http_response_code(404); exit;\n", LOCK_EX);
        }

        if ($trace_id === '') {
            $post_id = max(0, (int) ($context['post_id'] ?? 0));
            $index = max(0, (int) ($context['index'] ?? 0));
            try {
                $nonce = bin2hex(random_bytes(4));
            } catch (Exception $e) {
                $nonce = substr(md5(uniqid('', true)), 0, 8);
            }
            $trace_id = date('Ymd-His') . '-post' . $post_id . '-part' . $index . '-' . $nonce;
        }

        $safe_suffix = preg_replace('/[^a-z0-9._-]/i', '', (string) $suffix);
        $path = $dir . '/' . $trace_id . '.' . $safe_suffix;
        $written = @file_put_contents($path, (string) $bytes, LOCK_EX);
        if ($written === false) {
            self::debug_log('DeepL raw trace write failed path=' . $path
                . ' dir_writable=' . (is_writable($dir) ? 'yes' : 'no'));
            return '';
        }

        $manifest = date('c')
            . "\t" . $trace_id
            . "\t" . $safe_suffix
            . "\tbytes=" . strlen((string) $bytes)
            . "\tsha256=" . hash('sha256', (string) $bytes)
            . PHP_EOL;
        @file_put_contents($dir . '/manifest.log', $manifest, FILE_APPEND | LOCK_EX);
        self::debug_log('DeepL raw trace id=' . $trace_id
            . ' type=' . $safe_suffix
            . ' bytes=' . strlen((string) $bytes)
            . ' sha256=' . hash('sha256', (string) $bytes)
            . ' path=' . $path);
        return $trace_id;
    }

    /**
     * 递归清理 DeepL JSON 请求中的非法 UTF-8 字节。
     * wp_json_encode() 遇到非法字节会返回 false；如果直接把 false 交给
     * WordPress Requests/cURL，就会出现“Argument #3 ($data) must be array|string”。
     */
    private static function sanitize_utf8_value($value, &$changed = false) {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::sanitize_utf8_value($item, $changed);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->$key = self::sanitize_utf8_value($item, $changed);
            }
            return $value;
        }
        if (!is_string($value) || $value === '' || preg_match('//u', $value) === 1) {
            return $value;
        }

        $clean = false;
        if (function_exists('iconv')) {
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }
        if (!is_string($clean) && function_exists('mb_convert_encoding')) {
            $clean = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        if (!is_string($clean)) {
            $clean = '';
        }
        $changed = true;
        return $clean;
    }

    private static function deepl_route_mode() {
        if (get_option('lisun_relay_url', '') && get_option('lisun_relay_secret', '')) {
            return 'relay';
        }
        return get_option('lisun_deepl_proxy_enabled', false) ? 'direct_proxy' : 'direct';
    }

    private static function save_deepl_error($message) {
        $state = self::get_deepl_state();
        $state['last_error'] = (string) $message;
        update_option('lisun_deepl_glossary_state', $state, false);
    }

    /**
     * AJAX: 推送术语库到远程站
     */
    public static function ajax_push_to_en() {
        self::check_nonce();

        $glossary = self::get_all();

        $result = Lisun_Manual_Sync::push_glossary($glossary);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: 从远程站拉取术语库
     */
    public static function ajax_pull_from_en() {
        self::check_nonce();

        $result = Lisun_Manual_Sync::pull_glossary();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $glossary = $result['glossary'];

        if (empty($glossary)) {
            wp_send_json_error(['message' => __('远程站术语库为空，未执行替换', 'lisun-manual')]);
        }

        // 替换本地术语库
        self::save_all($glossary);

        wp_send_json_success([
            'message' => sprintf(__('已从远程站拉取 %d 条术语', 'lisun-manual'), count($glossary)),
            'total'   => count($glossary),
        ]);
    }


    public static function ajax_sync_from_sheets() {
        self::check_nonce();
        $script = LISUN_MANUAL_PATH . "sync_glossary.py";
        if (!file_exists($script)) {
            wp_send_json_error(["message" => "脚本不存在: " . $script]);
        }
        set_time_limit(120);
        $output = []; $ret = 0;

        // 只给 Google Sheets 同步脚本设置代理，不修改 WordPress 全局网络请求。
        // 优先使用后台“系统设置 → 代理服务”；兼容旧版 wp-config.php 常量配置。
        $proxy_enabled = get_option('lisun_glossary_proxy_enabled', null);
        if ($proxy_enabled === null) {
            $sheets_proxy = defined('LISUN_GLOSSARY_SHEETS_PROXY')
                ? (string) LISUN_GLOSSARY_SHEETS_PROXY
                : '';
        } elseif ($proxy_enabled) {
            $sheets_proxy = (string) get_option('lisun_glossary_proxy_url', 'http://127.0.0.1:7890');
        } else {
            $sheets_proxy = '';
        }
        $sheets_proxy = trim((string) apply_filters('lisun_glossary_sheets_proxy', $sheets_proxy));

        // Alinux 3 已安装 python38；固定使用它，避开宝塔拦截的 /usr/bin/python3 入口。
        $python_binary = '/usr/bin/python3.8';
        $command = escapeshellarg($python_binary) . ' ' . escapeshellarg($script);
        $python_log_line = '使用 Python: ' . $python_binary;
        $output[] = $python_log_line;

        // 代理环境只传给 Python 子进程，不调用 putenv()，避免宝塔禁用该函数导致 500。
        // 不调用无参数 getenv()，兼容旧版 PHP；仅继承运行 Python 所需的基础环境。
        $process_env = [];
        foreach (['PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'TMPDIR', 'PWD'] as $env_key) {
            $env_value = getenv($env_key);
            if ($env_value !== false) {
                $process_env[$env_key] = $env_value;
            }
        }
        if (!empty($_ENV)) {
            $process_env = array_merge($process_env, $_ENV);
        }
        $proxy_env_keys = ['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY',
                           'http_proxy', 'https_proxy', 'all_proxy'];
        if ($sheets_proxy !== '') {
            foreach ($proxy_env_keys as $env_key) {
                $process_env[$env_key] = $sheets_proxy;
            }
            $process_env['NO_PROXY'] = '127.0.0.1,localhost';
            $process_env['no_proxy'] = '127.0.0.1,localhost';
        } else {
            // 代理关闭时移除 PHP-FPM 可能继承的全局代理，确保其他服务器直连。
            foreach ($proxy_env_keys as $env_key) {
                unset($process_env[$env_key]);
            }
        }

        // 优先使用 proc_open 捕获 stdout/stderr；部分宝塔 PHP 环境会禁用 exec()。
        if (function_exists('proc_open')) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = @proc_open($command, $descriptors, $pipes, dirname($script), $process_env);

            if (!is_resource($process)) {
                $output[] = '无法启动 Python 进程，请检查 PHP-FPM 的 proc_open 权限。';
                $ret = 127;
            } else {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $ret = proc_close($process);
                $combined = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
                $output = array_merge(
                    [$python_log_line],
                    $combined === '' ? [] : preg_split('/\r?\n/', $combined)
                );
            }
        } elseif (function_exists('exec')) {
            // 兼容 proc_open 不可用的旧环境，通过 env 命令给子进程注入代理。
            $env_prefix = '';
            if ($sheets_proxy !== '') {
                foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY',
                          'http_proxy', 'https_proxy', 'all_proxy'] as $env_key) {
                    $env_prefix .= $env_key . '=' . escapeshellarg($sheets_proxy) . ' ';
                }
            }
            exec($env_prefix . $command . " 2>&1", $output, $ret);
        } else {
            $output[] = 'PHP 同时禁用了 proc_open() 和 exec()，无法运行 sync_glossary.py。';
            $ret = 127;
        }

        $log = implode("\n", $output);
        if ($ret !== 0) {
            wp_send_json_error(["message" => "脚本执行失败 (exit=" . $ret . ")", "log" => $log]);
        }
        $count = count(self::get_all());
        wp_send_json_success([
            "message" => sprintf("已从 Google Sheets 同步 %d 条术语", $count),
            "total" => $count,
            "log" => $log,
        ]);
    }
}
