<?php
defined('_JEXEC') or die;

/**
 * NumisTR API Constants
 * Tüm sabitler ve yapılandırma değerleri
 */
return [
    // "Sikkeler" kök kategori id
    'ROOT_CAT_ID' => 16,
    
    // Geniş sorgularda sonuç seti üst sınırı
    'SAFE_CAP' => 2000,
    
    // Pro üyelik grup ID'si (Joomla User Groups'tan)
    'PRO_GROUP_ID' => 9,
    
    // Custom field ID'leri
    'FIELD_ID' => [
        // Filtrede kullanılanlar
        'material'            => 23,
        'mint_name'           => 4,
        'authority_name'      => 2,
        
        // Diğer alanlar
        'coin_id'             => 27,
        'authority_uri'       => 3,
        'mint_uri'            => 5,
        'denomination_name'   => 6,
        'denomination_uri'    => 7,
        'obverse_desc'        => 30,
        'obverse_desc_tr'     => 32,
        'reverse_desc'        => 31,
        'reverse_desc_tr'     => 33,
        'findspot_name'       => 12,
        'findspot_uri'        => 13,
        'coordinates'         => 15,
        'start_date'          => 25,
        'end_date'            => 26,
        'source_citation'     => 18,
        'image_gallery_urls'  => 35,
    ],
    
    // Malzeme kısa kodları
    'MATERIAL_MAP' => [
        'ae' => 'bronze',
        'ar' => 'silver',
        'av' => 'gold',
        'el' => 'electrum',
        'cu' => 'copper',
        'pb' => 'lead',
        'fe' => 'iron',
    ],
    
    // Malzeme varyantları (veritabanında farklı şekillerde yazılabilir)
    'MATERIAL_VARIANTS' => [
        'bronze'   => ['bronze', 'copper', 'cu', 'bronz', 'ae'],
        'electrum' => ['electrum', 'elektrum', 'el'],
        'gold'     => ['gold', 'altın', 'altin', 'av', 'au'],
        'iron'     => ['iron', 'demir', 'fe'],
        'lead'     => ['lead', 'kurşun', 'kursun', 'pb'],
        'silver'   => ['silver', 'gümüş', 'gumus', 'ar'],
    ],
    
    // Malzeme listesi (API response için)
    'MATERIALS_LIST' => [
        ['code' => 'bronze', 'name' => 'Bronze', 'name_tr' => 'Bronz'],
        ['code' => 'silver', 'name' => 'Silver', 'name_tr' => 'Gümüş'],
        ['code' => 'gold', 'name' => 'Gold', 'name_tr' => 'Altın'],
        ['code' => 'electrum', 'name' => 'Electrum', 'name_tr' => 'Elektrum'],
        ['code' => 'copper', 'name' => 'Copper', 'name_tr' => 'Bakır'],
        ['code' => 'lead', 'name' => 'Lead', 'name_tr' => 'Kurşun'],
        ['code' => 'iron', 'name' => 'Iron', 'name_tr' => 'Demir'],
    ],
    
    // Rate Limiting (Dakika başına istek limitleri)
    'RATE_LIMITS' => [
        'default' => 60,           // Basit endpoint'ler
        'search' => 30,            // Arama endpoint'leri
        'complex_query' => 10,     // Karmaşık sorgular
        'stats' => 20,             // İstatistik endpoint'i
        'facets' => 15,            // Facet endpoint'i
    ],
    
    // Query Performance Limits
    'QUERY_LIMITS' => [
        'max_per_page' => 100,         // Sayfa başına maksimum kayıt
        'max_total_without_filter' => 1000,  // Filtresiz maksimum sonuç
        'slow_query_threshold' => 2.0,  // Yavaş sorgu eşiği (saniye)
        'max_query_time' => 30,         // Maksimum sorgu süresi (saniye)
    ],
    
    // Cache Settings
    'CACHE' => [
        'enabled' => true,
        'ttl_stats' => 300,        // Stats 5 dakika cache
        'ttl_regions' => 3600,     // Regions 1 saat cache
        'ttl_materials' => 3600,   // Materials 1 saat cache
        'ttl_variants' => 60,      // Variants 1 dakika cache
        'ttl_facets' => 120,       // Facets 2 dakika cache
    ],
];