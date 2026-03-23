-- =======================================================================
-- Migration 002: Superadmin & Credential Cleanup
-- =======================================================================
-- Phase 2 of the modernisation project.
--
-- Adds:
--   - is_superadmin flag on users (system-wide admin powers)
--   - Nullifies old ASP-era family passwords (guest_password, admin_password)
--     so that legacy password-only login is disabled for all families.
--     Users must now log in via username + password.
--
-- Run against an existing seeourfamily database.
-- Idempotent: checks before altering.
-- =======================================================================

USE `seeourfamily`;


-- -----------------------------------------------------------------------
-- 1. Add is_superadmin column to users
-- -----------------------------------------------------------------------

DELIMITER //
CREATE PROCEDURE IF NOT EXISTS _add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    SET @col_exists = (
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = p_table
          AND column_name = p_column
    );
    IF @col_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _add_column_if_missing('users', 'is_superadmin', "TINYINT NOT NULL DEFAULT 0 COMMENT 'System-wide superadmin flag' AFTER `email`");


-- -----------------------------------------------------------------------
-- 2. Scrape old ASP-era family passwords
-- -----------------------------------------------------------------------
-- These were stored as plaintext in the original Access DB, then hashed
-- during migration. Now that real user accounts exist, the old family-level
-- passwords are no longer needed. Nullifying them disables legacy login.

UPDATE `families` SET `guest_password` = NULL, `admin_password` = NULL
WHERE `guest_password` IS NOT NULL OR `admin_password` IS NOT NULL;


-- -----------------------------------------------------------------------
-- 3. Cleanup
-- -----------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _add_column_if_missing;


-- -----------------------------------------------------------------------
-- Done.
-- -----------------------------------------------------------------------
SELECT 'Migration 002 complete.' AS status;
