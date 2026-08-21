<?php
defined('_JEXEC') or die;

/**
 * Core knowledge base (site guide, regions, membership, glossary, FAQ).
 *
 * Source files: plugins/webservices/numistr/assistant/core-kb.{tr,en}.md
 * build() concatenates the file with a header and returns an md5 hash.
 * The hash feeds NumisTRLLMClient::ensureCache() so that editing the
 * markdown automatically invalidates the Gemini explicit cache
 * (OSGBpro lesson: content-hash invalidation).
 */
class NumisTRAssistantCoreKb
{
    /** @var string directory with core-kb.*.md */
    private $dir;

    /** @var array request-local cache lang => build result */
    private static $built = [];

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (dirname(__DIR__, 2) . '/assistant');
    }

    public function path(string $lang): string
    {
        return $this->dir . '/core-kb.' . ($lang === 'en' ? 'en' : 'tr') . '.md';
    }

    /**
     * @return array ['text'=>string,'hash'=>string,'chars'=>int,'tokens_est'=>int,'exists'=>bool]
     */
    public function build(string $lang): array
    {
        $lang = $lang === 'en' ? 'en' : 'tr';

        if (isset(self::$built[$lang])) {
            return self::$built[$lang];
        }

        $file   = $this->path($lang);
        $exists = is_file($file);
        $raw    = $exists ? (string) file_get_contents($file) : '';
        $raw    = str_replace("\r\n", "\n", $raw);
        $raw    = trim($raw);

        $header = $lang === 'en'
            ? "=== NUMISTR CORE KNOWLEDGE (authoritative; answer only from this) ===\n"
            : "=== NUMISTR CEKIRDEK BILGI (yetkili kaynak; yalnizca buna dayan) ===\n";

        $text = $header . $raw . "\n=== END ===";

        self::$built[$lang] = [
            'text'       => $text,
            'hash'       => md5($text),
            'chars'      => mb_strlen($text, 'UTF-8'),
            'tokens_est' => (int) ceil(strlen($text) / 3.5),
            'exists'     => $exists,
        ];

        return self::$built[$lang];
    }

    /**
     * Full system prompt for the "site" route: rules + core KB.
     */
    public function systemPrompt(string $lang, string $rules): string
    {
        $kb = $this->build($lang);

        return $rules . "\n\n" . $kb['text'];
    }

    public static function resetCache(): void
    {
        self::$built = [];
    }
}
