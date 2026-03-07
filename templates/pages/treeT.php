<?php

/**
 * Timeline tree view — all ancestors and descendants sorted chronologically.
 *
 * Collects every person reachable from the central person (ancestors up,
 * descendants down) and displays them on a vertical timeline ordered by
 * birth year.
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
// DATA: find the central person
// =========================================================================

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
// COLLECT ALL RELATED PEOPLE (ancestors + descendants)
// =========================================================================

$collected = []; // id => ['id','uuid','first_name','last_name','birth','death','relation']
$visited = [];

/**
 * Add a person to the collection (dedup by id).
 */
function treeTAdd(array &$collected, array $p, string $relation, int $gen): void
{
    $id = (int)$p['id'];
    if (!isset($collected[$id])) {
        $collected[$id] = [
            'id' => $id,
            'uuid' => $p['uuid'],
            'first_name' => $p['first_name'],
            'last_name' => $p['last_name'],
            'birth' => $p['birth'] ?? '',
            'death' => $p['death'] ?? '',
            'relation' => $relation,
            'gen' => $gen,
        ];
    }
}

/**
 * Collect ancestors recursively.
 */
function treeTCollectAncestors(PDO $pdo, int $fid, int $personId, array &$collected, array &$visited, int $gen = 1): void
{
    if (isset($visited['a' . $personId]) || $gen > 20) return;
    $visited['a' . $personId] = true;

    $stmt = $pdo->prepare(
        'SELECT p.couple_id,
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
    $row = $stmt->fetch();
    if (!$row) return;

    $label = $gen === 1 ? 'parent' : 'ancestor (gen ' . $gen . ')';
    treeTAdd($collected, ['id' => $row['p1_id'], 'uuid' => $row['p1_uuid'], 'first_name' => $row['p1_fn'], 'last_name' => $row['p1_ln'], 'birth' => $row['p1_birth'], 'death' => $row['p1_death']], $label, $gen);
    treeTAdd($collected, ['id' => $row['p2_id'], 'uuid' => $row['p2_uuid'], 'first_name' => $row['p2_fn'], 'last_name' => $row['p2_ln'], 'birth' => $row['p2_birth'], 'death' => $row['p2_death']], $label, $gen);

    treeTCollectAncestors($pdo, $fid, (int)$row['p1_id'], $collected, $visited, $gen + 1);
    treeTCollectAncestors($pdo, $fid, (int)$row['p2_id'], $collected, $visited, $gen + 1);
}

/**
 * Collect descendants recursively.
 */
function treeTCollectDescendants(PDO $pdo, int $fid, int $personId, array &$collected, array &$visited, int $gen = 1): void
{
    if (isset($visited['d' . $personId]) || $gen > 20) return;
    $visited['d' . $personId] = true;

    // Find couples for this person
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
    $couples = $stmt->fetchAll();

    foreach ($couples as $couple) {
        // Add spouse (same generation as the person being processed: 1 - $gen)
        $spouseGen = 1 - $gen;
        if ((int)$couple['p1_id'] === $personId) {
            treeTAdd($collected, ['id' => $couple['p2_id'], 'uuid' => $couple['p2_uuid'], 'first_name' => $couple['p2_fn'], 'last_name' => $couple['p2_ln'], 'birth' => $couple['p2_birth'], 'death' => $couple['p2_death']], 'spouse', $spouseGen);
        } else {
            treeTAdd($collected, ['id' => $couple['p1_id'], 'uuid' => $couple['p1_uuid'], 'first_name' => $couple['p1_fn'], 'last_name' => $couple['p1_ln'], 'birth' => $couple['p1_birth'], 'death' => $couple['p1_death']], 'spouse', $spouseGen);
        }

        // Find children
        $cStmt = $pdo->prepare(
            'SELECT id, uuid, first_name, last_name,
                    IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                    IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
             FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
        );
        $cStmt->execute([(int)$couple['couple_id'], $fid]);
        $children = $cStmt->fetchAll();

        $label = $gen === 1 ? 'child' : 'descendant (gen ' . $gen . ')';
        foreach ($children as $child) {
            treeTAdd($collected, $child, $label, -$gen);
            treeTCollectDescendants($pdo, $fid, (int)$child['id'], $collected, $visited, $gen + 1);
        }
    }
}

/**
 * Collect siblings and their immediate families (spouses + children).
 */
function treeTCollectSiblingFamilies(PDO $pdo, int $fid, int $personId, array &$collected, array &$visited): void
{
    $stmt = $pdo->prepare('SELECT couple_id FROM people WHERE id = ? AND family_id = ?');
    $stmt->execute([$personId, $fid]);
    $row = $stmt->fetch();
    if (!$row || !$row['couple_id']) return;

    // Get siblings (same parent couple, excluding self)
    $stmt = $pdo->prepare(
        'SELECT id, uuid, first_name, last_name,
                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
         FROM people WHERE couple_id = ? AND family_id = ? AND id != ? ORDER BY couple_sort'
    );
    $stmt->execute([(int)$row['couple_id'], $fid, $personId]);
    $siblings = $stmt->fetchAll();

    foreach ($siblings as $sib) {
        treeTAdd($collected, $sib, 'sibling', 0);

        // Find sibling's couples (spouses + children)
        $sibId = (int)$sib['id'];
        $cStmt = $pdo->prepare(
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
        $cStmt->execute([$sibId, $sibId, $fid]);
        $couples = $cStmt->fetchAll();

        foreach ($couples as $couple) {
            // Add sibling's spouse
            if ((int)$couple['p1_id'] === $sibId) {
                treeTAdd($collected, ['id' => $couple['p2_id'], 'uuid' => $couple['p2_uuid'], 'first_name' => $couple['p2_fn'], 'last_name' => $couple['p2_ln'], 'birth' => $couple['p2_birth'], 'death' => $couple['p2_death']], "sibling's spouse", 0);
            } else {
                treeTAdd($collected, ['id' => $couple['p1_id'], 'uuid' => $couple['p1_uuid'], 'first_name' => $couple['p1_fn'], 'last_name' => $couple['p1_ln'], 'birth' => $couple['p1_birth'], 'death' => $couple['p1_death']], "sibling's spouse", 0);
            }

            // Find sibling's children (nephews/nieces)
            $chStmt = $pdo->prepare(
                'SELECT id, uuid, first_name, last_name,
                        IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                        IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
                 FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
            );
            $chStmt->execute([(int)$couple['couple_id'], $fid]);
            $children = $chStmt->fetchAll();

            foreach ($children as $child) {
                treeTAdd($collected, $child, 'nephew/niece', -1);
            }
        }
    }
}

// Add the central person
treeTAdd($collected, $person, 'self', 0);

// Collect ancestors, descendants, and sibling families
treeTCollectAncestors($pdo, $fid, $personId, $collected, $visited);
treeTCollectDescendants($pdo, $fid, $personId, $collected, $visited);
treeTCollectSiblingFamilies($pdo, $fid, $personId, $collected, $visited);

// Build a map of generation → earliest known birth year
$genEarliestYear = [];
foreach ($collected as $p) {
    if ($p['birth'] !== '') {
        $g = $p['gen'];
        $y = (int)$p['birth'];
        if (!isset($genEarliestYear[$g]) || $y < $genEarliestYear[$g]) {
            $genEarliestYear[$g] = $y;
        }
    }
}

// Group by decade; place unknown-date people in the decade of the
// earliest known person of the same generation (just before them).
$decades = [];
$unknown = [];  // truly unplaceable (no generation match)
foreach ($collected as $p) {
    if ($p['birth'] !== '') {
        $decade = (int)(floor((int)$p['birth'] / 10) * 10);
        $decades[$decade][] = $p;
    } else {
        $g = $p['gen'];
        if (isset($genEarliestYear[$g])) {
            $decade = (int)(floor($genEarliestYear[$g] / 10) * 10);
            $decades[$decade][] = $p;
        } else {
            $unknown[] = $p;
        }
    }
}
ksort($decades);

// Within each decade, sort: unknowns first (by last name), then known (by year, then last name)
foreach ($decades as &$people) {
    usort($people, function ($a, $b) {
        $aKnown = $a['birth'] !== '' ? 1 : 0;
        $bKnown = $b['birth'] !== '' ? 1 : 0;
        if ($aKnown !== $bKnown) return $aKnown - $bKnown; // unknowns first
        if ($aKnown && $bKnown) {
            $cmp = (int)$a['birth'] - (int)$b['birth'];
            if ($cmp !== 0) return $cmp;
        }
        return strcmp($a['last_name'], $b['last_name']);
    });
}
unset($people);

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['tree_timeline'] ?? 'Timeline' ?>
    — <?= count($collected) ?> <?= strtolower($L['individuals']) ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($person['uuid']) ?>"><?= $L['classic'] ?></a> .
        <a href="/treeP/<?= h($person['uuid']) ?>"><?= $L['tree_person'] ?? 'Full Tree' ?></a> .
        <a href="/treeTr/<?= h($person['uuid']) ?>"><?= $L['tree_treant'] ?? 'Treant' ?></a>
    </span>
</div>

<?php
// =========================================================================
// RENDER: Timeline
// =========================================================================
?>
<div class="treet-timeline">
<?php if (!empty($unknown)): /* Truly unplaceable unknowns (no generation peers) at the top */ ?>
    <div class="treet-decade">
        <div class="treet-decade-label">?</div>
        <div class="treet-decade-people">
        <?php foreach ($unknown as $p):
            $isSelf = ($p['relation'] === 'self');
        ?>
            <div class="treet-person<?= $isSelf ? ' treet-self' : '' ?>">
                <span class="treet-dates">?<?= $p['death'] !== '' ? '–' . h($p['death']) : '' ?></span>
                <?= $isSelf ? '<b>' : '' ?><a href="/treeT/<?= h($p['uuid']) ?>"><?= h($p['first_name']) ?>&nbsp;<?= h($p['last_name']) ?></a><?= $isSelf ? '</b>' : '' ?>
                <span class="treet-relation"><?= h($p['relation']) ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($decades as $decade => $people): ?>
    <div class="treet-decade">
        <div class="treet-decade-label"><?= $decade ?>s</div>
        <div class="treet-decade-people">
        <?php foreach ($people as $p):
            $isSelf = ($p['relation'] === 'self');
            $cls = 'treet-person';
            if ($isSelf) $cls .= ' treet-self';
            $dateDisplay = $p['birth'] !== '' ? h($p['birth']) : '?';
        ?>
            <div class="<?= $cls ?>">
                <span class="treet-dates"><?= $dateDisplay ?><?= $p['death'] !== '' ? '–' . h($p['death']) : '' ?></span>
                <?= $isSelf ? '<b>' : '' ?><a href="/treeT/<?= h($p['uuid']) ?>"><?= h($p['first_name']) ?>&nbsp;<?= h($p['last_name']) ?></a><?= $isSelf ? '</b>' : '' ?>
                <span class="treet-relation"><?= h($p['relation']) ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
