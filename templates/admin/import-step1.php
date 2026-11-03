<?php
/**
 * 批量导入 — 步骤1：上传 Word 文件
 */

if (!defined('ABSPATH')) {
    exit;
}

$uploaded_docs = get_option('lisun_uploaded_docs', []);

// 清理磁盘上已不存在的文件记录
$valid_docs = [];
foreach ($uploaded_docs as $key => $doc) {
    if (file_exists($doc['stored_path'])) {
        $valid_docs[$key] = $doc;
    }
}
if (count($valid_docs) !== count($uploaded_docs)) {
    update_option('lisun_uploaded_docs', $valid_docs);
    $uploaded_docs = $valid_docs;
}
?>

<div class="lisun-import-step">

    <h2><?php _e('上传 Word 文件', 'lisun-manual'); ?></h2>
    <p class="description">
        <?php _e('选择需要导入的 .doc 或 .docx 文件。支持多选，按住 Ctrl 或 Shift 选择多个文件。', 'lisun-manual'); ?>
    </p>

    <!-- 拖拽上传区域 -->
    <div class="lisun-drop-zone" id="lisun-doc-drop">
        <div class="lisun-drop-inner">
            <span class="dashicons dashicons-media-document"></span>
            <p><?php _e('拖拽 Word 文件到此处', 'lisun-manual'); ?></p>
            <p class="lisun-drop-or">— <?php _e('或者', 'lisun-manual'); ?> —</p>
            <label class="button button-primary" for="lisun-doc-input">
                <?php _e('选择 Word 文件', 'lisun-manual'); ?>
            </label>
            <input type="file" id="lisun-doc-input" name="docs[]" accept=".doc,.docx" multiple style="display:none;">
        </div>
    </div>

    <!-- 上传进度 -->
    <div class="lisun-progress" id="lisun-doc-progress" style="display:none;">
        <div class="lisun-progress-bar"><div class="lisun-progress-fill"></div></div>
        <p class="lisun-progress-text"></p>
    </div>

    <!-- Word 上传结果（内联错误展示） -->
    <div id="lisun-doc-results" style="margin: 15px 0;"></div>
    <div id="lisun-doc-results-table-wrap" style="display:none; margin: 15px 0;">
        <h3><?php _e('上传详情', 'lisun-manual'); ?></h3>
        <table class="wp-list-table widefat fixed striped" id="lisun-doc-results-table">
            <thead>
                <tr>
                    <th width="30"><?php _e('状态', 'lisun-manual'); ?></th>
                    <th><?php _e('文件名', 'lisun-manual'); ?></th>
                    <th><?php _e('详情', 'lisun-manual'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <!-- 已上传文件列表 -->
    <div id="lisun-doc-list-container">
        <h3><?php _e('已上传的文件', 'lisun-manual'); ?> <span class="count">(<?php echo count($uploaded_docs); ?>)</span></h3>
        <table class="wp-list-table widefat fixed striped" id="lisun-doc-table">
            <thead>
                <tr>
                    <th><?php _e('文件名', 'lisun-manual'); ?></th>
                    <th width="100"><?php _e('格式', 'lisun-manual'); ?></th>
                    <th width="120"><?php _e('上传时间', 'lisun-manual'); ?></th>
                    <th width="120"><?php _e('操作', 'lisun-manual'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($uploaded_docs)): ?>
                    <?php foreach ($uploaded_docs as $stored_name => $doc): ?>
                    <tr data-stored="<?php echo esc_attr($stored_name); ?>">
                        <td><strong><?php echo esc_html($doc['filename']); ?></strong></td>
                        <td><?php echo strtoupper(pathinfo($doc['filename'], PATHINFO_EXTENSION)); ?></td>
                        <td><?php echo esc_html($doc['uploaded_at'] ?? '—'); ?></td>
                        <td>
                            <button type="button" class="button button-small lisun-remove-doc" style="color:#d63638;">
                                <?php _e('移除', 'lisun-manual'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="lisun-no-docs">
                        <td colspan="4" style="text-align:center;color:#999;">
                            <?php _e('尚未上传文件', 'lisun-manual'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 导航按钮 -->
    <div class="lisun-nav-buttons">
        <span></span>
        <a href="<?php echo admin_url('admin.php?page=lisun-manual-import&step=2'); ?>" class="button button-primary" id="lisun-goto-step2">
            <?php _e('下一步：执行导入', 'lisun-manual'); ?> →
        </a>
    </div>
</div>
