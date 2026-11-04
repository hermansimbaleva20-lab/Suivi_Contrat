-- ============================================================================
-- PRISE / PREDIRE V10 — SOCLE ORGANISATIONNEL (TDR §5, phase 2) — MySQL
--
-- Équivalent SQL des migrations :
--   2026_10_28_000004_convert_tables_to_innodb
--   2026_10_28_000005_create_organizational_structure
--   2026_10_28_000006_seed_initial_pools
-- À utiliser à la place de « php artisan migrate » si vous passez par phpMyAdmin.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT.
-- Prérequis : V9 appliquée (V9_mysql_update.sql ou php artisan migrate).
-- ============================================================================
SET NAMES utf8mb4;

-- 1. Conversion en InnoDB (transactions et clés étrangères effectives). Données conservées.
DROP PROCEDURE IF EXISTS v10_innodb;
DROP PROCEDURE IF EXISTS v10_add_column;
DELIMITER $$
CREATE PROCEDURE v10_innodb()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE t VARCHAR(64);
  DECLARE cur CURSOR FOR SELECT TABLE_NAME FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'MyISAM';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  boucle: LOOP
    FETCH cur INTO t;
    IF done THEN LEAVE boucle; END IF;
    SET @s = CONCAT('ALTER TABLE `', t, '` ENGINE = InnoDB');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END LOOP;
  CLOSE cur;
END$$
CREATE PROCEDURE v10_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;
CALL v10_innodb();

-- 2. Référentiel : coordination nationale / pools, postes
CREATE TABLE IF NOT EXISTS `organizational_units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(20) NOT NULL,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `province` VARCHAR(150) NULL,
  `description` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `org_units_project_code_unique` (`project_id`, `code`),
  KEY `org_units_project_type_idx` (`project_id`, `type`, `is_active`),
  CONSTRAINT `organizational_units_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `positions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `positions_project_name_unique` (`project_id`, `name`),
  CONSTRAINT `positions_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3. Affectation des comptes et audit avant/après
CALL v10_add_column('users', 'organizational_unit_id', 'BIGINT UNSIGNED NULL');
CALL v10_add_column('users', 'position_id', 'BIGINT UNSIGNED NULL');
CALL v10_add_column('audit_logs', 'organizational_unit_id', 'BIGINT UNSIGNED NULL');
CALL v10_add_column('audit_logs', 'old_values', 'LONGTEXT NULL');
CALL v10_add_column('audit_logs', 'new_values', 'LONGTEXT NULL');

-- 4. Une coordination nationale par projet (modifiable dans Administration > Organisation)
INSERT INTO `organizational_units` (`project_id`, `type`, `code`, `name`, `is_active`, `created_at`, `updated_at`)
SELECT p.`id`, 'COORDINATION', 'CN', 'Coordination nationale', 1, NOW(), NOW() FROM `projects` p
 WHERE NOT EXISTS (SELECT 1 FROM `organizational_units` u WHERE u.`project_id` = p.`id` AND u.`type` = 'COORDINATION');

-- 4 bis. Pools initiaux (décision D1 du 28/10/2026), modifiables ensuite dans Administration > Organisation
INSERT INTO `organizational_units` (`project_id`, `type`, `code`, `name`, `province`, `is_active`, `created_at`, `updated_at`)
SELECT p.`id`, 'POOL', v.`code`, v.`name`, v.`province`, 1, NOW(), NOW()
  FROM (SELECT 'PRISE' AS `project`, 'MBUJI-MAYI' AS `code`, 'Antenne MBUJI-MAYI' AS `name`, 'Kasaï-Oriental' AS `province`
        UNION ALL SELECT 'PRISE', 'KANANGA', 'Antenne KANANGA', 'Kasaï-Central'
        UNION ALL SELECT 'PREDIRE', 'GBADOLITE', 'Antenne GBADOLITE', 'Nord-Ubangi') v
  JOIN `projects` p ON p.`code` = v.`project`
 WHERE NOT EXISTS (SELECT 1 FROM `organizational_units` u
                    WHERE u.`project_id` = p.`id` AND (u.`code` = v.`code` OR LOWER(u.`name`) = LOWER(v.`name`)));

DROP PROCEDURE IF EXISTS v10_innodb;
DROP PROCEDURE IF EXISTS v10_add_column;

-- 5. Enregistrement des migrations (évite qu'artisan migrate les rejoue)
-- MAX() est calculé dans une table dérivée : un agrégat seul renverrait une ligne même quand NOT EXISTS est faux.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_28_000004_convert_tables_to_innodb', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_28_000004_convert_tables_to_innodb');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_28_000005_create_organizational_structure', b.`n` FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_28_000005_create_organizational_structure');
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_28_000006_seed_initial_pools', b.`n` FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_28_000006_seed_initial_pools');
