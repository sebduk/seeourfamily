<?php

/**
 * Admin: Couples CRUD.
 *
 * Replaces Prog/Admin/coupIndex.asp + coupList.asp + coupPage.asp.
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
        $pdo->prepare('DELETE FROM couples WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Couple deleted.';
        $id = 0;
    } elseif ($todo === 'add' || $todo === 'update') {
        $fields = [
            'person1_id'      => $val('person1_id') ? (int)$val('person1_id') : null,
            'person2_id'      => $val('person2_id') ? (int)$val('person2_id') : null,
            'start_date'      => $val('start_date'),
            'start_precision' => $val('start_precision'),
            'start_place'     => $val('start_place'),
            'end_date'        => $val('end_date'),
            'end_precision'   => $val('end_precision'),
            'end_place'       => $val('end_place'),
            'comment'         => $val('comment'),
        ];
        if ($todo === 'add') {
            $fields['family_id'] = $fid;
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO couples ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
            $msg = 'Couple added.';
        } else {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE couples SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                ->execute([...array_values($fields), $id, $fid]);
            $msg = 'Couple updated.';
        }
    }
}

$editId = (int)($id ?? $_GET['id'] ?? 0);

// List
$stmt = $pdo->prepare(
    'SELECT c.id, p1.last_name AS ln1, p1.first_name AS fn1, p2.last_name AS ln2, p2.first_name AS fn2
     FROM couples c
     JOIN people p1 ON c.person1_id = p1.id
     JOIN people p2 ON c.person2_id = p2.id
     WHERE c.family_id = ?
     ORDER BY p1.last_name, p1.first_name'
);
$stmt->execute([$fid]);
$couplesList = $stmt->fetchAll();

// Edit record
$couple = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM couples WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $couple = $stmt->fetch();
}

// People dropdowns (men/women)
$menStmt = $pdo->prepare('SELECT id, first_name, last_name FROM people WHERE family_id = ? AND is_male = 1 ORDER BY last_name, first_name');
$menStmt->execute([$fid]);
$men = $menStmt->fetchAll();

$womenStmt = $pdo->prepare('SELECT id, first_name, last_name FROM people WHERE family_id = ? AND is_male = 0 ORDER BY last_name, first_name');
$womenStmt->execute([$fid]);
$women = $womenStmt->fetchAll();
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Couples</div>
        <div class="sidebar-links"><a href="/admin/couples">Add a Couple</a></div>
        <hr>
        <?php foreach ($couplesList as $c): ?>
            <a href="/admin/couples?id=<?= $c['id'] ?>"><?= h($c['fn1'] . ' ' . $c['ln1'] . ' & ' . $c['fn2'] . ' ' . $c['ln2']) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <form method="post" action="/admin/couples" class="admin-form">
            <input type="hidden" name="id" value="<?= $couple ? $couple['id'] : '' ?>">
            <input type="hidden" name="todo" value="<?= $couple ? 'update' : 'add' ?>">

            <div class="form-row"><label>Person 1 (M)</label><select name="person1_id"><option value="">--</option><?php foreach ($men as $m): ?><option value="<?= $m['id'] ?>"<?= ($couple && $couple['person1_id'] == $m['id']) ? ' selected' : '' ?>><?= h($m['last_name'] . ' ' . $m['first_name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-row"><label>Person 2 (F)</label><select name="person2_id"><option value="">--</option><?php foreach ($women as $w): ?><option value="<?= $w['id'] ?>"<?= ($couple && $couple['person2_id'] == $w['id']) ? ' selected' : '' ?>><?= h($w['last_name'] . ' ' . $w['first_name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-row"><label>Wedding Date</label><input type="date" name="start_date" value="<?= h($couple['start_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Precision</label><select name="start_precision"><option value="">-</option><option value="ymd"<?= ($couple['start_precision'] ?? '') === 'ymd' ? ' selected' : '' ?>>Day</option><option value="ym"<?= ($couple['start_precision'] ?? '') === 'ym' ? ' selected' : '' ?>>Month</option><option value="y"<?= ($couple['start_precision'] ?? '') === 'y' ? ' selected' : '' ?>>Year</option></select></div>
            <div class="form-row"><label>Wedding Place</label><input type="text" name="start_place" size="30" value="<?= h($couple['start_place'] ?? '') ?>"></div>
            <div class="form-row"><label>End Date</label><input type="date" name="end_date" value="<?= h($couple['end_date'] ?? '') ?>"></div>
            <div class="form-row"><label>End Precision</label><select name="end_precision"><option value="">-</option><option value="ymd"<?= ($couple['end_precision'] ?? '') === 'ymd' ? ' selected' : '' ?>>Day</option><option value="ym"<?= ($couple['end_precision'] ?? '') === 'ym' ? ' selected' : '' ?>>Month</option><option value="y"<?= ($couple['end_precision'] ?? '') === 'y' ? ' selected' : '' ?>>Year</option></select></div>
            <div class="form-row"><label>End Place</label><input type="text" name="end_place" size="30" value="<?= h($couple['end_place'] ?? '') ?>"></div>
            <div class="form-row"><label>Comment</label><textarea name="comment" cols="60" rows="3"><?= h($couple['comment'] ?? '') ?></textarea></div>
            <div class="form-actions">
                <input type="submit" value="<?= $couple ? 'Update' : 'Add' ?>">
                <?php if ($couple): ?><input type="submit" name="todo" value="delete" onclick="return confirm('Delete this couple?')"><?php endif; ?>
            </div>
        </form>
    </div>
</div>
