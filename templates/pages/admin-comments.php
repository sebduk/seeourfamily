<?php

/**
 * Admin: Comments CRUD.
 *
 * Replaces Prog/Admin/commIndex.asp + commList.asp + commPage.asp.
 * Includes dual-list for linking people to comments.
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
        $pdo->prepare('DELETE FROM comment_person_link WHERE comment_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM comments WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Comment deleted.';
        $id = 0;
    } elseif ($todo === 'add' || $todo === 'update') {
        $fields = [
            'title'      => $val('title'),
            'event_date' => $val('event_date'),
            'body'       => $val('body'),
        ];
        $personIds = $_POST['linked_people'] ?? [];

        if ($todo === 'add') {
            $fields['family_id'] = $fid;
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO comments ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
            $msg = 'Comment added.';
        } else {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE comments SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                ->execute([...array_values($fields), $id, $fid]);
            $msg = 'Comment updated.';
        }

        // Update linked people
        if ($id > 0) {
            $pdo->prepare('DELETE FROM comment_person_link WHERE comment_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO comment_person_link (comment_id, person_id, sort_order) VALUES (?, ?, ?)');
            foreach ($personIds as $sortIdx => $pid) {
                $ins->execute([$id, (int)$pid, $sortIdx + 1]);
            }
        }
    }
}

$editId = (int)($id ?? $_GET['id'] ?? 0);

// List
$stmt = $pdo->prepare('SELECT id, title, event_date FROM comments WHERE family_id = ? ORDER BY title');
$stmt->execute([$fid]);
$commentsList = $stmt->fetchAll();

// Edit record
$comment = null;
$linkedPeople = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM comments WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $comment = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT p.id, p.first_name, p.last_name FROM people p
         JOIN comment_person_link cpl ON cpl.person_id = p.id
         WHERE cpl.comment_id = ? ORDER BY cpl.sort_order'
    );
    $stmt->execute([$editId]);
    $linkedPeople = $stmt->fetchAll();
}

// All people (for dual-list)
$allPeople = $pdo->prepare('SELECT id, first_name, last_name FROM people WHERE family_id = ? ORDER BY last_name, first_name');
$allPeople->execute([$fid]);
$allPeopleList = $allPeople->fetchAll();
$linkedIds = array_column($linkedPeople, 'id');
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Comments</div>
        <div class="sidebar-links"><a href="/admin/comments">Add a Comment</a></div>
        <hr>
        <?php foreach ($commentsList as $c): ?>
            <a href="/admin/comments?id=<?= $c['id'] ?>"><?= h($c['title'] ?? '(untitled)') ?> <?= $c['event_date'] ? '(' . h($c['event_date']) . ')' : '' ?></a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <form method="post" action="/admin/comments" class="admin-form" onsubmit="selectAllLinked()">
            <input type="hidden" name="id" value="<?= $comment ? $comment['id'] : '' ?>">
            <input type="hidden" name="todo" value="<?= $comment ? 'update' : 'add' ?>">

            <div class="form-row"><label>Title</label><input type="text" name="title" size="40" value="<?= h($comment['title'] ?? '') ?>"></div>
            <div class="form-row"><label>Event Date</label><input type="text" name="event_date" size="20" value="<?= h($comment['event_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Comment</label><textarea name="body" cols="60" rows="8"><?= h($comment['body'] ?? '') ?></textarea></div>

            <!-- Dual-list: link people -->
            <div class="form-row"><label>People</label>
                <div class="dual-list">
                    <div>
                        <b>All</b><br>
                        <select id="allList" size="12" multiple>
                            <?php foreach ($allPeopleList as $p): ?>
                                <?php if (!in_array($p['id'], $linkedIds)): ?>
                                <option value="<?= $p['id'] ?>"><?= h($p['last_name'] . ' ' . $p['first_name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dual-buttons">
                        <button type="button" onclick="addPerson()">&gt;&gt;</button>
                        <button type="button" onclick="removePerson()">&lt;&lt;</button>
                        <button type="button" onclick="moveUp()">Up</button>
                        <button type="button" onclick="moveDown()">Down</button>
                    </div>
                    <div>
                        <b>Linked</b><br>
                        <select id="linkedList" name="linked_people[]" size="12" multiple>
                            <?php foreach ($linkedPeople as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= h($p['last_name'] . ' ' . $p['first_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <input type="submit" value="<?= $comment ? 'Update' : 'Add' ?>">
                <?php if ($comment): ?><input type="submit" name="todo" value="delete" onclick="return confirm('Delete this comment?')"><?php endif; ?>
            </div>
        </form>

        <script>
        function addPerson() {
            const all = document.getElementById('allList');
            const linked = document.getElementById('linkedList');
            for (const opt of [...all.selectedOptions]) { linked.add(opt); }
        }
        function removePerson() {
            const all = document.getElementById('allList');
            const linked = document.getElementById('linkedList');
            for (const opt of [...linked.selectedOptions]) { all.add(opt); }
        }
        function moveUp() {
            const sel = document.getElementById('linkedList');
            for (const opt of [...sel.selectedOptions]) {
                if (opt.previousElementSibling) sel.insertBefore(opt, opt.previousElementSibling);
            }
        }
        function moveDown() {
            const sel = document.getElementById('linkedList');
            for (const opt of [...sel.selectedOptions].reverse()) {
                if (opt.nextElementSibling) sel.insertBefore(opt.nextElementSibling, opt);
            }
        }
        function selectAllLinked() {
            for (const opt of document.getElementById('linkedList').options) opt.selected = true;
        }
        </script>
    </div>
</div>
