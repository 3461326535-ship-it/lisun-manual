<?php
/** Human-in-the-loop LLM review for committed Translation V2 units. */
if (!defined('ABSPATH')) exit;

class Lisun_Manual_V2_LLM_Review {
    const META_KEY = '_lisun_manual_llm_review';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        $permission = static function ($request) {
            $post_id = (int) ($request['id'] ?? $request->get_param('post_id'));
            return $post_id > 0 && current_user_can('edit_post', $post_id);
        };
        register_rest_route('lisun-manual/v1', '/review/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'rest_get'], 'permission_callback' => $permission,
        ]);
        register_rest_route('lisun-manual/v1', '/review/run', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_run'], 'permission_callback' => $permission,
        ]);
        register_rest_route('lisun-manual/v1', '/review/action', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_action'], 'permission_callback' => $permission,
        ]);
        register_rest_route('lisun-manual/v1', '/review/discuss', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'rest_discuss'], 'permission_callback' => $permission,
        ]);
    }

    public static function add_meta_box() {
        if (!(bool) get_option('lisun_llm_lab_enabled', false)) return;
        add_meta_box('lisun-manual-llm-review', 'AI 翻译预审核', [__CLASS__, 'render_meta_box'], 'manual', 'normal', 'high');
    }

    public static function render_meta_box($post) {
        if (get_post_meta($post->ID, '_lisun_manual_lang', true) === 'en') return;
        $review = self::load($post->ID);
        $items = array_values((array) ($review['items'] ?? []));
        $counts = ['pass' => 0, 'suggest' => 0, 'block' => 0, 'accepted' => 0, 'rejected' => 0];
        foreach ($items as $item) {
            $state = (string) ($item['decision'] ?? $item['status'] ?? 'pass');
            if (isset($counts[$state])) $counts[$state]++;
        }
        ?>
        <div id="lisun-review" data-post-id="<?php echo (int) $post->ID; ?>">
            <p>
                <button type="button" class="button button-primary" id="lisun-review-run">开始 AI 审核</button>
                <span id="lisun-review-status" style="margin-left:8px;color:#646970;">
                    <?php echo $items ? esc_html('上次审核：' . (string) ($review['updated_at'] ?? '') . '；建议 ' . $counts['suggest'] . '，阻断 ' . $counts['block'] . '，已接受 ' . $counts['accepted']) : '尚未审核'; ?>
                </span>
            </p>
            <div id="lisun-review-list">
                <?php foreach ($items as $index => $item) self::render_item($item, $index); ?>
            </div>
        </div>
        <style>
            .lisun-review-card{border:1px solid #dcdcde;border-left:4px solid #72aee6;background:#fff;margin:10px 0;padding:12px 14px}
            .lisun-review-card[data-status="block"]{border-left-color:#d63638}.lisun-review-card[data-status="pass"]{border-left-color:#00a32a}
            .lisun-review-card.is-decided{opacity:.72}.lisun-review-source{color:#50575e}.lisun-review-target,.lisun-review-suggestion{white-space:pre-wrap;background:#f6f7f7;padding:8px;margin:6px 0}
            .lisun-review-suggestion{background:#fff8e5}.lisun-review-reason{margin:7px 0}.lisun-review-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
            .lisun-review-discuss{display:none;margin-top:8px}.lisun-review-discuss textarea{width:100%;min-height:64px}
        </style>
        <script>
        jQuery(function($){
            var $root=$('#lisun-review'); if(!$root.length)return;
            var id=$root.data('post-id'), base='<?php echo esc_js(rest_url('lisun-manual/v1')); ?>', nonce='<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
            function post(path,data){data.post_id=id;return $.ajax({url:base+path,method:'POST',contentType:'application/json',data:JSON.stringify(data),beforeSend:function(x){x.setRequestHeader('X-WP-Nonce',nonce);}})}
            $('#lisun-review-run').on('click',function(){var $b=$(this).prop('disabled',true);$('#lisun-review-status').text('AI 正在逐批审核，请稍候…');post('/review/run',{}).done(function(){location.reload()}).fail(function(x){$b.prop('disabled',false);$('#lisun-review-status').text((x.responseJSON&&x.responseJSON.message)||'审核失败')})});
            $root.on('click','.lisun-review-accept,.lisun-review-reject',function(){var $b=$(this), action=$b.hasClass('lisun-review-accept')?'accept':'reject', key=$b.closest('.lisun-review-card').data('key');$b.prop('disabled',true);post('/review/action',{unit_key:key,review_action:action}).done(function(){location.reload()}).fail(function(x){$b.prop('disabled',false);alert((x.responseJSON&&x.responseJSON.message)||'操作失败')})});
            $root.on('click','.lisun-review-open-discuss',function(){$(this).closest('.lisun-review-card').find('.lisun-review-discuss').toggle()});
            $root.on('click','.lisun-review-send',function(){var $card=$(this).closest('.lisun-review-card'),$b=$(this).prop('disabled',true),msg=$card.find('textarea').val();post('/review/discuss',{unit_key:$card.data('key'),message:msg}).done(function(){location.reload()}).fail(function(x){$b.prop('disabled',false);alert((x.responseJSON&&x.responseJSON.message)||'讨论失败')})});
        });
        </script>
        <?php
    }

    private static function render_item($item, $index) {
        $status = (string) ($item['status'] ?? 'pass');
        $decision = (string) ($item['decision'] ?? '');
        $label = ['pass' => '✓ 通过', 'suggest' => '建议修改', 'block' => '需处理'][$status] ?? $status;
        ?>
        <div class="lisun-review-card <?php echo $decision ? 'is-decided' : ''; ?>" data-key="<?php echo esc_attr((string) ($item['unit_key'] ?? '')); ?>" data-status="<?php echo esc_attr($status); ?>">
            <div><strong><?php echo (int) ($index + 1); ?>. <?php echo esc_html($label); ?></strong> <span style="color:#646970">[<?php echo esc_html((string) ($item['category'] ?? '综合')); ?>] <?php echo esc_html(number_format((float) ($item['confidence'] ?? 0), 2)); ?></span></div>
            <div class="lisun-review-source"><strong>原文：</strong><?php echo esc_html((string) ($item['source'] ?? '')); ?></div>
            <div class="lisun-review-target"><strong>当前：</strong><?php echo esc_html((string) ($item['current'] ?? '')); ?></div>
            <?php if (!empty($item['suggestion'])): ?><div class="lisun-review-suggestion"><strong>建议：</strong><?php echo esc_html((string) $item['suggestion']); ?></div><?php endif; ?>
            <?php if (!empty($item['reason'])): ?><div class="lisun-review-reason"><strong>原因：</strong><?php echo esc_html((string) $item['reason']); ?></div><?php endif; ?>
            <?php if ($decision): ?><p><strong>处理结果：</strong><?php echo esc_html($decision === 'accepted' ? '已接受并生成新版本' : '已拒绝'); ?></p><?php elseif ($status !== 'pass'): ?>
                <div class="lisun-review-actions"><button type="button" class="button button-primary lisun-review-accept">✓ 接受</button><button type="button" class="button lisun-review-reject">✗ 拒绝</button><button type="button" class="button lisun-review-open-discuss">💬 讨论</button></div>
                <div class="lisun-review-discuss"><textarea placeholder="告诉模型哪里不合适、希望如何调整……"></textarea><p><button type="button" class="button button-primary lisun-review-send">发送并修改建议</button></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function rest_get($request) {
        return rest_ensure_response(self::load((int) $request['id']));
    }

    public static function rest_run($request) {
        $post_id = (int) $request->get_param('post_id');
        $snapshot = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
        if (!$snapshot) return new WP_Error('review_no_revision', '没有可审核的 V2 翻译版本', ['status' => 409]);
        $items = [];
        foreach (array_chunk((array) $snapshot['units'], 8) as $batch) {
            $result = self::review_batch($batch);
            if (is_wp_error($result)) return $result;
            $items = array_merge($items, $result);
        }
        $record = [
            'revision_id' => (int) $snapshot['revision']['id'],
            'model' => self::config()['model'],
            'updated_at' => current_time('mysql'),
            'items' => $items,
        ];
        update_post_meta($post_id, self::META_KEY, $record);
        return rest_ensure_response($record);
    }

    public static function rest_action($request) {
        $post_id = (int) $request->get_param('post_id');
        $key = sanitize_text_field((string) $request->get_param('unit_key'));
        $action = sanitize_key((string) $request->get_param('review_action'));
        $review = self::load($post_id);
        $index = self::find_item($review, $key);
        if ($index < 0) return new WP_Error('review_item_missing', '审核项不存在', ['status' => 404]);
        if ($action === 'reject') {
            $review['items'][$index]['decision'] = 'rejected';
            $review['items'][$index]['decided_at'] = current_time('mysql');
            update_post_meta($post_id, self::META_KEY, $review);
            return rest_ensure_response(['success' => true, 'decision' => 'rejected']);
        }
        if ($action !== 'accept') return new WP_Error('review_bad_action', '未知操作', ['status' => 400]);
        $suggestion = (string) ($review['items'][$index]['suggestion'] ?? '');
        if ($suggestion === '') return new WP_Error('review_empty_suggestion', '该审核项没有可接受的建议', ['status' => 409]);
        $accepted = self::accept_suggestion($post_id, $key, $suggestion);
        if (is_wp_error($accepted)) return $accepted;
        $review['revision_id'] = (int) $accepted['revision_id'];
        $review['items'][$index]['decision'] = 'accepted';
        $review['items'][$index]['decided_at'] = current_time('mysql');
        $review['items'][$index]['current'] = $suggestion;
        update_post_meta($post_id, self::META_KEY, $review);
        return rest_ensure_response($accepted);
    }

    public static function rest_discuss($request) {
        $post_id = (int) $request->get_param('post_id');
        $key = sanitize_text_field((string) $request->get_param('unit_key'));
        $message = sanitize_textarea_field((string) $request->get_param('message'));
        if ($message === '') return new WP_Error('review_empty_message', '请输入讨论内容', ['status' => 400]);
        $review = self::load($post_id);
        $index = self::find_item($review, $key);
        if ($index < 0) return new WP_Error('review_item_missing', '审核项不存在', ['status' => 404]);
        $item = $review['items'][$index];
        $reply = self::discuss_item($item, $message);
        if (is_wp_error($reply)) return $reply;
        $item['suggestion'] = (string) $reply['suggestion'];
        $item['reason'] = (string) $reply['reason'];
        $item['status'] = 'suggest';
        $item['discussion'][] = ['time' => current_time('mysql'), 'user' => $message, 'model' => $item['reason']];
        $review['items'][$index] = $item;
        $review['updated_at'] = current_time('mysql');
        update_post_meta($post_id, self::META_KEY, $review);
        return rest_ensure_response($item);
    }

    private static function review_batch($units) {
        $payload = [];
        foreach ($units as $unit) {
            $payload[] = [
                'unit_key' => (string) $unit['unit_key'],
                'context' => (string) ($unit['unit_type'] ?? 'text'),
                'source' => (string) ($unit['source_text'] ?? ''),
                'translation' => (string) ($unit['target_template'] ?? ''),
            ];
        }
        $prompt = "Review each Chinese-to-English technical-manual translation. Check terminology, untranslated Chinese, grammar, naturalness, formal manual register, heading capitalization, and spacing between numbers and SI units. Do not fact-check. Preserve every HTML tag and every {{LISUN_TERM:...}} marker exactly in any suggestion. Return strict JSON only: {\"items\":[{\"unit_key\":\"...\",\"status\":\"pass|suggest|block\",\"category\":\"terminology|language|style|format\",\"confidence\":0.0,\"reason\":\"concise Chinese reason\",\"suggestion\":\"complete corrected translation or empty\"}]}. Include every input key once.\n\n" . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = self::call_model($prompt);
        if (is_wp_error($data)) return $data;
        $returned = [];
        foreach ((array) ($data['items'] ?? []) as $item) $returned[(string) ($item['unit_key'] ?? '')] = $item;
        $result = [];
        foreach ($payload as $source) {
            $item = (array) ($returned[$source['unit_key']] ?? []);
            $status = in_array(($item['status'] ?? ''), ['pass', 'suggest', 'block'], true) ? $item['status'] : 'block';
            $suggestion = trim((string) ($item['suggestion'] ?? ''));
            if ($status !== 'pass') {
                $valid = self::validate_template($source['translation'], $suggestion);
                if (is_wp_error($valid)) {
                    $status = 'block'; $suggestion = '';
                    $item['reason'] = trim((string) ($item['reason'] ?? '')) . '（模型建议破坏了标签或术语槽位，已禁止直接接受）';
                }
            }
            $result[] = [
                'unit_key' => $source['unit_key'], 'source' => $source['source'], 'current' => $source['translation'],
                'status' => $status, 'category' => sanitize_key((string) ($item['category'] ?? 'language')),
                'confidence' => max(0, min(1, (float) ($item['confidence'] ?? 0))),
                'reason' => sanitize_textarea_field((string) ($item['reason'] ?? '')), 'suggestion' => $suggestion,
                'discussion' => [],
            ];
        }
        return $result;
    }

    private static function discuss_item($item, $message) {
        $prompt = "Revise one technical-manual translation suggestion based on the operator's feedback. Preserve every HTML tag and every {{LISUN_TERM:...}} marker exactly. Return strict JSON only: {\"suggestion\":\"complete corrected translation\",\"reason\":\"concise Chinese explanation\"}.\n\n" . wp_json_encode([
            'source' => $item['source'] ?? '', 'current' => $item['current'] ?? '', 'previous_suggestion' => $item['suggestion'] ?? '',
            'previous_reason' => $item['reason'] ?? '', 'operator_feedback' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = self::call_model($prompt);
        if (is_wp_error($data)) return $data;
        $suggestion = trim((string) ($data['suggestion'] ?? ''));
        $valid = self::validate_template((string) ($item['current'] ?? ''), $suggestion);
        if (is_wp_error($valid)) return $valid;
        return ['suggestion' => $suggestion, 'reason' => sanitize_textarea_field((string) ($data['reason'] ?? ''))];
    }

    private static function accept_suggestion($post_id, $unit_key, $suggestion) {
        $snapshot = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
        if (!$snapshot) return new WP_Error('review_no_revision', '当前 V2 版本不存在', ['status' => 409]);
        $occurrences = [];
        foreach ((array) $snapshot['occurrences'] as $occ) $occurrences[(string) $occ['unit_key']][] = $occ;
        $found = false;
        foreach ($snapshot['units'] as &$unit) {
            $unit['field'] = (string) $unit['field_name'];
            $unit['occurrences'] = $occurrences[(string) $unit['unit_key']] ?? [];
            if ((string) $unit['unit_key'] !== $unit_key) continue;
            $valid = self::validate_template((string) $unit['target_template'], $suggestion);
            if (is_wp_error($valid)) return $valid;
            $unit['target_template'] = $suggestion; $unit['lock_state'] = 'manual'; $unit['status'] = 'translated'; $found = true;
        }
        unset($unit);
        if (!$found) return new WP_Error('review_unit_changed', '翻译版本已变化，请重新审核', ['status' => 409]);
        $revision = Lisun_Manual_V2_Repository::save_committed_revision(
            $post_id, (string) $snapshot['revision']['source_hash'], (string) $snapshot['revision']['source_skeleton'],
            $snapshot['units'], (int) $snapshot['revision']['glossary_revision'], (string) $snapshot['revision']['transport_version']
        );
        if (is_wp_error($revision)) return $revision;
        $published = Lisun_Manual_Translate::publish_review_revision($post_id, (int) $revision);
        if (is_wp_error($published)) return $published;
        return ['success' => true, 'revision_id' => (int) $revision, 'publication' => $published];
    }

    private static function validate_template($current, $suggestion) {
        if ($suggestion === '') return new WP_Error('review_empty_suggestion', '模型没有返回完整建议', ['status' => 422]);
        preg_match_all('/\{\{LISUN_TERM:[^}]+\}\}/', $current, $a); preg_match_all('/\{\{LISUN_TERM:[^}]+\}\}/', $suggestion, $b);
        $left = $a[0] ?? []; $right = $b[0] ?? []; sort($left); sort($right);
        if ($left !== $right) return new WP_Error('review_marker_mismatch', '建议改变了术语槽位，不能接受', ['status' => 422]);
        preg_match_all('/<\/?[a-zA-Z][^>]*>/', $current, $ta); preg_match_all('/<\/?[a-zA-Z][^>]*>/', $suggestion, $tb);
        if (($ta[0] ?? []) !== ($tb[0] ?? [])) return new WP_Error('review_tag_mismatch', '建议改变了 HTML 标签，不能接受', ['status' => 422]);
        return true;
    }

    private static function call_model($prompt) {
        $config = self::config();
        if ($config['api_key'] === '' || $config['model'] === '') return new WP_Error('review_config_missing', 'LLM 审核配置不完整', ['status' => 500]);
        $body = ['model' => $config['model'], 'temperature' => 0, 'messages' => [
            ['role' => 'system', 'content' => 'You are a conservative bilingual QA reviewer for technical product manuals. Output strict JSON only.'],
            ['role' => 'user', 'content' => $prompt],
        ]];
        if ($config['provider'] === 'doubao') $body['thinking'] = ['type' => 'disabled'];
        $response = wp_remote_post($config['api_url'], ['timeout' => $config['timeout'], 'headers' => [
            'Authorization' => 'Bearer ' . $config['api_key'], 'Content-Type' => 'application/json',
        ], 'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        if (is_wp_error($response)) return $response;
        $status = (int) wp_remote_retrieve_response_code($response); $raw = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) return new WP_Error('review_http_error', (string) ($raw['error']['message'] ?? ('HTTP ' . $status)), ['status' => 502]);
        $content = trim((string) ($raw['choices'][0]['message']['content'] ?? ''));
        $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content));
        $data = json_decode($content, true);
        return is_array($data) ? $data : new WP_Error('review_invalid_json', '模型审核结果不是有效 JSON', ['status' => 502]);
    }

    private static function config() {
        $provider = (string) get_option('lisun_llm_lab_provider', 'doubao');
        $urls = ['deepseek' => 'https://api.deepseek.com/chat/completions', 'doubao' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions', 'openai' => 'https://api.openai.com/v1/chat/completions', 'openrouter' => 'https://openrouter.ai/api/v1/chat/completions', 'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'];
        $models = ['deepseek' => 'deepseek-chat', 'doubao' => (string) get_option('lisun_doubao_model', 'Doubao-Seed-2.1-pro'), 'openai' => 'gpt-4o', 'openrouter' => 'openrouter/auto', 'gemini' => 'gemini-3.6-flash'];
        $url = trim((string) get_option('lisun_llm_lab_api_url', '')); if ($url === '' || $provider === 'doubao') $url = $urls[$provider] ?? $urls['doubao'];
        $model = $provider === 'doubao' ? trim((string) get_option('lisun_doubao_endpoint', '')) : trim((string) get_option('lisun_llm_lab_model', $models[$provider] ?? ''));
        if ($model === '') $model = $models[$provider] ?? '';
        $key = class_exists('Lisun_Manual_Provider_Config') ? Lisun_Manual_Provider_Config::api_key($provider) : '';
        return ['provider' => $provider, 'api_url' => $url, 'api_key' => trim((string) $key), 'model' => $model, 'timeout' => max(30, (int) get_option('lisun_llm_lab_timeout', 180))];
    }

    private static function load($post_id) {
        $review = get_post_meta((int) $post_id, self::META_KEY, true);
        return is_array($review) ? $review : ['revision_id' => 0, 'updated_at' => '', 'items' => []];
    }

    private static function find_item($review, $key) {
        foreach ((array) ($review['items'] ?? []) as $index => $item) if ((string) ($item['unit_key'] ?? '') === $key) return (int) $index;
        return -1;
    }
}
