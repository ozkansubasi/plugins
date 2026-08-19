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
 * Auth0 JWT doğrulayıcı (RS256 + JWKS)
 *
 * Bağımlılık yok: imza doğrulaması openssl ile, JWK -> PEM dönüşümü
 * elle DER kodlamasıyla yapılır. JWKS dosya sisteminde cache'lenir.
 *
 * Doğrulanan iddialar: imza, alg, iss, aud, exp, nbf.
 *
 * @since 1.5.0
 */
class NumisTRJwtVerifier
{
    /**
     * AUTH0 config bloğu (constants.php içindeki 'AUTH0')
     *
     * @var array
     */
    private $config;

    /**
     * Son doğrulama hatası (teşhis için)
     *
     * @var string
     */
    private $lastError = '';

    public function __construct(array $auth0Config)
    {
        $this->config = $auth0Config;
    }

    /**
     * Son hatayı döndürür
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * JWT'yi doğrula ve claim'leri döndür.
     *
     * @param   string  $jwt  Ham JWT
     *
     * @return  array|null  Doğrulanmış claim'ler, geçersizse null
     */
    public function verify(string $jwt): ?array
    {
        $this->lastError = '';

        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            // 5 parça = JWE (şifreli token). Eski app sürümleri bunu gönderiyordu.
            $this->lastError = count($parts) === 5
                ? 'Encrypted token (JWE) is not supported'
                : 'Malformed JWT (expected 3 segments, got ' . count($parts) . ')';

            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode((string) self::base64UrlDecode($headerB64), true);
        $claims = json_decode((string) self::base64UrlDecode($payloadB64), true);

        if (!is_array($header) || !is_array($claims)) {
            $this->lastError = 'Header/payload decode failed';

            return null;
        }

        // ---- alg kontrolü (alg=none ve HS256 downgrade saldırılarına karşı) ----
        $alg = (string) ($header['alg'] ?? '');
        $allowedAlgs = $this->config['algorithms'] ?? ['RS256'];

        if ($alg === '' || !in_array($alg, $allowedAlgs, true)) {
            $this->lastError = 'Disallowed alg: ' . ($alg ?: '(empty)');

            return null;
        }

        $opensslAlg = self::opensslAlgFor($alg);

        if ($opensslAlg === null) {
            $this->lastError = 'Unsupported alg: ' . $alg;

            return null;
        }

        // ---- iss kontrolü ----
        $issuer = (string) ($claims['iss'] ?? '');
        $allowedIssuers = array_map([self::class, 'normalizeIssuer'], $this->config['issuers'] ?? []);

        if ($issuer === '' || !in_array(self::normalizeIssuer($issuer), $allowedIssuers, true)) {
            $this->lastError = 'Untrusted issuer: ' . ($issuer ?: '(empty)');

            return null;
        }

        // ---- aud kontrolü ----
        $allowedAudiences = $this->config['audiences'] ?? [];

        if (!empty($allowedAudiences)) {
            $tokenAud = $claims['aud'] ?? null;
            $tokenAudList = is_array($tokenAud) ? $tokenAud : ($tokenAud === null ? [] : [$tokenAud]);

            if (empty(array_intersect($tokenAudList, $allowedAudiences))) {
                $this->lastError = 'Audience mismatch: ' . json_encode($tokenAud);

                return null;
            }
        }

        // ---- zaman kontrolleri ----
        $leeway = (int) ($this->config['leeway'] ?? 60);
        $now    = time();

        if (isset($claims['exp']) && is_numeric($claims['exp']) && ($claims['exp'] + $leeway) < $now) {
            $this->lastError = 'Token expired at ' . (int) $claims['exp'];

            return null;
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && ($claims['nbf'] - $leeway) > $now) {
            $this->lastError = 'Token not yet valid (nbf)';

            return null;
        }

        // ---- imza doğrulaması ----
        $kid = (string) ($header['kid'] ?? '');

        if ($kid === '') {
            $this->lastError = 'Missing kid in JWT header';

            return null;
        }

        $signature   = self::base64UrlDecode($signatureB64);
        $signedInput = $headerB64 . '.' . $payloadB64;

        if ($signature === false || $signature === '') {
            $this->lastError = 'Signature decode failed';

            return null;
        }

        // Önce cache'lenmiş JWKS ile dene; kid bulunamazsa (anahtar rotasyonu) bir kez yenile.
        $pem = $this->publicKeyFor($issuer, $kid, false);

        if ($pem === null) {
            $pem = $this->publicKeyFor($issuer, $kid, true);
        }

        if ($pem === null) {
            $this->lastError = 'No JWKS key for kid=' . $kid;

            return null;
        }

        $ok = openssl_verify($signedInput, $signature, $pem, $opensslAlg);

        if ($ok !== 1) {
            $this->lastError = 'Signature verification failed';

            return null;
        }

        return $claims;
    }

