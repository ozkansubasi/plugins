<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * NumisTR Response Helper
 * JSON response ve HTTP header işlemleri
 */
class NumisTRResponseHelper
{
    /**
     * JSON response gönderir (başarılı)
     * 
     * @param array $payload Response data
     */
    public function sendJson($payload): void
    {
        $app = Factory::getApplication();
        
        // CORS Headers - Tüm origin'lere izin ver
        $app->setHeader('Access-Control-Allow-Origin', '*', true);
        $app->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS', true);
        $app->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization', true);
        
        // Content type
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        
        // Cache control - 30 saniye cache
        $app->setHeader('Cache-Control', 'public, max-age=30, stale-while-revalidate=30', true);

        // ETag/If-None-Match - Değişmeyen içerik için 304 döndür
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($json) . '"';
        $app->setHeader('ETag', $etag, true);
        
        $ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNone !== '' && trim($ifNone) === $etag) {
            http_response_code(304);
            echo '';
            $app->close();
        }

        // Response gönder
        http_response_code(200);
        echo $json;
        $app->close();
    }
    
    /**
     * Error response gönderir
     * 
     * @param int $code HTTP status code
     * @param string $title Error başlığı
     * @param string|null $detail Detaylı açıklama (opsiyonel)
     */
    public function sendError(int $code, string $title, string $detail = null): void
    {
        $app = Factory::getApplication();
        
        // CORS Headers
        $app->setHeader('Access-Control-Allow-Origin', '*', true);
        $app->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS', true);
        $app->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization', true);
        
        // Content type
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        
        // Cache control - Hataları cache'leme
        $app->setHeader('Cache-Control', 'no-store', true);
        
        // HTTP status code
        http_response_code($code);
        
        // Error payload oluştur
        $err = ['errors' => [['title' => $title, 'code' => $code]]];
        if ($detail) {
            $err['errors'][0]['detail'] = $detail;
        }
        
        $json = json_encode($err, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($json) . '"';
        $app->setHeader('ETag', $etag, true);
        
        echo $json;
        $app->close();
    }
}