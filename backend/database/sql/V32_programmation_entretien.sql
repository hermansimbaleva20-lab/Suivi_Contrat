-- ============================================================================
-- PRISE / PREDIRE V32 — PROGRAMMATION DU PROCHAIN ENTRETIEN — MySQL
--
-- Équivalent SQL de la migration 2026_11_05_000001_fleet_maintenance_plan : compteur du dernier entretien et compteur du
-- prochain, sur l'engin et sur chaque entretien enregistré.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V31 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v32_run;
DELIMITER $$
CREATE PROCEDURE v32_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_assets' AND COLUMN_NAME = 'maintenance_last_meter') THEN
    ALTER TABLE `fleet_assets` ADD COLUMN `maintenance_last_meter` DECIMAL(12,1) NULL,
      ADD COLUMN `maintenance_next_meter` DECIMAL(12,1) NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_maintenances' AND COLUMN_NAME = 'next_meter') THEN
    ALTER TABLE `fleet_maintenances` ADD COLUMN `next_meter` DECIMAL(12,1) NULL;
  END IF;
END$$
DELIMITER ;
CALL v32_run();
DROP PROCEDURE IF EXISTS v32_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_05_000001_fleet_maintenance_plan', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_05_000001_fleet_maintenance_plan');
