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
     * ADR-006 Faz 2 — Pro filigransız görsel imza sırrı (HMAC-SHA256).
     * com_numistr bileşeni de bu dosyayı okur (JPATH_PLUGINS/.../secrets.php); bileşen
     * ayarındaki 'hd_sign_secret' yalnızca yedek. Boş bırakılırsa özellik kapalıdır.
     *   php -r "echo bin2hex(random_bytes(32));"
     */
    'image_hd_secret' => '',

    /*
     * Görsel tamamlama hattı yönetici uçları (/v1/admin/gallery/*) — HMAC-SHA256 sırrı.
     * Aynı değer yerelde .claude/secrets.local.md içinde 'gallery_admin_secret' olarak durur.
     * En az 32 karakter; boş bırakılırsa uçlar kapalıdır (503).
     *   php -r "echo bin2hex(random_bytes(32));"
     */
    'gallery_admin_secret' => '',

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
