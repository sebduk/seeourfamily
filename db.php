<?php
/**
 * db.php - Database configuration and connection for See Our Family
 *
 * Provides a PDO connection to MariaDB and session-level family context.
 * Include this file at the top of every page.
 */

// =========================================================================
// CONFIGURATION - Edit these values for your environment
// =========================================================================

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'seeourfamily');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// =========================================================================
// DATABASE CONNECTION
// =========================================================================

function db_connect(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// =========================================================================
// SESSION & FAMILY CONTEXT
// =========================================================================

session_start();

/**
 * Get the current family_id from the session.
 * Returns null if no family is selected.
 */
function current_family_id(): ?int
{
    return $_SESSION['family_id'] ?? null;
}

/**
 * Get the current family row from the database.
 * Returns null if no family is selected or family not found.
 */
function current_family(): ?array
{
    $fid = current_family_id();
    if ($fid === null) {
        return null;
    }
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT * FROM families WHERE id = ? AND is_online = 1');
    $stmt->execute([$fid]);
    return $stmt->fetch() ?: null;
}

/**
 * Get the current logged-in user_id from the session.
 * Returns null if not logged in.
 */
function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get the current user's role for the active family.
 * Returns null if no user or family in session.
 */
function current_role(): ?string
{
    $uid = current_user_id();
    $fid = current_family_id();
    if ($uid === null || $fid === null) {
        return null;
    }
    $pdo = db_connect();
    $stmt = $pdo->prepare(
        'SELECT role FROM user_family_link WHERE user_id = ? AND family_id = ? AND is_online = 1'
    );
    $stmt->execute([$uid, $fid]);
    $row = $stmt->fetch();
    return $row ? $row['role'] : null;
}

/**
 * Simple HTML escaping helper.
 */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
