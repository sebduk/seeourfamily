<?php

/**
 * Photo/video gallery page.
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

// Per-page: user choice via dropdown, persisted in cookie
$allowedPerPage = [25, 50, 75, 100];
$perPage = isset($_GET['pp']) ? (int)$_GET['pp'] : (isset($_COOKIE['sof_photos_pp']) ? (int)$_COOKIE['sof_photos_pp'] : 50);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}
if (isset($_GET['pp'])) {
    setcookie('sof_photos_pp', (string)$perPage, time() + 86400 * 365, '/');
}

$currentPage = max(1, (int)($_GET['page'] ?? 1));
$folderId = isset($_GET['folder']) ? (int)$_GET['folder'] : null;

// Media filter: images + video + audio (matches both legacy file_name and new stored_filename)
$mediaFilter = "(
    (p.file_name IS NOT NULL
        AND (LOWER(RIGHT(p.file_name, 3)) IN ('jpg','gif','png','mp4','avi','mp3','ogg','wav','mov','m4v','webp') OR LOWER(RIGHT(p.file_name, 4)) IN ('jpeg','webm')))
    OR (p.stored_filename IS NOT NULL AND p.file_name IS NULL
        AND (LOWER(RIGHT(p.stored_filename, 3)) IN ('jpg','gif','png','mp4','avi','mp3','ogg','wav','mov','m4v','webp') OR LOWER(RIGHT(p.stored_filename, 4)) IN ('jpeg','webm')))
)";

// Count total photos/videos
$countSql = "SELECT COUNT(*) FROM documents p WHERE p.family_id = ? AND $mediaFilter";
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
$totalItems = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $perPage;

// Load current page of photos/videos
$sql = "SELECT p.id, p.uuid, p.file_name, p.original_filename, p.stored_filename, p.description, p.doc_date, p.mime_type, p.poster_uuid
        FROM documents p
        WHERE p.family_id = ? AND $mediaFilter";
$params = [$fid];
if ($folderId !== null) {
    if ($folderId === 0) {
        $sql .= ' AND p.folder_id IS NULL';
    } else {
        $sql .= ' AND p.folder_id = ?';
        $params[] = $folderId;
    }
}
$sql .= ' ORDER BY p.doc_date, COALESCE(p.original_filename, p.file_name) LIMIT ? OFFSET ?';
$params[] = $perPage;
$params[] = $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

// Virtual folders (from DB)
$fStmt = $pdo->prepare(
    "SELECT f.id, f.name, COUNT(p.id) AS cnt
     FROM folders f
     LEFT JOIN documents p ON p.folder_id = f.id AND p.family_id = f.family_id
       AND $mediaFilter
     WHERE f.family_id = ? AND f.is_online = 1
     GROUP BY f.id, f.name
     ORDER BY f.name"
);
$fStmt->execute([$fid]);
$folders = $fStmt->fetchAll();

// Also count unfiled items
$unfStmt = $pdo->prepare("SELECT COUNT(*) FROM documents p WHERE p.family_id = ? AND p.folder_id IS NULL AND $mediaFilter");
$unfStmt->execute([$fid]);
$unfiledCount = (int)$unfStmt->fetchColumn();

// Build base query string for links (preserves folder + pp)
$qs = '';
if ($folderId !== null) $qs .= '&amp;folder=' . $folderId;
if ($perPage !== 50) $qs .= '&amp;pp=' . $perPage;

// Helper to build page URL
$pageUrl = function(int $pg) use ($qs): string {
    return '/photos?page=' . $pg . $qs;
};
?>

<!-- Top bar: [photo/video] tag + per-page dropdown -->
<div class="photo-pagination">
    <span class="media-type-tag">[photo/video]</span>
    <span class="pager">
        <?php if ($currentPage > 1): ?>
            <a href="<?= $pageUrl(1) ?>" title="First page">&laquo;</a>
            <a href="<?= $pageUrl($currentPage - 1) ?>" title="Previous page">&lsaquo;</a>
        <?php else: ?>
            <span class="pager-disabled">&laquo;</span>
            <span class="pager-disabled">&lsaquo;</span>
        <?php endif; ?>

        <span class="pager-info"><?= $currentPage ?> / <?= $totalPages ?></span>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= $pageUrl($currentPage + 1) ?>" title="Next page">&rsaquo;</a>
            <a href="<?= $pageUrl($totalPages) ?>" title="Last page">&raquo;</a>
        <?php else: ?>
            <span class="pager-disabled">&rsaquo;</span>
            <span class="pager-disabled">&raquo;</span>
        <?php endif; ?>
    </span>
    <span class="per-page-wrap">
        <select class="per-page-select" onchange="location.href='/photos?page=1<?= $folderId !== null ? '&amp;folder=' . $folderId : '' ?>&amp;pp='+this.value">
            <?php foreach ($allowedPerPage as $pp): ?>
                <option value="<?= $pp ?>"<?= $pp === $perPage ? ' selected' : '' ?>><?= $pp ?></option>
            <?php endforeach; ?>
        </select>
        / page
    </span>
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
        $docName = $photo['original_filename'] ?? $photo['file_name'] ?? '';
        $hoverTitle = pathinfo($docName, PATHINFO_FILENAME);
        $mime = \SeeOurFamily\Media::mimeFromRow($photo);
        $isVid = \SeeOurFamily\Media::isVideo($mime);
        $isAud = \SeeOurFamily\Media::isAudio($mime);
    ?>
        <a class="thumb" href="/photo/<?= h($photo['uuid']) ?>" title="<?= h($hoverTitle) ?>"<?= ($isVid || $isAud) ? ' style="position:relative"' : '' ?>>
            <?php if (($isVid || $isAud) && !empty($photo['poster_uuid'])): ?>
                <img src="/media/<?= h($photo['uuid']) ?>?poster=1&amp;tn=1" alt="<?= h($hoverTitle) ?>">
            <?php elseif ($isVid || $isAud): ?>
                <span style="display:flex;align-items:center;justify-content:center;width:100px;height:75px;background:#333;color:#fff;font-size:2rem"><?= $isVid ? '&#9654;' : '&#9835;' ?></span>
            <?php else: ?>
                <img src="/media/<?= h($photo['uuid']) ?>?tn=1" alt="<?= h($hoverTitle) ?>">
            <?php endif; ?>
            <?php if ($isVid): ?><span class="thumb-badge">&#9654;</span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Bottom pagination -->
<div class="photo-pagination">
    <span class="pager">
        <?php if ($currentPage > 1): ?>
            <a href="<?= $pageUrl(1) ?>" title="First page">&laquo;</a>
            <a href="<?= $pageUrl($currentPage - 1) ?>" title="Previous page">&lsaquo;</a>
        <?php else: ?>
            <span class="pager-disabled">&laquo;</span>
            <span class="pager-disabled">&lsaquo;</span>
        <?php endif; ?>

        <span class="pager-info"><?= $currentPage ?> / <?= $totalPages ?></span>

        <?php if ($currentPage < $totalPages): ?>
            <a href="<?= $pageUrl($currentPage + 1) ?>" title="Next page">&rsaquo;</a>
            <a href="<?= $pageUrl($totalPages) ?>" title="Last page">&raquo;</a>
        <?php else: ?>
            <span class="pager-disabled">&rsaquo;</span>
            <span class="pager-disabled">&raquo;</span>
        <?php endif; ?>
    </span>
    |
    <a href="/photos"><?= $L['pictures_all'] ?? 'View all pictures' ?></a> |
    <a href="/photos?sort=last"><?= $L['last_updates'] ?? 'Last updates' ?></a>
</div>
