<?php
/** Build a stable document skeleton and semantic translation units. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Unit_Extractor {

    private static $unit_tags = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'li', 'td', 'th', 'figcaption', 'caption', 'blockquote',
    ];

    public static function extract($html, $field = 'content', $on_progress = null) {
        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) $html);
        if (Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens) !== (string) $html) {
            return new WP_Error('v2_token_roundtrip', 'V2 HTML tokenizer 无法逐字节还原中文源文');
        }

        $skeleton = '';
        $units = [];
        $active = null;
        $stack = [];
        // WordPress content occasionally contains a table inside a paragraph
        // or figcaption wrapper.  If we keep collecting that outer semantic
        // unit, the whole table and all following blocks become one DeepL
        // request and the provider can move text across cell boundaries.
        // When a table begins inside one of these wrappers, close the wrapper
        // around the prefix and consume its original closing tag later.
        $detached_closures = [];
        $tag_counts = [];
        $duplicates = [];

        $token_total = count($tokens);
        $report_every = max(1, (int) ceil($token_total / 20));
        foreach ($tokens as $token_index => $token) {
            if (is_callable($on_progress)
                && ($token_index === 0 || $token_index === $token_total - 1 || $token_index % $report_every === 0)) {
                call_user_func($on_progress, $token_index + 1, $token_total);
            }
            if ($active !== null) {
                if ($token['type'] === 'start'
                    && $token['tag'] === 'table'
                    && self::can_split_before_table($active)) {
                    $outer_tag = (string) ($active['outer_tag'] ?? '');
                    $unit = self::finalize_unit(
                        $active['tokens'],
                        $field,
                        $active['path'],
                        count($units),
                        $duplicates,
                        '</' . $outer_tag . '>'
                    );
                    if (trim(html_entity_decode(
                        wp_strip_all_tags((string) ($unit['inner_html'] ?? '')),
                        ENT_QUOTES,
                        'UTF-8'
                    )) !== '') {
                        $units[] = $unit;
                        $skeleton .= '<!--lisun-v2-unit:' . $unit['unit_key'] . '-->';
                    } else {
                        // Preserve an empty wrapper without creating an empty
                        // translation request.
                        $skeleton .= (string) ($unit['wrapper_open'] ?? '')
                            . (string) ($unit['wrapper_close'] ?? '');
                    }
                    $detached_closures[] = $outer_tag;
                    $active = null;
                    // Re-process the table start token below as ordinary
                    // document structure instead of adding it to the prefix.
                } else {
                    $active['tokens'][] = $token;
                }

                if ($active === null) {
                    // Continue into the ordinary token handling below.
                } else {
                if ($token['type'] === 'start' && !$token['void']) {
                    $active['open_tags'][] = $token['tag'];
                } elseif ($token['type'] === 'end') {
                    $top = end($active['open_tags']);
                    if ($top === $token['tag']) {
                        array_pop($active['open_tags']);
                    } elseif ($token['tag'] === $active['outer_tag']) {
                        // HTML error recovery closes still-open inline tags at
                        // the semantic block boundary. Preserve their raw bytes
                        // but do not let them swallow following paragraphs.
                        $active['open_tags'] = [];
                    }
                }
                if (empty($active['open_tags'])) {
                    $unit = self::finalize_unit($active['tokens'], $field, $active['path'], count($units), $duplicates);
                    $units[] = $unit;
                    $skeleton .= '<!--lisun-v2-unit:' . $unit['unit_key'] . '-->';
                    $active = null;
                }
                continue;
                }
            }

            if ($token['type'] === 'start') {
                $parent = empty($stack) ? 'root' : implode('/', array_column($stack, 'path'));
                $count_key = $parent . '>' . $token['tag'];
                $tag_counts[$count_key] = ($tag_counts[$count_key] ?? 0) + 1;
                $path_part = $token['tag'] . '[' . $tag_counts[$count_key] . ']';
                $path = $parent . '/' . $path_part;

                if (in_array($token['tag'], self::$unit_tags, true) && !$token['void']) {
                    $active = [
                        'tokens' => [$token],
                        'open_tags' => [$token['tag']],
                        'outer_tag' => $token['tag'],
                        'path' => $path,
                    ];
                    continue;
                }

                $skeleton .= $token['raw'];
                if (!$token['void']) $stack[] = ['tag' => $token['tag'], 'path' => $path_part];
                continue;
            }

            if ($token['type'] === 'end') {
                $detached_index = array_search($token['tag'], $detached_closures, true);
                if ($detached_index !== false) {
                    unset($detached_closures[$detached_index]);
                    $detached_closures = array_values($detached_closures);
                    // The split prefix already owns a synthetic close tag.
                    // Do not emit the malformed source close a second time.
                    continue;
                }
                $skeleton .= $token['raw'];
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] === $token['tag']) {
                        array_splice($stack, $i, 1);
                        break;
                    }
                }
                continue;
            }

            if ($token['type'] === 'text' && trim(html_entity_decode($token['raw'], ENT_QUOTES, 'UTF-8')) !== '') {
                $unit = self::finalize_unit([$token], $field, 'root/text[' . (count($units) + 1) . ']', count($units), $duplicates);
                $units[] = $unit;
                $skeleton .= '<!--lisun-v2-unit:' . $unit['unit_key'] . '-->';
                continue;
            }

            $skeleton .= $token['raw'];
        }

        // An unclosed semantic element is retained as one unit. Its malformed
        // outer HTML stays local and will never be sent to DeepL as raw HTML.
        if ($active !== null) {
            $unit = self::finalize_unit($active['tokens'], $field, $active['path'], count($units), $duplicates);
            $units[] = $unit;
            $skeleton .= '<!--lisun-v2-unit:' . $unit['unit_key'] . '-->';
        }

        $boundary_check = self::validate_table_unit_boundaries($units);
        if (is_wp_error($boundary_check)) return $boundary_check;

        return [
            'skeleton' => $skeleton,
            'units' => $units,
            'source_hash' => hash('sha256', (string) $html),
        ];
    }

    public static function extract_plain($text, $field, $unit_type = 'text') {
        $source_text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $hash = hash('sha256', $source_text);
        return [
            'unit_key' => sha1($field . '|' . $unit_type . '|' . $hash),
            'field' => $field,
            'unit_type' => $unit_type,
            'unit_order' => 0,
            'source_path' => $field,
            'source_text' => $source_text,
            'source_html' => (string) $text,
            'source_hash' => $hash,
            'wrapper_open' => '',
            'wrapper_close' => '',
            'inner_html' => (string) $text,
        ];
    }

    private static function can_split_before_table($active) {
        return in_array((string) ($active['outer_tag'] ?? ''), [
            'p', 'figcaption', 'blockquote', 'li',
        ], true);
    }

    /**
     * A table must never be hidden inside a paragraph/figcaption translation
     * unit.  A nested table inside a table cell remains part of that cell and
     * is intentionally allowed.
     */
    private static function validate_table_unit_boundaries($units) {
        foreach ((array) $units as $unit) {
            $unit_type = strtolower((string) ($unit['unit_type'] ?? ''));
            if (in_array($unit_type, ['td', 'th'], true)) continue;
            if (preg_match('/<table\b/i', (string) ($unit['source_html'] ?? ''))) {
                return new WP_Error(
                    'v2_table_unit_boundary',
                    'V2 表格未被拆成独立单元，已停止翻译以避免跨单元格重排',
                    ['source_path' => (string) ($unit['source_path'] ?? '')]
                );
            }
        }
        return true;
    }

    private static function finalize_unit($tokens, $field, $path, $order, &$duplicates, $wrapper_close_override = null) {
        $source_html = Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens);
        $first = reset($tokens);
        $last = end($tokens);
        $unit_type = ($first && $first['type'] === 'start') ? $first['tag'] : 'text';
        $wrapper_open = '';
        $wrapper_close = '';
        $inner_tokens = $tokens;

        if ($unit_type !== 'text') {
            $wrapper_open = (string) $first['raw'];
            array_shift($inner_tokens);
            if ($wrapper_close_override !== null) {
                $wrapper_close = (string) $wrapper_close_override;
            } elseif ($last && $last['type'] === 'end' && $last['tag'] === $unit_type) {
                $wrapper_close = (string) $last['raw'];
                array_pop($inner_tokens);
            }
        }

        $inner_html = Lisun_Manual_V2_Html_Tokenizer::untokenize($inner_tokens);
        $source_text = trim(html_entity_decode(wp_strip_all_tags($inner_html), ENT_QUOTES, 'UTF-8'));
        $source_hash = hash('sha256', $source_html);
        $identity = $field . '|' . $unit_type . '|' . preg_replace('/\s+/u', ' ', $source_text);
        $duplicate_index = $duplicates[$identity] ?? 0;
        $duplicates[$identity] = $duplicate_index + 1;

        return [
            'unit_key' => sha1($identity . '|' . $duplicate_index),
            'field' => $field,
            'unit_type' => $unit_type,
            'unit_order' => (int) $order,
            'source_path' => $path,
            'source_text' => $source_text,
            'source_html' => $source_html,
            'source_hash' => $source_hash,
            'wrapper_open' => $wrapper_open,
            'wrapper_close' => $wrapper_close,
            'inner_html' => $inner_html,
        ];
    }
}
