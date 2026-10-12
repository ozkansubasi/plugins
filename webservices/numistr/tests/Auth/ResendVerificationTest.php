<?php
/**
 * A23 — doğrulama iletisini yeniden gönder (POST /v1/auth/resend-verification).
 *
 * Ağ ve önbellek sahte: kararlar, Auth0'ya giden istekler ve hata eşlemesi test edilir.
 * Güvenlik kuralları: kimlik yalnız doğrulanmış belirteçten (`sub`), yalnız e-posta/şifre
 * hesapları, tavan aşılınca Auth0'ya hiç gidilmez, sır hata metnine sızmaz.
 */

require_once $root . '/helpers/RateLimiter.php';
require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/VerificationResendHelper.php';

$H = 'VerificationResendHelper';

// ------------------------------------------------------------------- ayarlar ---
$off = $H::settings([]);
check('ayar yoksa özellik KAPALI', $off['configured'] === false);
check('varsayılan tavanlar 1/dk · 5/gün · 30/saat', $off['per_minute'] === 1 && $off['per_day'] === 5 && $off['global_per_hour'] === 30);

$repo = include $root . '/config/constants.php';
$rs   = $H::settings($repo);
check('repo: Management API kanonik tenant adresinde (custom domain değil)', $rs['domain'] === 'dev-ja5k8sumb7005j4n.us.auth0.com');
check('repo: ileti mobil uygulamanın ayarlarıyla (audiences[0])', $rs['verify_client_id'] === $repo['AUTH0']['audiences'][0]);
check('repo: sır repoda yok (secrets.php olmadan kapalı)', $rs['client_secret'] === '' && $rs['configured'] === false);

// ------------------------------------------------------------------ uygunluk ---
check('belirteç doğrulanamadı → 401 INVALID_TOKEN', $H::eligibility(null) === ['ok' => false, 'status' => 401, 'code' => 'INVALID_TOKEN']);
check('sub yok → 401', $H::eligibility(['email' => 'a@b.c'])['code'] === 'INVALID_TOKEN');
check('zaten doğrulanmış → 200 ALREADY_VERIFIED', $H::eligibility(['sub' => 'auth0|1', 'email_verified' => true])['code'] === 'ALREADY_VERIFIED');
check('Google girişi → 400 NOT_APPLICABLE', $H::eligibility(['sub' => 'google-oauth2|9', 'email_verified' => false])['status'] === 400);
check('Apple girişi → 400 NOT_APPLICABLE', $H::eligibility(['sub' => 'apple|9'])['code'] === 'NOT_APPLICABLE');
check('e-posta/şifre, doğrulanmamış → uygun', $H::eligibility(['sub' => 'auth0|abc', 'email_verified' => false])['ok'] === true);

// ------------------------------------------------------------------ pencereler ---
$now  = 1791760437; // 2026-10-11 23:13:57 UTC
$plan = $H::limitPlan($off, 'auth0|abc', $now);
check('pencere sırası: dakika → gün → genel saat', count($plan) === 3 && strpos($plan[0]['key'], 'm_') === 0 && strpos($plan[1]['key'], 'd_') === 0 && $plan[2]['key'] === 'global');
check('sayaç anahtarında ham kimlik yok (özet)', strpos(implode('|', array_column($plan, 'key')), 'auth0') === false);
check('dakika penceresi bekleme süresi ≤ 60', $plan[0]['retry_after'] === 60 - ($now % 60));
check('gün penceresi UTC tarihi', $plan[1]['window'] === '2026-10-11');

// ------------------------------------------------------------- uçtan uca (sahte) ---
$cfg = ['AUTH0' => ['management' => [
    'domain' => 'tenant.example', 'client_id' => 'm2m-id', 'client_secret' => 'S3CRET-VALUE',
    'verify_client_id' => 'app-client', 'per_minute' => 1, 'per_day' => 5, 'global_per_hour' => 30,
]]];
$claims = ['sub' => 'auth0|abc', 'email_verified' => false];

