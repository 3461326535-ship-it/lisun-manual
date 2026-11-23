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
        $counts = ['pass' => 0, 'suggest' => 0, 'block' => 0, 'accepted' => 0, 'rejected' => 0, 'undone' => 0];
        $pending = []; $handled = [];
        foreach ($items as $index => $item) {
            $item['_source_order'] = $index + 1;
            $state = (string) ($item['decision'] ?? $item['status'] ?? 'pass');
            if (isset($counts[$state])) $counts[$state]++;
            if (!empty($item['decision'])) $handled[] = $item;
            elseif ((string) ($item['status'] ?? 'pass') !== 'pass') $pending[] = $item;
        }
        usort($pending, static function ($left, $right) {
            $priority = ['block' => 0, 'suggest' => 1];
            $a = $priority[(string) ($left['status'] ?? 'suggest')] ?? 2;
            $b = $priority[(string) ($right['status'] ?? 'suggest')] ?? 2;
            return $a === $b ? ((int) $left['_source_order'] <=> (int) $right['_source_order']) : ($a <=> $b);
        });
        ?>
        <div id="lisun-review" data-post-id="<?php echo (int) $post->ID; ?>">
            <p>
                <button type="button" class="button button-primary" id="lisun-review-run">开始 AI 审核</button>
                <span id="lisun-review-status" style="margin-left:8px;color:#646970;">
                    <?php echo $items ? esc_html('上次审核：' . (string) ($review['updated_at'] ?? '') . '；待处理 ' . count($pending) . '（阻断 ' . $counts['block'] . '），通过 ' . $counts['pass'] . '，已处理 ' . count($handled)) : '尚未审核'; ?>
                </span>
            </p>
            <div id="lisun-review-list">
                <?php foreach ($pending as $item) self::render_item($item, (int) $item['_source_order'] - 1); ?>
                <?php if ($handled): ?>
                    <details class="lisun-review-history">
                        <summary>处理记录（<?php echo count($handled); ?>）</summary>
                        <?php foreach ($handled as $item) self::render_item($item, (int) $item['_source_order'] - 1); ?>
                    </details>
                <?php endif; ?>
            </div>
        </div>
        <style>
            .lisun-review-card{border:1px solid #dcdcde;border-left:4px solid #72aee6;background:#fff;margin:10px 0;padding:12px 14px}
            .lisun-review-card[data-status="block"]{border-left-color:#d63638}.lisun-review-card[data-status="pass"]{border-left-color:#00a32a}
            .lisun-review-card.is-decided{opacity:.72}.lisun-review-source{color:#50575e}.lisun-review-target,.lisun-review-suggestion{white-space:pre-wrap;background:#f6f7f7;padding:8px;margin:6px 0}
            .lisun-review-suggestion{background:#fff8e5}.lisun-review-reason{margin:7px 0}.lisun-review-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
            .lisun-review-discuss{display:none;margin-top:8px}.lisun-review-discuss textarea{width:100%;min-height:64px}
            .lisun-review-history{margin-top:14px;border-top:1px solid #dcdcde;padding-top:10px}.lisun-review-history>summary{cursor:pointer;font-weight:600;color:#50575e}
        </style>
        <script>
        jQuery(function($){
            var $root=$('#lisun-review'); if(!$root.length)return;
            var id=$root.data('post-id'), base='<?php echo esc_js(rest_url('lisun-manual/v1')); ?>', nonce='<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
            function post(path,data){data.post_id=id;return $.ajax({url:base+path,method:'POST',contentType:'application/json',data:JSON.stringify(data),beforeSend:function(x){x.setRequestHeader('X-WP-Nonce',nonce);}})}
            $('#lisun-review-run').on('click',function(){var $b=$(this).prop('disabled',true);$('#lisun-review-status').text('AI 正在逐批审核，请稍候…');post('/review/run',{}).done(function(){location.reload()}).fail(function(x){$b.prop('disabled',false);$('#lisun-review-status').text((x.responseJSON&&x.responseJSON.message)||'审核失败')})});
            $root.on('click','.lisun-review-accept,.lisun-review-reject,.lisun-review-undo',function(){var $b=$(this), action=$b.hasClass('lisun-review-accept')?'accept':($b.hasClass('lisun-review-undo')?'undo':'reject'), key=$b.closest('.lisun-review-card').data('key');if(action==='undo'&&!confirm('撤销这次接受并恢复前一个 V2 版本？'))return;$b.prop('disabled',true);post('/review/action',{unit_key:key,review_action:action}).done(function(){location.reload()}).fail(function(x){$b.prop('disabled',false);alert((x.responseJSON&&x.responseJSON.message)||'操作失败')})});
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
            <?php if ($decision): ?><p><strong>处理结果：</strong><?php echo esc_html($decision === 'accepted' ? '已接受并生成新版本' : ($decision === 'undone' ? '已撤销' : '已拒绝')); ?>
                <?php if ($decision === 'accepted' && !empty($item['accepted_revision_id'])): ?><button type="button" class="button button-small lisun-review-undo" style="margin-left:8px">撤销接受</button><?php endif; ?>
            </p><?php elseif ($status !== 'pass'): ?>
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
        $occurrences = [];
        foreach ((array) $snapshot['occurrences'] as $occurrence) {
            $occurrences[(string) ($occurrence['unit_key'] ?? '')][] = $occurrence;
        }
        $units = (array) $snapshot['units'];
        foreach ($units as &$unit) $unit['occurrences'] = $occurrences[(string) ($unit['unit_key'] ?? '')] ?? [];
        unset($unit);
        $items = [];
        $batches = array_chunk($units, 12);
        foreach (array_chunk($batches, 3) as $group) {
            $prompts = array_map([__CLASS__, 'review_prompt'], $group);
            $responses = self::call_model_group($prompts);
            foreach ($group as $offset => $batch) {
                $data = $responses[$offset] ?? new WP_Error('review_transport_missing', '审核批次没有返回结果');
                if (is_wp_error($data)) return $data;
                $result = self::review_batch($batch, $data);
                if (is_wp_error($result)) return $result;
                $items = array_merge($items, $result);
            }
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
        if ($action === 'undo') {
            $accepted_revision = (int) ($review['items'][$index]['accepted_revision_id'] ?? 0);
            $previous_revision = (int) ($review['items'][$index]['previous_revision_id'] ?? 0);
            if ($accepted_revision <= 0) return new WP_Error('review_nothing_to_undo', '这条建议没有可撤销的接受记录', ['status' => 409]);
            $active = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
            if (!$active || (int) ($active['revision']['id'] ?? 0) !== $accepted_revision) {
                return new WP_Error('review_undo_stale', '接受后已有更新版本，为避免覆盖新内容，已停止撤销', ['status' => 409]);
            }
            $undone = Lisun_Manual_Translate::undo_glossary_apply($post_id, $accepted_revision, $previous_revision);
            if (is_wp_error($undone)) {
                $error_data = (array) $undone->get_error_data();
                if (empty($error_data['restored'])) return $undone;
                $undone = [
                    'restored' => true,
                    'previous_revision_id' => (int) ($error_data['previous_revision_id'] ?? $previous_revision),
                    'pushed' => false,
                    'push_msg' => $undone->get_error_message(),
                ];
            }
            $review['revision_id'] = (int) ($undone['previous_revision_id'] ?? 0);
            $review['items'][$index]['decision'] = 'undone';
            $review['items'][$index]['undone_at'] = current_time('mysql');
            update_post_meta($post_id, self::META_KEY, $review);
            return rest_ensure_response($undone);
        }
        if ($action === 'reject') {
            $review['items'][$index]['decision'] = 'rejected';
            $review['items'][$index]['decided_at'] = current_time('mysql');
            update_post_meta($post_id, self::META_KEY, $review);
            return rest_ensure_response(['success' => true, 'decision' => 'rejected']);
        }
        if ($action !== 'accept') return new WP_Error('review_bad_action', '未知操作', ['status' => 400]);
        $suggestion = (string) ($review['items'][$index]['suggestion_template'] ?? $review['items'][$index]['suggestion'] ?? '');
        if ($suggestion === '') return new WP_Error('review_empty_suggestion', '该审核项没有可接受的建议', ['status' => 409]);
        $before_accept = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
        $previous_revision = (int) ($before_accept['revision']['id'] ?? 0);
        $accepted = self::accept_suggestion($post_id, $key, $suggestion);
        if (is_wp_error($accepted)) return $accepted;
        $review['revision_id'] = (int) $accepted['revision_id'];
        $review['items'][$index]['decision'] = 'accepted';
        $review['items'][$index]['decided_at'] = current_time('mysql');
        $review['items'][$index]['accepted_revision_id'] = (int) $accepted['revision_id'];
        $review['items'][$index]['previous_revision_id'] = $previous_revision;
        $review['items'][$index]['current_template'] = $suggestion;
        $review['items'][$index]['current'] = (string) ($review['items'][$index]['suggestion'] ?? $suggestion);
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
        $item['suggestion_template'] = (string) $reply['suggestion'];
        $item['suggestion'] = self::readable_template((string) $reply['suggestion'], (array) ($item['term_slots'] ?? []));
        $item['reason'] = (string) $reply['reason'];
        $item['status'] = 'suggest';
        $item['discussion'][] = ['time' => current_time('mysql'), 'user' => $message, 'model' => $item['reason']];
        $review['items'][$index] = $item;
        $review['updated_at'] = current_time('mysql');
        update_post_meta($post_id, self::META_KEY, $review);
        return rest_ensure_response($item);
    }

    public static function review_prompt($units) {
        $payload = [];
        foreach ($units as $unit) {
            $slots = self::slot_map((array) ($unit['occurrences'] ?? []));
            $payload[] = [
                'unit_key' => (string) $unit['unit_key'],
                'context' => (string) ($unit['unit_type'] ?? 'text'),
                'source' => (string) ($unit['source_text'] ?? ''),
                'translation' => (string) ($unit['target_template'] ?? ''),
                'term_slots' => $slots,
            ];
        }
        return "Review each Chinese-to-English technical-manual translation. Check terminology, untranslated Chinese, grammar, naturalness, formal manual register, heading capitalization, and spacing between numbers and SI units. Do not fact-check. Each {{LISUN_TERM:...}} marker already renders as the visible English term listed in term_slots: never repeat that term before or after its marker. Preserve every HTML tag and every marker exactly in any suggestion. Use consistent Title Case for h1-h6 headings, including explanatory text in parentheses; do not demand Title Case for ordinary td table data. Preserve the conventional form 70% RH without inserting a space before %. Ignore differences consisting only of leading/trailing whitespace. Mark pass when the current wording is already acceptable; do not invent a suggestion identical to the current translation. If grammar around a fixed term marker is awkward, rewrite the sentence around it rather than adding a suffix outside the marker. Return strict JSON only: {\"items\":[{\"unit_key\":\"...\",\"status\":\"pass|suggest|block\",\"category\":\"terminology|language|style|format\",\"confidence\":0.0,\"reason\":\"concise Chinese reason\",\"suggestion\":\"complete corrected translation template or empty\"}]}. Include every input key once.\n\n" . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function review_batch($units, $data) {
        $payload = [];
        foreach ($units as $unit) {
            $payload[] = [
                'unit_key' => (string) $unit['unit_key'], 'source' => (string) ($unit['source_text'] ?? ''),
                'translation' => (string) ($unit['target_template'] ?? ''), 'term_slots' => self::slot_map((array) ($unit['occurrences'] ?? [])),
            ];
        }
        $returned = [];
        foreach ((array) ($data['items'] ?? []) as $item) $returned[(string) ($item['unit_key'] ?? '')] = $item;
        $result = [];
        foreach ($payload as $source) {
            $item = (array) ($returned[$source['unit_key']] ?? []);
            $status = in_array(($item['status'] ?? ''), ['pass', 'suggest', 'block'], true) ? $item['status'] : 'block';
            $suggestion = trim((string) ($item['suggestion'] ?? ''));
            if ($status !== 'pass' && self::substantive_text($source['translation']) === self::substantive_text($suggestion)) {
                $status = 'pass'; $suggestion = ''; $item['reason'] = '';
            }
            if ($status !== 'pass') {
                $valid = self::validate_template($source['translation'], $suggestion);
                if (!is_wp_error($valid)) $valid = self::validate_slot_grammar($suggestion, $source['term_slots']);
                if (is_wp_error($valid)) {
                    $status = 'block'; $suggestion = '';
                    $item['reason'] = trim((string) ($item['reason'] ?? '')) . '（模型建议破坏了标签或术语槽位，已禁止直接接受）';
                }
            }
            $result[] = [
                'unit_key' => $source['unit_key'], 'source' => $source['source'],
                'current_template' => $source['translation'], 'current' => self::readable_template($source['translation'], $source['term_slots']),
                'status' => $status, 'category' => sanitize_key((string) ($item['category'] ?? 'language')),
                'confidence' => max(0, min(1, (float) ($item['confidence'] ?? 0))),
                'reason' => sanitize_textarea_field((string) ($item['reason'] ?? '')),
                'suggestion_template' => $suggestion, 'suggestion' => self::readable_template($suggestion, $source['term_slots']),
                'term_slots' => $source['term_slots'],
                'discussion' => [],
            ];
        }
        return $result;
    }

    private static function discuss_item($item, $message) {
        $prompt = "Revise one technical-manual translation suggestion based on the operator's feedback. Preserve every HTML tag and every {{LISUN_TERM:...}} marker exactly. Never put suffixes such as s, es, ed, or ing immediately after a term marker; rewrite the sentence around the fixed marker instead. A singular fixed term must not directly follow 'two' or 'a pair of'. For cable-like mass nouns, write 'two lengths of <marker>'; otherwise use a grammatical surrounding noun such as 'two <marker> units'. Return strict JSON only: {\"suggestion\":\"complete corrected translation\",\"reason\":\"concise Chinese explanation\"}.\n\n" . wp_json_encode([
            'source' => $item['source'] ?? '', 'current' => $item['current_template'] ?? $item['current'] ?? '', 'previous_suggestion' => $item['suggestion_template'] ?? $item['suggestion'] ?? '',
            'previous_reason' => $item['reason'] ?? '', 'operator_feedback' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = self::call_model($prompt);
        if (is_wp_error($data)) return $data;
        $suggestion = trim((string) ($data['suggestion'] ?? ''));
        $valid = self::validate_template((string) ($item['current_template'] ?? $item['current'] ?? ''), $suggestion);
        if (!is_wp_error($valid)) $valid = self::validate_slot_grammar($suggestion, (array) ($item['term_slots'] ?? []));
        if (is_wp_error($valid) && in_array($valid->get_error_code(), ['review_suffix_outside_marker', 'review_slot_grammar'], true)) {
            $retry = self::call_model($prompt . "\n\nYour previous suggestion violated fixed-term grammar. Rewrite it without a suffix after a marker and without placing a singular marker directly after 'two' or 'a pair of'. Use 'two lengths of <marker>' for power cord. Invalid suggestion:\n" . $suggestion);
            if (is_wp_error($retry)) return $retry;
            $suggestion = trim((string) ($retry['suggestion'] ?? ''));
            $data = $retry;
            $valid = self::validate_template((string) ($item['current_template'] ?? $item['current'] ?? ''), $suggestion);
            if (!is_wp_error($valid)) $valid = self::validate_slot_grammar($suggestion, (array) ($item['term_slots'] ?? []));
        }
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
        if (preg_match('/\}\}\s*(?:s|es|ed|ing)\b/i', $suggestion)) {
            return new WP_Error('review_suffix_outside_marker', '建议把词尾放在术语槽位外，不能接受', ['status' => 422]);
        }
        preg_match_all('/<\/?[a-zA-Z][^>]*>/', $current, $ta); preg_match_all('/<\/?[a-zA-Z][^>]*>/', $suggestion, $tb);
        if (($ta[0] ?? []) !== ($tb[0] ?? [])) return new WP_Error('review_tag_mismatch', '建议改变了 HTML 标签，不能接受', ['status' => 422]);
        return true;
    }

    private static function validate_slot_grammar($template, $slots) {
        foreach ((array) $slots as $marker => $visible) {
            $visible = trim((string) $visible);
            if ($visible === '' || preg_match('/s$/i', $visible)) continue;
            $quoted = preg_quote((string) $marker, '/');
            if (preg_match('/(?:\btwo\s+|\ba(?:\s+[a-z-]+){0,3}\s+pair\s+of\s+)' . $quoted . '/i', (string) $template)) {
                return new WP_Error('review_slot_grammar', '建议把固定单数术语直接接在数量结构后，语法不成立', ['status' => 422]);
            }
        }
        return true;
    }

    private static function call_model($prompt) {
        $results = self::call_model_group([$prompt]);
        return $results[0] ?? new WP_Error('review_transport_missing', '模型没有返回审核结果');
    }

    /** Send up to three independent review batches concurrently. */
    private static function call_model_group($prompts) {
        $config = self::config();
        if ($config['api_key'] === '' || $config['model'] === '') return [new WP_Error('review_config_missing', 'LLM 审核配置不完整', ['status' => 500])];
        if (count($prompts) <= 1 || !function_exists('curl_multi_init')) {
            $results = [];
            foreach ($prompts as $prompt) {
                $body = self::request_body($config, $prompt);
                $response = wp_remote_post($config['api_url'], ['timeout' => $config['timeout'], 'headers' => [
                    'Authorization' => 'Bearer ' . $config['api_key'], 'Content-Type' => 'application/json',
                ], 'body' => $body]);
                $results[] = is_wp_error($response) ? $response : self::decode_response(
                    (int) wp_remote_retrieve_response_code($response), (string) wp_remote_retrieve_body($response)
                );
            }
            return $results;
        }
        $multi = curl_multi_init(); $handles = [];
        foreach (array_values($prompts) as $index => $prompt) {
            $handle = curl_init($config['api_url']);
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['api_key'], 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => self::request_body($config, $prompt), CURLOPT_CONNECTTIMEOUT => min(20, $config['timeout']),
                CURLOPT_TIMEOUT => $config['timeout'], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($multi, $handle); $handles[$index] = $handle;
        }
        do { $state = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 1.0); } while ($running && $state === CURLM_OK);
        $results = [];
        foreach ($handles as $index => $handle) {
            $error = curl_error($handle);
            $results[$index] = $error !== '' ? new WP_Error('review_transport_error', $error) : self::decode_response(
                (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) curl_multi_getcontent($handle)
            );
            curl_multi_remove_handle($multi, $handle); curl_close($handle);
        }
        curl_multi_close($multi); ksort($results);
        return array_values($results);
    }

    private static function request_body($config, $prompt) {
        $body = ['model' => $config['model'], 'temperature' => 0, 'messages' => [
            ['role' => 'system', 'content' => 'You are a conservative bilingual QA reviewer for technical product manuals. Output strict JSON only.'],
            ['role' => 'user', 'content' => $prompt],
        ]];
        if ($config['provider'] === 'doubao') $body['thinking'] = ['type' => 'disabled'];
        return wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function decode_response($status, $raw_body) {
        $raw = json_decode((string) $raw_body, true);
        if ($status < 200 || $status >= 300) return new WP_Error('review_http_error', (string) ($raw['error']['message'] ?? ('HTTP ' . $status)), ['status' => 502]);
        $content = trim((string) ($raw['choices'][0]['message']['content'] ?? ''));
        $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content));
        $data = json_decode($content, true);
        return is_array($data) ? $data : new WP_Error('review_invalid_json', '模型审核结果不是有效 JSON', ['status' => 502]);
    }

    private static function slot_map($occurrences) {
        $slots = [];
        foreach ($occurrences as $occurrence) {
            $id = (string) ($occurrence['occurrence_id'] ?? '');
            if ($id !== '') $slots['{{LISUN_TERM:' . $id . '}}'] = (string) ($occurrence['translated_text'] ?? '');
        }
        return $slots;
    }

    private static function readable_template($template, $slots) {
        return strtr((string) $template, (array) $slots);
    }

    private static function substantive_text($text) {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
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
