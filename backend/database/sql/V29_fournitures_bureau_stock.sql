-- ============================================================================
-- PRISE / PREDIRE V29 — FOURNITURES DE BUREAU : SUIVI PAR LE STOCK — MySQL
--
-- Équivalent SQL de la migration 2026_11_04_000005_office_supplies_stock_only : ces contrats n'ont plus d'avance de démarrage.
-- IDEMPOTENT. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V28 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

UPDATE `contracts` c
   SET c.`initial_financing_rate` = 0
 WHERE c.`goods_procedure` = 'FOURNITURES_BUREAU' AND c.`initial_financing_rate` > 0
   AND NOT EXISTS (SELECT 1 FROM `contract_advances` a WHERE a.`contract_id` = c.`id` AND a.`status` <> 'REJETEE');

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_04_000005_office_supplies_stock_only', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_04_000005_office_supplies_stock_only');
