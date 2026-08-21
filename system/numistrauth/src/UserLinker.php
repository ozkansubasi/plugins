<?php
/**
 * @package     plg_system_numistrauth
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Auth0 claims -> Joomla user id. Mobil API'deki AuthHelper ile AYNI kurallar:
 *   1) numistr_auth_identities.subject == sub  -> user_id
 *   2) yoksa doğrulanmış e-posta ile #__users eşleşmesi (block=0)
 *   3) yoksa yeni kullanıcı (Registered, grup 2) + identity kaydı
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class NumistrAuthUserLinker
{
    private $db;
    private $log;
    private $lastError = '';

    public function __construct($db, callable $log)
    {
        $this->db  = $db;
        $this->log = $log;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function resolveUserId(array $claims, bool $requireVerified): int
    {
        $sub      = trim((string) ($claims['sub'] ?? ''));
        $email    = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = !empty($claims['email_verified']);

        if ($sub === '') {
            $this->lastError = 'no_sub';

            return 0;
        }

        $userId = $this->findBySubject($sub);

        if ($userId <= 0 && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($requireVerified && !$verified) {
                $this->lastError = 'email_unverified';

                return 0;
            }

            $userId = $this->findByEmail($email);

            if ($userId <= 0) {
                $userId = $this->createUser($claims, $email);
            }
        }

        if ($userId <= 0) {
            $this->lastError = $this->lastError ?: 'no_user';

            return 0;
        }

        $this->rememberIdentity($sub, $userId, $email);

        return $userId;
    }

    private function findBySubject(string $sub): int
    {
        try {
            $db = $this->db;
            $q  = $db->getQuery(true)
                ->select($db->quoteName('user_id'))
                ->from($db->quoteName('numistr_auth_identities'))
                ->where($db->quoteName('subject') . ' = ' . $db->quote($sub))
                ->setLimit(1);
            $db->setQuery($q);

            return (int) $db->loadResult();
        } catch (\Throwable $e) {
            ($this->log)('identity-lookup', $e->getMessage());

            return 0;
        }
    }

    private function findByEmail(string $email): int
    {
        $db = $this->db;
        $q  = $db->getQuery(true)
            ->select('id')
            ->from($db->quoteName('#__users'))
            ->where('LOWER(' . $db->quoteName('email') . ') = ' . $db->quote($email))
            ->where($db->quoteName('block') . ' = 0')
            ->setLimit(1);
        $db->setQuery($q);

        return (int) $db->loadResult();
    }

    private function createUser(array $claims, string $email): int
    {
        try {
            $db   = $this->db;
            $now  = Factory::getDate()->toSql();
            $name = trim((string) ($claims['name'] ?? $claims['nickname'] ?? explode('@', $email)[0]));
            $name = $name !== '' ? mb_substr($name, 0, 100) : explode('@', $email)[0];

            $data = [
                'name'          => $name,
                'username'      => $this->uniqueUsername($email),
                'email'         => $email,
                'password'      => bin2hex(random_bytes(32)), // never used; login is via Auth0
                'block'         => 0,
                'sendEmail'     => 0,
                'registerDate'  => $now,
                'lastvisitDate' => $now,
                'params'        => '{}',
            ];

            $q = $db->getQuery(true)
                ->insert($db->quoteName('#__users'))
                ->columns($db->quoteName(array_keys($data)))
                ->values(implode(',', array_map([$db, 'quote'], $data)));
            $db->setQuery($q);
            $db->execute();
            $userId = (int) $db->insertid();

            if ($userId <= 0) {
                $this->lastError = 'insert_failed';

                return 0;
            }

            $q = $db->getQuery(true)
                ->insert($db->quoteName('#__user_usergroup_map'))
                ->columns($db->quoteName(['user_id', 'group_id']))
                ->values($userId . ', 2');
            $db->setQuery($q);
            $db->execute();

            ($this->log)('auto-register', 'user ' . $userId . ' created for ' . $email);

            return $userId;
        } catch (\Throwable $e) {
            $this->lastError = 'insert_failed';
            ($this->log)('auto-register-error', $e->getMessage());

            return 0;
        }
    }

    private function uniqueUsername(string $email): string
    {
        $db   = $this->db;
        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $email)[0]));
        $base = $base !== '' ? substr($base, 0, 40) : 'user';
        $try  = $base;

        for ($i = 0; $i < 50; $i++) {
            $q = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__users'))->where($db->quoteName('username') . ' = ' . $db->quote($try));
            $db->setQuery($q);

            if ((int) $db->loadResult() === 0) {
                return $try;
            }

            $try = $base . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
        }

        return $base . '_' . time();
    }

    private function rememberIdentity(string $sub, int $userId, string $email): void
    {
        try {
            $db  = $this->db;
            $now = Factory::getDate()->toSql();
            $sql = 'INSERT INTO ' . $db->quoteName('numistr_auth_identities')
                . ' (' . $db->quoteName('subject') . ', ' . $db->quoteName('user_id') . ', ' . $db->quoteName('email') . ', '
                . $db->quoteName('created_at') . ', ' . $db->quoteName('last_seen_at') . ')'
                . ' VALUES (' . $db->quote($sub) . ', ' . $userId . ', ' . $db->quote($email) . ', ' . $db->quote($now) . ', ' . $db->quote($now) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('user_id') . ' = ' . $userId . ', '
                . $db->quoteName('email') . ' = ' . $db->quote($email) . ', ' . $db->quoteName('last_seen_at') . ' = ' . $db->quote($now);
            $db->setQuery($sql);
            $db->execute();
        } catch (\Throwable $e) {
            ($this->log)('identity-upsert', $e->getMessage());
        }
    }
}
