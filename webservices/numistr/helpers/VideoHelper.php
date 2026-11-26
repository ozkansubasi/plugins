<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2026 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Sayfaya bağlı YouTube videoları (1.30.0, BACKLOG 12b V1) — uygulamadaki küçük resim kartı için.
 *
 * Kaynak tablo `numistr_videolar`: sunucuda saatlik `video_esitle.php` Pazarlama Merkezi'nden doldurur
 * (yalnız herkese açık + gömülebilir videolar). Sitede aynı tabloyu mod_numistr_ilgili 1.3.0 okur.
 * Tablo yoksa ya da okunamazsa boş dizi: yazı / yerleşim ucu hiçbir zaman bu yüzden hata vermez.
 * Uygulama videoyu gömmez; kart YouTube'u dış uygulamada açar (watch_url).
 *
 * @since 1.30.0
 */
class NumisTRVideos
{
    public const TABLE = 'numistr_videolar';
    public const LIMIT = 2;

    /** Tablo satırı → uç biçimi. Geçersiz kimlik → null. $root: site kökü (sonda / yok). */
    public static function format(array $r, string $root): ?array
    {
        $yt = (string) ($r['youtube_id'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $yt)) {
            return null;
        }

        $format = ($r['bicim'] ?? '') === 'video' ? 'video' : 'shorts';
        $thumb  = (string) ($r['kucuk_resim'] ?? '');
        $sure   = (int) ($r['sure_sn'] ?? 0);
        $tarih  = (string) ($r['yayin_tarihi'] ?? '');

        return [
            'youtube_id'    => $yt,
            'format'        => $format,
            'title'         => trim((string) ($r['baslik'] ?? '')),
            'credit'        => trim((string) ($r['kunye'] ?? '')),
            'duration_sec'  => $sure > 0 ? $sure : null,
            'thumbnail_url' => preg_match('~^images/videolar/[A-Za-z0-9_-]{11}\.jpg$~', $thumb) ? rtrim($root, '/') . '/' . $thumb : null,
            'watch_url'     => $format === 'shorts' ? 'https://www.youtube.com/shorts/' . $yt : 'https://www.youtube.com/watch?v=' . $yt,
            'published_at'  => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tarih) ? str_replace(' ', 'T', $tarih) . 'Z' : null,
        ];
    }

    /** Makalenin videoları (sayfa dilinde): önce 16:9, sonra en yeni; en çok LIMIT. */
    public static function forArticle(int $articleId, string $lang, string $root): array
    {
        if ($articleId <= 0) {
            return [];
        }

        try {
            $db = Factory::getDbo();
            $q  = $db->getQuery(true)
                ->select($db->quoteName(['youtube_id', 'bicim', 'baslik', 'kunye', 'sure_sn', 'kucuk_resim', 'yayin_tarihi']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('makale_id') . ' = ' . (int) $articleId)
                ->where($db->quoteName('dil') . ' = ' . $db->quote($lang === 'en' ? 'en' : 'tr'))
                ->order($db->quoteName('bicim') . ' = ' . $db->quote('video') . ' DESC, ' . $db->quoteName('yayin_tarihi') . ' DESC');
            $db->setQuery($q, 0, self::LIMIT);
            $rows = (array) $db->loadAssocList();
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($r) use ($root) {
            return self::format((array) $r, $root);
        }, $rows)));
    }
}
