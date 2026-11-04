-- ============================================================================
-- PRISE / PREDIRE V15 — ÉTATS FIGÉS (rapports) ET ALERTES PAR E-MAIL (destinataires, séquence, historique) — MySQL
--
-- Équivalent SQL de la migration 2026_10_29_000001_reporting_and_alert_settings.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V14 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `report_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `phase_id` BIGINT UNSIGNED NULL,
  `period_type` VARCHAR(12) NOT NULL,
  `label` VARCHAR(60) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `data` LONGTEXT NOT NULL,
  `comment` VARCHAR(2000) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `report_snapshots_label_unique` (`project_id`, `phase_id`, `label`),
  CONSTRAINT `report_snapshots_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `name` VARCHAR(150) NULL,
  `organizational_unit_id` BIGINT UNSIGNED NULL,
  `categories` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `alert_recipients_email_unique` (`project_id`, `email`),
  CONSTRAINT `alert_recipients_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alert_recipients_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `category` VARCHAR(20) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `interval_days` SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  `max_sends` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `alert_rules_category_unique` (`project_id`, `category`),
  CONSTRAINT `alert_rules_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alert_dispatches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `category` VARCHAR(20) NOT NULL,
  `alert_key` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `recipients` TEXT NOT NULL,
  `status` VARCHAR(10) NOT NULL,
  `message` VARCHAR(1000) NULL,
  `trigger` VARCHAR(10) NOT NULL,
  `sent_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  KEY `alert_dispatches_key_idx` (`project_id`, `alert_key`, `status`),
  CONSTRAINT `alert_dispatches_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Séquence par défaut : relance tous les 7 jours, 3 envois au plus, pour chaque catégorie et chaque projet
INSERT INTO `alert_rules` (`project_id`, `category`, `is_enabled`, `interval_days`, `max_sends`, `created_at`, `updated_at`)
SELECT p.`id`, c.`cat`, 1, 7, 3, NOW(), NOW()
  FROM `projects` p
  JOIN (SELECT 'RETARD' AS `cat` UNION ALL SELECT 'GARANTIE' UNION ALL SELECT 'DECOMPTE' UNION ALL SELECT 'STOCK' UNION ALL SELECT 'CHARROI' UNION ALL SELECT 'GROUPE') c
 WHERE NOT EXISTS (SELECT 1 FROM `alert_rules` r WHERE r.`project_id` = p.`id` AND r.`category` = c.`cat`);

-- Reprise des 4 destinataires SMTP existants, abonnés à toutes les catégories
INSERT INTO `alert_recipients` (`project_id`, `email`, `categories`, `is_active`, `created_at`, `updated_at`)
SELECT x.`project_id`, x.`email`, 'RETARD,GARANTIE,DECOMPTE,STOCK,CHARROI,GROUPE', 1, NOW(), NOW()
  FROM (SELECT `project_id`, LOWER(TRIM(`recipient1`)) AS `email` FROM `smtp_settings`
        UNION SELECT `project_id`, LOWER(TRIM(`recipient2`)) FROM `smtp_settings`
        UNION SELECT `project_id`, LOWER(TRIM(`recipient3`)) FROM `smtp_settings`
        UNION SELECT `project_id`, LOWER(TRIM(`recipient4`)) FROM `smtp_settings`) x
 WHERE x.`email` LIKE '%_@_%._%'
   AND NOT EXISTS (SELECT 1 FROM `alert_recipients` a WHERE a.`project_id` = x.`project_id` AND a.`email` = x.`email`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_29_000001_reporting_and_alert_settings', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_29_000001_reporting_and_alert_settings');
