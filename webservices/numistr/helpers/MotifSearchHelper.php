<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2026 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

/**
 * Katalog betimlerinde motif araması (ön/arka yüz betimi, TR ya da EN).
 *
 * Neden: /v1/variants yalnız yapısal filtre (bölge/darphane/metal/otorite/yıl) sunar. "Başak",
 * "homonoia" gibi MOTİF konuları ancak betim metninde aranarak bulunur. 2026-09-24'e kadar bu
 * phpMyAdmin'den elle yapılıyordu; /KB araştırma notu katalog desteği ölçülmeden yazılıyor,
 * 3 notun 2'si katalogda karşılıksız çıkıyordu.
 *
 * Eşleme SÖZCÜK BAŞINA bağlıdır (önünde harf olmayacak), sonu serbesttir (Türkçe ekler: başak →
 * başağı, başaklar). Böylece ölçülmüş tuzaklar düşer: "arpa" ↛ "harpa", "gül" ↛ "örgülü",
 * "arı" ↛ "noktaların"/"yukarıda". SQL LIKE yalnız ön süzmedir; kesin karar matches()'tedir.
 *
 * @since 1.15.0
 */
class NumisTRMotifSearch
{
    public const MAX_TERMS = 8;
    public const MIN_TERM_LEN = 2;
    public const MAX_CANDIDATES = 5000;

    /** Türkçe duyarlı küçük harf (mb_strtolower 'I'yı 'i', 'İ'yı 'i̇' yapar). */
    public static function trLower(string $s): string
    {
        return mb_strtolower(strtr($s, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    /** "başak, buğday,,x" → ['başak','buğday'] (tekil, küçük harf, en az 2 harf, en çok 8). */
    public static function parseTerms(string $q): array
    {
        $out = [];
        foreach (explode(',', $q) as $t) {
            $t = self::trLower(trim($t));
            if (mb_strlen($t, 'UTF-8') >= self::MIN_TERM_LEN && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return array_slice($out, 0, self::MAX_TERMS);
    }

    /** Metinde terimlerden biri SÖZCÜK BAŞINDA geçiyor mu? */
    public static function matches(string $text, array $terms): bool
    {
        $text = self::trLower($text);
        foreach ($terms as $t) {
            if (preg_match('/(?<!\p{L})' . preg_quote($t, '/') . '/u', $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Satırları (id, mint, start, end, obv, rev) süzer ve toplar. Veritabanından bağımsız (test edilir).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function aggregate(array $rows, array $terms, int $mintLimit = 50, int $sampleLimit = 30): array
    {
        $mints = [];
        $ids = [];
        $early = null;
        $late = null;
        foreach ($rows as $r) {
            if (!self::matches((string) ($r['obv'] ?? '') . ' ' . (string) ($r['rev'] ?? ''), $terms)) {
                continue;
            }
            $ids[] = (int) $r['id'];
            $m = trim((string) ($r['mint'] ?? '')) ?: '(yok)';
            $mints[$m] = ($mints[$m] ?? 0) + 1;
            if (is_numeric($r['start'] ?? null)) {
                $early = $early === null ? (int) $r['start'] : min($early, (int) $r['start']);
            }
            if (is_numeric($r['end'] ?? null)) {
                $late = $late === null ? (int) $r['end'] : max($late, (int) $r['end']);
            }
        }
        arsort($mints);
        sort($ids);
        $list = [];
        foreach (array_slice($mints, 0, $mintLimit, true) as $m => $n) {
            $list[] = ['mint' => (string) $m, 'count' => $n];
        }
        return [
            'total' => count($ids),
            'mint_count' => count($mints),
            'mints' => $list,
            'years' => ['from' => $early, 'to' => $late],
            'sample_ids' => array_slice($ids, 0, $sampleLimit),
        ];
    }

    /**
     * Veritabanından aday satırları çeker (LIKE ön süzme) ve aggregate() ile toplar.
     *
     * @param  object  $db  Joomla DatabaseDriver
     */
    public static function search($db, array $terms, array $fieldIds): array
    {
        $fv = $db->quoteName('#__fields_values');
        $descIds = implode(',', array_map('intval', [$fieldIds['obv'], $fieldIds['rev']]));
        $likes = [];
        foreach ($terms as $t) {
            $likes[] = 'LOWER(v.value) LIKE ' . $db->quote('%' . $db->escape($t, true) . '%', false);
        }

        // 1) Aday makaleler: yayımda, TR kaydı (katalog TR+EN çift tutar, TR = language '*')
        $db->setQuery(
            'SELECT DISTINCT c.id FROM ' . $db->quoteName('#__content') . ' c'
            . ' JOIN ' . $fv . ' v ON v.item_id = CAST(c.id AS CHAR) COLLATE utf8mb4_unicode_ci'
            . ' WHERE c.state = 1 AND c.language = ' . $db->quote('*')
            . ' AND v.field_id IN (' . $descIds . ') AND (' . implode(' OR ', $likes) . ')'
            . ' LIMIT ' . (self::MAX_CANDIDATES + 1)
        );
        $cand = array_map('intval', $db->loadColumn() ?: []);
        $truncated = count($cand) > self::MAX_CANDIDATES;
        $cand = array_slice($cand, 0, self::MAX_CANDIDATES);
        if (!$cand) {
            return self::aggregate([], $terms) + ['candidates' => 0, 'truncated' => false];
        }

        // 2) Adayların darphane, tarih ve betim alanları
        $f = array_map('intval', $fieldIds);
        $db->setQuery(
            'SELECT v.item_id AS id,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['mint'] . ' THEN v.value END) AS mint,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['start'] . ' THEN v.value END) AS start,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['end'] . ' THEN v.value END) AS end,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['obv'] . ' THEN v.value END) AS obv,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['rev'] . ' THEN v.value END) AS rev'
            . ' FROM ' . $fv . ' v'
            . ' WHERE v.field_id IN (' . implode(',', $f) . ')'
            . ' AND v.item_id IN (' . implode(',', array_map(fn ($i) => $db->quote((string) $i), $cand)) . ')'
            . ' GROUP BY v.item_id'
        );
        $rows = $db->loadAssocList() ?: [];

        return self::aggregate($rows, $terms) + ['candidates' => count($cand), 'truncated' => $truncated];
    }
}
