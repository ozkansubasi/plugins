-- ADR-004: iyzico web aboneliği kayıt tablosu
-- phpMyAdmin'de bir kez çalıştırılır (prefix'siz; numistr_billing_events ile aynı desen)

CREATE TABLE IF NOT EXISTS `numistr_subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `source` VARCHAR(16) NOT NULL DEFAULT 'iyzico',
  `plan` VARCHAR(16) NOT NULL,
  `subscription_reference_code` VARCHAR(64) NOT NULL,
  `customer_reference_code` VARCHAR(64) NULL,
  `pricing_plan_reference_code` VARCHAR(64) NULL,
  `status` VARCHAR(16) NOT NULL,
  `current_period_end` DATETIME NULL,
  `canceled_at` DATETIME NULL,
  `conversation_id` VARCHAR(96) NULL,
  `consent_at` DATETIME NULL,
  `consent_ip` VARCHAR(45) NULL,
  `raw_json` MEDIUMTEXT NULL,
  `created` DATETIME NOT NULL,
  `modified` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subref` (`subscription_reference_code`),
  KEY `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
