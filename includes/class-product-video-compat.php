<?php
/**
 * 产品视频兼容层。
 *
 * 优先使用产品主题现有的 lisun_get_product_videos_by_model()；
 * 当旧产品页只把视频写在 tab-video 内容块、而没有写入 product-video
 * ACF 字段时，再从匹配到的产品页正文中提取 Bilibili/YouTube 等视频。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Lisun_Manual_Product_Video_Compat')) {
    class Lisun_Manual_Product_Video_Compat {
        public static function get_by_model($model, $language = '') {
            $model = trim((string) $model);
            if ($model === '') {
                return [];
            }

            $videos = self::call_existing_lookup($model, $language);
            if (!empty($videos)) {
                return $videos;
            }

            return self::find_legacy_product_videos($model);
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

        private static function find_legacy_product_videos($model) {
            $post_types = get_post_types(['public' => true], 'names');
            $post_types = array_values(array_diff($post_types, ['manual', 'attachment']));
            if (empty($post_types)) {
                $post_types = ['post', 'page'];
            }

            $ids = get_posts([
                'post_type'              => $post_types,
                'post_status'            => 'publish',
                'posts_per_page'         => 50,
                's'                      => $model,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'suppress_filters'       => false,
            ]);

            $candidates = [];
            foreach ((array) $ids as $post_id) {
                $content = (string) get_post_field('post_content', $post_id);
                if ($content === '') {
                    continue;
                }

                $urls = self::extract_video_urls($content);
                if (empty($urls)) {
                    continue;
                }

                $title = (string) get_the_title($post_id);
                $score = 0;
                if (stripos($title, $model) !== false) {
                    $score += 20;
                }
                if (stripos($content, $model) !== false) {
                    $score += 5;
                }
                if (preg_match('/id\s*=\s*["\']tab-video["\']/i', $content)) {
                    $score += 100;
                }

                $candidates[] = [
                    'score' => $score,
                    'title' => $title,
                    'urls'  => $urls,
                ];
            }

            usort($candidates, static function ($a, $b) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });

            if (empty($candidates)) {
                return [];
            }

            $result = [];
            $default_name = $candidates[0]['title'] ?: ($model . ' - Video');
            foreach ($candidates[0]['urls'] as $url) {
                $result[] = [
                    'name' => $default_name,
                    'url'  => $url,
                ];
            }

            error_log('LISUN Manual: legacy product video fallback matched model '
                . $model . ' with ' . count($result) . ' video(s)');
            return $result;
        }

        private static function extract_video_urls($html) {
            $scope = (string) $html;
            if (preg_match('/<li\b[^>]*\bid\s*=\s*(["\'])tab-video\1[^>]*>(.*?)<\/li\s*>/is', $scope, $match)) {
                $scope = $match[2];
            }

            $scope = html_entity_decode($scope, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            preg_match_all(
                '~(?:(?:https?:)?//)(?:player\.)?bilibili\.com/[^"\'\s<>]+|'
                . '(?:(?:https?:)?//)(?:www\.)?youtube\.com/(?:embed/|watch\?v=)[^"\'\s<>]+|'
                . '(?:(?:https?:)?//)youtu\.be/[^"\'\s<>]+|'
                . '(?:(?:https?:)?//)(?:player\.)?vimeo\.com/[^"\'\s<>]+~i',
                $scope,
                $matches
            );

            $urls = [];
            foreach ($matches[0] ?? [] as $raw_url) {
                $url = trim(html_entity_decode($raw_url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $url = rtrim($url, "'\".,);>");
                if (strpos($url, '//') === 0) {
                    $url = 'https:' . $url;
                }
                if ($url !== '') {
                    $urls[$url] = true;
                }
            }

            return array_keys($urls);
        }

        private static function normalize_videos($videos) {
            if (!is_array($videos)) {
                return [];
            }

            $result = [];
            foreach ($videos as $video) {
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
                $result[] = [
                    'name' => $name !== '' ? $name : 'Video',
                    'url'  => $url,
                ];
            }

            return $result;
        }
    }
}
