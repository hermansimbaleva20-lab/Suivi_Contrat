-- ============================================================================
-- PRISE / PREDIRE V22 — MODÈLES DES MESSAGES ET HISTORIQUE DES ENVOIS PAR CONTRAT — MySQL
--
-- Équivalent SQL de la migration 2026_10_30_000003_alert_templates_and_history :
--  - alert_templates : modèles modifiés des messages aux entreprises (vigilance, retard, satisfaction ; garantie ou
--    contrat) ; sans ligne, le modèle par défaut de l'application s'applique ;
--  - alert_dispatches : contrat, garantie, n° de garantie, nature et texte du message envoyé.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V21 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `alert_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `alert_templates_code_unique` (`project_id`, `code`),
  CONSTRAINT `alert_templates_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS v22_run;
DELIMITER $$
CREATE PROCEDURE v22_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_dispatches' AND COLUMN_NAME = 'contract_id') THEN
    ALTER TABLE `alert_dispatches` ADD COLUMN `contract_id` BIGINT UNSIGNED NULL,
      ADD COLUMN `performance_guarantee_id` BIGINT UNSIGNED NULL,
      ADD COLUMN `guarantee_number` VARCHAR(190) NULL,
      ADD COLUMN `message_type` VARCHAR(20) NULL,
      ADD COLUMN `body` TEXT NULL,
      ADD INDEX `alert_dispatches_contract_idx` (`project_id`, `contract_id`);
  END IF;
END$$
DELIMITER ;
CALL v22_run();
DROP PROCEDURE IF EXISTS v22_run;

UPDATE `alert_dispatches` d JOIN `performance_guarantees` g ON d.`alert_key` LIKE CONCAT('GARANTIE:', g.`id`, ':%')
   SET d.`performance_guarantee_id` = g.`id`, d.`contract_id` = g.`contract_id`, d.`guarantee_number` = g.`account_number`
 WHERE d.`performance_guarantee_id` IS NULL AND d.`category` = 'GARANTIE';

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_30_000003_alert_templates_and_history', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_30_000003_alert_templates_and_history');
