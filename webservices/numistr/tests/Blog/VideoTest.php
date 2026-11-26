<?php
/**
 * Sayfa videoları (1.30.0): tablo satırı → uç biçimi. DB'li forArticle yalnız tablo yokken boş dizi döndürmeli.
 */

require_once $root . '/helpers/VideoHelper.php';

$V = 'NumisTRVideos';
$r = ['youtube_id' => 'dQw4w9WgXcQ', 'bicim' => 'shorts', 'baslik' => ' Zela ', 'kunye' => 'Berlin · Brahms', 'sure_sn' => 52,
    'kucuk_resim' => 'images/videolar/dQw4w9WgXcQ.jpg', 'yayin_tarihi' => '2026-11-27 16:30:00'];
$f = $V::format($r, 'https://www.numistr.org/');
check('video: alanlar', $f === [
    'youtube_id' => 'dQw4w9WgXcQ', 'format' => 'shorts', 'title' => 'Zela', 'credit' => 'Berlin · Brahms', 'duration_sec' => 52,
    'thumbnail_url' => 'https://www.numistr.org/images/videolar/dQw4w9WgXcQ.jpg',
    'watch_url' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'published_at' => '2026-11-27T16:30:00Z'], json_encode($f));
$g = $V::format(['bicim' => 'video', 'sure_sn' => 0, 'kucuk_resim' => '', 'yayin_tarihi' => null] + $r, 'https://x');
check('video: 16:9, boş alanlar null', $g['format'] === 'video' && $g['watch_url'] === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
    && $g['duration_sec'] === null && $g['thumbnail_url'] === null && $g['published_at'] === null);
check('video: geçersiz kimlik → null', $V::format(['youtube_id' => '"><script>'] + $r, 'https://x') === null);
check('video: dış / üst dizin resim yolu reddedilir', $V::format(['kucuk_resim' => '../configuration.php'] + $r, 'https://x')['thumbnail_url'] === null
    && $V::format(['kucuk_resim' => 'https://evil/x.jpg'] + $r, 'https://x')['thumbnail_url'] === null);
check('video: bilinmeyen biçim → shorts', $V::format(['bicim' => 'reel'] + $r, 'https://x')['format'] === 'shorts');
check('video: DB yoksa boş dizi (uç düşmez)', $V::forArticle(34067, 'tr', 'https://x') === []);
check('video: geçersiz makale → boş', $V::forArticle(0, 'tr', 'https://x') === []);
