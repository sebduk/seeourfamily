<?php

/**
 * Hybrid family tree: jsPlumb (ancestors upward) + Treant.js (descendants downward).
 *
 * Couples are shown in a single box (div model from treeTr).
 * A "priority" setting controls the order of partners in the couple box:
 *   - "family" : the direct family member (blood relative) is on top
 *   - "patriarchal" : person1 (father / parent1) is always on top
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

// Priority setting from cookie (default: family)
$priority = $_COOKIE['sof_tree_priority'] ?? 'family';
if (!in_array($priority, ['family', 'patriarchal'], true)) {
    $priority = 'family';
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

function treePTAddPerson(PDO $pdo, int $fid, int $pid, array &$treePeople): void
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

function treePTCollectUp(PDO $pdo, int $fid, int $pid, array &$treePeople, array &$treeCouples, array &$coupleChildren, array &$personParentCouple, array &$visitedUp, int $depth = 0): void
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

    treePTAddPerson($pdo, $fid, $p1id, $treePeople);
    treePTAddPerson($pdo, $fid, $p2id, $treePeople);

    treePTCollectUp($pdo, $fid, $p1id, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp, $depth + 1);
    treePTCollectUp($pdo, $fid, $p2id, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp, $depth + 1);
}

function treePTCollectDown(PDO $pdo, int $fid, int $pid, array &$treePeople, array &$treeCouples, array &$coupleChildren, array &$visitedDown, int $depth = 0): void
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

        treePTAddPerson($pdo, $fid, $p1id, $treePeople);
        treePTAddPerson($pdo, $fid, $p2id, $treePeople);

        if (!isset($coupleChildren[$cid])) {
            $cStmt = $pdo->prepare(
                'SELECT id FROM people WHERE couple_id = ? AND family_id = ? ORDER BY couple_sort'
            );
            $cStmt->execute([$cid, $fid]);
            $coupleChildren[$cid] = array_map(fn($r) => (int)$r['id'], $cStmt->fetchAll());
        }

        foreach ($coupleChildren[$cid] as $childId) {
            treePTAddPerson($pdo, $fid, $childId, $treePeople);
            treePTCollectDown($pdo, $fid, $childId, $treePeople, $treeCouples, $coupleChildren, $visitedDown, $depth + 1);
        }
    }
}

// Collect the central person
treePTAddPerson($pdo, $fid, $personId, $treePeople);

// Collect ancestors (upward)
treePTCollectUp($pdo, $fid, $personId, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp);

// Collect descendants (downward)
treePTCollectDown($pdo, $fid, $personId, $treePeople, $treeCouples, $coupleChildren, $visitedDown);

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
    treePTCollectUp($pdo, $fid, $spouseId, $treePeople, $treeCouples, $coupleChildren, $personParentCouple, $visitedUp);
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
    'priority' => $priority,
], JSON_HEX_TAG | JSON_HEX_AMP);

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['tree_hybrid'] ?? 'Hybrid' ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($person['uuid']) ?>"><?= $L['classic'] ?></a> .
        <a href="/treeFC/<?= h($person['uuid']) ?>"><?= $L['tree_fc'] ?? 'Family Chart' ?></a> .
        <a href="/treeDo/<?= h($person['uuid']) ?>"><?= $L['tree_donut'] ?? 'Donut' ?></a> .
        <a href="/treeT/<?= h($person['uuid']) ?>"><?= $L['tree_timeline'] ?? 'Timeline' ?></a> .
        <a href="/treeTr/<?= h($person['uuid']) ?>"><?= $L['tree_treant'] ?? 'Treant' ?></a>
    </span>
    <span class="nav-links" style="margin-left:12px">
        | <?= $L['tree_priority'] ?? 'Priority' ?>:
        <a href="#" onclick="setTreePriority('family',event)" id="treept-btn-family"><?= $L['tree_priority_family'] ?? 'Family' ?></a> .
        <a href="#" onclick="setTreePriority('patriarchal',event)" id="treept-btn-patriarchal"><?= $L['tree_priority_patriarchal'] ?? 'Patriarchal' ?></a>
    </span>
</div>

<!-- Single scrollable tree frame -->
<div id="treept-wrap">
    <div id="treept-anc-container"></div>
    <div id="treept-desc-container"></div>
</div>

<link rel="stylesheet" href="/js/vendor/Treant.css">
<script src="/js/vendor/raphael.min.js"></script>
<script src="/js/vendor/Treant.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsPlumb/2.15.6/js/jsplumb.min.js"></script>
<script>
(function() {
    'use strict';

    var DATA = <?= $jsonData ?>;
    var PRIORITY = DATA.priority; // 'family' or 'patriarchal'

    // =====================================================================
    // Priority toggle (cookie + reload)
    // =====================================================================
    window.setTreePriority = function(val, e) {
        if (e) e.preventDefault();
        document.cookie = 'sof_tree_priority=' + val + ';path=/;max-age=31536000';
        location.reload();
    };

    // Highlight active priority button
    var famBtn = document.getElementById('treept-btn-family');
    var patBtn = document.getElementById('treept-btn-patriarchal');
    if (PRIORITY === 'family') { famBtn.style.fontWeight = 'bold'; }
    else { patBtn.style.fontWeight = 'bold'; }

    // =====================================================================
    // Shared helpers
    // =====================================================================
    var couplesById = {};
    DATA.couples.forEach(function(c) { couplesById[c.id] = c; });

    // Find ALL couples the root person belongs to (multiple marriages)
    var rootCoupleIds = [];
    for (var i = 0; i < DATA.couples.length; i++) {
        var c = DATA.couples[i];
        if (c.p1 === DATA.rootId || c.p2 === DATA.rootId) {
            rootCoupleIds.push(c.id);
        }
    }
    var rootCoupleId = rootCoupleIds.length > 0 ? rootCoupleIds[0] : null;

    // Build child→parentCouple index
    var isChildOf = {};
    Object.keys(DATA.coupleChildren).forEach(function(cid) {
        DATA.coupleChildren[cid].forEach(function(chId) {
            isChildOf[chId] = parseInt(cid);
        });
    });

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /**
     * Determine the display order of partners in a couple box.
     * Returns [topPerson, bottomPerson] based on priority setting.
     */
    function orderedPartners(couple) {
        var p1 = DATA.people[couple.p1];
        var p2 = DATA.people[couple.p2];
        if (!p1 || !p2) return [p1 || p2, null];

        if (PRIORITY === 'patriarchal') {
            // person1 (DB order, traditionally father) on top
            return [p1, p2];
        }

        // Family priority: the blood-relative (child of a parent couple) on top
        if (isChildOf[couple.p2] !== undefined && isChildOf[couple.p1] === undefined) {
            return [p2, p1];
        }
        return [p1, p2];
    }

    function personLine(p, isRoot) {
        var dates = (p.birth || '?') + '\u2013' + (p.death || '');
        var cls = isRoot ? ' treept-highlight' : '';
        return '<a href="/treePT/' + esc(p.uuid) + '" class="treept-name' + cls + '">'
            + esc(p.fn) + ' ' + esc(p.ln) + '</a>'
            + '<a href="/person/' + esc(p.uuid) + '" class="treept-dates">' + esc(dates) + '</a>';
    }

    function buildCoupleHTML(couple) {
        var partners = orderedPartners(couple);
        var topP = partners[0];
        var botP = partners[1];
        var isTopRoot = topP && topP.id === DATA.rootId;
        var isBotRoot = botP && botP.id === DATA.rootId;
        var wy = '<span class="treept-wy">' + (couple.wy ? esc(couple.wy) : '&nbsp;') + '</span>';
        var html = '<div class="treept-couple-inner">';
        if (topP) html += '<div class="treept-partner">' + personLine(topP, isTopRoot) + '</div>';
        html += wy;
        if (botP) html += '<div class="treept-partner">' + personLine(botP, isBotRoot) + '</div>';
        html += '</div>';
        return html;
    }

    function buildPersonHTML(p, isRoot) {
        return '<div class="treept-single-inner">' + personLine(p, isRoot) + '</div>';
    }

    // Track ancestor container dimensions for centring
    var ancContainerWidth = 0;

    // =====================================================================
    // PART 1: ANCESTOR TREE (jsPlumb, upward)
    // =====================================================================
    (function renderAncestors() {
        var ancContainer = document.getElementById('treept-anc-container');

        // Gather unique parent couples from ALL root couples (multiple marriages)
        if (rootCoupleIds.length === 0) return;

        var parentBranches = [];
        var seenPC = {};
        rootCoupleIds.forEach(function(cid) {
            var couple = couplesById[cid];
            if (!couple) return;
            [couple.p1, couple.p2].forEach(function(pid) {
                var pcid = DATA.parentCouple[pid];
                if (pcid && !seenPC[pcid]) {
                    seenPC[pcid] = true;
                    parentBranches.push(pcid);
                }
            });
        });
        if (parentBranches.length === 0) return; // No ancestors to show

        var NODE_W = 160;
        var NODE_H = 60;
        var H_GAP = 40;
        var V_GAP = 64;

        var nodes = [];
        var connections = [];

        // Ancestor subtree width
        var ancestorWidthCache = {};
        function ancestorSubtreeWidth(cid, visited) {
            if (!visited) visited = {};
            if (visited[cid]) return NODE_W;
            visited[cid] = true;
            if (ancestorWidthCache[cid] !== undefined) return ancestorWidthCache[cid];

            var couple = couplesById[cid];
            if (!couple) return NODE_W;

            var nodeW = NODE_W;
            var p1p = DATA.parentCouple[couple.p1];
            var p2p = DATA.parentCouple[couple.p2];

            if (!p1p && !p2p) {
                ancestorWidthCache[cid] = nodeW;
                return nodeW;
            }

            var aboveW = 0;
            if (p1p) aboveW += ancestorSubtreeWidth(p1p, Object.assign({}, visited));
            if (p1p && p2p) aboveW += H_GAP;
            if (p2p) aboveW += ancestorSubtreeWidth(p2p, Object.assign({}, visited));

            var w = Math.max(nodeW, aboveW);
            ancestorWidthCache[cid] = w;
            return w;
        }

        function makeCoupleNode(cid, x, y) {
            var nid = 'anc-c' + cid;
            var couple = couplesById[cid];
            nodes.push({
                id: nid, x: x, y: y, w: NODE_W, h: NODE_H,
                couple: couple, isRoot: (couple.p1 === DATA.rootId || couple.p2 === DATA.rootId)
            });
            return nid;
        }

        function layoutAncestorUp(cid, left, bottom, visited) {
            if (!visited) visited = {};
            if (visited[cid]) return;
            visited[cid] = true;

            var couple = couplesById[cid];
            if (!couple) return;

            var subtreeW = ancestorSubtreeWidth(cid, {});
            var nodeLeft = left + (subtreeW - NODE_W) / 2;
            var top = bottom - NODE_H;

            var nid = makeCoupleNode(cid, nodeLeft, top);

            var p1p = DATA.parentCouple[couple.p1];
            var p2p = DATA.parentCouple[couple.p2];

            if (p1p || p2p) {
                var p1AncW = p1p ? ancestorSubtreeWidth(p1p, {}) : 0;
                var p2AncW = p2p ? ancestorSubtreeWidth(p2p, {}) : 0;
                var gap = (p1p && p2p) ? H_GAP : 0;
                var totalAboveW = p1AncW + gap + p2AncW;
                var aboveLeft = left + (subtreeW - totalAboveW) / 2;

                if (p1p) {
                    layoutAncestorUp(p1p, aboveLeft, top - V_GAP, visited);
                    connections.push({ from: 'anc-c' + p1p, to: nid });
                }
                if (p2p) {
                    var p2Left = aboveLeft + p1AncW + gap;
                    layoutAncestorUp(p2p, p2Left, top - V_GAP, visited);
                    connections.push({ from: 'anc-c' + p2p, to: nid });
                }
            }
        }

        // Layout each parent branch (supports N branches for multiple marriages)
        var branchWidths = parentBranches.map(function(cid) {
            return ancestorSubtreeWidth(cid, {});
        });

        // Count max ancestor depth for height calculation
        function maxAncDepth(cid, depth, visited) {
            if (!visited) visited = {};
            if (visited[cid]) return depth;
            visited[cid] = true;
            var couple = couplesById[cid];
            if (!couple) return depth;
            var d = depth;
            var p1p = DATA.parentCouple[couple.p1];
            var p2p = DATA.parentCouple[couple.p2];
            if (p1p) d = Math.max(d, maxAncDepth(p1p, depth + 1, Object.assign({}, visited)));
            if (p2p) d = Math.max(d, maxAncDepth(p2p, depth + 1, Object.assign({}, visited)));
            return d;
        }

        var maxDepth = 0;
        parentBranches.forEach(function(cid) {
            maxDepth = Math.max(maxDepth, maxAncDepth(cid, 1, {}));
        });

        var totalH = maxDepth * (NODE_H + V_GAP) + 20;
        var bottomY = totalH;

        var xOff = 0;
        parentBranches.forEach(function(cid, idx) {
            layoutAncestorUp(cid, xOff, bottomY, {});
            xOff += branchWidths[idx];
            if (idx < parentBranches.length - 1) xOff += H_GAP;
        });

        if (nodes.length === 0) return;

        // Normalize positions
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
        nodes.forEach(function(n) { n.x += offsetX; n.y += offsetY; });

        var containerW = (maxX - minX) + PAD * 2;
        var containerH = (maxY - minY) + PAD * 2;
        ancContainerWidth = containerW;
        ancContainer.style.width = containerW + 'px';
        ancContainer.style.height = containerH + 'px';

        // Render DOM
        nodes.forEach(function(n) {
            var el = document.createElement('div');
            el.id = n.id;
            el.className = 'treept-node treept-couple' + (n.isRoot ? ' treept-root' : '');
            el.style.position = 'absolute';
            el.style.left = n.x + 'px';
            el.style.top = n.y + 'px';
            el.style.width = n.w + 'px';
            el.innerHTML = buildCoupleHTML(n.couple);
            ancContainer.appendChild(el);
        });

        // jsPlumb connections
        jsPlumb.ready(function() {
            var instance = jsPlumb.getInstance({
                Container: ancContainer,
                Connector: ['Flowchart', { cornerRadius: 4, stub: 12, midpoint: 0.5 }],
                PaintStyle: { stroke: '#888', strokeWidth: 1.5 },
                Endpoint: 'Blank'
            });

            connections.forEach(function(conn) {
                var srcEl = document.getElementById(conn.from);
                var tgtEl = document.getElementById(conn.to);
                if (!srcEl || !tgtEl) return;
                instance.connect({
                    source: srcEl,
                    target: tgtEl,
                    anchors: ['Bottom', 'Top'],
                    paintStyle: { stroke: '#888', strokeWidth: 1.5 }
                });
            });
        });
    })();

    // =====================================================================
    // PART 2: DESCENDANT TREE (Treant.js, downward)
    // =====================================================================
    (function renderDescendants() {
        if (rootCoupleIds.length === 0) {
            // Single person, no couples
            var rp = DATA.people[DATA.rootId];
            if (!rp) return;
            var rootNode = {
                innerHTML: buildPersonHTML(rp, true),
                HTMLclass: 'treept-single treept-root'
            };
            var config = {
                chart: {
                    container: '#treept-desc-container',
                    rootOrientation: 'NORTH',
                    nodeAlign: 'BOTTOM',
                    levelSeparation: 50,
                    siblingSeparation: 25,
                    subTeeSeparation: 35,
                    connectors: {
                        type: 'step',
                        style: { 'stroke-width': 1.5, 'stroke': '#888', 'arrow-end': 'none' }
                    },
                    node: { HTMLclass: 'treept-node' },
                    padding: 20,
                    scrollbar: 'None'
                },
                nodeStructure: rootNode
            };
            new Treant(config);
            return;
        }

        function buildCoupleNode(cid, visited) {
            if (!visited) visited = {};
            if (visited[cid]) return null;
            visited[cid] = true;

            var couple = couplesById[cid];
            if (!couple) return null;

            var isRoot = (couple.p1 === DATA.rootId || couple.p2 === DATA.rootId);
            var node = {
                innerHTML: buildCoupleHTML(couple),
                HTMLclass: 'treept-couple' + (isRoot ? ' treept-root' : ''),
                children: []
            };

            var children = DATA.coupleChildren[cid] || [];
            children.forEach(function(chId) {
                var childCouples = DATA.couples.filter(function(cc) {
                    return (cc.p1 === chId || cc.p2 === chId) && !visited[cc.id];
                });

                if (childCouples.length > 0) {
                    childCouples.forEach(function(cc) {
                        var childNode = buildCoupleNode(cc.id, visited);
                        if (childNode) node.children.push(childNode);
                    });
                } else {
                    var p = DATA.people[chId];
                    if (p) {
                        node.children.push({
                            innerHTML: buildPersonHTML(p, chId === DATA.rootId),
                            HTMLclass: 'treept-single' + (chId === DATA.rootId ? ' treept-root' : '')
                        });
                    }
                }
            });

            return node;
        }

        var rootNode;
        if (rootCoupleIds.length === 1) {
            rootNode = buildCoupleNode(rootCoupleIds[0], {});
        } else {
            // Multiple marriages: person node as root, each couple as a child branch
            var rp = DATA.people[DATA.rootId];
            rootNode = {
                innerHTML: buildPersonHTML(rp, true),
                HTMLclass: 'treept-single treept-root',
                children: []
            };
            var visited = {};
            rootCoupleIds.forEach(function(cid) {
                var childNode = buildCoupleNode(cid, visited);
                if (childNode) rootNode.children.push(childNode);
            });
        }
        if (!rootNode) {
            document.getElementById('treept-desc-container').innerHTML = '<p>No tree data available.</p>';
            return;
        }

        var config = {
            chart: {
                container: '#treept-desc-container',
                rootOrientation: 'NORTH',
                nodeAlign: 'BOTTOM',
                levelSeparation: 50,
                siblingSeparation: 25,
                subTeeSeparation: 35,
                connectors: {
                    type: 'step',
                    style: { 'stroke-width': 1.5, 'stroke': '#888', 'arrow-end': 'none' }
                },
                node: { HTMLclass: 'treept-node' },
                padding: 20,
                scrollbar: 'None'
            },
            nodeStructure: rootNode
        };

        new Treant(config);

        // Measure tree bounds, then centre ancestors
        setTimeout(function() {
            var descContainer = document.getElementById('treept-desc-container');

            // Measure actual descendant tree bounds from Treant .node wrappers
            var nodeEls = descContainer.querySelectorAll('.node');
            var descMinTop = Infinity;
            var descMaxRight = 0;
            var descMaxBottom = 0;
            nodeEls.forEach(function(el) {
                var t = el.offsetTop;
                if (t < descMinTop) descMinTop = t;
                var r = el.offsetLeft + el.offsetWidth;
                var b = el.offsetTop + el.offsetHeight;
                if (r > descMaxRight) descMaxRight = r;
                if (b > descMaxBottom) descMaxBottom = b;
            });

            // If any nodes overflow above the container, shift them all down
            var PAD = 20;
            if (descMinTop < PAD && nodeEls.length > 0) {
                var shiftY = PAD - descMinTop;
                nodeEls.forEach(function(el) {
                    el.style.top = (el.offsetTop + shiftY) + 'px';
                });
                descMaxBottom += shiftY;
                // Also shift any Treant SVG/canvas connectors
                var svgs = descContainer.querySelectorAll('svg, canvas');
                svgs.forEach(function(sv) {
                    var curTop = parseFloat(sv.style.top) || 0;
                    sv.style.top = (curTop + shiftY) + 'px';
                });
            }

            if (descMaxRight > 0) {
                descContainer.style.width = Math.max(descContainer.clientWidth, descMaxRight + 20) + 'px';
                descContainer.style.height = Math.max(descContainer.clientHeight, descMaxBottom + 20) + 'px';
            }

            // Centre ancestor block above the root couple in the descendant tree
            if (ancContainerWidth > 0) {
                var ancContainer = document.getElementById('treept-anc-container');
                var rootEl = descContainer.querySelector('.treept-root');
                if (rootEl && ancContainer) {
                    // Walk up to the Treant .node wrapper for the correct absolute position
                    var nodeEl = rootEl.closest('.node') || rootEl;
                    var rootCentreX = nodeEl.offsetLeft + nodeEl.offsetWidth / 2;
                    var offset = rootCentreX - ancContainerWidth / 2;

                    if (offset >= 0) {
                        ancContainer.style.marginLeft = offset + 'px';
                        ancContainer.style.marginRight = '0px';
                    } else {
                        // Ancestors wider than space left of root: shift desc tree right
                        ancContainer.style.marginLeft = '0px';
                        ancContainer.style.marginRight = '0px';
                        descContainer.style.marginLeft = (-offset) + 'px';
                        var curW = parseFloat(descContainer.style.width) || descContainer.clientWidth;
                        descContainer.style.width = (curW + (-offset)) + 'px';
                    }
                }
            }
        }, 100);
    })();

})();
</script>
