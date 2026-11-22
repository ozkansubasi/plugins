<?php
/**
 * Explain-route citations (1.16.1) and no model-written app reminder (1.16.2).
 *
 * 1. Every source carries the [n] numbers that point at it. The numbers do not
 *    follow the source list (the chunks of one term share its row), so a client
 *    that counted rows would label the wrong article.
 *    1.27.0: one row per term, linked only when the term is on the glossary page;
 *    an answer no longer ends on a lead-in whose link was removed.
 * 2. The "register for free and use the app" reminder is not a model rule: it
 *    was added to fact questions, reached members and app users (sign-in
 *    required), and for anonymous visitors duplicated the fixed cta_register
 *    sentence the server appends on the site/tools routes.
 */

require_once $root . '/helpers/AuthHelper.php';
require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';
require_once $root . '/helpers/Assistant/AssistantGlossary.php';
require_once $root . '/controllers/AssistantController.php';

$C        = 'AssistantController';
$glossary = 'https://numistr.org/tr/numizmatik-karsiliklar';
$a1       = 'https://numistr.org/tr/blog/kistophoros';
$a2       = 'https://numistr.org/tr/blog/bergama';

// Glossary page contents for the tests (the real list is config/glossary_terms.php).
$terms = ['tr' => ['drahmi' => 'Drahmi (Drachm)', 'drachm' => 'Drahmi (Drachm)', 'altın' => 'Altın (Gold - AV)'], 'en' => []];

// "deka drahmi nedir" shape (2026-11-22): three chunks of one term (one of them the
// English copy, returned under the Turkish title), one other term on the page, one
// term that is not on the page, then two articles.
$kb = [
    ['title' => 'Dekadrahmi', 'text' => 'k1'],
    ['title' => 'Dekadrahmi', 'text' => 'k2'],
    ['title' => 'Drahmi', 'text' => 'k3'],
    ['title' => 'dekadrahmi', 'text' => 'k4 (english copy)'],
    ['title' => 'Tetradrahmi', 'text' => 'k5'],
];
$site = [['title' => 'Kistophoros yazisi', 'url' => $a1, 'text' => 's1'], ['title' => 'Bergama', 'url' => $a2, 'text' => 's2']];

$ctx = $C::explainContext($kb, $site, 'tr', $glossary, $terms);
$src = $ctx['sources'];
check('context is numbered 1..7, one number per chunk', count($ctx['lines']) === 7 && str_starts_with($ctx['lines'][6], '[7] Bergama'));
check('one row per term + two articles', count($src) === 5);
check('term rows first, in order of first appearance', array_column(array_slice($src, 0, 3), 'title') === ['Dekadrahmi', 'Drahmi', 'Tetradrahmi']);
check('chunks of one term share its row (any case)', $src[0]['refs'] === [1, 2, 4]);
check('term rows are marked kind=term', $src[0]['kind'] === 'term' && $src[2]['kind'] === 'term');
check('term NOT on the glossary page has no link', $src[0]['url'] === '' && $src[2]['url'] === '');
check('term on the page links to its entry (text fragment)', $src[1]['url'] === $glossary . '#:~:text=Drahmi%20%28Drachm%29' && $src[1]['refs'] === [3]);
check('first article is [6] although it is the fourth row', $src[3]['url'] === $a1 && $src[3]['refs'] === [6]);
check('second article is [7]', $src[4]['refs'] === [7]);
check('articles carry no kind', !isset($src[3]['kind']));
check('no row repeats the bare glossary link', !in_array($glossary, array_column($src, 'url'), true));

// ---- articles only: numbers start at 1 ----
$ctx = $C::explainContext([], $site, 'tr', $glossary, $terms);
check('articles only: no term rows', count($ctx['sources']) === 2);
check('articles only: [1] and [2]', $ctx['sources'][0]['refs'] === [1] && $ctx['sources'][1]['refs'] === [2]);

// ---- English: the English glossary page carries no terms -> rows without links ----
$ctx = $C::explainContext([['title' => 'Drachm', 'text' => 'k']], $site, 'en', 'https://numistr.org/en/numismatic-dictionary', $terms);
check('en: term row kept, unlinked', $ctx['sources'][0]['title'] === 'Drachm' && $ctx['sources'][0]['url'] === '');

// ---- language without a glossary page: term rows unlinked, numbers kept ----
$ctx = $C::explainContext($kb, $site, 'de', '', $terms);
check('no glossary url: term rows still listed, none linked', count($ctx['sources']) === 5 && implode('', array_column(array_slice($ctx['sources'], 0, 3), 'url')) === '');
check('no glossary url: articles still [6] and [7]', $ctx['sources'][3]['refs'] === [6] && $ctx['sources'][4]['refs'] === [7]);

