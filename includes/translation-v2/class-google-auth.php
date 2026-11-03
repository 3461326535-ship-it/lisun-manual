<?php
/**
 * Service-account authentication + HTTP plumbing for Google Cloud Translation v3
 * and Cloud Storage.
 *
 * Cloud Translation v3 rejects API keys ("API keys are not supported by this
 * API"), so the Google engine authenticates as a service account: sign a JWT
 * with the account's private key, exchange it for a bearer token, cache the
 * token in a transient for ~50 minutes.
 *
 * The credential file lives outside the plugin directory (settings hold the
 * path) so it never enters the git repository.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Google_Auth {

    const DEFAULT_SA_PATH  = '';
    const DEFAULT_PROJECT  = 'lisun-group';
    const DEFAULT_LOCATION = 'us-central1';
    const DEFAULT_BUCKET   = 'lisun-translation-glossary';
    const SCOPE            = 'https://www.googleapis.com/auth/cloud-platform';
    const TOKEN_ENDPOINT   = 'https://oauth2.googleapis.com/token';

    /** Hosts that may use the optional local proxy (Google is blocked in CN). */
    private static $proxy_hosts = [
        'oauth2.googleapis.com',
        'storage.googleapis.com',
        'translation.googleapis.com',
        'www.googleapis.com',
    ];

    public static function credentials_path() {
        $path = trim((string) get_option('lisun_google_sa_path', ''));
        if ($path !== '') return $path;
        // Default: beside uploads, i.e. outside the plugin directory so the
        // credential never lands in the git repository.
        if (defined('WP_CONTENT_DIR')) {
            return untrailingslashit(WP_CONTENT_DIR) . '/uploads/lisun-google-sa.json';
        }
        return '';
    }

    public static function project() {
        $project = trim((string) get_option('lisun_google_project', ''));
        return $project === '' ? self::DEFAULT_PROJECT : $project;
    }

    public static function location() {
        $location = trim((string) get_option('lisun_google_location', ''));
        return $location === '' ? self::DEFAULT_LOCATION : $location;
    }

    public static function bucket() {
        $bucket = trim((string) get_option('lisun_google_bucket', ''));
        return $bucket === '' ? self::DEFAULT_BUCKET : $bucket;
    }

    public static function object_prefix() {
        $prefix = trim((string) get_option('lisun_google_object_prefix', ''));
        if ($prefix === '') $prefix = 'lisun';
        return trim($prefix, '/');
    }

    /** Read + validate the service account JSON (never logged). */
    public static function credentials() {
        $path = self::credentials_path();
        if ($path === '') {
            return new WP_Error('google_sa_missing_path', '未配置 Google 服务账号 JSON 路径（设置 → 翻译 API 设置 → Google 服务账号 JSON）');
        }
        if (!is_readable($path)) {
            return new WP_Error('google_sa_unreadable', 'Google 服务账号 JSON 不可读：' . $path);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return new WP_Error('google_sa_unreadable', 'Google 服务账号 JSON 读取失败：' . $path);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return new WP_Error('google_sa_invalid', 'Google 服务账号 JSON 缺少 client_email 或 private_key');
        }
        return $data;
    }

    /**
     * Bearer token for the service account.
     * @return string|WP_Error
     */
    public static function token($force_refresh = false) {
        $sa = self::credentials();
        if (is_wp_error($sa)) return $sa;

        $cache_key = 'lisun_google_token_' . md5($sa['client_email'] . '|' . self::credentials_path());
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_string($cached) && $cached !== '') return $cached;
        }

        $jwt = self::sign_jwt($sa);
        if (is_wp_error($jwt)) return $jwt;

        $response = self::http(
            self::TOKEN_ENDPOINT,
            'POST',
            [
                'body'    => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'timeout' => 30,
            ],
            // oauth2.googleapis.com is blocked in mainland China too, so the
            // token exchange must honour the same proxy switch.
            true
        );
        if (is_wp_error($response)) return $response;

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
            $message = isset($body['error_description'])
                ? (string) $body['error_description']
                : (isset($body['error']) && is_string($body['error']) ? (string) $body['error'] : ('HTTP ' . $code));
            self::log('Google token error http=' . $code . ' message=' . $message);
            return new WP_Error('google_token_error', 'Google 服务账号换取令牌失败：' . $message, ['http_code' => $code]);
        }

        $ttl = isset($body['expires_in']) ? max(60, ((int) $body['expires_in']) - 600) : 3000;
        set_transient($cache_key, (string) $body['access_token'], $ttl);
        self::log('Google token acquired ttl=' . $ttl);
        return (string) $body['access_token'];
    }

    public static function clear_token() {
        $sa = self::credentials();
        if (is_wp_error($sa)) return;
        delete_transient('lisun_google_token_' . md5($sa['client_email'] . '|' . self::credentials_path()));
    }

    /**
     * Signed RS256 JWT. Google requires the assertion form, not an OAuth secret.
     */
    private static function sign_jwt($sa) {
        if (!function_exists('openssl_sign')) {
            return new WP_Error('google_openssl_missing', '服务器缺少 PHP openssl 扩展，无法为 Google 服务账号签名');
        }
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => (string) $sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $enc = static function ($data) {
            return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
        };
        $signing = $enc(wp_json_encode($header)) . '.' . $enc(wp_json_encode($claims));

        $signature = '';
        $key = openssl_pkey_get_private((string) $sa['private_key']);
        if ($key === false) {
            return new WP_Error('google_sa_key_invalid', 'Google 服务账号私钥无法解析');
        }
        $ok = openssl_sign($signing, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok || $signature === '') {
            return new WP_Error('google_sa_sign_failed', 'Google 服务账号 JWT 签名失败');
        }
        return $signing . '.' . $enc($signature);
    }

    /**
     * Authenticated JSON request against a Google API.
     *
     * @return array{code:int,data:array,raw:string}|WP_Error
     */
    public static function api($url, $method = 'GET', $body = null, $timeout = 60) {
        $token = self::token();
        if (is_wp_error($token)) return $token;

        $args = [
            'method'  => strtoupper($method),
            'timeout' => $timeout,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ];
        if ($body !== null) {
            $encoded = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
            if (!is_string($encoded)) {
                return new WP_Error('google_body_encode', 'Google 请求内容无法编码为 JSON');
            }
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
            $args['body'] = $encoded;
        }

        $response = self::http($url, strtoupper($method), $args, true);
        if (is_wp_error($response)) return $response;

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];

        if ($code === 401) {
            // token may have been revoked early: retry once with a fresh one.
            self::clear_token();
            $token = self::token(true);
            if (!is_wp_error($token)) {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
                $retry = self::http($url, strtoupper($method), $args, true);
                if (!is_wp_error($retry)) {
                    $code = (int) wp_remote_retrieve_response_code($retry);
                    $raw = (string) wp_remote_retrieve_body($retry);
                    $data = json_decode($raw, true);
                    if (!is_array($data)) $data = [];
                }
            }
        }

        if ($code < 200 || $code >= 300) {
            $message = $data['error']['message'] ?? ('HTTP ' . $code);
            self::log('Google api error url=' . preg_replace('#\?.*$#', '', $url)
                . ' http=' . $code . ' message=' . (is_string($message) ? $message : wp_json_encode($message)));
            return new WP_Error('google_api_error', is_string($message) ? $message : 'Google 接口返回错误', [
                'http_code' => $code,
                'body'      => $data,
            ]);
        }

        return ['code' => $code, 'data' => $data, 'raw' => $raw];
    }

    /** Raw bytes upload (Cloud Storage media upload). */
    public static function upload($url, $bytes, $content_type = 'text/csv') {
        $token = self::token();
        if (is_wp_error($token)) return $token;
        $response = self::http($url, 'POST', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => $content_type,
            ],
            'body' => $bytes,
        ], true);
        if (is_wp_error($response)) return $response;

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            $message = $data['error']['message'] ?? ('HTTP ' . $code);
            self::log('Google storage error http=' . $code . ' message=' . (is_string($message) ? $message : ''));
            return new WP_Error('google_storage_error', is_string($message) ? $message : 'GCS 上传失败', ['http_code' => $code]);
        }
        return ['code' => $code, 'data' => is_array($data) ? $data : []];
    }

    /**
     * wp_remote_* wrapper that applies the optional local proxy to Google hosts
     * (the same switch the DeepL path uses) without touching other requests.
     */
    private static function http($url, $method, $args, $use_proxy) {
        $filter = null;
        if ($use_proxy && get_option('lisun_deepl_proxy_enabled', false)) {
            $proxy = trim((string) get_option('lisun_deepl_proxy_url', 'http://127.0.0.1:7890'));
            if ($proxy !== '' && function_exists('curl_init')) {
                $filter = static function ($handle, $request, $request_url) use ($proxy) {
                    $host = (string) wp_parse_url($request_url, PHP_URL_HOST);
                    if (!in_array($host, self::$proxy_hosts, true)) return $handle;
                    if (defined('CURLOPT_PROXY')) curl_setopt($handle, CURLOPT_PROXY, $proxy);
                    return $handle;
                };
                add_filter('http_api_curl', $filter, 10, 3);
            }
        }
        // wp_remote_request only honours a method that is present in $args.
        $args['method'] = $method !== '' ? strtoupper($method) : 'GET';
        $response = wp_remote_request($url, $args);
        if ($filter !== null) remove_filter('http_api_curl', $filter, 10);
        if (is_wp_error($response)) {
            self::log('Google http error url=' . preg_replace('#\?.*$#', '', $url) . ' message=' . $response->get_error_message());
            return new WP_Error('google_http', $response->get_error_message());
        }
        return $response;
    }

    /** Endpoint helpers. */
    public static function v3_base() {
        return 'https://translation.googleapis.com/v3/projects/' . self::project()
            . '/locations/' . self::location();
    }

    public static function translate_endpoint() {
        return self::v3_base() . ':translateText';
    }

    private static function log($message) {
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log((string) $message);
        }
    }
}
