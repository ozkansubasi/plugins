<?php
/**
 * plg_system_numistrconsent — Joomla'sız sınama: php plugins/system/numistrconsent/tests/numistrconsent_test.php
 */
namespace Joomla\CMS\Plugin {
    class CMSPlugin
    {
        public $params;
        protected $app;
    }
}

namespace {
    define('_JEXEC', 1);
    require __DIR__ . '/../numistrconsent.php';

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

    $gtmBlok = "<!-- Google Tag Manager --><script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
        . "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;"
        . "f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-TRH8ZR6M');</script><!-- End Google Tag Manager -->";

    $sayfa = '<!DOCTYPE html><html lang="tr-tr"><head><meta charset="utf-8"><title>T</title>'
        . '<script src="/templates/yootheme/js/theme.js"></script>' . $gtmBlok . '</head><body><main>İçerik</main>'
        . '<footer><ul class="uk-list">'
        . '<li class="el-item"> <div class="el-content uk-panel uk-h6"><a href="/tr/misyon-vizyon" class="el-link uk-link-muted">Misyon</a></div> </li>'
        . '<li class="el-item"> <div class="el-content uk-panel uk-h6"><a href="/tr/kvkk-ve-gizlilik-politikasi" class="el-link uk-link-muted uk-margin-remove-last-child"><p>Gizlilik Politikası</p></a></div> </li>'
        . '<li class="el-item"> <div class="el-content uk-panel uk-h6"><a href="/tr/kullanim-kosullari" class="el-link uk-link-muted">Koşullar</a></div> </li>'
        . '</ul></footer></body></html>';

    $metin = PlgSystemNumistrconsent::metinleriOku(__DIR__ . '/../media/metinler.json');
    $p = [
        'dil' => 'tr', 'metin' => $metin, 'gtm' => 'GTM-TRH8ZR6M', 'sure_gun' => 182,
        'politika' => '/tr/kvkk-ve-gizlilik-politikasi', 'sablon_gtm' => true, 'medya' => '/media/plg_system_numistrconsent',
    ];

    echo "metinler.json\n";
    check('okunur, sürüm dolu', is_array($metin) && $metin['surum'] !== '');
    foreach (['banner_baslik', 'banner_metin', 'btn_kabul', 'btn_ret', 'btn_ayarlar', 'ayar_baslik', 'ayar_giris', 'kat_zorunlu_ad',
        'kat_zorunlu_aciklama', 'kat_analitik_ad', 'kat_analitik_aciklama', 'kat_pazarlama_ad', 'kat_pazarlama_aciklama', 'btn_kaydet',
        'altbilgi_baglanti', 'politika_baglanti_metin'] as $k) {
        if (empty($metin['tr'][$k]) || empty($metin['en'][$k])) {
            check("şema anahtarı $k (tr+en)", false);
        }
    }
    check('şema anahtarları tam', true);
    check('tercih çerezi listede zorunlu', (bool) array_filter($metin['cerezler'], function ($c) {
        return $c['ad'] === PlgSystemNumistrconsent::CEREZ && $c['kategori'] === 'zorunlu';
    }));
    check('bozuk dosya → null', PlgSystemNumistrconsent::metinleriOku(__FILE__) === null);
    check('olmayan dosya → null', PlgSystemNumistrconsent::metinleriOku(__DIR__ . '/yok.json') === null);

    echo "isle (TR)\n";
    $o = PlgSystemNumistrconsent::isle($sayfa, $p);
    check('şablon GTM ayıklandı', strpos($o, "'GTM-TRH8ZR6M');</script>") === false && strpos($o, 'Google Tag Manager -->') === false);
    $basYer = strpos($o, '<head><script>(function(w,d){var A=');
    check('onay betiği <head> başında', $basYer !== false);
    check('onay betiği öteki betiklerden önce', $basYer < strpos($o, 'theme.js'));
    check('consent default dördü denied', strpos($o, 'g("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",wait_for_update:500})') !== false);
    check('ads_data_redaction açık, url_passthrough kapalı', strpos($o, 'g("set","ads_data_redaction",true);g("set","url_passthrough",false)') !== false);
    check('ad_personalization her zaman denied', strpos($o, 'ad_personalization:"denied"});if(a||p)') !== false);
    check('GTM yalnız a||p ile', strpos($o, 'if(a||p){N.gtmYukle();}') !== false);
    check('sayfada doğrudan gtm.js etiketi yok', !preg_match('~<script[^>]+src="[^"]*googletagmanager~', $o));
    preg_match('~var A=(\{[^}]*\});~', $o, $esl);
    $A = json_decode($esl[1] ?? 'null', true);
    check('ayar: çerez adı numistr_cerez_tercihi', ($A['c'] ?? '') === 'numistr_cerez_tercihi');
    check('ayar: süre 182 gün', ($A['s'] ?? 0) === 182 * 86400);
    check('ayar: metin sürümü', ($A['v'] ?? '') === $metin['surum']);
    check('ayar: GTM kimliği', ($A['g'] ?? '') === 'GTM-TRH8ZR6M');
    check('biçem + ertelenmiş betik </head> önünde', (bool) preg_match('~cerez\.css\?v=[^"]+"><script src="/media/plg_system_numistrconsent/cerez\.js\?v=[^"]+" defer></script></head>~', $o));
    $li = strpos($o, 'href="#cerez-ayarlari"');
    check('alt bilgi bağlantısı eklendi', $li !== false && substr_count($o, 'href="#cerez-ayarlari"') === 1);
    check('bağlantı politika satırının ardında', $li > strpos($o, 'kvkk-ve-gizlilik-politikasi') && $li < strpos($o, '/tr/kullanim-kosullari'));
    check('bağlantı YOOtheme liste öğesi biçiminde', strpos($o, '<li class="el-item"><div class="el-content uk-panel uk-h6"><a href="#cerez-ayarlari"') !== false);
    check('bağlantı metni TR', strpos($o, 'data-ncz-ac>Çerez ayarları</a>') !== false);
    preg_match('~<script type="application/json" id="ncz-veri">(.*?)</script></body>~s', $o, $vj);
    $veri = json_decode($vj[1] ?? 'null', true);
    check('arayüz verisi </body> önünde, geçerli JSON', is_array($veri));
    check('veri: dil tr, TR metin', ($veri['dil'] ?? '') === 'tr' && ($veri['m']['btn_ret'] ?? '') === $metin['tr']['btn_ret']);
    check('veri: çerez listesi TR amaç', ($veri['c'][0]['amac'] ?? '') === $metin['cerezler'][0]['amac_tr']);
    check('veri: politika adresi', ($veri['politika'] ?? '') === '/tr/kvkk-ve-gizlilik-politikasi');
    check('iki kez işlenmez', PlgSystemNumistrconsent::isle($o, $p) === $o);

