<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * NumisTR Rate Limiter
 * API kullanım sınırlaması ve kötüye kullanım önleme
 */
class NumisTRRateLimiter
{
    private $config;
    private $cacheLifetime = 60; // 1 dakika
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Onbellek denetleyicisi.
     *
     * 🔴 2026-08-28'de bulunan hata: burada `Factory::getCache('numistr_api', 'file')`
     * cagriliyordu. Joomla'da ikinci parametre DEPOLAMA degil DENETLEYICI TIPIdir
     * (callback|output|page|view); 'file' diye bir denetleyici yoktur. Cagri istisna
     * atiyor, cagiranlardaki try/catch fail-open donuyordu — yani bu eklentideki
     * TUM hiz sinirlari (stats, ticker, recognition, university-application,
     * locations) 2025'ten beri hic uygulanmiyordu. Canlida olculdu: /v1/stats
     * 20/dk sinirina ragmen 25 istegin tamamini 200 ile karsiladi.
     *
     * Ayrica `caching => true` veriliyor: Joomla'nin genel "System Cache" ayari
     * kapaliysa Factory::getCache() sessizce devre disi bir denetleyici doner ve
     * sinir yine calismaz. Hiz siniri, sitenin onbellek tercihinden bagimsiz olmali.
     */
    private function cacheController(string $group)
    {
        $options = array(
            'defaultgroup' => $group,
            'caching'      => true,
            'lifetime'     => 1440, // dk; asil pencere kontrolu degerin icindeki damgada
        );

        try {
            return Factory::getContainer()
                ->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)
                ->createCacheController('output', $options);
        } catch (\Throwable $e) {
            // Eski surum / container yoksa: gecerli bir denetleyici tipiyle dene.
            $cache = Factory::getCache($group, 'output');

            if (method_exists($cache, 'setCaching')) {
                $cache->setCaching(true);
            }

            return $cache;
        }
    }

    /**
     * Pencere damgali sayac. Degerin ICINDE pencere kimligi tutulur; boylece
     * Joomla onbellek lifetime biriminin (saniye mi dakika mi) surume gore
     * degismesi sayaci bozmaz — pencere degisince sayac sifirlanir.
     *
     * @param   string  $group   Onbellek grubu
     * @param   string  $key     Sayac anahtari
     * @param   string  $window  Pencere kimligi (ornegin dakika no ya da tarih)
     * @param   int     $max     Tavan (0 veya eksi = kapali)
     *
     * @return  bool  Istek yapilabilir mi?
     */
    private function bumpCounter(string $group, string $key, string $window, int $max): bool
    {
        if ($max <= 0) {
            return true;
        }

        try {
            $cache = $this->cacheController($group);
            $row   = $cache->get($key);

            if (!is_array($row) || !isset($row['w'], $row['c']) || $row['w'] !== $window) {
                $cache->store(array('w' => $window, 'c' => 1), $key);

                return true;
            }

            if ((int) $row['c'] >= $max) {
                return false;
            }

            $cache->store(array('w' => $window, 'c' => (int) $row['c'] + 1), $key);

            return true;
        } catch (\Throwable $e) {
            // Onbellek gercekten kullanilamiyorsa istegi kesme (fail-open), ama
            // sessiz kalma: bu durumda koruma yok demektir.
            $this->logIssue('sayac yazilamadi (' . $group . '): ' . $e->getMessage());

            return true;
        }
    }

    private function logIssue(string $message): void
    {
        try {
            \Joomla\CMS\Log\Log::addLogger(array('text_file' => 'numistr_ratelimit.php'), \Joomla\CMS\Log\Log::ALL, array('numistr-rate'));
            \Joomla\CMS\Log\Log::add($message, \Joomla\CMS\Log\Log::WARNING, 'numistr-rate');
        } catch (\Throwable $e) {
            // yoksay
        }
    }

    /**
     * Rate limit kontrolü (dakika penceresi)
     *
     * @param string $endpoint Endpoint adı
     * @param int $maxRequests Dakikada maksimum istek
     * @return bool İstek yapılabilir mi?
     */
    public function checkLimit(string $endpoint, int $maxRequests = 60): bool
    {
        $ip  = $this->getClientIP();
        $key = 'rate_limit_' . md5($ip . '_' . $endpoint);

        return $this->bumpCounter('numistr_api', $key, 'm' . floor(time() / 60), $maxRequests);
    }

    /**
     * Hesap/istemci basina GUNLUK istek tavani — toplu kazimaya karsi.
     *
     * Dakika bazli sinir ani yuku dengeler ama sabirli bir kaziyiciyi durdurmaz:
     * dakikada 60 istekle gunde 86.400 istek yapilabilir. Katalog ~10.000 sikke ve
     * ~11.000 gorselden olusuyor; asil varlik bu (ADR-005). Gunluk tavan, normal
     * uygulama kullaniminin cok ustunde ama toptan kopyalamanin cok altinda durur.
     *
     * @param   string  $subject     Kimlik anahtari (kullanici jetonu ya da IP)
     * @param   int     $maxPerDay   0 veya eksi = tavan kapali
     *
     * @return  bool  Istek yapilabilir mi?
     */
    public function checkDailyLimit(string $subject, int $maxPerDay): bool
    {
        if ($subject === '') {
            return true;
        }

        return $this->bumpCounter('numistr_api_daily', 'daily_' . md5($subject), gmdate('Y-m-d'), $maxPerDay);
    }

    /**
     * Gunluk sayacin bugunku degeri (teshis/gozlem icin).
     */
    public function getDailyCount(string $subject): int
    {
        if ($subject === '') {
            return 0;
        }

        try {
            $row = $this->cacheController('numistr_api_daily')->get('daily_' . md5($subject));

            if (is_array($row) && isset($row['w'], $row['c']) && $row['w'] === gmdate('Y-m-d')) {
                return (int) $row['c'];
            }
        } catch (\Throwable $e) {
            // yoksay
        }

        return 0;
    }

    /**
     * Kalan istek sayısını döndür
     */
    public function getRemainingRequests(string $endpoint, int $maxRequests = 60): int
    {
        $ip = $this->getClientIP();
        $key = 'rate_limit_' . md5($ip . '_' . $endpoint);

        try {
            $row = $this->cacheController('numistr_api')->get($key);

            if (!is_array($row) || !isset($row['w'], $row['c']) || $row['w'] !== 'm' . floor(time() / 60)) {
                return $maxRequests;
            }

            return max(0, $maxRequests - (int) $row['c']);
        } catch (\Throwable $e) {
            return $maxRequests;
        }
    }
    
    /**
     * Query complexity skoru hesapla
     * Karmaşık sorgular için daha düşük limit
     * 
     * @param array $filters Filtreler
     * @return int Complexity skoru (1-10)
     */
    public function calculateQueryComplexity(array $filters): int
    {
        $complexity = 1;
        
        // Filtre sayısı
        $filterCount = 0;
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $filterCount++;
            }
        }
        
        // Her filtre +1 complexity
        $complexity += $filterCount;
        
        // Wildcard araması +2 complexity
        if (isset($filters['mint']) && (strpos($filters['mint'], '%') !== false)) {
            $complexity += 2;
        }
        
        // Yıl aralığı varsa +1
        if (isset($filters['year_from']) || isset($filters['year_to'])) {
            $complexity += 1;
        }
        
        return min(10, $complexity);
    }
    
    /**
     * Complexity'e göre rate limit hesapla
     */
    public function getComplexityBasedLimit(int $complexity): int
    {
        // Basit sorgular: 60/dakika
        // Karmaşık sorgular: 10/dakika
        return (int)max(10, 60 - ($complexity * 5));
    }
    
    /**
     * Client IP adresini al
     */
    private function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For birden fazla IP içerebilir
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                return $ip;
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Query timeout sınırı hesapla
     */
    public function getQueryTimeout(int $complexity): int
    {
        // Basit: 5 saniye, Karmaşık: 30 saniye
        return min(30, 5 + ($complexity * 2));
    }
}