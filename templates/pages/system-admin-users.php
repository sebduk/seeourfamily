<?php

/**
 * System Admin: User management.
 *
 * List users, change roles, add/remove family access, promote to admin/superadmin.
 *
 * Available from index.php: $db, $auth, $router, $L, $isLoggedIn
 */

if (!$auth->isSuperAdmin()) {
    echo '<p>System admin access required. <a href="/login">Login</a></p>';
    return;
}

$pdo = $db->pdo();
$message = '';

// ---- Handle POST actions ----

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['form_action'] ?? '';

    if ($postAction === 'toggle_superadmin') {
        $uid = (int)($_POST['user_id'] ?? 0);
        // Don't allow removing your own superadmin
        if ($uid === $auth->userId()) {
            $message = '<span style="color:#c00;">Cannot change your own superadmin status.</span>';
        } else {
            $pdo->prepare('UPDATE users SET is_superadmin = NOT is_superadmin WHERE id = ?')->execute([$uid]);
            $message = '<span style="color:#060;">Superadmin toggled for user #' . $uid . '.</span>';
        }
    }

    if ($postAction === 'change_role') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $fid = (int)($_POST['family_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? '';
        if (in_array($newRole, ['Owner', 'Admin', 'Guest'], true)) {
            $stmt = $pdo->prepare(
                'UPDATE user_family_link SET role = ? WHERE user_id = ? AND family_id = ?'
            );
            $stmt->execute([$newRole, $uid, $fid]);
            $message = '<span style="color:#060;">Role updated to ' . h($newRole) . '.</span>';
        }
    }

    if ($postAction === 'add_family_access') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $fid = (int)($_POST['family_id'] ?? 0);
        $role = $_POST['role'] ?? 'Guest';
        if ($uid && $fid && in_array($role, ['Owner', 'Admin', 'Guest'], true)) {
            $stmt = $pdo->prepare(
                'INSERT INTO user_family_link (user_id, family_id, role, is_online)
                 VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), is_online = 1'
            );
            $stmt->execute([$uid, $fid, $role]);
            $message = '<span style="color:#060;">Access granted.</span>';
        }
    }

    if ($postAction === 'remove_family_access') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $fid = (int)($_POST['family_id'] ?? 0);
        $pdo->prepare(
            'UPDATE user_family_link SET is_online = 0 WHERE user_id = ? AND family_id = ?'
        )->execute([$uid, $fid]);
        $message = '<span style="color:#060;">Access removed.</span>';
    }

    if ($postAction === 'toggle_user_online') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $auth->userId()) {
            $message = '<span style="color:#c00;">Cannot deactivate your own account.</span>';
        } else {
            $pdo->prepare('UPDATE users SET is_online = NOT is_online WHERE id = ?')->execute([$uid]);
            $message = '<span style="color:#060;">User #' . $uid . ' toggled.</span>';
        }
    }

    if ($postAction === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $newPass = $_POST['new_password'] ?? '';
        if ($uid && strlen($newPass) >= 6) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $uid]);
            $message = '<span style="color:#060;">Password reset for user #' . $uid . '.</span>';
        } else {
            $message = '<span style="color:#c00;">Password must be at least 6 characters.</span>';
        }
    }
}

// ---- Fetch data ----

$editUserId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

$users = $pdo->query(
    'SELECT u.*, COUNT(ufl.family_id) AS family_count
     FROM users u
     LEFT JOIN user_family_link ufl ON ufl.user_id = u.id AND ufl.is_online = 1
     GROUP BY u.id
     ORDER BY u.login'
)->fetchAll();

$allFamilies = $pdo->query('SELECT id, name FROM families WHERE is_online = 1 ORDER BY name')->fetchAll();

?>