    echo "isle (EN, yedek metin, kenar durumlar)\n";
    $en = str_replace(['/tr/kvkk-ve-gizlilik-politikasi', 'lang="tr-tr"'], ['/en/privacy-policy-gdpr', 'lang="en-gb"'], $sayfa);
    $oe = PlgSystemNumistrconsent::isle($en, array_merge($p, ['dil' => 'en', 'politika' => '/en/privacy-policy-gdpr']));
    preg_match('~id="ncz-veri">(.*?)</script>~s', $oe, $vj);
    $ve = json_decode($vj[1], true);
    check('EN: metin İngilizce', $ve['m']['btn_kabul'] === $metin['en']['btn_kabul'] && $ve['e']['saglayici'] === 'Provider');
    check('EN: alt bilgi bağlantısı İngilizce', strpos($oe, 'data-ncz-ac>Cookie settings</a>') !== false);
    $oy = PlgSystemNumistrconsent::isle($sayfa, array_merge($p, ['metin' => null]));
    check('metinler yoksa yedek metinle yine çalışır', strpos($oy, 'id="ncz-veri"') !== false && strpos($oy, '"v":"yedek"') !== false);
    check('metinler yoksa da şablon GTM ayıklanır', strpos($oy, "'GTM-TRH8ZR6M');</script>") === false);
    $ok = PlgSystemNumistrconsent::isle($sayfa, array_merge($p, ['sablon_gtm' => false]));
    check('ayıklama kapalıysa şablon GTM kalır', strpos($ok, '<!-- Google Tag Manager -->') !== false);
    $og = PlgSystemNumistrconsent::isle($sayfa, array_merge($p, ['gtm' => 'x"><script>']));
    check('geçersiz GTM kimliği boşa düşer', strpos($og, '"g":""') !== false && strpos($og, 'x"><script>') === false);
    $ayaksiz = '<html><head></head><body><p>x</p></body></html>';
    $oa = PlgSystemNumistrconsent::isle($ayaksiz, $p);
    check('footer yoksa bağlantı </body> önüne', strpos($oa, '<p class="ncz-altbilgi"><a href="#cerez-ayarlari" data-ncz-ac>') !== false);
    $satirsiz = str_replace('kvkk-ve-gizlilik-politikasi', 'baska', $sayfa);
    $os = PlgSystemNumistrconsent::isle($satirsiz, $p);
    check('politika satırı yoksa </footer> önüne', strpos($os, 'ncz-altbilgi"><a href="#cerez-ayarlari" data-ncz-ac>Çerez ayarları</a></p></footer>') !== false);
    check('</head> yoksa dokunulmaz', PlgSystemNumistrconsent::isle('<div>parça</div>', $p) === '<div>parça</div>');
    check('süre üst sınırı 395 gün', strpos(PlgSystemNumistrconsent::isle($sayfa, array_merge($p, ['sure_gun' => 9999])), '"s":' . (395 * 86400)) !== false);

    echo "sablonGtmAyikla\n";
    $yorumsuz = "<head><script>(function(w,d,s,l,i){j.src='https://www.googletagmanager.com/gtm.js?id='+i;})(window,document,'script','dataLayer','GTM-X1');</script><script>var x=1;</script></head>";
    check('yorumsuz satır içi GTM gider, öteki betik kalır', PlgSystemNumistrconsent::sablonGtmAyikla($yorumsuz) === '<head><script>var x=1;</script></head>');
    $ns = '<body><noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X1" height="0" width="0"></iframe></noscript><p>a</p></body>';
    check('noscript iframe gider', PlgSystemNumistrconsent::sablonGtmAyikla($ns) === '<body><p>a</p></body>');

    echo "dil\n";
    check('en-GB → en', PlgSystemNumistrconsent::dil('en-GB') === 'en');
    check('tr-TR → tr', PlgSystemNumistrconsent::dil('tr-TR') === 'tr');
    check('bilinmeyen → tr', PlgSystemNumistrconsent::dil('de-DE') === 'tr');

    echo "\n$gecti geçti, $kaldi kaldı\n";
    exit($kaldi > 0 ? 1 : 0);
}
