<?php
/**
 * Site-corpus word gate, and the two coin regions whose URLs were wrong.
 *
 * Measured 2026-09-09 on the live site-search webhook: numistr_site had NO
 * protection at all - all ten invented subjects came back with five excerpts
 * carrying real article titles and real public URLs, which is exactly the
 * material for the failure closed on 2026-09-06. A score threshold cannot
 * separate them: invented queries top out at 0.600 while genuine ones start at
 * 0.481, so a fifth of real questions sit below the invented ceiling.
 */

require_once $root . '/helpers/DatabaseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantTools.php';

$T = 'NumisTRAssistantTools';

// ---------------------------------------------------------------- stems -----
check('stem: default five characters', $T::matchStem('zarkanion') === 'zarka');
check('stem: seven for the site corpus', $T::matchStem('zarkanion', 7) === 'zarkani');
check('stem: short token untouched', $T::matchStem('obol', 7) === 'obol');

// The collision that forced seven: "Xanthoderos" is invented, "Xanthos" is a real
// Lycian city, and they share the first five letters.
check('five-letter prefix collides', $T::matchStem('xanthoderos') === $T::matchStem('xanthos'));
check('seven-letter prefix does not', $T::matchStem('xanthoderos', 7) !== $T::matchStem('xanthos', 7));

// ---------------------------------------------------- corpus stop words -----
// "stater" is a generic topic word in an article search but IS the subject in the
// terminology KB. The lists must stay separate.
check('site corpus drops stater', $T::queryTerms('stater sikkeleri hakkinda bilgi', 'site') === []);
check('kb corpus keeps stater', in_array('stater', $T::queryTerms('stater nedir', 'kb'), true));
check('site corpus drops generic article words', $T::queryTerms('antik kenti kalintilari', 'site') === []);
check('site corpus keeps the real subject', $T::queryTerms('Pergonaut antik kenti kalintilari', 'site') === ['pergonaut']);

// ------------------------------------------------------------ site gate -----
$gercek = [
    ['title' => 'Perge', 'text' => 'Perge, Pamfilya bolgesinde antik bir kenttir.'],
    ['title' => 'Perge Sikkeleri', 'text' => 'Perge darphanesinde basilan sikkeler.'],
];

check(
    'invented city refused even though Perge scores high',
    $T::kbCanAnswer($T::queryTerms('Pergonaut antik kenti kalintilari', 'site'), $gercek, 7) === false
);
check(
    'real city answerable',
    $T::kbCanAnswer($T::queryTerms('Perge antik kenti kalintilari', 'site'), $gercek, 7) === true
);

// The five-letter stem would have let the invented king through on "Xanthos".
$xanthos = [['title' => 'Xanthos Hanedan Sikkeleri', 'text' => 'Xanthos, Likya bolgesinin merkezi.']];
check(
    'invented king slips through at five',
    $T::kbCanAnswer($T::queryTerms('Kral Xanthoderos', 'site'), $xanthos, 5) === true
);
check(
    'invented king refused at seven',
    $T::kbCanAnswer($T::queryTerms('Kral Xanthoderos', 'site'), $xanthos, 7) === false
);

// ------------------------------------------------------------- coin urls ----
$base = 'https://numistr.org';

check(
    'ordinary region keeps the normal shape',
    $T::coinUrl($base, 'tr', 'pamphylia-coins', 3404, 'aspendus-x')
        === 'https://numistr.org/tr/anatolian-coins/pamphylia-coins/3404-aspendus-x'
);

// Verified against the live record 12093: the Turkish menu alias is misspelled.
check(
    'cilicia TR uses the site typo alias',
    $T::coinUrl($base, 'tr', 'cilicia-coins', 12093, 'adana-levante-1984-3-7')
        === 'https://numistr.org/tr/anatolian-coins/clicia-coins/12093-adana-levante-1984-3-7'
);
check(
    'cilicia EN keeps the correct spelling',
    $T::coinUrl($base, 'en', 'cilicia-coins', 12093, 'adana-levante-1984-3-7')
        === 'https://numistr.org/en/anatolian-coins/cilicia-coins/12093-adana-levante-1984-3-7'
);

// Verified against the live record 15060: this region is not under anatolian-coins.
check(
    'other regions TR sits outside anatolian-coins',
    $T::coinUrl($base, 'tr', 'other-ancient-regions-coins', 15060, 'arsames-x')
        === 'https://numistr.org/tr/other-ancient-place-coins/15060-arsames-x'
);
check(
    'other regions EN uses its own alias',
    $T::coinUrl($base, 'en', 'other-ancient-regions-coins', 15060, 'arsames-x')
        === 'https://numistr.org/en/other-ancient-regions/15060-arsames-x'
);
check(
    'unknown language falls back to tr',
    mb_strpos($T::coinUrl($base, 'de', 'pamphylia-coins', 1, 'x'), '/tr/') !== false
);
