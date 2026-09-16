<?php
/**
 * ADR-006 Faz 2 — Pro filigransız görsel imzası.
 *
 * Aynı algoritma iki yerde yaşıyor: plugin (NumisTRImageSign) ve bileşen
 * (com_numistr view.raw.php numistr_hd_verify). Buradaki sabit vektör iki
 * tarafın ayrışmadığını yakalar: bileşen kopyası değişirse bu vektör de
 * değişmek zorunda kalır ve fark görünür olur.
 */

require_once $root . '/helpers/ImageSignHelper.php';

$secret = 'test-secret-do-not-use';
$now    = 1789545315; // 2026-09-16

// ------------------------------------------------------------ sabit vektör ---
$sig = NumisTRImageSign::sign(113346, 42, $now + 900, $secret);
check('imza deterministik ve URL-güvenli', (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $sig), $sig);
check('sabit vektör (bileşen kopyasıyla ortak)', $sig === 'Xh4AvZHGdC6ggLuuHuGu86seb2KbLNf7FOYq8fkiDHg', $sig);

// ------------------------------------------------------------- doğrulama -----
check('geçerli imza kabul', NumisTRImageSign::verify(113346, 42, $now + 900, $sig, $secret, $now));
check('süresi dolmuş ret', !NumisTRImageSign::verify(113346, 42, $now - 1, NumisTRImageSign::sign(113346, 42, $now - 1, $secret), $secret, $now));
check('tam sınırda (exp == now) ret', !NumisTRImageSign::verify(113346, 42, $now, NumisTRImageSign::sign(113346, 42, $now, $secret), $secret, $now));
check('başka görsel id ret', !NumisTRImageSign::verify(113347, 42, $now + 900, $sig, $secret, $now));
check('başka kullanıcı ret', !NumisTRImageSign::verify(113346, 43, $now + 900, $sig, $secret, $now));
check('yanlış sır ret', !NumisTRImageSign::verify(113346, 42, $now + 900, $sig, 'other', $now));
check('boş sır ret (özellik kapalı)', !NumisTRImageSign::verify(113346, 42, $now + 900, $sig, '', $now));
check('boş imza ret', !NumisTRImageSign::verify(113346, 42, $now + 900, '', $secret, $now));
check('kullanıcı 0 ret', !NumisTRImageSign::verify(113346, 0, $now + 900, NumisTRImageSign::sign(113346, 0, $now + 900, $secret), $secret, $now));
check('imza üzerinde oynama ret', !NumisTRImageSign::verify(113346, 42, $now + 900, substr($sig, 0, -1) . 'A', $secret, $now));

// ------------------------------------------------------------- query() -------
$q = NumisTRImageSign::query(113346, 42, 900, $secret, $now);
check('query wm=2', ($q['wm'] ?? null) === 2);
check('query exp = now + ttl', ($q['exp'] ?? null) === $now + 900);
check('query ttl tabanı 60 sn', NumisTRImageSign::query(1, 1, 5, $secret, $now)['exp'] === $now + 60);
check('query imzası doğrulanır', NumisTRImageSign::verify(113346, 42, $q['exp'], $q['sig'], $secret, $now));

// ------------------------------------------- bileşen kopyasıyla çapraz kontrol ---
// com_numistr view.raw.php ayrı depoda (numistr/). Çalışma alanında ../../../numistr/...
// yolundan, CI/VPS'te NUMISTR_COMPONENT_RAW ortam değişkeninden bulunur; yoksa atlanır.
$rawPath = getenv('NUMISTR_COMPONENT_RAW') ?: ($root . '/../../../numistr/components/com_numistr/views/gorsel/view.raw.php');
if (is_file($rawPath)) {
    $src = (string) file_get_contents($rawPath);
    // Yalnız imza fonksiyonlarını çıkar (dosyanın geri kalanı Joomla ister); farklı isimle tanımla.
    $ok = preg_match('/function numistr_hd_sign\(.*?\n}\n/s', $src, $m1)
       && preg_match('/function numistr_hd_verify\(.*?\n}\n/s', $src, $m2);
    check('bileşen imza fonksiyonları bulundu', (bool) $ok, $rawPath);
    if ($ok) {
        eval(str_replace(['numistr_hd_sign', 'numistr_hd_verify'], ['cmp_hd_sign', 'cmp_hd_verify'], $m1[0] . $m2[0]));
        check('bileşen kopyası aynı imzayı üretiyor', cmp_hd_sign(113346, 42, $now + 900, $secret) === $sig);
        check('bileşen kopyası plugin imzasını kabul ediyor', cmp_hd_verify(113346, 42, $now + 900, $sig, $secret, $now));
        check('bileşen kopyası süresi dolmuşu reddediyor', !cmp_hd_verify(113346, 42, $now, NumisTRImageSign::sign(113346, 42, $now, $secret), $secret, $now));
        check('bileşen kopyası boş sırrı reddediyor', !cmp_hd_verify(113346, 42, $now + 900, $sig, '', $now));
    }
} else {
    check('bileşen kopyası çapraz kontrolü (dosya yok, atlandı)', true, $rawPath);
}
