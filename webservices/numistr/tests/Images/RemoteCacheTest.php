<?php
/**
 * com_numistr 1.5.3 — uzak görsel disk önbelleği (numistr_fetch_remote_cached).
 *
 * 2026-09-27: anlık akış önce çalıştığı için önbellek katmanı hiç devreye girmiyordu; her istek müzeden
 * yeniden indiriliyordu. Bu test bileşen fonksiyonlarını dosyadan çıkarır ve yerel bir PHP sunucusuna karşı
 * çalıştırır: ilk istek indirir ('fetch'), ikincisi diskten ('cache'), baytlar değişmez (ADR-008 §3.1-4).
 */

$rawPath = getenv('NUMISTR_COMPONENT_RAW') ?: ($root . '/../../../numistr/components/com_numistr/views/gorsel/view.raw.php');
if (!is_file($rawPath) || !function_exists('curl_init')) {
    check('uzak önbellek testi (bileşen ya da curl yok, atlandı)', true, $rawPath);
    return;
}

$src   = (string) file_get_contents($rawPath);
$names = ['numistr_host', 'numistr_pick_cainfo_path', 'numistr_is_ssl_chain_error', 'numistr_bm_fallback_urls',
          'numistr_bm_try_fallbacks', 'numistr_download_remote_curl', 'numistr_fetch_remote_cached'];
$code  = '';
foreach ($names as $n) {
    // önce tek satırlık tanım (numistr_host), sonra "\n}\n" ile biten çok satırlı tanım
    if (preg_match('/function ' . $n . '\([^\n]*\}\R/', $src, $m) || preg_match('/function ' . $n . '\(.*?\R}\R/s', $src, $m)) {
        $code .= $m[0];
    } else {
        check('bileşen fonksiyonu bulundu: ' . $n, false);
        return;
    }
}
if (!defined('JPATH_ROOT')) define('JPATH_ROOT', sys_get_temp_dir());
if (!function_exists('numistr_fetch_remote_cached')) eval($code);
check('bileşen önbellek fonksiyonları yüklendi', function_exists('numistr_fetch_remote_cached'));

// ---------------------------------------------------------------- yerel kaynak sunucusu ---
$docroot = sys_get_temp_dir() . '/numistr_rc_' . getmypid();
$cache   = $docroot . '_cache';
@mkdir($docroot, 0775, true);
@mkdir($cache, 0775, true);
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
file_put_contents($docroot . '/coin.jpg', $jpeg);
file_put_contents($docroot . '/page.txt', 'not an image');
file_put_contents($docroot . '/router.php', '<?php $f = __DIR__ . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    . ' if (!is_file($f)) { http_response_code(404); exit; }'
    . ' header("Content-Type: " . (str_ends_with($f, ".jpg") ? "image/jpeg" : "text/plain")); readfile($f);');

$port = 18000 + (getmypid() % 1000);
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $docroot . '/router.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = 'http://127.0.0.1:' . $port;

try {
    $kind = null;
    $p1 = numistr_fetch_remote_cached($base . '/coin.jpg', $cache, 15, false, 0, $kind);
    check('ilk istek indirir (fetch)', $p1 !== null && $kind === 'fetch', (string) $kind);
    check('saklanan dosya baytları kaynakla aynı', $p1 !== null && file_get_contents($p1) === $jpeg);
    check('dosya adı sha1(url).bin', $p1 === $cache . '/' . sha1($base . '/coin.jpg') . '.bin', (string) $p1);

    $kind = null;
    $p2 = numistr_fetch_remote_cached($base . '/coin.jpg', $cache, 15, false, 0, $kind);
    check('ikinci istek diskten (cache)', $p2 === $p1 && $kind === 'cache', (string) $kind);

    check('404 → null (akışa düşer)', numistr_fetch_remote_cached($base . '/yok.jpg', $cache, 15, false, 0) === null);
    check('image/* olmayan içerik saklanmaz', numistr_fetch_remote_cached($base . '/page.txt', $cache, 15, false, 0) === null);
    check('geçici dosya kalmadı', glob($cache . '/*.tmp') === []);
} finally {
    proc_terminate($proc);
    proc_close($proc);
    array_map('unlink', array_merge(glob($docroot . '/*') ?: [], glob($cache . '/*') ?: []));
    @rmdir($docroot);
    @rmdir($cache);
}
