<?php
/**
 * Site araması (/v1/search, 1.26.0) — saf (DB'siz) parçalar.
 * Darphane yazımları canlı /v1/suggest/mints ile doğrulandı (16 Kas 2026).
 */

require_once $root . '/helpers/SiteSearchHelper.php';

$S = 'NumisTRSiteSearch';

// ---------------------------------------------------------- normalize ---
check('normalize: çok kısa → null', $S::normalize(' a ') === null);
check('normalize: boş → null', $S::normalize('') === null);
check('normalize: joker karakterler temizlenir', $S::normalize('%%_') === null);
$n = $S::normalize('  Lampsakos  ');
check('normalize: kırpma + küçük harf', $n['raw'] === 'Lampsakos' && $n['lower'] === 'lampsakos');
check('normalize: Latince biçim eklenir (lampsakos → lampsacus)', in_array('lampsacus', $n['variants'], true), implode(',', $n['variants']));
check('normalize: betim terimi yalnız kendi yazımı', $n['text'] === ['lampsakos'], implode(',', $n['text']));
$n = $S::normalize('Kanatlı At');
check('normalize: Türkçe küçük harf + ASCII biçim', $n['text'] === ['kanatlı at', 'kanatli at'], implode('|', $n['text']));
$n = $S::normalize('İzmir');
check('normalize: İ → i ve İzmir → smyrna', $n['lower'] === 'izmir' && in_array('smyrna', $n['variants'], true), implode(',', $n['variants']));
check('normalize: 60 karakterde kesilir', mb_strlen($S::normalize(str_repeat('ab', 50))['raw'], 'UTF-8') === 60);
check('normalize: kontrol karakteri boşluğa', $S::normalize("efes\x00\x07")['raw'] === 'efes');

// ----------------------------------------------------------- latinise ---
$cases = [
    'kyzikos' => 'cyzicus', 'knidos' => 'cnidus', 'phokaia' => 'phocaea', 'klazomenai' => 'clazomenae',
    'halikarnassos' => 'halicarnassus', 'miletos' => 'miletus', 'aspendos' => 'aspendus', 'kolophon' => 'colophon',
    'efes' => 'ephesus', 'bergama' => 'pergamum', 'sart' => 'sardes', 'lapseki' => 'lampsacus',
    'antiokhos' => 'antiochus', 'seleukos' => 'seleucus', 'lysimakhos' => 'lysimachus', 'kroisos' => 'croesus',
    'buyuk iskender' => 'buyuc alexander',
];
foreach ($cases as $in => $want) {
    check("latinise: $in → $want", $S::latinise($in) === $want, $S::latinise($in));
}

// --------------------------------------------------- matchesWordStart ---
$m = fn (string $text, string $q) => $S::matchesWordStart($text, $S::normalize($q)['text']);
check('motif: "kanatlı at" → "kanatlı atın ön yarısı"', $m('Sola dönük kanatlı atın ön yarısı', 'kanatlı at'));
check('motif: "at" → "kanat" DEĞİL', !$m('Kanat açmış kartal', 'at'));
check('motif: büyük harf / Türkçe İ', $m('İSKENDER BAŞI', 'iskender'));
check('motif: ASCII yazımla Türkçe metin', $m('Aslan başı; altında başak', 'basak'));
check('darphane: alt çizgi sözcük ayırıcı', $S::matchesWordStart('prusa_ad_olympum', ['olympum']));
check('darphane: Latince biçim', $S::matchesWordStart('lampsacus', $S::normalize('Lampsakos')['variants']));
check('darphane: sözcük ortası DEĞİL', !$S::matchesWordStart('colossae', ['loss']));

