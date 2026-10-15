<?php
/**
 * @package     plg_system_numistrseo
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Saf yardımcılar (Joomla'sız sınanır: tests/numistrseo_test.php). M4-b/M4-c, ADR-011.
 */

defined('_JEXEC') or die;

class PlgNumistrSeoHelper
{
    /**
     * Aynı coin-id'li karşı dil kopyalarından birini seçer: başlığı birebir aynı olan, yoksa en küçük kimlik.
     * (822 TR sikkenin birden çok EN kopyası var — mükerrer EN makaleler; seçim kararlı olmalı.)
     *
     * @param   array   $adaylar  [['id' => int, 'title' => string, ...], ...]
     * @param   string  $baslik   geçerli makalenin başlığı
     *
     * @return  array|null
     */
    public static function esSec(array $adaylar, $baslik)
    {
        if (!$adaylar) {
            return null;
        }

        usort($adaylar, function ($a, $b) {
            return (int) $a['id'] <=> (int) $b['id'];
        });

        foreach ($adaylar as $a) {
            if (trim((string) $a['title']) === trim((string) $baslik)) {
                return $a;
            }
        }

        return $adaylar[0];
    }

    /**
     * Eş karşılıklı mı: eşin kendi gözünden seçeceği karşı dil kopyası geçerli makale mi? (aynı coin-id'li tüm kopyalar verilir)
     *
     * @param   array  $hepsi  aynı coin-id'li yayındaki makaleler [['id','title','language'], ...] (geçerli makale dahil)
     * @param   array  $es     esSec ile seçilen eş
     * @param   int    $benId  geçerli makale
     *
     * @return  bool
     */
    public static function karsilikliMi(array $hepsi, array $es, $benId)
    {
        $esTr = in_array($es['language'], array('*', 'tr-TR'), true);
        $geri = array_values(array_filter($hepsi, function ($a) use ($esTr, $es) {
            return (int) $a['id'] !== (int) $es['id'] && ($esTr ? $a['language'] === 'en-GB' : in_array($a['language'], array('*', 'tr-TR'), true));
        }));
        $sec = self::esSec($geri, (string) $es['title']);

        return $sec !== null && (int) $sec['id'] === (int) $benId;
    }

    /**
     * Dil değiştiricide (YOOtheme `mod-languages` bloğu) karşı dilin bağlantısını verilen adresle değiştirir.
     * Yalnız `mod-languages` bloklarının içine dokunur; etkin (`uk-active`) dil ve blok dışı bağlantılar aynen kalır.
     *
     * @param   string  $html   sayfa gövdesi
     * @param   array   $urls   ['EN' => 'https://…', 'TR' => 'https://…'] — bağlantı metnine göre (büyük harf)
     *
     * @return  string
     */
    public static function dilDegistirici($html, array $urls)
    {
        if (!$urls || strpos($html, 'mod-languages') === false) {
            return $html;
        }

        return preg_replace_callback('~(<div\b[^>]*class="[^"]*\bmod-languages\b[^"]*"[^>]*>)(.*?)(</ul>)~s', function ($blok) use ($urls) {
            $ic = preg_replace_callback('~<li\b([^>]*)>(.*?)</li>~s', function ($li) use ($urls) {
                if (strpos($li[1], 'uk-active') !== false) {
                    return $li[0];
                }

                $metin = strtoupper(trim(strip_tags($li[2])));

                if (!isset($urls[$metin])) {
                    return $li[0];
                }

                $yeni = preg_replace('~(<a\b[^>]*?\bhref=")[^"]*(")~', '${1}' . htmlspecialchars($urls[$metin], ENT_QUOTES, 'UTF-8') . '${2}', $li[2], 1);

                return '<li' . $li[1] . '>' . $yeni . '</li>';
            }, $blok[2]);

            return $blok[1] . $ic . $blok[3];
        }, $html);
    }
}
