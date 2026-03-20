<?php

/**
 * Family tree visualisation using donatso/family-chart (D3-based).
 *
 * Collects ancestors + descendants and renders an interactive,
 * zoomable/pannable tree via the family-chart library.
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
// DATA: Collect all people, couples, and relationships
// =========================================================================

$fcPeople = [];       // id => {id, uuid, fn, ln, birth, death, gender}
$fcCouples = [];      // coupleId => {id, p1, p2}
$fcCoupleChildren = []; // coupleId => [childId, ...]
$fcPersonParentCouple = []; // personId => coupleId
$fcVisitedUp = [];
$fcVisitedDown = [];

function fcAddPerson(PDO $pdo, int $fid, int $pid, array &$fcPeople): void
{
    if (isset($fcPeople[$pid])) return;
    $stmt = $pdo->prepare(
        'SELECT id, uuid, first_name, last_name, couple_id, is_male,
                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
         FROM people WHERE id = ? AND family_id = ?'
    );
    $stmt->execute([$pid, $fid]);
    $row = $stmt->fetch();
    if ($row) {
        $fcPeople[$pid] = [
            'id' => (int)$row['id'],
            'uuid' => $row['uuid'],
            'fn' => $row['first_name'],
            'ln' => $row['last_name'],
            'birth' => $row['birth'],
            'death' => $row['death'],
            'gender' => $row['is_male'] === null ? 'M' : ((int)$row['is_male'] ? 'M' : 'F'),
        ];
    }
}

function fcCollectUp(PDO $pdo, int $fid, int $pid, array &$fcPeople, array &$fcCouples, array &$fcCoupleChildren, array &$fcPersonParentCouple, array &$fcVisitedUp, int $depth = 0): void
{
    if (isset($fcVisitedUp[$pid]) || $depth > 12) return;
    $fcVisitedUp[$pid] = true;

    $stmt = $pdo->prepare(
        'SELECT p.couple_id,
                c.id AS cid,
                c.person1_id AS p1id, c.person2_id AS p2id
         FROM people p
         JOIN couples c ON c.id = p.couple_id AND c.family_id = ?
         WHERE p.id = ? AND p.family_id = ?'
    );
    $stmt->execute([$fid, $pid, $fid]);
    $row = $stmt->fetch();
    if (!$row) return;

    $cid = (int)$row['cid'];
    $p1id = (int)$row['p1id'];
    $p2id = (int)$row['p2id'];

    $fcPersonParentCouple[$pid] = $cid;

    if (!isset($fcCouples[$cid])) {
        $fcCouples[$cid] = ['id' => $cid, 'p1' => $p1id, 'p2' => $p2id];
    }

    if (!isset($fcCoupleChildren[$cid])) {
        $cStmt = $pdo->prepare(
            'SELECT id FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
        );
        $cStmt->execute([$cid, $fid]);
        $fcCoupleChildren[$cid] = array_map(fn($r) => (int)$r['id'], $cStmt->fetchAll());
    }

    fcAddPerson($pdo, $fid, $p1id, $fcPeople);
    fcAddPerson($pdo, $fid, $p2id, $fcPeople);

    fcCollectUp($pdo, $fid, $p1id, $fcPeople, $fcCouples, $fcCoupleChildren, $fcPersonParentCouple, $fcVisitedUp, $depth + 1);
    fcCollectUp($pdo, $fid, $p2id, $fcPeople, $fcCouples, $fcCoupleChildren, $fcPersonParentCouple, $fcVisitedUp, $depth + 1);
}

function fcCollectDown(PDO $pdo, int $fid, int $pid, array &$fcPeople, array &$fcCouples, array &$fcCoupleChildren, array &$fcVisitedDown, int $depth = 0): void
{
    if (isset($fcVisitedDown[$pid]) || $depth > 15) return;
    $fcVisitedDown[$pid] = true;

    $stmt = $pdo->prepare(
        'SELECT c.id AS cid,
                c.person1_id AS p1id, c.person2_id AS p2id
         FROM couples c
         WHERE (c.person1_id = ? OR c.person2_id = ?) AND c.family_id = ?
         ORDER BY c.start_date'
    );
    $stmt->execute([$pid, $pid, $fid]);
    $couples = $stmt->fetchAll();

    foreach ($couples as $cr) {
        $cid = (int)$cr['cid'];
        $p1id = (int)$cr['p1id'];
        $p2id = (int)$cr['p2id'];

        if (!isset($fcCouples[$cid])) {
            $fcCouples[$cid] = ['id' => $cid, 'p1' => $p1id, 'p2' => $p2id];
        }

        fcAddPerson($pdo, $fid, $p1id, $fcPeople);
        fcAddPerson($pdo, $fid, $p2id, $fcPeople);

        if (!isset($fcCoupleChildren[$cid])) {
            $cStmt = $pdo->prepare(
                'SELECT id FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
            );
            $cStmt->execute([$cid, $fid]);
            $fcCoupleChildren[$cid] = array_map(fn($r) => (int)$r['id'], $cStmt->fetchAll());
        }

        foreach ($fcCoupleChildren[$cid] as $childId) {
            fcAddPerson($pdo, $fid, $childId, $fcPeople);
            fcCollectDown($pdo, $fid, $childId, $fcPeople, $fcCouples, $fcCoupleChildren, $fcVisitedDown, $depth + 1);
        }
    }
}

// Collect the central person
fcAddPerson($pdo, $fid, $personId, $fcPeople);

// Collect ancestors (upward)
fcCollectUp($pdo, $fid, $personId, $fcPeople, $fcCouples, $fcCoupleChildren, $fcPersonParentCouple, $fcVisitedUp);

// Collect descendants (downward)
fcCollectDown($pdo, $fid, $personId, $fcPeople, $fcCouples, $fcCoupleChildren, $fcVisitedDown);

// Also collect ancestors of spouse(s)
$spouseStmt = $pdo->prepare(
    'SELECT c.person1_id AS p1id, c.person2_id AS p2id
     FROM couples c
     WHERE (c.person1_id = ? OR c.person2_id = ?) AND c.family_id = ?'
);
$spouseStmt->execute([$personId, $personId, $fid]);
$spouseRows = $spouseStmt->fetchAll();
foreach ($spouseRows as $sr) {
    $spouseId = ((int)$sr['p1id'] === $personId) ? (int)$sr['p2id'] : (int)$sr['p1id'];
    fcCollectUp($pdo, $fid, $spouseId, $fcPeople, $fcCouples, $fcCoupleChildren, $fcPersonParentCouple, $fcVisitedUp);
}

$person = $fcPeople[$personId] ?? null;
if (!$person) {
    echo '<p>Person not found.</p>';
    return;
}

$personName = $person['fn'] . ' ' . $person['ln'];

// =========================================================================
// TRANSFORM: Convert relational data to family-chart format
// =========================================================================
// family-chart expects: [{id, data: {first name, last name, birthday, gender}, rels: {spouses:[], children:[], parents:[]}}, ...]

// Build relationship maps
$personSpouses = [];   // personId => [spouseId, ...]
$personChildren = [];  // personId => [childId, ...]
$personParents = [];   // personId => [parentId, ...]

foreach ($fcCouples as $couple) {
    $p1 = $couple['p1'];
    $p2 = $couple['p2'];

    // Spouses
    $personSpouses[$p1][] = $p2;
    $personSpouses[$p2][] = $p1;

    // Children of this couple
    $kids = $fcCoupleChildren[$couple['id']] ?? [];
    foreach ($kids as $kid) {
        $personChildren[$p1][] = $kid;
        $personChildren[$p2][] = $kid;
        $personParents[$kid][] = $p1;
        $personParents[$kid][] = $p2;
    }
}

// Build family-chart data array
$fcData = [];
foreach ($fcPeople as $pid => $p) {
    $node = [
        'id' => (string)$pid,
        'data' => [
            'first name' => $p['fn'],
            'last name' => $p['ln'],
            'birthday' => $p['birth'],
            'gender' => $p['gender'],
            'uuid' => $p['uuid'],
        ],
        'rels' => [],
    ];

    if (!empty($personSpouses[$pid])) {
        $spouses = array_filter(array_unique($personSpouses[$pid]), fn($id) => isset($fcPeople[$id]));
        if ($spouses) $node['rels']['spouses'] = array_values(array_map('strval', $spouses));
    }
    if (!empty($personChildren[$pid])) {
        $children = array_filter(array_unique($personChildren[$pid]), fn($id) => isset($fcPeople[$id]));
        if ($children) $node['rels']['children'] = array_values(array_map('strval', $children));
    }
    if (!empty($personParents[$pid])) {
        $parents = array_filter(array_unique($personParents[$pid]), fn($id) => isset($fcPeople[$id]));
        // family-chart expects max 2 parents
        if ($parents) $node['rels']['parents'] = array_values(array_map('strval', array_slice($parents, 0, 2)));
    }

    // Ensure rels serialises as a JSON object (not array) even when empty
    if (empty($node['rels'])) {
        $node['rels'] = new \stdClass();
    }

    $fcData[] = $node;
}

$jsonData = json_encode($fcData, JSON_HEX_TAG | JSON_HEX_AMP);
$jsonMainId = json_encode((string)$personId, JSON_HEX_TAG);

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<?php $treeNavUuid = h($person['uuid']); $treeNavCurrent = 'family_chart'; require __DIR__ . '/../_tree-nav.php'; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/family-chart@0.9.0/dist/styles/family-chart.css">
<div class="f3" id="FamilyChart"></div>

<script type="module">
import * as f3 from 'https://esm.sh/family-chart@0.9.0';

(function() {
    'use strict';

    var DATA = <?= $jsonData ?>;
    var MAIN_ID = <?= $jsonMainId ?>;

    if (!DATA || DATA.length === 0) {
        document.getElementById('FamilyChart').innerHTML = '<p>No tree data available.</p>';
        return;
    }

    // Mark the main/central person in the data
    DATA.forEach(function(d) {
        if (d.id === MAIN_ID) {
            d.main = true;
        }
    });

    // Create the chart
    var f3Chart = f3.createChart('#FamilyChart', DATA)
        .setTransitionTime(800);

    // Configure card display
    var f3Card = f3Chart.setCardHtml()
        .setCardDisplay([['first name', 'last name'], ['birthday']]);

    // Click handler: navigate to that person's tree
    f3Card.setOnCardClick(function(e, d) {
        if (d && d.data && d.data.data && d.data.data.uuid) {
            window.location.href = '/treeFC/' + d.data.data.uuid;
        }
    });

    // Right-click: go to person page
    document.getElementById('FamilyChart').addEventListener('contextmenu', function(e) {
        var cardEl = e.target.closest('.card');
        if (!cardEl) return;
        var nodeId = cardEl.getAttribute('data-id') || cardEl.id;
        if (!nodeId) return;
        var person = DATA.find(function(p) { return p.id === nodeId; });
        if (person && person.data && person.data.uuid) {
            e.preventDefault();
            window.location.href = '/person/' + person.data.uuid;
        }
    });

    // Render
    f3Chart.updateTree({ initial: true });

})();
</script>
