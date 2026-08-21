<?php
defined('_JEXEC') or die;

/**
 * NumisTR LLM client (plain cURL, no Composer deps).
 *
 * Providers:
 *  - Gemini  : generateContent + cachedContents explicit cache (content-hash invalidation)
 *  - Anthropic: Messages API with tool_use loop and ephemeral cache_control on system
 *
 * Every public method returns an array:
 *   ['ok'=>bool, 'text'=>string, 'tokens_in'=>int, 'tokens_out'=>int,
 *    'model'=>string, 'cache_hit'=>bool, 'error'=>string, ...]
 * Transport/JSON problems are reported as ok=false (and thrown as
 * NumisTRLLMException only from the low-level request helper when
 * $throw=true; the public API never throws so the controller can degrade).
 *
 * Ported from OSGBpro LLMClient (2026-04) with these fixes:
 *  - cache_hit is always reported (caller writes it to the message row)
 *  - cache bookkeeping in NumisTRAssistantSettings (no PDO/king_db)
 *  - tool loop caps both iterations and total tool calls
 */
class NumisTRLLMException extends \RuntimeException
{
}

class NumisTRLLMClient
{
    const GEMINI_BASE    = 'https://generativelanguage.googleapis.com/v1beta';
    const ANTHROPIC_URL  = 'https://api.anthropic.com/v1/messages';
    const ANTHROPIC_VER  = '2023-06-01';

    /** @var array config/assistant.php */
    private $config;

    /** @var array config/secrets.php */
    private $secrets;

    /** @var NumisTRAssistantSettings|null */
    private $settings;

    /** @var string last low-level error (debug) */
    private $lastError = '';

