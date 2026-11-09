<?php
/**
 * Plugin Name: LISUN Manual System
 * Plugin URI:  https://lisungroup.cn
 * Description: 双站说明书管理系统 — Word 批量导入、DeepSeek 自动翻译、双站同步、密码保护
 * Version:     1.1.35
 * Author:      LISUN Group
 * License:     Proprietary
 * Text Domain: lisun-manual
 * Domain Path: /languages
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 插件常量
define('LISUN_MANUAL_VERSION', '1.1.35');
define('LISUN_MANUAL_PATH', plugin_dir_path(__FILE__));
define('LISUN_MANUAL_URL', plugin_dir_url(__FILE__));
define('LISUN_MANUAL_FILE', __FILE__);

// Composer autoload（如果存在）
$composer_autoload = LISUN_MANUAL_PATH . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// 加载公共 trait
require_once LISUN_MANUAL_PATH . 'includes/trait-nonce.php';
// 加载核心类
require_once LISUN_MANUAL_PATH . 'includes/class-cpt.php';
require_once LISUN_MANUAL_PATH . 'includes/class-admin-menu.php';
require_once LISUN_MANUAL_PATH . 'includes/class-import.php';
require_once LISUN_MANUAL_PATH . 'includes/class-import-parser.php';
// REST 路由必须在插件加载时提前挂载，避免 REST 请求时序导致接口未注册。
add_action('rest_api_init', ['Lisun_Manual_Import_Parser', 'register_rest_routes']);
require_once LISUN_MANUAL_PATH . 'includes/class-glossary.php';
require_once LISUN_MANUAL_PATH . 'includes/class-glossary-apply.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-schema.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-html-tokenizer.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-unit-extractor.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-term-matcher.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-unit-transport.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-term-boundary.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-materializer.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-repository.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-planner.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-deepl-adapter.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-google-auth.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-google-glossary.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-google-adapter.php';
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-runner.php';
require_once LISUN_MANUAL_PATH . 'includes/lib/simple-html-dom.php';
require_once LISUN_MANUAL_PATH . 'includes/class-translate.php';
require_once LISUN_MANUAL_PATH . 'includes/class-translate-log.php';
require_once LISUN_MANUAL_PATH . 'includes/class-sync.php';
require_once LISUN_MANUAL_PATH . 'includes/class-access-control.php';
require_once LISUN_MANUAL_PATH . 'includes/class-customer.php';
require_once LISUN_MANUAL_PATH . 'includes/class-settings.php';
require_once LISUN_MANUAL_PATH . 'includes/class-proxy-subscription.php';
require_once LISUN_MANUAL_PATH . 'includes/class-image-cleaner.php';

/**
 * 插件激活钩子
 */
function lisun_manual_activate() {
    // 确保 CPT 注册然后刷新重写规则
    Lisun_Manual_CPT::register();
    flush_rewrite_rules();

    // 创建翻译日志表
    Lisun_Manual_Translate_Log::create_table();

    // 创建翻译队列表
    Lisun_Manual_Translate::create_queue_table();

    // 创建 V2 文档/单元/术语关系表（仅新增，不修改 V1 数据）
    Lisun_Manual_V2_Schema::create_tables();
    update_option('lisun_translation_v2_schema_version', Lisun_Manual_V2_Schema::VERSION, false);

    // 默认选项
    if (!get_option('lisun_glossary')) {
        update_option('lisun_glossary', []);
    }
    if (!get_option('lisun_customers')) {
        update_option('lisun_customers', []);
    }
    if (!get_option('lisun_access_logs')) {
        update_option('lisun_access_logs', []);
    }
    if (!get_option('lisun_img_maps')) {
        update_option('lisun_img_maps', []);
    }
}

register_activation_hook(__FILE__, 'lisun_manual_activate');

register_deactivation_hook(__FILE__, function () {
    if (class_exists('Lisun_Manual_Import_Parser')) {
        Lisun_Manual_Import_Parser::deactivate();
    }
    if (class_exists('Lisun_Manual_Proxy_Subscription')) {
        Lisun_Manual_Proxy_Subscription::deactivate();
    }
});

/**
 * 插件卸载钩子
 */
function lisun_manual_uninstall() {
    // 不删除数据，保留配置
}

register_uninstall_hook(__FILE__, 'lisun_manual_uninstall');

