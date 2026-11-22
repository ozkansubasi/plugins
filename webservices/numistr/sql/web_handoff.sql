-- Uygulamadan siteye tek kullanimlik gecis kodu (eklenti 1.28.0 + plg_system_numistrauth 1.3.0, 22 Kas 2026).
-- Bir kez calistirilir; kod bu tablo olmadan da guvenle calisir (uc 503 HANDOFF_UNAVAILABLE doner, uygulama duz adrese duser).
-- Ham kod SAKLANMAZ, yalnizca SHA-256 ozeti. IP / cihaz bilgisi tutulmaz. Kayitlar 1 gunde silinir (her kod uretiminde).

CREATE TABLE IF NOT EXISTS `numistr_web_handoff` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL COMMENT 'o_users.id',
  `code_hash` CHAR(64) NOT NULL COMMENT 'SHA-256(kod)',
  `return_path` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '/tr/... ya da /en/... goreli yol',
  `created_at` DATETIME NOT NULL COMMENT 'UTC',
  `expires_at` DATETIME NOT NULL COMMENT 'UTC, created_at + 120 sn',
  `used_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC; doluysa kod tuketildi',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_hash` (`code_hash`),
  KEY `user_created` (`user_id`, `created_at`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Uygulama -> site tek kullanimlik gecis kodlari';
