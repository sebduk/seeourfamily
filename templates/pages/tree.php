<?php

/**
 * Family tree page.
 *
 * Replaces Prog/View/arbre.asp + arbre.out.asp (combined).
 * CSS Grid layout replaces the old colspan=8/4/2/1 table.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();

$personId = (int)($router->param('id') ?? $_GET['IDPerso'] ?? 1);
$pos      = (int)($router->param('pos') ?? $_GET['Pos'] ?? 1);

// File path prefix for family images
$familyName = $family['name'] ?? '';
$imagePath  = '/Gene/File/' . urlencode($familyName) . '/Image/';

// =========================================================================
// HELPER: format a person cell
// =========================================================================
function personCell(?string $firstName, ?string $lastName, ?string $birth, ?string $death, int $id): string
{
    $name = h($firstName) . '&nbsp;' . h($lastName);
    $dates = h($birth) . '-' . h($death);
    return '<a href="/tree/' . $id . '">' . $name . '</a><br>'
         . '(<a href="/person/' . $id . '">' . $dates . '</a>)';
}

function unknownCell(): string
{
    return '?&nbsp;?<br>(?-?)';
}

// =========================================================================
// DATA: Build the ancestor structure (same logic as ASP's StartStructure + SetStructureUP)
// =========================================================================

/** Slots: 1-8 = gen -2 (great-grandparents), 11-14 = gen -1 (grandparents), 21-22 = gen 0 */
$struct = [];  // $struct[slot] = ['id'=>, 'couple_id'=>, 'first_name'=>, 'last_name'=>, 'birth'=>, 'death'=>]

/**
 * Find a couple by couple_id and fill parent slots.
 */
function fillParents(PDO $pdo, int $fid, ?int $coupleId, int $slotM, int $slotF): void
{
    global $struct;
    if (!$coupleId) return;

    $stmt = $pdo->prepare(
        'SELECT c.id AS couple_id,
                p1.id AS p1_id, p1.couple_id AS p1_couple, p1.first_name AS p1_fn, p1.last_name AS p1_ln,
                IFNULL(DATE_FORMAT(p1.birth_date, "%Y"), "") AS p1_birth,
                IFNULL(DATE_FORMAT(p1.death_date, "%Y"), "") AS p1_death,
                p2.id AS p2_id, p2.couple_id AS p2_couple, p2.first_name AS p2_fn, p2.last_name AS p2_ln,
                IFNULL(DATE_FORMAT(p2.birth_date, "%Y"), "") AS p2_birth,
                IFNULL(DATE_FORMAT(p2.death_date, "%Y"), "") AS p2_death
         FROM couples c
         JOIN people p1 ON c.person1_id = p1.id
         JOIN people p2 ON c.person2_id = p2.id
         WHERE c.id = ? AND c.family_id = ?'
    );
    $stmt->execute([$coupleId, $fid]);
    $row = $stmt->fetch();
    if (!$row) return;

    $struct[$slotM] = [
        'id' => (int)$row['p1_id'], 'couple_id' => $row['p1_couple'] ? (int)$row['p1_couple'] : null,
        'first_name' => $row['p1_fn'], 'last_name' => $row['p1_ln'],
        'birth' => $row['p1_birth'], 'death' => $row['p1_death'],
    ];
    $struct[$slotF] = [
        'id' => (int)$row['p2_id'], 'couple_id' => $row['p2_couple'] ? (int)$row['p2_couple'] : null,
        'first_name' => $row['p2_fn'], 'last_name' => $row['p2_ln'],
        'birth' => $row['p2_birth'], 'death' => $row['p2_death'],
    ];
}

