<?php
/** Expand V2 unit and term slots into clean public HTML/text. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Materializer {

    const MODE_SNAPSHOT = 'snapshot';
    const MODE_CURRENT_GLOSSARY = 'current_glossary';

    public static function materialize_document($skeleton, $units, $occurrences, $glossary, $mode = self::MODE_CURRENT_GLOSSARY) {
        if (!in_array($mode, [self::MODE_SNAPSHOT, self::MODE_CURRENT_GLOSSARY], true)) {
            return new WP_Error('v2_materialize_mode', 'V2 译文物化模式无效');
        }
        $by_term = [];
        if ($mode === self::MODE_CURRENT_GLOSSARY) {
            foreach ((array) $glossary as $term) {
                if (!empty($term['term_id'])) $by_term[$term['term_id']] = $term;
            }
        }
        $occ_by_unit = [];
        foreach ((array) $occurrences as $occurrence) {
            $occ_by_unit[$occurrence['unit_key'] ?? ''][] = $occurrence;
        }

        $html = (string) $skeleton;
        foreach ((array) $units as $unit) {
            $unit_key = (string) ($unit['unit_key'] ?? '');
            // A degraded unit contains a valid DeepL snapshot but not a
            // one-to-one term anchor map. Never apply the current glossary to
            // any part of that unit: replacing one surviving slot could remove
            // text that DeepL merged into it.
            $unit_glossary = $by_term;
            if ($mode === self::MODE_CURRENT_GLOSSARY
                && Lisun_Manual_V2_Term_Boundary::is_locked_status((string) ($unit['status'] ?? ''))) {
                $unit_glossary = [];
            }
            $rendered = self::materialize_unit(
                (string) ($unit['target_template'] ?? ''),
                $occ_by_unit[$unit_key] ?? [],
                $unit_glossary,
                (string) ($unit['field'] ?? 'content'),
                $mode
            );
            if (is_wp_error($rendered)) return $rendered;
            $marker = '<!--lisun-v2-unit:' . $unit_key . '-->';
            if (substr_count($html, $marker) !== 1) {
                return new WP_Error('v2_skeleton_mismatch', 'V2 文档骨架中的翻译单元标记数量不正确');
            }
            $html = str_replace($marker, $rendered, $html);
        }
        if (preg_match('/<!--lisun-v2-unit:[a-f0-9]{40}-->/', $html)
            || strpos($html, '{{LISUN_TERM:') !== false) {
            return new WP_Error('v2_unresolved_marker', 'V2 组装后仍有未解析的内部标记');
        }
        return $html;
    }

    public static function materialize_unit($template, $occurrences, $glossary_by_id, $field = 'content', $mode = self::MODE_CURRENT_GLOSSARY) {
        if (!in_array($mode, [self::MODE_SNAPSHOT, self::MODE_CURRENT_GLOSSARY], true)) {
            return new WP_Error('v2_materialize_mode', 'V2 译文物化模式无效');
        }
        $template = self::normalize_historical_transport_whitespace((string) $template);
        // Older V2 transports used a literal pipe as a separator between
        // adjacent term slots. Remove only that legacy artifact, and only
        // from a template that still contains V2 term slots. Do not touch
        // ordinary source text or opaque/code blocks.
        $template = self::normalize_legacy_term_separator_pipes($template);
        $by_occurrence = [];
        foreach ((array) $occurrences as $occurrence) {
            if (!empty($occurrence['occurrence_id'])) {
                $by_occurrence[$occurrence['occurrence_id']] = $occurrence;
            }
        }
        $target_case_contexts = self::target_case_contexts(
            $template,
            array_values($by_occurrence),
            $field
        );
        $rendered_terms = [];
        $result = preg_replace_callback('/\{\{LISUN_TERM:(occ_[a-f0-9]{32})\}\}/', function ($match) use ($by_occurrence, $glossary_by_id, $target_case_contexts, &$rendered_terms, $mode) {
            $id = $match[1];
            if (!isset($by_occurrence[$id])) return $match[0];
            $occurrence = $by_occurrence[$id];
            $term_id = (string) ($occurrence['term_id'] ?? '');
            // Deleted/missing terms freeze their last known English; they never
            // remove surrounding text or an entire historical occurrence.
            $text = $mode === self::MODE_CURRENT_GLOSSARY && isset($glossary_by_id[$term_id])
                ? (string) ($glossary_by_id[$term_id]['en'] ?? '')
                : (string) ($occurrence['translated_text'] ?? '');
            $context = (string) ($target_case_contexts[$id]
                ?? $occurrence['case_context'] ?? 'body');
            $text = Lisun_Manual_V2_Term_Matcher::apply_case($text, $context);
            $slot = count($rendered_terms);
            $rendered_terms[$slot] = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            return "\x01LISUN_TERM:{$slot}\x02";
        }, (string) $template);
        if ($result === null || strpos($result, '{{LISUN_TERM:') !== false) {
            return new WP_Error('v2_term_materialize', 'V2 术语槽位无法完整生成公开译文');
        }
        $result = self::expand_rendered_term_slots($result, $rendered_terms);
        // DeepL can return punctuation at the end of one XML text node and
        // the next word at the start of another node. Normalize the public
        // output after slots are expanded so the rule also covers HTML/tag
        // boundaries and historical snapshots.
        $result = self::normalize_public_punctuation_spacing($result);
        if ($field === 'title') {
            $result = html_entity_decode(wp_strip_all_tags($result), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $result = self::title_case($result);
        }
        return $result;
    }

    /**
     * Determine term casing from its position in the translated template.
     * DeepL may move a Chinese term from the start of the source sentence to
     * the middle of the English sentence, so source offsets are not reliable
     * for English capitalization.
     */
    public static function target_case_contexts($template, $occurrences, $field = 'content') {
        $by_occurrence = [];
        foreach ((array) $occurrences as $occurrence) {
            $id = (string) ($occurrence['occurrence_id'] ?? '');
            if ($id !== '') $by_occurrence[$id] = $occurrence;
        }
        if (empty($by_occurrence)) return [];

        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) $template);
        $is_heading = (string) $field === 'title';
        foreach ($tokens as $token) {
            if (($token['type'] ?? '') === 'start'
                && preg_match('/^h[1-6]$/i', (string) ($token['tag'] ?? ''))) {
                $is_heading = true;
                break;
            }
        }

        $contexts = [];
        $visible = '';
        $pattern = '/(\{\{LISUN_TERM:occ_[a-f0-9]{32}\}\})/';
        foreach ($tokens as $token) {
            if (($token['type'] ?? '') !== 'text') {
                $tag = strtolower((string) ($token['tag'] ?? ''));
                if ($visible !== '' && in_array($tag, [
                    'br', 'p', 'div', 'li', 'td', 'th', 'blockquote',
                    'figcaption', 'caption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                ], true)) {
                    $visible .= "\n";
                }
                continue;
            }

            $raw = html_entity_decode(
                (string) ($token['raw'] ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $parts = preg_split($pattern, $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts)) {
                $visible .= $raw;
                continue;
            }
            for ($index = 0, $count = count($parts); $index < $count; $index++) {
                $part = (string) $parts[$index];
                if (preg_match('/^\{\{LISUN_TERM:(occ_[a-f0-9]{32})\}\}$/', $part, $match)) {
                    $id = $match[1];
                    if (isset($by_occurrence[$id])) {
                        $stored = (string) ($by_occurrence[$id]['case_context'] ?? 'body');
                        $contexts[$id] = $is_heading || in_array($stored, ['heading', 'title'], true)
                            ? 'heading'
                            : Lisun_Manual_V2_Term_Matcher::context_for_offset(
                                $visible,
                                mb_strlen($visible),
                                'body'
                            );
                    }
                    // A neutral visible word prevents a following adjacent
                    // slot from being mistaken for the start of a sentence.
                    $visible .= 'term';
                    continue;
                }
                $visible .= $part;
            }
        }
        return $contexts;
    }

    /**
     * Remove the literal separator leaked by the pre-v9 term transport.
     * Restrict this to ordinary HTML text nodes and leave code-like blocks
     * byte-identical.
     */
    private static function normalize_legacy_term_separator_pipes($template) {
        $template = (string) $template;
        if (strpos($template, '{{LISUN_TERM:') === false || strpos($template, '|') === false) {
            return $template;
        }

        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize($template);
        $opaque = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];
        $opaque_depth = 0;
        foreach ($tokens as &$token) {
            $tag = (string) ($token['tag'] ?? '');
            if ($token['type'] === 'start' && !$token['void'] && in_array($tag, $opaque, true)) {
                $opaque_depth++;
            } elseif ($token['type'] === 'end' && in_array($tag, $opaque, true)) {
                $opaque_depth = max(0, $opaque_depth - 1);
            } elseif ($token['type'] === 'text' && $opaque_depth === 0) {
                $clean = preg_replace('/\s*\|\s*/u', ' ', (string) $token['raw']);
                $token['raw'] = $clean === null ? (string) $token['raw'] : $clean;
            }
        }
        unset($token);
        return Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens);
    }

    /**
     * Repair punctuation that is glued to the following word. Work on text
     * nodes only, while also checking adjacent nodes across inline HTML tags.
     * Periods are handled conservatively so abbreviations such as U.S. and
     * e.g. are not changed.
     */
    private static function normalize_public_punctuation_spacing($html) {
        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) $html);
        $opaque = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];
        $opaque_depth = 0;
        $previous_text = null;
        foreach ($tokens as &$token) {
            $tag = (string) ($token['tag'] ?? '');
            if ($token['type'] === 'start' && !$token['void'] && in_array($tag, $opaque, true)) {
                $opaque_depth++;
                $previous_text = null;
                continue;
            }
            if ($token['type'] === 'end' && in_array($tag, $opaque, true)) {
                $opaque_depth = max(0, $opaque_depth - 1);
                $previous_text = null;
                continue;
            }
            if ($token['type'] !== 'text' || $opaque_depth > 0) continue;

            $raw = (string) $token['raw'];
            $raw = self::normalize_punctuation_in_text($raw);
            if ($previous_text !== null && self::needs_public_boundary_space($previous_text, $raw)) {
                $raw = ' ' . ltrim($raw);
            }
            $token['raw'] = $raw;
            $previous_text = $raw;
        }
        unset($token);
        return Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens);
    }

    private static function normalize_punctuation_in_text($text) {
        $text = (string) $text;
        $normalized = preg_replace('/([,!?;:])(?=[A-Za-z])/u', '$1 ', $text);
        $normalized = preg_replace('/([A-Za-z]{2,})\.(?=[A-Z])/u', '$1. ', $normalized === null ? $text : $normalized);
        $normalized = preg_replace('/\)(?=[A-Z])/u', ') ', $normalized === null ? $text : $normalized);
        return $normalized === null ? $text : $normalized;
    }

    private static function needs_public_boundary_space($previous, $current) {
        $previous = rtrim((string) $previous);
        $current = ltrim((string) $current);
        if ($previous === '' || $current === '' || !preg_match('/^[A-Z]/u', $current)) return false;
        if (preg_match('/[,!?;:)]$/u', $previous)) return true;
        return preg_match('/[A-Za-z]{2,}\.$/u', $previous) === 1;
    }

    /**
     * Older committed V2 snapshots may already contain XML indentation that
     * arrived between inline nodes. Keep source HTML breaks and opaque blocks
     * untouched, but remove transport newlines from ordinary text nodes so
     * WordPress cannot render them as extra <br /> elements.
     */
    private static function normalize_historical_transport_whitespace($template) {
        $template = (string) $template;
        if (strpos($template, '{{LISUN_TERM:') === false) return $template;

        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize($template);
        $opaque = ['script', 'style', 'pre', 'code', 'noscript', 'textarea', 'select'];
        $opaque_depth = 0;
        foreach ($tokens as &$token) {
            $tag = (string) ($token['tag'] ?? '');
            if ($token['type'] === 'start' && !$token['void'] && in_array($tag, $opaque, true)) {
                $opaque_depth++;
            } elseif ($token['type'] === 'end' && in_array($tag, $opaque, true)) {
                $opaque_depth = max(0, $opaque_depth - 1);
            } elseif ($token['type'] === 'text' && $opaque_depth === 0) {
                $token['raw'] = preg_replace('/[\r\n\t]+/u', ' ', (string) $token['raw']);
            }
        }
        unset($token);
        return Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens);
    }

    /**
     * Expand term slots without inventing whitespace around them. DeepL's
     * response is authoritative for ordinary word boundaries; adding a space
     * whenever both sides contain ASCII letters can split a word when DeepL
     * has placed a tag through the middle of that word (for example,
     * "capac" + "itive").
     */
    private static function expand_rendered_term_slots($text, $rendered_terms) {
        $parts = preg_split('/(\x01LISUN_TERM:\d+\x02)/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return (string) $text;

        $result = '';
        $last_was_term = false;
        $part_count = count($parts);
        // Use indexed access instead of foreach values here.  The next text
        // part may be trimmed when it duplicates a rendered term; that
        // change must be visible when the next iteration consumes it.
        for ($part_index = 0; $part_index < $part_count; $part_index++) {
            $part = (string) ($parts[$part_index] ?? '');
            if (preg_match('/^\x01LISUN_TERM:(\d+)\x02$/', $part, $match)) {
                $value = (string) ($rendered_terms[(int) $match[1]] ?? '');

                // DeepL can leave its ordinary translation immediately next
                // to the protected term slot.  Replacing the slot then turns
                // e.g. "diameter{{slot}}" into "diameter diameter".  The
                // ordinary word is not a second term occurrence; coalesce
                // only an exact boundary duplicate caused by this slot.
                $result = self::remove_duplicate_term_suffix($result, $value);
                if ($part_index + 1 < $part_count) {
                    $parts[$part_index + 1] = self::remove_duplicate_term_prefix(
                        $parts[$part_index + 1],
                        $value
                    );
                }
                $result .= $value;
                $last_was_term = true;
                continue;
            }

            $result .= $part;
            $last_was_term = false;
        }
        return $result;
    }

    private static function remove_duplicate_term_suffix($text, $value) {
        $text = (string) $text;
        $value = (string) $value;
        if ($value === '' || strlen($text) < strlen($value)) return $text;
        if (substr_compare($text, $value, -strlen($value), strlen($value), true) !== 0) {
            return $text;
        }

        $before = strlen($text) - strlen($value);
        if ($before > 0) {
            $previous = substr($text, $before - 1, 1);
            // Do not turn a normal word ending in the term into a match.
            if (preg_match('/[A-Za-z0-9]/', $previous)) return $text;
        }
        return substr($text, 0, $before);
    }

    private static function remove_duplicate_term_prefix($text, $value) {
        $text = (string) $text;
        $value = (string) $value;
        if ($value === '') return $text;
        $leading = '';
        if (preg_match('/^\s*/', $text, $match)) $leading = (string) $match[0];
        $offset = strlen($leading);
        if (strlen($text) < $offset + strlen($value)
            || substr_compare($text, $value, $offset, strlen($value), true) !== 0) {
            return $text;
        }

        $after = $offset + strlen($value);
        if ($after < strlen($text)) {
            $next = substr($text, $after, 1);
            // The duplicate must end at a word boundary.
            if (preg_match('/[A-Za-z0-9]/', $next)) return $text;
        }
        return substr($text, $after);
    }

    private static function word_start($text) {
        return preg_match('/^[A-Za-z0-9]/', (string) $text) === 1;
    }

    private static function word_end($text) {
        return preg_match('/[A-Za-z0-9]$/', (string) $text) === 1;
    }

    private static function title_case($text) {
        return preg_replace_callback('/\b([A-Za-z])([A-Za-z]*)\b/', function ($match) {
            if (strlen($match[0]) > 1 && strtoupper($match[0]) === $match[0]) return $match[0];
            return strtoupper($match[1]) . strtolower($match[2]);
        }, (string) $text);
    }
}
