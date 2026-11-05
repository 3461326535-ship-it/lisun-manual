<?php
/**
 * 术语库管理页面 — 含分页
 */

if (!defined('ABSPATH')) {
    exit;
}

$glossary = Lisun_Manual_Glossary::get_all();

// 防御：确保是数组
if (!is_array($glossary)) {
    $decoded = json_decode($glossary, true);
    $glossary = is_array($decoded) ? $decoded : [];
    update_option('lisun_glossary', $glossary);
}

// 默认按添加时间倒序：新加的排第一页，方便直接调试；同一天后加的排前面。
$_indexed = [];
foreach ($glossary as $_idx => $_row) {
    $_indexed[] = ['idx' => $_idx, 'item' => $_row];
}
usort($_indexed, function ($a, $b) {
    $da = (string) ($a['item']['import_date'] ?? '');
    $db = (string) ($b['item']['import_date'] ?? '');
    if ($da === $db) return $b['idx'] <=> $a['idx'];
    return strcmp($db, $da);
});
$glossary = array_map(function ($row) { return $row['item']; }, $_indexed);
unset($_indexed, $_row, $_idx);

$per_page     = 50;
$total        = count($glossary);
$total_pages  = max(1, (int) ceil($total / $per_page));
$current_page = 1;
$items        = array_slice($glossary, 0, $per_page);

