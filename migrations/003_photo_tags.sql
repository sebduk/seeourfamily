-- =======================================================================
-- Migration 003: Photo Tags Table
-- =======================================================================
-- Adds the photo_tags table for face/position tagging on photos.
-- This table was in createDB.sql but missing from migration 001.
--
-- Run against an existing seeourfamily database.
-- Idempotent: uses IF NOT EXISTS.
-- =======================================================================

USE `seeourfamily`;

CREATE TABLE IF NOT EXISTS `photo_tags` (
  `id`              INT            NOT NULL AUTO_INCREMENT,
  `photo_id`        INT            NOT NULL,
  `person_id`       INT            NOT NULL,
  `x_pct`           DECIMAL(5,2)   NOT NULL COMMENT '0.00-100.00 from left',
  `y_pct`           DECIMAL(5,2)   NOT NULL COMMENT '0.00-100.00 from top',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_photo_person` (`photo_id`, `person_id`),
  KEY `idx_pt_photo` (`photo_id`),
  CONSTRAINT `fk_pt_photo`  FOREIGN KEY (`photo_id`)  REFERENCES `photos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Migration 003 complete.' AS status;
