-- ============================================================================
-- PRISE / PREDIRE V11 — LOGISTIQUE : STOCKS (TDR §6, phase 3) — MySQL
--
-- Équivalent SQL de la migration 2026_10_28_000007_create_stock_module.
-- À utiliser à la place de « php artisan migrate » si vous passez par phpMyAdmin.
-- IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT.
-- Prérequis : V10 appliquée (V10_socle_organisationnel.sql ou php artisan migrate).
-- ============================================================================
SET NAMES utf8mb4;

-- Catalogue des articles du projet (« prix unitaires évalués » : article, unité, PU en USD)
CREATE TABLE IF NOT EXISTS `stock_articles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `number` INT UNSIGNED NOT NULL,
  `designation` VARCHAR(255) NOT NULL,
  `unit` VARCHAR(40) NOT NULL,
  `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `alert_threshold` DECIMAL(14,2) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `stock_articles_project_number_unique` (`project_id`, `number`),
  UNIQUE KEY `stock_articles_project_designation_unique` (`project_id`, `designation`),
  CONSTRAINT `stock_articles_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Lignes des fiches de stock, par unité (jamais modifiées : annulées avec motif)
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `organizational_unit_id` BIGINT UNSIGNED NOT NULL,
  `stock_article_id` BIGINT UNSIGNED NOT NULL,
  `movement_date` DATE NOT NULL,
  `type` VARCHAR(10) NOT NULL,
  `quantity` DECIMAL(14,2) NOT NULL,
  `origin_destination` VARCHAR(190) NOT NULL,
  `observation` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `cancelled_at` TIMESTAMP NULL,
  `cancelled_by` BIGINT UNSIGNED NULL,
  `cancel_reason` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NULL, `updated_at` TIMESTAMP NULL,
  KEY `stock_movements_card_idx` (`project_id`, `organizational_unit_id`, `stock_article_id`, `movement_date`),
  CONSTRAINT `stock_movements_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `organizational_units` (`id`),
  CONSTRAINT `stock_movements_stock_article_id_foreign` FOREIGN KEY (`stock_article_id`) REFERENCES `stock_articles` (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Habilitations : consulter / gérer les stocks
DROP PROCEDURE IF EXISTS v11_add_column;
DELIMITER $$
CREATE PROCEDURE v11_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;
CALL v11_add_column('users', 'can_view_stock', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL v11_add_column('users', 'can_manage_stock', 'TINYINT(1) NOT NULL DEFAULT 0');
DROP PROCEDURE IF EXISTS v11_add_column;

-- Enregistrement de la migration (évite qu'artisan migrate la rejoue). MAX() dans une table dérivée : un agrégat seul
-- renverrait une ligne même quand NOT EXISTS est faux, et la migration serait enregistrée à chaque exécution.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_28_000007_create_stock_module', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_28_000007_create_stock_module');
