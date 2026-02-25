<?php

/**
 * Ascendants view — recursive tree going upward.
 *
 * Shows a person, their parents, grandparents, great-grandparents,
 * and so on until no more ancestors are found.
 * Unknown ancestors are simply omitted (no question marks).
 *
 * Replaces Prog/View/arbre.asc.v.asp.
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
function ascPersonCell(?string $firstName, ?string $lastName, ?string $birth, ?string $death, string $uuid): string
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
// RECURSIVE FUNCTION: render a person's ancestors
// =========================================================================

/**
 * Find a person's parent couple via their couple_id.
 * Returns the couple row or false if no known parents.
 */
function findParentCouple(PDO $pdo, int $fid, int $personId): array|false
{
    $stmt = $pdo->prepare(
        'SELECT p.couple_id,
                c.id AS cid,
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

/**
 * Render ancestors recursively for a given person.
 * Ancestors are rendered ABOVE the couple (recurse first, display after)
 * so the tree visually grows upward. No bold — only the root person is bold.
 */
function renderAncestors(PDO $pdo, int $fid, int $personId): void
{
    $couple = findParentCouple($pdo, $fid, $personId);
    if (!$couple) return;

    echo '<div class="desc-children">';
    echo '<div class="desc-couple">';

    // Recurse FIRST so ancestors appear above
    renderAncestors($pdo, $fid, (int)$couple['p1_id']);
    renderAncestors($pdo, $fid, (int)$couple['p2_id']);

    // Then display this couple
    echo '<div class="desc-pair">';
    echo '<span>' . ascPersonCell($couple['p1_fn'], $couple['p1_ln'], $couple['p1_birth'], $couple['p1_death'], $couple['p1_uuid']) . '</span>';
    echo '<span class="desc-sep">&amp;</span>';
    echo '<span>' . ascPersonCell($couple['p2_fn'], $couple['p2_ln'], $couple['p2_birth'], $couple['p2_death'], $couple['p2_uuid']) . '</span>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
}

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['full_ascendance'] ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($person['uuid']) ?>"><?= $L['classic'] ?></a>
    </span>
</div>

<?php
// =========================================================================
// RENDER: Show the person, then recurse upward
// =========================================================================
?>
<div class="desc-tree">
    <div class="desc-couple desc-root">
        <?php renderAncestors($pdo, $fid, $personId); ?>
        <div class="desc-single">
            <b><?= ascPersonCell($person['first_name'], $person['last_name'], $person['birth'], $person['death'], $person['uuid']) ?></b>
        </div>
    </div>
</div>
