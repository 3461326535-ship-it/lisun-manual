<?php
/** Isolated multi-provider translation comparison. */
if (!defined('ABSPATH')) exit;

class Lisun_Manual_V2_LLM_Comparison {
    const META_SOURCE = '_lisun_llm_compare_source_id';
    const META_PROVIDER = '_lisun_llm_compare_provider';
    const META_MODEL = '_lisun_llm_compare_model';
    const META_METRICS = '_lisun_llm_compare_metrics';
    const META_SHADOW = '_lisun_llm_compare_shadow';

    /** [修改时间：2026-11-23 +08:00] 同文、同术语、同校验分别运行，不绑定或推送正式英文。 */
    public static function run_all($source_id, $providers = ['deepseek', 'doubao', 'gemini']) {
        $results = [];
        foreach ((array) $providers as $provider) {
            $results[(string) $provider] = self::run_provider((int) $source_id, (string) $provider);
        }
        return $results;
    }

    public static function run_provider($source_id, $provider) {
        $source = get_post((int) $source_id);
        if (!$source || $source->post_type !== 'manual') {
            return new WP_Error('llm_compare_source_missing', '找不到待测试的中文说明书。');
        }
        if (!in_array($provider, ['deepseek', 'doubao', 'gemini'], true)) {
            return new WP_Error('llm_compare_provider_invalid', '不支持的对比模型：' . $provider);
        }
        $config = self::provider_config($provider);
        if (is_wp_error($config)) return $config;
        $terms = Lisun_Manual_Glossary::is_enabled()
            ? Lisun_Manual_Glossary::terms_for_post($source->ID)
            : [];
        $shadow_id = self::comparison_post($source, $provider, true);
        if (is_wp_error($shadow_id)) return $shadow_id;

        $started = microtime(true);
        $adapter = new Lisun_Manual_V2_LLM_Lab_Adapter($terms, $config);
        $translated = Lisun_Manual_V2_Runner::translate_document(
            $shadow_id,
            (string) $source->post_content,
            (string) $source->post_title,
            true,
            [],
            ['terms' => $terms, 'adapter' => $adapter]
        );
        if (is_wp_error($translated)) return $translated;

        $elapsed = round(microtime(true) - $started, 3);
        $metrics = [
            'provider' => $provider,
            'model' => $config['model'],
            'duration_seconds' => $elapsed,
            'terms' => count($terms),
            'units' => (int) ($translated['units'] ?? 0),
            'requests' => (int) ($translated['requests'] ?? 0),
            'characters' => (int) ($translated['characters'] ?? 0),
            'degraded_units' => (int) ($translated['degraded_units'] ?? 0),
            'term_anomaly_units' => (int) ($translated['term_anomaly_units'] ?? 0),
            'created_at' => current_time('mysql'),
        ];
        $result_id = self::comparison_post($source, $provider, false, [
            'post_title' => '[' . self::provider_label($provider) . '] ' . (string) $translated['title'],
            'post_content' => (string) $translated['content'],
        ]);
        if (is_wp_error($result_id)) return $result_id;
        update_post_meta($result_id, self::META_MODEL, (string) $config['model']);
        update_post_meta($result_id, self::META_METRICS, $metrics);

        return [
            'post_id' => (int) $result_id,
            'edit_url' => get_edit_post_link($result_id, 'raw'),
            'preview_url' => get_preview_post_link($result_id),
            'metrics' => $metrics,
        ];
    }

    private static function provider_config($provider) {
        $urls = [
            'deepseek' => 'https://api.deepseek.com/chat/completions',
            'doubao' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
        ];
        $models = [
            'deepseek' => 'deepseek-chat',
            'doubao' => trim((string) get_option('lisun_doubao_endpoint', '')) ?: trim((string) get_option('lisun_doubao_model', 'Doubao-Seed-2.1-pro')),
            'gemini' => 'gemini-3.8-flash',
        ];
        $key = Lisun_Manual_Provider_Config::api_key($provider);
        if ($key === '') return new WP_Error('llm_compare_key_missing', self::provider_label($provider) . ' API Key 未配置。');
        return [
            'provider' => $provider,
            'api_url' => $urls[$provider],
            'model' => $models[$provider],
            'api_key' => $key,
            'temperature' => 0,
            'max_tokens' => max(8192, (int) get_option('lisun_llm_lab_max_tokens', 8192)),
            'timeout' => max(180, (int) get_option('lisun_llm_lab_timeout', 180)),
        ];
    }

    private static function comparison_post($source, $provider, $shadow, $post_fields = []) {
        $query = [
            'post_type' => 'manual', 'post_status' => ['draft', 'private'], 'posts_per_page' => 1,
            'meta_query' => [
                ['key' => self::META_SOURCE, 'value' => (int) $source->ID, 'compare' => '='],
                ['key' => self::META_PROVIDER, 'value' => $provider, 'compare' => '='],
                ['key' => self::META_SHADOW, 'value' => $shadow ? '1' : '0', 'compare' => '='],
            ],
        ];
        $existing = get_posts($query);
        $data = array_merge([
            'ID' => $existing ? (int) $existing[0]->ID : 0,
            'post_type' => 'manual',
            'post_status' => $shadow ? 'draft' : 'private',
            'post_title' => '[LLM Compare ' . self::provider_label($provider) . '] ' . $source->post_title,
            'post_content' => $shadow ? (string) $source->post_content : '',
            'post_author' => (int) ($source->post_author ?: get_current_user_id()),
        ], $post_fields);
        $id = wp_insert_post($data, true);
        if (is_wp_error($id)) return $id;
        update_post_meta($id, '_lisun_manual_lang', $shadow ? 'zh' : 'en');
        update_post_meta($id, self::META_SOURCE, (int) $source->ID);
        update_post_meta($id, self::META_PROVIDER, $provider);
        update_post_meta($id, self::META_SHADOW, $shadow ? '1' : '0');
        return (int) $id;
    }

    private static function provider_label($provider) {
        return ['deepseek' => 'DeepSeek', 'doubao' => 'Doubao', 'gemini' => 'Gemini'][$provider] ?? ucfirst($provider);
    }
}
