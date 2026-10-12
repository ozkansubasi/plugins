<?php
/**
 * A21 — kullanıcıya (ya da kuruluma) özel yanıtlar paylaşımlı önbelleğe girmez.
 *
 * ResponseHelper::sendJson varsayılanı `public, max-age=30`: katalog uçları için doğru,
 * ama profil / abonelik / kota yanıtında araya bir vekil girerse bir kullanıcının yanıtı
 * başkasına gidebilir. Bugün araya giren önbellek yok; kural kod ilerledikçe bozulmasın diye
 * kaynak düzeyinde denetlenir.
 */

$src = (string) file_get_contents($root . '/numistr.php');

/** Bir metodun gövdesi (bir sonraki "private function"a kadar). */
$body = function (string $name) use ($src): string {
    $start = strpos($src, 'private function ' . $name . '(');

    if ($start === false) {
        return '';
    }

    $next = strpos($src, 'private function ', $start + 10);

    return substr($src, $start, $next === false ? null : $next - $start);
};

foreach (['handleUserProfile', 'handleUserSubscription', 'handleScanQuota', 'handleAnonScanQuota', 'handleUserDelete'] as $name) {
    $b = $body($name);
    check('A21: ' . $name . ' yanıtı no-store',
        $b !== '' && preg_match('/sendJson\(\s*(\$payload|\[)/', $b) === 1 && preg_match('/sendJson\([^;]*,\s*true\)\s*;/s', $b) === 1);
}

$rec = (string) file_get_contents($root . '/controllers/RecognitionController.php');
check('A21: tanıma yanıtlarının hepsi no-store (JSON çıkış sayısı = no-store sayısı)',
    substr_count($rec, "header('Content-Type: application/json');") === substr_count($rec, "header('Cache-Control: no-store');")
    && substr_count($rec, "header('Cache-Control: no-store');") >= 3);
