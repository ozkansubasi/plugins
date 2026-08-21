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

    /*
     * AI Asistan (ADR-003). Anahtarlar:
     *   GEMINI_API_KEY    : Google AI Studio (aistudio.google.com) API key
     *   ANTHROPIC_API_KEY : console.anthropic.com API key
     *   KB_WEBHOOK_SECRET : n8n numistr-kb-query webhook'una X-NumisTR-KB basligiyla gonderilen paylasilan sir
     * Bos birakilan anahtar ilgili rotayi devre disi birakir (asistan 503 / tool hata dondurur).
     */
    'GEMINI_API_KEY'    => '',
    'ANTHROPIC_API_KEY' => '',
    'KB_WEBHOOK_SECRET' => '',
];
