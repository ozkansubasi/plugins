-- ADR-013 — çerez rızası ispat kaydı. Eklenti manifestine bağlı değil: tablo runbook'la (ana oturum, kullanıcı onayı) kurulur.
-- Yalnız ekleme yapılır; saklama süresi (eklenti ayarı saklama_gun, varsayılan 730) dolan satırları eklenti kendisi siler.
-- IP, User-Agent, kullanıcı hesabı ve GA kimliği TUTULMAZ.
CREATE TABLE IF NOT EXISTS `numistr_cerez_rizalari` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `onay_kimligi` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'istemcide üretilen 128 bit rastgele kimlik (hex)',
  `zaman` DATETIME(3) NOT NULL COMMENT 'sunucu zamanı, UTC',
  `istemci_zaman` BIGINT NOT NULL COMMENT 'tercih çerezindeki t (ms)',
  `metin_surumu` VARCHAR(32) NOT NULL COMMENT 'metinler.json surum; metin depoda cerez-metni/<surum> etiketinde',
  `eylem` VARCHAR(16) NOT NULL COMMENT 'tumunu_kabul | tumunu_ret | secim_kaydet | geri_cekme',
  `katman` TINYINT UNSIGNED NOT NULL COMMENT '1 pencere, 2 tercih merkezi',
  `analitik` TINYINT(1) NOT NULL,
  `pazarlama` TINYINT(1) NOT NULL,
  `kisisellestirme` TINYINT(1) NOT NULL DEFAULT 0,
  `dil` CHAR(2) NOT NULL,
  `sayfa` VARCHAR(191) NULL COMMENT 'yalnız yol; yeniden gönderimde NULL',
  PRIMARY KEY (`id`),
  UNIQUE KEY `onay_olay` (`onay_kimligi`, `istemci_zaman`),
  KEY `zaman` (`zaman`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Çerez rızası ispat kaydı (ADR-013); IP/UA tutulmaz';