/**
 * 一次性迁移：把历史翻译备份等大体积 option 从 autoload 改为非 autoload。
 *
 * 背景：早期 save_translation_backup() 用 update_option 不带 autoload 参数，
 * 备份（每篇可达数 MB，增量翻译后还重复存储 zh_segments/en_parts）默认
 * autoload='yes'。wp_load_alloptions() 会在每次请求把它们全部加载进内存，
 * 翻译数百篇后 PHP-FPM OOM，表现为 Cloudflare 525。
 */
function lisun_manual_fix_autoload_once() {
    if (get_option('lisun_manual_autoload_fixed_v2')) {
        return;
    }
    global $wpdb;
    foreach (['lisun_trans_bak_%', 'lisun_access_logs'] as $like) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name LIKE %s AND autoload = 'yes'",
            $like
        ));
    }
    update_option('lisun_manual_autoload_fixed_v2', 1, false);
}

/**
 * 初始化插件
 */
function lisun_manual_init() {
    // 把历史大 option 从 autoload 迁移为非 autoload（一次性，避免每次请求 OOM）
    lisun_manual_fix_autoload_once();
    Lisun_Manual_V2_Schema::maybe_upgrade();

    // 注册 CPT
    Lisun_Manual_CPT::init();
    Lisun_Manual_CPT::register();
    Lisun_Manual_CPT::register_taxonomies();
    Lisun_Manual_CPT::register_meta_fields();

    // 初始化各模块
    Lisun_Manual_Admin_Menu::init();
    Lisun_Manual_Import_Parser::init();
    Lisun_Manual_Import::init();
    Lisun_Manual_Glossary::init();
    Lisun_Manual_Glossary_Apply::init();
    Lisun_Manual_Translate::init();
    Lisun_Manual_Translate_Log::init();
    Lisun_Manual_Sync::init();
    Lisun_Manual_Access_Control::init();
    Lisun_Manual_Customer::init();
    Lisun_Manual_Settings::init();
    Lisun_Manual_Proxy_Subscription::init();
    Lisun_Manual_Image_Cleaner::init();

    // 为说明书内容中的图片补充 wp-image-{id} class，使 WordPress 自动生成 srcset/sizes
    add_filter('the_content', 'lisun_manual_make_images_responsive', 5);

    // 插件更新或首次运行时自动刷新 rewrite 规则
    $stored_version = get_option('lisun_manual_version', '0');
    if (version_compare($stored_version, LISUN_MANUAL_VERSION, '<')) {
        update_option('lisun_manual_version', LISUN_MANUAL_VERSION);
        // 延迟到 shutdown 再 flush，避免 init 阶段冲突
        add_action('shutdown', 'flush_rewrite_rules');
    }
}

add_action('init', 'lisun_manual_init', 10);

// ====== 批量修正 manual slug（去末尾 6 位随机字母）======

/**
 * AJAX 预览 — 列出将要修改的 slug
 */
