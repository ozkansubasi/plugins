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
            // Pro'da limit bir sentinel'dir (PRO_TIER_LIMIT). Istemci ham sayiyi
            // gostermesin diye durumu acikca bildiriyoruz; boylece her istemci
            // esigi kendi tahmin etmek zorunda kalmaz.
            'unlimited' => $limit >= self::PRO_TIER_LIMIT,
            'tier' => $tier,
            'reset_date' => date('Y-m-01', strtotime('+1 month'))
        );
    }

    /**
     * Adil kullanim / kotuye kullanim korumasi — yuksek kapasiteli Pro'nun ucunu kapatir.
     *
     * Neden ayri bir katman: aylik kota (canScan) Pro'da fiilen acik uclu, yani
     * tek bir hesapla sabahtan aksama kadar tarama yapmak ya da hesabi paylasip
     * hacmi katlamak mumkundu. Cozum pencere bazli tavan: kisa pencere (120 sn)
     * otomatik betikleri, gunluk tavan ise paylasimin ekonomisini durdurur.
     * Esikler gercek temposundan turetildi — bir tarama (fotograf cekimi/secimi,
     * yukleme, sonucun degerlendirilmesi) ~2-3 dakika surer; gunde 30 tanima
     * kesintisiz bir ila bir bucuk saatlik calismaya karsilik gelir ve 5 kisiye
     * dagitilmis bir hesap bu tavani hemen gorur.
     *
     * IP ile engelleme BILINCLI olarak yapilmiyor: CGNAT, dinamik ev IP'si, VPN ve
     * seyahat yuzunden mesru kullaniciyi cezalandirir, paylasimi ise ayni evde
     * yakalayamaz. IP yalnizca inceleme sinyali olarak loglanir.
     *
     * @param   User  $user  Giris yapmis kullanici
     *
     * @return  array  ['allowed'=>bool,'scope'=>''|'burst'|'hour'|'day',
     *                  'limit'=>int,'used'=>int,'retry_after'=>int,'counts'=>array,'measured'=>bool]
     */
    public function checkRateLimits(User $user)
    {
        $limits = $this->rateLimitConfig();
        $counts = $this->recentScanCounts((int) $user->id);

        if ($counts === null) {
            // Sayim yapilamadi (tablo yok / SQL hatasi). Tanimayi kesmiyoruz ama
            // korumanin CALISMADIGI acikca loglaniyor — sessizce devre disi kalmasin.
            $this->logRateIssue('sayim yapilamadi: numistr_recognition_requests okunamadi');

            return array(
                'allowed' => true, 'scope' => '', 'limit' => 0, 'used' => 0,
                'retry_after' => 0, 'counts' => array(), 'measured' => false
            );
        }

        $decision = self::rateDecision($counts, $limits);
        $decision['counts']   = $counts;
        $decision['measured'] = true;

        return $decision;
    }

    /**
     * Saf karar fonksiyonu (test edilebilir olsun diye ayri): sayimlar ve limitler
     * verildiginde hangi pencerenin asildigini soyler. En dar pencere once bakilir,
     * boylece kullaniciya en kisa bekleme suresi bildirilir.
     *
     * @param   array  $counts  ['burst'=>int,'hour'=>int,'day'=>int]
     * @param   array  $limits  ayni anahtarlar; 0 veya eksi = o pencere kapali
     *
     * @return  array  ['allowed'=>bool,'scope'=>string,'limit'=>int,'used'=>int,'retry_after'=>int]
     */
    public static function rateDecision(array $counts, array $limits)
    {
        // 'burst' penceresi 120 saniyedir: bir taramanin gercek suresi ~2-3 dakika
        // oldugundan dakika bazli bir tavan bu kullanimi anlamli bicimde tarif etmiyor.
        $windows = array(
            'burst' => 120,
            'hour'  => 3600,
            'day'   => 86400,
        );

        foreach ($windows as $scope => $seconds) {
            $limit = isset($limits[$scope]) ? (int) $limits[$scope] : 0;
            $used  = isset($counts[$scope]) ? (int) $counts[$scope] : 0;

            if ($limit > 0 && $used >= $limit) {
                return array(
                    'allowed'     => false,
                    'scope'       => $scope,
                    'limit'       => $limit,
                    'used'        => $used,
                    'retry_after' => $seconds,
                );
            }
        }

        return array('allowed' => true, 'scope' => '', 'limit' => 0, 'used' => 0, 'retry_after' => 0);
    }

    /**
     * Yapilandirilmis adil kullanim tavanlari (istemciye bildirmek icin publik).
     *
     * @return  array  ['burst'=>int,'hour'=>int,'day'=>int]  burst penceresi 120 sn
     */
    public function rateLimits()
    {
        return $this->rateLimitConfig();
    }

    private function rateLimitConfig()
    {
        $cfg = isset($this->config['QUOTA']['rate_limits']) ? (array) $this->config['QUOTA']['rate_limits'] : array();

        return array(
            'burst' => isset($cfg['per_2min']) ? (int) $cfg['per_2min'] : 1,
            'hour'  => isset($cfg['per_hour']) ? (int) $cfg['per_hour'] : 10,
            'day'   => isset($cfg['per_day'])  ? (int) $cfg['per_day']  : 30,
        );
    }

    /**
     * Son 24 saatteki tarama sayimlari. Tek sorgu, idx_user_timestamp uzerinden
     * ve 1 gunluk pencereyle sinirli.
     *
     * @return  array|null  null = olculemedi
     */
    private function recentScanCounts($userId)
    {
        if ($userId <= 0) {
            return array('burst' => 0, 'hour' => 0, 'day' => 0);
        }

        try {
            $db = $this->db !== null ? $this->db : Factory::getDbo();

            $db->setQuery(
                'SELECT'
                . ' SUM(CASE WHEN ' . $db->quoteName('request_timestamp') . ' >= (NOW() - INTERVAL 120 SECOND) THEN 1 ELSE 0 END) AS c_burst,'
                . ' SUM(CASE WHEN ' . $db->quoteName('request_timestamp') . ' >= (NOW() - INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS c_hour,'
                . ' COUNT(*) AS c_day'
                . ' FROM ' . $db->quoteName('numistr_recognition_requests')
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . (int) $userId
                . ' AND ' . $db->quoteName('request_timestamp') . ' >= (NOW() - INTERVAL 1 DAY)'
            );

            $row = $db->loadAssoc();

            if (!is_array($row)) {
                return array('burst' => 0, 'hour' => 0, 'day' => 0);
            }

            return array(
                'burst' => (int) $row['c_burst'],
                'hour'  => (int) $row['c_hour'],
                'day'   => (int) $row['c_day'],
            );
        } catch (\Throwable $e) {
            $this->logRateIssue($e->getMessage());

            return null;
        }
    }

    private function logRateIssue($message)
    {
        try {
            \Joomla\CMS\Log\Log::addLogger(array('text_file' => 'numistr_quota.php'), \Joomla\CMS\Log\Log::ALL, array('numistr-quota'));
            \Joomla\CMS\Log\Log::add('[rate-limit] ' . $message, \Joomla\CMS\Log\Log::WARNING, 'numistr-quota');
        } catch (\Throwable $e) {
            // sessiz gec: loglama hatasi tanimayi engellememeli
        }
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
