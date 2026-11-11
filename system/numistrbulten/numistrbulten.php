<?php
/**
 * @package     NumisTR E-Bülten
 * @subpackage  plg_system_numistrbulten
 * @version     1.0.0
 * @copyright   Copyright (C) 2026 NumisTR. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * BACKLOG C9 — sitedeki e-bülten formu → esemtipi.com MailWizz listesi (TR / EN). Liste çift onaylıdır;
 * onay e-postasını MailWizz gönderir, aylık bülten MailWizz + Amazon SES ile gider. API anahtarı yalnız eklenti
 * ayarında (sunucuda) durur, sayfaya çıkmaz.
 *
 * Uç (com_ajax):
 *   POST /index.php?option=com_ajax&plugin=numistrbulten&format=raw&task=subscribe
 *   girdi : email · onay=1 · dil=tr|en · web_site (tuzak alan, boş olmalı)
 *   yanıt : {"ok":true}  |  {"ok":false,"hata":"gecersiz_eposta|onay_yok|sinir|servis"}
 *
 * Korumalar: yalnız POST · yalnız sitenin kendi sayfasından (Origin / Referer, https) · tuzak alan ·
 * IP başına ve genel saatlik sınır · adres listede olsa da aynı yanıt (üyelik dışarı sızmaz).
 * Günlüğe (logs/numistrbulten.php) e-posta ya da IP yazılmaz; yalnız hata kodları.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;

require_once __DIR__ . '/helper.php';

class PlgSystemNumistrbulten extends CMSPlugin
{
    /** @var \Joomla\CMS\Application\CMSApplication */
    protected $app;

    protected $autoloadLanguage = true;

    /**
     * com_ajax giriş noktası: plugin=numistrbulten
     *
     * @return string JSON (com_ajax format=raw olduğu gibi basar)
     */
    public function onAjaxNumistrbulten()
    {
        $H = 'PlgNumistrBultenHelper';

        if (!$this->app->isClient('site') || $this->app->input->getCmd('task', '') !== 'subscribe') {
            return $this->yanit(404, array('ok' => false, 'hata' => $H::HATA_SERVIS));
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return $this->yanit(405, array('ok' => false, 'hata' => $H::HATA_SERVIS));
        }
        $izinli = explode(',', (string) $this->params->get('allowed_hosts', 'www.numistr.org,numistr.org'));
        if (!$H::kokenIzinli($_SERVER['HTTP_ORIGIN'] ?? null, $_SERVER['HTTP_REFERER'] ?? null, $izinli)) {
            return $this->yanit(403, array('ok' => false, 'hata' => $H::HATA_SERVIS));
        }

        $post = $this->app->input->post;
        $d = $H::dogrula(array(
            'email'    => (string) $post->get('email', '', 'raw'),
            'onay'     => (string) $post->get('onay', '', 'cmd'),
            'dil'      => (string) $post->get('dil', '', 'cmd'),
            'web_site' => (string) $post->get('web_site', '', 'raw'),
        ));
        if ($d['durum'] === 'tuzak') {
            return $this->yanit(200, array('ok' => true));
        }
        if ($d['durum'] !== 'gecerli') {
            return $this->yanit(422, array('ok' => false, 'hata' => $d['hata']));
        }

        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ipAnahtar = 'ip_' . hash('sha256', $ip . '|' . (string) $this->app->get('secret'));
        if ($this->sinirAsildi($ipAnahtar, (int) $this->params->get('per_ip_limit', 5))
            || $this->sinirAsildi('genel', (int) $this->params->get('global_limit', 300))) {
            return $this->yanit(429, array('ok' => false, 'hata' => $H::HATA_SINIR));
        }

        $apiUrl  = trim((string) $this->params->get('api_url', 'https://esemtipi.com/api/index.php'));
        $apiKey  = trim((string) $this->params->get('api_key', ''));
        $listUid = $H::listeUid(array(
            'list_uid_tr' => $this->params->get('list_uid_tr', ''),
            'list_uid_en' => $this->params->get('list_uid_en', ''),
        ), $d['dil']);
        if ($apiUrl === '' || $apiKey === '' || $listUid === '') {
            $this->gunluk('ayar eksik (' . $d['dil'] . '): API adresi, anahtar ya da liste kimliği boş');

            return $this->yanit(503, array('ok' => false, 'hata' => $H::HATA_SERVIS));
        }

        $istek = $H::istek($apiUrl, $listUid, $apiKey, $d['email'], $ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        try {
            $http  = HttpFactory::getHttp(array(), array('curl', 'stream'));
            $cevap = $http->post($istek['url'], $istek['govde'], $istek['basliklar'], 10);
            $kod   = (int) $cevap->code;
            $govde = (string) $cevap->body;
        } catch (\Throwable $e) {
            $this->gunluk('MailWizz isteği başarısız (' . $d['dil'] . '): ' . get_class($e));

            return $this->yanit(502, array('ok' => false, 'hata' => $H::HATA_SERVIS));
        }

        if ($H::yanit($kod, $govde) === 'hata') {
            $json = json_decode($govde, true);
            $hata = is_array($json) ? json_encode($json['error'] ?? '', JSON_UNESCAPED_UNICODE) : 'JSON değil';
            $this->gunluk('MailWizz HTTP ' . $kod . ' (' . $d['dil'] . '): ' . substr((string) $hata, 0, 200));

            return $this->yanit(502, array('ok' => false, 'hata' => $H::HATA_SERVIS));
        }

        return $this->yanit(200, array('ok' => true));
    }

    /** Saatlik sınır: JPATH_CACHE/numistrbulten/<anahtar>.json (yalnız zaman damgaları; kilitli). */
    private function sinirAsildi(string $anahtar, int $limit): bool
    {
        if ($limit <= 0) {
            return false;
        }
        $dizin = JPATH_CACHE . '/numistrbulten';
        if (!is_dir($dizin) && !@mkdir($dizin, 0755, true) && !is_dir($dizin)) {
            $this->gunluk('sınır dizini açılamadı');

            return false;
        }
        $fp = @fopen($dizin . '/' . preg_replace('/[^a-z0-9_]/', '', $anahtar) . '.json', 'c+');
        if ($fp === false) {
            $this->gunluk('sınır dosyası açılamadı');

            return false;
        }
        flock($fp, LOCK_EX);
        $kayitlar = json_decode((string) stream_get_contents($fp), true);
        $s = PlgNumistrBultenHelper::sinir(is_array($kayitlar) ? $kayitlar : array(), time(), $limit);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($s['kayitlar']));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $s['asildi'];
    }

    private function yanit(int $kod, array $govde): string
    {
        $this->app->setHeader('status', (string) $kod, true);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);

        return json_encode($govde, JSON_UNESCAPED_UNICODE);
    }

    private function gunluk(string $mesaj): void
    {
        try {
            Log::addLogger(array('text_file' => 'numistrbulten.php'), Log::ALL, array('numistrbulten'));
            Log::add($mesaj, Log::WARNING, 'numistrbulten');
        } catch (\Throwable $e) {
            // günlük yazılamazsa istek yine yanıtlanır
        }
    }
}
