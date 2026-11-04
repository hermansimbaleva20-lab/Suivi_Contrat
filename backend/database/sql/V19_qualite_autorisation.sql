-- ============================================================================
-- PRISE / PREDIRE V19 — QUALITÉ D'AUTORISATION DES DEMANDES LOGISTIQUES — MySQL
--
-- Équivalent SQL de la migration 2026_10_29_000005_logistics_authority : users.logistics_authority = CA (antenne),
-- HRAF ou COMPTABLE (coordination nationale) ; can_authorize_fleet en découle. Les comptes déjà habilités reçoivent la
-- qualité de leur unité. IDEMPOTENT et NON DESTRUCTIF. SAUVEGARDEZ LA BASE AVANT. Prérequis : V9 à V18 appliqués.
-- ============================================================================
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS v19_add_column;
DELIMITER $$
CREATE PROCEDURE v19_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def VARCHAR(500))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
    SET @s = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

CALL v19_add_column('users', 'logistics_authority', 'VARCHAR(12) NULL');
DROP PROCEDURE IF EXISTS v19_add_column;

UPDATE `users` u LEFT JOIN `organizational_units` o ON o.`id` = u.`organizational_unit_id` LEFT JOIN `positions` p ON p.`id` = u.`position_id`
   SET u.`logistics_authority` = CASE WHEN o.`type` = 'POOL' THEN 'CA'
       WHEN UPPER(COALESCE(p.`name`, u.`project_position`, '')) LIKE '%HRAF%' THEN 'HRAF' ELSE 'COMPTABLE' END
 WHERE u.`can_authorize_fleet` = 1 AND u.`logistics_authority` IS NULL AND u.`is_super_admin` = 0 AND o.`id` IS NOT NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_29_000005_logistics_authority', b.`n` + 1 FROM (SELECT COALESCE(MAX(`batch`), 0) AS `n` FROM `migrations`) b
 WHERE NOT EXISTS (SELECT 1 FROM (SELECT `migration` FROM `migrations`) m WHERE m.`migration` = '2026_10_29_000005_logistics_authority');
