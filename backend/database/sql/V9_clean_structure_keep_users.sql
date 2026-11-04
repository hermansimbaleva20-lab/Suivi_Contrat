-- ============================================================================
-- PRISE / PREDIRE V9 — STRUCTURE PROPRE : toutes les tables métier VIDES, utilisateurs conservés
--
-- ATTENTION : ce script SUPPRIME définitivement les données de : contrats, sites, avancements, paiements,
-- garanties, avenants, documents, entreprises, journal d'audit, demandes de réinitialisation.
-- FAITES UNE SAUVEGARDE DE LA BASE AVANT (phpMyAdmin > Exporter).
--
-- Conservés tels quels : users, personal_access_tokens, projects, project_phases, smtp_settings, provinces, migrations.
-- Prérequis : « php artisan migrate » a déjà été exécuté (la table users doit contenir les colonnes V8/V9).
-- Utilisation : phpMyAdmin > sélectionner la base > « Importer » > ce fichier.
-- Équivalent en ligne de commande : php artisan prise:reset-structure
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Modules ajoutés depuis (stocks, charroi, décomptes, rapports, notifications) : vidés aussi ; relancez ensuite V10 à V17
-- pour les recréer.
DROP TABLE IF EXISTS `user_notifications`, `report_snapshots`, `alert_dispatches`, `contract_decomptes`;
DROP TABLE IF EXISTS `fleet_logs`, `fleet_maintenances`, `fleet_insurances`, `fleet_assets`, `stock_movements`, `stock_articles`;
DROP TABLE IF EXISTS `contract_advances`, `contract_renewals`, `guarantee_extensions`, `performance_guarantees`, `contract_amendments`, `contract_documents`, `contract_payments`,
  `progress_entries`, `contract_sites`, `contracts`, `companies`, `alerts`, `work_types`, `password_reset_requests`, `user_reset_tokens`, `audit_logs`;

-- ---------------------------------------------------------------- entreprises
CREATE TABLE `companies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `address` TEXT NULL,
  `phone` VARCHAR(255) NULL,
  `email` VARCHAR(255) NULL,
  `observation` TEXT NULL,
  `guarantee_bank` VARCHAR(255) NULL,
  `guarantee_account_number` VARCHAR(255) NULL,
  `guarantee_observation` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `companies_project_id_name_unique` (`project_id`,`name`),
  CONSTRAINT `companies_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- contrats
CREATE TABLE `contracts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `phase_id` BIGINT UNSIGNED NOT NULL,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `reference` VARCHAR(255) NOT NULL,
  `title` VARCHAR(255) NULL,
  `province` VARCHAR(255) NULL,
  `category` VARCHAR(80) NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `signature_date` DATE NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `duration_months` INT UNSIGNED NULL,
  `initial_financing_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `activities` TEXT NULL,
  `work_type_text` TEXT NULL,
  `completed` TINYINT(1) NOT NULL DEFAULT 0,
  `termination_date` DATE NULL,
  `termination_reason` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contracts_project_reference_unique` (`project_id`,`reference`),
  KEY `contracts_project_id_phase_id_index` (`project_id`,`phase_id`),
  CONSTRAINT `contracts_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contracts_phase_id_foreign` FOREIGN KEY (`phase_id`) REFERENCES `project_phases` (`id`),
  CONSTRAINT `contracts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `contract_sites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `province` VARCHAR(255) NOT NULL,
  `work_type` VARCHAR(255) NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `quantity` DECIMAL(15,2) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `contract_sites_contract_id_foreign` (`contract_id`),
  CONSTRAINT `contract_sites_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `progress_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `contract_site_id` BIGINT UNSIGNED NULL,
  `situation_date` DATE NOT NULL,
  `progress` DECIMAL(6,2) NOT NULL,
  `realization_physical` TEXT NULL,
  `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `observation` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `progress_entries_contract_id_foreign` (`contract_id`),
  KEY `progress_entries_contract_site_id_situation_date_index` (`contract_site_id`,`situation_date`),
  CONSTRAINT `progress_entries_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `progress_entries_contract_site_id_foreign` FOREIGN KEY (`contract_site_id`) REFERENCES `contract_sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `contract_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `reference` VARCHAR(255) NULL,
  `observation` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `contract_payments_contract_id_foreign` (`contract_id`),
  CONSTRAINT `contract_payments_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `contract_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `storage_path` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(150) NULL,
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `contract_documents_contract_id_foreign` (`contract_id`),
  CONSTRAINT `contract_documents_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- garanties et avenants
