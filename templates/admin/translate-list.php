<?php
/**
 * 翻译管理页面 — 显示所有中文页面，按状态分两区
 */

if (!defined('ABSPATH')) {
    exit;
}

$all_zh = Lisun_Manual_CPT::get_zh_pages();
$is_configured = Lisun_Manual_Translate::is_configured();
$lisun_glossary_enabled = Lisun_Manual_Glossary::is_enabled();
$lang_pair = Lisun_Manual_Translate::get_language_pair();
$lang_names = [
    'zh' => '中文', 'en' => 'English', 'ja' => '日本語',
    'ko' => '한국어', 'de' => 'Deutsch', 'fr' => 'Français',
    'es' => 'Español', 'ru' => 'Русский',
];

// 型号徽标：从 product-no 自定义字段读取（与主列表「型号」列同源）
$lisun_model_badge = function ($post_id) {
    $model = get_post_meta($post_id, 'product-no', true);
    if (empty($model)) return '';
    return ' <code style="font-size:12px;color:#2271b1;">' . esc_html($model) . '</code>';
};

// 分组：翻译和推送是两个独立状态
$untranslated = [];   // 从未翻译或等待重译
$translated = [];     // 翻译已推送
$push_failed = [];    // 翻译成功但推送失败（译文已保存）
$failed = [];         // 实际翻译失败，不能伪装成“未翻译”
foreach ($all_zh as $post) {
    $status = get_post_meta($post->ID, '_lisun_manual_sync_status', true);
    if ($status === 'failed' && Lisun_Manual_Translate::repair_glossary_warning_status($post->ID)) {
        $status = 'synced';
    }
    if ($status === 'synced') {
        $translated[] = $post;
    } elseif ($status === 'translated' || $status === 'push_failed') {
        $push_failed[] = $post;
    } elseif ($status === 'failed') {
        $failed[] = $post;
    } else {
        $untranslated[] = $post;
    }
}
$total_count = count($all_zh);

$logs_result = Lisun_Manual_Translate_Log::get_logs(['per_page' => 20]);
$logs = $logs_result['logs'];
$log_total = $logs_result['total'];

