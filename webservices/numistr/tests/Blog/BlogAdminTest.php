<?php
/**
 * Blog yazma ucu (1.23.0) — saf (DB'siz) mantık: imza + gövde doğrulaması.
 *
 * İmza vektörü hattın Python tarafıyla ORTAKTIR (scripts/icerik_uretec/yayinla.py::imzala) — biri değişirse
 * bu test kırılır.
 */

require_once $root . '/helpers/BlogAdminHelper.php';
require_once $root . '/helpers/AiCatalogHelper.php';
require_once $root . '/helpers/GalleryAdminHelper.php';

$secret = str_repeat('k', 32) . '-test-secret';
$now    = 1789600000;
$body   = '{"title":"x"}';

// ------------------------------------------------------------------ imza ---
$sig = NumisTRBlogAdmin::sign('POST', 'article', $now, $body, $secret);
check('sabit vektör (Python tarafıyla ortak)', $sig === '866d8051894bfdb6d4db07456a184017e09c0e34d4ba27e3f246717764964002', $sig);
check('geçerli imza kabul', NumisTRBlogAdmin::verify('POST', 'article', $now, $body, $sig, $secret, 300, $now));
check('gövde değişirse ret', !NumisTRBlogAdmin::verify('POST', 'article', $now, '{"title":"y"}', $sig, $secret, 300, $now));
check('eski zaman damgası ret', !NumisTRBlogAdmin::verify('POST', 'article', $now, $body, $sig, $secret, 300, $now + 301));
check('kısa sır ret (uç kapalı)', !NumisTRBlogAdmin::verify('POST', 'article', $now, $body,
    NumisTRBlogAdmin::sign('POST', 'article', $now, $body, str_repeat('a', 31)), str_repeat('a', 31), 300, $now));
check('AI imzası blog ucunu açmaz', !NumisTRBlogAdmin::verify('POST', 'article', $now, $body,
    NumisTRAiCatalog::sign('POST', 'article', $now, $body, $secret), $secret, 300, $now));
check('galeri imzası blog ucunu açmaz', !NumisTRBlogAdmin::verify('POST', 'article', $now, $body,
    NumisTRGalleryAdmin::sign('POST', 'article', $now, $body, $secret), $secret, 300, $now));

// -------------------------------------------------------------- doğrulama ---
$izinli = [35, 36, 37, 38, 39, 40];
$ok = ['title' => 'Büyük Antiokhos', 'alias' => 'buyuk-antiokhos-ve-magnesia', 'introtext' => '<p><em>Özet</em></p>',
       'fulltext' => '<h2>Bölüm</h2><p>Metin</p>', 'catid' => 39, 'state' => 1, 'tags' => [412, 412, 530]];
[$n, $h] = NumisTRBlogAdmin::normalize($ok, $izinli);
check('geçerli gövdede hata yok', $h === null, (string) $h);
check('geçerli gövde', $n['alias'] === 'buyuk-antiokhos-ve-magnesia' && $n['state'] === 1 && $n['language'] === 'tr-TR');
check('yinelenen etiket tekilleşir', $n['tags'] === [412, 530], json_encode($n['tags']));

$hata = function (array $degisim) use ($ok, $izinli): ?string {
    [$veri, $h] = NumisTRBlogAdmin::normalize(array_merge($ok, $degisim), $izinli);
    return $veri === null ? $h : null;
};
check('katalog kategorisi ret (16)', $hata(['catid' => 16]) !== null);
check('izinsiz kategori ret (2)', $hata(['catid' => 2]) !== null);
check('alias Türkçe harf ret', $hata(['alias' => 'büyük-antiokhos']) !== null);
check('alias boşluk/çift tire ret', $hata(['alias' => 'a--b']) !== null && $hata(['alias' => 'a b c']) !== null);
check('state "1" (metin) ret', $hata(['state' => '1']) !== null);
check('state 2 ret', $hata(['state' => 2]) !== null);
check('boş başlık ret', $hata(['title' => '  ']) !== null);
check('boş gövde ret', $hata(['introtext' => '', 'fulltext' => ' ']) !== null);
check('ROOT etiketi (1) ret', $hata(['tags' => [1]]) !== null);
check('metin etiket ret', $hata(['tags' => ['412']]) !== null);
check('bilinmeyen dil ret', $hata(['language' => 'de-DE']) !== null);
check('uzun metadesc ret', $hata(['metadesc' => str_repeat('a', 301)]) !== null);
