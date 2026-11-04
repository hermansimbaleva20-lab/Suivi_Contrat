-- ============================================================================
-- PRISE / PREDIRE V13 — LOGISTIQUE : postes par unité, livraisons, colisage, N° d'opération — MySQL
--
-- Équivalent SQL de la migration 2026_10_28_000009_logistics_refinements.
-- À utiliser à la place de « php artisan migrate » si vous passez par phpMyAdmin.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT.
-- Prérequis : V10, V11 et V12 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v13_add_column;
DROP PROCEDURE IF EXISTS v13_run_if;
DELIMITER $$
CREATE PROCEDURE v13_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
-- Exécute une instruction si l'index (ou la contrainte) indiqué existe (present = 1) ou n'existe pas (present = 0).
CREATE PROCEDURE v13_run_if(IN t VARCHAR(64), IN idx VARCHAR(64), IN present INT, IN stmt VARCHAR(1000))
BEGIN
  DECLARE found INT;
  SELECT COUNT(*) INTO found FROM (
    SELECT INDEX_NAME AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = idx
    UNION ALL
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND CONSTRAINT_NAME = idx
  ) x;
  IF (found > 0) = (present = 1) THEN
    SET @s = stmt;
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- 1. Postes rattachés à une unité (un poste sans unité reste proposé à toutes les unités)
CALL v13_add_column('positions', 'organizational_unit_id', 'BIGINT UNSIGNED NULL AFTER `project_id`');
CALL v13_run_if('positions', 'positions_organizational_unit_id_foreign', 0,
  'ALTER TABLE `positions` ADD CONSTRAINT `positions_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL');
CALL v13_run_if('positions', 'positions_project_unit_name_unique', 0,
  'ALTER TABLE `positions` ADD UNIQUE KEY `positions_project_unit_name_unique` (`project_id`, `organizational_unit_id`, `name`)');
CALL v13_run_if('positions', 'positions_project_name_unique', 1, 'ALTER TABLE `positions` DROP INDEX `positions_project_name_unique`');

-- 2. Stock : colisage, N° d'opération, livraison (fournisseur, bon de livraison)
CALL v13_add_column('stock_articles', 'pack_size', 'DECIMAL(10,2) NULL AFTER `unit`');
CALL v13_add_column('stock_articles', 'pack_unit', 'VARCHAR(40) NULL AFTER `pack_size`');
CALL v13_add_column('stock_movements', 'operation_no', 'INT UNSIGNED NULL AFTER `stock_article_id`');
CALL v13_add_column('stock_movements', 'company_id', 'BIGINT UNSIGNED NULL AFTER `origin_destination`');
CALL v13_add_column('stock_movements', 'reference', 'VARCHAR(100) NULL AFTER `company_id`');
CALL v13_run_if('stock_movements', 'stock_movements_company_id_foreign', 0,
  'ALTER TABLE `stock_movements` ADD CONSTRAINT `stock_movements_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL');

-- N° d'opération des lignes existantes : ordre chronologique de chaque fiche (article × unité)
UPDATE `stock_movements` m
  JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY organizational_unit_id, stock_article_id ORDER BY movement_date, id) AS rn
          FROM `stock_movements`) x ON x.id = m.id
   SET m.operation_no = x.rn
 WHERE m.operation_no IS NULL;

-- 3. Super-administrateur rattaché à la coordination nationale de son projet
UPDATE `users` u
   SET u.organizational_unit_id = (SELECT MIN(o.id) FROM `organizational_units` o WHERE o.project_id = u.project_id AND o.type = 'COORDINATION')
 WHERE u.is_super_admin = 1 AND u.organizational_unit_id IS NULL;

DROP PROCEDURE IF EXISTS v13_add_column;
DROP PROCEDURE IF EXISTS v13_run_if;

-- Enregistrement de la migration (MAX() dans une table dérivée, pour ne l'enregistrer qu'une fois)
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_28_000009_logistics_refinements', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_28_000009_logistics_refinements');
