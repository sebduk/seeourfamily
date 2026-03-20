<?php

/**
 * Descendant Donut / Fan-chart.
 *
 * Renders descendants as concentric arcs radiating outward from a central person.
 * Angular space is proportional to total descendant count (a branch with many
 * descendants gets more room). Remarriage means a person may appear in multiple
 * branches with separate angular portions.
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
// DATA: Collect descendants recursively into a JSON tree
// =========================================================================

/**
 * Find all couples for a person.
 */
function treeDoDescCouples(PDO $pdo, int $fid, int $personId): array
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
function treeDoDescChildren(PDO $pdo, int $fid, int $coupleId): array
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
 * Build a recursive descendant tree. Returns a node with:
 *   id, uuid, fn, ln, birth, death, unions: [ {spouse: {...}, children: [node, ...]} ]
 * Also sets 'descCount' = total number of leaf descendants (minimum 1 for leaves).
 */
function treeDoDescBuild(PDO $pdo, int $fid, int $personId, int $depth = 0, int $maxDepth = 12): ?array
{
    if ($depth > $maxDepth) return null;

    $stmt = $pdo->prepare(
        'SELECT id, uuid, first_name, last_name,
                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
                IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
         FROM people WHERE id = ? AND family_id = ?'
    );
    $stmt->execute([$personId, $fid]);
    $person = $stmt->fetch();
    if (!$person) return null;

    $node = [
        'id' => (int)$person['id'],
        'uuid' => $person['uuid'],
        'fn' => $person['first_name'],
        'ln' => $person['last_name'],
        'birth' => $person['birth'],
        'death' => $person['death'],
        'unions' => [],
        'descCount' => 0,
    ];

    $couples = treeDoDescCouples($pdo, $fid, $personId);
    foreach ($couples as $c) {
        $spouseId = ((int)$c['p1_id'] === $personId) ? (int)$c['p2_id'] : (int)$c['p1_id'];
        $spouseData = ((int)$c['p1_id'] === $personId)
            ? ['id' => (int)$c['p2_id'], 'uuid' => $c['p2_uuid'], 'fn' => $c['p2_fn'], 'ln' => $c['p2_ln'], 'birth' => $c['p2_birth'], 'death' => $c['p2_death']]
            : ['id' => (int)$c['p1_id'], 'uuid' => $c['p1_uuid'], 'fn' => $c['p1_fn'], 'ln' => $c['p1_ln'], 'birth' => $c['p1_birth'], 'death' => $c['p1_death']];

        $children = treeDoDescChildren($pdo, $fid, (int)$c['couple_id']);
        $childNodes = [];
        $unionDescCount = 0;
        foreach ($children as $child) {
            $childNode = treeDoDescBuild($pdo, $fid, (int)$child['id'], $depth + 1, $maxDepth);
            if ($childNode) {
                $childNodes[] = $childNode;
                $unionDescCount += $childNode['descCount'];
            }
        }
        // If no children, this union still counts as 1 (the couple itself is a leaf)
        if ($unionDescCount === 0) $unionDescCount = 1;

        $node['unions'][] = [
            'spouse' => $spouseData,
            'children' => $childNodes,
            'descCount' => $unionDescCount,
        ];
        $node['descCount'] += $unionDescCount;
    }

    // A person with no unions is a leaf, counts as 1
    if (empty($node['unions'])) {
        $node['descCount'] = 1;
    }

    return $node;
}

// Central person
$stmt = $pdo->prepare(
    'SELECT id, uuid, first_name, last_name,
            IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth,
            IFNULL(DATE_FORMAT(death_date, "%Y"), "") AS death
     FROM people WHERE id = ? AND family_id = ?'
);
$stmt->execute([$personId, $fid]);
$centralPerson = $stmt->fetch();

if (!$centralPerson) {
    echo '<p>Person not found.</p>';
    return;
}

$personName = $centralPerson['first_name'] . ' ' . $centralPerson['last_name'];
$personUuid = $centralPerson['uuid'];

$tree = treeDoDescBuild($pdo, $fid, $personId);

$jsonData = json_encode($tree, JSON_HEX_TAG | JSON_HEX_AMP);

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<?php $treeNavUuid = h($personUuid); $treeNavCurrent = 'donut_desc'; require __DIR__ . '/../_tree-nav.php'; ?>

