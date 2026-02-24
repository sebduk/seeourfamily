<?php

/**
 * Descendants view — recursive tree going downward.
 *
 * Shows a person + spouse, their children + spouses,
 * grandchildren + spouses, and so on until no more descendants.
 *
 * Replaces Prog/View/arbre.desc.v.asp.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();

$personUuid = $router->param('id') ?? $_GET['IDPerso'] ?? '';

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
function descPersonCell(?string $firstName, ?string $lastName, ?string $birth, ?string $death, string $uuid): string
{
    $name = h($firstName) . '&nbsp;' . h($lastName);
    $dates = h($birth) . '-' . h($death);
    return '<a href="/tree/' . h($uuid) . '">' . $name . '</a>'
         . ' (<a href="/person/' . h($uuid) . '">' . $dates . '</a>)';
}

// =========================================================================
// DATA: find the central person + their couple(s)
// =========================================================================

// Get person info
$stmt = $pdo->prepare(
    'SELECT id, uuid, first_name, last_name,
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
// RECURSIVE FUNCTION: render a person's descendants
// =========================================================================

/**
 * Find all couples for a given person.
 * Returns array of couples with spouse info.
 */
function findCouples(PDO $pdo, int $fid, int $personId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.id AS couple_id,
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

/**
 * Find children of a couple.
 */
function findChildren(PDO $pdo, int $fid, int $coupleId): array
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

/**
 * Wrap a person cell in <b> if they are the direct descendant.
 */
function descBold(string $cell, bool $isDirect): string
{
    return $isDirect ? '<b>' . $cell . '</b>' : $cell;
}

/**
 * Render descendants recursively starting from a couple.
 * Every child is a direct descendant; their spouse is not.
 */
function renderDescendants(PDO $pdo, int $fid, int $coupleId): void
{
    $children = findChildren($pdo, $fid, $coupleId);
    if (empty($children)) return;

    echo '<div class="desc-children">';
    foreach ($children as $child) {
        $childId = (int)$child['id'];
        $couples = findCouples($pdo, $fid, $childId);

        if (!empty($couples)) {
            // Child has spouse(s): show each couple and recurse
            foreach ($couples as $couple) {
                $p1Direct = ((int)$couple['p1_id'] === $childId);
                echo '<div class="desc-couple">';
                echo '<div class="desc-pair">';
                echo '<span>' . descBold(descPersonCell($couple['p1_fn'], $couple['p1_ln'], $couple['p1_birth'], $couple['p1_death'], $couple['p1_uuid']), $p1Direct) . '</span>';
                echo '<span class="desc-sep">&amp;</span>';
                echo '<span>' . descBold(descPersonCell($couple['p2_fn'], $couple['p2_ln'], $couple['p2_birth'], $couple['p2_death'], $couple['p2_uuid']), !$p1Direct) . '</span>';
                echo '</div>';
                renderDescendants($pdo, $fid, (int)$couple['couple_id']);
                echo '</div>';
            }
        } else {
            // Child has no spouse: always a direct descendant
            echo '<div class="desc-couple">';
            echo '<div class="desc-single">';
            echo '<b>' . descPersonCell($child['first_name'], $child['last_name'], $child['birth'], $child['death'], $child['uuid']) . '</b>';
            echo '</div>';
            echo '</div>';
        }
    }
    echo '</div>';
}

// =========================================================================
// NAVIGATION BAR (same as tree view)
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['full_descendance'] ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($person['uuid']) ?>"><?= $L['classic'] ?></a>
    </span>
</div>

<?php
// =========================================================================
// RENDER: Show the person with all their couples, recursively
// =========================================================================

$couples = findCouples($pdo, $fid, $personId);

if (!empty($couples)):
    foreach ($couples as $couple):
        $p1Direct = ((int)$couple['p1_id'] === $personId);
    ?>
<div class="desc-tree">
    <div class="desc-couple desc-root">
        <div class="desc-pair">
            <span><?= descBold(descPersonCell($couple['p1_fn'], $couple['p1_ln'], $couple['p1_birth'], $couple['p1_death'], $couple['p1_uuid']), $p1Direct) ?></span>
            <span class="desc-sep">&amp;</span>
            <span><?= descBold(descPersonCell($couple['p2_fn'], $couple['p2_ln'], $couple['p2_birth'], $couple['p2_death'], $couple['p2_uuid']), !$p1Direct) ?></span>
        </div>
        <?php renderDescendants($pdo, $fid, (int)$couple['couple_id']); ?>
    </div>
</div>
<?php endforeach;
else:
    // Person has no spouse — just show them
?>
<div class="desc-tree">
    <div class="desc-couple desc-root">
        <div class="desc-single">
            <b><?= descPersonCell($person['first_name'], $person['last_name'], $person['birth'], $person['death'], $person['uuid']) ?></b>
        </div>
    </div>
</div>
<?php endif; ?>
