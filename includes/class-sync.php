<?php
/**
 * 双站同步模块
 * 本地站 ↔ 远程站 REST API 通信
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-table-layout.php';

class Lisun_Manual_Sync {

    /**
     * 编码远程 REST 请求正文，确保非法 UTF-8 不会让 json_encode 返回 false。
     * Requests 的 cURL 传输层只接受 array|string，不能把 false 作为 body 传入。
     */
    private static function encode_json_body($value, $context = 'sync') {
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($value, $flags)
            : json_encode($value, $flags);
        if (!is_string($encoded)) {
            error_log('LISUN Manual sync JSON encoding failed context=' . $context);
            return '{}';
        }
        return $encoded;
    }

    public static function init() {
        // 内部同步端点：接收翻译数据，直接用 wp_insert_post + update_post_meta
        // 绕过 WP REST API 的 show_in_rest 限制
        add_action('rest_api_init', function () {
            register_rest_route('lisun-manual/v1', '/sync', [
                'methods'  => 'POST',
                'callback' => [__CLASS__, 'rest_receive_translation'],
                'permission_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ]);
        });
    }

    /**
     * REST: 接收翻译数据，创建/更新 manual post
     *
     * 用 wp_insert_post/wp_update_post 直接操作，meta 通过 update_post_meta 写入，
     * 不依赖 WP REST API 的 meta 参数（无需远程站注册 show_in_rest）。
     */
    public static function rest_receive_translation($request) {
        $title   = sanitize_text_field($request->get_param('title') ?? '');
        $content = $request->get_param('content') ?? '';
        $slug    = sanitize_title($request->get_param('slug') ?? '');
        $status  = sanitize_text_field($request->get_param('status') ?? 'publish');
        $meta    = $request->get_param('meta') ?? [];
        $tags    = $request->get_param('tags') ?? [];
        $source_id = intval($request->get_param('source_id') ?? 0);

        if (empty($title) || empty($content)) {
            return new WP_REST_Response(['success' => false, 'message' => 'title 和 content 必填'], 200);
        }
        if ($source_id <= 0) {
            return new WP_REST_Response(['success' => false, 'message' => 'source_id 必填，拒绝无身份同步'], 200);
        }

        // JSON 可以合法携带 NUL，但 MySQL/WP 写文章时会拒绝这类字节。
        // 只移除不可见的 NUL，不改变正文 HTML、中文或英文内容。
        $title = str_replace("\0", '', (string) $title);
        $content = str_replace("\0", '', (string) $content);

        // 检查是否已存在（只能通过 source_id meta，不能用 slug ——
        // 同名产品 slug 相同会误匹配，导致后推送的覆盖先推送的远程页）
        $existing_id = 0;
        $found_ids = get_posts([
            'post_type'      => 'manual',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_lisun_manual_source_id',
            'meta_value'     => (string) $source_id,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        $source_match = self::resolve_unique_source_match($found_ids);
        if ($source_match['status'] === 'duplicate') {
            error_log('LISUN Manual remote sync refused duplicate source_id=' . $source_id
                . ' target_ids=' . implode(',', $source_match['ids']));
            return new WP_REST_Response([
                'success' => false,
                'message' => '远程站存在多个相同 source_id 的文章，已停止同步以防止错误覆盖：'
                    . implode(',', $source_match['ids']),
                'source_id' => $source_id,
                'target_ids' => $source_match['ids'],
            ], 200);
        }
        if ($source_match['status'] === 'one') $existing_id = (int) $source_match['id'];

        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => 'manual',
            'post_name'    => $slug ?: sanitize_title($title),
        ];

        // 临时关闭 KSES 过滤，避免 <style> 标签被 WordPress 剥离
        // （非管理员用户通过 REST API 插入内容时，wp_insert_post 会执行 wp_kses_post）
        kses_remove_filters();

        if ($existing_id) {
            $post_data['ID'] = $existing_id;
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        // 恢复 KSES 过滤
        kses_init_filters();

        if (is_wp_error($post_id)) {
            global $wpdb;
            error_log('LISUN Manual remote database write failed source_id=' . $source_id
                . ' target_id=' . (int) $existing_id
                . ' operation=' . ($existing_id ? 'update' : 'insert')
                . ' title_chars=' . mb_strlen($title)
                . ' content_bytes=' . strlen($content)
                . ' utf8=' . (preg_match('//u', $content) === 1 ? 'yes' : 'no')
                . ' wp_error="' . $post_id->get_error_message() . '"'
                . ' db_error="' . (isset($wpdb) ? (string) $wpdb->last_error : '') . '"');
            return new WP_REST_Response(['success' => false, 'message' => $post_id->get_error_message()], 200);
        }

        // 写入 meta（直接操作，不依赖 show_in_rest）
        if (!empty($meta) && is_array($meta)) {
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
        }

        // 设置标签
        if (!empty($tags) && is_array($tags)) {
            $tag_names = array_map(function($t) {
                return is_array($t) ? ($t['name'] ?? '') : strval($t);
            }, $tags);
            $tag_names = array_filter($tag_names);
            if (!empty($tag_names)) {
                wp_set_post_terms($post_id, $tag_names, 'post_tag');
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'id'      => $post_id,
            'action'  => $existing_id ? 'updated' : 'created',
        ], 200);
    }

    /** Resolve a source lookup without ever choosing arbitrarily among duplicates. */
    public static function resolve_unique_source_match($ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        sort($ids, SORT_NUMERIC);
        if (count($ids) > 1) {
            return ['status' => 'duplicate', 'id' => 0, 'ids' => $ids];
        }
        if (count($ids) === 1) {
            return ['status' => 'one', 'id' => (int) $ids[0], 'ids' => $ids];
        }
        return ['status' => 'none', 'id' => 0, 'ids' => []];
    }

    /**
     * 直接推送翻译内容到远程站（不走本地 Post）
     *
     * @param int    $zh_post_id 中文源页面 ID
     * @param string $en_title   英文标题
     * @param string $en_content 英文正文（已含 TOC + 附录 + 内联 CSS）
     * @param string $en_slug    英文 slug（型号）
     * @param array  $en_seo     已翻译的 SEO 字段（title / description / keywords），为空时自动取中文原文
     * @return int|WP_Error 远程站页面 ID
     */
    public static function push_translation($zh_post_id, $en_title, $en_content, $en_slug, $en_seo = []) {
        $url = get_option('lisun_en_site_url', '');
        $username = get_option('lisun_en_site_username', '');
        $password = get_option('lisun_en_site_password', '');

        if (empty($url) || empty($username) || empty($password)) {
            return new WP_Error('no_config', __('远程站连接未配置', 'lisun-manual'));
        }

        // 本地内容可保留术语锚点，但公开远程站只接收干净正文。
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'strip_glossary_anchors')) {
            $en_title = Lisun_Manual_Translate::strip_glossary_anchors($en_title);
            $en_content = Lisun_Manual_Translate::strip_glossary_anchors($en_content);
            foreach ($en_seo as $seo_key => $seo_value) {
                if (is_string($seo_value)) {
                    $en_seo[$seo_key] = Lisun_Manual_Translate::strip_glossary_anchors($seo_value);
                }
            }
        }
        // 防止 JSON 解码后的 NUL 字节进入远程 wp_update_post()。
        $en_title = str_replace("\0", '', (string) $en_title);
        $en_content = str_replace("\0", '', (string) $en_content);
        foreach ($en_seo as $seo_key => $seo_value) {
            if (is_string($seo_value)) {
                $en_seo[$seo_key] = str_replace("\0", '', $seo_value);
            }
        }
        $content = self::prepare_content_for_remote($en_content);

        // 用自定义端点同步（绕过 WP REST API meta/show_in_rest 限制）
        $api_url = trailingslashit($url) . 'wp-json/lisun-manual/v1/sync';
        $auth = base64_encode($username . ':' . $password);

        // 构建 meta
        $meta = [
            '_lisun_manual_lang'      => 'en',
            '_lisun_manual_source_id' => $zh_post_id,
        ];
        // SEO 字段：只推送已翻译的英文，同时写入自有 meta box + Rank Math 字段
        $seo_map = [
            'title'       => ['title', 'rank_math_title'],
            'description' => ['description', 'rank_math_description'],
            'keywords'    => ['keywords', 'rank_math_focus_keyword'],
        ];
        foreach ($seo_map as $seo_key => $meta_keys) {
            if (!empty($en_seo[$seo_key])) {
                foreach ($meta_keys as $mk) {
                    $meta[$mk] = $en_seo[$seo_key];
                }
            }
            // 如果没翻译成功，不推送该字段
        }
        // 产品型号无需翻译，直接复制
        $product_no = get_post_meta($zh_post_id, 'product-no', true);
        if (!empty($product_no)) {
            $meta['product-no'] = $product_no;
        }

        // 软件与驱动下载信息属于文章自定义字段，不在正文中。
        // 同步该字段后，远程 single-manual 模板才能渲染“附录、相关下载”区域。
        $software = get_post_meta($zh_post_id, '_lisun_manual_software', true);
        if (!empty($software)) {
            $meta['_lisun_manual_software'] = is_string($software)
                ? $software
                : wp_json_encode($software, JSON_UNESCAPED_UNICODE);
        }

        // 收集 tags
        $tag_terms = wp_get_post_terms($zh_post_id, 'post_tag', ['fields' => 'names']);
        $tags = !empty($tag_terms) ? array_map(function($n) { return ['name' => $n]; }, $tag_terms) : [];

        $body = [
            'title'     => $en_title,
            'content'   => $content,
            'slug'      => $en_slug,
            'status'    => 'publish',
            'source_id' => $zh_post_id,
            'meta'      => $meta,
            'tags'      => $tags,
        ];

        $json_body = self::encode_json_body($body, 'push_translation');
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log('remote push request source_id=' . (int) $zh_post_id
                . ' endpoint=' . esc_url_raw($api_url)
                . ' title_chars=' . mb_strlen((string) $en_title)
                . ' content_bytes=' . strlen((string) $content)
                . ' body_bytes=' . strlen((string) $json_body));
        }

        $response = wp_remote_post($api_url, [
            'headers' => ['Authorization' => 'Basic ' . $auth, 'Content-Type' => 'application/json'],
            'body'    => $json_body,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            error_log('LISUN Manual sync push error: ' . $response->get_error_message());
            if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
                Lisun_Manual_Translate::diagnostic_log('remote push response source_id=' . (int) $zh_post_id
                    . ' transport_error="' . $response->get_error_message() . '"');
            }
            return new WP_Error('http_error', $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_text = wp_remote_retrieve_body($response);
        $data = json_decode($body_text, true);

        error_log('LISUN Manual sync push: HTTP ' . $http_code . ' body: ' . substr($body_text, 0, 300));
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log('remote push response source_id=' . (int) $zh_post_id
                . ' http=' . (int) $http_code
                . ' body=' . substr((string) $body_text, 0, 1000));
        }

        if (!$data || empty($data['success'])) {
            $message = $data['message'] ?? ('HTTP ' . $http_code);
            return new WP_Error(
                'api_error',
                '远程同步接口错误（' . esc_url_raw($api_url) . '）：' . $message
            );
        }

        $remote_id = intval($data['id'] ?? 0);
        update_post_meta($zh_post_id, '_lisun_manual_remote_id', $remote_id);
        return $remote_id;
    }

    /**
     * 把中文页面已翻译的本地英文内容推送到远程站
     *
     * @param int $zh_post_id 中文 manual 页面 ID
     * @return int|WP_Error 远程站页面 ID 或错误
     */
    public static function push_existing_translation($zh_post_id) {
        $start_time = microtime(true);
        $zh_post = get_post($zh_post_id);
        if (!$zh_post || $zh_post->post_type !== 'manual') {
            return new WP_Error('invalid_post', __('无效页面', 'lisun-manual'));
        }

        // 找本地英文译文
        $en_post_id = get_post_meta($zh_post_id, '_lisun_manual_target_id', true);
        if (!$en_post_id || !get_post($en_post_id)) {
            return new WP_Error('no_translation', __('未找到本地英文译文，请先翻译', 'lisun-manual'));
        }

        $en_post = get_post($en_post_id);

        // 检查同步锁定
        $locked = get_post_meta($zh_post_id, '_lisun_manual_lock_sync', true);
        if ($locked) {
            return new WP_Error('locked', __('页面同步已锁定', 'lisun-manual'));
        }

        // 检查页面级英文锁定（方案 A）：锁定后禁止推送覆盖远程站人工编辑
        if (class_exists('Lisun_Manual_Translate') && Lisun_Manual_Translate::is_en_locked($zh_post_id)) {
            return new WP_Error('en_locked', __('该页面英文已锁定，请先解锁', 'lisun-manual'));
        }

        // 修复旧数据的图注
        $fixed_content = Lisun_Manual_Translate::merge_figure_captions_in_html($en_post->post_content);

        // 重试推送不能重新翻译 SEO。优先使用已有备份，没有就不发送，
        // 避免重试操作因额外 API 请求超时或把中文 SEO 写到远程站。
        $backup = Lisun_Manual_Translate::get_translation_backup($zh_post_id);
        $en_seo = is_array($backup) && !empty($backup['en_seo'])
            ? (array) $backup['en_seo']
            : [];

        // 统一走自定义同步端点。它按 source_id 唯一匹配，不能用 slug
        // 或“查询结果第一篇”决定目标，避免把另一篇文章正文覆盖掉。
        $remote_id = self::push_translation(
            $zh_post_id,
            $en_post->post_title,
            $fixed_content,
            (string) $zh_post->post_name,
            $en_seo
        );

        if (is_wp_error($remote_id)) {
            update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'push_failed');
            Lisun_Manual_Translate_Log::add([
                'zh_page_id' => $zh_post_id, 'en_page_id' => $en_post_id,
                'status' => 'repush_failed', 'duration' => microtime(true) - $start_time,
                'error_msg' => $remote_id->get_error_message(),
            ]);
            return $remote_id;
        }

        // 更新关联（本地英文 ID + 远程英文 ID）
        update_post_meta($zh_post_id, '_lisun_manual_target_id', $en_post_id);
        update_post_meta($zh_post_id, '_lisun_manual_remote_id', $remote_id);
        update_post_meta($zh_post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($zh_post_id, '_lisun_manual_last_sync', current_time('mysql'));

        // 推送成功后，把本地英文 post 同步回翻译备份（含按块重新对齐段数据），
        // 让后续增量翻译能复用人工编辑过的英文。
        if (class_exists('Lisun_Manual_Translate')) {
            Lisun_Manual_Translate::resync_backup_from_local_en($zh_post_id);
        }

        Lisun_Manual_Translate_Log::add([
            'zh_page_id' => $zh_post_id, 'en_page_id' => $en_post_id,
            'status' => 'repush_success', 'duration' => microtime(true) - $start_time,
        ]);

        return $remote_id;
    }

    /**
     * 在远程站查找 source_id 对应的页面
     */
    private static function find_remote_by_source($zh_post_id) {
        $url = get_option('lisun_en_site_url', '');
        $username = get_option('lisun_en_site_username', '');
        $password = get_option('lisun_en_site_password', '');

        if (empty($url) || empty($username) || empty($password)) {
            return false;
        }

        $api_url = trailingslashit($url) . 'wp-json/wp/v2/manual?meta_key=_lisun_manual_source_id&meta_value=' . $zh_post_id;
        $auth = base64_encode($username . ':' . $password);

        $response = wp_remote_get($api_url, [
            'headers' => ['Authorization' => 'Basic ' . $auth],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data) && is_array($data)) {
            return intval($data[0]['id']);
        }

        return false;
    }

    /**
     * 同步单个页面（兼容旧接口）
     *
     * 如果是中文页面且有本地英文译文，优先推送译文内容。
     * 如果是英文页面或没有译文，直接推送当前内容。
     */
    public static function sync_single($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'manual') {
            return new WP_Error('invalid_post', __('无效页面', 'lisun-manual'));
        }

        $lang = get_post_meta($post_id, '_lisun_manual_lang', true) ?: 'zh';

        // 中文页面：如果已有译文，委托给 push_existing_translation 推送英文内容
        if ($lang === 'zh') {
            $target_id = get_post_meta($post_id, '_lisun_manual_target_id', true);
            if ($target_id && get_post($target_id)) {
                return self::push_existing_translation($post_id);
            }
        }

        // 检查是否被锁定
        $locked = get_post_meta($post_id, '_lisun_manual_lock_sync', true);
        if ($locked) {
            return new WP_Error('locked', __('页面同步已锁定', 'lisun-manual'));
        }

        // 同步到远程站（英文页面直接推送，或中文页面首次推送）
        $push_content = $post->post_content;
        if (get_post_meta($post_id, '_lisun_manual_lang', true) === 'en') {
            $push_content = Lisun_Manual_Translate::merge_figure_captions_in_html($push_content);
        }
        // 中文页面推送时翻译 SEO 字段
        $en_seo = ($lang === 'zh') ? Lisun_Manual_Translate::translate_seo_meta($post_id) : [];
        $remote_target = get_post_meta($post_id, '_lisun_manual_remote_id', true);
        $result = self::push_to_english(
            $post->post_title,
            $push_content,
            $post_id,
            $remote_target ?: null,
            $post_id,
            $en_seo
        );

        if (is_wp_error($result)) {
            update_post_meta($post_id, '_lisun_manual_sync_status', 'failed');
            return $result;
        }

        update_post_meta($post_id, '_lisun_manual_remote_id', $result);
        update_post_meta($post_id, '_lisun_manual_sync_status', 'synced');
        update_post_meta($post_id, '_lisun_manual_last_sync', current_time('mysql'));

        return $result;
    }

    public static function push_to_english($title, $content, $source_id, $target_id = null, $seo_source_id = null, $en_seo = []) {
        $url = get_option('lisun_en_site_url', '');
        $username = get_option('lisun_en_site_username', '');
        $password = get_option('lisun_en_site_password', '');

        if (empty($url) || empty($username) || empty($password)) {
            return new WP_Error('no_config', __('远程站连接未配置', 'lisun-manual'));
        }

        // 本地译文保留术语锚点，公开远程站不应暴露插件内部 span。
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'strip_glossary_anchors')) {
            $title = Lisun_Manual_Translate::strip_glossary_anchors($title);
            $content = Lisun_Manual_Translate::strip_glossary_anchors($content);
            foreach ($en_seo as $seo_key => $seo_value) {
                if (is_string($seo_value)) {
                    $en_seo[$seo_key] = Lisun_Manual_Translate::strip_glossary_anchors($seo_value);
                }
            }
        }

        // 预处理内容：替换图片 ID 为完整 URL，远程站才能显示
        $content = self::prepare_content_for_remote($content);

        $api_url = trailingslashit($url) . 'wp-json/wp/v2/manual';
        $auth = base64_encode($username . ':' . $password);

        $body = [
            'title'   => $title,
            'content' => $content,
            'status'  => 'publish',
            'meta'    => [
                '_lisun_manual_lang'       => 'en',
                '_lisun_manual_source_id'  => $source_id,
            ],
        ];

        // 保持 slug 不变（型号），从中文原站取 post_name
        $source_post = get_post($source_id);
        if ($source_post && !empty($source_post->post_name)) {
            $body['slug'] = $source_post->post_name;
        }

        // 附加 SEO meta + Rank Math 字段（只推送已翻译的英文，避免中文泄漏）
        if ($seo_source_id) {
            $seo_fields = [
                'title'                  => 'title',
                'description'            => 'description',
                'keywords'               => 'keywords',
                'product-no'             => 'product-no',
                'rank_math_title'        => 'title',
                'rank_math_description'  => 'description',
                'rank_math_focus_keyword'=> 'keywords',
            ];
            foreach ($seo_fields as $remote_key => $local_key) {
                if ($remote_key === 'product-no') {
                    // 产品型号无需翻译，直接复制
                    $val = get_post_meta($seo_source_id, 'product-no', true);
                    if (!empty($val)) $body['meta']['product-no'] = $val;
                } elseif (!empty($en_seo[$local_key])) {
                    // 只有翻译成功才推送，不推送中文原文
                    $body['meta'][$remote_key] = $en_seo[$local_key];
                }
                // 如果没翻译成功，不推送该字段
            }

        }

        // 软件与驱动下载信息属于文章自定义字段，不在正文中。
        // 与自定义同步接口保持一致，REST 推送更新时也必须带上该字段。
        $software_source_id = $seo_source_id ?: $source_id;
        $software = get_post_meta($software_source_id, '_lisun_manual_software', true);
        if (!empty($software)) {
            $body['meta']['_lisun_manual_software'] = is_string($software)
                ? $software
                : wp_json_encode($software, JSON_UNESCAPED_UNICODE);
        }

        // 如果是更新已有页面
        if ($target_id) {
            $api_url .= '/' . intval($target_id);
            $response = wp_remote_request($api_url, [
                'method'  => 'PUT',
                'headers' => [
                    'Authorization' => 'Basic ' . $auth,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => self::encode_json_body($body, 'push_to_english_put'),
                'timeout' => 60,
            ]);

            // PUT 权限被拒（403/401）→ 降级为新建，避免因用户权限变化导致推送失败
            $put_code = wp_remote_retrieve_response_code($response);
            if (in_array($put_code, [401, 403])) {
                error_log('LISUN Manual sync: PUT ' . $target_id . ' got ' . $put_code . ', fallback to POST new');
                $api_url = trailingslashit($url) . 'wp-json/wp/v2/manual';
                $response = wp_remote_post($api_url, [
                    'headers' => [
                        'Authorization' => 'Basic ' . $auth,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => self::encode_json_body($body, 'push_to_english_fallback'),
                    'timeout' => 60,
                ]);
            }
        } else {
            $response = wp_remote_post($api_url, [
                'headers' => [
                    'Authorization' => 'Basic ' . $auth,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => self::encode_json_body($body, 'push_to_english_post'),
                'timeout' => 60,
            ]);
        }

        if (is_wp_error($response)) {
            return new WP_Error('http_error', $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_text = wp_remote_retrieve_body($response);
        $data = json_decode($body_text, true);

        if (!in_array($http_code, [200, 201])) {
            $error_msg = $data['message'] ?? ('HTTP ' . $http_code);
            return new WP_Error('api_error', __('远程站 API 错误：', 'lisun-manual') . $error_msg);
        }

        return intval($data['id'] ?? 0);
    }

    /**
     * 预处理推送到远程的内容
     * - 相对路径图片补全域名
     *
     * 注意：不再调用 do_blocks() / wp_filter_content_tags()。它们会基于附件元数据
     * 重写 <img> 的 srcset/sizes/loading/fetchpriority，可能覆盖插件自己拼装的属性
     * （图片属性已由 lisun_manual_make_images_responsive 在前台渲染时统一处理）。
     */
    private static function prepare_content_for_remote($content) {
        if (class_exists('Lisun_Manual_Translate')) {
            Lisun_Manual_Translate::debug_numeric_format('before_push_prepare', $content);
        }
        // DeepL/LLM 偶尔会把 HTML 属性中的 URL 改成 Markdown 链接：
        // src="[https://a.test/x.jpg](https://a.test/x.jpg)"。
        // 这不是合法的图片地址，浏览器会把整个字符串当作 URL，最终出现裂图。
        // 只清理 URL 属性，避免误改正文中的普通 Markdown 链接。
        $content = preg_replace_callback(
            '/\b(src|href|poster|action|data-src|data-lazy-src|srcset)\s*=\s*(["\'])(.*?)\2/is',
            function ($m) {
                $value = preg_replace_callback(
                    '/\[\s*(https?:\/\/[^\]\s]+)\s*\]\(\s*(https?:\/\/[^\s\)]+)\s*\)/i',
                    function ($u) {
                        return $u[2];
                    },
                    $m[3]
                );
                return $m[1] . '=' . $m[2] . $value . $m[2];
            },
            $content
        );
        // 推送前对完整 HTML 再做一次数字统一，覆盖表格等可能未经过文本节点后处理的内容。
        $content = preg_replace('/(?<![\d,])(\d{1,3}),[\s\x{00A0}\x{202F}]*(\d{3})(?!\d)/u', '$1$2', $content);
        if (class_exists('Lisun_Manual_Translate')) {
            Lisun_Manual_Translate::debug_numeric_format('after_push_prepare', $content);
        }
        // 同步时给 LISUN 型号/编号表格加统一布局标记，确保远程站的新文章
        // 与一次性修复脚本及本地前台使用同一套首列/后续列规则。
        $content = Lisun_Manual_Table_Layout::apply_to_content($content);

        $site_url = get_site_url();

        // 图片相对路径 → 绝对 URL（中文站域名）
        $content = preg_replace_callback(
            '/<img\s+[^>]*src="(\/[^"]+)"/',
            function ($m) use ($site_url) {
                $src = $m[1];
                if (strpos($src, '://') === false) {
                    return str_replace($m[1], $site_url . $src, $m[0]);
                }
                return $m[0];
            },
            $content
        );

        // srcset 中的相对路径 → 绝对 URL
        $content = preg_replace_callback(
            '/srcset="([^"]*)"/',
            function ($m) use ($site_url) {
                $fixed = preg_replace_callback(
                    '/(https?:\/\/[^\s,]+|(\/[^\s,]+))(\s+\d+[wx])?/',
                    function ($sm) use ($site_url) {
                        $url = $sm[1];
                        if (strpos($url, '://') === false) {
                            return $site_url . $url . ($sm[3] ?? '');
                        }
                        return $sm[0];
                    },
                    $m[1]
                );
                return 'srcset="' . $fixed . '"';
            },
            $content
        );

        // 剥离内联排版样式（font-size / font-family / line-height 等），统一由推送 CSS 控制
        // 复用 class-translate.php 中的通用清洗方法，保持两处逻辑一致
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'strip_inline_styles')) {
            $content = Lisun_Manual_Translate::strip_inline_styles($content);
        }

        // 嵌入关键样式（表格、图片框、内容排版），远程站即使没装插件也能正常显示
        $inline_css = '<style>' . self::get_push_css() . '</style>';

        return $inline_css . "\n" . '<div class="lisun-manual-content">' . $content . '</div>';
    }

    /**
     * 测试远程站连接
     */
    public static function test_connection() {
        $url = get_option('lisun_en_site_url', '');
        $username = get_option('lisun_en_site_username', '');
        $password = get_option('lisun_en_site_password', '');

        if (empty($url) || empty($username) || empty($password)) {
            return new WP_Error('no_config', __('连接信息不完整', 'lisun-manual'));
        }

        // A GET to wp/v2/manual only proves that the remote site is readable.
        // V2 publishing uses our authenticated internal sync endpoint instead,
        // so test that exact endpoint with an intentionally incomplete payload.
        // It must return our validation message without creating any post.
        $api_url = trailingslashit($url) . 'wp-json/lisun-manual/v1/sync';
        $auth = base64_encode($username . ':' . $password);

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json',
            ],
            'body'    => '{}',
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('http_error', $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code === 200
            && is_array($data)
            && array_key_exists('success', $data)
            && empty($data['success'])
            && ($data['message'] ?? '') === 'title 和 content 必填') {
            return [
                'status' => 'ok',
                'message' => '远程同步接口可用：已验证 Application Password、写入权限和同步路径（未创建测试文章）',
            ];
        }

        $remote_message = is_array($data) ? (string) ($data['message'] ?? '') : '';
        $remote_code = is_array($data) ? (string) ($data['code'] ?? '') : '';
        if ($remote_code === 'rest_no_route') {
            return new WP_Error(
                'sync_endpoint_missing',
                '远程站缺少 V2 同步接口 ' . esc_url_raw($api_url) . '。请把同一版本插件更新到远程站。'
            );
        }
        if ($remote_code === 'rest_forbidden' || $http_code === 401 || $http_code === 403) {
            return new WP_Error(
                'sync_permission_denied',
                '远程同步接口拒绝了 Application Password（' . esc_url_raw($api_url) . '）：' . ($remote_message ?: 'HTTP ' . $http_code)
            );
        }
        return new WP_Error(
            'bad_response',
            '远程同步接口验证失败（' . esc_url_raw($api_url) . '）：' . ($remote_message ?: 'HTTP ' . $http_code)
        );
    }

    /**
     * 锁定/解锁同步
     */
    public static function toggle_lock($post_id, $lock = true) {
        update_post_meta($post_id, '_lisun_manual_lock_sync', $lock);
    }

    /**
     * 删除远程站页面
     *
     * @param int  $remote_id  远程站页面 ID
     * @param bool $force      是否永久删除（true=永久，false=移到回收站）
     * @return bool|WP_Error
     */
    public static function delete_remote($remote_id, $force = false) {
        $url = get_option('lisun_en_site_url', '');
        $username = get_option('lisun_en_site_username', '');
        $password = get_option('lisun_en_site_password', '');

        if (empty($url) || empty($username) || empty($password)) {
            return new WP_Error('no_config', __('远程站连接未配置，远程译文未删除', 'lisun-manual'));
        }

        $api_url = trailingslashit($url) . 'wp-json/wp/v2/manual/' . intval($remote_id);
        if ($force) {
            $api_url .= '?force=true';
        }

        $auth = base64_encode($username . ':' . $password);

        $response = wp_remote_request($api_url, [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);

        if (!in_array($http_code, [200, 204])) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return new WP_Error(
                'delete_failed',
                __('删除远程译文失败：HTTP ', 'lisun-manual') . $http_code . ' — ' . ($body['message'] ?? '')
            );
        }

        return true;
    }

    /**
     * 推送术语库到远程站
     *
     * @param array $glossary 术语库数组 [['zh' => '', 'en' => '', 'note' => ''], ...]
     * @return array|WP_Error 成功返回 ['message' => string, 'total' => int]
     */
    public static function push_glossary($glossary) {
        $url = get_option('lisun_en_site_url', '');
        $username = get_option('lisun_en_site_username', '');
        $password = get_option('lisun_en_site_password', '');

        if (empty($url) || empty($username) || empty($password)) {
            return new WP_Error('no_config', __('远程站连接未配置', 'lisun-manual'));
        }

        $api_url = trailingslashit($url) . 'wp-json/lisun-manual/v1/glossary';
        $auth = base64_encode($username . ':' . $password);

        $response = wp_remote_post($api_url, [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json',
            ],
            'body'    => self::encode_json_body(['glossary' => $glossary], 'push_glossary'),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('http_error', $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_text = wp_remote_retrieve_body($response);
        $data = json_decode($body_text, true);

        if (!in_array($http_code, [200, 201])) {
            $error_msg = $data['message'] ?? ('HTTP ' . $http_code);
            return new WP_Error('api_error', __('术语库推送失败：', 'lisun-manual') . $error_msg);
        }

        return [
            'message' => sprintf(__('已推送 %d 条术语到远程站', 'lisun-manual'), count($glossary)),
            'total'   => count($glossary),
        ];
    }

    /**
     * 从远程站拉取术语库
     *
     * @return array|WP_Error 成功返回 ['glossary' => [...], 'total' => int]
     */
    public static function pull_glossary() {
        $url = get_option('lisun_en_site_url', '');
        $username = get_option('lisun_en_site_username', '');
        $password = get_option('lisun_en_site_password', '');

        if (empty($url) || empty($username) || empty($password)) {
            return new WP_Error('no_config', __('远程站连接未配置', 'lisun-manual'));
        }

        $api_url = trailingslashit($url) . 'wp-json/lisun-manual/v1/glossary';
        $auth = base64_encode($username . ':' . $password);

        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('http_error', $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_text = wp_remote_retrieve_body($response);
        $data = json_decode($body_text, true);

        if ($http_code !== 200) {
            $error_msg = $data['message'] ?? ('HTTP ' . $http_code);
            return new WP_Error('api_error', __('术语库拉取失败：', 'lisun-manual') . $error_msg);
        }

        $glossary = $data['glossary'] ?? [];

        return [
            'glossary' => $glossary,
            'total'    => count($glossary),
        ];
    }

    /**
     * 推送用内联 CSS（单源维护，避免与 front.css 和 single-manual.php 漂移）
     *
     * @return string CSS 规则（不含 <style> 标签）
     */
    private static function get_push_css() {
        return '
.lisun-manual-content{max-width:950px;line-height:1.6;font-size:13px!important;font-weight:normal!important;color:#333;letter-spacing:0.2px;font-family:"Microsoft YaHei","微软雅黑",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.lisun-manual-content p{margin:.8em 0;font-size:13px!important;line-height:23px!important;font-weight:normal!important}
.lisun-manual-content table,.lisun-manual-table{border-collapse:collapse;margin:1.2em 0;font-size:13px!important;font-weight:normal!important;border:1px solid #ccc!important;table-layout:auto!important;word-break:normal!important;overflow-wrap:break-word!important;hyphens:auto!important;content-visibility:auto;contain-intrinsic-size:auto 200px}
.lisun-manual-content td,.lisun-manual-content th,.lisun-manual-table td,.lisun-manual-table th{border:1px solid #ccc!important;padding:8px 12px;text-align:left;vertical-align:top;font-size:13px!important;font-weight:normal!important;overflow-wrap:break-word!important;word-break:normal!important;hyphens:auto!important;white-space:normal!important}
.lisun-manual-content table.lisun-model-number-table,.lisun-manual-content table.lisun-two-column-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:none!important}
.lisun-manual-content .table-scroll-container table.lisun-model-number-table,.lisun-manual-content .table-scroll-container .lisun-manual-table.lisun-model-number-table,.lisun-manual-content .table-scroll-container table.lisun-two-column-table,.lisun-manual-content .table-scroll-container .lisun-manual-table.lisun-two-column-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:none!important}
.lisun-manual-content table.lisun-model-number-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column="0"],.lisun-manual-content table.lisun-model-number-table>tr>[data-lisun-column="0"],.lisun-manual-content table.lisun-two-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column="0"],.lisun-manual-content table.lisun-two-column-table>tr>[data-lisun-column="0"]{width:var(--lisun-first-column-width,120px)!important;min-width:var(--lisun-first-column-width,120px)!important;max-width:none!important;white-space:normal!important;overflow-wrap:anywhere!important;word-break:normal!important}
.lisun-manual-content table.lisun-model-number-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content table.lisun-model-number-table>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content table.lisun-two-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content table.lisun-two-column-table>tr>[data-lisun-column]:not([data-lisun-column="0"]){width:var(--lisun-rest-column-width,auto)!important;min-width:0!important;max-width:none!important;white-space:normal!important;overflow-wrap:anywhere!important}
.lisun-manual-content table.lisun-auto-column-table,.lisun-manual-content .table-scroll-container table.lisun-auto-column-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:none!important}
.lisun-manual-content table.lisun-auto-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column="0"],.lisun-manual-content table.lisun-auto-column-table>tr>[data-lisun-column="0"]{width:var(--lisun-first-column-width,120px)!important;min-width:var(--lisun-first-column-width,120px)!important;max-width:none!important;white-space:normal!important;overflow-wrap:anywhere!important;word-break:normal!important}
.lisun-manual-content table.lisun-auto-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content table.lisun-auto-column-table>tr>[data-lisun-column]:not([data-lisun-column="0"]){width:var(--lisun-rest-column-width,auto)!important;min-width:0!important;max-width:none!important;white-space:normal!important;overflow-wrap:anywhere!important}
.lisun-manual-content table.lisun-source-layout-table,.lisun-manual-content .table-scroll-container table.lisun-source-layout-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:none!important}
.lisun-manual-content table.lisun-complex-table,.lisun-manual-content .table-scroll-container table.lisun-complex-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:none!important}.lisun-manual-content table.lisun-complex-table td,.lisun-manual-content table.lisun-complex-table th{width:auto!important;min-width:0!important;max-width:none!important;white-space:normal!important;overflow-wrap:anywhere!important}
.lisun-manual-content table.lisun-auto-column-table.lisun-complex-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column],.lisun-manual-content table.lisun-auto-column-table.lisun-complex-table>tr>[data-lisun-column]{width:auto!important;min-width:0!important;max-width:none!important}
.lisun-manual-content table td span,.lisun-manual-content table th span{font-size:inherit!important;line-height:inherit!important}
.lisun-manual-content td a,.lisun-manual-content th a,.lisun-manual-table td a,.lisun-manual-table th a{word-break:break-all}
.lisun-manual-content td[style*="width"],.lisun-manual-content th[style*="width"]{min-width:60px}
.lisun-manual-content thead th{background:#e8edf2;font-weight:700!important;border-bottom:2px solid #bbb!important}
.lisun-manual-content tbody tr:nth-child(even){background:#f8f9fa}
@media (max-width:767px){.lisun-manual-table-wrap{overflow-x:auto!important;-webkit-overflow-scrolling:touch}.lisun-manual-content .table-scroll-container{overflow-x:auto!important;-webkit-overflow-scrolling:touch}.lisun-manual-content .table-scroll-container table.lisun-model-number-table,.lisun-manual-content .table-scroll-container table.lisun-two-column-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:100%!important}.lisun-manual-content .table-scroll-container table.lisun-model-number-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column="0"],.lisun-manual-content .table-scroll-container table.lisun-model-number-table>tr>[data-lisun-column="0"],.lisun-manual-content .table-scroll-container table.lisun-two-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column="0"],.lisun-manual-content .table-scroll-container table.lisun-two-column-table>tr>[data-lisun-column="0"]{width:min(var(--lisun-first-column-width,120px),45vw)!important;min-width:0!important;max-width:45vw!important}.lisun-manual-content .table-scroll-container table.lisun-model-number-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content .table-scroll-container table.lisun-model-number-table>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content .table-scroll-container table.lisun-two-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content .table-scroll-container table.lisun-two-column-table>tr>[data-lisun-column]:not([data-lisun-column="0"]){width:calc(100% - min(var(--lisun-first-column-width,120px),45vw))!important;min-width:0!important;max-width:none!important}}
 .lisun-manual-content .table-scroll-container table.lisun-auto-column-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:100%!important}.lisun-manual-content .table-scroll-container table.lisun-auto-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column="0"],.lisun-manual-content .table-scroll-container table.lisun-auto-column-table>tr>[data-lisun-column="0"]{width:min(var(--lisun-first-column-width,120px),45vw)!important;min-width:0!important;max-width:45vw!important}.lisun-manual-content .table-scroll-container table.lisun-auto-column-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column]:not([data-lisun-column="0"]),.lisun-manual-content .table-scroll-container table.lisun-auto-column-table>tr>[data-lisun-column]:not([data-lisun-column="0"]){width:calc((100% - min(var(--lisun-first-column-width,120px),45vw)) / var(--lisun-table-rest-count,1))!important;min-width:0!important;max-width:none!important}.lisun-manual-content .table-scroll-container table.lisun-source-layout-table[data-lisun-table-columns="4"]{min-width:520px!important;max-width:none!important}.lisun-manual-content .table-scroll-container table.lisun-source-layout-table[data-lisun-table-columns="5"]{min-width:620px!important;max-width:none!important}.lisun-manual-content .table-scroll-container table.lisun-source-layout-table[data-lisun-table-columns="6"],.lisun-manual-content .table-scroll-container table.lisun-source-layout-table[data-lisun-table-columns="7"],.lisun-manual-content .table-scroll-container table.lisun-source-layout-table[data-lisun-table-columns="8"]{min-width:720px!important;max-width:none!important}.lisun-manual-content .table-scroll-container table.lisun-complex-table{table-layout:fixed!important;width:100%!important;min-width:100%!important;max-width:none!important}.lisun-manual-content .table-scroll-container table.lisun-complex-table[data-lisun-table-columns="4"]{min-width:520px!important}.lisun-manual-content .table-scroll-container table.lisun-complex-table[data-lisun-table-columns="5"]{min-width:620px!important}.lisun-manual-content .table-scroll-container table.lisun-complex-table[data-lisun-table-columns="6"],.lisun-manual-content .table-scroll-container table.lisun-complex-table[data-lisun-table-columns="7"],.lisun-manual-content .table-scroll-container table.lisun-complex-table[data-lisun-table-columns="8"]{min-width:720px!important}
