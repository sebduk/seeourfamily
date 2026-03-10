<?php

/**
 * Donut / Fan-chart family tree.
 *
 * Renders ancestors as concentric semicircular arcs (fan chart / donut chart).
 * The central person is in the middle; each generation forms a ring outward.
 * Clicking a segment navigates to that person's tree.
 *
 * Data collection reuses the same recursive ancestor pattern as tree.php.
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
// DATA: Collect ancestors recursively (fan chart shows ancestors outward)
// =========================================================================

$treeDoAncestors = []; // gen => [pos => {id, uuid, fn, ln, birth, death}]

function treeDoCollectAncestors(PDO $pdo, int $fid, int $personId, int $gen, int $pos, array &$result, int $maxDepth = 8): void
{
    if ($gen > $maxDepth) return;
    $stmt = $pdo->prepare(
        'SELECT p.couple_id, c.id AS cid,
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

    $fatherPos = $pos * 2;
    $motherPos = $pos * 2 + 1;
    $result[$gen][$fatherPos] = ['id' => (int)$row['p1_id'], 'uuid' => $row['p1_uuid'], 'fn' => $row['p1_fn'], 'ln' => $row['p1_ln'], 'birth' => $row['p1_birth'], 'death' => $row['p1_death']];
    $result[$gen][$motherPos] = ['id' => (int)$row['p2_id'], 'uuid' => $row['p2_uuid'], 'fn' => $row['p2_fn'], 'ln' => $row['p2_ln'], 'birth' => $row['p2_birth'], 'death' => $row['p2_death']];

    treeDoCollectAncestors($pdo, $fid, (int)$row['p1_id'], $gen + 1, $fatherPos, $result, $maxDepth);
    treeDoCollectAncestors($pdo, $fid, (int)$row['p2_id'], $gen + 1, $motherPos, $result, $maxDepth);
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

// Collect all ancestors
treeDoCollectAncestors($pdo, $fid, $personId, 1, 0, $treeDoAncestors);

$maxGen = !empty($treeDoAncestors) ? max(array_keys($treeDoAncestors)) : 0;

// Build JSON for the fan chart
$jsonRoot = [
    'id' => (int)$centralPerson['id'],
    'uuid' => $centralPerson['uuid'],
    'fn' => $centralPerson['first_name'],
    'ln' => $centralPerson['last_name'],
    'birth' => $centralPerson['birth'],
    'death' => $centralPerson['death'],
];

$jsonAncestors = [];
foreach ($treeDoAncestors as $gen => $positions) {
    foreach ($positions as $pos => $person) {
        $jsonAncestors[] = [
            'gen' => $gen,
            'pos' => $pos,
            'id' => $person['id'],
            'uuid' => $person['uuid'],
            'fn' => $person['fn'],
            'ln' => $person['ln'],
            'birth' => $person['birth'],
            'death' => $person['death'],
        ];
    }
}

$jsonData = json_encode([
    'root' => $jsonRoot,
    'ancestors' => $jsonAncestors,
    'maxGen' => $maxGen,
], JSON_HEX_TAG | JSON_HEX_AMP);

// =========================================================================
// NAVIGATION BAR
// =========================================================================
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $L['tree_donut'] ?? 'Donut' ?>
    <span class="nav-links">|
        <a href="/tree/<?= h($personUuid) ?>"><?= $L['classic'] ?></a> .
        <a href="/treeT/<?= h($personUuid) ?>"><?= $L['tree_timeline'] ?? 'Timeline' ?></a> .
        <a href="/treeTr/<?= h($personUuid) ?>"><?= $L['tree_treant'] ?? 'Treant' ?></a>
    </span>
</div>

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
    var CENTER_RADIUS = 60;     // Radius of the central person circle
    var RING_WIDTH    = 55;     // Width of each ancestor ring
    var GAP           = 2;      // Gap between segments (in pixels)
    var START_ANGLE   = -Math.PI;  // Full circle: -PI to PI (left to left)
    var END_ANGLE     = Math.PI;

    // Generation colour palette (soft pastels)
    var COLORS = [
        '#e8f0fe',  // gen 0 (root) - light blue
        '#d4e4fc',  // gen 1 - parents
        '#b8d4f0',  // gen 2 - grandparents
        '#f0e0c8',  // gen 3 - great-grandparents
        '#e8d0b0',  // gen 4
        '#d8c8b0',  // gen 5
        '#c8e0c8',  // gen 6
        '#b8d8b8',  // gen 7
        '#d8c8d8',  // gen 8
    ];

    var MALE_COLORS = [
        '#e8f0fe',
        '#c8daf8',
        '#a8c4f0',
        '#d8d0b8',
        '#c8c0a8',
        '#b8b898',
        '#a8c8a8',
        '#98b898',
        '#b8a8b8',
    ];

    var FEMALE_COLORS = [
        '#fce8f0',
        '#f0d0e0',
        '#e8c0d0',
        '#f0d8c8',
        '#e8c8b0',
        '#d8b8a0',
        '#c8d8c8',
        '#b8c8b8',
        '#d0c0d0',
    ];

    // =====================================================================
    // BUILD SEGMENT DATA
    // =====================================================================
    var segments = []; // {gen, pos, startAngle, endAngle, innerR, outerR, person, color}

    var maxGen = DATA.maxGen || 0;
    var totalAngle = END_ANGLE - START_ANGLE;

    // Index ancestors by gen+pos
    var ancestorIndex = {};
    DATA.ancestors.forEach(function(a) {
        if (!ancestorIndex[a.gen]) ancestorIndex[a.gen] = {};
        ancestorIndex[a.gen][a.pos] = a;
    });

    // Build segments for each generation
    for (var gen = 1; gen <= maxGen; gen++) {
        var slotsAtGen = Math.pow(2, gen);
        var anglePerSlot = totalAngle / slotsAtGen;
        var innerR = CENTER_RADIUS + (gen - 1) * RING_WIDTH;
        var outerR = innerR + RING_WIDTH;

        for (var pos = 0; pos < slotsAtGen; pos++) {
            var sa = START_ANGLE + pos * anglePerSlot;
            var ea = sa + anglePerSlot;
            var person = (ancestorIndex[gen] && ancestorIndex[gen][pos]) ? ancestorIndex[gen][pos] : null;
            // Even positions = father (male), odd = mother (female)
            var isFemale = (pos % 2 === 1);
            var color = person
                ? (isFemale ? FEMALE_COLORS[gen] || '#ddd' : MALE_COLORS[gen] || '#ddd')
                : '#f0f0f0';
            segments.push({
                gen: gen, pos: pos,
                startAngle: sa, endAngle: ea,
                innerR: innerR, outerR: outerR,
                person: person, color: color,
                isFemale: isFemale
            });
        }
    }

    // =====================================================================
    // CANVAS SIZING
    // =====================================================================
    var maxR = CENTER_RADIUS + maxGen * RING_WIDTH + 20;
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

        ctx.beginPath();
        ctx.arc(cx, cy, seg.outerR, seg.startAngle + gapAngle, seg.endAngle - gapAngle);
        ctx.arc(cx, cy, seg.innerR, seg.endAngle - gapAngle, seg.startAngle + gapAngle, true);
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
        var arcLen = midAngle * midR; // approximate arc length per slot
        var segArcLen = (seg.endAngle - seg.startAngle) * midR;

        // Only draw text if the segment is wide enough
        var name = seg.person.fn + ' ' + seg.person.ln;
        var dates = (seg.person.birth || '?') + '-' + (seg.person.death || '');

        // Determine font size based on available space
        var availWidth = seg.outerR - seg.innerR - 6;
        var fontSize = Math.max(7, Math.min(11, availWidth / 6));

        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(midAngle);

        // Flip text for readability in bottom half
        var flipText = (midAngle > Math.PI / 2 || midAngle < -Math.PI / 2);
        if (flipText) {
            ctx.rotate(Math.PI);
            ctx.textAlign = 'center';
            var textR = -midR;
        } else {
            ctx.textAlign = 'center';
            var textR = midR;
        }

        ctx.fillStyle = '#333';
        ctx.font = 'bold ' + fontSize + 'px sans-serif';

        // Truncate name if needed
        var maxTextW = segArcLen - 4;
        var displayName = name;
        if (ctx.measureText(displayName).width > maxTextW) {
            // Try first initial + last name
            displayName = seg.person.fn.charAt(0) + '. ' + seg.person.ln;
            if (ctx.measureText(displayName).width > maxTextW) {
                displayName = seg.person.fn.charAt(0) + '.';
            }
        }

        ctx.fillText(displayName, 0, textR - 3);

        ctx.font = fontSize * 0.85 + 'px sans-serif';
        ctx.fillStyle = '#666';
        ctx.fillText(dates, 0, textR + fontSize - 1);

        ctx.restore();
    }

    function drawCenter() {
        // Central circle for root person
        ctx.beginPath();
        ctx.arc(cx, cy, CENTER_RADIUS, 0, Math.PI * 2);
        ctx.fillStyle = COLORS[0];
        ctx.fill();
        ctx.strokeStyle = '#369';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Name
        var name = DATA.root.fn + ' ' + DATA.root.ln;
        var dates = (DATA.root.birth || '?') + '-' + (DATA.root.death || '');

        ctx.fillStyle = '#333';
        ctx.font = 'bold 12px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        // Wrap name if needed
        if (ctx.measureText(name).width > CENTER_RADIUS * 1.6) {
            ctx.fillText(DATA.root.fn, cx, cy - 12);
            ctx.fillText(DATA.root.ln, cx, cy + 2);
        } else {
            ctx.fillText(name, cx, cy - 6);
        }

        ctx.font = '10px sans-serif';
        ctx.fillStyle = '#666';
        ctx.fillText(dates, cx, cy + 14);
    }

    // =====================================================================
    // RENDER
    // =====================================================================
    function render() {
        ctx.clearRect(0, 0, canvasSize, canvasSize);

        // Draw segments (back to front)
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

        // Check center
        if (dist <= CENTER_RADIUS) {
            return { type: 'root', person: DATA.root };
        }

        // Check segments
        for (var i = 0; i < segments.length; i++) {
            var seg = segments[i];
            if (dist >= seg.innerR && dist <= seg.outerR) {
                // Normalize angle to match segment range
                var a = angle;
                // Handle angle wrapping
                var sa = seg.startAngle;
                var ea = seg.endAngle;
                if (a >= sa && a <= ea) {
                    return { type: 'segment', segment: seg, person: seg.person };
                }
                // Handle wrap-around for angles near -PI/PI
                if (sa < -Math.PI) {
                    if (a + Math.PI * 2 >= sa && a + Math.PI * 2 <= ea) {
                        return { type: 'segment', segment: seg, person: seg.person };
                    }
                }
                if (ea > Math.PI) {
                    if (a - Math.PI * 2 >= sa && a - Math.PI * 2 <= ea) {
                        return { type: 'segment', segment: seg, person: seg.person };
                    }
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
            tooltip.innerHTML = '<b>' + name + '</b><br>' + dates;
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

    canvas.addEventListener('click', function(e) {
        var rect = canvas.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;

        var hit = hitTest(mx, my);
        if (hit && hit.person && hit.person.uuid) {
            window.location.href = '/treeDo/' + hit.person.uuid;
        }
    });

    // Right-click to go to person page
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