// Level 0: find the central person and their couple
$stmt = $pdo->prepare(
    'SELECT c.id AS couple_id,
            p1.id AS p1_id, p1.couple_id AS p1_couple, p1.first_name AS p1_fn, p1.last_name AS p1_ln,
            IFNULL(DATE_FORMAT(p1.birth_date, "%Y"), "") AS p1_birth,
            IFNULL(DATE_FORMAT(p1.death_date, "%Y"), "") AS p1_death,
            p2.id AS p2_id, p2.couple_id AS p2_couple, p2.first_name AS p2_fn, p2.last_name AS p2_ln,
            IFNULL(DATE_FORMAT(p2.birth_date, "%Y"), "") AS p2_birth,
            IFNULL(DATE_FORMAT(p2.death_date, "%Y"), "") AS p2_death
     FROM couples c
     JOIN people p1 ON c.person1_id = p1.id
     JOIN people p2 ON c.person2_id = p2.id
     WHERE (c.person1_id = ? OR c.person2_id = ?) AND c.family_id = ?
     UNION ALL
     SELECT NULL AS couple_id,
            p.id AS p1_id, p.couple_id AS p1_couple, p.first_name AS p1_fn, p.last_name AS p1_ln,
            IFNULL(DATE_FORMAT(p.birth_date, "%Y"), "") AS p1_birth,
            IFNULL(DATE_FORMAT(p.death_date, "%Y"), "") AS p1_death,
            p.id AS p2_id, p.couple_id AS p2_couple, p.first_name AS p2_fn, p.last_name AS p2_ln,
            IFNULL(DATE_FORMAT(p.birth_date, "%Y"), "") AS p2_birth,
            IFNULL(DATE_FORMAT(p.death_date, "%Y"), "") AS p2_death
     FROM people p
     WHERE p.id = ? AND p.family_id = ?'
);
$stmt->execute([$personId, $personId, $fid, $personId, $fid]);
$allCouples = $stmt->fetchAll();

// Pick the couple at the requested position ($pos, 1-based)
$posMax = count($allCouples);
$idx = min($pos, $posMax) - 1;
$central = $allCouples[$idx] ?? $allCouples[0] ?? null;

if (!$central) {
    echo '<p>Person not found.</p>';
    return;
}

$coupleId = $central['couple_id'] ? (int)$central['couple_id'] : null;
$hasSpouse = ($coupleId !== null);

// Person name for the nav bar
$personName = ($personId == (int)$central['p1_id'])
    ? $central['p1_fn'] . ' ' . $central['p1_ln']
    : $central['p2_fn'] . ' ' . $central['p2_ln'];

// Fill slots 21, 22
$struct[21] = [
    'id' => (int)$central['p1_id'], 'couple_id' => $central['p1_couple'] ? (int)$central['p1_couple'] : null,
    'first_name' => $central['p1_fn'], 'last_name' => $central['p1_ln'],
    'birth' => $central['p1_birth'], 'death' => $central['p1_death'],
];
if ($hasSpouse) {
    $struct[22] = [
        'id' => (int)$central['p2_id'], 'couple_id' => $central['p2_couple'] ? (int)$central['p2_couple'] : null,
        'first_name' => $central['p2_fn'], 'last_name' => $central['p2_ln'],
        'birth' => $central['p2_birth'], 'death' => $central['p2_death'],
    ];
}

// Level -1: parents of slots 21, 22
if (!empty($struct[21]['couple_id'])) fillParents($pdo, $fid, $struct[21]['couple_id'], 11, 12);
if ($hasSpouse && !empty($struct[22]['couple_id'])) fillParents($pdo, $fid, $struct[22]['couple_id'], 13, 14);

// Level -2: grandparents
if (!empty($struct[11]['couple_id'])) fillParents($pdo, $fid, $struct[11]['couple_id'], 1, 2);
if (!empty($struct[12]['couple_id'])) fillParents($pdo, $fid, $struct[12]['couple_id'], 3, 4);
if (!empty($struct[13]['couple_id'])) fillParents($pdo, $fid, $struct[13]['couple_id'], 5, 6);
if (!empty($struct[14]['couple_id'])) fillParents($pdo, $fid, $struct[14]['couple_id'], 7, 8);

// Children of the central couple
$children = [];
$hasChildren = false;
if ($coupleId) {
    $stmt = $pdo->prepare(
        'SELECT id, first_name, last_name,
                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
         FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
    );
    $stmt->execute([$coupleId, $fid]);
    $children = $stmt->fetchAll();
    $hasChildren = count($children) > 0;
}

