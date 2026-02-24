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

    /** Set the active family by ID (used after login when user picks a family). */
    public function setFamilyById(int $familyId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM families WHERE id = ? AND is_online = 1'
        );
        $stmt->execute([$familyId]);
        $row = $stmt->fetch();
        if ($row) {
            $_SESSION['family_id'] = (int)$row['id'];
            // If user is logged in, resolve their role for this family
            $userId = $this->userId();
            if ($userId !== null) {
                $role = $this->resolveUserRole($userId, (int)$row['id']);
                if ($role !== null) {
                    $_SESSION['role'] = $role;
                }
            }
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
     * Login with username and password.
     *
     * Checks the users table for a matching login + hashed password.
     * On success, stores user_id in session and returns an array of
     * families the user has access to (with their roles).
     *
     * @return array|null Array of families on success, null on failure.
     *   Each entry: ['family_id' => int, 'role' => string, 'name' => string, 'title' => string]
     */
    public function loginUser(string $login, string $password): ?array
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, password FROM users WHERE login = ? AND is_online = 1'
        );
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }

        // User authenticated — store in session
        $_SESSION['user_id'] = (int)$user['id'];

        // Get all families this user can access
        return $this->userFamilies((int)$user['id']);
    }

    /**
     * Legacy login: authenticate with just a password against the active family.
     *
     * The old ASP site had two password tiers stored on the family record:
     *   - guest_password  -> grants "Guest" role (view only)
     *   - admin_password  -> grants "Admin" role (edit)
     *
     * Returns the role string on success, or null on failure.
     */
    public function loginFamilyPassword(string $password): ?string
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

        return null;
    }

    /**
     * Get all families a user has access to (with roles).
     *
     * @return array Each entry: ['family_id', 'role', 'name', 'title']
     */
    public function userFamilies(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT f.id AS family_id, ufl.role, f.name, f.title
             FROM user_family_link ufl
             JOIN families f ON f.id = ufl.family_id
             WHERE ufl.user_id = ? AND ufl.is_online = 1 AND f.is_online = 1
             ORDER BY f.name'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Look up the role a user has for a specific family. */
    private function resolveUserRole(int $userId, int $familyId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT role FROM user_family_link
             WHERE user_id = ? AND family_id = ? AND is_online = 1'
        );
        $stmt->execute([$userId, $familyId]);
        $row = $stmt->fetch();
        return $row ? $row['role'] : null;
    }

    public function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['family_id']);
    }

    public function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    /** True if the user has an active role (authenticated via user login or family password). */
    public function isLoggedIn(): bool
    {
        return $this->role() !== null;
    }

    /** True if role is Admin or Owner. */
    public function isAdmin(): bool
    {
        $role = $this->role();
        return $role === 'Admin' || $role === 'Owner';
    }

    /** Get the logged-in user's display name. */
    public function userName(): ?string
    {
        $uid = $this->userId();
        if ($uid === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT name, login FROM users WHERE id = ? AND is_online = 1'
        );
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row['name'] ?: $row['login'];
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
