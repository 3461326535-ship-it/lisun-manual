<?php
/**
 * 说明书单页模板 — 独立 HTML 结构（参考 single-cases-old.php）
 * 含侧边 TOC、图片边框、响应式布局、视频/软件下载
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!doctype html>
<html lang="<?php echo get_locale() == 'zh_CN' ? 'zh-CN' : 'en'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,Chrome=1">
    <link rel="shortcut icon" href="<?php echo esc_url(get_template_directory_uri()); ?>/img/favicon.ico">
    <title><?php if(function_exists('get_field') && get_field('title')){the_field('title');}else{wp_title('');} echo ' - ' . get_bloginfo('name'); ?></title>
    <meta name="description" content="<?php echo function_exists('get_field') ? get_field('description') : ''; ?>">
    <meta name="keywords" content="<?php echo function_exists('get_field') ? get_field('keywords') : ''; ?>">
    <link rel="stylesheet" href="<?php echo esc_url(get_template_directory_uri()); ?>/css/cases-s.css" type="text/css" media="all" />
    <script type="text/javascript" src="<?php echo esc_url(get_template_directory_uri()); ?>/js/jquery-1.9.1.min.js"></script>
    <?php wp_head(); ?>

    <!-- 下载区域样式 —— 内联，不依赖外部 CSS -->
    <style>
    /* ========== 型号显示 ========== */
    .lisun-manual-model {
        font-size: 14px;
        color: #6b7280;
        margin: 4px 0 16px;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }

    /* 文章头部标题 */
    .lisun-manual-single article header h1 {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 8px;
        line-height: 1.3;
    }

    /* H1 下方的副标题（如"操作使用说明书"） */
    .lisun-manual-subtitle {
        font-size: 20px;
        color: #6b7280;
        margin: 0 0 12px;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }

    /* 2026-11-09 副标题行 + PDF 下载按钮（屏幕端；打印由 front.css 整行隐藏） */
    .lisun-subtitle-row {
        position: relative;
        margin: 0 0 12px;
        /* 两侧等量留白：副标题继续由主题的 text-align:center 居中，
           右侧按钮绝对定位、不挤压文本居中；留白只用于避免长标题压到按钮 */
        padding: 0 100px;
    }
    .lisun-subtitle-row .lisun-manual-subtitle {
        margin: 0;
    }
    .lisun-pdf-btn {
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex: none;
        padding: 8px 14px;
        font-size: 13px;
        line-height: 1;
        color: #0088cc;
        background: #fff;
        border: 1px solid #0088cc;
        border-radius: 6px;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color .15s ease, color .15s ease;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }
    .lisun-pdf-btn:hover {
        background: #0088cc;
        color: #fff;
    }
    .lisun-pdf-btn svg {
        width: 14px;
        height: 14px;
        fill: currentColor;
        flex: none;
    }
    /* 2026-11-09 下载状态反馈：加载中禁点、成功绿、失败红 */
    .lisun-pdf-btn.is-loading {
        opacity: .75;
        pointer-events: none; /* 生成期间彻底禁点，杜绝重复触发生成 */
    }
    .lisun-pdf-btn svg.lisun-pdf-spin {
        fill: none;
        animation: lisun-pdf-rotate .9s linear infinite;
    }
    @keyframes lisun-pdf-rotate {
        to { transform: rotate(360deg); }
    }
    .lisun-pdf-btn.is-done {
        color: #1e9e5b;
        border-color: #1e9e5b;
    }
    .lisun-pdf-btn.is-error {
        color: #d9534f;
        border-color: #d9534f;
    }
    /* 窄屏：取消两侧留白，按钮换行居中（继承 text-align，inline-flex 会居中） */
    @media (max-width: 720px) {
        .lisun-subtitle-row {
            padding: 0;
        }
        .lisun-pdf-btn {
            position: static;
            transform: none;
            margin-top: 8px;
        }
    }

    /* ========== 下载区域 ========== */
    .lisun-manual-downloads {
        margin-top: 48px;
        padding-top: 32px;
        border-top: 1px solid #e5e7eb;
    }

    /* 2026-11-09 附录：屏幕端隐藏二维码（打印/PDF 显示，规则见 front.css @media print）；
       软件链接沿用网页按钮本体，PDF 内可点击，不再另行输出明文地址 */
    .lisun-video-qr {
        display: none;
    }

    /* 主标题 —— 附录 */
    .lisun-dl-section-title {
        background: linear-gradient(135deg, #e8f0fe 0%, #d4e4fc 100%);
        border-left: 4px solid #2563eb;
        padding: 14px 22px;
        margin-bottom: 24px;
        border-radius: 0 8px 8px 0;
        font-size: 17px;
        font-weight: 600;
        color: #1e40af;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }

    /* 子标题 —— 罗马数字徽章 */
    /* 2026-11-09 改为 h2 以进入 PDF 书签；显式覆盖主题对 h2 的默认装饰 */
    .lisun-dl-sub-title {
        display: flex;
        align-items: center;
        border: 0;
        padding: 0;
        background: none;
        line-height: 1.4;
        gap: 10px;
        margin: 28px 0 16px;
        font-size: 15px;
        font-weight: 600;
        color: #1f2937;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }
    .lisun-dl-sub-title::before {
        content: attr(data-num);
        min-width: 32px;
        height: 32px;
        padding: 0 6px;
        background: #2563eb;
        color: #fff;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 700;
        flex-shrink: 0;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }

    /* 视频卡片 */
    .lisun-video-cards {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .lisun-video-card {
        display: block;
        width: 100%;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        overflow: hidden;
        cursor: pointer;
        transition: box-shadow 0.25s, transform 0.25s;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }
    .lisun-video-card:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        transform: translateY(-2px);
    }
    .lisun-video-thumb {
        position: relative;
        width: 100%;
        aspect-ratio: 16/9;
        background: #1f2937;
        overflow: hidden;
    }
    .lisun-video-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: 0.85;
        transition: opacity 0.3s;
    }
    .lisun-video-card:hover .lisun-video-thumb img {
        opacity: 1;
    }
    .lisun-play-btn {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 52px;
        height: 52px;
        background: rgba(255,255,255,0.95);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        pointer-events: none;
    }
    .lisun-play-btn svg {
        width: 22px;
        height: 22px;
        color: #dc2626;
        margin-left: 3px;
    }
    .lisun-video-info {
        padding: 14px 16px;
    }
    .lisun-video-name {
        font-size: 15px;
        font-weight: 500;
        color: #111827;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }

    /* iframe 播放器 */
    .lisun-video-iframe {
        width: 100%;
        aspect-ratio: 16/9;
        border: none;
        display: block;
    }

    /* 软件列表 */
    .lisun-software-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .lisun-software-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 16px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }
    .lisun-software-icon {
        width: 38px;
        height: 38px;
        background: #eff6ff;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .lisun-software-icon svg {
        width: 18px;
        height: 18px;
        color: #2563eb;
    }
    .lisun-software-name {
        flex: 1;
        font-size: 14px;
        font-weight: 500;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
        color: #374151;
    }
    .lisun-software-dl-btns {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
    }
    .lisun-software-dl-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 12px;
        font-size: 12px;
        color: #2563eb;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .lisun-software-dl-btn:hover {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
    }
    .lisun-software-dl-btn svg {
        width: 14px;
        height: 14px;
        flex-shrink: 0;
    }
    .lisun-download-icon {
        width: 18px;
        height: 18px;
        color: #9ca3af;
        flex-shrink: 0;
        transition: color 0.2s;
    }
    .lisun-software-item:hover .lisun-download-icon {
        color: #2563eb;
    }

    /* ========== 多级编号标题 ========== */
    /* 兼容已有原生 H 标签（非 JS 转换）的文章 */
    html,
    body {
        -webkit-text-size-adjust: 100%;
        text-size-adjust: 100%;
    }

    .lisun-manual-content {
        font-size: 13px;
        line-height: 1.6;
        font-family: -apple-system, BlinkMacSystemFont, "PingFang SC",
            "Helvetica Neue", Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
    }
    .lisun-manual-content ul {
        list-style: disc;
        padding-left: 1.5em;
        margin: 0.5em 0;
    }
    .lisun-manual-content ul li {
        padding-bottom: 2px;
        line-height: 1.6;
        font-size: 13px;
    }
    .lisun-manual-content ol {
        list-style: decimal;
        padding-left: 1.5em;
        margin: 0.5em 0;
    }
    .lisun-manual-content ol li {
        padding-bottom: 2px;
        line-height: 1.6;
        font-size: 13px;
    }
    .lisun-manual-content h1 {
        font-size: 24px;
        font-weight: 600;
        margin-top: 0.8em;
        margin-bottom: 0.4em;
        line-height: 1.4;
        color: #111827;
    }
    .lisun-manual-content h2 {
        font-size: 20px;
        font-weight: 600;
        margin-top: 0.8em;
        margin-bottom: 0.3em;
        line-height: 1.4;
        color: #1f2937;
    }
    .lisun-manual-content h3 {
        font-size: 17px;
        font-weight: 600;
        margin-top: 0.7em;
        margin-bottom: 0.2em;
        line-height: 1.4;
        color: #374151;
    }
    .lisun-manual-content h4 {
        font-size: 15px;
        font-weight: 600;
        margin-top: 0.55em;
        margin-bottom: 0.15em;
        line-height: 1.4;
        color: #4b5563;
    }

    /* ========== 多级编号标题 —— 数据库已转换为原生 H 标签 ========== */
    .lisun-manual-content h2.lisun-num-heading,
    .lisun-manual-content h3.lisun-num-heading,
    .lisun-manual-content h4.lisun-num-heading,
    .lisun-manual-content h5.lisun-num-heading {
        font-weight: 600;
        line-height: 1.5;
    }

    /* 紧接在标题后的第一个段落去掉上边距，保持紧凑 */
    .lisun-manual-content .lisun-num-heading + p {
        margin-top: 0 !important;
    }

    /* lv1 —— 一级章节 "1、xxx" (h2, 左边条+渐变背景，醒目) */
    .lisun-manual-content h2.lisun-num-lv1 {
        font-size: 24px;
        line-height: 1.4;
        margin-top: 1em;
        margin-bottom: 0.4em;
        color: #111827;
        background: linear-gradient(90deg, #eff6ff 0%, transparent 100%);
        border-left: 4px solid #2563eb;
        padding: 8px 0 8px 14px;
        border-radius: 0 4px 4px 0;
    }
    /* lv2 —— 二级章节 "1.1 xxx" (h3) */
    .lisun-manual-content h3.lisun-num-lv2 {
        font-size: 20px;
        line-height: 1.4;
        margin-top: 0.7em;
        margin-bottom: 0.25em;
        color: #1f2937;
    }
    /* lv3 —— 三级章节 "1.1.1 xxx" (h4, 下划线) */
    .lisun-manual-content h4.lisun-num-lv3 {
        font-size: 17px;
        line-height: 1.4;
        margin-top: 0.55em;
        margin-bottom: 0.15em;
        padding-bottom: 4px;
        color: #374151;
        border-bottom: 1px solid #d1d5db;
    }
    /* lv4 —— 四级章节 "1.1.1.1 xxx" (h5, 下划线) */
    .lisun-manual-content h5.lisun-num-lv4 {
        font-size: 15px;
        line-height: 1.4;
        margin-top: 0.4em;
        margin-bottom: 0.1em;
        padding-bottom: 3px;
        color: #4b5563;
        border-bottom: 1px dashed #d1d5db;
    }

    /* 编号胶囊（用于 JS 转换的深度>=2 标题，数据库已有原生 h 标签不用此徽章） */
    .lisun-num-badge {
        display: inline-block;
        background: #2563eb;
        color: #fff;
        font-size: 0.8em;
        padding: 2px 8px;
        border-radius: 4px;
        margin-right: 6px;
        vertical-align: middle;
    }

    /* 响应式 */
    @media (max-width: 640px) {
    }

    /* =============================================
       表格增强样式 — 对齐产品页
       注意：必须覆盖 front.css 和 class-sync.php 中的
       .lisun-manual-content table 样式，确保 table-wrapper
       内的表格与产品页完全一致（无 !important 冲突）
    ============================================= */

    /* — 覆盖 front.css / sync CSS：wrapper 内的表格不要旧样式 — */
    .lisun-manual-content .table-scroll-container table,
    .lisun-manual-content .table-scroll-container .lisun-manual-table {
        border: none !important;
        table-layout: auto !important;
        margin: 0 !important;
    }

    /* 表格高度由内容自然撑开，不接受编辑器保存的固定 table/tr/cell 高度。 */
    .lisun-manual-content table,
    .lisun-manual-content table tr,
    .lisun-manual-content table th,
    .lisun-manual-content table td {
        height: auto !important;
    }

    /* 型号/编号表格：英文译文可能更长，首列允许换行，避免覆盖相邻列。 */
    .lisun-manual-content table.lisun-model-number-table,
    .lisun-manual-content table.lisun-two-column-table,
    .lisun-manual-content table.lisun-auto-column-table {
        table-layout: fixed !important;
        width: 100% !important;
        min-width: 100% !important;
        max-width: none !important;
    }
    .lisun-manual-content .table-scroll-container table.lisun-model-number-table,
    .lisun-manual-content .table-scroll-container .lisun-manual-table.lisun-model-number-table,
    .lisun-manual-content .table-scroll-container table.lisun-two-column-table,
    .lisun-manual-content .table-scroll-container .lisun-manual-table.lisun-two-column-table,
    .lisun-manual-content .table-scroll-container table.lisun-auto-column-table,
    .lisun-manual-content .table-scroll-container .lisun-manual-table.lisun-auto-column-table {
        table-layout: fixed !important;
        width: 100% !important;
        min-width: 100% !important;
        max-width: none !important;
    }
    .lisun-manual-content table.lisun-model-number-table > tr > [data-lisun-column="0"],
    .lisun-manual-content table.lisun-model-number-table > * > tr > [data-lisun-column="0"],
    .lisun-manual-content table.lisun-two-column-table > tr > [data-lisun-column="0"],
    .lisun-manual-content table.lisun-two-column-table > * > tr > [data-lisun-column="0"],
    .lisun-manual-content table.lisun-auto-column-table > tr > [data-lisun-column="0"],
    .lisun-manual-content table.lisun-auto-column-table > * > tr > [data-lisun-column="0"] {
        width: var(--lisun-first-column-width, 120px) !important;
        min-width: var(--lisun-first-column-width, 120px) !important;
        max-width: none !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: normal !important;
    }
    .lisun-manual-content table.lisun-model-number-table > tr > [data-lisun-column]:not([data-lisun-column="0"]),
    .lisun-manual-content table.lisun-model-number-table > * > tr > [data-lisun-column]:not([data-lisun-column="0"]),
    .lisun-manual-content table.lisun-two-column-table > tr > [data-lisun-column]:not([data-lisun-column="0"]),
    .lisun-manual-content table.lisun-two-column-table > * > tr > [data-lisun-column]:not([data-lisun-column="0"]),
    .lisun-manual-content table.lisun-auto-column-table > tr > [data-lisun-column]:not([data-lisun-column="0"]),
    .lisun-manual-content table.lisun-auto-column-table > * > tr > [data-lisun-column]:not([data-lisun-column="0"]) {
        width: var(--lisun-rest-column-width, auto) !important;
        min-width: 0 !important;
        max-width: none !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
    }

    /* 复杂表格必须放在这段模板内联 CSS 的最后覆盖旧的列宽规则，
       否则 .lisun-auto-column-table 会再次把前三列压窄。 */
    .lisun-manual-content table.lisun-complex-table,
    .lisun-manual-content .table-scroll-container table.lisun-complex-table {
        table-layout: fixed !important;
        width: 100% !important;
        min-width: 100% !important;
        max-width: none !important;
    }
    .lisun-manual-content table.lisun-complex-table th,
    .lisun-manual-content table.lisun-complex-table td,
    .lisun-manual-content table.lisun-auto-column-table.lisun-complex-table > tr > [data-lisun-column],
    .lisun-manual-content table.lisun-auto-column-table.lisun-complex-table > * > tr > [data-lisun-column] {
        width: auto !important;
        min-width: 0 !important;
        max-width: none !important;
    }
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

    .lisun-manual-content .table-scroll-container th,
    .lisun-manual-content .table-scroll-container td {
        border: none !important;
        border-bottom: 1px solid #e8e8e8 !important;
        border-right: 1px solid #e8e8e8 !important;
        overflow: visible !important;
        text-align: left !important;
        vertical-align: middle !important;
        font-size: 13px !important;
        line-height: 1.5 !important;
    }
    /* 表格优先适配内容区并换行，不使用横向滚动条。 */
    .lisun-manual-content .table-scroll-container {
        --lisun-table-min-width: 0;
        --lisun-table-max-width: 100%;
        overflow-x: hidden !important;
    }
    .lisun-manual-content .table-scroll-container table,
    .lisun-manual-content .table-scroll-container .lisun-manual-table {
        width: 100% !important;
        min-width: var(--lisun-table-min-width) !important;
        max-width: var(--lisun-table-max-width) !important;
        table-layout: fixed !important;
    }
    .lisun-manual-content .table-scroll-container table th,
    .lisun-manual-content .table-scroll-container table td {
        min-width: 0 !important;
        max-width: 100% !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: break-word !important;
    }
    /* 单元格已有 padding，重置内部 p 的正文默认上下 margin，避免留白叠加。 */
    .lisun-manual-content .table-scroll-container th > p,
    .lisun-manual-content .table-scroll-container td > p,
    .lisun-manual-content .table-scroll-container th p,
    .lisun-manual-content .table-scroll-container td p {
        margin: 0 !important;
        padding: 0 !important;
    }
    .lisun-manual-content .table-scroll-container th > ul,
    .lisun-manual-content .table-scroll-container td > ul,
    .lisun-manual-content .table-scroll-container th > ol,
    .lisun-manual-content .table-scroll-container td > ol {
        margin: 0 !important;
    }
    .lisun-manual-content .table-scroll-container thead th {
        background: #f5f7fa !important;
        font-weight: 600 !important;
        color: #333 !important;
    }
    .lisun-manual-content .table-scroll-container thead th[rowspan],
    .lisun-manual-content .table-scroll-container thead td[rowspan] {
        border-bottom: none !important;
    }
    .lisun-manual-content .table-scroll-container tbody tr:nth-child(even) {
        background: transparent !important;
    }
    .lisun-manual-content .table-scroll-container tbody tr:hover {
        background: #f8fbfd !important;
    }
    .lisun-manual-content .table-scroll-container tbody tr:last-child td {
        border-bottom: none !important;
    }

    /* 表格内不应有标题 — 修复被误转换的数据（如 0.15 等测量值） */
    .lisun-manual-content td h1, .lisun-manual-content td h2,
    .lisun-manual-content td h3, .lisun-manual-content td h4,
    .lisun-manual-content td h5, .lisun-manual-content th h1,
    .lisun-manual-content th h2, .lisun-manual-content th h3,
    .lisun-manual-content th h4, .lisun-manual-content th h5,
    .lisun-manual-content .table-scroll-container td h1,
    .lisun-manual-content .table-scroll-container td h2,
    .lisun-manual-content .table-scroll-container td h3,
    .lisun-manual-content .table-scroll-container td h4,
    .lisun-manual-content .table-scroll-container td h5,
    .lisun-manual-content .table-scroll-container th h1,
    .lisun-manual-content .table-scroll-container th h2,
    .lisun-manual-content .table-scroll-container th h3,
    .lisun-manual-content .table-scroll-container th h4,
    .lisun-manual-content .table-scroll-container th h5 {
        font-size: inherit !important;
        font-weight: inherit !important;
        color: inherit !important;
        background: none !important;
        border: none !important;
        border-bottom: none !important;
        border-left: none !important;
        margin: 0 !important;
        padding: 0 !important;
        display: inline !important;
    }

    .copy-toast {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 10px 18px;
        background: #28a745;
        color: #fff;
        border-radius: 4px;
        font-size: 14px;
        z-index: 100000;
        opacity: 0;
        transform: translateY(-10px);
        transition: all 0.3s ease;
        pointer-events: none;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .copy-toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    /* 移动端表格适配 */
    @media (max-width: 767px) {
        .table-toolbar {
            padding: 6px 8px;
            gap: 6px;
        }
        .table-toolbar button {
            padding: 4px 8px;
            font-size: 12px;
        }
        .table-toolbar button svg {
            width: 12px;
            height: 12px;
        }

        .table-scroll-container {
            width: 100% !important;
            overflow-x: auto !important;
            overflow-y: auto !important;
            max-width: 100% !important;
            overscroll-behavior-x: contain !important;
            scrollbar-width: thin !important;
            scrollbar-color: #d0d0d0 #f5f5f5 !important;
            scrollbar-gutter: stable !important;
            -ms-overflow-style: scrollbar !important;
            -webkit-overflow-scrolling: touch !important;
        }
        .table-scroll-container::-webkit-scrollbar {
            height: 8px !important;
            display: block !important;
        }
        .table-scroll-container::-webkit-scrollbar-track {
            background: #f5f5f5 !important;
        }
        .table-scroll-container::-webkit-scrollbar-thumb {
            background: #d0d0d0 !important;
            border-radius: 4px !important;
            min-height: 30px !important;
        }
        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #b0b0b0 !important;
        }
        .table-scroll-container table {
            table-layout: auto !important;
            width: max-content !important;
            min-width: 100% !important;
            box-sizing: border-box !important;
        }
        .table-scroll-container table th,
        .table-scroll-container table td {
            padding: 4px 6px !important;
            font-size: 13px !important;
            line-height: 1.4 !important;
            white-space: normal !important;
            /* 英文按空格换行，不把 Number、Method 等正常单词拆开 */
            overflow-wrap: normal !important;
            word-break: normal !important;
            hyphens: none !important;
            overflow: visible !important;
            box-sizing: border-box !important;
            vertical-align: top !important;
            min-width: 80px !important;
            max-width: 320px !important;
        }

        /* 型号/编号表和普通两列表格在手机上不要继承桌面端首列像素宽度，避免右列被压成竖栏。 */
        .lisun-manual-content .table-scroll-container table.lisun-model-number-table,
        .lisun-manual-content .table-scroll-container table.lisun-two-column-table,
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table {
            table-layout: fixed !important;
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
        }
        .lisun-manual-content .table-scroll-container table.lisun-model-number-table > tr > [data-lisun-column="0"],
        .lisun-manual-content .table-scroll-container table.lisun-model-number-table > * > tr > [data-lisun-column="0"],
        .lisun-manual-content .table-scroll-container table.lisun-two-column-table > tr > [data-lisun-column="0"],
        .lisun-manual-content .table-scroll-container table.lisun-two-column-table > * > tr > [data-lisun-column="0"],
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table > tr > [data-lisun-column="0"],
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table > * > tr > [data-lisun-column="0"] {
            width: min(var(--lisun-first-column-width, 120px), 45vw) !important;
            min-width: 0 !important;
            max-width: 45vw !important;
        }
        .lisun-manual-content .table-scroll-container table.lisun-model-number-table > tr > [data-lisun-column]:not([data-lisun-column="0"]),
        .lisun-manual-content .table-scroll-container table.lisun-model-number-table > * > tr > [data-lisun-column]:not([data-lisun-column="0"]),
        .lisun-manual-content .table-scroll-container table.lisun-two-column-table > tr > [data-lisun-column]:not([data-lisun-column="0"]),
        .lisun-manual-content .table-scroll-container table.lisun-two-column-table > * > tr > [data-lisun-column]:not([data-lisun-column="0"]),
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table > tr > [data-lisun-column]:not([data-lisun-column="0"]),
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table > * > tr > [data-lisun-column]:not([data-lisun-column="0"]) {
            width: calc((100% - min(var(--lisun-first-column-width, 120px), 45vw)) / var(--lisun-table-rest-count, 1)) !important;
            min-width: 0 !important;
            max-width: none !important;
        }
        .lisun-manual-content .table-scroll-container table.lisun-complex-table,
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table.lisun-complex-table {
            table-layout: fixed !important;
            width: 100% !important;
            min-width: 100% !important;
            max-width: none !important;
        }
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table.lisun-complex-table > tr > [data-lisun-column],
        .lisun-manual-content .table-scroll-container table.lisun-auto-column-table.lisun-complex-table > * > tr > [data-lisun-column] {
            width: auto !important;
            min-width: 0 !important;
            max-width: none !important;
        }
    }

    /* ========== 侧边栏目录层级缩进 ========== */
    .lisun-toc-list { list-style: none; padding: 0; margin: 0; }
    .lisun-toc-item { line-height: 1.6; }
    .lisun-toc-item a { text-decoration: none; color: #374151; font-size: 13px; }
    .lisun-toc-item a:hover { color: #2563eb; }
    .lisun-toc-lv1 { padding-left: 0; font-weight: 600; }
    .lisun-toc-lv1 a { font-size: 14px; color: #111827; }
    .lisun-toc-lv2 { padding-left: 14px; }
    .lisun-toc-lv3 { padding-left: 28px; }
    .lisun-toc-lv4 { padding-left: 42px; font-size: 12px; }
    .lisun-toc-lv5 { padding-left: 56px; font-size: 11px; }
    </style>
</head>
<body>
    <?php
    if (file_exists(get_template_directory() . '/nav.php')) {
        include get_template_directory() . '/nav.php';
    }
    ?>

    <!-- 面包屑 -->
    <style>
    .breadcrumb_info .content_wrap { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .breadcrumb_info .content_wrap > *:not(:last-child)::after { content:">"; margin-left:10px; color:#999; }
    </style>
    <?php
    // 前台界面文字必须优先服从「系统设置 → 页面语言」。
    // 远程站历史页面可能没有 _lisun_manual_lang 元数据；此前这里直接
    // 单篇文章优先使用自身语言，避免英文译文页面被全局中文设置覆盖。
    // 没有文章语言元数据时，再兼容旧文章并使用全局语言设置。
    $post_lang = get_post_meta(get_the_ID(), '_lisun_manual_lang', true);
    $site_lang = get_option('lisun_page_lang', '');
    $interface_lang = in_array($post_lang, ['zh', 'en'], true)
        ? $post_lang
        : (in_array($site_lang, ['zh', 'en'], true) ? $site_lang : 'zh');
    $is_en = ($interface_lang === 'en');

    /* 2026-11-09 页眉标题文字（打印 / PDF 用）：按页面语言切换。
       版式基准与字号推导见 assets/css/front.css 顶部注释。
       英文 20.7pt 对应 5.52mm 帽高；中文按视觉等重降到 18pt（墨高约 6.27mm）。*/
    printf(
        '<style>@page{@top-left{content:"%s";font-size:%s;padding-top:%s}}</style>',
        $is_en ? "User's Manual" : '操作使用说明书',
        $is_en ? '20.7pt' : '18pt',
        $is_en ? '13.84mm' : '14.13mm'
    );
    ?>
    <div class="out_wrap breadcrumb_info">
        <div class="content_wrap">
            <a class="fa" href="/"><?php echo $is_en ? 'Home' : '首页'; ?></a>
            <a href="<?php echo esc_url(get_post_type_archive_link('manual')); ?>"><?php echo $is_en ? 'Manual' : '说明书'; ?></a>
            <span><?php the_title(); ?></span>
            <div class="clearfix"></div>
        </div>
    </div>

    <!-- 正文+侧边栏容器 -->
    <div class="lisun-print-header" aria-hidden="true">
        <div class="lisun-print-brand">
            <img src="<?php echo esc_url(defined('LISUN_MANUAL_URL') ? LISUN_MANUAL_URL . 'LISUN-LOGO_Original.jpg' : ''); ?>" alt="LISUN GROUP">
            <span class="lisun-print-brand-divider"></span>
            <span class="lisun-print-brand-copy">Testing Instruments &amp; Solutions<br>for EMC, Lighting, Electrical Safety and Environmental</span>
            <span class="lisun-print-doc-type">TECHNICAL DOCUMENTATION</span>
        </div>
        <div class="lisun-print-rule"></div>
    </div>

    <div class="lisun-manual-wrap">
        <!-- 侧边栏 -->
        <div class="lisun-manual-sidebar">
            <div class="lisun-sidebar-top"></div>
            <aside class="lisun-manual-toc" id="lisun-toc">
                <div class="lisun-toc-inner">
                    <div class="lisun-toc-title"><?php echo $is_en ? 'Contents' : '目录'; ?></div>
                    <nav class="lisun-toc-nav" id="lisun-toc-nav"></nav>
                </div>
            </aside>
            <div class="lisun-sidebar-bottom"></div>
        </div>

        <!-- 正文区域 -->
        <main class="lisun-manual-single">
            <article>
                <?php while (have_posts()): the_post();
                    // 标题即为产品名，副标题根据文章语言自动确定
                    $raw_title = get_the_title();
                    $default_subtitle = $is_en ? "User's Manual" : '操作使用说明书';
                    $subtitle = $default_subtitle;
                    $clean_title = $raw_title;

                    // 兼容旧数据：如果标题末尾恰好带有后缀，剥离后用原标题
                    $suffixes = ['操作使用说明书', '使用说明书', '操作使用说明',
                                  'Operation Manual', 'operation manual', "User's Manual", 'User Manual', 'Instruction Manual'];
                    foreach ($suffixes as $suffix) {
                        $suffix_len = mb_strlen($suffix);
                        if (mb_substr($raw_title, -$suffix_len) === $suffix) {
                            $clean_title = mb_substr($raw_title, 0, -$suffix_len);
                            break;
                        }
                    }
                ?>

                <header>
                    <h1><?php echo esc_html($clean_title); ?></h1>
                    <?php /* 2026-11-09 副标题行右侧加「下载 PDF」按钮；打印时整行隐藏（保留空行），见 front.css */ ?>
                    <div class="lisun-subtitle-row">
                        <p class="lisun-manual-subtitle"><?php echo esc_html($subtitle); ?></p>
                        <a class="lisun-pdf-btn"
                           href="<?php echo esc_url(add_query_arg('lisun_pdf', '1', get_permalink())); ?>"
                           download
                           data-filename="<?php echo esc_attr(sanitize_file_name(get_the_title() . ' - ' . ($is_en ? "User's Manual" : '操作使用说明书') . '.pdf')); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                            <?php echo $is_en ? 'Download PDF' : '下载 PDF'; ?>
                        </a>
                    </div>
                    <?php /* 2026-11-09 下载防重复点击：点击即进入加载态，fetch 接管，成功/失败给状态 */ ?>
                    <script>
                    (function () {
                        var btn = document.querySelector('.lisun-pdf-btn');
                        if (!btn || btn.dataset.lisunPdfBound) return;
                        btn.dataset.lisunPdfBound = '1';
                        var isEn = <?php echo $is_en ? 'true' : 'false'; ?>;
                        var origHTML = btn.innerHTML;
                        var busy = false;

                        var ICON_SPIN = '<svg class="lisun-pdf-spin" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="42" stroke-dashoffset="14" stroke-linecap="round"/></svg>';
                        var ICON_OK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>';
                        var ICON_WARN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>';

                        function setState(html, cls, text, restoreMs) {
                            btn.className = btn.className.replace(/\bis-(loading|done|error)\b/g, ' ').replace(/\s+/g, ' ').trim();
                            if (cls) btn.classList.add(cls);
                            btn.innerHTML = html + '<span>' + text + '</span>';
                            if (restoreMs) {
                                setTimeout(function () {
                                    btn.classList.remove(cls);
                                    btn.innerHTML = origHTML;
                                    busy = false;
                                }, restoreMs);
                            }
                        }

                        btn.addEventListener('click', function (e) {
                            if (busy) { e.preventDefault(); return; }
                            busy = true;
                            e.preventDefault();
                            setState(ICON_SPIN, 'is-loading', isEn ? 'Generating…' : '生成中…');

                            fetch(btn.href, { credentials: 'same-origin' })
                                .then(function (r) {
                                    var ct = (r.headers.get('content-type') || '').toLowerCase();
                                    if (!r.ok || ct.indexOf('pdf') === -1) {
                                        // 回落到登录页 HTML：多半是会话过期/门禁未过
                                        throw new Error(ct.indexOf('html') !== -1 ? 'login' : 'http-' + r.status);
                                    }
                                    var cd = r.headers.get('content-disposition') || '';
                                    var m = /filename\*=UTF-8''([^;\s]+)/i.exec(cd);
                                    var name = m ? decodeURIComponent(m[1].replace(/"/g, ''))
                                               : (btn.getAttribute('data-filename') || 'manual.pdf');
                                    return r.blob().then(function (b) { return { b: b, name: name }; });
                                })
                                .then(function (o) {
                                    var url = URL.createObjectURL(o.b);
                                    var a = document.createElement('a');
                                    a.href = url;
                                    a.download = o.name;
                                    document.body.appendChild(a);
                                    a.click();
                                    a.remove();
                                    setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
                                    setState(ICON_OK, 'is-done', isEn ? 'Downloaded' : '已开始下载', 2500);
                                })
                                .catch(function (err) {
                                    var msg = (err && err.message === 'login')
                                        ? (isEn ? 'Please refresh & sign in' : '请刷新页面后重试')
                                        : (isEn ? 'Failed, click to retry' : '生成失败，点击重试');
                                    setState(ICON_WARN, 'is-error', msg, 3000);
                                });
                        });
                    })();
                    </script>
                    <?php $product_no = function_exists('get_field') ? get_field('product-no') : ''; ?>
                    <?php $model_tags = get_the_tags(); ?>
                    <?php if (!empty($model_tags)): ?>
                        <p class="lisun-manual-model"><?php echo $is_en ? 'Applicable LISUN models' : '适用于如下LISUN型号'; ?>：
                            <?php
                            $tag_names = array_map(function($t) { return $t->name; }, $model_tags);
                            echo esc_html(implode('、', $tag_names));
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php if (has_post_thumbnail()): ?>
                        <div class="lisun-manual-thumbnail">
                            <?php
                            $thumb_fig_label = $is_en ? 'Figure' : '图';
                            the_post_thumbnail('large', ['alt' => $clean_title . '-' . $thumb_fig_label . '0']);
                            ?>
                        </div>
                    <?php endif; ?>
                </header>

                <div class="lisun-manual-content">
                    <?php
                    // 前台动态补充表格布局标记，避免已有文章必须先运行一次迁移脚本。
                    // 推送流程仍会在保存远程内容前持久化同样的标记。
                    if (!class_exists('Lisun_Manual_Table_Layout') && defined('LISUN_MANUAL_PATH')) {
                        $layout_file = LISUN_MANUAL_PATH . 'includes/class-table-layout.php';
                        if (is_file($layout_file)) {
                            require_once $layout_file;
                        }
                    }
                    if (!class_exists('Lisun_Manual_Product_Video_Compat') && defined('LISUN_MANUAL_PATH')) {
                        $video_file = LISUN_MANUAL_PATH . 'includes/class-product-video-compat.php';
                        if (is_file($video_file)) {
                            require_once $video_file;
                        }
                    }
                    $manual_content = apply_filters('the_content', get_the_content());
                    if (class_exists('Lisun_Manual_Table_Layout')) {
                        $manual_content = Lisun_Manual_Table_Layout::apply_to_content($manual_content);
                    }
                    echo $manual_content;
                    ?>
                </div>

                <?php endwhile; ?>
            </article>

            <?php
            // 视频只读取后台已保存记录；产品页同步由后台任务完成，前台不再实时搜索或写入。
            $manual_videos = json_decode(get_post_meta(get_the_ID(), '_lisun_manual_videos', true), true) ?: [];
            // 去重（按 URL）
            $seen_urls = [];
            $videos = [];
            foreach ($manual_videos as $v) {
                if (!is_array($v)) continue;
                $vurl = $v['url'] ?? '';
                // 归一化：去掉协议前缀再比较，避免 //xxx 和 https://xxx 被当作不同 URL
                $vurl = preg_replace('#^https?:#', '', $vurl);
                if ($vurl && !isset($seen_urls[$vurl])) {
                    $seen_urls[$vurl] = true;
                    $videos[] = $v;
                }
            }
            $software   = json_decode(get_post_meta(get_the_ID(), '_lisun_manual_software', true), true) ?: [];
            $has_media  = !empty($videos) || !empty($software);
            ?>

            <?php if ($has_media): ?>
            <div class="lisun-manual-downloads">
                <h1 class="lisun-dl-section-title">
                    <?php echo $is_en ? 'Appendix & Downloads' : '附录、相关下载'; ?>
                </h1>
                <?php $sec_num = 0; ?>

                <?php if (!empty($software)): $sec_num++; ?>
                <?php /* 2026-11-09 用 h2 而非 div：PDF 书签按标题层级生成，div 不会进大纲 */ ?>
                <h2 class="lisun-dl-sub-title" data-num="<?php echo $sec_num; ?>">
                    <?php echo $is_en ? 'Software & Drivers' : '软件与驱动'; ?>
                </h2>
                <div class="lisun-software-list">
                    <?php foreach ($software as $s): ?>
                    <div class="lisun-software-item">
                        <div class="lisun-software-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <span class="lisun-software-name"><?php echo esc_html($s['name'] ?: $product_no); ?></span>
                        <div class="lisun-software-dl-btns">
                            <?php if (!empty($s['url'])): ?>
                            <a href="<?php echo esc_url($s['url']); ?>" class="lisun-software-dl-btn" target="_blank" rel="noopener">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                <?php echo $is_en ? 'Download 1' : '下载地址 1'; ?>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($s['url2'])): ?>
                            <a href="<?php echo esc_url($s['url2']); ?>" class="lisun-software-dl-btn" target="_blank" rel="noopener">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                <?php echo $is_en ? 'Download 2' : '下载地址 2'; ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php /* 2026-11-09 修订：不再输出明文 URL。
                           交付客户多为电子版，PDF 里的链接本身可点击，直接沿用网页锚文本。 */ ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($videos)): $sec_num++;
                /* 2026-11-09 PDF 渲染通道下不加载视频 iframe：打印时 iframe 本就隐藏，
                   但 Chrome 要等 load 事件，外链播放器会明显拖慢生成。 */
                $is_pdf_render = isset($_GET['lisun_pdf_render']);
                ?>
                <h2 class="lisun-dl-sub-title" data-num="<?php echo $sec_num; ?>">
                    <?php echo $is_en ? 'Videos' : '视频资料'; ?>
                </h2>
                <?php foreach ($videos as $v):
                    $embed_url = $v['url'];
                    // YouTube: 统一转为 embed 格式
                    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/', $v['url'], $m)) {
                        $embed_url = 'https://www.youtube.com/embed/' . $m[1];
                    }
                    /* 2026-11-09 二维码落地页：库里存的是播放器嵌址，扫码后应落到视频页，
                       bilibili player.html?aid=xxx -> bilibili.com/video/avxxx；
                       YouTube embed/ID -> watch?v=ID；协议相对地址补 https。 */
                    $qr_url = $v['url'];
                    if (strpos($qr_url, '//') === 0) {
                        $qr_url = 'https:' . $qr_url;
                    }
                    if (preg_match('#player\.bilibili\.com/player\.html\?.*?\baid=(\d+)#', $qr_url, $qm)) {
                        $qr_url = 'https://www.bilibili.com/video/av' . $qm[1];
                    } elseif (preg_match('#youtube\.com/embed/([A-Za-z0-9_-]+)#', $qr_url, $qm)) {
                        $qr_url = 'https://www.youtube.com/watch?v=' . $qm[1];
                    }
                ?>
                <div class="lisun-video-block" style="margin:0 0 20px 0;">
                    <h3 style="margin:0 0 8px 0;font-size:15px;line-height:1.5;"><?php echo esc_html($v['name']); ?></h3>
                    <div class="lisun-video-embed" style="position:relative;width:100%;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:4px;background:#000;">
                        <iframe <?php echo $is_pdf_render ? 'data-src' : 'src'; ?>="<?php echo esc_url($embed_url); ?>"
                                style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"
                                scrolling="no"
                                allowfullscreen
                                loading="lazy"
                                sandbox="allow-scripts allow-same-origin allow-popups"
                                title="<?php echo esc_attr($v['name']); ?>"></iframe>
                    </div>
                    <?php /* 2026-11-09 打印用二维码：屏幕隐藏，纸上由 JS 生成的 SVG 呈现 */ ?>
                    <div class="lisun-video-qr" data-qr="<?php echo esc_attr($qr_url); ?>">
                        <div class="lisun-video-qr-tip"><?php echo $is_en ? 'Scan to watch the video' : '扫码观看视频'; ?></div>
                        <div class="lisun-video-qr-code"></div>
                        <div class="lisun-video-qr-url"><a href="<?php echo esc_url($qr_url); ?>"><?php echo esc_html($qr_url); ?></a></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($videos)): ?>
            <?php /* 2026-11-09 附录二维码：qrcode-generator 输出 SVG（矢量，打印不糊）。
               脚本在正文里执行，qrcode.js 在页脚入队，故等 DOMContentLoaded / load 再画。 */ ?>
            <script>
            (function () {
                function boot() {
                    var boxes = document.querySelectorAll('.lisun-video-qr[data-qr]');
                    if (!boxes.length || typeof qrcode === 'undefined') return;
                    for (var i = 0; i < boxes.length; i++) {
                        var box = boxes[i];
                        if (box.getAttribute('data-qr-done')) continue;
                        box.setAttribute('data-qr-done', '1');
                        var holder = box.querySelector('.lisun-video-qr-code') || box;
                        try {
                            var qr = qrcode(0, 'M');
                            qr.addData(box.getAttribute('data-qr'));
                            qr.make();
                            holder.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0 });
                            var svg = holder.querySelector('svg');
                            if (svg) {
                                var n = qr.getModuleCount();
                                svg.removeAttribute('width');
                                svg.removeAttribute('height');
                                /* 2026-11-09 修正：createSvgTag 的坐标单位是 cellSize（此处 4），
                                   viewBox 必须同量级，否则只显示左上角一块（二维码变成大黑块）。 */
                                var cell = 4;
                                svg.setAttribute('viewBox', '0 0 ' + (n * cell) + ' ' + (n * cell));
                                svg.setAttribute('shape-rendering', 'crispEdges');
                            }
                        } catch (e) { /* 单条失败不影响页面 */ }
                    }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', boot);
                    window.addEventListener('load', boot);
                } else {
                    boot();
                }
            })();
            </script>
            <?php endif; ?>

        </main>
    </div>

    <footer class="lisun-print-footer" aria-hidden="true">
        <span class="lisun-print-contact-web">www.lisungroup.com</span><i></i><span class="lisun-print-contact-mail">sales@lisungroup.com</span><i></i><span class="lisun-print-contact-phone">+86-21-5108 3341</span>
        <b><span class="lisun-print-page-current"></span> / <span class="lisun-print-page-total"></span></b>
    </footer>

    <?php get_footer(); ?>

    <!-- TOC 生成脚本 + sticky 侧边栏修复 + 表格增强 -->
    <script>
    // 图片外框包裹 — 所有屏幕尺寸均执行，不依赖 TOC/侧边栏
    (function wrapFigures() {
        var content = document.querySelector('.lisun-manual-content');
        if (!content) return;

        function wrapFigure(imgEl, captionEl) {
            var fig = document.createElement('figure');
            fig.className = 'lisun-manual-figure';
            imgEl.parentNode.insertBefore(fig, imgEl);
            fig.appendChild(imgEl);
            if (captionEl) {
                var cap = document.createElement('figcaption');
                cap.textContent = captionEl.textContent.trim();
                fig.appendChild(cap);
                captionEl.remove();
            }
        }

        // WordPress 图片块可能已经生成 .wp-caption 外层；若其中已有 figure，
        // 合并为单层 figure，避免 .wp-caption 和 .lisun-manual-figure 叠加出双边框。
        content.querySelectorAll('.wp-caption').forEach(function (wrapper) {
            var fig = wrapper.querySelector('figure');
            if (!fig) return;
            var oldCaption = wrapper.querySelector('figcaption, .wp-caption-text, p');
            if (!fig.querySelector('figcaption') && oldCaption && oldCaption !== fig) {
                var cap = document.createElement('figcaption');
                cap.textContent = oldCaption.textContent.trim();
                fig.appendChild(cap);
            }
            fig.classList.add('lisun-manual-figure');
            wrapper.parentNode.insertBefore(fig, wrapper);
            wrapper.remove();
        });

        // 有图注的：找 "图X" 段落 → 关联前后图片
        var usedFigs = new Set(), usedImgs = new Set();
        content.querySelectorAll('p').forEach(function (p) {
            var txt = p.textContent.trim();
            var isCaption = /^图\s*\d/.test(txt) || /^图片\s*\d/.test(txt) || /^(?:Figure|Figures|Fig\.?|Figs\.?)\s*\d/i.test(txt);
            if (!isCaption || txt.length > 30) return;
            var imgContainer = null;
            var node = p.previousElementSibling;
            for (var k = 0; k < 3 && node; k++) {
                if (node.tagName === 'FIGURE') { imgContainer = node; break; }
                if (node.querySelector && node.querySelector('img')) { imgContainer = node; break; }
                node = node.previousElementSibling;
            }
            if (!imgContainer) {
                node = p.nextElementSibling;
                for (var kk = 0; kk < 3 && node; kk++) {
                    if (node.tagName === 'FIGURE') { imgContainer = node; break; }
                    if (node.querySelector && node.querySelector('img')) { imgContainer = node; break; }
                    node = node.nextElementSibling;
                }
            }
            if (!imgContainer || usedFigs.has(imgContainer)) return;
            usedFigs.add(imgContainer);
            if (imgContainer.tagName === 'FIGURE') {
                imgContainer.classList.add('lisun-manual-figure');
                if (!imgContainer.querySelector('figcaption')) {
                    var cap = document.createElement('figcaption');
                    cap.textContent = p.textContent.trim();
                    imgContainer.appendChild(cap);
                    p.remove();
                }
                return;
            }
            var rawImg = imgContainer.tagName === 'IMG' ? imgContainer : imgContainer.querySelector('img');
            if (!rawImg) return;
            usedImgs.add(rawImg);
            wrapFigure(rawImg, p);
        });

        // 无图注的：所有裸 <img> 包上外框
        content.querySelectorAll('img').forEach(function (img) {
            if (img.closest('figure')) return;
            if (img.closest('.table-wrapper')) return;
            wrapFigure(img, null);
        });
    })();

    // 部分页面内容由异步渲染/翻译脚本稍后插入，首次扫描时可能还没有 wp-caption。
    // 延迟再次合并，确保异步生成的图片也不会出现双层边框。
    (function retryNormalizeWpCaptions() {
        var content = document.querySelector('.lisun-manual-content');
        if (!content) return;
        function normalize() {
            content.querySelectorAll('.wp-caption').forEach(function (wrapper) {
                var fig = wrapper.querySelector('figure');
                if (!fig) return;
                var oldCaption = wrapper.querySelector('figcaption, .wp-caption-text, p');
                if (!fig.querySelector('figcaption') && oldCaption && oldCaption !== fig) {
                    var cap = document.createElement('figcaption');
                    cap.textContent = oldCaption.textContent.trim();
                    fig.appendChild(cap);
                }
                fig.classList.add('lisun-manual-figure');
                wrapper.parentNode.insertBefore(fig, wrapper);
                wrapper.remove();
            });
        }
        normalize();
        setTimeout(normalize, 300);
        setTimeout(normalize, 1000);
        setTimeout(normalize, 2500);
    })();

    // 标题编号转换 — 所有屏幕均执行，让正文结构更清晰
    (function convertHeadings() {
        var content = document.querySelector('.lisun-manual-content');
        if (!content) return;

        var numToTag = {1: 'H2', 2: 'H3', 3: 'H4', 4: 'H5'};  // depth→tag: "1、"→h2, "1.1"→h3, "1.2.1"→h4
        var allPs = content.querySelectorAll('p');
        allPs.forEach(function (p) {
            // 跳过表格内的段落 —— 表格中的数据不应被当作标题
            // 检查所有可能的表格祖先：td/th（最直接），table（兜底），tr/tbody/thead/tfoot（中间层）
            if (p.closest('td, th, table, tr, tbody, thead, tfoot')) return;
            var t = p.textContent.trim();
            var m = t.match(/^(\d+(?:[\.\、]\d+)*)\s*([\.\、\s]?)\s*/);
            if (!m) return;
            // 深度=2（如 "0.3"）可能是小数/测量值，紧跟拉丁字母说明是单位
            // 深度≥3（如 "12.5.1"）肯定是章节编号，不跳过
            var depth = m[1].split(/[\.\、]/).length;
            // depth=1 的单级编号（如 "1. xxxx"）通常是列表项，不是标题
            // 真正的一级标题（如 "1. 产品概述"）应使用 wp:heading 块，不需要从 <p> 转换
            if (depth === 1) return;
            if (depth === 2) {
                var suffix = t.substring(m[0].length).trim();
                if (suffix.length <= 5 && /^[a-zA-Z\d\s°%²³⁰¹²³⁴⁵⁶⁷⁸⁹/\.\-℃]+$/.test(suffix)) return;
                // "0.x" 开头的数字是测量值（如 0.3m, 0.15 N.m），不是章节编号，跳过
                if (/^0\.\d/.test(m[1])) return;
                // "x.y" 双段小数（如 1.5, 12.34），后跟单位/测量符号 → 测量值，非章节编号
                if (/^\d+\.\d+$/.test(m[1]) && /^[a-zA-Z\d\s°%²³⁰¹²³⁴⁵⁶⁷⁸⁹\.\,\;\、\-\℃°]+$/.test(suffix)) return;
            }
            if (!/[\.\、]/.test(m[1]) && (m[2] || '').trim() === '') return;
            var tag = numToTag[Math.min(depth, 4)];
            if (!tag) return;
            var h = document.createElement(tag);
            if (depth === 2) {
                var tmp = document.createElement('div');
                tmp.innerHTML = p.innerHTML;
                var remain = m[0].length;
                var walker = document.createTreeWalker(tmp, NodeFilter.SHOW_TEXT);
                while (walker.nextNode() && remain > 0) {
                    var node = walker.currentNode;
                    var txt = node.textContent;
                    if (txt.length >= remain) {
                        node.textContent = txt.substring(remain);
                        remain = 0;
                    } else {
                        remain -= txt.length;
                        node.textContent = '';
                    }
                }
                h.innerHTML = '<span class="lisun-num-badge">' + m[1] + '</span> ' + tmp.innerHTML;
            } else {
                h.innerHTML = p.innerHTML;
            }
            var tw = document.createTreeWalker(h, NodeFilter.SHOW_TEXT);
            while (tw.nextNode()) {
                var tn = tw.currentNode;
                if (/^\d+(?:[\.\、]\d+)*$/.test(tn.textContent.trim())) continue;
                var tx = tn.textContent, px;
                do { px = tx; tx = tx.replace(/^(\d+(?:[\.\、]\d+)*[\.\、])\s+(\d)/, '$1$2'); } while (tx !== px);
                tx = tx.replace(/^(\d+)\s+([\.\、])/, '$1$2')
                       .replace(/^(\d+)([\.\、])([^\d\s])/, '$1$2 $3')
                       .replace(/^(\d+(?:[\.\、]\d+)+)([^\d\s\.\、])/, '$1 $2');
                tn.textContent = tx;
            }
            var hx = h.innerHTML, px2;
            do { px2 = hx; hx = hx.replace(/^(\d+(?:[\.\、]\d+)*[\.\、])\s+(\d)/, '$1$2'); } while (hx !== px2);
            h.innerHTML = hx
                .replace(/^(\d+)\s+([\.\、])/, '$1$2')
                .replace(/^(\d+)([\.\、])\s*(<[^>]+>)?\s*([^\d\s<])/, '$1$2 $3$4')
                .replace(/^(\d+(?:[\.\、]\d+)+)\s*(<[^>]+>)?\s*([^\d\s<\.\、])/, '$1 $2$3');
            h.className = 'lisun-num-heading lisun-num-lv' + depth;
            p.parentNode.replaceChild(h, p);
        });

        // 规范化所有标题编号间距（跳过表格内标题）
        var allHeadings = content.querySelectorAll('h1, h2, h3, h4, h5');
        allHeadings.forEach(function (h) {
            if (h.closest('table, td, th, tr, tbody, thead, tfoot')) return;
            var hx2 = h.innerHTML, px3;
            do { px3 = hx2; hx2 = hx2.replace(/^(\d+(?:[\.\、]\d+)*[\.\、])\s+(\d)/, '$1$2'); } while (hx2 !== px3);
            h.innerHTML = hx2
                .replace(/^(\d+)\s+([\.\、])/, '$1$2')
                .replace(/^(\d+)([\.\、])\s*(<[^>]+>)?\s*([^\d\s<])/, '$1$2 $3$4')
                .replace(/^(\d+(?:[\.\、]\d+)+)\s*(<[^>]+>)?\s*([^\d\s<\.\、])/, '$1 $2$3');
            var orig = h.textContent;
            var fixed = orig;
            var pf;
            do { pf = fixed; fixed = fixed.replace(/^(\d+(?:[\.\、]\d+)*[\.\、])\s+(\d)/, '$1$2'); } while (fixed !== pf);
            fixed = fixed.replace(/^(\d+)\s+([\.\、])/, '$1$2')
                         .replace(/^(\d+)([\.\、])([^\d\s])/, '$1$2 $3')
                         .replace(/^(\d+(?:[\.\、]\d+)+)([^\d\s\.\、])/, '$1 $2');
            if (fixed !== orig) {
                var spacePos = 0;
                while (spacePos < orig.length && spacePos < fixed.length && orig[spacePos] === fixed[spacePos]) spacePos++;
                var walker = document.createTreeWalker(h, NodeFilter.SHOW_TEXT);
                var pos = 0;
                while (walker.nextNode()) {
                    var node = walker.currentNode;
                    var len = node.textContent.length;
                    if (pos + len >= spacePos) {
                        var offset = spacePos - pos;
                        node.textContent = node.textContent.slice(0, offset) + ' ' + node.textContent.slice(offset);
                        break;
                    }
                    pos += len;
                }
            }
        });
        // 给原生 H 标签加上多级标题样式和蓝色编号徽章
        // —— 兼容两种来源：数据库已有 class 的（跳过归类，补徽章）
        //                 数据库没有 class 的（补归类 + 补徽章）
        content.querySelectorAll('h1, h2, h3, h4, h5').forEach(function (h) {
            // 跳过表格内的标题 —— 表格中不可能有章节标题
            if (h.closest('table, td, th, tr, tbody, thead, tfoot')) return;
            // 已有徽章时仍清理正文中残留的编号，避免出现“徽章 6.2 + 文字 6.2”。
            var existingBadge = h.querySelector('.lisun-num-badge');
            if (existingBadge) {
                var existingWalker = document.createTreeWalker(h, NodeFilter.SHOW_TEXT);
                while (existingWalker.nextNode()) {
                    var existingNode = existingWalker.currentNode;
                    if (existingNode.parentElement && existingNode.parentElement.closest('.lisun-num-badge')) continue;
                    existingNode.textContent = existingNode.textContent.replace(/^\s*\d+(?:[\.、]\d+)+\s*[\.、]?\s*/, '');
                    break;
                }
                return;
            }

            var txt = h.textContent.trim();
            // 如果开头是 Word 页码残留（如 "17    4.3文件管理"），跳过前缀取真正的章节号
            var prefixMatch = txt.match(/^\d+\s{2,}(\d+(?:[\.\、]\d+)+)/);
            var num = prefixMatch ? prefixMatch[1] : null;
            var matchText = prefixMatch ? txt.substring(prefixMatch[0].length - prefixMatch[1].length) : txt;
            // 匹配："1.1 xxx" / "1、xxx" / "第1章 xxx" / "第1.2节 xxx"
            var m = (num ? [null, num] : null) || matchText.match(/^(?:第)?(\d+(?:[\.\、]\d+)*)[章節节]?\s*[\.\、\s]?\s*/);
            if (!m || !m[1]) return;

            var parts = m[1].split(/[\.\、]/);
            var lv   = Math.min(parts.length, 4);

            // 修正标签层级：lv1→H2, lv2→H3, lv3→H4, lv4→H5
            var lvToTag = {1: 'H2', 2: 'H3', 3: 'H4', 4: 'H5'};
            var expectedTag = lvToTag[lv];
            if (h.tagName !== expectedTag) {
                var newH = document.createElement(expectedTag);
                while (h.firstChild) newH.appendChild(h.firstChild);
                for (var ai = 0; ai < h.attributes.length; ai++) {
                    newH.setAttribute(h.attributes[ai].name, h.attributes[ai].value);
                }
                newH.classList.add('lisun-num-heading', 'lisun-num-lv' + lv);
                h.parentNode.replaceChild(newH, h);
                h = newH;
            } else {
                // 没有 lisun-num-heading class 的（旧文章）：补 class
                if (!h.classList.contains('lisun-num-heading')) {
                    h.classList.add('lisun-num-heading', 'lisun-num-lv' + lv);
                }
            }

            // lv1 不加胶囊徽章；lv3/lv4 也不加（用下划线）
            if (lv !== 2) return;

            // 仅 lv2：插入蓝色编号徽章（含尾部空格，确保 textContent 中编号与正文分隔）
            var badge = document.createElement('span');
            badge.className = 'lisun-num-badge';
            badge.textContent = m[1] + ' ';

            // 从文本节点中移除编号部分（含尾部点号/空格）
            var walker = document.createTreeWalker(h, NodeFilter.SHOW_TEXT);
            if (walker.nextNode()) {
                var node = walker.currentNode;
                node.textContent = node.textContent.replace(m[1], '').replace(/^[\.\、]\s*/, '');
            }
            h.insertBefore(badge, h.firstChild);
        });
    })();

    // TOC、侧边栏 — 仅大屏
    (function () {
        if (window.innerWidth <= 900) return;

        (function fixSidebar() {
            var sidebar = document.querySelector('.lisun-manual-sidebar');
            var wrap = document.querySelector('.lisun-manual-wrap');
            if (!sidebar || !wrap) return;

            // 清理旧代码可能残留在 body/html 上的 overflow:visible
            if (document.body.style.getPropertyPriority('overflow') === 'important') {
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('overflow-x');
                document.body.style.removeProperty('overflow-y');
            }
            if (document.documentElement.style.getPropertyPriority('overflow') === 'important') {
                document.documentElement.style.removeProperty('overflow');
                document.documentElement.style.removeProperty('overflow-x');
                document.documentElement.style.removeProperty('overflow-y');
            }

            // 修正祖先 overflow（sticky 需要所有祖先 overflow:visible）
            // 注意：只修到 <body> 之前，不碰 <body>/<html>
            // 否则视口失去裁剪能力，页面会滑过页脚
            var el = sidebar;
            while (el && el !== document.body) {
                el = el.parentElement;
                if (!el || el === document.body) break;
                var s = getComputedStyle(el);
                if (s.overflowX !== 'visible') el.style.setProperty('overflow-x', 'visible', 'important');
                if (s.overflowY !== 'visible') el.style.setProperty('overflow-y', 'visible', 'important');
                if (s.overflow !== 'visible') el.style.setProperty('overflow', 'visible', 'important');
            }

            var TOP_OFFSET = 130;
            var ticking = false;

            function updateSidebar() {
                var wrapRect = wrap.getBoundingClientRect();
                var viewH = window.innerHeight;

                // 侧边栏可用高度：不超过视口高度
                var sidebarTop = Math.max(wrapRect.top, TOP_OFFSET);
                var availH = viewH - sidebarTop - 20;
                // 当内容很短时，侧边栏不应超过 wrap 的实际高度，否则 sticky 会把侧边栏顶出内容区
                var maxH = Math.max(Math.min(availH, wrapRect.height), 200);
                sidebar.style.maxHeight = maxH + 'px';

                // 触底时：用 translateY 抵消 sticky 被父容器顶上去的量
                // 短内容页面（viewH > wrapBottom）不做偏移，让侧边栏自然跟随
                var wrapBottom = wrapRect.bottom;
                var pushAmount = viewH - wrapBottom;
                if (pushAmount > 0 && wrapRect.height > viewH * 0.5) {
                    sidebar.style.transform = 'translateY(-' + Math.min(pushAmount, sidebar.offsetHeight - 60) + 'px)';
                } else {
                    sidebar.style.transform = '';
                }
            }

            updateSidebar();
            // 暴露出接口，让表格初始化等操作后也能触发重算
            window._lisunUpdateSidebar = updateSidebar;
            // 图片加载后内容高度会变，需要重新计算侧边栏
            document.querySelectorAll('.lisun-manual-content img').forEach(function(img) {
                if (img.complete) return;
                img.addEventListener('load', function() { updateSidebar(); });
            });
            // 兜底：500ms 后再算一次
            setTimeout(function() { updateSidebar(); }, 500);
            window.addEventListener('resize', function () {
                updateSidebar();
            });
            window.addEventListener('scroll', function () {
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(function () {
                    updateSidebar();
                    ticking = false;
                });
            }, { passive: true });
        })();

        var content = document.querySelector('.lisun-manual-content');
        var toc = document.getElementById('lisun-toc-nav');
        if (!content || !toc) return;

        var items = [];
        // 同时扫描正文和下载区的标题
        var headings = content.querySelectorAll('h1, h2, h3, h4, h5');
        var dlHeadings = document.querySelectorAll('.lisun-manual-downloads h1, .lisun-manual-downloads h2, .lisun-manual-downloads h3');
        headings = Array.from(headings).concat(Array.from(dlHeadings));

        if (headings.length >= 2) {
            // 检查文档中是否存在 h1（没有 h1 时，h2 的章节标题应降为 lv1）
            var hasH1 = Array.from(headings).some(function(h) { return h.tagName === 'H1'; });
            headings.forEach(function (h, i) {
                // 跳过表格内的标题 —— 表格中不可能有章节标题，数字如 0.15 是数据不是标题
                if (h.closest('table')) return;
                var id = 'lisun-h-' + i;
                h.id = id;
                var text = h.textContent.trim();
                // 跳过纯小数/数字（如 "0.15"、"1.5"）—— 这些是测量值，不是章节编号
                if (/^\d+\.\d+$/.test(text)) return;
                var level;
                // 通过编号模式检测逻辑深度："1、"→1级, "1.1"→2级, "1.2.1"→3级
                var numMatch = text.match(/^(\d+(?:[\.\、]\d+)*)/);
                if (numMatch) {
                    level = Math.min(numMatch[1].split(/[\.\、]/).length, 5);
                } else {
                    // 无编号标题：用标签级别。若文档无 h1，h2 降为 lv1
                    level = parseInt(h.tagName.charAt(1));
                    if (!hasH1 && level >= 2) level = level - 1;
                }
                items.push({
                    id: id,
                    text: text,
                    level: level,
                    el: h
                });
            });
        } else {
            var paras = content.querySelectorAll('p');
            var found = [];
            paras.forEach(function (p) {
                // 跳过表格内的段落 —— 表格中的数据不应被当作标题
                if (p.closest('table')) return;
                var t = p.textContent.trim();
                var m = t.match(/^(\d+(?:[\.\、]\d+)*)\s*([\.\、\s])\s*/);
                if (!m) return;
                if (!/[\.\、]/.test(m[1]) && m[2].trim() === '') return;
                // "0.x" 开头的数字是测量值（如 0.3 m），不是章节编号，跳过
                if (/^0\.\d/.test(m[1])) return;
                var parts = m[1].split(/[\.\、]/);
                var pt;
                do { pt = t; t = t.replace(/^(\d+(?:[\.\、]\d+)*[\.\、])\s+(\d)/, '$1$2'); } while (t !== pt);
                t = t.replace(/^(\d+)\s+([\.\、])/, '$1$2')
                     .replace(/^(\d+)([\.\、])([^\d\s])/, '$1$2 $3')
                     .replace(/^(\d+(?:[\.\、]\d+)+)([^\d\s\.\、])/, '$1 $2');
                found.push({ el: p, text: t, level: parts.length });  // 1="1、xxx"→lv1, 2="1.1"→lv2, 3="1.1.1"→lv3
            });
            if (found.length >= 2) {
                found.forEach(function (item, i) {
                    var id = 'lisun-h-' + i;
                    var anchor = document.createElement('span');
                    anchor.id = id;
                    anchor.style.cssText = 'display:block;height:1px;';
                    item.el.parentNode.insertBefore(anchor, item.el);
                    items.push({ id: id, text: item.text, level: item.level, el: anchor });
                });
            }
        }

        if (items.length < 2) {
            document.getElementById('lisun-toc').style.display = 'none';
            return;
        }

        var html = '<ul class="lisun-toc-list">';
        items.forEach(function (item) {
            var cls = 'lisun-toc-item lisun-toc-lv' + item.level;
            html += '<li class="' + cls + '"><a href="#' + item.id + '">' + item.text + '</a></li>';
        });
        html += '</ul>';
        toc.innerHTML = html;

        var tocLinks = toc.querySelectorAll('a');
        var tocBox = document.getElementById('lisun-toc');
        var tocNavigation = {
            token: 0,
            timers: [],
            cleanup: null,
            lockedHref: ''
        };

        function centerTocLink(link) {
            if (!link || !tocBox) return;
            var linkRect = link.getBoundingClientRect();
            var tocRect = tocBox.getBoundingClientRect();
            var linkTop = linkRect.top - tocRect.top + tocBox.scrollTop;
            var tocScroll = tocBox.scrollTop;
            var tocH = tocBox.clientHeight;
            if (linkTop < tocScroll + 20 || linkTop > tocScroll + tocH - 40) {
                var oldBehavior = tocBox.style.scrollBehavior;
                tocBox.style.scrollBehavior = 'auto';
                tocBox.scrollTop = linkTop - tocH / 3;
                requestAnimationFrame(function () {
                    tocBox.style.scrollBehavior = oldBehavior;
                });
            }
        }

        function setTocActive(href, keepVisible) {
            var activeLink = null;
            tocLinks.forEach(function (a) {
                var match = a.getAttribute('href') === href;
                a.classList.toggle('lisun-toc-active', match);
                if (match) activeLink = a;
            });
            if (keepVisible) centerTocLink(activeLink);
        }

        function cancelTocNavigation() {
            tocNavigation.token++;
            tocNavigation.timers.forEach(function (timer) {
                clearTimeout(timer);
            });
            tocNavigation.timers = [];
            if (tocNavigation.cleanup) {
                tocNavigation.cleanup();
                tocNavigation.cleanup = null;
            }
            tocNavigation.lockedHref = '';
        }

        function addTocTimer(callback, delay) {
            var timer = setTimeout(function () {
                tocNavigation.timers = tocNavigation.timers.filter(function (t) {
                    return t !== timer;
                });
                callback();
            }, delay);
            tocNavigation.timers.push(timer);
        }

        toc.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                cancelTocNavigation();
                var navToken = tocNavigation.token;
                var clickedHref = this.getAttribute('href');
                tocNavigation.lockedHref = clickedHref;
                setTocActive(clickedHref, true);
                var targetId = clickedHref.substring(1);
                var target = document.getElementById(targetId);
                if (!target) {
                    cancelTocNavigation();
                    return;
                }

                function scrollToTarget(behavior) {
                    var t = document.getElementById(targetId);
                    if (!t) return;
                    var top = t.getBoundingClientRect().top + window.pageYOffset - 140;
                    window.scrollTo({ top: top, behavior: behavior });
                }

                // 首次 smooth 滚动，记下目标位置
                var targetTop = target.getBoundingClientRect().top + window.pageYOffset - 140;
                scrollToTarget('smooth');
                var scrollTargetY = targetTop;
                var userScrolledAway = false;

                // 监听用户手动滚动（滚轮/触摸），主动滚走就取消后续修正
                function onUserScroll() {
                    userScrolledAway = true;
                    if (navToken === tocNavigation.token) {
                        tocNavigation.lockedHref = '';
                        updateTocActiveFromScroll();
                    }
                }
                window.addEventListener('wheel', onUserScroll, { once: true, passive: true });
                window.addEventListener('touchmove', onUserScroll, { once: true, passive: true });
                tocNavigation.cleanup = function () {
                    window.removeEventListener('wheel', onUserScroll);
                    window.removeEventListener('touchmove', onUserScroll);
                };

                // 多次修正：300ms / 700ms / 1500ms
                // 覆盖懒加载图片/视频/iframe 加载后造成的布局偏移
                [300, 700, 1500].forEach(function (delay) {
                    addTocTimer(function () {
                        if (navToken !== tocNavigation.token || userScrolledAway) return;
                        var t = document.getElementById(targetId);
                        if (!t) return;
                        var top = t.getBoundingClientRect().top + window.pageYOffset - 140;
                        if (Math.abs(window.pageYOffset - top) > 30) {
                            scrollToTarget('auto');
                            scrollTargetY = top;
                        }
                    }, delay);
                });
                addTocTimer(function () {
                    if (navToken !== tocNavigation.token) return;
                    tocNavigation.lockedHref = '';
                    updateTocActiveFromScroll();
                }, 1700);

                // 监听内容区图片全部加载完成，再做一次修正
                (function () {
                    var content = document.querySelector('.lisun-manual-content');
                    if (!content) return;
                    var imgs = Array.from(content.querySelectorAll('img'));
                    if (imgs.length === 0) return;
                    var pending = 0;
                    imgs.forEach(function (img) {
                        if (!img.complete) {
                            pending++;
                            img.addEventListener('load', function () {
                                pending--;
                                if (pending === 0) checkAndFix();
                            }, { once: true });
                        }
                    });
                    // 如果全部已加载完（pending 仍为 0），直接检查一次
                    if (pending === 0) checkAndFix();
                    function checkAndFix() {
                        if (navToken !== tocNavigation.token || userScrolledAway) return;
                        var t = document.getElementById(targetId);
                        if (!t) return;
                        var top = t.getBoundingClientRect().top + window.pageYOffset - 140;
                        if (Math.abs(window.pageYOffset - top) > 30) {
                            scrollToTarget('auto');
                            scrollTargetY = top;
                        }
                    }
                })();
            });
        });

        var ticking = false;

        function updateTocActiveFromScroll() {
            if (tocNavigation.lockedHref) {
                setTocActive(tocNavigation.lockedHref, true);
                return;
            }
            var current = '';
            var currentLink = null;
            for (var i = items.length - 1; i >= 0; i--) {
                if (items[i].el.getBoundingClientRect().top <= 150) {
                    current = '#' + items[i].id;
                    break;
                }
            }
            tocLinks.forEach(function (a) {
                var match = a.getAttribute('href') === current;
                a.classList.toggle('lisun-toc-active', match);
                if (match) currentLink = a;
            });
            centerTocLink(currentLink);
        }

        window.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(function () {
                updateTocActiveFromScroll();
                ticking = false;
            });
        }, { passive: true });

    })();

    var TBL = {
        copy: '<svg viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>',
        download: '<svg viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>',
        fs: '<svg viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>',
        fsExit: '<svg viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>'
    };

    function initTable() {
        document.querySelectorAll('.lisun-manual-content table').forEach(function(table) {
            if (table.closest('.table-wrapper')) return;
            var oldWrap = table.closest('.lisun-manual-table-wrap');
            if (oldWrap) {
                oldWrap.parentNode.insertBefore(table, oldWrap);
                oldWrap.parentNode.removeChild(oldWrap);
            }
            var wrapper = document.createElement('div');
            wrapper.className = 'table-wrapper';
            var toolbar = document.createElement('div');
            toolbar.className = 'table-toolbar';
            toolbar.innerHTML =
                '<button data-action="copy" title="复制表格(TSV)">' + TBL.copy + ' Copy</button>' +
                '<button data-action="download" title="下载CSV">' + TBL.download + ' Download</button>' +
                '<button data-action="fullscreen" title="全屏">' + TBL.fs + ' Fullscreen</button>';
            var scrollDiv = document.createElement('div');
            scrollDiv.className = 'table-scroll-container';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(toolbar);
            wrapper.appendChild(scrollDiv);
            scrollDiv.appendChild(table);
        });
        // 表格初始化后立即规范化列宽
        normalizeTableColumns();
    }

    /**
     * 规范化表格列宽 —— 按内容智能分配
     *
     * 遍历每列所有单元格的文本长度，用 sqrt(maxLen × avgLen) 计算"宽度得分"，
     * 归一化到 100% 后写入各单元格 width 属性。
     *
     * 对比旧版"只读显式 width"的做法，这个算法能正确处理：
     * - 编号/序号列（短文本）→ 自动收窄
     * - 说明/描述列（长段落）→ 自动放宽
     * - 多列型号对比表（相近长度）→ 接近均分
     */
    function normalizeTableColumns() {
        // 桌面端 table-layout: auto 已按内容自适应，不再用 JS 干预列宽
        // 保留函数体为空，以便将来需要时恢复
        return;
    }



    function copyTable(wrapper) {
        var table = wrapper.querySelector('table');
        var tsv = [];
        var rows = table.querySelectorAll('tr');
        rows.forEach(function(row) {
            var rowCells = [];
            row.querySelectorAll('th, td').forEach(function(cell) {
                // Get raw text, replace tabs/newlines with space, trim
                rowCells.push(cell.textContent.replace(/[\t\n\r]+/g, ' ').replace(/\s+/g, ' ').trim());
            });
            tsv.push(rowCells.join('\t'));
        });
        var textResult = tsv.join('\n');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textResult).then(showToast);
        } else {
            var ta = document.createElement('textarea');
            ta.value = textResult;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast();
        }
    }

    function showToast() {
        var toast = document.querySelector('.copy-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'copy-toast';
            toast.textContent = '已复制为TSV格式！';
            document.body.appendChild(toast);
        }
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 2000);
    }

    function downloadCSV(wrapper) {
        var csv = [];
        wrapper.querySelectorAll('table tr').forEach(function(row) {
            var rowData = [];
            row.querySelectorAll('th, td').forEach(function(cell) {
                rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
            });
            csv.push(rowData.join(','));
        });
        var blob = new Blob(['﻿' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);
        link.href = url;
        link.download = 'table-data.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function toggleFullscreen(wrapper) {
        if (document.fullscreenElement || document.webkitFullscreenElement) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        } else {
            (wrapper.requestFullscreen || wrapper.webkitRequestFullscreen).call(wrapper);
        }
    }

    function updateFsBtn() {
        var isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
        document.querySelectorAll('.table-toolbar button[data-action="fullscreen"]').forEach(function(btn) {
            btn.innerHTML = isFs ? TBL.fsExit + ' 退出' : TBL.fs + ' 全屏';
            btn.title = isFs ? '退出全屏' : '全屏';
        });
    }

    document.addEventListener('fullscreenchange', updateFsBtn);
    document.addEventListener('webkitfullscreenchange', updateFsBtn);

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.table-toolbar button');
        if (!btn) return;
        e.preventDefault();
        var wrapper = btn.closest('.table-wrapper');
        var action = btn.getAttribute('data-action');
        if (action === 'copy') copyTable(wrapper);
        else if (action === 'download') downloadCSV(wrapper);
        else if (action === 'fullscreen') toggleFullscreen(wrapper);
    });

    function initTableAndFixSidebar() {
        initTable();
        if (window._lisunUpdateSidebar) window._lisunUpdateSidebar();
    }

    document.addEventListener('DOMContentLoaded', function() {
        initTableAndFixSidebar();
        setTimeout(initTableAndFixSidebar, 500);
    });

    if (document.readyState !== 'loading') {
        initTableAndFixSidebar();
        setTimeout(initTableAndFixSidebar, 500);
    }

    // 兜底：页面完全加载后（含懒加载图片），再次重算侧边栏
    window.addEventListener('load', function() {
        if (window._lisunUpdateSidebar) {
            window._lisunUpdateSidebar();
            setTimeout(function() { window._lisunUpdateSidebar(); }, 1000);
        }
    });

    // 页面加载后持续 30s 定期重算侧边栏（延迟渲染的图片/表格会导致内容高度变化）
    if (window._lisunUpdateSidebar) {
        var _count = 0;
        var _iv = setInterval(function() {
            window._lisunUpdateSidebar();
            if (++_count >= 15) clearInterval(_iv);
        }, 2000);
    }

    // 监听内容区 DOM 变化（图片替换、表格初始化等），自动重算侧边栏
    if (window._lisunUpdateSidebar) {
        var contentEl = document.querySelector('.lisun-manual-content');
        if (contentEl) {
            var sidebarTimer = 0;
            var observer = new MutationObserver(function() {
                clearTimeout(sidebarTimer);
                sidebarTimer = setTimeout(function() { window._lisunUpdateSidebar(); }, 200);
            });
            observer.observe(contentEl, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['style', 'src', 'width', 'height', 'data-src']
            });
        }
    }
    </script>
</body>
</html>
