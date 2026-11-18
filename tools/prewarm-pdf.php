<?php
/**
 * 说明书 PDF 后台预热（系统 cron 调用，CLI only）
 *
 * 用法：
 *   /www/server/php/82/bin/php tools/prewarm-pdf.php [每次生成篇数]
 * 示例（每次最多生成 2 篇）：
 *   /www/server/php/82/bin/php tools/prewarm-pdf.php 2
 *
 * 建议以 www 用户运行，保证生成的缓存文件归属正确：
 *   runuser -u www -- /www/server/php/82/bin/php /www/wwwroot/lisungroup.com/wp-content/plugins/lisun-manual/tools/prewarm-pdf.php 2
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit('CLI only');
}

// 从 plugins/lisun-manual/tools/ 上溯 4 层即站点根目录，定位 wp-load.php
require dirname(__DIR__, 4) . '/wp-load.php';

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 2;

if (!class_exists('Lisun_Manual_Pdf_Download')) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] 错误：未找到 Lisun_Manual_Pdf_Download 类\n");
    exit(1);
}

$r = Lisun_Manual_Pdf_Download::prewarm($limit);
echo '[' . date('Y-m-d H:i:s') . '] ' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(0);
