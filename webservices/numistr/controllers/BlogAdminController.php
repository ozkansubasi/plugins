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
 * Dönemsel içerik hattı — blog yazma ucu
 *
 *   POST /v1/admin/blog/article   {dry_run, title, alias, introtext, fulltext, catid, state, language?, metadesc?, tags?}
 *
 * Kimlik doğrulama: Joomla oturumu/Bearer DEĞİL; config/secrets.php 'blog_admin_secret' ile HMAC-SHA256.
 * Başlıklar: X-Numistr-Ts (unix sn), X-Numistr-Sig (hex). İmzalanan dizge: NumisTRBlogAdmin::payload().
 * Sır boş/kısaysa uç 503 (kapalı). dry_run AÇIKÇA false verilmedikçe hiçbir şey yazılmaz: kuru koşu
 * doğrulamayı, (kategori, alias) eşleşmesini ve etiket varlığını raporlar.
 *
 * @since 1.23.0
 */
class BlogAdminController
{
    public static function handle(string $action): void
    {
        $response = new NumisTRResponseHelper();

        $configPath = __DIR__ . '/../config/constants.php';
        $config     = file_exists($configPath) ? include $configPath : [];
        $cfg        = $config['BLOG_ADMIN'] ?? [];
        $secret     = (string) ($cfg['secret'] ?? '');

        if (strlen($secret) < NumisTRBlogAdmin::MIN_SECRET_LEN) {
            $response->sendError(503, 'Service Unavailable', 'Blog admin is not configured');
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            $response->sendError(405, 'Method Not Allowed', 'Use POST');
            return;
        }

        $raw = (string) file_get_contents('php://input');
        $ts  = (int) ($_SERVER['HTTP_X_NUMISTR_TS'] ?? 0);
        $sig = trim((string) ($_SERVER['HTTP_X_NUMISTR_SIG'] ?? ''));

        if (!NumisTRBlogAdmin::verify($method, $action, $ts, $raw, $sig, $secret, (int) ($cfg['max_skew'] ?? 300))) {
            $response->sendError(401, 'Unauthorized', 'Invalid signature');
            return;
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $response->sendError(400, 'Bad Request', 'JSON body required');
            return;
        }

        [$d, $gecersiz] = NumisTRBlogAdmin::normalize($body, (array) ($cfg['allowed_catids'] ?? []));
        if ($gecersiz !== null) {
            $response->sendError(400, 'Bad Request', $gecersiz);
            return;
        }

        try {
            $db     = Factory::getDbo();
            $mevcut = NumisTRBlogAdmin::mevcut($db, $d['catid'], $d['alias']);
            $eksik  = NumisTRBlogAdmin::eksikEtiketler($db, $d['tags']);
            if ($eksik) {
                $response->sendError(400, 'Bad Request', 'yayimda olmayan etiket: ' . implode(',', $eksik));
                return;
            }

            if ($d['es_id'] !== null && ($esHata = NumisTRBlogAdmin::esDogrula($db, $d['es_id'])) !== null) {
                $response->sendError(400, 'Bad Request', $esHata);
                return;
            }

            // Güvenli varsayılan: yalnız dry_run === false gerçek yazım yapar.
            $dryRun = !(array_key_exists('dry_run', $body) && $body['dry_run'] === false);
            if ($dryRun) {
                $response->sendJson(['data' => ['dry_run' => true, 'islem' => $mevcut ? 'guncellenecek' : 'olusturulacak',
                    'id' => $mevcut ?: null, 'alias' => $d['alias'], 'catid' => $d['catid'], 'state' => $d['state'],
                    'etiket' => count($d['tags']), 'bayt' => strlen($d['introtext']) + strlen($d['fulltext'])]], true);
                return;
            }

            [$id, $islem] = NumisTRBlogAdmin::kaydet($d, $mevcut, (int) ($cfg['created_by'] ?? 0));
            $iliski = $d['es_id'] !== null ? NumisTRBlogAdmin::iliskilendir($db, $d['es_id'], $id) : null;
            $response->sendJson(['data' => ['dry_run' => false, 'islem' => $islem, 'id' => $id, 'alias' => $d['alias'],
                'catid' => $d['catid'], 'state' => $d['state'], 'etiket' => count($d['tags']), 'iliski' => $iliski]], true);
        } catch (\Throwable $e) {
            // Çağıran HMAC ile doğrulanmış yönetici: hata metni gizlenmez (sessiz varsayılan hatayı maskeler dersi).
            $response->sendServerError($e);
        }
    }
}
