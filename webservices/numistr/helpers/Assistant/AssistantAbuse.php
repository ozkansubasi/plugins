<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Abuse score + soft/hard bans (#__numistr_assistant_abuse).
 *
 * Score accumulates per event (config abuse.scores); thresholds
 * (config abuse.thresholds) trigger soft 1h / soft 24h / hard 7d bans.
 * Normal messages subtract a little so honest users recover.
 */
class NumisTRAssistantAbuse
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

    /**
     * Pure: which ban (if any) a score maps to.
     *
     * @return array|null ['type'=>'soft'|'hard','seconds'=>int,'message_key'=>string]
     */
    public static function banFor(float $score, array $thresholds): ?array
    {
        if ($score >= (float) ($thresholds['hard_7d'] ?? PHP_FLOAT_MAX)) {
            return ['type' => 'hard', 'seconds' => 7 * 86400, 'message_key' => 'hard_ban'];
        }

        if ($score >= (float) ($thresholds['soft_24h'] ?? PHP_FLOAT_MAX)) {
            return ['type' => 'soft', 'seconds' => 86400, 'message_key' => 'soft_ban'];
        }

        if ($score >= (float) ($thresholds['soft_1h'] ?? PHP_FLOAT_MAX)) {
            return ['type' => 'soft', 'seconds' => 3600, 'message_key' => 'soft_ban'];
        }

        return null;
    }

    public function scoreFor(string $event): float
    {
        return (float) ($this->config['abuse']['scores'][$event] ?? 0.0);
    }

    /**
     * @return array ['banned'=>bool,'type'=>'none'|'soft'|'hard','until'=>?string,'message_key'=>string]
     */
    public function check(string $subjectKey): array
    {
        try {
            $db = $this->db();
            $q  = $db->getQuery(true)
                ->select([$db->quoteName('ban_type'), $db->quoteName('banned_until')])
                ->from($db->quoteName('#__numistr_assistant_abuse'))
                ->where($db->quoteName('subject_key') . ' = ' . $db->quote($subjectKey))
                ->where($db->quoteName('ban_type') . ' != ' . $db->quote('none'))
                ->where($db->quoteName('banned_until') . ' > NOW()');
            $db->setQuery($q);
            $row = $db->loadAssoc();
        } catch (\Throwable $e) {
            $row = null;
        }

        if (!$row) {
            return ['banned' => false, 'type' => 'none', 'until' => null, 'message_key' => ''];
        }

        return [
            'banned'      => true,
            'type'        => $row['ban_type'],
            'until'       => $row['banned_until'],
            'message_key' => $row['ban_type'] === 'hard' ? 'hard_ban' : 'soft_ban',
        ];
    }

    /**
     * Add score for an event; apply ban when a threshold is crossed.
     *
     * @return float new score
     */
    public function record(string $subjectType, string $subjectKey, string $event): float
    {
        $delta = $this->scoreFor($event);

        if ($delta == 0.0) {
            return 0.0;
        }

        try {
            $db  = $this->db();
            $t   = $db->quoteName('#__numistr_assistant_abuse');
            $sql = 'INSERT INTO ' . $t . ' (subject_key, subject_type, score, last_event) VALUES ('
                . $db->quote($subjectKey) . ', ' . $db->quote($subjectType) . ', ' . sprintf('%.2F', max(0, $delta)) . ', ' . $db->quote($event) . ')'
                . ' ON DUPLICATE KEY UPDATE score = GREATEST(0, score + ' . sprintf('%.2F', $delta) . '), last_event = VALUES(last_event)';
            $db->setQuery($sql)->execute();

            $db->setQuery('SELECT score FROM ' . $t . ' WHERE subject_key = ' . $db->quote($subjectKey));
            $score = (float) $db->loadResult();
        } catch (\Throwable $e) {
            return 0.0;
        }

        if ($delta > 0) {
            $ban = self::banFor($score, $this->config['abuse']['thresholds'] ?? []);

            if ($ban !== null) {
                $this->applyBan($subjectKey, $ban['type'], $ban['seconds']);
            }
        }

        return $score;
    }

    private function applyBan(string $subjectKey, string $type, int $seconds): void
    {
        try {
            $db  = $this->db();
            $sql = 'UPDATE ' . $db->quoteName('#__numistr_assistant_abuse')
                . ' SET ban_type = ' . $db->quote($type)
                . ', banned_until = DATE_ADD(NOW(), INTERVAL ' . (int) $seconds . ' SECOND)'
                . ', total_bans = total_bans + 1'
                . ' WHERE subject_key = ' . $db->quote($subjectKey)
                . ' AND (ban_type = ' . $db->quote('none') . ' OR banned_until IS NULL OR banned_until < NOW())';
            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            // no-op
        }
    }
}
