<?php
/**
 * @package     NumisTR App Link
 * @subpackage  plg_system_numistrapp
 * @version     1.0.0
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Prime NT-261112-023202th — reklam ve paylaşımlar için tek uygulama bağlantısı:
 *   https://www.numistr.org/tr/app  ·  https://www.numistr.org/en/app
 * iPhone/iPad → App Store, Android → Google Play, masaüstü/bilinmeyen → iki rozetli sayfa (yönlendirme yok).
 *
 * Ölçüm şartı: yönlendirme sunucuda (301/302) DEĞİL, istemcide. Sayfa sitedeki GTM kapsayıcısını aynen
 * yükler, `dataLayer.push({event:'app_store_redirect', store:'apple'|'google', lang:'tr'|'en'})` iter,
 * GTM etiketleri bitince (eventCallback) ya da en geç 1 sn sonra mağazaya gider. Gelen adresteki utm_*
 * parametreleri sayfa adresinde kalır (GA4 page_view okur); Google Play bağlantısına ayrıca `referrer`
 * olarak eklenir (Play Install Referrer → uygulamadaki Firebase kurulum kaynağı).
 * Sayfa noindex (meta + X-Robots-Tag). GTM / GA4 / Ads ayarları Prime'da.
 *
 * Joomla yönlendiricisine hiç girmez: onAfterInitialise'da yol eşleşirse sayfayı basıp uygulamayı kapatır.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;

class PlgSystemNumistrapp extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    protected $autoloadLanguage = true;

    public const GTM_VARSAYILAN = 'GTM-TRH8ZR6M';

    public function onAfterInitialise()
    {
        if (!$this->app->isClient('site')) {
            return;
        }

        $uri  = Uri::getInstance();
        $dil  = self::eslesme($uri->getPath());

        if ($dil === null) {
            return;
        }

        $gtm  = (string) $this->params->get('gtm_id', self::GTM_VARSAYILAN);
        $html = self::sayfa($dil, $gtm, self::magazaAdresleri($dil, $uri->getQuery(true)));

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->app->setHeader('Cache-Control', 'public, max-age=600', true);
        $this->app->sendHeaders();
        echo $html;
        $this->app->close();
    }

    /**
     * @return string|null  'tr' | 'en' | null (bu eklentinin adresi değil)
     */
    public static function eslesme($path)
    {
        if (preg_match('~^/(tr|en)/app/?$~', (string) $path, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Mağaza adresleri. utm_* (ve gclid) Google Play'e `referrer` olarak geçer; değerler süzülür.
     *
     * @return array{apple:string, google:string}
     */
    public static function magazaAdresleri($dil, array $query)
    {
        $apple = $dil === 'tr'
            ? 'https://apps.apple.com/tr/app/anatolian-coins/id6810686285?l=tr'
            : 'https://apps.apple.com/us/app/anatolian-coins/id6810686285';
        $google = 'https://play.google.com/store/apps/details?id=com.anatoliancoins.app&hl=' . ($dil === 'tr' ? 'tr' : 'en');

        $ref = self::playReferrer($query);

        if ($ref !== '') {
            $google .= '&referrer=' . rawurlencode($ref);
        }

        return ['apple' => $apple, 'google' => $google];
    }

    public static function playReferrer(array $query)
    {
        $izinli = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'];
        $parca  = [];

        foreach ($izinli as $ad) {
            if (!isset($query[$ad]) || !is_string($query[$ad])) {
                continue;
            }

            $deger = preg_replace('~[^A-Za-z0-9._\-]~', '', $query[$ad]);
            $deger = substr((string) $deger, 0, 100);

            if ($deger !== '') {
                $parca[] = $ad . '=' . $deger;
            }
        }

        return implode('&', $parca);
    }

    /** Sayfa metinleri (iki dil; sayfa Joomla dil yüklemesinden önce basılır). */
    public static function metinler($dil)
    {
        if ($dil === 'tr') {
            return [
                'title'    => 'Anatolian Coins — uygulamayı indirin',
                'tagline'  => 'Antik Anadolu sikkeleri için tanıma ve katalog uygulaması.',
                'redirect' => 'Uygulama mağazasına yönlendiriliyorsunuz…',
                'choose'   => 'Telefonunuza göre mağazayı seçin:',
                'apple'    => 'App Store\'dan indirin',
                'google'   => 'Google Play\'den alın',
                'site'     => 'numistr.org\'a dön',
                'home'     => '/tr/',
                'apple_badge'  => 'https://toolbox.marketingtools.apple.com/api/v2/badges/download-on-the-app-store/black/tr-tr',
                'google_badge' => 'https://play.google.com/intl/en_us/badges/static/images/badges/tr_badge_web_generic.png',
            ];
        }

        return [
            'title'    => 'Anatolian Coins — get the app',
            'tagline'  => 'Recognition and catalogue app for ancient Anatolian coins.',
            'redirect' => 'Taking you to the app store…',
            'choose'   => 'Choose the store for your phone:',
            'apple'    => 'Download on the App Store',
            'google'   => 'Get it on Google Play',
            'site'     => 'Back to numistr.org',
            'home'     => '/en/',
            'apple_badge'  => 'https://toolbox.marketingtools.apple.com/api/v2/badges/download-on-the-app-store/black/en-us',
            'google_badge' => 'https://play.google.com/intl/en_us/badges/static/images/badges/en_badge_web_generic.png',
        ];
    }

    /**
     * @param array{apple:string, google:string} $adres
     */
    public static function sayfa($dil, $gtmId, array $adres)
    {
        $t   = self::metinler($dil);
        $h   = static function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $jsf = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

        $gtm = '';

        if (preg_match('~^GTM-[A-Z0-9]+$~', (string) $gtmId)) {
            // Sitedeki GTM parçasıyla aynı (şablondaki <head> kodu).
            $gtm = "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
                . "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;"
                . "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})"
                . "(window,document,'script','dataLayer'," . json_encode($gtmId, $jsf) . ");</script>";
        }

        $js = '(function(){'
            . 'var L=' . json_encode($dil, $jsf) . ',U=' . json_encode($adres, $jsf) . ';'
            . 'var ua=navigator.userAgent||"";'
            . 'var ios=/iPhone|iPad|iPod/i.test(ua)||(navigator.platform==="MacIntel"&&navigator.maxTouchPoints>1);'
            . 'var android=/Android/i.test(ua);'
            . 'var store=ios?"apple":(android?"google":null);'
            . 'if(!store)return;'
            . 'document.documentElement.className+=" yonlendir";'
            . 'var gitti=false;function git(){if(gitti)return;gitti=true;location.replace(U[store]);}'
            . 'window.dataLayer=window.dataLayer||[];'
            . 'window.dataLayer.push({event:"app_store_redirect",store:store,lang:L,eventCallback:git,eventTimeout:800});'
            . 'setTimeout(git,1000);'
            . '})();';

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="' . $h($dil) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $h($t['title']) . '</title>'
            . $gtm
            . '<style>'
            . ':root{--bg:#faf7f0;--fg:#2b2418;--muted:#6b5f4b;--gold:#8b6914}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#1c1a16;--fg:#f3ecdc;--muted:#b9ad94;--gold:#c9a24a}}'
            . 'html,body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            . 'main{max-width:480px;margin:0 auto;padding:48px 16px;text-align:center}'
            . 'h1{font-size:1.6rem;margin:0 0 8px;color:var(--gold)}p{margin:0 0 20px;color:var(--muted)}'
            . '.rozet{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;align-items:center;margin:8px 0 28px}'
            . '.rozet img{height:48px;width:auto;display:block}.rozet a.g img{height:70px;margin:-11px 0}'
            . '.yon{display:none;font-weight:600;color:var(--fg)}.yonlendir .yon{display:block}.yonlendir .sec{display:none}'
            . 'a.site{color:var(--gold)}'
            . '</style>'
            . '</head><body><main>'
            . '<h1>Anatolian Coins</h1>'
            . '<p>' . $h($t['tagline']) . '</p>'
            . '<p class="yon">' . $h($t['redirect']) . '</p>'
            . '<p class="sec">' . $h($t['choose']) . '</p>'
            . '<div class="rozet">'
            . '<a class="a" href="' . $h($adres['apple']) . '"><img src="' . $h($t['apple_badge']) . '" alt="' . $h($t['apple']) . '"></a>'
            . '<a class="g" href="' . $h($adres['google']) . '"><img src="' . $h($t['google_badge']) . '" alt="' . $h($t['google']) . '"></a>'
            . '</div>'
            . '<a class="site" href="' . $h($t['home']) . '">' . $h($t['site']) . '</a>'
            . '</main><script>' . $js . '</script></body></html>';
    }
}
