-- ============================================================================
-- PRISE / PREDIRE V23 — UNE SEULE LISTE POUR LE PERSONNEL DU PROJET — MySQL
--
-- Équivalent SQL de la migration 2026_11_02_000001_project_recipients_single_list : les adresses des 4 champs
-- « Destinataire » des paramètres SMTP sont reprises dans alert_recipients (toutes les catégories), puis ces champs sont
-- vidés, pour qu'une adresse supprimée de la liste ne reçoive plus rien. Les colonnes restent en place.
-- IDEMPOTENT. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V22 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

INSERT INTO `alert_recipients` (`project_id`, `email`, `categories`, `is_active`, `created_at`, `updated_at`)
SELECT x.`project_id`, x.`email`, 'RETARD,GARANTIE,DECOMPTE,STOCK,CHARROI,GROUPE', 1, NOW(), NOW()
  FROM (SELECT `project_id`, LOWER(TRIM(`recipient1`)) AS `email` FROM `smtp_settings`
        UNION SELECT `project_id`, LOWER(TRIM(`recipient2`)) FROM `smtp_settings`
        UNION SELECT `project_id`, LOWER(TRIM(`recipient3`)) FROM `smtp_settings`
        UNION SELECT `project_id`, LOWER(TRIM(`recipient4`)) FROM `smtp_settings`) x
 WHERE x.`email` LIKE '%_@_%._%'
   AND NOT EXISTS (SELECT 1 FROM `alert_recipients` r WHERE r.`project_id` = x.`project_id` AND r.`email` = x.`email`);

UPDATE `smtp_settings` SET `recipient1` = NULL, `recipient2` = NULL, `recipient3` = NULL, `recipient4` = NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_02_000001_project_recipients_single_list', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_11_02_000001_project_recipients_single_list');
