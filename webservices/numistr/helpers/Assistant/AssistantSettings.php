<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Key/value store on #__numistr_assistant_settings.
 *
 * Used for the Gemini explicit-cache bookkeeping (name / expiry / content hash)
 * and, later, admin overrides of config/assistant.php values.
 * All DB failures are swallowed (fail-open): the assistant must keep working
 * even when the settings table is missing.
 */
class NumisTRAssistantSettings
{
    /** @var object|null Joomla DatabaseDriver */
    private $db;

    /** @var array request-local cache */
    private static $cache = [];

    public function __construct($db = null)
    {
        $this->db = $db;
    }

    private function db()
    {
        if ($this->db === null) {
            $this->db = Factory::getDbo();
        }

        return $this->db;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        try {
            $db = $this->db();
            $q  = $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__numistr_assistant_settings'))
                ->where($db->quoteName('key') . ' = ' . $db->quote($key));
            $db->setQuery($q);
            $val = $db->loadResult();
            self::$cache[$key] = ($val === null) ? $default : (string) $val;
        } catch (\Throwable $e) {
            self::$cache[$key] = $default;
        }

        return self::$cache[$key];
    }

    public function set(string $key, string $value): void
    {
        self::$cache[$key] = $value;

        try {
            $db  = $this->db();
            $sql = 'INSERT INTO ' . $db->quoteName('#__numistr_assistant_settings')
                . ' (' . $db->quoteName('key') . ', ' . $db->quoteName('value') . ') VALUES ('
                . $db->quote($key) . ', ' . $db->quote($value) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('value') . ' = VALUES(' . $db->quoteName('value') . ')';
            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            // no-op (fail-open)
        }
    }

    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