    public function __construct(array $config, array $secrets, $settings = null)
    {
        $this->config   = $config;
        $this->secrets  = $secrets;
        $this->settings = $settings;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function hasGemini(): bool
    {
        return trim((string) ($this->secrets['GEMINI_API_KEY'] ?? '')) !== '';
    }

    public function hasAnthropic(): bool
    {
        return trim((string) ($this->secrets['ANTHROPIC_API_KEY'] ?? '')) !== '';
    }

    // ======================================================================
    // Cost
    // ======================================================================

    /**
     * USD cost for a call. Cached input tokens are billed at the cache rate.
     */
    public static function cost(array $costTable, string $model, int $tokensIn, int $tokensOut, int $cachedTokens = 0): float
    {
        $row = $costTable[$model] ?? null;

        if ($row === null) {
            return 0.0;
        }

        $cachedTokens = max(0, min($cachedTokens, $tokensIn));
        $freshIn      = $tokensIn - $cachedTokens;

        $usd = ($freshIn * ($row['input'] ?? 0) + $cachedTokens * ($row['cache'] ?? 0) + $tokensOut * ($row['output'] ?? 0)) / 1000000;

        return round($usd, 6);
    }

    // ======================================================================
    // Gemini
    // ======================================================================

    /**
     * Single-word classifier.
     *
     * @param  string[] $labels allowed labels (lower-case)
     * @return array ['ok','label','tokens_in','tokens_out','model']
     */
    public function classify(string $model, string $systemPrompt, string $message, array $labels): array
    {
        $body = [
            'contents'          => [['role' => 'user', 'parts' => [['text' => $message]]]],
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'generationConfig'  => [
                'maxOutputTokens' => 8,
                'temperature'     => 0,
                'thinkingConfig'  => ['thinkingBudget' => (int) ($this->config['gemini_thinking_budget'] ?? 0)],
            ],
        ];

        $resp  = $this->geminiCall($model, $body, 12);
        $label = null;

        if ($resp['ok']) {
            $text = strtolower(trim(self::geminiText($resp['data'])));
            $text = preg_replace('/[^a-z_]/', '', $text);

            if (in_array($text, $labels, true)) {
                $label = $text;
            }
        }

        $usage = $resp['data']['usageMetadata'] ?? [];

        return [
            'ok'         => $resp['ok'],
            'label'      => $label,
            'tokens_in'  => (int) ($usage['promptTokenCount'] ?? 0),
            'tokens_out' => (int) ($usage['candidatesTokenCount'] ?? 0),
            'model'      => $model,
            'cache_hit'  => false,
            'error'      => $resp['error'],
        ];
    }

    /**
     * Gemini generateContent with optional explicit cache for the system prompt.
     *
     * @param array $history  [['role'=>'user'|'assistant','text'=>...], ...]
     * @param array $opts     max_output, temperature, cache_key (enables explicit cache), timeout
     */
    public function geminiGenerate(string $model, string $systemPrompt, array $history, string $message, array $opts = []): array
    {
        $maxOut  = (int) ($opts['max_output'] ?? 400);
        $temp    = (float) ($opts['temperature'] ?? 0.3);
        $timeout = (int) ($opts['timeout'] ?? 30);

        $contents = [];

        foreach ($history as $h) {
            $role = ($h['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = trim((string) ($h['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $genCfg = [
            'maxOutputTokens' => $maxOut,
            'temperature'     => $temp,
            'thinkingConfig'  => ['thinkingBudget' => (int) ($this->config['gemini_thinking_budget'] ?? 0)],
        ];

        $cacheKey  = (string) ($opts['cache_key'] ?? '');
        $cacheName = $cacheKey !== '' ? $this->ensureCache($cacheKey, $model, $systemPrompt) : null;
        $resp      = null;

        if ($cacheName !== null) {
            $body = [
                'contents'         => $contents,
                'cachedContent'    => $cacheName,
                'generationConfig' => $genCfg,
            ];
            $resp = $this->geminiCall($model, $body, $timeout);

            if (!$resp['ok'] && self::looksLikeCacheError($resp['error'])) {
                // expired / deleted on the provider side -> rebuild once
                $this->dropCache($cacheKey);
                $cacheName = $this->ensureCache($cacheKey, $model, $systemPrompt);

                if ($cacheName !== null) {
                    $body['cachedContent'] = $cacheName;
                    $resp = $this->geminiCall($model, $body, $timeout);
                } else {
                    $resp = null;
                }
            }
        }

        if ($resp === null || (!$resp['ok'] && $cacheName !== null)) {
            // no cache (short prompt / no settings) or cache path failed -> inline system prompt
            $body = [
                'contents'          => $contents,
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'generationConfig'  => $genCfg,
            ];
            $resp = $this->geminiCall($model, $body, $timeout);
        }

        $text  = $resp['ok'] ? trim(self::geminiText($resp['data'])) : '';
        $usage = $resp['data']['usageMetadata'] ?? [];

        return [
            'ok'            => $resp['ok'] && $text !== '',
            'text'          => $text,
            'tokens_in'     => (int) ($usage['promptTokenCount'] ?? 0),
            'tokens_out'    => (int) ($usage['candidatesTokenCount'] ?? 0),
            'cached_tokens' => (int) ($usage['cachedContentTokenCount'] ?? 0),
            'model'         => $model,
            'cache_hit'     => !empty($usage['cachedContentTokenCount']),
            'error'         => $resp['error'],
        ];
    }

    private static function geminiText(array $data): string
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $out   = '';

        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $out .= $p['text'];
            }
        }

        return $out;
    }

    /**
     * Return a valid cachedContents name for this (key, model, prompt) or null.
     * Rebuilds when expired or when md5(model|prompt) changed (content-hash invalidation).
     */
    private function ensureCache(string $key, string $model, string $systemPrompt): ?string
    {
        if ($this->settings === null || !$this->hasGemini()) {
            return null;
        }

        // Gemini 2.5 Flash requires >= 1024 tokens of cached content; ~3.5 chars/token
        if ((int) (strlen($systemPrompt) / 3.5) < 1200) {
            return null;
        }

        $hash    = md5($model . '|' . $systemPrompt);
        $name    = (string) $this->settings->get('gemini_cache_' . $key . '_name', '');
        $expires = (int) $this->settings->get('gemini_cache_' . $key . '_expires', '0');
        $stored  = (string) $this->settings->get('gemini_cache_' . $key . '_hash', '');

        if ($name !== '' && $expires > (time() + 60) && $stored === $hash) {
            return $name;
        }

        $ttl  = (int) ($this->config['gemini_cache_ttl'] ?? 3600);
        $body = [
            'model'             => 'models/' . $model,
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'ttl'               => $ttl . 's',
        ];

        $url  = self::GEMINI_BASE . '/cachedContents?key=' . rawurlencode($this->secrets['GEMINI_API_KEY']);
        $resp = $this->httpJson($url, $body, ['Content-Type: application/json'], 30);
        $data = $resp['json'];

        if ($resp['http'] !== 200 || empty($data['name'])) {
            $this->lastError = 'gemini cache create failed: http=' . $resp['http'] . ' ' . ($data['error']['message'] ?? $resp['error']);

            return null;
        }

        $this->settings->set('gemini_cache_' . $key . '_name', (string) $data['name']);
        $this->settings->set('gemini_cache_' . $key . '_expires', (string) (time() + $ttl - 100));
        $this->settings->set('gemini_cache_' . $key . '_hash', $hash);

        return (string) $data['name'];
    }

    private function dropCache(string $key): void
    {
        if ($this->settings !== null) {
            $this->settings->set('gemini_cache_' . $key . '_expires', '0');
        }
    }

    public static function looksLikeCacheError(string $err): bool
    {
        return stripos($err, 'cached') !== false
            || stripos($err, 'NOT_FOUND') !== false
            || stripos($err, 'expired') !== false;
    }

    private function geminiCall(string $model, array $body, int $timeout): array
    {
        if (!$this->hasGemini()) {
            return ['ok' => false, 'data' => [], 'error' => 'GEMINI_API_KEY missing', 'http' => 0];
        }

        $url  = self::GEMINI_BASE . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->secrets['GEMINI_API_KEY']);
        $resp = $this->httpJson($url, $body, ['Content-Type: application/json'], $timeout);
        $data = $resp['json'];
        $ok   = ($resp['http'] === 200 && !isset($data['error']));
        $err  = $ok ? '' : (string) ($data['error']['message'] ?? $resp['error'] ?? ('http ' . $resp['http']));

        if (!$ok) {
            $this->lastError = 'gemini: ' . $err;
        }

        return ['ok' => $ok, 'data' => $data, 'error' => $err, 'http' => $resp['http']];
    }

