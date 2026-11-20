<?php
// Çerez onayı uçtan uca denemesi — yerel site kurulumu. Kullanım: tests/e2e/calistir.sh
namespace Joomla\CMS\Plugin { class CMSPlugin { public $params; protected $app; } class PluginHelper {} }
namespace Joomla\CMS\Uri { class Uri {} }
namespace {
define('_JEXEC', 1);
$P = dirname(__DIR__, 3) . '/';
$D = $argv[1] ?? sys_get_temp_dir() . '/ncz_e2e';
require $P . 'numistrconsent/numistrconsent.php';
require $P . 'numistrapp/numistrapp.php';
$metin = PlgSystemNumistrconsent::metinleriOku($P . 'numistrconsent/media/metinler.json');
foreach (['tr' => '/tr/kvkk-ve-gizlilik-politikasi', 'en' => '/en/privacy-policy-gdpr'] as $dil => $pol) {
    $h = file_get_contents("$D/ham_$dil.html");
    // Canlıda eklenti açık (20 Kas'tan beri): onun çıktısı ayıklanır, yoksa isle() işaretten dolayı sayfayı işlemeden geçer.
    $h = preg_replace('~<script>\(function\(w,d\)\{var A=.*?\}\)\(window,document\);</script>~s', '', $h, 1);
    $h = preg_replace('~<link rel="stylesheet" href="/media/plg_system_numistrconsent/cerez\.css[^"]*">~', '', $h);
    $h = preg_replace('~<script src="/media/plg_system_numistrconsent/cerez\.js[^"]*" defer></script>~', '', $h);
    $h = preg_replace('~<script type="application/json" id="ncz-veri">.*?</script>~s', '', $h);
    $h = preg_replace('~<li class="el-item"><div class="el-content uk-panel uk-h6"><a href="#cerez-ayarlari"[^>]*data-ncz-ac>.*?</a></div></li>~s', '', $h);
    $h = preg_replace('~<p class="ncz-altbilgi">.*?</p>~s', '', $h);
    echo "$dil: canlı eklenti çıktısı kaldı mı: ", (strpos($h, 'ncz-veri') !== false || strpos($h, 'NumistrCerez') !== false ? 'EVET' : 'hayır'), "\n";
    $o = PlgSystemNumistrconsent::isle($h, ['dil' => $dil, 'metin' => $metin, 'gtm' => 'GTM-TRH8ZR6M', 'sure_gun' => 182,
        'politika' => $pol, 'sablon_gtm' => true, 'medya' => '/media/plg_system_numistrconsent', 'kayit' => PlgSystemNumistrconsent::KAYIT_YOLU]);
    // Yerelde sitenin kendi varlıkları canlıdan gelsin (bizim medya hariç).
    $o = preg_replace('~(href|src|srcset)="/(?!media/plg_system_numistrconsent/)~', '$1="https://www.numistr.org/', $o);
    file_put_contents("$D/site/$dil/index.html", $o);
    echo "$dil: ", strlen($h), " → ", strlen($o), " B, GTM parçası kaldı mı: ", (strpos($o, "'script','dataLayer','GTM") !== false ? 'EVET' : 'hayır'), "\n";
}
// ADR-013: rıza kaydı ucu — eklentinin GERÇEK doğrulaması; kayıtlar $D/kayit.log, hata kipi $D/kayit_hata dosyası.
$yonlendirici = '<?php
namespace Joomla\CMS\Plugin { class CMSPlugin { public $params; protected $app; } }
namespace {
define("_JEXEC", 1);
require ' . var_export($P . 'numistrconsent/numistrconsent.php', true) . ';
$D = dirname(__DIR__);
if (($_GET["option"] ?? "") !== "com_ajax") { http_response_code(404); exit; }
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") { http_response_code(405); exit; }
if (is_file("$D/kayit_hata")) { http_response_code(500); exit; }
$k = PlgSystemNumistrconsent::kayitDogrula((string) file_get_contents("php://input", false, null, 0, 1025),
    (string) ($_SERVER["HTTP_ORIGIN"] ?? ""), (string) ($_SERVER["HTTP_REFERER"] ?? ""), ' . var_export($metin['surum'], true) . ',
    [rtrim((string) getenv("NCZ_SITE"), "/")]);
if (is_int($k)) { file_put_contents("$D/kayit_ret.log", $k . "\n", FILE_APPEND); http_response_code($k); exit; }
file_put_contents("$D/kayit.log", json_encode($k, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
http_response_code(204);
}
';
file_put_contents("$D/site/index.php", $yonlendirici);
$onay = PlgSystemNumistrapp::onayAyari($P . 'numistrconsent/media/metinler.json');
file_put_contents("$D/site/tr/app/index.html", PlgSystemNumistrapp::sayfa('tr', 'GTM-TRH8ZR6M', PlgSystemNumistrapp::magazaAdresleri('tr', ['utm_source' => 'deneme']), $onay));
echo "app: tamam\n";
}
