<?php
/**
 * The assistant must not hand out links to pages that do not exist.
 *
 * Measured 2026-09-09: asked about a mint that does not exist, it answered
 * honestly and then offered /tr/yerlesimleri and /tr/sikkeler to look in. Both
 * 404. On the settlement route it produced /tr/antik-yerlesimleri - the real
 * alias is /tr/antik-yerlesimler, one letter apart. Rule 4 of the system prompt
 * already forbade inventing URLs, so this is enforced in code instead.
 */

require_once $root . '/helpers/AuthHelper.php';
require_once $root . '/helpers/ResponseHelper.php';
require_once $root . '/helpers/Assistant/AssistantSettings.php';
require_once $root . '/helpers/Assistant/LLMClient.php';
require_once $root . '/helpers/Assistant/AssistantQuota.php';
require_once $root . '/helpers/Assistant/AssistantAbuse.php';
require_once $root . '/helpers/Assistant/AssistantCoreKb.php';
require_once $root . '/controllers/AssistantController.php';

$C   = 'AssistantController';
$cfg = include $root . '/config/assistant.php';

$ok  = 'https://numistr.org/tr/numizmatik-karsiliklar';
$coin = 'https://numistr.org/tr/anatolian-coins/pisidia-coins/3285-selge-babelon-1907-871';

// ---- url normalisation ----
check('normalise: www ignored', $C::normaliseSiteUrl('https://www.numistr.org/tr/') === $C::normaliseSiteUrl('https://numistr.org/tr'));
check('normalise: trailing punctuation dropped', $C::normaliseSiteUrl('https://numistr.org/tr/blog.') === 'numistr.org/tr/blog');
check('normalise: scheme dropped', $C::normaliseSiteUrl('http://numistr.org/tr') === 'numistr.org/tr');

// ---- the measured failures ----
$answer = 'Bilgi bulunamadi. Su sayfalara bakabilirsiniz: https://numistr.org/tr/yerlesimleri ve https://numistr.org/tr/sikkeler';
$out    = $C::dropUnknownSiteLinks($answer, [$ok]);
check('invented bare urls removed', mb_strpos($out, 'yerlesimleri') === false && mb_strpos($out, '/tr/sikkeler') === false);
check('sentence survives removal', mb_strpos($out, 'Bilgi bulunamadi.') === 0);

// one letter off from the real alias must not slip through
$out = $C::dropUnknownSiteLinks('Bakiniz https://numistr.org/tr/antik-yerlesimleri sayfasi', ['https://numistr.org/tr/antik-yerlesimler']);
check('near-miss alias removed', mb_strpos($out, 'antik-yerlesimleri') === false);

// ---- allowed urls survive ----
$out = $C::dropUnknownSiteLinks('Detay: ' . $coin, [$coin]);
check('tool-returned url kept', mb_strpos($out, $coin) !== false);
$out = $C::dropUnknownSiteLinks('Sozluk: ' . $ok, [$ok]);
check('landing url kept', mb_strpos($out, $ok) !== false);

// ---- markdown links keep their label ----
$out = $C::dropUnknownSiteLinks('[Sikkeler Listesi](https://numistr.org/tr/sikkeler) sayfasina bakin', [$ok]);
check('markdown link stripped to its text', mb_strpos($out, 'Sikkeler Listesi') !== false && mb_strpos($out, 'http') === false);
$out = $C::dropUnknownSiteLinks('[Numizmatik](' . $ok . ') sayfasi', [$ok]);
check('allowed markdown link left intact', mb_strpos($out, '](' . $ok . ')') !== false);

// ---- foreign domains are not ours to police ----
$out = $C::dropUnknownSiteLinks('Kaynak: https://www.britishmuseum.org/collection', [$ok]);
check('external url untouched', mb_strpos($out, 'britishmuseum.org') !== false);

// ---- config list must not contain anything unverified ----
$landing = $cfg['landing_urls'] ?? [];
check('landing list exists for both languages', isset($landing['tr'], $landing['en']));
check(
    'english glossary alias is NOT listed (it 404s)',
    !in_array('https://numistr.org/en/numizmatik-karsiliklar', $landing['en'] ?? [], true)
);
$all = array_merge($landing['tr'] ?? [], $landing['en'] ?? []);
$bad = array_filter($all, static function ($u) {
    return !preg_match('~^https://numistr\.org/(tr|en)(/[a-z0-9-]+)?$~', $u);
});
check('landing urls are plain site pages', $bad === [], implode(',', $bad));

// ---- degenerate ----
check('empty answer untouched', $C::dropUnknownSiteLinks('', [$ok]) === '');
check('no allowed urls strips all ours', mb_strpos($C::dropUnknownSiteLinks('Bak ' . $ok, []), 'http') === false);
