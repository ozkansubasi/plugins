<?php
/**
 * 1.20.0 — lisansı doğrulanmamış görseller REST API yanıtlarından çıkarılır (App Store "Content Rights").
 * Serbest (license_ok=1) ve NC (license_ok=0 + lisans metni; ADR-008 §3.2) kalır. Tek kaynak coins_images.license/license_ok.
 */

require_once $root . '/helpers/ImageLicenseHelper.php';
require_once $root . '/helpers/AiServiceHelper.php';

$L = 'NumisTRImageLicense';

// ------------------------------------------------------------------- saf karar ---
check('serbest (license_ok=1, PDM) → görünür', $L::isVisible('PDM 1.0', '1') === true);
check('serbest, int 1 → görünür', $L::isVisible('Domaine public', 1) === true);
check('serbest, lisans metni boş ama license_ok=1 → görünür (olumlu doğrulama)', $L::isVisible(null, '1') === true);
check('NC (BM, CC BY-NC-SA 4.0, license_ok=0) → görünür (ADR-008 §3.2)', $L::isVisible('CC BY-NC-SA 4.0', '0') === true);
check('NC (Ashmolean, "yalniz ticari olmayan") → görünür', $L::isVisible('yalniz ticari olmayan', 0) === true);
check('doğrulanmamış: license_ok NULL → gizli (Kenom/DNB)', $L::isVisible(null, null) === false);
check('doğrulanmamış: license_ok NULL, metin boş → gizli', $L::isVisible('', null) === false);
check('doğrulanmamış: license_ok=0 ama metin YOK → gizli (sütun varsayılanı 0 olsa da)', $L::isVisible(null, '0') === false && $L::isVisible('   ', 0) === false);
check('beklenmeyen değer (2, "x") → gizli', $L::isVisible('PDM 1.0', '2') === false && $L::isVisible('PDM 1.0', 'x') === false);

// ------------------------------------------------------------------- SQL karşılığı ---
$fakeDb = new class {
    public function quoteName($n) { return '`' . str_replace('.', '`.`', $n) . '`'; }
};
$sql = $L::sqlVisible($fakeDb, 'ci');
check('SQL: isVisible ile aynı kural', $sql === "(`ci`.`license_ok` = 1 OR (`ci`.`license_ok` = 0 AND `ci`.`license` IS NOT NULL AND TRIM(`ci`.`license`) <> ''))");
check('SQL: NULL license_ok hiçbir kola uymaz (= 1 / = 0 NULL\'da yanlış)', strpos($sql, 'IS NULL') === false);

// ------------------------------------------------------------------- ayar ---
check('varsayılan AÇIK', $L::enabled([]) === true);
check('kapatma anahtarı', $L::enabled(['IMAGE_LICENSE' => ['hide_unverified' => false]]) === false);
$repo = include $root . '/config/constants.php';
check('repo: IMAGE_LICENSE.hide_unverified = true', $L::enabled($repo) === true && $repo['IMAGE_LICENSE']['hide_unverified'] === true);

// ------------------------------------------------------------------- tanıma küçük resimleri ---
$h   = new AiServiceHelper();
$fmt = $h->formatResults([
    'matches'      => [
        ['rank' => 1, 'article_id' => 3404, 'confidence' => 0.9, 'image_id' => 101],   // serbest
        ['rank' => 2, 'article_id' => 5000, 'confidence' => 0.7, 'image_id' => 202],   // Kenom (doğrulanmamış)
        ['rank' => 3, 'article_id' => 6000, 'confidence' => 0.6],                      // image_id yok
    ],
    'near_matches' => [['rank' => 1, 'article_id' => 7000, 'confidence' => 0.2, 'image_id' => 303]],  // DB'de yok
]);
check('kimlikler: matches + near_matches, tekil', $L::recognitionImageIds($fmt) === [101, 202, 303]);
$out = $L::scrubRecognition($fmt, [101]);
check('görünür görselin küçük resmi kalır', strpos((string) $out['matches'][0]['thumbnail_url'], 'id=101') !== false);
check('doğrulanmamış görselin küçük resmi null', $out['matches'][1]['thumbnail_url'] === null);
check('eşleşmenin kendisi kalır (makale, güven, sıra)', $out['matches'][1]['article_id'] === 5000 && $out['matches'][1]['confidence'] === 0.7 && count($out['matches']) === 3);
check('image_id\'siz eşleşmeye alan eklenmez', !array_key_exists('thumbnail_url', $out['matches'][2]));
check('DB\'de olmayan görsel (eski dizin kaydı) → null', $out['near_matches'][0]['thumbnail_url'] === null);
check('diğer alanlar dokunulmaz', $out['confidence'] === $fmt['confidence'] && $out['no_match'] === $fmt['no_match']);
check('sorgu başarısız (görünür liste boş) → hiçbir küçük resim yok', $L::scrubRecognition($fmt, [])['matches'][0]['thumbnail_url'] === null);
check('visibleIds: boş girdi sorgusuz boş', $L::visibleIds($fakeDb, [0, -3]) === []);

// ------------------------------------------------------------- kaynak denetimi ---
$main = (string) file_get_contents($root . '/numistr.php');
$s    = strpos($main, 'private function getVariantImages(');
$e    = strpos($main, 'private function ', $s + 10);
$gv   = $s === false ? '' : substr($main, $s, $e - $s);
check('getVariantImages: doğrulanmamış satır yanıta girmez', strpos($gv, 'NumisTRImageLicense::isVisible($r[\'license\'] ?? null, $r[\'license_ok\'] ?? null)') !== false
    && strpos($gv, 'continue;') !== false);
check('getVariantImages: license + license_ok seçiliyor', strpos($gv, "ci.license'") !== false && strpos($gv, "ci.license_ok'") !== false);
check('getVariantImages: kapatma anahtarı + yardımcı yoksa eski davranış', strpos($gv, "class_exists('NumisTRImageLicense') && NumisTRImageLicense::enabled(\$this->config)") !== false);
check('images ucu ve ?include=images aynı yoldan', substr_count($main, '$this->getVariantImages(') >= 2);
check('filter[has_images]: yalnız görünür görsel sayılır', strpos($main, "NumisTRImageLicense::sqlVisible(\$db, 'ci')") !== false);
check('yardımcı güvenli yükleniyor', strpos($main, "if (file_exists(__DIR__ . '/helpers/ImageLicenseHelper.php'))") !== false);

$rec = (string) file_get_contents($root . '/controllers/RecognitionController.php');
check('tanıma: oturumlu ve oturumsuz yolun ikisi de küçük resmi süzer',
    substr_count($rec, 'self::licensedThumbnails($core[\'helper\']->formatResults($core[\'ai\']), $config)') === 2
    && strpos($rec, '->formatResults($core[\'ai\']);') === false);
check('görsel URL\'si üreten başka yer yok (view=gorsel yalnız bilinen yerlerde)',
    substr_count($main, "'view'=>'gorsel'") + substr_count($main, "'view' => 'gorsel'") === 2
    && substr_count((string) file_get_contents($root . '/helpers/AiServiceHelper.php'), 'view=gorsel') === 1);
