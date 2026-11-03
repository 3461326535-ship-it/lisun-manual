<?php
/**
 * 只读检测：找出被「全库术语误应用」污染的文章。
 *
 * 原理：V2 翻译时每个被术语表命中的出现点都会写入 lisun_term_occurrences
 * （source_text / translated_text / revision_id）。旧代码拿全库术语去匹配，
 * 导致「本不该用于本篇」的术语也留下了 occurrence 行。本脚本把每篇当前
 * 生效 revision 的 occurrence 与 terms_for_post() 的正确集合做差集，剩下的
 * 就是误应用。
 *
 * 注意：当前库里 term_occurrences.term_id 列全部为 "0"（历史遗留），所以
 * 这里按 source_text（中文原文）回查术语，而不是按 term_id。回查不到的行
 * （术语已被删除）不计入误应用统计，仅单独提示。
 *
 * 运行（只读，不写任何数据）：
 *   wp eval-file wp-content/plugins/lisun-manual/find-misapplied-terms.php
 *
 * 可选：把底部 WANT_FREETEXT 改为 true，额外对「无 V2 记录」的旧文章做英文
 * 正文关键词扫描（较慢、有误报，仅作兜底）。
 */

if (!defined('ABSPATH')) {
    exit;
}

const WANT_FREETEXT = false;

function lisun_norm_text($s) {
    return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
}

