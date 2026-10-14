<?php
/**
 * S29 (1.20.2) — /v1/assistant/health?diag=1 yalnız KB sırrıyla çalışır.
 *
 * 1.20.1'e kadar diag herkese açıktı: her istek ücretli bir canlı Gemini çağrısı yapıyordu (?model= ile
 * desene uyan pahalı bir model de seçilebiliyordu) ve &tool= ile DB aracını çalıştırıp istisna metnini
 * yanıta yazıyordu. Diag'sız health herkese açık kalır (runbook duman testleri onu çağırıyor).
 */

require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';
require_once $root . '/controllers/AssistantController.php';

$savedServer = $_SERVER;
$secretsProp = new ReflectionProperty('AssistantController', 'secrets');
$secretsProp->setAccessible(true);
$savedSecrets = $secretsProp->getValue();

$ok = new ReflectionMethod('AssistantController', 'kbSecretOk');
$ok->setAccessible(true);

$secretsProp->setValue(null, ['KB_WEBHOOK_SECRET' => 's3cret']);

unset($_SERVER['HTTP_X_NUMISTR_KB']);
check('S29: başlıksız istek reddedilir', $ok->invoke(null) === false);

$_SERVER['HTTP_X_NUMISTR_KB'] = 'yanlis';
check('S29: yanlış sır reddedilir', $ok->invoke(null) === false);

$_SERVER['HTTP_X_NUMISTR_KB'] = 's3cret';
check('S29: doğru sır kabul edilir', $ok->invoke(null) === true);

$secretsProp->setValue(null, ['KB_WEBHOOK_SECRET' => '']);
$_SERVER['HTTP_X_NUMISTR_KB'] = '';
check('S29: sır tanımlı değilse boş başlık da reddedilir', $ok->invoke(null) === false);

$secretsProp->setValue(null, $savedSecrets);
$_SERVER = $savedServer;

// Kaynak düzeyi: diag dalında kapı, canlı LLM ve DB aracından ÖNCE.
$src   = (string) file_get_contents($root . '/controllers/AssistantController.php');
$start = strpos($src, 'public static function health(');
$end   = strpos($src, 'public static function export(');
$body  = ($start !== false && $end !== false) ? substr($src, $start, $end - $start) : '';

$gate = strpos($body, 'self::kbSecretOk()');
$llm  = strpos($body, 'new NumisTRLLMClient');
$tool = strpos($body, 'new NumisTRAssistantTools');

check('S29: health diag dalı kapıyı canlı Gemini çağrısından önce denetler',
    $gate !== false && $llm !== false && $gate < $llm);
check('S29: health diag dalı kapıyı DB aracından önce denetler',
    $gate !== false && $tool !== false && $gate < $tool);

$exportBody = $end !== false ? substr($src, $end, 1200) : '';
check('S29: export aynı kapıyı kullanır', str_contains($exportBody, 'self::kbSecretOk()'));
