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

        // canonical çoğu zaman hreflang tr-TR ile aynı adres; addHeadLink adres başına tek etiket tuttuğu için (1.0.0'da canonical
        // ezildi) canonical ve x-default dil süzgeci eklentisi gibi özel etiketle basılır
        $doc->addCustomTag('<link href="' . htmlspecialchars($this->es['canonical'], ENT_QUOTES, 'UTF-8') . '" rel="canonical">');

        foreach ($this->es['alternates'] as $hreflang => $url) {
            $doc->addHeadLink(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), 'alternate', 'rel', array('hreflang' => $hreflang));
        }

        if ($this->es['xdefault']) {
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

        // aynı coin-id'li tüm yayındaki kopyalar (iki dil, kendisi dahil) — karşılıklılık denetimi için
        $hepsi = $db->setQuery($db->getQuery(true)
            ->select('c.id, c.title, c.alias, c.catid, c.language')
            ->from($db->quoteName('#__content', 'c'))
            ->join('INNER', $db->quoteName('#__fields_values', 'v') . ' ON v.item_id = CAST(c.id AS CHAR) COLLATE utf8mb4_unicode_ci')
            ->where('v.field_id = ' . $fid)
            ->where('v.value = ' . $db->quote($coin))
            ->where('c.state = 1')
            ->where('c.language IN (' . implode(',', array_map(array($db, 'quote'), array('*', 'tr-TR', 'en-GB'))) . ')'))->loadAssocList() ?: array();

        $adaylar = array_values(array_filter($hepsi, function ($a) use ($karsi, $id) {
            return (int) $a['id'] !== $id && in_array($a['language'], $karsi, true);
        }));
        $es = PlgNumistrSeoHelper::esSec($adaylar, (string) $cur->title);
        // 1.0.2: hreflang yalnız karşılıklı çiftte — mükerrer EN kopyalardan (822 grup) seçilmeyen kopya TR'yi gösterse de TR onu
        // göstermez; tek yönlü hreflang arama motorunca yok sayılır, basılmaz (canonical ve dil değiştirici kalır)
        $karsilikli = $es !== null && PlgNumistrSeoHelper::karsilikliMi($hepsi, $es, (int) $cur->id);
        $ben = $this->url((int) $cur->id, (string) $cur->alias, (int) $cur->catid, $benim);
        // dil değiştirici: kendi dili de verilir — TR kopya /en/ yolunda açıldığında TR bağlantısı da sikkede kalsın
        $out = array('canonical' => $ben, 'alternates' => array(), 'xdefault' => null,
            'switcher' => array($benim === 'tr-TR' ? 'TR' : 'EN' => $ben));

        if ($es) {
            $esDil = $benim === 'tr-TR' ? 'en-GB' : 'tr-TR';
            $o     = $this->url((int) $es['id'], (string) $es['alias'], (int) $es['catid'], $esDil);
            $tr    = $benim === 'tr-TR' ? $ben : $o;
            $out['switcher'][$esDil === 'en-GB' ? 'EN' : 'TR'] = $o;

            if ($karsilikli) {
                $out['alternates'] = array('tr-TR' => $tr, 'en-GB' => $benim === 'en-GB' ? $ben : $o);
                $out['xdefault']   = $tr;
            }
        }

        return $out;
    }

    private function url(int $id, string $alias, int $catid, string $lang)
    {
        return Route::link('site', RouteHelper::getArticleRoute($id . ':' . $alias, $catid, $lang), false, Route::TLS_IGNORE, true);
    }
}
