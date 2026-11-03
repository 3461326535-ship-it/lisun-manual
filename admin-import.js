/**
 * LISUN Manual — 前台说明书页面样式
 * 用于 single-manual.php 和 archive-manual
 */

/* ========== 双栏布局：TOC + 正文 ========== */
.lisun-manual-wrap {
    display: flex;
    max-width: 1300px;
    margin: 0 auto;
    padding: 0 20px;
    gap: 40px;
    align-items: flex-start;
    overflow-x: hidden;
    font-family: "Microsoft YaHei", "PingFang SC", "Helvetica Neue", sans-serif;
    box-sizing: border-box;
}

/* ========== 侧边栏容器 ========== */
.lisun-manual-sidebar {
    width: 250px;
    flex-shrink: 0;
    position: -webkit-sticky;
    position: sticky;
    top: 130px;
    align-self: flex-start;
    transition: none;
    /* height 由 JS 动态计算（视口 - 侧边栏距顶距离） */
    display: flex;
    flex-direction: column;
}
/* 顶部留白 5% */
.lisun-sidebar-top {
    flex: 5;
    min-height: 0;
}
/* 底部留白 5% */
.lisun-sidebar-bottom {
    flex: 5;
    min-height: 0;
}

/*
 * 修复 sticky 失效：确保所有祖先没有 overflow 裁剪
 * sticky 在 overflow:hidden/auto/scroll/clip 的容器内完全不工作
 * 注意：只在 Y 轴设为 visible，X 轴仍允许 hidden 防止横向溢出
 */
.lisun-manual-wrap {
    overflow-y: visible !important;
}

/* ========== 侧边 TOC（90%） ========== */
.lisun-manual-toc {
    flex: 90;
    overflow-y: auto;
    scroll-behavior: smooth;
    background: #fafbfc;
    border: 1px solid #e1e4e8;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.6;
    min-height: 0;  /* flex 子元素允许收缩 */
}

.lisun-toc-inner {
    padding: 16px;
}

.lisun-toc-title {
    font-weight: 700;
    font-size: 14px;
    color: #222;
    margin-bottom: 12px;
    padding: 8px 16px 8px 16px;
    border-bottom: 1px solid #e1e4e8;
    position: sticky;
    top: 0;
    background: #fafbfc;
    z-index: 2;
    margin-left: -16px;
    margin-right: -16px;
}

.lisun-toc-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.lisun-toc-item {
    margin: 2px 0;
}

.lisun-toc-item a {
    display: block;
    padding: 4px 8px;
    color: #555;
    text-decoration: none;
    border-radius: 4px;
    border-left: 2px solid transparent;
    transition: all 0.15s;
}

.lisun-toc-item a:hover {
    color: #2271b1;
    background: #f0f6fc;
    border-left-color: #2271b1;
}

.lisun-toc-item a.lisun-toc-active {
    color: #2271b1;
    font-weight: 600;
    background: #eef4fa;
    border-left-color: #2271b1;
}

/* TOC 级别缩进 — 绝对级别，与 push TOC 一致 */
.lisun-toc-lv1 a {
    font-weight: 600;
}
.lisun-toc-lv2 {
    padding-left: 16px;
}
.lisun-toc-lv3 {
    padding-left: 32px;
}
.lisun-toc-lv3 a {
    font-size: 13px;
}
.lisun-toc-lv4 {
    padding-left: 48px;
}
.lisun-toc-lv4 a {
    font-size: 12px;
}
.lisun-toc-lv5 {
    padding-left: 56px;
}
.lisun-toc-lv5 a {
    font-size: 11px;
}

/* ========== 正文区域 ========== */
.lisun-manual-single {
    flex: 1;
    min-width: 0;
    padding: 20px 0;
}

.lisun-manual-single header {
    margin-bottom: 30px;
    text-align: center;
}

.lisun-manual-single header h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.lisun-manual-single header img {
    max-height: 400px;
    object-fit: contain;
    border-radius: 8px;
}

