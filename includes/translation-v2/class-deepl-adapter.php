<?php
/** DeepL batching and unit validation for Translation V2. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_DeepL_Adapter {

    const MAX_TEXTS = 50;
    const MAX_REQUEST_BYTES = 100000;
    private $request_count = 0;
    private $characters = 0;
    private $terms = null;

    /** @param array|null $terms 本次翻译生效的术语子集（文章级筛选结果）。 */
    public function __construct($terms = null) {
        $this->terms = is_array($terms) ? $terms : null;
    }

    public function translate($prepared_units, $post_id = 0, $on_batch = null) {
        // 同一个固定 glossary_id 被所有文章共享：翻译开始前取得全局互斥锁，
        // 覆盖术语表 → 翻译全部批次 → 释放锁，避免两个文章交叉污染术语表。
        $lock_held = false;
        if (Lisun_Manual_Glossary::is_enabled() && !empty($this->terms)) {
            $lock_held = Lisun_Manual_Glossary::acquire_translate_glossary_lock();
            if (!$lock_held) {
                return new WP_Error('deepl_glossary_lock_busy', '另一个翻译任务正在使用 DeepL 术语表，请稍后重试');
            }
        }
        try {
            $batches = $this->make_batches($prepared_units);
            if (is_wp_error($batches)) return $batches;
            $results = [];
            $completed = 0;
            $total_units = count((array) $prepared_units);
            $total_batches = count($batches);
            foreach ($batches as $batch_index => $batch) {
                $response = $this->request($batch, $post_id, $batch_index);
                if (is_wp_error($response)) return $response;
                foreach ($response as $unit_key => $result) $results[$unit_key] = $result;
                $completed += count($batch);
                if (is_callable($on_batch)) {
                    call_user_func($on_batch, $completed, $total_units, $batch_index + 1, $total_batches);
                }
            }
            return $results;
        } finally {
            if ($lock_held) {
                Lisun_Manual_Glossary::release_translate_glossary_lock();
            }
        }
    }

    public function stats() {
        return ['requests' => $this->request_count, 'characters' => $this->characters];
    }

    private function make_batches($prepared_units) {
        $batches = [];
        $current = [];
        foreach ((array) $prepared_units as $prepared) {
            // A semantic unit is atomic. It must be rejected before any HTTP
            // request if its own safe JSON payload would exceed the limit.
            if ($this->request_size([$prepared]) > self::MAX_REQUEST_BYTES) {
                return new WP_Error('v2_unit_too_large', '单个 V2 翻译单元超过 DeepL 安全请求上限');
            }
            $candidate = array_merge($current, [$prepared]);
            $bytes = $this->request_size($candidate);
            if (!empty($current) && (count($candidate) > self::MAX_TEXTS || $bytes > self::MAX_REQUEST_BYTES)) {
                $batches[] = $current;
                $current = [$prepared];
            } else {
                $current = $candidate;
            }
        }
        if (!empty($current)) $batches[] = $current;
        return $batches;
    }

    private function request_size($batch) {
        return strlen((string) wp_json_encode($this->build_body($batch), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function build_body($batch) {
        $body = [
            'text' => array_values(array_map(function ($prepared) {
                return (string) $prepared['xml'];
            }, (array) $batch)),
            'source_lang' => 'ZH',
            'target_lang' => 'EN-US',
            'formality' => get_option('lisun_deepl_formality', 'default'),
            'tag_handling' => 'xml',
            'tag_handling_version' => 'v2',
            'outline_detection' => false,
            'split_sentences' => 'nonewlines',
            // t carries a translatable Chinese term occurrence. It must not
            // be ignored, otherwise DeepL would return the Chinese source.
            'non_splitting_tags' => ['f', 't', 'ph'],
            // ph is an opaque formatting placeholder; t remains translatable.
            'ignore_tags' => ['ph'],
        ];
        return $body;
    }

    private function request($batch, $post_id, $batch_index) {
        $body = $this->build_body($batch);
        $glossary_id = Lisun_Manual_Glossary::is_enabled()
            ? Lisun_Manual_Glossary::ensure_deepl_glossary(false, $this->terms)
            : '';
        if (is_wp_error($glossary_id)) return $glossary_id;
        if ($glossary_id !== '') $body['glossary_id'] = $glossary_id;

        foreach ($batch as $prepared) {
            $this->characters += mb_strlen((string) ($prepared['unit']['source_text'] ?? ''));
        }
        $this->request_count++;
        self::log('V2 DeepL request post=' . (int) $post_id
            . ' batch=' . (int) $batch_index
            . ' units=' . count($batch)
            . ' bytes=' . $this->request_size($batch)
            . ' occurrences=' . array_sum(array_map(function ($item) {
                return count((array) ($item['occurrences'] ?? []));
            }, $batch)));
        $response = Lisun_Manual_Glossary::deepl_api_request('/v2/translate', 'POST', $body, [
            'post_id' => (int) $post_id,
            'index' => (int) $batch_index,
            'force_trace' => true,
        ]);
        if (is_wp_error($response)) return $response;
        $trace_id = (string) ($response['trace_id'] ?? '');
        $translations = (array) ($response['data']['translations'] ?? []);
        if (count($translations) !== count($batch)) {
            return new WP_Error('v2_response_count', 'DeepL 返回的翻译单元数量与请求不一致');
        }

        $results = [];
        foreach (array_values($batch) as $index => $prepared) {
            $text = (string) ($translations[$index]['text'] ?? '');
            $restored = $text === ''
                ? new WP_Error('v2_empty_unit', 'DeepL 返回了空翻译单元')
                : Lisun_Manual_V2_Unit_Transport::restore($prepared, $text);
            if (!is_wp_error($restored)) {
                $restored = $this->validate_visible_unit($prepared, $restored);
            }
            if (is_wp_error($restored)) {
                $error_data = $restored->get_error_data($restored->get_error_code());
                $error_detail = is_array($error_data)
                    ? ' data=' . wp_json_encode($error_data, JSON_UNESCAPED_UNICODE)
                    : '';
                self::log('V2 DeepL validation failed post=' . (int) $post_id
                    . ' batch=' . (int) $batch_index
                    . ' unit=' . (string) $prepared['unit']['unit_key']
                    . ' code=' . $restored->get_error_code()
                    . $error_detail
                    . ' trace=' . $trace_id);
                $suffix = $trace_id !== '' ? ' 原始报文追踪 ID：' . $trace_id : '';
                $wrapped_error_data = [
                    'trace_id' => $trace_id,
                    'unit_key' => (string) ($prepared['unit']['unit_key'] ?? ''),
                    'batch_index' => (int) $batch_index,
                ];
                $original_error_data = $restored->get_error_data($restored->get_error_code());
                if (is_array($original_error_data)) {
                    $wrapped_error_data = array_merge($wrapped_error_data, $original_error_data);
                }
                return new WP_Error(
                    $restored->get_error_code(),
                    $restored->get_error_message() . $suffix,
                    $wrapped_error_data
                );
            }
            if (($restored['anchor_status'] ?? 'complete') === 'degraded') {
                self::log('V2 DeepL degraded unit post=' . (int) $post_id
                    . ' batch=' . (int) $batch_index
                    . ' unit=' . (string) $prepared['unit']['unit_key']
                    . ' missing=' . implode(',', (array) ($restored['missing_occurrence_ids'] ?? []))
                    . ' missing_formats=' . implode(',', (array) ($restored['missing_format_ids'] ?? []))
                    . ' reason=' . (string) ($restored['fallback_reason'] ?? 'missing_anchor')
                    . ' action=save_snapshot_degraded_plain_translation'
                    . ' trace=' . $trace_id);
            }
            $results[$prepared['unit']['unit_key']] = $restored;
        }
        self::log('V2 DeepL response post=' . (int) $post_id
            . ' batch=' . (int) $batch_index
            . ' units=' . count($results)
            . ' trace=' . $trace_id);
        return $results;
    }

    private function validate_visible_unit($prepared, $restored) {
        $source = trim((string) ($prepared['unit']['source_text'] ?? ''));
        $template = (string) ($restored['target_template'] ?? '');
        $fallbacks = [];
        foreach ((array) ($restored['occurrences'] ?? []) as $occurrence) {
            $fallbacks['{{LISUN_TERM:' . $occurrence['occurrence_id'] . '}}'] = (string) ($occurrence['translated_text'] ?? '');
        }
        $visible = trim(html_entity_decode(
            wp_strip_all_tags(strtr($template, $fallbacks)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
        $source_chars = mb_strlen(preg_replace('/\s+/u', '', $source));
        $visible_chars = mb_strlen(preg_replace('/\s+/u', '', $visible));
        if ($source_chars > 0 && $visible_chars === 0) {
            return new WP_Error('v2_empty_unit', 'DeepL 返回了空的可见译文单元');
        }
        if ($source_chars >= 20 && ($visible_chars / $source_chars) < 0.20) {
            return new WP_Error('v2_incomplete_unit', 'DeepL 返回的可见译文单元严重缺失');
        }
        return $restored;
    }

    private static function log($message) {
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log((string) $message);
        }
    }
}
