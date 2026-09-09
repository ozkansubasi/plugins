<?php
/**
 * Pre-fetched settlement context must not become unearned citations.
 *
 * The settlement route fetches nearby articles before the model answers (1.9.3),
 * so a question about a place that does not exist still retrieves the four
 * closest real settlements. Attaching those to a "no record of this" answer
 * would look like they backed it - the citation half of the fabrication problem
 * closed on 2026-09-06.
 */

require_once $root . '/helpers/AuthHelper.php';
require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';
require_once $root . '/controllers/AssistantController.php';

$C = 'AssistantController';

$zara   = 'https://numistr.org/tr/pontus-yerlesimleri/31006-zara';
$anaz   = 'https://numistr.org/tr/kilikya-yerlesimleri/31691-anazarbos-caesarea-ioustin-ian-oupolis';
$nikop  = 'https://numistr.org/tr/kapadokya-yerlesimleri/31234-nicopolis';

$pre = [
    $zara  => ['title' => 'Zara', 'url' => $zara],
    $anaz  => ['title' => 'Anazarbos/Caesarea/Ioustin(ian)oupolis', 'url' => $anaz],
    $nikop => ['title' => 'Nicopolis', 'url' => $nikop],
];

// ---- the case that prompted this: invented place, honest answer ----
$answer = 'Zarkanopolis hakkinda NumisTR veritabaninda bilgi bulamadim.';
$kept   = $C::sourcesSupportedByAnswer($pre, $answer);
check('invented place: no sources attached', $kept === []);

// "Zara" must not match inside "Zarkanopolis"
check('title does not match inside a longer word', !isset($kept[$zara]));

// ---- a real answer keeps its source ----
$answer = 'Zara, antik Pontus bolgesinde yer alan bir yerlesimdir.';
$kept   = $C::sourcesSupportedByAnswer($pre, $answer);
check('named settlement keeps its source', array_keys($kept) === [$zara]);

// ---- the URL pasted inline also counts ----
$answer = 'Daha fazla bilgi icin: ' . $nikop;
$kept   = $C::sourcesSupportedByAnswer($pre, $answer);
check('inline url counts as support', array_keys($kept) === [$nikop]);

// ---- a title with regex metacharacters must not blow up or over-match ----
$answer = 'Anazarbos/Caesarea/Ioustin(ian)oupolis, Kilikya bolgesindedir.';
$kept   = $C::sourcesSupportedByAnswer($pre, $answer);
check('regex metacharacters in title handled', array_keys($kept) === [$anaz]);

// ---- Turkish letters are word characters, so no false boundary match ----
$pre2   = ['u' => ['title' => 'Kesim', 'url' => 'u']];
check('no match inside a suffixed word', $C::sourcesSupportedByAnswer($pre2, 'Kesimler hakkinda') === []);
check('match when the word stands alone', $C::sourcesSupportedByAnswer($pre2, 'Kesim nedir') !== []);

// ---- degenerate inputs ----
check('empty answer keeps nothing', $C::sourcesSupportedByAnswer($pre, '') === []);
check('empty source list stays empty', $C::sourcesSupportedByAnswer([], 'herhangi bir cevap') === []);

// ---- tool-registered sources are not evidence either (1.9.5) ----
// The model calls a search tool because the prompt tells it to; for a place that
// does not exist the tool returns the NEAREST real settlements, and those were
// being cited. Same rule applies to them.
$answer = 'Maalesef NumisTR veritabaninda Zarkanopolis hakkinda kayit bulunamadi.';
check(
    'tool results about other places are dropped too',
    $C::sourcesSupportedByAnswer($pre, $answer) === []
);

// ---- glossary exemption ----
$glossary = 'https://numistr.org/tr/numizmatik-karsiliklar';
$withGlossary = $pre + [$glossary => ['title' => 'Numizmatik terimler', 'url' => $glossary]];

check(
    'glossary survives although the answer never names it',
    array_keys($C::sourcesSupportedByAnswer($withGlossary, $answer, [$glossary])) === [$glossary]
);
check(
    'without the exemption the glossary would be dropped',
    $C::sourcesSupportedByAnswer($withGlossary, $answer) === []
);
check(
    'exemption does not rescue unrelated sources',
    array_keys($C::sourcesSupportedByAnswer($withGlossary, $answer, [$glossary])) === [$glossary]
);
