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
 * A23 — e-posta doğrulama iletisini yeniden gönder (POST /v1/auth/resend-verification).
 *
 * Neden: AuthHelper doğrulanmamış e-postaya Joomla hesabı açmaz (require_email_verified —
 * ön-kayıt ele geçirme ve sahte e-postayla kota çoğaltmaya karşı, bilerek). Uygulama +22'de
 * "e-postanızı doğrulayın" paneli gösteriyor; ama ileti gelmezse (spam, yazım, gecikme) kullanıcı
 * takılı kalıyordu: Auth0 yeniden göndermeyi herkese açık bir uçla sunmuyor, yalnız Management API
 * `POST /api/v2/jobs/verification-email` ile (yetki `update:users`).
 *
 * Güvenlik:
 *  - Kimlik yalnız imzası doğrulanmış Auth0 ID token'ından (`sub`); istek gövdesinden kullanıcı
 *    ya da e-posta ALINMAZ → herkes yalnız KENDİ hesabının iletisini tetikleyebilir.
 *  - Yalnız e-posta/şifre hesapları (`auth0|…`); sosyal girişte e-postayı sağlayıcı doğrular.
 *  - Kişi başına dakikada 1 + günde 5, tüm hesaplar için saatte 30 tavan (Auth0 e-posta ve
 *    M2M belirteç kotasını korur). Sayaç denemede artar (Auth0'ya giden her istek sayılır).
 *  - M2M belirteci Joomla önbelleğinde tutulur (dosya önbelleği `die()` önekiyle yazılır,
 *    web'den okunamaz); süresinden 60 sn önce yenilenir. Sır yalnız secrets.php'de.
 *
 * @since  1.19.0
 */
class VerificationResendHelper
{
    const CACHE_GROUP = 'numistr_auth0_mgmt';
    const LIMIT_GROUP = 'numistr_resend';

    /** @var array */
    private $settings;

    /** @var callable  function(string $method, string $url, array $headers, ?string $body): array{status:int,body:string} */
    private $http;

    /** @var callable  function(string $group, string $key, string $window, int $max): bool */
    private $limiter;

    /** @var callable  function(string $key): mixed */
    private $storeGet;

    /** @var callable  function(string $key, mixed $value): void */
    private $storeSet;

    /** @var string */
    private $lastError = '';

    public function __construct(array $config, ?callable $http = null, ?callable $limiter = null, ?callable $storeGet = null, ?callable $storeSet = null)
    {
        $this->settings = self::settings($config);
        $this->http     = $http ?: array($this, 'curlRequest');
        $this->limiter  = $limiter ?: function ($group, $key, $window, $max) use ($config) {
            $rl = new NumisTRRateLimiter($config);

            return $rl->checkWindowLimit($group, $key, $window, $max);
        };
        $this->storeGet = $storeGet ?: array($this, 'cacheGet');
        $this->storeSet = $storeSet ?: array($this, 'cacheSet');
    }

    /**
     * constants.php AUTH0.management. Kimlik bilgisi eksikse özellik KAPALI (503).
     *
     * @return  array
     */
    public static function settings(array $config)
    {
        $m = isset($config['AUTH0']['management']) ? (array) $config['AUTH0']['management'] : array();

        $s = array(
            'domain'           => trim((string) ($m['domain'] ?? '')),
            'client_id'        => trim((string) ($m['client_id'] ?? '')),
            'client_secret'    => (string) ($m['client_secret'] ?? ''),
            'verify_client_id' => trim((string) ($m['verify_client_id'] ?? '')),
            'per_minute'       => isset($m['per_minute']) ? max(0, (int) $m['per_minute']) : 1,
            'per_day'          => isset($m['per_day']) ? max(0, (int) $m['per_day']) : 5,
            'global_per_hour'  => isset($m['global_per_hour']) ? max(0, (int) $m['global_per_hour']) : 30,
        );

        $s['configured'] = $s['domain'] !== '' && $s['client_id'] !== '' && $s['client_secret'] !== '' && $s['verify_client_id'] !== '';

        return $s;
    }

    /**
     * Saf karar: bu belirtecin sahibi için ileti gönderilebilir mi?
     *
     * @param   array|null  $claims  İmzası doğrulanmış ID token yükü; null = doğrulanamadı
     *
     * @return  array  ['ok'=>bool,'status'=>int,'code'=>string]
     */
    public static function eligibility($claims)
    {
        if (!is_array($claims) || empty($claims['sub']) || !is_string($claims['sub'])) {
            return array('ok' => false, 'status' => 401, 'code' => 'INVALID_TOKEN');
        }

        if (!empty($claims['email_verified'])) {
            return array('ok' => false, 'status' => 200, 'code' => 'ALREADY_VERIFIED');
        }

        if (strpos($claims['sub'], 'auth0|') !== 0) {
            return array('ok' => false, 'status' => 400, 'code' => 'NOT_APPLICABLE');
        }

        return array('ok' => true, 'status' => 200, 'code' => '');
    }

    /**
     * Saf: uygulanacak pencereler (sıra önemli: en dar pencere önce, bekleme süresi en kısa).
     *
     * @return  array  [['key'=>string,'window'=>string,'max'=>int,'retry_after'=>int], ...]
     */
    public static function limitPlan(array $s, $sub, $now)
    {
        $now  = (int) $now;
        $hash = hash('sha256', (string) $sub);

        return array(
            array('key' => 'm_' . $hash, 'window' => 'm' . intdiv($now, 60), 'max' => (int) $s['per_minute'], 'retry_after' => 60 - ($now % 60)),
            array('key' => 'd_' . $hash, 'window' => gmdate('Y-m-d', $now), 'max' => (int) $s['per_day'], 'retry_after' => 86400 - ($now % 86400)),
            array('key' => 'global', 'window' => 'h' . intdiv($now, 3600), 'max' => (int) $s['global_per_hour'], 'retry_after' => 3600 - ($now % 3600)),
        );
    }

    /**
     * Uçtan uca işlem. Yanıt doğrudan istemciye gider.
     *
     * @param   array|null  $claims  İmzası doğrulanmış ID token yükü
     * @param   int|null    $now
     *
     * @return  array  ['status'=>int,'code'=>string,'body'=>array,'retry_after'=>int]
     */
    public function resend($claims, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $el  = self::eligibility($claims);

        if (!$el['ok']) {
            return $this->result($el['status'], $el['code'], array('sent' => false));
        }

        if (!$this->settings['configured']) {
            return $this->result(503, 'RESEND_UNAVAILABLE', array('sent' => false));
        }

        foreach (self::limitPlan($this->settings, $claims['sub'], $now) as $w) {
            if ($w['max'] > 0 && !call_user_func($this->limiter, self::LIMIT_GROUP, $w['key'], $w['window'], $w['max'])) {
                return $this->result(429, 'RESEND_RATE_LIMITED', array('sent' => false), $w['retry_after']);
            }
        }

        $token = $this->managementToken($now);

        if ($token === null) {
            return $this->result(502, 'RESEND_FAILED', array('sent' => false));
        }

        $res = call_user_func(
            $this->http,
            'POST',
            'https://' . $this->settings['domain'] . '/api/v2/jobs/verification-email',
            array('Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'),
            json_encode(array('user_id' => $claims['sub'], 'client_id' => $this->settings['verify_client_id']))
        );

        $status = (int) ($res['status'] ?? 0);

        if ($status === 201 || $status === 200) {
            $retry = (int) $this->settings['per_minute'] > 0 ? 60 : 0;

            return $this->result(200, '', array('sent' => true, 'retry_after' => $retry));
        }

        if ($status === 401 || $status === 403) {
            // Belirteç iptal edilmiş / yetkisi değişmiş olabilir: bir dahaki istek yenisini alsın.
            call_user_func($this->storeSet, $this->tokenKey(), null);
        }

        $this->lastError = 'jobs/verification-email http=' . $status . ' ' . substr((string) ($res['body'] ?? ''), 0, 200);

        // Belirteç doğrulamadan önce alınmış olabilir (email_verified=false kalır); Auth0 doğrulanmış
        // hesap için 400 döner. Bu bir hata değil: istemci "Doğruladım, giriş yap"a yönlendirir.
        if ($status === 400 && stripos((string) ($res['body'] ?? ''), 'verified') !== false) {
            return $this->result(200, 'ALREADY_VERIFIED', array('sent' => false));
        }

        if ($status === 429) {
            return $this->result(429, 'RESEND_RATE_LIMITED', array('sent' => false), 60);
        }

        return $this->result(502, 'RESEND_FAILED', array('sent' => false));
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Management API belirteci (client_credentials). Önbellekte geçerliyse ağ isteği yok.
     */
    private function managementToken($now)
    {
        $key    = $this->tokenKey();
        $cached = call_user_func($this->storeGet, $key);

        if (is_array($cached) && !empty($cached['t']) && (int) ($cached['exp'] ?? 0) > $now + 60) {
            return (string) $cached['t'];
        }

        $res = call_user_func(
            $this->http,
            'POST',
            'https://' . $this->settings['domain'] . '/oauth/token',
            array('Content-Type: application/json', 'Accept: application/json'),
            json_encode(array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->settings['client_id'],
                'client_secret' => $this->settings['client_secret'],
                'audience'      => 'https://' . $this->settings['domain'] . '/api/v2/',
            ))
        );

        $data = json_decode((string) ($res['body'] ?? ''), true);

        if ((int) ($res['status'] ?? 0) !== 200 || !is_array($data) || empty($data['access_token'])) {
            // Yanıt gövdesi sır içermez (hata metni); yine de kısaltılır.
            $this->lastError = 'oauth/token http=' . (int) ($res['status'] ?? 0) . ' ' . substr((string) ($res['body'] ?? ''), 0, 200);

            return null;
        }

        $ttl = isset($data['expires_in']) ? max(0, (int) $data['expires_in']) : 3600;
        call_user_func($this->storeSet, $key, array('t' => (string) $data['access_token'], 'exp' => $now + $ttl));

        return (string) $data['access_token'];
    }

    private function tokenKey()
    {
        return 'tok_' . sha1($this->settings['domain'] . '|' . $this->settings['client_id']);
    }

    private function result($status, $code, array $body, $retryAfter = 0)
    {
        if ($code !== '') {
            $body['code'] = $code;
        }

        if ($retryAfter > 0) {
            $body['retry_after'] = (int) $retryAfter;
        }

        return array('status' => (int) $status, 'code' => $code, 'body' => $body, 'retry_after' => (int) $retryAfter);
    }

    // ------------------------------------------------------------ varsayılan G/Ç ---

    /** @internal varsayılan HTTP istemcisi */
    public function curlRequest($method, $url, array $headers, $body)
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ));
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            return array('status' => $raw === false ? 0 : $status, 'body' => $raw === false ? $err : (string) $raw);
        } catch (\Throwable $e) {
            return array('status' => 0, 'body' => $e->getMessage());
        }
    }

    /** @internal */
    public function cacheGet($key)
    {
        try {
            return $this->cacheController()->get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @internal */
    public function cacheSet($key, $value)
    {
        try {
            $cache = $this->cacheController();

            if ($value === null) {
                $cache->remove($key);
            } else {
                $cache->store($value, $key);
            }
        } catch (\Throwable $e) {
            // önbellek yoksa her istekte yeni belirteç alınır; tavanlar bunu sınırlar
        }
    }

    /** RateLimiter::cacheController ile aynı gerekçe: sitenin önbellek ayarından bağımsız. */
    private function cacheController()
    {
        $options = array('defaultgroup' => self::CACHE_GROUP, 'caching' => true, 'lifetime' => 1440);

        return Factory::getContainer()
            ->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)
            ->createCacheController('output', $options);
    }
}
