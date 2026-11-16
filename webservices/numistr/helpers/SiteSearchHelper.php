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
 * Site araması (GET /v1/search, 1.26.0): sikke tipleri, antik yerleşimler, blog yazıları.
 *
 * Neden: sitede arama yoktu (Smart Search dizini boş; /v1/variants serbest metin almıyor, filtresiz 422).
 * Ana sayfa denetimi (16 Kas 2026, claudedocs/web/ana_sayfa_denetimi_2026-11-16.md) sonrası kullanıcı
 * "kendi arama sayfamız" yolunu seçti.
 *
 * Sikkelerde eşleşme türü sırayı belirler: darphane > hükümdar > başlık > ön/arka yüz betimi (motif).
 * Eşleşme SÖZCÜK BAŞINA bağlıdır (NumisTRMotifSearch ile aynı ilke: "at" → "atın" evet, "kanat" hayır);
 * SQL LIKE yalnız ön süzmedir, kesin karar matchesWordStart()'tadır.
 *
 * Türkçe yer/hükümdar adları katalogdaki Latince yazıma çevrilir (Efes → ephesus, Lapseki → lampsacus,
 * Kyzikos → cyzicus). Konumlandırma kuralı: toplam sonuç sayısı DÖNMEZ, yalnız has_more.
 *
 * @since 1.26.0
 */
class NumisTRSiteSearch
{
    public const MIN_LEN = 2;
    public const MAX_LEN = 60;
    public const MAX_CANDIDATES = 4000;
    public const MAX_OFFSET = 480;

    /** Küçük sayı = üstte. */
    public const TIERS = ['mint' => 0, 'authority' => 1, 'title' => 2, 'motif' => 3];

    /** Antik yerleşim kategorileri (NumisTRAssistantTools::SETTLEMENT_CATS ile aynı). */
    public const SETTLEMENT_CATS = ['tr' => [72, 88], 'en' => [89, 105]];
    public const FIELD_SETTLEMENT_REGION = 45;

    /** Blog menü yolları (canlı adresler: /tr/sikke-hikayeleri/<id>-<alias>, /en/coin-stories/<id>-<alias>). */
    public const ARTICLE_PATHS = ['tr' => 'sikke-hikayeleri', 'en' => 'coin-stories'];
    public const ARTICLE_CATS = ['tr' => [35, 36, 37, 38, 39, 40], 'en' => [107, 108, 109, 110, 111, 112]];

    /**
     * Türkçe ya da yaygın yazım → katalogdaki Latince yazım. Yalnız kuralın (latinise) tutturamadığı adlar;
     * değerler canlı /v1/suggest/mints ile doğrulandı (16 Kas 2026).
     */
    public const ALIASES = [
        'efes' => 'ephesus', 'bergama' => 'pergamum', 'pergamon' => 'pergamum', 'izmir' => 'smyrna',
        'sart' => 'sardes', 'sardeis' => 'sardes', 'sardis' => 'sardes', 'milet' => 'miletus',
        'foca' => 'phocaea', 'bodrum' => 'halicarnassus', 'halikarnas' => 'halicarnassus',
        'datca' => 'cnidus', 'lapseki' => 'lampsacus', 'bursa' => 'prusa', 'iznik' => 'nicaea',
        'izmit' => 'nicomedia', 'sinop' => 'sinope', 'samsun' => 'amisus', 'antakya' => 'antioch',
        'iskender' => 'alexander', 'karun' => 'croesus',
    ];

    /** @var object Joomla DatabaseDriver */
    private $db;

    /** @var array config/constants.php */
    private $constants;

    /** @var NumisTRDatabaseHelper */
    private $dbHelper;

    /** @var string site kökü, sonda / yok */
    private $base;

    /** @var array<int, string> catid → menü takma adı */
    private $menuAlias = [];

