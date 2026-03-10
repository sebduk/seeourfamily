<?php

/**
 * Family tree visualisation using Treant.js.
 *
 * Reuses the same data-collection approach as treeP (ancestors up,
 * descendants down) but renders through Treant.js + Raphael SVG
 * connectors instead of jsPlumb.
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
// DATA: Collect the full tree as flat arrays
// =========================================================================

$treePeople = [];
$treeCouples = [];
$coupleChildren = [];
$personParentCouple = [];
$visitedUp = [];
$visitedDown = [];

function treeTrAddPerson(PDO $pdo, int $fid, int $pid, array &$treePeople): void
{
    if (isset($treePeople[$pid])) return;
    $stmt = $pdo->prepare(
        'SELECT id, uuid, first_name, last_name, couple_id,
                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
         FROM people WHERE id = ? AND family_id = ?'
    );
    $stmt->execute([$pid, $fid]);
    $row = $stmt->fetch();
    if ($row) {
        $treePeople[$pid] = [
            'id' => (int)$row['id'],
            'uuid' => $row['uuid'],
            'fn' => $row['first_name'],
            'ln' => $row['last_name'],
            'birth' => $row['birth'],
            'death' => $row['death'],
        ];
    }
}

function treeTrCollectUp(PDO $pdo, int $fid, int $pid, array &$treePeople, array &$treeCouples, array &$coupleChildren, array &$personParentCouple, array &$visitedUp, int $depth = 0): void
{
    if (isset($visitedUp[$pid]) || $depth > 1) return;
    $visitedUp[$pid] = true;

    $stmt = $pdo->prepare(
        'SELECT p.couple_id,
                c.id AS cid,
                IFNULL(DATE_FORMAT(c.start_date, "%Y"), "") AS wy,
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

    $personParentCouple[$pid] = $cid;

    if (!isset($treeCouples[$cid])) {
        $treeCouples[$cid] = ['id' => $cid, 'p1' => $p1id, 'p2' => $p2id, 'wy' => $row['wy']];
    }

    if (!isset($coupleChildren[$cid])) {
        $cStmt = $pdo->prepare(
            'SELECT id FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
        );
        $cStmt->execute([$cid, $fid]);
        $coupleChildren[$cid] = array_map(fn($r) => (int)$r['id'], $cStmt->fetchAll());
    }

    treeTrAddPerson($pdo, $fid, $p1id, $treePeople);
    treeTrAddPerson($pdo, $fid, $p2id, $treePeople);

    treeTrCollectUp($pdo, $fid, $p1id, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp, $depth + 1);
    treeTrCollectUp($pdo, $fid, $p2id, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp, $depth + 1);
}

function treeTrCollectDown(PDO $pdo, int $fid, int $pid, array &$treePeople, array &$treeCouples, array &$coupleChildren, array &$visitedDown, int $depth = 0): void
{
    if (isset($visitedDown[$pid]) || $depth > 15) return;
    $visitedDown[$pid] = true;

    $stmt = $pdo->prepare(
        'SELECT c.id AS cid,
                IFNULL(DATE_FORMAT(c.start_date, "%Y"), "") AS wy,
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

        if (!isset($treeCouples[$cid])) {
            $treeCouples[$cid] = ['id' => $cid, 'p1' => $p1id, 'p2' => $p2id, 'wy' => $cr['wy']];
        }

        treeTrAddPerson($pdo, $fid, $p1id, $treePeople);
        treeTrAddPerson($pdo, $fid, $p2id, $treePeople);

        if (!isset($coupleChildren[$cid])) {
            $cStmt = $pdo->prepare(
                'SELECT id FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
            );
            $cStmt->execute([$cid, $fid]);
            $coupleChildren[$cid] = array_map(fn($r) => (int)$r['id'], $cStmt->fetchAll());
        }

        foreach ($coupleChildren[$cid] as $childId) {
            treeTrAddPerson($pdo, $fid, $childId, $treePeople);
            treeTrCollectDown($pdo, $fid, $childId, $treePeople, $treeCouples, $coupleChildren, $visitedDown, $depth + 1);
        }
    }
}

// Collect the central person
treeTrAddPerson($pdo, $fid, $personId, $treePeople);

// Collect ancestors (upward)
treeTrCollectUp($pdo, $fid, $personId, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp);

// Collect descendants (downward)
treeTrCollectDown($pdo, $fid, $personId, $treePeople, $treeCouples, $coupleChildren, $visitedDown);

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
    treeTrCollectUp($pdo, $fid, $spouseId, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp);
}

$person = $treePeople[$personId] ?? null;
if (!$person) {
    echo '<p>Person not found.</p>';
    return;
}

$personName = $person['fn'] . ' ' . $person['ln'];

// Normalize couples so the child-of-parent is always p1
$isChildOf = [];
foreach ($coupleChildren as $cid => $kids) {
    foreach ($kids as $kid) {
        $isChildOf[$kid] = $cid;
    }
}
foreach ($treeCouples as &$c) {
    if (isset($isChildOf[$c['p2']]) && !isset($isChildOf[$c['p1']])) {
        $tmp = $c['p1'];
        $c['p1'] = $c['p2'];
        $c['p2'] = $tmp;
    }
}
unset($c);

// Build JSON for JavaScript
$jsonData = json_encode([
    'people' => $treePeople,
    'couples' => array_values($treeCouples),
    'coupleChildren' => (object)$coupleChildren,
    'parentCouple' => (object)$personParentCouple,
    'rootId' => $personId,
], JSON_HEX_TAG | JSON_HEX_AMP);

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['tree_treant'] ?? 'Treant' ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($person['uuid']) ?>"><?= $L['classic'] ?></a> .
        <a href="/treeFC/<?= h($person['uuid']) ?>"><?= $L['tree_fc'] ?? 'Family Chart' ?></a> .
        <a href="/treeDo/<?= h($person['uuid']) ?>"><?= $L['tree_donut'] ?? 'Donut' ?></a> .
        <a href="/treeT/<?= h($person['uuid']) ?>"><?= $L['tree_timeline'] ?? 'Timeline' ?></a>
    </span>
</div>

<div id="tretr-wrap">
    <div id="tretr-container"></div>
</div>

<link rel="stylesheet" href="/js/vendor/Treant.css">
<script src="/js/vendor/raphael.min.js"></script>
<script src="/js/vendor/Treant.js"></script>
<script>
(function() {
    'use strict';

    var DATA = <?= $jsonData ?>;

    // Index couples by id
    var couplesById = {};
    DATA.couples.forEach(function(c) { couplesById[c.id] = c; });

    // =====================================================================
    // Find the root couple (first couple containing rootId)
    // =====================================================================
    var rootCoupleId = null;
    for (var i = 0; i < DATA.couples.length; i++) {
        var c = DATA.couples[i];
        if (c.p1 === DATA.rootId || c.p2 === DATA.rootId) {
            rootCoupleId = c.id;
            break;
        }
    }

    // =====================================================================
    // Walk up from rootId to find the topmost ancestor couple
    // =====================================================================
    var topCoupleId = rootCoupleId;
    if (topCoupleId) {
        var walked = {};
        walked[topCoupleId] = true;
        var currentId = DATA.rootId;
        while (DATA.parentCouple[currentId]) {
            var pcId = DATA.parentCouple[currentId];
            if (walked[pcId]) break;
            walked[pcId] = true;
            topCoupleId = pcId;
            // Continue up through the blood-relative partner
            var pc = couplesById[pcId];
            if (pc && DATA.parentCouple[pc.p1]) {
                currentId = pc.p1;
            } else if (pc && DATA.parentCouple[pc.p2]) {
                currentId = pc.p2;
            } else {
                break;
            }
        }
    }

    // =====================================================================
    // HTML builders for nodes
    // =====================================================================
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function personLine(p, isRoot) {
        var dates = (p.birth || '?') + '\u2013' + (p.death || '');
        var cls = isRoot ? ' tretr-highlight' : '';
        return '<a href="/treeTr/' + esc(p.uuid) + '" class="tretr-name' + cls + '">'
            + esc(p.fn) + ' ' + esc(p.ln) + '</a>'
            + '<a href="/person/' + esc(p.uuid) + '" class="tretr-dates">' + esc(dates) + '</a>';
    }

    function buildCoupleHTML(couple, isRoot) {
        var p1 = DATA.people[couple.p1];
        var p2 = DATA.people[couple.p2];
        var isP1Root = couple.p1 === DATA.rootId;
        var isP2Root = couple.p2 === DATA.rootId;
        var wy = couple.wy ? '<span class="tretr-wy">' + esc(couple.wy) + '</span>' : '';
        var html = '<div class="tretr-couple-inner">';
        if (p1) html += '<div class="tretr-partner">' + personLine(p1, isP1Root) + '</div>';
        html += wy;
        if (p2) html += '<div class="tretr-partner">' + personLine(p2, isP2Root) + '</div>';
        html += '</div>';
        return html;
    }

    function buildPersonHTML(p, isRoot) {
        return '<div class="tretr-single-inner">' + personLine(p, isRoot) + '</div>';
    }

    // =====================================================================
    // Build Treant.js nodeStructure recursively
    // =====================================================================
    function buildCoupleNode(cid, visited) {
        if (!visited) visited = {};
        if (visited[cid]) return null;
        visited[cid] = true;

        var couple = couplesById[cid];
        if (!couple) return null;

        var isRoot = (couple.p1 === DATA.rootId || couple.p2 === DATA.rootId);
        var node = {
            innerHTML: buildCoupleHTML(couple, isRoot),
            HTMLclass: 'tretr-couple' + (isRoot ? ' tretr-root' : ''),
            children: []
        };

        var children = DATA.coupleChildren[cid] || [];
        children.forEach(function(chId) {
            // Find couples for this child that we haven't visited yet
            var childCouples = DATA.couples.filter(function(cc) {
                return (cc.p1 === chId || cc.p2 === chId) && !visited[cc.id];
            });

            if (childCouples.length > 0) {
                childCouples.forEach(function(cc) {
                    var childNode = buildCoupleNode(cc.id, visited);
                    if (childNode) node.children.push(childNode);
                });
            } else {
                // Single person (no partner)
                var p = DATA.people[chId];
                if (p) {
                    node.children.push({
                        innerHTML: buildPersonHTML(p, chId === DATA.rootId),
                        HTMLclass: 'tretr-single' + (chId === DATA.rootId ? ' tretr-root' : '')
                    });
                }
            }
        });

        return node;
    }

    // =====================================================================
    // Build the tree
    // =====================================================================
    var rootNode;

    if (topCoupleId) {
        rootNode = buildCoupleNode(topCoupleId, {});
    } else if (rootCoupleId) {
        rootNode = buildCoupleNode(rootCoupleId, {});
    } else {
        // Single person, no couples at all
        var rp = DATA.people[DATA.rootId];
        rootNode = {
            innerHTML: buildPersonHTML(rp, true),
            HTMLclass: 'tretr-single tretr-root'
        };
    }

    if (!rootNode) {
        document.getElementById('tretr-container').innerHTML = '<p>No tree data available.</p>';
        return;
    }

    // =====================================================================
    // Treant.js configuration
    // =====================================================================
    var config = {
        chart: {
            container: '#tretr-container',
            rootOrientation: 'NORTH',
            nodeAlign: 'BOTTOM',
            levelSeparation: 50,
            siblingSeparation: 25,
            subTeeSeparation: 35,
            connectors: {
                type: 'step',
                style: {
                    'stroke-width': 1.5,
                    'stroke': '#888',
                    'arrow-end': 'none'
                }
            },
            node: {
                HTMLclass: 'tretr-node'
            },
            padding: 20,
            scrollbar: 'native'
        },
        nodeStructure: rootNode
    };

    new Treant(config);

    // Equalize single/couple node heights within the same generation row
    setTimeout(function() {
        var allNodes = document.querySelectorAll('#tretr-container .tretr-couple, #tretr-container .tretr-single');
        var byRow = {};
        allNodes.forEach(function(el) {
            var bucket = Math.round(el.offsetTop / 5) * 5;
            if (!byRow[bucket]) byRow[bucket] = [];
            byRow[bucket].push(el);
        });
        Object.keys(byRow).forEach(function(key) {
            var group = byRow[key];
            if (group.length <= 1) return;
            var maxH = 0;
            group.forEach(function(el) { maxH = Math.max(maxH, el.offsetHeight); });
            group.forEach(function(el) {
                if (el.offsetHeight < maxH) {
                    el.style.height = maxH + 'px';
                }
            });
        });
    }, 100);

})();
</script>
