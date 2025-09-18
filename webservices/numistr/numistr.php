<?php
\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

class PlgWebservicesNumistr extends CMSPlugin
{
    /** "Sikkeler" kök kategori id (cat=16) */
    private const ROOT_CAT_ID = 16;

    /** Geniş sorgularda sonuç seti üst sınırı */
    private const SAFE_CAP = 2000;

    /** Tüm custom field ID'leri */
    private const FIELD_ID = [
        'material'            => 23,
        'mint_name'           => 4,
        'authority_name'      => 2,
        'coin_id'             => 27,
        'authority_uri'       => 3,
        'mint_uri'            => 5,
        'denomination_name'   => 6,
        'denomination_uri'    => 7,
        'obverse_desc'        => 30,
        'obverse_desc_tr'     => 32,
        'reverse_desc'        => 31,
        'reverse_desc_tr'     => 33,
        'findspot_name'       => 12,
        'findspot_uri'        => 13,
        'coordinates'         => 15,
        'start_date'          => 25,
        'end_date'            => 26,
        'source_citation'     => 18,
        'image_gallery_urls'  => 35,
    ];

    /** Mat tablo varsa onu kullan, yoksa view */
    private function resolveVariantsSourceTable($db): string
    {
        try {
            $db->setQuery("SHOW TABLES LIKE " . $db->quote('o_numistr_variants_public_mat'));
            $has = (string)$db->loadResult();
            return $db->quoteName($has !== '' ? 'o_numistr_variants_public_mat' : 'o_numistr_variants_public', 'v');
        } catch (\Throwable $e) {
            return $db->quoteName('o_numistr_variants_public', 'v');
        }
    }

