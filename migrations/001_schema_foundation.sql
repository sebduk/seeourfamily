-- =======================================================================
-- Migration 001: Schema Foundation
-- =======================================================================
-- Phase 1 of the modernisation project.
--
-- Adds:
--   - uuid columns to all content tables (for public-facing URLs)
--   - folders table (virtual folder management for media)
--   - New columns on photos (flat storage, folder FK, mime, size, poster)
--   - blog_posts table (public front page blog)
--   - password_resets table (password recovery tokens)
--   - invitations table (user onboarding tokens)
--   - uuid column on users table
--
-- Run against an existing seeourfamily database.
-- Idempotent: uses IF NOT EXISTS / IF NOT COLUMN checks where possible.
-- =======================================================================

USE `seeourfamily`;


-- -----------------------------------------------------------------------
-- 1. Add uuid columns to existing tables
-- -----------------------------------------------------------------------

-- Helper: MariaDB doesn't support IF NOT EXISTS on ALTER ADD COLUMN,
-- so we use a procedure to check first.

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


-- families
CALL _add_column_if_missing('families', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- users
CALL _add_column_if_missing('users', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- people
CALL _add_column_if_missing('people', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- couples
CALL _add_column_if_missing('couples', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- photos
CALL _add_column_if_missing('photos', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- comments
CALL _add_column_if_missing('comments', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- forums
CALL _add_column_if_missing('forums', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- forum_items
CALL _add_column_if_missing('forum_items', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");

-- infos
CALL _add_column_if_missing('infos', 'uuid', "CHAR(36) NOT NULL DEFAULT '' COMMENT 'Public-facing identifier (UUIDv4)' AFTER `id`");


-- -----------------------------------------------------------------------
-- 2. Populate UUIDs for existing rows (empty uuid = needs one)
-- -----------------------------------------------------------------------

UPDATE `families`    SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `users`       SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `people`      SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `couples`     SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `photos`      SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `comments`    SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `forums`      SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `forum_items` SET `uuid` = UUID() WHERE `uuid` = '';
UPDATE `infos`       SET `uuid` = UUID() WHERE `uuid` = '';


-- -----------------------------------------------------------------------
-- 3. Add unique indexes on uuid columns (skip if already exists)
-- -----------------------------------------------------------------------

DELIMITER //
CREATE PROCEDURE IF NOT EXISTS _add_unique_if_missing(
    IN p_table VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_column VARCHAR(64)
)
BEGIN
    SET @idx_exists = (
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = p_table
          AND index_name = p_index_name
    );
    IF @idx_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD UNIQUE KEY `', p_index_name, '` (`', p_column, '`)');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _add_unique_if_missing('families',    'uq_families_uuid',    'uuid');
CALL _add_unique_if_missing('users',       'uq_users_uuid',       'uuid');
CALL _add_unique_if_missing('people',      'uq_people_uuid',      'uuid');
CALL _add_unique_if_missing('couples',     'uq_couples_uuid',     'uuid');
CALL _add_unique_if_missing('photos',      'uq_photos_uuid',      'uuid');
CALL _add_unique_if_missing('comments',    'uq_comments_uuid',    'uuid');
CALL _add_unique_if_missing('forums',      'uq_forums_uuid',      'uuid');
CALL _add_unique_if_missing('forum_items', 'uq_forum_items_uuid', 'uuid');
CALL _add_unique_if_missing('infos',       'uq_infos_uuid',       'uuid');


-- -----------------------------------------------------------------------
-- 4. Create folders table
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `folders` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `uuid`            CHAR(36)     NOT NULL COMMENT 'Public-facing identifier (UUIDv4)',
  `family_id`       INT          NOT NULL,
  `type`            VARCHAR(10)  NOT NULL COMMENT 'image or document',
  `name`            VARCHAR(255) NOT NULL,
  `parent_folder_id` INT                  DEFAULT NULL COMMENT 'Nullable, for nested folders',
  `is_online`       TINYINT      NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by`      INT                   DEFAULT NULL,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_folders_uuid` (`uuid`),
  KEY `idx_folders_family` (`family_id`),
  KEY `idx_folders_parent` (`parent_folder_id`),
  CONSTRAINT `fk_folders_family` FOREIGN KEY (`family_id`) REFERENCES `families` (`id`),
  CONSTRAINT `fk_folders_parent` FOREIGN KEY (`parent_folder_id`) REFERENCES `folders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------
-- 5. Add new columns to photos table
-- -----------------------------------------------------------------------

CALL _add_column_if_missing('photos', 'stored_filename',  "VARCHAR(255) DEFAULT NULL COMMENT 'UUID-based name on disk' AFTER `file_name`");
CALL _add_column_if_missing('photos', 'original_filename', "VARCHAR(255) DEFAULT NULL COMMENT 'Original filename as uploaded' AFTER `stored_filename`");
CALL _add_column_if_missing('photos', 'folder_id',        "INT DEFAULT NULL COMMENT 'FK to folders, NULL = root' AFTER `original_filename`");
CALL _add_column_if_missing('photos', 'mime_type',        "VARCHAR(100) DEFAULT NULL COMMENT 'e.g. image/jpeg' AFTER `folder_id`");
CALL _add_column_if_missing('photos', 'file_size',        "BIGINT DEFAULT NULL COMMENT 'Size in bytes' AFTER `mime_type`");
CALL _add_column_if_missing('photos', 'poster_uuid',      "CHAR(36) DEFAULT NULL COMMENT 'Poster image UUID for video/audio' AFTER `file_size`");

-- Add folder FK if not exists (check constraint name)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'photos'
      AND constraint_name = 'fk_photos_folder'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `photos` ADD KEY `idx_photos_folder` (`folder_id`), ADD CONSTRAINT `fk_photos_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------
-- 6. Create blog_posts table
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `uuid`            CHAR(36)     NOT NULL COMMENT 'Public-facing identifier (UUIDv4)',
  `title`           VARCHAR(255) NOT NULL,
  `body`            TEXT         NOT NULL COMMENT 'HTML or plain text content',
  `is_published`    TINYINT      NOT NULL DEFAULT 0 COMMENT '1=visible to public, 0=draft',
  `is_hidden`       TINYINT      NOT NULL DEFAULT 0 COMMENT '1=hidden from listing even if published',
  `published_at`    TIMESTAMP             DEFAULT NULL COMMENT 'When the post went live',
  `is_online`       TINYINT      NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by`      INT                   DEFAULT NULL,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_posts_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------
-- 7. Create password_resets table
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `user_id`         INT          NOT NULL,
  `token`           VARCHAR(255) NOT NULL COMMENT 'Unique reset token (hashed in app layer)',
  `expires_at`      TIMESTAMP    NOT NULL COMMENT 'Token expiry time',
  `used_at`         TIMESTAMP             DEFAULT NULL COMMENT 'When the token was consumed',
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token` (`token`),
  KEY `idx_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------
-- 8. Create invitations table
-- -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `invitations` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `family_id`       INT          NOT NULL,
  `token`           VARCHAR(255) NOT NULL COMMENT 'Unique invitation token',
  `email`           VARCHAR(255) NOT NULL COMMENT 'Email the invitation was sent to',
  `role`            VARCHAR(10)  NOT NULL DEFAULT 'Guest' COMMENT 'Role granted on acceptance: Owner, Admin, Guest',
  `expires_at`      TIMESTAMP    NOT NULL COMMENT 'Token expiry time',
  `used_at`         TIMESTAMP             DEFAULT NULL COMMENT 'When the invitation was accepted',
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invitations_token` (`token`),
  KEY `idx_inv_family` (`family_id`),
  CONSTRAINT `fk_inv_family` FOREIGN KEY (`family_id`) REFERENCES `families` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------
-- 9. Cleanup helper procedures
-- -----------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _add_column_if_missing;
DROP PROCEDURE IF EXISTS _add_unique_if_missing;


-- -----------------------------------------------------------------------
-- Done.
-- -----------------------------------------------------------------------
SELECT 'Migration 001 complete.' AS status;
