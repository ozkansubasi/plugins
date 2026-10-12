<?php
/**
 * 1.20.0 — uygulama içi hesap silme (POST /v1/user/delete).
 *
 * Ağ, önbellek ve veritabanı sahte: kararlar, sıra (Auth0 başarısızsa DB'ye dokunulmaz), Auth0 istekleri,
 * yol koruması, korunan gruplar ve mağaza olaylarının kimliksizleştirilmesi test edilir.
 */

require_once $root . '/helpers/RateLimiter.php';
require_once $root . '/helpers/Auth0ManagementClient.php';
require_once $root . '/helpers/AccountDeletionHelper.php';

$D = 'AccountDeletionHelper';
$C = 'Auth0ManagementClient';

// ------------------------------------------------------------------- ayarlar ---
$def = $D::settings([]);
check('varsayılan: kişi başı saatte 3, IP dakikada 10', $def['per_hour'] === 3 && $def['ip_per_minute'] === 10);
check('varsayılan korunan gruplar 6/7/8', $def['protected_groups'] === [6, 7, 8]);
$narrow = $D::settings(['ACCOUNT_DELETION' => ['protected_groups' => [12]]]);
check('ayar çekirdek yönetici gruplarını KALDIRAMAZ, yalnız ekler', $narrow['protected_groups'] === [6, 7, 8, 12]);

$repo = include $root . '/config/constants.php';
$rs   = $D::settings($repo);
check('repo: ACCOUNT_DELETION tanımlı (3/saat, 6/7/8)', $rs['per_hour'] === 3 && $rs['protected_groups'] === [6, 7, 8]);
$ms = $C::settings($repo);
check('repo: M2M kanonik tenant, sır repoda yok (kapalı)', $ms['domain'] === 'dev-ja5k8sumb7005j4n.us.auth0.com' && $ms['client_secret'] === '' && $ms['configured'] === false);
check('M2M yapılandırması verify_client_id istemez (silme için)', $C::settings(['AUTH0' => ['management' => ['domain' => 'd', 'client_id' => 'c', 'client_secret' => 's']]])['configured'] === true);

// ------------------------------------------------------------------- onay gövdesi ---
check('onay: {"confirm":true} → evet', $D::confirmed('{"confirm":true}') === true);
check('onay: ek alan kimlik sayılmaz ama onayı bozmaz', $D::confirmed('{"confirm":true,"sub":"auth0|başkası"}') === true);
check('onay: "true" (metin) → hayır', $D::confirmed('{"confirm":"true"}') === false);
check('onay: 1 → hayır', $D::confirmed('{"confirm":1}') === false);
check('onay: false / boş / bozuk JSON / dizi → hayır',
    !$D::confirmed('{"confirm":false}') && !$D::confirmed('') && !$D::confirmed('{confirm:true}') && !$D::confirmed('[true]') && !$D::confirmed('{}'));

// ------------------------------------------------------------------- pencere ---
$now  = 1791849600 + 1234; // 2026-10-13 00:20:34 UTC
$plan = $D::limitPlan('google-oauth2|123', $now, 3);
check('kişi başı pencere: saatlik, tavan 3', count($plan) === 1 && $plan[0]['max'] === 3 && $plan[0]['window'] === 'h' . intdiv($now, 3600));
check('sayaç anahtarında ham kimlik yok (sha256)', $plan[0]['key'] === 'h_' . hash('sha256', 'google-oauth2|123') && strpos($plan[0]['key'], 'google') === false);
check('bekleme süresi saat sonuna kadar', $plan[0]['retry_after'] === 3600 - ($now % 3600));

// ------------------------------------------------------------------- korunan gruplar ---
check('Registered + Pro → silinebilir', $D::isProtected([1, 2, 10], false) === false);
check('Manager (6) → korunur', $D::isProtected([1, 6], false) === true);
check('Administrator (6←7 miras) → korunur', $D::isProtected([1, 6, 7], false) === true);
check('Super Users (8) → korunur', $D::isProtected([1, 8], false) === true);
check('grup kimliği metin olsa da korunur', $D::isProtected(['1', '8'], false) === true);
check('core.admin / core.login.admin yetkisi (özel yönetici grubu) → korunur', $D::isProtected([1, 2, 42], true) === true);
check('Author/Editor/Publisher yönetici sayılmaz', $D::isProtected([1, 2, 3, 4, 5], false) === false);

// ------------------------------------------------------------------- subject / e-posta ---
check('subject sırası: tekil, boşlar atılır, isteyen EN SONDA',
    $D::orderSubjects(['auth0|me', 'google-oauth2|1', '', 'apple|2', 'google-oauth2|1'], 'auth0|me') === ['google-oauth2|1', 'apple|2', 'auth0|me']);
