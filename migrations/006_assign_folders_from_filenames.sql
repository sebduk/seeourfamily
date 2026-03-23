-- =======================================================================
-- Migration 006: Assign documents with "/" in file_name to folders
-- and ensure file_name uses "folder/name.ext" format consistently.
-- =======================================================================
--
-- Context:
--   Legacy file_name values from the Access DB may contain path separators,
--   e.g. "Vacances/photo01.jpg". The part before the "/" represents a
--   subfolder on disk. This migration:
--
--   1. Creates any missing folders from those prefixes.
--   2. Assigns documents to the matching folder (sets folder_id).
--   3. For documents already in a folder but whose file_name lacks the
--      folder prefix, prepends it so every in-folder document has a
--      consistent "FolderName/file.ext" name.
-- =======================================================================

-- -----------------------------------------------------------------
-- Step 1: Create folders for each distinct prefix found in file_name
--         that doesn't already exist for the family.
-- -----------------------------------------------------------------
INSERT INTO folders (uuid, family_id, name)
SELECT UUID(), sub.family_id, sub.folder_name
FROM (
    SELECT DISTINCT d.family_id,
           SUBSTRING_INDEX(d.file_name, '/', 1) AS folder_name
    FROM documents d
    WHERE d.file_name LIKE '%/%'
      AND d.folder_id IS NULL
      AND d.file_name NOT LIKE '%.tn.%'
) sub
WHERE NOT EXISTS (
    SELECT 1 FROM folders f
    WHERE f.family_id = sub.family_id
      AND f.name = sub.folder_name
      AND f.is_online = 1
);

-- -----------------------------------------------------------------
-- Step 2: Assign documents with "/" in file_name to matching folders.
-- -----------------------------------------------------------------
UPDATE documents d
JOIN folders f
  ON f.family_id = d.family_id
 AND f.name = SUBSTRING_INDEX(d.file_name, '/', 1)
 AND f.is_online = 1
SET d.folder_id = f.id
WHERE d.file_name LIKE '%/%'
  AND d.folder_id IS NULL
  AND d.file_name NOT LIKE '%.tn.%';

-- -----------------------------------------------------------------
-- Step 3: For documents already in a folder but whose file_name does
--         not start with the folder name, prepend it.
--         (Skips rows that already have any "/" — those are handled.)
-- -----------------------------------------------------------------
UPDATE documents d
JOIN folders f ON f.id = d.folder_id AND f.is_online = 1
SET d.file_name = CONCAT(f.name, '/', d.file_name)
WHERE d.folder_id IS NOT NULL
  AND d.file_name IS NOT NULL
  AND d.file_name != ''
  AND d.file_name NOT LIKE '%/%';

-- -----------------------------------------------------------------
-- Done.
-- -----------------------------------------------------------------
SELECT 'Migration 006 complete.' AS status;
