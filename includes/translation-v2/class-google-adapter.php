<?php
/**
 * Google Cloud Translation adapter for Translation V2.
 *
 * Deliberately a thin transport twin of Lisun_Manual_V2_DeepL_Adapter:
 *  - it sends the *same* synthetic unit XML that the DeepL path sends
 *    (<lisun-unit>/<t>/<ph>/<f>), only with mimeType/format = html,
 *  - it hands the answer back to the *same* Lisun_Manual_V2_Unit_Transport::restore()
 *    validator, so term anchoring, degrade semantics and HTML reconstruction are
 *    byte-for-byte the code path already proven with DeepL.
 * Nothing engine-specific is added: no placeholder spans, no custom restore,
 * no post-processing of the returned markup.
 *
 * Two modes, selected by lisun_google_mode:
 *  - v3_glossary (default): Advanced v3 with the service account and the Google
 *    glossary maintained by Lisun_Manual_V2_Google_Glossary, so terminology is
 *    enforced by Google exactly the way DeepL enforces its own glossary.
 *  - v2_plain: Basic v2 with an API key, no glossary (pure translation fallback).
 *
 * Without a glossary (v2_plain, or a unit Google words differently than the local
 * glossary) the unit stays "degraded": Google's own wording is kept, no term
 * identity is claimed, and the unit is logged.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Google_Adapter {

    /** Cloud Translation v2 accepts up to 128 q segments per request. */
    const MAX_TEXTS = 100;
    /** Cloud Translation v2 accepts up to 30k characters per request. */
    const MAX_CHARS_PER_REQUEST = 18000;

    const DEFAULT_ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    private $request_count = 0;
    private $characters = 0;
    private $degraded_units = 0;
    private $residual_units = 0;
    private $terms = null;

    /** @param array|null $terms 本次翻译生效的术语子集（文章级筛选结果）。 */
    public function __construct($terms = null) {
        $this->terms = is_array($terms) ? $terms : null;
    }

    public function translate($prepared_units, $post_id = 0, $on_batch = null) {
        $batches = $this->make_batches($prepared_units);
        if (is_wp_error($batches)) return $batches;
        $results = [];
        $completed = 0;
        $total_units = count((array) $prepared_units);
        $total_batches = count($batches);
        foreach ($batches as $batch_index => $batch) {
            $batch_results = $this->request($batch, $post_id, $batch_index);
            if (is_wp_error($batch_results)) return $batch_results;
            foreach ($batch_results as $unit_key => $result) $results[$unit_key] = $result;
            $completed += count($batch);
            if (is_callable($on_batch)) {
                call_user_func($on_batch, $completed, $total_units, $batch_index + 1, $total_batches);
            }
        }
        if ($this->degraded_units > 0 || $this->residual_units > 0) {
            self::log('V2 Google finished post=' . (int) $post_id
                . ' mode=' . (string) get_option('lisun_google_mode', 'v3_glossary')
                . ' degraded_units=' . (int) $this->degraded_units
                . ' residual_chinese_units=' . (int) $this->residual_units);
        }
        return $results;
    }

    public function stats() {
        return ['requests' => $this->request_count, 'characters' => $this->characters];
    }

    // ------------------------------------------------------------------
    // batching (same shape as the DeepL adapter)
    // ------------------------------------------------------------------

    private function make_batches($prepared_units) {
        $batches = [];
        $current = [];
        $chars = 0;
        foreach ((array) $prepared_units as $prepared) {
            $unit_chars = (int) mb_strlen((string) ($prepared['unit']['source_text'] ?? ''));
            if ($unit_chars > self::MAX_CHARS_PER_REQUEST) {
                return new WP_Error('v2_unit_too_large', '单个翻译单元超过 Google 翻译单次请求上限');
            }
            if (!empty($current)
                && (count($current) >= self::MAX_TEXTS || ($chars + $unit_chars) > self::MAX_CHARS_PER_REQUEST)) {
                $batches[] = $current;
                $current = [];
                $chars = 0;
            }
            $current[] = $prepared;
            $chars += $unit_chars;
        }
        if (!empty($current)) $batches[] = $current;
        return $batches;
    }

    // ------------------------------------------------------------------
    // one batch: request -> existing restore() -> existing validation
    // ------------------------------------------------------------------

    private function request($batch, $post_id, $batch_index) {
        $batch = array_values((array) $batch);
        $inputs = [];
        foreach ($batch as $prepared) $inputs[] = (string) $prepared['xml'];

        $translations = $this->google_request($inputs, $post_id, $batch_index);
        if (is_wp_error($translations)) return $translations;
        if (count($translations) !== count($batch)) {
            return new WP_Error('v2_response_count', 'Google 翻译返回的译文数量与请求不一致');
        }

        $results = [];
        foreach ($batch as $index => $prepared) {
            $unit_key = (string) ($prepared['unit']['unit_key'] ?? '');
            $text = (string) ($translations[$index] ?? '');
            $restored = $text === ''
                ? new WP_Error('v2_empty_unit', 'Google 翻译返回了空翻译单元')
                : Lisun_Manual_V2_Unit_Transport::restore($prepared, $text);
            if (!is_wp_error($restored)) {
                $restored = $this->validate_visible_unit($prepared, $restored);
            }
            if (is_wp_error($restored)) {
                $code = $restored->get_error_code();
                self::log('V2 Google validation failed post=' . (int) $post_id
                    . ' batch=' . (int) $batch_index
                    . ' unit=' . $unit_key
                    . ' code=' . $code
                    . ' message=' . $restored->get_error_message());
                return new WP_Error(
                    $code,
                    $restored->get_error_message(),
                    ['unit_key' => $unit_key, 'batch_index' => (int) $batch_index]
                );
            }
            if (($restored['anchor_status'] ?? 'complete') === 'degraded') {
                $this->degraded_units++;
                self::log('V2 Google degraded unit post=' . (int) $post_id
                    . ' batch=' . (int) $batch_index
                    . ' unit=' . $unit_key
                    . ' missing=' . implode(',', (array) ($restored['missing_occurrence_ids'] ?? []))
                    . ' missing_formats=' . implode(',', (array) ($restored['missing_format_ids'] ?? []))
                    . ' reason=' . (string) ($restored['fallback_reason'] ?? 'missing_anchor')
                    . ' action=save_snapshot_degraded_plain_translation');
            }
            $chinese = $this->han_count($this->visible_text($restored));
            if ($chinese > 0) {
                $this->residual_units++;
                self::log('V2 Google residual chinese post=' . (int) $post_id
                    . ' unit=' . $unit_key
                    . ' chars=' . $chinese
                    . ' action=needs_manual_review');
            }
            $results[$unit_key] = $restored;
        }

        self::log('V2 Google response post=' . (int) $post_id
            . ' batch=' . (int) $batch_index
            . ' units=' . count($results));

        return $results;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    /**
     * @return array|WP_Error List of decoded translated unit markup.
     * Protected so a test double can stub the HTTP layer without live credentials.
     */
    protected function google_request($texts, $post_id = 0, $batch_index = 0) {
        if ((string) get_option('lisun_google_mode', 'v3_glossary') === 'v2_plain') {
            return $this->request_v2_api_key($texts, $post_id, $batch_index);
        }
        return $this->request_v3_service_account($texts, $post_id, $batch_index);
    }

    /**
     * Cloud Translation v3 via service account, optionally with a glossary.
     * The unit XML is sent as text/html; term enforcement comes from the Google
     * glossary, exactly like the DeepL path relies on its own glossary.
     */
    private function request_v3_service_account($texts, $post_id = 0, $batch_index = 0) {
        $glossary = '';
        if (Lisun_Manual_V2_Google_Glossary::enabled()
            && class_exists('Lisun_Manual_Glossary')
            && Lisun_Manual_Glossary::is_enabled()) {
            // 文章级筛选后的子集：Google 术语库同样只包含本篇勾选的术语，
            // 未勾选的术语按普通文本翻译，不再被全库术语表强制改写。
            $glossary = Lisun_Manual_V2_Google_Glossary::ensure(
                $this->terms !== null ? $this->terms : Lisun_Manual_Glossary::get_all()
            );
            if (is_wp_error($glossary)) return $glossary;
        }

        $body = [
            'contents'           => array_values((array) $texts),
            'sourceLanguageCode' => $this->google_lang((string) get_option('lisun_translate_source_lang', 'zh'), 'zh-CN'),
            'targetLanguageCode' => $this->google_lang((string) get_option('lisun_translate_target_lang', 'en'), 'en'),
            // Unknown inline tags of the unit XML survive, text nodes translate.
            'mimeType'           => 'text/html',
        ];
        if ($glossary !== '') {
            $body['glossaryConfig'] = ['glossary' => $glossary];
        }

        foreach ((array) $texts as $text) $this->characters += mb_strlen((string) $text);
        $this->request_count++;

        $result = Lisun_Manual_Google_Auth::api(
            Lisun_Manual_Google_Auth::translate_endpoint(),
            'POST',
            $body,
            120
        );
        if (is_wp_error($result)) {
            self::log('V2 Google v3 error post=' . (int) $post_id
                . ' batch=' . (int) $batch_index
                . ' reason=' . $result->get_error_message());
            return $result;
        }

        $plain = (array) ($result['data']['translations'] ?? []);
        $gloss = (array) ($result['data']['glossaryTranslations'] ?? []);

        $out = [];
        foreach (array_values((array) $texts) as $index => $unused) {
            // Prefer the glossary-applied variant when Google returns one.
            $text = (string) ($gloss[$index]['translatedText'] ?? '');
            if ($text === '') $text = (string) ($plain[$index]['translatedText'] ?? '');
            $out[] = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        self::log('V2 Google v3 response post=' . (int) $post_id
            . ' batch=' . (int) $batch_index
            . ' q=' . count((array) $texts)
            . ' returned=' . count($out)
            . ' glossary=' . ($glossary === '' ? 'none' : basename(str_replace('\\', '/', $glossary))));

        return $out;
    }

    /** Cloud Translation Basic v2 via API key (no glossary support). */
    private function request_v2_api_key($texts, $post_id = 0, $batch_index = 0) {
        $api_key  = trim((string) get_option('lisun_google_key', ''));
        $endpoint = trim((string) get_option('lisun_google_endpoint', ''));
        if ($endpoint === '') $endpoint = self::DEFAULT_ENDPOINT;
        if ($api_key === '') {
            return new WP_Error('google_no_key', 'Google 翻译未配置 API Key（设置 → 翻译 API 设置 → Google 翻译 API Key）');
        }

        $body = [
            'q'      => array_values((array) $texts),
            'source' => $this->google_lang((string) get_option('lisun_translate_source_lang', 'zh'), 'zh-CN'),
            'target' => $this->google_lang((string) get_option('lisun_translate_target_lang', 'en'), 'en'),
            // The unit XML is HTML-ish: Google keeps unknown inline tags and only
            // translates text nodes, which is exactly what restore() expects.
            'format' => 'html',
        ];

        $url = $endpoint;
        if (strpos($url, 'key=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'key=' . rawurlencode($api_key);
        }

        foreach ((array) $texts as $text) $this->characters += mb_strlen((string) $text);
        $this->request_count++;

        $args = [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];

        // Same optional local proxy the DeepL path uses; Google is not reachable
        // from a mainland China server without it.
        $proxy = '';
        if (get_option('lisun_deepl_proxy_enabled', false)) {
            $proxy = trim((string) get_option('lisun_deepl_proxy_url', 'http://127.0.0.1:7890'));
        }
        $proxy_filter = null;
        if ($proxy !== '' && function_exists('curl_init')) {
            $proxy_host = (string) wp_parse_url($url, PHP_URL_HOST);
            $proxy_filter = static function ($handle, $request, $request_url) use ($proxy, $proxy_host) {
                if ((string) wp_parse_url($request_url, PHP_URL_HOST) !== $proxy_host) return $handle;
                if (defined('CURLOPT_PROXY')) curl_setopt($handle, CURLOPT_PROXY, $proxy);
                return $handle;
            };
            add_filter('http_api_curl', $proxy_filter, 10, 3);
        }

        $response = wp_remote_post($url, $args);

        if ($proxy_filter !== null) remove_filter('http_api_curl', $proxy_filter, 10);

        if (is_wp_error($response)) {
            self::log('V2 Google http error post=' . (int) $post_id . ' message=' . $response->get_error_message());
            return new WP_Error('google_http', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];

        if ($code < 200 || $code >= 300) {
            $message = isset($data['error']['message']) ? (string) $data['error']['message'] : ('HTTP ' . $code);
            self::log('V2 Google api error post=' . (int) $post_id . ' http=' . $code . ' message=' . $message);
            return new WP_Error('google_api_error', $message, ['http_code' => $code]);
        }

        $out = [];
        foreach ((array) ($data['data']['translations'] ?? []) as $item) {
            $out[] = html_entity_decode(
                (string) ($item['translatedText'] ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        self::log('V2 Google request done post=' . (int) $post_id
            . ' batch=' . (int) $batch_index
            . ' q=' . count((array) $texts)
            . ' returned=' . count($out)
            . ' http=' . $code);

        return $out;
    }

    /** Map the plugin language codes onto Google's codes. */
    private function google_lang($code, $default) {
        $code = strtolower(trim((string) $code));
        if ($code === '') return $default;
        $map = [
            'zh'    => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'zh-tw' => 'zh-TW',
            'en'    => 'en',
            'en-gb' => 'en',
            'en-us' => 'en',
            'ja'    => 'ja',
            'ko'    => 'ko',
            'de'    => 'de',
            'fr'    => 'fr',
            'es'    => 'es',
            'ru'    => 'ru',
        ];
        return $map[$code] ?? $code;
    }

    // ------------------------------------------------------------------
    // helpers (same rules as the DeepL adapter)
    // ------------------------------------------------------------------

    private function visible_text($restored) {
        $template = (string) ($restored['target_template'] ?? '');
        $slots = [];
        foreach ((array) ($restored['occurrences'] ?? []) as $occurrence) {
            $slots['{{LISUN_TERM:' . $occurrence['occurrence_id'] . '}}'] =
                (string) ($occurrence['translated_text'] ?? '');
        }
        if (!empty($slots)) $template = strtr($template, $slots);
        return (string) html_entity_decode(wp_strip_all_tags($template), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function validate_visible_unit($prepared, $restored) {
        if (is_wp_error($restored)) return $restored;
        $source = trim((string) ($prepared['unit']['source_text'] ?? ''));
        $visible = trim($this->visible_text($restored));
        $source_chars = mb_strlen(preg_replace('/\s+/u', '', $source));
        $visible_chars = mb_strlen(preg_replace('/\s+/u', '', $visible));
        if ($source_chars > 0 && $visible_chars === 0) {
            return new WP_Error('v2_empty_unit', 'Google 翻译返回了空的可见译文单元');
        }
        if ($source_chars >= 20 && ($visible_chars / $source_chars) < 0.20) {
            return new WP_Error('v2_incomplete_unit', 'Google 翻译返回的可见译文单元严重缺失');
        }
        return $restored;
    }

    private function han_count($text) {
        return (int) preg_match_all('/[\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}]/u', (string) $text);
    }

    private static function log($message) {
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log((string) $message);
        }
    }
}
