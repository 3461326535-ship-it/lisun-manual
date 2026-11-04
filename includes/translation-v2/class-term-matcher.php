<?php
/** Stable Chinese glossary matching for Translation V2. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Term_Matcher {

    private $trie = [];

    public function __construct($terms) {
        foreach ((array) $terms as $term) {
            $zh = trim((string) ($term['zh'] ?? ''));
            $en = trim((string) ($term['en'] ?? ''));
            $term_id = (string) ($term['term_id'] ?? '');
            if ($zh === '' || $en === '' || $term_id === '') continue;
            $chars = self::characters($zh);
            if (empty($chars)) continue;
            $node =& $this->trie;
            foreach ($chars as $char) {
                if (!isset($node['children'][$char])) $node['children'][$char] = [];
                $node =& $node['children'][$char];
            }
            $node['term'] = [
                'term_id' => $term_id,
                'zh' => $zh,
                'en' => $en,
                'note' => (string) ($term['note'] ?? ''),
            ];
            unset($node);
        }
    }

    /**
     * Split visible text into ordinary text and term occurrence parts.
     * Longest match wins and matches never overlap.
     */
    public function match($text, $unit_key, &$sequence, $case_context = 'body', $leading_text = '') {
        $chars = self::characters((string) $text);
        if (empty($chars) || empty($this->trie)) {
            return [['type' => 'text', 'text' => (string) $text]];
        }

        $parts = [];
        $buffer = '';
        $count = count($chars);
        for ($i = 0; $i < $count;) {
            $node = $this->trie;
            $best = null;
            $best_end = $i;
            for ($j = $i; $j < $count; $j++) {
                $char = $chars[$j];
                if (!isset($node['children'][$char])) break;
                $node = $node['children'][$char];
                if (isset($node['term'])) {
                    $best = $node['term'];
                    $best_end = $j + 1;
                }
            }

            if ($best === null) {
                $buffer .= $chars[$i];
                $i++;
                continue;
            }

            if ($buffer !== '') {
                $parts[] = ['type' => 'text', 'text' => $buffer];
                $buffer = '';
            }
            $matched = implode('', array_slice($chars, $i, $best_end - $i));
            $occurrence_context = self::context_for_offset(
                (string) $leading_text . (string) $text,
                mb_strlen((string) $leading_text) + $i,
                $case_context
            );
            $occurrence_id = 'occ_' . substr(hash('sha256',
                $unit_key . '|' . $best['term_id'] . '|' . $sequence . '|' . $i
            ), 0, 32);
            $parts[] = [
                'type' => 'term',
                'occurrence_id' => $occurrence_id,
                'term_id' => $best['term_id'],
                'source_text' => $matched,
                'source_offset' => $i,
                'source_order' => $sequence,
                'case_context' => $occurrence_context,
                'translated_text' => self::apply_case($best['en'], $occurrence_context),
            ];
            $sequence++;
            $i = $best_end;
        }
        if ($buffer !== '') $parts[] = ['type' => 'text', 'text' => $buffer];
        return $parts;
    }

    public static function occurrence_signature($occurrences) {
        $signature = [];
        foreach ((array) $occurrences as $occurrence) {
            $signature[] = implode('|', [
                (string) ($occurrence['term_id'] ?? ''),
                (string) ($occurrence['source_order'] ?? ''),
                (string) ($occurrence['source_offset'] ?? ''),
                (string) ($occurrence['source_text'] ?? ''),
            ]);
        }
        return hash('sha256', implode("\n", $signature));
    }

    public static function apply_case($text, $context) {
        $text = trim((string) $text);
        if ($text === '') return $text;

        if ($context === 'heading' || $context === 'title') {
            return preg_replace_callback('/\b([A-Za-z])([A-Za-z]*)\b/', function ($match) {
                $word = $match[0];
                if (self::preserve_word_case($word)) return $word;
                return strtoupper($match[1]) . strtolower($match[2]);
            }, $text);
        }

        // Glossary values are often entered with an initial capital merely
        // because they are shown as standalone labels. In prose, normalize
        // ordinary English words to sentence case. Deliberate acronyms and
        // internal mixed-case product/brand names remain byte-identical.
        $normalized = preg_replace_callback('/\b[A-Za-z][A-Za-z\x{2019}\'-]*\b/u', function ($match) {
            $word = $match[0];
            if (self::preserve_word_case($word)) return $word;
            return strtolower($word);
        }, $text);

        if ($context !== 'sentence_start') return $normalized;
        return preg_replace_callback('/\b[A-Za-z][A-Za-z\x{2019}\'-]*\b/u', static function ($match) {
            $word = $match[0];
            if (self::preserve_word_case($word)) return $word;
            return strtoupper(substr($word, 0, 1)) . substr($word, 1);
        }, $normalized, 1);
    }

    /** Resolve one occurrence as heading, sentence start, or ordinary body text. */
    public static function context_for_offset($text, $offset, $base_context = 'body') {
        if ($base_context === 'heading' || $base_context === 'title') return $base_context;

        $offset = max(0, (int) $offset);
        $before = mb_substr((string) $text, 0, $offset);
        if ($before === '' || trim($before) === '') return 'sentence_start';

        // A sentence terminator or hard line break followed only by wrapping
        // punctuation/whitespace starts a new sentence.
        if (preg_match('/(?:[。！？.!?\r\n])[\s\x{00A0}"\'“”‘’（()\[\]【】]*$/u', $before)) {
            return 'sentence_start';
        }

        // Also handle list prefixes such as "1、", "(2)" and "•" at the
        // beginning of a semantic unit without treating normal prose as such.
        if (preg_match('/^[\s\x{00A0}\d一二三四五六七八九十①-⑳（()\[\]【】、.．•·*-]*$/u', $before)) {
            return 'sentence_start';
        }
        return 'body';
    }

    private static function preserve_word_case($word) {
        $letters = preg_replace('/[^A-Za-z]/', '', (string) $word);
        if ($letters === '') return true;
        if (strlen($letters) > 1 && strtoupper($letters) === $letters) return true;

        // Hyphenated Title Case is ordinary sentence text, not a camel-case
        // product name: Over-Temperature must normalize to Over-temperature.
        if (strpos((string) $word, '-') !== false) return false;

        // Preserve camelCase/PascalCase only when an uppercase letter occurs
        // after the first letter. A simple initial capital is normalized.
        return preg_match('/^[A-Za-z][A-Za-z\x{2019}\'-]*[A-Z][A-Za-z\x{2019}\'-]*$/u', (string) $word) === 1;
    }

    private static function characters($text) {
        $chars = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? $chars : [];
    }
}
