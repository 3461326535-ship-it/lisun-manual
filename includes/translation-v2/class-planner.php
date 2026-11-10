<?php
/** Decide which semantic units can be reused and which must be translated. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Planner {

    public static function plan($units, $active, $current_terms, $force = false, $force_unit_keys = []) {
        $force_unit_keys = array_fill_keys(array_map('strval', (array) $force_unit_keys), true);
        $matching_terms = self::indexed_terms($current_terms);
        $prepared_by_key = [];
        foreach ((array) $units as $unit) {
            // Titles remain plain text in the public WordPress field, but their
            // internal V2 template may still contain term slots.  This lets a
            // glossary apply update a title by term_id without emitting span
            // HTML or rescanning the translated English title.
            $unit_terms = in_array(($unit['field'] ?? ''), ['content', 'title'], true)
                ? $matching_terms
                : [];
            $prepared_by_key[$unit['unit_key']] = Lisun_Manual_V2_Unit_Transport::prepare($unit, $unit_terms);
        }

        $old_units = [];
        $old_occurrences = [];
        if ($active) {
            foreach ((array) ($active['units'] ?? []) as $old_unit) {
                $old_units[$old_unit['unit_key']] = $old_unit;
            }
            foreach ((array) ($active['occurrences'] ?? []) as $occurrence) {
                $old_occurrences[$occurrence['unit_key']][] = $occurrence;
            }
        }

        $pending = [];
        $reused = [];
        $recovery_units = [];
        foreach ($prepared_by_key as $key => $prepared) {
            $old = $old_units[$key] ?? null;
            // 2026-10-29：媒体资源属于源文结构；可见文字未变时也必须检测图片变化。
            $media_changed = $old
                && self::media_signature($old['source_html'] ?? '')
                    !== self::media_signature($prepared['unit']['source_html'] ?? '');
            $media_count_changed = $media_changed
                && self::media_count($old['source_html'] ?? '')
                    !== self::media_count($prepared['unit']['source_html'] ?? '');
            $can_reuse = !$force && !isset($force_unit_keys[(string) $key]) && $old
                // A degraded unit has already lost one or more stable term
                // anchors. It must be sent through DeepL again; reusing its
                // natural-language snapshot can never repair those anchors.
                && (string) ($old['status'] ?? '') !== 'degraded'
                && self::source_unit_matches($old, $prepared['unit'])
                && (string) ($old['occurrence_signature'] ?? '') === (string) $prepared['occurrence_signature']
                && (string) ($old['target_template'] ?? '') !== ''
                // 2026-10-29：文本单元内图片数量发生增删时结构已经变化，需要重新处理；
                // 纯图片单元没有需要翻译的文本，可以直接用当前源文媒体更新。
                && (!$media_count_changed || trim((string) ($prepared['unit']['source_text'] ?? '')) === '');
            if ($can_reuse) {
                // A split/unknown unit may be reused only while the current
                // glossary still describes the same visible boundary. If the
                // target no longer matches, force this unit back through DeepL
                // instead of attempting a partial local replacement. The
                // same probe also catches a translated <t> slot that contains
                // only an article (for example "a") even if the old unit was
                // previously marked translated.
                $boundary_probe = $old;
                $boundary_probe['occurrences'] = $old_occurrences[$key] ?? [];
                $boundary = Lisun_Manual_V2_Term_Boundary::analyze_unit(
                    $boundary_probe,
                    $matching_terms
                );
                // [修改时间：2026-11-05 11:10:48 +08:00] 目标术语在旧译文中完全缺失时，强制重译当前单元；
                // [修改时间：2026-11-05 11:10:48 +08:00] 部分命中（如 11/12）仍仅提示核对，正常单元继续复用。
                foreach ((array) ($boundary['term_checks'] ?? []) as $check) {
                    if ((int) ($check['expected_count'] ?? 0) > 0
                        && (int) ($check['actual_count'] ?? 0) === 0) {
                        $can_reuse = false;
                        $recovery_units[(string) $key] = true;
                        break;
                    }
                }
                if (!empty($boundary['retranslation_required'])) {
                    $can_reuse = false;
                } elseif (Lisun_Manual_V2_Term_Boundary::requires_boundary_retranslation($old['status'] ?? '')
                    && ($boundary['status'] ?? '') !== Lisun_Manual_V2_Term_Boundary::SPLIT) {
                    $can_reuse = false;
                }
            }
            if (!$can_reuse) {
                $pending[] = $prepared;
                continue;
            }

            $unit = $prepared['unit'];
            // 2026-10-29：复用英文文字时同步当前源文的图片标记；纯图片单元即使
            // 图片数量发生变化也在本地处理，不额外请求翻译接口。
            // 2026-11-03：最终版合并时保留上述逻辑，并与 Runner 的完整源文重建
            // 检测配合，避免历史 target_template 把旧图片 URL 再次写回远程站。
            $unit['target_template'] = self::sync_image_tags(
                (string) $old['target_template'],
                (string) ($unit['source_html'] ?? ''),
                trim((string) ($unit['source_text'] ?? '')) === ''
            );
            $unit['occurrence_signature'] = $prepared['occurrence_signature'];
            $unit['occurrences'] = self::merge_occurrence_fallbacks(
                $prepared['occurrences'],
                $old_occurrences[$key] ?? []
            );
            $unit['lock_state'] = $old['lock_state'] ?? 'auto';
            // Preserve the safety state of a previously committed unit. A
            // degraded snapshot must remain excluded from glossary-only apply
            // until that unit is translated again with complete anchors.
            $unit['status'] = $old['status'] ?? 'translated';
            $reused[$key] = $unit;
        }

        return [
            'prepared_by_key' => $prepared_by_key,
            'pending' => $pending,
            'reused' => $reused,
            'reuse_count' => count($reused),
            'recovery_unit_keys' => array_keys($recovery_units),
        ];
    }

    /**
     * Unit hashes include raw HTML. Reuse is safe when the semantic block is
     * unchanged but WordPress rewrote equivalent markup around it.
     */
    private static function source_unit_matches($old, $current) {
        if ((string) ($old['source_hash'] ?? '') === (string) ($current['source_hash'] ?? '')) {
            return true;
        }
        if ((string) ($old['unit_type'] ?? '') !== (string) ($current['unit_type'] ?? '')) {
            return false;
        }
        $normalize = static function ($value) {
            $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim((string) preg_replace('/\s+/u', ' ', $value));
        };
        return $normalize($old['source_text'] ?? '') === $normalize($current['source_text'] ?? '');
    }

    private static function sync_image_tags($target, $source, $image_only = false) {
        preg_match_all('/<img\b[^>]*>/is', (string) $source, $source_matches);
        preg_match_all('/<img\b[^>]*>/is', (string) $target, $target_matches);
        $source_tags = array_values($source_matches[0] ?? []);
        $target_tags = array_values($target_matches[0] ?? []);
        if (count($source_tags) !== count($target_tags)) {
            return $image_only ? (string) $source : (string) $target;
        }
        if (!$source_tags) return (string) $target;
        $index = 0;
        $synced = preg_replace_callback('/<img\b[^>]*>/is', function ($match) use (&$index, $source_tags) {
            return $source_tags[$index++] ?? $match[0];
        }, (string) $target);
        return is_string($synced) ? $synced : (string) $target;
    }

    private static function media_signature($html) {
        preg_match_all('/<img\b[^>]*>/is', (string) $html, $matches);
        return implode("\n", array_map('trim', (array) ($matches[0] ?? [])));
    }

    private static function media_count($html) {
        preg_match_all('/<img\b[^>]*>/is', (string) $html, $matches);
        return count((array) ($matches[0] ?? []));
    }

    private static function indexed_terms($current) {
        // 已删除的术语不再以 ghost 形式保留历史译文：删除即视为普通文本，
        // 下次翻译重新走 DeepL。否则旧的全库误应用 / 锚点错译会被永久固定，
        // 文章级范围筛选也随之失效。
        $terms = [];
        foreach ((array) $current as $term) {
            $terms[$term['term_id']] = $term;
        }
        return array_values($terms);
    }

    private static function merge_occurrence_fallbacks($current, $old) {
        $old_by_id = [];
        foreach ((array) $old as $occurrence) {
            $old_by_id[$occurrence['occurrence_id']] = $occurrence;
        }
        foreach ($current as &$occurrence) {
            $id = $occurrence['occurrence_id'];
            // A reused occurrence produced from the current glossary must retain
            // the current glossary wording.  Otherwise an English-only glossary
            // edit would render correctly once, but the newly committed revision
            // would still remember the older wording and report the same change
            // again on every later "apply glossary" run.  Historical deleted
            // terms arrive here with their previous wording already filled in;
            // only use the old value as a last-resort fallback for an empty slot.
            if ((string) ($occurrence['translated_text'] ?? '') === ''
                && isset($old_by_id[$id]['translated_text'])) {
                $occurrence['translated_text'] = $old_by_id[$id]['translated_text'];
            }
        }
        unset($occurrence);
        return $current;
    }
}
