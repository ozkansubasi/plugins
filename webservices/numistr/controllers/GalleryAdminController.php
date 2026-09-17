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
 * Görsel tamamlama hattı — yönetici uçları
 *
 *   GET  /v1/admin/gallery/targets    görselsiz makaleler (klasör + coin_uri ile)
 *   POST /v1/admin/gallery/publish    {run_id, dry_run, articles:[{article_id, items:[…]}]}
 *   POST /v1/admin/gallery/rollback   {run_id, delete_files}
 *
 * Kimlik doğrulama: Joomla oturumu/Bearer DEĞİL; config/secrets.php 'gallery_admin_secret'
 * ile HMAC-SHA256. Başlıklar: X-Numistr-Ts (unix sn), X-Numistr-Sig (hex).
 * İmzalanan dizge: NumisTRGalleryAdmin::payload(). Sır boş/kısaysa uç 503 (kapalı).
 * dry_run AÇIKÇA false verilmedikçe hiçbir şey yazılmaz.
 *
 * @since 1.13.0
 */
class GalleryAdminController
{
    public static function handle(string $action): void
    {
        $response = new NumisTRResponseHelper();

        $configPath = __DIR__ . '/../config/constants.php';
        $config     = file_exists($configPath) ? include $configPath : [];
        $cfg        = $config['GALLERY_ADMIN'] ?? [];
        $secret     = (string) ($cfg['secret'] ?? '');

        if (strlen($secret) < NumisTRGalleryAdmin::MIN_SECRET_LEN) {
            $response->sendError(503, 'Service Unavailable', 'Gallery admin is not configured');
            return;
        }

        $method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $expected = $action === 'targets' ? 'GET' : 'POST';
        if ($method !== $expected) {
            $response->sendError(405, 'Method Not Allowed', 'Use ' . $expected);
            return;
        }

        $raw = $method === 'POST' ? (string) file_get_contents('php://input') : '';
        $ts  = (int) ($_SERVER['HTTP_X_NUMISTR_TS'] ?? 0);
        $sig = trim((string) ($_SERVER['HTTP_X_NUMISTR_SIG'] ?? ''));

        if (!NumisTRGalleryAdmin::verify($method, $action, $ts, $raw, $sig, $secret, (int) ($cfg['max_skew'] ?? 300))) {
            $response->sendError(401, 'Unauthorized', 'Invalid signature');
            return;
        }

        try {
            $sikkeRoot = realpath(JPATH_ROOT . '/../sikke');
            if (!$sikkeRoot || !is_dir($sikkeRoot)) {
                $response->sendError(500, 'Internal server error', 'sikke directory not found');
                return;
            }

            $admin = new NumisTRGalleryAdmin(Factory::getDbo(), $sikkeRoot, (int) ($config['ROOT_CAT_ID'] ?? 16));

            if ($action === 'targets') {
                $rows = $admin->targets();
                $response->sendJson(['data' => $rows, 'meta' => ['count' => count($rows)]], true);
                return;
            }

            $body  = json_decode($raw, true);
            $runId = is_array($body) ? (string) ($body['run_id'] ?? '') : '';
            if (!preg_match(NumisTRGalleryAdmin::RUN_RE, $runId)) {
                $response->sendError(400, 'Bad Request', 'run_id missing or malformed');
                return;
            }

            if ($action === 'rollback') {
                $results = $admin->rollback($runId, !empty($body['delete_files']));
                $response->sendJson(['data' => ['run_id' => $runId, 'results' => $results]], true);
                return;
            }

            $articles = $body['articles'] ?? null;
            $max      = (int) ($cfg['max_articles'] ?? 50);
            if (!is_array($articles) || !$articles || count($articles) > $max) {
                $response->sendError(400, 'Bad Request', 'articles must be a non-empty array (max ' . $max . ')');
                return;
            }

            // Güvenli varsayılan: yalnız dry_run === false gerçek yazım yapar.
            $dryRun  = !(array_key_exists('dry_run', $body) && $body['dry_run'] === false);
            $results = $admin->publish($runId, $articles, $dryRun);

            $counts = [];
            foreach ($results as $r) {
                $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
            }
            $response->sendJson(['data' => ['run_id' => $runId, 'dry_run' => $dryRun, 'counts' => $counts, 'results' => $results]], true);
        } catch (\Throwable $e) {
            // Çağıran HMAC ile doğrulanmış yönetici: hata metni gizlenmez (sessiz varsayılan hatayı maskeler dersi).
            $response->sendError(500, 'Internal server error', $e->getMessage());
        }
    }
}