// =========================================================================
// TREE NAVIGATION BAR (was arbre.out.asp)
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['full_ascendance'] ?>
    <span class="nav-links">|
        <a href="/tree/<?= $personId ?>"><?= $L['classic'] ?></a> .
        <a href="/tree/<?= $personId ?>?dir=asc&amp;style=horizontal"><?= $L['horizontal'] ?></a> .
        <a href="/ascendants/<?= $personId ?>"><?= $L['vertical'] ?></a> .
        <a href="/tree/<?= $personId ?>?dir=asc&amp;style=table"><?= $L['table'] ?></a> .
        <a href="/tree/<?= $personId ?>?dir=asc&amp;style=excel"><?= $L['excel'] ?></a>
    </span>
    <?php if ($hasChildren): ?>
    <br>
    <strong>&nbsp;</strong>
    <?= $L['full_descendance'] ?>
    <span class="nav-links">|
        <a href="/tree/<?= $personId ?>?dir=desc&amp;style=horizontal"><?= $L['horizontal'] ?></a> .
        <a href="/descendants/<?= $personId ?>"><?= $L['vertical'] ?></a> .
        <a href="/tree/<?= $personId ?>?dir=desc&amp;style=table"><?= $L['table'] ?></a> .
        <a href="/tree/<?= $personId ?>?dir=desc&amp;style=excel"><?= $L['excel'] ?></a>
    </span>
    <?php endif; ?>
</div>

<?php
// =========================================================================
// ALTERNATE VIEW MODES: horizontal, table, excel
// =========================================================================
$style = $_GET['style'] ?? '';
$dir   = $_GET['dir']   ?? '';

if ($style !== '' && in_array($style, ['horizontal', 'table', 'excel'], true)):

// ---- Recursive data builders -------------------------------------------

/**
 * Collect all ancestors of a person into a flat array keyed by generation.
 * $result[gen][] = ['id'=>, 'first_name'=>, 'last_name'=>, 'birth'=>, 'death'=>, 'pos'=>]
 * pos = position within generation (0-based, binary tree order)
 */
