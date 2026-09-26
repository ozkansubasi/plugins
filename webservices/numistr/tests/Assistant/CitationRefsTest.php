<?php
/**
 * Explain-route citations and the anonymous-only app reminder (1.16.1).
 *
 * 1. Every source carries the [n] numbers that point at it. The numbers do not
 *    follow the source list (terminology chunks share one glossary link), so a
 *    client that counted links would label the wrong article.
 * 2. The "register for free and use the app" reminder is for anonymous visitors
 *    only; members and app users (sign-in required) already have an account.
 */

require_once $root . '/helpers/AuthHelper.php';
require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';
require_once $root . '/controllers/AssistantController.php';

$C        = 'AssistantController';
$glossary = 'https://numistr.org/tr/numizmatik-karsiliklar';
$a1       = 'https://numistr.org/tr/blog/kistophoros';
$a2       = 'https://numistr.org/tr/blog/bergama';

$kb   = [['title' => 'Kistophoros', 'text' => 'k1'], ['title' => 'Cista mystica', 'text' => 'k2']];
$site = [['title' => 'Kistophoros yazisi', 'url' => $a1, 'text' => 's1'], ['title' => 'Bergama', 'url' => $a2, 'text' => 's2']];

// ---- two terminology chunks + two articles ----
$ctx = $C::explainContext($kb, $site, 'tr', $glossary);
check('context is numbered 1..4', count($ctx['lines']) === 4 && str_starts_with($ctx['lines'][3], '[4] Bergama'));
check('three sources: glossary + two articles', count($ctx['sources']) === 3);
check('glossary carries both terminology numbers', $ctx['sources'][0]['url'] === $glossary && $ctx['sources'][0]['refs'] === [1, 2]);
check('first article is [3] although it is the second link', $ctx['sources'][1]['url'] === $a1 && $ctx['sources'][1]['refs'] === [3]);
check('second article is [4]', $ctx['sources'][2]['refs'] === [4]);

// ---- articles only: numbers start at 1 ----
$ctx = $C::explainContext([], $site, 'tr', $glossary);
check('articles only: no glossary link', count($ctx['sources']) === 2);
check('articles only: [1] and [2]', $ctx['sources'][0]['refs'] === [1] && $ctx['sources'][1]['refs'] === [2]);

// ---- language without a glossary page: chunks keep their numbers ----
$ctx = $C::explainContext($kb, $site, 'de', '');
check('no glossary url: terminology not linked', count($ctx['sources']) === 2);
check('no glossary url: articles still [3] and [4]', $ctx['sources'][0]['refs'] === [3] && $ctx['sources'][1]['refs'] === [4]);

// ---- anonymous-only reminder ----
$config = require $root . '/config/assistant.php';

foreach (['tr', 'en'] as $lang) {
    $member = $C::rulesFor($config, $lang, false);
    $anon   = $C::rulesFor($config, $lang, true);
    check("$lang: member rules do not mention the app", stripos($member, 'AnatolianCoins') === false);
    check("$lang: anonymous rules add the reminder", stripos($anon, 'AnatolianCoins') !== false);
    check("$lang: anonymous rules start with the member rules", str_starts_with($anon, $member));
}
