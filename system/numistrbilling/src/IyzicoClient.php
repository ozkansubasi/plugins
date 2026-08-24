<?php
/**
 * @package     NumisTR Billing (iyzico web subscription)
 * @subpackage  plg_system_numistrbilling
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * iyzico v2 API istemcisi (IYZWSv2 / HMACSHA256 imza).
 * SDK bağımlılığı yok; imza şeması scripts/iyzico_bootstrap.py ile
 * sandbox'ta ampirik doğrulandı (ADR-004).
 */

defined('_JEXEC') or die;

class NumistrIyzicoClient
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $secretKey;

    /** @var string */
    private $baseUrl;

    /** @var callable|null fn(string $branch, string $message): void */
    private $logger;

    public function __construct(string $apiKey, string $secretKey, string $baseUrl, ?callable $logger = null)
    {
        $this->apiKey    = trim($apiKey);
        $this->secretKey = trim($secretKey);
        $this->baseUrl   = rtrim(trim($baseUrl), '/');
        $this->logger    = $logger;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->secretKey !== '' && $this->baseUrl !== '';
    }

    // ------------------------------------------------------------------
    // Abonelik uçları
    // ------------------------------------------------------------------

    /**
     * Checkout Form ile abonelik başlat.
     * DİKKAT: yanıt 'data' sarmalayıcısı OLMADAN kök seviyede döner
     * (token, checkoutFormContent, tokenExpireTime). ADR-004 ampirik not.
     */
    public function initializeCheckoutForm(array $body): array
    {
        return $this->request('POST', '/v2/subscription/checkoutform/initialize', $body);
    }

    /** Checkout Form sonucunu token ile sorgula (callback sonrası ZORUNLU doğrulama). */
    public function retrieveCheckoutFormResult(string $token): array
    {
        return $this->request('GET', '/v2/subscription/checkoutform/' . rawurlencode($token));
    }

    /** Abonelik detayı ('data' sarmalayıcılı). */
    public function getSubscription(string $subscriptionReferenceCode): array
    {
        return $this->request('GET', '/v2/subscription/subscriptions/' . rawurlencode($subscriptionReferenceCode));
    }

    /** Aboneliği iptal et (gelecek tahsilatlar durur). */
    public function cancelSubscription(string $subscriptionReferenceCode): array
    {
        return $this->request(
            'POST',
            '/v2/subscription/subscriptions/' . rawurlencode($subscriptionReferenceCode) . '/cancel',
            ['subscriptionReferenceCode' => $subscriptionReferenceCode]
        );
    }

    /** Kart güncelleme Checkout Form'u başlat (yanıt kök seviyede, CF init gibi). */
    public function initializeCardUpdateForm(array $body): array
    {
        return $this->request('POST', '/v2/subscription/card-update/checkoutform/initialize', $body);
    }

    // ------------------------------------------------------------------
    // HTTP + IYZWSv2 imza
    // ------------------------------------------------------------------

    /**
     * @return array Çözümlenmiş JSON. Ağ/parse hatasında
     *               ['status' => 'failure', 'errorCode' => 'transport', ...]
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'failure', 'errorCode' => 'config', 'errorMessage' => 'iyzico client not configured'];
        }

        $bodyStr = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        try {
            $random = (string) random_int(100000000, 999999999);
        } catch (\Throwable $e) {
            $random = (string) mt_rand(100000000, 999999999);
        }

        // signature = hex(HMACSHA256(randomKey + uri.path + request.body, secretKey))
        $signature = hash_hmac('sha256', $random . $path . $bodyStr, $this->secretKey);
        $authStr   = 'apiKey:' . $this->apiKey . '&randomKey:' . $random . '&signature:' . $signature;

        $headers = [
            'Authorization' => 'IYZWSv2 ' . base64_encode($authStr),
            'x-iyzi-rnd'    => $random,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];

        try {
            $http = \Joomla\CMS\Http\HttpFactory::getHttp([], ['curl', 'stream']);
            $url  = $this->baseUrl . $path;

            if ($method === 'GET') {
                $response = $http->get($url, $headers, 30);
            } elseif ($method === 'DELETE') {
                $response = $http->delete($url, $headers, 30);
            } else {
                $response = $http->post($url, $bodyStr, $headers, 30);
            }

            $decoded = json_decode((string) $response->body, true);

            if (!is_array($decoded)) {
                $this->log('http', 'non-json response code=' . $response->code . ' path=' . $path);

                return [
                    'status'       => 'failure',
                    'errorCode'    => 'transport',
                    'errorMessage' => 'Non-JSON response (HTTP ' . $response->code . ')',
                ];
            }

            if (($decoded['status'] ?? '') !== 'success') {
                $this->log(
                    'api-failure',
                    'path=' . $path
                    . ' code=' . ($decoded['errorCode'] ?? '?')
                    . ' msg=' . ($decoded['errorMessage'] ?? '?')
                );
            }

            return $decoded;
        } catch (\Throwable $e) {
            $this->log('transport', $path . ' :: ' . $e->getMessage());

            return ['status' => 'failure', 'errorCode' => 'transport', 'errorMessage' => $e->getMessage()];
        }
    }

    private function log(string $branch, string $message): void
    {
        if ($this->logger) {
            call_user_func($this->logger, 'iyzico-' . $branch, $message);
        }
    }
}
