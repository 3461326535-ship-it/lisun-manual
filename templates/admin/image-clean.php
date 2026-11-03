<div class="wrap lisun-image-clean-wrap">
    <h1><?php _e('图片清理 — 重复附件扫描与去重', 'lisun-manual'); ?></h1>
    <p class="description">
        <?php _e('扫描媒体库中说明书相关的图片附件，按 MD5 找出重复图片。保留一份主力副本，自动更新所有说明书内容和图片映射表中的引用，删除多余的附件及物理文件，释放磁盘空间。', 'lisun-manual'); ?>
    </p>
    <p class="description" style="color:#b32d2e;">
        <strong><?php _e('⚠ 清理操作会物理删除文件和附件记录，请确认备份后再执行。', 'lisun-manual'); ?></strong>
    </p>

    <div style="margin:16px 0;display:flex;align-items:center;gap:12px;">
        <button type="button" class="button button-primary" id="lisun-img-scan-btn">
            <span class="dashicons dashicons-search" style="vertical-align:middle;"></span>
            <?php _e('扫描重复图片', 'lisun-manual'); ?>
        </button>
        <button type="button" class="button button-secondary" id="lisun-img-clean-btn" style="display:none;" disabled>
            <span class="dashicons dashicons-trash" style="vertical-align:middle;"></span>
            <?php _e('清理重复图片', 'lisun-manual'); ?>
        </button>
        <span id="lisun-img-spinner" class="spinner" style="float:none;margin:0;"></span>
        <span id="lisun-img-status" style="color:#666;font-size:13px;"></span>
    </div>

    <!-- 统计面板 -->
    <div id="lisun-img-summary" style="display:none;margin:16px 0;"></div>

    <!-- 重复组列表 -->
    <div id="lisun-img-groups" style="display:none;"></div>
</div>

