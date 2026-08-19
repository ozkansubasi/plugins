<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2026 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * RevenueCat webhook alıcısı
 *
 * POST /v1/billing/revenuecat
 *
 * Kimlik doğrulama: RevenueCat panelinde tanımlanan Authorization header
 * değeri, config/secrets.php içindeki paylaşılan sırla karşılaştırılır.
 *
 * Görev: satın alma/yenileme olaylarında kullanıcıyı Joomla Pro grubuna al,
 * süre bitiminde gruptan çıkar. Tüm olaylar numistr_billing_events tablosuna
 * yazılır (idempotency + denetim izi).
 *
 * @since 1.5.0
 */
class BillingController
{
    /**
     * Webhook giriş noktası
     */
    public static function revenueCatWebhook(): void
    {
        $response = new NumisTRResponseHelper();

        $configPath = __DIR__ . '/../config/constants.php';
        $config     = file_exists($configPath) ? include $configPath : [];
        $rcConfig   = $config['REVENUECAT'] ?? [];

        // ---- 1. Yöntem kontrolü ----
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'OPTIONS') {
            $response->sendJson(['ok' => true]);
            return;
        }

        if ($method !== 'POST') {
            $response->sendError(405, 'Method Not Allowed', 'Use POST');
            return;
        }

        // ---- 2. Paylaşılan sır doğrulaması ----
        $secret = (string) ($rcConfig['webhook_secret'] ?? '');

        if ($secret === '') {
            self::log('config', 'webhook secret not configured (config/secrets.php)');
            $response->sendError(503, 'Service Unavailable', 'Webhook is not configured');
            return;
        }

        $provided = self::authorizationHeader();

        // RevenueCat header'ı olduğu gibi gönderir; "Bearer " öneki varsa da kabul et.
        if (stripos($provided, 'Bearer ') === 0) {
            $provided = substr($provided, 7);
        }

        if (!hash_equals($secret, trim($provided))) {
            self::log('auth', 'invalid webhook authorization header');
            $response->sendError(401, 'Unauthorized', 'Invalid webhook secret');
            return;
        }

