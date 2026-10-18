<?php
/**
 * plg_content_numistr — saf yardımcıların testi (Joomla'sız): php plugins/content/numistr/tests/numistr_test.php
 *
 * Figür sabit vektörleri `scripts/icerik_uretec/gorsel_sec.py::figur_html` çıktısıdır (2026-10-18 üretildi): PHP çıktısı
 * bayt bayt aynı olmalı. GERCEK = canlıdaki 34038 III. Antiokhos yazısının Tarsos figürü (API verisiyle).
 */
define('_JEXEC', 1);
require __DIR__ . '/../helper.php';

$ok = 0; $fail = 0;
function check($ad, $kosul, $ek = '') { global $ok, $fail; if ($kosul) { $ok++; echo "  ok   $ad\n"; } else { $fail++; echo "  FAIL $ad $ek\n"; } }
$H = 'PlgNumistrContentHelper';
$KOK = 'https://www.numistr.org';

// ------------------------------------------------------- Python ile bayt bayt aynı ---
$GERCEK = <<<'GERCEK'
<figure class="numistr-figur" style="margin:32px 0;padding:0">
  <div class="numistr-figur-sira" style="display:flex;gap:16px;justify-content:center;align-items:center;flex-wrap:wrap">
    <img src="https://www.numistr.org/index.php?option=com_numistr&amp;view=gorsel&amp;format=raw&amp;id=38282&amp;wm=1" alt="Seleucid Coins (part 1) 1025 — Ön yüz" loading="lazy" style="max-width:230px;width:45%;display:block;height:auto">
    <img src="https://www.numistr.org/index.php?option=com_numistr&amp;view=gorsel&amp;format=raw&amp;id=38283&amp;wm=1" alt="Seleucid Coins (part 1) 1025 — Arka yüz" loading="lazy" style="max-width:230px;width:45%;display:block;height:auto">
  </div>
  <figcaption style="font-size:14px;line-height:1.6;color:#5b6470;text-align:center;margin-top:12px;max-width:560px;margin-left:auto;margin-right:auto">
    <b><a href="https://www.numistr.org/tr/anatolian-coins/clicia-coins/12764-seleucid-coins-part-1-1025">Seleucid Coins (part 1) 1025</a></b> — Tarsus, MÖ 223–211. Ön yüz: Sağa dönük diademli Antiokhos III başı (Tip Aii). Arka yüz: Omphalos üzerinde sola dönük oturan, oku test eden ve sol elini yere dayalı yaya koyan Apollon.
    <span class="img-credit" style="display:block;font-size:12px;color:#999;margin-top:6px">American Numismatic Society · No Copyright - United States</span>
  </figcaption>
</figure>
GERCEK;
$k = $H::kayit(array('title_tr' => 'Seleucid Coins (part 1) 1025', 'slug' => 'seleucid-coins-part-1-1025', 'metal' => null),
    array('mint-name' => 'tarsus', 'start-date' => '-223', 'end-date' => '-211',
        'obverse-desc-tr' => 'Sağa dönük diademli Antiokhos III başı (Tip Aii)',
        'reverse-desc-tr' => 'Omphalos üzerinde sola dönük oturan, oku test eden ve sol elini yere dayalı yaya koyan Apollon'));
$sec = $H::secilenler(array(38282, 38283), array(
    array('image_id' => 38283, 'coin_id' => 12764, 'image_type' => 'arka', 'source_credit' => 'American Numismatic Society', 'license' => 'No Copyright - United States', 'license_ok' => '1', 'source_unavailable' => '0'),
    array('image_id' => 38282, 'coin_id' => 12764, 'image_type' => 'on', 'source_credit' => 'American Numismatic Society', 'license' => 'No Copyright - United States', 'license_ok' => '1', 'source_unavailable' => '0'),
), 12764);
$html = $H::figurHtml($k, 'https://www.numistr.org/tr/anatolian-coins/clicia-coins/12764-seleucid-coins-part-1-1025', $sec, $KOK);
check('gerçek figür (34038 Tarsos) Python ile aynı', $html === $GERCEK, "\n$html");

$TEK = <<<'TEK'
<figure class="numistr-figur" style="margin:32px 0;padding:0">
  <img src="https://www.numistr.org/index.php?option=com_numistr&amp;view=gorsel&amp;format=raw&amp;id=118104&amp;wm=1" alt="Lydia &#x27;Kroiseid&#x27; &amp; &lt;altın&gt;" loading="lazy" style="max-width:420px;width:100%;display:block;margin:0 auto;height:auto">
  <figcaption style="font-size:14px;line-height:1.6;color:#5b6470;text-align:center;margin-top:12px;max-width:560px;margin-left:auto;margin-right:auto">
    <b><a href="https://www.numistr.org/tr/anatolian-coins/lydia-coins/118-kroiseid-a">Lydia 'Kroiseid' &amp; &lt;altın&gt;</a></b> — Sardeis, MÖ 550, altın.
    <span class="img-credit" style="display:block;font-size:12px;color:#999;margin-top:6px">Berlin Münzkabinett &amp; Co · Public Domain Mark</span>
  </figcaption>
