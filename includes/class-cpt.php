<?php
/**
 * Custom Post Type: manual
 * 注册说明书 Post Type + Meta 字段
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_CPT {

    public static function init() {
        // 注册 meta 字段（REST API 需要 show_in_rest 才能接受 meta 参数）
        self::register_meta_fields();

        // 替换原生标签框为自定义搜索选择框
        add_action('add_meta_boxes', [__CLASS__, 'replace_tag_metabox'], 99);
        add_action('add_meta_boxes', [__CLASS__, 'add_media_metabox']);
        add_action('add_meta_boxes', [__CLASS__, 'add_model_metabox']);
        add_action('add_meta_boxes', [__CLASS__, 'add_seo_metabox']);
        add_action('add_meta_boxes', [__CLASS__, 'add_term_panel_metabox']);
        add_action('save_post_manual', [__CLASS__, 'save_tag_metabox']);
        add_action('save_post_manual', [__CLASS__, 'save_media_metabox']);
        add_action('save_post_manual', [__CLASS__, 'save_model_metabox']);
        add_action('save_post_manual', [__CLASS__, 'save_seo_metabox']);

        // 旧 slug 301 重定向兜底（WordPress 内置 wp_old_slug_redirect 已处理 _wp_old_slug，
        // 这里补充处理 CDN/缓存层可能残存的旧 URL 变体）
        add_action('template_redirect', [__CLASS__, 'redirect_old_slug_pattern'], 0);

        // Rank Math SEO: 确保 manual CPT 支持 SEO meta box + Schema
        add_filter('rank_math/post_types/enabled', [__CLASS__, 'enable_rank_math']);

        // 说明书单页链接尾部加斜杠：/manual/slug/ 而不是 /manual/slug
        add_filter('post_type_link', [__CLASS__, 'add_trailing_slash'], 10, 2);

        // 阻止 WordPress canonical redirect 把 /manual/slug/ 重定向回 /manual/slug
        add_filter('redirect_canonical', [__CLASS__, 'prevent_trailing_slash_redirect'], 10, 2);

        // 懒加载由 lisun_manual_make_images_responsive() 在 the_content 优先级 5 统一处理，
        // 不再通过 wp_img_tag_add_loading_attr 禁用（该 filter 无法阻止已在 HTML 中的 loading 属性）
    }

    /**
     * 将 manual 加入 Rank Math 支持的 post type 列表
     */
    public static function enable_rank_math($post_types) {
        if (!in_array('manual', $post_types, true)) {
            $post_types[] = 'manual';
        }
        return $post_types;
    }

    /**
     * 说明书页面禁用图片懒加载
     *
     * 说明书单页模板有大量 DOM 操作（编号标题转换、TOC 生成、图注合并、表格包裹），
     * 会导致布局抖动，干扰 Intersection Observer 的懒加载判定，
     * 表现为图片间歇性不加载（非 404），硬刷新恢复。
     * 说明书是用户主动打开的文档页，直接加载所有图片不依赖滚动。
     */
    public static function disable_lazy_loading($value, $image, $context) {
        if (is_singular('manual') || get_post_type() === 'manual') {
            return false;
        }
        return $value;
    }

    // ==================== 产品型号 Meta Box（对标 products 的 product-no） ====================

    /**
     * 添加产品型号 meta box
     */
    public static function add_model_metabox() {
        add_meta_box(
            'lisun-manual-model',
            __('产品型号', 'lisun-manual'),
            [__CLASS__, 'render_model_metabox'],
            'manual',
            'side',
            'high'
        );
    }

    /**
     * 渲染产品型号 meta box — 搜索多选（复用 tag 选择器样式，仅可选已有 tag）
     */
    public static function render_model_metabox($post) {
        $product_nos = get_post_meta($post->ID, 'product-no', true);
        $selected = $product_nos ? array_map('trim', explode(',', $product_nos)) : [];
        $all_tags = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => false, 'orderby' => 'name']);
        wp_nonce_field('lisun_model_metabox', 'lisun_model_nonce');
        ?>
        <div class="lisun-model-select-wrap">
            <input type="text" id="lisun-model-search" placeholder="Search model..." autocomplete="off"
                   style="width:100%;margin-bottom:6px;">
            <div class="lisun-tag-dropdown" id="lisun-model-dropdown" style="display:none;max-height:180px;overflow-y:auto;border:1px solid #c3c4c7;border-radius:0 0 4px 4px;background:#fff;margin-bottom:6px;"></div>
            <div class="lisun-tag-chips" id="lisun-model-chips"></div>
            <select id="lisun-model-hidden" name="product-no[]" multiple style="display:none;"></select>
        </div>
        <p style="color:#888;font-size:12px;margin:4px 0 0;">Only existing tags can be selected.</p>
        <script>
        jQuery(function($){
            var allTags = <?php echo json_encode(array_map(function($t){ return ['slug'=>$t->slug,'name'=>$t->name]; }, $all_tags)); ?>;
            var selected = <?php echo json_encode(array_values($selected)); ?>;

            function renderChips(){
                var $c = $('#lisun-model-chips'), $h = $('#lisun-model-hidden');
                $c.empty(); $h.empty();
                selected.forEach(function(name, i){
                    $c.append('<span class="lisun-tag-chip">' + name + ' <button type="button" class="lisun-tag-remove" data-idx="'+i+'">&times;</button></span>');
                    $h.append('<option value="'+name+'" selected></option>');
                });
            }
            renderChips();

            $('#lisun-model-chips').on('click', '.lisun-tag-remove', function(){
                selected.splice($(this).data('idx'), 1);
                renderChips();
            });

            var timer;
            $('#lisun-model-search').on('input keydown', function(e){
                if (e.type === 'keydown' && e.key === 'Enter') {
                    e.preventDefault();
                    var val = $(this).val().trim();
                    if (!val) return;
                    var exact = allTags.filter(function(t){ return selected.indexOf(t.name)===-1 && t.name.toLowerCase()===val.toLowerCase(); })[0];
                    if (!exact) exact = allTags.filter(function(t){ return selected.indexOf(t.name)===-1 && t.slug.toLowerCase()===val.toLowerCase(); })[0];
                    if (exact) { selected.push(exact.name); renderChips(); $(this).val(''); }
                    $('#lisun-model-dropdown').hide();
                    return;
                }
                clearTimeout(timer);
                var q = $(this).val().trim();
                // 逗号分隔：前面部分自动选中，最后一段搜索
                var parts = q.split(',');
                var last = parts.pop().trim();
                parts.forEach(function(p){
                    p = p.trim();
                    if (!p) return;
                    var exact = allTags.filter(function(t){ return selected.indexOf(t.name)===-1 && t.name.toLowerCase()===p.toLowerCase(); })[0];
                    if (!exact) exact = allTags.filter(function(t){ return selected.indexOf(t.name)===-1 && t.slug.toLowerCase()===p.toLowerCase(); })[0];
                    if (exact) { selected.push(exact.name); }
                });
                if (parts.length > 0) { $(this).val(last); renderChips(); }
                if (!last) { $('#lisun-model-dropdown').hide(); return; }
                timer = setTimeout(function(){
                    var results = allTags.filter(function(t){
                        if (selected.indexOf(t.name) !== -1) return false;
                        return t.name.toLowerCase().indexOf(last.toLowerCase()) !== -1 || t.slug.toLowerCase().indexOf(last.toLowerCase()) !== -1;
                    }).slice(0, 15);
                    var $dd = $('#lisun-model-dropdown').empty();
                    if (!results.length) { $dd.append('<div style="padding:6px 10px;color:#999;">No matches</div>'); }
                    else { results.forEach(function(t){ $dd.append('<div class="lisun-tag-option" data-name="'+t.name+'" style="padding:4px 10px;cursor:pointer;">'+t.name+'</div>'); }); }
                    $dd.show();
                }, 150);
            });

            $('#lisun-model-dropdown').on('click', '.lisun-tag-option', function(){
                selected.push($(this).data('name'));
                renderChips();
                $('#lisun-model-search').val('').focus();
                $('#lisun-model-dropdown').hide();
            });

            $(document).on('click', function(e){ if(!$(e.target).closest('.lisun-model-select-wrap').length) $('#lisun-model-dropdown').hide(); });
        });
        </script>
        <?php
    }

    /**
     * 保存产品型号 meta box
     */
    public static function save_model_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['lisun_model_nonce']) || !wp_verify_nonce($_POST['lisun_model_nonce'], 'lisun_model_metabox')) return;

        $product_nos = isset($_POST['product-no']) ? (array) $_POST['product-no'] : [];
        $product_nos = array_filter(array_map('sanitize_text_field', array_map('wp_unslash', $product_nos)));
        if (!empty($product_nos)) {
            update_post_meta($post_id, 'product-no', implode(', ', $product_nos));
        } else {
            delete_post_meta($post_id, 'product-no');
        }
    }

    // ==================== SEO 三要素 Meta Box（对标 products ACF SEO 字段） ====================

    /**
     * 添加 SEO meta box
     */
    public static function add_seo_metabox() {
        add_meta_box(
            'lisun-manual-seo',
            __('SEO 三要素', 'lisun-manual'),
            [__CLASS__, 'render_seo_metabox'],
            'manual',
            'normal',
            'high'
        );
    }

    /**
     * 渲染 SEO meta box — title / description / keywords
     */
    public static function render_seo_metabox($post) {
        $seo_title       = get_post_meta($post->ID, 'title', true);
        $seo_description = get_post_meta($post->ID, 'description', true);
        $seo_keywords    = get_post_meta($post->ID, 'keywords', true);
        wp_nonce_field('lisun_seo_metabox', 'lisun_seo_nonce');
        ?>
        <style>
            .lisun-seo-field { margin-bottom: 12px; }
            .lisun-seo-field label { display: block; font-weight: 600; margin-bottom: 4px; }
            .lisun-seo-field input,
            .lisun-seo-field textarea { width: 100%; }
            .lisun-seo-field .char-count { font-size: 12px; color: #888; margin-top: 2px; text-align: right; }
        </style>
        <div class="lisun-seo-field">
            <label for="lisun-seo-title"><?php _e('SEO 标题', 'lisun-manual'); ?></label>
            <input type="text" name="seo_title" id="lisun-seo-title"
                   value="<?php echo esc_attr($seo_title); ?>"
                   placeholder="<?php echo esc_attr__('留空则使用文章标题', 'lisun-manual'); ?>">
            <div class="char-count"><span id="lisun-seo-title-count">0</span> / 70</div>
        </div>
        <div class="lisun-seo-field">
            <label for="lisun-seo-desc"><?php _e('SEO 描述', 'lisun-manual'); ?></label>
            <textarea name="seo_description" id="lisun-seo-desc" rows="3"
                      placeholder="<?php echo esc_attr__('页面 meta description', 'lisun-manual'); ?>"><?php echo esc_textarea($seo_description); ?></textarea>
            <div class="char-count"><span id="lisun-seo-desc-count">0</span> / 160</div>
        </div>
        <div class="lisun-seo-field">
            <label for="lisun-seo-keywords"><?php _e('SEO 关键词', 'lisun-manual'); ?></label>
            <input type="text" name="seo_keywords" id="lisun-seo-keywords"
                   value="<?php echo esc_attr($seo_keywords); ?>"
                   placeholder="<?php echo esc_attr__('用英文逗号分隔多个关键词', 'lisun-manual'); ?>">
        </div>
        <div class="lisun-seo-manual-generate" style="border-top:1px solid #dcdcde;padding-top:10px;margin-top:4px;">
            <button type="button" class="button" id="lisun-generate-seo"><?php _e('手动生成 SEO', 'lisun-manual'); ?></button>
            <span id="lisun-generate-seo-status" style="margin-left:8px;"></span>
            <p class="description" style="margin:6px 0 0;"><?php _e('默认生成失败或字段为空时，可点击此按钮重试。', 'lisun-manual'); ?></p>
        </div>
        <script>
        jQuery(function($) {
            function updateCount($el, max) {
                var len = $el.val().length;
                $el.closest('.lisun-seo-field').find('.char-count span').text(len).css('color', len > max ? '#e00' : '#888');
            }
            $('#lisun-seo-title').on('input', function() { updateCount($(this), 70); }).trigger('input');
            $('#lisun-seo-desc').on('input', function() { updateCount($(this), 160); }).trigger('input');

            $('#lisun-generate-seo').on('click', function() {
                var $button = $(this);
                var $status = $('#lisun-generate-seo-status');
                var hasSeo = $('#lisun-seo-title').val().trim() || $('#lisun-seo-desc').val().trim() || $('#lisun-seo-keywords').val().trim();
                if (hasSeo && !window.confirm('<?php echo esc_js(__('将覆盖当前 SEO 字段内容，确定继续吗？', 'lisun-manual')); ?>')) {
                    return;
                }

                $button.prop('disabled', true);
                $status.css('color', '#646970').text('<?php echo esc_js(__('正在调用 LLM...', 'lisun-manual')); ?>');
                $.post(ajaxurl, {
                    action: 'lisun_generate_manual_seo',
                    post_id: <?php echo (int) $post->ID; ?>,
                    nonce: '<?php echo esc_js(wp_create_nonce('lisun_generate_manual_seo')); ?>'
                }).done(function(response) {
                    if (response.success && response.data && response.data.seo) {
                        var seo = response.data.seo;
                        if (seo.title) $('#lisun-seo-title').val(seo.title).trigger('input');
                        if (seo.description) $('#lisun-seo-desc').val(seo.description).trigger('input');
                        if (seo.keywords) $('#lisun-seo-keywords').val(seo.keywords);
                        $status.css('color', '#008a20').text(response.data.message || '<?php echo esc_js(__('生成成功，请点击“更新”保存页面。', 'lisun-manual')); ?>');
                    } else {
                        $status.css('color', '#d63638').text((response.data && response.data.message) || '<?php echo esc_js(__('生成失败，请稍后重试。', 'lisun-manual')); ?>');
                    }
                }).fail(function() {
                    $status.css('color', '#d63638').text('<?php echo esc_js(__('请求失败，请检查网络或稍后重试。', 'lisun-manual')); ?>');
                }).always(function() {
                    $button.prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * 保存 SEO meta box
     */
    public static function save_seo_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['lisun_seo_nonce']) || !wp_verify_nonce($_POST['lisun_seo_nonce'], 'lisun_seo_metabox')) return;

        $fields = [
            'title'       => $_POST['seo_title'] ?? '',
            'description' => $_POST['seo_description'] ?? '',
            'keywords'    => $_POST['seo_keywords'] ?? '',
        ];

        // SEO 字段 → Rank Math 字段映射
        $rmk_map = [
            'title'       => 'rank_math_title',
            'description' => 'rank_math_description',
            'keywords'    => 'rank_math_focus_keyword',
        ];

        foreach ($fields as $key => $value) {
            $value = sanitize_text_field(wp_unslash($value));
            if ($value !== '') {
                update_post_meta($post_id, $key, $value);
                // 同步写入 Rank Math SEO 字段
                if (isset($rmk_map[$key])) {
                    update_post_meta($post_id, $rmk_map[$key], $value);
                }
            } else {
                delete_post_meta($post_id, $key);
                if (isset($rmk_map[$key])) {
                    delete_post_meta($post_id, $rmk_map[$key]);
                }
            }
        }
    }

    /**
     * 注册 Post Type
     */
    public static function register() {
        $is_en = get_option('lisun_page_lang', 'zh') === 'en';

        $labels = $is_en ? [
            'name'                  => 'Manuals',
            'singular_name'         => 'Manual',
            'add_new'               => 'Add Manual',
            'add_new_item'          => 'Add Manual',
            'edit_item'             => 'Edit Manual',
            'new_item'              => 'New Manual',
            'view_item'             => 'View Manual',
            'search_items'          => 'Search Manuals',
            'not_found'             => 'No manuals found',
            'not_found_in_trash'    => 'No manuals found in Trash',
            'all_items'             => 'All Manuals',
            'archives'              => 'Manual Archives',
        ] : [
            'name'                  => '说明书',
            'singular_name'         => '说明书',
            'add_new'               => '添加说明书',
            'add_new_item'          => '添加说明书',
            'edit_item'             => '编辑说明书',
            'new_item'              => '新说明书',
            'view_item'             => '查看说明书',
            'search_items'          => '搜索说明书',
            'not_found'             => '未找到说明书',
            'not_found_in_trash'    => '回收站中未找到说明书',
            'all_items'             => '全部说明书',
            'archives'              => '说明书归档',
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => ['slug' => 'manual', 'with_front' => false],
            'supports'            => ['title', 'editor', 'revisions'],
            'taxonomies'          => ['post_tag'],
            'show_in_rest'        => true,
            // 不自动创建菜单，由插件统一管理
            'show_in_menu'        => false,
            'show_ui'             => true,
            'capability_type'     => 'post',
            'publicly_queryable'  => true,
            'exclude_from_search' => false,
        ];

        register_post_type('manual', $args);
    }

    /**
     * 注册说明书分类法（型号 — 对标 products 的 product 分类法）
     */
    public static function register_taxonomies() {
        $labels = [
            'name'              => _x('说明书分类', 'taxonomy 名称', 'lisun-manual'),
            'singular_name'     => _x('说明书分类', 'taxonomy 单数名称', 'lisun-manual'),
            'search_items'      => __('搜索分类', 'lisun-manual'),
            'all_items'         => __('所有分类', 'lisun-manual'),
            'parent_item'       => __('上级分类', 'lisun-manual'),
            'parent_item_colon' => __('上级分类：', 'lisun-manual'),
            'edit_item'         => __('编辑分类', 'lisun-manual'),
            'update_item'       => __('更新分类', 'lisun-manual'),
            'add_new_item'      => __('添加新分类', 'lisun-manual'),
            'new_item_name'     => __('新分类名称', 'lisun-manual'),
            'menu_name'         => __('说明书分类', 'lisun-manual'),
        ];

        $args = [
            'labels'            => $labels,
            'hierarchical'      => true,
            'show_ui'           => false,
            'show_admin_column' => false,
            'show_in_menu'      => false,
            'show_in_rest'      => false,
            'rewrite'           => ['slug' => 'manual-model', 'with_front' => false],
            'capabilities'      => [
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ],
        ];

        register_taxonomy('manual_model', 'manual', $args);
    }

    /**
     * 注册 Meta 字段
     */
    public static function register_meta_fields() {
        $meta_fields = [
            '_lisun_manual_lang' => [
                'type'    => 'string',
                'default' => 'zh',
            ],
            '_lisun_manual_source_id' => [
                'type'    => 'integer',
                'default' => 0,
            ],
            '_lisun_manual_target_id' => [
                'type'    => 'integer',
                'default' => 0,
            ],
            '_lisun_manual_sync_status' => [
                'type'    => 'string',
                'default' => 'pending',
            ],
            '_lisun_manual_last_sync' => [
                'type'    => 'string',
                'default' => '',
            ],
            '_lisun_manual_lock_sync' => [
                'type'    => 'boolean',
                'default' => false,
            ],
            '_lisun_manual_videos' => [
                'type'    => 'string',
                'default' => '',
            ],
            '_lisun_manual_software' => [
                'type'    => 'string',
                'default' => '',
            ],
            // SEO meta 字段 — 注册后 REST API 可读写，翻译+推送时自动同步
            'title' => [
                'type'    => 'string',
                'default' => '',
            ],
            'description' => [
                'type'    => 'string',
                'default' => '',
            ],
            'keywords' => [
                'type'    => 'string',
                'default' => '',
            ],
            'product-no' => [
                'type'    => 'string',
                'default' => '',
            ],
        ];

        foreach ($meta_fields as $key => $config) {
            register_post_meta('manual', $key, [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => $config['type'],
                'default'       => $config['default'],
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
        }
    }

    /**
     * 获取指定语言的说明书
     */
    public static function get_by_lang($lang = 'zh', $args = []) {
        $defaults = [
            'post_type'      => 'manual',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => '_lisun_manual_lang',
                    'value' => $lang,
                ],
            ],
        ];

        $query_args = wp_parse_args($args, $defaults);
        return get_posts($query_args);
    }

    /**
     * 获取所有中文说明书页面
     */
    public static function get_zh_pages($status_filter = '') {
        $args = [
            'post_type'      => 'manual',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'   => '_lisun_manual_lang',
                    'value' => 'zh',
                ],
            ],
        ];

        // 按翻译状态过滤
        if ($status_filter === 'untranslated') {
            // 没有 target_id 或 sync_status 不是 synced
            $args['meta_query'][] = [
                'relation' => 'OR',
                [
                    'key'     => '_lisun_manual_sync_status',
                    'value'   => 'synced',
                    'compare' => '!=',
                ],
                [
                    'key'     => '_lisun_manual_sync_status',
                    'compare' => 'NOT EXISTS',
                ],
            ];
        } elseif ($status_filter === 'translated') {
            $args['meta_query'][] = [
                'key'     => '_lisun_manual_sync_status',
                'value'   => 'synced',
            ];
        } elseif ($status_filter === 'failed') {
            $args['meta_query'][] = [
                'key'     => '_lisun_manual_sync_status',
                'value'   => 'failed',
            ];
        }

        return get_posts($args);
    }

    /**
     * 获取未同步的页面（兼容旧代码）
     */
    public static function get_unsynced() {
        return self::get_zh_pages('untranslated');
    }

    /**
     * 统计各状态中文页面数量
     */
    public static function get_zh_counts() {
        $all = self::get_zh_pages();
        $total = count($all);
        $translated = 0;
        $failed = 0;
        $untranslated = 0;

        foreach ($all as $post) {
            $status = get_post_meta($post->ID, '_lisun_manual_sync_status', true);
            if ($status === 'synced') {
                $translated++;
            } elseif ($status === 'failed') {
                $failed++;
            } else {
                $untranslated++;
            }
        }

        return compact('total', 'translated', 'failed', 'untranslated');
    }

    // ==================== 自定义标签 Meta Box（搜索多选，不可新建） ====================

    /**
     * 替换原生 post_tag 框为自定义 AJAX 搜索选择框
     */
    public static function replace_tag_metabox() {
        remove_meta_box('tagsdiv-post_tag', 'manual', 'side');
        add_meta_box(
            'lisun-tag-selector',
            'Product Models (Tags)',
            [__CLASS__, 'render_tag_metabox'],
            'manual',
            'side',
            'default'
        );
    }

    /**
     * 渲染自定义标签选择 meta box
     */
    public static function render_tag_metabox($post) {
        $selected = wp_get_post_terms($post->ID, 'post_tag', ['fields' => 'slugs']);
        $all_tags = get_terms(['taxonomy' => 'post_tag', 'hide_empty' => false, 'orderby' => 'name']);
        ?>
        <div class="lisun-tag-select-wrap">
            <input type="text" id="lisun-tag-search" placeholder="Search tags..." autocomplete="off" style="width:100%;margin-bottom:6px;">
            <div class="lisun-tag-dropdown" id="lisun-tag-dropdown" style="display:none;max-height:180px;overflow-y:auto;border:1px solid #c3c4c7;border-radius:0 0 4px 4px;background:#fff;margin-bottom:6px;"></div>
            <div class="lisun-tag-chips" id="lisun-tag-chips"></div>
            <select id="lisun-tag-hidden" name="lisun_tags[]" multiple style="display:none;"></select>
            <input type="hidden" name="lisun_tag_metabox" value="1">
            <p style="color:#888;font-size:12px;margin:4px 0 0;">Only existing tags can be selected.</p>
        </div>
        <script>
        jQuery(function($){
            var allTags = <?php echo json_encode(array_map(function($t){ return ['slug'=>$t->slug,'name'=>$t->name]; }, $all_tags)); ?>;
            var selected = <?php echo json_encode(array_values($selected)); ?>;

            function renderChips(){
                var $c = $('#lisun-tag-chips'), $h = $('#lisun-tag-hidden');
                $c.empty(); $h.empty();
                selected.forEach(function(slug, i){
                    var tag = allTags.filter(function(t){ return t.slug === slug; })[0];
                    var name = tag ? tag.name : slug;
                    $c.append('<span class="lisun-tag-chip">' + name + ' <button type="button" class="lisun-tag-remove" data-idx="'+i+'">&times;</button></span>');
                    $h.append('<option value="'+slug+'" selected></option>');
                });
            }
            renderChips();

            $('#lisun-tag-chips').on('click', '.lisun-tag-remove', function(){
                selected.splice($(this).data('idx'), 1);
                renderChips();
            });

            var timer;
            // 输入搜索 + 逗号/回车自动选中
            $('#lisun-tag-search').on('input keydown', function(e){
                if (e.type === 'keydown' && e.key === 'Enter') {
                    e.preventDefault();
                    var val = $(this).val().trim();
                    if (!val) return;
                    // 先找精确匹配
                    var exact = allTags.filter(function(t){ return selected.indexOf(t.slug)===-1 && t.name.toLowerCase()===val.toLowerCase(); })[0];
                    if (!exact) exact = allTags.filter(function(t){ return selected.indexOf(t.slug)===-1 && t.slug.toLowerCase()===val.toLowerCase(); })[0];
                    if (exact) { selected.push(exact.slug); renderChips(); $(this).val(''); }
                    $('#lisun-tag-dropdown').hide();
                    return;
                }
                clearTimeout(timer);
                var q = $(this).val().trim();
                // 逗号分隔：取最后一段搜索
                var parts = q.split(',');
                var last = parts.pop().trim();
                // 逗号前的部分尝试精确匹配自动选中
                parts.forEach(function(p){
                    p = p.trim();
                    if (!p) return;
                    var exact = allTags.filter(function(t){ return selected.indexOf(t.slug)===-1 && t.name.toLowerCase()===p.toLowerCase(); })[0];
                    if (!exact) exact = allTags.filter(function(t){ return selected.indexOf(t.slug)===-1 && t.slug.toLowerCase()===p.toLowerCase(); })[0];
                    if (exact) { selected.push(exact.slug); }
                });
                if (parts.length > 0) { $(this).val(last); renderChips(); }
                if(!last){ $('#lisun-tag-dropdown').hide(); return; }
                timer = setTimeout(function(){
                    var results = allTags.filter(function(t){
                        if(selected.indexOf(t.slug) !== -1) return false;
                        return t.name.toLowerCase().indexOf(last.toLowerCase()) !== -1 || t.slug.toLowerCase().indexOf(last.toLowerCase()) !== -1;
                    }).slice(0, 15);
                    var $dd = $('#lisun-tag-dropdown').empty();
                    if(!results.length){ $dd.append('<div style="padding:6px 10px;color:#999;">No matches</div>'); }
                    else { results.forEach(function(t){ $dd.append('<div class="lisun-tag-option" data-slug="'+t.slug+'" style="padding:4px 10px;cursor:pointer;">'+t.name+'</div>'); }); }
                    $dd.show();
                }, 150);
            });

            $('#lisun-tag-dropdown').on('click', '.lisun-tag-option', function(){
                selected.push($(this).data('slug'));
                renderChips();
                $('#lisun-tag-search').val('').focus();
                $('#lisun-tag-dropdown').hide();
            });

            $(document).on('click', function(e){ if(!$(e.target).closest('.lisun-tag-select-wrap').length) $('#lisun-tag-dropdown').hide(); });

            // 样式
            $('<style>.lisun-tag-chip{display:inline-flex;align-items:center;gap:3px;background:#e6f4ff;color:#1677ff;border:1px solid #91caff;border-radius:3px;padding:1px 6px;font-size:12px;margin:2px 4px 2px 0;}.lisun-tag-remove{background:none;border:none;cursor:pointer;font-size:14px;color:#1677ff;padding:0;line-height:1;}.lisun-tag-remove:hover{color:#cf1322;}.lisun-tag-option:hover{background:#f0f6ff;color:#2271b1;}</style>').appendTo('head');
        });
        </script>
        <?php
    }

    // ==================== 本篇术语面板 Meta Box ====================

    /**
     * 详情页「本篇术语」面板。仅在启用术语表且设置里打开
     * 「详情页显示术语配置面板」时注册。
     */
    public static function add_term_panel_metabox() {
        if (!class_exists('Lisun_Manual_Glossary') || !Lisun_Manual_Glossary::is_enabled()) {
            return;
        }
        if (!get_option('lisun_show_post_term_panel', true)) {
            return;
        }
        add_meta_box(
            'lisun-post-term-panel',
            __('本篇术语（翻译时应用）', 'lisun-manual'),
            [__CLASS__, 'render_term_panel_metabox'],
            'manual',
            'side',
            'default'
        );
    }

    public static function render_term_panel_metabox($post) {
        $post_id = (int) $post->ID;
        $terms = Lisun_Manual_Glossary::post_term_config($post_id);

        // 全局术语折叠（极少改动），手动/自定义术语按导入时间倒序（新的在上）。
        $global_terms = [];
        $article_terms = [];
        foreach ($terms as $term) {
            if (($term['scope'] ?? '') === 'global') {
                $global_terms[] = $term;
            } else {
                $article_terms[] = $term;
            }
        }
        $by_import_date_desc = function ($a, $b) {
            return strcmp((string) ($b['import_date'] ?? ''), (string) ($a['import_date'] ?? ''));
        };
        usort($global_terms, $by_import_date_desc);
        usort($article_terms, $by_import_date_desc);
        ?>
        <div id="lisun-post-term-panel-root">
            <p style="color:#888;font-size:12px;margin:0 0 8px;">
                <?php _e('勾选本次翻译要应用的术语；未勾选术语按普通文本翻译。全局术语默认启用，可取消以形成「本篇排除」。', 'lisun-manual'); ?>
            </p>
            <div id="lisun-post-term-list" style="max-height:320px;overflow-y:auto;margin-bottom:8px;">
                <?php if (!empty($global_terms)): ?>
                    <details class="lisun-term-global" style="margin-bottom:6px;">
                        <summary style="cursor:pointer;font-size:12px;color:#2271b1;font-weight:600;">
                            <?php echo esc_html(sprintf(__('全局术语（%d）', 'lisun-manual'), count($global_terms))); ?>
                        </summary>
                        <div style="margin-left:14px;border-left:2px solid #eee;padding-left:6px;margin-top:4px;">
                            <?php foreach ($global_terms as $term): ?>
                                <label style="display:block;font-size:12px;padding:1px 0;">
                                    <input type="checkbox" class="lisun-post-term-check" data-term-id="<?php echo esc_attr($term['term_id']); ?>"
                                           <?php checked($term['enabled']); ?>>
                                    <span style="color:#333;"><?php echo esc_html($term['zh']); ?></span>
                                    <span style="color:#999;">→ <?php echo esc_html($term['en']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
                <?php if (!empty($article_terms)): ?>
                    <div class="lisun-term-article">
                        <div style="font-size:11px;color:#888;border-top:1px solid #eee;margin:2px 0 6px;padding-top:6px;">
                            <?php _e('手动 / 自定义术语（新的在上）', 'lisun-manual'); ?>
                        </div>
                        <?php foreach ($article_terms as $term): ?>
                            <label style="display:block;font-size:12px;padding:1px 0;">
                                <input type="checkbox" class="lisun-post-term-check" data-term-id="<?php echo esc_attr($term['term_id']); ?>"
                                       <?php checked($term['enabled']); ?>>
                                <span style="color:#333;"><?php echo esc_html($term['zh']); ?></span>
                                <span style="color:#999;">→ <?php echo esc_html($term['en']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <p>
                <button type="button" class="button button-primary button-small" id="lisun-post-term-tmp"><?php _e('仅本次翻译使用', 'lisun-manual'); ?></button>
                <button type="button" class="button button-small" id="lisun-post-term-save"><?php _e('保存为本篇默认术语', 'lisun-manual'); ?></button>
            </p>
            <p id="lisun-post-term-msg" style="color:#2271b1;font-size:12px;display:none;"></p>
        </div>
        <script>
        jQuery(function ($) {
            var postId = <?php echo (int) $post_id; ?>;

            function collectIds() {
                return $('#lisun-post-term-list .lisun-post-term-check:checked').map(function () {
                    return $(this).data('term-id');
                }).get();
            }

            function flash(msg, ok) {
                $('#lisun-post-term-msg').text(msg).css('color', ok ? '#2271b1' : '#d63638').show();
                setTimeout(function () { $('#lisun-post-term-msg').fadeOut(); }, 3000);
            }

            $('#lisun-post-term-tmp').on('click', function () {
                var $btn = $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'lisun_set_post_term_override',
                    _nonce: <?php echo wp_json_encode(wp_create_nonce('lisun_manual_nonce')); ?>,
                    post_id: postId,
                    enabled_ids: collectIds()
                }, function (res) {
                    $btn.prop('disabled', false);
                    if (res.success) flash(res.data.message || '已记录临时选择', true);
                    else flash((res.data && res.data.message) || '操作失败', false);
                }).fail(function () {
                    $btn.prop('disabled', false);
                    flash('请求失败', false);
                });
            });

            $('#lisun-post-term-save').on('click', function () {
                var $btn = $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'lisun_save_post_term_config',
                    _nonce: <?php echo wp_json_encode(wp_create_nonce('lisun_manual_nonce')); ?>,
                    post_id: postId,
                    enabled_ids: collectIds()
                }, function (res) {
                    $btn.prop('disabled', false);
                    if (res.success) flash('已保存为本篇默认术语', true);
                    else flash((res.data && res.data.message) || '保存失败', false);
                }).fail(function () {
                    $btn.prop('disabled', false);
                    flash('保存失败', false);
                });
            });
        });
        </script>
        <?php
    }

    // ==================== 视频 / 软件下载 Meta Box（支持多项） ====================

    /**
     * 添加视频和软件下载 meta box
     */
    public static function add_media_metabox() {
        add_meta_box(
            'lisun-manual-media',
            'Related Downloads（视频 & 软件）',
            [__CLASS__, 'render_media_metabox'],
            'manual',
            'normal',
            'low'
        );
    }

    /**
     * 渲染视频 / 软件下载 meta box — 动态增删行
     */
    public static function render_media_metabox($post) {
        $videos   = json_decode(get_post_meta($post->ID, '_lisun_manual_videos', true), true) ?: [];
        $software = json_decode(get_post_meta($post->ID, '_lisun_manual_software', true), true) ?: [];
        ?>
        <style>
            .lisun-media-section { margin-bottom: 16px; }
            .lisun-media-section h4 { margin: 8px 0 4px; }
            .lisun-media-row { display:flex; gap:8px; align-items:center; margin-bottom:6px; }
            .lisun-media-row input { flex:1; min-width:0; }
            .lisun-media-row .lisun-media-name { flex:2; }
            .lisun-media-row .lisun-media-url { flex:3; }
            .lisun-media-row button { white-space:nowrap; }
            .lisun-media-add { margin-top:4px; }
        </style>

        <!-- 软件 -->
        <div class="lisun-media-section">
            <h4>💾 软件与驱动</h4>
            <div id="lisun-software-rows">
                <?php foreach ($software as $i => $s): ?>
                <div class="lisun-media-row">
                    <input type="text" name="lisun_software_name[]" value="<?php echo esc_attr($s['name'] ?? ''); ?>"
                           placeholder="软件名称" class="lisun-media-name">
                    <input type="url" name="lisun_software_url[]" value="<?php echo esc_url($s['url'] ?? ''); ?>"
                           placeholder="Download URL 1" class="lisun-media-url">
                    <input type="url" name="lisun_software_url2[]" value="<?php echo esc_url($s['url2'] ?? ''); ?>"
                           placeholder="Download URL 2" class="lisun-media-url">
                    <button type="button" class="button lisun-remove-row">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button lisun-media-add" data-target="lisun-software-rows" data-fields='[{"name":"lisun_software_name[]","cls":"lisun-media-name","ph":"软件名称"},{"name":"lisun_software_url[]","cls":"lisun-media-url","ph":"Download URL 1","type":"url"},{"name":"lisun_software_url2[]","cls":"lisun-media-url","ph":"Download URL 2","type":"url"}]'>
                + 添加软件
            </button>
        </div>

        <!-- 视频 -->
        <div class="lisun-media-section">
            <h4>🎬 视频资料</h4>
            <div id="lisun-video-rows">
                <?php foreach ($videos as $i => $v): ?>
                <div class="lisun-media-row">
                    <input type="text" name="lisun_video_name[]" value="<?php echo esc_attr($v['name'] ?? ''); ?>"
                           placeholder="视频名称" class="lisun-media-name">
                    <input type="url" name="lisun_video_url[]" value="<?php echo esc_url($v['url'] ?? ''); ?>"
                           placeholder="https://youtu.be/..." class="lisun-media-url">
                    <button type="button" class="button lisun-remove-row">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button lisun-media-add" data-target="lisun-video-rows" data-fields='[{"name":"lisun_video_name[]","cls":"lisun-media-name","ph":"视频名称"},{"name":"lisun_video_url[]","cls":"lisun-media-url","ph":"https://youtu.be/...","type":"url"}]'>
                + 添加视频
            </button>
        </div>

        <p class="description" style="margin-top:8px;">全部留空则不显示"相关下载"模块。</p>

        <script>
        jQuery(function($) {
            // 添加行
            $('.lisun-media-add').on('click', function() {
                var target = $('#' + $(this).data('target'));
                var fields = $(this).data('fields');
                var row = $('<div class="lisun-media-row">');
                fields.forEach(function(f) {
                    var attrs = 'name="' + f.name + '" placeholder="' + f.ph + '" class="' + (f.cls || '') + '"';
                    if (f.type === 'url') attrs += ' type="url"';
                    else attrs += ' type="text"';
                    row.append($('<input>').attr('name', f.name).attr('placeholder', f.ph)
                        .attr('type', f.type || 'text').addClass(f.cls || ''));
                });
                row.append('<button type="button" class="button lisun-remove-row">✕</button>');
                target.append(row);
            });

            // 删除行
            $(document).on('click', '.lisun-remove-row', function() {
                $(this).closest('.lisun-media-row').remove();
            });
        });
        </script>
        <?php
    }

    /**
     * 保存视频 / 软件下载字段（JSON 数组）
     */
    public static function save_media_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (wp_is_post_revision($post_id)) return;

        // 视频
        $videos = [];
        $v_names = $_POST['lisun_video_name'] ?? [];
        $v_urls  = $_POST['lisun_video_url'] ?? [];
        foreach ($v_names as $i => $name) {
            $url = trim($v_urls[$i] ?? '');
            $name = trim($name);
            if ($url !== '') {
                $videos[] = ['name' => $name ?: 'Video ' . ($i + 1), 'url' => esc_url_raw($url)];
            }
        }
        update_post_meta($post_id, '_lisun_manual_videos', !empty($videos) ? wp_json_encode($videos, JSON_UNESCAPED_UNICODE) : '');

        // 软件
        $software = [];
        $s_names  = $_POST['lisun_software_name'] ?? [];
        $s_urls   = $_POST['lisun_software_url'] ?? [];
        $s_urls2  = $_POST['lisun_software_url2'] ?? [];
        foreach ($s_names as $i => $n) {
            $url  = trim($s_urls[$i] ?? '');
            $url2 = trim($s_urls2[$i] ?? '');
            if ($url !== '' || $url2 !== '') {
                $entry = ['name' => trim($n) ?: 'Software ' . ($i + 1)];
                if ($url !== '')  $entry['url']  = esc_url_raw($url);
                if ($url2 !== '') $entry['url2'] = esc_url_raw($url2);
                $software[] = $entry;
            }
        }
        update_post_meta($post_id, '_lisun_manual_software', !empty($software) ? wp_json_encode($software, JSON_UNESCAPED_UNICODE) : '');
    }

    /**
     * 保存自定义标签选择
     */
    public static function save_tag_metabox($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (wp_is_post_revision($post_id)) return;

        // 非编辑表单提交（如 AJAX 导入）时不覆盖已有标签
        if (!isset($_POST['lisun_tag_metabox'])) return;

        $tags = isset($_POST['lisun_tags']) ? array_map('sanitize_text_field', (array) $_POST['lisun_tags']) : [];
        wp_set_post_terms($post_id, $tags, 'post_tag', false);
    }

    /**
     * 给 manual 单页链接尾部追加斜杠
     *
     * /manual/ls-b65-eocibp  →  /manual/ls-b65-eocibp/
     */
    public static function add_trailing_slash($permalink, $post) {
        if ($post->post_type === 'manual') {
            return trailingslashit($permalink);
        }
        return $permalink;
    }

    /**
     * 阻止 WordPress 把带斜杠的 manual 链接重定向回不带斜杠的版本
     *
     * WordPress redirect_canonical 默认会尝试将 URL "规范化"，
     * 可能把 /manual/slug/ → /manual/slug 重定向，
     * 这里对 manual 单页禁用这个行为。
     */
    public static function prevent_trailing_slash_redirect($redirect_url, $requested_url) {
        if (is_singular('manual')) {
            return false;
        }
        return $redirect_url;
    }

    /**
     * 兜底 301：URL 中的 slug 与当前帖子实际 slug 不一致时跳转
     *
     * WordPress 内置的 wp_old_slug_redirect 已处理通过 wp_update_post 变更 slug
     * 产生的旧链接。这里作为补充：当 URL 仍匹配到帖子（如 WordPress 模糊匹配），
     * 但 slug 不完全一致时，发出 301 跳转到规范链接。
     */
    public static function redirect_old_slug_pattern() {
        // 仅处理 manual 单页
        if (!is_singular('manual')) return;

        $post = get_queried_object();
        if (!$post) return;

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        // 提取 URL 中的 manual slug
        if (!preg_match('#/manual/([^/?]+)#', $request_uri, $m)) return;

        $url_slug = trim($m[1], '/');
        if ($url_slug !== $post->post_name) {
            // URL slug 与帖子当前 slug 不一致 → 301 到正确链接
            wp_redirect(get_permalink($post), 301);
            exit;
        }
    }
}