    /**
     * Belirtilen kid için PEM public key döndür.
     *
     * @param   string  $issuer        Token issuer'ı
     * @param   string  $kid           Anahtar kimliği
     * @param   bool    $forceRefresh  Cache'i atlayıp JWKS'i yeniden indir
     *
     * @return  string|null
     */
    private function publicKeyFor(string $issuer, string $kid, bool $forceRefresh): ?string
    {
        $jwks = $this->loadJwks($issuer, $forceRefresh);

        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return self::jwkToPem($key);
            }
        }

        return null;
    }

    /**
     * JWKS'i cache'den ya da ağdan yükle.
     *
     * @return  array  JWKS yapısı (başarısızsa boş dizi)
     */
    private function loadJwks(string $issuer, bool $forceRefresh): array
    {
        $url       = self::normalizeIssuer($issuer) . '/.well-known/jwks.json';
        $cacheFile = $this->cacheFileFor($issuer);
        $ttl       = (int) ($this->config['jwks_ttl'] ?? 43200);

        if (!$forceRefresh && $cacheFile !== null && is_readable($cacheFile)) {
            $age = time() - (int) @filemtime($cacheFile);

            if ($age < $ttl) {
                $cached = json_decode((string) @file_get_contents($cacheFile), true);

                if (is_array($cached) && !empty($cached['keys'])) {
                    return $cached;
                }
            }
        }

        $fetched = $this->fetchJwks($url);

        if ($fetched !== null && !empty($fetched['keys'])) {
            if ($cacheFile !== null) {
                @file_put_contents($cacheFile, json_encode($fetched), LOCK_EX);
            }

            return $fetched;
        }

        // Ağ hatası: bayat cache varsa onu kullan (kısa kesinti dayanıklılığı).
        if ($cacheFile !== null && is_readable($cacheFile)) {
            $stale = json_decode((string) @file_get_contents($cacheFile), true);

            if (is_array($stale) && !empty($stale['keys'])) {
                return $stale;
            }
        }

        return [];
    }

    /**
     * JWKS endpoint'inden anahtarları indir.
     */
    private function fetchJwks(string $url): ?array
    {
        try {
            $ch = curl_init();

            curl_setopt_array(
                $ch,
                [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => (int) ($this->config['jwks_timeout'] ?? 8),
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                ]
            );

            $body     = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($body === false || $httpCode !== 200) {
                $this->log('jwks-fetch-error', 'HTTP ' . $httpCode . ' ' . $curlErr . ' url=' . $url);

                return null;
            }

            $data = json_decode((string) $body, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->log('jwks-fetch-exception', $e->getMessage());

            return null;
        }
    }

    /**
     * JWKS cache dosya yolu (yazılabilir bir dizin bulunamazsa null).
     */
    private function cacheFileFor(string $issuer): ?string
    {
        $name = 'numistr_jwks_' . sha1(self::normalizeIssuer($issuer)) . '.json';

        $candidates = [];

        if (defined('JPATH_CACHE')) {
            $candidates[] = JPATH_CACHE;
        }

        if (defined('JPATH_SITE')) {
            $candidates[] = JPATH_SITE . '/cache';
        }

        $candidates[] = sys_get_temp_dir();

        foreach ($candidates as $dir) {
            if ($dir && is_dir($dir) && is_writable($dir)) {
                return rtrim($dir, '/\\') . '/' . $name;
            }
        }

        return null;
    }

    // ========================================================================
    // JWK -> PEM (RSA)
    // ========================================================================

    /**
     * RSA JWK'yı PEM public key'e çevir.
     *
     * @param   array  $jwk  JWKS içindeki tek anahtar
     *
     * @return  string|null
     */
    private static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        // x5c varsa sertifikadan doğrudan public key çıkarmak en güvenlisi.
        if (!empty($jwk['x5c'][0])) {
            $cert = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split((string) $jwk['x5c'][0], 64, "\n")
                . "-----END CERTIFICATE-----\n";

            $pubKey = @openssl_pkey_get_public($cert);

            if ($pubKey !== false) {
                $details = @openssl_pkey_get_details($pubKey);

                if (!empty($details['key'])) {
                    return $details['key'];
                }
            }
        }

        $modulus  = self::base64UrlDecode((string) $jwk['n']);
        $exponent = self::base64UrlDecode((string) $jwk['e']);

        if ($modulus === false || $exponent === false) {
            return null;
        }

        $rsaPublicKey = self::derSequence(
            self::derInteger($modulus) . self::derInteger($exponent)
        );

        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algorithmIdentifier = self::derSequence(
            hex2bin('06092a864886f70d010101') . hex2bin('0500')
        );

        // BIT STRING (unused bits = 0) içine RSAPublicKey
        $bitString = "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $spki = self::derSequence($algorithmIdentifier . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * DER INTEGER kodla (negatif yorumlanmaması için gerekirse 0x00 ekler).
     */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        if (ord($bytes[0]) > 0x7F) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    /**
     * DER SEQUENCE sarmalayıcı.
     */
    private static function derSequence(string $bytes): string
    {
        return "\x30" . self::derLength(strlen($bytes)) . $bytes;
    }

    /**
     * DER uzunluk kodlaması (kısa/uzun form).
     */
    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';

        while ($length > 0) {
            $bytes  = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    // ========================================================================
    // Yardımcılar
    // ========================================================================

    /**
     * base64url decode (strict).
     *
     * @return  string|false
     */
    public static function base64UrlDecode(string $input)
    {
        $remainder = strlen($input) % 4;

        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'), true);
    }

    /**
     * Issuer'ı sondaki '/' olmadan normalize et.
     */
    public static function normalizeIssuer(string $issuer): string
    {
        return rtrim(trim($issuer), '/');
    }

    /**
     * JWT alg -> OpenSSL algoritma sabiti.
     *
     * @return  int|null
     */
    private static function opensslAlgFor(string $alg): ?int
    {
        switch ($alg) {
            case 'RS256':
                return OPENSSL_ALGO_SHA256;
            case 'RS384':
                return OPENSSL_ALGO_SHA384;
            case 'RS512':
                return OPENSSL_ALGO_SHA512;
            default:
                return null;
        }
    }

    /**
     * Teşhis logu
     */
    private function log(string $branch, string $message): void
    {
        try {
            $logger = Factory::getContainer()->get('logger');
            $logger->info('[NumisTR-JWT] branch="' . $branch . '" msg="' . $message . '"');
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
