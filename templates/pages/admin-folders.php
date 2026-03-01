<?php

/**
 * Admin: Virtual folder management.
 *
 * Manages folders table for organizing documents (all file types).
 * Supports nested folders (parent_folder_id), rename, delete.
 */

if (!$isAdmin) { echo '<p>Admin access required.</p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$msg = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $todo = $_POST['todo'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    if ($todo === 'create') {
        $name     = trim($_POST['name'] ?? '');
        $parentId = (int)($_POST['parent_folder_id'] ?? 0) ?: null;
        if ($name === '') {
            $msg = 'Folder name is required.';
        } else {
            // Verify parent belongs to same family if specified
            if ($parentId !== null) {
                $chk = $pdo->prepare('SELECT id FROM folders WHERE id = ? AND family_id = ?');
                $chk->execute([$parentId, $fid]);
                if (!$chk->fetch()) {
                    $parentId = null; // invalid parent, put in root
                }
            }
            $uuid = self_uuid();
            $pdo->prepare(
                'INSERT INTO folders (uuid, family_id, name, parent_folder_id) VALUES (?, ?, ?, ?)'
            )->execute([$uuid, $fid, $name, $parentId]);
            $msg = 'Folder created.';
        }
    } elseif ($todo === 'rename' && $id > 0) {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            // Get old folder name to update document file_name prefixes
            $oldNameStmt = $pdo->prepare('SELECT name FROM folders WHERE id = ? AND family_id = ?');
            $oldNameStmt->execute([$id, $fid]);
            $oldFolderName = $oldNameStmt->fetchColumn();

            $pdo->prepare('UPDATE folders SET name = ?, updated_at = NOW() WHERE id = ? AND family_id = ?')
                ->execute([$name, $id, $fid]);

            // Update file_name prefix in documents that belong to this folder
            if ($oldFolderName) {
                $pdo->prepare(
                    "UPDATE documents
                     SET file_name = CONCAT(?, '/', SUBSTRING_INDEX(file_name, '/', -1))
                     WHERE folder_id = ? AND family_id = ?
                       AND file_name LIKE CONCAT(?, '/%')"
                )->execute([$name, $id, $fid, $oldFolderName]);
            }
            $msg = 'Folder renamed.';
        }
    } elseif ($todo === 'move' && $id > 0) {
        $parentId = (int)($_POST['parent_folder_id'] ?? 0) ?: null;
        // Prevent moving a folder into itself or its descendants
        if ($parentId !== null && $parentId === $id) {
            $msg = 'Cannot move a folder into itself.';
        } else {
            // Verify parent isn't a descendant
            $ok = true;
            if ($parentId !== null) {
                $checkId = $parentId;
                while ($checkId !== null) {
                    if ($checkId === $id) { $ok = false; break; }
                    $anc = $pdo->prepare('SELECT parent_folder_id FROM folders WHERE id = ? AND family_id = ?');
                    $anc->execute([$checkId, $fid]);
                    $row = $anc->fetch();
                    $checkId = $row ? ($row['parent_folder_id'] ? (int)$row['parent_folder_id'] : null) : null;
                }
            }
            if ($ok) {
                $pdo->prepare('UPDATE folders SET parent_folder_id = ?, updated_at = NOW() WHERE id = ? AND family_id = ?')
                    ->execute([$parentId, $id, $fid]);
                $msg = 'Folder moved.';
            } else {
                $msg = 'Cannot move a folder into one of its subfolders.';
            }
        }
    } elseif ($todo === 'delete' && $id > 0) {
        // Get folder name to strip prefix from document file_names
        $delNameStmt = $pdo->prepare('SELECT name FROM folders WHERE id = ? AND family_id = ?');
        $delNameStmt->execute([$id, $fid]);
        $delFolderName = $delNameStmt->fetchColumn();

        // Strip folder prefix from file_name and unlink documents
        if ($delFolderName) {
            $pdo->prepare(
                "UPDATE documents
                 SET folder_id = NULL,
                     file_name = SUBSTRING_INDEX(file_name, '/', -1)
                 WHERE folder_id = ? AND family_id = ?
                   AND file_name LIKE CONCAT(?, '/%')"
            )->execute([$id, $fid, $delFolderName]);
        }
        // Also unlink any remaining documents without the prefix
        $pdo->prepare('UPDATE documents SET folder_id = NULL WHERE folder_id = ? AND family_id = ?')
            ->execute([$id, $fid]);
        // Move child folders up to parent
        $parent = $pdo->prepare('SELECT parent_folder_id FROM folders WHERE id = ? AND family_id = ?');
        $parent->execute([$id, $fid]);
        $parentRow = $parent->fetch();
        $parentFolderId = $parentRow ? $parentRow['parent_folder_id'] : null;
        $pdo->prepare('UPDATE folders SET parent_folder_id = ? WHERE parent_folder_id = ? AND family_id = ?')
            ->execute([$parentFolderId, $id, $fid]);
        // Delete the folder
        $pdo->prepare('DELETE FROM folders WHERE id = ? AND family_id = ?')
            ->execute([$id, $fid]);
        $msg = 'Folder deleted. Documents moved to root; subfolders moved to parent.';
    }
}

