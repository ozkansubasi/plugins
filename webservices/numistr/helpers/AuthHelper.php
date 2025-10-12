<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;

/**
 * NumisTR Authentication Helper
 * Kullanıcı kimlik doğrulama ve yetkilendirme işlemleri
 */
class NumisTRAuthHelper
{
    private $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * API Token ile kullanıcıyı authenticate et
     * Authorization: Bearer {token}
     * 
     * @return User|null Authenticated user veya null
     */
    public function authenticateUser(): ?User
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader)) {
            return null;
        }

        // Bearer token al
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        if (empty($token)) {
            return null;
        }

        try {
            $db = Factory::getDbo();
            
            // Token'ı veritabanında ara
            $query = $db->getQuery(true)
                ->select('user_id')
                ->from($db->quoteName('#__user_keys'))
                ->where($db->quoteName('token') . ' = ' . $db->quote($token))
                ->where($db->quoteName('series') . ' = ' . $db->quote('api'))
                ->setLimit(1);
            
            $db->setQuery($query);
            $userId = (int)$db->loadResult();

            if ($userId <= 0) {
                return null;
            }

            // User objesini al
            $user = Factory::getUser($userId);
            
            if ($user->id <= 0 || $user->block == 1) {
                return null;
            }

            return $user;

        } catch (\Throwable $e) {
            $this->dbg('auth-error', $e->getMessage());
            return null;
        }
    }
    
    /**
     * Kullanıcının pro üyeliği var mı kontrol et
     * 
     * @param User $user Kontrol edilecek kullanıcı
     * @return bool Pro üye ise true
     */
    public function hasProSubscription(User $user): bool
    {
        if ($user->guest) {
            return false;
        }

        $proGroupId = $this->config['PRO_GROUP_ID'];
        return in_array($proGroupId, $user->getAuthorisedGroups(), true);
    }
    
    /**
     * Teşhis için basit log helper
     */
    private function dbg(string $branch, string $message): void
    {
        try {
            $logger = Factory::getContainer()->get('logger');
            $logger->info('[NumisTR-Auth] branch="' . $branch . '" msg="' . $message . '"');
        } catch (\Throwable $e) {
            // no-op
        }
    }
}