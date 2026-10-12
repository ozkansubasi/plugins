<?php
/**
 * S27 — istemci IP'si yalnızca REMOTE_ADDR'dan okunur.
 *
 * Site bir vekil arkasında değil (numistr.org doğrudan cPanel'e çözülüyor). CF-Connecting-IP,
 * X-Forwarded-For ve X-Real-IP istemcinin yazdığı başlıklardır: okunurlarsa her istekte farklı
 * bir "IP" uydurulup IP tabanlı sınırlar (katalog dakika sınırı, asistanın anonim kotası) delinir.
 */

require_once $root . '/helpers/RateLimiter.php';
require_once $root . '/helpers/AuthHelper.php';
require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';
require_once $root . '/controllers/AssistantController.php';
require_once $root . '/helpers/AnonTrialHelper.php';

$saved = $_SERVER;

$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.2, 10.0.0.1';
$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.3';

$limiterIp = new ReflectionMethod('NumisTRRateLimiter', 'getClientIP');
$limiterIp->setAccessible(true);
$limiter = new NumisTRRateLimiter([]);

check('RateLimiter: uydurma başlıklar yok sayılır, REMOTE_ADDR kullanılır', $limiterIp->invoke($limiter) === '203.0.113.7');

$assistantIp = new ReflectionMethod('AssistantController', 'clientIp');
$assistantIp->setAccessible(true);

check('Asistan: uydurma başlıklar yok sayılır, REMOTE_ADDR kullanılır', $assistantIp->invoke(null) === '203.0.113.7');
check('M17 deneme: aynı kural (REMOTE_ADDR)', AnonTrialHelper::clientIp() === '203.0.113.7');

unset($_SERVER['REMOTE_ADDR']);
check('RateLimiter: REMOTE_ADDR yoksa 0.0.0.0 (başlığa düşmez)', $limiterIp->invoke($limiter) === '0.0.0.0');
check('Asistan: REMOTE_ADDR yoksa 0.0.0.0 (başlığa düşmez)', $assistantIp->invoke(null) === '0.0.0.0');

// Kaynakta sahte başlık okuması kalmasın (yeni bir yardımcı eklenirse yakalasın).
$offenders = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($iter as $file) {
    $path = str_replace('\\', '/', (string) $file);

    if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false) {
        continue;
    }

    $src = (string) file_get_contents($path);

    if (preg_match('/HTTP_(X_FORWARDED_FOR|CF_CONNECTING_IP|X_REAL_IP)/', $src)) {
        $offenders[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    }
}

check('Eklentide uydurulabilir IP başlığı okuyan dosya yok', $offenders === [], implode(', ', $offenders));

$_SERVER = $saved;
