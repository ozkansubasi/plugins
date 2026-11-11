<?php
/**
 * plg_system_numistrbulten — saf yardımcıların testi (Joomla'sız): php plugins/system/numistrbulten/tests/numistrbulten_test.php
 */
define('_JEXEC', 1);
require __DIR__ . '/../helper.php';

$ok = 0; $fail = 0;
function check($ad, $kosul) { global $ok, $fail; if ($kosul) { $ok++; echo "  ok   $ad\n"; } else { $fail++; echo "  FAIL $ad\n"; } }
$H = 'PlgNumistrBultenHelper';

// ------------------------------------------------------------------ doğrulama ---
$d = $H::dogrula(array('email' => ' ali@example.com ', 'onay' => '1', 'dil' => 'tr'));
check('geçerli adres + onay → gecerli, boşluk kırpılır', $d['durum'] === 'gecerli' && $d['email'] === 'ali@example.com' && $d['dil'] === 'tr');
check('tuzak alan dolu → tuzak (MailWizz\'e gitmez)', $H::dogrula(array('email' => 'a@b.co', 'onay' => '1', 'web_site' => 'x'))['durum'] === 'tuzak');
check('tuzak alan e-postadan önce bakılır', $H::dogrula(array('email' => 'bozuk', 'web_site' => 'x'))['durum'] === 'tuzak');
check('onay yok → onay_yok', $H::dogrula(array('email' => 'a@b.co'))['hata'] === 'onay_yok');
check('onay "on" değil "1" olmalı', $H::dogrula(array('email' => 'a@b.co', 'onay' => 'on'))['hata'] === 'onay_yok');
check('geçersiz adres → gecersiz_eposta', $H::dogrula(array('email' => 'abc', 'onay' => '1'))['hata'] === 'gecersiz_eposta');
check('boş adres → gecersiz_eposta', $H::dogrula(array('email' => '', 'onay' => '1'))['hata'] === 'gecersiz_eposta');
check('satır sonu enjeksiyonu → gecersiz_eposta', $H::dogrula(array('email' => "a@b.co\r\nBcc: x@y.z", 'onay' => '1'))['hata'] === 'gecersiz_eposta');
check('254 karakterden uzun → gecersiz_eposta', $H::dogrula(array('email' => str_repeat('a', 250) . '@b.co', 'onay' => '1'))['hata'] === 'gecersiz_eposta');
check('bilinmeyen dil → en', $H::dogrula(array('email' => 'a@b.co', 'onay' => '1', 'dil' => 'de'))['dil'] === 'en');
check('dil büyük harf → tr', $H::dogrula(array('email' => 'a@b.co', 'onay' => '1', 'dil' => 'TR'))['dil'] === 'tr');

// ------------------------------------------------------------------- liste ---
$ayar = array('list_uid_tr' => ' trUID ', 'list_uid_en' => 'enUID');
check('tr → TR listesi (kırpılmış)', $H::listeUid($ayar, 'tr') === 'trUID');
check('en → EN listesi', $H::listeUid($ayar, 'en') === 'enUID');
check('TR listesi boşsa EN\'e düşmez', $H::listeUid(array('list_uid_en' => 'enUID'), 'tr') === '');

// ------------------------------------------------------------------- istek ---
$i = $H::istek('https://esemtipi.com/api/index.php/', 'ab12/cd', 'ANAHTAR', 'a+b@c.co', '1.2.3.4', str_repeat('U', 300));
check('adres: tek eğik çizgi + liste kimliği kaçışlı', $i['url'] === 'https://esemtipi.com/api/index.php/lists/ab12%2Fcd/subscribers');
check('X-Api-Key başlığı', $i['basliklar']['X-Api-Key'] === 'ANAHTAR');
parse_str($i['govde'], $g);
check('gövde: EMAIL (+ korunur)', $g['EMAIL'] === 'a+b@c.co');
check('gövde: ip_address', $g['details']['ip_address'] === '1.2.3.4');
check('gövde: user_agent 255\'e kırpılır', strlen($g['details']['user_agent']) === 255);

// ------------------------------------------------------------------- yanıt ---
check('201 success → eklendi', $H::yanit(201, '{"status":"success","data":{"record":{"subscriber_uid":"x"}}}') === 'eklendi');
check('200 ama status error → hata', $H::yanit(200, '{"status":"error","error":"x"}') === 'hata');
check('409 → zaten', $H::yanit(409, '{"status":"error","error":"The subscriber already exists in this list."}') === 'zaten');
check('422 + already exists (dizi) → zaten', $H::yanit(422, '{"status":"error","error":{"EMAIL":["This email address already exists"]}}') === 'zaten');
check('401 geçersiz anahtar → hata', $H::yanit(401, '{"status":"error","error":"Invalid API key. Please refer to the documentation."}') === 'hata');
check('JSON değil → hata', $H::yanit(502, '<html>Bad gateway</html>') === 'hata');
check('2xx ama JSON değil → hata', $H::yanit(200, 'ok') === 'hata');

// ------------------------------------------------------------------- sınır ---
$s = $H::sinir(array(), 1000, 3);
check('boş → aşılmadı, kayıt eklendi', !$s['asildi'] && $s['kayitlar'] === array(1000));
$s = $H::sinir(array(998, 999), 1000, 3);
check('2 kayıt, sınır 3 → aşılmadı (3. istek geçer)', !$s['asildi'] && count($s['kayitlar']) === 3);
$s = $H::sinir(array(997, 998, 999), 1000, 3);
check('3 kayıt, sınır 3 → aşıldı, kayıt eklenmez', $s['asildi'] && count($s['kayitlar']) === 3);
$s = $H::sinir(array(1, 2, 3), 5000, 3);
check('pencere dışındakiler düşer → aşılmadı', !$s['asildi'] && $s['kayitlar'] === array(5000));
check('gelecekteki zaman damgası sayılmaz', !$H::sinir(array(9999, 9999, 9999), 1000, 3)['asildi']);
check('sınır 0 → kapalı (hiç aşılmaz)', !$H::sinir(array_fill(0, 50, 999), 1000, 0)['asildi']);

// ------------------------------------------------------------------- köken ---
$izin = array('www.numistr.org', ' numistr.org ');
check('Origin izinli', $H::kokenIzinli('https://www.numistr.org', null, $izin));
check('Origin yabancı → hayır (Referer izinli olsa da)', !$H::kokenIzinli('https://kotu.example', 'https://www.numistr.org/tr/', $izin));
check('Origin yok → Referer izinli', $H::kokenIzinli(null, 'https://numistr.org/en/x', $izin));
check('Origin "null" → Referer\'e bakılır', $H::kokenIzinli('null', 'https://www.numistr.org/tr/', $izin));
check('http (https değil) → hayır', !$H::kokenIzinli('http://www.numistr.org', null, $izin));
check('benzer alan adı → hayır', !$H::kokenIzinli('https://www.numistr.org.kotu.example', null, $izin));
check('ikisi de yok → hayır', !$H::kokenIzinli(null, null, $izin));

echo "\n$ok ok, $fail fail\n";
exit($fail ? 1 : 0);
