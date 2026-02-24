#!/usr/bin/env php
<?php

/**
 * CLI: Scrape (nullify) old ASP-era family passwords.
 *
 * Usage:
 *   php cli/scrape-old-passwords.php [--dry-run]
 *
 * This nullifies guest_password and admin_password on all families.
 * After running this, legacy family-password login is disabled.
 * Users must log in via username + password.
 *
 * Use --dry-run to see what would be affected without making changes.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Bootstrap
require_once __DIR__ . '/../src/Database.php';

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

$dryRun = in_array('--dry-run', $argv, true);

$db = new SeeOurFamily\Database();
$pdo = $db->pdo();

// Count affected families
$stmt = $pdo->query(
    "SELECT id, name, guest_password IS NOT NULL AS has_guest, admin_password IS NOT NULL AS has_admin
     FROM families
     WHERE guest_password IS NOT NULL OR admin_password IS NOT NULL"
);
$affected = $stmt->fetchAll();

if (empty($affected)) {
    echo "No families with legacy passwords found. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($affected) . " family/families with legacy passwords:\n\n";

foreach ($affected as $f) {
    $types = [];
    if ($f['has_guest']) $types[] = 'guest';
    if ($f['has_admin']) $types[] = 'admin';
    echo "  [{$f['id']}] {$f['name']} — " . implode(', ', $types) . " password(s)\n";
}

echo "\n";

if ($dryRun) {
    echo "[DRY RUN] No changes made.\n";
    exit(0);
}

// Scrape
$result = $pdo->exec(
    "UPDATE families SET guest_password = NULL, admin_password = NULL
     WHERE guest_password IS NOT NULL OR admin_password IS NOT NULL"
);

echo "Scrubbed legacy passwords from {$result} family/families.\n";
echo "Legacy family-password login is now disabled.\n";
echo "Done.\n";
