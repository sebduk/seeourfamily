<?php

/**
 * Visual family tree using jsPlumb connectors.
 *
 * PHP collects the full tree data (ancestors up, descendants down) as JSON.
 * JavaScript computes a hierarchical layout and uses jsPlumb 2.x Flowchart
 * connectors to draw lines between couples and parent→child relationships.
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

$treePeople = [];   // id => {id, uuid, fn, ln, birth, death}
$treeCouples = [];  // coupleId => {id, p1, p2, wy}
$coupleChildren = []; // coupleId => [personId, ...]
$personParentCouple = []; // personId => coupleId
$visitedUp = [];
$visitedDown = [];

function treePAddPerson(PDO $pdo, int $fid, int $pid, array &$treePeople): void
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

/**
 * Collect ancestors upward from a person.
 */
function treePCollectUp(PDO $pdo, int $fid, int $pid, array &$treePeople, array &$treeCouples, array &$coupleChildren, array &$personParentCouple, array &$visitedUp, int $depth = 0): void
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

    // Add children of this couple (siblings) if not already collected
    if (!isset($coupleChildren[$cid])) {
        $cStmt = $pdo->prepare(
            'SELECT id FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
        );
        $cStmt->execute([$cid, $fid]);
        $coupleChildren[$cid] = array_map(fn($r) => (int)$r['id'], $cStmt->fetchAll());
    }

    treePAddPerson($pdo, $fid, $p1id, $treePeople);
    treePAddPerson($pdo, $fid, $p2id, $treePeople);

    // Recurse up for both parents
    treePCollectUp($pdo, $fid, $p1id, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp, $depth + 1);
    treePCollectUp($pdo, $fid, $p2id, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp, $depth + 1);
}

/**
 * Collect descendants downward from a person.
 */
function treePCollectDown(PDO $pdo, int $fid, int $pid, array &$treePeople, array &$treeCouples, array &$coupleChildren, array &$visitedDown, int $depth = 0): void
{
    if (isset($visitedDown[$pid]) || $depth > 15) return;
    $visitedDown[$pid] = true;

    // Find all couples for this person
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

        treePAddPerson($pdo, $fid, $p1id, $treePeople);
        treePAddPerson($pdo, $fid, $p2id, $treePeople);

        // Children of this couple
        if (!isset($coupleChildren[$cid])) {
            $cStmt = $pdo->prepare(
                'SELECT id FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
            );
            $cStmt->execute([$cid, $fid]);
            $coupleChildren[$cid] = array_map(fn($r) => (int)$r['id'], $cStmt->fetchAll());
        }

        foreach ($coupleChildren[$cid] as $childId) {
            treePAddPerson($pdo, $fid, $childId, $treePeople);
            treePCollectDown($pdo, $fid, $childId, $treePeople, $treeCouples, $coupleChildren, $visitedDown, $depth + 1);
        }
    }
}

// Collect the central person
treePAddPerson($pdo, $fid, $personId, $treePeople);

// Collect ancestors (upward)
treePCollectUp($pdo, $fid, $personId, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp);

// Collect descendants (downward) — for the central person AND their spouse(s)
treePCollectDown($pdo, $fid, $personId, $treePeople, $treeCouples, $coupleChildren, $visitedDown);

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
    treePCollectUp($pdo, $fid, $spouseId, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp);
}

$person = $treePeople[$personId] ?? null;
if (!$person) {
    echo '<p>Person not found.</p>';
    return;
}

$personName = $person['fn'] . ' ' . $person['ln'];

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
    <?= $L['tree_person'] ?? 'Full Tree' ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($person['uuid']) ?>"><?= $L['classic'] ?></a> .
        <a href="/treeT/<?= h($person['uuid']) ?>"><?= $L['tree_timeline'] ?? 'Timeline' ?></a>
    </span>
</div>

<div id="treep-wrap">
    <div id="treep-container"></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jsPlumb/2.15.6/js/jsplumb.min.js"></script>
