-- ============================================================
-- NumisTR AI Assistant - Phase 1 (anonymous "Genel" assistant)
-- ADR-003 / Veri bolumu. Prefix placeholder: #__  (Joomla prefix = o_)
-- Calistirma: phpMyAdmin > numistr DB > SQL sekmesi (asagidaki o_ kopyasini kullan)
-- KVKK: anon_key = sha256(ip + user-agent + cookie). Ham IP SAKLANMAZ.
-- ============================================================

CREATE TABLE IF NOT EXISTS `#__numistr_assistant_conversation` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Joomla user id (Faz 2+), anonim ise NULL',
  `anon_key`     CHAR(64) NULL DEFAULT NULL COMMENT 'sha256(ip+ua+cookie) - ham IP yok',
  `subject_type` ENUM('anon','user','pro') NOT NULL DEFAULT 'anon',
  `lang`         CHAR(2) NOT NULL DEFAULT 'tr',
  `title`        VARCHAR(200) NULL DEFAULT NULL,
  `created`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived`     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_anon` (`anon_key`, `last_at`),
  KEY `idx_user` (`user_id`, `last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__numistr_assistant_message` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `role`            ENUM('user','assistant','system') NOT NULL,
  `content`         MEDIUMTEXT NULL,
  `route`           VARCHAR(32) NULL DEFAULT NULL COMMENT 'site|coin_search|settlement|explain|other|keyword|blocked|quota|ban',
  `model`           VARCHAR(64) NULL DEFAULT NULL,
  `tokens_in`       INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out`      INT UNSIGNED NOT NULL DEFAULT 0,
  `cost_usd`        DECIMAL(10,6) NOT NULL DEFAULT 0,
  `cache_hit`       TINYINT(1) NOT NULL DEFAULT 0,
  `created`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`, `id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__numistr_assistant_tool_log` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`   INT UNSIGNED NULL DEFAULT NULL,
  `tool`         VARCHAR(64) NOT NULL,
  `params_json`  TEXT NULL,
  `ok`           TINYINT(1) NOT NULL DEFAULT 0,
  `result_count` INT NOT NULL DEFAULT 0,
  `error`        VARCHAR(255) NULL DEFAULT NULL,
  `ms`           INT UNSIGNED NOT NULL DEFAULT 0,
  `created`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tool` (`tool`, `created`),
  KEY `idx_msg` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__numistr_assistant_quota` (
  `day`          DATE NOT NULL,
  `subject_type` ENUM('anon','user','pro') NOT NULL,
  `subject_key`  VARCHAR(64) NOT NULL COMMENT 'anon_key veya user id',
  `messages`     INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_in`    INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out`   INT UNSIGNED NOT NULL DEFAULT 0,
  `cost_usd`     DECIMAL(10,6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `subject_type`, `subject_key`),
  KEY `idx_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__numistr_assistant_abuse` (
  `subject_key`  VARCHAR(64) NOT NULL,
  `subject_type` ENUM('anon','user','pro') NOT NULL DEFAULT 'anon',
  `score`        DECIMAL(8,2) NOT NULL DEFAULT 0,
  `ban_type`     ENUM('none','soft','hard') NOT NULL DEFAULT 'none',
  `banned_until` DATETIME NULL DEFAULT NULL,
  `total_bans`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_event`   VARCHAR(64) NULL DEFAULT NULL,
  `updated`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`subject_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gemini explicit cache adi/suresi/icerik-hash + ileride admin override'lari
CREATE TABLE IF NOT EXISTS `#__numistr_assistant_settings` (
  `key`     VARCHAR(64) NOT NULL,
  `value`   TEXT NULL,
  `updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* ============================================================
   LITERAL o_ PREFIX COPY (phpMyAdmin'e bunu yapistir)
   ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `o_numistr_assistant_conversation` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `anon_key` CHAR(64) NULL DEFAULT NULL,
  `subject_type` ENUM('anon','user','pro') NOT NULL DEFAULT 'anon',
  `lang` CHAR(2) NOT NULL DEFAULT 'tr',
  `title` VARCHAR(200) NULL DEFAULT NULL,
  `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_anon` (`anon_key`, `last_at`),
  KEY `idx_user` (`user_id`, `last_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `o_numistr_assistant_message` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant','system') NOT NULL,
  `content` MEDIUMTEXT NULL,
  `route` VARCHAR(32) NULL DEFAULT NULL,
  `model` VARCHAR(64) NULL DEFAULT NULL,
  `tokens_in` INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out` INT UNSIGNED NOT NULL DEFAULT 0,
  `cost_usd` DECIMAL(10,6) NOT NULL DEFAULT 0,
  `cache_hit` TINYINT(1) NOT NULL DEFAULT 0,
  `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`, `id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `o_numistr_assistant_tool_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT UNSIGNED NULL DEFAULT NULL,
  `tool` VARCHAR(64) NOT NULL,
  `params_json` TEXT NULL,
  `ok` TINYINT(1) NOT NULL DEFAULT 0,
  `result_count` INT NOT NULL DEFAULT 0,
  `error` VARCHAR(255) NULL DEFAULT NULL,
  `ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tool` (`tool`, `created`),
  KEY `idx_msg` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `o_numistr_assistant_quota` (
  `day` DATE NOT NULL,
  `subject_type` ENUM('anon','user','pro') NOT NULL,
  `subject_key` VARCHAR(64) NOT NULL,
  `messages` INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_in` INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out` INT UNSIGNED NOT NULL DEFAULT 0,
  `cost_usd` DECIMAL(10,6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `subject_type`, `subject_key`),
  KEY `idx_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `o_numistr_assistant_abuse` (
  `subject_key` VARCHAR(64) NOT NULL,
  `subject_type` ENUM('anon','user','pro') NOT NULL DEFAULT 'anon',
  `score` DECIMAL(8,2) NOT NULL DEFAULT 0,
  `ban_type` ENUM('none','soft','hard') NOT NULL DEFAULT 'none',
  `banned_until` DATETIME NULL DEFAULT NULL,
  `total_bans` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_event` VARCHAR(64) NULL DEFAULT NULL,
  `updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`subject_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `o_numistr_assistant_settings` (
  `key` VARCHAR(64) NOT NULL,
  `value` TEXT NULL,
  `updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

   ============================================================ */
