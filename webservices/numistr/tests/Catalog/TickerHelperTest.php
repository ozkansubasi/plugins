<?php
/**
 * Ticker (1.16.3): alan biçimleme + toplu alan sorgusu gruplaması + fields_values tip regresyonu.
 *
 * 2026-09-27 ölçümü: soğuk önbellekte /v1/ticker 3,7–5,3 sn (sıcak 0,14 sn). Neden: fv.item_id (varchar)
 * int ile karşılaştırılıyordu → item_id indeksi kullanılamıyor; üstüne madde başına ayrı sorgu (20×).
 */

require_once $root . '/helpers/TickerHelper.php';

$item = (object) [
    'id' => 42, 'title' => 'Başlık', 'introtext' => '<p>Giriş metni</p>', 'catid' => 46,
    'category_title' => 'Ticker Info', 'category_alias' => 'ticker-info',
];

// ------------------------------------------------------------ biçimleme ---
$f = NumisTRTickerHelper::formatItem($item, ['fact-title' => 'Sardes', 'fact-description' => 'Lidya başkenti', 'region-code' => 'lydia-coins']);
check('tireli alan adları okunur', $f['fact_title'] === 'Sardes' && $f['fact_description'] === 'Lidya başkenti');
check('bölge kodu tireli alandan', $f['region_code'] === 'lydia-coins');
check('eski biçim alanları eş', $f['ancient_name'] === 'Sardes' && $f['modern_name'] === 'Lidya başkenti');
check('full_text "başlık: açıklama"', $f['full_text'] === 'Sardes: Lidya başkenti');
check('id int, kategori korunur', $f['id'] === 42 && $f['category'] === ['id' => 46, 'title' => 'Ticker Info', 'alias' => 'ticker-info']);

$f = NumisTRTickerHelper::formatItem($item, ['fact_title' => 'Efes', 'fact_description' => 'İonya']);
check('alt çizgili alan adları okunur', $f['fact_title'] === 'Efes' && $f['fact_description'] === 'İonya');

$f = NumisTRTickerHelper::formatItem($item, []);
check('alan yoksa makale başlığı + etiketsiz giriş', $f['fact_title'] === 'Başlık' && $f['fact_description'] === 'Giriş metni');
check('alan yoksa bölge null', $f['region_code'] === null);
check('debug kapalıyken _debug yok', !isset($f['_debug']));
check('debug açıkken ham alanlar', isset(NumisTRTickerHelper::formatItem($item, ['x' => 'y'], true)['_debug']['custom_fields_raw']['x']));

// ------------------------------------------------------------- gruplama ---
$g = NumisTRTickerHelper::groupCustomFields([
    (object) ['item_id' => '7', 'name' => 'fact-title', 'value' => 'A'],
    (object) ['item_id' => '9', 'name' => 'fact-title', 'value' => 'B'],
    (object) ['item_id' => '7', 'name' => 'region-code', 'value' => 'caria-coins'],
    (object) ['item_id' => '7', 'name' => 'fact-title', 'value' => 'A2'],
]);
check('makaleye göre gruplanır (varchar id → int anahtar)', array_keys($g) === [7, 9], json_encode(array_keys($g)));
check('aynı ad tekrarında sonuncusu kazanır', $g[7]['fact-title'] === 'A2');
check('diğer alanlar korunur', $g[7]['region-code'] === 'caria-coins' && $g[9] === ['fact-title' => 'B']);
check('boş satır listesi', NumisTRTickerHelper::groupCustomFields([]) === []);

// ------------------------------------------------- tip regresyonu (kaynak) ---
$src = (string) file_get_contents($root . '/helpers/TickerHelper.php');
check('fv.item_id int sütunla CAST\'siz eşlenmiyor', !preg_match('/fv\.item_id\s*=\s*a\.id\b/', $src));
check('fv.item_id int literal ile karşılaştırılmıyor', !preg_match('/fv\.item_id\s*=\s*\'\s*\.\s*\(int\)/', $src));
check('JOIN\'ler CAST + COLLATE deseninde', substr_count($src, 'fv.item_id = CAST(a.id AS CHAR) COLLATE utf8mb4_unicode_ci') === 2);
check('madde başına alan sorgusu kalmadı', strpos($src, 'getCustomFields(') === false);
