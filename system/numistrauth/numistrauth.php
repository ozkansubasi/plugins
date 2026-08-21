<?php
/**
 * @package     NumisTR Web Auth (Auth0 OIDC login for the Joomla site)
 * @subpackage  plg_system_numistrauth
 * @version     1.0.2
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * ADR-003 Faz 2 — web üyelik. Uygulamayla AYNI Auth0 hesabı: Authorization Code + PKCE,
 * ID token doğrulaması (JwtHelper, RS256/JWKS), sub -> Joomla user eşlemesi
 * (numistr_auth_identities; mobil API ile aynı tablo), sonra gerçek Joomla oturumu.
 *
 * Uçlar (com_ajax):
 *   /index.php?option=com_ajax&plugin=numistrauth&format=raw&task=login[&return=/tr/...]
 *   /index.php?option=com_ajax&plugin=numistrauth&format=raw&task=signup[&return=...]
 *   /index.php?option=com_ajax&plugin=numistrauth&format=raw&task=callback   (Auth0 redirect_uri)
 *   /index.php?option=com_ajax&plugin=numistrauth&format=raw&task=logout[&return=...]
 *   /index.php?option=com_ajax&plugin=numistrauth&format=json&task=status    (giriş durumu, JSON)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserHelper;

class PlgSystemNumistrauth extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    protected $autoloadLanguage = true;

    private const SESSION_NS = 'numistrauth';

    /**
     * com_ajax entry point: plugin=numistrauth
     */
    public function onAjaxNumistrauth()
    {
        if (!$this->app->isClient('site')) {
            return $this->fail(403, 'site only');
        }

        $task = preg_replace('/[^a-z_]/', '', strtolower((string) $this->app->input->getCmd('task', '')));

        switch ($task) {
            case 'login':
            case 'signup':
                return $this->startAuth($task === 'signup');
            case 'callback':
                return $this->handleCallback();
            case 'logout':
                return $this->handleLogout();
            case 'status':
                return $this->status();
            default:
                return $this->fail(404, 'unknown task');
        }
    }

    // ------------------------------------------------------------------
    // 1) Authorize redirect (PKCE + state)
    // ------------------------------------------------------------------
    private function startAuth(bool $signup)
    {
        $domain   = $this->domain();
        $clientId = trim((string) $this->params->get('client_id', ''));

        if ($domain === '' || $clientId === '') {
            return $this->fail(503, 'Auth0 not configured');
        }

        // Session cookies are host-only: start the flow on the canonical host so the
        // callback (also canonical) finds the same session/state.
        $current = Uri::getInstance();
        $canon   = Uri::getInstance($this->canonicalRoot());

        if (strcasecmp($current->getHost(), $canon->getHost()) !== 0) {
            $this->app->redirect($this->canonicalRoot() . ltrim($current->toString(['path', 'query']), '/'));

            return null;
        }

        $session  = $this->app->getSession();
        $state    = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $lang     = $this->lang();
        $return   = $this->safeReturn((string) $this->app->input->getString('return', ''), $lang);

        $session->set(self::SESSION_NS . '.state', $state);
        $session->set(self::SESSION_NS . '.verifier', $verifier);
        $session->set(self::SESSION_NS . '.return', $return);
        $session->set(self::SESSION_NS . '.lang', $lang);
        $session->set(self::SESSION_NS . '.started', time());

        $query = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $this->redirectUri(),
            'scope'                 => 'openid profile email',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'ui_locales'            => $lang,
        ];

        if ($signup) {
            $query['screen_hint'] = 'signup';
        }

        $conn = trim((string) $this->params->get('connection', ''));

        if ($conn !== '') {
            $query['connection'] = $conn;
        }

        $this->app->redirect('https://' . $domain . '/authorize?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return null;
    }

    // ------------------------------------------------------------------
    // 2) Callback: code -> tokens -> verify -> link user -> Joomla login
    // ------------------------------------------------------------------
    private function handleCallback()
    {
        $session = $this->app->getSession();
        $lang    = (string) ($session->get(self::SESSION_NS . '.lang') ?: $this->lang());
        $input   = $this->app->input;

        $error = (string) $input->getString('error', '');

        if ($error !== '') {
            $this->log('auth0-error', $error . ' ' . $input->getString('error_description', ''));
            $this->clearTransient();

            return $this->failRedirect($lang, 'PLG_SYSTEM_NUMISTRAUTH_ERR_AUTH0');
        }

        $code  = (string) $input->getString('code', '');
        $state = (string) $input->getString('state', '');
        $saved = (string) $session->get(self::SESSION_NS . '.state', '');
        $verifier = (string) $session->get(self::SESSION_NS . '.verifier', '');
        $started  = (int) $session->get(self::SESSION_NS . '.started', 0);
        $return   = (string) $session->get(self::SESSION_NS . '.return', '');

        $this->clearTransient();

        if ($code === '' || $state === '' || $saved === '' || !hash_equals($saved, $state) || $verifier === '' || (time() - $started) > 900) {
            $this->log('state-mismatch', 'state/verifier missing or expired');

            return $this->failRedirect($lang, 'PLG_SYSTEM_NUMISTRAUTH_ERR_STATE');
        }

        $tokens = $this->exchangeCode($code, $verifier);

        if ($tokens === null || empty($tokens['id_token'])) {
            return $this->failRedirect($lang, 'PLG_SYSTEM_NUMISTRAUTH_ERR_TOKEN');
        }

        $claims = $this->verifyIdToken((string) $tokens['id_token']);

        if ($claims === null) {
            return $this->failRedirect($lang, 'PLG_SYSTEM_NUMISTRAUTH_ERR_TOKEN');
        }

        $linker = $this->linker();
        $userId = $linker->resolveUserId($claims, (bool) $this->params->get('require_email_verified', 1));

        if ($userId <= 0) {
            $this->log('link-failed', $linker->getLastError());

            return $this->failRedirect($lang, $linker->getLastError() === 'email_unverified'
                ? 'PLG_SYSTEM_NUMISTRAUTH_ERR_UNVERIFIED'
                : 'PLG_SYSTEM_NUMISTRAUTH_ERR_LINK');
        }

        $user = Factory::getUser($userId);

        if ($user->id <= 0 || (int) $user->block === 1) {
            return $this->failRedirect($lang, 'PLG_SYSTEM_NUMISTRAUTH_ERR_BLOCKED');
        }

        // One-time nonce consumed by plg_authentication_numistrauth
        $nonce = bin2hex(random_bytes(24));
        $session->set(self::SESSION_NS . '.sso_nonce', $nonce);
        $session->set(self::SESSION_NS . '.sso_user', (int) $user->id);
        $session->set(self::SESSION_NS . '.sso_time', time());

        $ok = $this->app->login(
            ['username' => $user->username, 'password' => '', 'numistrauth_nonce' => $nonce],
            ['silent' => true, 'remember' => (bool) $this->params->get('remember_me', 1), 'action' => 'core.login.site']
        );

        $session->clear(self::SESSION_NS . '.sso_nonce');
        $session->clear(self::SESSION_NS . '.sso_user');
        $session->clear(self::SESSION_NS . '.sso_time');

        if ($ok !== true) {
            $this->log('joomla-login-failed', 'app->login returned false for user ' . $user->id);

            return $this->failRedirect($lang, 'PLG_SYSTEM_NUMISTRAUTH_ERR_LOGIN');
        }

        $this->app->redirect($return !== '' ? $return : $this->defaultReturn($lang));

        return null;
    }

    // ------------------------------------------------------------------
    // 3) Logout (Joomla + Auth0 session)
    // ------------------------------------------------------------------
    private function handleLogout()
    {
        $lang   = $this->lang();
        $return = $this->safeReturn((string) $this->app->input->getString('return', ''), $lang);
        $this->app->logout();

        $domain   = $this->domain();
        $clientId = trim((string) $this->params->get('client_id', ''));
        // safeReturn() already yields an absolute same-host URL; only the fallback needs the root prefix
        $target   = $return !== '' ? $return : $this->canonicalRoot() . ltrim($this->homeFor($lang), '/');

        if ($domain !== '' && $clientId !== '' && (bool) $this->params->get('auth0_logout', 1)) {
            $this->app->redirect('https://' . $domain . '/v2/logout?' . http_build_query(['client_id' => $clientId, 'returnTo' => $target], '', '&', PHP_QUERY_RFC3986));
        } else {
            $this->app->redirect($target);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 4) Status (JSON, for page scripts)
    // ------------------------------------------------------------------
    private function status()
    {
        $user = Factory::getUser();
        $pro  = false;

        if ($user->id > 0) {
            $proGroup = (int) $this->params->get('pro_group_id', 10);
            $pro      = in_array($proGroup, array_map('intval', (array) $user->getAuthorisedGroups()), true);
        }

        $this->app->setHeader('Cache-Control', 'private, no-store', true);

        return [
            'logged_in' => $user->id > 0,
            'name'      => $user->id > 0 ? $user->name : null,
            'email'     => $user->id > 0 ? $user->email : null,
            'pro'       => $pro,
        ];
    }

    // ------------------------------------------------------------------
    // Auth0 HTTP
    // ------------------------------------------------------------------
    private function exchangeCode(string $code, string $verifier): ?array
    {
        $domain = $this->domain();
        $body   = http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => trim((string) $this->params->get('client_id', '')),
            'client_secret' => (string) $this->params->get('client_secret', ''),
            'code'          => $code,
            'code_verifier' => $verifier,
            'redirect_uri'  => $this->redirectUri(),
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init('https://' . $domain . '/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $http !== 200) {
            $this->log('token-exchange', 'http=' . $http . ' ' . ($err ?: substr((string) $raw, 0, 300)));

            return null;
        }

        $data = json_decode((string) $raw, true);

        return is_array($data) ? $data : null;
    }

    private function verifyIdToken(string $jwt): ?array
    {
        $jwtHelper = JPATH_PLUGINS . '/webservices/numistr/helpers/JwtHelper.php';

        if (!class_exists('NumisTRJwtVerifier')) {
            if (!is_file($jwtHelper)) {
                $this->log('jwt-helper-missing', $jwtHelper);

                return null;
            }

            require_once $jwtHelper;
        }

        $verifier = new NumisTRJwtVerifier([
            'issuers'      => ['https://' . $this->domain() . '/'],
            'audiences'    => [trim((string) $this->params->get('client_id', ''))],
            'algorithms'   => ['RS256'],
            'mode'         => 'enforce',
            'leeway'       => 60,
            'jwks_ttl'     => 43200,
            'jwks_timeout' => 8,
        ]);

        $claims = $verifier->verify($jwt);

        if ($claims === null) {
            $this->log('jwt-verify', $verifier->getLastError());
        }

        return $claims;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    private function linker(): NumistrAuthUserLinker
    {
        require_once __DIR__ . '/src/UserLinker.php';

        return new NumistrAuthUserLinker(Factory::getDbo(), function (string $b, string $m) { $this->log($b, $m); });
    }

    private function domain(): string
    {
        $d = strtolower(trim((string) $this->params->get('auth0_domain', '')));
        $d = preg_replace('#^https?://#', '', $d);

        return rtrim((string) $d, '/');
    }

    /**
     * Canonical site root for Auth0 redirect/return URLs. The site answers on both
     * numistr.org and www.numistr.org; Auth0 requires an exact match, so the host is
     * pinned to the configured canonical root (param) instead of the request host.
     */
    private function canonicalRoot(): string
    {
        $root = trim((string) $this->params->get('canonical_root', ''));

        if ($root === '' || !preg_match('#^https://[a-z0-9.-]+(/.*)?$#i', $root)) {
            $root = Uri::root();
        }

        return rtrim($root, '/') . '/';
    }

    private function redirectUri(): string
    {
        return $this->canonicalRoot() . 'index.php?option=com_ajax&plugin=numistrauth&format=raw&task=callback';
    }

    private function lang(): string
    {
        $tag = strtolower((string) Factory::getLanguage()->getTag());

        if (strpos($tag, 'en') === 0) {
            return 'en';
        }

        if (strpos($tag, 'tr') === 0) {
            return 'tr';
        }

        $p = (string) $this->app->input->getCmd('lang', '');

        return $p === 'en' ? 'en' : 'tr';
    }

    private function homeFor(string $lang): string
    {
        return $lang === 'en' ? '/en/' : '/tr/';
    }

    private function defaultReturn(string $lang): string
    {
        $key = $lang === 'en' ? 'return_en' : 'return_tr';
        $v   = trim((string) $this->params->get($key, ''));

        return $v !== '' ? $this->canonicalRoot() . ltrim($v, '/') : $this->canonicalRoot() . ltrim($this->homeFor($lang), '/');
    }

    /** Only same-host relative paths are accepted (open-redirect guard). */
    private function safeReturn(string $return, string $lang): string
    {
        $return = trim($return);

        if ($return === '' || strlen($return) > 500) {
            return '';
        }

        if ($return[0] === '/' && (strlen($return) === 1 || $return[1] !== '/')) {
            if (preg_match('#^/[A-Za-z0-9_\-./?=&%+]*$#', $return)) {
                return $this->canonicalRoot() . ltrim($return, '/');
            }

            return '';
        }

        $host = parse_url($return, PHP_URL_HOST);
        $site = Uri::getInstance()->getHost();

        if ($host !== null && strtolower($host) === strtolower($site) && strpos($return, 'https://') === 0) {
            return $return;
        }

        return '';
    }

    private function clearTransient(): void
    {
        $s = $this->app->getSession();

        foreach (['state', 'verifier', 'return', 'lang', 'started'] as $k) {
            $s->clear(self::SESSION_NS . '.' . $k);
        }
    }

    private function failRedirect(string $lang, string $langKey)
    {
        $this->app->enqueueMessage(Factory::getLanguage()->_($langKey), 'error');
        $page = trim((string) $this->params->get($lang === 'en' ? 'error_en' : 'error_tr', ''));
        $this->app->redirect($this->canonicalRoot() . ltrim($page !== '' ? $page : $this->homeFor($lang), '/'));

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
            \Joomla\CMS\Log\Log::addLogger(['text_file' => 'numistrauth.php'], \Joomla\CMS\Log\Log::ALL, ['numistrauth']);
            \Joomla\CMS\Log\Log::add('[' . $branch . '] ' . $message, \Joomla\CMS\Log\Log::INFO, 'numistrauth');
        } catch (\Throwable $e) {
            // never break the auth flow because of logging
        }
    }
}
