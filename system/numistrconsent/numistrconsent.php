<?php
/**
 * @package     NumisTR Cookie Consent
 * @subpackage  plg_system_numistrconsent
 * @version     1.1.0
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
 *
 * 1.1.0 — rızanın sunucuda kaydı (ADR-013): ziyaretçi bir eylem yapınca (tümünü kabul / ret, seçimleri kaydet,
 * geri çekme) cerez.js `index.php?option=com_ajax&plugin=numistrconsent&group=system&format=raw` ucuna JSON gönderir;
 * onAjaxNumistrconsent() doğrular ve `numistr_cerez_rizalari` tablosuna YALNIZ EKLER. Kayıtta rastgele onay kimliği,
 * sunucu zamanı (UTC), metin sürümü, eylem, katman, seçimler, dil ve sayfa yolu var; IP, User-Agent, hesap ve GA
 * kimliği YOK. Hız sınırı IP başına saatlik sayaçla (Joomla önbelleği, anahtar HMAC; IP yazılmaz). Saklama süresi
 * dolan kayıtlar eklemelerin ~1/200'ünde silinir. Geri alma: ayar "Rıza kaydı" = Hayır.
 * /app sayfası (plg_system_numistrapp ≥ 1.1.0) pencere göstermez, aynı çerezi okur.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;

class PlgSystemNumistrconsent extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    protected $autoloadLanguage = true;

    public const SURUM          = '1.1.0';
    public const GTM_VARSAYILAN = 'GTM-TRH8ZR6M';
    public const CEREZ          = 'numistr_cerez_tercihi';
    public const SURE_GUN       = 182;
    public const MEDYA          = '/media/plg_system_numistrconsent';
    public const ISARET         = 'id="ncz-veri"';
    public const TABLO          = 'numistr_cerez_rizalari';
    public const KAYIT_YOLU     = '/index.php?option=com_ajax&plugin=numistrconsent&group=system&format=raw';
    public const GOVDE_SINIR    = 1024;
    public const SAKLAMA_GUN    = 730;
    public const HIZ_SAAT       = 30;
    public const EYLEMLER       = ['tumunu_kabul', 'tumunu_ret', 'secim_kaydet', 'geri_cekme'];
    public const KOKENLER       = ['https://www.numistr.org', 'https://numistr.org'];

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
        'tr' => ['cerezler' => 'Çerezler', 'ad' => 'Ad', 'saglayici' => 'Sağlayıcı', 'amac' => 'Amaç', 'sure' => 'Süre', 'bolge' => 'Çerez tercihleri', 'kimlik' => 'Onay kimliğiniz'],
        'en' => ['cerezler' => 'Cookies', 'ad' => 'Name', 'saglayici' => 'Provider', 'amac' => 'Purpose', 'sure' => 'Duration', 'bolge' => 'Cookie preferences', 'kimlik' => 'Your consent ID'],
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
            'kayit'       => (int) $this->params->get('kayit_acik', 1) === 1 ? rtrim(Uri::root(true), '/') . self::KAYIT_YOLU : '',
        ]);

        if ($yeni !== $body) {
            $this->app->setBody($yeni);
        }
    }

    // --- rıza kaydı (ADR-013) ------------------------------------------------

    /** com_ajax ucu: POST gövdesini doğrular, hız sınırını uygular, kaydı ekler. Yanıt gövdesizdir (204/400/403/405/429/500). */
    public function onAjaxNumistrconsent()
    {
        $this->yanit($this->app->isClient('site') ? $this->kayitAl() : 403);

        return '';
    }

    private function kayitAl()
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return 405;
        }

        // Kayıt kapalıysa istemci yeniden denemesin: başarı gibi yanıtla.
        if ((int) $this->params->get('kayit_acik', 1) !== 1) {
            return 204;
        }

        $metin = self::metinleriOku(JPATH_ROOT . self::MEDYA . '/metinler.json');
        $govde = (string) file_get_contents('php://input', false, null, 0, self::GOVDE_SINIR + 1);
        $kayit = self::kayitDogrula(
            $govde,
            (string) ($_SERVER['HTTP_ORIGIN'] ?? ''),
            (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            $metin ? (string) $metin['surum'] : 'yedek',
            self::izinliKokenler()
        );

        if (is_int($kayit)) {
            return $kayit;
        }

        if (!$this->hizUygun((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            return 429;
        }

        try {
            $db = Factory::getDbo();
            $db->setQuery(self::eklemeSorgusu($db, $kayit))->execute();

            if (random_int(1, 200) === 1) {
                $gun = max(30, (int) $this->params->get('saklama_gun', self::SAKLAMA_GUN));
                $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLO) . ' WHERE ' . $db->quoteName('zaman')
                    . ' < UTC_TIMESTAMP() - INTERVAL ' . $gun . ' DAY LIMIT 1000')->execute();
            }
        } catch (\Throwable $e) {
            return 500;
        }

        return 204;
    }

    private function yanit($kod)
    {
        $this->app->setHeader('status', (string) $kod, true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $this->app->sendHeaders();
        $this->app->close();
    }

    /** Sitenin kendi kökeni + numistr.org adları. */
    private static function izinliKokenler()
    {
        $kok = Uri::getInstance(Uri::root())->toString(['scheme', 'host', 'port']);

        return array_values(array_unique(array_merge(self::KOKENLER, [$kok])));
    }

    /** IP başına saatlik sayaç (Joomla önbelleği). Anahtar HMAC(IP|saat, site sırrı); IP hiçbir yere yazılmaz. */
    private function hizUygun($ip)
    {
        try {
            $onbellek = Factory::getContainer()->get(CacheControllerFactoryInterface::class)->createCacheController('output', [
                'defaultgroup' => 'plg_numistrconsent_hiz',
                'caching'      => true,
                'lifetime'     => 60,
            ]);
            $anahtar = hash_hmac('sha256', $ip . '|' . gmdate('YmdH'), (string) $this->app->get('secret'));
            $sayi    = (int) $onbellek->get($anahtar);

            if ($sayi >= max(1, (int) $this->params->get('hiz_saat', self::HIZ_SAAT))) {
                return false;
            }

            $onbellek->store((string) ($sayi + 1), $anahtar);
        } catch (\Throwable $e) {
            // Önbellek çalışmıyorsa kayıt engellenmez (sınır yalnız kötüye kullanım için).
        }

        return true;
    }

    /**
     * Gövdeyi ve kökeni doğrular (Joomla'sız sınanır). Hata: HTTP kodu (int). Başarı: tabloya yazılacak alanlar.
     * Gövde: {i: 32 hex, t: istemci ms, v: metin sürümü, e: eylem, k: 1|2, a: 0|1, p: 0|1, d: tr|en, s: yol|null}
     *
     * @return int|array<string,int|string|null>
     */
    public static function kayitDogrula($govde, $origin, $referer, $surum, array $kokenler)
    {
        if (!self::kokenUygun($origin, $referer, $kokenler)) {
            return 403;
        }

        if ($govde === '' || strlen($govde) > self::GOVDE_SINIR) {
            return 400;
        }

        $g = json_decode($govde, true);

        if (!is_array($g)) {
            return 400;
        }

        $i = $g['i'] ?? null;
        $t = $g['t'] ?? null;
        $e = $g['e'] ?? null;
        $k = $g['k'] ?? null;
        $a = $g['a'] ?? null;
        $p = $g['p'] ?? null;
        $d = $g['d'] ?? null;

        if (!is_string($i) || !preg_match('~^[0-9a-f]{32}$~', $i)
            || !is_int($t) || $t < 1600000000000 || $t > 4100000000000
            || !is_string($g['v'] ?? null) || $g['v'] !== $surum
            || !is_string($e) || !in_array($e, self::EYLEMLER, true)
            || !in_array($k, [1, 2], true) || !in_array($a, [0, 1], true) || !in_array($p, [0, 1], true)
            || !in_array($d, ['tr', 'en'], true)) {
            return 400;
        }

        if (($e === 'tumunu_kabul' && ($a !== 1 || $p !== 1)) || ($e === 'tumunu_ret' && ($a !== 0 || $p !== 0))) {
            return 400;
        }

        return [
            'onay_kimligi'    => $i,
            'istemci_zaman'   => $t,
            'metin_surumu'    => $surum,
            'eylem'           => $e,
            'katman'          => $k,
            'analitik'        => $a,
            'pazarlama'       => $p,
            'kisisellestirme' => 0,
            'dil'             => $d,
            'sayfa'           => self::sayfaYolu($g['s'] ?? null),
        ];
    }

    /** Origin varsa o, yoksa Referer'ın kökeni izinli listede olmalı; ikisi de yoksa ret. */
    public static function kokenUygun($origin, $referer, array $kokenler)
    {
        if ($origin !== '' && $origin !== 'null') {
            return in_array(rtrim($origin, '/'), $kokenler, true);
        }

        if ($referer === '') {
            return false;
        }

        $u = parse_url($referer);

        if (empty($u['scheme']) || empty($u['host'])) {
            return false;
        }

        return in_array($u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''), $kokenler, true);
    }

    /** Yalnız yol: sorgu ve parça atılır, '/' ile başlamalı, denetim karakteri yok, en çok 191 karakter. Uymazsa null. */
    public static function sayfaYolu($s)
    {
        if (!is_string($s) || $s === '') {
            return null;
        }

        $s = preg_replace('~[?#].*$~s', '', $s);

        if ($s === '' || $s[0] !== '/' || preg_match('~[\x00-\x1F\x7F]~', $s)) {
            return null;
        }

        return mb_substr($s, 0, 191, 'UTF-8');
    }

    /** INSERT IGNORE: aynı (onay_kimligi, istemci_zaman) ikinci kez eklenmez (istemcinin yeniden denemesi). */
    public static function eklemeSorgusu($db, array $k)
    {
        $sutun = [];
        $deger = [];

        foreach ($k as $ad => $v) {
            $sutun[] = $db->quoteName($ad);
            $deger[] = $v === null ? 'NULL' : (is_int($v) ? (string) $v : $db->quote($v));
        }

        return 'INSERT IGNORE INTO ' . $db->quoteName(self::TABLO) . ' (' . implode(', ', $sutun) . ', ' . $db->quoteName('zaman') . ')'
            . ' VALUES (' . implode(', ', $deger) . ', UTC_TIMESTAMP(3))';
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
     * @param array{dil:string, metin:?array, gtm:string, sure_gun:int, politika:string, sablon_gtm:bool, medya:string, kayit?:string} $p
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

        $veri = ['dil' => $dil, 'm' => $m, 'e' => self::EK[$dil], 'c' => $cerezler, 'politika' => (string) $p['politika'], 'k' => (string) ($p['kayit'] ?? '')];
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
