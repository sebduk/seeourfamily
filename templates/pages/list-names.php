<?php

/**
 * Name / date list page.
 *
 * Replaces Prog/View/lstNomDate.asp.
 * CSS columns layout replaces the 3-column <table>.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();

$sort   = $_GET['sort'] ?? $_POST['tri'] ?? 'name';
$search = $_GET['search'] ?? $_POST['search'] ?? '';

// Build query
$sql = 'SELECT p.id, p.uuid, p.last_name, p.first_name,
               IFNULL(DATE_FORMAT(p.birth_date, "%Y"), "") AS birth,
               IFNULL(DATE_FORMAT(p.death_date, "%Y"), "") AS death,
               p.updated_at,
               COUNT(dpl.document_id) AS photo_count
        FROM people p
        LEFT JOIN document_person_link dpl ON dpl.person_id = p.id
        WHERE p.family_id = ?';
$params = [$fid];

if ($search !== '') {
    $sql .= ' AND CONCAT(p.last_name, p.first_name, p.last_name) LIKE ?';
    $params[] = '%' . $search . '%';
}

$sql .= ' GROUP BY p.id, p.last_name, p.first_name, p.birth_date, p.death_date, p.updated_at';

switch ($sort) {
    case 'year':
        $sql .= ' ORDER BY p.birth_date, p.death_date, p.last_name, p.first_name';
        break;
    case 'last':
        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';
        break;
    default:
        $sql .= ' ORDER BY p.last_name, p.first_name, p.birth_date';
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$people = $stmt->fetchAll();
$total = count($people);
?>

<!-- Header: "Last updates" link + search form -->
<div class="name-list-header">
    <div>
        <a href="/list-names?sort=last"><?= $L['last_updates'] ?></a>
    </div>
    <form action="/list-names" method="get">
        <input type="hidden" name="sort" value="<?= h($sort) ?>">
        <input type="text" name="search" value="<?= h($search) ?>" class="box">
        <input type="submit" value="<?= h($L['search']) ?>" class="box">
    </form>
</div>

<!-- Name list: CSS columns instead of 3-col table -->
<div class="name-list">
    <?php foreach ($people as $p):
        $hasPhoto = $p['photo_count'] > 0;
        $icon = $hasPhoto ? '/Image/Icon/logPhoto.gif' : '/Image/Icon/logBio.gif';

        if ($sort === 'year') {
            $display = '(' . h($p['birth']) . '-' . h($p['death']) . ')&nbsp;'
                     . h($p['last_name']) . '&nbsp;' . h($p['first_name']);
        } else {
            $display = h($p['last_name']) . '&nbsp;' . h($p['first_name'])
                     . '&nbsp;(' . h($p['birth']) . '-' . h($p['death']) . ')';
        }
    ?>
    <div class="name-list-item">
        <a href="/person/<?= h($p['uuid']) ?>"><img src="<?= $icon ?>" alt=""></a>
        <a href="/tree/<?= h($p['uuid']) ?>"><?= $display ?></a>
    </div>
    <?php endforeach; ?>
</div>

<div class="name-list-footer">
    <?= $total ?> <?= $L['individuals'] ?>
</div>
