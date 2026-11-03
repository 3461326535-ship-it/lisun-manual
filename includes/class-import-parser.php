<?php
/**
 * Word 解析器：PhpWord → Gutenberg Blocks
 *
 * 图片映射策略（按优先级）：
 * 1. 从 docx 内部提取图片，计算 MD5 → 与用户上传图片的哈希匹配
 * 2. 哈希匹配不到 → 直接将提取的图片上传到 WP 媒体库
 * 3. 用户手动在文中写的 [img:filename.jpg] 占位符 → 按文件名查映射表
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Import_Parser {

    const LEGACY_IMAGE_CRON = 'lisun_manual_convert_legacy_images';
    const LEGACY_IMAGE_SKIP_MIGRATION = 'lisun_manual_legacy_image_skip_migration_v2';
    // 定时任务分批处理，避免一次转换大量高分辨率图片占满 PHP 内存。
    const LEGACY_IMAGE_BATCH = 20;

    /**
     * 返回后台配置的历史图片处理数量。
     * 每小时执行一次，限制在 1-100 张，避免错误配置导致单次任务过重。
     */
    public static function get_legacy_image_batch() {
        $batch = (int) get_option('lisun_legacy_image_batch', self::LEGACY_IMAGE_BATCH);
        return max(1, min(100, $batch));
    }

    /**
     * 返回历史图片转换的汇总和明细，供后台设置页展示。
     */
    public static function get_legacy_image_status($limit = 100) {
        $maps = self::reconcile_referenced_image_maps();

        $summary = [
            'total'     => 0,
            'referenced' => 0,
            'processed' => 0,
            'pending'   => 0,
            'failed'    => 0,
            'skipped'   => 0,
            'unreferenced' => 0,
            'listed'    => 0,
        ];
        $all_rows = [];
        $skipped_rows = [];
        $limit = max(1, min(500, (int) $limit));
        $reference_details = self::get_manual_image_reference_counts($maps, true);

        foreach ($maps as $name => $map) {
            if (!is_array($map)) continue;
            $summary['total']++;

            $attachment_id = (int) ($map['attachment_id'] ?? 0);
            $source_file = $attachment_id ? get_attached_file($attachment_id) : '';
            $old_url = (string) ($map['url'] ?? '');
            if ($old_url === '' && $attachment_id) {
                $old_url = (string) wp_get_attachment_url($attachment_id);
            }
            $reference_detail = $reference_details[(string) $name] ?? [];
            $references = (int) ($reference_detail['count'] ?? 0);
            $reference_post_ids = array_values(array_filter(array_map('intval', (array) ($reference_detail['post_ids'] ?? []))));
            if ($references === 0) {
                // 插件曾记录过但正文没有引用的媒体，不进入转换队列。
                $summary['unreferenced']++;
                continue;
            }
            $summary['referenced']++;
            // 只有没有 URL，或已由后台工作进程确认 HTTP 404/410，才判定为跳过。
            // 本地文件不可读不等于 URL 404，可能是 CDN、对象存储或附件目录迁移造成的。
            $unavailable = $old_url === '' || !empty($map['progressive_jpeg_skip_verified']);
            if ($unavailable) {
                $summary['skipped']++;
                $skipped_rows[] = [
                    'name'   => (string) $name,
                    'url'    => $old_url,
                    'references' => $references,
                    'reference_post_ids' => $reference_post_ids,
                    'reason' => (string) ($map['progressive_jpeg_skip_reason'] ?? '原图片 URL 不可用'),
                ];
                continue;
            }

            $summary['listed']++;
            $already_progressive = empty($map['progressive_jpeg_attachment_id'])
                && $source_file !== ''
                && is_readable($source_file)
                && self::is_progressive_jpeg($source_file);

            if (!empty($map['progressive_jpeg_attachment_id']) || $already_progressive) {
                $status = 'processed';
                $summary['processed']++;
            } elseif (!empty($map['progressive_jpeg_error'])) {
                $status = 'failed';
                $summary['failed']++;
            } else {
                $status = 'pending';
                $summary['pending']++;
            }

            $all_rows[] = [
                'name'       => (string) $name,
                'status'     => $status,
                'attachment' => (int) ($map['attachment_id'] ?? 0),
                'url'        => (string) ($map['url'] ?? ''),
                'references' => $references,
                'reference_post_ids' => $reference_post_ids,
                'error'      => (string) ($map['progressive_jpeg_error'] ?? ''),
                'attempt'    => (int) ($map['progressive_jpeg_last_attempt'] ?? 0),
                'note'       => $already_progressive
                    ? '原图已是 progressive JPEG'
                    : ($source_file === '' || !is_readable($source_file) ? '本地文件不可读，处理时将尝试从 URL 获取' : ''),
            ];
        }

        // 失败和待处理优先展示，历史记录很多时仍能直接看到需要关注的项目。
        $priority = ['failed' => 0, 'pending' => 1, 'processed' => 2];
        usort($all_rows, static function ($a, $b) use ($priority) {
            $a_priority = $priority[$a['status']] ?? 9;
            $b_priority = $priority[$b['status']] ?? 9;
            if ($a_priority !== $b_priority) return $a_priority <=> $b_priority;
            return strnatcasecmp($a['name'], $b['name']);
        });
        $rows = array_slice($all_rows, 0, $limit);
        usort($skipped_rows, static function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        $skipped_rows = array_slice($skipped_rows, 0, $limit);

        return [
            'summary' => $summary,
            'rows'    => $rows,
            'skipped_rows' => $skipped_rows,
            'limited' => $summary['listed'] > count($rows),
            'skipped_limited' => $summary['skipped'] > count($skipped_rows),
        ];
    }

    /**
     * 从说明书正文反向统计图片引用。
     *
     * 不能只用“映射表 URL 是否原样出现在正文”判断，因为 WordPress 保存内容时
     * 可能使用缩略图 URL、srcset 或 CDN/旧域名。这里以正文当前实际出现的图片 URL
     * 和媒体库真实文件路径为依据，不使用换图后可能残留的旧附件 ID。
     */
    private static function get_manual_image_reference_counts($maps, $with_post_ids = false) {
        global $wpdb;
        $posts = $wpdb->get_results(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_type = 'manual'
               AND post_status NOT IN ('trash', 'auto-draft')"
        );

        $map_by_id = [];
        $map_by_path = [];
        $map_by_basename = [];
        foreach ($maps as $name => $map) {
            if (!is_array($map)) continue;
            $key = (string) $name;
            $attachment_id = (int) ($map['attachment_id'] ?? 0);
            if ($attachment_id > 0) {
                $map_by_id[$attachment_id][] = $key;
            }
            $path = self::normalize_image_reference_path((string) ($map['url'] ?? ''));
            if ($path !== '') {
                $map_by_path[$path][] = $key;
                $basename = basename($path);
                if ($basename !== '') $map_by_basename[$basename][] = $key;
            }
        }

        // 建立媒体库真实文件路径索引。正文 URL 可能来自 CDN 或旧域名，
        // 但 uploads 下的相对路径和附件 ID 仍然可靠。
        $attachment_by_file = [];
        $attachment_rows = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
        );
        foreach ((array) $attachment_rows as $attachment_row) {
            $attached_file = strtolower(ltrim(rawurldecode((string) ($attachment_row->attached_file ?? '')), '/'));
            if ($attached_file !== '') {
                $attachment_by_file[$attached_file][] = (int) $attachment_row->ID;
            }
        }

        $counts = [];
        $post_ids = [];
        foreach ($maps as $name => $map) {
            if (is_array($map)) {
                $key = (string) $name;
                $counts[$key] = 0;
                $post_ids[$key] = [];
            }
        }

        foreach ((array) $posts as $post) {
            $content = (string) ($post->post_content ?? '');
            if ($content === '') continue;

            $matched = [];

            // 逐个处理 img，避免“旧 wp-image-ID + 新 src URL”同时存在时，
            // 把已经替换掉的旧附件误判为当前引用。URL 能解析到附件时，以 URL 为准。
            if (preg_match_all('/<img\b[^>]*>/is', $content, $image_tags)) {
                foreach ($image_tags[0] as $image_tag) {
                    $tag_url_keys = [];
                    $tag_url_resolved = false;
                    $tag_url_seen = false;
                    if (preg_match_all('/(?:src|data-src|data-lazy-src|data-original|srcset|data-srcset)\s*=\s*(["\'])(.*?)\1/is', $image_tag, $tag_attrs, PREG_SET_ORDER)) {
                        foreach ($tag_attrs as $attr) {
                            $value = html_entity_decode((string) ($attr[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            foreach (preg_split('/\s*,\s*/', $value) ?: [] as $candidate) {
                                $candidate = trim((string) $candidate);
                                // srcset 候选通常是“URL 400w”，只取前半段。
                                $candidate = preg_split('/\s+/', $candidate, 2)[0] ?? '';
                                $path = self::normalize_image_reference_path($candidate);
                                if ($path === '') continue;
                                $tag_url_seen = true;

                                $path_map_keys = $map_by_path[$path] ?? [];
                                foreach ($path_map_keys as $key) $tag_url_keys[$key] = true;
                                if (!empty($path_map_keys)) $tag_url_resolved = true;

                                // 通过媒体库附件相对路径反查，兼容 CDN/旧域名/协议变化。
                                $relative_file = self::extract_upload_relative_path($path);
                                $resolved_attachment_ids = $attachment_by_file[$relative_file] ?? [];
                                foreach ($resolved_attachment_ids as $attachment_id) {
                                    foreach (($map_by_id[$attachment_id] ?? []) as $key) $tag_url_keys[$key] = true;
                                }
                                if (!empty($resolved_attachment_ids)) $tag_url_resolved = true;

                                // 不按 basename 兜底：不同批次/月份可能存在同名图片，
                                // 仅文件名相同不能证明是同一个附件。
                            }
                        }
                    }

                    if ($tag_url_resolved || $tag_url_seen) {
                        foreach (array_keys($tag_url_keys) as $key) $matched[$key] = true;
                    }
                }
            }

            // 不使用 Gutenberg 区块 JSON 中的旧 id 作为引用依据；
            // 只认区块内部当前实际输出的图片 URL，避免换图后残留旧 id 造成误判。

            // 兼容没有标准 img 标签、只有懒加载属性的旧正文。
            if (preg_match_all('/(?:src|data-src|data-lazy-src|data-original|srcset|data-srcset)\s*=\s*(["\'])(.*?)\1/is', $content, $url_attrs, PREG_SET_ORDER)) {
                foreach ($url_attrs as $attr) {
                    $value = html_entity_decode((string) ($attr[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    foreach (preg_split('/\s*,\s*/', $value) ?: [] as $candidate) {
                        $candidate = trim((string) $candidate);
                        $candidate = preg_split('/\s+/', $candidate, 2)[0] ?? '';
                        $path = self::normalize_image_reference_path($candidate);
                        if ($path === '') continue;
                        foreach (($map_by_path[$path] ?? []) as $key) $matched[$key] = true;
                        $relative_file = self::extract_upload_relative_path($path);
                        foreach (($attachment_by_file[$relative_file] ?? []) as $attachment_id) {
                            foreach (($map_by_id[$attachment_id] ?? []) as $key) $matched[$key] = true;
                        }
                    }
                }
            }

            foreach (array_keys($matched) as $key) {
                if (!isset($counts[$key])) continue;
                $counts[$key]++;
                if ($with_post_ids) {
                    $post_id = (int) ($post->ID ?? 0);
                    if ($post_id > 0) $post_ids[$key][$post_id] = true;
                }
            }
        }
        if ($with_post_ids) {
            $details = [];
            foreach ($counts as $key => $count) {
                $details[$key] = [
                    'count' => $count,
                    'post_ids' => array_map('intval', array_keys($post_ids[$key] ?? [])),
                ];
            }
            return $details;
        }
        return $counts;
    }

    /**
     * 将正文实际引用、但历史映射表遗漏的附件补入映射表。
     * 历史上有些图片通过 Gutenberg 图片区块或后续媒体替换进入正文，
     * 不一定经过本插件的 upload_extracted_image()。
     */
    private static function reconcile_referenced_image_maps() {
        $maps = get_option('lisun_img_maps', []);
        if (!is_array($maps)) $maps = [];

        $referenced_ids = self::get_manual_referenced_attachment_ids();
        if (empty($referenced_ids)) return $maps;

        $known_ids = [];
        foreach ($maps as $map) {
            if (is_array($map) && !empty($map['attachment_id'])) {
                $known_ids[(int) $map['attachment_id']] = true;
            }
        }

        $changed = false;
        foreach ($referenced_ids as $attachment_id) {
            $attachment_id = (int) $attachment_id;
            if ($attachment_id <= 0 || isset($known_ids[$attachment_id])) continue;

            $url = function_exists('wp_get_attachment_url')
                ? (string) wp_get_attachment_url($attachment_id)
                : '';
            if ($url === '') continue;
            $file = function_exists('get_attached_file')
                ? (string) get_attached_file($attachment_id)
                : '';
            $url_parts = wp_parse_url($url);
            $name = $file !== ''
                ? basename($file)
                : basename((string) (is_array($url_parts) ? ($url_parts['path'] ?? '') : ''));
            if ($name === '') $name = 'attachment-' . $attachment_id;

            $key = 'attachment-' . $attachment_id;
            while (isset($maps[$key])) $key .= '-ref';
            $maps[$key] = [
                'name'          => $name,
                'attachment_id' => $attachment_id,
                'url'           => $url,
                'source'        => 'manual-content-reconciled',
                'reconciled_at' => time(),
            ];
            $known_ids[$attachment_id] = true;
            $changed = true;
        }

        if ($changed) update_option('lisun_img_maps', $maps);
        return $maps;
    }

    /**
     * 从所有 manual 正文提取真实引用的附件 ID。
     * 兼容经典编辑器、Gutenberg 图片区块、CDN/旧域名和 srcset。
     */
    private static function get_manual_referenced_attachment_ids() {
        global $wpdb;
        $posts = $wpdb->get_results(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_type = 'manual'
               AND post_status NOT IN ('trash', 'auto-draft')"
        );
        if (empty($posts)) return [];

        $attachment_by_file = [];
        $attachment_rows = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
        );
        foreach ((array) $attachment_rows as $row) {
            $file = strtolower(ltrim(rawurldecode((string) ($row->attached_file ?? '')), '/'));
            if ($file !== '') $attachment_by_file[$file][] = (int) $row->ID;
        }

        $ids = [];
        foreach ((array) $posts as $post) {
            $content = (string) ($post->post_content ?? '');
            if ($content === '') continue;

            // 与状态列表保持一致：同一个 img 同时存在旧 wp-image-ID 和新 URL 时，
            // 以能从当前 URL 解析出的附件为准，避免把换图前的旧附件重新认定为引用。
            if (preg_match_all('/<img\b[^>]*>/is', $content, $image_tags)) {
                foreach ($image_tags[0] as $image_tag) {
                    $tag_attachment_ids = [];
                    $tag_url_seen = false;
                    if (preg_match_all('/(?:src|data-src|data-lazy-src|data-original|srcset|data-srcset)\s*=\s*(["\'])(.*?)\1/is', $image_tag, $attrs, PREG_SET_ORDER)) {
                        foreach ($attrs as $attr) {
                            $value = html_entity_decode((string) ($attr[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            foreach (preg_split('/\s*,\s*/', $value) ?: [] as $candidate) {
                                $candidate = trim((string) $candidate);
                                $candidate = preg_split('/\s+/', $candidate, 2)[0] ?? '';
                                $path = self::normalize_image_reference_path($candidate);
                                if ($path === '') continue;
                                $tag_url_seen = true;
                                $relative = self::extract_upload_relative_path($path);
                                foreach (($attachment_by_file[$relative] ?? []) as $id) {
                                    $tag_attachment_ids[(int) $id] = true;
                                }
                            }
                        }
                    }
                    if ($tag_url_seen) {
                        foreach (array_keys($tag_attachment_ids) as $id) $ids[(int) $id] = true;
                    } else {
                        // 没有可解析的当前 URL 时不再使用旧 wp-image-ID；
                        // 该函数只为当前正文 URL 建立真实附件映射。
                    }
                }
            }

            // 不使用 Gutenberg 区块 JSON 中的旧 id；换图后只有当前 URL 才是有效依据。

            // 兼容只有懒加载属性、没有标准 img 标签的旧正文。
            if (preg_match_all('/(?:src|data-src|data-lazy-src|data-original|srcset|data-srcset)\s*=\s*(["\'])(.*?)\1/is', $content, $attrs, PREG_SET_ORDER)) {
                foreach ($attrs as $attr) {
                    $value = html_entity_decode((string) ($attr[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    foreach (preg_split('/\s*,\s*/', $value) ?: [] as $candidate) {
                        $candidate = trim((string) $candidate);
                        $candidate = preg_split('/\s+/', $candidate, 2)[0] ?? '';
                        $path = self::normalize_image_reference_path($candidate);
                        if ($path === '') continue;
                        $relative = self::extract_upload_relative_path($path);
                        foreach (($attachment_by_file[$relative] ?? []) as $id) $ids[(int) $id] = true;
                    }
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * 将图片 URL 规范为可比较的路径。
     * 会去掉域名、查询参数和 WordPress 生成的 -400x300 等尺寸后缀。
     */
    private static function normalize_image_reference_path($url) {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || strpos($url, 'data:') === 0) return '';
        $parts = wp_parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($path === '' && $url[0] === '/') $path = $url;
        if ($path === '') return '';
        $path = rawurldecode($path);
        $path = preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $path);
        return strtolower('/' . ltrim((string) $path, '/'));
    }

    /**
     * 从 uploads URL 路径中提取与 _wp_attached_file 相同的相对路径。
     */
    private static function extract_upload_relative_path($path) {
        $path = strtolower(rawurldecode((string) $path));
        $marker = '/uploads/';
        $pos = strrpos($path, $marker);
        if ($pos !== false) {
            return ltrim(substr($path, $pos + strlen($marker)), '/');
        }
        return ltrim($path, '/');
    }

    /**
     * 判断 JPEG 是否使用 progressive 编码。
     * 只读取 JPEG 标记，不解码整张图片，避免后台状态页额外占用大量内存。
     */
    private static function is_progressive_jpeg($file) {
        $info = @getimagesize($file);
        if (!is_array($info) || ($info['mime'] ?? '') !== 'image/jpeg') return false;

        $handle = @fopen($file, 'rb');
        if (!$handle) return false;
        $start = fread($handle, 2);
        if ($start !== "\xFF\xD8") {
            fclose($handle);
            return false;
        }

        // SOF2、SOF6、SOFA、SOFE 分别对应几种 progressive JPEG 编码。
        $progressive_markers = [0xC2, 0xC6, 0xCA, 0xCE];
        while (!feof($handle)) {
            $byte = fread($handle, 1);
            if ($byte === '' || $byte === false) break;
            if (ord($byte) !== 0xFF) continue;

            do {
                $marker_byte = fread($handle, 1);
                if ($marker_byte === '' || $marker_byte === false) {
                    fclose($handle);
                    return false;
                }
                $marker = ord($marker_byte);
            } while ($marker === 0xFF);

            if (in_array($marker, $progressive_markers, true)) {
                fclose($handle);
                return true;
            }
            if ($marker === 0xD9 || $marker === 0xDA) break; // EOI / SOS 后进入压缩数据
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) continue;

            $length_bytes = fread($handle, 2);
            if (strlen($length_bytes) !== 2) break;
            $length = (ord($length_bytes[0]) << 8) | ord($length_bytes[1]);
            if ($length < 2 || fseek($handle, $length - 2, SEEK_CUR) !== 0) break;
        }

        fclose($handle);
        return false;
    }

    public static function init() {
        self::migrate_unverified_skip_status();
        add_action(self::LEGACY_IMAGE_CRON, [__CLASS__, 'convert_legacy_images']);
        add_action('wp_ajax_lisun_legacy_image_convert_now', [__CLASS__, 'ajax_convert_legacy_images']);
        add_action('admin_post_lisun_legacy_image_convert_now', [__CLASS__, 'admin_post_convert_legacy_images']);
        if (!wp_next_scheduled(self::LEGACY_IMAGE_CRON)) {
            wp_schedule_event(time() + 300, 'hourly', self::LEGACY_IMAGE_CRON);
        }
    }

    /**
     * 清除早期版本把“本地文件不可读”误记为 404 的状态。
     * 新版本会在后台工作进程中重新核实 URL，避免状态页长期保留错误结论。
     */
    private static function migrate_unverified_skip_status() {
        if (get_option(self::LEGACY_IMAGE_SKIP_MIGRATION, false)) return;

        $maps = get_option('lisun_img_maps', []);
        if (is_array($maps)) {
            foreach ($maps as $key => $map) {
                if (!is_array($map)) continue;
                unset(
                    $maps[$key]['progressive_jpeg_skipped'],
                    $maps[$key]['progressive_jpeg_skip_verified'],
                    $maps[$key]['progressive_jpeg_skip_reason']
                );
            }
            update_option('lisun_img_maps', $maps);
        }
        update_option(self::LEGACY_IMAGE_SKIP_MIGRATION, 1, false);
    }

    /**
     * 注册手动转换 REST 接口，避开部分服务器对 admin-ajax.php 的 400 拦截。
     */
    public static function register_rest_routes() {
        register_rest_route('lisun-manual/v1', '/legacy-image-convert', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_convert_legacy_images'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * REST：手动执行一批历史图片转换。
     */
    public static function rest_convert_legacy_images($request = null) {
        $batch = self::get_legacy_image_batch();
        if ($request && method_exists($request, 'get_param')) {
            $requested_batch = (int) $request->get_param('batch');
            if ($requested_batch > 0) $batch = max(1, min(100, $requested_batch));
        }
        $result = self::convert_legacy_images(true, $batch);
        $processed = (int) ($result['processed'] ?? 0);
        $attempted = (int) ($result['attempted'] ?? 0);
        $handled = (int) ($result['handled'] ?? 0);
        $message = $processed > 0
            ? sprintf('本次已处理 %d 张。', $processed)
            : ($attempted > 0 ? '本批有图片处理失败。' : ($handled > 0 ? '本批已核查 %d 张。' : '没有找到待处理图片。'));
        if (strpos($message, '%d') !== false) $message = sprintf($message, $handled);
        return new WP_REST_Response([
            'success' => true,
            'data'    => ['message' => $message, 'result' => $result],
        ], 200);
    }

    /**
     * 后台手动执行一批历史图片转换。
     * 手动执行会跳过失败记录的一天冷却时间，方便修复配置后立即重试。
     */
    public static function ajax_convert_legacy_images() {
        check_ajax_referer('lisun_legacy_image_convert', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '没有权限执行历史图片转换']);
        }

        // 手动按后台设置的批量执行；每批完成后前端会自动继续下一批。
        $result = self::convert_legacy_images(true, self::get_legacy_image_batch());
        $processed = (int) ($result['processed'] ?? 0);
        $attempted = (int) ($result['attempted'] ?? 0);
        $handled = (int) ($result['handled'] ?? 0);
        $message = $processed > 0
            ? sprintf('本次已处理 %d 张。', $processed)
            : ($attempted > 0 ? '本批有图片处理失败。' : ($handled > 0 ? sprintf('本批已核查 %d 张。', $handled) : '没有找到待处理图片。'));
        wp_send_json_success(['message' => $message, 'result' => $result]);
    }

    /**
     * admin-post：手动执行一批历史图片转换。
     * 作为后台 AJAX/REST 被服务器拦截时的稳定入口。
     */
    public static function admin_post_convert_legacy_images() {
        check_admin_referer('lisun_legacy_image_convert', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '没有权限执行历史图片转换'], 403);
        }

        $result = self::convert_legacy_images(true, self::get_legacy_image_batch());
        $processed = (int) ($result['processed'] ?? 0);
        $attempted = (int) ($result['attempted'] ?? 0);
        $handled = (int) ($result['handled'] ?? 0);
        $message = $processed > 0
            ? sprintf('本次已处理 %d 张。', $processed)
            : ($attempted > 0 ? '本批有图片处理失败。' : ($handled > 0 ? sprintf('本批已核查 %d 张。', $handled) : '没有找到待处理图片。'));
        wp_send_json_success(['message' => $message, 'result' => $result]);
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::LEGACY_IMAGE_CRON);
        if ($timestamp) wp_unschedule_event($timestamp, self::LEGACY_IMAGE_CRON);
    }

    /**
     * 空闲时分批转换历史导入图片，并把本地文章中的旧 URL 替换为新附件 URL。
     * 原附件保留，确保遗漏的外部引用不会立刻变成 404。
     */
    public static function convert_legacy_images($force = false, $batch_override = null) {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        // 手动按钮按配置分批执行；队列已由状态页建立，手动批次直接复用已保存队列。
        // 定时任务（无 batch_override）才在本轮开始前重新核对正文引用。
        $maps = $batch_override === null
            ? self::reconcile_referenced_image_maps()
            : get_option('lisun_img_maps', []);
        if (!is_array($maps) || empty($maps)) {
            return [
                'processed' => 0,
                'checked'   => 0,
                'attempted' => 0,
                'failed'    => 0,
                'total'     => 0,
                'batch'     => self::get_legacy_image_batch(),
                'has_more'  => false,
            ];
        }

        $reference_counts = self::get_manual_image_reference_counts($maps);
        // 先建立真实待处理队列；已完成、失败、跳过和未被正文引用的记录
        // 根本不进入队列，也不会被批次扫描。
        $keys = self::get_pending_legacy_image_keys($maps, $reference_counts);
        $total = count($keys);
        $cursor = max(0, (int) get_option('lisun_manual_legacy_image_cursor', 0));
        $processed = 0;
        $checked = 0;
        $attempted = 0;
        $failed = 0;
        $skipped = 0;
        $handled = 0;
        $candidates = 0;
        $batch = $batch_override === null
            ? self::get_legacy_image_batch()
            : max(1, min(100, (int) $batch_override));

        // 每张图片处理后立即保存映射和游标。即使本批后续遇到 502/524，
        // 已完成的图片也不会丢失，下一次请求会从最后保存的位置继续。
        $checkpoint = static function ($persist_maps = true) use (&$maps, $total, $cursor, &$checked) {
            if ($persist_maps) update_option('lisun_img_maps', $maps, false);
            update_option(
                'lisun_manual_legacy_image_cursor',
                $total > 0 ? (($cursor + $checked) % $total) : 0,
                false
            );
        };

        // batch 只按实际被引用、且尚未完成的图片计算；未引用/已处理记录不占用批量名额。
        while ($checked < $total && $candidates < $batch) {
            $index = ($cursor + $checked) % $total;
            $key = $keys[$index];
            $checked++;

            if (!isset($maps[$key]) || !is_array($maps[$key])) continue;
            $handled++;
            $map = $maps[$key];
            unset($maps[$key]['progressive_jpeg_unreferenced'], $maps[$key]['progressive_jpeg_unreferenced_at']);
            $old_attachment_id = (int) ($map['attachment_id'] ?? 0);
            $source_file = $old_attachment_id ? get_attached_file($old_attachment_id) : '';
            // 只有已有完成标记才算历史已完成；本次确认原图已是 progressive
            // 也要计入本次完成数，否则连续处理会显示“0 张”但实际改变了状态。
            $was_completed = !empty($map['progressive_jpeg_attachment_id']);
            $old_url = (string) ($map['url'] ?? '');
            if ($old_url === '' && $old_attachment_id) $old_url = (string) wp_get_attachment_url($old_attachment_id);
            if ($old_url === '') {
                // 没有任何可访问 URL，才可以确定无法转换。
                $skipped++;
                $maps[$key]['progressive_jpeg_skipped'] = 1;
                $maps[$key]['progressive_jpeg_skip_verified'] = 1;
                $maps[$key]['progressive_jpeg_skip_reason'] = '原附件文件或 URL 不可用';
                unset($maps[$key]['progressive_jpeg_error']);
                $checkpoint();
                continue;
            }

            $downloaded_source = false;
            if ($source_file === '' || !is_readable($source_file)) {
                // 附件可能位于 CDN/对象存储；本地文件不可读时先从有效 URL 获取一份临时源文件。
                $downloaded_source = download_url($old_url, 30);
                if (is_wp_error($downloaded_source)) {
                    $download_error_code = (string) $downloaded_source->get_error_code();
                    $download_error_message = $downloaded_source->get_error_message();
                    $is_not_found = in_array($download_error_code, ['http_404', 'http_410'], true);
                    if ($is_not_found) {
                        $skipped++;
                        $maps[$key]['progressive_jpeg_skipped'] = 1;
                        $maps[$key]['progressive_jpeg_skip_verified'] = 1;
                        $maps[$key]['progressive_jpeg_skip_reason'] = '原图片 URL 返回 HTTP 404/410';
                        unset($maps[$key]['progressive_jpeg_error']);
                    } else {
                        $failed++;
                        $maps[$key]['progressive_jpeg_last_attempt'] = time();
                        $maps[$key]['progressive_jpeg_error'] = '本地附件文件不可读，且无法从原 URL 获取：' . $download_error_message;
                    }
                    $checkpoint();
                    continue;
                }
                $source_file = $downloaded_source;
            }
            // 资源后来恢复时允许重新进入处理队列。
            unset($maps[$key]['progressive_jpeg_skipped'], $maps[$key]['progressive_jpeg_skip_verified'], $maps[$key]['progressive_jpeg_skip_reason']);
            if (!$force && !empty($map['progressive_jpeg_last_attempt'])
                && (time() - (int) $map['progressive_jpeg_last_attempt']) < DAY_IN_SECONDS) {
                $checkpoint(false);
                continue;
            }
            // 只有确认有 URL/本地源文件、确实可以进入转换判断时，才占用本批数量。
            // 404、无 URL 的记录只做跳过，不影响后续有效图片凑满本批数量。
            $candidates++;
            $attempted++;

            // 原图已经是 progressive JPEG 时不重复生成新附件，直接完成标记。
            if (self::is_progressive_jpeg($source_file)) {
                foreach ($maps as &$entry) {
                    if (!is_array($entry) || (int) ($entry['attachment_id'] ?? 0) !== $old_attachment_id) continue;
                    $entry['progressive_jpeg_attachment_id'] = $old_attachment_id;
                    $entry['progressive_jpeg_source'] = 'already_progressive';
                    unset($entry['progressive_jpeg_error']);
                }
                unset($entry);
                if ($downloaded_source) @unlink($source_file);
                if (!$was_completed) $processed++;
                $checkpoint();
                continue;
            }

            $maps[$key]['progressive_jpeg_last_attempt'] = time();
            $parser = new self();
            $converted_file = $parser->convert_to_progressive_jpeg($source_file);
            if ($downloaded_source) @unlink($source_file);
            if ($converted_file === false) {
                $failed++;
                $maps[$key]['progressive_jpeg_error'] = '原图片无法读取或服务器没有可用的图片转换库';
                $checkpoint();
                continue;
            }

            $base = sanitize_file_name(pathinfo($source_file, PATHINFO_FILENAME) . '-progressive.jpg');
            $file_array = [
                'name'     => $base,
                'tmp_name' => $converted_file,
                'error'    => 0,
                'size'     => (int) filesize($converted_file),
            ];
            $new_attachment_id = media_handle_sideload($file_array, 0);
            @unlink($converted_file);
            if (is_wp_error($new_attachment_id)) {
                $failed++;
                $maps[$key]['progressive_jpeg_error'] = $new_attachment_id->get_error_message();
                $checkpoint();
                continue;
            }

            $new_url = (string) wp_get_attachment_url($new_attachment_id);
            if ($new_url === '') {
                $failed++;
                $maps[$key]['progressive_jpeg_error'] = '新 JPG 附件 URL 为空';
                $checkpoint();
                continue;
            }

            self::replace_legacy_image_url($old_url, $new_url);
            foreach ($maps as &$entry) {
                if (!is_array($entry) || (int) ($entry['attachment_id'] ?? 0) !== $old_attachment_id) continue;
                $entry['attachment_id'] = (int) $new_attachment_id;
                $entry['url'] = $new_url;
                $entry['thumb'] = wp_get_attachment_image_url($new_attachment_id, 'thumbnail');
                $entry['progressive_jpeg_attachment_id'] = (int) $new_attachment_id;
                unset($entry['progressive_jpeg_error']);
            }
            unset($entry);
            $processed++;
            $checkpoint();
        }

        $checkpoint();
        return [
            'processed' => $processed,
            'checked'   => $checked,
            'attempted' => $attempted,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'handled'   => $handled,
            'candidates'=> $candidates,
            'total'     => $total,
            'batch'     => $batch,
            'has_more'  => self::has_pending_legacy_images($maps, $reference_counts),
        ];
    }

    /**
     * 判断队列中是否还有可继续处理的图片。
     * 已跳过、已处理和已有错误的记录不算“待处理”，避免手动连续执行陷入死循环。
     */
    private static function has_pending_legacy_images($maps, $reference_counts) {
        return !empty(self::get_pending_legacy_image_keys($maps, $reference_counts));
    }

    /**
     * 返回真正待处理的历史图片键。
     * 队列与状态页的“待处理”定义保持一致，已完成记录不会进入批次。
     */
    private static function get_pending_legacy_image_keys($maps, $reference_counts) {
        $keys = [];
        foreach ((array) $maps as $key => $map) {
            if (!is_array($map) || (int) ($reference_counts[(string) $key] ?? 0) === 0) continue;
            if (!empty($map['progressive_jpeg_attachment_id'])
                || !empty($map['progressive_jpeg_skip_verified'])
                || !empty($map['progressive_jpeg_error'])) continue;

            // 本地文件已确认是 progressive JPEG 的记录，直接视为已处理。
            $attachment_id = (int) ($map['attachment_id'] ?? 0);
            $source_file = $attachment_id ? get_attached_file($attachment_id) : '';
            if ($source_file !== '' && is_readable($source_file) && self::is_progressive_jpeg($source_file)) continue;
            $keys[] = (string) $key;
        }
        return $keys;
    }

    private static function replace_legacy_image_url($old_url, $new_url) {
        if ($old_url === '' || $new_url === '' || $old_url === $new_url) return;
        global $wpdb;
        $like = '%' . $wpdb->esc_like($old_url) . '%';
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s",
            $like
        ));
        foreach ((array) $post_ids as $post_id) {
            $content = (string) get_post_field('post_content', (int) $post_id);
            if (strpos($content, $old_url) === false) continue;
            wp_update_post([
                'ID'           => (int) $post_id,
                'post_content' => str_replace($old_url, $new_url, $content),
            ]);
        }
    }

    /**
     * 图片映射表（来自 wp_options lisun_img_maps）
     * 结构：filename => { attachment_id, url, thumb, hash }
     */
    private $img_maps = [];

    /**
     * 哈希 → 映射条目 反向索引（用于内容匹配）
     */
    private $hash_to_map = [];

    /**
     * 从 docx ZIP 中提取的图片
     * 结构：internal_path => { path, hash, binary }
     */
    private $docx_images = [];

    /**
     * 当前文档标题
     */
    private $doc_title = '';

    /**
     * 当前正在解析的文件路径（用于 ZIP 提取）
     */
    private $current_filepath = '';

    /**
     * 当前 DOCX 的 Word 源表格列宽，按 document.xml 中的表格顺序保存。
     * 只用于新导入，不写入或回溯修改已有文章。
     */
    private $source_table_layouts = [];

    /** 当前正在解析的源表格序号。 */
    private $source_table_cursor = 0;

    /** 当前表格的源列宽元数据，供网格输出时避免写入冲突的单元格宽度。 */
    private $active_source_table_layout = null;

    /**
     * 用户上传的原始文件名（用于生成有意义的图片名）
     */
    private $original_filename = '';

    /**
     * 当前文档的图片计数器（每次 parse() 重置）
     */
    private $image_counter = 0;

    /**
     * 已检查过的历史图片 URL/附件可用性，避免同一批导入重复请求。
     */
    private $image_map_health = [];

    public function __construct() {
        $this->img_maps = get_option('lisun_img_maps', []);
        $this->build_hash_index();
    }

    /**
     * 构建哈希反向索引
     */
    private function build_hash_index() {
        $this->hash_to_map = [];
        foreach ($this->img_maps as $filename => $entry) {
            if (!empty($entry['hash'])) {
                $this->hash_to_map[$entry['hash']] = $entry;
            }
        }
    }

    /**
     * 从 .docx 文件中提取所有图片
     *
     * .docx 本质是 ZIP 压缩包，图片存放在 word/media/ 目录下。
     * 此方法直接读取 ZIP，不依赖 PhpWord 的图片提取 API。
     *
     * @param string $filepath .docx 文件路径
     * @return array 提取到的图片列表
     */
    private function extract_docx_images($filepath) {
        $images = [];
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        // .doc 旧格式不支持 ZIP 提取，跳过
        if ($ext !== 'docx') {
            return $images;
        }

        if (!class_exists('ZipArchive')) {
            return $images;
        }

        $zip = new ZipArchive();
        $open_result = $zip->open($filepath);

        if ($open_result !== true) {
            return $images;
        }

        // 遍历 word/media/ 目录
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // 匹配 word/media/ 下的图片文件
            if (stripos($name, 'word/media/') !== 0) {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tiff', 'tif'])) {
                continue;
            }

            $binary = $zip->getFromIndex($i);
            if (empty($binary)) {
                continue;
            }

            $hash = md5($binary);

            $images[$name] = [
                'path'   => $name,
                'name'   => basename($name),
                'hash'   => $hash,
                'binary' => $binary,
            ];
        }

        $zip->close();

        return $images;
    }

    /**
     * 当前文档中提取到的域编号标记（已废弃，改用 XML 预处理）
     */
    private $field_markers = [];

    /**
     * 移除 .docx 中 PhpWord 不支持的图片格式（EMF/WMF）
     *
     * .emf / .wmf 是 Windows 矢量图格式，PHP 的 getimagesize() 无法识别，
     * 会导致 PhpWord 在构造 Image 元素时抛出 InvalidImageException
     * 从而整个文档解析失败。
     *
     * 此方法直接操作 docx 内部的 XML，移除引用不支持的图片的
     * <w:drawing> 元素及其对应的 Relationship。
     *
     * @param string $filepath .docx 文件路径
     * @return string 处理后的文件路径（原地修改则返回原路径）
     */
    private function remove_unsupported_docx_images($filepath) {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($ext !== 'docx') {
            return $filepath;
        }

        if (!class_exists('ZipArchive')) {
            return $filepath;
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            return $filepath;
        }

        // 1. 读取 word/_rels/document.xml.rels，找到指向 .emf/.wmf 的 rId
        $rels_xml = $zip->getFromName('word/_rels/document.xml.rels');
        if (empty($rels_xml)) {
            $zip->close();
            return $filepath;
        }

        $bad_rids = [];
        if (preg_match_all(
            '/<Relationship[^>]*\sId="(rId\d+)"[^>]*\sTarget="([^"]*\.(?:emf|wmf|EMF|WMF))"[^>]*\/?>/i',
            $rels_xml,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $bad_rids[] = $m[1];
            }
        }

        if (empty($bad_rids)) {
            $zip->close();
            return $filepath;
        }

        // 2. 读取 document.xml，移除引用 bad rId 的 <w:drawing> 块
        $doc_xml = $zip->getFromName('word/document.xml');
        $modified = false;
        if (!empty($doc_xml)) {
            foreach ($bad_rids as $rid) {
                $pattern = '/<w:drawing[^>]*>.*?r:embed="' . preg_quote($rid, '/') . '".*?<\/w:drawing>/s';
                $new_xml = preg_replace($pattern, '', $doc_xml, -1, $count);
                if ($count > 0) {
                    $doc_xml = $new_xml;
                    $modified = true;
                }
            }
        }

        // 3. 从 rels 中移除对应的 <Relationship>
        foreach ($bad_rids as $rid) {
            $pattern = '/<Relationship[^>]*\sId="' . preg_quote($rid, '/') . '"[^>]*\/?>\s*/i';
            $new_rels = preg_replace($pattern, '', $rels_xml, -1, $count);
            if ($count > 0) {
                $rels_xml = $new_rels;
                $modified = true;
            }
        }

        $zip->close();

        if (!$modified) {
            return $filepath;
        }

        // 4. 重建 ZIP（ZipArchive 不支持原地修改流）
        $tmp_file = $filepath . '.cleaned_' . uniqid();
        $new_zip = new ZipArchive();
        if ($new_zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $filepath;
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            @unlink($tmp_file);
            return $filepath;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'word/_rels/document.xml.rels') {
                $new_zip->addFromString($name, $rels_xml);
            } elseif ($name === 'word/document.xml') {
                $new_zip->addFromString($name, $doc_xml);
            } else {
                $content = $zip->getFromIndex($i);
                $new_zip->addFromString($name, $content);
            }
        }
        $new_zip->close();
        $zip->close();

        // 5. 用清理后的文件替换原文件
        if (!@rename($tmp_file, $filepath)) {
            @unlink($tmp_file);
        }

        return $filepath;
    }

    /**
     * PhpWord 回退前清理表格单元格内的分页/换行节点。
     *
     * 某些 Word 文件会把普通换行、lastRenderedPageBreak 或
     * pageBreakBefore 写进 w:tc。PhpWord 会尝试向 Cell 添加 PageBreak，
     * 随后抛出 “Cannot add PageBreak in Cell”。PHP XML 预处理和 PhpWord
     * 主路径都需要这个兜底处理。
     *
     * @param string $filepath .docx 路径
     * @return string 原路径
     */
    private function remove_docx_breaks_in_cells($filepath) {
        if (strtolower(pathinfo($filepath, PATHINFO_EXTENSION)) !== 'docx') {
            return $filepath;
        }
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return $filepath;
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            return $filepath;
        }

        $w_ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xml_ns = 'http://www.w3.org/XML/1998/namespace';
        $xml_parts = [];
        $removed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (stripos($name, 'word/') !== 0 || !preg_match('/\.xml$/i', $name)) {
                continue;
            }

            $content = $zip->getFromIndex($i);
            if (!is_string($content) || $content === '') {
                continue;
            }

            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = true;
            $dom->formatOutput = false;
            libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            if (!$loaded) {
                continue;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', $w_ns);
            $changed = false;
            foreach ($xpath->query('//w:tc') as $cell) {
                $nodes = $xpath->query(
                    './/w:br | .//w:lastRenderedPageBreak | .//w:pPr/w:pageBreakBefore',
                    $cell
                );
                foreach ($nodes as $node) {
                    $parent = $node->parentNode;
                    if (!$parent) continue;

                    if ($node->localName === 'br') {
                        // 用空格保留相邻文字的分隔，避免 PhpWord 把它当 PageBreak。
                        $text = $dom->createElementNS($w_ns, 'w:t');
                        $text->setAttributeNS($xml_ns, 'xml:space', 'preserve');
                        $text->appendChild($dom->createTextNode(' '));
                        $parent->replaceChild($text, $node);
                    } else {
                        $parent->removeChild($node);
                    }
                    $removed++;
                    $changed = true;
                }
            }

            if ($changed) {
                $xml_parts[$name] = $dom->saveXML();
            }
        }
        $zip->close();

        if ($removed === 0) {
            return $filepath;
        }

        $tmp_file = $filepath . '.breaks_' . uniqid();
        $new_zip = new ZipArchive();
        if ($new_zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $filepath;
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            $new_zip->close();
            @unlink($tmp_file);
            return $filepath;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $new_zip->addFromString($name, $xml_parts[$name] ?? $zip->getFromIndex($i));
        }
        $new_zip->close();
        $zip->close();

        if (@rename($tmp_file, $filepath)) {
            error_log('LISUN Manual: Removed ' . $removed . ' break node(s) inside DOCX table cells before PhpWord fallback.');
        } else {
            @unlink($tmp_file);
        }

        return $filepath;
    }

    /**
     * 创建 DOCX 工作副本。
     * 所有 XML 预处理只允许写入副本，原始上传文件必须保持不变。
     */
    private function create_docx_working_copy($filepath) {
        $tmp_dir = LISUN_MANUAL_PATH . 'temp/';
        if (!wp_mkdir_p($tmp_dir)) {
            throw new \RuntimeException('DOCX 导入失败：无法创建临时目录。');
        }

        $tmp_file = $tmp_dir . 'docx_import_' . uniqid('', true) . '.docx';
        if (!@copy($filepath, $tmp_file)) {
            throw new \RuntimeException('DOCX 导入失败：无法创建工作副本。');
        }
        return $tmp_file;
    }

    /**
     * 在 PhpWord 解析前，将 DOCX 域结果物化为普通文字。
     *
     * 兼容 PhpWord 不直接解析复杂字段的环境。
     * 对 LISTNUM/AUTONUM 等域，优先使用 Word 已缓存的结果；若结果为空，
     * 从“= 1 \* GB3”这类指令生成 ①、② 等带圈编号。
     *
     * @param string $filepath .docx 路径
     * @return string 原路径
     */
    private function materialize_docx_fields($filepath) {
        if (strtolower(pathinfo($filepath, PATHINFO_EXTENSION)) !== 'docx') {
            return $filepath;
        }
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return $filepath;
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            return $filepath;
        }

        $w_ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xml_ns = 'http://www.w3.org/XML/1998/namespace';
        $xml_parts = [];
        $replaced = 0;

        // 按 XML 文档顺序提取 w:t / w:delText，并保留 tab、换行。
        $collect_text = static function ($node) use (&$collect_text) {
            $text = '';
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $text .= $child->nodeValue;
                    continue;
                }
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                if ($child->localName === 't' || $child->localName === 'delText') {
                    $text .= $child->textContent;
                } elseif ($child->localName === 'tab') {
                    $text .= "\t";
                } elseif ($child->localName === 'br' || $child->localName === 'cr') {
                    $text .= "\n";
                } else {
                    $text .= $collect_text($child);
                }
            }
            return $text;
        };

        $make_text_run = static function ($dom, $source_run, $value) use ($w_ns, $xml_ns) {
            $new_run = $dom->createElementNS($w_ns, 'w:r');
            foreach ($source_run->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'rPr') {
                    $new_run->appendChild($child->cloneNode(true));
                    break;
                }
            }
            $text = $dom->createElementNS($w_ns, 'w:t');
            if (preg_match('/^\s|\s$/u', $value)) {
                $text->setAttributeNS($xml_ns, 'xml:space', 'preserve');
            }
            $text->appendChild($dom->createTextNode($value));
            $new_run->appendChild($text);
            return $new_run;
        };

        $gb3_value = static function ($instruction) {
            if (!preg_match('/=\s*(\d+)\s+\\\*\s*GB3/i', $instruction, $m)) {
                return '';
            }
            $number = (int) $m[1];
            if ($number < 1 || $number > 20) return '';
            return function_exists('mb_chr')
                ? mb_chr(0x245F + $number, 'UTF-8')
                : ['①','②','③','④','⑤','⑥','⑦','⑧','⑨','⑩','⑪','⑫','⑬','⑭','⑮','⑯','⑰','⑱','⑲','⑳'][$number - 1];
        };

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (stripos($name, 'word/') !== 0 || !preg_match('/\.xml$/i', $name)) continue;
            $content = $zip->getFromIndex($i);
            if (!is_string($content) || $content === '') continue;

            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = true;
            libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            if (!$loaded) continue;

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', $w_ns);
            $changed = false;

            foreach ($xpath->query('//w:p') as $paragraph) {
                // 复杂字段：支持段落内任意嵌套容器中的连续 w:r。
                $did_replace = true;
                while ($did_replace) {
                    $did_replace = false;
                    $runs = iterator_to_array($xpath->query('.//w:r', $paragraph));
                    for ($r = 0; $r < count($runs); $r++) {
                        $begin = $xpath->query('./w:fldChar[@w:fldCharType="begin"]', $runs[$r])->item(0);
                        if (!$begin) continue;

                        $depth = 1;
                        $separate = null;
                        $end = null;
                        $instruction = '';
                        for ($j = $r + 1; $j < count($runs); $j++) {
                            $field = $xpath->query('./w:fldChar', $runs[$j])->item(0);
                            if ($field) {
                                $type = (string) $field->getAttributeNS($w_ns, 'fldCharType');
                                if ($type === 'begin') {
                                    $depth++;
                                } elseif ($type === 'separate' && $depth === 1 && $separate === null) {
                                    $separate = $j;
                                } elseif ($type === 'end') {
                                    $depth--;
                                    if ($depth === 0) {
                                        $end = $j;
                                        break;
                                    }
                                }
                            } elseif ($separate === null && $depth === 1) {
                                foreach ($xpath->query('.//w:instrText', $runs[$j]) as $instr) {
                                    $instruction .= (string) $instr->textContent;
                                }
                            }
                        }
                        if ($end === null) continue;

                        $result = '';
                        if ($separate !== null) {
                            for ($j = $separate + 1; $j < $end; $j++) {
                                $result .= $collect_text($runs[$j]);
                            }
                        }
                        if ($result === '') $result = $gb3_value($instruction);
                        if ($result === '') continue;

                        $replacement = $make_text_run($dom, $runs[$r], $result);
                        $begin_parent = $runs[$r]->parentNode;
                        if (!$begin_parent) continue;
                        $begin_parent->insertBefore($replacement, $runs[$r]);
                        for ($j = $r; $j <= $end; $j++) {
                            if ($runs[$j]->parentNode) {
                                $runs[$j]->parentNode->removeChild($runs[$j]);
                            }
                        }
                        $replaced++;
                        $changed = true;
                        $did_replace = true;
                        break;
                    }
                }

                // 简单字段：<w:fldSimple w:instr="...">...</w:fldSimple>。
                foreach (iterator_to_array($xpath->query('.//w:fldSimple', $paragraph)) as $simple) {
                    $result = $collect_text($simple);
                    if ($result === '') {
                        $result = $gb3_value((string) $simple->getAttributeNS($w_ns, 'instr'));
                    }
                    if ($result === '') continue;
                    $source_run = $xpath->query('.//w:r', $simple)->item(0);
                    if (!$source_run || !$simple->parentNode) continue;
                    $replacement = $make_text_run($dom, $source_run, $result);
                    $simple->parentNode->insertBefore($replacement, $simple);
                    $simple->parentNode->removeChild($simple);
                    $replaced++;
                    $changed = true;
                }
            }

            if ($changed) $xml_parts[$name] = $dom->saveXML();
        }
        $zip->close();

        if ($replaced === 0) return $filepath;

        $tmp_file = $filepath . '.fields_' . uniqid();
        $new_zip = new ZipArchive();
        if ($new_zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $filepath;
        }
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            $new_zip->close();
            @unlink($tmp_file);
            return $filepath;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $new_zip->addFromString($name, $xml_parts[$name] ?? $zip->getFromIndex($i));
        }
        $new_zip->close();
        $zip->close();

        if (@rename($tmp_file, $filepath)) {
            error_log('LISUN Manual: Materialized ' . $replaced . ' DOCX field result(s) before parsing.');
        } else {
            @unlink($tmp_file);
        }
        return $filepath;
    }

    public function parse($filepath) {
        // 步骤 0.5：初始化
        $this->current_filepath = $filepath;
        $this->image_counter = 0;
        $this->image_map_health = [];
        $this->doc_title = '';
        $this->source_table_layouts = [];
        $this->source_table_cursor = 0;
        $this->active_source_table_layout = null;

        // 从上传记录中查找原始文件名
        $this->original_filename = '';
        $uploaded_docs = get_option('lisun_uploaded_docs', []);
        foreach ($uploaded_docs as $doc) {
            if (($doc['stored_path'] ?? '') === $filepath) {
                $this->original_filename = $doc['filename'] ?? '';
                break;
            }
        }

        // DOCX 主路径使用 PHP 原生 ZipArchive/DOMDocument 预处理后交给
        // PhpWord 渲染；不依赖外部运行时，也不改动原始上传文件。
        if (!class_exists('PhpOffice\PhpWord\IOFactory')) {
            throw new Exception(__('PhpOffice/PhpWord 未安装，请运行 composer require phpoffice/phpword', 'lisun-manual'));
        }

        $original_filepath = $filepath;
        $working_copy = '';
        if (strtolower(pathinfo($filepath, PATHINFO_EXTENSION)) === 'docx') {
            if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
                throw new \RuntimeException('DOCX 导入失败：PHP 缺少 ZipArchive 或 DOMDocument 扩展。');
            }

            // 先从原始 DOCX 保存 Word 的逻辑列网格；后续工作副本只用于兼容性清理。
            $this->source_table_layouts = $this->extract_docx_table_layouts($filepath);
            $working_copy = $this->create_docx_working_copy($filepath);
            $filepath = $working_copy;
        }

        // 在临时副本上把 LISTNUM/AUTONUM 等域变成普通文字。
        $filepath = $this->materialize_docx_fields($filepath);

        // PhpWord 解析前先清理表格单元格内的分页节点，
        // 避免 PhpWord 在 Cell 中创建 PageBreak 直接中止导入。
        $filepath = $this->remove_docx_breaks_in_cells($filepath);

        // 步骤 0：移除 .docx 中 PhpWord 不支持的图片（EMF/WMF），避免 load() 时崩溃
        $filepath = $this->remove_unsupported_docx_images($filepath);

        // 步骤 0.5：从 docx 中提取所有图片（用于哈希匹配）
        $this->current_filepath = $original_filepath;
        $this->image_counter = 0;

        // 保留初始化阶段根据原始上传路径解析出的文件名；工作副本路径
        // 不会出现在 lisun_uploaded_docs 中。
        $this->docx_images = $this->extract_docx_images($filepath);

        // 按文件扩展名选择 Reader（.doc 旧格式用 MsDoc，.docx 用 Word2007）
        $actual_filepath = $filepath;
        $ext = strtolower(pathinfo($actual_filepath, PATHINFO_EXTENSION));
        $reader_name = ($ext === 'doc') ? 'MsDoc' : 'Word2007';
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($actual_filepath, $reader_name);
        } catch (\Throwable $e) {
            if ($working_copy && file_exists($working_copy)) {
                @unlink($working_copy);
            }
            throw $e;
        }
        $blocks = [];
        $this->doc_title = '';

        foreach ($phpWord->getSections() as $section) {
            $elements = $section->getElements();

            foreach ($elements as $element) {
                $block = $this->parse_element($element);
                if ($block) {
                    if (is_array($block)) {
                        $blocks = array_merge($blocks, $block);
                    } else {
                        $blocks[] = $block;
                    }
                }
            }
        }

        // 清理 DOCX 工作副本
        if ($working_copy && file_exists($working_copy)) {
            @unlink($working_copy);
        }

        // 释放 docx 图片二进制数据（单个文档可能有几十 MB），避免批量导入时内存累加
        $this->docx_images = [];

        // 最终清洗：移除所有 block 中的 HTML/XML 标签碎片
        $blocks = $this->clean_block_tags($blocks);

        // 去重：移除紧邻图片块之前的"裸图段落"（Word 中 inline 图和独立图共存导致重复）
        $blocks = $this->deduplicate_images($blocks);

        // 合并图片+图注
        $blocks = $this->merge_figure_captions($blocks);

        // 自动检测标题模式，<p> → <h2>-<h5>
        $blocks = $this->auto_detect_headings($blocks);

        // 始终取正文第一个非空行作为标题（不管它是不是 h1 样式）
        // 同时从正文中移除这一行，避免标题和正文首行重复
        $title_from_content = '';
        $title_block_index = -1;
        if (!empty($blocks)) {
            foreach ($blocks as $i => $block) {
                $first_text = wp_strip_all_tags($block, true);
                $first_text = trim($first_text);
                if (!empty($first_text)) {
                    $title_from_content = $first_text;
                    $title_block_index = $i;
                    // 截断过长的文字（最多 100 字）
                    if (function_exists('mb_substr')) {
                        $title_from_content = mb_strlen($first_text) > 100
                            ? mb_substr($first_text, 0, 100) . '…'
                            : $first_text;
                    } elseif (strlen($first_text) > 100) {
                        $title_from_content = substr($first_text, 0, 100) . '…';
                    }
                    break;
                }
            }
        }

        if (!empty($title_from_content)) {
            $this->doc_title = $title_from_content;
            // 从正文中移除用作标题的那一行
            if ($title_block_index >= 0) {
                array_splice($blocks, $title_block_index, 1);
            }
        }

        // 最后兜底：文件名
        if (empty($this->doc_title)) {
            $this->doc_title = pathinfo($original_filepath, PATHINFO_FILENAME);
        }

        return [
            'title'  => $this->doc_title,
            'blocks' => $blocks,
        ];
    }

    /**
     * 读取 DOCX 原始 document.xml 中的 w:tblGrid/w:gridCol。
     *
     * gridCol 的单位是 dxa/twips；这里归一化为百分比，交给 HTML
     * <colgroup> 使用。每张表都保留一个位置，即使该表没有有效 grid，
     * 这样才能与 PhpWord 后续解析到的表格顺序保持一致。
     *
     * @param string $filepath
     * @return array
     */
    private function extract_docx_table_layouts($filepath) {
        $layouts = [];
        $zip = new \ZipArchive();

        if ($zip->open($filepath) !== true) {
            return $layouts;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return $layouts;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return $layouts;
        }

        $xpath = new \DOMXPath($dom);
        $word_namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xpath->registerNamespace('w', $word_namespace);

        // 只取正文直属表格，匹配 PhpWord section->getElements() 的顶层顺序；
        // 不把单元格内部的嵌套表格混入游标，避免后续表格错位。
        $tables = $xpath->query('/w:document/w:body/w:tbl');
        if (!$tables) {
            return $layouts;
        }

        foreach ($tables as $table) {
            $widths = [];
            $grid_cols = $xpath->query('./w:tblGrid/w:gridCol', $table);
            if ($grid_cols) {
                foreach ($grid_cols as $grid_col) {
                    $value = $grid_col->getAttributeNS($word_namespace, 'w');
                    if ($value === '') {
                        $value = $grid_col->getAttribute('w:w');
                    }
                    $width = (int) $value;
                    if ($width > 0) {
                        $widths[] = $width;
                    }
                }
            }

            $total = array_sum($widths);
            $percentages = [];
            if ($total > 0 && count($widths) >= 2) {
                foreach ($widths as $width) {
                    $percentages[] = ($width / $total) * 100;
                }
            }

            $layouts[] = [
                'widths'          => $widths,
                'percentages'     => $percentages,
                'logical_columns' => count($widths),
                'has_source_grid' => !empty($percentages),
            ];
        }

        return $layouts;
    }

    /**
     * 解析单个元素
     *
     * @param mixed $element PhpWord element
     * @return string|array|null
     */
    private function parse_element($element) {
        $class = get_class($element);

        switch ($class) {
            case 'PhpOffice\PhpWord\Element\TextRun':
                return $this->parse_text_run($element);

            case 'PhpOffice\PhpWord\Element\Title':
                return $this->parse_title($element);

            case 'PhpOffice\PhpWord\Element\TextBreak':
                return $this->make_block('paragraph', '<br>');

            case 'PhpOffice\PhpWord\Element\ListItem':
                return $this->parse_list_item($element);

            case 'PhpOffice\PhpWord\Element\ListItemRun':
                return $this->parse_list_item_run($element);

            case 'PhpOffice\PhpWord\Element\Table':
                return $this->parse_table($element);

            case 'PhpOffice\PhpWord\Element\Image':
                return $this->parse_image($element);

            case 'PhpOffice\PhpWord\Element\Link':
                return $this->parse_link($element);

            case 'PhpOffice\PhpWord\Element\Text':
                return $this->parse_text($element);

            case 'PhpOffice\PhpWord\Element\PreserveText':
                return $this->parse_preserve_text($element);

            // 忽略这些文档结构元素（不影响内容完整性）
            case 'PhpOffice\PhpWord\Element\TOC':
            case 'PhpOffice\PhpWord\Element\PageBreak':
            case 'PhpOffice\PhpWord\Element\Footnote':
            case 'PhpOffice\PhpWord\Element\Endnote':
            case 'PhpOffice\PhpWord\Element\CheckBox':
            case 'PhpOffice\PhpWord\Element\Field':
                return null;

            default:
                // 未知类型记录日志，方便后续排查
                error_log('LISUN Manual: Unhandled PhpWord element type: ' . $class);
                return null;
        }
    }

    /**
     * 解析 TextRun（含样式的内容块）
     */
    private function parse_text_run($text_run) {
        $elements = $text_run->getElements();

        // 检查是否仅包含一张图片（Word 中常见：图片被包在 TextRun 里）
        if (count($elements) === 1 && $elements[0] instanceof \PhpOffice\PhpWord\Element\Image) {
            return $this->parse_image($elements[0]);
        }

        $content = $this->parse_text_run_elements($elements);

        $content = trim($content);
        if (empty($content)) {
            return null;
        }

        return $this->make_block('paragraph', $content);
    }

    /**
     * 解析标题
     */
    private function parse_title($title) {
        $text = $title->getText();

        // 第一个 h1 作为文档标题
        if (empty($this->doc_title) && $title->getDepth() <= 1) {
            $this->doc_title = $text;
        }

        $level = min($title->getDepth(), 6);
        $level = max($level, 2); // 最少 h2

        if ($title->getDepth() === 1) {
            $level = 1;
        }

        // 通过编号模式修正层级："1、"→h2, "1.1"→h3, "1.2.1"→h4
        if (preg_match('/^(\d+(?:[\.\、]\d+)*)[\.\、\s]/u', $text, $nm)) {
            $depth = count(preg_split('/[\.\、]/', $nm[1]));
            $level = min($depth + 1, 6);
        }

        return $this->make_block('heading', $this->clean_text($text), ['level' => $level]);
    }

    /**
     * 解析纯文本
     */
    private function parse_text($text) {
        $content = trim($text->getText());
        if (empty($content)) {
            return null;
        }

        // 检测图片占位符 [img:filename.jpg]
        if (preg_match('/^\[img:(.+?)\]$/', $content, $matches)) {
            return $this->insert_image_by_filename($matches[1]);
        }

        return $this->make_block('paragraph', $this->clean_text($content));
    }

    /**
     * 解析链接
     */
    private function parse_link($link) {
        $href = $link->getSource();
        $text = $link->getText();

        return $this->make_block(
            'paragraph',
            sprintf('<a href="%s">%s</a>', esc_url($href), esc_html($text))
        );
    }

    /**
     * 解析简单列表项
     */
    private function parse_list_item($item) {
        return $this->make_block(
            'list-item',
            $this->clean_text($item->getText())
        );
    }

    /**
     * 解析富文本列表项（ListItemRun）
     *
     * ListItemRun 是 Word 中最常见的列表元素，支持：
     * - 文本 + 内联格式（加粗、斜体、颜色）
     * - 子元素：Text, TextRun, TextBreak, Link, Image
     * - 嵌套层级：getDepth()
     *
     * 生成 Gutenberg List Item Block，保留富文本。
     */
    private function parse_list_item_run($item) {
        $depth = method_exists($item, 'getDepth') ? $item->getDepth() : 0;
        $elements = $item->getElements();

        $content = $this->parse_text_run_elements($elements);

        if (empty($content)) {
            // 回退到 getText()
            $text = $item->getText();
            if (empty($text)) {
                return null;
            }
            $content = $this->clean_text($text);
        }

        return $this->make_block('list-item', $content);
    }

    /**
     * 解析 PreserveText（保留空格/换行的文本）
     *
     * PreserveText 的 getText() 可能返回数组（文本段数组），
     * 需要逐段拼接。
     */
    private function parse_preserve_text($preserve_text) {
        $raw = $preserve_text->getText();

        if (is_string($raw)) {
            $content = trim($raw);
            if (empty($content)) {
                return null;
            }
            return $this->make_block('paragraph', $this->clean_text($content));
        }

        // 数组格式：可能是 [text1, text2, ...] 或 [{text: "...", ...}, ...]
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $segment) {
                if (is_string($segment)) {
                    $parts[] = $segment;
                } elseif (is_array($segment) && isset($segment['text'])) {
                    $parts[] = $segment['text'];
                } elseif (is_object($segment) && method_exists($segment, 'getText')) {
                    $parts[] = $segment->getText();
                }
            }
            $content = trim(implode('', $parts));
            if (empty($content)) {
                return null;
            }
            return $this->make_block('paragraph', $this->clean_text($content));
        }

        return null;
    }

    /**
     * 将 TextRun / ListItemRun 的子元素列表解析为 HTML
     *
     * 提取自 parse_text_run，供 parse_text_run 和 parse_list_item_run 共用。
     * 处理：Text（含加粗/斜体/颜色）、Link、Image、TextBreak
     *
     * @param array $elements PhpWord 子元素数组
     * @return string HTML 片段
     */
    private function parse_text_run_elements($elements) {
        $result = '';

        foreach ($elements as $el) {
            $class = get_class($el);

            switch ($class) {
                case 'PhpOffice\PhpWord\Element\Text':
                    $text = $el->getText();
                    if ($text === null || $text === '') break;

                    $font = $el->getFontStyle();
                    $content = $this->clean_text($text);

                    if ($font) {
                        if ($font->isBold()) {
                            $content = '<strong>' . $content . '</strong>';
                        }
                        if ($font->isItalic()) {
                            $content = '<em>' . $content . '</em>';
                        }
                        // Word 常把默认黑色也返回为 000000。默认黑色不需要额外的
                        // span，否则会被外部 CSS 单独匹配，导致表格内字号不一致。
                        // 其他真实颜色仍保留 span，不能丢失原文的颜色格式。
                        $color = strtoupper(trim((string) $font->getColor()));
                        $color = ltrim($color, '#');
                        if (strlen($color) === 8 && substr($color, 0, 2) === 'FF') {
                            $color = substr($color, 2);
                        }
                        if (preg_match('/^[0-9A-F]{6}$/', $color) && $color !== '000000') {
                            $content = '<span style="color:#' . $color . '">' . $content . '</span>';
                        }
                    }
                    $result .= $content;
                    break;

                case 'PhpOffice\PhpWord\Element\TextRun':
                    $result .= $this->parse_text_run_elements($el->getElements());
                    break;

                case 'PhpOffice\PhpWord\Element\TextBreak':
                    $result .= '<br>';
                    break;

                case 'PhpOffice\PhpWord\Element\Link':
                    $result .= sprintf(
                        '<a href="%s" target="_blank" rel="noopener">%s</a>',
                        esc_url($el->getSource()),
                        $this->clean_text($el->getText())
                    );
                    break;

                case 'PhpOffice\PhpWord\Element\Image':
                    $desired = $this->build_image_filename($el);
                    $img_data = $this->get_image_data($el, $desired);
                    if ($img_data && !empty($img_data['url'])) {
                        $result .= sprintf(
                            '<img src="%s" alt="%s" style="max-width:100%%;height:auto;">',
                            esc_url($img_data['url']),
                            esc_attr($img_data['name'])
                        );
                    }
                    break;

                default:
                    // Word/PhpWord 可能把单元格文字包在 TextBox、容器或
                    // 其他复合元素中。先递归解析子元素，避免只处理常见
                    // Text/TextRun 时整段内容变成空白。
                    if (method_exists($el, 'getElements')) {
                        $nested = $el->getElements();
                        if (is_array($nested) && !empty($nested)) {
                            $result .= $this->parse_text_run_elements($nested);
                            continue 2;
                        }
                    }

                    // 其他叶子类型（如 PreserveText）尝试 getText()
                    if (method_exists($el, 'getText')) {
                        $t = $el->getText();
                        if (is_string($t) && $t !== '') {
                            $result .= $this->clean_text($t);
                        }
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * 解析表格 — 两遍扫描：先建二维网格，再输出 HTML
     *
     * Word 底层 XML 保留被合并单元格的占位（vMerge continue），
     * 直接按顺序输出 <td> 会导致列错位。这里先铺成完整网格，
     * 算出每个格子的 rowspan/colspan，然后只在"合并起点"输出 <td>。
     */
    private function parse_table($table) {
        $source_layout = $this->source_table_layouts[$this->source_table_cursor] ?? null;
        $this->source_table_cursor++;

        $rows = $table->getRows();
        if (empty($rows)) {
            return '';
        }

        $row_count   = count($rows);
        $col_count   = $this->get_table_col_count($rows);
        $header_rows = $this->detect_header_rows($rows);

        // 只有源网格列数与 PhpWord 解析出的逻辑列数一致时才应用，
        // 避免异常 DOCX 的表格顺序或网格信息错位。
        $has_source_layout = is_array($source_layout)
            && !empty($source_layout['has_source_grid'])
            && (int) ($source_layout['logical_columns'] ?? 0) === $col_count;
        $this->active_source_table_layout = $has_source_layout ? $source_layout : null;

        // 第一遍：建二维网格
        $grid = $this->build_table_grid($rows, $row_count, $col_count);

        // 第二遍：遍历网格输出 HTML
        $table_class = 'lisun-manual-table';
        $source_attributes = '';
        if ($has_source_layout) {
            $table_class .= ' lisun-source-layout-table';
            $source_attributes = ' data-lisun-source-layout="word-grid"'
                . ' data-lisun-table-columns="' . (int) $col_count . '"';
        }
        $html = '<table class="' . esc_attr($table_class) . '"'
            . $source_attributes . ' style="width:100%;">';

        if ($has_source_layout) {
            $html .= '<colgroup>';
            foreach ($source_layout['percentages'] as $percentage) {
                $width_text = rtrim(rtrim(number_format((float) $percentage, 4, '.', ''), '0'), '.');
                $html .= '<col style="width:' . esc_attr($width_text) . '%;">';
            }
            $html .= '</colgroup>';
        }

        if ($header_rows > 0) {
            $html .= '<thead>';
            for ($r = 0; $r < $header_rows; $r++) {
                $html .= $this->render_grid_row($grid, $r, $col_count, 'th');
            }
            $html .= '</thead>';
        }

        $html .= '<tbody>';
        for ($r = $header_rows; $r < $row_count; $r++) {
            $html .= $this->render_grid_row($grid, $r, $col_count, 'td');
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $this->active_source_table_layout = null;

        // 表格直接输出，前端 JS 会自动包裹 .table-wrapper（全屏按钮）
        return $html;
    }

    /**
     * 第一遍扫描：建二维网格
     *
     * Word 对每个 vMerge='restart' 格向下数 continue 格来计算 rowspan。
     * continue 格在 XML 中仍然占位，但在 HTML 中不应输出。
     *
     * 网格结构：$grid[$r][$c] = [
     *     'type'        => 'origin' | 'covered',
     *     'colspan'     => int,
     *     'rowspan'     => int,
     *     'content'     => string,
     *     'extra_class' => string,
     *     'width_style' => string,
     * ]
     *
     * @return array 二维网格
     */
    private function build_table_grid($rows, $row_count, $col_count) {
        // 初始化为 null（未放置）
        $grid = array_fill(0, $row_count, array_fill(0, $col_count, null));

        for ($r = 0; $r < $row_count; $r++) {
            $cells = $rows[$r]->getCells();

            // 用纯粹的列累加指针（不跳过 covered）来定位 continue 格，
            // 保证 continue 格与上方 restart 格在同一网格列
            $col = 0;

            foreach ($cells as $cell) {
                if ($col >= $col_count) break;

                $style     = $cell->getStyle();
                $grid_span = $style ? max(1, (int) $style->getGridSpan()) : 1;
                $v_merge   = $style ? $style->getVMerge() : null;
                $colspan   = min($grid_span, $col_count - $col);

                // 渲染用属性
                $width_style = '';
                if (!$this->active_source_table_layout
                    && $style
                    && method_exists($style, 'getWidth')
                    && $style->getWidth()
                ) {
                    $width_style = ' style="width:' . round(floatval($style->getWidth()) / 15, 1) . '%"';
                }
                $extra_class = '';
                if ($style && method_exists($style, 'getVAlign') && $style->getVAlign() === 'center') {
                    $extra_class = ' lisun-td-middle';
                }
                $content = $this->parse_cell_content($cell);

                if ($v_merge === 'continue') {
                    // ——— 被上方 rowspan 覆盖的格子 ———
                    // 如果已被 restart 格的向下计数提前标记，跳过（避免 rowspan 重复累加）
                    if (isset($grid[$r][$col]) && $grid[$r][$col]['type'] === 'covered') {
                        $col += $colspan;
                        continue;
                    }

                    $origin_r = $r - 1;
                    while ($origin_r >= 0) {
                        if (isset($grid[$origin_r][$col])
                            && $grid[$origin_r][$col]['type'] === 'origin'
                        ) {
                            $grid[$origin_r][$col]['rowspan']++;
                            break;
                        }
                        $origin_r--;
                    }
                    // 标记当前位置为被覆盖
                    for ($cc = $col; $cc < $col + $colspan; $cc++) {
                        if ($cc < $col_count) {
                            $grid[$r][$cc] = ['type' => 'covered'];
                        }
                    }
                } else {
                    // ——— 普通格 或 vMerge='restart' ———
                    $rowspan = 1;
                    if ($v_merge === 'restart') {
                        // 向后续行搜索同列的 continue 格
                        for ($nr = $r + 1; $nr < $row_count; $nr++) {
                            $ncells = $rows[$nr]->getCells();
                            // 后续行也用纯顺序指针定位 continue
                            $ncol   = 0;
                            $found  = false;
                            foreach ($ncells as $ncell) {
                                if ($ncol >= $col_count) break;
                                $ns  = $ncell->getStyle() ? max(1, (int) $ncell->getStyle()->getGridSpan()) : 1;
                                $nvm = $ncell->getStyle() ? $ncell->getStyle()->getVMerge() : null;
                                if ($ncol === $col && $nvm === 'continue') {
                                    $rowspan++;
                                    $found = true;
                                    // 标记 continue 格为 covered
                                    for ($cc = $ncol; $cc < $ncol + $ns && $cc < $col_count; $cc++) {
                                        $grid[$nr][$cc] = ['type' => 'covered'];
                                    }
                                    break;
                                }
                                $ncol += $ns;
                            }
                            if (! $found) break;
                        }
                    }

                    // 放置 origin 格（占据 grid_span 列）
                    for ($cc = $col; $cc < $col + $colspan; $cc++) {
                        $grid[$r][$cc] = [
                            'type'        => 'origin',
                            'colspan'     => $colspan,
                            'rowspan'     => $rowspan,
                            'content'     => $content,
                            'extra_class' => $extra_class,
                            'width_style' => $width_style,
                        ];
                    }

                    // rowspan > 1：标记后续行被覆盖的所有网格位置
                    if ($rowspan > 1) {
                        for ($sr = $r + 1; $sr < $r + $rowspan; $sr++) {
                            for ($sc = $col; $sc < $col + $colspan; $sc++) {
                                if ($sc < $col_count && ! isset($grid[$sr][$sc])) {
                                    $grid[$sr][$sc] = ['type' => 'covered'];
                                }
                            }
                        }
                    }
                }

                $col += $colspan;
            }
        }

        return $grid;
    }

    /**
     * 检测表头行数
     *
     * 策略：
     * 1. 第一行如果所有带文字单元格都加粗 → 表头
     * 2. 连续多行加粗 → 多行表头
     */
    private function detect_header_rows($rows) {
        $header_count = 0;
        $max_header = min(3, count($rows));

        for ($i = 0; $i < $max_header; $i++) {
            $cells = $rows[$i]->getCells();
            if (empty($cells)) continue;

            $all_bold = true;
            $has_text = false;

            foreach ($cells as $cell) {
                $elements = $cell->getElements();
                foreach ($elements as $el) {
                    if ($el instanceof \PhpOffice\PhpWord\Element\Text) {
                        $has_text = true;
                        $font = $el->getFontStyle();
                        if (!$font || !$font->isBold()) {
                            $all_bold = false;
                        }
                    } elseif ($el instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        $has_text = true;
                        foreach ($el->getElements() as $s) {
                            if ($s instanceof \PhpOffice\PhpWord\Element\Text) {
                                $f = $s->getFontStyle();
                                if (!$f || !$f->isBold()) {
                                    $all_bold = false;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

            if ($has_text && $all_bold) {
                $header_count++;
            } else {
                break;
            }
        }

        // 不再默认第一行为表头。
        // 没有明确加粗表头时，第一行可能是普通数据行；强行拆成
        // <thead>/<tbody> 会让跨越分组边界的 rowspan 失效。
        return $header_count;
    }

    /**
     * 获取表格最大列数
     */
    private function get_table_col_count($rows) {
        $max = 0;
        foreach ($rows as $row) {
            $count = 0;
            foreach ($row->getCells() as $cell) {
                $span = $cell->getStyle() ? $cell->getStyle()->getGridSpan() : 1;
                $count += max(1, $span);
            }
            $max = max($max, $count);
        }
        return $max;
    }

    /**
     * 第二遍扫描：渲染网格中的一行
     *
     * 遍历该行网格列，只在 'origin' 格输出 <td>/<th> 并带 colspan/rowspan。
     * 输出后跳过 colspan 覆盖的列。'covered' 和 null 格只输出空占位。
     *
     * @param array  $grid        二维网格
     * @param int    $r           行号
     * @param int    $col_count   总列数
     * @param string $default_tag 'th' 或 'td'
     * @return string <tr>...</tr>
     */
    private function render_grid_row($grid, $r, $col_count, $default_tag) {
        $html = '<tr>';

        for ($c = 0; $c < $col_count; $c++) {
            // null — 网格空隙，填空白格
            if (! isset($grid[$r][$c])) {
                $html .= '<' . $default_tag . '>&nbsp;</' . $default_tag . '>';
                continue;
            }

            $cell = $grid[$r][$c];

            // covered — 被上方 rowspan 覆盖，跳过（不输出任何格）
            if ($cell['type'] === 'covered') continue;

            // origin — 输出带 colspan/rowspan 的单元格
            $colspan_attr = $cell['colspan'] > 1 ? ' colspan="' . $cell['colspan'] . '"' : '';
            $rowspan_attr = $cell['rowspan'] > 1 ? ' rowspan="' . $cell['rowspan'] . '"' : '';

            $html .= '<' . $default_tag . $colspan_attr . $rowspan_attr
                   . ' class="' . $cell['extra_class'] . '"' . $cell['width_style'] . '>';
            $html .= $cell['content'];
            $html .= '</' . $default_tag . '>';

            // 跳过 colspan 覆盖的列（-1 因为 for 循环会 +1）
            $c += $cell['colspan'] - 1;
        }

        $html .= '</tr>';
        return $html;
    }

    /**
     * 解析单元格内容（支持富文本、图片、换行、链接）
     */
    /**
     * 解析单元格内容（复用统一的子元素解析器）
     */
    private function parse_cell_content($cell) {
        $elements = $cell->getElements();
        $result = $this->parse_text_run_elements($elements);

        // 空单元格用 &nbsp; 撑住（避免无边框塌陷）
        return $result !== '' ? $result : '&nbsp;';
    }

    /**
     * 解析图片元素（正文中的图片）
     */
    private function parse_image($image) {
        // 构造规范文件名：doc_code + fig序号，传给上传函数用于策略2兜底
        $desired_filename = $this->build_image_filename($image);

        $img_data = $this->get_image_data($image, $desired_filename);
        if (!$img_data || empty($img_data['url'])) {
            // 图片未能解析，返回占位提示
            $placeholder_name = $img_data['name'] ?? 'unknown';
            return '<p><em>[' . esc_html($placeholder_name) . ' - ' . __('图片未能解析', 'lisun-manual') . ']</em></p>';
        }

        $image_name = $img_data['name'];
        $image_url = $img_data['url'];
        $image_id = $img_data['attachment_id'] ?? 0;

        // 构建图片 HTML（保留 wp-image-{id} class 以便 WP 自动生成 srcset）
        $img_class = $image_id ? 'wp-image-' . intval($image_id) : '';
        $block_content = '<figure>';
        $block_content .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($image_name) . '" class="' . $img_class . '">';
        $block_content .= '</figure>';

        return $block_content;
    }

    /**
     * 通过占位符 [img:filename.jpg] 插入图片
     */
    private function insert_image_by_filename($filename) {
        if (isset($this->img_maps[$filename])) {
            $map = $this->img_maps[$filename];
            $id = $map['attachment_id'] ?? 0;
            $img_class = $id ? 'wp-image-' . intval($id) : '';
            $block_content = '<figure>';
            $block_content .= '<img src="' . esc_url($map['url']) . '" alt="' . esc_attr($filename) . '" class="' . $img_class . '">';
            $block_content .= '</figure>';

            return $block_content;
        }

        // 映射表中未找到，返回占位提示
        return '<p><em>[' . esc_html($filename) . ' - ' . __('图片未找到', 'lisun-manual') . ']</em></p>';
    }

    /**
     * 从 PhpWord Image 元素获取对应 URL
     *
     * 匹配策略（按优先级）：
     * 1. 从 docx ZIP 提取该图片 → 计算哈希 → 在用户上传的图片中按哈希匹配
     * 2. 哈希匹配不到 → 直接把提取的图片上传到 WP 媒体库
     * 3. 旧格式 .doc（无法从 ZIP 提取）→ 按文件名模糊匹配（兜底）
     *
     * @param mixed $image PhpWord Image 元素
     * @return array|null ['name' => string, 'url' => string, 'attachment_id' => int]
     */
    private function get_image_data($image, $desired_filename = null) {
        // 获取 PhpWord 内部的图片引用路径，例如 media/image1.png
        $internal_path = '';
        if (method_exists($image, 'getSource')) {
            $internal_path = $image->getSource();
        }
        if (empty($internal_path) && method_exists($image, 'getName')) {
            $internal_path = $image->getName();
        }

        $image_name = basename($internal_path);

        // ========== 策略 1：内容哈希匹配 ==========
        if (!empty($this->docx_images)) {
            // 先精确匹配内部路径
            $extracted = null;
            if (isset($this->docx_images[$internal_path])) {
                $extracted = $this->docx_images[$internal_path];
            } else {
                // PhpWord 的内部路径可能带前导目录或格式略有不同
                // 尝试用 basename 匹配
                foreach ($this->docx_images as $path => $info) {
                    if (basename($path) === $image_name) {
                        $extracted = $info;
                        break;
                    }
                }
            }

            if ($extracted && !empty($extracted['hash'])) {
                // 按哈希查用户上传的映射表
                if (isset($this->hash_to_map[$extracted['hash']])) {
                    $map = $this->get_usable_image_map($this->hash_to_map[$extracted['hash']]);
                    if ($map) {
                        return [
                            'name'          => $image_name,
                            'url'           => $map['url'],
                            'attachment_id' => $map['attachment_id'] ?? 0,
                        ];
                    }
                    // 历史映射指向 404/已删除附件时，不再盲目复用，
                    // 继续走当前二进制重新上传。
                }

                // ========== 策略 2：直接上传提取的图片 ==========
                $upload_result = $this->upload_extracted_image($extracted, $desired_filename);
                if ($upload_result) {
                    return $upload_result;
                }
            }
        }

        // ========== 策略 3：文件名兜底匹配（旧 .doc 格式或映射表有手动添加的条目）==========
        if ($image_name && isset($this->img_maps[$image_name])) {
            $map = $this->get_usable_image_map($this->img_maps[$image_name]);
            if ($map) {
                return [
                    'name'          => $image_name,
                    'url'           => $map['url'],
                    'attachment_id' => $map['attachment_id'] ?? 0,
                ];
            }
        }

        // 全部策略失败
        return [
            'name'          => $image_name ?: 'unknown',
            'url'           => '',
            'attachment_id' => 0,
        ];
    }

    /**
     * 将从 docx 提取的图片上传到 WordPress 媒体库
     *
     * @param array       $extracted        从 extract_docx_images() 提取的图片信息
     * @param string|null $desired_filename 期望的文件名（不含扩展名），如 "LSG-1950-fig01"，
     *                                      由调用方（parse_image / parse_text_run_elements）传入，
     *                                      携带业务上下文。传 null 时退化为哈希前缀命名。
     * @return array|null 成功返回 ['name', 'url', 'attachment_id']，失败返回 null
     */
    private function upload_extracted_image($extracted, $desired_filename = null) {
        // 避免重复上传同一张图片：检查是否已有同哈希的映射条目
        if (!empty($extracted['hash']) && isset($this->hash_to_map[$extracted['hash']])) {
            $map = $this->get_usable_image_map($this->hash_to_map[$extracted['hash']]);
            if ($map) {
                // 如果旧条目是哈希前缀命名（doc_xxx-figN）且新名字不同，更新映射
                $this->maybe_update_map_name($extracted, $desired_filename);
                // 改名后重新获取映射（URL 可能已变）
                $map = $this->hash_to_map[$extracted['hash']] ?? $map;
                return [
                    'name'          => $extracted['name'],
                    'url'           => $map['url'],
                    'attachment_id' => $map['attachment_id'] ?? 0,
                ];
            }
        }

        // 检查持久化的 lisun_img_maps（跨导入批次去重）
        if (!empty($extracted['hash'])) {
            $persistent_maps = get_option('lisun_img_maps', []);
            foreach ($persistent_maps as $name => $map) {
                if (!empty($map['hash']) && $map['hash'] === $extracted['hash']) {
                    $usable_map = $this->get_usable_image_map($map);
                    if (!$usable_map) {
                        // 映射存在但附件已经删除/URL 已失效，继续上传当前图片。
                        continue;
                    }
                    // 找到同哈希的已上传图片，复用
                    $this->img_maps[$extracted['name']] = $usable_map;
                    $this->hash_to_map[$extracted['hash']] = $usable_map;
                    // 如果旧条目是哈希前缀命名（doc_xxx-figN）且新名字不同，更新映射
                    $this->maybe_update_map_name($extracted, $desired_filename);
                    // 改名后重新获取映射（URL 可能已变）
                    $map = $this->hash_to_map[$extracted['hash']] ?? $usable_map;
                    return [
                        'name'          => $extracted['name'],
                        'url'           => $map['url'],
                        'attachment_id' => $map['attachment_id'] ?? 0,
                    ];
                }
            }
        }

        // 写入临时文件
        $tmp_dir = LISUN_MANUAL_PATH . 'temp/';
        wp_mkdir_p($tmp_dir);

        $tmp_file = $tmp_dir . uniqid('docx_img_') . '_' . $extracted['name'];
        $written = file_put_contents($tmp_file, $extracted['binary']);

        if ($written === false) {
            return null;
        }

        // 上传到媒体库
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // 构造上传用的文件名：优先用调用方传入的规范名，否则退化为哈希前8位
        $ext_from_name = strtolower(pathinfo($extracted['name'], PATHINFO_EXTENSION));
        // 从二进制头部确认真实图片格式（更可靠）
        $ext = $this->detect_image_ext($extracted['binary'], $ext_from_name);
        $upload_tmp_file = $tmp_file;
        $converted_tmp_file = $this->convert_to_progressive_jpeg($tmp_file);
        if ($converted_tmp_file !== false) {
            $upload_tmp_file = $converted_tmp_file;
            $ext = 'jpg';
        }
        if ($desired_filename) {
            $upload_name = sanitize_file_name($desired_filename . '.' . $ext);
        } else {
            // 退化为哈希前缀，至少比 A1B2C3 有可追溯性
            $upload_name = sanitize_file_name('img-' . substr($extracted['hash'], 0, 8) . '.' . $ext);
        }

        $file_array = [
            'name'     => $upload_name,
            'tmp_name' => $upload_tmp_file,
            'error'    => 0,
            'size'     => (int) filesize($upload_tmp_file),
        ];

        $attachment_id = media_handle_sideload($file_array, 0);

        // 清理临时文件
        @unlink($tmp_file);
        if ($converted_tmp_file !== false) @unlink($converted_tmp_file);

        if (is_wp_error($attachment_id)) {
            return null;
        }

        $url = wp_get_attachment_url($attachment_id);

        // 将新上传的图片也加入映射表（下次匹配直接命中）
        $new_map = [
            'attachment_id' => $attachment_id,
            'url'           => $url,
            'thumb'         => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
            'hash'          => $extracted['hash'],
        ];
        if ($converted_tmp_file !== false) {
            $new_map['progressive_jpeg_attachment_id'] = (int) $attachment_id;
        }
        $this->img_maps[$extracted['name']] = $new_map;
        $this->hash_to_map[$extracted['hash']] = $this->img_maps[$extracted['name']];

        // 持久化到 wp_options
        update_option('lisun_img_maps', $this->img_maps);

        return [
            'name'          => $extracted['name'],
            'url'           => $url,
            'attachment_id' => $attachment_id,
        ];
    }

    /**
     * 只返回仍然可用的历史图片映射。
     *
     * 旧映射可能仍有 hash，但附件文件已经被删除，或保存的 URL 指向
     * 已不存在的旧 uploads 路径。仅按 hash 复用会把 404 再次写进新文章。
     * 本地附件存在时优先生成当前 URL；本地不可读时再检查历史 URL。
     *
     * @param mixed $map
     * @return array|null
     */
    private function get_usable_image_map($map) {
        if (!is_array($map)) return null;

        $attachment_id = (int) ($map['attachment_id'] ?? 0);
        if ($attachment_id > 0 && function_exists('get_attached_file')) {
            $local_file = get_attached_file($attachment_id);
            if ($local_file && is_readable($local_file)) {
                $current_url = function_exists('wp_get_attachment_url')
                    ? wp_get_attachment_url($attachment_id)
                    : '';
                if ($current_url) {
                    $map['url'] = $current_url;
                    return $map;
                }
            }
        }

        $url = trim((string) ($map['url'] ?? ''));
        if ($url === '' || !function_exists('wp_remote_head')) return null;

        // 一个导入批次内按 URL 缓存，而不是按 attachment_id + URL 缓存；
        // 历史映射可能有多个 attachment_id，但同一 URL 只允许探测一次。
        $cache_key = $url;
        if (array_key_exists($cache_key, $this->image_map_health)) {
            return $this->image_map_health[$cache_key] ? $map : null;
        }

        try {
            $response = wp_remote_head($url, [
                'timeout'     => 2,
                'redirection' => 1,
            ]);
        } catch (\Throwable $e) {
            // 网络异常只代表这条历史映射不可复用，不能中断整个导入。
            $response = null;
        }
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $usable = $code >= 200 && $code < 400;
        $this->image_map_health[$cache_key] = $usable;

        if (!$usable) {
            error_log('LISUN Manual: stale image mapping skipped (' . $code . '): ' . $url);
        }

        return $usable ? $map : null;
    }

    /**
     * 将 Word 内嵌图片转成质量 85 的 progressive JPEG。
     * 透明区域使用白色背景；无法处理的格式则返回 false，调用方保留原图。
     */
    private function convert_to_progressive_jpeg($source_file) {
        $destination = $source_file . '.progressive.jpg';

        if (class_exists('Imagick')) {
            try {
                $image = new \Imagick();
                $image->readImage($source_file);
                if (method_exists($image, 'setIteratorIndex') && $image->getNumberImages() > 1) {
                    $image->setIteratorIndex(0);
                }
                if (method_exists($image, 'autoOrientImage')) {
                    $image->autoOrientImage();
                }
                $image->setImageBackgroundColor(new \ImagickPixel('white'));
                if (defined('Imagick::ALPHACHANNEL_REMOVE') && method_exists($image, 'setImageAlphaChannel')) {
                    $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                }
                $image->setImageFormat('jpeg');
                if (defined('Imagick::INTERLACE_PLANE')) {
                    if (method_exists($image, 'setInterlaceScheme')) {
                        $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
                    } elseif (method_exists($image, 'setImageInterlaceScheme')) {
                        $image->setImageInterlaceScheme(\Imagick::INTERLACE_PLANE);
                    }
                }
                $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $image->setImageCompressionQuality(85);
                $image->stripImage();
                $written = $image->writeImage($destination);
                $image->clear();
                $image->destroy();
                if ($written && is_file($destination) && filesize($destination) > 0) return $destination;
            } catch (\Throwable $e) {
                error_log('LISUN Manual: Imagick progressive JPEG conversion failed: ' . $e->getMessage());
                @unlink($destination);
            }
        }

        // 没有 Imagick 时回退到 GD；透明图片同样铺白底，并开启交错扫描。
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $source = @imagecreatefromstring((string) @file_get_contents($source_file));
            if ($source !== false) {
                $width = imagesx($source);
                $height = imagesy($source);
                $canvas = imagecreatetruecolor($width, $height);
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefill($canvas, 0, 0, $white);
                imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
                if (function_exists('imageinterlace')) imageinterlace($canvas, true);
                $written = imagejpeg($canvas, $destination, 85);
                imagedestroy($source);
                imagedestroy($canvas);
                if ($written && is_file($destination) && filesize($destination) > 0) return $destination;
            }
        }

        @unlink($destination);
        error_log('LISUN Manual: no usable image library for progressive JPEG conversion; original image kept');
        return false;
    }

    /**
     * 构造规范图片文件名：{文档编号}-fig{序号}
     *
     * 从 PhpWord 内部图片路径（如 media/image3.png）中提取原始序号，
     * 结合源文件名作为文档编号，生成可读的上传文件名。
     *
     * 信息传递路径：阶段2（PhpWord 定位图片，知道序号）→ 阶段1（上传函数，仅有二进制数据）
     *
     * @param mixed $image PhpWord Image 元素
     * @return string|null 如 "LSG-1950-fig03"，无法确定时返回 null
     */
    private function build_image_filename($image) {
        // 从 PhpWord 内部路径提取原始图片序号（如 media/image3.png → 3）
        $internal_path = '';
        if (method_exists($image, 'getSource')) {
            $internal_path = $image->getSource();
        }
        if (empty($internal_path) && method_exists($image, 'getName')) {
            $internal_path = $image->getName();
        }

        // 使用文档内图片出现顺序作为序号（而非 Word 内部 media/imageN 的编号）
        // 这样 fig01/fig02 对应文档中第 1 张/第 2 张图，而非 Word 插入序号
        $seq = ++$this->image_counter;

        // 从原始上传文件名派生文档编号：如 "LPCE-2 说明书.docx" → "LPCE-2"
        // 优先用原始文件名，其次用文件路径（兜底）
        $source_name = !empty($this->original_filename)
            ? $this->original_filename
            : $this->current_filepath;
        $doc_code = pathinfo($source_name, PATHINFO_FILENAME);
        // 去掉中文字符和多余空格
        $doc_code = preg_replace('/[\x{4e00}-\x{9fff}\x{3000}-\x{303f}\x{ff00}-\x{ffef}]/u', '', $doc_code);
        $doc_code = trim(preg_replace('/\s+/', '-', $doc_code), '-');
        if (empty($doc_code)) {
            // 文件名全中文或无法提取，用哈希前 6 位保证唯一性
            $doc_code = substr(md5($source_name), 0, 6);
        }

        return sprintf('%s-fig%02d', $doc_code, max(1, $seq));
    }

    /**
     * 当哈希匹配复用旧附件时，如果旧附件名是哈希前缀格式（doc_xxx-figN），
     * 且有更好的新名字（从原始文件名派生），则重命名附件并更新映射表。
     *
     * 这样重新导入同一篇文档时，图片自动从 "doc_6a79eba04e2fe-fig72.jpg"
     * 变成 "LPCE-2-fig01.jpg"。
     */
    private function maybe_update_map_name($extracted, $desired_filename) {
        if (empty($desired_filename) || empty($extracted['hash'])) return;

        $hash = $extracted['hash'];
        $persistent_maps = get_option('lisun_img_maps', []);
        $old_name = '';

        // hash_to_map 是 hash => map_array，key 是 hash 不是文件名。
        // 要找到旧文件名，需要遍历 img_maps（filename => map_array）和 persistent_maps。
        $sources = [$this->img_maps, $persistent_maps];
        foreach ($sources as $source) {
            foreach ($source as $name => $map) {
                if (!empty($map['hash']) && $map['hash'] === $hash) {
                    $old_name = $name;
                    break 2;
                }
            }
        }
        if (empty($old_name)) return;

        // 判断旧名是否为哈希前缀格式（doc_[a-f0-9]+-figN.ext 或 img-[a-f0-9]+-figN.ext）
        $is_hash_name = (bool) preg_match('/^(doc_[a-f0-9]+|img-[a-f0-9]{8})-fig\d+\.\w+$/i', $old_name);
        if (!$is_hash_name) return;  // 已经是好名字，不需要更新

        // 检查是否真的需要更新（新旧名含义相同则跳过）
        $ext = strtolower(pathinfo($old_name, PATHINFO_EXTENSION));
        $new_name = sanitize_file_name($desired_filename . '.' . $ext);
        if ($old_name === $new_name) return;

        // 获取已有的 attachment_id
        $map = $persistent_maps[$old_name] ?? $this->img_maps[$old_name] ?? [];
        $attachment_id = $map['attachment_id'] ?? 0;
        if (!$attachment_id) return;

        // 重命名附件（post_title + 文件名 + meta）
        $old_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (empty($old_file)) return;

        $old_path = wp_upload_dir()['basedir'] . '/' . $old_file;
        $new_rel = dirname($old_file) . '/' . $new_name;
        $new_path = wp_upload_dir()['basedir'] . '/' . $new_rel;

        // 避免覆盖已有文件
        if ($old_path === $new_path) return;
        if (file_exists($new_path)) {
            // 目标文件已存在，跳过重命名，但依然更新映射表（指向旧文件）
            error_log("LISUN Manual: skip rename, target exists: {$new_name}");
        } else {
            if (!@rename($old_path, $new_path)) {
                error_log("LISUN Manual: rename failed: {$old_path} -> {$new_path}");
                return;
            }
            update_post_meta($attachment_id, '_wp_attached_file', $new_rel);
        }

        // 更新 wp_posts（post_title, post_name, guid）
        wp_update_post([
            'ID'         => $attachment_id,
            'post_title' => pathinfo($new_name, PATHINFO_FILENAME),
            'post_name'  => sanitize_title(pathinfo($new_name, PATHINFO_FILENAME)),
        ]);

        // 更新附件元数据中的文件名
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (is_array($metadata)) {
            $metadata['file'] = $new_rel;
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        // 更新映射表：旧 key → 新 key
        $new_entry = array_merge($map, ['url' => wp_upload_dir()['baseurl'] . '/' . $new_rel]);
        unset($persistent_maps[$old_name]);
        $persistent_maps[$new_name] = $new_entry;
        update_option('lisun_img_maps', $persistent_maps);

        // 同步 in-memory 缓存
        unset($this->img_maps[$old_name]);
        $this->img_maps[$new_name] = $new_entry;

        // 重建 hash 索引
        $this->hash_to_map = [];
        foreach ($this->img_maps as $n => $m) {
            if (!empty($m['hash'])) {
                $this->hash_to_map[$m['hash']] = $m;
            }
        }

        error_log("LISUN Manual: renamed attachment {$old_name} -> {$new_name}");
    }

    /**
     * 从图片二进制头部检测真实扩展名
     *
     * WordPress 的 media_handle_sideload 依赖文件扩展名判断 MIME 类型，
     * 如果扩展名不准确可能导致上传失败或 MIME 识别错误。
     * 此方法直接读取二进制 magic bytes 返回准确扩展名。
     *
     * @param string $binary    图片二进制数据
     * @param string $fallback  无法识别时的回退扩展名（如从文件名推导）
     * @return string 如 'jpg', 'png', 'gif', 'webp'
     */
    private function detect_image_ext($binary, $fallback = 'jpg') {
        $head = substr($binary, 0, 12);

        // PNG: 89 50 4E 47
        if (strpos($head, "\x89PNG") === 0) return 'png';
        // JPEG: FF D8 FF
        if (strpos($head, "\xFF\xD8\xFF") === 0) return 'jpg';
        // GIF: 47 49 46 38
        if (strpos($head, "GIF8") === 0) return 'gif';
        // WebP: 52 49 46 46 ... 57 45 42 50
        if (strpos($head, "RIFF") === 0 && strpos(substr($binary, 8, 4), "WEBP") === 0) return 'webp';
        // BMP: 42 4D
        if (strpos($head, "BM") === 0) return 'bmp';

        // 无法识别，回退
        $fallback = strtolower($fallback);
        return in_array($fallback, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']) ? $fallback : 'jpg';
    }

    /**
     * 清洗纯文本：先去掉 HTML/XML 标签再转义
     *
     * Word 文档中可能包含直接写入的 HTML 标签（如 &lt;strong&gt;），
     * 也可能因 DOCX 预处理 / PhpWord 解析异常产生标签碎片，
     * 需要先 strip 再 esc，避免标签残留在最终输出中。
     *
     * 注意：不使用宽泛的 \b[a-z]+> 正则，它会误删合法文本中的
     * "英文词>" 模式（如 "timeout>30s"、"OK>Cancel"）。
     *
     * @param string $text 原始文本
     * @return string 清洗后的安全文本
     */
    private function clean_text($text) {
        $text = (string) $text;
        // 去掉任何完整的 HTML/XML 标签（含 < 和 > 的）
        // wp_strip_all_tags 只处理标准 HTML 标签，对 XML 命名空间标签也有效
        $text = wp_strip_all_tags($text);
        // 去掉 XML 命名空间标签残留（如 <w:rPr> 等 Word 内部标记）
        // 这些是 Word 内部 XML，不会出现在正常文本中
        $text = preg_replace('/<\/?[a-z]+:[a-z]+[^>]*>/i', '', $text);
        // 去掉已知 HTML 标签的尾部碎片（如 "strong>", "/em>" 等）
        // 仅匹配完整的标签名，不匹配部分碎片（如 "ong>"），避免误删合法文本
        $text = $this->remove_tail_fragments($text);
        // HTML 实体转义 — 任何残留的 < > 都会被转为 &lt; &gt; 安全显示
        $text = esc_html(trim($text));
        return $text;
    }

    /**
     * 移除已知 HTML 标签的尾部碎片
     *
     * 当 HTML 标签丢失开头的 < 时，会留下 "strong>", "/em>" 等尾部碎片。
     * 此方法仅匹配完整的已知标签名，不匹配部分碎片（如 "ong>"），
     * 以避免误删 "song>", "long>" 等合法文本。
     *
     * 用两个正则：
     * 1. 开标签尾部：完整标签名 + >  （如 strong>, em>）
     * 2. 闭标签尾部：/ + 完整标签名 + >  （如 /strong>, /em>）
     *
     * @param string $text
     * @return string
     */
    private function remove_tail_fragments($text) {
        // 仅使用完整的 HTML 标签名，不使用子串如 ong/rong/trong/ption
        $tag_names = 'strong'
            . '|em|br|p|span|div|li|ul|ol'
            . '|table|tbody|thead|tr|td|th|sup|sub'
            . '|figure|figcaption|caption'
            . '|section|header|footer|nav|main'
            . '|article|aside|html|head|body'
            . '|title|meta|link|style|script'
            . '|form|input|button|select|option|label|textarea'
            . '|fieldset|legend|iframe|video|audio|source|canvas';
        // 开标签尾部碎片（如 strong>, em>）
        // (?<![a-zA-Z]) 确保标签名前面不是字母，避免匹配到英文单词内部的模式
        // (?<!\/) 排除 / 开头的情况（/strong> 交给闭标签正则处理）
        $text = preg_replace('/(?<!\/)(?<![a-zA-Z])(' . $tag_names . ')>+/i', '', $text);
        // 闭标签尾部碎片（如 /strong>, /em>）- / 之后紧跟标签名
        $text = preg_replace('/\/(' . $tag_names . ')>+/i', '', $text);
        return $text;
    }

    /**
     * 清洗所有 block 中的 HTML/XML 标签碎片
     *
     * 保护 Gutenberg 注释（<!-- wp:... --><!-- /wp:... -->）和已知的合法标签
     * （<strong>, <em>, <br>, <a>, <img>, <span>, <table>, <figure> 等），
     * 删除其他孤立的 HTML/XML 标签碎片。
     *
     * @param array $blocks
     * @return array
     */
    private function clean_block_tags($blocks) {
        $keep_tags = '<strong><em><br><a><img><span><table><thead><tbody><tr><th><td>'
                   . '<figure><figcaption><p><h1><h2><h3><h4><h5><h6><ul><ol><li><div><sup><sub>';

        $cleaned = [];
        foreach ($blocks as $block) {
            $block = $this->strip_tag_fragments($block, $keep_tags);
            $cleaned[] = $block;
        }
        return $cleaned;
    }

    /**
     * 移除文本中的 HTML/XML 标签碎片，保留白名单中的合法标签
     *
     * 注意：不使用宽泛的 \b[a-z]+> 或 <[a-z]+\b 正则，
     * 它们会误删合法文本中的 "英文词>" 和 "<开头" 模式。
     *
     * @param string $text
     * @param string $keep_tags strip_tags 的允许标签
     * @return string
     */
    private function strip_tag_fragments($text, $keep_tags) {
        // 用 strip_tags 保留合法标签，去掉不在白名单中的标签
        $text = strip_tags($text, $keep_tags);
        // XML 命名空间标签（<w:xxx>, <v:xxx> 等）
        // strip_tags 不认识冒号命名空间，需要单独清理
        $text = preg_replace('/<\/?[a-z]+:[a-z]+[^>]*>/i', '', $text);
        // 注意：此处不能调用 remove_tail_fragments()！
        // 该方法会匹配 p>、strong>、/em> 等模式，会错误地破坏
        // strip_tags 刚刚保留下来的合法 HTML 标签（如 <p>、<strong>、</em>）
        return $text;
    }

    /**
     * 去重图片：Word 文档中的同一张图可能同时以 inline 和独立 figure
     * 形式嵌入，PhpWord 解析出两遍。这里检测"裸图段落"紧邻 wp:image 块
     * 的情况，移除多余的裸图段落。
     *
     * 裸图段落 = wp:paragraph 块，内容仅含 <img> 标签（无文字）。
     */
    private function deduplicate_images($blocks) {
        $count = count($blocks);
        $keep  = [];

        for ($i = 0; $i < $count; $i++) {
            $block = $blocks[$i];

            // 判断当前是否为"裸图段落"（<p> 内仅含 <img>，无文字）
            $is_bare_img = (strpos($block, '<p') === 0)
                        && $this->block_is_bare_image($block);

            if (!$is_bare_img) {
                $keep[] = $block;
                continue;
            }

            // 裸图段落：检查下一个是否为 <figure> 块（或再下一个，中间可隔空白）
            $next_idx = $i + 1;
            while ($next_idx < $count && trim($blocks[$next_idx]) === '') {
                $next_idx++;
            }

            if ($next_idx < $count && strpos($blocks[$next_idx], '<figure') === 0) {
                // 裸图后面紧跟 figure → 跳过裸图，保留正规图块
                continue;
            }

            // 没有紧邻的 figure，保留此裸图（可能是文档中唯一的位置）
            $keep[] = $block;
        }

        return $keep;
    }

    /**
     * 判断一个 wp:paragraph 块是否仅包含 <img> 标签（无实质文字）
     */
    private function block_is_bare_image($block) {
        // 提取标签之间的正文（去掉 HTML 标签）
        $text = wp_strip_all_tags($block);
        $text = trim($text);

        // 有文字内容 → 不是裸图
        if ($text !== '') {
            return false;
        }

        // 确认有 <img> 标签
        return (stripos($block, '<img') !== false);
    }

    /**
     * 合并图片和紧随其后的图注段落
     * 匹配 "图1" "图2-3" "Figure 1" "Fig 1" "图片1" 等模式，
     * 将图注文字嵌入 <figcaption>，与图片共处一个 <figure>。
     */
    private function merge_figure_captions($blocks) {
        $merged = [];
        $count  = count($blocks);
        $caption_patterns = [
            '/<p>(?:<[^>]+>)*\s*(图\s*\d+(?:[\.\-]\d+)*|图片\s*\d+(?:[\.\-]\d+)*)\b[^<]*/i',
            '/<p>(?:<[^>]+>)*\s*(Figure\s+\d+(?:[\.\-]\d+)*|Fig\.?\s*\d+(?:[\.\-]\d+)*)\b[^<]*/i',
        ];

        for ($i = 0; $i < $count; $i++) {
            $block = $blocks[$i];

            // 判断是否为图片块（<figure> 含 <img>）
            $is_image = (strpos($block, '<figure') === 0);
            if (!$is_image) {
                $merged[] = $block;
                continue;
            }

            // 检查下一个 block 是否为段落 + 图注
            $next = $i + 1;
            if ($next >= $count) {
                $merged[] = $block;
                continue;
            }

            $next_block = $blocks[$next];
            // 只处理段落
            if (strpos($next_block, '<p') !== 0) {
                $merged[] = $block;
                continue;
            }

            $caption_text = '';
            foreach ($caption_patterns as $pattern) {
                if (preg_match($pattern, $next_block, $m)) {
                    $caption_text = trim(wp_strip_all_tags($m[1] ?? ''));
                    // 提取图注后面的描述文字
                    $rest = trim(wp_strip_all_tags(preg_replace($pattern, '', $next_block)));
                    if (!empty($rest)) {
                        $caption_text .= ' ' . $rest;
                    }
                    break;
                }
            }

            if (empty($caption_text)) {
                $merged[] = $block;
                continue;
            }

            // 图注作为 <p class="manual-caption"> 放在 <figure> 后面
            // 同时兼容已有 <figcaption>（不清除）
            $merged[] = $block;
            $merged[] = '<p class="manual-caption">' . esc_html($caption_text) . '</p>';
            $i++; // 跳过图注 block
        }

        return $merged;
    }

    /**
     * 构建 Gutenberg Block
     */
    /**
     * 自动检测段落中的标题模式，转为 <h2>-<h5> 标签
     *
     * 支持模式：
     *   "1.1 产品概述" → <h3>  (深度2)
     *   "1.1.1 详细说明" → <h4>  (深度3)
     *   "1.2.3.4 xxx" → <h5>  (深度4+)
     *   "1、概述" → <h2>  (顿号分隔)
     *   "一、概述" → <h2>  (中文数字)
     *   "第1章 xxx" → <h2>
     *
     * 排除：0.x 测量值、小数+单位组合、表格内段落
     */
    private function auto_detect_headings($blocks) {
        $result = [];
        foreach ($blocks as $block) {
            if (!preg_match('/^<p>(.*?)<\/p>$/s', $block, $pm)) {
                $result[] = $block;
                continue;
            }
            $inner = $pm[1];
            $text = trim(wp_strip_all_tags($inner));
            if (empty($text)) {
                $result[] = $block;
                continue;
            }

            $h = $this->try_convert_heading($inner, $text);
            $result[] = $h ?: $block;
        }
        return $result;
    }

    /**
     * 尝试将一段文本转为标题 HTML
     * @return string|null 标题 HTML 或 null（不是标题）
     */
    private function try_convert_heading($inner_html, $plain_text) {
        // ── Pattern 1: 多级编号 "1.1 xxx", "1.1.1 xxx", "1.2.3.4 xxx" ──
        if (preg_match('/^(\d+(?:[\.\、]\d+)+)\s*[\.\、\s]?\s*(.*)$/u', $plain_text, $m)) {
            $num   = $m[1];
            $rest  = trim($m[2]);
            $depth = count(preg_split('/[\.\、]/', $num));

            // 排除 0.x 测量值
            if (preg_match('/^0\.\d/', $num)) return null;

            // depth=2 排除测量值（小数 + 短单位）
            if ($depth === 2) {
                if (mb_strlen($rest) <= 5 && preg_match('/^[a-zA-Z\d\s°%²³\.\,\;\、\-\℃°\/]+$/u', $rest)) return null;
                if (preg_match('/^\d+\.\d+$/', $num) && preg_match('/^[a-zA-Z\d\s°%²³\.\,\;\、\-\℃°\/]+$/u', $rest)) return null;
            }

            $level = min($depth + 1, 5); // depth2→h3, depth3→h4, depth4+→h5
            $tag   = 'h' . $level;
            $cls   = 'lisun-num-heading lisun-num-lv' . $depth;

            // depth=2: 插入编号徽章，并从正文移除编号（兼容内联标签如 <strong>1.1</strong>）
            if ($depth === 2) {
                $inner_html = self::strip_heading_number($inner_html, $num, $plain_text);
                return '<' . $tag . ' class="' . $cls . '">'
                     . '<span class="lisun-num-badge">' . esc_html($num) . '</span> '
                     . $inner_html
                     . '</' . $tag . '>';
            }

            return '<' . $tag . ' class="' . $cls . '">' . $inner_html . '</' . $tag . '>';
        }

        // ── Pattern 2: "第X章 xxx" ──
        if (preg_match('/^第(\d+)[章節节]\s*(.*)$/u', $plain_text, $m)) {
            $inner_html = preg_replace('/^第\d+[章節节]\s*/u', '', $inner_html, 1);
            return '<h2 class="lisun-num-heading lisun-num-lv1">' . $inner_html . '</h2>';
        }

        // ── Pattern 3: "一、xxx" / "二、xxx" 中文数字+顿号 ──
        if (preg_match('/^[一二三四五六七八九十]{1,2}[\、]\s*(.+)$/u', $plain_text, $m)) {
            return '<h2 class="lisun-num-heading lisun-num-lv1">' . $inner_html . '</h2>';
        }

        // ── Pattern 4: "1、" / "2、" 单数字+顿号（文字≥4字才算标题）──
        if (preg_match('/^(\d+)[、]\s*(.+)$/u', $plain_text, $m)) {
            $rest = trim($m[2]);
            if (mb_strlen($rest) >= 4) {
                return '<h2 class="lisun-num-heading lisun-num-lv1">' . $inner_html . '</h2>';
            }
        }

        return null;
    }

    /**
     * Strip heading number from HTML content, handling inline tags like <strong>1.1</strong>.
     *
     * Tokenises HTML into <tag> / text runs, walks the text consuming $num_len rendered
     * characters while tracking the open-tag stack, then skips any closing tags that
     * balance the consumed region before returning the tail.
     */
    private static function strip_heading_number($inner_html, $num, $plain_text) {
        if (preg_match('/^\s*' . preg_quote($num, '/') . '\s*[\.\、]?\s*/u', $plain_text, $m)) {
            $num_len = mb_strlen($m[0]);
        } else {
            return $inner_html;
        }

        // Tokenise: keep tags as separate tokens, discard empty runs
        $raw = preg_split('/(<[^>]*>)/', $inner_html, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $consumed = 0;   // rendered-text chars consumed
        $open     = [];  // stack of tag names currently open

        foreach ($raw as $i => $token) {
            // Track tag stack for every token (text or tag)
            if ($token !== '' && $token[0] === '<') {
                if (preg_match('/^<\s*(\/?\w+)/', $token, $tm)) {
                    $tname = strtolower($tm[1]);
                    if ($tname[0] !== '/') {
                        $open[] = $tname;
                    } else {
                        $closing = substr($tname, 1);
                        for ($k = count($open) - 1; $k >= 0; $k--) {
                            if ($open[$k] === $closing) { array_splice($open, $k, 1); break; }
                        }
                    }
                }
                continue;
            }

            // Text token
            $tlen = mb_strlen($token);
            if ($consumed + $tlen < $num_len) {
                $consumed += $tlen;
                continue;
            }

            // ── Cut point reached ──
            // Keep the unconsumed portion of this text token
            $keep = mb_substr($token, $num_len - $consumed);
            // Ltrim, but only for ASCII whitespace (mb chars are content we want)
            $keep = ltrim($keep);

            // Rebuild from the next token, but first skip closing tags
            // that balance whatever is still open from before the cut
            $j = $i + 1;
            $n = count($raw);
            while ($j < $n && !empty($open) && $raw[$j] !== '' && $raw[$j][0] === '<') {
                if (preg_match('/^<\s*(\/\w+)/', $raw[$j], $tm)) {
                    $closing = strtolower(substr($tm[1], 1));
                    $found = false;
                    for ($k = count($open) - 1; $k >= 0; $k--) {
                        if ($open[$k] === $closing) { array_splice($open, $k, 1); $found = true; break; }
                    }
                    if ($found) { $j++; continue; }
                }
                break; // not a matching closing tag
            }

            // Also skip whitespace-only text tokens right after the cut
            while ($j < $n && $raw[$j] !== '' && $raw[$j][0] !== '<' && trim($raw[$j]) === '') {
                $j++;
            }

            $result = $keep;
            for (; $j < $n; $j++) {
                $result .= $raw[$j];
            }
            return $result;
        }

        return $inner_html; // fallback
    }

    private function make_block($type, $content, $attrs = []) {
        switch ($type) {
            case 'paragraph':
                return '<p>' . $content . '</p>';

            case 'heading':
                $level = $attrs['level'] ?? 2;
                return '<h' . $level . '>' . $content . '</h' . $level . '>';

            case 'list-item':
                // 编号已内嵌在文字中（①②③），输出为普通段落避免多余符号
                return '<p>' . $content . '</p>';

            case 'image':
                return '<figure><img src="' . $content . '"></figure>';

            default:
                return '<p>' . $content . '</p>';
        }
    }
}