.lisun-manual-content {
    max-width: 1000px;
    line-height: 1.6;
    font-size: 13px !important;
    color: #333;
}

/* ====== H1~H5 标题美化（普通 h 标签 + 自动编号标题统一） ====== */

/* H1：章标题 — 渐变背景 + 左蓝条 */
.lisun-manual-content h1,
.lisun-manual-content .lisun-num-lv1 {
    font-size: 24px;
    font-weight: 600;
    line-height: 1.4;
    color: #333;
    background: linear-gradient(to right, #e0eafc, #cfdef3);
    padding: 14px 20px;
    border-left: 6px solid #4a90e2;
    margin: 20px 0 10px;
}

/* 标题内的 b/strong 继承标题字号，避免外部样式把嵌套文字缩小。 */
.lisun-manual-content h1 b, .lisun-manual-content h1 strong,
.lisun-manual-content h2 b, .lisun-manual-content h2 strong,
.lisun-manual-content h3 b, .lisun-manual-content h3 strong,
.lisun-manual-content h4 b, .lisun-manual-content h4 strong,
.lisun-manual-content h5 b, .lisun-manual-content h5 strong,
.lisun-manual-content h6 b, .lisun-manual-content h6 strong,
.lisun-manual-content .lisun-num-heading b,
.lisun-manual-content .lisun-num-heading strong,
.lisun-manual-content h1 span[data-lisun-term-id],
.lisun-manual-content h2 span[data-lisun-term-id],
.lisun-manual-content h3 span[data-lisun-term-id],
.lisun-manual-content h4 span[data-lisun-term-id],
.lisun-manual-content h5 span[data-lisun-term-id],
.lisun-manual-content h6 span[data-lisun-term-id],
.lisun-manual-content .lisun-num-heading span[data-lisun-term-id] {
    font-size: inherit !important;
    font-weight: inherit;
    line-height: inherit;
}

/* H2：节标题 */
.lisun-manual-content h2,
.lisun-manual-content .lisun-num-lv2 {
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: #333;
    margin: 14px 0 8px;
}
/* H2 / lv2 的蓝色胶囊徽章 */
.lisun-manual-content .lisun-num-lv2 .lisun-num-badge {
    display: inline-block;
    background-color: #4a90e2;
    color: #fff;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    margin-right: 8px;
    vertical-align: middle;
    font-family: "Microsoft YaHei", sans-serif;
}

/* H3：小节标题 — 蓝色文字 + 底部细线 */
.lisun-manual-content h3,
.lisun-manual-content .lisun-num-lv3 {
    font-size: 17px;
    font-weight: 600;
    line-height: 1.4;
    color: #4a90e2;
    margin: 10px 0 6px;
    display: block;
    border-bottom: 2px solid rgba(74, 144, 226, 0.3);
    padding-bottom: 4px;
}

/* H4：浅蓝变体 */
.lisun-manual-content h4,
.lisun-manual-content .lisun-num-lv4 {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.4;
    color: #5a9fd4;
    margin: 7px 0;
    display: block;
    border-bottom: 1.5px solid rgba(74, 144, 226, 0.2);
    padding-bottom: 3px;
}

/* H5：保留基础样式 */
.lisun-manual-content h5 {
    font-size: 15px;
    font-weight: 600;
    color: #333;
    margin: 0.75em 0 0.25em;
}

/* 编号标题通用 */
.lisun-manual-content .lisun-num-heading {
    font-weight: 600;
}

.lisun-manual-content p {
    margin: 0.8em 0;
    font-size: 13px !important;
    line-height: 1.6;
}

/* 标签徽章 */
.lisun-manual-tags {
    margin: -8px 0 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.lisun-tag-badge {
    display: inline-block;
    background: #e8f0fe;
    color: #4a90e2;
    font-size: 13px;
    padding: 3px 12px;
    border-radius: 20px;
    text-decoration: none;
    transition: background 0.2s;
}
.lisun-tag-badge:hover {
    background: #d0e3fc;
    color: #2a6fc9;
}

/* 列表：去掉多余符号（编号已内嵌在文字中） */
.lisun-manual-content ul,
.lisun-manual-content ol {
    margin: 0.8em 0;
    padding-left: 0;
    list-style: none;
}

.lisun-manual-content li {
    margin: 0.3em 0;
    font-size: 13px !important;
}

/* ========== 表格（参考 product 页 .rich_text table 风格） ========== */
.lisun-manual-content table {
    width: 100%;
    border-collapse: collapse;
    margin: 1.2em 0;
    font-size: 13px;
    letter-spacing: 0.2px;
    border: 1px solid #ccc;
    table-layout: auto;
    word-break: normal;
}

.lisun-manual-content th,
.lisun-manual-content td {
    border: 1px solid #eaeaea;
    padding: 8px 12px;
    text-align: left;
    vertical-align: middle;
    font-size: 13px !important;
    /* overflow-wrap 只在单词过长时断词，不像 break-all 那样把正常词也拆开 */
    overflow-wrap: break-word;
    word-break: normal;
    /* 防止内容撑开固定列宽 */
    overflow: hidden;
}

.lisun-manual-content .lisun-manual-table th,
.lisun-manual-content .lisun-manual-table td {
    text-align: left;
}

/* 表格内长链接 / 长代码不撑破布局 */
.lisun-manual-content td a,
.lisun-manual-content th a {
    word-break: break-all;
}

/* 窄列（< 6% 或内容少）给最小宽度，避免挤成一根线 */
.lisun-manual-content th[style*="width"],
.lisun-manual-content td[style*="width"] {
    min-width: 60px;
}

.lisun-manual-content thead th,
.lisun-manual-content thead td {
    background: #e8edf2;
    font-weight: 700;
    color: #222;
    border-bottom: 2px solid #bbb;
}
/* 合并单元格去掉底线，让旁边的非合并格接上，避免蓝线错位 */
.lisun-manual-content thead th[rowspan],
.lisun-manual-content thead td[rowspan] {
    border-bottom: none;
}

.lisun-manual-content tbody tr:nth-child(even) {
    background: #f8f9fa;
}

.lisun-manual-content tbody tr:hover {
    background: #eef4fa;
}

.lisun-manual-content td img,
.lisun-manual-content th img {
    max-width: 200px;
    height: auto;
    display: inline-block;
    vertical-align: middle;
}

/* ========== 图片 ========== */
.lisun-manual-content img {
    max-width: 100%;
    height: auto;
    /* 图片加载中显示浅灰背景，减少白屏感 */
    background: #f3f4f6;
    /* 避免图片加载前的 CLS（layout shift）—— 由 width/height 属性精确控制 */
    /* 渐进式加载效果 */
    transition: opacity 0.3s ease;
}
/*
 * 渐进式加载增强：仅在浏览器原生懒加载触发后做淡入。
 * 默认不隐藏图片 —— opacity:0 有风险：如果 JS 失败或 CDN 改动 DOM，
 * 图片会永久不可见（用户看到的就是"图片不显示"）。
 * 改用 background 做加载占位（已在上面 .lisun-manual-content img 中设置），
 * 淡入效果只作为视觉增强，不作为可见性的必要条件。
 */
.lisun-manual-content img.lisun-loading {
    opacity: 0;
    transition: opacity 0.3s ease;
}
.lisun-manual-content img.lisun-loaded {
    opacity: 1;
}

/* 图片框：Gutenberg figure + JS 生成的 lisun-manual-figure
 * content-visibility: auto 让浏览器跳过屏幕外图片的渲染和加载，
 * 图片多的长页面大幅减少初始网络请求和渲染开销 */
.lisun-manual-content figure,
.lisun-manual-content .wp-block-image,
.lisun-manual-content .lisun-manual-figure,
.lisun-manual-content .wp-caption {
    margin: 1.8em auto !important;
    padding: 12px !important;
    background: #fafbfc !important;
    border: 1px solid #d0d4d8 !important;
    border-radius: 8px !important;
    text-align: center !important;
    max-width: 100%;
    box-sizing: border-box;
}

.lisun-manual-content figure img,
.lisun-manual-content .wp-block-image img,
.lisun-manual-content .lisun-manual-figure img,
.lisun-manual-content .wp-caption img {
    display: block !important;
    margin: 0 auto !important;
    border-radius: 4px;
}

.lisun-manual-content figcaption,
.lisun-manual-content .manual-caption,
.lisun-manual-content .wp-caption-text {
    display: block !important;
    margin-top: 10px !important;
    font-size: 13px !important;
    color: #888 !important;
    text-align: center !important;
}

/* "图n" 段落：旧版兼容 */
.lisun-manual-content p.lisun-figcaption,
.lisun-manual-content .manual-caption {
    margin-top: -1em;
    font-size: 13px;
    color: #888;
    text-align: center;
}

/* 表格内图片不加框 */
.lisun-manual-content td img,
.lisun-manual-content th img,
.lisun-manual-content td figure,
.lisun-manual-content th figure {
    border: none !important;
    padding: 0 !important;
    background: none !important;
    box-shadow: none !important;
    display: inline !important;
}

/* 表格不再用 content-visibility：contain:size 会让移动端表格单元格宽度异常（TD 比 Table 短一截） */

/* ========== 链接 ========== */
.lisun-manual-content a {
    color: #2271b1;
    text-decoration: none;
}

.lisun-manual-content a:hover {
    text-decoration: underline;
}

/* 原文链接 */
.lisun-manual-source {
    margin-top: 30px;
    padding: 10px 15px;
    background: #f0f6fc;
    border-radius: 4px;
    font-size: 14px;
}

.lisun-manual-source a {
    color: #2271b1;
}

/* ========== 归档页 — TOC 列表布局 ========== */
.lisun-archive-toc-wrap {
    display: flex;
    flex-direction: column;
    height: 100vh;
    max-width: 900px;
    margin: 0 auto;
}

/* 顶部留白 5% */
.lisun-archive-toc-top {
    flex: 5;
    min-height: 0;
}

/* TOC 列表 90% */
.lisun-archive-toc {
    flex: 90;
    overflow-y: auto;
    min-height: 0;
}

/* 底部留白 5% */
.lisun-archive-toc-bottom {
    flex: 5;
    min-height: 0;
}

.lisun-archive-toc-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.lisun-archive-toc-item {
    border-bottom: 1px solid #eee;
}

.lisun-archive-toc-item:first-child {
    border-top: 1px solid #eee;
}

.lisun-archive-toc-item a {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    text-decoration: none;
    color: #333;
    transition: background 0.15s, color 0.15s;
}

.lisun-archive-toc-item a:hover {
    background: #f0f6fc;
    color: #2271b1;
}

.lisun-archive-toc-title {
    font-size: 15px;
    font-weight: 500;
}

.lisun-archive-toc-lang {
    flex-shrink: 0;
    margin-left: 16px;
    font-size: 12px;
    color: #888;
    background: #f0f0f0;
    padding: 2px 8px;
    border-radius: 3px;
}

/* ========== 表格工具栏（独立一行，对齐产品页） ========== */
.table-wrapper {
    position: relative;
    margin: 20px 0;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    background: #fff;
    overflow: hidden;
}

.table-toolbar {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #e0e0e0;
}

.table-toolbar button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 12px;
    font-size: 13px;
    color: #555;
    background: #fff;
    border: 1px solid #d0d0d0;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s ease;
    line-height: 1.4;
}

.table-toolbar button:hover {
    background: #f0f0f0;
    border-color: #bbb;
    color: #333;
}

.table-toolbar button:active {
    background: #e8e8e8;
}

.table-toolbar button svg {
    width: 14px;
    height: 14px;
    fill: currentColor;
    flex-shrink: 0;
}

.table-scroll-container {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #c0c0c0 #f0f0f0;
}

.table-scroll-container::-webkit-scrollbar {
    height: 8px;
}
.table-scroll-container::-webkit-scrollbar-track {
    background: #f0f0f0;
    border-radius: 4px;
}
.table-scroll-container::-webkit-scrollbar-thumb {
    background: #c0c0c0;
    border-radius: 4px;
}
.table-scroll-container::-webkit-scrollbar-thumb:hover {
    background: #a0a0a0;
}

/* — wrapper 内表格样式（覆盖 .lisun-manual-content table 的默认边框/斑马纹） — */
.lisun-manual-content .table-scroll-container table,
.lisun-manual-content .table-scroll-container .lisun-manual-table {
    border: 1px solid #e8e8e8;
    table-layout: auto;
    margin: 0;
}

.lisun-manual-content .table-scroll-container th,
.lisun-manual-content .table-scroll-container td {
    border: none;
    border-bottom: 1px solid #e8e8e8;
    border-right: 1px solid #e8e8e8;
    overflow: visible;
    text-align: left;
    vertical-align: middle;
}
.table-scroll-container table {
    width: 100%;
    min-width: 100%;
    border-collapse: collapse;
    border-spacing: 0;
    font-size: 13px;
    line-height: 1.5;
    margin: 0;
}

.table-scroll-container table thead {
    background: #f5f7fa;
}

.table-scroll-container table th,
.table-scroll-container table td {
    padding: 8px 10px;
    text-align: left;
    border-bottom: 1px solid #e8e8e8;
    border-right: 1px solid #e8e8e8;
    white-space: normal;
    /* 正常英文单词保持完整，仅在空格处换行；超长连续字符串仍可折行 */
    word-break: normal;
    overflow-wrap: break-word;
    vertical-align: middle;
}
.lisun-manual-content .table-scroll-container thead th {
    background: #f5f7fa;
    font-weight: 600;
    color: #333;
    border-bottom: 2px solid #0088cc;
}

.table-scroll-container table th {
    font-weight: 600;
    color: #333;
    border-bottom: 2px solid #0088cc;
    position: sticky;
    top: 0;
    background: #f5f7fa;
    z-index: 10;
}

.table-scroll-container table tbody tr:hover {
    background: #f8fbfd;
}

.table-scroll-container table tbody tr:last-child td {
    border-bottom: none;
}

.lisun-manual-content .table-scroll-container tbody tr:nth-child(even) {
    background: transparent;
}

.lisun-manual-content .table-scroll-container tbody tr:hover {
    background: #f8fbfd;
}

.lisun-manual-content .table-scroll-container tbody tr:last-child td {
    border-bottom: none;
}

/* Fullscreen */
.table-wrapper:fullscreen,
.table-wrapper:-webkit-full-screen,
.table-wrapper:-moz-full-screen,
.table-wrapper:-ms-fullscreen {
    width: 100vw !important;
    height: 100vh !important;
    border-radius: 0;
    display: flex;
    flex-direction: column;
    background: #fff;
    padding: 0;
}

.table-wrapper:fullscreen .table-toolbar,
.table-wrapper:-webkit-full-screen .table-toolbar,
.table-wrapper:-moz-full-screen .table-toolbar,
.table-wrapper:-ms-fullscreen .table-toolbar {
    border-radius: 0;
    padding: 12px 20px;
    flex-shrink: 0;
}

.table-wrapper:fullscreen .table-scroll-container,
.table-wrapper:-webkit-full-screen .table-scroll-container,
.table-wrapper:-moz-full-screen .table-scroll-container,
.table-wrapper:-ms-fullscreen .table-scroll-container {
    flex: 1;
    overflow: auto;
    max-height: calc(100vh - 50px);
}

.table-wrapper:fullscreen .table-scroll-container table,
.table-wrapper:-webkit-full-screen .table-scroll-container table {
    min-width: auto;
    width: 100%;
}

.table-wrapper:fullscreen .table-toolbar button[data-action="fullscreen"],
.table-wrapper:-webkit-full-screen .table-toolbar button[data-action="fullscreen"] {
    background: #0088cc;
    color: #fff;
    border-color: #0088cc;
}

/* ========== 响应式 ========== */
@media (max-width: 900px) {
    .lisun-manual-wrap {
        flex-direction: column;
        gap: 20px;
        max-width: 100%;
        padding: 0 16px;
    }

    .lisun-manual-sidebar {
        display: none;
    }

    .lisun-manual-single {
        padding: 10px 0;
        min-width: 0;
        max-width: 100%;
    }

    .lisun-manual-content {
        max-width: 100%;
    }

    /* 表格由 .table-scroll-container 承载横向滚动；这里只让单元格文字正常换行，
       不再用 display:block + white-space:nowrap（那会把每格文字压成一行，表格过宽） */
    .lisun-manual-content table {
        word-break: break-word;
        overflow-wrap: break-word;
    }
}

@media (max-width: 768px) {
    .lisun-manual-wrap {
        padding: 0 12px;
    }

    /* 移动端放大正文字号（覆盖 13px !important），提升可读性 */
    .lisun-manual-content {
        font-size: 15px !important;
        line-height: 1.7;
    }
    .lisun-manual-content p,
    .lisun-manual-content li,
    .lisun-manual-content th,
    .lisun-manual-content td {
        font-size: 15px !important;
        line-height: 1.7;
    }
    .lisun-manual-content table {
        font-size: 14px;
    }

    .lisun-manual-content h1,
    .lisun-manual-content .lisun-num-lv1 { font-size: 20px; padding: 10px 14px; }
    .lisun-manual-content h2,
    .lisun-manual-content .lisun-num-lv2 { font-size: 18px; }
    .lisun-manual-content .lisun-num-lv2 .lisun-num-badge { font-size: 13px; padding: 1px 8px; }
    .lisun-manual-content h3,
    .lisun-manual-content .lisun-num-lv3 { font-size: 16px; }
    .lisun-manual-content h4,
    .lisun-manual-content .lisun-num-lv4 { font-size: 15px; }

    .lisun-manual-content th,
    .lisun-manual-content td {
        padding: 6px 8px;
    }

    /* 修复真机移动端「TD 比 Table 短一截」：
       单元格会继承 Word 写死的 style="width:X%"，这些宽度加起来往往不等于 100%，
       iOS Safari 会按写死宽度渲染导致行尾留空。这里让单元格宽度交给浏览器自适应。 */
    .lisun-manual-content table {
        width: 100% !important;
        table-layout: auto !important;
    }
    .lisun-manual-content th,
    .lisun-manual-content td {
        width: auto !important;
        min-width: 0 !important;
    }

    .lisun-manual-single header h1 {
        font-size: 24px;
    }

/* 修复 AI Chat 插件造成的页脚下方多余空白
   #lisun-chat-root 为 position:relative 且在文档流中，
   移动端 .chat-panel 被设为 position:relative+固定高度，撑出多余空间 */
.lisun-manual-single #lisun-chat-root,
.single-manual #lisun-chat-root {
    position: fixed !important;
    top: 0;
    left: 0;
    width: 0;
    height: 0;
    pointer-events: none;
    overflow: visible;
}
.lisun-manual-single #lisun-chat-root > *,
.single-manual #lisun-chat-root > * {
    pointer-events: auto;
}

    .lisun-manual-content figure,
    .lisun-manual-content .wp-block-image,
    .lisun-manual-content .lisun-manual-figure {
        margin: 1em 0 !important;
        padding: 8px !important;
    }
}