<div class="page-wrap">
    <h2><a href="/system-admin" style="color:inherit;">System Admin</a> &gt; Users</h2>

    <?php if ($message): ?>
        <p><?= $message ?></p>
    <?php endif; ?>

    <!-- User list -->
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">
        <thead style="background:#eee;">
            <tr>
                <th>ID</th>
                <th>Login</th>
                <th>Name</th>
                <th>Email</th>
                <th>Families</th>
                <th>Super</th>
                <th>Online</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr<?= !$u['is_online'] ? ' style="opacity:0.5;"' : '' ?>>
                <td><?= (int)$u['id'] ?></td>
                <td><?= h($u['login']) ?></td>
                <td><?= h($u['name'] ?? '') ?></td>
                <td><?= h($u['email'] ?? '') ?></td>
                <td><?= (int)$u['family_count'] ?></td>
                <td><?= $u['is_superadmin'] ? '<b>YES</b>' : 'no' ?></td>
                <td><?= $u['is_online'] ? 'Yes' : 'No' ?></td>
                <td>
                    <a href="/system-admin/users?edit=<?= (int)$u['id'] ?>">Edit</a>
                    |
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="form_action" value="toggle_superadmin">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" style="border:none; background:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                            <?= $u['is_superadmin'] ? 'Revoke Super' : 'Make Super' ?>
                        </button>
                    </form>
                    |
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="form_action" value="toggle_user_online">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" style="border:none; background:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                            <?= $u['is_online'] ? 'Deactivate' : 'Reactivate' ?>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($editUserId):
        $editUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $editUser->execute([$editUserId]);
        $editUser = $editUser->fetch();
        if ($editUser):
            $userLinks = $pdo->prepare(
                'SELECT ufl.*, f.name AS family_name
                 FROM user_family_link ufl
                 JOIN families f ON f.id = ufl.family_id
                 WHERE ufl.user_id = ? AND ufl.is_online = 1
                 ORDER BY f.name'
            );
            $userLinks->execute([$editUserId]);
            $userLinks = $userLinks->fetchAll();
    ?>

    <!-- Edit user detail -->
    <div style="border:1px solid #ccc; padding:16px; margin:16px 0; background:#f9f9f9;">
        <h3>Editing: <?= h($editUser['login']) ?> (<?= h($editUser['name'] ?? 'no name') ?>)</h3>

        <h4>Reset Password</h4>
        <form method="post" style="margin-bottom:16px;">
            <input type="hidden" name="form_action" value="reset_password">
            <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>">
            <input type="password" name="new_password" placeholder="New password (min 6 chars)" class="box" required minlength="6">
            <button type="submit" class="box">Reset Password</button>
        </form>

        <h4>Family Access</h4>
        <?php if ($userLinks): ?>
        <table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse; margin-bottom:12px;">
            <tr style="background:#eee;"><th>Family</th><th>Role</th><th>Actions</th></tr>
            <?php foreach ($userLinks as $link): ?>
            <tr>
                <td><?= h($link['family_name']) ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="form_action" value="change_role">
                        <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>">
                        <input type="hidden" name="family_id" value="<?= (int)$link['family_id'] ?>">
                        <select name="new_role" class="box" onchange="this.form.submit()">
                            <option value="Guest"<?= $link['role'] === 'Guest' ? ' selected' : '' ?>>Guest</option>
                            <option value="Admin"<?= $link['role'] === 'Admin' ? ' selected' : '' ?>>Admin</option>
                            <option value="Owner"<?= $link['role'] === 'Owner' ? ' selected' : '' ?>>Owner</option>
                        </select>
                    </form>
                </td>
                <td>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="form_action" value="remove_family_access">
                        <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>">
                        <input type="hidden" name="family_id" value="<?= (int)$link['family_id'] ?>">
                        <button type="submit" style="border:none; background:none; color:#c00; cursor:pointer; text-decoration:underline; font-size:inherit;">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p><em>No family access.</em></p>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="form_action" value="add_family_access">
            <input type="hidden" name="user_id" value="<?= (int)$editUserId ?>">
            <select name="family_id" class="box">
                <?php foreach ($allFamilies as $af): ?>
                    <option value="<?= (int)$af['id'] ?>"><?= h($af['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="role" class="box">
                <option value="Guest">Guest</option>
                <option value="Admin">Admin</option>
                <option value="Owner">Owner</option>
            </select>
            <button type="submit" class="box">Add Access</button>
        </form>
    </div>

    <?php endif; endif; ?>
</div>
