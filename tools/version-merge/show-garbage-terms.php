<?php
/**
 * 只读：列出「已删除垃圾术语」的真实错译例子（第二类）。
 * 只统计每篇当前【生效 revision】的 occurrence（superseded 旧版本一律不计）。
 * 判定：source_text 回查不到当前术语表，且英文是明显垃圾（长句片段/错译）。
 */

define('SHORTINIT', false);
@ini_set('memory_limit', '1024M');
require '/www/wwwroot/demo.lisungroup.cn/wp-load.php';

global $wpdb;

function lisun_norm($s) {
    return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
}

$occ_table = $wpdb->prefix . 'lisun_term_occurrences';
$units_table = $wpdb->prefix . 'lisun_translation_units';
$rev_table = $wpdb->prefix . 'lisun_translation_revisions';
$doc_table = $wpdb->prefix . 'lisun_translation_documents';

// 当前术语表 zh 集合
$by_zh = [];
foreach (Lisun_Manual_Glossary::get_all() as $term) {
    $zh = lisun_norm((string) ($term['zh'] ?? ''));
    if ($zh !== '') $by_zh[$zh] = (string) ($term['term_id'] ?? '');
}

// 每篇文档的当前生效 revision
$active_revs = $wpdb->get_results(
    "SELECT d.id AS doc_id, d.source_post_id, d.active_revision_id
     FROM {$doc_table} d WHERE d.active_revision_id > 0",
    ARRAY_A
);
$active_by_doc = [];
foreach ((array) $active_revs as $d) {
    $active_by_doc[(int) $d['id']] = (int) $d['active_revision_id'];
}
$active_ids = array_values(array_unique($active_by_doc));
$pid_by_doc = [];
foreach ((array) $active_revs as $d) $pid_by_doc[(int) $d['id']] = (int) $d['source_post_id'];

if (empty($active_ids)) { echo "无生效 revision。\n"; exit; }

$rows = $wpdb->get_results(
    "SELECT o.source_text, o.translated_text, o.revision_id, r.document_id
     FROM {$occ_table} o INNER JOIN {$rev_table} r ON r.id = o.revision_id
     WHERE o.status = 'active' AND o.revision_id IN (" . implode(',', $active_ids) . ")",
    ARRAY_A
);
$rows = is_array($rows) ? $rows : [];

$deleted = [];
foreach ($rows as $r) {
    // 只认生效 revision
    $did = (int) $r['document_id'];
    if ((int) $r['revision_id'] !== ($active_by_doc[$did] ?? 0)) continue;

    $zh = lisun_norm((string) $r['source_text']);
    $en = lisun_norm((string) $r['translated_text']);
    if ($zh === '' || $en === '') continue;
    if (isset($by_zh[$zh])) continue; // 还在库

    $words = preg_match_all('/[A-Za-z][A-Za-z\x{2019}\'-]*/u', $en, $m);
    $is_fragment = $words >= 4 || preg_match('/[▶→\n\r]|how to|instructions|the .* is|must|should|cannot|please|ensure/i', $en);
    if (!$is_fragment) continue;

    if (!isset($deleted[$zh])) $deleted[$zh] = ['count' => 0, 'samples' => [], 'posts' => []];
    $deleted[$zh]['count']++;
    if (count($deleted[$zh]['samples']) < 5) $deleted[$zh]['samples'][$en] = true;
    $pid = $pid_by_doc[$did] ?? 0;
    if ($pid > 0) $deleted[$zh]['posts'][$pid] = true;
}

uasort($deleted, function ($a, $b) { return $b['count'] <=> $a['count']; });

echo "=== 第二类（仅统计每篇当前生效 revision）：已删除术语中英文明显异常 ===\n";
echo "共 " . count($deleted) . " 种\n\n";
foreach ($deleted as $zh => $info) {
    $pids = array_keys($info['posts']);
    sort($pids, SORT_NUMERIC);
    echo sprintf("「%s」 出现 %d 次，涉及 %d 篇\n", $zh, $info['count'], count($pids));
    foreach (array_keys($info['samples']) as $s) echo "    英文: " . $s . "\n";
    echo "    文章: #" . implode(', #', $pids) . "\n\n";
}
