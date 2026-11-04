-- ============================================================================
-- PRISE / PREDIRE V31 — STOCK ET FOURNITURES DE BUREAU PAR UNITÉ — MySQL
--
-- Équivalent SQL de la migration 2026_11_04_000007_office_supplies_by_unit : plus de catalogue commun, N° de fiche par unité,
-- et un contrat de fournitures de bureau appartient à une unité. L'existant revient à la coordination nationale.
-- IDEMPOTENT. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V30 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v31_run;
DELIMITER $$
CREATE PROCEDURE v31_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contracts' AND COLUMN_NAME = 'organizational_unit_id') THEN
    ALTER TABLE `contracts` ADD COLUMN `organizational_unit_id` BIGINT UNSIGNED NULL,
      ADD CONSTRAINT `contracts_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL;
  END IF;

  UPDATE `contracts` c
     SET c.`organizational_unit_id` = (SELECT MIN(u.`id`) FROM `organizational_units` u WHERE u.`project_id` = c.`project_id` AND u.`type` = 'COORDINATION')
   WHERE c.`goods_procedure` = 'FOURNITURES_BUREAU' AND c.`organizational_unit_id` IS NULL;

  UPDATE `stock_articles` a
     SET a.`organizational_unit_id` = (SELECT MIN(u.`id`) FROM `organizational_units` u WHERE u.`project_id` = a.`project_id` AND u.`type` = 'COORDINATION')
   WHERE a.`organizational_unit_id` IS NULL;

  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_articles' AND INDEX_NAME = 'stock_articles_unit_number_unique') THEN
    ALTER TABLE `stock_articles` ADD UNIQUE KEY `stock_articles_unit_number_unique` (`project_id`, `organizational_unit_id`, `number`);
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_articles' AND INDEX_NAME = 'stock_articles_project_number_unique') THEN
    ALTER TABLE `stock_articles` DROP INDEX `stock_articles_project_number_unique`;
  END IF;
END$$
DELIMITER ;
CALL v31_run();
DROP PROCEDURE IF EXISTS v31_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000007_office_supplies_by_unit', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_04_000007_office_supplies_by_unit');
