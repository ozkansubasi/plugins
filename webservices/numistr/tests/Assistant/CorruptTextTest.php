<?php
/**
 * Mojibake gate on retrieved KB/site chunks.
 *
 * Locks in the 2026-09-08 finding: numistr_kb serves a corrupt generation of
 * its own documents (UTF-8 stored after being decoded as GBK) alongside a
 * clean one. Roughly 45% of retrieved chunks were affected. Until the corpus
 * is repaired the assistant must not ground on, or quote, that text.
 */

require_once $root . '/helpers/DatabaseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantTools.php';

// ---- real corrupt strings, copied from live webhook responses ----
$corrupt = [
    'Beyaz Alt谋n',
    'Elektr眉m',
    'Bas谋m',
    'Yaz谋t',
    '脟evre Yaz谋t',
    '脟ok 陌yi Korunmu艧',
    '莽e艧itli sikke t眉rleri',
    'B枚l眉mler',
];

foreach ($corrupt as $s) {
    check('corrupt detected: ' . $s, NumisTRAssistantTools::isCorruptText($s) === true);
}

// ---- clean Turkish and English must survive untouched ----
$clean = [
    'Beyaz Altın',
    'Elektrüm',
    'Basım',
    'Yazıt',
    'Çevre Yazıt',
    'Çok İyi Korunmuş',
    'Exergue',
    'Hekte',
    'Ağırlık ve çap ölçüleri',
    'The term hekte denotes one sixth of a stater.',
    'Sikkenin ön yüzünde Zeus başı, arka yüzünde kartal betimlenmiştir.',
    'Âdem kâr etti, îmâ edilen şey buydu.',
    '',
];

foreach ($clean as $s) {
    check('clean survives: ' . ($s === '' ? '(empty)' : $s), NumisTRAssistantTools::isCorruptText($s) === false);
}

// ---- the other classic form: UTF-8 read as Latin-1 ----
check('latin1 mojibake detected (Ã¼)', NumisTRAssistantTools::isCorruptText('Ã¼zerinde') === true);
check('latin1 mojibake detected (Ä±)', NumisTRAssistantTools::isCorruptText('AltÄ±n') === true);

// ---- null tolerated ----
check('null is not corrupt', NumisTRAssistantTools::isCorruptText(null) === false);

// ---- a chunk is judged on title AND text together ----
$titleClean = 'Hekte';
$textDirty  = 'Hekte, bir stater\'谋n alt谋da biridir.';
check(
    'clean title with corrupt body is still corrupt',
    NumisTRAssistantTools::isCorruptText($titleClean . ' ' . $textDirty) === true
);
