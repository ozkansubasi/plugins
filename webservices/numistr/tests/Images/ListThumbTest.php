<?php
/**
 * 1.25.0 (M36, 13 Kas 2026) — liste yanıtına küçük görsel. Uygulama liste sayfası başına 20 ayrı
 * /v1/variants/{id}/images isteği atıyordu (iPhone'da kaydırmada 429). Seçim görsel ucuyla aynı kurallar:
 * lisansı doğrulanmamış ve kaynağı erişilemeyen görsel atlanır; sikke-gorsel-url yalnız özgün ilk satır görünürse.
 */

require_once $root . '/helpers/ImageLicenseHelper.php';

$L = 'NumisTRImageLicense';

$rows = [
    // sikke 10: ilk satır doğrulanmamış (gizli) → ikinci satır seçilir, özel adres ona BAĞLANMAZ
    ['coin_id' => 10, 'image_id' => 101, 'remote_url' => 'https://a/101.jpg', 'license' => null, 'license_ok' => null, 'source_unavailable' => 0],
    ['coin_id' => 10, 'image_id' => 102, 'remote_url' => 'https://a/102.jpg', 'license' => 'CC BY', 'license_ok' => '1', 'source_unavailable' => 0],
    // sikke 20: ilk satır görünür → özel adres remote_url olur
    ['coin_id' => 20, 'image_id' => 201, 'remote_url' => 'https://a/201.jpg', 'license' => 'PD', 'license_ok' => '1', 'source_unavailable' => 0],
    ['coin_id' => 20, 'image_id' => 202, 'remote_url' => 'https://a/202.jpg', 'license' => 'PD', 'license_ok' => '1', 'source_unavailable' => 0],
    // sikke 30: erişilemeyen → NC (metinli, görünür) seçilir
    ['coin_id' => 30, 'image_id' => 301, 'remote_url' => '', 'license' => 'PD', 'license_ok' => '1', 'source_unavailable' => '1'],
    ['coin_id' => 30, 'image_id' => 302, 'remote_url' => '', 'license' => 'CC BY-NC', 'license_ok' => '0', 'source_unavailable' => 0],
    // sikke 40: hiçbiri görünür değil → sonuçta yok
    ['coin_id' => 40, 'image_id' => 401, 'remote_url' => 'https://a/401.jpg', 'license' => '', 'license_ok' => '0', 'source_unavailable' => 0],
    // geçersiz satırlar
    ['coin_id' => 0, 'image_id' => 1, 'license_ok' => '1'],
];
$custom = [10 => 'https://ozel/10.jpg', 20 => 'https://ozel/20.jpg'];

$p = $L::firstVisiblePerCoin($rows, $custom, true);

check('gizli ilk satır atlanır, ikinci seçilir', ($p[10]['image_id'] ?? null) === 102);
check('özel adres yalnız özgün ilk satıra bağlanır (görsel ucuyla aynı)', ($p[10]['remote_url'] ?? null) === 'https://a/102.jpg');
check('ilk satır görünürse özel adres remote_url olur', ($p[20]['image_id'] ?? null) === 201 && $p[20]['remote_url'] === 'https://ozel/20.jpg');
check('erişilemeyen atlanır; NC görünür; boş remote_url → null', ($p[30]['image_id'] ?? null) === 302 && $p[30]['remote_url'] === null);
check('görünür görseli olmayan sikke yok', !isset($p[40]));
check('coin_id 0 yok sayılır', !isset($p[0]));
check('kapı kapalıyken (hide_unverified=false) ilk satır seçilir', $L::firstVisiblePerCoin($rows, $custom, false)[10]['image_id'] === 101);
check('kapı kapalıyken özel adres ilk satıra bağlanır', $L::firstVisiblePerCoin($rows, $custom, false)[10]['remote_url'] === 'https://ozel/10.jpg');
check('boş girdi → boş', $L::firstVisiblePerCoin([], [], true) === []);

// Sıra: sikkeler karışık gelse de her sikkenin kendi ilk satırı (özgün $idx sikke içinde sayılır)
$mixed = [
    ['coin_id' => 5, 'image_id' => 51, 'license' => null, 'license_ok' => null, 'source_unavailable' => 0],
    ['coin_id' => 6, 'image_id' => 61, 'license' => 'PD', 'license_ok' => '1', 'source_unavailable' => 0],
    ['coin_id' => 5, 'image_id' => 52, 'license' => 'PD', 'license_ok' => '1', 'source_unavailable' => 0],
];
$pm = $L::firstVisiblePerCoin($mixed, [5 => 'https://ozel/5.jpg', 6 => 'https://ozel/6.jpg'], true);
check('karışık sıra: sikke 5 → 52, özel adres yok', $pm[5]['image_id'] === 52 && $pm[5]['remote_url'] === null);
check('karışık sıra: sikke 6 kendi ilk satırı → özel adres', $pm[6]['image_id'] === 61 && $pm[6]['remote_url'] === 'https://ozel/6.jpg');

// ------------------------------------------------------------- kaynak denetimi ---
$main = (string) file_get_contents($root . '/numistr.php');
$s    = strpos($main, 'private function handleVariantsList(');
$e    = strpos($main, 'private function ', $s + 10);
$hl   = $s === false ? '' : substr($main, $s, $e - $s);
check('liste ucu satırlara küçük görsel ekler', strpos($hl, '$rows = $this->attachListThumbs($db, $rows);') !== false);

$s  = strpos($main, 'private function attachListThumbs(');
$e  = strpos($main, 'private function ', $s + 10);
$at = $s === false ? '' : substr($main, $s, $e - $s);
check('attachListThumbs: seçim saf yardımcıyla, lisans anahtarına bağlı',
    strpos($at, 'NumisTRImageLicense::firstVisiblePerCoin(') !== false
    && strpos($at, 'NumisTRImageLicense::enabled($this->config)') !== false);
check('attachListThumbs: source_unavailable + lisans kolonları seçiliyor',
    strpos($at, "ci.source_unavailable'") !== false && strpos($at, "ci.license_ok'") !== false);
check('attachListThumbs: sıra coin_id + ordering', strpos($at, "ci.ordering') . ' ASC'") !== false);
check('attachListThumbs: thumb_url = url_raw ile aynı (wm 0, mutlak)', strpos($at, "buildImageUrl((int)\$p['image_id'], 0, 1)") !== false);
check('attachListThumbs: sorgu hatası listeyi düşürmez', strpos($at, 'catch (\Throwable $e)') !== false);
check('attachListThumbs: sikke-gorsel-url süzgeci görsel ucuyla aynı', strpos($at, "stripos(\$u, 'option=com_numistr') === false") !== false);

// 1.25.0 (M36): katalog uçları 429'da error_code + retry_after verir (uygulama kısa bekleyip yeniden dener)
foreach (['handleVariantsList', 'handleVariantImages', 'handleVariantItem'] as $h) {
    $s  = strpos($main, 'private function ' . $h . '(');
    $e  = strpos($main, 'private function ', $s + 10);
    $hb = $s === false ? '' : substr($main, $s, $e - $s);
    check($h . ': 429 gövdesinde retry_after (RATE_LIMITED)', (bool) preg_match("~checkRateLimit\\('catalog(_images)?', [^;]+, 'RATE_LIMITED'\\);~", $hb));
}
