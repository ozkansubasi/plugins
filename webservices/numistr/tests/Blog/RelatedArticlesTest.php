<?php
/**
 * İlgili yazılar (1.31.0): saf işlevler. DB'li uçlar modül yokken boş liste dönmeli (uç düşmez).
 */

require_once $root . '/helpers/RelatedArticlesHelper.php';

$R = 'NumisTRRelatedArticles';
check('dil: en → en-GB, diğerleri tr-TR', $R::dil('en') === 'en-GB' && $R::dil('tr') === 'tr-TR' && $R::dil('de') === 'tr-TR');
check('sınır: varsayılan 3, en çok 6', $R::sinir(0) === 3 && $R::sinir(-2) === 3 && $R::sinir(2) === 2 && $R::sinir(50) === 6 && $R::sinir('x') === 3);
check('özet: etiket + varlık + boşluk', $R::ozet("<p>Kyzikos&nbsp;stateri\n\n<b>elektrum</b></p>") === "Kyzikos\u{a0}stateri elektrum"
    || $R::ozet("<p>Kyzikos&nbsp;stateri\n\n<b>elektrum</b></p>") === 'Kyzikos stateri elektrum', json_encode($R::ozet("<p>Kyzikos&nbsp;stateri\n\n<b>elektrum</b></p>")));
$uzun = str_repeat('ş', 250);
check('özet: 200 karakter + ...', $R::ozet($uzun) === str_repeat('ş', 200) . '...');
$html = '<a href="https://numistr.org/tr/anatolian-coins/troas-coins/23547-cn-type-3853">a</a> '
    . '<a href="https://www.numistr.org/en/anatolian-coins/troas-coins/25408-cn-type-3853">b</a> '
    . '<a href="/tr/anatolian-coins/troas-coins/23547-cn-type-3853">tekrar</a> <a href="/tr/sikke-hikayeleri/34036-x">yazı</a>';
check('sikke bağlantıları: sıra, tekrar yok, yazı bağlantısı alınmaz', $R::sikkeBaglantilari($html) === [23547, 25408], json_encode($R::sikkeBaglantilari($html)));
check('sikke bağlantıları: yok → boş', $R::sikkeBaglantilari('<p>sikke yok</p>') === []);
$k = $R::kart(['id' => '34036', 'baslik' => 'İskender', 'introtext' => '<p>Giriş</p>', 'kat' => '', 'catid' => '39', 'created' => '2026-10-17 07:05:00']);
check('kart: liste ucu biçimi', $k === ['id' => 34036, 'title' => 'İskender', 'intro' => 'Giriş', 'category' => 'Blog', 'category_id' => 39,
    'created' => '2026-10-17 07:05:00'], json_encode($k, JSON_UNESCAPED_UNICODE));
check('modül yoksa boş liste (sikke)', $R::forCoin(21188, 'tr') === []);
check('modül yoksa boş liste (yerleşim)', $R::forLocation(31475, 'tr') === []);
check('geçersiz kimlik → boş', $R::forCoin(0, 'tr') === [] && $R::forLocation(0, 'en') === []);
