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

        return self::persistAndRespond($db, $convId, $identity, $lang, $res, $quota);
    }

    // ======================================================================
    // Routes
    // ======================================================================

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
        $names = $route === 'settlement'
            ? ['search_settlements', 'get_settlement']
            : ['search_coins', 'get_variant'];

        $tools = new NumisTRAssistantTools(self::$constants, self::$config, self::$secrets, $db);
        $tools->setMessageId($userMsgId > 0 ? $userMsgId : null);
        $defs  = NumisTRAssistantTools::definitions($names);

        $system = $rules . "\n\n" . (string) (self::$config['prompts'][$lang]['tools_hint'] ?? '')
            . "\n" . ($lang === 'en' ? 'Today: ' : 'Bugun: ') . date('Y-m-d');

        $sources = [];

        $executor = function (string $name, array $input) use ($tools, $lang, &$sources) {
            $result = $tools->execute($name, $input, $lang);

            $items = isset($result['items']) ? $result['items'] : (isset($result['url']) ? [$result] : []);

            foreach ($items as $it) {
                if (!empty($it['url']) && !empty($it['title'])) {
                    $sources[$it['url']] = ['title' => (string) $it['title'], 'url' => (string) $it['url']];
                }
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
        $kb    = $tools->execute('search_kb', ['query' => $message, 'lang' => $lang], $lang);

        if (isset($kb['error']) || trim((string) ($kb['answer'] ?? '')) === '') {
            // KB unavailable -> fall back to the core-KB glossary (site route)
            self::log('explain-kb', (string) ($kb['error'] ?? 'empty answer'));
            $res = self::routeSite($llm, $message, $history, $lang, $rules, $limits, $coreKb);
            $res['model'] = $model;

            return $res;
        }

        $system = $rules . "\n\n" . (string) (self::$config['prompts'][$lang]['explain_hint'] ?? '')
            . "\n\n" . ($lang === 'en' ? 'KNOWLEDGE BASE ANSWER:' : 'BILGI TABANI YANITI:') . "\n" . $kb['answer'];

        $r = $llm->geminiGenerate($model, $system, $history, $message, [
            'max_output' => (int) $limits['max_output'],
        ]);

        if (!$r['ok']) {
            self::log('explain-llm', $r['error']);
            // still useful: return the raw KB answer
            $r['text'] = (string) $kb['answer'];
        }

        $glossaryUrl = (string) (self::$config['site_base'] ?? 'https://numistr.org') . '/' . $lang . '/numizmatik-karsiliklar';

        return [
            'text'       => $r['text'],
            'model'      => $model,
            'tokens_in'  => $r['tokens_in'],
            'tokens_out' => $r['tokens_out'],
            'cost'       => NumisTRLLMClient::cost(self::$config['costs'] ?? [], $model, $r['tokens_in'], $r['tokens_out']),
            'cache_hit'  => $r['cache_hit'],
            'sources'    => [['title' => $lang === 'en' ? 'Numismatic terms' : 'Numizmatik terimler', 'url' => $glossaryUrl]],
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
            . "- coin_search: wants to find/list coins or a specific coin by region, metal, date, mint, ruler, type (e.g. 'silver coins of Caria 4th century BC', 'Ephesus tetradrachms', 'coins of Croesus').\n"
            . "- settlement: asks about an ancient city/site/settlement: where it is, its history, whether it minted coins (e.g. 'Aphrodisias nerede', 'tell me about Sardes').\n"
            . "- explain: asks the meaning/definition of a numismatic term or concept (e.g. 'what is a stater', 'obverse ne demek', 'kontrmark nedir').\n"
            . "- other: greetings only, chit-chat, coin valuation/price requests, politics, coding, anything unrelated.\n"
            . "Examples:\n"
            . "\"Pro uyelik ne kadar?\" -> site\n"
            . "\"Karya bolgesi gumus sikkeler MO 400\" -> coin_search\n"
            . "\"Show me bronze coins from Pergamon\" -> coin_search\n"
            . "\"Aphrodisias hangi bolgede?\" -> settlement\n"
            . "\"Where is Xanthos\" -> settlement\n"
            . "\"Tetradrahmi nedir?\" -> explain\n"
            . "\"What does incuse mean\" -> explain\n"
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
            'quota'           => ['remaining_today' => $quota->remaining($identity['type'], $identity['key'])],
        ];

        if (!empty($res['cta']) && $identity['type'] === 'anon') {
            $out['cta'] = ['type' => 'register', 'url' => (string) (self::$config['register_url'][$lang] ?? self::$config['register_url']['tr'] ?? '')];
        }

        return $out;
    }

    /**
     * Reply without an LLM call (ban / quota / blocked). Nothing is stored and the
     * daily quota is not consumed; the conversation is only created for real turns.
     */
    private static function staticReply(string $lang, array $identity, string $route, string $text, NumisTRAssistantQuota $quota): array
    {
        return [
            'conversation_id' => null,
            'answer'          => $text,
            'route'           => $route,
            'sources'         => [],
            'quota'           => ['remaining_today' => $quota->remaining($identity['type'], $identity['key'])],
        ];
    }
}
