<?php
/**
 * LLM-primary experiment adapter for Translation V2.
 *
 * This adapter is deliberately inert unless lisun_llm_lab_enabled is true.
 * It reuses V2 extraction, per-post terms, anchors and restore validation, and
 * never introduces a second glossary store.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_LLM_Lab_Adapter {

    private $terms = [];
    private $request_count = 0;
    private $characters = 0;

    public function __construct($terms = null) {
        $this->terms = is_array($terms) ? array_values($terms) : [];
    }

    public function translate($prepared_units, $post_id = 0, $on_batch = null) {
        $prepared_units = array_values((array) $prepared_units);
        $results = [];
        $total = count($prepared_units);

        // One semantic unit per request for the first experiment. This makes
        // truncation and accidental cross-unit merging observable and retryable.
        foreach ($prepared_units as $index => $prepared) {
            $translated_xml = $this->request_unit($prepared, $post_id, $index);
            if (is_wp_error($translated_xml)) return $translated_xml;

            $restored = Lisun_Manual_V2_Unit_Transport::restore($prepared, $translated_xml);
            if (is_wp_error($restored)) {
                return new WP_Error(
                    'llm_lab_restore_failed',
                    'LLM 返回内容未通过 V2 标签/术语锚点校验：' . $restored->get_error_message(),
                    ['unit_key' => (string) ($prepared['unit']['unit_key'] ?? '')]
                );
            }
            $results[(string) $prepared['unit']['unit_key']] = $restored;
            if (is_callable($on_batch)) {
                call_user_func($on_batch, $index + 1, $total, $index + 1, $total);
            }
        }
        return $results;
    }

    public function stats() {
        return ['requests' => $this->request_count, 'characters' => $this->characters];
    }

    private function request_unit($prepared, $post_id, $index) {
        $config = $this->config();
        if ($config['api_key'] === '') {
            return new WP_Error('llm_lab_key_missing', 'LLM 实验 API Key 未配置');
        }
        if ($config['api_url'] === '' || $config['model'] === '') {
            return new WP_Error('llm_lab_config_missing', 'LLM 实验 API 地址或模型未配置');
        }

        $xml = (string) ($prepared['xml'] ?? '');
        $this->characters += mb_strlen((string) ($prepared['unit']['source_text'] ?? ''));
        $body = [
            'model' => $config['model'],
            'temperature' => $config['temperature'],
            'messages' => [
                ['role' => 'system', 'content' => $this->system_prompt()],
                ['role' => 'user', 'content' => "Translate this single unit. Return only XML.\n\n" . $xml],
            ],
        ];
        if ($config['max_tokens'] > 0) $body['max_tokens'] = $config['max_tokens'];

        $this->request_count++;
        $response = wp_remote_post($config['api_url'], [
            'timeout' => $config['timeout'],
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        if (is_wp_error($response)) return $response;
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            $message = (string) ($data['error']['message'] ?? ('HTTP ' . $status));
            return new WP_Error('llm_lab_http_error', 'LLM 实验请求失败：' . $message);
        }

        $finish = (string) ($data['choices'][0]['finish_reason'] ?? '');
        if ($finish === 'length') {
            return new WP_Error('llm_lab_truncated', 'LLM 返回因长度限制被截断，当前单元未保存');
        }
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        $content = preg_replace('/^```(?:xml)?\s*|\s*```$/i', '', $content);
        $content = trim((string) $content);
        if ($content === '' || strpos($content, '<lisun-unit') === false) {
            return new WP_Error('llm_lab_non_xml', 'LLM 没有返回预期的 lisun-unit XML，当前单元未保存');
        }
        return $content;
    }

    private function system_prompt() {
        $rules = [
            'You translate Chinese technical product manuals into natural, precise US English.',
            'Return only the supplied <lisun-unit> XML. Do not add Markdown, explanations, notes, alternatives, or JSON.',
            'Preserve every element, id, attribute, placeholder, nesting relationship, and count exactly.',
            'Translate only human-readable Chinese text nodes. Keep existing English, model numbers, standards, units, URLs and code unchanged.',
            'Never leave Chinese in the output. Never merge, omit, duplicate, or invent content.',
            'Text inside each <t> element is a terminology occurrence. Translate it using the glossary below and keep the <t> element around the translated words.',
        ];
        $glossary = [];
        foreach ($this->terms as $term) {
            $zh = trim((string) ($term['zh'] ?? ''));
            $en = trim((string) ($term['en'] ?? ''));
            if ($zh !== '' && $en !== '') $glossary[] = $zh . ' => ' . $en;
        }
        return implode("\n", $rules)
            . (empty($glossary) ? '' : "\n\nMandatory glossary for this article:\n" . implode("\n", $glossary));
    }

    private function config() {
        $provider = (string) get_option('lisun_llm_lab_provider', 'deepseek');
        $defaults = [
            'deepseek' => 'https://api.deepseek.com/chat/completions',
            'doubao' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        ];
        $default_models = [
            'deepseek' => 'deepseek-chat',
            'doubao' => (string) get_option('lisun_doubao_model', 'Doubao-Seed-2.1-pro'),
            'openai' => 'gpt-4o',
            'openrouter' => 'openrouter/auto',
            'gemini' => 'gemini-3.6-flash',
        ];
        $api_url = trim((string) get_option('lisun_llm_lab_api_url', ''));
        if ($api_url === '') $api_url = $defaults[$provider] ?? $defaults['deepseek'];
        if ($provider === 'doubao') {
            $endpoint = trim((string) get_option('lisun_doubao_endpoint', ''));
            $api_url = $defaults['doubao'];
        }
        $api_key = class_exists('Lisun_Manual_Provider_Config')
            ? Lisun_Manual_Provider_Config::api_key($provider)
            : '';
        // Lab-only compatibility for configurations created before the
        // provider-specific credential layer existed.
        if ($api_key === '') {
            $api_key = trim((string) get_option('lisun_llm_lab_api_key', ''));
        }
        return [
            'api_url' => $api_url,
            'api_key' => $api_key,
            'model' => $provider === 'doubao' && $endpoint !== ''
                ? $endpoint
                : trim((string) get_option('lisun_llm_lab_model', $default_models[$provider] ?? '')),
            'temperature' => (float) get_option('lisun_llm_lab_temperature', 0),
            'max_tokens' => max(0, (int) get_option('lisun_llm_lab_max_tokens', 8192)),
            'timeout' => max(30, (int) get_option('lisun_llm_lab_timeout', 180)),
        ];
    }
}
