<?php
/**
 * S18 — magaza "revoke" olayi web (iyzico) donemini ezmemeli.
 *
 * 2026-09-13'te bulundu: RevenueCat webhook'u `revoke` olayinda dogrudan
 * revokePro() cagiriyordu ve kullanicinin web tarafinda ODENMIS, SUREN bir
 * donemi olup olmadigina bakmiyordu. Iki odeme kanali (iyzico ve magaza)
 * bagimsiz; hicbiri digerini gormuyor. Ters yonde koruma zaten vardi
 * (numistrbilling housekeeping -> hasActivePlayEntitlement), bu yon eksikti.
 *
 * Ayni kusurun istemci ayagi 2026-09-12'de duzeltildi (S17): uygulama web
 * uyeligini gormedigi icin paywall aciyor ve ikinci satin almaya izin veriyordu.
 *
 * Burada DB'ye dokunan sorgu degil, KARAR test edilir: keepsProFromWeb().
 */

require_once $root . '/controllers/BillingController.php';

$C = 'BillingController';

// Kira (lease) modeli: iyzico IPTALLERDE webhook gondermiyor (destek teyidi
// 27.08.2026), bu yuzden asil dogruluk kaynagi donem sonu.
$now = strtotime('2026-09-13 12:00:00');

// ---------------------------------------------------- hak korunmali (true) ---
check(
    'gelecekteki donem + ACTIVE -> hak korunur',
    $C::keepsProFromWeb('2026-09-25 22:03:05', 'ACTIVE', $now) === true
);

// Kullanici 900'un canli hali: 26 Agustos'ta iptal etti ama 25 Eylul'e kadar odedi.
check(
    'gelecekteki donem + CANCELED -> hak korunur (kira modeli)',
    $C::keepsProFromWeb('2026-09-25 22:03:05', 'CANCELED', $now) === true
);

check(
    'gelecekteki donem + UNPAID -> hak korunur (donem sonu asil kaynak)',
    $C::keepsProFromWeb('2026-09-20 00:00:00', 'UNPAID', $now) === true
);

check(
    'bir saat sonrasi bile hak korunur',
    $C::keepsProFromWeb('2026-09-13 13:00:00', 'ACTIVE', $now) === true
);

// ------------------------------------------------- hak korunmamali (false) ---
// Kullanici 902'nin canli hali: donemi 1 Agustos'ta bitmis.
check(
    'gecmis donem -> hak korunmaz',
    $C::keepsProFromWeb('2026-08-01 00:00:00', 'CANCELED', $now) === false
);

// EXPIRED, supurme/uzlastirmanin "odeme yok" teyidinden sonra yazdigi nihai
// durum; donem sonu ileri gorunse bile bayat veridir.
check(
    'EXPIRED, donem ileri gorunse bile hak korunmaz',
    $C::keepsProFromWeb('2026-12-31 00:00:00', 'EXPIRED', $now) === false
);

check(
    'kucuk harfli expired de sayilir',
    $C::keepsProFromWeb('2026-12-31 00:00:00', 'expired', $now) === false
);

check('tarih yok -> hak korunmaz', $C::keepsProFromWeb(null, 'ACTIVE', $now) === false);
check('bos tarih -> hak korunmaz', $C::keepsProFromWeb('', 'ACTIVE', $now) === false);
check('cozulemeyen tarih -> hak korunmaz', $C::keepsProFromWeb('yok', 'ACTIVE', $now) === false);

// Sinir: tam simdi = gecmis sayilir (kesin buyuk olmali)
check('tam simdi -> hak korunmaz', $C::keepsProFromWeb('2026-09-13 12:00:00', 'ACTIVE', $now) === false);
