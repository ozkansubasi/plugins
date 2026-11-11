<?php
/**
 * @package     NumisTR E-Bülten
 * @subpackage  plg_system_numistrbulten
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Saf yardımcılar (Joomla'sız; tests/numistrbulten_test.php sınar): girdi doğrulama, MailWizz isteği,
 * MailWizz yanıtının sonuca çevrilmesi, istek sınırı, köken denetimi.
 */

defined('_JEXEC') or die;

class PlgNumistrBultenHelper
{
    public const DILLER = array('tr', 'en');

    /** Ziyaretçiye dönen hata kodları (formdaki metinler bu anahtarlarla eşleşir). */
    public const HATA_EPOSTA = 'gecersiz_eposta';
    public const HATA_ONAY   = 'onay_yok';
    public const HATA_SINIR  = 'sinir';
    public const HATA_SERVIS = 'servis';

    /**
     * Form girdisini doğrular.
     *
     * @param  array  $girdi  email, onay, dil, web_site (tuzak alan)
     *
     * @return array  ['durum' => 'gecerli'|'tuzak'|'hata', 'hata' => ?string, 'email' => string, 'dil' => 'tr'|'en']
     */
    public static function dogrula(array $girdi): array
    {
        $dil = strtolower(trim((string) ($girdi['dil'] ?? '')));
        $dil = in_array($dil, self::DILLER, true) ? $dil : 'en';
        $email = trim((string) ($girdi['email'] ?? ''));
        $sonuc = array('durum' => 'hata', 'hata' => null, 'email' => $email, 'dil' => $dil);

        // Tuzak alan doluysa bot: başarı gibi yanıtlanır, MailWizz'e gitmez.
        if (trim((string) ($girdi['web_site'] ?? '')) !== '') {
            $sonuc['durum'] = 'tuzak';

            return $sonuc;
        }
        if ($email === '' || strlen($email) > 254 || preg_match('/[\x00-\x1F\x7F\s]/', $email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $sonuc['hata'] = self::HATA_EPOSTA;

            return $sonuc;
        }
        if ((string) ($girdi['onay'] ?? '') !== '1') {
            $sonuc['hata'] = self::HATA_ONAY;

            return $sonuc;
        }
        $sonuc['durum'] = 'gecerli';

        return $sonuc;
    }

    /** Dile göre liste kimliği; TR listesi boşsa EN listesine düşmez (yanlış dilde onay e-postası gitmesin). */
    public static function listeUid(array $ayar, string $dil): string
    {
        return trim((string) ($ayar[$dil === 'tr' ? 'list_uid_tr' : 'list_uid_en'] ?? ''));
    }

    /**
     * MailWizz "abone oluştur" isteği: POST {api}/lists/{list_uid}/subscribers.
     * Liste çift onaylıysa onay e-postasını MailWizz gönderir.
     *
     * @return array ['url' => string, 'basliklar' => array, 'govde' => string]
     */
    public static function istek(string $apiUrl, string $listUid, string $apiKey, string $email, string $ip, string $ua): array
    {
        return array(
            'url'       => rtrim($apiUrl, '/') . '/lists/' . rawurlencode($listUid) . '/subscribers',
            'basliklar' => array(
                'X-Api-Key'    => $apiKey,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'govde'     => http_build_query(array(
                'EMAIL'   => $email,
                'details' => array('ip_address' => $ip, 'user_agent' => substr($ua, 0, 255)),
            )),
        );
    }

    /**
     * MailWizz yanıtını sonuca çevirir.
     *
     * @return string 'eklendi' | 'zaten' | 'hata'
     */
    public static function yanit(int $kod, string $govde): string
    {
        $json = json_decode($govde, true);
        $hata = is_array($json) ? $json['error'] ?? '' : '';
        $hata = is_array($hata) ? implode(' ', array_map('strval', self::duzlestir($hata))) : (string) $hata;

        if ($kod >= 200 && $kod < 300 && is_array($json) && ($json['status'] ?? '') === 'success') {
            return 'eklendi';
        }
        // Zaten listede: ziyaretçiye aynı yanıt verilir (adresin listede olup olmadığı dışarı sızmaz).
        if ($kod === 409 || stripos($hata, 'already exists') !== false) {
            return 'zaten';
        }

        return 'hata';
    }

    /**
     * Pencere içindeki istek sayısı sınırı aşıyor mu? (zaman damgaları saniye)
     *
     * @return array ['asildi' => bool, 'kayitlar' => int[] (penceredekiler + bu istek, aşılmadıysa)]
     */
    public static function sinir(array $kayitlar, int $simdi, int $limit, int $pencere = 3600): array
    {
        $kalan = array_values(array_filter(array_map('intval', $kayitlar), static function ($t) use ($simdi, $pencere) {
            return $t > $simdi - $pencere && $t <= $simdi;
        }));
        if ($limit > 0 && count($kalan) >= $limit) {
            return array('asildi' => true, 'kayitlar' => $kalan);
        }
        $kalan[] = $simdi;

        return array('asildi' => false, 'kayitlar' => $kalan);
    }

    /** İstek sitenin kendi sayfasından mı geliyor? Origin varsa o, yoksa Referer; ikisi de yoksa hayır. */
    public static function kokenIzinli(?string $origin, ?string $referer, array $izinliHostlar): bool
    {
        $izinli = array_filter(array_map(static function ($h) {
            return strtolower(trim((string) $h));
        }, $izinliHostlar));
        $kaynak = ($origin !== null && $origin !== '' && $origin !== 'null') ? $origin : (string) $referer;
        $host = strtolower((string) parse_url($kaynak, PHP_URL_HOST));
        $sema = strtolower((string) parse_url($kaynak, PHP_URL_SCHEME));

        return $host !== '' && $sema === 'https' && in_array($host, $izinli, true);
    }

    private static function duzlestir(array $a): array
    {
        $out = array();
        array_walk_recursive($a, static function ($v) use (&$out) {
            $out[] = $v;
        });

        return $out;
    }
}
