<?php
/**
 * 产品视频兼容层。
 *
 * 视频只允许来自正式产品数据，且只返回“操作视频”。不再从全站新闻、
 * 案例等公开 CPT 的正文中猜测视频，避免型号部分匹配导致错误关联。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Lisun_Manual_Product_Video_Compat')) {
    class Lisun_Manual_Product_Video_Compat {
        private static $cache = [];

        /** 显式重新同步时丢弃本次请求中可能产生的空查询缓存。 */
        public static function clear_cache() {
            self::$cache = [];
        }

        public static function get_by_model($model, $language = '') {
            $model = trim((string) $model);
            if ($model === '') {
                return [];
            }

            $cache_key = strtolower($model) . '|' . strtolower((string) $language);
            if (array_key_exists($cache_key, self::$cache)) {
                return self::$cache[$cache_key];
            }

            $videos = self::call_existing_lookup($model, $language);
            if (!empty($videos)) {
                return self::$cache[$cache_key] = $videos;
            }

            return self::$cache[$cache_key] = self::find_product_cpt_videos($model, $language);
        }

        private static function call_existing_lookup($model, $language) {
            if (!function_exists('lisun_get_product_videos_by_model')) {
                return [];
            }

            try {
                $reflection = new ReflectionFunction('lisun_get_product_videos_by_model');
                $args = [$model];
                if ($language !== '' && $reflection->getNumberOfParameters() >= 2) {
                    $args[] = $language;
                }
                $result = call_user_func_array('lisun_get_product_videos_by_model', $args);
                return self::normalize_videos($result);
            } catch (Throwable $e) {
                error_log('LISUN Manual: product video lookup failed: ' . $e->getMessage());
                return [];
            }
        }

        /**
         * 兼容产品主题没有提供查询函数的情况。
         * 只查 products CPT，并且要求产品 meta 或标题中的型号严格匹配。
         */
        private static function find_product_cpt_videos($model, $language = '') {
            global $wpdb;

            $mapped_ids = [];
            $meta_table = $wpdb->prefix . 'lisun_product_meta';
            // 产品页展示的型号主要来自自定义产品表，并不一定写入 WordPress post meta。
            // 旧查询使用 SQL 精确等号，遇到全角符号、空格或连字符差异就会漏掉。
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table)) === $meta_table) {
                $rows = $wpdb->get_results("SELECT product_id, model FROM {$meta_table} WHERE model <> ''", ARRAY_A);
                $target = self::normalize_model($model);
                foreach ((array) $rows as $row) {
                    if (self::normalize_model($row['model'] ?? '') === $target) {
                        $mapped_ids[] = (int) ($row['product_id'] ?? 0);
                    }
                }
            }

            $ids = get_posts([
                'post_type'              => 'products',
                'post_status'            => 'publish',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'suppress_filters'       => false,
            ]);

            $matched = [];
            foreach ((array) $ids as $post_id) {
                if (in_array((int) $post_id, $mapped_ids, true) || self::product_matches_model($post_id, $model)) {
                    $matched[] = (int) $post_id;
                }
            }

            $matched = array_values(array_unique($matched));

            if (count($matched) !== 1) {
                if (count($matched) > 1) {
                    error_log('LISUN Manual: strict product video lookup found multiple products for model ' . $model);
                }
                return [];
            }

            $post_id = $matched[0];
            $videos = [];
            if (function_exists('get_field')) {
                $videos = self::normalize_videos(get_field('product-video', $post_id), $language);
            }
            if (empty($videos)) {
                foreach (['product-video', 'product_video', 'videos', 'video'] as $meta_key) {
                    $videos = self::normalize_videos(get_post_meta($post_id, $meta_key, true), $language);
                    if (!empty($videos)) break;
                }
            }
            if (!empty($videos)) {
                return $videos;
            }

            // 旧产品页只在 tab-video 中保存链接时，仅解析该区域；不再扫描整篇文章。
            $content = (string) get_post_field('post_content', $post_id);
            if (preg_match('/<li\b[^>]*\bid\s*=\s*(["\'])tab-video\1[^>]*>(.*?)<\/li\s*>/is', $content, $match)) {
                $legacy = self::extract_legacy_operation_videos($match[2]);
                if (!empty($legacy)) return $legacy;
            }

            return [];
        }

        private static function product_matches_model($post_id, $model) {
            $target = self::normalize_model($model);
            if ($target === '') return false;

            foreach (['product-no', 'model', 'product_model', 'product-no-model'] as $key) {
                $values = get_post_meta($post_id, $key, false);
                foreach ((array) $values as $value) {
                    foreach (preg_split('/[,，;；]+/u', (string) $value) as $part) {
                        if (self::normalize_model($part) === $target) return true;
                    }
                }
            }

            // 一些产品页只给 products 文章绑定 post_tag 型号，页面上能显示但没有型号 meta。
            foreach (wp_get_post_terms($post_id, 'post_tag', ['fields' => 'names']) as $tag_name) {
                if (self::normalize_model($tag_name) === $target) return true;
            }

            return self::text_has_exact_model((string) get_the_title($post_id), $model);
        }

        private static function normalize_model($value) {
            return strtoupper((string) preg_replace('/[^a-z0-9]+/i', '', trim((string) $value)));
        }

        private static function text_has_exact_model($text, $model) {
            $parts = preg_split('/[^a-z0-9]+/i', trim((string) $model), -1, PREG_SPLIT_NO_EMPTY);
            if (empty($parts)) return false;
            $pattern = implode('[^a-z0-9]*', array_map(function ($part) {
                return preg_quote($part, '/');
            }, $parts));
            return (bool) preg_match('/(?<![a-z0-9])' . $pattern . '(?![a-z0-9])/i', (string) $text);
        }

        private static function extract_legacy_operation_videos($html) {
            $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            preg_match_all(
                '~(?:(?:https?:)?//)(?:player\.)?bilibili\.com/[^"\'\s<>]+|'
                . '(?:(?:https?:)?//)(?:www\.)?youtube\.com/(?:embed/|watch\?v=)[^"\'\s<>]+|'
                . '(?:(?:https?:)?//)youtu\.be/[^"\'\s<>]+|'
                . '(?:(?:https?:)?//)(?:player\.)?vimeo\.com/[^"\'\s<>]+~i',
                $html,
                $matches
            );

            $result = [];
            foreach (array_unique($matches[0] ?? []) as $url) {
                $url = rtrim(trim($url), "'\".,);>");
                $prefix = substr($html, max(0, (int) strpos($html, $url) - 180), 180);
                if (!self::is_operation_video(['name' => strip_tags($prefix), 'url' => $url])) continue;
                $result[] = ['name' => 'Operation Video', 'url' => $url, 'video_type' => 'operation'];
            }
            return $result;
        }

        private static function normalize_videos($videos, $language = '') {
            if (!is_array($videos)) {
                return [];
            }

            // ACF 可能返回单条关联数组，也可能返回 repeater 的二维数组。
            $has_url = false;
            foreach (['url', 'video_url', 'embed_url', 'link', 'src'] as $key) {
                if (!empty($videos[$key])) {
                    $has_url = true;
                    break;
                }
            }
            if ($has_url) {
                $videos = [$videos];
            }

            $result = [];
            foreach ($videos as $video) {
                if (is_array($video) && !self::has_video_url($video)) {
                    // 兼容一层嵌套的 repeater/group 字段。
                    // LISUN 产品页 ACF repeater 的正式字段名。
                    if (!empty($video['product-video-item'])) {
                        $item = trim((string) $video['product-video-item']);
                        $type = trim((string) ($video['product-video-type'] ?? ''));
                        $name = trim((string) ($video['product-video-text'] ?? ''));
                        if (!self::is_operation_video([
                            'type' => $type,
                            'name' => $name,
                        ])) {
                            continue;
                        }
                        $result[] = [
                            'name' => $name !== '' ? $name : 'Operation Video',
                            'url' => self::acf_video_item_to_url($item, $language),
                            'video_type' => 'operation',
                        ];
                        continue;
                    }

                    $nested = self::normalize_videos($video, $language);
                    foreach ($nested as $item) {
                        $result[] = $item;
                    }
                    continue;
                }
                if (is_string($video)) {
                    $video = ['url' => $video];
                }
                if (!is_array($video)) {
                    continue;
                }

                $url = '';
                foreach (['url', 'video_url', 'embed_url', 'link', 'src'] as $key) {
                    if (!empty($video[$key])) {
                        $url = trim((string) $video[$key]);
                        break;
                    }
                }
                if ($url === '') {
                    continue;
                }

                $name = '';
                foreach (['name', 'title', 'label', 'video_name'] as $key) {
                    if (!empty($video[$key])) {
                        $name = trim((string) $video[$key]);
                        break;
                    }
                }
                if (!self::is_operation_video($video + ['name' => $name, 'url' => $url])) {
                    continue;
                }
                $result[] = [
                    'name' => $name !== '' ? $name : 'Video',
                    'url'  => $url,
                    'video_type' => 'operation',
                ];
            }

            return $result;
        }

        private static function acf_video_item_to_url($item, $language = '') {
            if (preg_match('#^(?:https?:)?//#i', $item)) return $item;
            $site_language = $language !== '' ? $language : get_option('lisun_page_lang', 'zh');
            if ($site_language === 'en') {
                return 'https://www.youtube.com/watch?v=' . rawurlencode($item);
            }
            return '//player.bilibili.com/player.html?aid=' . rawurlencode($item) . '&page=1&autoplay=0';
        }

        private static function has_video_url($video) {
            foreach (['url', 'video_url', 'embed_url', 'link', 'src'] as $key) {
                if (is_array($video) && !empty($video[$key])) return true;
            }
            return false;
        }

        private static function is_operation_video($video) {
            $text = strtolower(implode(' ', [
                (string) ($video['type'] ?? ''),
                (string) ($video['video_type'] ?? ''),
                (string) ($video['category'] ?? ''),
                (string) ($video['purpose'] ?? ''),
                (string) ($video['name'] ?? ''),
                (string) ($video['title'] ?? ''),
                (string) ($video['label'] ?? ''),
                (string) ($video['video_name'] ?? ''),
                (string) ($video['product-video-type'] ?? ''),
                (string) ($video['product-video-text'] ?? ''),
            ]));
            if (preg_match('/介绍|原理|概述|售后|问答|introduction|overview|after[- ]?sales|faq|what\s+does/i', $text)) {
                return false;
            }
            return (bool) preg_match('/操作|使用|安装|说明|教程|operation|operating|instruction|user\s*manual|installation|setup|how\s*to|use/i', $text);
        }
    }
}
