<?php
/**
 * 说明书 PDF 下载（2026-11-09 新增）
 * 详情页「下载 PDF」按钮 -> 服务端用 chrome-headless-shell 按 @page 打印版式生成 -> 缓存 -> 附件流下载
 *
 * 流程（利用 template_redirect 优先级，天然复用密码门的授权判定）：
 *   1. 浏览器点击按钮     GET /manual/slug/?lisun_pdf=1
 *      优先级 1 的 Lisun_Manual_Access_Control::access_check 先跑，未授权者只会看到登录页；
 *   2. 优先级 2（本类）：已通过密码门 -> 签发 120 秒渲染令牌并 302 回跳；
 *   3. 优先级 2（本类）：带有效令牌 -> 命中缓存或现场生成 -> 以 attachment 下发；
 *   4. chrome 渲染时带 lisun_pdf_render=1&令牌，优先级 0 校验通过后摘掉密码门，
 *      让无 cookie 的 chrome 能拿到完整页面（仅允许 127.0.0.1 本机发起）。
 *
 * 缓存：wp-content/uploads/lisun-pdf/{post_id}-{md5}.pdf，正文更新后自动重生成。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Pdf_Download {

    const TOKEN_TTL  = 120;   // 渲染令牌有效期（秒）
    const Q_DOWNLOAD = 'lisun_pdf';
    const Q_TOKEN    = 'lisun_pdf_token';
    const Q_RENDER   = 'lisun_pdf_render';
    /* 2026-11-09 多站部署：默认路径仅作兜底，可用 wp-config.php 的
       LISUN_MANUAL_CHROME_BIN 常量覆盖，或 filter lisun_manual_chrome_bin。
       取用请走 self::chrome_bin()，不要再直接引用本常量。 */
    const CHROME_DEFAULT = '/opt/lisun-pdf-poc/chrome-headless-shell-linux64/chrome-headless-shell';
    /* 2026-11-09 缓存版本：生成逻辑变化时 +1，旧缓存自动失效。
       v2 = 加入 PDF 书签（--generate-pdf-document-outline） */
    const CACHE_VER  = 2;
    /* 2026-11-09 全局排队：同时只允许 1 份生成（单份峰值约 1GB），
       排队等待上限（秒），超时直接失败并让前端提示重试 */
    const GEN_WAIT   = 90;

    private static $last_error = '';

    /**
     * 2026-11-09 全局生成锁：串行化所有文章的 chrome 生成，避免并发吃光内存。
     * 拿不到锁文件时降级为不排队（宁可变慢，不可整体不可用）。
     *
     * @return resource|null 锁句柄，null 表示未取得（超时或无法创建）
     */
    private static function acquire_global_lock($dir) {
        $fp = @fopen($dir . '/.generation.lock', 'c');
        if (!$fp) {
            return null;
        }
        $deadline = microtime(true) + self::GEN_WAIT;
        while (!flock($fp, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($fp);
                return null;
            }
            usleep(300000); // 0.3s 轮询
        }
        return $fp;
    }

    private static function release_global_lock($fp) {
        if (!$fp) {
            return;
        }
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /** 实际使用的 chrome 可执行文件路径（多站部署时可覆盖） */
    private static function chrome_bin() {
        if (defined('LISUN_MANUAL_CHROME_BIN') && LISUN_MANUAL_CHROME_BIN) {
            return (string) LISUN_MANUAL_CHROME_BIN;
        }
        return (string) apply_filters('lisun_manual_chrome_bin', self::CHROME_DEFAULT);
    }

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'maybe_render_page'], 0);
        add_action('template_redirect', [__CLASS__, 'maybe_download'], 2);
    }

    /* ---------------- chrome 渲染通道 ---------------- */

    /**
     * chrome 无 cookie 拉取页面：带有效令牌且来自本机时，摘掉密码门
     */
    public static function maybe_render_page() {
        if (!isset($_GET[self::Q_RENDER]) || !is_singular('manual')) {
            return;
        }
        if (!self::token_valid($_GET[self::Q_TOKEN] ?? '', get_the_ID(), true)) {
            return;
        }
        remove_action('template_redirect', ['Lisun_Manual_Access_Control', 'access_check'], 1);
        nocache_headers();
    }

    /* ---------------- 下载通道 ---------------- */

    public static function maybe_download() {
        if (!isset($_GET[self::Q_DOWNLOAD]) || !is_singular('manual')) {
            return;
        }
        $post_id = get_the_ID();
        $token   = (string) ($_GET[self::Q_TOKEN] ?? '');

        if (!self::token_valid($token, $post_id, false)) {
            // 走到这里说明已通过优先级 1 的密码门 -> 放心签发令牌
            // 注意：回跳必须同时带上 lisun_pdf=1，否则第二跳会被当成普通页面渲染
            $token = wp_generate_password(40, false, false);
            set_transient('lisun_pdf_t_' . md5($token), ['pid' => (int) $post_id], self::TOKEN_TTL);
            wp_safe_redirect(add_query_arg([
                self::Q_DOWNLOAD => '1',
                self::Q_TOKEN    => $token,
            ], get_permalink($post_id)), 302);
            exit;
        }

        $file = self::ensure_pdf($post_id, $token);
        if (!$file) {
            wp_die(
                esc_html(self::$last_error ?: 'PDF 生成失败，请稍后重试 / PDF generation failed.'),
                'PDF 生成失败',
                ['response' => 503]
            );
        }
        self::send_file($file, $post_id);
    }

    private static function token_valid($token, $post_id, $require_local) {
        if (!$token || strlen($token) < 20) {
            return false;
        }
        $data = get_transient('lisun_pdf_t_' . md5($token));
        if (!$data || (int) ($data['pid'] ?? 0) !== (int) $post_id) {
            return false;
        }
        if ($require_local) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
                return false;
            }
        }
        return true;
    }

    /* ---------------- 生成与缓存 ---------------- */

    private static function ensure_pdf($post_id, $token) {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . 'lisun-pdf';
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        // 禁止从 Web 直接访问缓存目录
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }

        $lang  = (string) get_post_meta($post_id, '_lisun_manual_lang', true);
        $key   = md5($post_id . '|' . $lang . '|' . home_url() . '|v' . self::CACHE_VER);
        $file  = $dir . '/' . (int) $post_id . '-' . $key . '.pdf';
        $fresh = (int) get_post_modified_time('U', true);

        if (file_exists($file) && filemtime($file) >= $fresh) {
            return $file;
        }

        // flock 防并发重复生成：后来的请求等锁，拿到锁后先看缓存是否已就绪
        $lock = @fopen($file . '.lock', 'w');
        if ($lock) {
            flock($lock, LOCK_EX);
            if (file_exists($file) && filemtime($file) >= $fresh) {
                flock($lock, LOCK_UN);
                fclose($lock);
                return $file;
            }
        }

        /* 2026-11-09 排队：不同文章之间也串行，防止并发 chrome 打爆内存 */
        $global = self::acquire_global_lock($dir);
        if (!$global) {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            self::$last_error = '服务器正在生成其他说明书，排队超时，请稍后重试';
            return false;
        }

        $ok = self::generate($post_id, $token, $file);
        self::release_global_lock($global);

        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        /* 2026-11-09 清掉同文章的历史缓存（版本升级或正文改动后残留，单份可达数 MB） */
        if ($ok) {
            foreach ((array) glob($dir . '/' . (int) $post_id . '-*.pdf') as $stale) {
                if ($stale !== $file) {
                    @unlink($stale);
                }
            }
        }

        return $ok ? $file : false;
    }

    private static function generate($post_id, $token, $file) {
        $chrome = self::chrome_bin();
        if (!file_exists($chrome)) {
            self::$last_error = '服务器未找到 chrome-headless-shell：' . $chrome
                . '（可用 wp-config.php 的 LISUN_MANUAL_CHROME_BIN 指定实际路径）';
            return false;
        }
        // 宝塔面板常禁用 exec/putenv/proc_open（安全设置）。缺了就无法启动 chrome。
        // putenv 缺失只影响 HOME 环境变量（用 --user-data-dir 兜底），exec 缺失则无法生成。
        if (!function_exists('exec')) {
            self::$last_error = '站点 PHP 已禁用 exec()（宝塔「PHP 禁用函数」），无法调用 chrome 生成 PDF。'
                . '请在面板解除 exec（建议连同 putenv、proc_open）后重试。';
            return false;
        }
        if (function_exists('putenv')) {
            putenv('HOME=' . sys_get_temp_dir());
        }

        $permalink = get_permalink($post_id);
        $host      = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $tmp       = $file . '.tmp.pdf';
        $profile   = sys_get_temp_dir() . '/lisun-chrome-' . getmypid();
        $render    = add_query_arg([self::Q_RENDER => '1', self::Q_TOKEN => $token], $permalink);

        $cmd = escapeshellarg($chrome)
            . ' --headless --no-sandbox --disable-dev-shm-usage --hide-scrollbars'
            . ' --no-pdf-header-footer --run-all-compositor-stages-before-draw'
            /* 2026-11-09 PDF 书签：按标题层级（h1-h6）生成大纲，Chrome 126+ 支持；
               低版本 chrome 忽略该开关，不影响生成 */
            . ' --generate-pdf-document-outline'
            . ' --user-data-dir=' . escapeshellarg($profile)
            . ' --host-resolver-rules=' . escapeshellarg('MAP ' . $host . ' 127.0.0.1')
            . ' --ignore-certificate-errors'
            . ' --print-to-pdf=' . escapeshellarg($tmp)
            . ' ' . escapeshellarg($render)
            . ' 2>&1';

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        exec($cmd, $out, $code);
        self::rrmdir($profile);

        // 有效 PDF 至少几 KB；失败或空文件都视为生成失败
        if ($code !== 0 || !file_exists($tmp) || filesize($tmp) < 2000) {
            @unlink($tmp);
            return false;
        }
        rename($tmp, $file);
        return true;
    }

    private static function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $item) {
            is_dir($item) ? self::rrmdir($item) : @unlink($item);
        }
        @rmdir($dir);
    }

    /* ---------------- 下发 ---------------- */

    private static function send_file($file, $post_id) {
        /* 2026-11-09 文件名带说明书后缀：中文「产品名 - 操作使用说明书」，
           英文「产品名 - User's Manual」。语言判断与 single-manual.php 一致：
           _lisun_manual_lang -> lisun_page_lang -> zh。
           filename* 用原始名（rawurlencode 保证合法）；ASCII 回退单独清洗。 */
        $title     = get_the_title($post_id);
        $post_lang = get_post_meta($post_id, '_lisun_manual_lang', true);
        $site_lang = get_option('lisun_page_lang', '');
        $lang      = in_array($post_lang, array('zh', 'en'), true) ? $post_lang
                   : (in_array($site_lang, array('zh', 'en'), true) ? $site_lang : 'zh');
        $suffix    = $lang === 'en' ? "User's Manual" : '操作使用说明书';
        $name      = $title . ' - ' . $suffix;
        $ascii     = trim(preg_replace('/[^A-Za-z0-9._-]/', '_', sanitize_file_name($name)), '_-');
        if ($ascii === '' || $ascii === '.pdf') {
            $ascii = 'lisun-manual-' . (int) $post_id;
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $ascii . '.pdf"; filename*=UTF-8\'\'' . rawurlencode($name . '.pdf'));
        header('Content-Length: ' . (string) filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}
