<?php
/**
 * Yazı ucunda figür kısa kodu (1.24.2) — istemciye asla ham `{numistr_gorsel …}` gitmez.
 * Gerçek içerik eklentisi Joomla ister; burada önyükleme enjekte edilir (sahte eklenti).
 */

require_once $root . '/helpers/ArticleFigureHelper.php';

$H = 'ArticleFigureHelper';

// ------------------------------------------------------------------ stripCodes ---
$lidya = "<p>Doğal alaşım...</p>\n<h2>Altın ve gümüş sikkelerde ikili sistem</h2>\n"
    . "<p>{numistr_gorsel variant=30669 gorsel=118104}</p>\n<p>Herodotos, altın ve gümüş sikkeyi ilk basıp kullananların...</p>";
$sade = $H::stripCodes($lidya);
check('kod ve saran <p> silinir', strpos($sade, 'numistr_gorsel') === false && strpos($sade, '<p></p>') === false, $sade);
check('çevre metin korunur', strpos($sade, '<h2>Altın ve gümüş sikkelerde ikili sistem</h2>') !== false
    && strpos($sade, '<p>Herodotos, altın') !== false);
check('&nbsp; ile saran <p> da gider', $H::stripCodes('<p>&nbsp;{numistr_gorsel variant=1 gorsel=2,3}&nbsp;</p>x') === 'x');
check('satır içi kod da gider', $H::stripCodes('a {numistr_gorsel variant=1 gorsel=2} b') === 'a  b');
check('birden çok kod', substr_count($H::stripCodes('{numistr_gorsel variant=1 gorsel=2}{numistr_gorsel variant=3 gorsel=4}'), 'numistr') === 0);
check('kodsuz metin aynen', $H::stripCodes('<p>Sardeis</p>') === '<p>Sardeis</p>');
check('başka süslü parantez dokunulmaz', $H::stripCodes('{loadmodule x} {numistr_gorsel variant=1 gorsel=2}') === '{loadmodule x} ');
check('özet: strip_tags sonrası kod yok', strpos(strip_tags($H::stripCodes("<p>{numistr_gorsel variant=9 gorsel=9}</p><p>Giriş metni</p>")), '{') === false);

// ------------------------------------------------------------------ render ---
$sahte = new class {
    public $cagri = 0;
    public $baglam = null;
    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        $this->cagri++;
        $this->baglam = array($context, $article->id, $article->language);
        $article->text = str_replace('<p>{numistr_gorsel variant=30669 gorsel=118104}</p>', '<figure class="numistr-figur">F</figure>', $article->text);
    }
};
$cikti = $H::render($lidya, 34028, 'tr-TR', function () use ($sahte) { return $sahte; });
check('eklenti çağrılır, figür kurulur', strpos($cikti, '<figure class="numistr-figur">F</figure>') !== false, $cikti);
check('eklentiye bağlam/kimlik/dil gider', $sahte->baglam === array('com_content.article', 34028, 'tr-TR'), json_encode($sahte->baglam));
check('figürden sonra ham kod kalmaz', strpos($cikti, 'numistr_gorsel') === false);

$yarim = new class {
    public function onContentPrepare($context, &$article, &$params, $page = 0) { /* hiçbir şey yapmaz (eski 1.1.0: API'de dokunmaz) */ }
};
check('eklenti kodu bırakırsa yine silinir', strpos($H::render($lidya, 1, 'tr-TR', function () use ($yarim) { return $yarim; }), 'numistr_gorsel') === false);

$bozuk = new class {
    public function onContentPrepare($context, &$article, &$params, $page = 0) { throw new \RuntimeException('db yok'); }
};
$c2 = $H::render($lidya, 1, 'tr-TR', function () use ($bozuk) { return $bozuk; });
check('eklenti hata verirse yazı açılır, kod silinir', strpos($c2, 'numistr_gorsel') === false && strpos($c2, 'Herodotos') !== false);
check('eklenti yoksa (null) kod silinir', strpos($H::render($lidya, 1, 'tr-TR', function () { return null; }), 'numistr_gorsel') === false);
check('önyükleme patlarsa kod silinir', strpos($H::render($lidya, 1, 'tr-TR', function () { throw new \Error('x'); }), 'numistr_gorsel') === false);

$sayac = new class { public $n = 0; public function onContentPrepare($c, &$a, &$p, $g = 0) { $this->n++; } };
$H::render('<p>kodsuz</p>', 1, 'tr-TR', function () use ($sayac) { return $sayac; });
check('kodsuz yazıda eklenti hiç çağrılmaz', $sayac->n === 0);
check('varsayılan önyükleme Joomla yokken çökmez', strpos($H::render($lidya, 1, 'tr-TR'), 'numistr_gorsel') === false);
