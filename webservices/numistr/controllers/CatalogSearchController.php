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
 * GET /v1/variants/motif-count?q=başak,buğday[&lang=tr|en]
 *
 * Katalog betimlerinde motif sayımı: tip sayısı, darphane dağılımı, tarih aralığı, örnek kimlikler.
 * İç kullanım (n8n /KB fizibilite kapısı + içerik hattı). Kimlik: dışa aktarma ucuyla aynı
 * X-NumisTR-KB başlığı (config/secrets.php KB_WEBHOOK_SECRET); sır yoksa 503.
 *
 * @since 1.15.0
 */
class CatalogSearchController
{
    public static function motifCount(): void
    {
        $response = new NumisTRResponseHelper();
        $base = dirname(__DIR__);
        $secrets = file_exists($base . '/config/secrets.php') ? include $base . '/config/secrets.php' : [];
        $config = include $base . '/config/constants.php';

        $secret = trim((string) (is_array($secrets) ? ($secrets['KB_WEBHOOK_SECRET'] ?? '') : ''));
        if ($secret === '') {
            $response->sendError(503, 'Service Unavailable', 'Motif search is not configured');
            return;
        }
        $given = trim((string) ($_SERVER['HTTP_X_NUMISTR_KB'] ?? ''));
        if ($given === '' || !hash_equals($secret, $given)) {
            $response->sendError(401, 'Unauthorized', 'X-NumisTR-KB header required');
            return;
        }
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $response->sendError(405, 'Method Not Allowed', 'Use GET');
            return;
        }

        $terms = NumisTRMotifSearch::parseTerms((string) ($_GET['q'] ?? ''));
        if (!$terms) {
            $response->sendError(400, 'Bad Request', 'q: comma-separated terms, each at least 2 letters');
            return;
        }
        $lang = (($_GET['lang'] ?? 'tr') === 'en') ? 'en' : 'tr';
        $fid = $config['FIELD_ID'];
        $fieldIds = [
            'mint' => $fid['mint_name'], 'start' => $fid['start_date'], 'end' => $fid['end_date'],
            'obv' => $lang === 'en' ? $fid['obverse_desc'] : $fid['obverse_desc_tr'],
            'rev' => $lang === 'en' ? $fid['reverse_desc'] : $fid['reverse_desc_tr'],
        ];

        try {
            $r = NumisTRMotifSearch::search(Factory::getDbo(), $terms, $fieldIds);
            $response->sendJson(['data' => $r, 'meta' => ['q' => $terms, 'lang' => $lang,
                'match' => 'word-start (Türkçe ekler serbest)']], true);
        } catch (\Throwable $e) {
            $response->sendError(500, 'Internal server error', $e->getMessage());
        }
    }
}
