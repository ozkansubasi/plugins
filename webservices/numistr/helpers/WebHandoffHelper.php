<?php
defined('_JEXEC') or die;

/**
 * Uygulamadan siteye tek kullanımlık geçiş kodu — 1.28.0 (22 Kas 2026).
 *
 * Sorun: uygulama Auth0'a geçici oturumla girer (preferEphemeralSession), tarayıcıda Auth0 çerezi kalmaz.
 * Uygulamada girişli kullanıcı "Aboneliği yönet" ile sitenin Hesabım sayfasını açınca misafir görünüyordu.
 *
 * Akış: uygulama `POST /v1/user/web-handoff` (Bearer) → bu sınıf kod üretir, DB'ye yalnız özetini yazar →
 * yanıt `url` = site `index.php?option=com_ajax&plugin=numistrauth&format=raw&task=handoff&code=…` →
 * uygulama bu adresi tarayıcıda açar → plg_system_numistrauth kodu bir kez tüketip oturumu açar, dönüş yoluna gider.
 *
 * Güvenlik: 32 bayt CSPRNG kod (64 onaltılık), DB'de yalnız sha256, 120 sn ömür, tek kullanım (sitede koşullu
 * UPDATE), kullanıcı başına saatte en çok 10 kod, dönüş yolu yalnız `/tr/…` ya da `/en/…` göreli yol.
 * Kodu tüketen taraf: plugins/system/numistrauth (tablo adı ve özet yöntemi orada da aynı).
 */
final class WebHandoffHelper
{
    public const TABLE = 'numistr_web_handoff';

    /** @return array{site_root:string, ttl:int, per_hour:int, ip_per_minute:int} */
    public static function settings(array $config): array
    {
        $c    = (array) ($config['WEB_HANDOFF'] ?? []);
        $root = trim((string) ($c['site_root'] ?? 'https://www.numistr.org'));

        if (!preg_match('#^https://[a-z0-9.-]+$#i', rtrim($root, '/'))) {
            $root = 'https://www.numistr.org';
        }

        return [
            'site_root'     => rtrim($root, '/'),
            'ttl'           => max(30, min(600, (int) ($c['ttl'] ?? 120))),
            'per_hour'      => max(1, (int) ($c['per_hour'] ?? 10)),
            'ip_per_minute' => max(1, (int) ($c['ip_per_minute'] ?? 20)),
        ];
    }

    public static function newCode(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function lang(string $lang): string
    {
        return strtolower(substr(trim($lang), 0, 2)) === 'en' ? 'en' : 'tr';
    }

    /** Yalnız `/tr/…` ya da `/en/…` göreli yol; değilse dile göre Hesabım. Çift eğik çizgi, `..`, sorgu yok. */
    public static function safeReturn(string $return, string $lang): string
    {
        $return = trim($return);

        if ($return !== '' && strlen($return) <= 200
            && preg_match('#^/(tr|en)(/[A-Za-z0-9_\-]+)*/?$#', $return)) {
            return $return;
        }

        return self::lang($lang) === 'en' ? '/en/my-account' : '/tr/hesabim';
    }

    public static function url(string $siteRoot, string $code, string $lang = 'tr'): string
    {
        return rtrim($siteRoot, '/') . '/index.php?option=com_ajax&plugin=numistrauth&format=raw&task=handoff&code=' . $code
            . '&lang=' . self::lang($lang);
    }

    /** Gövde: {"return": "/tr/hesabim", "lang": "tr"} — ikisi de isteğe bağlı. */
    public static function parseBody(string $raw): array
    {
        $j = json_decode($raw, true);

        return [
            'return' => is_array($j) && isset($j['return']) && is_string($j['return']) ? $j['return'] : '',
            'lang'   => is_array($j) && isset($j['lang']) && is_string($j['lang']) ? $j['lang'] : 'tr',
        ];
    }

    /**
     * Kod üretir ve kaydeder.
     *
     * @param \Joomla\Database\DatabaseInterface $db
     * @return array{status:int, code:string, body?:array, retry_after?:int}
     */
    public static function issue($db, array $settings, int $userId, string $returnPath, int $now, string $lang = 'tr'): array
    {
        if ($userId <= 0) {
            return ['status' => 401, 'code' => 'UNAUTHORIZED'];
        }

        $t = $db->quoteName(self::TABLE);

        // Eski kayıtlar (1 günden eski) her üretimde silinir; tablo küçük kalır.
        $db->setQuery('DELETE FROM ' . $t . ' WHERE created_at < ' . $db->quote(gmdate('Y-m-d H:i:s', $now - 86400)))->execute();

        $db->setQuery('SELECT COUNT(*) FROM ' . $t . ' WHERE user_id = ' . (int) $userId
            . ' AND created_at > ' . $db->quote(gmdate('Y-m-d H:i:s', $now - 3600)));

        if ((int) $db->loadResult() >= $settings['per_hour']) {
            return ['status' => 429, 'code' => 'RATE_LIMITED', 'retry_after' => 3600];
        }

        $code = self::newCode();
        $row  = (object) [
            'user_id'     => $userId,
            'code_hash'   => self::hash($code),
            'return_path' => $returnPath,
            'created_at'  => gmdate('Y-m-d H:i:s', $now),
            'expires_at'  => gmdate('Y-m-d H:i:s', $now + $settings['ttl']),
        ];
        $db->insertObject(self::TABLE, $row);

        return [
            'status' => 200,
            'code'   => 'OK',
            'body'   => ['url' => self::url($settings['site_root'], $code, $lang), 'expires_in' => $settings['ttl']],
        ];
    }
}
