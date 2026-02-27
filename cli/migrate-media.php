#!/usr/bin/env php
<?php

/**
 * CLI: Migrate legacy media files to UUID-based flat storage.
 *
 * Usage:
 *   php cli/migrate-media.php              # dry-run (default)
 *   php cli/migrate-media.php --dry-run    # same as above
 *   php cli/migrate-media.php --execute    # actually copy files + update DB
 *
 * For each photos row where stored_filename IS NULL and file_name IS NOT NULL:
 *   1. Resolves the legacy disk path: {MEDIA_LEGACY_DIR}/Gene/File/{FamilyName}/Image|Document/{file_name}
 *   2. Copies the file to {MEDIA_DIR}/{family_id}/{uuid}.ext
 *   3. Creates a thumbnail for images.
 *   4. Updates the DB row with stored_filename, original_filename, mime_type, file_size.
 *
 * Safety:
 *   - Dry-run by default — pass --execute to make real changes.
 *   - Never deletes legacy files — clean up manually once satisfied.
 *   - Skips rows that already have a stored_filename.
 *   - Reports files that couldn't be found on disk.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Bootstrap
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Media.php';

// Load .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Parse flags
$execute = in_array('--execute', $argv, true);
$dryRun  = !$execute;

// Validate MEDIA_LEGACY_DIR
$legacyDir = rtrim($_ENV['MEDIA_LEGACY_DIR'] ?? '', '/');
if ($legacyDir === '') {
    fwrite(STDERR, "Error: MEDIA_LEGACY_DIR is not set in .env. Nothing to migrate from.\n");
    exit(1);
}
if (!is_dir($legacyDir)) {
    fwrite(STDERR, "Error: MEDIA_LEGACY_DIR does not exist: {$legacyDir}\n");
    exit(1);
}

$mediaDir = rtrim($_ENV['MEDIA_DIR'] ?? (__DIR__ . '/../media'), '/');

$db  = new SeeOurFamily\Database();
$pdo = $db->pdo();
$media = new SeeOurFamily\Media($db);

// ------------------------------------------------------------------
// Pre-cache family names: id → name
// ------------------------------------------------------------------
$familyNames = [];
$stmt = $pdo->query('SELECT id, name FROM families');
while ($f = $stmt->fetch()) {
    $familyNames[(int)$f['id']] = $f['name'];
}

// ------------------------------------------------------------------
// Fetch rows that need migration
// ------------------------------------------------------------------
$stmt = $pdo->query(
    "SELECT id, uuid, family_id, file_name
     FROM photos
     WHERE stored_filename IS NULL
       AND file_name IS NOT NULL
       AND file_name != ''
     ORDER BY family_id, id"
);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "No legacy photos to migrate. All rows already have stored_filename.\n";
    exit(0);
}

echo ($dryRun ? "[DRY RUN] " : "") . "Found " . count($rows) . " photo(s) to migrate.\n\n";

// ------------------------------------------------------------------
// UUID generator (same logic as Media::generateUuid)
// ------------------------------------------------------------------
function generateMigrationUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ------------------------------------------------------------------
// Migrate
// ------------------------------------------------------------------
$migrated = 0;
$skipped  = 0;
$missing  = 0;
$errors   = 0;

$updateStmt = $pdo->prepare(
    'UPDATE photos
     SET stored_filename  = ?,
         original_filename = ?,
         mime_type         = ?,
         file_size         = ?
     WHERE id = ?'
);

foreach ($rows as $row) {
    $familyId   = (int)$row['family_id'];
    $familyName = $familyNames[$familyId] ?? '';
    $fileName   = $row['file_name'];

    if ($familyName === '') {
        fwrite(STDERR, "  [SKIP] Photo #{$row['id']}: no family name for family_id={$familyId}\n");
        $skipped++;
        continue;
    }

    // Resolve legacy path
    $ext    = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'gif', 'png', 'webp'], true);
    $subDir  = $isImage ? 'Image' : 'Document';
    $srcPath = $legacyDir . '/Gene/File/' . $familyName . '/' . $subDir . '/' . $fileName;

    if (!file_exists($srcPath)) {
        fwrite(STDERR, "  [MISSING] Photo #{$row['id']} ({$row['uuid']}): {$srcPath}\n");
        $missing++;
        continue;
    }

    // Prepare destination
    $storedName = generateMigrationUuid() . '.' . $ext;
    $destDir    = $mediaDir . '/' . $familyId;
    $destPath   = $destDir . '/' . $storedName;

    // Detect MIME + size
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($srcPath);
    $size  = (int)filesize($srcPath);

    if ($dryRun) {
        echo "  [OK] Photo #{$row['id']} ({$row['uuid']}): {$fileName} → {$storedName}"
           . "  [{$mime}, " . number_format($size) . " bytes]\n";
        $migrated++;
        continue;
    }

    // Create destination directory
    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0755, true)) {
            fwrite(STDERR, "  [ERROR] Photo #{$row['id']}: could not create dir {$destDir}\n");
            $errors++;
            continue;
        }
    }

    // Copy file (never move/delete the legacy file)
    if (!copy($srcPath, $destPath)) {
        fwrite(STDERR, "  [ERROR] Photo #{$row['id']}: copy failed → {$destPath}\n");
        $errors++;
        continue;
    }

    // Create thumbnail for images
    if (in_array($ext, ['jpg', 'jpeg', 'gif', 'png'], true)) {
        $media->createThumbnail($destPath, $destDir, $storedName);
    }

    // Update DB
    $updateStmt->execute([
        $storedName,
        $fileName,  // original_filename = legacy file_name
        $mime,
        $size,
        $row['id'],
    ]);

    echo "  [OK] Photo #{$row['id']} ({$row['uuid']}): {$fileName} → {$storedName}\n";
    $migrated++;
}

// ------------------------------------------------------------------
// Summary
// ------------------------------------------------------------------
echo "\n" . str_repeat('─', 50) . "\n";
echo ($dryRun ? "[DRY RUN] " : "") . "Summary:\n";
echo "  Migrated : {$migrated}\n";
echo "  Missing  : {$missing} (legacy file not found on disk)\n";
echo "  Skipped  : {$skipped} (no family name)\n";
if (!$dryRun) {
    echo "  Errors   : {$errors}\n";
}
echo str_repeat('─', 50) . "\n";

if ($dryRun) {
    echo "\nThis was a dry run. Pass --execute to actually copy files and update the database.\n";
}

echo "Done.\n";
