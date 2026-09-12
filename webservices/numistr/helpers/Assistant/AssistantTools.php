<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * Tool definitions (Anthropic JSON schema) + executors for the assistant.
 *
 * Tools (Phase 1): search_coins, get_variant, search_settlements, get_settlement, search_kb
 *
 * Rules baked in:
 *  - "sonsuzluk algisi": NEVER return total counts, only items + has_more
 *  - URLs are built server-side from real ids/aliases; the model only relays them
 *  - every call is logged to #__numistr_assistant_tool_log (params, ok, ms)
 */
class NumisTRAssistantTools
{
    const SETTLEMENT_CATS = [
        'tr' => [72, 88],
        'en' => [89, 105],
    ];

    const FIELD_LOC_ID    = 44;
    const FIELD_REGION    = 45;
    const FIELD_HAS_COINS = 46;

    /** Turkish / alternative region names -> canonical English stem used in region_code */
    const REGION_ALIASES = [
        'karya' => 'caria', 'lidya' => 'lydia', 'iyonya' => 'ionia', 'ionya' => 'ionia',
        'likya' => 'lycia', 'frigya' => 'phrygia', 'misya' => 'mysia', 'misia' => 'mysia',
        'bitinya' => 'bithynia', 'pamfilya' => 'pamphylia', 'kilikya' => 'cilicia',
        'clicia' => 'cilicia', 'kapadokya' => 'cappadocia', 'galatya' => 'galatia',
        'pisidya' => 'pisidia', 'paflagonya' => 'paphlagonia', 'aiolis' => 'aeolis',
        'diger' => 'other', 'diğer' => 'other',
    ];

    /** @var array plugin constants (FIELD_ID, MATERIAL_VARIANTS, ROOT_CAT_ID) */
    private $constants;

    /** @var array config/assistant.php */
    private $config;

    /** @var array config/secrets.php */
    private $secrets;

    /** @var object|null */
    private $db;

    /** @var NumisTRDatabaseHelper|null */
    private $dbHelper;

    /** @var int|null message id for tool_log rows */
    private $messageId = null;

    /** @var array cache catid -> menu alias */
    private $menuAliasCache = [];

    public function __construct(array $constants, array $config, array $secrets, $db = null)
    {
        $this->constants = $constants;
        $this->config    = $config;
        $this->secrets   = $secrets;
        $this->db        = $db;
    }

    public function setMessageId(?int $id): void
    {
        $this->messageId = $id;
    }

    private function db()
    {
        if ($this->db === null) {
            $this->db = Factory::getDbo();
        }

        return $this->db;
    }

    private function dbHelper(): NumisTRDatabaseHelper
    {
        if ($this->dbHelper === null) {
            $this->dbHelper = new NumisTRDatabaseHelper($this->constants);
        }

        return $this->dbHelper;
    }

    // ======================================================================
    // Definitions
    // ======================================================================

