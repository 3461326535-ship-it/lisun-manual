<?php
/** Synthetic XML transport. Raw WordPress HTML is never sent to DeepL. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Unit_Transport {

    private static $opaque_tags = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];

    public static function prepare($unit, $terms) {
        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) ($unit['inner_html'] ?? ''));
        $pairs = self::strict_pairs($tokens);
        $matcher = new Lisun_Manual_V2_Term_Matcher($terms);
        $sequence = 0;
        $format_sequence = 0;
        $format_map = [];
        $occurrences = [];
        $xml = '<lisun-unit key="' . self::xml((string) $unit['unit_key']) . '">';
        $last_was_term = false;
        // Circled/parenthesized list numbers are visual content, not numbers
        // for DeepL to normalize. Keep them as opaque placeholders so ⑩ can
        // never come back as (10), 10., or another numbering style.
        $sequence_marker_pattern = '/([\x{2460}-\x{2473}\x{2474}-\x{2487}\x{24EA}])/u';
        $single_sequence_marker_pattern = '/^[\x{2460}-\x{2473}\x{2474}-\x{2487}\x{24EA}]$/u';
        $context = in_array((string) ($unit['unit_type'] ?? ''), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
            || ($unit['field'] ?? '') === 'title' ? 'heading' : 'body';
        $visible_prefix = '';

        $skip_until = -1;
        foreach ($tokens as $index => $token) {
            if ($index <= $skip_until) continue;

            if ($token['type'] === 'start' && in_array($token['tag'], self::$opaque_tags, true) && isset($pairs[$index])) {
                $end_index = $pairs[$index];
                $raw = '';
                for ($j = $index; $j <= $end_index; $j++) $raw .= $tokens[$j]['raw'];
                $id = 'fmt_' . substr(hash('sha256', $unit['unit_key'] . '|opaque|' . $format_sequence++), 0, 24);
                $format_map[$id] = ['kind' => 'raw', 'raw' => $raw];
                $xml .= '<ph id="' . $id . '" />';
                $skip_until = $end_index;
                continue;
            }

            if ($token['type'] === 'text') {
                $visible = html_entity_decode($token['raw'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parts = $matcher->match(
                    $visible,
                    $unit['unit_key'],
                    $sequence,
                    $context,
                    $visible_prefix
                );
                foreach ($parts as $part) {
                    if ($part['type'] === 'text') {
                        $text_pieces = preg_split(
                            $sequence_marker_pattern,
                            (string) $part['text'],
                            -1,
                            PREG_SPLIT_DELIM_CAPTURE
                        );
                        foreach ($text_pieces as $text_piece) {
                            if ($text_piece === '') continue;
                            if (preg_match($single_sequence_marker_pattern, $text_piece)) {
                                $id = 'fmt_' . substr(hash('sha256',
                                    $unit['unit_key'] . '|sequence|' . $format_sequence++
                                ), 0, 24);
                                $format_map[$id] = ['kind' => 'raw', 'raw' => $text_piece];
                                $xml .= '<ph id="' . $id . '" />';
                            } else {
                                $xml .= self::xml($text_piece);
                            }
                            $last_was_term = false;
                        }
                    } else {
                        // DeepL can merge neighboring XML term tags. Use an
                        // opaque ph placeholder between them so the separator
                        // cannot leak into the public translation if DeepL
                        // moves the surrounding text. It is removed after
                        // restore by its internal marker, not by looking for a
                        // particular neighboring term shape.
                        if ($last_was_term) {
                            $separator_id = 'fmt_' . substr(hash('sha256',
                                $unit['unit_key'] . '|term-separator|' . $format_sequence++
                            ), 0, 24);
                            $format_map[$separator_id] = [
                                'kind' => 'term_separator',
                                'raw' => '',
                            ];
                            $xml .= '<ph id="' . $separator_id . '" />';
                        }
                        $occurrences[] = $part;
                        // The term tag is the occurrence identity. Its
                        // contents stay in Chinese so DeepL and its glossary
                        // perform the translation; restore_node() recovers
                        // the actual returned text by this stable id.
                        $xml .= '<t id="' . self::xml($part['occurrence_id']) . '">'
                            . self::xml($part['source_text']) . '</t>';
                        $last_was_term = true;
                    }
                }
                $visible_prefix .= $visible;
                continue;
            }

            if ($token['type'] === 'start' && !$token['void'] && isset($pairs[$index])) {
                $id = 'fmt_' . substr(hash('sha256', $unit['unit_key'] . '|pair|' . $format_sequence++), 0, 24);
                $end_index = $pairs[$index];
                $format_map[$id] = [
                    'kind' => 'pair',
                    'open' => $token['raw'],
                    'close' => $tokens[$end_index]['raw'],
                ];
                $xml .= '<f id="' . $id . '">';
                continue;
            }

            // Images are immutable document structure. Give each one an
            // explicit, stable transport label so the provider can return it
            // at the exact text position where it was sent. The raw source
            // node is restored after translation; its filename is irrelevant.
            if ($token['type'] === 'start' && $token['void'] && $token['tag'] === 'img') {
                $id = 'img_' . sprintf('%03d', $format_sequence++);
                $format_map[$id] = ['kind' => 'image', 'raw' => $token['raw']];
                // Google may discard an unknown XML <ph>. A plain opaque
                // marker survives both Google and DeepL and is replaced with
                // the original node after the translated text is restored.
                $xml .= ' LISUN_IMAGE_' . substr($id, 4) . ' ';
                continue;
            }

            if ($token['type'] === 'end' && isset($pairs[$index])) {
                $xml .= '</f>';
                continue;
            }

            // Void, comment, declaration and malformed/orphan tags are inert
            // placeholders. They remain byte-identical in the target skeleton.
            $id = 'fmt_' . substr(hash('sha256', $unit['unit_key'] . '|raw|' . $format_sequence++), 0, 24);
            $format_map[$id] = ['kind' => 'raw', 'raw' => $token['raw']];
            $xml .= '<ph id="' . $id . '" />';
            $last_was_term = false;
        }
        $xml .= '</lisun-unit>';

        return [
            'unit' => $unit,
            'xml' => $xml,
            'format_map' => $format_map,
            'occurrences' => $occurrences,
            'occurrence_signature' => Lisun_Manual_V2_Term_Matcher::occurrence_signature($occurrences),
            'term_transport' => 'term-tag-v9',
        ];
    }

    /**
     * Restore a DeepL unit. Anchor-only failures are downgraded to a plain
     * translated snapshot so one problematic glossary occurrence or lost
     * formatting marker cannot abort the whole document. Unknown XML markers
     * and invalid XML remain fatal because there is no safe plain-text value.
     */
    public static function restore($prepared, $translated_xml) {
        $strict = self::restore_strict($prepared, $translated_xml);
        if (!is_wp_error($strict)) return $strict;
        if (($prepared['term_transport'] ?? '') === 'plain-text-fallback-v9') return $strict;
        if (!self::is_anchor_error($strict)) return $strict;

        $degraded = self::restore_degraded($prepared, $translated_xml);
        if (is_wp_error($degraded)) return $strict;

        $degraded['fallback_reason'] = $strict->get_error_code() . ': ' . $strict->get_error_message();
        return $degraded;
    }

    private static function restore_strict($prepared, $translated_xml) {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('v2_dom_missing', '服务器缺少 PHP DOM/XML 扩展，无法校验 V2 翻译单元');
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML((string) $translated_xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || !$dom->documentElement || $dom->documentElement->tagName !== 'lisun-unit') {
            $detail = !empty($errors) ? trim($errors[0]->message) : 'missing lisun-unit root';
            return new WP_Error('v2_invalid_xml', 'DeepL 返回的翻译单元不是合法 XML：' . $detail);
        }

        $state = ['formats' => [], 'terms' => [], 'term_translations' => [], 'legacy_terms' => []];
        $inner = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $restored = self::restore_node($child, $prepared, $state);
            if (is_wp_error($restored)) return $restored;
            $inner .= $restored;
        }

        $transport = (string) ($prepared['term_transport'] ?? '');
        if ($transport === 'term-tag-v9') {
            $inner = self::remove_term_separator_placeholders($inner);
            // <t> is only a transport hint. DeepL may move an article outside
            // the tag, so map the complete visible translation to local
            // glossary targets instead of validating each tag independently.
            $surface = self::expand_term_slots_to_surface($inner, $state['term_translations']);
            $anchor_state = [
                'formats' => $state['formats'],
                'terms' => [],
                'term_translations' => [],
                'legacy_terms' => [],
            ];
            $anchored = self::anchor_translated_terms($surface, $prepared, $anchor_state);
            if (is_wp_error($anchored)) {
                return new WP_Error(
                    'v2_term_anchor_unresolved',
                    'DeepL 完整译文已返回，但无法根据本地术语建立稳定锚点',
                    [
                        'anchor_code' => $anchored->get_error_code(),
                        'anchor_message' => $anchored->get_error_message(),
                    ]
                );
            }
            $inner = $anchored;
            $state['terms'] = $anchor_state['terms'];
            $state['term_translations'] = $anchor_state['term_translations'];
            $finalized = $inner;
        } elseif ($transport === 'term-tag-v8') {
            $inner = self::remove_literal_term_separators($inner);
            $finalized = $inner;
        } elseif ($transport === 'term-tag-v7' || $transport === 'term-tag-v6' || $transport === 'term-tag-v5') {
            // The <t id="occ_..."> node itself is the authoritative
            // occurrence anchor. Do not infer its identity from English text.
            $finalized = $inner;
        } elseif ($transport === 'plain-text-fallback-v9') {
            $inner = self::remove_term_separator_placeholders($inner);
            $finalized = self::anchor_translated_terms($inner, $prepared, $state);
        } elseif ($transport === 'plain-text-v4') {
            // Read already-running v4 jobs, but never create new v4 jobs.
            $finalized = self::anchor_translated_terms($inner, $prepared, $state);
        } else {
            $finalized = self::finalize_term_boundaries($inner, $prepared, $state);
        }
        if (is_wp_error($finalized)) return $finalized;
        $inner = $finalized;

        foreach ((array) ($prepared['format_map'] ?? []) as $format_id => $format) {
            if (($format['kind'] ?? '') !== 'image') continue;
            $marker = 'LISUN_IMAGE_' . substr((string) $format_id, 4);
            if (strpos($inner, $marker) === false) continue;
            $inner = str_replace($marker, (string) ($format['raw'] ?? ''), $inner);
            $state['formats'][] = (string) $format_id;
        }

        $expected_formats = array_keys((array) $prepared['format_map']);
        $expected_terms = array_column((array) $prepared['occurrences'], 'occurrence_id');
        sort($expected_formats, SORT_STRING);
        sort($expected_terms, SORT_STRING);
        sort($state['formats'], SORT_STRING);
        sort($state['terms'], SORT_STRING);
        if ($expected_formats !== $state['formats']) {
            $missing_formats = array_values(array_diff($expected_formats, $state['formats']));
            $missing_images = array_values(array_filter($missing_formats, static function ($id) use ($prepared) {
                return (($prepared['format_map'][$id]['kind'] ?? '') === 'image');
            }));
            if (!empty($missing_images)) {
                return new WP_Error(
                    'v2_image_placeholder_missing',
                    '翻译器丢失图片占位符，未保存当前翻译单元',
                    ['missing_images' => $missing_images]
                );
            }
            return new WP_Error(
                'v2_format_mismatch',
                'DeepL 返回的格式占位符不完整，当前翻译单元未保存',
                [
                    'missing_formats' => array_values(array_diff($expected_formats, $state['formats'])),
                    'extra_formats' => array_values(array_diff($state['formats'], $expected_formats)),
                ]
            );
        }
        $missing_term_ids = array_values(array_diff($expected_terms, $state['terms']));
        // DeepL may merge short/contextual terms into a neighbouring translated
        // phrase and omit the corresponding <t> node.  That is acceptable for
        // the first, natural-language snapshot, but the unit is no longer safe
        // for glossary-only replacement.  Keep the distinction explicit rather
        // than rejecting an otherwise usable translation or inventing anchors.

        $occurrences = (array) ($prepared['occurrences'] ?? []);
        foreach ($occurrences as &$occurrence) {
            $id = (string) ($occurrence['occurrence_id'] ?? '');
            $occurrence['translated_text'] = (string) ($state['term_translations'][$id] ?? '');
        }
        unset($occurrence);

        $unit = $prepared['unit'];
        $template = (string) ($unit['wrapper_open'] ?? '') . $inner . (string) ($unit['wrapper_close'] ?? '');
        return [
            'target_template' => $template,
            'occurrences' => $occurrences,
            'occurrence_signature' => $prepared['occurrence_signature'],
            'anchor_status' => empty($missing_term_ids) ? 'complete' : 'degraded',
            'missing_occurrence_ids' => $missing_term_ids,
        ];
    }

    /**
     * Render the translated XML without term slots after an anchor-only
     * failure. Formatting placeholders are still validated and restored;
     * only term identity is intentionally discarded for this unit.
     */
    private static function restore_degraded($prepared, $translated_xml) {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('v2_dom_missing', '服务器缺少 PHP DOM/XML 扩展，无法校验 V2 翻译单元');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML((string) $translated_xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || !$dom->documentElement || $dom->documentElement->tagName !== 'lisun-unit') {
            $detail = !empty($errors) ? trim($errors[0]->message) : 'missing lisun-unit root';
            return new WP_Error('v2_invalid_xml', 'DeepL 返回的翻译单元不是合法 XML：' . $detail);
        }

        $state = ['formats' => []];
        $inner = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $restored = self::restore_degraded_node($child, $prepared, $state);
            if (is_wp_error($restored)) return $restored;
            $inner .= $restored;
        }

        foreach ((array) ($prepared['format_map'] ?? []) as $format_id => $format) {
            if (($format['kind'] ?? '') !== 'image') continue;
            $marker = 'LISUN_IMAGE_' . substr((string) $format_id, 4);
            if (strpos($inner, $marker) === false) continue;
            $inner = str_replace($marker, (string) ($format['raw'] ?? ''), $inner);
            $state['formats'][] = (string) $format_id;
        }

        // A missing term separator is recoverable: it is an internal transport
        // aid, not visible HTML. Real formatting placeholders may also be
        // missing in a DeepL response. In that case we deliberately keep the
        // translated text as ordinary/degraded HTML and record the missing
        // markers instead of aborting the entire document.
        $expected_formats = [];
        foreach ((array) ($prepared['format_map'] ?? []) as $format_id => $format) {
            if (($format['kind'] ?? '') !== 'term_separator') $expected_formats[] = (string) $format_id;
        }
        $actual_formats = [];
        foreach ((array) ($state['formats'] ?? []) as $format_id) {
            if (($prepared['format_map'][$format_id]['kind'] ?? '') !== 'term_separator') {
                $actual_formats[] = (string) $format_id;
            }
        }
        sort($expected_formats, SORT_STRING);
        sort($actual_formats, SORT_STRING);
        $missing_formats = array_values(array_diff($expected_formats, $actual_formats));
        $missing_images = array_values(array_filter($missing_formats, static function ($id) use ($prepared) {
            return (($prepared['format_map'][$id]['kind'] ?? '') === 'image');
        }));
        if (!empty($missing_images)) {
            return new WP_Error(
                'v2_image_placeholder_missing',
                '翻译器丢失图片占位符，未保存当前翻译单元',
                ['missing_images' => $missing_images]
            );
        }
        $extra_formats = array_values(array_diff($actual_formats, $expected_formats));

        $inner = self::remove_term_separator_placeholders($inner);
        $occurrences = (array) ($prepared['occurrences'] ?? []);
        $missing_term_ids = [];
        foreach ($occurrences as &$occurrence) {
            $missing_term_ids[] = (string) ($occurrence['occurrence_id'] ?? '');
            // There is deliberately no stable glossary replacement value after
            // an anchor failure. The public text from DeepL is authoritative.
            $occurrence['translated_text'] = '';
        }
        unset($occurrence);

        $unit = $prepared['unit'];
        return [
            'target_template' => (string) ($unit['wrapper_open'] ?? '') . $inner . (string) ($unit['wrapper_close'] ?? ''),
            'occurrences' => $occurrences,
            'occurrence_signature' => $prepared['occurrence_signature'],
            'anchor_status' => 'degraded',
            'missing_occurrence_ids' => array_values(array_filter($missing_term_ids)),
            'missing_format_ids' => $missing_formats,
            'extra_format_ids' => $extra_formats,
            'format_status' => empty($missing_formats) && empty($extra_formats) ? 'complete' : 'degraded',
        ];
    }

    private static function restore_degraded_node($node, $prepared, &$state) {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $text = preg_replace('/[ \t\r\n]+/u', ' ', (string) $node->nodeValue);
            $text = self::normalize_punctuation_spacing($text === null ? (string) $node->nodeValue : $text);
            return htmlspecialchars($text === null ? (string) $node->nodeValue : $text,
                ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) return '';

        $tag = $node->tagName;
        $id = (string) $node->getAttribute('id');
        if ($tag === 't') {
            // Keep the plain translated wording for a known occurrence. An
            // invented term id is not a formatting loss and must remain fatal.
            if ($id === '' || !self::has_occurrence($prepared['occurrences'], $id)) {
                return new WP_Error('v2_unknown_term_marker', 'DeepL 返回了未知术语位置标记，无法安全降级保存');
            }
            $text = preg_replace('/[ \t\r\n]+/u', ' ', (string) $node->textContent);
            $text = trim($text === null ? (string) $node->textContent : $text);
            return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if ($tag === 'ph') {
            if ($id === '' || !isset($prepared['format_map'][$id])) {
                return new WP_Error('v2_unknown_format_marker', 'DeepL 返回了未知格式占位符，无法安全降级保存');
            }
            $state['formats'][] = $id;
            if (($prepared['format_map'][$id]['kind'] ?? '') === 'term_separator') {
                return '{{LISUN_TERM_SEPARATOR:' . $id . '}}';
            }
            return (string) ($prepared['format_map'][$id]['raw'] ?? '');
        }
        if ($tag === 'f') {
            if ($id === '' || !isset($prepared['format_map'][$id]) || ($prepared['format_map'][$id]['kind'] ?? '') !== 'pair') {
                return new WP_Error('v2_unknown_format_marker', 'DeepL 返回了未知成对格式标记，无法安全降级保存');
            }
            $state['formats'][] = $id;
            $inner = '';
            foreach ($node->childNodes as $child) {
                $restored = self::restore_degraded_node($child, $prepared, $state);
                if (is_wp_error($restored)) return $restored;
                $inner .= $restored;
            }
            return $prepared['format_map'][$id]['open'] . $inner . $prepared['format_map'][$id]['close'];
        }
        return new WP_Error('v2_unknown_xml_tag', 'DeepL 返回了未知 XML 标签：' . $tag);
    }

    private static function is_anchor_error($error) {
        if (!is_wp_error($error)) return false;
        return in_array((string) $error->get_error_code(), [
            'v2_term_mismatch',
            'v2_term_translation_missing',
            'v2_unknown_term_marker',
            'v2_term_marker_content',
            'v2_term_boundary_format',
            'v2_term_anchor_missing',
            'v2_term_anchor_ambiguous',
            'v2_term_anchor_unresolved',
            // A missing internal term separator or HTML format marker is
            // handled by restore_degraded as ordinary translated text.
            'v2_format_mismatch',
        ], true);
    }

    private static function restore_node($node, $prepared, &$state) {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            // DeepL may pretty-print XML and insert indentation/newlines
            // between inline term/format nodes. Those are transport whitespace,
            // not source HTML line breaks; keeping them lets wpautop render an
            // extra <br /> between nearly every translated fragment.
            $text = preg_replace('/[ \t\r\n]+/u', ' ', (string) $node->nodeValue);
            $text = self::normalize_punctuation_spacing($text === null ? (string) $node->nodeValue : $text);
            return htmlspecialchars($text === null ? (string) $node->nodeValue : $text,
                ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = $node->tagName;
        $id = (string) $node->getAttribute('id');
        if ($tag === 't') {
            // A term tag identifies the source occurrence, but its returned
            // text is only a transport fragment. The visible sentence is
            // remapped after all fragments are collected; DeepL may move an
            // article or part of the glossary phrase outside this tag.
            if ($id !== '' && self::has_occurrence($prepared['occurrences'], $id)) {
                if (in_array($id, $state['terms'], true)) {
                    return new WP_Error('v2_term_mismatch', 'DeepL 重复返回了同一个术语位置标记，当前翻译单元未保存');
                }
                $state['terms'][] = $id;
                $translated = preg_replace('/[ \t\r\n]+/u', ' ', (string) $node->textContent);
                $translated = trim($translated === null ? (string) $node->textContent : $translated);
                if ($translated === '') {
                    return new WP_Error('v2_term_translation_missing', 'DeepL 返回了空的术语译文，当前翻译单元未保存');
                }
                $state['term_translations'][$id] = $translated;
                $state['legacy_terms'][] = $id;
                return '{{LISUN_TERM:' . $id . '}}';
            }
            if ($id === '' || !preg_match('/^(occ_[a-f0-9]{32})__(start|end)$/', $id, $match)
                || !self::has_occurrence($prepared['occurrences'], $match[1])) {
                return new WP_Error('v2_unknown_term_marker', 'DeepL 返回了未知术语位置标记');
            }
            if ($node->hasChildNodes() && trim((string) $node->textContent) !== '') {
                return new WP_Error('v2_term_marker_content', 'DeepL 返回的术语边界标记包含文本，当前翻译单元未保存');
            }
            return '{{LISUN_TERM_' . strtoupper($match[2]) . ':' . $match[1] . '}}';
        }
        if ($tag === 'ph') {
            if ($id === '' || !isset($prepared['format_map'][$id])) {
                return new WP_Error('v2_unknown_format_marker', 'DeepL 返回了未知格式占位符');
            }
            $state['formats'][] = $id;
            if (($prepared['format_map'][$id]['kind'] ?? '') === 'term_separator') {
                return '{{LISUN_TERM_SEPARATOR:' . $id . '}}';
            }
            return (string) ($prepared['format_map'][$id]['raw'] ?? '');
        }
        if ($tag === 'f') {
            if ($id === '' || !isset($prepared['format_map'][$id]) || ($prepared['format_map'][$id]['kind'] ?? '') !== 'pair') {
                return new WP_Error('v2_unknown_format_marker', 'DeepL 返回了未知成对格式标记');
            }
            $state['formats'][] = $id;
            $inner = '';
            foreach ($node->childNodes as $child) {
                $restored = self::restore_node($child, $prepared, $state);
                if (is_wp_error($restored)) return $restored;
                $inner .= $restored;
            }
            return $prepared['format_map'][$id]['open'] . $inner . $prepared['format_map'][$id]['close'];
        }
        return new WP_Error('v2_unknown_xml_tag', 'DeepL 返回了未知 XML 标签：' . $tag);
    }

    private static function has_occurrence($occurrences, $id) {
        foreach ((array) $occurrences as $occurrence) {
            if (($occurrence['occurrence_id'] ?? '') === $id) return true;
        }
        return false;
    }

    /** Expand DeepL term slots into the visible text used for local anchoring. */
    private static function expand_term_slots_to_surface($inner, $term_translations) {
        $expanded = preg_replace_callback(
            '/\{\{LISUN_TERM:(occ_[a-f0-9]{32})\}\}/',
            static function (array $match) use ($term_translations): string {
                $value = (string) ($term_translations[$match[1]] ?? '');
                return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            },
            (string) $inner
        );
        return $expanded === null ? (string) $inner : $expanded;
    }

    /** Convert empty start/end labels into a slot containing DeepL's text. */
    private static function finalize_term_boundaries($inner, $prepared, &$state) {
        foreach ((array) ($prepared['occurrences'] ?? []) as $occurrence) {
            $id = (string) ($occurrence['occurrence_id'] ?? '');
            if ($id === '') continue;
            $start = '{{LISUN_TERM_START:' . $id . '}}';
            $end = '{{LISUN_TERM_END:' . $id . '}}';
            if (substr_count($inner, $start) !== 1 || substr_count($inner, $end) !== 1) {
                // A legacy response has already materialized this occurrence.
                if (in_array($id, $state['legacy_terms'], true)) continue;
                return new WP_Error('v2_term_mismatch', 'DeepL 返回的术语位置边界不完整，当前翻译单元未保存');
            }
            $start_pos = strpos($inner, $start);
            $end_pos = strpos($inner, $end, $start_pos + strlen($start));
            if ($start_pos === false || $end_pos === false || $end_pos < $start_pos) {
                return new WP_Error('v2_term_mismatch', 'DeepL 返回的术语位置边界顺序异常，当前翻译单元未保存');
            }
            $between_start = $start_pos + strlen($start);
            $translated_fragment = substr($inner, $between_start, $end_pos - $between_start);
            if ($translated_fragment === '' || preg_match('/\{\{LISUN_TERM_(?:START|END):/u', $translated_fragment)) {
                return new WP_Error('v2_term_mismatch', 'DeepL 返回的术语边界相互嵌套，当前翻译单元未保存');
            }
            if (strpos($translated_fragment, '<') !== false) {
                return new WP_Error('v2_term_boundary_format', 'DeepL 将格式标签插入术语边界，当前翻译单元未保存');
            }
            $translated = preg_replace('/[ \t\r\n]+/u', ' ', html_entity_decode(
                $translated_fragment,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
            $translated = trim($translated === null ? $translated_fragment : $translated);
            if ($translated === '') {
                return new WP_Error('v2_term_translation_missing', 'DeepL 未返回完整的术语译文');
            }
            $state['terms'][] = $id;
            $state['term_translations'][$id] = $translated;
            $inner = substr($inner, 0, $start_pos)
                . '{{LISUN_TERM:' . $id . '}}'
                . substr($inner, $end_pos + strlen($end));
        }
        if (preg_match('/\{\{LISUN_TERM_(?:START|END):/u', $inner)) {
            return new WP_Error('v2_term_mismatch', 'DeepL 返回了未识别的术语位置边界，当前翻译单元未保存');
        }
        return $inner;
    }

    /**
     * Anchor local glossary targets in the complete visible translation.
     *
     * The <t> nodes are only an XML transport envelope. DeepL is allowed to
     * move articles or part of a phrase across those nodes, so their contents
     * and positions are deliberately ignored here. We search the materialized
     * body for the local target phrase and assign matches in source occurrence
     * order, preserving the exact spelling/case found in the body.
     */
    private static function anchor_translated_terms($inner, $prepared, &$state) {
        $occurrences = (array) ($prepared['occurrences'] ?? []);
        if (empty($occurrences)) return $inner;

        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) $inner);
        $groups = [];
        foreach ($occurrences as $occurrence) {
            $id = (string) ($occurrence['occurrence_id'] ?? '');
            if ($id === '' || in_array($id, $state['terms'], true)) continue;
            $value = Lisun_Manual_V2_Term_Matcher::apply_case(
                (string) ($occurrence['translated_text'] ?? ''),
                (string) ($occurrence['case_context'] ?? 'body')
            );
            if ($value === '') {
                return new WP_Error('v2_term_anchor_missing', 'DeepL 返回的术语译文为空，当前翻译单元未保存');
            }
            $normalized = preg_replace('/\s+/u', ' ', trim($value));
            $key = strtolower($normalized === null ? trim($value) : $normalized);
            $groups[$key]['value'] = $value;
            $groups[$key]['term_ids'][(string) ($occurrence['term_id'] ?? '')] = true;
            $groups[$key]['occurrences'][] = $occurrence;
        }

        // Match longer returned glossary phrases first. Otherwise a short
        // target such as "integrating sphere" would also match inside the
        // longer target "integrating sphere system" and look ambiguous.
        uasort($groups, static function (array $a, array $b): int {
            return strlen((string) $b['value']) <=> strlen((string) $a['value']);
        });
        $reserved = [];
        $replacements = [];
        foreach ($groups as $group) {
            if (count($group['term_ids']) !== 1) {
                return new WP_Error('v2_term_anchor_ambiguous', '多个术语对应同一英文译文，无法安全建立锚点');
            }
            // Search the full visible body for the local glossary target. The
            // actual matched spelling is retained below, so sentence-start
            // capitalization remains whatever DeepL returned.
            $candidates = array_values(array_unique([
                htmlspecialchars($group['value'], ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                $group['value'],
            ]));
            $matches = [];
            foreach ($candidates as $candidate) {
                if ($candidate === '') continue;
                $found = self::find_text_matches($tokens, $candidate);
                $found = array_values(array_filter($found, static function (array $match) use ($reserved): bool {
                    $end = $match['start'] + $match['length'];
                    foreach (($reserved[$match['token']] ?? []) as $range) {
                        if ($match['start'] < $range[1] && $end > $range[0]) return false;
                    }
                    return true;
                }));
                if (count($found) > count($matches)) $matches = $found;
            }
            if (count($matches) !== count($group['occurrences'])) {
                return new WP_Error(
                    count($matches) === 0 ? 'v2_term_anchor_missing' : 'v2_term_anchor_ambiguous',
                    count($matches) === 0
                        ? 'DeepL 返回的术语译文无法定位，当前翻译单元未保存'
                        : 'DeepL 返回的术语译文出现次数不一致，当前翻译单元未保存'
                );
            }
            usort($matches, static function (array $a, array $b): int {
                return [$a['token'], $a['start']] <=> [$b['token'], $b['start']];
            });
            foreach (array_values($group['occurrences']) as $index => $occurrence) {
                $match = $matches[$index];
                $reserved[$match['token']][] = [
                    $match['start'],
                    $match['start'] + $match['length'],
                ];
                $replacements[] = [
                    'token' => $match['token'],
                    'start' => $match['start'],
                    'length' => $match['length'],
                    'occurrence_id' => (string) $occurrence['occurrence_id'],
                    'translated_text' => trim(html_entity_decode(
                        substr($tokens[$match['token']]['raw'], $match['start'], $match['length']),
                        ENT_QUOTES | ENT_HTML5,
                        'UTF-8'
                    )),
                ];
            }
        }

        usort($replacements, static function (array $a, array $b): int {
            if ($a['token'] !== $b['token']) return $a['token'] <=> $b['token'];
            return $a['start'] <=> $b['start'];
        });
        $previous_by_token = [];
        foreach ($replacements as $replacement) {
            $token = $replacement['token'];
            $previous_end = $previous_by_token[$token] ?? -1;
            if ($replacement['start'] < $previous_end) {
                return new WP_Error('v2_term_anchor_ambiguous', 'DeepL 返回的术语译文相互重叠，当前翻译单元未保存');
            }
            $previous_by_token[$token] = $replacement['start'] + $replacement['length'];
        }
        for ($i = count($replacements) - 1; $i >= 0; $i--) {
            $replacement = $replacements[$i];
            $token_index = $replacement['token'];
            $raw = (string) $tokens[$token_index]['raw'];
            $tokens[$token_index]['raw'] = substr($raw, 0, $replacement['start'])
                . '{{LISUN_TERM:' . $replacement['occurrence_id'] . '}}'
                . substr($raw, $replacement['start'] + $replacement['length']);
            $state['terms'][] = $replacement['occurrence_id'];
            $state['term_translations'][$replacement['occurrence_id']] = $replacement['translated_text'];
        }
        return Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens);
    }

    private static function remove_literal_term_separators($inner) {
        do {
            $before = $inner;
            $inner = preg_replace(
                '/(\{\{LISUN_TERM:[^}]+\}\})\s*\|\s*(\{\{LISUN_TERM:[^}]+\}\})/u',
                '$1$2',
                $inner
            );
        } while ($inner !== $before);
        return $inner;
    }

    private static function remove_term_separator_placeholders($inner) {
        // The separator represents a boundary between two Chinese terms.
        // Keep one ordinary English space after removing the transport-only
        // placeholder; this prevents adjacent one-word translations from
        // being glued together.
        $clean = preg_replace('/\{\{LISUN_TERM_SEPARATOR:[^}]+\}\}/u', ' ', (string) $inner);
        return $clean === null ? (string) $inner : $clean;
    }

    private static function normalize_punctuation_spacing($text) {
        $text = (string) $text;
        // XML-tag translation can return a punctuation mark directly followed
        // by the next sentence. Repair only an ASCII-letter boundary so
        // decimals, URLs, and ordinary punctuation remain untouched.
        $normalized = preg_replace('/([,.!?;])(?=[A-Z])/u', '$1 ', $text);
        $normalized = preg_replace('/\)(?=[A-Z])/u', ') ', $normalized === null ? $text : $normalized);
        return $normalized === null ? $text : $normalized;
    }

    private static function find_text_matches($tokens, $needle) {
        $matches = [];
        foreach ((array) $tokens as $index => $token) {
            if (($token['type'] ?? '') !== 'text') continue;
            $raw = (string) ($token['raw'] ?? '');
            $offset = 0;
            while (($position = stripos($raw, (string) $needle, $offset)) !== false) {
                $matches[] = [
                    'token' => (int) $index,
                    'start' => (int) $position,
                    'length' => strlen((string) $needle),
                ];
                $offset = $position + max(1, strlen((string) $needle));
            }
        }
        return $matches;
    }

    /** Pair only strictly nested tags. Crossed or orphan tags become raw ph. */
    private static function strict_pairs($tokens) {
        $stack = [];
        $pairs = [];
        foreach ((array) $tokens as $index => $token) {
            if ($token['type'] === 'start' && !$token['void']) {
                $stack[] = ['index' => $index, 'tag' => $token['tag']];
            } elseif ($token['type'] === 'end') {
                $top = end($stack);
                if ($top && $top['tag'] === $token['tag']) {
                    array_pop($stack);
                    $pairs[$top['index']] = $index;
                    $pairs[$index] = $top['index'];
                }
            }
        }
        // Remove pairs nested inside an unmatched start. Otherwise emitting a
        // raw outer '<b>' as ph around a live </f> could create invalid XML.
        return $pairs;
    }

    private static function xml($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
