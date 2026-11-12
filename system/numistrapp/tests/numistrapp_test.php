<?php
/**
 * plg_system_numistrapp — Joomla'sız sınama: php plugins/system/numistrapp/tests/numistrapp_test.php
 */
namespace Joomla\CMS\Plugin {
    class CMSPlugin
    {
        public $params;
        protected $app;
    }
}

namespace Joomla\CMS\Uri {
    class Uri
    {
    }
}

namespace {
    define('_JEXEC', 1);
    require __DIR__ . '/../numistrapp.php';

    $gecti = 0;
    $kaldi = 0;

    function check($ad, $kosul)
    {
        global $gecti, $kaldi;
        if ($kosul) {
            $gecti++;
            echo "  ok   $ad\n";
        } else {
            $kaldi++;
            echo "  FAIL $ad\n";
        }
    }

    // Adres eşleşmesi
    check('/tr/app → tr', PlgSystemNumistrapp::eslesme('/tr/app') === 'tr');
    check('/en/app/ → en', PlgSystemNumistrapp::eslesme('/en/app/') === 'en');
    check('/tr/apps eşleşmez', PlgSystemNumistrapp::eslesme('/tr/apps') === null);
    check('/tr/app/x eşleşmez', PlgSystemNumistrapp::eslesme('/tr/app/x') === null);
    check('/de/app eşleşmez', PlgSystemNumistrapp::eslesme('/de/app') === null);
    check('/app eşleşmez', PlgSystemNumistrapp::eslesme('/app') === null);
    check('/tr/ eşleşmez', PlgSystemNumistrapp::eslesme('/tr/') === null);

    // Mağaza adresleri
    $tr = PlgSystemNumistrapp::magazaAdresleri('tr', []);
    check('TR App Store ?l=tr', $tr['apple'] === 'https://apps.apple.com/tr/app/anatolian-coins/id6810686285?l=tr');
    check('TR Play hl=tr, referrer yok', $tr['google'] === 'https://play.google.com/store/apps/details?id=com.anatoliancoins.app&hl=tr');
    $en = PlgSystemNumistrapp::magazaAdresleri('en', []);
    check('EN App Store us', $en['apple'] === 'https://apps.apple.com/us/app/anatolian-coins/id6810686285');
    check('EN Play hl=en', $en['google'] === 'https://play.google.com/store/apps/details?id=com.anatoliancoins.app&hl=en');

    // utm → Play referrer
    $q = ['utm_source' => 'youtube', 'utm_medium' => 'video', 'utm_campaign' => 'lidya-tanitim', 'x' => 'y'];
    $u = PlgSystemNumistrapp::magazaAdresleri('en', $q);
    check('Play referrer utm taşır', strpos($u['google'], '&referrer=utm_source%3Dyoutube%26utm_medium%3Dvideo%26utm_campaign%3Dlidya-tanitim') !== false);
    check('izinsiz parametre geçmez', strpos($u['google'], 'x%3Dy') === false);
    check('App Store adresi değişmez', $u['apple'] === $en['apple']);
    $kotu = PlgSystemNumistrapp::playReferrer(['utm_source' => '"><script>alert(1)</script>', 'utm_term' => ['dizi']]);
    check('referrer süzülür', $kotu === 'utm_source=scriptalert1script');
    check('referrer uzunluk sınırı', strlen(PlgSystemNumistrapp::playReferrer(['utm_content' => str_repeat('a', 500)])) === strlen('utm_content=') + 100);

    // Sayfa
    $html = PlgSystemNumistrapp::sayfa('tr', 'GTM-TRH8ZR6M', $u);
    check('noindex meta', strpos($html, '<meta name="robots" content="noindex, nofollow">') !== false);
    check('lang=tr', strpos($html, '<html lang="tr">') !== false);
    check('GTM kapsayıcısı', strpos($html, 'googletagmanager.com/gtm.js?id=') !== false && strpos($html, '"GTM-TRH8ZR6M"') !== false);
    check('olay adı app_store_redirect', strpos($html, 'event:"app_store_redirect"') !== false);
    check('GTM olaydan önce', strpos($html, 'gtm.js') < strpos($html, 'app_store_redirect'));
    check('masaüstü rozetleri (iki mağaza bağlantısı)', substr_count($html, '<a class="a"') === 1 && substr_count($html, '<a class="g"') === 1);
    check('href kaçışlı (&amp;)', strpos($html, 'hl=en&amp;referrer=') !== false);
    check('JS ve HTML içinde ham & yok', strpos($html, '&referrer=') === false && strpos($html, '\u0026referrer=') !== false);
    check('TR metin', strpos($html, 'yönlendiriliyorsunuz') !== false);
    check('istemci yönlendirmesi: location.replace', strpos($html, 'location.replace(U[store])') !== false);
    check('geri dönüş zamanlayıcısı', strpos($html, 'setTimeout(git,1000)') !== false);

    $yokGtm = PlgSystemNumistrapp::sayfa('en', 'GTM-x"><script>', $en);
    check('geçersiz GTM kimliği → GTM yok', strpos($yokGtm, 'googletagmanager') === false);
    check('EN metin', strpos($yokGtm, 'Taking you to the app store') !== false && strpos($yokGtm, '<html lang="en">') !== false);
    check('enjeksiyon yok', strpos($yokGtm, '"><script>') === false);

    echo "\n$gecti passed, $kaldi failed\n";
    exit($kaldi > 0 ? 1 : 0);
}
