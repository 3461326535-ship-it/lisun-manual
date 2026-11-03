<?php
/**
 * 系统设置模块
 * 远程站连接、DeepSeek API、爬虫豁免、密码保护
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Settings {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_head', [__CLASS__, 'render_settings_tabs_head']);
        add_action('wp_ajax_lisun_test_connection', [__CLASS__, 'ajax_test_connection']);
        add_action('wp_ajax_lisun_test_deepseek', [__CLASS__, 'ajax_test_deepseek']);

        // REST API 端点：测试 LLM 连接（绕过 admin-ajax.php WAF 拦截）
        add_action('rest_api_init', function () {
            // 与设置页现有 REST 接口在同一处注册，兼容部分站点未加载独立模块 REST 挂载的情况。
            register_rest_route('lisun-manual/v1', '/legacy-image-convert-now', [
                'methods'             => 'POST',
                'callback'            => ['Lisun_Manual_Import_Parser', 'rest_convert_legacy_images'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]);
            register_rest_route('lisun-manual/v1', '/test-llm', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_test_llm'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]);
        });
    }

    /**
     * 在页头先隐藏未完成 Tab 化的设置表，避免页脚脚本初始化前闪现旧版长页面。
     */
    public static function render_settings_tabs_head() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'lisun-manual-settings') === false) return;
        echo '<style id="lisun-settings-tabs-preload-style">
            form[action="options.php"]:not(.lisun-settings-tabs-ready) { visibility: hidden; }
            form[action="options.php"].lisun-settings-tabs-ready { visibility: visible; }
        </style>';
    }

    /**
     * 注册所有设置项
     */
    public static function register_settings() {
        $group = 'lisun_manual_settings_group';
        $page = 'lisun_manual_settings';

        // ===== 远程站连接 =====
        add_settings_section(
            'lisun_en_connection',
            __('远程站连接', 'lisun-manual'),
            function () {
                echo '<p>' . __('配置本地站与远程站的 REST API 通信。需要在远程站 WordPress 后台生成 Application Password。支持双向同步（推送译文、推送/拉取术语库）。', 'lisun-manual') . '</p>';
            },
            $page
        );

        self::add_field('lisun_en_site_url', __('远程站 URL', 'lisun-manual'), 'url', $group, $page, 'lisun_en_connection');
        self::add_field('lisun_en_site_username', __('用户名', 'lisun-manual'), 'text', $group, $page, 'lisun_en_connection');
        self::add_field('lisun_en_site_password', __('Application Password', 'lisun-manual'), 'password', $group, $page, 'lisun_en_connection');

        // 测试连接按钮
        add_action('admin_footer', [__CLASS__, 'render_test_connection_script']);

        // ===== Google Sheets 代理 =====
        add_settings_section(
            'lisun_glossary_proxy',
            __('代理服务', 'lisun-manual'),
            function () {
                echo '<p>' . __('用于 Google Sheets 同步术语、DeepL API 请求，以及可选的 Mihomo 订阅 URL 管理。默认直连；启用后只让指定功能使用代理，不会让整站流量经过代理。', 'lisun-manual') . '</p>';
            },
            $page
        );

        self::add_field('lisun_glossary_proxy_enabled', __('启用 Google Sheets 代理', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_glossary_proxy', [
            'label' => __('同步术语时使用下面的 HTTP 代理', 'lisun-manual'),
        ]);
        self::add_field('lisun_glossary_proxy_url', __('代理地址', 'lisun-manual'), 'url', $group, $page, 'lisun_glossary_proxy', [
            'default'     => 'http://127.0.0.1:7890',
            'description' => __('例如 http://127.0.0.1:7890；不启用代理时不会使用此地址。', 'lisun-manual'),
        ]);
        self::add_field('lisun_deepl_proxy_enabled', __('启用 API 本机网络代理', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_glossary_proxy', [
            'label' => __('DeepL 和 SEO LLM API 请求使用下面的 HTTP 代理；若未启用，则 SEO 会复用上面的 Google Sheets 代理（仅影响这些 API，不影响全站流量）', 'lisun-manual'),
        ]);
        self::add_field('lisun_deepl_proxy_url', __('API 代理地址', 'lisun-manual'), 'url', $group, $page, 'lisun_glossary_proxy', [
            'default'     => 'http://127.0.0.1:7890',
            'description' => __('例如 http://127.0.0.1:7890；配置 Relay 时优先使用 Relay。', 'lisun-manual'),
        ]);

        // ===== 翻译 API =====
        add_settings_section(
            'lisun_deepseek',
            __('翻译 API 设置', 'lisun-manual'),
            function () {
                echo '<p>' . __('选择翻译引擎。专业机翻引擎（DeepL、Google 翻译）走“翻译单元 V2”流程，逐个单元翻译并校验；'
                    . '大模型接口（DeepSeek、OpenAI、Gemini、OpenRouter 等 OpenAI 兼容端点）走提示词流程。', 'lisun-manual') . '</p>';
            },
            $page
        );

        // 提供商选择
        self::add_field('lisun_llm_provider', __('翻译引擎', 'lisun-manual'), 'select', $group, $page, 'lisun_deepseek', [
            'default' => 'deepseek',
            'options' => [
                'deepseek'   => 'DeepSeek (LLM)',
                'deepl'      => 'DeepL (机翻引擎)',
                'google'     => 'Google 翻译 (Cloud Translation，机翻引擎)',
                'openai'     => 'OpenAI (ChatGPT, LLM)',
                'gemini'     => 'Google Gemini (LLM)',
                'openrouter' => 'OpenRouter (LLM)',
                'custom'     => '自定义 (Custom, LLM)',
            ],
            'description' => __('DeepL 与 Google 翻译属于专业机器翻译接口，不是大模型。两者都走 V2 单元流程，并各自同步自己的术语表（DeepL Glossary / Google 术语表）；只有 v2 纯翻译模式不带术语表。', 'lisun-manual'),
        ]);

        self::add_field('lisun_llm_api_url', __('API 端点 URL', 'lisun-manual'), 'url', $group, $page, 'lisun_deepseek', [
            'default'     => 'https://api.deepseek.com/chat/completions',
            'description' => __('OpenAI 兼容的 Chat Completions 端点；可按实际服务手动修改。', 'lisun-manual'),
        ]);

        self::add_field('lisun_deepseek_key', __('API 密钥', 'lisun-manual'), 'password', $group, $page, 'lisun_deepseek');
        self::add_field('lisun_deepseek_model', __('模型名称', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'default'     => 'deepseek-chat',
            'description' => __('例如 deepseek-chat, gpt-4o, gemini-3.6-flash, openrouter/auto', 'lisun-manual'),
        ]);

        // Relay 中转配置（用于国内服务器访问被墙 API）
        self::add_field('lisun_relay_url', __('Relay 中转地址', 'lisun-manual'), 'url', $group, $page, 'lisun_deepseek', [
            'default'     => '',
            'description' => __('美国中转服务器地址，如 https://relay.ledsvip.com。留空则不使用中转。', 'lisun-manual'),
        ]);
        self::add_field('lisun_relay_secret', __('Relay 密钥', 'lisun-manual'), 'password', $group, $page, 'lisun_deepseek', [
            'default'     => '',
            'description' => __('中转服务的 X-Relay-Secret 鉴权密钥', 'lisun-manual'),
        ]);
        self::add_field('lisun_deepseek_temperature', __('温度 (Temperature)', 'lisun-manual'), 'number', $group, $page, 'lisun_deepseek', [
            'default' => 0.3,
            'min'     => 0,
            'max'     => 2,
            'step'    => 0.1,
        ]);
        self::add_field('lisun_deepseek_max_tokens', __('最大 Tokens', 'lisun-manual'), 'number', $group, $page, 'lisun_deepseek', [
            'default' => 8192,
            'min'     => 256,
            'max'     => 32768,
        ]);
        self::add_field('lisun_deepseek_timeout', __('超时时间（秒）', 'lisun-manual'), 'number', $group, $page, 'lisun_deepseek', [
            'default' => 120,
            'min'     => 10,
            'max'     => 600,
        ]);

        // 翻译语言设置
        self::add_field('lisun_translate_source_lang', __('源语言', 'lisun-manual'), 'select', $group, $page, 'lisun_deepseek', [
            'default' => 'zh',
            'options' => [
                'zh' => '中文',
                'en' => 'English',
                'ja' => '日本語',
                'ko' => '한국어',
                'de' => 'Deutsch',
                'fr' => 'Français',
                'es' => 'Español',
                'ru' => 'Русский',
            ],
        ]);

        self::add_field('lisun_translate_target_lang', __('目标语言', 'lisun-manual'), 'select', $group, $page, 'lisun_deepseek', [
            'default' => 'en',
            'options' => [
                'en' => 'English',
                'zh' => '中文',
                'ja' => '日本語',
                'ko' => '한국어',
                'de' => 'Deutsch',
                'fr' => 'Français',
                'es' => 'Español',
                'ru' => 'Русский',
            ],
        ]);

        // ===== 豆包翻译（第一轮：保证完整性） =====
        self::add_field('lisun_doubao_enabled', __('启用豆包首轮翻译', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_deepseek', [
            'label' => __('翻译时先用豆包完整翻译，再用 DeepSeek 润色（避免漏译）', 'lisun-manual'),
        ]);
        self::add_field('lisun_doubao_key', __('豆包 API 密钥', 'lisun-manual'), 'password', $group, $page, 'lisun_deepseek');
        self::add_field('lisun_doubao_model', __('豆包模型名称', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'default'     => 'Doubao-Seed-2.1-pro',
            'description' => 'ARK 平台模型，如 Doubao-Seed-2.1-pro',
        ]);
        self::add_field('lisun_doubao_endpoint', __('豆包 Endpoint ID', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'description' => 'ARK endpoint ID（ep-xxx）',
        ]);

        // ===== DeepL 翻译（共用上方 API 密钥字段，无需单独密钥） =====
        self::add_field('lisun_deepl_pro', __('使用 DeepL Pro', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_deepseek', [
            'label' => __('勾选使用 Pro 端点（api.deepl.com），否则使用 Free 端点（api-free.deepl.com）', 'lisun-manual'),
        ]);
        self::add_field('lisun_deepl_formality', __('Formality 级别', 'lisun-manual'), 'select', $group, $page, 'lisun_deepseek', [
            'default' => 'default',
            'options' => [
                'default'       => '默认 (default)',
                'prefer_more'   => '更正式 (prefer_more)',
                'prefer_less'   => '更口语 (prefer_less)',
            ],
            'description' => __('技术手册建议使用 "默认"', 'lisun-manual'),
        ]);
        // ===== Google 翻译 =====
        self::add_field('lisun_google_mode', __('Google 接入方式', 'lisun-manual'), 'select', $group, $page, 'lisun_deepseek', [
            'default' => 'v3_glossary',
            'options' => [
                'v3_glossary' => 'Advanced v3 + 服务账号 + 术语表（推荐）',
                'v2_plain'    => 'Basic v2 + API Key（无术语表，仅纯翻译）',
            ],
            'description' => __('v3 必须用服务账号（Google 明确不接受 API Key），只有 v3 支持术语表。v2 模式保留为纯翻译备选。', 'lisun-manual'),
        ]);
        self::add_field('lisun_google_sa_path', __('Google 服务账号 JSON', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'default'     => '/www/wwwroot/demo.lisungroup.cn/wp-content/uploads/lisun-google-sa.json',
            'description' => __('服务账号密钥文件在服务器上的绝对路径（插件目录之外，不要提交到 git）。仅 v3 模式使用。', 'lisun-manual'),
        ]);
        self::add_field('lisun_google_project', __('Google 项目 ID', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'default' => 'lisun-group',
        ]);
        self::add_field('lisun_google_location', __('Google 区域', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'default'     => 'us-central1',
            'description' => __('术语表只在区域端点可用，写 global 会被拒绝。', 'lisun-manual'),
        ]);
        self::add_field('lisun_google_bucket', __('术语表 CSV 的 GCS 存储桶', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'default'     => 'lisun-translation-glossary',
            'description' => __('插件把术语表拼成无表头 CSV 直接上传到这里（不落盘），再用它创建 Google 术语表。', 'lisun-manual'),
        ]);
        self::add_field('lisun_google_object_prefix', __('GCS 对象前缀', 'lisun-manual'), 'text', $group, $page, 'lisun_deepseek', [
            'default'     => 'lisun',
            'description' => __('上传路径为 gs://存储桶/前缀/glossary-{哈希}.csv。', 'lisun-manual'),
        ]);
        self::add_field('lisun_google_key', __('Google 翻译 API Key（仅 v2 模式）', 'lisun-manual'), 'password', $group, $page, 'lisun_deepseek', [
            'description' => __('v2 纯翻译模式使用；v3 + 术语表模式用上面的服务账号，不需要 API Key。', 'lisun-manual'),
        ]);
        self::add_field('lisun_google_endpoint', __('Google v2 端点（仅 v2 模式）', 'lisun-manual'), 'url', $group, $page, 'lisun_deepseek', [
            'default'     => 'https://translation.googleapis.com/language/translate/v2',
            'description' => __('默认官方端点。国内服务器访问不到 Google 时，可在“代理服务”里启用本机代理，或改成兼容 v2 的中转地址。', 'lisun-manual'),
        ]);

        self::add_field('lisun_glossary_enabled', __('启用术语表', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_deepseek', [
            'default' => true,
            'label' => __('翻译时使用内置术语表；DeepL 会同步为 DeepL Glossary，Google 会同步为 Google 术语表；关闭后不做任何术语表同步与本地术语替换。', 'lisun-manual'),
        ]);
        self::add_field('lisun_term_anchors_enabled', __('启用正文术语锚点', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_deepseek', [
            'default' => true,
            'label' => __('仅对正文/富文本生成中文源锚点。关闭后仍可使用 DeepL Glossary，但完全回到无锚点翻译路径，便于回归排错。', 'lisun-manual'),
        ]);
        self::add_field('lisun_show_post_term_panel', __('详情页显示术语配置面板', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_deepseek', [
            'default' => true,
            'label' => __('在文章详情页显示“本篇术语”面板，可临时调整本次翻译用哪些术语，或保存为本篇默认术语。关闭后面板不显示，但术语筛选逻辑不受影响。', 'lisun-manual'),
        ]);
        self::add_field('lisun_translation_engine_v2', __('启用翻译单元 V2', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_deepseek', [
            'default' => true,
            'label' => __('使用“HTML 骨架 + 翻译单元 + 独立术语位置”新流程。取消勾选可立即回到旧版 V1，V2 不会删除旧备份。', 'lisun-manual'),
        ]);
        self::add_field('lisun_deepl_trace_enabled', __('记录 DeepL 原始报文', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_deepseek', [
            'default' => false,
            'label' => __('排错时逐字节保存 DeepL 请求体和响应体到 uploads/lisun-deepl-trace；内容可能包含完整文章，验证后请关闭。', 'lisun-manual'),
        ]);

        // ===== SEO 生成 API（与翻译 API 完全分开） =====
        add_settings_section(
            'lisun_seo_api',
            __('SEO 生成 API 设置', 'lisun-manual'),
            function () {
                echo '<p>' . __('用于导入说明书时生成 SEO 标题、描述和关键词。可与翻译 API 使用不同的提供商和 API 密钥，例如 DeepL 翻译 + OpenAI 生成 SEO。网络代理请使用上方代理服务中的开关和地址；代理不能替代 API 密钥。', 'lisun-manual') . '</p>';
            },
            $page
        );

        self::add_field('lisun_seo_provider', __('SEO LLM 提供商', 'lisun-manual'), 'select', $group, $page, 'lisun_seo_api', [
            'default' => 'openai',
            'options' => [
                'deepseek'   => 'DeepSeek',
                'openai'     => 'OpenAI (ChatGPT)',
                'gemini'     => 'Google Gemini',
                'openrouter' => 'OpenRouter',
                'custom'     => '自定义 (Custom)',
            ],
        ]);
        self::add_field('lisun_seo_api_url', __('SEO API 端点 URL', 'lisun-manual'), 'url', $group, $page, 'lisun_seo_api', [
            'default'     => 'https://api.openai.com/v1/chat/completions',
            'description' => __('OpenAI 兼容的 Chat Completions 端点；可按实际服务手动修改，提供商切换不会锁定此字段。', 'lisun-manual'),
        ]);
        self::add_field('lisun_seo_api_key', __('SEO API 密钥', 'lisun-manual'), 'password', $group, $page, 'lisun_seo_api');
        self::add_field('lisun_seo_model', __('SEO 模型名称', 'lisun-manual'), 'text', $group, $page, 'lisun_seo_api', [
            'default'     => 'gpt-4o',
            'description' => __('例如 gpt-4o、deepseek-chat、gemini-3.6-flash、openrouter/auto', 'lisun-manual'),
        ]);
        self::add_field('lisun_seo_relay_url', __('SEO Relay 中转地址', 'lisun-manual'), 'url', $group, $page, 'lisun_seo_api', [
            'default'     => '',
            'description' => __('SEO 单独使用 Relay 时填写；留空则直连 API。', 'lisun-manual'),
        ]);
        self::add_field('lisun_seo_relay_secret', __('SEO Relay 密钥', 'lisun-manual'), 'password', $group, $page, 'lisun_seo_api');
        self::add_field('lisun_seo_timeout', __('SEO API 超时时间（秒）', 'lisun-manual'), 'number', $group, $page, 'lisun_seo_api', [
            'default' => 120,
            'min'     => 10,
            'max'     => 600,
        ]);

        // ===== 历史图片转换 =====
        add_settings_section(
            'lisun_legacy_images',
            __('历史图片转换', 'lisun-manual'),
            function () {
            echo '<p>' . __('将历史导入图片转换为 progressive JPEG，并自动替换说明书正文中的旧图片 URL。已经是 progressive JPEG 的图片会直接跳过；仅文件名带 .jpg 不代表一定是 progressive JPEG。本地文件不可读但 URL 有效时会先尝试下载；只有确认 URL 返回 404/410 或完全没有 URL 才会跳过。原附件会保留，不会因转换导致旧引用立即失效。任务由 WordPress 定时任务每小时执行一次。', 'lisun-manual') . '</p>';
            },
            $page
        );
        self::add_field('lisun_legacy_image_batch', __('每小时处理数量', 'lisun-manual'), 'number', $group, $page, 'lisun_legacy_images', [
            'default'     => 20,
            'min'         => 1,
            'max'         => 100,
            'description' => __('定时任务和手动连续处理都按此数量分批，例如设置 10 或 20。手动会自动继续下一批；图片较大或服务器内存较小时建议设置 5–10。', 'lisun-manual'),
        ]);
        add_settings_field(
            'lisun_legacy_image_status',
            __('处理状态', 'lisun-manual'),
            [__CLASS__, 'render_legacy_image_status'],
            $page,
            'lisun_legacy_images'
        );

        // ===== 爬虫豁免 =====
        add_settings_section(
            'lisun_crawler',
            __('爬虫豁免', 'lisun-manual'),
            function () {
                echo '<p>' . __('搜索引擎爬虫的 User-Agent 匹配规则和反向 DNS 验证域名。', 'lisun-manual') . '</p>';
            },
            $page
        );

        self::add_field('lisun_crawler_ip_verify', __('启用 IP 反向 DNS 验证', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_crawler', [
            'label' => __('启用（关闭后仅检查 User-Agent，安全性降低）', 'lisun-manual'),
        ]);

        // 爬虫列表用自定义渲染
        add_settings_field(
            'lisun_crawler_list',
            __('爬虫 User-Agent 列表', 'lisun-manual'),
            [__CLASS__, 'render_crawler_list'],
            $page,
            'lisun_crawler'
        );
        register_setting($group, 'lisun_crawler_list', [__CLASS__, 'sanitize_crawler_list']);

        // ===== 密码保护 =====
        add_settings_section(
            'lisun_password',
            __('密码保护', 'lisun-manual'),
            function () {
                echo '<p>' . __('控制 /manual/ 页面的访问权限。', 'lisun-manual') . '</p>';
            },
            $page
        );

        self::add_field('lisun_password_enabled', __('启用密码保护', 'lisun-manual'), 'checkbox', $group, $page, 'lisun_password', [
            'label' => __('启用密码保护（客户需要登录才能查看）', 'lisun-manual'),
        ]);

        self::add_field('lisun_password_realm', __('登录提示文字', 'lisun-manual'), 'text', $group, $page, 'lisun_password', [
            'default' => 'LISUN Manual Access',
        ]);

        self::add_field('lisun_access_logs_max', __('访问日志最大条数', 'lisun-manual'), 'number', $group, $page, 'lisun_password', [
            'default' => 1000,
            'min'     => 100,
            'max'     => 10000,
        ]);

        // ===== 客户管理权限 =====
        add_settings_section(
            'lisun_customer_perms_section',
            __('客户管理权限', 'lisun-manual'),
            function () {
                echo '<p>' . __('设置哪些 WP 用户只能管理售前/售后客户。管理员（administrator）始终拥有全部权限。', 'lisun-manual') . '</p>';
            },
            $page
        );

        add_settings_field(
            'lisun_customer_perms',
            __('用户权限分配', 'lisun-manual'),
            [__CLASS__, 'render_customer_perms'],
            $page,
            'lisun_customer_perms_section'
        );
        register_setting($group, 'lisun_customer_perms', [__CLASS__, 'sanitize_customer_perms']);

        // ===== 页面语言 =====
        add_settings_section(
            'lisun_page_lang',
            __('页面语言', 'lisun-manual'),
            function () {
                echo '<p>' . __('控制说明书前台页面的全部固定界面文字，包括面包屑、目录标题和页面副标题；不改变文章正文内容。', 'lisun-manual') . '</p>';
            },
            $page
        );
        register_setting($group, 'lisun_page_lang');
        add_settings_field(
            'lisun_page_lang',
            __('界面语言', 'lisun-manual'),
            [__CLASS__, 'render_lang_select'],
            $page,
            'lisun_page_lang'
        );
    }

    /**
     * 快捷注册字段
     */
    private static function add_field($name, $title, $type, $group, $page, $section, $opts = []) {
        add_settings_field(
            $name,
            $title,
            function () use ($name, $type, $opts) {
                $value = get_option($name, $opts['default'] ?? '');

                switch ($type) {
                    case 'url':
                    case 'text':
                        echo '<input type="' . $type . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text">';
                        break;

                    case 'password':
                        echo '<input type="password" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" autocomplete="new-password">';
                        break;

                    case 'number':
                        $min = isset($opts['min']) ? 'min="' . $opts['min'] . '"' : '';
                        $max = isset($opts['max']) ? 'max="' . $opts['max'] . '"' : '';
                        $step = isset($opts['step']) ? 'step="' . $opts['step'] . '"' : '';
                        echo '<input type="number" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . $min . ' ' . $max . ' ' . $step . ' class="small-text">';
                        break;

                    case 'checkbox':
                        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
                        echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($value, true, false) . '> ' . esc_html($opts['label'] ?? '') . '</label>';
                        break;

                    case 'select':
                        echo '<select name="' . esc_attr($name) . '">';
                        foreach ($opts['options'] as $val => $label) {
                            echo '<option value="' . esc_attr($val) . '" ' . selected($value, $val, false) . '>' . esc_html($label) . '</option>';
                        }
                        echo '</select>';
                        break;
                }

                if (!empty($opts['description'])) {
                    echo '<p class="description">' . esc_html($opts['description']) . '</p>';
                }
            },
            $page,
            $section
        );

        // 注册设置
        $sanitize = $opts['sanitize'] ?? null;
        if (!$sanitize) {
            switch ($type) {
                case 'url':
                    $sanitize = 'esc_url_raw';
                    break;
                case 'number':
                    $sanitize = function ($v) use ($opts) {
                        $v = floatval($v);
                        if (isset($opts['min'])) $v = max($v, $opts['min']);
                        if (isset($opts['max'])) $v = min($v, $opts['max']);
                        return $v;
                    };
                    break;
                case 'checkbox':
                    $sanitize = function ($v) { return $v ? true : false; };
                    break;
                default:
                    $sanitize = 'sanitize_text_field';
            }
        }

        register_setting($group, $name, $sanitize);
    }


    /**
     * 渲染爬虫列表（textarea）
     */
    public static function render_crawler_list() {
        $list = get_option('lisun_crawler_list', Lisun_Manual_Access_Control::CRAWLER_PATTERNS);
        $lines = [];

        foreach ($list as $bot => $domains) {
            $lines[] = $bot . ': ' . implode(', ', (array) $domains);
        }

        echo '<textarea name="lisun_crawler_list_raw" rows="8" class="large-text code">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
        echo '<p class="description">' . __('每行一个爬虫，格式："Bot名称: domain1.com, domain2.com"', 'lisun-manual') . '</p>';
    }

    /**
     * 渲染语言选择器
     */
    public static function render_lang_select() {
        $current = get_option('lisun_page_lang', 'zh');
        ?>
        <select name="lisun_page_lang">
            <option value="zh" <?php selected($current, 'zh'); ?>>中文</option>
            <option value="en" <?php selected($current, 'en'); ?>>English</option>
        </select>
        <p class="description">控制前台页面 TOC 标题等界面文字的语言。</p>
        <?php
    }

    /**
     * 渲染客户管理权限分配
     */
    public static function render_customer_perms() {
        $perms = get_option('lisun_customer_perms', []);
        $users = get_users(['role__not_in' => ['administrator'], 'orderby' => 'display_name']);
        if (empty($users)) {
            echo '<p style="color:#888;">暂无可用用户（除管理员外）。</p>';
            return;
        }
        echo '<table class="widefat" style="max-width:600px;"><thead><tr><th>用户</th><th style="width:120px;">可管理类型</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $current = $perms[$u->ID] ?? '';
            echo '<tr>';
            echo '<td>' . esc_html($u->display_name) . ' (' . esc_html($u->user_login) . ')</td>';
            echo '<td><select name="lisun_customer_perms[' . $u->ID . ']">';
            echo '<option value="">— 无权限 —</option>';
            echo '<option value="pre_sale" ' . selected($current, 'pre_sale', false) . '>仅售前</option>';
            echo '<option value="after_sale" ' . selected($current, 'after_sale', false) . '>仅售后</option>';
            echo '<option value="both" ' . selected($current, 'both', false) . '>全部</option>';
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">管理员默认拥有全部权限。未设置的用户无权限管理客户。</p>';
    }

    /**
     * 渲染历史图片转换状态。
     */
    public static function render_legacy_image_status() {
        $status = Lisun_Manual_Import_Parser::get_legacy_image_status(100);
        $summary = $status['summary'];
        $next = wp_next_scheduled(Lisun_Manual_Import_Parser::LEGACY_IMAGE_CRON);

        echo '<p><strong>总计：</strong>' . esc_html($summary['total'])
            . '　<strong style="color:#135e96;">实际被引用：</strong>' . esc_html($summary['referenced'] ?? 0)
            . '　<strong style="color:#168a3b;">已处理：</strong>' . esc_html($summary['processed'])
            . '　<strong style="color:#996800;">待处理：</strong>' . esc_html($summary['pending'])
            . '　<strong style="color:#d63638;">失败：</strong>' . esc_html($summary['failed'])
            . '　<strong style="color:#777;">已跳过：</strong>' . esc_html($summary['skipped'])
            . '　<strong style="color:#777;">未被引用：</strong>' . esc_html($summary['unreferenced']) . '</p>';
        echo '<p class="description">“总计”是插件历史记录中的媒体数量；只有正文中实际出现的图片才计入“实际被引用”并进入转换队列，未被引用的媒体不会处理。</p>';

        if ($next) {
        echo '<p class="description">下一次定时处理：' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next)) . '</p>';
        } else {
            echo '<p class="description">当前没有检测到已安排的定时任务；插件初始化后会自动安排。</p>';
        }

        echo '<p><button type="button" class="button button-primary" id="lisun-legacy-image-convert-now"'
            . ' data-rest-url="' . esc_attr(rest_url('lisun-manual/v1/legacy-image-convert-now')) . '"'
            . ' data-rest-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">连续处理全部</button> ';
        echo '<span id="lisun-legacy-image-convert-result" style="margin-left:8px;"></span></p>';
        echo '<p class="description">点击后会自动连续处理，直到没有待处理图片；每批数量使用上面的设置，批次之间自动继续。失败记录会保留，不会无限重复重试。</p>';

        if (empty($status['rows'])) {
            echo '<p style="color:#777;">暂无可转换的历史图片记录。</p>';
        } else {
            $labels = [
                'processed' => ['已处理', '#168a3b'],
                'pending'   => ['待处理', '#996800'],
                'failed'    => ['失败', '#d63638'],
            ];
            echo '<table class="widefat striped" style="max-width:1000px;">';
            echo '<thead><tr><th>图片文件名</th><th style="width:90px;">状态</th><th style="width:90px;">附件 ID</th><th style="width:230px;">引用说明书 Post ID</th><th>最近错误</th><th style="width:150px;">最近尝试</th></tr></thead><tbody>';
            foreach ($status['rows'] as $row) {
                $label = $labels[$row['status']] ?? ['未知', '#777'];
                $name = $row['url'] !== ''
                    ? '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener">' . esc_html($row['name']) . '</a>'
                    : esc_html($row['name']);
                $reference_ids = [];
                foreach ((array) ($row['reference_post_ids'] ?? []) as $post_id) {
                    $post_id = (int) $post_id;
                    if ($post_id <= 0) continue;
                    $edit_link = get_edit_post_link($post_id);
                    $reference_ids[] = $edit_link
                        ? '<a href="' . esc_url($edit_link) . '" target="_blank" rel="noopener">' . esc_html($post_id) . '</a>'
                        : esc_html($post_id);
                }
                $reference_ids = $reference_ids ? implode(', ', $reference_ids) : '—';
                $attempt = $row['attempt'] ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $row['attempt']) : '—';
                echo '<tr><td>' . $name . '</td>'
                    . '<td><strong style="color:' . esc_attr($label[1]) . ';">' . esc_html($label[0]) . '</strong></td>'
                    . '<td>' . esc_html($row['attachment'] ?: '—') . '</td>'
                    . '<td>' . $reference_ids . '</td>'
                    . '<td>' . ($row['error'] !== '' ? esc_html($row['error']) : (($row['note'] ?? '') !== '' ? esc_html($row['note']) : '—')) . '</td>'
                    . '<td>' . esc_html($attempt) . '</td></tr>';
            }
            echo '</tbody></table>';
            if ($status['limited']) {
                echo '<p class="description">仅显示前 100 条，汇总数字包含全部记录。</p>';
            }
        }

        if (!empty($status['skipped_rows'])) {
            echo '<details style="margin-top:16px;max-width:1000px;"><summary style="cursor:pointer;font-weight:600;">查看已跳过图片（仅核查，不参与转换）</summary>';
            echo '<table class="widefat striped" style="margin-top:10px;">';
            echo '<thead><tr><th>图片文件名</th><th style="width:230px;">引用说明书 Post ID</th><th>跳过原因</th></tr></thead><tbody>';
            foreach ($status['skipped_rows'] as $row) {
                $name = $row['url'] !== ''
                    ? '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener">' . esc_html($row['name']) . '</a>'
                    : esc_html($row['name']);
                $reference_ids = [];
                foreach ((array) ($row['reference_post_ids'] ?? []) as $post_id) {
                    $post_id = (int) $post_id;
                    if ($post_id <= 0) continue;
                    $edit_link = get_edit_post_link($post_id);
                    $reference_ids[] = $edit_link
                        ? '<a href="' . esc_url($edit_link) . '" target="_blank" rel="noopener">' . esc_html($post_id) . '</a>'
                        : esc_html($post_id);
                }
                $reference_ids = $reference_ids ? implode(', ', $reference_ids) : '—';
                echo '<tr><td>' . $name . '</td>'
                    . '<td>' . $reference_ids . '</td>'
                    . '<td>' . esc_html($row['reason']) . '</td></tr>';
            }
            echo '</tbody></table>';
            if ($status['skipped_limited']) {
                echo '<p class="description">仅显示前 100 条已跳过记录。</p>';
            }
            echo '</details>';
        }
    }

    /**
     * 清洗客户权限数据
     */
    public static function sanitize_customer_perms($input) {
        if (!is_array($input)) return [];
        $valid = ['pre_sale', 'after_sale', 'both', ''];
        $clean = [];
        foreach ($input as $uid => $type) {
            $uid = intval($uid);
            if ($uid > 0 && in_array($type, $valid, true) && $type !== '') {
                $clean[$uid] = $type;
            }
        }
        return $clean;
    }

    /**
     * 清洗爬虫列表
     */
    public static function sanitize_crawler_list($input) {
        // input 可能来自 textarea
        if (is_string($_POST['lisun_crawler_list_raw'] ?? '')) {
            $raw = sanitize_textarea_field(wp_unslash($_POST['lisun_crawler_list_raw']));
            $lines = explode("\n", $raw);
            $result = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = explode(':', $line, 2);
                if (count($parts) !== 2) continue;

                $bot = trim($parts[0]);
                $domains = array_map('trim', explode(',', $parts[1]));
                $domains = array_filter($domains);

                if (!empty($bot) && !empty($domains)) {
                    $result[$bot] = $domains;
                }
            }

            return !empty($result) ? $result : Lisun_Manual_Access_Control::CRAWLER_PATTERNS;
        }

        return $input;
    }

    /**
     * 渲染测试连接脚本
     */
    public static function render_test_connection_script() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'lisun-manual-settings') === false) {
            return;
        }
        $nonce = wp_create_nonce('lisun_manual_nonce');
        $rest_nonce = wp_create_nonce('wp_rest');
        $rest_url = rest_url('lisun-manual/v1/test-llm');

        // 提供商预设 URL
        $provider_urls = [
            'deepseek'   => 'https://api.deepseek.com/chat/completions',
            'openai'     => 'https://api.openai.com/v1/chat/completions',
            'gemini'     => 'https://generativelanguage.googleapis.com/v1beta/models',
            'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
            'deepl'      => '',
            'google'     => '',
        ];
        $provider_models = [
            'deepseek'   => 'deepseek-chat',
            'openai'     => 'gpt-4o',
            'gemini'     => 'gemini-3.6-flash',
            'openrouter' => 'openrouter/auto',
            'deepl'      => 'N/A',
            'google'     => 'N/A',
        ];
        ?>
        <script>
        jQuery(function ($) {
            // —— 系统设置顶部 Tab ——
            // WordPress 会把所有 settings section 连续输出在同一个 form 中，
            // 这里按 section 的 h2 分组，切换时只显示当前设置区，保存逻辑不变。
            var $settingsForm = $('form[action="options.php"]');
            var $sectionHeads = $settingsForm.children('h2');
            // WordPress 的 do_settings_sections() 默认不会给 h2 输出 id，
            // 按 register_settings() 中的注册顺序补上稳定 ID。
            var sectionOrder = [
                'lisun_en_connection',
                'lisun_glossary_proxy',
                'lisun_deepseek',
                'lisun_seo_api',
                'lisun_legacy_images',
                'lisun_crawler',
                'lisun_password',
                'lisun_customer_perms_section',
                'lisun_page_lang'
            ];
            $sectionHeads.each(function (index) {
                if (!this.id && sectionOrder[index]) $(this).attr('id', sectionOrder[index]);
            });
            $sectionHeads = $sectionHeads.filter('[id]');
            if ($settingsForm.length && $sectionHeads.length > 1) {
                var tabLabels = {
                    lisun_en_connection: '远程站',
                    lisun_glossary_proxy: '代理服务',
                    lisun_deepseek: '翻译 API',
                    lisun_seo_api: 'SEO API',
                    lisun_legacy_images: '图片格式',
                    lisun_crawler: '爬虫豁免',
                    lisun_password: '密码保护',
                    lisun_customer_perms_section: '客户权限',
                    lisun_page_lang: '页面语言'
                };
                var $tabs = $('<div class="lisun-settings-tabs" role="tablist" aria-label="系统设置分类"></div>');
                var $panels = $();

                $sectionHeads.each(function (index) {
                    var $head = $(this);
                    var id = $head.attr('id');
                    // 先保存节点集合，再移动 h2；否则 h2 移动后无法继续找到后续兄弟节点。
                    var $sectionNodes = $head.add($head.nextUntil('h2[id]'));
                    var $panel = $('<section class="lisun-settings-panel"></section>')
                        .attr('id', id + '-panel')
                        .attr('role', 'tabpanel')
                        .attr('aria-labelledby', id + '-tab');
                    var $tab = $('<button type="button" class="lisun-settings-tab" role="tab"></button>')
                        .attr('id', id + '-tab')
                        .attr('aria-controls', id + '-panel')
                        .text(tabLabels[id] || $.trim($head.text()));

                    $head.before($panel);
                    $panel.append($sectionNodes);
                    $tabs.append($tab);
                    $panels = $panels.add($panel);

                    $tab.on('click', function () {
                        activateTab(id);
                    });
                });

                // submit_button() is emitted after the final section.  A
                // single button at the very bottom is invisible whenever a
                // different tab is active, so replace it with one real submit
                // button inside every tab.  All tabs remain in the same form,
                // therefore each button saves every registered setting safely.
                var $saveBar = $settingsForm.find('p.submit').last();
                if ($saveBar.length) {
                    $saveBar.remove();
                }
                $panels.each(function () {
                    var $panel = $(this);
                    var $panelSave = $('<p class="submit lisun-settings-panel-savebar"></p>')
                        .append($('<button type="submit" class="button button-primary">保存设置</button>'))
                        .append($('<span class="description">保存当前填写内容后再进行连接测试。</span>'));
                    $panel.append($panelSave);
                });

                // Tab 插入在第一个设置区之前；保存栏保持在所有标签之外。
                $settingsForm.prepend($tabs);
                $('<style id="lisun-settings-tabs-style">\
                    .lisun-settings-tabs { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 18px; padding:8px 0 0; border-bottom:1px solid #c3c4c7; }\
                    .lisun-settings-tab { appearance:none; border:1px solid #c3c4c7; border-bottom:0; border-radius:4px 4px 0 0; background:#f6f7f7; color:#1d2327; padding:8px 14px; cursor:pointer; font-size:13px; line-height:1.4; }\
                    .lisun-settings-tab:hover { background:#fff; color:#135e96; }\
                    .lisun-settings-tab.is-active { background:#fff; color:#135e96; border-color:#2271b1; box-shadow:0 -2px 0 #2271b1 inset; font-weight:600; }\
                    .lisun-settings-panel { display:none; }\
                    p.submit.lisun-settings-panel-savebar { display:flex; align-items:center; gap:10px; margin:24px 0 4px !important; padding:16px 0 0 !important; border-top:1px solid #dcdcde; }\
                    .lisun-settings-panel-savebar .button-primary { min-width:88px; }\
                    .lisun-settings-panel-savebar .description { margin:0; }\
                    .lisun-api-actions { display:flex; align-items:center; gap:8px; min-height:40px; }\
                    .lisun-api-actions .button { margin:0; }\
                </style>').appendTo('head');

                function activateTab(id) {
                    $panels.hide().filter('#' + id + '-panel').show();
                    $tabs.find('.lisun-settings-tab').removeClass('is-active').attr('aria-selected', 'false');
                    $tabs.find('#' + id + '-tab').addClass('is-active').attr('aria-selected', 'true');
                    try { sessionStorage.setItem('lisun_manual_settings_tab', id); } catch (e) {}
                }

                function hasPanel(id) {
                    return $panels.filter(function () {
                        return this.id === id + '-panel';
                    }).length > 0;
                }

                var initialTab = window.location.hash.replace(/^#/, '');
                if (!initialTab || !hasPanel(initialTab)) {
                    try { initialTab = sessionStorage.getItem('lisun_manual_settings_tab') || ''; } catch (e) {}
                }
                if (!initialTab || !hasPanel(initialTab)) {
                    initialTab = $sectionHeads.first().attr('id');
                }
                activateTab(initialTab);
            }
            // 页头已暂时隐藏表单；Tab 和首个面板准备好后一次性显示。
            $settingsForm.addClass('lisun-settings-tabs-ready');

            // —— 历史图片手动转换 ——
            $('#lisun-legacy-image-convert-now').on('click', function (e) {
                e.preventDefault();
                var $button = $(this);
                var $result = $('#lisun-legacy-image-convert-result');
                var batchSize = parseInt($('input[name="lisun_legacy_image_batch"]').val(), 10) || 20;
                batchSize = Math.max(1, Math.min(100, batchSize));
                var requestCount = 0;
                var processedCount = 0;
                var failedCount = 0;
                var skippedCount = 0;
                var networkRetryCount = 0;

                $button.prop('disabled', true).text('连续处理中...');

                function runOne() {
                    requestCount++;
                    $result.css('color', '#666').text('正在连续处理：第 ' + requestCount + ' 批（每批 ' + batchSize + ' 张），本次完成 ' + processedCount + ' 张，跳过 ' + skippedCount + ' 张');

                    $.ajax({
                        url: $button.data('rest-url'),
                        method: 'POST',
                        data: { batch: batchSize },
                        headers: { 'X-WP-Nonce': $button.data('rest-nonce') },
                        timeout: 180000
                    }).done(function (res) {
                        if (!res || !res.success) {
                            $button.prop('disabled', false).text('连续处理全部');
                            $result.css('color', '#d63638').text((res && res.data && res.data.message) || '处理失败');
                            return;
                        }

                        var item = (res.data && res.data.result) || {};
                        networkRetryCount = 0;
                        processedCount += parseInt(item.processed, 10) || 0;
                        failedCount += parseInt(item.failed, 10) || 0;
                        skippedCount += parseInt(item.skipped, 10) || 0;
                        // 后端按队列状态判断是否还有待处理，不能用 attempted=0，
                        // 因为中间可能只是扫描到已处理、跳过或未被引用的记录。
                        if (item.has_more === true) {
                            window.setTimeout(runOne, 80);
                            return;
                        }
                        if (failedCount > 0) {
                            $result.css('color', '#996800').text('本次完成 ' + processedCount + ' 张，跳过 ' + skippedCount + ' 张；另有 ' + failedCount + ' 张失败，页面即将刷新。');
                        } else {
                            $result.css('color', '#168a3b').text('已连续处理完队列，本次完成 ' + processedCount + ' 张，跳过 ' + skippedCount + ' 张，页面即将刷新。');
                        }
                        window.setTimeout(function () { window.location.reload(); }, 700);
                    }).fail(function (xhr) {
                        var message = xhr.status
                            ? '网络请求失败（HTTP ' + xhr.status + '）'
                            : '网络请求失败：服务器未返回响应，可能是请求超时或被代理中断';
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            message += '：' + xhr.responseJSON.data.message;
                        } else if (xhr.responseText) {
                            var preview = $.trim(xhr.responseText.replace(/<[^>]+>/g, ' ')).slice(0, 160);
                            if (preview) message += '：' + preview;
                        }
                        // 502/504/522/524 或客户端超时可能发生在服务器已经完成转换之后。
                        // 自动等待后继续，避免丢失成功响应；多次失败后刷新以读取服务器真实统计。
                        var gatewayTimeout = !xhr.status || [502, 504, 522, 524].indexOf(xhr.status) !== -1;
                        if (gatewayTimeout && networkRetryCount < 3) {
                            networkRetryCount++;
                            var waitSeconds = networkRetryCount * 10;
                            $result.css('color', '#996800').text(message + '；' + waitSeconds + ' 秒后自动继续（第 ' + networkRetryCount + '/3 次）');
                            window.setTimeout(runOne, waitSeconds * 1000);
                            return;
                        }
                        $button.prop('disabled', false).text('连续处理全部');
                        $result.css('color', '#d63638').text(message + '；已自动刷新统计。');
                        window.setTimeout(function () { window.location.reload(); }, 1500);
                    });
                }

                runOne();
            });

            // —— 远程站测试连接按钮 ——
            var $btn = $('<button>')
                .text('测试连接')
                .addClass('button')
                .css('margin-left', '10px')
                .on('click', function (e) {
                    e.preventDefault();
                    var $this = $(this);
                    $this.prop('disabled', true).text('测试中...');

                    $.post(ajaxurl, {
                        action: 'lisun_test_connection',
                        _nonce: '<?php echo $nonce; ?>'
                    }, function (res) {
                        $this.prop('disabled', false).text('测试连接');
                        if (res.success) {
                            alert(res.data.message || '连接成功！');
                        } else {
                            alert('连接失败: ' + (res.data.message || ''));
                        }
                    });
                });

            $('input[name="lisun_en_site_password"]').closest('table').find('tr').last().after(
                $('<tr>').append($('<th>')).append($('<td>').append($btn))
            );

            // —— 翻译 API 测试按钮 ——
            var $btnApi = $('<button>')
                .text('测试连接')
                .addClass('button')
                .css('margin-left', '10px')
                .on('click', function (e) {
                    e.preventDefault();
                    var $this = $(this);
                    $this.prop('disabled', true).text('测试中...');

                    // 收集当前表单值（用户可能填了新值还没保存，作为服务端回退）
                    var formData = {
                        provider: $('select[name="lisun_llm_provider"]').val(),
                        api_key: $('input[name="lisun_deepseek_key"]').val(),
                        api_url: $('input[name="lisun_llm_api_url"]').val(),
                        model: $('input[name="lisun_deepseek_model"]').val(),
                        deepl_pro: $('input[name="lisun_deepl_pro"]').is(':checked'),
                    };

                    $.ajax({
                        url: '<?php echo esc_js($rest_url); ?>',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify(formData),
                        beforeSend: function (xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js($rest_nonce); ?>');
                        },
                        success: function (res) {
                            $this.prop('disabled', false).text('测试连接');
                            if (res.success) {
                                alert(res.data.message || 'API 连接成功！');
                            } else {
                                alert('API 连接失败: ' + (res.data.message || ''));
                            }
                        },
                        error: function (jqXHR) {
                            $this.prop('disabled', false).text('测试连接');
                            var msg = '网络请求异常';
                            try {
                                var err = JSON.parse(jqXHR.responseText);
                                msg = err.message || err.data?.message || msg;
                            } catch (e) {}
                            alert('API 连接失败 (' + jqXHR.status + '): ' + msg);
                        }
                    });
                });

            // 按钮放在翻译 API 设置表格底部
            // 优先追加到 DeepL formality 行之后（DeepL 字段组的最后一行），
            // 这样无论哪个 provider 被选中，按钮都固定在表格末尾
            var $targetRow = $('select[name="lisun_deepl_formality"]').closest('tr');
            if ($targetRow.length) {
                $targetRow.after(
                    $('<tr class="lisun-test-api-row">').append($('<th>')).append($('<td>').append($btnApi))
                );
            } else {
                $('input[name="lisun_deepseek_key"]').closest('table').find('tr').last().after(
                    $('<tr class="lisun-test-api-row">').append($('<th>')).append($('<td>').append($btnApi))
                );
            }
            // Keep the normal WordPress submit control beside the test button.
            // It remains inside the same form, but is no longer hidden in a
            // different tab or rendered as a large full-width bar.
            if ($saveBar && $saveBar.length) {
                var $apiActions = $('<div class="lisun-api-actions"></div>');
                $btnApi.after($apiActions);
                $apiActions.append($btnApi).append($saveBar);
            }

            // —— 提供商切换联动 ——
            var $provider = $('select[name="lisun_llm_provider"]');
            var $apiUrl = $('input[name="lisun_llm_api_url"]');
            var $model = $('input[name="lisun_deepseek_model"]');
            var providerUrls = <?php echo json_encode($provider_urls); ?>;
            var providerModels = <?php echo json_encode($provider_models); ?>;

            // 预设值只用于辅助填写。自定义端点/模型必须能够保留并继续编辑，
            // 尤其是 Gemini、第三方兼容接口和本地网关的地址通常不是固定值。
            function isKnownValue(value, values) {
                value = $.trim(value || '');
                return !value || $.inArray(value, values) !== -1;
            }

            var knownProviderUrls = Object.keys(providerUrls).map(function (key) { return providerUrls[key]; });
            var knownProviderModels = Object.keys(providerModels).map(function (key) { return providerModels[key]; });

            // All Google engine rows (mode + service account + glossary storage + v2 fallback)
            function googleRows() {
                return $('select[name="lisun_google_mode"]').closest('tr')
                    .add($('input[name="lisun_google_sa_path"]').closest('tr'))
                    .add($('input[name="lisun_google_project"]').closest('tr'))
                    .add($('input[name="lisun_google_location"]').closest('tr'))
                    .add($('input[name="lisun_google_bucket"]').closest('tr'))
                    .add($('input[name="lisun_google_object_prefix"]').closest('tr'))
                    .add($('input[name="lisun_google_key"]').closest('tr'))
                    .add($('input[name="lisun_google_endpoint"]').closest('tr'));
            }

            $provider.on('change', function () {
                var p = $(this).val();
                var $apiFields = $apiUrl.closest('tr').add($model.closest('tr'))
                    .add($('input[name="lisun_deepseek_temperature"]').closest('tr'))
                    .add($('input[name="lisun_deepseek_max_tokens"]').closest('tr'));
                var $deeplFields = $('input[name="lisun_deepl_pro"]').closest('tr')
                    .add($('select[name="lisun_deepl_formality"]').closest('tr'));
                var $googleFields = googleRows();

                if (p === 'deepl') {
                    $apiFields.hide();
                    $googleFields.hide();
                    $deeplFields.show();
                } else if (p === 'google') {
                    $apiFields.hide();
                    $deeplFields.hide();
                    $googleFields.show();
                } else {
                    $apiFields.show();
                    $deeplFields.hide();
                    $googleFields.hide();
                    if (p === 'custom') {
                        if (isKnownValue($apiUrl.val(), knownProviderUrls)) $apiUrl.val('');
                        if (isKnownValue($model.val(), knownProviderModels)) $model.val('');
                        $apiUrl.prop('readonly', false).focus();
                        $model.prop('readonly', false).attr('placeholder', '输入模型名称');
                    } else if (providerUrls[p]) {
                        if (isKnownValue($apiUrl.val(), knownProviderUrls)) $apiUrl.val(providerUrls[p]);
                        if (isKnownValue($model.val(), knownProviderModels)) $model.val(providerModels[p] || '');
                        $apiUrl.prop('readonly', false);
                        $model.prop('readonly', false).attr('placeholder', providerModels[p] || '');
                    }
                }
            });

            // 页面加载时触发一次
            var initProvider = $provider.val();
            $apiUrl.prop('readonly', false);
            $model.prop('readonly', false);
            // DeepL 初始状态
            if (initProvider === 'deepl') {
                $apiUrl.closest('tr').add($model.closest('tr'))
                    .add($('input[name="lisun_deepseek_temperature"]').closest('tr'))
                    .add($('input[name="lisun_deepseek_max_tokens"]').closest('tr')).hide();
                googleRows().hide();
                $('input[name="lisun_deepl_pro"]').closest('tr')
                    .add($('select[name="lisun_deepl_formality"]').closest('tr')).show();
            } else if (initProvider === 'google') {
                $apiUrl.closest('tr').add($model.closest('tr'))
                    .add($('input[name="lisun_deepseek_temperature"]').closest('tr'))
                    .add($('input[name="lisun_deepseek_max_tokens"]').closest('tr')).hide();
                $('input[name="lisun_deepl_pro"]').closest('tr')
                    .add($('select[name="lisun_deepl_formality"]').closest('tr')).hide();
                googleRows().show();
            } else {
                $('input[name="lisun_deepl_pro"]').closest('tr')
                    .add($('select[name="lisun_deepl_formality"]').closest('tr')).hide();
                googleRows().hide();
            }

            // —— SEO 生成 API 提供商联动 ——
            var $seoProvider = $('select[name="lisun_seo_provider"]');
            var $seoApiUrl = $('input[name="lisun_seo_api_url"]');
            var $seoModel = $('input[name="lisun_seo_model"]');
            var seoProviderUrls = {
                deepseek: 'https://api.deepseek.com/chat/completions',
                openai: 'https://api.openai.com/v1/chat/completions',
                gemini: 'https://generativelanguage.googleapis.com/v1beta/models',
                openrouter: 'https://openrouter.ai/api/v1/chat/completions'
            };
            var seoProviderModels = {
                deepseek: 'deepseek-chat',
                openai: 'gpt-4o',
                gemini: 'gemini-3.6-flash',
                openrouter: 'openrouter/auto'
            };

            var knownSeoProviderUrls = Object.keys(seoProviderUrls).map(function (key) { return seoProviderUrls[key]; });
            var knownSeoProviderModels = Object.keys(seoProviderModels).map(function (key) { return seoProviderModels[key]; });

            $seoProvider.on('change', function () {
                var p = $(this).val();
                if (p === 'custom') {
                    if (isKnownValue($seoApiUrl.val(), knownSeoProviderUrls)) $seoApiUrl.val('');
                    if (isKnownValue($seoModel.val(), knownSeoProviderModels)) $seoModel.val('');
                    $seoApiUrl.prop('readonly', false).focus();
                    $seoModel.prop('readonly', false).attr('placeholder', '输入模型名称');
                } else if (seoProviderUrls[p]) {
                    if (isKnownValue($seoApiUrl.val(), knownSeoProviderUrls)) $seoApiUrl.val(seoProviderUrls[p]);
                    if (isKnownValue($seoModel.val(), knownSeoProviderModels)) $seoModel.val(seoProviderModels[p] || '');
                    $seoApiUrl.prop('readonly', false);
                    $seoModel.prop('readonly', false).attr('placeholder', seoProviderModels[p] || '');
                }
            });

            var initSeoProvider = $seoProvider.val();
            $seoApiUrl.prop('readonly', false);
            $seoModel.prop('readonly', false);
        });
        </script>
        <?php
    }

    /**
     * REST API: 测试 LLM 连接
     */
    public static function rest_test_llm($request) {
        ob_start();

        // 优先用已保存的选项，回退到表单传来的值（用户可能填了还没保存）
        $body = $request->get_json_params();
        if (empty($body)) {
            $raw = $request->get_body();
            $body = json_decode($raw, true) ?: [];
        }
        if (empty($body)) {
            $body = $request->get_params();
        }
        if (empty($body)) {
            $body = $_POST ?: [];
        }

        $form_provider  = $body['provider'] ?? '';
        $form_api_key   = $body['api_key'] ?? '';
        $form_api_url   = $body['api_url'] ?? '';
        $form_model     = $body['model'] ?? '';
        $form_deepl_pro = !empty($body['deepl_pro']);

        $provider = get_option('lisun_llm_provider', 'deepseek');
        // 如果表单选了不同的 provider（用户改了但还没保存），优先用表单值
        if (!empty($form_provider) && $form_provider !== $provider) {
            $provider = $form_provider;
        }

        // Google 翻译连接测试（v3 服务账号 + 术语表 / v2 API Key）
        if ($provider === 'google') {
            $google_mode = (string) get_option('lisun_google_mode', 'v3_glossary');

            if ($google_mode === 'v3_glossary') {
                error_log('LISUN Manual test API (Google v3): project=' . Lisun_Manual_Google_Auth::project()
                    . ' location=' . Lisun_Manual_Google_Auth::location());

                $result = Lisun_Manual_Google_Auth::api(
                    Lisun_Manual_Google_Auth::translate_endpoint(),
                    'POST',
                    [
                        'contents'           => ['Hello world'],
                        'sourceLanguageCode' => 'en',
                        'targetLanguageCode' => 'zh-CN',
                        'mimeType'           => 'text/plain',
                    ],
                    30
                );
                if (is_wp_error($result)) {
                    return new WP_REST_Response(['success' => false, 'data' => ['message' => $result->get_error_message()]], 200);
                }
                $translated = (string) ($result['data']['translations'][0]['translatedText'] ?? '');
                $glossary_name = (string) get_option(Lisun_Manual_V2_Google_Glossary::OPT_NAME, '');
                $glossary_count = (int) get_option(Lisun_Manual_V2_Google_Glossary::OPT_COUNT, 0);
                $notes = [];
                $notes[] = 'v3 + 服务账号';
                $notes[] = '区域 ' . Lisun_Manual_Google_Auth::location();
                $notes[] = $glossary_name === ''
                    ? '术语表：尚未同步（首次翻译时自动创建）'
                    : ('术语表：' . basename($glossary_name) . '（' . $glossary_count . ' 条）');
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => ['message' => 'Google 翻译连接成功！测试翻译: Hello world → ' . $translated
                        . "\n" . implode('；', $notes)],
                ], 200);
            }

            $google_key = (string) get_option('lisun_google_key', '');
            if ($google_key === '') {
                return new WP_REST_Response(['success' => false, 'data' => ['message' =>
                    '当前为 v2 模式，请先填入 Google 翻译 API Key 并保存'
                ]], 200);
            }
            $google_endpoint = trim((string) get_option('lisun_google_endpoint', ''));
            if ($google_endpoint === '') {
                $google_endpoint = 'https://translation.googleapis.com/language/translate/v2';
            }
            $google_url = $google_endpoint
                . (strpos($google_endpoint, '?') === false ? '?' : '&')
                . 'key=' . rawurlencode($google_key);

            error_log('LISUN Manual test API (Google): ' . $google_endpoint);

            $response = wp_remote_post($google_url, [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode([
                    'q'      => ['Hello world'],
                    'target' => 'zh-CN',
                    'format' => 'text',
                ]),
            ]);

            if (is_wp_error($response)) {
                $err = $response->get_error_message();
                error_log('LISUN Manual Google test wp_error: ' . $err);
                return new WP_REST_Response(['success' => false, 'data' => ['message' =>
                    '服务器连接失败（国内服务器通常需要代理或中转）: ' . $err
                ]], 200);
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $google_body = json_decode(wp_remote_retrieve_body($response), true);
            $translated = $google_body['data']['translations'][0]['translatedText'] ?? '';
            if ($code === 200 && $translated !== '') {
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => ['message' => 'Google 翻译连接成功！测试翻译: Hello world → '
                        . html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8')],
                ], 200);
            }
            $err_msg = $google_body['error']['message'] ?? ('HTTP ' . $code);
            error_log('LISUN Manual Google test error: HTTP ' . $code . ' - ' . $err_msg);
            return new WP_REST_Response(['success' => false, 'data' => ['message' =>
                'Google 翻译 API 返回错误: ' . $err_msg
            ]], 200);
        }

        // DeepL 连接测试（共用 lisun_deepseek_key 作为 API 密钥）
        if ($provider === 'deepl') {
            // The test button is explicitly meant to test the values currently
            // visible in the form.  Using a previously saved key first made a
            // newly pasted key appear ineffective until after a page reload.
            $deepl_key = !empty($form_api_key)
                ? sanitize_text_field((string) $form_api_key)
                : get_option('lisun_deepseek_key', '');
            if (empty($deepl_key)) {
                return new WP_REST_Response(['success' => false, 'data' => ['message' =>
                    '请先在 API 密钥字段中输入 DeepL API Key 并保存'
                ]], 200);
            }
            $is_pro = array_key_exists('deepl_pro', (array) $body)
                ? (bool) $form_deepl_pro
                : (bool) get_option('lisun_deepl_pro', false);

            // Relay 中转：与 call_deepl_api 保持一致（国内服务器直连不了 DeepL）
            $relay_url    = get_option('lisun_relay_url', '');
            $relay_secret = get_option('lisun_relay_secret', '');
            $is_relay     = !empty($relay_url) && !empty($relay_secret);
            if ($is_relay) {
                $api_url = rtrim($relay_url, '/') . '/deepl/translate';
            } else {
                $api_url = $is_pro ? 'https://api.deepl.com/v2/translate' : 'https://api-free.deepl.com/v2/translate';
            }

            error_log('LISUN Manual test API (DeepL): ' . $api_url . ($is_relay ? ' (relay)' : ' (direct)'));

            $test_headers = ['Content-Type' => 'application/json'];
            if ($is_relay) {
                $test_headers['X-Relay-Secret'] = $relay_secret;
            } else {
                $test_headers['Authorization'] = 'DeepL-Auth-Key ' . $deepl_key;
            }

            $response = wp_remote_post($api_url, [
                'headers' => $test_headers,
                'body'    => json_encode([
                    'text'        => ['Hello'],
                    'target_lang' => 'ZH',
                ]),
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $err = $response->get_error_message();
                error_log('LISUN Manual DeepL test wp_error: ' . $err);
                return new WP_REST_Response(['success' => false, 'data' => ['message' => '服务器连接失败: ' . $err]], 200);
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && !empty($body['translations'])) {
                $translated = $body['translations'][0]['text'] ?? '';
                $endpoint = $is_pro ? 'Pro' : 'Free';
                $route_note = $is_relay
                    ? '通过 Relay 中转（本页 DeepL key 不参与该请求）'
                    : "DeepL {$endpoint}";
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => ['message' => "{$route_note} 连接成功！测试翻译: Hello → {$translated}"],
                ], 200);
            }

            $err_msg = $body['message'] ?? "HTTP {$code}";
            error_log('LISUN Manual DeepL test error: HTTP ' . $code . ' - ' . $err_msg);
            $route_hint = $is_relay
                ? '（当前通过 Relay 中转，本页 DeepL key 未参与请求；请在 Relay 服务更换 key，或清空 Relay 地址和密钥后直连）'
                : '';
            return new WP_REST_Response(['success' => false, 'data' => ['message' => 'DeepL API 返回错误: ' . $err_msg . $route_hint]], 200);
        }

        // OpenAI 兼容 API 连接测试
        $api_key = get_option('lisun_deepseek_key', '');
        if (empty($api_key) && !empty($form_api_key)) {
            $api_key = $form_api_key; // 回退到表单值
        }
        if (empty($api_key)) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '请填写 API 密钥并先点击"保存更改"']], 200);
        }

        $api_url = get_option('lisun_llm_api_url', '');
        if (empty($api_url) && !empty($form_api_url)) {
            $api_url = $form_api_url;
        }
        if (empty($api_url)) {
            $api_url = 'https://api.deepseek.com/chat/completions';
        }

        $model = get_option('lisun_deepseek_model', 'deepseek-chat');
        if ((empty($model) || $model === 'deepseek-chat') && !empty($form_model)) {
            $model = $form_model;
        }
        if (empty($model)) {
            $model = 'deepseek-chat';
        }
        $timeout = intval(get_option('lisun_deepseek_timeout', 30));

        // Relay 中转: 如果配置了 relay，通过中转发送请求
        $relay_url    = get_option('lisun_relay_url', '');
        $relay_secret = get_option('lisun_relay_secret', '');
        $use_relay    = !empty($relay_url) && !empty($relay_secret);

        if ($use_relay) {
            // 通过 relay 中转，relay 会注入真正的 API key
            $relay_path     = str_replace(['https://', 'http://'], '', untrailingslashit($relay_url));
            $relay_provider = $provider;
            if ($relay_provider === 'gemini') {
                $actual_url = $relay_url . '/gemini/openai/chat/completions';
            } elseif ($relay_provider === 'deepl') {
                $actual_url = $relay_url . '/deepl/translate';
            } else {
                $actual_url = $relay_url . '/chat/completions';
            }
            $test_headers = [
                'Content-Type'   => 'application/json',
                'X-Relay-Secret' => $relay_secret,
            ];
            // 仍传 API key 但 relay 会覆盖（兼容深层 API 鉴权）
            if (!empty($api_key)) {
                $test_headers['Authorization'] = 'Bearer ' . $api_key;
            }
            error_log('LISUN Manual test API (REST via relay): provider=' . $provider . ' url=' . $actual_url . ' model=' . $model);
            $test_url = $actual_url;
        } else {
            $test_headers = [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ];
            error_log('LISUN Manual test API (REST): URL=' . $api_url . ' model=' . $model);
            $test_url = $api_url;
        }

        $response = wp_remote_post($test_url, [
            'headers' => $test_headers,
            'body'    => json_encode([
                'model'       => $model,
                'max_tokens'  => 16,
                'temperature' => 0,
                'messages'    => [
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ]),
            'timeout' => max($timeout, 15),
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            error_log('LISUN Manual test API wp_error: ' . $err);
            return new WP_REST_Response(['success' => false, 'data' => ['message' => '服务器连接失败: ' . $err]], 200);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['choices'])) {
            $reply = $body['choices'][0]['message']['content'] ?? '';
            $usage = isset($body['usage']['total_tokens']) ? '（tokens: ' . $body['usage']['total_tokens'] . '）' : '';
            return new WP_REST_Response([
                'success' => true,
                'data'    => ['message' => "{$provider} API 连接成功！模型: {$model}，回复: {$reply}{$usage}"],
            ], 200);
        }

        $err_msg = $body['error']['message'] ?? "HTTP {$code}";
        error_log('LISUN Manual test API error: HTTP ' . $code . ' - ' . $err_msg);
        return new WP_REST_Response(['success' => false, 'data' => ['message' => 'API 返回错误: ' . $err_msg]], 200);
    }

    /**
     * AJAX: 测试远程站连接
     */
    public static function ajax_test_connection() {
        if (!wp_verify_nonce($_POST['_nonce'] ?? '', 'lisun_manual_nonce')) {
            wp_send_json_error(['message' => 'Nonce error']);
        }

        $result = Lisun_Manual_Sync::test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: 测试 DeepSeek API 连接
     */
    public static function ajax_test_deepseek() {
        ob_start();

        if (!wp_verify_nonce($_POST['_nonce'] ?? '', 'lisun_manual_nonce')) {
            wp_send_json_error(['message' => '安全验证失败，请刷新页面后重试']);
        }

        $api_key = get_option('lisun_deepseek_key', '');
        if (empty($api_key)) {
            wp_send_json_error(['message' => '请先填写 API 密钥并保存设置']);
        }

        $api_url = get_option('lisun_llm_api_url', '');
        if (empty($api_url)) {
            $api_url = 'https://api.deepseek.com/chat/completions';
        }

        $model   = get_option('lisun_deepseek_model', 'deepseek-chat');
        if (empty($model)) {
            $model = 'deepseek-chat';
        }
        $timeout = intval(get_option('lisun_deepseek_timeout', 30));

        // Relay 中转: 如果配置了 relay，通过中转发送请求
        $relay_url    = get_option('lisun_relay_url', '');
        $relay_secret = get_option('lisun_relay_secret', '');
        $provider     = get_option('lisun_llm_provider', 'deepseek');
        $use_relay    = !empty($relay_url) && !empty($relay_secret);

        if ($use_relay) {
            if ($provider === 'gemini') {
                $actual_url = $relay_url . '/gemini/openai/chat/completions';
            } elseif ($provider === 'deepl') {
                $actual_url = $relay_url . '/deepl/translate';
            } else {
                $actual_url = $relay_url . '/chat/completions';
            }
            $test_headers = [
                'Content-Type'   => 'application/json',
                'X-Relay-Secret' => $relay_secret,
            ];
            if (!empty($api_key)) {
                $test_headers['Authorization'] = 'Bearer ' . $api_key;
            }
            error_log('LISUN Manual test API (via relay): provider=' . $provider . ' url=' . $actual_url . ' model=' . $model);
            $test_url = $actual_url;
        } else {
            $test_headers = [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ];
            error_log('LISUN Manual test API: URL=' . $api_url . ' model=' . $model);
            $test_url = $api_url;
        }

        $response = wp_remote_post($test_url, [
            'headers' => $test_headers,
            'body'    => json_encode([
                'model'       => $model,
                'max_tokens'  => 16,
                'temperature' => 0,
                'messages'    => [
                    ['role' => 'user', 'content' => 'Hi'],
                ],
            ]),
            'timeout' => max($timeout, 15),
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            error_log('LISUN Manual test API wp_error: ' . $err);
            wp_send_json_error(['message' => '服务器连接失败: ' . $err]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['choices'])) {
            $reply = $body['choices'][0]['message']['content'] ?? '';
            $usage = isset($body['usage']['total_tokens']) ? '（tokens: ' . $body['usage']['total_tokens'] . '）' : '';
            $provider = get_option('lisun_llm_provider', 'deepseek');
            wp_send_json_success(['message' => "{$provider} API 连接成功！模型: {$model}，回复: {$reply}{$usage}"]);
        }

        $err_msg = $body['error']['message'] ?? "HTTP {$code}";
        error_log('LISUN Manual test API error: HTTP ' . $code . ' - ' . $err_msg);
        wp_send_json_error(['message' => 'API 返回错误: ' . $err_msg]);
    }
}
