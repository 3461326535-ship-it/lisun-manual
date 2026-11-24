<?php
/** LLM-primary adapter for the existing Translation V2 pipeline. */
if (!defined('ABSPATH')) exit;

class Lisun_Manual_V2_LLM_Lab_Adapter {
    private $terms = [];
    private $request_count = 0;
    private $characters = 0;
    private $split_retries = 0;
    private $corrective_retries = 0;
    private $max_batch_units = 10;
    private $max_batch_xml_chars = 14000;
    private $concurrency = 3;
    private $config_override = [];

    /** [修改时间：2026-11-23 +08:00] 允许隔离测试显式指定提供商，避免改写线上全局设置。 */
    public function __construct($terms = null, $config_override = []) {
        $this->terms = is_array($terms) ? array_values($terms) : [];
        $this->config_override = is_array($config_override) ? $config_override : [];
    }

    public function translate($prepared_units, $post_id = 0, $on_batch = null) {
        $prepared_units = array_values((array) $prepared_units);
        $results = [];
        $total = count($prepared_units);
        $batches = $this->make_batches($prepared_units);
        $completed = 0;
        foreach (array_chunk($batches, $this->concurrency, true) as $batch_group) {
            $attempts = $this->request_batch_group($batch_group, $post_id);
            foreach ($batch_group as $batch_index => $batch) {
                $translated = $this->resolve_batch_result(
                    $batch,
                    $post_id,
                    (string) $batch_index,
                    $attempts[$batch_index] ?? new WP_Error('llm_lab_transport_error', 'LLM 并发请求没有返回结果')
                );
                if (is_wp_error($translated)) return $translated;
                $results = array_merge($results, $translated);
                $completed += count($batch);
                if (is_callable($on_batch)) call_user_func($on_batch, $completed, $total, $batch_index + 1, count($batches));
            }
        }
        return $results;
    }

