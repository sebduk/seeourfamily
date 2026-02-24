<?php

/**
 * Admin: Information CRUD.
 *
 * Replaces Prog/Admin/infoIndex.asp + infoList.asp + infoPage.asp.
 * Manages free-form information/greeting messages displayed on various pages.
 * Each info record has a "location" (page slot) and HTML content.
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
        $pdo->prepare('DELETE FROM infos WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Information deleted.';
        $id = 0;
    } elseif ($todo === 'add' || $todo === 'update') {
        $fields = [
            'location' => $val('location'),
            'content'  => $val('content'),
        ];
        if ($todo === 'add') {
            $fields['family_id'] = $fid;
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO infos ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
            $msg = 'Information added.';
        } else {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE infos SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                ->execute([...array_values($fields), $id, $fid]);
            $msg = 'Information updated.';
        }
    }
}

$editUuid = $router->param('id') ?? $_GET['id'] ?? '';
if ($editUuid !== '' && !ctype_digit($editUuid)) {
    $stmt = $pdo->prepare('SELECT id FROM infos WHERE uuid = ? AND family_id = ?');
    $stmt->execute([$editUuid, $fid]);
    $resolved = $stmt->fetch();
    $editId = $resolved ? (int)$resolved['id'] : 0;
} else {
    $editId = (int)$editUuid;
}

// List
$stmt = $pdo->prepare('SELECT id, uuid, location FROM infos WHERE family_id = ? ORDER BY location');
$stmt->execute([$fid]);
$infoList = $stmt->fetchAll();

// Edit record
$info = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM infos WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $info = $stmt->fetch();
}
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Information</div>
        <div class="sidebar-links"><a href="/admin/info">Add Information</a></div>
        <hr>
        <?php foreach ($infoList as $i): ?>
            <a href="/admin/info?id=<?= $i['uuid'] ?>"><?= h($i['location'] ?? '(no location)') ?></a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <form method="post" action="/admin/info" class="admin-form">
            <input type="hidden" name="id" value="<?= $info ? $info['id'] : '' ?>">
            <input type="hidden" name="todo" value="<?= $info ? 'update' : 'add' ?>">

            <div class="form-row"><label>Location</label><input type="text" name="location" size="30" value="<?= h($info['location'] ?? '') ?>"></div>
            <div class="form-row"><label>Content</label><textarea name="content" cols="80" rows="12"><?= h($info['content'] ?? '') ?></textarea></div>
            <p><small>The Location field identifies which page displays this content (e.g. "Intro").<br>
            The Content field can contain HTML tags.</small></p>

            <div class="form-actions">
                <input type="submit" value="<?= $info ? 'Update' : 'Add' ?>">
                <?php if ($info): ?><input type="submit" name="todo" value="delete" onclick="return confirm('Delete this information?')"><?php endif; ?>
            </div>
        </form>
    </div>
</div>
