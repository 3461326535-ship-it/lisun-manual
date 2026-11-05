<?php
/**
 * 说明书归档页模板 — 风格与单页对齐
 * 面包屑、页脚、配色、字体与 single-manual 保持一致
 * 包含分类筛选器 + 内部搜索
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Lisun_Manual_Product_Video_Compat') && defined('LISUN_MANUAL_PATH')) {
    $video_file = LISUN_MANUAL_PATH . 'includes/class-product-video-compat.php';
    if (is_file($video_file)) {
        require_once $video_file;
    }
}

// ========== 筛选器数据准备 ==========
global $wpdb;
$table_cats    = $wpdb->prefix . 'lisun_product_cats';
$table_subcats = $wpdb->prefix . 'lisun_product_subcats';
$has_filter_data = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_cats)) === $table_cats);

$filter_cats    = [];
$filter_subcats = [];
if ($has_filter_data) {
    $filter_cats    = $wpdb->get_results("SELECT id, name FROM {$table_cats} ORDER BY sort_order, id");
    $filter_subcats = $wpdb->get_results("SELECT id, cat_id, name FROM {$table_subcats} ORDER BY cat_id, sort_order, id");
}

// 当前筛选值
$current_cat    = isset($_GET['manual_cat']) ? intval($_GET['manual_cat']) : 0;
$current_subcat = isset($_GET['manual_subcat']) ? intval($_GET['manual_subcat']) : 0;
$current_search = isset($_GET['manual_search']) ? sanitize_text_field(wp_unslash($_GET['manual_search'])) : '';

// 构建 JS 用的子分类映射
$subcat_map_js = [];
foreach ($filter_subcats as $sc) {
    $cat_id = intval($sc->cat_id);
    if (!isset($subcat_map_js[$cat_id])) {
        $subcat_map_js[$cat_id] = [];
    }
    $subcat_map_js[$cat_id][] = ['id' => intval($sc->id), 'name' => $sc->name];
}
$is_en = get_option('lisun_page_lang', 'zh') === 'en';
?>
<!doctype html>
<html lang="<?php echo get_option('lisun_page_lang', 'zh') !== 'en' ? 'zh-CN' : 'en'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,Chrome=1">
    <link rel="shortcut icon" href="<?php echo esc_url(get_template_directory_uri()); ?>/img/favicon.ico">
    <title><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? 'Manuals' : '说明书'; ?> - <?php bloginfo('name'); ?></title>
    <meta name="description" content="<?php bloginfo('description'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(get_template_directory_uri()); ?>/css/cases-s.css" type="text/css" media="all" />
    <script type="text/javascript" src="<?php echo esc_url(get_template_directory_uri()); ?>/js/jquery-1.9.1.min.js"></script>
    <?php wp_head(); ?>

    <style>
    /* ========== 归档页专属样式 ========== */
    .lisun-manual-archive {
        max-width: 1000px;
        margin: 0 auto;
        padding: 0 24px 80px;
        font-family: "Microsoft YaHei", "微软雅黑", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }

    /* 归档页标题 */
    .lisun-archive-header {
        text-align: center;
        padding: 48px 0 36px;
        margin-bottom: 24px;
        transform: translatey(20px);
    }
    .lisun-archive-header h1 {
        font-size: 28px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 8px;
        letter-spacing: -0.5px;
    }
    .lisun-archive-header p {
        font-size: 14px;
        color: #6b7280;
        margin: 0;
    }

    /* ========== 筛选器样式 ========== */
    .lisun-filter-bar {
        margin-bottom: 24px;
    }
    .lisun-filter-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .lisun-filter-select {
        height: 42px;
        padding: 0 36px 0 14px;
        font-size: 14px;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
        color: #374151;
        background: #fff;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        cursor: pointer;
        outline: none;
        -webkit-appearance: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%236b7280' stroke-width='2' viewBox='0 0 24 24'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        transition: border-color 0.2s;
        min-width: 180px;
        max-width: 240px;
    }
    .lisun-filter-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .lisun-filter-search-wrap {
        position: relative;
        flex: 1;
        min-width: 200px;
    }
    .lisun-filter-search-input {
        width: 100%;
        height: 42px;
        padding: 0 42px 0 14px;
        font-size: 14px;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
        color: #374151;
        background: #fff;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        outline: none;
        transition: border-color 0.2s;
        box-sizing: border-box;
    }
    .lisun-filter-search-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    .lisun-filter-search-input::placeholder {
        color: #9ca3af;
    }
    .lisun-filter-search-btn {
        position: absolute;
        right: 0;
        top: 0;
        width: 42px;
        height: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: none;
        cursor: pointer;
        color: #6b7280;
        border-radius: 0 8px 8px 0;
        transition: color 0.2s;
    }
    .lisun-filter-search-btn:hover {
        color: #2563eb;
    }
    .lisun-filter-reset {
        flex-shrink: 0;
        height: 42px;
        line-height: 42px;
        padding: 0 16px;
        font-size: 13px;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
        color: #6b7280;
        text-decoration: none;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #f9fafb;
        transition: all 0.2s;
        white-space: nowrap;
    }
    .lisun-filter-reset:hover {
        color: #ef4444;
        border-color: #fca5a5;
        background: #fef2f2;
    }

    /* 列表容器 */
    .lisun-archive-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    /* 列表项 —— 卡片式 */
    .lisun-archive-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 24px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        text-decoration: none;
        color: #374151;
        transition: all 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    .lisun-archive-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: #2563eb;
        transform: scaleY(0);
        transition: transform 0.2s ease;
        border-radius: 10px 0 0 10px;
    }
    .lisun-archive-item:hover {
        border-color: #bfdbfe;
        box-shadow: 0 4px 16px rgba(37, 99, 235, 0.08);
        transform: translateX(2px);
    }
    .lisun-archive-item:hover::before {
        transform: scaleY(1);
    }

    /* 标题区 */
    .lisun-archive-item-left {
        display: flex;
        align-items: center;
        gap: 14px;
        flex: 1;
        min-width: 0;
    }
    .lisun-archive-icon {
        width: 40px;
        height: 40px;
        background: #eff6ff;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .lisun-archive-icon svg {
        width: 20px;
        height: 20px;
        color: #2563eb;
    }
    .lisun-archive-title {
        font-size: 15px;
        font-weight: 500;
        color: #1f2937;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }

    /* 标题前的型号前缀 */
    .lisun-archive-model-prefix {
        margin-right: 8px;
    }

    /* 视频/软件标签 */
    .lisun-archive-tags {
        display: flex;
        gap: 6px;
        flex-shrink: 0;
    }
    .lisun-archive-tag {
        font-size: 11px;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 5px;
        white-space: nowrap;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }
    .lisun-archive-tag.video-tag {
        background: #fef3c7;
        color: #92400e;
    }
    .lisun-archive-tag.software-tag {
        background: #dbeafe;
        color: #1e40af;
    }

    /* 箭头 */
    .lisun-archive-arrow {
        width: 18px;
        height: 18px;
        color: #9ca3af;
        flex-shrink: 0;
        transition: color 0.2s, transform 0.2s;
    }
    .lisun-archive-item:hover .lisun-archive-arrow {
        color: #2563eb;
        transform: translateX(3px);
    }

    /* 分页导航 */
    .lisun-archive-pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 40px;
        padding-top: 32px;
    }
    .lisun-archive-pagination a,
    .lisun-archive-pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        height: 40px;
        padding: 0 14px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s;
        font-family: "Microsoft YaHei", "微软雅黑", sans-serif;
    }
    .lisun-archive-pagination a {
        background: #fff;
        border: 1px solid #e5e7eb;
        color: #374151;
    }
    .lisun-archive-pagination a:hover {
        border-color: #2563eb;
        color: #2563eb;
        background: #eff6ff;
    }
    .lisun-archive-pagination span.current {
        background: #2563eb;
        color: #fff;
        border: 1px solid #2563eb;
    }
    .lisun-archive-pagination span.dots {
        background: transparent;
        border: none;
        color: #9ca3af;
    }

    /* 空状态 */
    .lisun-archive-empty {
        text-align: center;
        padding: 80px 24px;
        color: #9ca3af;
        font-size: 15px;
    }

    /* 响应式 */
    @media (max-width: 640px) {
        .lisun-manual-archive {
            padding: 0 16px 60px;
        }
        .lisun-archive-header {
            padding: 32px 0 24px;
        }
        .lisun-archive-header h1 {
            font-size: 22px;
        }
        .lisun-archive-item {
            padding: 14px 16px;
        }
        .lisun-archive-title {
            font-size: 14px;
        }
        .lisun-archive-icon {
            width: 34px;
            height: 34px;
        }
        .lisun-archive-icon svg {
            width: 16px;
            height: 16px;
        }
        .lisun-filter-row {
            flex-direction: column;
            align-items: stretch;
        }
        .lisun-filter-select {
            min-width: 0;
            max-width: none;
            width: 100%;
        }
        .lisun-filter-search-wrap {
            max-width: none;
        }
        .lisun-filter-reset {
            text-align: center;
        }
    }
    </style>
