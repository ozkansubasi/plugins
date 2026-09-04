<?php
/**
 * @package     NumisTR Billing (iyzico web subscription)
 * @subpackage  plg_system_numistrbilling
 * @version     1.3.0
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * ADR-004 — PRO web aboneliği: iyzico Subscription API v2 + Checkout Form.
 * Kart iyzico'da saklanır; bizde yalnız abonelik durumu + Pro grup (10) senkronu.
 *
 * Uçlar (com_ajax):
 *   ...&plugin=numistrbilling&format=raw&task=checkout&plan=monthly|yearly  (GET, giriş gerekli: fatura formu)
 *   ...&task=start        (POST + CSRF: CF initialize → ödeme formu sayfası)
 *   ...&task=callback     (iyzico redirect: token → sonuç doğrula → grup 10 + kayıt)
 *   ...&task=webhook      (iyzico server-to-server: subscription.order.success|failure)
 *   ...&task=cancel       (POST + CSRF, giriş gerekli: aboneliği iptal et)
 *   ...&task=cardupdate   (GET, giriş gerekli: kart güncelleme formu)
 *   ...&format=json&task=status  (giriş gerekli: Hesabım için abonelik özeti)
 *   ...&task=housekeeping&key=…  (n8n cron: süresi geçmiş abonelikleri kapat)
 *   onUserBeforeDelete        (Joomla olayı: hesap silinmeden aktif iyzico aboneliğini iptal et — 1.3.0)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

class PlgSystemNumistrbilling extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    protected $autoloadLanguage = true;

    private const SESSION_NS = 'numistrbilling';
    private const SOURCE     = 'iyzico';

    /**
     * com_ajax entry point: plugin=numistrbilling
     */
    public function onAjaxNumistrbilling()
    {
        if (!$this->app->isClient('site')) {
            return $this->fail(403, 'site only');
        }

        $task = preg_replace('/[^a-z_]/', '', strtolower((string) $this->app->input->getCmd('task', '')));

        switch ($task) {
            case 'checkout':
                return $this->showCheckoutForm();
            case 'start':
                return $this->startSubscription();
            case 'callback':
                return $this->handleCallback();
            case 'webhook':
                return $this->handleWebhook();
            case 'cancel':
                return $this->handleCancel();
            case 'cardupdate':
                return $this->handleCardUpdate();
            case 'status':
                return $this->status();
            case 'housekeeping':
                return $this->housekeeping();
            default:
                return $this->fail(404, 'unknown task');
        }
    }

    // ==================================================================
    // 1) Fatura bilgisi formu (giriş gerekli)
    // ==================================================================

    private function showCheckoutForm()
    {
        $user = $this->requireLogin();

        if (!$user) {
            return null; // redirect edildi
        }

        $plan = $this->planFromInput();

        if ($plan === '') {
            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_PLAN');
        }

        if ($this->activeSubscriptionRow((int) $user->id)) {
            $this->app->enqueueMessage($this->text('already_active'), 'info');
            $this->app->redirect($this->accountUrl());

            return null;
        }

        echo $this->renderBillingFormPage($user, $plan, [], [], $this->currencyFromInput());
        $this->app->close();

        return null;
    }

    // ==================================================================
    // 2) CF initialize (POST + CSRF)
    // ==================================================================

    private function startSubscription()
    {
        $user = $this->requireLogin();

        if (!$user) {
            return null;
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !Session::checkToken('post')) {
            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_CSRF');
        }

        $plan    = $this->planFromInput();
        $cur     = $this->currencyFromInput();
        $planRef = $this->planRef($plan, $cur);

        if ($plan === '' || $planRef === '') {
            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_PLAN');
        }

        $in     = $this->app->input->post;
        $fields = [
            'name'    => trim((string) $in->getString('bill_name', '')),
            'surname' => trim((string) $in->getString('bill_surname', '')),
            'gsm'     => preg_replace('/[^0-9+]/', '', (string) $in->getString('bill_gsm', '')),
            'tckn'    => preg_replace('/\D/', '', (string) $in->getString('bill_tckn', '')),
            'address' => trim((string) $in->getString('bill_address', '')),
            'city'    => trim((string) $in->getString('bill_city', '')),
            'zip'     => trim((string) $in->getString('bill_zip', '')),
            'consent' => $in->getInt('bill_consent', 0) === 1,
        ];

        $errors = $this->validateBilling($fields);

        if ($errors) {
            echo $this->renderBillingFormPage($user, $plan, $errors, $fields, $cur);
            $this->app->close();

            return null;
        }

        // GSM'i E.164'e normalize et (TR varsayımı; "+" ile girilen yabancı numara korunur)
        if (strpos($fields['gsm'], '+') === 0 && strpos($fields['gsm'], '+90') !== 0) {
            $gsm = $fields['gsm'];
        } else {
            $digits = ltrim(preg_replace('/\D/', '', $fields['gsm']), '0');

            if (strpos($digits, '90') === 0 && strlen($digits) === 12) {
                $digits = substr($digits, 2);
            }

            $gsm = '+90' . $digits;
        }

        // conversationId'ye para birimini de göm: oturum düşerse callback doğru ref'i yazsın
        $conversationId = 'u' . (int) $user->id . '|' . $plan . '|' . strtolower($cur) . '|' . bin2hex(random_bytes(4));

        $body = [
            'locale'                   => 'tr',
            'conversationId'           => $conversationId,
            'callbackUrl'              => $this->endpointUrl('callback'),
            'pricingPlanReferenceCode' => $planRef,
            'subscriptionInitialStatus' => 'ACTIVE',
            'customer'                 => [
                'name'           => $fields['name'],
                'surname'        => $fields['surname'],
                'email'          => (string) $user->email,
                'gsmNumber'      => $gsm,
                'identityNumber' => $fields['tckn'],
                'billingAddress' => [
                    'contactName' => $fields['name'] . ' ' . $fields['surname'],
                    'city'        => $fields['city'],
                    'country'     => 'Turkey',
                    'address'     => $fields['address'],
                    'zipCode'     => $fields['zip'] !== '' ? $fields['zip'] : '00000',
                ],
                'shippingAddress' => [
                    'contactName' => $fields['name'] . ' ' . $fields['surname'],
                    'city'        => $fields['city'],
                    'country'     => 'Turkey',
                    'address'     => $fields['address'],
                    'zipCode'     => $fields['zip'] !== '' ? $fields['zip'] : '00000',
                ],
            ],
        ];

        $res = $this->client()->initializeCheckoutForm($body);

        // DİKKAT: CF init yanıtı 'data' sarmalayıcısız, kök seviyede (ADR-004)
        if (($res['status'] ?? '') !== 'success' || empty($res['checkoutFormContent'])) {
            $this->log('cf-init-failed', 'user=' . $user->id . ' code=' . ($res['errorCode'] ?? '?') . ' msg=' . ($res['errorMessage'] ?? '?'));

            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_INIT');
        }

        // Bekleyen ödemeyi oturumda tut (callback doğrulaması + onay kanıtı)
        $s = $this->app->getSession();
        $s->set(self::SESSION_NS . '.pending', [
            'user_id'         => (int) $user->id,
            'plan'            => $plan,
            'plan_ref'        => $planRef,
            'currency'        => $cur,
            'conversation_id' => $conversationId,
            'consent_at'      => Factory::getDate()->toSql(),
            'consent_ip'      => $this->clientIp(),
            'token'           => (string) ($res['token'] ?? ''),
        ]);

        $this->log('cf-init-ok', 'user=' . $user->id . ' plan=' . $plan . ' conv=' . $conversationId);

        echo $this->renderPaymentPage((string) $res['checkoutFormContent']);
        $this->app->close();

        return null;
    }

    // ==================================================================
    // 3) iyzico dönüş adresi (callback)
    // ==================================================================

    private function handleCallback()
    {
        // iyzico token'ı POST form alanı olarak yollar; GET'e de tolerans göster
        $token = trim((string) $this->app->input->getString('token', ''));

        if ($token === '') {
            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_CB_TOKEN');
        }

        $res  = $this->client()->retrieveCheckoutFormResult($token);
        $data = is_array($res['data'] ?? null) ? $res['data'] : $res; // sarmalayıcıya karşı savunmacı

        if (($res['status'] ?? '') !== 'success') {
            $this->log('cb-failed', 'token=' . $token . ' code=' . ($res['errorCode'] ?? '?') . ' msg=' . ($res['errorMessage'] ?? '?'));

            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_CB_RESULT');
        }

        $subRef      = (string) ($data['referenceCode'] ?? $data['subscriptionReferenceCode'] ?? '');
        $customerRef = (string) ($data['customerReferenceCode'] ?? '');
        $subStatus   = strtoupper((string) ($data['subscriptionStatus'] ?? $data['status'] ?? 'ACTIVE'));
        $convId      = (string) ($data['conversationId'] ?? ($res['conversationId'] ?? ''));

        if ($subRef === '') {
            $this->log('cb-noref', 'token=' . $token . ' raw=' . substr(json_encode($res), 0, 800));

            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_CB_RESULT');
        }

        // Kullanıcıyı çöz: önce oturumdaki bekleyen kayıt, sonra conversationId
        $s       = $this->app->getSession();
        $pending = (array) $s->get(self::SESSION_NS . '.pending', []);
        $userId  = (int) ($pending['user_id'] ?? 0);
        $plan    = (string) ($pending['plan'] ?? '');
        $cur     = strtoupper((string) ($pending['currency'] ?? ''));

        // conversationId biçimi: u<id>|<plan>|<cur>|<rnd>  (1.0.0'da <cur> yoktu → opsiyonel)
        if ($userId <= 0 && preg_match('/^u(\d+)\|(monthly|yearly)\|(?:(try|eur)\|)?/', $convId, $m)) {
            $userId = (int) $m[1];
            $plan   = $m[2];

            if ($cur === '' && !empty($m[3])) {
                $cur = strtoupper($m[3]);
            }
        }

        if ($cur === '') {
            $cur = 'TRY';
        }

        if ($userId <= 0) {
            $this->log('cb-nouser', 'token=' . $token . ' conv=' . $convId . ' subRef=' . $subRef);

            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_CB_RESULT');
        }

        if ($plan === '') {
            $plan = 'monthly';
        }

        $now = Factory::getDate()->toSql();

        $this->upsertSubscription([
            'user_id'                     => $userId,
            'plan'                        => $plan,
            'subscription_reference_code' => $subRef,
            'customer_reference_code'     => $customerRef,
            'pricing_plan_reference_code' => (string) ($pending['plan_ref'] ?? $this->planRef($plan, $cur)),
            'status'                      => $subStatus !== '' ? $subStatus : 'ACTIVE',
            'current_period_end'          => $this->periodEndFrom('now', $plan),
            'conversation_id'             => $convId,
            'consent_at'                  => (string) ($pending['consent_at'] ?? $now),
            'consent_ip'                  => (string) ($pending['consent_ip'] ?? $this->clientIp()),
            'raw_json'                    => json_encode($res, JSON_UNESCAPED_UNICODE),
        ]);

        $changed = $this->grantPro($userId);
        $s->clear(self::SESSION_NS . '.pending');

        $this->log('cb-ok', 'user=' . $userId . ' subRef=' . $subRef . ' status=' . $subStatus . ' proChanged=' . ($changed ? '1' : '0'));

        $this->app->enqueueMessage($this->text('success'), 'message');
        $this->app->redirect($this->accountUrl());

        return null;
    }

    // ==================================================================
    // 4) Webhook (subscription.order.success | subscription.order.failure)
    // ==================================================================

    private function handleWebhook()
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $this->fail(405, 'POST only');
        }

        $raw     = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return $this->fail(400, 'invalid json');
        }

        $eventType = strtolower((string) ($payload['iyziEventType'] ?? ''));
        $subRef    = (string) ($payload['subscriptionReferenceCode'] ?? '');
        $orderRef  = (string) ($payload['orderReferenceCode'] ?? '');
        $custRef   = (string) ($payload['customerReferenceCode'] ?? '');
        $iyziRef   = (string) ($payload['iyziReferenceCode'] ?? '');

        // Ödeme bildirimleri (Firma Ayarları → üye işyeri bildirimleri) aynı URL'ye
        // düşebilir. iyzico teslimatı sağlıklı saysın diye 400 DEĞİL, 200 dönüyoruz;
        // olayı yalnızca kaydedip geçiyoruz (Pro durumu abonelik olaylarından yönetilir).
        if ($subRef === '' || strpos($eventType, 'subscription.') !== 0) {
            // 1.2.1 — teşhis: abonelik dışı bildirimler de aynı gizli anahtarla
            // imzalanıyorsa, yenileme webhook'u gelmeden ÖNCE hangi HMAC varyantının
            // tuttuğunu öğreniriz (eşleşirse 'wh-sig-ok variant=…' satırı düşer).
            $this->log(
                'wh-non-subscription',
                'event=' . ($eventType !== '' ? $eventType : '(bos)')
                . ' sig=' . $this->verifyWebhookSignature($eventType, $subRef, $orderRef, $custRef)
                . ' sigLen=' . strlen($this->signatureHeader())
                . ' keys=' . implode('|', array_slice(array_keys($payload), 0, 15))
            );

            return $this->ok(['ok' => true, 'action' => 'ignored_non_subscription']);
        }

        // İmza doğrulama (X-IYZ-SIGNATURE-V3). Dokümanda alan sırası çelişkili
        // (metin vs PHP örneği) — iki aday da denenir; hangisinin tuttuğu loglanır.
        $sigState = $this->verifyWebhookSignature($eventType, $subRef, $orderRef, $custRef);

        if ($sigState === 'invalid' && (int) $this->params->get('webhook_signature_required', 0) === 1) {
            $this->log('wh-sig-reject', 'subRef=' . $subRef);

            return $this->fail(401, 'invalid signature');
        }

        // Idempotency (denetim tablosu varsa)
        $eventId = $iyziRef !== '' ? $iyziRef : sha1($raw);

        if ($this->eventAlreadyProcessed($eventId)) {
            return $this->ok(['ok' => true, 'action' => 'duplicate']);
        }

        // Savunma ilkesi: webhook yalnız tetikleyicidir; gerçek durum API'den okunur.
        $detail  = $this->client()->getSubscription($subRef);
        $sub     = is_array($detail['data'] ?? null) ? $detail['data'] : [];
        $apiOk   = ($detail['status'] ?? '') === 'success';
        $status  = strtoupper((string) ($sub['subscriptionStatus'] ?? ''));

        $row    = $this->subscriptionRowByRef($subRef);
        $userId = $row ? (int) $row->user_id : 0;
        $action = 'ignored';

        if ($userId > 0) {
            $plan = $row->plan ?: 'monthly';

            if ($eventType === 'subscription.order.success') {
                // Yenileme tahsil edildi → dönemi uzat + Pro garanti et
                $this->updateSubscription($subRef, [
                    'status'             => $apiOk && $status !== '' ? $status : 'ACTIVE',
                    'current_period_end' => $this->periodEndFrom('now', $plan),
                ]);
                $this->grantPro($userId);
                $action = 'renewed';
            } elseif ($eventType === 'subscription.order.failure') {
                // Tahsilat başarısız → durumu işle; grup housekeeping'de,
                // dönem sonu + grace geçince kaldırılır.
                $this->updateSubscription($subRef, [
                    'status' => $apiOk && $status !== '' ? $status : 'UNPAID',
                ]);
                $action = 'payment_failed';
            }
        } else {
            $this->log('wh-unknown-sub', 'subRef=' . $subRef . ' event=' . $eventType);
            $action = 'sub_not_found';
        }

        $this->recordBillingEvent($eventId, $eventType, $custRef, $userId, $subRef, $action, $raw);
        $this->log('wh-ok', 'event=' . $eventType . ' subRef=' . $subRef . ' user=' . $userId . ' action=' . $action . ' sig=' . $sigState);

        return $this->ok(['ok' => true, 'action' => $action]);
    }

    /**
     * @return string 'valid' | 'invalid' | 'missing' | 'unconfigured'
     */
    private function verifyWebhookSignature(string $eventType, string $subRef, string $orderRef, string $custRef): string
    {
        $secret     = (string) $this->params->get('secret_key', '');
        $merchantId = trim((string) $this->params->get('merchant_id', ''));

        $header = $this->signatureHeader();

        if ($header === '') {
            return 'missing';
        }

        if ($secret === '' || $merchantId === '') {
            return 'unconfigured';
        }

        $tail       = $eventType . $subRef . $orderRef . $custRef;
        $candidates = [
            'php-sample' => $merchantId . $secret . $tail, // dokümandaki PHP örneği
            'doc-text'   => $secret . $merchantId . $tail, // dokümandaki düzyazı sırası
        ];

        foreach ($candidates as $label => $message) {
            $calc = bin2hex(hash_hmac('sha256', $message, $secret, true));

            if (hash_equals($calc, strtolower(trim($header)))) {
                $this->log('wh-sig-ok', 'variant=' . $label);

                return 'valid';
            }
        }

        return 'invalid';
    }

    /**
     * X-IYZ-SIGNATURE-V3 başlığı; yoksa boş dize.
     */
    private function signatureHeader(): string
    {
        if (!empty($_SERVER['HTTP_X_IYZ_SIGNATURE_V3'])) {
            return (string) $_SERVER['HTTP_X_IYZ_SIGNATURE_V3'];
        }

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'X-IYZ-SIGNATURE-V3') === 0) {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    // ==================================================================
    // 5) İptal (giriş + CSRF)
    // ==================================================================

    private function handleCancel()
    {
        $user = $this->requireLogin();

        if (!$user) {
            return null;
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !Session::checkToken('post')) {
            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_CSRF');
        }

        $row = $this->activeSubscriptionRow((int) $user->id);

        if (!$row) {
            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_NOSUB');
        }

        $res = $this->client()->cancelSubscription((string) $row->subscription_reference_code);

        if (($res['status'] ?? '') !== 'success') {
            $this->log('cancel-failed', 'user=' . $user->id . ' code=' . ($res['errorCode'] ?? '?'));

            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_CANCEL');
        }

        $this->updateSubscription((string) $row->subscription_reference_code, [
            'status'      => 'CANCELED',
            'canceled_at' => Factory::getDate()->toSql(),
        ]);

        // Grup 10 dönem sonuna kadar kalır; housekeeping kaldırır.
        $this->log('cancel-ok', 'user=' . $user->id . ' subRef=' . $row->subscription_reference_code);
        $this->app->enqueueMessage($this->text('canceled'), 'message');
        $this->app->redirect($this->accountUrl());

        return null;
    }

    // ==================================================================
    // 5b) Hesap silme: aktif iyzico aboneliğini önce iptal et (BACKLOG S10)
    // ==================================================================

    /**
     * Joomla kullanıcısı silinmeden hemen önce (yönetici paneli, API, CLI) o kullanıcının
     * ACTIVE iyzico aboneliğini iptal eder. Aksi hâlde hesap silinir, iyzico kartı dönem
     * dönem çekmeye devam eder ve satırı hesaba bağlayan tek şey ölü bir user_id kalır.
     *
     * Legacy listener: CMSPlugin `on*` metotlarını otomatik kaydeder; sistem eklentisi her
     * uygulamada yüklü olduğundan User::delete()'in tetiklediği olay buraya düşer.
     * Dönüş değeri Joomla tarafından okunmaz (silme engellenemez) — bu yüzden başarısızlık
     * log + yönetici uyarısıyla görünür kılınır, silme devam eder.
     *
     * Abonelik satırı SİLİNMEZ (fatura/ödeme kaydı 10 yıl saklanır — Gizlilik Politikası §8);
     * yalnızca status=CANCELED + canceled_at yazılır. Play (RevenueCat) aboneliği sunucudan
     * iptal edilemez; varsa yöneticiye ayrıca uyarı verilir.
     *
     * @param  array|object  $user  Silinen kullanıcının özellikleri (User::getProperties())
     * @return void
     */
    public function onUserBeforeDelete($user): void
    {
        $userId = (int) (\is_array($user) ? ($user['id'] ?? 0) : ($user->id ?? 0));

        if ($userId <= 0) {
            return;
        }

        $row = $this->activeSubscriptionRow($userId);

        if ($row) {
            $subRef = (string) $row->subscription_reference_code;
            $ok     = false;
            $detail = '';

            try {
                $res    = $this->client()->cancelSubscription($subRef);
                $ok     = (($res['status'] ?? '') === 'success');
                $detail = $ok ? 'success' : ('code=' . ($res['errorCode'] ?? '?') . ' msg=' . ($res['errorMessage'] ?? ''));
            } catch (\Throwable $e) {
                $detail = 'exception: ' . $e->getMessage();
            }

            if ($ok) {
                $this->updateSubscription($subRef, [
                    'status'      => 'CANCELED',
                    'canceled_at' => Factory::getDate()->toSql(),
                ]);
                $this->log('userdelete-cancel-ok', 'user=' . $userId . ' subRef=' . $subRef);
                $this->adminMessage(Text::sprintf('PLG_SYSTEM_NUMISTRBILLING_USERDELETE_CANCELED', $userId, $subRef), 'message');
            } else {
                $this->log('userdelete-cancel-FAILED', 'user=' . $userId . ' subRef=' . $subRef . ' ' . $detail);
                $this->adminMessage(Text::sprintf('PLG_SYSTEM_NUMISTRBILLING_USERDELETE_FAILED', $userId, $subRef), 'error');
            }

            $this->recordBillingEvent(
                'userdelete:' . $subRef,
                'user.before_delete',
                (string) ($row->customer_reference_code ?? ''),
                $userId,
                $subRef,
                $ok ? 'cancel-on-delete' : 'cancel-on-delete-FAILED',
                (string) json_encode(['user_id' => $userId, 'detail' => $detail, 'at' => Factory::getDate()->toSql()])
            );
        }

        if ($this->hasActivePlayEntitlement($userId)) {
            $this->log('userdelete-play-active', 'user=' . $userId . ' — Play aboneliği sunucudan iptal edilemez');
            $this->adminMessage(Text::sprintf('PLG_SYSTEM_NUMISTRBILLING_USERDELETE_PLAY_ACTIVE', $userId), 'warning');
        }
    }

    /** Yönetici/site uygulamasında mesaj kuyruğa alınır; CLI/API'de sessizce atlanır. */
    private function adminMessage(string $msg, string $type): void
    {
        try {
            if ($this->app && method_exists($this->app, 'enqueueMessage')) {
                $this->app->enqueueMessage($msg, $type);
            }
        } catch (\Throwable $e) {
            // mesaj akışı silmeyi asla bozmasın
        }
    }

    // ==================================================================
    // 6) Kart güncelleme (giriş gerekli)
    // ==================================================================

    private function handleCardUpdate()
    {
        $user = $this->requireLogin();

        if (!$user) {
            return null;
        }

        $row = $this->activeSubscriptionRow((int) $user->id);

        if (!$row || (string) $row->customer_reference_code === '') {
            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_NOSUB');
        }

        $res = $this->client()->initializeCardUpdateForm([
            'locale'                    => 'tr',
            'callbackUrl'               => $this->accountUrl(),
            'customerReferenceCode'     => (string) $row->customer_reference_code,
            'subscriptionReferenceCode' => (string) $row->subscription_reference_code,
        ]);

        if (($res['status'] ?? '') !== 'success' || empty($res['checkoutFormContent'])) {
            $this->log('cardupdate-failed', 'user=' . $user->id . ' code=' . ($res['errorCode'] ?? '?'));

            return $this->failRedirect('PLG_SYSTEM_NUMISTRBILLING_ERR_INIT');
        }

        echo $this->renderPaymentPage((string) $res['checkoutFormContent'], true);
        $this->app->close();

        return null;
    }

    // ==================================================================
    // 7) Durum (Hesabım modülü için JSON)
    // ==================================================================

    private function status()
    {
        $user = Factory::getUser();

        if ($user->guest) {
            return $this->fail(401, 'login required');
        }

        $row = $this->latestSubscriptionRow((int) $user->id);

        return $this->ok([
            'ok'  => true,
            'sub' => $row ? [
                'plan'               => $row->plan,
                'status'             => $row->status,
                'current_period_end' => $row->current_period_end,
                'canceled_at'        => $row->canceled_at,
            ] : null,
        ]);
    }

    // ==================================================================
    // 8) Housekeeping (n8n cron): süresi geçenlerin grubunu kaldır
    // ==================================================================

    private function housekeeping()
    {
        $key      = (string) $this->app->input->getString('key', '');
        $expected = (string) $this->params->get('housekeeping_key', '');

        if ($expected === '' || !hash_equals($expected, $key)) {
            return $this->fail(403, 'forbidden');
        }

        $graceDays = max(0, (int) $this->params->get('grace_days', 3));
        $cutoff    = Factory::getDate('-' . $graceDays . ' days')->toSql();

        // Kira (lease) modeli: iyzico İPTALLERDE webhook GÖNDERMİYOR (destek teyidi,
        // 27.08.2026) ve abonelik okuma uçları bu hesapta veri dönmüyor. Bu yüzden
        // "ACTIVE" tek başına güvenilir değil; asıl doğruluk kaynağı current_period_end.
        // Yenileme tahsilatı webhook'u dönemi uzatır; uzatılmayan satır kendiliğinden düşer.
        //
        // Süpürme, yenileme webhook'ları CANLI olarak doğrulanana kadar varsayılan
        // KAPALI. Kapalıyken bile süresi geçmiş ACTIVE satırlar sayılıp raporlanır ki
        // cron sessizce hiçbir şey yapıyor görünmesin.
        $expireActive = (int) $this->params->get('expire_active_after_period', 0) === 1;

        $db       = Factory::getDbo();
        $statuses = [$db->quote('CANCELED'), $db->quote('UNPAID')];

        if ($expireActive) {
            $statuses[] = $db->quote('ACTIVE');
        }

        $baseWhere = ' WHERE ' . $db->quoteName('source') . ' = ' . $db->quote(self::SOURCE)
            . ' AND ' . $db->quoteName('current_period_end') . ' IS NOT NULL'
            . ' AND ' . $db->quoteName('current_period_end') . ' < ' . $db->quote($cutoff);

        // 1.2.1 — EXPIRED satırlar her koşuda yeniden sayılmasın (checked sürekli
        // şişiyordu). Kapatılmış kayıt yalnızca kullanıcı HÂLÂ Pro grubundaysa
        // (önceki koşuda revoke başarısız olmuş olabilir) tekrar ele alınır.
        $statusWhere = $db->quoteName('status') . ' IN (' . implode(', ', $statuses) . ')';
        $proGroupId  = $this->proGroupId();

        if ($proGroupId > 0) {
            $statusWhere = '(' . $statusWhere
                . ' OR (' . $db->quoteName('status') . ' = ' . $db->quote('EXPIRED')
                . ' AND EXISTS (SELECT 1 FROM ' . $db->quoteName('#__user_usergroup_map') . ' AS m'
                . ' WHERE m.' . $db->quoteName('user_id') . ' = '
                . $db->quoteName('numistr_subscriptions') . '.' . $db->quoteName('user_id')
                . ' AND m.' . $db->quoteName('group_id') . ' = ' . $proGroupId . ')))';
        }

        $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('numistr_subscriptions') . $baseWhere
            . ' AND ' . $statusWhere
        );

        $rows    = (array) $db->loadObjectList();
        $revoked = [];

        // Süpürme kapalıyken bile görünürlük: süresi geçmiş ACTIVE satır sayısı.
        $staleActive = 0;

        if (!$expireActive) {
            $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->quoteName('numistr_subscriptions') . $baseWhere
                . ' AND ' . $db->quoteName('status') . ' = ' . $db->quote('ACTIVE')
            );
            $staleActive = (int) $db->loadResult();
        }

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;

            // Başka bir kanaldan (diğer iyzico kaydı / Play) hâlâ Pro ise dokunma
            if ($this->activeSubscriptionRow($userId, (int) $row->id) || $this->hasActivePlayEntitlement($userId)) {
                continue;
            }

            if ($this->revokePro($userId)) {
                $revoked[] = $userId;
            }

            $this->updateSubscription((string) $row->subscription_reference_code, ['status' => 'EXPIRED']);
        }

        $this->log(
            'housekeeping',
            'checked=' . count($rows) . ' revoked=[' . implode(',', $revoked) . ']'
            . ' expireActive=' . ($expireActive ? '1' : '0') . ' staleActive=' . $staleActive
        );

        return $this->ok([
            'ok'            => true,
            'checked'       => count($rows),
            'revoked'       => $revoked,
            'expire_active' => $expireActive,
            'stale_active'  => $staleActive,
        ]);
    }

    // ==================================================================
    // Yardımcılar: kimlik / plan / URL
    // ==================================================================

    /** @return \Joomla\CMS\User\User|null Girişli değilse login'e yönlendirir. */
    private function requireLogin()
    {
        $user = Factory::getUser();

        if (!$user->guest) {
            return $user;
        }

        $current = Uri::getInstance()->toString(['path', 'query']);
        $login   = $this->canonicalRoot()
            . 'index.php?option=com_ajax&plugin=numistrauth&format=raw&task=login&return=' . urlencode($current);

        $this->app->redirect($login);

        return null;
    }

    private function planFromInput(): string
    {
        $plan = strtolower((string) $this->app->input->getCmd('plan', ''));

        return in_array($plan, ['monthly', 'yearly'], true) ? $plan : '';
    }

    /**
     * Para birimi: açık `cur` parametresi > site dili (en-GB → EUR) > TRY.
     * iyzico kısıtı: yabancı para planına yalnız TL-dışı kartla abone olunabilir.
     */
    private function currencyFromInput(): string
    {
        $cur = strtoupper((string) $this->app->input->getCmd('cur', ''));

        if (in_array($cur, ['EUR', 'TRY'], true)) {
            return $cur === 'EUR' && $this->eurConfigured() ? 'EUR' : 'TRY';
        }

        $tag = strtolower((string) $this->app->getLanguage()->getTag());

        return (strpos($tag, 'en') === 0 && $this->eurConfigured()) ? 'EUR' : 'TRY';
    }

    private function eurConfigured(): bool
    {
        return trim((string) $this->params->get('plan_monthly_eur_ref', '')) !== ''
            || trim((string) $this->params->get('plan_yearly_eur_ref', '')) !== '';
    }

    private function planRef(string $plan, string $cur = 'TRY'): string
    {
        $suffix = ($cur === 'EUR') ? '_eur' : '';

        if ($plan === 'monthly') {
            $ref = trim((string) $this->params->get('plan_monthly' . $suffix . '_ref', ''));

            return $ref !== '' ? $ref : trim((string) $this->params->get('plan_monthly_ref', ''));
        }

        if ($plan === 'yearly') {
            $ref = trim((string) $this->params->get('plan_yearly' . $suffix . '_ref', ''));

            return $ref !== '' ? $ref : trim((string) $this->params->get('plan_yearly_ref', ''));
        }

        return '';
    }

    private function client(): NumistrIyzicoClient
    {
        require_once __DIR__ . '/src/IyzicoClient.php';

        return new NumistrIyzicoClient(
            (string) $this->params->get('api_key', ''),
            (string) $this->params->get('secret_key', ''),
            (string) $this->params->get('base_url', 'https://api.iyzipay.com'),
            function (string $b, string $m) {
                $this->log($b, $m);
            }
        );
    }

    private function canonicalRoot(): string
    {
        $root = trim((string) $this->params->get('canonical_root', 'https://www.numistr.org'));

        if ($root === '' || !preg_match('#^https://[a-z0-9.-]+(/.*)?$#i', $root)) {
            $root = Uri::root();
        }

        return rtrim($root, '/') . '/';
    }

    private function endpointUrl(string $task): string
    {
        return $this->canonicalRoot() . 'index.php?option=com_ajax&plugin=numistrbilling&format=raw&task=' . $task;
    }

    private function accountUrl(): string
    {
        $path = trim((string) $this->params->get(
            $this->lang() === 'en' ? 'account_en' : 'account_tr',
            $this->lang() === 'en' ? '/en/my-account' : '/tr/hesabim'
        ));

        return $this->canonicalRoot() . ltrim($path, '/');
    }

    private function lang(): string
    {
        $tag = strtolower((string) Factory::getLanguage()->getTag());

        return strpos($tag, 'en') === 0 ? 'en' : 'tr';
    }

    private function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    // ==================================================================
    // Doğrulama
    // ==================================================================

    /** @return array alan => hata mesajı */
    private function validateBilling(array $f): array
    {
        $e = [];

        if (mb_strlen($f['name']) < 2) {
            $e['name'] = $this->text('err_name');
        }

        if (mb_strlen($f['surname']) < 2) {
            $e['surname'] = $this->text('err_surname');
        }

        $gsmDigits = preg_replace('/\D/', '', $f['gsm']);

        if (strlen($gsmDigits) < 10) {
            $e['gsm'] = $this->text('err_gsm');
        }

        if (!$this->isValidTckn($f['tckn'])) {
            $e['tckn'] = $this->text('err_tckn');
        }

        if (mb_strlen($f['address']) < 10) {
            $e['address'] = $this->text('err_address');
        }

        if (mb_strlen($f['city']) < 2) {
            $e['city'] = $this->text('err_city');
        }

        if (!$f['consent']) {
            $e['consent'] = $this->text('err_consent');
        }

        return $e;
    }

    /** Standart TCKN algoritması */
    private function isValidTckn(string $tckn): bool
    {
        if (!preg_match('/^[1-9]\d{10}$/', $tckn)) {
            return false;
        }

        $d = array_map('intval', str_split($tckn));

        $odd  = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];

        if ((($odd * 7) - $even) % 10 !== $d[9]) {
            return false;
        }

        return (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
    }

    // ==================================================================
    // DB: numistr_subscriptions + numistr_billing_events
    // ==================================================================

    private function subscriptionRowByRef(string $subRef)
    {
        try {
            $db = Factory::getDbo();
            $db->setQuery(
                'SELECT * FROM ' . $db->quoteName('numistr_subscriptions')
                . ' WHERE ' . $db->quoteName('subscription_reference_code') . ' = ' . $db->quote($subRef)
                . ' LIMIT 1'
            );

            return $db->loadObject();
        } catch (\Throwable $e) {
            $this->log('db-error', 'rowByRef: ' . $e->getMessage());

            return null;
        }
    }

    private function activeSubscriptionRow(int $userId, int $excludeId = 0)
    {
        try {
            $db  = Factory::getDbo();
            $sql = 'SELECT * FROM ' . $db->quoteName('numistr_subscriptions')
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
                . ' AND ' . $db->quoteName('source') . ' = ' . $db->quote(self::SOURCE)
                . ' AND ' . $db->quoteName('status') . ' = ' . $db->quote('ACTIVE');

            if ($excludeId > 0) {
                $sql .= ' AND ' . $db->quoteName('id') . ' != ' . $excludeId;
            }

            $db->setQuery($sql . ' ORDER BY ' . $db->quoteName('id') . ' DESC LIMIT 1');

            return $db->loadObject();
        } catch (\Throwable $e) {
            $this->log('db-error', 'activeRow: ' . $e->getMessage());

            return null;
        }
    }

    private function latestSubscriptionRow(int $userId)
    {
        try {
            $db = Factory::getDbo();
            $db->setQuery(
                'SELECT * FROM ' . $db->quoteName('numistr_subscriptions')
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
                . ' AND ' . $db->quoteName('source') . ' = ' . $db->quote(self::SOURCE)
                . ' ORDER BY ' . $db->quoteName('id') . ' DESC LIMIT 1'
            );

            return $db->loadObject();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function upsertSubscription(array $data): void
    {
        try {
            $db  = Factory::getDbo();
            $now = Factory::getDate()->toSql();

            $cols = [
                'user_id', 'source', 'plan', 'subscription_reference_code', 'customer_reference_code',
                'pricing_plan_reference_code', 'status', 'current_period_end', 'conversation_id',
                'consent_at', 'consent_ip', 'raw_json', 'created', 'modified',
            ];

            $vals = [
                (int) $data['user_id'],
                $db->quote(self::SOURCE),
                $db->quote($data['plan']),
                $db->quote($data['subscription_reference_code']),
                $db->quote($data['customer_reference_code'] ?? ''),
                $db->quote($data['pricing_plan_reference_code'] ?? ''),
                $db->quote($data['status']),
                $db->quote($data['current_period_end']),
                $db->quote($data['conversation_id'] ?? ''),
                $db->quote($data['consent_at']),
                $db->quote($data['consent_ip'] ?? ''),
                $db->quote(mb_substr((string) ($data['raw_json'] ?? ''), 0, 60000)),
                $db->quote($now),
                $db->quote($now),
            ];

            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('numistr_subscriptions')
                . ' (' . implode(', ', array_map([$db, 'quoteName'], $cols)) . ')'
                . ' VALUES (' . implode(', ', $vals) . ')'
                . ' ON DUPLICATE KEY UPDATE '
                . $db->quoteName('status') . ' = ' . $db->quote($data['status']) . ', '
                . $db->quoteName('current_period_end') . ' = ' . $db->quote($data['current_period_end']) . ', '
                . $db->quoteName('modified') . ' = ' . $db->quote($now)
            );
            $db->execute();
        } catch (\Throwable $e) {
            $this->log('db-error', 'upsert: ' . $e->getMessage());
        }
    }

    private function updateSubscription(string $subRef, array $fields): void
    {
        try {
            $db   = Factory::getDbo();
            $sets = [$db->quoteName('modified') . ' = ' . $db->quote(Factory::getDate()->toSql())];

            foreach ($fields as $col => $val) {
                $sets[] = $db->quoteName($col) . ' = ' . ($val === null ? 'NULL' : $db->quote((string) $val));
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName('numistr_subscriptions')
                . ' SET ' . implode(', ', $sets)
                . ' WHERE ' . $db->quoteName('subscription_reference_code') . ' = ' . $db->quote($subRef)
            );
            $db->execute();
        } catch (\Throwable $e) {
            $this->log('db-error', 'update: ' . $e->getMessage());
        }
    }

    private function periodEndFrom(string $base, string $plan): string
    {
        $interval = $plan === 'yearly' ? '+1 year' : '+1 month';

        return Factory::getDate($base === 'now' ? $interval : $base . ' ' . $interval)->toSql();
    }

    private function eventAlreadyProcessed(string $eventId): bool
    {
        try {
            $db = Factory::getDbo();
            $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->quoteName('numistr_billing_events')
                . ' WHERE ' . $db->quoteName('event_id') . ' = ' . $db->quote($eventId)
            );

            return (int) $db->loadResult() > 0;
        } catch (\Throwable $e) {
            return false; // tablo yoksa idempotency devre dışı, webhook çalışmaya devam eder
        }
    }

    private function recordBillingEvent(string $eventId, string $eventType, string $custRef, int $userId, string $subRef, string $action, string $raw): void
    {
        try {
            $db  = Factory::getDbo();
            $now = Factory::getDate()->toSql();

            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('numistr_billing_events')
                . ' (' . implode(', ', array_map([$db, 'quoteName'], [
                    'event_id', 'event_type', 'app_user_id', 'user_id', 'product_id',
                    'environment', 'action', 'expires_at', 'payload', 'created_at',
                ])) . ') VALUES ('
                . $db->quote($eventId) . ', '
                . $db->quote($eventType) . ', '
                . $db->quote($custRef) . ', '
                . $userId . ', '
                . $db->quote($subRef) . ', '
                . $db->quote('IYZICO') . ', '
                . $db->quote($action) . ', NULL, '
                . $db->quote(mb_substr($raw, 0, 60000)) . ', '
                . $db->quote($now)
                . ') ON DUPLICATE KEY UPDATE '
                . $db->quoteName('action') . ' = ' . $db->quote($action)
            );
            $db->execute();
        } catch (\Throwable $e) {
            $this->log('db-error', 'recordEvent: ' . $e->getMessage());
        }
    }

    /** Son RevenueCat grant olayı hâlâ geçerli mi? (Play aboneliği koruması) */
    private function hasActivePlayEntitlement(int $userId): bool
    {
        try {
            $db = Factory::getDbo();
            $db->setQuery(
                'SELECT ' . $db->quoteName('expires_at')
                . ' FROM ' . $db->quoteName('numistr_billing_events')
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
                . ' AND ' . $db->quoteName('environment') . ' != ' . $db->quote('IYZICO')
                . ' AND ' . $db->quoteName('action') . ' LIKE ' . $db->quote('grant%')
                . ' ORDER BY ' . $db->quoteName('created_at') . ' DESC LIMIT 1'
            );

            $expires = (string) $db->loadResult();

            return $expires !== '' && strtotime($expires) > time();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ==================================================================
    // Pro grup yönetimi (bilinçli olarak yerel kopya: cross-plugin require
    // canlıda kesinti riski taşıdı — bkz. 2026-07-08 LocationsHelper dersi)
    // ==================================================================

    private function proGroupId(): int
    {
        return (int) $this->params->get('pro_group_id', 10);
    }

    private function isInProGroup(int $userId): bool
    {
        $db = Factory::getDbo();
        $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName('#__user_usergroup_map')
            . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
            . ' AND ' . $db->quoteName('group_id') . ' = ' . $this->proGroupId()
        );

        return (int) $db->loadResult() > 0;
    }

    private function grantPro(int $userId): bool
    {
        if ($userId <= 0 || $this->proGroupId() <= 0 || $this->isInProGroup($userId)) {
            return false;
        }

        try {
            $db = Factory::getDbo();
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName('#__user_usergroup_map')
                . ' (' . $db->quoteName('user_id') . ', ' . $db->quoteName('group_id') . ')'
                . ' VALUES (' . $userId . ', ' . $this->proGroupId() . ')'
            );
            $db->execute();
            Access::clearStatics();

            return true;
        } catch (\Throwable $e) {
            $this->log('grant-error', 'user=' . $userId . ' :: ' . $e->getMessage());

            return false;
        }
    }

    private function revokePro(int $userId): bool
    {
        if ($userId <= 0 || $this->proGroupId() <= 0 || !$this->isInProGroup($userId)) {
            return false;
        }

        try {
            $db = Factory::getDbo();
            $db->setQuery(
                'DELETE FROM ' . $db->quoteName('#__user_usergroup_map')
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . $userId
                . ' AND ' . $db->quoteName('group_id') . ' = ' . $this->proGroupId()
            );
            $db->execute();
            Access::clearStatics();

            return true;
        } catch (\Throwable $e) {
            $this->log('revoke-error', 'user=' . $userId . ' :: ' . $e->getMessage());

            return false;
        }
    }

    // ==================================================================
    // Sayfa şablonları (bilinçli self-contained: YooTheme dışı com_ajax çıktısı)
    // ==================================================================

    private function renderBillingFormPage($user, string $plan, array $errors, array $old = [], string $cur = 'TRY'): string
    {
        $t = $this->texts();

        $isEur    = ($cur === 'EUR');
        $suffix   = $isEur ? '_eur' : '';
        $priceKey = ($plan === 'yearly' ? 'price_yearly' : 'price_monthly') . $suffix;

        if ($isEur) {
            $fallbackPrice = $plan === 'yearly' ? '€34,99/yıl' : '€3,99/ay';
        } else {
            $fallbackPrice = $plan === 'yearly' ? '₺839,99/yıl' : '₺99,99/ay';
        }

        $price    = (string) $this->params->get($priceKey, $fallbackPrice);
        $planName = $plan === 'yearly' ? $t['plan_yearly'] : $t['plan_monthly'];
        $action   = $this->endpointUrl('start') . '&plan=' . $plan . '&cur=' . strtolower($cur);
        $token    = Session::getFormToken();

        // iyzico kısıtı: yabancı para planına yalnız TL-dışı kartla abone olunabilir.
        $curNotice = $isEur
            ? '<p class="note">' . htmlspecialchars($t['eur_card_notice'], ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        $nameParts = explode(' ', trim((string) $user->name), 2);
        $v         = static function (string $key, string $fallback) use ($old) {
            return htmlspecialchars((string) ($old[$key] ?? $fallback), ENT_QUOTES, 'UTF-8');
        };
        $err = static function (string $key) use ($errors) {
            return isset($errors[$key])
                ? '<div class="err">' . htmlspecialchars($errors[$key], ENT_QUOTES, 'UTF-8') . '</div>'
                : '';
        };

        $contractUrl = htmlspecialchars((string) $this->params->get('contract_url', '/tr/mesafeli-satis-sozlesmesi'), ENT_QUOTES, 'UTF-8');
        $preinfoUrl  = htmlspecialchars((string) $this->params->get('preinfo_url', '/tr/on-bilgilendirme-formu'), ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="' . $this->lang() . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $t['checkout_title'] . ' — NumisTR</title>'
            . '<style>' . $this->pageCss() . '</style></head><body><div class="card">'
            . '<h1>' . $t['checkout_title'] . '</h1>'
            . '<p class="plan"><strong>' . $planName . '</strong> · ' . htmlspecialchars($price, ENT_QUOTES, 'UTF-8') . '</p>'
            . $curNotice
            . '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="' . $token . '" value="1">'
            . '<div class="row2">'
            . '<label>' . $t['f_name'] . '<input name="bill_name" value="' . $v('name', $nameParts[0] ?? '') . '" required>' . $err('name') . '</label>'
            . '<label>' . $t['f_surname'] . '<input name="bill_surname" value="' . $v('surname', $nameParts[1] ?? '') . '" required>' . $err('surname') . '</label>'
            . '</div>'
            . '<label>' . $t['f_email'] . '<input value="' . htmlspecialchars((string) $user->email, ENT_QUOTES, 'UTF-8') . '" readonly></label>'
            . '<div class="row2">'
            . '<label>' . $t['f_gsm'] . '<input name="bill_gsm" value="' . $v('gsm', '') . '" placeholder="05xx xxx xx xx" required>' . $err('gsm') . '</label>'
            . '<label>' . $t['f_tckn'] . '<input name="bill_tckn" value="' . $v('tckn', '') . '" maxlength="11" inputmode="numeric" required>' . $err('tckn') . '</label>'
            . '</div>'
            . '<label>' . $t['f_address'] . '<input name="bill_address" value="' . $v('address', '') . '" required>' . $err('address') . '</label>'
            . '<div class="row2">'
            . '<label>' . $t['f_city'] . '<input name="bill_city" value="' . $v('city', '') . '" required>' . $err('city') . '</label>'
            . '<label>' . $t['f_zip'] . '<input name="bill_zip" value="' . $v('zip', '') . '"></label>'
            . '</div>'
            . '<label class="consent"><input type="checkbox" name="bill_consent" value="1"> '
            . sprintf($t['consent'], $preinfoUrl, $contractUrl) . $err('consent') . '</label>'
            . '<button type="submit">' . $t['pay_button'] . '</button>'
            . '</form>'
            . '<p class="fine">' . $t['secure_note'] . '</p>'
            . '</div></body></html>';
    }

    private function renderPaymentPage(string $checkoutFormContent, bool $cardUpdate = false): string
    {
        $t     = $this->texts();
        $title = $cardUpdate ? $t['cardupdate_title'] : $t['pay_title'];

        return '<!DOCTYPE html><html lang="' . $this->lang() . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $title . ' — NumisTR</title>'
            . '<style>' . $this->pageCss() . '</style></head><body><div class="card">'
            . '<h1>' . $title . '</h1>'
            . '<div id="iyzipay-checkout-form" class="responsive"></div>'
            . $checkoutFormContent
            . '</div></body></html>';
    }

    private function pageCss(): string
    {
        return 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f2ee;margin:0;'
            . 'display:flex;justify-content:center;padding:24px 12px}'
            . '.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:560px;'
            . 'width:100%;padding:28px}'
            . 'h1{font-size:1.35rem;margin:0 0 4px;color:#22252a}'
            . '.plan{color:#6b5d45;margin:0 0 18px}'
            . 'label{display:block;font-size:.85rem;color:#444;margin:0 0 12px}'
            . 'input{display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:10px;border:1px solid #d5d0c6;'
            . 'border-radius:8px;font-size:1rem}'
            . 'input[readonly]{background:#f7f5f1;color:#777}'
            . '.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
            . '@media(max-width:480px){.row2{grid-template-columns:1fr}}'
            . '.consent{font-size:.82rem;line-height:1.45;margin:14px 0}'
            . '.consent input{display:inline;width:auto;margin-right:6px}'
            . '.consent a{color:#8a6d3b}'
            . 'button{width:100%;padding:13px;border:0;border-radius:8px;background:#8a6d3b;color:#fff;'
            . 'font-size:1.05rem;font-weight:600;cursor:pointer}'
            . 'button:hover{background:#75592c}'
            . '.err{color:#b3261e;font-size:.78rem;margin-top:3px}'
            . '.fine{color:#999;font-size:.75rem;text-align:center;margin:16px 0 0}';
    }

    private function texts(): array
    {
        if ($this->lang() === 'en') {
            return [
                'checkout_title'   => 'NumisTR PRO Subscription',
                'pay_title'        => 'Secure Payment',
                'cardupdate_title' => 'Update Card',
                'plan_monthly'     => 'PRO Monthly',
                'plan_yearly'      => 'PRO Yearly',
                'f_name'           => 'First name',
                'f_surname'        => 'Last name',
                'f_email'          => 'E-mail',
                'f_gsm'            => 'Mobile phone',
                'f_tckn'           => 'Turkish ID number (TCKN)',
                'f_address'        => 'Billing address',
                'f_city'           => 'City',
                'f_zip'            => 'Postal code',
                'consent'          => 'I have read and accept the <a href="%s" target="_blank">Preliminary Information Form</a> and the <a href="%s" target="_blank">Distance Sales Agreement</a>.',
                'pay_button'       => 'Continue to secure payment',
                'secure_note'      => 'Payment is processed by iyzico. Your card details are never stored on NumisTR.',
                'eur_card_notice'  => 'This plan is charged in euro (EUR). Cards issued in Turkish lira cannot be used for foreign-currency subscriptions — please use a non-TRY card, or switch to the Turkish lira plan.',
                'already_active'   => 'You already have an active PRO subscription.',
                'success'          => 'Your PRO subscription is active. Welcome!',
                'canceled'         => 'Your subscription has been canceled. PRO access continues until the end of the paid period.',
                'err_name'         => 'Enter your first name.',
                'err_surname'      => 'Enter your last name.',
                'err_gsm'          => 'Enter a valid mobile number.',
                'err_tckn'         => 'Enter a valid 11-digit Turkish ID number.',
                'err_address'      => 'Enter your billing address.',
                'err_city'         => 'Enter your city.',
                'err_consent'      => 'You must accept the agreements to continue.',
            ];
        }

        return [
            'checkout_title'   => 'NumisTR PRO Aboneliği',
            'pay_title'        => 'Güvenli Ödeme',
            'cardupdate_title' => 'Kart Güncelleme',
            'plan_monthly'     => 'PRO Aylık',
            'plan_yearly'      => 'PRO Yıllık',
            'f_name'           => 'Ad',
            'f_surname'        => 'Soyad',
            'f_email'          => 'E-posta',
            'f_gsm'            => 'Cep telefonu',
            'f_tckn'           => 'T.C. Kimlik No',
            'f_address'        => 'Fatura adresi',
            'f_city'           => 'İl',
            'f_zip'            => 'Posta kodu',
            'consent'          => '<a href="%s" target="_blank">Ön Bilgilendirme Formu</a>\'nu ve <a href="%s" target="_blank">Mesafeli Satış Sözleşmesi</a>\'ni okudum, kabul ediyorum.',
            'pay_button'       => 'Güvenli ödemeye geç',
            'secure_note'      => 'Ödeme iyzico altyapısıyla alınır. Kart bilgileriniz NumisTR\'de saklanmaz.',
            'eur_card_notice'  => 'Bu plan euro (EUR) olarak tahsil edilir. Yabancı para aboneliklerinde TL kartlar kullanılamaz — TL dışı bir kart kullanın veya Türk lirası planına geçin.',
            'already_active'   => 'Zaten aktif bir PRO aboneliğiniz var.',
            'success'          => 'PRO aboneliğiniz aktif. Hoş geldiniz!',
            'canceled'         => 'Aboneliğiniz iptal edildi. PRO erişiminiz ödenen dönemin sonuna kadar devam eder.',
            'err_name'         => 'Adınızı girin.',
            'err_surname'      => 'Soyadınızı girin.',
            'err_gsm'          => 'Geçerli bir cep telefonu girin.',
            'err_tckn'         => 'Geçerli bir 11 haneli T.C. Kimlik No girin.',
            'err_address'      => 'Fatura adresinizi girin.',
            'err_city'         => 'İlinizi girin.',
            'err_consent'      => 'Devam etmek için sözleşmeleri kabul etmelisiniz.',
        ];
    }

    private function text(string $key): string
    {
        $t = $this->texts();

        return $t[$key] ?? $key;
    }

    // ==================================================================
    // Çıkış yardımcıları
    // ==================================================================

    private function failRedirect(string $langKey)
    {
        $this->app->enqueueMessage(Factory::getLanguage()->_($langKey), 'error');
        $page = trim((string) $this->params->get(
            $this->lang() === 'en' ? 'error_en' : 'error_tr',
            $this->lang() === 'en' ? '/en/plans' : '/tr/abonelikler'
        ));
        $this->app->redirect($this->canonicalRoot() . ltrim($page, '/'));

        return null;
    }

    private function ok(array $payload)
    {
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->app->close();

        return null;
    }

    private function fail(int $code, string $msg)
    {
        $this->app->setHeader('status', (string) $code, true);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        echo json_encode(['error' => $msg]);
        $this->app->close();

        return null;
    }

    private function log(string $branch, string $message): void
    {
        try {
            \Joomla\CMS\Log\Log::addLogger(['text_file' => 'numistrbilling.php'], \Joomla\CMS\Log\Log::ALL, ['numistrbilling']);
            \Joomla\CMS\Log\Log::add('[' . $branch . '] ' . $message, \Joomla\CMS\Log\Log::INFO, 'numistrbilling');
        } catch (\Throwable $e) {
            // loglama akışı asla bozmasın
        }
    }
}
