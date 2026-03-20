# CLAUDE.md — Project Context for Claude Code

## What is this project?

**See Our Family** — a multi-tenant family genealogy and history web application. Migrated from Classic ASP + MS Access to PHP 8.1+ with MariaDB. Families can maintain genealogy trees, photo collections, blogs, and records.

## Tech Stack

- **Backend:** PHP 8.1+ (strict mode, PSR-4 autoload), MariaDB 10.x
- **Frontend:** HTML5, CSS3, vanilla JS + jQuery, Treant.js + Raphael (tree visualization)
- **Dependencies:** Composer (phpdotenv), npm (treant-js, raphael)
- **No test framework** — manual QA only
- **No build step** — PHP files served directly via Apache (.htaccess rewrites)

## Key Directories

```
src/                  Core PHP classes (Auth, Database, Router, Media, Html, Labels)
templates/            View layer
  layout.php          Master template
  pages/              38 page templates (home, tree variants, admin-*, etc.)
  help/               Help pages in 7 languages
cli/                  CLI scripts (migrate-media, create-superadmin, scrape-old-passwords)
migrations/           SQL migrations (001–006, applied incrementally)
js/                   Frontend JS + vendor libs
lang/                 Translations (EN, FR, ES, IT, PT, DE, NL)
Image/                Static images, favicon
```

## Key Files

- `index.php` — Front controller & bootstrap (412 lines). Routes, auth, labels, media serving, layout rendering.
- `createDB.sql` — Initial schema + sample data
- `migrate_access_to_mariadb.py` — Python script to migrate legacy Access .mdb files to MariaDB
- `style.css` — Monolithic stylesheet (1006 lines)
- `.env.example` — Environment config template (DB, media paths, SMTP, dev mode)
- `PLAN.md` — Detailed roadmap for .env cleanup, media migration, and photo/document unification

## Architecture

- **Front controller pattern:** All requests route through `index.php` via `.htaccess`
- **Multi-tenant:** Each family has isolated data; Auth.php manages family context
- **Media storage:** UUID-based flat files in `{MEDIA_DIR}/{family_id}/{uuid}.ext`
- **Legacy compat:** MEDIA_LEGACY_DIR supports old ASP-era file paths during migration
- **7 tree views:** classic, descendants, fan-charts, family-chart (Treant.js + family-chart lib)

## Current State & Roadmap (see PLAN.md)

**Completed:**
- Core ASP → PHP migration
- Database schema in MariaDB with proper FK constraints
- Authentication (bcrypt, sessions), multi-language UI
- Media upload/serving with UUID storage
- CLI media migration script (`cli/migrate-media.php`)
- Multiple tree visualization modes (including family-chart, descendant fan chart)
- SQL migrations 001–006

**In Progress / Next Steps (from PLAN.md):**
1. `.env` enforcement — fail clearly when `.env` is missing
2. Harden `Media.php` against empty `MEDIA_LEGACY_DIR`
3. Unify photos + documents into single `documents` table (migration 005+)
4. Merge `admin-photos.php` and `admin-documents.php` into one admin page
5. Update public pages to query unified `documents` table

## Git Info

- **Main branch:** `main` (remote), `master` (local)
- **Dev branch:** `claude/convert-asp-to-python-php-yw1Go`
- Recent work: tree visualization improvements, CSS fixes, family-chart integration

## Running the App

1. Copy `.env.example` to `.env` and configure
2. `composer install`
3. Create MariaDB database, run `createDB.sql` then `migrations/*.sql` in order
4. Point Apache/nginx to project root with `.htaccess` support
5. `APP_DEV=1` in `.env` enables dev login bypass buttons

## Common Patterns

- Pages are PHP templates in `templates/pages/` — they receive variables from `index.php`
- Routes defined in `src/Router.php` — maps clean URLs to page templates
- Database queries use PDO directly (no ORM)
- Labels/translations loaded from `lang/*.php` files (associative arrays)
- Admin pages prefixed `admin-*` with role-based access in Auth.php
