<?php

/**
 * Admin: Documents CRUD.
 *
 * Replaces Prog/Admin/docIndex.asp + docList.asp + docPage.asp + docUpload.asp.
 * Documents are non-image files (.doc, .pdf, .xls, etc.) stored in the File directory.
 * Uses the same photos table but filtered to non-image extensions.
 * Includes dual-list for linking people to documents.
 */

if (!$isAdmin) { echo '<p>Admin access required.</p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$msg = '';

$familyName = $family['name'] ?? '';
$docPath    = '/Gene/File/' . urlencode($familyName) . '/Document/';
$docDir     = $_SERVER['DOCUMENT_ROOT'] . $docPath;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $todo = $_POST['todo'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $val  = fn(string $k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;

    if ($todo === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM photo_person_link WHERE photo_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM photos WHERE id = ? AND family_id = ?')->execute([$id, $fid]);
        $msg = 'Document deleted.';
        $id = 0;
    } elseif ($todo === 'upload' && !empty($_FILES['doc_file']['name'])) {
        // File upload — allowlist only
        $allowedExt = ['jpg', 'jpeg', 'gif', 'png', 'mp3', 'mp4', 'avi', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $msg = 'File type not allowed. Accepted: ' . implode(', ', $allowedExt);
        } elseif ($_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Upload error (code ' . $_FILES['doc_file']['error'] . ').';
        } else {
            // Verify actual file content matches extension
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['doc_file']['tmp_name']);
            $allowedMime = [
                'image/jpeg', 'image/gif', 'image/png',
                'audio/mpeg', 'video/mp4', 'video/x-msvideo', 'video/avi',
                'application/pdf',
            ];
            if (!in_array($mime, $allowedMime, true)) {
                $msg = 'File content does not match an allowed type (detected: ' . h($mime) . ').';
            } else {
                $fileName = basename($_FILES['doc_file']['name']);
                // Sanitize: strip anything that isn't alphanumeric, dash, underscore, dot
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                $folder = $val('folder') ? trim($val('folder'), '/') . '/' : '';
                $targetDir = $docDir . $folder;
                if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
                $target = $targetDir . $fileName;
                if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $target)) {
                    $dbName = $folder . $fileName;
                    $pdo->prepare(
                        'INSERT INTO photos (family_id, file_name, photo_date) VALUES (?, ?, NULL)'
                    )->execute([$fid, $dbName]);
                    $id = (int)$pdo->lastInsertId();
                    $msg = 'Document uploaded. Now edit its details below.';
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
            $msg = 'Document added.';
        } else {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $pdo->prepare("UPDATE photos SET $set, updated_at = NOW() WHERE id = ? AND family_id = ?")
                ->execute([...array_values($fields), $id, $fid]);
            $msg = 'Document updated.';
        }

        // Update linked people
        if ($id > 0) {
            $pdo->prepare('DELETE FROM photo_person_link WHERE photo_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO photo_person_link (photo_id, person_id, sort_order) VALUES (?, ?, ?)');
            foreach ($personIds as $sortIdx => $pid) {
                $ins->execute([$id, (int)$pid, $sortIdx + 1]);
            }
        }
    }
}

$editUuid = $router->param('id') ?? $_GET['id'] ?? '';
if ($editUuid !== '' && !ctype_digit($editUuid)) {
    $stmt = $pdo->prepare('SELECT id FROM photos WHERE uuid = ? AND family_id = ?');
    $stmt->execute([$editUuid, $fid]);
    $resolved = $stmt->fetch();
    $editId = $resolved ? (int)$resolved['id'] : 0;
} else {
    $editId = (int)$editUuid;
}

// File type icon helper
function docIcon(string $fileName): string {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $map = [
        'doc' => 'doc', 'docx' => 'doc',
        'xls' => 'xls', 'xlsx' => 'xls',
        'pdf' => 'pdf',
        'ppt' => 'ppt', 'pptx' => 'ppt', 'pps' => 'ppt',
        'txt' => 'txt',
        'zip' => 'zip', 'rar' => 'zip', 'gz' => 'zip',
        'mdb' => 'mdb', 'accdb' => 'mdb',
    ];
    return $map[$ext] ?? 'txt';
}

// List (non-image files only)
$stmt = $pdo->prepare(
    "SELECT id, uuid, file_name, photo_date FROM photos
     WHERE family_id = ?
       AND LOWER(RIGHT(file_name, 3)) NOT IN ('jpg','gif','png')
       AND LOWER(RIGHT(file_name, 4)) NOT IN ('jpeg','webp')
     ORDER BY file_name"
);
$stmt->execute([$fid]);
$docsList = $stmt->fetchAll();

// Edit record
$doc = null;
$linkedPeople = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND family_id = ?');
    $stmt->execute([$editId, $fid]);
    $doc = $stmt->fetch();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.first_name, p.last_name FROM people p
         JOIN photo_person_link ppl ON ppl.person_id = p.id
         WHERE ppl.photo_id = ? ORDER BY ppl.sort_order'
    );
    $stmt->execute([$editId]);
    $linkedPeople = $stmt->fetchAll();
}