check('identities boşsa yalnız isteyen', $D::orderSubjects([], 'apple|x') === ['apple|x']);
check('e-postalar: küçük harf, tekil, geçersiz atılır',
    $D::emailsFor(' Ali@Ornek.COM ', ['ali@ornek.com', 'privaterelay@APPLEID.com', 'bozuk', '']) === ['ali@ornek.com', 'privaterelay@appleid.com']);

// ------------------------------------------------------------------- dosya yolu koruması ---
$dir = '/srv/site/media/university_applications';
check('yol: düz dosya adı → dizin içinde', $D::safeUploadPath($dir, '20261013_101500_Yilmaz_Ali.pdf') === $dir . '/20261013_101500_Yilmaz_Ali.pdf');
check('yol: sondaki / tekrarlanmaz', $D::safeUploadPath($dir . '/', 'a.jpg') === $dir . '/a.jpg');
check('yol: ../ reddedilir', $D::safeUploadPath($dir, '../../configuration.pdf') === null);
check('yol: alt dizin / ters bölü reddedilir', $D::safeUploadPath($dir, 'x/y.pdf') === null && $D::safeUploadPath($dir, 'x\\y.pdf') === null);
check('yol: .htaccess ve gizli dosya reddedilir', $D::safeUploadPath($dir, '.htaccess') === null && $D::safeUploadPath($dir, '..pdf') === null);
check('yol: izinli olmayan uzantı (php) reddedilir', $D::safeUploadPath($dir, 'kabuk.php') === null && $D::safeUploadPath($dir, 'a.pdf.php') === null);
check('yol: NUL bayt / boşluk / boş reddedilir', $D::safeUploadPath($dir, "a\0.pdf") === null && $D::safeUploadPath($dir, 'a b.pdf') === null && $D::safeUploadPath($dir, '') === null);
check('yol: büyük harf uzantı kabul (JPG)', $D::safeUploadPath($dir, 'A.JPG') === $dir . '/A.JPG');

// ------------------------------------------------------------------- mağaza olayı kimliksizleştirme ---
$rcRaw = json_encode([
    'api_version' => '1.0',
    'event'       => [
        'id' => 'EVT-1', 'type' => 'INITIAL_PURCHASE', 'product_id' => 'numistr_pro_monthly', 'environment' => 'PRODUCTION',
        'store' => 'APP_STORE', 'period_type' => 'NORMAL', 'purchased_at_ms' => 1791000000000, 'expiration_at_ms' => 1793600000000,
        'entitlement_ids' => ['pro'], 'price' => 4.99, 'currency' => 'USD',
        'app_user_id' => 'google-oauth2|1040398', 'original_app_user_id' => 'google-oauth2|1040398',
        'aliases' => ['$RCAnonymousID:abc', 'google-oauth2|1040398'],
        'subscriber_attributes' => ['$email' => ['value' => 'ali@ornek.com'], '$displayName' => ['value' => 'Ali Yılmaz']],
        'transaction_id' => '2000000123', 'original_transaction_id' => '2000000100', 'country_code' => 'TR',
        'metadata' => ['note' => 'x'],
    ],
]);
$anon = $D::anonymizeBillingPayload($rcRaw, '2026-10-13 00:20:34');
$aj   = json_decode($anon, true);
check('kimliksiz payload geçerli JSON + işaretli', is_array($aj) && $aj['anonymized'] === true && $aj['anonymized_at'] === '2026-10-13 00:20:34');
check('ürün / olay türü / ortam / tarihler KALIR',
    $aj['event']['product_id'] === 'numistr_pro_monthly' && $aj['event']['type'] === 'INITIAL_PURCHASE' && $aj['event']['environment'] === 'PRODUCTION'
    && $aj['event']['purchased_at_ms'] === 1791000000000 && $aj['event']['expiration_at_ms'] === 1793600000000 && $aj['event']['entitlement_ids'] === ['pro']);
check('kişisel alanlar ATILIR (app_user_id, aliases, subscriber_attributes, transaction_id, country_code, metadata)',
    !isset($aj['event']['app_user_id']) && !isset($aj['event']['original_app_user_id']) && !isset($aj['event']['aliases'])
    && !isset($aj['event']['subscriber_attributes']) && !isset($aj['event']['transaction_id']) && !isset($aj['event']['original_transaction_id'])
    && !isset($aj['event']['country_code']) && !isset($aj['event']['metadata']));
check('kimliksiz payload\'da e-posta / sub / ad izi yok',
    strpos($anon, 'ornek.com') === false && strpos($anon, '1040398') === false && strpos($anon, 'Yılmaz') === false && strpos($anon, 'RCAnonymousID') === false);
