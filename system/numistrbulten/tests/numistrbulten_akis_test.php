<?php
/**
 * plg_system_numistrbulten — uç akışı testi (Joomla sınıfları taklit): php plugins/system/numistrbulten/tests/numistrbulten_akis_test.php
 */
namespace Joomla\CMS\Plugin {
    class CMSPlugin
    {
        public $params;
        protected $app;

        public function __construct($app, $params)
        {
            $this->app = $app;
            $this->params = $params;
        }
    }
}

namespace Joomla\CMS\Log {
    class Log
    {
        public const ALL = 30719;
        public const WARNING = 16;
        public static $mesajlar = array();

        public static function addLogger(...$a) {}

        public static function add($m, ...$a)
        {
            self::$mesajlar[] = $m;
        }
    }
}

namespace Joomla\CMS\Http {
    class HttpFactory
    {
        public static $cevap;
        public static $son;
        public static $sayac = 0;

        public static function getHttp(...$a)
        {
            return new class {
                public function post($url, $govde, $basliklar, $sure)
                {
                    HttpFactory::$sayac++;
                    HttpFactory::$son = array('url' => $url, 'govde' => $govde, 'basliklar' => $basliklar);
                    if (HttpFactory::$cevap instanceof \Throwable) {
                        throw HttpFactory::$cevap;
                    }

                    return (object) HttpFactory::$cevap;
                }
            };
        }
    }
}

namespace {
    define('_JEXEC', 1);
    define('JPATH_CACHE', sys_get_temp_dir() . '/numistrbulten_test_' . getmypid());
    require __DIR__ . '/../numistrbulten.php';

    use Joomla\CMS\Http\HttpFactory;
    use Joomla\CMS\Log\Log;

    class Ayar
    {
        public $a;
        public function __construct(array $a) { $this->a = $a; }
        public function get($k, $d = null) { return $this->a[$k] ?? $d; }
    }

    class Girdi
    {
        public $post;
        private $task;
        public function __construct(string $task, array $post) { $this->task = $task; $this->post = new Ayar($post); }
        public function getCmd($k, $d = '') { return $k === 'task' ? $this->task : $d; }
    }

    class Uyg
    {
        public $input;
        public $basliklar = array();
        public function __construct(Girdi $g) { $this->input = $g; }
        public function isClient($c) { return $c === 'site'; }
        public function get($k) { return $k === 'secret' ? 'gizli-tuz' : null; }
        public function setHeader($k, $v, $r = false) { $this->basliklar[strtolower($k)] = $v; }
    }

    $ok = 0; $fail = 0;
    function check($ad, $kosul) { global $ok, $fail; if ($kosul) { $ok++; echo "  ok   $ad\n"; } else { $fail++; echo "  FAIL $ad\n"; } }

    $tamAyar = array('api_url' => 'https://esemtipi.com/api/index.php', 'api_key' => 'GIZLI-ANAHTAR',
                     'list_uid_tr' => 'TRUID', 'list_uid_en' => 'ENUID', 'per_ip_limit' => 50, 'global_limit' => 500);

    function cagir(array $post, array $ayar, string $yontem = 'POST', ?string $origin = 'https://www.numistr.org', string $ip = '10.0.0.1', string $task = 'subscribe')
    {
        $_SERVER['REQUEST_METHOD'] = $yontem;
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_SERVER['HTTP_USER_AGENT'] = 'Test UA';
        unset($_SERVER['HTTP_REFERER']);
        if ($origin === null) { unset($_SERVER['HTTP_ORIGIN']); } else { $_SERVER['HTTP_ORIGIN'] = $origin; }
        $uyg = new Uyg(new Girdi($task, $post));
        $e = new PlgSystemNumistrbulten($uyg, new Ayar($ayar));
        $j = json_decode($e->onAjaxNumistrbulten(), true);

        return array($j, (int) ($uyg->basliklar['status'] ?? 0), $uyg->basliklar);
    }

    $gecerli = array('email' => 'okur@example.com', 'onay' => '1', 'dil' => 'tr', 'web_site' => '');

    // --------------------------------------------------------- ön kapılar ---
    HttpFactory::$sayac = 0;
    [$j, $k] = cagir($gecerli, $tamAyar, 'GET');
    check('GET → 405 servis', $k === 405 && $j['ok'] === false && $j['hata'] === 'servis');
    [$j, $k] = cagir($gecerli, $tamAyar, 'POST', 'https', '10.0.0.1', 'baska');
    check('bilinmeyen görev → 404', $k === 404);
    [$j, $k] = cagir($gecerli, $tamAyar, 'POST', 'https://kotu.example');
    check('yabancı köken → 403', $k === 403 && $j['hata'] === 'servis');
    [$j, $k] = cagir($gecerli, $tamAyar, 'POST', null);
    check('köken ve Referer yok → 403', $k === 403);
    [$j, $k] = cagir(array('web_site' => 'http://spam') + $gecerli, $tamAyar);
    check('tuzak alan → 200 ok (bota başarı görünür)', $k === 200 && $j['ok'] === true);
    [$j, $k] = cagir(array('email' => 'bozuk') + $gecerli, $tamAyar);
    check('geçersiz adres → 422 gecersiz_eposta', $k === 422 && $j['hata'] === 'gecersiz_eposta');
    [$j, $k] = cagir(array('onay' => '') + $gecerli, $tamAyar);
    check('onay yok → 422 onay_yok', $k === 422 && $j['hata'] === 'onay_yok');
    check('ön kapılarda MailWizz çağrılmadı', HttpFactory::$sayac === 0);

