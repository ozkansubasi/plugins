<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;

// Helper sınıflarını yükle
require_once __DIR__ . '/helpers/AuthHelper.php';
require_once __DIR__ . '/helpers/DatabaseHelper.php';
require_once __DIR__ . '/helpers/ResponseHelper.php';
require_once __DIR__ . '/helpers/RateLimiter.php';

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
                        ON CAST(fv.item_id AS UNSIGNED) = v.article_id 
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
                    $this->responseHelper->sendError(400, 'Query too broad', 'filter[material] tek başına kullanılamaz.');
                    return;
                }
                if ($regionF !== '' && $mintF === '' && $authorityF === '' && $yearFromF === null && $yearToF === null) {
                    $this->responseHelper->sendError(400, 'Query too broad', 'filter[region] tek başına kullanılamaz.');
                    return;
                }
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
                  ->join('LEFT', $fvTbl('fv_mat') . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = ' . $db->quoteName('v.article_id') 
                    . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
                $qCount->join('LEFT', $fvTbl('fv_mat') . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = ' . $db->quoteName('v.article_id') 
                    . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
            } else { 
                $q->select('NULL AS ' . $db->quoteName('material_value')); 
            }

            if ($mintF !== '' && $mintFieldId !== null) {
                $q->select($db->quoteName('fv_mint.value', 'mint_value'))
                  ->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = ' . $db->quoteName('v.article_id') 
                    . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                $qCount->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = ' . $db->quoteName('v.article_id') 
                    . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
            } else { 
                $q->select('NULL AS ' . $db->quoteName('mint_value')); 
            }

            if ($authorityF !== '' && $authFieldId !== null) {
                $q->select($db->quoteName('fv_auth.value', 'authority_value'))
                  ->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = ' . $db->quoteName('v.article_id') 
                    . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                $qCount->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = ' . $db->quoteName('v.article_id') 
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
                $qBase->join('LEFT', $fvTbl('fv_mat') . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = ' . $db->quoteName('v.article_id') 
                    . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
            }
            if ($mintFieldId !== null) {
                $qBase->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = ' . $db->quoteName('v.article_id') 
                    . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
            }
            if ($authFieldId !== null) {
                $qBase->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = ' . $db->quoteName('v.article_id') 
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
                $q->join('LEFT', $fvTbl('fv_mint') . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = ' . $db->quoteName('v.article_id')
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
                $q->join('LEFT', $fvTbl('fv_auth') . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = ' . $db->quoteName('v.article_id')
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
            $obverseFieldId = $this->dbHelper->fid('obverse_desc_tr');   // ✅ Türkçe tercih
            $reverseFieldId = $this->dbHelper->fid('reverse_desc_tr');   // ✅ Türkçe tercih
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
                      . ' ON ' . $db->quoteName('fv_mat.item_id') . ' = ' . $db->quoteName('v.article_id')
                      . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('material_value'));
            }

            if ($mintFieldId !== null) {
                $q->select($db->quoteName('fv_mint.value', 'mint_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_mint')
                      . ' ON ' . $db->quoteName('fv_mint.item_id') . ' = ' . $db->quoteName('v.article_id')
                      . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('mint_value'));
            }

            if ($authFieldId !== null) {
                $q->select($db->quoteName('fv_auth.value', 'authority_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_auth')
                      . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = ' . $db->quoteName('v.article_id')
                      . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('authority_value'));
            }

            if ($dateFromFieldId !== null) {
                $q->select($db->quoteName('fv_dfrom.value', 'date_from_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_dfrom')
                      . ' ON ' . $db->quoteName('fv_dfrom.item_id') . ' = ' . $db->quoteName('v.article_id')
                      . ' AND ' . $db->quoteName('fv_dfrom.field_id') . ' = ' . (int)$dateFromFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('date_from_value'));
            }

            if ($dateToFieldId !== null) {
                $q->select($db->quoteName('fv_dto.value', 'date_to_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_dto')
                      . ' ON ' . $db->quoteName('fv_dto.item_id') . ' = ' . $db->quoteName('v.article_id')
                      . ' AND ' . $db->quoteName('fv_dto.field_id') . ' = ' . (int)$dateToFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('date_to_value'));
            }

            if ($obverseFieldId !== null) {
                $q->select($db->quoteName('fv_obv.value', 'obverse_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_obv')
                      . ' ON ' . $db->quoteName('fv_obv.item_id') . ' = ' . $db->quoteName('v.article_id')
                      . ' AND ' . $db->quoteName('fv_obv.field_id') . ' = ' . (int)$obverseFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('obverse_value'));
            }

            if ($reverseFieldId !== null) {
                $q->select($db->quoteName('fv_rev.value', 'reverse_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_rev')
                      . ' ON ' . $db->quoteName('fv_rev.item_id') . ' = ' . $db->quoteName('v.article_id')
                      . ' AND ' . $db->quoteName('fv_rev.field_id') . ' = ' . (int)$reverseFieldId);
            } else {
                $q->select('NULL AS ' . $db->quoteName('reverse_value'));
            }

            if ($coordsFieldId !== null) {
                $q->select($db->quoteName('fv_coords.value', 'coordinates_value'))
                  ->join('LEFT', $db->quoteName($fvTableName, 'fv_coords')
                      . ' ON ' . $db->quoteName('fv_coords.item_id') . ' = ' . $db->quoteName('v.article_id')
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
                'obverse_desc' => $r['obverse_value'] ?? $r['obverse_desc_tr'] ?? $r['obverse_desc'] ?? null,
                'reverse_desc' => $r['reverse_value'] ?? $r['reverse_desc_tr'] ?? $r['reverse_desc'] ?? null,
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
            ])
            ->from($imgTbl)
            ->where($db->quoteName('ci.coin_id') . ' = ' . (int)$variantId)
            ->order($db->quoteName('ci.ordering') . ' ASC');
        $db->setQuery($q);
        $rows = $db->loadAssocList() ?: [];

        $data = [];
        foreach ($rows as $r) {
            $imageId = (int)($r['image_id'] ?? 0);
            if ($imageId <= 0) { continue; }
            $item = [
                'image_id' => $imageId,
                'variant_id' => (int)($r['coin_id'] ?? $variantId),
                'type' => $r['image_type'] ?? null,
                'weight' => $r['weight'] ?? null,
                'diameter' => $r['diameter'] ?? null,
                'ordering' => isset($r['ordering']) ? (int)$r['ordering'] : null,
                'url' => $this->buildImageUrl($imageId, $wmPref, $abs),
                'url_raw' => $this->buildImageUrl($imageId, 0, $abs),
            ];
            $data[] = $item;
        }
        return $data;
    }
}