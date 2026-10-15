<?php
/**
 * 1.21.1 (G12, 2026-10-16) — kaynağı erişilemeyen görsel (coins_images.source_unavailable = 1; yerelde ve kalıcı önbellekte
 * yok, kaynak vermiyor — ör. Gallica'nın Cloudflare engeli) hiçbir yanıtta yer almaz. Lisans kapısından bağımsız.
 */

require_once $root . '/helpers/ImageLicenseHelper.php';

$L = 'NumisTRImageLicense';

// ------------------------------------------------------------------- saf karar ---
check('varsayılan 0 → erişilebilir', $L::isAvailable(0) === true && $L::isAvailable('0') === true);
check('işaretli 1 → erişilemez', $L::isAvailable(1) === false && $L::isAvailable('1') === false);
check('alan yok (eski şema, null) → erişilebilir sayılır', $L::isAvailable(null) === true);

$fakeDb = new class {
    public function quoteName($n) { return '`' . str_replace('.', '`.`', $n) . '`'; }
};
check('SQL: isAvailable ile aynı kural', $L::sqlAvailable($fakeDb, 'ci') === '`ci`.`source_unavailable` = 0');

// ------------------------------------------------------------- kaynak denetimi ---
$main = (string) file_get_contents($root . '/numistr.php');
$s    = strpos($main, 'private function getVariantImages(');
$e    = strpos($main, 'private function ', $s + 10);
$gv   = $s === false ? '' : substr($main, $s, $e - $s);
check('getVariantImages: source_unavailable seçiliyor', strpos($gv, "ci.source_unavailable'") !== false);
check('getVariantImages: erişilemeyen satır PHP\'de atlanır (sikke-gorsel-url özgün $idx kuralı korunur)',
    strpos($gv, "NumisTRImageLicense::isAvailable(\$r['source_unavailable'] ?? 0)") !== false
    && strpos($gv, "->where(NumisTRImageLicense::sqlAvailable") === false);
check('filter[has_images]: erişilemeyen görsel sayılmaz', strpos($main, "NumisTRImageLicense::sqlAvailable(\$db, 'ci')") !== false
    && strpos($main, '$licenseSql . $availSql') !== false);

$hlp = (string) file_get_contents($root . '/helpers/ImageLicenseHelper.php');
check('tanıma küçük resmi: visibleIds erişilebilirliği de süzer', strpos($hlp, "self::sqlVisible(\$db, 'ci')\n                . ' AND ' . self::sqlAvailable(\$db, 'ci')") !== false);
