<?php
/**
 * Yönetim panelindeki eklenti ayarlarının config/constants.php değerlerine uygulanması.
 *
 * 2026-09-27: numistr.xml'deki "Safe Cap" alanı hiçbir yere bağlı değildi; panelde
 * 2000 → 4000 kaydedilmesine rağmen sınır constants.php'deki 2000'de kaldı ve Misya
 * (görselli 2320, tümü 2800 sikke) bölge listesi 422 "Sonuç kümesi çok geniş" alıyordu.
 * Yalnız safe_cap bağlandı: root_category_id / pro_group_id alanları da bağlı değil ama
 * panel varsayılanları sabitlerden farklı (Pro grup 9 ≠ 10) — bağlamak Pro'yu bozabilir.
 */

defined('_JEXEC') or die;

class NumisTRConfigParams
{
    /** numistr.xml safe_cap alanının sınırları (min/max) */
    public const SAFE_CAP_MIN = 100;
    public const SAFE_CAP_MAX = 10000;

    /**
     * Panel değeri geçerli bir sayıysa (sınırlara kırpılarak) onu, değilse sabiti döndürür.
     *
     * @param mixed $param   $this->params->get('safe_cap') (kaydedilmemişse null)
     * @param int   $default constants.php SAFE_CAP
     */
    public static function safeCap($param, int $default): int
    {
        if ($param === null || $param === '' || !is_numeric($param)) {
            return $default;
        }

        return max(self::SAFE_CAP_MIN, min(self::SAFE_CAP_MAX, (int) $param));
    }
}
