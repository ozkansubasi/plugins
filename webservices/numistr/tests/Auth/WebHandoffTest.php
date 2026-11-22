<?php
/**
 * 1.28.0 — uygulamadan siteye tek kullanımlık geçiş kodu (POST /v1/user/web-handoff).
 * Saf kararlar + sahte veritabanıyla üretim: özet saklanır (ham kod asla), ömür, saatlik tavan, dönüş yolu süzgeci.
 */

require_once $root . '/helpers/WebHandoffHelper.php';

$H = 'WebHandoffHelper';

// ------------------------------------------------------------------- ayarlar ---
$s = $H::settings([]);
check('varsayılanlar: kök www, 120 sn, saatte 10, IP dakikada 20', $s === ['site_root' => 'https://www.numistr.org', 'ttl' => 120, 'per_hour' => 10, 'ip_per_minute' => 20]);
check('kök http ya da yollu ise varsayılana döner', $H::settings(['WEB_HANDOFF' => ['site_root' => 'http://evil.example']])['site_root'] === 'https://www.numistr.org');
check('ömür 30–600 aralığına kısılır', $H::settings(['WEB_HANDOFF' => ['ttl' => 99999]])['ttl'] === 600 && $H::settings(['WEB_HANDOFF' => ['ttl' => 1]])['ttl'] === 30);
$repo = include $root . '/config/constants.php';
check('repo ayarı okunuyor', $H::settings($repo)['site_root'] === 'https://www.numistr.org');

// -------------------------------------------------------------------- kod ---
$k1 = $H::newCode(); $k2 = $H::newCode();
check('kod 64 onaltılık karakter', (bool) preg_match('/^[0-9a-f]{64}$/', $k1));
check('iki kod farklı', $k1 !== $k2);
check('özet sha256 ve koddan farklı', $H::hash($k1) === hash('sha256', $k1) && $H::hash($k1) !== $k1);

// -------------------------------------------------------------- dönüş yolu ---
check('/tr/hesabim kabul', $H::safeReturn('/tr/hesabim', 'tr') === '/tr/hesabim');
check('/en/plans kabul', $H::safeReturn('/en/plans', 'en') === '/en/plans');
check('dış adres → Hesabım', $H::safeReturn('https://evil.example/x', 'tr') === '/tr/hesabim');
check('protokolsüz dış adres → Hesabım', $H::safeReturn('//evil.example', 'tr') === '/tr/hesabim');
check('.. → Hesabım', $H::safeReturn('/tr/../administrator', 'tr') === '/tr/hesabim');
check('sorgu dizgisi → Hesabım', $H::safeReturn('/tr/hesabim?x=1', 'tr') === '/tr/hesabim');
check('dil öneki yok → Hesabım (EN)', $H::safeReturn('/administrator/', 'en') === '/en/my-account');
check('boş → dile göre Hesabım', $H::safeReturn('', 'en-GB') === '/en/my-account' && $H::safeReturn('', 'tr') === '/tr/hesabim');

// ---------------------------------------------------------------- adres + gövde ---
check('adres site köküne, task=handoff, dil', $H::url('https://www.numistr.org/', 'abc', 'en-GB') === 'https://www.numistr.org/index.php?option=com_ajax&plugin=numistrauth&format=raw&task=handoff&code=abc&lang=en');
check('dil yoksa tr', substr($H::url('https://www.numistr.org', 'abc'), -8) === '&lang=tr');
check('gövde: return + lang', $H::parseBody('{"return":"/en/my-account","lang":"en"}') === ['return' => '/en/my-account', 'lang' => 'en']);
check('bozuk gövde → varsayılan', $H::parseBody('xx') === ['return' => '', 'lang' => 'tr']);
check('dizgi olmayan return yok sayılır', $H::parseBody('{"return":["/tr/x"]}')['return'] === '');

// ------------------------------------------------------- üretim (sahte veritabanı) ---
final class FakeHandoffDb
{
    public array $queries = []; public array $rows = []; public int $count = 0; private string $last = '';
    public function quoteName($n) { return '`' . $n . '`'; }
    public function quote($v) { return "'" . str_replace("'", "''", (string) $v) . "'"; }
    public function setQuery($q) { $this->last = (string) $q; $this->queries[] = $this->last; return $this; }
    public function execute() { return true; }
    public function loadResult() { return $this->count; }
    public function insertObject($table, $row) { $this->rows[] = [$table, $row]; return true; }
}
$now = 1795370000; // 2026-11-22 UTC civarı
$db  = new FakeHandoffDb();
$r   = $H::issue($db, $s, 42, '/tr/hesabim', $now);
check('üretim 200', $r['status'] === 200 && $r['code'] === 'OK');
preg_match('/code=([0-9a-f]{64})&lang=tr$/', $r['body']['url'], $m);
$ham = $m[1] ?? '';
check('yanıtta kodlu adres + ömür', $ham !== '' && $r['body']['expires_in'] === 120);
$row = $db->rows[0][1] ?? null;
check('tabloya yazıldı', ($db->rows[0][0] ?? '') === 'numistr_web_handoff');
check('DB\'de ham kod YOK, yalnız özet', $row && $row->code_hash === hash('sha256', $ham) && strpos(json_encode($row), $ham) === false);
check('bitiş = oluşturma + 120 sn (UTC)', $row && strtotime($row->expires_at . ' UTC') - strtotime($row->created_at . ' UTC') === 120);
check('kullanıcı ve dönüş yolu kaydedildi', $row && $row->user_id === 42 && $row->return_path === '/tr/hesabim');
check('önce 1 günden eski kayıtlar silinir', strpos($db->queries[0], 'DELETE FROM `numistr_web_handoff` WHERE created_at <') === 0);

$db2 = new FakeHandoffDb(); $db2->count = 10;
$r2 = $H::issue($db2, $s, 42, '/tr/hesabim', $now);
check('saatte 10 kod dolunca 429, kayıt yok', $r2['status'] === 429 && $r2['code'] === 'RATE_LIMITED' && $db2->rows === []);
check('kullanıcı yok → 401', $H::issue(new FakeHandoffDb(), $s, 0, '/tr/hesabim', $now)['status'] === 401);
