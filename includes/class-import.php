<?php
/**
 * 批量导入模块
 * 处理：上传图片 → 管理映射 → 上传 Word → 解析导入
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-table-layout.php';
require_once __DIR__ . '/class-product-video-compat.php';

class Lisun_Manual_Import {

    use Lisun_Manual_Nonce_Trait;

    /**
     * 允许的图片扩展名
     */
    const ALLOWED_IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * 允许的文档扩展名
     */
    const ALLOWED_DOC_EXT = ['doc', 'docx'];

    /**
     * 初始化
     */
    public static function init() {
        // AJAX: 上传图片
        add_action('wp_ajax_lisun_upload_images', [__CLASS__, 'ajax_upload_images']);
        // AJAX: 检查已存在的图片（断点续传用）
        add_action('wp_ajax_lisun_check_existing_images', [__CLASS__, 'ajax_check_existing_images']);
        // AJAX: 上传 ZIP
        add_action('wp_ajax_lisun_upload_zip', [__CLASS__, 'ajax_upload_zip']);
        // AJAX: 删除图片映射
        add_action('wp_ajax_lisun_delete_img_map', [__CLASS__, 'ajax_delete_img_map']);
        // AJAX: 手动编辑映射
        add_action('wp_ajax_lisun_update_img_map', [__CLASS__, 'ajax_update_img_map']);
        // AJAX: 上传 Word 文件
        add_action('wp_ajax_lisun_upload_docs', [__CLASS__, 'ajax_upload_docs']);
        // AJAX: 检查已存在的 Word 文件（断点续传用）
        add_action('wp_ajax_lisun_check_existing_docs', [__CLASS__, 'ajax_check_existing_docs']);
        // AJAX: 获取已上传 Word 文件列表
        add_action('wp_ajax_lisun_get_uploaded_docs', [__CLASS__, 'ajax_get_uploaded_docs']);
        // AJAX: 移除已上传的 Word 文件
        add_action('wp_ajax_lisun_remove_uploaded_doc', [__CLASS__, 'ajax_remove_uploaded_doc']);
        // AJAX: 分片上传 Word 文件（接收单个分片）
        add_action('wp_ajax_lisun_upload_doc_chunk', [__CLASS__, 'ajax_upload_doc_chunk']);
        // AJAX: 查询分片上传状态（断点续传用）
        add_action('wp_ajax_lisun_upload_doc_chunk_status', [__CLASS__, 'ajax_upload_doc_chunk_status']);
        // AJAX: 解析 Word 文件（同步版，小文件用）
        add_action('wp_ajax_lisun_parse_doc', [__CLASS__, 'ajax_parse_doc']);
        // AJAX: 解析 Word 文件（异步版，大文件用 — 立刻返回 job_id，后台处理）
        add_action('wp_ajax_lisun_parse_doc_async', [__CLASS__, 'ajax_parse_doc_async']);
        // AJAX: 查询异步解析进度
        add_action('wp_ajax_lisun_parse_status', [__CLASS__, 'ajax_parse_status']);
        // AJAX: 执行导入
        add_action('wp_ajax_lisun_do_import', [__CLASS__, 'ajax_do_import']);
        // AJAX: 执行导入（异步版）
        add_action('wp_ajax_lisun_do_import_async', [__CLASS__, 'ajax_do_import_async']);
        // AJAX: 一键批量导入
        add_action('wp_ajax_lisun_batch_import', [__CLASS__, 'ajax_batch_import']);
        // AJAX: 获取导入/解析进度
        add_action('wp_ajax_lisun_import_status', [__CLASS__, 'ajax_import_status']);
        // AJAX: 在文章详情页手动生成 SEO
        add_action('wp_ajax_lisun_generate_manual_seo', [__CLASS__, 'ajax_generate_manual_seo']);
    }

    /**
     * 导入时统一处理表格宽度和布局标记。
     *
     * 宽度写入正文 HTML，后台编辑器打开新文章时也能立即显示为满宽；
     * 布局标记则与前台动态处理、同步推送保持一致。
     *
     * @param string $content
     * @return string
     */
    private static function prepare_import_content($content) {
        $content = Lisun_Manual_Table_Layout::apply_table_widths($content);
        return Lisun_Manual_Table_Layout::apply_to_content($content);
    }

    // ==================== 步骤 1：上传图片 ====================

    /**
     * AJAX: 上传图片文件
     */
    public static function ajax_upload_images() {
        self::check_nonce();

        if (empty($_FILES['images'])) {
            // 区分"未选文件"和"post_max_size 截断"
            $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($content_length > 0) {
                wp_send_json_error([
                    'message' => __('上传请求体过大，被服务器拒绝。请减少每批上传的文件数量，或联系主机提供商增大 post_max_size。', 'lisun-manual'),
                    'code'    => 'post_max_size_exceeded',
                ]);
            }
            wp_send_json_error(['message' => __('未收到文件', 'lisun-manual')]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $files = self::normalize_files_array($_FILES['images']);
        $results = [];
        $img_maps = get_option('lisun_img_maps', []);
        $skip_existing = !empty($_POST['skip_existing']);

        foreach ($files as $file) {
            // 零字节文件检查
            if (empty($file['size'])) {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'error',
                    'message'  => __('文件为空', 'lisun-manual'),
                ];
                continue;
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, self::ALLOWED_IMG_EXT)) {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'error',
                    'message'  => __('不支持的图片格式', 'lisun-manual'),
                ];
                continue;
            }

            // 断点续传：跳过已有映射的文件
            if ($skip_existing && isset($img_maps[$file['name']])) {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'skipped',
                    'message'  => __('文件已存在，已跳过', 'lisun-manual'),
                ];
                continue;
            }

            // 计算文件哈希，用于内容去重
            $file_hash = md5_file($file['tmp_name']);

            // 按内容哈希查找是否已有相同图片（不同文件名但内容相同）
            $existing_attachment_id = null;
            foreach ($img_maps as $existing) {
                if (!empty($existing['hash']) && $existing['hash'] === $file_hash && !empty($existing['attachment_id'])) {
                    $existing_attachment_id = $existing['attachment_id'];
                    break;
                }
            }

            if ($existing_attachment_id) {
                // 内容相同，复用已有附件，只更新文件名映射
                $url   = wp_get_attachment_url($existing_attachment_id);
                $thumb = wp_get_attachment_image_url($existing_attachment_id, 'thumbnail');

                $img_maps[$file['name']] = [
                    'attachment_id' => $existing_attachment_id,
                    'url'           => $url,
                    'thumb'         => $thumb,
                    'hash'          => $file_hash,
                ];

                $results[] = [
                    'filename'      => $file['name'],
                    'status'        => 'success',
                    'attachment_id' => $existing_attachment_id,
                    'url'           => $url,
                    'thumb'         => $thumb,
                    'reused'        => true,
                ];
                continue;
            }

            // 上传到媒体库
            $attachment_id = media_handle_sideload($file, 0);

            if (is_wp_error($attachment_id)) {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'error',
                    'message'  => $attachment_id->get_error_message(),
                ];
                continue;
            }

            $url = wp_get_attachment_url($attachment_id);
            $thumb = wp_get_attachment_image_url($attachment_id, 'thumbnail');

            // $file_hash 已在上方计算，此处直接使用
            // 保存映射
            $img_maps[$file['name']] = [
                'attachment_id' => $attachment_id,
                'url'           => $url,
                'thumb'         => $thumb,
                'hash'          => $file_hash,
            ];

            $results[] = [
                'filename'      => $file['name'],
                'status'        => 'success',
                'attachment_id' => $attachment_id,
                'url'           => $url,
                'thumb'         => $thumb,
            ];
        }

        update_option('lisun_img_maps', $img_maps);

        wp_send_json_success([
            'results' => $results,
            'total'   => count($img_maps),
        ]);
    }

    /**
     * AJAX: 检查哪些文件名已存在于映射表（断点续传用）
     */
    public static function ajax_check_existing_images() {
        self::check_nonce();

        $filenames = $_POST['filenames'] ?? [];
        if (empty($filenames) || !is_array($filenames)) {
            wp_send_json_success(['existing' => []]);
        }

        $img_maps = get_option('lisun_img_maps', []);
        $existing = [];
        foreach ($filenames as $name) {
            $name = sanitize_file_name($name);
            if (isset($img_maps[$name])) {
                $existing[] = $name;
            }
        }

        wp_send_json_success(['existing' => $existing]);
    }

    /**
     * AJAX: 上传 ZIP 并解压
     */
    public static function ajax_upload_zip() {
        self::check_nonce();

        if (empty($_FILES['zip_file'])) {
            wp_send_json_error(['message' => __('未收到 ZIP 文件', 'lisun-manual')]);
        }

        $file = $_FILES['zip_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('文件上传失败，错误码：', 'lisun-manual') . $file['error']]);
        }

        if (!class_exists('ZipArchive')) {
            wp_send_json_error(['message' => __('服务器不支持 ZipArchive', 'lisun-manual')]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $zip = new ZipArchive();
        $open_result = $zip->open($file['tmp_name']);

        if ($open_result !== true) {
            wp_send_json_error(['message' => __('无法打开 ZIP 文件，错误码：', 'lisun-manual') . $open_result]);
        }

        $extract_dir = LISUN_MANUAL_PATH . 'temp/' . uniqid('zip_');
        wp_mkdir_p($extract_dir);
        $zip->extractTo($extract_dir);
        $zip->close();

        $results = [];
        $img_maps = get_option('lisun_img_maps', []);

        // 遍历解压文件
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extract_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $ext = strtolower($item->getExtension());
            if (!in_array($ext, self::ALLOWED_IMG_EXT)) {
                continue;
            }

            $filename = $item->getFilename();
            $tmp_path = $item->getPathname();

            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp_path,
                'error'    => 0,
                'size'     => $item->getSize(),
            ];

            $attachment_id = media_handle_sideload($file_array, 0);

            if (is_wp_error($attachment_id)) {
                $results[] = [
                    'filename' => $filename,
                    'status'   => 'error',
                    'message'  => $attachment_id->get_error_message(),
                ];
                continue;
            }

            $url = wp_get_attachment_url($attachment_id);
            $thumb = wp_get_attachment_image_url($attachment_id, 'thumbnail');

            $file_hash = md5_file($tmp_path);

            $img_maps[$filename] = [
                'attachment_id' => $attachment_id,
                'url'           => $url,
                'thumb'         => $thumb,
                'hash'          => $file_hash,
            ];

            $results[] = [
                'filename'      => $filename,
                'status'        => 'success',
                'attachment_id' => $attachment_id,
                'url'           => $url,
                'thumb'         => $thumb,
            ];
        }

        // 清理临时目录
        self::rmdir_recursive($extract_dir);
        update_option('lisun_img_maps', $img_maps);

        wp_send_json_success([
            'results' => $results,
            'total'   => count($img_maps),
        ]);
    }

    // ==================== 步骤 2：管理图片映射 ====================

    /**
     * AJAX: 删除图片映射
     */
    public static function ajax_delete_img_map() {
        self::check_nonce();

        $filename = sanitize_text_field($_POST['filename'] ?? '');
        if (empty($filename)) {
            wp_send_json_error(['message' => __('缺少文件名', 'lisun-manual')]);
        }

        $img_maps = get_option('lisun_img_maps', []);
        unset($img_maps[$filename]);
        update_option('lisun_img_maps', $img_maps);

        wp_send_json_success(['total' => count($img_maps)]);
    }

    /**
     * AJAX: 更新图片映射
     */
    public static function ajax_update_img_map() {
        self::check_nonce();

        $old_filename = sanitize_text_field($_POST['old_filename'] ?? '');
        $new_filename = sanitize_text_field($_POST['new_filename'] ?? '');
        $new_url = esc_url_raw($_POST['new_url'] ?? '');

        if (empty($old_filename) || empty($new_filename)) {
            wp_send_json_error(['message' => __('参数不完整', 'lisun-manual')]);
        }

        $img_maps = get_option('lisun_img_maps', []);

        if (isset($img_maps[$old_filename])) {
            $entry = $img_maps[$old_filename];
            unset($img_maps[$old_filename]);

            if ($new_url) {
                $entry['url'] = $new_url;
                // 尝试获取 attachment_id
                $entry['attachment_id'] = attachment_url_to_postid($new_url);
                $entry['thumb'] = $entry['attachment_id']
                    ? wp_get_attachment_image_url($entry['attachment_id'], 'thumbnail')
                    : $new_url;
            }

            $img_maps[$new_filename] = $entry;
        }

        update_option('lisun_img_maps', $img_maps);

        wp_send_json_success(['total' => count($img_maps)]);
    }

    // ==================== 步骤 3：上传 Word 文件 ====================

    /**
     * AJAX: 上传 Word 文件
     */
    public static function ajax_upload_docs() {
        self::check_nonce();

        if (empty($_FILES['docs'])) {
            // 区分"未选文件"和"post_max_size 截断"
            $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($content_length > 0) {
                wp_send_json_error([
                    'message' => __('上传请求体过大，被服务器拒绝。请减少每批上传的文件数量，或联系主机提供商增大 post_max_size。', 'lisun-manual'),
                    'code'    => 'post_max_size_exceeded',
                ]);
            }
            wp_send_json_error(['message' => __('未收到文件', 'lisun-manual')]);
        }

        $files = self::normalize_files_array($_FILES['docs']);
        $upload_dir = LISUN_MANUAL_PATH . 'uploads/';
        wp_mkdir_p($upload_dir);

        // 读取已有的上传列表
        $uploaded_docs = get_option('lisun_uploaded_docs', []);
        $skip_existing = !empty($_POST['skip_existing']);
        $results = [];

        // 构建已存在文件名集合（用于断点续传跳过）
        $existing_names = [];
        foreach ($uploaded_docs as $doc) {
            $existing_names[] = $doc['filename'];
        }

        foreach ($files as $file) {
            // 零字节文件检查
            if (empty($file['size'])) {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'error',
                    'message'  => __('文件为空', 'lisun-manual'),
                ];
                continue;
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, self::ALLOWED_DOC_EXT)) {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'error',
                    'message'  => __('不支持的文档格式', 'lisun-manual'),
                ];
                continue;
            }

            // 断点续传：跳过已有同名文件
            if ($skip_existing && in_array($file['name'], $existing_names)) {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'skipped',
                    'message'  => __('文件已存在，已跳过', 'lisun-manual'),
                ];
                continue;
            }

            $stored_name = uniqid('doc_') . '.' . $ext;
            $stored_path = $upload_dir . $stored_name;

            if (move_uploaded_file($file['tmp_name'], $stored_path)) {
                $doc_info = [
                    'filename'    => $file['name'],
                    'stored_name' => $stored_name,
                    'stored_path' => $stored_path,
                    'uploaded_at' => current_time('mysql'),
                ];

                $results[] = array_merge($doc_info, ['status' => 'success']);

                // 持久化：存入 option
                $uploaded_docs[$stored_name] = $doc_info;
            } else {
                $results[] = [
                    'filename' => $file['name'],
                    'status'   => 'error',
                    'message'  => __('文件保存失败', 'lisun-manual'),
                ];
            }
        }

        // 保存到 option
        update_option('lisun_uploaded_docs', $uploaded_docs);

        wp_send_json_success(['results' => $results]);
    }

    /**
     * AJAX: 分片上传 — 接收单个二进制分片，收齐后拼接为完整文件
     */
    public static function ajax_upload_doc_chunk() {
        self::check_nonce();

        $filename     = sanitize_file_name($_POST['filename'] ?? '');
        $upload_id    = sanitize_key($_POST['upload_id'] ?? '');
        $chunk_index  = (int) ($_POST['chunk_index'] ?? -1);
        $total_chunks = (int) ($_POST['total_chunks'] ?? 0);

        if (empty($filename) || empty($upload_id) || $chunk_index < 0 || $total_chunks <= 0) {
            wp_send_json_error(['message' => __('缺少必要参数', 'lisun-manual')]);
        }

        // 验证扩展名
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_DOC_EXT)) {
            wp_send_json_error(['message' => __('不支持的文档格式', 'lisun-manual')]);
        }

        // 断点续传：跳过已有同名文件（整个文件级别）
        $skip_existing = !empty($_POST['skip_existing']);
        if ($skip_existing && $chunk_index === 0) {
            $uploaded_docs = get_option('lisun_uploaded_docs', []);
            foreach ($uploaded_docs as $doc) {
                if (isset($doc['filename']) && $doc['filename'] === $filename) {
                    wp_send_json_success([
                        'assembled'       => true,
                        'received_chunks' => 1,
                        'total_chunks'    => 1,
                        'result'          => [
                            'filename' => $filename,
                            'status'   => 'skipped',
                            'message'  => __('文件已存在，已跳过', 'lisun-manual'),
                        ],
                    ]);
                }
            }
        }

        // 检查分片文件
        if (empty($_FILES['chunk_data'])) {
            $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($content_length > 0) {
                wp_send_json_error([
                    'message' => __('上传请求体过大，被服务器拒绝。', 'lisun-manual'),
                    'code'    => 'post_max_size_exceeded',
                ]);
            }
            wp_send_json_error(['message' => __('未收到分片数据', 'lisun-manual')]);
        }

        $chunk_file = $_FILES['chunk_data'];
        if ($chunk_file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('分片上传失败，错误码：', 'lisun-manual') . $chunk_file['error']]);
        }

        // 清理超过 24 小时的旧临时目录
        self::cleanup_stale_chunks();

        // 创建分片临时目录
        $chunk_dir = LISUN_MANUAL_PATH . 'temp/chunks/' . $upload_id . '/';
        wp_mkdir_p($chunk_dir);

        // 保存分片
        $chunk_name = 'chunk_' . str_pad($chunk_index, 5, '0', STR_PAD_LEFT);
        $chunk_path = $chunk_dir . $chunk_name;

        if (!move_uploaded_file($chunk_file['tmp_name'], $chunk_path)) {
            wp_send_json_error(['message' => __('分片保存失败', 'lisun-manual')]);
        }

        // 首片写入元数据文件（用于断点续传查询）
        $meta_file = $chunk_dir . 'meta.json';
        if (!file_exists($meta_file)) {
            file_put_contents($meta_file, wp_json_encode([
                'filename'     => $filename,
                'total_chunks' => $total_chunks,
                'started_at'   => current_time('mysql'),
            ]));
        }

        // 统计已接收分片数
        $received_chunks = count(glob($chunk_dir . 'chunk_*'));

        // 尚未收齐，继续等待
        if ($received_chunks < $total_chunks) {
            wp_send_json_success([
                'assembled'       => false,
                'received_chunks' => $received_chunks,
                'total_chunks'    => $total_chunks,
            ]);
        }

        // ---- 收齐：原子性拼接（文件锁防止并发重复拼接） ----
        $lock_file = $chunk_dir . 'assembling.lock';
        $lock_fp = @fopen($lock_file, 'x'); // 'x' = 排他创建，已存在则失败
        if (!$lock_fp) {
            // 另一请求正在拼接，返回"继续等待"让客户端一会重试
            wp_send_json_success([
                'assembled'       => false,
                'received_chunks' => $received_chunks,
                'total_chunks'    => $total_chunks,
            ]);
        }

        $upload_dir  = LISUN_MANUAL_PATH . 'uploads/';
        wp_mkdir_p($upload_dir);

        $stored_name = uniqid('doc_') . '.' . $ext;
        $stored_path = $upload_dir . $stored_name;

        $out_fp = fopen($stored_path, 'wb');
        if (!$out_fp) {
            fclose($lock_fp);
            @unlink($lock_file);
            wp_send_json_error(['message' => __('无法创建目标文件', 'lisun-manual')]);
        }

        for ($i = 0; $i < $total_chunks; $i++) {
            $chunk_path_i = $chunk_dir . 'chunk_' . str_pad($i, 5, '0', STR_PAD_LEFT);
            if (!file_exists($chunk_path_i)) {
                fclose($out_fp);
                @unlink($stored_path);
                fclose($lock_fp);
                @unlink($lock_file);
                wp_send_json_error(['message' => __('缺少分片 ', 'lisun-manual') . $i]);
            }
            $in_fp = fopen($chunk_path_i, 'rb');
            if (!$in_fp) {
                fclose($out_fp);
                @unlink($stored_path);
                fclose($lock_fp);
                @unlink($lock_file);
                wp_send_json_error(['message' => __('无法读取分片 ', 'lisun-manual') . $i]);
            }
            while (!feof($in_fp)) {
                fwrite($out_fp, fread($in_fp, 8192));
            }
            fclose($in_fp);
        }
        fclose($out_fp);

        // 验证拼接结果
        if (filesize($stored_path) === 0) {
            @unlink($stored_path);
            self::rmdir_recursive($chunk_dir);
            fclose($lock_fp);
            @unlink($lock_file);
            wp_send_json_error(['message' => __('拼接后文件为空', 'lisun-manual')]);
        }

        // 记录到 option
        $uploaded_docs = get_option('lisun_uploaded_docs', []);
        $doc_info = [
            'filename'    => $filename,
            'stored_name' => $stored_name,
            'stored_path' => $stored_path,
            'uploaded_at' => current_time('mysql'),
        ];
        $uploaded_docs[$stored_name] = $doc_info;
        update_option('lisun_uploaded_docs', $uploaded_docs);

        // 清理分片临时目录（含锁文件）
        fclose($lock_fp);
        self::rmdir_recursive($chunk_dir);

        wp_send_json_success([
            'assembled'       => true,
            'received_chunks' => $total_chunks,
            'total_chunks'    => $total_chunks,
            'result'          => array_merge($doc_info, ['status' => 'success']),
        ]);
    }

    /**
     * AJAX: 查询分片上传状态（断点续传用）
     */
    public static function ajax_upload_doc_chunk_status() {
        self::check_nonce();

        $upload_id = sanitize_key($_POST['upload_id'] ?? '');
        if (empty($upload_id)) {
            wp_send_json_error(['message' => __('缺少 upload_id', 'lisun-manual')]);
        }

        $chunk_dir = LISUN_MANUAL_PATH . 'temp/chunks/' . $upload_id . '/';

        $received_indexes = [];
        $total_chunks     = 0;
        $filename         = '';

        if (is_dir($chunk_dir)) {
            // 读取元数据
            $meta_file = $chunk_dir . 'meta.json';
            if (file_exists($meta_file)) {
                $meta = json_decode(file_get_contents($meta_file), true);
                if ($meta) {
                    $total_chunks = (int) ($meta['total_chunks'] ?? 0);
                    $filename     = $meta['filename'] ?? '';
                }
            }

            // 收集已接收的分片索引
            $chunk_files = glob($chunk_dir . 'chunk_*');
            if ($chunk_files) {
                foreach ($chunk_files as $cf) {
                    $basename = basename($cf);
                    if (preg_match('/^chunk_(\d+)$/', $basename, $m)) {
                        $received_indexes[] = (int) $m[1];
                    }
                }
                sort($received_indexes);
            }
        }

        wp_send_json_success([
            'received_indexes' => $received_indexes,
            'total_chunks'     => $total_chunks,
            'filename'         => $filename,
        ]);
    }

    /**
     * 检查哪些 Word 文件名已存在（断点续传用）
     */
    public static function ajax_check_existing_docs() {
        self::check_nonce();

        $filenames = $_POST['filenames'] ?? [];
        if (empty($filenames) || !is_array($filenames)) {
            wp_send_json_success(['existing' => []]);
        }

        $uploaded_docs = get_option('lisun_uploaded_docs', []);
        $existing_names = [];
        foreach ($uploaded_docs as $doc) {
            $existing_names[] = $doc['filename'];
        }

        $existing = [];
        foreach ($filenames as $name) {
            $name = sanitize_file_name($name);
            if (in_array($name, $existing_names)) {
                $existing[] = $name;
            }
        }

        wp_send_json_success(['existing' => $existing]);
    }

    /**
     * AJAX: 获取已上传的 Word 文件列表
     */
    public static function ajax_get_uploaded_docs() {
        self::check_nonce();
        $uploaded_docs = get_option('lisun_uploaded_docs', []);

        // 过滤掉磁盘上已不存在的文件
        $valid = [];
        foreach ($uploaded_docs as $key => $doc) {
            if (file_exists($doc['stored_path'])) {
                $valid[$key] = $doc;
            }
        }

        if (count($valid) !== count($uploaded_docs)) {
            update_option('lisun_uploaded_docs', $valid);
        }

        wp_send_json_success(['docs' => array_values($valid)]);
    }

    /**
     * AJAX: 移除已上传的 Word 文件
     */
    public static function ajax_remove_uploaded_doc() {
        self::check_nonce();

        $stored_name = sanitize_text_field($_POST['stored_name'] ?? '');
        if (empty($stored_name)) {
            wp_send_json_error(['message' => __('缺少文件名', 'lisun-manual')]);
        }

        $uploaded_docs = get_option('lisun_uploaded_docs', []);

        if (isset($uploaded_docs[$stored_name])) {
            $filepath = $uploaded_docs[$stored_name]['stored_path'];
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            unset($uploaded_docs[$stored_name]);
            update_option('lisun_uploaded_docs', $uploaded_docs);
        }

        wp_send_json_success(['total' => count($uploaded_docs)]);
    }

    // ==================== 步骤 4：解析与导入 ====================

    /**
     * AJAX: 解析 Word 文件（预览）
     */
    public static function ajax_parse_doc() {
        self::check_nonce();
        @set_time_limit(300);  // 预览解析允许 5 分钟（含 Python 预处理 + PhpWord）

        $stored_name = sanitize_text_field($_POST['stored_name'] ?? '');
        if (empty($stored_name)) {
            wp_send_json_error(['message' => __('缺少文件名', 'lisun-manual')]);
        }

        $filepath = LISUN_MANUAL_PATH . 'uploads/' . basename($stored_name);

        if (!file_exists($filepath)) {
            wp_send_json_error(['message' => __('文件不存在', 'lisun-manual')]);
        }

        if (!class_exists('Lisun_Manual_Import_Parser')) {
            wp_send_json_error(['message' => __('解析器不可用，请确认已安装 PhpOffice/PhpWord', 'lisun-manual')]);
        }

        ob_start();
        try {
            $parser = new Lisun_Manual_Import_Parser();
            $result = $parser->parse($filepath);
            ob_clean();
            wp_send_json_success($result);
        } catch (Exception $e) {
            ob_clean();
            wp_send_json_error(['message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            ob_clean();
            wp_send_json_error(['message' => 'Parse error: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX: 异步解析 Word 文件（大文件友好 — 立刻返回 job_id）
     *
     * 返回 {job_id, status: 'processing'} 后继续在后台解析，
     * 前端轮询 lisun_parse_status 获取结果。
     * 如果 fastcgi_finish_request 不可用，降级为同步处理。
     */
    public static function ajax_parse_doc_async() {
        self::check_nonce();

        $stored_name = sanitize_text_field($_POST['stored_name'] ?? '');
        if (empty($stored_name)) {
            wp_send_json_error(['message' => __('缺少文件名', 'lisun-manual')]);
        }

        $filepath = LISUN_MANUAL_PATH . 'uploads/' . basename($stored_name);
        if (!file_exists($filepath)) {
            wp_send_json_error(['message' => __('文件不存在', 'lisun-manual')]);
        }

        if (!class_exists('Lisun_Manual_Import_Parser')) {
            wp_send_json_error(['message' => __('解析器不可用', 'lisun-manual')]);
        }

        $job_id = uniqid('parse_', true);
        set_transient("lisun_parse_{$job_id}", [
            'status'      => 'processing',
            'started_at'  => current_time('mysql'),
            'stored_name' => $stored_name,
        ], 600);

        // 先返回 job_id 给前端，断开连接
        status_header(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo wp_json_encode(['success' => true, 'data' => ['job_id' => $job_id, 'status' => 'processing']]);
        while (ob_get_level()) ob_end_flush();
        flush();

        $can_async = function_exists('fastcgi_finish_request');
        if ($can_async) {
            session_write_close();
            fastcgi_finish_request();
        }

        // === 后台处理 ===
        @set_time_limit(300);
        @ignore_user_abort(true);

        try {
            $parser = new Lisun_Manual_Import_Parser();
            $result = $parser->parse($filepath);

            set_transient("lisun_parse_{$job_id}", [
                'status'      => 'done',
                'result'      => $result,
                'finished_at' => current_time('mysql'),
            ], 600);
        } catch (\Exception $e) {
            set_transient("lisun_parse_{$job_id}", [
                'status'      => 'error',
                'message'     => $e->getMessage(),
                'finished_at' => current_time('mysql'),
            ], 600);
        } catch (\Throwable $e) {
            set_transient("lisun_parse_{$job_id}", [
                'status'      => 'error',
                'message'     => 'Parse error: ' . $e->getMessage(),
                'finished_at' => current_time('mysql'),
            ], 600);
        }

        die();
    }

    /**
     * AJAX: 查询异步解析进度
     *
     * 前端每 2 秒轮询一次，直到 status === 'done' 或 'error'
     */
    public static function ajax_parse_status() {
        self::check_nonce();
        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        if (empty($job_id)) {
            wp_send_json_error(['message' => __('缺少 job_id', 'lisun-manual')]);
        }

        $job = get_transient("lisun_parse_{$job_id}");
        if (!$job) {
            wp_send_json_error(['message' => __('任务已过期或不存在', 'lisun-manual')]);
        }

        wp_send_json_success($job);
    }

    /**
     * AJAX: 异步执行导入（大文件友好 — 立刻返回 job_id）
     *
     * 和 ajax_do_import 逻辑一致，但从 fastcgi_finish_request 断开后在后台执行。
     */
    public static function ajax_do_import_async() {
        self::check_nonce();

        $stored_name = sanitize_text_field($_POST['stored_name'] ?? '');
        $post_title  = sanitize_text_field($_POST['post_title'] ?? '');
        if (empty($stored_name)) {
            wp_send_json_error(['message' => __('缺少文件名', 'lisun-manual')]);
        }

        $filepath = LISUN_MANUAL_PATH . 'uploads/' . basename($stored_name);
        if (!file_exists($filepath)) {
            wp_send_json_error(['message' => __('文件不存在', 'lisun-manual')]);
        }

        if (!class_exists('Lisun_Manual_Import_Parser')) {
            wp_send_json_error(['message' => __('解析器不可用', 'lisun-manual')]);
        }

        $job_id = uniqid('import_', true);
        set_transient("lisun_import_{$job_id}", [
            'status'      => 'processing',
            'started_at'  => current_time('mysql'),
            'stored_name' => $stored_name,
            'post_title'  => $post_title,
        ], 600);

        // 先返回 job_id
        status_header(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo wp_json_encode(['success' => true, 'data' => ['job_id' => $job_id, 'status' => 'processing']]);
        while (ob_get_level()) ob_end_flush();
        flush();

        $can_async = function_exists('fastcgi_finish_request');
        if ($can_async) {
            session_write_close();
            fastcgi_finish_request();
        }

        // === 后台处理 ===
        @set_time_limit(300);
        @ignore_user_abort(true);

        try {
            // 1. 解析
            $parser = new Lisun_Manual_Import_Parser();
            $parsed = $parser->parse($filepath);

            if (empty($parsed['blocks'])) {
                set_transient("lisun_import_{$job_id}", [
                    'status'      => 'error',
                    'message'     => __('解析结果为空', 'lisun-manual'),
                    'finished_at' => current_time('mysql'),
                ], 600);
                die();
            }

            // 2. 组装内容
            $post_content = self::prepare_import_content(implode("\n\n", $parsed['blocks']));

            // 确定标题：用户输入 > 解析出的标题 > 文件名
            $uploaded_docs = get_option('lisun_uploaded_docs', []);
            $original_name = $uploaded_docs[$stored_name]['filename'] ?? $stored_name;
            if (empty($post_title)) {
                $post_title = $parsed['title'] ?? '';
            }
            if (empty($post_title)) {
                $post_title = pathinfo($original_name, PATHINFO_FILENAME);
            }

            // 3. 创建 manual 文章
            $post_id = wp_insert_post([
                'post_title'   => $post_title,
                'post_content' => $post_content,
                'post_status'  => 'draft',
                'post_type'    => 'manual',
                'post_name'    => self::generate_filename_slug($original_name),
                'meta_input'   => [
                    '_lisun_manual_lang'        => 'zh',
                    '_lisun_manual_sync_status' => 'pending',
                ],
            ], true);
            if (is_wp_error($post_id)) {
                throw new \Exception($post_id->get_error_message());
            }

            // 保存原始 .docx 路径（用于后续翻译管线）
            self::save_docx_backup($post_id, $filepath);

            // 4. 自动标签
            $model_hint = self::extract_model_from_filename($parsed['original_filename'] ?? $original_name);
            self::auto_tag_post($post_id, $model_hint);
            if (!empty($model_hint)) {
                update_post_meta($post_id, 'product-no', $model_hint);
            }

            // 4.5. 从产品 CPT 同步操作视频
            self::sync_videos_from_product($post_id);

            // 5.5. 自动生成 SEO 三要素
            self::generate_seo_for_post($post_id);

            // 5. 中文导入完成 → 立即通知前端（翻译在后台并行，不阻塞）
            set_transient("lisun_import_{$job_id}", [
                'status'       => 'imported',
                'post_id'      => $post_id,
                'post_title'   => $post_title,
                'post_url'     => get_edit_post_link($post_id, 'raw'),
                'translating'  => Lisun_Manual_Translate::is_configured(),
                'finished_at'  => current_time('mysql'),
            ], 600);

            // 6. 清理上传记录
            if (isset($uploaded_docs[$stored_name])) {
                unset($uploaded_docs[$stored_name]);
                update_option('lisun_uploaded_docs', $uploaded_docs);
            }

            // 7. 后台翻译：通过独立 HTTP 请求触发（新 FPM worker，不被当前超时限制）
            if (Lisun_Manual_Translate::is_configured()) {
                self::fire_background_translate($post_id, $job_id);
            }
        } catch (\Exception $e) {
            set_transient("lisun_import_{$job_id}", [
                'status'      => 'error',
                'message'     => $e->getMessage(),
                'finished_at' => current_time('mysql'),
            ], 600);
        } catch (\Throwable $e) {
            set_transient("lisun_import_{$job_id}", [
                'status'      => 'error',
                'message'     => 'Import error: ' . $e->getMessage(),
                'finished_at' => current_time('mysql'),
            ], 600);
        }

        die();
    }

    /**
     * AJAX: 执行导入
     */
    public static function ajax_do_import() {
        self::check_nonce();

        // 单文件解析可能较耗时（Python 预处理 + PhpWord + 图片上传），放宽执行时间
        @set_time_limit(300);
        @ignore_user_abort(true);

        $stored_name = sanitize_text_field($_POST['stored_name'] ?? '');
        $post_title = sanitize_text_field($_POST['post_title'] ?? '');

        if (empty($stored_name)) {
            wp_send_json_error(['message' => __('缺少文件名', 'lisun-manual')]);
        }

        $filepath = LISUN_MANUAL_PATH . 'uploads/' . basename($stored_name);

        if (!file_exists($filepath)) {
            wp_send_json_error(['message' => __('文件不存在', 'lisun-manual')]);
        }

        if (!class_exists('Lisun_Manual_Import_Parser')) {
            wp_send_json_error(['message' => __('解析器不可用', 'lisun-manual')]);
        }

        ob_start();
        try {
            $parser = new Lisun_Manual_Import_Parser();
            $result = $parser->parse($filepath);

            if (empty($result['blocks'])) {
                ob_clean();
                wp_send_json_error(['message' => __('解析结果为空', 'lisun-manual')]);
            }

            // 组装 Gutenberg 内容
            $post_content = self::prepare_import_content(implode("\n\n", $result['blocks']));

            // 确定标题：用户输入 > 解析出的标题 > 文件名
            $uploaded_docs = get_option('lisun_uploaded_docs', []);
            $original_name = $uploaded_docs[$stored_name]['filename'] ?? $stored_name;
            if (empty($post_title)) {
                $post_title = $result['title'] ?? '';
            }
            if (empty($post_title)) {
                $post_title = pathinfo($original_name, PATHINFO_FILENAME);
            }

            // 创建 manual 页面（slug = 文件名 + 随机后缀）
            $post_id = wp_insert_post([
                'post_title'   => $post_title,
                'post_content' => $post_content,
                'post_status'  => 'draft',
                'post_type'    => 'manual',
                'post_name'    => self::generate_filename_slug($original_name),
                'meta_input'   => [
                    '_lisun_manual_lang'        => 'zh',
                    '_lisun_manual_sync_status' => 'pending',
                ],
            ]);

            if (is_wp_error($post_id)) {
                ob_clean();
                wp_send_json_error(['message' => $post_id->get_error_message()]);
            }

            // 保存原始 .docx 备份（翻译管线用）
            self::save_docx_backup($post_id, $filepath);

            // 自动匹配 Product Model 标签
            $uploaded_docs = get_option('lisun_uploaded_docs', []);
            $original_name = $uploaded_docs[$stored_name]['filename'] ?? $stored_name;
            $model_hint = self::extract_model_from_filename($original_name);
            if (!empty($model_hint)) {
                self::auto_tag_post($post_id, $model_hint);
                update_post_meta($post_id, 'product-no', $model_hint);
            }

            // 从产品 CPT 同步操作视频
            self::sync_videos_from_product($post_id);

            // 自动生成 SEO 三要素
            self::generate_seo_for_post($post_id);

            // 清理上传文件和 option 记录
            unlink($filepath);
            $uploaded_docs = get_option('lisun_uploaded_docs', []);
            unset($uploaded_docs[$stored_name]);
            update_option('lisun_uploaded_docs', $uploaded_docs);

            ob_clean();
            wp_send_json_success([
                'post_id'    => $post_id,
                'edit_url'   => get_edit_post_link($post_id, 'raw'),
                'view_url'   => get_permalink($post_id),
            ]);
        } catch (\Exception $e) {
            ob_clean();
            wp_send_json_error(['message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            ob_clean();
            wp_send_json_error(['message' => 'Import error: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX: 一键批量导入（跳过预览，直接解析+导入全部勾选文件）
     */
    public static function ajax_batch_import() {
        self::check_nonce();

        $stored_names = $_POST['stored_names'] ?? [];
        if (empty($stored_names) || !is_array($stored_names)) {
            wp_send_json_error(['message' => __('请选择要导入的文件', 'lisun-manual')]);
        }

        if (!class_exists('Lisun_Manual_Import_Parser')) {
            wp_send_json_error(['message' => __('解析器不可用', 'lisun-manual')]);
        }

        // 批量导入可能耗时较长，放宽执行时间
        set_time_limit(300);

        $uploaded_docs = get_option('lisun_uploaded_docs', []);
        $results = [];
        $parser = new Lisun_Manual_Import_Parser();

        // 顶层 ob_start，防止 PHP 警告污染 JSON 响应
        ob_start();

        foreach ($stored_names as $stored_name) {
            $stored_name = sanitize_text_field($stored_name);
            $filepath = LISUN_MANUAL_PATH . 'uploads/' . basename($stored_name);

            if (!file_exists($filepath)) {
                $results[] = [
                    'stored_name' => $stored_name,
                    'success'     => false,
                    'message'     => '文件不存在',
                ];
                continue;
            }

            try {
                $result = $parser->parse($filepath);

                if (empty($result['blocks'])) {
                    ob_clean();
                    $results[] = ['stored_name' => $stored_name, 'success' => false, 'message' => '解析结果为空'];
                    continue;
                }

                $post_content = self::prepare_import_content(implode("\n\n", $result['blocks']));

                // 自动生成标题：解析标题 > 文件名
                $original_name = $uploaded_docs[$stored_name]['filename'] ?? $stored_name;
                $post_title = $result['title'] ?? '';
                if (empty($post_title)) {
                    $post_title = pathinfo($original_name, PATHINFO_FILENAME);
                }
                if (empty($post_title)) {
                    $post_title = 'Untitled Manual';
                }

                $post_id = wp_insert_post([
                    'post_title'   => $post_title,
                    'post_content' => $post_content,
                    'post_status'  => 'draft',
                    'post_type'    => 'manual',
                    'post_name'    => self::generate_filename_slug($original_name),
                    'meta_input'   => [
                        '_lisun_manual_lang'        => 'zh',
                        '_lisun_manual_sync_status' => 'pending',
                    ],
                ]);

                if (is_wp_error($post_id)) {
                    ob_clean();
                    $results[] = ['stored_name' => $stored_name, 'success' => false, 'message' => $post_id->get_error_message()];
                    continue;
                }

                // 保存原始 .docx 备份（翻译管线用）
                self::save_docx_backup($post_id, $filepath);

                // 自动匹配 Product Model 标签
                $original_name = $uploaded_docs[$stored_name]['filename'] ?? $stored_name;
                $model_hint = self::extract_model_from_filename($original_name);
                if (!empty($model_hint)) {
                    self::auto_tag_post($post_id, $model_hint);
                    update_post_meta($post_id, 'product-no', $model_hint);
                }

                // 从产品 CPT 同步操作视频
                self::sync_videos_from_product($post_id);

                // 自动生成 SEO 三要素
                self::generate_seo_for_post($post_id);

                // 清理
                unlink($filepath);
                unset($uploaded_docs[$stored_name]);

                ob_clean();
                $results[] = [
                    'stored_name' => $stored_name,
                    'success'     => true,
                    'post_id'     => $post_id,
                    'title'       => $post_title,
                    'edit_url'    => get_edit_post_link($post_id, 'raw'),
                    'view_url'    => get_permalink($post_id),
                ];
            } catch (\Exception $e) {
                ob_clean();
                $results[] = ['stored_name' => $stored_name, 'success' => false, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                ob_clean();
                $results[] = ['stored_name' => $stored_name, 'success' => false, 'message' => 'Import error: ' . $e->getMessage()];
            }
        }

        // 更新 option
        update_option('lisun_uploaded_docs', $uploaded_docs);

        $ok = count(array_filter($results, fn($r) => $r['success']));
        $fail = count($results) - $ok;
        ob_clean();
        wp_send_json_success([
            'results' => $results,
            'ok'      => $ok,
            'fail'    => $fail,
            'message' => "导入完成：成功 {$ok} 篇" . ($fail > 0 ? "，失败 {$fail} 篇" : ''),
        ]);
    }

    /**
     * AJAX: 获取导入状态（用于批量导入进度）
     */
    public static function ajax_import_status() {
        self::check_nonce();
        $job_id = sanitize_text_field($_POST['job_id'] ?? '');
        if (empty($job_id)) {
            wp_send_json_error(['message' => __('缺少 job_id', 'lisun-manual')]);
        }

        // 同时检查 parse 和 import 两种 job
        $job = get_transient("lisun_import_{$job_id}") ?: get_transient("lisun_parse_{$job_id}");
        if (!$job) {
            wp_send_json_error(['message' => __('任务已过期或不存在', 'lisun-manual')]);
        }

        wp_send_json_success($job);
    }

    /**
     * AJAX: 在 manual 详情页手动生成 SEO 三要素。
     */
    public static function ajax_generate_manual_seo() {
        @set_time_limit(300);
        @ignore_user_abort(true);

        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'lisun_generate_manual_seo')) {
            wp_send_json_error(['message' => __('安全验证失败', 'lisun-manual')]);
        }

        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('没有权限编辑此页面', 'lisun-manual')]);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'manual') {
            wp_send_json_error(['message' => __('说明书页面不存在', 'lisun-manual')]);
        }

        if (!class_exists('Lisun_Manual_Translate') || !Lisun_Manual_Translate::is_llm_configured()) {
            wp_send_json_error(['message' => __('请先在设置中配置 DeepSeek、OpenAI 或 Gemini，DeepL 不能生成 SEO', 'lisun-manual')]);
        }

        try {
            // 手动触发允许重新生成，解决自动生成只写入部分字段的情况。
            $seo = self::generate_seo_for_post($post_id, true);
            if (empty($seo)) {
                $detail = method_exists('Lisun_Manual_Translate', 'get_last_llm_error')
                    ? Lisun_Manual_Translate::get_last_llm_error()
                    : '';
                wp_send_json_error(['message' => $detail
                    ? __('SEO 生成失败：', 'lisun-manual') . $detail
                    : __('SEO 生成失败，请检查 LLM 配置或稍后重试', 'lisun-manual')]);
            }

            wp_send_json_success([
                'seo'     => $seo,
                'message' => __('SEO 三要素已生成并保存', 'lisun-manual'),
            ]);
        } catch (\Throwable $e) {
            error_log('LISUN Manual: manual SEO generation exception for post ' . $post_id . ': ' . $e->getMessage());
            $detail = method_exists('Lisun_Manual_Translate', 'get_last_llm_error')
                ? Lisun_Manual_Translate::get_last_llm_error()
                : '';
            if ($detail === '') {
                $detail = $e->getMessage();
            }
            wp_send_json_error(['message' => __('SEO 生成失败：', 'lisun-manual') . mb_substr((string) $detail, 0, 500)]);
        }
    }

    // ==================== 工具方法 ====================

    /**
     * 规范化 $_FILES 数组
     */
    private static function normalize_files_array($files) {
        $normalized = [];

        if (!is_array($files['name'])) {
            return [$files];
        }

        foreach ($files['name'] as $key => $name) {
            $normalized[] = [
                'name'     => $name,
                'type'     => $files['type'][$key],
                'tmp_name' => $files['tmp_name'][$key],
                'error'    => $files['error'][$key],
                'size'     => $files['size'][$key],
            ];
        }

        return $normalized;
    }

    /**
     * 递归删除目录
     */
    private static function rmdir_recursive($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * 清理超过 24 小时的过期分片临时目录
     */
    public static function cleanup_stale_chunks() {
        // 每小时最多执行一次
        $last_cleanup = get_transient('lisun_chunk_cleanup_ran');
        if ($last_cleanup) {
            return;
        }
        set_transient('lisun_chunk_cleanup_ran', true, HOUR_IN_SECONDS);

        $chunks_base = LISUN_MANUAL_PATH . 'temp/chunks/';
        if (!is_dir($chunks_base)) {
            return;
        }

        $stale_seconds = 24 * HOUR_IN_SECONDS;
        $now = time();

        $dirs = glob($chunks_base . '*', GLOB_ONLYDIR);
        if (!$dirs) {
            return;
        }

        foreach ($dirs as $chunk_dir) {
            $meta_file = $chunk_dir . '/meta.json';
            $mtime = 0;

            if (file_exists($meta_file)) {
                $meta = json_decode(file_get_contents($meta_file), true);
                if ($meta && !empty($meta['started_at'])) {
                    $mtime = strtotime($meta['started_at']);
                }
            }

            if (!$mtime) {
                $mtime = filemtime($chunk_dir);
            }

            if (($now - $mtime) > $stale_seconds) {
                self::rmdir_recursive($chunk_dir);
            }
        }
    }

    /**
     * 获取所有图片映射
     */
    public static function get_img_maps() {
        return get_option('lisun_img_maps', []);
    }

    /**
     * 从文件名中提取型号
     * 从右往左循环剥后缀：_CN/_ZH → _大写后缀(2-6位) → " Series"
     *
     * 例如：
     *   AGM-500_AGM-580_CP_CN_processed.docx → AGM-500_AGM-580
     *   DC-B Series_TM_processed.docx          → DC-B
     *   LS-DDT Series_ZH_CN_processed.docx     → LS-DDT
     *   LPCE-2(LMS-9000C LMS-9500C)_YM_CN...   → LPCE-2(LMS-9000C LMS-9500C)
     *
     * @param string $filename 原始文件名
     * @return string 型号
     */
    /**
     * 从文件名生成文章 slug：{清理后的文件名}-{4位随机字符串}
     *
     * 例如 "jl-9k1l_yx_cn_kgn6c8.docx" → "jl-9k1l-kgn6c8"
     *      "tpb-ul935_ske_cn_qsu5fu.docx" → "tpb-ul935-qsu5fu"
     *
     * @param string $filename 原始文件名
     * @return string slug
     */
    private static function generate_filename_slug($filename) {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $stem = sanitize_title($stem);
        // 删除末尾的版本/语言标记，保留已有的 4-6 位随机码；旧的 6 位 slug 不能被改写。
        $preserved_random = '';
        $stem = preg_replace_callback('/_(?:[a-z0-9]{2,6})_cn_([a-z0-9]{4,6})$/i', static function ($match) use (&$preserved_random) {
            $preserved_random = strtolower($match[1]);
            return '-' . $preserved_random;
        }, $stem);
        // slug 不使用下划线，统一改为中横线并合并重复分隔符。
        $stem = str_replace('_', '-', $stem);
        $stem = preg_replace('/-+/', '-', $stem);
        $stem = trim($stem, '-');
        if (empty($stem)) {
            $stem = 'manual';
        }
        if ($preserved_random !== '') return $stem . '-' . $preserved_random;
        $random = wp_generate_password(4, false);
        return $stem . '-' . $random;
    }

    /**
     * 将导入的 .docx 备份到插件目录，并保存路径到 post meta
     *
     * 备份文件供后续翻译管线使用（extractor → raw text → LLM → build_en_docx）。
     *
     * @param int    $post_id  中文 post ID
     * @param string $filepath 原始 .docx 文件路径
     */
    private static function save_docx_backup($post_id, $filepath) {
        if (!file_exists($filepath)) return;

        $backup_dir = LISUN_MANUAL_PATH . 'backups/';
        if (!wp_mkdir_p($backup_dir)) return;

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $dest = $backup_dir . 'zh_' . $post_id . '.' . $ext;
        @copy($filepath, $dest);
        update_post_meta($post_id, '_lisun_manual_docx_path', $dest);
    }

    /**
     * 通过 CLI exec 触发后台翻译（无 FPM 超时限制）
     *
     * ★ 优先使用 exec() 启动 PHP CLI 进程 — CLI 无 request_terminate_timeout，
     *   max_execution_time = 300s，大文档翻译也不怕被杀。
     *
     * 降级方案：HTTP fire-and-forget 请求到 admin-ajax.php（FPM worker，60s 限制）
     *
     * 翻译结果会写回 import transient，前端轮询可获取最终状态。
     *
     * @param int    $post_id 中文 post ID
     * @param string $job_id  导入 job ID（用于 transient 通信）
     */
    private static function fire_background_translate($post_id, $job_id) {
        $token   = wp_hash('lisun_bg_translate_' . $post_id);
        $fired   = false;
        $php_bin = defined('PHP_BINARY') ? PHP_BINARY : '/usr/bin/php';

        // ── 方案 A: CLI shell_exec（无 FPM 超时，最可靠）──
        if (function_exists('shell_exec')) {
            $worker_script = LISUN_MANUAL_PATH . 'worker-translate.php';
            if (file_exists($worker_script)) {
                $cmd = escapeshellarg($php_bin) . ' '
                     . escapeshellarg($worker_script) . ' '
                     . intval($post_id) . ' '
                     . escapeshellarg($job_id) . ' '
                     . escapeshellarg($token)
                     . ' > /dev/null 2>&1 &';
                @shell_exec($cmd);
                $fired = true;  // shell_exec 无返回值，假设成功
            }
        }

        // ── 方案 B: HTTP fire-and-forget（降级）──
        if (!$fired) {
            $admin_ajax_url = admin_url('admin-ajax.php');
            $body = http_build_query([
                'action'  => 'lisun_bg_translate',
                'post_id' => $post_id,
                'token'   => $token,
                'job_id'  => $job_id,
            ]);

            // wp_remote_post 非阻塞
            $response = wp_remote_post($admin_ajax_url, [
                'body'      => $body,
                'timeout'   => 1,
                'blocking'  => false,
                'sslverify' => false,
                'headers'   => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);
            $fired = !is_wp_error($response);

            // cURL 非阻塞备用
            if (!$fired && function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $admin_ajax_url,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_TIMEOUT_MS     => 500,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_FRESH_CONNECT  => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                ]);
                curl_exec($ch);
                curl_close($ch);
                $fired = true;
            }
        }

        error_log("LISUN Manual: fire background translate for post {$post_id}, job={$job_id}, fired=" . ($fired ? 'yes' : 'no'));
    }

    /**
     * 根据 product-no 从 products CPT 同步操作视频到 _lisun_manual_videos
     *
     * 视频数据来自 products 帖子的 ACF repeater 字段 product-video，
     * 通过 lisun_get_product_videos_by_model() 查找匹配。
     * 已有手动视频则不覆盖。
     */
    private static function sync_videos_from_product($post_id) {
        $product_no = get_post_meta($post_id, 'product-no', true);
        if (empty($product_no)) return;

        // 已有人工设置的视频则跳过
        $existing = get_post_meta($post_id, '_lisun_manual_videos', true);
        if (!empty($existing) && $existing !== '[]') return;

        $videos = Lisun_Manual_Product_Video_Compat::get_by_model($product_no);
        if (empty($videos)) return;

        update_post_meta($post_id, '_lisun_manual_videos',
            wp_json_encode($videos, JSON_UNESCAPED_UNICODE));

        error_log("LISUN Manual: synced " . count($videos) . " video(s) from product CPT for post {$post_id} (model: {$product_no})");
    }

    /**
     * 使用 LLM 为导入的说明书生成 SEO 三要素（title / description / keywords）
     *
     * 复用翻译模块的 API 配置，生成中文 SEO meta 并写入 post meta
     * 同时写入 rank_math_* 字段兼容 Rank Math SEO 插件
     */
    private static function generate_seo_for_post($post_id, $force = false) {
        if (!class_exists('Lisun_Manual_Translate') || !Lisun_Manual_Translate::is_llm_configured()) {
            return null;
        }

        // 自动导入时不覆盖手填 SEO；详情页手动触发允许重新生成。
        $existing_title = get_post_meta($post_id, 'title', true);
        if (!$force && !empty($existing_title)) {
            return [];
        }

        $post = get_post($post_id);
        if (!$post) return null;

        $post_title   = $post->post_title;
        $post_content = $post->post_content;
        $product_no   = get_post_meta($post_id, 'product-no', true);

        // 截取内容前 3000 字符作为上下文
        $content_excerpt = mb_substr(wp_strip_all_tags($post_content), 0, 3000);

        $system = <<<EOT
You are an SEO specialist for LISUN Group (力汕电子) laboratory testing equipment.
Generate Chinese SEO metadata based on the product manual content.

RULES:
- Output ONLY a valid JSON object: {"title":"...","description":"...","keywords":"..."}
- SEO title: ≤70 characters, include product model number, compelling and informative
- Do NOT append "| 力汕 (LISUN)" or any company/site suffix to the SEO title; the website adds that suffix automatically
- SEO description: 140-155 characters when possible, never exceed 160 characters; summarize the product's key features and testing applications
- Keywords: 3-5 Chinese keywords, comma-separated
- If mentioning the company in the description, use "力汕" or "LISUN", never "力昇" or other variants
- In JSON strings, use only standard JSON escapes; do not add a backslash before underscores, parentheses, spaces, or other ordinary characters
- Do NOT add markdown fences, quotes around JSON, or any explanation
EOT;

        $user = "标题: {$post_title}";
        if (!empty($product_no)) {
            $user .= "\n产品型号: {$product_no}";
        }
        $user .= "\n内容摘要:\n{$content_excerpt}";

        $raw = Lisun_Manual_Translate::call_llm($system, $user, [
            'temperature' => 0.3,
            'max_tokens'  => 500,
        ]);

        if (empty($raw)) {
            error_log("LISUN Manual: SEO generation failed for post {$post_id} — empty LLM response");
            return null;
        }

        // 解析 JSON，兼容 markdown fence、前后说明文字以及值中包含引号的情况。
        $seo = self::parse_seo_json($raw);

        if (!is_array($seo)) {
            error_log("LISUN Manual: SEO JSON parse failed for post {$post_id}. Raw: " . substr($raw, 0, 200));
            // 重试一次
            $raw = Lisun_Manual_Translate::call_llm($system, $user, [
                'temperature' => 0.3,
                'max_tokens'  => 500,
            ]);
            if (empty($raw)) return null;
            $seo = self::parse_seo_json($raw);
            if (!is_array($seo)) {
                error_log("LISUN Manual: SEO JSON parse failed on retry for post {$post_id}");
                if (method_exists('Lisun_Manual_Translate', 'set_last_llm_error')) {
                    $preview = method_exists('Lisun_Manual_Translate', 'get_last_llm_response_preview')
                        ? Lisun_Manual_Translate::get_last_llm_response_preview(800)
                        : '';
                    $message = 'LLM 已返回内容，但不是可识别的 SEO JSON 格式';
                    if ($preview !== '') {
                        $message .= '；原始返回预览：' . $preview;
                    }
                    Lisun_Manual_Translate::set_last_llm_error($message);
                }
                return null;
            }
        }

        // 写入 post meta（插件自有字段 + Rank Math 字段）
        $rmk_map = [
            'title'       => 'rank_math_title',
            'description' => 'rank_math_description',
            'keywords'    => 'rank_math_focus_keyword',
        ];

        $generated = [];
        foreach (['title', 'description', 'keywords'] as $key) {
            if (isset($seo[$key]) && is_scalar($seo[$key]) && trim((string) $seo[$key]) !== '') {
                $value = sanitize_text_field((string) $seo[$key]);
                if ($key === 'title') {
                    // 网站前台会统一追加品牌后缀，避免模型重复写入。
                    $value = preg_replace('/\s*(?:\|\s*)?(?:力汕(?:\s*\(\s*LISUN\s*\))?|LISUN)\s*$/iu', '', $value);
                    $value = mb_substr($value, 0, 70);
                } elseif ($key === 'description') {
                    $value = mb_substr($value, 0, 160);
                }
                if ($value === '') continue;
                update_post_meta($post_id, $key, $value);
                $generated[$key] = $value;
                if (isset($rmk_map[$key])) {
                    update_post_meta($post_id, $rmk_map[$key], $value);
                }
            }
        }

        if (empty($generated)) {
            error_log("LISUN Manual: SEO response did not contain usable title/description/keywords for post {$post_id}");
            if (method_exists('Lisun_Manual_Translate', 'set_last_llm_error')) {
                Lisun_Manual_Translate::set_last_llm_error('LLM 返回了 JSON，但缺少可用的 title、description 或 keywords 字段');
            }
            return null;
        }

        error_log("LISUN Manual: SEO generated for post {$post_id} — title=" . ($seo['title'] ?? 'none'));
        return $generated;
    }

    /**
     * 从 LLM 响应中提取 SEO JSON。
     *
     * 不使用简单的正则截取对象，因为 SEO 文本本身可能包含转义引号。
     * @return array|null
     */
    private static function parse_seo_json($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
        $clean = trim(is_string($clean) ? $clean : $raw);

        // 某些模型会在 JSON 字符串中输出 \_、\( 等非法 JSON 转义。
        // 先尝试原文，再仅修复字符串内的非法反斜杠，避免误伤正常转义。
        $candidates = [$clean];
        $repaired = self::repair_seo_json_escapes($clean);
        if ($repaired !== $clean) $candidates[] = $repaired;

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) return self::normalize_seo_fields($decoded);
            // 某些 Gemini 兼容层会把 JSON 再编码成一个 JSON 字符串。
            if (is_string($decoded) && trim($decoded) !== '') {
                $decoded_again = json_decode($decoded, true);
                if (is_array($decoded_again)) return self::normalize_seo_fields($decoded_again);
            }

            $length = strlen($candidate);
            for ($start = 0; $start < $length; $start++) {
                if ($candidate[$start] !== '{') continue;

                $depth = 0;
                $in_string = false;
                $escaped = false;
                for ($i = $start; $i < $length; $i++) {
                    $char = $candidate[$i];
                    if ($in_string) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($char === '\\') {
                            $escaped = true;
                        } elseif ($char === '"') {
                            $in_string = false;
                        }
                        continue;
                    }

                    if ($char === '"') {
                        $in_string = true;
                    } elseif ($char === '{') {
                        $depth++;
                    } elseif ($char === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $json_object = substr($candidate, $start, $i - $start + 1);
                            $decoded = json_decode($json_object, true);
                            if (is_array($decoded)) return self::normalize_seo_fields($decoded);
                            break;
                        }
                    }
                }
            }
        }

        // 兜底兼容：模型可能返回带字段名的文本或非严格 JSON（单独字段仍可提取）。
        $loose = self::parse_loose_seo_fields($clean);
        if (!empty($loose)) return $loose;

        return null;
    }

    /** 修复模型在 JSON 字符串中产生的非法反斜杠转义，例如 \_、\(。 */
    private static function repair_seo_json_escapes($text) {
        $text = (string) $text;
        $out = '';
        $in_string = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if (!$in_string) {
                $out .= $char;
                if ($char === '"') $in_string = true;
                continue;
            }

            if ($char === '"') {
                $out .= $char;
                $in_string = false;
                continue;
            }

            if ($char !== '\\') {
                $ord = ord($char);
                if ($ord < 32) {
                    $out .= ($char === "\r" || $char === "\n") ? '\\n' : ' ';
                } else {
                    $out .= $char;
                }
                continue;
            }

            $next = $i + 1 < $length ? $text[$i + 1] : '';
            if (strpos('"\\/bfnrt', $next) !== false) {
                $out .= '\\' . $next;
                $i++;
                continue;
            }
            if ($next === 'u') {
                $hex = substr($text, $i + 2, 4);
                if (strlen($hex) === 4 && preg_match('/^[0-9a-f]{4}$/i', $hex)) {
                    $out .= '\\u' . $hex;
                    $i += 5;
                    continue;
                }
            }

            // 非法转义只去掉反斜杠，保留后面的实际字符（如 \_ -> _）。
            if ($next !== '') {
                $out .= $next;
                $i++;
            }
        }

        return $out;
    }

    /** 将不同模型常用的 SEO 字段名统一为 title/description/keywords。 */
    private static function normalize_seo_fields($data) {
        if (!is_array($data)) return [];
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_scalar($value)) continue;
            $normalized_key = strtolower((string) $key);
            $normalized_key = preg_replace('/[\s_\-]+/u', '', $normalized_key);
            if (strpos($normalized_key, 'title') !== false || strpos($normalized_key, '标题') !== false) {
                $result['title'] = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (strpos($normalized_key, 'description') !== false || strpos($normalized_key, 'desc') !== false || strpos($normalized_key, '描述') !== false) {
                $result['description'] = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (strpos($normalized_key, 'keyword') !== false || strpos($normalized_key, '关键词') !== false) {
                $result['keywords'] = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $result;
    }

    /** 从 SEO Title/标题: xxx 这类非严格 JSON 文本中提取字段。 */
    private static function parse_loose_seo_fields($text) {
        $result = [];
        $patterns = [
            'title' => '/(?:"|\')?\s*(?:seo[\s_-]*)?(?:title|标题)\s*(?:"|\')?\s*[:：]\s*(?:"|\')((?:\\.|[^"\'\\])*)(?:"|\')/isu',
            'description' => '/(?:"|\')?\s*(?:seo[\s_-]*)?(?:description|desc|描述)\s*(?:"|\')?\s*[:：]\s*(?:"|\')((?:\\.|[^"\'\\])*)(?:"|\')/isu',
            'keywords' => '/(?:"|\')?\s*(?:seo[\s_-]*)?(?:keywords?|关键词)\s*(?:"|\')?\s*[:：]\s*(?:"|\')((?:\\.|[^"\'\\])*)(?:"|\')/isu',
        ];
        foreach ($patterns as $key => $pattern) {
            if (!preg_match($pattern, $text, $match)) continue;
            $value = json_decode('"' . $match[1] . '"', true);
            $result[$key] = is_string($value) ? $value : stripslashes($match[1]);
        }

        // Markdown 列表/普通文本通常没有引号，按字段标签截取到下一行。
        if (empty($result['title']) && preg_match('/(?:SEO\s*)?标题\s*[:：]\s*(.+?)(?=\r?\n|$)/isu', $text, $m)) {
            $result['title'] = trim($m[1], " \t`*_\"'");
        }
        if (empty($result['description']) && preg_match('/(?:SEO\s*)?描述\s*[:：]\s*(.+?)(?=\r?\n\s*(?:SEO\s*)?(?:关键词|关键字)|$)/isu', $text, $m)) {
            $result['description'] = trim($m[1], " \t`*_\"'");
        }
        if (empty($result['keywords']) && preg_match('/(?:SEO\s*)?(?:关键词|关键字)\s*[:：]\s*(.+?)(?=\r?\n|$)/isu', $text, $m)) {
            $result['keywords'] = trim($m[1], " \t`*_\"'");
        }

        return array_filter($result, static function ($value) {
            return trim((string) $value) !== '';
        });
    }

    private static function extract_model_from_filename($filename) {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        // 1. 去掉 _processed 尾巴
        $base = preg_replace('/_processed$/i', '', $base);

        // 2. 从右往左循环剥后缀
        while (true) {
            // 剥语言标记 _CN / _ZH / _EN / _ES
            if (preg_match('/_(CN|ZH|EN|ES)$/i', $base)) {
                $base = substr($base, 0, -3);
                continue;
            }

            // 剥纯大写字母后缀（2-6位），如 _CP, _YQ, _SS, _SKE, _TM, _YM, _WB, _YX
            $last_us = strrpos($base, '_');
            if ($last_us !== false) {
                $suffix = substr($base, $last_us + 1);
                if (ctype_upper($suffix) && strlen($suffix) >= 2 && strlen($suffix) <= 6) {
                    $base = substr($base, 0, $last_us);
                    continue;
                }
            }

            // 剥 " Series"（带空格）
            if (substr($base, -7) === ' Series') {
                $base = substr($base, 0, -7);
                continue;
            }

            break;
        }

        // 3. 如果结果含空格，从开头提取型号（处理 "PFM61000-8A Instruction Manual" 这种）
        if (strpos($base, ' ') !== false) {
            if (preg_match('/^([A-Za-z0-9]+(?:[-\/][A-Za-z0-9]+)*(?:\s*\([^)]+\))?)/', $base, $m)) {
                $base = $m[1];
            }
        }

        return trim($base);
    }

    /**
     * 根据型号提示自动创建并设置 Product Model 标签
     *
     * 每个型号创建独立的标签，不做模糊匹配避免错误的标签关联。
     * 仅在同名标签已存在时复用（处理同一型号的重复导入/更新场景）。
     *
     * @param int    $post_id
     * @param string $model_hint 型号提示（如 "DO160-S17"）
     */
    private static function auto_tag_post($post_id, $model_hint) {
        // 如果同名 term 已存在直接复用，否则创建新标签
        $term = term_exists($model_hint, 'post_tag');
        if (!$term) {
            $term = wp_insert_term($model_hint, 'post_tag', ['slug' => sanitize_title($model_hint)]);
        }
        if (!is_wp_error($term) && !empty($term['term_id'])) {
            wp_set_post_terms($post_id, [intval($term['term_id'])], 'post_tag', true);
        }
    }
}
