<?php
/**
 * @package     NumisTR Billing (iyzico web subscription)
 * @subpackage  plg_system_numistrbilling
 * @since       1.5.0
 *
 * Kart deneme (carding) savunması — ödeme formu açma KARARI.
 *
 * 2026-11-14 olayı: tek IP'den 31 hesap, 63 ödeme formu; tek hesap 10 dakikada 39 form
 * açıp iyzico'da kart denedi (runbook 2026-11-14-iyzico-kart-deneme-saldirisi.md).
 * Sayımlar eklentide `numistr_billing_attempts` tablosundan toplanır; karar burada,
 * Joomla'sız verilir ki test edilebilsin (tests/CheckoutGuardTest.php).
 */

defined('_JEXEC') or die;

final class NumistrCheckoutGuard
{
    /** Varsayılan sınırlar. Ayar 1'den küçükse varsayılan kullanılır (yanlışlıkla sınırsız açılmasın). */
    public const DEFAULTS = [
        'limit_user_day'    => 3,  // hesap başına 24 saatte açılan ödeme formu
        'limit_ip_hour'     => 3,  // IP başına 1 saatte
        'limit_ip_day'      => 5,  // IP başına 24 saatte
        'limit_global_hour' => 10, // tüm site 1 saatte (devre kesici)
        'limit_global_day'  => 30, // tüm site 24 saatte (devre kesici)
        'limit_fail_day'    => 2,  // hesap ya da IP başına 24 saatte başarısız kart denemesi
    ];

    /** Bilinen tek kullanımlık e-posta alan adları (ayardan genişletilir). */
    public const DEFAULT_BLOCKED_DOMAINS = [
        'fldubai.com', 'grabmail.io', 'fulldiets.com', 'tanpony.com',
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com',
        '10minutemail.com', 'temp-mail.org', 'tempmail.plus', 'yopmail.com',
        'trashmail.com', 'dispostable.com', 'getnada.com', 'maildrop.cc',
        'moakt.com', 'emailondeck.com', 'mail.tm', 'mohmal.com',
    ];

    /**
     * @param array<string,int> $counts user_day, ip_hour, ip_day, global_hour, global_day,
     *                                  fail_user_day, fail_ip_day
     * @param array<string,mixed> $limits DEFAULTS anahtarları (eklenti ayarlarından)
     *
     * @return string '' = izin; aksi hâlde neden kodu (breaker|failures|user_limit|ip_limit)
     */
    public static function decide(array $counts, array $limits): string
    {
        $l = self::limits($limits);
        $c = static function (string $k) use ($counts): int {
            return max(0, (int) ($counts[$k] ?? 0));
        };

        if ($c('global_hour') >= $l['limit_global_hour'] || $c('global_day') >= $l['limit_global_day']) {
            return 'breaker';
        }

        if ($c('fail_user_day') >= $l['limit_fail_day'] || $c('fail_ip_day') >= $l['limit_fail_day']) {
            return 'failures';
        }

        if ($c('user_day') >= $l['limit_user_day']) {
            return 'user_limit';
        }

        if ($c('ip_hour') >= $l['limit_ip_hour'] || $c('ip_day') >= $l['limit_ip_day']) {
            return 'ip_limit';
        }

        return '';
    }

    /** @return array<string,int> */
    public static function limits(array $params): array
    {
        $out = [];

        foreach (self::DEFAULTS as $k => $def) {
            $v       = (int) ($params[$k] ?? 0);
            $out[$k] = $v >= 1 ? $v : $def;
        }

        return $out;
    }

    /**
     * E-posta, engelli alan adlarından birinde (ya da alt alan adında) mı?
     *
     * @param string $extraList Ayardaki ek liste: satır, virgül ya da boşlukla ayrılmış
     */
    public static function emailDomainBlocked(string $email, string $extraList = ''): bool
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = strtolower(trim(substr($email, $at + 1), " \t\n\r\0\x0B."));

        if ($domain === '') {
            return false;
        }

        $extra = preg_split('/[\s,;]+/', strtolower($extraList), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach (array_merge(self::DEFAULT_BLOCKED_DOMAINS, $extra) as $d) {
            $d = trim($d, '.@ ');

            if ($d === '') {
                continue;
            }

            if ($domain === $d || substr($domain, -strlen('.' . $d)) === '.' . $d) {
                return true;
            }
        }

        return false;
    }

    /**
     * iyzico checkout_form_auth bildiriminden deneme sonucu.
     *
     * @return string 'auth_success' | 'auth_failure' | '' (tanınmadı)
     */
    public static function authResult(string $status): string
    {
        $s = strtoupper(trim($status));

        if ($s === 'SUCCESS') {
            return 'auth_success';
        }

        if ($s === 'FAILURE' || $s === 'FAILED' || $s === 'ERROR') {
            return 'auth_failure';
        }

        return '';
    }
}
