<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * @copyright   (C) 2026 NumisTR
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

/**
 * M17 — oturumsuz deneme hakki: kurulum basina N basarili tanima (varsayilan 3).
 *
 * Neden: tanima oturum istiyordu. Ilk taramadan once kayit istemek aktivasyonu dusurur
 * ve App Store 5.1.1(v) hesap gerektirmeyen bir ozellik icin zorunlu girisi reddedebilir.
 *
 * Kimlik: uygulamanin urettigi rastgele kurulum kimligi (X-Device-Id basligi). Donanim
 * kimligi DEGIL; sunucu yalnizca SHA-256 ozetini saklar, ham deger hicbir yere yazilmaz.
 * Istemci kimligi istedigi gibi degistirebilir; bu yuzden asil koruma iki tavandir:
 *   - IP basina gunluk deneme tavani (betikle kimlik degistirmeyi durdurur)
 *   - tum denemeler icin saatlik tavan (AI sunucusu istek basina ~4,5 sn CPU harcar)
 * IP olarak yalnizca REMOTE_ADDR kullanilir: site bir vekil arkasinda degil (2026-10-11,
 * numistr.org dogrudan cPanel'e cozuluyor), X-Forwarded-For istemcinin uydurabilecegi bir
 * basliktir ve tavani deler.
 *
 * Oturumlu yoldan farki: olculemeyen durumda KAPALI kalir (deneme bir ikramdir; sayim
 * yapilamiyorsa AI sunucusunu korumak oncelikli).
 *
 * @since  1.18.0
 */
class AnonTrialHelper
{
    const TABLE = 'numistr_anon_devices';

    /** @var \Joomla\Database\DatabaseDriver|null */
    private $db;

    /** @var array */
    private $settings;

    public function __construct($db = null, ?array $config = null)
    {
        if ($config === null) {
            $configPath = __DIR__ . '/../config/constants.php';
            $config = file_exists($configPath) ? include $configPath : [];
        }

        $this->db       = $db;
        $this->settings = self::settings($config);
    }

    /**
     * constants.php QUOTA.anonymous. Ayar yoksa deneme KAPALI (kod ayardan once
     * yuklenirse kimse oturumsuz tanima alamaz).
     *
     * @return  array  ['enabled'=>bool,'device_limit'=>int,'ip_per_day'=>int,'global_per_hour'=>int]
     *                 ip_per_day / global_per_hour icin 0 = o tavan kapali
     */
    public static function settings(array $config)
    {
        $a = isset($config['QUOTA']['anonymous']) ? (array) $config['QUOTA']['anonymous'] : array();

        return array(
            'enabled'         => !empty($a['enabled']),
            'device_limit'    => isset($a['device_limit']) ? max(0, (int) $a['device_limit']) : 3,
            'ip_per_day'      => isset($a['ip_per_day']) ? max(0, (int) $a['ip_per_day']) : 10,
            'global_per_hour' => isset($a['global_per_hour']) ? max(0, (int) $a['global_per_hour']) : 60,
        );
    }

