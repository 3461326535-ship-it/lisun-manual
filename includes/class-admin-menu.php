<?php
/**
 * 后台菜单注册 + 页面路由
 * 只有一个顶级菜单「说明书管理」，CPT 原生列表为主界面
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Admin_Menu {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menus']);
        // CPT 原生列表自定义列
        add_filter('manage_manual_posts_columns', [__CLASS__, 'add_list_columns']);
        add_action('manage_manual_posts_custom_column', [__CLASS__, 'render_list_column'], 10, 2);
        // 行操作按钮
        add_filter('post_row_actions', [__CLASS__, 'add_row_actions'], 10, 2);
        // 后台列表页标题去掉"操作使用说明书"等后缀
        add_filter('the_title', [__CLASS__, 'strip_manual_title_suffix'], 10, 2);
        // 处理自定义 action
        add_action('admin_init', [__CLASS__, 'handle_list_actions']);
        // 语言分区：筛选标签 + 查询过滤
        add_filter('views_edit-manual', [__CLASS__, 'add_lang_filter_tabs']);
        add_action('pre_get_posts', [__CLASS__, 'filter_by_lang']);
        // 后台说明书搜索：优先匹配产品型号和标题，避免精确型号落到后面
        add_filter('posts_join', [__CLASS__, 'join_manual_search_model'], 20, 2);
        add_filter('posts_search', [__CLASS__, 'search_manual_model'], 20, 2);
        add_filter('posts_orderby', [__CLASS__, 'order_manual_search_results'], 20, 2);
        add_filter('posts_distinct', [__CLASS__, 'distinct_manual_search_results'], 20, 2);
        // 编辑页标题输入框去掉后缀（JS 辅助）
        add_action('admin_footer', [__CLASS__, 'edit_title_script']);
        // 语言计数缓存失效：手册新增/修改/删除时清除 transient
        add_action('save_post_manual', [__CLASS__, 'bust_lang_count_cache']);
        add_action('before_delete_post', [__CLASS__, 'bust_lang_count_cache']);
    }

    /**
     * 清除语言计数 transient 缓存
     */
    public static function bust_lang_count_cache($post_id = null) {
        delete_transient('lisun_manual_lang_counts');
    }

    /**
     * 注册所有菜单 — 只有一个顶级菜单
     */
    public static function register_menus() {
        // 顶级菜单 → CPT 原生列表页
        add_menu_page(
            __('说明书管理', 'lisun-manual'),
            __('说明书管理', 'lisun-manual'),
            'manage_options',
            'edit.php?post_type=manual',
            '',
            'dashicons-book-alt',
            25
        );

        // 全部说明书
        add_submenu_page(
            'edit.php?post_type=manual',
            __('全部说明书', 'lisun-manual'),
            __('全部说明书', 'lisun-manual'),
            'manage_options',
            'edit.php?post_type=manual'
        );

        // 添加说明书
        add_submenu_page(
            'edit.php?post_type=manual',
            __('添加说明书', 'lisun-manual'),
            __('添加说明书', 'lisun-manual'),
            'manage_options',
            'post-new.php?post_type=manual'
        );

        // 批量导入
        add_submenu_page(
            'edit.php?post_type=manual',
            __('批量导入', 'lisun-manual'),
            __('批量导入', 'lisun-manual'),
            'manage_options',
            'lisun-manual-import',
            [__CLASS__, 'render_import']
        );

        // 术语库
        add_submenu_page(
            'edit.php?post_type=manual',
            __('术语库', 'lisun-manual'),
            __('术语库', 'lisun-manual'),
            'manage_options',
            'lisun-manual-glossary',
            [__CLASS__, 'render_glossary']
        );

        // 翻译管理
        add_submenu_page(
            'edit.php?post_type=manual',
            __('翻译管理', 'lisun-manual'),
            __('翻译管理', 'lisun-manual'),
            'manage_options',
            'lisun-manual-translate',
            [__CLASS__, 'render_translate']
        );

        // 分享管理
        add_submenu_page(
            'edit.php?post_type=manual',
            __('分享管理', 'lisun-manual'),
            __('分享管理', 'lisun-manual'),
            'edit_pages',
            'lisun-manual-customers',
            [__CLASS__, 'render_customers']
        );

        // 系统设置
        add_submenu_page(
            'edit.php?post_type=manual',
            __('系统设置', 'lisun-manual'),
            __('系统设置', 'lisun-manual'),
            'manage_options',
            'lisun-manual-settings',
            [__CLASS__, 'render_settings']
        );

        // 图片清理 — 已禁用，改用 CLI 脚本清理
        // add_submenu_page(
        //     'edit.php?post_type=manual',
        //     __('图片清理', 'lisun-manual'),
        //     __('图片清理', 'lisun-manual'),
        //     'manage_options',
        //     'lisun-manual-image-clean',
        //     [__CLASS__, 'render_image_clean']
        // );
    }

    // ==================== CPT 原生列表自定义列 ====================

    /**
     * 后台列表页标题去掉末尾的"操作使用说明书"等后缀
     */
    public static function strip_manual_title_suffix($title, $post_id = null) {
        if (!$post_id || get_post_type($post_id) !== 'manual') return $title;
        if (!is_admin()) return $title;

        $suffixes = ['操作使用说明书', '使用说明书', '操作使用说明'];
        foreach ($suffixes as $suffix) {
            $suffix_len = mb_strlen($suffix);
            if (mb_substr($title, -$suffix_len) === $suffix) {
                return mb_substr($title, 0, -$suffix_len);
            }
        }
        return $title;
    }

    /**
     * 编辑页标题输入框去掉后缀（JS 辅助）
     */
    public static function edit_title_script() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'manual') return;
        if (!in_array($screen->base, ['post'])) return;
        ?>
        <script>
        (function() {
            var titleInput = document.getElementById('title');
            if (!titleInput) return;
            var suffixes = ['操作使用说明书', '使用说明书', '操作使用说明'];
            var val = titleInput.value;
            for (var i = 0; i < suffixes.length; i++) {
                var suffix = suffixes[i];
                if (val.length > suffix.length && val.lastIndexOf(suffix) === val.length - suffix.length) {
                    titleInput.value = val.slice(0, val.length - suffix.length);
                    break;
                }
            }
        })();
        </script>
        <?php
    }
    public static function add_list_columns($columns) {
        $new = [];
        foreach ($columns as $key => $val) {
            $new[$key] = $val;
            if ($key === 'title') {
                $new['lisun_model']    = __('型号', 'lisun-manual');
                $new['lisun_category'] = __('说明书分类', 'lisun-manual');
                $new['lisun_lang']     = __('语言', 'lisun-manual');
                $new['lisun_sync']      = __('翻译状态', 'lisun-manual');
                $new['lisun_remote_id'] = __('远程ID', 'lisun-manual');
            }
        }
        return $new;
    }

    /**
     * 渲染自定义列内容
     */
    public static function render_list_column($column, $post_id) {
        $lang = get_post_meta($post_id, '_lisun_manual_lang', true) ?: 'zh';
        $sync = get_post_meta($post_id, '_lisun_manual_sync_status', true) ?: '';
        $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);

        if ($column === 'lisun_lang') {
            echo $lang === 'zh' ? '中文' : 'English';
        }

        if ($column === 'lisun_model') {
            $model = get_post_meta($post_id, 'product-no', true);
            if ($model) {
                echo '<code style="font-size:12px;">' . esc_html($model) . '</code>';
            } else {
                echo '<span style="color:#999;">—</span>';
            }
        }

        if ($column === 'lisun_category') {
            $category = self::get_manual_category($post_id);
            if ($category) {
                if ($category['cat']) {
                    echo '<span style="font-size:12px;">' . esc_html($category['cat'] . ' › ' . $category['subcat']) . '</span>';
                } else {
                    echo '<span style="font-size:12px;">' . esc_html($category['subcat']) . '</span>';
                }
            } else {
                echo '<span style="color:#999;">—</span>';
            }
        }

        if ($column === 'lisun_remote_id') {
            $remote_id = get_post_meta($post_id, '_lisun_manual_remote_id', true);
            if ($remote_id) {
                echo '<code style="font-size:12px;">' . intval($remote_id) . '</code>';
            } else {
                echo '<span style="color:#999;">—</span>';
            }
        }

        if ($column === 'lisun_sync') {
            if (empty($sync) || $sync === 'pending') {
                echo '<span style="color:#999;">— ' . __('未翻译', 'lisun-manual') . '</span>';
            } elseif ($sync === 'synced') {
                echo '<span style="color:green;">✓ ' . __('已翻译', 'lisun-manual') . '</span>';
                if ($target_id) {
                    echo ' <a href="' . get_edit_post_link($target_id) . '" style="font-size:12px;">[' . __('译文', 'lisun-manual') . ']</a>';
                }
            } elseif ($sync === 'failed') {
                echo '<span style="color:red;">✗ ' . __('失败', 'lisun-manual') . '</span>';
            }
        }
    }

    /**
     * 行操作按钮
     */
    public static function add_row_actions($actions, $post) {
        if ($post->post_type !== 'manual') {
            return $actions;
        }

        $post_id = $post->ID;
        $lang = get_post_meta($post_id, '_lisun_manual_lang', true) ?: 'zh';
        $sync = get_post_meta($post_id, '_lisun_manual_sync_status', true) ?: '';
        $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);

        // 中文页面：翻译 + 推送
        if ($lang === 'zh' && $sync !== 'synced') {
            $url = wp_nonce_url(
                admin_url('edit.php?post_type=manual&lisun_action=translate&id=' . $post_id),
                'lisun_translate_' . $post_id
            );
            $actions['translate'] = '<a href="' . $url . '" style="color:#2271b1;font-weight:bold;">' . __('翻译并推送', 'lisun-manual') . '</a>';
        }

        // 中文页面：已翻译的可以单独推送到远程站
        if ($lang === 'zh' && $target_id) {
            $url = wp_nonce_url(
                admin_url('edit.php?post_type=manual&lisun_action=push&id=' . $post_id),
                'lisun_push_' . $post_id
            );
            $actions['push'] = '<a href="' . $url . '" style="color:#00a32a;">' . __('推送', 'lisun-manual') . '</a>';

            $url = wp_nonce_url(
                admin_url('edit.php?post_type=manual&lisun_action=delete_trans&id=' . $post_id),
                'lisun_delete_trans_' . $post_id
            );
            $actions['delete_trans'] = '<a href="' . $url . '" style="color:#e67e22;" onclick="return confirm(\'' . esc_js(__('仅删除远程站译文，中文原文保留。确定？', 'lisun-manual')) . '\')">' . __('删译文', 'lisun-manual') . '</a>';
        }

        return $actions;
    }

    /**
     * 处理自定义 action（翻译、删译文）
     */
    public static function handle_list_actions() {
        if (empty($_GET['lisun_action']) || empty($_GET['id'])) {
            return;
        }

        $action = $_GET['lisun_action'];
        $post_id = intval($_GET['id']);

        // 翻译（完成后跳到翻译管理页看结果）
        if ($action === 'translate' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lisun_translate_' . $post_id)) {
            if (!Lisun_Manual_Translate::is_configured()) {
                wp_redirect(admin_url('admin.php?page=lisun-manual-translate&msg=no_api'));
                exit;
            }
            $result = Lisun_Manual_Translate::translate_post($post_id);
            if (is_wp_error($result)) {
                wp_redirect(admin_url('admin.php?page=lisun-manual-translate&msg=error&detail=' . urlencode($result->get_error_message())));
                exit;
            } else {
                wp_redirect(admin_url('admin.php?page=lisun-manual-translate&msg=done&time=' . $result['duration'] . '&tokens=' . ($result['prompt_tokens'] + $result['completion_tokens']) . '&chars=' . intval($result['chars'] ?? 0)));
                exit;
            }
        }

        // 推送已翻译内容到远程站
        if ($action === 'push' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lisun_push_' . $post_id)) {
            $result = Lisun_Manual_Sync::push_existing_translation($post_id);
            if (is_wp_error($result)) {
                add_action('admin_notices', function () use ($result) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('推送失败：', 'lisun-manual') . esc_html($result->get_error_message()) . '</p></div>';
                });
            } else {
                add_action('admin_notices', function () use ($result) {
                    echo '<div class="notice notice-success"><p>' . sprintf(__('推送成功！远程站页面 ID: %d', 'lisun-manual'), $result) . '</p></div>';
                });
            }
        }

        // 仅删除远程译文
        if ($action === 'delete_trans' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'lisun_delete_trans_' . $post_id)) {
            $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);
            if ($target_id) {
                $result = Lisun_Manual_Sync::delete_remote($target_id, true);
                if (is_wp_error($result)) {
                    add_action('admin_notices', function () use ($result) {
                        echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
                    });
                } else {
                    delete_post_meta($post_id, '_lisun_manual_target_id');
                    update_post_meta($post_id, '_lisun_manual_sync_status', 'pending');
                    add_action('admin_notices', function () {
                        echo '<div class="notice notice-success"><p>' . __('远程站译文已删除，中文原文保留', 'lisun-manual') . '</p></div>';
                    });
                }
            }
        }
    }

    // ==================== 各子页面 ====================

    /**
     * 批量导入页
     */
    public static function render_import() {
        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;

        echo '<div class="wrap lisun-import-wrap">';
        echo '<h1>' . __('批量导入说明书', 'lisun-manual') . '</h1>';

        self::render_step_indicator($step);

        $template_map = [
            1 => 'import-step1.php',
            2 => 'import-step2.php',
        ];

        if (isset($template_map[$step])) {
            $template_file = LISUN_MANUAL_PATH . 'templates/admin/' . $template_map[$step];
            if (file_exists($template_file)) {
                include $template_file;
            } else {
                echo '<div class="notice notice-error"><p>' . __('模板文件未找到', 'lisun-manual') . '</p></div>';
            }
        }

        echo '</div>';
    }

    private static function render_step_indicator($current_step) {
        $steps = [
            1 => __('步骤 1：上传 Word 文件', 'lisun-manual'),
            2 => __('步骤 2：执行导入', 'lisun-manual'),
        ];

        echo '<div class="lisun-steps">';
        foreach ($steps as $num => $label) {
            $class = 'lisun-step';
            if ($num === $current_step) {
                $class .= ' lisun-step-current';
            } elseif ($num < $current_step) {
                $class .= ' lisun-step-done';
            }
            $url = admin_url('admin.php?page=lisun-manual-import&step=' . $num);
            echo '<a href="' . esc_url($url) . '" class="' . $class . '">';
            echo '<span class="lisun-step-num">' . $num . '</span>';
            echo '<span class="lisun-step-label">' . esc_html($label) . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }

    public static function render_glossary() {
        $template = LISUN_MANUAL_PATH . 'templates/admin/glossary-list.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="wrap"><h1>' . __('术语库', 'lisun-manual') . '</h1><p>' . __('模板未找到', 'lisun-manual') . '</p></div>';
        }
    }

    public static function render_translate() {
        $template = LISUN_MANUAL_PATH . 'templates/admin/translate-list.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="wrap"><h1>' . __('翻译管理', 'lisun-manual') . '</h1><p>' . __('模板未找到', 'lisun-manual') . '</p></div>';
        }
    }

    public static function render_customers() {
        $template = LISUN_MANUAL_PATH . 'templates/admin/customer-list.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="wrap"><h1>' . __('客户权限', 'lisun-manual') . '</h1><p>' . __('模板未找到', 'lisun-manual') . '</p></div>';
        }
    }

    public static function render_settings() {
        ?>
        <div class="wrap">
            <h1><?php _e('系统设置', 'lisun-manual'); ?></h1>
            <?php settings_errors('lisun_manual_settings'); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('lisun_manual_settings_group');
                do_settings_sections('lisun_manual_settings');
                submit_button();
                ?>
            </form>

            <hr style="margin:32px 0 16px;">

            <h2><?php _e('维护工具', 'lisun-manual'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('访问日志', 'lisun-manual'); ?></th>
                    <td>
                        <?php
                        $log_count = count(get_option('lisun_access_logs', []));
                        ?>
                        <p><?php printf(__('当前共 %d 条访问记录。', 'lisun-manual'), $log_count); ?></p>
                        <button type="button" class="button button-small" id="lisun-clear-logs-btn" style="color:#d63638;">
                            <?php _e('清空全部日志', 'lisun-manual'); ?>
                        </button>
                        <span id="lisun-clear-logs-spinner" class="spinner" style="float:none;margin:0 0 0 8px;"></span>
                        <span id="lisun-clear-logs-msg" style="font-size:12px;color:#666;margin-left:8px;"></span>
                        <p class="description" style="margin-top:8px;">
                            <?php _e('清空后不可恢复。访问日志仅用于审计客户访问行为，清空不影响客户账号和密码。', 'lisun-manual'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <script>
        jQuery(function ($) {
            $('#lisun-clear-logs-btn').on('click', function () {
                if (!confirm('<?php _e('确定清空所有访问日志？此操作不可恢复。', 'lisun-manual'); ?>')) return;
                var $btn = $(this).prop('disabled', true);
                var $spinner = $('#lisun-clear-logs-spinner').addClass('is-active');
                $('#lisun-clear-logs-msg').text('');
                $.post(ajaxurl, {
                    action: 'lisun_delete_access_logs',
                    _nonce: '<?php echo wp_create_nonce("lisun_manual_nonce"); ?>'
                }, function () {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $('#lisun-clear-logs-msg').text('<?php _e('已清空。', 'lisun-manual'); ?>').css('color', '#00a32a');
                    setTimeout(function () { location.reload(); }, 1000);
                }).fail(function () {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $('#lisun-clear-logs-msg').text('<?php _e('操作失败，请重试。', 'lisun-manual'); ?>').css('color', '#d63638');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * 图片清理页
     */
    public static function render_image_clean() {
        $template = LISUN_MANUAL_PATH . 'templates/admin/image-clean.php';
        if (file_exists($template)) {
            include $template;
        } else {
            echo '<div class="wrap"><div class="notice notice-error"><p>'
               . __('模板文件未找到', 'lisun-manual') . '</p></div></div>';
        }
    }

    // ==================== 语言分区筛选 ====================

    /**
     * 在 CPT 列表页顶部添加语言筛选标签
     */
    public static function add_lang_filter_tabs($views) {
        global $wp_query;

        $current = $_GET['lisun_lang'] ?? '';
        $base = admin_url('edit.php?post_type=manual');

        // 尝试从 transient 获取计数（缓存 5 分钟，减少 GROUP BY 查询）
        $counts = get_transient('lisun_manual_lang_counts');
        if ($counts === false || !is_array($counts)) {
            global $wpdb;
            $counts = ['zh' => 0, 'en' => 0];
            $sql = "SELECT meta_value, COUNT(*) as cnt FROM {$wpdb->postmeta}
                    WHERE meta_key = '_lisun_manual_lang' AND post_id IN (
                        SELECT ID FROM {$wpdb->posts} WHERE post_type = 'manual' AND post_status != 'trash'
                    ) GROUP BY meta_value";
            $rows = $wpdb->get_results($sql);
            foreach ($rows as $row) {
                $lang = $row->meta_value ?: 'zh';
                if (isset($counts[$lang])) {
                    $counts[$lang] = (int) $row->cnt;
                }
            }
            set_transient('lisun_manual_lang_counts', $counts, 5 * MINUTE_IN_SECONDS);
        }

        $total = $counts['zh'] + $counts['en'];

        // 全部
        $class_all = empty($current) ? ' class="current"' : '';
        $views['all'] = '<a href="' . $base . '"' . $class_all . '>全部 <span class="count">(' . $total . ')</span></a>';

        // 中文
        $class_zh = $current === 'zh' ? ' class="current"' : '';
        $views['zh'] = '<a href="' . add_query_arg('lisun_lang', 'zh', $base) . '"' . $class_zh . '>中文 <span class="count">(' . $counts['zh'] . ')</span></a>';

        // English
        $class_en = $current === 'en' ? ' class="current"' : '';
        $views['en'] = '<a href="' . add_query_arg('lisun_lang', 'en', $base) . '"' . $class_en . '>English <span class="count">(' . $counts['en'] . ')</span></a>';

        return $views;
    }

    /**
     * 根据语言筛选标签过滤 CPT 列表
     */
    public static function filter_by_lang($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'manual') return;

        $lang = $_GET['lisun_lang'] ?? '';
        if (empty($lang) || !in_array($lang, ['zh', 'en'])) return;

        $query->set('meta_key', '_lisun_manual_lang');
        $query->set('meta_value', $lang);
    }

    /**
     * 判断是否为后台说明书列表搜索。
     */
    private static function is_manual_admin_search($query) {
        return is_admin()
            && $query instanceof WP_Query
            && $query->is_main_query()
            && $query->get('post_type') === 'manual'
            && trim((string) $query->get('s')) !== '';
    }

    /**
     * 把说明书的产品型号 product-no 加入后台搜索查询。
     */
    public static function join_manual_search_model($join, $query) {
        if (!self::is_manual_admin_search($query)) return $join;

        global $wpdb;
        if (strpos($join, 'lisun_manual_model_meta') === false) {
            $join .= " LEFT JOIN {$wpdb->postmeta} AS lisun_manual_model_meta"
                   . " ON lisun_manual_model_meta.post_id = {$wpdb->posts}.ID"
                   . " AND lisun_manual_model_meta.meta_key = 'product-no'";
        }
        if (strpos($join, 'lisun_manual_tag_relationships') === false) {
            $join .= " LEFT JOIN {$wpdb->term_relationships} AS lisun_manual_tag_relationships"
                   . " ON lisun_manual_tag_relationships.object_id = {$wpdb->posts}.ID"
                   . " LEFT JOIN {$wpdb->term_taxonomy} AS lisun_manual_tag_taxonomy"
                   . " ON lisun_manual_tag_taxonomy.term_taxonomy_id = lisun_manual_tag_relationships.term_taxonomy_id"
                   . " AND lisun_manual_tag_taxonomy.taxonomy = 'post_tag'"
                   . " LEFT JOIN {$wpdb->terms} AS lisun_manual_tags"
                   . " ON lisun_manual_tags.term_id = lisun_manual_tag_taxonomy.term_id";
        }
        return $join;
    }

    /**
     * 后台搜索同时匹配标题、摘要、正文和产品型号。
     * 型号字段是列表中实际显示的“型号”来源，不能只依赖 WP 默认全文搜索。
     */
    public static function search_manual_model($search, $query) {
        if (!self::is_manual_admin_search($query)) return $search;

        global $wpdb;
        $term = trim((string) $query->get('s'));
        $like = '%' . $wpdb->esc_like($term) . '%';

        return $wpdb->prepare(
            " AND ({$wpdb->posts}.post_title LIKE %s"
            . " OR {$wpdb->posts}.post_excerpt LIKE %s"
            . " OR {$wpdb->posts}.post_content LIKE %s"
            . " OR lisun_manual_model_meta.meta_value LIKE %s"
            . " OR lisun_manual_tags.name LIKE %s"
            . " OR lisun_manual_tags.slug LIKE %s)",
            $like,
            $like,
            $like,
            $like,
            $like,
            $like
        );
    }

    /**
     * 精确型号优先，其次是型号前缀、标题精确匹配和标题前缀匹配。
     */
    public static function order_manual_search_results($orderby, $query) {
        if (!self::is_manual_admin_search($query)) return $orderby;

        global $wpdb;
        $term = trim((string) $query->get('s'));
        $exact = $wpdb->prepare('%s', $term);
        $prefix = $wpdb->prepare('%s', $wpdb->esc_like($term) . '%');

        $priority = "CASE"
            . " WHEN LOWER(TRIM(lisun_manual_model_meta.meta_value)) = LOWER({$exact}) THEN 0"
            . " WHEN LOWER(lisun_manual_tags.name) = LOWER({$exact})"
            . " OR LOWER(lisun_manual_tags.slug) = LOWER({$exact}) THEN 1"
            . " WHEN LOWER(lisun_manual_model_meta.meta_value) LIKE LOWER({$prefix}) THEN 2"
            . " WHEN LOWER(lisun_manual_tags.name) LIKE LOWER({$prefix})"
            . " OR LOWER(lisun_manual_tags.slug) LIKE LOWER({$prefix}) THEN 3"
            . " WHEN LOWER({$wpdb->posts}.post_title) = LOWER({$exact}) THEN 4"
            . " WHEN LOWER({$wpdb->posts}.post_title) LIKE LOWER({$prefix}) THEN 5"
            . " ELSE 6 END ASC";

        return $priority . ($orderby ? ', ' . $orderby : ", {$wpdb->posts}.post_date DESC");
    }

    /**
     * product-no 可能存在多条 meta，避免搜索结果重复。
     */
    public static function distinct_manual_search_results($distinct, $query) {
        if (!self::is_manual_admin_search($query)) return $distinct;
        return 'DISTINCT';
    }

    /**
     * 分类缓存：避免 N+1 查询
     * @var array post_id => ['cat' => ..., 'subcat' => ...] | null
     */
    private static $category_cache = [];

    /**
     * 产品表存在标记（避免重复 SHOW TABLES）
     * @var bool|null
     */
    private static $product_tables_exist = null;

    /**
     * 动态获取说明书所属分类
     *
     * 链路 A: tag → products 帖子 → product 分类法 → 匹配 subcat name
     * 链路 B: product-no → lisun_product_meta.model → lisun_product_items
     *
     * 后台 CPT 列表页自动批量预取，避免逐行查询（N+1 问题）。
     * 其他页面单次查询并缓存。
     *
     * @param int $post_id
     * @return array|null ['cat' => '大分类', 'subcat' => '小分类'] 或 null
     */
    public static function get_manual_category($post_id) {
        // 命中缓存直接返回
        if (array_key_exists($post_id, self::$category_cache)) {
            return self::$category_cache[$post_id];
        }

        // 后台 CPT 列表页：批量预取所有可见 Post 的分类
        if (is_admin() && function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'edit-manual') {
                global $wp_query;
                if (!empty($wp_query->posts)) {
                    $ids = [];
                    foreach ($wp_query->posts as $p) {
                        $ids[] = $p->ID;
                    }
                    self::batch_fetch_categories($ids);
                    return self::$category_cache[$post_id] ?? null;
                }
            }
        }

        // 兜底：单条查询
        return self::query_category_single($post_id);
    }

    /**
     * 批量预取分类 — 2 条查询覆盖整个列表页
     *
     * @param int[] $post_ids
     */
    private static function batch_fetch_categories($post_ids) {
        global $wpdb;

        if (empty($post_ids)) return;

        // 初始化缓存（标记为"已查询"避免重复触发）
        foreach ($post_ids as $id) {
            if (!array_key_exists($id, self::$category_cache)) {
                self::$category_cache[$id] = null;
            }
        }

        // 检查产品表是否存在（只查一次）
        if (self::$product_tables_exist === null) {
            $table_items = $wpdb->prefix . 'lisun_product_items';
            $check = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($table_items)
            ));
            self::$product_tables_exist = ($check === $table_items);
        }

        if (!self::$product_tables_exist) return;

        $table_items   = $wpdb->prefix . 'lisun_product_items';
        $table_subcats = $wpdb->prefix . 'lisun_product_subcats';
        $table_cats    = $wpdb->prefix . 'lisun_product_cats';
        $table_meta    = $wpdb->prefix . 'lisun_product_meta';

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $args = $post_ids;

        // 链路 A: tag → products → product taxonomy → subcat name（批量）
        $rows_a = $wpdb->get_results($wpdb->prepare("
            SELECT tr.object_id AS post_id, c.name AS cat_name, s.name AS subcat_name
            FROM wp_term_relationships tr
            JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_tag'
            JOIN wp_term_relationships ptr ON ptr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN wp_posts prod ON prod.ID = ptr.object_id AND prod.post_type = 'products'
            JOIN wp_term_relationships pcat_tr ON pcat_tr.object_id = prod.ID
            JOIN wp_term_taxonomy pcat_tt ON pcat_tt.term_taxonomy_id = pcat_tr.term_taxonomy_id AND pcat_tt.taxonomy = 'product'
            JOIN wp_terms pcat_t ON pcat_t.term_id = pcat_tt.term_id
            JOIN {$table_subcats} s ON s.name COLLATE utf8mb4_unicode_520_ci = pcat_t.name
            JOIN {$table_cats} c ON c.id = s.cat_id
            WHERE tr.object_id IN ({$placeholders})
        ", ...$args));

        foreach ($rows_a as $row) {
            if (!isset(self::$category_cache[$row->post_id]) || self::$category_cache[$row->post_id] === null) {
                self::$category_cache[$row->post_id] = ['cat' => $row->cat_name, 'subcat' => $row->subcat_name];
            }
        }

        // 收集链路 A 未找到的 post_id，用链路 B 批量补查
        $missing = [];
        foreach ($post_ids as $id) {
            if (self::$category_cache[$id] === null) {
                $missing[] = $id;
            }
        }

        if (empty($missing)) return;

        // 链路 B: product-no → lisun_product_meta.model → lisun_product_items（批量）
        $placeholders_b = implode(',', array_fill(0, count($missing), '%d'));
        $rows_b = $wpdb->get_results($wpdb->prepare("
            SELECT pm.post_id, c.name AS cat_name, s.name AS subcat_name
            FROM wp_postmeta pm
            JOIN {$table_meta} lpm ON lpm.model COLLATE utf8mb4_unicode_520_ci = pm.meta_value
            JOIN {$table_items} lpi ON lpi.product_id = lpm.product_id
            JOIN {$table_subcats} s ON s.id = lpi.subcat_id
            JOIN {$table_cats} c ON c.id = s.cat_id
            WHERE pm.post_id IN ({$placeholders_b}) AND pm.meta_key = 'product-no'
        ", ...$missing));

        foreach ($rows_b as $row) {
            if (!isset(self::$category_cache[$row->post_id]) || self::$category_cache[$row->post_id] === null) {
                self::$category_cache[$row->post_id] = ['cat' => $row->cat_name, 'subcat' => $row->subcat_name];
            }
        }

        // 收集链路 A+B 仍未找到的 post_id，用链路 C 批量补查
        $still_missing = [];
        foreach ($post_ids as $id) {
            if (self::$category_cache[$id] === null) {
                $still_missing[] = $id;
            }
        }

        if (empty($still_missing)) return;

        // 链路 C (fallback): tag → products 帖子 → product 分类法 term（批量）
        $placeholders_c = implode(',', array_fill(0, count($still_missing), '%d'));
        $rows_c = $wpdb->get_results($wpdb->prepare("
            SELECT tr.object_id AS post_id, pcat_t.name AS subcat_name, parent_t.name AS cat_name
            FROM wp_term_relationships tr
            JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_tag'
            JOIN wp_terms t ON t.term_id = tt.term_id
            JOIN wp_term_relationships ptr ON ptr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN wp_posts prod ON prod.ID = ptr.object_id AND prod.post_type = 'products'
            JOIN wp_term_relationships pcat_tr ON pcat_tr.object_id = prod.ID
            JOIN wp_term_taxonomy pcat_tt ON pcat_tt.term_taxonomy_id = pcat_tr.term_taxonomy_id AND pcat_tt.taxonomy = 'product'
            JOIN wp_terms pcat_t ON pcat_t.term_id = pcat_tt.term_id
            LEFT JOIN wp_term_taxonomy parent_tt ON parent_tt.term_id = pcat_tt.parent AND parent_tt.taxonomy = 'product'
            LEFT JOIN wp_terms parent_t ON parent_t.term_id = parent_tt.term_id
            WHERE tr.object_id IN ({$placeholders_c})
        ", ...$still_missing));

        foreach ($rows_c as $row) {
            if (!isset(self::$category_cache[$row->post_id]) || self::$category_cache[$row->post_id] === null) {
                self::$category_cache[$row->post_id] = [
                    'cat'    => $row->cat_name ?: '',
                    'subcat' => $row->subcat_name,
                ];
            }
        }
    }

    /**
     * 单条查询分类（非列表页场景的兜底）
     *
     * @param int $post_id
     * @return array|null
     */
    private static function query_category_single($post_id) {
        global $wpdb;
        $table_items   = $wpdb->prefix . 'lisun_product_items';
        $table_subcats = $wpdb->prefix . 'lisun_product_subcats';
        $table_cats    = $wpdb->prefix . 'lisun_product_cats';
        $table_meta    = $wpdb->prefix . 'lisun_product_meta';

        if (self::$product_tables_exist === null) {
            $check = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($table_items)
            ));
            self::$product_tables_exist = ($check === $table_items);
        }
        if (!self::$product_tables_exist) {
            self::$category_cache[$post_id] = null;
            return null;
        }

        // 链路 A
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT c.name AS cat_name, s.name AS subcat_name
            FROM wp_term_relationships tr
            JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_tag'
            JOIN wp_term_relationships ptr ON ptr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN wp_posts prod ON prod.ID = ptr.object_id AND prod.post_type = 'products'
            JOIN wp_term_relationships pcat_tr ON pcat_tr.object_id = prod.ID
            JOIN wp_term_taxonomy pcat_tt ON pcat_tt.term_taxonomy_id = pcat_tr.term_taxonomy_id AND pcat_tt.taxonomy = 'product'
            JOIN wp_terms pcat_t ON pcat_t.term_id = pcat_tt.term_id
            JOIN {$table_subcats} s ON s.name COLLATE utf8mb4_unicode_520_ci = pcat_t.name
            JOIN {$table_cats} c ON c.id = s.cat_id
            WHERE tr.object_id = %d
            LIMIT 1
        ", $post_id));

        if ($row) {
            self::$category_cache[$post_id] = ['cat' => $row->cat_name, 'subcat' => $row->subcat_name];
            return self::$category_cache[$post_id];
        }

        // 链路 B
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT c.name AS cat_name, s.name AS subcat_name
            FROM wp_postmeta pm
            JOIN {$table_meta} lpm ON lpm.model COLLATE utf8mb4_unicode_520_ci = pm.meta_value
            JOIN {$table_items} lpi ON lpi.product_id = lpm.product_id
            JOIN {$table_subcats} s ON s.id = lpi.subcat_id
            JOIN {$table_cats} c ON c.id = s.cat_id
            WHERE pm.post_id = %d AND pm.meta_key = 'product-no'
            LIMIT 1
        ", $post_id));

        if ($row) {
            self::$category_cache[$post_id] = ['cat' => $row->cat_name, 'subcat' => $row->subcat_name];
            return self::$category_cache[$post_id];
        }

        // 链路 C (fallback): tag → products 帖子 → product 分类法 term
        // 当产品在 lisun_product_* 表中没有记录时，直接使用 product 分类法
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT pcat_t.name AS subcat_name, parent_t.name AS cat_name
            FROM wp_term_relationships tr
            JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_tag'
            JOIN wp_terms t ON t.term_id = tt.term_id
            JOIN wp_term_relationships ptr ON ptr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN wp_posts prod ON prod.ID = ptr.object_id AND prod.post_type = 'products'
            JOIN wp_term_relationships pcat_tr ON pcat_tr.object_id = prod.ID
            JOIN wp_term_taxonomy pcat_tt ON pcat_tt.term_taxonomy_id = pcat_tr.term_taxonomy_id AND pcat_tt.taxonomy = 'product'
            JOIN wp_terms pcat_t ON pcat_t.term_id = pcat_tt.term_id
            LEFT JOIN wp_term_taxonomy parent_tt ON parent_tt.term_id = pcat_tt.parent AND parent_tt.taxonomy = 'product'
            LEFT JOIN wp_terms parent_t ON parent_t.term_id = parent_tt.term_id
            WHERE tr.object_id = %d
            LIMIT 1
        ", $post_id));

        if ($row) {
            self::$category_cache[$post_id] = [
                'cat'    => $row->cat_name ?: '',
                'subcat' => $row->subcat_name,
            ];
            return self::$category_cache[$post_id];
        }

        self::$category_cache[$post_id] = null;
        return null;
    }
}