// 当前页数据的全局索引起点（行号用）
$start_index = 0;
$deepl_state = Lisun_Manual_Glossary::get_deepl_status();
$deepl_enabled = Lisun_Manual_Glossary::is_enabled();
$deepl_last_error = $deepl_enabled ? (string) ($deepl_state['last_error'] ?? '') : '';
$deepl_synced_at = (int) ($deepl_state['synced_at'] ?? 0);
$deepl_status_labels = [
    'synced' => '已同步',
    'pending' => '待同步',
    'error' => '同步失败',
    'empty' => '本地术语表为空',
    'disabled' => '已关闭',
];
$deepl_status = $deepl_status_labels[$deepl_state['status'] ?? 'pending'] ?? '待同步';
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('术语库', 'lisun-manual'); ?></h1>
    <hr class="wp-header-end">

    <!-- 操作栏 -->
    <div class="lisun-table-actions">
        <div class="lisun-search">
            <input type="text" id="lisun-glossary-search" class="regular-text" placeholder="<?php esc_attr_e('搜索术语...', 'lisun-manual'); ?>">
            <button type="button" class="button" id="lisun-search-btn"><?php _e('搜索', 'lisun-manual'); ?></button>
            <button type="button" class="button" id="lisun-search-clear" style="display:none;"><?php _e('清除', 'lisun-manual'); ?></button>
        </div>
        <div class="lisun-actions-right">
            <button type="button" class="button button-primary" id="lisun-add-glossary-btn">
                <?php _e('添加术语', 'lisun-manual'); ?>
            </button>
            <label class="button" for="lisun-glossary-csv-input">
                <?php _e('导入 CSV', 'lisun-manual'); ?>
            </label>
            <input type="file" id="lisun-glossary-csv-input" accept=".csv" style="display:none;">
            <button type="button" class="button" id="lisun-export-glossary">
                <?php _e('导出 CSV', 'lisun-manual'); ?>
            </button>
            <button type="button" class="button" id="lisun-push-glossary-en"
                    title="<?php esc_attr_e('将当前术语库推送到远程站', 'lisun-manual'); ?>">
                <?php _e('推送到远程站', 'lisun-manual'); ?>
            </button>
            <button type="button" class="button" id="lisun-pull-glossary-en"
                    title="<?php esc_attr_e('从远程站拉取术语库替换当前术语库', 'lisun-manual'); ?>">
                <?php _e('从远程站拉取', 'lisun-manual'); ?>
            </button>
            <button type="button" class="button button-primary" id="lisun-sync-sheets"
                    style="background:#0f9d58;border-color:#0f9d58;color:#fff;"
                    title="<?php esc_attr_e('从 Google Sheets 同步术语库', 'lisun-manual'); ?>">
                <?php _e('从 Google Sheets 同步', 'lisun-manual'); ?>
            </button>
        </div>
    </div>

    <div id="lisun-deepl-glossary-status" style="margin:10px 0 15px;padding:10px 12px;background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo $deepl_last_error !== '' ? '#d63638' : ($deepl_enabled ? '#2271b1' : '#888'); ?>;">
        <strong>DeepL Glossary：<?php echo esc_html($deepl_status); ?></strong>
        <?php if (!$deepl_enabled): ?>
            <span style="margin-left:12px;color:#666;">翻译时不会使用任何术语表</span>
        <?php endif; ?>
        <?php if (!empty($deepl_state['glossary_id'])): ?>
            <span style="margin-left:12px;color:#666;">术语 <?php echo (int) ($deepl_state['entry_count'] ?? 0); ?> 条，ID 尾号 <?php echo esc_html(substr((string) $deepl_state['glossary_id'], -8)); ?></span>
        <?php endif; ?>
        <?php if ($deepl_synced_at): ?>
            <span style="margin-left:12px;color:#666;">最近同步：<?php echo esc_html(wp_date('Y-m-d H:i:s', $deepl_synced_at)); ?></span>
        <?php endif; ?>
        <?php if ($deepl_last_error !== ''): ?>
            <div style="margin-top:5px;color:#d63638;">最近错误：<?php echo esc_html($deepl_last_error); ?></div>
        <?php endif; ?>
        <button type="button" class="button" id="lisun-check-deepl" style="margin-top:8px;" <?php echo !$deepl_enabled ? 'disabled title="请先在系统设置中启用术语表"' : ''; ?>><?php echo $deepl_enabled ? 'DeepL 连接检测 / 修复' : '术语表已关闭'; ?></button>
    </div>

    <!-- 添加/编辑表单（默认隐藏） -->
    <div id="lisun-glossary-form" style="display:none; background:#fff; border:1px solid #ddd; padding:15px; margin-bottom:15px;">
        <h3 id="lisun-form-title"><?php _e('添加术语', 'lisun-manual'); ?></h3>
        <input type="hidden" id="lisun-glossary-index" value="-1">
        <input type="hidden" id="lisun-glossary-term-id" value="">
        <table class="form-table">
            <tr>
                <th><label for="lisun-glossary-zh"><?php _e('中文术语', 'lisun-manual'); ?></label></th>
                <td><input type="text" id="lisun-glossary-zh" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-en"><?php _e('英文译法', 'lisun-manual'); ?></label></th>
                <td><input type="text" id="lisun-glossary-en" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-category"><?php _e('术语类别', 'lisun-manual'); ?></label></th>
                <td>
                    <select id="lisun-glossary-category" class="regular-text">
                        <option value="manual">手动添加专业名词</option>
                        <option value="erp">ERP产品名称</option>
                        <option value="custom">自定义</option>
                    </select>
                    <p class="description">类别决定默认适用范围：ERP 默认全局，其余默认指定文章。</p>
                </td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-scope"><?php _e('适用范围', 'lisun-manual'); ?></label></th>
                <td>
                    <select id="lisun-glossary-scope" class="regular-text">
                        <option value="global">全局使用</option>
                        <option value="article">指定文章</option>
                    </select>
                    <p class="description">单条术语可覆盖类别默认值；指定文章的术语需在详情页勾选到具体文章。</p>
                </td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-note"><?php _e('备注', 'lisun-manual'); ?></label></th>
                <td><input type="text" id="lisun-glossary-note" class="regular-text" placeholder="自定义备注（可选）"></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="lisun-save-glossary-btn"><?php _e('保存', 'lisun-manual'); ?></button>
            <button type="button" class="button" id="lisun-cancel-glossary-btn"><?php _e('取消', 'lisun-manual'); ?></button>
        </p>
    </div>

    <!-- CSV 预览 -->
    <div class="lisun-csv-preview"></div>

    <!-- 术语列表 -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="4%">#</th>
                <th width="20%"><?php _e('中文术语', 'lisun-manual'); ?></th>
                <th width="20%"><?php _e('英文译法', 'lisun-manual'); ?></th>
                <th width="16%"><?php _e('类别 · 范围', 'lisun-manual'); ?></th>
                <th width="10%"><?php _e('导入日期', 'lisun-manual'); ?></th>
                <th width="14%"><?php _e('最近应用', 'lisun-manual'); ?></th>
                <th width="16%"><?php _e('操作', 'lisun-manual'); ?></th>
            </tr>
        </thead>
        <tbody id="lisun-glossary-tbody">
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $i => $item): ?>
                <tr class="lisun-glossary-term-row zebra-<?php echo ($i % 2 === 0) ? 'odd' : 'even'; ?>"
                    data-index="<?php echo $start_index + $i; ?>"
                    data-term-id="<?php echo esc_attr($item['term_id'] ?? ''); ?>"
                    data-zh="<?php echo esc_attr($item['zh']); ?>"
                    data-en="<?php echo esc_attr($item['en']); ?>"
                    data-note="<?php echo esc_attr($item['note'] ?? ''); ?>"
                    data-category="<?php echo esc_attr($item['category'] ?? ''); ?>"
                    data-scope="<?php echo esc_attr($item['scope'] ?? ''); ?>"
                    data-import-date="<?php echo esc_attr($item['import_date'] ?? (date('Y') . '/09/21')); ?>">
                    <td class="lisun-row-num"><?php echo $start_index + $i + 1; ?></td>
                    <td class="lisun-cell-zh"><?php echo esc_html($item['zh']); ?></td>
                    <td class="lisun-cell-en"><?php echo esc_html($item['en']); ?></td>
                    <td class="lisun-cell-category">
                        <?php
                        $cat_label = Lisun_Manual_Glossary::category_label($item['category'] ?? '');
                        $scope_label = Lisun_Manual_Glossary::scope_label($item['scope'] ?? '');
                        $note_extra = !empty($item['note']) ? '（' . esc_html($item['note']) . '）' : '';
                        echo esc_html($cat_label) . ' · ' . esc_html($scope_label) . $note_extra;
                        ?>
                    </td>
                    <td class="lisun-cell-import-date"><?php echo esc_html($item['import_date'] ?? (date('Y') . '/09/21')); ?></td>
                    <td class="lisun-cell-apply-status" style="font-size:12px;">
                        <?php $latest_apply = Lisun_Manual_Glossary_Apply::latest_for_term($item['term_id'] ?? ''); ?>
                        <?php if ($latest_apply): ?>
                            <?php
                            $latest_status_labels = ['queued' => '排队中', 'processing' => '处理中', 'done' => '已完成', 'finished_with_errors' => '部分失败', 'failed' => '失败', 'stale' => '待重新应用'];
                            $latest_status = $latest_status_labels[$latest_apply['status'] ?? ''] ?? (string) ($latest_apply['status'] ?? '—');
                            $effective_count = (int) ($latest_apply['total_jobs'] ?? 0);
                            if ($effective_count === 0) {
                                $_det = json_decode((string) ($latest_apply['details_json'] ?? ''), true);
                                if (is_array($_det) && is_array($_det['candidates'] ?? null)) $effective_count = count($_det['candidates']);
                            }
                            // The apply class supplies the request-level,
                            // deduplicated current count. Do not run a full
                            // history audit once per glossary row here.
                            if (array_key_exists('current_applied_count', $latest_apply)) {
                                $effective_count = (int) $latest_apply['current_applied_count'];
                            }
                            if ($effective_count === 0 && in_array((string) ($latest_apply['status'] ?? ''), ['done', 'finished_with_errors'], true)) {
                                $latest_status = '待重新应用';
                            }
                            ?>
                            <div class="lisun-recent-apply-summary">
                                <span class="lisun-recent-apply-time"><?php echo esc_html((string) $latest_apply['created_at']); ?></span>
                            </div>
                            <div class="lisun-recent-apply-status"><?php echo esc_html($latest_status); ?> · <?php echo $effective_count; ?> 篇</div>
                            <button type="button" class="lisun-recent-apply-toggle" data-term-id="<?php echo esc_attr($item['term_id'] ?? ''); ?>">展开⌄</button>
                        <?php else: ?>
                            <span style="color:#999;">未应用</span>
                        <?php endif; ?>
                    </td>
                    <td class="lisun-row-actions">
                        <span class="lisun-view-btns">
                            <button type="button" class="button button-small lisun-edit-glossary"><?php _e('编辑', 'lisun-manual'); ?></button>
                            <button type="button" class="button button-small lisun-glossary-impact"><?php _e('查看影响', 'lisun-manual'); ?></button>
                            <button type="button" class="button button-small lisun-delete-glossary" style="color:#d63638;"><?php _e('删除', 'lisun-manual'); ?></button>
                        </span>
                        <span class="lisun-edit-btns" style="display:none;">
                            <button type="button" class="button button-small button-primary lisun-save-glossary"><?php _e('保存', 'lisun-manual'); ?></button>
                            <button type="button" class="button button-small lisun-cancel-edit"><?php _e('取消', 'lisun-manual'); ?></button>
                        </span>
                    </td>
                </tr>
                <?php if ($latest_apply): ?>
                <tr class="lisun-recent-apply-detail" data-term-id="<?php echo esc_attr($item['term_id'] ?? ''); ?>" style="display:none;">
                    <td colspan="7"><div class="lisun-recent-apply-box"></div></td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:#999;"><?php _e('术语库为空', 'lisun-manual'); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 分页 -->
    <div class="lisun-pagination-bar" style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;">
        <div class="lisun-pagination-info">
            <?php _e('共', 'lisun-manual'); ?> <strong id="lisun-glossary-count"><?php echo $total; ?></strong> <?php _e('条术语', 'lisun-manual'); ?>
            <span id="lisun-page-info" style="margin-left:12px;color:#666;"></span>
        </div>
        <div class="lisun-pagination-pages" id="lisun-pagination-pages">
            <?php if ($total_pages > 1): ?>
            <button type="button" class="button button-small lisun-page-btn" data-page="1" disabled>&laquo;</button>
            <button type="button" class="button button-small lisun-page-btn" data-page="1" disabled>&lsaquo;</button>
            <?php
            $max_buttons = 7;
            if ($total_pages <= $max_buttons) {
                for ($p = 1; $p <= $total_pages; $p++) {
                    $active = $p === 1 ? ' button-primary' : '';
                    echo '<button type="button" class="button button-small lisun-page-num' . $active . '" data-page="' . $p . '">' . $p . '</button>';
                }
            } else {
                // 始终显示：1 ... 中间 ... N
                for ($p = 1; $p <= $total_pages; $p++) {
                    if ($p === 1 || $p === $total_pages || ($p >= $current_page - 1 && $p <= $current_page + 1)) {
                        $active = $p === 1 ? ' button-primary' : '';
                        echo '<button type="button" class="button button-small lisun-page-num' . $active . '" data-page="' . $p . '">' . $p . '</button>';
                    } elseif ($p === 2 || $p === $total_pages - 1) {
                        echo '<span class="lisun-page-ellipsis" style="padding:0 4px;color:#999;">&hellip;</span>';
                    }
                }
            }
            ?>
            <button type="button" class="button button-small lisun-page-btn" data-page="2">&rsaquo;</button>
            <button type="button" class="button button-small lisun-page-btn" data-page="<?php echo $total_pages; ?>">&raquo;</button>
            <?php endif; ?>
        </div>
    </div>

    <style>
        #lisun-glossary-impact-modal {
            display:none; position:fixed; z-index:100001; inset:0; overflow:auto;
            padding:32px 16px; background:rgba(15,23,42,.48);
        }
        .lisun-impact-dialog {
            width:min(820px, calc(100vw - 32px)); margin:0 auto; overflow:hidden;
            color:#202124; background:#f8fafc; border:1px solid #d9dee5; border-radius:16px;
            box-shadow:0 18px 55px rgba(15,23,42,.28);
        }
        .lisun-impact-head {
            display:flex; align-items:center; justify-content:space-between; gap:16px;
            padding:18px 24px 13px; background:#fff;
        }
        .lisun-impact-title { display:flex; align-items:center; gap:9px; margin:0; font-size:16px; font-weight:600; }
        .lisun-impact-title-icon { color:#202124; font-size:17px; }
        .lisun-impact-term { padding:5px 12px; color:#5f6368; background:#f1f3f4; border-radius:8px; font-size:13px; white-space:nowrap; }
        .lisun-impact-close-x { border:0; padding:0 2px; color:#5f6368; background:transparent; font-size:22px; line-height:1; cursor:pointer; }
        .lisun-impact-close-x:disabled { color:#b7bcc3; cursor:not-allowed; opacity:.55; }
        .lisun-impact-summary { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:8px; padding:0 24px 11px; background:#fff; }
        .lisun-impact-stat { min-height:79px; padding:11px 12px 9px; background:#fff; border:1px solid #d8dde3; border-radius:11px; }
        .lisun-impact-stat.is-accent { background:#fff8ed; border-color:#ff8a00; }
        .lisun-impact-stat-label { color:#777; font-size:12px; }
        .lisun-impact-stat-number { margin-top:3px; font-size:19px; font-weight:600; line-height:1.15; }
        .lisun-impact-stat-number small { margin-left:2px; color:#777; font-size:11px; font-weight:400; }
        .lisun-impact-stat-note { margin-top:3px; color:#a0a5aa; font-size:11px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .lisun-impact-state { grid-column:1 / -1; padding:9px 12px; border-radius:9px; color:#4b5560; background:#f1f3f4; border:1px solid #d8dde3; font-size:12px; line-height:1.55; }
        .lisun-impact-state.is-applied { color:#24533a; background:#e9f7ee; border-color:#b9dec5; }
        .lisun-impact-state.is-changed { color:#754b12; background:#fff4df; border-color:#f2cf91; }
        .lisun-impact-state-title { font-weight:600; }
        .lisun-impact-state-meta { margin-left:8px; color:#727980; font-weight:400; }
        .lisun-impact-body { padding:0 24px 18px; }
        .lisun-impact-article { margin-top:9px; background:#fff; border:1px solid #d8dde3; border-radius:11px; overflow:hidden; }
        .lisun-impact-article > summary { display:flex; align-items:center; gap:8px; padding:12px 13px; cursor:pointer; list-style:none; }
        .lisun-impact-article > summary::-webkit-details-marker { display:none; }
        .lisun-impact-article > summary:before { content:'⌄'; width:12px; color:#777; font-size:13px; text-align:center; }
        .lisun-impact-article[open] > summary:before { content:'⌃'; }
        .lisun-impact-article-title { min-width:0; flex:1; font-size:13px; }
        .lisun-impact-article-title strong { margin-right:7px; color:#8a9198; font-weight:400; }
        .lisun-impact-badges { display:flex; align-items:center; gap:5px; flex-shrink:0; }
        .lisun-impact-badge { padding:4px 8px; color:#555; background:#f4f5f6; border-radius:6px; font-size:11px; white-space:nowrap; }
        .lisun-impact-badge.is-warning { color:#754b12; background:#fff0d9; }
        .lisun-impact-badge.is-applied { color:#24533a; background:#e1f3e7; }
        .lisun-impact-single { flex-shrink:0; padding:5px 10px; border:0; border-radius:7px; color:#fff; background:#202124; font-size:11px; cursor:pointer; }
        .lisun-impact-single:disabled { opacity:.6; cursor:wait; }
        .lisun-impact-article-content { padding:0 14px 13px 31px; border-top:1px solid #e2e5e8; }
        .lisun-impact-check { flex-shrink:0; width:15px; height:15px; margin:0; cursor:pointer; accent-color:#2271b1; }
        .lisun-impact-article.is-selected { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1 inset; }
        .lisun-impact-selectbar { display:flex; align-items:center; gap:12px; margin:4px 0 8px; padding:8px 12px; background:#f0f6fc; border:1px solid #c5d9ed; border-radius:9px; font-size:12px; color:#3c434a; }
        .lisun-impact-selectbar label { display:flex; align-items:center; gap:6px; margin:0; cursor:pointer; font-weight:600; }
        .lisun-impact-selectbar input[type="checkbox"] { margin:0; accent-color:#2271b1; }
        .lisun-impact-selected-count { color:#2271b1; font-weight:600; }
        .lisun-impact-selectbar-hint { color:#787c82; }
        .lisun-impact-tabs { display:flex; gap:20px; border-bottom:1px solid #e5e7eb; }
        .lisun-impact-tab { padding:11px 0 9px; border:0; border-bottom:2px solid transparent; color:#777; background:transparent; font-size:12px; cursor:pointer; }
        .lisun-impact-tab.is-active { border-bottom-color:#202124; color:#202124; }
        .lisun-impact-panel { padding-top:10px; }
        .lisun-impact-context { margin:0 0 10px; padding:8px 10px 9px; border-left:2px solid #d5d9dd; background:#fbfcfd; }
        .lisun-impact-context-path { margin-bottom:5px; color:#9aa0a6; font:11px/1.3 ui-monospace,SFMono-Regular,Menlo,monospace; }
        .lisun-impact-context-text { color:#4b4f52; font-size:12px; line-height:1.75; }
        .lisun-impact-context-text mark { padding:1px 2px; background:#fff0c2; color:inherit; }
        .lisun-impact-empty { padding:10px 0; color:#999; font-size:12px; }
        .lisun-impact-unapplied { margin:10px 0 2px; padding:8px 10px; color:#754b12; background:#fff8ed; border:1px solid #f0d39d; border-radius:8px; font-size:12px; }
        .lisun-impact-unapplied summary { cursor:pointer; font-weight:600; }
        .lisun-impact-unapplied-list { margin:8px 0 0 18px; padding:0; }
        .lisun-impact-unapplied-item { margin:5px 0; line-height:1.55; }
        .lisun-impact-unapplied-reason { color:#9a6818; }
        .lisun-impact-comparison { margin:8px 0 2px; padding:8px 10px; color:#4b4f52; background:#fff; border:1px solid #e2d4b9; border-radius:6px; font-size:11px; line-height:1.6; }
        .lisun-impact-comparison-block { margin:0 0 7px; }
        .lisun-impact-comparison-block:last-child { margin-bottom:0; }
        .lisun-impact-comparison-label { display:block; margin-bottom:2px; color:#8a6a32; font-weight:600; }
        .lisun-impact-comparison-text { white-space:pre-wrap; word-break:break-word; }
        .lisun-impact-comparison-link { display:inline-block; margin-top:5px; color:#2271b1; text-decoration:none; }
        .lisun-impact-comparison-link:hover { text-decoration:underline; }
        .lisun-impact-skipped { margin-top:10px; padding:9px 12px; background:#fff; border:1px solid #e0e4e8; border-radius:10px; color:#666; font-size:12px; }
        .lisun-impact-skipped summary { cursor:pointer; }
        .lisun-impact-skipped ul { margin:9px 0 0 0; }
        .lisun-impact-skipped li { display:flex; align-items:center; gap:8px; margin:0; padding:5px 0; border-bottom:1px solid #f0f1f2; }
        .lisun-impact-skipped li:last-child { border-bottom:0; }
        .lisun-impact-skipped-text { min-width:0; flex:1; }
        .lisun-stale-retranslate-actions { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:9px; padding:9px 10px; color:#754b12; background:#fff7e8; border:1px solid #f0d39d; border-radius:8px; }
        .lisun-stale-retranslate-actions .button { flex-shrink:0; }
        .lisun-retranslate-stale-single { flex-shrink:0; }
        .lisun-impact-history-box { padding:10px 0 0; }
        .lisun-impact-history-item { margin:0 0 7px; padding:8px 10px; background:#fbfcfd; border:1px solid #e7e9eb; border-radius:7px; color:#62676c; font-size:11px; line-height:1.5; }
        .lisun-impact-global-history { margin:3px 24px 18px; padding-top:4px; }
        .lisun-impact-global-history h3 { margin:0 0 8px; font-size:13px; }
        .lisun-impact-global-history table { font-size:11px; }
        .lisun-impact-global-history .widefat td, .lisun-impact-global-history .widefat th { padding:6px 8px; }
        .lisun-impact-footer { display:flex; align-items:center; flex-wrap:wrap; gap:8px; padding:13px 24px 18px; background:#fff; border-top:1px solid #e1e4e7; }
        .lisun-impact-footer #lisun-glossary-batch-status { flex:1 1 100%; margin:0; color:#646970; font-size:12px; }
        .lisun-impact-footer #lisun-glossary-batch-errors { flex:1 1 100%; }
        .lisun-batch-errors { margin:0; padding:8px 10px; color:#7a2525; background:#fff5f5; border:1px solid #efb5b5; border-radius:7px; font-size:12px; line-height:1.55; }
        .lisun-batch-errors summary { cursor:pointer; font-weight:600; }
        .lisun-batch-errors ul { margin:6px 0 0 18px; padding:0; }
        .lisun-batch-errors li { margin:4px 0; }
        .lisun-batch-error-meta { color:#8a4b4b; }
        .lisun-impact-footer .button-primary { border-color:#202124; background:#202124; }
        .lisun-impact-footer .lisun-manual-unlock { color:#8a4b00; border-color:#d99a3d; background:#fff8ed; }
        .lisun-impact-footer .lisun-manual-unlock:hover { color:#6f3d00; border-color:#b97818; background:#fff0d2; }
        .lisun-recent-apply-summary { display:flex; align-items:center; gap:6px; line-height:1.35; }
        .lisun-recent-apply-time { color:#8a9198; font-size:11px; }
        .lisun-recent-apply-status { color:#30343b; font-size:12px; }
        .lisun-recent-apply-toggle { margin-top:4px; padding:1px 5px; border:0; border-radius:4px; color:#24533a; background:#e1f3e7; font-size:11px; line-height:18px; cursor:pointer; }
        .lisun-recent-apply-toggle:hover { background:#ccebd6; }
        .lisun-recent-apply-detail > td { padding:0 !important; background:#f7f7f7 !important; border-top:0 !important; }
        /* The detail row is a second <tr> and must not affect the main-row zebra pattern. */
        #lisun-glossary-tbody > tr.lisun-glossary-term-row.zebra-odd > td { background:#fff; }
        #lisun-glossary-tbody > tr.lisun-glossary-term-row.zebra-even > td { background:#f6f7f7; }
        .lisun-recent-apply-box { margin:0 12%; padding:12px 16px 13px; color:#30343b; font-size:12px; }
        .lisun-recent-apply-heading { margin-bottom:8px; color:#8a9198; }
        .lisun-recent-apply-item { display:flex; align-items:center; gap:8px; min-height:24px; }
        .lisun-recent-apply-item-id { width:55px; color:#8a9198; }
        .lisun-recent-apply-item-title { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .lisun-recent-apply-item-units { width:70px; color:#8a9198; text-align:right; }
        .lisun-recent-apply-item-state { min-width:42px; padding:2px 5px; border-radius:5px; color:#24533a; background:#e1f3e7; text-align:center; font-size:11px; }
        .lisun-recent-apply-item-state.is-failed { color:#9a2e2e; background:#fde5e5; }
        .lisun-recent-apply-empty { padding:8px 0; color:#999; }
        .lisun-recent-apply-record { border-bottom:1px solid #e6e8eb; }
        .lisun-recent-apply-record:last-child { border-bottom:0; }
        .lisun-recent-hit-toggle { margin-left:4px; padding:1px 5px; border:0; border-radius:4px; color:#4a5560; background:#edf0f2; font-size:11px; line-height:18px; cursor:pointer; }
        .lisun-recent-hit-toggle:hover { background:#e0e5e8; }
        .lisun-recent-hit-box { margin:0 0 7px 63px; padding:7px 9px; border-left:2px solid #d5d9dd; background:#fbfcfd; }
        .lisun-recent-hit-context { margin:0 0 7px; }
        .lisun-recent-hit-context:last-child { margin-bottom:0; }
        .lisun-recent-hit-path { margin-bottom:3px; color:#9aa0a6; font:10px/1.3 ui-monospace,SFMono-Regular,Menlo,monospace; }
        .lisun-recent-hit-text { color:#4b4f52; line-height:1.6; }
        .lisun-recent-hit-text mark { padding:1px 2px; background:#fff0c2; color:inherit; }
        @media (max-width:700px) {
            #lisun-glossary-impact-modal { padding:12px 8px; }
            .lisun-impact-dialog { width:calc(100vw - 16px); }
            .lisun-impact-head, .lisun-impact-body, .lisun-impact-footer { padding-left:14px; padding-right:14px; }
            .lisun-impact-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); padding-left:14px; padding-right:14px; }
            .lisun-impact-article > summary { flex-wrap:wrap; }
            .lisun-impact-article-title { flex-basis:calc(100% - 25px); }
            .lisun-impact-badges { margin-left:20px; }
            .lisun-impact-single { margin-left:auto; }
            .lisun-impact-global-history { margin-left:14px; margin-right:14px; }
            .lisun-impact-selectbar { flex-wrap:wrap; gap:6px 10px; }
            .lisun-impact-selectbar-hint { flex-basis:100%; }
        }
    </style>
    <div id="lisun-glossary-impact-modal">
        <div class="lisun-impact-dialog">
            <div class="lisun-impact-head">
                <h2 class="lisun-impact-title"><span class="lisun-impact-title-icon">⌘</span>术语影响范围</h2>
                <span id="lisun-glossary-term-pill" class="lisun-impact-term">—</span>
                <button type="button" class="lisun-impact-close-x" id="lisun-glossary-impact-close" aria-label="关闭">×</button>
            </div>
            <div id="lisun-glossary-summary" class="lisun-impact-summary"></div>
            <div id="lisun-glossary-history" class="lisun-impact-global-history"></div>
            <div id="lisun-glossary-impact-body" class="lisun-impact-body">正在扫描…</div>
            <div class="lisun-impact-footer">
                <p id="lisun-glossary-batch-status"></p>
                <div id="lisun-glossary-batch-errors"></div>
                <button type="button" class="button button-primary" id="lisun-glossary-apply-confirm" disabled>确认并全局更新现有译文</button>
                <button type="button" class="button lisun-manual-unlock" id="lisun-glossary-manual-unlock" style="display:none;">人工解锁并重新应用</button>
                <button type="button" class="button" id="lisun-glossary-apply-retry" style="display:none;">重试失败任务</button>
                <button type="button" class="button" id="lisun-glossary-impact-close-footer">关闭</button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function ($) {
    var PER_PAGE     = <?php echo $per_page; ?>;
    var currentPage  = 1;
    var totalPages   = <?php echo $total_pages; ?>;
    var currentKw    = '';
    var nonce        = '<?php echo wp_create_nonce("lisun_manual_nonce"); ?>';
    // Fallback channel: the article metabox queues through REST with the same
    // capability that an editor account already has, so it also works when the
    // admin-ajax enqueue is blocked (CDN/WAF) or rejected on that install.
    var restBase     = '<?php echo esc_js(rest_url('lisun-manual/v1')); ?>';
    var restNonce    = '<?php echo wp_create_nonce('wp_rest'); ?>';
    var CAT_LABELS   = {manual: '手动添加专业名词', erp: 'ERP产品名称', custom: '自定义'};
    var SCOPE_LABELS = {global: '全局使用', article: '指定文章'};

    // HTML 转义
    function escHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function glossaryIssueMessage(data) {
        if (data && data.message) return data.message;
        var issues = (data && data.issues) || [];
        return $.map(issues, function (issue) {
            return (issue.row ? '第 ' + issue.row + ' 行：' : '') + (issue.message || '术语存在关系冲突');
        }).join('\n');
    }
    function confirmGlossaryWarnings(data) {
        var text = glossaryIssueMessage(data);
        return data && data.requires_confirmation && window.confirm(text + '\n\n确认后继续保存？');
    }
    function setGlossaryMeta(category, scope, note) {
        category = CAT_LABELS[category] ? category : 'manual';
        scope = SCOPE_LABELS[scope] ? scope : (category === 'erp' ? 'global' : 'article');
        $('#lisun-glossary-category').val(category);
        $('#lisun-glossary-scope').val(scope);
        $('#lisun-glossary-note').val(String(note || ''));
    }
    function getGlossaryMeta() {
        return {
            category: $('#lisun-glossary-category').val(),
            scope: $('#lisun-glossary-scope').val(),
            note: $('#lisun-glossary-note').val().trim()
        };
    }
    // 类别变化时范围回落到类别默认值，用户可在其后手动覆盖单条范围。
    $('#lisun-glossary-category').on('change', function () {
        $('#lisun-glossary-scope').val(($(this).val() === 'erp') ? 'global' : 'article');
    });
    function latestApplyHtml(latest, termId) {
        if (!latest) return '<span style="color:#999;">未应用</span>';
        var labels = {queued:'排队中', processing:'处理中', done:'已完成', finished_with_errors:'部分失败', failed:'失败', stale:'待重新应用'};
        var status = labels[latest.status] || latest.status || '—';
        var count = parseInt(latest.total_jobs, 10) || 0;
        var activeCount = null;
        // The server has already merged all batches and undo markers. Prefer
        // this canonical count over the latest batch's raw applications,
        // which may be absent in legacy rows or may still contain undone
        // articles in old JSON.
        if (Object.prototype.hasOwnProperty.call(latest, 'current_applied_count')) {
            activeCount = parseInt(latest.current_applied_count, 10) || 0;
            count = activeCount;
            if (activeCount === 0 && (latest.status === 'done' || latest.status === 'finished_with_errors')) {
                status = '待重新应用';
            }
        }
        if (latest.details_json) {
            try {
                var det = typeof latest.details_json === 'string' ? JSON.parse(latest.details_json) : latest.details_json;
                if (!count && det && Array.isArray(det.candidates)) count = det.candidates.length;
                if (activeCount === null && det && Array.isArray(det.applications)) {
                    activeCount = $.grep(det.applications, function (application) {
                        return !application.undone;
                    }).length;
                }
            } catch (e) {}
        }
        if (activeCount !== null) {
            count = activeCount;
            if (activeCount === 0 && (latest.status === 'done' || latest.status === 'finished_with_errors')) {
                status = '待重新应用';
            }
        }
        return '<div class="lisun-recent-apply-summary"><span class="lisun-recent-apply-time">' + escHtml(latest.created_at || '') + '</span></div>' +
            '<div class="lisun-recent-apply-status">' + escHtml(status) + ' · ' + count + ' 篇</div>' +
            '<button type="button" class="lisun-recent-apply-toggle" data-term-id="' + escAttr(termId || '') + '">展开⌄</button>';
    }
    function recentApplyDetailRow(termId) {
        return '<tr class="lisun-recent-apply-detail" data-term-id="' + escAttr(termId || '') + '" style="display:none;">' +
            '<td colspan="7"><div class="lisun-recent-apply-box"></div></td></tr>';
    }
    function recentApplyHistoryHtml(history) {
        if (!history || !history.length) return '<div class="lisun-recent-apply-empty">暂无应用记录</div>';
        // 合并所有批次，按 post_id 去重，保留最新一次的记录（含撤销状态）。
        var byPost = {};
        var unitsByPost = {};
        $.each(history, function (_, batch) {
            var cands = batch.details && batch.details.candidates ? batch.details.candidates : [];
            $.each(cands, function (_, c) {
                unitsByPost[String(c.post_id)] = c.units || c.occurrences || 0;
            });
            var apps = batch.applications || [];
            $.each(apps, function (_, app) {
                var pid = String(app.post_id || '');
                if (!pid || pid === '0') return;
                app._batch_id = batch.batch_id;
                app._batch_time = batch.created_at || '';
                // 后遍历的批次更新（history 已按时间倒序，所以先到的是最新的，不覆盖）。
                if (!byPost[pid]) byPost[pid] = app;
            });
        });
        var displayItems = Object.values(byPost);
        var labels = {queued:'排队中', processing:'处理中', done:'已完成', finished_with_errors:'部分失败', failed:'失败'};
        var latest = history[0] || {};
        var activeDisplayItems = $.grep(displayItems, function (application) {
            return !application.undone;
        });
        var html = '<div class="lisun-recent-apply-heading">累计应用 · ' + escHtml(latest.created_at || '') + ' · 共 ' +
            activeDisplayItems.length + ' 篇文章当前已应用</div>';
        $.each(displayItems, function (_, application) {
            var localOnly = application.local_only || (application.status === 'done' && application.pushed === false);
            var warningOnly = !localOnly && (application.warning_only || application.term_count_warning || (application.post_apply_audit && (application.post_apply_audit.term_count_warning || parseInt(application.post_apply_audit.visible_missing_count || 0, 10) > 0)));
            var status = application.undone ? '已撤销' : (localOnly ? '失败（本地已更新，远程待重推）' : (labels[application.status] || application.status || '—'));
            if (warningOnly && status === '已完成') status += '（有警告）';
            var failed = localOnly || (!warningOnly && (application.status === 'failed' || application.status === 'finished_with_errors'));
            var units = unitsByPost[String(application.post_id)] || application.candidate_units || application.translated_units || application.updated || application.occurrences || 0;
            var audit = application.audit || {};
            var appliedCount = parseInt(audit.hit_count || 0, 10) || 0;
            var countLabel = !failed && appliedCount ? appliedCount + ' 处' : units + ' 个翻译单元';
            var contexts = application.contexts || [];
            var contextHtml = contexts.length ? $.map(contexts, function (hit) {
                return '<div class="lisun-recent-hit-context"><div class="lisun-recent-hit-path">' +
                    escHtml((hit.unit_type || 'text') + ' · ' + (hit.source_path || '')) + '</div><div class="lisun-recent-hit-text">' +
                    escContextText(hit.context || '') + '</div></div>';
            }).join('') : '<div class="lisun-recent-apply-empty">暂无可展示的命中上下文</div>';
            var undoBtn = '';
            if (application.undo_available && application.applied_revision_id) {
                undoBtn = '<button type="button" class="lisun-undo-apply" data-post-id="' + escAttr(application.post_id) + '" data-batch-id="' + escAttr(application._batch_id || '') + '" data-revision="' + escAttr(application.applied_revision_id) + '" data-has-newer="' + (application.undo_warning ? '1' : '0') + '" title="' + escAttr(application.undo_warning || '撤销并恢复到应用前版本') + '" style="margin-left:6px;padding:2px 8px;font-size:11px;color:#b32d2e;border:1px solid #f3b9bb;background:#fff5f5;border-radius:3px;cursor:pointer;">撤销此篇</button>';
            } else if (application.undone) {
                undoBtn = '<span style="margin-left:6px;font-size:11px;color:#888;">已撤销</span>';
            } else if (application.undo_reason && application.status === 'done') {
                undoBtn = '<span style="margin-left:6px;font-size:11px;color:#aaa;" title="' + escAttr(application.undo_reason) + '">' + escHtml(application.undo_reason) + '</span>';
            }
            html += '<div class="lisun-recent-apply-record"><div class="lisun-recent-apply-item">' +
                '<span class="lisun-recent-apply-item-id">#' + escHtml(application.post_id || '') + '</span>' +
                '<span class="lisun-recent-apply-item-title">' + escHtml(application.title || '') + '</span>' +
                '<span class="lisun-recent-apply-item-units">' + escHtml(countLabel) + '</span>' +
                '<span class="lisun-recent-apply-item-state' + (failed ? ' is-failed' : '') + '">' + escHtml(status) + '</span>' +
                undoBtn +
                '<button type="button" class="lisun-recent-hit-toggle">命中展开⌄</button>' +
                '</div><div class="lisun-recent-hit-box" style="display:none;">' + contextHtml + '</div></div>';
        });
        return html;
    }

    // ========== 加载分页数据 ==========
    function loadPage(page, keyword) {
        if (typeof keyword === 'undefined') keyword = currentKw;
        $.post(ajaxurl, {
            action: 'lisun_get_glossary_page',
            page: page,
            per_page: PER_PAGE,
            keyword: keyword,
            _nonce: nonce
        }, function (res) {
            if (!res.success) { alert('加载失败'); return; }
            var data       = res.data;
            var items      = data.items;
            totalPages     = data.total_pages;
            currentPage    = data.page;
            currentKw      = keyword;

            var tbody = $('#lisun-glossary-tbody');
            tbody.empty();

            if (items.length === 0) {
                tbody.append('<tr><td colspan="7" style="text-align:center;color:#999;"><?php echo esc_js(__('无匹配结果', 'lisun-manual')); ?></td></tr>');
            }

            var offset = (currentPage - 1) * PER_PAGE;
            $.each(items, function (i, item) {
                var rowIndex = offset + i;
                var zh   = item.zh || '';
                var en   = item.en || '';
                var note = item.note || '';
                var catLabel  = CAT_LABELS[item.category] || item.category || '';
                var scopeLabel = SCOPE_LABELS[item.scope] || item.scope || '';
                var noteExtra = note ? '（' + escHtml(note) + '）' : '';
                var importDate = item.import_date || (new Date().getFullYear() + '/09/21');
                tbody.append(
                    '<tr class="lisun-glossary-term-row zebra-' + (i % 2 === 0 ? 'odd' : 'even') + '" data-index="' + rowIndex + '" data-term-id="' + escAttr(item.term_id || '') + '" data-zh="' + escAttr(zh) + '" data-en="' + escAttr(en) + '" data-note="' + escAttr(note) + '" data-category="' + escAttr(item.category || '') + '" data-scope="' + escAttr(item.scope || '') + '" data-import-date="' + escAttr(importDate) + '">' +
                    '<td class="lisun-row-num">' + (rowIndex + 1) + '</td>' +
                    '<td class="lisun-cell-zh">' + escHtml(zh) + '</td>' +
                    '<td class="lisun-cell-en">' + escHtml(en) + '</td>' +
                    '<td class="lisun-cell-category">' + escHtml(catLabel) + ' · ' + escHtml(scopeLabel) + noteExtra + '</td>' +
                    '<td class="lisun-cell-import-date">' + escHtml(importDate) + '</td>' +
                    '<td class="lisun-cell-apply-status" style="font-size:12px;">' + latestApplyHtml(item.latest_apply, item.term_id || '') + '</td>' +
                    '<td class="lisun-row-actions">' +
                    '<span class="lisun-view-btns">' +
                    '<button class="button button-small lisun-edit-glossary"><?php echo esc_js(__('编辑', 'lisun-manual')); ?></button> ' +
                    '<button class="button button-small lisun-glossary-impact">查看影响</button> ' +
                    '<button class="button button-small lisun-delete-glossary" style="color:#d63638;"><?php echo esc_js(__('删除', 'lisun-manual')); ?></button>' +
                    '</span>' +
                    '<span class="lisun-edit-btns" style="display:none;">' +
                    '<button class="button button-small button-primary lisun-save-glossary"><?php echo esc_js(__('保存', 'lisun-manual')); ?></button> ' +
                    '<button class="button button-small lisun-cancel-edit"><?php echo esc_js(__('取消', 'lisun-manual')); ?></button>' +
                    '</span>' +
                    '</td>' +
                    '</tr>' +
                    (item.latest_apply ? recentApplyDetailRow(item.term_id || '') : '')
                );
            });

            $('#lisun-glossary-count').text(data.total);
            updatePageInfo();
            renderPageButtons();
        });
    }

    // 最近应用：默认收起，展开时读取最新一批文章明细
    $('#lisun-glossary-tbody').on('click', '.lisun-recent-apply-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var $detail = $row.next('.lisun-recent-apply-detail');
        if (!$detail.length) {
            $detail = $('.lisun-recent-apply-detail[data-term-id="' + String($btn.data('term-id')).replace(/"/g, '\\"') + '"]').first();
        }
        if ($detail.is(':visible')) {
            $detail.hide();
            $btn.text('展开⌄');
            return;
        }
        $detail.show();
        $btn.text('收起⌃');
        var $box = $detail.find('.lisun-recent-apply-box');
        if ($box.data('loaded')) return;
        $box.html('<div class="lisun-recent-apply-empty">正在读取本次应用明细…</div>');
        $.post(ajaxurl, {
            action: 'lisun_glossary_apply_history',
            term_id: String($btn.data('term-id') || ''),
            _nonce: nonce
        }, function (res) {
            if (res.success) {
                $box.html(recentApplyHistoryHtml(res.data.history || [])).data('loaded', true);
            } else {
                $box.html('<div class="lisun-recent-apply-empty">读取应用记录失败</div>');
            }
        }).fail(function () {
            $box.html('<div class="lisun-recent-apply-empty">读取应用记录请求失败</div>');
        });
    });

    $('#lisun-glossary-tbody').on('click', '.lisun-recent-hit-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var $box = $btn.closest('.lisun-recent-apply-record').find('.lisun-recent-hit-box');
        if ($box.is(':visible')) {
            $box.hide();
            $btn.text('命中展开⌄');
        } else {
            $box.show();
            $btn.text('命中收起⌃');
        }
    });

    // ========== 页面信息文字 ==========
    function updatePageInfo() {
        if (totalPages <= 1) {
            $('#lisun-page-info').text('');
        } else {
            $('#lisun-page-info').text('<?php echo esc_js(__('第', 'lisun-manual')); ?> ' + currentPage + ' / ' + totalPages + ' <?php echo esc_js(__('页', 'lisun-manual')); ?>');
        }
    }
    updatePageInfo();

    // ========== 渲染页码按钮 ==========
    function renderPageButtons() {
        var $container = $('#lisun-pagination-pages');
        if (totalPages <= 1) {
            $container.empty();
            return;
        }

        var html = '';
        // 首页 & 上一页
        html += '<button type="button" class="button button-small lisun-page-btn" data-page="1"' + (currentPage <= 1 ? ' disabled' : '') + '>&laquo;</button>';
        html += '<button type="button" class="button button-small lisun-page-btn" data-page="' + Math.max(1, currentPage - 1) + '"' + (currentPage <= 1 ? ' disabled' : '') + '>&lsaquo;</button>';

        var maxBtns = 7;
        if (totalPages <= maxBtns) {
            for (var p = 1; p <= totalPages; p++) {
                html += '<button type="button" class="button button-small lisun-page-num' + (p === currentPage ? ' button-primary' : '') + '" data-page="' + p + '">' + p + '</button>';
            }
        } else {
            for (var p = 1; p <= totalPages; p++) {
                if (p === 1 || p === totalPages || (p >= currentPage - 1 && p <= currentPage + 1)) {
                    html += '<button type="button" class="button button-small lisun-page-num' + (p === currentPage ? ' button-primary' : '') + '" data-page="' + p + '">' + p + '</button>';
                } else if (p === 2 || p === totalPages - 1) {
                    html += '<span class="lisun-page-ellipsis" style="padding:0 4px;color:#999;">&hellip;</span>';
                }
            }
        }

        // 下一页 & 末页
        html += '<button type="button" class="button button-small lisun-page-btn" data-page="' + Math.min(totalPages, currentPage + 1) + '"' + (currentPage >= totalPages ? ' disabled' : '') + '>&rsaquo;</button>';
        html += '<button type="button" class="button button-small lisun-page-btn" data-page="' + totalPages + '"' + (currentPage >= totalPages ? ' disabled' : '') + '>&raquo;</button>';

        $container.html(html);
    }

    // ========== 页码点击事件（委托） ==========
    $('#lisun-pagination-pages').on('click', '.lisun-page-btn, .lisun-page-num', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var page = parseInt($btn.data('page'));
        if (page && page !== currentPage) {
            loadPage(page);
        }
    });

    // ========== 添加表单 ==========
    $('#lisun-add-glossary-btn').on('click', function () {
        $('#lisun-glossary-index').val('-1');
        $('#lisun-glossary-term-id').val('');
        $('#lisun-glossary-zh').val('');
        $('#lisun-glossary-en').val('');
        setGlossaryMeta('manual', 'article', '');
        $('#lisun-form-title').text('<?php echo esc_js(__('添加术语', 'lisun-manual')); ?>');
        $('#lisun-glossary-form').slideDown();
    });

    $('#lisun-cancel-glossary-btn').on('click', function () {
        $('#lisun-glossary-form').slideUp();
    });

    // 保存新术语/编辑术语
    $('#lisun-save-glossary-btn').on('click', function () {
        var zh = $('#lisun-glossary-zh').val().trim();
        var en = $('#lisun-glossary-en').val().trim();
        var meta = getGlossaryMeta();
        var index = parseInt($('#lisun-glossary-index').val(), 10);
        var termId = String($('#lisun-glossary-term-id').val() || '');

        if (!zh || !en) { alert('<?php echo esc_js(__('中英文术语不能为空', 'lisun-manual')); ?>'); return; }

        var action = (termId !== '' || index >= 0) ? 'lisun_save_glossary' : 'lisun_add_glossary';
        var payload = {action: action, zh: zh, en: en, note: meta.note, category: meta.category, scope: meta.scope, _nonce: nonce};
        if (index >= 0) payload.index = index;
        if (termId !== '') payload.term_id = termId;
        function submit(confirmed) {
            var data = $.extend({}, payload);
            if (confirmed) {
                data.confirm_warnings = 1;
                if (action === 'lisun_add_glossary') data.overwrite_existing = 1;
            }
            $.post(ajaxurl, data, function (res) {
                if (res.success) {
                    if (res.data && res.data.message) alert(res.data.message);
                    location.reload();
                } else if (res.data && res.data.requires_confirmation) {
                    if (confirmGlossaryWarnings(res.data)) submit(true);
                    // 用户点击取消：保持表单内容，不保存，也不再弹第二个窗口。
                } else {
                    alert(glossaryIssueMessage(res.data) || '<?php echo esc_js(__('保存失败', 'lisun-manual')); ?>');
                }
            });
        }
        submit(false);
    });

    // CSV 导入：第一次只做解析和关系检查，确认后才真正提交，避免半批量写入。
    $('#lisun-glossary-csv-input').on('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        function submit(confirmed) {
            var formData = new FormData();
            formData.append('action', 'lisun_import_glossary_csv');
            formData.append('_nonce', nonce);
            formData.append('csv_file', file);
            if (confirmed) formData.append('confirm_warnings', '1');
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.success) {
                        alert('已导入 ' + (res.data.imported || 0) + ' 条术语。');
                        location.reload();
                    } else if (res.data && res.data.requires_confirmation) {
                        if (confirmGlossaryWarnings(res.data)) submit(true);
                        // 用户点击取消：不导入，不再弹第二个窗口。
                    } else {
                        alert(glossaryIssueMessage(res.data) || 'CSV 导入失败，未保存任何术语。');
                    }
                },
                error: function () { alert('CSV 导入请求失败，未保存任何术语。'); }
            });
        }
        submit(false);
    });

    // ========== 搜索 ==========
    function doSearch() {
        var kw = $('#lisun-glossary-search').val().trim();
        if (kw) {
            $('#lisun-search-clear').show();
        } else {
            $('#lisun-search-clear').hide();
        }
        loadPage(1, kw);
    }

    $('#lisun-search-btn').on('click', doSearch);
    $('#lisun-glossary-search').on('keydown', function (e) {
        if (e.key === 'Enter') doSearch();
    });

    $('#lisun-search-clear').on('click', function () {
        $('#lisun-glossary-search').val('');
        $(this).hide();
        loadPage(1, '');
    });

    // ========== 导出 ==========
    $('#lisun-export-glossary').on('click', function () {
        window.location.href = ajaxurl + '?action=lisun_export_glossary&_nonce=' + nonce;
    });

    // ========== 编辑 / 删除（委托事件，stopPropagation 防止外部 JS 冲突） ==========
    $('#lisun-glossary-tbody')

    // 编辑 — 打开顶部表单
    .on('click', '.lisun-edit-glossary', function (e) {
        e.stopPropagation();
        var $tr   = $(this).closest('tr');
        var index = parseInt($tr.data('index'));
        $('#lisun-glossary-index').val(index);
        $('#lisun-glossary-term-id').val(String($tr.data('term-id') || ''));
        $('#lisun-glossary-zh').val($tr.data('zh'));
        $('#lisun-glossary-en').val($tr.data('en'));
        setGlossaryMeta($tr.data('category'), $tr.data('scope'), $tr.data('note'));
        $('#lisun-form-title').text('<?php echo esc_js(__('编辑术语', 'lisun-manual')); ?>');
        $('#lisun-glossary-form').slideDown();
    })

    // 删除 — 分页感知，reload 当前页
    .on('click', '.lisun-delete-glossary', function (e) {
        e.stopPropagation();
        if (!confirm('<?php echo esc_js(__('确认删除该术语？', 'lisun-manual')); ?>')) return;
        var $tr = $(this).closest('tr');
        var index = parseInt($tr.data('index'));
        $.post(ajaxurl, {
            action: 'lisun_delete_glossary',
            index: index,
            _nonce: nonce
        }, function (res) {
            if (res.success) {
                loadPage(currentPage);
            } else {
                alert(res.data.message || '<?php echo esc_js(__('删除失败', 'lisun-manual')); ?>');
            }
        });
    });

    // ========== 术语中心：查看影响 → 确认后后台批量应用 ==========
    var activeImpact = null;
    var activeBatchId = '';
    var batchTimer = null;
    var staleTimer = null;
    var activeStaleJobIds = [];
    var activeApplyButton = null;
    var impactModalLocked = false;
    // 用户在影响列表里勾选的文章 ID（map: postId -> true）。
    // 勾选后底部按钮只入队这些文章；不勾选保持全局应用行为。
    var selectedPostIds = {};
    var currentPendingCount = 0;
    var currentAppStateStatus = '';

    function setImpactModalLocked(locked) {
        impactModalLocked = !!locked;
        $('#lisun-glossary-impact-close, #lisun-glossary-impact-close-footer')
            .prop('disabled', impactModalLocked)
            .attr('aria-disabled', impactModalLocked ? 'true' : 'false');
        $('#lisun-glossary-manual-unlock').prop('disabled', impactModalLocked);
        $('.lisun-retranslate-stale-single, .lisun-retranslate-stale-all').prop('disabled', impactModalLocked);
        $('.lisun-impact-check, #lisun-impact-selectall').prop('disabled', impactModalLocked);
        $('#lisun-glossary-impact-modal').toggleClass('lisun-impact-modal-locked', impactModalLocked);
    }

    function escList(items, key) {
        if (!items || !items.length) return '<li class="lisun-impact-empty">无</li>';
        return $.map(items.slice(0, 30), function (item) {
            var sourceChanged = item.reason === '中文源文已变化，需先重新翻译';
            var retryable = sourceChanged || /需重新翻译|需重译|无法定位对应翻译单元|没有稳定术语锚点/.test(item.reason || '');
            return '<li><span class="lisun-impact-skipped-text">#' + escHtml(item.post_id) + ' ' + escHtml(item.title || '') +
                (item.reason ? ' — <span style="color:#996800;">' + escHtml(item.reason) + '</span>' : '') +
                (item.units ? '（' + item.units + ' 个单元）' : '') +
                (item.occurrences ? '（' + item.occurrences + ' 处）' : '') +
                '</span>' +
                (retryable ? '<button type="button" class="button button-small lisun-retranslate-stale-single" data-post-id="' + escAttr(item.post_id) + '">' + (sourceChanged ? '增量翻译' : '重新翻译') + '</button>' : '') +
                '</li>';
        }).join('') + (items.length > 30 ? '<li>…其余 ' + (items.length - 30) + ' 篇未展开</li>' : '');
    }

    function sourceChangedSkipped(items) {
        return $.grep(items || [], function (item) {
            return item.reason === '中文源文已变化，需先重新翻译' || /需重新翻译|需重译|无法定位对应翻译单元|没有稳定术语锚点/.test(item.reason || '');
        });
    }

    function escContextText(text) {
        var safe = escHtml(text || '');
        return safe.replace(/【([^【】]+)】/g, '<mark>【$1】</mark>');
    }

    function escComparisonText(text) {
        return escHtml(text || '').replace(/\r?\n/g, '<br>');
    }

    function renderTermComparison(hit) {
        if (!hit || (!hit.source_text && !hit.english_text && !hit.remote_edit_url)) return '';
        var html = '<div class="lisun-impact-comparison">';
        if (hit.source_text) {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">中文原文</span>' +
                '<div class="lisun-impact-comparison-text">' + escComparisonText(hit.source_text) + '</div></div>';
        }
        if (hit.english_text) {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">当前英文译文</span>' +
                '<div class="lisun-impact-comparison-text">' + escComparisonText(hit.english_text) + '</div></div>';
        } else {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">当前英文译文</span>' +
                '<div class="lisun-impact-comparison-text">暂时无法读取该段英文</div></div>';
        }
        if (hit.target_term) {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">目标术语</span>' +
                '<div class="lisun-impact-comparison-text">' + escHtml(hit.target_term) +
                '（英文段落中出现 ' + escHtml(hit.actual_count || 0) + '/' + escHtml(hit.expected_count || 0) + ' 次）</div></div>';
        }
        if (hit.remote_edit_url) {
            html += '<a class="lisun-impact-comparison-link" href="' + escAttr(hit.remote_edit_url) + '" target="_blank" rel="noopener">打开远程英文文章后台</a>';
        }
        return html + '</div>';
    }

    function escContexts(item) {
        var contexts = item.contexts || [];
        if (!contexts.length) return '<div class="lisun-impact-empty">暂无可展示的命中上下文</div>';
        var html = '<div class="lisun-impact-panel lisun-impact-context-panel">';
        $.each(contexts.slice(0, 100), function (_, hit) {
            html += '<div class="lisun-impact-context">' +
                '<div class="lisun-impact-context-path">' + escHtml((hit.unit_type || 'text') + ' · ' + (hit.source_path || '')) + '</div>' +
                '<div class="lisun-impact-context-text">' + escContextText(hit.context || '') + '</div>' +
                '</div>';
        });
        if (contexts.length > 100) html += '<div class="lisun-impact-empty">其余 ' + (contexts.length - 100) + ' 处未展开</div>';
        return html + '</div>';
    }

    function escUnappliedMatches(item) {
        // A completed historical application may still carry the preview-time
        // audit, but that audit is not a current pending state. Only an
        // explicit post-apply audit may show remaining occurrences.
        var postAudit = item.post_apply_audit || {};
        var postAuditHasTermWarning = !!(postAudit.term_count_warning || parseInt(postAudit.visible_missing_count || 0, 10) > 0);
        if (item.status === 'applied' && !postAudit.unanchored_count && !postAuditHasTermWarning) return '';
        var audit = Object.keys(postAudit).length ? postAudit : (item.audit || {});
        var auditHitCount = parseInt(audit.hit_count || 0, 10) || 0;
        var auditVisibleCount = parseInt(audit.visible_applied_count || 0, 10) || 0;
        var auditMissingCount = parseInt(audit.visible_missing_count || audit.unapplied_count || 0, 10) || 0;
        var hardVisibleFailure = !!audit.term_count_failure
            || (auditHitCount > 0 && auditVisibleCount === 0 && auditMissingCount >= auditHitCount);
        var termCountWarning = !hardVisibleFailure && !!(audit.term_count_warning || auditMissingCount > 0);
        var rows = audit.unapplied || [];
        var unanchored = audit.unanchored || [];
        if (!rows.length && !unanchored.length) return '';
        var title = rows.length
            ? (hardVisibleFailure
                ? '应用失败：英文译文中未发现目标术语，需重新增量翻译'
                : (termCountWarning
                ? '术语数量提示：' + rows.length + ' 处需人工核对'
                : (item.status === 'incomplete' ? '英文译文仍缺 ' : (audit.resolved ? '已通过增量处理 ' : '待处理 ')) + rows.length + ' 处'))
            : '英文已有译文但有 ' + unanchored.length + ' 处未建立稳定锚点';
        var html = '<details class="lisun-impact-unapplied"' + (hardVisibleFailure || termCountWarning ? ' open' : '') + '><summary>' + title + '（查看原因）</summary>';
        if (rows.length) html += '<ul class="lisun-impact-unapplied-list">';
        $.each(rows.slice(0, 200), function (_, hit) {
            var reason = hardVisibleFailure
                ? '应用后英文译文中未发现目标术语，需重新增量翻译'
                : (hit.reason || '英文表达与术语出现次数不同，请人工核对');
            html += '<li class="lisun-impact-unapplied-item"><strong>' + escHtml(hit.unit_type || 'text') + ' · ' + escHtml(hit.source_path || hit.unit_key || '') + '</strong>' +
                '：' + escContextText(hit.context || hit.matched || '') +
                ' <span class="lisun-impact-unapplied-reason">— ' + escHtml(reason) + '</span>' +
                renderTermComparison(hit) + '</li>';
        });
        if (rows.length) {
            if (rows.length > 200) html += '<li class="lisun-impact-unapplied-item">其余 ' + (rows.length - 200) + ' 处已保存到批次记录</li>';
            html += '</ul>';
        }
        if (unanchored.length) html += '<div class="lisun-impact-anchor-note">其中 ' + unanchored.length + ' 处英文目标词仍可见，但没有稳定锚点；不影响本次可见译文统计，后续修改术语表时不能自动替换，需人工核对。</div>';
        return html + '</details>';
    }

    function escCandidates(items) {
        if (!items || !items.length) return '<div class="lisun-impact-empty">没有需要更新的文章</div>';
        var modeLabels = {direct:'直接替换', incremental:'增量翻译', legacy:'旧版安全替换', local_only:'本地已更新，远程待重推'};
        return $.map(items, function (item) {
            var applied = item.status === 'applied';
            var incomplete = item.status === 'incomplete' || item.incomplete;
            var localOnly = item.status === 'local_only' || item.local_only;
            var modeClass = applied ? ' is-applied' : (localOnly || incomplete || item.mode === 'incremental' ? ' is-warning' : '');
            var contexts = item.contexts || [];
            var postAudit = item.post_apply_audit || {};
            var audit = Object.keys(postAudit).length ? postAudit : (item.audit || {});
            var hitCount = parseInt(audit.hit_count || contexts.length, 10) || 0;
            var hasVisibleAudit = Object.prototype.hasOwnProperty.call(audit, 'visible_applied_count');
            var visibleAppliedRaw = Object.prototype.hasOwnProperty.call(item, 'visible_applied_occurrences')
                ? item.visible_applied_occurrences
                : audit.visible_applied_count;
            var stableAnchorRaw = Object.prototype.hasOwnProperty.call(item, 'stable_anchor_occurrences')
                ? item.stable_anchor_occurrences
                : audit.stable_anchor_count;
            var visibleAppliedCount = parseInt(visibleAppliedRaw, 10);
            var stableAnchorCount = parseInt(stableAnchorRaw, 10);
            if (isNaN(visibleAppliedCount)) visibleAppliedCount = 0;
            if (isNaN(stableAnchorCount)) stableAnchorCount = 0;
            var auditMissingCount = parseInt(audit.visible_missing_count || audit.unapplied_count || 0, 10) || 0;
            var hardVisibleFailure = !!audit.term_count_failure
                || (hitCount > 0 && visibleAppliedCount === 0 && auditMissingCount >= hitCount);
            if (hardVisibleFailure) {
                applied = false;
                incomplete = true;
            }
            modeClass = applied ? ' is-applied' : (localOnly || incomplete || item.mode === 'incremental' ? ' is-warning' : '');
            var appliedHitCount = incomplete ? visibleAppliedCount : (hasVisibleAudit ? visibleAppliedCount : (parseInt(audit.stable_anchor_count || 0, 10) || 0));
            var appliedRaw = Object.prototype.hasOwnProperty.call(item, 'applied_occurrences')
                ? item.applied_occurrences
                : appliedHitCount;
            var appliedOccurrenceCount = parseInt(appliedRaw, 10);
            if (isNaN(appliedOccurrenceCount)) appliedOccurrenceCount = 0;
            var directOccurrenceCount = parseInt(item.direct_occurrences || 0, 10) || 0;
            var incrementalUnitCount = parseInt(item.incremental_units || (item.mode === 'incremental' ? item.units : 0), 10) || 0;
            var mixedApplication = directOccurrenceCount > 0 && incrementalUnitCount > 0;
            var incrementalOccurrenceCount = parseInt(item.incremental_occurrences || 0, 10) || 0;
            if (!incrementalOccurrenceCount && mixedApplication && hitCount > directOccurrenceCount) {
                incrementalOccurrenceCount = hitCount - directOccurrenceCount;
            }
            // Once an article is applied, show the number of glossary
            // occurrences affected. For pending incremental work, show the
            // number of translation units that will be sent to DeepL.
            var countText = hasVisibleAudit && (applied || incomplete)
                ? ('可见译文 ' + visibleAppliedCount + '/' + hitCount + ' 处')
                : mixedApplication
                ? ('直接替换 ' + directOccurrenceCount + ' 处 + 增量翻译 ' + incrementalUnitCount + ' 个单元（覆盖 ' + incrementalOccurrenceCount + ' 处）')
                : incomplete && item.attempted_expected_occurrences
                ? ((item.attempted_applied_occurrences || 0) + '/' + item.attempted_expected_occurrences + ' 处')
                : applied
                ? (item.mode === 'incremental' && incrementalUnitCount
                    ? (incrementalUnitCount + ' 个单元')
                    : (appliedOccurrenceCount + ' 处'))
                : (item.mode === 'incremental'
                    ? ((item.units || 0) + ' 个翻译单元')
                    : (item.occurrences ? item.occurrences + ' 处' : ((item.units || 0) + ' 个翻译单元')));
            var unappliedCount = parseInt(audit.unapplied_count || 0, 10) || 0;
            var resolvedCount = parseInt(audit.resolved_count || 0, 10) || 0;
            var processCount = applied ? resolvedCount : unappliedCount;
            var processLabel = applied ? '已通过增量处理 ' : (incomplete ? '增量翻译后仍缺失 ' : '待增量处理 ');
            var termAnomalyCount = parseInt(item.term_anomaly_units || 0, 10) || 0;
            var termWarningCount = parseInt(audit.term_count_warning_count || audit.visible_missing_count || 0, 10) || 0;
            var selectable = !applied;
            var singleButtonText = hardVisibleFailure ? '重新增量翻译' : '单篇应用';
            return '<details class="lisun-impact-article' + (selectedPostIds[item.post_id] ? ' is-selected' : '') + '" data-post-id="' + escAttr(item.post_id) + '">' +
                '<summary>' +
                    (selectable ? '<input type="checkbox" class="lisun-impact-check" data-post-id="' + escAttr(item.post_id) + '" title="勾选后可批量应用到这篇" ' + (selectedPostIds[item.post_id] ? 'checked' : '') + '>' : '') +
                    '<span class="lisun-impact-article-title"><strong>#' + escHtml(item.post_id) + '</strong>' + escHtml(item.title || '') + '</span>' +
                    '<span class="lisun-impact-badges">' +
                        '<span class="lisun-impact-badge' + modeClass + '">' + escHtml(applied ? '已应用' : (hardVisibleFailure ? '应用失败' : (incomplete ? '增量翻译未完成' : (localOnly ? modeLabels.local_only : (modeLabels[item.mode] || item.mode || ''))))) + ' · ' + escHtml(countText) + '</span>' +
                        '<span class="lisun-impact-badge">命中 ' + hitCount + ' 处</span>' +
                        (hasVisibleAudit && stableAnchorCount < hitCount ? '<span class="lisun-impact-badge is-warning">稳定锚点 ' + stableAnchorCount + '/' + hitCount + ' 处</span>' : '') +
                        (hardVisibleFailure ? '<span class="lisun-impact-badge is-warning">应用失败：需重新增量翻译</span>' : (termWarningCount ? '<span class="lisun-impact-badge is-warning">术语数量提示：少 ' + termWarningCount + ' 处（仅供核对）</span>' : '')) +
                        (termAnomalyCount ? '<span class="lisun-impact-badge is-warning">术语表异常 ' + termAnomalyCount + ' 个段落</span>' : '') +
                        (!applied && !hasVisibleAudit && appliedHitCount && unappliedCount ? '<span class="lisun-impact-badge is-applied">已应用 ' + appliedHitCount + ' 处</span>' : '') +
                        (processCount ? '<span class="lisun-impact-badge is-warning">' + processLabel + processCount + ' 处</span>' : '') +
                    '</span>' +
                    (applied ? '' : '<button type="button" class="lisun-impact-single lisun-apply-single" data-post-id="' + escAttr(item.post_id) + '" data-retry-label="' + escAttr(singleButtonText) + '">' + escHtml(singleButtonText) + '</button>') +
                '</summary>' +
                '<div class="lisun-impact-article-content">' +
                    '<div class="lisun-impact-tabs">' +
                        '<button type="button" class="lisun-impact-tab is-active" data-panel="context">命中上下文 ' + contexts.length + '</button>' +
                        '<button type="button" class="lisun-impact-tab lisun-application-history" data-panel="history" data-post-id="' + escAttr(item.post_id) + '">应用记录</button>' +
                    '</div>' +
                    ((localOnly || incomplete || hardVisibleFailure) && item.last_error ? '<div class="lisun-impact-panel" style="border-left:3px solid #d63638;color:#8a2424;">' + escHtml(item.last_error) + '</div>' : '') +
                    (termAnomalyCount ? '<div class="lisun-impact-panel" style="border-left:3px solid #dba617;color:#754b12;">术语表检查发现 ' + termAnomalyCount + ' 个段落的英文目标术语数量与中文原文不一致，请打开英文译文人工核对。</div>' : '') +
                    escUnappliedMatches(item) +
                    '<div class="lisun-impact-panel lisun-impact-context-panel">' + escContexts(item).replace(/^<div class="lisun-impact-panel lisun-impact-context-panel">|<\/div>$/g, '') + '</div>' +
                    '<div class="lisun-application-history-box lisun-impact-panel" style="display:none;"></div>' +
                '</div>' +
            '</details>';
        }).join('');
    }

    function escPostHistory(history) {
        if (!history || !history.length) return '<span style="color:#999;">暂无该文章的应用记录</span>';
        var labels = {done:'已完成', failed:'失败', processing:'处理中', pending:'排队中', running:'处理中', finished_with_errors:'部分失败'};
        var html = '<div style="border-left:3px solid #dcdcde;padding-left:10px;"><strong>应用记录</strong><ul style="margin:4px 0 0 18px;">';
        $.each(history, function (_, item) {
            var status = labels[item.status] || labels[item.batch_status] || item.status || '—';
            var warningOnly = item.warning_only || (item.post_apply_audit && (item.post_apply_audit.term_count_warning || parseInt(item.post_apply_audit.visible_missing_count || 0, 10) > 0));
            if (warningOnly && status === '已完成') status += '（有警告）';
            var line = escHtml(item.time || item.updated_at || '') + ' · ' + escHtml(status) +
                ' · ' + (item.scope === 'single' ? '单篇' : '全局') +
                ' · 更新 ' + escHtml(item.updated || 0) +
                ' · 请求 ' + escHtml(item.requests || 0);
            var postAudit = item.post_apply_audit || {};
            // After an attempt, the committed-revision audit is authoritative;
            // it explains visible coverage separately from stable anchors.
            var audit = Object.keys(postAudit).length ? postAudit : (item.audit || {});
            if (audit.hit_count) line += ' · 命中 ' + escHtml(audit.hit_count) + ' 处';
            if (Object.prototype.hasOwnProperty.call(audit, 'visible_applied_count')) {
                line += ' · 可见译文 ' + escHtml(audit.visible_applied_count) + '/' + escHtml(audit.hit_count || 0) + ' 处';
                if (audit.stable_anchor_count < audit.hit_count) {
                    line += ' · 稳定锚点 ' + escHtml(audit.stable_anchor_count || 0) + '/' + escHtml(audit.hit_count || 0) + ' 处';
                }
            } else if (item.applied_occurrences || postAudit.stable_anchor_count) {
                line += ' · 已应用 ' + escHtml(item.applied_occurrences || postAudit.stable_anchor_count) + ' 处';
            }
            var termWarningCount = parseInt(audit.term_count_warning_count || audit.visible_missing_count || 0, 10) || 0;
            var termCountWarning = !!(audit.term_count_warning || termWarningCount > 0);
            if (termWarningCount) {
                line += ' · 术语数量提示：少 ' + escHtml(termWarningCount) + ' 处（仅供核对）';
            }
            var completedIncremental = item.status === 'done' && item.mode === 'incremental';
            if (!termCountWarning && audit.unapplied_count) line += ' · ' + (completedIncremental ? '已通过增量处理 ' : '待增量处理 ') + escHtml(audit.unapplied_count) + ' 处';
            if (audit.resolved_count) line += ' · 已通过增量处理 ' + escHtml(audit.resolved_count) + ' 处';
            if (item.error) line += ' · ' + escHtml(item.error);
            if (audit.unapplied && audit.unapplied.length) {
                line += '<details style="margin-top:5px;"><summary style="cursor:pointer;color:#754b12;">' + (termCountWarning ? '查看术语数量提示明细' : '查看增量处理明细') + '</summary><ul style="margin:5px 0 0 18px;">';
                $.each(audit.unapplied.slice(0, 200), function (_, hit) {
                    line += '<li>' + escHtml(hit.source_path || hit.unit_key || '') + '：' + escHtml(hit.reason || '未找到可应用锚点') + renderTermComparison(hit) + '</li>';
                });
                line += '</ul></details>';
            }
            html += '<li>' + line + '</li>';
        });
        return html + '</ul></div>';
    }

    function escHistory(history) {
        if (!history || !history.length) return '<p style="color:#999;">暂无应用记录</p>';
        var statusLabels = {queued:'排队中', processing:'处理中', done:'已完成', finished_with_errors:'部分失败', failed:'失败'};
        var html = '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>范围</th><th>文章/数量</th><th>状态</th><th>时间</th><th>批次</th></tr></thead><tbody>';
        $.each(history, function (_, item) {
            var scope = item.scope === 'single' ? '单篇' : '全局';
            if (item.details && item.details.manual_unlock) scope += '（人工解锁）';
            var target = item.scope === 'single'
                ? ('#' + escHtml(item.post_id) + ' ' + escHtml(item.title || ''))
                : (escHtml(item.total_jobs) + ' 篇');
            html += '<tr><td>' + scope + '</td><td>' + target + '</td><td>' + escHtml(statusLabels[item.status] || item.status || '—') +
                '</td><td>' + escHtml(item.created_at || '') + '</td><td><code>' + escHtml(item.batch_id || '') + '</code></td></tr>';
            if (item.applications && item.applications.length) {
                html += '<tr><td colspan="5"><details><summary>查看本批次明细（' + item.applications.length + ' 篇）</summary><ul style="margin:6px 0 0 18px;">';
                $.each(item.applications, function (_, application) {
                    var appStatus = (application.local_only || (application.status === 'done' && application.pushed === false))
                        ? '失败（本地已更新，远程待重推）'
                        : (application.status === 'done' ? '已完成' : '失败');
                    var appText = '#' + escHtml(application.post_id) + ' ' + escHtml(application.title || '') +
                        '：' + appStatus + '，更新 ' + escHtml(application.updated || 0) + '，复用 ' + escHtml(application.reused_units || 0) +
                        '，请求 ' + escHtml(application.requests || 0);
                    if (application.error) appText += ' — ' + escHtml(application.error);
                    html += '<li>' + appText + '</li>';
                });
                html += '</ul></details></td></tr>';
            }
        });
        return '<h3 style="margin-bottom:4px;">全局与单篇应用记录</h3>' + html + '</tbody></table>';
    }

    function escApplicationState(state) {
        state = state || {};
        var latest = state.latest || null;
        var status = state.status || 'pending';
        var title = status === 'applied'
            ? '已应用'
            : (status === 'partial'
                ? '已应用，发现新增命中文章'
                : (status === 'changed' ? '术语已变更，需要重新应用'
                    : (status === 'unlocked' ? '已人工解锁，请确认重新应用' : '待处理')));
        var className = status === 'applied' ? ' is-applied' : (status === 'changed' || status === 'unlocked' ? ' is-changed' : '');
        var meta = [];
        if (latest) {
            if (latest.created_at || latest.updated_at) meta.push('应用时间：' + (latest.updated_at || latest.created_at));
            if (latest.batch_id) meta.push('批次：' + latest.batch_id);
            meta.push('范围：' + (latest.scope === 'single' ? '单篇' : '全局'));
        }
        return '<div class="lisun-impact-state' + className + '">' +
            '<span class="lisun-impact-state-title">' + escHtml(title) + '</span>' +
            (meta.length ? '<span class="lisun-impact-state-meta">' + escHtml(meta.join(' · ')) + '</span>' : '') +
            '</div>';
    }

    function renderImpact(data) {
        var s = data.summary;
        var state = data.application_state || {};
        var staleItems = sourceChangedSkipped(data.skipped);
        var pendingCount = typeof state.pending_articles === 'number'
            ? state.pending_articles
            : $.grep(data.candidates || [], function (item) { return item.status !== 'applied'; }).length;
        $('#lisun-glossary-term-pill').text((data.term.zh || '') + ' → ' + (data.term.en || ''));
        var summaryHtml = escApplicationState(state);
        if (state.status === 'applied') {
            var latest = state.latest || {};
            summaryHtml +=
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">已应用文章</div><div class="lisun-impact-stat-number">' + escHtml(state.applied_articles || 0) + '<small>篇</small></div><div class="lisun-impact-stat-note">当前命中均无需重复应用</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">应用范围</div><div class="lisun-impact-stat-number">' + escHtml(latest.scope === 'single' ? '单篇' : '全局') + '</div><div class="lisun-impact-stat-note">上次成功应用范围</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">应用批次</div><div class="lisun-impact-stat-number" style="font-size:13px;word-break:break-all;">' + escHtml(latest.batch_id || '—') + '</div><div class="lisun-impact-stat-note">记录批次</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">应用时间</div><div class="lisun-impact-stat-number" style="font-size:13px;">' + escHtml(latest.updated_at || latest.created_at || '—') + '</div><div class="lisun-impact-stat-note">术语状态未变化</div></div>';
        } else {
            summaryHtml +=
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">可直接替换</div><div class="lisun-impact-stat-number">' + escHtml(s.direct_articles || 0) + '<small>篇 / ' + escHtml(s.direct_occurrences || 0) + '处</small></div><div class="lisun-impact-stat-note">不调用 DeepL</div></div>' +
                '<div class="lisun-impact-stat is-accent"><div class="lisun-impact-stat-label">需增量翻译</div><div class="lisun-impact-stat-number">' + escHtml(s.incremental_articles || 0) + '<small>篇 / ' + escHtml(s.incremental_units || 0) + '单元</small></div><div class="lisun-impact-stat-note">预计 ' + escHtml(s.estimated_characters || 0) + ' 字符</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">旧版可安全替换</div><div class="lisun-impact-stat-number">' + escHtml(s.legacy_articles || 0) + '<small>篇</small></div><div class="lisun-impact-stat-note">可直接复用旧版译文</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">跳过</div><div class="lisun-impact-stat-number">' + escHtml(s.skipped_articles || 0) + '<small>篇</small></div><div class="lisun-impact-stat-note">锁定/源文变化/无可靠锚点</div></div>';
            if (s.local_only_articles) {
                summaryHtml += '<div class="lisun-impact-stat is-warning"><div class="lisun-impact-stat-label">本地已更新</div><div class="lisun-impact-stat-number">' + escHtml(s.local_only_articles) + '<small>篇</small></div><div class="lisun-impact-stat-note">远程推送失败，不能算已应用</div></div>';
            }
        }
        $('#lisun-glossary-summary').html(summaryHtml);

        // 预览刷新后，已不在候选里的文章（已应用/被锁定）不再保留勾选。
        var candidateIds = {};
        $.each(data.candidates || [], function (_, item) {
            if (item.status !== 'applied') candidateIds[item.post_id] = true;
        });
        $.each(selectedPostIds, function (pid) {
            if (!candidateIds[pid]) delete selectedPostIds[pid];
        });
        currentPendingCount = pendingCount;
        currentAppStateStatus = state.status;

        var selectableCount = $.grep(data.candidates || [], function (item) { return item.status !== 'applied'; }).length;
        var selectbar = '<div class="lisun-impact-selectbar">' +
            '<label><input type="checkbox" id="lisun-impact-selectall"> 全选</label>' +
            '<span class="lisun-impact-selected-count">已选 0 篇</span>' +
            '<span class="lisun-impact-selectbar-hint">勾选后底部按钮只应用选中文章；不勾选则应用全部 ' + selectableCount + ' 篇待处理文章。</span>' +
        '</div>';

        $('#lisun-glossary-impact-body').html(
            (selectableCount ? selectbar : '') +
            '<div class="lisun-impact-articles">' + escCandidates(data.candidates) + '</div>' +
            '<details class="lisun-impact-skipped"><summary>跳过的文章（' + escHtml(s.skipped_articles || 0) + ' 篇）</summary>' +
                (staleItems.length ? '<div class="lisun-stale-retranslate-actions"><span>其中 ' + staleItems.length + ' 篇可以重新翻译并推送，已完成记录不会被清除。</span><button type="button" class="button button-primary lisun-retranslate-stale-all">批量重新翻译这 ' + staleItems.length + ' 篇</button></div>' : '') +
                '<ul>' + escList(data.skipped) + '</ul></details>' +
            '<p class="lisun-impact-empty">“确认应用”只处理当前可安全更新的文章；可恢复的跳过文章可单独或批量重新翻译，锁定文章仍不会被修改。</p>'
        );
        $('#lisun-glossary-history').html(escHistory(data.history || []));
        refreshApplyButtonLabel();
        syncSelectbarState();
        $('#lisun-glossary-manual-unlock')
            .toggle(state.status === 'applied')
            .prop('disabled', impactModalLocked || state.status !== 'applied');
    }

    function selectedCount() {
        return Object.keys(selectedPostIds).length;
    }

    function refreshApplyButtonLabel() {
        var $btn = $('#lisun-glossary-apply-confirm');
        var n = selectedCount();
        var text;
        if (!currentPendingCount) {
            text = currentAppStateStatus === 'applied' ? '已应用' : '没有可应用文章';
        } else if (n > 0) {
            text = '确认应用选中的 ' + n + ' 篇';
        } else {
            text = '确认并全局更新现有译文';
        }
        $btn.text(text).prop('disabled', impactModalLocked || !currentPendingCount);
    }

    function syncSelectbarState() {
        var $body = $('#lisun-glossary-impact-body');
        var $boxes = $body.find('.lisun-impact-check');
        var n = selectedCount();
        $body.find('.lisun-impact-selected-count').text('已选 ' + n + ' 篇');
        var $all = $('#lisun-impact-selectall');
        if ($all.length) {
            $all.prop('checked', $boxes.length > 0 && n === $boxes.length);
            $all.prop('indeterminate', n > 0 && n < $boxes.length);
        }
    }

    function refreshHistory() {
        if (!activeImpact || !activeImpact.term) return;
        $.post(ajaxurl, {action:'lisun_glossary_apply_history', term_id:activeImpact.term.term_id, _nonce:nonce}, function (res) {
            if (res.success) $('#lisun-glossary-history').html(escHistory(res.data.history || []));
        });
    }

    // The impact list is a snapshot from when the modal was opened. After a
    // glossary batch finishes, refresh that snapshot as well; otherwise the
    // history says "done" while the article cards still show the old
    // "incremental" classification.
    function refreshImpactPreview() {
        if (!activeImpact || !activeImpact.term || !activeImpact.term.term_id) return;
        var termId = String(activeImpact.term.term_id);
        $.post(ajaxurl, {
            action: 'lisun_glossary_impact_preview',
            term_id: termId,
            _nonce: nonce
        }, function (res) {
            if (!res.success) return;
            activeImpact = res.data;
            renderImpact(activeImpact);
        });
    }

    function pollStaleTranslations() {
        if (!activeStaleJobIds.length) return;
        $.post(ajaxurl, {
            action: 'lisun_glossary_retranslate_stale_status',
            job_ids: activeStaleJobIds,
            _nonce: nonce
        }, function (res) {
            if (!res.success) {
                $('#lisun-glossary-batch-status').text((res.data && res.data.message) || '增量翻译状态查询失败');
                return;
            }
            var c = res.data.counts || {};
            $('#lisun-glossary-batch-status').text(
                '增量翻译：完成 ' + (c.done || 0) + '，处理中 ' + (c.processing || 0) +
                '，等待 ' + (c.pending || 0) + '，失败 ' + (c.failed || 0)
            );
            if (res.data.finished) {
                activeStaleJobIds = [];
                $('.lisun-retranslate-stale-single, .lisun-retranslate-stale-all').prop('disabled', false);
                refreshImpactPreview();
                loadPage(currentPage);
            } else {
                staleTimer = setTimeout(pollStaleTranslations, 3000);
            }
        }).fail(function () {
            $('#lisun-glossary-batch-status').text('增量翻译状态查询失败，可关闭窗口；后台队列会继续处理');
        });
    }

    /**
     * POST an admin-ajax action as JSON.
     *
     * The enqueue button used to show a bare 「请求失败」 for every transport
     * problem, which hid whether the server rejected the call or the request
     * never arrived (stale keep-alive socket, CDN/WAF hiccup, expired nonce).
     * Enqueueing is idempotent server-side (enqueue_job reuses an existing
     * pending/processing row), so one automatic retry is safe.
     */
    function lisunDescribeFailure(xhr) {
        var status = xhr && xhr.status ? xhr.status : 0;
        var hint;
        if (status === 0) hint = '网络或 CDN 中断（已自动重试 1 次）';
        else if (status === 401 || status === 403) hint = '登录或安全令牌已失效，请刷新页面后重试';
        else if (status === 404) hint = '接口不存在，请确认插件文件已更新';
        else if (status >= 500) hint = '服务器错误（详见 lisun-debug.log），请稍后重试';
        else hint = 'HTTP ' + status;
        var body = String((xhr && xhr.responseText) || '').replace(/\s+/g, ' ').slice(0, 140);
        return hint + (body ? '\n\n' + body : '');
    }

    function lisunPostJson(data, attempt) {
        attempt = attempt || 0;
        return $.ajax({
            url: ajaxurl,
            method: 'POST',
            dataType: 'json',
            timeout: 25000,
            data: data
        }).then(null, function (xhr, textStatus) {
            var status = xhr && xhr.status ? xhr.status : 0;
            var transient = (status === 0 || status >= 500 || textStatus === 'timeout');
            if (transient && attempt < 1) {
                return lisunPostJson(data, attempt + 1);
            }
            return $.Deferred().reject(xhr, lisunDescribeFailure(xhr)).promise();
        });
    }

    /**
     * Queue the same incremental translation through the REST route the article
     * metabox uses (POST /lisun-manual/v1/translate, capability edit_posts).
     * Server-side this lands in exactly the same 'translate' job, so the term
     * workflow stays inside the glossary screen when admin-ajax is unavailable.
     */
    function lisunRestEnqueue(postIds) {
        var queue = postIds.slice(0);
        var queued = 0;
        var failed = [];

        function next() {
            if (!queue.length) {
                return $.Deferred().resolve(queued, failed).promise();
            }
            var postId = queue.shift();
            return $.ajax({
                url: restBase + '/translate',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ post_id: parseInt(postId, 10) }),
                timeout: 25000,
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', restNonce); }
            }).then(function (res) {
                if (res && res.success) queued++; else failed.push(postId);
                return next();
            }, function () {
                failed.push(postId);
                return next();
            });
        }

        return postIds.length ? next() : $.Deferred().resolve(0, []).promise();
    }

    function queueStaleTranslations(postIds, $button) {
        postIds = $.map(postIds || [], function (id) { return parseInt(id, 10) || null; });
        if (!activeImpact || !postIds.length) return;
        if (!confirm('确认将这 ' + postIds.length + ' 篇文章加入增量翻译队列？\n\n系统会复用未变化单元，完成后自动推送英文站。')) return;

        clearTimeout(staleTimer);
        $button.prop('disabled', true).text('入队中…');
        lisunPostJson({
            action: 'lisun_glossary_retranslate_stale',
            term_id: activeImpact.term.term_id,
            post_ids: postIds,
            _nonce: nonce
        }).done(function (res) {
            if (!res.success || !res.data.queued) {
                $button.prop('disabled', false).text(postIds.length === 1 ? '增量翻译' : '批量增量翻译');
                alert((res.data && res.data.message) || '增量翻译入队失败');
                return;
            }
            activeStaleJobIds = res.data.job_ids || [];
            $button.text('已入队');
            $('.lisun-retranslate-stale-single, .lisun-retranslate-stale-all').prop('disabled', true);
            $('#lisun-glossary-batch-status').text(res.data.message || '增量翻译任务已入队');
            pollStaleTranslations();
        }).fail(function (xhr, reason) {
            var why = reason || lisunDescribeFailure(xhr);
            // Admin-ajax path failed: fall back to the REST channel the article
            // metabox uses, so the term workflow does not require opening each
            // article by hand.
            $('#lisun-glossary-batch-status').text('admin-ajax 通道失败，正在改用 REST 通道入队…');
            lisunRestEnqueue(postIds).done(function (queued, failedIds) {
                if (queued > 0) {
                    activeStaleJobIds = [];
                    $button.text('已入队');
                    $('.lisun-retranslate-stale-single, .lisun-retranslate-stale-all').prop('disabled', true);
                    $('#lisun-glossary-batch-status').text(
                        '已通过 REST 通道入队 ' + queued + ' 篇'
                        + (failedIds.length ? '（失败 ' + failedIds.length + ' 篇）' : '')
                        + '；后台队列会继续处理。admin-ajax 失败原因：' + why
                    );
                    return;
                }
                $button.prop('disabled', false).text(postIds.length === 1 ? '增量翻译' : '批量增量翻译');
                alert('增量翻译入队失败：' + why
                    + '\n\n已尝试 REST 通道回退，同样未成功。');
            });
        });
    }

    function pollBatch() {
        if (!activeBatchId) return;
        // Ignore a late response from an older batch after the user has
        // opened another term and started polling its batch.
        var pollingBatchId = activeBatchId;
        $.post(ajaxurl, {action:'lisun_glossary_apply_status', batch_id:pollingBatchId, _nonce:nonce}, function (res) {
            if (!res.success) return;
            if (activeBatchId !== pollingBatchId) return;
            var d = res.data, c = d.counts;
            $('#lisun-glossary-batch-status').text('批次状态：' + d.status + '；成功 ' + c.done + (c.warnings ? '（有警告 ' + c.warnings + '）' : '') + '，处理中 ' + c.processing + '，等待 ' + c.pending + '，失败 ' + c.failed + '，跳过 ' + c.skipped + (c.local_only ? '，本地已更新待重推 ' + c.local_only : ''));
            renderBatchErrors(d.details || {});
            $('#lisun-glossary-apply-retry').toggle(c.failed > 0);
            if (d.status === 'running') {
                if (activeApplyButton) activeApplyButton.text('处理中…');
                batchTimer = setTimeout(pollBatch, 3000);
            } else {
                setImpactModalLocked(false);
                if (activeApplyButton) {
                    activeApplyButton.prop('disabled', false).text(d.status === 'done' ? '已完成' : '处理失败');
                }
                refreshHistory();
                loadPage(currentPage);
                refreshImpactPreview();
            }
        }).fail(function () {
            $('#lisun-glossary-batch-status').text('状态查询失败，请刷新影响范围查看应用记录');
        });
    }

    function renderBatchErrors(details) {
        var applications = details && details.applications ? details.applications : [];
        var failed = $.grep(applications, function (item) { return item.status === 'failed'; });
        if (!failed.length) {
            $('#lisun-glossary-batch-errors').empty();
            return;
        }
        var html = '<details class="lisun-batch-errors" open><summary>失败明细（' + failed.length + ' 篇）</summary><ul>';
        $.each(failed, function (_, item) {
            var line = '<strong>#' + escHtml(item.post_id || '') + ' ' + escHtml(item.title || '') + '</strong>：' +
                escHtml(item.error || '未返回具体错误');
            var meta = [];
            if (item.error_code) meta.push('错误码 ' + item.error_code);
            if (item.unit_key) meta.push('单元 ' + item.unit_key);
            if (item.batch_index !== undefined) meta.push('批次索引 ' + item.batch_index);
            if (item.trace_id) meta.push('追踪 ID ' + item.trace_id);
            if (meta.length) line += '<div class="lisun-batch-error-meta">' + escHtml(meta.join(' · ')) + '</div>';
            $.each(item.error_occurrences || [], function (_, occurrence) {
                line += '<div class="lisun-batch-error-meta">术语标签 ' + escHtml(occurrence.source_text || occurrence.occurrence_id || '') +
                    '：期望“' + escHtml(occurrence.expected || '') + '”，DeepL 返回“' + escHtml(occurrence.actual || '') + '”</div>';
            });
            html += '<li>' + line + '</li>';
        });
        html += '</ul></details>';
        $('#lisun-glossary-batch-errors').html(html);
    }

    $('#lisun-glossary-tbody').on('click', '.lisun-glossary-impact', function (e) {
        e.stopPropagation();
        var termId = String($(this).closest('tr').data('term-id') || '');
        if (!termId) { alert('该术语缺少稳定 ID，请重新保存后再试'); return; }
        activeImpact = null; activeBatchId = ''; activeApplyButton = null;
        selectedPostIds = {};
        currentPendingCount = 0; currentAppStateStatus = '';
        clearTimeout(batchTimer);
        clearTimeout(staleTimer);
        activeStaleJobIds = [];
        setImpactModalLocked(false);
        $('#lisun-glossary-term-pill').text('—');
        $('#lisun-glossary-summary').empty();
        $('#lisun-glossary-impact-body').text('正在扫描受影响文章…');
        $('#lisun-glossary-batch-status').text('');
        $('#lisun-glossary-batch-errors').empty();
        $('#lisun-glossary-apply-retry').hide();
        $('#lisun-glossary-apply-confirm').prop('disabled', true).text('确认并全局更新现有译文');
        $('#lisun-glossary-manual-unlock').hide().prop('disabled', false);
        $('#lisun-glossary-impact-modal').show();
        $.post(ajaxurl, {action:'lisun_glossary_impact_preview', term_id:termId, _nonce:nonce}, function(res) {
            if (!res.success) { $('#lisun-glossary-impact-body').text(res.data.message || '扫描失败'); return; }
            activeImpact = res.data;
            renderImpact(activeImpact);
        }).fail(function () { $('#lisun-glossary-impact-body').text('扫描请求失败'); });
    });

    // 阻止勾选时冒泡到 <summary>，避免展开/折叠详情。
    $('#lisun-glossary-impact-body').on('click', '.lisun-impact-check', function (e) {
        e.stopPropagation();
    });
    $('#lisun-glossary-impact-body').on('change', '.lisun-impact-check', function (e) {
        e.stopPropagation();
        var postId = String($(this).data('post-id') || '');
        if (!postId) return;
        var $article = $(this).closest('.lisun-impact-article');
        if (this.checked) {
            selectedPostIds[postId] = true;
            $article.addClass('is-selected');
        } else {
            delete selectedPostIds[postId];
            $article.removeClass('is-selected');
        }
        syncSelectbarState();
        refreshApplyButtonLabel();
    });
    // 全选 / 全不选
    $('#lisun-glossary-impact-body').on('change', '#lisun-impact-selectall', function (e) {
        var checked = this.checked;
        $('#lisun-glossary-impact-body .lisun-impact-check').each(function () {
            var postId = String($(this).data('post-id') || '');
            if (!postId) return;
            this.checked = checked;
            if (checked) {
                selectedPostIds[postId] = true;
                $(this).closest('.lisun-impact-article').addClass('is-selected');
            } else {
                delete selectedPostIds[postId];
                $(this).closest('.lisun-impact-article').removeClass('is-selected');
            }
        });
        syncSelectbarState();
        refreshApplyButtonLabel();
    });

    $('#lisun-glossary-impact-body').on('click', '.lisun-apply-single', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (!activeImpact) return;
        var $btn = $(this);
        activeApplyButton = $btn;
        var postId = parseInt($btn.data('post-id'), 10);
        var retryLabel = String($btn.data('retry-label') || '单篇应用');
        if (!postId) return;
        if (!confirm(retryLabel === '重新增量翻译'
            ? '确认重新增量翻译这 1 篇文章？\n\n本次会重新翻译受影响单元，并在完成后重新检查术语。'
            : '确认只应用到这 1 篇文章？')) return;
        $btn.prop('disabled', true).text('入队中…');
        setImpactModalLocked(true);
        lisunPostJson({
            action: 'lisun_glossary_apply_single',
            term_id: activeImpact.term.term_id,
            post_id: postId,
            term_revision: activeImpact.term_revision,
            _nonce: nonce
        }).done(function (res) {
            if (!res.success) {
                setImpactModalLocked(false);
                $btn.prop('disabled', false).text(retryLabel);
                alert(res.data.message || '单篇应用失败');
                return;
            }
            activeBatchId = res.data.batch_id;
            $btn.text('处理中…');
            $('#lisun-glossary-batch-status').text(res.data.message || '单篇应用任务已入队');
            refreshHistory();
            pollBatch();
        }).fail(function (xhr, reason) {
            setImpactModalLocked(false);
            $btn.prop('disabled', false).text(retryLabel);
            alert('单篇应用失败：' + (reason || lisunDescribeFailure(xhr)));
        });
    });

    $('#lisun-glossary-impact-body').on('click', '.lisun-retranslate-stale-single', function (e) {
        e.preventDefault();
        e.stopPropagation();
        queueStaleTranslations([$(this).data('post-id')], $(this));
    });

    $(document).on('click', '.lisun-undo-apply', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var postId = parseInt($btn.data('post-id'), 10);
        var batchId = String($btn.data('batch-id') || '');
        var revId = parseInt($btn.data('revision'), 10);
        var hasNewer = String($btn.data('has-newer') || '') === '1';
        if (!postId || !batchId || !revId) return;
        var confirmMessage = hasNewer
            ? '这篇文章应用术语后已经产生了新的译文。\n\n继续撤销会恢复到应用术语之前的版本，并覆盖应用后的译文和修改；如果确认这次术语应用不适用于本篇，请点击“确定”继续。\n\n是否继续？'
            : '撤销这一篇的术语应用？\n\n会把这篇英文译文恢复到应用术语之前的版本，并重新推送到远程站。';
        if (!confirm(confirmMessage)) return;
        $btn.prop('disabled', true).text('撤销中…');
        $.post(ajaxurl, {
            action: 'lisun_glossary_apply_undo',
            batch_id: batchId,
            post_id: postId,
            applied_revision_id: revId,
            _nonce: nonce
        }, function (res) {
            if (!res.success) {
                $btn.prop('disabled', false).text('撤销此篇');
                alert(res.data.message || '撤销失败');
                return;
            }
            $btn.replaceWith('<span style="margin-left:6px;font-size:11px;color:#888;">已撤销</span>');
            if (typeof refreshHistory === 'function') refreshHistory();
        }).fail(function () {
            $btn.prop('disabled', false).text('撤销此篇');
            alert('撤销请求失败');
        });
    });

    $('#lisun-glossary-impact-body').on('click', '.lisun-retranslate-stale-all', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var ids = $.map(sourceChangedSkipped((activeImpact && activeImpact.skipped) || []), function (item) {
            return item.post_id;
        });
        queueStaleTranslations(ids, $(this));
    });

    $('#lisun-glossary-impact-body').on('click', '.lisun-application-history', function () {
        if (!activeImpact || !activeImpact.term) return;
        var $btn = $(this);
        var $article = $btn.closest('.lisun-impact-article');
        var $box = $article.find('.lisun-application-history-box');
        if ($box.is(':visible')) {
            $article.find('.lisun-impact-tab').removeClass('is-active');
            $article.find('.lisun-impact-tab[data-panel="context"]').addClass('is-active');
            $article.find('.lisun-impact-context-panel').show();
            $box.hide();
            return;
        }
        $article.find('.lisun-impact-tab').removeClass('is-active');
        $btn.addClass('is-active');
        $article.find('.lisun-impact-context-panel').hide();
        $box.show().html('<span style="color:#646970;">正在读取应用记录…</span>');
        $.post(ajaxurl, {
            action: 'lisun_glossary_apply_post_history',
            term_id: activeImpact.term.term_id,
            post_id: parseInt($btn.data('post-id'), 10),
            _nonce: nonce
        }, function (res) {
            if (res.success) {
                $box.html(escPostHistory(res.data.history || []));
            } else {
                $box.html('<span style="color:#b32d2e;">' + escHtml((res.data && res.data.message) || '读取失败') + '</span>');
            }
        }).fail(function () {
            $box.html('<span style="color:#b32d2e;">应用记录请求失败</span>');
        });
    });

    $('#lisun-glossary-impact-body').on('click', '.lisun-impact-tab[data-panel="context"]', function () {
        var $article = $(this).closest('.lisun-impact-article');
        $article.find('.lisun-impact-tab').removeClass('is-active');
        $(this).addClass('is-active');
        $article.find('.lisun-impact-context-panel').show();
        $article.find('.lisun-application-history-box').hide();
    });

    $('#lisun-glossary-apply-confirm').on('click', function () {
        if (!activeImpact) return;
        var selected = Object.keys(selectedPostIds).map(function (id) { return parseInt(id, 10) || 0; }).filter(function (id) { return id > 0; });
        var msg = selected.length
            ? '确认只应用选中的 ' + selected.length + ' 篇文章？本地英语副本更新后将同步推送到远程英文站。'
            : '确认更新这些历史译文？本地英语副本更新后将同步推送到远程英文站。';
        if (!confirm(msg)) return;
        var $btn = $(this).prop('disabled', true).text('正在入队…');
        activeApplyButton = $btn;
        setImpactModalLocked(true);
        var payload = {action:'lisun_glossary_apply_start', term_id:activeImpact.term.term_id, term_revision:activeImpact.term_revision, _nonce:nonce};
        if (selected.length) payload.post_ids = selected;
        $.post(ajaxurl, payload, function(res) {
            if (!res.success) {
                setImpactModalLocked(false);
                refreshApplyButtonLabel();
                alert(res.data.message || '创建批次失败');
                return;
            }
            // 入队成功后清空勾选，避免下一轮误把已选 ID 再次提交。
            selectedPostIds = {};
            $btn.text('处理中…');
            activeBatchId = res.data.batch_id;
            $('#lisun-glossary-batch-status').text(res.data.message);
            refreshHistory();
            pollBatch();
        }).fail(function () {
            setImpactModalLocked(false);
            refreshApplyButtonLabel();
            alert('创建批次失败');
        });
    });

    // 已应用状态下的高风险人工重刷：两次确认，并要求输入固定文字，
    // 防止误点后直接把所有命中文章再次加入队列。
    $('#lisun-glossary-manual-unlock').on('click', function () {
        if (!activeImpact || !activeImpact.term || !activeImpact.application_state
            || activeImpact.application_state.status !== 'applied') return;
        var count = parseInt(activeImpact.application_state.applied_articles || 0, 10) || 0;
        var termLabel = (activeImpact.term.zh || '') + ' → ' + (activeImpact.term.en || '');
        if (!window.confirm('确认要人工解锁术语“' + termLabel + '”吗？\n\n这将重新处理当前已应用的 ' + count + ' 篇文章，并推送到远程英文站。历史记录不会删除。')) return;
        var phrase = window.prompt('为避免误操作，请输入：解锁并重新应用');
        if (phrase !== '解锁并重新应用') {
            if (phrase !== null) window.alert('确认文字不正确，未执行人工解锁。');
            return;
        }
        if (!window.confirm('最后确认：现在重新应用“' + termLabel + '”的全部当前命中文章？')) return;

        var $btn = $(this).prop('disabled', true).text('正在解锁…');
        setImpactModalLocked(true);
        $.post(ajaxurl, {
            action: 'lisun_glossary_manual_unlock',
            term_id: activeImpact.term.term_id,
            term_revision: activeImpact.term_revision,
            unlock_confirmed: 1,
            unlock_phrase: phrase,
            _nonce: nonce
        }, function (res) {
            if (!res.success) {
                setImpactModalLocked(false);
                $btn.prop('disabled', false).text('人工解锁并重新应用');
                window.alert((res.data && res.data.message) || '人工解锁失败');
                return;
            }
            setImpactModalLocked(false);
            $btn.text('人工已解锁');
            $('#lisun-glossary-batch-status').text(res.data.message || '人工解锁成功，请继续确认应用');
            refreshHistory();
            refreshImpactPreview();
        }).fail(function () {
            setImpactModalLocked(false);
            $btn.prop('disabled', false).text('人工解锁并重新应用');
            window.alert('人工解锁请求失败');
        });
    });

    $('#lisun-glossary-apply-retry').on('click', function () {
        if (!activeBatchId) return;
        var $btn = $(this).prop('disabled', true).text('重试中…');
        setImpactModalLocked(true);
        $.post(ajaxurl, {action:'lisun_glossary_apply_retry', batch_id:activeBatchId, _nonce:nonce}, function(res) {
            if (!res.success) {
                setImpactModalLocked(false);
                $btn.prop('disabled', false).text('重试失败');
                alert(res.data.message || '重试失败');
                return;
            }
            $btn.prop('disabled', false).text('重试失败');
            $('#lisun-glossary-batch-status').text(res.data.message);
            pollBatch();
        }).fail(function () {
            setImpactModalLocked(false);
            $btn.prop('disabled', false).text('重试失败');
            alert('重试请求失败');
        });
    });
    $('#lisun-glossary-impact-close, #lisun-glossary-impact-close-footer').on('click', function () {
        if (impactModalLocked) return;
        clearTimeout(batchTimer);
        clearTimeout(staleTimer);
        $('#lisun-glossary-impact-modal').hide();
    });

    // ========== 推送 / 拉取 / Google Sheets ==========
    $('#lisun-push-glossary-en').on('click', function () {
        if (!confirm('<?php echo esc_js(__('确定将当前术语库推送到远程站？将替换远程站现有术语库。', 'lisun-manual')); ?>')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('推送中...', 'lisun-manual')); ?>');

        $.post(ajaxurl, {
            action: 'lisun_push_glossary_en',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('推送到远程站', 'lisun-manual')); ?>');
            if (res.success) {
                alert(res.data.message);
            } else {
                alert('<?php echo esc_js(__('推送失败：', 'lisun-manual')); ?>' + (res.data.message || ''));
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('推送到远程站', 'lisun-manual')); ?>');
            alert('<?php echo esc_js(__('网络请求失败：', 'lisun-manual')); ?>' + textStatus);
        });
    });

    $('#lisun-pull-glossary-en').on('click', function () {
        if (!confirm('<?php echo esc_js(__('将从远程站拉取术语库替换当前术语库，确定？', 'lisun-manual')); ?>')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('拉取中...', 'lisun-manual')); ?>');

        $.post(ajaxurl, {
            action: 'lisun_pull_glossary_en',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('从远程站拉取', 'lisun-manual')); ?>');
            if (res.success) {
                alert(res.data.message);
                location.reload();
            } else {
                alert('<?php echo esc_js(__('拉取失败：', 'lisun-manual')); ?>' + (res.data.message || ''));
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('从远程站拉取', 'lisun-manual')); ?>');
            alert('<?php echo esc_js(__('网络请求失败：', 'lisun-manual')); ?>' + textStatus);
        });
    });

    $('#lisun-sync-sheets').on('click', function () {
        if (!confirm('<?php echo esc_js(__('将从 Google Sheets 同步术语库，可能需要数十秒。确定？', 'lisun-manual')); ?>')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('同步中...', 'lisun-manual')); ?>');

        $.post(ajaxurl, {
            action: 'lisun_sync_glossary_sheets',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('从 Google Sheets 同步', 'lisun-manual')); ?>');
            if (res.success) {
                alert(res.data.message + '\n\n<?php echo esc_js(__('脚本输出', 'lisun-manual')); ?>:\n' + (res.data.log || ''));
                location.reload();
            } else {
                alert('<?php echo esc_js(__('同步失败', 'lisun-manual')); ?>: ' + (res.data.message || '') + '\n\n<?php echo esc_js(__('输出', 'lisun-manual')); ?>:\n' + (res.data.log || ''));
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('从 Google Sheets 同步', 'lisun-manual')); ?>');
            var response = jqXHR.responseText || '';
            var detail = response ? '\n\n' + response.substring(0, 3000) : '';
            alert('<?php echo esc_js(__('请求失败', 'lisun-manual')); ?>: HTTP ' + (jqXHR.status || 0) + ' / ' + textStatus + detail);
        });
    });

    $('#lisun-check-deepl').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('检测中...');

        $.post(ajaxurl, {
            action: 'lisun_check_deepl_connection',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('DeepL 连接检测 / 修复');
            if (res.success) {
                alert(res.data.message || '检测完成');
                location.reload();
            } else {
                alert('检测失败：' + (res.data.message || '未知错误'));
                location.reload();
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('DeepL 连接检测 / 修复');
            alert('请求失败：HTTP ' + (jqXHR.status || 0) + ' / ' + textStatus);
        });
    });
});
</script>
