<?php
/**
 * Terminology sources, one row per term (1.27.0).
 *
 * Until 1.26 every terminology chunk the knowledge base returned was attributed to
 * the public glossary page as ONE source, carrying all the [n] numbers. Asked
 * "deka drahmi nedir" on 2026-11-22, the app listed [1, 2, 3, 4, 5, 6, 7, 8] behind
 * a single link - and that page (110 short entries) does not mention the
 * dekadrachm at all, so the reader had nothing to check the answer against. The
 * English glossary page carries no terms whatsoever (it is a data-field table).
 *
 * Now each term is its own source row, and a row links to the glossary only when
 * the term is actually on that page: the link is a text fragment
 * (#:~:text=...) so the browser scrolls to the entry. A term that is not on the
 * page is still listed, without a link; the client labels it as the NumisTR
 * terminology database. The KB's own source documents are private Google Docs
 * and are never surfaced (see NumisTRAssistantTools::searchKb).
 *
 * Which terms are on the page: config/glossary_terms.php, generated from the live
 * page by scripts/asistan_sozluk_terimleri.py in the main repository.
 */

defined('_JEXEC') or die;

final class NumisTRAssistantGlossary
{
    /** @var array<string,array<string,string>>|null */
    private static $terms = null;

    /**
     * Same rule as normal() in scripts/asistan_sozluk_terimleri.py: Turkish
     * dotted/dotless I, lower case, quotes dropped, whitespace collapsed.
     */
    public static function normalise(string $s): string
    {
        $s = str_replace(['İ', 'I'], ['i', 'ı'], $s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/["“”\'’]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /**
     * Terms on the glossary page for $lang: normalised name => the entry's text
     * as printed on the page.
     *
     * @param array<string,array<string,string>>|null $override tests only
     * @return array<string,string>
     */
    public static function pageTerms(string $lang, ?array $override = null): array
    {
        if ($override !== null) {
            return (array) ($override[$lang] ?? []);
        }

        if (self::$terms === null) {
            $file        = dirname(__DIR__, 2) . '/config/glossary_terms.php';
            $loaded      = is_file($file) ? include $file : [];
            self::$terms = is_array($loaded) ? $loaded : [];
        }

        return (array) (self::$terms[$lang] ?? []);
    }

    /**
     * Link for a term, or '' when the glossary page does not carry it.
     *
     * Text fragments need '-', ',' and '&' percent-encoded; rawurlencode leaves
     * the hyphen alone ("Altın (Gold - AV)"), hence the extra replace.
     */
    public static function termUrl(string $title, string $lang, string $glossaryUrl, ?array $override = null): string
    {
        if ($glossaryUrl === '' || trim($title) === '') {
            return '';
        }

        $terms = self::pageTerms($lang, $override);
        $key   = self::normalise($title);

        if (!isset($terms[$key])) {
            return '';
        }

        return $glossaryUrl . '#:~:text=' . str_replace('-', '%2D', rawurlencode($terms[$key]));
    }

    /**
     * One source row per distinct term, in order of first appearance.
     *
     * $items are KB chunks ['title' => ..., ...]; $refs[i] is the [n] number the
     * caller gave chunk i in the model's context (null when the route does not
     * number its context). Several chunks of one term - and the English copy of a
     * Turkish term, which the KB search returns under the same title - collapse
     * into one row carrying all their numbers.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,int|null>            $refs
     * @return array<string,array<string,mixed>> keyed 'term:<normalised title>'
     */
    public static function termSources(array $items, array $refs, string $lang, string $glossaryUrl, ?array $override = null): array
    {
        $rows = [];

        foreach (array_values($items) as $i => $it) {
            $title = trim((string) ($it['title'] ?? ''));

            if ($title === '') {
                $title = $lang === 'en' ? 'Numismatic term' : 'Numizmatik terim';
            }

            $key = 'term:' . self::normalise($title);

            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'title' => $title,
                    'url'   => self::termUrl($title, $lang, $glossaryUrl, $override),
                    'kind'  => 'term',
                ];
            }

            $n = $refs[$i] ?? null;

            if ($n !== null) {
                $rows[$key]['refs'][] = (int) $n;
            }
        }

        return $rows;
    }
}