<script>
(function() {
    'use strict';

    var DATA = <?= $jsonData ?>;

    // =====================================================================
    // CONSTANTS
    // =====================================================================
    var NODE_W  = 140;
    var NODE_H  = 52;
    var COUPLE_GAP = 16;   // gap between two person cards in a couple
    var WED_W   = 30;      // width of the wedding-year connector area
    var H_GAP   = 24;      // horizontal gap between sibling subtrees
    var V_GAP   = 56;      // vertical gap between generations
    var MAX_ROW_W = 1200;  // max width before wrapping children to next row

    var container = document.getElementById('treep-container');

    // =====================================================================
    // TREE STRUCTURE BUILDER
    // =====================================================================

    // Find the "root couple" (the first couple containing rootId)
    var rootCoupleId = null;
    for (var i = 0; i < DATA.couples.length; i++) {
        var c = DATA.couples[i];
        if (c.p1 === DATA.rootId || c.p2 === DATA.rootId) {
            rootCoupleId = c.id;
            break;
        }
    }

    // Index couples by id
    var couplesById = {};
    DATA.couples.forEach(function(c) { couplesById[c.id] = c; });

    // =====================================================================
    // LAYOUT: Row grouping for children
    // =====================================================================

    // Group a couple's children into rows that fit within MAX_ROW_W
    var childRowsCache = {};

    function getChildRows(cid) {
        if (childRowsCache[cid]) return childRowsCache[cid];
        var children = DATA.coupleChildren[cid] || [];
        if (children.length === 0) { childRowsCache[cid] = []; return []; }

        var childWidths = children.map(function(chId) {
            return personSubtreeWidthDown(chId, {});
        });

        var rows = [];
        var currentRow = [];
        var currentW = 0;

        for (var i = 0; i < children.length; i++) {
            var w = childWidths[i];
            var newW = currentW + (currentRow.length > 0 ? H_GAP : 0) + w;
            if (currentRow.length > 0 && newW > MAX_ROW_W) {
                rows.push(currentRow);
                currentRow = [];
                currentW = 0;
                newW = w;
            }
            currentRow.push({ id: children[i], w: w, idx: i });
            currentW = newW;
        }
        if (currentRow.length > 0) rows.push(currentRow);
        childRowsCache[cid] = rows;
        return rows;
    }

    function rowWidth(row) {
        var w = 0;
        row.forEach(function(item, idx) {
            if (idx > 0) w += H_GAP;
            w += item.w;
        });
        return w;
    }

    // =====================================================================
    // LAYOUT: Recursive width & height calculation
    // =====================================================================

    var coupleWidthCache = {};
    var personWidthCacheDown = {};

    function personSubtreeWidthDown(pid, visited) {
        if (!visited) visited = {};
        if (visited[pid]) return NODE_W;
        visited[pid] = true;
        if (personWidthCacheDown[pid] !== undefined) return personWidthCacheDown[pid];

        var pCouples = DATA.couples.filter(function(c) {
            return c.p1 === pid || c.p2 === pid;
        });
        if (pCouples.length === 0) {
            personWidthCacheDown[pid] = NODE_W;
            return NODE_W;
        }

        var totalW = 0;
        pCouples.forEach(function(c, idx) {
            if (idx > 0) totalW += H_GAP;
            totalW += coupleSubtreeWidthDown(c.id, visited);
        });
        personWidthCacheDown[pid] = totalW;
        return totalW;
    }

    function coupleSubtreeWidthDown(cid, visited) {
        if (!visited) visited = {};
        if (coupleWidthCache[cid]) return coupleWidthCache[cid];

        var pairW = NODE_W * 2 + WED_W;
        var children = DATA.coupleChildren[cid] || [];
        if (children.length === 0) {
            coupleWidthCache[cid] = pairW;
            return pairW;
        }

        var rows = getChildRows(cid);
        var maxRowW = 0;
        rows.forEach(function(row) { maxRowW = Math.max(maxRowW, rowWidth(row)); });

        var w = Math.max(pairW, maxRowW);
        coupleWidthCache[cid] = w;
        return w;
    }

    // Height of a person's subtree (for multi-row spacing)
    function personSubtreeHeightDown(pid, visited) {
        if (!visited) visited = {};
        if (visited[pid]) return NODE_H;
        visited[pid] = true;
        var pCouples = DATA.couples.filter(function(c) {
            return c.p1 === pid || c.p2 === pid;
        });
        if (pCouples.length === 0) return NODE_H;
        var maxH = 0;
        pCouples.forEach(function(c) {
            maxH = Math.max(maxH, coupleSubtreeHeightDown(c.id, Object.assign({}, visited)));
        });
        return maxH;
    }

    function coupleSubtreeHeightDown(cid, visited) {
        if (!visited) visited = {};
        if (visited[cid]) return NODE_H;
        visited[cid] = true;
        var children = DATA.coupleChildren[cid] || [];
        if (children.length === 0) return NODE_H;

        var rows = getChildRows(cid);
        var totalRowH = 0;
        rows.forEach(function(row, rowIdx) {
            var maxH = 0;
            row.forEach(function(item) {
                maxH = Math.max(maxH, personSubtreeHeightDown(item.id, Object.assign({}, visited)));
            });
            totalRowH += maxH;
            if (rowIdx < rows.length - 1) totalRowH += V_GAP;
        });
        return NODE_H + V_GAP + totalRowH;
    }

    // Ancestor subtree width (upward from a couple)
    var ancestorWidthCache = {};

    function ancestorSubtreeWidth(cid, visited) {
        if (!visited) visited = {};
        if (visited['a' + cid]) return NODE_W * 2 + WED_W;
        visited['a' + cid] = true;
        if (ancestorWidthCache[cid] !== undefined) return ancestorWidthCache[cid];

        var couple = couplesById[cid];
        if (!couple) return NODE_W * 2 + WED_W;

        var pairW = NODE_W * 2 + WED_W;
        var p1Parent = DATA.parentCouple[couple.p1];
        var p2Parent = DATA.parentCouple[couple.p2];

        if (!p1Parent && !p2Parent) {
            ancestorWidthCache[cid] = pairW;
            return pairW;
        }

        var aboveW = 0;
        if (p1Parent) {
            aboveW += ancestorSubtreeWidth(p1Parent, Object.assign({}, visited));
        } else {
            aboveW += NODE_W;
        }
        aboveW += H_GAP;
        if (p2Parent) {
            aboveW += ancestorSubtreeWidth(p2Parent, Object.assign({}, visited));
        } else {
            aboveW += NODE_W;
        }

        var w = Math.max(pairW, aboveW);
        ancestorWidthCache[cid] = w;
        return w;
    }

    // =====================================================================
    // LAYOUT: Position calculation
    // =====================================================================

    var nodes = [];   // {id, type:'person'|'couple', x, y, w, h, data, isRoot}
    var connections = []; // {from, to, type:'couple'|'child', label}
    var nodeId = 0;

    function makePersonNode(pid, x, y, isRoot) {
        var p = DATA.people[pid];
        if (!p) return null;
        var nid = 'p' + pid;
        nodes.push({
            id: nid, type: 'person', x: x, y: y,
            w: NODE_W, h: NODE_H, data: p,
            isRoot: isRoot || false
        });
        return nid;
    }

    function makeCoupleJoint(cid, x, y, label) {
        var nid = 'cj' + cid;
        nodes.push({
            id: nid, type: 'joint', x: x, y: y,
            w: WED_W, h: 12, data: { wy: label || '' }
        });
        return nid;
    }

    // Layout descendants from a couple downward (multi-row support)
    function layoutCoupleDown(cid, left, top, visited) {
        if (!visited) visited = {};
        if (visited[cid]) return;
        visited[cid] = true;

        var couple = couplesById[cid];
        if (!couple) return;

        var subtreeW = coupleSubtreeWidthDown(cid, {});
        var pairW = NODE_W * 2 + WED_W;
        var pairLeft = left + (subtreeW - pairW) / 2;

        var isP1Root = couple.p1 === DATA.rootId;
        var isP2Root = couple.p2 === DATA.rootId;

        var p1Nid = makePersonNode(couple.p1, pairLeft, top, isP1Root);
        var jointNid = makeCoupleJoint(cid, pairLeft + NODE_W, top + NODE_H / 2 - 6, couple.wy);
        var p2Nid = makePersonNode(couple.p2, pairLeft + NODE_W + WED_W, top, isP2Root);

        if (p1Nid && p2Nid) {
            connections.push({ from: p1Nid, to: jointNid, type: 'couple' });
            connections.push({ from: jointNid, to: p2Nid, type: 'couple' });
        }

        var children = DATA.coupleChildren[cid] || [];
        if (children.length === 0) return;

        // Group children into rows
        var rows = getChildRows(cid);
        var rowTop = top + NODE_H + V_GAP;

        rows.forEach(function(row) {
            var rw = rowWidth(row);
            var childLeft = left + (subtreeW - rw) / 2;
            var maxRowH = NODE_H;

            row.forEach(function(item) {
                var chId = item.id;
                var chW = item.w;

                var chCouples = DATA.couples.filter(function(cc) {
                    return (cc.p1 === chId || cc.p2 === chId) && !visited[cc.id];
                });

                if (chCouples.length > 0) {
                    chCouples.forEach(function(cc) {
                        layoutCoupleDown(cc.id, childLeft, rowTop, visited);
                        var h = coupleSubtreeHeightDown(cc.id, {});
                        maxRowH = Math.max(maxRowH, h);
                    });
                    connections.push({ from: jointNid, to: 'p' + chId, type: 'child' });
                } else {
                    var singleX = childLeft + (chW - NODE_W) / 2;
                    var chNid = makePersonNode(chId, singleX, rowTop, false);
                    if (chNid) {
                        connections.push({ from: jointNid, to: chNid, type: 'child' });
                    }
                }

                childLeft += chW + H_GAP;
            });

            rowTop += maxRowH + V_GAP;
        });
    }

    // Layout ancestors from a couple upward
    function layoutCoupleUp(cid, left, bottom, visited) {
        if (!visited) visited = {};
        if (visited['a' + cid]) return;
        visited['a' + cid] = true;

        var couple = couplesById[cid];
        if (!couple) return;

        var subtreeW = ancestorSubtreeWidth(cid, {});
        var pairW = NODE_W * 2 + WED_W;
        var pairLeft = left + (subtreeW - pairW) / 2;

        var top = bottom - NODE_H;

        // Check if these nodes already exist (placed during down-layout)
        var p1Exists = nodes.some(function(n) { return n.id === 'p' + couple.p1; });
        var p2Exists = nodes.some(function(n) { return n.id === 'p' + couple.p2; });

        var p1Nid, p2Nid, jointNid;

        if (!p1Exists) {
            p1Nid = makePersonNode(couple.p1, pairLeft, top, couple.p1 === DATA.rootId);
        } else {
            p1Nid = 'p' + couple.p1;
        }

        var jointExists = nodes.some(function(n) { return n.id === 'cj' + cid; });
        if (!jointExists) {
            jointNid = makeCoupleJoint(cid, pairLeft + NODE_W, top + NODE_H / 2 - 6, couple.wy);
        } else {
            jointNid = 'cj' + cid;
        }

        if (!p2Exists) {
            p2Nid = makePersonNode(couple.p2, pairLeft + NODE_W + WED_W, top, couple.p2 === DATA.rootId);
        } else {
            p2Nid = 'p' + couple.p2;
        }

        if (!p1Exists || !p2Exists) {
            connections.push({ from: p1Nid, to: jointNid, type: 'couple' });
            connections.push({ from: jointNid, to: p2Nid, type: 'couple' });
        }

        // Parents of p1
        var p1Parent = DATA.parentCouple[couple.p1];
        if (p1Parent) {
            var p1AncW = ancestorSubtreeWidth(p1Parent, {});
            var p1AncLeft = pairLeft + NODE_W / 2 - p1AncW / 2;
            layoutCoupleUp(p1Parent, p1AncLeft, top - V_GAP, visited);
            // Connect parent couple joint to p1
            connections.push({ from: 'cj' + p1Parent, to: p1Nid, type: 'child' });
        }

        // Parents of p2
        var p2Parent = DATA.parentCouple[couple.p2];
        if (p2Parent) {
            var p2AncW = ancestorSubtreeWidth(p2Parent, {});
            var p2AncLeft = pairLeft + NODE_W + WED_W + NODE_W / 2 - p2AncW / 2;
            layoutCoupleUp(p2Parent, p2AncLeft, top - V_GAP, visited);
            connections.push({ from: 'cj' + p2Parent, to: p2Nid, type: 'child' });
        }
    }

    // =====================================================================
    // BUILD LAYOUT
    // =====================================================================

    // Count ancestor generations to set the root Y position
    function maxAncestorDepth(cid, depth, visited) {
        if (!visited) visited = {};
        if (visited[cid]) return depth;
        visited[cid] = true;
        var couple = couplesById[cid];
        if (!couple) return depth;
        var d = depth;
        var p1p = DATA.parentCouple[couple.p1];
        var p2p = DATA.parentCouple[couple.p2];
        if (p1p) d = Math.max(d, maxAncestorDepth(p1p, depth + 1, Object.assign({}, visited)));
        if (p2p) d = Math.max(d, maxAncestorDepth(p2p, depth + 1, Object.assign({}, visited)));
        return d;
    }

    var ancDepth = 0;
    if (rootCoupleId) {
        ancDepth = maxAncestorDepth(rootCoupleId, 0, {});
    } else {
        var rootParent = DATA.parentCouple[DATA.rootId];
        if (rootParent) ancDepth = maxAncestorDepth(rootParent, 1, {});
    }

    var rootTop = ancDepth * (NODE_H + V_GAP) + 20;

    if (rootCoupleId) {
        var downW = coupleSubtreeWidthDown(rootCoupleId, {});
        var upW = ancestorSubtreeWidth(rootCoupleId, {});
        var totalLayoutW = Math.max(downW, upW);

        // Center both descendant and ancestor trees within the same total width
        layoutCoupleDown(rootCoupleId, (totalLayoutW - downW) / 2, rootTop, {});
        layoutCoupleUp(rootCoupleId, (totalLayoutW - upW) / 2, rootTop, {});
    } else {
        // Single person, no couple
        var rootParent = DATA.parentCouple[DATA.rootId];
        var upW = rootParent ? ancestorSubtreeWidth(rootParent, {}) : NODE_W;
        var totalLayoutW = Math.max(NODE_W, upW);
        var personLeft = (totalLayoutW - NODE_W) / 2;

        makePersonNode(DATA.rootId, personLeft, rootTop, true);

        if (rootParent) {
            layoutCoupleUp(rootParent, (totalLayoutW - upW) / 2, rootTop - V_GAP, {});
            connections.push({ from: 'cj' + rootParent, to: 'p' + DATA.rootId, type: 'child' });
        }
    }

    // =====================================================================
    // NORMALIZE POSITIONS (shift so min x = 20, min y = 20)
    // =====================================================================

    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    nodes.forEach(function(n) {
        if (n.x < minX) minX = n.x;
        if (n.y < minY) minY = n.y;
        if (n.x + n.w > maxX) maxX = n.x + n.w;
        if (n.y + n.h > maxY) maxY = n.y + n.h;
    });

    var PAD = 20;
    var offsetX = PAD - minX;
    var offsetY = PAD - minY;
    nodes.forEach(function(n) {
        n.x += offsetX;
        n.y += offsetY;
    });

    var totalW = (maxX - minX) + PAD * 2;
    var totalH = (maxY - minY) + PAD * 2;
    container.style.width = totalW + 'px';
    container.style.height = totalH + 'px';

    // =====================================================================
    // RENDER DOM NODES
    // =====================================================================

    nodes.forEach(function(n) {
        var el = document.createElement('div');
        el.id = n.id;
        el.style.position = 'absolute';
        el.style.left = n.x + 'px';
        el.style.top = n.y + 'px';
        el.style.width = n.w + 'px';
        el.style.height = n.h + 'px';

        if (n.type === 'person') {
            el.className = 'treep-node' + (n.isRoot ? ' treep-node-root' : '');
            var p = n.data;
            el.innerHTML = '<a href="/tree/' + p.uuid + '" class="treep-name">'
                + p.fn + ' ' + p.ln + '</a>'
                + '<a href="/person/' + p.uuid + '" class="treep-dates">'
                + (p.birth || '?') + '–' + (p.death || '') + '</a>';
        } else if (n.type === 'joint') {
            el.className = 'treep-joint';
            if (n.data.wy) {
                el.innerHTML = '<span>' + n.data.wy + '</span>';
            }
        }

        container.appendChild(el);
    });

    // =====================================================================
    // JSPLUMB CONNECTIONS
    // =====================================================================

    jsPlumb.ready(function() {
        var instance = jsPlumb.getInstance({
            Container: container,
            Connector: ['Flowchart', { cornerRadius: 4, stub: 12, midpoint: 0.5 }],
            PaintStyle: { stroke: '#888', strokeWidth: 1.5 },
            Endpoint: 'Blank'
        });

        connections.forEach(function(conn) {
            var srcEl = document.getElementById(conn.from);
            var tgtEl = document.getElementById(conn.to);
            if (!srcEl || !tgtEl) return;

            if (conn.type === 'couple') {
                // Horizontal connection between person ↔ joint
                instance.connect({
                    source: srcEl,
                    target: tgtEl,
                    anchors: ['Right', 'Left'],
                    paintStyle: { stroke: '#b08050', strokeWidth: 1.5 }
                });
            } else if (conn.type === 'child') {
                // Vertical connection from couple-joint down to child
                instance.connect({
                    source: srcEl,
                    target: tgtEl,
                    anchors: ['Bottom', 'Top'],
                    paintStyle: { stroke: '#888', strokeWidth: 1.5 }
                });
            }
        });
    });

})();
</script>
