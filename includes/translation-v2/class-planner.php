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
        foreach ($prepared_by_key as $key => $prepared) {
            $old = $old_units[$key] ?? null;
            $can_reuse = !$force && !isset($force_unit_keys[(string) $key]) && $old
                // A degraded unit has already lost one or more stable term
                // anchors. It must be sent through DeepL again; reusing its
                // natural-language snapshot can never repair those anchors.
                && (string) ($old['status'] ?? '') !== 'degraded'
                && self::source_unit_matches($old, $prepared['unit'])
                && (string) ($old['occurrence_signature'] ?? '') === (string) $prepared['occurrence_signature']
                && (string) ($old['target_template'] ?? '') !== '';
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
            $unit['target_template'] = $old['target_template'];
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