    /** [修改时间：2026-11-20 +08:00] 首轮批次使用三路有限并发；异常批次仍走原有拆分重试。 */
    private function request_batch_group($batch_group, $post_id) {
        if (count($batch_group) <= 1 || !function_exists('curl_multi_init')) {
            $results = [];
            foreach ($batch_group as $batch_index => $batch) {
                $results[$batch_index] = $this->request_batch($batch, $post_id, (string) $batch_index);
            }
            return $results;
        }
        $multi = curl_multi_init();
        $handles = [];
        $results = [];
        foreach ($batch_group as $batch_index => $batch) {
            $request = $this->build_request($batch, $post_id, (string) $batch_index, false);
            if (is_wp_error($request)) {
                $results[$batch_index] = $request;
                continue;
            }
            $handle = curl_init($request['url']);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => $request['headers'],
                CURLOPT_POSTFIELDS => $request['body'],
                CURLOPT_CONNECTTIMEOUT => min(20, $request['timeout']),
                CURLOPT_TIMEOUT => $request['timeout'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($request['proxy_url'] !== '') curl_setopt($handle, CURLOPT_PROXY, $request['proxy_url']);
            curl_multi_add_handle($multi, $handle);
            $handles[$batch_index] = $handle;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $batch_index => $handle) {
            $body = (string) curl_multi_getcontent($handle);
            $error = curl_error($handle);
            $status_code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $results[$batch_index] = $error !== ''
                ? new WP_Error('llm_lab_transport_error', $error)
                : $this->parse_response($batch_group[$batch_index], $status_code, $body);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return $results;
    }

    private function resolve_batch_result($batch, $post_id, $batch_id, $translated, $corrective_retry = false) {
        if (!is_wp_error($translated)) return $translated;
        if (count($batch) === 1 && $translated->get_error_code() === 'llm_lab_language_validation' && !$corrective_retry) {
            $this->corrective_retries++;
            return $this->translate_batch_with_split($batch, $post_id, $batch_id . 'r', true);
        }
        if (count($batch) <= 1 || !in_array($translated->get_error_code(), [
            'llm_lab_truncated', 'llm_lab_non_xml', 'llm_lab_batch_mismatch',
            'llm_lab_restore_failed', 'llm_lab_language_validation'
        ], true)) return $translated;
        $this->split_retries++;
        $middle = (int) ceil(count($batch) / 2);
        $left = $this->translate_batch_with_split(array_slice($batch, 0, $middle), $post_id, $batch_id . 'a');
        if (is_wp_error($left)) return $left;
        $right = $this->translate_batch_with_split(array_slice($batch, $middle), $post_id, $batch_id . 'b');
        return is_wp_error($right) ? $right : array_merge($left, $right);
    }

    public function stats() {
        return [
            'requests' => $this->request_count,
            'characters' => $this->characters,
            'split_retries' => $this->split_retries,
            'corrective_retries' => $this->corrective_retries,
        ];
    }

    private function make_batches($prepared_units) {
        $batches = []; $current = []; $chars = 0;
        foreach ($prepared_units as $prepared) {
            $size = mb_strlen((string) ($prepared['xml'] ?? ''));
            if ($current && (count($current) >= $this->max_batch_units || $chars + $size > $this->max_batch_xml_chars)) {
                $batches[] = $current; $current = []; $chars = 0;
            }
            $current[] = $prepared; $chars += $size;
        }
        if ($current) $batches[] = $current;
        return $batches;
    }

    private function translate_batch_with_split($batch, $post_id, $batch_id, $corrective_retry = false) {
        $translated = $this->request_batch($batch, $post_id, $batch_id, $corrective_retry);
        return $this->resolve_batch_result($batch, $post_id, $batch_id, $translated, $corrective_retry);
    }

    private function request_batch($batch, $post_id, $batch_id, $corrective_retry = false) {
        $request = $this->build_request($batch, $post_id, $batch_id, $corrective_retry);
        if (is_wp_error($request)) return $request;
        if ($request['proxy_url'] !== '' && function_exists('curl_init')) {
            $handle = curl_init($request['url']);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => $request['headers'],
                CURLOPT_POSTFIELDS => $request['body'],
                CURLOPT_CONNECTTIMEOUT => min(20, $request['timeout']),
                CURLOPT_TIMEOUT => $request['timeout'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => $request['proxy_url'],
            ]);
            $body = (string) curl_exec($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            return $error !== ''
                ? new WP_Error('llm_lab_transport_error', $error)
                : $this->parse_response($batch, $status, $body);
        }
        $response = wp_remote_post($request['url'], ['timeout' => $request['timeout'], 'headers' => [
            'Authorization' => 'Bearer ' . $request['api_key'], 'Content-Type' => 'application/json'
        ], 'body' => $request['body']]);
        if (is_wp_error($response)) return $response;
        return $this->parse_response(
            $batch,
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response)
        );
    }

    private function build_request($batch, $post_id, $batch_id, $corrective_retry = false) {
        $config = $this->config();
        if ($config['api_key'] === '') return new WP_Error('llm_lab_key_missing', 'LLM API Key 未配置');
        if ($config['api_url'] === '' || $config['model'] === '') return new WP_Error('llm_lab_config_missing', 'LLM API 地址或模型未配置');
        $xml = '<lisun-batch id="' . esc_attr((string) $post_id . '-' . $batch_id) . '">';
        foreach ($batch as $prepared) {
            $xml .= (string) ($prepared['xml'] ?? '');
            $this->characters += mb_strlen((string) ($prepared['unit']['source_text'] ?? ''));
        }
        $xml .= '</lisun-batch>';
        $body = ['model' => $config['model'], 'temperature' => $config['temperature'], 'messages' => [
            ['role' => 'system', 'content' => $this->system_prompt($batch)],
            ['role' => 'user', 'content' => ($corrective_retry
                ? "Correct the previous language-validation failure. Do not leave Chinese and do not place suffixes such as s, es, ed, or ing outside a <t> element. Return only the complete lisun-batch XML.\n\n"
                : "Translate every unit in this batch. Return only the complete lisun-batch XML in the original order.\n\n") . $xml],
        ]];
        if ($config['provider'] === 'doubao') $body['thinking'] = ['type' => 'disabled'];
        if ($config['max_tokens'] > 0) $body['max_tokens'] = $config['max_tokens'];
        $this->request_count++;
        return [
            'url' => $config['api_url'],
            'api_key' => $config['api_key'],
            'headers' => ['Authorization: Bearer ' . $config['api_key'], 'Content-Type: application/json'],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'timeout' => $config['timeout'],
            'proxy_url' => $config['proxy_url'],
        ];
    }

    private function parse_response($batch, $status, $raw_body) {
        $data = json_decode((string) $raw_body, true);
        if ($status < 200 || $status >= 300) return new WP_Error('llm_lab_http_error', (string) ($data['error']['message'] ?? ('HTTP ' . $status)));
        if ((string) ($data['choices'][0]['finish_reason'] ?? '') === 'length') return new WP_Error('llm_lab_truncated', 'LLM 输出被截断');
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        $content = trim((string) preg_replace('/^```(?:xml)?\s*|\s*```$/i', '', $content));
        if ($content === '' || strpos($content, '<lisun-unit') === false) return new WP_Error('llm_lab_non_xml', 'LLM 没有返回预期 XML');
        return $this->restore_batch($batch, $content);
    }

    private function restore_batch($batch, $content) {
        if (!class_exists('DOMDocument')) return new WP_Error('llm_lab_non_xml', 'PHP DOM/XML 扩展不可用');
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$ok) return new WP_Error('llm_lab_non_xml', 'LLM 返回了无效 XML');
        $nodes = $dom->getElementsByTagName('lisun-unit');
        if ($nodes->length !== count($batch)) return new WP_Error('llm_lab_batch_mismatch', 'LLM 返回的单元数量不一致');
        $results = [];
        foreach ($batch as $index => $prepared) {
            $node = $nodes->item($index);
            $expected = (string) ($prepared['unit']['unit_key'] ?? '');
            if (!$node || $node->getAttribute('key') !== $expected) return new WP_Error('llm_lab_batch_mismatch', 'LLM 改变了单元顺序或 key');
            $unit_xml = $dom->saveXML($node);
            $language_issue = $this->validate_language_output($unit_xml);
            if (is_wp_error($language_issue)) return $language_issue;
            $restored = Lisun_Manual_V2_Unit_Transport::restore($prepared, $unit_xml);
            if (is_wp_error($restored)) return new WP_Error('llm_lab_restore_failed', 'LLM 输出未通过 V2 校验：' . $restored->get_error_message(), ['unit_key' => $expected]);
            // Term anchoring can rewrite an otherwise valid model response.
            // Validate the actual HTML that will be assembled, not only the
            // transport XML returned by the provider.
            $language_issue = $this->validate_language_output((string) ($restored['target_template'] ?? ''));
            if (is_wp_error($language_issue)) return $language_issue;
            $results[$expected] = $restored;
        }
        return $results;
    }

