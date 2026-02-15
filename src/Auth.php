<?php

declare(strict_types=1);

namespace SeeOurFamily;

use PDO;

/**
 * Session-based authentication.
 *
 * Replaces:
 *   Include/CodeHeader.asp  (Session("DomainPwdGuest"), Session("IsUser"), etc.)
 *   p.login.asp             (login form POST handling)
 *   Prog/Admin/login.asp    (admin login)
 *
 * Old ASP stored passwords in plaintext and compared with UCase().
 * New system uses password_hash() / password_verify().
 *
 * Session keys:
 *   family_id  – active family (int)
 *   user_id    – logged-in user (int)
 *   role       – role within current family (Owner|Admin|Guest)
 *   language   – UI language override (ENG|FRA|ESP|...)
 */
class Auth
{
    public function __construct(private Database $db)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // -----------------------------------------------------------------
    // Family context
    // -----------------------------------------------------------------

    /** Resolve a DomKey hash to a family and store in session. */
    public function setFamilyByHash(string $hash): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM families WHERE hash = ? AND is_online = 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if ($row) {
            $_SESSION['family_id'] = (int)$row['id'];
            return true;
        }
        return false;
    }

    public function familyId(): ?int
    {
        return isset($_SESSION['family_id']) ? (int)$_SESSION['family_id'] : null;
    }

    /** Get the full family row for the active family. */
    public function family(): ?array
    {
        $fid = $this->familyId();
        if ($fid === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM families WHERE id = ? AND is_online = 1'
        );
        $stmt->execute([$fid]);
        return $stmt->fetch() ?: null;
    }

    // -----------------------------------------------------------------
    // User login / logout
    // -----------------------------------------------------------------

    /**
     * Attempt login with a password.
     *
     * The old ASP site had two password tiers stored on the family record:
     *   - guest_password  -> grants "Guest" role (view only)
     *   - admin_password  -> grants "Admin" role (edit)
     *
     * After migration, these are hashed with password_hash().
     * We also support user-table logins for multi-family users.
     *
     * Returns the role string on success, or null on failure.
     */
    public function login(string $password): ?string
    {
        $family = $this->family();
        if ($family === null) {
            return null;
        }

        // Try admin password first
        if ($family['admin_password'] && password_verify($password, $family['admin_password'])) {
            $_SESSION['role'] = 'Admin';
            return 'Admin';
        }

        // Then guest password
        if ($family['guest_password'] && password_verify($password, $family['guest_password'])) {
            $_SESSION['role'] = 'Guest';
            return 'Guest';
        }

        // Try user-table login (login field = username, password field = hashed)
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.password, ufl.role
             FROM users u
             JOIN user_family_link ufl ON ufl.user_id = u.id
             WHERE u.login = ? AND ufl.family_id = ? AND u.is_online = 1 AND ufl.is_online = 1'
        );
        $stmt->execute([$password, $family['id']]);
        // Note: old system used a single password field; this checks if the
        // submitted value matches the login or verifies against hashed password
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = (int)$row['id'];
            $_SESSION['role'] = $row['role'];
            return $row['role'];
        }

        return null;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['role']);
    }

    public function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public function isLoggedIn(): bool
    {
        return $this->role() !== null;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role(), ['Admin', 'Owner'], true);
    }

    // -----------------------------------------------------------------
    // Language
    // -----------------------------------------------------------------

    public function setLanguage(string $lang): void
    {
        $_SESSION['language'] = $lang;
    }

    /** Active language: session override > family default > ENG. */
    public function language(): string
    {
        if (!empty($_SESSION['language'])) {
            return $_SESSION['language'];
        }
        $family = $this->family();
        return $family['language'] ?? 'ENG';
    }
}
