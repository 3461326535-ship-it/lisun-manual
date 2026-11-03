<?php
/**
 * 重复图片扫描与清理模块
 *
 * 分批异步处理，每批最多 50 个附件，避免 Cloudflare 524 超时。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_Image_Cleaner {

    use Lisun_Manual_Nonce_Trait;

    const CHUNK_SIZE = 50; // 每批处理的附件数

    public static function init() {
        add_action('wp_ajax_lisun_image_scan', [__CLASS__, 'ajax_scan_chunk']);
        add_action('wp_ajax_lisun_image_clean', [__CLASS__, 'ajax_clean_chunk']);
    }

    // ==================== 分批扫描 ====================

    /**
     * AJAX: 分批扫描（前端串行调用，直到 has_more=false）
     */
    public static function ajax_scan_chunk() {
        self::check_nonce();

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $accumulated = isset($_POST['accumulated']) ? json_decode(stripslashes($_POST['accumulated']), true) : null;
        if (!is_array($accumulated)) $accumulated = ['hash_groups' => [], 'total_attachments' => 0, 'total_bytes' => 0, 'orphan_count' => 0];

        // 1. 收集所有待扫描的附件 ID（只做一次，offset=0 时）
        $all_ids = null;
        if ($offset === 0) {
            $all_ids = self::collect_attachment_ids();
            if (empty($all_ids)) {
                wp_send_json_success([
                    'done'              => true,
                    'total_attachments'  => 0,
                    'duplicate_groups'   => [],
                    'wasted_bytes'       => 0,
                    'wasted_mb'          => 0,
                    'orphan_count'       => 0,
                    'total_mb'           => 0,
                ]);
                return;
            }
            // 缓存到 transient，后续批次复用
            set_transient('lisun_img_scan_ids', $all_ids, 300);
            set_transient('lisun_img_scan_refs', [
                'referenced'  => self::get_all_referenced_attachment_ids(),
                'map_ids'     => self::get_map_attachment_ids(),
            ], 300);
        } else {
            $all_ids = get_transient('lisun_img_scan_ids');
            if (empty($all_ids)) {
                wp_send_json_error(['message' => '扫描会话已过期，请重新扫描。']);
                return;
            }
        }

        $refs = get_transient('lisun_img_scan_refs') ?: ['referenced' => [], 'map_ids' => []];
        $referenced_ids = $refs['referenced'];
        $map_attachment_ids = $refs['map_ids'];
        $all_referenced = array_unique(array_merge($referenced_ids, $map_attachment_ids));

        // 2. 处理本批（只取 CHUNK_SIZE 个）
        $batch = array_slice($all_ids, $offset, self::CHUNK_SIZE);
        $hash_groups = $accumulated['hash_groups'];

        foreach ($batch as $attach_id) {
            $attach_id = intval($attach_id);
            $file_path = get_attached_file($attach_id);
            if (!$file_path || !file_exists($file_path)) continue;

            $md5 = md5_file($file_path);
            $file_size = filesize($file_path);
            $accumulated['total_bytes'] += $file_size;
            $accumulated['total_attachments']++;

            $is_referenced = in_array($attach_id, $all_referenced);
            if (!$is_referenced) $accumulated['orphan_count']++;

            if (!isset($hash_groups[$md5])) $hash_groups[$md5] = [];
            $hash_groups[$md5][] = [
                'id'           => $attach_id,
                'file'         => basename($file_path),
                'path'         => $file_path,
                'url'          => wp_get_attachment_url($attach_id),
                'size'         => $file_size,
                'referenced'   => $is_referenced,
                'in_img_maps'  => in_array($attach_id, $map_attachment_ids),
                'in_content'   => in_array($attach_id, $referenced_ids),
                'date'         => get_the_date('Y-m-d H:i:s', $attach_id),
            ];
        }

        $accumulated['hash_groups'] = $hash_groups;
        $offset += count($batch);
        $has_more = $offset < count($all_ids);

        // 3. 返回分片结果（中间批次不计算重复详情，最后一批统一计算）
        if ($has_more) {
            wp_send_json_success([
                'done'       => false,
                'offset'     => $offset,
                'accumulated'=> json_encode($accumulated),
                'total_ids'  => count($all_ids),
                'processed'  => $offset,
                'progress'   => round(($offset / count($all_ids)) * 100),
            ]);
        }

        // 最后一批：计算重复详情
        $duplicate_groups = [];
        $wasted_bytes = 0;

        foreach ($hash_groups as $md5 => $group) {
            if (count($group) <= 1) continue;
            usort($group, function ($a, $b) {
                $a_score = ($a['referenced'] ? 100 : 0) + ($a['in_content'] ? 50 : 0) + ($a['in_img_maps'] ? 30 : 0);
                $b_score = ($b['referenced'] ? 100 : 0) + ($b['in_content'] ? 50 : 0) + ($b['in_img_maps'] ? 30 : 0);
                if ($a_score !== $b_score) return $b_score - $a_score;
                return strcmp($a['date'], $b['date']);
            });

            $keeper = $group[0];
            $duplicates = array_slice($group, 1);
            foreach ($duplicates as $dup) {
                $wasted_bytes += $dup['size'];
                $meta = wp_get_attachment_metadata($dup['id']);
                if (!empty($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size_info) {
                        if (!empty($size_info['file'])) {
                            $thumb_path = dirname($dup['path']) . '/' . $size_info['file'];
                            if (file_exists($thumb_path)) $wasted_bytes += filesize($thumb_path);
                        }
                    }
                }
            }

            $duplicate_groups[] = [
                'md5'        => $md5,
                'count'      => count($group),
                'keeper'     => $keeper,
                'duplicates' => $duplicates,
                'total_size' => array_sum(array_column($group, 'size')),
            ];
        }

        // 缓存扫描结果供清理使用
        set_transient('lisun_img_scan_result', $duplicate_groups, 300);

        $total_dup = array_sum(array_column($duplicate_groups, 'count')) - count($duplicate_groups);

        wp_send_json_success([
            'done'              => true,
            'total_attachments'  => $accumulated['total_attachments'],
            'duplicate_groups'   => $duplicate_groups,
            'duplicate_count'    => $total_dup,
            'wasted_bytes'       => $wasted_bytes,
            'wasted_mb'          => round($wasted_bytes / 1048576, 2),
            'orphan_count'       => $accumulated['orphan_count'],
            'total_mb'           => round($accumulated['total_bytes'] / 1048576, 2),
        ]);

        delete_transient('lisun_img_scan_ids');
        delete_transient('lisun_img_scan_refs');
    }

    // ==================== 分批清理 ====================

    /**
     * AJAX: 分批清理（前端串行调用，每次只处理一个重复图片，避免超时）
     */
    public static function ajax_clean_chunk() {
        self::check_nonce();
        set_time_limit(30);

        $group_index = isset($_POST['group_index']) ? intval($_POST['group_index']) : 0;
        $dup_index   = isset($_POST['dup_index'])   ? intval($_POST['dup_index'])   : 0;
        $dry_run     = !empty($_POST['dry_run']);

        $results = isset($_POST['accumulated_results']) ? json_decode(stripslashes($_POST['accumulated_results']), true) : null;
        if (!is_array($results)) {
            $results = ['deleted_count' => 0, 'freed_bytes' => 0, 'errors' => [], 'updated_maps' => 0, 'updated_posts' => 0];
        }

        $duplicate_groups = get_transient('lisun_img_scan_result');
        if (empty($duplicate_groups)) {
            wp_send_json_error(['message' => '扫描结果已过期，请重新扫描后再清理。']);
            return;
        }

        $total_groups = count($duplicate_groups);

        // 计算总重复数（用于精确进度）
        $total_dups = 0;
        foreach ($duplicate_groups as $g) {
            $total_dups += count($g['duplicates']);
        }

        // 全部组处理完毕
        if ($group_index >= $total_groups) {
            $results['freed_mb'] = round($results['freed_bytes'] / 1048576, 2);
            delete_transient('lisun_img_scan_result');
            wp_send_json_success(['done' => true, 'results' => $results]);
            return;
        }

        $group      = $duplicate_groups[$group_index];
        $keeper     = $group['keeper'];
        $keeper_url = $keeper['url'];
        $keeper_id  = $keeper['id'];
        $duplicates = $group['duplicates'];

        // 当前组所有重复项已处理完 → 跳到下一组
        if ($dup_index >= count($duplicates)) {
            $next_group = $group_index + 1;
            $has_more   = $next_group < $total_groups;

            // 累计已处理数量
            $processed = 0;
            for ($i = 0; $i <= $group_index; $i++) {
                $processed += count($duplicate_groups[$i]['duplicates']);
            }

            wp_send_json_success([
                'done'                => !$has_more,
                'group_index'         => $group_index,
                'dup_index'           => $dup_index,
                'next_group_index'    => $has_more ? $next_group : $group_index,
                'next_dup_index'      => 0,
                'total_groups'        => $total_groups,
                'accumulated_results' => json_encode($results),
                'progress'            => $has_more ? round(($processed / max($total_dups, 1)) * 100) : 100,
            ]);
            return;
        }

        // ---- 只处理当前这一个重复项 ----
        $dup    = $duplicates[$dup_index];
        $dup_id = $dup['id'];

        $img_maps     = get_option('lisun_img_maps', []);
        $maps_updated = false;

        // 更新 lisun_img_maps
        foreach ($img_maps as $name => &$entry) {
            if (!empty($entry['attachment_id']) && intval($entry['attachment_id']) === $dup_id) {
                $entry['attachment_id'] = $keeper_id;
                $entry['url']           = $keeper_url;
                $entry['thumb']         = wp_get_attachment_image_url($keeper_id, 'thumbnail');
                $results['updated_maps']++;
                $maps_updated = true;
            }
        }
        unset($entry);

        // 更新 post_content 引用
        $results['updated_posts'] += self::update_content_image_refs($dup_id, $keeper_id, $keeper_url);

        if (!$dry_run) {
            // 删除重复附件
            $delete_result = wp_delete_attachment($dup_id, true);
            if ($delete_result === false) {
                $results['errors'][] = "无法删除附件 #{$dup_id}: {$dup['file']}";
            } else {
                $results['deleted_count']++;
                $results['freed_bytes'] += $dup['size'];
                // 缩略图大小已经在 wp_delete_attachment 中随物理文件删除了，
                // 这里只统计即可
                $meta = wp_get_attachment_metadata($dup_id);
                if (!empty($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size_info) {
                        if (!empty($size_info['file'])) {
                            $thumb_path = dirname($dup['path']) . '/' . $size_info['file'];
                            if (file_exists($thumb_path)) {
                                $results['freed_bytes'] += filesize($thumb_path);
                            }
                        }
                    }
                }
            }
        }

        if ($maps_updated && !$dry_run) {
            update_option('lisun_img_maps', $img_maps);
        }

        // ---- 计算下一个位置 ----
        $next_dup         = $dup_index + 1;
        $has_more_in_group = $next_dup < count($duplicates);
        $has_more_groups   = ($group_index + 1) < $total_groups;
        $has_more          = $has_more_in_group || $has_more_groups;

        // 累计已处理数量
        $processed = 0;
        for ($i = 0; $i < $group_index; $i++) {
            $processed += count($duplicate_groups[$i]['duplicates']);
        }
        $processed += $next_dup;

        wp_send_json_success([
            'done'                => !$has_more,
            'group_index'         => $group_index,
            'dup_index'           => $dup_index,
            'next_group_index'    => $has_more_in_group ? $group_index : ($has_more_groups ? $group_index + 1 : $group_index),
            'next_dup_index'      => $has_more_in_group ? $next_dup : 0,
            'total_groups'        => $total_groups,
            'accumulated_results' => json_encode($results),
            'progress'            => $has_more ? round(($processed / max($total_dups, 1)) * 100) : 100,
        ]);
    }

    // ==================== 辅助方法 ====================

    private static function collect_attachment_ids() {
        global $wpdb;

        $referenced_ids = self::get_all_referenced_attachment_ids();
        $map_ids = self::get_map_attachment_ids();
        $all_referenced = array_unique(array_merge($referenced_ids, $map_ids));

        $where = '';
        if (!empty($all_referenced)) {
            $where = "ID IN (" . implode(',', array_map('intval', $all_referenced)) . ") OR ";
        }
        $where .= "post_mime_type LIKE 'image/%'";

        // 限制总数避免无限扫描
        $ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'attachment' AND ({$where})
            ORDER BY ID DESC
            LIMIT 5000
        ");

        return $ids ?: [];
    }

    private static function get_map_attachment_ids() {
        $img_maps = get_option('lisun_img_maps', []);
        $ids = [];
        foreach ($img_maps as $entry) {
            if (!empty($entry['attachment_id'])) $ids[] = intval($entry['attachment_id']);
        }
        return array_unique($ids);
    }

    private static function get_all_referenced_attachment_ids() {
        global $wpdb;

        $ids = [];
        $posts = $wpdb->get_results("
            SELECT ID, post_content FROM {$wpdb->posts}
            WHERE post_type = 'manual' AND post_content LIKE '%wp-image-%'
        ");

        foreach ($posts as $post) {
            if (preg_match_all('/wp-image-(\d+)/', $post->post_content, $m)) {
                foreach ($m[1] as $id) $ids[] = intval($id);
            }
        }

        $child_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
              AND post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'manual')
        ");
        foreach ($child_ids as $id) $ids[] = intval($id);

        return array_unique($ids);
    }

    private static function update_content_image_refs($old_id, $new_id, $new_url) {
        global $wpdb;
        $count = 0;
        $old_url = wp_get_attachment_url($old_id);

        $posts = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_content FROM {$wpdb->posts}
            WHERE post_type = 'manual'
              AND (post_content LIKE %s OR post_content LIKE %s)
        ", '%wp-image-' . $old_id . '%', '%' . $wpdb->esc_like($old_id) . '%'));

        foreach ($posts as $post) {
            $content = $post->post_content;
            $changed = false;

            if (strpos($content, 'wp-image-' . $old_id) !== false) {
                $content = str_replace('wp-image-' . $old_id, 'wp-image-' . $new_id, $content);
                $changed = true;
            }
            if (strpos($content, '"id":' . $old_id) !== false) {
                $content = preg_replace('/"id":\s*' . $old_id . '/', '"id":' . $new_id, $content);
                $changed = true;
            }
            if ($old_url && strpos($content, $old_url) !== false) {
                $content = str_replace($old_url, $new_url, $content);
                $changed = true;
            }

            if ($changed) {
                // Use the WordPress write path so post_modified, object cache
                // and save_post_manual translation-staleness detection stay in
                // sync with the actual Chinese source HTML.
                $updated = wp_update_post([
                    'ID' => (int) $post->ID,
                    'post_content' => $content,
                ], true);
                if (!is_wp_error($updated)) $count++;
            }
        }

        return $count;
    }
}
