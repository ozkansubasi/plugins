<?php
/**
 * plg_system_numistrcanonical — Joomla'sız sınama: php plugins/system/numistrcanonical/tests/numistrcanonical_test.php
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

namespace Joomla\CMS\Uri {
    class Uri
    {
        public static $path = '/';
        public static $query = array();

        public static function getInstance()
        {
            return new self();
        }

        public function getPath()
        {
            return self::$path;
        }

        public function getQuery($asArray = false)
        {
            return self::$query;
        }
    }
}

namespace {
    define('_JEXEC', 1);
    require __DIR__ . '/../numistrcanonical.php';

    use Joomla\CMS\Uri\Uri;

    class Ayar
    {
        public $a;
        public function __construct(array $a) { $this->a = $a; }
        public function get($k, $d = null) { return $this->a[$k] ?? $d; }
    }

    class Belge
    {
        public $_links = array();
        public $tur;
        public function __construct($tur) { $this->tur = $tur; }
        public function getType() { return $this->tur; }
        public function addHeadLink($href, $rel) { $this->_links[$href] = array('relation' => $rel, 'relType' => 'rel', 'attribs' => array()); }
    }

    class Uyg
    {
        public $belge;
        public $istemci;
        public function __construct($belge, $istemci = 'site') { $this->belge = $belge; $this->istemci = $istemci; }
        public function isClient($c) { return $c === $this->istemci; }
        public function getDocument() { return $this->belge; }
    }

    $ok = 0; $fail = 0;
    function check($ad, $kosul) { global $ok, $fail; if ($kosul) { $ok++; echo "  ok   $ad\n"; } else { $fail++; echo "  FAIL $ad\n"; } }

    $K = 'PlgSystemNumistrcanonical';
    $D = 'https://www.numistr.org';
    $keep = array('start', 'limitstart');

    // ------------------------------------------------------------ kanonik() ---
    check('ana sayfa', $K::kanonik($D, '/tr/', array(), $keep) === 'https://www.numistr.org/tr/');
    check('utm + gclid atılır', $K::kanonik($D, '/tr/', array('utm_source' => 'x', 'gclid' => 'abc', 'nc' => '5'), $keep) === 'https://www.numistr.org/tr/');
    check('sayfalama kalır', $K::kanonik($D, '/tr/sikke-hikayeleri', array('start' => '20', 'utm_medium' => 'e'), $keep) === 'https://www.numistr.org/tr/sikke-hikayeleri?start=20');
    check('start=0 atılır', $K::kanonik($D, '/tr/blog', array('start' => '0'), $keep) === 'https://www.numistr.org/tr/blog');
    check('sayısal olmayan start atılır', $K::kanonik($D, '/tr/blog', array('start' => '1 OR 1'), $keep) === 'https://www.numistr.org/tr/blog');
    check('dizi parametre atılır', $K::kanonik($D, '/tr/blog', array('start' => array('1')), $keep) === 'https://www.numistr.org/tr/blog');
    check('önde sıfır temizlenir', $K::kanonik($D, '/tr/blog', array('start' => '020'), $keep) === 'https://www.numistr.org/tr/blog?start=20');
    check('parametre sırası sabit', $K::kanonik($D, '/x', array('start' => '2', 'limitstart' => '4'), $keep) === 'https://www.numistr.org/x?limitstart=4&start=2');
    check('alan adı sonunda / olabilir', $K::kanonik($D . '/', '/en/', array(), $keep) === 'https://www.numistr.org/en/');
    check('yol başında / yoksa eklenir', $K::kanonik($D, 'en/coin-stories', array(), $keep) === 'https://www.numistr.org/en/coin-stories');
    check('SEF\'siz adres → dokunma', $K::kanonik($D, '/index.php', array('option' => 'com_content', 'id' => '5'), $keep) === null);
    check('http alan adı → dokunma', $K::kanonik('http://www.numistr.org', '/tr/', array(), $keep) === null);
    check('boş alan adı → dokunma', $K::kanonik('', '/tr/', array(), $keep) === null);

    // ------------------------------------------------- onBeforeCompileHead() ---
    $belge = new Belge('html');
    $belge->_links['https://www.numistr.org/tr/?utm_source=x'] = array('relation' => 'canonical', 'relType' => 'rel', 'attribs' => array());
    $belge->_links['https://www.numistr.org/en/'] = array('relation' => 'alternate', 'relType' => 'rel', 'attribs' => array('hreflang' => 'en-GB'));
    Uri::$path = '/tr/';
    Uri::$query = array('utm_source' => 'x');
    (new PlgSystemNumistrcanonical(new Uyg($belge), new Ayar(array())))->onBeforeCompileHead();
    $can = array_keys(array_filter($belge->_links, function ($l) { return $l['relation'] === 'canonical'; }));
    check('eski canonical silindi, tek yeni canonical', $can === array('https://www.numistr.org/tr/'));
    check('hreflang alternatif korunur', isset($belge->_links['https://www.numistr.org/en/']));

    $hata = new Belge('error');
    (new PlgSystemNumistrcanonical(new Uyg($hata), new Ayar(array())))->onBeforeCompileHead();
    check('hata belgesine dokunmaz', $hata->_links === array());

    $yonetim = new Belge('html');
    (new PlgSystemNumistrcanonical(new Uyg($yonetim, 'administrator'), new Ayar(array())))->onBeforeCompileHead();
    check('yönetimde çalışmaz', $yonetim->_links === array());

    $sefsiz = new Belge('html');
    $sefsiz->_links['https://www.numistr.org/index.php?option=com_content&amp;id=5'] = array('relation' => 'canonical', 'relType' => 'rel', 'attribs' => array());
    Uri::$path = '/index.php';
    Uri::$query = array('option' => 'com_content', 'id' => '5');
    (new PlgSystemNumistrcanonical(new Uyg($sefsiz), new Ayar(array())))->onBeforeCompileHead();
    check('SEF\'siz adreste SEF eklentisinin canonical\'ı kalır', count($sefsiz->_links) === 1);

    $ayarli = new Belge('html');
    Uri::$path = '/tr/liste';
    Uri::$query = array('page' => '3', 'start' => '10');
    (new PlgSystemNumistrcanonical(new Uyg($ayarli), new Ayar(array('keep_params' => 'page'))))->onBeforeCompileHead();
    check('keep_params ayarı uygulanır', isset($ayarli->_links['https://www.numistr.org/tr/liste?page=3']));

    echo "\n$ok ok, $fail fail\n";
    exit($fail ? 1 : 0);
}
