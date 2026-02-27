<?php

/**
 * System Admin dashboard.
 *
 * Only accessible to superadmins. Provides cross-family management:
 *   - Family management (create, list, configure)
 *   - User management (list, promote, deactivate)
 *   - Invitation management (issue invitations to any family)
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn, $isAdmin
 */

if (!$auth->isSuperAdmin()) {
    echo '<p>System admin access required. <a href="/login">Login</a></p>';
    return;
}

// Quick stats
$familyCount = $db->pdo()->query("SELECT COUNT(*) FROM families WHERE is_online = 1")->fetchColumn();
$userCount   = $db->pdo()->query("SELECT COUNT(*) FROM users WHERE is_online = 1")->fetchColumn();
$pendingInv  = $db->pdo()->query("SELECT COUNT(*) FROM invitations WHERE used_at IS NULL AND expires_at > NOW()")->fetchColumn();
$blogCount   = $db->pdo()->query("SELECT COUNT(*) FROM blog_posts WHERE family_id IS NULL AND is_online = 1")->fetchColumn();
?>

<div class="page-wrap">
    <h2>System Administration</h2>
    <p style="color: #666;">Superadmin panel &mdash; cross-family management.</p>

    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0;">
        <a href="/system-admin/families" style="text-decoration:none; color:inherit;">
            <div style="border:1px solid #ccc; padding:20px; min-width:180px; text-align:center; background:#f9f9f9;">
                <div style="font-size:28pt; font-weight:bold;"><?= (int)$familyCount ?></div>
                <div>Families</div>
            </div>
        </a>
        <a href="/system-admin/users" style="text-decoration:none; color:inherit;">
            <div style="border:1px solid #ccc; padding:20px; min-width:180px; text-align:center; background:#f9f9f9;">
                <div style="font-size:28pt; font-weight:bold;"><?= (int)$userCount ?></div>
                <div>Users</div>
            </div>
        </a>
        <a href="/system-admin/invitations" style="text-decoration:none; color:inherit;">
            <div style="border:1px solid #ccc; padding:20px; min-width:180px; text-align:center; background:#f9f9f9;">
                <div style="font-size:28pt; font-weight:bold;"><?= (int)$pendingInv ?></div>
                <div>Pending Invitations</div>
            </div>
        </a>
        <a href="/system-admin/blog" style="text-decoration:none; color:inherit;">
            <div style="border:1px solid #ccc; padding:20px; min-width:180px; text-align:center; background:#f9f9f9;">
                <div style="font-size:28pt; font-weight:bold;"><?= (int)$blogCount ?></div>
                <div>Blog Posts</div>
            </div>
        </a>
    </div>

    <h3>Quick Actions</h3>
    <ul>
        <li><a href="/system-admin/families?action=new">Create new family</a></li>
        <li><a href="/system-admin/invitations?action=new">Send invitation</a></li>
        <li><a href="/system-admin/users">Manage users</a></li>
        <li><a href="/system-admin/blog?action=new">Write blog post</a></li>
    </ul>
</div>
