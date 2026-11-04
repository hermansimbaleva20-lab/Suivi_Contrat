-- ============================================================================
-- PRISE / PREDIRE V16 — IA (paramètres) ET FIN DES DÉCOMPTES (ligne « RETENUE », lien avec les paiements) — MySQL
--
-- Équivalent SQL de la migration 2026_10_29_000002_ai_and_decompte_release, SAUF la création des paiements des
-- décomptes déjà validés : elle demande le calcul de la situation du contrat. Après ce script, lancez une fois
-- « php artisan migrate » ou, sans accès à artisan, faites enregistrer ces paiements par l'équipe technique.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V15 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v16_add_column;
DROP PROCEDURE IF EXISTS v16_run_if_missing;
DELIMITER $$
CREATE PROCEDURE v16_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
CREATE PROCEDURE v16_run_if_missing(IN t VARCHAR(64), IN cname VARCHAR(64), IN stmt VARCHAR(1000))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND CONSTRAINT_NAME = cname) THEN
    SET @s = stmt;
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

CALL v16_add_column('contract_decomptes', 'kind', 'VARCHAR(12) NOT NULL DEFAULT ''DECOMPTE'' AFTER `number`');
CALL v16_add_column('contract_payments', 'decompte_id', 'BIGINT UNSIGNED NULL');
CALL v16_run_if_missing('contract_payments', 'contract_payments_decompte_id_unique', 'ALTER TABLE `contract_payments` ADD UNIQUE KEY `contract_payments_decompte_id_unique` (`decompte_id`)');
CALL v16_run_if_missing('contract_payments', 'contract_payments_decompte_id_foreign',
  'ALTER TABLE `contract_payments` ADD CONSTRAINT `contract_payments_decompte_id_foreign` FOREIGN KEY (`decompte_id`) REFERENCES `contract_decomptes` (`id`) ON DELETE SET NULL');

CREATE TABLE IF NOT EXISTS `ai_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `provider` VARCHAR(20) NOT NULL DEFAULT 'ANTHROPIC',
  `model` VARCHAR(100) NOT NULL DEFAULT 'claude-opus-5-5',
  `endpoint` VARCHAR(255) NULL,
  `api_key` TEXT NULL,
  `feature_comments` TINYINT(1) NOT NULL DEFAULT 0,
  `feature_alerts` TINYINT(1) NOT NULL DEFAULT 0,
  `feature_extraction` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `ai_settings_project_id_unique` (`project_id`),
  CONSTRAINT `ai_settings_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS v16_add_column;
DROP PROCEDURE IF EXISTS v16_run_if_missing;
-- La migration n'est PAS marquée comme exécutée : « php artisan migrate » la rejouera sans risque (elle est relançable)
-- et créera les paiements des décomptes déjà validés.