<script>
(function ($) {
    'use strict';

    var scanData = null;

    function showSpinner(msg) {
        $('#lisun-img-spinner').addClass('is-active');
        if (msg) $('#lisun-img-status').text(msg);
    }
    function hideSpinner(msg) {
        $('#lisun-img-spinner').removeClass('is-active');
        if (msg) $('#lisun-img-status').text(msg);
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(2) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    // 进度条
    var $progressBar = $('<div/>', { id: 'lisun-img-progress-bar', css: { display:'none', margin:'12px 0' } })
        .html('<div style="background:#e0e0e0;border-radius:4px;overflow:hidden;height:16px;"><div id="lisun-img-progress-fill" style="background:#2271b1;height:100%;width:0%;transition:width 0.2s;"></div></div><span id="lisun-img-progress-text" style="font-size:12px;color:#666;"></span>');
    $('.lisun-image-clean-wrap').prepend($progressBar);

    function showProgress(pct, msg) {
        $progressBar.show();
        $('#lisun-img-progress-fill').css('width', pct + '%');
        if (msg) $('#lisun-img-progress-text').text(msg);
    }
    function hideProgress() {
        $progressBar.hide();
        $('#lisun-img-progress-fill').css('width', '0%');
        $('#lisun-img-progress-text').text('');
    }

    // ====== 分片扫描 ======
    $('#lisun-img-scan-btn').on('click', function () {
        showSpinner('正在扫描媒体库...');
        $('#lisun-img-summary').hide();
        $('#lisun-img-groups').hide();
        $('#lisun-img-clean-btn').hide().prop('disabled', true);
        hideProgress();

        function scanChunk(offset, accumulated) {
            $.post(lisun_manual.ajax_url, {
                action: 'lisun_image_scan',
                _nonce: lisun_manual.nonce,
                offset: offset,
                accumulated: accumulated
            }, function (resp) {
                if (!resp.success) {
                    hideSpinner('扫描失败：' + (resp.data && resp.data.message || '未知错误'));
                    hideProgress();
                    return;
                }
                var d = resp.data;
                showProgress(d.progress || 0, '已扫描 ' + d.processed + ' / ' + d.total_ids + ' 个附件...');

                if (!d.done) {
                    // 继续下一批
                    setTimeout(function () {
                        scanChunk(d.offset, d.accumulated);
                    }, 100);
                } else {
                    // 全部完成
                    hideSpinner('');
                    hideProgress();
                    scanData = d;
                    renderSummary(d);
                    renderGroups(d);
                }
            }).fail(function () {
                hideSpinner('网络错误，请重试');
                hideProgress();
            });
        }

        scanChunk(0, '');
    });

    // ====== 分片清理 ======
    $('#lisun-img-clean-btn').on('click', function () {
        if (!scanData || !scanData.duplicate_groups || scanData.duplicate_groups.length === 0) {
            alert('没有可清理的重复图片');
            return;
        }
        if (!confirm('即将删除 ' + scanData.duplicate_count + ' 个重复附件，释放约 ' + scanData.wasted_mb + ' MB。\n\n' +
            '所有引用会自动更新到保留副本，页面展示不受影响。\n\n确定继续？')) {
            return;
        }

        var $btn = $(this).prop('disabled', true);
        showSpinner('正在清理重复图片...');
        hideProgress();

        var totalGroups = scanData.duplicate_groups.length;

        function cleanChunk(groupIndex, dupIndex, accumulatedResults) {
            $.post(lisun_manual.ajax_url, {
                action: 'lisun_image_clean',
                _nonce: lisun_manual.nonce,
                group_index: groupIndex,
                dup_index: dupIndex,
                dry_run: 0,
                accumulated_results: accumulatedResults
            }, function (resp) {
                if (!resp.success) {
                    hideSpinner('清理失败：' + (resp.data && resp.data.message || '未知错误'));
                    hideProgress();
                    $btn.prop('disabled', false);
                    return;
                }
                var d = resp.data;
                showProgress(d.progress || 0, '已清理 ' + (d.group_index + 1) + ' / ' + d.total_groups + ' 组...');

                if (!d.done) {
                    setTimeout(function () {
                        cleanChunk(d.next_group_index, d.next_dup_index, d.accumulated_results);
                    }, 150);
                } else {
                    hideSpinner('');
                    hideProgress();
                    $btn.prop('disabled', false);
                    var r = d.results;
                    alert('清理完成！\n\n' +
                        '已删除：' + r.deleted_count + ' 个重复附件\n' +
                        '释放空间：约 ' + r.freed_mb + ' MB\n' +
                        '更新引用：映射表 ' + r.updated_maps + ' 处，正文 ' + r.updated_posts + ' 处\n' +
                        (r.errors && r.errors.length > 0 ? '错误：' + r.errors.join('\n') : ''));
                    $('#lisun-img-scan-btn').click();
                }
            }).fail(function () {
                hideSpinner('网络错误，请重试');
                hideProgress();
                $btn.prop('disabled', false);
            });
        }

        cleanChunk(0, 0, '');
    });

    function renderSummary(data) {
        var html = '';
        if (data.duplicate_count > 0) {
            html += '<div class="notice notice-warning"><p>';
            html += '共 <strong>' + data.total_attachments + '</strong> 个附件，';
            html += '发现 <strong>' + data.duplicate_groups.length + '</strong> 组重复（涉及 <strong>' + data.duplicate_count + '</strong> 个多余副本），';
            html += '可释放约 <strong>' + data.wasted_mb + ' MB</strong>。';
            if (data.orphan_count > 0) {
                html += ' 另有 <strong>' + data.orphan_count + '</strong> 个孤立附件未被引用。';
            }
            html += '</p></div>';
            $('#lisun-img-clean-btn').show().prop('disabled', false);
        } else {
            html += '<div class="notice notice-success"><p>';
            html += '共 <strong>' + data.total_attachments + '</strong> 个附件，未发现重复图片。';
            if (data.orphan_count > 0) {
                html += ' 有 <strong>' + data.orphan_count + '</strong> 个孤立附件。';
            }
            html += '</p></div>';
        }
        html += '<p style="color:#666;">附件总大小：<strong>' + data.total_mb + ' MB</strong></p>';
        $('#lisun-img-summary').html(html).show();
    }

    function renderGroups(data) {
        if (!data.duplicate_groups || data.duplicate_groups.length === 0) {
            $('#lisun-img-groups').hide();
            return;
        }

        var html = '<h2>重复详情</h2>';
        data.duplicate_groups.forEach(function (g) {
            html += '<div style="border:1px solid #ccd0d4;border-radius:6px;padding:16px;margin-bottom:16px;background:#fff;">';
            html += '<p style="margin:0 0 12px;"><strong>MD5:</strong> <code>' + g.md5 + '</code> — ' + g.count + ' 个重复，共 ' + formatBytes(g.total_size) + '</p>';
            html += '<table class="widefat striped" style="margin:0;"><thead><tr>';
            html += '<th>ID</th><th>文件</th><th>大小</th><th>引用状态</th><th>角色</th>';
            html += '</tr></thead><tbody>';

            // Keeper
            html += renderGroupRow(g.keeper, true);
            // Duplicates
            g.duplicates.forEach(function (d) {
                html += renderGroupRow(d, false);
            });

            html += '</tbody></table></div>';
        });

        $('#lisun-img-groups').html(html).show();
    }

    function renderGroupRow(img, isKeeper) {
        var refs = [];
        if (img.in_content) refs.push('正文引用');
        if (img.in_img_maps) refs.push('映射表');
        if (refs.length === 0) refs.push('无引用');
        var badge = isKeeper
            ? '<span style="background:#d4edda;color:#155724;padding:1px 8px;border-radius:3px;font-size:11px;">保留</span>'
            : '<span style="background:#f8d7da;color:#721c24;padding:1px 8px;border-radius:3px;font-size:11px;">待删除</span>';
        return '<tr>'
            + '<td>' + img.id + '</td>'
            + '<td style="font-family:monospace;font-size:12px;">' + img.file + '</td>'
            + '<td>' + formatBytes(img.size) + '</td>'
            + '<td>' + refs.join('，') + '</td>'
            + '<td>' + badge + '</td>'
            + '</tr>';
    }

})(jQuery);
</script>