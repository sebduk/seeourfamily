<?php

/**
 * System Admin: Invitation management.
 *
 * Issue invitations to any family, view pending/used invitations.
 *
 * Available from index.php: $db, $auth, $router, $L, $isLoggedIn
 */

if (!$auth->isSuperAdmin()) {
    echo '<p>System admin access required. <a href="/login">Login</a></p>';
    return;
}

$pdo = $db->pdo();
$message = '';
$action = $_GET['action'] ?? '';

// ---- Handle POST actions ----

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['form_action'] ?? '';

    if ($postAction === 'create_invitation') {
        $fid   = (int)($_POST['family_id'] ?? 0);
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? 'Guest';
        $days  = max(1, min(90, (int)($_POST['days'] ?? 7)));

        if (!$fid || $email === '') {
            $message = '<span style="color:#c00;">Family and email are required.</span>';
        } elseif (!in_array($role, ['Owner', 'Admin', 'Guest'], true)) {
            $message = '<span style="color:#c00;">Invalid role.</span>';
        } else {
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare(
                'INSERT INTO invitations (family_id, token, email, role, expires_at, created_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), NOW())'
            );
            $stmt->execute([$fid, $token, $email, $role, $days]);

            // Build the invitation URL
            $baseUrl = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $invUrl = $baseUrl . '/register?invite=' . $token;

            $message = '<span style="color:#060;">Invitation created for ' . h($email) . '.</span><br>'
                     . '<b>Invitation link:</b> <code style="word-break:break-all;">' . h($invUrl) . '</code><br>'
                     . '<small>Expires in ' . $days . ' day(s).</small>';
        }
    }

    if ($postAction === 'revoke_invitation') {
        $invId = (int)($_POST['invitation_id'] ?? 0);
        $pdo->prepare(
            'UPDATE invitations SET expires_at = NOW() WHERE id = ? AND used_at IS NULL'
        )->execute([$invId]);
        $message = '<span style="color:#060;">Invitation #' . $invId . ' revoked.</span>';
    }
}

// ---- Fetch data ----

$allFamilies = $pdo->query('SELECT id, name FROM families WHERE is_online = 1 ORDER BY name')->fetchAll();

$invitations = $pdo->query(
    'SELECT i.*, f.name AS family_name
     FROM invitations i
     JOIN families f ON f.id = i.family_id
     ORDER BY i.created_at DESC
     LIMIT 100'
)->fetchAll();

?>

<div class="page-wrap">
    <h2><a href="/system-admin" style="color:inherit;">System Admin</a> &gt; Invitations</h2>

    <?php if ($message): ?>
        <p><?= $message ?></p>
    <?php endif; ?>

    <?php if ($action === 'new'): ?>
    <!-- Send invitation form -->
    <div style="border:1px solid #ccc; padding:16px; margin:16px 0; background:#f9f9f9;">
        <h3>Send New Invitation</h3>
        <form method="post">
            <input type="hidden" name="form_action" value="create_invitation">
            <div style="margin-bottom:8px;">
                <label>Family:
                    <select name="family_id" class="box" required>
                        <option value="">-- select --</option>
                        <?php foreach ($allFamilies as $af): ?>
                            <option value="<?= (int)$af['id'] ?>"><?= h($af['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Email: <input type="email" name="email" class="box" required></label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Role:
                    <select name="role" class="box">
                        <option value="Guest">Guest</option>
                        <option value="Admin">Admin</option>
                        <option value="Owner">Owner</option>
                    </select>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Expires in (days): <input type="number" name="days" value="7" min="1" max="90" class="box" style="width:60px;"></label>
            </div>
            <button type="submit" class="box">Create Invitation</button>
            <a href="/system-admin/invitations" style="margin-left:12px;">Cancel</a>
        </form>
    </div>
    <?php endif; ?>

    <!-- Invitation list -->
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">
        <thead style="background:#eee;">
            <tr>
                <th>ID</th>
                <th>Family</th>
                <th>Email</th>
                <th>Role</th>
                <th>Expires</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invitations as $inv):
                $isUsed = $inv['used_at'] !== null;
                $isExpired = !$isUsed && strtotime($inv['expires_at']) < time();
                $isPending = !$isUsed && !$isExpired;
            ?>
            <tr style="<?= $isUsed ? 'opacity:0.5;' : ($isExpired ? 'opacity:0.4;' : '') ?>">
                <td><?= (int)$inv['id'] ?></td>
                <td><?= h($inv['family_name']) ?></td>
                <td><?= h($inv['email']) ?></td>
                <td><?= h($inv['role']) ?></td>
                <td><?= h($inv['expires_at']) ?></td>
                <td>
                    <?php if ($isUsed): ?>
                        <span style="color:#060;">Used <?= h($inv['used_at']) ?></span>
                    <?php elseif ($isExpired): ?>
                        <span style="color:#999;">Expired</span>
                    <?php else: ?>
                        <span style="color:#c60;">Pending</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($isPending): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="form_action" value="revoke_invitation">
                            <input type="hidden" name="invitation_id" value="<?= (int)$inv['id'] ?>">
                            <button type="submit" style="border:none; background:none; color:#c00; cursor:pointer; text-decoration:underline; font-size:inherit;">Revoke</button>
                        </form>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:12px;"><a href="/system-admin/invitations?action=new">+ Send new invitation</a></p>
</div>
