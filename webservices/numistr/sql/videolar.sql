-- Sayfalara gomulen YouTube videolari (BACKLOG 12b V1, 26 Kas 2026).
-- Kaynak: Pazarlama Merkezi > "Dijital Pazarlama" sekmesi (sahibi Prime): yayin_youtube + ilgili_sayfa dolu satirlar.
-- Dolduran: /home/numistro/video_esitle/video_esitle.php (cron, saatte bir; depoda scripts/sunucu/video_esitle.php).
-- Okuyan: mod_numistr_ilgili 1.3.0 (site, "Videoda izleyin" blogu) + API 1.30.0 (/v1/articles/{id}, /v1/locations/{id} "videos").
-- Tablo yoksa iki okuyucu da sessizce video gostermez. Bir kez calistirilir.

CREATE TABLE IF NOT EXISTS `numistr_videolar` (
  `kimlik` VARCHAR(100) NOT NULL COMMENT 'Dijital Pazarlama kimlik (klip-...)',
  `makale_id` INT UNSIGNED NOT NULL COMMENT 'o_content.id; ilgili_sayfa adresindeki /<id>-<alias>',
  `dil` CHAR(2) NOT NULL COMMENT 'tr | en (makalenin dili ile ayni olmak zorunda)',
  `youtube_id` CHAR(11) NOT NULL,
  `bicim` VARCHAR(8) NOT NULL DEFAULT 'shorts' COMMENT 'shorts (9:16) | video (16:9)',
  `baslik` VARCHAR(255) NOT NULL DEFAULT '',
  `aciklama` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'kanca; VideoObject description',
  `kunye` TEXT NULL COMMENT 'lisans kunyesi (CC BY atfi), sayfada video altinda',
  `sure_sn` SMALLINT UNSIGNED NULL,
  `kucuk_resim` VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'site kokune goreli (images/videolar/<id>.jpg); bossa yer tutucu',
  `yayin_tarihi` DATETIME NULL COMMENT 'UTC',
  `dogrulama` DATETIME NULL COMMENT 'UTC; son YouTube oEmbed denetimi (herkese acik + gomulebilir)',
  `guncelleme` DATETIME NOT NULL COMMENT 'UTC; son esitleme',
  PRIMARY KEY (`kimlik`),
  KEY `makale` (`makale_id`, `dil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Sayfalara gomulen YouTube videolari (Pazarlama Merkezi eslemesi)';
