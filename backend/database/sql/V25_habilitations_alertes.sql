-- ============================================================================
-- PRISE / PREDIRE V25 — HABILITATIONS D'ALERTES SCINDÉES — MySQL
--
-- Équivalent SQL de la migration 2026_11_04_000001_split_alert_permissions : « Voir alertes » devient trois habilitations
-- (contrats, bonne exécution, logistique). La reprise des droits n'a lieu qu'à l'ajout des colonnes : rejouer le script ne
-- défait pas ce que le super-admin a réglé ensuite dans Utilisateurs. La colonne can_view_alerts reste mais ne sert plus.
-- IDEMPOTENT. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V24 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v25_run;
DELIMITER $$
CREATE PROCEDURE v25_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_view_alerts_contracts') THEN
    ALTER TABLE `users` ADD COLUMN `can_view_alerts_contracts` TINYINT(1) NOT NULL DEFAULT 0,
      ADD COLUMN `can_view_alerts_guarantees` TINYINT(1) NOT NULL DEFAULT 0,
      ADD COLUMN `can_view_alerts_logistics` TINYINT(1) NOT NULL DEFAULT 0;
    UPDATE `users` SET `can_view_alerts_contracts` = `can_view_alerts`, `can_view_alerts_guarantees` = `can_view_alerts`;
    UPDATE `users` SET `can_view_alerts_logistics` = 1 WHERE `can_manage_stock` = 1 OR `can_manage_fleet` = 1 OR `can_authorize_fleet` = 1;
  END IF;
END$$
DELIMITER ;
CALL v25_run();
DROP PROCEDURE IF EXISTS v25_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000001_split_alert_permissions', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_04_000001_split_alert_permissions');
