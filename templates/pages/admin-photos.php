<?php

/**
 * Admin: Photos CRUD + face tagging.
 *
 * Replaces Prog/Admin/photoIndex+List+Page+Upload.asp.
 * Handles image files (.jpg, .gif, .png).
 * Tagging stores x/y as percentages of image dimensions.
 */

if (!$isAdmin) { echo '<p>Admin access required.</p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$msg = '';

$familyName = $family['name'] ?? '';
$imagePath  = '/Gene/File/' . urlencode($familyName) . '/Image/';
$imageDir   = $_SERVER['DOCUMENT_ROOT'] . $imagePath;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $todo = $_POST['todo'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $val  = fn(string $k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;

    if ($todo === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM photo_tags WHERE photo_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM photo_person_link WHERE photo_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM photos WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Photo deleted.';
        $id = 0;
    } elseif ($todo === 'upload' && !empty($_FILES['photo_file']['name'])) {
        // File upload — allowlist only
        $allowedExt = ['jpg', 'jpeg', 'gif', 'png', 'mp3', 'mp4', 'avi', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['photo_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $msg = 'File type not allowed. Accepted: ' . implode(', ', $allowedExt);
        } elseif ($_FILES['photo_file']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Upload error (code ' . $_FILES['photo_file']['error'] . ').';
        } else {
            // Verify actual file content matches extension
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['photo_file']['tmp_name']);
            $allowedMime = [
                'image/jpeg', 'image/gif', 'image/png',
                'audio/mpeg', 'video/mp4', 'video/x-msvideo', 'video/avi',
                'application/pdf',
            ];
            if (!in_array($mime, $allowedMime, true)) {
                $msg = 'File content does not match an allowed type (detected: ' . h($mime) . ').';
            } else {
                $fileName = basename($_FILES['photo_file']['name']);
                // Sanitize: strip anything that isn't alphanumeric, dash, underscore, dot
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                $folder = $val('folder') ? trim($val('folder'), '/') . '/' : '';
                $targetDir = $imageDir . $folder;
                if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
                $target = $targetDir . $fileName;
                if (move_uploaded_file($_FILES['photo_file']['tmp_name'], $target)) {
                    $dbName = $folder . $fileName;
                    $pdo->prepare(
                        'INSERT INTO photos (family_id, file_name, photo_date) VALUES (?, ?, NULL)'
                    )->execute([$fid, $dbName]);
                    $id = (int)$pdo->lastInsertId();
                    $msg = 'Photo uploaded. Now edit its details below.';
                } else {
                    $msg = 'Upload failed.';
                }
            }
        }
    } elseif ($todo === 'add' || $todo === 'update') {
        $newFileName = $val('file_name');
        // On update: preserve original extension — only the basename can change
        if ($todo === 'update' && $newFileName !== null && $id > 0) {
            $origStmt = $pdo->prepare('SELECT file_name FROM photos WHERE id = ? AND family_id = ?');
            $origStmt->execute([$id, $fid]);
            $origFile = $origStmt->fetchColumn();
            if ($origFile) {
                $origExt = strtolower(pathinfo($origFile, PATHINFO_EXTENSION));
                $newExt  = strtolower(pathinfo($newFileName, PATHINFO_EXTENSION));
                if ($newExt !== $origExt) {
                    $newFileName = pathinfo($newFileName, PATHINFO_FILENAME) . '.' . $origExt;
                }
            }
        }
        $fields = [
            'file_name'       => $newFileName,
            'description'     => $val('description'),
            'photo_date'      => $val('photo_date'),
            'photo_precision' => $val('photo_precision'),
        ];
        $personIds = $_POST['linked_people'] ?? [];

        if ($todo === 'add') {
            $fields['family_id'] = $fid;
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO photos ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
            $msg = 'Photo added.';
        } else {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE photos SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                ->execute([...array_values($fields), $id, $fid]);
            $msg = 'Photo updated.';
        }

        // Update linked people
        if ($id > 0) {
            $pdo->prepare('DELETE FROM photo_person_link WHERE photo_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO photo_person_link (photo_id, person_id, sort_order) VALUES (?, ?, ?)');
            foreach ($personIds as $sortIdx => $pid) {
                $ins->execute([$id, (int)$pid, $sortIdx + 1]);
            }
        }

        // Save tags
        if ($id > 0) {
            $pdo->prepare('DELETE FROM photo_tags WHERE photo_id = ?')->execute([$id]);
            $tagsJson = $_POST['tags_json'] ?? '[]';
            $tags = json_decode($tagsJson, true) ?: [];
            $tagStmt = $pdo->prepare(
                'INSERT INTO photo_tags (photo_id, person_id, x_pct, y_pct) VALUES (?, ?, ?, ?)'
            );
            foreach ($tags as $tag) {
                if (!empty($tag['person_id'])) {
                    $tagStmt->execute([$id, (int)$tag['person_id'], (float)$tag['x'], (float)$tag['y']]);
                }
            }
        }
    }
}

$editId = (int)($id ?? $_GET['id'] ?? 0);

// All people (needed for sidebar filter and dual-list)
$allPeople = $pdo->prepare('SELECT id, first_name, last_name, YEAR(birth_date) AS birth_year FROM people WHERE family_id = ? ORDER BY last_name, first_name');
$allPeople->execute([$fid]);
$allPeopleList = $allPeople->fetchAll();

/** Format person name with optional birth year */
$pname = function(array $p): string {
    $n = $p['last_name'] . ' ' . $p['first_name'];
    if (!empty($p['birth_year'])) $n .= ' (' . $p['birth_year'] . ')';
    return $n;
};

// Sidebar filters
$filterPerson = (int)($_GET['person'] ?? 0);
$sortBy       = ($_GET['sort'] ?? '') === 'year' ? 'year' : 'name';

// Build query string helper (preserves filter/sort when navigating)
$qs = function(array $extra = []): string {
    $params = [];
    foreach (['person', 'sort'] as $k) {
        if (!empty($_GET[$k])) $params[$k] = $_GET[$k];
    }
    $params = array_merge($params, $extra);
    // Remove empty values
    $params = array_filter($params, fn($v) => $v !== '' && $v !== 0 && $v !== '0');
    return $params ? '?' . http_build_query($params) : '';
};

// List (image files only) with tag status
$sql = "SELECT p.id, p.file_name, p.photo_date,
               COUNT(DISTINCT ppl.person_id) AS linked_count,
               COUNT(DISTINCT pt.person_id)  AS tagged_count
        FROM photos p
        LEFT JOIN photo_person_link ppl ON ppl.photo_id = p.id
        LEFT JOIN photo_tags pt ON pt.photo_id = p.id";
$where = " WHERE p.family_id = ?
           AND (LOWER(RIGHT(p.file_name, 3)) IN ('jpg','gif','png') OR LOWER(RIGHT(p.file_name, 4)) = 'jpeg')";
$params = [$fid];

if ($filterPerson > 0) {
    $sql .= " JOIN photo_person_link ppl_f ON ppl_f.photo_id = p.id AND ppl_f.person_id = ?";
    $params[] = $filterPerson;
}

$sql .= $where . " GROUP BY p.id, p.file_name, p.photo_date";
$sql .= $sortBy === 'year' ? " ORDER BY p.photo_date DESC, p.file_name" : " ORDER BY p.file_name, p.photo_date";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photosList = $stmt->fetchAll();

// Edit record
$photo = null;
$linkedPeople = [];
$existingTags = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $photo = $stmt->fetch();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.first_name, p.last_name, YEAR(p.birth_date) AS birth_year FROM people p
         JOIN photo_person_link ppl ON ppl.person_id = p.id
         WHERE ppl.photo_id = ? ORDER BY ppl.sort_order'
    );
    $stmt->execute([$editId]);
    $linkedPeople = $stmt->fetchAll();

    // Load existing tags
    $stmt = $pdo->prepare('SELECT person_id, x_pct, y_pct FROM photo_tags WHERE photo_id = ?');
    $stmt->execute([$editId]);
    $existingTags = $stmt->fetchAll();
}

