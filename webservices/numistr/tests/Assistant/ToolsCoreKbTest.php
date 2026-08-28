<?php
/** Tool JSON schema validity, URL builders, HTML->text, region normalisation, core KB build/hash, LLM role normalisation. */

require_once $root . '/helpers/DatabaseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantTools.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';

$cfg = include $root . '/config/assistant.php';

// ---- tool definitions ----
$defs  = NumisTRAssistantTools::definitions();
$names = array_column($defs, 'name');
check('6 tools defined', count($defs) === 6, implode(',', $names));
check('tool names unique', count(array_unique($names)) === count($names));
check('expected tool names', $names === ['search_coins', 'get_variant', 'search_settlements', 'get_settlement', 'search_site', 'search_kb']);

foreach ($defs as $d) {
    $ok = isset($d['name'], $d['description'], $d['input_schema'])
        && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $d['name'])
        && ($d['input_schema']['type'] ?? '') === 'object'
        && is_array($d['input_schema']['properties'] ?? null);

    if ($ok && isset($d['input_schema']['required'])) {
        foreach ($d['input_schema']['required'] as $req) {
            if (!isset($d['input_schema']['properties'][$req])) {
                $ok = false;
            }
        }
    }

    if ($ok) {
        foreach ($d['input_schema']['properties'] as $pn => $p) {
            if (!in_array($p['type'] ?? '', ['string', 'integer', 'boolean', 'number', 'array'], true)) {
                $ok = false;
            }
        }
    }

    check('schema valid: ' . $d['name'], $ok, json_encode($d));
}

$json = json_encode($defs);
check('definitions JSON-encodable', $json !== false && strlen($json) > 500);
check('subset selection works', array_column(NumisTRAssistantTools::definitions(['get_variant', 'search_kb']), 'name') === ['get_variant', 'search_kb']);
check('limit capped at 10 in schema', $defs[0]['input_schema']['properties']['limit']['maximum'] === 10);

// ---- region normalisation ----
check('region: Karya -> caria', NumisTRAssistantTools::normaliseRegion('Karya') === 'caria');
check('region: caria-coins -> caria', NumisTRAssistantTools::normaliseRegion('caria-coins') === 'caria');
check('region: Lidya Sikkeleri -> lydia', NumisTRAssistantTools::normaliseRegion('Lidya Sikkeleri') === 'lydia');
check('region: Kilikya -> cilicia', NumisTRAssistantTools::normaliseRegion('Kilikya') === 'cilicia');
check('region: Diğer -> other', NumisTRAssistantTools::normaliseRegion('Diğer') === 'other');
check('region: empty -> null', NumisTRAssistantTools::normaliseRegion('  ') === null);
check('region: English passthrough', NumisTRAssistantTools::normaliseRegion('PHRYGIA') === 'phrygia');

// ---- URL builders ----
check(
    'coin url shape',
    NumisTRAssistantTools::coinUrl('https://numistr.org/', 'tr', 'pamphylia-coins', 3404, 'aspendus-imhoof-blumer-1901-2-p-316-21')
        === 'https://numistr.org/tr/anatolian-coins/pamphylia-coins/3404-aspendus-imhoof-blumer-1901-2-p-316-21'
);
check(
    'settlement url TR (hidden menu alias)',
    NumisTRAssistantTools::settlementUrl('https://numistr.org', 'tr', 'karya-yerlesimleri', 30690, 'aphrodisias')
        === 'https://numistr.org/tr/karya-yerlesimleri/30690-aphrodisias'
);
check(
    'settlement url EN',
    NumisTRAssistantTools::settlementUrl('https://numistr.org', 'en', 'caria-settlements', 30691, 'aphrodisias')
        === 'https://numistr.org/en/caria-settlements/30691-aphrodisias'
);
check('url encodes unsafe alias chars', strpos(NumisTRAssistantTools::settlementUrl('https://x', 'tr', 'a b', 1, 'c/d'), ' ') === false);

