<?php
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

/**
 * Numistr Content Plugin
 * Bu eklenti, her bir com_content makalesine, YOOtheme Pro'da dinamik olarak
 * kullanılabilmesi için 'numistr_thumb_url' adında bir özellik ekler.
 */
class PlgContentNumistr extends CMSPlugin
{
    /**
     * @param   string   $context  İçeriğin çağrıldığı bağlam.
     * @param   object   &$item    İçerik makalesi nesnesi (örneğin, bir makale veya kategori).
     * @param   mixed    &$params  Ek parametreler.
     * @param   integer  $page     Sayfa numarası.
     */
    public function onContentPrepare($context, &$item, &$params, $page = 0)
    {
        // Sadece com_content.article bağlamında ve $item bir ID'ye sahipse çalış
        if ($context !== 'com_content.article' || !isset($item->id)) {
            return;
        }

        // Eğer özellik zaten eklenmişse tekrar çalıştırma (performans için)
        if (isset($item->numistr_thumb_url)) {
            return;
        }

        try {
            // Makale ID'sini al
            $articleId = (int) $item->id;

            // Veritabanından bu makaleye ait ilk görselin ID'sini al
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select($db->quoteName('image_id'))
                ->from($db->quoteName('coins_images'))
                ->where($db->quoteName('coin_id') . ' = ' . $articleId)
                ->order($db->quoteName('ordering') . ' ASC');

            $db->setQuery($query, 0, 1);
            $imageId = (int) $db->loadResult();
            
            // Görsel bulunamazsa, özelliği boş bir değerle ayarla ve çık
            if (!$imageId) {
                $item->numistr_thumb_url = '';
                return;
            }
            
            // Görsel URL'sini oluştur
            $queryParams = [
                'option' => 'com_numistr',
                'view'   => 'gorsel',
                'id'     => $imageId,
                'wm'     => 0
            ];
            $imageUrl = Route::_('index.php?' . http_build_query($queryParams));

            // URL'yi makale nesnesine yeni bir özellik olarak ekle
            $item->numistr_thumb_url = $imageUrl;

        } catch (\Throwable $e) {
            // Bir hata olursa, özelliği boş olarak ayarla ki sayfa bozulmasın.
            $item->numistr_thumb_url = '';
            // Opsiyonel: Hatayı loglayabilirsiniz.
            // Factory::getApplication()->enqueueMessage('Numistr Plugin Hatası: ' . $e->getMessage(), 'error');
        }
    }
}