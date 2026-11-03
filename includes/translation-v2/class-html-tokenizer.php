<?php
/** Byte-preserving, non-DOM HTML tokenizer used by Translation V2. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Html_Tokenizer {

    private static $void_tags = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    public static function tokenize($html) {
        $html = (string) $html;
        $length = strlen($html);
        $tokens = [];
        $offset = 0;

        while ($offset < $length) {
            if ($html[$offset] !== '<') {
                $next = strpos($html, '<', $offset);
                if ($next === false) $next = $length;
                $tokens[] = self::token('text', substr($html, $offset, $next - $offset), '', false, $offset);
                $offset = $next;
                continue;
            }

            if (substr($html, $offset, 4) === '<!--') {
                $end = strpos($html, '-->', $offset + 4);
                $end = $end === false ? $length : $end + 3;
                $tokens[] = self::token('comment', substr($html, $offset, $end - $offset), '', false, $offset);
                $offset = $end;
                continue;
            }

            $end = self::find_tag_end($html, $offset + 1);
            if ($end === false) {
                // A literal/incomplete '<' is text; never invent a closing tag.
                $tokens[] = self::token('text', substr($html, $offset), '', false, $offset);
                break;
            }

            $raw = substr($html, $offset, $end - $offset + 1);
            $parsed = self::parse_tag($raw, $offset);
            $tokens[] = $parsed ?: self::token('text', $raw, '', false, $offset);
            $offset = $end + 1;
        }

        return $tokens;
    }

    public static function untokenize($tokens) {
        $html = '';
        foreach ((array) $tokens as $token) {
            $html .= (string) ($token['raw'] ?? '');
        }
        return $html;
    }

    private static function token($type, $raw, $tag, $void, $offset) {
        return [
            'type' => $type,
            'raw' => $raw,
            'tag' => $tag,
            'void' => (bool) $void,
            'offset' => (int) $offset,
        ];
    }

    private static function find_tag_end($html, $offset) {
        $quote = '';
        $length = strlen($html);
        for ($i = $offset; $i < $length; $i++) {
            $char = $html[$i];
            if ($quote !== '') {
                if ($char === $quote) $quote = '';
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '>') return $i;
        }
        return false;
    }

    private static function parse_tag($raw, $offset) {
        if (preg_match('/^<\s*\/\s*([a-z][a-z0-9:-]*)[^>]*>$/i', $raw, $match)) {
            return self::token('end', $raw, strtolower($match[1]), false, $offset);
        }
        if (preg_match('/^<\s*([a-z][a-z0-9:-]*)\b[^>]*>$/i', $raw, $match)) {
            $tag = strtolower($match[1]);
            $void = in_array($tag, self::$void_tags, true) || preg_match('/\/\s*>$/', $raw);
            return self::token('start', $raw, $tag, (bool) $void, $offset);
        }
        if (preg_match('/^<\s*[!?]/', $raw)) {
            return self::token('declaration', $raw, '', false, $offset);
        }
        return null;
    }
}
