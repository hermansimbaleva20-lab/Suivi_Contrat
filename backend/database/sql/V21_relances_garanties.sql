-- ============================================================================
-- PRISE / PREDIRE V21 — RELANCES DES GARANTIES DE BONNE EXÉCUTION — MySQL
--
-- Équivalent SQL de la migration 2026_10_30_000002_guarantee_alert_sequences : relances adressées à l'entreprise
-- selon la part consommée de la garantie (App\Support\GuaranteeAlerts).
--  - performance_guarantees : interruption par un administrateur (date, auteur, motif) et fréquence propre ;
--  - alert_rules.levels : paliers de la séquence (JSON) pour la catégorie GARANTIE.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V20 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v21_run;
DELIMITER $$
CREATE PROCEDURE v21_run()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'performance_guarantees' AND COLUMN_NAME = 'alerts_paused_at') THEN
    ALTER TABLE `performance_guarantees` ADD COLUMN `alerts_paused_at` DATETIME NULL,
      ADD COLUMN `alerts_paused_by` BIGINT UNSIGNED NULL,
      ADD COLUMN `alerts_pause_reason` VARCHAR(500) NULL,
      ADD COLUMN `alerts_per_week` TINYINT UNSIGNED NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_rules' AND COLUMN_NAME = 'levels') THEN
    ALTER TABLE `alert_rules` ADD COLUMN `levels` TEXT NULL;
  END IF;
END$$
DELIMITER ;
CALL v21_run();
DROP PROCEDURE IF EXISTS v21_run;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_30_000002_guarantee_alert_sequences', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_30_000002_guarantee_alert_sequences');
