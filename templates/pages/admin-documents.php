<?php

/**
 * Admin: Documents CRUD + face tagging.
 *
 * Unified admin page for ALL file types (images, video, audio, PDFs, etc.).
 * Replaces the old separate admin-photos.php and admin-documents.php pages.
 * Tagging stores x/y as percentages of image dimensions.
 */

if (!$isAdmin) { echo '<p>Admin access required.</p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$msg = '';

$familyName = $family['name'] ?? '';

// Allowed extensions for upload
$allowedExt = [
    'jpg', 'jpeg', 'gif', 'png', 'webp',
    'mp3', 'ogg', 'wav',
    'mp4', 'avi', 'webm', 'mov', 'm4v',
    'pdf', 'txt', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
];
$acceptAttr = implode(',', array_map(fn($e) => '.' . $e, $allowedExt));

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $todo = $_POST['todo'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $val  = fn(string $k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;

    if ($todo === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM document_tags WHERE document_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM document_person_link WHERE document_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM documents WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Document deleted.';
        $id = 0;
    } elseif ($todo === 'upload' && !empty($_FILES['upload_file']['name'])) {
        $result = $media->storeUpload($_FILES['upload_file'], $fid);
        if ($result === null) {
            $ext = strtolower(pathinfo($_FILES['upload_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                $msg = 'File type not allowed. Accepted: ' . implode(', ', $allowedExt);
            } else {
                $msg = 'Upload failed — file content may not match an allowed type.';
            }
        } else {
            $pdo->prepare(
                'INSERT INTO documents (family_id, stored_filename, original_filename, mime_type, file_size, doc_date)
                 VALUES (?, ?, ?, ?, ?, NULL)'
            )->execute([$fid, $result['stored_filename'], $result['original_filename'], $result['mime_type'], $result['file_size']]);
            $id = (int)$pdo->lastInsertId();
            $msg = 'File uploaded. Now edit its details below.';
        }
    } elseif ($todo === 'add' || $todo === 'update') {
        $newFileName = $val('file_name');
        // On update: preserve original extension — only the basename can change
        if ($todo === 'update' && $newFileName !== null && $id > 0) {
            $origStmt = $pdo->prepare('SELECT file_name FROM documents WHERE id = ? AND family_id = ?');
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
        $folderId = (int)($_POST['folder_id'] ?? 0) ?: null;
        $fields = [
            'file_name'      => $newFileName,
            'description'    => $val('description'),
            'doc_date'       => $val('doc_date'),
            'doc_precision'  => $val('doc_precision'),
            'folder_id'      => $folderId,
        ];
        $personIds = $_POST['linked_people'] ?? [];

        if ($todo === 'add') {
            $fields['family_id'] = $fid;
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO documents ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
            $msg = 'Document added.';
        } else {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE documents SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                ->execute([...array_values($fields), $id, $fid]);
            $msg = 'Document updated.';
        }

        // Update linked people
        if ($id > 0) {
            $pdo->prepare('DELETE FROM document_person_link WHERE document_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO document_person_link (document_id, person_id, sort_order) VALUES (?, ?, ?)');
            foreach ($personIds as $sortIdx => $pid) {
                $ins->execute([$id, (int)$pid, $sortIdx + 1]);
            }
        }

        // Handle poster image upload for video/audio
        if ($id > 0 && !empty($_FILES['poster_file']['name'])) {
            $posterResult = $media->storeUpload($_FILES['poster_file'], $fid);
            if ($posterResult !== null) {
                // Create a hidden document row for the poster image
                $pdo->prepare(
                    'INSERT INTO documents (family_id, stored_filename, original_filename, mime_type, file_size)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$fid, $posterResult['stored_filename'], $posterResult['original_filename'], $posterResult['mime_type'], $posterResult['file_size']]);
                $posterDocId = (int)$pdo->lastInsertId();
                $puStmt = $pdo->prepare('SELECT uuid FROM documents WHERE id = ?');
                $puStmt->execute([$posterDocId]);
                $posterUuid = $puStmt->fetchColumn();
                $pdo->prepare('UPDATE documents SET poster_uuid = ? WHERE id = ? AND family_id = ?')
                    ->execute([$posterUuid, $id, $fid]);
            }
        } elseif ($id > 0 && isset($_POST['remove_poster']) && $_POST['remove_poster'] === '1') {
            $pdo->prepare('UPDATE documents SET poster_uuid = NULL WHERE id = ? AND family_id = ?')
                ->execute([$id, $fid]);
        }

        // Save tags
        if ($id > 0) {
            $pdo->prepare('DELETE FROM document_tags WHERE document_id = ?')->execute([$id]);
            $tagsJson = $_POST['tags_json'] ?? '[]';
            $tags = json_decode($tagsJson, true) ?: [];
            $tagStmt = $pdo->prepare(
                'INSERT INTO document_tags (document_id, person_id, x_pct, y_pct) VALUES (?, ?, ?, ?)'
            );
            foreach ($tags as $tag) {
                if (!empty($tag['person_id'])) {
                    $tagStmt->execute([$id, (int)$tag['person_id'], (float)$tag['x'], (float)$tag['y']]);
                }
            }
        }
    }
}

$editUuid = $router->param('id') ?? $_GET['id'] ?? '';
if ($editUuid !== '' && !ctype_digit($editUuid)) {
    $stmt = $pdo->prepare('SELECT id FROM documents WHERE uuid = ? AND family_id = ?');
    $stmt->execute([$editUuid, $fid]);
    $resolved = $stmt->fetch();
    $editId = $resolved ? (int)$resolved['id'] : 0;
} else {
    $editId = (int)$editUuid;
}

// ---- Maintenance: check for missing files ----
$warnings = [];

$chkStmt = $pdo->prepare(
    "SELECT id, file_name, stored_filename FROM documents WHERE family_id = ?
     AND (file_name IS NOT NULL OR stored_filename IS NOT NULL)"
);
$chkStmt->execute([$fid]);
foreach ($chkStmt->fetchAll() as $chk) {
    if ($media->diskPath($chk + ['family_id' => $fid], $familyName) === null) {
        $label = $chk['stored_filename'] ?: $chk['file_name'];
        $warnings[] = 'File missing on disk: ' . ($label ?? '(no filename)');
    }
}

// All folders for dropdown (no type filter — folders are universal now)
$folderStmt = $pdo->prepare('SELECT id, name, parent_folder_id FROM folders WHERE family_id = ? AND is_online = 1 ORDER BY name');
$folderStmt->execute([$fid]);
$foldersList = $folderStmt->fetchAll();

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
$filterTagged = in_array($_GET['tagged'] ?? '', ['done', 'partial', 'none'], true) ? $_GET['tagged'] : '';
$sortBy       = in_array($_GET['sort'] ?? '', ['name', 'year'], true) ? $_GET['sort'] : 'path';

// Build query string helper (preserves filter/sort when navigating)
$qs = function(array $extra = []): string {
    $params = [];
    foreach (['person', 'tagged', 'sort'] as $k) {
        if (!empty($_GET[$k])) $params[$k] = $_GET[$k];
    }
    $params = array_merge($params, $extra);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== 0 && $v !== '0');
    return $params ? '?' . http_build_query($params) : '';
};

// List all documents with tag status
$sql = "SELECT p.id, p.uuid, p.file_name, p.original_filename, p.doc_date, p.mime_type,
               COUNT(DISTINCT dpl.person_id) AS linked_count,
               COUNT(DISTINCT dt.person_id)  AS tagged_count
        FROM documents p
        LEFT JOIN document_person_link dpl ON dpl.document_id = p.id
        LEFT JOIN document_tags dt ON dt.document_id = p.id";
$sql .= " WHERE p.family_id = ?
           AND (p.file_name IS NOT NULL OR p.stored_filename IS NOT NULL)
           AND COALESCE(p.file_name, '') NOT LIKE '%.tn.%'";
$params = [$fid];

if ($filterPerson > 0) {
    $sql .= " AND EXISTS (SELECT 1 FROM document_person_link dpl_f WHERE dpl_f.document_id = p.id AND dpl_f.person_id = ?)";
    $params[] = $filterPerson;
}

$sql .= " GROUP BY p.id, p.uuid, p.file_name, p.original_filename, p.doc_date, p.mime_type";

if ($filterTagged === 'done') {
    $sql .= " HAVING COUNT(DISTINCT dpl.person_id) > 0 AND COUNT(DISTINCT dt.person_id) >= COUNT(DISTINCT dpl.person_id)";
} elseif ($filterTagged === 'partial') {
    $sql .= " HAVING COUNT(DISTINCT dt.person_id) > 0 AND COUNT(DISTINCT dt.person_id) < COUNT(DISTINCT dpl.person_id)";
} elseif ($filterTagged === 'none') {
    $sql .= " HAVING COUNT(DISTINCT dt.person_id) = 0";
}

if ($sortBy === 'year') {
    $sql .= " ORDER BY p.doc_date DESC, COALESCE(p.original_filename, p.file_name)";
} elseif ($sortBy === 'path') {
    $sql .= " ORDER BY COALESCE(p.original_filename, p.file_name), p.doc_date";
} else {
    $sql .= " ORDER BY SUBSTRING_INDEX(COALESCE(p.original_filename, p.file_name), '/', -1), p.doc_date";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$docsList = $stmt->fetchAll();

// Edit record
$doc = null;
$linkedPeople = [];
$existingTags = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $doc = $stmt->fetch();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.first_name, p.last_name, YEAR(p.birth_date) AS birth_year FROM people p
         JOIN document_person_link dpl ON dpl.person_id = p.id
         WHERE dpl.document_id = ? ORDER BY dpl.sort_order'
    );
    $stmt->execute([$editId]);
    $linkedPeople = $stmt->fetchAll();

    // Load existing tags
    $stmt = $pdo->prepare('SELECT person_id, x_pct, y_pct FROM document_tags WHERE document_id = ?');
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

$docMime = $doc ? \SeeOurFamily\Media::mimeFromRow($doc) : '';
$isImageFile = \SeeOurFamily\Media::isImage($docMime);
$isVideoFile = \SeeOurFamily\Media::isVideo($docMime);
$isAudioFile = \SeeOurFamily\Media::isAudio($docMime);
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>
<?php if ($warnings): ?>
<div class="admin-warnings">
    <b>Maintenance log:</b>
    <ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Documents</div>
        <div class="sidebar-links">
            <a href="/admin/documents<?= $qs() ?>">Add / Upload</a>
        </div>

        <!-- Filter & sort -->
        <form method="get" action="/admin/documents" class="sidebar-filter">
            <select name="person" onchange="this.form.submit()">
                <option value="">All people</option>
                <?php foreach ($allPeopleList as $fp): ?>
                <option value="<?= $fp['id'] ?>"<?= $filterPerson === (int)$fp['id'] ? ' selected' : '' ?>><?= h($pname($fp)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="tagged" onchange="this.form.submit()">
                <option value=""<?= $filterTagged === '' ? ' selected' : '' ?>>Tags: all</option>
                <option value="done"<?= $filterTagged === 'done' ? ' selected' : '' ?>>Tags: done</option>
                <option value="partial"<?= $filterTagged === 'partial' ? ' selected' : '' ?>>Tags: partial</option>
                <option value="none"<?= $filterTagged === 'none' ? ' selected' : '' ?>>Tags: none</option>
            </select>
            <select name="sort" onchange="this.form.submit()">
                <option value="path"<?= $sortBy === 'path' ? ' selected' : '' ?>>Sort: name</option>
                <option value="name"<?= $sortBy === 'name' ? ' selected' : '' ?>>Sort: filename</option>
                <option value="year"<?= $sortBy === 'year' ? ' selected' : '' ?>>Sort: date</option>
            </select>
        </form>

        <hr>
        <?php foreach ($docsList as $p):
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
            <?php $displayName = preg_replace('/\.[^.]+$/', '', $p['original_filename'] ?? $p['file_name'] ?? ''); ?>
            <a href="/admin/documents?id=<?= $p['uuid'] ?><?= h(substr($qs(), 1) ? '&' . substr($qs(), 1) : '') ?>"><span class="tag-status-dot <?= $dotClass ?>">&#9679;</span> <?= h($displayName) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <!-- Upload form -->
        <?php if (!$doc): ?>
        <form method="post" action="/admin/documents<?= $qs() ?>" enctype="multipart/form-data" class="admin-form" style="margin-bottom:1rem">
            <input type="hidden" name="todo" value="upload">
            <div class="form-row"><label>Upload File</label><input type="file" name="upload_file" accept="<?= $acceptAttr ?>"></div>
            <div class="form-actions"><input type="submit" value="Upload"></div>
        </form>
        <p><small>Accepted: <?= implode(', ', array_map(fn($e) => '.' . $e, $allowedExt)) ?></small></p>
        <hr>
        <?php endif; ?>

        <!-- Edit/Add form -->
        <form method="post" action="/admin/documents<?= $qs() ?>" class="admin-form" id="docForm" onsubmit="return onSubmit()" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= $doc ? $doc['id'] : '' ?>">
            <input type="hidden" name="todo" value="<?= $doc ? 'update' : 'add' ?>">
            <input type="hidden" name="tags_json" id="tagsJson" value="">

            <?php if ($doc && $isVideoFile): ?>
            <!-- Video player preview -->
            <div class="media-preview">
                <video controls preload="metadata" style="max-width:100%;max-height:70vh"
                    <?php if (!empty($doc['poster_uuid'])): ?> poster="/media/<?= h($doc['uuid']) ?>?poster=1"<?php endif; ?>>
                    <source src="/media/<?= h($doc['uuid']) ?>" type="<?= h($docMime) ?>">
                </video>
            </div>
            <?php elseif ($doc && $isAudioFile): ?>
            <!-- Audio player preview -->
            <div class="media-preview">
                <?php if (!empty($doc['poster_uuid'])): ?>
                    <img src="/media/<?= h($doc['uuid']) ?>?poster=1" style="max-width:300px;display:block;margin-bottom:.5rem" alt="Poster">
                <?php endif; ?>
                <audio controls preload="metadata" style="width:100%">
                    <source src="/media/<?= h($doc['uuid']) ?>" type="<?= h($docMime) ?>">
                </audio>
            </div>
            <?php elseif ($doc && $isImageFile): ?>
            <!-- Taggable image -->
            <div class="tag-container" id="tagContainer">
                <img id="tagImg" src="/media/<?= h($doc['uuid']) ?>">
            </div>
            <p class="tag-instruction" id="tagInstruction">Click a name below, then click the image to place their tag. Click a tag dot to remove it.</p>

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

            <?php if ($doc && $doc['stored_filename']): ?>
            <div class="form-row"><label>File</label><span><?= h($doc['original_filename'] ?? $doc['stored_filename']) ?></span></div>
            <?php else: ?>
            <div class="form-row"><label>File Name</label><input type="text" name="file_name" size="40" value="<?= h($doc['file_name'] ?? '') ?>"></div>
            <?php endif; ?>
            <div class="form-row"><label>Description</label><textarea name="description" cols="60" rows="3"><?= h($doc['description'] ?? '') ?></textarea></div>
            <div class="form-row"><label>Date</label><input type="date" name="doc_date" value="<?= h($doc['doc_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Precision</label><select name="doc_precision"><option value="">-</option><option value="ymd"<?= ($doc['doc_precision'] ?? '') === 'ymd' ? ' selected' : '' ?>>Day</option><option value="ym"<?= ($doc['doc_precision'] ?? '') === 'ym' ? ' selected' : '' ?>>Month</option><option value="y"<?= ($doc['doc_precision'] ?? '') === 'y' ? ' selected' : '' ?>>Year</option></select></div>
            <div class="form-row"><label>Folder</label>
                <select name="folder_id">
                    <option value="">(none)</option>
                    <?php foreach ($foldersList as $fl): ?>
                    <option value="<?= $fl['id'] ?>"<?= ($doc['folder_id'] ?? '') == $fl['id'] ? ' selected' : '' ?>><?= h($fl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($doc && ($isVideoFile || $isAudioFile)): ?>
            <div class="form-row"><label>Poster Image</label>
                <div>
                    <?php if (!empty($doc['poster_uuid'])): ?>
                        <img src="/media/<?= h($doc['uuid']) ?>?poster=1&amp;tn=1" alt="Current poster" style="max-height:80px;vertical-align:middle">
                        <label><input type="checkbox" name="remove_poster" value="1"> Remove poster</label><br>
                    <?php endif; ?>
                    <input type="file" name="poster_file" accept=".jpg,.jpeg,.gif,.png">
                </div>
            </div>
            <?php endif; ?>

            <div class="form-row"><label>People</label>
                <div class="dual-list">
                    <div><b>All</b><br><select id="allList" size="10" multiple><?php foreach ($allPeopleList as $p): ?><?php if (!in_array($p['id'], $linkedIds)): ?><option value="<?= $p['id'] ?>"><?= h($pname($p)) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                    <div class="dual-buttons"><button type="button" onclick="addPerson()">&gt;&gt;</button><button type="button" onclick="removePerson()">&lt;&lt;</button><button type="button" onclick="moveUp()">Up</button><button type="button" onclick="moveDown()">Down</button></div>
                    <div><b>Linked</b><br><select id="linkedList" name="linked_people[]" size="10" multiple><?php foreach ($linkedPeople as $p): ?><option value="<?= $p['id'] ?>"><?= h($pname($p)) ?></option><?php endforeach; ?></select></div>
                </div>
            </div>

            <div class="form-actions">
                <input type="submit" value="<?= $doc ? 'Update' : 'Add' ?>">
                <?php if ($doc): ?><input type="submit" name="todo" value="delete" onclick="return confirm('Delete this document?')"><?php endif; ?>
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
                // Add tag chip if editing an image
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
