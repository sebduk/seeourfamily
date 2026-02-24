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

$familyName = $family['name'] ?? '';
$imagePath  = '/Gene/File/' . urlencode($familyName) . '/Image/';

$photoUuid = $router->param('id') ?? $_GET['IDPhoto'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM photos WHERE uuid = ? AND family_id = ?');
$stmt->execute([$photoUuid, $fid]);
$photo = $stmt->fetch();

if (!$photo) {
    echo '<p>Photo not found.</p>';
    return;
}

$photoId = (int)$photo['id']; // integer for internal JOINs

// Date formatting
$dateStr = '';
if ($photo['photo_date']) {
    $d = new DateTime($photo['photo_date']);
    $prec = $photo['photo_precision'] ?? 'y';
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
     JOIN photo_person_link ppl ON ppl.person_id = p.id
     WHERE ppl.photo_id = ?
     ORDER BY p.last_name, p.first_name'
);
$stmt->execute([$photoId]);
$people = $stmt->fetchAll();

// Face tags
$stmt = $pdo->prepare(
    'SELECT pt.person_id, pt.x_pct, pt.y_pct, p.uuid, p.first_name, p.last_name
     FROM photo_tags pt
     JOIN people p ON pt.person_id = p.id
     WHERE pt.photo_id = ?'
);
$stmt->execute([$photoId]);
$tags = $stmt->fetchAll();
?>

<div class="photo-detail">
    <div class="photo-tags-container">
        <img src="<?= h($imagePath . $photo['file_name']) ?>" alt="">
        <?php foreach ($tags as $tag): ?>
        <a class="photo-tag" href="/person/<?= h($tag['uuid']) ?>"
           style="left:<?= $tag['x_pct'] ?>%;top:<?= $tag['y_pct'] ?>%">
            <?= h($tag['first_name']) ?> <?= h($tag['last_name']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($photo['description']): ?>
        <div class="photo-info"><?= nl2br(h($photo['description'])) ?></div>
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
</div>

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