/** Sahte Auth0: yanıtları sırayla verir, istekleri kaydeder. */
$makeHttp = function (array $replies) {
    $log = new ArrayObject();
    $fn  = function ($method, $url, $headers, $body) use (&$replies, $log) {
        $log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

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
$allow = function () { return true; };

// a) yapılandırılmamış → 503, Auth0'ya gidilmez
[$http, $log] = $makeHttp([]);
$r = (new $H(['AUTH0' => []], $http, $allow))->resend($claims, $now);
check('yapılandırılmamış → 503 RESEND_UNAVAILABLE', $r['status'] === 503 && $r['code'] === 'RESEND_UNAVAILABLE' && count($log) === 0);

// b) uygun değil → Auth0'ya gidilmez, sayaç da artmaz
$bumped = 0;
$count  = function () use (&$bumped) { $bumped++; return true; };
[$http, $log] = $makeHttp([]);
$r = (new $H($cfg, $http, $count))->resend(['sub' => 'google-oauth2|1'], $now);
check('sosyal giriş → 400, Auth0 isteği yok, sayaç artmadı', $r['status'] === 400 && count($log) === 0 && $bumped === 0);

// c) dakika tavanı → 429 + bekleme, Auth0'ya gidilmez
$deny = function ($group, $key) { return strpos($key, 'm_') !== 0; };
[$http, $log] = $makeHttp([]);
$r = (new $H($cfg, $http, $deny))->resend($claims, $now);
check('dakika tavanı → 429 RESEND_RATE_LIMITED + retry_after', $r['status'] === 429 && $r['code'] === 'RESEND_RATE_LIMITED' && $r['retry_after'] > 0 && $r['retry_after'] <= 60);
check('tavan aşılınca Auth0 isteği yok', count($log) === 0);

// d) mutlu yol
[$get, $set, $box] = $memStore();
[$http, $log] = $makeHttp([
    ['status' => 200, 'body' => json_encode(['access_token' => 'TOK1', 'expires_in' => 86400])],
    ['status' => 201, 'body' => '{"status":"pending"}'],
]);
$helper = new $H($cfg, $http, $allow, $get, $set);
$r = $helper->resend($claims, $now);
check('başarı → 200 {sent:true, retry_after:60}', $r['status'] === 200 && $r['body'] === ['sent' => true, 'retry_after' => 60]);
$tokReq = json_decode($log[0]['body'], true);
check('belirteç isteği: client_credentials + Management API audience', $log[0]['url'] === 'https://tenant.example/oauth/token'
    && $tokReq['grant_type'] === 'client_credentials' && $tokReq['audience'] === 'https://tenant.example/api/v2/');
$jobReq = json_decode($log[1]['body'], true);
check('iş isteği: jobs/verification-email, kimlik belirteçten, ileti uygulama ayarıyla',
    $log[1]['url'] === 'https://tenant.example/api/v2/jobs/verification-email'
    && $jobReq === ['user_id' => 'auth0|abc', 'client_id' => 'app-client']
    && in_array('Authorization: Bearer TOK1', $log[1]['headers'], true));

// e) belirteç önbellekten (ikinci istekte oauth/token yok)
[$http2, $log2] = $makeHttp([['status' => 201, 'body' => '{}']]);
$r = (new $H($cfg, $http2, $allow, $get, $set))->resend($claims, $now + 120);
check('ikinci gönderimde belirteç önbellekten (tek istek)', $r['status'] === 200 && count($log2) === 1 && strpos($log2[0]['url'], '/jobs/') !== false);

// f) süresi dolmak üzere olan belirteç yenilenir
$box['tok_' . sha1('tenant.example|m2m-id')] = ['t' => 'OLD', 'exp' => $now + 30];
[$http3, $log3] = $makeHttp([
    ['status' => 200, 'body' => json_encode(['access_token' => 'TOK2', 'expires_in' => 86400])],
    ['status' => 201, 'body' => '{}'],
]);
(new $H($cfg, $http3, $allow, $get, $set))->resend($claims, $now);
check('süresine 60 sn kalan belirteç yenilenir', count($log3) === 2 && in_array('Authorization: Bearer TOK2', $log3[1]['headers'], true));

// g) belirteç alınamaz → 502, sır hata metnine sızmaz
[$get4, $set4] = $memStore();
[$http4] = $makeHttp([['status' => 401, 'body' => '{"error":"access_denied","error_description":"Unauthorized"}']]);
$h4 = new $H($cfg, $http4, $allow, $get4, $set4);
$r  = $h4->resend($claims, $now);
check('belirteç alınamadı → 502 RESEND_FAILED', $r['status'] === 502 && $r['code'] === 'RESEND_FAILED');
check('hata metninde sır yok', strpos($h4->getLastError(), 'S3CRET') === false && $h4->getLastError() !== '');

// h) Auth0 "zaten doğrulanmış" (belirteç eski) → 200 ALREADY_VERIFIED
[$get5, $set5] = $memStore();
[$http5] = $makeHttp([
    ['status' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 600])],
    ['status' => 400, 'body' => '{"statusCode":400,"message":"The user\'s email is already verified."}'],
]);
$r = (new $H($cfg, $http5, $allow, $get5, $set5))->resend($claims, $now);
check('Auth0 400 "already verified" → 200 ALREADY_VERIFIED', $r['status'] === 200 && $r['body']['code'] === 'ALREADY_VERIFIED');