// ---- html -> text ----
$html = '<p>Aphrodisias, <b>Karya</b> bölgesinde&nbsp;bir antik kenttir.</p><script>x()</script><ul><li>Tapınak</li><li>Stadyum</li></ul>';
$txt  = NumisTRAssistantTools::htmlToText($html, 300);
check('htmlToText strips tags and script', strpos($txt, '<') === false && strpos($txt, 'x()') === false);
check('htmlToText decodes entities', strpos($txt, 'bölgesinde bir') !== false, $txt);
check('htmlToText keeps list items on lines', strpos($txt, "Tapınak\nStadyum") !== false, json_encode($txt));
check('htmlToText truncates to max', mb_strlen(NumisTRAssistantTools::htmlToText(str_repeat('abc ', 2000), 100), 'UTF-8') <= 100);

// ---- core KB ----
$kb = new NumisTRAssistantCoreKb($root . '/assistant');
$tr = $kb->build('tr');
$en = $kb->build('en');
check('core-kb.tr.md exists', $tr['exists']);
check('core-kb.en.md exists', $en['exists']);
check('TR hash is md5', preg_match('/^[a-f0-9]{32}$/', $tr['hash']) === 1);
check('TR and EN hashes differ', $tr['hash'] !== $en['hash']);
check('hash is deterministic', $tr['hash'] === md5($tr['text']));
check('TR KB large enough for Gemini explicit cache (>=1200 tokens est)', $tr['tokens_est'] >= 1200, (string) $tr['tokens_est']);
check('EN KB large enough for Gemini explicit cache', $en['tokens_est'] >= 1200, (string) $en['tokens_est']);
// Fiyat/kanal bilgisi bayatlamasin: 2026-08-28'de cekirdek KB hala '699,99' ve
// "web odemesi henuz yok" diyordu — web aboneligi 2026-08-25'te canliya alinmisti.
check('TR KB has current Pro price', strpos($tr['text'], '99,99') !== false && strpos($tr['text'], '839,99') !== false);
check('TR KB has no stale 699,99 price', strpos($tr['text'], '699,99') === false);
check('EN KB has current Pro price', strpos($en['text'], '34.99') !== false && strpos($en['text'], '3.99') !== false);
check('EN KB has no stale 699.99 price', strpos($en['text'], '699.99') === false);
check('TR KB mentions web purchase channel', stripos($tr['text'], 'iyzico') !== false && strpos($tr['text'], '/tr/abonelikler') !== false);
check('EN KB mentions web purchase channel', stripos($en['text'], 'iyzico') !== false && strpos($en['text'], '/en/plans') !== false);
check('TR KB does not claim web payment is missing', stripos($tr['text'], 'web ödemesi henüz yok') === false);
check('EN KB does not claim web payment is missing', stripos($en['text'], 'no web payment') === false);
check('TR KB explains web cancellation', strpos($tr['text'], '/tr/hesabim') !== false);
check('EN KB explains web cancellation', strpos($en['text'], '/en/my-account') !== false);
check('TR KB lists 16 regions + Diğer', strpos($tr['text'], '16. Pontus') !== false && strpos($tr['text'], '17. Diğer') !== false);
check('TR KB has 15 FAQs', strpos($tr['text'], '15. **') !== false);
check('KB glossary has >= 40 terms (TR)', preg_match_all('/^- \*\*/m', $tr['text']) >= 40);
check('KB glossary has >= 40 terms (EN)', preg_match_all('/^- \*\*/m', $en['text']) >= 40);
check('KB has no CRLF', strpos($tr['text'], "\r") === false);

$prompt = $kb->systemPrompt('tr', $cfg['prompts']['tr']['rules']);
check('system prompt = rules + KB', strpos($prompt, 'KURALLAR') !== false && strpos($prompt, 'CEKIRDEK BILGI') !== false);