        // ---- 3. Payload ----
        $raw     = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true);
        $event   = $payload['event'] ?? null;

        if (!is_array($event)) {
            $response->sendError(400, 'Bad Request', 'Missing event object');
            return;
        }

        $eventId     = (string) ($event['id'] ?? '');
        $eventType   = strtoupper((string) ($event['type'] ?? ''));
        $appUserId   = (string) ($event['app_user_id'] ?? '');
        $environment = strtoupper((string) ($event['environment'] ?? ''));

        if ($eventId === '' || $eventType === '') {
            $response->sendError(400, 'Bad Request', 'Missing event id/type');
            return;
        }

        // RevenueCat bağlantı testi: gerçek abonelik verisi taşımaz
        if ($eventType === 'TEST') {
            self::recordEvent($event, $raw, 0, 'test');
            $response->sendJson(['ok' => true, 'action' => 'test', 'event_id' => $eventId]);
            return;
        }

        // ---- 4. Idempotency: aynı event bir kez işlenir ----
        if (self::eventAlreadyProcessed($eventId)) {
            self::log('duplicate', 'event ' . $eventId . ' already processed');
            $response->sendJson(['ok' => true, 'action' => 'duplicate', 'event_id' => $eventId]);
            return;
        }

        // ---- 5. Sandbox politikası ----
        if ($environment === 'SANDBOX' && empty($rcConfig['allow_sandbox'])) {
            self::recordEvent($event, $raw, 0, 'ignored_sandbox');
            $response->sendJson(['ok' => true, 'action' => 'ignored_sandbox', 'event_id' => $eventId]);
            return;
        }

        // ---- 6. Kullanıcıyı çöz ----
        $userId = self::resolveUserId($event);

        if ($userId <= 0) {
            // 200 dönüyoruz: tekrar denemek sonucu değiştirmez, kayıt manuel eşleme için tutulur.
            self::recordEvent($event, $raw, 0, 'user_not_found');
            self::log('user-not-found', 'app_user_id=' . $appUserId . ' event=' . $eventType);
            $response->sendJson([
                'ok'       => true,
                'action'   => 'user_not_found',
                'event_id' => $eventId,
            ]);
            return;
        }

        // ---- 7. Karar: hak ver / hak kaldır ----
        $action = self::decideAction($event, $rcConfig);
        $membership = new NumisTRMembershipHelper($config);
        $changed = false;

        if ($action === 'grant') {
            $changed = $membership->grantPro($userId);
        } elseif ($action === 'revoke') {
            $changed = $membership->revokePro($userId);
        }

        self::recordEvent($event, $raw, $userId, $action . ($changed ? '' : '_noop'));
        self::log(
            'processed',
            'event=' . $eventType . ' user=' . $userId . ' action=' . $action . ' changed=' . ($changed ? '1' : '0')
        );

        $response->sendJson([
            'ok'       => true,
            'action'   => $action,
            'changed'  => $changed,
            'user_id'  => $userId,
            'event_id' => $eventId,
        ]);
    }

    /**
     * Event türü ve süresine göre karar üret
     *
     * @return string 'grant' | 'revoke' | 'ignore'
     */
    private static function decideAction(array $event, array $rcConfig): string
    {
        $type         = strtoupper((string) ($event['type'] ?? ''));
        $grantEvents  = $rcConfig['grant_events'] ?? [];
        $revokeEvents = $rcConfig['revoke_events'] ?? [];

        if (in_array($type, $revokeEvents, true)) {
            return 'revoke';
        }

        // İptal: normalde dönem sonuna kadar Pro kalır. Yalnızca iade/destek
        // kaynaklı iptallerde hak anında kaldırılır.
        if ($type === 'CANCELLATION') {
            $reason = strtoupper((string) ($event['cancel_reason'] ?? ''));

            return in_array($reason, $rcConfig['revoke_cancel_reasons'] ?? [], true) ? 'revoke' : 'ignore';
        }

        if (in_array($type, $grantEvents, true)) {
            // Süresi geçmiş bir olay Pro hakkı vermemeli (ör. gecikmiş teslim)
            $expiresAtMs = $event['expiration_at_ms'] ?? null;

            if ($expiresAtMs !== null && is_numeric($expiresAtMs)) {
                $expiresAt = (int) floor(((float) $expiresAtMs) / 1000);

                if ($expiresAt > 0 && $expiresAt < time()) {
                    return 'revoke';
                }
            }

            return 'grant';
        }

        return 'ignore';
    }

    /**
     * RevenueCat app_user_id (Auth0 sub) -> Joomla user id
     *
     * Sırayla: kimlik eşleme tablosu (app_user_id + aliases) -> subscriber
     * attribute e-postası -> #__users.email
     */
    private static function resolveUserId(array $event): int
    {
        $candidates = [];

        if (!empty($event['app_user_id'])) {
            $candidates[] = (string) $event['app_user_id'];
        }

        if (!empty($event['original_app_user_id'])) {
            $candidates[] = (string) $event['original_app_user_id'];
        }

        foreach ((array) ($event['aliases'] ?? []) as $alias) {
            $candidates[] = (string) $alias;
        }

        $db = Factory::getDbo();

        foreach (array_unique($candidates) as $subject) {
            if ($subject === '') {
                continue;
            }

            // RevenueCat anonim id'leri kullanıcıyı temsil etmez
            if (strpos($subject, '$RCAnonymousID:') === 0) {
                continue;
            }

            try {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('user_id'))
                    ->from($db->quoteName('numistr_auth_identities'))
                    ->where($db->quoteName('subject') . ' = ' . $db->quote($subject))
                    ->setLimit(1);

                $db->setQuery($query);
                $userId = (int) $db->loadResult();

                if ($userId > 0) {
                    return $userId;
                }
            } catch (\Throwable $e) {
                self::log('identity-lookup-error', $e->getMessage());
            }
        }

        // Yedek yol: subscriber attribute olarak gönderilen e-posta
        $email = $event['subscriber_attributes']['$email']['value'] ?? null;

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                $query = $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('email') . ' = ' . $db->quote($email))
                    ->where($db->quoteName('block') . ' = 0')
                    ->setLimit(1);

                $db->setQuery($query);
                $userId = (int) $db->loadResult();

                if ($userId > 0) {
                    return $userId;
                }
            } catch (\Throwable $e) {
                self::log('email-lookup-error', $e->getMessage());
            }
        }

        return 0;
    }

    /**
     * Bu event daha önce işlendi mi?
     */
    private static function eventAlreadyProcessed(string $eventId): bool
    {
        try {
            $db = Factory::getDbo();

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('numistr_billing_events'))
                ->where($db->quoteName('event_id') . ' = ' . $db->quote($eventId));

            $db->setQuery($query);

            return (int) $db->loadResult() > 0;
        } catch (\Throwable $e) {
            // Tablo yoksa idempotency devre dışı kalır ama webhook çalışmaya devam eder.
            self::log('event-check-error', $e->getMessage());

            return false;
        }
    }

    /**
     * Olayı denetim tablosuna yaz
     */
    private static function recordEvent(array $event, string $raw, int $userId, string $action): void
    {
        try {
            $db  = Factory::getDbo();
            $now = Factory::getDate()->toSql();

            $expiresAt = null;

            if (!empty($event['expiration_at_ms']) && is_numeric($event['expiration_at_ms'])) {
                $expiresAt = date('Y-m-d H:i:s', (int) floor(((float) $event['expiration_at_ms']) / 1000));
            }

            $query = 'INSERT INTO ' . $db->quoteName('numistr_billing_events') . ' ('
                . $db->quoteName('event_id') . ', '
                . $db->quoteName('event_type') . ', '
                . $db->quoteName('app_user_id') . ', '
                . $db->quoteName('user_id') . ', '
                . $db->quoteName('product_id') . ', '
                . $db->quoteName('environment') . ', '
                . $db->quoteName('action') . ', '
                . $db->quoteName('expires_at') . ', '
                . $db->quoteName('payload') . ', '
                . $db->quoteName('created_at')
                . ') VALUES ('
                . $db->quote((string) ($event['id'] ?? '')) . ', '
                . $db->quote((string) ($event['type'] ?? '')) . ', '
                . $db->quote((string) ($event['app_user_id'] ?? '')) . ', '
                . (int) $userId . ', '
                . $db->quote((string) ($event['product_id'] ?? '')) . ', '
                . $db->quote((string) ($event['environment'] ?? '')) . ', '
                . $db->quote($action) . ', '
                . ($expiresAt === null ? 'NULL' : $db->quote($expiresAt)) . ', '
                . $db->quote(mb_substr($raw, 0, 60000)) . ', '
                . $db->quote($now)
                . ') ON DUPLICATE KEY UPDATE '
                . $db->quoteName('action') . ' = ' . $db->quote($action) . ', '
                . $db->quoteName('user_id') . ' = ' . (int) $userId;

            $db->setQuery($query);
            $db->execute();
        } catch (\Throwable $e) {
            self::log('record-error', $e->getMessage());
        }
    }

    /**
     * Authorization header'ını sunucu değişkenlerinden çıkar
     */
    private static function authorizationHeader(): string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        ];

        foreach ($candidates as $value) {
            if ($value !== '') {
                return (string) $value;
            }
        }

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    /**
     * Teşhis logu
     */
    private static function log(string $branch, string $message): void
    {
        try {
            $logger = Factory::getContainer()->get('logger');
            $logger->info('[NumisTR-Billing] branch="' . $branch . '" msg="' . $message . '"');
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
