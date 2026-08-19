<?php
/**
 * Ticker Helper for NumisTR REST API Plugin
 * Handles ticker content retrieval and formatting
 *
 * @package     NumisTR
 * @subpackage  WebServices
 * @since       1.2.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;

class NumisTRTickerHelper
{
    private $db;
    private $config;

    public function __construct($config = [])
    {
        $this->db = Factory::getDbo();
        $this->config = $config;
    }

    /**
     * Get ticker items for API response
     *
     * @param   array  $options  Query options
     *                 - category_id: Category ID to fetch from
     *                 - category_alias: Category alias (alternative to ID)
     *                   Special aliases: 'darphane-isimleri' or 'mints' -> uses coin mints data
     *                 - region: Region filter
     *                 - language: Language filter (e.g., 'tr-TR', 'en-GB', '*' for all)
     *                 - limit: Number of items to return
     *                 - random: Randomize order
     *                 - cache: Cache duration in seconds
     *
     * @return  array  Ticker items
     */
    public function getTickerItems($options = [])
    {
        // Extract options
        $categoryId = $options['category_id'] ?? null;
        $categoryAlias = $options['category_alias'] ?? null;
        $regionFilter = $options['region'] ?? 'all';
        $languageFilter = $options['language'] ?? '*'; // Language filter (tr-TR, en-GB, or * for all)
        $limit = (int) ($options['limit'] ?? 50);
        $random = (bool) ($options['random'] ?? true);
        $cacheDuration = (int) ($options['cache'] ?? 3600);
        $debug = (bool) ($options['debug'] ?? false);

        // Skip cache if debug mode
        if ($debug) {
            $cacheDuration = 0;
        }

        // Build cache key (include language in cache key)
        $cacheKey = 'ticker_' . md5(
            ($categoryId ?? $categoryAlias) . '_' . $regionFilter . '_' . $languageFilter . '_' . $limit . '_' . ($random ? 'rand' : 'ord')
        );

        // Try cache first
        if ($cacheDuration > 0) {
            $cached = $this->getFromCache($cacheKey, $cacheDuration);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Get category ID if alias provided and no ID given
        if (!$categoryId && $categoryAlias) {
            $categoryId = $this->getCategoryIdByAlias($categoryAlias);
        }

        // If still no category ID, return empty
        if (!$categoryId) {
            return [];
        }

        // Build query
        $query = $this->db->getQuery(true);

        $query->select([
            'a.id',
            'a.title',
            'a.introtext',
            'a.alias',
            'a.catid',
            'a.language',
            'c.title AS category_title',
            'c.alias AS category_alias'
        ])
        ->from($this->db->quoteName('#__content', 'a'))
        ->join('LEFT', $this->db->quoteName('#__categories', 'c') . ' ON a.catid = c.id')
        ->where('a.state = 1'); // Published only

        // Filter by category
        if ($categoryId) {
            $query->where('a.catid = ' . (int) $categoryId);
        }

        // Filter by language if specified (not '*' which means all languages)
        if ($languageFilter !== '*' && $languageFilter !== 'all' && $languageFilter !== null && $languageFilter !== '') {
            // Include both specific language AND articles marked for all languages ('*')
            $query->where('(a.language = ' . $this->db->quote($languageFilter) . ' OR a.language = ' . $this->db->quote('*') . ')');
        }

        // Filter by region if specified
        // Check both hyphenated (region-code) and underscored (region_code) field names
        if ($regionFilter !== 'all' && $regionFilter !== null && $regionFilter !== '') {
            $query->leftJoin(
                $this->db->quoteName('#__fields_values', 'fv') . ' ON fv.item_id = a.id'
            )
            ->leftJoin(
                $this->db->quoteName('#__fields', 'f') . ' ON f.id = fv.field_id'
            )
            ->where('(f.name = ' . $this->db->quote('region-code') . ' OR f.name = ' . $this->db->quote('region_code') . ')')
            ->where('fv.value = ' . $this->db->quote($regionFilter));
        }

        // Ordering
        if ($random) {
            $query->order('RAND()');
        } else {
            $query->order('a.ordering ASC, a.title ASC');
        }

        // Limit
        $query->setLimit($limit);

        $this->db->setQuery($query);

        try {
            $items = $this->db->loadObjectList();

            // Process items
            $processed = [];
            foreach ($items as $item) {
                $processed[] = $this->processItem($item, $debug);
            }

            // Cache the result
            if ($cacheDuration > 0) {
                $this->saveToCache($cacheKey, $processed, $cacheDuration);
            }

            return $processed;

        } catch (Exception $e) {
            Factory::getLog()->add(
                'Ticker Helper Error: ' . $e->getMessage(),
                JLog::ERROR,
                'numistr'
            );
            return [];
        }
    }

    /**
     * Get ticker items from coin mints data
     * Uses o_numistr_variants_public view for mint information
     *
     * @param   string  $regionFilter   Region filter (e.g., 'lydia-coins')
     * @param   int     $limit          Number of items
     * @param   bool    $random         Randomize order
     * @param   int     $cacheDuration  Cache duration
     * @param   string  $cacheKey       Cache key
     *
     * @return  array   Ticker items from mint data
     */
    private function getMintTickerItems($regionFilter, $limit, $random, $cacheDuration, $cacheKey)
    {
        $query = $this->db->getQuery(true);

        // Get unique mints with coin count per region
        $query->select([
            'v.mint_name',
            'v.region_code',
            'COUNT(DISTINCT v.article_id) AS coin_count'
        ])
        ->from($this->db->quoteName('o_numistr_variants_public', 'v'))
        ->where('v.mint_name IS NOT NULL')
        ->where('v.mint_name != ' . $this->db->quote(''))
        ->group('v.mint_name, v.region_code');

        // Filter by region if specified
        if ($regionFilter !== 'all' && $regionFilter !== null && $regionFilter !== '') {
            $query->where('v.region_code = ' . $this->db->quote($regionFilter));
        }

        // Ordering
        if ($random) {
            $query->order('RAND()');
        } else {
            $query->order('v.mint_name ASC');
        }

        // Limit
        $query->setLimit($limit);

        $this->db->setQuery($query);

        try {
            $mints = $this->db->loadObjectList();

            // Process mints into ticker format
            $processed = [];
            $id = 1;
            foreach ($mints as $mint) {
                $processed[] = [
                    'id' => $id++,
                    'fact_title' => $mint->mint_name,
                    'fact_description' => $mint->coin_count . ' sikke',
                    'ancient_name' => $mint->mint_name,
                    'modern_name' => $mint->coin_count . ' sikke',
                    'region_code' => $mint->region_code,
                    'category' => [
                        'id' => 0,
                        'title' => 'Darphane İsimleri',
                        'alias' => 'darphane-isimleri',
                    ],
                    'full_text' => $mint->mint_name . ': ' . $mint->coin_count . ' sikke',
                ];
            }

            // Cache the result
            if ($cacheDuration > 0) {
                $this->saveToCache($cacheKey, $processed, $cacheDuration);
            }

            return $processed;

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Process a single ticker item
     * Supports both old format (ancient_name/modern_name) and new format (fact_title/fact_description)
     *
     * @param   object  $item  Raw item from database
     * @param   bool    $debug Whether to include debug info
     *
     * @return  array  Processed item
     */
    private function processItem($item, $debug = false)
    {
        // Get custom fields
        $customFields = $this->getCustomFields($item->id);

        // NEW FORMAT: fact_title and fact_description
        // Check BOTH hyphenated (fact-title) and underscored (fact_title) versions
        // Joomla may store field names with hyphens
        $factTitle = $customFields['fact-title']
            ?? $customFields['fact_title']
            ?? $customFields['ancient_name']
            ?? $item->title;

        $factDescription = $customFields['fact-description']
            ?? $customFields['fact_description']
            ?? $customFields['modern_name']
            ?? strip_tags($item->introtext);

        $regionCode = $customFields['region_code'] ?? $customFields['region-code'] ?? null;

        $result = [
            'id' => (int) $item->id,
            // NEW FORMAT fields
            'fact_title' => $factTitle,
            'fact_description' => $factDescription,
            // OLD FORMAT fields (backward compatibility)
            'ancient_name' => $factTitle,
            'modern_name' => $factDescription,
            // Common fields
            'region_code' => $regionCode,
            'category' => [
                'id' => (int) $item->catid,
                'title' => $item->category_title ?? null,
                'alias' => $item->category_alias ?? null,
            ],
            // Display format: "Title: Description"
            'full_text' => $factTitle . ': ' . $factDescription,
        ];

        // Add debug info if requested
        if ($debug) {
            $result['_debug'] = [
                'article_title' => $item->title,
                'article_introtext' => substr(strip_tags($item->introtext ?? ''), 0, 100),
                'custom_fields_raw' => $customFields,
                'custom_field_keys' => array_keys($customFields),
            ];
        }

        return $result;
    }

    /**
     * Get custom fields for an article
     *
     * @param   int  $articleId  Article ID
     *
     * @return  array  Custom fields (name => value)
     */
    private function getCustomFields($articleId)
    {
        $query = $this->db->getQuery(true);

        $query->select([
            'f.name',
            'fv.value'
        ])
        ->from($this->db->quoteName('#__fields_values', 'fv'))
        ->join('INNER', $this->db->quoteName('#__fields', 'f') . ' ON f.id = fv.field_id')
        ->where('fv.item_id = ' . (int) $articleId)
        ->where('f.context = ' . $this->db->quote('com_content.article'));

        $this->db->setQuery($query);

        try {
            $fields = $this->db->loadObjectList('name');
            $result = [];
            foreach ($fields as $name => $field) {
                $result[$name] = $field->value;
            }
            return $result;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get category ID by alias
     *
     * @param   string  $alias  Category alias
     *
     * @return  int|null  Category ID or null if not found
     */
    private function getCategoryIdByAlias($alias)
    {
        $query = $this->db->getQuery(true);

        $query->select('id')
            ->from('#__categories')
            ->where('alias = ' . $this->db->quote($alias))
            ->where('extension = ' . $this->db->quote('com_content'));

        $this->db->setQuery($query);

        try {
            return (int) $this->db->loadResult();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get from cache
     *
     * @param   string  $key       Cache key
     * @param   int     $duration  Cache duration in seconds
     *
     * @return  mixed  Cached data or null
     */
    private function getFromCache($key, $duration)
    {
        try {
            $cache = Factory::getCache('numistr_ticker', 'output');
            $cache->setLifeTime($duration);
            $cached = $cache->get($key);
            return $cached !== false ? $cached : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Save to cache
     *
     * @param   string  $key       Cache key
     * @param   mixed   $data      Data to cache
     * @param   int     $duration  Cache duration in seconds
     *
     * @return  void
     */
    private function saveToCache($key, $data, $duration)
    {
        try {
            $cache = Factory::getCache('numistr_ticker', 'output');
            $cache->setLifeTime($duration);
            $cache->store($data, $key);
        } catch (Exception $e) {
            // Silent fail
        }
    }

    /**
     * Get ticker statistics
     *
     * @param   int  $categoryId  Category ID
     *
     * @return  array  Statistics
     */
    public function getTickerStats($categoryId = null)
    {
        $query = $this->db->getQuery(true);

        $query->select('COUNT(*) as total')
            ->from('#__content')
            ->where('state = 1');

        if ($categoryId) {
            $query->where('catid = ' . (int) $categoryId);
        }

        $this->db->setQuery($query);

        try {
            $total = (int) $this->db->loadResult();

            // Get region breakdown
            $regionBreakdown = $this->getRegionBreakdown($categoryId);

            return [
                'total_items' => $total,
                'by_region' => $regionBreakdown,
            ];

        } catch (Exception $e) {
            return [
                'total_items' => 0,
                'by_region' => [],
            ];
        }
    }

    /**
     * Get region breakdown
     *
     * @param   int  $categoryId  Category ID
     *
     * @return  array  Region counts
     */
    private function getRegionBreakdown($categoryId = null)
    {
        $query = $this->db->getQuery(true);

        $query->select([
            'fv.value as region_code',
            'COUNT(*) as count'
        ])
        ->from($this->db->quoteName('#__content', 'a'))
        ->leftJoin(
            $this->db->quoteName('#__fields_values', 'fv') . ' ON fv.item_id = a.id'
        )
        ->leftJoin(
            $this->db->quoteName('#__fields', 'f') . ' ON f.id = fv.field_id'
        )
        ->where('a.state = 1')
        ->where('f.name = ' . $this->db->quote('region_code'));

        if ($categoryId) {
            $query->where('a.catid = ' . (int) $categoryId);
        }

        $query->group('fv.value')
            ->order('count DESC');

        $this->db->setQuery($query);

        try {
            $results = $this->db->loadObjectList();
            $breakdown = [];
            foreach ($results as $row) {
                if ($row->region_code) {
                    $breakdown[$row->region_code] = (int) $row->count;
                }
            }
            return $breakdown;
        } catch (Exception $e) {
            return [];
        }
    }
}