    /**
     * @param string[]|null $only subset of tool names
     */
    public static function definitions(?array $only = null): array
    {
        $limitProp = ['type' => 'integer', 'description' => 'Max items (1-10, default 5)', 'minimum' => 1, 'maximum' => 10];
        $langProp  = ['type' => 'string', 'enum' => ['tr', 'en'], 'description' => 'Content language'];

        $defs = [
            [
                'name'        => 'search_coins',
                'description' => 'Search ancient Anatolian coin variants in the NumisTR database by region, metal, date range, mint, authority or free text. Returns up to 10 example variants with page URLs. Never reveals total counts.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'region'    => ['type' => 'string', 'description' => 'Region in English (caria, lydia, ionia, lycia, phrygia, mysia, bithynia, pamphylia, cilicia, cappadocia, galatia, pisidia, troas, paphlagonia, aeolis, pontus)'],
                        'metal'     => ['type' => 'string', 'description' => 'silver | gold | bronze | electrum | lead | iron'],
                        'date_from' => ['type' => 'integer', 'description' => 'Earliest year; BC is negative (e.g. -400)'],
                        'date_to'   => ['type' => 'integer', 'description' => 'Latest year; BC is negative (e.g. -300)'],
                        'mint'      => ['type' => 'string', 'description' => 'Mint (ancient city) name in English/Latin catalogue spelling, never the Turkish form (e.g. halicarnassus not Halikarnassos, rhodes not Rhodos, ephesus, sardes, cnidus)'],
                        'authority' => ['type' => 'string', 'description' => 'Issuing authority / ruler (e.g. croesus, hadrian)'],
                        'q'         => ['type' => 'string', 'description' => 'Free text matched against title, mint and authority (English/Latin spelling)'],
                        'limit'     => $limitProp,
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_variant',
                'description' => 'Get details (title, region, metal, dates, mint, authority, obverse/reverse descriptions, URL) of one coin variant by its article_id.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'article_id' => ['type' => 'integer', 'description' => 'Variant article id'],
                    ],
                    'required' => ['article_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'search_settlements',
                'description' => 'Search ancient settlement (city/site) articles by name and/or region. Returns title, short summary, loc_id and page URL for up to 10 settlements.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'q'         => ['type' => 'string', 'description' => 'Settlement name or part of it; titles use Latin spelling (e.g. Aphrodisias, Ephesus, Cnidus) but Turkish forms (Efes, Knidos) are also matched'],
                        'region'    => ['type' => 'string', 'description' => 'Region in English (caria, lydia, ...)'],
                        'has_coins' => ['type' => 'boolean', 'description' => 'Only settlements that minted coins'],
                        'lang'      => $langProp,
                        'limit'     => $limitProp,
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_settlement',
                'description' => 'Get the full text (max 4000 chars) of one settlement article by article_id or loc_id.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'article_id' => ['type' => 'integer', 'description' => 'Joomla article id'],
                        'loc_id'     => ['type' => 'string', 'description' => 'Settlement loc_id from search_settlements'],
                        'lang'       => $langProp,
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'search_site',
                'description' => 'Full-text semantic search over NumisTR articles: blog posts (history, iconography, symbols, rulers, hoards, collecting) and ancient settlement pages. Returns up to 5 excerpts with title and page URL. Use for history/culture/"why" questions and anything not answered by the structured coin/settlement tools.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Natural-language search query in the user language'],
                        'type'  => ['type' => 'string', 'enum' => ['blog', 'settlements'], 'description' => 'Optional: restrict to blog posts or settlement pages'],
                        'lang'  => $langProp,
                        'limit' => $limitProp,
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'search_kb',
                'description' => 'Semantic search in the NumisTR numismatic terminology knowledge base (definitions of terms such as obverse, stater, tetradrachm, countermark). Returns up to 8 excerpts with the term title; an empty list means the term is not in the knowledge base -- say so, do not answer from general knowledge.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'The question or term'],
                        'lang'  => $langProp,
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        if ($only === null) {
            return $defs;
        }

        return array_values(array_filter($defs, function ($d) use ($only) {
            return in_array($d['name'], $only, true);
        }));
    }

    // ======================================================================
    // Dispatcher
    // ======================================================================

    public function execute(string $name, array $input, string $defaultLang = 'tr'): array
    {
        $t0 = microtime(true);
        $ok = false;
        $err = null;
        $count = 0;

        try {
            switch ($name) {
                case 'search_coins':
                    $result = $this->searchCoins($input, $defaultLang);
                    break;
                case 'get_variant':
                    $result = $this->getVariant((int) ($input['article_id'] ?? 0), $defaultLang);
                    break;
                case 'search_settlements':
                    $result = $this->searchSettlements($input, $defaultLang);
                    break;
                case 'get_settlement':
                    $result = $this->getSettlement($input, $defaultLang);
                    break;
                case 'search_kb':
                    $result = $this->searchKb((string) ($input['query'] ?? ''), (string) ($input['lang'] ?? $defaultLang));
                    break;
                case 'search_site':
                    $result = $this->searchSite((string) ($input['query'] ?? ''), (string) ($input['lang'] ?? $defaultLang), isset($input['type']) ? (string) $input['type'] : null, (int) ($input['limit'] ?? 5));
                    break;
                default:
                    $result = ['error' => 'unknown tool: ' . $name];
            }

            $ok    = !isset($result['error']);
            $err   = $result['error'] ?? null;
            $count = isset($result['items']) && is_array($result['items']) ? count($result['items']) : ($ok ? 1 : 0);
        } catch (\Throwable $e) {
            $result = ['error' => 'tool failure'];
            $err    = $e->getMessage();
        }

        $this->log($name, $input, $ok, $count, $err, (int) round((microtime(true) - $t0) * 1000));

        return $result;
    }

    private function log(string $tool, array $params, bool $ok, int $count, ?string $err, int $ms): void
    {
        try {
            $db  = $this->db();
            $sql = 'INSERT INTO ' . $db->quoteName('#__numistr_assistant_tool_log')
                . ' (message_id, tool, params_json, ok, result_count, error, ms) VALUES ('
                . ($this->messageId !== null ? (int) $this->messageId : 'NULL') . ', '
                . $db->quote($tool) . ', '
                . $db->quote(mb_substr(json_encode($params, JSON_UNESCAPED_UNICODE), 0, 2000)) . ', '
                . ($ok ? 1 : 0) . ', ' . (int) $count . ', '
                . ($err !== null ? $db->quote(mb_substr($err, 0, 250)) : 'NULL') . ', '
                . (int) $ms . ')';
            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            // no-op
        }
    }

    // ======================================================================
    // Helpers (pure, testable)
    // ======================================================================

    public static function normaliseRegion(?string $region): ?string
    {
        $r = mb_strtolower(trim((string) $region), 'UTF-8');

        if ($r === '') {
            return null;
        }

        $r = preg_replace('/[-_\s]*(coins|sikkeleri|yerlesimleri|settlements)$/u', '', $r);
        $r = preg_replace('/[^a-zçğıöşü]/u', '', $r);

        if (isset(self::REGION_ALIASES[$r])) {
            $r = self::REGION_ALIASES[$r];
        }

        return $r !== '' ? $r : null;
    }

    /**
     * Regions whose pages do not sit at /{lang}/anatolian-coins/{region_code}/.
     *
     * Both were producing a 404 for every coin in them; verified 2026-09-09 against
     * live records (12093 Adana, 15060 Arsames):
     *
     *  - cilicia-coins: the Turkish menu alias is "clicia-coins" - a typo on the site
     *    that was never corrected, while the API and the English menu both spell it
     *    properly. 824 variants, so every Turkish answer about a Cilician or Tarsus
     *    coin handed the reader a dead link.
     *  - other-ancient-regions-coins: not under anatolian-coins at all, and the alias
     *    differs per language. 46 variants, broken in BOTH languages.
     *
     * The link filter cannot catch this: these URLs come from tool results, which it
     * trusts by construction. The shape has to be right at the source.
     */
    private const COIN_PATHS = [
        'cilicia-coins' => [
            'tr' => 'anatolian-coins/clicia-coins',
            'en' => 'anatolian-coins/cilicia-coins',
        ],
        'other-ancient-regions-coins' => [
            'tr' => 'other-ancient-place-coins',
            'en' => 'other-ancient-regions',
        ],
    ];

    public static function coinUrl(string $base, string $lang, string $regionCode, int $id, string $alias): string
    {
        $lang = $lang === 'en' ? 'en' : 'tr';
        $path = self::COIN_PATHS[$regionCode][$lang] ?? 'anatolian-coins/' . rawurlencode($regionCode);

        return rtrim($base, '/') . '/' . $lang . '/' . $path . '/' . $id . '-' . rawurlencode($alias);
    }

    public static function settlementUrl(string $base, string $lang, string $menuAlias, int $id, string $alias): string
    {
        return rtrim($base, '/') . '/' . $lang . '/' . rawurlencode($menuAlias) . '/' . $id . '-' . rawurlencode($alias);
    }

    public static function htmlToText(?string $html, int $max): string
    {
        $html = (string) $html;
        $html = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $html);
        $html = preg_replace('~<br\s*/?>|</(p|div|li|h[1-6]|tr)>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        $text = trim($text);

        if (mb_strlen($text, 'UTF-8') > $max) {
            $text = rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . '…';
        }

        return $text;
    }

    /**
     * True when a retrieved chunk carries mojibake and must not reach the user.
     *
     * Measured 2026-09-08: ~45% of the chunks numistr_kb returns are UTF-8 text
     * that some earlier ingestion run decoded as GBK before storing, so "Altın"
     * comes back as "Alt谋n" and "Basım" as "Bas谋m". The source Google Docs are
     * clean (verified against Drive), the corruption lives in the stored points,
     * and a clean and a corrupt generation of the same document sit side by side
     * in the collection.
     *
     * Until that corpus is repaired this gate keeps the garbled generation out
     * of the assistant's context. It costs recall on purpose: quoting a term
     * definition back at the reader as "Bas谋m" is worse than saying nothing, and
     * grounding on unreadable text invites the model to "repair" it by guessing
     * - which is the fabrication route closed on 2026-09-06.
     *
     * Detection: this corpus is Turkish and English numismatics prose, so any
     * CJK / Kana / Hangul codepoint is proof of a decoding accident rather than
     * content. The second pattern catches the other classic form, UTF-8 read as
     * Latin-1 ("Ã¼", "Ä±"), which these pipelines have produced before.
     */
    /**
     * Question words, conjunctions, common verb forms and domain-generic nouns.
     * These are not what a question is ABOUT, so the word gate must not require
     * a chunk to contain them.
     *
     * Safety note: extending this list can never weaken the gate. The gate rests
     * on the invented term matching nothing, and an invented term is never a stop
     * word. Every entry added here only stops a genuine question being refused.
     */
    private const GATE_STOPWORDS = 'nedir ne demek demektir demektedir anlama anlaminda gelir gelen geliyor '
        . 'nasil nicin neden hangi hangisi kim kimdir kimler nelerdir nerede neresi neresinde '
        . 'nereden nereye kac kadar bir bu su ve ile veya ama mi mi mu mu icin olan olarak olur '
        . 'olusur olusum olustur olusan var yok vardir yoktur hakkinda bilgi bilgisi acikla anlat '
        . 'anlatir soyle bahset ozetle bulunur bulunan bulundugu denir denilen denen adlandirilir '
        . 'ifade eder edilir edilen kullanilir kullanilan kullanim yapilir yapilan gorulur gorunur '
        . 'gosterir tasir icerir sayilir gecer basildi basilir basilan basim donemde donemi tarihi '
        . 'tarih ornek ornekleri turleri turu cesitleri arasinda uzerinde uzerindeki uzerine ustunde '
        . 'altinda yuzunde yuzune iliskin dair '
        . 'sikke sikkeler sikkenin sikkeye sikkede sikkeyi sikkelerin madeni para '
        . 'what is are was were the a an of does do did mean meaning means how why which who whom '
        . 'where when tell me about explain definition define term terms coin coins coinage on in '
        . 'for and or to with from used call called known refers refer type types example examples';

    /**
     * Extra stop words for the SITE corpus only.
     *
     * Deliberately NOT merged into GATE_STOPWORDS. "stater", "kral", "krallik" are
     * generic topic words in an article search, but in the terminology KB "stater"
     * IS the term being asked about - putting them in the shared list would blind
     * the terminology gate to its own subject matter.
     */
    private const SITE_STOPWORDS = 'antik kenti kentin kentte kent yerlesim yerlesimi yerlesimin '
        . 'kalinti kalintilari kalintilar darphane darphanesi tasvir tasviri tasvirleri '
        . 'sembol sembolu sembolleri birligi hanedan hanedani stater krallik kral kralin '
        . 'onemi merkezi merkez bolge bolgesi bolgesinde ortaya cikisi cikis yansitilmis '
        . 'gorulur anlatilir tarihce tarihcesi kultu kultur ticaret ekonomi '
        . 'ancient city site ruins ruin mint depiction symbol dynasty kingdom king '
        . 'importance region centre center emergence culture trade economy history';

    /** Case- and diacritic-folded form used for all gate comparisons. */
    public static function normaliseForMatch(?string $s): string
    {
        // Fold the Turkish capitals before mb_strtolower, which otherwise turns
        // "İ" into "i" plus a combining dot and breaks the comparison.
        $s = strtr((string) $s, [
            'İ' => 'i', 'I' => 'i', 'Ş' => 's', 'Ğ' => 'g', 'Ü' => 'u',
            'Ö' => 'o', 'Ç' => 'c', 'Â' => 'a', 'Î' => 'i', 'Û' => 'u', 'É' => 'e',
        ]);
        $s = mb_strtolower($s, 'UTF-8');

        return strtr($s, [
            'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u',
            'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u', 'é' => 'e',
        ]);
    }

    /**
     * Turkish is agglutinative, so a question says "sikkesi" where the chunk says
     * "sikke". Comparing on a short prefix absorbs the suffix without needing a
     * stemmer; five characters keeps "drahmi"/"drahmisi" together while still
     * telling "zarkanion" apart from every real word in the terminology corpus.
     *
     * The site corpus needs SEVEN. It is full of ancient place names and invented
     * ones look just like them: measured 2026-09-09, "Xanthoderos" collided with
     * the real city "Xanthos" on the five-letter prefix "xanth" and carried an
     * invented king through the gate. Seven closed it without losing a single real
     * query - Turkish suffixes attach after the root, so a longer prefix still
     * absorbs inflection.
     */
    public static function matchStem(string $token, int $length = 5): string
    {
        return mb_strlen($token, 'UTF-8') > $length ? mb_substr($token, 0, $length, 'UTF-8') : $token;
    }

    /** Content words of a query: what the reader is actually asking about. */
    public static function queryTerms(?string $query, string $corpus = 'kb'): array
    {
        static $stop = [];

        if (!isset($stop[$corpus])) {
            $words = self::GATE_STOPWORDS . ($corpus === 'site' ? ' ' . self::SITE_STOPWORDS : '');
            $stop[$corpus] = array_flip(preg_split('/\s+/', $words) ?: []);
        }

        $words = $stop[$corpus];

        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::normaliseForMatch($query)) ?: [];
        $out   = [];

        foreach ($parts as $p) {
            if ($p === '' || mb_strlen($p, 'UTF-8') < 3) {
                continue;
            }

            // The stem is checked too, so "sikkesi" is dropped along with "sikke".
            // Without that, "zarkanion sikkesi nedir" would match every coin chunk
            // and carry the invented term through the gate (measured, 2026-09-08).
            if (isset($words[$p]) || isset($words[self::matchStem($p)])) {
                continue;
            }

            $out[$p] = true;
        }

        return array_keys($out);
    }