check('ikinci kez kimliksizleştirme aynı alanları korur (idempotent)',
    json_decode($D::anonymizeBillingPayload($anon, 'T2'), true)['event'] === $aj['event']);
check('boş/bozuk payload → geçerli JSON, boş event', json_decode($D::anonymizeBillingPayload(null, 'T'), true)['event'] === [] && $D::anonymizeBillingPayload('{bozuk', 'T') !== '');
$cols = $D::billingAnonymizedColumns($rcRaw, 'T');
check('satırda YALNIZ user_id=0, app_user_id=deleted, payload değişir (ürün/tür/ortam/tarih kolonlarına dokunulmaz)',
    array_keys($cols) === ['user_id', 'app_user_id', 'payload'] && $cols['user_id'] === 0 && $cols['app_user_id'] === 'deleted');

// ------------------------------------------------------------------- web aboneliği sonucu ---
check('web aboneliği yoktu → null', $D::webCancelOutcome(null, null) === null);
check('vardı, iptal edildi → true', $D::webCancelOutcome('SUB-1', 'CANCELED') === true);
check('vardı, hâlâ ACTIVE → false', $D::webCancelOutcome('SUB-1', 'ACTIVE') === false);
check('vardı, durum okunamadı → null', $D::webCancelOutcome('SUB-1', null) === null);

// ------------------------------------------------------------------- Auth0 durum eşleme ---
check('Auth0 204/200 → silindi', $C::deleteOutcome(204) === 'deleted' && $C::deleteOutcome(200) === 'deleted');
check('Auth0 404 → zaten yok (başarı)', $C::deleteOutcome(404) === 'absent');
check('Auth0 401/403 → belirteç sorunu', $C::deleteOutcome(401) === 'auth' && $C::deleteOutcome(403) === 'auth');
check('Auth0 429/500/0 → hata', $C::deleteOutcome(429) === 'error' && $C::deleteOutcome(500) === 'error' && $C::deleteOutcome(0) === 'error');

