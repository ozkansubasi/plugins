<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2026 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;

/**
 * Pro üyelik (Joomla kullanıcı grubu) yönetimi
 *
 * Yalnızca config'deki PRO_GROUP_ID grubuna dokunur; kullanıcının diğer
 * grupları (Super Users, Üniversite Öğrencileri vb.) hiçbir zaman değişmez.
 *
 * @since 1.5.0
 */
class NumisTRMembershipHelper
{
    /**
     * Plugin config (constants.php)
     *
     * @var array
     */
    private $config;

    /**
     * @var \Joomla\Database\DatabaseInterface
     */
    private $db;

    public function __construct(?array $config = null, $db = null)
    {
        if ($config === null) {
            $configPath = __DIR__ . '/../config/constants.php';
            $config     = file_exists($configPath) ? include $configPath : [];
        }

        $this->config = $config;
        $this->db     = $db ?: Factory::getDbo();
    }

    /**
     * Pro grubunun ID'si
     */
    public function getProGroupId(): int
    {
        return (int) ($this->config['PRO_GROUP_ID'] ?? 0);
    }

    /**
     * Kullanıcı Pro grubunda mı? (yalnızca doğrudan grup üyeliği)
     */
    public function isInProGroup(int $userId): bool
    {
        $groupId = $this->getProGroupId();

        if ($groupId <= 0 || $userId <= 0) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__user_usergroup_map'))
            ->where($this->db->quoteName('user_id') . ' = ' . (int) $userId)
            ->where($this->db->quoteName('group_id') . ' = ' . $groupId);

        $this->db->setQuery($query);

        return (int) $this->db->loadResult() > 0;
    }

    /**
     * Kullanıcıyı Pro grubuna ekle (idempotent)
     *
     * @return bool  Değişiklik yapıldıysa true, zaten üyeyse false
     */
    public function grantPro(int $userId): bool
    {
        $groupId = $this->getProGroupId();

        if ($groupId <= 0 || $userId <= 0) {
            return false;
        }

        if ($this->isInProGroup($userId)) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__user_usergroup_map'))
            ->columns($this->db->quoteName(['user_id', 'group_id']))
            ->values((int) $userId . ', ' . $groupId);

        $this->db->setQuery($query);
        $this->db->execute();

        $this->clearUserCaches($userId);

        return true;
    }

    /**
     * Kullanıcıyı Pro grubundan çıkar (idempotent)
     *
     * @return bool  Değişiklik yapıldıysa true, zaten üye değilse false
     */
    public function revokePro(int $userId): bool
    {
        $groupId = $this->getProGroupId();

        if ($groupId <= 0 || $userId <= 0) {
            return false;
        }

        if (!$this->isInProGroup($userId)) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__user_usergroup_map'))
            ->where($this->db->quoteName('user_id') . ' = ' . (int) $userId)
            ->where($this->db->quoteName('group_id') . ' = ' . $groupId);

        $this->db->setQuery($query);
        $this->db->execute();

        $this->clearUserCaches($userId);

        return true;
    }

    /**
     * Grup değişiminden sonra Joomla'nın statik erişim önbelleklerini temizle
     */
    private function clearUserCaches(int $userId): void
    {
        try {
            Access::clearStatics();
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
