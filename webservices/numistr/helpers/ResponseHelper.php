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
     * @param bool  $noStore true = Cache-Control: no-store (per-user / POST answers, e.g. assistant chat)
     */
    public function sendJson($payload, bool $noStore = false): void
    {
        $app = Factory::getApplication();
        
        // CORS Headers - Tüm origin'lere izin ver
        $app->setHeader('Access-Control-Allow-Origin', '*', true);
        $app->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS', true);
        $app->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization', true);
        
        // Content type
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        
        // Cache control - 30 saniye cache (noStore ile kapatilabilir)
        $app->setHeader('Cache-Control', $noStore ? 'no-store' : 'public, max-age=30, stale-while-revalidate=30', true);

        // 2026-09-08: bu sunucuda serialize_precision varsayilan (-1) DEGIL; float'lar
        // tam ondalik acilimiyla basiliyordu:
        //   round(40.006566, 6) -> "40.00656599999999940564521239139139652252197265625"
        // Olcum: /v1/locations tam Anadolu yanitinin ~%31'i sirf bu sismeydi.
        // -1 = en kisa gidip-gelen (round-trip) gosterim; PHP 7.1+ varsayilani ve
        // deger kaybi YOK. Yalnizca bu kodlama icin ayarlanip eski deger geri konur,
        // boylece Joomla'nin geri kalanina dokunulmaz.
        $sp = @ini_get('serialize_precision');

        if ($sp !== false && (string) $sp !== '-1') {
            @ini_set('serialize_precision', '-1');
        }

        // ETag/If-None-Match - Değişmeyen içerik için 304 döndür
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($sp !== false && (string) $sp !== '-1') {
            @ini_set('serialize_precision', (string) $sp);
        }
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
     * @param array $extra errors[0]'a eklenecek alanlar (1.19.0): 'error_code' (makine-okunur,
     *                     ör. EMAIL_NOT_VERIFIED), 'retry_after' (sn; Retry-After başlığı da yazılır)
     */
    public function sendError(int $code, string $title, string $detail = null, array $extra = []): void
    {
        $app = Factory::getApplication();

        if (!empty($extra['retry_after'])) {
            $app->setHeader('Retry-After', (string) (int) $extra['retry_after'], true);
        }
        
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
        
        $json = json_encode(self::errorPayload($code, $title, $detail, $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($json) . '"';
        $app->setHeader('ETag', $etag, true);
        
        echo $json;
        $app->close();
    }

    /**
     * Hata gövdesi (saf; test edilebilir). Eski alanlar (title, code=HTTP, detail) aynen kalır;
     * ek alanlar yalnız verildiğinde eklenir — mevcut istemciler etkilenmez.
     */
    public static function errorPayload(int $code, string $title, string $detail = null, array $extra = []): array
    {
        $err = ['title' => $title, 'code' => $code];

        if ($detail) {
            $err['detail'] = $detail;
        }

        if (!empty($extra['error_code'])) {
            $err['error_code'] = (string) $extra['error_code'];
        }

        if (!empty($extra['retry_after'])) {
            $err['retry_after'] = (int) $extra['retry_after'];
        }

        return ['errors' => [$err]];
    }
}