<?php
/**
 * @package     plg_system_numistrseo
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * M4-b / M4-c (ADR-011, 2026-10-16) — sikke sayfalarında TR ↔ EN eşi `coin-id` alanından bulunur:
 *  - <link rel="canonical"> sayfanın kendi dilindeki adresi (TR kopya `*` dilli olduğu için /en/ yolunda da açılıyor →
 *    yinelenen içerik; canonical TR yolunu gösterir),
 *  - <link rel="alternate" hreflang> tr-TR / en-GB / x-default (eş varsa),
 *  - dil değiştirici (YOOtheme `mod-languages`) karşı dilde ana sayfa yerine aynı sikkenin kopyasına gider.
 * Joomla ilişkilendirmesi (#__associations) KULLANILMAZ: TR sikkeler `*` dilli, Joomla `*` makaleyi ilişkilendiremez;
 * tr-TR'ye geçmek mevcut /en/…/<TR kimliği> adreslerini 404 yapar (ölçüldü). Dil ve veri değişmez.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Site\Helper\RouteHelper;

require_once __DIR__ . '/helper.php';

class PlgSystemNumistrseo extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    /** @var \Joomla\Database\DatabaseDriver */
    protected $db;

    /** @var array|null ['canonical' => url, 'alternates' => [hreflang => url], 'xdefault' => url|null, 'switcher' => [KOD => url]] */
    private $es = null;

    public function onAfterDispatch()
    {
        if (!$this->app->isClient('site') || $this->app->getDocument()->getType() !== 'html') {
            return;
        }

        $in = $this->app->getInput();

        if ($in->getCmd('option') !== 'com_content' || $in->getCmd('view') !== 'article' || $in->getInt('id') <= 0) {
            return;
        }

        try {
            $this->es = $this->hesapla($in->getInt('id'));
        } catch (\Throwable $e) {
            $this->es = null;   // SEO etiketi yoksa sayfa yine çalışır
        }
    }

    public function onBeforeCompileHead()
    {
        if (!$this->es) {
            return;
        }

        $doc = $this->app->getDocument();

        foreach ($doc->_links as $href => $l) {
            if (($l['relation'] ?? '') === 'canonical'
                || (($l['relation'] ?? '') === 'alternate' && isset($l['attribs']['hreflang']))) {
                unset($doc->_links[$href]);
            }
        }

        $doc->addHeadLink(htmlspecialchars($this->es['canonical'], ENT_QUOTES, 'UTF-8'), 'canonical');

        foreach ($this->es['alternates'] as $hreflang => $url) {
            $doc->addHeadLink(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), 'alternate', 'rel', array('hreflang' => $hreflang));
        }

        if ($this->es['xdefault']) {
            // addHeadLink adres başına tek etiket tutar (x-default TR adresiyle aynı) → dil süzgeci eklentisi gibi özel etiket
            $doc->addCustomTag('<link href="' . htmlspecialchars($this->es['xdefault'], ENT_QUOTES, 'UTF-8') . '" rel="alternate" hreflang="x-default">');
        }
    }

    public function onAfterRender()
    {
        if (!$this->es || empty($this->es['switcher'])) {
            return;
        }

        $this->app->setBody(PlgNumistrSeoHelper::dilDegistirici($this->app->getBody(), $this->es['switcher']));
    }

    private function hesapla(int $id)
    {
        $db  = $this->db ?: \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $fid = (int) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('name') . ' = ' . $db->quote('coin-id'))
            ->where($db->quoteName('context') . ' = ' . $db->quote('com_content.article')))->loadResult();

        if ($fid <= 0) {
            return null;
        }

        $cur = $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(array('id', 'title', 'alias', 'catid', 'language', 'state')))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = ' . $id))->loadObject();

        if (!$cur || (int) $cur->state !== 1) {
            return null;
        }

        $coin = trim((string) $db->setQuery($db->getQuery(true)
            ->select($db->quoteName('value'))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = ' . $fid)
            ->where($db->quoteName('item_id') . ' = ' . $db->quote((string) $id)))->loadResult());

        if ($coin === '') {
            return null;   // sikke değil (coin-id yalnız sikkelerde)
        }

        if ($cur->language === 'en-GB') {
            $benim = 'en-GB';
            $karsi = array('*', 'tr-TR');
        } elseif ($cur->language === '*' || $cur->language === 'tr-TR') {
            $benim = 'tr-TR';
            $karsi = array('en-GB');
        } else {
            return null;
        }

        $adaylar = $db->setQuery($db->getQuery(true)
            ->select('c.id, c.title, c.alias, c.catid, c.language')
            ->from($db->quoteName('#__content', 'c'))
            ->join('INNER', $db->quoteName('#__fields_values', 'v') . ' ON v.item_id = CAST(c.id AS CHAR) COLLATE utf8mb4_unicode_ci')
            ->where('v.field_id = ' . $fid)
            ->where('v.value = ' . $db->quote($coin))
            ->where('c.state = 1')
            ->where('c.id <> ' . $id)
            ->where('c.language IN (' . implode(',', array_map(array($db, 'quote'), $karsi)) . ')'))->loadAssocList() ?: array();

        $es  = PlgNumistrSeoHelper::esSec($adaylar, (string) $cur->title);
        $ben = $this->url((int) $cur->id, (string) $cur->alias, (int) $cur->catid, $benim);
        $out = array('canonical' => $ben, 'alternates' => array(), 'xdefault' => null, 'switcher' => array());

        if ($es) {
            $esDil = $benim === 'tr-TR' ? 'en-GB' : 'tr-TR';
            $o     = $this->url((int) $es['id'], (string) $es['alias'], (int) $es['catid'], $esDil);
            $tr    = $benim === 'tr-TR' ? $ben : $o;
            $out['alternates'] = array('tr-TR' => $tr, 'en-GB' => $benim === 'en-GB' ? $ben : $o);
            $out['xdefault']   = $tr;
            $out['switcher']   = array($esDil === 'en-GB' ? 'EN' : 'TR' => $o);
        }

        return $out;
    }

    private function url(int $id, string $alias, int $catid, string $lang)
    {
        return Route::link('site', RouteHelper::getArticleRoute($id . ':' . $alias, $catid, $lang), false, Route::TLS_IGNORE, true);
    }
}
