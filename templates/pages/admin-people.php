<?php

/**
 * Admin: People CRUD.
 *
 * Replaces Prog/Admin/persIndex.asp + persList.asp + persPage.asp.
 * Sidebar list + form on one page (replaces old frameset).
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn, $isAdmin
 */

if (!$isAdmin) { echo '<p>Admin access required.</p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$msg = '';

// =========================================================================
// HANDLE POST
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $todo = $_POST['todo'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    // Helper: nullable field
    $val = fn(string $k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;

    if ($todo === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM people WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Person deleted.';
        $id = 0;
    } elseif ($todo === 'add' || $todo === 'update') {
        $fields = [
            'first_name' => $val('first_name'),
            'first_names' => $val('first_names'),
            'last_name' => $val('last_name'),
            'is_male' => isset($_POST['is_male']) ? 1 : 0,
            'birth_date' => $val('birth_date'),
            'birth_precision' => $val('birth_precision'),
            'death_date' => $val('death_date'),
            'death_precision' => $val('death_precision'),
            'birth_place' => $val('birth_place'),
            'death_place' => $val('death_place'),
            'couple_id' => ($val('couple_id') && $val('couple_id') !== '0') ? (int)$val('couple_id') : null,
            'couple_sort' => $val('couple_sort'),
            'email' => $val('email'),
            'biography' => $val('biography'),
            'links' => $val('links') ? preg_replace('/[,; ]+/', "\n", $_POST['links']) : null,
        ];

        if ($todo === 'add') {
            $fields['family_id'] = $fid;
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $pdo->prepare("INSERT INTO people ($cols) VALUES ($placeholders)");
            $stmt->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
            $msg = 'Person added.';
        } elseif ($todo === 'update' && $id > 0) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE people SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?");
            $stmt->execute([...array_values($fields), $id, $fid]);
            $msg = 'Person updated.';
        }
    }
}

$editId = (int)($id ?? $_GET['id'] ?? 0);

// =========================================================================
// LOAD LIST
// =========================================================================

$sort = $_GET['sort'] ?? 'alpha';
$listSql = 'SELECT id, first_name, last_name,
                   IFNULL(YEAR(birth_date), "") AS birth,
                   IFNULL(YEAR(death_date), "") AS death
            FROM people WHERE family_id = ?';
switch ($sort) {
    case 'chrono':  $listSql .= ' ORDER BY birth_date, last_name, first_name'; break;
    case 'last':    $listSql .= ' ORDER BY updated_at DESC, id DESC'; break;
    case 'nopar':   $listSql .= ' AND couple_id IS NULL ORDER BY last_name, first_name'; break;
    case 'withpar': $listSql .= ' AND couple_id IS NOT NULL ORDER BY last_name, first_name'; break;
    default:        $listSql .= ' ORDER BY last_name, first_name, birth_date'; break;
}
$stmt = $pdo->prepare($listSql);
$stmt->execute([$fid]);
$people = $stmt->fetchAll();

// Load person for editing
$person = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM people WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $person = $stmt->fetch();
}

// Load couples for parent dropdown
$couples = $pdo->prepare(
    'SELECT c.id, p1.last_name AS ln1, p1.first_name AS fn1, p2.last_name AS ln2, p2.first_name AS fn2
     FROM couples c
     JOIN people p1 ON c.person1_id = p1.id
     JOIN people p2 ON c.person2_id = p2.id
     WHERE c.family_id = ?
     ORDER BY p1.last_name, p1.first_name'
);
$couples->execute([$fid]);
$coupleList = $couples->fetchAll();
?>

<!-- Admin nav -->
<?php require __DIR__ . '/../_admin-nav.php'; ?>

