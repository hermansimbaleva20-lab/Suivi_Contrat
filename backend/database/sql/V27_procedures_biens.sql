-- ============================================================================
-- PRISE / PREDIRE V27 — PROCÉDURES DES MARCHÉS DE BIENS — MySQL
--
-- Équivalent SQL de la migration 2026_11_04_000003_goods_procedures : procédure BAD, nationale ou fournitures de bureau,
-- origine des biens, échéancier indicatif, pénalité de retard, et avance versée sur demande contre une garantie de restitution.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V26 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v27_run;
DELIMITER $$
CREATE PROCEDURE v27_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts' AND COLUMN_NAME = 'goods_procedure') THEN
    ALTER TABLE `contracts` ADD COLUMN `goods_procedure` VARCHAR(20) NULL,
      ADD COLUMN `goods_origin` VARCHAR(10) NULL,
      ADD COLUMN `payment_schedule` TEXT NULL,
      ADD COLUMN `delay_penalty_rate` DECIMAL(6,3) NULL,
      ADD COLUMN `delay_penalty_cap` DECIMAL(5,2) NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contract_decomptes' AND COLUMN_NAME = 'is_final') THEN
    ALTER TABLE `contract_decomptes` ADD COLUMN `is_final` TINYINT(1) NOT NULL DEFAULT 0,
      ADD COLUMN `reception_date` DATE NULL,
      ADD COLUMN `penalty_weeks` INT UNSIGNED NOT NULL DEFAULT 0,
      ADD COLUMN `penalty_amount` DECIMAL(15,2) NOT NULL DEFAULT 0;
  END IF;
END$$
DELIMITER ;
CALL v27_run();
DROP PROCEDURE IF EXISTS v27_run;

CREATE TABLE IF NOT EXISTS `contract_advances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `request_date` DATE NOT NULL,
  `request_reference` VARCHAR(120) NOT NULL,
  `rate` DECIMAL(5,2) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `guarantee_bank` VARCHAR(191) NOT NULL,
  `guarantee_number` VARCHAR(120) NOT NULL,
  `guarantee_amount` DECIMAL(15,2) NOT NULL,
  `guarantee_end_date` DATE NOT NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'DEMANDEE',
  `paid_date` DATE NULL,
  `payment_reference` VARCHAR(120) NULL,
  `decision_comment` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  KEY `contract_advances_status_idx` (`contract_id`, `status`),
  CONSTRAINT `contract_advances_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000003_goods_procedures', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_04_000003_goods_procedures');
