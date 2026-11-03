/**
 * LISUN Manual — 术语库管理交互
 */
(function ($) {
    'use strict';

    var ajaxUrl = lisun_manual_glossary.ajax_url;
    var nonce = lisun_manual_glossary.nonce;

    // glossary-list.php owns the paginated screen and its term-application
    // modal. Avoid registering the legacy duplicate row handlers there.
    if ($('#lisun-glossary-tbody').length) return;

    // 删除术语
    $(document).on('click', '.lisun-delete-glossary', function () {
        var row = $(this).closest('tr');
        var index = row.data('index');

        if (!confirm('确定删除此术语？')) return;

        $.post(ajaxUrl, {
            action: 'lisun_delete_glossary',
            index: index,
            _nonce: nonce
        }, function (response) {
            if (response.success) {
                row.fadeOut(300, function () { $(this).remove(); });
            } else {
                alert(response.data.message || '删除失败');
            }
        });
    });

    // 编辑术语 — 同单元格内替换为输入框
    $(document).on('click', '.lisun-edit-glossary', function () {
        var row = $(this).closest('tr');

        // 把每列文本替换为 input
        ['zh', 'en', 'note'].forEach(function (field) {
            var cell = row.find('.lisun-cell-' + field);
            var text = row.data(field);
            cell.data('original-text', text);
            cell.html('<input type="text" class="lisun-edit-' + field + ' regular-text" value="' + escAttr(text) + '">');
        });

        // 切换按钮
        row.find('.lisun-view-btns').hide();
        row.find('.lisun-edit-btns').show();
    });

    // 取消编辑 — 恢复原文
    $(document).on('click', '.lisun-cancel-edit', function () {
        var row = $(this).closest('tr');

        ['zh', 'en', 'note'].forEach(function (field) {
            var cell = row.find('.lisun-cell-' + field);
            cell.text(cell.data('original-text') || '');
        });

        row.find('.lisun-edit-btns').hide();
        row.find('.lisun-view-btns').show();
    });

    // 保存编辑
    $(document).on('click', '.lisun-save-glossary', function () {
        var row = $(this).closest('tr');
        var index = row.data('index');
        var zh = row.find('.lisun-edit-zh').val();
        var en = row.find('.lisun-edit-en').val();
        var note = row.find('.lisun-edit-note').val();

        if (!zh || !en) {
            alert('中英文术语不能为空');
            return;
        }

        $.post(ajaxUrl, {
            action: 'lisun_save_glossary',
            index: index,
            zh: zh,
            en: en,
            note: note,
            _nonce: nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || '保存失败');
            }
        });
    });

    // CSV 导入预览
    $('#lisun-glossary-csv-input').on('change', function () {
        var file = this.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function (e) {
            var lines = e.target.result.split('\n');
            var preview = $('.lisun-csv-preview');
            preview.empty();

            var previewTable = '<table class="widefat striped"><thead><tr>' +
                '<th>中文术语</th><th>英文译法</th><th>备注</th>' +
                '</tr></thead><tbody>';

            var count = 0;
            for (var i = 0; i < Math.min(lines.length, 11); i++) {
                if (i === 0 && lines[i].toLowerCase().includes('中文')) continue; // 跳过头行
                var cols = lines[i].split(',');
                if (cols.length >= 2) {
                    previewTable += '<tr>' +
                        '<td>' + (cols[0] || '').trim() + '</td>' +
                        '<td>' + (cols[1] || '').trim() + '</td>' +
                        '<td>' + (cols[2] || '').trim() + '</td>' +
                        '</tr>';
                    count++;
                }
            }
            if (lines.length > 11) {
                previewTable += '<tr><td colspan="3">... 共 ' + (lines.length - 1) + ' 条</td></tr>';
            }

            previewTable += '</tbody></table>';
            preview.html(previewTable).show();
        };
        reader.readAsText(file, 'UTF-8');
    });

    // HTML 转义辅助
    function escAttr(str) {
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})(jQuery);