    public function onBeforeApiRoute($event): void
    {
        $app = Factory::getApplication();
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // ---- ping
        if (strpos($uri, '/v1/ping') !== false) {
            $this->sendJson(['ok' => true, 'pong' => time()]);
            return;
        }

        // ===================== LIST: /v1/variants ======================
        if ($this->isVariantsIndex($uri)) {
            try {
                $db   = Factory::getDbo();
                $vTbl = $this->resolveVariantsSourceTable($db);
                $cTbl = $db->quoteName('#__content', 'ct');

                // params
                $perPage = max(1, min((int)$app->input->get('per_page', 20), 100));
                $page    = max(1, (int)$app->input->get('page', 1));
                $offset  = ($page - 1) * $perPage;

                $modeParam  = strtolower((string)$app->input->get('mode', ''));
                $onlyParam  = strtolower((string)$app->input->get('only', ''));
                $countOnly  = ($modeParam === 'count' || $onlyParam === 'count');

                $sortParam  = strtolower((string)$app->input->get('sort', 'uid_asc'));

                $filter        = (array)$app->input->get('filter', [], 'ARRAY');
                $regionF       = isset($filter['region'])      ? trim((string)$filter['region'])      : '';
                $materialF     = isset($filter['material'])    ? trim((string)$filter['material'])    : '';
                $mintF         = isset($filter['mint'])        ? trim((string)$filter['mint'])        : '';
                $authorityF    = isset($filter['authority'])   ? trim((string)$filter['authority'])   : '';
                $yearFromF     = isset($filter['year_from'])   ? (int)$filter['year_from']            : null;
                $yearToF       = isset($filter['year_to'])     ? (int)$filter['year_to']              : null;
                $hasImagesFStr = isset($filter['has_images'])  ? strtolower((string)$filter['has_images']) : '';
                $hasImagesF    = in_array($hasImagesFStr, ['1','true','yes'], true);

                // Guardrails
                if (!$countOnly) {
                    if ($materialF !== '' && $mintF === '' && $authorityF === '' && $regionF === '' && $yearFromF === null && $yearToF === null) {
                        $this->sendError(400, 'Query too broad',
                            'filter[material] tek başına kullanılamaz. Lütfen filter[mint] veya filter[authority] veya filter[year_from]/[year_to] ekleyin.');
                        return;
                    }
                    if ($regionF !== '' && $mintF === '' && $authorityF === '' && $yearFromF === null && $yearToF === null) {
                        $this->sendError(400, 'Query too broad',
                            'filter[region] tek başına kullanılamaz. Lütfen filter[mint] veya filter[authority] veya filter[year_from]/[year_to] ekleyin.');
                        return;
                    }
                }

                // allowed categories
                $allowedCatIds = $this->getAllowedCatIds($db, self::ROOT_CAT_ID);
                if (empty($allowedCatIds)) {
                    $this->sendJson([
                        'data'=>[], 'meta'=>['total'=>0,'page'=>1,'per_page'=>$perPage], 'links'=>['next'=>null,'prev'=>null]
                    ]);
                    return;
                }
                $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

                // fields table
                $fvTableName = $this->resolveFieldsValuesTable($db);
                $fvTbl       = fn(string $alias) => $db->quoteName($fvTableName, $alias);

                // field ids
                $matFieldId  = $this->fid('material');
                $mintFieldId = $this->fid('mint_name');
                $authFieldId = $this->fid('authority_name');

                // base query
                $qBase = $db->getQuery(true)
                    ->from($vTbl)
                    ->join(
                        'INNER',
                        $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                        . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                        . ' AND ' . $db->quoteName('ct.state') . ' = 1'
                    );

                // COUNT
                $qCount = clone $qBase;
                $qCount->clear('select')->select('COUNT(*)');

                // SELECT
                $q = clone $qBase;
                $q->clear('select')->select('v.*');

                // joins (material/mint/authority)
                if ($matFieldId !== null) {
                    $q->select($db->quoteName('fv_mat.value', 'material_value'))
                      ->join('LEFT', $fvTbl('fv_mat') . ' ON CAST(' . $db->quoteName('fv_mat.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
                    $qCount->join('LEFT', $fvTbl('fv_mat') . ' ON CAST(' . $db->quoteName('fv_mat.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
                } else { $q->select('NULL AS ' . $db->quoteName('material_value')); }

                if ($mintFieldId !== null) {
                    $q->select($db->quoteName('fv_mint.value', 'mint_value'))
                      ->join('LEFT',  $fvTbl('fv_mint') . ' ON CAST(' . $db->quoteName('fv_mint.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                    $qCount->join('LEFT',  $fvTbl('fv_mint') . ' ON CAST(' . $db->quoteName('fv_mint.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                } else { $q->select('NULL AS ' . $db->quoteName('mint_value')); }

                if ($authFieldId !== null) {
                    $q->select($db->quoteName('fv_auth.value', 'authority_value'))
                      ->join('LEFT', $fvTbl('fv_auth') . ' ON CAST(' . $db->quoteName('fv_auth.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                    $qCount->join('LEFT', $fvTbl('fv_auth') . ' ON CAST(' . $db->quoteName('fv_auth.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                } else { $q->select('NULL AS ' . $db->quoteName('authority_value')); }

                // ----- filtreler -----
                if ($materialF !== '') {
                    $normKey = $this->normalizeMaterialKey($materialF);
                    if ($normKey !== null) {
                        $variants = $this->dbMaterialVariantsFor($normKey);
                        $ins = array_map(fn($m) => $db->quote(mb_strtolower($m, 'UTF-8')), $variants);
                        $coalesceMat = ($matFieldId !== null)
                            ? 'LOWER(COALESCE(' . $db->quoteName('v.metal') . ', ' . $db->quoteName('fv_mat.value') . '))'
                            : 'LOWER(' . $db->quoteName('v.metal') . ')';
                        $q->where($coalesceMat . ' IN (' . implode(',', $ins) . ')');
                        $qCount->where($coalesceMat . ' IN (' . implode(',', $ins) . ')');
                    } else {
                        $this->sendJson(['data'=>[], 'meta'=>['total'=>0,'page'=>1,'per_page'=>$perPage], 'links'=>['next'=>null,'prev'=>null]]);
                        return;
                    }
                }

                if ($mintF !== '') {
                    $hasWild = (strpos($mintF, '%') !== false || strpos($mintF, '_') !== false);
                    if ($hasWild) {
                        $mintLike = '%' . mb_strtolower($mintF, 'UTF-8') . '%';
                        $coalesceMint = ($mintFieldId !== null)
                            ? 'LOWER(COALESCE(' . $db->quoteName('v.mint_name') . ', ' . $db->quoteName('fv_mint.value') . '))'
                            : 'LOWER(' . $db->quoteName('v.mint_name') . ')';
                        $q->where($coalesceMint . ' LIKE ' . $db->quote($mintLike));
                        $qCount->where($coalesceMint . ' LIKE ' . $db->quote($mintLike));
                    } else {
                        $mintEq = $db->quote($mintF);
                        if ($mintFieldId !== null) {
                            $q->where('(' . $db->quoteName('v.mint_name') . ' = ' . $mintEq . ' OR ' . $db->quoteName('fv_mint.value') . ' = ' . $mintEq . ')');
                            $qCount->where('(' . $db->quoteName('v.mint_name') . ' = ' . $mintEq . ' OR ' . $db->quoteName('fv_mint.value') . ' = ' . $mintEq . ')');
                        } else {
                            $q->where($db->quoteName('v.mint_name') . ' = ' . $mintEq);
                            $qCount->where($db->quoteName('v.mint_name') . ' = ' . $mintEq);
                        }
                    }
                }

                if ($authorityF !== '' && $authFieldId !== null) {
                    $authLike = '%' . mb_strtolower($authorityF, 'UTF-8') . '%';
                    $expr = 'LOWER(' . $db->quoteName('fv_auth.value') . ')';
                    $q->where($expr . ' LIKE ' . $db->quote($authLike));
                    $qCount->where($expr . ' LIKE ' . $db->quote($authLike));
                }

                if ($regionF !== '') {
                    $q->where($db->quoteName('v.region_code') . ' = ' . $db->quote($regionF));
                    $qCount->where($db->quoteName('v.region_code') . ' = ' . $db->quote($regionF));
                }

                if ($yearFromF !== null || $yearToF !== null) {
                    $lhsFrom = $db->quoteName('v.date_from');
                    $lhsTo   = $db->quoteName('v.date_to');
                    $yf = $yearFromF ?? $yearToF;
                    $yt = $yearToF   ?? $yearFromF;
                    $q->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
                    $q->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
                    $qCount->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
                    $qCount->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
                }

                if ($hasImagesF) {
                    $existsSql = 'EXISTS (SELECT 1 FROM ' . $db->quoteName('coins_images', 'ci') . ' WHERE ' . $db->quoteName('ci.coin_id') . ' = ' . $db->quoteName('v.article_id') . ')';
                    $q->where($existsSql);
                    $qCount->where($existsSql);
                }

                // ----- total -----
                $db->setQuery($qCount);
                $total = (int)$db->loadResult();

                if ($countOnly) {
                    $this->sendJson([
                        'data'  => [],
                        'meta'  => ['total' => $total, 'page' => 1, 'per_page' => 0, 'total_pages' => 0, 'mode' => 'count'],
                        'links' => ['next'=>null,'prev'=>null],
                    ]);
                    return;
                }

                $hasNarrower = ($mintF !== '' || $authorityF !== '' || $yearFromF !== null || $yearToF !== null);
                if ($total > self::SAFE_CAP && !$hasNarrower) {
                    $this->sendError(422, 'Result too large',
                        'Sonuç kümesi çok geniş (' . $total . '). Lütfen filter[mint] veya filter[authority] veya filter[year_from]/[year_to] ekleyin ya da mode=count kullanın.');
                    return;
                }

                // ----- sıralama -----
                $orderClause = $db->quoteName('v.uid') . ' ASC';
                switch ($sortParam) {
                    case 'updated_at_desc':
                        $orderClause = $db->quoteName('v.updated_at') . ' DESC, ' . $db->quoteName('v.uid') . ' ASC'; break;
                    case 'updated_at_asc':
                        $orderClause = $db->quoteName('v.updated_at') . ' ASC, ' . $db->quoteName('v.uid') . ' ASC'; break;
                    case 'uid_desc':
                        $orderClause = $db->quoteName('v.uid') . ' DESC'; break;
                    case 'uid_asc':
                    default:
                        $orderClause = $db->quoteName('v.uid') . ' ASC'; break;
                }

                $q->order($orderClause)->setLimit($perPage, $offset);
                $db->setQuery($q);
                $rows = $db->loadAssocList() ?: [];

                $totalPages = (int)ceil($total / $perPage);
                $next = null; $prev = null;
                if ($offset + $perPage < $total) { $next = $page + 1; }
                if ($page > 1) { $prev = $page - 1; }

                $this->sendJson([
                    'data'  => $rows,
                    'meta'  => [
                        'total' => $total,
                        'page' => $page,
                        'per_page' => $perPage,
                        'total_pages' => $totalPages,
                        'sort' => $sortParam
                    ],
                    'links' => [
                        'next' => $next ? '/v1/variants?page=' . $next . '&per_page=' . $perPage : null,
                        'prev' => $prev ? '/v1/variants?page=' . $prev . '&per_page=' . $perPage : null
                    ],
                ]);
                return;

            } catch (\Throwable $e) {
                $this->sendError(500, 'Internal server error', $e->getMessage());
                return;
            }
        }

        // ===================== FACETS: /v1/variants/facets ==================
        if (preg_match('~/v1/variants/facets(?:[/?#?]|$)~', $uri)) {
            try {
                $db   = Factory::getDbo();
                $vTbl = $this->resolveVariantsSourceTable($db);
                $cTbl = $db->quoteName('#__content', 'ct');

                $filter        = (array)$app->input->get('filter', [], 'ARRAY');
                $regionF       = isset($filter['region'])     ? trim((string)$filter['region'])     : '';
                $materialF     = isset($filter['material'])   ? trim((string)$filter['material'])   : '';
                $mintF         = isset($filter['mint'])       ? trim((string)$filter['mint'])       : '';
                $authorityF    = isset($filter['authority'])  ? trim((string)$filter['authority'])  : '';
                $yearFromF     = isset($filter['year_from'])  ? (int)$filter['year_from']           : null;
                $yearToF       = isset($filter['year_to'])    ? (int)$filter['year_to']             : null;

                $facetLimit    = max(1, min((int)$app->input->get('facet_limit', 15), 100));
                $yearsBucket   = max(1, min((int)$app->input->get('years_bucket', 50), 500));

                $skipParam = strtolower((string)$app->input->get('skip', ''));
                $skip = array_filter(array_map('trim', explode(',', $skipParam)));
                $skipSet = fn(string $key) => in_array($key, $skip, true);

                $allowedCatIds = $this->getAllowedCatIds($db, self::ROOT_CAT_ID);
                if (empty($allowedCatIds)) {
                    $this->sendJson(['meta'=>['total'=>0, 'years_bucket'=>$yearsBucket], 'facets'=>['mint'=>[], 'authority'=>[], 'material'=>[], 'years'=>[]]]);
                    return;
                }
                $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

                $fvTableName = $this->resolveFieldsValuesTable($db);
                $fvTbl       = fn(string $alias) => $db->quoteName($fvTableName, $alias);
                $matFieldId  = $this->fid('material');
                $mintFieldId = $this->fid('mint_name');
                $authFieldId = $this->fid('authority_name');

                $qBase = $db->getQuery(true)
                    ->from($vTbl)
                    ->join(
                        'INNER',
                        $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                        . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                        . ' AND ' . $db->quoteName('ct.state') . ' = 1'
                    );

                $qCount = clone $qBase;
                $qCount->clear('select')->select('COUNT(*)');

                // ortak filtreler
                if ($regionF !== '') {
                    $qBase->where($db->quoteName('v.region_code') . ' = ' . $db->quote($regionF));
                    $qCount->where($db->quoteName('v.region_code') . ' = ' . $db->quote($regionF));
                }
                if ($yearFromF !== null || $yearToF !== null) {
                    $lhsFrom = $db->quoteName('v.date_from');
                    $lhsTo   = $db->quoteName('v.date_to');
                    $yf = $yearFromF ?? $yearToF;
                    $yt = $yearToF   ?? $yearFromF;
                    $qBase->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
                    $qBase->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
                    $qCount->where("($lhsTo IS NULL OR $lhsTo >= " . (int)$yf . ')');
                    $qCount->where("($lhsFrom IS NULL OR $lhsFrom <= " . (int)$yt . ')');
                }
                // mint filtresi
                if ($mintF !== '') {
                    $hasWild = (strpos($mintF, '%') !== false || strpos($mintF, '_') !== false);
                    if ($hasWild) {
                        if ($mintFieldId !== null) {
                            $coalesceMint = 'LOWER(COALESCE(' . $db->quoteName('v.mint_name') . ', ' . $db->quoteName('fv_mint.value') . '))';
                            $mintLike = '%' . mb_strtolower($mintF, 'UTF-8') . '%';
                            $qBase->join('LEFT',  $fvTbl('fv_mint') . ' ON CAST(' . $db->quoteName('fv_mint.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                            $qCount->join('LEFT', $fvTbl('fv_mint') . ' ON CAST(' . $db->quoteName('fv_mint.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                            $qBase->where($coalesceMint . ' LIKE ' . $db->quote($mintLike));
                            $qCount->where($coalesceMint . ' LIKE ' . $db->quote($mintLike));
                        } else {
                            $qBase->where('LOWER(' . $db->quoteName('v.mint_name') . ') LIKE ' . $db->quote('%' . mb_strtolower($mintF, 'UTF-8') . '%'));
                            $qCount->where('LOWER(' . $db->quoteName('v.mint_name') . ') LIKE ' . $db->quote('%' . mb_strtolower($mintF, 'UTF-8') . '%'));
                        }
                    } else {
                        $qBase->where($db->quoteName('v.mint_name') . ' = ' . $db->quote($mintF));
                        $qCount->where($db->quoteName('v.mint_name') . ' = ' . $db->quote($mintF));
                    }
                }
                // material filtresi
                if ($materialF !== '') {
                    $normKey = $this->normalizeMaterialKey($materialF);
                    if ($normKey !== null) {
                        $ins = array_map(fn($m) => $db->quote(mb_strtolower($m, 'UTF-8')), $this->dbMaterialVariantsFor($normKey));
                        $matExpr = 'LOWER(' . $db->quoteName('v.metal') . ')';
                        $qBase->where($matExpr . ' IN (' . implode(',', $ins) . ')');
                        $qCount->where($matExpr . ' IN (' . implode(',', $ins) . ')');
                    } else {
                        $this->sendJson(['meta'=>['total'=>0,'years_bucket'=>$yearsBucket], 'facets'=>['mint'=>[], 'authority'=>[], 'material'=>[], 'years'=>[]]]);
                        return;
                    }
                }
                // authority filtresi
                if ($authorityF !== '' && $authFieldId !== null) {
                    $qBase->join('LEFT', $fvTbl('fv_auth') . ' ON CAST(' . $db->quoteName('fv_auth.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                    $qCount->join('LEFT', $fvTbl('fv_auth') . ' ON CAST(' . $db->quoteName('fv_auth.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                    $authLike = '%' . mb_strtolower($authorityF, 'UTF-8') . '%';
                    $qBase->where('LOWER(' . $db->quoteName('fv_auth.value') . ') LIKE ' . $db->quote($authLike));
                    $qCount->where('LOWER(' . $db->quoteName('fv_auth.value') . ') LIKE ' . $db->quote($authLike));
                }

                // only=meta
                $onlyParam = strtolower((string)$app->input->get('only', ''));
                if ($onlyParam === 'meta') {
                    $db->setQuery($qCount);
                    $total = (int)$db->loadResult();
                    $this->sendJson([
                        'meta'   => ['total' => $total, 'years_bucket'=>$yearsBucket],
                        'facets' => ['mint'=>[], 'authority'=>[], 'material'=>[], 'years'=>[]]
                    ]);
                    return;
                }

                // total
                $db->setQuery($qCount);
                $total = (int)$db->loadResult();

                // facets: mint
                $mintRows = [];
                if (!$skipSet('mint')) {
                    $coalesceMint = ($mintFieldId !== null)
                        ? 'COALESCE(' . $db->quoteName('v.mint_name') . ', ' . $db->quoteName('fv_mint.value') . ')'
                        : $db->quoteName('v.mint_name');
                    $qMint = clone $qBase;
                    if ($mintFieldId !== null) {
                        $qMint->join('LEFT',  $fvTbl('fv_mint') . ' ON CAST(' . $db->quoteName('fv_mint.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                    }
                    $qMint->clear('select')
                          ->select('LOWER(' . $coalesceMint . ') AS name')
                          ->select('COUNT(*) AS cnt')
                          ->where('(' . $coalesceMint . ' IS NOT NULL AND ' . $coalesceMint . " <> '')")
                          ->group('LOWER(' . $coalesceMint . ')')
                          ->order('cnt DESC, name ASC')
                          ->setLimit($facetLimit);
                    $db->setQuery($qMint);
                    $mintRows = $db->loadAssocList() ?: [];
                }

                // facets: authority
                $authRows = [];
                if (!$skipSet('authority') && $authFieldId !== null) {
                    $authExpr = $db->quoteName('fv_auth.value');
                    $qAuth = clone $qBase;
                    $qAuth->join('LEFT', $fvTbl('fv_auth') . ' ON CAST(' . $db->quoteName('fv_auth.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                    $qAuth->clear('select')
                          ->select('LOWER(' . $authExpr . ') AS name')
                          ->select('COUNT(*) AS cnt')
                          ->where('(' . $authExpr . ' IS NOT NULL AND ' . $authExpr . " <> '')")
                          ->group('LOWER(' . $authExpr . ')')
                          ->order('cnt DESC, name ASC')
                          ->setLimit($facetLimit);
                    $db->setQuery($qAuth);
                    $authRows = $db->loadAssocList() ?: [];
                }

                // facets: material
                $matRows = [];
                if (!$skipSet('material')) {
                    $coalesceMat = ($matFieldId !== null)
                        ? 'LOWER(COALESCE(' . $db->quoteName('v.metal') . ', ' . $db->quoteName('fv_mat.value') . '))'
                        : 'LOWER(' . $db->quoteName('v.metal') . ')';
                    $qMat = clone $qBase;
                    if ($matFieldId !== null) {
                        $qMat->join('LEFT', $fvTbl('fv_mat') . ' ON CAST(' . $db->quoteName('fv_mat.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id') . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId);
                    }
                    $qMat->clear('select')
                         ->select($coalesceMat . ' AS name')
                         ->select('COUNT(*) AS cnt')
                         ->where('(' . $coalesceMat . ' IS NOT NULL AND ' . $coalesceMat . " <> '')")
                         ->group($coalesceMat)
                         ->order('cnt DESC, name ASC')
                         ->setLimit($facetLimit);
                    $db->setQuery($qMat);
                    $matRows = $db->loadAssocList() ?: [];
                }

                // facets: years (numeric-safe)
                $years = [];
                if (!$skipSet('years')) {
                    $dFromSafe = 'CASE WHEN ' . $db->quoteName('v.date_from') . " REGEXP '^-?[0-9]+$' THEN CAST(" . $db->quoteName('v.date_from') . ' AS SIGNED) ELSE NULL END';
                    $dToSafe   = 'CASE WHEN ' . $db->quoteName('v.date_to')   . " REGEXP '^-?[0-9]+$' THEN CAST(" . $db->quoteName('v.date_to')   . ' AS SIGNED) ELSE NULL END';
                    $yExprCast = 'COALESCE(' . $dFromSafe . ', ' . $dToSafe . ')';
                    $bucketStartExpr = 'CAST(FLOOR(' . $yExprCast . ' / ' . (int)$yearsBucket . ') * ' . (int)$yearsBucket . ' AS SIGNED)';

                    $qYears = clone $qBase;
                    $qYears->clear('select')
                           ->select($bucketStartExpr . ' AS bucket_start')
                           ->select('COUNT(*) AS cnt')
                           ->where('(' . $yExprCast . ' IS NOT NULL)')
                           ->group('bucket_start')
                           ->order('bucket_start ASC');
                    $db->setQuery($qYears);
                    $yearsRows = $db->loadAssocList() ?: [];
                    foreach ($yearsRows as $r) {
                        $start = (int)$r['bucket_start'];
                        $years[] = ['bucket' => $start . '..' . ($start + $yearsBucket - 1), 'count' => (int)$r['cnt']];
                    }
                }

                $this->sendJson([
                    'meta'=>['total'=>$total, 'years_bucket'=>$yearsBucket],
                    'facets'=>[
                        'mint'      => array_map(fn($r) => ['name'=>$r['name'], 'count'=>(int)$r['cnt']], $mintRows),
                        'authority' => array_map(fn($r) => ['name'=>$r['name'], 'count'=>(int)$r['cnt']], $authRows),
                        'material'  => array_map(fn($r) => ['name'=>$r['name'], 'count'=>(int)$r['cnt']], $matRows),
                        'years'     => $years
                    ]
                ]);
                return;

            } catch (\Throwable $e) {
                $this->sendError(500, 'Internal server error', $e->getMessage());
                return;
            }
        }

        // ===================== SUGGEST: /v1/suggest/mints ===================
        if (preg_match('~/v1/suggest/mints(?:[/?#?]|$)~', $uri)) {
            try {
                $app = Factory::getApplication();
                $db  = Factory::getDbo();

                $qStr  = trim((string)$app->input->get('q', ''));
                $limit = max(1, min((int)$app->input->get('limit', 10), 20));
                if (mb_strlen($qStr, 'UTF-8') < 2) { $this->sendJson(['data'=>[]]); return; }

                $allowedCatIds = $this->getAllowedCatIds($db, self::ROOT_CAT_ID);
                if (empty($allowedCatIds)) { $this->sendJson(['data'=>[]]); return; }
                $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

                $vTbl = $this->resolveVariantsSourceTable($db);
                $cTbl = $db->quoteName('#__content', 'ct');

                $fvTableName = $this->resolveFieldsValuesTable($db);
                $fvTbl       = fn(string $alias) => $db->quoteName($fvTableName, $alias);
                $mintFieldId = $this->fid('mint_name');

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
                    $q->join('LEFT',  $fvTbl('fv_mint') . ' ON CAST(' . $db->quoteName('fv_mint.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id')
                                           . ' AND ' . $db->quoteName('fv_mint.field_id') . ' = ' . (int)$mintFieldId);
                }
                $q->where('(' . $nameExpr . ' LIKE ' . $db->quote($qLike) . ')')
                  ->where('(' . $nameExpr . ' IS NOT NULL AND ' . $nameExpr . " <> '')")
                  ->group('name')
                  ->order('cnt DESC, name ASC')
                  ->setLimit($limit);

                $db->setQuery($q);
                $rows = $db->loadAssocList() ?: [];
                $this->sendJson(['data' => array_map(fn($r) => ['name'=>$r['name']], $rows)]);
                return;

            } catch (\Throwable $e) {
                $this->sendError(500, 'Internal Server Error', $e->getMessage());
                return;
            }
        }

        // ===================== SUGGEST: /v1/suggest/authorities =============
        if (preg_match('~/v1/suggest/authorities(?:[/?#?]|$)~', $uri)) {
            try {
                $app = Factory::getApplication();
                $db  = Factory::getDbo();

                $qStr  = trim((string)$app->input->get('q', ''));
                $limit = max(1, min((int)$app->input->get('limit', 10), 20));
                if (mb_strlen($qStr, 'UTF-8') < 2) { $this->sendJson(['data'=>[]]); return; }

                $allowedCatIds = $this->getAllowedCatIds($db, self::ROOT_CAT_ID);
                if (empty($allowedCatIds)) { $this->sendJson(['data'=>[]]); return; }
                $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

                $vTbl = $this->resolveVariantsSourceTable($db);
                $cTbl = $db->quoteName('#__content', 'ct');

                $fvTableName = $this->resolveFieldsValuesTable($db);
                $fvTbl       = fn(string $alias) => $db->quoteName($fvTableName, $alias);
                $authFieldId = $this->fid('authority_name');

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
                    $q->join('LEFT', $fvTbl('fv_auth') . ' ON CAST(' . $db->quoteName('fv_auth.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id')
                                           . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int)$authFieldId);
                }
                $q->where('(' . $nameExpr . ' LIKE ' . $db->quote($qLike) . ')')
                  ->where('(' . $nameExpr . ' IS NOT NULL AND ' . $nameExpr . " <> '')")
                  ->group('name')
                  ->order('cnt DESC, name ASC')
                  ->setLimit($limit);

                $db->setQuery($q);
                $rows = $db->loadAssocList() ?: [];
                $this->sendJson(['data' => array_map(fn($r) => ['name'=>$r['name']], $rows)]);
                return;

            } catch (\Throwable $e) {
                $this->sendError(500, 'Internal Server Error', $e->getMessage());
                return;
            }
        }

        // ===================== IMAGES: /v1/variants/{id}/images ============
        if (preg_match('~/v1/variants/(\d+)/images(?:[/?#]|$)~', $uri, $m)) {
            $variantId = (int)$m[1];
            try {
                $db   = Factory::getDbo();
                $vTbl = $this->resolveVariantsSourceTable($db);
                $cTbl = $db->quoteName('#__content', 'ct');

                $allowedCatIds = $this->getAllowedCatIds($db, self::ROOT_CAT_ID);
                if (empty($allowedCatIds)) { $this->sendError(404, 'Resource not found'); return; }
                $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

                $qv = $db->getQuery(true)
                    ->select('1')
                    ->from($vTbl)
                    ->join(
                        'INNER',
                        $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                        . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                        . ' AND ' . $db->quoteName('ct.state') . ' = 1'
                    )
                    ->where($db->quoteName('v.article_id') . ' = ' . (int)$variantId)
                    ->setLimit(1);
                $db->setQuery($qv);
                $exists = (int)$db->loadResult();
                if ($exists !== 1) { $this->sendError(404, 'Resource not found'); return; }

                $wm  = (int)$app->input->get('wm', 1);
                $abs = (int)$app->input->get('abs', 0);

                $data = $this->getVariantImages($db, $variantId, $wm, $abs);

                $this->sendJson(['data' => $data]);
                return;

            } catch (\Throwable $e) {
                $this->sendError(500, 'Internal server error', $e->getMessage());
                return;
            }
        }

        // ===================== ITEM: /v1/variants/{key} =================
        if (preg_match('~/v1/variants/([^/?#]+)~', $uri, $m)) {
            $token = $m[1];

            try {
                $db   = Factory::getDbo();
                $vTbl = $this->resolveVariantsSourceTable($db);
                $cTbl = $db->quoteName('#__content', 'ct');

                $includeList = array_filter(array_map('trim', explode(',', $app->input->getString('include',''))));
                $includeRaw  = in_array('raw', $includeList, true);
                $includeFlds = in_array('fields', $includeList, true);
                $includeImgs = in_array('images', $includeList, true);

                $wmPref = (int)$app->input->get('wm', 1);
                $absUrl = (int)$app->input->get('abs', 0);

                $allowedCatIds = $this->getAllowedCatIds($db, self::ROOT_CAT_ID);
                if (empty($allowedCatIds)) { $this->sendError(404, 'Resource not found'); return; }
                $allowedCatIdsSql = implode(',', array_map('intval', $allowedCatIds));

                $fvTableName = $this->resolveFieldsValuesTable($db);
                $matFieldId  = $this->fid('material');

                $q = $db->getQuery(true)
                    ->select('v.*')
                    ->from($vTbl)
                    ->join(
                        'INNER',
                        $cTbl . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                        . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . $allowedCatIdsSql . ')'
                        . ' AND ' . $db->quoteName('ct.state') . ' = 1'
                    );

                if ($matFieldId !== null) {
                    $q->select($db->quoteName('fv_mat.value', 'material_value'))
                      ->join(
                          'LEFT',
                          $db->quoteName($fvTableName, 'fv_mat')
                          . ' ON CAST(' . $db->quoteName('fv_mat.item_id') . ' AS UNSIGNED) = ' . $db->quoteName('v.article_id')
                          . ' AND ' . $db->quoteName('fv_mat.field_id') . ' = ' . (int)$matFieldId
                      );
                } else {
                    $q->select('NULL AS ' . $db->quoteName('material_value'));
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
                if (!$r) { $this->sendError(404, 'Resource not found'); return; }

                $title =
                    (!empty($r['title_tr']) ? $r['title_tr'] : null)
                    ?? (!empty($r['title_en']) ? $r['title_en'] : null)
                    ?? ($r['slug'] ?? null);

                $materialSrc = $r['metal'] ?? null;
                if (($materialSrc === null || $materialSrc === '') && array_key_exists('material_value', $r)) {
                    $materialSrc = $r['material_value'];
                }

                $payload = [
                    'uid'      => $r['uid']  ?? null,
                    'slug'     => $r['slug'] ?? null,
                    'title'    => $title,
                    'region'   => $r['region_code'] ?? null,
                    'material' => $this->normalizeMaterialKey((string)$materialSrc),
                ];
                if ($includeRaw)   { $payload['_raw'] = $r; }
                if ($includeFlds)  { $payload['_fields'] = [ 'material_source' => $materialSrc ]; }

                if ($includeImgs) {
                    $variantId = (int)($r['article_id'] ?? 0);
                    $payload['images'] = $variantId > 0 ? $this->getVariantImages($db, $variantId, $wmPref, $absUrl) : [];
                }

                $this->sendJson(['data' => $payload]);
                return;

            } catch (\Throwable $e) {
                $this->sendError(500, 'Internal server error', $e->getMessage());
                return;
            }
        }

        // -------------- not found --------------
        $this->sendError(404, 'Endpoint not found');
    }

    /** /v1/variants index rotasını tespit eder */
    private function isVariantsIndex(string $uri): bool
    {
        return (bool)(preg_match('~/v1/variants(?:[/?#?]|$)~', $uri) && !preg_match('~/v1/variants/[^/]+~', $uri));
    }

    private function resolveFieldsValuesTable($db): string
    {
        $tableCandidates = ['#__fields_values', '#__fields_value'];
        foreach ($tableCandidates as $t) {
            try {
                $db->setQuery('SHOW TABLES LIKE ' . $db->quote(str_replace('#__', $db->getPrefix(), $t)));
                if ($db->loadResult()) return str_replace('#__', $db->getPrefix(), $t);
            } catch (\Throwable $e) {}
        }
        return str_replace('#__', $db->getPrefix(), '#__fields_values');
    }

    private function fid(string $key): ?int
    {
        return array_key_exists($key, self::FIELD_ID) ? (int)self::FIELD_ID[$key] : null;
    }

    private function normalizeMaterialKey(?string $v): ?string
    {
        if ($v === null) return null;
        $v = trim(mb_strtolower($v, 'UTF-8'));
        if ($v === '') return null;

        $map = ['ae'=>'bronze','ar'=>'silver','av'=>'gold','el'=>'electrum','cu'=>'copper','pb'=>'lead','fe'=>'iron'];
        if (isset($map[$v])) return $map[$v];

        if (str_contains($v, 'gumus') || str_contains($v, 'gümüş') || $v === 'silver') return 'silver';
        if (str_contains($v, 'altin') || str_contains($v, 'altın') || $v === 'gold') return 'gold';
        if (str_contains($v, 'bronz') || $v === 'bronze' || $v === 'copper' || $v === 'cu') return 'bronze';
        if ($v === 'electrum' || $v === 'elektrum') return 'electrum';
        if ($v === 'lead' || $v === 'kursun' || $v === 'kurşun' || $v === 'pb') return 'lead';
        if ($v === 'iron' || $v === 'demir' || $v === 'fe') return 'iron';
        return $v;
    }

    private function dbMaterialVariantsFor(string $norm): array
    {
        switch ($norm) {
            case 'bronze':   return ['bronze','copper','cu','bronz','ae'];
            case 'electrum': return ['electrum','elektrum','el'];
            case 'gold':     return ['gold','altın','altin','av','au'];
            case 'iron':     return ['iron','demir','fe'];
            case 'lead':     return ['lead','kurşun','kursun','pb'];
            case 'silver':   return ['silver','gümüş','gumus','ar'];
            default:         return [];
        }
    }

    private function getAllowedCatIds($db, int $rootId): array
    {
        $cats = $db->quoteName('#__categories');

        $q1 = $db->getQuery(true)
            ->select([$db->quoteName('lft'), $db->quoteName('rgt')])
            ->from($cats)
            ->where($db->quoteName('id') . ' = ' . (int)$rootId)
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'));
        $db->setQuery($q1);
        $row = $db->loadAssoc();
        if (!$row) return [];

        $q2 = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($cats)
            ->where($db->quoteName('lft') . ' BETWEEN ' . (int)$row['lft'] . ' AND ' . (int)$row['rgt'])
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('lft') . ' ASC');
        $db->setQuery($q2);
        return array_map('intval', $db->loadColumn() ?: []);
    }

    /** Joomla görüntüleyici üzerinden görsel URL üretir. */
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

    /** Variant görsellerini (coins_images) getirir. */
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
                'image_id'   => $imageId,
                'variant_id' => (int)($r['coin_id'] ?? $variantId),
                'type'       => $r['image_type'] ?? null,
                'weight'     => $r['weight'] ?? null,
                'diameter'   => $r['diameter'] ?? null,
                'ordering'   => isset($r['ordering']) ? (int)$r['ordering'] : null,
                'url'        => $this->buildImageUrl($imageId, $wmPref, $abs),
                'url_raw'    => $this->buildImageUrl($imageId, 0, $abs),
            ];
            $data[] = $item;
        }
        return $data;
    }

    private function sendJson($payload): void
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);

        // ETag/If-None-Match
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($json) . '"';
        $app->setHeader('ETag', $etag, true);
        $ifNone = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNone !== '' && trim($ifNone) === $etag) {
            http_response_code(304);
            echo '';
            $app->close();
        }

        http_response_code(200);
        echo $json;
        $app->close();
    }

    private function sendError(int $code, string $title, string $detail = null): void
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        http_response_code($code);
        $err = ['errors' => [[ 'title' => $title, 'code' => $code ]]];
        if ($detail) $err['errors'][0]['detail'] = $detail;
        $json = json_encode($err, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"' . sha1($json) . '"';
        $app->setHeader('ETag', $etag, true);
        echo $json;
        $app->close();
    }
}
