<?php

/**
 * Documents list page.
 *
 * Replaces Prog/View/lstDocs.asp.
 * Modern <table> styling (.data-table) replaces bgcolor + spacer cells.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();

$familyName = $family['name'] ?? '';

// Sort parameters (s=column, o=direction)
$sortCol = $_GET['s'] ?? '2';
$sortDir = $_GET['o'] ?? 'u';

$sql = "SELECT id, uuid, file_name, original_filename, stored_filename, description, photo_date, file_size, created_at
        FROM photos
        WHERE family_id = ?
          AND (
            (file_name IS NOT NULL AND LOWER(RIGHT(file_name, 3)) NOT IN ('jpg','gif','png')
              AND LOWER(RIGHT(file_name, 4)) <> 'jpeg')
            OR (stored_filename IS NOT NULL AND file_name IS NULL
              AND LOWER(RIGHT(stored_filename, 3)) NOT IN ('jpg','gif','png')
              AND LOWER(RIGHT(stored_filename, 4)) <> 'jpeg')
          )";
$params = [$fid];

switch ($sortCol) {
    case '1':
        $sql .= ' ORDER BY COALESCE(original_filename, file_name)' . ($sortDir === 'd' ? ' DESC' : '') . ', photo_date';
        break;
    case '2':
    default:
        $sql .= ' ORDER BY photo_date' . ($sortDir === 'd' ? ' DESC' : '') . ', COALESCE(original_filename, file_name)';
        break;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll();

// Sortable header link helper
function sortLink(string $col, string $currentCol, string $currentDir, string $label): string
{
    $newDir = ($col === $currentCol && $currentDir === 'u') ? 'd' : 'u';
    return '<a href="/documents?s=' . $col . '&amp;o=' . $newDir . '"><b>' . $label . '</b></a>';
}
?>

<table class="data-table">
    <thead>
        <tr>
            <th class="doc-icon">&nbsp;</th>
            <th><?= sortLink('1', $sortCol, $sortDir, $L['file_name']) ?></th>
            <th><?= sortLink('2', $sortCol, $sortDir, $L['date']) ?></th>
            <th style="text-align:right"><?= $L['size'] ?? 'Size' ?></th>
            <th style="text-align:right"><?= $L['uploaded'] ?? 'Uploaded' ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($docs as $doc):
        $docFileName = $doc['original_filename'] ?? $doc['file_name'] ?? $doc['stored_filename'] ?? '';
        $ext = strtolower(pathinfo($docFileName, PATHINFO_EXTENSION));
        $knownIcons = ['doc','mdb','pdf','ppt','pps','txt','xls','zip'];
        $icon = in_array($ext, $knownIcons) ? $ext : 'other';
        $baseName = pathinfo($docFileName, PATHINFO_FILENAME);

        // Participants
        $stmt = $pdo->prepare(
            'SELECT p.id, p.uuid, p.first_name, p.last_name FROM people p
             JOIN photo_person_link ppl ON ppl.person_id = p.id
             WHERE ppl.photo_id = ?
             ORDER BY p.last_name, p.first_name'
        );
        $stmt->execute([$doc['id']]);
        $participants = $stmt->fetchAll();
    ?>
        <tr>
            <td class="doc-icon"><img src="/Image/Icon/<?= $icon ?>.gif" alt="<?= h($ext) ?>"></td>
            <td><a href="/media/<?= h($doc['uuid']) ?>"><b><?= h($baseName) ?></b></a></td>
            <td><?= h($doc['photo_date'] ?? '') ?></td>
            <td style="text-align:right"><?php if ($doc['file_size']): ?><?= number_format((int)$doc['file_size'] / 1024, 0) ?> KB<?php endif; ?></td>
            <td style="text-align:right"><?= $doc['created_at'] ? h(date('d/m/Y', strtotime($doc['created_at']))) : '' ?></td>
        </tr>
        <?php if ($doc['description'] || $participants): ?>
        <tr>
            <td>&nbsp;</td>
            <td colspan="4">
                <?php if ($doc['description']): ?><?= h($doc['description']) ?><br><?php endif; ?>
                <?php if ($participants): ?>
                    <?php foreach ($participants as $i => $p):
                        if ($i > 0) echo ', ';
                    ?>
                        <a href="/person/<?= h($p['uuid']) ?>"><?= h($p['first_name']) ?>&nbsp;<?= h($p['last_name']) ?></a>
                    <?php endforeach; ?>.
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
