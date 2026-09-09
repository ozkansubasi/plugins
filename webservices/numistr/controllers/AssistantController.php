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
 * AI Assistant controller (ADR-003, Phase 1: anonymous "Genel" assistant)
 *
 * Endpoints:
 *   POST /v1/assistant/chat                {message, conversation_id?, lang?}
 *   GET  /v1/assistant/conversations/{id}  last 30 messages (owner only)
 *   GET  /v1/assistant/health              {ok, models, kb_hash}
 *
 * Pipeline (per ADR Mimari):
 *   identity -> ban -> quota -> pre-filter -> keyword FAQ -> system breaker
 *   -> classify (Gemini flash-lite, regex fallback) -> route
 *      site        : Gemini flash + core KB (explicit cache)
 *      coin_search : Claude haiku + tools[search_coins,get_variant]
 *      settlement  : Claude haiku + tools[search_settlements,get_settlement]
 *      explain     : search_kb -> Gemini flash summary
 *      other       : polite refusal (+abuse score)
 *
 * @since 1.6.0
 */
class AssistantController
{
    const ROUTES = ['site', 'coin_search', 'settlement', 'explain', 'other'];

    const COOKIE_NAME = 'nt_aid';

    /** @var array */
    private static $config;

    /** @var array */
    private static $constants;

    /** @var array */
    private static $secrets;

    // ======================================================================
    // Bootstrap
    // ======================================================================

    private static function boot(): void
    {
        if (self::$config !== null) {
            return;
        }

        $base = dirname(__DIR__);

        self::$constants = file_exists($base . '/config/constants.php') ? include $base . '/config/constants.php' : [];
        self::$config    = file_exists($base . '/config/assistant.php') ? include $base . '/config/assistant.php' : [];
        self::$secrets   = file_exists($base . '/config/secrets.php') ? include $base . '/config/secrets.php' : [];

        if (!is_array(self::$secrets)) {
            self::$secrets = [];
        }
    }

    private static function log(string $branch, string $message): void
    {
        try {
            $logger = Factory::getContainer()->get('logger');
            $logger->info('[NumisTR-Assistant] branch="' . $branch . '" msg="' . $message . '"');
        } catch (\Throwable $e) {
            // no-op
        }
    }

    private static function msg(string $lang, string $key): string
    {
        return (string) (self::$config['messages'][$lang][$key] ?? self::$config['messages']['tr'][$key] ?? $key);
    }

    // ======================================================================
    // GET /v1/assistant/health
    // ======================================================================

    public static function health(): void
    {
        self::boot();
        $response = new NumisTRResponseHelper();
        $kb       = new NumisTRAssistantCoreKb();
        $tr       = $kb->build('tr');
        $en       = $kb->build('en');

        $diag = null;

        // ?diag=1 -> one tiny live Gemini call, returns only the error text (never the keys)
        if (isset($_GET['diag']) && (string) $_GET['diag'] === '1') {
            $llm   = new NumisTRLLMClient(self::$config, self::$secrets, null);
            $model = (string) (self::$config['models']['classify'] ?? 'gemini-2.5-flash-lite');

            // optional model probe: ?diag=1&model=gemini-x.y-flash (strict whitelist pattern)
            if (isset($_GET['model']) && preg_match('/^gemini-[0-9.]{1,5}-[a-z-]{1,20}$/', (string) $_GET['model'])) {
                $model = (string) $_GET['model'];
            }
            $g     = $llm->classify($model, 'Reply with exactly one word: ok', 'ping', ['ok']);
            $diag  = [
                'gemini' => ['ok' => (bool) $g['ok'], 'model' => $model, 'error' => mb_substr((string) ($g['error'] ?? ''), 0, 300)],
            ];

            // optional DB tool probe: &tool=search_coins|search_settlements&mint=..&region=..&q=..&metal=..
            $toolName = (string) ($_GET['tool'] ?? '');

            if (in_array($toolName, ['search_coins', 'search_settlements'], true)) {
                $params = [];

                foreach (['mint', 'region', 'q', 'metal', 'authority'] as $k) {
                    if (isset($_GET[$k]) && $_GET[$k] !== '') {
                        $params[$k] = mb_substr((string) $_GET[$k], 0, 60);
                    }
                }

                $params['limit'] = 3;
                $toolLang        = ($_GET['lang'] ?? 'tr') === 'en' ? 'en' : 'tr';

                try {
                    $tools = new NumisTRAssistantTools(self::$constants, self::$config, self::$secrets, Factory::getDbo());
                    $out   = $toolName === 'search_coins' ? $tools->searchCoins($params, $toolLang) : $tools->searchSettlements($params, $toolLang);
                    $diag['tool'] = ['name' => $toolName, 'params' => $params, 'ok' => !isset($out['error']), 'error' => $out['error'] ?? null,
                        'count' => isset($out['items']) ? count($out['items']) : null, 'first' => $out['items'][0] ?? null];
                } catch (\Throwable $e) {
                    $diag['tool'] = ['name' => $toolName, 'params' => $params, 'ok' => false, 'exception' => mb_substr($e->getMessage(), 0, 500)];
                }
            }
        }

        $response->sendJson([
            'diag'    => $diag,
            'ok'      => (bool) (self::$config['enabled'] ?? false) && $tr['exists'] && $en['exists'],
            'enabled' => (bool) (self::$config['enabled'] ?? false),
            'models'  => self::$config['models'] ?? [],
            'keys'    => [
                'gemini'    => trim((string) (self::$secrets['GEMINI_API_KEY'] ?? '')) !== '',
                'anthropic' => trim((string) (self::$secrets['ANTHROPIC_API_KEY'] ?? '')) !== '',
                'kb'        => trim((string) (self::$secrets['KB_WEBHOOK_SECRET'] ?? '')) !== '',
            ],
            'kb_hash' => ['tr' => $tr['hash'], 'en' => $en['hash']],
            'kb_tokens_est' => ['tr' => $tr['tokens_est'], 'en' => $en['tokens_est']],
            'version' => '1.6.0-phase1',
        ]);
    }

    // ======================================================================
    // GET /v1/assistant/export?type=blog|settlements&lang=tr|en&page=N&per_page=100[&since=YYYY-MM-DD]
    // Plain-text article feed for the RAG ingestion (n8n -> Qdrant numistr_site).
    // Protected with the shared KB secret (X-NumisTR-KB header).
    // ======================================================================

    public static function export(): void
    {
        self::boot();
        $response = new NumisTRResponseHelper();

        $secret = trim((string) (self::$secrets['KB_WEBHOOK_SECRET'] ?? ''));
        $given  = trim((string) ($_SERVER['HTTP_X_NUMISTR_KB'] ?? ''));

        if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
            $response->sendError(401, 'Unauthorized', 'X-NumisTR-KB header required');
            return;
        }

