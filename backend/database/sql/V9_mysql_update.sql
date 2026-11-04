-- ============================================================================
-- PRISE / PREDIRE (React + Laravel) V9 — MISE À JOUR MySQL / MariaDB
--
-- Équivalent SQL de la migration  backend/database/migrations/2026_10_20_000004_align_with_csharp_features.php
-- À utiliser si vous préférez exécuter le SQL dans phpMyAdmin au lieu de « php artisan migrate ».
-- (Les deux méthodes sont équivalentes et peuvent être combinées : la migration PHP est idempotente.)
--
-- Prérequis : la base contient déjà les tables créées par les migrations V8 (php artisan migrate de la V8).
-- Ce script est IDEMPOTENT et NON DESTRUCTIF : il n'efface aucune donnée.
-- phpMyAdmin : sélectionnez la base (ex. prise_db) > onglet « Importer » > ce fichier.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v9_add_column;
DROP PROCEDURE IF EXISTS v9_add_index;
DROP PROCEDURE IF EXISTS v9_drop_index;
DELIMITER $$
CREATE PROCEDURE v9_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t)
     AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
CREATE PROCEDURE v9_add_index(IN t VARCHAR(64), IN i VARCHAR(64), IN cols VARCHAR(300), IN uq TINYINT)
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t)
     AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = i) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD ', IF(uq = 1, 'UNIQUE ', ''), 'INDEX `', i, '` (', cols, ')');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
CREATE PROCEDURE v9_drop_index(IN t VARCHAR(64), IN i VARCHAR(64))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = i) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` DROP INDEX `', i, '`');
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------- contracts
CALL v9_add_column('contracts', 'completed', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL v9_add_column('contracts', 'work_type_text', 'TEXT NULL');
ALTER TABLE `contracts` MODIFY `title` VARCHAR(255) NULL;                 -- l'intitulé devient facultatif
CALL v9_drop_index('contracts', 'contracts_reference_unique');            -- unicité globale supprimée
CALL v9_add_index('contracts', 'contracts_project_reference_unique', '`project_id`,`reference`', 1);  -- unicité par projet

-- ---------------------------------------------------------------- progress_entries : saisie possible sans site
ALTER TABLE `progress_entries` MODIFY `contract_site_id` BIGINT UNSIGNED NULL;
CALL v9_add_column('progress_entries', 'realization_physical', 'TEXT NULL');

-- ---------------------------------------------------------------- avenants
CALL v9_add_column('contract_amendments', 'added_months', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL v9_add_column('contract_amendments', 'previous_end_date', 'DATE NULL');
CALL v9_add_column('contract_amendments', 'created_by', 'BIGINT UNSIGNED NULL');
UPDATE `contract_amendments` SET `added_months` = GREATEST(1, ROUND(`duration_days` / 30)) WHERE `added_months` = 0 AND `duration_days` > 0;

-- ---------------------------------------------------------------- garanties : auteur du mouvement
CALL v9_add_column('guarantee_extensions', 'created_by', 'BIGINT UNSIGNED NULL');

-- ---------------------------------------------------------------- audit par projet
CALL v9_add_column('audit_logs', 'project_id', 'BIGINT UNSIGNED NULL');
CALL v9_add_index('audit_logs', 'audit_logs_project_id_index', '`project_id`', 0);
UPDATE `audit_logs` a JOIN `users` u ON u.id = a.user_id SET a.project_id = u.project_id WHERE a.project_id IS NULL;

-- ---------------------------------------------------------------- réinitialisation du mot de passe par jeton
CALL v9_add_column('password_reset_requests', 'user_id', 'BIGINT UNSIGNED NULL');
CALL v9_add_column('password_reset_requests', 'status', "VARCHAR(200) NOT NULL DEFAULT 'Lien envoyé'");
CALL v9_add_column('password_reset_requests', 'processed_at', 'TIMESTAMP NULL');

CREATE TABLE IF NOT EXISTS `user_reset_tokens` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- référentiels
CREATE TABLE IF NOT EXISTS `work_types` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `work_types` (`project_id`,`category`,`name`,`is_custom`,`created_at`,`updated_at`)
SELECT p.id, 'TRAVAUX', n.name, n.is_custom, NOW(), NOW()
  FROM `projects` p
  JOIN (SELECT 'Système d’AEP' AS name, 0 AS is_custom UNION ALL SELECT 'École primaire',1 UNION ALL SELECT 'Centre de santé',1
        UNION ALL SELECT 'Latrine publique',1 UNION ALL SELECT 'Espace à vivre',1) n
 WHERE NOT EXISTS (SELECT 1 FROM `work_types` w WHERE w.project_id = p.id);

CREATE TABLE IF NOT EXISTS `provinces` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provinces_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `provinces` (`name`,`created_at`,`updated_at`) VALUES
 ('Kinshasa',NOW(),NOW()),('Kongo Central',NOW(),NOW()),('Kwango',NOW(),NOW()),('Kwilu',NOW(),NOW()),('Mai-Ndombe',NOW(),NOW()),('Équateur',NOW(),NOW()),
 ('Mongala',NOW(),NOW()),('Nord-Ubangi',NOW(),NOW()),('Sud-Ubangi',NOW(),NOW()),('Tshuapa',NOW(),NOW()),('Tshopo',NOW(),NOW()),('Bas-Uele',NOW(),NOW()),
 ('Haut-Uele',NOW(),NOW()),('Ituri',NOW(),NOW()),('Nord-Kivu',NOW(),NOW()),('Sud-Kivu',NOW(),NOW()),('Maniema',NOW(),NOW()),('Tanganyika',NOW(),NOW()),
 ('Haut-Lomami',NOW(),NOW()),('Lualaba',NOW(),NOW()),('Haut-Katanga',NOW(),NOW()),('Kasaï',NOW(),NOW()),('Kasaï Central',NOW(),NOW()),('Lomami',NOW(),NOW()),
 ('Sankuru',NOW(),NOW()),('Kasaï Oriental',NOW(),NOW());

-- ---------------------------------------------------------------- SMTP : une ligne par projet
INSERT INTO `smtp_settings` (`project_id`,`host`,`port`,`sender_email`,`use_ssl`,`created_at`,`updated_at`)
SELECT p.id, 'smtp.gmail.com', 587, '', 0, NOW(), NOW() FROM `projects` p
 WHERE NOT EXISTS (SELECT 1 FROM `smtp_settings` s WHERE s.project_id = p.id);

-- ---------------------------------------------------------------- super-administrateur
UPDATE `users` SET `is_super_admin` = 1, `is_active` = 1, `is_pending` = 0 WHERE `email` = 'hermansimbaleva20@gmail.com';

-- ---------------------------------------------------------------- Laravel : marquer la migration comme exécutée
INSERT INTO `migrations` (`migration`, `batch`)
-- (MAX() est calculé dans une table dérivée : un agrégat seul renverrait une ligne même quand NOT EXISTS est faux.)
SELECT '2026_10_20_000004_align_with_csharp_features', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_20_000004_align_with_csharp_features');

DROP PROCEDURE IF EXISTS v9_add_column;
DROP PROCEDURE IF EXISTS v9_add_index;
DROP PROCEDURE IF EXISTS v9_drop_index;
SELECT 'PRISE V9 : mise à jour MySQL terminée.' AS Resultat;
