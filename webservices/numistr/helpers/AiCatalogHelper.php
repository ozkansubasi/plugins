<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * AI dizini eşitlemesi (AI9) — katalog dökümü. Tek seferlik `scripts/ai_katalog_dokumu.php`
 * betiğinin eklentideki kalıcı karşılığı; AI VPS'teki haftalık eşitleme hattı
 * (`scripts/ai9/katalog_cek.py`) bunu imzalı GET ile çeker.
 *
 * SALT OKUNUR: hiçbir tabloya yazmaz. Döküm biçimi betikle birebir aynıdır
 * (`scripts/ai_eval/dizin_esitle.py plan` girdisi):
 *   gorseller  [image_id, coin_id, image_type, weight, diameter]
 *   makaleler  [article_id, title_tr, title_en, metal, date_from, date_to, mint_name, region_code]
 *   erisilemez [image_id, …]   (coins_images.source_unavailable = 1; G12)
 *
 * İmza galeri uçlarıyla aynı biçimde ama AYRI önek ve AYRI sırla ('ai|…', secrets.php
 * 'ai_sync_secret'): bu sır yalnız dökümü açar, galeri yazma uçlarını açamaz.
 * Saf (DB'siz) kısım tests/Ai/AiCatalogSignTest.php ile sınanır.
 *
 * @since 1.22.0
 */

defined('_JEXEC') or die;

final class NumisTRAiCatalog
{
    public const MIN_SECRET_LEN = 32;

    // ------------------------------------------------------------------ imza ---

    public static function payload(string $method, string $action, int $ts, string $body): string
    {
        return 'ai|' . strtoupper($method) . '|' . $action . '|' . $ts . '|' . hash('sha256', $body);
    }

    public static function sign(string $method, string $action, int $ts, string $body, string $secret): string
    {
        return hash_hmac('sha256', self::payload($method, $action, $ts, $body), $secret);
    }

    /** Sır kısa/boşsa uç KAPALI sayılır (false). Zaman damgası ±$skew sn dışında ise tekrar oynatma reddi. */
    public static function verify(string $method, string $action, int $ts, string $body, string $sig, string $secret, int $skew = 300, ?int $now = null): bool
    {
        if (strlen($secret) < self::MIN_SECRET_LEN || $sig === '' || $ts <= 0) {
            return false;
        }
        if (abs(($now ?? time()) - $ts) > $skew) {
            return false;
        }
        return hash_equals(self::sign($method, $action, $ts, $body, $secret), strtolower($sig));
    }

    // ---------------------------------------------------------------- döküm ---

    /** Betikle aynı üç sorgu (29 Eyl phpMyAdmin dökümü ve 16 Eki v5.1 ile aynı küme). */
    public static function dump($db): array
    {
        $gorseller = $db->setQuery(
            'SELECT ci.image_id, ci.coin_id, ci.image_type, ci.weight, ci.diameter
               FROM coins_images ci JOIN ' . $db->quoteName('#__numistr_variants_public', 'v') . ' ON v.article_id = ci.coin_id
              ORDER BY ci.image_id'
        )->loadRowList();

        // yalnız görselli makaleler (görünüm sayfa/yerleşim/EN kopyalarını da içerir)
        $makaleler = $db->setQuery(
            'SELECT v.article_id, v.title_tr, v.title_en, v.metal, v.date_from, v.date_to, v.mint_name, v.region_code
               FROM ' . $db->quoteName('#__numistr_variants_public', 'v') . '
              WHERE EXISTS (SELECT 1 FROM coins_images ci WHERE ci.coin_id = v.article_id)
              ORDER BY v.article_id'
        )->loadRowList();

        $erisilemez = $db->setQuery(
            'SELECT ci.image_id
               FROM coins_images ci JOIN ' . $db->quoteName('#__numistr_variants_public', 'v') . ' ON v.article_id = ci.coin_id
              WHERE ci.source_unavailable = 1
              ORDER BY ci.image_id'
        )->loadColumn();

        return self::shape($gorseller, $makaleler, $erisilemez, gmdate('Y-m-d\TH:i:s\Z'));
    }

    /** Ham satırlar → döküm. Satırlar sayısal dizi olarak geçer (betikteki PDO::FETCH_NUM ile aynı). */
    public static function shape(array $gorseller, array $makaleler, array $erisilemez, string $olusturma): array
    {
        return [
            'olusturma'  => $olusturma,
            'kaynak'     => 'coins_images JOIN numistr_variants_public (plg_webservices_numistr /v1/admin/ai/catalog)',
            'alanlar_g'  => ['image_id', 'coin_id', 'image_type', 'weight', 'diameter'],
            'alanlar_m'  => ['article_id', 'title_tr', 'title_en', 'metal', 'date_from', 'date_to', 'mint_name', 'region_code'],
            'gorseller'  => array_map('array_values', $gorseller),
            'makaleler'  => array_map('array_values', $makaleler),
            'erisilemez' => array_map('intval', array_values($erisilemez)),
        ];
    }
}
