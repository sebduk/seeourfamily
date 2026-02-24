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
 *   family_id    – active family (int)
 *   user_id      – logged-in user (int)
 *   role         – role within current family (Owner|Admin|Guest)
 *   is_superadmin – system-wide superadmin flag (bool)
 *   language     – UI language override (ENG|FRA|ESP|...)
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
            // Superadmins get Owner role on any family
            if ($this->isSuperAdmin()) {
                $_SESSION['role'] = 'Owner';
                return true;
            }
            // Regular users: resolve their role for this family
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
            'SELECT id, password, is_superadmin FROM users WHERE login = ? AND is_online = 1'
        );
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }

        // User authenticated — store in session
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['is_superadmin'] = (bool)$user['is_superadmin'];

        // Superadmins can access all families
        if ($user['is_superadmin']) {
            return $this->allFamilies();
        }

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

    /** Get all families in the system (for superadmins). */
    public function allFamilies(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT id AS family_id, 'Owner' AS role, name, title
             FROM families WHERE is_online = 1 ORDER BY name"
        );
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
        unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['family_id'], $_SESSION['is_superadmin']);
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
        return $this->role() !== null || $this->isSuperAdmin();
    }

    /** True if role is Admin or Owner, or if superadmin. */
    public function isAdmin(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        $role = $this->role();
        return $role === 'Admin' || $role === 'Owner';
    }

    /** True if the user has the system-wide superadmin flag. */
    public function isSuperAdmin(): bool
    {
        return !empty($_SESSION['is_superadmin']);
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
    // Password reset
    // -----------------------------------------------------------------

    /**
     * Create a password reset token for a user identified by email.
     *
     * @return array|null ['token' => string, 'user' => array] on success, null if email not found.
     */
    public function createPasswordReset(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, login, name, email FROM users WHERE email = ? AND is_online = 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        // Invalidate any existing unused tokens for this user
        $this->db->pdo()->prepare(
            'UPDATE password_resets SET expires_at = NOW() WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()'
        )->execute([$user['id']]);

        // Generate new token (64 hex chars)
        $token = bin2hex(random_bytes(32));

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO password_resets (user_id, token, expires_at, created_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())'
        );
        $stmt->execute([$user['id'], $token]);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * Validate a password reset token.
     *
     * @return array|null The user row if token is valid, null otherwise.
     */
    public function validateResetToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pr.user_id, u.login, u.name, u.email
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.is_online = 1'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Reset a user's password using a valid token.
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $user = $this->validateResetToken($token);
        if (!$user) {
            return false;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $this->db->pdo()->prepare(
            'UPDATE users SET password = ? WHERE id = ?'
        )->execute([$hash, $user['user_id']]);

        // Mark token as used
        $this->db->pdo()->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE token = ?'
        )->execute([$token]);

        return true;
    }

    // -----------------------------------------------------------------
    // Invitations
    // -----------------------------------------------------------------

    /**
     * Validate an invitation token.
     *
     * @return array|null Invitation row (with family_name) if valid, null otherwise.
     */
    public function validateInvitation(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.*, f.name AS family_name, f.title AS family_title
             FROM invitations i
             JOIN families f ON f.id = i.family_id
             WHERE i.token = ? AND i.used_at IS NULL AND i.expires_at > NOW() AND f.is_online = 1'
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Accept an invitation: create a user account and link to the family.
     *
     * @return int|null The new user ID on success, null on failure.
     */
    public function acceptInvitation(string $token, string $login, string $password, string $name, string $email): ?int
    {
        $invitation = $this->validateInvitation($token);
        if (!$invitation) {
            return null;
        }

        $login = trim($login);
        $name = trim($name);
        $email = trim($email);

        if ($login === '' || strlen($login) < 4 || $password === '' || strlen($password) < 6) {
            return null;
        }

        // Check login uniqueness
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE login = ?');
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            return null; // Login already taken
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            // Create user
            $uuid = sprintf('%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            );
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO users (uuid, login, password, name, email, is_online, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())'
            );
            $stmt->execute([$uuid, $login, $hash, $name ?: null, $email ?: $invitation['email']]);
            $userId = (int)$pdo->lastInsertId();

            // Link user to family with the invitation's role
            $stmt = $pdo->prepare(
                'INSERT INTO user_family_link (user_id, family_id, role, is_online, created_at)
                 VALUES (?, ?, ?, 1, NOW())'
            );
            $stmt->execute([$userId, $invitation['family_id'], $invitation['role']]);

            // Mark invitation as used
            $pdo->prepare(
                'UPDATE invitations SET used_at = NOW() WHERE id = ?'
            )->execute([$invitation['id']]);

            $pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return null;
        }
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
