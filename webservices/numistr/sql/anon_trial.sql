-- M17 — oturumsuz deneme hakki (eklenti 1.18.0, AnonTrialHelper).
-- phpMyAdmin'de bir kez calistirilir; kod bu tablo olmadan da guvenle calisir (deneme kapali kalir).
-- Ham kurulum kimligi SAKLANMAZ, yalnizca SHA-256 ozeti.

CREATE TABLE IF NOT EXISTS `numistr_anon_devices` (
  `device_hash` CHAR(64) NOT NULL COMMENT 'SHA-256(kurulum kimligi)',
  `scans_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'basarili oturumsuz tanima sayisi',
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  PRIMARY KEY (`device_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='M17 oturumsuz deneme sayaci';