CREATE TABLE `performance_guarantees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `bank_name` VARCHAR(255) NOT NULL,
  `account_number` VARCHAR(255) NULL,
  `rate` DECIMAL(5,2) NOT NULL DEFAULT 5,
  `amount` DECIMAL(15,2) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `observation` TEXT NULL,
  `alerts_paused_at` DATETIME NULL,
  `alerts_paused_by` BIGINT UNSIGNED NULL,
  `alerts_pause_reason` VARCHAR(500) NULL,
  `alerts_per_week` TINYINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `performance_guarantees_contract_id_unique` (`contract_id`),
  KEY `performance_guarantees_project_id_foreign` (`project_id`),
  KEY `performance_guarantees_company_id_foreign` (`company_id`),
  CONSTRAINT `performance_guarantees_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_guarantees_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `performance_guarantees_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `guarantee_extensions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `performance_guarantee_id` BIGINT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `type` ENUM('RENOUVELLEMENT','PROLONGATION') NOT NULL DEFAULT 'PROLONGATION',
  `reason` TEXT NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `guarantee_extensions_performance_guarantee_id_foreign` (`performance_guarantee_id`),
  CONSTRAINT `guarantee_extensions_performance_guarantee_id_foreign` FOREIGN KEY (`performance_guarantee_id`) REFERENCES `performance_guarantees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `contract_amendments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `duration_days` INT NOT NULL DEFAULT 0,
  `added_months` INT UNSIGNED NOT NULL DEFAULT 0,
  `previous_end_date` DATE NULL,
  `new_end_date` DATE NULL,
  `reason` TEXT NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `contract_amendments_contract_id_foreign` (`contract_id`),
  CONSTRAINT `contract_amendments_contract_id_foreign` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- référentiel, sécurité, audit
