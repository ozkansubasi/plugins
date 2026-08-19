<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Webservices.numistr
 *
 * NumisTR gizli yapılandırma ŞABLONU.
 *
 * KULLANIM:
 *   1. Bu dosyayı sunucuda `config/secrets.php` adıyla kopyalayın.
 *   2. Değerleri doldurun.
 *   3. secrets.php ASLA git'e commit edilmez (.gitignore'da).
 */

defined('_JEXEC') or die;

return [
    /*
     * RevenueCat webhook paylaşılan sırrı.
     *
     * RevenueCat panelinde: Project > Integrations > Webhooks > Authorization header
     * alanına yazdığınız değerin BİREBİR aynısı olmalı (RevenueCat bu değeri
     * Authorization header'ında olduğu gibi gönderir).
     *
     * Öneri: en az 32 karakter rastgele dize, örn:
     *   php -r "echo bin2hex(random_bytes(24));"
     */
    'revenuecat_webhook_secret' => '',
];