function lisun_manual_preview_slugs() {
    if (!current_user_can('manage_options')) {
        wp_die('Admin only.');
    }

    $all = get_posts([
        'post_type'      => 'manual',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $list = [];
    foreach ($all as $p) {
        $old = $p->post_name;
        if (!preg_match('/^(.+)-([a-z]{6})$/', $old, $m)) continue;
        $base = $m[1];
        if (empty($base) || !preg_match('/^[a-z]/', $base)) continue;

        $new_slug = wp_unique_post_slug($base, $p->ID, $p->post_status, 'manual', $p->post_parent);
        $list[] = [
            'id'        => $p->ID,
            'title'     => get_the_title($p),
            'old_slug'  => $old,
            'new_slug'  => $new_slug,
            'conflict'  => $new_slug !== $base,
        ];
    }

    echo '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><title>Fix Manual Slugs — Preview</title>';
    echo '<style>body{font-family:monospace;padding:20px;max-width:1000px;margin:0 auto}';
    echo 'table{border-collapse:collapse;width:100%}';
    echo 'th,td{border:1px solid #ccc;padding:5px 10px;font-size:13px;text-align:left}';
    echo 'th{background:#f0f0f0}.conflict{color:#e67e22}';
    echo '.btn{display:inline-block;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:bold;margin:10px 10px 0 0}';
    echo '.btn-go{background:#1677ff;color:#fff}.btn-back{background:#f0f0f0;color:#333}</style>';
    echo '</head><body>';

    echo '<h2>🔍 预览 — 将要修改的 manual slug（共 ' . count($list) . ' 篇）</h2>';
    echo '<p>旧 slug 末尾 6 位随机字母将被去除。冲突的会加 -2、-3。<br>';
    echo 'WordPress 的 wp_update_post 会自动记录旧 slug，实现 301 跳转。</p>';

    if (empty($list)) {
        echo '<p style="color:green;">✅ 没有需要修改的 slug，全部已是纯型号。</p>';
    } else {
        echo '<table><tr><th>ID</th><th>标题</th><th>旧 slug</th><th>新 slug</th></tr>';
        foreach ($list as $r) {
            $cls = $r['conflict'] ? ' class="conflict"' : '';
            echo '<tr' . $cls . '><td>' . $r['id'] . '</td><td>' . esc_html($r['title'])
               . '</td><td>' . esc_html($r['old_slug']) . '</td><td>' . esc_html($r['new_slug']) . '</td></tr>';
        }
        echo '</table>';
        echo '<a class="btn btn-go" href="' . admin_url('admin-ajax.php?action=lisun_fix_slugs') . '">确认执行，修改 ' . count($list) . ' 篇 →</a>';
    }
    echo '<a class="btn btn-back" href="' . admin_url() . '">返回后台</a>';
    echo '</body></html>';
    wp_die();
}
add_action('wp_ajax_lisun_preview_slugs', 'lisun_manual_preview_slugs');

/**
 * AJAX 执行 — 批量修改 slug
 */
function lisun_manual_fix_slugs() {
    if (!current_user_can('manage_options')) {
        wp_die('Admin only.');
    }

    $all = get_posts([
        'post_type'      => 'manual',
        'post_status'    => 'any',
        'posts_per_page' => -1,
    ]);

    $changed = 0;
    $skipped = 0;
    $errors  = 0;
    $rows    = [];

    foreach ($all as $p) {
        $old = $p->post_name;
        if (!preg_match('/^(.+)-([a-z]{6})$/', $old, $m)) {
            $skipped++;
            continue;
        }
        $base = $m[1];
        if (empty($base) || !preg_match('/^[a-z]/', $base)) {
            $skipped++;
            continue;
        }

        $new_slug = wp_unique_post_slug($base, $p->ID, $p->post_status, 'manual', $p->post_parent);
        $result = wp_update_post(['ID' => $p->ID, 'post_name' => $new_slug], true);

        if (is_wp_error($result)) {
            $errors++;
            $rows[] = '<tr><td>' . $p->ID . '</td><td>' . esc_html(get_the_title($p))
                    . '</td><td>' . esc_html($old) . '</td><td>' . esc_html($new_slug)
                    . '</td><td style="color:red">' . esc_html($result->get_error_message()) . '</td></tr>';
        } else {
            $changed++;
            $rows[] = '<tr><td>' . $p->ID . '</td><td>' . esc_html(get_the_title($p))
                    . '</td><td>' . esc_html($old) . '</td><td>' . esc_html($new_slug)
                    . '</td><td style="color:green">✅</td></tr>';
        }
    }

    wp_cache_flush();

    echo '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><title>Fix Manual Slugs — Done</title>';
    echo '<style>body{font-family:monospace;padding:20px;max-width:1000px;margin:0 auto}';
    echo 'table{border-collapse:collapse;width:100%}';
    echo 'th,td{border:1px solid #ccc;padding:5px 10px;font-size:13px;text-align:left}';
    echo 'th{background:#f0f0f0}.btn{display:inline-block;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:10px}';
    echo '</style></head><body>';

    echo '<h2>✅ Slug 修正完成</h2>';
    echo '<p>修改 ' . $changed . ' 篇，跳过 ' . $skipped . ' 篇，出错 ' . $errors . ' 篇</p>';
    if (!empty($rows)) {
        echo '<table><tr><th>ID</th><th>标题</th><th>旧 slug</th><th>新 slug</th><th>状态</th></tr>' . implode('', $rows) . '</table>';
    }
    echo '<p style="color:#888;">旧 URL 的 301 重定向由 WordPress 内置 wp_old_slug_redirect 自动处理。</p>';
    echo '<a class="btn" href="' . admin_url() . '" style="background:#1677ff;color:#fff;">返回后台</a>';
    echo '</body></html>';
    wp_die();
}
add_action('wp_ajax_lisun_fix_slugs', 'lisun_manual_fix_slugs');

/**
 * 加载插件自带模板
 *
 * WordPress 默认只从主题目录加载 single-{post_type}.php，
 * 这里通过 template_include 过滤器让插件自己的模板也能被使用。
 */
function lisun_manual_template_include($template) {
    // 说明书单页
    if (is_singular('manual')) {
        $plugin_template = LISUN_MANUAL_PATH . 'templates/single-manual.php';
        // 说明书详情页由插件统一维护，优先使用插件模板，避免主题中的旧模板覆盖打印结构。
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    // 说明书归档页
    if (is_post_type_archive('manual')) {
        $plugin_template = LISUN_MANUAL_PATH . 'templates/archive-manual.php';
        $theme_template = locate_template('archive-manual.php');
        if (!$theme_template && file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    return $template;
}

add_filter('template_include', 'lisun_manual_template_include', 99);

/**
 * 发送说明书页面的 HTTP 响应头
 *
 * - 设置合理的浏览器/ESA 边缘缓存策略
 * - 添加 preconnect 到本站资源域，加速图片加载
 * - 不会影响登录用户的页面（WordPress 已为登录用户发送 no-cache）
 */
function lisun_manual_send_headers() {
    if (!is_singular('manual') && !is_post_type_archive('manual')) {
        return;
    }

    // 对非登录用户：允许 CDN/ESA 缓存，设合理的过期时间
    if (!is_user_logged_in()) {
        // s-maxage 给 CDN 边缘节点，max-age 给浏览器
        // stale-while-revalidate 允许 CDN 在后台异步刷新过期缓存
        header('Cache-Control: public, max-age=3600, s-maxage=86400, stale-while-revalidate=86400');
    }

    // 添加 preconnect 到本站资源域（减少图片请求的 TCP+TLS 握手延迟）
    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
    if ($site_host) {
        header('Link: <https://' . $site_host . '>; rel=preconnect', false);
    }
}
add_action('send_headers', 'lisun_manual_send_headers');

/**
 * 高效地将图片 URL 转为 attachment ID。
 * 优先查 lisun_img_maps (O(1) 内存查找)，再用静态缓存避免重复 DB 查询。
 */
function lisun_manual_url_to_attachment_id($src_url) {
    static $cache = [];
    static $img_maps = null;

    if (isset($cache[$src_url])) {
        return $cache[$src_url];
    }

    // 懒加载 lisun_img_maps（一次请求只读一次 option）
    if ($img_maps === null) {
        $img_maps = get_option('lisun_img_maps', []);
    }

    // 从 URL 中提取文件名，查 lisun_img_maps
    $filename = basename(parse_url($src_url, PHP_URL_PATH));
    if ($filename && isset($img_maps[$filename]) && !empty($img_maps[$filename]['attachment_id'])) {
        $cache[$src_url] = (int) $img_maps[$filename]['attachment_id'];
        return $cache[$src_url];
    }

    // 回退：WordPress 原生查询（慢，仅兜底）
    $att_id = attachment_url_to_postid($src_url);
    $cache[$src_url] = $att_id ? (int) $att_id : 0;
    return $cache[$src_url];
}

/**
 * 为说明书内容中的图片补充 wp-image-{id} class，
 * 并添加 width/height/loading/decoding 属性。
 *
 * 在 wp_filter_content_tags（优先级 10）之前运行，
 * 使 WordPress 自动为图片生成 srcset / sizes。
 *
 * 重要：明确添加 loading="lazy" 和 decoding="async"，
 * 即使 WordPress 跳过了（如图片缺少尺寸信息时也兜底）。
 * 使用浏览器原生懒加载，不依赖 ESA 图片优化等 JS 方案，
 * 确保 CDN 边缘缓存的页面也能正常显示图片。
 */
function lisun_manual_make_images_responsive($content) {
    if (!is_singular('manual') && !is_post_type_archive('manual')) {
        return $content;
    }

    $img_count = 0;

    // SEO alt text 准备：产品名称 + 语言
    $post_title = get_the_title();
    $post_lang  = get_post_meta(get_the_ID(), '_lisun_manual_lang', true);
    $fig_label  = ($post_lang === 'en') ? 'Figure' : '图';

    return preg_replace_callback(
        // 使用所有格量词 *+ 避免在 base64 等长属性值上触发 PREG_BACKTRACK_LIMIT_ERROR
        '/<img\s+([^>]*+)>/i',
        function ($m) use (&$img_count, $post_title, $fig_label) {
            $attrs = $m[1];
            $modified = false;
            $att_id = 0;
            $img_count++;

            // 跳过 base64 内联图片（无法匹配到附件，避免无效查询和大字符串拼接）
            $is_base64 = (bool) preg_match('/\bsrc\s*=\s*"data:/i', $attrs);

            // 尝试从 src 中提取 URL，匹配附件
            if (!$is_base64 && !preg_match('/class="[^"]*\bwp-image-\d+\b[^"]*"/', $attrs)) {
                if (preg_match('/src="([^"]+)"/', $attrs, $src_m)) {
                    $src = $src_m[1];
                    if (strpos($src, '://') === false) {
                        $src = site_url($src);
                    }
                    $att_id = lisun_manual_url_to_attachment_id($src);
                    if ($att_id) {
                        if (preg_match('/class="([^"]*)"/', $attrs, $class_m)) {
                            $new_class = $class_m[1] . ' wp-image-' . $att_id;
                            $attrs = str_replace($class_m[0], 'class="' . $new_class . '"', $attrs);
                        } else {
                            $attrs .= ' class="wp-image-' . $att_id . '"';
                        }
                        $modified = true;
                    }
                }
            } else {
                // 已有 wp-image-{id}，提取 attachment id
                if (preg_match('/\bwp-image-(\d+)\b/', $attrs, $id_m)) {
                    $att_id = (int) $id_m[1];
                }
            }

            // SEO alt text：产品名称-图号（替换已有 alt，确保一致）
            $seo_alt = esc_attr($post_title . '-' . $fig_label . $img_count);
            if (preg_match('/\balt\s*=\s*"[^"]*"/i', $attrs)) {
                $attrs = preg_replace('/\balt\s*=\s*"[^"]*"/i', 'alt="' . $seo_alt . '"', $attrs);
            } else {
                $attrs .= ' alt="' . $seo_alt . '"';
            }
            $modified = true;

            // 补充 loading="lazy" —— 浏览器原生懒加载，无需 JS
            // 即使 wp_filter_content_tags 已添加，这里也确保不会遗漏
            if (!preg_match('/\bloading\s*=/i', $attrs)) {
                $attrs .= ' loading="lazy"';
                $modified = true;
            }

            // 补充 decoding="async" —— 并行解码，不阻塞主线程渲染
            if (!preg_match('/\bdecoding\s*=/i', $attrs)) {
                $attrs .= ' decoding="async"';
                $modified = true;
            }

            // fetchpriority：首图高优先级（LCP 优化），其余低优先级
            if (!preg_match('/\bfetchpriority\s*=/i', $attrs)) {
                if ($img_count === 1) {
                    $attrs .= ' fetchpriority="high"';
                } else {
                    $attrs .= ' fetchpriority="low"';
                }
                $modified = true;
            }

            // sizes：告诉浏览器图片在 1200px 宽内容区内的实际显示尺寸
            // 浏览器据此从 srcset 中选择最合适的变体，避免下载过大图片
            if (!preg_match('/\bsizes\s*=/i', $attrs)) {
                $attrs .= ' sizes="(max-width: 1200px) 100vw, 1200px"';
                $modified = true;
            }

            // 不设置固定 width/height，让 CSS height:auto 保证比例不拉伸
            // 避免从附件元数据读取的尺寸与实际图片不匹配导致的变形

            if ($modified) {
                return '<img ' . $attrs . '>';
            }
            return $m[0];
        },
        $content
    );
}

	/**
	 * 禁用 WordPress 自动输出 WebP 缩略图。
	 *
	 * WordPress 6.1+ 对 JPEG 原图默认输出 WebP 子尺寸，
	 * 但本站 WebP 转换不完整，大量 srcset 出现 404。
	 * 此 filter 强制保留原始格式（PNG/JPEG）。
	 */
	add_filter('image_editor_output_format', '__return_empty_array');

/**
 * 后台加载资源
 */
function lisun_manual_admin_enqueue($hook) {
    // 只在插件页面加载
    if (strpos($hook, 'lisun-manual') === false && strpos($hook, 'lisun_manual') === false) {
        return;
    }

    wp_enqueue_style(
        'lisun-manual-admin',
        LISUN_MANUAL_URL . 'assets/css/admin.css',
        [],
        LISUN_MANUAL_VERSION
    );

    wp_enqueue_script(
        'lisun-manual-admin-import',
        LISUN_MANUAL_URL . 'assets/js/admin-import.js',
        ['jquery'],
        LISUN_MANUAL_VERSION,
        true
    );

    wp_enqueue_script(
        'lisun-manual-admin-glossary',
        LISUN_MANUAL_URL . 'assets/js/admin-glossary.js',
        ['jquery'],
        LISUN_MANUAL_VERSION,
        true
    );

    wp_localize_script('lisun-manual-admin-import', 'lisun_manual', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('lisun_manual_nonce'),
        'i18n'     => [
            'uploading'     => __('正在上传...', 'lisun-manual'),
            'upload_success' => __('上传成功', 'lisun-manual'),
            'upload_failed'  => __('上传失败', 'lisun-manual'),
            'processing'     => __('正在处理...', 'lisun-manual'),
            'import_success' => __('导入成功', 'lisun-manual'),
            'import_failed'  => __('导入失败', 'lisun-manual'),
        ],
        'chunk'    => [
            'chunk_size'      => 2 * 1024 * 1024,   // 2MB per chunk
            'max_retries'     => 3,                   // retry each chunk up to 3 times
            'max_file_size'   => 500 * 1024 * 1024,   // warn if file > 500MB
        ],
    ]);

    wp_localize_script('lisun-manual-admin-glossary', 'lisun_manual_glossary', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('lisun_manual_nonce'),
    ]);

}

add_action('admin_enqueue_scripts', 'lisun_manual_admin_enqueue');

/**
 * 前台加载资源
 */
function lisun_manual_front_enqueue() {
    if (!is_singular('manual') && !is_post_type_archive('manual')) {
        return;
    }

    wp_enqueue_style(
        'lisun-manual-front',
        LISUN_MANUAL_URL . 'assets/css/front.css',
        [],
        LISUN_MANUAL_VERSION
    );

    // 图片加载安全网：修复 CDN 图片优化（如 ESA/Cloudflare Mirage）可能遗留的 data-src 问题。
    // 部分 CDN 会把 src→data-src 后依赖自身 JS 还原，但边缘缓存或时序问题会导致还原失败。
    // 修复策略：
    //   1. 多次定时扫描（不再用 fixedMirage 一次性标志 —— CDN 可能在任何时机修改 DOM）
    //   2. MutationObserver 实时监听属性变更
    //   3. 滚动时兜底扫描
    wp_add_inline_script('jquery', '
(function() {
    if (!document.querySelector(".lisun-manual-content, .lisun-manual-single")) return;

    // --- Part 1: 修复 CDN 图片优化遗留的 data-src ---
    function fixMirageImages() {
        var imgs = document.querySelectorAll("img[data-src]");
        for (var i = 0; i < imgs.length; i++) {
            var ds = imgs[i].getAttribute("data-src");
            if (ds) {
                imgs[i].setAttribute("src", ds);
                imgs[i].removeAttribute("data-src");
            }
        }
    }

    // 多次定时扫描：CDN 可能在不同时机修改 DOM（100ms / 1s / 2.5s / 5s / 10s）
    [100, 1000, 2500, 5000, 10000].forEach(function(d) {
        setTimeout(fixMirageImages, d);
    });

    // MutationObserver：CDN 修改 <img> 属性或插入新图片时立即响应
    if ("MutationObserver" in window) {
        var mo = new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (m.type === "attributes" && m.target.tagName === "IMG" &&
                    (m.attributeName === "src" || m.attributeName === "data-src")) {
                    fixMirageImages();
                    break;
                }
                if (m.type === "childList") {
                    for (var j = 0; j < m.addedNodes.length; j++) {
                        var n = m.addedNodes[j];
                        if (n.tagName === "IMG" || (n.querySelectorAll && n.querySelectorAll("img").length)) {
                            fixMirageImages();
                            break;
                        }
                    }
                }
            }
        });
        mo.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ["src", "data-src"],
            childList: true,
            subtree: true
        });
    }

    // load 事件后再扫一次
    window.addEventListener("load", function() {
        setTimeout(fixMirageImages, 300);
    });

    // 首次滚动时兜底扫描（CDN 懒加载可能给视口外图片加 data-src）
    var scrolled = false;
    window.addEventListener("scroll", function() {
        if (scrolled) return;
        scrolled = true;
        setTimeout(fixMirageImages, 800);
    }, { once: true, passive: true });

    // --- Part 2: 图片加载淡入效果（增强，非必须） ---
    // 注意：默认不隐藏图片！
    // lisun-loading → opacity:0（仅在 JS 明确标记后），
    // lisun-loaded  → opacity:1（带过渡动画）。
    // 如果 JS 失败或出错，图片仍然可见（不会被 CSS 隐藏）。
    function markLoading(img) {
        if (!img.classList.contains("lisun-loaded") && !img.classList.contains("lisun-loading")) {
            img.classList.add("lisun-loading");
        }
    }
    function markLoaded(img) {
        img.classList.remove("lisun-loading");
        img.classList.add("lisun-loaded");
    }

    function observeImage(img) {
        if (img.complete && img.naturalWidth > 0) {
            markLoaded(img);
        } else {
            markLoading(img);
            img.addEventListener("load", function() { markLoaded(img); }, { once: true });
            img.addEventListener("error", function() {
                // 加载失败也移除 loading 态，避免图片永久透明
                img.classList.remove("lisun-loading");
            }, { once: true });
        }
    }

    document.querySelectorAll(".lisun-manual-content img, .lisun-manual-single img").forEach(observeImage);
})();
', 'after');
}

