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
 * 1.20.0 — lisansı DOĞRULANMAMIŞ görseller REST API yanıtlarından çıkarılır (App Store "Content Rights" beyanı,
 * kullanıcı kararı 2026-10-13). Serbest lisanslılar ve NC (ADR-008 §3.2: her kademede filigransız, atıflı) AYNEN kalır.
 *
 * Tek kaynak: `coins_images.license` (VARCHAR 60) + `coins_images.license_ok` (TINYINT 1). Bu iki kolon 2026-09-17'de
 * lisans haritasından (claudedocs/gorsel-tamamlama/LISANS-HARITASI-2026-09-17.md, scripts/gorsel_kurtarma/veri/
 * atif_haritasi.csv) dolduruldu: 58.889 serbest (license_ok=1) + 11.335 NC (license_ok=0, lisans metni dolu).
 * Kalan ~2.145 satır (Kenom, DNB, 14 küçük kaynak, localhost:3030 bozuk satırlar, remote_url'siz satırlar) DOLDURULMADI.
 * Site (com_numistr görsel bileşeni, galeri modülü) filigran kararını da aynı kolondan veriyor; burada host listesi
 * TEKRARLANMAZ (iki kaynak ayrışırdı — ör. images.numismatics.org haritada "doğrulanmadı", DB'de license_ok=1).
 *
 * Kural (saf): görünür ⇔ license_ok = 1 · YA DA · (license_ok = 0 VE lisans metni dolu = doğrulanmış NC).
 * license_ok NULL ya da lisans metni boş → doğrulanmamış → gizli. Kural, doldurulmamış satırların license_ok'u NULL mı
 * 0 mı olduğundan bağımsızdır (ikisinde de gizlenir).
 *
 * Kapatma anahtarı: constants.php IMAGE_LICENSE.hide_unverified = false (dosya geri almadan eski davranış).
 * Kapsam dışı: site (com_numistr view=gorsel, mod galeri) — orada görseller değişmeden görünmeye devam eder.
 *
 * @since  1.20.0
 */
class NumisTRImageLicense
{
    /** Gizleme açık mı? (varsayılan AÇIK) */
    public static function enabled(array $config)
    {
        $c = isset($config['IMAGE_LICENSE']) ? (array) $config['IMAGE_LICENSE'] : array();

        return !array_key_exists('hide_unverified', $c) || (bool) $c['hide_unverified'];
    }

    /**
     * Saf karar: bu görsel API yanıtında yer alabilir mi?
     *
     * @param   mixed  $license    coins_images.license (DB'den metin ya da NULL)
     * @param   mixed  $licenseOk  coins_images.license_ok (DB'den "1" / "0" / NULL)
     */
    public static function isVisible($license, $licenseOk)
    {
        if ($licenseOk === null || $licenseOk === '' || !is_numeric($licenseOk)) {
            return false; // doldurulmamış = doğrulanmamış
        }

        $ok = (int) $licenseOk;

        if ($ok === 1) {
            return true; // serbest
        }

        // NC: lisans metni olan (haritadan doğrulanmış) satır kalır; metinsiz 0 = doğrulanmamış
        return $ok === 0 && trim((string) $license) !== '';
    }

    /**
     * SQL karşılığı (isVisible ile aynı kural). `$alias` coins_images takma adı.
     *
     * @param   object  $db  quoteName() sağlayan sürücü
     */
    public static function sqlVisible($db, $alias)
    {
        $ok  = $db->quoteName($alias . '.license_ok');
        $lic = $db->quoteName($alias . '.license');

        return '(' . $ok . ' = 1 OR (' . $ok . ' = 0 AND ' . $lic . ' IS NOT NULL AND TRIM(' . $lic . ") <> ''))";
    }

    /**
     * G12 (2026-10-16): kaynağı erişilemeyen görsel (yerelde ve kalıcı önbellekte yok, kaynak vermiyor — ör. Gallica'nın
     * Cloudflare engeli) hiçbir yanıtta yer almaz. Lisans kapısından bağımsız ve kapatma anahtarı yok: kırık görsel işe yaramaz.
     * Alanı `coins_images.source_unavailable` dolduran: `scripts/g12_gorsel_envanter.php` + ısıtma denemesi (runbook).
     */
    public static function isAvailable($sourceUnavailable)
    {
        return (int) $sourceUnavailable !== 1;
    }

    /** SQL karşılığı (isAvailable ile aynı kural). `$alias` coins_images takma adı. */
    public static function sqlAvailable($db, $alias)
    {
        return $db->quoteName($alias . '.source_unavailable') . ' = 0';
    }

    /**
     * 1.25.0 (M36, 13 Kas 2026) — liste yanıtına küçük görsel: her sikkenin İLK GÖRÜNÜR görseli, görsel ucuyla
     * (getVariantImages) aynı kurallarla seçilir. Uygulama eskiden liste sayfası başına 20 ayrı görsel isteği atıyordu.
     *
     * Saf. `$rows` coins_images satırları: coin_id, image_id, remote_url, license, license_ok, source_unavailable;
     * her sikke içinde `ordering` sırasıyla gelmeli (sikkeler karışık olabilir). `$customUrls` [coin_id => sikke-gorsel-url];
     * görsel ucundaki gibi YALNIZ sikkenin sıradaki ilk satırı (özgün $idx 0) görünürse onun remote_url'i olur.
     *
     * @return  array  [coin_id => ['image_id' => int, 'remote_url' => ?string]]; görünür görseli olmayan sikke yer almaz
     */
    public static function firstVisiblePerCoin(array $rows, array $customUrls, $hideUnverified)
    {
        $out  = array();
        $seen = array(); // coin_id => o sikkede şimdiye dek görülen satır sayısı (özgün $idx)

        foreach ($rows as $r) {
            $coinId  = (int) ($r['coin_id'] ?? 0);
            $imageId = (int) ($r['image_id'] ?? 0);

            if ($coinId <= 0) {
                continue;
            }

            $idx           = $seen[$coinId] ?? 0;
            $seen[$coinId] = $idx + 1;

            if (isset($out[$coinId]) || $imageId <= 0) {
                continue;
            }

            if ($hideUnverified && !self::isVisible($r['license'] ?? null, $r['license_ok'] ?? null)) {
                continue;
            }

            if (!self::isAvailable($r['source_unavailable'] ?? 0)) {
                continue;
            }

            $remote = isset($r['remote_url']) && $r['remote_url'] !== '' ? (string) $r['remote_url'] : null;

            if ($idx === 0 && !empty($customUrls[$coinId])) {
                $remote = (string) $customUrls[$coinId];
            }

            $out[$coinId] = array('image_id' => $imageId, 'remote_url' => $remote);
        }

        return $out;
    }

    /** Tanıma yanıtındaki (matches + near_matches) görsel kimlikleri. */
    public static function recognitionImageIds(array $formatted)
    {
        $ids = array();

        foreach (array('matches', 'near_matches') as $k) {
            foreach ((array) ($formatted[$k] ?? array()) as $m) {
                if (is_array($m) && isset($m['image_id']) && (int) $m['image_id'] > 0) {
                    $ids[] = (int) $m['image_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Saf: görünür olmayan görselin küçük resim adresi null olur; eşleşmenin kendisi (makale, güven) kalır.
     * DB'de bulunmayan görsel (dizin eski kayda bakıyor) de görünür sayılmaz.
     *
     * @param   int[]  $visibleIds  coins_images'ta görünür olan kimlikler
     */
    public static function scrubRecognition(array $formatted, array $visibleIds)
    {
        $visible = array_flip(array_map('intval', $visibleIds));

        foreach (array('matches', 'near_matches') as $k) {
            if (!isset($formatted[$k]) || !is_array($formatted[$k])) {
                continue;
            }

            foreach ($formatted[$k] as $i => $m) {
                if (is_array($m) && array_key_exists('thumbnail_url', $m)
                    && !isset($visible[(int) ($m['image_id'] ?? 0)])) {
                    $formatted[$k][$i]['thumbnail_url'] = null;
                }
            }
        }

        return $formatted;
    }

    /**
     * coins_images'tan görünür kimlikleri okur. Sorgu başarısızsa boş dizi (hiçbir küçük resim gösterilmez — lisans
     * açısından güvenli taraf; tanımanın kendisi etkilenmez).
     *
     * @param   object  $db
     * @param   int[]   $ids
     *
     * @return  int[]
     */
    public static function visibleIds($db, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
            return $v > 0;
        })));

        if (!$ids) {
            return array();
        }

        try {
            $db->setQuery(
                'SELECT ' . $db->quoteName('ci.image_id') . ' FROM ' . $db->quoteName('coins_images', 'ci')
                . ' WHERE ' . $db->quoteName('ci.image_id') . ' IN (' . implode(',', $ids) . ')'
                . ' AND ' . self::sqlVisible($db, 'ci')
                . ' AND ' . self::sqlAvailable($db, 'ci')
            );

            return array_map('intval', (array) $db->loadColumn());
        } catch (\Throwable $e) {
            return array();
        }
    }
}
