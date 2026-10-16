<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2026 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * AI dizini eşitlemesi (AI9) — yönetici ucu
 *
 *   GET /v1/admin/ai/catalog    katalog dökümü (salt okunur; biçim: NumisTRAiCatalog)
 *
 * Kimlik doğrulama: Joomla oturumu/Bearer DEĞİL; config/secrets.php 'ai_sync_secret'
 * ile HMAC-SHA256. Başlıklar: X-Numistr-Ts (unix sn), X-Numistr-Sig (hex).
 * İmzalanan dizge: NumisTRAiCatalog::payload(). Sır boş/kısaysa uç 503 (kapalı).
 *
 * @since 1.22.0
 */
class AiSyncController
{
    public static function handle(string $action): void
    {
        $response = new NumisTRResponseHelper();

        $configPath = __DIR__ . '/../config/constants.php';
        $config     = file_exists($configPath) ? include $configPath : [];
        $cfg        = $config['AI_SYNC'] ?? [];
        $secret     = (string) ($cfg['secret'] ?? '');

        if (strlen($secret) < NumisTRAiCatalog::MIN_SECRET_LEN) {
            $response->sendError(503, 'Service Unavailable', 'AI sync is not configured');
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET') {
            $response->sendError(405, 'Method Not Allowed', 'Use GET');
            return;
        }

        $ts  = (int) ($_SERVER['HTTP_X_NUMISTR_TS'] ?? 0);
        $sig = trim((string) ($_SERVER['HTTP_X_NUMISTR_SIG'] ?? ''));

        if (!NumisTRAiCatalog::verify($method, $action, $ts, '', $sig, $secret, (int) ($cfg['max_skew'] ?? 300))) {
            $response->sendError(401, 'Unauthorized', 'Invalid signature');
            return;
        }

        try {
            $dump = NumisTRAiCatalog::dump(Factory::getDbo());
            $response->sendJson(['data' => $dump, 'meta' => [
                'gorsel' => count($dump['gorseller']), 'makale' => count($dump['makaleler']), 'erisilemez' => count($dump['erisilemez']),
            ]], true);
        } catch (\Throwable $e) {
            // Çağıran HMAC ile doğrulanmış yönetici: hata metni gizlenmez (sessiz varsayılan hatayı maskeler dersi).
            $response->sendServerError($e);
        }
    }
}