<div id="treedo-wrap">
    <canvas id="treedo-canvas"></canvas>
    <div id="treedo-tooltip" class="treedo-tooltip"></div>
</div>

<script>
(function() {
    'use strict';

    var DATA = <?= $jsonData ?>;
    var canvas = document.getElementById('treedo-canvas');
    var ctx = canvas.getContext('2d');
    var tooltip = document.getElementById('treedo-tooltip');

    // =====================================================================
    // CONFIGURATION
    // =====================================================================
    var CENTER_RADIUS = 80;     // Central person circle radius (enlarged for couple)
    var RING_WIDTH    = 55;     // Width of each generation ring
    var GAP           = 2;      // Gap between segments (pixels)
    var START_ANGLE   = -Math.PI;
    var END_ANGLE     = Math.PI;
    var TOTAL_ANGLE   = END_ANGLE - START_ANGLE;

    // Generation colour palette
    var GEN_COLORS = [
        '#e8f0fe',  // gen 0 (root)
        '#d4e4fc',  // gen 1
        '#b8d4f0',  // gen 2
        '#f0e0c8',  // gen 3
        '#e8d0b0',  // gen 4
        '#d8c8b0',  // gen 5
        '#c8e0c8',  // gen 6
        '#b8d8b8',  // gen 7
        '#d8c8d8',  // gen 8
        '#c8b8c8',  // gen 9
        '#b8c8d8',  // gen 10
        '#d8d8b8',  // gen 11
        '#c8d8c8',  // gen 12
    ];

    // =====================================================================
    // FLATTEN TREE INTO SEGMENTS
    // =====================================================================
    // Each segment: {gen, startAngle, endAngle, innerR, outerR, person, color, isSpouse}
    var segments = [];
    var maxGenSeen = 0;

    /**
     * Recursively lay out a person's descendants.
     * @param {Object} node - tree node with unions/children
     * @param {number} gen - generation depth (0 = root)
     * @param {number} aStart - start angle for this node's allocation
     * @param {number} aEnd - end angle for this node's allocation
     */
    function layoutNode(node, gen, aStart, aEnd) {
        if (gen > maxGenSeen) maxGenSeen = gen;

        var innerR = CENTER_RADIUS + gen * RING_WIDTH;
        var outerR = innerR + RING_WIDTH;
        var color = GEN_COLORS[gen % GEN_COLORS.length] || '#ddd';

        // Add segment for this person (skip gen 0, drawn as center circle)
        if (gen > 0) {
            segments.push({
                gen: gen,
                startAngle: aStart,
                endAngle: aEnd,
                innerR: innerR,
                outerR: outerR,
                person: node,
                color: color,
                isSpouse: false
            });
        }

        // If no unions, this is a leaf — nothing more to draw
        if (!node.unions || node.unions.length === 0) return;

        // Distribute angular space among unions proportionally
        var totalDesc = node.descCount || 1;
        var cursor = aStart;

        for (var u = 0; u < node.unions.length; u++) {
            var union = node.unions[u];
            var unionAngle = (union.descCount / totalDesc) * (aEnd - aStart);
            var unionStart = cursor;
            var unionEnd = cursor + unionAngle;

            // If there are children, distribute among them proportionally
            if (union.children.length > 0) {
                var childCursor = unionStart;
                var childTotalDesc = 0;
                for (var c = 0; c < union.children.length; c++) {
                    childTotalDesc += union.children[c].descCount || 1;
                }

                for (var c = 0; c < union.children.length; c++) {
                    var child = union.children[c];
                    var childAngle = ((child.descCount || 1) / childTotalDesc) * unionAngle;
                    layoutNode(child, gen + 1, childCursor, childCursor + childAngle);
                    childCursor += childAngle;
                }
            }

            cursor = unionEnd;
        }
    }

    // Layout starting from root
    if (DATA) {
        layoutNode(DATA, 0, START_ANGLE, END_ANGLE);
    }

    // =====================================================================
    // CANVAS SIZING
    // =====================================================================
    var maxR = CENTER_RADIUS + (maxGenSeen + 1) * RING_WIDTH + 20;
    var canvasSize = maxR * 2 + 40;
    var dpr = window.devicePixelRatio || 1;

    canvas.width = canvasSize * dpr;
    canvas.height = canvasSize * dpr;
    canvas.style.width = canvasSize + 'px';
    canvas.style.height = canvasSize + 'px';
    ctx.scale(dpr, dpr);

    var cx = canvasSize / 2;
    var cy = canvasSize / 2;

    // =====================================================================
    // DRAWING FUNCTIONS
    // =====================================================================
    function drawSegment(seg) {
        var gapAngle = GAP / ((seg.innerR + seg.outerR) / 2);
        var sa = seg.startAngle + gapAngle;
        var ea = seg.endAngle - gapAngle;
        if (ea <= sa) return; // too narrow

        ctx.beginPath();
        ctx.arc(cx, cy, seg.outerR, sa, ea);
        ctx.arc(cx, cy, seg.innerR, ea, sa, true);
        ctx.closePath();

        ctx.fillStyle = seg.color;
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 1;
        ctx.stroke();
    }

    function drawSegmentText(seg) {
        if (!seg.person) return;

        var midAngle = (seg.startAngle + seg.endAngle) / 2;
        var midR = (seg.innerR + seg.outerR) / 2;
        var segArcLen = (seg.endAngle - seg.startAngle) * midR;

        // Skip text if segment is too narrow
        if (segArcLen < 20) return;

        var name = seg.person.fn + ' ' + seg.person.ln;
        var dates = (seg.person.birth || '?') + '-' + (seg.person.death || '');

        var availWidth = seg.outerR - seg.innerR - 6;
        var fontSize = Math.max(7, Math.min(11, availWidth / 6));

        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(midAngle);

        // Flip text for readability in bottom half
        var flipText = (midAngle > Math.PI / 2 || midAngle < -Math.PI / 2);
        if (flipText) {
            ctx.rotate(Math.PI);
        }
        ctx.textAlign = 'center';
        var textR = flipText ? -midR : midR;

        ctx.fillStyle = '#333';
        ctx.font = 'bold ' + fontSize + 'px sans-serif';

        // Truncate name if needed
        var maxTextW = segArcLen - 4;
        var displayName = name;
        if (ctx.measureText(displayName).width > maxTextW) {
            displayName = seg.person.fn.charAt(0) + '. ' + seg.person.ln;
            if (ctx.measureText(displayName).width > maxTextW) {
                displayName = seg.person.fn.charAt(0) + '.';
                if (ctx.measureText(displayName).width > maxTextW) {
                    ctx.restore();
                    return;
                }
            }
        }

        ctx.fillText(displayName, textR, -3);

        ctx.font = fontSize * 0.85 + 'px sans-serif';
        ctx.fillStyle = '#666';
        ctx.fillText(dates, textR, fontSize - 1);

        ctx.restore();
    }

    function drawCenter() {
        if (!DATA) return;

        ctx.beginPath();
        ctx.arc(cx, cy, CENTER_RADIUS, 0, Math.PI * 2);
        ctx.fillStyle = GEN_COLORS[0];
        ctx.fill();
        ctx.strokeStyle = '#369';
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        var spouse = (DATA.unions && DATA.unions.length > 0) ? DATA.unions[0].spouse : null;
        var total = (DATA.descCount || 1) - 1;

        if (spouse) {
            // --- Couple display ---
            var name1 = DATA.fn + ' ' + DATA.ln;
            var dates1 = (DATA.birth || '?') + '-' + (DATA.death || '');
            var name2 = spouse.fn + ' ' + spouse.ln;
            var dates2 = (spouse.birth || '?') + '-' + (spouse.death || '');

            // Person 1 (top half)
            ctx.fillStyle = '#333';
            ctx.font = 'bold 11px sans-serif';
            var maxW = CENTER_RADIUS * 1.6;
            if (ctx.measureText(name1).width > maxW) {
                ctx.fillText(DATA.fn, cx, cy - 32);
                ctx.fillText(DATA.ln, cx, cy - 19);
            } else {
                ctx.fillText(name1, cx, cy - 26);
            }
            ctx.font = '9px sans-serif';
            ctx.fillStyle = '#666';
            ctx.fillText(dates1, cx, cy - 11);

            // Separator
            ctx.fillStyle = '#999';
            ctx.font = '9px sans-serif';
            ctx.fillText('&', cx, cy + 1);

            // Person 2 (bottom half)
            ctx.fillStyle = '#333';
            ctx.font = 'bold 11px sans-serif';
            if (ctx.measureText(name2).width > maxW) {
                ctx.fillText(spouse.fn, cx, cy + 14);
                ctx.fillText(spouse.ln, cx, cy + 27);
            } else {
                ctx.fillText(name2, cx, cy + 18);
            }
            ctx.font = '9px sans-serif';
            ctx.fillStyle = '#666';
            ctx.fillText(dates2, cx, cy + 33);

            // Descendant count
            if (total > 0) {
                ctx.font = '9px sans-serif';
                ctx.fillStyle = '#999';
                ctx.fillText(total + ' desc.', cx, cy + 47);
            }
        } else {
            // --- Single person display ---
            var name = DATA.fn + ' ' + DATA.ln;
            var dates = (DATA.birth || '?') + '-' + (DATA.death || '');

            ctx.fillStyle = '#333';
            ctx.font = 'bold 12px sans-serif';

            if (ctx.measureText(name).width > CENTER_RADIUS * 1.6) {
                ctx.fillText(DATA.fn, cx, cy - 12);
                ctx.fillText(DATA.ln, cx, cy + 2);
            } else {
                ctx.fillText(name, cx, cy - 6);
            }

            ctx.font = '10px sans-serif';
            ctx.fillStyle = '#666';
            ctx.fillText(dates, cx, cy + 14);

            if (total > 0) {
                ctx.font = '9px sans-serif';
                ctx.fillStyle = '#999';
                ctx.fillText(total + ' desc.', cx, cy + 28);
            }
        }
    }

    // =====================================================================
    // RENDER
    // =====================================================================
    function render() {
        ctx.clearRect(0, 0, canvasSize, canvasSize);
        segments.forEach(drawSegment);
        segments.forEach(drawSegmentText);
        drawCenter();
    }

    render();

    // =====================================================================
    // INTERACTION: Hover tooltip + click navigation
    // =====================================================================
    function hitTest(mx, my) {
        var dx = mx - cx;
        var dy = my - cy;
        var dist = Math.sqrt(dx * dx + dy * dy);
        var angle = Math.atan2(dy, dx);

        if (dist <= CENTER_RADIUS) {
            return { type: 'root', person: DATA };
        }

        for (var i = 0; i < segments.length; i++) {
            var seg = segments[i];
            if (dist >= seg.innerR && dist <= seg.outerR) {
                var a = angle;
                var sa = seg.startAngle;
                var ea = seg.endAngle;
                if (a >= sa && a <= ea) {
                    return { type: 'segment', segment: seg, person: seg.person };
                }
                if (sa < -Math.PI && a + Math.PI * 2 >= sa && a + Math.PI * 2 <= ea) {
                    return { type: 'segment', segment: seg, person: seg.person };
                }
                if (ea > Math.PI && a - Math.PI * 2 >= sa && a - Math.PI * 2 <= ea) {
                    return { type: 'segment', segment: seg, person: seg.person };
                }
            }
        }

        return null;
    }

    canvas.addEventListener('mousemove', function(e) {
        var rect = canvas.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;

        var hit = hitTest(mx, my);

        if (hit && hit.person) {
            var p = hit.person;
            var name = p.fn + ' ' + p.ln;
            var dates = (p.birth || '?') + ' - ' + (p.death || '');
            var desc = (p.descCount || 1) - 1;
            var descText = desc > 0 ? '<br>' + desc + ' descendant' + (desc > 1 ? 's' : '') : '';
            tooltip.innerHTML = '<b>' + name + '</b><br>' + dates + descText;
            tooltip.style.display = 'block';
            tooltip.style.left = (e.clientX - rect.left + 12) + 'px';
            tooltip.style.top = (e.clientY - rect.top - 10) + 'px';
            canvas.style.cursor = 'pointer';
        } else {
            tooltip.style.display = 'none';
            canvas.style.cursor = 'default';
        }
    });

    canvas.addEventListener('mouseleave', function() {
        tooltip.style.display = 'none';
    });

    // Left-click: re-center the fan chart on this person
    canvas.addEventListener('click', function(e) {
        var rect = canvas.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;

        var hit = hitTest(mx, my);
        if (hit && hit.person && hit.person.uuid) {
            window.location.href = '/treeDoDesc/' + hit.person.uuid;
        }
    });

    // Right-click: go to person page
    canvas.addEventListener('contextmenu', function(e) {
        var rect = canvas.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;

        var hit = hitTest(mx, my);
        if (hit && hit.person && hit.person.uuid) {
            e.preventDefault();
            window.location.href = '/person/' + hit.person.uuid;
        }
    });

})();
</script>
