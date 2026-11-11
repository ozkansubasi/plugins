<?php
/**
 * @package     NumisTR Canonical
 * @subpackage  plg_system_numistrcanonical
 * @version     1.0.0
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * BACKLOG C11 — sorgusuz `canonical`. Joomla'nın "System - SEF" eklentisi (Site Domain dolu) canonical'a
 * sorgu dizgesini de koyuyor: `/tr/?utm_source=x` kendi kendini canonical gösteriyor. Bu eklenti sayfa başı
 * derlenmeden hemen önce (onBeforeCompileHead, SEF eklentisinden sonra) var olan canonical'ı siler ve
 * `alan adı + yol` yazar. Sorgudan yalnız sayfalama parametreleri (ayar `keep_params`) kalır.
 *
 * Dokunmadığı durumlar: yönetim, HTML olmayan belge (hata sayfası, raw, json), SEF'siz adres
 * (sorguda `option` varsa — `/index.php?option=…` hepsi aynı yola düşmesin; SEF eklentisinin canonical'ı kalır).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;

class PlgSystemNumistrcanonical extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    protected $autoloadLanguage = true;

    public function onBeforeCompileHead()
    {
        if (!$this->app->isClient('site')) {
            return;
        }

        $doc = $this->app->getDocument();

        if (!$doc || $doc->getType() !== 'html') {
            return;
        }

        $uri  = Uri::getInstance();
        $keep = array_filter(array_map('trim', explode(',', (string) $this->params->get('keep_params', 'start,limitstart'))));
        $href = self::kanonik((string) $this->params->get('domain', 'https://www.numistr.org'), $uri->getPath(), $uri->getQuery(true), $keep);

        if ($href === null) {
            return;
        }

        foreach ($doc->_links as $adres => $baglanti) {
            if (isset($baglanti['relation']) && $baglanti['relation'] === 'canonical') {
                unset($doc->_links[$adres]);
            }
        }

        $doc->addHeadLink(htmlspecialchars($href, ENT_QUOTES, 'UTF-8'), 'canonical');
    }

    /**
     * Canonical adresi: alan adı + yol + (yalnız izinli, anlamlı sayfalama parametreleri).
     *
     * @param   string  $domain  https://alan.adi (sonda / olmadan da olur)
     * @param   string  $path    istek yolu (/tr/…)
     * @param   array   $query   istek sorgusu (ayrıştırılmış)
     * @param   array   $keep    korunacak parametre adları
     *
     * @return  string|null  null → dokunma
     */
    public static function kanonik($domain, $path, array $query, array $keep)
    {
        $domain = rtrim(trim((string) $domain), '/');

        if (!preg_match('~^https://[a-z0-9.-]+$~i', $domain)) {
            return null;
        }

        // SEF'siz adres: yol hep /index.php olur; sorgusuz canonical bütün sayfaları tek adrese bağlardı.
        if (isset($query['option'])) {
            return null;
        }

        $path = '/' . ltrim((string) $path, '/');

        $tut = array();

        foreach ($keep as $ad) {
            if (!isset($query[$ad]) || !is_scalar($query[$ad])) {
                continue;
            }

            $deger = (string) $query[$ad];

            // Sayfalama: yalnız pozitif tam sayı; 0 ilk sayfadır (sorgusuz adresle aynı).
            if (!ctype_digit($deger) || (int) $deger === 0) {
                continue;
            }

            $tut[$ad] = (string) (int) $deger;
        }

        ksort($tut);

        return $domain . $path . ($tut ? '?' . http_build_query($tut) : '');
    }
}
