<?php
/**
 * İlgili yazılar ve günün hikâyesinin sikkesi (API 1.31.0, 28 Kas 2026).
 *
 * Uygulamadaki üç yüzey için:
 *   - sikke detayı Kaynak sekmesi: GET /v1/variants/{id}/articles
 *   - harita bilgi ekranı:         GET /v1/locations/{loc_id}/articles
 *   - ana sayfa günün hikâyesi:    GET /v1/articles/featured → "coin"
 *
 * Kural sitedeki mod_numistr_ilgili 1.4.1 ile aynıdır. Modülün saf işlevleri kullanılır:
 *   - dönem süzgeci, sıra (sikkeYazilariniSirala);
 *   - Price tipi hükümdar (yaziHukumdari);
 *   - darphane ↔ yerleşim eşlemesi (harita / tersHarita).
 * Modül kurulu değilse uçlar boş liste döner, uygulama bölümü gizler. Uç düşmez.
 *
 * Aday sorguları burada yazılır. Modülün sikkeYazilari() site yönlendirmesi ve oturum kimliği ister; API uygulamasında
 * ikisi de yok. Yalnız herkese açık (access 1), yayındaki blog yazıları döner.
 */
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class NumisTRRelatedArticles
{
    const MODUL = '/modules/mod_numistr_ilgili/helper.php';

    /** Blog kategorileri (TR 35–40, EN 107–112): modülün BLOG_KATEGORILERI ile aynı. */
    const BLOG_KATEGORILERI = [35, 36, 37, 38, 39, 40, 107, 108, 109, 110, 111, 112];

    // ------------------------------------------------------------------ saf (DB'siz test edilir)

    /** Uygulamanın dil parametresi → Joomla dil kodu. */
    public static function dil(string $lang): string
    {
        return $lang === 'en' ? 'en-GB' : 'tr-TR';
    }

    /** Liste sınırı: 1–6, varsayılan 3. */
    public static function sinir($limit): int
    {
        $l = (int) $limit;

        return $l < 1 ? 3 : min(6, $l);
    }

    /** Kart özeti: figür kodu atılır, etiket ve fazla boşluk temizlenir, en çok $sinir karakter (liste ucuyla aynı ölçü). */
    public static function ozet(string $introtext, int $sinir = 200): string
    {
        if (class_exists('ArticleFigureHelper')) {
            $introtext = ArticleFigureHelper::stripCodes($introtext);
        }

        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($introtext), ENT_QUOTES, 'UTF-8')));

        return mb_strlen($t, 'UTF-8') > $sinir ? mb_substr($t, 0, $sinir, 'UTF-8') . '...' : $t;
    }

    /** Yazıdaki sikke sayfası bağlantılarının makale kimlikleri (…/anatolian-coins/<bölge>/<id>-<alias>), sırayla, tekrarsız. */
    public static function sikkeBaglantilari(string $html): array
    {
        preg_match_all('~/anatolian-coins/[^"\'\s<>)]*?/(\d+)-~', $html, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * Aday satırı (sorgu sonucu) → kart (liste ucu biçimi: id, title, intro, category, category_id, created).
     *
     * @param  array  $r  ['id','baslik','introtext','kat','catid','created']
     */
    public static function kart(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['baslik'],
            'intro'       => self::ozet((string) ($r['introtext'] ?? '')),
            'category'    => (string) (($r['kat'] ?? '') !== '' ? $r['kat'] : 'Blog'),
            'category_id' => (int) $r['catid'],
            'created'     => (string) $r['created'],
        ];
    }

    // ------------------------------------------------------------------ modül

    /** Modülün saf işlevleri yüklenebiliyor mu (1.4.1+)? */
    public static function modul(): bool
    {
        if (!class_exists('ModNumistrIlgiliHelper', false)) {
            $yol = (defined('JPATH_SITE') ? JPATH_SITE : '') . self::MODUL;

            if (!is_file($yol)) {
                return false;
            }

            require_once $yol;
        }

        return class_exists('ModNumistrIlgiliHelper', false)
            && method_exists('ModNumistrIlgiliHelper', 'sikkeYazilariniSirala')
            && method_exists('ModNumistrIlgiliHelper', 'yaziHukumdari');
    }

    // ------------------------------------------------------------------ uçlar

    /** Sikkenin ilgili yazıları (en çok $limit); sikke yoksa / darphanesizse / modül yoksa boş. */
    public static function forCoin(int $coinId, string $lang, int $limit = 3): array
    {
        if ($coinId <= 0 || !self::modul()) {
            return [];
        }

        $H        = 'ModNumistrIlgiliHelper';
        $a        = $H::alanDegerleri($coinId);
        $darphane = trim((string) ($a[$H::ALAN_DARPHANE] ?? ''));

        if ($darphane === '') {
            return [];
        }

        $huk    = $H::yaziHukumdari($H::makaleBasligi($coinId), $a[$H::ALAN_HUKUMDAR] ?? '');
        $donem  = $H::sikkeDonemleri($a[$H::ALAN_BASLANGIC] ?? '', $a[$H::ALAN_BITIS] ?? '');
        $aday   = self::adaylar([$H::yerEtiketi($darphane)], $huk !== '' ? [$H::sade($huk)] : [], self::dil($lang), $coinId);

        return array_map([__CLASS__, 'kart'], $H::sikkeYazilariniSirala($aday, $donem, $limit));
    }

    /** Yerleşimin ilgili yazıları: yerleşimin darphaneleri (eşleme tablosu) → yer etiketleri; dönem süzgeci yok. */
    public static function forLocation(int $articleIdTr, string $lang, int $limit = 3): array
    {
        if ($articleIdTr <= 0 || !self::modul()) {
            return [];
        }

        $H           = 'ModNumistrIlgiliHelper';
        $darphaneler = $H::tersHarita($H::harita())[$articleIdTr] ?? [];

        if (!$darphaneler) {
            return [];
        }

        $aday = self::adaylar(array_map([$H, 'yerEtiketi'], $darphaneler), [], self::dil($lang), 0);

        return array_map([__CLASS__, 'kart'], $H::sikkeYazilariniSirala($aday, [], $limit));
    }

    /**
     * Yazıdaki sikkelerin katalog (TR, dil '*') kimlikleri, yazıdaki sırayla.
     * EN yazının bağlantıları EN kopyalara gider; ortak coin-id (PELLA / CN adresi) ile TR kopyaya çevrilir.
     */
    public static function yazininSikkeleri(string $html): array
    {
        $ids = self::sikkeBaglantilari($html);

        if (!$ids) {
            return [];
        }

        $db   = Factory::getDbo();
        $in   = implode(',', array_map('intval', $ids));
        $dil  = $db->setQuery("SELECT id, language FROM #__content WHERE id IN ($in) AND state = 1")->loadAssocList('id', 'language') ?: [];
        $ceviri = [];
        $en   = array_keys(array_filter($dil, function ($l) { return $l === 'en-GB'; }));

        if ($en) {
            $alan = (int) $db->setQuery("SELECT id FROM #__fields WHERE name = 'coin-id' AND context = 'com_content.article'")->loadResult();

            if ($alan) {
                $q = "SELECT e.item_id en_id, t.item_id tr_id FROM #__fields_values e
                      JOIN #__fields_values t ON t.field_id = e.field_id AND t.value = e.value AND t.value <> ''
                      JOIN #__content c ON c.id = t.item_id AND c.language = '*' AND c.state = 1
                      WHERE e.field_id = $alan AND e.item_id IN ('" . implode("','", array_map('intval', $en)) . "')";

                foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r) {
                    $ceviri[(int) $r['en_id']] = $ceviri[(int) $r['en_id']] ?? (int) $r['tr_id'];
                }
            }
        }

        $sonuc = [];

        foreach ($ids as $id) {
            $l  = $dil[$id] ?? null;
            $tr = $l === '*' ? $id : ($l === 'en-GB' ? ($ceviri[$id] ?? 0) : 0);

            if ($tr > 0 && !in_array($tr, $sonuc, true)) {
                $sonuc[] = $tr;
            }
        }

        return $sonuc;
    }

    // ------------------------------------------------------------------ sorgular

    /**
     * Yer / hükümdar etiketli yayındaki blog yazıları + dönem etiketleri ve yer + hükümdar etiketi sayısı.
     * Satırlar modülün sikkeYazilariniSirala biçiminde (eslesme: ikisi | hukumdar | darphane).
     */
    private static function adaylar(array $yerAlias, array $hukAlias, string $dil, int $haric): array
    {
        $db    = Factory::getDbo();
        $kosul = [];

        if ($yerAlias) {
            $kosul[] = "(p.alias = 'yerler' AND t.alias IN (" . implode(',', array_map([$db, 'quote'], $yerAlias)) . '))';
        }

        if ($hukAlias) {
            $kosul[] = "(p.alias = 'hukumdarlar' AND t.alias IN (" . implode(',', array_map([$db, 'quote'], $hukAlias)) . '))';
        }

        if (!$kosul) {
            return [];
        }

        $simdi = $db->quote(Factory::getDate()->toSql());
        $kat   = implode(',', self::BLOG_KATEGORILERI);
        $q     = "SELECT c.id, c.title, c.introtext, c.catid, c.publish_up, c.created, cat.title kat, p.alias ust
                  FROM #__contentitem_tag_map m
                  JOIN #__tags t ON t.id = m.tag_id
                  JOIN #__tags p ON p.id = t.parent_id
                  JOIN #__content c ON c.id = m.content_item_id
                  LEFT JOIN #__categories cat ON cat.id = c.catid
                  WHERE m.type_alias = 'com_content.article' AND t.published = 1 AND (" . implode(' OR ', $kosul) . ")
                    AND c.language IN (" . $db->quote($dil) . ", '*') AND c.state = 1 AND c.access = 1 AND c.catid IN ($kat)
                    AND (c.publish_up IS NULL OR c.publish_up <= $simdi) AND (c.publish_down IS NULL OR c.publish_down >= $simdi)
                    AND c.id <> " . (int) $haric . '
                  LIMIT 300';
        $yazilar = [];

        foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r) {
            $id = (int) $r['id'];

            if (!isset($yazilar[$id])) {
                $yazilar[$id] = ['id' => $id, 'baslik' => (string) $r['title'], 'introtext' => (string) $r['introtext'],
                    'catid' => (int) $r['catid'], 'publish_up' => (string) $r['publish_up'], 'created' => (string) $r['created'],
                    'kat' => (string) $r['kat'], 'yer' => false, 'huk' => false, 'donem' => [], 'yer_sayisi' => 0, 'etiket_sayisi' => 0];
            }

            $yazilar[$id][$r['ust'] === 'yerler' ? 'yer' : 'huk'] = true;
        }

        if (!$yazilar) {
            return [];
        }

        $q = "SELECT m.content_item_id id, t.alias, p.alias ust
              FROM #__contentitem_tag_map m
              JOIN #__tags t ON t.id = m.tag_id
              JOIN #__tags p ON p.id = t.parent_id
              WHERE m.type_alias = 'com_content.article' AND t.published = 1
                AND m.content_item_id IN (" . implode(',', array_keys($yazilar)) . ")
                AND p.alias IN ('yerler', 'hukumdarlar', 'donemler')";

        foreach ($db->setQuery($q)->loadAssocList() ?: [] as $r) {
            $id = (int) $r['id'];

            if ($r['ust'] === 'donemler') {
                $yazilar[$id]['donem'][] = preg_replace('/^donem-/', '', (string) $r['alias']);
            } else {
                $yazilar[$id]['etiket_sayisi']++;
                $yazilar[$id]['yer_sayisi'] += $r['ust'] === 'yerler' ? 1 : 0;
            }
        }

        foreach ($yazilar as &$y) {
            $y['eslesme'] = $y['yer'] && $y['huk'] ? 'ikisi' : ($y['huk'] ? 'hukumdar' : 'darphane');
        }
        unset($y);

        return array_values($yazilar);
    }
}
