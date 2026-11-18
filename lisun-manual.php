<?php
/**
 * Plugin Name: LISUN Manual System
 * Plugin URI:  https://lisungroup.cn
 * Description: ˫վ˵�������ϵͳ �� Word �������롢DeepSeek �Զ����롢˫վͬ�������뱣��
 * Version:     1.1.35
 * Author:      LISUN Group
 * License:     Proprietary
 * Text Domain: lisun-manual
 * Domain Path: /languages
 */

// ��ֱֹ�ӷ���
if (!defined('ABSPATH')) {
    exit;
}

// �������
define('LISUN_MANUAL_VERSION', '1.1.35');
define('LISUN_MANUAL_PATH', plugin_dir_path(__FILE__));
define('LISUN_MANUAL_URL', plugin_dir_url(__FILE__));
define('LISUN_MANUAL_FILE', __FILE__);

// Composer autoload��������ڣ�
$composer_autoload = LISUN_MANUAL_PATH . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// ���ع��� trait
require_once LISUN_MANUAL_PATH . 'includes/trait-nonce.php';
// ���غ�����
require_once LISUN_MANUAL_PATH . 'includes/class-cpt.php';
require_once LISUN_MANUAL_PATH . 'includes/class-pdf-download.php';
require_once LISUN_MANUAL_PATH . 'includes/class-admin-menu.php';
require_once LISUN_MANUAL_PATH . 'includes/class-import.php';
require_once LISUN_MANUAL_PATH . 'includes/class-import-parser.php';
// REST ·�ɱ����ڲ������ʱ��ǰ���أ����� REST ����ʱ���½ӿ�δע�ᡣ
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
// [�޸�ʱ�䣺2026-11-18 +08:00] LLM ��������·ʵ�飻Ĭ�Ϲرգ����ı���������·����
require_once LISUN_MANUAL_PATH . 'includes/translation-v2/class-llm-lab-adapter.php';
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
 * ��������
 */
