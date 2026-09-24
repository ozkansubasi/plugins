<?php
/**
 * Katalog motif araması — saf (DB'siz) eşleme ve toplama.
 *
 * Vakalar 2026-09-23/24'te phpMyAdmin ölçümlerinde düşülen gerçek tuzaklardır
 * (scripts/icerik_uretec/sorgular.md): harpa/arpa, örgülü/gül, noktaların/arı, cümle başı.
 */

require_once $root . '/helpers/MotifSearchHelper.php';

// ------------------------------------------------------------ terimler ---
check('terimler: virgül, boşluk, tekil, küçük harf',
    NumisTRMotifSearch::parseTerms(' Başak, buğday ,başak,,x ') === ['başak', 'buğday']);
check('terimler: Türkçe I/İ', NumisTRMotifSearch::parseTerms('IŞIK,İsis') === ['ışık', 'isis']);
check('terimler: en çok 8', count(NumisTRMotifSearch::parseTerms('aa,bb,cc,dd,ee,ff,gg,hh,ii,jj')) === 8);
check('terimler: boş sorgu', NumisTRMotifSearch::parseTerms(' , ') === []);

// ------------------------------------------------------------- eşleme ---
$m = fn (string $t, string $q) => NumisTRMotifSearch::matches($t, NumisTRMotifSearch::parseTerms($q));
check('arpa eşleşir', $m('Zeytin dalı; iki arpa tanesi', 'arpa'));
check('harpa (savaş orağı) arpa DEĞİL', !$m('Perseus elinde harpa tutuyor', 'arpa'));
check('gül eşleşir', $m('Helios başı; arka yüz: Gül ve tomurcuk', 'gül'));
check('örgülü gül DEĞİL', !$m('Örgülü saçlı kadın başı', 'gül'));
check('arı: cümle başı eşleşir', $m('Arı üç delme zımbası', 'arı'));
check('noktaların/yukarıda arı DEĞİL', !$m('Noktaların sınırı; yukarıda yıldız', 'arı'));
check('Türkçe ek serbest: başağı', $m('Elinde tahıl başağı tutan Demeter', 'başak') === false
    && $m('Elinde tahıl başakları tutan Demeter', 'başak'));
check('ünsüz yumuşaması için kök terimi: başa', $m('Elinde tahıl başağı tutan Demeter', 'başa'));
check('büyük/küçük harf duyarsız', $m('TON BALIĞI', 'ton balığı'));
check('noktalama sonrası sözcük başı', $m('Orkinos;ton balığı', 'ton balığı'));

// ------------------------------------------------------------ toplama ---
$rows = [
    ['id' => 3, 'mint' => 'cyzicus', 'start' => '-600', 'end' => '-550', 'obv' => 'Ton balığı', 'rev' => ''],
    ['id' => 1, 'mint' => 'cyzicus', 'start' => '-410', 'end' => '-330', 'obv' => 'Herakles', 'rev' => 'altta ton balığı'],
    ['id' => 2, 'mint' => 'phocaea', 'start' => '-600', 'end' => '-522', 'obv' => 'Fok', 'rev' => ''],
    ['id' => 4, 'mint' => '', 'start' => null, 'end' => '100', 'obv' => 'ton balığı', 'rev' => null],
];
$a = NumisTRMotifSearch::aggregate($rows, ['ton balığı']);
check('toplam 3 (fok süzüldü)', $a['total'] === 3, (string) $a['total']);
check('darphane sayısı 2 (boş → "(yok)")', $a['mint_count'] === 2, json_encode($a['mints']));
check('en kalabalık darphane önce', $a['mints'][0] === ['mint' => 'cyzicus', 'count' => 2]);
check('tarih aralığı -600..100', $a['years'] === ['from' => -600, 'to' => 100], json_encode($a['years']));
check('örnek kimlikler sıralı', $a['sample_ids'] === [1, 3, 4]);
check('boş satır listesi', NumisTRMotifSearch::aggregate([], ['x'])['total'] === 0);
