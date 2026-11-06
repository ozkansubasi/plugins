<?php
/**
 * @package     plg_webservices_numistr
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * 1.24.2 (2026-11-07) — blog yazısındaki sikke figürü kısa kodu (`{numistr_gorsel variant=… gorsel=…}`, B14) API'de.
 *
 * Sorun: `/v1/articles/{id}` yazı metnini ham döndürüyordu; içerik eklentileri API'de çalışmadığı için mobil uygulama kısa kodu
 * düz metin olarak gösteriyordu (görselli her yazıda). Çözüm: yazı detayında yalnız plg_content_numistr (1.2.0+) doğrudan
 * çağrılır — başka içerik eklentisi tetiklenmez (emailcloak vb. API çıktısına betik eklemesin). Eklenti yoksa, kapalıysa ya da hata
 * verirse kısa kod yalnız silinir: istemciye ASLA ham kod gitmez. Liste/öne çıkan özetlerinde kod her zaman silinir.
 */

defined('_JEXEC') or die;

class ArticleFigureHelper
{
    const KOD = 'numistr_gorsel';

    /** Kodu tek başına saran <p> da gider (içerik eklentisinin `degistir` kalıbıyla aynı); kalan tekil kodlar da. */
    const KALIP = '~<p\b[^>]*>(?:\s|&nbsp;|\x{00A0})*\{numistr_gorsel\b[^}]*\}(?:\s|&nbsp;|\x{00A0})*</p>|\{numistr_gorsel\b[^}]*\}~u';

    public static function hasCodes(string $text): bool
    {
        return strpos($text, '{' . self::KOD) !== false;
    }

    /** Bütün kısa kodları siler. Düzenli ifade hata verirse metin aynen döner (yine de kod kalmaz diye ikinci geçiş). */
    public static function stripCodes(string $text): string
    {
        if (!self::hasCodes($text)) {
            return $text;
        }

        $out = preg_replace(self::KALIP, '', $text);

        if (!is_string($out)) {
            $out = str_replace('{' . self::KOD, '{', $text);   // UTF-8 bozuksa: kodu en azından etkisizleştir
        }

        return $out;
    }

    /**
     * Yazı detayı için: figürleri içerik eklentisiyle kurar, kalan her kodu siler.
     *
     * @param   callable|null  $bootPlugin  function (): ?object — testte enjekte edilir; null ise Joomla'dan önyüklenir
     */
    public static function render(string $content, int $id, string $language, ?callable $bootPlugin = null): string
    {
        if (!self::hasCodes($content)) {
            return $content;
        }

        try {
            $plugin = $bootPlugin ? $bootPlugin() : self::bootContentPlugin();

            if ($plugin !== null && method_exists($plugin, 'onContentPrepare')) {
                $item   = (object) array('id' => $id, 'text' => $content, 'language' => $language);
                $params = class_exists('Joomla\Registry\Registry') ? new \Joomla\Registry\Registry() : new \stdClass();
                $plugin->onContentPrepare('com_content.article', $item, $params, 0);

                if (isset($item->text) && is_string($item->text)) {
                    $content = $item->text;
                }
            }
        } catch (\Throwable $e) {
            // figür kurulamazsa yazı yine açılır; aşağıda kod silinir
        }

        return self::stripCodes($content);
    }

    /** plg_content_numistr yalnız ETKİNSE önyüklenir; aksi hâlde null (kod silinir). */
    private static function bootContentPlugin(): ?object
    {
        if (!class_exists('Joomla\CMS\Plugin\PluginHelper')
            || !\Joomla\CMS\Plugin\PluginHelper::isEnabled('content', 'numistr')) {
            return null;
        }

        $app = \Joomla\CMS\Factory::getApplication();

        if (!method_exists($app, 'bootPlugin')) {
            return null;
        }

        $plugin = $app->bootPlugin('numistr', 'content');

        return is_object($plugin) ? $plugin : null;
    }
}
