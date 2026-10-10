<?php
/**
 * M17 — oturumsuz deneme hakki. DB'ye dokunan sorgular degil, KARARLAR test edilir:
 * ayarlarin varsayilanlari, kurulum kimligi dogrulamasi ve decide().
 *
 * Neden onemli: deneme kapisi AI sunucusunu (istek basina ~4,5 sn CPU) herkese acar.
 * Ayar yoksa ya da sayim yapilamiyorsa kapi KAPALI kalmali; aksi halde bir yapilandirma
 * hatasi sinirsiz oturumsuz tanima demek.
 */

require_once $root . '/helpers/AnonTrialHelper.php';

$H = 'AnonTrialHelper';

// ------------------------------------------------------------------- ayarlar ---
$off = $H::settings([]);
check('ayar yoksa deneme KAPALI', $off['enabled'] === false);
check('ayar yoksa varsayilan hak 3', $off['device_limit'] === 3);

$on = $H::settings(['QUOTA' => ['anonymous' => [
    'enabled' => true, 'device_limit' => 3, 'ip_per_day' => 10, 'global_per_hour' => 60,
]]]);
check('constants.php degerleri okunur', $on['enabled'] === true && $on['ip_per_day'] === 10 && $on['global_per_hour'] === 60);

$neg = $H::settings(['QUOTA' => ['anonymous' => ['enabled' => true, 'device_limit' => -5]]]);
check('eksi hak 0a kirpilir', $neg['device_limit'] === 0);

$repo = include $root . '/config/constants.php';
$live = $H::settings($repo);
check('repodaki constants.php denemeyi acar (3 hak, IP ve genel tavan acik)',
    $live['enabled'] === true && $live['device_limit'] === 3 && $live['ip_per_day'] > 0 && $live['global_per_hour'] > 0);

// --------------------------------------------------------- kurulum kimligi ---
$uuid = '3f2b8c1e-9a4d-4c7e-b1f0-5d6a7e8f9a0b';
$h1   = $H::deviceHash($uuid);
check('UUID v4 kabul edilir, 64 hex ozet', is_string($h1) && strlen($h1) === 64 && ctype_xdigit($h1));
check('buyuk/kucuk harf ayni kurulum', $H::deviceHash(strtoupper($uuid)) === $h1);
check('bas/son bosluk ayni kurulum', $H::deviceHash('  ' . $uuid . "\n") === $h1);
check('ham kimlik ozette gecmez', strpos($h1, '3f2b8c1e') === false);
check('bos baslik reddedilir', $H::deviceHash('') === null);
check('null reddedilir', $H::deviceHash(null) === null);
check('dizi reddedilir', $H::deviceHash(['x']) === null);
check('cok kisa (15) reddedilir', $H::deviceHash(str_repeat('a', 15)) === null);
check('cok uzun (65) reddedilir', $H::deviceHash(str_repeat('a', 65)) === null);
check('SQL/ozel karakter reddedilir', $H::deviceHash("abcdefgh'; DROP TABLE x;--") === null);
check('16 karakter siniri kabul', $H::deviceHash(str_repeat('a', 16)) !== null);

// ------------------------------------------------------------------- karar ---
$c0 = ['ip_day' => 0, 'global_hour' => 0];

$d = $H::decide($off, 0, $c0);
check('kapaliyken TRIAL_UNAVAILABLE/disabled', !$d['allowed'] && $d['code'] === 'TRIAL_UNAVAILABLE' && $d['scope'] === 'disabled');

$d = $H::decide($on, null, $c0);
check('kurulum sayilamazsa kapali (unmeasured)', !$d['allowed'] && $d['scope'] === 'unmeasured');

$d = $H::decide($on, 0, null);
check('IP/genel sayim yapilamazsa kapali (unmeasured)', !$d['allowed'] && $d['scope'] === 'unmeasured');

check('ilk tarama izinli', $H::decide($on, 0, $c0)['allowed'] === true);
check('3. tarama (2 kullanilmis) izinli', $H::decide($on, 2, $c0)['allowed'] === true);

$d = $H::decide($on, 3, $c0);
check('4. tarama TRIAL_EXHAUSTED', !$d['allowed'] && $d['code'] === 'TRIAL_EXHAUSTED' && $d['scope'] === 'device');