function collectAncestors(PDO $pdo, int $fid, int $personId, int $gen, int $pos, array &$result, int $maxDepth = 12): void
{
    if ($gen > $maxDepth) return;
    $stmt = $pdo->prepare(
        'SELECT p.couple_id, c.id AS cid,
                p1.id AS p1_id, p1.first_name AS p1_fn, p1.last_name AS p1_ln,
                IFNULL(DATE_FORMAT(p1.birth_date, "%Y"), "") AS p1_birth,
                IFNULL(DATE_FORMAT(p1.death_date, "%Y"), "") AS p1_death,
                p2.id AS p2_id, p2.first_name AS p2_fn, p2.last_name AS p2_ln,
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

    $fatherPos = $pos * 2;
    $motherPos = $pos * 2 + 1;
    $result[$gen][$fatherPos] = ['id' => (int)$row['p1_id'], 'first_name' => $row['p1_fn'], 'last_name' => $row['p1_ln'], 'birth' => $row['p1_birth'], 'death' => $row['p1_death']];
    $result[$gen][$motherPos] = ['id' => (int)$row['p2_id'], 'first_name' => $row['p2_fn'], 'last_name' => $row['p2_ln'], 'birth' => $row['p2_birth'], 'death' => $row['p2_death']];

    collectAncestors($pdo, $fid, (int)$row['p1_id'], $gen + 1, $fatherPos, $result, $maxDepth);
    collectAncestors($pdo, $fid, (int)$row['p2_id'], $gen + 1, $motherPos, $result, $maxDepth);
}

/**
 * Collect all descendants of a person into a nested tree.
 * Returns array of children, each with 'person', 'spouse', 'children' keys.
 */
function collectDescendants(PDO $pdo, int $fid, int $personId, int $depth = 0, int $maxDepth = 12): array
{
    if ($depth > $maxDepth) return [];
    $couples = [];
    $stmt = $pdo->prepare(
        'SELECT c.id AS couple_id,
                p1.id AS p1_id, p1.first_name AS p1_fn, p1.last_name AS p1_ln,
                IFNULL(DATE_FORMAT(p1.birth_date, "%Y"), "") AS p1_birth,
                IFNULL(DATE_FORMAT(p1.death_date, "%Y"), "") AS p1_death,
                p2.id AS p2_id, p2.first_name AS p2_fn, p2.last_name AS p2_ln,
                IFNULL(DATE_FORMAT(p2.birth_date, "%Y"), "") AS p2_birth,
                IFNULL(DATE_FORMAT(p2.death_date, "%Y"), "") AS p2_death
         FROM couples c
         JOIN people p1 ON c.person1_id = p1.id
         JOIN people p2 ON c.person2_id = p2.id
         WHERE (c.person1_id = ? OR c.person2_id = ?) AND c.family_id = ?
         ORDER BY c.start_date'
    );
    $stmt->execute([$personId, $personId, $fid]);
    $coupleRows = $stmt->fetchAll();

    foreach ($coupleRows as $cr) {
        $spouseId = ((int)$cr['p1_id'] === $personId) ? (int)$cr['p2_id'] : (int)$cr['p1_id'];
        $spouse = ((int)$cr['p1_id'] === $personId)
            ? ['id' => (int)$cr['p2_id'], 'first_name' => $cr['p2_fn'], 'last_name' => $cr['p2_ln'], 'birth' => $cr['p2_birth'], 'death' => $cr['p2_death']]
            : ['id' => (int)$cr['p1_id'], 'first_name' => $cr['p1_fn'], 'last_name' => $cr['p1_ln'], 'birth' => $cr['p1_birth'], 'death' => $cr['p1_death']];

        $children = [];
        $cStmt = $pdo->prepare(
            'SELECT id, first_name, last_name,
                    IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                    IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
             FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
        );
        $cStmt->execute([(int)$cr['couple_id'], $fid]);
        $childRows = $cStmt->fetchAll();

        foreach ($childRows as $child) {
            $grandDesc = collectDescendants($pdo, $fid, (int)$child['id'], $depth + 1, $maxDepth);
            $children[] = ['person' => $child, 'descendants' => $grandDesc];
        }
        $couples[] = ['spouse' => $spouse, 'children' => $children];
    }
    return $couples;
}

/**
 * Flatten descendants into generation rows for table/excel views.
 * Each row: [gen, name, birth, death, id]
 */
function flattenDescendants(array $descendants, int $gen, array &$rows, array $personRow): void
{
    foreach ($descendants as $coupleInfo) {
        foreach ($coupleInfo['children'] as $child) {
            $p = $child['person'];
            $rows[] = [$gen, $p['first_name'] . ' ' . $p['last_name'], $p['birth'], $p['death'], (int)$p['id']];
            flattenDescendants($child['descendants'], $gen + 1, $rows, $p);
        }
    }
}

// ---- Build the data ----------------------------------------------------

$rootPerson = $struct[21]; // Central person (always in slot 21)

if ($dir === 'asc') {
    $ancestors = [];
    collectAncestors($pdo, $fid, $personId, 1, 0, $ancestors);
    $maxGen = !empty($ancestors) ? max(array_keys($ancestors)) : 0;
} else {
    $descTree = collectDescendants($pdo, $fid, $personId);
}

// ---- Excel / CSV export ------------------------------------------------
if ($style === 'excel'):
    header('Content-Type: text/csv; charset=utf-8');
    $direction = ($dir === 'asc') ? 'ascendants' : 'descendants';
    header('Content-Disposition: attachment; filename="' . $direction . '_' . $personId . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Generation', 'Name', 'Birth', 'Death', 'ID']);

    if ($dir === 'asc') {
        fputcsv($out, [0, $rootPerson['first_name'] . ' ' . $rootPerson['last_name'], $rootPerson['birth'], $rootPerson['death'], $rootPerson['id']]);
        for ($g = 1; $g <= $maxGen; $g++) {
            if (!isset($ancestors[$g])) continue;
            ksort($ancestors[$g]);
            foreach ($ancestors[$g] as $a) {
                fputcsv($out, [$g, $a['first_name'] . ' ' . $a['last_name'], $a['birth'], $a['death'], $a['id']]);
            }
        }
    } else {
        fputcsv($out, [0, $rootPerson['first_name'] . ' ' . $rootPerson['last_name'], $rootPerson['birth'], $rootPerson['death'], $rootPerson['id']]);
        $rows = [];
        flattenDescendants($descTree, 1, $rows, $rootPerson);
        foreach ($rows as $r) { fputcsv($out, $r); }
    }
    fclose($out);
    exit; // CSV output — skip all further rendering

// ---- Horizontal view ---------------------------------------------------
elseif ($style === 'horizontal'):
    if ($dir === 'asc'):
        // Pedigree chart: person on left, ancestors branching right via rowspan
        $totalRows = ($maxGen > 0) ? pow(2, $maxGen) : 1;
?>
<table class="data-table" style="border-collapse:collapse;">
<?php for ($row = 0; $row < $totalRows; $row++): ?>
<tr>
<?php
    // Column 0 (generation 0): the person — only on the middle row
    if ($row === 0):
        echo '<td rowspan="' . $totalRows . '" style="padding:4px 8px; vertical-align:middle; border-right:1px solid #ccc;">';
        echo '<b>' . personCell($rootPerson['first_name'], $rootPerson['last_name'], $rootPerson['birth'], $rootPerson['death'], $rootPerson['id']) . '</b>';
        echo '</td>';
    endif;

    // Columns 1..maxGen: each generation
    for ($g = 1; $g <= $maxGen; $g++):
        $slotsAtGen = pow(2, $g);
        $rowsPerSlot = $totalRows / $slotsAtGen;
        // This cell appears only once per slot
        if ($row % $rowsPerSlot === 0):
            $pos = (int)($row / $rowsPerSlot);
            echo '<td rowspan="' . (int)$rowsPerSlot . '" style="padding:4px 8px; vertical-align:middle; border-right:1px solid #ccc; font-size:' . max(7, 10 - $g) . 'pt;">';
            if (isset($ancestors[$g][$pos])) {
                $a = $ancestors[$g][$pos];
                echo personCell($a['first_name'], $a['last_name'], $a['birth'], $a['death'], $a['id']);
            } else {
                echo unknownCell();
            }
            echo '</td>';
        endif;
    endfor;
?>
</tr>
<?php endfor; ?>
</table>
<?php
    else: // desc horizontal
        // Descendant tree as indented nested list
        function renderDescHorizontal(array $descendants, int $depth = 1): void {
            if (empty($descendants)) return;
            echo '<table style="border-collapse:collapse; margin-left:' . ($depth > 1 ? '20' : '0') . 'px;">';
            foreach ($descendants as $coupleInfo) {
                if (!empty($coupleInfo['spouse'])):
                    $s = $coupleInfo['spouse'];
                    echo '<tr><td style="padding:2px 6px; color:#666; font-size:9pt;">';
                    echo '&amp; ' . personCell($s['first_name'], $s['last_name'], $s['birth'], $s['death'], $s['id']);
                    echo '</td></tr>';
                endif;
                foreach ($coupleInfo['children'] as $child) {
                    $p = $child['person'];
                    echo '<tr><td style="padding:2px 6px;">';
                    echo personCell($p['first_name'], $p['last_name'], $p['birth'], $p['death'], (int)$p['id']);
                    echo '</td></tr>';
                    if (!empty($child['descendants'])):
                        echo '<tr><td>';
                        renderDescHorizontal($child['descendants'], $depth + 1);
                        echo '</td></tr>';
                    endif;
                }
            }
            echo '</table>';
        }
?>
<table style="border-collapse:collapse;">
<tr><td style="padding:4px 8px;">
    <b><?= personCell($rootPerson['first_name'], $rootPerson['last_name'], $rootPerson['birth'], $rootPerson['death'], $rootPerson['id']) ?></b>
</td></tr>
<tr><td>
    <?php renderDescHorizontal($descTree); ?>
</td></tr>
</table>
<?php
    endif;

// ---- Table view --------------------------------------------------------
elseif ($style === 'table'):
    if ($dir === 'asc'):
?>
<table class="data-table">
<thead>
<tr><th>Gen</th><th colspan="<?= ($maxGen > 0) ? pow(2, $maxGen) : 1 ?>"><?= $L['full_ascendance'] ?></th></tr>
</thead>
<tbody>
<tr>
    <td>0</td>
    <td colspan="<?= ($maxGen > 0) ? pow(2, $maxGen) : 1 ?>">
        <b><?= personCell($rootPerson['first_name'], $rootPerson['last_name'], $rootPerson['birth'], $rootPerson['death'], $rootPerson['id']) ?></b>
    </td>
</tr>
<?php for ($g = 1; $g <= $maxGen; $g++):
    $slotsAtGen = pow(2, $g);
    $colSpan = max(1, (int)(pow(2, $maxGen) / $slotsAtGen));
?>
<tr>
    <td><?= $g ?></td>
    <?php for ($pos = 0; $pos < $slotsAtGen; $pos++): ?>
    <td colspan="<?= $colSpan ?>" style="font-size:<?= max(7, 10 - $g) ?>pt;">
        <?php if (isset($ancestors[$g][$pos])):
            $a = $ancestors[$g][$pos];
            echo personCell($a['first_name'], $a['last_name'], $a['birth'], $a['death'], $a['id']);
        else:
            echo unknownCell();
        endif; ?>
    </td>
    <?php endfor; ?>
</tr>
<?php endfor; ?>
</tbody>
</table>
<?php
    else: // desc table
        $rows = [];
        flattenDescendants($descTree, 1, $rows, $rootPerson);
?>
<table class="data-table">
<thead><tr><th>Gen</th><th>Name</th><th>Birth</th><th>Death</th></tr></thead>
<tbody>
<tr>
    <td>0</td>
    <td><b><?= personCell($rootPerson['first_name'], $rootPerson['last_name'], $rootPerson['birth'], $rootPerson['death'], $rootPerson['id']) ?></b></td>
    <td><?= h($rootPerson['birth']) ?></td>
    <td><?= h($rootPerson['death']) ?></td>
</tr>
<?php foreach ($rows as $r): ?>
<tr>
    <td><?= $r[0] ?></td>
    <td style="padding-left:<?= $r[0] * 20 ?>px;"><?= personCell(explode(' ', $r[1], 2)[0] ?? '', explode(' ', $r[1], 2)[1] ?? '', $r[2], $r[3], $r[4]) ?></td>
    <td><?= h($r[2]) ?></td>
    <td><?= h($r[3]) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php
    endif;
endif; // style switch

echo '<p style="font-size:8pt; color:#999; margin-top:12px;">' . $L['heavy_warning'] . '</p>';
return; // Skip classic grid rendering

endif; // $style !== ''
?>

<?php
// =========================================================================
// RENDER: TREE GRID
// =========================================================================

// Helper to render a slot
function renderSlot(int $slot, bool $alignRight = false): string
{
    global $struct;
    if (isset($struct[$slot])) {
        $s = $struct[$slot];
        return personCell($s['first_name'], $s['last_name'], $s['birth'], $s['death'], $s['id']);
    }
    return unknownCell();
}

if ($hasSpouse):
?>

<!-- 8-column grid: couple tree -->
<div class="tree-grid">
    <!-- Generation -2 (great-grandparents): 8 cells -->
    <?php for ($i = 1; $i <= 8; $i++): ?>
        <div class="person-cell tree-gen-2 <?= ($i % 2 !== 0) ? 'align-r' : 'align-l' ?>"><?= renderSlot($i) ?></div>
    <?php endfor; ?>

    <!-- Generation -1 (grandparents): 4 cells spanning 2 each -->
    <?php for ($i = 11; $i <= 14; $i++): ?>
        <div class="person-cell tree-gen-1 <?= ($i % 2 !== 0) ? 'align-r' : 'align-l' ?>"><?= renderSlot($i) ?></div>
    <?php endfor; ?>

    <!-- Generation 0 (central couple): 2 cells spanning 4 each -->
    <div class="person-cell tree-gen-0 align-r"><b><?= renderSlot(21) ?></b></div>
    <div class="person-cell tree-gen-0 align-l"><b><?= renderSlot(22) ?></b></div>

    <!-- Children row -->
    <div class="person-cell tree-children">
        <hr>
        <?php if ($hasChildren): ?>
        <div class="children-row">
            <?php foreach ($children as $child):
                // Find if child has a spouse
                $cStmt = $pdo->prepare(
                    'SELECT c.id AS couple_id,
                            p1.id AS p1_id, p1.first_name AS p1_fn, p1.last_name AS p1_ln,
                            IFNULL(DATE_FORMAT(p1.birth_date, "%Y"), "") AS p1_birth,
                            IFNULL(DATE_FORMAT(p1.death_date, "%Y"), "") AS p1_death,
                            p2.id AS p2_id, p2.first_name AS p2_fn, p2.last_name AS p2_ln,
                            IFNULL(DATE_FORMAT(p2.birth_date, "%Y"), "") AS p2_birth,
                            IFNULL(DATE_FORMAT(p2.death_date, "%Y"), "") AS p2_death
                     FROM couples c
                     JOIN people p1 ON c.person1_id = p1.id
                     JOIN people p2 ON c.person2_id = p2.id
                     WHERE (c.person1_id = ? OR c.person2_id = ?) AND c.family_id = ?
                     LIMIT 1'
                );
                $cStmt->execute([$child['id'], $child['id'], $fid]);
                $childCouple = $cStmt->fetch();
            ?>
            <div class="child-family">
                <?php if ($childCouple): ?>
                    <div class="couple">
                        <span class="align-r"><?= personCell($childCouple['p1_fn'], $childCouple['p1_ln'], $childCouple['p1_birth'], $childCouple['p1_death'], (int)$childCouple['p1_id']) ?></span>
                        <span><?= personCell($childCouple['p2_fn'], $childCouple['p2_ln'], $childCouple['p2_birth'], $childCouple['p2_death'], (int)$childCouple['p2_id']) ?></span>
                    </div>
                    <?php
                    // Grandchildren
                    $gcStmt = $pdo->prepare(
                        'SELECT id, first_name, last_name,
                                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                                IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
                         FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
                    );
                    $gcStmt->execute([(int)$childCouple['couple_id'], $fid]);
                    $grandchildren = $gcStmt->fetchAll();
                    if ($grandchildren): ?>
                    <div class="grandchildren">
                        <?php foreach ($grandchildren as $gc): ?>
                            <span><?= personCell($gc['first_name'], $gc['last_name'], $gc['birth'], $gc['death'], (int)$gc['id']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?= personCell($child['first_name'], $child['last_name'], $child['birth'], $child['death'], (int)$child['id']) ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: /* Single person, no spouse */ ?>

<!-- 4-column grid: single person tree -->
<div class="tree-grid-single">
    <?php for ($i = 1; $i <= 4; $i++): ?>
        <div class="person-cell tree-single-gen-2 <?= ($i % 2 !== 0) ? 'align-r' : 'align-l' ?>"><?= renderSlot($i) ?></div>
    <?php endfor; ?>

    <?php for ($i = 11; $i <= 12; $i++): ?>
        <div class="person-cell tree-single-gen-1 <?= ($i % 2 !== 0) ? 'align-r' : 'align-l' ?>"><?= renderSlot($i) ?></div>
    <?php endfor; ?>

    <div class="person-cell tree-single-gen-0"><b><?= renderSlot(21) ?></b></div>
</div>

<?php endif; ?>

<?php
// Multiple marriages
if ($posMax > 2):
?>
<div class="tree-marriages">
    <?php for ($i = 1; $i < $posMax; $i++): ?>
        .<?php if ($i !== $pos): ?>
            <a href="/tree/<?= $personId ?>?pos=<?= $i ?>"><?= $L['couple'] ?> <?= $i ?></a>
        <?php else: ?>
            <b><?= $L['couple'] ?> <?= $i ?></b>
        <?php endif; ?>
    <?php endfor; ?>
    .
</div>
<?php endif; ?>