$allPeople = $pdo->prepare('SELECT id, first_name, last_name FROM people WHERE family_id = ? ORDER BY last_name, first_name');
$allPeople->execute([$fid]);
$allPeopleList = $allPeople->fetchAll();
$linkedIds = array_column($linkedPeople, 'id');
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Documents</div>
        <div class="sidebar-links"><a href="/admin/documents">Add / Upload</a></div>
        <hr>
        <?php foreach ($docsList as $d): ?>
            <a href="/admin/documents?id=<?= $d['uuid'] ?>"><?= h(pathinfo($d['file_name'], PATHINFO_FILENAME)) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <!-- Upload form -->
        <?php if (!$doc): ?>
        <form method="post" action="/admin/documents" enctype="multipart/form-data" class="admin-form" style="margin-bottom:1rem">
            <input type="hidden" name="todo" value="upload">
            <div class="form-row"><label>Upload File</label><input type="file" name="doc_file" accept=".jpg,.jpeg,.gif,.png,.mp3,.mp4,.avi,.pdf"></div>
            <div class="form-row"><label>Folder (optional)</label><input type="text" name="folder" size="20"></div>
            <div class="form-actions"><input type="submit" value="Upload"></div>
        </form>
        <p><small>Naming convention: LastFirstNamesYearMonthDay.ext (e.g. DucosGabriel20020530.pdf)<br>
        Accepted types: .jpg, .jpeg, .gif, .png, .mp3, .mp4, .avi, .pdf</small></p>
        <hr>
        <?php endif; ?>

        <!-- Edit/Add form -->
        <form method="post" action="/admin/documents" class="admin-form" onsubmit="selectAllLinked()">
            <input type="hidden" name="id" value="<?= $doc ? $doc['id'] : '' ?>">
            <input type="hidden" name="todo" value="<?= $doc ? 'update' : 'add' ?>">

            <div class="form-row"><label>File Name</label><input type="text" name="file_name" size="40" value="<?= h($doc['file_name'] ?? '') ?>"></div>
            <div class="form-row"><label>Description</label><textarea name="description" cols="60" rows="3"><?= h($doc['description'] ?? '') ?></textarea></div>
            <div class="form-row"><label>Date</label><input type="date" name="photo_date" value="<?= h($doc['photo_date'] ?? '') ?>"></div>
            <div class="form-row"><label>Precision</label><select name="photo_precision"><option value="">-</option><option value="ymd"<?= ($doc['photo_precision'] ?? '') === 'ymd' ? ' selected' : '' ?>>Day</option><option value="ym"<?= ($doc['photo_precision'] ?? '') === 'ym' ? ' selected' : '' ?>>Month</option><option value="y"<?= ($doc['photo_precision'] ?? '') === 'y' ? ' selected' : '' ?>>Year</option></select></div>

            <div class="form-row"><label>People</label>
                <div class="dual-list">
                    <div><b>All</b><br><select id="allList" size="10" multiple><?php foreach ($allPeopleList as $p): ?><?php if (!in_array($p['id'], $linkedIds)): ?><option value="<?= $p['id'] ?>"><?= h($p['last_name'] . ' ' . $p['first_name']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                    <div class="dual-buttons"><button type="button" onclick="addPerson()">&gt;&gt;</button><button type="button" onclick="removePerson()">&lt;&lt;</button><button type="button" onclick="moveUp()">Up</button><button type="button" onclick="moveDown()">Down</button></div>
                    <div><b>Linked</b><br><select id="linkedList" name="linked_people[]" size="10" multiple><?php foreach ($linkedPeople as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['last_name'] . ' ' . $p['first_name']) ?></option><?php endforeach; ?></select></div>
                </div>
            </div>

            <div class="form-actions">
                <input type="submit" value="<?= $doc ? 'Update' : 'Add' ?>">
                <?php if ($doc): ?><input type="submit" name="todo" value="delete" onclick="return confirm('Delete this document?')"><?php endif; ?>
            </div>
        </form>

        <script>
        function addPerson(){const a=document.getElementById('allList'),l=document.getElementById('linkedList');for(const o of[...a.selectedOptions])l.add(o)}
        function removePerson(){const a=document.getElementById('allList'),l=document.getElementById('linkedList');for(const o of[...l.selectedOptions])a.add(o)}
        function moveUp(){const s=document.getElementById('linkedList');for(const o of[...s.selectedOptions])if(o.previousElementSibling)s.insertBefore(o,o.previousElementSibling)}
        function moveDown(){const s=document.getElementById('linkedList');for(const o of[...s.selectedOptions].reverse())if(o.nextElementSibling)s.insertBefore(o.nextElementSibling,o)}
        function selectAllLinked(){for(const o of document.getElementById('linkedList').options)o.selected=true}
        </script>
    </div>
</div>
