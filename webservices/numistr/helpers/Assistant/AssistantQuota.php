<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Per-subject daily message quota + rate limits + system USD circuit breaker.
 *
 * Tables: #__numistr_assistant_quota (day, subject_type, subject_key, messages, tokens, cost_usd)
 *         #__numistr_assistant_message / _conversation (for per-minute / per-hour counts)
 *
 * Pure arithmetic lives in static evaluate()/systemEvaluate() so it can be unit tested
 * without a database.
 */
class NumisTRAssistantQuota
{
    /** @var array config/assistant.php */
    private $config;

    /** @var object|null */
    private $db;

    public function __construct(array $config, $db = null)
    {
        $this->config = $config;
        $this->db     = $db;
    }

    private function db()
    {
        if ($this->db === null) {
            $this->db = Factory::getDbo();
        }

        return $this->db;
    }

    public function limitsFor(string $subjectType): array
    {
        $limits = $this->config['limits'][$subjectType] ?? $this->config['limits']['anon'] ?? [];

        return array_merge([
            'daily_messages' => 10,
            'per_minute'     => 3,
            'per_hour'       => 15,
            'max_tool_calls' => 2,
            'max_output'     => 400,
            'history_turns'  => 6,
        ], $limits);
    }

    // ------------------------------------------------------------------
    // Pure arithmetic
    // ------------------------------------------------------------------

    /**
     * @return array ['allowed'=>bool,'reason'=>''|'daily'|'minute'|'hour','remaining'=>int]
     */
    public static function evaluate(array $limits, int $usedToday, int $lastMinute, int $lastHour): array
    {
        $remaining = max(0, (int) $limits['daily_messages'] - $usedToday);

        if ($usedToday >= (int) $limits['daily_messages']) {
            return ['allowed' => false, 'reason' => 'daily', 'remaining' => 0];
        }

        if ($lastMinute >= (int) $limits['per_minute']) {
            return ['allowed' => false, 'reason' => 'minute', 'remaining' => $remaining];
        }

        if ($lastHour >= (int) $limits['per_hour']) {
            return ['allowed' => false, 'reason' => 'hour', 'remaining' => $remaining];
        }

        return ['allowed' => true, 'reason' => '', 'remaining' => $remaining];
    }

    /**
     * @return array ['allowed'=>bool,'reason'=>''|'daily_cost'|'monthly_cost']
     */
    public static function systemEvaluate(array $systemLimits, float $costToday, float $costMonth): array
    {
        if ($costToday >= (float) ($systemLimits['daily_cost_usd'] ?? PHP_FLOAT_MAX)) {
            return ['allowed' => false, 'reason' => 'daily_cost'];
        }

        if ($costMonth >= (float) ($systemLimits['monthly_cost_usd'] ?? PHP_FLOAT_MAX)) {
            return ['allowed' => false, 'reason' => 'monthly_cost'];
        }

        return ['allowed' => true, 'reason' => ''];
    }

    // ------------------------------------------------------------------
    // DB-backed checks
    // ------------------------------------------------------------------

    /**
     * Daily quota + rate limits for one subject.
     */
    public function check(string $subjectType, string $subjectKey): array
    {
        $limits = $this->limitsFor($subjectType);

        try {
            $db = $this->db();

            $q = $db->getQuery(true)
                ->select($db->quoteName('messages'))
                ->from($db->quoteName('#__numistr_assistant_quota'))
                ->where($db->quoteName('day') . ' = CURDATE()')
                ->where($db->quoteName('subject_type') . ' = ' . $db->quote($subjectType))
                ->where($db->quoteName('subject_key') . ' = ' . $db->quote($subjectKey));
            $db->setQuery($q);
            $usedToday = (int) $db->loadResult();

            $lastMinute = $this->countRecent($subjectType, $subjectKey, '1 MINUTE');
            $lastHour   = $this->countRecent($subjectType, $subjectKey, '1 HOUR');
        } catch (\Throwable $e) {
            // fail-open on DB trouble but keep the daily number conservative
            return ['allowed' => true, 'reason' => '', 'remaining' => (int) $limits['daily_messages'], 'error' => $e->getMessage()];
        }

        return self::evaluate($limits, $usedToday, $lastMinute, $lastHour);
    }

    private function countRecent(string $subjectType, string $subjectKey, string $interval): int
    {
        $db = $this->db();

        $subjectCond = ($subjectType === 'anon')
            ? $db->quoteName('c.anon_key') . ' = ' . $db->quote($subjectKey)
            : $db->quoteName('c.user_id') . ' = ' . (int) $subjectKey;

        $q = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__numistr_assistant_message', 'm'))
            ->join('INNER', $db->quoteName('#__numistr_assistant_conversation', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('m.conversation_id'))
            ->where($db->quoteName('m.role') . ' = ' . $db->quote('user'))
            ->where($subjectCond)
            ->where($db->quoteName('m.created') . ' >= DATE_SUB(NOW(), INTERVAL ' . $interval . ')');
        $db->setQuery($q);

        return (int) $db->loadResult();
    }

    /**
     * System-wide USD circuit breaker (all subjects).
     */
    public function systemCheck(): array
    {
        try {
            $db = $this->db();
            $t  = $db->quoteName('#__numistr_assistant_quota');

            $db->setQuery('SELECT COALESCE(SUM(cost_usd),0) FROM ' . $t . ' WHERE day = CURDATE()');
            $today = (float) $db->loadResult();

            $db->setQuery('SELECT COALESCE(SUM(cost_usd),0) FROM ' . $t . " WHERE day >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
            $month = (float) $db->loadResult();
        } catch (\Throwable $e) {
            return ['allowed' => true, 'reason' => '', 'error' => $e->getMessage()];
        }

        return self::systemEvaluate($this->config['system'] ?? [], $today, $month);
    }

    /**
     * Record one handled user message.
     */
    public function add(string $subjectType, string $subjectKey, int $tokensIn, int $tokensOut, float $costUsd): void
    {
        try {
            $db  = $this->db();
            $sql = 'INSERT INTO ' . $db->quoteName('#__numistr_assistant_quota')
                . ' (day, subject_type, subject_key, messages, tokens_in, tokens_out, cost_usd) VALUES ('
                . 'CURDATE(), ' . $db->quote($subjectType) . ', ' . $db->quote($subjectKey) . ', 1, '
                . (int) $tokensIn . ', ' . (int) $tokensOut . ', ' . sprintf('%.6F', $costUsd) . ')'
                . ' ON DUPLICATE KEY UPDATE messages = messages + 1, tokens_in = tokens_in + VALUES(tokens_in),'
                . ' tokens_out = tokens_out + VALUES(tokens_out), cost_usd = cost_usd + VALUES(cost_usd)';
            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            // no-op
        }
    }

    /**
     * Remaining messages today (after add()).
     */
    public function remaining(string $subjectType, string $subjectKey): int
    {
        $limits = $this->limitsFor($subjectType);

        try {
            $db = $this->db();
            $q  = $db->getQuery(true)
                ->select($db->quoteName('messages'))
                ->from($db->quoteName('#__numistr_assistant_quota'))
                ->where($db->quoteName('day') . ' = CURDATE()')
                ->where($db->quoteName('subject_type') . ' = ' . $db->quote($subjectType))
                ->where($db->quoteName('subject_key') . ' = ' . $db->quote($subjectKey));
            $db->setQuery($q);
            $used = (int) $db->loadResult();
        } catch (\Throwable $e) {
            $used = 0;
        }

        return max(0, (int) $limits['daily_messages'] - $used);
    }
}
