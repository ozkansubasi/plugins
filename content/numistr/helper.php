<?php
/**
 * @package     plg_content_numistr
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Saf yardımcılar (Joomla'sız sınanır: tests/numistr_test.php). B14, 2026-10-18.
 *
 * Figür HTML'i `scripts/icerik_uretec/gorsel_sec.py::figur_html` ile BAYT BAYT aynıdır (test sabit vektörü Python'dan
 * üretildi); biri değişirse öteki de değişmeli. Fark yalnız verinin kaynağı: Python API'den yayın anında, burada DB'den
 * sayfa çizilirken — lisans kapanırsa ya da görsel erişilemez olursa figür kendiliğinden düşer.
 */

defined('_JEXEC') or die;

class PlgNumistrContentHelper
{
    /** Kısa kodun adı: {numistr_gorsel variant=12764 gorsel=38282,38283} */
    const KOD = 'numistr_gorsel';

    /** Bir figürde en çok kaç görsel (yayındaki figürlerde 1–2). */
    const EN_COK_GORSEL = 6;

    /** Altyazı için okunan özel alanlar (adlar tireli; sorgular.md şema notu). */
    const ALANLAR = array('mint-name', 'material', 'start-date', 'end-date', 'obverse-desc-tr', 'reverse-desc-tr');

    const METAL_TR = array('gold' => 'altın', 'silver' => 'gümüş', 'electrum' => 'elektrum', 'bronze' => 'bronz',
        'copper' => 'bakır', 'billon' => 'billon', 'lead' => 'kurşun');

    const YUZ_TR = array('on' => 'Ön yüz', 'arka' => 'Arka yüz', 'detay' => 'Detay');

    /** Eklentinin DatabaseHelper::normalizeMaterialKey kısa kodları (constants.php MATERIAL_MAP). */
    const MALZEME_KOD = array('ae' => 'bronze', 'ar' => 'silver', 'av' => 'gold', 'au' => 'gold', 'el' => 'electrum',
        'cu' => 'bronze', 'pb' => 'lead', 'fe' => 'iron');

    // Stiller satır içi (gorsel_sec.py ile aynı; Joomla <style> bloklarını siliyor, makale tema CSS'ine bağlı kalmasın)
    const S_FIGUR     = 'margin:32px 0;padding:0';
    const S_SIRA      = 'display:flex;gap:16px;justify-content:center;align-items:center;flex-wrap:wrap';
    const S_IMG_TEK   = 'max-width:420px;width:100%;display:block;margin:0 auto;height:auto';
    const S_IMG_CIFT  = 'max-width:230px;width:45%;display:block;height:auto';
    const S_ALTYAZI   = 'font-size:14px;line-height:1.6;color:#5b6470;text-align:center;margin-top:12px;max-width:560px;margin-left:auto;margin-right:auto';
    const S_ATIF      = 'display:block;font-size:12px;color:#999;margin-top:6px';

