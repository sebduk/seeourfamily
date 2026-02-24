<?php

/**
 * System Admin: Family management.
 *
 * List all families, create new ones, toggle is_online.
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

    if ($postAction === 'create_family') {
        $name  = trim($_POST['name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $lang  = $_POST['language'] ?? 'ENG';
        $pkg   = $_POST['package'] ?? 'Starter';

        if ($name === '') {
            $message = '<span style="color:#c00;">Family name is required.</span>';
        } else {
            $uuid = sprintf('%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            );
            $hash = substr(bin2hex(random_bytes(12)), 0, 20);
            $stmt = $pdo->prepare(
                'INSERT INTO families (uuid, name, title, language, package, hash, is_online, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW())'
            );
            $stmt->execute([$uuid, $name, $title ?: $name, $lang, $pkg, $hash]);
            $message = '<span style="color:#060;">Family "' . h($name) . '" created (ID ' . $pdo->lastInsertId() . ').</span>';
        }
    }

    if ($postAction === 'toggle_online') {
        $fid = (int)($_POST['family_id'] ?? 0);
        $pdo->prepare('UPDATE families SET is_online = NOT is_online WHERE id = ?')->execute([$fid]);
        $message = '<span style="color:#060;">Family #' . $fid . ' toggled.</span>';
    }
}

// ---- Fetch families ----

$families = $pdo->query(
    'SELECT f.*, COUNT(ufl.user_id) AS user_count
     FROM families f
     LEFT JOIN user_family_link ufl ON ufl.family_id = f.id AND ufl.is_online = 1
     GROUP BY f.id
     ORDER BY f.name'
)->fetchAll();

?>

<div class="page-wrap">
    <h2><a href="/system-admin" style="color:inherit;">System Admin</a> &gt; Families</h2>

    <?php if ($message): ?>
        <p><?= $message ?></p>
    <?php endif; ?>

    <?php if ($action === 'new'): ?>
    <!-- Create new family form -->
    <div style="border:1px solid #ccc; padding:16px; margin:16px 0; background:#f9f9f9;">
        <h3>Create New Family</h3>
        <form method="post">
            <input type="hidden" name="form_action" value="create_family">
            <div style="margin-bottom:8px;">
                <label>Name (unique): <input type="text" name="name" class="box" required></label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Display title: <input type="text" name="title" class="box"></label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Language:
                    <select name="language" class="box">
                        <option value="ENG">English</option>
                        <option value="FRA">Fran&ccedil;ais</option>
                        <option value="ESP">Espa&ntilde;ol</option>
                        <option value="ITA">Italiano</option>
                        <option value="POR">Portugu&ecirc;s</option>
                        <option value="DEU">Deutsch</option>
                        <option value="NLD">Nederlands</option>
                    </select>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Package:
                    <select name="package" class="box">
                        <option value="Starter">Starter</option>
                        <option value="Premium">Premium</option>
                        <option value="Platinum">Platinum</option>
                    </select>
                </label>
            </div>
            <button type="submit" class="box">Create</button>
            <a href="/system-admin/families" style="margin-left:12px;">Cancel</a>
        </form>
    </div>
    <?php endif; ?>

    <!-- Family list -->
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">
        <thead style="background:#eee;">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Title</th>
                <th>Package</th>
                <th>Language</th>
                <th>Users</th>
                <th>Online</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($families as $f): ?>
            <tr<?= !$f['is_online'] ? ' style="opacity:0.5;"' : '' ?>>
                <td><?= (int)$f['id'] ?></td>
                <td><?= h($f['name']) ?></td>
                <td><?= h($f['title'] ?? '') ?></td>
                <td><?= h($f['package']) ?></td>
                <td><?= h($f['language']) ?></td>
                <td><?= (int)$f['user_count'] ?></td>
                <td><?= $f['is_online'] ? 'Yes' : 'No' ?></td>
                <td>
                    <a href="/home?DomKey=<?= h($f['hash'] ?? '') ?>">View</a>
                    |
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="form_action" value="toggle_online">
                        <input type="hidden" name="family_id" value="<?= (int)$f['id'] ?>">
                        <button type="submit" style="border:none; background:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                            <?= $f['is_online'] ? 'Deactivate' : 'Reactivate' ?>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:12px;"><a href="/system-admin/families?action=new">+ Create new family</a></p>
</div>
