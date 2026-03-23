#!/usr/bin/env php
<?php

/**
 * CLI: Create a superadmin user account.
 *
 * Usage:
 *   php cli/create-superadmin.php <login> <password> [name] [email]
 *
 * Examples:
 *   php cli/create-superadmin.php admin MySecurePass123
 *   php cli/create-superadmin.php admin MySecurePass123 "Site Owner" owner@example.com
 *
 * If the login already exists, the user is promoted to superadmin and
 * their password is updated.
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

// Parse args
$login    = $argv[1] ?? '';
$password = $argv[2] ?? '';
$name     = $argv[3] ?? '';
$email    = $argv[4] ?? '';

if ($login === '' || $password === '') {
    fwrite(STDERR, "Usage: php cli/create-superadmin.php <login> <password> [name] [email]\n");
    exit(1);
}

if (strlen($login) < 4) {
    fwrite(STDERR, "Error: Login must be at least 4 characters.\n");
    exit(1);
}

if (strlen($password) < 6) {
    fwrite(STDERR, "Error: Password must be at least 6 characters.\n");
    exit(1);
}

$db = new SeeOurFamily\Database();
$pdo = $db->pdo();

$hash = password_hash($password, PASSWORD_DEFAULT);

// Check if user exists
$stmt = $pdo->prepare('SELECT id, is_superadmin FROM users WHERE login = ?');
$stmt->execute([$login]);
$existing = $stmt->fetch();

if ($existing) {
    // Update existing user
    $pdo->prepare(
        'UPDATE users SET password = ?, is_superadmin = 1, is_online = 1 WHERE id = ?'
    )->execute([$hash, $existing['id']]);

    echo "User '{$login}' (ID {$existing['id']}) updated and promoted to superadmin.\n";
} else {
    // Create new user
    $uuid = sprintf('%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );

    $stmt = $pdo->prepare(
        'INSERT INTO users (uuid, login, password, name, email, is_superadmin, is_online, created_at)
         VALUES (?, ?, ?, ?, ?, 1, 1, NOW())'
    );
    $stmt->execute([$uuid, $login, $hash, $name ?: null, $email ?: null]);

    $newId = $pdo->lastInsertId();
    echo "Superadmin user '{$login}' created (ID {$newId}).\n";
}

echo "Done.\n";