    private function validate_language_output($unit_xml) {
        if (preg_match('/[\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}]/u', $unit_xml)) {
            return new WP_Error('llm_lab_language_validation', 'LLM 输出仍包含中文');
        }
        if (preg_match('/<\/t>\s*(?:s|es|ed|ing)\b/i', $unit_xml, $match)) {
            return new WP_Error('llm_lab_language_validation', '术语标签外出现游离词尾：' . trim((string) $match[0]));
        }
        // [修改时间：2026-11-24 +08:00] 不在纯文本中猜测游离词尾。
        // “in s”“of S”等合法单位/型号表达会被“英文词 + s”误伤；真正需要阻断的
        // 术语后缀已由上面的 </t> 边界检查精确覆盖。
        return true;
    }

    private function system_prompt($batch) {
        $rules = [
            'You translate Chinese technical product manuals into natural, precise US English.',
            'Return only the supplied <lisun-batch> XML. Do not add Markdown, explanations, notes, alternatives, or JSON.',
            'Preserve every unit, element, key, id, placeholder, nesting relationship, order, and count exactly.',
            'Translate only human-readable Chinese text nodes. Keep existing English, model numbers, standards, units, URLs and code unchanged.',
            'Never leave Chinese in the output. Never merge, omit, duplicate, or invent content.',
            'Text inside each <t> element is a terminology occurrence. Use the mandatory glossary and keep the <t> element and id.',
            'Glossary wording is canonical, but capitalization is grammatical: lowercase common equipment names in running text unless they begin a sentence, occur in a title, or are part of an official product/model name.',
            'Never place suffixes such as s, es, ed, or ing outside a <t> element. If inflection is needed, keep the complete inflected term inside the same <t> element or rewrite the sentence.',
            'In normal technical prose, put a space between a number and an SI unit: 1 m, 300 mm, 40 °C. Preserve model numbers, identifiers, formulas, and strings where spacing is semantically significant.',
            'Wrong: <t id="x">power cord</t> s. Correct: <t id="x">power cords</t>.',
            'Wrong in running text: the <t id="x">Integrating Sphere</t> is closed. Correct: the <t id="x">integrating sphere</t> is closed.',
            'Wrong: diameter 300mm. Correct: diameter 300 mm.',
            'For every <lisun-unit context="heading">, use consistent Title Case for the entire heading, including explanatory text inside parentheses. Keep articles, short prepositions, and conjunctions lowercase unless they are the first or last word.',
            'Use formal technical-manual style. Do not use contractions such as we\'ll, don\'t, or it\'s. Use large/small for equipment sizes, never big.',
            'Translate table-column 说明 as Description when it labels what an item, parameter, or step means. Use Note only for an actual note or caution outside that column-heading role.',
            'Prefer established technical English over word-for-word calques. Examples: 浮尘 means airborne dust or dust accumulation; 手旋螺丝 means thumb screw or hand knob depending on the hardware; 螺丝手柄 means screw knob or fastener knob, not screw handle; choose the natural component name from context.',
            'Preserve chemical formulas and render numeric chemical indices as Unicode subscripts when plain text is used, for example BaSO₄ rather than BaSO4.',
            'Do not introduce doubled spaces or spaces before punctuation.',
        ];
        $glossary = [];
        foreach ($batch as $prepared) foreach ((array) ($prepared['occurrences'] ?? []) as $occurrence) {
            $zh = trim((string) ($occurrence['source_text'] ?? ''));
            $en = trim((string) ($occurrence['translated_text'] ?? ''));
            if ($zh !== '' && $en !== '') $glossary[$zh . "\0" . $en] = $zh . ' => ' . $en;
        }
        return implode("\n", $rules) . (empty($glossary) ? '' : "\n\nMandatory glossary for this batch:\n" . implode("\n", $glossary));
    }

