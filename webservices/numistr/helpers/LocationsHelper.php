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
            ? 'COALESCE(NULLIF(' . $db->quoteName('name_en') . ", ''), " . $db->quoteName('name_tr') . ')'
            : $db->quoteName('name_tr');

        $q = $db->getQuery(true)
            ->select($db->quoteName('loc_id'))
            ->select($db->quoteName('lat'))
            ->select($db->quoteName('lng'))
            ->select($nameExpr . ' AS ' . $db->quoteName('name'))
            ->select($db->quoteName('has_coins'))
            ->from($db->quoteName('locations'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('loc_id') . ' IS NOT NULL')
            ->where($db->quoteName('lat') . ' BETWEEN ' . (float)$swLat . ' AND ' . (float)$neLat)
            ->where($db->quoteName('lng') . ' BETWEEN ' . (float)$swLng . ' AND ' . (float)$neLng);

        if (!empty($opts['only_coins'])) {
            $q->where($db->quoteName('has_coins') . ' = 1');
        }
        if (!empty($opts['region'])) {
            $q->where($db->quoteName('region_code') . ' = ' . $db->quote((string)$opts['region']));
        }

        $q->setLimit($limit);
        $db->setQuery($q);
        $rows = $db->loadAssocList() ?: [];

        return array_map(static function ($r) {
            return [
                'id'        => $r['loc_id'],
                'lat'       => (float)$r['lat'],
                'lng'       => (float)$r['lng'],
                'name'      => $r['name'],
                'has_coins' => (bool)(int)$r['has_coins'],
            ];
        }, $rows);
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
            'summary'     => $pick($r['summary_en'] ?? null, $r['summary_tr'] ?? null),
            'content'     => $pick($r['content_en'] ?? null, $r['content_tr'] ?? null),
            'lat'         => isset($r['lat']) ? (float)$r['lat'] : null,
            'lng'         => isset($r['lng']) ? (float)$r['lng'] : null,
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