</head>
<body>
    <?php
    if (file_exists(get_template_directory() . '/nav.php')) {
        include get_template_directory() . '/nav.php';
    }
    ?>

    <!-- 面包屑 —— 与单页完全一致 -->
    <style>
    .breadcrumb_info .content_wrap { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .breadcrumb_info .content_wrap > *:not(:last-child)::after { content:">"; margin-left:10px; color:#999; }
    </style>
    <div class="out_wrap breadcrumb_info">
        <div class="content_wrap">
            <a class="fa" href="/"><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? 'Home' : '首页'; ?></a>
            <span><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? 'Manual' : '说明书'; ?></span>
            <div class="clearfix"></div>
        </div>
    </div>

    <!-- 归档主体 -->
    <main class="lisun-manual-archive">
        <div class="lisun-archive-header">
            <h1><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? 'Manuals' : '说明书'; ?></h1>
            <p><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? 'Browse all product manuals and operation guides' : '浏览全部产品说明书与操作手册'; ?></p>
        </div>

        <?php if ($has_filter_data): ?>
        <!-- 筛选器 + 搜索栏 -->
        <form method="get" action="<?php echo esc_url(get_post_type_archive_link('manual')); ?>" class="lisun-filter-bar" id="lisun-filter-form">
            <div class="lisun-filter-row">
                <!-- 大分类 -->
                <select name="manual_cat" id="lisun-filter-cat" class="lisun-filter-select">
                    <option value="0"><?php echo $is_en ? 'All Categories' : '全部分类'; ?></option>
                    <?php foreach ($filter_cats as $cat): ?>
                    <option value="<?php echo intval($cat->id); ?>" <?php selected($current_cat, intval($cat->id)); ?>>
                        <?php echo esc_html($cat->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- 小分类 -->
                <select name="manual_subcat" id="lisun-filter-subcat" class="lisun-filter-select">
                    <option value="0"><?php echo $is_en ? 'All Subcategories' : '全部分类'; ?></option>
                    <?php foreach ($filter_subcats as $sc): ?>
                    <option value="<?php echo intval($sc->id); ?>"
                            data-cat="<?php echo intval($sc->cat_id); ?>"
                            <?php selected($current_subcat, intval($sc->id)); ?>
                            <?php if ($current_cat > 0 && intval($sc->cat_id) !== $current_cat) echo 'style="display:none"'; ?>>
                        <?php echo esc_html($sc->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- 搜索框 -->
                <div class="lisun-filter-search-wrap">
                    <input type="text" name="manual_search" id="lisun-filter-search"
                           class="lisun-filter-search-input"
                           placeholder="<?php echo $is_en ? 'Search model / keyword…' : '搜索型号 / 关键词…'; ?>"
                           value="<?php echo esc_attr($current_search); ?>">
                    <button type="submit" class="lisun-filter-search-btn" aria-label="<?php echo $is_en ? 'Search' : '搜索'; ?>">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                        </svg>
                    </button>
                </div>

                <?php if ($current_cat > 0 || $current_subcat > 0 || $current_search !== ''): ?>
                <a href="<?php echo esc_url(get_post_type_archive_link('manual')); ?>" class="lisun-filter-reset">
                    <?php echo $is_en ? 'Reset' : '清除筛选'; ?>
                </a>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>

        <?php if (have_posts()): ?>
        <div class="lisun-archive-list">
            <?php while (have_posts()): the_post();
                $lang = get_post_meta(get_the_ID(), '_lisun_manual_lang', true);
                $product_no = get_post_meta(get_the_ID(), 'product-no', true);
                $videos     = !empty($product_no) && class_exists('Lisun_Manual_Product_Video_Compat')
                    ? Lisun_Manual_Product_Video_Compat::get_by_model($product_no, $lang)
                    : [];
                $software   = json_decode(get_post_meta(get_the_ID(), '_lisun_manual_software', true), true) ?: [];

                // 去掉 "xxx操作说明书" / "xxx说明书" 等后缀，只保留产品名称
                $title = get_the_title();
                $title = preg_replace(
                    '/\s*(?:[（(]?(?:操作|使用|安装|用户)?[说説說]明[书書]|(?:操作|使用|安装|用户)手[册冊]|(?:Operation|User|Instruction)\s+Manual)[)）]?\s*/iu',
                    '', $title
                );
                $title = trim($title);
            ?>
            <a href="<?php the_permalink(); ?>" class="lisun-archive-item">
                <div class="lisun-archive-item-left">
                    <div class="lisun-archive-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <span class="lisun-archive-title">
                        <?php if (!empty($product_no)): ?>
                            <span class="lisun-archive-model-prefix"><?php echo esc_html($product_no); ?></span>
                        <?php endif; ?>
                        <?php echo esc_html($title); ?>
                    </span>
                </div>
                <div class="lisun-archive-tags">
                    <?php if (!empty($videos)): ?>
                    <span class="lisun-archive-tag video-tag"><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? '📹 Video' : '📹 视频'; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($software)): ?>
                    <span class="lisun-archive-tag software-tag"><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? '📥 Software' : '📥 软件'; ?></span>
                    <?php endif; ?>
                </div>
                <svg class="lisun-archive-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
            <?php endwhile; ?>
        </div>

        <!-- 分页 -->
        <?php
        global $wp_query;
        if ($wp_query->max_num_pages > 1):
        ?>
        <div class="lisun-archive-pagination">
            <?php
            echo paginate_links(array(
                'prev_text' => '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>',
                'next_text' => '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>',
                'type'      => 'plain',
                'end_size'  => 1,
                'mid_size'  => 2,
            ));
            ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="lisun-archive-empty">
            <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom: 16px; opacity: 0.4;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p><?php echo get_option('lisun_page_lang', 'zh') === 'en' ? 'No manuals available yet. Please check back later.' : '暂无说明书，请稍后查看。'; ?></p>
        </div>
        <?php endif; ?>
    </main>

    <?php get_footer(); ?>

    <?php if ($has_filter_data): ?>
    <!-- 筛选器交互脚本 -->
    <script>
    (function() {
        var catSelect = document.getElementById('lisun-filter-cat');
        var subcatSelect = document.getElementById('lisun-filter-subcat');
        var searchInput = document.getElementById('lisun-filter-search');
        var filterForm = document.getElementById('lisun-filter-form');

        if (!catSelect || !subcatSelect || !filterForm) return;

        // 子分类数据: { cat_id: [{id, name}, ...] }
        var subcatData = <?php echo json_encode($subcat_map_js, JSON_UNESCAPED_UNICODE); ?>;

        // 大分类变化 → 更新小分类选项
        catSelect.addEventListener('change', function() {
            var catId = parseInt(this.value);
            var allOptions = subcatSelect.querySelectorAll('option');
            var foundVisible = false;

            allOptions.forEach(function(opt) {
                var optCatId = parseInt(opt.getAttribute('data-cat'));
                if (opt.value === '0') return; // 保留"全部分类"
                if (catId === 0 || optCatId === catId) {
                    opt.style.display = '';
                    if (!foundVisible) foundVisible = true;
                } else {
                    opt.style.display = 'none';
                }
            });

            // 如果当前选中的小分类不可见，切回"全部分类"
            if (subcatSelect.selectedIndex > 0) {
                var selectedOpt = subcatSelect.options[subcatSelect.selectedIndex];
                if (selectedOpt.style.display === 'none') {
                    subcatSelect.value = '0';
                }
            }

            // 自动提交
            filterForm.submit();
        });

        // 小分类变化 → 自动提交
        subcatSelect.addEventListener('change', function() {
            filterForm.submit();
        });

        // 搜索框回车自动提交（保留默认行为）
        // 点击搜索按钮也提交
    })();
    </script>
    <?php endif; ?>
</body>
</html>