</figure>
TEK;
$k2 = array('title' => "Lydia 'Kroiseid' & <altın>", 'mint' => '  sardeis  ', 'material' => 'gold', 'date_from' => -550, 'date_to' => -550,
    'obverse_desc_tr' => 'Aslan ve boğa protomları..', 'reverse_desc_tr' => 'İki kare zımba');
$s2 = array(array('image_id' => 118104, 'type' => null, 'credit' => 'Berlin Münzkabinett & Co', 'license' => 'Public Domain Mark'));
$h2 = $H::figurHtml($k2, 'https://www.numistr.org/tr/anatolian-coins/lydia-coins/118-kroiseid-a', $s2, $KOK);
check('tek görsel + kaçış + tek yıl + yüzsüz tür (betim yok) Python ile aynı', $h2 === $TEK, "\n$h2");

$UCLU = <<<'UCLU'
<figure class="numistr-figur" style="margin:32px 0;padding:0">
  <div class="numistr-figur-sira" style="display:flex;gap:16px;justify-content:center;align-items:center;flex-wrap:wrap">
    <img src="https://www.numistr.org/index.php?option=com_numistr&amp;view=gorsel&amp;format=raw&amp;id=1&amp;wm=1" alt="CN Type 11115 — Ön yüz" loading="lazy" style="max-width:230px;width:45%;display:block;height:auto">
    <img src="https://www.numistr.org/index.php?option=com_numistr&amp;view=gorsel&amp;format=raw&amp;id=2&amp;wm=1" alt="CN Type 11115 — Arka yüz" loading="lazy" style="max-width:230px;width:45%;display:block;height:auto">
    <img src="https://www.numistr.org/index.php?option=com_numistr&amp;view=gorsel&amp;format=raw&amp;id=3&amp;wm=1" alt="CN Type 11115 — Detay" loading="lazy" style="max-width:230px;width:45%;display:block;height:auto">
  </div>
  <figcaption style="font-size:14px;line-height:1.6;color:#5b6470;text-align:center;margin-top:12px;max-width:560px;margin-left:auto;margin-right:auto">
    <b><a href="https://www.numistr.org/tr/other-ancient-place-coins/22727-cn-type-11115">CN Type 11115</a></b> — Pergamum, MÖ 270 – MS 14, bronz. Ön yüz: Athena başı.
    <span class="img-credit" style="display:block;font-size:12px;color:#999;margin-top:6px">Corpus Nummorum · CC BY 4.0 | CC0</span>
  </figcaption>
</figure>
UCLU;
$k3 = array('title' => 'CN Type 11115', 'mint' => 'PERGAMUM', 'material' => 'bronze', 'date_from' => -270, 'date_to' => 14,
    'obverse_desc_tr' => 'Athena başı', 'reverse_desc_tr' => '');
$s3 = array(array('image_id' => 1, 'type' => 'on', 'credit' => 'Corpus Nummorum', 'license' => 'CC BY 4.0'),
    array('image_id' => 2, 'type' => 'arka', 'credit' => 'Corpus Nummorum', 'license' => 'CC BY 4.0'),
    array('image_id' => 3, 'type' => 'detay', 'credit' => '', 'license' => 'CC0'));
$h3 = $H::figurHtml($k3, 'https://www.numistr.org/tr/other-ancient-place-coins/22727-cn-type-11115', $s3, $KOK);
check('üç görsel + MÖ–MS aralığı + boş arka betim + çoklu atıf Python ile aynı', $h3 === $UCLU, "\n$h3");

// ------------------------------------------------------------- lisans kapısı ---
$satir = function ($id, $coin, $ok, $yok = 0, $tip = 'on') {
    return array('image_id' => $id, 'coin_id' => $coin, 'image_type' => $tip, 'source_credit' => 'K', 'license' => 'L', 'license_ok' => $ok, 'source_unavailable' => $yok);
};
$r = array($satir(1, 9, '1'), $satir(2, 9, '0'), $satir(3, 9, null), $satir(4, 9, '1', '1'), $satir(5, 8, '1'), $satir(6, 9, '1', 0, 'arka'));
$ids = array_column($H::secilenler(array(6, 5, 4, 3, 2, 1, 7), $r, 9), 'image_id');
check('yalnız license_ok=1 + erişilebilir + bu sikkenin görselleri, istenen sırada', $ids === array(6, 1), json_encode($ids));
check('NC (license_ok=0, lisans metni dolu) yazıda gösterilmez', $H::secilenler(array(2), $r, 9) === array());
check('doğrulanmamış (license_ok NULL) gösterilmez', $H::secilenler(array(3), $r, 9) === array());
check('erişilemez (source_unavailable=1) gösterilmez', $H::secilenler(array(4), $r, 9) === array());
check('başka sikkenin görseli gösterilmez', $H::secilenler(array(5), $r, 9) === array());
check('DB\'de olmayan kimlik gösterilmez', $H::secilenler(array(7), $r, 9) === array());
check('boş kredi null olur (atıfta boş parça yok)', $H::secilenler(array(1), array(array_merge($satir(1, 9, '1'), array('source_credit' => ''))), 9)[0]['credit'] === null);

