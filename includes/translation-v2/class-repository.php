<?php
/** Persistence and atomic revision switching for Translation V2. */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Repository {

    public static function get_active_snapshot($source_post_id) {
        global $wpdb;
        Lisun_Manual_V2_Schema::maybe_upgrade();
        $documents = $wpdb->prefix . 'lisun_translation_documents';
        $revisions = $wpdb->prefix . 'lisun_translation_revisions';
        $units_table = $wpdb->prefix . 'lisun_translation_units';
        $occ_table = $wpdb->prefix . 'lisun_term_occurrences';

        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$documents} WHERE source_post_id = %d LIMIT 1",
            (int) $source_post_id
        ), ARRAY_A);
        if (!$document || empty($document['active_revision_id'])) return null;

        $revision = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$revisions} WHERE id = %d LIMIT 1",
            (int) $document['active_revision_id']
        ), ARRAY_A);
        if (!$revision) return null;

        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$units_table} WHERE revision_id = %d ORDER BY field_name, unit_order, id",
            (int) $revision['id']
        ), ARRAY_A);
        $occurrences = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, u.unit_key
             FROM {$occ_table} o INNER JOIN {$units_table} u ON u.id = o.unit_id
             WHERE o.revision_id = %d ORDER BY u.field_name, u.unit_order, o.source_order",
            (int) $revision['id']
        ), ARRAY_A);

        foreach ((array) $units as &$unit) {
            $decoded = json_decode((string) ($unit['term_check_json'] ?? ''), true);
            $unit['term_check'] = is_array($decoded) ? $decoded : [];
        }
        unset($unit);

        return [
            'document' => $document,
            'revision' => $revision,
            'units' => is_array($units) ? $units : [],
            'occurrences' => is_array($occurrences) ? $occurrences : [],
        ];
    }

    public static function save_committed_revision($source_post_id, $source_hash, $skeleton, $units, $glossary_revision, $transport_version = null) {
        global $wpdb;
        Lisun_Manual_V2_Schema::maybe_upgrade();
        $documents = $wpdb->prefix . 'lisun_translation_documents';
        $revisions = $wpdb->prefix . 'lisun_translation_revisions';
        $units_table = $wpdb->prefix . 'lisun_translation_units';
        $occ_table = $wpdb->prefix . 'lisun_term_occurrences';
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');
        try {
            $document = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$documents} WHERE source_post_id = %d FOR UPDATE",
                (int) $source_post_id
            ), ARRAY_A);
            if (!$document) {
                $ok = $wpdb->insert($documents, [
                    'source_post_id' => (int) $source_post_id,
                    'target_post_id' => 0,
                    'active_revision_id' => 0,
                    'source_hash' => '',
                    'glossary_revision' => 0,
                    'status' => 'staging',
                    'last_error' => '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'document insert failed');
                $document_id = (int) $wpdb->insert_id;
                $previous_revision = 0;
            } else {
                $document_id = (int) $document['id'];
                $previous_revision = (int) $document['active_revision_id'];
            }

            $ok = $wpdb->insert($revisions, [
                'document_id' => $document_id,
                'source_hash' => (string) $source_hash,
                'glossary_revision' => (int) $glossary_revision,
                'transport_version' => $transport_version === null
                    ? Lisun_Manual_V2_Schema::TRANSPORT_VERSION
                    : (string) $transport_version,
                'status' => 'staging',
                'source_skeleton' => (string) $skeleton,
                'created_at' => $now,
                'committed_at' => null,
            ]);
            if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'revision insert failed');
            $revision_id = (int) $wpdb->insert_id;

            foreach ((array) $units as $unit) {
                $ok = $wpdb->insert($units_table, [
                    'revision_id' => $revision_id,
                    'unit_key' => (string) $unit['unit_key'],
                    'field_name' => (string) ($unit['field'] ?? 'content'),
                    'unit_type' => (string) ($unit['unit_type'] ?? 'text'),
                    'unit_order' => (int) ($unit['unit_order'] ?? 0),
                    'source_path' => (string) ($unit['source_path'] ?? ''),
                    'source_text' => (string) ($unit['source_text'] ?? ''),
                    'source_html' => (string) ($unit['source_html'] ?? ''),
                    'source_hash' => (string) ($unit['source_hash'] ?? ''),
                    'occurrence_signature' => (string) ($unit['occurrence_signature'] ?? ''),
                    'target_template' => (string) ($unit['target_template'] ?? ''),
                    'status' => (string) ($unit['status'] ?? 'translated'),
                    'lock_state' => (string) ($unit['lock_state'] ?? 'auto'),
                    'term_check_json' => wp_json_encode(
                        (array) ($unit['term_check'] ?? []),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]);
                if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'unit insert failed');
                $unit_id = (int) $wpdb->insert_id;

                foreach ((array) ($unit['occurrences'] ?? []) as $occurrence) {
                    $ok = $wpdb->insert($occ_table, [
                        'occurrence_id' => (string) $occurrence['occurrence_id'],
                        'revision_id' => $revision_id,
                        'unit_id' => $unit_id,
                        'term_id' => (string) $occurrence['term_id'],
                        'source_order' => (int) ($occurrence['source_order'] ?? 0),
                        'source_text' => (string) ($occurrence['source_text'] ?? ''),
                        'source_offset' => (int) ($occurrence['source_offset'] ?? 0),
                        'case_context' => (string) ($occurrence['case_context'] ?? 'body'),
                        'translated_text' => (string) ($occurrence['translated_text'] ?? ''),
                        'status' => 'active',
                    ]);
                    if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'occurrence insert failed');
                }
            }

            $wpdb->update($revisions, [
                'status' => 'active',
                'committed_at' => $now,
            ], ['id' => $revision_id]);
            if ($previous_revision > 0) {
                $wpdb->update($revisions, ['status' => 'superseded'], ['id' => $previous_revision]);
            }
            $ok = $wpdb->update($documents, [
                'active_revision_id' => $revision_id,
                'source_hash' => (string) $source_hash,
                'glossary_revision' => (int) $glossary_revision,
                'status' => 'ready',
                'last_error' => '',
                'updated_at' => $now,
            ], ['id' => $document_id]);
            if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'document commit failed');
            $wpdb->query('COMMIT');
            return $revision_id;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('v2_database_write', 'V2 翻译版本写入失败：' . $error->getMessage());
        }
    }

    public static function update_document_state($source_post_id, $status, $target_post_id = null, $error = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'lisun_translation_documents';
        $data = [
            'status' => (string) $status,
            'last_error' => (string) $error,
            'updated_at' => current_time('mysql'),
        ];
        if ($target_post_id !== null) $data['target_post_id'] = (int) $target_post_id;
        $wpdb->update($table, $data, ['source_post_id' => (int) $source_post_id]);
    }

    /** Load a snapshot for a specific revision id (used by undo / rollback). */
    public static function get_snapshot_by_revision($revision_id) {
        global $wpdb;
        Lisun_Manual_V2_Schema::maybe_upgrade();
        $documents = $wpdb->prefix . 'lisun_translation_documents';
        $revisions = $wpdb->prefix . 'lisun_translation_revisions';
        $units_table = $wpdb->prefix . 'lisun_translation_units';
        $occ_table = $wpdb->prefix . 'lisun_term_occurrences';

        $revision = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$revisions} WHERE id = %d LIMIT 1",
            (int) $revision_id
        ), ARRAY_A);
        if (!$revision) return null;

        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$documents} WHERE id = %d LIMIT 1",
            (int) $revision['document_id']
        ), ARRAY_A);
        if (!$document) return null;

        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$units_table} WHERE revision_id = %d ORDER BY field_name, unit_order, id",
            (int) $revision['id']
        ), ARRAY_A);
        $occurrences = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, u.unit_key
             FROM {$occ_table} o INNER JOIN {$units_table} u ON u.id = o.unit_id
             WHERE o.revision_id = %d ORDER BY u.field_name, u.unit_order, o.source_order",
            (int) $revision['id']
        ), ARRAY_A);

        foreach ((array) $units as &$unit) {
            $decoded = json_decode((string) ($unit['term_check_json'] ?? ''), true);
            $unit['term_check'] = is_array($decoded) ? $decoded : [];
        }
        unset($unit);

        return [
            'document' => $document,
            'revision' => $revision,
            'units' => is_array($units) ? $units : [],
            'occurrences' => is_array($occurrences) ? $occurrences : [],
        ];
    }

    /** Find the most recent revision id for a document with id < $before_id. */
    public static function previous_revision_id($source_post_id, $before_id) {
        global $wpdb;
        Lisun_Manual_V2_Schema::maybe_upgrade();
        $documents = $wpdb->prefix . 'lisun_translation_documents';
        $revisions = $wpdb->prefix . 'lisun_translation_revisions';
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$documents} WHERE source_post_id = %d LIMIT 1",
            (int) $source_post_id
        ), ARRAY_A);
        if (!$doc) return 0;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$revisions} WHERE document_id = %d AND id < %d ORDER BY id DESC LIMIT 1",
            (int) $doc['id'], (int) $before_id
        ), ARRAY_A);
        return $row ? (int) $row['id'] : 0;
    }

    /** Atomically switch active pointer to $new_revision_id (used by undo). */
    public static function switch_active_revision($source_post_id, $new_revision_id) {
        global $wpdb;
        Lisun_Manual_V2_Schema::maybe_upgrade();
        $documents = $wpdb->prefix . 'lisun_translation_documents';
        $revisions = $wpdb->prefix . 'lisun_translation_revisions';
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$documents} WHERE source_post_id = %d LIMIT 1 FOR UPDATE",
            (int) $source_post_id
        ), ARRAY_A);
        if (!$doc) return false;
        $old_active = (int) $doc['active_revision_id'];
        // Mark the restored revision active, the previously-active one superseded.
        $wpdb->update($revisions, ['status' => 'active'], ['id' => (int) $new_revision_id]);
        if ($old_active > 0 && $old_active !== (int) $new_revision_id) {
            $wpdb->update($revisions, ['status' => 'superseded'], ['id' => $old_active]);
        }
        // Adopt source_hash / glossary_revision from the restored revision.
        $restored = $wpdb->get_row($wpdb->prepare(
            "SELECT source_hash, glossary_revision FROM {$revisions} WHERE id = %d",
            (int) $new_revision_id
        ), ARRAY_A);
        $wpdb->update($documents, [
            'active_revision_id' => (int) $new_revision_id,
            'source_hash' => (string) ($restored['source_hash'] ?? ''),
            'glossary_revision' => (int) ($restored['glossary_revision'] ?? 0),
            'status' => 'ready',
            'updated_at' => current_time('mysql'),
        ], ['id' => (int) $doc['id']]);
        return true;
    }
}