// --------------------------------------------------------------- rank ---
$r = $S::rank([
    ['id' => 9, 'kind' => 'motif'],
    ['id' => 3, 'kind' => 'title'],
    ['id' => 9, 'kind' => 'mint'],
    ['id' => 5, 'kind' => 'authority'],
    ['id' => 1, 'kind' => 'motif'],
    ['id' => 2, 'kind' => 'bilinmeyen'],
    ['id' => 0, 'kind' => 'mint'],
]);
check('rank: tür sırası, tekil, en iyi tür', array_map(fn ($h) => $h['id'] . $h['kind'][0], $r) === ['9m', '5a', '3t', '1m'], json_encode($r));
check('rank: aynı tür içinde id artan',
    array_column($S::rank([['id' => 7, 'kind' => 'mint'], ['id' => 4, 'kind' => 'mint']]), 'id') === [4, 7]);

// ------------------------------------------------------------ adresler ---
check('coinUrl TR', $S::coinUrl('https://www.numistr.org/', 'tr', 'mysia-coins', 21086, 'cn-type-20572')
    === 'https://www.numistr.org/tr/anatolian-coins/mysia-coins/21086-cn-type-20572');
check('coinUrl diğer bölgeler EN', $S::coinUrl('https://x', 'en', 'other-ancient-regions-coins', 5, 'a')
    === 'https://x/en/other-ancient-regions/5-a');
check('articleUrl TR', $S::articleUrl('https://www.numistr.org', 'tr', 34073, 'kanatli-at')
    === 'https://www.numistr.org/tr/sikke-hikayeleri/34073-kanatli-at');
check('articleUrl EN', $S::articleUrl('https://www.numistr.org', 'en', 34071, 'i-came')
    === 'https://www.numistr.org/en/coin-stories/34071-i-came');
check('introImage göreli → mutlak, # parçası atılır',
    $S::introImage('{"image_intro":"images/a.jpg#joomlaImage://local-images/a.jpg?width=1"}', 'https://www.numistr.org')
    === 'https://www.numistr.org/images/a.jpg');
check('introImage mutlak kalır', $S::introImage('{"image_intro":"https://www.numistr.org/index.php?option=com_numistr&view=gorsel&id=1&wm=0"}', 'https://x')
    === 'https://www.numistr.org/index.php?option=com_numistr&view=gorsel&id=1&wm=0');
check('introImage boş → null', $S::introImage('{"image_intro":""}', 'https://x') === null && $S::introImage(null, 'https://x') === null);

// ------------------------------------------------------- plain / formatCoin ---
check('plain: etiket + varlık', $S::plain('<p>Kanatlı &amp; at</p>', 50) === 'Kanatlı & at');
check('plain: sözcük sınırında kesilir', $S::plain('bir iki üç dört beş altı yedi', 16) === 'bir iki üç dört…', $S::plain('bir iki üç dört beş altı yedi', 16));
$row = ['article_id' => '21086', 'title_tr' => 'CN Type 20572', 'title_en' => null, 'slug' => 'cn-type-20572', 'region_code' => 'mysia-coins',
    'alias' => 'cn-type-20572', 'mint' => 'lampsacus', 'authority' => '', 'date_from' => '-520', 'date_to' => '-480',
    'obv' => 'Sola dönük kanatlı atın ön yarısı; tümü asma çelengi içinde.', 'rev' => 'Dört bölmeli çukur kare.'];
$c = $S::formatCoin($row, 'tr', 'https://www.numistr.org', 'electrum', 'mint');
check('formatCoin: alanlar', $c['article_id'] === 21086 && $c['title'] === 'CN Type 20572' && $c['mint'] === 'lampsacus'
    && $c['authority'] === null && $c['material'] === 'electrum' && $c['date_from'] === -520 && $c['date_to'] === -480
    && $c['region'] === 'mysia-coins' && $c['match'] === 'mint', json_encode($c, JSON_UNESCAPED_UNICODE));
check('formatCoin: adres', $c['url'] === 'https://www.numistr.org/tr/anatolian-coins/mysia-coins/21086-cn-type-20572');
check('formatCoin: toplam sayı alanı YOK', !array_key_exists('total', $c));
$c = $S::formatCoin(['title_tr' => '', 'title_en' => 'EN', 'date_from' => 'x'] + $row, 'en', 'https://x', null, 'title');
check('formatCoin: EN başlık + sayısal olmayan tarih null', $c['title'] === 'EN' && $c['date_from'] === null);