add_action('wp_enqueue_scripts', 'lisun_manual_front_enqueue');

/**
 * 单页补丁：仅对 agm-500 页面生效的内联 CSS + JS

/**
 * 说明书归档页 — 分类筛选 + 搜索过滤
 *
 * 根据 URL 参数 manual_cat / manual_subcat 找到对应分类，
 * 通过两条链路收集产品型号名 → 筛选 manual 的 post_tag 或 product-no；
 * manual_search 过滤标题。
 *
 * 链路 A: 分类 → subcat name → product 分类法 term → products 帖子 → post_tag
 * 链路 B: 分类 → lisun_product_items → lisun_product_meta.model
 */
function lisun_manual_filter_archive_query($query) {
    if (is_admin() || !$query->is_main_query()) return;
    if (!$query->is_post_type_archive('manual')) return;

    $cat_id    = isset($_GET['manual_cat']) ? intval($_GET['manual_cat']) : 0;
    $subcat_id = isset($_GET['manual_subcat']) ? intval($_GET['manual_subcat']) : 0;
    $search    = isset($_GET['manual_search']) ? sanitize_text_field(wp_unslash($_GET['manual_search'])) : '';

    if ($cat_id > 0 || $subcat_id > 0) {
        global $wpdb;
        $table_items   = $wpdb->prefix . 'lisun_product_items';
        $table_subcats = $wpdb->prefix . 'lisun_product_subcats';
        $table_meta    = $wpdb->prefix . 'lisun_product_meta';

        $check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table_items)));
        if ($check !== $table_items) return;

        // 确定筛选条件
        if ($subcat_id > 0) {
            $cat_where    = $wpdb->prepare("s.id = %d", $subcat_id);
            $items_where  = $wpdb->prepare("lpi.subcat_id = %d", $subcat_id);
        } else {
            $cat_where    = $wpdb->prepare("c.id = %d", $cat_id);
            $items_where  = $wpdb->prepare("lps.cat_id = %d", $cat_id);
        }

        $all_terms = [];

        // 链路 A: subcat name → product 分类法 term → products 帖子 → post_tag
        $subcat_names = $wpdb->get_col("
            SELECT s.name FROM {$table_subcats} s
            JOIN {$wpdb->prefix}lisun_product_cats c ON c.id = s.cat_id
            WHERE {$cat_where}
        ");

        if (!empty($subcat_names)) {
            $placeholders = implode(',', array_fill(0, count($subcat_names), '%s'));
            $tags_a = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT t.name
                FROM wp_terms pcat_t
                JOIN wp_term_taxonomy pcat_tt ON pcat_tt.term_id = pcat_t.term_id AND pcat_tt.taxonomy = 'product'
                JOIN wp_term_relationships pcat_tr ON pcat_tr.term_taxonomy_id = pcat_tt.term_taxonomy_id
                JOIN wp_posts prod ON prod.ID = pcat_tr.object_id AND prod.post_type = 'products'
                JOIN wp_term_relationships ptr ON ptr.object_id = prod.ID
                JOIN wp_term_taxonomy ptt ON ptt.term_taxonomy_id = ptr.term_taxonomy_id AND ptt.taxonomy = 'post_tag'
                JOIN wp_terms t ON t.term_id = ptt.term_id
                WHERE pcat_t.name COLLATE utf8mb4_unicode_520_ci IN ({$placeholders})
            ", ...$subcat_names));
            $all_terms = array_merge($all_terms, $tags_a ?: []);
        }

        // 链路 B: product models from lisun_product_meta
        $models = $wpdb->get_col("
            SELECT DISTINCT lpm.model
            FROM {$table_meta} lpm
            JOIN {$table_items} lpi ON lpi.product_id = lpm.product_id
            JOIN {$table_subcats} lps ON lps.id = lpi.subcat_id
            WHERE {$items_where} AND lpm.model != ''
        ");
        $all_terms = array_merge($all_terms, $models ?: []);

        $all_terms = array_unique(array_filter($all_terms));

        if (!empty($all_terms)) {
            // 同时用 tax_query (post_tag) 和 meta_query (product-no) 来覆盖两条链路
            $tax_query = $query->get('tax_query', []);
            $tax_query[] = [
                'taxonomy' => 'post_tag',
                'field'    => 'name',
                'terms'    => $all_terms,
                'operator' => 'IN',
            ];
            $query->set('tax_query', $tax_query);
        } else {
            $query->set('post__in', [0]);
        }
    }

    if ($search !== '') {
        $query->set('s', $search);
    }
}

