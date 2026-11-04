-- ============================================================================
-- PRISE / PREDIRE V14 — DÉCOMPTES (situation du contrat, validation par le comptable) — MySQL
--
-- Équivalent SQL de la migration 2026_10_28_000010_create_contract_decomptes.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V13 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `contract_decomptes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `line_no` INT UNSIGNED NOT NULL,
  `number` VARCHAR(60) NOT NULL,
  `operation_date` DATE NOT NULL,
  `gross_amount` DECIMAL(15,2) NOT NULL,
  `advance_rate` DECIMAL(6,4) NOT NULL DEFAULT 0,
  `guarantee_rate` DECIMAL(6,4) NOT NULL DEFAULT 0,
  `status` VARCHAR(12) NOT NULL DEFAULT 'EN_ATTENTE',
  `observation` VARCHAR(500) NULL,
  `submitted_by` BIGINT UNSIGNED NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` TIMESTAMP NULL,
  `decision_comment` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `decomptes_contract_line_unique` (`contract_id`, `line_no`),
  KEY `decomptes_project_status_idx` (`project_id`, `status`),
  CONSTRAINT `contract_decomptes_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contract_decomptes_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Habilitation du comptable : valider ou rejeter les décomptes
DROP PROCEDURE IF EXISTS v14_add_column;
DELIMITER $$
CREATE PROCEDURE v14_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;
CALL v14_add_column('users', 'can_validate_decomptes', 'TINYINT(1) NOT NULL DEFAULT 0');
DROP PROCEDURE IF EXISTS v14_add_column;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_28_000010_create_contract_decomptes', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_28_000010_create_contract_decomptes');
