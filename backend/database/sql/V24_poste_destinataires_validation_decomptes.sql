-- ============================================================================
-- PRISE / PREDIRE V24 — POSTE DES DESTINATAIRES ET VALIDATION DES DÉCOMPTES — MySQL
--
-- Équivalent SQL de la migration 2026_11_03_000001_recipient_position_and_decompte_validator :
--  - alert_recipients.position_id : poste occupé dans le projet par chaque destinataire ;
--  - la validation des décomptes passe du comptable au chef de cellule infrastructures. Ce transfert n'a lieu qu'une fois :
--    rejouer le script ne défait pas ce que le super-admin a réglé ensuite dans Utilisateurs.
-- IDEMPOTENT. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V23 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v24_run;
DELIMITER $$
CREATE PROCEDURE v24_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_recipients' AND COLUMN_NAME = 'position_id') THEN
    ALTER TABLE `alert_recipients` ADD COLUMN `position_id` BIGINT UNSIGNED NULL,
      ADD CONSTRAINT `alert_recipients_position_id_foreign` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM `migrations` WHERE `migration` = '2026_11_03_000001_recipient_position_and_decompte_validator') THEN
    UPDATE `users` u LEFT JOIN `positions` p ON p.`id` = u.`position_id` SET u.`can_validate_decomptes` = 0
     WHERE u.`can_validate_decomptes` = 1 AND UPPER(TRIM(COALESCE(p.`name`, u.`project_position`, ''))) = 'COMPTABLE';
    UPDATE `users` u JOIN `positions` p ON p.`id` = u.`position_id` SET u.`can_validate_decomptes` = 1
     WHERE UPPER(p.`name`) LIKE 'CHEF DE CELLULE INFRASTRUCTURE%';
  END IF;
END$$
DELIMITER ;
CALL v24_run();
DROP PROCEDURE IF EXISTS v24_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_03_000001_recipient_position_and_decompte_validator', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_03_000001_recipient_position_and_decompte_validator');
