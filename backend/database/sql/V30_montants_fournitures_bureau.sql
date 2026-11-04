-- ============================================================================
-- PRISE / PREDIRE V30 — MONTANTS MINIMUM ET MAXIMUM DES FOURNITURES DE BUREAU — MySQL
--
-- Équivalent SQL de la migration 2026_11_04_000006_office_supplies_min_max : simple clause du contrat, sans contrôle des livraisons.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V29 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v30_run;
DELIMITER $$
CREATE PROCEDURE v30_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts' AND COLUMN_NAME = 'goods_min_amount') THEN
    ALTER TABLE `contracts` ADD COLUMN `goods_min_amount` DECIMAL(15,2) NULL,
      ADD COLUMN `goods_max_amount` DECIMAL(15,2) NULL;
  END IF;
END$$
DELIMITER ;
CALL v30_run();
DROP PROCEDURE IF EXISTS v30_run;

UPDATE `contracts` SET `goods_min_amount` = 500, `goods_max_amount` = 5500
 WHERE `goods_procedure` = 'FOURNITURES_BUREAU' AND `goods_max_amount` IS NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000006_office_supplies_min_max', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_04_000006_office_supplies_min_max');
