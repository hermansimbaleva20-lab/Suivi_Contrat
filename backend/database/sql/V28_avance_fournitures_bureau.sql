-- ============================================================================
-- PRISE / PREDIRE V28 — AVANCE SUR DEMANDE DES FOURNITURES DE BUREAU — MySQL
--
-- Équivalent SQL de la migration 2026_11_04_000004_office_supplies_advance : l'avance d'un contrat de fournitures de bureau
-- est versée sur demande de l'entreprise, sans garantie de restitution ; les colonnes de cette garantie deviennent facultatives.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V27 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE `contract_advances`
  MODIFY COLUMN `guarantee_bank` VARCHAR(191) NULL,
  MODIFY COLUMN `guarantee_number` VARCHAR(120) NULL,
  MODIFY COLUMN `guarantee_amount` DECIMAL(15,2) NULL,
  MODIFY COLUMN `guarantee_end_date` DATE NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000004_office_supplies_advance', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_04_000004_office_supplies_advance');