add_action('pre_get_posts', 'lisun_manual_filter_archive_query');

/**
 * 根据产品型号（product-no）从 products CPT 获取产品详情中的视频
 *
 * 视频数据来自 products 帖子的 ACF repeater 字段 product-video，
 * 每个视频包含 product-video-item（Bilibili aid）和 product-video-text（名称）。
 *
 * @param string $model 产品型号，如 "LSG-1200A"
 * @param string|null $lang 语言代码 'zh' 或 'en'。为 null 时回退到全局选项 lisun_page_lang。
 * @return array 视频列表，格式 [['name' => '...', 'url' => '...'], ...]
 */
function lisun_get_product_videos_by_model($model, $lang = null) {
    global $wpdb;
    $model = trim($model);
    if (empty($model)) return [];

    // 1. 通过 lisun_product_meta 查找匹配的 product_id
    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT product_id FROM {$wpdb->prefix}lisun_product_meta WHERE model = %s LIMIT 1",
        $model
    ));

    if (empty($product_id)) return [];

    // 2. 确认对应的 products 帖子存在
    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'products') return [];

    // 3. 读取 ACF repeater 字段 product-video
    if (!function_exists('have_rows')) return [];

    $videos = [];
    if (have_rows('product-video', $product_id)) {
        while (have_rows('product-video', $product_id)) {
            the_row();
            $aid  = get_sub_field('product-video-item');
            $name = get_sub_field('product-video-text');
            $type = get_sub_field('product-video-type'); // 2026-08 新增：视频类型字段
            if (empty($aid)) continue;
            // 只引用"产品操作"类视频，跳过"产品展示"
            // 优先用 product-video-type DB 字段，兼容旧数据回退到名称关键词
            if (!empty($type)) {
                if ($type === '产品展示') continue;
            } else {
                if (strpos($name, '产品展示') !== false) continue;
            }
            // 根据插件后台语言设置生成对应平台的视频 URL
            // 中文站用 Bilibili，英文站用 YouTube
            $site_lang = ($lang !== null) ? $lang : get_option('lisun_page_lang', 'zh');
            if ($site_lang === 'en') {
                // YouTube: product-video-item 存的是视频 ID（如 zfa9EEl95aI）
                $video_url = 'https://www.youtube.com/watch?v=' . $aid;
            } else {
                // Bilibili: product-video-item 存的是 aid
                $video_url = '//player.bilibili.com/player.html?aid=' . $aid . '&page=1&autoplay=0';
            }
            $videos[] = [
                'name' => $name ?: 'Video',
                'url'  => $video_url,
            ];
        }
    }

    return $videos;
}

/**
 * 修复主题 nav.php 中未注册的 AJAX action 导致的 400 错误。
 *
 * 主题在 nav.php 中调用了 admin-ajax.php?action=setcookie 和
 * admin-ajax.php?action=huictypephone，但未注册对应的 WordPress handler。
 * 此处补注册，返回空成功响应，消除浏览器控制台报错。
 */
function lisun_manual_ajax_setcookie() {
    if (!empty($_GET['bd_vid'])) {
        setcookie('bd_vid', $_GET['bd_vid'], time() + 30 * 86400, '/');
    }
    wp_send_json_success();
}
add_action('wp_ajax_setcookie', 'lisun_manual_ajax_setcookie');
add_action('wp_ajax_nopriv_setcookie', 'lisun_manual_ajax_setcookie');

function lisun_manual_ajax_huictypephone() {
    wp_send_json_success();
}
add_action('wp_ajax_huictypephone', 'lisun_manual_ajax_huictypephone');
add_action('wp_ajax_nopriv_huictypephone', 'lisun_manual_ajax_huictypephone');
