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
    $o = PlgSystemNumistrconsent::isle($h, ['dil' => $dil, 'metin' => $metin, 'gtm' => 'GTM-TRH8ZR6M', 'sure_gun' => 182,
        'politika' => $pol, 'sablon_gtm' => true, 'medya' => '/media/plg_system_numistrconsent']);
    // Yerelde sitenin kendi varlıkları canlıdan gelsin (bizim medya hariç).
    $o = preg_replace('~(href|src|srcset)="/(?!media/plg_system_numistrconsent/)~', '$1="https://www.numistr.org/', $o);
    file_put_contents("$D/site/$dil/index.html", $o);
    echo "$dil: ", strlen($h), " → ", strlen($o), " B, GTM parçası kaldı mı: ", (strpos($o, "'script','dataLayer','GTM") !== false ? 'EVET' : 'hayır'), "\n";
}
$onay = PlgSystemNumistrapp::onayAyari($P . 'numistrconsent/media/metinler.json');
file_put_contents("$D/site/tr/app/index.html", PlgSystemNumistrapp::sayfa('tr', 'GTM-TRH8ZR6M', PlgSystemNumistrapp::magazaAdresleri('tr', ['utm_source' => 'deneme']), $onay));
echo "app: tamam\n";
}
