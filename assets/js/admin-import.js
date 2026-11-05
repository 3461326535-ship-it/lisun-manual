/**
 * LISUN Manual — 批量导入交互
 */
(function ($) {
    'use strict';

    var ajaxUrl = lisun_manual.ajax_url;
    var nonce = lisun_manual.nonce;
    var UPLOAD_CHUNK_SIZE = 1;       // 每批上传文件数，避免超过 post_max_size（图片较大时逐个上传）
    var FAILED_FILES = [];           // 收集失败文件用于重试
    var isUploading = false;         // 防并发上传锁

    // 真分片上传配置（从 PHP 注入，有默认值兜底）
    var CHUNK_CONFIG = lisun_manual.chunk || {};
    var CHUNK_SIZE = CHUNK_CONFIG.chunk_size || (2 * 1024 * 1024);   // 每片字节数，默认 2MB
    var MAX_CHUNK_RETRIES = CHUNK_CONFIG.max_retries || 3;            // 每片最多重试次数
    var MAX_FILE_SIZE = CHUNK_CONFIG.max_file_size || (500 * 1024 * 1024); // 文件大小警告阈值

    // ========== 步骤 1：图片上传 ==========

    // 拖拽支持
    var imgDropZone = document.getElementById('lisun-img-drop');
    if (imgDropZone) {
        imgDropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            this.classList.add('lisun-dragover');
        });
        imgDropZone.addEventListener('dragleave', function () {
            this.classList.remove('lisun-dragover');
        });
        imgDropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            this.classList.remove('lisun-dragover');
            var files = e.dataTransfer.files;
            if (files.length > 0) {
                var isZip = files.length === 1 && files[0].name.toLowerCase().endsWith('.zip');
                if (isZip) {
                    uploadZipFile(files[0]);
                } else {
                    uploadImageFiles(files);
                }
            }
        });
    }

    // 图片文件选择
    $('#lisun-img-input').on('change', function () {
        if (this.files.length > 0) {
            uploadImageFiles(this.files);
            this.value = ''; // 清空 input 以支持重复选择同一文件
        }
    });

    // ZIP 文件选择
    $('#lisun-zip-input').on('change', function () {
        if (this.files.length > 0) {
            uploadZipFile(this.files[0]);
            this.value = '';
        }
    });

    /**
     * 上传图片文件（分块 + 断点续传）
     */
    function uploadImageFiles(files) {
        if (isUploading) {
            alert('上传进行中，请等待当前批次完成...');
            return;
        }

        var fileArray = Array.from(files);
        if (fileArray.length === 0) return;

        // 重置状态
        FAILED_FILES = [];
        $('#lisun-upload-results').empty();
        $('#lisun-upload-results-table-wrap').hide();

        // 步骤 1：检查已存在的文件
        var filenames = fileArray.map(function (f) { return f.name; });

        $.post(ajaxUrl, {
            action: 'lisun_check_existing_images',
            filenames: filenames,
            _nonce: nonce
        }, function (response) {
            if (response.success && response.data.existing && response.data.existing.length > 0) {
                showExistingFilesPrompt(response.data.existing, fileArray);
            } else {
                startChunkedUpload(fileArray, false);
            }
        }).fail(function () {
            // 检查失败则直接开始上传，不做预检
            startChunkedUpload(fileArray, false);
        });
    }

    /**
     * 显示已有文件提示
     */
    function showExistingFilesPrompt(existing, fileArray) {
        var html = '<div class="notice notice-warning inline lisun-existing-prompt">';
        html += '<p><strong>' + existing.length + ' 个文件已存在：</strong></p><ul>';
        existing.forEach(function (name) {
            html += '<li>' + escapeHtml(name) + '</li>';
        });
        html += '</ul>';
        html += '<p>';
        html += '<button class="button" id="lisun-skip-existing">跳过已存在的文件</button> ';
        html += '<button class="button button-primary" id="lisun-overwrite-existing">全部重新上传</button>';
        html += '</p></div>';
        $('#lisun-upload-results').html(html);

        $('#lisun-skip-existing').on('click', function () {
            $('#lisun-upload-results').empty();
            startChunkedUpload(fileArray, true);
        });
        $('#lisun-overwrite-existing').on('click', function () {
            $('#lisun-upload-results').empty();
            startChunkedUpload(fileArray, false);
        });
    }

    /**
     * 分块顺序上传
     */
    function startChunkedUpload(fileArray, skipExisting) {
        isUploading = true;

        var chunks = [];
        for (var i = 0; i < fileArray.length; i += UPLOAD_CHUNK_SIZE) {
            chunks.push(fileArray.slice(i, i + UPLOAD_CHUNK_SIZE));
        }

        var totalChunks = chunks.length;

        function uploadChunk(index) {
            if (index >= chunks.length) {
                isUploading = false;
                onAllUploadsComplete();
                return;
            }

            var chunk = chunks[index];
            var formData = new FormData();
            formData.append('action', 'lisun_upload_images');
            formData.append('_nonce', nonce);
            if (skipExisting) {
                formData.append('skip_existing', '1');
            }

            for (var j = 0; j < chunk.length; j++) {
                formData.append('images[]', chunk[j]);
            }

            var chunkNames = chunk.map(function(f) { return f.name; }).join(', ');
            if (chunkNames.length > 80) {
                chunkNames = chunkNames.substring(0, 80) + '...';
            }
            var startNum = index * UPLOAD_CHUNK_SIZE + 1;
            var endNum = Math.min(startNum + chunk.length - 1, fileArray.length);
            showProgress('#lisun-img-progress',
                '第 ' + startNum + '-' + endNum + '/' + fileArray.length + ' 个 (批次 ' + (index + 1) + '/' + totalChunks + ')：' + chunkNames);
            $('#lisun-upload-results-table-wrap').show();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', function(e) {
                            if (e.lengthComputable) {
                                var pct = Math.round(e.loaded / e.total * 100);
                                showProgress('#lisun-img-progress',
                                    '第 ' + startNum + '-' + endNum + '/' + fileArray.length + ' 个 (批次 ' + (index + 1) + '/' + totalChunks + ')：' + chunkNames + ' — ' + pct + '%');
                            }
                        });
                    }
                    return xhr;
                },
                success: function (response) {
                    hideProgress('#lisun-img-progress');
                    if (response.success) {
                        if (response.data.results) {
                            appendChunkResults(response.data.results);
                        }
                    } else {
                        // 整批失败（post_max_size / 服务端错误）
                        var errMsg = response.data.message || '上传批次失败';
                        if (response.data.code === 'post_max_size_exceeded') {
                            errMsg = '请求体过大，请减少每批上传数量或联系主机商调整 post_max_size';
                        }
                        appendChunkError(chunk, errMsg);
                    }
                    uploadChunk(index + 1);
                },
                error: function (jqXHR, textStatus) {
                    hideProgress('#lisun-img-progress');
                    var errMsg;
                    if (jqXHR.status === 413) {
                        errMsg = '请求体过大(413)，请减少每批上传数量或联系主机商增大 client_max_body_size';
                    } else {
                        errMsg = '网络错误：' + textStatus + '（批次 ' + (index + 1) + '/' + totalChunks + '）';
                    }
                    appendChunkError(chunk, errMsg);
                    uploadChunk(index + 1); // 即使失败也继续下一批
                }
            });
        }

        $('#lisun-upload-results').empty();
        $('#lisun-upload-results-table tbody').empty();
        uploadChunk(0);
    }

    /**
     * 渲染单个 chunk 的结果行
     */
    function appendChunkResults(results) {
        var html = '';
        results.forEach(function (r) {
            var icon, color;
            if (r.status === 'success') {
                icon = '&#10003;';
                color = 'green';
            } else if (r.status === 'skipped') {
                icon = '&ndash;';
                color = '#dba617';
            } else {
                icon = '&#10007;';
                color = '#d63638';
                FAILED_FILES.push({ filename: r.filename, message: r.message });
            }
            html += '<tr>';
            html += '<td><span style="color:' + color + ';font-weight:bold;">' + icon + '</span></td>';
            html += '<td>' + escapeHtml(r.filename) + '</td>';
            html += '<td style="color:' + color + ';">' + escapeHtml(r.message || r.status) + '</td>';
            html += '</tr>';
        });
        $('#lisun-upload-results-table tbody').append(html);
    }

    /**
     * 整批失败时标记该批所有文件
     */
    function appendChunkError(files, errorMsg) {
        var html = '';
        files.forEach(function (f) {
            html += '<tr>';
            html += '<td><span style="color:#d63638;font-weight:bold;">&#10007;</span></td>';
            html += '<td>' + escapeHtml(f.name || f.filename || '') + '</td>';
            html += '<td style="color:#d63638;">' + escapeHtml(errorMsg) + '</td>';
            html += '</tr>';
            FAILED_FILES.push({ file: f, message: errorMsg });
        });
        $('#lisun-upload-results-table tbody').append(html);
    }

    /**
     * 全部批次完成后显示摘要
     */
    function onAllUploadsComplete() {
        hideProgress('#lisun-img-progress');

        var totalRows = $('#lisun-upload-results-table tbody tr').length;
        var failCount = FAILED_FILES.length;
        var successCount = totalRows - failCount;

        var summaryHtml = '<div class="lisun-upload-summary">';
        summaryHtml += '<p>上传完成：成功 ' + successCount + ' 个';
        if (failCount > 0) {
            summaryHtml += '，失败 ' + failCount + ' 个';
            summaryHtml += ' <button class="button" id="lisun-retry-failed">重试失败的文件</button>';
        }
        summaryHtml += '</p></div>';
        $('#lisun-upload-results').prepend(summaryHtml);

        if (failCount > 0) {
            $('#lisun-retry-failed').on('click', function () {
                retryFailedFiles();
            });
        }
    }

    /**
     * 重试失败文件
     */
    function retryFailedFiles() {
        var retryFiles = [];
        FAILED_FILES.forEach(function (f) {
            if (f.file) {
                retryFiles.push(f.file);
            }
        });

        if (retryFiles.length === 0) {
            alert('无法重试：文件对象已释放。请重新选择文件。');
            return;
        }

        FAILED_FILES = [];
        $('#lisun-upload-results').empty();
        $('#lisun-upload-results-table tbody').empty();
        startChunkedUpload(retryFiles, false);
    }

    /**
     * 上传 ZIP 文件
     */
    function uploadZipFile(file) {
        if (isUploading) {
            alert('上传进行中，请等待...');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'lisun_upload_zip');
        formData.append('_nonce', nonce);
        formData.append('zip_file', file);

        isUploading = true;
        showProgress('#lisun-img-progress', '正在解压并上传...');
        $('#lisun-upload-results-table-wrap').show();
        $('#lisun-upload-results').empty();
        $('#lisun-upload-results-table tbody').empty();

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                hideProgress('#lisun-img-progress');
                isUploading = false;
                if (response.success) {
                    if (response.data.results) {
                        appendChunkResults(response.data.results);
                    }
                    onAllUploadsComplete();
                } else {
                    var errMsg = response.data.message || 'ZIP 上传失败';
                    var errorRow = '<tr><td><span style="color:#d63638;font-weight:bold;">&#10007;</span></td>' +
                        '<td>' + escapeHtml(file.name) + '</td>' +
                        '<td style="color:#d63638;">' + escapeHtml(errMsg) + '</td></tr>';
                    $('#lisun-upload-results-table tbody').append(errorRow);
                    FAILED_FILES.push({ filename: file.name, message: errMsg });
                }
            },
            error: function () {
                hideProgress('#lisun-img-progress');
                isUploading = false;
                var errorRow = '<tr><td><span style="color:#d63638;font-weight:bold;">&#10007;</span></td>' +
                    '<td>' + escapeHtml(file.name) + '</td>' +
                    '<td style="color:#d63638;">网络错误：ZIP 上传失败</td></tr>';
                $('#lisun-upload-results-table tbody').append(errorRow);
                FAILED_FILES.push({ filename: file.name, message: '网络错误' });
                location.reload();
            }
        });
    }

    // ========== 步骤 2：图片映射管理 ==========

    // 删除映射
    $('.lisun-delete-map').on('click', function () {
        var row = $(this).closest('tr');
        var filename = row.data('filename');

        if (!confirm('确定删除 "' + filename + '" 的映射？')) return;

        $.post(ajaxUrl, {
            action: 'lisun_delete_img_map',
            filename: filename,
            _nonce: nonce
        }, function (response) {
            if (response.success) {
                row.fadeOut(300, function () { $(this).remove(); });
            } else {
                alert(response.data.message || '删除失败');
            }
        });
    });

    // 保存映射
    $('.lisun-save-map').on('click', function () {
        var row = $(this).closest('tr');
        var oldFilename = row.data('filename');
        var newFilename = row.find('.lisun-map-filename').val();
        var newUrl = row.find('.lisun-map-url').val();

        $.post(ajaxUrl, {
            action: 'lisun_update_img_map',
            old_filename: oldFilename,
            new_filename: newFilename,
            new_url: newUrl,
            _nonce: nonce
        }, function (response) {
            if (response.success) {
                alert('保存成功');
                location.reload();
            } else {
                alert(response.data.message || '保存失败');
            }
        });
    });

    // 选择图片
    $('.lisun-select-image').on('click', function () {
        var row = $(this).closest('tr');
        var urlInput = row.find('.lisun-map-url');

        var mediaFrame = wp.media({
            title: '选择图片',
            button: { text: '使用此图片' },
            multiple: false
        });

        mediaFrame.on('select', function () {
            var attachment = mediaFrame.state().get('selection').first().toJSON();
            urlInput.val(attachment.url);
        });

        mediaFrame.open();
    });

    // 导出映射
    $('#lisun-export-maps').on('click', function () {
        var maps = {};
        $('#lisun-map-table tbody tr').each(function () {
            var filename = $(this).find('.lisun-map-filename').val();
            var url = $(this).find('.lisun-map-url').val();
            if (filename) maps[filename] = url;
        });

        var blob = new Blob([JSON.stringify(maps, null, 2)], { type: 'application/json' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'lisun-img-maps-' + new Date().toISOString().slice(0, 10) + '.json';
        link.click();
    });

    // ========== 步骤 3：Word 文件上传 ==========

    // 拖拽上传 Word
    var docDropZone = document.getElementById('lisun-doc-drop');
    if (docDropZone) {
        docDropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            this.classList.add('lisun-dragover');
        });
        docDropZone.addEventListener('dragleave', function () {
            this.classList.remove('lisun-dragover');
        });
        docDropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            this.classList.remove('lisun-dragover');
            if (e.dataTransfer.files.length > 0) {
                uploadDocFiles(e.dataTransfer.files);
            }
        });
    }

    // 文件选择
    $('#lisun-doc-input').on('change', function () {
        if (this.files.length > 0) {
            uploadDocFiles(this.files);
            this.value = '';
        }
    });

    /**
     * 上传 Word 文件（分块 + 断点续传）
     */
    function uploadDocFiles(files) {
        if (isUploading) {
            alert('上传进行中，请等待当前批次完成...');
            return;
        }

        var fileArray = Array.from(files);
        if (fileArray.length === 0) return;

        // 重置状态
        FAILED_FILES = [];
        $('#lisun-doc-results').empty();
        $('#lisun-doc-results-table-wrap').hide();

        // 步骤 1：检查已存在的文件
        var filenames = fileArray.map(function (f) { return f.name; });

        $.post(ajaxUrl, {
            action: 'lisun_check_existing_docs',
            filenames: filenames,
            _nonce: nonce
        }, function (response) {
            if (response.success && response.data.existing && response.data.existing.length > 0) {
                showDocExistingPrompt(response.data.existing, fileArray);
            } else {
                startDocChunkedUpload(fileArray, false);
            }
        }).fail(function () {
            // 检查失败则直接开始上传，不做预检
            startDocChunkedUpload(fileArray, false);
        });
    }

    /**
     * 显示已有 Word 文件提示
     */
    function showDocExistingPrompt(existing, fileArray) {
        var html = '<div class="notice notice-warning inline lisun-existing-prompt">';
        html += '<p><strong>' + existing.length + ' 个文件已存在：</strong></p><ul>';
        existing.forEach(function (name) {
            html += '<li>' + escapeHtml(name) + '</li>';
        });
        html += '</ul>';
        html += '<p>';
        html += '<button class="button" id="lisun-skip-docs">跳过已存在的文件</button> ';
        html += '<button class="button button-primary" id="lisun-overwrite-docs">全部重新上传</button>';
        html += '</p></div>';
        $('#lisun-doc-results').html(html);

        $('#lisun-skip-docs').on('click', function () {
            $('#lisun-doc-results').empty();
            startDocChunkedUpload(fileArray, true);
        });
        $('#lisun-overwrite-docs').on('click', function () {
            $('#lisun-doc-results').empty();
            startDocChunkedUpload(fileArray, false);
        });
    }

    /**
     * 真分片上传 Word 文件 — 逐文件、逐分片上传
     *
     * 每个文件被切为 CHUNK_SIZE 大小的二进制分片，
     * 逐个上传到服务端，收齐后服务端自动拼接。
     */
    function startDocChunkedUpload(fileArray, skipExisting) {
        isUploading = true;

        var totalFiles = fileArray.length;
        var currentFileIndex = 0;

        function processNextFile() {
            if (currentFileIndex >= totalFiles) {
                isUploading = false;
                onDocUploadsComplete();
                return;
            }

            var file = fileArray[currentFileIndex];

            // 文件大小警告
            if (file.size > MAX_FILE_SIZE) {
                if (!confirm('文件 "' + file.name + '" 大小为 ' +
                    (file.size / 1024 / 1024).toFixed(1) + ' MB，超过建议上限 ' +
                    (MAX_FILE_SIZE / 1024 / 1024).toFixed(0) + ' MB，是否继续上传？')) {
                    // 跳过该文件
                    appendDocChunkResults([{
                        filename: file.name,
                        status: 'error',
                        message: '用户取消（文件过大）'
                    }]);
                    currentFileIndex++;
                    processNextFile();
                    return;
                }
            }

            var uploadId = generateUploadId();

            showProgress('#lisun-doc-progress',
                '文件 ' + (currentFileIndex + 1) + '/' + totalFiles + ': ' + file.name + ' — 准备上传...');
            $('#lisun-doc-results-table-wrap').show();

            uploadFileChunks(file, uploadId, skipExisting, currentFileIndex, totalFiles, function (result) {
                if (result && result.status === 'success') {
                    appendDocChunkResults([result]);
                } else if (result && result.status === 'skipped') {
                    appendDocChunkResults([result]);
                } else {
                    // 整个文件上传失败：直接渲染错误行 + 记录 File 对象用于重试
                    if (result) {
                        var html = '<tr>';
                        html += '<td><span style="color:#d63638;font-weight:bold;">&#10007;</span></td>';
                        html += '<td>' + escapeHtml(result.filename || file.name) + '</td>';
                        html += '<td style="color:#d63638;">' + escapeHtml(result.message || '上传失败') + '</td>';
                        html += '</tr>';
                        $('#lisun-doc-results-table tbody').append(html);
                        // 只推一条带 File 对象的记录，用于计数和重试
                        FAILED_FILES.push({ file: file, message: result.message || '上传失败' });
                    }
                }
                currentFileIndex++;
                processNextFile();
            });
        }

        $('#lisun-doc-results').empty();
        $('#lisun-doc-results-table tbody').empty();
        processNextFile();
    }

    /**
     * 生成唯一上传会话 ID
     */
    function generateUploadId() {
        return Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 10);
    }

    /**
     * 上传单个文件的所有分片（递归 + 重试）
     *
     * @param {File}     file          浏览器 File 对象
     * @param {string}   uploadId      上传会话 ID
     * @param {boolean}  skipExisting  是否跳过已存在文件
     * @param {number}   fileIndex     当前文件索引（用于进度显示）
     * @param {number}   totalFiles    总文件数
     * @param {Function} onComplete    完成回调，参数 {status, filename, message, stored_name?}
     */
    function uploadFileChunks(file, uploadId, skipExisting, fileIndex, totalFiles, onComplete) {
        var totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        var currentChunkIndex = 0;
        var completed = false;   // 防止重试竞态导致 onComplete 被调用多次
        var currentXHR = null;   // 当前 AJAX 请求引用，用于重试前 abort

        // 零字节文件
        if (totalChunks === 0) {
            onComplete({
                filename: file.name,
                status: 'error',
                message: '文件为空（0 字节）'
            });
            return;
        }

        function sendNextChunk() {
            if (currentChunkIndex >= totalChunks) {
                // 所有分片已发送，onComplete 已在最后一个分片的 success 回调中调用
                return;
            }

            var start = currentChunkIndex * CHUNK_SIZE;
            var end = Math.min(start + CHUNK_SIZE, file.size);
            var blob = file.slice(start, end);

            // 更新进度（用即将上传的分片序号计算百分比）
            var pct = Math.round((currentChunkIndex + 1) / totalChunks * 100);
            showProgress('#lisun-doc-progress',
                '文件 ' + (fileIndex + 1) + '/' + totalFiles + ': ' + file.name +
                ' — 分片 ' + (currentChunkIndex + 1) + '/' + totalChunks + ' (' + pct + '%)');

            var formData = new FormData();
            formData.append('action', 'lisun_upload_doc_chunk');
            formData.append('_nonce', nonce);
            formData.append('upload_id', uploadId);
            formData.append('filename', file.name);
            formData.append('chunk_index', currentChunkIndex);
            formData.append('total_chunks', totalChunks);
            formData.append('chunk_data', blob, file.name + '.chunk' + currentChunkIndex);
            if (skipExisting) {
                formData.append('skip_existing', '1');
            }

            // 重试前中止上一次仍在进行中的请求
            if (currentXHR) {
                currentXHR.abort();
            }

            currentXHR = $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    // 忽略已完成后的延迟响应（防止重试竞态）
                    if (completed) return;

                    if (response.success) {
                        var data = response.data;

                        if (data.assembled) {
                            // 服务端已拼接完成
                            completed = true;
                            hideProgress('#lisun-doc-progress');
                            onComplete(data.result || {
                                filename: file.name,
                                status: 'success',
                                message: '上传完成'
                            });
                        } else {
                            // 分片已接收，继续下一片
                            currentChunkIndex++;
                            handleChunkError._retries = 0;  // 新分片，重置重试计数
                            sendNextChunk();
                        }
                    } else {
                        // 服务端返回错误（非网络错误，如格式不支持等）
                        // 此类错误通常不可重试成功，直接失败
                        completed = true;
                        hideProgress('#lisun-doc-progress');
                        var serverMsg = response.data && response.data.message ?
                            response.data.message : '服务端错误';
                        onComplete({
                            filename: file.name,
                            status: 'error',
                            message: serverMsg
                        });
                    }
                },
                error: function (jqXHR, textStatus) {
                    // 忽略 abort 产生的错误（由重试逻辑主动触发）
                    if (completed || textStatus === 'abort') return;

                    // 413 错误不重试（分片大小超过 nginx 限制）
                    if (jqXHR.status === 413) {
                        completed = true;
                        hideProgress('#lisun-doc-progress');
                        onComplete({
                            filename: file.name,
                            status: 'error',
                            message: '分片过大(413)，请减小 CHUNK_SIZE'
                        });
                        return;
                    }

                    handleChunkError('网络错误：' + textStatus);
                }
            });
        }

        function handleChunkError(errMsg) {
            // retryCount 在 sendNextChunk 中每进入新分片时重置，这里只递增
            // 但为保证重试不跨分片污染，每次重试前不调用 sendNextChunk 而直接发请求
            var retries = (handleChunkError._retries || 0) + 1;
            handleChunkError._retries = retries;

            if (retries <= MAX_CHUNK_RETRIES) {
                var delay = Math.pow(2, retries - 1) * 1000;
                console.warn(
                    '分片 ' + currentChunkIndex + '/' + totalChunks +
                    ' 失败（' + errMsg + '），' +
                    '重试 ' + retries + '/' + MAX_CHUNK_RETRIES + '，' +
                    delay + 'ms 后重试'
                );
                setTimeout(sendNextChunk, delay);
            } else {
                completed = true;
                hideProgress('#lisun-doc-progress');
                var finalMsg = '分片 ' + currentChunkIndex + ' 上传失败（已重试 ' + MAX_CHUNK_RETRIES + ' 次）：' + errMsg;
                onComplete({
                    filename: file.name,
                    status: 'error',
                    message: finalMsg
                });
            }
        }

        // 启动分片循环
        sendNextChunk();
    }

    /**
     * 渲染 Word 文档单个 chunk 的结果行
     */
    function appendDocChunkResults(results) {
        var html = '';
        results.forEach(function (r) {
            var icon, color;
            if (r.status === 'success') {
                icon = '&#10003;';
                color = 'green';
            } else if (r.status === 'skipped') {
                icon = '&ndash;';
                color = '#dba617';
            } else {
                icon = '&#10007;';
                color = '#d63638';
                FAILED_FILES.push({ filename: r.filename, message: r.message });
            }
            html += '<tr>';
            html += '<td><span style="color:' + color + ';font-weight:bold;">' + icon + '</span></td>';
            html += '<td>' + escapeHtml(r.filename) + '</td>';
            html += '<td style="color:' + color + ';">' + escapeHtml(r.message || r.status) + '</td>';
            html += '</tr>';
        });
        $('#lisun-doc-results-table tbody').append(html);
    }

    /**
     * Word 文档整批失败时标记该批所有文件
     */
    function appendDocChunkError(files, errorMsg) {
        var html = '';
        files.forEach(function (f) {
            html += '<tr>';
            html += '<td><span style="color:#d63638;font-weight:bold;">&#10007;</span></td>';
            html += '<td>' + escapeHtml(f.name || f.filename || '') + '</td>';
            html += '<td style="color:#d63638;">' + escapeHtml(errorMsg) + '</td>';
            html += '</tr>';
            FAILED_FILES.push({ file: f, message: errorMsg });
        });
        $('#lisun-doc-results-table tbody').append(html);
    }

    /**
     * Word 文档全部批次完成后显示摘要
     */
    function onDocUploadsComplete() {
        hideProgress('#lisun-doc-progress');

        var totalRows = $('#lisun-doc-results-table tbody tr').length;
        var failCount = FAILED_FILES.length;
        var successCount = totalRows - failCount;

        var summaryHtml = '<div class="lisun-upload-summary">';
        summaryHtml += '<p>上传完成：成功 ' + successCount + ' 个';
        if (failCount > 0) {
            summaryHtml += '，失败 ' + failCount + ' 个';
            summaryHtml += ' <button class="button" id="lisun-retry-failed-docs">重试失败的文件</button>';
        }
        summaryHtml += '</p></div>';
        $('#lisun-doc-results').prepend(summaryHtml);

        if (failCount > 0) {
            $('#lisun-retry-failed-docs').on('click', function () {
                retryFailedDocs();
            });
        } else {
            // 全部成功则刷新页面，让服务端渲染最新文件列表
            location.reload();
        }
    }

    /**
     * 重试失败的 Word 文件
     */
    function retryFailedDocs() {
        var retryFiles = [];
        FAILED_FILES.forEach(function (f) {
            if (f.file) {
                retryFiles.push(f.file);
            }
        });

        if (retryFiles.length === 0) {
            alert('无法重试：文件对象已释放。请重新选择文件。');
            return;
        }

        FAILED_FILES = [];
        $('#lisun-doc-results').empty();
        $('#lisun-doc-results-table tbody').empty();
        startDocChunkedUpload(retryFiles, false);
    }

    /**
     * 移除文件 — AJAX 删除服务端记录和磁盘文件，然后刷新
     */
    $(document).on('click', '.lisun-remove-doc', function () {
        var stored = $(this).closest('tr').data('stored');
        if (!confirm('确定移除该文件？')) return;

        $.post(ajaxUrl, {
            action: 'lisun_remove_uploaded_doc',
            stored_name: stored,
            _nonce: nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message || '移除失败');
            }
        });
    });

    // ========== 步骤 4：预览与导入 ==========

    // 全选
    $('#lisun-select-all').on('change', function () {
        $('#lisun-import-table tbody input[type="checkbox"]').prop('checked', this.checked);
    });

    // 预览文件
    $(document).on('click', '.lisun-preview-doc', function () {
        var storedName = $(this).data('stored');
        var defaultTitle = $(this).data('filename') || '';
        directReplaceStored = null;
        $('#lisun-replace-section').hide();
        $('#lisun-do-import').text('导入当前预览的这篇');
        // [修改时间：2026-11-05 11:49:15 +08:00] 单篇预览只选当前文件，防止默认全选时覆盖导入了另一份 DOCX。
        $('#lisun-import-table tbody input[type="checkbox"]').prop('checked', false);
        $(this).closest('tr').find('input[type="checkbox"]').prop('checked', true);
        previewDoc(storedName, defaultTitle);
    });

    // 一键全部导入（跳过预览）—— 逐个导入，实时进度
    $('#lisun-batch-import').on('click', function () {
        var checked = $('#lisun-import-table tbody input[type="checkbox"]:checked');
        if (checked.length === 0) {
            alert('没有可导入的文件，请先完成前面步骤');
            return;
        }

        if (!confirm('将一键导入全部 ' + checked.length + ' 篇说明书（跳过预览）。确定？')) return;

        var $btn = $(this).prop('disabled', true);
        var $spinner = $btn.next('.spinner').addClass('is-active');
        var $progress = $('#lisun-batch-progress');
        var $results = $('#lisun-import-results');
        $results.empty();

        // 模态遮罩层（防止误操作，显示当前导入状态）
        var $overlay = $('<div/>', {
            id: 'lisun-import-overlay',
            css: {
                display: 'none',
                position: 'fixed',
                top: 0, left: 0, right: 0, bottom: 0,
                background: 'rgba(255,255,255,0.85)',
                zIndex: 99990,
                textAlign: 'center',
                paddingTop: '15%'
            }
        });
        var $overlayBox = $('<div/>', {
            css: {
                display: 'inline-block',
                background: '#fff',
                border: '1px solid #ccd0d4',
                borderRadius: '8px',
                padding: '32px 48px',
                boxShadow: '0 4px 24px rgba(0,0,0,0.12)',
                minWidth: '360px'
            }
        });
        var $overlaySpinner = $('<span/>', { 'class': 'spinner is-active', css: { float: 'none', margin: '0 12px 0 0', verticalAlign: 'middle' } });
        var $overlayTitle = $('<p/>', { css: { fontSize: '15px', fontWeight: 600, margin: '0 0 8px', color: '#1d2327' } }).text('正在批量导入说明书');
        var $overlayFile = $('<p/>', { id: 'lisun-overlay-filename', css: { fontSize: '13px', color: '#646970', margin: '0 0 16px' } }).text('准备中...');
        var $overlayBarBg = $('<div/>', { css: { background: '#e0e0e0', borderRadius: '4px', height: '8px', marginBottom: '8px', overflow: 'hidden' } });
        var $overlayBar = $('<div/>', { id: 'lisun-overlay-bar', css: { background: '#2271b1', height: '100%', width: '0%', transition: 'width 0.3s' } });
        var $overlayStat = $('<p/>', { id: 'lisun-overlay-stat', css: { fontSize: '12px', color: '#8c8f94', margin: 0 } });
        var $overlayCancel = $('<button/>', {
            type: 'button', 'class': 'button',
            css: { marginTop: '16px' },
            text: '取消（已导入的会保留）',
            click: function () {
                cancelled = true;
                $overlayCancel.prop('disabled', true).text('正在停止...');
            }
        });

        $overlayBarBg.append($overlayBar);
        $overlayBox.append($overlaySpinner, $overlayTitle, $overlayFile, $overlayBarBg, $overlayStat, $overlayCancel);
        $overlay.append($overlayBox);
        $('body').append($overlay);
        $overlay.fadeIn(200);

        // 收集待导入文件
        var items = [];
        checked.each(function () {
            var $tr = $(this).closest('tr');
            items.push({
                storedName: $tr.data('stored'),
                filename: $tr.find('td:eq(1) strong').text(),
                $row: $tr
            });
        });

        var total = items.length;
        var done = 0;
        var ok = 0;
        var fail = 0;
        var cancelled = false;

        function updateProgress() {
            var pct = total > 0 ? Math.round((done / total) * 100) : 0;
            $overlayBar.css('width', pct + '%');
            $overlayStat.text(done + ' / ' + total + '（成功 ' + ok + '，失败 ' + fail + '）');
            $progress.text('正在导入 ' + done + '/' + total + '（成功 ' + ok + '，失败 ' + fail + '）...');
        }

        function processNext(index) {
            if (cancelled || index >= total) {
                // 全部完成或取消
                $overlay.fadeOut(200, function () { $overlay.remove(); });
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                if (cancelled) {
                    $progress.text('已取消。成功导入 ' + ok + ' 篇' + (fail > 0 ? '，失败 ' + fail + ' 篇' : '') + '，剩余 ' + (total - done) + ' 篇未处理。');
                } else {
                    $progress.text('导入完成：成功 ' + ok + ' 篇' + (fail > 0 ? '，失败 ' + fail + ' 篇' : ''));
                }
                if ($('#lisun-import-table tbody tr').length === 0) {
                    $('#lisun-import-table').hide();
                    $('#lisun-no-files-msg').show();
                }
                return;
            }

            var item = items[index];
            $overlayFile.text('正在处理：' + item.filename);
            updateProgress();

            function completeBatchItem(success, message, postData) {
                if (cancelled) return;
                if (success) {
                    ok++;
                    var title = (postData && (postData.post_title || postData.title)) || item.filename;
                    var editUrl = (postData && (postData.post_url || postData.edit_url)) || '';
                    var viewUrl = (postData && postData.view_url) || '';
                    $results.append(
                        '<div class="notice notice-success inline"><p>&#10003; <strong>' +
                        escapeHtml(title) + '</strong> ' +
                        (editUrl ? '<a href="' + editUrl + '">编辑</a>' : '') +
                        (viewUrl ? ' | <a href="' + viewUrl + '" target="_blank">查看</a>' : '') +
                        '</p></div>'
                    );
                    item.$row.fadeOut(300, function () { $(this).remove(); });
                } else {
                    fail++;
                    $results.append(
                        '<div class="notice notice-error inline"><p>&#10007; ' +
                        escapeHtml(item.filename) + ': ' + escapeHtml(message || '导入失败') +
                        '</p></div>'
                    );
                }
                done++;
                updateProgress();
                setTimeout(function () { processNext(index + 1); }, 150);
            }

            function pollBatchImportJob(jobId, pollCount) {
                if (cancelled) return;
                $.post(ajaxUrl, {
                    action: 'lisun_import_status',
                    job_id: jobId,
                    _nonce: nonce
                }, function (response) {
                    if (!response.success) {
                        completeBatchItem(false, (response.data && response.data.message) || '导入状态查询失败');
                        return;
                    }
                    var job = response.data || {};
                    // imported 表示中文文章已经创建，后续翻译在后台继续，不应阻塞批量导入统计。
                    if (job.status === 'imported' || job.status === 'done') {
                        completeBatchItem(true, '', job);
                    } else if (job.status === 'error') {
                        completeBatchItem(false, job.message || '导入失败');
                    } else if (pollCount >= 180) {
                        completeBatchItem(false, '后台任务超时，请检查文章列表；已创建的文章不会被删除');
                    } else {
                        setTimeout(function () { pollBatchImportJob(jobId, pollCount + 1); }, 2000);
                    }
                }).fail(function () {
                    if (pollCount >= 180) {
                        completeBatchItem(false, '导入状态查询超时，请检查文章列表');
                    } else {
                        setTimeout(function () { pollBatchImportJob(jobId, pollCount + 1); }, 2000);
                    }
                });
            }

            function fallbackToSyncImport() {
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'lisun_do_import',
                        stored_name: item.storedName,
                        post_title: '',
                        _nonce: nonce
                    },
                    timeout: 300000
                }).done(function (response) {
                    if (response.success) {
                        completeBatchItem(true, '', response.data || {});
                    } else {
                        completeBatchItem(false, (response.data && response.data.message) || '导入失败');
                    }
                }).fail(function (jqXHR, textStatus) {
                    completeBatchItem(false, textStatus === 'timeout'
                        ? '请求超时；请检查文章列表，文章可能已创建'
                        : '请求失败（超时或网络错误）');
                });
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lisun_do_import_async',
                    stored_name: item.storedName,
                    post_title: '',
                    _nonce: nonce
                },
                timeout: 10000
            }).done(function (response) {
                if (response.success && response.data && response.data.job_id) {
                    pollBatchImportJob(response.data.job_id, 0);
                } else {
                    fallbackToSyncImport();
                }
            }).fail(function () {
                // 老环境不支持异步接口时保留同步兜底。
                fallbackToSyncImport();
            });
        }

        processNext(0);
    });

    // [修改时间：2026-11-05 11:49:15 +08:00] 替换模式先查目标标题、链接和绑定，再允许单篇提交。
    var replaceTarget = null;
    var directReplaceStored = null;
    // [修改时间：2026-11-05 12:09:00 +08:00] 跳过解析预览，直接选文件并核对要替换的旧文章。
    $(document).on('click', '.lisun-replace-doc', function () {
        var row = $(this).closest('tr');
        directReplaceStored = String(row.data('stored'));
        replaceTarget = null;
        $('#lisun-import-table tbody input[type="checkbox"]').prop('checked', false);
        row.find('input[type="checkbox"]').prop('checked', true);
        $('#lisun-preview-section').hide();
        $('#lisun-preview-title-input').val('');
        $('#lisun-replace-source').text('将导入文件：' + row.find('td strong').text());
        $('#lisun-replace-section, #lisun-import-actions').show();
        $('#lisun-do-import').text('确认替换这篇说明书');
        $('#lisun-replace-target').empty();
    });
    $('#lisun-replace-post-id').on('input', function () { replaceTarget = null; $('#lisun-replace-target').empty(); });
    $('#lisun-check-replace-target').on('click', function () {
        var id = parseInt($('#lisun-replace-post-id').val(), 10) || 0;
        $('#lisun-replace-target').text('正在核对...');
        $.post(ajaxUrl, {action: 'lisun_replace_target', post_id: id, _nonce: nonce}, function (r) {
            if (!r.success) { replaceTarget = null; $('#lisun-replace-target').text(r.data.message || '核对失败'); return; }
            replaceTarget = r.data;
            $('#lisun-replace-target').empty().append($('<strong/>').text('目标：' + r.data.title + '（ID ' + r.data.id + '）'))
                .append($('<p/>').append($('<a/>', {href:r.data.url, target:'_blank', rel:'noopener'}).text(r.data.url)))
                .append($('<p/>').text('远程绑定 ID：' + (r.data.remote_id || '未绑定')));
        }).fail(function () { replaceTarget = null; $('#lisun-replace-target').text('核对请求失败'); });
    });

    // 执行导入（单篇预览后）— 异步版，避免大文件 524
    $('#lisun-do-import').on('click', function () {
        var checked = $('#lisun-import-table tbody input[type="checkbox"]:checked');
        if (checked.length === 0) {
            alert('请选择要导入的文件');
            return;
        }

        var $btn = $(this).prop('disabled', true);
        var $spinner = $btn.next('.spinner').addClass('is-active');
        var $results = $('#lisun-import-results');

        var storedName = directReplaceStored || checked.first().closest('tr').data('stored');
        var title = $('#lisun-preview-title-input').val();
        var row = directReplaceStored ? $('#lisun-import-table tbody tr').filter(function () { return String($(this).data('stored')) === directReplaceStored; }) : checked.first().closest('tr');

        var replacing = !!directReplaceStored;
        if (replacing && (!replaceTarget || replaceTarget.id !== (parseInt($('#lisun-replace-post-id').val(), 10) || 0))) {
            $btn.prop('disabled', false); $spinner.removeClass('is-active'); alert('请先核对要替换的旧文章 ID'); return;
        }
        if (replacing && !confirm('将用 ' + storedName + ' 的解析正文替换「' + replaceTarget.title + '」(ID ' + replaceTarget.id + ')。\n原链接：' + replaceTarget.url + '\n远程绑定 ID：' + (replaceTarget.remote_id || '未绑定') + '\n旧正文和 DOCX 将保存为可撤回快照。确认替换？')) {
            $btn.prop('disabled', false); $spinner.removeClass('is-active'); return;
        }
        var replaceData = replacing ? {replace_post_id: replaceTarget.id, replace_hash: replaceTarget.hash} : {};

        // 在结果区显示进度
        var $progressMsg = $('<div class="notice notice-info inline"><p>正在导入 ' + escapeHtml(storedName) + ' ...</p></div>');
        $results.append($progressMsg);

        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: $.extend({
                action: 'lisun_do_import_async',
                stored_name: storedName,
                post_title: title,
                _nonce: nonce
            }, replaceData),
            timeout: 10000
        }).done(function (response) {
            if (response.success && response.data && response.data.job_id) {
                pollImportJob(response.data.job_id, row, $btn, $spinner, $progressMsg, $results);
            } else {
                // 降级到同步导入
                if (replacing) {
                    $progressMsg.remove(); $btn.prop('disabled', false); $spinner.removeClass('is-active');
                    alert((response.data && response.data.message) || '覆盖导入未启动，请重新核对目标。');
                    return;
                }
                $progressMsg.find('p').text('异步不可用，使用同步导入（大文件可能较慢）...');
                $.post(ajaxUrl, $.extend({
                    action: 'lisun_do_import',
                    stored_name: storedName,
                    post_title: title,
                    _nonce: nonce
                }, replaceData), function (syncResp) {
                    handleImportResult(syncResp, row, $btn, $spinner, $progressMsg, $results);
                }).fail(function () {
                    $progressMsg.remove();
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    alert('导入失败: 请求超时');
                });
            }
        }).fail(function () {
            $progressMsg.remove();
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            alert('导入失败: 无法连接到服务器');
        });
    });

    /**
     * 轮询导入进度
     */
    function pollImportJob(jobId, row, $btn, $spinner, $progressMsg, $results) {
        var pollCount = 0;
        var maxPolls = 150;

        function poll() {
            pollCount++;
            $.post(ajaxUrl, {
                action: 'lisun_import_status',
                job_id: jobId,
                _nonce: nonce
            }, function (response) {
                if (!response.success) {
                    $progressMsg.remove();
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    alert('导入失败: ' + (response.data && response.data.message || '未知错误'));
                    return;
                }

                var job = response.data;
                if (job.status === 'done') {
                    // 导入+翻译全部完成
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $progressMsg.remove();
                    var statusText = lisun_manual.i18n.import_success;
                    if (job.error) {
                        statusText += '（翻译失败: ' + escapeHtml(job.error) + '）';
                    }
                    var msg = '<div class="notice notice-success inline"><p>' +
                        statusText +
                        (job.post_url ? ' <a href="' + job.post_url + '">编辑</a>' : '') +
                        '</p></div>';
                    $results.append(msg);

                    row.fadeOut(300, function () {
                        $(this).remove();
                        if ($('#lisun-import-table tbody tr').length === 0) {
                            $('#lisun-import-table').hide();
                            $('#lisun-no-files-msg').show();
                            $('#lisun-preview-section').hide();
                            $('#lisun-import-actions').hide();
                        }
                    });
                } else if (job.status === 'imported') {
                    // 中文导入完成，正在翻译
                    if (job.translating) {
                        $progressMsg.find('p').text('已导入，正在翻译+推送远程站... (' + Math.round(pollCount * 2) + 's)');
                    } else {
                        // 无需翻译，直接完成
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                        $progressMsg.remove();
                        var doneMsg = '<div class="notice notice-success inline"><p>' +
                            lisun_manual.i18n.import_success +
                            (job.post_url ? ' <a href="' + job.post_url + '">编辑</a>' : '') +
                            '</p></div>';
                        $results.append(doneMsg);
                        row.fadeOut(300, function () { $(this).remove(); });
                        return;
                    }
                    setTimeout(poll, 2000);
                } else if (job.status === 'error') {
                    $progressMsg.remove();
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $results.append('<div class="notice notice-error inline"><p>' +
                        escapeHtml(job.message || lisun_manual.i18n.import_failed) + '</p></div>');
                } else if (pollCount >= maxPolls) {
                    $progressMsg.remove();
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $results.append('<div class="notice notice-error inline"><p>导入超时: 请检查文档是否过大</p></div>');
                } else {
                    $progressMsg.find('p').text('正在导入... (' + Math.round(pollCount * 2) + 's)');
                    setTimeout(poll, 2000);
                }
            }).fail(function () {
                if (pollCount >= maxPolls) {
                    $progressMsg.remove();
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $results.append('<div class="notice notice-error inline"><p>导入超时: 请检查服务器状态后重试</p></div>');
                } else {
                    setTimeout(poll, 2000);
                }
            });
        }

        poll();
    }

    /**
     * 处理导入结果（同步和异步统一入口）
     */
    function handleImportResult(response, row, $btn, $spinner, $progressMsg, $results) {
        $btn.prop('disabled', false);
        $spinner.removeClass('is-active');
        $progressMsg.remove();
        if (response.success) {
            var msg = '<div class="notice notice-success inline"><p>' +
                lisun_manual.i18n.import_success +
                (response.data.edit_url ? ' <a href="' + response.data.edit_url + '">编辑</a>' : '') +
                '</p></div>';
            $results.append(msg);

            row.fadeOut(300, function () {
                $(this).remove();
                if ($('#lisun-import-table tbody tr').length === 0) {
                    $('#lisun-import-table').hide();
                    $('#lisun-no-files-msg').show();
                    $('#lisun-preview-section').hide();
                    $('#lisun-import-actions').hide();
                }
            });
        } else {
            $results.append('<div class="notice notice-error inline"><p>' +
                escapeHtml(response.data.message || lisun_manual.i18n.import_failed) + '</p></div>');
        }
    }

    /**
     * 预览文档解析结果
     */
    function previewDoc(storedName, defaultTitle) {
        var $progress = $('#lisun-doc-progress');
        if (!$progress.length) {
            $progress = $('#lisun-img-progress');
        }

        // 尝试异步解析（fastcgi_finish_request 可用时后台处理 + 轮询）
        showProgress($progress, '正在解析文档...');
        var $previewBtn = $('.lisun-preview-doc[data-stored="' + storedName + '"]').prop('disabled', true);

        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'lisun_parse_doc_async',
                stored_name: storedName,
                _nonce: nonce
            },
            timeout: 10000,  // 异步启动只需 10s，实际解析在后台
            success: function (response) {
                if (response.success && response.data && response.data.job_id) {
                    // 异步模式：开始轮询
                    pollParseJob(response.data.job_id, defaultTitle, $progress, $previewBtn);
                } else {
                    // 降级：可能是同步返回（fastcgi_finish_request 不可用）
                    handleParseResult(response, defaultTitle, $progress, $previewBtn);
                }
            },
            error: function (xhr, status) {
                // 异步端点失败，降级到同步端点
                hideProgress($progress);
                showProgress($progress, '异步不可用，使用同步解析（大文件可能较慢）...');
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'lisun_parse_doc',
                        stored_name: storedName,
                        _nonce: nonce
                    },
                    timeout: 180000
                }).done(function (syncResponse) {
                    handleParseResult(syncResponse, defaultTitle, $progress, $previewBtn);
                }).fail(function () {
                    hideProgress($progress);
                    $previewBtn.prop('disabled', false);
                    alert('预览失败: 解析超时，请尝试直接导入');
                });
            }
        });
    }

    /**
     * 轮询异步解析进度
     */
    function pollParseJob(jobId, defaultTitle, $progress, $previewBtn) {
        var pollCount = 0;
        var maxPolls = 150;  // 最多轮询 5 分钟（每 2s 一次）

        showProgress($progress, '正在解析文档...');

        function poll() {
            pollCount++;
            $.post(ajaxUrl, {
                action: 'lisun_parse_status',
                job_id: jobId,
                _nonce: nonce
            }, function (response) {
                if (!response.success) {
                    hideProgress($progress);
                    $previewBtn.prop('disabled', false);
                    alert('解析失败: ' + (response.data && response.data.message || '未知错误'));
                    return;
                }

                var job = response.data;
                if (job.status === 'done') {
                    hideProgress($progress);
                    $previewBtn.prop('disabled', false);
                    // 构造和同步端点一致的返回格式
                    handleParseResult(
                        { success: true, data: job.result },
                        defaultTitle,
                        $progress,
                        $previewBtn
                    );
                } else if (job.status === 'error') {
                    hideProgress($progress);
                    $previewBtn.prop('disabled', false);
                    alert('解析失败: ' + (job.message || '未知错误'));
                } else if (pollCount >= maxPolls) {
                    hideProgress($progress);
                    $previewBtn.prop('disabled', false);
                    alert('解析超时: 文档可能过大，请尝试直接导入');
                } else {
                    // 更新进度提示
                    var elapsed = Math.round((Date.now() / 1000) - (job.started_at ? new Date(job.started_at).getTime() / 1000 : 0));
                    showProgress($progress, '正在解析文档... (' + elapsed + 's)');
                    setTimeout(poll, 2000);
                }
            }).fail(function () {
                if (pollCount >= maxPolls) {
                    hideProgress($progress);
                    $previewBtn.prop('disabled', false);
                    alert('解析超时: 请检查服务器状态后重试');
                } else {
                    setTimeout(poll, 2000);
                }
            });
        }

        poll();
    }

    /**
     * 处理解析结果（同步和异步统一入口）
     */
    function handleParseResult(response, defaultTitle, $progress, $previewBtn) {
        if (response.success) {
            $('#lisun-preview-title-input').val(response.data.title || defaultTitle || '');
            $('#lisun-preview-content').html(
                '<div class="lisun-preview-rendered">' +
                response.data.blocks.join('\n') +
                '</div>'
            );
            $('#lisun-preview-section').show();
            $('#lisun-import-actions').show();
        } else {
            alert('解析失败: ' + (response.data && response.data.message || '未知错误'));
        }
        $previewBtn.prop('disabled', false);
    }

    // ========== 工具函数 ==========

    function showProgress(selector, text) {
        $(selector).show().find('.lisun-progress-text').text(text);
    }

    function hideProgress(selector) {
        $(selector).hide();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})(jQuery);
