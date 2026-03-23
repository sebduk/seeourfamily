<?php

/**
 * Admin: Messages / Forums CRUD.
 *
 * Replaces Prog/Admin/messIndex.asp + messList.asp + messPage.asp.
 * Manages message boards (forums) and their items.
 * Platinum package only.
 */

if (!$isAdmin) { echo '<p>Admin access required.</p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$msg = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $todo = $_POST['todo'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $val  = fn(string $k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;

    if ($todo === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM forum_items WHERE forum_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM forums WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Forum deleted.';
        $id = 0;
    } elseif ($todo === 'toggle' && $id > 0) {
        // Toggle a forum_item online/offline
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $pdo->prepare(
                'UPDATE forum_items SET is_online = IF(is_online = 1, 0, 1) WHERE id = ? AND forum_id = ?'
            )->execute([$itemId, $id]);
            $msg = 'Message visibility toggled.';
        }
    } elseif ($todo === 'purge' && $id > 0) {
        // Remove offline messages
        $pdo->prepare(
            'DELETE fi FROM forum_items fi
             JOIN forums f ON fi.forum_id = f.id
             WHERE fi.forum_id = ? AND f.family_id = ? AND fi.is_online = 0'
        )->execute([$id, $fid]);
        $msg = 'Offline messages purged.';
    } elseif ($todo === 'add' || $todo === 'update') {
        $fields = [
            'admin_name' => $val('admin_name'),
            'title'      => $val('title'),
            'sort_order'  => $val('sort_order') ? (int)$val('sort_order') : 0,
            'is_online'  => isset($_POST['is_online']) ? 1 : 0,
        ];
        if ($todo === 'add') {
            $fields['family_id'] = $fid;
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO forums ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
            $msg = 'Forum added.';
        } else {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE forums SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                ->execute([...array_values($fields), $id, $fid]);
            $msg = 'Forum updated.';
        }
    }
}

$editUuid = $router->param('id') ?? $_GET['id'] ?? '';
if ($editUuid !== '' && !ctype_digit($editUuid)) {
    $stmt = $pdo->prepare('SELECT id FROM forums WHERE uuid = ? AND family_id = ?');
    $stmt->execute([$editUuid, $fid]);
    $resolved = $stmt->fetch();
    $editId = $resolved ? (int)$resolved['id'] : 0;
} else {
    $editId = (int)$editUuid;
}

// List forums
$stmt = $pdo->prepare('SELECT id, uuid, title, admin_name, is_online FROM forums WHERE family_id = ? ORDER BY sort_order, title');
$stmt->execute([$fid]);
$forumsList = $stmt->fetchAll();

// Edit record + items
$forum = null;
$forumItems = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM forums WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $forum = $stmt->fetch();

    if ($forum) {
        $stmt = $pdo->prepare(
            'SELECT id, title, author_name, author_email, posted_at, is_online
             FROM forum_items WHERE forum_id = ? ORDER BY posted_at DESC'
        );
        $stmt->execute([$editId]);
        $forumItems = $stmt->fetchAll();
    }
}
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Message Boards</div>
        <div class="sidebar-links"><a href="/admin/messages">Add a Board</a></div>
        <hr>
        <?php foreach ($forumsList as $f): ?>
            <a href="/admin/messages?id=<?= $f['uuid'] ?>" <?= !$f['is_online'] ? 'style="font-style:italic"' : '' ?>><?= h($f['title'] ?? '(untitled)') ?></a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <form method="post" action="/admin/messages" class="admin-form">
            <input type="hidden" name="id" value="<?= $forum ? $forum['id'] : '' ?>">
            <input type="hidden" name="todo" value="<?= $forum ? 'update' : 'add' ?>">

            <div class="form-row"><label>Name</label><input type="text" name="admin_name" size="30" value="<?= h($forum['admin_name'] ?? '') ?>"></div>
            <div class="form-row"><label>Title</label><input type="text" name="title" size="40" value="<?= h($forum['title'] ?? '') ?>"></div>
            <div class="form-row"><label>Sort Order</label><input type="number" name="sort_order" size="3" value="<?= h((string)($forum['sort_order'] ?? '')) ?>"></div>
            <div class="form-row"><label>Online</label><input type="checkbox" name="is_online" value="1"<?= (!$forum || ($forum['is_online'] ?? 1)) ? ' checked' : '' ?>></div>

            <div class="form-actions">
                <input type="submit" value="<?= $forum ? 'Update' : 'Add' ?>">
                <?php if ($forum): ?><input type="submit" name="todo" value="delete" onclick="return confirm('Delete this forum and all its messages?')"><?php endif; ?>
            </div>
        </form>

        <?php if ($forum && $forumItems): ?>
        <hr>
        <h4>Messages (<?= count($forumItems) ?>)</h4>
        <table class="data-table" style="max-width:100%">
            <tr><th>Title</th><th>From</th><th>Date</th><th>Status</th><th>Action</th></tr>
            <?php foreach ($forumItems as $item): ?>
            <tr<?= !$item['is_online'] ? ' style="font-style:italic;color:#999"' : '' ?>>
                <td><?= h($item['title'] ?? '') ?></td>
                <td><?= h($item['author_name'] ?? '') ?></td>
                <td><?= h($item['posted_at'] ?? '') ?></td>
                <td><?= $item['is_online'] ? 'Online' : 'Offline' ?></td>
                <td>
                    <form method="post" action="/admin/messages" style="display:inline">
                        <input type="hidden" name="id" value="<?= $forum['id'] ?>">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <input type="hidden" name="todo" value="toggle">
                        <input type="submit" value="<?= $item['is_online'] ? 'Hide' : 'Show' ?>">
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <form method="post" action="/admin/messages" style="margin-top:8px">
            <input type="hidden" name="id" value="<?= $forum['id'] ?>">
            <input type="hidden" name="todo" value="purge">
            <input type="submit" value="Purge offline messages" onclick="return confirm('Remove all offline messages from this board?')">
        </form>
        <?php endif; ?>
    </div>
</div>