<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <!-- Sidebar: People list -->
    <div class="admin-sidebar">
        <div class="section-title">People</div>
        <div class="sidebar-links">
            <a href="/admin/people">Add a Person</a>
        </div>
        <a href="/admin/people?sort=alpha">Alphabetically</a>
        <a href="/admin/people?sort=chrono">Chronologically</a>
        <a href="/admin/people?sort=last">Last Updated</a>
        <a href="/admin/people?sort=withpar">With Parents</a>
        <a href="/admin/people?sort=nopar">W/o Parents</a>
        <hr>
        <?php foreach ($people as $p): ?>
            <a href="/admin/people?id=<?= $p['id'] ?>&amp;sort=<?= h($sort) ?>"><?= h($p['last_name']) ?> <?= h($p['first_name']) ?> (<?= h($p['birth']) ?>-<?= h($p['death']) ?>)</a>
        <?php endforeach; ?>
    </div>

    <!-- Main: Edit/Add form -->
    <div class="admin-main">
        <?php if ($person): ?>
        <form method="post" action="/admin/people?sort=<?= h($sort) ?>" class="admin-form">
            <input type="hidden" name="id" value="<?= $person['id'] ?>">
            <input type="hidden" name="todo" value="update">

            <div class="form-row"><label>First Name</label><div><input type="text" name="first_name" size="20" value="<?= h($person['first_name'] ?? '') ?>"> Masc. <input type="checkbox" name="is_male" value="1"<?= $person['is_male'] ? ' checked' : '' ?>></div></div>
            <div class="form-row"><label>First Names</label><input type="text" name="first_names" size="40" value="<?= h($person['first_names'] ?? '') ?>"></div>
            <div class="form-row"><label>Last Name</label><input type="text" name="last_name" size="20" value="<?= h($person['last_name'] ?? '') ?>"></div>
            <div class="form-row"><label>Birth Date</label><input type="date" name="birth_date" value="<?= h($person['birth_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Birth Precision</label><select name="birth_precision"><option value="">-</option><option value="ymd"<?= ($person['birth_precision'] ?? '') === 'ymd' ? ' selected' : '' ?>>Day</option><option value="ym"<?= ($person['birth_precision'] ?? '') === 'ym' ? ' selected' : '' ?>>Month</option><option value="y"<?= ($person['birth_precision'] ?? '') === 'y' ? ' selected' : '' ?>>Year</option></select></div>
            <div class="form-row"><label>Death Date</label><input type="date" name="death_date" value="<?= h($person['death_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Death Precision</label><select name="death_precision"><option value="">-</option><option value="ymd"<?= ($person['death_precision'] ?? '') === 'ymd' ? ' selected' : '' ?>>Day</option><option value="ym"<?= ($person['death_precision'] ?? '') === 'ym' ? ' selected' : '' ?>>Month</option><option value="y"<?= ($person['death_precision'] ?? '') === 'y' ? ' selected' : '' ?>>Year</option></select></div>
            <div class="form-row"><label>Birth Place</label><input type="text" name="birth_place" size="30" value="<?= h($person['birth_place'] ?? '') ?>"></div>
            <div class="form-row"><label>Death Place</label><input type="text" name="death_place" size="30" value="<?= h($person['death_place'] ?? '') ?>"></div>
            <div class="form-row"><label>Parents</label><select name="couple_id"><option value="0">(none)</option><?php foreach ($coupleList as $c): ?><option value="<?= $c['id'] ?>"<?= ($person['couple_id'] ?? 0) == $c['id'] ? ' selected' : '' ?>><?= h($c['ln1'] . ' ' . $c['fn1'] . ' & ' . $c['ln2'] . ' ' . $c['fn2']) ?></option><?php endforeach; ?></select></div>
            <div class="form-row"><label>Sibling Order</label><input type="number" name="couple_sort" size="3" value="<?= h($person['couple_sort'] ?? '') ?>"></div>
            <div class="form-row"><label>Email [private]</label><input type="text" name="email" size="40" value="<?= h($person['email'] ?? '') ?>"></div>
            <div class="form-row"><label>Biography</label><textarea name="biography" cols="60" rows="5"><?= h($person['biography'] ?? '') ?></textarea></div>
            <div class="form-row"><label>Links</label><textarea name="links" cols="40" rows="3"><?= h($person['links'] ?? '') ?></textarea></div>
            <div class="form-actions">
                <input type="submit" value="Update">
                <input type="submit" name="todo" value="delete" onclick="return confirm('Delete this person?')">
            </div>
        </form>
        <?php else: ?>
        <!-- Add form -->
        <form method="post" action="/admin/people?sort=<?= h($sort) ?>" class="admin-form">
            <input type="hidden" name="todo" value="add">

            <div class="form-row"><label>First Name</label><div><input type="text" name="first_name" size="20"> Masc. <input type="checkbox" name="is_male" value="1" checked></div></div>
            <div class="form-row"><label>First Names</label><input type="text" name="first_names" size="40"></div>
            <div class="form-row"><label>Last Name</label><input type="text" name="last_name" size="20"></div>
            <div class="form-row"><label>Birth Date</label><input type="date" name="birth_date"></div>
            <div class="form-row"><label>Birth Precision</label><select name="birth_precision"><option value="">-</option><option value="ymd">Day</option><option value="ym">Month</option><option value="y">Year</option></select></div>
            <div class="form-row"><label>Death Date</label><input type="date" name="death_date"></div>
            <div class="form-row"><label>Death Precision</label><select name="death_precision"><option value="">-</option><option value="ymd">Day</option><option value="ym">Month</option><option value="y">Year</option></select></div>
            <div class="form-row"><label>Birth Place</label><input type="text" name="birth_place" size="30"></div>
            <div class="form-row"><label>Death Place</label><input type="text" name="death_place" size="30"></div>
            <div class="form-row"><label>Parents</label><select name="couple_id"><option value="0">(none)</option><?php foreach ($coupleList as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['ln1'] . ' ' . $c['fn1'] . ' & ' . $c['ln2'] . ' ' . $c['fn2']) ?></option><?php endforeach; ?></select></div>
            <div class="form-row"><label>Sibling Order</label><input type="number" name="couple_sort" size="3"></div>
            <div class="form-row"><label>Email [private]</label><input type="text" name="email" size="40"></div>
            <div class="form-row"><label>Biography</label><textarea name="biography" cols="60" rows="5"></textarea></div>
            <div class="form-actions">
                <input type="submit" value="Add">
            </div>
        </form>
        <p><small>[*] Email addresses are private and not displayed on the site.</small></p>
        <?php endif; ?>
    </div>
</div>
