<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * NumisTR Locations Helper
 *
 * Secure data access for the ancient-settlements `locations` table.
 * Replaces the insecure standalone data_collection/MAPS/get_points.php
 * (hardcoded creds + no auth). Uses the Joomla DBO (configured creds).
 *
 * Public identifier exposed by the API is `loc_id` (e.g. "LOC-0017").
 */
class NumisTRLocationsHelper
{
    /** @var array */
    private $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Lightweight pins for a map bounding box.
     * Returns: [ ['id'=>loc_id, 'lat'=>float, 'lng'=>float, 'name'=>string, 'has_coins'=>bool], ... ]
     *
     * @param float  $swLat  south-west latitude
     * @param float  $swLng  south-west longitude
     * @param float  $neLat  north-east latitude
     * @param float  $neLng  north-east longitude
     * @param string $lang   'tr' | 'en'
     * @param array  $opts   ['limit'=>int, 'only_coins'=>bool, 'region'=>string]
     */
    public function getByBbox(float $swLat, float $swLng, float $neLat, float $neLng, string $lang = 'tr', array $opts = []): array
    {
        $db = Factory::getDbo();

        $limit = (int)($opts['limit'] ?? 2000);
        if ($limit < 1)    { $limit = 1; }
        if ($limit > 5000) { $limit = 5000; }

        // Language-aware name with TR fallback
        $nameExpr = ($lang === 'en')
            ? 'COALESCE(NULLIF(' . $db->quoteName('l.name_en') . ", ''), " . $db->quoteName('l.name_tr') . ')'
            : $db->quoteName('l.name_tr');

        // Makale koprusu: 2026-09-08'de backfill edildi (loc_id -> article_id_tr/en).
        // alias ve catid DENORMALIZE EDILMEDI; burada canli okunuyor ki makale
        // yeniden adlandirilinca URL kendiliginden dogru kalsin.
        $artCol = $lang === 'en' ? 'l.article_id_en' : 'l.article_id_tr';

        $q = $db->getQuery(true)
            ->select($db->quoteName('l.loc_id'))
            ->select($db->quoteName('l.lat'))
            ->select($db->quoteName('l.lng'))
            ->select($nameExpr . ' AS ' . $db->quoteName('name'))
            ->select($db->quoteName('l.has_coins'))
            ->select($db->quoteName('c.id', 'article_id'))
            ->select($db->quoteName('c.alias', 'article_alias'))
            ->select($db->quoteName('c.catid', 'article_catid'))
            ->select($db->quoteName('cat.alias', 'cat_alias'))
            ->from($db->quoteName('locations', 'l'))
            ->join('LEFT', $db->quoteName('#__content', 'c')
                . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName($artCol)
                . ' AND ' . $db->quoteName('c.state') . ' = 1')
            ->join('LEFT', $db->quoteName('#__categories', 'cat')
                . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.catid'))
            ->where($db->quoteName('l.published') . ' = 1')
            ->where($db->quoteName('l.loc_id') . ' IS NOT NULL')
            ->where($db->quoteName('l.lat') . ' BETWEEN ' . (float)$swLat . ' AND ' . (float)$neLat)
            ->where($db->quoteName('l.lng') . ' BETWEEN ' . (float)$swLng . ' AND ' . (float)$neLng);

        if (!empty($opts['only_coins'])) {
            $q->where($db->quoteName('l.has_coins') . ' = 1');
        }
        if (!empty($opts['region'])) {
            $q->where($db->quoteName('l.region_code') . ' = ' . $db->quote((string)$opts['region']));
        }

        $q->setLimit($limit);
        $db->setQuery($q);
        $rows = $db->loadAssocList() ?: [];

        $base     = rtrim((string)($this->config['site_base'] ?? 'https://numistr.org'), '/');
        $fallback = $lang === 'en' ? 'ancient-settlements' : 'antik-yerlesimler';
        $menuMap  = $this->menuAliasMap($lang);

        $out = [];

        foreach ($rows as $r) {
            // Koordinatlar 6 haneye yuvarlanir (~11 cm). Yuvarlanmadan
            // "38.46667817000000155758243636228144168853759765625" gibi degerler
            // gidiyordu; olcum 2026-09-08: yukun %31'i sirf hassasiyet sismesiydi.
            $item = [
                'id'        => $r['loc_id'],
                'lat'       => round((float)$r['lat'], 6),
                'lng'       => round((float)$r['lng'], 6),
                'name'      => $r['name'],
                'has_coins' => (bool)(int)$r['has_coins'],
            ];

            $articleId = (int)($r['article_id'] ?? 0);

            if ($articleId > 0 && !empty($r['article_alias'])) {
                $catid = (int)($r['article_catid'] ?? 0);
                $menu  = $menuMap[$catid] ?? ((string)($r['cat_alias'] ?? '') !== '' ? (string)$r['cat_alias'] : $fallback);

                $item['url'] = $base . '/' . $lang . '/' . rawurlencode($menu)
                    . '/' . $articleId . '-' . rawurlencode((string)$r['article_alias']);
            }
            // Makale yoksa 'url' HIC eklenmez -- bos string ya da uydurma URL degil.

            $out[] = $item;
        }

        return $out;
    }

    /**
     * catid -> menu alias haritasi, TEK sorguda.
     *
     * Asistan tarafindaki menuAliasFor() her catid icin ayri sorgu atiyor; bir bbox
     * yaniti onlarca kategoriye yayilabildigi icin burada toplu cozuyoruz.
     * Menude karsiligi olmayan kategoriler icin cagiran taraf #__categories.alias'a,
     * o da yoksa dil varsayilanina duser.
     */
    private function menuAliasMap(string $lang): array
    {
        static $cache = [];

        if (isset($cache[$lang])) {
            return $cache[$lang];
        }

        $db  = Factory::getDbo();
        $map = [];

        try {
            $q = $db->getQuery(true)
                ->select([$db->quoteName('link'), $db->quoteName('alias'), $db->quoteName('language')])
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('link') . ' LIKE ' . $db->quote('%option=com_content&view=category%'))
                ->where($db->quoteName('language') . ' IN ('
                    . $db->quote($lang === 'en' ? 'en-GB' : 'tr-TR') . ', ' . $db->quote('*') . ')')
                ->order($db->quoteName('language') . ' DESC');   // dile ozgu kayit '*' onune gecsin

            $db->setQuery($q);

            foreach (($db->loadAssocList() ?: []) as $row) {
                if (preg_match('~[?&]id=(\d+)~', (string)$row['link'], $m)) {
                    $cid = (int)$m[1];

                    if (!isset($map[$cid]) && (string)$row['alias'] !== '') {
                        $map[$cid] = (string)$row['alias'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Menu okunamazsa harita yine calisir; cagiran taraf yedege duser.
            $map = [];
        }

        $cache[$lang] = $map;

        return $map;
    }

    /**
     * Full detail for one location by loc_id (e.g. "LOC-0017"), language-aware.
     * Returns null if not found / unpublished.
     */
    public function getDetail(string $locId, string $lang = 'tr'): ?array
    {
        $db = Factory::getDbo();

        $q = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('locations'))
            ->where($db->quoteName('loc_id') . ' = ' . $db->quote($locId))
            ->where($db->quoteName('published') . ' = 1')
            ->setLimit(1);

        $db->setQuery($q);
        $r = $db->loadAssoc();
        if (!$r) {
            return null;
        }

        $pick = static function ($en, $tr) use ($lang) {
            if ($lang === 'en') {
                return ($en !== null && $en !== '') ? $en : $tr;
            }
            return $tr;
        };

        return [
            'id'          => $r['loc_id'],
            'name'        => $pick($r['name_en'] ?? null, $r['name_tr'] ?? null),
            // summary_* alanlari hic doldurulmadi (2026-09-08 olcumu: hepsi NULL).
            // Gercek metin desc_* icinde -- Pleiades kokenli kisa aciklama. Uctan
            // hic yayinlanmiyordu, yani harita popup'i bos kalirdi. Yedege baglandi.
            'summary'     => $pick($r['summary_en'] ?? null, $r['summary_tr'] ?? null)
                             ?: $pick($r['desc_en'] ?? null, $r['desc_tr'] ?? null),
            'content'     => $pick($r['content_en'] ?? null, $r['content_tr'] ?? null),
            // 6 hane ~11 cm; yuvarlanmadan tam float hassasiyeti gidiyordu
            'lat'         => isset($r['lat']) ? round((float)$r['lat'], 6) : null,
            'lng'         => isset($r['lng']) ? round((float)$r['lng'], 6) : null,
            'region_code' => $r['region_code'] ?? null,
            'has_coins'   => (bool)(int)($r['has_coins'] ?? 0),
            'coin_count'  => (int)($r['coin_count'] ?? 0),
            'lang'        => $lang,
        ];
    }

    /**
     * Upsert one location row by loc_id. Only provided keys are written
     * (partial updates allowed, e.g. EN-only enrichment pass).
     * Returns 'inserted' | 'updated'.
     *
     * @param array $row keys: loc_id (required), name_tr, name_en, region_code,
     *                   has_coins, coin_count, lat, lng, summary_tr, summary_en,
     *                   content_tr, content_en, published
     */
    public function upsert(array $row): string
    {
        $db = Factory::getDbo();

        $locId = trim((string)($row['loc_id'] ?? ''));
        if ($locId === '') {
            throw new \InvalidArgumentException('loc_id required');
        }

        // Whitelist writable columns
        $allowed = [
            'name_tr', 'name_en', 'region_code', 'has_coins', 'coin_count',
            'lat', 'lng', 'summary_tr', 'summary_en', 'content_tr', 'content_en', 'published',
        ];

        // Does it exist?
        $q = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('locations'))
            ->where($db->quoteName('loc_id') . ' = ' . $db->quote($locId))
            ->setLimit(1);
        $db->setQuery($q);
        $existingId = (int)$db->loadResult();

        $now = Factory::getDate()->toSql();

        if ($existingId > 0) {
            $upd = $db->getQuery(true)->update($db->quoteName('locations'));
            $set = false;
            foreach ($allowed as $col) {
                if (array_key_exists($col, $row)) {
                    $upd->set($db->quoteName($col) . ' = ' . $db->quote((string)$row[$col]));
                    $set = true;
                }
            }
            $upd->set($db->quoteName('updated_at') . ' = ' . $db->quote($now));
            $upd->where($db->quoteName('loc_id') . ' = ' . $db->quote($locId));
            if ($set) {
                $db->setQuery($upd);
                $db->execute();
            }
            return 'updated';
        }

        $cols = [$db->quoteName('loc_id'), $db->quoteName('updated_at')];
        $vals = [$db->quote($locId), $db->quote($now)];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $row)) {
                $cols[] = $db->quoteName($col);
                $vals[] = $db->quote((string)$row[$col]);
            }
        }
        $ins = $db->getQuery(true)
            ->insert($db->quoteName('locations'))
            ->columns($cols)
            ->values(implode(',', $vals));
        $db->setQuery($ins);
        $db->execute();
        return 'inserted';
    }
}
