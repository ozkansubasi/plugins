<?php
/**
 * AI yanıtının uygulamaya geçişi (formatResults). 1.16.5 (2026-09-29): Faz D `verified` ve AI4 `near_matches`
 * üst düzey alanları eklendi. Eski alanlar ve eşleşme içindeki yeni alanlar (basis, verified) aynen geçmeli;
 * eski AI servisi (alan yok) ya da bozuk veri uygulamayı kırmamalı.
 */

require_once $root . '/helpers/AiServiceHelper.php';

$h = new AiServiceHelper();

$r = $h->formatResults([
    'matches' => [['rank' => 1, 'article_id' => 3404, 'confidence' => 1.0, 'basis' => 'both', 'verified' => true]],
    'confidence' => 1.0, 'method' => 'dinov3_sam2_fazABCDE', 'processing_time_ms' => 8400,
    'no_match' => false, 'no_match_reason' => null, 'quality' => ['obverse' => ['coin_detected' => true]],
    'verified' => true, 'near_matches' => [],
]);
check('doğrulanmış: verified=true geçer', $r['verified'] === true);
check('doğrulanmış: near_matches boş dizi', $r['near_matches'] === []);
check('eşleşme içi basis/verified aynen geçer', $r['matches'][0]['basis'] === 'both' && $r['matches'][0]['verified'] === true);
check('eski alanlar aynen', $r['method'] === 'dinov3_sam2_fazABCDE' && $r['no_match'] === false
    && $r['quality']['obverse']['coin_detected'] === true && $r['processing_time_ms'] === 8400);

$r2 = $h->formatResults([
    'matches' => [], 'no_match' => true, 'no_match_reason' => 'below_confidence', 'verified' => false,
    'near_matches' => [['rank' => 1, 'article_id' => 11704, 'confidence' => 0.25, 'basis' => 'obverse', 'image_id' => 55]],
]);
check('yakın aday geçer (basis ile)', count($r2['near_matches']) === 1 && $r2['near_matches'][0]['basis'] === 'obverse');
check('yakın adaya küçük resim eklenir', strpos($r2['near_matches'][0]['thumbnail_url'], 'id=55') !== false);
check('eşleşme yok: verified=false, no_match=true', $r2['verified'] === false && $r2['no_match'] === true);

$r3 = $h->formatResults(['matches' => [['article_id' => 1, 'confidence' => 0.5]]]);   // eski AI servisi
check('eski servis: verified=false, near_matches=[]', $r3['verified'] === false && $r3['near_matches'] === []);

$r4 = $h->formatResults(['matches' => [], 'near_matches' => 'bozuk']);
check('bozuk near_matches -> []', $r4['near_matches'] === []);