    public function __construct($db, array $constants, $dbHelper, string $base)
    {
        $this->db = $db;
        $this->constants = $constants;
        $this->dbHelper = $dbHelper;
        $this->base = rtrim($base, '/');
    }

    // ================================================================ saf yardımcılar (test edilir)

    /** Türkçe duyarlı küçük harf. */
    public static function trLower(string $s): string
    {
        return mb_strtolower(strtr($s, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    /** Türkçe harfleri ASCII'ye indirger (küçük harfli girdi bekler). */
    public static function fold(string $s): string
    {
        return strtr($s, ['ı' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u', 'i̇' => 'i']);
    }

    /**
     * Yunanca/Türkçe yazımı katalogdaki Latince yazıma yaklaştırır (sözcük sözcük):
     * kh→ch, k→c, ai→ae, oi→oe, ei→i, sondaki -os→-us. lampsakos → lampsacus, kyzikos → cyzicus.
     */
    public static function latinise(string $folded): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($folded)) ?: [] as $w) {
            if ($w === '') {
                continue;
            }
            if (isset(self::ALIASES[$w])) {
                $out[] = self::ALIASES[$w];
                continue;
            }
            $w = str_replace(['kh', 'k', 'ai', 'oi', 'ei'], ['ch', 'c', 'ae', 'oe', 'i'], $w);
            $w = preg_replace('/os$/', 'us', $w);
            $out[] = $w;
        }

        return implode(' ', $out);
    }

    /**
     * Sorguyu temizler ve arama biçimlerini üretir. Geçersizse null.
     *
     * @return array{raw: string, lower: string, variants: string[], text: string[]}|null
     *   variants: darphane/hükümdar/başlık için (Latince biçimler dahil); text: betim (motif) için.
     */
    public static function normalize(string $q): ?array
    {
        $q = preg_replace('/[\x00-\x1F\x7F%_*?]+/u', ' ', $q) ?? '';
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if (mb_strlen($q, 'UTF-8') > self::MAX_LEN) {
            $q = trim(mb_substr($q, 0, self::MAX_LEN, 'UTF-8'));
        }
        if (mb_strlen($q, 'UTF-8') < self::MIN_LEN) {
            return null;
        }

        $lower = self::trLower($q);
        $folded = self::fold($lower);
        $text = array_values(array_unique([$lower, $folded]));
        $variants = $text;
        $latin = self::latinise($folded);
        if ($latin !== '' && !in_array($latin, $variants, true)) {
            $variants[] = $latin;
        }
        $variants = array_values(array_filter($variants, fn ($v) => mb_strlen($v, 'UTF-8') >= self::MIN_LEN));

        return ['raw' => $q, 'lower' => $lower, 'variants' => $variants, 'text' => $text];
    }

    /** Metinde terimlerden biri SÖZCÜK BAŞINDA geçiyor mu? (alt çizgi sözcük ayırıcı sayılır: prusa_ad_olympum) */
    public static function matchesWordStart(string $text, array $terms): bool
    {
        $lower = self::trLower(str_replace('_', ' ', $text));
        $hay = [$lower, self::fold($lower)];
        foreach ($terms as $t) {
            $re = '/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '/u';
            foreach ($hay as $h) {
                if (preg_match($re, $h)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * İsabetleri sıralar: her sikke en iyi eşleşme türüyle bir kez; tür sırası, sonra article_id.
     *
     * @param  array<int, array{id: int, kind: string}>  $hits
     * @return array<int, array{id: int, kind: string}>
     */
    public static function rank(array $hits): array
    {
        $best = [];
        foreach ($hits as $h) {
            $id = (int) ($h['id'] ?? 0);
            $kind = (string) ($h['kind'] ?? '');
            if ($id <= 0 || !isset(self::TIERS[$kind])) {
                continue;
            }
            if (!isset($best[$id]) || self::TIERS[$kind] < self::TIERS[$best[$id]]) {
                $best[$id] = $kind;
            }
        }
        $out = [];
        foreach ($best as $id => $kind) {
            $out[] = ['id' => $id, 'kind' => $kind];
        }
        usort($out, fn ($a, $b) => [self::TIERS[$a['kind']], $a['id']] <=> [self::TIERS[$b['kind']], $b['id']]);

        return $out;
    }

    /** Sikke adresi (NumisTRAssistantTools::coinUrl ile aynı biçim). */
    public static function coinUrl(string $base, string $lang, string $regionCode, int $id, string $alias): string
    {
        $lang = $lang === 'en' ? 'en' : 'tr';
        $paths = ['other-ancient-regions-coins' => ['tr' => 'other-ancient-place-coins', 'en' => 'other-ancient-regions']];
        $path = $paths[$regionCode][$lang] ?? 'anatolian-coins/' . rawurlencode($regionCode);

        return rtrim($base, '/') . '/' . $lang . '/' . $path . '/' . $id . '-' . rawurlencode($alias);
    }

    public static function articleUrl(string $base, string $lang, int $id, string $alias): string
    {
        $lang = $lang === 'en' ? 'en' : 'tr';

        return rtrim($base, '/') . '/' . $lang . '/' . self::ARTICLE_PATHS[$lang] . '/' . $id . '-' . rawurlencode($alias);
    }

    /** Joomla images JSON'undan giriş görseli (mutlak adres) ya da null. */
    public static function introImage(?string $imagesJson, string $base): ?string
    {
        $j = json_decode((string) $imagesJson, true);
        $u = is_array($j) ? trim((string) ($j['image_intro'] ?? '')) : '';
        if ($u === '') {
            return null;
        }
        $u = preg_replace('/#.*$/', '', $u);
        if (preg_match('~^https?://~i', $u)) {
            return $u;
        }

        return rtrim($base, '/') . '/' . ltrim($u, '/');
    }

    /** HTML → düz metin, en çok $max karakter (sözcük sınırında kesilir). */
    public static function plain(?string $html, int $max): string
    {
        $t = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        if (mb_strlen($t, 'UTF-8') <= $max) {
            return $t;
        }
        $cut = mb_substr($t, 0, $max, 'UTF-8');
        $sp = mb_strrpos($cut, ' ', 0, 'UTF-8');

        return rtrim($sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp, 'UTF-8') : $cut, ' ,.;:') . '…';
    }

    /** Ham sikke satırını yanıt biçimine çevirir. */
    public static function formatCoin(array $r, string $lang, string $base, ?string $material, string $kind): array
    {
        $title = $lang === 'en'
            ? ($r['title_en'] ?: ($r['title_tr'] ?: $r['slug']))
            : ($r['title_tr'] ?: ($r['title_en'] ?: $r['slug']));
        $year = fn ($v) => is_numeric($v) ? (int) $v : null;

        return [
            'article_id' => (int) $r['article_id'],
            'title' => (string) $title,
            'mint' => ($r['mint'] ?? '') !== '' ? (string) $r['mint'] : null,
            'authority' => ($r['authority'] ?? '') !== '' ? (string) $r['authority'] : null,
            'material' => $material,
            'date_from' => $year($r['date_from'] ?? null),
            'date_to' => $year($r['date_to'] ?? null),
            'region' => (string) ($r['region_code'] ?? ''),
            'obverse' => self::plain($r['obv'] ?? '', 160),
            'reverse' => self::plain($r['rev'] ?? '', 160),
            'match' => $kind,
            'url' => self::coinUrl($base, $lang, (string) ($r['region_code'] ?? ''), (int) $r['article_id'], (string) (($r['alias'] ?? '') ?: $r['slug'])),
        ];
    }

    // ================================================================ veritabanı

    private function likeAny(string $col, array $terms): string
    {
        $db = $this->db;
        $parts = [];
        foreach ($terms as $t) {
            $parts[] = 'LOWER(' . $col . ') LIKE ' . $db->quote('%' . $db->escape($t, true) . '%', false);
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @return array<string, int> */
    private function coinFieldIds(string $lang): array
    {
        $f = $this->constants['FIELD_ID'] ?? [];

        return [
            'mint' => (int) ($f['mint_name'] ?? 4),
            'authority' => (int) ($f['authority_name'] ?? 2),
            'material' => (int) ($f['material'] ?? 23),
            'start' => (int) ($f['start_date'] ?? 25),
            'end' => (int) ($f['end_date'] ?? 26),
            'obv' => (int) ($lang === 'en' ? ($f['obverse_desc'] ?? 30) : ($f['obverse_desc_tr'] ?? 32)),
            'rev' => (int) ($lang === 'en' ? ($f['reverse_desc'] ?? 31) : ($f['reverse_desc_tr'] ?? 33)),
        ];
    }

    /**
     * Sikke araması: sıralı isabetlerden [offset, offset+limit) dilimi + has_more.
     *
     * @return array{rows: array<int, array>, kinds: array<int, string>, has_more: bool}
     */
    public function coins(string $lang, array $n, int $offset, int $limit): array
    {
        $db = $this->db;
        $empty = ['rows' => [], 'kinds' => [], 'has_more' => false];
        $allowed = $this->dbHelper->getAllowedCatIds($db, (int) ($this->constants['ROOT_CAT_ID'] ?? 16));
        if (!$allowed) {
            return $empty;
        }
        $cats = implode(',', array_map('intval', $allowed));
        $fv = $db->quoteName($this->dbHelper->resolveFieldsValuesTable($db));
        $fid = $this->coinFieldIds($lang);
        $content = ' JOIN ' . $db->quoteName('#__content') . ' c ON v.item_id = CAST(c.id AS CHAR) COLLATE utf8mb4_unicode_ci'
            . ' AND c.state = 1 AND c.catid IN (' . $cats . ')';

        $hits = [];

        // 1) darphane + hükümdar (Latince biçimler dahil)
        $db->setQuery(
            'SELECT v.item_id AS id, v.field_id AS f, v.value AS val FROM ' . $fv . ' v' . $content
            . ' WHERE v.field_id IN (' . $fid['mint'] . ',' . $fid['authority'] . ') AND ' . $this->likeAny('v.value', $n['variants'])
            . ' LIMIT ' . self::MAX_CANDIDATES
        );
        foreach ($db->loadAssocList() ?: [] as $r) {
            if (self::matchesWordStart((string) $r['val'], $n['variants'])) {
                $hits[] = ['id' => (int) $r['id'], 'kind' => ((int) $r['f'] === $fid['mint']) ? 'mint' : 'authority'];
            }
        }

        // 2) başlık (TR + EN başlık)
        $db->setQuery(
            'SELECT pv.article_id AS id, pv.title_tr AS t1, pv.title_en AS t2 FROM ' . $db->quoteName('o_numistr_variants_public') . ' pv'
            . ' JOIN ' . $db->quoteName('#__content') . ' c ON c.id = pv.article_id AND c.state = 1 AND c.catid IN (' . $cats . ')'
            . ' WHERE ' . $this->likeAny('pv.title_tr', $n['variants']) . ' OR ' . $this->likeAny('pv.title_en', $n['variants'])
            . ' LIMIT ' . self::MAX_CANDIDATES
        );
        foreach ($db->loadAssocList() ?: [] as $r) {
            if (self::matchesWordStart((string) $r['t1'] . ' ' . (string) $r['t2'], $n['variants'])) {
                $hits[] = ['id' => (int) $r['id'], 'kind' => 'title'];
            }
        }

        // 3) ön/arka yüz betimi (motif) — yalnız sorgunun kendi yazımı
        $db->setQuery(
            'SELECT v.item_id AS id, v.value AS val FROM ' . $fv . ' v' . $content
            . ' WHERE v.field_id IN (' . $fid['obv'] . ',' . $fid['rev'] . ') AND ' . $this->likeAny('v.value', $n['text'])
            . ' LIMIT ' . self::MAX_CANDIDATES
        );
        foreach ($db->loadAssocList() ?: [] as $r) {
            if (self::matchesWordStart((string) $r['val'], $n['text'])) {
                $hits[] = ['id' => (int) $r['id'], 'kind' => 'motif'];
            }
        }

        $ranked = self::rank($hits);
        $slice = array_slice($ranked, $offset, $limit + 1);
        $hasMore = count($slice) > $limit;
        $slice = array_slice($slice, 0, $limit);
        if (!$slice) {
            return $empty;
        }

        $ids = array_map(fn ($h) => (int) $h['id'], $slice);
        $kinds = [];
        foreach ($slice as $h) {
            $kinds[(int) $h['id']] = $h['kind'];
        }
        $f = $fid;
        $db->setQuery(
            'SELECT pv.article_id, pv.title_tr, pv.title_en, pv.slug, pv.region_code, c.alias,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['mint'] . ' THEN v.value END) AS mint,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['authority'] . ' THEN v.value END) AS authority,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['material'] . ' THEN v.value END) AS material,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['start'] . ' THEN v.value END) AS date_from,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['end'] . ' THEN v.value END) AS date_to,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['obv'] . ' THEN v.value END) AS obv,'
            . ' MAX(CASE WHEN v.field_id = ' . $f['rev'] . ' THEN v.value END) AS rev'
            . ' FROM ' . $db->quoteName('o_numistr_variants_public') . ' pv'
            . ' JOIN ' . $db->quoteName('#__content') . ' c ON c.id = pv.article_id'
            . ' LEFT JOIN ' . $fv . ' v ON v.item_id = CAST(pv.article_id AS CHAR) COLLATE utf8mb4_unicode_ci'
            . ' AND v.field_id IN (' . implode(',', array_map('intval', $f)) . ')'
            . ' WHERE pv.article_id IN (' . implode(',', $ids) . ')'
            . ' GROUP BY pv.article_id, pv.title_tr, pv.title_en, pv.slug, pv.region_code, c.alias'
        );
        $byId = [];
        foreach ($db->loadAssocList() ?: [] as $r) {
            $byId[(int) $r['article_id']] = $r;
        }
        $rows = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            }
        }

        return ['rows' => $rows, 'kinds' => $kinds, 'has_more' => $hasMore];
    }

    /** Kategori → menü takma adı (yerleşim adresleri bölge kategorisine göre değişir). */
    private function menuAliasFor(int $catid, string $lang): string
    {
        $key = $catid . ':' . $lang;
        if (isset($this->menuAlias[$key])) {
            return $this->menuAlias[$key];
        }
        $db = $this->db;
        $alias = '';
        try {
            $q = $db->getQuery(true)
                ->select($db->quoteName('alias'))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('link') . ' LIKE ' . $db->quote('%option=com_content&view=category%'))
                ->where('(' . $db->quoteName('link') . ' LIKE ' . $db->quote('%&id=' . $catid) . ' OR ' . $db->quoteName('link') . ' LIKE ' . $db->quote('%&id=' . $catid . '&%') . ')')
                ->where($db->quoteName('language') . ' IN (' . $db->quote($lang === 'en' ? 'en-GB' : 'tr-TR') . ', ' . $db->quote('*') . ')')
                ->order($db->quoteName('language') . ' DESC')
                ->setLimit(1);
            $db->setQuery($q);
            $alias = (string) $db->loadResult();
            if ($alias === '') {
                $db->setQuery($db->getQuery(true)->select($db->quoteName('alias'))->from($db->quoteName('#__categories'))->where($db->quoteName('id') . ' = ' . (int) $catid));
                $alias = (string) $db->loadResult();
            }
        } catch (\Throwable $e) {
            $alias = '';
        }

        return $this->menuAlias[$key] = ($alias !== '' ? $alias : ($lang === 'en' ? 'ancient-settlements' : 'antik-yerlesimler'));
    }

    /** @return array{items: array<int, array>, has_more: bool} */
    public function settlements(string $lang, array $n, int $offset, int $limit): array
    {
        $db = $this->db;
        [$lo, $hi] = self::SETTLEMENT_CATS[$lang === 'en' ? 'en' : 'tr'];
        $fv = $db->quoteName($this->dbHelper->resolveFieldsValuesTable($db));
        $exact = $db->quote($n['lower']);
        $db->setQuery(
            'SELECT c.id, c.title, c.alias, c.catid, c.introtext, r.value AS region FROM ' . $db->quoteName('#__content') . ' c'
            . ' LEFT JOIN ' . $fv . ' r ON r.item_id = CAST(c.id AS CHAR) COLLATE utf8mb4_unicode_ci AND r.field_id = ' . self::FIELD_SETTLEMENT_REGION
            . ' WHERE c.state = 1 AND c.catid BETWEEN ' . (int) $lo . ' AND ' . (int) $hi
            . ' AND (' . $this->likeAny('c.title', $n['variants']) . ' OR ' . $this->likeAny('c.alias', $n['variants']) . ')'
            . ' ORDER BY CASE WHEN LOWER(c.title) = ' . $exact . ' THEN 0 ELSE 1 END, c.title ASC'
            . ' LIMIT ' . (int) $offset . ', ' . ((int) $limit + 1)
        );
        $rows = $db->loadAssocList() ?: [];
        $hasMore = count($rows) > $limit;
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $r) {
            $items[] = [
                'article_id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'region' => ($r['region'] ?? '') !== '' ? (string) $r['region'] : null,
                'summary' => self::plain($r['introtext'] ?? '', 180),
                'url' => $this->base . '/' . ($lang === 'en' ? 'en' : 'tr') . '/' . rawurlencode($this->menuAliasFor((int) $r['catid'], $lang)) . '/' . (int) $r['id'] . '-' . rawurlencode((string) $r['alias']),
            ];
        }

        return ['items' => $items, 'has_more' => $hasMore];
    }