    // ======================================================================
    // Anthropic Messages API + tool loop
    // ======================================================================

    /**
     * Tool-calling loop.
     *
     * @param array    $history   [['role'=>'user'|'assistant','text'=>...], ...]
     * @param array    $tools     Anthropic tool definitions
     * @param callable $executor  function(string $name, array $input): array  (tool result, JSON-encodable)
     * @param array    $opts      max_output, max_iterations, max_tool_calls, timeout
     *
     * @return array ['ok','text','tokens_in','tokens_out','model','cache_hit','tool_calls'=>[['name','input','result','ms']], 'error']
     */
    public function claudeToolLoop(string $model, string $systemPrompt, array $history, string $message, array $tools, callable $executor, array $opts = []): array
    {
        $maxOut   = (int) ($opts['max_output'] ?? 500);
        $maxIter  = (int) ($opts['max_iterations'] ?? 3);
        $maxCalls = (int) ($opts['max_tool_calls'] ?? 2);
        $timeout  = (int) ($opts['timeout'] ?? 40);

        $messages = [];

        foreach ($history as $h) {
            $text = trim((string) ($h['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $messages[] = ['role' => (($h['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user'), 'content' => $text];
        }

        // Anthropic requires alternating roles starting with user
        $messages = self::normaliseRoles($messages);
        $messages[] = ['role' => 'user', 'content' => $message];

        $toolCalls  = [];
        $tokensIn   = 0;
        $tokensOut  = 0;
        $cacheHit   = false;
        $finalText  = '';
        $iter       = 0;
        $callsUsed  = 0;
        $lastErr    = '';

        while ($iter < $maxIter) {
            $iter++;

            $body = [
                'model'      => $model,
                'max_tokens' => $maxOut,
                'system'     => [[
                    'type'          => 'text',
                    'text'          => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'messages'   => $messages,
            ];

            if (!empty($tools) && $callsUsed < $maxCalls) {
                $body['tools'] = $tools;
            }

            $resp = $this->claudeCall($body, $timeout);

            if (!$resp['ok']) {
                $lastErr = $resp['error'];
                break;
            }

            $data  = $resp['data'];
            $usage = $data['usage'] ?? [];
            $tokensIn  += (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0);
            $tokensOut += (int) ($usage['output_tokens'] ?? 0);
            $cacheHit   = $cacheHit || !empty($usage['cache_read_input_tokens']);

            $content = $data['content'] ?? [];

            // empty tool input must be serialised as {} not []
            foreach ($content as &$block) {
                if (($block['type'] ?? '') === 'tool_use' && isset($block['input']) && is_array($block['input']) && empty($block['input'])) {
                    $block['input'] = new \stdClass();
                }
            }
            unset($block);

            $messages[] = ['role' => 'assistant', 'content' => $content];

            if (($data['stop_reason'] ?? '') === 'tool_use') {
                $results = [];

                foreach ($content as $block) {
                    if (($block['type'] ?? '') !== 'tool_use') {
                        continue;
                    }

                    $name  = (string) $block['name'];
                    $input = $block['input'] ?? [];

                    if (is_object($input)) {
                        $input = (array) $input;
                    }

                    if ($callsUsed >= $maxCalls) {
                        $result = ['error' => 'tool call limit reached; answer with what you have'];
                    } else {
                        $callsUsed++;
                        $t0 = microtime(true);

                        try {
                            $result = $executor($name, $input);
                        } catch (\Throwable $e) {
                            $result = ['error' => 'tool failed: ' . $e->getMessage()];
                        }

                        $toolCalls[] = [
                            'name'   => $name,
                            'input'  => $input,
                            'result' => $result,
                            'ms'     => (int) round((microtime(true) - $t0) * 1000),
                        ];
                    }

                    $results[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $block['id'],
                        'content'     => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                }

                if (!empty($results)) {
                    $messages[] = ['role' => 'user', 'content' => $results];
                    continue;
                }
            }

            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $finalText .= $block['text'];
                }
            }

            break;
        }

        // Loop exhausted while the model still wanted tools: one last text-only pass
        if (trim($finalText) === '' && $lastErr === '' && $iter >= $maxIter) {
            $body = [
                'model'      => $model,
                'max_tokens' => $maxOut,
                'system'     => $systemPrompt,
                'messages'   => array_merge($messages, [['role' => 'user', 'content' => 'Summarise the tool results above for the user now without calling more tools.']]),
            ];
            $resp = $this->claudeCall($body, $timeout);

            if ($resp['ok']) {
                $usage = $resp['data']['usage'] ?? [];
                $tokensIn  += (int) ($usage['input_tokens'] ?? 0);
                $tokensOut += (int) ($usage['output_tokens'] ?? 0);

                foreach (($resp['data']['content'] ?? []) as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $finalText .= $block['text'];
                    }
                }
            } else {
                $lastErr = $resp['error'];
            }
        }

        $finalText = trim($finalText);

        return [
            'ok'         => $finalText !== '',
            'text'       => $finalText,
            'tokens_in'  => $tokensIn,
            'tokens_out' => $tokensOut,
            'model'      => $model,
            'cache_hit'  => $cacheHit,
            'tool_calls' => $toolCalls,
            'iterations' => $iter,
            'error'      => $lastErr,
        ];
    }

    /**
     * Merge consecutive same-role messages and drop a leading assistant turn.
     */
    public static function normaliseRoles(array $messages): array
    {
        $out = [];

        foreach ($messages as $m) {
            if (empty($out) && $m['role'] === 'assistant') {
                continue;
            }

            $last = count($out) - 1;

            if ($last >= 0 && $out[$last]['role'] === $m['role'] && is_string($out[$last]['content']) && is_string($m['content'])) {
                $out[$last]['content'] .= "\n\n" . $m['content'];
                continue;
            }

            $out[] = $m;
        }

        // must end with assistant so that the new user message alternates
        if (!empty($out) && $out[count($out) - 1]['role'] === 'user') {
            array_pop($out);
        }

        return $out;
    }

    private function claudeCall(array $body, int $timeout): array
    {
        if (!$this->hasAnthropic()) {
            return ['ok' => false, 'data' => [], 'error' => 'ANTHROPIC_API_KEY missing', 'http' => 0];
        }

        $resp = $this->httpJson(
            self::ANTHROPIC_URL,
            $body,
            [
                'Content-Type: application/json',
                'x-api-key: ' . $this->secrets['ANTHROPIC_API_KEY'],
                'anthropic-version: ' . self::ANTHROPIC_VER,
            ],
            $timeout
        );

        $data = $resp['json'];
        $ok   = ($resp['http'] === 200 && empty($data['error']));
        $err  = $ok ? '' : (string) ($data['error']['message'] ?? $resp['error'] ?? ('http ' . $resp['http']));

        if (!$ok) {
            $this->lastError = 'anthropic: ' . $err;
        }

        return ['ok' => $ok, 'data' => $data, 'error' => $err, 'http' => $resp['http']];
    }

    // ======================================================================
    // HTTP
    // ======================================================================

    /**
     * POST JSON. Never throws; returns ['http'=>int,'json'=>array,'raw'=>string,'error'=>string].
     */
    protected function httpJson(string $url, array $body, array $headers, int $timeout): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return ['http' => 0, 'json' => [], 'raw' => '', 'error' => 'json_encode failed: ' . json_last_error_msg()];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['http' => $http, 'json' => [], 'raw' => '', 'error' => 'curl: ' . $err];
        }

        $json = json_decode((string) $raw, true);

        if (!is_array($json)) {
            return ['http' => $http, 'json' => [], 'raw' => (string) $raw, 'error' => 'invalid JSON response (http ' . $http . ')'];
        }

        return ['http' => $http, 'json' => $json, 'raw' => (string) $raw, 'error' => $err];
    }
}
