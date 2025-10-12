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
     * Rate limit kontrolü
     * 
     * @param string $endpoint Endpoint adı
     * @param int $maxRequests Dakikada maksimum istek
     * @return bool İstek yapılabilir mi?
     */
    public function checkLimit(string $endpoint, int $maxRequests = 60): bool
    {
        $ip = $this->getClientIP();
        $key = 'rate_limit_' . md5($ip . '_' . $endpoint);
        
        $cache = Factory::getCache('numistr_api', 'callback');
        $cache->setLifeTime($this->cacheLifetime);
        
        try {
            $count = $cache->get($key);
            
            if ($count === false) {
                // İlk istek
                $cache->store(1, $key);
                return true;
            }
            
            if ($count >= $maxRequests) {
                return false;
            }
            
            // Sayacı artır
            $cache->store($count + 1, $key);
            return true;
            
        } catch (\Exception $e) {
            // Cache hatası varsa izin ver (fail-open)
            return true;
        }
    }
    
    /**
     * Kalan istek sayısını döndür
     */
    public function getRemainingRequests(string $endpoint, int $maxRequests = 60): int
    {
        $ip = $this->getClientIP();
        $key = 'rate_limit_' . md5($ip . '_' . $endpoint);
        
        $cache = Factory::getCache('numistr_api', 'callback');
        $count = $cache->get($key);
        
        if ($count === false) {
            return $maxRequests;
        }
        
        return max(0, $maxRequests - $count);
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