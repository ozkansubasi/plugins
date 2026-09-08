<?php
/**
 * The KB word gate.
 *
 * Locks in the 2026-09-08 measurement: a similarity threshold cannot separate
 * real terms from invented ones (real p5 0.346, invented max 0.454 - the ranges
 * overlap), so searchKb() additionally requires the retrieved text to contain
 * the words the reader asked about. Invented queries then produce nothing and
 * the assistant says it does not know, which is the whole point.
 */

require_once $root . '/helpers/DatabaseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantTools.php';

$T = 'NumisTRAssistantTools';

// ---------------------------------------------------------------- folding ---
check('fold: Turkish capitals', $T::normaliseForMatch('BASIM İĞNE') === 'basim igne');
check('fold: diacritics', $T::normaliseForMatch('Çok İyi Korunmuş') === 'cok iyi korunmus');
check('fold: dotless i', $T::normaliseForMatch('Altın') === 'altin');
check('fold: English untouched', $T::normaliseForMatch('Exergue') === 'exergue');

// ------------------------------------------------------------ query terms ---
check('terms: question words dropped', $T::queryTerms('drahmi nedir') === ['drahmi']);
check('terms: English question words dropped', $T::queryTerms('what is a drahmi') === ['drahmi']);
check('terms: short words dropped', $T::queryTerms('ne is a') === []);
check('terms: duplicates collapsed', $T::queryTerms('drahmi drahmi nedir') === ['drahmi']);

// "sikkesi" must go the same way as "sikke". Without the stem check,
// "zarkanion sikkesi nedir" matches every coin chunk and the invented term
// rides through the gate.
check('terms: inflected generic noun dropped via stem', $T::queryTerms('zarkanion sikkesi nedir') === ['zarkanion']);
check('terms: content words kept', $T::queryTerms('elektrum stater agirligi') === ['elektrum', 'stater', 'agirligi']);

// ------------------------------------------------------------ stem prefix ---
check('stem: long word cut to five', $T::matchStem('zarkanion') === 'zarka');
check('stem: short word intact', $T::matchStem('obol') === 'obol');

// --------------------------------------------------------- chunk mentions ---
check(
    'chunk: suffix absorbed by prefix match',
    $T::chunkMentionsTerms(['drahmisi'], 'Drahmi antik Yunan gumus sikke birimidir.') === true
);
check(
    'chunk: Turkish spelling matched after folding',
    $T::chunkMentionsTerms(['basim'], 'Basım işlemi kalıpla yapılır.') === true
);
check(
    'chunk: unrelated chunk rejected',
    $T::chunkMentionsTerms(['zarkanion'], 'Karza, Kappadokia bolgesinde bir yerlesimdir.') === false
);
check(
    'chunk: no terms means no opinion',
    $T::chunkMentionsTerms([], 'herhangi bir metin') === true
);

// ------------------------------------------------------------ query gate ----
$gercek = [
    ['title' => 'Drahmi', 'text' => 'Drahmi, antik Yunan gumus sikke birimidir.'],
    ['title' => 'Tetradrahmi', 'text' => 'Dort drahmi degerindedir.'],
];
$yerlesim = [
    ['title' => '*Karza', 'text' => 'Kappadokia bolgesinde antik bir yerlesim.'],
    ['title' => 'Zara', 'text' => 'Sivas ilinde antik bir yerlesim yeri.'],
];

check(
    'gate: real term present -> answerable',
    $T::kbCanAnswer($T::queryTerms('drahmi nedir'), $gercek) === true
);
check(
    'gate: invented term absent -> refused',
    $T::kbCanAnswer($T::queryTerms('zarkanion nedir'), $yerlesim) === false
);

// The case a plain "at least one word matches" rule lets through: the invented
// term is carried by real generic words. Measured 2026-09-08: 10 of 30 such
// queries passed until the gate began requiring every 6+ character word.
check(
    'gate: invented term with real generic words -> still refused',
    $T::kbCanAnswer($T::queryTerms('zarkanion antik sikke birimi nedir'), $yerlesim) === false
);

// Words under six characters are generic; requiring them refused genuine
// questions without making the gate any safer.
check(
    'gate: short generic words are not required',
    $T::kbCanAnswer($T::queryTerms('kac drahmi eder'), $gercek) === true
);
check(
    'gate: no content words -> score decides alone',
    $T::kbCanAnswer($T::queryTerms('bu ne'), $yerlesim) === true
);
check(
    'gate: empty result set refuses a specific term',
    $T::kbCanAnswer($T::queryTerms('zarkanion nedir'), []) === false
);
