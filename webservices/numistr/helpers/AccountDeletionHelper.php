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
 * 1.20.0 — uygulama içi hesap silme (POST /v1/user/delete; App Store 5.1.1(v)).
 *
 * Kararlar (kullanıcı, 2026-10-13): silme HEMEN (bekleme yok) · Apple belirteç iptali yapılmaz (Auth0'a bırakılır) ·
 * mağaza (RevenueCat) olayları silinmez, KİMLİKSİZLEŞTİRİLİR · iyzico olayları ve numistr_subscriptions yasal saklama
 * için kalır · silip yeniden açanın aylık kotası sıfırlanır (kabul).
 *
 * Giriş: run($claims) (REST API, doğrulanmış `sub`) · runForUserId($userId) (ileride site "Hesabım"; oturum + CSRF
 * çağıranda). İkisi de aynı çekirdekte (complete()) biter, aynı sonuç dizisini döner.
 *
 * Sıra (önemli):
 *   1. Kimlik yalnız imzası doğrulanmış ID token'dan (`sub`); gövdeden kullanıcı ALINMAZ.
 *   2. Kişi başına saatte 3 (sha256(sub) anahtarı) — çağıran ayrıca kaba IP sınırı uygular.
 *   3. sub → user_id (numistr_auth_identities; yoksa doğrulanmış e-postayla #__users — AuthHelper ile aynı kural,
 *      yarım kalmış bir silmenin yeniden denenmesini de tamamlar). Yönetici grubundaysa 403, HİÇBİR şey silinmez.
 *   4. Auth0: o hesabın TÜM subject'leri silinir (204/404 başarı). Biri başarısızsa 502 — Joomla'ya dokunulmaz.
 *      İsteyen sub en sona: yarıda kalırsa kullanıcı hâlâ giriş yapıp yeniden deneyebilir.
 *   5. Joomla hesabı yoksa 200.
 *   6. DB (tek transaction): kota, tanıma kayıtları, asistan, üniversite başvurusu, mağaza olayları kimliksizleştirme,
 *      kimlik eşlemesi. Sonra başvuru dosyaları (yalnız basename, dizin dışına çıkmaz).
 *   7. Joomla User::delete() — numistrbilling onUserBeforeDelete aktif iyzico aboneliğini iptal eder.
 *   8. Günlük: tek satır, e-posta YOK.
 *
 * @since  1.20.0
 */
class AccountDeletionHelper
{
    const LIMIT_GROUP = 'numistr_user_delete';

    /** Joomla çekirdek grup kimlikleri (installation/sql/mysql/base.sql): 6 Manager, 7 Administrator, 8 Super Users. */
    const PROTECTED_GROUPS = array(6, 7, 8);

    /** Kimliksizleştirilmiş mağaza olayında app_user_id değeri (kolon NOT NULL). */
    const DELETED_APP_USER_ID = 'deleted';

    /** iyzico olaylarının environment değeri (numistrbilling recordBillingEvent) — bunlar KALIR. */
    const IYZICO_ENVIRONMENT = 'IYZICO';

    /**
     * Kimliksizleştirilmiş RevenueCat payload'ında KALAN `event` alanları (beyaz liste; geri kalan her şey — app_user_id,
     * original_app_user_id, aliases, subscriber_attributes ($email, $displayName…), transaction_id, country_code,
     * metadata… — atılır). Ürün, olay türü, ortam ve tarihler kalır.
     */
    const BILLING_KEEP_EVENT_FIELDS = array(
        'id', 'type', 'product_id', 'new_product_id', 'environment', 'store', 'period_type',
        'purchased_at_ms', 'expiration_at_ms', 'event_timestamp_ms', 'grace_period_expiration_at_ms',
        'entitlement_id', 'entitlement_ids', 'cancel_reason', 'expiration_reason', 'is_trial_conversion',
        'renewal_number', 'currency', 'price', 'price_in_purchased_currency',
    );

    /** Üniversite başvurusu kimlik dosyası uzantıları (UniversityApplicationHelper::ALLOWED_EXTENSIONS). */
    const UPLOAD_EXTENSIONS = array('jpg', 'jpeg', 'png', 'gif', 'pdf');

    /** @var array */
    private $settings;

    /** @var Auth0ManagementClient */
    private $mgmt;

    /** @var callable  function(string $group, string $key, string $window, int $max): bool */
    private $limiter;

    /** @var AccountDeletionStoreInterface */
    private $store;

    public function __construct(array $config, ?Auth0ManagementClient $mgmt = null, ?callable $limiter = null, ?AccountDeletionStoreInterface $store = null)
    {
        $this->settings = self::settings($config);
        $this->mgmt     = $mgmt ?: new Auth0ManagementClient(Auth0ManagementClient::settings($config));
        $this->limiter  = $limiter ?: function ($group, $key, $window, $max) use ($config) {
            $rl = new NumisTRRateLimiter($config);

            return $rl->checkWindowLimit($group, $key, $window, $max);
        };
        $this->store    = $store ?: new AccountDeletionStore();
    }

    /**
     * constants.php ACCOUNT_DELETION (eksikse güvenli varsayılanlar).
     *
     * @return  array  ['per_hour'=>int,'ip_per_minute'=>int,'protected_groups'=>int[]]
     */
    public static function settings(array $config)
    {
        $c = isset($config['ACCOUNT_DELETION']) ? (array) $config['ACCOUNT_DELETION'] : array();

        $groups = isset($c['protected_groups']) && is_array($c['protected_groups'])
            ? array_values(array_unique(array_map('intval', $c['protected_groups'])))
            : self::PROTECTED_GROUPS;

        return array(
            'per_hour'         => isset($c['per_hour']) ? max(0, (int) $c['per_hour']) : 3,
            'ip_per_minute'    => isset($c['ip_per_minute']) ? max(1, (int) $c['ip_per_minute']) : 10,
            // Çekirdek yönetici grupları her zaman korunur; ayar yalnız EKLER.
            'protected_groups' => array_values(array_unique(array_merge(self::PROTECTED_GROUPS, $groups))),
        );
    }

    // ===================================================================== saf kararlar ===

    /** Gövde tam olarak {"confirm": true} içermeli (boolean true; "true"/1 kabul edilmez). */
    public static function confirmed($rawBody)
    {
        $data = json_decode((string) $rawBody, true);

        return is_array($data) && array_key_exists('confirm', $data) && $data['confirm'] === true;
    }

    /**
     * Kişi başına pencere (saatlik). Anahtar özet: sayaçta ham kimlik yok.
     *
     * @return  array  [['key'=>string,'window'=>string,'max'=>int,'retry_after'=>int]]
     */
    public static function limitPlan($sub, $now, $perHour)
    {
        $now = (int) $now;

        return array(
            array('key' => 'h_' . hash('sha256', (string) $sub), 'window' => 'h' . intdiv($now, 3600), 'max' => (int) $perHour, 'retry_after' => 3600 - ($now % 3600)),
        );
    }

    /**
     * Yönetici hesabı uygulamadan silinemez.
     *
     * @param   int[]  $authorisedGroups  User::getAuthorisedGroups() (miras alınan üst gruplar dahil)
     * @param   bool   $canAdmin          core.admin ya da core.login.admin yetkisi (özel yönetici grupları)
     */
    public static function isProtected(array $authorisedGroups, $canAdmin, array $protected = self::PROTECTED_GROUPS)
    {
        if ($canAdmin) {
            return true;
        }

        return count(array_intersect(array_map('intval', $authorisedGroups), array_map('intval', $protected))) > 0;
    }

    /** Tekil, boş olmayan subject'ler; isteyen en sonda. */
    public static function orderSubjects(array $subjects, $requester)
    {
        $requester = (string) $requester;
        $out       = array();

        foreach ($subjects as $s) {
            $s = trim((string) $s);

            if ($s !== '' && $s !== $requester && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        if ($requester !== '') {
            $out[] = $requester;
        }

        return $out;
    }

    /** Başvuru eşleştirmesi için e-postalar: küçük harf, geçerli, tekil. */
    public static function emailsFor($userEmail, array $identityEmails)
    {
        $out = array();

        foreach (array_merge(array($userEmail), $identityEmails) as $e) {
            $e = strtolower(trim((string) $e));

            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /**
     * Başvuru dosyasının silinecek yolu ya da null (silinmez). Yalnız düz dosya adı: dizin ayırıcı, `..`,
     * gizli dosya (.htaccess) ve izinli uzantı dışı her şey reddedilir.
     */
    public static function safeUploadPath($dir, $filename)
    {
        $filename = (string) $filename;

        if ($filename === '' || strlen($filename) > 255 || preg_match('~[/\\\\\x00]~', $filename)
            || $filename[0] === '.' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            return null;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, self::UPLOAD_EXTENSIONS, true)) {
            return null;
        }

        return rtrim((string) $dir, '/') . '/' . $filename;
    }

    /** Kimliksizleştirilmiş RevenueCat payload'ı (yalnız beyaz listedeki skaler alanlar). */
    public static function anonymizeBillingPayload($payload, $at)
    {
        $data  = json_decode((string) $payload, true);
        $event = (is_array($data) && isset($data['event']) && is_array($data['event'])) ? $data['event'] : array();
        $keep  = array();

        foreach (self::BILLING_KEEP_EVENT_FIELDS as $f) {
            if (!array_key_exists($f, $event)) {
                continue;
            }

            $v = $event[$f];

            if (is_scalar($v) || $v === null) {
                $keep[$f] = $v;
            } elseif (is_array($v) && $v === array_filter($v, 'is_string')) {
                $keep[$f] = array_values($v); // entitlement_ids: yetki adları
            }
        }

        $out = array('anonymized' => true, 'anonymized_at' => (string) $at);

        if (is_array($data) && isset($data['api_version']) && is_scalar($data['api_version'])) {
            $out['api_version'] = $data['api_version'];
        }

        $out['event'] = (object) $keep;

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Mağaza olayı satırında değişecek kolonlar — YALNIZ bunlar. event_id, event_type, product_id, environment,
     * action, expires_at, created_at aynen kalır.
     */
    public static function billingAnonymizedColumns($payload, $at)
    {
        return array(
            'user_id'     => 0,
            'app_user_id' => self::DELETED_APP_USER_ID,
            'payload'     => self::anonymizeBillingPayload($payload, $at),
        );
    }

    /**
     * web_subscription_canceled: null = silme öncesi aktif iyzico aboneliği yoktu (ya da durum okunamadı) ·
     * true = vardı ve artık ACTIVE değil (onUserBeforeDelete iptal etti) · false = vardı, hâlâ ACTIVE (iptal başarısız).
     */
    public static function webCancelOutcome($subRef, $statusAfter)
    {
        if ($subRef === null || $subRef === '' || $statusAfter === null) {
            return null;
        }

        return strtoupper((string) $statusAfter) !== 'ACTIVE';
    }

    /**
     * Saf: DB adımları (sıra önemli — alt kayıtlar önce, kimlik eşlemesi EN SON). Tanıma kayıtları SİLİNİR
     * (user_id=0 yapılmaz: AnonTrial IP tavanı user_id=0 satırlarını sayar).
     *
     * @param   int       $userId
     * @param   string[]  $subjects  Auth0 subject'leri
     * @param   string[]  $emails    emailsFor() çıktısı
     * @param   callable  $q         değer tırnaklayıcı ($db->quote)
     * @param   callable  $qn        ad tırnaklayıcı ($db->quoteName)
     *
     * @return  array  [['step','table','requires'=>string[],'kind'=>exec|files_then_exec|anonymize,'sql','select'?], ...]
     */
    public static function purgePlan($userId, array $subjects, array $emails, callable $q, callable $qn)
    {
        $uid = (int) $userId;

        if ($uid <= 0) {
            throw new \InvalidArgumentException('purgePlan: user_id > 0 olmalı');
        }

        $subjIn = $subjects ? implode(', ', array_map($q, array_values($subjects))) : '';
        $uidKey = $q((string) $uid);

        $tConv = '#__numistr_assistant_conversation';
        $tMsg  = '#__numistr_assistant_message';
        $tUni  = '#__numistr_university_applications';
        $conv  = 'SELECT ' . $qn('id') . ' FROM ' . $qn($tConv) . ' WHERE ' . $qn('user_id') . ' = ' . $uid;
        $msg   = 'SELECT ' . $qn('id') . ' FROM ' . $qn($tMsg) . ' WHERE ' . $qn('conversation_id') . ' IN (' . $conv . ')';

        $del = function ($step, $table, $where, array $requires = array()) use ($qn) {
            return array('step' => $step, 'table' => $table, 'requires' => $requires, 'kind' => 'exec',
                'sql' => 'DELETE FROM ' . $qn($table) . ' WHERE ' . $where);
        };

        $plan   = array();
        $plan[] = $del('quota', 'numistr_scan_quota', $qn('user_id') . ' = ' . $uid);
        $plan[] = $del('recog', 'numistr_recognition_requests', $qn('user_id') . ' = ' . $uid);
        $plan[] = $del('recog_log', '#__numistr_recognition_log', $qn('user_id') . ' = ' . $uid);
        $plan[] = $del('asst_tool', '#__numistr_assistant_tool_log', $qn('message_id') . ' IN (' . $msg . ')', array($tMsg, $tConv));
        $plan[] = $del('asst_msg', $tMsg, $qn('conversation_id') . ' IN (' . $conv . ')', array($tConv));
        $plan[] = $del('asst_conv', $tConv, $qn('user_id') . ' = ' . $uid);
        $plan[] = $del('asst_quota', '#__numistr_assistant_quota', $qn('subject_type') . ' IN (' . $q('user') . ', ' . $q('pro') . ') AND ' . $qn('subject_key') . ' = ' . $uidKey);
        $plan[] = $del('asst_abuse', '#__numistr_assistant_abuse', $qn('subject_key') . ' = ' . $uidKey);

        if ($emails) {
            // Büyük/küçük harf duyarsız: başvuru formu e-postayı olduğu gibi kaydediyor.
            $byMail = 'LOWER(' . $qn('email') . ') IN (' . implode(', ', array_map($q, array_values($emails))) . ')';
            $plan[] = array(
                'step' => 'uni', 'table' => $tUni, 'requires' => array(), 'kind' => 'files_then_exec',
                'select' => 'SELECT ' . $qn('id_card_filename') . ' FROM ' . $qn($tUni) . ' WHERE ' . $byMail,
                'sql'    => 'DELETE FROM ' . $qn($tUni) . ' WHERE ' . $byMail,
            );
        }

        $mine   = $qn('user_id') . ' = ' . $uid . ($subjIn !== '' ? ' OR ' . $qn('app_user_id') . ' IN (' . $subjIn . ')' : '');
        $plan[] = array(
            'step' => 'billing_anon', 'table' => 'numistr_billing_events', 'requires' => array(), 'kind' => 'anonymize',
            'select' => 'SELECT ' . $qn('id') . ', ' . $qn('payload') . ' FROM ' . $qn('numistr_billing_events')
                . ' WHERE ' . $qn('environment') . ' <> ' . $q(self::IYZICO_ENVIRONMENT) . ' AND (' . $mine . ')',
            'sql'    => '',
        );

        $plan[] = $del('identities', 'numistr_auth_identities',
            $qn('user_id') . ' = ' . $uid . ($subjIn !== '' ? ' OR ' . $qn('subject') . ' IN (' . $subjIn . ')' : ''));

        return $plan;
    }

    /** Günlük satırı: e-posta yazılmaz; hata metnindeki e-posta biçimli her şey maskelenir. */
    public static function logLine(array $r)
    {
        $parts = array();

        foreach ($r as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif ($v === null) {
                $v = 'null';
            } elseif (is_array($v)) {
                $v = json_encode($v);
            }

            $v       = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@\'"]+/', '[e-posta]', (string) $v);
            $parts[] = $k . '=' . str_replace(array("\r", "\n"), ' ', $v);
        }

        return implode(' ', $parts);
    }

    // ==================================================================== uçtan uca ===
    //
    // İki giriş, tek çekirdek (complete()):
    //   run($claims)          — REST API (POST /v1/user/delete): kimlik = imzası doğrulanmış Auth0 `sub`.
    //   runForUserId($userId) — ileride sitedeki "Hesabım" düğmesi: kimlik = Joomla oturumu; oturumu ve CSRF'i
    //                           ÇAĞIRAN doğrular. sub'lar numistr_auth_identities'ten bulunur.
    // Dönüş ikisinde de aynı: ['status','code','body','retry_after','log','error'].

    /**
     * @param   array|null  $claims  İmzası doğrulanmış ID token yükü; null = doğrulanamadı
     * @param   int|null    $now
     *
     * @return  array  ['status'=>int,'code'=>string,'body'=>array,'retry_after'=>int,'log'=>string,'error'=>bool]
     */
    public function run($claims, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $log = array('uid' => 0);

        if (!is_array($claims) || empty($claims['sub']) || !is_string($claims['sub'])) {
            return $this->result(401, 'UNAUTHORIZED', $log);
        }

        $sub = $claims['sub'];

        if (!$this->mgmt->isConfigured()) {
            return $this->result(503, 'DELETE_UNAVAILABLE', $log);
        }

        foreach (self::limitPlan($sub, $now, $this->settings['per_hour']) as $w) {
            if ($w['max'] > 0 && !call_user_func($this->limiter, self::LIMIT_GROUP, $w['key'], $w['window'], $w['max'])) {
                return $this->result(429, 'RATE_LIMITED', $log, $w['retry_after']);
            }
        }

        // ---- 3. hesabı bul (henüz hiçbir şey silinmedi) ----
        try {
            $uid = $this->store->findUserIdBySubject($sub);

            if ($uid <= 0 && !empty($claims['email_verified']) && !empty($claims['email'])
                && filter_var((string) $claims['email'], FILTER_VALIDATE_EMAIL)) {
                $uid           = $this->store->findUserIdByEmail((string) $claims['email']);
                $log['via']    = $uid > 0 ? 'email' : 'none';
            } else {
                $log['via'] = $uid > 0 ? 'identity' : 'none';
            }

            $user       = $uid > 0 ? $this->store->loadUser($uid) : null;
            $identities = $uid > 0 ? $this->store->identities($uid) : array();
        } catch (\Throwable $e) {
            $log['step'] = 'lookup';
            $log['err']  = $e->getMessage();

            return $this->result(500, 'DELETE_FAILED', $log);
        }

        return $this->complete((int) $uid, $user, $identities, $sub, $log, $now);
    }

    /**
     * Joomla kullanıcı kimliğiyle silme (site "Hesabım" yolu için hazır; bu sürümde çağıran yok).
     * ÇAĞIRAN SORUMLU: geçerli Joomla oturumu (kullanıcı yalnız KENDİ id'si) + CSRF (Session::checkToken) + onay.
     * Hesabın Auth0 subject'leri numistr_auth_identities'ten bulunur; hiç yoksa Auth0 adımı atlanır.
     *
     * @param   int       $userId
     * @param   int|null  $now
     *
     * @return  array  run() ile aynı biçim
     */
    public function runForUserId($userId, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $uid = (int) $userId;
        $log = array('uid' => $uid, 'via' => 'session');

        if ($uid <= 0) {
            return $this->result(401, 'UNAUTHORIZED', $log);
        }

        foreach (self::limitPlan('uid:' . $uid, $now, $this->settings['per_hour']) as $w) {
            if ($w['max'] > 0 && !call_user_func($this->limiter, self::LIMIT_GROUP, $w['key'], $w['window'], $w['max'])) {
                return $this->result(429, 'RATE_LIMITED', $log, $w['retry_after']);
            }
        }

        try {
            $user       = $this->store->loadUser($uid);
            $identities = $this->store->identities($uid);
        } catch (\Throwable $e) {
            $log['step'] = 'lookup';
            $log['err']  = $e->getMessage();

            return $this->result(500, 'DELETE_FAILED', $log);
        }

        return $this->complete($uid, $user, $identities, '', $log, $now);
    }

    /**
     * Ortak çekirdek: hesap bulunduktan sonraki her şey (koruma → Auth0 → DB → dosyalar → Joomla).
     *
     * @param   int         $uid         0 = Joomla hesabı yok
     * @param   array|null  $user        AccountDeletionStoreInterface::loadUser()
     * @param   array       $identities  [['subject','email'], ...]
     * @param   string      $requester   İsteyen sub (API) — Auth0'da EN SON silinir; site yolunda ''
     */
    private function complete($uid, $user, array $identities, $requester, array $log, $now)
    {
        $log['uid'] = (int) $uid;

        if ($user !== null && self::isProtected((array) $user['groups'], !empty($user['can_admin']), $this->settings['protected_groups'])) {
            return $this->result(403, 'ACCOUNT_PROTECTED', $log);
        }

        $all      = array_column($identities, 'subject');
        $subjects = self::orderSubjects($requester !== '' ? array_merge($all, array($requester)) : $all, $requester);

        if ($subjects && !$this->mgmt->isConfigured()) {
            return $this->result(503, 'DELETE_UNAVAILABLE', $log);
        }

        $subRef = $uid > 0 ? $this->store->activeWebSubscription($uid) : null;

        $log['subjects']     = count($subjects);
        $log['web_sub']      = $subRef !== null ? 'active' : 'none';
        $log['store_active'] = $uid > 0 ? $this->store->hasActiveStoreEntitlement($uid) : false;

        // ---- 4. Auth0 (başarısızsa Joomla'ya DOKUNULMAZ) ----
        $deleted = 0;
        $absent  = 0;

        foreach ($subjects as $s) {
            $d = $this->mgmt->deleteUser($s, $now);

            if (!$d['ok']) {
                $log['auth0_deleted'] = $deleted;
                $log['auth0_absent']  = $absent;
                $log['step']          = 'auth0';
                $log['err']           = $d['outcome'] . ' ' . $this->mgmt->getLastError();

                return $this->result(502, 'AUTH0_DELETE_FAILED', $log);
            }

            if ($d['outcome'] === 'deleted') {
                $deleted++;
            } else {
                $absent++;
            }
        }

        $log['auth0_deleted'] = $deleted;
        $log['auth0_absent']  = $absent;
        $body                 = array('deleted' => true, 'auth0_users_deleted' => $deleted, 'web_subscription_canceled' => null);

        // ---- 5. Joomla hesabı yok ----
        if ($uid <= 0) {
            return $this->result(200, '', $log, 0, $body);
        }

        // ---- 6. DB (transaction) + başvuru dosyaları ----
        $emails = self::emailsFor($user !== null ? (string) $user['email'] : '', array_column($identities, 'email'));

        try {
            $purge = $this->store->purge($uid, $subjects, $emails, gmdate('Y-m-d H:i:s', $now));
        } catch (\Throwable $e) {
            $log['step'] = 'db';
            $log['err']  = $e->getMessage();

            return $this->result(500, 'DELETE_FAILED', $log);
        }

        foreach ((array) $purge['counts'] as $k => $v) {
            $log[$k] = $v;
        }

        $files            = $this->store->unlinkUploads((array) $purge['files']);
        $log['uni_files'] = $files['deleted'] . '/' . ($files['deleted'] + $files['failed']);

        // ---- 7. Joomla hesabı ----
        if ($user !== null) {
            try {
                $joomla = $this->store->deleteJoomlaUser($uid);
            } catch (\Throwable $e) {
                $joomla     = 'failed';
                $log['err'] = $e->getMessage();
            }

            $log['joomla'] = $joomla;

            if ($joomla === 'failed') {
                $log['step'] = 'joomla';

                return $this->result(500, 'DELETE_FAILED', $log);
            }
        } else {
            $log['joomla'] = 'absent';
        }

        $body['web_subscription_canceled'] = self::webCancelOutcome($subRef, $subRef !== null ? $this->store->webSubscriptionStatus($subRef) : null);
        $log['web_canceled']               = $body['web_subscription_canceled'];

        return $this->result(200, '', $log, 0, $body);
    }

    private function result($status, $code, array $log, $retryAfter = 0, array $body = array())
    {
        $log = array_merge(array('result' => (int) $status, 'code' => $code !== '' ? $code : 'OK'), $log);

        return array(
            'status'      => (int) $status,
            'code'        => $code,
            'body'        => $body,
            'retry_after' => (int) $retryAfter,
            'log'         => self::logLine($log),
            'error'       => $status >= 500,
        );
    }

    /** Günlük dosyası: administrator/logs/numistr_user_delete.php (Joomla Log; `die()` önekli). */
    public static function writeLog($line, $isError)
    {
        try {
            \Joomla\CMS\Log\Log::addLogger(array('text_file' => 'numistr_user_delete.php'), \Joomla\CMS\Log\Log::ALL, array('numistr-user-delete'));
            \Joomla\CMS\Log\Log::add((string) $line, $isError ? \Joomla\CMS\Log\Log::ERROR : \Joomla\CMS\Log\Log::INFO, 'numistr-user-delete');
        } catch (\Throwable $e) {
            // günlük yazılamazsa silme sonucu değişmez
        }
    }
}

/**
 * Hesap silmenin veri katmanı (testte sahtesi verilir).
 *
 * @since  1.20.0
 */
interface AccountDeletionStoreInterface
{
    /** @throws \Throwable  sorgu hatası (silme başlamadan 500) */
    public function findUserIdBySubject($sub);

    /** Doğrulanmış e-postayla, engellenmemiş hesap (AuthHelper kuralı). @throws \Throwable */
    public function findUserIdByEmail($email);

    /** @return array|null  ['id'=>int,'email'=>string,'groups'=>int[],'can_admin'=>bool]; null = Joomla hesabı yok */
    public function loadUser($userId);

    /** @return array  [['subject'=>string,'email'=>string], ...] @throws \Throwable */
    public function identities($userId);

    /** @return string|null  ACTIVE iyzico aboneliğinin referansı */
    public function activeWebSubscription($userId);

    /** @return string|null */
    public function webSubscriptionStatus($subRef);

    /** Yalnız günlük için: son mağaza grant olayı hâlâ geçerli mi? (kimliksizleştirmeden ÖNCE okunur) */
    public function hasActiveStoreEntitlement($userId);

    /** @return array  ['counts'=>[step=>int|'skip'], 'files'=>string[]] @throws \Throwable  (geri alındıktan sonra) */
    public function purge($userId, array $subjects, array $emails, $at);

    /** @return array  ['deleted'=>int,'failed'=>int] */
    public function unlinkUploads(array $filenames);

    /** @return string  'ok' | 'absent' | 'failed' */
    public function deleteJoomlaUser($userId);
}

/**
 * Joomla veritabanı uygulaması. Tablo adları kodla aynı: numistr_* önek'siz, asistan/başvuru `#__` önekli.
 * Olmayan tablo adımı atlanır ('skip'); kurulmamış bir özellik silmeyi bozmaz.
 *
 * @since  1.20.0
 */
class AccountDeletionStore implements AccountDeletionStoreInterface
{
    /** @var array|null  küçük harf gerçek tablo adı => true */
    private $tables = null;

    private function db()
    {
        return Factory::getDbo();
    }

    public function findUserIdBySubject($sub)
    {
        $db = $this->db();
        $db->setQuery(
            'SELECT ' . $db->quoteName('user_id') . ' FROM ' . $db->quoteName('numistr_auth_identities')
            . ' WHERE ' . $db->quoteName('subject') . ' = ' . $db->quote((string) $sub) . ' LIMIT 1'
        );

        return (int) $db->loadResult();
    }

    public function findUserIdByEmail($email)
    {
        $db = $this->db();
        $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__users')
            . ' WHERE LOWER(' . $db->quoteName('email') . ') = ' . $db->quote(strtolower(trim((string) $email)))
            . ' AND ' . $db->quoteName('block') . ' = 0 LIMIT 1'
        );

        return (int) $db->loadResult();
    }

    public function loadUser($userId)
    {
        $user = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById((int) $userId);

        if (!$user || (int) $user->id <= 0) {
            return null;
        }

        return array(
            'id'        => (int) $user->id,
            'email'     => (string) $user->email,
            'groups'    => array_map('intval', (array) $user->getAuthorisedGroups()),
            'can_admin' => (bool) ($user->authorise('core.admin') || $user->authorise('core.login.admin')),
        );
    }

    public function identities($userId)
    {
        $db = $this->db();
        $db->setQuery(
            'SELECT ' . $db->quoteName('subject') . ', ' . $db->quoteName('email') . ' FROM ' . $db->quoteName('numistr_auth_identities')
            . ' WHERE ' . $db->quoteName('user_id') . ' = ' . (int) $userId
        );

        return (array) $db->loadAssocList();
    }

    public function activeWebSubscription($userId)
    {
        try {
            $db = $this->db();
            $db->setQuery(
                'SELECT ' . $db->quoteName('subscription_reference_code') . ' FROM ' . $db->quoteName('numistr_subscriptions')
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . (int) $userId
                . ' AND ' . $db->quoteName('source') . ' = ' . $db->quote('iyzico')
                . ' AND ' . $db->quoteName('status') . ' = ' . $db->quote('ACTIVE')
                . ' ORDER BY ' . $db->quoteName('id') . ' DESC LIMIT 1'
            );
            $ref = (string) $db->loadResult();

            return $ref !== '' ? $ref : null;
        } catch (\Throwable $e) {
            return null; // tablo yok = web aboneliği yok
        }
    }

    public function webSubscriptionStatus($subRef)
    {
        try {
            $db = $this->db();
            $db->setQuery(
                'SELECT ' . $db->quoteName('status') . ' FROM ' . $db->quoteName('numistr_subscriptions')
                . ' WHERE ' . $db->quoteName('subscription_reference_code') . ' = ' . $db->quote((string) $subRef) . ' LIMIT 1'
            );
            $st = $db->loadResult();

            return $st === null ? null : (string) $st;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function hasActiveStoreEntitlement($userId)
    {
        try {
            $db = $this->db();
            $db->setQuery(
                'SELECT ' . $db->quoteName('expires_at') . ' FROM ' . $db->quoteName('numistr_billing_events')
                . ' WHERE ' . $db->quoteName('user_id') . ' = ' . (int) $userId
                . ' AND ' . $db->quoteName('environment') . ' <> ' . $db->quote(AccountDeletionHelper::IYZICO_ENVIRONMENT)
                . ' AND ' . $db->quoteName('action') . ' LIKE ' . $db->quote('grant%')
                . ' ORDER BY ' . $db->quoteName('created_at') . ' DESC LIMIT 1'
            );
            $exp = (string) $db->loadResult();

            return $exp !== '' && strtotime($exp) > time();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasTable($name)
    {
        $db = $this->db();

        if ($this->tables === null) {
            $this->tables = array();

            foreach ((array) $db->getTableList() as $t) {
                $this->tables[strtolower((string) $t)] = true;
            }
        }

        return isset($this->tables[strtolower($db->replacePrefix($name))]);
    }

    public function purge($userId, array $subjects, array $emails, $at)
    {
        $db   = $this->db();
        $plan = AccountDeletionHelper::purgePlan($userId, $subjects, $emails, array($db, 'quote'), array($db, 'quoteName'));

        $counts = array();
        $files  = array();

        $db->transactionStart();

        try {
            foreach ($plan as $p) {
                $ok = $this->hasTable($p['table']);

                foreach ($p['requires'] as $req) {
                    $ok = $ok && $this->hasTable($req);
                }

                if (!$ok) {
                    $counts[$p['step']] = 'skip';
                    continue;
                }

                if ($p['kind'] === 'files_then_exec') {
                    $db->setQuery($p['select']);
                    $files = array_merge($files, array_map('strval', (array) $db->loadColumn()));
                }

                if ($p['kind'] === 'anonymize') {
                    $db->setQuery($p['select']);
                    $n = 0;

                    foreach ((array) $db->loadAssocList() as $row) {
                        $cols = AccountDeletionHelper::billingAnonymizedColumns($row['payload'] ?? null, $at);
                        $db->setQuery(
                            'UPDATE ' . $db->quoteName($p['table']) . ' SET '
                            . $db->quoteName('user_id') . ' = ' . (int) $cols['user_id'] . ', '
                            . $db->quoteName('app_user_id') . ' = ' . $db->quote($cols['app_user_id']) . ', '
                            . $db->quoteName('payload') . ' = ' . $db->quote($cols['payload'])
                            . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $row['id']
                        )->execute();
                        $n++;
                    }

                    $counts[$p['step']] = $n;
                    continue;
                }

                $db->setQuery($p['sql'])->execute();
                $counts[$p['step']] = (int) $db->getAffectedRows();
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            try {
                $db->transactionRollback();
            } catch (\Throwable $ignore) {
                // bağlantı koptuysa geri alma da düşer; asıl hata yukarı gider
            }

            throw $e;
        }

        return array('counts' => $counts, 'files' => $files);
    }

    public function unlinkUploads(array $filenames)
    {
        $dir     = JPATH_ROOT . '/media/university_applications';
        $realDir = realpath($dir);
        $deleted = 0;
        $failed  = 0;

        foreach (array_unique($filenames) as $name) {
            $path = AccountDeletionHelper::safeUploadPath($dir, $name);

            if ($path === null || $realDir === false) {
                $failed++;
                continue;
            }

            if (!file_exists($path)) {
                continue; // zaten yok
            }

            // Sembolik bağlantı / dizin dışı hedef: yalnız gerçek dizindeki düz dosya silinir.
            if (is_link($path) || !is_file($path) || dirname((string) realpath($path)) !== $realDir) {
                $failed++;
                continue;
            }

            if (@unlink($path)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        return array('deleted' => $deleted, 'failed' => $failed);
    }

    public function deleteJoomlaUser($userId)
    {
        $user = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById((int) $userId);

        if (!$user || (int) $user->id <= 0) {
            return 'absent';
        }

        // onUserBeforeDelete (numistrbilling: aktif iyzico aboneliğini iptal) → tablo silme → onUserAfterDelete.
        return $user->delete() ? 'ok' : 'failed';
    }
}
