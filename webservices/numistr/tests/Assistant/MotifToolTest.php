<?php
/**
 * Asistan search_coins motif parametresi (1.29.0): terim ayrıştırma, sözcük başı eşleme, araç tanımı.
 * Eskiden serbest metin yalnız başlık / darphane / hükümdara bakıyordu; "baykuşlu sikkeler" yanıtsızdı.
 */

require_once $root . '/helpers/MotifSearchHelper.php';
require_once $root . '/helpers/DatabaseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantTools.php';

// ------------------------------------------------------------ terimler ---
check('motif: virgül, noktalı virgül, dikey çizgi ayırır',
    NumisTRAssistantTools::motifTerms('Baykuş; owl | baykuş') === ['baykuş', 'owl']);
check('motif: çok sözcüklü terim bütün kalır', NumisTRAssistantTools::motifTerms('aslan başı, lion head') === ['aslan başı', 'lion head']);
check('motif: boş', NumisTRAssistantTools::motifTerms(' , ') === []);
check('motif: Türkçe İ/I', NumisTRAssistantTools::motifTerms('İSİS, IŞIK') === ['isis', 'ışık']);

// -------------------------------------------------------------- eşleme ---
$rows = [
    ['id' => 101, 'val' => 'Athena başı sağa; arka yüz: Baykuş'],
    ['id' => 102, 'val' => 'Owl standing right, head facing'],
    ['id' => 103, 'val' => 'Noktaların sınırı içinde yıldız'],          // "arı" tuzağı
    ['id' => 104, 'val' => 'Perseus elinde harpa'],                       // "arpa" tuzağı
    ['id' => 101, 'val' => 'Ön yüz: Athena; baykuş ve zeytin dalı'],      // aynı makale ikinci alan
    ['id' => 105, 'val' => '<p>Arı üç delme zımbası</p>'],
];
check('eşleme: TR ve EN betim, makale tekil',
    NumisTRAssistantTools::motifMatchIds($rows, NumisTRAssistantTools::motifTerms('baykuş, owl')) === [101, 102]);
check('eşleme: "arı" noktaların içinde DEĞİL, cümle başında evet',
    NumisTRAssistantTools::motifMatchIds($rows, NumisTRAssistantTools::motifTerms('arı')) === [105]);
check('eşleme: "arpa" harpa içinde DEĞİL',
    NumisTRAssistantTools::motifMatchIds($rows, NumisTRAssistantTools::motifTerms('arpa')) === []);
check('eşleme: üst sınır', count(NumisTRAssistantTools::motifMatchIds($rows, ['baykuş', 'owl', 'arı'], 2)) === 2);

// ---------------------------------------------------------- araç tanımı ---
$defs = NumisTRAssistantTools::definitions(['search_coins']);
$props = $defs[0]['input_schema']['properties'] ?? ($defs[0]['parameters']['properties'] ?? []);
check('araç tanımı: search_coins motif parametresi var', isset($props['motif']) && ($props['motif']['type'] ?? '') === 'string');