// ------------------------------------------------------------- kısa kod ---
check('kod ayrıştırma', $H::ayristir('{numistr_gorsel variant=12764 gorsel=38282,38283}') === array('variant' => 12764, 'gorsel' => array(38282, 38283)));
check('tırnaklı ve &nbsp;\'lı kod', $H::ayristir('{numistr_gorsel&nbsp;variant="12764" gorsel="38282, 38283"}') === array('variant' => 12764, 'gorsel' => array(38282, 38283)));
check('yinelenen görsel tekilleşir', $H::ayristir('{numistr_gorsel variant=1 gorsel=5,5}')['gorsel'] === array(5));
check('7 görsel → geçersiz', $H::ayristir('{numistr_gorsel variant=1 gorsel=1,2,3,4,5,6,7}') === null);
check('sıfır kimlik → geçersiz', $H::ayristir('{numistr_gorsel variant=0 gorsel=1}') === null && $H::ayristir('{numistr_gorsel variant=1 gorsel=0}') === null);
check('görselsiz / sözcüklü / ters sıra → geçersiz', $H::ayristir('{numistr_gorsel variant=1}') === null
    && $H::ayristir('{numistr_gorsel variant=1 gorsel=2 x=3}') === null && $H::ayristir('{numistr_gorsel gorsel=2 variant=1}') === null);

$cagri = array();
$uret = function ($v, array $ids) use (&$cagri) { $cagri[] = array($v, $ids); return '<figure>' . $v . '</figure>'; };
$metin = "<h2>A</h2>\n<p>{numistr_gorsel variant=1 gorsel=2,3}</p>\n<p>x</p>\n{numistr_gorsel variant=4 gorsel=5}\n<p>&nbsp;{numistr_gorsel variant=6 gorsel=7}&nbsp;</p>";
$out = $H::degistir($metin, $uret);
check('kodu saran <p> gider, çıplak kod da değişir', $out === "<h2>A</h2>\n<figure>1</figure>\n<p>x</p>\n<figure>4</figure>\n<figure>6</figure>", $out);
check('üretici kimliklerle çağrılır', $cagri === array(array(1, array(2, 3)), array(4, array(5)), array(6, array(7))));
check('cümle içindeki kodda paragraf kalır', $H::degistir('<p>a {numistr_gorsel variant=1 gorsel=2} b</p>', $uret) === '<p>a <figure>1</figure> b</p>');
check('bozuk kod yorum satırına döner', $H::degistir('<p>{numistr_gorsel variant=x gorsel=2}</p>', $uret) === '<!-- numistr_gorsel: geçersiz kod -->');
check('kodsuz metin aynen', $H::degistir('<p>{loadmodule x}</p>', $uret) === '<p>{loadmodule x}</p>');
check('boş dönüş figürü siler (site dışı istemci)', $H::degistir("a\n<p>{numistr_gorsel variant=1 gorsel=2}</p>\nb", function () { return ''; }) === "a\n\nb");

// ------------------------------------------------------------- kayıt alanları ---
$kk = $H::kayit(array('title_tr' => '', 'title_en' => 'EN t', 'metal' => '', 'mint_name' => 'görünüm', 'date_from' => null),
    array('material' => 'AR', 'start-date' => '', 'end-date' => '-480'), 'c başlık');
check('başlık: title_tr boşsa title_en', $kk['title'] === 'EN t');
check('malzeme: metal boşsa özel alan (AR → silver)', $kk['material'] === 'silver');
check('darphane: özel alan yoksa görünüm sütunu', $kk['mint'] === 'görünüm');
check('yıl: boş metin null, sayı metni int', $kk['date_from'] === null && $kk['date_to'] === -480);
check('başlık: görünümde hiçbiri yoksa #__content başlığı', $H::kayit(array(), array(), 'c başlık')['title'] === 'c başlık');
check('malzeme eşlemesi', $H::malzeme('Gümüş') === 'silver' && $H::malzeme('AE') === 'bronze' && $H::malzeme('elektrum') === 'electrum'
    && $H::malzeme(' ') === null && $H::malzeme(null) === null && $H::malzeme('nan') === 'nan');
check('tarih: aynı çağ kısaltılır, farklı çağ açık', $H::tarihAraligi(array('date_from' => -223, 'date_to' => -211)) === 'MÖ 223–211'
    && $H::tarihAraligi(array('date_from' => -30, 'date_to' => 14)) === 'MÖ 30 – MS 14' && $H::tarihAraligi(array('date_to' => 120)) === 'MS 120');
check('ilk harf büyük, kalan küçük (Python capitalize)', $H::ilkBuyuk('KYZIKOS (CYZICUS)') === 'Kyzikos (cyzicus)' && $H::ilkBuyuk('ışık') === 'Işık');

echo "\n$ok ok, $fail fail\n";
exit($fail ? 1 : 0);