// i) Auth0 429 → 429
[$get6, $set6] = $memStore();
[$http6] = $makeHttp([['status' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 600])], ['status' => 429, 'body' => '']]);
$r = (new $H($cfg, $http6, $allow, $get6, $set6))->resend($claims, $now);
check('Auth0 429 → 429 RESEND_RATE_LIMITED', $r['status'] === 429 && $r['retry_after'] === 60);

// j) Auth0 403 → önbellekteki belirteç atılır
[$get7, $set7, $box7] = $memStore();
[$http7] = $makeHttp([['status' => 200, 'body' => json_encode(['access_token' => 'T', 'expires_in' => 600])], ['status' => 403, 'body' => '{"message":"Insufficient scope"}']]);
$r = (new $H($cfg, $http7, $allow, $get7, $set7))->resend($claims, $now);
check('Auth0 403 → 502 ve önbellekteki belirteç atıldı', $r['status'] === 502 && count($box7) === 0);

// ------------------------------------------------------------- hata gövdesi ---
$plain = NumisTRResponseHelper::errorPayload(401, 'Unauthorized', 'x');
check('eski hata gövdesi aynen (title, code, detail)', $plain === ['errors' => [['title' => 'Unauthorized', 'code' => 401, 'detail' => 'x']]]);
$coded = NumisTRResponseHelper::errorPayload(401, 'Unauthorized', 'x', ['error_code' => 'EMAIL_NOT_VERIFIED']);
check('error_code eklenir', $coded['errors'][0]['error_code'] === 'EMAIL_NOT_VERIFIED' && !isset($coded['errors'][0]['retry_after']));
$rl = NumisTRResponseHelper::errorPayload(429, 'Too Many Requests', null, ['error_code' => 'RESEND_RATE_LIMITED', 'retry_after' => 42]);
check('retry_after eklenir', $rl['errors'][0]['retry_after'] === 42);

// ------------------------------------------------------------- kaynak denetimi ---
$main = (string) file_get_contents($root . '/numistr.php');
$s    = strpos($main, 'private function handleResendVerification(');
$e    = strpos($main, 'private function ', $s + 10);
$hb   = $s === false ? '' : substr($main, $s, $e - $s);
check('uç kayıtlı', strpos($main, '/v1/auth/resend-verification') !== false);
check('uç imzayı her zaman doğrular (NumisTRJwtVerifier::verify)', strpos($hb, 'new NumisTRJwtVerifier') !== false && strpos($hb, '->verify(') !== false);
check('uç kimliği istek gövdesinden almaz', strpos($hb, 'php://input') === false && strpos($hb, '$_POST') === false);
check('uç yanıtı no-store', strpos($hb, 'sendJson($res[\'body\'], true)') !== false);

$auth = (string) file_get_contents($root . '/helpers/AuthHelper.php');
check('AuthHelper doğrulanmamış e-postada EMAIL_NOT_VERIFIED bırakır', strpos($auth, "\$this->lastFailure = 'EMAIL_NOT_VERIFIED';") !== false);
check('AuthHelper her denemede nedeni sıfırlar', strpos($auth, "\$this->lastFailure = '';") !== false);
