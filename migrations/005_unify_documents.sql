-- =======================================================================
-- Migration 005: Unify photos and documents into a single "documents" model
-- =======================================================================
--
-- Core idea: everything is a document. The photo/document split is just
-- a UI concern based on file type (mime_type).
--
-- Changes:
--   1. folders: drop the `type` column (any file type can go in any folder)
--   2. photos  -> documents (rename table + columns + constraints)
--   3. photo_person_link -> document_person_link (rename table + columns)
--   4. photo_tags -> document_tags (rename table + columns)
--   5. Column renames: photo_date -> doc_date, photo_precision -> doc_precision
-- =======================================================================

-- -----------------------------------------------------------------
-- 1. folders: drop the `type` column
-- -----------------------------------------------------------------
ALTER TABLE `folders` DROP COLUMN `type`;

-- -----------------------------------------------------------------
-- 2. photos -> documents
-- -----------------------------------------------------------------

-- Drop foreign keys first (they reference the old table name)
ALTER TABLE `photos` DROP FOREIGN KEY `fk_photos_family`;
ALTER TABLE `photos` DROP FOREIGN KEY `fk_photos_folder`;

-- Rename the table
ALTER TABLE `photos` RENAME TO `documents`;

-- Rename columns
ALTER TABLE `documents`
  CHANGE `photo_date`      `doc_date`      DATE DEFAULT NULL,
  CHANGE `photo_precision` `doc_precision`  VARCHAR(3) DEFAULT NULL COMMENT 'ymd, ym, or y';

-- Rename indexes
ALTER TABLE `documents` DROP KEY `uq_photos_uuid`;
ALTER TABLE `documents` ADD UNIQUE KEY `uq_documents_uuid` (`uuid`);

ALTER TABLE `documents` DROP KEY `idx_photos_family`;
ALTER TABLE `documents` ADD KEY `idx_documents_family` (`family_id`);

ALTER TABLE `documents` DROP KEY `idx_photos_folder`;
ALTER TABLE `documents` ADD KEY `idx_documents_folder` (`folder_id`);

-- Re-add foreign keys with new names
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_family` FOREIGN KEY (`family_id`) REFERENCES `families` (`id`),
  ADD CONSTRAINT `fk_documents_folder` FOREIGN KEY (`folder_id`) REFERENCES `folders` (`id`) ON DELETE SET NULL;

-- -----------------------------------------------------------------
-- 3. photo_person_link -> document_person_link
-- -----------------------------------------------------------------

-- Drop foreign keys
ALTER TABLE `photo_person_link` DROP FOREIGN KEY `fk_ppl_photo`;
ALTER TABLE `photo_person_link` DROP FOREIGN KEY `fk_ppl_person`;

-- Rename table
ALTER TABLE `photo_person_link` RENAME TO `document_person_link`;

-- Rename column photo_id -> document_id
ALTER TABLE `document_person_link`
  CHANGE `photo_id` `document_id` INT NOT NULL;

-- Rebuild primary key and indexes
ALTER TABLE `document_person_link` DROP PRIMARY KEY;
ALTER TABLE `document_person_link` ADD PRIMARY KEY (`document_id`, `person_id`);

ALTER TABLE `document_person_link` DROP KEY `idx_ppl_photo`;
ALTER TABLE `document_person_link` ADD KEY `idx_dpl_document` (`document_id`);

ALTER TABLE `document_person_link` DROP KEY `idx_ppl_person`;
ALTER TABLE `document_person_link` ADD KEY `idx_dpl_person` (`person_id`);

-- Re-add foreign keys
ALTER TABLE `document_person_link`
  ADD CONSTRAINT `fk_dpl_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dpl_person`   FOREIGN KEY (`person_id`)   REFERENCES `people`    (`id`) ON DELETE CASCADE;

-- -----------------------------------------------------------------
-- 4. photo_tags -> document_tags
-- -----------------------------------------------------------------

-- Drop foreign keys
ALTER TABLE `photo_tags` DROP FOREIGN KEY `fk_pt_photo`;
ALTER TABLE `photo_tags` DROP FOREIGN KEY `fk_pt_person`;

-- Rename table
ALTER TABLE `photo_tags` RENAME TO `document_tags`;

-- Rename column photo_id -> document_id
ALTER TABLE `document_tags`
  CHANGE `photo_id` `document_id` INT NOT NULL;

-- Rebuild unique key and indexes
ALTER TABLE `document_tags` DROP KEY `uq_photo_person`;
ALTER TABLE `document_tags` ADD UNIQUE KEY `uq_document_person` (`document_id`, `person_id`);

ALTER TABLE `document_tags` DROP KEY `idx_pt_photo`;
ALTER TABLE `document_tags` ADD KEY `idx_dt_document` (`document_id`);

-- Re-add foreign keys
ALTER TABLE `document_tags`
  ADD CONSTRAINT `fk_dt_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dt_person`   FOREIGN KEY (`person_id`)   REFERENCES `people`    (`id`) ON DELETE CASCADE;
