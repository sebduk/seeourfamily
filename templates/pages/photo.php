<?php

/**
 * Single photo detail page.
 *
 * Replaces Prog/View/photo.asp.
 * Shows photo with hover-reveal face tags and linked people.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$months = $L['months'] ?? [];

$photoUuid = $router->param('id') ?? '';

$stmt = $pdo->prepare('SELECT * FROM documents WHERE uuid = ? AND family_id = ?');
$stmt->execute([$photoUuid, $fid]);
$photo = $stmt->fetch();

if (!$photo) {
    echo '<p>Photo not found.</p>';
    return;
}

$photoId = (int)$photo['id']; // integer for internal JOINs
$photoMime = \SeeOurFamily\Media::mimeFromRow($photo);
$isVideoFile = \SeeOurFamily\Media::isVideo($photoMime);
$isAudioFile = \SeeOurFamily\Media::isAudio($photoMime);

// Folder name
$folderName = '';
if ($photo['folder_id']) {
    $stmt = $pdo->prepare('SELECT name FROM folders WHERE id = ? AND family_id = ?');
    $stmt->execute([$photo['folder_id'], $fid]);
    $folderName = $stmt->fetchColumn() ?: '';
}

// File name (without extension)
$docFileName = $photo['original_filename'] ?? $photo['file_name'] ?? $photo['stored_filename'] ?? '';
$displayFileName = pathinfo($docFileName, PATHINFO_FILENAME);

// Prev / next navigation from session
$prevUuid = null;
$nextUuid = null;
$galleryUrl = $_SESSION['photo_nav_gallery_url'] ?? '/photos';
$navList = $_SESSION['photo_nav_list'] ?? [];
if ($navList) {
    $pos = array_search($photoUuid, $navList);
    if ($pos !== false) {
        if ($pos > 0) $prevUuid = $navList[$pos - 1];
        if ($pos < count($navList) - 1) $nextUuid = $navList[$pos + 1];
    }
}

// Date formatting
$dateStr = '';
if ($photo['doc_date']) {
    $d = new DateTime($photo['doc_date']);
    $prec = $photo['doc_precision'] ?? 'y';
    $parts = [];
    if (($prec === 'ymd') && (int)$d->format('j') > 0) $parts[] = $d->format('j');
    if ($prec === 'ymd' || $prec === 'ym') {
        $m = (int)$d->format('n');
        if (isset($months[$m])) $parts[] = $months[$m];
    }
    $parts[] = $d->format('Y');
    $dateStr = '<b>(' . implode(' ', $parts) . ')</b>';
}

// People in the photo
$stmt = $pdo->prepare(
    'SELECT p.id, p.uuid, p.first_name, p.last_name,
            IFNULL(DATE_FORMAT(p.birth_date, "%Y"), "") AS birth,
            IFNULL(DATE_FORMAT(p.death_date, "%Y"), "") AS death
     FROM people p
     JOIN document_person_link dpl ON dpl.person_id = p.id
     WHERE dpl.document_id = ?
     ORDER BY p.last_name, p.first_name'
);
$stmt->execute([$photoId]);
$people = $stmt->fetchAll();

// Face tags
$stmt = $pdo->prepare(
    'SELECT dt.person_id, dt.x_pct, dt.y_pct, p.uuid, p.first_name, p.last_name
     FROM document_tags dt
     JOIN people p ON dt.person_id = p.id
     WHERE dt.document_id = ?'
);
$stmt->execute([$photoId]);
$tags = $stmt->fetchAll();
?>

<div class="photo-detail">
    <?php if ($isVideoFile): ?>
    <div class="media-player">
        <video controls preload="metadata" style="max-width:100%;max-height:80vh"
            <?php if (!empty($photo['poster_uuid'])): ?> poster="/media/<?= h($photo['uuid']) ?>?poster=1"<?php endif; ?>>
            <source src="/media/<?= h($photo['uuid']) ?>" type="<?= h($photoMime) ?>">
        </video>
    </div>
    <?php elseif ($isAudioFile): ?>
    <div class="media-player">
        <?php if (!empty($photo['poster_uuid'])): ?>
            <img src="/media/<?= h($photo['uuid']) ?>?poster=1" alt="" style="max-width:100%;max-height:60vh;display:block;margin-bottom:.5rem">
        <?php endif; ?>
        <audio controls preload="metadata" style="width:100%">
            <source src="/media/<?= h($photo['uuid']) ?>" type="<?= h($photoMime) ?>">
        </audio>
    </div>
    <?php else: ?>
    <div class="photo-tags-container">
        <img src="/media/<?= h($photo['uuid']) ?>" alt="">
        <?php foreach ($tags as $tag): ?>
        <a class="photo-tag" href="/person/<?= h($tag['uuid']) ?>"
           style="left:<?= $tag['x_pct'] ?>%;top:<?= $tag['y_pct'] ?>%">
            <?= h($tag['first_name']) ?> <?= h($tag['last_name']) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($photo['description']): ?>
        <div class="photo-info"><?= nl2br(h($photo['description'])) ?></div>
    <?php endif; ?>

    <?php if ($folderName || $displayFileName): ?>
        <div class="photo-info">
            <?php if ($folderName): ?><?= ($L['folder'] ?? 'Folder') ?>: <?= h($folderName) ?><?php endif; ?>
            <?php if ($folderName && $displayFileName): ?> — <?php endif; ?>
            <?php if ($displayFileName): ?><?= h($displayFileName) ?><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($dateStr): ?>
        <div class="photo-info"><?= $dateStr ?></div>
    <?php endif; ?>

    <?php if ($people): ?>
        <div class="photo-people">
            <?php foreach ($people as $i => $p):
                if ($i > 0) echo ', ';
                $title = '[' . h($p['birth']) . ($p['death'] ? '-' . h($p['death']) : '') . ']';
            ?>
                <a href="/person/<?= h($p['uuid']) ?>" title="<?= $title ?>"><?= h($p['first_name']) ?> <?= h($p['last_name']) ?></a>
            <?php endforeach; ?>.
        </div>
    <?php endif; ?>

    <div class="photo-nav-links">
        <?php if ($prevUuid): ?>
            <a href="/photo/<?= h($prevUuid) ?>"><?= $L['nav_prev'] ?? '&lt; prev' ?></a>
        <?php else: ?>
            <span class="photo-nav-disabled"><?= $L['nav_prev'] ?? '&lt; prev' ?></span>
        <?php endif; ?>
        &nbsp;|&nbsp;
        <a href="<?= h($galleryUrl) ?>"><?= $L['nav_gallery'] ?? 'gallery' ?></a>
        &nbsp;|&nbsp;
        <?php if ($nextUuid): ?>
            <a href="/photo/<?= h($nextUuid) ?>"><?= $L['nav_next'] ?? 'next &gt;' ?></a>
        <?php else: ?>
            <span class="photo-nav-disabled"><?= $L['nav_next'] ?? 'next &gt;' ?></span>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isVideoFile && !$isAudioFile): ?>
<script>
// Scale small images up to 2x their natural size, capped at 80vh
(function() {
    var img = document.querySelector('.photo-tags-container img');
    if (!img) return;
    function scaleUp() {
        var maxH = window.innerHeight * 0.8;
        var scale = Math.min(2, maxH / img.naturalHeight);
        if (scale > 1) {
            img.style.width = Math.round(img.naturalWidth * scale) + 'px';
            img.style.height = Math.round(img.naturalHeight * scale) + 'px';
        }
    }
    if (img.complete) scaleUp(); else img.addEventListener('load', scaleUp);
})();
</script>
<?php endif; ?>
