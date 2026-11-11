<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * Dönemsel içerik hattı — blog makalesi yazma (otomasyon planı §6 adım 5).
 *
 * Şimdiye kadar her yazı tarayıcıda CSVI içe aktarımı + Joomla formunda etiket/yayın ile giriyordu; bu sınıf aynı
 * işi tek imzalı istekle yapar (scripts/icerik_uretec/yayinla.py). Makale com_content'in kendi yönetici modeliyle
 * kaydedilir (formdaki "Kaydet" ile aynı yol: etiketler, varlık kaydı, iş akışı, olay eklentileri).
 *
 * Eşleşme anahtarı (kategori, alias): aynı alias ile ikinci istek GÜNCELLEMEDİR (CSVI'deki upsert davranışı).
 * Yalnız izinli kategorilere (BLOG_ADMIN.allowed_catids) yazar; sikke kataloğuna dokunamaz.
 *
 * İmza galeri/AI uçlarıyla aynı biçimde, AYRI önek ('blog|') ve AYRI sırla (secrets.php 'blog_admin_secret').
 * Saf (DB'siz) kısım tests/Blog/BlogAdminTest.php ile sınanır.
 *
 * @since 1.23.0 (1.23.1: bileşen sabitleri, yeni makalede iş akışı ilişkisi)
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

final class NumisTRBlogAdmin
{
    public const MIN_SECRET_LEN = 32;
    public const ALIAS_RE       = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const DILLER         = ['tr-TR', 'en-GB', '*'];
    public const MAX_HTML       = 300000;
    public const MAX_ETIKET     = 40;

    // ------------------------------------------------------------------ imza ---

    public static function payload(string $method, string $action, int $ts, string $body): string
    {
        return 'blog|' . strtoupper($method) . '|' . $action . '|' . $ts . '|' . hash('sha256', $body);
    }

    public static function sign(string $method, string $action, int $ts, string $body, string $secret): string
    {
        return hash_hmac('sha256', self::payload($method, $action, $ts, $body), $secret);
    }

    /** Sır kısa/boşsa uç KAPALI (false). Zaman damgası ±$skew sn dışında ise tekrar oynatma reddi. */
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

    // -------------------------------------------------------------- doğrulama ---

    /**
     * İstek gövdesini doğrular ve normalleştirir. Döner: [veri, null] ya da [null, hata metni]. Hata metinleri
     * yalnız bu sınıftaki sabit dizgelerdir (istisna metni istemciye gitmez — S28).
     *
     * @param  array  $in   {title, alias, introtext, fulltext, catid, state, language?, metadesc?, tags?}
     * @param  int[]  $izinliKategoriler
     */
    public static function normalize(array $in, array $izinliKategoriler): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            return [null, 'title 1-255 karakter olmali'];
        }
        $alias = (string) ($in['alias'] ?? '');
        if (strlen($alias) < 3 || strlen($alias) > 190 || !preg_match(self::ALIAS_RE, $alias)) {
            return [null, 'alias kucuk harf/rakam/tire, 3-190 karakter olmali'];
        }
        $catid = (int) ($in['catid'] ?? 0);
        if (!in_array($catid, array_map('intval', $izinliKategoriler), true)) {
            return [null, 'catid izinli blog kategorilerinden biri olmali'];
        }
        $state = $in['state'] ?? 0;
        if (!in_array($state, [0, 1], true)) {
            return [null, 'state 0 (yayimsiz) ya da 1 (yayimli) olmali'];
        }
        $language = (string) ($in['language'] ?? 'tr-TR');
        if (!in_array($language, self::DILLER, true)) {
            return [null, 'language tr-TR, en-GB ya da * olmali'];
        }
        $intro = (string) ($in['introtext'] ?? '');
        $full  = (string) ($in['fulltext'] ?? '');
        if (trim($intro . $full) === '' || strlen($intro) > self::MAX_HTML || strlen($full) > self::MAX_HTML) {
            return [null, 'introtext/fulltext bos olamaz ve her biri en cok ' . self::MAX_HTML . ' bayt'];
        }
        $metadesc = trim((string) ($in['metadesc'] ?? ''));
        if (mb_strlen($metadesc) > 300) {
            return [null, 'metadesc en cok 300 karakter'];
        }
        $tags = $in['tags'] ?? [];
        if (!is_array($tags) || count($tags) > self::MAX_ETIKET) {
            return [null, 'tags en cok ' . self::MAX_ETIKET . ' etiket kimligi listesi olmali'];
        }
        $tagIds = [];
        foreach ($tags as $t) {
            if (!is_int($t) || $t <= 1) { // 1 = ROOT etiketi
                return [null, 'tags yalniz pozitif tamsayi etiket kimlikleri icerir'];
            }
            $tagIds[$t] = $t;
        }
        // 1.24.0: EN yazının TR eşi (o_associations). Yalnız en-GB yazıda; TR'nin kendisi doğrulanır (esDogrula).
        $esId = $in['es_id'] ?? null;
        if ($esId !== null && (!is_int($esId) || $esId <= 0 || $language !== 'en-GB')) {
            return [null, 'es_id yalniz en-GB yazida pozitif tamsayi olabilir'];
        }

        return [[
            'es_id' => $esId, 'title' => $title, 'alias' => $alias, 'catid' => $catid, 'state' => $state, 'language' => $language,
            'introtext' => $intro, 'fulltext' => $full, 'metadesc' => $metadesc, 'tags' => array_values($tagIds),
        ], null];
    }

    // ------------------------------------------------------------------ yazma ---

    /** (kategori, alias) ile mevcut makale; yoksa 0. */
    public static function mevcut($db, int $catid, string $alias): int
    {
        $q = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('catid') . ' = ' . (int) $catid)
            ->where($db->quoteName('alias') . ' = ' . $db->quote($alias))
            ->setLimit(1);
        return (int) $db->setQuery($q)->loadResult();
    }

    /** Joomla AdminModel::save ile aynı anahtar: md5(json_encode([dil => kimlik])), en-GB önce (dil sırası). */
    public static function iliskiAnahtari(int $trId, int $enId): string
    {
        return md5(json_encode(['en-GB' => $enId, 'tr-TR' => $trId]));
    }

    /** TR eş yayında kullanılabilir mi? Null = uygun; dizge = hata. */
    public static function esDogrula($db, int $esId): ?string
    {
        $q = $db->getQuery(true)
            ->select($db->quoteName(['language', 'state']))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = ' . $esId);
        $r = $db->setQuery($q)->loadAssoc();
        if (!$r) {
            return 'es_id makalesi yok: ' . $esId;
        }
        return $r['language'] === 'tr-TR' ? null : 'es_id tr-TR dilinde degil: ' . $esId;
    }

    /**
     * TR ↔ EN ilişkisini yazar. Zaten bu ikili bağlıysa dokunmaz; iki taraftan biri BAŞKA bir ilişkideyse sessizce
     * ezmez, hata verir. Döner: 'baglandi' | 'zaten_bagli'.
     */
    public static function iliskilendir($db, int $trId, int $enId): string
    {
        $baglam = 'com_content.item';
        $anahtar = self::iliskiAnahtari($trId, $enId);
        $q = $db->getQuery(true)
            ->select($db->quoteName(['id', 'key']))
            ->from($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = ' . $db->quote($baglam))
            ->where($db->quoteName('id') . ' IN (' . $trId . ',' . $enId . ')');
        $mevcut = $db->setQuery($q)->loadAssocList() ?: [];
        $ayni = 0;
        foreach ($mevcut as $m) {
            if ($m['key'] !== $anahtar) {
                throw new \RuntimeException('makale ' . $m['id'] . ' baska bir iliskide; elle cozulmeli');
            }
            $ayni++;
        }
        if ($ayni === 2) {
            return 'zaten_bagli';
        }
        $var = array_map('intval', array_column($mevcut, 'id'));
        foreach ([$trId, $enId] as $id) {
            if (in_array($id, $var, true)) {
                continue;
            }
            $ekle = $db->getQuery(true)
                ->insert($db->quoteName('#__associations'))
                ->columns($db->quoteName(['id', 'context', 'key']))
                ->values($id . ',' . $db->quote($baglam) . ',' . $db->quote($anahtar));
            $db->setQuery($ekle)->execute();
        }
        return 'baglandi';
    }

    /** Var olmayan etiket kimlikleri (yazımdan ÖNCE denetlenir; yarım etiketli yazı olmasın). */
    public static function eksikEtiketler($db, array $tagIds): array
    {
        if (!$tagIds) {
            return [];
        }
        $q = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__tags'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $tagIds)) . ')')
            ->where($db->quoteName('published') . ' = 1');
        $var = array_map('intval', $db->setQuery($q)->loadColumn() ?: []);
        return array_values(array_diff($tagIds, $var));
    }

    /**
     * 1.24.3 (2026-11-12, C11): yeni yazının giriş görseli = ilk figürün ilk görseli (`{numistr_gorsel … gorsel=ID…}`),
     * 480 px filigransız küçük görsel (com_numistr `wm=0`). Ana sayfa kartları ve blog listesi bunu gösterir.
     * Figür yoksa '{}' (görselsiz). Güncellemede çağrılmaz: elle seçilmiş görsel ezilmez.
     */
    public static function girisGorseli(string $introtext, string $fulltext, string $title): string
    {
        if (!preg_match('/\{numistr_gorsel\b[^}]*?\bgorsel=(\d+)/', $introtext . "\n" . $fulltext, $m)) {
            return '{}';
        }

        return json_encode([
            'image_intro'     => 'https://www.numistr.org/index.php?option=com_numistr&view=gorsel&format=raw&id=' . (int) $m[1] . '&wm=0',
            'image_intro_alt' => $title,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Makaleyi com_content yönetici modeliyle kaydeder (yeni ya da güncelleme). Döner: [id, islem].
     * $createdBy: yeni makalenin yazarı (BLOG_ADMIN.created_by; API isteğinde Joomla oturumu yok).
     */
    public static function kaydet(array $d, int $mevcutId, int $createdBy): array
    {
        // 1.23.1 (2026-10-18, canlıda 500): API isteğinde hiçbir bileşen dağıtılmaz → JPATH_COMPONENT* tanımsız.
        // Model YENİ makaleden sonra iş akışının ilk aşamasını yazmak için form yükler (WorkflowBehaviorTrait::
        // workflowAfterSave → getForm → FormBehaviorTrait::loadForm, JPATH_COMPONENT . '/forms'). Sabit yoksa makale
        // kaydedilir ama iş akışı ilişkisi yazılmadan istisna fırlar. İstek bu uçta biter; sabitler başka koda sızmaz.
        foreach (['JPATH_COMPONENT' => JPATH_ADMINISTRATOR . '/components/com_content',
                  'JPATH_COMPONENT_ADMINISTRATOR' => JPATH_ADMINISTRATOR . '/components/com_content',
                  'JPATH_COMPONENT_SITE' => JPATH_SITE . '/components/com_content'] as $sabit => $yol) {
            if (!defined($sabit)) {
                define($sabit, $yol);
            }
        }

        $app   = Factory::getApplication();
        $model = $app->bootComponent('com_content')->getMVCFactory()
            ->createModel('Article', 'Administrator', ['ignore_request' => true]);

        $veri = [
            'id'        => $mevcutId,
            'title'     => $d['title'],
            'alias'     => $d['alias'],
            'catid'     => $d['catid'],
            'state'     => $d['state'],
            'language'  => $d['language'],
            'introtext' => $d['introtext'],
            'fulltext'  => $d['fulltext'],
            'metadesc'  => $d['metadesc'],
            'tags'      => $d['tags'],
            'access'    => 1,
        ];
        if ($mevcutId === 0) {
            // NOT NULL sütunlar formda varsayılanla gelir; modelin doğrudan çağrısında biz veririz
            $veri += ['created_by' => $createdBy, 'metakey' => '',
                      'images' => self::girisGorseli($d['introtext'], $d['fulltext'], $d['title']), 'urls' => '{}',
                      'attribs' => '{}', 'metadata' => '{}', 'featured' => 0];
        }
        if ($mevcutId === 0 && $d['state'] === 1) {
            // blog listesi yayın tarihine göre sıralanır; güncellemede ilk yayın tarihi korunur
            $veri['publish_up'] = Factory::getDate()->toSql();
        }

        if (!$model->save($veri)) {
            throw new \RuntimeException('com_content kaydetmedi: ' . $model->getError());
        }
        $id = (int) $model->getState('article.id');
        if ($id <= 0) {
            throw new \RuntimeException('com_content kaydetti ama kimlik donmedi');
        }
        return [$id, $mevcutId ? 'guncellendi' : 'olusturuldu'];
    }
}
