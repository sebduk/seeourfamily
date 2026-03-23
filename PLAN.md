# Plan: .env, Media Migration, and Unified Documents

## 1. Clean up `.env.example` and ensure `.env` is actually used

### 1a. Rewrite `.env.example` — all variables, uncommented, with sensible Mac-friendly defaults

```env
# See Our Family — Environment Configuration
# Copy to .env and edit. NEVER commit .env to git.

# ── Database ──
DB_HOST=localhost
DB_PORT=3306
DB_NAME=seeourfamily
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

# ── Media storage ──
# All files (photos, videos, docs) live here as flat UUID files:
#   {MEDIA_DIR}/{family_id}/{uuid}.ext
# On Mac you might use ./media or ~/seeourfamily-media
# On Linux production: /var/media/seeourfamily
MEDIA_DIR=./media

# Legacy files imported from the old ASP system sit under:
#   {MEDIA_LEGACY_DIR}/Gene/File/{FamilyName}/...
# Only needed until you run the migration script (see cli/migrate-media.php).
# Set to empty string once all files have been migrated.
MEDIA_LEGACY_DIR=

# ── Development ──
# Set to 1 to enable "Dev login" bypass buttons on the login page.
# NEVER enable in production.
APP_DEV=0

# ── SMTP (outgoing email) ──
# Leave SMTP_HOST blank to show tokens on-screen instead of emailing.
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM=noreply@example.com
SMTP_FROM_NAME=See Our Family
```

Key changes:
- Every variable is **uncommented** so the full inventory is obvious.
- `MEDIA_DIR` defaults to `./media` (works everywhere, no `/var` assumption).
- `MEDIA_LEGACY_DIR` defaults to empty — no silent fallback to document root.

### 1b. Make the app fail clearly when `.env` is missing

Currently if there is no `.env`, the code silently falls through and `$_ENV` is empty; DB/Media then rely on fallback defaults that may or may not work.

**Change in `index.php`:**
- After the `.env` loading block, if `$_ENV['DB_HOST']` is not set, display a clear error:
  _"`.env` file not found. Copy `.env.example` to `.env` and configure it."_
  and `exit`.

This makes it impossible to run the app with an unconfigured environment.

### 1c. Harden `Media.php` constructor against empty `MEDIA_LEGACY_DIR`

Currently falls back to `$_SERVER['DOCUMENT_ROOT']`. Change to:
- If `MEDIA_LEGACY_DIR` is set and non-empty → use it.
- Otherwise → legacy resolution is **disabled** (diskPath returns null for legacy files).

This means once you set `MEDIA_LEGACY_DIR=` (empty), legacy lookup is off — which is exactly what we want after migration.

---

## 2. CLI migration script: legacy files → `MEDIA_DIR`

New file: **`cli/migrate-media.php`**

What it does:
1. Loads `.env`.
2. Reads every row from `photos` where `stored_filename IS NULL` and `file_name IS NOT NULL`.
3. For each row, resolves the legacy disk path using the current logic:
   `{MEDIA_LEGACY_DIR}/Gene/File/{FamilyName}/Image/{file_name}` or `.../Document/{file_name}`.
4. Generates a UUID-based `stored_filename` (same logic as `storeUpload`).
5. **Copies** the file to `{MEDIA_DIR}/{family_id}/{uuid}.ext`.
6. Creates a thumbnail for images.
7. Updates the DB row: sets `stored_filename`, `original_filename` (= `file_name`), `mime_type`, `file_size`.
8. Prints progress and a summary at the end.

**Safety:**
- Dry-run mode by default (`--dry-run`). Pass `--execute` to actually copy/update.
- Never deletes the legacy files — you clean those up manually once satisfied.
- Skips rows that already have a `stored_filename`.
- Reports files that couldn't be found on disk (missing legacy files).

After running this, you can set `MEDIA_LEGACY_DIR=` and the legacy path is dead.

---

## 3. Unify Photos and Documents into a single "Documents" model

The core idea: **everything is a document**. The photo/document split is just a UI concern based on file type.

### 3a. Database changes (new migration `005_unify_documents.sql`)

**`folders` table:**
- Drop the `type` column (`image` / `document`). Folders are just folders — any file type can go in any folder.

**`photos` table — rename to `documents`:**
- `ALTER TABLE photos RENAME TO documents;`
- Rename all foreign key constraints and indexes accordingly.
- Update `photo_person_link` → `document_person_link` (rename table + columns `photo_id` → `document_id`).
- Update `photo_tags` → `document_tags` (rename table + columns `photo_id` → `document_id`).
- Rename columns:
  - `photo_date` → `doc_date`
  - `photo_precision` → `doc_precision`

**No new columns needed** — the existing schema already has everything:
- `uuid` — public identifier
- `stored_filename` — UUID on disk
- `original_filename` — display name (the "Name" you want)
- `mime_type` — determines file type and which section it appears in
- `description` — can be blank
- `doc_date` + `doc_precision` — date with precision
- `folder_id` — virtual folder
- `document_person_link` — people attached in order

### 3b. Update `Media.php`

- Rename constants and queries from `photos` → `documents`.
- Update `diskPath()`, `thumbnailPath()`, `serve()`, `storeUpload()` to reference `documents` table.
- Expand allowed upload extensions to include: `jpg, jpeg, gif, png, webp, mp3, ogg, wav, mp4, avi, webm, mov, m4v, pdf, txt, doc, docx, ppt, pptx, xls, xlsx`.
- Expand MIME whitelist accordingly.
- Add helper: `isVisual(mime): bool` → true for image + video + audio (the "photo grid" types).

### 3c. Update admin pages

- **Merge** `admin-photos.php` and `admin-documents.php` into a single **`admin-documents.php`**.
- Single upload form accepting all file types.
- Sidebar shows all documents, filterable by:
  - Type chip: "Media" (images/video/audio) | "Files" (pdf, doc, etc.) | "All"
  - Folder dropdown
  - Person filter
- Tag interface shown only when editing an image (`jpg/jpeg/gif/png`).
- Poster upload shown only when editing video/audio.
- Delete the old `admin-photos.php` file.
- Update route mappings in `index.php` — `/admin/photos` redirects to `/admin/documents`.

### 3d. Update public pages

- **`photos.php`** → rename to display the "Media" view (photo/video/audio grid).
  - Queries `documents` table, filtered to visual MIME types.
  - Grid display stays the same.
  - Folders are shared (no `type` filter on folders).

- **`documents.php`** → displays "Files" view (pdf, doc, txt, etc.).
  - Queries `documents` table, filtered to non-visual MIME types.
  - Table display stays the same.
  - Folders are shared.

- Both pages source from the same `documents` table, just filtered differently.

### 3e. Update all other references

- `index.php`: route table, media serving query, any reference to `photos` table.
- `templates/pages/admin-info.php` or any page that joins on `photos` → update to `documents`.
- `templates/pages/person.php` (if it shows a person's photos) → query `documents` + `document_person_link`.
- Navigation menu labels stay user-friendly: "Photos" and "Documents" in the UI even though the table is unified.

---

## Execution order

1. **`.env.example` + `.env` enforcement** — you create your `.env`, app starts working with explicit config.
2. **`cli/migrate-media.php`** — you run it to copy legacy files into `MEDIA_DIR`. Set `MEDIA_LEGACY_DIR=` after.
3. **Migration 005 + code changes** — unify the schema and merge the admin/public pages.

Each step is independently committable and deployable.
