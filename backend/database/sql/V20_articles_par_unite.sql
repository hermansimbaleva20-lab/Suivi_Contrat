-- ============================================================================
-- PRISE / PREDIRE V20 — ARTICLES DE STOCK PAR UNITÉ ET PAR CONTRAT — MySQL
--
-- Équivalent SQL de la migration 2026_10_30_000001_stock_articles_by_unit : chaque antenne ajoute ses propres articles
-- (organizational_unit_id), la coordination nationale tient le catalogue commun (NULL) ; un article peut venir d'un
-- contrat (contract_id). La désignation n'est plus unique dans tout le projet (contrôle par unité dans l'API).
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V19 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v20_run;
DELIMITER $$
CREATE PROCEDURE v20_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_articles' AND COLUMN_NAME = 'organizational_unit_id') THEN
    ALTER TABLE `stock_articles` ADD COLUMN `organizational_unit_id` BIGINT UNSIGNED NULL AFTER `project_id`,
      ADD COLUMN `contract_id` BIGINT UNSIGNED NULL AFTER `organizational_unit_id`,
      ADD CONSTRAINT `stock_articles_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`),
      ADD CONSTRAINT `stock_articles_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE SET NULL;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_articles' AND INDEX_NAME = 'stock_articles_project_designation_unique') THEN
    ALTER TABLE `stock_articles` ADD INDEX `stock_articles_project_designation_idx` (`project_id`, `designation`), DROP INDEX `stock_articles_project_designation_unique`;
  END IF;
END$$
DELIMITER ;
CALL v20_run();
DROP PROCEDURE IF EXISTS v20_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_30_000001_stock_articles_by_unit', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_30_000001_stock_articles_by_unit');