CREATE TABLE `work_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `category` VARCHAR(80) NOT NULL,
  `name` VARCHAR(250) NOT NULL,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_types_unique` (`project_id`,`category`,`name`),
  KEY `work_types_project_id_index` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `password_reset_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `email` VARCHAR(255) NOT NULL,
  `requested_at` TIMESTAMP NULL,
  `is_read_by_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(200) NOT NULL DEFAULT 'Lien envoyé',
  `processed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `password_reset_requests_project_id_index` (`project_id`),
  KEY `password_reset_requests_project_id_is_read_by_admin_index` (`project_id`,`is_read_by_admin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `user_reset_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(100) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `user_reset_tokens_project_id_index` (`project_id`),
  KEY `user_reset_tokens_user_id_index` (`user_id`),
  KEY `user_reset_tokens_token_hash_index` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(255) NOT NULL,
  `entity_name` VARCHAR(255) NOT NULL,
  `entity_id` VARCHAR(255) NULL,
  `details` TEXT NULL,
  `machine_name` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_project_id_index` (`project_id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  KEY `audit_logs_action_index` (`action`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- configuration indispensable (aucune donnée métier)
INSERT INTO `projects` (`code`,`name`,`created_at`,`updated_at`)
SELECT 'PRISE','Projet de Renforcement des Infrastructures Socio-Économiques dans la Zone Centre de la RDC',NOW(),NOW() FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `projects` WHERE `code` = 'PRISE');
INSERT INTO `projects` (`code`,`name`,`created_at`,`updated_at`)
SELECT 'PREDIRE','Programme Régional d’Appui au Développement des Infrastructures et de Valorisation des Ressources en Eau Transfrontalières entre la RCA et la RDC',NOW(),NOW() FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `projects` WHERE `code` = 'PREDIRE');

-- Une phase courante par projet
INSERT INTO `project_phases` (`project_id`,`number`,`name`,`is_current`,`is_archived`,`created_at`,`updated_at`)
SELECT p.`id`, IF(p.`code` = 'PRISE', 2, 1), IF(p.`code` = 'PRISE', 'Phase 2', 'Phase 1'), 1, 0, NOW(), NOW() FROM `projects` p
 WHERE NOT EXISTS (SELECT 1 FROM `project_phases` f WHERE f.`project_id` = p.`id`);
UPDATE `project_phases` f
  JOIN (SELECT `project_id`, MIN(`id`) AS `first_id` FROM `project_phases` GROUP BY `project_id`) m ON m.`project_id` = f.`project_id`
   SET f.`is_current` = 1, f.`is_archived` = 0
 WHERE f.`id` = m.`first_id`
   AND NOT EXISTS (SELECT 1 FROM (SELECT `project_id` FROM `project_phases` WHERE `is_current` = 1) c WHERE c.`project_id` = f.`project_id`);

-- Catalogue d'ouvrages de départ
INSERT IGNORE INTO `work_types` (`project_id`,`category`,`name`,`is_custom`,`created_at`,`updated_at`)
SELECT p.`id`, 'TRAVAUX', n.`name`, n.`is_custom`, NOW(), NOW() FROM `projects` p
  JOIN (SELECT 'Système d’AEP' AS `name`, 0 AS `is_custom` UNION ALL SELECT 'École primaire',1 UNION ALL SELECT 'Centre de santé',1
        UNION ALL SELECT 'Latrine publique',1 UNION ALL SELECT 'Espace à vivre',1) n;

-- Provinces (si la table existe et est vide)
INSERT IGNORE INTO `provinces` (`name`,`created_at`,`updated_at`) VALUES
 ('Kinshasa',NOW(),NOW()),('Kongo Central',NOW(),NOW()),('Kwango',NOW(),NOW()),('Kwilu',NOW(),NOW()),('Mai-Ndombe',NOW(),NOW()),('Équateur',NOW(),NOW()),
 ('Mongala',NOW(),NOW()),('Nord-Ubangi',NOW(),NOW()),('Sud-Ubangi',NOW(),NOW()),('Tshuapa',NOW(),NOW()),('Tshopo',NOW(),NOW()),('Bas-Uele',NOW(),NOW()),
 ('Haut-Uele',NOW(),NOW()),('Ituri',NOW(),NOW()),('Nord-Kivu',NOW(),NOW()),('Sud-Kivu',NOW(),NOW()),('Maniema',NOW(),NOW()),('Tanganyika',NOW(),NOW()),
 ('Haut-Lomami',NOW(),NOW()),('Lualaba',NOW(),NOW()),('Haut-Katanga',NOW(),NOW()),('Kasaï',NOW(),NOW()),('Kasaï Central',NOW(),NOW()),('Lomami',NOW(),NOW()),
 ('Sankuru',NOW(),NOW()),('Kasaï Oriental',NOW(),NOW());

-- Une ligne de paramètres SMTP par projet
INSERT INTO `smtp_settings` (`project_id`,`host`,`port`,`sender_email`,`use_ssl`,`created_at`,`updated_at`)
SELECT p.`id`, 'smtp.gmail.com', 587, '', 0, NOW(), NOW() FROM `projects` p
 WHERE NOT EXISTS (SELECT 1 FROM `smtp_settings` s WHERE s.`project_id` = p.`id`);

SELECT 'PRISE V9 : structure propre créée. Tables métier vides, utilisateurs conservés.' AS Resultat;
