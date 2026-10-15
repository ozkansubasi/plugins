<?php
/**
 * AI12 / ADR-009 K3 (1.21.0) — kullanıcıya hiçbir sikke bilgisi verilmeyen yanıt tarama hakkı düşürmez:
 * eşleşme yok VE neden modern_coin / no_coin_detected. Oturumlu kota ve oturumsuz deneme aynı kuralı kullanır.
 * Bilgi verilen her yanıtta (eşleşme, yakın aday gösterilen below_confidence / low_detail_surface, bilinmeyen neden)
 * hak bugünkü gibi düşmeli — muafiyetin genişlemesi bedava tanıma demek.
 */

require_once $root . '/helpers/QuotaHelper.php';

$F = function (array $ai) {
    return QuotaHelper::isFreeScan($ai);
};

check('modern para, eşleşme yok → hak düşmez',
    $F(['matches' => [], 'no_match' => true, 'no_match_reason' => 'modern_coin']) === true);
check('sikke bulunamadı, eşleşme yok → hak düşmez',
    $F(['matches' => [], 'no_match' => true, 'no_match_reason' => 'no_coin_detected']) === true);
check('below_confidence (yakın aday + kart gösterilir) → hak düşer',
    $F(['matches' => [], 'no_match' => true, 'no_match_reason' => 'below_confidence']) === false);
check('low_detail_surface → hak düşer',
    $F(['matches' => [], 'no_match' => true, 'no_match_reason' => 'low_detail_surface']) === false);
check('zayıf adaylarla gelen no_coin_detected (aday gösterilir) → hak düşer',
    $F(['matches' => [['article_id' => 1, 'confidence' => 0.35]], 'no_match' => false,
        'no_match_reason' => 'no_coin_detected']) === false);
check('no_match=true ama eşleşme dolu (tutarsız yanıt) → hak düşer',
    $F(['matches' => [['article_id' => 1]], 'no_match' => true, 'no_match_reason' => 'modern_coin']) === false);
check('doğrulanmış eşleşme → hak düşer',
    $F(['matches' => [['article_id' => 3404, 'confidence' => 1.0]], 'no_match' => false, 'no_match_reason' => null]) === false);
check('bilinmeyen neden → hak düşer', $F(['matches' => [], 'no_match' => true, 'no_match_reason' => 'Modern_Coin']) === false);
check('no_match alanı yok (eski servis) → hak düşer', $F(['matches' => [], 'no_match_reason' => 'modern_coin']) === false);
check('boş yanıt → hak düşer', $F([]) === false);

// ---- iki yol da aynı kararı kullanıyor (kaynak düzeyi: oturumlu kota + oturumsuz deneme) ----
$rec = (string) file_get_contents($root . '/controllers/RecognitionController.php');
check('oturumlu yol: consumeScan muafiyet koşulunun içinde',
    (bool) preg_match('/if \(!QuotaHelper::isFreeScan\(\$core\[\'ai\'\]\)\) \{\s*try \{\s*\$quotaHelper->consumeScan\(\$user\);/', $rec));
check('oturumsuz yol: consume muafiyet koşulunun içinde',
    (bool) preg_match('/\$free = QuotaHelper::isFreeScan\(\$core\[\'ai\'\]\);\s*if \(!\$free\) \{\s*try \{\s*\$trial->consume\(\$deviceHash\);/', $rec));
check('oturumsuz yol: hak düşmediyse kalan hak aynı bildirilir', strpos($rec, '$trial->quota($free ? $used : $used + 1)') !== false);
check('istek her durumda günlüğe yazılır (hız sınırı muafiyetten etkilenmez)',
    substr_count($rec, 'self::logSuccess(') === 2);