    /** True when this chunk mentions at least one of the query's content words. */
    public static function chunkMentionsTerms(array $terms, string $haystack, int $stemLength = 5): bool
    {
        if (!$terms) {
            return true;   // nothing to judge on - fall back to the score alone
        }

        $hay = self::normaliseForMatch($haystack);

        foreach ($terms as $t) {
            if (mb_strpos($hay, self::matchStem($t, $stemLength), 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The word gate, at query level: can the KB answer this at all?
     *
     * Why a gate and not a threshold. Measured 2026-09-08 over 120 real and 30
     * invented queries: real scores run p5 0.346 / median 0.551, invented ones
     * top out at 0.454. The ranges overlap, so NO threshold separates them -
     * cosine similarity scores topical closeness, not "is this term in the KB".
     * At 0.35 alone, 26 of 30 invented queries still produced an answer.
     *
     * The gate asks a different question: does the retrieved text actually
     * contain the words the reader asked about? "zarkanion" appears in no chunk,
     * so nothing survives and the assistant says it does not know. Results with
     * the gate: invented 0/30 through, real answerable 101/120 (a threshold of
     * 0.45 with no gate answered 86/120, so this is better on both counts).
     *
     * Only words of six characters or more must be found. Shorter ones are
     * generic ("kac", "eder") and requiring them refused genuine questions;
     * requiring every word cost real recall for no gain in safety (measured).
     */
    public static function kbCanAnswer(array $terms, array $chunks, int $stemLength = 5): bool
    {
        $long = [];

        foreach ($terms as $t) {
            if (mb_strlen($t, 'UTF-8') >= 6) {
                $long[] = $t;
            }
        }

        if (!$long) {
            return true;
        }

        $hay = '';

        foreach ($chunks as $c) {
            $hay .= ' ' . ($c['title'] ?? '') . ' ' . ($c['text'] ?? '');
        }

        $hay = self::normaliseForMatch($hay);

        foreach ($long as $t) {
            if (mb_strpos($hay, self::matchStem($t, $stemLength), 0, 'UTF-8') === false) {
                return false;
            }
        }

        return true;
    }

    public static function isCorruptText(?string $text): bool
    {
        $text = (string) $text;

        if ($text === '') {
            return false;
        }

        // CJK ideographs, Kana, Hangul - never legitimate in this corpus.
        if (preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text)) {
            return true;
        }

        // UTF-8 mis-read as Latin-1: a lead byte surfacing as its own character,
        // followed by a continuation-range one. The range covers C2-C5, i.e.
        // U+0080-U+017F, because Turkish needs Latin Extended-A: "ı" is C4 B1
        // and "ş" is C5 9F, so stopping at C3 would let "AltÄ±n" through.
        return (bool) preg_match('/[\x{00C2}-\x{00C5}][\x{0080}-\x{00BF}]/u', $text);
    }

    private static function clampLimit($v, int $default, int $max): int
    {
        $n = (int) ($v ?? $default);

        return max(1, min($n, $max));
    }

    private function lang(?string $lang, string $default): string
    {
        $l = strtolower(trim((string) $lang));

        return in_array($l, ['tr', 'en'], true) ? $l : ($default === 'en' ? 'en' : 'tr');
    }

    private function fvJoin($db, string $fvTable, string $alias, int $fieldId, string $itemCol): string
    {
        return $db->quoteName($fvTable, $alias) . ' ON ' . $db->quoteName($alias . '.item_id')
            . ' = CAST(' . $db->quoteName($itemCol) . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
            . ' AND ' . $db->quoteName($alias . '.field_id') . ' = ' . (int) $fieldId;
    }

    // ======================================================================
    // search_coins / get_variant
    // ======================================================================

    public function searchCoins(array $in, string $lang): array
    {
        $db    = $this->db();
        $lang  = $this->lang($in['lang'] ?? null, $lang);
        $limit = self::clampLimit($in['limit'] ?? null, 5, (int) ($this->config['tools']['result_limit'] ?? 10));

        $allowed = $this->dbHelper()->getAllowedCatIds($db, (int) ($this->constants['ROOT_CAT_ID'] ?? 16));

        if (empty($allowed)) {
            return ['items' => [], 'has_more' => false];
        }

        $q = $db->getQuery(true)
            ->select(['v.article_id', 'v.title_tr', 'v.title_en', 'v.slug', 'v.region_code', 'v.metal', 'v.date_from', 'v.date_to', 'v.mint_name', 'ct.alias'])
            ->from($db->quoteName('o_numistr_variants_public', 'v'))
            ->join('INNER', $db->quoteName('#__content', 'ct') . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . implode(',', array_map('intval', $allowed)) . ')'
                . ' AND ' . $db->quoteName('ct.state') . ' = 1');

        // authority lives in Joomla custom fields (the public view has no authority_name column)
        $authCol = 'NULL';
        $authFid = $this->dbHelper()->fid('authority_name');

        if ($authFid !== null) {
            $q->join('LEFT', $db->quoteName($this->dbHelper()->resolveFieldsValuesTable($db), 'fv_auth')
                . ' ON ' . $db->quoteName('fv_auth.item_id') . ' = CAST(' . $db->quoteName('v.article_id') . ' AS CHAR) COLLATE utf8mb4_unicode_ci'
                . ' AND ' . $db->quoteName('fv_auth.field_id') . ' = ' . (int) $authFid);
            $authCol = $db->quoteName('fv_auth.value');
        }

        $q->select($authCol . ' AS ' . $db->quoteName('authority_name'));

        // mint / material: the view columns are mostly empty, the custom fields carry the data (same as /v1/variants)
        $fvTable  = $this->dbHelper()->resolveFieldsValuesTable($db);
        $mintFid  = $this->dbHelper()->fid('mint_name');
        $matFid   = $this->dbHelper()->fid('material');
        $mintExpr = 'LOWER(' . $db->quoteName('v.mint_name') . ')';
        $metalExpr = 'LOWER(' . $db->quoteName('v.metal') . ')';

        if ($mintFid !== null) {
            $q->join('LEFT', $this->fvJoin($db, $fvTable, 'fv_mint', (int) $mintFid, 'v.article_id'));
            $mintExpr = 'LOWER(COALESCE(NULLIF(' . $db->quoteName('v.mint_name') . ', ""), ' . $db->quoteName('fv_mint.value') . '))';
            $q->select('COALESCE(NULLIF(' . $db->quoteName('v.mint_name') . ', ""), ' . $db->quoteName('fv_mint.value') . ') AS ' . $db->quoteName('mint_eff'));
        } else {
            $q->select($db->quoteName('v.mint_name', 'mint_eff'));
        }

        if ($matFid !== null) {
            $q->join('LEFT', $this->fvJoin($db, $fvTable, 'fv_mat', (int) $matFid, 'v.article_id'));
            $metalExpr = 'LOWER(COALESCE(NULLIF(' . $db->quoteName('v.metal') . ', ""), ' . $db->quoteName('fv_mat.value') . '))';
            $q->select('COALESCE(NULLIF(' . $db->quoteName('v.metal') . ', ""), ' . $db->quoteName('fv_mat.value') . ') AS ' . $db->quoteName('metal_eff'));
        } else {
            $q->select($db->quoteName('v.metal', 'metal_eff'));
        }

        $region = self::normaliseRegion($in['region'] ?? null);

        if ($region !== null) {
            $cands = [$region];

            if ($region === 'cilicia') {
                $cands[] = 'clicia'; // live category alias typo
            }

            $ors = [];

            foreach ($cands as $c) {
                $ors[] = $db->quoteName('v.region_code') . ' LIKE ' . $db->quote($c . '%');
            }

            $q->where('(' . implode(' OR ', $ors) . ')');
        }

        $metal = $this->dbHelper()->normalizeMaterialKey((string) ($in['metal'] ?? ''));

        if ($metal !== null) {
            $variants = $this->dbHelper()->dbMaterialVariantsFor($metal) ?: [$metal];
            $ins      = array_map(function ($m) use ($db) {
                return $db->quote(mb_strtolower($m, 'UTF-8'));
            }, $variants);
            $q->where($metalExpr . ' IN (' . implode(',', $ins) . ')');
        }

        $yf = isset($in['date_from']) && $in['date_from'] !== '' ? (int) $in['date_from'] : null;
        $yt = isset($in['date_to']) && $in['date_to'] !== '' ? (int) $in['date_to'] : null;

        if ($yf !== null || $yt !== null) {
            $from = $yf ?? $yt;
            $to   = $yt ?? $yf;

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            $q->where('(' . $db->quoteName('v.date_to') . ' IS NULL OR ' . $db->quoteName('v.date_to') . ' >= ' . (int) $from . ')');
            $q->where('(' . $db->quoteName('v.date_from') . ' IS NULL OR ' . $db->quoteName('v.date_from') . ' <= ' . (int) $to . ')');
        }

        $mint     = mb_strtolower(trim((string) ($in['mint'] ?? '')), 'UTF-8');
        $mintTry  = [];

        if ($mint !== '') {
            // 1st: as given; 2nd: Latinised prefix fallback (Halikarnassos -> halic -> halicarnassus, Rhodos -> rhod -> rhodes)
            $mintTry[] = '%' . str_replace(' ', '_', $mint) . '%';
            $latin     = self::latinisePlaceName($mint);
            $prefix    = mb_substr($latin, 0, min(4, mb_strlen($latin, 'UTF-8')), 'UTF-8');

            if ($prefix !== '' && mb_strlen($prefix, 'UTF-8') >= 3) {
                $mintTry[] = $prefix . '%';
            }
        }

        $auth = mb_strtolower(trim((string) ($in['authority'] ?? '')), 'UTF-8');

        if ($auth !== '') {
            $q->where('LOWER(' . $authCol . ') LIKE ' . $db->quote('%' . $auth . '%'));
        }

        $free = mb_strtolower(trim((string) ($in['q'] ?? '')), 'UTF-8');

        if ($free !== '') {
            $like = $db->quote('%' . $free . '%');
            $q->where('(LOWER(' . $db->quoteName('v.title_tr') . ') LIKE ' . $like
                . ' OR LOWER(' . $db->quoteName('v.title_en') . ') LIKE ' . $like
                . ' OR ' . $mintExpr . ' LIKE ' . $like
                . ' OR LOWER(' . $authCol . ') LIKE ' . $like . ')');
        }

        $q->order($db->quoteName('v.article_id') . ' ASC')->setLimit($limit + 1);
        $rows = [];

        if (empty($mintTry)) {
            $db->setQuery($q);
            $rows = $db->loadAssocList() ?: [];
        } else {
            foreach ($mintTry as $pattern) {
                $qm = clone $q;
                $qm->where($mintExpr . ' LIKE ' . $db->quote($pattern));
                $db->setQuery($qm);
                $rows = $db->loadAssocList() ?: [];

                if (!empty($rows)) {
                    break;
                }
            }
        }

        $hasMore = count($rows) > $limit;
        $rows    = array_slice($rows, 0, $limit);
        $base    = (string) ($this->config['site_base'] ?? 'https://numistr.org');
        $items   = [];

        foreach ($rows as $r) {
            $items[] = $this->variantRow($r, $lang, $base);
        }

        return ['items' => $items, 'has_more' => $hasMore];
    }

    /**
     * Rough Turkish/Greek -> Latin catalogue spelling for place names (used only as a LIKE-prefix fallback).
     */
    public static function latinisePlaceName(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['ı' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
        $alias = [
            'efes' => 'ephesus', 'bergama' => 'pergamon', 'milet' => 'miletus', 'izmir' => 'smyrna', 'sart' => 'sardis',
            'truva' => 'troy', 'foça' => 'phocaea', 'foca' => 'phocaea', 'iznik' => 'nicaea', 'izmit' => 'nicomedia',
            'antakya' => 'antioch', 'tarsus' => 'tarsus', 'side' => 'side', 'perge' => 'perge', 'bodrum' => 'halicarnassus',
            'datça' => 'cnidus', 'datca' => 'cnidus', 'knidos' => 'cnidus', 'halikarnassos' => 'halicarnassus', 'rodos' => 'rhodes', 'rhodos' => 'rhodes',
        ];

        if (isset($alias[$s])) {
            return $alias[$s];
        }

        $s = str_replace(['kh', 'k', 'ai', 'oi', 'ei'], ['ch', 'c', 'ae', 'oe', 'i'], $s);

        return $s;
    }

    private function variantRow(array $r, string $lang, string $base): array
    {
        $title = $lang === 'en'
            ? ($r['title_en'] ?: ($r['title_tr'] ?: $r['slug']))
            : ($r['title_tr'] ?: ($r['title_en'] ?: $r['slug']));

        return [
            'article_id' => (int) $r['article_id'],
            'title'      => $title,
            'region'     => $r['region_code'],
            'metal'      => $this->dbHelper()->normalizeMaterialKey((string) ($r['metal_eff'] ?? $r['metal'] ?? '')),
            'date_from'  => $r['date_from'] !== null ? (int) $r['date_from'] : null,
            'date_to'    => $r['date_to'] !== null ? (int) $r['date_to'] : null,
            'mint'       => $r['mint_eff'] ?? $r['mint_name'] ?? null,
            'authority'  => $r['authority_name'] ?? null,
            'url'        => self::coinUrl($base, $lang, (string) $r['region_code'], (int) $r['article_id'], (string) ($r['alias'] ?: $r['slug'])),
        ];
    }

    public function getVariant(int $articleId, string $lang): array
    {
        if ($articleId <= 0) {
            return ['error' => 'article_id required'];
        }

        $db      = $this->db();
        $allowed = $this->dbHelper()->getAllowedCatIds($db, (int) ($this->constants['ROOT_CAT_ID'] ?? 16));

        if (empty($allowed)) {
            return ['error' => 'not found'];
        }

        $fv  = $this->dbHelper()->resolveFieldsValuesTable($db);
        $fid = function (string $k) {
            return $this->dbHelper()->fid($k);
        };

        $q = $db->getQuery(true)
            ->select(['v.*', 'ct.alias'])
            ->from($db->quoteName('o_numistr_variants_public', 'v'))
            ->join('INNER', $db->quoteName('#__content', 'ct') . ' ON ' . $db->quoteName('ct.id') . ' = ' . $db->quoteName('v.article_id')
                . ' AND ' . $db->quoteName('ct.catid') . ' IN (' . implode(',', array_map('intval', $allowed)) . ')'
                . ' AND ' . $db->quoteName('ct.state') . ' = 1')
            ->where($db->quoteName('v.article_id') . ' = ' . (int) $articleId);

        $extra = [
            'obv_tr' => $fid('obverse_desc_tr'),
            'rev_tr' => $fid('reverse_desc_tr'),
            'obv_en' => $fid('obverse_desc'),
            'rev_en' => $fid('reverse_desc'),
            'denom'  => $fid('denomination_name'),
            'authority_name' => $fid('authority_name'),
        ];

        foreach ($extra as $alias => $fieldId) {
            if ($fieldId !== null) {
                $q->select($db->quoteName('fv_' . $alias . '.value', $alias))
                  ->join('LEFT', $this->fvJoin($db, $fv, 'fv_' . $alias, (int) $fieldId, 'v.article_id'));
            }
        }

        $db->setQuery($q->setLimit(1));
        $r = $db->loadAssoc();

        if (!$r) {
            return ['error' => 'not found'];
        }

        $base = (string) ($this->config['site_base'] ?? 'https://numistr.org');
        $item = $this->variantRow($r, $lang, $base);

        $item['denomination'] = $r['denom'] ?? null;
        $item['obverse']      = $lang === 'en' ? ($r['obv_en'] ?? $r['obverse_desc'] ?? null) : ($r['obv_tr'] ?? $r['obverse_desc_tr'] ?? null);
        $item['reverse']      = $lang === 'en' ? ($r['rev_en'] ?? $r['reverse_desc'] ?? null) : ($r['rev_tr'] ?? $r['reverse_desc_tr'] ?? null);
        $item['weight']       = $r['weight_nominal'] ?? null;
        $item['diameter']     = $r['diameter_nominal'] ?? null;

        return $item;
    }

    // ======================================================================
    // search_settlements / get_settlement
    // ======================================================================

    private function menuAliasFor(int $catid, string $lang): string
    {
        if (isset($this->menuAliasCache[$catid])) {
            return $this->menuAliasCache[$catid];
        }

        $db    = $this->db();
        $alias = '';

        try {
            $q = $db->getQuery(true)
                ->select($db->quoteName('alias'))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('client_id') . ' = 0')
                ->where($db->quoteName('link') . ' LIKE ' . $db->quote('%option=com_content&view=category%'))
                ->where('(' . $db->quoteName('link') . ' LIKE ' . $db->quote('%&id=' . $catid) . ' OR ' . $db->quoteName('link') . ' LIKE ' . $db->quote('%&id=' . $catid . '&%') . ')')
                ->where($db->quoteName('language') . ' IN (' . $db->quote($lang === 'en' ? 'en-GB' : 'tr-TR') . ', ' . $db->quote('*') . ')')
                ->order($db->quoteName('language') . ' DESC')
                ->setLimit(1);
            $db->setQuery($q);
            $alias = (string) $db->loadResult();
        } catch (\Throwable $e) {
            $alias = '';
        }

        if ($alias === '') {
            try {
                $q = $db->getQuery(true)
                    ->select($db->quoteName('alias'))
                    ->from($db->quoteName('#__categories'))
                    ->where($db->quoteName('id') . ' = ' . (int) $catid);
                $db->setQuery($q);
                $alias = (string) $db->loadResult();
            } catch (\Throwable $e) {
                $alias = '';
            }
        }

        $this->menuAliasCache[$catid] = $alias !== '' ? $alias : ($lang === 'en' ? 'ancient-settlements' : 'antik-yerlesimler');

        return $this->menuAliasCache[$catid];
    }

    private function settlementBaseQuery($db, string $lang)
    {
        [$lo, $hi] = self::SETTLEMENT_CATS[$lang];
        $fv = $this->dbHelper()->resolveFieldsValuesTable($db);

        return $db->getQuery(true)
            ->select(['c.id', 'c.title', 'c.alias', 'c.catid', 'c.introtext', 'fv_loc.value AS loc_id', 'fv_reg.value AS region', 'fv_hc.value AS has_coins'])
            ->from($db->quoteName('#__content', 'c'))
            ->join('LEFT', $this->fvJoin($db, $fv, 'fv_loc', self::FIELD_LOC_ID, 'c.id'))
            ->join('LEFT', $this->fvJoin($db, $fv, 'fv_reg', self::FIELD_REGION, 'c.id'))
            ->join('LEFT', $this->fvJoin($db, $fv, 'fv_hc', self::FIELD_HAS_COINS, 'c.id'))
            ->where($db->quoteName('c.state') . ' = 1')
            ->where($db->quoteName('c.catid') . ' BETWEEN ' . (int) $lo . ' AND ' . (int) $hi);
    }

    public function searchSettlements(array $in, string $lang): array
    {
        $db    = $this->db();
        $lang  = $this->lang($in['lang'] ?? null, $lang);
        $limit = self::clampLimit($in['limit'] ?? null, 5, (int) ($this->config['tools']['result_limit'] ?? 10));
        $q     = $this->settlementBaseQuery($db, $lang);

        $name = mb_strtolower(trim((string) ($in['q'] ?? '')), 'UTF-8');

        if ($name !== '') {
            $q->where('(LOWER(' . $db->quoteName('c.title') . ') LIKE ' . $db->quote('%' . $name . '%')
                . ' OR LOWER(' . $db->quoteName('c.alias') . ') LIKE ' . $db->quote('%' . $name . '%') . ')');
        }

        $region = self::normaliseRegion($in['region'] ?? null);

        if ($region !== null) {
            $q->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.catid'));
            $q->where('(LOWER(' . $db->quoteName('fv_reg.value') . ') LIKE ' . $db->quote('%' . $region . '%')
                . ' OR LOWER(' . $db->quoteName('cat.alias') . ') LIKE ' . $db->quote($region . '%') . ')');
        }

        if (isset($in['has_coins']) && filter_var($in['has_coins'], FILTER_VALIDATE_BOOLEAN)) {
            $q->where($db->quoteName('fv_hc.value') . ' IN (' . $db->quote('1') . ', ' . $db->quote('yes') . ', ' . $db->quote('true') . ')');
        }

        if ($name === '' && $region === null) {
            return ['error' => 'provide q (name) or region'];
        }

        // exact-title first, then alphabetical
        if ($name !== '') {
            $q->order('CASE WHEN LOWER(' . $db->quoteName('c.title') . ') = ' . $db->quote($name) . ' THEN 0 ELSE 1 END, ' . $db->quoteName('c.title') . ' ASC');
        } else {
            $q->order($db->quoteName('c.title') . ' ASC');
        }

        $q->setLimit($limit + 1);
        $db->setQuery($q);
        $rows = $db->loadAssocList() ?: [];

        // Fallbacks for Turkish/Greek spellings: titles are Latin (Knidos -> "Cnidus", Efes -> "Ephesus")
        if (empty($rows) && $name !== '') {
            $latin  = self::latinisePlaceName($name);
            $prefix = mb_substr($latin, 0, min(4, mb_strlen($latin, 'UTF-8')), 'UTF-8');
            $tries  = [];

            if (mb_strlen($prefix, 'UTF-8') >= 3 && $latin !== $name) {
                $tries[] = '(LOWER(' . $db->quoteName('c.title') . ') LIKE ' . $db->quote($prefix . '%')
                    . ' OR LOWER(' . $db->quoteName('c.alias') . ') LIKE ' . $db->quote($prefix . '%') . ')';
            }

            // the article body usually carries the local spelling
            $tries[] = 'LOWER(' . $db->quoteName('c.introtext') . ') LIKE ' . $db->quote('%' . $name . '%');

            foreach ($tries as $cond) {
                $qf = $this->settlementBaseQuery($db, $lang);

                if ($region !== null) {
                    $qf->join('LEFT', $db->quoteName('#__categories', 'cat') . ' ON ' . $db->quoteName('cat.id') . ' = ' . $db->quoteName('c.catid'));
                    $qf->where('(LOWER(' . $db->quoteName('fv_reg.value') . ') LIKE ' . $db->quote('%' . $region . '%')
                        . ' OR LOWER(' . $db->quoteName('cat.alias') . ') LIKE ' . $db->quote($region . '%') . ')');
                }

                $qf->where($cond)->order($db->quoteName('c.title') . ' ASC')->setLimit($limit + 1);
                $db->setQuery($qf);
                $rows = $db->loadAssocList() ?: [];

                if (!empty($rows)) {
                    break;
                }
            }
        }

        $hasMore = count($rows) > $limit;
        $rows    = array_slice($rows, 0, $limit);
        $base    = (string) ($this->config['site_base'] ?? 'https://numistr.org');
        $items   = [];

        foreach ($rows as $r) {
            $items[] = [
                'article_id' => (int) $r['id'],
                'title'      => $r['title'],
                'summary'    => self::htmlToText($r['introtext'], 300),
                'loc_id'     => $r['loc_id'],
                'region'     => $r['region'],
                'has_coins'  => in_array(strtolower((string) $r['has_coins']), ['1', 'yes', 'true'], true),
                'url'        => self::settlementUrl($base, $lang, $this->menuAliasFor((int) $r['catid'], $lang), (int) $r['id'], (string) $r['alias']),
            ];
        }

        return ['items' => $items, 'has_more' => $hasMore, 'lang' => $lang];
    }

    public function getSettlement(array $in, string $lang): array
    {
        $db   = $this->db();
        $lang = $this->lang($in['lang'] ?? null, $lang);
        $id   = (int) ($in['article_id'] ?? 0);
        $loc  = trim((string) ($in['loc_id'] ?? ''));

        if ($id <= 0 && $loc === '') {
            return ['error' => 'article_id or loc_id required'];
        }

        $q = $this->settlementBaseQuery($db, $lang)->select($db->quoteName('c.fulltext'));

        if ($id > 0) {
            $q->where($db->quoteName('c.id') . ' = ' . $id);
        } else {
            $q->where($db->quoteName('fv_loc.value') . ' = ' . $db->quote($loc));
        }

        $db->setQuery($q->setLimit(1));
        $r = $db->loadAssoc();

        if (!$r && $id > 0) {
            // the id may belong to the other language; look up by loc_id in the requested language
            $q2 = $this->settlementBaseQuery($db, $lang === 'en' ? 'tr' : 'en')->where($db->quoteName('c.id') . ' = ' . $id);
            $db->setQuery($q2->setLimit(1));
            $other = $db->loadAssoc();

            if ($other && !empty($other['loc_id'])) {
                return $this->getSettlement(['loc_id' => $other['loc_id'], 'lang' => $lang], $lang);
            }
        }

        if (!$r) {
            return ['error' => 'not found'];
        }

        $base = (string) ($this->config['site_base'] ?? 'https://numistr.org');

        return [
            'article_id' => (int) $r['id'],
            'title'      => $r['title'],
            'loc_id'     => $r['loc_id'],
            'region'     => $r['region'],
            'text'       => self::htmlToText(($r['introtext'] ?? '') . "\n" . ($r['fulltext'] ?? ''), 4000),
            'url'        => self::settlementUrl($base, $lang, $this->menuAliasFor((int) $r['catid'], $lang), (int) $r['id'], (string) $r['alias']),
        ];
    }

    // ======================================================================
    // search_kb (n8n webhook -> Qdrant)
    // ======================================================================

    /**
     * Semantic search over blog + settlement articles (n8n webhook -> Qdrant numistr_site).
     * Returns ['items' => [{title,url,type,lang,score,text}], 'has_more' => false] or ['error' => ...].
     */
    public function searchSite(string $query, string $lang, ?string $type = null, int $limit = 5): array
    {
        $query = trim($query);
        $lang  = $this->lang($lang, 'tr');
        $limit = max(1, min(10, $limit));

        if ($query === '') {
            return ['error' => 'query required'];
        }

        $url    = (string) ($this->config['tools']['site_search_url'] ?? '');
        $secret = (string) ($this->secrets['KB_WEBHOOK_SECRET'] ?? '');

        if ($url === '' || $secret === '') {
            return ['error' => 'site search not configured'];
        }

        $payload = json_encode([
            'query' => mb_substr($query, 0, 500),
            'lang'  => $lang,
            'type'  => in_array($type, ['blog', 'settlements'], true) ? $type : null,
            'limit' => $limit,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-NumisTR-KB: ' . $secret],
            CURLOPT_TIMEOUT        => (int) ($this->config['tools']['kb_timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $http < 200 || $http >= 300) {
            return ['error' => 'site search unreachable (' . ($err !== '' ? $err : 'http ' . $http) . ')'];
        }

        $data = json_decode((string) $raw, true);

        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $min   = (float) ($this->config['tools']['site_search_min_score'] ?? 0.3);
        $items = [];

        $corrupt = 0;
        $scored  = [];

        foreach ((array) ($data['results'] ?? []) as $r) {
            if (!is_array($r) || (float) ($r['score'] ?? 0) < $min) {
                continue;
            }

            // Same mojibake gate as searchKb(). numistr_site has not been
            // measured for corruption; if it is clean this drops nothing.
            if (self::isCorruptText(($r['title'] ?? '') . ' ' . ($r['text'] ?? ''))) {
                $corrupt++;
                continue;
            }

            $scored[] = [
                'title' => (string) ($r['title'] ?? ''),
                'url'   => (string) ($r['url'] ?? ''),
                'type'  => (string) ($r['type'] ?? ''),
                'lang'  => (string) ($r['lang'] ?? $lang),
                'score' => round((float) ($r['score'] ?? 0), 3),
                'text'  => mb_substr((string) ($r['text'] ?? ''), 0, 1200, 'UTF-8'),
            ];
        }

        // ---- the word gate, same idea as searchKb, different constants -------
        // Measured 2026-09-09: this store had NO protection at all. Every one of ten
        // invented subjects came back with five excerpts carrying real article titles
        // and real public URLs - exactly the material for the failure closed on
        // 2026-09-06, where an invented term was answered with genuine site links.
        //
        // A score threshold cannot separate them here either, and the overlap is worse
        // than in the terminology store: invented queries top out at 0.600 while real
        // ones start at 0.481, so a fifth of genuine questions sit below the invented
        // ceiling. "Pergonaut antik kenti kalintilari" scores 0.600 and returns Perge.
        //
        // Seven-character stems, not five: see matchStem(). Site-specific stop words:
        // see SITE_STOPWORDS.
        $terms = self::queryTerms($query, 'site');
        $items = [];
        $gated = 0;

        if (self::kbCanAnswer($terms, $scored, 7)) {
            foreach ($scored as $c) {
                if (!self::chunkMentionsTerms($terms, $c['title'] . ' ' . $c['text'], 7)) {
                    $gated++;
                    continue;
                }

                $items[] = $c;
            }
        } else {
            $gated = count($scored);
        }

        $out = ['items' => $items, 'has_more' => false];

        if ($gated > 0) {
            $out['gate_dropped'] = $gated;
        }

        if ($corrupt > 0) {
            $out['corrupt_dropped'] = $corrupt;
        }

        return $out;
    }

    /**
     * Semantic search over the terminology KB (n8n webhook -> Qdrant numistr_kb).
     * Returns ['items' => [{title,url,text,score,lang}], 'result_count' => n] or ['error' => ...].
     *
     * The webhook returns RAW CHUNKS on purpose. It used to return a prose answer
     * written by a second LLM, which answered from its own memory whenever retrieval
     * came back empty -- and retrieval was in fact always empty, because the node read
     * payload.text while the ingestion writes payload.chunk_text. The caller had no way
     * to tell an invented answer from a retrieved one, so invented numismatics reached
     * users labelled as the NumisTR terminology database. Never reintroduce a path that
     * accepts a pre-written answer from this endpoint.
     */
    public function searchKb(string $query, string $lang, string $sessionId = ''): array
    {
        $query = trim($query);
        $lang  = $this->lang($lang, 'tr');

        if ($query === '') {
            return ['error' => 'query required'];
        }

        $url    = (string) ($this->config['tools']['kb_webhook_url'] ?? '');
        $secret = (string) ($this->secrets['KB_WEBHOOK_SECRET'] ?? '');

        if ($url === '' || $secret === '') {
            return ['error' => 'knowledge base not configured'];
        }

        $payload = json_encode([
            'query'      => mb_substr($query, 0, 500),
            'language'   => $lang,
            'session_id' => $sessionId !== '' ? $sessionId : ('assistant-' . substr(sha1((string) $this->messageId . $query), 0, 12)),
            // numistr_kb holds 2,509 terminology points AND 21,937 settlement
            // points. Ask for terminology only: settlements were taking 30% of
            // the eight result slots, and because approximate search degrades as
            // the collection grows, the real term was sometimes missed entirely -
            // "Exergue" and "Hekte" returned NOTHING while their chunks sat in
            // the collection. Settlement content is served better by search_site,
            // which has real public URLs; the copies here carry private Google
            // Doc links that this method blanks, so they can never be cited.
            // Measured 2026-09-08: settlement share 30% -> 0%, real queries with
            // a usable hit 75/80 -> 80/80, invented queries matching 6/10 -> 2/10.
            'scope'      => 'terminology',
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-NumisTR-KB: ' . $secret],
            CURLOPT_TIMEOUT        => (int) ($this->config['tools']['kb_timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $http < 200 || $http >= 300) {
            return ['error' => 'knowledge base unreachable (' . ($err !== '' ? $err : 'http ' . $http) . ')'];
        }

        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            return ['error' => 'knowledge base returned non-json'];
        }

        // tolerate the common n8n shape where the payload is wrapped in a list
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        if (!isset($data['results']) || !is_array($data['results'])) {
            // Old contract (or an n8n error payload). Deliberately treated as "nothing
            // retrieved" rather than falling back to any 'answer' field.
            return ['items' => [], 'result_count' => 0];
        }

        $min     = (float) ($this->config['tools']['kb_search_min_score'] ?? 0.35);
        $scored  = [];
        $corrupt = 0;

        foreach ($data['results'] as $r) {
            if (!is_array($r) || (float) ($r['score'] ?? 0) < $min) {
                continue;
            }

            $text  = trim((string) ($r['text'] ?? ''));
            $title = (string) ($r['title'] ?? '');

            if ($text === '') {
                continue;
            }

            // Guard against the read-path mojibake fixed in the n8n workflow on
            // 2026-09-08. It drops nothing while that fix holds; if the fix ever
            // regresses the assistant refuses the garbled text instead of
            // quoting it back at the reader. See isCorruptText().
            if (self::isCorruptText($title . ' ' . $text)) {
                $corrupt++;
                continue;
            }

            $scored[] = [
                'title' => $title,
                // The webhook returns the term's source document (a private Google Doc).
                // Never surface it: KB chunks are attributed to the public glossary page
                // by the caller. Blanked here so no future caller can leak it.
                'url'   => '',
                'text'  => mb_substr($text, 0, 1200, 'UTF-8'),
                'lang'  => (string) ($r['lang'] ?? $lang),
                'score' => round((float) ($r['score'] ?? 0), 3),
            ];
        }

        // ---- the word gate (see kbCanAnswer) --------------------------------
        // The score alone cannot tell a real term from an invented one, so ask
        // instead whether the retrieved text contains the words the reader used.
        $terms = self::queryTerms($query);

        if (!self::kbCanAnswer($terms, $scored)) {
            return [
                'items'        => [],
                'result_count' => 0,
                // The caller must be able to tell "nothing retrieved" from
                // "retrieved, but about something else" when reading tool logs.
                'gated'        => 'query terms absent from retrieved text',
            ];
        }

        $items = [];
        $gated = 0;

        foreach ($scored as $c) {
            if (!self::chunkMentionsTerms($terms, $c['title'] . ' ' . $c['text'])) {
                $gated++;
                continue;
            }

            $items[] = $c;
        }

        $out = ['items' => $items, 'result_count' => count($items)];

        // Surfaced so thin recall is legible in the tool log as a deliberate
        // refusal rather than as a retrieval failure.
        if ($gated > 0) {
            $out['gate_dropped'] = $gated;
        }

        if ($corrupt > 0) {
            $out['corrupt_dropped'] = $corrupt;
        }

        return $out;
    }
}
