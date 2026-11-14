<?php
/**
 * NumistrCheckoutGuard — kart deneme savunmasının karar testleri (Joomla'sız).
 *
 *   php system/numistrbilling/tests/CheckoutGuardTest.php
 *
 * Senaryolar 2026-11-14 olayından: tek hesap 39 form (uid 941), tek IP 65 istek,
 * 29 tek kullanımlık hesap (fldubai.com, grabmail.io).
 */

define('_JEXEC', 1);
require_once __DIR__ . '/../src/CheckoutGuard.php';

$G    = 'NumistrCheckoutGuard';
$pass = 0;
$fail = 0;

function check(string $name, bool $ok): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}\n";
    }
}

$none = [];

// ------------------------------------------------------------- izin verilir ---
check('ilk deneme (hiç kayıt yok) -> izin', $G::decide([], $none) === '');
check('hesapta 2 form, IP 2 -> izin', $G::decide(['user_day' => 2, 'ip_hour' => 2, 'ip_day' => 2, 'global_hour' => 2, 'global_day' => 2], $none) === '');
check('1 başarısız kart -> izin', $G::decide(['fail_user_day' => 1, 'fail_ip_day' => 1], $none) === '');

// ------------------------------------------------------- olay senaryoları ---
check('uid 941: 3 formdan sonra hesap sınırı', $G::decide(['user_day' => 3], $none) === 'user_limit');
check('uid 941: 39 form -> engel', $G::decide(['user_day' => 39], $none) !== '');
check('aynı IP yeni hesap: saatte 3 -> ip_limit', $G::decide(['user_day' => 0, 'ip_hour' => 3, 'ip_day' => 3], $none) === 'ip_limit');
check('aynı IP gün içinde 5 -> ip_limit', $G::decide(['ip_hour' => 1, 'ip_day' => 5], $none) === 'ip_limit');
// 1.5.1 — devre kesici IP bazlı: site geneli sayım kimseyi engellemez, yalnız uyarı
check('site saatte 10 -> gerçek müşteri ENGELLENMEZ', $G::decide(['global_hour' => 10, 'global_day' => 10], $none) === '');
check('site günde 500 -> gerçek müşteri ENGELLENMEZ', $G::decide(['global_hour' => 0, 'global_day' => 500], $none) === '');
check('site saatte 10 -> uyarı (globalSpike)', $G::globalSpike(['global_hour' => 10], $none) === true);
check('site günde 30 -> uyarı', $G::globalSpike(['global_day' => 30], $none) === true);
check('site saatte 9 / günde 29 -> uyarı yok', $G::globalSpike(['global_hour' => 9, 'global_day' => 29], $none) === false);
check('IP değiştiren saldırgan: her yeni IP 3. formda durur', $G::decide(['global_hour' => 40, 'ip_hour' => 3, 'ip_day' => 3], $none) === 'ip_limit');
check('IP değiştiren saldırgan: her yeni IP 2 başarısız kartta durur', $G::decide(['global_day' => 40, 'fail_ip_day' => 2], $none) === 'failures');
check('hesapta 2 başarısız kart -> failures', $G::decide(['fail_user_day' => 2], $none) === 'failures');
check('IP\'de 2 başarısız kart (yeni hesap) -> failures', $G::decide(['fail_ip_day' => 2], $none) === 'failures');
check('site sayımı yüksek + hesap sınırı -> user_limit', $G::decide(['global_hour' => 50, 'user_day' => 50], $none) === 'user_limit');

// ---------------------------------------------------------------- ayarlar ---
check('ayar 0 -> varsayılan (sınırsız değil)', $G::limits(['limit_user_day' => 0])['limit_user_day'] === 3);
check('ayar boş metin -> varsayılan', $G::limits(['limit_ip_hour' => ''])['limit_ip_hour'] === 3);
check('ayar 5 -> 5', $G::limits(['limit_user_day' => '5'])['limit_user_day'] === 5);
check('ayarla gevşetilen sınır uygulanır', $G::decide(['user_day' => 3], ['limit_user_day' => 5]) === '');
check('negatif sayım 0 sayılır', $G::decide(['user_day' => -10], $none) === '');

// ------------------------------------------------------------- alan adları ---
check('fldubai.com engelli', $G::emailDomainBlocked('numistra6eba706ec02@fldubai.com'));
check('grabmail.io engelli', $G::emailDomainBlocked('numistr-12a86581178295@grabmail.io'));
check('büyük harf engelli', $G::emailDomainBlocked('X@GRABMAIL.IO'));
check('alt alan adı engelli', $G::emailDomainBlocked('a@mx.mailinator.com'));
check('gmail serbest', !$G::emailDomainBlocked('someone@gmail.com'));
check('benzer ad serbest (notgrabmail.io)', !$G::emailDomainBlocked('a@notgrabmail.io'));
check('ek liste (satır/virgül) çalışır', $G::emailDomainBlocked('a@kotu.example', "foo.com,\nkotu.example"));
check('ek listede @ ve nokta temizlenir', $G::emailDomainBlocked('a@kotu.example', '@kotu.example.'));
check('@ yoksa false', !$G::emailDomainBlocked('gecersiz'));
check('boş alan adı false', !$G::emailDomainBlocked('a@'));

// ----------------------------------------------------- iyzico sonuç durumu ---
check('SUCCESS -> auth_success', $G::authResult('SUCCESS') === 'auth_success');
check('success (küçük) -> auth_success', $G::authResult('success') === 'auth_success');
check('FAILURE -> auth_failure', $G::authResult('FAILURE') === 'auth_failure');
check('bilinmeyen -> boş', $G::authResult('INIT_THREEDS') === '');

echo "\n{$pass} geçti, {$fail} kaldı\n";
exit($fail === 0 ? 0 : 1);
