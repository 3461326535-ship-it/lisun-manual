<?php
/**
 * ��������ҳ�� �� ����ҳ
 */

if (!defined('ABSPATH')) {
    exit;
}

$glossary = Lisun_Manual_Glossary::get_all();
$trash_count = count(Lisun_Manual_Glossary::get_trash());

// ������ȷ��������
if (!is_array($glossary)) {
    $decoded = json_decode($glossary, true);
    $glossary = is_array($decoded) ? $decoded : [];
    update_option('lisun_glossary', $glossary);
}

// Ĭ�ϰ�����ʱ�䵹���¼ӵ��ŵ�һҳ������ֱ�ӵ��ԣ�ͬһ���ӵ���ǰ�档
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

// ��ǰҳ���ݵ�ȫ��������㣨�к��ã�
$start_index = 0;
$deepl_state = Lisun_Manual_Glossary::get_deepl_status();
$deepl_enabled = Lisun_Manual_Glossary::is_enabled();
$deepl_last_error = $deepl_enabled ? (string) ($deepl_state['last_error'] ?? '') : '';
$deepl_synced_at = (int) ($deepl_state['synced_at'] ?? 0);
$deepl_status_labels = [
    'synced' => '��ͬ��',
    'pending' => '��ͬ��',
    'error' => 'ͬ��ʧ��',
    'empty' => '���������Ϊ��',
    'disabled' => '�ѹر�',
];
$deepl_status = $deepl_status_labels[$deepl_state['status'] ?? 'pending'] ?? '��ͬ��';
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('�����', 'lisun-manual'); ?></h1>
    <hr class="wp-header-end">

    <!-- ������ -->
    <div class="lisun-table-actions">
        <div class="lisun-search">
            <input type="text" id="lisun-glossary-search" class="regular-text" placeholder="<?php esc_attr_e('��������...', 'lisun-manual'); ?>">
            <button type="button" class="button" id="lisun-search-btn"><?php _e('����', 'lisun-manual'); ?></button>
            <button type="button" class="button" id="lisun-search-clear" style="display:none;"><?php _e('���', 'lisun-manual'); ?></button>
        </div>
        <div class="lisun-actions-right">
            <button type="button" class="button button-primary" id="lisun-add-glossary-btn">
                <?php _e('��������', 'lisun-manual'); ?>
            </button>
            <label class="button" for="lisun-glossary-csv-input">
                <?php _e('���� CSV', 'lisun-manual'); ?>
            </label>
            <input type="file" id="lisun-glossary-csv-input" accept=".csv" style="display:none;">
            <button type="button" class="button" id="lisun-export-glossary">
                <?php _e('���� CSV', 'lisun-manual'); ?>
            </button>
            <button type="button" class="button" id="lisun-push-glossary-en"
                    title="<?php esc_attr_e('����ǰ��������͵�Զ��վ', 'lisun-manual'); ?>">
                <?php _e('���͵�Զ��վ', 'lisun-manual'); ?>
            </button>
            <button type="button" class="button" id="lisun-pull-glossary-en"
                    title="<?php esc_attr_e('��Զ��վ��ȡ������滻��ǰ�����', 'lisun-manual'); ?>">
                <?php _e('��Զ��վ��ȡ', 'lisun-manual'); ?>
            </button>
            <button type="button" class="button button-primary" id="lisun-sync-sheets"
                    style="background:#0f9d58;border-color:#0f9d58;color:#fff;"
                    title="<?php esc_attr_e('�� Google Sheets ͬ�������', 'lisun-manual'); ?>">
                <?php _e('�� Google Sheets ͬ��', 'lisun-manual'); ?>
            </button>
            <button type="button" class="button" id="lisun-open-glossary-trash">
                ����վ<?php echo $trash_count ? ' (' . (int) $trash_count . ')' : ''; ?>
            </button>
        </div>
    </div>

    <div id="lisun-glossary-trash-panel" style="display:none;background:#fff;border:1px solid #dcdcde;margin:10px 0 15px;padding:14px 16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">
            <h2 style="margin:0;font-size:16px;">�������վ</h2>
            <button type="button" class="button" id="lisun-close-glossary-trash">�ر�</button>
        </div>
        <p id="lisun-glossary-trash-note" style="margin:0 0 10px;color:#646970;">ɾ��������ᱣ�� 30 �죬�ڼ���Իָ���</p>
        <table class="widefat striped">
            <thead><tr><th>��������</th><th>Ӣ���뷨</th><th>��� �� ��Χ</th><th>ɾ��ʱ��</th><th>�Զ�ɾ������</th><th>����</th></tr></thead>
            <tbody id="lisun-glossary-trash-tbody"><tr><td colspan="6" style="text-align:center;color:#999;">���ڼ��ء�</td></tr></tbody>
        </table>
    </div>

    <div id="lisun-deepl-glossary-status" style="margin:10px 0 15px;padding:10px 12px;background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo $deepl_last_error !== '' ? '#d63638' : ($deepl_enabled ? '#2271b1' : '#888'); ?>;">
        <strong>DeepL Glossary��<?php echo esc_html($deepl_status); ?></strong>
        <?php if (!$deepl_enabled): ?>
            <span style="margin-left:12px;color:#666;">����ʱ����ʹ���κ������</span>
        <?php endif; ?>
        <?php if (!empty($deepl_state['glossary_id'])): ?>
            <span style="margin-left:12px;color:#666;">���� <?php echo (int) ($deepl_state['entry_count'] ?? 0); ?> ����ID β�� <?php echo esc_html(substr((string) $deepl_state['glossary_id'], -8)); ?></span>
        <?php endif; ?>
        <?php if ($deepl_synced_at): ?>
            <span style="margin-left:12px;color:#666;">���ͬ����<?php echo esc_html(wp_date('Y-m-d H:i:s', $deepl_synced_at)); ?></span>
        <?php endif; ?>
        <?php if ($deepl_last_error !== ''): ?>
            <div style="margin-top:5px;color:#d63638;">�������<?php echo esc_html($deepl_last_error); ?></div>
        <?php endif; ?>
        <button type="button" class="button" id="lisun-check-deepl" style="margin-top:8px;" <?php echo !$deepl_enabled ? 'disabled title="������ϵͳ���������������"' : ''; ?>><?php echo $deepl_enabled ? 'DeepL ���Ӽ�� / �޸�' : '������ѹر�'; ?></button>
    </div>

    <!-- ����/�༭������Ĭ�����أ� -->
    <div id="lisun-glossary-form" style="display:none; background:#fff; border:1px solid #ddd; padding:15px; margin-bottom:15px;">
        <h3 id="lisun-form-title"><?php _e('��������', 'lisun-manual'); ?></h3>
        <input type="hidden" id="lisun-glossary-index" value="-1">
        <input type="hidden" id="lisun-glossary-term-id" value="">
        <table class="form-table">
            <tr>
                <th><label for="lisun-glossary-zh"><?php _e('��������', 'lisun-manual'); ?></label></th>
                <td><input type="text" id="lisun-glossary-zh" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-en"><?php _e('Ӣ���뷨', 'lisun-manual'); ?></label></th>
                <td><input type="text" id="lisun-glossary-en" class="regular-text" required></td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-category"><?php _e('�������', 'lisun-manual'); ?></label></th>
                <td>
                    <select id="lisun-glossary-category" class="regular-text">
                        <option value="manual">�ֶ�����רҵ����</option>
                        <option value="erp">ERP��Ʒ����</option>
                        <option value="custom">�Զ���</option>
                    </select>
                    <p class="description">���ֻ�����������Ĭ�Ϸ�Χ��������ʵ��ѡ�����ȣ�����ͬ�����ᰴ��𸲸ǡ�</p>
                </td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-scope"><?php _e('���÷�Χ', 'lisun-manual'); ?></label></th>
                <td>
                    <select id="lisun-glossary-scope" class="regular-text">
                        <option value="global">ȫ��ʹ��</option>
                        <option value="article">ָ������</option>
                    </select>
                    <p class="description">��������ɸ������Ĭ��ֵ��ָ�����µ�������������ҳ��ѡ���������¡�</p>
                </td>
            </tr>
            <tr>
                <th><label for="lisun-glossary-note"><?php _e('��ע', 'lisun-manual'); ?></label></th>
                <td><input type="text" id="lisun-glossary-note" class="regular-text" placeholder="�Զ��屸ע����ѡ��"></td>
            </tr>
        </table>
        <p>
            <button type="button" class="button button-primary" id="lisun-save-glossary-btn"><?php _e('����', 'lisun-manual'); ?></button>
            <button type="button" class="button" id="lisun-cancel-glossary-btn"><?php _e('ȡ��', 'lisun-manual'); ?></button>
        </p>
    </div>

    <!-- CSV Ԥ�� -->
    <div class="lisun-csv-preview"></div>

    <!-- �����б� -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th width="4%">#</th>
                <th width="20%"><?php _e('��������', 'lisun-manual'); ?></th>
                <th width="20%"><?php _e('Ӣ���뷨', 'lisun-manual'); ?></th>
                <th width="16%"><?php _e('��� �� ��Χ', 'lisun-manual'); ?></th>
                <th width="10%"><?php _e('��������', 'lisun-manual'); ?></th>
                <th width="14%"><?php _e('���Ӧ��', 'lisun-manual'); ?></th>
                <th width="16%"><?php _e('����', 'lisun-manual'); ?></th>
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
                        $note_extra = !empty($item['note']) ? '��' . esc_html($item['note']) . '��' : '';
                        echo esc_html($cat_label) . ' �� ' . esc_html($scope_label) . $note_extra;
                        ?>
                    </td>
                    <td class="lisun-cell-import-date"><?php echo esc_html($item['import_date'] ?? (date('Y') . '/09/21')); ?></td>
                    <td class="lisun-cell-apply-status" style="font-size:12px;">
                        <?php $latest_apply = Lisun_Manual_Glossary_Apply::latest_for_term($item['term_id'] ?? ''); ?>
                        <?php if ($latest_apply): ?>
                            <?php
                            $latest_status_labels = ['queued' => '�Ŷ���', 'processing' => '������', 'done' => '�����', 'finished_with_errors' => '����ʧ��', 'failed' => 'ʧ��', 'stale' => '������Ӧ��'];
                            $latest_status = $latest_status_labels[$latest_apply['status'] ?? ''] ?? (string) ($latest_apply['status'] ?? '��');
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
                                $latest_status = '������Ӧ��';
                            }
                            ?>
                            <div class="lisun-recent-apply-summary">
                                <span class="lisun-recent-apply-time"><?php echo esc_html((string) $latest_apply['created_at']); ?></span>
                            </div>
                            <div class="lisun-recent-apply-status"><?php echo esc_html($latest_status); ?> �� <?php echo $effective_count; ?> ƪ</div>
                            <button type="button" class="lisun-recent-apply-toggle" data-term-id="<?php echo esc_attr($item['term_id'] ?? ''); ?>">չ��?</button>
                        <?php else: ?>
                            <span style="color:#999;">δӦ��</span>
                        <?php endif; ?>
                    </td>
                    <td class="lisun-row-actions">
                        <span class="lisun-view-btns">
                            <button type="button" class="button button-small lisun-edit-glossary"><?php _e('�༭', 'lisun-manual'); ?></button>
                            <button type="button" class="button button-small lisun-glossary-impact"><?php _e('�鿴Ӱ��', 'lisun-manual'); ?></button>
                            <button type="button" class="button button-small lisun-delete-glossary" style="color:#d63638;"><?php _e('ɾ��', 'lisun-manual'); ?></button>
                        </span>
                        <span class="lisun-edit-btns" style="display:none;">
                            <button type="button" class="button button-small button-primary lisun-save-glossary"><?php _e('����', 'lisun-manual'); ?></button>
                            <button type="button" class="button button-small lisun-cancel-edit"><?php _e('ȡ��', 'lisun-manual'); ?></button>
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
                <tr><td colspan="7" style="text-align:center;color:#999;"><?php _e('�����Ϊ��', 'lisun-manual'); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ��ҳ -->
    <div class="lisun-pagination-bar" style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;">
        <div class="lisun-pagination-info">
            <?php _e('��', 'lisun-manual'); ?> <strong id="lisun-glossary-count"><?php echo $total; ?></strong> <?php _e('������', 'lisun-manual'); ?>
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
                // ʼ����ʾ��1 ... �м� ... N
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
        .lisun-impact-article > summary:before { content:'?'; width:12px; color:#777; font-size:13px; text-align:center; }
        .lisun-impact-article[open] > summary:before { content:'?'; }
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
                <h2 class="lisun-impact-title"><span class="lisun-impact-title-icon">?</span>����Ӱ�췶Χ</h2>
                <span id="lisun-glossary-term-pill" class="lisun-impact-term">��</span>
                <button type="button" class="lisun-impact-close-x" id="lisun-glossary-impact-close" aria-label="�ر�">��</button>
            </div>
            <div id="lisun-glossary-summary" class="lisun-impact-summary"></div>
            <div id="lisun-glossary-history" class="lisun-impact-global-history"></div>
            <div id="lisun-glossary-impact-body" class="lisun-impact-body">����ɨ�衭</div>
            <div class="lisun-impact-footer">
                <p id="lisun-glossary-batch-status"></p>
                <div id="lisun-glossary-batch-errors"></div>
                <button type="button" class="button button-primary" id="lisun-glossary-apply-confirm" disabled>ȷ�ϲ�ȫ�ָ�����������</button>
                <button type="button" class="button lisun-manual-unlock" id="lisun-glossary-manual-unlock" style="display:none;">�˹�����������Ӧ��</button>
                <button type="button" class="button" id="lisun-glossary-apply-retry" style="display:none;">����ʧ������</button>
                <button type="button" class="button" id="lisun-glossary-impact-close-footer">�ر�</button>
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
    var CAT_LABELS   = {manual: '�ֶ�����רҵ����', erp: 'ERP��Ʒ����', custom: '�Զ���'};
    var SCOPE_LABELS = {global: 'ȫ��ʹ��', article: 'ָ������'};

    // HTML ת��
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
            return (issue.row ? '�� ' + issue.row + ' �У�' : '') + (issue.message || '������ڹ�ϵ��ͻ');
        }).join('\n');
    }
    function confirmGlossaryWarnings(data) {
        var text = glossaryIssueMessage(data);
        return data && data.requires_confirmation && window.confirm(text + '\n\nȷ�Ϻ�������棿');
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
    // ���仯ʱ��Χ���䵽���Ĭ��ֵ���û���������ֶ����ǵ�����Χ��
    $('#lisun-glossary-category').on('change', function () {
        $('#lisun-glossary-scope').val(($(this).val() === 'erp') ? 'global' : 'article');
    });
    function latestApplyHtml(latest, termId) {
        if (!latest) return '<span style="color:#999;">δӦ��</span>';
        var labels = {queued:'�Ŷ���', processing:'������', done:'�����', finished_with_errors:'����ʧ��', failed:'ʧ��', stale:'������Ӧ��'};
        var status = labels[latest.status] || latest.status || '��';
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
                status = '������Ӧ��';
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
                status = '������Ӧ��';
            }
        }
        return '<div class="lisun-recent-apply-summary"><span class="lisun-recent-apply-time">' + escHtml(latest.created_at || '') + '</span></div>' +
            '<div class="lisun-recent-apply-status">' + escHtml(status) + ' �� ' + count + ' ƪ</div>' +
            '<button type="button" class="lisun-recent-apply-toggle" data-term-id="' + escAttr(termId || '') + '">չ��?</button>';
    }
    function recentApplyDetailRow(termId) {
        return '<tr class="lisun-recent-apply-detail" data-term-id="' + escAttr(termId || '') + '" style="display:none;">' +
            '<td colspan="7"><div class="lisun-recent-apply-box"></div></td></tr>';
    }
    function recentApplyHistoryHtml(history) {
        if (!history || !history.length) return '<div class="lisun-recent-apply-empty">����Ӧ�ü�¼</div>';
        // �ϲ��������Σ��� post_id ȥ�أ���������һ�εļ�¼��������״̬����
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
                // ����������θ��£�history �Ѱ�ʱ�䵹�������ȵ��������µģ������ǣ���
                if (!byPost[pid]) byPost[pid] = app;
            });
        });
        var displayItems = Object.values(byPost);
        var labels = {queued:'�Ŷ���', processing:'������', done:'�����', finished_with_errors:'����ʧ��', failed:'ʧ��'};
        var latest = history[0] || {};
        var activeDisplayItems = $.grep(displayItems, function (application) {
            return !application.undone;
        });
        var html = '<div class="lisun-recent-apply-heading">�ۼ�Ӧ�� �� ' + escHtml(latest.created_at || '') + ' �� �� ' +
            activeDisplayItems.length + ' ƪ���µ�ǰ��Ӧ��</div>';
        $.each(displayItems, function (_, application) {
            var localOnly = application.local_only || (application.status === 'done' && application.pushed === false);
            var warningOnly = !localOnly && (application.warning_only || application.term_count_warning || (application.post_apply_audit && (application.post_apply_audit.term_count_warning || parseInt(application.post_apply_audit.visible_missing_count || 0, 10) > 0)));
            var status = application.undone ? '�ѳ���' : (localOnly ? 'ʧ�ܣ������Ѹ��£�Զ�̴����ƣ�' : (labels[application.status] || application.status || '��'));
            if (warningOnly && status === '�����') status += '���о��棩';
            var failed = localOnly || (!warningOnly && (application.status === 'failed' || application.status === 'finished_with_errors'));
            var units = unitsByPost[String(application.post_id)] || application.candidate_units || application.translated_units || application.updated || application.occurrences || 0;
            var audit = application.audit || {};
            var appliedCount = parseInt(audit.hit_count || 0, 10) || 0;
            var countLabel = !failed && appliedCount ? appliedCount + ' ��' : units + ' �����뵥Ԫ';
            var contexts = application.contexts || [];
            var contextHtml = contexts.length ? $.map(contexts, function (hit) {
                return '<div class="lisun-recent-hit-context"><div class="lisun-recent-hit-path">' +
                    escHtml((hit.unit_type || 'text') + ' �� ' + (hit.source_path || '')) + '</div><div class="lisun-recent-hit-text">' +
                    escContextText(hit.context || '') + '</div></div>';
            }).join('') : '<div class="lisun-recent-apply-empty">���޿�չʾ������������</div>';
            html += '<div class="lisun-recent-apply-record"><div class="lisun-recent-apply-item">' +
                '<span class="lisun-recent-apply-item-id">#' + escHtml(application.post_id || '') + '</span>' +
                '<span class="lisun-recent-apply-item-title">' + escHtml(application.title || '') + '</span>' +
                '<span class="lisun-recent-apply-item-units">' + escHtml(countLabel) + '</span>' +
                '<span class="lisun-recent-apply-item-state' + (failed ? ' is-failed' : '') + '">' + escHtml(status) + '</span>' +
                '<button type="button" class="lisun-recent-hit-toggle">����չ��?</button>' +
                '</div><div class="lisun-recent-hit-box" style="display:none;">' + contextHtml + '</div></div>';
        });
        return html;
    }

    // ========== ���ط�ҳ���� ==========
    function loadPage(page, keyword) {
        if (typeof keyword === 'undefined') keyword = currentKw;
        $.post(ajaxurl, {
            action: 'lisun_get_glossary_page',
            page: page,
            per_page: PER_PAGE,
            keyword: keyword,
            _nonce: nonce
        }, function (res) {
            if (!res.success) { alert('����ʧ��'); return; }
            var data       = res.data;
            var items      = data.items;
            totalPages     = data.total_pages;
            currentPage    = data.page;
            currentKw      = keyword;

            var tbody = $('#lisun-glossary-tbody');
            tbody.empty();

            if (items.length === 0) {
                tbody.append('<tr><td colspan="7" style="text-align:center;color:#999;"><?php echo esc_js(__('��ƥ����', 'lisun-manual')); ?></td></tr>');
            }

            var offset = (currentPage - 1) * PER_PAGE;
            $.each(items, function (i, item) {
                var rowIndex = offset + i;
                var zh   = item.zh || '';
                var en   = item.en || '';
                var note = item.note || '';
                var catLabel  = CAT_LABELS[item.category] || item.category || '';
                var scopeLabel = SCOPE_LABELS[item.scope] || item.scope || '';
                var noteExtra = note ? '��' + escHtml(note) + '��' : '';
                var importDate = item.import_date || (new Date().getFullYear() + '/09/21');
                tbody.append(
                    '<tr class="lisun-glossary-term-row zebra-' + (i % 2 === 0 ? 'odd' : 'even') + '" data-index="' + rowIndex + '" data-term-id="' + escAttr(item.term_id || '') + '" data-zh="' + escAttr(zh) + '" data-en="' + escAttr(en) + '" data-note="' + escAttr(note) + '" data-category="' + escAttr(item.category || '') + '" data-scope="' + escAttr(item.scope || '') + '" data-import-date="' + escAttr(importDate) + '">' +
                    '<td class="lisun-row-num">' + (rowIndex + 1) + '</td>' +
                    '<td class="lisun-cell-zh">' + escHtml(zh) + '</td>' +
                    '<td class="lisun-cell-en">' + escHtml(en) + '</td>' +
                    '<td class="lisun-cell-category">' + escHtml(catLabel) + ' �� ' + escHtml(scopeLabel) + noteExtra + '</td>' +
                    '<td class="lisun-cell-import-date">' + escHtml(importDate) + '</td>' +
                    '<td class="lisun-cell-apply-status" style="font-size:12px;">' + latestApplyHtml(item.latest_apply, item.term_id || '') + '</td>' +
                    '<td class="lisun-row-actions">' +
                    '<span class="lisun-view-btns">' +
                    '<button class="button button-small lisun-edit-glossary"><?php echo esc_js(__('�༭', 'lisun-manual')); ?></button> ' +
                    '<button class="button button-small lisun-glossary-impact">�鿴Ӱ��</button> ' +
                    '<button class="button button-small lisun-delete-glossary" style="color:#d63638;"><?php echo esc_js(__('ɾ��', 'lisun-manual')); ?></button>' +
                    '</span>' +
                    '<span class="lisun-edit-btns" style="display:none;">' +
                    '<button class="button button-small button-primary lisun-save-glossary"><?php echo esc_js(__('����', 'lisun-manual')); ?></button> ' +
                    '<button class="button button-small lisun-cancel-edit"><?php echo esc_js(__('ȡ��', 'lisun-manual')); ?></button>' +
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

    // ========== ����վ ==========
    function loadGlossaryTrash() {
        var $tbody = $('#lisun-glossary-trash-tbody');
        $tbody.html('<tr><td colspan="6" style="text-align:center;color:#999;">���ڼ��ء�</td></tr>');
        $.post(ajaxurl, {action:'lisun_get_glossary_trash', _nonce:nonce}, function (res) {
            if (!res.success) { $tbody.html('<tr><td colspan="6" style="text-align:center;color:#d63638;">����վ����ʧ��</td></tr>'); return; }
            var items = res.data.items || [];
            $('#lisun-open-glossary-trash').text('����վ' + (items.length ? ' (' + items.length + ')' : ''));
            if (!items.length) { $tbody.html('<tr><td colspan="6" style="text-align:center;color:#999;">����վΪ��</td></tr>'); return; }
            var html = '';
            $.each(items, function (_, item) {
                html += '<tr data-term-id="' + escAttr(item.term_id || '') + '">' +
                    '<td>' + escHtml(item.zh || '') + '</td><td>' + escHtml(item.en || '') + '</td>' +
                    '<td>' + escHtml((CAT_LABELS[item.category] || item.category || '') + ' �� ' + (SCOPE_LABELS[item.scope] || item.scope || '')) + '</td>' +
                    '<td>' + escHtml(item.deleted_at_text || '') + '</td><td>' + escHtml(item.expires_at_text || '') + '</td>' +
                    '<td><button type="button" class="button button-small lisun-restore-glossary">�ָ�</button> ' +
                    '<button type="button" class="button button-small lisun-permanently-delete-glossary" style="color:#d63638;">����ɾ��</button></td></tr>';
            });
            $tbody.html(html);
        }).fail(function () { $tbody.html('<tr><td colspan="6" style="text-align:center;color:#d63638;">����վ����ʧ��</td></tr>'); });
    }

    $('#lisun-open-glossary-trash').on('click', function () { $('#lisun-glossary-trash-panel').slideDown(120); loadGlossaryTrash(); });
    $('#lisun-close-glossary-trash').on('click', function () { $('#lisun-glossary-trash-panel').slideUp(120); });
    $('#lisun-glossary-trash-tbody').on('click', '.lisun-restore-glossary', function () {
        var $btn = $(this), termId = String($btn.closest('tr').data('term-id') || '');
        if (!termId || !confirm('ȷ�ϻָ������')) return;
        $btn.prop('disabled', true);
        $.post(ajaxurl, {action:'lisun_restore_glossary', term_id:termId, _nonce:nonce}, function (res) {
            if (res.success) { loadGlossaryTrash(); loadPage(currentPage); }
            else { alert((res.data && res.data.message) || '�ָ�ʧ��'); $btn.prop('disabled', false); }
        }).fail(function () { alert('�ָ�����ʧ��'); $btn.prop('disabled', false); });
    });
    $('#lisun-glossary-trash-tbody').on('click', '.lisun-permanently-delete-glossary', function () {
        var $btn = $(this), termId = String($btn.closest('tr').data('term-id') || '');
        if (!termId || !confirm('����ɾ�����޷��ָ���ȷ�ϼ�����')) return;
        $btn.prop('disabled', true);
        $.post(ajaxurl, {action:'lisun_permanently_delete_glossary', term_id:termId, _nonce:nonce}, function (res) {
            if (res.success) loadGlossaryTrash();
            else { alert((res.data && res.data.message) || '����ɾ��ʧ��'); $btn.prop('disabled', false); }
        }).fail(function () { alert('����ɾ������ʧ��'); $btn.prop('disabled', false); });
    });

    // ���Ӧ�ã�Ĭ������չ��ʱ��ȡ����һ��������ϸ
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
            $btn.text('չ��?');
            return;
        }
        $detail.show();
        $btn.text('����?');
        var $box = $detail.find('.lisun-recent-apply-box');
        if ($box.data('loaded')) return;
        $box.html('<div class="lisun-recent-apply-empty">���ڶ�ȡ����Ӧ����ϸ��</div>');
        $.post(ajaxurl, {
            action: 'lisun_glossary_apply_history',
            term_id: String($btn.data('term-id') || ''),
            _nonce: nonce
        }, function (res) {
            if (res.success) {
                $box.html(recentApplyHistoryHtml(res.data.history || [])).data('loaded', true);
            } else {
                $box.html('<div class="lisun-recent-apply-empty">��ȡӦ�ü�¼ʧ��</div>');
            }
        }).fail(function () {
            $box.html('<div class="lisun-recent-apply-empty">��ȡӦ�ü�¼����ʧ��</div>');
        });
    });

    $('#lisun-glossary-tbody').on('click', '.lisun-recent-hit-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var $box = $btn.closest('.lisun-recent-apply-record').find('.lisun-recent-hit-box');
        if ($box.is(':visible')) {
            $box.hide();
            $btn.text('����չ��?');
        } else {
            $box.show();
            $btn.text('��������?');
        }
    });

    // ========== ҳ����Ϣ���� ==========
    function updatePageInfo() {
        if (totalPages <= 1) {
            $('#lisun-page-info').text('');
        } else {
            $('#lisun-page-info').text('<?php echo esc_js(__('��', 'lisun-manual')); ?> ' + currentPage + ' / ' + totalPages + ' <?php echo esc_js(__('ҳ', 'lisun-manual')); ?>');
        }
    }
    updatePageInfo();

    // ========== ��Ⱦҳ�밴ť ==========
    function renderPageButtons() {
        var $container = $('#lisun-pagination-pages');
        if (totalPages <= 1) {
            $container.empty();
            return;
        }

        var html = '';
        // ��ҳ & ��һҳ
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

        // ��һҳ & ĩҳ
        html += '<button type="button" class="button button-small lisun-page-btn" data-page="' + Math.min(totalPages, currentPage + 1) + '"' + (currentPage >= totalPages ? ' disabled' : '') + '>&rsaquo;</button>';
        html += '<button type="button" class="button button-small lisun-page-btn" data-page="' + totalPages + '"' + (currentPage >= totalPages ? ' disabled' : '') + '>&raquo;</button>';

        $container.html(html);
    }

    // ========== ҳ�����¼���ί�У� ==========
    $('#lisun-pagination-pages').on('click', '.lisun-page-btn, .lisun-page-num', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var page = parseInt($btn.data('page'));
        if (page && page !== currentPage) {
            loadPage(page);
        }
    });

    // ========== ���ӱ��� ==========
    $('#lisun-add-glossary-btn').on('click', function () {
        $('#lisun-glossary-index').val('-1');
        $('#lisun-glossary-term-id').val('');
        $('#lisun-glossary-zh').val('');
        $('#lisun-glossary-en').val('');
        setGlossaryMeta('manual', 'article', '');
        $('#lisun-form-title').text('<?php echo esc_js(__('��������', 'lisun-manual')); ?>');
        $('#lisun-glossary-form').slideDown();
    });

    $('#lisun-cancel-glossary-btn').on('click', function () {
        $('#lisun-glossary-form').slideUp();
    });

    // ����������/�༭����
    $('#lisun-save-glossary-btn').on('click', function () {
        var zh = $('#lisun-glossary-zh').val().trim();
        var en = $('#lisun-glossary-en').val().trim();
        var meta = getGlossaryMeta();
        var index = parseInt($('#lisun-glossary-index').val(), 10);
        var termId = String($('#lisun-glossary-term-id').val() || '');

        if (!zh || !en) { alert('<?php echo esc_js(__('��Ӣ�����ﲻ��Ϊ��', 'lisun-manual')); ?>'); return; }

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
                    // �û����ȡ�������ֱ������ݣ������棬Ҳ���ٵ��ڶ������ڡ�
                } else {
                    alert(glossaryIssueMessage(res.data) || '<?php echo esc_js(__('����ʧ��', 'lisun-manual')); ?>');
                }
            });
        }
        submit(false);
    });

    // CSV ���룺��һ��ֻ�������͹�ϵ��飬ȷ�Ϻ�������ύ�����������д�롣
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
                        alert('�ѵ��� ' + (res.data.imported || 0) + ' �����');
                        location.reload();
                    } else if (res.data && res.data.requires_confirmation) {
                        if (confirmGlossaryWarnings(res.data)) submit(true);
                        // �û����ȡ���������룬���ٵ��ڶ������ڡ�
                    } else {
                        alert(glossaryIssueMessage(res.data) || 'CSV ����ʧ�ܣ�δ�����κ����');
                    }
                },
                error: function () { alert('CSV ��������ʧ�ܣ�δ�����κ����'); }
            });
        }
        submit(false);
    });

    // ========== ���� ==========
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

    // ========== ���� ==========
    $('#lisun-export-glossary').on('click', function () {
        window.location.href = ajaxurl + '?action=lisun_export_glossary&_nonce=' + nonce;
    });

    // ========== �༭ / ɾ����ί���¼���stopPropagation ��ֹ�ⲿ JS ��ͻ�� ==========
    $('#lisun-glossary-tbody')

    // �༭ �� �򿪶�������
    .on('click', '.lisun-edit-glossary', function (e) {
        e.stopPropagation();
        var $tr   = $(this).closest('tr');
        var index = parseInt($tr.data('index'));
        $('#lisun-glossary-index').val(index);
        $('#lisun-glossary-term-id').val(String($tr.data('term-id') || ''));
        $('#lisun-glossary-zh').val($tr.data('zh'));
        $('#lisun-glossary-en').val($tr.data('en'));
        setGlossaryMeta($tr.data('category'), $tr.data('scope'), $tr.data('note'));
        $('#lisun-form-title').text('<?php echo esc_js(__('�༭����', 'lisun-manual')); ?>');
        $('#lisun-glossary-form').slideDown();
    })

    // ɾ�� �� ��ҳ��֪��reload ��ǰҳ
    .on('click', '.lisun-delete-glossary', function (e) {
        e.stopPropagation();
        if (!confirm('ȷ�Ͻ��������������վ��\n\n����ᱣ�� 30 �죬�ڼ���Իָ���')) return;
        var $tr = $(this).closest('tr');
        var termId = String($tr.data('term-id') || '');
        if (!termId) { alert('����ȱ��Ψһ��ʶ���޷���ȫɾ������ˢ��ҳ������ԡ�'); return; }
        $.post(ajaxurl, {
            action: 'lisun_delete_glossary',
            term_id: termId,
            _nonce: nonce
        }, function (res) {
            if (res.success) {
                loadGlossaryTrash();
                loadPage(currentPage);
            } else {
                alert(res.data.message || '<?php echo esc_js(__('ɾ��ʧ��', 'lisun-manual')); ?>');
            }
        });
    });

    // ========== �������ģ��鿴Ӱ�� �� ȷ�Ϻ��̨����Ӧ�� ==========
    var activeImpact = null;
    var activeBatchId = '';
    var batchTimer = null;
    var staleTimer = null;
    var activeStaleJobIds = [];
    var activeApplyButton = null;
    var impactModalLocked = false;
    // �û���Ӱ���б��ﹴѡ������ ID��map: postId -> true����
    // ��ѡ��ײ���ťֻ�����Щ���£�����ѡ����ȫ��Ӧ����Ϊ��
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
        if (!items || !items.length) return '<li class="lisun-impact-empty">��</li>';
        return $.map(items.slice(0, 30), function (item) {
            var sourceChanged = item.reason === '����Դ���ѱ仯���������·���';
            var retryable = sourceChanged || /�����·���|������|�޷���λ��Ӧ���뵥Ԫ|û���ȶ�����ê��/.test(item.reason || '');
            return '<li><span class="lisun-impact-skipped-text">#' + escHtml(item.post_id) + ' ' + escHtml(item.title || '') +
                (item.reason ? ' �� <span style="color:#996800;">' + escHtml(item.reason) + '</span>' : '') +
                (item.units ? '��' + item.units + ' ����Ԫ��' : '') +
                (item.occurrences ? '��' + item.occurrences + ' ����' : '') +
                '</span>' +
                (retryable ? '<button type="button" class="button button-small lisun-retranslate-stale-single" data-post-id="' + escAttr(item.post_id) + '">' + (sourceChanged ? '��������' : '���·���') + '</button>' : '') +
                '</li>';
        }).join('') + (items.length > 30 ? '<li>������ ' + (items.length - 30) + ' ƪδչ��</li>' : '');
    }

    function sourceChangedSkipped(items) {
        return $.grep(items || [], function (item) {
            return item.reason === '����Դ���ѱ仯���������·���' || /�����·���|������|�޷���λ��Ӧ���뵥Ԫ|û���ȶ�����ê��/.test(item.reason || '');
        });
    }

    function escContextText(text) {
        var safe = escHtml(text || '');
        return safe.replace(/��([^����]+)��/g, '<mark>��$1��</mark>');
    }

    function escComparisonText(text) {
        return escHtml(text || '').replace(/\r?\n/g, '<br>');
    }

    function renderTermComparison(hit) {
        if (!hit || (!hit.source_text && !hit.english_text && !hit.remote_edit_url)) return '';
        var html = '<div class="lisun-impact-comparison">';
        if (hit.source_text) {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">����ԭ��</span>' +
                '<div class="lisun-impact-comparison-text">' + escComparisonText(hit.source_text) + '</div></div>';
        }
        if (hit.english_text) {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">��ǰӢ������</span>' +
                '<div class="lisun-impact-comparison-text">' + escComparisonText(hit.english_text) + '</div></div>';
        } else {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">��ǰӢ������</span>' +
                '<div class="lisun-impact-comparison-text">��ʱ�޷���ȡ�ö�Ӣ��</div></div>';
        }
        if (hit.target_term) {
            html += '<div class="lisun-impact-comparison-block"><span class="lisun-impact-comparison-label">Ŀ������</span>' +
                '<div class="lisun-impact-comparison-text">' + escHtml(hit.target_term) +
                '��Ӣ�Ķ����г��� ' + escHtml(hit.actual_count || 0) + '/' + escHtml(hit.expected_count || 0) + ' �Σ�</div></div>';
        }
        if (hit.remote_edit_url) {
            html += '<a class="lisun-impact-comparison-link" href="' + escAttr(hit.remote_edit_url) + '" target="_blank" rel="noopener">��Զ��Ӣ�����º�̨</a>';
        }
        return html + '</div>';
    }

    function escContexts(item) {
        var contexts = item.contexts || [];
        if (!contexts.length) return '<div class="lisun-impact-empty">���޿�չʾ������������</div>';
        var html = '<div class="lisun-impact-panel lisun-impact-context-panel">';
        $.each(contexts.slice(0, 100), function (_, hit) {
            html += '<div class="lisun-impact-context">' +
                '<div class="lisun-impact-context-path">' + escHtml((hit.unit_type || 'text') + ' �� ' + (hit.source_path || '')) + '</div>' +
                '<div class="lisun-impact-context-text">' + escContextText(hit.context || '') + '</div>' +
                '</div>';
        });
        if (contexts.length > 100) html += '<div class="lisun-impact-empty">���� ' + (contexts.length - 100) + ' ��δչ��</div>';
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
                ? '����Ӧ��δ��ɣ���ӦӢ�Ķ���δʹ��Ŀ���뷨�������´���'
                : (termCountWarning
                ? '����������ʾ��' + rows.length + ' �����˹��˶�'
                : (item.status === 'incomplete' ? 'Ӣ��������ȱ ' : (audit.resolved ? '��ͨ���������� ' : '������ ')) + rows.length + ' ��'))
            : 'Ӣ���������ĵ��� ' + unanchored.length + ' ��δ�����ȶ�ê��';
        var html = '<details class="lisun-impact-unapplied"' + (hardVisibleFailure || termCountWarning ? ' open' : '') + '><summary>' + title + '���鿴ԭ��</summary>';
        if (rows.length) html += '<ul class="lisun-impact-unapplied-list">';
        $.each(rows.slice(0, 200), function (_, hit) {
            var reason = hardVisibleFailure
                ? 'Ӧ�ú�Ӣ��������δ����Ŀ�������������������'
                : (hit.reason || 'Ӣ�ı�����������ִ�����ͬ�����˹��˶�');
            html += '<li class="lisun-impact-unapplied-item"><strong>' + escHtml(hit.unit_type || 'text') + ' �� ' + escHtml(hit.source_path || hit.unit_key || '') + '</strong>' +
                '��' + escContextText(hit.context || hit.matched || '') +
                ' <span class="lisun-impact-unapplied-reason">�� ' + escHtml(reason) + '</span>' +
                renderTermComparison(hit) + '</li>';
        });
        if (rows.length) {
            if (rows.length > 200) html += '<li class="lisun-impact-unapplied-item">���� ' + (rows.length - 200) + ' ���ѱ��浽���μ�¼</li>';
            html += '</ul>';
        }
        if (unanchored.length) html += '<div class="lisun-impact-anchor-note">���� ' + unanchored.length + ' ��Ӣ��Ŀ����Կɼ�����û���ȶ�ê�㣻��Ӱ�챾�οɼ�����ͳ�ƣ������޸������ʱ�����Զ��滻�����˹��˶ԡ�</div>';
        return html + '</details>';
    }

    function latestApplicationsByPost(history) {
        var byPost = {};
        $.each(history || [], function (_, batch) {
            $.each(batch.applications || [], function (_, application) {
                var postId = String(application.post_id || '');
                if (!postId || postId === '0' || byPost[postId]) return;
                application._batch_id = batch.batch_id || application.batch_id || '';
                byPost[postId] = application;
            });
        });
        return byPost;
    }

    function escCandidates(items, history) {
        if (!items || !items.length) return '<div class="lisun-impact-empty">û����Ҫ���µ�����</div>';
        var modeLabels = {direct:'ֱ���滻', incremental:'��������', legacy:'�ɰ氲ȫ�滻', local_only:'�����Ѹ��£�Զ�̴�����'};
        var latestApplications = latestApplicationsByPost(history);
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
                ? ('�ɼ����� ' + visibleAppliedCount + '/' + hitCount + ' ��')
                : mixedApplication
                ? ('ֱ���滻 ' + directOccurrenceCount + ' �� + �������� ' + incrementalUnitCount + ' ����Ԫ������ ' + incrementalOccurrenceCount + ' ����')
                : incomplete && item.attempted_expected_occurrences
                ? ((item.attempted_applied_occurrences || 0) + '/' + item.attempted_expected_occurrences + ' ��')
                : applied
                ? (item.mode === 'incremental' && incrementalUnitCount
                    ? (incrementalUnitCount + ' ����Ԫ')
                    : (appliedOccurrenceCount + ' ��'))
                : (item.mode === 'incremental'
                    ? ((item.units || 0) + ' �����뵥Ԫ')
                    : (item.occurrences ? item.occurrences + ' ��' : ((item.units || 0) + ' �����뵥Ԫ')));
            var unappliedCount = parseInt(audit.unapplied_count || 0, 10) || 0;
            var resolvedCount = parseInt(audit.resolved_count || 0, 10) || 0;
            var processCount = applied ? resolvedCount : unappliedCount;
            var processLabel = applied ? '��ͨ���������� ' : (incomplete ? '�����������ȱʧ ' : '���������� ');
            var termAnomalyCount = parseInt(item.term_anomaly_units || 0, 10) || 0;
            var termWarningCount = parseInt(audit.term_count_warning_count || audit.visible_missing_count || 0, 10) || 0;
            var selectable = !applied;
            var singleButtonText = hardVisibleFailure ? '������������' : '��ƪӦ��';
            var application = latestApplications[String(item.post_id)] || {};
            var undoButton = '';
            if (applied && application.undo_available && application.applied_revision_id) {
                undoButton = '<button type="button" class="lisun-impact-single lisun-undo-apply" data-post-id="' + escAttr(item.post_id) + '" data-batch-id="' + escAttr(application._batch_id || '') + '" data-revision="' + escAttr(application.applied_revision_id) + '" data-has-newer="' + (application.undo_warning ? '1' : '0') + '" title="' + escAttr(application.undo_warning || '�������ָ���Ӧ��ǰ�汾') + '" style="color:#b32d2e;border-color:#f3b9bb;background:#fff5f5;">������ƪ</button>';
            } else if (applied && application.undo_reason) {
                undoButton = '<span class="lisun-impact-single" style="font-size:11px;color:#888;" title="' + escAttr(application.undo_reason) + '">' + escHtml(application.undo_reason) + '</span>';
            }
            return '<details class="lisun-impact-article' + (selectedPostIds[item.post_id] ? ' is-selected' : '') + '" data-post-id="' + escAttr(item.post_id) + '">' +
                '<summary>' +
                    (selectable ? '<input type="checkbox" class="lisun-impact-check" data-post-id="' + escAttr(item.post_id) + '" title="��ѡ�������Ӧ�õ���ƪ" ' + (selectedPostIds[item.post_id] ? 'checked' : '') + '>' : '') +
                    '<span class="lisun-impact-article-title"><strong>#' + escHtml(item.post_id) + '</strong>' + escHtml(item.title || '') + '</span>' +
                    '<span class="lisun-impact-badges">' +
                        '<span class="lisun-impact-badge' + modeClass + '">' + escHtml(applied ? '��Ӧ��' : (hardVisibleFailure ? '����Ӧ��δ���' : (incomplete ? '��������δ���' : (localOnly ? modeLabels.local_only : (modeLabels[item.mode] || item.mode || ''))))) + ' �� ' + escHtml(countText) + '</span>' +
                        '<span class="lisun-impact-badge">���� ' + hitCount + ' ��</span>' +
                        (hasVisibleAudit && stableAnchorCount < hitCount ? '<span class="lisun-impact-badge is-warning">�ȶ�ê�� ' + stableAnchorCount + '/' + hitCount + ' ��</span>' : '') +
                        (hardVisibleFailure ? '<span class="lisun-impact-badge is-warning">Ŀ���뷨δ��ʵ�������´���</span>' : (termWarningCount ? '<span class="lisun-impact-badge is-warning">����������ʾ���� ' + termWarningCount + ' ���������˶ԣ�</span>' : '')) +
                        (termAnomalyCount ? '<span class="lisun-impact-badge is-warning">������쳣 ' + termAnomalyCount + ' ������</span>' : '') +
                        (!applied && !hasVisibleAudit && appliedHitCount && unappliedCount ? '<span class="lisun-impact-badge is-applied">��Ӧ�� ' + appliedHitCount + ' ��</span>' : '') +
                        (processCount ? '<span class="lisun-impact-badge is-warning">' + processLabel + processCount + ' ��</span>' : '') +
                    '</span>' +
                    (applied ? undoButton : '<button type="button" class="lisun-impact-single lisun-apply-single" data-post-id="' + escAttr(item.post_id) + '" data-retry-label="' + escAttr(singleButtonText) + '">' + escHtml(singleButtonText) + '</button>') +
                '</summary>' +
                '<div class="lisun-impact-article-content">' +
                    '<div class="lisun-impact-tabs">' +
                        '<button type="button" class="lisun-impact-tab is-active" data-panel="context">���������� ' + contexts.length + '</button>' +
                        '<button type="button" class="lisun-impact-tab lisun-application-history" data-panel="history" data-post-id="' + escAttr(item.post_id) + '">Ӧ�ü�¼</button>' +
                    '</div>' +
                    ((localOnly || incomplete || hardVisibleFailure) && item.last_error ? '<div class="lisun-impact-panel" style="border-left:3px solid #d63638;color:#8a2424;">' + escHtml(item.last_error) + '</div>' : '') +
                    (termAnomalyCount ? '<div class="lisun-impact-panel" style="border-left:3px solid #dba617;color:#754b12;">�������鷢�� ' + termAnomalyCount + ' �������Ӣ��Ŀ����������������ԭ�Ĳ�һ�£����Ӣ�������˹��˶ԡ�</div>' : '') +
                    escUnappliedMatches(item) +
                    '<div class="lisun-impact-panel lisun-impact-context-panel">' + escContexts(item).replace(/^<div class="lisun-impact-panel lisun-impact-context-panel">|<\/div>$/g, '') + '</div>' +
                    '<div class="lisun-application-history-box lisun-impact-panel" style="display:none;"></div>' +
                '</div>' +
            '</details>';
        }).join('');
    }

    function escPostHistory(history) {
        if (!history || !history.length) return '<span style="color:#999;">���޸����µ�Ӧ�ü�¼</span>';
        var labels = {done:'�����', failed:'ʧ��', processing:'������', pending:'�Ŷ���', running:'������', finished_with_errors:'����ʧ��'};
        var html = '<div style="border-left:3px solid #dcdcde;padding-left:10px;"><strong>Ӧ�ü�¼</strong><ul style="margin:4px 0 0 18px;">';
        $.each(history, function (_, item) {
            var status = labels[item.status] || labels[item.batch_status] || item.status || '��';
            var warningOnly = item.warning_only || (item.post_apply_audit && (item.post_apply_audit.term_count_warning || parseInt(item.post_apply_audit.visible_missing_count || 0, 10) > 0));
            if (warningOnly && status === '�����') status += '���о��棩';
            var line = escHtml(item.time || item.updated_at || '') + ' �� ' + escHtml(status) +
                ' �� ' + applicationScopeLabel(item) +
                ' �� ���� ' + escHtml(item.updated || 0) +
                ' �� ���� ' + escHtml(item.requests || 0);
            var postAudit = item.post_apply_audit || {};
            // After an attempt, the committed-revision audit is authoritative;
            // it explains visible coverage separately from stable anchors.
            var audit = Object.keys(postAudit).length ? postAudit : (item.audit || {});
            if (audit.hit_count) line += ' �� ���� ' + escHtml(audit.hit_count) + ' ��';
            if (Object.prototype.hasOwnProperty.call(audit, 'visible_applied_count')) {
                line += ' �� �ɼ����� ' + escHtml(audit.visible_applied_count) + '/' + escHtml(audit.hit_count || 0) + ' ��';
                if (audit.stable_anchor_count < audit.hit_count) {
                    line += ' �� �ȶ�ê�� ' + escHtml(audit.stable_anchor_count || 0) + '/' + escHtml(audit.hit_count || 0) + ' ��';
                }
            } else if (item.applied_occurrences || postAudit.stable_anchor_count) {
                line += ' �� ��Ӧ�� ' + escHtml(item.applied_occurrences || postAudit.stable_anchor_count) + ' ��';
            }
            var termWarningCount = parseInt(audit.term_count_warning_count || audit.visible_missing_count || 0, 10) || 0;
            var termCountWarning = !!(audit.term_count_warning || termWarningCount > 0);
            if (termWarningCount) {
                line += ' �� ����������ʾ���� ' + escHtml(termWarningCount) + ' ���������˶ԣ�';
            }
            var completedIncremental = item.status === 'done' && item.mode === 'incremental';
            if (!termCountWarning && audit.unapplied_count) line += ' �� ' + (completedIncremental ? '��ͨ���������� ' : '���������� ') + escHtml(audit.unapplied_count) + ' ��';
            if (audit.resolved_count) line += ' �� ��ͨ���������� ' + escHtml(audit.resolved_count) + ' ��';
            if (item.error) line += ' �� ' + escHtml(item.error);
            if (audit.unapplied && audit.unapplied.length) {
                line += '<details style="margin-top:5px;"><summary style="cursor:pointer;color:#754b12;">' + (termCountWarning ? '�鿴����������ʾ��ϸ' : '�鿴����������ϸ') + '</summary><ul style="margin:5px 0 0 18px;">';
                $.each(audit.unapplied.slice(0, 200), function (_, hit) {
                    line += '<li>' + escHtml(hit.source_path || hit.unit_key || '') + '��' + escHtml(hit.reason || 'δ�ҵ���Ӧ��ê��') + renderTermComparison(hit) + '</li>';
                });
                line += '</ul></details>';
            }
            html += '<li>' + line + '</li>';
        });
        return html + '</ul></div>';
    }

    function escHistory(history) {
        if (!history || !history.length) return '<p style="color:#999;">����Ӧ�ü�¼</p>';
        var statusLabels = {queued:'�Ŷ���', processing:'������', done:'�����', finished_with_errors:'����ʧ��', failed:'ʧ��'};
        var html = '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>��Χ</th><th>����/����</th><th>״̬</th><th>ʱ��</th><th>����</th></tr></thead><tbody>';
        $.each(history, function (_, item) {
            var scope = applicationScopeLabel(item);
            if (item.details && item.details.manual_unlock) scope += '���˹�������';
            var target = applicationScopeTarget(item);
            html += '<tr><td>' + scope + '</td><td>' + target + '</td><td>' + escHtml(statusLabels[item.status] || item.status || '��') +
                '</td><td>' + escHtml(item.created_at || '') + '</td><td><code>' + escHtml(item.batch_id || '') + '</code></td></tr>';
            if (item.applications && item.applications.length) {
                html += '<tr><td colspan="5"><details><summary>�鿴��������ϸ��' + item.applications.length + ' ƪ��</summary><ul style="margin:6px 0 0 18px;">';
                $.each(item.applications, function (_, application) {
                    var appStatus = (application.local_only || (application.status === 'done' && application.pushed === false))
                        ? 'ʧ�ܣ������Ѹ��£�Զ�̴����ƣ�'
                        : (application.status === 'done' ? '�����' : 'ʧ��');
                    var appText = '#' + escHtml(application.post_id) + ' ' + escHtml(application.title || '') +
                        '��' + appStatus + '������ ' + escHtml(application.updated || 0) + '������ ' + escHtml(application.reused_units || 0) +
                        '������ ' + escHtml(application.requests || 0);
                    if (application.error) appText += ' �� ' + escHtml(application.error);
                    html += '<li>' + appText + '</li>';
                });
                html += '</ul></details></td></tr>';
            }
        });
        return '<h3 style="margin-bottom:4px;">Ӧ�����μ�¼</h3>' + html + '</tbody></table>';
    }

    function applicationScopeLabel(item) {
        item = item || {};
        var scope = String(item.scope || 'legacy');
        var count = parseInt(item.total_jobs || 0, 10) || 0;
        if (scope === 'single') return '��ƪ';
        if (scope === 'selected') return count === 1 ? 'ѡ����ƪ' : 'ѡ����Χ';
        if (scope === 'all') return 'ȫ����������';
        // Old rows did not retain whether the user selected a subset. Do not
        // mislabel them as a global run merely because they contain >1 page.
        return '�ɰ�����';
    }

    function applicationScopeTarget(item) {
        item = item || {};
        if (item.scope === 'single') {
            return '#' + escHtml(item.post_id) + ' ' + escHtml(item.title || '');
        }
        if (item.scope === 'selected' && parseInt(item.total_jobs || 0, 10) === 1
            && item.applications && item.applications.length) {
            return '#' + escHtml(item.applications[0].post_id || '') + ' ' + escHtml(item.applications[0].title || '');
        }
        return escHtml(item.total_jobs || 0) + ' ƪ';
    }

    function escApplicationState(state) {
        state = state || {};
        var latest = state.latest || null;
        var status = state.status || 'pending';
        var title = status === 'applied'
            ? '��Ӧ��'
            : (status === 'partial'
                ? '��Ӧ�ã�����������������'
                : (status === 'changed' ? '�����ѱ������Ҫ����Ӧ��'
                    : (status === 'unlocked' ? '���˹���������ȷ������Ӧ��' : '������')));
        var className = status === 'applied' ? ' is-applied' : (status === 'changed' || status === 'unlocked' ? ' is-changed' : '');
        var meta = [];
        if (latest) {
            if (latest.created_at || latest.updated_at) meta.push('Ӧ��ʱ�䣺' + (latest.updated_at || latest.created_at));
            if (latest.batch_id) meta.push('���Σ�' + latest.batch_id);
            meta.push('��Χ��' + applicationScopeLabel(latest));
        }
        return '<div class="lisun-impact-state' + className + '">' +
            '<span class="lisun-impact-state-title">' + escHtml(title) + '</span>' +
            (meta.length ? '<span class="lisun-impact-state-meta">' + escHtml(meta.join(' �� ')) + '</span>' : '') +
            '</div>';
    }

    function renderImpact(data) {
        var s = data.summary;
        var state = data.application_state || {};
        var staleItems = sourceChangedSkipped(data.skipped);
        var pendingCount = typeof state.pending_articles === 'number'
            ? state.pending_articles
            : $.grep(data.candidates || [], function (item) { return item.status !== 'applied'; }).length;
        $('#lisun-glossary-term-pill').text((data.term.zh || '') + ' �� ' + (data.term.en || ''));
        var summaryHtml = escApplicationState(state);
        if (state.status === 'applied') {
            var latest = state.latest || {};
            summaryHtml +=
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">��Ӧ������</div><div class="lisun-impact-stat-number">' + escHtml(state.applied_articles || 0) + '<small>ƪ</small></div><div class="lisun-impact-stat-note">��ǰ���о������ظ�Ӧ��</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">Ӧ�÷�Χ</div><div class="lisun-impact-stat-number">' + escHtml(applicationScopeLabel(latest)) + '</div><div class="lisun-impact-stat-note">�ϴγɹ�Ӧ�÷�Χ</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">Ӧ������</div><div class="lisun-impact-stat-number" style="font-size:13px;word-break:break-all;">' + escHtml(latest.batch_id || '��') + '</div><div class="lisun-impact-stat-note">��¼����</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">Ӧ��ʱ��</div><div class="lisun-impact-stat-number" style="font-size:13px;">' + escHtml(latest.updated_at || latest.created_at || '��') + '</div><div class="lisun-impact-stat-note">����״̬δ�仯</div></div>';
        } else {
            summaryHtml +=
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">��ֱ���滻</div><div class="lisun-impact-stat-number">' + escHtml(s.direct_articles || 0) + '<small>ƪ / ' + escHtml(s.direct_occurrences || 0) + '��</small></div><div class="lisun-impact-stat-note">������ DeepL</div></div>' +
                '<div class="lisun-impact-stat is-accent"><div class="lisun-impact-stat-label">����������</div><div class="lisun-impact-stat-number">' + escHtml(s.incremental_articles || 0) + '<small>ƪ / ' + escHtml(s.incremental_units || 0) + '��Ԫ</small></div><div class="lisun-impact-stat-note">Ԥ�� ' + escHtml(s.estimated_characters || 0) + ' �ַ�</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">�ɰ�ɰ�ȫ�滻</div><div class="lisun-impact-stat-number">' + escHtml(s.legacy_articles || 0) + '<small>ƪ</small></div><div class="lisun-impact-stat-note">��ֱ�Ӹ��þɰ�����</div></div>' +
                '<div class="lisun-impact-stat"><div class="lisun-impact-stat-label">����</div><div class="lisun-impact-stat-number">' + escHtml(s.skipped_articles || 0) + '<small>ƪ</small></div><div class="lisun-impact-stat-note">����/Դ�ı仯/�޿ɿ�ê��</div></div>';
            if (s.local_only_articles) {
                summaryHtml += '<div class="lisun-impact-stat is-warning"><div class="lisun-impact-stat-label">�����Ѹ���</div><div class="lisun-impact-stat-number">' + escHtml(s.local_only_articles) + '<small>ƪ</small></div><div class="lisun-impact-stat-note">Զ������ʧ�ܣ���������Ӧ��</div></div>';
            }
        }
        $('#lisun-glossary-summary').html(summaryHtml);

        // Ԥ��ˢ�º��Ѳ��ں�ѡ������£���Ӧ��/�����������ٱ�����ѡ��
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
            '<label><input type="checkbox" id="lisun-impact-selectall"> ȫѡ</label>' +
            '<span class="lisun-impact-selected-count">��ѡ 0 ƪ</span>' +
            '<span class="lisun-impact-selectbar-hint">��ѡ��ײ���ťֻӦ��ѡ�����£�����ѡ��Ӧ��ȫ�� ' + selectableCount + ' ƪ���������¡�</span>' +
        '</div>';

        $('#lisun-glossary-impact-body').html(
            (selectableCount ? selectbar : '') +
            '<div class="lisun-impact-articles">' + escCandidates(data.candidates, data.history) + '</div>' +
            '<details class="lisun-impact-skipped"><summary>���������£�' + escHtml(s.skipped_articles || 0) + ' ƪ��</summary>' +
                (staleItems.length ? '<div class="lisun-stale-retranslate-actions"><span>���� ' + staleItems.length + ' ƪ�������·��벢���ͣ�����ɼ�¼���ᱻ�����</span><button type="button" class="button button-primary lisun-retranslate-stale-all">�������·����� ' + staleItems.length + ' ƪ</button></div>' : '') +
                '<ul>' + escList(data.skipped) + '</ul></details>' +
            '<p class="lisun-impact-empty">��ȷ��Ӧ�á�ֻ������ǰ�ɰ�ȫ���µ����£��ɻָ����������¿ɵ������������·��룬���������Բ��ᱻ�޸ġ�</p>'
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
            text = currentAppStateStatus === 'applied' ? '��Ӧ��' : 'û�п�Ӧ������';
        } else if (n > 0) {
            text = 'ȷ��Ӧ��ѡ�е� ' + n + ' ƪ';
        } else {
            text = 'ȷ�ϲ�ȫ�ָ�����������';
        }
        $btn.text(text).prop('disabled', impactModalLocked || !currentPendingCount);
    }

    function syncSelectbarState() {
        var $body = $('#lisun-glossary-impact-body');
        var $boxes = $body.find('.lisun-impact-check');
        var n = selectedCount();
        $body.find('.lisun-impact-selected-count').text('��ѡ ' + n + ' ƪ');
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
                $('#lisun-glossary-batch-status').text((res.data && res.data.message) || '��������״̬��ѯʧ��');
                return;
            }
            var c = res.data.counts || {};
            $('#lisun-glossary-batch-status').text(
                '�������룺��� ' + (c.done || 0) + '�������� ' + (c.processing || 0) +
                '���ȴ� ' + (c.pending || 0) + '��ʧ�� ' + (c.failed || 0)
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
            $('#lisun-glossary-batch-status').text('��������״̬��ѯʧ�ܣ��ɹرմ��ڣ���̨���л��������');
        });
    }

    /**
     * POST an admin-ajax action as JSON.
     *
     * The enqueue button used to show a bare ������ʧ�ܡ� for every transport
     * problem, which hid whether the server rejected the call or the request
     * never arrived (stale keep-alive socket, CDN/WAF hiccup, expired nonce).
     * Enqueueing is idempotent server-side (enqueue_job reuses an existing
     * pending/processing row), so one automatic retry is safe.
     */
    function lisunDescribeFailure(xhr) {
        var status = xhr && xhr.status ? xhr.status : 0;
        var hint;
        if (status === 0) hint = '����� CDN �жϣ����Զ����� 1 �Σ�';
        else if (status === 401 || status === 403) hint = '��¼��ȫ������ʧЧ����ˢ��ҳ�������';
        else if (status === 404) hint = '�ӿڲ����ڣ���ȷ�ϲ���ļ��Ѹ���';
        else if (status >= 500) hint = '������������� lisun-debug.log�������Ժ�����';
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
        if (!confirm('ȷ�Ͻ��� ' + postIds.length + ' ƪ���¼�������������У�\n\nϵͳ�Ḵ��δ�仯��Ԫ����ɺ��Զ�����Ӣ��վ��')) return;

        clearTimeout(staleTimer);
        $button.prop('disabled', true).text('����С�');
        lisunPostJson({
            action: 'lisun_glossary_retranslate_stale',
            term_id: activeImpact.term.term_id,
            post_ids: postIds,
            _nonce: nonce
        }).done(function (res) {
            if (!res.success || !res.data.queued) {
                $button.prop('disabled', false).text(postIds.length === 1 ? '��������' : '������������');
                alert((res.data && res.data.message) || '�����������ʧ��');
                return;
            }
            activeStaleJobIds = res.data.job_ids || [];
            $button.text('�����');
            $('.lisun-retranslate-stale-single, .lisun-retranslate-stale-all').prop('disabled', true);
            $('#lisun-glossary-batch-status').text(res.data.message || '�����������������');
            pollStaleTranslations();
        }).fail(function (xhr, reason) {
            var why = reason || lisunDescribeFailure(xhr);
            // Admin-ajax path failed: fall back to the REST channel the article
            // metabox uses, so the term workflow does not require opening each
            // article by hand.
            $('#lisun-glossary-batch-status').text('admin-ajax ͨ��ʧ�ܣ����ڸ��� REST ͨ����ӡ�');
            lisunRestEnqueue(postIds).done(function (queued, failedIds) {
                if (queued > 0) {
                    activeStaleJobIds = [];
                    $button.text('�����');
                    $('.lisun-retranslate-stale-single, .lisun-retranslate-stale-all').prop('disabled', true);
                    $('#lisun-glossary-batch-status').text(
                        '��ͨ�� REST ͨ����� ' + queued + ' ƪ'
                        + (failedIds.length ? '��ʧ�� ' + failedIds.length + ' ƪ��' : '')
                        + '����̨���л����������admin-ajax ʧ��ԭ��' + why
                    );
                    return;
                }
                $button.prop('disabled', false).text(postIds.length === 1 ? '��������' : '������������');
                alert('�����������ʧ�ܣ�' + why
                    + '\n\n�ѳ��� REST ͨ�����ˣ�ͬ��δ�ɹ���');
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
            $('#lisun-glossary-batch-status').text('����״̬��' + d.status + '���ɹ� ' + c.done + (c.warnings ? '���о��� ' + c.warnings + '��' : '') + '�������� ' + c.processing + '���ȴ� ' + c.pending + '��ʧ�� ' + c.failed + '������ ' + c.skipped + (c.local_only ? '�������Ѹ��´����� ' + c.local_only : ''));
            renderBatchErrors(d.details || {});
            $('#lisun-glossary-apply-retry').toggle(c.failed > 0);
            if (d.status === 'running') {
                if (activeApplyButton) activeApplyButton.text('�����С�');
                batchTimer = setTimeout(pollBatch, 3000);
            } else {
                setImpactModalLocked(false);
                if (activeApplyButton) {
                    activeApplyButton.prop('disabled', false).text(d.status === 'done' ? '�����' : '����ʧ��');
                }
                refreshHistory();
                loadPage(currentPage);
                refreshImpactPreview();
            }
        }).fail(function () {
            $('#lisun-glossary-batch-status').text('״̬��ѯʧ�ܣ���ˢ��Ӱ�췶Χ�鿴Ӧ�ü�¼');
        });
    }

    function renderBatchErrors(details) {
        var applications = details && details.applications ? details.applications : [];
        var failed = $.grep(applications, function (item) { return item.status === 'failed'; });
        if (!failed.length) {
            $('#lisun-glossary-batch-errors').empty();
            return;
        }
        var html = '<details class="lisun-batch-errors" open><summary>ʧ����ϸ��' + failed.length + ' ƪ��</summary><ul>';
        $.each(failed, function (_, item) {
            var line = '<strong>#' + escHtml(item.post_id || '') + ' ' + escHtml(item.title || '') + '</strong>��' +
                escHtml(item.error || 'δ���ؾ������');
            var meta = [];
            if (item.error_code) meta.push('������ ' + item.error_code);
            if (item.unit_key) meta.push('��Ԫ ' + item.unit_key);
            if (item.batch_index !== undefined) meta.push('�������� ' + item.batch_index);
            if (item.trace_id) meta.push('׷�� ID ' + item.trace_id);
            if (meta.length) line += '<div class="lisun-batch-error-meta">' + escHtml(meta.join(' �� ')) + '</div>';
            $.each(item.error_occurrences || [], function (_, occurrence) {
                line += '<div class="lisun-batch-error-meta">�����ǩ ' + escHtml(occurrence.source_text || occurrence.occurrence_id || '') +
                    '��������' + escHtml(occurrence.expected || '') + '����DeepL ���ء�' + escHtml(occurrence.actual || '') + '��</div>';
            });
            html += '<li>' + line + '</li>';
        });
        html += '</ul></details>';
        $('#lisun-glossary-batch-errors').html(html);
    }

    $('#lisun-glossary-tbody').on('click', '.lisun-glossary-impact', function (e) {
        e.stopPropagation();
        var termId = String($(this).closest('tr').data('term-id') || '');
        if (!termId) { alert('������ȱ���ȶ� ID�������±��������'); return; }
        activeImpact = null; activeBatchId = ''; activeApplyButton = null;
        selectedPostIds = {};
        currentPendingCount = 0; currentAppStateStatus = '';
        clearTimeout(batchTimer);
        clearTimeout(staleTimer);
        activeStaleJobIds = [];
        setImpactModalLocked(false);
        $('#lisun-glossary-term-pill').text('��');
        $('#lisun-glossary-summary').empty();
        $('#lisun-glossary-impact-body').text('����ɨ����Ӱ�����¡�');
        $('#lisun-glossary-batch-status').text('');
        $('#lisun-glossary-batch-errors').empty();
        $('#lisun-glossary-apply-retry').hide();
        $('#lisun-glossary-apply-confirm').prop('disabled', true).text('ȷ�ϲ�ȫ�ָ�����������');
        $('#lisun-glossary-manual-unlock').hide().prop('disabled', false);
        $('#lisun-glossary-impact-modal').show();
        $.post(ajaxurl, {action:'lisun_glossary_impact_preview', term_id:termId, _nonce:nonce}, function(res) {
            if (!res.success) { $('#lisun-glossary-impact-body').text(res.data.message || 'ɨ��ʧ��'); return; }
            activeImpact = res.data;
            renderImpact(activeImpact);
        }).fail(function () { $('#lisun-glossary-impact-body').text('ɨ������ʧ��'); });
    });

    // ��ֹ��ѡʱð�ݵ� <summary>������չ��/�۵����顣
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
    // ȫѡ / ȫ��ѡ
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
        var retryLabel = String($btn.data('retry-label') || '��ƪӦ��');
        if (!postId) return;
        if (!confirm(retryLabel === '������������'
            ? 'ȷ���������������� 1 ƪ���£�\n\n���λ����·�����Ӱ�쵥Ԫ��������ɺ����¼�����'
            : 'ȷ��ֻӦ�õ��� 1 ƪ���£�')) return;
        $btn.prop('disabled', true).text('����С�');
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
                alert(res.data.message || '��ƪӦ��ʧ��');
                return;
            }
            activeBatchId = res.data.batch_id;
            $btn.text('�����С�');
            $('#lisun-glossary-batch-status').text(res.data.message || '��ƪӦ�����������');
            refreshHistory();
            pollBatch();
        }).fail(function (xhr, reason) {
            setImpactModalLocked(false);
            $btn.prop('disabled', false).text(retryLabel);
            alert('��ƪӦ��ʧ�ܣ�' + (reason || lisunDescribeFailure(xhr)));
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
            ? '��ƪ����Ӧ��������Ѿ��������µ����ġ�\n\n����������ָ���Ӧ������֮ǰ�İ汾��������Ӧ�ú�����ĺ��޸ģ����ȷ���������Ӧ�ò������ڱ�ƪ��������ȷ����������\n\n�Ƿ������'
            : '������һƪ������Ӧ�ã�\n\n�����ƪӢ�����Ļָ���Ӧ������֮ǰ�İ汾�����������͵�Զ��վ��';
        if (!confirm(confirmMessage)) return;
        $btn.prop('disabled', true).text('�����С�');
        $.post(ajaxurl, {
            action: 'lisun_glossary_apply_undo',
            batch_id: batchId,
            post_id: postId,
            applied_revision_id: revId,
            _nonce: nonce
        }, function (res) {
            if (!res.success) {
                $btn.prop('disabled', false).text('������ƪ');
                alert(res.data.message || '����ʧ��');
                return;
            }
            $btn.replaceWith('<span class="lisun-impact-single" style="font-size:11px;color:#888;">�ѳ���������ˢ�¡�</span>');
            if (typeof refreshHistory === 'function') refreshHistory();
            refreshImpactPreview();
            loadPage(currentPage);
        }).fail(function () {
            $btn.prop('disabled', false).text('������ƪ');
            alert('��������ʧ��');
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
        $box.show().html('<span style="color:#646970;">���ڶ�ȡӦ�ü�¼��</span>');
        $.post(ajaxurl, {
            action: 'lisun_glossary_apply_post_history',
            term_id: activeImpact.term.term_id,
            post_id: parseInt($btn.data('post-id'), 10),
            _nonce: nonce
        }, function (res) {
            if (res.success) {
                $box.html(escPostHistory(res.data.history || []));
            } else {
                $box.html('<span style="color:#b32d2e;">' + escHtml((res.data && res.data.message) || '��ȡʧ��') + '</span>');
            }
        }).fail(function () {
            $box.html('<span style="color:#b32d2e;">Ӧ�ü�¼����ʧ��</span>');
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
            ? 'ȷ��ֻӦ��ѡ�е� ' + selected.length + ' ƪ���£�����Ӣ�︱�����º�ͬ�����͵�Զ��Ӣ��վ��'
            : 'ȷ�ϸ�����Щ��ʷ���ģ�����Ӣ�︱�����º�ͬ�����͵�Զ��Ӣ��վ��';
        if (!confirm(msg)) return;
        var $btn = $(this).prop('disabled', true).text('������ӡ�');
        activeApplyButton = $btn;
        setImpactModalLocked(true);
        var payload = {action:'lisun_glossary_apply_start', term_id:activeImpact.term.term_id, term_revision:activeImpact.term_revision, _nonce:nonce};
        if (selected.length) payload.post_ids = selected;
        $.post(ajaxurl, payload, function(res) {
            if (!res.success) {
                setImpactModalLocked(false);
                refreshApplyButtonLabel();
                alert(res.data.message || '��������ʧ��');
                return;
            }
            // ��ӳɹ�����չ�ѡ��������һ�������ѡ ID �ٴ��ύ��
            selectedPostIds = {};
            $btn.text('�����С�');
            activeBatchId = res.data.batch_id;
            $('#lisun-glossary-batch-status').text(res.data.message);
            refreshHistory();
            pollBatch();
        }).fail(function () {
            setImpactModalLocked(false);
            refreshApplyButtonLabel();
            alert('��������ʧ��');
        });
    });

    // ��Ӧ��״̬�µĸ߷����˹���ˢ������ȷ�ϣ���Ҫ������̶����֣�
    // ��ֹ����ֱ�Ӱ��������������ٴμ�����С�
    $('#lisun-glossary-manual-unlock').on('click', function () {
        if (!activeImpact || !activeImpact.term || !activeImpact.application_state
            || activeImpact.application_state.status !== 'applied') return;
        var count = parseInt(activeImpact.application_state.applied_articles || 0, 10) || 0;
        var termLabel = (activeImpact.term.zh || '') + ' �� ' + (activeImpact.term.en || '');
        if (!window.confirm('ȷ��Ҫ�˹��������' + termLabel + '����\n\n�⽫���´�����ǰ��Ӧ�õ� ' + count + ' ƪ���£������͵�Զ��Ӣ��վ����ʷ��¼����ɾ����')) return;
        var phrase = window.prompt('Ϊ����������������룺����������Ӧ��');
        if (phrase !== '����������Ӧ��') {
            if (phrase !== null) window.alert('ȷ�����ֲ���ȷ��δִ���˹�������');
            return;
        }
        if (!window.confirm('���ȷ�ϣ���������Ӧ�á�' + termLabel + '����ȫ����ǰ�������£�')) return;

        var $btn = $(this).prop('disabled', true).text('���ڽ�����');
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
                $btn.prop('disabled', false).text('�˹�����������Ӧ��');
                window.alert((res.data && res.data.message) || '�˹�����ʧ��');
                return;
            }
            setImpactModalLocked(false);
            $btn.text('�˹��ѽ���');
            $('#lisun-glossary-batch-status').text(res.data.message || '�˹������ɹ��������ȷ��Ӧ��');
            refreshHistory();
            refreshImpactPreview();
        }).fail(function () {
            setImpactModalLocked(false);
            $btn.prop('disabled', false).text('�˹�����������Ӧ��');
            window.alert('�˹���������ʧ��');
        });
    });

    $('#lisun-glossary-apply-retry').on('click', function () {
        if (!activeBatchId) return;
        var $btn = $(this).prop('disabled', true).text('�����С�');
        setImpactModalLocked(true);
        $.post(ajaxurl, {action:'lisun_glossary_apply_retry', batch_id:activeBatchId, _nonce:nonce}, function(res) {
            if (!res.success) {
                setImpactModalLocked(false);
                $btn.prop('disabled', false).text('����ʧ��');
                alert(res.data.message || '����ʧ��');
                return;
            }
            $btn.prop('disabled', false).text('����ʧ��');
            $('#lisun-glossary-batch-status').text(res.data.message);
            pollBatch();
        }).fail(function () {
            setImpactModalLocked(false);
            $btn.prop('disabled', false).text('����ʧ��');
            alert('��������ʧ��');
        });
    });
    $('#lisun-glossary-impact-close, #lisun-glossary-impact-close-footer').on('click', function () {
        if (impactModalLocked) return;
        clearTimeout(batchTimer);
        clearTimeout(staleTimer);
        $('#lisun-glossary-impact-modal').hide();
    });

    // ========== ���� / ��ȡ / Google Sheets ==========
    $('#lisun-push-glossary-en').on('click', function () {
        if (!confirm('<?php echo esc_js(__('ȷ������ǰ��������͵�Զ��վ�����滻Զ��վ��������⡣', 'lisun-manual')); ?>')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('������...', 'lisun-manual')); ?>');

        $.post(ajaxurl, {
            action: 'lisun_push_glossary_en',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('���͵�Զ��վ', 'lisun-manual')); ?>');
            if (res.success) {
                alert(res.data.message);
            } else {
                alert('<?php echo esc_js(__('����ʧ�ܣ�', 'lisun-manual')); ?>' + (res.data.message || ''));
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('���͵�Զ��վ', 'lisun-manual')); ?>');
            alert('<?php echo esc_js(__('��������ʧ�ܣ�', 'lisun-manual')); ?>' + textStatus);
        });
    });

    $('#lisun-pull-glossary-en').on('click', function () {
        if (!confirm('<?php echo esc_js(__('����Զ��վ��ȡ������滻��ǰ����⣬ȷ����', 'lisun-manual')); ?>')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('��ȡ��...', 'lisun-manual')); ?>');

        $.post(ajaxurl, {
            action: 'lisun_pull_glossary_en',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('��Զ��վ��ȡ', 'lisun-manual')); ?>');
            if (res.success) {
                alert(res.data.message);
                location.reload();
            } else {
                alert('<?php echo esc_js(__('��ȡʧ�ܣ�', 'lisun-manual')); ?>' + (res.data.message || ''));
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('��Զ��վ��ȡ', 'lisun-manual')); ?>');
            alert('<?php echo esc_js(__('��������ʧ�ܣ�', 'lisun-manual')); ?>' + textStatus);
        });
    });

    $('#lisun-sync-sheets').on('click', function () {
        if (!confirm('<?php echo esc_js(__('���� Google Sheets ͬ������⣬������Ҫ��ʮ�롣ȷ����', 'lisun-manual')); ?>')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('ͬ����...', 'lisun-manual')); ?>');

        $.post(ajaxurl, {
            action: 'lisun_sync_glossary_sheets',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('�� Google Sheets ͬ��', 'lisun-manual')); ?>');
            if (res.success) {
                alert(res.data.message + '\n\n<?php echo esc_js(__('�ű����', 'lisun-manual')); ?>:\n' + (res.data.log || ''));
                location.reload();
            } else {
                alert('<?php echo esc_js(__('ͬ��ʧ��', 'lisun-manual')); ?>: ' + (res.data.message || '') + '\n\n<?php echo esc_js(__('���', 'lisun-manual')); ?>:\n' + (res.data.log || ''));
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('�� Google Sheets ͬ��', 'lisun-manual')); ?>');
            var response = jqXHR.responseText || '';
            var detail = response ? '\n\n' + response.substring(0, 3000) : '';
            alert('<?php echo esc_js(__('����ʧ��', 'lisun-manual')); ?>: HTTP ' + (jqXHR.status || 0) + ' / ' + textStatus + detail);
        });
    });

    $('#lisun-check-deepl').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('�����...');

        $.post(ajaxurl, {
            action: 'lisun_check_deepl_connection',
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('DeepL ���Ӽ�� / �޸�');
            if (res.success) {
                alert(res.data.message || '������');
                location.reload();
            } else {
                alert('���ʧ�ܣ�' + (res.data.message || 'δ֪����'));
                location.reload();
            }
        }).fail(function (jqXHR, textStatus) {
            $btn.prop('disabled', false).text('DeepL ���Ӽ�� / �޸�');
            alert('����ʧ�ܣ�HTTP ' + (jqXHR.status || 0) + ' / ' + textStatus);
        });
    });
});
</script>

