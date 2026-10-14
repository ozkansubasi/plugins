<?php
/**
 * S28 (1.20.1) — malzeme filtresi hiçbir girdide `IN ()` kurmaz; 500 yanıtları istisna metnini sızdırmaz.
 *
 * Canlıda (2026-10-14) `filter[material]=billon|cu` → 500 + MySQL hata metni: tanınmayan değer
 * için dbMaterialVariantsFor boş liste döndürüyordu. 'cu' da 'copper'a gidiyordu ama
 * MATERIAL_VARIANTS'ta 'copper' yoktu. Facet ucunun döndürdüğü ham değerler (ölçüm: bronze,
 * silver, gold, electrum, billon, nan) ve uygulamanın sabit listesi filtrelenebilir olmalı.
 */

require_once $root . '/helpers/DatabaseHelper.php';
require_once $root . '/helpers/ResponseHelper.php';

$cfg = include $root . '/config/constants.php';
$db  = new NumisTRDatabaseHelper($cfg);

$nonEmpty = function (string $input) use ($db): bool {
    $k = $db->normalizeMaterialKey($input);

    return $k !== null && count($db->dbMaterialVariantsFor($k)) > 0;
};

// Hatanın kaynağı olan değişmez: her kısa kodun hedefinin varyant listesi var.
foreach ($cfg['MATERIAL_MAP'] as $code => $target) {
    check("S28: MATERIAL_MAP['{$code}'] → '{$target}' MATERIAL_VARIANTS'ta tanımlı",
        isset($cfg['MATERIAL_VARIANTS'][$target]));
}

check("S28: 'cu' bronze'a gider", $db->normalizeMaterialKey('cu') === 'bronze');
check("S28: 'AU' gold'a gider", $db->normalizeMaterialKey('AU') === 'gold');
check("S28: 'billon' kendi anahtarı", $db->normalizeMaterialKey('Billon') === 'billon');

// Canlı facet değerleri + uygulamanın sabit listesi + /v1/materials kodları.
$inputs = ['bronze', 'silver', 'gold', 'electrum', 'billon', 'nan', 'lead'];
foreach ($cfg['MATERIALS_LIST'] as $m) {
    $inputs[] = $m['code'];
}
foreach (array_unique($inputs) as $in) {
    check("S28: '{$in}' için varyant listesi boş değil", $nonEmpty($in));
}

// Tanınmayan değer kendisiyle eşleşir (0 sonuç), boş liste yok.
check("S28: canlıdaki 500 girdisi 'billon|cu' boş liste üretmez", $nonEmpty('billon|cu'));
check("S28: tanınmayan değer kendisiyle eşleşir",
    $db->dbMaterialVariantsFor('xyz') === ['xyz']);
check("S28: boş girdi filtre kurmaz", $db->normalizeMaterialKey('  ') === null);

// 500 yanıtları: istisna metni yalnız DEBUG_MODE'da.
check('S28: DEBUG_MODE canlıda kapalı', empty($cfg['DEBUG_MODE']));
check('S28: ayrıntısız hata gövdesinde detail alanı yok',
    !array_key_exists('detail', NumisTRResponseHelper::errorPayload(500, 'Internal server error', null)['errors'][0]));

$sources = ['numistr.php'];
foreach (glob($root . '/controllers/*.php') ?: [] as $f) {
    $sources[] = 'controllers/' . basename($f);
}
foreach ($sources as $rel) {
    // sendError(...) satırında istisna metni varsa DEBUG_MODE / $debug koşuluna bağlı olmalı.
    $leaks = array_filter(file($root . '/' . $rel) ?: [], function (string $line): bool {
        return str_contains($line, 'sendError(') && str_contains($line, '$e->getMessage()')
            && !str_contains($line, 'DEBUG_MODE') && !str_contains($line, '::$debug');
    });
    check("S28: {$rel} istisna metnini koşulsuz hata yanıtına yazmıyor", $leaks === [],
        implode(' | ', array_map('trim', $leaks)));
}
