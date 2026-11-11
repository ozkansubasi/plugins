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
check('es_id verilmezse null', $n['es_id'] === null);
[$en, $enH] = NumisTRBlogAdmin::normalize(array_merge($ok, ['language' => 'en-GB', 'es_id' => 34030]), $izinli);
check('en-GB yazıda es_id kabul', $enH === null && $en['es_id'] === 34030, (string) $enH);
[, $trH] = NumisTRBlogAdmin::normalize(array_merge($ok, ['es_id' => 34030]), $izinli);
check('tr-TR yazıda es_id ret', $trH !== null);
[, $tipH] = NumisTRBlogAdmin::normalize(array_merge($ok, ['language' => 'en-GB', 'es_id' => '34030']), $izinli);
check('dizge es_id ret', $tipH !== null);
check('ilişki anahtarı Joomla biçiminde', NumisTRBlogAdmin::iliskiAnahtari(13036, 33943) === md5('{"en-GB":33943,"tr-TR":13036}'));
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

// 1.23.1: yeni makalede com_content modeli iş akışı aşaması için form yükler; API isteğinde JPATH_COMPONENT tanımsızdı
// (canlıda 34038: makale yazıldı, iş akışı ilişkisi yazılmadı, 500). Kaynak düzeyinde koruma: sabitler modelden ÖNCE.
$kaynak = file_get_contents($root . '/helpers/BlogAdminHelper.php');
$sabit  = strpos($kaynak, "'JPATH_COMPONENT' =>");
$model  = strpos($kaynak, "createModel('Article'");
check('1.23.1: JPATH_COMPONENT modelden önce tanımlanıyor', $sabit !== false && $model !== false && $sabit < $model);
check('1.23.1: sabit yalnız tanımsızsa tanımlanıyor', str_contains($kaynak, 'if (!defined($sabit))'));

// ------------------------------------------------- giriş görseli (1.24.3) ---
$gg = json_decode(NumisTRBlogAdmin::girisGorseli('<p>Özet</p>', "<h2>B</h2>\n{numistr_gorsel variant=11615 gorsel=29182,29183}\n<p>x</p>{numistr_gorsel variant=2 gorsel=5}", 'Tanrının eli'), true);
check('ilk figürün ilk görseli', ($gg['image_intro'] ?? '') === 'https://www.numistr.org/index.php?option=com_numistr&view=gorsel&format=raw&id=29182&wm=0', json_encode($gg));
check('alt metni başlık', ($gg['image_intro_alt'] ?? '') === 'Tanrının eli');
check('figür girişteyse de bulunur', strpos(NumisTRBlogAdmin::girisGorseli('{numistr_gorsel gorsel=77}', '', 't'), 'id=77&wm=0') !== false);
check('figür yoksa {}', NumisTRBlogAdmin::girisGorseli('<p>a</p>', '<p>b</p>', 't') === '{}');
check('gorsel parametresi yoksa {}', NumisTRBlogAdmin::girisGorseli('{numistr_gorsel variant=5}', '', 't') === '{}');
check('json geçerli, eğik çizgi kaçışsız', json_decode(NumisTRBlogAdmin::girisGorseli('{numistr_gorsel gorsel=1}', '', 'a"b'), true)['image_intro_alt'] === 'a"b');
