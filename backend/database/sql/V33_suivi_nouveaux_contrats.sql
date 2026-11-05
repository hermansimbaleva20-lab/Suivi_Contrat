-- ============================================================================
-- PRISE / PREDIRE V33 — COMPTES NOTIFIÉS DES NOUVEAUX CONTRATS — MySQL
--
-- Équivalent SQL de la migration 2026_11_05_000002_new_contract_followers : le chef de cellule infrastructures et le personnel
-- de sa cellule, choisis par le super-administrateur, sont notifiés de chaque nouveau contrat pour en commencer le suivi.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V32 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v33_run;
DELIMITER $$
CREATE PROCEDURE v33_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'can_follow_new_contracts') THEN
    ALTER TABLE `users` ADD COLUMN `can_follow_new_contracts` TINYINT(1) NOT NULL DEFAULT 0;
  END IF;
END$$
DELIMITER ;
CALL v33_run();
DROP PROCEDURE IF EXISTS v33_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_05_000002_new_contract_followers', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_05_000002_new_contract_followers');
