<?php
/**
 * Mihomo 订阅管理与健康检查
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Proxy_Subscription {

    const CRON_HOOK = 'lisun_manual_proxy_subscription_check';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('init', [__CLASS__, 'ensure_schedule'], 20);
        add_action(self::CRON_HOOK, [__CLASS__, 'scheduled_check']);

        add_action('wp_ajax_lisun_proxy_subscription_check', [__CLASS__, 'ajax_check_now']);
        add_action('wp_ajax_lisun_proxy_subscription_apply', [__CLASS__, 'ajax_apply_now']);
        add_action('wp_ajax_lisun_proxy_runtime_list', [__CLASS__, 'ajax_runtime_list']);
        add_action('wp_ajax_lisun_proxy_runtime_switch', [__CLASS__, 'ajax_runtime_switch']);
        add_action('admin_footer', [__CLASS__, 'render_admin_script']);

        add_action('update_option_lisun_proxy_subscription_url', [__CLASS__, 'url_changed'], 10, 3);
        add_action('add_option_lisun_proxy_subscription_url', [__CLASS__, 'url_added'], 10, 2);
    }

    public static function register_settings() {
        $group   = 'lisun_manual_settings_group';
        $page    = 'lisun_manual_settings';
        $section = 'lisun_glossary_proxy';

        add_settings_field(
            'lisun_proxy_subscription_help',
            __('使用说明', 'lisun-manual'),
            [__CLASS__, 'render_help'],
            $page,
            $section
        );

        add_settings_field(
            'lisun_proxy_subscription_enabled',
            __('启用订阅管理', 'lisun-manual'),
            [__CLASS__, 'render_checkbox'],
            $page,
            $section,
            ['name' => 'lisun_proxy_subscription_enabled', 'label' => __('启用订阅 URL 检测和 Mihomo 管理', 'lisun-manual')]
        );
        register_setting($group, 'lisun_proxy_subscription_enabled', [
            'sanitize_callback' => function ($value) { return $value ? true : false; },
        ]);

        self::add_text_setting($group, $page, $section, 'lisun_proxy_subscription_url', __('订阅 URL', 'lisun-manual'), 'url', '', __('填写 Mihomo proxy-providers 中使用的订阅地址。', 'lisun-manual'));
        self::add_text_setting($group, $page, $section, 'lisun_proxy_subscription_provider', __('代理提供商名称（可选）', 'lisun-manual'), 'text', '', __('只有 config.yaml 含有 proxy-providers 时才填写；没有该配置时留空，只做订阅检测。', 'lisun-manual'));
        self::add_text_setting($group, $page, $section, 'lisun_proxy_subscription_config_path', __('Mihomo 配置文件', 'lisun-manual'), 'text', '/opt/lisun-mihomo/config.yaml', __('例如 /opt/lisun-mihomo/config.yaml；留空则只检测 URL，不改配置文件。', 'lisun-manual'));
        self::add_text_setting($group, $page, $section, 'lisun_proxy_subscription_controller', __('Mihomo 控制器地址', 'lisun-manual'), 'url', 'http://127.0.0.1:9090', __('用于修改配置并重载 Mihomo。仅监听 127.0.0.1 时更安全。', 'lisun-manual'));
        self::add_text_setting($group, $page, $section, 'lisun_proxy_subscription_secret', __('控制器 Secret', 'lisun-manual'), 'password', '', __('对应 external-controller-secret；没有设置可留空。', 'lisun-manual'));

        add_settings_field(
            'lisun_proxy_runtime_selector',
            __('后台切换节点', 'lisun-manual'),
            [__CLASS__, 'render_runtime_selector'],
            $page,
            $section
        );

        add_settings_field(
            'lisun_proxy_subscription_auto_apply',
            __('自动应用订阅 URL', 'lisun-manual'),
            [__CLASS__, 'render_checkbox'],
            $page,
            $section,
            ['name' => 'lisun_proxy_subscription_auto_apply', 'label' => __('保存 URL 后自动写入配置文件并重载 Mihomo（需要提供商名称）', 'lisun-manual')]
        );
        register_setting($group, 'lisun_proxy_subscription_auto_apply', [
            'sanitize_callback' => function ($value) { return $value ? true : false; },
        ]);

        add_settings_field(
            'lisun_proxy_subscription_interval',
            __('检测频率', 'lisun-manual'),
            [__CLASS__, 'render_interval'],
            $page,
            $section
        );
        register_setting($group, 'lisun_proxy_subscription_interval', [
            'sanitize_callback' => function ($value) {
                return in_array($value, ['hourly', 'twicedaily', 'daily'], true) ? $value : 'hourly';
            },
        ]);

        self::add_text_setting($group, $page, $section, 'lisun_proxy_subscription_email', __('失效通知邮箱', 'lisun-manual'), 'email', '', __('留空使用 WordPress 管理员邮箱。', 'lisun-manual'));

        add_settings_field(
            'lisun_proxy_subscription_check_proxy',
            __('订阅检测代理', 'lisun-manual'),
            [__CLASS__, 'render_checkbox'],
            $page,
            $section,
            ['name' => 'lisun_proxy_subscription_check_proxy', 'label' => __('检测订阅 URL 时使用上面的 HTTP 代理', 'lisun-manual')]
        );
        register_setting($group, 'lisun_proxy_subscription_check_proxy', [
            'sanitize_callback' => function ($value) { return $value ? true : false; },
        ]);

        add_settings_field(
            'lisun_proxy_subscription_status',
            __('检测状态', 'lisun-manual'),
            [__CLASS__, 'render_status'],
            $page,
            $section
        );
    }

    public static function render_help() {
        echo '<div style="max-width:760px;padding:12px 16px;background:#f0f6fc;border-left:4px solid #2271b1;line-height:1.8;">';
        echo '<strong>普通用法（推荐）</strong><br>';
        echo '填写“订阅 URL”，国内服务器需要勾选“订阅检测代理”；保持“自动应用订阅 URL”关闭。这样系统只负责检测订阅是否可访问，失效时发邮件，不会修改 Mihomo 配置。<br>';
        echo '<strong>自动修改 Mihomo 配置</strong><br>';
        echo '只有 config.yaml 含有 <code>proxy-providers:</code> 时才使用。此时填写其下面的提供商名称，并确认配置文件、控制器地址和权限正确，再开启“自动应用”。<br>';
        echo '<strong>提醒</strong><br>';
        echo '“立即检测”只检测订阅；“立即应用到 Mihomo”会尝试修改配置文件。检测频率由 WordPress 定时任务触发，邮件发送依赖 WordPress SMTP。';
        echo '</div>';
    }

    public static function render_runtime_selector() {
        echo '<div id="lisun-proxy-runtime-selector" style="max-width:760px;">';
        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<select id="lisun-proxy-runtime-group" style="min-width:220px"><option value="">正在读取节点组…</option></select>';
        echo '<select id="lisun-proxy-runtime-node" style="min-width:280px"><option value="">请先选择节点组</option></select>';
        echo '<button type="button" class="button button-primary" id="lisun-proxy-runtime-switch">切换节点</button>';
        echo '</div>';
        echo '<p class="description">直接通过 Mihomo 控制器切换运行中的节点，不修改 config.yaml。若 GLOBAL 当前是 DIRECT，请先把 GLOBAL 切换为你的代理选择组，再切换选择组中的具体节点。</p>';
        echo '<span id="lisun-proxy-runtime-result"></span>';
        echo '</div>';
    }

    private static function add_text_setting($group, $page, $section, $name, $title, $type, $default, $description) {
        add_settings_field($name, $title, function () use ($name, $type, $default, $description) {
            $value = get_option($name, $default);
            $autocomplete = $type === 'password' ? ' autocomplete="new-password"' : '';
            echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text"' . $autocomplete . '>';
            if ($description !== '') {
                echo '<p class="description">' . esc_html($description) . '</p>';
            }
        }, $page, $section);

        $sanitize = 'sanitize_text_field';
        if ($type === 'url') $sanitize = 'esc_url_raw';
        if ($type === 'email') $sanitize = 'sanitize_email';
        register_setting($group, $name, ['sanitize_callback' => $sanitize, 'default' => $default]);
    }

    public static function render_checkbox($args) {
        $name = $args['name'];
        $value = get_option($name, false);
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
        echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($value, true, false) . '> ' . esc_html($args['label']) . '</label>';
    }

    public static function render_interval() {
        $value = get_option('lisun_proxy_subscription_interval', 'hourly');
        echo '<select name="lisun_proxy_subscription_interval">';
        foreach (['hourly' => '每小时', 'twicedaily' => '每天两次', 'daily' => '每天一次'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">启用订阅管理后生效。检测由 WordPress 定时任务触发。</p>';
    }

    public static function render_status() {
        $last = get_option('lisun_proxy_subscription_last_check', 0);
        $ok_raw = get_option('lisun_proxy_subscription_last_ok', null);
        // WordPress 可能把 true 读取为字符串 "1"，不能使用严格的 true 判断。
        if ($ok_raw === null) {
            $ok = null;
        } else {
            $ok = in_array($ok_raw, [true, 1, '1', 'true'], true);
        }
        $error = get_option('lisun_proxy_subscription_last_error', '');
        $apply_message = get_option('lisun_proxy_subscription_last_apply_message', '');
        $apply_time = get_option('lisun_proxy_subscription_last_apply', 0);
        $provider = trim((string) get_option('lisun_proxy_subscription_provider', ''));
        $time = $last ? wp_date('Y-m-d H:i:s', (int) $last) : '尚未检测';
        $color = $ok === true ? '#008a20' : ($ok === false ? '#b32d2e' : '#666');
        $text = $ok === true ? '正常' : ($ok === false ? '失效' : '未知');
        echo '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html($text) . '</span>　最近检测：' . esc_html($time);
        if ($error !== '') echo '<br><span style="color:#b32d2e;">' . esc_html($error) . '</span>';
        if ($apply_message !== '' && $provider !== '') {
            echo '<br><span>最近应用：' . esc_html($apply_time ? wp_date('Y-m-d H:i:s', (int) $apply_time) : '') . '，' . esc_html($apply_message) . '</span>';
        }
        echo '<br><button type="button" class="button" id="lisun-proxy-subscription-check">立即检测</button> ';
        if ($provider !== '') {
            echo '<button type="button" class="button" id="lisun-proxy-subscription-apply">立即应用到 Mihomo</button> ';
        } else {
            echo '<span style="color:#666;">当前为检测模式，无需应用到 Mihomo</span> ';
        }
        echo '<span id="lisun-proxy-subscription-result"></span>';
    }

    public static function ensure_schedule() {
        $enabled = (bool) get_option('lisun_proxy_subscription_enabled', false);
        $url = trim((string) get_option('lisun_proxy_subscription_url', ''));
        $event = wp_get_scheduled_event(self::CRON_HOOK);

        if (!$enabled || $url === '') {
            if ($event) wp_unschedule_event($event->timestamp, self::CRON_HOOK);
            return;
        }

        $recurrence = get_option('lisun_proxy_subscription_interval', 'hourly');
        if (!$event) {
            wp_schedule_event(time() + 60, $recurrence, self::CRON_HOOK);
        } elseif (($event->schedule ?? '') !== $recurrence) {
            wp_unschedule_event($event->timestamp, self::CRON_HOOK);
            wp_schedule_event(time() + 60, $recurrence, self::CRON_HOOK);
        }
    }

    public static function scheduled_check() {
        self::check(false);
    }

    public static function ajax_check_now() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限'], 403);
        }
        check_ajax_referer('lisun_proxy_subscription_check');
        $result = self::check(true);
        if ($result['ok']) wp_send_json_success($result);
        wp_send_json_error($result);
    }

    public static function ajax_apply_now() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限'], 403);
        }
        check_ajax_referer('lisun_proxy_subscription_apply');
        $result = self::apply_to_mihomo();
        self::save_apply_result($result);
        if ($result['ok']) wp_send_json_success($result);
        wp_send_json_error($result);
    }

    public static function ajax_runtime_list() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限'], 403);
        }
        check_ajax_referer('lisun_proxy_runtime_list');
        $result = self::get_runtime_groups();
        if ($result['ok']) wp_send_json_success($result);
        wp_send_json_error($result);
    }

    public static function ajax_runtime_switch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '无权限'], 403);
        }
        check_ajax_referer('lisun_proxy_runtime_switch');

        $group = sanitize_text_field(wp_unslash($_POST['group'] ?? ''));
        $node  = sanitize_text_field(wp_unslash($_POST['node'] ?? ''));
        if ($group === '' || $node === '') {
            wp_send_json_error(['message' => '请选择节点组和节点']);
        }

        $groups = self::get_runtime_groups();
        if (!$groups['ok']) wp_send_json_error($groups);
        $matched = null;
        foreach ($groups['groups'] as $item) {
            if ((string) $item['name'] === $group) {
                $matched = $item;
                break;
            }
        }
        if ($matched === null) wp_send_json_error(['message' => '节点组不存在或已更新，请刷新后重试']);
        if (!in_array($node, $matched['all'], true)) {
            wp_send_json_error(['message' => '所选节点不属于该节点组，请刷新后重试']);
        }

        $request = self::controller_request(
            'PUT',
            '/proxies/' . rawurlencode($group),
            ['name' => $node]
        );
        if (!$request['ok']) wp_send_json_error($request);
        wp_send_json_success(['message' => '已切换到：' . $node, 'group' => $group, 'node' => $node]);
    }

    private static function controller_request($method, $path, $body = null) {
        $controller = rtrim(trim((string) get_option('lisun_proxy_subscription_controller', 'http://127.0.0.1:9090')), '/');
        if ($controller === '') return ['ok' => false, 'message' => '未配置 Mihomo 控制器地址'];

        $headers = ['Accept' => 'application/json'];
        $secret = trim((string) get_option('lisun_proxy_subscription_secret', ''));
        if ($secret !== '') $headers['Authorization'] = 'Bearer ' . $secret;
        $args = [
            'method'    => strtoupper((string) $method),
            'headers'   => $headers,
            'timeout'   => 15,
            'sslverify' => false,
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($controller . $path, $args);
        if (is_wp_error($response)) return ['ok' => false, 'message' => 'Mihomo 控制器请求失败：' . $response->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $body_text = trim((string) wp_remote_retrieve_body($response));
            return ['ok' => false, 'message' => 'Mihomo 控制器返回 HTTP ' . $code . ($body_text !== '' ? '：' . mb_substr($body_text, 0, 200) : '')];
        }
        return ['ok' => true, 'data' => json_decode((string) wp_remote_retrieve_body($response), true)];
    }

    private static function get_runtime_groups() {
        $request = self::controller_request('GET', '/proxies');
        if (!$request['ok']) return $request;
        $proxies = $request['data']['proxies'] ?? null;
        if (!is_array($proxies)) return ['ok' => false, 'message' => 'Mihomo 返回中没有 proxies 节点数据'];

        $selectable_types = ['Selector', 'Fallback', 'URLTest', 'LoadBalance', 'Relay'];
        $groups = [];
        foreach ($proxies as $name => $proxy) {
            if (!is_array($proxy) || !in_array((string) ($proxy['type'] ?? ''), $selectable_types, true)) continue;
            $all = array_values(array_filter(array_map('strval', (array) ($proxy['all'] ?? [])), static function ($value) {
                return $value !== '';
            }));
            if (empty($all)) continue;
            $groups[] = [
                'name'    => (string) $name,
                'type'    => (string) ($proxy['type'] ?? ''),
                'now'     => (string) ($proxy['now'] ?? ''),
                'all'     => $all,
            ];
        }
        if (empty($groups)) return ['ok' => false, 'message' => 'Mihomo 没有可切换的代理组'];
        return ['ok' => true, 'groups' => $groups];
    }

    private static function check($manual) {
        $url = trim((string) get_option('lisun_proxy_subscription_url', ''));
        if ($url === '') {
            return ['ok' => false, 'message' => '尚未填写订阅 URL'];
        }

        $ok = true;
        $message = '订阅 URL 可访问';
        $code = 0;
        $proxy = '';
        if (get_option('lisun_proxy_subscription_check_proxy', false)) {
            $proxy = trim((string) get_option('lisun_glossary_proxy_url', 'http://127.0.0.1:7890'));
        }
        $fetched = self::fetch_subscription($url, $proxy);
        if (!$fetched['ok']) {
            $ok = false;
            $message = $fetched['message'];
            $code = (int) ($fetched['code'] ?? 0);
        } else {
            $code = (int) $fetched['code'];
            $body = (string) $fetched['body'];
            if ($code < 200 || $code >= 300) {
                $ok = false;
                $message = 'HTTP ' . $code;
            } elseif (strlen(trim($body)) < 10) {
                $ok = false;
                $message = '返回内容为空，可能订阅已失效';
            } elseif (preg_match('/<html[\s>]|subscription\s+(expired|invalid)|unauthorized|access denied/i', $body)) {
                $ok = false;
                $message = '返回的是错误页面或失效提示';
            }
        }

        $previous = get_option('lisun_proxy_subscription_state', 'unknown');
        update_option('lisun_proxy_subscription_last_check', time(), false);
        update_option('lisun_proxy_subscription_last_ok', $ok, false);
        update_option('lisun_proxy_subscription_last_error', $ok ? '' : $message, false);
        update_option('lisun_proxy_subscription_last_http_code', $code, false);
        update_option('lisun_proxy_subscription_state', $ok ? 'ok' : 'failed', false);

        if ($previous !== ($ok ? 'ok' : 'failed')) {
            self::send_state_email($ok, $message, $url, $code);
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'code' => $code,
            'manual' => (bool) $manual,
        ];
    }

    private static function fetch_subscription($url, $proxy = '') {
        $user_agent = 'LISUN-Manual-Subscription-Monitor/' . (defined('LISUN_MANUAL_VERSION') ? LISUN_MANUAL_VERSION : '1.0');

        // WordPress HTTP API 没有统一支持自定义代理地址；代理检测使用 cURL，
        // 避免国内服务器把订阅请求直连到返回 520 的 CDN。
        if ($proxy !== '') {
            if (!function_exists('curl_init')) {
                return ['ok' => false, 'message' => '已启用订阅检测代理，但 PHP 未启用 cURL 扩展'];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => $user_agent,
                CURLOPT_HTTPHEADER => ['Accept: */*'],
                CURLOPT_ENCODING => '',
                CURLOPT_PROXY => $proxy,
            ]);
            // 命令行测试显示该订阅服务通过 HTTP/2 返回 200；部分 CDN 对
            // PHP cURL 默认协商出的 HTTP/1.1 返回 520，因此优先使用 HTTP/2。
            if (defined('CURL_HTTP_VERSION_2TLS')) {
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
            }
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) return ['ok' => false, 'message' => '代理请求失败：' . ($error !== '' ? $error : '未知 cURL 错误'), 'code' => $code];
            return ['ok' => true, 'body' => (string) $body, 'code' => $code];
        }

        $response = wp_remote_get($url, [
            'timeout' => 25,
            'redirection' => 3,
            'sslverify' => true,
            'user-agent' => $user_agent,
        ]);
        if (is_wp_error($response)) return ['ok' => false, 'message' => $response->get_error_message(), 'code' => 0];
        return [
            'ok' => true,
            'body' => (string) wp_remote_retrieve_body($response),
            'code' => (int) wp_remote_retrieve_response_code($response),
        ];
    }

    private static function send_state_email($ok, $message, $url, $code) {
        $to = sanitize_email(get_option('lisun_proxy_subscription_email', ''));
        if ($to === '') $to = sanitize_email(get_option('admin_email', ''));
        if ($to === '') return;

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = '[' . $site . '] Mihomo 订阅' . ($ok ? '已恢复' : '已失效');
        $body = 'Mihomo 订阅状态发生变化。' . "\n\n";
        $body .= '状态：' . ($ok ? '正常/已恢复' : '失效') . "\n";
        $body .= '检测结果：' . $message . "\n";
        if ($code) $body .= 'HTTP 状态：' . $code . "\n";
        $body .= '订阅 URL：' . $url . "\n";
        $body .= '检测时间：' . wp_date('Y-m-d H:i:s') . "\n";
        wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
    }

    public static function url_changed($old, $new, $option) {
        if ((string) $old === (string) $new) return;
        self::apply_if_enabled((string) $new);
    }

    public static function url_added($option, $value) {
        self::apply_if_enabled((string) $value);
    }

    private static function apply_if_enabled($url) {
        // options.php 按表单顺序保存设置；URL 字段可能先于“自动应用”字段触发更新钩子，
        // 因此同时读取本次提交值，避免第一次勾选时拿到旧选项。
        $posted_auto_apply = isset($_POST['lisun_proxy_subscription_auto_apply'])
            ? (bool) intval(wp_unslash($_POST['lisun_proxy_subscription_auto_apply']))
            : false;
        if (!get_option('lisun_proxy_subscription_auto_apply', false) && !$posted_auto_apply) return;
        if (trim($url) === '') return;
        self::save_apply_result(self::apply_to_mihomo($url));
    }

    private static function save_apply_result($result) {
        update_option('lisun_proxy_subscription_last_apply', time(), false);
        update_option('lisun_proxy_subscription_last_apply_message', (string) ($result['message'] ?? ''), false);
        update_option('lisun_proxy_subscription_last_apply_ok', !empty($result['ok']), false);
    }

    public static function deactivate() {
        $event = wp_get_scheduled_event(self::CRON_HOOK);
        if ($event) wp_unschedule_event($event->timestamp, self::CRON_HOOK);
    }

    public static function apply_to_mihomo($url = '') {
        $url = trim($url !== '' ? $url : (string) get_option('lisun_proxy_subscription_url', ''));
        $path = trim((string) get_option('lisun_proxy_subscription_config_path', '/opt/lisun-mihomo/config.yaml'));
        $provider = trim((string) get_option('lisun_proxy_subscription_provider', ''));
        if ($url === '' || $path === '') return ['ok' => false, 'message' => '订阅 URL和配置路径不能为空'];
        if ($provider === '') return ['ok' => false, 'message' => '未填写提供商名称；config.yaml 没有 proxy-providers 时只能检测 URL，不能自动应用'];
        if (!is_readable($path)) return ['ok' => false, 'message' => '配置文件不可读：' . $path];

        $yaml = file_get_contents($path);
        if ($yaml === false) return ['ok' => false, 'message' => '无法读取配置文件'];
        $updated = self::replace_provider_url($yaml, $provider, $url);
        if ($updated === false) return ['ok' => false, 'message' => '未找到 proxy-providers 下的提供商：' . $provider];
        if ($updated === $yaml) return ['ok' => true, 'message' => '配置中的订阅 URL 没有变化'];
        if (!is_writable($path)) return ['ok' => false, 'message' => '配置文件不可写：' . $path];

        $backup = $path . '.bak';
        if (!@copy($path, $backup)) return ['ok' => false, 'message' => '无法创建配置备份：' . $backup];
        if (@file_put_contents($path, $updated, LOCK_EX) === false) return ['ok' => false, 'message' => '无法写入配置文件'];

        $reload = self::reload_mihomo();
        if (!$reload['ok']) return ['ok' => false, 'message' => 'URL 已写入，但 Mihomo 重载失败：' . $reload['message']];
        return ['ok' => true, 'message' => '订阅 URL 已写入并重载 Mihomo'];
    }

    private static function replace_provider_url($yaml, $provider, $url) {
        $lines = preg_split('/\r\n|\n|\r/', $yaml);
        $in_section = false;
        $provider_index = -1;
        $provider_indent = 0;
        $section_indent = 0;

        foreach ($lines as $i => $line) {
            if (preg_match('/^(\s*)proxy-providers\s*:\s*(?:#.*)?$/', $line, $m)) {
                $in_section = true;
                $section_indent = strlen($m[1]);
                continue;
            }
            if (!$in_section || trim($line) === '' || preg_match('/^\s*#/', $line)) continue;
            $indent = strlen($line) - strlen(ltrim($line));
            if ($indent <= $section_indent) {
                $in_section = false;
                continue;
            }
            if ($provider_index < 0 && preg_match('/^(\s*)(?:["\'])?(.+?)(?:["\'])?\s*:\s*(?:#.*)?$/', $line, $m)) {
                if ($m[2] === $provider) {
                    $provider_index = $i;
                    $provider_indent = strlen($m[1]);
                }
            }
        }
        if ($provider_index < 0) return false;

        $found_url = false;
        $end = count($lines);
        for ($i = $provider_index + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (trim($line) !== '' && !preg_match('/^\s*#/', $line)) {
                $indent = strlen($line) - strlen(ltrim($line));
                if ($indent <= $provider_indent) {
                    $end = $i;
                    break;
                }
            }
            if (preg_match('/^(\s*)url\s*:/i', $line, $m)) {
                $lines[$i] = $m[1] . 'url: "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $url) . '"';
                $found_url = true;
                break;
            }
        }
        if (!$found_url) {
            $insert_at = $end;
            for ($i = $provider_index + 1; $i < $end; $i++) {
                if (preg_match('/^(\s*)type\s*:/i', $lines[$i], $m)) {
                    $insert_at = $i + 1;
                    $lines = array_merge(array_slice($lines, 0, $insert_at), [$m[1] . 'url: "' . str_replace(['\\', '"'], ['\\\\', '\\"'], $url) . '"'], array_slice($lines, $insert_at));
                    $found_url = true;
                    break;
                }
            }
        }
        return $found_url ? implode("\n", $lines) : false;
    }

    private static function reload_mihomo() {
        $controller = rtrim(trim((string) get_option('lisun_proxy_subscription_controller', 'http://127.0.0.1:9090')), '/');
        if ($controller === '') return ['ok' => false, 'message' => '未配置 Mihomo 控制器地址'];
        $headers = ['Content-Type' => 'application/json'];
        $secret = trim((string) get_option('lisun_proxy_subscription_secret', ''));
        if ($secret !== '') $headers['Authorization'] = 'Bearer ' . $secret;
        $response = wp_remote_request($controller . '/configs?force=true', [
            'method' => 'PUT',
            'headers' => $headers,
            'body' => wp_json_encode(['path' => '', 'payload' => '']),
            'timeout' => 15,
            'sslverify' => false,
        ]);
        if (is_wp_error($response)) return ['ok' => false, 'message' => $response->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300 ? ['ok' => true, 'message' => 'HTTP ' . $code] : ['ok' => false, 'message' => 'HTTP ' . $code];
    }

    public static function render_admin_script() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, 'lisun-manual-settings') === false) return;
        ?>
        <script>
        jQuery(function ($) {
            var $runtimeGroup = $('#lisun-proxy-runtime-group');
            var $runtimeNode = $('#lisun-proxy-runtime-node');
            var runtimeGroups = [];
            function renderRuntimeNodes() {
                var groupName = $runtimeGroup.val();
                var group = runtimeGroups.find(function (item) { return item.name === groupName; });
                $runtimeNode.empty();
                if (!group) {
                    $runtimeNode.append($('<option>').val('').text('请先选择节点组'));
                    return;
                }
                group.all.forEach(function (node) {
                    $runtimeNode.append($('<option>').val(node).text(node + (node === group.now ? '（当前）' : '')));
                });
                if (group.now && group.all.indexOf(group.now) >= 0) $runtimeNode.val(group.now);
            }
            if ($runtimeGroup.length) {
                $.post(ajaxurl, {
                    action: 'lisun_proxy_runtime_list',
                    _ajax_nonce: '<?php echo esc_js(wp_create_nonce('lisun_proxy_runtime_list')); ?>'
                }).done(function (response) {
                    var data = response.data || {};
                    runtimeGroups = data.groups || [];
                    $runtimeGroup.empty();
                    if (!response.success || !runtimeGroups.length) {
                        $runtimeGroup.append($('<option>').val('').text(data.message || '没有可用节点组'));
                        renderRuntimeNodes();
                        return;
                    }
                    runtimeGroups.forEach(function (group) {
                        $runtimeGroup.append($('<option>').val(group.name).text(group.name + '（' + group.type + '）'));
                    });
                    renderRuntimeNodes();
                }).fail(function () {
                    $runtimeGroup.empty().append($('<option>').val('').text('读取 Mihomo 节点失败'));
                });
                $runtimeGroup.on('change', renderRuntimeNodes);
                $('#lisun-proxy-runtime-switch').on('click', function () {
                    var $button = $(this), $result = $('#lisun-proxy-runtime-result');
                    if (!$runtimeGroup.val() || !$runtimeNode.val()) {
                        $result.text('请先选择节点组和节点').css('color', '#b32d2e');
                        return;
                    }
                    $button.prop('disabled', true); $result.text('切换中…').css('color', '#666');
                    $.post(ajaxurl, {
                        action: 'lisun_proxy_runtime_switch',
                        group: $runtimeGroup.val(),
                        node: $runtimeNode.val(),
                        _ajax_nonce: '<?php echo esc_js(wp_create_nonce('lisun_proxy_runtime_switch')); ?>'
                    }).done(function (response) {
                        var data = response.data || {};
                        $result.text(data.message || '切换完成').css('color', response.success ? '#008a20' : '#b32d2e');
                        if (response.success) setTimeout(function () { location.reload(); }, 700);
                    }).fail(function () {
                        $result.text('请求失败，请检查 Mihomo 控制器').css('color', '#b32d2e');
                    }).always(function () { $button.prop('disabled', false); });
                });
            }
            $('#lisun-proxy-subscription-check').on('click', function () {
                var $button = $(this), $result = $('#lisun-proxy-subscription-result');
                $button.prop('disabled', true); $result.text('检测中…').css('color', '#666');
                $.post(ajaxurl, {
                    action: 'lisun_proxy_subscription_check',
                    _ajax_nonce: '<?php echo esc_js(wp_create_nonce('lisun_proxy_subscription_check')); ?>'
                }).done(function (response) {
                    var data = response.data || {};
                    $result.text(data.message || '检测完成').css('color', response.success ? '#008a20' : '#b32d2e');
                    setTimeout(function () { location.reload(); }, 500);
                }).fail(function () {
                    $result.text('请求失败').css('color', '#b32d2e');
                }).always(function () { $button.prop('disabled', false); });
            });
            $('#lisun-proxy-subscription-apply').on('click', function () {
                var $button = $(this), $result = $('#lisun-proxy-subscription-result');
                $button.prop('disabled', true); $result.text('应用中…').css('color', '#666');
                $.post(ajaxurl, {
                    action: 'lisun_proxy_subscription_apply',
                    _ajax_nonce: '<?php echo esc_js(wp_create_nonce('lisun_proxy_subscription_apply')); ?>'
                }).done(function (response) {
                    var data = response.data || {};
                    $result.text(data.message || '应用完成').css('color', response.success ? '#008a20' : '#b32d2e');
                    setTimeout(function () { location.reload(); }, 500);
                }).fail(function () {
                    $result.text('请求失败').css('color', '#b32d2e');
                }).always(function () { $button.prop('disabled', false); });
            });
        });
        </script>
        <?php
    }
}
