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
 * Auth0 Management API istemcisi (M2M, client_credentials) — ortak kod.
 *
 * 1.19.0'da VerificationResendHelper'ın içindeydi; 1.20.0'da hesap silme (`DELETE /api/v2/users/{id}`)
 * aynı belirteci kullandığı için buraya taşındı. Davranış aynı:
 *  - Belirteç Joomla önbelleğinde (grup `numistr_auth0_mgmt`, anahtar `tok_` + sha1(domain|client_id) — DEĞİŞMEDİ,
 *    canlıdaki önbellek kaydı geçerli kalır); süresinden 60 sn önce yenilenir.
 *  - Dosya önbelleği `die()` önekiyle yazılır, web'den okunamaz. Sır yalnız secrets.php'de; hata metnine girmez.
 *
 * Yetkiler (M2M uygulaması "NumisTR API — doğrulama iletisi"): `update:users` (A23 yeniden gönder) +
 * `delete:users` (1.20.0 hesap silme). Kapsam sonradan eklendiği için önbellekteki eski belirteç `delete:users`
 * taşımayabilir → silmede 401/403 alınırsa belirteç atılır ve BİR KEZ yeniden denenir.
 *
 * @since  1.20.0
 */
class Auth0ManagementClient
{
    const CACHE_GROUP = 'numistr_auth0_mgmt';

    /** @var array */
    private $settings;

    /** @var callable  function(string $method, string $url, array $headers, ?string $body): array{status:int,body:string} */
    private $http;

    /** @var callable  function(string $key): mixed */
    private $storeGet;

    /** @var callable  function(string $key, mixed $value): void */
    private $storeSet;

    /** @var string */
    private $lastError = '';

    /**
     * @param   array          $settings  self::settings() çıktısı (domain, client_id, client_secret)
     * @param   callable|null  $http      Test için sahte HTTP; null = cURL
     * @param   callable|null  $storeGet  Test için sahte önbellek okuma; null = Joomla önbelleği
     * @param   callable|null  $storeSet  Test için sahte önbellek yazma (null değer = sil)
     */
    public function __construct(array $settings, ?callable $http = null, ?callable $storeGet = null, ?callable $storeSet = null)
    {
        $this->settings = array(
            'domain'        => trim((string) ($settings['domain'] ?? '')),
            'client_id'     => trim((string) ($settings['client_id'] ?? '')),
            'client_secret' => (string) ($settings['client_secret'] ?? ''),
        );
        $this->http     = $http ?: array($this, 'curlRequest');
        $this->storeGet = $storeGet ?: array($this, 'cacheGet');
        $this->storeSet = $storeSet ?: array($this, 'cacheSet');
    }

    /**
     * constants.php AUTH0.management → M2M kimliği. Eksikse istemci kapalı (çağıran 503 döner).
     *
     * @return  array  ['domain','client_id','client_secret','configured'=>bool]
     */
    public static function settings(array $config)
    {
        $m = isset($config['AUTH0']['management']) ? (array) $config['AUTH0']['management'] : array();

        $s = array(
            'domain'        => trim((string) ($m['domain'] ?? '')),
            'client_id'     => trim((string) ($m['client_id'] ?? '')),
            'client_secret' => (string) ($m['client_secret'] ?? ''),
        );

        $s['configured'] = $s['domain'] !== '' && $s['client_id'] !== '' && $s['client_secret'] !== '';

        return $s;
    }

    public function isConfigured()
    {
        return $this->settings['domain'] !== '' && $this->settings['client_id'] !== '' && $this->settings['client_secret'] !== '';
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Management API belirteci (client_credentials). Önbellekte geçerliyse ağ isteği yok.
     *
     * @param   int  $now
     *
     * @return  string|null  null = alınamadı (getLastError'da neden; sır içermez)
     */
    public function token($now)
    {
        $now    = (int) $now;
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

    /** Önbellekteki belirteci at (iptal edilmiş / kapsamı değişmiş olabilir): bir sonraki çağrı yenisini alır. */
    public function forgetToken()
    {
        call_user_func($this->storeSet, $this->tokenKey(), null);
    }

    /**
     * Management API çağrısı (`/api/v2/...`). Belirteci çağıran verir; yeniden deneme yapılmaz.
     *
     * @return  array{status:int,body:string}
     */
    public function call($method, $path, $token, $body = null)
    {
        $headers = array('Authorization: Bearer ' . $token);

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $headers[] = 'Accept: application/json';

        $res = call_user_func($this->http, $method, 'https://' . $this->settings['domain'] . $path, $headers, $body);

        return array('status' => (int) ($res['status'] ?? 0), 'body' => (string) ($res['body'] ?? ''));
    }

    /**
     * Saf: `DELETE /api/v2/users/{id}` yanıt kodunun anlamı.
     *
     * @return  string  'deleted' (204/200) · 'absent' (404 = zaten yok → başarı, idempotent) ·
     *                  'auth' (401/403 → belirteç yenilenip bir kez daha denenir) · 'error'
     */
    public static function deleteOutcome($status)
    {
        $status = (int) $status;

        if ($status === 204 || $status === 200) {
            return 'deleted';
        }

        if ($status === 404) {
            return 'absent';
        }

        if ($status === 401 || $status === 403) {
            return 'auth';
        }

        return 'error';
    }

    /**
     * Auth0 kullanıcısını sil. 404 = zaten silinmiş = başarı.
     *
     * @param   string  $userId  Auth0 user_id (= ID token `sub`, ör. `google-oauth2|123`)
     * @param   int     $now
     *
     * @return  array{ok:bool,outcome:string,status:int}  outcome: deleted|absent|token|auth|error
     */
    public function deleteUser($userId, $now)
    {
        $userId = (string) $userId;

        if ($userId === '') {
            return array('ok' => false, 'outcome' => 'error', 'status' => 0);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->token($now);

            if ($token === null) {
                return array('ok' => false, 'outcome' => 'token', 'status' => 0);
            }

            $res     = $this->call('DELETE', '/api/v2/users/' . rawurlencode($userId), $token);
            $outcome = self::deleteOutcome($res['status']);

            if ($outcome === 'deleted' || $outcome === 'absent') {
                return array('ok' => true, 'outcome' => $outcome, 'status' => $res['status']);
            }

            $this->lastError = 'DELETE users http=' . $res['status'] . ' ' . substr($res['body'], 0, 200);

            if ($outcome !== 'auth') {
                return array('ok' => false, 'outcome' => $outcome, 'status' => $res['status']);
            }

            // Belirteç eski kapsamla (yalnız update:users) önbellekte kalmış olabilir: at, bir kez yenisiyle dene.
            $this->forgetToken();
        }

        return array('ok' => false, 'outcome' => 'auth', 'status' => $res['status']);
    }

    public function tokenKey()
    {
        return 'tok_' . sha1($this->settings['domain'] . '|' . $this->settings['client_id']);
    }

    // ------------------------------------------------------------ varsayılan G/Ç ---

    /** @internal varsayılan HTTP istemcisi */
    public function curlRequest($method, $url, array $headers, $body)
    {
        try {
            $ch   = curl_init($url);
            $opts = array(
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            );

            // Gövdesiz istekte (DELETE) POSTFIELDS verilmez: aksi hâlde cURL boş form gövdesi + form Content-Type ekler.
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }

            curl_setopt_array($ch, $opts);
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
            // önbellek yoksa her istekte yeni belirteç alınır; çağıranın tavanları bunu sınırlar
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
