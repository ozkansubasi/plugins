<?php
/**
 * Minimal dependency-free test runner (PHP >= 8.0, no PHPUnit).
 *
 *   php tests/run.php            # all tests
 *   php tests/run.php Quota      # only files matching "Quota"
 *
 * Each tests/<Group>/<Name>Test.php file is included and uses check().
 * Joomla is stubbed just enough for the helpers to load outside the CMS
 * (same approach as NumisTR/tests/php/jwt_verifier_test.php).
 */

define('_JEXEC', 1);

if (!class_exists('Joomla\CMS\Factory')) {
    eval('
        namespace Joomla\CMS;
        class Factory {
            public static function getContainer() { throw new \RuntimeException("no container in tests"); }
            public static function getDbo() { throw new \RuntimeException("no database in tests"); }
            public static function getApplication() { throw new \RuntimeException("no application in tests"); }
        }
    ');
}

// ---------------------------------------------------------------------------
// mbstring polyfill - ONLY for CLI test environments without ext-mbstring
// (the local WSL PHP). Joomla requires mbstring, so production never hits this.
// ---------------------------------------------------------------------------
if (!function_exists('mb_strlen')) {
    echo "[run.php] ext-mbstring missing: using UTF-8 polyfill for tests\n";

    function mb_strlen(string $s, ?string $enc = null): int
    {
        return (int) preg_match_all('/./us', $s);
    }

    function mb_substr(string $s, int $start, ?int $length = null, ?string $enc = null): string
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', $length === null ? array_slice($chars, $start) : array_slice($chars, $start, $length));
    }

    function mb_strtolower(string $s, ?string $enc = null): string
    {
        static $map = ['İ' => 'i', 'I' => 'ı', 'Ç' => 'ç', 'Ğ' => 'ğ', 'Ö' => 'ö', 'Ş' => 'ş', 'Ü' => 'ü', 'Â' => 'â', 'Î' => 'î', 'Û' => 'û', 'É' => 'é'];

        return strtr(strtolower($s), $map);
    }

    function mb_strpos(string $h, string $n, int $offset = 0, ?string $enc = null)
    {
        $pos = strpos($h, $n, $offset);

        return $pos === false ? false : mb_strlen(substr($h, 0, $pos));
    }

    function mb_stripos(string $h, string $n, int $offset = 0, ?string $enc = null)
    {
        return mb_strpos(mb_strtolower($h), mb_strtolower($n), $offset);
    }
}

$GLOBALS['__passed'] = 0;
$GLOBALS['__failed'] = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['__passed']++;
        echo "  ok   {$name}\n";
    } else {
        $GLOBALS['__failed']++;
        echo "  FAIL {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

$root   = dirname(__DIR__);
$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/*/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    if ($filter !== '' && stripos(basename($file), $filter) === false) {
        continue;
    }

    echo "\n" . basename(dirname($file)) . '/' . basename($file) . "\n";
    require $file;
}

echo "\n" . $GLOBALS['__passed'] . ' passed, ' . $GLOBALS['__failed'] . " failed\n";
exit($GLOBALS['__failed'] === 0 ? 0 : 1);