    /**
     * Kurulum kimligini dogrular ve ozetler. Gecerli bicim: 16-64 karakter, harf/rakam/tire
     * (uygulama UUID v4 uretir). Buyuk/kucuk harf farki ayni kurulum sayilir.
     *
     * @param   mixed  $raw  X-Device-Id basliginin degeri
     *
     * @return  string|null  64 karakterlik SHA-256 ozeti; gecersizse null
     */
    public static function deviceHash($raw)
    {
        $raw = is_string($raw) ? trim($raw) : '';

        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $raw)) {
            return null;
        }

        return hash('sha256', strtolower($raw));
    }

    /**
     * Saf karar fonksiyonu (test edilebilir olsun diye ayri).
     *
     * @param   array     $s           self::settings() ciktisi
     * @param   int|null  $deviceUsed  bu kurulumun kullandigi hak; null = olculemedi
     * @param   array|null $counts     ['ip_day'=>int,'global_hour'=>int]; null = olculemedi
     *
     * @return  array  ['allowed'=>bool,'code'=>''|'TRIAL_EXHAUSTED'|'TRIAL_UNAVAILABLE','scope'=>string]
     */
    public static function decide(array $s, $deviceUsed, $counts)
    {
        if (empty($s['enabled'])) {
            return array('allowed' => false, 'code' => 'TRIAL_UNAVAILABLE', 'scope' => 'disabled');
        }

        if ($deviceUsed === null || !is_array($counts)) {
            return array('allowed' => false, 'code' => 'TRIAL_UNAVAILABLE', 'scope' => 'unmeasured');
        }

        if ((int) $deviceUsed >= (int) $s['device_limit']) {
            return array('allowed' => false, 'code' => 'TRIAL_EXHAUSTED', 'scope' => 'device');
        }

        if ($s['ip_per_day'] > 0 && (int) $counts['ip_day'] >= $s['ip_per_day']) {
            return array('allowed' => false, 'code' => 'TRIAL_UNAVAILABLE', 'scope' => 'ip');
        }

        if ($s['global_per_hour'] > 0 && (int) $counts['global_hour'] >= $s['global_per_hour']) {
            return array('allowed' => false, 'code' => 'TRIAL_UNAVAILABLE', 'scope' => 'global');
        }

        return array('allowed' => true, 'code' => '', 'scope' => '');
    }

    /**
     * Istemciye giden kota ozeti — oturumlu tanima yanitindaki 'quota' ile ayni anahtarlar.
     *
     * @param   int|null  $used  null = olculemedi (kalan 0 gosterilir)
     */
    public function quota($used)
    {
        $limit = (int) $this->settings['device_limit'];
        $used  = $used === null ? $limit : (int) $used;

        return array(
            'used'       => $used,
            'remaining'  => max(0, $limit - $used),
            'limit'      => $limit,
            'tier'       => 'anonymous',
            'reset_date' => null,
        );
    }

    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * Bu kurulumun kullandigi deneme hakki.
     *
     * @return  int|null  null = olculemedi (tablo yok / SQL hatasi)
     */
    public function used($deviceHash)
    {
        try {
            $db = $this->db();
            $db->setQuery(
                'SELECT ' . $db->quoteName('scans_used')
                . ' FROM ' . $db->quoteName(self::TABLE)
                . ' WHERE ' . $db->quoteName('device_hash') . ' = ' . $db->quote($deviceHash)
            );
            $value = $db->loadResult();

            return $value === null ? 0 : (int) $value;
        } catch (\Throwable $e) {
            $this->logIssue('used: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Son 24 saatte bu IP'den ve son 1 saatte toplam oturumsuz tanima istegi.
     * Istek gunlugunde oturumsuz satirlar user_id = 0'dir; idx_user_timestamp kullanilir.
     * Basarisiz istekler de sayilir (hata dongusuyle tavan delinmesin).
     *
     * @return  array|null  ['ip_day'=>int,'global_hour'=>int]; null = olculemedi
     */
    public function counts($ip)
    {
        try {
            $db = $this->db();
            $db->setQuery(
                'SELECT'
                . ' SUM(CASE WHEN ' . $db->quoteName('ip_address') . ' = ' . $db->quote((string) $ip) . ' THEN 1 ELSE 0 END) AS c_ip,'
                . ' SUM(CASE WHEN ' . $db->quoteName('request_timestamp') . ' >= (NOW() - INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS c_global'
                . ' FROM ' . $db->quoteName('numistr_recognition_requests')
                . ' WHERE ' . $db->quoteName('user_id') . ' = 0'
                . ' AND ' . $db->quoteName('request_timestamp') . ' >= (NOW() - INTERVAL 1 DAY)'
            );
            $row = $db->loadAssoc();

            return array(
                'ip_day'      => is_array($row) ? (int) $row['c_ip'] : 0,
                'global_hour' => is_array($row) ? (int) $row['c_global'] : 0,
            );
        } catch (\Throwable $e) {
            $this->logIssue('counts: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Basarili tanimadan sonra bir hak dusulur (oturumlu kotayla ayni kural).
     *
     * @throws  \RuntimeException  yazilamazsa
     */
    public function consume($deviceHash)
    {
        try {
            $db  = $this->db();
            $now = $db->quote(Factory::getDate()->toSql());
            $db->setQuery(
                'INSERT INTO ' . $db->quoteName(self::TABLE)
                . ' (' . $db->quoteName('device_hash') . ', ' . $db->quoteName('scans_used') . ', '
                . $db->quoteName('first_seen_at') . ', ' . $db->quoteName('last_seen_at') . ')'
                . ' VALUES (' . $db->quote($deviceHash) . ', 1, ' . $now . ', ' . $now . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('scans_used') . ' = ' . $db->quoteName('scans_used') . ' + 1, '
                . $db->quoteName('last_seen_at') . ' = ' . $now
            );
            $db->execute();
        } catch (\Throwable $e) {
            $this->logIssue('consume: ' . $e->getMessage());

            throw new \RuntimeException('Anonymous trial consumption failed', 500);
        }
    }

    /**
     * Istemcinin gercek IP'si. Yalnizca REMOTE_ADDR (sinif aciklamasina bakin).
     */
    public static function clientIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    private function db()
    {
        if ($this->db === null) {
            $this->db = Factory::getDbo();
        }

        return $this->db;
    }

    private function logIssue($message)
    {
        try {
            \Joomla\CMS\Log\Log::addLogger(array('text_file' => 'numistr_quota.php'), \Joomla\CMS\Log\Log::ALL, array('numistr-quota'));
            \Joomla\CMS\Log\Log::add('[anon-trial] ' . $message, \Joomla\CMS\Log\Log::WARNING, 'numistr-quota');
        } catch (\Throwable $e) {
            // sessiz gec: loglama hatasi istegi engellememeli
        }
    }
}
