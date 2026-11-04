-- ============================================================================
-- PRISE / PREDIRE V17 — POSTE COMME RÔLE, VUE NATIONALE DES POOLS, NOTIFICATIONS, VISA DU CARNET DE BORD — MySQL
--
-- Équivalent SQL de la migration 2026_10_29_000003_roles_notifications_fleet_visa :
--  - suppression de la colonne users.function (la « Fonction » de l'inscription) ;
--  - users.can_view_pools : consultation des pools au niveau national, désignée par le super-admin (lecture seule) ;
--  - table user_notifications (décompte à valider, saisie du carnet à autoriser, décision rendue) ;
--  - fleet_logs : personne désignée pour l'autorisation (HRAF ou comptable), objection motivée ; lubrifiant en texte,
--    « PLEIN » par défaut.
-- IDEMPOTENT. SAUVEGARDEZ LA BASE AVANT (la colonne users.function est supprimée). Prérequis : V9 à V16 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v17_add_column;
DROP PROCEDURE IF EXISTS v17_run;
DELIMITER $$
CREATE PROCEDURE v17_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
CREATE PROCEDURE v17_run()
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'function') THEN
    ALTER TABLE `users` DROP COLUMN `function`;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_logs' AND INDEX_NAME = 'fleet_logs_assignee_idx') THEN
    ALTER TABLE `fleet_logs` ADD INDEX `fleet_logs_assignee_idx` (`assigned_to`, `authorized_at`);
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_logs' AND COLUMN_NAME = 'lubricant' AND DATA_TYPE <> 'varchar') THEN
    ALTER TABLE `fleet_logs` MODIFY `lubricant` VARCHAR(50) NULL DEFAULT 'PLEIN';
    -- 2.00 → « 2 L » : les quantités saisies avant le changement restent lisibles.
    UPDATE `fleet_logs` SET `lubricant` = CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM `lubricant`)), ' L')
     WHERE `lubricant` REGEXP '^[0-9]+(\\.[0-9]+)?$';
  END IF;
END$$
DELIMITER ;

CALL v17_add_column('users', 'can_view_pools', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL v17_add_column('fleet_logs', 'assigned_to', 'BIGINT UNSIGNED NULL AFTER `created_by`');
CALL v17_add_column('fleet_logs', 'objected_by', 'BIGINT UNSIGNED NULL AFTER `authorized_at`');
CALL v17_add_column('fleet_logs', 'objected_at', 'TIMESTAMP NULL AFTER `objected_by`');
CALL v17_add_column('fleet_logs', 'objection_reason', 'VARCHAR(500) NULL AFTER `objected_at`');
CALL v17_run();

CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(40) NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `body` VARCHAR(500) NULL,
  `link` VARCHAR(190) NULL,
  `entity_name` VARCHAR(60) NULL,
  `entity_id` VARCHAR(40) NULL,
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  KEY `user_notifications_user_read_idx` (`user_id`, `read_at`),
  KEY `user_notifications_entity_idx` (`entity_name`, `entity_id`),
  CONSTRAINT `user_notifications_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS v17_add_column;
DROP PROCEDURE IF EXISTS v17_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_29_000003_roles_notifications_fleet_visa', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_29_000003_roles_notifications_fleet_visa');
