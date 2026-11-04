-- ============================================================================
-- PRISE / PREDIRE V26 — RENOUVELLEMENT DES CONTRATS DE BIENS — MySQL
--
-- Équivalent SQL de la migration 2026_11_04_000002_contract_renewals : le même contrat reçoit une nouvelle période, un montant
-- ajouté et un numéro à suffixe (144 → 144/R1). Le montant ajouté reste hors de l'assiette de l'avance de démarrage.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V25 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v26_run;
DELIMITER $$
CREATE PROCEDURE v26_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts' AND COLUMN_NAME = 'renewal_no') THEN
    ALTER TABLE `contracts` ADD COLUMN `renewal_no` INT UNSIGNED NOT NULL DEFAULT 0,
      ADD COLUMN `renewed_amount` DECIMAL(15,2) NOT NULL DEFAULT 0;
  END IF;
END$$
DELIMITER ;
CALL v26_run();
DROP PROCEDURE IF EXISTS v26_run;

CREATE TABLE IF NOT EXISTS `contract_renewals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `renewal_no` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `previous_reference` VARCHAR(255) NOT NULL,
  `new_reference` VARCHAR(255) NOT NULL,
  `added_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `previous_amount` DECIMAL(15,2) NOT NULL,
  `previous_end_date` DATE NULL,
  `period_start` DATE NOT NULL,
  `new_end_date` DATE NOT NULL,
  `months` INT UNSIGNED NOT NULL,
  `last_line_no` INT UNSIGNED NOT NULL DEFAULT 0,
  `reason` TEXT NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `contract_renewals_no_unique` (`contract_id`, `renewal_no`),
  CONSTRAINT `contract_renewals_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000002_contract_renewals', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_04_000002_contract_renewals');
