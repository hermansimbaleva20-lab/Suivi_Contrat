-- ============================================================================
-- PRISE / PREDIRE V12 — CHARROI AUTOMOBILE ET GROUPES ÉLECTROGÈNES (TDR §7, §8, phase 3) — MySQL
--
-- Équivalent SQL de la migration 2026_10_28_000008_create_fleet_module.
-- À utiliser à la place de « php artisan migrate » si vous passez par phpMyAdmin.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT.
-- Prérequis : V10 appliquée (V10_socle_organisationnel.sql ou php artisan migrate).
-- ============================================================================
SET NAMES utf8mb4;

-- Parc : véhicules et groupes, avec leurs repères (réservoir, consommation, entretien). Compteur en km ou en heures.
CREATE TABLE IF NOT EXISTS `fleet_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `organizational_unit_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(10) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `registration` VARCHAR(40) NULL,
  `tank_capacity` DECIMAL(8,2) NOT NULL DEFAULT 120,
  `consumption_rate` DECIMAL(8,3) NULL,
  `fuel_price` DECIMAL(8,3) NOT NULL DEFAULT 1.37,
  `fuel_alert_level` DECIMAL(8,2) NULL,
  `maintenance_interval` INT UNSIGNED NOT NULL,
  `maintenance_warning` INT UNSIGNED NOT NULL,
  `initial_meter` DECIMAL(12,1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `fleet_assets_project_name_unique` (`project_id`, `name`),
  CONSTRAINT `fleet_assets_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fleet_assets_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Carnet de bord : courses des véhicules, relevés des groupes (jamais modifiés : annulés avec motif)
CREATE TABLE IF NOT EXISTS `fleet_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `organizational_unit_id` BIGINT UNSIGNED NOT NULL,
  `fleet_asset_id` BIGINT UNSIGNED NOT NULL,
  `log_date` DATE NOT NULL,
  `requester` VARCHAR(150) NULL,
  `destination` VARCHAR(190) NULL,
  `purpose` VARCHAR(255) NULL,
  `departure_time` VARCHAR(5) NULL,
  `arrival_time` VARCHAR(5) NULL,
  `meter_start` DECIMAL(12,1) NOT NULL,
  `meter_end` DECIMAL(12,1) NOT NULL,
  `fuel_level_before` DECIMAL(8,2) NULL,
  `fuel_purchased` DECIMAL(8,2) NULL,
  `fuel_price` DECIMAL(8,3) NULL,
  `lubricant` DECIMAL(8,2) NULL,
  `observation` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `authorized_by` BIGINT UNSIGNED NULL,
  `authorized_at` TIMESTAMP NULL,
  `cancelled_at` TIMESTAMP NULL,
  `cancelled_by` BIGINT UNSIGNED NULL,
  `cancel_reason` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  KEY `fleet_logs_asset_date_idx` (`project_id`, `fleet_asset_id`, `log_date`),
  KEY `fleet_logs_unit_date_idx` (`project_id`, `organizational_unit_id`, `log_date`),
  CONSTRAINT `fleet_logs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fleet_logs_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`),
  CONSTRAINT `fleet_logs_fleet_asset_id_foreign` FOREIGN KEY (`fleet_asset_id`) REFERENCES `fleet_assets` (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fleet_maintenances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `organizational_unit_id` BIGINT UNSIGNED NOT NULL,
  `fleet_asset_id` BIGINT UNSIGNED NOT NULL,
  `maintenance_date` DATE NOT NULL,
  `meter_reading` DECIMAL(12,1) NOT NULL,
  `designation` VARCHAR(255) NOT NULL,
  `cost` DECIMAL(14,2) NULL,
  `provider` VARCHAR(190) NULL,
  `observation` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  KEY `fleet_maint_asset_date_idx` (`project_id`, `fleet_asset_id`, `maintenance_date`),
  CONSTRAINT `fleet_maintenances_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fleet_maintenances_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`),
  CONSTRAINT `fleet_maintenances_fleet_asset_id_foreign` FOREIGN KEY (`fleet_asset_id`) REFERENCES `fleet_assets` (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fleet_insurances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `organizational_unit_id` BIGINT UNSIGNED NOT NULL,
  `fleet_asset_id` BIGINT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `designation` VARCHAR(255) NOT NULL,
  `cost` DECIMAL(14,2) NULL,
  `observation` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  KEY `fleet_ins_asset_end_idx` (`project_id`, `fleet_asset_id`, `end_date`),
  CONSTRAINT `fleet_insurances_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fleet_insurances_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`),
  CONSTRAINT `fleet_insurances_fleet_asset_id_foreign` FOREIGN KEY (`fleet_asset_id`) REFERENCES `fleet_assets` (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Habilitations : consulter, gérer (logistique), autoriser (comptable / RAF)
DROP PROCEDURE IF EXISTS v12_add_column;
DELIMITER $$
CREATE PROCEDURE v12_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;
CALL v12_add_column('users', 'can_view_fleet', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL v12_add_column('users', 'can_manage_fleet', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL v12_add_column('users', 'can_authorize_fleet', 'TINYINT(1) NOT NULL DEFAULT 0');
DROP PROCEDURE IF EXISTS v12_add_column;

-- Enregistrement de la migration. MAX() dans une table dérivée : un agrégat seul renverrait une ligne même quand
-- NOT EXISTS est faux, et la migration serait enregistrée à chaque exécution.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_28_000008_create_fleet_module', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_28_000008_create_fleet_module');
