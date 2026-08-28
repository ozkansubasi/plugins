<?php
defined('_JEXEC') or die;

/**
 * NumisTR API Constants
 * Tüm sabitler ve yapılandırma değerleri
 *
 * Gizli değerler (webhook secret vb.) bu dosyada DEĞİL, yanındaki
 * secrets.php dosyasında tutulur (git'e girmez, sunucuda oluşturulur).
 * Örnek şablon: config/secrets.example.php
 */
$secrets = [];

if (file_exists(__DIR__ . '/secrets.php')) {
    $loaded = include __DIR__ . '/secrets.php';

    if (is_array($loaded)) {
        $secrets = $loaded;
    }
}

return [
    // ================= Auth0 JWT doğrulama =================
    // Mobil uygulama Authorization: Bearer <ID token> gönderir.
    // ID token'ın aud claim'i Auth0 uygulamasının Client ID'sidir.
    'AUTH0' => [
        // Kabul edilen issuer'lar (Auth0 tenant URL'leri; sondaki / opsiyonel)
        'issuers' => [
            'https://dev-ja5k8sumb7005j4n.us.auth0.com/',
        ],

        // Kabul edilen audience'lar (Auth0 Application Client ID'leri)
        'audiences' => [
            '5AFSce7JEdmyxBrwjwEI7IcnRnvXKF8c',
        ],

        // İzin verilen imza algoritmaları (alg=none / HS256 downgrade'e karşı beyaz liste)
        'algorithms' => ['RS256'],

        // 'enforce'  = imza geçersizse 401 (ÜRETİM AYARI)
        // 'log_only' = sadece logla, eski imzasız davranışı sürdür (kademeli geçiş için)
        'mode' => 'enforce',

        // Mevcut bir Joomla hesabına e-postayla bağlanmak için email_verified şartı
        'require_email_verified' => true,

        // Saat kayması toleransı (saniye)
        'leeway' => 60,

        // JWKS cache süresi (saniye) ve indirme timeout'u
        'jwks_ttl'     => 43200,
        'jwks_timeout' => 8,
    ],

    // ================= RevenueCat webhook =================
    'REVENUECAT' => [
        // RevenueCat panelindeki Authorization header değeriyle birebir aynı olmalı.
        // Değer config/secrets.php içinde tutulur; boşsa endpoint 503 döner (kapalı).
        'webhook_secret' => $secrets['revenuecat_webhook_secret'] ?? '',

        // SANDBOX (test) satın alımları da Pro grubunu versin mi?
        // License tester'larla test ederken true olmalı.
        'allow_sandbox' => true,

        // Pro hakkı veren event türleri
        'grant_events' => [
            'INITIAL_PURCHASE',
            'RENEWAL',
            'UNCANCELLATION',
            'PRODUCT_CHANGE',
            'SUBSCRIPTION_EXTENDED',
            'NON_RENEWING_PURCHASE',
            'TRANSFER',
        ],

        // Pro hakkını kaldıran event türleri
        // NOT: CANCELLATION burada YOK — kullanıcı otomatik yenilemeyi kapattığında
        // dönem sonuna kadar Pro kalır; iptal iadeye dönmüşse cancel_reason'a bakılır.
        'revoke_events' => [
            'EXPIRATION',
            'SUBSCRIPTION_PAUSED',
        ],

        // Bu iptal sebepleri (iade/destek kaynaklı) anında hak kaybı sayılır
        'revoke_cancel_reasons' => [
            'CUSTOMER_SUPPORT',
            'BILLING_ERROR',
        ],
    ],

    // Hata yanıtlarında ayrıntılı istisna mesajı gösterilsin mi?
    // ⚠️ Canlıda DAİMA false: true olursa SQL/dosya yolu gibi iç detaylar API'ye sızar.
    // (2026-08-28: anahtar hiç tanımlı değildi → 4 catch bloğunda "Undefined array key"
    //  uyarısı basılıyor, header'lar gönderildiği için HTTP durum kodu da ayarlanamıyordu.)
    'DEBUG_MODE' => false,

    // "Sikkeler" kök kategori id
    'ROOT_CAT_ID' => 16,
    
    // Geniş sorgularda sonuç seti üst sınırı
    'SAFE_CAP' => 2000,
    
    // Pro üyelik grup ID'si (Joomla User Groups'tan)
    'PRO_GROUP_ID' => 10,

    // Üniversite öğrenci grup ID'si (ücretsiz Pro erişimi)
    // Joomla Admin > Users > Groups'ta oluşturulan "Universite Ogrencileri" grubunun ID'si
    // NOT: Grubu oluşturduktan sonra bu değeri güncelleyin!
    'UNIVERSITY_GROUP_ID' => null, // Grup oluşturulduktan sonra ID'yi buraya yazın (örn: 11)
    
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

    // AI Service Settings
    'AI_SERVICE' => [
        'url' => 'https://ai.numistr.org',
        'timeout' => 30,           // Request timeout (saniye)
        'verify_ssl' => true,      // SSL sertifika doğrulama
    ],

    // Scan Quota Settings (Admin panel'den değiştirilebilir)
    'QUOTA' => [
        'free_limit' => 10,        // Free tier aylık limit
        'pro_limit' => -1,         // Pro tier (-1 = unlimited)
        'reset_day' => 1,          // Ayın kaçında reset (1 = ayın ilk günü)

        // Adil kullanım tavanı — "sınırsız" Pro'nun açık ucunu kapatır.
        // Tüm kademelere uygulanır (ücretsiz kademe zaten aylık 10 ile sınırlı;
        // dakika/saat penceresi orada da otomatik betikleri durdurur).
        // Bir pencereyi kapatmak için 0 yaz.
        //   minute → betik/burst koruması (insan bu hızda fotoğraf çekemez)
        //   hour   → yoğun kataloglama seansına yer bırakır
        //   day    → hesap paylaşımının ekonomisini durdurur
        'rate_limits' => [
            'per_minute' => 6,
            'per_hour'   => 60,
            'per_day'    => 100,
        ],
    ],
];