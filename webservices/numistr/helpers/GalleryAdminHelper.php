<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * Görsel tamamlama hattının sunucu yarısı (scripts/gorsel_kurtarma/gorsel_hatti.py'nin karşılığı).
 *
 * Eski yol: FTP → CSVI (alan 35) → web kökündeki korumasız migrate_images.php → coins_images.
 * Bu sınıf aynı işi tek, imzalı ve geri alınabilir bir işlemde yapar:
 *   - dosyalar ÖNCEDEN ../sikke/{klasör}/ altına konmuş olmalı (hat, cPanel API ile yükler);
 *   - publish: alan 35 (image-gallery-urls) + coins_images, makale başına tek transaction;
 *   - her yazım numistr_gallery_runs tablosuna run_id ile kaydedilir → rollback kesin.
 *
 * Bilinçli darlık: yalnız HİÇ görseli ve galeri JSON'u olmayan makalelere yazar. Var olan bir
 * galeriye dokunmak image_id'leri (AI indeksi + view=gorsel URL'leri) riske atar; o iş bu ucun
 * kapsamı dışındadır.
 *
 * Saf (DB'siz) kısım statiktir ve tests/Gallery/GalleryAdminTest.php ile sınanır.
 *
 * @since 1.13.0
 */

defined('_JEXEC') or die;

final class NumisTRGalleryAdmin
{
    /** n{makale_id}_{sıra}_{tip}.{uzantı} — hattın ürettiği tek biçim; 'n' öneki eski dosyalardan ayırır. */
    public const FILE_RE   = '/^n(\d{1,9})_(\d{1,2})_(on|arka|detay)\.(jpg|png|gif|webp|tif)$/';
    public const RUN_RE    = '/^[A-Za-z0-9_-]{6,40}$/';
    public const LEVELS    = ['exact', 'parent'];
    public const MAX_ITEMS = 12;
    public const MIN_SECRET_LEN = 32;

    // ------------------------------------------------------------------ imza ---

    public static function payload(string $method, string $action, int $ts, string $body): string
    {
        return 'gallery|' . strtoupper($method) . '|' . $action . '|' . $ts . '|' . hash('sha256', $body);
    }

    public static function sign(string $method, string $action, int $ts, string $body, string $secret): string
    {
        return hash_hmac('sha256', self::payload($method, $action, $ts, $body), $secret);
    }

    /** Sır kısa/boşsa uç KAPALI sayılır (false). Zaman damgası ±$skew sn dışında ise tekrar oynatma reddi. */
    public static function verify(string $method, string $action, int $ts, string $body, string $sig, string $secret, int $skew = 300, ?int $now = null): bool
    {
        if (strlen($secret) < self::MIN_SECRET_LEN || $sig === '' || $ts <= 0) {
            return false;
        }
        if (abs(($now ?? time()) - $ts) > $skew) {
            return false;
        }
        return hash_equals(self::sign($method, $action, $ts, $body, $secret), strtolower($sig));
    }

    // ------------------------------------------------------------ doğrulama ---

    public static function safeFolder(?string $folder): bool
    {
        return is_string($folder) && (bool) preg_match('/^[a-z0-9_]{2,40}$/', $folder);
    }

    /** region_map_json anahtarı kategori alias'ıdır (büyük/küçük harf duyarsız). */
    public static function resolveFolder(string $catAlias, array $regionMap): ?string
    {
        $alias = strtolower(trim($catAlias));
        foreach ($regionMap as $key => $folder) {
            if (strtolower((string) $key) === $alias) {
                return self::safeFolder($folder) ? $folder : null;
            }
        }
        return null;
    }

    private static function httpUrl($v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        return ($v !== '' && strlen($v) <= 2000 && preg_match('~^https?://[^\s<>"]+$~i', $v)) ? $v : null;
    }

    /** @return string|null|false  null = verilmemiş, false = sayı değil */
    private static function metric($v)
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = str_replace(',', '.', trim((string) $v));
        return preg_match('/^\d{1,4}(\.\d{1,3})?$/', $s) ? $s : false;
    }

    /**
     * İstekten gelen öğeleri coins_images satırlarına çevirir.
     * @return array{rows: array<int,array<string,mixed>>, errors: string[]}
     */
    public static function normalizeItems(int $articleId, $items): array
    {
        $errors = [];
        $rows   = [];

        if (!is_array($items) || !$items) {
            return ['rows' => [], 'errors' => ['items boş']];
        }
        if (count($items) > self::MAX_ITEMS) {
            return ['rows' => [], 'errors' => ['en çok ' . self::MAX_ITEMS . ' görsel']];
        }

        $seen = [];
        foreach (array_values($items) as $i => $it) {
            $pos = $i + 1;
            $fn  = is_array($it) ? (string) ($it['main_local'] ?? '') : '';

            if (!preg_match(self::FILE_RE, $fn, $m)) {
                $errors[] = "#$pos dosya adı biçimi geçersiz";
                continue;
            }
            if ((int) $m[1] !== $articleId) {
                $errors[] = "#$pos dosya adı başka makaleye ait ($m[1])";
            }
            if ((int) $m[2] !== $pos) {
                $errors[] = "#$pos dosya adındaki sıra ($m[2]) konumla uyuşmuyor";
            }
            if (isset($seen[$fn])) {
                $errors[] = "#$pos dosya adı yineleniyor";
            }
            $seen[$fn] = true;

            $type = (string) ($it['image_type'] ?? '');
            if ($type !== $m[3]) {
                $errors[] = "#$pos image_type dosya adıyla uyuşmuyor";
            }

            $remote = self::httpUrl($it['main_remote'] ?? null);
            if ($remote === null) {
                $errors[] = "#$pos main_remote geçerli bir http(s) URL değil";
            }
            $thumbRemote = self::httpUrl($it['thumb_remote'] ?? null) ?? $remote;

            $w = self::metric($it['weight'] ?? null);
            $d = self::metric($it['diameter'] ?? null);
            if ($w === false || $d === false) {
                $errors[] = "#$pos weight/diameter sayı değil";
            }

            $level = (string) ($it['match_level'] ?? 'exact');
            if (!in_array($level, self::LEVELS, true)) {
                $errors[] = "#$pos match_level geçersiz";
            }
            $matchUri = self::httpUrl($it['match_uri'] ?? null);
            if ($level === 'parent' && $matchUri === null) {
                $errors[] = "#$pos parent eşleşmede match_uri zorunlu";
            }

            $sha1 = strtolower((string) ($it['sha1'] ?? ''));
            if ($sha1 !== '' && !preg_match('/^[0-9a-f]{40}$/', $sha1)) {
                $errors[] = "#$pos sha1 biçimi geçersiz";
            }

            $credit = trim((string) ($it['source_credit'] ?? ''));

            $rows[] = [
                'coin_id'       => $articleId,
                'filename'      => $fn,
                'image_type'    => $type,
                'ordering'      => $pos,
                'weight'        => $w ?: null,
                'diameter'      => $d ?: null,
                'remote_url'    => $remote,
                'thumb_remote'  => $thumbRemote,
                'source_credit' => $credit === '' ? null : mb_substr($credit, 0, 255),
                'match_level'   => $level,
                'match_uri'     => $level === 'parent' ? $matchUri : null,
                'sha1'          => $sha1,
            ];
        }

        return ['rows' => $errors ? [] : $rows, 'errors' => $errors];
    }

    /** Alan 35'in canlıdaki sözleşmesi (bkz. claudedocs/gorsel-tamamlama/HAT-TASARIMI §1.1). */
    public static function galleryJson(array $rows): string
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'thumb_local'  => $r['filename'],
                'main_local'   => $r['filename'],
                'thumb_remote' => $r['thumb_remote'],
                'main_remote'  => $r['remote_url'],
                'weight'       => (string) ($r['weight'] ?? ''),
                'diameter'     => (string) ($r['diameter'] ?? ''),
            ];
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------- DB ---

    private $db;
    private string $sikkeRoot;
    private int $rootCatId;
    private string $logTable = 'numistr_gallery_runs';

    /** @param \Joomla\Database\DatabaseInterface $db */
    public function __construct($db, string $sikkeRoot, int $rootCatId)
    {
        $this->db        = $db;
        $this->sikkeRoot = rtrim($sikkeRoot, '/\\');
        $this->rootCatId = $rootCatId;
    }

    private function regionMap(): array
    {
        $json = $this->db->setQuery(
            "SELECT params FROM #__extensions WHERE element = 'com_numistr' AND type = 'component'", 0, 1
        )->loadResult();
        $params = json_decode((string) $json, true) ?: [];
        $map    = $params['region_map_json'] ?? [];
        if (is_string($map)) {
            $map = json_decode($map, true);
        }
        return is_array($map) ? $map : [];
    }

    private function fieldId(): int
    {
        return (int) $this->db->setQuery(
            "SELECT id FROM #__fields WHERE name = 'image-gallery-urls' AND context = 'com_content.article'", 0, 1
        )->loadResult();
    }

    private function hasMatchColumns(): bool
    {
        $cols = $this->db->getTableColumns('coins_images', false);
        return isset($cols['match_level'], $cols['match_uri']);
    }

    /** Sikke kökü altındaki, yayında ve HİÇ coins_images satırı olmayan makaleler. */
    public function targets(): array
    {
        $db  = $this->db;
        $map = $this->regionMap();
        // item_id varchar'dır: COLLATE olmadan Joomla bağlantısında collation farkı 500 verir (2026-08-19 dersi).
        $sql = "SELECT a.id, a.catid, a.title, c.alias, k.value AS coin_uri
                FROM #__content a
                JOIN #__categories c ON c.id = a.catid
                JOIN #__categories r ON r.id = " . (int) $this->rootCatId . "
                LEFT JOIN #__fields f ON f.name = 'coin-id' AND f.context = 'com_content.article'
                LEFT JOIN #__fields_values k ON k.field_id = f.id AND k.item_id = CAST(a.id AS CHAR) COLLATE utf8mb4_unicode_ci
                WHERE c.lft >= r.lft AND c.rgt <= r.rgt AND a.state = 1
                  AND NOT EXISTS (SELECT 1 FROM coins_images ci WHERE ci.coin_id = a.id)
                ORDER BY a.id";
        $out = [];
        foreach ($db->setQuery($sql)->loadAssocList() as $row) {
            $out[] = [
                'article_id' => (int) $row['id'],
                'catid'      => (int) $row['catid'],
                'folder'     => self::resolveFolder((string) $row['alias'], $map),
                'coin_uri'   => $row['coin_uri'],
                'title'      => $row['title'],
            ];
        }
        return $out;
    }

    /**
     * @param array<int,array{article_id:int,items:array}> $articles
     * @return array<int,array<string,mixed>> makale başına sonuç
     */
    public function publish(string $runId, array $articles, bool $dryRun): array
    {
        $db       = $this->db;
        $map      = $this->regionMap();
        $fieldId  = $this->fieldId();
        $hasMatch = $this->hasMatchColumns();
        $results  = [];

        if ($fieldId <= 0) {
            throw new \RuntimeException("image-gallery-urls alanı bulunamadı");
        }

        foreach ($articles as $art) {
            $aid = (int) ($art['article_id'] ?? 0);
            $res = ['article_id' => $aid, 'status' => 'error', 'reasons' => []];

            $row = $aid > 0 ? $db->setQuery(
                "SELECT a.id, a.state, c.alias,
                        (c.lft >= r.lft AND c.rgt <= r.rgt) AS in_root
                 FROM #__content a
                 JOIN #__categories c ON c.id = a.catid
                 JOIN #__categories r ON r.id = " . (int) $this->rootCatId . "
                 WHERE a.id = " . $aid
            )->loadAssoc() : null;

            if (!$row) {
                $res['reasons'][] = 'makale yok';
            } elseif ((int) $row['state'] !== 1 || !(int) $row['in_root']) {
                $res['reasons'][] = 'makale yayında değil ya da sikke kökü dışında';
            }

            $folder = $row ? self::resolveFolder((string) $row['alias'], $map) : null;
            if ($row && $folder === null) {
                $res['reasons'][] = "kategori '{$row['alias']}' için klasör eşlemesi yok";
            }
            $res['folder'] = $folder;

            $norm = self::normalizeItems($aid, $art['items'] ?? null);
            $res['reasons'] = array_merge($res['reasons'], $norm['errors']);

            if (!$res['reasons']) {
                $existing = (int) $db->setQuery("SELECT COUNT(*) FROM coins_images WHERE coin_id = " . $aid)->loadResult();
                if ($existing > 0) {
                    $res['status']  = 'skipped_has_images';
                    $results[]      = $res;
                    continue;
                }
            }

            $fieldRow = null;
            if (!$res['reasons']) {
                $fieldRow = $db->setQuery(
                    "SELECT value FROM #__fields_values WHERE field_id = " . $fieldId . " AND item_id = " . $db->quote((string) $aid)
                )->loadAssocList();
                if (count($fieldRow) > 1) {
                    $res['reasons'][] = 'alan 35 için birden çok satır var';
                } elseif ($fieldRow && trim((string) $fieldRow[0]['value']) !== '') {
                    $res['status'] = 'skipped_has_gallery_json';
                    $results[]     = $res;
                    continue;
                }
            }

            // Dosyalar gerçekten yerinde ve görsel mi? (yükleme bu uçtan ÖNCE yapılır)
            if (!$res['reasons']) {
                foreach ($norm['rows'] as $r) {
                    $path = $this->sikkeRoot . '/' . $folder . '/' . $r['filename'];
                    if (!is_file($path)) {
                        $res['reasons'][] = $r['filename'] . ' sunucuda yok';
                        continue;
                    }
                    if (!@getimagesize($path)) {
                        $res['reasons'][] = $r['filename'] . ' geçerli görsel değil';
                    } elseif ($r['sha1'] !== '' && sha1_file($path) !== $r['sha1']) {
                        $res['reasons'][] = $r['filename'] . ' sha1 uyuşmuyor (yükleme bozuk)';
                    }
                    if ($r['match_level'] === 'parent' && !$hasMatch) {
                        $res['reasons'][] = 'match_level sütunları yok (migration çalıştırılmamış)';
                    }
                }
            }

            if ($res['reasons']) {
                $res['reasons'] = array_values(array_unique($res['reasons']));
                $results[]      = $res;
                continue;
            }

            $res['inserts'] = count($norm['rows']);
            if ($dryRun) {
                $res['status'] = 'would_publish';
                $results[]     = $res;
                continue;
            }

            try {
                $db->transactionStart();

                $imageIds = [];
                foreach ($norm['rows'] as $r) {
                    $cols = ['coin_id', 'filename', 'image_type', 'ordering', 'weight', 'diameter', 'remote_url', 'source_credit'];
                    if ($hasMatch) {
                        $cols[] = 'match_level';
                        $cols[] = 'match_uri';
                    }
                    $vals = [];
                    foreach ($cols as $c) {
                        $vals[] = $r[$c] === null ? 'NULL' : (is_int($r[$c]) ? (string) $r[$c] : $db->quote((string) $r[$c]));
                    }
                    $db->setQuery(
                        'INSERT INTO coins_images (' . implode(',', array_map([$db, 'quoteName'], $cols)) . ') VALUES (' . implode(',', $vals) . ')'
                    )->execute();
                    $imageIds[] = (int) $db->insertid();
                }

                $json = self::galleryJson($norm['rows']);
                if ($fieldRow) {
                    $db->setQuery(
                        "UPDATE #__fields_values SET value = " . $db->quote($json)
                        . " WHERE field_id = " . $fieldId . " AND item_id = " . $db->quote((string) $aid)
                    )->execute();
                } else {
                    $db->setQuery(
                        "INSERT INTO #__fields_values (field_id, item_id, value) VALUES (" . $fieldId . ", " . $db->quote((string) $aid) . ", " . $db->quote($json) . ")"
                    )->execute();
                }

                $db->setQuery(
                    "INSERT INTO " . $db->quoteName($this->logTable)
                    . " (run_id, article_id, folder, image_ids, files, field_row_existed, created_at) VALUES ("
                    . $db->quote($runId) . ", " . $aid . ", " . $db->quote($folder) . ", "
                    . $db->quote(json_encode($imageIds)) . ", "
                    . $db->quote(json_encode(array_column($norm['rows'], 'filename'))) . ", "
                    . ($fieldRow ? 1 : 0) . ", " . $db->quote(gmdate('Y-m-d H:i:s')) . ")"
                )->execute();

                $db->transactionCommit();
                $res['status']    = 'published';
                $res['image_ids'] = $imageIds;
            } catch (\Throwable $e) {
                $db->transactionRollback();
                $res['status']    = 'error';
                $res['reasons'][] = 'yazım geri alındı: ' . $e->getMessage();
            }
            $results[] = $res;
        }

        return $results;
    }

    /** Bir koşunun yazdığı her şeyi geri alır. Boş galeriye yazıldığı için "önceki değer" hep boştur. */
    public function rollback(string $runId, bool $deleteFiles): array
    {
        $db      = $this->db;
        $fieldId = $this->fieldId();
        $rows    = $db->setQuery(
            "SELECT * FROM " . $db->quoteName($this->logTable) . " WHERE run_id = " . $db->quote($runId) . " AND rolled_back_at IS NULL ORDER BY id"
        )->loadAssocList();
        $results = [];

        foreach ($rows as $log) {
            $aid = (int) $log['article_id'];
            $ids = array_filter(array_map('intval', json_decode((string) $log['image_ids'], true) ?: []));
            $res = ['article_id' => $aid, 'status' => 'error'];

            try {
                $db->transactionStart();
                if ($ids) {
                    $db->setQuery(
                        "DELETE FROM coins_images WHERE coin_id = " . $aid . " AND image_id IN (" . implode(',', $ids) . ")"
                    )->execute();
                    $res['deleted_rows'] = $db->getAffectedRows();
                }
                if ((int) $log['field_row_existed']) {
                    $db->setQuery(
                        "UPDATE #__fields_values SET value = '' WHERE field_id = " . $fieldId . " AND item_id = " . $db->quote((string) $aid)
                    )->execute();
                } else {
                    $db->setQuery(
                        "DELETE FROM #__fields_values WHERE field_id = " . $fieldId . " AND item_id = " . $db->quote((string) $aid)
                    )->execute();
                }
                $db->setQuery(
                    "UPDATE " . $db->quoteName($this->logTable) . " SET rolled_back_at = " . $db->quote(gmdate('Y-m-d H:i:s')) . " WHERE id = " . (int) $log['id']
                )->execute();
                $db->transactionCommit();
                $res['status'] = 'rolled_back';
            } catch (\Throwable $e) {
                $db->transactionRollback();
                $res['reason'] = $e->getMessage();
                $results[]     = $res;
                continue;
            }

            if ($deleteFiles && self::safeFolder($log['folder'])) {
                $res['deleted_files'] = 0;
                foreach (json_decode((string) $log['files'], true) ?: [] as $fn) {
                    // yalnız hattın kendi adlandırdığı ve BU makaleye ait dosyalar silinir
                    if (preg_match(self::FILE_RE, (string) $fn, $m) && (int) $m[1] === $aid) {
                        $path = $this->sikkeRoot . '/' . $log['folder'] . '/' . $fn;
                        if (is_file($path) && @unlink($path)) {
                            $res['deleted_files']++;
                        }
                    }
                }
            }
            $results[] = $res;
        }

        return $results;
    }
}
