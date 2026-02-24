<?php

/**
 * Photo gallery page.
 *
 * Replaces Prog/View/lstPhotos.asp.
 * CSS Grid (.photo-grid) replaces the 4x5 <table>.
 * Browsing by virtual folders (DB-driven) replaces file_name path parsing.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();

$familyName = $family['name'] ?? '';

$perPage = 78;
$start   = max(0, (int)($_GET['start'] ?? 0));
$folderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;

// Image filter: matches both legacy file_name and new stored_filename entries
$imageFilter = "(
    (p.file_name IS NOT NULL AND (LOWER(RIGHT(p.file_name, 3)) IN ('jpg','gif','png') OR LOWER(RIGHT(p.file_name, 4)) = 'jpeg'))
    OR (p.stored_filename IS NOT NULL AND p.file_name IS NULL
        AND (LOWER(RIGHT(p.stored_filename, 3)) IN ('jpg','gif','png') OR LOWER(RIGHT(p.stored_filename, 4)) = 'jpeg'))
)";

// Count total photos
$countSql = "SELECT COUNT(*) FROM photos p WHERE p.family_id = ? AND $imageFilter";
$countParams = [$fid];
if ($folderId !== null) {
    if ($folderId === 0) {
        $countSql .= ' AND p.folder_id IS NULL';
    } else {
        $countSql .= ' AND p.folder_id = ?';
        $countParams[] = $folderId;
    }
}
$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$totalPhotos = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalPhotos / $perPage));

// Load current page of photos
$sql = "SELECT p.id, p.uuid, p.file_name, p.original_filename, p.stored_filename, p.description, p.photo_date
        FROM photos p
        WHERE p.family_id = ? AND $imageFilter";
$params = [$fid];
if ($folderId !== null) {
    if ($folderId === 0) {
        $sql .= ' AND p.folder_id IS NULL';
    } else {
        $sql .= ' AND p.folder_id = ?';
        $params[] = $folderId;
    }
}
$sql .= ' ORDER BY p.photo_date, COALESCE(p.original_filename, p.file_name) LIMIT ? OFFSET ?';
$params[] = $perPage;
$params[] = $start;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

// Virtual folders (from DB)
$fStmt = $pdo->prepare(
    'SELECT f.id, f.name, COUNT(p.id) AS cnt
     FROM folders f
     LEFT JOIN photos p ON p.folder_id = f.id AND p.family_id = f.family_id
     WHERE f.family_id = ? AND f.type = ? AND f.is_online = 1
     GROUP BY f.id, f.name
     ORDER BY f.name'
);
$fStmt->execute([$fid, 'image']);
$folders = $fStmt->fetchAll();

// Also count unfiled photos
$unfStmt = $pdo->prepare("SELECT COUNT(*) FROM photos p WHERE p.family_id = ? AND p.folder_id IS NULL AND $imageFilter");
$unfStmt->execute([$fid]);
$unfiledCount = (int)$unfStmt->fetchColumn();

// Build folder query string for pagination
$folderQs = $folderId !== null ? '&amp;folder=' . $folderId : '';
?>

<!-- Pagination -->
<div class="photo-pagination">
    <?php for ($i = 0; $i < $totalPages; $i++):
        $pageStart = $i * $perPage;
        $pageNum = $i + 1;
    ?>
        <?php if ($pageStart === $start): ?>
            <b><?= $pageNum ?></b>.
        <?php else: ?>
            <a href="/photos?start=<?= $pageStart ?><?= $folderQs ?>"><?= $pageNum ?></a>.
        <?php endif; ?>
    <?php endfor; ?>
</div>

<?php if ($folders): ?>
<!-- Folder navigation -->
<div class="photo-folders">
    <a href="/photos"<?= $folderId === null ? ' class="active-folder"' : '' ?>><?= $L['pictures_all'] ?? 'All' ?></a>
    | <a href="/photos?folder=0"<?= $folderId === 0 ? ' class="active-folder"' : '' ?>>Unfiled (<?= $unfiledCount ?>)</a>
    <?php foreach ($folders as $f): ?>
        | <a href="/photos?folder=<?= $f['id'] ?>"<?= $folderId === (int)$f['id'] ? ' class="active-folder"' : '' ?>><?= h($f['name']) ?> (<?= $f['cnt'] ?>)</a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Thumbnail grid -->
<div class="photo-grid">
    <?php foreach ($photos as $photo):
        $alt = pathinfo($photo['original_filename'] ?? $photo['file_name'] ?? '', PATHINFO_FILENAME);
    ?>
        <a class="thumb" href="/photo/<?= h($photo['uuid']) ?>" title="<?= h($alt) ?>">
            <img src="/media/<?= h($photo['uuid']) ?>?tn=1" alt="<?= h($alt) ?>">
        </a>
    <?php endforeach; ?>
</div>

<!-- View All / Last -->
<div class="photo-pagination">
    <a href="/photos"><?= $L['pictures_all'] ?? 'View all pictures' ?></a> |
    <a href="/photos?sort=last"><?= $L['last_updates'] ?? 'Last updates' ?></a>
</div>
