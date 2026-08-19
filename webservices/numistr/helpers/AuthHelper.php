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
     * Desteklenen token türleri:
     * 1. Auth0 JWT (eyJ ile başlar)
     * 2. Joomla API Token
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

        // Token türünü belirle
        // JWT tokenlar "eyJ" ile başlar (base64 encoded JSON)
        if (substr($token, 0, 3) === 'eyJ') {
            $this->dbg('auth-type', 'Auth0 JWT detected');
            return $this->authenticateWithAuth0JWT($token);
        }

        // Joomla API token
        $this->dbg('auth-type', 'Joomla API token detected');
        return $this->authenticateWithJoomlaToken($token);
    }

    /**
     * Auth0 JWT ile authenticate et
     *
     * İmza (RS256 + Auth0 JWKS), issuer, audience ve süre doğrulaması
     * NumisTRJwtVerifier tarafından yapılır. Doğrulama modu config
     * AUTH0.mode ile kontrol edilir:
     *   - 'enforce'  : imza geçersizse token reddedilir (üretim ayarı)
     *   - 'log_only' : sadece loglanır, eski (imzasız) davranış sürer (kademeli geçiş)
     *
     * @param string $jwt JWT token
     * @return User|null
     */
    private function authenticateWithAuth0JWT(string $jwt): ?User
    {
        try {
            $auth0Config = $this->config['AUTH0'] ?? [];
            $mode        = $auth0Config['mode'] ?? 'enforce';

            $data = null;

            if (class_exists('NumisTRJwtVerifier')) {
                $verifier = new NumisTRJwtVerifier($auth0Config);
                $data     = $verifier->verify($jwt);

                if ($data === null) {
                    $this->dbg('jwt-verify-failed', $verifier->getLastError());

                    if ($mode !== 'log_only') {
                        return null;
                    }
                }
            } else {
                $this->dbg('jwt-verifier-missing', 'JwtHelper.php not deployed');

                if ($mode !== 'log_only') {
                    return null;
                }
            }

            // log_only modu: doğrulama başarısız olsa da eski davranışla devam et
            if ($data === null) {
                $data = $this->decodeJwtPayloadUnverified($jwt);

                if (!$data) {
                    $this->dbg('jwt-error', 'Failed to decode JWT payload');
                    return null;
                }

                if (isset($data['exp']) && is_numeric($data['exp']) && $data['exp'] < time()) {
                    $this->dbg('jwt-error', 'Token expired');
                    return null;
                }
            }

            $sub           = isset($data['sub']) ? (string) $data['sub'] : '';
            $email         = isset($data['email']) ? trim((string) $data['email']) : '';
            $emailVerified = !empty($data['email_verified']);

            if ($sub === '' && $email === '') {
                $this->dbg('jwt-error', 'No sub/email in JWT');
                return null;
            }

            $db = Factory::getDbo();

            // 1) Önce kimlik eşlemesinden (sub -> user_id) bul
            $userId = $sub !== '' ? $this->findUserIdBySubject($sub) : 0;

            // 2) Yoksa e-postadan bul
            if ($userId <= 0 && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $requireVerified = ($auth0Config['require_email_verified'] ?? true) && $mode !== 'log_only';

                if ($requireVerified && !$emailVerified) {
                    $this->dbg('jwt-error', 'Unverified email, refusing to link account: ' . $email);
                    return null;
                }

                $query = $db->getQuery(true)
                    ->select('id')
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('email') . ' = ' . $db->quote($email))
                    ->where($db->quoteName('block') . ' = 0')
                    ->setLimit(1);

                $db->setQuery($query);
                $userId = (int) $db->loadResult();

                // 3) Hâlâ yoksa otomatik oluştur (Auto-register)
                if ($userId <= 0) {
                    $this->dbg('jwt-auto-register', 'Creating new user for email: ' . $email);
                    $userId = $this->createUserFromJWT($data, $email);

                    if ($userId <= 0) {
                        $this->dbg('jwt-error', 'Failed to create user for email: ' . $email);
                        return null;
                    }
                }
            }

            if ($userId <= 0) {
                $this->dbg('jwt-error', 'No matching Joomla user for sub=' . $sub);
                return null;
            }

            $user = Factory::getUser($userId);

            if ($user->id <= 0 || $user->block == 1) {
                return null;
            }

            // Auth0 sub <-> Joomla user eşlemesini güncel tut (RevenueCat webhook bunu kullanır)
            if ($sub !== '') {
                $this->rememberIdentity($sub, (int) $user->id, $email);
            }

            $this->dbg('jwt-success', 'User authenticated: ' . $user->username);
            return $user;

        } catch (\Throwable $e) {
            $this->dbg('jwt-exception', $e->getMessage());
            return null;
        }
    }

    /**
     * JWT payload'ını imza doğrulamadan çöz (yalnızca log_only modunda kullanılır)
     *
     * @param string $jwt
     * @return array|null
     */
    private function decodeJwtPayloadUnverified(string $jwt): ?array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        $data    = json_decode((string) $payload, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Auth0 subject'ten Joomla user id bul (kimlik eşleme tablosu)
     *
     * Tablo yoksa sessizce 0 döner (deploy sırası güvenliği).
     *
     * @param string $subject Auth0 sub claim'i
     * @return int
     */
    private function findUserIdBySubject(string $subject): int
    {
        try {
            $db = Factory::getDbo();

            $query = $db->getQuery(true)
                ->select($db->quoteName('user_id'))
                ->from($db->quoteName('numistr_auth_identities'))
                ->where($db->quoteName('subject') . ' = ' . $db->quote($subject))
                ->setLimit(1);

            $db->setQuery($query);

            return (int) $db->loadResult();
        } catch (\Throwable $e) {
            $this->dbg('identity-lookup-error', $e->getMessage());

            return 0;
        }
    }

    /**
     * Auth0 subject <-> Joomla user eşlemesini kaydet/güncelle
     *
     * Tablo yoksa sessizce geçilir (auth akışını asla bozmaz).
     *
     * @param string $subject Auth0 sub
     * @param int    $userId  Joomla user id
     * @param string $email   Token'daki e-posta (opsiyonel)
     */
    private function rememberIdentity(string $subject, int $userId, string $email = ''): void
    {
        try {
            $db  = Factory::getDbo();
            $now = Factory::getDate()->toSql();

            $query = 'INSERT INTO ' . $db->quoteName('numistr_auth_identities')
                . ' (' . $db->quoteName('subject') . ', ' . $db->quoteName('user_id') . ', '
                . $db->quoteName('email') . ', ' . $db->quoteName('created_at') . ', '
                . $db->quoteName('last_seen_at') . ')'
                . ' VALUES (' . $db->quote($subject) . ', ' . (int) $userId . ', '
                . $db->quote($email) . ', ' . $db->quote($now) . ', ' . $db->quote($now) . ')'
                . ' ON DUPLICATE KEY UPDATE '
                . $db->quoteName('user_id') . ' = ' . (int) $userId . ', '
                . $db->quoteName('email') . ' = ' . $db->quote($email) . ', '
                . $db->quoteName('last_seen_at') . ' = ' . $db->quote($now);

            $db->setQuery($query);
            $db->execute();
        } catch (\Throwable $e) {
            $this->dbg('identity-upsert-error', $e->getMessage());
        }
    }

    /**
     * Joomla API Token ile authenticate et
     *
     * @param string $token Joomla API token
     * @return User|null
     */
    private function authenticateWithJoomlaToken(string $token): ?User
    {
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
                $this->dbg('joomla-token-error', 'Token not found in database');
                return null;
            }

            // User objesini al
            $user = Factory::getUser($userId);

            if ($user->id <= 0 || $user->block == 1) {
                return null;
            }

            $this->dbg('joomla-token-success', 'User authenticated: ' . $user->username);
            return $user;

        } catch (\Throwable $e) {
            $this->dbg('joomla-token-exception', $e->getMessage());
            return null;
        }
    }
    
    /**
     * JWT payload'dan yeni Joomla kullanıcısı oluştur
     *
     * @param array $jwtData Decoded JWT payload
     * @param string $email User email
     * @return int User ID (0 on failure)
     */
    private function createUserFromJWT(array $jwtData, string $email): int
    {
        try {
            $db = Factory::getDbo();

            // Extract user details from JWT
            $name = $jwtData['name'] ?? $jwtData['nickname'] ?? explode('@', $email)[0];
            $username = $this->generateUniqueUsername($email);

            // Prepare user data
            $userData = [
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => bin2hex(random_bytes(32)), // Random password (won't be used)
                'block' => 0,
                'sendEmail' => 0,
                'registerDate' => Factory::getDate()->toSql(),
                'lastvisitDate' => Factory::getDate()->toSql(),
                'params' => '{}'
            ];

            // Insert user into #__users table
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__users'))
                ->columns($db->quoteName(array_keys($userData)))
                ->values(implode(',', array_map([$db, 'quote'], $userData)));

            $db->setQuery($query);
            $db->execute();
            $userId = $db->insertid();

            if ($userId <= 0) {
                return 0;
            }

            // Assign to Registered user group (group 2)
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__user_usergroup_map'))
                ->columns($db->quoteName(['user_id', 'group_id']))
                ->values($userId . ', 2');

            $db->setQuery($query);
            $db->execute();

            $this->dbg('jwt-auto-register', 'User created successfully: ' . $username . ' (ID: ' . $userId . ')');
            return $userId;

        } catch (\Throwable $e) {
            $this->dbg('jwt-auto-register-error', 'Exception: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Email'den unique username üret
     *
     * @param string $email User email
     * @return string Unique username
     */
    private function generateUniqueUsername(string $email): string
    {
        $db = Factory::getDbo();
        $baseUsername = strtolower(explode('@', $email)[0]);
        $baseUsername = preg_replace('/[^a-z0-9_]/', '', $baseUsername);

        // Ensure username is at least 3 characters
        if (strlen($baseUsername) < 3) {
            $baseUsername = 'user_' . $baseUsername;
        }

        $username = $baseUsername;
        $counter = 1;

        // Check if username exists and increment until unique
        while (true) {
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('username') . ' = ' . $db->quote($username));

            $db->setQuery($query);
            $exists = (int)$db->loadResult();

            if ($exists === 0) {
                break;
            }

            $username = $baseUsername . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Kullanıcının pro üyeliği var mı kontrol et
     * Pro subscription'a hak kazanan gruplar:
     * - Pro Group (config'deki PRO_GROUP_ID)
     * - Universite Ogrencileri grubu (config'deki UNIVERSITY_GROUP_ID)
     *
     * @param User $user Kontrol edilecek kullanıcı
     * @return bool Pro üye ise true
     */
    public function hasProSubscription(User $user): bool
    {
        if ($user->guest) {
            return false;
        }

        $userGroups = $user->getAuthorisedGroups();

        // Pro grubu kontrolü
        $proGroupId = $this->config['PRO_GROUP_ID'];
        if (in_array($proGroupId, $userGroups, true)) {
            return true;
        }

        // Universite grubu kontrolü (ücretsiz Pro hakkı)
        $universityGroupId = $this->config['UNIVERSITY_GROUP_ID'] ?? null;
        if ($universityGroupId && in_array($universityGroupId, $userGroups, true)) {
            return true;
        }

        return false;
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