?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('翻译管理', 'lisun-manual'); ?></h1>
    <hr class="wp-header-end">

    <?php
    // 翻译完成后的重定向消息
    $msg = $_GET['msg'] ?? '';
    if ($msg === 'done'):
        $time  = floatval($_GET['time'] ?? 0);
        $tokens = intval($_GET['tokens'] ?? 0);
        $chars  = intval($_GET['chars'] ?? 0);
    ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php _e('翻译完成！', 'lisun-manual'); ?></strong>
        <?php if ($chars > 0): ?>
            <?php printf(__('耗时 %.1f 秒，消耗 %d 字符。译文已保存为草稿，请在下方「已翻译」区查看。', 'lisun-manual'), $time, $chars); ?>
        <?php else: ?>
            <?php printf(__('耗时 %.1f 秒，消耗 %d tokens。译文已保存为草稿，请在下方「已翻译」区查看。', 'lisun-manual'), $time, $tokens); ?>
        <?php endif; ?></p>
    </div>
    <?php elseif ($msg === 'error'): ?>
    <div class="notice notice-error is-dismissible">
        <p><strong><?php _e('翻译失败：', 'lisun-manual'); ?></strong> <?php echo esc_html($_GET['detail'] ?? ''); ?></p>
    </div>
    <?php elseif ($msg === 'no_api'): ?>
    <div class="notice notice-error is-dismissible">
        <p><?php _e('请先在系统设置中配置 DeepSeek API 密钥。', 'lisun-manual'); ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$is_configured): ?>
    <div class="notice notice-warning">
        <p><?php _e('尚未配置 DeepSeek API 密钥。请在「系统设置」中配置。', 'lisun-manual'); ?></p>
    </div>
    <?php endif; ?>

    <!-- 当前翻译方向 -->
    <div class="notice notice-info inline" style="margin-bottom:15px;">
        <p>
            <strong><?php _e('翻译方向：', 'lisun-manual'); ?></strong>
            <?php echo esc_html($lang_names[$lang_pair['source']] ?? $lang_pair['source']); ?>
            →
            <?php echo esc_html($lang_names[$lang_pair['target']] ?? $lang_pair['target']); ?>
            &nbsp;|&nbsp;
            <?php printf(__('共 %d 篇：%d 已同步，%d 翻译待推送，%d 待翻译，%d 翻译失败', 'lisun-manual'), $total_count, count($translated), count($push_failed), count($untranslated), count($failed)); ?>
        </p>
    </div>

    <?php
    $lisun_deepl_fail_log = (array) get_option('lisun_deepl_glossary_fail_log', []);
    if (!empty($lisun_deepl_fail_log)):
    ?>
    <!-- DeepL 术语表同步失败提示：失败不降级，任务停在此处等待手动恢复 -->
    <div class="notice notice-error" style="border-left-color:#d63638;">
        <p>
            <strong><?php _e('DeepL 术语表同步失败', 'lisun-manual'); ?></strong>
            <?php
            $fail_parts = [];
            if (!empty($lisun_deepl_fail_log['http_code'])) $fail_parts[] = 'HTTP ' . (int) $lisun_deepl_fail_log['http_code'];
            if (!empty($lisun_deepl_fail_log['deepl_error'])) $fail_parts[] = '错误：' . esc_html((string) $lisun_deepl_fail_log['deepl_error']);
            if (!empty($lisun_deepl_fail_log['hash'])) $fail_parts[] = '术语集合哈希：' . esc_html(substr((string) $lisun_deepl_fail_log['hash'], 0, 12));
            if (!empty($lisun_deepl_fail_log['retry_count'])) $fail_parts[] = '已重试 ' . (int) $lisun_deepl_fail_log['retry_count'] . ' 次';
            echo implode('，', $fail_parts);
            ?>
            <br><span style="color:#666;"><?php _e('同步失败不会静默降级到旧术语表。请修复 DeepL 连接后先「重试术语同步」，再「重试并继续翻译」。', 'lisun-manual'); ?></span>
        </p>
        <p>
            <button type="button" class="button" id="lisun-retry-glossary-sync"><?php _e('重试术语同步', 'lisun-manual'); ?></button>
            <button type="button" class="button button-primary" id="lisun-retry-continue-translate"><?php _e('重试并继续翻译', 'lisun-manual'); ?></button>
        </p>
    </div>
    <?php endif; ?>

    <!-- ===== 待翻译 ===== -->
    <h2><?php _e('待翻译', 'lisun-manual'); ?> <span class="count">(<?php echo count($untranslated); ?>)</span></h2>

    <?php if (!empty($untranslated)): ?>
    <!-- 批量翻译进度条（默认隐藏） -->
    <div id="lisun-batch-progress" data-lisun-ui-version="2026-09-15-progress-friendly" style="display:none;margin:12px 0;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <strong id="lisun-batch-title" style="font-size:14px;"><?php _e('批量翻译中...', 'lisun-manual'); ?></strong>
            <span id="lisun-batch-counter" style="font-size:13px;color:#666;">0 / 0</span>
        </div>
        <div style="background:#e0e0e0;border-radius:4px;height:10px;overflow:hidden;">
            <div id="lisun-batch-bar" style="background:#2271b1;height:100%;width:1%;transition:width 0.3s;border-radius:4px;"></div>
        </div>
        <div id="lisun-batch-stage" style="margin-top:6px;font-size:12px;color:#2271b1;"><?php _e('正在等待服务器反馈…', 'lisun-manual'); ?></div>
        <div id="lisun-batch-current" style="margin-top:6px;font-size:12px;color:#888;"></div>
        <div id="lisun-batch-error-list" style="margin-top:6px;font-size:12px;color:#d63638;"></div>
        <div style="margin-top:10px;">
            <button type="button" class="button button-small" id="lisun-batch-cancel" style="color:#d63638;"><?php _e('取消', 'lisun-manual'); ?></button>
        </div>
    </div>

    <div class="lisun-table-actions" id="lisun-batch-buttons">
        <button type="button" class="button button-primary" id="lisun-translate-selected" <?php echo !$is_configured ? 'disabled' : ''; ?>>
            <?php _e('翻译选中', 'lisun-manual'); ?>
        </button>
        <button type="button" class="button" id="lisun-translate-all" <?php echo !$is_configured ? 'disabled' : ''; ?>>
            <?php _e('一键翻译全部', 'lisun-manual'); ?>
        </button>
        <button type="button" class="button" id="lisun-batch-resume" style="display:none;" title="<?php _e('继续上次未完成的翻译', 'lisun-manual'); ?>">
            <?php _e('继续翻译', 'lisun-manual'); ?>
        </button>
        <span class="spinner" style="float:none;margin:0;"></span>
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr data-id="<?php echo $post->ID; ?>">
                <th width="30"><input type="checkbox" id="lisun-select-all-translate"></th>
                <th><?php _e('标题', 'lisun-manual'); ?></th>
                <th width="120"><?php _e('状态', 'lisun-manual'); ?></th>
                <th width="200"><?php _e('操作', 'lisun-manual'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($untranslated as $post): ?>
            <tr data-id="<?php echo $post->ID; ?>">
                <td><input type="checkbox" class="lisun-translate-check" value="<?php echo $post->ID; ?>"></td>
                <td>
                    <strong>
                        <a href="<?php echo get_edit_post_link($post->ID); ?>">
                            <?php echo esc_html($post->post_title); ?>
                        </a>
                    </strong>
                    <?php echo $lisun_model_badge($post->ID); ?>
                    <?php if ($post->post_status === 'draft'): ?>
                        <span style="color:#999;">— <?php _e('草稿', 'lisun-manual'); ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="lisun-batch-status" style="color:#999;">— <?php _e('未翻译', 'lisun-manual'); ?></span></td>
                <td>
                    <button type="button" class="button button-small lisun-translate-single"
                            data-id="<?php echo $post->ID; ?>"
                            <?php echo !$is_configured ? 'disabled' : ''; ?>>
                        <?php _e('增量翻译', 'lisun-manual'); ?>
                    </button>
                    <button type="button" class="button button-small lisun-translate-full"
                            data-id="<?php echo $post->ID; ?>"
                            title="<?php echo esc_attr__('忽略已有译文，从头全量翻译', 'lisun-manual'); ?>"
                            <?php echo !$is_configured ? 'disabled' : ''; ?>>
                        <?php _e('全量翻译', 'lisun-manual'); ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($failed)): ?>
    <h2 style="margin-top:40px;color:#d63638;"><?php _e('翻译失败', 'lisun-manual'); ?> <span class="count">(<?php echo count($failed); ?>)</span></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr><th width="30"></th><th><?php _e('标题', 'lisun-manual'); ?></th><th width="120"><?php _e('状态', 'lisun-manual'); ?></th><th width="200"><?php _e('操作', 'lisun-manual'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($failed as $post): ?>
            <tr data-id="<?php echo $post->ID; ?>">
                <td><input type="checkbox" class="lisun-translate-check" value="<?php echo $post->ID; ?>"></td>
                <td>
                    <strong><a href="<?php echo get_edit_post_link($post->ID); ?>"><?php echo esc_html($post->post_title); ?></a></strong>
                    <?php echo $lisun_model_badge($post->ID); ?>
                </td>
                <td><span style="color:#d63638;">✗ <?php _e('翻译失败', 'lisun-manual'); ?></span></td>
                <td>
                    <button type="button" class="button button-small lisun-translate-single" data-id="<?php echo $post->ID; ?>" <?php echo !$is_configured ? 'disabled' : ''; ?>><?php _e('重试翻译', 'lisun-manual'); ?></button>
                    <a class="button button-small" href="<?php echo get_edit_post_link($post->ID); ?>"><?php _e('查看原因', 'lisun-manual'); ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ===== 翻译已保存，推送失败 ===== -->
    <?php if (!empty($push_failed)): ?>
    <h2 style="margin-top:30px;"><?php _e('翻译已保存，待推送', 'lisun-manual'); ?> <span class="count">(<?php echo count($push_failed); ?>)</span></h2>
    <p class="description" style="color:#d63638;"><?php _e('以下页面翻译成功，译文已保存为草稿，但推送到远程站失败。请检查远程站连接后重试推送，无需重新翻译。', 'lisun-manual'); ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('中文原文', 'lisun-manual'); ?></th>
                <th><?php _e('英文译文', 'lisun-manual'); ?></th>
                <th width="120"><?php _e('状态', 'lisun-manual'); ?></th>
                <th width="120"><?php _e('操作', 'lisun-manual'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($push_failed as $post): ?>
            <?php
                $backup = Lisun_Manual_Translate::get_translation_backup($post->ID);
            ?>
            <tr>
                <td>
                    <strong><a href="<?php echo get_edit_post_link($post->ID); ?>"><?php echo esc_html($post->post_title); ?></a></strong>
                    <?php echo $lisun_model_badge($post->ID); ?>
                </td>
                <td>
                    <?php if ($backup): ?>
                        <span><?php echo esc_html($backup['en_title'] ?? ''); ?></span>
                        <span style="font-size:12px;color:#999;">(<?php echo esc_html($backup['saved_at'] ?? ''); ?>)</span>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td><span style="color:#d63638;"><?php _e('推送失败', 'lisun-manual'); ?></span></td>
                <td>
                    <button type="button" class="button button-small lisun-retry-push" data-id="<?php echo $post->ID; ?>"><?php _e('重试推送', 'lisun-manual'); ?></button>
                    <button type="button" class="button button-small lisun-retranslate-incr" data-id="<?php echo $post->ID; ?>"><?php _e('增量重译', 'lisun-manual'); ?></button>
                    <button type="button" class="button button-small lisun-retranslate" data-id="<?php echo $post->ID; ?>"><?php _e('全量重译', 'lisun-manual'); ?></button>
                    <?php if ($backup): ?>
                    <button type="button" class="button button-small lisun-delete-backup"
                            data-id="<?php echo $post->ID; ?>"
                            style="color:#b32d2e;"
                            title="删除本地翻译备份，不影响文章和远程站">删除备份</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ===== 已翻译 ===== -->
    <h2 style="margin-top:40px;"><?php _e('已翻译', 'lisun-manual'); ?> <span class="count">(<?php echo count($translated); ?>)</span></h2>

    <?php if (!empty($translated)): ?>
    <div style="margin-bottom:8px;">
        <button type="button" class="button button-primary" id="lisun-batch-retry-push">
            <?php _e('重推选中', 'lisun-manual'); ?>
        </button>
        <button type="button" class="button" id="lisun-batch-retranslate-incr">
            <?php _e('增量重译选中', 'lisun-manual'); ?>
        </button>
        <button type="button" class="button" id="lisun-batch-retranslate">
            <?php _e('全量重译选中', 'lisun-manual'); ?>
        </button>
        <span id="lisun-batch-done-spinner" class="spinner" style="float:none;margin:0 0 0 8px;"></span>
    </div>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="30"><input type="checkbox" id="lisun-select-all-done"></th>
                <th><?php _e('中文原文', 'lisun-manual'); ?></th>
                <th><?php _e('英文译文', 'lisun-manual'); ?></th>
                <th width="100"><?php _e('远程ID', 'lisun-manual'); ?></th>
                <th width="120"><?php _e('翻译时间', 'lisun-manual'); ?></th>
                <th width="140"><?php _e('操作', 'lisun-manual'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($translated as $post): ?>
            <?php
                $last_sync = get_post_meta($post->ID, '_lisun_manual_last_sync', true);
                $has_backup = Lisun_Manual_Translate::get_translation_backup($post->ID);
                $locked = Lisun_Manual_Translate::is_en_locked($post->ID);
                $glossary_apply_last = get_post_meta($post->ID, '_lisun_manual_v2_glossary_apply_last', true);
                $term_anomalies = get_post_meta($post->ID, '_lisun_manual_v2_term_anomalies', true);
                $term_anomalies = is_array($term_anomalies) ? $term_anomalies : [];
            ?>
            <tr data-id="<?php echo $post->ID; ?>">
                <td><input type="checkbox" class="lisun-done-check" value="<?php echo $post->ID; ?>" <?php echo $locked ? 'disabled' : ''; ?>></td>
                <td>
                    <strong>
                        <a href="<?php echo get_edit_post_link($post->ID); ?>">
                            <?php echo esc_html($post->post_title); ?>
                        </a>
                    </strong>
                    <?php echo $lisun_model_badge($post->ID); ?>
                    <?php if ($locked): ?>
                        <span class="lisun-lock-badge" style="color:#d63638;font-weight:600;">🔒 <?php _e('已锁定', 'lisun-manual'); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="color:#00a32a;">✓ <?php _e('已推送至远程站', 'lisun-manual'); ?></span>
                    <?php if ($has_backup): ?>
                        <span style="font-size:11px;color:#999;">(<?php echo esc_html($has_backup['saved_at'] ?? ''); ?>)</span>
                    <?php endif; ?>
                    <?php if (is_array($glossary_apply_last) && !empty($glossary_apply_last['time'])): ?>
                        <br><span class="lisun-glossary-apply-record" style="font-size:11px;color:#50575e;">
                            <?php
                            printf(
                                '术语表最近应用：%s；替换 %d 处；DeepL %d 次',
                                esc_html((string) $glossary_apply_last['time']),
                                (int) ($glossary_apply_last['updated'] ?? 0),
                                (int) ($glossary_apply_last['requests'] ?? 0)
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($term_anomalies): ?>
                        <br><span style="font-size:11px;color:#9a6700;">⚠ 术语表检查异常：<?php echo count($term_anomalies); ?> 个段落需人工核对</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $remote_id = get_post_meta($post->ID, '_lisun_manual_remote_id', true); ?>
                    <?php if ($remote_id): ?>
                        <code style="font-size:12px;"><?php echo intval($remote_id); ?></code>
                    <?php else: ?>
                        <span style="color:#999;">—</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $last_sync ?: '—'; ?></td>
                <td>
                    <button type="button" class="button button-small lisun-toggle-lock <?php echo $locked ? 'button-primary' : ''; ?>"
                            data-id="<?php echo $post->ID; ?>"
                            data-lock="<?php echo $locked ? 'unlocked' : 'locked'; ?>"
                            title="<?php echo esc_attr($locked ? '解锁后恢复自动翻译' : '锁定后跳过翻译/重推，保护远程站英文'); ?>">
                        <?php echo $locked ? '🔓 解锁' : '🔒 锁定'; ?>
                    </button>
                    <?php if ($has_backup): ?>
                    <button type="button" class="button button-small lisun-retry-push"
                            data-id="<?php echo $post->ID; ?>" <?php echo $locked ? 'disabled' : ''; ?>>
                        <?php _e('重推', 'lisun-manual'); ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="button button-small lisun-retranslate-incr"
                            data-id="<?php echo $post->ID; ?>" <?php echo $locked ? 'disabled' : ''; ?>>
                        <?php _e('增量重译', 'lisun-manual'); ?>
                    </button>
                    <button type="button" class="button button-small lisun-retranslate"
                            data-id="<?php echo $post->ID; ?>" <?php echo $locked ? 'disabled' : ''; ?>>
                        <?php _e('全量重译', 'lisun-manual'); ?>
                    </button>
                    <?php if ($has_backup): ?>
                    <button type="button" class="button button-small lisun-delete-backup"
                            data-id="<?php echo $post->ID; ?>"
                            style="color:#b32d2e;"
                            title="删除本地翻译备份，不影响文章和远程站">删除备份</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color:#999;"><?php _e('暂无已翻译页面', 'lisun-manual'); ?></p>
    <?php endif; ?>

    <!-- ===== 翻译日志 ===== -->
    <h2 style="margin-top:40px;"><?php _e('翻译日志', 'lisun-manual'); ?></h2>

    <?php if (!empty($logs)): ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="60">ID</th>
                <th><?php _e('中文页面', 'lisun-manual'); ?></th>
                <th width="80"><?php _e('状态', 'lisun-manual'); ?></th>
                <th width="60"><?php _e('耗时(s)', 'lisun-manual'); ?></th>
                <th width="80"><?php _e('Tokens', 'lisun-manual'); ?></th>
                <th width="80"><?php _e('字符', 'lisun-manual'); ?></th>
                <th><?php _e('错误', 'lisun-manual'); ?></th>
                <th width="140"><?php _e('时间', 'lisun-manual'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?php echo $log['id']; ?></td>
                <td><?php echo $log['zh_page_id'] ? esc_html(get_the_title($log['zh_page_id']) ?: '#' . $log['zh_page_id']) : '—'; ?></td>
                <td><span class="lisun-status-<?php echo esc_attr($log['status']); ?>"><?php echo in_array($log['status'], ['success', 'glossary_reapply', 'repush_success'], true) ? '✓' : '✗'; ?></span></td>
                <td><?php echo round($log['duration'], 1); ?></td>
                <td><?php echo number_format(($log['prompt_tokens'] ?? 0) + ($log['completion_tokens'] ?? 0)); ?></td>
                <td><?php echo !empty($log['chars']) ? number_format(intval($log['chars'])) : '—'; ?></td>
                <td style="color:#d63638;font-size:12px;"><?php echo esc_html($log['error_msg'] ?? ''); ?></td>
                <td><?php echo $log['created_at']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p><?php printf(__('共 %d 条记录', 'lisun-manual'), $log_total); ?></p>
    <?php else: ?>
    <p style="color:#999;"><?php _e('暂无翻译日志。', 'lisun-manual'); ?></p>
    <?php endif; ?>
</div>

<script>
        /* lisun-translate-ui: 2026-09-15-progress-friendly */
jQuery(function ($) {
    var nonce = '<?php echo wp_create_nonce("lisun_manual_nonce"); ?>';
    var restNonce = '<?php echo wp_create_nonce("wp_rest"); ?>';
    var restBase = '<?php echo esc_js(rest_url("lisun-manual/v1")); ?>';
    var BATCH_KEY = 'lisun_batch_state'; // sessionStorage key
    var batchCanceled = false;

    // ==================== 工具函数 ====================

    function restPost(path, data, opts) {
        opts = opts || {};
        return $.ajax({
            url: restBase + path,
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify(data),
            timeout: opts.timeout || 30000,
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', restNonce);
            }
        });
    }

    function restGet(path) {
        return $.ajax({
            url: restBase + path,
            method: 'GET',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); }
        });
    }

    function showNotice(msg, type, sticky) {
        type = type || 'success';
        var $notice = $('<div class="notice notice-' + type + ' inline" style="margin:10px 0;"><p></p></div>');
        $notice.find('p').text(msg);
        $('.wrap h1').first().after($notice);
        if (!sticky) setTimeout(function () { $notice.fadeOut(); }, 8000);
    }

    function ajaxErrorMessage(xhr, textStatus, errorThrown) {
        var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
        var message = response && response.data && response.data.message
            ? response.data.message
            : (response && response.message ? response.message : '');
        if (!message && xhr && xhr.responseText) {
            try {
                var parsed = JSON.parse(xhr.responseText);
                message = parsed && parsed.data && parsed.data.message
                    ? parsed.data.message
                    : (parsed && parsed.message ? parsed.message : '');
            } catch (e) {}
        }
        if (message) return message;
        var status = xhr && xhr.status ? 'HTTP ' + xhr.status : '';
        if (textStatus === 'timeout') return '请求超时（浏览器等待期已结束），服务器可能仍在处理';
        var preview = '';
        if (xhr && xhr.responseText) {
            preview = $.trim(String(xhr.responseText)
                .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                .replace(/<[^>]+>/g, ' ')
                .replace(/\s+/g, ' '));
            preview = preview.slice(0, 280);
        }
        if (preview) return (status ? status + '：' : '请求失败：') + preview;
        if (errorThrown) return (status ? status + '：' : '请求失败：') + errorThrown;
        return status ? status + '，服务器未返回可读错误信息' : '请求失败，服务器未返回响应';
    }

    // ==================== 批量翻译进度条 ====================

    var lisunBatchContext = { total: 1, completed: 0 };
    var lisunDisplayedProgress = 1;
    var lisunTargetProgress = 1;
    var lisunProgressTimer = null;

    function stageLabel(stage) {
        var labels = {
            queued: '等待开始',
            starting: '启动翻译',
            extracting: '提取翻译单元',
            planning: '分析可复用译文',
            translating: '翻译翻译单元',
            rendering: '组装并检查译文',
            saving: '保存译文',
            pushing: '推送到远程站',
            done: '已完成',
            error: '处理失败'
        };
        return labels[stage] || '处理中';
    }

    function startFriendlyProgress() {
        if (lisunProgressTimer) clearInterval(lisunProgressTimer);
        lisunDisplayedProgress = 1;
        lisunTargetProgress = 1;
        $('#lisun-batch-bar').css('width', '1%');
        lisunProgressTimer = setInterval(function () {
            // 没有新服务器反馈时也缓慢前进，但处理中不会跑到完成。
            var next = Math.min(94, Math.max(lisunTargetProgress, lisunDisplayedProgress + 1));
            if (next !== lisunDisplayedProgress) {
                lisunDisplayedProgress = next;
                $('#lisun-batch-bar').css('width', lisunDisplayedProgress + '%');
            }
        }, 1500);
    }

    function finishFriendlyProgress() {
        if (lisunProgressTimer) clearInterval(lisunProgressTimer);
        lisunProgressTimer = null;
        lisunDisplayedProgress = 100;
        lisunTargetProgress = 100;
        $('#lisun-batch-bar').css('width', '100%');
    }

    function setTaskProgress(progress, stage, message) {
        var itemPct = Math.max(1, Math.min(100, parseInt(progress, 10) || 1));
        var total = Math.max(1, lisunBatchContext.total || 1);
        var completed = Math.max(0, lisunBatchContext.completed || 0);
        var overallPct = total > 1
            ? Math.round(((completed + itemPct / 100) / total) * 100)
            : itemPct;
        lisunTargetProgress = Math.max(lisunTargetProgress, Math.min(94, Math.max(1, overallPct)));
        $('#lisun-batch-bar').css('width', Math.max(lisunDisplayedProgress, lisunTargetProgress) + '%');
        lisunDisplayedProgress = Math.max(lisunDisplayedProgress, lisunTargetProgress);
        $('#lisun-batch-stage').text('当前阶段：' + stageLabel(stage));
        if (message) $('#lisun-batch-current').text(message);
        if (total === 1 && !message) $('#lisun-batch-counter').text('处理中…');
    }

    function showProgress(ids, done, failed) {
        $('#lisun-batch-progress').show();
        $('#lisun-batch-buttons').hide();
        var total = ids.length;
        var completed = done.length + failed.length;
        lisunBatchContext = { total: total, completed: completed };
        startFriendlyProgress();
        var pct = total > 0 ? Math.round(completed / total * 100) : 0;
        $('#lisun-batch-counter').text(completed + ' / ' + total);
        lisunTargetProgress = Math.max(1, pct);
        lisunDisplayedProgress = Math.max(lisunDisplayedProgress, lisunTargetProgress);
        $('#lisun-batch-bar').css('width', lisunDisplayedProgress + '%');
        $('#lisun-batch-stage').text(completed > 0 ? '已完成部分任务，准备继续…' : '正在等待服务器反馈…');
        if (pct >= 100) {
            $('#lisun-batch-bar').css('background', '#00a32a');
        }
    }

    function updateCurrentItem(title, index, total) {
        $('#lisun-batch-current').text('正在翻译第 ' + (index + 1) + ' 篇：' + title);
        $('#lisun-batch-title').text('批量翻译中 (' + (index + 1) + '/' + total + ')');
        $('#lisun-batch-stage').text('当前阶段：等待开始');
    }

    function updateProgressCounts(ids, done, failed) {
        var total = ids.length;
        var completed = done.length + failed.length;
        lisunBatchContext = { total: total, completed: completed };
        var pct = total > 0 ? Math.round(completed / total * 100) : 0;
        $('#lisun-batch-counter').text(completed + ' / ' + total);
        lisunTargetProgress = Math.max(lisunTargetProgress, Math.max(1, pct));
        lisunDisplayedProgress = Math.max(lisunDisplayedProgress, lisunTargetProgress);
        $('#lisun-batch-bar').css('width', lisunDisplayedProgress + '%');
        $('#lisun-batch-stage').text(pct >= 100 ? '全部任务已完成' : '已完成 ' + completed + ' / ' + total + ' 篇');
        if (pct >= 100) {
            $('#lisun-batch-bar').css('background', '#00a32a');
        }
        if (failed.length > 0) {
            $('#lisun-batch-error-list').html('<span style="color:#d63638;">⚠ 失败 ' + failed.length + ' 篇（ID: ' + failed.join(', ') + '）</span>');
        }
    }

    function saveBatchState(ids, done, failed) {
        try {
            sessionStorage.setItem(BATCH_KEY, JSON.stringify({
                ids: ids, done: done, failed: failed, time: Date.now()
            }));
        } catch (e) {}
    }

    function clearBatchState() {
        try { sessionStorage.removeItem(BATCH_KEY); } catch (e) {}
    }

    function loadBatchState() {
        try {
            var raw = sessionStorage.getItem(BATCH_KEY);
            if (!raw) return null;
            var state = JSON.parse(raw);
            // 只保留 1 小时内的状态
            if (Date.now() - state.time > 3600000) { clearBatchState(); return null; }
            return state;
        } catch (e) { return null; }
    }

    // ==================== 页面加载：检测未完成的批量任务 ====================

    var savedState = loadBatchState();
    if (savedState && savedState.ids && savedState.done && savedState.ids.length > savedState.done.length + (savedState.failed || []).length) {
        var remaining = savedState.ids.length - savedState.done.length - (savedState.failed || []).length;
        $('#lisun-batch-resume').show().text('继续翻译（剩余 ' + remaining + ' 篇）');
    }

    // ==================== 单个翻译（异步：提交任务 → 轮询结果） ====================

    function pollUntilDone(postId, $btn, $status, title, onDone) {
        var pollCount = 0;
        var pollTimer = setInterval(function () {
            pollCount++;
            restGet('/translate/progress/' + postId).done(function (p) {
                if (!p.active) return;

                if (p.step === 'done') {
                    clearInterval(pollTimer);
                    $btn.prop('disabled', false).text('翻译');
                    $status.html('<span style="color:green;">✓ 已翻译</span>');
                    finishFriendlyProgress();
                    $('#lisun-batch-stage').text('当前阶段：已完成');
                    if (p.result) {
                        if (p.result.pushed) {
                            var cost = (p.result.chars && p.result.chars > 0)
                                ? ('，消耗 ' + p.result.chars + ' 字符')
                                : ('，消耗 ' + ((p.result.prompt_tokens || 0) + (p.result.completion_tokens || 0)) + ' tokens');
                            showNotice('「' + title + '」翻译完成并已推送！耗时 ' + (p.result.duration || '?') + ' 秒' + cost);
                        } else {
                            showNotice('「' + title + '」翻译完成（推送失败：' + (p.result.push_msg || '') + '）', 'error');
                        }
                    } else {
                        showNotice('「' + title + '」翻译完成！');
                    }
                    if (onDone) {
                        onDone(true); // 批量模式：回调继续下一个，不刷新
                    } else {
                        setTimeout(function () { location.reload(); }, 2000); // 单篇模式：刷新页面
                    }
                } else if (p.step === 'error') {
                    clearInterval(pollTimer);
                    $btn.prop('disabled', false).text('翻译');
                    $status.html('<span style="color:red;">✗ 失败</span>');
                    $('#lisun-batch-stage').text('当前阶段：处理失败');
                    showNotice('「' + title + '」翻译失败：' + (p.error || p.msg), 'error');
                    if (!onDone) {
                        $('#lisun-batch-progress').hide();
                        $('#lisun-batch-buttons').show();
                    }
                    if (onDone) onDone(false);
                } else {
                    var stage = p.stage || p.step || 'processing';
                    $btn.text(stageLabel(stage) + '...');
                    $status.html('<span style="color:#2271b1;">⏳ ' + p.msg + '</span>');
                    // V2 使用后端上报的阶段进度；旧版继续兼容 "2/4"。
                    if (typeof p.progress !== 'undefined' && p.progress !== null) {
                        setTaskProgress(p.progress, stage, p.msg || '正在处理…');
                        if (p.total_units) {
                            $('#lisun-batch-counter').text((p.completed_units || 0) + ' / ' + p.total_units + ' 个单元');
                        }
                    }
                    var parts = p.step.split('/');
                    if (parts.length === 2 && (typeof p.progress === 'undefined' || p.progress === null)) {
                        var cur = parseInt(parts[0]) || 0;
                        var total = parseInt(parts[1]) || 1;
                        var pct = Math.round(cur / total * 100);
                        $('#lisun-batch-bar').css('width', Math.max(1, pct) + '%');
                        $('#lisun-batch-counter').text(cur + ' / ' + total);
                        $('#lisun-batch-title').text('翻译中');
                        $('#lisun-batch-current').text(p.msg || '正在处理...');
                    }
                }
            });
        }, 2000);

        // 超时保护：600 秒
        setTimeout(function () {
            clearInterval(pollTimer);
            if ($btn.prop('disabled')) {
                $btn.prop('disabled', false).text('翻译');
                $status.html('<span style="color:red;">✗ 超时</span>');
                showNotice('「' + title + '」翻译超时（超过 10 分钟）', 'error');
                if (onDone) onDone(false);
            }
        }, 600000);
    }

    $('.lisun-translate-single').on('click', function () {
        var $btn = $(this);
        var postId = $btn.data('id');
        var $row = $btn.closest('tr');
        var title = $row.find('strong').first().text().trim();
        var $status = $row.find('.lisun-batch-status');
        lisunBatchContext = { total: 1, completed: 0 };
        $('#lisun-batch-progress').show();
        $('#lisun-batch-buttons').hide();
        $('#lisun-batch-title').text('翻译中');
        $('#lisun-batch-counter').text('处理中…');
        startFriendlyProgress();
        $('#lisun-batch-bar').css('background', '#2271b1');
        $('#lisun-batch-stage').text('当前阶段：等待开始');
        $('#lisun-batch-current').text(title);
        $('#lisun-batch-cancel').hide();
        $btn.prop('disabled', true).text('排队中...');
        $status.html('<span style="color:#2271b1;">⏳ 排队中...</span>');

        // 提交翻译任务（立即返回，不阻塞）
        restPost('/translate', { post_id: postId }, { timeout: 30000 })
        .done(function (res) {
            if (res.success && res.data.status === 'queued') {
                $btn.text('翻译中...');
                pollUntilDone(postId, $btn, $status, title, null);
            } else if (res.success && res.data.status === 'already_queued') {
                pollUntilDone(postId, $btn, $status, title, null);
            } else {
                $btn.prop('disabled', false).text('翻译');
                $status.html('<span style="color:red;">✗ 失败</span>');
                showNotice('「' + title + '」提交失败：' + (res.data.message || ''), 'error');
            }
        })
        .fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('翻译');
            $status.html('<span style="color:red;">✗ 网络错误</span>');
            showNotice('「' + title + '」请求失败（' + textStatus + '）', 'error');
        });
    });

    // ==================== 批量翻译（选中 / 全部 / 续传） ====================

    function startBatch(ids, full) {
        full = !!full;
        if (ids.length === 0) { showNotice('没有待翻译的页面', 'error'); return; }

        batchCanceled = false;
        var done = [];
        var failed = [];

        // 检查是否有保存的进度（续传场景）
        var state = loadBatchState();
        if (state && arraysEqual(state.ids, ids)) {
            done = state.done || [];
            failed = state.failed || [];
        }

        showProgress(ids, done, failed);
        processNext(ids, done, failed, 0, full);
    }

    function arraysEqual(a, b) {
        if (a.length !== b.length) return false;
        for (var i = 0; i < a.length; i++) {
            if (parseInt(a[i]) !== parseInt(b[i])) return false;
        }
        return true;
    }

    function processNext(ids, done, failed, currentIndex, full) {
        if (batchCanceled) {
            finishBatch(ids, done, failed, true);
            return;
        }

        // 找出下一个待翻译的（跳过已完成的）
        var idx = currentIndex;
        while (idx < ids.length) {
            var id = parseInt(ids[idx]);
            if (done.indexOf(id) === -1 && failed.indexOf(id) === -1) {
                break;
            }
            idx++;
        }

        if (idx >= ids.length) {
            // 全部完成
            finishBatch(ids, done, failed, false);
            return;
        }

        var postId = parseInt(ids[idx]);
        var $row = $('tr[data-id="' + postId + '"]');
        var title = $row.find('strong').first().text().trim();
        var $status = $row.find('.lisun-batch-status');
        var $btn = $row.find('.lisun-translate-single'); // 可能没有

        updateCurrentItem(title, idx, ids.length);
        $status.html('<span style="color:#2271b1;">⏳ 排队中 (' + (idx + 1) + '/' + ids.length + ')...</span>');

        // 提交翻译任务（立即返回，通过轮询等待结果）
        restPost(full ? '/translate/retranslate' : '/translate', { post_id: postId }, { timeout: 30000 })
        .done(function (res) {
            if (batchCanceled) { finishBatch(ids, done, failed, true); return; }

            if (res.success && (res.data.status === 'queued' || res.data.status === 'already_queued')) {
                $status.html('<span style="color:#2271b1;">⏳ 翻译中 (' + (idx + 1) + '/' + ids.length + ')...</span>');
                // 轮询等待完成
                pollUntilDone(postId, $btn || $status, $status, title, function (ok) {
                    if (batchCanceled) { finishBatch(ids, done, failed, true); return; }
                    if (ok) {
                        done.push(postId);
                    } else {
                        failed.push(postId);
                    }
                    updateProgressCounts(ids, done, failed);
                    saveBatchState(ids, done, failed);
                    processNext(ids, done, failed, idx + 1, full);
                });
            } else {
                failed.push(postId);
                $status.html('<span style="color:red;">✗ ' + ((res.data || {}).message || '提交失败').substring(0, 20) + '</span>');
                updateProgressCounts(ids, done, failed);
                saveBatchState(ids, done, failed);
                processNext(ids, done, failed, idx + 1, full);
            }
        })
        .fail(function (jqXHR, textStatus) {
            if (batchCanceled) { finishBatch(ids, done, failed, true); return; }
            failed.push(postId);
            $status.html('<span style="color:red;">✗ 网络错误</span>');
            updateProgressCounts(ids, done, failed);
            saveBatchState(ids, done, failed);
            processNext(ids, done, failed, idx + 1, full);
        });
    }

    function finishBatch(ids, done, failed, wasCanceled) {
        clearBatchState();
        $('#lisun-batch-current').text(wasCanceled ? '已取消' : '全部完成');
        $('#lisun-batch-cancel').hide();
        $('#lisun-batch-resume').hide();

        if (!wasCanceled) {
            var ok = done.length;
            var fail = failed.length;
            var msg = '批量翻译完成：成功 ' + ok + ' 篇';
            if (fail > 0) msg += '，失败 ' + fail + ' 篇';
            showNotice(msg, fail > 0 ? 'error' : 'success');
            $('#lisun-batch-done-spinner').removeClass('is-active');
            $('#lisun-batch-retranslate, #lisun-batch-retranslate-incr, #lisun-batch-retry-push').prop('disabled', false);
            setTimeout(function () { location.reload(); }, 3000);
        } else {
            showNotice('批量翻译已取消（已完成 ' + done.length + ' 篇，失败 ' + failed.length + ' 篇）', 'error');
            $('#lisun-batch-buttons').show();
            $('#lisun-batch-progress').hide();
            if (done.length + failed.length < ids.length) {
                $('#lisun-batch-resume').show().text('继续翻译（剩余 ' + (ids.length - done.length - failed.length) + ' 篇）');
            }
        }
    }

    // 取消按钮
    $('#lisun-batch-cancel').on('click', function () {
        if (confirm('确定取消批量翻译？已完成的不会丢失。')) {
            batchCanceled = true;
        }
    });

    // 翻译选中（增量）
    $('#lisun-translate-selected').on('click', function () {
        var ids = $('.lisun-translate-check:checked').map(function () { return this.value; }).get();
        if (ids.length === 0) { showNotice('请先勾选要翻译的页面', 'error'); return; }
        startBatch(ids, false);
    });

    // 翻译全部（增量）
    $('#lisun-translate-all').on('click', function () {
        var ids = [];
        $('.lisun-translate-check').each(function () { ids.push(this.value); });
        if (ids.length === 0) { showNotice('没有待翻译的页面', 'error'); return; }
        if (!confirm('确定要翻译全部 ' + ids.length + ' 篇说明书吗？可能需要一些时间。\n\n翻译过程中请勿关闭页面，已翻译的会自动保存。')) return;
        startBatch(ids, false);
    });

    // 继续翻译（续传，增量）
    $('#lisun-batch-resume').on('click', function () {
        var state = loadBatchState();
        if (!state || !state.ids) { showNotice('没有可继续的任务', 'error'); return; }
        startBatch(state.ids, false);
    });

    // ==================== DeepL 术语表同步失败恢复 ====================

    $('#lisun-retry-glossary-sync').on('click', function () {
        var $btn = $(this).prop('disabled', true).text('重试中…');
        $.post(ajaxurl, {
            action: 'lisun_retry_glossary_sync',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('重试术语同步');
            if (res.success) {
                showNotice(res.data.message || '术语同步已恢复', 'success');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                showNotice('重试术语同步失败：' + (res.data.message || '未知错误'), 'error');
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('重试术语同步');
            showNotice('请求失败：HTTP ' + (jqXHR.status || 0) + ' / ' + textStatus, 'error');
        });
    });

    $('#lisun-retry-continue-translate').on('click', function () {
        var ids = <?php echo wp_json_encode(array_map(function ($p) { return (int) $p->ID; }, $failed)); ?>;
        if (!ids || ids.length === 0) { showNotice('没有失败的翻译任务', 'error'); return; }
        if (!confirm('确定重新翻译 ' + ids.length + ' 篇失败的页面？翻译时会按各篇术语重新同步 DeepL 术语表。')) return;
        clearBatchState();
        startBatch(ids, true);
    });

    // 已翻译区全选（跳过锁定的行）
    $('#lisun-select-all-done').on('change', function () {
        $('.lisun-done-check:not(:disabled)').prop('checked', this.checked);
    });

    // 批量重推（用备份数据直接推送，不重新翻译）
    $('#lisun-batch-retry-push').on('click', function () {
        var ids = $('.lisun-done-check:checked:not(:disabled)').map(function () { return this.value; }).get();
        if (ids.length === 0) { showNotice('请先勾选要重推的页面', 'error'); return; }
        if (!confirm('确定批量重推 ' + ids.length + ' 篇？使用备份数据直接推送，不重新翻译。')) return;

        var $btn = $(this).prop('disabled', true);
        $('#lisun-batch-done-spinner').addClass('is-active');
        doBatchRetryPush(ids, 0, 0, ids.length);
    });

    function doBatchRetryPush(ids, index, ok, total) {
        if (index >= ids.length) {
            $('#lisun-batch-done-spinner').removeClass('is-active');
            $('#lisun-batch-retry-push').prop('disabled', false);
            showNotice('批量重推完成：成功 ' + ok + '/' + total + ' 篇', ok === total ? 'success' : 'error');
            setTimeout(function () { location.reload(); }, 2000);
            return;
        }

        var $row = $('tr[data-id="' + ids[index] + '"]');
        var $status = $row.find('td').eq(1); // 英文译文列
        $status.html('<span style="color:#2271b1;">⏳ 推送中...</span>');

        restPost('/translate/retry-push', { post_id: parseInt(ids[index]) }, { timeout: 60000 })
        .always(function () {
            var newOk = ok;
            if (arguments[0] && arguments[0].success) {
                $status.html('<span style="color:#00a32a;">✓ 已推送</span>');
                newOk++;
            } else {
                $status.html('<span style="color:#d63638;">✗ 推送失败</span>');
            }
            doBatchRetryPush(ids, index + 1, newOk, total);
        });
    }

    // 批量增量重译（只翻变更段）
    $('#lisun-batch-retranslate-incr').on('click', function () {
        var ids = $('.lisun-done-check:checked:not(:disabled)').map(function () { return this.value; }).get();
        if (ids.length === 0) { showNotice('请先勾选要重译的页面', 'error'); return; }

        var $btn = $(this).prop('disabled', true);
        $('#lisun-batch-done-spinner').addClass('is-active');
        startBatch(ids, false);
    });

    // 批量全量重译（忽略已有译文，从头翻译+推送）
    $('#lisun-batch-retranslate').on('click', function () {
        var ids = $('.lisun-done-check:checked:not(:disabled)').map(function () { return this.value; }).get();
        if (ids.length === 0) { showNotice('请先勾选要重译的页面', 'error'); return; }
        if (!confirm('确定「全量」重译 ' + ids.length + ' 篇？会忽略已有译文，从头重新翻译并推送。')) return;

        var $btn = $(this).prop('disabled', true);
        $('#lisun-batch-done-spinner').addClass('is-active');
        startBatch(ids, true);
    });

    // 全选
    $('#lisun-select-all-translate').on('change', function () {
        $('.lisun-translate-check').prop('checked', this.checked);
    });

    // ==================== 重试推送 ====================

    $('.lisun-retry-push').on('click', function () {
        var $btn = $(this);
        var postId = $btn.data('id');
        var title = $btn.closest('tr').find('strong').first().text().trim();
        $btn.prop('disabled', true).text('推送中...');

        restPost('/translate/retry-push', { post_id: postId }, { timeout: 30000 })
        .done(function (res) {
            $btn.prop('disabled', false).text('重试推送');
            if (res.success) {
                showNotice('「' + title + '」推送成功！');
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                showNotice('推送失败：' + (res.data.message || ''), 'error');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('重试推送');
            showNotice('推送请求失败', 'error');
        });
    });

    // 删除本地翻译备份：只删除本地 lisun_trans_bak_{post_id}，不影响文章或远程站
    $('.lisun-delete-backup').on('click', function () {
        var $btn = $(this);
        var postId = $btn.data('id');
        var title = $btn.closest('tr').find('strong').first().text().trim();
        if (!confirm('确定删除「' + title + '」的本地翻译备份吗？\n\n不会删除中文文章、英文草稿或远程英文页面，但删除后无法用该备份重推或应用术语表。')) return;

        $btn.prop('disabled', true).text('删除中...');
        restPost('/translate/delete-backup', { post_id: postId }, { timeout: 30000 })
        .done(function (res) {
            if (res.success) {
                showNotice('「' + title + '」的本地翻译备份已删除。');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                $btn.prop('disabled', false).text('删除备份');
                showNotice('删除失败：' + (res.data.message || ''), 'error');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('删除备份');
            showNotice('删除请求失败', 'error');
        });
    });

    // ==================== 页面级锁定（方案 A） ====================

    $('.lisun-toggle-lock').on('click', function () {
        var $btn = $(this);
        var postId = $btn.data('id');
        var target = $btn.data('lock'); // 'locked' | 'unlocked'
        var title = $btn.closest('tr').find('strong').first().text().trim();
        $btn.prop('disabled', true);

        restPost('/translate/page-lock', { post_id: postId, lock: target }, { timeout: 30000 })
        .done(function (res) {
            if (res.success) {
                showNotice('「' + title + '」' + (res.data.locked ? '已锁定：后续翻译/重推将跳过此篇' : '已解锁：恢复自动翻译'));
                setTimeout(function () { location.reload(); }, 800);
            } else {
                $btn.prop('disabled', false);
                showNotice('操作失败：' + (res.data.message || ''), 'error');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false);
            showNotice('请求失败', 'error');
        });
    });

    // ==================== 单篇重译（增量 / 全量，异步） ====================

    function queueTranslate(postId, $btn, title, full, label) {
        full = !!full;
        label = label || (full ? '全量重译' : '增量重译');
        if (full && !confirm('确定「全量」重新翻译「' + title + '」？会忽略已有译文，从头翻译并覆盖当前译文。')) return;

        $btn.prop('disabled', true).text('排队中...');

        // 显示进度条
        $('#lisun-batch-progress').show();
        $('#lisun-batch-title').text(label + '中');
        $('#lisun-batch-counter').text('处理中…');
        lisunBatchContext = { total: 1, completed: 0 };
        startFriendlyProgress();
        $('#lisun-batch-bar').css('background', '#2271b1');
        $('#lisun-batch-stage').text('当前阶段：等待开始');
        $('#lisun-batch-current').text(title);
        $('#lisun-batch-cancel').hide();
        $('#lisun-batch-error-list').text('');

        restPost(full ? '/translate/retranslate' : '/translate', { post_id: postId }, { timeout: 30000 })
        .done(function (res) {
            if (res.success && (res.data.status === 'queued' || res.data.status === 'already_queued')) {
                $btn.text(label + '中...');
                $('#lisun-batch-current').text(label + '中：' + title);
                pollUntilDone(postId, $btn, $btn, title, function (ok) {
                    $('#lisun-batch-progress').hide();
                    if (ok) {
                        $('#lisun-batch-bar').css('width', '100%').css('background', '#00a32a');
                        $('#lisun-batch-counter').text('1 / 1');
                        setTimeout(function () { location.reload(); }, 1500);
                    } else {
                        showNotice(label + '失败', 'error');
                    }
                });
            } else {
                $('#lisun-batch-progress').hide();
                $btn.prop('disabled', false).text(label);
                showNotice(label + '失败：' + (res.data.message || ''), 'error');
            }
        })
        .fail(function () {
            $('#lisun-batch-progress').hide();
            $btn.prop('disabled', false).text(label);
            showNotice(label + '请求失败', 'error');
        });
    }

    // 全量重译（已翻译 / 推送失败区）
    $('.lisun-retranslate').on('click', function () {
        var $btn = $(this);
        queueTranslate($btn.data('id'), $btn, $btn.closest('tr').find('strong').first().text().trim(), true, '全量重译');
    });

    // 增量重译（已翻译 / 推送失败区）
    $('.lisun-retranslate-incr').on('click', function () {
        var $btn = $(this);
        queueTranslate($btn.data('id'), $btn, $btn.closest('tr').find('strong').first().text().trim(), false, '增量重译');
    });

    // 全量翻译（待翻译区，忽略已有译文从头翻译）
    $('.lisun-translate-full').on('click', function () {
        var $btn = $(this);
        queueTranslate($btn.data('id'), $btn, $btn.closest('tr').find('strong').first().text().trim(), true, '全量翻译');
    });

    // ==================== 删译文 ====================

    $('.lisun-delete-trans').on('click', function () {
        var $btn = $(this);
        var postId = $btn.data('id');
        var title = $btn.closest('tr').find('strong').first().text().trim();
        if (!confirm('仅删除「' + title + '」的英文译文，中文原文保留。确定？')) return;

        $.ajax({
            url: restBase + '/translate/' + postId,
            method: 'DELETE',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', restNonce);
            }
        }).done(function (res) {
            if (res.success) {
                showNotice('「' + title + '」的译文已删除');
                setTimeout(function () { location.reload(); }, 1000);
            } else {
                showNotice('删除失败：' + (res.data.message || ''), 'error');
            }
        });
    });
});
</script>
