<?php

declare(strict_types=1);

namespace SeeOurFamily;

use PDO;

/**
 * Thin PDO wrapper.
 *
 * Replaces:
 *   Include/DAOHeader.asp  (ADODB connection open)
 *   Include/DAOFooter.asp  (ADODB connection close)
 *   Include/CodeHeader.asp (strConn = "PROVIDER=Microsoft.Jet.OLEDB.4.0;...")
 *
 * Usage:
 *   $db = new Database();      // reads DB_* from $_ENV
 *   $pdo = $db->pdo();         // lazy-connects on first call
 */
class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private string $host = '',
        private int    $port = 3306,
        private string $name = '',
        private string $user = '',
        private string $pass = '',
        private string $charset = 'utf8mb4',
    ) {
        // If no explicit values were passed, read from environment
        if ($this->host === '') {
            $this->host    = $_ENV['DB_HOST']    ?? 'localhost';
            $this->port    = (int)($_ENV['DB_PORT'] ?? 3306);
            $this->name    = $_ENV['DB_NAME']    ?? 'seeourfamily';
            $this->user    = $_ENV['DB_USER']    ?? 'root';
            $this->pass    = $_ENV['DB_PASS']    ?? '';
            $this->charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';
        }
    }

    /** Get the PDO instance (lazy-connects). */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->host,
                $this->port,
                $this->name,
                $this->charset,
            );
            $this->pdo = new PDO($dsn, $this->user, $this->pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $this->pdo;
    }
}