    /** @return array{items: array<int, array>, has_more: bool} */
    public function articles(string $lang, array $n, int $offset, int $limit): array
    {
        $db = $this->db;
        $lang = $lang === 'en' ? 'en' : 'tr';
        $cats = implode(',', array_map('intval', self::ARTICLE_CATS[$lang]));
        $now = $db->quote(gmdate('Y-m-d H:i:s'));
        $db->setQuery(
            'SELECT c.id, c.title, c.alias, c.images, c.publish_up FROM ' . $db->quoteName('#__content') . ' c'
            . ' WHERE c.state = 1 AND c.catid IN (' . $cats . ')'
            . ' AND (c.publish_up IS NULL OR c.publish_up <= ' . $now . ')'
            . ' AND (' . $this->likeAny('c.title', $n['variants']) . ' OR ' . $this->likeAny('c.metadesc', $n['text']) . ')'
            . ' ORDER BY c.publish_up DESC'
            . ' LIMIT ' . (int) $offset . ', ' . ((int) $limit + 1)
        );
        $rows = $db->loadAssocList() ?: [];
        $hasMore = count($rows) > $limit;
        $items = [];
        foreach (array_slice($rows, 0, $limit) as $r) {
            $items[] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'date' => $r['publish_up'] ? substr((string) $r['publish_up'], 0, 10) : null,
                'image' => self::introImage($r['images'] ?? null, $this->base),
                'url' => self::articleUrl($this->base, $lang, (int) $r['id'], (string) $r['alias']),
            ];
        }

        return ['items' => $items, 'has_more' => $hasMore];
    }
}
