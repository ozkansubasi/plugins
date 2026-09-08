<?php
/**
 * Tanima ozeti bicimlendirmesi (AssistantController).
 *
 * Neden var: 2026-09-08'e kadar recognitionSummary() yalnizca baslik + guven skoru
 * basiyordu; enrichMatches() on/arka yuz tasvirini, darphaneyi, otoriteyi cekip
 * atiyordu. Yeni hali metni DOGRUDAN alanlardan kuruyor, LLM cagirmiyor.
 *
 * Bu testin asil isi GROUNDING davranisini kilitlemek: bos alan icin cumle
 * kurulmamali. Bir gun biri "bilinmiyor" yazan bir fallback eklerse burasi kirilir.
 */

require_once $root . '/helpers/AuthHelper.php';
require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';
require_once $root . '/controllers/AssistantController.php';

$call = static function (string $method, array $args) {
    $m = new ReflectionMethod('AssistantController', $method);
    $m->setAccessible(true);

    return $m->invokeArgs(null, $args);
};

// ---------------------------------------------------------------------------
// dateRangeLabel - negatif yil = MO
// ---------------------------------------------------------------------------
check('tarih: tek yil MO', $call('dateRangeLabel', [-400, -400, 'tr']) === 'MÖ 400');
check('tarih: MO araligi tek kez donem yazar', $call('dateRangeLabel', [-400, -370, 'tr']) === 'MÖ 400–370');
check('tarih: MS araligi', $call('dateRangeLabel', [100, 150, 'tr']) === 'MS 100–150');
check('tarih: cag gecisi iki donem yazar', $call('dateRangeLabel', [-30, 14, 'tr']) === 'MÖ 30 – MS 14');
check('tarih EN: BC sonda', $call('dateRangeLabel', [-400, -370, 'en']) === '400–370 BC');
check('tarih EN: cag gecisi', $call('dateRangeLabel', [-30, 14, 'en']) === '30 BC – AD 14');
check('tarih: ikisi de bos -> bos string', $call('dateRangeLabel', [null, null, 'tr']) === '');
check('tarih: yalniz from dolu', $call('dateRangeLabel', [-250, null, 'tr']) === 'MÖ 250');

// ---------------------------------------------------------------------------
// regionLabel / metalLabel - taninmayan deger UYDURULMAZ, oldugu gibi gecer
// ---------------------------------------------------------------------------
check('bolge: caria-coins -> Karya', $call('regionLabel', ['caria-coins', 'tr']) === 'Karya');
check('bolge: EN dilinde Ingilizce ad', $call('regionLabel', ['caria-coins', 'en']) === 'Caria');
check('bolge: canli alias hatasi clicia da Kilikya', $call('regionLabel', ['clicia-coins', 'tr']) === 'Kilikya');
check('bolge: bilinmeyen kod okunabilir hale gelir', $call('regionLabel', ['sophene-coins', 'tr']) === 'Sophene');
check('bolge: bos -> bos', $call('regionLabel', [null, 'tr']) === '');
check('metal: silver -> gumus', $call('metalLabel', ['silver', 'tr']) === 'gümüş');
check('metal: EN dokunmaz', $call('metalLabel', ['silver', 'en']) === 'silver');
check('metal: bilinmeyen anahtar aynen gecer', $call('metalLabel', ['orichalcum', 'tr']) === 'orichalcum');
check('metal: bos -> bos', $call('metalLabel', ['', 'tr']) === '');

// ---------------------------------------------------------------------------
// recognitionSummary - dolu kayit
// ---------------------------------------------------------------------------
$full = [[
    'article_id'   => 6643,
    'title'        => 'Cnidus Nordbo 1972 Series 26',
    'region'       => 'caria-coins',
    'metal'        => 'bronze',
    'date_from'    => -100,
    'date_to'      => -28,
    'mint'         => 'cnidus',
    'authority'    => 'Cnidus',
    'denomination' => 'AE',
    'obverse'      => 'Head of Athena wearing a helmet',
    'reverse'      => 'Nike advancing holding a wreath',
    'url'          => 'https://numistr.org/tr/anatolian-coins/caria-coins/6643-cnidus',
    'confidence'   => 0.87,
]];

$r = $call('recognitionSummary', [$full, 'tr']);
$t = $r['text'];