    /**
     * Metindeki her kısa kodu `$uret(variant, [görsel kimlikleri])` dönüşüyle değiştirir. Kodu tek başına saran <p> da
     * gider (TinyMCE kodu paragrafa alır; <figure> paragraf içinde geçersiz). Bozuk kod yorum satırına döner.
     *
     * @param   string    $metin
     * @param   callable  $uret  function (int $variant, array $ids): string
     *
     * @return  string
     */
    public static function degistir($metin, callable $uret)
    {
        if (strpos((string) $metin, '{' . self::KOD) === false) {
            return (string) $metin;
        }

        $kod = '\{' . self::KOD . '(?:\s|&nbsp;|\x{00A0})[^{}]*\}';

        return preg_replace_callback('~<p\b[^>]*>(?:\s|&nbsp;|\x{00A0})*(' . $kod . ')(?:\s|&nbsp;|\x{00A0})*</p>|(' . $kod . ')~u',
            function ($m) use ($uret) {
                $ham = isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1];
                $k   = self::ayristir($ham);

                if ($k === null) {
                    return '<!-- ' . self::KOD . ': geçersiz kod -->';
                }

                return (string) $uret($k['variant'], $k['gorsel']);
            }, (string) $metin);
    }

    /**
     * `{numistr_gorsel variant=12764 gorsel=38282,38283}` → ['variant' => 12764, 'gorsel' => [38282, 38283]].
     * Tırnak ve &nbsp; (düzenleyici) tolere edilir; başka her şey null.
     *
     * @return  array|null
     */
    public static function ayristir($ham)
    {
        $ic = str_replace(array('&nbsp;', "\xC2\xA0", '&quot;', '"', "'"), array(' ', ' ', '', '', ''), (string) $ham);

        if (!preg_match('~^\{' . self::KOD . '\s+variant\s*=\s*(\d{1,9})\s+gorsel\s*=\s*(\d{1,9}(?:\s*,\s*\d{1,9})*)\s*\}$~', $ic, $m)) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', preg_split('~\s*,\s*~', $m[2]))));

        if ((int) $m[1] <= 0 || count($ids) > self::EN_COK_GORSEL || in_array(0, $ids, true)) {
            return null;
        }

        return array('variant' => (int) $m[1], 'gorsel' => $ids);
    }

    /**
     * Yazıda gösterilebilecek görseller, istenen sırada. Kural (gorsel_sec.py lisans kapısı + G12):
     * görsel bu sikkenin · `license_ok` = 1 (ticari kullanıma açık; NC ve doğrulanmamış DEĞİL) · kaynağı erişilebilir.
     *
     * @param   int[]  $istenen   kısa koddaki kimlikler
     * @param   array  $satirlar  coins_images satırları (image_id, coin_id, image_type, source_credit, license, license_ok,
     *                            source_unavailable)
     *
     * @return  array  [['image_id' => int, 'type' => ?string, 'credit' => ?string, 'license' => ?string], ...]
     */
    public static function secilenler(array $istenen, array $satirlar, $variant)
    {
        $harita = array();

        foreach ($satirlar as $r) {
            $harita[(int) ($r['image_id'] ?? 0)] = $r;
        }

        $out = array();

        foreach ($istenen as $id) {
            $r = $harita[(int) $id] ?? null;

            if ($r === null || (int) ($r['coin_id'] ?? 0) !== (int) $variant) {
                continue;
            }

            $ok = $r['license_ok'] ?? null;

            if (!is_numeric($ok) || (int) $ok !== 1 || (int) ($r['source_unavailable'] ?? 0) === 1) {
                continue;
            }

            $out[] = array('image_id' => (int) $id, 'type' => $r['image_type'] ?? null,
                'credit' => ($r['source_credit'] ?? '') !== '' ? $r['source_credit'] : null,
                'license' => ($r['license'] ?? '') !== '' ? $r['license'] : null);
        }

        return $out;
    }

    /**
     * Altyazı kaydı — API'nin /v1/variants/{id} alanlarıyla aynı öncelik (özel alan, yoksa görünüm sütunu).
     *
     * @param   array  $v       o_numistr_variants_public satırı (boş olabilir)
     * @param   array  $alanlar [alan adı => değer] (self::ALANLAR)
     * @param   string $baslik  #__content.title (görünümde başlık yoksa)
     */
    public static function kayit(array $v, array $alanlar, $baslik = '')
    {
        $dolu = function ($x) {
            return $x !== null && $x !== '' ? $x : null;
        };
        $yil = function ($x) {
            return ($x === null || $x === '') ? null : (int) $x;
        };
        $malzeme = $dolu($v['metal'] ?? null);

        if ($malzeme === null && array_key_exists('material', $alanlar)) {
            $malzeme = $alanlar['material'];
        }

        return array(
            'title'           => $dolu($v['title_tr'] ?? null) ?? $dolu($v['title_en'] ?? null) ?? $dolu($v['slug'] ?? null)
                ?? (string) $baslik,
            'mint'            => $alanlar['mint-name'] ?? $v['mint_name'] ?? null,
            'material'        => self::malzeme($malzeme),
            'date_from'       => $yil($alanlar['start-date'] ?? $v['date_from'] ?? null),
            'date_to'         => $yil($alanlar['end-date'] ?? $v['date_to'] ?? null),
            'obverse_desc_tr' => $alanlar['obverse-desc-tr'] ?? null,
            'reverse_desc_tr' => $alanlar['reverse-desc-tr'] ?? null,
        );
    }

    /** DatabaseHelper::normalizeMaterialKey ile aynı kural. */
    public static function malzeme($v)
    {
        if ($v === null) {
            return null;
        }

        $v = trim(mb_strtolower((string) $v, 'UTF-8'));

        if ($v === '') {
            return null;
        }

        if (isset(self::MALZEME_KOD[$v])) {
            return self::MALZEME_KOD[$v];
        }

        if (str_contains($v, 'gumus') || str_contains($v, 'gümüş') || $v === 'silver') {
            return 'silver';
        }

        if (str_contains($v, 'altin') || str_contains($v, 'altın') || $v === 'gold') {
            return 'gold';
        }

        if (str_contains($v, 'bronz') || $v === 'bronze' || $v === 'copper' || $v === 'cu') {
            return 'bronze';
        }

        $esit = array('electrum' => 'electrum', 'elektrum' => 'electrum', 'lead' => 'lead', 'kursun' => 'lead',
            'iron' => 'iron', 'demir' => 'iron', 'billon' => 'billon', 'biyon' => 'billon');

        return $esit[$v] ?? $v;
    }

    /** -560 → 'MÖ 560' · 120 → 'MS 120' */
    public static function yilYaz($n)
    {
        if ($n === null || $n === '') {
            return '';
        }

        $n = (int) $n;

        return $n < 0 ? 'MÖ ' . (-$n) : 'MS ' . $n;
    }

    public static function tarihAraligi(array $k)
    {
        $bas = self::yilYaz($k['date_from'] ?? null);
        $son = self::yilYaz($k['date_to'] ?? null);

        if ($bas !== '' && $son !== '' && $bas === $son) {
            return $bas;
        }

        if ($bas !== '' && $son !== '') {
            $b = explode(' ', $bas);
            $s = explode(' ', $son);

            return $b[0] === $s[0] ? $bas . '–' . $s[1] : $bas . ' – ' . $son;
        }

        return $bas !== '' ? $bas : $son;
    }

    /** Python str.capitalize(): ilk harf büyük, kalanı küçük. */
    public static function ilkBuyuk($s)
    {
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_strtolower(mb_substr($s, 1, null, 'UTF-8'), 'UTF-8');
    }

    /** Python html.escape(s, quote=False) */
    private static function e($s)
    {
        return htmlspecialchars((string) $s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Python html.escape(s, quote=True) — tek tırnak &#x27; */
    private static function ea($s)
    {
        return str_replace("'", '&#x27;', htmlspecialchars((string) $s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /** Python str.strip() + rstrip('.') */
    private static function kirp($s)
    {
        return rtrim(trim((string) $s), '.');
    }

    public static function altyazi(array $k, $sikkeUrl, array $secilen)
    {
        $parca = array();
        $darphane = trim((string) ($k['mint'] ?? ''));

        if ($darphane !== '') {
            $parca[] = self::ilkBuyuk($darphane);
        }

        $tarih = self::tarihAraligi($k);

        if ($tarih !== '') {
            $parca[] = $tarih;
        }

        $metal = self::METAL_TR[strtolower((string) ($k['material'] ?? ''))] ?? null;

        if ($metal !== null) {
            $parca[] = $metal;
        }

        $turler  = array_column($secilen, 'type');
        $betimler = array();

        if (in_array('on', $turler, true) && trim((string) ($k['obverse_desc_tr'] ?? '')) !== '') {
            $betimler[] = 'Ön yüz: ' . self::kirp($k['obverse_desc_tr']);
        }

        if (in_array('arka', $turler, true) && trim((string) ($k['reverse_desc_tr'] ?? '')) !== '') {
            $betimler[] = 'Arka yüz: ' . self::kirp($k['reverse_desc_tr']);
        }

        $metin = '<b><a href="' . self::ea($sikkeUrl) . '">' . self::e($k['title'] ?? '') . '</a></b>';

        if ($parca) {
            $metin .= ' — ' . self::e(implode(', ', $parca)) . '.';
        }

        if ($betimler) {
            $metin .= ' ' . self::e(implode('. ', $betimler)) . '.';
        }

        return $metin;
    }

    /** Sitedeki galeriyle aynı biçim: 'Kurum · Lisans'; farklı kaynaklar ' | ' ile. */
    public static function atif(array $secilen)
    {
        $gorulen = array();
        $satir   = array();

        foreach ($secilen as $g) {
            $ikili = array(trim((string) ($g['credit'] ?? '')), trim((string) ($g['license'] ?? '')));
            $anahtar = implode("\0", $ikili);

            if (isset($gorulen[$anahtar]) || ($ikili[0] === '' && $ikili[1] === '')) {
                continue;
            }

            $gorulen[$anahtar] = true;
            $satir[] = implode(' · ', array_filter($ikili, 'strlen'));
        }

        return implode(' | ', $satir);
    }

    /**
     * @param   array   $k         self::kayit()
     * @param   string  $sikkeUrl  sikke sayfasının mutlak adresi
     * @param   array   $secilen   self::secilenler() (boş olmamalı)
     * @param   string  $kok       site kökü, sondaki / olmadan (https://www.numistr.org)
     */
    public static function figurHtml(array $k, $sikkeUrl, array $secilen, $kok)
    {
        $cift   = count($secilen) > 1;
        $satir  = array('<figure class="numistr-figur" style="' . self::S_FIGUR . '">');
        $girinti = $cift ? '    ' : '  ';

        if ($cift) {
            $satir[] = '  <div class="numistr-figur-sira" style="' . self::S_SIRA . '">';
        }

        foreach ($secilen as $g) {
            $yuz = self::YUZ_TR[$g['type'] ?? ''] ?? '';
            $alt = $yuz !== '' ? ($k['title'] ?? '') . ' — ' . $yuz : ($k['title'] ?? '');
            $src = $kok . '/index.php?option=com_numistr&amp;view=gorsel&amp;format=raw&amp;id=' . (int) $g['image_id'] . '&amp;wm=1';
            $satir[] = $girinti . '<img src="' . $src . '" alt="' . self::ea($alt) . '" loading="lazy" style="'
                . ($cift ? self::S_IMG_CIFT : self::S_IMG_TEK) . '">';
        }

        if ($cift) {
            $satir[] = '  </div>';
        }

        $satir[] = '  <figcaption style="' . self::S_ALTYAZI . '">';
        $satir[] = '    ' . self::altyazi($k, $sikkeUrl, $secilen);
        $atif    = self::atif($secilen);

        if ($atif !== '') {
            $satir[] = '    <span class="img-credit" style="' . self::S_ATIF . '">' . self::e($atif) . '</span>';
        }

        $satir[] = '  </figcaption>';
        $satir[] = '</figure>';

        return implode("\n", $satir);
    }
}
