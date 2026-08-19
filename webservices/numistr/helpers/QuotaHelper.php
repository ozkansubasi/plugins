<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2025 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * Helper class for managing scan quota
 *
 * @since  1.4.0
 */
class QuotaHelper
{
    /**
     * Free tier monthly limit (fallback when config missing)
     */
    const FREE_TIER_LIMIT = 10;

    /**
     * Pro tier monthly limit (effectively unlimited)
     */
    const PRO_TIER_LIMIT = 999999;

    /**
     * Plugin configuration (config/constants.php)
     *
     * @var array
     */
    private $config;

    /**
     * Database driver
     *
     * @var JDatabaseDriver
     */
    private $db;

    /**
     * Constructor
     *
     * @param   JDatabaseDriver  $db      Database driver
     * @param   array|null       $config  Plugin config; loaded from constants.php when null
     */
    public function __construct($db = null, ?array $config = null)
    {
        $this->db = $db ?: Factory::getDbo();

        if ($config === null) {
            $configPath = __DIR__ . '/../config/constants.php';
            $config = file_exists($configPath) ? include $configPath : [];
        }

        $this->config = $config;
    }

    /**
     * Get user's tier (free or pro)
     *
     * Pro grubu ve üniversite grubu ID'leri config/constants.php'den okunur;
     * AuthHelper::hasProSubscription() ile aynı mantık.
     *
     * @param   User  $user  User object
     *
     * @return  string  'free' or 'pro'
     */
    public function getUserTier(User $user)
    {
        if ($user->guest) {
            return 'free';
        }

        $groups = $user->getAuthorisedGroups();

        $proGroupId = (int) ($this->config['PRO_GROUP_ID'] ?? 0);
        if ($proGroupId && in_array($proGroupId, $groups, true)) {
            return 'pro';
        }

        $universityGroupId = (int) ($this->config['UNIVERSITY_GROUP_ID'] ?? 0);
        if ($universityGroupId && in_array($universityGroupId, $groups, true)) {
            return 'pro';
        }

        return 'free';
    }

    /**
     * Get monthly limit for user's tier
     *
     * @param   string  $tier  User tier ('free' or 'pro')
     *
     * @return  int  Monthly scan limit
     */
    public function getMonthlyLimit($tier)
    {
        $quota = $this->config['QUOTA'] ?? [];

        if ($tier === 'pro') {
            $proLimit = (int) ($quota['pro_limit'] ?? -1);

            return $proLimit === -1 ? self::PRO_TIER_LIMIT : $proLimit;
        }

        return (int) ($quota['free_limit'] ?? self::FREE_TIER_LIMIT);
    }

    /**
     * Get current month in YYYY-MM format
     *
     * @return  string  Current month
     */
    private function getCurrentMonth()
    {
        return date('Y-m');
    }

    /**
     * Get quota usage for user in current month
     *
     * @param   int  $userId  User ID
     *
     * @return  array  Quota data
     */
    public function getQuotaUsage($userId)
    {
        $month = $this->getCurrentMonth();

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('numistr_scan_quota'))
            ->where($this->db->quoteName('user_id') . ' = ' . (int) $userId)
            ->where($this->db->quoteName('month') . ' = ' . $this->db->quote($month));

        $this->db->setQuery($query);
        $result = $this->db->loadAssoc();

        if (!$result) {
            // First scan this month
            return array(
                'user_id' => $userId,
                'month' => $month,
                'scans_used' => 0,
                'tier' => 'free',
                'created_at' => null,
                'updated_at' => null
            );
        }

        return $result;
    }

    /**
     * Check if user can perform a scan
     *
     * @param   User  $user  User object
     *
     * @return  array  ['allowed' => bool, 'remaining' => int, 'limit' => int, 'tier' => string]
     */
    public function canScan(User $user)
    {
        $userId = $user->id;
        $tier = $this->getUserTier($user);
        $limit = $this->getMonthlyLimit($tier);
        $usage = $this->getQuotaUsage($userId);
        $used = $usage['scans_used'];
        $remaining = max(0, $limit - $used);
        $allowed = $used < $limit;

        return array(
            'allowed' => $allowed,
            'used' => $used,
            'remaining' => $remaining,
            'limit' => $limit,
            'tier' => $tier,
            'reset_date' => date('Y-m-01', strtotime('+1 month'))
        );
    }

    /**
     * Consume one scan from user's quota
     *
     * @param   User  $user  User object
     *
     * @return  bool  Success
     *
     * @throws  RuntimeException  If quota exceeded
     */
    public function consumeScan(User $user)
    {
        $status = $this->canScan($user);

        if (!$status['allowed']) {
            throw new RuntimeException('Monthly scan quota exceeded', 429);
        }

        $userId = $user->id;
        $month = $this->getCurrentMonth();
        $tier = $this->getUserTier($user);

        // Check if record exists
        $query = $this->db->getQuery(true)
            ->select('id, scans_used')
            ->from($this->db->quoteName('numistr_scan_quota'))
            ->where($this->db->quoteName('user_id') . ' = ' . (int) $userId)
            ->where($this->db->quoteName('month') . ' = ' . $this->db->quote($month));

        $this->db->setQuery($query);
        $existing = $this->db->loadAssoc();

        if ($existing) {
            // Update existing record
            $newCount = $existing['scans_used'] + 1;
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('numistr_scan_quota'))
                ->set($this->db->quoteName('scans_used') . ' = ' . (int) $newCount)
                ->where($this->db->quoteName('id') . ' = ' . (int) $existing['id']);

            $this->db->setQuery($query);
            return $this->db->execute();
        } else {
            // Insert new record
            $now = Factory::getDate()->toSql();

            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('numistr_scan_quota'))
                ->columns(array(
                    $this->db->quoteName('user_id'),
                    $this->db->quoteName('month'),
                    $this->db->quoteName('scans_used'),
                    $this->db->quoteName('tier'),
                    $this->db->quoteName('created_at'),
                    $this->db->quoteName('updated_at')
                ))
                ->values(
                    (int) $userId . ', ' .
                    $this->db->quote($month) . ', ' .
                    '1, ' .
                    $this->db->quote($tier) . ', ' .
                    $this->db->quote($now) . ', ' .
                    $this->db->quote($now)
                );

            $this->db->setQuery($query);
            return $this->db->execute();
        }
    }
}
