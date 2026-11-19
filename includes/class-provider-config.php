<?php
/** Provider-specific translation credentials and one-time legacy migration. */

if (!defined('ABSPATH')) exit;

class Lisun_Manual_Provider_Config {
    const MIGRATION_OPTION = 'lisun_provider_keys_migrated_v1';

    private static $key_options = [
        'deepseek'   => 'lisun_deepseek_api_key',
        'openai'     => 'lisun_openai_api_key',
        'gemini'     => 'lisun_gemini_api_key',
        'openrouter' => 'lisun_openrouter_api_key',
        'doubao'     => 'lisun_doubao_key',
        'deepl'      => 'lisun_deepl_key',
        'google'     => 'lisun_google_key',
    ];

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings'], 30);
        add_action('admin_footer', [__CLASS__, 'hide_legacy_fields']);
    }

    public static function register_settings() {
        $group = 'lisun_manual_settings_group';
        $page = 'lisun_manual_settings';
        $labels = [
            'deepseek' => 'DeepSeek API Key',
            'openai' => 'OpenAI API Key',
            'gemini' => 'Gemini API Key',
            'openrouter' => 'OpenRouter API Key',
            'deepl' => 'DeepL API Key',
        ];
        foreach ($labels as $provider => $label) {
            $option = self::key_option($provider);
            register_setting($group, $option, [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]);
            add_settings_field(
                $option,
                $label,
                [__CLASS__, 'render_password_field'],
                $page,
                'lisun_deepseek',
                ['option' => $option]
            );
        }
    }

    public static function render_password_field($args) {
        $option = (string) ($args['option'] ?? '');
        printf(
            '<input type="password" class="regular-text" name="%1$s" value="%2$s" autocomplete="new-password">',
            esc_attr($option),
            esc_attr((string) get_option($option, ''))
        );
    }

    public static function hide_legacy_fields() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, 'lisun-manual-settings') === false) return;
        ?>
        <script>
        jQuery(function ($) {
            var keyFields = {
                deepseek: 'lisun_deepseek_api_key',
                doubao: 'lisun_doubao_key',
                openai: 'lisun_openai_api_key',
                gemini: 'lisun_gemini_api_key',
                openrouter: 'lisun_openrouter_api_key',
                deepl: 'lisun_deepl_key'
            };
            function row(name) {
                return $('[name="' + name + '"]').first().closest('tr');
            }
            function rows(names) {
                var result = $();
                $.each(names, function (_, name) { result = result.add(row(name)); });
                return result;
            }
            var providerKeyRows = rows(Object.keys(keyFields).map(function (p) { return keyFields[p]; }));
            var commonLlmRows = rows([
                'lisun_deepseek_temperature', 'lisun_deepseek_max_tokens', 'lisun_deepseek_timeout'
            ]);
            var genericLlmRows = rows(['lisun_llm_api_url', 'lisun_deepseek_model']);
            var relayRows = rows(['lisun_relay_url', 'lisun_relay_secret']);
            var doubaoRows = rows(['lisun_doubao_key', 'lisun_doubao_model', 'lisun_doubao_endpoint']);
            var deeplRows = rows(['lisun_deepl_pro', 'lisun_deepl_formality']);
            var legacyWorkflowRows = rows([
                'lisun_term_anchors_enabled', 'lisun_translation_engine_v2', 'lisun_deepl_trace_enabled'
            ]);

            row('lisun_deepseek_key').hide();
            row('lisun_doubao_enabled').hide();

            function refreshProviderFields() {
                var provider = $('select[name="lisun_llm_provider"]').val() || 'deepseek';
                var isLlm = $.inArray(provider, ['deepseek', 'doubao', 'openai', 'gemini', 'openrouter', 'custom']) !== -1;
                providerKeyRows.hide();
                doubaoRows.hide();
                deeplRows.hide();
                legacyWorkflowRows.hide();
                commonLlmRows.toggle(isLlm);
                genericLlmRows.toggle(isLlm && provider !== 'doubao');
                relayRows.toggle($.inArray(provider, ['openai', 'gemini', 'openrouter', 'custom']) !== -1);
                if (provider === 'doubao') {
                    row('lisun_doubao_key').show();
                    row('lisun_doubao_endpoint').show();
                    row('lisun_doubao_model').toggle(!$.trim($('[name="lisun_doubao_endpoint"]').val()));
                }

                if (keyFields[provider]) row(keyFields[provider]).show();
                if (provider === 'deepl') {
                    deeplRows.show();
                    legacyWorkflowRows.show();
                }

                var glossaryHelp = provider === 'deepl'
                    ? '翻译时使用本篇筛选后的术语，并同步为 DeepL Glossary。'
                    : provider === 'google'
                        ? '翻译时使用本篇筛选后的术语，并同步为 Google 术语表。'
                        : '翻译时把本篇筛选后的产品名称和专业术语作为强制规则交给当前 LLM；关闭后不向模型提供术语约束。';
                row('lisun_glossary_enabled').find('.description').text(glossaryHelp);
            }

            $('select[name="lisun_llm_provider"]').on('change.lisunProviderUi', refreshProviderFields);
            refreshProviderFields();
        });
        </script>
        <?php
    }

    public static function key_option($provider) {
        return self::$key_options[(string) $provider] ?? '';
    }

    public static function api_key($provider) {
        $provider = (string) $provider;
        $option = self::key_option($provider);
        $key = $option !== '' ? trim((string) get_option($option, '')) : '';
        if ($key !== '') return $key;
        $selected = (string) get_option('lisun_llm_provider', 'deepseek');
        if ($provider === $selected && $provider !== 'google') {
            return trim((string) get_option('lisun_deepseek_key', ''));
        }
        return '';
    }

    public static function migrate_legacy_key_once() {
        if (get_option(self::MIGRATION_OPTION, false)) return;
        $provider = (string) get_option('lisun_llm_provider', 'deepseek');
        $legacy = trim((string) get_option('lisun_deepseek_key', ''));
        $target = self::key_option($provider);
        if ($legacy !== '' && $target !== '' && $provider !== 'google'
            && trim((string) get_option($target, '')) === '') {
            update_option($target, $legacy, false);
        }
        update_option(self::MIGRATION_OPTION, 1, false);
    }
}
