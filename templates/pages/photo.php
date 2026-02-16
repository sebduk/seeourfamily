<?php

/**
 * Single photo detail page.
 *
 * Replaces Prog/View/photo.asp.
 * Simple centered layout (.photo-detail) replaces <center>.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$months = $L['months'] ?? [];

$familyName = $family['name'] ?? '';
$imagePath  = '/Gene/File/' . urlencode($familyName) . '/Image/';

$photoId = (int)($router->param('id') ?? $_GET['IDPhoto'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND family_id = ?');
$stmt->execute([$photoId, $fid]);
$photo = $stmt->fetch();

if (!$photo) {
    echo '<p>Photo not found.</p>';
    return;
}

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
    'SELECT p.id, p.first_name, p.last_name,
            IFNULL(DATE_FORMAT(p.birth_date, "%Y"), "") AS birth,
            IFNULL(DATE_FORMAT(p.death_date, "%Y"), "") AS death
     FROM people p
     JOIN photo_person_link ppl ON ppl.person_id = p.id
     WHERE ppl.photo_id = ?
     ORDER BY p.last_name, p.first_name'
);
$stmt->execute([$photoId]);
$people = $stmt->fetchAll();
?>

<div class="photo-detail">
    <a href="javascript:history.back();">
        <img src="<?= h($imagePath . $photo['file_name']) ?>" alt="">
    </a>

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
                <a href="/person/<?= $p['id'] ?>" title="<?= $title ?>"><?= h($p['first_name']) ?> <?= h($p['last_name']) ?></a>
            <?php endforeach; ?>.
        </div>
    <?php endif; ?>
</div>
