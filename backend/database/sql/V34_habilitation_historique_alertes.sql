-- ============================================================================
-- PRISE / PREDIRE V34 — CONSULTATION DES MODÈLES ET DE L'HISTORIQUE DES ALERTES — MySQL
--
-- Équivalent SQL de la migration 2026_11_05_000003_alert_history_permission : le super-administrateur, et les comptes à qui
-- il accorde cette habilitation, consultent les modèles et l'historique des alertes. Être administrateur ne suffit pas.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V33 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v34_run;
DELIMITER $$
CREATE PROCEDURE v34_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_view_alert_history') THEN
    ALTER TABLE `users` ADD COLUMN `can_view_alert_history` TINYINT(1) NOT NULL DEFAULT 0;
  END IF;
END$$
DELIMITER ;
CALL v34_run();
DROP PROCEDURE IF EXISTS v34_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_05_000003_alert_history_permission', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_05_000003_alert_history_permission');
