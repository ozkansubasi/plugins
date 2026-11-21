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
    check('kapanmamış <script> olduğu gibi kalır', PlgSystemNumistrconsent::sablonGtmAyikla('<head><script>var a=1;') === '<head><script>var a=1;');
    check('büyük harfli <SCRIPT> içindeki GTM de gider', PlgSystemNumistrconsent::sablonGtmAyikla('<SCRIPT>x="googletagmanager.com/gtm.js"</SCRIPT><p>b</p>') === '<p>b</p>');

    echo "1.1.1 büyük satır içi betik (20–21 Kas: sikke sayfaları boş gidiyordu)\n";
    $buyuk = '<script>var veri = "' . str_repeat('a', 1000000) . '";</script>';
    $bs = str_replace('<main>İçerik</main>', '<main>İçerik</main>' . $buyuk, $sayfa);
    $bo = PlgSystemNumistrconsent::isle($bs, $p);
    check('1 MB betikli sayfa boş dönmez', is_string($bo) && strlen($bo) > strlen($bs) - strlen($gtmBlok));
    check('1 MB betik aynen kalır', strpos($bo, $buyuk) !== false);
    check('1 MB betikli sayfada şablon GTM yine gider', strpos($bo, '<!-- Google Tag Manager -->') === false && strpos($bo, "'GTM-TRH8ZR6M');</script>") === false);
    check('1 MB betikli sayfaya onay katmanı eklenir', strpos($bo, PlgSystemNumistrconsent::ISARET) !== false);
    $gb = '<head></head><body>' . $buyuk . '<script>j.src="https://www.googletagmanager.com/gtm.js?id=GTM-X1";</script>' . $buyuk . '</body>';
    check('iki 1 MB betik arasındaki GTM betiği gider, ötekiler kalır', PlgSystemNumistrconsent::sablonGtmAyikla($gb) === '<head></head><body>' . $buyuk . $buyuk . '</body>');

    echo "rıza kaydı (ADR-013)\n";
    $K = ['https://www.numistr.org', 'https://numistr.org'];
    $sv = $metin['surum'];
    $iyi = ['i' => str_repeat('a1', 16), 't' => 1795173501757, 'v' => $sv, 'e' => 'tumunu_kabul', 'k' => 1, 'a' => 1, 'p' => 1, 'd' => 'tr', 's' => '/tr/?utm=x#y'];
    $dg = function (array $g, $o = 'https://www.numistr.org', $r = '') use ($sv, $K) {
        return PlgSystemNumistrconsent::kayitDogrula(json_encode($g), $o, $r, $sv, $K);
    };
    $k = $dg($iyi);
    check('geçerli gövde → kayıt alanları', is_array($k) && $k['onay_kimligi'] === $iyi['i'] && $k['istemci_zaman'] === 1795173501757
        && $k['metin_surumu'] === $sv && $k['eylem'] === 'tumunu_kabul' && $k['katman'] === 1 && $k['analitik'] === 1 && $k['pazarlama'] === 1
        && $k['kisisellestirme'] === 0 && $k['dil'] === 'tr');
    check('sayfa: sorgu ve parça atılır', is_array($k) && $k['sayfa'] === '/tr/');
    check('kayıtta IP / UA alanı yok', is_array($k) && !array_intersect(array_keys($k), ['ip', 'ua', 'user_agent', 'kullanici']));
    check('yabancı Origin → 403', $dg($iyi, 'https://kotu.example') === 403);
    check('Origin yok, Referer izinli → kabul', is_array($dg($iyi, '', 'https://numistr.org/en/sayfa?x=1')));
    check('Origin yok, Referer yabancı → 403', $dg($iyi, '', 'https://kotu.example/tr/') === 403);
    check('Origin ve Referer yok → 403', $dg($iyi, '', '') === 403);
    check('Origin "null" → Referer\'a bakılır', $dg($iyi, 'null', '') === 403);
    check('büyük gövde → 400', PlgSystemNumistrconsent::kayitDogrula(str_repeat('x', 1025), 'https://www.numistr.org', '', $sv, $K) === 400);
    check('boş / JSON değil → 400', PlgSystemNumistrconsent::kayitDogrula('', 'https://www.numistr.org', '', $sv, $K) === 400
        && PlgSystemNumistrconsent::kayitDogrula('{x', 'https://www.numistr.org', '', $sv, $K) === 400);
    check('eski metin sürümü → 400', $dg(['v' => '2026-11-v1'] + $iyi) === 400 || $sv === '2026-11-v1');
    check('kimlik 32 hex değil → 400', $dg(['i' => 'ABC'] + $iyi) === 400 && $dg(['i' => str_repeat('G', 32)] + $iyi) === 400);
    check('zaman sayı değil / aralık dışı → 400', $dg(['t' => '1795173501757'] + $iyi) === 400 && $dg(['t' => 5] + $iyi) === 400);
    check('bilinmeyen eylem → 400', $dg(['e' => 'kabul'] + $iyi) === 400);
    check('katman / bayrak / dil dışı → 400', $dg(['k' => 3] + $iyi) === 400 && $dg(['a' => true] + $iyi) === 400 && $dg(['d' => 'de'] + $iyi) === 400);
    check('tümünü kabul ama a=0 → 400', $dg(['a' => 0] + $iyi) === 400);
    check('tümünü ret ama p=1 → 400', $dg(['e' => 'tumunu_ret', 'a' => 0, 'p' => 1] + $iyi) === 400);
    $gc = $dg(['e' => 'geri_cekme', 'k' => 2, 'a' => 1, 'p' => 0, 's' => null] + $iyi);
    check('geri çekme, yol yok → kayıt, sayfa null', is_array($gc) && $gc['eylem'] === 'geri_cekme' && $gc['katman'] === 2 && $gc['sayfa'] === null);
    check('seçim kaydet a=0 p=1 → kayıt', is_array($dg(['e' => 'secim_kaydet', 'k' => 2, 'a' => 0, 'p' => 1] + $iyi)));
    check('sayfaYolu: göreli / denetim karakteri / uzun', PlgSystemNumistrconsent::sayfaYolu('tr/') === null
        && PlgSystemNumistrconsent::sayfaYolu("/a\nb") === null && mb_strlen(PlgSystemNumistrconsent::sayfaYolu('/' . str_repeat('ş', 300))) === 191);
    $sahteDb = new class {
        public function quoteName($n) { return '`' . $n . '`'; }
        public function quote($v) { return "'" . str_replace("'", "''", $v) . "'"; }
    };
    $sq = PlgSystemNumistrconsent::eklemeSorgusu($sahteDb, $gc);
    check('ekleme: INSERT IGNORE + sunucu zamanı UTC', strpos($sq, 'INSERT IGNORE INTO `numistr_cerez_rizalari`') === 0 && substr($sq, -19) === ', UTC_TIMESTAMP(3))');
    check('ekleme: NULL ve sayılar tırnaksız, metin tırnaklı', strpos($sq, ", NULL, UTC_TIMESTAMP(3))") !== false && strpos($sq, "'geri_cekme', 2, 1, 0, 0, 'tr'") !== false);
    $ok = PlgSystemNumistrconsent::isle($sayfa, $p + ['kayit' => '/index.php?option=com_ajax&plugin=numistrconsent&group=system&format=raw']);
    preg_match('~id="ncz-veri">(.*?)</script>~s', $ok, $vm);
    $vj = json_decode($vm[1] ?? '', true);
    check('arayüz verisinde kayıt adresi (k) ve kimlik etiketi', ($vj['k'] ?? '') === '/index.php?option=com_ajax&plugin=numistrconsent&group=system&format=raw'
        && ($vj['e']['kimlik'] ?? '') === 'Onay kimliğiniz');
    preg_match('~id="ncz-veri">(.*?)</script>~s', PlgSystemNumistrconsent::isle($sayfa, $p), $vm2);
    check('kayıt kapalı → k boş', (json_decode($vm2[1] ?? '', true)['k'] ?? 'x') === '');
    check('tercih çerezi açıklamasında onay kimliği', (bool) array_filter($metin['cerezler'], function ($c) {
        return $c['ad'] === 'numistr_cerez_tercihi' && strpos($c['amac_tr'], 'onay kimliğinizi') !== false && strpos($c['amac_en'], 'consent ID') !== false;
    }));

    echo "dil\n";
    check('en-GB → en', PlgSystemNumistrconsent::dil('en-GB') === 'en');
    check('tr-TR → tr', PlgSystemNumistrconsent::dil('tr-TR') === 'tr');
    check('bilinmeyen → tr', PlgSystemNumistrconsent::dil('de-DE') === 'tr');

    echo "\n$gecti geçti, $kaldi kaldı\n";
    exit($kaldi > 0 ? 1 : 0);
}
