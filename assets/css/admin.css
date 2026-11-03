/**
 * LISUN Manual — 后台样式
 */

/* ========== 导入步骤指示器 ========== */
.lisun-steps {
    display: flex;
    align-items: center;
    margin: 0 0 30px;
    padding: 15px 0;
    border-bottom: 2px solid #ddd;
    gap: 0;
}

.lisun-step {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 20px;
    margin: 0;
    text-decoration: none;
    color: #999;
    font-size: 13px;
    position: relative;
    transition: color 0.2s;
}

.lisun-step:hover {
    color: #2271b1;
}

.lisun-step::after {
    content: "→";
    margin-left: 20px;
    color: #ccc;
    font-size: 16px;
}

.lisun-step:last-child::after {
    display: none;
}

.lisun-step-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #ddd;
    color: #666;
    font-weight: bold;
    font-size: 14px;
    flex-shrink: 0;
}

.lisun-step-current .lisun-step-num {
    background: #2271b1;
    color: #fff;
}

.lisun-step-current {
    color: #2271b1;
    font-weight: 600;
}

.lisun-step-done .lisun-step-num {
    background: #00a32a;
    color: #fff;
}

.lisun-step-done {
    color: #00a32a;
}

/* ========== 拖拽上传区 ========== */
.lisun-drop-zone {
    border: 3px dashed #ccc;
    border-radius: 8px;
    padding: 10px;
    margin: 15px 0;
    transition: border-color 0.2s, background 0.2s;
}

.lisun-drop-zone.lisun-dragover {
    border-color: #2271b1;
    background: #f0f6fc;
}

.lisun-drop-inner {
    text-align: center;
    padding: 40px 20px;
}

.lisun-drop-inner .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #999;
    margin-bottom: 10px;
}

.lisun-drop-inner p {
    margin: 5px 0;
    color: #666;
    font-size: 14px;
}

.lisun-drop-or {
    color: #999 !important;
    font-size: 13px !important;
    margin: 15px 0 !important;
}

.lisun-drop-inner .button {
    margin: 5px 8px;
}

/* ========== 进度条 ========== */
.lisun-progress {
    margin: 15px 0;
    padding: 10px;
    background: #f9f9f9;
    border-radius: 4px;
}

.lisun-progress-bar {
    height: 6px;
    background: #e5e5e5;
    border-radius: 3px;
    overflow: hidden;
}

.lisun-progress-fill {
    height: 100%;
    width: 100%;
    background: #2271b1;
    border-radius: 3px;
    animation: lisun-progress-anim 1.5s ease-in-out infinite;
}

@keyframes lisun-progress-anim {
    0% { width: 0%; }
    50% { width: 70%; }
    100% { width: 100%; }
}

.lisun-progress-text {
    margin: 5px 0 0;
    color: #666;
    font-size: 12px;
}

/* ========== 上传结果表格 ========== */
#lisun-upload-results-table {
    margin-top: 10px;
}

#lisun-upload-results-table td:first-child {
    text-align: center;
    width: 40px;
}

.lisun-upload-summary {
    padding: 10px 12px;
    background: #f0f6fc;
    border-left: 4px solid #2271b1;
    margin: 15px 0;
}

.lisun-upload-summary p {
    margin: 0;
    font-weight: 600;
}

.lisun-existing-prompt ul {
    margin: 8px 0 12px 20px;
    list-style: disc;
    color: #555;
    max-height: 150px;
    overflow-y: auto;
}

.lisun-existing-prompt .button {
    margin-right: 8px;
}

/* ========== 导航按钮 ========== */
.lisun-nav-buttons {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #eee;
}

/* ========== 预览区 ========== */
.lisun-preview-box {
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
    max-height: 500px;
    overflow-y: auto;
}

.lisun-preview-title {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    background: #f9f9f9;
}

.lisun-preview-title input {
    width: 100%;
    max-width: 500px;
}

.lisun-preview-content {
    padding: 20px;
}

.lisun-preview-rendered {
    line-height: 1.8;
}

/* ========== 导入步骤通用 ========== */
.lisun-import-step h2 {
    margin-bottom: 5px;
}

.lisun-import-step .description {
    color: #666;
    margin-bottom: 20px;
}

/* ========== 术语库/翻译管理/客户列表 通用表格 ========== */
.lisun-table-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 15px 0;
    flex-wrap: wrap;
    gap: 10px;
}

.lisun-table-actions .lisun-search {
    display: flex;
    gap: 5px;
}

.lisun-table-actions .lisun-search input {
    width: 250px;
}

.lisun-table-actions .lisun-actions-right {
    display: flex;
    gap: 5px;
}

.lisun-csv-preview {
    display: none;
    margin: 15px 0;
    max-height: 300px;
    overflow-y: auto;
}

/* ========== 行内编辑 ========== */
.lisun-cell-zh input,
.lisun-cell-en input,
.lisun-cell-note input {
    width: 100%;
    max-width: 250px;
    margin: 0;
}

.lisun-edit-btns {
    white-space: nowrap;
}

/* ========== 翻译状态标签 ========== */
.lisun-status-success { color: #00a32a; font-weight: bold; }
.lisun-status-failed { color: #d63638; font-weight: bold; }
.lisun-status-pending { color: #dba617; font-weight: bold; }
.lisun-status-translating { color: #2271b1; animation: lisun-pulse 1s infinite; }

@keyframes lisun-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* ========== 系统设置 ========== */
.lisun-settings-section {
    margin-bottom: 25px;
    padding: 15px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.lisun-settings-section h3 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

/* ========== 访问日志 ========== */
.lisun-access-logs {
    max-height: 400px;
    overflow-y: auto;
}

/* ========== 表单表格居中 ========== */
.form-table th {
    text-align: center !important;
}

/* ========== 响应式 ========== */
@media (max-width: 782px) {
    .lisun-steps {
        flex-wrap: wrap;
    }

    .lisun-step::after {
        display: none;
    }

    .lisun-nav-buttons {
        flex-direction: column;
        gap: 10px;
    }

    .lisun-table-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .lisun-table-actions .lisun-search input {
        width: 100%;
    }
}