function lisun_manual_activate() {
    // ȷ�� CPT ע��Ȼ��ˢ����д����
    Lisun_Manual_CPT::register();
    flush_rewrite_rules();

    // ����������־��
    Lisun_Manual_Translate_Log::create_table();

    // ����������б�
    Lisun_Manual_Translate::create_queue_table();

    // ���� V2 �ĵ�/��Ԫ/�����ϵ���������������޸� V1 ���ݣ�
    Lisun_Manual_V2_Schema::create_tables();
    update_option('lisun_translation_v2_schema_version', Lisun_Manual_V2_Schema::VERSION, false);

    // Ĭ��ѡ��
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
 * ���ж�ع���
 */
function lisun_manual_uninstall() {
    // ��ɾ�����ݣ���������
}

register_uninstall_hook(__FILE__, 'lisun_manual_uninstall');

/**
 * һ����Ǩ�ƣ�����ʷ���뱸�ݵȴ���� option �� autoload ��Ϊ�� autoload��
 *
 * ���������� save_translation_backup() �� update_option ���� autoload ������
 * ���ݣ�ÿƪ�ɴ��� MB������������ظ��洢 zh_segments/en_parts��Ĭ��
 * autoload='yes'��wp_load_alloptions() ����ÿ�����������ȫ�����ؽ��ڴ棬
 * ��������ƪ�� PHP-FPM OOM������Ϊ Cloudflare 525��
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
 * ��ʼ�����
 */
function lisun_manual_init() {
    // ����ʷ�� option �� autoload Ǩ��Ϊ�� autoload��һ���ԣ�����ÿ������ OOM��
    lisun_manual_fix_autoload_once();
    Lisun_Manual_V2_Schema::maybe_upgrade();

    // ע�� CPT
    Lisun_Manual_CPT::init();
    Lisun_Manual_CPT::register();
    Lisun_Manual_CPT::register_taxonomies();
    Lisun_Manual_CPT::register_meta_fields();

    // ��ʼ����ģ��
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
    Lisun_Manual_Pdf_Download::init();   // 2026-11-09 ����ҳ PDF ����

    // Ϊ˵���������е�ͼƬ���� wp-image-{id} class��ʹ WordPress �Զ����� srcset/sizes
    add_filter('the_content', 'lisun_manual_make_images_responsive', 5);

    // ������»��״�����ʱ�Զ�ˢ�� rewrite ����
    $stored_version = get_option('lisun_manual_version', '0');
    if (version_compare($stored_version, LISUN_MANUAL_VERSION, '<')) {
        update_option('lisun_manual_version', LISUN_MANUAL_VERSION);
        // �ӳٵ� shutdown �� flush������ init �׶γ�ͻ
        add_action('shutdown', 'flush_rewrite_rules');
    }
}

add_action('init', 'lisun_manual_init', 10);

// ====== �������� manual slug��ȥĩβ 6 λ�����ĸ��======

/**
 * AJAX Ԥ�� �� �г���Ҫ�޸ĵ� slug
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

    echo '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><title>Fix Manual Slugs �� Preview</title>';
    echo '<style>body{font-family:monospace;padding:20px;max-width:1000px;margin:0 auto}';
    echo 'table{border-collapse:collapse;width:100%}';
    echo 'th,td{border:1px solid #ccc;padding:5px 10px;font-size:13px;text-align:left}';
    echo 'th{background:#f0f0f0}.conflict{color:#e67e22}';
    echo '.btn{display:inline-block;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:bold;margin:10px 10px 0 0}';
    echo '.btn-go{background:#1677ff;color:#fff}.btn-back{background:#f0f0f0;color:#333}</style>';
    echo '</head><body>';

    echo '<h2>?? Ԥ�� �� ��Ҫ�޸ĵ� manual slug���� ' . count($list) . ' ƪ��</h2>';
    echo '<p>�� slug ĩβ 6 λ�����ĸ����ȥ������ͻ�Ļ�� -2��-3��<br>';
    echo 'WordPress �� wp_update_post ���Զ���¼�� slug��ʵ�� 301 ��ת��</p>';

    if (empty($list)) {
        echo '<p style="color:green;">? û����Ҫ�޸ĵ� slug��ȫ�����Ǵ��ͺš�</p>';
    } else {
        echo '<table><tr><th>ID</th><th>����</th><th>�� slug</th><th>�� slug</th></tr>';
        foreach ($list as $r) {
            $cls = $r['conflict'] ? ' class="conflict"' : '';
            echo '<tr' . $cls . '><td>' . $r['id'] . '</td><td>' . esc_html($r['title'])
               . '</td><td>' . esc_html($r['old_slug']) . '</td><td>' . esc_html($r['new_slug']) . '</td></tr>';
        }
        echo '</table>';
        echo '<a class="btn btn-go" href="' . admin_url('admin-ajax.php?action=lisun_fix_slugs') . '">ȷ��ִ�У��޸� ' . count($list) . ' ƪ ��</a>';
    }
    echo '<a class="btn btn-back" href="' . admin_url() . '">���غ�̨</a>';
    echo '</body></html>';
    wp_die();
}
add_action('wp_ajax_lisun_preview_slugs', 'lisun_manual_preview_slugs');

/**
 * AJAX ִ�� �� �����޸� slug
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
                    . '</td><td style="color:green">?</td></tr>';
        }
    }

    wp_cache_flush();

    echo '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><title>Fix Manual Slugs �� Done</title>';
    echo '<style>body{font-family:monospace;padding:20px;max-width:1000px;margin:0 auto}';
    echo 'table{border-collapse:collapse;width:100%}';
    echo 'th,td{border:1px solid #ccc;padding:5px 10px;font-size:13px;text-align:left}';
    echo 'th{background:#f0f0f0}.btn{display:inline-block;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:10px}';
    echo '</style></head><body>';

    echo '<h2>? Slug �������</h2>';
    echo '<p>�޸� ' . $changed . ' ƪ������ ' . $skipped . ' ƪ������ ' . $errors . ' ƪ</p>';
    if (!empty($rows)) {
        echo '<table><tr><th>ID</th><th>����</th><th>�� slug</th><th>�� slug</th><th>״̬</th></tr>' . implode('', $rows) . '</table>';
    }
    echo '<p style="color:#888;">�� URL �� 301 �ض����� WordPress ���� wp_old_slug_redirect �Զ�������</p>';
    echo '<a class="btn" href="' . admin_url() . '" style="background:#1677ff;color:#fff;">���غ�̨</a>';
    echo '</body></html>';
    wp_die();
}
add_action('wp_ajax_lisun_fix_slugs', 'lisun_manual_fix_slugs');

/**
 * ���ز���Դ�ģ��
 *
 * WordPress Ĭ��ֻ������Ŀ¼���� single-{post_type}.php��
 * ����ͨ�� template_include �������ò���Լ���ģ��Ҳ�ܱ�ʹ�á�
 */
function lisun_manual_template_include($template) {
    // ˵���鵥ҳ
    if (is_singular('manual')) {
        $plugin_template = LISUN_MANUAL_PATH . 'templates/single-manual.php';
        // ˵��������ҳ�ɲ��ͳһά��������ʹ�ò��ģ�壬���������еľ�ģ�帲�Ǵ�ӡ�ṹ��
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    // ˵����鵵ҳ
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
 * ����˵����ҳ��� HTTP ��Ӧͷ
 *
 * - ���ú����������/ESA ��Ե�������
 * - ���� preconnect ����վ��Դ�򣬼���ͼƬ����
 * - ����Ӱ���¼�û���ҳ�棨WordPress ��Ϊ��¼�û����� no-cache��
 */
function lisun_manual_send_headers() {
    if (!is_singular('manual') && !is_post_type_archive('manual')) {
        return;
    }

    // �Էǵ�¼�û������� CDN/ESA ���棬������Ĺ���ʱ��
    if (!is_user_logged_in()) {
        // s-maxage �� CDN ��Ե�ڵ㣬max-age �������
        // stale-while-revalidate ���� CDN �ں�̨�첽ˢ�¹��ڻ���
        header('Cache-Control: public, max-age=3600, s-maxage=86400, stale-while-revalidate=86400');
    }

    // ���� preconnect ����վ��Դ�򣨼���ͼƬ����� TCP+TLS �����ӳ٣�
    $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
    if ($site_host) {
        header('Link: <https://' . $site_host . '>; rel=preconnect', false);
    }
}
add_action('send_headers', 'lisun_manual_send_headers');

/**
 * ��Ч�ؽ�ͼƬ URL תΪ attachment ID��
 * ���Ȳ� lisun_img_maps (O(1) �ڴ����)�����þ�̬��������ظ� DB ��ѯ��
 */
function lisun_manual_url_to_attachment_id($src_url) {
    static $cache = [];
    static $img_maps = null;

    if (isset($cache[$src_url])) {
        return $cache[$src_url];
    }

    // ������ lisun_img_maps��һ������ֻ��һ�� option��
    if ($img_maps === null) {
        $img_maps = get_option('lisun_img_maps', []);
    }

    // �� URL ����ȡ�ļ������� lisun_img_maps
    $filename = basename(parse_url($src_url, PHP_URL_PATH));
    if ($filename && isset($img_maps[$filename]) && !empty($img_maps[$filename]['attachment_id'])) {
        $cache[$src_url] = (int) $img_maps[$filename]['attachment_id'];
        return $cache[$src_url];
    }

    // ���ˣ�WordPress ԭ����ѯ�����������ף�
    $att_id = attachment_url_to_postid($src_url);
    $cache[$src_url] = $att_id ? (int) $att_id : 0;
    return $cache[$src_url];
}

/**
 * Ϊ˵���������е�ͼƬ���� wp-image-{id} class��
 * ������ width/height/loading/decoding ���ԡ�
 *
 * �� wp_filter_content_tags�����ȼ� 10��֮ǰ���У�
 * ʹ WordPress �Զ�ΪͼƬ���� srcset / sizes��
 *
 * ��Ҫ����ȷ���� loading="lazy" �� decoding="async"��
 * ��ʹ WordPress �����ˣ���ͼƬȱ�ٳߴ���ϢʱҲ���ף���
 * ʹ�������ԭ�������أ������� ESA ͼƬ�Ż��� JS ������
 * ȷ�� CDN ��Ե�����ҳ��Ҳ��������ʾͼƬ��
 */
function lisun_manual_make_images_responsive($content) {
    if (!is_singular('manual') && !is_post_type_archive('manual')) {
        return $content;
    }

    $img_count = 0;

    // SEO alt text ׼������Ʒ���� + ����
    $post_title = get_the_title();
    $post_lang  = get_post_meta(get_the_ID(), '_lisun_manual_lang', true);
    $fig_label  = ($post_lang === 'en') ? 'Figure' : 'ͼ';

    return preg_replace_callback(
        // ʹ�����и����� *+ ������ base64 �ȳ�����ֵ�ϴ��� PREG_BACKTRACK_LIMIT_ERROR
        '/<img\s+([^>]*+)>/i',
        function ($m) use (&$img_count, $post_title, $fig_label) {
            $attrs = $m[1];
            $modified = false;
            $att_id = 0;
            $img_count++;

            // ���� base64 ����ͼƬ���޷�ƥ�䵽������������Ч��ѯ�ʹ��ַ���ƴ�ӣ�
            $is_base64 = (bool) preg_match('/\bsrc\s*=\s*"data:/i', $attrs);

            // ���Դ� src ����ȡ URL��ƥ�丽��
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
                // ���� wp-image-{id}����ȡ attachment id
                if (preg_match('/\bwp-image-(\d+)\b/', $attrs, $id_m)) {
                    $att_id = (int) $id_m[1];
                }
            }

            // SEO alt text����Ʒ����-ͼ�ţ��滻���� alt��ȷ��һ�£�
            $seo_alt = esc_attr($post_title . '-' . $fig_label . $img_count);
            if (preg_match('/\balt\s*=\s*"[^"]*"/i', $attrs)) {
                $attrs = preg_replace('/\balt\s*=\s*"[^"]*"/i', 'alt="' . $seo_alt . '"', $attrs);
            } else {
                $attrs .= ' alt="' . $seo_alt . '"';
            }
            $modified = true;

            // ���� loading="lazy" ���� �����ԭ�������أ����� JS
            // ��ʹ wp_filter_content_tags �����ӣ�����Ҳȷ��������©
            if (!preg_match('/\bloading\s*=/i', $attrs)) {
                $attrs .= ' loading="lazy"';
                $modified = true;
            }

            // ���� decoding="async" ���� ���н��룬���������߳���Ⱦ
            if (!preg_match('/\bdecoding\s*=/i', $attrs)) {
                $attrs .= ' decoding="async"';
                $modified = true;
            }

            // fetchpriority����ͼ�����ȼ���LCP �Ż�������������ȼ�
            if (!preg_match('/\bfetchpriority\s*=/i', $attrs)) {
                if ($img_count === 1) {
                    $attrs .= ' fetchpriority="high"';
                } else {
                    $attrs .= ' fetchpriority="low"';
                }
                $modified = true;
            }

            // sizes�����������ͼƬ�� 1200px ���������ڵ�ʵ����ʾ�ߴ�
            // ������ݴ˴� srcset ��ѡ������ʵı��壬�������ع���ͼƬ
            if (!preg_match('/\bsizes\s*=/i', $attrs)) {
                $attrs .= ' sizes="(max-width: 1200px) 100vw, 1200px"';
                $modified = true;
            }

            // �����ù̶� width/height���� CSS height:auto ��֤����������
            // ����Ӹ���Ԫ���ݶ�ȡ�ĳߴ���ʵ��ͼƬ��ƥ�䵼�µı���

            if ($modified) {
                return '<img ' . $attrs . '>';
            }
            return $m[0];
        },
        $content
    );
}

	/**
	 * ���� WordPress �Զ���� WebP ����ͼ��
	 *
	 * WordPress 6.1+ �� JPEG ԭͼĬ����� WebP �ӳߴ磬
	 * ����վ WebP ת�������������� srcset ���� 404��
	 * �� filter ǿ�Ʊ���ԭʼ��ʽ��PNG/JPEG����
	 */
	add_filter('image_editor_output_format', '__return_empty_array');

/**
 * ��̨������Դ
 */
function lisun_manual_admin_enqueue($hook) {
    // ֻ�ڲ��ҳ�����
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
            'uploading'     => __('�����ϴ�...', 'lisun-manual'),
            'upload_success' => __('�ϴ��ɹ�', 'lisun-manual'),
            'upload_failed'  => __('�ϴ�ʧ��', 'lisun-manual'),
            'processing'     => __('���ڴ���...', 'lisun-manual'),
            'import_success' => __('����ɹ�', 'lisun-manual'),
            'import_failed'  => __('����ʧ��', 'lisun-manual'),
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
 * ǰ̨������Դ
 */
function lisun_manual_front_enqueue() {
    if (!is_singular('manual') && !is_post_type_archive('manual')) {
        return;
    }

    /* 2026-11-09 14:30 ��ʽ���汾�Ÿ����ļ��޸�ʱ�䣺
       ԭ�ȹ̶��� LISUN_MANUAL_VERSION������汾������ front.css ��汾�Ų��䣬
       ������� CDN ��������оɻ��棨����Ϊ��������ʽ����ӡ�����ϰ�ʽ������
       ���� filemtime ��ÿ�θĶ���ʽ�����Զ�ʧЧ�� */
    $lisun_front_css = LISUN_MANUAL_PATH . 'assets/css/front.css';
    wp_enqueue_style(
        'lisun-manual-front',
        LISUN_MANUAL_URL . 'assets/css/front.css',
        [],
        file_exists($lisun_front_css) ? filemtime($lisun_front_css) : LISUN_MANUAL_VERSION
    );

    /* 2026-11-09 ��¼��Ƶ��ά�룺�������� qrcode-generator��MIT���������� CDN��
       ��˵����ҳ���أ�ҳ�������ģ�������ű��� DOMContentLoaded ����ơ� */
    $lisun_qr_js = LISUN_MANUAL_PATH . 'assets/js/qrcode.js';
    wp_enqueue_script(
        'lisun-manual-qrcode',
        LISUN_MANUAL_URL . 'assets/js/qrcode.js',
        [],
        file_exists($lisun_qr_js) ? filemtime($lisun_qr_js) : LISUN_MANUAL_VERSION,
        true
    );

    // ͼƬ���ذ�ȫ�����޸� CDN ͼƬ�Ż����� ESA/Cloudflare Mirage������������ data-src ���⡣
    // ���� CDN ��� src��data-src ���������� JS ��ԭ������Ե�����ʱ������ᵼ�»�ԭʧ�ܡ�
    // �޸����ԣ�
    //   1. ��ζ�ʱɨ�裨������ fixedMirage һ���Ա�־ ���� CDN �������κ�ʱ���޸� DOM��
    //   2. MutationObserver ʵʱ�������Ա��
    //   3. ����ʱ����ɨ��
    wp_add_inline_script('jquery', '
(function() {
    if (!document.querySelector(".lisun-manual-content, .lisun-manual-single")) return;

    // --- Part 1: �޸� CDN ͼƬ�Ż������� data-src ---
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

    // ��ζ�ʱɨ�裺CDN �����ڲ�ͬʱ���޸� DOM��100ms / 1s / 2.5s / 5s / 10s��
    [100, 1000, 2500, 5000, 10000].forEach(function(d) {
        setTimeout(fixMirageImages, d);
    });

    // MutationObserver��CDN �޸� <img> ���Ի������ͼƬʱ������Ӧ
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

    // load �¼�����ɨһ��
    window.addEventListener("load", function() {
        setTimeout(fixMirageImages, 300);
    });

    // �״ι���ʱ����ɨ�裨CDN �����ؿ��ܸ��ӿ���ͼƬ�� data-src��
    var scrolled = false;
    window.addEventListener("scroll", function() {
        if (scrolled) return;
        scrolled = true;
        setTimeout(fixMirageImages, 800);
    }, { once: true, passive: true });

    // --- Part 2: ͼƬ���ص���Ч������ǿ���Ǳ��룩 ---
    // ע�⣺Ĭ�ϲ�����ͼƬ��
    // lisun-loading �� opacity:0������ JS ��ȷ��Ǻ󣩣�
    // lisun-loaded  �� opacity:1�������ɶ�������
    // ��� JS ʧ�ܻ������ͼƬ��Ȼ�ɼ������ᱻ CSS ���أ���
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
                // ����ʧ��Ҳ�Ƴ� loading ̬������ͼƬ����͸��
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
 * ��ҳ���������� agm-500 ҳ����Ч������ CSS + JS

/**
 * ˵����鵵ҳ �� ����ɸѡ + ��������
 *
 * ���� URL ���� manual_cat / manual_subcat �ҵ���Ӧ���࣬
 * ͨ��������·�ռ���Ʒ�ͺ��� �� ɸѡ manual �� post_tag �� product-no��
 * manual_search ���˱��⡣
 *
 * ��· A: ���� �� subcat name �� product ���෨ term �� products ���� �� post_tag
 * ��· B: ���� �� lisun_product_items �� lisun_product_meta.model
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

        // ȷ��ɸѡ����
        if ($subcat_id > 0) {
            $cat_where    = $wpdb->prepare("s.id = %d", $subcat_id);
            $items_where  = $wpdb->prepare("lpi.subcat_id = %d", $subcat_id);
        } else {
            $cat_where    = $wpdb->prepare("c.id = %d", $cat_id);
            $items_where  = $wpdb->prepare("lps.cat_id = %d", $cat_id);
        }

        $all_terms = [];

        // ��· A: subcat name �� product ���෨ term �� products ���� �� post_tag
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

        // ��· B: product models from lisun_product_meta
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
            // ͬʱ�� tax_query (post_tag) �� meta_query (product-no) ������������·
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
 * ���ݲ�Ʒ�ͺţ�product-no���� products CPT ��ȡ��Ʒ�����е���Ƶ
 *
 * ��Ƶ�������� products ���ӵ� ACF repeater �ֶ� product-video��
 * ÿ����Ƶ���� product-video-item��Bilibili aid���� product-video-text�����ƣ���
 *
 * @param string $model ��Ʒ�ͺţ��� "LSG-1200A"
 * @param string|null $lang ���Դ��� 'zh' �� 'en'��Ϊ null ʱ���˵�ȫ��ѡ�� lisun_page_lang��
 * @return array ��Ƶ�б�����ʽ [['name' => '...', 'url' => '...'], ...]
 */
function lisun_get_product_videos_by_model($model, $lang = null) {
    global $wpdb;
    $model = trim($model);
    if (empty($model)) return [];

    // 1. ͨ�� lisun_product_meta ����ƥ��� product_id
    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT product_id FROM {$wpdb->prefix}lisun_product_meta WHERE model = %s LIMIT 1",
        $model
    ));

    if (empty($product_id)) return [];

    // 2. ȷ�϶�Ӧ�� products ���Ӵ���
    $post = get_post($product_id);
    if (!$post || $post->post_type !== 'products') return [];

    // 3. ��ȡ ACF repeater �ֶ� product-video
    if (!function_exists('have_rows')) return [];

    $videos = [];
    if (have_rows('product-video', $product_id)) {
        while (have_rows('product-video', $product_id)) {
            the_row();
            $aid  = get_sub_field('product-video-item');
            $name = get_sub_field('product-video-text');
            $type = get_sub_field('product-video-type'); // 2026-08 ��������Ƶ�����ֶ�
            if (empty($aid)) continue;
            // ֻ����"��Ʒ����"����Ƶ������"��Ʒչʾ"
            // ������ product-video-type DB �ֶΣ����ݾ����ݻ��˵����ƹؼ���
            if (!empty($type)) {
                if ($type === '��Ʒչʾ') continue;
            } else {
                if (strpos($name, '��Ʒչʾ') !== false) continue;
            }
            // ���ݲ����̨�����������ɶ�Ӧƽ̨����Ƶ URL
            // ����վ�� Bilibili��Ӣ��վ�� YouTube
            $site_lang = ($lang !== null) ? $lang : get_option('lisun_page_lang', 'zh');
            if ($site_lang === 'en') {
                // YouTube: product-video-item �������Ƶ ID���� zfa9EEl95aI��
                $video_url = 'https://www.youtube.com/watch?v=' . $aid;
            } else {
                // Bilibili: product-video-item ����� aid
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
 * �޸����� nav.php ��δע��� AJAX action ���µ� 400 ����
 *
 * ������ nav.php �е����� admin-ajax.php?action=setcookie ��
 * admin-ajax.php?action=huictypephone����δע���Ӧ�� WordPress handler��
 * �˴���ע�ᣬ���ؿճɹ���Ӧ���������������̨������
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