$d = $H::decide($on, 0, ['ip_day' => 10, 'global_hour' => 0]);
check('IP gunluk tavan -> TRIAL_UNAVAILABLE/ip', !$d['allowed'] && $d['code'] === 'TRIAL_UNAVAILABLE' && $d['scope'] === 'ip');
check('IP tavaninin bir alti izinli', $H::decide($on, 0, ['ip_day' => 9, 'global_hour' => 0])['allowed'] === true);

$d = $H::decide($on, 0, ['ip_day' => 0, 'global_hour' => 60]);
check('genel saatlik tavan -> TRIAL_UNAVAILABLE/global', !$d['allowed'] && $d['scope'] === 'global');

$d = $H::decide($on, 3, ['ip_day' => 10, 'global_hour' => 60]);
check('hak bitmisse once TRIAL_EXHAUSTED (kullaniciya dogru mesaj)', $d['code'] === 'TRIAL_EXHAUSTED');

$noCaps = $H::settings(['QUOTA' => ['anonymous' => ['enabled' => true, 'ip_per_day' => 0, 'global_per_hour' => 0]]]);
check('0 = tavan kapali', $H::decide($noCaps, 0, ['ip_day' => 999, 'global_hour' => 999])['allowed'] === true);

$zero = $H::settings(['QUOTA' => ['anonymous' => ['enabled' => true, 'device_limit' => 0]]]);
check('hak 0 ise ilk tarama bile TRIAL_EXHAUSTED', $H::decide($zero, 0, $c0)['code'] === 'TRIAL_EXHAUSTED');

// ------------------------------------------------------------- kota ozeti ---
$t = new AnonTrialHelper(null, ['QUOTA' => ['anonymous' => ['enabled' => true]]]);
$q = $t->quota(1);
check('kota ozeti: 1 kullanildi -> 2 kaldi / 3', $q['used'] === 1 && $q['remaining'] === 2 && $q['limit'] === 3 && $q['tier'] === 'anonymous');
check('kota ozeti: olculemediyse kalan 0', $t->quota(null)['remaining'] === 0);
check('kota ozeti: fazla kullanim eksiye dusmez', $t->quota(7)['remaining'] === 0);

// ------------------------------------------------ pencere (saat dilimi) ---
// 2026-10-11 canlida olculdu: MySQL @@time_zone = SYSTEM (+03), istek gunlugu UTC yaziliyor.
// NOW() - INTERVAL 1 HOUR kayitlarin 2 saat ilerisine dustugu icin saatlik pencere hep bostu.
$t0 = strtotime('2026-10-11 01:00:00 UTC');
check('saatlik pencere UTC hesaplanir', $H::windowStart($t0, 3600) === '2026-10-11 00:00:00');
check('gunluk pencere UTC hesaplanir', $H::windowStart($t0, 86400) === '2026-10-10 01:00:00');

$fakeDb = new class {
    public $sql = '';
    public function quoteName($n) { return '`' . $n . '`'; }
    public function quote($v) { return "'" . addslashes((string) $v) . "'"; }
    public function setQuery($q) { $this->sql = (string) $q; return $this; }
    public function loadAssoc() { return ['c_ip' => '2', 'c_global' => '5']; }
};
$tz = new AnonTrialHelper($fakeDb, ['QUOTA' => ['anonymous' => ['enabled' => true]]]);
$cnt = $tz->counts('203.0.113.7');
check('sayim sonucu okunur', $cnt === ['ip_day' => 2, 'global_hour' => 5]);
check("sayim SQL'inde NOW() yok", stripos($fakeDb->sql, 'NOW(') === false, $fakeDb->sql);
check("sayim SQL'i UTC gunluk siniri kullanir", strpos($fakeDb->sql, "'" . gmdate('Y-m-d H', time() - 86400)) !== false);
check("sayim SQL'i yalniz oturumsuz satirlari sayar", strpos($fakeDb->sql, '`user_id` = 0') !== false);
check("IP SQL'e tirnakli gecer", strpos($fakeDb->sql, "'203.0.113.7'") !== false);
