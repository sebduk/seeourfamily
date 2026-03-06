<?php

/**
 * Person tree view — combined ancestors (up) and descendants (down).
 *
 * Shows a person at the center, with all ancestors above and all
 * descendants below in a single unified vertical view.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();

$personUuid = $router->param('id') ?? '';

// Resolve UUID to integer id
if ($personUuid !== '' && !ctype_digit($personUuid)) {
    $stmt = $pdo->prepare('SELECT id FROM people WHERE uuid = ? AND family_id = ?');
    $stmt->execute([$personUuid, $fid]);
    $resolved = $stmt->fetch();
    $personId = $resolved ? (int)$resolved['id'] : 0;
} else {
    $personId = (int)$personUuid ?: 1;
}

// =========================================================================
// HELPER: format a person link
// =========================================================================
function treePPersonCell(?string $firstName, ?string $lastName, ?string $birth, ?string $death, string $uuid): string
{
    $name = h($firstName) . '&nbsp;' . h($lastName);
    $dates = h($birth) . '-' . h($death);
    return '<a href="/tree/' . h($uuid) . '">' . $name . '</a>'
         . ' (<a href="/person/' . h($uuid) . '">' . $dates . '</a>)';
}

// =========================================================================
// DATA: find the central person
// =========================================================================

$stmt = $pdo->prepare(
    'SELECT id, uuid, first_name, last_name, couple_id,
            IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
            IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
     FROM people WHERE id = ? AND family_id = ?'
);
$stmt->execute([$personId, $fid]);
$person = $stmt->fetch();

if (!$person) {
    echo '<p>Person not found.</p>';
    return;
}

$personName = $person['first_name'] . ' ' . $person['last_name'];

// =========================================================================
// ANCESTOR FUNCTIONS (upward)
// =========================================================================

function treePFindParentCouple(PDO $pdo, int $fid, int $personId): array|false
{
    $stmt = $pdo->prepare(
        'SELECT p.couple_id,
                c.id AS cid,
                IFNULL(DATE_FORMAT(c.start_date, "%Y"), "") AS wedding_year,
                p1.id AS p1_id, p1.uuid AS p1_uuid, p1.first_name AS p1_fn, p1.last_name AS p1_ln,
                IFNULL(DATE_FORMAT(p1.birth_date, "%Y"), "") AS p1_birth,
                IFNULL(DATE_FORMAT(p1.death_date, "%Y"), "") AS p1_death,
                p2.id AS p2_id, p2.uuid AS p2_uuid, p2.first_name AS p2_fn, p2.last_name AS p2_ln,
                IFNULL(DATE_FORMAT(p2.birth_date, "%Y"), "") AS p2_birth,
                IFNULL(DATE_FORMAT(p2.death_date, "%Y"), "") AS p2_death
         FROM people p
         JOIN couples c  ON c.id = p.couple_id AND c.family_id = ?
         JOIN people  p1 ON c.person1_id = p1.id
         JOIN people  p2 ON c.person2_id = p2.id
         WHERE p.id = ? AND p.family_id = ?'
    );
    $stmt->execute([$fid, $personId, $fid]);
    return $stmt->fetch() ?: false;
}

function treePRenderAncestors(PDO $pdo, int $fid, int $personId): void
{
    $couple = treePFindParentCouple($pdo, $fid, $personId);
    if (!$couple) return;

    echo '<div class="desc-children">';
    echo '<div class="desc-couple">';

    // Recurse FIRST so ancestors appear above
    treePRenderAncestors($pdo, $fid, (int)$couple['p1_id']);
    treePRenderAncestors($pdo, $fid, (int)$couple['p2_id']);

    // Then display this couple
    $wy = !empty($couple['wedding_year']) ? ' ' . h($couple['wedding_year']) : '';
    echo '<div class="desc-pair">';
    echo '<span>' . treePPersonCell($couple['p1_fn'], $couple['p1_ln'], $couple['p1_birth'], $couple['p1_death'], $couple['p1_uuid']) . '</span>';
    echo '<span class="desc-sep">&amp;' . $wy . '</span>';
    echo '<span>' . treePPersonCell($couple['p2_fn'], $couple['p2_ln'], $couple['p2_birth'], $couple['p2_death'], $couple['p2_uuid']) . '</span>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
}

// =========================================================================
// DESCENDANT FUNCTIONS (downward)
// =========================================================================

function treePFindCouples(PDO $pdo, int $fid, int $personId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id AS couple_id,
                IFNULL(DATE_FORMAT(c.start_date, "%Y"), "") AS wedding_year,
                p1.id AS p1_id, p1.uuid AS p1_uuid, p1.first_name AS p1_fn, p1.last_name AS p1_ln,
                IFNULL(DATE_FORMAT(p1.birth_date, "%Y"), "") AS p1_birth,
                IFNULL(DATE_FORMAT(p1.death_date, "%Y"), "") AS p1_death,
                p2.id AS p2_id, p2.uuid AS p2_uuid, p2.first_name AS p2_fn, p2.last_name AS p2_ln,
                IFNULL(DATE_FORMAT(p2.birth_date, "%Y"), "") AS p2_birth,
                IFNULL(DATE_FORMAT(p2.death_date, "%Y"), "") AS p2_death
         FROM couples c
         JOIN people p1 ON c.person1_id = p1.id
         JOIN people p2 ON c.person2_id = p2.id
         WHERE (c.person1_id = ? OR c.person2_id = ?) AND c.family_id = ?
         ORDER BY c.start_date'
    );
    $stmt->execute([$personId, $personId, $fid]);
    return $stmt->fetchAll();
}

function treePFindChildren(PDO $pdo, int $fid, int $coupleId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, uuid, first_name, last_name,
                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
         FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
    );
    $stmt->execute([$coupleId, $fid]);
    return $stmt->fetchAll();
}

function treePRenderDescendants(PDO $pdo, int $fid, int $coupleId): void
{
    $children = treePFindChildren($pdo, $fid, $coupleId);
    if (empty($children)) return;

    echo '<div class="desc-children">';
    foreach ($children as $child) {
        $childId = (int)$child['id'];
        $couples = treePFindCouples($pdo, $fid, $childId);

        if (!empty($couples)) {
            foreach ($couples as $couple) {
                $p1Direct = ((int)$couple['p1_id'] === $childId);
                $wy = !empty($couple['wedding_year']) ? ' ' . h($couple['wedding_year']) : '';
                echo '<div class="desc-couple">';
                echo '<div class="desc-pair">';
                echo '<span>' . ($p1Direct ? '<b>' : '') . treePPersonCell($couple['p1_fn'], $couple['p1_ln'], $couple['p1_birth'], $couple['p1_death'], $couple['p1_uuid']) . ($p1Direct ? '</b>' : '') . '</span>';
                echo '<span class="desc-sep">&amp;' . $wy . '</span>';
                echo '<span>' . (!$p1Direct ? '<b>' : '') . treePPersonCell($couple['p2_fn'], $couple['p2_ln'], $couple['p2_birth'], $couple['p2_death'], $couple['p2_uuid']) . (!$p1Direct ? '</b>' : '') . '</span>';
                echo '</div>';
                treePRenderDescendants($pdo, $fid, (int)$couple['couple_id']);
                echo '</div>';
            }
        } else {
            echo '<div class="desc-couple">';
            echo '<div class="desc-single">';
            echo '<b>' . treePPersonCell($child['first_name'], $child['last_name'], $child['birth'], $child['death'], $child['uuid']) . '</b>';
            echo '</div>';
            echo '</div>';
        }
    }
    echo '</div>';
}

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['tree_person'] ?? 'Full Tree' ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($person['uuid']) ?>"><?= $L['classic'] ?></a> .
        <a href="/treeT/<?= h($person['uuid']) ?>"><?= $L['tree_timeline'] ?? 'Timeline' ?></a>
    </span>
</div>

<?php
// =========================================================================
// RENDER: Ancestors above, person in center, descendants below
// =========================================================================

$couples = treePFindCouples($pdo, $fid, $personId);
?>

<div class="desc-tree">
    <!-- Ancestors (growing upward) -->
    <div class="desc-couple">
        <?php treePRenderAncestors($pdo, $fid, $personId); ?>
    </div>

    <!-- Central person (highlighted) -->
    <div class="treep-center">
<?php if (!empty($couples)): ?>
    <?php foreach ($couples as $couple):
        $p1Direct = ((int)$couple['p1_id'] === $personId);
        $wy = !empty($couple['wedding_year']) ? ' ' . h($couple['wedding_year']) : '';
    ?>
        <div class="desc-couple desc-root">
            <div class="desc-pair">
                <span><?= $p1Direct ? '<b>' : '' ?><?= treePPersonCell($couple['p1_fn'], $couple['p1_ln'], $couple['p1_birth'], $couple['p1_death'], $couple['p1_uuid']) ?><?= $p1Direct ? '</b>' : '' ?></span>
                <span class="desc-sep">&amp;<?= $wy ?></span>
                <span><?= !$p1Direct ? '<b>' : '' ?><?= treePPersonCell($couple['p2_fn'], $couple['p2_ln'], $couple['p2_birth'], $couple['p2_death'], $couple['p2_uuid']) ?><?= !$p1Direct ? '</b>' : '' ?></span>
            </div>
            <!-- Descendants (growing downward) -->
            <?php treePRenderDescendants($pdo, $fid, (int)$couple['couple_id']); ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
        <div class="desc-couple desc-root">
            <div class="desc-single">
                <b><?= treePPersonCell($person['first_name'], $person['last_name'], $person['birth'], $person['death'], $person['uuid']) ?></b>
            </div>
        </div>
<?php endif; ?>
    </div>
</div>