// ------------------------------------------------------------------- DB planı (saf) ---
$q  = function ($v) { return "'" . addslashes((string) $v) . "'"; };
$qn = function ($n) { return '`' . $n . '`'; };
$threw = false;
try {
    $D::purgePlan(0, [], [], $q, $qn);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('plan: user_id=0 ile plan YOK (istisna)', $threw);

$pp    = $D::purgePlan(5, ['google-oauth2|1', 'auth0|me'], ['ali@ornek.com'], $q, $qn);
$steps = array_column($pp, 'step');
$byS   = array_combine($steps, $pp);
check('plan: tüm adımlar', $steps === ['quota', 'recog', 'recog_log', 'asst_tool', 'asst_msg', 'asst_conv', 'asst_quota', 'asst_abuse', 'uni', 'billing_anon', 'identities']);
check('plan: asistan alt kayıtları önce (araç günlüğü → mesaj → konuşma)',
    array_search('asst_tool', $steps) < array_search('asst_msg', $steps) && array_search('asst_msg', $steps) < array_search('asst_conv', $steps));
check('plan: kimlik eşlemesi EN SON', end($steps) === 'identities');
check('plan: tanıma kayıtları SİLİNİR (user_id=0 yapılmaz)',
    $byS['recog']['sql'] === 'DELETE FROM `numistr_recognition_requests` WHERE `user_id` = 5' && strpos(implode(';', array_column($pp, 'sql')), '= 0') === false
    && stripos(implode(';', array_column($pp, 'sql')), 'UPDATE') === false);
check('plan: kota + recognition_log user_id ile', $byS['quota']['sql'] === 'DELETE FROM `numistr_scan_quota` WHERE `user_id` = 5'
    && $byS['recog_log']['table'] === '#__numistr_recognition_log');
check('plan: asistan kotası subject_key=(string)user_id, user/pro', strpos($byS['asst_quota']['sql'], "`subject_key` = '5'") !== false && strpos($byS['asst_quota']['sql'], "IN ('user', 'pro')") !== false);
check('plan: asistan abuse subject_key=user_id', strpos($byS['asst_abuse']['sql'], "`subject_key` = '5'") !== false);
check('plan: araç günlüğü mesaj+konuşma tablosu ister', $byS['asst_tool']['requires'] === ['#__numistr_assistant_message', '#__numistr_assistant_conversation']);
check('plan: başvuru e-postayla, büyük/küçük harf duyarsız; önce dosya adları okunur',
    $byS['uni']['kind'] === 'files_then_exec' && strpos($byS['uni']['sql'], "LOWER(`email`) IN ('ali@ornek.com')") !== false
    && strpos($byS['uni']['select'], '`id_card_filename`') !== false);
check('plan: mağaza olayları iyzico DIŞI, user_id veya subject ile; silinmez, kimliksizleşir',
    $byS['billing_anon']['kind'] === 'anonymize' && strpos($byS['billing_anon']['select'], "`environment` <> 'IYZICO'") !== false
    && strpos($byS['billing_anon']['select'], "`app_user_id` IN ('google-oauth2|1', 'auth0|me')") !== false && $byS['billing_anon']['sql'] === '');
check('plan: kimlik eşlemesi user_id + tüm subject\'ler', strpos($byS['identities']['sql'], "`user_id` = 5 OR `subject` IN ('google-oauth2|1', 'auth0|me')") !== false);
check('plan: numistr_subscriptions (iyzico, yasal saklama) plana GİRMEZ', strpos(json_encode($pp), 'numistr_subscriptions') === false);
check('plan: e-posta yoksa başvuru adımı yok', !in_array('uni', array_column($D::purgePlan(5, ['a|1'], [], $q, $qn), 'step'), true));
check('plan: tırnaklama zorunlu (enjeksiyon)', strpos(json_encode($D::purgePlan(5, ["x' OR 1=1 --"], [], $q, $qn)), "x\\\\' OR") !== false);

// ------------------------------------------------------------------- günlük ---
$line = $D::logLine(['uid' => 5, 'err' => "Duplicate entry 'ali@ornek.com' for key", 'ok' => true, 'web' => null]);
check('günlük: e-posta maskelenir, tek satır', strpos($line, 'ornek.com') === false && strpos($line, '[e-posta]') !== false && strpos($line, "\n") === false);
check('günlük: bool/null okunur', strpos($line, 'ok=1') !== false && strpos($line, 'web=null') !== false);

// ======================================================================= uçtan uca ===
$cfg = ['AUTH0' => ['management' => ['domain' => 'tenant.example', 'client_id' => 'm2m-id', 'client_secret' => 'S3CRET-VALUE']]];

$events = new ArrayObject();

/** Sahte Auth0: yanıtları sırayla verir, istekleri kaydeder. */
$makeHttp = function (array $replies) use ($events) {
    $log = new ArrayObject();
    $fn  = function ($method, $url, $headers, $body) use (&$replies, $log, $events) {
        $log[]    = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $events[] = 'http:' . $method . ' ' . parse_url($url, PHP_URL_PATH);

        return array_shift($replies) ?: ['status' => 500, 'body' => ''];
    };

    return [$fn, $log];
};
$memStore = function () {
    $box = new ArrayObject();

    return [
        function ($k) use ($box) { return $box[$k] ?? null; },
        function ($k, $v) use ($box) { if ($v === null) { unset($box[$k]); } else { $box[$k] = $v; } },
        $box,
    ];
};
$tok   = function ($t) { return ['status' => 200, 'body' => json_encode(['access_token' => $t, 'expires_in' => 86400])]; };
$allow = function () { return true; };

/** Sahte veri katmanı: her çağrı $events'e yazılır. */
$makeStore = function (array $o) use ($events) {
    return new class ($o, $events) implements AccountDeletionStoreInterface {
        public $o;
        public $ev;
        public $calls = [];

        public function __construct($o, $ev)
        {
            $this->o  = $o + [
                'bySub' => 0, 'byEmail' => 0, 'user' => null, 'identities' => [], 'subRef' => null, 'statusAfter' => null,
                'storeActive' => false, 'purgeThrows' => false, 'joomla' => 'ok', 'lookupThrows' => false,
            ];
            $this->ev = $ev;
        }

        private function rec($n, $a = null)
        {
            $this->calls[] = [$n, $a];
            $this->ev[]    = 'store:' . $n;
        }

        public function findUserIdBySubject($sub)
        {
            $this->rec('bySub', $sub);
            if ($this->o['lookupThrows']) {
                throw new \RuntimeException("Table 'numistr_auth_identities' doesn't exist");
            }

            return $this->o['bySub'];
        }

        public function findUserIdByEmail($email) { $this->rec('byEmail', $email); return $this->o['byEmail']; }
        public function loadUser($userId) { $this->rec('loadUser', $userId); return $this->o['user']; }
        public function identities($userId) { $this->rec('identities', $userId); return $this->o['identities']; }
        public function activeWebSubscription($userId) { $this->rec('activeWeb', $userId); return $this->o['subRef']; }
        public function webSubscriptionStatus($subRef) { $this->rec('webStatus', $subRef); return $this->o['statusAfter']; }
        public function hasActiveStoreEntitlement($userId) { $this->rec('storeActive', $userId); return $this->o['storeActive']; }

        public function purge($userId, array $subjects, array $emails, $at)
        {
            $this->rec('purge', [$userId, $subjects, $emails]);
            if ($this->o['purgeThrows']) {
                throw new \RuntimeException("Deadlock found when trying to get lock; email='ali@ornek.com'");
            }

            return ['counts' => ['quota' => 1, 'recog' => 7, 'recog_log' => 'skip', 'billing_anon' => 2, 'identities' => 2], 'files' => ['20261001_x.pdf']];
        }

        public function unlinkUploads(array $filenames) { $this->rec('unlink', $filenames); return ['deleted' => count($filenames), 'failed' => 0]; }
        public function deleteJoomlaUser($userId) { $this->rec('joomla', $userId); return $this->o['joomla']; }

        public function called($n) { return in_array($n, array_column($this->calls, 0), true); }
        public function arg($n) { foreach ($this->calls as $c) { if ($c[0] === $n) { return $c[1]; } } return null; }
    };
};
$regular = ['id' => 5, 'email' => 'Ali@Ornek.com', 'groups' => [1, 2, 10], 'can_admin' => false];
$claims  = ['sub' => 'auth0|me', 'email' => 'ali@ornek.com', 'email_verified' => true];
$mk      = function ($http, $store, $limiter = null, $mem = null) use ($cfg, $allow, $memStore) {
    [$get, $set] = $mem ?: $memStore();

    return new AccountDeletionHelper($cfg, new Auth0ManagementClient(Auth0ManagementClient::settings($cfg), $http, $get, $set), $limiter ?: $allow, $store);
};

// a) belirteç doğrulanamadı → 401, hiçbir çağrı yok
[$http, $log] = $makeHttp([]);
$st = $makeStore([]);
$r  = $mk($http, $st)->run(null, $now);
check('belirteç yok → 401 UNAUTHORIZED, Auth0/DB çağrısı yok', $r['status'] === 401 && $r['code'] === 'UNAUTHORIZED' && count($log) === 0 && $st->calls === []);
check('sub boş → 401', $mk($http, $makeStore([]))->run(['sub' => ''], $now)['status'] === 401);

// b) M2M yapılandırılmamış → 503
[$http, $log] = $makeHttp([]);
$st = $makeStore([]);
$r  = (new AccountDeletionHelper(['AUTH0' => []], new Auth0ManagementClient([], $http), $allow, $st))->run($claims, $now);
check('M2M yok → 503 DELETE_UNAVAILABLE, hiçbir şey silinmez', $r['status'] === 503 && $r['code'] === 'DELETE_UNAVAILABLE' && count($log) === 0 && $st->calls === []);

// c) kişi başı tavan → 429
$seen = [];
$deny = function ($group, $key, $window, $max) use (&$seen) { $seen = [$group, $key, $max]; return false; };
[$http, $log] = $makeHttp([]);
$st = $makeStore(['bySub' => 5, 'user' => $regular]);
$r  = $mk($http, $st, $deny)->run($claims, $now);
check('kişi başı tavan → 429 RATE_LIMITED + retry_after', $r['status'] === 429 && $r['code'] === 'RATE_LIMITED' && $r['retry_after'] === 3600 - ($now % 3600));
check('tavan: grup numistr_user_delete, anahtar özet, tavan 3', $seen === ['numistr_user_delete', 'h_' . hash('sha256', 'auth0|me'), 3]);
check('tavan aşılınca Auth0 ve DB çağrısı yok', count($log) === 0 && $st->calls === []);

// d) yönetici → 403, hiçbir şey silinmez
foreach ([['Super User', [1, 8], false], ['Administrator', [1, 6, 7], false], ['özel yönetici grubu (core.login.admin)', [1, 2, 30], true]] as [$label, $groups, $canAdmin]) {
    [$http, $log] = $makeHttp([$tok('T')]);
    $st = $makeStore(['bySub' => 1, 'user' => ['id' => 1, 'email' => 'a@b.co', 'groups' => $groups, 'can_admin' => $canAdmin]]);
    $r  = $mk($http, $st)->run($claims, $now);
    check('korunan hesap (' . $label . ') → 403 ACCOUNT_PROTECTED, Auth0/DB silme yok',
        $r['status'] === 403 && $r['code'] === 'ACCOUNT_PROTECTED' && count($log) === 0 && !$st->called('purge') && !$st->called('joomla'));
}

// e) Auth0 başarısız → 502, Joomla'ya DOKUNULMAZ
$events->exchangeArray([]);
[$http, $log] = $makeHttp([$tok('T'), ['status' => 204, 'body' => ''], ['status' => 500, 'body' => '{"error":"x"}']]);
$st = $makeStore(['bySub' => 5, 'user' => $regular, 'identities' => [['subject' => 'google-oauth2|1', 'email' => 'ali@ornek.com'], ['subject' => 'auth0|me', 'email' => 'ali@ornek.com']]]);
$r  = $mk($http, $st)->run($claims, $now);
check('Auth0 500 → 502 AUTH0_DELETE_FAILED', $r['status'] === 502 && $r['code'] === 'AUTH0_DELETE_FAILED' && $r['error'] === true);
check('Auth0 başarısızsa DB / dosya / Joomla adımı ÇAĞRILMAZ', !$st->called('purge') && !$st->called('unlink') && !$st->called('joomla'));
check('Auth0 başarısız: günlükte adım ve sayaçlar', strpos($r['log'], 'step=auth0') !== false && strpos($r['log'], 'auth0_deleted=1') !== false);

[$http, $log] = $makeHttp([['status' => 401, 'body' => '{"error":"access_denied"}']]);
$st = $makeStore(['bySub' => 5, 'user' => $regular]);
$r  = $mk($http, $st)->run($claims, $now);
check('M2M belirteci alınamadı → 502, DB\'ye dokunulmaz', $r['status'] === 502 && !$st->called('purge') && strpos($r['log'], 'S3CRET') === false);

// f) mutlu yol: iki subject, sıra, istekler
$events->exchangeArray([]);
[$http, $log] = $makeHttp([$tok('TOK1'), ['status' => 204, 'body' => ''], ['status' => 204, 'body' => '']]);
$st = $makeStore([
    'bySub' => 5, 'user' => $regular, 'subRef' => 'SUB-9', 'statusAfter' => 'CANCELED', 'storeActive' => true,
    'identities' => [['subject' => 'auth0|me', 'email' => 'ali@ornek.com'], ['subject' => 'google-oauth2|1', 'email' => 'Ali.Yilmaz@Gmail.com']],
]);
$r = $mk($http, $st)->run($claims, $now);
check('başarı → 200 {deleted:true, auth0_users_deleted:2, web_subscription_canceled:true}',
    $r['status'] === 200 && $r['body'] === ['deleted' => true, 'auth0_users_deleted' => 2, 'web_subscription_canceled' => true]);
check('belirteç isteği client_credentials + Management API audience',
    $log[0]['url'] === 'https://tenant.example/oauth/token' && json_decode($log[0]['body'], true)['audience'] === 'https://tenant.example/api/v2/');
check('Auth0 DELETE: diğer subject önce, isteyen EN SON; kimlik rawurlencode',
    $log[1]['method'] === 'DELETE' && $log[1]['url'] === 'https://tenant.example/api/v2/users/google-oauth2%7C1'
    && $log[2]['url'] === 'https://tenant.example/api/v2/users/auth0%7Cme');
check('DELETE gövdesiz, Bearer belirteçli', $log[1]['body'] === null && in_array('Authorization: Bearer TOK1', $log[1]['headers'], true)
    && !in_array('Content-Type: application/json', $log[1]['headers'], true));
check('DB: purge user_id + tüm subject\'ler + küçük harf e-postalar',
    $st->arg('purge') === [5, ['google-oauth2|1', 'auth0|me'], ['ali@ornek.com', 'ali.yilmaz@gmail.com']]);
$ev   = $events->getArrayCopy();
$iDel = array_search('http:DELETE /api/v2/users/auth0%7Cme', $ev, true);
check('sıra: aktif web aboneliği okunur → Auth0 → DB → dosyalar → Joomla User::delete → durum',
    array_search('store:activeWeb', $ev, true) < array_search('http:DELETE /api/v2/users/google-oauth2%7C1', $ev, true)
    && $iDel < array_search('store:purge', $ev, true)
    && array_search('store:purge', $ev, true) < array_search('store:unlink', $ev, true)
    && array_search('store:unlink', $ev, true) < array_search('store:joomla', $ev, true)
    && array_search('store:joomla', $ev, true) < array_search('store:webStatus', $ev, true));
check('mağaza grant durumu kimliksizleştirmeden ÖNCE okunur (günlük için)', array_search('store:storeActive', $ev, true) < array_search('store:purge', $ev, true)
    && strpos($r['log'], 'store_active=1') !== false);
check('başvuru dosyaları purge çıktısından silinir', $st->arg('unlink') === ['20261001_x.pdf'] && strpos($r['log'], 'uni_files=1/1') !== false);
check('günlükte e-posta yok, adım sayaçları var', strpos($r['log'], '@') === false && strpos($r['log'], 'recog=7') !== false
    && strpos($r['log'], 'uid=5') !== false && strpos($r['log'], 'joomla=ok') !== false && $r['error'] === false);

// g) idempotent: Auth0 404 + Joomla hesabı yok → 200, DB'ye gidilmez
[$http, $log] = $makeHttp([$tok('T'), ['status' => 404, 'body' => '{"statusCode":404}']]);
$st = $makeStore(['bySub' => 0]);
$r  = $mk($http, $st)->run(['sub' => 'auth0|unverified', 'email' => 'x@y.co', 'email_verified' => false], $now);
check('Auth0 404 = zaten silinmiş = başarı; Joomla hesabı yok → 200, DB adımı yok',
    $r['status'] === 200 && $r['body'] === ['deleted' => true, 'auth0_users_deleted' => 0, 'web_subscription_canceled' => null]
    && !$st->called('purge') && !$st->called('joomla'));
check('doğrulanmamış e-postayla Joomla hesabı ARANMAZ', !$st->called('byEmail'));

// h) yarım kalmış silmenin tekrarı: kimlik eşlemesi silinmiş → doğrulanmış e-postayla bulunur
[$http, $log] = $makeHttp([$tok('T'), ['status' => 404, 'body' => '']]);
$st = $makeStore(['bySub' => 0, 'byEmail' => 5, 'user' => $regular]);
$r  = $mk($http, $st)->run($claims, $now);
check('doğrulanmış e-postayla hesap bulunur, silme tamamlanır', $r['status'] === 200 && $st->arg('byEmail') === 'ali@ornek.com'
    && $st->called('purge') && $st->called('joomla') && strpos($r['log'], 'via=email') !== false);

// i) önbellekteki eski kapsamlı belirteç: 403 → belirteç atılır, BİR KEZ yenisiyle denenir
[$get, $set, $box] = $memStore();
$box['tok_' . sha1('tenant.example|m2m-id')] = ['t' => 'OLD-UPDATE-ONLY', 'exp' => $now + 3600];
[$http, $log] = $makeHttp([['status' => 403, 'body' => '{"message":"Insufficient scope, expected any of: delete:users"}'], $tok('NEW'), ['status' => 204, 'body' => '']]);
$st = $makeStore(['bySub' => 0]);
$r  = $mk($http, $st, null, [$get, $set])->run(['sub' => 'apple|001'], $now);
check('403 (eski kapsam) → önbellek atılır, yeni belirteçle bir kez daha → 200',
    $r['status'] === 200 && count($log) === 3 && in_array('Authorization: Bearer OLD-UPDATE-ONLY', $log[0]['headers'], true)
    && $log[1]['url'] === 'https://tenant.example/oauth/token' && in_array('Authorization: Bearer NEW', $log[2]['headers'], true));
check('yeni belirteç önbellekte (aynı anahtar: resend ile ortak)', ($box['tok_' . sha1('tenant.example|m2m-id')]['t'] ?? '') === 'NEW');

[$get, $set, $box] = $memStore();
[$http, $log] = $makeHttp([$tok('A'), ['status' => 403, 'body' => ''], $tok('B'), ['status' => 403, 'body' => '']]);
$st = $makeStore(['bySub' => 5, 'user' => $regular]);
$r  = $mk($http, $st, null, [$get, $set])->run($claims, $now);
check('403 iki kez → 502, tek yeniden deneme, DB\'ye dokunulmaz', $r['status'] === 502 && count($log) === 4 && !$st->called('purge') && count($box) === 0);

// j) DB adımı başarısız → 500, Joomla User::delete çağrılmaz
[$http, $log] = $makeHttp([$tok('T'), ['status' => 204, 'body' => '']]);
$st = $makeStore(['bySub' => 5, 'user' => $regular, 'purgeThrows' => true]);
$r  = $mk($http, $st)->run($claims, $now);
check('DB hatası → 500 DELETE_FAILED (Auth0 silinmiş), User::delete çağrılmaz',
    $r['status'] === 500 && $r['code'] === 'DELETE_FAILED' && !$st->called('joomla') && !$st->called('unlink') && $r['error'] === true);
check('DB hatası günlükte, e-posta maskeli', strpos($r['log'], 'step=db') !== false && strpos($r['log'], 'Deadlock') !== false && strpos($r['log'], 'ornek.com') === false);

// k) Joomla User::delete başarısız → 500
[$http, $log] = $makeHttp([$tok('T'), ['status' => 204, 'body' => '']]);
$r = $mk($http, $makeStore(['bySub' => 5, 'user' => $regular, 'joomla' => 'failed']))->run($claims, $now);
check('User::delete başarısız → 500 DELETE_FAILED', $r['status'] === 500 && strpos($r['log'], 'step=joomla') !== false);

// l) arama hatası → 500, hiçbir şey silinmez
[$http, $log] = $makeHttp([$tok('T')]);
$st = $makeStore(['lookupThrows' => true]);
$r  = $mk($http, $st)->run($claims, $now);
check('hesap araması hata → 500, Auth0 silme yok', $r['status'] === 500 && count($log) === 0 && strpos($r['log'], 'step=lookup') !== false);

// m) web aboneliği iptal edilemedi / yoktu; Joomla hesabı zaten silinmiş (artık kimlik satırları)
[$http, $log] = $makeHttp([$tok('T'), ['status' => 204, 'body' => '']]);
$r = $mk($http, $makeStore(['bySub' => 5, 'user' => $regular, 'subRef' => 'SUB-1', 'statusAfter' => 'ACTIVE']))->run($claims, $now);
check('web aboneliği hâlâ ACTIVE → web_subscription_canceled=false', $r['status'] === 200 && $r['body']['web_subscription_canceled'] === false);
[$http, $log] = $makeHttp([$tok('T'), ['status' => 204, 'body' => '']]);
$st = $makeStore(['bySub' => 5, 'user' => null]);
$r  = $mk($http, $st)->run($claims, $now);
check('Joomla hesabı yok ama kimlik satırı var → DB temizlenir, User::delete çağrılmaz, 200',
    $r['status'] === 200 && $st->called('purge') && !$st->called('joomla') && $r['body']['web_subscription_canceled'] === null);

// ------------------------------------------------------------- kaynak denetimi ---
$main = (string) file_get_contents($root . '/numistr.php');
$s    = strpos($main, 'private function handleUserDelete(');
$e    = strpos($main, 'private function ', $s + 10);
$hb   = $s === false ? '' : substr($main, $s, $e - $s);
check('uç kayıtlı (/v1/user/delete)', strpos($main, "/v1/user/delete(?:[/?#;]|$)~") !== false && strpos($main, '$this->handleUserDelete($uri);') !== false);
check('uç yalnız POST (405)', strpos($hb, "!== 'POST'") !== false && strpos($hb, '405') !== false);
check('uç imzayı her zaman doğrular, requireAuth KULLANMAZ', strpos($hb, 'new NumisTRJwtVerifier') !== false && strpos($hb, '->verify(') !== false && strpos($hb, 'requireAuth') === false);
check('uç gövdeyi YALNIZ onay için okur; kimlik run($claims)\'e belirteçten gider',
    substr_count($hb, 'php://input') === 1 && strpos($hb, 'AccountDeletionHelper::confirmed((string) file_get_contents(\'php://input\'))') !== false
    && strpos($hb, '$helper->run($claims)') !== false && strpos($hb, '$_POST') === false);
check('uç: kaba IP sınırı RATE_LIMITED koduyla', strpos($hb, "checkRateLimit('user-delete', \$settings['ip_per_minute'], 'RATE_LIMITED')") !== false);
check('uç: kurulum eksikse 503 DELETE_UNAVAILABLE', strpos($hb, "'DELETE_UNAVAILABLE'") !== false && strpos($hb, "class_exists('AccountDeletionHelper')") !== false);
check('uç yanıtı no-store, data zarfında', strpos($hb, "sendJson(['data' => \$res['body']], true)") !== false);
$pClient = strpos($main, "require_once __DIR__ . '/helpers/Auth0ManagementClient.php';");
check('yükleme sırası: Auth0ManagementClient → VerificationResendHelper / AccountDeletionHelper',
    $pClient !== false && $pClient < strpos($main, "require_once __DIR__ . '/helpers/VerificationResendHelper.php';")
    && $pClient < strpos($main, "require_once __DIR__ . '/helpers/AccountDeletionHelper.php';"));
check('AccountDeletionHelper istemci yoksa yüklenmez (kısmi deploy /v1\'i düşürmez)', strpos($main, "if (class_exists('Auth0ManagementClient') && file_exists(__DIR__ . '/helpers/AccountDeletionHelper.php'))") !== false);
check('resend ucu da istemci yoksa 503', strpos($main, "!class_exists('VerificationResendHelper') || !class_exists('Auth0ManagementClient')") !== false);

$sec = (string) file_get_contents($root . '/config/secrets.example.php');
check('secrets.example: M2M kapsamı update:users + delete:users', strpos($sec, '`update:users` + `delete:users`') !== false);
$const = (string) file_get_contents($root . '/config/constants.php');
check('constants: M2M kapsamı belgeli', strpos($const, 'update:users + delete:users') !== false);
