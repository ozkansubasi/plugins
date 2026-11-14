-- 1.5.0 (2026-11-14): kart deneme savunması — ödeme formu açma denemeleri
-- phpMyAdmin'de bir kez çalıştırılır (prefix'siz; numistr_subscriptions ile aynı desen).
-- Kod tablo yoksa ödeme formunu AÇMAZ (fail-closed) — önce bu tablo, sonra kod.
-- status: init | init_failed | blocked | auth_success | auth_failure

CREATE TABLE IF NOT EXISTS `numistr_billing_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'subscribe',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `status` VARCHAR(16) NOT NULL,
  `reason` VARCHAR(32) NOT NULL DEFAULT '',
  `conversation_id` VARCHAR(96) NULL,
  `created` DATETIME NOT NULL,
  `modified` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created`),
  KEY `idx_user_created` (`user_id`, `created`),
  KEY `idx_ip_created` (`ip`, `created`),
  KEY `idx_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
