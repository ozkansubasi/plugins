<?php
/**
 * AI dizini eşitlemesi (AI9) katalog dökümü ucu — saf (DB'siz) mantık.
 *
 * İmza vektörü hattın Python tarafıyla ORTAKTIR
 * (scripts/ai9/katalog_cek.py::imzala) — biri değişirse bu test kırılır.
 */

require_once $root . '/helpers/AiCatalogHelper.php';
require_once $root . '/helpers/GalleryAdminHelper.php';

$secret = str_repeat('k', 32) . '-test-secret';
$now    = 1789600000;

// ------------------------------------------------------------------ imza ---
$sig = NumisTRAiCatalog::sign('GET', 'catalog', $now, '', $secret);
check('imza 64 hex', (bool) preg_match('/^[0-9a-f]{64}$/', $sig), $sig);
check('sabit vektör (Python tarafıyla ortak)', $sig === '6d90d51dbd7853556769612c5a644d4c233db64aca664c78e91627f6e5962e1a', $sig);
check('geçerli imza kabul', NumisTRAiCatalog::verify('GET', 'catalog', $now, '', $sig, $secret, 300, $now));
check('büyük harfli hex kabul', NumisTRAiCatalog::verify('GET', 'catalog', $now, '', strtoupper($sig), $secret, 300, $now));
check('başka yöntem ret', !NumisTRAiCatalog::verify('POST', 'catalog', $now, '', $sig, $secret, 300, $now));
check('başka eylem ret', !NumisTRAiCatalog::verify('GET', 'targets', $now, '', $sig, $secret, 300, $now));
check('eski zaman damgası ret (tekrar oynatma)', !NumisTRAiCatalog::verify('GET', 'catalog', $now, '', $sig, $secret, 300, $now + 301));
check('gelecek zaman damgası ret', !NumisTRAiCatalog::verify('GET', 'catalog', $now, '', $sig, $secret, 300, $now - 301));
check('yanlış sır ret', !NumisTRAiCatalog::verify('GET', 'catalog', $now, '', $sig, str_repeat('x', 40), 300, $now));
check('boş sır ret (uç kapalı)', !NumisTRAiCatalog::verify('GET', 'catalog', $now, '', NumisTRAiCatalog::sign('GET', 'catalog', $now, '', ''), '', 300, $now));
check('kısa sır ret (31 karakter)', !NumisTRAiCatalog::verify('GET', 'catalog', $now, '', NumisTRAiCatalog::sign('GET', 'catalog', $now, '', str_repeat('a', 31)), str_repeat('a', 31), 300, $now));
check('boş imza ret', !NumisTRAiCatalog::verify('GET', 'catalog', $now, '', '', $secret, 300, $now));

// Ayrı önek: aynı sırla bile galeri imzası döküm ucunu, döküm imzası galeri ucunu açmaz.
$gSig = NumisTRGalleryAdmin::sign('GET', 'catalog', $now, '', $secret);
check('galeri imzası döküm ucunu açmaz', !NumisTRAiCatalog::verify('GET', 'catalog', $now, '', $gSig, $secret, 300, $now));
check('döküm imzası galeri ucunu açmaz', !NumisTRGalleryAdmin::verify('GET', 'targets', $now, '', NumisTRAiCatalog::sign('GET', 'targets', $now, '', $secret), $secret, 300, $now));

// ---------------------------------------------------------------- biçim ---
$d = NumisTRAiCatalog::shape(
    [['image_id' => '7', 'coin_id' => '3404', 'image_type' => 'on', 'weight' => '8.1', 'diameter' => null]],
    [['3404', 'Başlık', 'Title', 'AR', '-400', '-380', null, 'karya']],
    ['12', 13],
    '2026-10-17T01:00:00Z'
);
check('üst anahtarlar betikle aynı', array_keys($d) === ['olusturma', 'kaynak', 'alanlar_g', 'alanlar_m', 'gorseller', 'makaleler', 'erisilemez']);
check('görsel satırı sayısal dizi', $d['gorseller'][0] === ['7', '3404', 'on', '8.1', null]);
check('makale satırı 8 alan', count($d['makaleler'][0]) === 8 && $d['makaleler'][0][7] === 'karya');
check('erişilemez tamsayı', $d['erisilemez'] === [12, 13]);
check('JSON gidiş-dönüş Türkçe korunur', json_decode(json_encode($d, JSON_UNESCAPED_UNICODE), true)['makaleler'][0][1] === 'Başlık');
