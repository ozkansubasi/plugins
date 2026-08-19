<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

// Helper sınıflarını yükle (temel)
require_once __DIR__ . '/helpers/AuthHelper.php';

// JWT doğrulayıcı (Auth0 imza kontrolü) — yoksa AuthHelper log_only'ye düşer
if (file_exists(__DIR__ . '/helpers/JwtHelper.php')) {
    require_once __DIR__ . '/helpers/JwtHelper.php';
}

require_once __DIR__ . '/helpers/DatabaseHelper.php';
require_once __DIR__ . '/helpers/ResponseHelper.php';
require_once __DIR__ . '/helpers/RateLimiter.php';
require_once __DIR__ . '/helpers/TickerHelper.php';

// Locations helper (optional - locations feature not fully deployed yet)
if (file_exists(__DIR__ . '/helpers/LocationsHelper.php')) {
    require_once __DIR__ . '/helpers/LocationsHelper.php';
}

// ---- Recognition helpers (optional - loaded on demand) ----
// Load only if files exist
if (file_exists(__DIR__ . '/helpers/QuotaHelper.php')) {
    require_once __DIR__ . '/helpers/QuotaHelper.php';
}
if (file_exists(__DIR__ . '/helpers/AiServiceHelper.php')) {
    require_once __DIR__ . '/helpers/AiServiceHelper.php';
}
if (file_exists(__DIR__ . '/controllers/RecognitionController.php')) {
    require_once __DIR__ . '/controllers/RecognitionController.php';
}
if (file_exists(__DIR__ . '/helpers/UniversityApplicationHelper.php')) {
    require_once __DIR__ . '/helpers/UniversityApplicationHelper.php';
}

// ---- Billing (RevenueCat webhook) - optional ----
if (file_exists(__DIR__ . '/helpers/MembershipHelper.php')) {
    require_once __DIR__ . '/helpers/MembershipHelper.php';
}
if (file_exists(__DIR__ . '/controllers/BillingController.php')) {
    require_once __DIR__ . '/controllers/BillingController.php';
}

class PlgWebservicesNumistr extends CMSPlugin
{
    private $config;
    private $authHelper;
    private $dbHelper;
    private $responseHelper;
    private $rateLimiter;

    public function __construct(&$subject, $config = [])
    {
        parent::__construct($subject, $config);
        
        // Config dosyasını yükle
        $this->config = require __DIR__ . '/config/constants.php';
        
        // Helper'ları başlat
        $this->authHelper = new NumisTRAuthHelper($this->config);
        $this->dbHelper = new NumisTRDatabaseHelper($this->config);
        $this->responseHelper = new NumisTRResponseHelper();
        $this->rateLimiter = new NumisTRRateLimiter($this->config);
    }

