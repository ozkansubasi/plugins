<?php
/**
 * Görsel tamamlama hattı yönetici uçları — saf (DB'siz) mantık.
 *
 * İmza vektörü hattın Python tarafıyla ORTAKTIR
 * (scripts/gorsel_kurtarma/gorsel_hatti.py::imzala) — biri değişirse bu test kırılır.
 */

require_once $root . '/helpers/GalleryAdminHelper.php';

$secret = str_repeat('k', 32) . '-test-secret';
$now    = 1789600000;
$body   = '{"run_id":"pilot-20260917","dry_run":true,"articles":[]}';

// ------------------------------------------------------------------ imza ---
$sig = NumisTRGalleryAdmin::sign('POST', 'publish', $now, $body, $secret);
check('imza 64 hex', (bool) preg_match('/^[0-9a-f]{64}$/', $sig), $sig);
check('sabit vektör (Python tarafıyla ortak)', $sig === 'f9257500a0ede705d000edd5a6103cc7fc7c78fcee5262fb1d3e36720ed48007', $sig);
check('geçerli imza kabul', NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, $sig, $secret, 300, $now));
check('büyük harfli hex kabul', NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, strtoupper($sig), $secret, 300, $now));
check('gövde değişirse ret', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body . ' ', $sig, $secret, 300, $now));
check('dry_run false yapılıp aynı imza kullanılırsa ret', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, str_replace('true', 'false', $body), $sig, $secret, 300, $now));
check('başka eylem ret (publish imzası rollback açmaz)', !NumisTRGalleryAdmin::verify('POST', 'rollback', $now, $body, $sig, $secret, 300, $now));
check('başka yöntem ret', !NumisTRGalleryAdmin::verify('GET', 'publish', $now, $body, $sig, $secret, 300, $now));
check('eski zaman damgası ret (tekrar oynatma)', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, $sig, $secret, 300, $now + 301));
check('gelecek zaman damgası ret', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, $sig, $secret, 300, $now - 301));
check('sınırda (300 sn) kabul', NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, $sig, $secret, 300, $now + 300));
check('yanlış sır ret', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, $sig, str_repeat('x', 40), 300, $now));
check('boş sır ret (uç kapalı)', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, NumisTRGalleryAdmin::sign('POST', 'publish', $now, $body, ''), '', 300, $now));
check('kısa sır ret (31 karakter)', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, NumisTRGalleryAdmin::sign('POST', 'publish', $now, $body, str_repeat('a', 31)), str_repeat('a', 31), 300, $now));
check('boş imza ret', !NumisTRGalleryAdmin::verify('POST', 'publish', $now, $body, '', $secret, 300, $now));
check('ts 0 ret', !NumisTRGalleryAdmin::verify('POST', 'publish', 0, $body, NumisTRGalleryAdmin::sign('POST', 'publish', 0, $body, $secret), $secret, 300, 0));

// ---------------------------------------------------------------- klasör ---
$map = ['mysia-coins' => 'misia', 'Bithynia-Coins' => 'bitinya', 'evil' => '../public_html', 'bos' => ''];
check('alias → klasör', NumisTRGalleryAdmin::resolveFolder('mysia-coins', $map) === 'misia');
check('alias büyük/küçük harf duyarsız', NumisTRGalleryAdmin::resolveFolder('BITHYNIA-coins', $map) === 'bitinya');
check('eşleme yoksa null', NumisTRGalleryAdmin::resolveFolder('troas-coins', $map) === null);
check('dizin kaçışı içeren klasör ret', NumisTRGalleryAdmin::resolveFolder('evil', $map) === null);
check('boş klasör ret', NumisTRGalleryAdmin::resolveFolder('bos', $map) === null);
check('safeFolder eğik çizgi ret', !NumisTRGalleryAdmin::safeFolder('a/b'));
check('safeFolder null ret', !NumisTRGalleryAdmin::safeFolder(null));

// ----------------------------------------------------------------- öğeler ---
$item = static function (string $fn, string $type, array $extra = []): array {
    return $extra + [
        'main_local'   => $fn,
        'thumb_local'  => $fn,
        'image_type'   => $type,
        'main_remote'  => 'https://ikmk.smb.museum/image/18200001/vs_org.jpg',
        'thumb_remote' => 'https://ikmk.smb.museum/image/18200001/vs_opt.jpg',
        'weight'       => '6.88',
        'diameter'     => '23',
        'source_credit' => 'Münzkabinett Berlin',
        'sha1'         => str_repeat('a', 40),
    ];
};

$ok = NumisTRGalleryAdmin::normalizeItems(5633, [$item('n5633_1_on.jpg', 'on'), $item('n5633_2_arka.jpg', 'arka')]);
check('geçerli çift: hata yok', $ok['errors'] === [], implode(' | ', $ok['errors']));
check('geçerli çift: 2 satır', count($ok['rows']) === 2);
check('ordering 1,2', array_column($ok['rows'], 'ordering') === [1, 2]);
check('coin_id = makale id', $ok['rows'][0]['coin_id'] === 5633);
check('varsayılan match_level exact', $ok['rows'][0]['match_level'] === 'exact' && $ok['rows'][0]['match_uri'] === null);
check('atıf korunur (UTF-8)', $ok['rows'][0]['source_credit'] === 'Münzkabinett Berlin');