check('ozet: baslik konusma basligina gecer', $r['title'] === 'Cnidus Nordbo 1972 Series 26');
check('ozet: guven yuzdesi yazilir', strpos($t, '%87 benzerlik') !== false);
check('ozet: bolge kunyede', strpos($t, 'Karya bölgesi') !== false);
check('ozet: darphane kunyede', strpos($t, 'Cnidus darphanesi') !== false);
check('ozet: tarih araligi kunyede', strpos($t, 'MÖ 100–28') !== false);
check('ozet: metal kunyede', strpos($t, 'bronz') !== false);
check('ozet: ON YUZ tasviri metne girer', strpos($t, 'Ön yüz: Head of Athena wearing a helmet') !== false);
check('ozet: ARKA YUZ tasviri metne girer', strpos($t, 'Arka yüz: Nike advancing holding a wreath') !== false);
check('ozet: url verilir', strpos($t, 'https://numistr.org/tr/anatolian-coins/caria-coins/6643-cnidus') !== false);
check('ozet: yuksek guvende uyari cikmaz', strpos($t, 'Benzerlik düşük') === false);
check('ozet: toplam sayi sizmaz', preg_match('/\b(toplam|sonuc bulundu|adet)\b/iu', $t) === 0);

// ---------------------------------------------------------------------------
// GROUNDING: bos alan icin CUMLE KURULMAZ
// ---------------------------------------------------------------------------
$sparse = [[
    'article_id' => 999,
    'title'      => 'Bilinmeyen Varyant',
    'region'     => 'lydia-coins',
    'metal'      => '',
    'date_from'  => null,
    'date_to'    => null,
    'mint'       => '',
    'authority'  => '',
    'obverse'    => '',
    'reverse'    => null,
    'url'        => '',
    'confidence' => 0.91,
]];

$r2 = $call('recognitionSummary', [$sparse, 'tr']);
$t2 = $r2['text'];

check('grounding: bos on yuz icin satir YOK', strpos($t2, 'Ön yüz:') === false);
check('grounding: bos arka yuz icin satir YOK', strpos($t2, 'Arka yüz:') === false);
check('grounding: bos otorite icin satir YOK', strpos($t2, 'Otorite:') === false);
check('grounding: bos darphane yazilmaz', strpos($t2, 'darphanesi') === false);
check('grounding: bos alan icin bilinmiyor/muhtemelen YAZILMAZ',
    preg_match('/bilinmiyor|bilinmemekte|muhtemelen|tahminen|olabilir/iu', $t2) === 0);
check('grounding: dolu olan bolge yine de yazilir', strpos($t2, 'Lidya') !== false);

// ---------------------------------------------------------------------------
// Dusuk benzerlikte kesinlik iddia edilmez
// ---------------------------------------------------------------------------
$low = $full;
$low[0]['confidence'] = 0.42;
$r3 = $call('recognitionSummary', [$low, 'tr']);
check('dusuk guven: ipucu uyarisi eklenir', strpos($r3['text'], 'Benzerlik düşük') !== false);

// ---------------------------------------------------------------------------
// Coklu eslesme: ilki kart, digerleri tek satir
// ---------------------------------------------------------------------------
$multi = [
    $full[0],
    ['article_id' => 20, 'title' => 'Ikinci Varyant', 'region' => 'ionia-coins', 'date_from' => -350, 'date_to' => -300, 'confidence' => 0.71],
    ['article_id' => 30, 'title' => 'Ucuncu Varyant', 'region' => 'lycia-coins', 'confidence' => 0.64],
];

$r4 = $call('recognitionSummary', [$multi, 'tr']);
$t4 = $r4['text'];

check('coklu: diger eslesmeler basligi', strpos($t4, 'Diğer yakın eşleşmeler') !== false);
check('coklu: 2. sirada numaralanir', strpos($t4, '2. Ikinci Varyant') !== false);
check('coklu: 3. sirada numaralanir', strpos($t4, '3. Ucuncu Varyant') !== false);
check('coklu: yardimci satirda bolge/tarih', strpos($t4, 'İyonya') !== false && strpos($t4, 'MÖ 350–300') !== false);
check('coklu: tarihsiz kayitta tarih uydurulmaz', strpos($t4, '3. Ucuncu Varyant (%64 benzerlik) — Likya') !== false);

// ---------------------------------------------------------------------------
// Eslesme yok
// ---------------------------------------------------------------------------
$r5 = $call('recognitionSummary', [[], 'tr']);
check('eslesme yok: yardimci mesaj', strpos($r5['text'], 'eşleştiremedim') !== false);
check('eslesme yok: baslik fallback', $r5['title'] === 'Sikke tanıma');

$r6 = $call('recognitionSummary', [$full, 'en']);
check('EN: obverse etiketi', strpos($r6['text'], 'Obverse: Head of Athena') !== false);
check('EN: similarity ifadesi', strpos($r6['text'], '87% similarity') !== false);
check('EN: BC bicimi', strpos($r6['text'], '100–28 BC') !== false);
