<?php
/**
 * @package     plg_content_numistr
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * B14 (2026-10-18) — blog yazısındaki sikke figürleri kısa koddan, sayfa çizilirken kurulur:
 *   {numistr_gorsel variant=12764 gorsel=38282,38283}
 * Görsel ve katalog verisi o anda DB'den okunur; lisans kapısı her gösterimde işler (yalnız `license_ok` = 1 ve
 * kaynağı erişilebilir görsel). Gösterilebilir görsel kalmazsa ya da sikke yayımda değilse figür düşer (yorum satırı kalır).
 * Önceden figür HTML'i makaleye donmuş olarak gömülüyordu: lisans sonradan kapansa da görünmeye devam ediyordu.
 * Site dışı istemcide (yönetim, API, Smart Search dizinleme) kod yalnız silinir — DB okunmaz.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

require_once __DIR__ . '/helper.php';

class PlgContentNumistr extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    /** @var \Joomla\Database\DatabaseDriver */
    protected $db;

    /** @var array istek içi önbellek: variant => kayıt|null */
    private $kayitlar = array();

    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        if (!is_object($article) || !isset($article->text)
            || strpos((string) $article->text, '{' . PlgNumistrContentHelper::KOD) === false) {
            return;
        }

        if (!$this->app || !$this->app->isClient('site')) {
            $article->text = PlgNumistrContentHelper::degistir($article->text, function () {
                return '';
            });

            return;
        }

        $article->text = PlgNumistrContentHelper::degistir($article->text, function ($variant, array $ids) {
            return $this->figur($variant, $ids);
        });
    }

    private function figur($variant, array $ids)
    {
        $yorum = '<!-- ' . PlgNumistrContentHelper::KOD . ' ' . (int) $variant . ': %s -->';

        try {
            $k = $this->kayit($variant);

            if ($k === null) {
                return sprintf($yorum, 'sikke yayımda değil');
            }

            $secilen = PlgNumistrContentHelper::secilenler($ids, $this->gorseller($ids), $variant);

            if (!$secilen) {
                return sprintf($yorum, 'gösterilebilir görsel yok');
            }

            return PlgNumistrContentHelper::figurHtml($k['kayit'], $k['url'], $secilen, rtrim(Uri::root(), '/'));
        } catch (\Throwable $e) {
            return sprintf($yorum, 'hata');   // figür yoksa yazı yine açılır (lisans açısından güvenli taraf)
        }
    }

    private function vt()
    {
        return $this->db ?: Factory::getContainer()->get(DatabaseInterface::class);
    }

    /** @return array|null ['kayit' => PlgNumistrContentHelper::kayit(), 'url' => sikke sayfası] */
    private function kayit($variant)
    {
        $variant = (int) $variant;

        if (array_key_exists($variant, $this->kayitlar)) {
            return $this->kayitlar[$variant];
        }

        $db = $this->vt();
        $c  = $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(array('id', 'title', 'alias', 'catid', 'state')))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = ' . $variant))->loadAssoc();

        if (!$c || (int) $c['state'] !== 1) {
            return $this->kayitlar[$variant] = null;
        }

        $v = $db->setQuery($db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('o_numistr_variants_public'))
            ->where($db->quoteName('article_id') . ' = ' . $variant)
            ->setLimit(1))->loadAssoc() ?: array();

        $alanlar = array();
        $rows    = $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(array('f.name', 'fv.value')))
            ->from($db->quoteName('#__fields_values', 'fv'))
            ->join('INNER', $db->quoteName('#__fields', 'f') . ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('fv.field_id'))
            ->where($db->quoteName('fv.item_id') . ' = ' . $db->quote((string) $variant))
            ->where($db->quoteName('f.context') . ' = ' . $db->quote('com_content.article'))
            ->whereIn($db->quoteName('f.name'), PlgNumistrContentHelper::ALANLAR, ParameterType::STRING))->loadAssocList() ?: array();

        foreach ($rows as $r) {
            if (!array_key_exists($r['name'], $alanlar)) {
                $alanlar[$r['name']] = $r['value'];
            }
        }

        // TR sikke `*` dilli; numistrseo'daki gibi tr-TR yolu (kategori yolu Joomla'dan, bölge istisnası tutulmaz)
        $url = Route::link('site', RouteHelper::getArticleRoute($variant . ':' . $c['alias'], (int) $c['catid'], 'tr-TR'),
            false, Route::TLS_IGNORE, true);

        return $this->kayitlar[$variant] = array(
            'kayit' => PlgNumistrContentHelper::kayit($v, $alanlar, (string) $c['title']),
            'url'   => $url,
        );
    }

    private function gorseller(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (!$ids) {
            return array();
        }

        $db = $this->vt();

        return $db->setQuery($db->getQuery(true)
            ->select($db->quoteName(array('image_id', 'coin_id', 'image_type', 'source_credit', 'license', 'license_ok',
                'source_unavailable')))
            ->from($db->quoteName('coins_images'))
            ->where($db->quoteName('image_id') . ' IN (' . implode(',', $ids) . ')'))->loadAssocList() ?: array();
    }
}
