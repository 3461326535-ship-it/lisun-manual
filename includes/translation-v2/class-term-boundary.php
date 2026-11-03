<?php
/** Detect and safely classify glossary text split across a V2 term slot. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Term_Boundary {

    const COMPLETE = 'complete';
    const SPLIT = 'term_split';
    const UNKNOWN = 'term_unknown';
    const DUPLICATE = 'legacy_duplicate';
    const TERM_ANOMALY = 'term_anomaly';

    public static function is_locked_status($status) {
        // A term-count anomaly is only a review warning. It must not block
        // normal glossary application or make a valid natural translation
        // look incomplete.
        return in_array((string) $status, ['degraded', self::SPLIT, self::UNKNOWN], true);
    }

    public static function requires_boundary_retranslation($status) {
        return in_array((string) $status, [self::SPLIT, self::UNKNOWN], true);
    }

    /**
     * Analyze all glossary occurrences in one translated unit.
     *
     * The decision is made from the complete visible unit body.  Historical
     * snapshots may contain incorrect <t> boundaries, so the marker position
     * is not treated as proof that a term was translated correctly.  We
     * materialize the stored occurrence text, count each current glossary
     * target in the visible body, and require that count to match the source
     * occurrence count. A mismatch is preserved on this unit for manual
     * review; it does not silently trigger repeated DeepL retries.
     */
    public static function analyze_unit($unit, $glossary) {
        $template = (string) ($unit['target_template'] ?? '');
        $occurrences = (array) ($unit['occurrences'] ?? []);
        $by_id = [];
        foreach ($occurrences as $occurrence) {
            $id = (string) ($occurrence['occurrence_id'] ?? '');
            if ($id !== '') $by_id[$id] = $occurrence;
        }

        $issues = [];
        $term_checks = [];
        $visible = self::visible_with_occurrence_text($template, $by_id);
        $expected_by_term = [];
        $target_by_term = [];

        foreach ($by_id as $id => $occurrence) {
            $term = self::find_term($occurrence, $glossary);
            if (!$term) continue;
            $target = self::normalize(Lisun_Manual_V2_Term_Matcher::apply_case(
                (string) ($term['en'] ?? ''),
                (string) ($occurrence['case_context'] ?? 'body')
            ));

            if ($target === '') continue;
            $term_id = (string) ($term['term_id'] ?? ($occurrence['term_id'] ?? ''));
            $key = $term_id !== '' ? $term_id : $target;
            $expected_by_term[$key] = (int) ($expected_by_term[$key] ?? 0) + 1;
            $target_by_term[$key] = $target;
        }

        // Do not validate the contents or location of an individual <t> tag.
        // The only authoritative check is whether the complete visible body
        // contains the expected number of local glossary targets.
        foreach ($expected_by_term as $key => $expected) {
            $target = (string) ($target_by_term[$key] ?? '');
            $actual = self::count_target_occurrences($visible, $target);
            $term_checks[] = [
                'term_id' => $key,
                'target_text' => $target,
                'expected_count' => $expected,
                'actual_count' => $actual,
                'ok' => $actual === $expected,
            ];
            if ($actual === $expected) continue;

            $issues[] = [
                'status' => self::TERM_ANOMALY,
                'reason' => 'visible_term_count_mismatch',
                'manual_review_required' => true,
                'retranslation_required' => false,
                'term_id' => $key,
                'target_text' => $target,
                'expected_count' => $expected,
                'actual_count' => $actual,
            ];
        }

        $status = self::COMPLETE;
        foreach ($issues as $issue) {
            if (($issue['status'] ?? '') === self::TERM_ANOMALY) {
                $status = self::TERM_ANOMALY;
                break;
            }
            if (($issue['status'] ?? '') === self::UNKNOWN) {
                $status = self::UNKNOWN;
                break;
            }
            if (($issue['status'] ?? '') === self::SPLIT) $status = self::SPLIT;
            if (($issue['status'] ?? '') === self::DUPLICATE && $status === self::COMPLETE) {
                $status = self::DUPLICATE;
            }
        }

        $retranslation_required = false;
        foreach ($issues as $issue) {
            if (!empty($issue['retranslation_required'])) {
                $retranslation_required = true;
                break;
            }
        }
        return [
            'status' => $status,
            'issues' => $issues,
            'term_checks' => $term_checks,
            'manual_review_required' => $status === self::TERM_ANOMALY,
            'retranslation_required' => $retranslation_required,
        ];
    }

    /** Remove one proven boundary overlap from a legacy template. */
    public static function repair_duplicate($template, $issue) {
        if (($issue['status'] ?? '') !== self::DUPLICATE) return [false, (string) $template];
        $id = (string) ($issue['occurrence_id'] ?? '');
        $marker = self::marker($id);
        $overlap = (string) ($issue['overlap'] ?? '');
        if ($id === '' || $overlap === '' || strpos((string) $template, $marker) === false) {
            return [false, (string) $template];
        }

        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) $template);
        $changed = false;
        foreach ($tokens as &$token) {
            if (($token['type'] ?? '') !== 'text') continue;
            $raw = (string) ($token['raw'] ?? '');
            $marker_position = strpos($raw, $marker);
            if ($marker_position === false) continue;
            if (($issue['side'] ?? '') === 'before') {
                $prefix = substr($raw, 0, $marker_position);
                $escaped = htmlspecialchars($overlap, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                $pattern = '/(?:' . preg_quote($escaped, '/') . '|' . preg_quote($overlap, '/') . ')\\s*$/iu';
                $updated = preg_replace($pattern, '', $prefix, 1, $count);
                if ($updated !== null && $count === 1) {
                    $token['raw'] = $updated . substr($raw, $marker_position);
                    $changed = true;
                }
            } else {
                $suffix = substr($raw, $marker_position + strlen($marker));
                $escaped = htmlspecialchars($overlap, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                $pattern = '/^\\s*(?:' . preg_quote($escaped, '/') . '|' . preg_quote($overlap, '/') . ')/iu';
                $updated = preg_replace($pattern, '', $suffix, 1, $count);
                if ($updated !== null && $count === 1) {
                    $token['raw'] = substr($raw, 0, $marker_position + strlen($marker)) . $updated;
                    $changed = true;
                }
            }
            if ($changed) break;
        }
        unset($token);
        return [$changed, $changed ? Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens) : (string) $template];
    }

    private static function find_term($occurrence, $glossary) {
        $term_id = (string) ($occurrence['term_id'] ?? '');
        $source = self::normalize((string) ($occurrence['source_text'] ?? ''));
        foreach ((array) $glossary as $term) {
            if ($term_id !== '' && (string) ($term['term_id'] ?? '') === $term_id) return $term;
        }
        foreach ((array) $glossary as $term) {
            if ($source !== '' && self::normalize((string) ($term['zh'] ?? '')) === $source) return $term;
        }
        return null;
    }

    /**
     * Materialize the body for validation without assigning meaning to any
     * individual <t> boundary.  The occurrence text is only historical
     * visible text stored with the unit; all matching is performed on the
     * resulting body as one string.
     */
    private static function visible_with_occurrence_text($template, $occurrences) {
        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) $template);
        $visible = '';
        foreach ($tokens as $token) {
            if (($token['type'] ?? '') !== 'text') {
                if (self::is_block_boundary($token)) $visible .= ' ';
                continue;
            }
            $text = html_entity_decode((string) ($token['raw'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach ((array) $occurrences as $occurrence_id => $occurrence) {
                $marker = self::marker($occurrence_id);
                if (strpos($text, $marker) === false) continue;
                $replacement = html_entity_decode(
                    (string) ($occurrence['translated_text'] ?? ''),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
                $text = str_replace($marker, $replacement, $text);
            }
            $visible .= $text;
        }
        return $visible;
    }

    public static function count_target_occurrences($visible, $target) {
        $target = self::normalize($target);
        if ($target === '') return 0;
        $body = self::normalize($visible);
        if ($body === '') return 0;
        $pattern = '/(?<![a-z0-9])' . preg_quote($target, '/') . '(?![a-z0-9])/iu';
        $count = preg_match_all($pattern, $body, $matches);
        return $count === false ? 0 : (int) $count;
    }

    private static function marker($id) {
        return '{{LISUN_TERM:' . (string) $id . '}}';
    }

    private static function normalize($text) {
        $value = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\\s+/u', ' ', trim($value));
        return strtolower($normalized === null ? trim($value) : $normalized);
    }

    private static function is_block_boundary($token) {
        $tag = strtolower((string) ($token['tag'] ?? ''));
        if ($tag === '') return false;
        return in_array($tag, [
            'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div',
            'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li',
            'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody',
            'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
        ], true);
    }
}
