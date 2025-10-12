<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * NumisTR Database Helper
 * Veritabanı sorguları ve veri işleme yardımcıları
 */
class NumisTRDatabaseHelper
{
    private $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Fields values tablosunu bulur
     * Joomla versiyonuna göre tablo adı değişebilir
     * 
     * @param object $db Database instance
     * @return string Tablo adı
     */
    public function resolveFieldsValuesTable($db): string
    {
        $tableCandidates = ['#__fields_values', '#__fields_value'];
        foreach ($tableCandidates as $t) {
            try {
                $db->setQuery('SHOW TABLES LIKE ' . $db->quote(str_replace('#__', $db->getPrefix(), $t)));
                if ($db->loadResult()) {
                    return str_replace('#__', $db->getPrefix(), $t);
                }
            } catch (\Throwable $e) {}
        }
        return str_replace('#__', $db->getPrefix(), '#__fields_values');
    }
    
    /**
     * Field ID'sini döndürür
     * 
     * @param string $key Field key (material, mint_name, vb.)
     * @return int|null Field ID veya null
     */
    public function fid(string $key): ?int
    {
        return array_key_exists($key, $this->config['FIELD_ID']) 
            ? (int)$this->config['FIELD_ID'][$key] 
            : null;
    }
    
    /**
     * İzin verilen kategori ID'lerini getirir
     * Root kategorisinin altındaki tüm yayınlanmış kategoriler
     * 
     * @param object $db Database instance
     * @param int $rootId Root kategori ID
     * @return array Kategori ID'leri
     */
    public function getAllowedCatIds($db, int $rootId): array
    {
        $cats = $db->quoteName('#__categories');

        // Root kategoriyi bul
        $q1 = $db->getQuery(true)
            ->select([$db->quoteName('lft'), $db->quoteName('rgt')])
            ->from($cats)
            ->where($db->quoteName('id') . ' = ' . (int)$rootId)
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'));
        $db->setQuery($q1);
        $row = $db->loadAssoc();
        if (!$row) return [];

        // Alt kategorileri getir (Nested Set Model)
        $q2 = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($cats)
            ->where($db->quoteName('lft') . ' BETWEEN ' . (int)$row['lft'] . ' AND ' . (int)$row['rgt'])
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('lft') . ' ASC');
        $db->setQuery($q2);
        return array_map('intval', $db->loadColumn() ?: []);
    }
    
    /**
     * Malzeme key'ini normalize eder
     * Farklı yazılışları standart forma çevirir
     * 
     * @param string|null $v Malzeme değeri
     * @return string|null Normalize edilmiş key
     */
    public function normalizeMaterialKey(?string $v): ?string
    {
        if ($v === null) return null;
        $v = trim(mb_strtolower($v, 'UTF-8'));
        if ($v === '') return null;

        // Kısa kodları kontrol et
        $map = $this->config['MATERIAL_MAP'];
        if (isset($map[$v])) return $map[$v];

        // Türkçe ve İngilizce isimleri kontrol et
        if (str_contains($v, 'gumus') || str_contains($v, 'gümüş') || $v === 'silver') return 'silver';
        if (str_contains($v, 'altin') || str_contains($v, 'altın') || $v === 'gold') return 'gold';
        if (str_contains($v, 'bronz') || $v === 'bronze' || $v === 'copper' || $v === 'cu') return 'bronze';
        if ($v === 'electrum' || $v === 'elektrum') return 'electrum';
        if ($v === 'lead' || $v === 'kursun') return 'lead';
        if ($v === 'iron' || $v === 'demir') return 'iron';
        
        return $v;
    }
    
    /**
     * Malzeme varyantlarını döndürür
     * Veritabanında bu malzemenin hangi şekillerde yazılabileceğini belirtir
     * 
     * @param string $norm Normalize edilmiş malzeme key
     * @return array Malzeme varyantları
     */
    public function dbMaterialVariantsFor(string $norm): array
    {
        return $this->config['MATERIAL_VARIANTS'][$norm] ?? [];
    }
}