$err = static fn (array $items, int $aid = 5633): string => implode(' | ', NumisTRGalleryAdmin::normalizeItems($aid, $items)['errors']);

check('boş items ret', $err([]) !== '');
check('items dizi değilse ret', NumisTRGalleryAdmin::normalizeItems(5633, 'x')['errors'] !== []);
check('13 görsel ret', $err(array_fill(0, 13, $item('n5633_1_on.jpg', 'on'))) !== '');
check('başka makalenin dosyası ret', strpos($err([$item('n9999_1_on.jpg', 'on')]), 'başka makaleye') !== false);
check('eski biçimli dosya adı ret (n öneki yok)', strpos($err([$item('0001_CN_Type_4841_0.jpeg', 'on')]), 'biçimi geçersiz') !== false);
check('dizin kaçışı ret', strpos($err([$item('../n5633_1_on.jpg', 'on')]), 'biçimi geçersiz') !== false);
check('php uzantısı ret', strpos($err([$item('n5633_1_on.php', 'on')]), 'biçimi geçersiz') !== false);
check('çift uzantı ret', strpos($err([$item('n5633_1_on.php.jpg', 'on')]), 'biçimi geçersiz') !== false);
check('sıra konumla uyuşmuyorsa ret', strpos($err([$item('n5633_2_on.jpg', 'on')]), 'konumla uyuşmuyor') !== false);
check('image_type dosya adıyla uyuşmuyorsa ret', strpos($err([$item('n5633_1_on.jpg', 'arka')]), 'image_type') !== false);
check('javascript: URL ret', strpos($err([$item('n5633_1_on.jpg', 'on', ['main_remote' => 'javascript:alert(1)'])]), 'main_remote') !== false);
check('sayı olmayan ağırlık ret', strpos($err([$item('n5633_1_on.jpg', 'on', ['weight' => 'nan'])]), 'weight') !== false);
check('bozuk sha1 ret', strpos($err([$item('n5633_1_on.jpg', 'on', ['sha1' => 'xyz'])]), 'sha1') !== false);
check('geçersiz match_level ret', strpos($err([$item('n5633_1_on.jpg', 'on', ['match_level' => 'sibling'])]), 'match_level') !== false);
check('parent + match_uri yok ret', strpos($err([$item('n5633_1_on.jpg', 'on', ['match_level' => 'parent'])]), 'match_uri zorunlu') !== false);
check('hata varken satır dönmez (yarım yazım olmasın)', NumisTRGalleryAdmin::normalizeItems(5633, [$item('n5633_1_on.jpg', 'on'), $item('bad', 'on')])['rows'] === []);

$parent = NumisTRGalleryAdmin::normalizeItems(5633, [$item('n5633_1_detay.jpg', 'detay', [
    'match_level' => 'parent', 'match_uri' => 'http://numismatics.org/ocre/id/ric.9.cyz.20D', 'weight' => null, 'diameter' => '', 'sha1' => '',
])]);
check('parent eşleşme kabul', $parent['errors'] === [] && $parent['rows'][0]['match_level'] === 'parent', implode(' | ', $parent['errors']));
check('parent match_uri korunur', $parent['rows'][0]['match_uri'] === 'http://numismatics.org/ocre/id/ric.9.cyz.20D');
check('boş metrik → null', $parent['rows'][0]['weight'] === null && $parent['rows'][0]['diameter'] === null);
check('virgüllü ondalık normalleşir', NumisTRGalleryAdmin::normalizeItems(5633, [$item('n5633_1_on.jpg', 'on', ['weight' => '6,88'])])['rows'][0]['weight'] === '6.88');

// -------------------------------------------------- alan 35 JSON sözleşmesi ---
$json = json_decode(NumisTRGalleryAdmin::galleryJson($ok['rows']), true);
check('JSON 2 öğe', is_array($json) && count($json) === 2);
check('JSON anahtarları canlı sözleşmeyle birebir', array_keys($json[0]) === ['thumb_local', 'main_local', 'thumb_remote', 'main_remote', 'weight', 'diameter'], implode(',', array_keys($json[0])));
check('JSON metrikleri dize', $json[0]['weight'] === '6.88' && $json[0]['diameter'] === '23');
check('JSON eğik çizgi kaçışsız (canlıdaki biçim)', strpos(NumisTRGalleryAdmin::galleryJson($ok['rows']), 'https://ikmk') !== false);
check('JSON null metrik boş dize olur', json_decode(NumisTRGalleryAdmin::galleryJson($parent['rows']), true)[0]['weight'] === '');
check('migrate_images.php pickFilename() ile uyum: main_local basename = filename', basename($json[1]['main_local']) === 'n5633_2_arka.jpg');
