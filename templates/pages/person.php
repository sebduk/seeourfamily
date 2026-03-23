<?php

/**
 * Person biography/detail page.
 *
 * Replaces Prog/View/bio.asp.
 * Uses CSS Grid (.bio-item, .bio-photo-item) instead of table colspan=4.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn, $labels
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$personUuid = $router->param('id') ?? '';
$months = $L['months'] ?? [];
$dateFormat = $family['date_format'] ?? 'dmy';

$familyName = $family['name'] ?? '';

// =========================================================================
// HELPERS
// =========================================================================

function formatFullDate(?string $dateStr, string $fmt, array $months): string
{
    if (!$dateStr) return '';
    $d = new DateTime($dateStr);
    $m = $months[(int)$d->format('n')] ?? $d->format('F');
    if ($fmt === 'mdy') {
        return $m . ' ' . $d->format('j') . ', ' . $d->format('Y');
    }
    return $d->format('j') . ' ' . $m . ' ' . $d->format('Y');
}

function formatPhotoDate(?string $dateStr, string $precision, array $months): string
{
    if (!$dateStr) return '';
    $d = new DateTime($dateStr);
    $y = $d->format('Y');
    $m = (int)$d->format('n');
    $day = (int)$d->format('j');
    $parts = [];
    if ($precision === 'ymd' || $precision === 'ym') {
        if ($precision === 'ymd' && $day > 0) $parts[] = $day;
        if ($m > 0 && isset($months[$m])) $parts[] = $months[$m];
    }
    $parts[] = $y;
    return '<b>(' . implode(' ', $parts) . ')</b> ';
}

// =========================================================================
// LOAD PERSON
// =========================================================================

$stmt = $pdo->prepare('SELECT * FROM people WHERE uuid = ? AND family_id = ?');
$stmt->execute([$personUuid, $fid]);
$person = $stmt->fetch();

if (!$person) {
    echo '<p>Person not found.</p>';
    return;
}

$personId = (int)$person['id']; // integer for internal JOINs
$personUuid = $person['uuid'];  // UUID for URLs
$isMale = (bool)($person['is_male'] ?? true);
$bornLabel = $isMale ? $L['born_m'] : $L['born_f'];
$diedLabel = $isMale ? $L['died_m'] : $L['died_f'];

// Sections: biography, comments, pictures, documents
$hasBio = !empty($person['biography']);

// Comments
$stmt = $pdo->prepare(
    'SELECT c.* FROM comments c
     JOIN comment_person_link cpl ON cpl.comment_id = c.id
     WHERE cpl.person_id = ? AND c.family_id = ?
     ORDER BY c.event_date'
);
$stmt->execute([$personId, $fid]);
$comments = $stmt->fetchAll();

// Pictures (images + video + audio)
$stmt = $pdo->prepare(
    "SELECT ph.* FROM documents ph
     JOIN document_person_link dpl ON dpl.document_id = ph.id
     WHERE dpl.person_id = ? AND ph.family_id = ?
       AND (
         (ph.file_name IS NOT NULL
           AND (LOWER(RIGHT(ph.file_name, 3)) IN ('jpg','gif','png','mp4','avi','mp3','ogg','wav') OR LOWER(RIGHT(ph.file_name, 4)) IN ('jpeg','webm')))
         OR (ph.stored_filename IS NOT NULL AND ph.file_name IS NULL
           AND (LOWER(RIGHT(ph.stored_filename, 3)) IN ('jpg','gif','png','mp4','avi','mp3','ogg','wav') OR LOWER(RIGHT(ph.stored_filename, 4)) IN ('jpeg','webm')))
       )
     ORDER BY ph.doc_date, COALESCE(ph.original_filename, ph.file_name)"
);
$stmt->execute([$personId, $fid]);
$pictures = $stmt->fetchAll();

// Store photo navigation list in session (for prev/next on individual photo page)
if ($pictures) {
    $_SESSION['photo_nav_list'] = array_column($pictures, 'uuid');
    $_SESSION['photo_nav_gallery_url'] = '/person/' . $personUuid . '#pic';
}

// Documents (non-image, non-video, non-audio files)
$stmt = $pdo->prepare(
    "SELECT ph.* FROM documents ph
     JOIN document_person_link dpl ON dpl.document_id = ph.id
     WHERE dpl.person_id = ? AND ph.family_id = ?
       AND (
         (ph.file_name IS NOT NULL
           AND LOWER(RIGHT(ph.file_name, 3)) NOT IN ('jpg','gif','png','mp4','avi','mp3','ogg','wav')
           AND LOWER(RIGHT(ph.file_name, 4)) NOT IN ('jpeg','webm'))
         OR (ph.stored_filename IS NOT NULL AND ph.file_name IS NULL
           AND LOWER(RIGHT(ph.stored_filename, 3)) NOT IN ('jpg','gif','png','mp4','avi','mp3','ogg','wav')
           AND LOWER(RIGHT(ph.stored_filename, 4)) NOT IN ('jpeg','webm'))
       )
     ORDER BY ph.doc_date, COALESCE(ph.original_filename, ph.file_name)"
);
$stmt->execute([$personId, $fid]);
$docs = $stmt->fetchAll();
?>

<!-- Bio header -->
<a id="top"></a>
<div class="bio-header">
    <h1><a href="/tree/<?= h($personUuid) ?>"><?= h($person['first_names'] ?? $person['first_name']) ?>&nbsp;<?= h($person['last_name']) ?></a></h1>
    <b>(<?php
        echo $person['birth_date'] ? formatFullDate($person['birth_date'], $dateFormat, $months) : h($person['birth_precision'] ?? '');
    ?>-<?php
        echo $person['death_date'] ? formatFullDate($person['death_date'], $dateFormat, $months) : h($person['death_precision'] ?? '');
    ?>)</b><br>
    <?php if ($person['birth_place']): ?>
        <?= $bornLabel ?>&nbsp;<?= h($person['birth_place']) ?>.&nbsp;
    <?php endif; ?>
    <?php if ($person['death_place']): ?>
        <?= $diedLabel ?>&nbsp;<?= h($person['death_place']) ?>.&nbsp;
    <?php endif; ?>
    <?php if ($person['email'] && !$person['death_date']): ?>
        <br>[<a href="/messages?forum=perso&amp;person=<?= h($personUuid) ?>">Email</a>]
    <?php endif; ?>
</div>

<!-- Section nav -->
<div class="bio-nav">
    <?php if ($hasBio): ?>&gt; <a href="#bio"><?= $L['biography'] ?></a>&nbsp;<?php endif; ?>
    <?php if ($comments): ?>&gt; <a href="#com"><?= $L['comments'] ?></a>&nbsp;<?php endif; ?>
    <?php if ($pictures): ?>&gt; <a href="#pic"><?= $L['pictures'] ?></a>&nbsp;<?php endif; ?>
    <?php if ($docs): ?>&gt; <a href="#doc"><?= $L['documents'] ?></a>&nbsp;<?php endif; ?>
</div>
<hr>

<?php if ($hasBio): ?>
<!-- Biography -->
<div class="bio-section" id="bio">
    <h2><?= $L['biography'] ?></h2>
    <div class="bio-item">
        <div class="bio-item-label">&nbsp;</div>
        <div><?= nl2br(h($person['biography'])) ?></div>
    </div>
    <a href="#top" class="to-top"><?= $L['top'] ?? 'top' ?></a>
    <hr>
</div>
<?php endif; ?>

<?php if ($comments): ?>
<!-- Comments -->
<div class="bio-section" id="com">
    <h2><?= $L['comments'] ?></h2>
    <?php foreach ($comments as $comment):
        // Other people linked to this comment
        $stmt = $pdo->prepare(
            'SELECT p.id, p.uuid, p.first_name, p.last_name FROM people p
             JOIN comment_person_link cpl ON cpl.person_id = p.id
             WHERE cpl.comment_id = ? AND p.id <> ?
             ORDER BY p.last_name, p.first_name'
        );
        $stmt->execute([$comment['id'], $personId]);
        $others = $stmt->fetchAll();
    ?>
    <div class="bio-item">
        <div class="bio-item-label"><?= h($comment['title'] ?? '') ?></div>
        <div>
            <?php if ($comment['event_date']): ?><b>(<?= h($comment['event_date']) ?>)</b> <?php endif; ?>
            <?php if ($comment['body']): ?><?= nl2br(h($comment['body'])) ?><br><?php endif; ?>
            <?php if ($others): ?>
                <i><?= $L['with'] ?>:
                <?php foreach ($others as $j => $o): ?>
                    <?php if ($j > 0) echo ', '; ?>
                    <a href="/person/<?= h($o['uuid']) ?>"><?= h($o['first_name']) ?>&nbsp;<?= h($o['last_name']) ?></a>
                <?php endforeach; ?>.</i>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <a href="#top" class="to-top"><?= $L['top'] ?? 'top' ?></a>
    <hr>
</div>
<?php endif; ?>

<?php if ($pictures): ?>
<!-- Pictures -->
<div class="bio-section" id="pic">
    <h2><?= $L['pictures'] ?></h2>
    <?php foreach ($pictures as $pic):
        $stmt = $pdo->prepare(
            'SELECT p.id, p.uuid, p.first_name, p.last_name FROM people p
             JOIN document_person_link dpl ON dpl.person_id = p.id
             WHERE dpl.document_id = ? AND p.id <> ?
             ORDER BY p.last_name, p.first_name'
        );
        $stmt->execute([$pic['id'], $personId]);
        $others = $stmt->fetchAll();

        $picMime = \SeeOurFamily\Media::mimeFromRow($pic);
        $picIsVid = \SeeOurFamily\Media::isVideo($picMime);
        $picIsAud = \SeeOurFamily\Media::isAudio($picMime);
    ?>
    <div class="bio-photo-item">
        <div class="thumb">
            <?php if ($picIsVid || $picIsAud): ?>
                <?php if (!empty($pic['poster_uuid'])): ?>
                    <a href="/photo/<?= h($pic['uuid']) ?>"><img src="/media/<?= h($pic['uuid']) ?>?poster=1&amp;tn=1" alt=""></a>
                <?php else: ?>
                    <a href="/photo/<?= h($pic['uuid']) ?>" style="display:flex;align-items:center;justify-content:center;width:100px;height:75px;background:#333;color:#fff;font-size:2rem;text-decoration:none"><?= $picIsVid ? '&#9654;' : '&#9835;' ?></a>
                <?php endif; ?>
            <?php else: ?>
                <a href="/photo/<?= h($pic['uuid']) ?>"><img src="/media/<?= h($pic['uuid']) ?>?tn=1" alt=""></a>
            <?php endif; ?>
        </div>
        <div>
            <?= formatPhotoDate($pic['doc_date'], $pic['doc_precision'] ?? 'y', $months) ?>
            <?php if ($pic['description']): ?><?= nl2br(h($pic['description'])) ?><br><?php endif; ?>
            <?php if ($others): ?>
                <i><?= $L['with'] ?>:
                <?php foreach ($others as $j => $o): ?>
                    <?php if ($j > 0) echo ', '; ?>
                    <a href="/person/<?= h($o['uuid']) ?>"><?= h($o['first_name']) ?>&nbsp;<?= h($o['last_name']) ?></a>
                <?php endforeach; ?>.</i>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <a href="#top" class="to-top"><?= $L['top'] ?? 'top' ?></a>
    <hr>
</div>
<?php endif; ?>

<?php if ($docs): ?>
<!-- Documents -->
<div class="bio-section" id="doc">
    <h2><?= $L['documents'] ?></h2>
    <?php foreach ($docs as $doc):
        $stmt = $pdo->prepare(
            'SELECT p.id, p.uuid, p.first_name, p.last_name FROM people p
             JOIN document_person_link dpl ON dpl.person_id = p.id
             WHERE dpl.document_id = ? AND p.id <> ?
             ORDER BY p.last_name, p.first_name'
        );
        $stmt->execute([$doc['id'], $personId]);
        $others = $stmt->fetchAll();

        $docFileName = $doc['original_filename'] ?? $doc['file_name'] ?? $doc['stored_filename'] ?? '';
        $ext = strtolower(pathinfo($docFileName, PATHINFO_EXTENSION));
        $knownIcons = ['doc','mdb','pdf','ppt','pps','txt','xls','zip'];
        $icon = in_array($ext, $knownIcons) ? $ext : 'other';
        $baseName = pathinfo($docFileName, PATHINFO_FILENAME);
    ?>
    <div class="bio-item">
        <div class="bio-item-label">
            <img src="/Image/Icon/<?= $icon ?>.gif" alt="<?= h($ext) ?>">
            <a href="/media/<?= h($doc['uuid']) ?>"><b><?= h($baseName) ?></b></a>
        </div>
        <div>
            <?php if ($doc['doc_date']): ?><b>(<?= h($doc['doc_date']) ?>)</b> <?php endif; ?>
            <?php if ($doc['description']): ?><?= nl2br(h($doc['description'])) ?><br><?php endif; ?>
            <?php if ($others): ?>
                <i><?= $L['with'] ?>:
                <?php foreach ($others as $j => $o): ?>
                    <?php if ($j > 0) echo ', '; ?>
                    <a href="/person/<?= h($o['uuid']) ?>"><?= h($o['first_name']) ?>&nbsp;<?= h($o['last_name']) ?></a>
                <?php endforeach; ?>.</i>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <a href="#top" class="to-top"><?= $L['top'] ?? 'top' ?></a>
    <hr>
</div>
<?php endif; ?>
