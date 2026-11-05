<?php
/**
 * 批量导入 — 步骤2：预览并执行导入
 */

if (!defined('ABSPATH')) {
    exit;
}

$uploaded_docs = get_option('lisun_uploaded_docs', []);

// 清理磁盘上已不存在的文件
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

    <h2><?php _e('执行导入', 'lisun-manual'); ?></h2>
    <p class="description">
        <?php _e('预览解析结果，确认无误后执行导入。导入后的页面将保存为草稿，您可以在编辑器中进一步调整。', 'lisun-manual'); ?>
    </p>

    <!-- 文件选择 -->
    <div id="lisun-import-files-section">
        <h3><?php _e('待导入文件', 'lisun-manual'); ?> <span class="count">(<?php echo count($uploaded_docs); ?>)</span></h3>

        <?php if (empty($uploaded_docs)): ?>
        <p class="description" id="lisun-no-files-msg">
            <?php _e('暂无文件。请返回步骤 1 上传 Word 文件。', 'lisun-manual'); ?>
        </p>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" id="lisun-import-table" <?php echo empty($uploaded_docs) ? 'style="display:none;"' : ''; ?>>
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" id="lisun-select-all" checked></th>
                    <th><?php _e('文件名', 'lisun-manual'); ?></th>
                    <th width="120"><?php _e('上传时间', 'lisun-manual'); ?></th>
                    <th width="150"><?php _e('操作', 'lisun-manual'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($uploaded_docs as $stored_name => $doc): ?>
                <tr data-stored="<?php echo esc_attr($stored_name); ?>">
                    <td><input type="checkbox" class="lisun-import-check" checked value="<?php echo esc_attr($stored_name); ?>"></td>
                    <td><strong><?php echo esc_html($doc['filename']); ?></strong></td>
                    <td><?php echo esc_html($doc['uploaded_at'] ?? '—'); ?></td>
                    <td>
                        <button type="button" class="button button-small lisun-preview-doc"
                            data-stored="<?php echo esc_attr($stored_name); ?>"
                            data-filename="<?php echo esc_attr(pathinfo($doc['filename'], PATHINFO_FILENAME)); ?>">
                            <?php _e('预览', 'lisun-manual'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 预览区域 -->
    <div id="lisun-preview-section" style="display:none; margin-top: 20px;">
        <h3><?php _e('解析预览', 'lisun-manual'); ?></h3>
        <div class="lisun-preview-box">
            <div class="lisun-preview-title">
                <strong><?php _e('标题：', 'lisun-manual'); ?></strong>
                <input type="text" id="lisun-preview-title-input" class="regular-text" placeholder="<?php esc_attr_e('输入页面标题...', 'lisun-manual'); ?>">
            </div>
            <div class="lisun-preview-content" id="lisun-preview-content">
                <!-- 动态渲染预览 -->
            </div>
        </div>
        <!-- [修改时间：2026-11-05 11:49:15 +08:00] 单篇覆盖导入必须先核对旧文章。 -->
        <div class="postbox" style="padding:12px;margin-top:15px;max-width:850px;">
            <label><input type="radio" name="lisun-import-mode" value="new" checked> 新建说明书</label>
            <label style="margin-left:20px;"><input type="radio" name="lisun-import-mode" value="replace"> 替换现有说明书正文（保留链接和远程绑定）</label>
            <div id="lisun-replace-fields" style="display:none;margin-top:12px;">
                <label for="lisun-replace-post-id">旧文章 ID：</label>
                <input type="number" min="1" id="lisun-replace-post-id" class="small-text" placeholder="ID">
                <button type="button" class="button" id="lisun-check-replace-target">核对目标</button>
                <div id="lisun-replace-target" style="margin-top:10px;"></div>
                <p class="description">仅替换正文和原 DOCX。旧标题、链接、SEO、标签、发布状态及远程绑定保持原值；替换前保存可撤回快照。</p>
            </div>
        </div>
    </div>

    <!-- 一键全部导入（无需预览） -->
    <div id="lisun-batch-import-section" style="margin-top: 20px;">
        <button type="button" class="button button-primary button-hero" id="lisun-batch-import">
            <?php _e('一键全部导入（跳过预览）', 'lisun-manual'); ?>
        </button>
        <span class="spinner" style="float:none;margin-top:8px;"></span>
        <span id="lisun-batch-progress" style="margin-left:10px;font-weight:600;"></span>
    </div>

    <!-- 单篇预览+导入 -->
    <div id="lisun-import-actions" style="display:none; margin-top: 20px;">
        <button type="button" class="button button-primary" id="lisun-do-import">
            <?php _e('导入当前预览的这篇', 'lisun-manual'); ?>
        </button>
        <span class="spinner" style="float:none;margin-top:0;"></span>
        <span id="lisun-import-result"></span>
    </div>

    <!-- 导入结果 -->
    <div id="lisun-import-results" style="margin-top:20px;"></div>

    <!-- 导航按钮 -->
    <div class="lisun-nav-buttons">
        <span>
            <a href="<?php echo admin_url('admin.php?page=lisun-manual-import&step=1'); ?>" class="button">
                ← <?php _e('返回上传 Word', 'lisun-manual'); ?>
            </a>
        </span>
    </div>
</div>