// UUID generator
function self_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Load all folders for this family
$stmt = $pdo->prepare(
    'SELECT f.*, (SELECT COUNT(*) FROM documents d WHERE d.folder_id = f.id) AS doc_count
     FROM folders f
     WHERE f.family_id = ? AND f.is_online = 1
     ORDER BY f.name'
);
$stmt->execute([$fid]);
$allFolders = $stmt->fetchAll();

// Build a nested tree structure
function buildTree(array $folders, ?int $parentId = null): array {
    $tree = [];
    foreach ($folders as $f) {
        $fpid = $f['parent_folder_id'] ? (int)$f['parent_folder_id'] : null;
        if ($fpid === $parentId) {
            $f['children'] = buildTree($folders, (int)$f['id']);
            $tree[] = $f;
        }
    }
    return $tree;
}

$folderTree = buildTree($allFolders);

// Recursive render helper
function renderFolderTree(array $tree, int $depth = 0): void {
    foreach ($tree as $f) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
        $count = (int)$f['doc_count'];
        echo '<div class="folder-row">';
        echo '<span class="folder-indent">' . $indent . '</span>';
        echo '<span class="folder-icon">&#128193;</span> ';
        echo '<b>' . h($f['name']) . '</b>';
        echo ' <small>(' . $count . ' item' . ($count !== 1 ? 's' : '') . ')</small>';
        echo ' <span class="folder-actions">';
        echo '<button type="submit" form="rename-' . $f['id'] . '" class="btn-link">Rename</button>';
        echo ' | <button type="submit" form="del-' . $f['id'] . '" class="btn-link" onclick="return confirm(\'Delete folder &quot;' . h($f['name']) . '&quot;?\')">Delete</button>';
        echo '</span>';
        echo '</div>';
        // Hidden rename form
        echo '<form id="rename-' . $f['id'] . '" method="post" action="/admin/folders" class="folder-rename-form" style="display:none">';
        echo '<input type="hidden" name="todo" value="rename"><input type="hidden" name="id" value="' . $f['id'] . '">';
        echo '<input type="text" name="name" value="' . h($f['name']) . '" size="30" required>';
        echo ' <input type="submit" value="Save"> <button type="button" onclick="this.closest(\'.folder-rename-form\').style.display=\'none\'">Cancel</button>';
        echo '</form>';
        // Hidden delete form
        echo '<form id="del-' . $f['id'] . '" method="post" action="/admin/folders">';
        echo '<input type="hidden" name="todo" value="delete"><input type="hidden" name="id" value="' . $f['id'] . '">';
        echo '</form>';

        if (!empty($f['children'])) {
            renderFolderTree($f['children'], $depth + 1);
        }
    }
}

// Flat option list for parent folder select
function folderOptions(array $folders, ?int $excludeId = null, ?int $parentId = null, int $depth = 0): string {
    $html = '';
    foreach ($folders as $f) {
        $fpid = $f['parent_folder_id'] ? (int)$f['parent_folder_id'] : null;
        if ($fpid !== $parentId) continue;
        if ($excludeId !== null && (int)$f['id'] === $excludeId) continue;
        $indent = str_repeat('— ', $depth);
        $html .= '<option value="' . $f['id'] . '">' . $indent . h($f['name']) . '</option>';
        $html .= folderOptions($folders, $excludeId, (int)$f['id'], $depth + 1);
    }
    return $html;
}
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title">Folders</div>
        <a href="/admin/folders">Manage folders</a>
    </div>

    <div class="admin-main">
        <!-- Create new folder -->
        <form method="post" action="/admin/folders" class="admin-form" style="margin-bottom:1.5rem">
            <input type="hidden" name="todo" value="create">
            <h3>Create Folder</h3>
            <div class="form-row">
                <label>Name</label>
                <input type="text" name="name" size="30" required>
            </div>
            <div class="form-row">
                <label>Parent</label>
                <select name="parent_folder_id">
                    <option value="">(root)</option>
                    <?= folderOptions($allFolders) ?>
                </select>
            </div>
            <div class="form-actions"><input type="submit" value="Create"></div>
        </form>

        <hr>

        <!-- All folders -->
        <h3>Folders</h3>
        <?php if ($folderTree): ?>
            <div class="folder-tree">
                <?php renderFolderTree($folderTree); ?>
            </div>
        <?php else: ?>
            <p><small>No folders yet.</small></p>
        <?php endif; ?>

        <script>
        // Show rename form on click
        document.querySelectorAll('.folder-actions .btn-link').forEach(function(btn) {
            if (btn.textContent === 'Rename') {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var formId = btn.getAttribute('form');
                    var form = document.getElementById(formId);
                    if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
                });
            }
        });
        </script>
    </div>
</div>
