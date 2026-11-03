<?php
/** Translation V2 orchestration: extract, reuse, translate, commit, render. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Runner {

    /**
     * Classify term slots before either committing a fresh translation or
     * applying a glossary. This keeps a translatable <t> transport while
     * preventing a partial DeepL slot from being replaced by a full glossary
     * phrase on top of its already translated context.
     */
    public static function classify_term_boundaries($units, $glossary, $repair_duplicates = false) {
        $split_units = 0;
        $unknown_units = 0;
        $manual_review_units = 0;
        $repaired_units = 0;
        $repaired_occurrences = 0;
        $retranslation_units = [];

        foreach ($units as &$unit) {
            $old_status = (string) ($unit['status'] ?? '');
            $analysis = Lisun_Manual_V2_Term_Boundary::analyze_unit($unit, $glossary);
            $unit['term_check'] = [
                'status' => (string) ($analysis['status'] ?? Lisun_Manual_V2_Term_Boundary::COMPLETE),
                'checks' => (array) ($analysis['term_checks'] ?? []),
                'issues' => (array) ($analysis['issues'] ?? []),
                'manual_review_required' => !empty($analysis['manual_review_required']),
            ];
            if ($repair_duplicates && ($analysis['status'] ?? '') === Lisun_Manual_V2_Term_Boundary::DUPLICATE) {
                $changed = false;
                foreach ((array) ($analysis['issues'] ?? []) as $issue) {
                    if (($issue['status'] ?? '') !== Lisun_Manual_V2_Term_Boundary::DUPLICATE) continue;
                    [$issue_changed, $template] = Lisun_Manual_V2_Term_Boundary::repair_duplicate(
                        (string) ($unit['target_template'] ?? ''),
                        $issue
                    );
                    if ($issue_changed) {
                        $unit['target_template'] = $template;
                        $changed = true;
                        $repaired_occurrences++;
                    }
                }
                if ($changed) {
                    $repaired_units++;
                    $analysis = Lisun_Manual_V2_Term_Boundary::analyze_unit($unit, $glossary);
                    $unit['term_check'] = [
                        'status' => (string) ($analysis['status'] ?? Lisun_Manual_V2_Term_Boundary::COMPLETE),
                        'checks' => (array) ($analysis['term_checks'] ?? []),
                        'issues' => (array) ($analysis['issues'] ?? []),
                        'manual_review_required' => !empty($analysis['manual_review_required']),
                    ];
                }
            }

            if (($analysis['status'] ?? '') === Lisun_Manual_V2_Term_Boundary::TERM_ANOMALY) {
                // DeepL may return the target word in the visible paragraph
                // fewer/more times than the Chinese source contains it. Keep
                // that natural translation, but lock this unit from automatic
                // glossary replacement and surface it for manual review.
                $unit['status'] = Lisun_Manual_V2_Term_Boundary::TERM_ANOMALY;
                $manual_review_units++;
            } elseif ($old_status === Lisun_Manual_V2_Term_Boundary::TERM_ANOMALY) {
                // A later translation that passes the visible-count check may
                // safely leave the manual-review state.
                $unit['status'] = 'translated';
            } elseif ($old_status === 'degraded') {
                // A degraded natural-language snapshot can still contain the
                // expected glossary words. It remains non-replaceable because
                // its stable anchors are missing, but it is not a term-count
                // anomaly when the visible count matches.
                $unit['status'] = 'degraded';
            } elseif (($analysis['status'] ?? '') === Lisun_Manual_V2_Term_Boundary::SPLIT) {
                $unit['status'] = Lisun_Manual_V2_Term_Boundary::SPLIT;
                $split_units++;
                if ($old_status === Lisun_Manual_V2_Term_Boundary::UNKNOWN) {
                    $retranslation_units[] = (string) ($unit['unit_key'] ?? '');
                }
            } elseif (($analysis['status'] ?? '') === Lisun_Manual_V2_Term_Boundary::UNKNOWN) {
                $unit['status'] = Lisun_Manual_V2_Term_Boundary::UNKNOWN;
                $unknown_units++;
                if (!empty($analysis['retranslation_required'])
                    || Lisun_Manual_V2_Term_Boundary::requires_boundary_retranslation($old_status)) {
                    $retranslation_units[] = (string) ($unit['unit_key'] ?? '');
                }
            } elseif (Lisun_Manual_V2_Term_Boundary::requires_boundary_retranslation($old_status)) {
                // A previously locked boundary no longer matches the current
                // glossary shape. It cannot safely fall back to normal local
                // replacement; force a fresh unit translation instead.
                $unit['status'] = Lisun_Manual_V2_Term_Boundary::UNKNOWN;
                $unknown_units++;
                $retranslation_units[] = (string) ($unit['unit_key'] ?? '');
            }
        }
        unset($unit);

        return [
            'units' => $units,
            'split_units' => $split_units,
            'unknown_units' => $unknown_units,
            'manual_review_units' => $manual_review_units,
            'repaired_units' => $repaired_units,
            'repaired_occurrences' => $repaired_occurrences,
            'retranslation_units' => array_values(array_unique(array_filter($retranslation_units))),
        ];
    }

    /**
     * Compare the active stable occurrences with the current glossary.
     * This is deliberately independent of the English public HTML: term_id is
     * the identity and source_text is only used for a human-readable record.
     */
    public static function summarize_glossary_apply($active, $glossary) {
        $by_term_id = [];
        $by_zh = [];
        foreach ((array) $glossary as $term) {
            $term_id = (string) ($term['term_id'] ?? '');
            if ($term_id !== '') $by_term_id[$term_id] = $term;
            $zh = self::normalize_source_text($term['zh'] ?? '');
            if ($zh !== '') $by_zh[$zh] = $term;
        }

        $occurrences_by_unit = [];
        foreach ((array) ($active['occurrences'] ?? []) as $occurrence) {
            $occurrences_by_unit[(string) ($occurrence['unit_key'] ?? '')][] = $occurrence;
        }
        $units_for_boundary = [];
        foreach ((array) ($active['units'] ?? []) as $unit) {
            $unit['occurrences'] = $occurrences_by_unit[(string) ($unit['unit_key'] ?? '')] ?? [];
            $units_for_boundary[] = $unit;
        }
        $boundary = self::classify_term_boundaries($units_for_boundary, $glossary, false);
        $locked_units = [];
        foreach ((array) ($boundary['units'] ?? []) as $unit) {
            if (Lisun_Manual_V2_Term_Boundary::requires_boundary_retranslation($unit['status'] ?? '')) {
                $locked_units[(string) ($unit['unit_key'] ?? '')] = (string) ($unit['status'] ?? '');
            }
        }

        $case_contexts = self::derive_case_contexts(
            $active['units'] ?? [],
            $active['occurrences'] ?? []
        );
        $updated = 0;
        $changed_terms = [];
        foreach ((array) ($active['occurrences'] ?? []) as $occurrence) {
            if (isset($locked_units[(string) ($occurrence['unit_key'] ?? '')])) continue;
            $term_id = (string) ($occurrence['term_id'] ?? '');
            $term = $term_id !== '' && isset($by_term_id[$term_id])
                ? $by_term_id[$term_id]
                : ($by_zh[self::normalize_source_text($occurrence['source_text'] ?? '')] ?? null);
            if (!$term) continue;

            $unit_key = (string) ($occurrence['unit_key'] ?? '');
            $source_order = (string) ($occurrence['source_order'] ?? '');
            $context = (string) ($case_contexts[$unit_key][$source_order]
                ?? $occurrence['case_context'] ?? 'body');
            $before = Lisun_Manual_V2_Term_Matcher::apply_case(
                (string) ($occurrence['translated_text'] ?? ''),
                $context
            );
            $after = Lisun_Manual_V2_Term_Matcher::apply_case(
                (string) ($term['en'] ?? ''),
                $context
            );
            if ($before === $after) continue;

            $updated++;
            $changed_terms[$term_id] = (string) ($term['zh'] ?? $occurrence['source_text'] ?? $term_id);
        }

        return [
            'updated' => $updated,
            'changed_terms' => array_values($changed_terms),
            'term_split_units' => count(array_filter($locked_units, static function ($status) {
                return $status === Lisun_Manual_V2_Term_Boundary::SPLIT;
            })),
            'term_unknown_units' => count(array_filter($locked_units, static function ($status) {
                return $status === Lisun_Manual_V2_Term_Boundary::UNKNOWN;
            })),
        ];
    }

    /**
     * Compare the live Chinese source with the committed V2 revision.
     *
     * The revision hash is intentionally a raw-byte fingerprint for audit
     * purposes. WordPress/editor saves can nevertheless rewrite equivalent
     * HTML (line endings, indentation, attributes, etc.). Such a rewrite is
     * not a source-language change and must not block glossary application or
     * reset a completed translation. On a raw mismatch, compare the extracted
     * semantic units before classifying it as a real source change.
     */
    public static function compare_source_to_active_revision($post_id, $content, $title, $active = null) {
        if (!$active) {
            $active = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
        }
        if (!$active || empty($active['revision'])) {
            return [
                'matches' => false,
                'raw_matches' => false,
                'reason' => 'no_active_revision',
            ];
        }

        $current_hash = hash('sha256', (string) $content . "\nTITLE\n" . (string) $title);
        $active_hash = (string) ($active['revision']['source_hash'] ?? '');
        if ($active_hash !== '' && hash_equals($active_hash, $current_hash)) {
            return [
                'matches' => true,
                'raw_matches' => true,
                'reason' => 'exact',
                'current_hash' => $current_hash,
                'active_hash' => $active_hash,
            ];
        }

        $current_content = Lisun_Manual_V2_Unit_Extractor::extract((string) $content, 'content');
        if (is_wp_error($current_content)) {
            return [
                'matches' => false,
                'raw_matches' => false,
                'reason' => 'body_parse_failed',
                'current_hash' => $current_hash,
                'active_hash' => $active_hash,
            ];
        }
        $current_title = Lisun_Manual_V2_Unit_Extractor::extract_plain((string) $title, 'title', 'title');

        $active_content_units = array_values(array_filter((array) ($active['units'] ?? []), static function ($unit) {
            return (string) ($unit['field_name'] ?? $unit['field'] ?? 'content') === 'content';
        }));
        $active_title_units = array_values(array_filter((array) ($active['units'] ?? []), static function ($unit) {
            return (string) ($unit['field_name'] ?? $unit['field'] ?? '') === 'title';
        }));

        $body_matches = self::semantic_units_match(
            (array) ($current_content['units'] ?? []),
            $active_content_units
        );
        $title_matches = !empty($active_title_units)
            && self::semantic_text_match(
                (string) ($current_title['source_text'] ?? ''),
                (string) ($active_title_units[0]['source_text'] ?? '')
            );

        // 图片 URL 位于文档骨架而不是翻译单元文本里，仅比较语义文本会漏掉
        // “只换了图片”的修改：正文文字没变，但远程站必须收到新的图片链接，
        // 否则会一直显示换图前的旧图。这里按顺序比较 <img src>，避免把换图
        // 误判成“仅格式变化”而跳过重译。
        if ($body_matches && $title_matches
            && self::image_src_signature((string) $content)
                !== self::image_src_signature((string) ($active['revision']['source_skeleton'] ?? ''))) {
            return [
                'matches' => false,
                'raw_matches' => false,
                'reason' => 'images_changed',
                'current_hash' => $current_hash,
                'active_hash' => $active_hash,
            ];
        }

        if ($body_matches && $title_matches) {
            return [
                'matches' => true,
                'raw_matches' => false,
                'reason' => 'format_only',
                'current_hash' => $current_hash,
                'active_hash' => $active_hash,
            ];
        }

        if (!$body_matches && !$title_matches) {
            $reason = 'title_and_body_changed';
        } elseif (!$body_matches) {
            $reason = 'body_changed';
        } else {
            $reason = 'title_changed';
        }
        return [
            'matches' => false,
            'raw_matches' => false,
            'reason' => $reason,
            'current_hash' => $current_hash,
            'active_hash' => $active_hash,
        ];
    }

    private static function semantic_units_match($current, $active) {
        if (count($current) !== count($active)) return false;
        $count = count($current);
        for ($i = 0; $i < $count; $i++) {
            $current_unit = (array) ($current[$i] ?? []);
            $active_unit = (array) ($active[$i] ?? []);
            if ((string) ($current_unit['unit_type'] ?? '') !== (string) ($active_unit['unit_type'] ?? '')) {
                return false;
            }
            if (!self::semantic_text_match(
                (string) ($current_unit['source_text'] ?? ''),
                (string) ($active_unit['source_text'] ?? '')
            )) {
                return false;
            }
        }
        return true;
    }

    private static function semantic_text_match($left, $right) {
        $normalize = static function ($value) {
            $value = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim((string) preg_replace('/\s+/u', ' ', $value));
        };
        return $normalize($left) === $normalize($right);
    }

    /**
     * Extract the ordered list of image src URLs from an HTML document (or a
     * V2 skeleton, whose non-unit <img> tags are preserved verbatim). Only the
     * src attribute is compared: it is the definitive "which image" signal and
     * is immune to attribute reordering / srcset generation differences.
     */
    private static function image_src_signature($html) {
        $urls = [];
        if (preg_match_all('/<img\b[^>]*>/is', (string) $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $m)) {
                    $url = trim(html_entity_decode((string) $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($url !== '') {
                        $urls[] = $url;
                    }
                }
            }
        }
        return $urls;
    }

    /**
     * Apply the current glossary to an already committed V2 document.
     *
     * This is intentionally not a translation operation.  The committed
     * Chinese skeleton, unit keys, occurrence IDs, term IDs and positions are
     * copied as-is; only the stored English fallback for occurrences whose
     * term_id still exists in the current glossary is refreshed.  Therefore a
     * glossary-only apply makes zero DeepL requests and cannot create/delete
     * anchors as a side effect.
     */
    public static function apply_glossary_only($post_id, $content, $title, $glossary) {
        $active = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
        if (!$active) return new WP_Error('v2_no_revision', '没有找到 V2 翻译版本，请先完整翻译一次');

        $source_check = self::compare_source_to_active_revision($post_id, $content, $title, $active);
        $source_hash = (string) ($source_check['current_hash'] ?? hash(
            'sha256', (string) $content . "\nTITLE\n" . (string) $title
        ));
        if (empty($source_check['matches'])) {
            self::log('V2 glossary-only rerouted to incremental translation post=' . (int) $post_id
                . ' reason=' . (string) ($source_check['reason'] ?? 'source_changed')
                . ' active_hash=' . (string) ($active['revision']['source_hash'] ?? '')
                . ' current_hash=' . $source_hash);
            // The old active revision can no longer be used for a direct
            // glossary-only materialization, but a source change does not
            // require a full-document translation. The V2 planner compares
            // semantic units and sends only new/changed units to DeepL.
            $incremental = self::translate_document(
                $post_id,
                (string) $content,
                (string) $title,
                false
            );
            if (is_wp_error($incremental)) return $incremental;
            $incremental['source_change_rerouted'] = true;
            $incremental['source_change_reason'] = (string) ($source_check['reason'] ?? 'source_changed');
            return $incremental;
        }
        if (($source_check['reason'] ?? '') === 'format_only') {
            self::log('V2 glossary-only accepted semantic-equivalent source post=' . (int) $post_id
                . ' reason=format_only active_hash=' . (string) ($active['revision']['source_hash'] ?? '')
                . ' current_hash=' . $source_hash);
        }

        $active_units_for_analysis = [];
        $active_occurrences_by_unit = [];
        foreach ((array) ($active['occurrences'] ?? []) as $occurrence) {
            $active_occurrences_by_unit[(string) ($occurrence['unit_key'] ?? '')][] = $occurrence;
        }
        foreach ((array) ($active['units'] ?? []) as $active_unit) {
            $active_unit['occurrences'] = $active_occurrences_by_unit[(string) ($active_unit['unit_key'] ?? '')] ?? [];
            $active_units_for_analysis[] = $active_unit;
        }
        $boundary = self::classify_term_boundaries($active_units_for_analysis, $glossary, true);
        if (!empty($boundary['retranslation_units'])) {
            self::log('V2 glossary-only reroute post=' . (int) $post_id
                . ' reason=term_boundary_changed'
                . ' units=' . count($boundary['retranslation_units'])
                . ' action=incremental_translate');
            return self::translate_document(
                $post_id,
                $content,
                $title,
                false,
                (array) ($boundary['retranslation_units'] ?? [])
            );
        }
        $active_for_apply = $active;
        $active_for_apply['units'] = $boundary['units'];
        $refreshed = self::refresh_glossary_occurrences($active_for_apply, $glossary);
        $occurrences = $refreshed['occurrences'];
        $by_unit = [];
        foreach ($occurrences as $occurrence) {
            $by_unit[(string) ($occurrence['unit_key'] ?? '')][] = $occurrence;
        }

        $units = [];
        $content_units = [];
        $title_unit = null;
        foreach ((array) ($active_for_apply['units'] ?? []) as $unit) {
            $unit['field'] = (string) ($unit['field_name'] ?? 'content');
            $unit['occurrences'] = $by_unit[(string) ($unit['unit_key'] ?? '')] ?? [];
            $units[] = $unit;
        }

        // A historical V2 revision may contain one literal old term value
        // instead of its slot (for example after an older partial materialize).
        // Repair it locally when the old value is uniquely identifiable; never
        // silently publish a partially updated glossary result.
        $slot_repair = self::repair_glossary_slots($units, (array) ($active['occurrences'] ?? []));
        if (is_wp_error($slot_repair)) {
            $repair_data = $slot_repair->get_error_data();
            $repair_units = array_values(array_unique(array_filter(array_map('strval',
                (array) ($repair_data['unit_keys'] ?? [])
            ))));
            if ($slot_repair->get_error_code() === 'v2_glossary_slot_mismatch' && !empty($repair_units)) {
                // A missing historical slot cannot be repaired by guessing and
                // should not trigger a full-document retry. Translate each
                // affected unit once; the planner reuses every other unit.
                self::log('V2 glossary-only reroute post=' . (int) $post_id
                    . ' reason=slot_mismatch units=' . count($repair_units)
                    . ' action=incremental_translate');
                return self::translate_document(
                    $post_id,
                    $content,
                    $title,
                    false,
                    $repair_units
                );
            }
            return $slot_repair;
        }
        $units = $slot_repair['units'];
        foreach ($units as $unit) {
            if ($unit['field'] === 'content') $content_units[] = $unit;
            if ($unit['field'] === 'title') $title_unit = $unit;
        }

        $public_content = Lisun_Manual_V2_Materializer::materialize_document(
            (string) ($active['revision']['source_skeleton'] ?? ''),
            $content_units,
            $occurrences,
            $glossary,
            Lisun_Manual_V2_Materializer::MODE_CURRENT_GLOSSARY
        );
        if (is_wp_error($public_content)) return $public_content;
        $public_content = Lisun_Manual_Translate::repair_deepl_structural_boundaries(
            (string) $content,
            (string) $public_content
        );
        $public_content = Lisun_Manual_Translate::merge_figure_captions_in_html($public_content);
        $integrity = self::validate_visible_translation((string) $content, (string) $public_content);
        if (is_wp_error($integrity)) return $integrity;
        if (!$title_unit) return new WP_Error('v2_title_missing', 'V2 标题翻译单元缺失');

        $glossary_by_id = [];
        foreach ((array) $glossary as $term) {
            if (!empty($term['term_id'])) $glossary_by_id[$term['term_id']] = $term;
        }
        $title_occurrences = $by_unit[(string) ($title_unit['unit_key'] ?? '')] ?? [];
        $title_glossary_by_id = Lisun_Manual_V2_Term_Boundary::is_locked_status($title_unit['status'] ?? '')
            ? []
            : $glossary_by_id;
        if (empty($title_occurrences)) {
            // Older V2 revisions deliberately kept titles marker-free and have
            // no term identity to refresh. Do not silently report success while
            // leaving a glossary term in the old title; ask for one targeted
            // incremental translation to establish title slots.
            $title_probe = Lisun_Manual_V2_Unit_Extractor::extract_plain(
                (string) ($title_unit['source_text'] ?? $title), 'title', 'title'
            );
            $prepared_title = Lisun_Manual_V2_Unit_Transport::prepare($title_probe, $glossary);
            if (!empty($prepared_title['occurrences'])) {
                return new WP_Error(
                    'v2_title_slots_missing',
                    '当前 V2 版本的标题没有术语槽位，请先执行一次增量重译（只需生成标题槽位）'
                );
            }
        }
        $public_title = Lisun_Manual_V2_Materializer::materialize_unit(
            (string) ($title_unit['target_template'] ?? ''),
            $title_occurrences,
            $title_glossary_by_id,
            'title',
            Lisun_Manual_V2_Materializer::MODE_CURRENT_GLOSSARY
        );
        if (is_wp_error($public_title)) return $public_title;

        $revision = Lisun_Manual_V2_Repository::save_committed_revision(
            $post_id,
            $source_hash,
            (string) ($active['revision']['source_skeleton'] ?? ''),
            $units,
            (int) get_option('lisun_glossary_revision', 0)
        );
        if (is_wp_error($revision)) return $revision;

        self::log('V2 glossary-only committed post=' . (int) $post_id
            . ' revision=' . (int) $revision
            . ' updated=' . (int) $refreshed['updated']
            . ' degraded_units_skipped=' . (int) ($refreshed['degraded_units'] ?? 0)
            . ' term_split_units=' . (int) ($boundary['split_units'] ?? 0)
            . ' term_unknown_units=' . (int) ($boundary['unknown_units'] ?? 0)
            . ' term_anomaly_units=' . (int) ($boundary['manual_review_units'] ?? 0)
            . ' locked_units_skipped=' . (int) ($refreshed['locked_units'] ?? 0)
            . ' boundary_repaired=' . (int) ($boundary['repaired_occurrences'] ?? 0)
            . ' slot_repaired=' . (int) $slot_repair['repaired']
            . ' units=' . count($units)
            . ' occurrences=' . count($occurrences)
            . ' requests=0');

        return [
            'revision_id' => $revision,
            // Preserve the exact one-shot selection used by this run. The
            // temporary post override is cleared during planning, so the
            // publication stage must not re-read the post defaults.
            'glossary_term_ids' => array_values(array_filter(array_map(function ($term) {
                return (string) ($term['term_id'] ?? '');
            }, (array) $current_terms))),
            'content' => $public_content,
            'title' => $public_title,
            'units' => count($units),
            'translated_units' => 0,
            'reused_units' => count($units),
            'occurrences' => count($occurrences),
            'requests' => 0,
            'characters' => 0,
            'updated' => (int) $refreshed['updated'],
            'slot_repaired' => (int) $slot_repair['repaired'],
            'term_split_units' => (int) ($boundary['split_units'] ?? 0),
            'term_unknown_units' => (int) ($boundary['unknown_units'] ?? 0),
            'term_anomaly_units' => count(self::term_anomaly_summary($units)),
            'term_anomalies' => self::term_anomaly_summary($units),
            'boundary_repaired' => (int) ($boundary['repaired_occurrences'] ?? 0),
        ];
    }

    public static function source_change_message($reason) {
        $messages = [
            'title_changed' => '源文指纹不一致：标题已变化，请先重新翻译；应用术语表不会自动重译',
            'body_changed' => '源文指纹不一致：正文内容或结构已变化，请先重新翻译；应用术语表不会自动重译',
            'title_and_body_changed' => '源文指纹不一致：标题和正文都已变化，请先重新翻译；应用术语表不会自动重译',
            'body_parse_failed' => '源文指纹不一致：当前正文 HTML 无法按翻译单元解析，请先重新翻译',
            'images_changed' => '源文中的图片已更换，请先重新翻译以把新图片同步到远程站',
        ];
        return $messages[$reason] ?? '源文指纹不一致：无法确认当前源文与翻译版本对应，请先重新翻译';
    }

    /**
     * Ensure every historical occurrence has one local render slot.
     *
     * The normal V2 pipeline always stores {{LISUN_TERM:occ_*}} in the unit
     * template. This recovery path is for older/partially materialized
     * revisions only and never scans or retranslates the Chinese source.
     */
    public static function repair_glossary_slots($units, $historical_occurrences) {
        $old_by_id = [];
        foreach ((array) $historical_occurrences as $occurrence) {
            $id = (string) ($occurrence['occurrence_id'] ?? '');
            if ($id !== '') $old_by_id[$id] = $occurrence;
        }

        $repaired = 0;
        $unresolved = [];
        $unresolved_unit_keys = [];
        foreach ($units as &$unit) {
            // Boundary-damaged units are not safe for automatic slot repair.
            // A term-count anomaly is only a warning, so it must not make a
            // valid natural translation fail the glossary-only operation.
            if (in_array((string) ($unit['status'] ?? ''), [
                'degraded',
                Lisun_Manual_V2_Term_Boundary::SPLIT,
                Lisun_Manual_V2_Term_Boundary::UNKNOWN,
                Lisun_Manual_V2_Term_Boundary::TERM_ANOMALY,
            ], true)) continue;
            $template = (string) ($unit['target_template'] ?? '');
            foreach ((array) ($unit['occurrences'] ?? []) as $occurrence) {
                $id = (string) ($occurrence['occurrence_id'] ?? '');
                if ($id === '') continue;
                $marker = '{{LISUN_TERM:' . $id . '}}';
                $marker_count = substr_count($template, $marker);
                if ($marker_count === 1) continue;
                if ($marker_count > 1) {
                    $unresolved[] = self::slot_issue($unit, $occurrence, '术语槽位重复');
                    $unresolved_unit_keys[] = (string) ($unit['unit_key'] ?? '');
                    continue;
                }

                $old_text = (string) ($old_by_id[$id]['translated_text'] ?? '');
                if ($old_text === '') {
                    $unresolved[] = self::slot_issue($unit, $occurrence, '缺少历史译文');
                    $unresolved_unit_keys[] = (string) ($unit['unit_key'] ?? '');
                    continue;
                }

                $candidates = [htmlspecialchars(
                    $old_text,
                    ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8'
                )];
                if ($candidates[0] !== $old_text) $candidates[] = $old_text;
                $matches = 0;
                $selected = '';
                foreach (array_values(array_unique($candidates)) as $candidate) {
                    [$count, $updated_template] = self::replace_text_token_once(
                        $template, $candidate, $marker
                    );
                    if ($count === 1) {
                        // Prefer the HTML-escaped form. A raw ampersand can
                        // otherwise match the beginning of an &amp; entity.
                        if ($selected === '') $selected = $updated_template;
                        $matches = max(1, $matches);
                    } elseif ($count > 1 && $selected === '') {
                        $matches = max($matches, $count);
                    }
                }
                if ($matches !== 1 || $selected === '') {
                    $unresolved[] = self::slot_issue(
                        $unit,
                        $occurrence,
                        $matches === 0 ? '找不到唯一的历史译文' : '历史译文出现多次，无法安全定位'
                    );
                    $unresolved_unit_keys[] = (string) ($unit['unit_key'] ?? '');
                    continue;
                }
                $template = $selected;
                $repaired++;
            }
            $unit['target_template'] = $template;
        }
        unset($unit);

        if (!empty($unresolved)) {
            $detail = implode('；', array_slice($unresolved, 0, 5));
            if (count($unresolved) > 5) $detail .= '；以及其他 ' . (count($unresolved) - 5) . ' 处';
            return new WP_Error(
                'v2_glossary_slot_mismatch',
                '术语应用未完成：发现 ' . count($unresolved) . ' 个无法唯一定位的术语槽位（' . $detail . '），未保存和推送',
                ['unit_keys' => array_values(array_unique(array_filter($unresolved_unit_keys)))]
            );
        }

        return ['units' => $units, 'repaired' => $repaired];
    }

    private static function replace_text_token_once($template, $needle, $replacement) {
        $needle = (string) $needle;
        if ($needle === '') return [0, (string) $template];
        $tokens = Lisun_Manual_V2_Html_Tokenizer::tokenize((string) $template);
        $count = 0;
        foreach ($tokens as &$token) {
            if (($token['type'] ?? '') !== 'text') continue;
            $hits = substr_count((string) ($token['raw'] ?? ''), $needle);
            if ($hits > 0) {
                $count += $hits;
                $token['raw'] = str_replace($needle, $replacement, (string) $token['raw']);
            }
        }
        unset($token);
        return [$count, Lisun_Manual_V2_Html_Tokenizer::untokenize($tokens)];
    }

    private static function slot_issue($unit, $occurrence, $reason) {
        return 'unit=' . (string) ($unit['unit_key'] ?? '')
            . ', source=' . (string) ($occurrence['source_text'] ?? '')
            . ', occurrence=' . (string) ($occurrence['occurrence_id'] ?? '')
            . '：' . (string) $reason;
    }

    /** Refresh English values while retaining every historical occurrence identity. */
    public static function refresh_glossary_occurrences($active, $glossary) {
        $by_term_id = [];
        $by_zh = [];
        foreach ((array) $glossary as $term) {
            $term_id = (string) ($term['term_id'] ?? '');
            if ($term_id !== '') $by_term_id[$term_id] = $term;
            $zh = self::normalize_source_text($term['zh'] ?? '');
            if ($zh !== '') $by_zh[$zh] = $term;
        }

        $case_contexts = self::derive_case_contexts(
            $active['units'] ?? [],
            $active['occurrences'] ?? []
        );
        $updated = 0;
        $occurrences = [];
        $degraded_units = [];
        $locked_units = [];
        foreach ((array) ($active['units'] ?? []) as $unit) {
            if (Lisun_Manual_V2_Term_Boundary::is_locked_status($unit['status'] ?? '')) {
                $unit_key = (string) ($unit['unit_key'] ?? '');
                $locked_units[$unit_key] = true;
                if ((string) ($unit['status'] ?? '') === 'degraded') $degraded_units[$unit_key] = true;
            }
        }
        foreach ((array) ($active['occurrences'] ?? []) as $occurrence) {
            if (!empty($locked_units[(string) ($occurrence['unit_key'] ?? '')])) {
                $occurrences[] = $occurrence;
                continue;
            }
            $term_id = (string) ($occurrence['term_id'] ?? '');
            $term = $term_id !== '' && isset($by_term_id[$term_id])
                ? $by_term_id[$term_id]
                : ($by_zh[self::normalize_source_text($occurrence['source_text'] ?? '')] ?? null);
            if ($term) {
                $unit_key = (string) ($occurrence['unit_key'] ?? '');
                $source_order = (string) ($occurrence['source_order'] ?? '');
                $context = (string) ($case_contexts[$unit_key][$source_order]
                    ?? $occurrence['case_context'] ?? 'body');
                $new_text = Lisun_Manual_V2_Term_Matcher::apply_case(
                    (string) ($term['en'] ?? ''),
                    $context
                );
                $old_text = (string) ($occurrence['translated_text'] ?? '');
                if ($old_text !== $new_text) $updated++;
                $occurrence['case_context'] = $context;
                $occurrence['translated_text'] = $new_text;
                if ($term_id === '' || !isset($by_term_id[$term_id])) {
                    self::log('V2 glossary-only matched occurrence by Chinese source'
                        . ' source=' . self::normalize_source_text($occurrence['source_text'] ?? '')
                        . ' historical_term_id=' . $term_id
                        . ' current_term_id=' . (string) ($term['term_id'] ?? ''));
                }
            }
            $occurrences[] = $occurrence;
        }

        return [
            'occurrences' => $occurrences,
            'updated' => $updated,
            'degraded_units' => count($degraded_units),
            'locked_units' => count($locked_units),
        ];
    }

    /**
     * Rebuild sentence-position metadata from the translated English slots.
     * This upgrades historical V2 occurrences when a glossary is reapplied,
     * without changing their occurrence IDs or making a DeepL request.
     */
    private static function derive_case_contexts($units, $occurrences = []) {
        $contexts = [];
        $by_unit = [];
        foreach ((array) $occurrences as $occurrence) {
            $by_unit[(string) ($occurrence['unit_key'] ?? '')][] = $occurrence;
        }
        foreach ((array) $units as $unit) {
            $unit_key = (string) ($unit['unit_key'] ?? '');
            if ($unit_key === '') continue;
            $unit_occurrences = (array) ($unit['occurrences'] ?? ($by_unit[$unit_key] ?? []));
            $by_id = Lisun_Manual_V2_Materializer::target_case_contexts(
                (string) ($unit['target_template'] ?? ''),
                $unit_occurrences,
                (string) ($unit['field_name'] ?? $unit['field'] ?? 'content')
            );
            foreach ($unit_occurrences as $occurrence) {
                $id = (string) ($occurrence['occurrence_id'] ?? '');
                if ($id === '' || !isset($by_id[$id])) continue;
                $contexts[$unit_key][(string) ($occurrence['source_order'] ?? '')]
                    = (string) $by_id[$id];
            }
        }
        return $contexts;
    }

    private static function normalize_source_text($text) {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    public static function translate_document($post_id, $content, $title, $force = false, $force_unit_keys = []) {
        Lisun_Manual_V2_Schema::maybe_upgrade();
        self::progress($post_id, 'extracting', 8, '正在提取翻译单元…');
        $content_document = Lisun_Manual_V2_Unit_Extractor::extract(
            (string) $content,
            'content',
            function ($processed, $total) use ($post_id) {
                $ratio = $total > 0 ? ($processed / $total) : 1;
                $progress = 8 + (int) floor($ratio * 8);
                self::progress($post_id, 'extracting', min(16, $progress),
                    '正在提取翻译单元…已扫描 ' . $processed . '/' . $total . ' 个文档片段');
            }
        );
        if (is_wp_error($content_document)) return $content_document;
        $title_unit = Lisun_Manual_V2_Unit_Extractor::extract_plain((string) $title, 'title', 'title');

        $active = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
        $legacy_active = $active
            && (string) ($active['revision']['transport_version'] ?? 'legacy')
                !== Lisun_Manual_V2_Schema::TRANSPORT_VERSION;
        if ($legacy_active) {
            $force = true;
            self::log('V2 legacy transport detected post=' . (int) $post_id
                . ' revision=' . (int) ($active['revision']['id'] ?? 0)
                . ' transport=' . (string) ($active['revision']['transport_version'] ?? 'legacy')
                . ' action=full_retranslate');
        }
        $current_terms = Lisun_Manual_Glossary::is_enabled() ? Lisun_Manual_Glossary::terms_for_post($post_id) : [];
        if (Lisun_Manual_Glossary::is_enabled()) {
            // “仅本次翻译使用”的临时选择已物化到 $current_terms，消费一次后清除，
            // 下次翻译若无新的临时选择则回落到本篇默认配置。
            Lisun_Manual_Glossary::clear_post_term_override($post_id);
        }
        $units = array_merge($content_document['units'], [$title_unit]);
        self::progress($post_id, 'planning', 18, '已提取 ' . count($units) . ' 个翻译单元，正在分析可复用译文…', [
            'total_units' => count($units),
        ]);
        $plan = Lisun_Manual_V2_Planner::plan(
            $units,
            $active,
            $current_terms,
            $force,
            $force_unit_keys
        );
        $pending = $plan['pending'];
        $translated_units = $plan['reused'];
        $reuse_count = $plan['reuse_count'];
        self::log('V2 plan post=' . (int) $post_id
            . ' units=' . count($units)
            . ' pending=' . count($pending)
            . ' reused=' . $reuse_count
            . ' force=' . ($force ? 'yes' : 'no'));

        $adapter = self::make_translation_adapter($current_terms);
        if (!empty($pending)) {
            $total_pending = count($pending);
            self::progress($post_id, 'translating', 25,
                '正在翻译 ' . $total_pending . ' 个待翻译单元…', [
                    'completed_units' => 0,
                    'total_units' => $total_pending,
                ]);
            $translated = $adapter->translate($pending, $post_id, function ($completed, $total, $completed_batches, $total_batches) use ($post_id) {
                $ratio = $total > 0 ? ($completed / $total) : 1;
                $progress = 25 + (int) floor($ratio * 50);
                self::progress($post_id, 'translating', min(75, $progress),
                    '正在翻译翻译单元：已完成 ' . $completed . '/' . $total . ' 个…', [
                        'completed_units' => $completed,
                        'total_units' => $total,
                        'completed_batches' => $completed_batches,
                        'total_batches' => $total_batches,
                    ]);
            });
            if (is_wp_error($translated)) return $translated;
            foreach ($pending as $prepared) {
                $key = $prepared['unit']['unit_key'];
                if (!isset($translated[$key])) {
                    return new WP_Error('v2_missing_unit', 'V2 翻译结果缺少完整单元');
                }
                $unit = $prepared['unit'];
                $unit['target_template'] = $translated[$key]['target_template'];
                $unit['occurrence_signature'] = $prepared['occurrence_signature'];
                $unit['occurrences'] = $translated[$key]['occurrences'];
                $unit['lock_state'] = 'auto';
                $unit['status'] = ($translated[$key]['anchor_status'] ?? 'complete') === 'degraded'
                    ? 'degraded'
                    : 'translated';
                $translated_units[$key] = $unit;
            }
        } else {
            self::progress($post_id, 'translating', 75, '待翻译单元已全部复用，正在整理译文…', [
                'completed_units' => 0,
                'total_units' => 0,
            ]);
        }

        usort($units, function ($a, $b) {
            if ($a['field'] === $b['field']) return $a['unit_order'] <=> $b['unit_order'];
            return strcmp($a['field'], $b['field']);
        });
        $ordered = [];
        foreach ($units as $unit) $ordered[] = $translated_units[$unit['unit_key']];

        // Persist casing metadata from the actual English slot positions,
        // after DeepL has completed any sentence-level word reordering.
        foreach ($ordered as &$ordered_unit) {
            $target_contexts = Lisun_Manual_V2_Materializer::target_case_contexts(
                (string) ($ordered_unit['target_template'] ?? ''),
                (array) ($ordered_unit['occurrences'] ?? []),
                (string) ($ordered_unit['field'] ?? 'content')
            );
            foreach ($ordered_unit['occurrences'] as &$occurrence) {
                $id = (string) ($occurrence['occurrence_id'] ?? '');
                if ($id === '' || !isset($target_contexts[$id])) continue;
                $occurrence['case_context'] = (string) $target_contexts[$id];
                $occurrence['translated_text'] = Lisun_Manual_V2_Term_Matcher::apply_case(
                    (string) ($occurrence['translated_text'] ?? ''),
                    (string) $target_contexts[$id]
                );
            }
            unset($occurrence);
        }
        unset($ordered_unit);

        $glossary = $current_terms;
        $boundary = self::classify_term_boundaries($ordered, $glossary, true);
        $ordered = $boundary['units'];

        $all_occurrences = [];
        foreach ($ordered as $unit) {
            foreach ((array) $unit['occurrences'] as $occurrence) {
                $occurrence['unit_key'] = $unit['unit_key'];
                $all_occurrences[] = $occurrence;
            }
        }
        $degraded_units = count(array_filter($ordered, function ($unit) {
            return (string) ($unit['status'] ?? '') === 'degraded';
        }));
        $content_units = array_values(array_filter($ordered, function ($unit) {
            return $unit['field'] === 'content';
        }));
        $title_units = array_values(array_filter($ordered, function ($unit) {
            return $unit['field'] === 'title';
        }));
        self::progress($post_id, 'rendering', 80, '正在组装译文并检查完整性…', [
            'total_units' => count($ordered),
        ]);
        $public_content = Lisun_Manual_V2_Materializer::materialize_document(
            $content_document['skeleton'], $content_units, $all_occurrences, [],
            Lisun_Manual_V2_Materializer::MODE_SNAPSHOT
        );
        if (is_wp_error($public_content)) return $public_content;
        $public_content = Lisun_Manual_Translate::repair_deepl_structural_boundaries(
            (string) $content,
            (string) $public_content
        );
        $public_content = Lisun_Manual_Translate::merge_figure_captions_in_html($public_content);
        $integrity = self::validate_visible_translation((string) $content, (string) $public_content);
        if (is_wp_error($integrity)) return $integrity;
        if (empty($title_units)) return new WP_Error('v2_title_missing', 'V2 标题翻译单元缺失');

        $title_occurrences = array_values(array_filter($all_occurrences, function ($occurrence) use ($title_units) {
            return $occurrence['unit_key'] === $title_units[0]['unit_key'];
        }));
        $glossary_by_id = [];
        foreach ($glossary as $term) $glossary_by_id[$term['term_id']] = $term;
        $public_title = Lisun_Manual_V2_Materializer::materialize_unit(
            $title_units[0]['target_template'], $title_occurrences, [], 'title',
            Lisun_Manual_V2_Materializer::MODE_SNAPSHOT
        );
        if (is_wp_error($public_title)) return $public_title;

        $source_hash = hash('sha256', (string) $content . "\nTITLE\n" . (string) $title);
        $revision = Lisun_Manual_V2_Repository::save_committed_revision(
            $post_id,
            $source_hash,
            $content_document['skeleton'],
            $ordered,
            (int) get_option('lisun_glossary_revision', 0)
        );
        if (is_wp_error($revision)) return $revision;
        self::progress($post_id, 'saving', 86, '翻译单元已保存，正在准备发布…', [
            'total_units' => count($ordered),
        ]);
        $stats = $adapter->stats();
        self::log('V2 committed post=' . (int) $post_id
            . ' revision=' . (int) $revision
            . ' translated_units=' . count($pending)
            . ' reused_units=' . $reuse_count
            . ' degraded_units=' . $degraded_units
            . ' term_split_units=' . (int) ($boundary['split_units'] ?? 0)
            . ' term_unknown_units=' . (int) ($boundary['unknown_units'] ?? 0)
            . ' term_anomaly_units=' . (int) ($boundary['manual_review_units'] ?? 0)
            . ' boundary_repaired=' . (int) ($boundary['repaired_occurrences'] ?? 0)
            . ' occurrences=' . count($all_occurrences)
            . ' requests=' . (int) $stats['requests']);

        return [
            'revision_id' => $revision,
            'content' => $public_content,
            'title' => $public_title,
            'units' => count($ordered),
            'translated_units' => count($pending),
            'reused_units' => $reuse_count,
            'degraded_units' => $degraded_units,
            'occurrences' => count($all_occurrences),
            'requests' => $stats['requests'],
            'characters' => $stats['characters'],
            'term_anomaly_units' => count(self::term_anomaly_summary($ordered)),
            'term_anomalies' => self::term_anomaly_summary($ordered),
        ];
    }

    /** Return paragraph-level glossary count mismatches for manual review. */
    private static function term_anomaly_summary($units) {
        $items = [];
        foreach ((array) $units as $unit) {
            if ((string) ($unit['status'] ?? '') !== Lisun_Manual_V2_Term_Boundary::TERM_ANOMALY) continue;
            $check = (array) ($unit['term_check'] ?? []);
            $items[] = [
                'unit_key' => (string) ($unit['unit_key'] ?? ''),
                'unit_type' => (string) ($unit['unit_type'] ?? 'text'),
                'source_path' => (string) ($unit['source_path'] ?? ''),
                'source_text' => (string) ($unit['source_text'] ?? ''),
                'checks' => (array) ($check['checks'] ?? []),
                'issues' => (array) ($check['issues'] ?? []),
            ];
        }
        return $items;
    }

    public static function materialize_active($post_id) {
        $active = Lisun_Manual_V2_Repository::get_active_snapshot($post_id);
        if (!$active) return new WP_Error('v2_no_revision', '没有找到 V2 翻译版本');
        $glossary = Lisun_Manual_Glossary::is_enabled() ? Lisun_Manual_Glossary::get_all() : [];
        $content_units = [];
        $title_unit = null;
        foreach ($active['units'] as $unit) {
            $unit['field'] = $unit['field_name'];
            if ($unit['field_name'] === 'content') $content_units[] = $unit;
            if ($unit['field_name'] === 'title') $title_unit = $unit;
        }
        $content = Lisun_Manual_V2_Materializer::materialize_document(
            $active['revision']['source_skeleton'], $content_units, $active['occurrences'], [],
            Lisun_Manual_V2_Materializer::MODE_SNAPSHOT
        );
        if (is_wp_error($content)) return $content;
        $content = Lisun_Manual_Translate::repair_deepl_structural_boundaries(
            (string) $active['revision']['source_skeleton'],
            (string) $content
        );
        $content = Lisun_Manual_Translate::merge_figure_captions_in_html($content);
        if (!$title_unit) return new WP_Error('v2_title_missing', 'V2 标题翻译单元缺失');
        $title_occurrences = array_values(array_filter($active['occurrences'], function ($occurrence) use ($title_unit) {
            return $occurrence['unit_key'] === $title_unit['unit_key'];
        }));
        $by_id = [];
        foreach ($glossary as $term) $by_id[$term['term_id']] = $term;
        $title = Lisun_Manual_V2_Materializer::materialize_unit(
            $title_unit['target_template'], $title_occurrences, [], 'title',
            Lisun_Manual_V2_Materializer::MODE_SNAPSHOT
        );
        if (is_wp_error($title)) return $title;
        return ['content' => $content, 'title' => $title, 'snapshot' => $active];
    }

    /**
     * Machine-translation engine selected in the settings screen.
     * Both adapters expose the same translate()/stats() contract, so the rest
     * of the pipeline (planning, restore, materialize, push) is engine-neutral.
     */
    private static function make_translation_adapter($terms = null) {
        $provider = (string) get_option('lisun_llm_provider', 'deepseek');
        if ($provider === 'google' && class_exists('Lisun_Manual_V2_Google_Adapter')) {
            self::log('V2 engine=google endpoint='
                . (string) (get_option('lisun_google_endpoint', '') ?: Lisun_Manual_V2_Google_Adapter::DEFAULT_ENDPOINT));
            return new Lisun_Manual_V2_Google_Adapter($terms);
        }
        self::log('V2 engine=deepl provider=' . $provider);
        return new Lisun_Manual_V2_DeepL_Adapter($terms);
    }

    private static function validate_visible_translation($source, $translated) {
        $source_text = trim(html_entity_decode(wp_strip_all_tags((string) $source), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $translated_text = trim(html_entity_decode(wp_strip_all_tags((string) $translated), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $source_chars = mb_strlen(preg_replace('/\s+/u', '', $source_text));
        $translated_chars = mb_strlen(preg_replace('/\s+/u', '', $translated_text));
        if ($source_chars > 0 && $translated_chars === 0) {
            return new WP_Error('v2_empty_document', 'DeepL 返回的整篇可见译文为空，未保存和推送');
        }
        if ($source_chars >= 20 && ($translated_chars / $source_chars) < 0.60) {
            return new WP_Error(
                'v2_incomplete_document',
                'DeepL 返回的整篇可见译文严重缺失（完整度低于 60%），未保存和推送'
            );
        }
        return true;
    }

    private static function log($message) {
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log((string) $message);
        }
    }

    private static function progress($post_id, $stage, $progress, $message, $extra = []) {
        if (class_exists('Lisun_Manual_Translate')
            && method_exists('Lisun_Manual_Translate', 'update_translation_progress')) {
            Lisun_Manual_Translate::update_translation_progress(
                (int) $post_id, $stage, (int) $progress, (string) $message, (array) $extra
            );
        }
    }
}