    private function config() {
        $provider = (string) ($this->config_override['provider'] ?? get_option('lisun_llm_lab_provider', 'deepseek'));
        $urls = ['deepseek' => 'https://api.deepseek.com/chat/completions', 'doubao' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions', 'openai' => 'https://api.openai.com/v1/chat/completions', 'openrouter' => 'https://openrouter.ai/api/v1/chat/completions', 'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'];
        $models = ['deepseek' => 'deepseek-chat', 'doubao' => (string) get_option('lisun_doubao_model', 'Doubao-Seed-2.1-pro'), 'openai' => 'gpt-4o', 'openrouter' => 'openrouter/auto', 'gemini' => 'gemini-3.8-flash'];
        $api_url = trim((string) ($this->config_override['api_url'] ?? get_option('lisun_llm_lab_api_url', '')));
        if ($api_url === '') $api_url = $urls[$provider] ?? $urls['deepseek'];
        $endpoint = '';
        if ($provider === 'doubao') { $endpoint = trim((string) get_option('lisun_doubao_endpoint', '')); $api_url = $urls['doubao']; }
        $api_key = trim((string) ($this->config_override['api_key'] ?? ''));
        if ($api_key === '' && class_exists('Lisun_Manual_Provider_Config')) $api_key = Lisun_Manual_Provider_Config::api_key($provider);
        if ($api_key === '') $api_key = trim((string) get_option('lisun_llm_lab_api_key', ''));
        $model = trim((string) ($this->config_override['model'] ?? ''));
        if ($model === '') $model = $provider === 'doubao' && $endpoint !== ''
            ? $endpoint
            : trim((string) get_option('lisun_llm_lab_model', $models[$provider] ?? ''));
        return ['provider' => $provider, 'api_url' => $api_url, 'api_key' => $api_key, 'model' => $model, 'temperature' => (float) ($this->config_override['temperature'] ?? get_option('lisun_llm_lab_temperature', 0)), 'max_tokens' => max(0, (int) ($this->config_override['max_tokens'] ?? get_option('lisun_llm_lab_max_tokens', 8192))), 'timeout' => max(30, (int) ($this->config_override['timeout'] ?? get_option('lisun_llm_lab_timeout', 180))), 'proxy_url' => trim((string) ($this->config_override['proxy_url'] ?? get_option('lisun_llm_proxy_url', '')))];
    }
}
