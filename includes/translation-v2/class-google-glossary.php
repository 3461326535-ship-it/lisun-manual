<?php
/**
 * Google glossary lifecycle for Translation V2.
 *
 * Cloud Translation glossaries can only be created from a CSV/TSV object in
 * Cloud Storage ("Empty input_uri"), so the plugin builds the CSV in memory,
 * uploads it with the service account and creates the glossary from that URI.
 *
 * Verified against the live API:
 *  - headerless CSV, one "source,target" pair per line  -> entryCount matches
 *  - glossaries.glossaryEntries supports create / patch / delete per entry,
 *    so a glossary that already exists is updated in place instead of rebuilt
 *  - creating a glossary is a long-running operation (~6s) that must be polled
 *
 * Update policy: at most INCREMENTAL_LIMIT entry changes are applied one by one;
 * a bigger change set (or a first run) rebuilds the glossary from a new CSV and
 * retires the previous one.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lisun_Manual_V2_Google_Glossary {

    const OPT_NAME    = 'lisun_google_glossary_name';
    const OPT_HASH    = 'lisun_google_glossary_hash';
    const OPT_OBJECT  = 'lisun_google_glossary_object';
    const OPT_ENTRIES = 'lisun_google_glossary_entries';
    const OPT_COUNT   = 'lisun_google_glossary_count';

    const INCREMENTAL_LIMIT = 30;
    const POLL_ATTEMPTS     = 20;
    const POLL_DELAY        = 3;

    private static $resolved = null;

    /** Google v3 + glossary mode? (the API-key v2 mode has no glossary) */
    public static function enabled() {
        return (string) get_option('lisun_google_mode', 'v3_glossary') === 'v3_glossary';
    }

    /**
     * Glossary resource name to attach to translateText, creating/syncing it when
     * the local glossary changed.
     *
     * @param array $terms Lisun_Manual_Glossary::get_all() rows (zh/en/term_id).
     * @return string|WP_Error Empty string when glossaries are disabled.
     */
    public static function ensure($terms) {
        if (!self::enabled()) return '';
        if (self::$resolved !== null) return self::$resolved;

        $pairs = self::normalize($terms);
        if (empty($pairs)) {
            self::$resolved = '';
            return '';
        }

        $hash = self::fingerprint($pairs);
        $name = (string) get_option(self::OPT_NAME, '');
        $current_hash = (string) get_option(self::OPT_HASH, '');

        if ($name !== '' && $current_hash === $hash) {
            self::$resolved = $name;
            return $name;
        }

        if ($name !== '' && $current_hash !== '') {
            $result = self::incremental_sync($name, $pairs, $hash);
            if (!is_wp_error($result)) {
                self::$resolved = $name;
                return $name;
            }
            self::log('Google glossary incremental refused: ' . $result->get_error_message() . ' -> rebuild');
        }

        $rebuilt = self::rebuild($pairs, $hash);
        if (is_wp_error($rebuilt)) {
            self::$resolved = $rebuilt;
            return $rebuilt;
        }
        self::$resolved = $rebuilt;
        return $rebuilt;
    }

    public static function status() {
        return [
            'mode'    => (string) get_option('lisun_google_mode', 'v3_glossary'),
            'name'    => (string) get_option(self::OPT_NAME, ''),
            'hash'    => (string) get_option(self::OPT_HASH, ''),
            'count'   => (int) get_option(self::OPT_COUNT, 0),
            'object'  => (string) get_option(self::OPT_OBJECT, ''),
            'project' => Lisun_Manual_Google_Auth::project(),
            'location' => Lisun_Manual_Google_Auth::location(),
            'bucket'  => Lisun_Manual_Google_Auth::bucket(),
        ];
    }

    public static function reset_cache() {
        self::$resolved = null;
    }

    // ------------------------------------------------------------------
    // data shaping
    // ------------------------------------------------------------------

    /** @return array ['zh' => ['en' => string, 'term_id' => string]] sorted by zh. */
    private static function normalize($terms) {
        $pairs = [];
        foreach ((array) $terms as $term) {
            if (!is_array($term)) continue;
            $zh = trim((string) ($term['zh'] ?? ''));
            $en = trim((string) ($term['en'] ?? ''));
            if ($zh === '' || $en === '') continue;
            if (isset($pairs[$zh])) continue;
            $pairs[$zh] = ['en' => $en, 'term_id' => (string) ($term['term_id'] ?? '')];
        }
        ksort($pairs, SORT_STRING);
        return $pairs;
    }

    private static function fingerprint($pairs) {
        $lines = [];
        foreach ($pairs as $zh => $row) $lines[] = $zh . "\t" . $row['en'];
        return hash('sha256', implode("\n", $lines));
    }

    /** Headerless CSV: one "source,target" pair per row (Google requires this). */
    private static function build_csv($pairs) {
        $rows = [];
        foreach ($pairs as $zh => $row) {
            $rows[] = self::csv_field($zh) . ',' . self::csv_field($row['en']);
        }
        return implode("\n", $rows) . "\n";
    }

    private static function csv_field($value) {
        $value = str_replace(["\r\n", "\r"], ' ', (string) $value);
        if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    // ------------------------------------------------------------------
    // rebuild: CSV -> GCS -> new glossary
    // ------------------------------------------------------------------

    private static function rebuild($pairs, $hash) {
        $object = Lisun_Manual_Google_Auth::object_prefix() . '/glossary-' . substr($hash, 0, 12) . '.csv';
        $bucket = Lisun_Manual_Google_Auth::bucket();
        $uri    = 'gs://' . $bucket . '/' . $object;

        $uploaded = self::upload_csv($pairs, $bucket, $object);
        if (is_wp_error($uploaded)) return $uploaded;

        $created = self::create_glossary($uri, $hash);
        if (is_wp_error($created)) return $created;

        $previous_name = (string) get_option(self::OPT_NAME, '');
        $previous_object = (string) get_option(self::OPT_OBJECT, '');

        update_option(self::OPT_NAME, $created, false);
        update_option(self::OPT_HASH, $hash, false);
        update_option(self::OPT_OBJECT, $object, false);
        update_option(self::OPT_COUNT, count($pairs), false);
        update_option(self::OPT_ENTRIES, [], false); // filled lazily by the next incremental run

        self::log('Google glossary rebuilt name=' . $created . ' entries=' . count($pairs)
            . ' hash=' . substr($hash, 0, 12) . ' object=' . $object);

        if ($previous_name !== '' && $previous_name !== $created) {
            self::retire($previous_name, $previous_object);
        }

        return $created;
    }

    private static function upload_csv($pairs, $bucket, $object) {
        $csv = self::build_csv($pairs);
        $url = 'https://storage.googleapis.com/upload/storage/v1/b/' . rawurlencode($bucket)
            . '/o?uploadType=media&name=' . rawurlencode($object);
        $result = Lisun_Manual_Google_Auth::upload($url, $csv, 'text/csv');
        if (is_wp_error($result)) return $result;
        return true;
    }

    private static function create_glossary($gs_uri, $hash) {
        $parent = 'projects/' . Lisun_Manual_Google_Auth::project()
            . '/locations/' . Lisun_Manual_Google_Auth::location();
        $glossary_id = 'lisun-manual-' . substr($hash, 0, 12);
        // Resource name (projects/.../glossaries/...), NOT a URL: the API rejects
        // anything else with "Invalid resource name. Should start with projects/.".
        $resource = $parent . '/glossaries/' . $glossary_id;

        $body = [
            'name'          => $resource,
            'languagePair'  => [
                'sourceLanguageCode' => 'zh-CN',
                'targetLanguageCode' => 'en',
            ],
            'inputConfig'   => ['gcsSource' => ['inputUri' => $gs_uri]],
            'displayName'   => 'LISUN Manual Glossary ' . substr($hash, 0, 8),
        ];

        $response = Lisun_Manual_Google_Auth::api(
            Lisun_Manual_Google_Auth::v3_base() . '/glossaries',
            'POST',
            $body,
            60
        );
        if (is_wp_error($response)) {
            // Same content already built earlier: reuse it instead of failing.
            if ((int) (($response->get_error_data()['http_code'] ?? 0)) === 409) {
                $existing = Lisun_Manual_Google_Auth::api(
                    Lisun_Manual_Google_Auth::v3_base() . '/glossaries/' . $glossary_id,
                    'GET', null, 30
                );
                if (!is_wp_error($existing)) return $resource;
            }
            return $response;
        }

        $operation = (string) ($response['data']['name'] ?? '');
        if ($operation === '') {
            return new WP_Error('google_glossary_no_operation', 'Google 未返回术语表创建任务名');
        }

        $done = self::poll_operation($operation);
        if (is_wp_error($done)) return $done;

        $resolved = (string) ($done['response']['name'] ?? '');
        if ($resolved !== '') $resource = $resolved;
        return $resource;
    }

    /** Poll a long-running operation until done (or error). */
    private static function poll_operation($operation) {
        $url = 'https://translation.googleapis.com/v3/' . ltrim($operation, '/');
        for ($i = 0; $i < self::POLL_ATTEMPTS; $i++) {
            sleep(self::POLL_DELAY);
            $result = Lisun_Manual_Google_Auth::api($url, 'GET', null, 30);
            if (is_wp_error($result)) return $result;
            $data = (array) $result['data'];
            if (empty($data['done'])) continue;
            if (!empty($data['error'])) {
                $message = $data['error']['message'] ?? '术语表创建失败';
                return new WP_Error('google_glossary_failed', 'Google 术语表创建失败：' . $message);
            }
            return (array) ($data['response'] ?? []);
        }
        return new WP_Error('google_glossary_timeout', 'Google 术语表创建超时（' . (self::POLL_ATTEMPTS * self::POLL_DELAY) . ' 秒）');
    }

    // ------------------------------------------------------------------
    // incremental: per-entry create / patch / delete on the live glossary
    // ------------------------------------------------------------------

    private static function incremental_sync($glossary, $pairs, $hash) {
        $existing = self::existing_entries($glossary);
        if (is_wp_error($existing)) return $existing;

        $to_create = [];
        $to_update = [];
        foreach ($pairs as $zh => $row) {
            if (!isset($existing[$zh])) { $to_create[$zh] = $row; continue; }
            if ((string) $existing[$zh]['en'] !== $row['en']) $to_update[$zh] = $row;
        }
        $to_delete = [];
        foreach ($existing as $zh => $row) {
            if (!isset($pairs[$zh])) $to_delete[$zh] = $row;
        }

        $changes = count($to_create) + count($to_update) + count($to_delete);
        if ($changes === 0) {
            update_option(self::OPT_HASH, $hash, false);
            update_option(self::OPT_COUNT, count($pairs), false);
            return true;
        }
        if ($changes > self::INCREMENTAL_LIMIT) {
            return new WP_Error('google_glossary_too_many_changes',
                '术语表变更 ' . $changes . ' 条，超过增量阈值 ' . self::INCREMENTAL_LIMIT . ' 条');
        }

        $glossary_url = Lisun_Manual_Google_Auth::v3_base() . '/glossaries/'
            . basename(str_replace('\\', '/', $glossary));

        foreach ($to_delete as $zh => $row) {
            if (empty($row['entry'])) continue;
            $deleted = Lisun_Manual_Google_Auth::api(
                'https://translation.googleapis.com/v3/' . ltrim((string) $row['entry'], '/'),
                'DELETE', null, 30
            );
            if (is_wp_error($deleted)) return $deleted;
        }

        foreach ($to_create as $zh => $row) {
            $created = Lisun_Manual_Google_Auth::api($glossary_url . '/glossaryEntries', 'POST', [
                'termsPair' => [
                    'sourceTerm' => ['languageCode' => 'zh-CN', 'text' => $zh],
                    'targetTerm' => ['languageCode' => 'en', 'text' => (string) $row['en']],
                ],
            ], 30);
            if (is_wp_error($created)) return $created;
        }

        foreach ($to_update as $zh => $row) {
            if (empty($existing[$zh]['entry'])) {
                // no entry name cached: fall back to create
                $created = Lisun_Manual_Google_Auth::api($glossary_url . '/glossaryEntries', 'POST', [
                    'termsPair' => [
                        'sourceTerm' => ['languageCode' => 'zh-CN', 'text' => $zh],
                        'targetTerm' => ['languageCode' => 'en', 'text' => (string) $row['en']],
                    ],
                ], 30);
                if (is_wp_error($created)) return $created;
                continue;
            }
            $patched = Lisun_Manual_Google_Auth::api(
                'https://translation.googleapis.com/v3/' . ltrim((string) $existing[$zh]['entry'], '/'),
                'PATCH',
                [
                    'termsPair' => [
                        'sourceTerm' => ['languageCode' => 'zh-CN', 'text' => $zh],
                        'targetTerm' => ['languageCode' => 'en', 'text' => (string) $row['en']],
                    ],
                ],
                30
            );
            if (is_wp_error($patched)) return $patched;
        }

        // Refresh the cached map once, so the next run has accurate entry names.
        $refreshed = self::fetch_entries($glossary);
        if (!is_wp_error($refreshed)) update_option(self::OPT_ENTRIES, $refreshed, false);

        update_option(self::OPT_HASH, $hash, false);
        update_option(self::OPT_COUNT, count($pairs), false);
        self::log('Google glossary incremental applied create=' . count($to_create)
            . ' patch=' . count($to_update) . ' delete=' . count($to_delete)
            . ' glossary=' . basename(str_replace('\\', '/', $glossary)));
        return true;
    }

    private static function existing_entries($glossary) {
        $cached = get_option(self::OPT_ENTRIES, []);
        if (is_array($cached) && !empty($cached)) return $cached;
        $fetched = self::fetch_entries($glossary);
        if (is_wp_error($fetched)) return $fetched;
        update_option(self::OPT_ENTRIES, $fetched, false);
        return $fetched;
    }

    /** @return array|WP_Error ['zh' => ['en' => string, 'entry' => resource name]] */
    private static function fetch_entries($glossary) {
        $base = Lisun_Manual_Google_Auth::v3_base() . '/glossaries/'
            . basename(str_replace('\\', '/', $glossary)) . '/glossaryEntries';
        $map = [];
        $page_token = '';
        for ($page = 0; $page < 20; $page++) {
            $url = $base . '?pageSize=1000';
            if ($page_token !== '') $url .= '&pageToken=' . rawurlencode($page_token);
            $result = Lisun_Manual_Google_Auth::api($url, 'GET', null, 60);
            if (is_wp_error($result)) return $result;
            foreach ((array) ($result['data']['glossaryEntries'] ?? []) as $entry) {
                $pair = (array) ($entry['termsPair'] ?? []);
                $source = (string) ($pair['sourceTerm']['text'] ?? '');
                $target = (string) ($pair['targetTerm']['text'] ?? '');
                if ($source === '') continue;
                $map[$source] = ['en' => $target, 'entry' => (string) ($entry['name'] ?? '')];
            }
            $page_token = (string) ($result['data']['nextPageToken'] ?? '');
            if ($page_token === '') break;
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // retirement of a previous glossary
    // ------------------------------------------------------------------

    private static function retire($name, $object) {
        $name = trim((string) $name);
        if ($name !== '') {
            $short = substr($name, strrpos($name, '/') + 1);
            $deleted = Lisun_Manual_Google_Auth::api(
                Lisun_Manual_Google_Auth::v3_base() . '/glossaries/' . $short,
                'DELETE', null, 30
            );
            if (is_wp_error($deleted)) {
                self::log('Google glossary retire failed name=' . $short . ' reason=' . $deleted->get_error_message());
            } else {
                self::log('Google glossary retired name=' . $short);
            }
        }
        $object = trim((string) $object);
        if ($object !== '') {
            $bucket = Lisun_Manual_Google_Auth::bucket();
            $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($bucket)
                . '/o/' . rawurlencode($object);
            // Fire and forget: a stale CSV object is harmless.
            Lisun_Manual_Google_Auth::api($url, 'DELETE', null, 20);
        }
    }

    private static function log($message) {
        if (class_exists('Lisun_Manual_Translate') && method_exists('Lisun_Manual_Translate', 'diagnostic_log')) {
            Lisun_Manual_Translate::diagnostic_log((string) $message);
        }
    }
}