.lisun-manual-content .table-scroll-container table.lisun-auto-column-table.lisun-complex-table>:is(thead,tbody,tfoot)>tr>[data-lisun-column],.lisun-manual-content .table-scroll-container table.lisun-auto-column-table.lisun-complex-table>tr>[data-lisun-column]{width:auto!important;min-width:0!important;max-width:none!important}
.lisun-manual-content figure,.lisun-manual-figure,.lisun-manual-content .wp-caption{margin:1.8em auto!important;padding:12px!important;background:#fafbfc!important;border:1px solid #d0d4d8!important;border-radius:8px!important;text-align:center!important;max-width:100%;content-visibility:auto;contain-intrinsic-size:auto 400px}
.lisun-manual-content figure img,.lisun-manual-figure img,.lisun-manual-content .wp-caption img{display:block!important;margin:0 auto!important;border-radius:4px;max-width:100%;height:auto}
.lisun-manual-content figcaption,.lisun-manual-content .wp-caption-text{display:block!important;margin-top:10px!important;font-size:13px!important;color:#888!important;text-align:center!important}
.lisun-manual-table-wrap{overflow-x:hidden;margin:1em 0}
 .lisun-manual-content h1{font-size:24px!important;line-height:1.4!important;color:#333;background:linear-gradient(to right,#e0eafc,#cfdef3);padding:14px 20px;border-left:6px solid #4a90e2;margin:20px 0 10px;font-weight:600!important}
 .lisun-manual-content h2{font-size:20px!important;line-height:1.4!important;color:#333;margin:7px 0 8px;font-weight:600!important}
 .lisun-manual-content h3{font-size:17px!important;line-height:1.4!important;color:#4a90e2;margin:10px 0 6px;display:block;border-bottom:2px solid rgba(74,144,226,0.3);padding-bottom:4px;font-weight:600!important}
 .lisun-manual-content h4{font-size:15px!important;line-height:1.4!important;color:#5a9fd4;margin:7px 0;display:block;border-bottom:1.5px solid rgba(74,144,226,0.2);padding-bottom:3px;font-weight:600!important}
 .lisun-manual-content h5{font-size:15px!important;color:#333;margin:0.75em 0 0.25em;font-weight:600!important}
 .lisun-manual-content h1 span[data-lisun-term-id],.lisun-manual-content h2 span[data-lisun-term-id],.lisun-manual-content h3 span[data-lisun-term-id],.lisun-manual-content h4 span[data-lisun-term-id],.lisun-manual-content h5 span[data-lisun-term-id],.lisun-manual-content h6 span[data-lisun-term-id],.lisun-manual-content .lisun-num-heading span[data-lisun-term-id]{font-size:inherit!important;font-weight:inherit!important;line-height:inherit!important}
/* 编号标题（JS 动态转换 p→h）- 方案二现代科技风 */
.lisun-manual-content .lisun-num-heading{font-weight:600!important}
.lisun-manual-content .lisun-num-lv1{font-size:24px!important;line-height:1.4!important;color:#333;background:linear-gradient(to right,#e0eafc,#cfdef3);padding:14px 20px;border-left:6px solid #4a90e2;margin:20px 0 10px}
.lisun-manual-content .lisun-num-lv2{font-size:20px!important;line-height:1.4!important;color:#333;margin:7px 0 8px;background:0;border-radius:0}
.lisun-manual-content .lisun-num-lv2 .lisun-num-badge{display:inline-block;background-color:#4a90e2;color:#fff;padding:2px 10px;border-radius:12px;font-size:14px!important;font-weight:600!important;margin-right:8px;vertical-align:middle;font-family:"Microsoft YaHei","微软雅黑",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.lisun-manual-content .lisun-num-lv3{font-size:17px!important;line-height:1.4!important;color:#4a90e2;margin:10px 0 6px;display:block;border-bottom:2px solid rgba(74,144,226,0.3);padding-bottom:4px}
.lisun-manual-content .lisun-num-lv4{font-size:15px!important;line-height:1.4!important;color:#5a9fd4;margin:7px 0;display:block;border-bottom:1.5px solid rgba(74,144,226,0.2);padding-bottom:3px}
.lisun-manual-content ul{list-style:disc;padding-left:1.5em;margin:.5em 0}.lisun-manual-content ul li{padding-bottom:2px;line-height:1.6;font-size:13px!important;font-weight:normal!important}.lisun-manual-content ol{list-style:decimal;padding-left:1.5em;margin:.5em 0}.lisun-manual-content ol li{padding-bottom:2px;line-height:1.6;font-size:13px!important;font-weight:normal!important}
/* TOC（推送用）*/
.lisun-manual-toc-push{background:#f8fafc;border:1px solid #e8ecf1;border-radius:8px;padding:20px 24px;margin-bottom:24px}
.lisun-manual-toc-push h3{font-size:15px!important;color:#333;margin:0 0 12px;background:0;border:0;font-weight:600!important}
.lisun-manual-toc-push a{display:block;font-size:14px!important;color:#4a90e2;text-decoration:none;padding:3px 0;line-height:1.6}
.lisun-manual-toc-push a:hover{color:#1a5fa8;text-decoration:underline}
.lisun-manual-toc-push .lisun-toc-lv1{padding-left:0;font-weight:600!important}
.lisun-manual-toc-push .lisun-toc-lv2{padding-left:16px}
.lisun-manual-toc-push .lisun-toc-lv3{padding-left:32px;font-size:13px!important}
.lisun-manual-toc-push .lisun-toc-lv4{padding-left:48px;font-size:12px!important}
/* 软件下载（推送到远程站的内联样式）*/
.lisun-software-list{display:flex;flex-direction:column;gap:10px}
.lisun-software-item{display:flex;align-items:center;gap:14px;padding:12px 16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;font-family:"Microsoft YaHei","微软雅黑",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
.lisun-software-icon{width:38px;height:38px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.lisun-software-icon svg{width:18px;height:18px;color:#2563eb}
.lisun-software-name{flex:1;font-size:14px;font-weight:500;color:#374151}
.lisun-software-dl-btns{display:flex;gap:8px;flex-shrink:0}
.lisun-software-dl-btn{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;font-size:12px!important;color:#2563eb;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;text-decoration:none;transition:all 0.2s;white-space:nowrap}
.lisun-software-dl-btn:hover{background:#2563eb;color:#fff;border-color:#2563eb}
.lisun-software-dl-btn svg{width:14px;height:14px;flex-shrink:0}
img{max-width:100%;height:auto}
';
    }
}
