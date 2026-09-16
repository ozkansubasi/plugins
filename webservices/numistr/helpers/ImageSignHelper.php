<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * ADR-006 Faz 2 — Pro "filigransız görsel" için imzalı URL.
 *
 * Neden imzalı URL: görsel bileşeni (com_numistr view=gorsel) bugüne kadar hiç kimlik
 * doğrulamadı ve görseller CachedNetworkImage/<img> gibi header taşımayan yükleyicilerle
 * çekiliyor. Kimlik ve Pro kararı zaten JWT'nin çözüldüğü bu plugin'de verilir; bileşen
 * yalnızca süreli bir HMAC imzasını doğrular. Böylece bileşene oturum/Bearer eklenmez,
 * URL görsel id'sine ve kullanıcıya bağlıdır ve TTL sonunda ölür (toplu indirme koruması).
 *
 * Aynı algoritma com_numistr/views/gorsel/view.raw.php içinde `numistr_hd_verify()` olarak
 * tekrarlanır — iki uzantı arasında require bağı bilinçli olarak KURULMADI (2026-07-08
 * korumasız require kesintisi dersi). İki kopya birlikte değişir; test vektörü:
 * tests/Images/HdSignatureTest.php
 */

defined('_JEXEC') or die;

final class NumisTRImageSign
{
    public const MODE_HD = 2;

    public static function payload(int $imageId, int $userId, int $exp): string
    {
        return 'hd|' . $imageId . '|' . $userId . '|' . $exp;
    }

    /** base64url(HMAC-SHA256(payload, secret)) — padding yok, URL-güvenli. */
    public static function sign(int $imageId, int $userId, int $exp, string $secret): string
    {
        $raw = hash_hmac('sha256', self::payload($imageId, $userId, $exp), $secret, true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function verify(int $imageId, int $userId, int $exp, string $sig, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $sig === '' || $imageId <= 0 || $userId <= 0) {
            return false;
        }
        if ($exp <= ($now ?? time())) {
            return false;
        }
        return hash_equals(self::sign($imageId, $userId, $exp, $secret), $sig);
    }

    /** Bileşen URL'sine eklenecek sorgu parametreleri. */
    public static function query(int $imageId, int $userId, int $ttl, string $secret, ?int $now = null): array
    {
        $exp = ($now ?? time()) + max(60, $ttl);
        return [
            'wm'  => self::MODE_HD,
            'u'   => $userId,
            'exp' => $exp,
            'sig' => self::sign($imageId, $userId, $exp, $secret),
        ];
    }
}
