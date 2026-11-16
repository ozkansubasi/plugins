<?php
/**
 * @package     NumisTR Cookie Consent
 * @subpackage  plg_system_numistrconsent
 * @version     1.0.0
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Prime NT-261117-013541j9 + EK NT-261117-014937av — numistr.org çerez onayı (kendi modülümüz, üçüncü taraf CMP yok).
 *
 * Consent Mode BASIC:
 *  - <head>'in en başında: dataLayer + gtag taslağı, `consent default` (dördü de denied, wait_for_update 500),
 *    `ads_data_redaction` açık, `url_passthrough` kapalı. Kayıtlı ve geçerli tercih varsa hemen `consent update`.
 *  - GTM yalnız analitik ya da pazarlama kategorilerinden en az biri onaylıysa yüklenir (sayfa içinde onay → o anda).
 *  - Şablonun eklediği GTM parçası (YOOtheme <head> kodu) çıktıdan ayıklanır: eklenti açıkken onaysız GTM
 *    yüklenemez; eklenti kapatılınca site eski davranışına döner (geri alma = eklentiyi kapatmak).
 *  - Pencere ve tercih merkezi tarayıcıda kurulur (cerez.js): HTML herkese aynıdır, Cloudflare önbelleğiyle uyumlu.
 *  - Tercih çerezi `numistr_cerez_tercihi` = {v: metin sürümü, t: zaman (ms), a: analitik 0/1, p: pazarlama 0/1},
 *    6 ay. Metin sürümü (metinler.json `surum`) değişince tercih geçersiz sayılır, pencere yeniden çıkar.
 *  - Geri çekme / ret: `_ga*`, `_gcl_*` çerezleri (.numistr.org ve www) ve localStorage `_gcl*` silinir;
 *    onay geri çekildiyse sayfa yenilenir (GTM bellekte kalmasın).
 *  - Alt bilgide Gizlilik Politikası satırının yanına "Çerez ayarları" bağlantısı (sunucu tarafında, CLS yok).
 *    Sitedeki her `<a href="#cerez-ayarlari">` tercih merkezini açar (politika sayfası metni için).
 *
 * Metinler: media/plg_system_numistrconsent/metinler.json (Prime'ın ortak şeması; adlar değişmez).
 * /app sayfası (plg_system_numistrapp ≥ 1.1.0) pencere göstermez, aynı çerezi okur.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;

class PlgSystemNumistrconsent extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    protected $autoloadLanguage = true;

    public const SURUM          = '1.0.0';
    public const GTM_VARSAYILAN = 'GTM-TRH8ZR6M';
    public const CEREZ          = 'numistr_cerez_tercihi';
    public const SURE_GUN       = 182;
    public const MEDYA          = '/media/plg_system_numistrconsent';
    public const ISARET         = 'id="ncz-veri"';

    /** metinler.json okunamazsa kullanılan en küçük metin (pencere yine çalışır; onaysız ölçüm yine kapalı). */
    private const YEDEK = [
        'tr' => [
            'banner_baslik' => 'Çerez tercihleri', 'banner_metin' => 'Sitenin çalışması için zorunlu çerezler kullanılır. Ölçüm ve pazarlama çerezleri yalnız onayınızla kullanılır.',
            'btn_kabul' => 'Tümünü kabul et', 'btn_ret' => 'Tümünü reddet', 'btn_ayarlar' => 'Ayarlar',
            'ayar_baslik' => 'Çerez ayarları', 'ayar_giris' => 'Hangi çerez kategorilerine izin verdiğinizi seçin.',
            'kat_zorunlu_ad' => 'Zorunlu', 'kat_zorunlu_aciklama' => 'Sitenin çalışması için gereklidir; kapatılamaz.',
            'kat_analitik_ad' => 'Analitik', 'kat_analitik_aciklama' => 'Sitenin nasıl kullanıldığını ölçmemize yardım eder.',
            'kat_pazarlama_ad' => 'Pazarlama', 'kat_pazarlama_aciklama' => 'Reklamlarımızın etkisini ölçmemize yardım eder.',
            'btn_kaydet' => 'Seçimi kaydet', 'altbilgi_baglanti' => 'Çerez ayarları', 'politika_baglanti_metin' => 'Gizlilik Politikası',
        ],
        'en' => [
            'banner_baslik' => 'Cookie preferences', 'banner_metin' => 'We use strictly necessary cookies to run the site. Analytics and marketing cookies are used only with your consent.',
            'btn_kabul' => 'Accept all', 'btn_ret' => 'Reject all', 'btn_ayarlar' => 'Settings',
            'ayar_baslik' => 'Cookie settings', 'ayar_giris' => 'Choose which cookie categories you allow.',
            'kat_zorunlu_ad' => 'Strictly necessary', 'kat_zorunlu_aciklama' => 'Required for the site to work; cannot be switched off.',
            'kat_analitik_ad' => 'Analytics', 'kat_analitik_aciklama' => 'Helps us measure how the site is used.',
            'kat_pazarlama_ad' => 'Marketing', 'kat_pazarlama_aciklama' => 'Helps us measure the effect of our ads.',
            'btn_kaydet' => 'Save choices', 'altbilgi_baglanti' => 'Cookie settings', 'politika_baglanti_metin' => 'Privacy Policy',
        ],
    ];

    /** Arayüzde şema dışında kalan küçük etiketler (metinler.json şemasına dokunmamak için burada). */
    private const EK = [
        'tr' => ['cerezler' => 'Çerezler', 'ad' => 'Ad', 'saglayici' => 'Sağlayıcı', 'amac' => 'Amaç', 'sure' => 'Süre', 'bolge' => 'Çerez tercihleri'],
        'en' => ['cerezler' => 'Cookies', 'ad' => 'Name', 'saglayici' => 'Provider', 'amac' => 'Purpose', 'sure' => 'Duration', 'bolge' => 'Cookie preferences'],
    ];

    public function onAfterRender()
    {
        if (!$this->app->isClient('site')) {
            return;
        }

        $doc = $this->app->getDocument();

        if (!$doc || $doc->getType() !== 'html') {
            return;
        }

        $input = $this->app->input;

        // YOOtheme önizlemesi ve açılır pencere şablonları dokunulmadan geçer.
        if ($input->get('customizer') !== null || $input->getCmd('tmpl') === 'component') {
            return;
        }

        $dil    = self::dil($this->app->getLanguage()->getTag());
        $metin  = self::metinleriOku(JPATH_ROOT . self::MEDYA . '/metinler.json');
        $body   = (string) $this->app->getBody();
        $yeni   = self::isle($body, [
            'dil'         => $dil,
            'metin'       => $metin,
            'gtm'         => (string) $this->params->get('gtm_id', self::GTM_VARSAYILAN),
            'sure_gun'    => (int) $this->params->get('sure_gun', self::SURE_GUN),
            'politika'    => (string) $this->params->get('politika_' . $dil, $dil === 'en' ? '/en/privacy-policy-gdpr' : '/tr/kvkk-ve-gizlilik-politikasi'),
            'sablon_gtm'  => (int) $this->params->get('sablon_gtm_ayikla', 1) === 1,
            'medya'       => self::MEDYA,
        ]);

        if ($yeni !== $body) {
            $this->app->setBody($yeni);
        }
    }

    public static function dil($etiket)
    {
        return stripos((string) $etiket, 'en') === 0 ? 'en' : 'tr';
    }

    /**
     * metinler.json'u okur ve doğrular. Okunamaz ya da şema dışıysa null (YEDEK kullanılır).
     *
     * @return array{surum:string, tr:array, en:array, cerezler:array}|null
     */
    public static function metinleriOku($yol)
    {
        if (!is_file($yol)) {
            return null;
        }

        $veri = json_decode((string) file_get_contents($yol), true);

        if (!is_array($veri) || empty($veri['surum']) || !is_string($veri['surum'])
            || !isset($veri['tr'], $veri['en']) || !is_array($veri['tr']) || !is_array($veri['en'])) {
            return null;
        }

        if (!isset($veri['cerezler']) || !is_array($veri['cerezler'])) {
            $veri['cerezler'] = [];
        }

        return $veri;
    }

    /**
     * Sayfa çıktısını işler (Joomla'sız sınanır).
     *
     * @param array{dil:string, metin:?array, gtm:string, sure_gun:int, politika:string, sablon_gtm:bool, medya:string} $p
     */
    public static function isle($html, array $p)
    {
        if ($html === '' || strpos($html, self::ISARET) !== false || !preg_match('~<head\b[^>]*>~i', $html)
            || stripos($html, '</head>') === false || stripos($html, '</body>') === false) {
            return $html;
        }

        $dil   = $p['dil'] === 'en' ? 'en' : 'tr';
        $metin = $p['metin'];
        $surum = $metin ? (string) $metin['surum'] : 'yedek';
        $m     = array_merge(self::YEDEK[$dil], $metin ? array_filter((array) $metin[$dil], 'is_string') : []);
        $gtm   = preg_match('~^GTM-[A-Z0-9]+$~', (string) $p['gtm']) ? (string) $p['gtm'] : '';
        $gun   = max(1, min(395, (int) $p['sure_gun']));
        $jsf   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $h     = static function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        // 1) Şablonun GTM parçası (yorumlu blok, yorumsuz satır içi betik, noscript iframe).
        if (!empty($p['sablon_gtm'])) {
            $html = self::sablonGtmAyikla($html);
        }

        // 2) <head>'in en başı: onay varsayılanı + kayıtlı tercih + (onay varsa) GTM.
        $ayar = ['c' => self::CEREZ, 'v' => $surum, 'g' => $gtm, 's' => $gun * 86400];
        $bas  = '<script>' . self::basBetigi(json_encode($ayar, $jsf)) . '</script>';
        $html = preg_replace('~<head\b[^>]*>~i', '$0' . self::kacis($bas), $html, 1);

        // 3) </head> önü: biçem + ertelenmiş arayüz betiği.
        $v    = rawurlencode(self::SURUM . '-' . $surum);
        $ek   = '<link rel="stylesheet" href="' . $h($p['medya'] . '/cerez.css?v=' . $v) . '">'
            . '<script src="' . $h($p['medya'] . '/cerez.js?v=' . $v) . '" defer></script>';
        $html = self::onune($html, '</head>', $ek);

        // 4) Alt bilgi bağlantısı.
        $html = self::altbilgiBaglantisi($html, (string) $p['politika'], (string) $m['altbilgi_baglanti']);

        // 5) Arayüz verisi (yalnız bu dil).
        $cerezler = [];

        foreach ($metin ? $metin['cerezler'] : [] as $c) {
            if (!is_array($c) || empty($c['ad']) || !in_array($c['kategori'] ?? '', ['zorunlu', 'analitik', 'pazarlama'], true)) {
                continue;
            }

            $cerezler[] = [
                'ad'        => (string) $c['ad'],
                'saglayici' => (string) ($c['saglayici'] ?? ''),
                'kategori'  => (string) $c['kategori'],
                'amac'      => (string) ($c['amac_' . $dil] ?? ''),
                'sure'      => (string) ($c['sure_' . $dil] ?? ''),
            ];
        }

        $veri = ['dil' => $dil, 'm' => $m, 'e' => self::EK[$dil], 'c' => $cerezler, 'politika' => (string) $p['politika']];
        $html = self::onune($html, '</body>', '<script type="application/json" ' . self::ISARET . '>' . json_encode($veri, $jsf) . '</script>');

        return $html;
    }

    /** Şablonun eklediği GTM parçalarını çıkarır. */
    public static function sablonGtmAyikla($html)
    {
        $html = preg_replace('~<!--\s*Google Tag Manager\s*-->.*?<!--\s*End Google Tag Manager\s*-->~is', '', $html);
        $html = preg_replace('~<!--\s*Google Tag Manager \(noscript\)\s*-->.*?<!--\s*End Google Tag Manager \(noscript\)\s*-->~is', '', $html);
        $html = preg_replace('~<script\b[^>]*>(?:(?!</script>).)*googletagmanager\.com/gtm\.js(?:(?!</script>).)*</script>~is', '', $html);
        $html = preg_replace('~<noscript>\s*<iframe[^>]*googletagmanager\.com/ns\.html.*?</noscript>~is', '', $html);

        return $html;
    }

    /** <head> başına giden betik. $ayarJson: {c: çerez adı, v: metin sürümü, g: GTM kimliği, s: süre (sn)}. */
    public static function basBetigi($ayarJson)
    {
        return '(function(w,d){var A=' . $ayarJson . ';'
            . 'w.dataLayer=w.dataLayer||[];'
            . 'if(typeof w.gtag!=="function"){w.gtag=function(){w.dataLayer.push(arguments);};}'
            . 'var g=w.gtag;'
            . 'g("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",wait_for_update:500});'
            . 'g("set","ads_data_redaction",true);g("set","url_passthrough",false);'
            . 'var t=null;try{var m=d.cookie.match(new RegExp("(?:^|;\\\\s*)"+A.c+"=([^;]*)"));'
            . 'if(m){t=JSON.parse(decodeURIComponent(m[1]));if(!t||t.v!==A.v){t=null;}}}catch(e){t=null;}'
            . 'var N=w.NumistrCerez={ayar:A,tercih:t,gtmYuklendi:false,'
            . 'gtmYukle:function(){if(N.gtmYuklendi||!A.g){return;}N.gtmYuklendi=true;'
            . 'w.dataLayer.push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
            . 'var j=d.createElement("script");j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+encodeURIComponent(A.g);'
            . '(d.head||d.documentElement).appendChild(j);},'
            . 'guncelle:function(a,p){g("consent","update",{analytics_storage:a?"granted":"denied",ad_storage:p?"granted":"denied",'
            . 'ad_user_data:p?"granted":"denied",ad_personalization:"denied"});if(a||p){N.gtmYukle();}}};'
            . 'if(t){N.guncelle(!!t.a,!!t.p);}'
            . '})(window,document);';
    }

    /**
     * "Çerez ayarları" bağlantısını alt bilgide politika satırının hemen ardına ekler (YOOtheme liste öğesi).
     * Satır bulunamazsa </footer> önüne, footer yoksa </body> önüne yalın bir bağlantı konur.
     */
    public static function altbilgiBaglantisi($html, $politika, $metin)
    {
        $h   = htmlspecialchars($metin, ENT_QUOTES, 'UTF-8');
        $bas = stripos($html, '<footer');

        if ($bas !== false && $politika !== '') {
            $son   = stripos($html, '</footer>', $bas);
            $ayak  = $son !== false ? substr($html, $bas, $son - $bas) : '';
            $desen = '~<li class="el-item">\s*<div class="el-content[^"]*">\s*<a href="' . preg_quote($politika, '~') . '"[^>]*>.*?</a>\s*</div>\s*</li>~is';

            if ($ayak !== '' && preg_match($desen, $ayak, $esl, PREG_OFFSET_CAPTURE)) {
                $oge  = '<li class="el-item"><div class="el-content uk-panel uk-h6">'
                    . '<a href="#cerez-ayarlari" class="el-link uk-link-muted uk-margin-remove-last-child" data-ncz-ac>' . $h . '</a>'
                    . '</div></li>';
                $yer  = $bas + $esl[0][1] + strlen($esl[0][0]);

                return substr($html, 0, $yer) . $oge . substr($html, $yer);
            }

            if ($son !== false) {
                return self::onune($html, '</footer>', '<p class="ncz-altbilgi"><a href="#cerez-ayarlari" data-ncz-ac>' . $h . '</a></p>');
            }
        }

        return self::onune($html, '</body>', '<p class="ncz-altbilgi"><a href="#cerez-ayarlari" data-ncz-ac>' . $h . '</a></p>');
    }

    /** $isaret'in SON geçtiği yerin önüne ekler (</head>, </body>, </footer>). */
    private static function onune($html, $isaret, $ek)
    {
        $yer = strripos($html, $isaret);

        return $yer === false ? $html : substr($html, 0, $yer) . $ek . substr($html, $yer);
    }

    /** preg_replace yerine koyma metninde $ ve \ kaçışı. */
    private static function kacis($s)
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $s);
    }
}