// ---- NumisTRAssistantGlossary ----
$G = 'NumisTRAssistantGlossary';
check('normalise: Turkish dotted capital I', $G::normalise('İçbükey') === 'içbükey');
check('normalise: dotless capital I', $G::normalise('IŞIK') === 'ışık');
check('normalise: quotes and spaces', $G::normalise('  Aegina  "Kaplumbağa" ') === 'aegina kaplumbağa');
check('hyphen percent-encoded in fragment', $G::termUrl('Altın', 'tr', $glossary, $terms) === $glossary . '#:~:text=Alt%C4%B1n%20%28Gold%20%2D%20AV%29');
check('English name on the Turkish page links too', $G::termUrl('drachm', 'tr', $glossary, $terms) !== '');
check('empty title -> no link', $G::termUrl('', 'tr', $glossary, $terms) === '');
check('empty glossary url -> no link', $G::termUrl('Drahmi', 'tr', '', $terms) === '');
$rows = $G::termSources([['title' => ''], ['title' => 'Drahmi']], [], 'tr', $glossary, $terms);
check('untitled chunk gets a generic row title', array_column(array_values($rows), 'title') === ['Numizmatik terim', 'Drahmi']);
check('no refs given -> rows carry no refs key', !isset(array_values($rows)[1]['refs']));

// Real generated list: every value must be a non-empty page entry.
$real = $G::pageTerms('tr');
check('generated glossary list loads (tr)', count($real) > 50 && !in_array('', $real, true));
check('generated list: Drahmi is on the page, Dekadrahmi is not', isset($real['drahmi']) && !isset($real['dekadrahmi']));
check('generated list: section headers are not terms', !isset($real['temel tanım']) && !isset($real['detaylı açıklama']));
check('generated list: en page has no terms', $G::pageTerms('en') === []);

// ---- tool routes: a term row survives only if the answer names the term ----
$tr = $G::termSources([['title' => 'Dekadrahmi'], ['title' => 'Drahmi'], ['title' => 'Hekte']], [], 'tr', $glossary, $terms);
$kept = $C::sourcesSupportedByAnswer($tr, "Dekadrahmiler nadirdir; drahminin on katidir.", [$glossary]);
check('term named with a suffix / lower case is kept', isset($kept['term:dekadrahmi']) && isset($kept['term:drahmi']));
check('term the answer never names is dropped', !isset($kept['term:hekte']));
check('unlinked term row survives the filter (not keyed by its empty url)', ($kept['term:dekadrahmi']['url'] ?? null) === '');
$short = $G::termSources([['title' => 'As'], ['title' => 'Ay']], [], 'tr', $glossary, $terms);
$kept  = $C::sourcesSupportedByAnswer($short, 'Asya ve Ayasoluk darphaneleri; Roma as sikkesi.', []);
check('short term: whole word only ("As" not in "Asya", "Ay" not in "Ayasoluk")', array_keys($kept) === ['term:as']);

// ---- an answer must not end on a lead-in that points at nothing ----
$base = "Dekadrahmi on drahmidir [1].";
check('dangling closing lead-in dropped', $C::dropDanglingLead($base . "\n\nDaha fazla bilgi için NumisTR terminoloji sayfasını ziyaret edebilirsiniz:") === $base);
check('dangling lead-in after a sentence on the same line dropped', $C::dropDanglingLead($base . " Ayrıntı için bakınız: ") === $base);
check('answer not ending on a colon untouched', $C::dropDanglingLead($base) === $base);
check('colon introducing a list further up untouched', $C::dropDanglingLead("Türleri:\n- tetradrahmi\n- dekadrahmi") === "Türleri:\n- tetradrahmi\n- dekadrahmi");
check('a one-sentence answer ending on a colon is left alone', $C::dropDanglingLead('Bakınız:') === 'Bakınız:');
check('removed link + dangling lead-in end to end',
    $C::dropDanglingLead($C::dropUnknownSiteLinks($base . "\n\nDaha fazla bilgi için: https://numistr.org/tr/terimler/dekadrahmi", [$glossary])) === $base);

// ---- no model-written app reminder ----
$config = require $root . '/config/assistant.php';

foreach (['tr', 'en'] as $lang) {
    $prompts = $config['prompts'][$lang];
    check("$lang: rules do not mention the app", stripos($prompts['rules'], 'AnatolianCoins') === false);
    check("$lang: no identify_cta prompt", !isset($prompts['identify_cta']));
    check("$lang: fixed anonymous sentence still configured", stripos((string) ($config['messages'][$lang]['cta_register'] ?? ''), 'AnatolianCoins') !== false);
}