        $type    = (string) ($_GET['type'] ?? 'blog');
        $lang    = (($_GET['lang'] ?? 'tr') === 'en') ? 'en' : 'tr';
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($_GET['per_page'] ?? 100)));
        $since   = trim((string) ($_GET['since'] ?? ''));

        if (!in_array($type, ['blog', 'settlements'], true)) {
            $response->sendError(400, 'Bad Request', 'type must be blog|settlements');
            return;
        }

        if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $since)) {
            $since = '';
        }

        $db      = Factory::getDbo();
        $langTag = $lang === 'en' ? 'en-GB' : 'tr-TR';
        $cfg     = self::$config['export'] ?? [];
        $roots   = $type === 'blog'
            ? (array) ($cfg['blog_roots'][$lang] ?? ($lang === 'en' ? [106] : [8]))
            : (array) ($cfg['settlement_roots'][$lang] ?? ($lang === 'en' ? [71] : [70]));

        // category subtree via lft/rgt
        $catIds = [];

        foreach ($roots as $root) {
            $q = $db->getQuery(true)->select(['lft', 'rgt'])->from($db->quoteName('#__categories'))->where('id = ' . (int) $root);
            $db->setQuery($q);
            $r = $db->loadAssoc();

            if ($r) {
                $q = $db->getQuery(true)->select('id')->from($db->quoteName('#__categories'))
                    ->where('extension = ' . $db->quote('com_content'))
                    ->where('lft >= ' . (int) $r['lft'] . ' AND rgt <= ' . (int) $r['rgt'])
                    ->where('published = 1');
                $db->setQuery($q);
                $catIds = array_merge($catIds, array_map('intval', (array) $db->loadColumn()));
            }
        }

        $catIds = array_values(array_unique($catIds));

        if (empty($catIds)) {
            $response->sendJson(['data' => [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'type' => $type, 'lang' => $lang]]);
            return;
        }

        $in = implode(',', $catIds);
        $qc = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__content', 'c'))
            ->where('c.state = 1')->where('c.catid IN (' . $in . ')')
            ->where('c.language IN (' . $db->quote($langTag) . ',' . $db->quote('*') . ')');

        if ($since !== '') {
            $qc->where('c.modified >= ' . $db->quote($since));
        }

        $db->setQuery($qc);
        $total = (int) $db->loadResult();

        $q = $db->getQuery(true)
            ->select(['c.id', 'c.title', 'c.alias', 'c.catid', 'c.introtext', 'c.fulltext', 'c.modified', 'c.language', 'cat.alias AS cat_alias', 'cat.title AS cat_title'])
            ->from($db->quoteName('#__content', 'c'))
            ->join('INNER', $db->quoteName('#__categories', 'cat') . ' ON cat.id = c.catid')
            ->where('c.state = 1')->where('c.catid IN (' . $in . ')')
            ->where('c.language IN (' . $db->quote($langTag) . ',' . $db->quote('*') . ')')
            ->order('c.id ASC');

        if ($since !== '') {
            $q->where('c.modified >= ' . $db->quote($since));
        }

        $db->setQuery($q, ($page - 1) * $perPage, $perPage);
        $rows = $db->loadAssocList() ?: [];

        // category -> menu alias (public URL path), cached per request
        $menuAlias = [];
        $aliasFor  = static function (int $catid) use ($db, $langTag, &$menuAlias): string {
            if (isset($menuAlias[$catid])) {
                return $menuAlias[$catid];
            }

            $alias = '';

            try {
                $q = $db->getQuery(true)->select('alias')->from($db->quoteName('#__menu'))
                    ->where('published = 1')->where('client_id = 0')
                    ->where($db->quoteName('link') . ' LIKE ' . $db->quote('%option=com_content&view=category%'))
                    ->where('(' . $db->quoteName('link') . ' LIKE ' . $db->quote('%&id=' . $catid) . ' OR ' . $db->quoteName('link') . ' LIKE ' . $db->quote('%&id=' . $catid . '&%') . ')')
                    ->where($db->quoteName('language') . ' IN (' . $db->quote($langTag) . ',' . $db->quote('*') . ')')
                    ->order($db->quoteName('language') . ' DESC')->setLimit(1);
                $db->setQuery($q);
                $alias = (string) $db->loadResult();
            } catch (\Throwable $e) {
                $alias = '';
            }

            return $menuAlias[$catid] = $alias;
        };

        $base = rtrim((string) (self::$config['site_base'] ?? 'https://numistr.org'), '/');
        $out  = [];

        foreach ($rows as $r) {
            $menu = $aliasFor((int) $r['catid']);
            $path = $menu !== '' ? $menu : (string) $r['cat_alias'];
            $text = NumisTRAssistantTools::htmlToText((string) $r['introtext'] . "\n" . (string) $r['fulltext'], 60000);

            $out[] = [
                'id'       => (int) $r['id'],
                'type'     => $type,
                'lang'     => $lang,
                'title'    => (string) $r['title'],
                'category' => (string) $r['cat_title'],
                'url'      => $base . '/' . $lang . '/' . $path . '/' . (int) $r['id'] . '-' . $r['alias'],
                'modified' => (string) $r['modified'],
                'text'     => $text,
            ];
        }

        $response->sendJson(['data' => $out, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'type' => $type, 'lang' => $lang]]);
    }

    // ======================================================================
    // GET /v1/assistant/conversations/{id}
    // ======================================================================

    public static function conversation(int $id): void
    {
        self::boot();
        $response = new NumisTRResponseHelper();
        $method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'OPTIONS') {
            $response->sendJson(['ok' => true]);
            return;
        }

        if ($method !== 'GET') {
            $response->sendError(405, 'Method Not Allowed', 'Use GET');
            return;
        }

        try {
            $db       = Factory::getDbo();
            $identity = self::resolveIdentity();

            $q = $db->getQuery(true)
                ->select(['id', 'user_id', 'anon_key', 'lang', 'title', 'created', 'last_at'])
                ->from($db->quoteName('#__numistr_assistant_conversation'))
                ->where($db->quoteName('id') . ' = ' . (int) $id);
            $db->setQuery($q);
            $conv = $db->loadAssoc();

            if (!$conv || !self::ownsConversation($conv, $identity)) {
                $response->sendError(404, 'Not found');
                return;
            }

            $q = $db->getQuery(true)
                ->select(['id', 'role', 'content', 'route', 'created'])
                ->from($db->quoteName('#__numistr_assistant_message'))
                ->where($db->quoteName('conversation_id') . ' = ' . (int) $id)
                ->order($db->quoteName('id') . ' DESC')
                ->setLimit(30);
            $db->setQuery($q);
            $rows = array_reverse($db->loadAssocList() ?: []);

            $response->sendJson([
                'conversation_id' => (int) $conv['id'],
                'lang'            => $conv['lang'],
                'title'           => $conv['title'],
                'created'         => $conv['created'],
                'messages'        => array_map(function ($r) {
                    return [
                        'id'      => (int) $r['id'],
                        'role'    => $r['role'],
                        'content' => $r['content'],
                        'route'   => $r['route'],
                        'created' => $r['created'],
                    ];
                }, $rows),
            ], true);
        } catch (\Throwable $e) {
            self::log('conversation-error', $e->getMessage());
            $response->sendError(500, 'Internal server error');
        }
    }

    /**
     * GET  /v1/assistant/conversations       — kimlige ait konusma listesi
     * (kopru: task=assistant.conversations)
     *
     * Anonim kullanici yalnizca kendi anon_key'ine bagli konusmalari gorur;
     * giris yapinca eski anonim konusmalari uyeye devralindigi icin
     * (loadOrCreateConversation) listede kalmaya devam eder.
     */
    public static function conversations(): void
    {
        self::boot();
        $response = new NumisTRResponseHelper();
        $method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'OPTIONS') {
            $response->sendJson(['ok' => true]);
            return;
        }

        if ($method !== 'GET') {
            $response->sendError(405, 'Method Not Allowed', 'Use GET');
            return;
        }

        try {
            $db       = Factory::getDbo();
            $identity = self::resolveIdentity();

            $where = [];

            if ($identity['user_id'] !== null) {
                $where[] = $db->quoteName('user_id') . ' = ' . (int) $identity['user_id'];
            }

            if ($identity['anon_key'] !== null && $identity['anon_key'] !== '') {
                $where[] = $db->quoteName('anon_key') . ' = ' . $db->quote($identity['anon_key']);
            }

            if (!$where) {
                $response->sendJson(['conversations' => [], 'identity' => $identity['type']], true);
                return;
            }

            $db->setQuery(
                'SELECT id, lang, title, created, last_at FROM '
                . $db->quoteName('#__numistr_assistant_conversation')
                . ' WHERE (' . implode(' OR ', $where) . ')'
                . ' AND ' . $db->quoteName('archived') . ' = 0'
                . ' ORDER BY ' . $db->quoteName('last_at') . ' DESC',
                0,
                20
            );

            $rows = $db->loadAssocList() ?: [];

            $response->sendJson([
                'identity'      => $identity['type'],
                'conversations' => array_map(static function ($r) {
                    return [
                        'id'      => (int) $r['id'],
                        'lang'    => $r['lang'],
                        'title'   => $r['title'],
                        'created' => $r['created'],
                        'last_at' => $r['last_at'],
                    ];
                }, $rows),
            ], true);
        } catch (\Throwable $e) {
            self::log('conversations-error', $e->getMessage());
            $response->sendError(500, 'Internal server error');
        }
    }

    /**
     * Konusmayi arsivle (KVKK: kullanici kendi gecmisini kaldirabilmeli).
     * Kopru: task=assistant.conversation.delete&id=N (POST).
     *
     * Satirlar SILINMEZ, archived=1 yapilir: maliyet/kota muhasebesi ve kotuye
     * kullanim incelemesi icin kayit korunur, kullaniciya gorunmez.
     */
    public static function archiveConversation(int $id): void
    {
        self::boot();
        $response = new NumisTRResponseHelper();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $response->sendError(405, 'Method Not Allowed', 'Use POST');
            return;
        }

        try {
            $db       = Factory::getDbo();
            $identity = self::resolveIdentity();

            $db->setQuery(
                $db->getQuery(true)
                    ->select(['id', 'user_id', 'anon_key'])
                    ->from($db->quoteName('#__numistr_assistant_conversation'))
                    ->where($db->quoteName('id') . ' = ' . (int) $id)
            );
            $conv = $db->loadAssoc();

            if (!$conv || !self::ownsConversation($conv, $identity)) {
                $response->sendError(404, 'Not found');
                return;
            }

            $db->setQuery(
                'UPDATE ' . $db->quoteName('#__numistr_assistant_conversation')
                . ' SET ' . $db->quoteName('archived') . ' = 1'
                . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) $id
            )->execute();

            $response->sendJson(['ok' => true, 'conversation_id' => (int) $id], true);
        } catch (\Throwable $e) {
            self::log('conversation-archive-error', $e->getMessage());
            $response->sendError(500, 'Internal server error');
        }
    }

    /**
     * POST /v1/assistant/recognize   (kopru: task=assistant.recognize)
     * ADR-003 Faz 2b parca 8 — "once yukle, sonra arac".
     *
     * LLM araclari ikili veri alamaz. Bu yuzden gorsel once buraya yuklenir;
     * sonuc konusmaya arac mesaji olarak yazilir ve kullanici "birincisini anlat"
     * dediginde LLM normal akista get_variant ile devam eder (eslesme id'leri
     * konusma baglaminda durur, ikinci yukleme gerekmez).
     *
     * Tanima kotasi asistan mesaj kotasindan AYRIDIR: uygulamadaki aylik tarama
     * havuzu (QuotaHelper) kullanilir — tek havuz karari (2026-08-28).
     * Anonim tanima yoktur; uyelik sarttir (ayni karar).
     */
    public static function recognize(): void
    {
        self::boot();
        $response = new NumisTRResponseHelper();
        $method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'OPTIONS') {
            $response->sendJson(['ok' => true]);
            return;
        }

        if ($method !== 'POST') {
            $response->sendError(405, 'Method Not Allowed', 'Use POST with multipart/form-data (image)');
            return;
        }

        if (empty(self::$config['enabled'])) {
            $response->sendError(503, 'Service Unavailable', self::msg('tr', 'disabled'));
            return;
        }

        $lang     = self::detectLang($_POST['lang'] ?? null);
        $identity = self::resolveIdentity();

        if ($identity['user_id'] === null) {
            $response->sendJson([
                'ok'       => false,
                'reason'   => 'auth_required',
                'identity' => 'anon',
                'answer'   => self::msg($lang, 'recognize_login'),
                'cta'      => self::authCta($lang),
            ], true);
            return;
        }

        if (!class_exists('RecognitionController')) {
            $response->sendError(503, 'Service Unavailable', 'Recognition not deployed');
            return;
        }

        $user = Factory::getUser((int) $identity['user_id']);

        if (!$user || (int) $user->id <= 0) {
            $response->sendError(401, 'Unauthorized', 'User not found');
            return;
        }

        try {
            $result = RecognitionController::runForUser(
                $user,
                $_FILES['image'] ?? null,
                $_FILES['reverse'] ?? null,
                [],
                self::$constants
            );
        } catch (\Throwable $e) {
            self::log('assistant-recognize', $e->getMessage());
            $response->sendError(500, 'Internal server error', self::msg($lang, 'llm_error'));
            return;
        }

        if (empty($result['ok'])) {
            $code = (string) ($result['error']['code'] ?? 'ERROR');

            $response->sendJson([
                'ok'         => false,
                'reason'     => $code === 'QUOTA_EXCEEDED' ? 'scan_quota' : ($code === 'RATE_LIMITED' ? 'rate_limit' : 'error'),
                'identity'   => $identity['type'],
                'answer'     => $code === 'QUOTA_EXCEEDED'
                    ? self::msg($lang, 'recognize_quota')
                    : ($code === 'RATE_LIMITED'
                        ? self::msg($lang, 'recognize_rate')
                        : (string) ($result['error']['message'] ?? '')),
                'scan_quota' => $result['quota'] ?? null,
            ], true);
            return;
        }

        $matches = self::enrichMatches((array) $result['data'], $identity['type'], $lang);
        $summary = self::recognitionSummary($matches, $lang);

        // Sonucu konusmaya yaz: kullanici sonraki turda "birincisini anlat" diyebilsin.
        $convId = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : 0;

        try {
            $db           = Factory::getDbo();
            $conversation = self::loadOrCreateConversation($db, $convId, $identity, $lang, $summary['title']);
            $convId       = (int) $conversation['id'];

            self::insertMessage($db, $convId, 'user', $summary['user_note'], 'recognize', null, 0, 0, 0.0, false);
            self::insertMessage($db, $convId, 'assistant', $summary['text'], 'recognize', null, 0, 0, 0.0, false);

            $db->setQuery('UPDATE ' . $db->quoteName('#__numistr_assistant_conversation') . ' SET last_at = NOW() WHERE id = ' . $convId)->execute();
        } catch (\Throwable $e) {
            self::log('assistant-recognize-persist', $e->getMessage());
            $convId = 0;
        }

        $response->sendJson([
            'ok'              => true,
            'identity'        => $identity['type'],
            'conversation_id' => $convId ?: null,
            'answer'          => $summary['text'],
            'matches'         => $matches,
            'scan_quota'      => $result['quota'],
            'request_id'      => $result['request_id'],
        ], true);
    }

    /**
     * AI servisinin dondurdugu eslesmeleri site verisiyle zenginlestirir.
     * Baslik/URL/bolge/metal alanlari get_variant ile ayni yerden gelir, boylece
     * kart ile sohbetin geri kalani ayni bicimi kullanir (URL uretimi tek yerde).
     *
     * Kademe: uye ilk 3, Pro ilk 10 eslesme (ADR-003).
     */
    private static function enrichMatches(array $data, string $identityType, string $lang): array
    {
        $limit = $identityType === 'pro' ? 10 : 3;
        $raw   = array_slice((array) ($data['matches'] ?? []), 0, $limit);

        if (!$raw) {
            return [];
        }

        $tools = null;

        try {
            $tools = new NumisTRAssistantTools(self::$constants, self::$config, self::$secrets, Factory::getDbo());
        } catch (\Throwable $e) {
            self::log('assistant-recognize-tools', $e->getMessage());
        }

        $out = [];

        foreach ($raw as $m) {
            $id   = isset($m['article_id']) ? (int) $m['article_id'] : 0;
            $conf = isset($m['confidence']) ? round((float) $m['confidence'], 3) : null;
            $row  = null;

            if ($tools !== null && $id > 0) {
                try {
                    $row = $tools->getVariant($id, $lang);
                } catch (\Throwable $e) {
                    $row = null;
                }
            }

            if (is_array($row) && empty($row['error'])) {
                $row['confidence'] = $conf;
                $out[] = $row;
                continue;
            }

            // Site kaydi bulunamadi (indeks makaleden once guncellenmis olabilir):
            // AI servisinin verdigi ham bilgiyle yetin, URL uydurma.
            $out[] = [
                'article_id' => $id ?: null,
                'title'      => (string) ($m['title'] ?? ''),
                'region'     => $m['region'] ?? null,
                'confidence' => $conf,
                'url'        => '',
            ];
        }

        return $out;
    }

    /**
     * Tanima sonucunu konusma metnine cevirir. Toplam sayi verilmez
     * ("sonsuzluk algisi" ilkesi) — yalnizca eslesmeler ve guven skoru.
     *
     * @return array{title:string,user_note:string,text:string}
     */
    /**
     * Bolge kodu -> okunabilir ad. Bilinmeyen kod temizlenip oldugu gibi dondurulur
     * (uydurma yapilmaz, kod da gizlenmez).
     */
    private static function regionLabel(?string $code, string $lang): string
    {
        $code = strtolower(trim((string) $code));

        if ($code === '') {
            return '';
        }

        $key = preg_replace('/-coins$/', '', $code);

        $tr = [
            'lydia' => 'Lidya', 'ionia' => 'İyonya', 'caria' => 'Karya', 'lycia' => 'Likya',
            'phrygia' => 'Frigya', 'mysia' => 'Misya', 'bithynia' => 'Bitinya',
            'pamphylia' => 'Pamfilya', 'cilicia' => 'Kilikya', 'clicia' => 'Kilikya',
            'cappadocia' => 'Kapadokya', 'galatia' => 'Galatya', 'pisidia' => 'Pisidya',
            'troas' => 'Troas', 'paphlagonia' => 'Paflagonya', 'aeolis' => 'Aiolis',
            'pontus' => 'Pontus', 'other' => 'Diğer Bölgeler',
        ];

        if ($lang !== 'en' && isset($tr[$key])) {
            return $tr[$key];
        }

        return ucwords(str_replace('-', ' ', $key));
    }

    /**
     * Metal anahtari -> okunabilir ad. Anahtar taninmazsa oldugu gibi dondurulur.
     */
    private static function metalLabel(?string $key, string $lang): string
    {
        $key = strtolower(trim((string) $key));

        if ($key === '') {
            return '';
        }

        $tr = [
            'silver' => 'gümüş', 'gold' => 'altın', 'bronze' => 'bronz',
            'electrum' => 'elektrum', 'lead' => 'kurşun', 'iron' => 'demir',
            'copper' => 'bakır', 'billon' => 'billon', 'potin' => 'potin',
        ];

        return ($lang !== 'en' && isset($tr[$key])) ? $tr[$key] : $key;
    }

    /**
     * Tarih araligi etiketi. Negatif yil = MO. Yalnizca dolu degerlerden kurulur.
     */
    private static function dateRangeLabel($from, $to, string $lang): string
    {
        $from = ($from === null || $from === '') ? null : (int) $from;
        $to   = ($to === null || $to === '') ? null : (int) $to;

        if ($from === null && $to === null) {
            return '';
        }

        if ($from === null) {
            $from = $to;
        }

        if ($to === null) {
            $to = $from;
        }

        $isEn = $lang === 'en';

        $one = static function (int $y) use ($isEn): string {
            return $y < 0
                ? ($isEn ? abs($y) . ' BC' : 'MÖ ' . abs($y))
                : ($isEn ? 'AD ' . $y : 'MS ' . $y);
        };

        if ($from === $to) {
            return $one($from);
        }

        // Ayni cagdaysa donem bir kez yazilir: "MO 400-370" / "400-370 BC"
        if (($from < 0) === ($to < 0)) {
            $a = abs($from);
            $b = abs($to);

            if ($from < 0) {
                return $isEn ? ($a . '–' . $b . ' BC') : ('MÖ ' . $a . '–' . $b);
            }

            return $isEn ? ('AD ' . $a . '–' . $b) : ('MS ' . $a . '–' . $b);
        }

        return $one($from) . ' – ' . $one($to);
    }

    /**
     * Tanima sonucunu konusma metnine cevirir.
     *
     * 2026-09-08: onceki hali yalnizca baslik + guven skoru basan bir sablondu.
     * `enrichMatches()` her eslesme icin `getVariant()` cagirip on/arka yuz tasvirini,
     * darphaneyi, otoriteyi ve birimi cekiyordu -- hepsi atiliyordu.
     *
     * GROUNDING: metin tamamen DB alanlarindan kurulur, LLM CAGRILMAZ. Bos alan icin
     * cumle KURULMAZ: tahmin edilmez, "muhtemelen" denmez. Boylece uydurma yapisal
     * olarak imkansizdir ve her taramaya ek gecikme/maliyet binmez. Derin yorum,
     * kullanicinin takip sorusuyla LLM + arac rotasinda yapilir.
     *
     * Kademe farki yukarida `enrichMatches()` tarafindan uygulanir (ucretsiz 3, Pro 10);
     * bu metod eline gecen kadarini bicimlendirir. Kademe farki KAC eslesme gorundugudur,
     * hangi olgunun gizlendigi degil -- olgu saklanmaz.
     *
     * Toplam sayi verilmez ("sonsuzluk algisi" ilkesi).
     *
     * @return array{title:string,user_note:string,text:string}
     */
    private static function recognitionSummary(array $matches, string $lang): array
    {
        $isEn      = $lang === 'en';
        $userNote  = $isEn ? '[photo uploaded]' : '[fotoğraf yüklendi]';
        $fallTitle = $isEn ? 'Coin recognition' : 'Sikke tanıma';

        if (!$matches) {
            return [
                'title'     => $fallTitle,
                'user_note' => $userNote,
                'text'      => $isEn
                    ? 'I could not match this photo to a coin in the database. A sharp photo on a plain background, with the coin filling the frame, usually helps -- and adding the other side improves accuracy.'
                    : 'Bu fotoğrafı veritabanındaki bir sikkeyle eşleştiremedim. Düz zeminde, kadrajı dolduran net bir fotoğraf genellikle yardımcı olur; diğer yüzü de eklemek doğruluğu artırır.',
            ];
        }

        $conf = static function ($c) use ($isEn): string {
            if ($c === null || $c === '') {
                return '';
            }

            $pct = (int) round(((float) $c) * 100);

            return $isEn ? ' (' . $pct . '% similarity)' : ' (%' . $pct . ' benzerlik)';
        };

        $first = $matches[0];
        $out   = [];

        $out[] = $isEn ? 'Closest match for your photo:' : 'Fotoğrafınıza en yakın eşleşme:';
        $out[] = '';

        $title = !empty($first['title']) ? (string) $first['title'] : ('#' . (int) ($first['article_id'] ?? 0));
        $out[] = '**' . $title . '**' . $conf($first['confidence'] ?? null);

        // Kunye satiri: yalnizca DOLU alanlar birlestirilir
        $meta   = [];
        $region = self::regionLabel($first['region'] ?? null, $lang);

        if ($region !== '') {
            $meta[] = $isEn ? $region : $region . ' bölgesi';
        }

        if (!empty($first['mint'])) {
            $mint   = ucwords((string) $first['mint']);
            $meta[] = $isEn ? $mint . ' mint' : $mint . ' darphanesi';
        }

        $dates = self::dateRangeLabel($first['date_from'] ?? null, $first['date_to'] ?? null, $lang);

        if ($dates !== '') {
            $meta[] = $dates;
        }

        $metal = self::metalLabel($first['metal'] ?? null, $lang);

        if ($metal !== '') {
            $meta[] = $metal;
        }

        if (!empty($first['denomination'])) {
            $meta[] = (string) $first['denomination'];
        }

        if ($meta) {
            $out[] = implode(' · ', $meta);
        }

        if (!empty($first['authority'])) {
            $out[] = ($isEn ? 'Authority: ' : 'Otorite: ') . (string) $first['authority'];
        }

        if (!empty($first['obverse'])) {
            $out[] = ($isEn ? 'Obverse: ' : 'Ön yüz: ') . (string) $first['obverse'];
        }

        if (!empty($first['reverse'])) {
            $out[] = ($isEn ? 'Reverse: ' : 'Arka yüz: ') . (string) $first['reverse'];
        }

        if (!empty($first['weight'])) {
            $out[] = ($isEn ? 'Weight: ' : 'Ağırlık: ') . (string) $first['weight'];
        }

        if (!empty($first['diameter'])) {
            $out[] = ($isEn ? 'Diameter: ' : 'Çap: ') . (string) $first['diameter'];
        }

        if (!empty($first['url'])) {
            $out[] = (string) $first['url'];
        }

        // Kalan eslesmeler: her biri tek satir
        $rest = array_slice($matches, 1);

        if ($rest) {
            $out[] = '';
            $out[] = $isEn ? 'Other close matches:' : 'Diğer yakın eşleşmeler:';

            foreach ($rest as $i => $m) {
                $t    = !empty($m['title']) ? (string) $m['title'] : ('#' . (int) ($m['article_id'] ?? 0));
                $bits = [];
                $r    = self::regionLabel($m['region'] ?? null, $lang);

                if ($r !== '') {
                    $bits[] = $r;
                }

                $d = self::dateRangeLabel($m['date_from'] ?? null, $m['date_to'] ?? null, $lang);

                if ($d !== '') {
                    $bits[] = $d;
                }

                $tail  = $bits ? ' — ' . implode(' · ', $bits) : '';
                $out[] = ($i + 2) . '. ' . $t . $conf($m['confidence'] ?? null) . $tail;
            }
        }

        // Dusuk benzerlikte kesinlik iddia edilmez
        $topConf = isset($first['confidence']) ? (float) $first['confidence'] : 1.0;

        if ($topConf > 0 && $topConf < 0.6) {
            $out[] = '';
            $out[] = $isEn
                ? 'Similarity is low, so treat this as a lead rather than an attribution — adding the other side, or a sharper photo, usually helps.'
                : 'Benzerlik düşük; bunu kesin teşhis değil bir ipucu olarak değerlendirin — diğer yüzü eklemek ya da daha net bir fotoğraf genellikle yardımcı olur.';
        }

        $out[] = '';
        $out[] = $isEn
            ? 'Ask me about any of them (for example "tell me more about the second one") and I will go deeper.'
            : 'İstediğiniz eşleşmeyi sorabilirsiniz (örneğin "ikincisini anlat"), daha ayrıntılı anlatayım.';

        return [
            'title'     => mb_substr($title, 0, 120),
            'user_note' => $userNote,
            'text'      => implode("\n", $out),
        ];
    }

    private static function ownsConversation(array $conv, array $identity): bool
    {
        if ($identity['user_id'] !== null && (int) $conv['user_id'] === (int) $identity['user_id']) {
            return true;
        }

        return $identity['anon_key'] !== null && (string) $conv['anon_key'] === $identity['anon_key'];
    }

    // ======================================================================
    // POST /v1/assistant/chat
    // ======================================================================

    public static function chat(): void
    {
        self::boot();
        $response = new NumisTRResponseHelper();
        $method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'OPTIONS') {
            $response->sendJson(['ok' => true]);
            return;
        }

        if ($method !== 'POST') {
            $response->sendError(405, 'Method Not Allowed', 'Use POST with JSON {message, conversation_id?, lang?}');
            return;
        }

        if (empty(self::$config['enabled'])) {
            $response->sendError(503, 'Service Unavailable', self::msg('tr', 'disabled'));
            return;
        }

        $raw     = (string) file_get_contents('php://input');
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            // allow form-encoded fallback
            $payload = ['message' => $_POST['message'] ?? '', 'conversation_id' => $_POST['conversation_id'] ?? null, 'lang' => $_POST['lang'] ?? null];
        }

        $message = trim((string) ($payload['message'] ?? ''));
        $convId  = isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : 0;
        $lang    = self::detectLang($payload['lang'] ?? null);

        try {
            $result = self::handle($message, $convId, $lang);
        } catch (\Throwable $e) {
            self::log('chat-fatal', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            $response->sendError(500, 'Internal server error', self::msg($lang, 'llm_error'));
            return;
        }

        // chat answers are per-user: never cached (no-store)
        $response->sendJson($result, true);
    }

    /**
     * Full pipeline; returns the response array.
     */
    private static function handle(string $message, int $convId, string $lang): array
    {
        $db       = Factory::getDbo();
        $identity = self::resolveIdentity();
        $sType    = $identity['type'];
        $sKey     = $identity['key'];

        $quota = new NumisTRAssistantQuota(self::$config, $db);
        $abuse = new NumisTRAssistantAbuse(self::$config, $db);
        $limits = $quota->limitsFor($sType);

        // 1. ban
        $ban = $abuse->check($sKey);

        if ($ban['banned']) {
            return self::staticReply($lang, $identity, 'ban', self::msg($lang, $ban['message_key']), $quota);
        }

        // 2. quota + rate limit (every check is actually evaluated - OSGBpro lesson)
        $qc = $quota->check($sType, $sKey);

        if (!$qc['allowed']) {
            if ($qc['reason'] === 'daily') {
                $reply = self::msg($lang, 'quota');
                $route = 'quota';
            } else {
                $abuse->record($sType, $sKey, 'rate_limit');
                $reply = self::msg($lang, 'rate_limit');
                $route = 'rate_limit';
            }

            $out = self::staticReply($lang, $identity, $route, $reply, $quota);
            $out['quota']['remaining_today'] = $qc['remaining'];

            return $out;
        }

        // 3. pre-filter
        $pf = self::preFilter($message, self::$config);

        if (!$pf['ok']) {
            if ($pf['abuse_event'] !== '') {
                $abuse->record($sType, $sKey, $pf['abuse_event']);
            }

            return self::staticReply($lang, $identity, 'blocked', self::msg($lang, $pf['message_key']), $quota);
        }

        // conversation row (created lazily here so blocked messages above cost nothing)
        $conversation = self::loadOrCreateConversation($db, $convId, $identity, $lang, $message);
        $convId       = (int) $conversation['id'];

        // history BEFORE this turn is stored
        $history = self::history($db, $convId, (int) $limits['history_turns']);

        // user message row first so that tool_log rows can reference it
        $userMsgId = 0;

        try {
            $userMsgId = self::insertMessage($db, $convId, 'user', $message, null, null, 0, 0, 0.0, false);
        } catch (\Throwable $e) {
            self::log('persist-user', $e->getMessage());
        }

        // 4. keyword FAQ (LLM-free)
        $kw = self::keywordMatch($message, self::$config['keyword_map'] ?? [], $lang);

        if ($kw !== null) {
            $abuse->record($sType, $sKey, 'normal');

            return self::persistAndRespond($db, $convId, $identity, $lang, [
                'text' => $kw, 'route' => 'keyword', 'model' => 'keyword_match',
                'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'cache_hit' => false, 'sources' => [], 'cta' => false,
            ], $quota);
        }

        // 5. system circuit breaker
        $sc = $quota->systemCheck();

        if (!$sc['allowed']) {
            self::log('system-breaker', $sc['reason']);

            return self::persistAndRespond($db, $convId, $identity, $lang, [
                'text' => self::msg($lang, 'system_quota'), 'route' => 'system_quota', 'model' => null,
                'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0, 'cache_hit' => false, 'sources' => [], 'cta' => false,
            ], $quota);
        }

        // 6. classify
        $settings = new NumisTRAssistantSettings($db);
        $llm      = new NumisTRLLMClient(self::$config, self::$secrets, $settings);
        $costs    = self::$config['costs'] ?? [];
        $models   = self::$config['models'] ?? [];

        $cls       = self::classify($llm, $message, $lang);
        $route     = $cls['route'];
        $tokensIn  = $cls['tokens_in'];
        $tokensOut = $cls['tokens_out'];
        $cost      = NumisTRLLMClient::cost($costs, (string) ($models['classify'] ?? ''), $cls['tokens_in'], $cls['tokens_out']);

        // 7. route
        $rules   = (string) (self::$config['prompts'][$lang]['rules'] ?? '');
        $coreKb  = new NumisTRAssistantCoreKb();
        $cta     = ($sType === 'anon');

        switch ($route) {
            case 'other':
                $abuse->record($sType, $sKey, 'other_route');

                return self::persistAndRespond($db, $convId, $identity, $lang, [
                    'text' => self::msg($lang, 'other'), 'route' => 'other', 'model' => (string) ($models['classify'] ?? ''),
                    'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'cost' => $cost, 'cache_hit' => false, 'sources' => [], 'cta' => false,
                ], $quota);

            case 'coin_search':
            case 'settlement':
                $res = self::routeTools($llm, $db, $userMsgId, $route, $message, $history, $lang, $rules, $limits);
                break;

            case 'explain':
                $res = self::routeExplain($llm, $db, $userMsgId, $message, $history, $lang, $rules, $limits, $coreKb);
                break;

            case 'site':
            default:
                $res = self::routeSite($llm, $message, $history, $lang, $rules, $limits, $coreKb);
                break;
        }

        $abuse->record($sType, $sKey, 'normal');

        $res['route']      = $route;
        $res['tokens_in']  = $tokensIn + (int) $res['tokens_in'];
        $res['tokens_out'] = $tokensOut + (int) $res['tokens_out'];
        $res['cost']       = $cost + (float) $res['cost'];
        $res['cta']        = $cta && !empty($res['cta']);

        if (trim((string) $res['text']) === '') {
            $res['text'] = self::msg($lang, 'llm_error');
        }

        // Applies to every route: the model invents plausible category URLs when it
        // wants somewhere to point, and they 404. Only tool-returned URLs and the
        // verified landing pages survive. See dropUnknownSiteLinks().
        // The curated core KB is the site's own link list (about, faq, map, region
        // coin pages...). Those are vouched for, so they belong in the allowed set:
        // without them the site route would lose its own legitimate links. Checked
        // 2026-09-09: 27 of the 28 URLs in that file return 200, the odd one out
        // being the English glossary alias, which glossaryUrl() no longer emits.
        $allowed = array_merge(
            array_column((array) ($res['sources'] ?? []), 'url'),
            (array) (self::$config['landing_urls'][$lang] ?? []),
            self::siteUrlsIn($coreKb->build($lang)['text'] ?? '')
        );
        $res['text'] = self::dropUnknownSiteLinks((string) $res['text'], $allowed);

        return self::persistAndRespond($db, $convId, $identity, $lang, $res, $quota);
    }

    // ======================================================================
    // Routes
    // ======================================================================

    /**
     * Remove links to our own site that nothing vouched for.
     *
     * Measured 2026-09-09: asked about a mint that does not exist, the assistant
     * answered honestly and then offered /tr/yerlesimleri and /tr/sikkeler as places
     * to look. Both are 404. So is /tr/antik-yerlesimleri, which it produced on the
     * settlement route - the real alias is /tr/antik-yerlesimler, one letter apart.
     * A confident answer ending in a dead link is worse than a vague one, and rule 4
     * ("URLs only exactly as returned by tools") had already told it not to.
     *
     * The prompt is not where this gets enforced. Two prompt-level fixes failed on
     * this same class of problem today (1.9.2, 0 of 5), so the check lives in code.
     *
     * Only numistr.org links are policed - those are the ones we can vouch for -
     * and a markdown link keeps its text, so the sentence still reads.
     */
    public static function dropUnknownSiteLinks(string $answer, array $allowedUrls): string
    {
        if ($answer === '') {
            return $answer;
        }

        $allowed = [];

        foreach ($allowedUrls as $u) {
            $n = self::normaliseSiteUrl((string) $u);

            if ($n !== '') {
                $allowed[$n] = true;
            }
        }

        $ours = '~https?://(?:www\.)?numistr\.org[^\s\)\]<>"]*~i';

        // Markdown links first, so the label survives when the target does not.
        $answer = preg_replace_callback(
            '~\[([^\]]*)\]\((' . 'https?://(?:www\.)?numistr\.org[^\s\)]*' . ')\)~i',
            static function (array $m) use ($allowed): string {
                return isset($allowed[self::normaliseSiteUrl($m[2])]) ? $m[0] : $m[1];
            },
            $answer
        ) ?? $answer;

        // Then bare URLs.
        $answer = preg_replace_callback(
            $ours,
            static function (array $m) use ($allowed): string {
                return isset($allowed[self::normaliseSiteUrl($m[0])]) ? $m[0] : '';
            },
            $answer
        ) ?? $answer;

        // Tidy what removal left behind: dangling "(): " fragments and double spaces.
        $answer = preg_replace('~\(\s*\)~u', '', $answer) ?? $answer;
        $answer = preg_replace('~[ \t]{2,}~u', ' ', $answer) ?? $answer;
        $answer = preg_replace('~[ \t]+([,.;:])~u', '$1', $answer) ?? $answer;

        return trim($answer);
    }

    /** Concrete numistr.org URLs written in a block of text (templates ignored). */
    public static function siteUrlsIn(string $text): array
    {
        if ($text === '' || !preg_match_all('~https?://(?:www\.)?numistr\.org[^\s\)\]<>"]*~i', $text, $m)) {
            return [];
        }

        $out = [];

        foreach ($m[0] as $u) {
            // core-kb documents URL SHAPES too ("/{region}-coins/{id}-{title}");
            // those are not addresses and must not widen the allowed set.
            if (mb_strpos($u, '{') !== false) {
                continue;
            }

            $out[] = rtrim($u, '.,;:');
        }

        return array_values(array_unique($out));
    }

    /** Compare our URLs without tripping over www, trailing slash or punctuation. */
    public static function normaliseSiteUrl(string $url): string
    {
        $url = trim($url);
        $url = rtrim($url, ".,;:!?)]\"'");
        $url = preg_replace('~^https?://~i', '', $url) ?? $url;
        $url = preg_replace('~^www\.~i', '', $url) ?? $url;

        return rtrim(mb_strtolower($url, 'UTF-8'), '/');
    }

    /**
     * Keep only the pre-fetched sources the answer actually talks about.
     *
     * Context handed to the model up front is not evidence for whatever it ends up
     * saying. A "no record of this place" answer must not carry four settlement
     * links that look like they support it - that is the citation half of the
     * fabrication problem closed on 2026-09-06.
     *
     * A title counts only as a whole word, so "Zara" does not match inside
     * "Zarkanopolis"; the URL counts because answers often paste it inline.
     *
     * $exemptUrls survive unconditionally. The glossary page is the case: search_kb
     * attributes terminology to it as a category rather than as a claim, so a correct
     * answer never names it and a mention test would always throw it away.
     */
    public static function sourcesSupportedByAnswer(array $sources, string $answer, array $exemptUrls = []): array
    {
        if (!$sources) {
            return [];
        }

        $exempt = array_flip($exemptUrls);
        $kept   = [];

        foreach ($sources as $url => $src) {
            if (isset($exempt[$url])) {
                $kept[$url] = $src;
                continue;
            }

            if ($answer === '') {
                continue;
            }

            $title = (string) ($src['title'] ?? '');

            if ($url !== '' && mb_strpos($answer, (string) $url) !== false) {
                $kept[$url] = $src;
                continue;
            }

            if ($title === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($title, '/') . '(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $answer) === 1) {
                $kept[$url] = $src;
            }
        }

        return $kept;
    }

    /**
     * Public glossary page. Terminology chunks are attributed here, never to the
     * private Google Doc they were ingested from.
     *
     * Returns '' for English on purpose. /en/numizmatik-karsiliklar is a 404 -
     * there is no English glossary page (the site's own English menu links to the
     * same dead alias, so this predates the assistant). Every English terminology
     * answer was citing it. Attributing a source to a page that does not exist is
     * worse than not attributing one, so callers skip an empty URL; restore the
     * entry here once the page is published.
     */
    private static function glossaryUrl(string $lang): string
    {
        if ($lang !== 'tr') {
            return '';
        }

        return (string) (self::$config['site_base'] ?? 'https://numistr.org')
            . '/tr/numizmatik-karsiliklar';
    }

    private static function routeSite($llm, string $message, array $history, string $lang, string $rules, array $limits, NumisTRAssistantCoreKb $coreKb): array
    {
        $model  = (string) (self::$config['models']['site'] ?? 'gemini-2.5-flash');
        $system = $coreKb->systemPrompt($lang, $rules);

        $r = $llm->geminiGenerate($model, $system, $history, $message, [
            'max_output' => (int) $limits['max_output'],
            'cache_key'  => 'site_' . $lang,
        ]);

        if (!$r['ok']) {
            self::log('site-llm', $r['error']);
        }

        return [
            'text'       => $r['text'],
            'model'      => $model,
            'tokens_in'  => $r['tokens_in'],
            'tokens_out' => $r['tokens_out'],
            'cost'       => NumisTRLLMClient::cost(self::$config['costs'] ?? [], $model, $r['tokens_in'], $r['tokens_out'], $r['cached_tokens'] ?? 0),
            'cache_hit'  => $r['cache_hit'],
            'sources'    => [],
            'cta'        => true,
        ];
    }

    private static function routeTools($llm, $db, int $userMsgId, string $route, string $message, array $history, string $lang, string $rules, array $limits): array
    {
        $model = (string) (self::$config['models']['tools'] ?? 'claude-haiku-4-5');
        // search_kb is on BOTH lists on purpose. The classifier reliably sends
        // "patina nedir" to explain, but reads denomination names as coin types:
        // "stater nedir" / "tetradrahmi nedir" land here instead (2026-09-06). Without
        // terminology access the model filled the gap from its own memory, which is the
        // same failure the explain route was just fixed for. Sharpening the classifier
        // lowers the frequency; this makes a mis-route harmless.
        $names = $route === 'settlement'
            ? ['search_settlements', 'get_settlement', 'search_site', 'search_kb']
            : ['search_coins', 'get_variant', 'search_site', 'search_kb'];

        $tools = new NumisTRAssistantTools(self::$constants, self::$config, self::$secrets, $db);
        $tools->setMessageId($userMsgId > 0 ? $userMsgId : null);
        $defs  = NumisTRAssistantTools::definitions($names);

        $system = $rules . "\n\n" . (string) (self::$config['prompts'][$lang]['tools_hint'] ?? '')
            . "\n" . ($lang === 'en' ? 'Today: ' : 'Bugun: ') . date('Y-m-d');

        $sources    = [];
        $preSources = [];

        // The settlement route used to work by accident. search_kb returned settlement
        // chunks too, so a model that reached for the wrong tool still found something.
        // Scoping search_kb to terminology (1.9.1) removed that accident and exposed the
        // real behaviour: asked "Zara nerede", the model asks which region instead of
        // searching - 0 of 5 attempts answered, and one of them volunteered "Zarai" from
        // its own memory. Instructing it to search first did not move the number (1.9.2,
        // still 0 of 5), so the content is fetched here rather than left to the model's
        // discretion, the way routeExplain already does it.
        //
        // search_site, not search_settlements: the latter matches a NAME with LIKE, so it
        // would need the place name pulled out of the sentence first, and getting that
        // wrong fails silently. Semantic search takes the sentence as written - "Zara
        // nerede" returns the Zara article at 0.609 - and carries the public URL with it.
        // Extended to the coin route on 2026-09-09 for the same reason. Asked
        // "Tarsus darphanesinde basilan sikkeler", the model wrote a confident essay
        // about Pharnabazos, Datames and Alexander's mint - from its own memory, with
        // no source behind a word of it. Meanwhile the site carries "Kilikya Gecidi,
        // Tarsus Darphanesi'nin Stratejik Onemi" and "Pers Satraplarinin Guc Gosterisi:
        // Tarsus Stateri", which cover exactly that ground and were never fetched.
        // search_coins answers "which coins", not "tell me about them"; the narrative
        // lives in the articles, so the articles have to be in front of the model.
        $preType = $route === 'settlement' ? 'settlements' : null;

        if (in_array($route, ['settlement', 'coin_search'], true)) {
            $pre = $tools->execute(
                'search_site',
                ['query' => $message, 'lang' => $lang, 'type' => $preType, 'limit' => 4],
                $lang
            );

            if (isset($pre['error'])) {
                self::log('settlement-presearch', (string) $pre['error']);
            }

            $preItems = (!isset($pre['error']) && !empty($pre['items'])) ? $pre['items'] : [];

            if ($preItems) {
                $lines = [];

                foreach ($preItems as $it) {
                    $lines[] = '- ' . $it['title'] . ' (' . $it['url'] . ")\n" . $it['text'];

                    if (!empty($it['url']) && !empty($it['title'])) {
                        // Deliberately NOT $sources: see sourcesSupportedByAnswer().
                        $preSources[$it['url']] = ['title' => (string) $it['title'], 'url' => (string) $it['url']];
                    }
                }

                $system .= "\n\n" . ($lang === 'en'
                    ? 'SITE ARTICLES already retrieved for this question. Base any historical or '
                        . 'descriptive claim on these and cite their URLs. Do not write background from '
                        . 'your own knowledge, and do not ask the user to narrow the question down when '
                        . 'one of these already answers it.'
                    : 'Bu soru icin ONCEDEN getirilmis SITE MAKALELERI. Tarihsel ya da betimleyici her '
                        . 'iddiani bunlara dayandir ve URL adreslerini kaynak goster. Kendi bilginden '
                        . 'arka plan YAZMA; bunlardan biri soruyu zaten yanitliyorsa kullaniciya soruyu '
                        . 'daraltmasini SOYLEME.')
                    . "\n" . implode("\n\n", $lines);
            }
        }

        $executor = function (string $name, array $input) use ($tools, $lang, &$sources) {
            $result = $tools->execute($name, $input, $lang);

            $items = isset($result['items']) ? $result['items'] : (isset($result['url']) ? [$result] : []);

            foreach ($items as $it) {
                if (!empty($it['url']) && !empty($it['title'])) {
                    $sources[$it['url']] = ['title' => (string) $it['title'], 'url' => (string) $it['url']];
                }
            }

            // Terminology chunks carry no public url of their own (see searchKb),
            // so attribute them to the glossary page once.
            if ($name === 'search_kb' && !empty($items) && self::glossaryUrl($lang) !== '') {
                $gUrl = self::glossaryUrl($lang);
                $sources[$gUrl] = [
                    'title' => $lang === 'en' ? 'Numismatic terms' : 'Numizmatik terimler',
                    'url'   => $gUrl,
                ];
            }

            return $result;
        };

        $r = $llm->claudeToolLoop($model, $system, $history, $message, $defs, $executor, [
            'max_output'     => (int) $limits['max_output'] + 100,
            'max_iterations' => (int) (self::$config['tools']['max_iterations'] ?? 3),
            'max_tool_calls' => (int) $limits['max_tool_calls'],
        ]);

        if (!$r['ok']) {
            self::log('tools-llm', $r['error']);
        }

        // Retrieved settlement articles are context, not automatically evidence.
        // Asked about a place that does not exist ("Zarkanopolis nerede"), the model
        // correctly answered that it found nothing - but the nearest real settlements
        // were still attached as sources, reading as if they backed that answer.
        //
        // 1.9.4 filtered only the pre-fetched ones and did NOT fix it: the model also
        // calls a search tool (the prompt tells it to), search_settlements finds no
        // such name, search_site returns the nearest places instead, and those were
        // registered as sources through the executor. A tool call is not evidence
        // either - what decides is whether the answer actually talks about the place.
        //
        // The glossary page is exempt: search_kb attributes terminology to it as a
        // category, not as a claim, so the answer never names it.
        // Applies to the coin route as well: asked about a mint that does not exist,
        // search_coins finds nothing, the model correctly says so - and 3 to 4 coin
        // pages were still listed underneath as if they backed it (measured
        // 2026-09-09 on 'Zarkania darphanesinde basilan sikkeler').
        $sources = self::sourcesSupportedByAnswer(
            $sources + $preSources,
            (string) $r['text'],
            array_filter([self::glossaryUrl($lang)])
        );

        return [
            'text'       => $r['text'],
            'model'      => $model,
            'tokens_in'  => $r['tokens_in'],
            'tokens_out' => $r['tokens_out'],
            'cost'       => NumisTRLLMClient::cost(self::$config['costs'] ?? [], $model, $r['tokens_in'], $r['tokens_out']),
            'cache_hit'  => $r['cache_hit'],
            'sources'    => array_values(array_slice($sources, 0, 10)),
            'cta'        => true,
        ];
    }

    private static function routeExplain($llm, $db, int $userMsgId, string $message, array $history, string $lang, string $rules, array $limits, NumisTRAssistantCoreKb $coreKb): array
    {
        $model = (string) (self::$config['models']['explain'] ?? 'gemini-2.5-flash');
        $tools = new NumisTRAssistantTools(self::$constants, self::$config, self::$secrets, $db);
        $tools->setMessageId($userMsgId > 0 ? $userMsgId : null);

        $kb   = $tools->execute('search_kb', ['query' => $message, 'lang' => $lang], $lang);
        $site = $tools->execute('search_site', ['query' => $message, 'lang' => $lang, 'limit' => 4], $lang);

        $kbItems   = (!isset($kb['error']) && !empty($kb['items'])) ? $kb['items'] : [];
        $siteItems = (!isset($site['error']) && !empty($site['items'])) ? $site['items'] : [];

        if (isset($kb['error'])) {
            self::log('explain-kb', (string) $kb['error']);
        }

        if (isset($site['error'])) {
            self::log('explain-site', (string) $site['error']);
        }

        if (empty($kbItems) && empty($siteItems)) {
            // Nothing retrieved above the relevance gate -> fall back to the curated
            // core KB (site route). That file is hand-written, so the answer stays
            // grounded; what we must never do here is let the model free-run.
            $res = self::routeSite($llm, $message, $history, $lang, $rules, $limits, $coreKb);
            $res['model'] = $model;

            return $res;
        }

        // One numbered list across both stores, so a [n] citation is unambiguous.
        $lines   = [];
        $sources = [];
        $n       = 0;

        // Terminology chunks come from Google Docs; their content_url is the private
        // source document, so cite the public glossary page instead (once).
        $glossaryUrl   = self::glossaryUrl($lang);
        $glossaryTitle = $lang === 'en' ? 'Numismatic terms' : 'Numizmatik terimler';

        foreach ($kbItems as $it) {
            $n++;
            $label   = $lang === 'en' ? 'terminology' : 'terminoloji';
            $lines[] = '[' . $n . '] ' . $it['title'] . ' (' . $label . ")\n" . $it['text'];
        }

        if (!empty($kbItems) && $glossaryUrl !== '') {
            $sources[] = ['title' => $glossaryTitle, 'url' => $glossaryUrl];
        }

        foreach ($siteItems as $it) {
            $n++;
            $lines[]   = '[' . $n . '] ' . $it['title'] . ' (' . $it['url'] . ")\n" . $it['text'];
            $sources[] = ['title' => $it['title'], 'url' => $it['url']];
        }

        $system = $rules . "\n\n" . (string) (self::$config['prompts'][$lang]['explain_hint'] ?? '')
            . "\n\n" . ($lang === 'en' ? 'CONTEXT:' : 'BAGLAM:') . "\n" . implode("\n\n", $lines);

        $r = $llm->geminiGenerate($model, $system, $history, $message, [
            'max_output' => (int) $limits['max_output'],
        ]);

        if (!$r['ok']) {
            self::log('explain-llm', $r['error']);
            $first      = $kbItems ? $kbItems[0] : $siteItems[0];
            $r['text']  = (string) ($first['text'] ?? '');
        }

        return [
            'text'       => $r['text'],
            'model'      => $model,
            'tokens_in'  => $r['tokens_in'],
            'tokens_out' => $r['tokens_out'],
            'cost'       => NumisTRLLMClient::cost(self::$config['costs'] ?? [], $model, $r['tokens_in'], $r['tokens_out']),
            'cache_hit'  => $r['cache_hit'],
            'sources'    => array_values(array_slice($sources, 0, 10)),
            'cta'        => false,
        ];
    }

    // ======================================================================
    // Classification
    // ======================================================================

    private static function classify($llm, string $message, string $lang): array
    {
        $model  = (string) (self::$config['models']['classify'] ?? 'gemini-2.5-flash-lite');
        $system = "Classify the user's message for the NumisTR assistant (ancient Anatolian coins website). "
            . "Reply with EXACTLY ONE WORD from: site, coin_search, settlement, explain, other.\n"
            . "- site: questions about the NumisTR website, membership, Pro, prices, app, scanning quota, contact, data usage, how to use the site, what NumisTR is.\n"
            . "- coin_search: wants to FIND or LIST actual coins by region, metal, date, mint, ruler or type (e.g. 'silver coins of Caria 4th century BC', 'Ephesus tetradrachms', 'coins of Croesus').\n"
            . "  DISAMBIGUATION: a bare 'X nedir' / 'what is X' / 'X ne demek' with no region, date, metal, mint or ruler is a DEFINITION request -> explain, even when X is a denomination (stater, tetradrahmi, obol, drahmi). Choose coin_search only when the user wants to see coins.\n"
            . "- settlement: asks about an ancient city/site/settlement: where it is, its history, whether it minted coins (e.g. 'Aphrodisias nerede', 'tell me about Sardes').\n"
            . "- explain: asks the meaning/definition of a numismatic term or concept (e.g. 'what is a stater', 'obverse ne demek', 'kontrmark nedir'), OR a history/culture/iconography/'why' question about Anatolian coins, rulers, symbols, regions, hoards or collecting that NumisTR articles can answer (e.g. 'Kyzikos sikkelerinde neden balik var', 'who was Croesus', 'what is patina').\n"
            . "- other: greetings only, chit-chat, coin valuation/price requests, politics, coding, anything unrelated.\n"
            . "Examples:\n"
            . "\"Pro uyelik ne kadar?\" -> site\n"
            . "\"Karya bolgesi gumus sikkeler MO 400\" -> coin_search\n"
            . "\"Show me bronze coins from Pergamon\" -> coin_search\n"
            . "\"Aphrodisias hangi bolgede?\" -> settlement\n"
            . "\"Where is Xanthos\" -> settlement\n"
            . "\"Tetradrahmi nedir?\" -> explain\n"
            . "\"Stater nedir?\" -> explain\n"
            . "\"Obol ne demek\" -> explain\n"
            . "\"Efes tetradrahmileri\" -> coin_search\n"
            . "\"Karya staterlerini goster\" -> coin_search\n"
            . "\"What does incuse mean\" -> explain\n"
            . "\"Kyzikos neden sikkelerine balik koydu?\" -> explain\n"
            . "\"Why did Lydians use lions on coins\" -> explain\n"
            . "\"Sikkem kac para eder?\" -> other\n"
            . "\"Merhaba nasilsin\" -> other\n"
            . "\"Uygulamayi nereden indiririm?\" -> site";

        $r = $llm->classify($model, $system, $message, self::ROUTES);

        $route = $r['label'];

        if ($route === null) {
            $route = self::classifyFallback($message, self::$config['classify_fallback'] ?? []);
            self::log('classify-fallback', $route . ' (' . $r['error'] . ')');
        }

        return ['route' => $route, 'tokens_in' => (int) $r['tokens_in'], 'tokens_out' => (int) $r['tokens_out']];
    }

    /**
     * Regex fallback when the classifier is unavailable. Pure/testable.
     */
    public static function classifyFallback(string $message, array $patterns): string
    {
        foreach (['coin_search', 'settlement', 'explain', 'site'] as $route) {
            $re = $patterns[$route] ?? null;

            if ($re !== null && @preg_match($re, $message)) {
                return $route;
            }
        }

        // unknown but non-empty question: give the site route a chance rather than refusing
        return (mb_strlen($message, 'UTF-8') >= 12 && strpos($message, '?') !== false) ? 'site' : 'other';
    }

    // ======================================================================
    // Pre-filter / keyword match (pure, testable)
    // ======================================================================

    /**
     * @return array ['ok'=>bool,'reason'=>''|'empty'|'too_long'|'noise'|'char_spam'|'blacklist','message_key'=>string,'abuse_event'=>string]
     */
    public static function preFilter(string $message, array $config): array
    {
        $cfg = $config['prefilter'] ?? [];
        $len = mb_strlen($message, 'UTF-8');

        if ($len === 0) {
            return ['ok' => false, 'reason' => 'empty', 'message_key' => 'empty', 'abuse_event' => ''];
        }

        if ($len > (int) ($cfg['max_length'] ?? 1500)) {
            return ['ok' => false, 'reason' => 'too_long', 'message_key' => 'too_long', 'abuse_event' => 'long_input'];
        }

        if (!preg_match('/[\p{L}\p{N}]/u', $message)) {
            return ['ok' => false, 'reason' => 'noise', 'message_key' => 'empty', 'abuse_event' => ''];
        }

        $spamRe = (string) ($cfg['char_spam_regex'] ?? '/(.)\1{9,}/u');

        if (@preg_match($spamRe, $message)) {
            return ['ok' => false, 'reason' => 'char_spam', 'message_key' => 'blocked', 'abuse_event' => 'char_spam'];
        }

        $lower = mb_strtolower($message, 'UTF-8');

        foreach ((array) ($cfg['blacklist'] ?? []) as $word) {
            $word = mb_strtolower(trim((string) $word), 'UTF-8');

            if ($word !== '' && mb_strpos($lower, $word, 0, 'UTF-8') !== false) {
                return ['ok' => false, 'reason' => 'blacklist', 'message_key' => 'blocked', 'abuse_event' => 'blacklist'];
            }
        }

        return ['ok' => true, 'reason' => '', 'message_key' => '', 'abuse_event' => ''];
    }

    /**
     * Keyword FAQ. Matches on an ASCII-folded lower-case copy so that
     * "Pro üyelik" matches key "pro uyelik".
     */
    public static function keywordMatch(string $message, array $map, string $lang): ?string
    {
        if (empty($map)) {
            return null;
        }

        $hay = self::fold($message);

        foreach ($map as $key => $answers) {
            $k = self::fold((string) $key);

            if ($k !== '' && strpos($hay, $k) !== false) {
                $a = $answers[$lang] ?? $answers['tr'] ?? null;

                return is_string($a) && $a !== '' ? $a : null;
            }
        }

        return null;
    }

    /** lower-case + Turkish diacritics folded to ASCII */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');

        return strtr($s, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    }

    public static function detectLang($param): string
    {
        $p = strtolower(trim((string) $param));

        if (in_array($p, ['tr', 'en'], true)) {
            return $p;
        }

        try {
            $tag = (string) Factory::getApplication()->getLanguage()->getTag();

            if (stripos($tag, 'en') === 0) {
                return 'en';
            }
        } catch (\Throwable $e) {
            // fall through
        }

        $al = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));

        if ($al !== '' && strpos($al, 'tr') === false && strpos($al, 'en') === 0) {
            return 'en';
        }

        return 'tr';
    }

    // ======================================================================
    // Identity
    // ======================================================================

    /**
     * @return array ['type'=>'anon'|'user'|'pro','key'=>string,'user_id'=>?int,'anon_key'=>?string]
     */
    private static function resolveIdentity(): array
    {
        $user = null;

        // a) Bearer token (Auth0 JWT / Joomla API token) via existing AuthHelper
        try {
            if (class_exists('NumisTRAuthHelper') && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth = new NumisTRAuthHelper(self::$constants);
                $user = $auth->authenticateUser();

                if ($user && !$user->guest && $user->id > 0) {
                    $isPro = $auth->hasProSubscription($user);

                    return ['type' => $isPro ? 'pro' : 'user', 'key' => (string) $user->id, 'user_id' => (int) $user->id, 'anon_key' => self::anonKey()];
                }
            }
        } catch (\Throwable $e) {
            self::log('identity-bearer', $e->getMessage());
        }

        // b) Joomla session identity (only when the API app shares the site session)
        try {
            $identity = Factory::getApplication()->getIdentity();

            if ($identity && !$identity->guest && $identity->id > 0) {
                $isPro = class_exists('NumisTRAuthHelper') ? (new NumisTRAuthHelper(self::$constants))->hasProSubscription($identity) : false;

                return ['type' => $isPro ? 'pro' : 'user', 'key' => (string) $identity->id, 'user_id' => (int) $identity->id, 'anon_key' => self::anonKey()];
            }
        } catch (\Throwable $e) {
            // no session in API context - fine
        }

        // c) anonymous: cookie + IP + UA hash
        $key = self::anonKey();

        return ['type' => 'anon', 'key' => $key, 'user_id' => null, 'anon_key' => $key];
    }

    /** @var string|null */
    private static $anonKeyCache = null;

    private static function anonKey(): string
    {
        if (self::$anonKeyCache !== null) {
            return self::$anonKeyCache;
        }

        $cookie = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');

        if (!preg_match('/^[a-f0-9]{32}$/', $cookie)) {
            $cookie = bin2hex(random_bytes(16));

            if (!headers_sent()) {
                setcookie(self::COOKIE_NAME, $cookie, [
                    'expires'  => time() + 365 * 86400,
                    'path'     => '/',
                    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            $_COOKIE[self::COOKIE_NAME] = $cookie;
        }

        self::$anonKeyCache = self::computeAnonKey(self::clientIp(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $cookie);

        return self::$anonKeyCache;
    }

    public static function computeAnonKey(string $ip, string $ua, string $cookie): string
    {
        return hash('sha256', $ip . '|' . $ua . '|' . $cookie);
    }

    private static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = (string) $_SERVER[$h];

                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                return $ip;
            }
        }

        return '0.0.0.0';
    }

    // ======================================================================
    // Persistence
    // ======================================================================

    private static function loadOrCreateConversation($db, int $convId, array $identity, string $lang, string $firstMessage): array
    {
        if ($convId > 0) {
            $q = $db->getQuery(true)
                ->select(['id', 'user_id', 'anon_key', 'lang'])
                ->from($db->quoteName('#__numistr_assistant_conversation'))
                ->where($db->quoteName('id') . ' = ' . (int) $convId)
                ->where($db->quoteName('archived') . ' = 0');
            $db->setQuery($q);
            $conv = $db->loadAssoc();

            if ($conv && self::ownsConversation($conv, $identity)) {
                // anonymous history becomes the user's when they log in mid-conversation
                if ($identity['user_id'] !== null && empty($conv['user_id'])) {
                    $db->setQuery('UPDATE ' . $db->quoteName('#__numistr_assistant_conversation')
                        . ' SET user_id = ' . (int) $identity['user_id'] . ', subject_type = ' . $db->quote($identity['type'])
                        . ' WHERE id = ' . (int) $convId)->execute();
                }

                return $conv;
            }
        }

        $title = mb_substr(preg_replace('/\s+/u', ' ', $firstMessage), 0, 120, 'UTF-8');
        $sql   = 'INSERT INTO ' . $db->quoteName('#__numistr_assistant_conversation')
            . ' (user_id, anon_key, subject_type, lang, title) VALUES ('
            . ($identity['user_id'] !== null ? (int) $identity['user_id'] : 'NULL') . ', '
            . ($identity['anon_key'] !== null ? $db->quote($identity['anon_key']) : 'NULL') . ', '
            . $db->quote($identity['type']) . ', ' . $db->quote($lang) . ', ' . $db->quote($title) . ')';
        $db->setQuery($sql)->execute();

        return ['id' => (int) $db->insertid(), 'user_id' => $identity['user_id'], 'anon_key' => $identity['anon_key'], 'lang' => $lang];
    }

    private static function insertMessage($db, int $convId, string $role, string $content, ?string $route, ?string $model, int $tin, int $tout, float $cost, bool $cacheHit): int
    {
        $sql = 'INSERT INTO ' . $db->quoteName('#__numistr_assistant_message')
            . ' (conversation_id, role, content, route, model, tokens_in, tokens_out, cost_usd, cache_hit) VALUES ('
            . (int) $convId . ', ' . $db->quote($role) . ', ' . $db->quote($content) . ', '
            . ($route !== null ? $db->quote($route) : 'NULL') . ', '
            . ($model !== null ? $db->quote($model) : 'NULL') . ', '
            . (int) $tin . ', ' . (int) $tout . ', ' . sprintf('%.6F', $cost) . ', ' . ($cacheHit ? 1 : 0) . ')';
        $db->setQuery($sql)->execute();

        return (int) $db->insertid();
    }

    /**
     * Last N turns as [['role','text'], ...] oldest first.
     */
    private static function history($db, int $convId, int $turns): array
    {
        try {
            $q = $db->getQuery(true)
                ->select(['role', 'content'])
                ->from($db->quoteName('#__numistr_assistant_message'))
                ->where($db->quoteName('conversation_id') . ' = ' . (int) $convId)
                ->where($db->quoteName('role') . ' IN (' . $db->quote('user') . ', ' . $db->quote('assistant') . ')')
                ->order($db->quoteName('id') . ' DESC')
                ->setLimit(max(0, $turns) * 2);
            $db->setQuery($q);
            $rows = array_reverse($db->loadAssocList() ?: []);
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(function ($r) {
            return ['role' => $r['role'], 'text' => mb_substr((string) $r['content'], 0, 2000, 'UTF-8')];
        }, $rows);
    }

    /**
     * Store the assistant turn (the user turn was stored in handle()), update
     * quota and build the response payload.
     */
    private static function persistAndRespond($db, int $convId, array $identity, string $lang, array $res, NumisTRAssistantQuota $quota): array
    {
        $tin   = (int) ($res['tokens_in'] ?? 0);
        $tout  = (int) ($res['tokens_out'] ?? 0);
        $cost  = (float) ($res['cost'] ?? 0);
        $route = (string) ($res['route'] ?? 'site');
        $model = isset($res['model']) && $res['model'] !== null && $res['model'] !== '' ? (string) $res['model'] : null;
        $text  = (string) ($res['text'] ?? '');

        if (!empty($res['cta']) && $identity['type'] === 'anon') {
            $text = rtrim($text) . "\n\n" . self::msg($lang, 'cta_register');
        }

        try {
            self::insertMessage($db, $convId, 'assistant', $text, $route, $model, $tin, $tout, $cost, !empty($res['cache_hit']));
            $db->setQuery('UPDATE ' . $db->quoteName('#__numistr_assistant_conversation') . ' SET last_at = NOW() WHERE id = ' . (int) $convId)->execute();
        } catch (\Throwable $e) {
            self::log('persist', $e->getMessage());
        }

        $quota->add($identity['type'], $identity['key'], $tin, $tout, $cost);

        $out = [
            'conversation_id' => $convId,
            'answer'          => $text,
            'route'           => $route,
            'sources'         => array_values((array) ($res['sources'] ?? [])),
            // Faz 2b: widget rozeti ve CTA icin kimlik tipi (anon | user | pro)
            'identity'        => $identity['type'],
            'quota'           => ['remaining_today' => $quota->remaining($identity['type'], $identity['key'])],
        ];

        if (!empty($res['cta']) && $identity['type'] === 'anon') {
            $out['cta'] = self::authCta($lang);
        }

        return $out;
    }

    /**
     * Reply without an LLM call (ban / quota / blocked). Nothing is stored and the
     * daily quota is not consumed; the conversation is only created for real turns.
     */
    private static function staticReply(string $lang, array $identity, string $route, string $text, NumisTRAssistantQuota $quota): array
    {
        $out = [
            'conversation_id' => null,
            'answer'          => $text,
            'route'           => $route,
            'sources'         => [],
            'identity'        => $identity['type'],
            'quota'           => ['remaining_today' => $quota->remaining($identity['type'], $identity['key'])],
        ];

        // Anonim kullanici gunluk hakkini bitirdiyse cozum yolunu goster:
        // giris yap (zaten uyeyse) veya ucretsiz uye ol.
        if ($route === 'quota' && $identity['type'] === 'anon') {
            $out['cta'] = self::authCta($lang);
        }

        return $out;
    }

    /**
     * Giris / ucretsiz uyelik baglantilari. 'url' alani geriye donuk uyumluluk
     * icin korunur (eski widget yalnizca onu okuyordu).
     *
     * @return array{type:string,url:string,login_url:string,register_url:string}
     */
    private static function authCta(string $lang): array
    {
        $auth = (array) (self::$config['auth_urls'] ?? []);

        return [
            'type'         => 'register',
            'url'          => (string) (self::$config['register_url'][$lang] ?? self::$config['register_url']['tr'] ?? ''),
            'login_url'    => (string) ($auth['login'] ?? ''),
            'register_url' => (string) ($auth['register'] ?? ''),
        ];
    }
}