// hash changes with content
$tmp = sys_get_temp_dir() . '/numistr-kb-test-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/core-kb.tr.md', "# A\nhello");
NumisTRAssistantCoreKb::resetCache();
$h1 = (new NumisTRAssistantCoreKb($tmp))->build('tr')['hash'];
file_put_contents($tmp . '/core-kb.tr.md', "# A\nhello world");
NumisTRAssistantCoreKb::resetCache();
$h2 = (new NumisTRAssistantCoreKb($tmp))->build('tr')['hash'];
check('hash changes when KB content changes (cache invalidation)', $h1 !== $h2);
@unlink($tmp . '/core-kb.tr.md');
@rmdir($tmp);
NumisTRAssistantCoreKb::resetCache();

// ---- LLM helpers ----
$msgs = [
    ['role' => 'assistant', 'content' => 'hi'],
    ['role' => 'user', 'content' => 'a'],
    ['role' => 'user', 'content' => 'b'],
    ['role' => 'assistant', 'content' => 'c'],
    ['role' => 'user', 'content' => 'd'],
];
$n = NumisTRLLMClient::normaliseRoles($msgs);
check('normaliseRoles drops leading assistant, merges user, ends with assistant', $n === [['role' => 'user', 'content' => "a\n\nb"], ['role' => 'assistant', 'content' => 'c']], json_encode($n));
check('looksLikeCacheError detects NOT_FOUND', NumisTRLLMClient::looksLikeCacheError('Resource NOT_FOUND: cachedContents/x'));
check('looksLikeCacheError ignores other errors', !NumisTRLLMClient::looksLikeCacheError('quota exceeded'));

$client = new NumisTRLLMClient($cfg, []);
check('client without keys reports missing', !$client->hasGemini() && !$client->hasAnthropic());
$r = $client->classify('gemini-3.7-flash', 'sys', 'hello', ['site']);
check('classify without key -> ok=false, label null (fallback path)', $r['ok'] === false && $r['label'] === null);
$r = $client->claudeToolLoop('claude-haiku-4-5', 'sys', [], 'hello', $defs, function () { return []; });
check('tool loop without key -> ok=false with error', $r['ok'] === false && $r['error'] !== '');

// ---- config sanity ----
check('models configured', $cfg['models']['classify'] === 'gemini-3.7-flash' && $cfg['models']['tools'] === 'claude-haiku-4-5');
check('every model has a cost row', isset($cfg['costs'][$cfg['models']['classify']], $cfg['costs'][$cfg['models']['site']], $cfg['costs'][$cfg['models']['tools']]));
check('prompts forbid counts (TR)', stripos($cfg['prompts']['tr']['rules'], 'sayisi verme') !== false);
check('prompts forbid counts (EN)', stripos($cfg['prompts']['en']['rules'], 'NEVER state total') !== false);

// ---- Faz 2b/7: giris + ucretsiz uyelik baglantilari ----
check('auth_urls configured', isset($cfg['auth_urls']['login'], $cfg['auth_urls']['register']));
check('auth_urls point at numistrauth (Auth0 web girisi)',
    strpos((string) $cfg['auth_urls']['login'], 'plugin=numistrauth') !== false
    && strpos((string) $cfg['auth_urls']['login'], 'task=login') !== false
    && strpos((string) $cfg['auth_urls']['register'], 'task=signup') !== false);
check('auth_urls are relative (return parametresi ile ayni kokene doner)',
    strpos((string) $cfg['auth_urls']['login'], '/index.php') === 0);
check('limits define user and pro tiers', isset($cfg['limits']['anon'], $cfg['limits']['user'], $cfg['limits']['pro']));
check('recognize messages exist (TR+EN)',
    isset($cfg['messages']['tr']['recognize_login'], $cfg['messages']['tr']['recognize_quota'],
          $cfg['messages']['en']['recognize_login'], $cfg['messages']['en']['recognize_quota']));
check('recognize quota message points at Pro page',
    strpos((string) $cfg['messages']['tr']['recognize_quota'], '/tr/abonelikler') !== false
    && strpos((string) $cfg['messages']['en']['recognize_quota'], '/en/plans') !== false);
check('user tier is more generous than anon',
    $cfg['limits']['user']['daily_messages'] > $cfg['limits']['anon']['daily_messages']
    && $cfg['limits']['pro']['daily_messages'] > $cfg['limits']['user']['daily_messages']);