function lisun_find_misapplied_terms() {
    global $wpdb;

    if (!class_exists('Lisun_Manual_Glossary') || !class_exists('Lisun_Manual_V2_Repository')) {
        echo "插件未加载，终止。\n";
        return;
    }
    if (!Lisun_Manual_Glossary::is_enabled()) {
        echo "术语表功能当前已关闭，terms_for_post 会返回空集，无法判定；请先开启再跑。\n";
        return;
    }

    $by_id = Lisun_Manual_Glossary::get_by_id();
    $by_zh = [];
    foreach ($by_id as $tid => $term) {
        $zh = lisun_norm_text((string) ($term['zh'] ?? ''));
        if ($zh !== '') $by_zh[$zh] = $term;
    }
    $occ_table = $wpdb->prefix . 'lisun_term_occurrences';

    $source_ids = get_posts([
        'post_type'      => 'manual',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);
    $source_ids = array_values(array_filter($source_ids, function ($id) {
        return get_post_meta($id, '_lisun_manual_lang', true) !== 'en';
    }));

    echo "=== 术语误应用检测（只读，按 source_text 回查术语） ===\n";
    echo "共扫描中文源文章：" . count($source_ids) . " 篇\n\n";

    $affected = 0;
    $total_hits = 0;

    foreach ($source_ids as $pid) {
        $pid = (int) $pid;
        $snapshot = Lisun_Manual_V2_Repository::get_active_snapshot($pid);
        if (!$snapshot || empty($snapshot['revision']['id'])) {
            continue;
        }
        $revision_id = (int) $snapshot['revision']['id'];

        $correct = [];
        foreach (Lisun_Manual_Glossary::terms_for_post($pid) as $t) {
            $tid = (string) ($t['term_id'] ?? '');
            if ($tid !== '') $correct[$tid] = true;
        }

        $applied = $wpdb->get_results($wpdb->prepare(
            "SELECT source_text,
                    COUNT(*) AS c,
                    GROUP_CONCAT(translated_text SEPARATOR ' ‖ ') AS en_samples
             FROM {$occ_table}
             WHERE revision_id = %d AND status = 'active'
             GROUP BY source_text",
            $revision_id
        ), ARRAY_A);
        $applied = is_array($applied) ? $applied : [];

        $wrong = [];
        foreach ($applied as $row) {
            $term = $by_zh[lisun_norm_text((string) $row['source_text'])] ?? null;
            if (!$term) continue; // 术语已删除，无法判定适用范围，跳过
            $tid = (string) $term['term_id'];
            if (!isset($correct[$tid])) {
                $wrong[] = [
                    'tid'      => $tid,
                    'zh'       => (string) ($term['zh'] ?? $row['source_text']),
                    'en_gl'    => (string) ($term['en'] ?? ''),
                    'en_samples' => (string) $row['en_samples'],
                    'c'        => (int) $row['c'],
                ];
            }
        }
        if (empty($wrong)) continue;

        $affected++;
        $title = get_the_title($pid);
        echo sprintf(
            "[#%d] %s  (正确术语 %d 条 / 误应用 %d 种)\n",
            $pid,
            $title ?: '(无标题)',
            count($correct),
            count($wrong)
        );
        foreach ($wrong as $row) {
            $total_hits += $row['c'];
            echo sprintf(
                "    - %s  %s → %s  [出现 %d 次]\n",
                $row['tid'],
                $row['zh'],
                $row['en_gl'] !== '' ? $row['en_gl'] : '(已删除)',
                $row['c']
            );
            echo "        实际渲染英文: " . $row['en_samples'] . "\n";
        }
        echo "\n";
    }

    echo sprintf("受影响文章 %d 篇，误应用出现点合计 %d 处。\n", $affected, $total_hits);
    if ($affected === 0) {
        echo "未发现 V2 误应用：当前生效译文与各篇正确术语集合一致。\n";
    }

    if (WANT_FREETEXT) {
        lisun_find_misapplied_terms_freetext($source_ids, $by_id);
    }
}

/** 兜底：扫描「无 V2 记录」的旧文章英文正文（近似，可能有误报）。 */
function lisun_find_misapplied_terms_freetext($source_ids, $by_id) {
    echo "\n=== 兜底扫描（无 V2 记录的旧文章，英文正文关键词匹配，近似结果） ===\n";

    $checked = 0;
    foreach ($source_ids as $pid) {
        $pid = (int) $pid;
        $snapshot = Lisun_Manual_V2_Repository::get_active_snapshot($pid);
        if ($snapshot) continue;

        $en_id = (int) get_post_meta($pid, '_lisun_manual_target_id', true);
        $en_post = $en_id ? get_post($en_id) : null;
        if (!$en_post) {
            $found = get_posts([
                'post_type'      => 'manual',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'meta_query'     => [
                    ['key' => '_lisun_manual_source_id', 'value' => $pid],
                    ['key' => '_lisun_manual_lang', 'value' => 'en'],
                ],
            ]);
            $en_post = empty($found) ? null : $found[0];
        }
        if (!$en_post || empty($en_post->post_content)) continue;

        $en_text = wp_strip_all_tags((string) $en_post->post_content);
        $en_text = html_entity_decode($en_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $correct = [];
        foreach (Lisun_Manual_Glossary::terms_for_post($pid) as $t) {
            $tid = (string) ($t['term_id'] ?? '');
            if ($tid !== '') $correct[$tid] = true;
        }

        $hits = [];
        foreach ($by_id as $tid => $term) {
            if (isset($correct[$tid])) continue;
            $en = (string) ($term['en'] ?? '');
            if ($en === '' || mb_strlen($en) < 2) continue;
            $pattern = '/(?<![A-Za-z0-9])' . preg_quote($en, '/') . '(?![A-Za-z0-9])/i';
            $n = preg_match_all($pattern, $en_text, $m);
            if ($n > 0) {
                $hits[] = sprintf('%s (%s → %s) ×%d', $tid, (string) ($term['zh'] ?? ''), $en, $n);
            }
        }
        if (empty($hits)) continue;

        $checked++;
        echo sprintf("[#%d] %s\n", $pid, get_the_title($pid) ?: '(无标题)');
        foreach ($hits as $h) {
            echo "    - 疑似误应用: {$h}\n";
        }
        echo "\n";
    }

    echo sprintf("兜底扫描到 %d 篇旧文章存在疑似误应用（请人工复核，可能误报）。\n", $checked);
}

lisun_find_misapplied_terms();
