<?php
/**
 * plg_system_numistrseo — saf yardımcıların testi (Joomla'sız): php plugins/system/numistrseo/tests/numistrseo_test.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../helper.php';

$ok = 0; $fail = 0;
function check($ad, $kosul) { global $ok, $fail; if ($kosul) { $ok++; echo "  ok   $ad\n"; } else { $fail++; echo "  FAIL $ad\n"; } }
$H = 'PlgNumistrSeoHelper';

// ------------------------------------------------------------------- eş seçimi ---
check('aday yok → null', $H::esSec(array(), 'Price 2528') === null);
check('tek aday → o', $H::esSec(array(array('id' => 27212, 'title' => 'Price 2410')), 'Price 2528')['id'] === 27212);
$c = array(array('id' => 17262, 'title' => 'Wakhssepddimi Tlos Konuk 2016 Figs. 8-10'), array('id' => 16599, 'title' => 'Wakhssepddimi Tlos Konuk 2016 Figs. 8-10'));
check('mükerrer EN kopyalar → en küçük kimlik (kararlı)', $H::esSec($c, 'Wakhssepddimi Tlos Konuk 2016 Figs. 8-10')['id'] === 16599);
$c2 = array(array('id' => 100, 'title' => 'Price 2528 (2)'), array('id' => 200, 'title' => 'Price 2528'));
check('başlığı birebir aynı olan önce (kimlik büyük olsa da)', $H::esSec($c2, 'Price 2528')['id'] === 200);
check('başlık boşluk farkı yok sayılır', $H::esSec($c2, ' Price 2528 ')['id'] === 200);

// ------------------------------------------------------- dil değiştirici ---
$tr = '<div class="uk-panel mod-languages">  <ul class="uk-subnav">  <li class="uk-active"> <a style="display: flex !important;" href="https://www.numistr.org/tr/anatolian-coins/aeolis-coins/3843-price-2528"> TR </a> </li>'
    . '  <li > <a style="display: flex !important;" href="/en/"> EN </a> </li>  </ul> </div>';
$sayfa = '<a href="/en/">EN anasayfa</a>' . $tr . $tr . '<ul><li><a href="/en/"> EN </a></li></ul>';
$out = $H::dilDegistirici($sayfa, array('EN' => 'https://www.numistr.org/en/anatolian-coins/aeolis-coins/27212-price-2410'));
check('her iki mod-languages bloğunda EN bağlantısı eşe gider', substr_count($out, 'href="https://www.numistr.org/en/anatolian-coins/aeolis-coins/27212-price-2410"') === 2);
check('etkin dil (TR) bağlantısı aynen', substr_count($out, 'href="https://www.numistr.org/tr/anatolian-coins/aeolis-coins/3843-price-2528"') === 2);
check('blok dışındaki /en/ bağlantıları dokunulmaz', substr_count($out, '<a href="/en/">EN anasayfa</a>') === 1 && substr_count($out, '<ul><li><a href="/en/"> EN </a></li></ul>') === 1);
check('başka bir şey değişmez (yalnız iki href)', strlen($out) - strlen($sayfa) === 2 * (strlen('https://www.numistr.org/en/anatolian-coins/aeolis-coins/27212-price-2410') - strlen('/en/')));
check('eşleme boşsa sayfa aynen', $H::dilDegistirici($sayfa, array()) === $sayfa);
check('mod-languages yoksa sayfa aynen', $H::dilDegistirici('<p>x</p>', array('EN' => 'y')) === '<p>x</p>');
$en = str_replace(array('<li class="uk-active">', '<li >'), array('<li class="tmp">', '<li class="uk-active">'), $tr);
$en = str_replace('<li class="tmp">', '<li >', $en);
$out2 = $H::dilDegistirici($en, array('TR' => 'https://www.numistr.org/tr/x/3843-price-2528'));
check('EN sayfada TR bağlantısı eşe gider, EN (etkin) aynen', strpos($out2, 'href="https://www.numistr.org/tr/x/3843-price-2528"> TR') !== false && strpos($out2, 'href="/en/"> EN') !== false);
$q = $H::dilDegistirici($tr, array('EN' => 'https://e.org/a?x=1&y=2'));
check('adres HTML için kaçışlı (&amp;)', strpos($q, 'href="https://e.org/a?x=1&amp;y=2"') !== false);

// ------------------------------------------------------- karşılıklılık (1.0.2) ---
$grup = array(array('id' => 6088, 'title' => 'Price 2056', 'language' => '*'),
              array('id' => 26268, 'title' => 'Price 2056', 'language' => 'en-GB'),
              array('id' => 26768, 'title' => 'Price 2056 (2)', 'language' => 'en-GB'));
$tr = $grup[0];
check('seçilen EN kopya (26268) karşılıklı', $H::karsilikliMi($grup, $tr, 26268) === true);
check('mükerrer EN kopya (26768) karşılıksız → hreflang yok', $H::karsilikliMi($grup, $tr, 26768) === false);
check('TR sayfasından: seçilen EN eşi TR\'yi geri gösterir', $H::karsilikliMi($grup, $grup[1], 6088) === true);
$iki = array(array('id' => 5000, 'title' => 'X', 'language' => '*'), array('id' => 5001, 'title' => 'X', 'language' => '*'),
             array('id' => 20000, 'title' => 'X', 'language' => 'en-GB'));
check('iki TR kopya: EN eşi en küçük TR\'yi seçer → 5001 karşılıksız', $H::karsilikliMi($iki, $iki[2], 5001) === false && $H::karsilikliMi($iki, $iki[2], 5000) === true);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
