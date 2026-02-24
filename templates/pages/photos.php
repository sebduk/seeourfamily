<?php

/**
 * Photo gallery page.
 *
 * Replaces Prog/View/lstPhotos.asp.
 * CSS Grid (.photo-grid) replaces the 4x5 <table>.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();

$familyName = $family['name'] ?? '';

$perPage = 78;
$start   = max(0, (int)($_GET['start'] ?? 0));
$folder  = $_GET['folder'] ?? null;

// Image filter: matches both legacy file_name and new stored_filename entries
$imageFilter = "(
    (file_name IS NOT NULL AND (LOWER(RIGHT(file_name, 3)) IN ('jpg','gif','png') OR LOWER(RIGHT(file_name, 4)) = 'jpeg'))
    OR (stored_filename IS NOT NULL AND file_name IS NULL
        AND (LOWER(RIGHT(stored_filename, 3)) IN ('jpg','gif','png') OR LOWER(RIGHT(stored_filename, 4)) = 'jpeg'))
)";

// Count total photos
$countSql = "SELECT COUNT(*) FROM photos WHERE family_id = ? AND $imageFilter";
$countParams = [$fid];
if ($folder) {
    $countSql .= ' AND file_name LIKE ?';
    $countParams[] = $folder . '/%';
}
$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$totalPhotos = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalPhotos / $perPage));

// Load current page of photos
$sql = "SELECT id, uuid, file_name, original_filename, stored_filename, description, photo_date
        FROM photos
        WHERE family_id = ? AND $imageFilter";
$params = [$fid];
if ($folder) {
    $sql .= ' AND file_name LIKE ?';
    $params[] = $folder . '/%';
}
$sql .= ' ORDER BY photo_date, COALESCE(original_filename, file_name) LIMIT ? OFFSET ?';
$params[] = $perPage;
$params[] = $start;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

// Folders
$fStmt = $pdo->prepare(
    "SELECT DISTINCT SUBSTRING_INDEX(file_name, '/', 1) AS folder
     FROM photos
     WHERE family_id = ? AND file_name LIKE '%/%'
       AND (LOWER(RIGHT(file_name, 3)) IN ('jpg','gif','png')
         OR LOWER(RIGHT(file_name, 4)) = 'jpeg')
     ORDER BY folder"
);
$fStmt->execute([$fid]);
$folders = $fStmt->fetchAll();
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
            <a href="/photos?start=<?= $pageStart ?><?= $folder ? '&amp;folder=' . h($folder) : '' ?>"><?= $pageNum ?></a>.
        <?php endif; ?>
    <?php endfor; ?>
</div>

<?php if ($folders): ?>
<!-- Folder navigation -->
<div class="photo-folders">
    <?php foreach ($folders as $j => $f): ?>
        <?php if ($j > 0) echo ' | '; ?>
        <a href="/photos?folder=<?= h($f['folder']) ?>"><?= h($f['folder']) ?></a>
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
    <a href="/photos?all=1"><?= $L['pictures_all'] ?? 'View all pictures' ?></a> |
    <a href="/photos?sort=last"><?= $L['last_updates'] ?? 'Last updates' ?></a>
</div>