    // ------------------------------------------------------------- ayar ---
    [$j, $k] = cagir($gecerli, array('api_key' => '') + $tamAyar);
    check('anahtar boş → 503 servis', $k === 503 && $j['hata'] === 'servis');
    [$j, $k] = cagir($gecerli, array('list_uid_tr' => '') + $tamAyar);
    check('TR listesi boş → 503 (EN\'e düşmez)', $k === 503 && HttpFactory::$sayac === 0);
    check('eksik ayar günlüğe yazıldı', strpos(end(Log::$mesajlar), 'ayar eksik (tr)') !== false);

    // ---------------------------------------------------------- MailWizz ---
    HttpFactory::$cevap = array('code' => 201, 'body' => '{"status":"success","data":{"record":{"subscriber_uid":"x"}}}');
    [$j, $k, $b] = cagir($gecerli, $tamAyar);
    check('201 → 200 ok', $k === 200 && $j === array('ok' => true));
    check('TR → TR listesine istek', HttpFactory::$son['url'] === 'https://esemtipi.com/api/index.php/lists/TRUID/subscribers');
    check('X-Api-Key gönderildi', HttpFactory::$son['basliklar']['X-Api-Key'] === 'GIZLI-ANAHTAR');
    parse_str(HttpFactory::$son['govde'], $g);
    check('gövdede EMAIL + IP', $g['EMAIL'] === 'okur@example.com' && $g['details']['ip_address'] === '10.0.0.1');
    check('yanıt JSON başlığı + önbelleksiz', strpos($b['content-type'], 'application/json') === 0 && $b['cache-control'] === 'no-store');
    check('anahtar yanıtta yok', strpos(json_encode($j), 'GIZLI') === false);
    cagir(array('dil' => 'en') + $gecerli, $tamAyar);
    check('EN → EN listesine istek', HttpFactory::$son['url'] === 'https://esemtipi.com/api/index.php/lists/ENUID/subscribers');

    HttpFactory::$cevap = array('code' => 409, 'body' => '{"status":"error","error":"The subscriber already exists in this list."}');
    [$j, $k] = cagir($gecerli, $tamAyar);
    check('409 zaten listede → 200 ok (aynı yanıt)', $k === 200 && $j['ok'] === true);

    HttpFactory::$cevap = array('code' => 401, 'body' => '{"status":"error","error":"Invalid API key. Please refer to the documentation."}');
    [$j, $k] = cagir($gecerli, $tamAyar);
    check('401 → 502 servis', $k === 502 && $j['hata'] === 'servis');
    check('hata günlüğünde HTTP kodu', strpos(end(Log::$mesajlar), 'MailWizz HTTP 401 (tr)') !== false);

    HttpFactory::$cevap = new \RuntimeException('bağlantı yok');
    [$j, $k] = cagir($gecerli, $tamAyar);
    check('bağlantı hatası → 502 servis', $k === 502 && $j['hata'] === 'servis');

    // ------------------------------------------------------------- sınır ---
    HttpFactory::$cevap = array('code' => 201, 'body' => '{"status":"success"}');
    $sinirli = array('per_ip_limit' => 2) + $tamAyar;
    [$j1] = cagir($gecerli, $sinirli, 'POST', 'https://www.numistr.org', '10.9.9.9');
    [$j2] = cagir($gecerli, $sinirli, 'POST', 'https://www.numistr.org', '10.9.9.9');
    [$j3, $k3] = cagir($gecerli, $sinirli, 'POST', 'https://www.numistr.org', '10.9.9.9');
    check('IP başına 2: üçüncü istek 429 sinir', $j1['ok'] && $j2['ok'] && $k3 === 429 && $j3['hata'] === 'sinir');
    [$j4] = cagir($gecerli, $sinirli, 'POST', 'https://www.numistr.org', '10.9.9.8');
    check('başka IP etkilenmez', $j4['ok'] === true);
    $dosyalar = glob(JPATH_CACHE . '/numistrbulten/*.json');
    check('sınır dosya adlarında IP yok (özet)', count($dosyalar) > 0 && !array_filter($dosyalar, function ($f) { return strpos($f, '10.9.9') !== false; }));

    // ------------------------------------------------------------ günlük ---
    $tum = implode("\n", Log::$mesajlar);
    check('günlükte e-posta ve IP yok', strpos($tum, 'okur@example.com') === false && strpos($tum, '10.0.0.1') === false && strpos($tum, 'GIZLI') === false);

    array_map('unlink', glob(JPATH_CACHE . '/numistrbulten/*.json'));
    @rmdir(JPATH_CACHE . '/numistrbulten');
    @rmdir(JPATH_CACHE);
    echo "\n$ok ok, $fail fail\n";
    exit($fail ? 1 : 0);
}