    /**
     * Teşhis için basit log helper
     */
    private function dbg(string $branch, string $uri): void
    {
        try {
            $logger = Factory::getContainer()->get('logger');
            $logger->info('[NumisTR] uri="' . $uri . '" branch="' . $branch . '"');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    /**
     * Protected endpoint için auth zorunluluğu kontrolü
     */
    private function requireAuth(): User
    {
        $user = $this->authHelper->authenticateUser();
        
        if ($user === null) {
            $this->responseHelper->sendError(401, 'Unauthorized', 'Valid API token required. Use: Authorization: Bearer {token}');
            exit;
        }

        return $user;
    }

    /**
     * Rate limit kontrolü yap
     */
    private function checkRateLimit(string $endpoint, int $maxRequests = null): void
    {
        if ($maxRequests === null) {
            $maxRequests = $this->config['RATE_LIMITS']['default'] ?? 60;
        }
        
        if (!$this->rateLimiter->checkLimit($endpoint, $maxRequests)) {
            $remaining = $this->rateLimiter->getRemainingRequests($endpoint, $maxRequests);
            $this->responseHelper->sendError(
                429,
                'Too Many Requests',
                'Rate limit exceeded. Please try again later. Limit: ' . $maxRequests . '/minute'
            );
            exit;
        }
    }

    /**
     * Ana route handler
     */
    public function onBeforeApiRoute($event): void
    {
        $app = Factory::getApplication();
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $this->dbg('entry', $uri);
		
		// ---- Recognition
		if (strpos($uri, '/v1/recognize') !== false) {
		  RecognitionController::recognize();
		  return;
		}

        // ===================== BILLING: /v1/billing/revenuecat ==============
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/billing/revenuecat(?:[/?#;]|$)~', $uri)) {
            $this->dbg('billing-revenuecat', $uri);

            if (!class_exists('BillingController')) {
                $this->responseHelper->sendError(503, 'Service Unavailable', 'Billing controller not deployed');
                return;
            }

            BillingController::revenueCatWebhook();
            return;
        }

        // ===================== TICKER: /v1/ticker ===========================
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/ticker(?:[/?#;]|$)~', $uri)) {
            $this->handleTicker($uri);
            return;
        }

        // ===================== PING ========================================
        if (strpos($uri, '/v1/ping') !== false) {
            $this->dbg('ping', $uri);
            $this->responseHelper->sendJson(['ok' => true, 'pong' => time()]);
            return;
        }

        // ===================== USER PROFILE: /v1/user/profile ==============
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/user/profile(?:[/?#;]|$)~', $uri)) {
            $this->handleUserProfile($uri);
            return;
        }

        // ===================== USER SUBSCRIPTION: /v1/user/subscription ====
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/user/subscription(?:[/?#;]|$)~', $uri)) {
            $this->handleUserSubscription($uri);
            return;
        }

        // ===================== REGIONS LIST: /v1/regions ===================
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/regions(?:[/?#;]|$)~', $uri)) {
            $this->handleRegions($uri);
            return;
        }

        // ===================== LOCATIONS IMPORT (admin): POST /v1/locations/import =
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/locations/import(?:[/?#;]|$)~', $uri)) {
            $this->handleLocationsImport($uri);
            return;
        }

        // ===================== LOCATION DETAIL: /v1/locations/{loc_id} =====
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/locations/([^/?#;]+)(?:[/?#;]|$)~', $uri, $m)) {
            $this->handleLocationDetail($uri, $m[1]);
            return;
        }

        // ===================== LOCATIONS BBOX LIST: /v1/locations ==========
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/locations(?:[/?#;]|$)~', $uri)) {
            $this->handleLocations($uri);
            return;
        }

        // ===================== MATERIALS LIST: /v1/materials ===============
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/materials(?:[/?#;]|$)~', $uri)) {
            $this->handleMaterials($uri);
            return;
        }

        // ===================== STATISTICS: /v1/stats =======================
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/stats(?:[/?#;]|$)~', $uri)) {
            $this->handleStats($uri);
            return;
        }

        // ===================== FACETS: /v1/variants/facets =================
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/variants/facets(?:[/?#;]|$)~', $uri)) {
            $this->handleVariantsFacets($uri);
            return;
        }

        // ===================== SUGGEST MINTS: /v1/suggest/mints ============
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/suggest/mints(?:[/?#;]|$)~', $uri)) {
            $this->handleSuggestMints($uri);
            return;
        }

        // ===================== SUGGEST AUTHORITIES: /v1/suggest/authorities =
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/suggest/authorities(?:[/?#;]|$)~', $uri)) {
            $this->handleSuggestAuthorities($uri);
            return;
        }

        // ===================== SCAN QUOTA: /v1/user/scan-quota =============
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/user/scan-quota(?:[/?#;]|$)~', $uri)) {
            $this->handleScanQuota($uri);
            return;
        }

        // ===================== UNIVERSITY APPLICATION: /v1/university-application ======
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/university-application(?:[/?#;]|$)~', $uri)) {
            $this->handleUniversityApplication($uri);
            return;
        }

        // ===================== ARTICLES: /v1/articles ======================
        // Featured article (daily rotation)
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/articles/featured(?:[/?#;]|$)~', $uri)) {
            $this->handleArticlesFeatured($uri);
            return;
        }

        // Blog categories
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/articles/categories(?:[/?#;]|$)~', $uri)) {
            $this->handleArticlesCategories($uri);
            return;
        }

        // Article detail
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/articles/([^/?#;]+)(?:[/?#;]|$)~', $uri, $m)) {
            $this->handleArticleDetail($uri, (int)$m[1]);
            return;
        }

        // Article list (paginated, optional category filter)
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/articles(?:[?#;]|$)~', $uri)) {
            $this->handleArticlesList($uri);
            return;
        }

        // ===================== IMAGES: /v1/variants/{id}/images ============
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/variants/([^/?#;]+)/images(?:[/?#;]|$)~', $uri, $m)) {
            $this->handleVariantImages($uri, (int)$m[1]);
            return;
        }

        // ===================== ITEM: /v1/variants/{key} ====================
        if (preg_match('~(?:/api)?(?:/index\.php)?/v1/variants/([^/?#;]+)~', $uri, $m)) {
            $this->handleVariantItem($uri, $m[1]);
            return;
        }

        // ===================== LIST: /v1/variants ==========================
        if ($this->isVariantsIndex($uri)) {
            $this->handleVariantsList($uri);
            return;
        }

        // ===================== NOT FOUND ===================================
        $this->dbg('none', $uri);
        $this->responseHelper->sendError(404, 'Endpoint not found');
    }

    // ========================================================================
    // ENDPOINT HANDLERS
    // ========================================================================

    /**
     * GET /v1/user/profile
     */
    private function handleUserProfile(string $uri): void
    {
        $this->dbg('user-profile', $uri);
        
        $user = $this->requireAuth();
        
        try {
            $isPro = $this->authHelper->hasProSubscription($user);
            
            $payload = [
                'data' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'name' => $user->name,
                    'email' => $user->email,
                    'subscription' => [
                        'type' => $isPro ? 'pro' : 'free',
                        'is_pro' => $isPro,
                    ],
                    'registered' => $user->registerDate,
                    'last_visit' => $user->lastvisitDate,
                ]
            ];

            $this->responseHelper->sendJson($payload);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/user/subscription
     */
    private function handleUserSubscription(string $uri): void
    {
        $this->dbg('user-subscription', $uri);
        
        $user = $this->requireAuth();
        
        try {
            $isPro = $this->authHelper->hasProSubscription($user);
            
            $payload = [
                'data' => [
                    'type' => $isPro ? 'pro' : 'free',
                    'is_pro' => $isPro,
                    'features' => [
                        'unlimited_access' => $isPro,
                        'download_images' => $isPro,
                        'advanced_filters' => $isPro,
                        'favorites' => $isPro,
                    ]
                ]
            ];

            $this->responseHelper->sendJson($payload);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/regions
     */
    private function handleRegions(string $uri): void
    {
        $this->dbg('regions', $uri);
        
        try {
            $db = Factory::getDbo();
            
            $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
            if (empty($allowedCatIds)) {
                $this->responseHelper->sendJson(['data' => []]);
                return;
            }

            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('title'),
                    $db->quoteName('alias'),
                    $db->quoteName('description'),
                ])
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $allowedCatIds) . ')')
                ->where($db->quoteName('id') . ' != ' . (int)$this->config['ROOT_CAT_ID'])
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('lft') . ' ASC');

            $db->setQuery($query);
            $regions = $db->loadAssocList() ?: [];

            $data = array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'name' => $r['title'],
                    'code' => $r['alias'],
                    'description' => strip_tags($r['description'] ?? ''),
                ];
            }, $regions);

            $this->responseHelper->sendJson(['data' => $data]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/locations
     * Lightweight map pins inside a bounding box.
     *
     * Params (either style):
     *   - bbox=swLat,swLng,neLat,neLng
     *   - ne_lat, ne_lng, sw_lat, sw_lng   (legacy get_points.php compatible)
     *   - lang=tr|en (default tr), limit (max 5000), only_coins=1, region={code}
     */
    private function handleLocations(string $uri): void
    {
        $this->dbg('locations-list', $uri);

        $this->checkRateLimit('locations', $this->config['RATE_LIMITS']['default'] ?? 60);

        try {
            $app  = Factory::getApplication();
            $lang = ($app->input->getString('lang', 'tr') === 'en') ? 'en' : 'tr';

            // Parse bbox (comma form takes precedence, else ne_/sw_ params)
            $swLat = $swLng = $neLat = $neLng = null;
            $bbox = trim((string)$app->input->getString('bbox', ''));
            if ($bbox !== '') {
                $p = array_map('trim', explode(',', $bbox));
                if (count($p) === 4) {
                    [$swLat, $swLng, $neLat, $neLng] = [(float)$p[0], (float)$p[1], (float)$p[2], (float)$p[3]];
                }
            } else {
                $neLat = (float)$app->input->get('ne_lat', 0, 'FLOAT');
                $neLng = (float)$app->input->get('ne_lng', 0, 'FLOAT');
                $swLat = (float)$app->input->get('sw_lat', 0, 'FLOAT');
                $swLng = (float)$app->input->get('sw_lng', 0, 'FLOAT');
            }

            if (!$swLat || !$swLng || !$neLat || !$neLng) {
                $this->responseHelper->sendError(400, 'Bad Request', 'bbox required: bbox=swLat,swLng,neLat,neLng (or ne_/sw_ params)');
                return;
            }
            // Normalize swapped corners
            if ($swLat > $neLat) { [$swLat, $neLat] = [$neLat, $swLat]; }
            if ($swLng > $neLng) { [$swLng, $neLng] = [$neLng, $swLng]; }

            $opts = [
                'limit'      => (int)$app->input->get('limit', 2000, 'INT'),
                'only_coins' => filter_var($app->input->getString('only_coins', '0'), FILTER_VALIDATE_BOOLEAN),
                'region'     => trim((string)$app->input->getString('region', '')),
            ];

            $helper = new NumisTRLocationsHelper($this->config);
            $pins = $helper->getByBbox($swLat, $swLng, $neLat, $neLng, $lang, $opts);

            $this->responseHelper->sendJson([
                'data' => $pins,
                'meta' => ['count' => count($pins), 'lang' => $lang],
            ]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $this->config['DEBUG_MODE'] ? $e->getMessage() : 'Unable to fetch locations');
        }
    }

    /**
     * GET /v1/locations/{loc_id}
     * Full content for a single ancient settlement (e.g. LOC-0017), language-aware.
     */
    private function handleLocationDetail(string $uri, string $locId): void
    {
        $this->dbg('locations-detail', $uri);

        $this->checkRateLimit('locations', $this->config['RATE_LIMITS']['default'] ?? 60);

        try {
            $app  = Factory::getApplication();
            $lang = ($app->input->getString('lang', 'tr') === 'en') ? 'en' : 'tr';

            $locId = strtoupper(trim($locId));
            if (!preg_match('/^LOC-\d{1,6}$/', $locId)) {
                $this->responseHelper->sendError(400, 'Bad Request', 'Invalid location id (expected LOC-NNNN)');
                return;
            }

            $helper = new NumisTRLocationsHelper($this->config);
            $detail = $helper->getDetail($locId, $lang);

            if ($detail === null) {
                $this->responseHelper->sendError(404, 'Resource not found');
                return;
            }

            $this->responseHelper->sendJson(['data' => $detail]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $this->config['DEBUG_MODE'] ? $e->getMessage() : 'Unable to fetch location');
        }
    }

    /**
     * POST /v1/locations/import   (admin only — Super User token)
     * Batch upsert of ancient-settlement rows by loc_id.
     * Body (JSON): { "rows": [ { "loc_id": "LOC-0017", "name_tr": "...", ... }, ... ] }
     * Used by the n8n consolidation workflow to push TR+EN content into `locations`.
     */
    private function handleLocationsImport(string $uri): void
    {
        $this->dbg('locations-import', $uri);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->responseHelper->sendError(405, 'Method Not Allowed', 'Use POST');
            return;
        }

        $user = $this->requireAuth();
        if (!$user->authorise('core.admin')) {
            $this->responseHelper->sendError(403, 'Forbidden', 'Admin privileges required');
            return;
        }

        try {
            $raw  = file_get_contents('php://input') ?: '';
            $body = json_decode($raw, true);
            $rows = $body['rows'] ?? (is_array($body) ? $body : null);

            if (!is_array($rows) || empty($rows)) {
                $this->responseHelper->sendError(400, 'Bad Request', 'JSON body must contain a non-empty "rows" array');
                return;
            }
            if (count($rows) > 200) {
                $this->responseHelper->sendError(400, 'Bad Request', 'Max 200 rows per batch');
                return;
            }

            $helper = new NumisTRLocationsHelper($this->config);
            $inserted = 0; $updated = 0; $errors = [];

            foreach ($rows as $i => $row) {
                try {
                    $res = $helper->upsert((array)$row);
                    if ($res === 'inserted') { $inserted++; } else { $updated++; }
                } catch (\Throwable $e) {
                    $errors[] = ['index' => $i, 'loc_id' => $row['loc_id'] ?? null, 'error' => $e->getMessage()];
                }
            }

            $this->responseHelper->sendJson([
                'data' => [
                    'inserted' => $inserted,
                    'updated'  => $updated,
                    'failed'   => count($errors),
                    'errors'   => $errors,
                ],
            ]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $this->config['DEBUG_MODE'] ? $e->getMessage() : 'Import failed');
        }
    }

    /**
     * GET /v1/materials
     */
    private function handleMaterials(string $uri): void
    {
        $this->dbg('materials', $uri);
        $this->responseHelper->sendJson(['data' => $this->config['MATERIALS_LIST']]);
    }

    /**
     * GET /v1/stats
     */
    private function handleStats(string $uri): void
    {
        $this->dbg('stats', $uri);
        
        // Rate limiting - Stats endpoint için özel limit
        $this->checkRateLimit('stats', $this->config['RATE_LIMITS']['stats']);
        
        try {
            // Cache kontrol
            $cacheKey = 'stats_data';
            $cache = Factory::getCache('numistr_api', 'callback');
            $cache->setLifeTime($this->config['CACHE']['ttl_stats']);
            
            $payload = $cache->get(function() {
                $db = Factory::getDbo();
                
                $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
                if (empty($allowedCatIds)) {
                    return ['data' => [
                        'total_variants' => 0,
                        'total_regions' => 0,
                        'total_mints' => 0,
                        'total_images' => 0,
                    ]];
                }
                
                $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

                // Total variants
                $query = $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('catid') . ' IN (' . $allowedCatIdsSql . ')')
                    ->where($db->quoteName('state') . ' = 1');
                $db->setQuery($query);
                $totalVariants = (int)$db->loadResult();

                // Total regions
                $totalRegions = count($allowedCatIds) - 1;

                // Total mints
                $fvTableName = $this->dbHelper->resolveFieldsValuesTable($db);
                $mintFieldId = $this->dbHelper->fid('mint_name');
                
                $mintSql = "
                    SELECT COUNT(DISTINCT 
                        LOWER(
                            COALESCE(
                                NULLIF(TRIM(v.mint_name), ''),
                                NULLIF(TRIM(fv.value), '')
                            )
                        )
                    ) 
                    FROM " . $db->quoteName('o_numistr_variants_public', 'v') . "
                    INNER JOIN " . $db->quoteName('#__content', 'ct') . " 
                        ON ct.id = v.article_id
                    LEFT JOIN " . $db->quoteName($fvTableName, 'fv') . "
                        ON fv.item_id = CAST(v.article_id AS CHAR) COLLATE utf8mb4_unicode_ci
                        AND fv.field_id = " . (int)$mintFieldId . "
                    WHERE ct.catid IN (" . $allowedCatIdsSql . ")
                        AND ct.state = 1
                        AND (
                            (v.mint_name IS NOT NULL AND v.mint_name != '')
                            OR (fv.value IS NOT NULL AND fv.value != '')
                        )
                ";
                $db->setQuery($mintSql);
                $totalMints = (int)$db->loadResult();

                // Total images
                $query = $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('coins_images'));
                $db->setQuery($query);
                $totalImages = (int)$db->loadResult();

                return [
                    'data' => [
                        'total_variants' => $totalVariants,
                        'total_regions' => $totalRegions,
                        'total_mints' => $totalMints,
                        'total_images' => $totalImages,
                    ]
                ];
            }, [], $cacheKey);

            $this->responseHelper->sendJson($payload);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/ticker
     * News ticker endpoint for mint names and regional content
     *
     * Query Parameters:
     *   - category: Category alias (default: 'darphane-isimleri')
     *   - region: Region code filter (default: 'all')
     *   - limit: Number of items (default: 50, max: 200)
     *   - random: Randomize order (default: true)
     */
    private function handleTicker(string $uri): void
    {
        $this->dbg('ticker', $uri);

        // Rate limiting - Ticker endpoint için özel limit
        $this->checkRateLimit('ticker', $this->config['RATE_LIMITS']['default']);

        try {
            // Query parametrelerini al
            // category_id varsa onu kullan, yoksa varsayılan 46 (Ticker Info kategorisi)
            $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 46;
            $categoryAlias = $_GET['category'] ?? null;
            $region = $_GET['region'] ?? 'all';
            $language = $_GET['language'] ?? '*'; // Language filter: tr-TR, en-GB, or * for all
            $limit = min((int) ($_GET['limit'] ?? 50), 200); // Max 200 items
            $random = isset($_GET['random']) ? filter_var($_GET['random'], FILTER_VALIDATE_BOOLEAN) : true;
            $debug = isset($_GET['debug']) ? filter_var($_GET['debug'], FILTER_VALIDATE_BOOLEAN) : false;

            // TickerHelper'ı başlat
            $tickerHelper = new NumisTRTickerHelper($this->config);

            // Ticker items'ları getir
            $items = $tickerHelper->getTickerItems([
                'category_id' => $categoryId,
                'category_alias' => $categoryAlias,
                'region' => $region,
                'language' => $language,
                'limit' => $limit,
                'random' => $random,
                'cache' => 3600, // 1 hour cache
                'debug' => $debug
            ]);

            // Response formatla
            $response = [
                'data' => [
                    'items' => $items,
                    'count' => count($items),
                    'region' => $region,
                    'language' => $language,
                    'category' => $categoryAlias,
                    'randomized' => $random
                ],
                'meta' => [
                    'total_available' => count($items),
                    'limit_applied' => $limit
                ]
            ];

            $this->responseHelper->sendJson($response);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(
                500,
                'Ticker error',
                $this->config['DEBUG_MODE'] ? $e->getMessage() : 'Unable to fetch ticker data'
            );
        }
    }

    /**
     * GET /v1/variants
     */
    private function handleVariantsList(string $uri): void
    {
        $this->dbg('variants-index', $uri);
        
        try {
            $app = Factory::getApplication();
            $db = Factory::getDbo();
            $vTbl = $db->quoteName('o_numistr_variants_public', 'v');
            $cTbl = $db->quoteName('#__content', 'ct');

            // Parametreler
            $perPage = max(1, min((int)$app->input->get('per_page', 20), 32));
            $page = max(1, (int)$app->input->get('page', 1));
            $offset = ($page - 1) * $perPage;

            $modeParam = strtolower((string)$app->input->get('mode', ''));
            $onlyParam = strtolower((string)$app->input->get('only', ''));
            $countOnly = ($modeParam === 'count' || $onlyParam === 'count');

            $sortParam = strtolower((string)$app->input->get('sort', 'uid_asc'));

            // Filtreler
            $filter = (array)$app->input->get('filter', [], 'ARRAY');
            $regionF = isset($filter['region']) ? trim((string)$filter['region']) : '';
            $materialF = isset($filter['material']) ? trim((string)$filter['material']) : '';
            $mintF = isset($filter['mint']) ? trim((string)$filter['mint']) : '';
            $authorityF = isset($filter['authority']) ? trim((string)$filter['authority']) : '';
            $yearFromF = isset($filter['year_from']) ? (int)$filter['year_from'] : null;
            $yearToF = isset($filter['year_to']) ? (int)$filter['year_to'] : null;
            $hasImagesFStr = isset($filter['has_images']) ? strtolower((string)$filter['has_images']) : '';
            $hasImagesF = in_array($hasImagesFStr, ['1','true','yes'], true);

            // Guardrails
			if (!$countOnly) {
				if ($materialF !== '' && $mintF === '' && $authorityF === '' && $regionF === '' && $yearFromF === null && $yearToF === null) {
					$this->responseHelper->sendError(400, 'Query too broad', 'filter[material] tek başına kullanılamaz. Lütfen bölge, darphane veya yıl ekleyin.');
					return;
				}
				// Region tek başına artık izin veriliyor - bu blok silindi
			}

            // İzin verilen kategoriler
            $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
            if (empty($allowedCatIds)) {
                $this->responseHelper->sendJson(['data'=>[], 'meta'=>['total'=>0,'page'=>1,'per_page'=>$perPage], 'links'=>['next'=>null,'prev'=>null]]);
                return;
            }
            $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

            // Fields table
            $fvTableName = $this->dbHelper->resolveFieldsValuesTable($db);
            $fvTbl = function(string $alias) use ($db, $fvTableName) {
                return $db->quoteName($fvTableName, $alias);
            };

            // Field IDs
            $matFieldId = $this->dbHelper->fid('material');
            $mintFieldId = $this->dbHelper->fid('mint_name');
            $authFieldId = $this->dbHelper->fid('authority_name');

            // Base query
            $qBase = $db->getQuery(true)
                ->from($vTbl)
                ->join('INNER', $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                    . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                    . ' AND ' . $db->quoteName('ct.state') . ' = 1');

            // COUNT
            $qCount = clone $qBase;
            $qCount->clear('select')->select('COUNT(*)');

            // SELECT
            $q = clone $qBase;
            $q->clear('select')->select('v.*');

            // Joins
            if ($materialF !== '' && $matFieldId !== null) {
                $q->select($db->quoteName('fv_mat.value', 'material_value'))
                  ->join('LEFT', $fvTbl('fv_mat') . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
                $qCount->join('LEFT', $fvTbl('fv_mat') . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
            } else { 
                $q->select('NULL AS ' . $db->quoteName('material_value')); 
            }

            if ($mintF !== '' && $mintFieldId !== null) {
                $q->select($db->quoteName('fv_mint.value', 'mint_value'))
                  ->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                $qCount->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
            } else { 
                $q->select('NULL AS ' . $db->quoteName('mint_value')); 
            }

            if ($authorityF !== '' && $authFieldId !== null) {
                $q->select($db->quoteName('fv_auth.value', 'authority_value'))
                  ->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                $qCount->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
            } else { 
                $q->select('NULL AS ' . $db->quoteName('authority_value')); 
            }

            // Filtreler uygula
            $this->applyVariantFilters($db, $q, $qCount, [
                'material' => $materialF,
                'mint' => $mintF,
                'authority' => $authorityF,
                'region' => $regionF,
                'year_from' => $yearFromF,
                'year_to' => $yearToF,
                'has_images' => $hasImagesF,
            ], $matFieldId, $mintFieldId, $authFieldId);

            // Total
            $db->setQuery($qCount);
            $total = (int)$db->loadResult();

            if ($countOnly) {
                $this->responseHelper->sendJson([
                    'data' => [],
                    'meta' => ['total' => $total, 'page' => 1, 'per_page' => 0, 'total_pages' => 0, 'mode' => 'count'],
                    'links' => ['next'=>null,'prev'=>null,'first'=>null,'last'=>null],
                ]);
                return;
            }

            $hasNarrower = ($mintF !== '' || $authorityF !== '' || $yearFromF !== null || $yearToF !== null);
            if ($total > $this->config['SAFE_CAP'] && !$hasNarrower) {
                $this->responseHelper->sendError(422, 'Result too large', 'Sonuç kümesi çok geniş (' . $total . ').');
                return;
            }

            // Sıralama
            $orderClause = $this->buildOrderClause($db, $sortParam);
            $q->order($orderClause)->setLimit($perPage, $offset);
            $db->setQuery($q);
            $rows = $db->loadAssocList() ?: [];

            $totalPages = (int)ceil($total / $perPage);
            $next = ($offset + $perPage < $total) ? $page + 1 : null;
            $prev = ($page > 1) ? $page - 1 : null;

            $this->responseHelper->sendJson([
                'data' => $rows,
                'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => $totalPages, 'sort' => $sortParam],
                'links' => [
                    'first' => '/v1/variants?page=1&per_page=' . $perPage,
                    'prev' => $prev ? '/v1/variants?page=' . $prev . '&per_page=' . $perPage : null,
                    'next' => $next ? '/v1/variants?page=' . $next . '&per_page=' . $perPage : null,
                    'last' => '/v1/variants?page=' . $totalPages . '&per_page=' . $perPage,
                ],
            ]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/variants/facets
     */
    private function handleVariantsFacets(string $uri): void
    {
        $this->dbg('variants-facets', $uri);
        
        try {
            $app = Factory::getApplication();
            $db = Factory::getDbo();
            $vTbl = $db->quoteName('o_numistr_variants_public', 'v');
            $cTbl = $db->quoteName('#__content', 'ct');

            $filter = (array)$app->input->get('filter', [], 'ARRAY');
            $regionF = isset($filter['region']) ? trim((string)$filter['region']) : '';
            $materialF = isset($filter['material']) ? trim((string)$filter['material']) : '';
            $mintF = isset($filter['mint']) ? trim((string)$filter['mint']) : '';
            $authorityF = isset($filter['authority']) ? trim((string)$filter['authority']) : '';
            $yearFromF = isset($filter['year_from']) ? (int)$filter['year_from'] : null;
            $yearToF = isset($filter['year_to']) ? (int)$filter['year_to'] : null;

            $facetLimit = max(1, min((int)$app->input->get('facet_limit', 15), 100));
            $yearsBucket = max(1, min((int)$app->input->get('years_bucket', 50), 500));

            $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
            if (empty($allowedCatIds)) {
                $this->responseHelper->sendJson(['meta'=>['total'=>0], 'facets'=>['mint'=>[], 'authority'=>[], 'material'=>[], 'years'=>[]]]);
                return;
            }
            $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

            $fvTableName = $this->dbHelper->resolveFieldsValuesTable($db);
            $fvTbl = function(string $alias) use ($db, $fvTableName) {
                return $db->quoteName($fvTableName, $alias);
            };
            $matFieldId = $this->dbHelper->fid('material');
            $mintFieldId = $this->dbHelper->fid('mint_name');
            $authFieldId = $this->dbHelper->fid('authority_name');

            $qBase = $db->getQuery(true)
                ->from($vTbl)
                ->join('INNER', $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                    . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                    . ' AND ' . $db->quoteName('ct.state') . ' = 1');

            if ($matFieldId !== null) {
                $qBase->join('LEFT', $fvTbl('fv_mat') . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
            }
            if ($mintFieldId !== null) {
                $qBase->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
            }
            if ($authFieldId !== null) {
                $qBase->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci' 
                    . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
            }

            $qCount = clone $qBase;
            $qCount->clear('select')->select('COUNT(*)');

            $this->applyFacetFilters($db, $qBase, $qCount, [
                'material' => $materialF,
                'mint' => $mintF,
                'authority' => $authorityF,
                'region' => $regionF,
                'year_from' => $yearFromF,
                'year_to' => $yearToF,
            ], $matFieldId, $mintFieldId, $authFieldId);

            $db->setQuery($qCount);
            $total = (int)$db->loadResult();

            $facets = [
                'mint' => $this->getFacetMint($db, $qBase, $mintFieldId, $facetLimit),
                'authority' => $this->getFacetAuthority($db, $qBase, $authFieldId, $facetLimit),
                'material' => $this->getFacetMaterial($db, $qBase, $matFieldId, $facetLimit),
                'years' => $this->getFacetYears($db, $qBase, $yearsBucket),
            ];

            $this->responseHelper->sendJson(['meta'=>['total'=>$total, 'years_bucket'=>$yearsBucket], 'facets'=>$facets]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/suggest/mints
     */
    private function handleSuggestMints(string $uri): void
    {
        $this->dbg('suggest-mints', $uri);
        
        try {
            $app = Factory::getApplication();
            $qStr = trim((string)$app->input->get('q', ''));
            $limit = max(1, min((int)$app->input->get('limit', 10), 20));
            
            if (mb_strlen($qStr, 'UTF-8') < 2) {
                $this->responseHelper->sendJson(['data'=>[]]);
                return;
            }

            $db = Factory::getDbo();
            $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
            if (empty($allowedCatIds)) {
                $this->responseHelper->sendJson(['data'=>[]]);
                return;
            }
            $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

            $vTbl = $db->quoteName('o_numistr_variants_public', 'v');
            $cTbl = $db->quoteName('#__content', 'ct');
            $fvTableName = $this->dbHelper->resolveFieldsValuesTable($db);
            $fvTbl = function(string $alias) use ($db, $fvTableName) {
                return $db->quoteName($fvTableName, $alias);
            };
            $mintFieldId = $this->dbHelper->fid('mint_name');

            $qLike = '%' . mb_strtolower($qStr, 'UTF-8') . '%';
            $nameExpr = ($mintFieldId !== null)
                ? 'LOWER(COALESCE(' . $db->quoteName('v.mint_name') . ', ' . $db->quoteName('fv_mint.value') . '))'
                : 'LOWER(' . $db->quoteName('v.mint_name') . ')';

            $q = $db->getQuery(true)
                ->select($nameExpr . ' AS name')
                ->select('COUNT(*) AS cnt')
                ->from($vTbl)
                ->join('INNER', $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                    . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                    . ' AND ' . $db->quoteName('ct.state') . ' = 1');
            
            if ($mintFieldId !== null) {
                $q->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                    . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
            }
            
            $q->where('(' . $nameExpr . ' LIKE ' . $db->quote($qLike) . ')')
              ->where('(' . $nameExpr . ' IS NOT NULL AND ' . $nameExpr . " <> '')")
              ->group('name')
              ->order('cnt DESC, name ASC')
              ->setLimit($limit);

            $db->setQuery($q);
            $rows = $db->loadAssocList() ?: [];
            
            $this->responseHelper->sendJson(['data' => array_map(function($r) {
                return ['name'=>$r['name']];
            }, $rows)]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal Server Error', $e->getMessage());
        }
    }

    /**
     * GET /v1/suggest/authorities
     */
    private function handleSuggestAuthorities(string $uri): void
    {
        $this->dbg('suggest-authorities', $uri);
        
        try {
            $app = Factory::getApplication();
            $qStr = trim((string)$app->input->get('q', ''));
            $limit = max(1, min((int)$app->input->get('limit', 10), 20));
            
            if (mb_strlen($qStr, 'UTF-8') < 2) {
                $this->responseHelper->sendJson(['data'=>[]]);
                return;
            }

            $db = Factory::getDbo();
            $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
            if (empty($allowedCatIds)) {
                $this->responseHelper->sendJson(['data'=>[]]);
                return;
            }
            $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

            $vTbl = $db->quoteName('o_numistr_variants_public', 'v');
            $cTbl = $db->quoteName('#__content', 'ct');
            $fvTableName = $this->dbHelper->resolveFieldsValuesTable($db);
            $fvTbl = function(string $alias) use ($db, $fvTableName) {
                return $db->quoteName($fvTableName, $alias);
            };
            $authFieldId = $this->dbHelper->fid('authority_name');

            $qLike = '%' . mb_strtolower($qStr, 'UTF-8') . '%';
            $nameExpr = ($authFieldId !== null)
                ? 'LOWER(' . $db->quoteName('fv_auth.value') . ')'
                : 'LOWER(' . $db->quoteName('v.authority_name') . ')';

            $q = $db->getQuery(true)
                ->select($nameExpr . ' AS name')
                ->select('COUNT(*) AS cnt')
                ->from($vTbl)
                ->join('INNER', $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                    . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                    . ' AND ' . $db->quoteName('ct.state') . ' = 1');
            
            if ($authFieldId !== null) {
                $q->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                    . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
            }
            
            $q->where('(' . $nameExpr . ' LIKE ' . $db->quote($qLike) . ')')
              ->where('(' . $nameExpr . ' IS NOT NULL AND ' . $nameExpr . " <> '')")
              ->group('name')
              ->order('cnt DESC, name ASC')
              ->setLimit($limit);

            $db->setQuery($q);
            $rows = $db->loadAssocList() ?: [];
            
            $this->responseHelper->sendJson(['data' => array_map(function($r) {
                return ['name'=>$r['name']];
            }, $rows)]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal Server Error', $e->getMessage());
        }
    }

    /**
     * GET /v1/variants/{id}/images
     */
    private function handleVariantImages(string $uri, int $variantId): void
    {
        $this->dbg('variants-images', $uri);
        
        try {
            $app = Factory::getApplication();
            $db = Factory::getDbo();
            $vTbl = $db->quoteName('o_numistr_variants_public', 'v');
            $cTbl = $db->quoteName('#__content', 'ct');

            $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
            if (empty($allowedCatIds)) {
                $this->responseHelper->sendError(404, 'Resource not found');
                return;
            }
            $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

            $qv = $db->getQuery(true)
                ->select('1')
                ->from($vTbl)
                ->join('INNER', $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                    . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                    . ' AND ' . $db->quoteName('ct.state') . ' = 1')
                ->where($db->quoteName('v.article_id') . ' = ' . (int)$variantId)
                ->setLimit(1);
            $db->setQuery($qv);
            $exists = (int)$db->loadResult();
            
            if ($exists !== 1) {
                $this->responseHelper->sendError(404, 'Resource not found');
                return;
            }

            $wm = (int)$app->input->get('wm', 1);
            $abs = (int)$app->input->get('abs', 0);

            $data = $this->getVariantImages($db, $variantId, $wm, $abs);
            $this->responseHelper->sendJson(['data' => $data]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/variants/{key}
     * ✅ DÜZELTME YAPILDI - Tüm custom fields eklendi
     */
    private function handleVariantItem(string $uri, string $token): void
    {
        $this->dbg('variants-item', $uri);
        
        try {
            $app = Factory::getApplication();
            $db = Factory::getDbo();
            $vTbl = $db->quoteName('o_numistr_variants_public', 'v');
            $cTbl = $db->quoteName('#__content', 'ct');

            $includeList = array_filter(array_map('trim', explode(',', $app->input->getString('include',''))));
            $includeRaw = in_array('raw', $includeList, true);
            $includeFlds = in_array('fields', $includeList, true);
            $includeImgs = in_array('images', $includeList, true);

            $wmPref = (int)$app->input->get('wm', 1);
            $absUrl = (int)$app->input->get('abs', 0);

            $allowedCatIds = $this->dbHelper->getAllowedCatIds($db, $this->config['ROOT_CAT_ID']);
            if (empty($allowedCatIds)) {
                $this->responseHelper->sendError(404, 'Resource not found');
                return;
            }
            $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

            $fvTableName = $this->dbHelper->resolveFieldsValuesTable($db);
            
            // ✅ TÜM FIELD ID'LERI - DOĞRU İSİMLERLE
            $matFieldId = $this->dbHelper->fid('material');
            $mintFieldId = $this->dbHelper->fid('mint_name');
            $authFieldId = $this->dbHelper->fid('authority_name');
            $dateFromFieldId = $this->dbHelper->fid('start_date');       // ✅ Düzeltildi
            $dateToFieldId = $this->dbHelper->fid('end_date');           // ✅ Düzeltildi
            $obverseTrFieldId = $this->dbHelper->fid('obverse_desc_tr'); // ✅ Türkçe
            $reverseTrFieldId = $this->dbHelper->fid('reverse_desc_tr'); // ✅ Türkçe
            $obverseEnFieldId = $this->dbHelper->fid('obverse_desc');     // ✅ İngilizce
            $reverseEnFieldId = $this->dbHelper->fid('reverse_desc');     // ✅ İngilizce
            $coordsFieldId = $this->dbHelper->fid('coordinates');        // ✅ Tek field

            $q = $db->getQuery(true)
                ->select('v.*')
                ->from($vTbl)
                ->join('INNER', $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                    . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                    . ' AND ' . $db->quoteName('ct.state') . ' = 1');

            // ✅ TÜM CUSTOM FIELDS JOIN
            if ($matFieldId !== null) {
                $q->select($db->quoteName('fv_mat.value', 'material_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_mat')
                      . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('material_value'));
            }

            if ($mintFieldId !== null) {
                $q->select($db->quoteName('fv_mint.value', 'mint_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_mint')
                      . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('mint_value'));
            }

            if ($authFieldId !== null) {
                $q->select($db->quoteName('fv_auth.value', 'authority_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_auth')
                      . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('authority_value'));
            }

            if ($dateFromFieldId !== null) {
                $q->select($db->quoteName('fv_dfrom.value', 'date_from_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_dfrom')
                      . ' ON ' . $db->quoteName('fv_dfrom.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_dfrom.field_id') . ' = ' . (int)$dateFromFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('date_from_value'));
            }

            if ($dateToFieldId !== null) {
                $q->select($db->quoteName('fv_dto.value', 'date_to_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_dto')
                      . ' ON ' . $db->quoteName('fv_dto.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_dto.field_id') . ' = ' . (int)$dateToFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('date_to_value'));
            }

            // ✅ Obverse TR
            if ($obverseTrFieldId !== null) {
                $q->select($db->quoteName('fv_obv_tr.value', 'obverse_tr_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_obv_tr')
                      . ' ON ' . $db->quoteName('fv_obv_tr.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_obv_tr.field_id') . ' = ' . (int)$obverseTrFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('obverse_tr_value'));
            }

            // ✅ Reverse TR
            if ($reverseTrFieldId !== null) {
                $q->select($db->quoteName('fv_rev_tr.value', 'reverse_tr_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_rev_tr')
                      . ' ON ' . $db->quoteName('fv_rev_tr.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_rev_tr.field_id') . ' = ' . (int)$reverseTrFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('reverse_tr_value'));
            }

            // ✅ Obverse EN
            if ($obverseEnFieldId !== null) {
                $q->select($db->quoteName('fv_obv_en.value', 'obverse_en_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_obv_en')
                      . ' ON ' . $db->quoteName('fv_obv_en.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_obv_en.field_id') . ' = ' . (int)$obverseEnFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('obverse_en_value'));
            }

            // ✅ Reverse EN
            if ($reverseEnFieldId !== null) {
                $q->select($db->quoteName('fv_rev_en.value', 'reverse_en_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_rev_en')
                      . ' ON ' . $db->quoteName('fv_rev_en.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_rev_en.field_id') . ' = ' . (int)$reverseEnFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('reverse_en_value'));
            }

            if ($coordsFieldId !== null) {
                $q->select($db->quoteName('fv_coords.value', 'coordinates_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_coords')
                      . ' ON ' . $db->quoteName('fv_coords.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                      . ' AND ' . $db->quoteName('fv_coords.field_id') . ' = ' . (int)$coordsFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('coordinates_value'));
            }

            $conds = [];
            if (ctype_digit($token)) {
                $conds[] = $db->quoteName('v.article_id') . ' = ' . (int)$token;
                $uid = 'ntr:var:' . str_pad((string)(int)$token, 8, '0', STR_PAD_LEFT);
                $conds[] = 'BINARY ' . $db->quoteName('v.uid') . ' = ' . $db->quote($uid);
                $conds[] = 'BINARY ' . $db->quoteName('v.slug') . ' = ' . $db->quote($token);
            } else {
                if (strpos($token, ':') !== false) {
                    $conds[] = 'BINARY ' . $db->quoteName('v.uid') . ' = ' . $db->quote($token);
                } else {
                    $conds[] = 'BINARY ' . $db->quoteName('v.slug') . ' = ' . $db->quote($token);
                }
            }
            $q->where('(' . implode(' OR ', array_unique($conds)) . ')')->setLimit(1);

            $db->setQuery($q);
            $r = $db->loadAssoc();
            
            if (!$r) {
                $this->responseHelper->sendError(404, 'Resource not found');
                return;
            }

            $title = (!empty($r['title_tr']) ? $r['title_tr'] : null)
                ?? (!empty($r['title_en']) ? $r['title_en'] : null)
                ?? ($r['slug'] ?? null);

            $materialSrc = $r['metal'] ?? null;
            if (($materialSrc === null || $materialSrc === '') && array_key_exists('material_value', $r)) {
                $materialSrc = $r['material_value'];
            }

            // ✅ Helper function - string to int/float
            $toInt = function($val) {
                if ($val === null || $val === '') return null;
                return (int)$val;
            };
            $toFloat = function($val) {
                if ($val === null || $val === '') return null;
                return (float)$val;
            };

            // ✅ Helper function - coordinates parse (lat,lng formatında)
            $parseCoords = function($coordsStr) {
                if ($coordsStr === null || $coordsStr === '') return [null, null];
                $parts = array_map('trim', explode(',', $coordsStr));
                if (count($parts) === 2) {
                    return [(float)$parts[0], (float)$parts[1]];
                }
                return [null, null];
            };

            [$latitude, $longitude] = $parseCoords($r['coordinates_value'] ?? null);

            // ✅ TÜM ALANLAR - CUSTOM FIELDS'TAN
            $payload = [
                'uid' => $r['uid'] ?? null,
                'slug' => $r['slug'] ?? null,
                'title' => $title,
                'region' => $r['region_code'] ?? null,
                'material' => $this->dbHelper->normalizeMaterialKey((string)$materialSrc),
                'date_from' => $toInt($r['date_from_value'] ?? $r['date_from']),
                'date_to' => $toInt($r['date_to_value'] ?? $r['date_to']),
                'authority' => $r['authority_value'] ?? $r['authority_name'] ?? null,
                'mint' => $r['mint_value'] ?? $r['mint_name'] ?? null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'obverse_desc_tr' => $r['obverse_tr_value'] ?? $r['obverse_desc_tr'] ?? null,
                'obverse_desc' => $r['obverse_en_value'] ?? $r['obverse_desc'] ?? null,
                'reverse_desc_tr' => $r['reverse_tr_value'] ?? $r['reverse_desc_tr'] ?? null,
                'reverse_desc' => $r['reverse_en_value'] ?? $r['reverse_desc'] ?? null,
                'weight' => $r['weight_nominal'] ?? null,
                'diameter' => $r['diameter_nominal'] ?? null,
            ];
            
            if ($includeRaw) { $payload['_raw'] = $r; }
            if ($includeFlds) { $payload['_fields'] = ['material_source' => $materialSrc]; }

            if ($includeImgs) {
                $variantId = (int)($r['article_id'] ?? 0);
                $payload['images'] = $variantId > 0 ? $this->getVariantImages($db, $variantId, $wmPref, $absUrl) : [];
            }

            $this->responseHelper->sendJson(['data' => $payload]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    private function isVariantsIndex(string $uri): bool
    {
        return (bool)preg_match('~(?:/api)?(?:/index\.php)?/v1/variants(?:[/?#;]|$)~', $uri)
            && !preg_match('~(?:/api)?(?:/index\.php)?/v1/variants/[^/]+~', $uri);
    }

    private function applyVariantFilters($db, $q, $qCount, array $filters, $matFieldId, $mintFieldId, $authFieldId): void
    {
        if ($filters['material'] !== '') {
            $normKey = $this->dbHelper->normalizeMaterialKey($filters['material']);
            if ($normKey !== null) {
                $variants = $this->dbHelper->dbMaterialVariantsFor($normKey);
                $ins = array_map(function($m) use ($db) {
                    return $db->quote(mb_strtolower($m, 'UTF-8'));
                }, $variants);
                $viewMetalCheck = 'LOWER(' . $db->quoteName('v.metal') . ') IN (' . implode(',', $ins) . ')';
                
                if ($matFieldId !== null) {
                    $fieldMetalCheck = 'LOWER(' . $db->quoteName('fv_mat.value') . ') IN (' . implode(',', $ins) . ')';
                    $q->where('(' . $viewMetalCheck . ' OR ' . $fieldMetalCheck . ')');
                    $qCount->where('(' . $viewMetalCheck . ' OR ' . $fieldMetalCheck . ')');
                } else {
                    $q->where($viewMetalCheck);
                    $qCount->where($viewMetalCheck);
                }
            }
        }

        if ($filters['mint'] !== '') {
            $hasWild = (strpos($filters['mint'], '%') !== false || strpos($filters['mint'], '_') !== false);
            
            if ($hasWild) {
                $mintLike = '%' . mb_strtolower($filters['mint'], 'UTF-8') . '%';
                $viewMintCheck = 'LOWER(' . $db->quoteName('v.mint_name') . ') LIKE ' . $db->quote($mintLike);
                
                if ($mintFieldId !== null) {
                    $fieldMintCheck = 'LOWER(' . $db->quoteName('fv_mint.value') . ') LIKE ' . $db->quote($mintLike);
                    $q->where('(' . $viewMintCheck . ' OR ' . $fieldMintCheck . ')');
                    $qCount->where('(' . $viewMintCheck . ' OR ' . $fieldMintCheck . ')');
                } else {
                    $q->where($viewMintCheck);
                    $qCount->where($viewMintCheck);
                }
            } else {
                $mintEq = $db->quote($filters['mint']);
                $viewMintCheck = $db->quoteName('v.mint_name') . ' = ' . $mintEq;
                
                if ($mintFieldId !== null) {
                    $fieldMintCheck = $db->quoteName('fv_mint.value') . ' = ' . $mintEq;
                    $q->where('(' . $viewMintCheck . ' OR ' . $fieldMintCheck . ')');
                    $qCount->where('(' . $viewMintCheck . ' OR ' . $fieldMintCheck . ')');
                } else {
                    $q->where($viewMintCheck);
                    $qCount->where($viewMintCheck);
                }
            }
        }

        if ($filters['authority'] !== '' && $authFieldId !== null) {
            $authLike = '%' . mb_strtolower($filters['authority'], 'UTF-8') . '%';
            $expr = 'LOWER(' . $db->quoteName('fv_auth.value') . ')';
            $q->where($expr . ' LIKE ' . $db->quote($authLike));
            $qCount->where($expr . ' LIKE ' . $db->quote($authLike));
        }

        if ($filters['region'] !== '') {
            $q->where($db->quoteName('v.region_code') . ' = ' . $db->quote($filters['region']));
            $qCount->where($db->quoteName('v.region_code') . ' = ' . $db->quote($filters['region']));
        }

        if ($filters['year_from'] !== null || $filters['year_to'] !== null) {
            $lhsFrom = $db->quoteName('v.date_from');
            $lhsTo = $db->quoteName('v.date_to');
            $yf = $filters['year_from'] ?? $filters['year_to'];
            $yt = $filters['year_to'] ?? $filters['year_from'];
            $q->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
            $q->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
            $qCount->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
            $qCount->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
        }

        if ($filters['has_images']) {
            $existsSql = 'EXISTS (SELECT 1 FROM ' . $db->quoteName('coins_images', 'ci') 
                . ' WHERE ' . $db->quoteName('ci.coin_id') . ' = ' . $db->quoteName('v.article_id') . ')';
            $q->where($existsSql);
            $qCount->where($existsSql);
        }
    }

    private function applyFacetFilters($db, $qBase, $qCount, array $filters, $matFieldId, $mintFieldId, $authFieldId): void
    {
        if ($filters['material'] !== '') {
            $normKey = $this->dbHelper->normalizeMaterialKey($filters['material']);
            if ($normKey !== null) {
                $variants = $this->dbHelper->dbMaterialVariantsFor($normKey);
                $ins = array_map(function($m) use ($db) {
                    return $db->quote(mb_strtolower($m, 'UTF-8'));
                }, $variants);
                $coalesceMat = ($matFieldId !== null)
                    ? 'LOWER(COALESCE(' . $db->quoteName('v.metal') . ', ' . $db->quoteName('fv_mat.value') . '))'
                    : 'LOWER(' . $db->quoteName('v.metal') . ')';
                $qBase->where($coalesceMat . ' IN (' . implode(',', $ins) . ')');
                $qCount->where($coalesceMat . ' IN (' . implode(',', $ins) . ')');
            }
        }

        if ($filters['mint'] !== '') {
            $hasWild = (strpos($filters['mint'], '%') !== false || strpos($filters['mint'], '_') !== false);
            if ($hasWild) {
                $mintLike = '%' . mb_strtolower($filters['mint'], 'UTF-8') . '%';
                $coalesceMint = ($mintFieldId !== null)
                    ? 'LOWER(COALESCE(' . $db->quoteName('v.mint_name') . ', ' . $db->quoteName('fv_mint.value') . '))'
                    : 'LOWER(' . $db->quoteName('v.mint_name') . ')';
                $qBase->where($coalesceMint . ' LIKE ' . $db->quote($mintLike));
                $qCount->where($coalesceMint . ' LIKE ' . $db->quote($mintLike));
            } else {
                $mintEq = $db->quote($filters['mint']);
                if ($mintFieldId !== null) {
                    $qBase->where('(' . $db->quoteName('v.mint_name') . ' = ' . $mintEq 
                        . ' OR ' . $db->quoteName('fv_mint.value') . ' = ' . $mintEq . ')');
                    $qCount->where('(' . $db->quoteName('v.mint_name') . ' = ' . $mintEq 
                        . ' OR ' . $db->quoteName('fv_mint.value') . ' = ' . $mintEq . ')');
                } else {
                    $qBase->where($db->quoteName('v.mint_name') . ' = ' . $mintEq);
                    $qCount->where($db->quoteName('v.mint_name') . ' = ' . $mintEq);
                }
            }
        }

        if ($filters['authority'] !== '' && $authFieldId !== null) {
            $authLike = '%' . mb_strtolower($filters['authority'], 'UTF-8') . '%';
            $qBase->where('LOWER(' . $db->quoteName('fv_auth.value') . ') LIKE ' . $db->quote($authLike));
            $qCount->where('LOWER(' . $db->quoteName('fv_auth.value') . ') LIKE ' . $db->quote($authLike));
        }

        if ($filters['region'] !== '') {
            $qBase->where($db->quoteName('v.region_code') . ' = ' . $db->quote($filters['region']));
            $qCount->where($db->quoteName('v.region_code') . ' = ' . $db->quote($filters['region']));
        }

        if ($filters['year_from'] !== null || $filters['year_to'] !== null) {
            $lhsFrom = $db->quoteName('v.date_from');
            $lhsTo = $db->quoteName('v.date_to');
            $yf = $filters['year_from'] ?? $filters['year_to'];
            $yt = $filters['year_to'] ?? $filters['year_from'];
            $qBase->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
            $qBase->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
            $qCount->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
            $qCount->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
        }
    }

    private function buildOrderClause($db, string $sortParam): string
    {
        switch ($sortParam) {
            case 'updated_at_desc':
                return $db->quoteName('v.updated_at') . ' DESC, ' . $db->quoteName('v.uid') . ' ASC';
            case 'updated_at_asc':
                return $db->quoteName('v.updated_at') . ' ASC, ' . $db->quoteName('v.uid') . ' ASC';
            case 'uid_desc':
                return $db->quoteName('v.uid') . ' DESC';
            case 'uid_asc':
            default:
                return $db->quoteName('v.uid') . ' ASC';
        }
    }

    private function getFacetMint($db, $qBase, $mintFieldId, int $limit): array
    {
        $coalesceMint = ($mintFieldId !== null)
            ? 'COALESCE(' . $db->quoteName('v.mint_name') . ', ' . $db->quoteName('fv_mint.value') . ')'
            : $db->quoteName('v.mint_name');
        
        $qMint = clone $qBase;
        $qMint->clear('select')
              ->select('LOWER(' . $coalesceMint . ') AS name')
              ->select('COUNT(*) AS cnt')
              ->where('(' . $coalesceMint . ' IS NOT NULL AND ' . $coalesceMint . " <> '')")
              ->group('LOWER(' . $coalesceMint . ')')
              ->order('cnt DESC, name ASC')
              ->setLimit($limit);
        
        $db->setQuery($qMint);
        $rows = $db->loadAssocList() ?: [];
        return array_map(function($r) {
            return ['name'=>$r['name'], 'count'=>(int)$r['cnt']];
        }, $rows);
    }

    private function getFacetAuthority($db, $qBase, $authFieldId, int $limit): array
    {
        $authExpr = ($authFieldId !== null) ? $db->quoteName('fv_auth.value') : 'NULL';
        
        $qAuth = clone $qBase;
        $qAuth->clear('select')
              ->select('LOWER(' . $authExpr . ') AS name')
              ->select('COUNT(*) AS cnt')
              ->where('(' . $authExpr . ' IS NOT NULL AND ' . $authExpr . " <> '')")
              ->group('LOWER(' . $authExpr . ')')
              ->order('cnt DESC, name ASC')
              ->setLimit($limit);
        
        $db->setQuery($qAuth);
        $rows = $db->loadAssocList() ?: [];
        return array_map(function($r) {
            return ['name'=>$r['name'], 'count'=>(int)$r['cnt']];
        }, $rows);
    }

    private function getFacetMaterial($db, $qBase, $matFieldId, int $limit): array
    {
        $coalesceMat = ($matFieldId !== null)
            ? 'LOWER(COALESCE(' . $db->quoteName('v.metal') . ', ' . $db->quoteName('fv_mat.value') . '))'
            : 'LOWER(' . $db->quoteName('v.metal') . ')';
        
        $qMat = clone $qBase;
        $qMat->clear('select')
             ->select($coalesceMat . ' AS name')
             ->select('COUNT(*) AS cnt')
             ->where('(' . $coalesceMat . ' IS NOT NULL AND ' . $coalesceMat . " <> '')")
             ->group($coalesceMat)
             ->order('cnt DESC, name ASC')
             ->setLimit($limit);
        
        $db->setQuery($qMat);
        $rows = $db->loadAssocList() ?: [];
        return array_map(function($r) {
            return ['name'=>$r['name'], 'count'=>(int)$r['cnt']];
        }, $rows);
    }

    private function getFacetYears($db, $qBase, int $yearsBucket): array
    {
        $yExpr = 'COALESCE(' . $db->quoteName('v.date_from') . ', ' . $db->quoteName('v.date_to') . ')';
        $bucketStartExpr = 'FLOOR(' . $yExpr . ' / ' . (int)$yearsBucket . ') * ' . (int)$yearsBucket;
        
        $qYears = clone $qBase;
        $qYears->clear('select')
               ->select($bucketStartExpr . ' AS bucket_start')
               ->select('COUNT(*) AS cnt')
               ->where('(' . $yExpr . ' IS NOT NULL)')
               ->group('bucket_start')
               ->order('bucket_start ASC');
        
        $db->setQuery($qYears);
        $yearsRows = $db->loadAssocList() ?: [];
        
        return array_map(function($r) use ($yearsBucket) {
            $start = (int)$r['bucket_start'];
            $end = $start + $yearsBucket - 1;
            return ['bucket' => $start . '..' . $end, 'count' => (int)$r['cnt']];
        }, $yearsRows);
    }

    private function buildImageUrl(int $imageId, ?int $wm = null, int $abs = 0): string
    {
        $qs = ['option'=>'com_numistr','view'=>'gorsel','id'=>$imageId];
        if ($wm !== null) { $qs['wm'] = (int)$wm; }
        $path = '/index.php?' . http_build_query($qs);
        if ($abs === 1) {
            $root = rtrim(Uri::root(), '/');
            return $root . $path;
        }
        return $path;
    }

    private function getVariantImages($db, int $variantId, int $wmPref = 1, int $abs = 0): array
    {
        $imgTbl = $db->quoteName('coins_images', 'ci');
        $q = $db->getQuery(true)
            ->select([
                $db->quoteName('ci.image_id'),
                $db->quoteName('ci.coin_id'),
                $db->quoteName('ci.image_type'),
                $db->quoteName('ci.weight'),
                $db->quoteName('ci.diameter'),
                $db->quoteName('ci.ordering'),
                $db->quoteName('ci.remote_url'),
            ])
            ->from($imgTbl)
            ->where($db->quoteName('ci.coin_id') . ' = ' . (int)$variantId)
            ->order($db->quoteName('ci.ordering') . ' ASC');
        $db->setQuery($q);
        $rows = $db->loadAssocList() ?: [];

        // Web sitesi mantığı: sikke-gorsel-url custom field'inden doğru URL'i al
        $customImageUrl = null;
        try {
            $q2 = $db->getQuery(true)
                ->select($db->quoteName('v.value'))
                ->from($db->quoteName('#__fields', 'f'))
                ->join('INNER', $db->quoteName('#__fields_values', 'v') . ' ON ' . $db->quoteName('v.field_id') . ' = ' . $db->quoteName('f.id'))
                ->where($db->quoteName('f.name') . ' = ' . $db->quote('sikke-gorsel-url'))
                ->where($db->quoteName('v.item_id') . ' = ' . (int)$variantId)
                ->setLimit(1);
            $db->setQuery($q2);
            $cv = trim((string)$db->loadResult());
            if ($cv !== '' && preg_match('~^https?://~i', $cv) && stripos($cv, 'option=com_numistr') === false) {
                $customImageUrl = $cv;
            }
        } catch (\Throwable $e) {
            // Hata varsa sessizce devam et
        }

        $data = [];
        foreach ($rows as $idx => $r) {
            $imageId = (int)($r['image_id'] ?? 0);
            if ($imageId <= 0) { continue; }

            // İlk görselde sikke-gorsel-url varsa remote_url olarak kullan (web sitesi mantığı)
            $remoteUrl = $r['remote_url'] ?? null;
            if ($idx === 0 && !empty($customImageUrl)) {
                $remoteUrl = $customImageUrl;
            }

            $item = [
                'image_id' => $imageId,
                'variant_id' => (int)($r['coin_id'] ?? $variantId),
                'type' => $r['image_type'] ?? null,
                'weight' => $r['weight'] ?? null,
                'diameter' => $r['diameter'] ?? null,
                'ordering' => isset($r['ordering']) ? (int)$r['ordering'] : null,
                'url' => $this->buildImageUrl($imageId, $wmPref, $abs),
                'url_raw' => $this->buildImageUrl($imageId, 0, $abs),
                'remote_url' => $remoteUrl,
            ];
            $data[] = $item;
        }
        return $data;
    }

    /**
     * POST /v1/recognize
     * AI-powered coin recognition with quota management
     * Proxies to AI Service and manages scan quotas
     *
     * ⚠️ DEAD CODE — hiçbir yerden çağrılmıyor. /v1/recognize route'u
     * onBeforeApiRoute() içinde RecognitionController::recognize()'a gidiyor.
     * Buradaki quota sorguları eski şemaya (is_pro kolonu, prefix'siz tablo)
     * göre yazılmış; canlı davranış için RecognitionController + QuotaHelper'a bak.
     * Thumbnail zenginleştirme örneği olarak tutuluyor; ileride temizlenebilir.
     */
    private function handleRecognition(string $uri): void
    {
        $this->dbg('recognition', $uri);

        // Auth required
        $user = $this->requireAuth();

        // Rate limiting
        $this->checkRateLimit('recognition', 10); // 10 requests per minute

        try {
            $app = Factory::getApplication();
            $db = Factory::getDbo();

            // Check if image was uploaded
            $files = $app->input->files->get('image');
            if (!$files || !isset($files['tmp_name']) || !is_uploaded_file($files['tmp_name'])) {
                $this->responseHelper->sendError(400, 'Bad Request', 'Image file required');
                return;
            }

            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            $fileType = $files['type'] ?? '';
            if (!in_array($fileType, $allowedTypes, true)) {
                $this->responseHelper->sendError(400, 'Bad Request', 'Invalid image format. Only JPEG and PNG allowed.');
                return;
            }

            // Validate file size (max 10MB - increased for high-res photos)
            $fileSize = $files['size'] ?? 0;
            if ($fileSize > 10 * 1024 * 1024) {
                $this->responseHelper->sendError(400, 'Bad Request', 'Image too large. Maximum 10MB allowed.');
                return;
            }

            // Check scan quota
            $isPro = $this->authHelper->hasProSubscription($user);
            $currentMonth = date('Y-m');
            $quotaConfig = $this->config['QUOTA'] ?? ['free_limit' => 10, 'pro_limit' => -1];
            $freeLimit = (int)($quotaConfig['free_limit'] ?? 10);
            $proLimit = (int)($quotaConfig['pro_limit'] ?? -1);

            // Get or create quota record
            $quotaQuery = $db->getQuery(true)
                ->select(['user_id', 'month', 'scans_used', 'is_pro'])
                ->from($db->quoteName('numistr_scan_quota'))
                ->where($db->quoteName('user_id') . ' = ' . (int)$user->id)
                ->where($db->quoteName('month') . ' = ' . $db->quote($currentMonth));

            $db->setQuery($quotaQuery);
            $quotaRecord = $db->loadAssoc();

            if (!$quotaRecord) {
                // Create new quota record
                $insertQuery = $db->getQuery(true)
                    ->insert($db->quoteName('numistr_scan_quota'))
                    ->columns([$db->quoteName('user_id'), $db->quoteName('month'), $db->quoteName('scans_used'), $db->quoteName('is_pro')])
                    ->values((int)$user->id . ', ' . $db->quote($currentMonth) . ', 0, ' . (int)$isPro);
                $db->setQuery($insertQuery);
                $db->execute();

                $scansUsed = 0;
            } else {
                $scansUsed = (int)$quotaRecord['scans_used'];
            }

            // Check quota limit
            $scanLimit = $isPro ? ($proLimit === -1 ? 999999 : $proLimit) : $freeLimit;
            $remaining = $scanLimit - $scansUsed;

            if ($remaining <= 0 && !$isPro) {
                $this->responseHelper->sendError(429, 'Quota Exceeded', 'Monthly scan limit reached. Upgrade to Pro for unlimited scans.');
                return;
            }

            // === PROXY TO AI SERVICE ===
            $aiConfig = $this->config['AI_SERVICE'] ?? [];
            $aiServiceUrl = $aiConfig['url'] ?? 'https://ai.numistr.org';
            $aiTimeout = $aiConfig['timeout'] ?? 30;
            $verifySsl = $aiConfig['verify_ssl'] ?? true;

            // Read image file
            $imageData = file_get_contents($files['tmp_name']);
            if ($imageData === false) {
                $this->responseHelper->sendError(500, 'Internal Error', 'Failed to read uploaded image');
                return;
            }

            // Check for optional reverse image
            $reverseFiles = $app->input->files->get('reverse');
            $reverseData = null;
            if ($reverseFiles && isset($reverseFiles['tmp_name']) && is_uploaded_file($reverseFiles['tmp_name'])) {
                $reverseData = file_get_contents($reverseFiles['tmp_name']);
            }

            // Build multipart request to AI Service
            $boundary = 'NumisTR' . uniqid();
            $body = '';

            // Add obverse image
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"obverse.jpg\"\r\n";
            $body .= "Content-Type: image/jpeg\r\n\r\n";
            $body .= $imageData . "\r\n";

            // Add reverse image if provided
            if ($reverseData) {
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"reverse\"; filename=\"reverse.jpg\"\r\n";
                $body .= "Content-Type: image/jpeg\r\n\r\n";
                $body .= $reverseData . "\r\n";
            }

            $body .= "--{$boundary}--\r\n";

            // Make request to AI Service
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $aiServiceUrl . '/recognize',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: multipart/form-data; boundary=' . $boundary,
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $aiTimeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            ]);

            $aiResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // Handle AI Service errors
            if ($aiResponse === false || !empty($curlError)) {
                $this->dbg('ai-service-error', $curlError);
                $this->responseHelper->sendError(503, 'AI Service Unavailable', 'Recognition service temporarily unavailable. Please try again later.');
                return;
            }

            if ($httpCode !== 200) {
                $this->dbg('ai-service-http-error', "HTTP {$httpCode}: {$aiResponse}");
                $this->responseHelper->sendError(502, 'AI Service Error', 'Recognition failed. Please try again.');
                return;
            }

            // Parse AI response
            $aiResult = json_decode($aiResponse, true);
            if (!$aiResult) {
                $this->responseHelper->sendError(502, 'AI Service Error', 'Invalid response from recognition service.');
                return;
            }

            // === SUCCESS - INCREMENT QUOTA ===
            $updateQuery = $db->getQuery(true)
                ->update($db->quoteName('numistr_scan_quota'))
                ->set($db->quoteName('scans_used') . ' = ' . $db->quoteName('scans_used') . ' + 1')
                ->where($db->quoteName('user_id') . ' = ' . (int)$user->id)
                ->where($db->quoteName('month') . ' = ' . $db->quote($currentMonth));
            $db->setQuery($updateQuery);
            $db->execute();

            // Enrich AI results with Joomla data (thumbnail URLs)
            $matches = $aiResult['matches'] ?? [];
            foreach ($matches as &$match) {
                $articleId = (int)($match['variant_id'] ?? $match['article_id'] ?? 0);
                if ($articleId > 0) {
                    // Get thumbnail URL from Joomla
                    $images = $this->getVariantImages($db, $articleId, 0, 1);
                    $match['thumbnail_url'] = !empty($images) ? $images[0]['url'] : null;
                    $match['article_id'] = $articleId;
                }
            }
            unset($match);

            // Calculate reset date
            $resetDate = date('Y-m-01', strtotime('first day of next month'));

            // Build final response with quota info
            $payload = [
                'matches' => $matches,
                'processing_time_ms' => $aiResult['processing_time_ms'] ?? null,
                'ocr_text' => $aiResult['ocr_text'] ?? null,
                'quota' => [
                    'scans_used' => $scansUsed + 1,
                    'scan_limit' => $isPro ? -1 : $freeLimit,
                    'remaining' => $isPro ? -1 : max(0, $remaining - 1),
                    'is_pro' => $isPro,
                    'reset_date' => $resetDate,
                ],
            ];

            $this->responseHelper->sendJson($payload);

        } catch (\Throwable $e) {
            $this->dbg('recognition-exception', $e->getMessage());
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/user/scan-quota
     * Returns user's current scan quota status
     */
    private function handleScanQuota(string $uri): void
    {
        $this->dbg('scan-quota', $uri);

        // Auth required
        $user = $this->requireAuth();

        try {
            $db = Factory::getDbo();
            $isPro = $this->authHelper->hasProSubscription($user);
            $currentMonth = date('Y-m');

            // Get quota config from constants
            $quotaConfig = $this->config['QUOTA'] ?? ['free_limit' => 10, 'pro_limit' => -1];
            $freeLimit = (int)($quotaConfig['free_limit'] ?? 10);
            $proLimit = (int)($quotaConfig['pro_limit'] ?? -1);

            // Get quota record (is_pro her zaman grup üyeliğinden hesaplanır; tablodaki
            // is_pro kolonu yalnızca aylık geçmiş kaydıdır, karar için kullanılmaz)
            $quotaQuery = $db->getQuery(true)
                ->select($db->quoteName('scans_used'))
                ->from($db->quoteName('numistr_scan_quota'))
                ->where($db->quoteName('user_id') . ' = ' . (int)$user->id)
                ->where($db->quoteName('month') . ' = ' . $db->quote($currentMonth));

            $db->setQuery($quotaQuery);
            $quotaRecord = $db->loadAssoc();

            $scansUsed = $quotaRecord ? (int)$quotaRecord['scans_used'] : 0;
            $scanLimit = $isPro ? $proLimit : $freeLimit;
            $remaining = $isPro ? -1 : max(0, $freeLimit - $scansUsed);

            // Calculate reset date (first day of next month)
            $resetDate = date('Y-m-01', strtotime('first day of next month'));

            $payload = [
                'scans_used' => $scansUsed,
                'scan_limit' => $scanLimit, // -1 means unlimited
                'remaining' => $remaining,  // -1 means unlimited
                'is_pro' => $isPro,
                'reset_date' => $resetDate,
            ];

            $this->responseHelper->sendJson($payload);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * POST /v1/university-application
     * Handles university student pro subscription applications
     */
    private function handleUniversityApplication(string $uri): void
    {
        $this->dbg('university-application', $uri);

        // Only POST method allowed
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'POST') {
            $this->responseHelper->sendError(405, 'Method Not Allowed', 'Only POST method is allowed');
            return;
        }

        // Rate limit: 5 applications per hour per IP
        $this->checkRateLimit('university-application', 5);

        try {
            $result = UniversityApplicationHelper::processApplication();

            if ($result['success']) {
                $this->responseHelper->sendJson([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => [
                        'application_id' => $result['application_id']
                    ]
                ]);
            } else {
                $this->responseHelper->sendError(
                    400,
                    $result['error'],
                    $result['message']
                );
            }

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/articles?page=&limit=&category_id=
     * Yayındaki blog makalelerini sayfalı listeler (mobil blog listesi).
     * Yanıt öğeleri mobil Article modeliyle birebir:
     * id, title, intro, category, category_id, created, modified
     */
    private function handleArticlesList(string $uri): void
    {
        $this->dbg('articles-list', $uri);

        try {
            $db = Factory::getDbo();
            $app = Factory::getApplication();
            $blogCatId = 8; // Blog category ID

            $page  = max(1, (int)$app->input->get('page', 1));
            $limit = (int)$app->input->get('limit', 20);
            $limit = max(1, min(50, $limit));
            $categoryId = (int)$app->input->get('category_id', 0);

            // Blog kategorisi + alt kategorileri
            $catQuery = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('parent_id') . ' = ' . (int)$blogCatId)
                ->where($db->quoteName('published') . ' = 1');
            $db->setQuery($catQuery);
            $subCatIds = $db->loadColumn() ?: [];
            $allCatIds = array_merge([$blogCatId], array_map('intval', $subCatIds));

            // category_id verilmişse blog ağacının içinde olmalı
            if ($categoryId > 0) {
                if (!in_array($categoryId, $allCatIds, true)) {
                    $this->responseHelper->sendJson(['data' => [], 'meta' => ['page' => $page, 'limit' => $limit, 'total' => 0]]);
                    return;
                }
                $filterCatIds = [$categoryId];
            } else {
                $filterCatIds = $allCatIds;
            }

            $catIdsSql = implode(',', array_map('intval', $filterCatIds));

            $countQuery = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('catid') . ' IN (' . $catIdsSql . ')')
                ->where($db->quoteName('state') . ' = 1');
            $db->setQuery($countQuery);
            $total = (int)$db->loadResult();

            $listQuery = $db->getQuery(true)
                ->select([
                    $db->quoteName('c.id'),
                    $db->quoteName('c.title'),
                    $db->quoteName('c.introtext'),
                    $db->quoteName('c.catid'),
                    $db->quoteName('c.created'),
                    $db->quoteName('c.modified'),
                    $db->quoteName('cat.title', 'category_name'),
                ])
                ->from($db->quoteName('#__content', 'c'))
                ->join('LEFT', $db->quoteName('#__categories', 'cat')
                    . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.catid'))
                ->where($db->quoteName('c.catid') . ' IN (' . $catIdsSql . ')')
                ->where($db->quoteName('c.state') . ' = 1')
                ->order($db->quoteName('c.created') . ' DESC')
                ->setLimit($limit, ($page - 1) * $limit);
            $db->setQuery($listQuery);
            $rows = $db->loadAssocList() ?: [];

            $items = [];
            foreach ($rows as $row) {
                $intro = strip_tags($row['introtext'] ?? '');
                $intro = mb_substr($intro, 0, 200, 'UTF-8');
                if (mb_strlen(strip_tags($row['introtext'] ?? ''), 'UTF-8') > 200) {
                    $intro .= '...';
                }

                $items[] = [
                    'id'          => (int)$row['id'],
                    'title'       => $row['title'],
                    'intro'       => $intro,
                    'category'    => $row['category_name'] ?: 'Blog',
                    'category_id' => (int)$row['catid'],
                    'created'     => $row['created'],
                    'modified'    => ($row['modified'] && $row['modified'] !== '0000-00-00 00:00:00')
                        ? $row['modified'] : null,
                ];
            }

            $this->responseHelper->sendJson([
                'data' => $items,
                'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
            ]);
        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/articles/featured
     * Returns daily rotating featured article from blog category (ID=8)
     */
    private function handleArticlesFeatured(string $uri): void
    {
        $this->dbg('articles-featured', $uri);

        try {
            $db = Factory::getDbo();
            $blogCatId = 8; // Blog category ID

            // Get all subcategories of blog category
            $catQuery = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('parent_id') . ' = ' . (int)$blogCatId)
                ->where($db->quoteName('published') . ' = 1');

            $db->setQuery($catQuery);
            $subCatIds = $db->loadColumn() ?: [];

            // Include parent blog category too
            $allCatIds = array_merge([$blogCatId], $subCatIds);

            if (empty($allCatIds)) {
                $this->responseHelper->sendError(404, 'No blog categories found');
                return;
            }

            $catIdsSql = implode(',', array_map('intval', $allCatIds));

            // Get total count of published articles
            $countQuery = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('catid') . ' IN (' . $catIdsSql . ')')
                ->where($db->quoteName('state') . ' = 1');

            $db->setQuery($countQuery);
            $totalArticles = (int)$db->loadResult();

            if ($totalArticles === 0) {
                $this->responseHelper->sendError(404, 'No articles found');
                return;
            }

            // Daily rotation: use date as seed for consistent daily selection
            $today = date('Y-m-d');
            $seed = crc32($today);
            $offset = $seed % $totalArticles;

            // Get the featured article
            $articleQuery = $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('title'),
                    $db->quoteName('alias'),
                    $db->quoteName('introtext'),
                    $db->quoteName('fulltext'),
                    $db->quoteName('catid'),
                    $db->quoteName('created'),
                    $db->quoteName('modified'),
                ])
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('catid') . ' IN (' . $catIdsSql . ')')
                ->where($db->quoteName('state') . ' = 1')
                ->order($db->quoteName('id') . ' ASC')
                ->setLimit(1, $offset);

            $db->setQuery($articleQuery);
            $article = $db->loadAssoc();

            if (!$article) {
                $this->responseHelper->sendError(404, 'Article not found');
                return;
            }

            // Get category name
            $catNameQuery = $db->getQuery(true)
                ->select('title')
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('id') . ' = ' . (int)$article['catid']);
            $db->setQuery($catNameQuery);
            $categoryName = $db->loadResult() ?: 'Blog';

            // Strip HTML and limit intro text to 150 characters
            $intro = strip_tags($article['introtext'] ?? '');
            $intro = mb_substr($intro, 0, 150, 'UTF-8');
            if (mb_strlen($intro, 'UTF-8') >= 150) {
                $intro .= '...';
            }

            $payload = [
                'data' => [
                    'id' => (int)$article['id'],
                    'title' => $article['title'],
                    'intro' => $intro,
                    'category' => $categoryName,
                    'category_id' => (int)$article['catid'],
                    'created' => $article['created'],
                ]
            ];

            $this->responseHelper->sendJson($payload);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/articles/categories
     * Returns list of blog categories
     */
    private function handleArticlesCategories(string $uri): void
    {
        $this->dbg('articles-categories', $uri);

        try {
            $db = Factory::getDbo();
            $blogCatId = 8; // Blog category ID

            // Get all subcategories
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('id'),
                    $db->quoteName('title'),
                    $db->quoteName('alias'),
                    $db->quoteName('description'),
                ])
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('parent_id') . ' = ' . (int)$blogCatId)
                ->where($db->quoteName('published') . ' = 1')
                ->order($db->quoteName('lft') . ' ASC');

            $db->setQuery($query);
            $categories = $db->loadAssocList() ?: [];

            $data = array_map(function($cat) {
                return [
                    'id' => (int)$cat['id'],
                    'name' => $cat['title'],
                    'slug' => $cat['alias'],
                    'description' => strip_tags($cat['description'] ?? ''),
                ];
            }, $categories);

            $this->responseHelper->sendJson(['data' => $data]);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }

    /**
     * GET /v1/articles/{id}
     * Returns full article content
     */
    private function handleArticleDetail(string $uri, int $articleId): void
    {
        $this->dbg('article-detail', $uri);

        try {
            $db = Factory::getDbo();
            $blogCatId = 8;

            // Get article
            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('ct.id'),
                    $db->quoteName('ct.title'),
                    $db->quoteName('ct.alias'),
                    $db->quoteName('ct.introtext'),
                    $db->quoteName('ct.fulltext'),
                    $db->quoteName('ct.catid'),
                    $db->quoteName('ct.created'),
                    $db->quoteName('ct.modified'),
                    $db->quoteName('cat.title', 'category_name'),
                ])
                ->from($db->quoteName('#__content', 'ct'))
                ->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('ct.catid'))
                ->where($db->quoteName('ct.id') . ' = ' . (int)$articleId)
                ->where($db->quoteName('ct.state') . ' = 1');

            $db->setQuery($query);
            $article = $db->loadAssoc();

            if (!$article) {
                $this->responseHelper->sendError(404, 'Article not found');
                return;
            }

            // Verify it's a blog article
            $catCheckQuery = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__categories'))
                ->where('(' . $db->quoteName('id') . ' = ' . (int)$article['catid'] .
                       ' OR ' . $db->quoteName('parent_id') . ' = ' . (int)$blogCatId . ')')
                ->where($db->quoteName('published') . ' = 1');

            $db->setQuery($catCheckQuery);
            $isBlogArticle = (int)$db->loadResult();

            if (!$isBlogArticle) {
                $this->responseHelper->sendError(404, 'Article not found');
                return;
            }

            // Combine intro and full text
            $content = trim($article['introtext'] ?? '') . "\n\n" . trim($article['fulltext'] ?? '');
            $content = trim($content);

            $payload = [
                'data' => [
                    'id' => (int)$article['id'],
                    'title' => $article['title'],
                    'content' => $content, // Full HTML content
                    'category' => $article['category_name'] ?? 'Blog',
                    'category_id' => (int)$article['catid'],
                    'created' => $article['created'],
                    'modified' => $article['modified'],
                ]
            ];

            $this->responseHelper->sendJson($payload);

        } catch (\Throwable $e) {
            $this->responseHelper->sendError(500, 'Internal server error', $e->getMessage());
        }
    }
}