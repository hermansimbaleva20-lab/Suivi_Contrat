-- ============================================================================
-- PRISE / PREDIRE V18 — DATE DE L'OPÉRATION DE DÉCISION DES DÉCOMPTES — MySQL
--
-- Équivalent SQL de la migration 2026_10_29_000004_decompte_decision_date : le comptable saisit la date de l'opération
-- de rejet (avec le motif) ou de validation. Les décisions déjà prises reçoivent la date de leur enregistrement.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V17 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v18_add_column;
DELIMITER $$
CREATE PROCEDURE v18_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

CALL v18_add_column('contract_decomptes', 'decision_date', 'DATE NULL AFTER `decided_at`');
UPDATE `contract_decomptes` SET `decision_date` = DATE(`decided_at`) WHERE `decision_date` IS NULL AND `decided_at` IS NOT NULL;

DROP PROCEDURE IF EXISTS v18_add_column;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_29_000004_decompte_decision_date', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_29_000004_decompte_decision_date');