// Build a lookup: person_id => {x, y}
$tagMap = [];
foreach ($existingTags as $t) {
    $tagMap[(int)$t['person_id']] = ['x' => (float)$t['x_pct'], 'y' => (float)$t['y_pct']];
}
$taggedIds = array_keys($tagMap);
$linkedIds = array_column($linkedPeople, 'id');
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Pictures</div>
        <div class="sidebar-links">
            <a href="/admin/photos<?= $qs() ?>">Add / Upload</a>
        </div>

        <!-- Filter & sort -->
        <form method="get" action="/admin/photos" class="sidebar-filter">
            <select name="person" onchange="this.form.submit()">
                <option value="">All people</option>
                <?php foreach ($allPeopleList as $fp): ?>
                <option value="<?= $fp['id'] ?>"<?= $filterPerson === (int)$fp['id'] ? ' selected' : '' ?>><?= h($pname($fp)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" onchange="this.form.submit()">
                <option value="name"<?= $sortBy === 'name' ? ' selected' : '' ?>>Sort: name</option>
                <option value="year"<?= $sortBy === 'year' ? ' selected' : '' ?>>Sort: year</option>
            </select>
        </form>

        <hr>
        <?php foreach ($photosList as $p):
            $linked = (int)$p['linked_count'];
            $tagged = (int)$p['tagged_count'];
            if ($linked > 0 && $tagged >= $linked) {
                $dotClass = 'tag-status-green';
            } elseif ($tagged > 0) {
                $dotClass = 'tag-status-yellow';
            } else {
                $dotClass = 'tag-status-red';
            }
        ?>
            <a href="/admin/photos?id=<?= $p['id'] ?><?= h(substr($qs(), 1) ? '&' . substr($qs(), 1) : '') ?>"><span class="tag-status-dot <?= $dotClass ?>">&#9679;</span> <?= h(pathinfo($p['file_name'], PATHINFO_FILENAME)) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <!-- Upload form -->
        <?php if (!$photo): ?>
        <form method="post" action="/admin/photos" enctype="multipart/form-data" class="admin-form" style="margin-bottom:1rem">
            <input type="hidden" name="todo" value="upload">
            <div class="form-row"><label>Upload Photo</label><input type="file" name="photo_file" accept=".jpg,.jpeg,.gif,.png,.mp3,.mp4,.avi,.pdf"></div>
            <div class="form-row"><label>Folder (optional)</label><input type="text" name="folder" size="20"></div>
            <div class="form-actions"><input type="submit" value="Upload"></div>
        </form>
        <hr>
        <?php endif; ?>

        <!-- Edit/Add form -->
        <form method="post" action="/admin/photos" class="admin-form" id="photoForm" onsubmit="return onSubmit()">
            <input type="hidden" name="id" value="<?= $photo ? $photo['id'] : '' ?>">
            <input type="hidden" name="todo" value="<?= $photo ? 'update' : 'add' ?>">
            <input type="hidden" name="tags_json" id="tagsJson" value="">

            <?php if ($photo): ?>
            <!-- Taggable image -->
            <div class="tag-container" id="tagContainer">
                <img id="tagImg" src="<?= h($imagePath . $photo['file_name']) ?>">
            </div>
            <p class="tag-instruction" id="tagInstruction">Click a name below, then click the photo to place their tag. Click a tag dot to remove it.</p>

            <!-- Tag people chips -->
            <div class="tag-people" id="tagPeople">
                <?php foreach ($linkedPeople as $p):
                    $pid = (int)$p['id'];
                    $isTagged = in_array($pid, $taggedIds);
                    $tx = $isTagged ? $tagMap[$pid]['x'] : '';
                    $ty = $isTagged ? $tagMap[$pid]['y'] : '';
                ?>
                <span class="tag-chip <?= $isTagged ? 'tagged' : '' ?>"
                      data-id="<?= $pid ?>"
                      data-x="<?= $tx ?>"
                      data-y="<?= $ty ?>">
                    <span class="tag-indicator"><?= $isTagged ? '&#9679;' : '&#9675;' ?></span>
                    <?= h($pname($p)) ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="form-row"><label>File Name</label><input type="text" name="file_name" size="40" value="<?= h($photo['file_name'] ?? '') ?>"></div>
            <div class="form-row"><label>Description</label><textarea name="description" cols="60" rows="3"><?= h($photo['description'] ?? '') ?></textarea></div>
            <div class="form-row"><label>Date</label><input type="date" name="photo_date" value="<?= h($photo['photo_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Precision</label><select name="photo_precision"><option value="">-</option><option value="ymd"<?= ($photo['photo_precision'] ?? '') === 'ymd' ? ' selected' : '' ?>>Day</option><option value="ym"<?= ($photo['photo_precision'] ?? '') === 'ym' ? ' selected' : '' ?>>Month</option><option value="y"<?= ($photo['photo_precision'] ?? '') === 'y' ? ' selected' : '' ?>>Year</option></select></div>

            <div class="form-row"><label>People</label>
                <div class="dual-list">
                    <div><b>All</b><br><select id="allList" size="10" multiple><?php foreach ($allPeopleList as $p): ?><?php if (!in_array($p['id'], $linkedIds)): ?><option value="<?= $p['id'] ?>"><?= h($pname($p)) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                    <div class="dual-buttons"><button type="button" onclick="addPerson()">&gt;&gt;</button><button type="button" onclick="removePerson()">&lt;&lt;</button><button type="button" onclick="moveUp()">Up</button><button type="button" onclick="moveDown()">Down</button></div>
                    <div><b>Linked</b><br><select id="linkedList" name="linked_people[]" size="10" multiple><?php foreach ($linkedPeople as $p): ?><option value="<?= $p['id'] ?>"><?= h($pname($p)) ?></option><?php endforeach; ?></select></div>
                </div>
            </div>

            <div class="form-actions">
                <input type="submit" value="<?= $photo ? 'Update' : 'Add' ?>">
                <?php if ($photo): ?><input type="submit" name="todo" value="delete" onclick="return confirm('Delete this photo?')"><?php endif; ?>
            </div>
        </form>

        <script>
        // ---- Tag state ----
        var tags = {};          // {personId: {x, y}}
        var selectedId = null;  // currently highlighted person for tagging

        // Init from server data
        document.querySelectorAll('.tag-chip').forEach(function(chip) {
            var x = parseFloat(chip.dataset.x);
            var y = parseFloat(chip.dataset.y);
            if (!isNaN(x) && !isNaN(y)) {
                tags[chip.dataset.id] = {x: x, y: y};
                renderDot(chip.dataset.id, x, y);
            }
        });

        // ---- Tag chip click: select person ----
        var tagPeople = document.getElementById('tagPeople');
        if (tagPeople) tagPeople.addEventListener('click', function(e) {
            var chip = e.target.closest('.tag-chip');
            if (!chip) return;
            document.querySelectorAll('.tag-chip').forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            selectedId = chip.dataset.id;
        });

        // ---- Image click: place tag ----
        var tagImg = document.getElementById('tagImg');
        if (tagImg) tagImg.addEventListener('click', function(e) {
            if (!selectedId) return;
            var rect = this.getBoundingClientRect();
            var x = ((e.clientX - rect.left) / rect.width * 100);
            var y = ((e.clientY - rect.top) / rect.height * 100);
            x = Math.round(x * 100) / 100;
            y = Math.round(y * 100) / 100;
            removeDot(selectedId);
            tags[selectedId] = {x: x, y: y};
            renderDot(selectedId, x, y);
            updateChip(selectedId, true);
            // Deselect
            document.querySelectorAll('.tag-chip').forEach(function(c) { c.classList.remove('active'); });
            selectedId = null;
        });

        // ---- Render / remove dots ----
        function renderDot(personId, x, y) {
            removeDot(personId);
            var container = document.getElementById('tagContainer');
            if (!container) return;
            var chip = document.querySelector('.tag-chip[data-id="' + personId + '"]');
            var name = chip ? chip.textContent.replace(/^[\u25CB\u25CF]\s*/, '').trim() : '';
            var dot = document.createElement('div');
            dot.className = 'tag-dot';
            dot.dataset.person = personId;
            dot.style.left = x + '%';
            dot.style.top = y + '%';
            var label = document.createElement('span');
            label.className = 'tag-dot-label';
            label.textContent = name;
            dot.appendChild(label);
            dot.addEventListener('click', function(ev) {
                ev.stopPropagation();
                delete tags[personId];
                removeDot(personId);
                updateChip(personId, false);
            });
            container.appendChild(dot);
        }

        function removeDot(personId) {
            var dot = document.querySelector('.tag-dot[data-person="' + personId + '"]');
            if (dot) dot.remove();
        }

        function updateChip(personId, isTagged) {
            var chip = document.querySelector('.tag-chip[data-id="' + personId + '"]');
            if (!chip) return;
            chip.classList.toggle('tagged', isTagged);
            var ind = chip.querySelector('.tag-indicator');
            if (ind) ind.innerHTML = isTagged ? '&#9679;' : '&#9675;';
        }

        // ---- Dual-list functions ----
        function addPerson() {
            var a = document.getElementById('allList'), l = document.getElementById('linkedList');
            var tp = document.getElementById('tagPeople');
            for (var o of [...a.selectedOptions]) {
                // Add tag chip if editing a photo
                if (tp && !document.querySelector('.tag-chip[data-id="' + o.value + '"]')) {
                    var chip = document.createElement('span');
                    chip.className = 'tag-chip';
                    chip.dataset.id = o.value;
                    chip.dataset.x = '';
                    chip.dataset.y = '';
                    chip.innerHTML = '<span class="tag-indicator">&#9675;</span> ' + o.textContent;
                    tp.appendChild(chip);
                }
                l.add(o);
            }
        }

        function removePerson() {
            var a = document.getElementById('allList'), l = document.getElementById('linkedList');
            for (var o of [...l.selectedOptions]) {
                // Remove tag and chip
                var pid = o.value;
                delete tags[pid];
                removeDot(pid);
                var chip = document.querySelector('.tag-chip[data-id="' + pid + '"]');
                if (chip) chip.remove();
                a.add(o);
            }
        }

        function moveUp() {
            var s = document.getElementById('linkedList');
            for (var o of [...s.selectedOptions]) if (o.previousElementSibling) s.insertBefore(o, o.previousElementSibling);
        }
        function moveDown() {
            var s = document.getElementById('linkedList');
            for (var o of [...s.selectedOptions].reverse()) if (o.nextElementSibling) s.insertBefore(o.nextElementSibling, o);
        }

        function onSubmit() {
            // Select all linked people (so they get submitted)
            for (var o of document.getElementById('linkedList').options) o.selected = true;
            // Serialize tags to JSON
            var arr = [];
            for (var pid in tags) {
                arr.push({person_id: parseInt(pid), x: tags[pid].x, y: tags[pid].y});
            }
            document.getElementById('tagsJson').value = JSON.stringify(arr);
            return true;
        }
        </script>
    </div>
</div>
