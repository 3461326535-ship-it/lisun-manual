<?php
/**
 * 密码保护 + 爬虫豁免模块
 * 在 PHP 层实现，不依赖 Nginx 配置
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Access_Control {

    /**
     * 爬虫 User-Agent 与反向 DNS 验证域名
     */
    const CRAWLER_PATTERNS = [
        'Googlebot'     => ['googlebot.com', 'google.com'],
        'Baiduspider'   => ['baidu.com', 'baidu.jp'],
        'Bingbot'       => ['search.msn.com'],
        'Slurp'         => ['yahoo.com', 'crawl.yahoo.net'],
        'DuckDuckBot'   => ['duckduckgo.com'],
        'YandexBot'     => ['yandex.com', 'yandex.ru'],
        'Sogou'         => ['sogou.com'],
        '360Spider'     => ['360.cn'],
        'Bytespider'    => ['bytedance.com'],
    ];

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'access_check'], 1);
        add_action('wp_ajax_lisun_test_access', [__CLASS__, 'ajax_test']);
        add_action('pre_get_posts', [__CLASS__, 'filter_archive_by_tags']);
    }

    /**
     * 访问控制检查（template_redirect 优先级 1）
     */
    public static function access_check() {
        // 仅对 manual 页面生效
        if (!is_singular('manual') && !is_post_type_archive('manual')) {
            return;
        }

        // 检查是否启用（默认关闭）
        if (!get_option('lisun_password_enabled', false)) {
            return;
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // 1. 爬虫识别 + 反向 DNS 验证
        if (self::is_valid_crawler($ua, $ip)) {
            return;
        }

        // 2. 已登录 WP 用户放行（管理员 / 编辑 / 作者）
        if (current_user_can('edit_posts')) {
            return;
        }

        // 3. 已认证客户放行（session/cookie 方式）
        $customer_data = self::is_customer_authenticated();
        if ($customer_data) {
            // 归档页：客户默认无权访问，除非开启了 archive_access
            if (is_post_type_archive('manual')) {
                $username = $customer_data['username'] ?? '';
                $customers = get_option('lisun_customers', []);
                $has_archive = $customers[$username]['archive_access'] ?? false;
                if (!$has_archive) {
                    status_header(403);
                    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:sans-serif;text-align:center;padding:60px 20px;"><h1 style="color:#cf1322;">403 Forbidden</h1><p>Archive access is not authorized for your account.</p><p style="color:#888;font-size:13px;">Please use the direct manual link provided to you.</p></body></html>';
                    exit;
                }
            }

            // 售前客户：检查产品型号权限
            if (($customer_data['type'] ?? '') === 'pre_sale' && !empty(Lisun_Manual_Customer::get_model_ids($customer_data))) {
                if (is_singular('manual')) {
                    $manual_tags = wp_get_post_terms(get_the_ID(), 'post_tag', ['fields' => 'ids']);
                    $allowed = Lisun_Manual_Customer::get_model_ids($customer_data);
                    $intersection = array_intersect($manual_tags, $allowed);
                    if (empty($intersection)) {
                        status_header(403);
                        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:sans-serif;text-align:center;padding:60px 20px;"><h1 style="color:#cf1322;">403 Forbidden</h1><p>You do not have permission to access this manual.</p><p style="color:#888;font-size:13px;">Your account is restricted to specific product models.</p></body></html>';
                        exit;
                    }
                }
            }
            self::log_access($customer_data['username'] ?? '', $ip);
            return;
        }

        // 4. 处理 HTML 表单登录（兜底方案：浏览器弹不出 Basic Auth 对话框时使用）
        $login_error = '';
        if (isset($_POST['lisun_login'], $_POST['lisun_username'], $_POST['lisun_password'])) {
            $form_user = sanitize_text_field(wp_unslash($_POST['lisun_username']));
            $form_pass = wp_unslash($_POST['lisun_password']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 密码无需清理
            $customers = get_option('lisun_customers', []);

            if (isset($customers[$form_user]) &&
                password_verify($form_pass, $customers[$form_user]['hash'])) {
                // 登录成功 → 种 Cookie 并跳转回当前页面（GET 方式）
                $cookie_val = self::encrypt_auth_cookie($form_user);
                setcookie('lisun_manual_auth', $cookie_val, [
                    'expires'  => time() + DAY_IN_SECONDS * 30,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                self::log_access($form_user, $ip);

                // 构建跳转 URL（避免 wp_safe_redirect 误判 URL 导致二次登录）
                $redirect = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $redirect = remove_query_arg(['lisun_login', 'lisun_username', 'lisun_password'], $redirect);
                $redirect = add_query_arg('_t', time(), $redirect); // 防缓存
                wp_redirect($redirect);
                exit;
            } else {
                $login_error = 'Invalid username or password.';
            }
        }

        // 5. 未登录 → 显示登录表单
        status_header(401);
        self::render_login_form($login_error);
        exit;
    }

    /**
     * 归档/列表页过滤：pre_sale 客户只能看到自己有权限的产品型号页面
     *
     * 通过 pre_get_posts 在查询层面过滤，不影响管理员和未选产品型号的客户。
     */
    public static function filter_archive_by_tags($query) {
        // 仅前台、主查询、manual 归档页
        if (is_admin() || !$query->is_main_query()) return;
        if (!$query->is_post_type_archive('manual')) return;

        // 管理员不受限制
        if (current_user_can('manage_options')) return;

        $customer_data = self::is_customer_authenticated();
        if (!$customer_data) return; // 未登录客户不过滤（登录表单会拦截）

        // 仅 pre_sale 且有产品型号限制
        if (($customer_data['type'] ?? '') !== 'pre_sale') return;
        $allowed = Lisun_Manual_Customer::get_model_ids($customer_data);
        if (empty($allowed)) return;

        // 添加 tax_query：只显示匹配标签的页面
        $tax_query = $query->get('tax_query', []);
        $tax_query[] = [
            'taxonomy' => 'post_tag',
            'field'    => 'term_id',
            'terms'    => $allowed,
            'operator' => 'IN',
        ];
        $query->set('tax_query', $tax_query);
    }

    /**
     * 判断是否为合法爬虫
     */
    private static function is_valid_crawler($ua, $ip) {
        $crawler_patterns = get_option('lisun_crawler_list', self::CRAWLER_PATTERNS);
        $ip_verification = get_option('lisun_crawler_ip_verify', true);

        foreach ($crawler_patterns as $bot_name => $domains) {
            if (stripos($ua, $bot_name) !== false) {
                // 如果关闭了 IP 验证，仅凭 UA 就放行
                if (!$ip_verification) {
                    return true;
                }

                // 反向 DNS 验证
                $host = @gethostbyaddr($ip);
                if ($host === $ip) {
                    // DNS 查询失败，不支持反向解析
                    continue;
                }

                foreach ($domains as $domain) {
                    if (stripos($host, $domain) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * 检查客户是否已通过 cookie 认证
     *
     * @return array|false 客户数据数组（含 username/type/product_models 等）或 false
     */
    private static function is_customer_authenticated() {
        $auth_cookie = $_COOKIE['lisun_manual_auth'] ?? '';
        if (empty($auth_cookie)) {
            return false;
        }

        $data = self::decrypt_auth_cookie($auth_cookie);
        if (!$data) {
            return false;
        }

        $customers = get_option('lisun_customers', []);
        if (!isset($customers[$data['username']])) {
            return false;
        }

        $customer = $customers[$data['username']];
        $customer['username'] = $data['username']; // 注入 username 以便后续使用
        return $customer;
    }

    /**
     * 记录访问日志 + 更新客户初访日期和访问次数
     */
    private static function log_access($username, $ip) {
        // 访问日志
        $post_id = get_the_ID();

        // 产品型号：从 manual 页面的 product-no 自定义字段读取（非标签）
        $product_model = '';
        if ($post_id && get_post_type($post_id) === 'manual') {
            $product_model = get_post_meta($post_id, 'product-no', true) ?: '';
        }

        // IP 地理位置（带缓存，避免每次登录都调外部 API）
        $geo = self::geolocate_ip($ip);

        $logs = get_option('lisun_access_logs', []);
        $max = get_option('lisun_access_logs_max', 1000);

        array_unshift($logs, [
            'username'      => $username,
            'ip'            => $ip,
            'country'       => $geo['country'] ?? '',
            'city'          => $geo['city'] ?? '',
            'product_model' => $product_model,
            'post_id'       => $post_id,
            'post_title'    => get_the_title($post_id),
            'time'          => current_time('mysql'),
        ]);

        if (count($logs) > $max) {
            $logs = array_slice($logs, 0, $max);
        }

        update_option('lisun_access_logs', $logs);

        // 更新客户初访日期 + 访问次数
        $customers = get_option('lisun_customers', []);
        if (isset($customers[$username])) {
            if (empty($customers[$username]['first_visit'])) {
                $customers[$username]['first_visit'] = current_time('mysql');
            }
            $customers[$username]['visit_count'] = ($customers[$username]['visit_count'] ?? 0) + 1;
            update_option('lisun_customers', $customers);
        }
    }

    /**
     * 从 WordPress SALT 派生稳定的 AES-256 密钥（32字节）
     */
    private static function get_aes_key() {
        return hash('sha256', wp_salt('auth'), true);
    }

    /**
     * 加密认证 Cookie
     */
    public static function encrypt_auth_cookie($username) {
        $payload = [
            'username' => $username,
            'time'     => time(),
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        $json = json_encode($payload);
        $key = self::get_aes_key();
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($json, 'aes-256-cbc', $key, 0, $iv);

        return base64_encode($iv . $encrypted);
    }

    /**
     * 解密认证 Cookie
     *
     * 先尝试新密钥（sha256 派生），失败则回退到旧密钥（兼容升级前的 Cookie）
     */
    private static function decrypt_auth_cookie($cookie) {
        $data = base64_decode($cookie);
        if (strlen($data) < 20) {
            return false;
        }

        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        // 尝试新密钥
        $key = self::get_aes_key();
        $json = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
        if ($json) {
            return json_decode($json, true);
        }

        // 回退：旧密钥（兼容升级前的 Cookie）
        $old_key = wp_salt('auth');
        $json = openssl_decrypt($encrypted, 'aes-256-cbc', $old_key, 0, $iv);
        if ($json) {
            return json_decode($json, true);
        }

        return false;
    }

    /**
     * AJAX: 测试爬虫判断逻辑
     */
    public static function ajax_test() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'lisun-manual')]);
        }

        $ua = sanitize_text_field($_POST['ua'] ?? '');
        $ip = sanitize_text_field($_POST['ip'] ?? '8.8.8.8');

        $is_crawler = self::is_valid_crawler($ua, $ip);

        wp_send_json_success([
            'ua'          => $ua,
            'ip'          => $ip,
            'is_crawler'  => $is_crawler,
            'reverse_dns' => @gethostbyaddr($ip),
        ]);
    }

    /**
     * 输出 HTML 登录表单（401 页面）
     *
     * 作为 HTTP Basic Auth 的兜底方案：部分浏览器/环境不会弹出原生的
     * Basic Auth 对话框，此时用户可通过页内表单直接登录。
     */
    private static function render_login_form($error = '') {
        $realm = esc_html(get_option('lisun_password_realm', 'LISUN Manual Access'));

        // 隐藏 WordPress 工具栏和后台导航
        if (function_exists('show_admin_bar')) {
            show_admin_bar(false);
        }

        nocache_headers();

        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html($realm) . '</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #f0f2f5;
    display: flex; align-items: center; justify-content: center;
    min-height: 100vh;
}
.login-box {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
    padding: 40px;
    width: 360px;
    max-width: 90vw;
}
.login-box h2 {
    font-size: 20px;
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 8px;
    text-align: center;
}
.login-box .subtitle {
    font-size: 13px;
    color: #888;
    margin-bottom: 24px;
    text-align: center;
}
.login-logo {
    text-align: center;
    margin-bottom: 16px;
}
.login-logo img {
    max-width: 120px;
    height: auto;
}
.login-box .error {
    background: #fff2f0;
    border: 1px solid #ffccc7;
    color: #cf1322;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 13px;
    margin-bottom: 16px;
}
.login-box label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: #333;
    margin-bottom: 4px;
}
.login-box input[type="text"],
.login-box input[type="password"] {
    width: 100%;
    height: 40px;
    border: 1px solid #d9d9d9;
    border-radius: 4px;
    padding: 0 12px;
    font-size: 14px;
    margin-bottom: 16px;
    transition: border-color .2s;
}
.login-box input:focus {
    border-color: #1677ff;
    outline: none;
    box-shadow: 0 0 0 2px rgba(22,119,255,.1);
}
.login-box button {
    width: 100%;
    height: 40px;
    background: #1677ff;
    color: #fff;
    border: none;
    border-radius: 4px;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    transition: background .2s;
}
.login-box button:hover { background: #4096ff; }
</style>
</head>
<body>
<div class="login-box">
    <div class="login-logo"><img src="' . esc_url(LISUN_MANUAL_URL . 'LISUN-LOGO_Original.jpg') . '" alt="LISUN"></div>
    <h2>' . esc_html($realm) . '</h2>
    <p class="subtitle">Please enter your credentials to access the manuals</p>';

        if ($error) {
            echo '<div class="error">' . esc_html($error) . '</div>';
        }

        echo '<form method="post" action="">
        <input type="hidden" name="lisun_login" value="1">
        <label for="lisun-user">Username</label>
        <input type="text" id="lisun-user" name="lisun_username" autocomplete="username" required autofocus>
        <label for="lisun-pass">Password</label>
        <input type="password" id="lisun-pass" name="lisun_password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</div>
</body>
</html>';
    }

    /**
     * IP 地理位置查询（带 30 天缓存）
     *
     * @param string $ip
     * @return array{country: string, city: string}
     */
    private static function geolocate_ip($ip) {
        // 本地/内网 IP 不查
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $ip)) {
            return ['country' => '', 'city' => ''];
        }

        // 查缓存
        $cache_key = 'lisun_geo_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $result = ['country' => '', 'city' => ''];

        // ipgeolocation.io（每月 1500 次免费，支持中文）
        $url = 'https://api.ipgeolocation.io/ipgeo?apiKey=5e5e838e06a44b6a8230d671a2e0cfc3&ip=' . rawurlencode($ip);
        $response = wp_remote_get($url, ['timeout' => 10]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body) && empty($body['message'])) {
                $result = [
                    'country' => $body['country_name'] ?? '',
                    'city'    => $body['city'] ?? '',
                ];
            }
        }

        // 缓存 30 天（IP 地理位置很少变动）
        set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);

        return $result;
    }

    /**
     * 获取访问日志
     */
    public static function get_logs($limit = 100) {
        $logs = get_option('lisun_access_logs', []);
        return array_slice($logs, 0, $limit);
    }
}
