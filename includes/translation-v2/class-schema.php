<?php
/**
 * Translation V2 database schema.
 *
 * V2 is additive: it never drops or rewrites the V1 backup options.  A failed
 * V2 run therefore cannot make the currently published translation unusable.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Schema {

    const VERSION = '3';
    // Each occurrence is sent as one non-empty <t id="occ_..."> tag. The
    // Chinese source remains inside the tag; the response is recovered by
    // occurrence id, never by guessing from the local English glossary.
    const TRANSPORT_VERSION = 'deepl-term-tag-v9';

    public static function maybe_upgrade() {
        if ((string) get_option('lisun_translation_v2_schema_version', '') === self::VERSION) {
            return;
        }
        self::create_tables();
        update_option('lisun_translation_v2_schema_version', self::VERSION, false);
    }

    public static function create_tables() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $documents = $wpdb->prefix . 'lisun_translation_documents';
        $revisions = $wpdb->prefix . 'lisun_translation_revisions';
        $units = $wpdb->prefix . 'lisun_translation_units';
        $occurrences = $wpdb->prefix . 'lisun_term_occurrences';
        $glossary = $wpdb->prefix . 'lisun_glossary_terms';

        $sql = [];
        $sql[] = "CREATE TABLE {$documents} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_post_id BIGINT UNSIGNED NOT NULL,
            target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active_revision_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_hash CHAR(64) NOT NULL DEFAULT '',
            glossary_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_post_id (source_post_id),
            KEY active_revision_id (active_revision_id),
            KEY status (status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$revisions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            document_id BIGINT UNSIGNED NOT NULL,
            source_hash CHAR(64) NOT NULL,
            glossary_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            transport_version VARCHAR(40) NOT NULL DEFAULT 'legacy',
            status VARCHAR(32) NOT NULL DEFAULT 'staging',
            source_skeleton LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            committed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY document_id (document_id),
            KEY status (status),
            KEY source_hash (source_hash)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$units} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            revision_id BIGINT UNSIGNED NOT NULL,
            unit_key CHAR(40) NOT NULL,
            field_name VARCHAR(40) NOT NULL DEFAULT 'content',
            unit_type VARCHAR(24) NOT NULL DEFAULT 'text',
            unit_order INT UNSIGNED NOT NULL DEFAULT 0,
            source_path VARCHAR(500) NOT NULL DEFAULT '',
            source_text LONGTEXT NOT NULL,
            source_html LONGTEXT NOT NULL,
            source_hash CHAR(64) NOT NULL,
            occurrence_signature CHAR(64) NOT NULL DEFAULT '',
            target_template LONGTEXT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            lock_state VARCHAR(16) NOT NULL DEFAULT 'auto',
            term_check_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY revision_unit (revision_id, unit_key),
            KEY source_hash (source_hash),
            KEY field_order (field_name, unit_order)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$occurrences} (
            occurrence_id VARCHAR(64) NOT NULL,
            revision_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NOT NULL,
            term_id VARCHAR(80) NOT NULL,
            source_order INT UNSIGNED NOT NULL DEFAULT 0,
            source_text TEXT NOT NULL,
            source_offset INT UNSIGNED NOT NULL DEFAULT 0,
            case_context VARCHAR(20) NOT NULL DEFAULT 'body',
            translated_text TEXT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            PRIMARY KEY (revision_id, occurrence_id),
            KEY occurrence_id (occurrence_id),
            KEY revision_id (revision_id),
            KEY unit_id (unit_id),
            KEY term_id (term_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$glossary} (
            term_id VARCHAR(80) NOT NULL,
            zh_text VARCHAR(500) NOT NULL,
            en_text VARCHAR(500) NOT NULL,
            note TEXT NULL,
            revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (term_id),
            UNIQUE KEY zh_text (zh_text(191)),
            KEY status (status),
            KEY revision (revision)
        ) {$charset};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }

    /**
     * Mirror the option-backed glossary into a relational table.  V1 can keep
     * reading its option while V2 gets stable indexed term rows.
     */
    public static function sync_glossary_terms($entries, $revision = null) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_charset_collate')) {
            return;
        }
        self::maybe_upgrade();

        $revision = $revision === null
            ? (int) get_option('lisun_glossary_revision', 0)
            : (int) $revision;
        $table = $wpdb->prefix . 'lisun_glossary_terms';
        $now = current_time('mysql');
        $active_ids = [];

        foreach ((array) $entries as $entry) {
            $term_id = (string) ($entry['term_id'] ?? '');
            $zh = (string) ($entry['zh'] ?? '');
            $en = (string) ($entry['en'] ?? '');
            if ($term_id === '' || $zh === '' || $en === '') continue;
            $active_ids[] = $term_id;
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table}
                    (term_id, zh_text, en_text, note, revision, status, created_at, updated_at)
                 VALUES (%s, %s, %s, %s, %d, 'active', %s, %s)
                 ON DUPLICATE KEY UPDATE
                    zh_text = VALUES(zh_text), en_text = VALUES(en_text),
                    note = VALUES(note), revision = VALUES(revision),
                    status = 'active', updated_at = VALUES(updated_at)",
                $term_id, $zh, $en, (string) ($entry['note'] ?? ''),
                $revision, $now, $now
            ));
        }

        // Soft-delete only. Historical occurrences must remain materializable.
        if (empty($active_ids)) {
            $wpdb->query("UPDATE {$table} SET status = 'deleted', revision = " . (int) $revision . ", updated_at = '" . esc_sql($now) . "' WHERE status = 'active'");
        } else {
            $placeholders = implode(',', array_fill(0, count($active_ids), '%s'));
            $params = array_merge([$revision, $now], $active_ids);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'deleted', revision = %d, updated_at = %s
                 WHERE status = 'active' AND term_id NOT IN ({$placeholders})",
                $params
            ));
        }
    }
}
