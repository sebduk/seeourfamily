<?php
/**
 * accueil.php - Welcome / Home page
 *
 * Replaces p.accueil.asp (the default page loaded in the main frame).
 * Shows family welcome info and basic stats.
 */

$family = current_family();

if (!$family) {
    // No family selected - show family chooser
    $pdo = db_connect();
    $stmt = $pdo->query('SELECT id, name, title, hash FROM families WHERE is_online = 1 ORDER BY name');
    $families = $stmt->fetchAll();
    ?>
    <h2>Welcome to See Our Family</h2>
    <p>Select a family to view:</p>
    <ul>
        <?php foreach ($families as $f): ?>
            <li>
                <a href="?h=<?= h($f['hash'] ?? '') ?>&amp;page=accueil">
                    <?= h($f['title'] ?? $f['name']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return;
}

// Family is selected - show welcome page
$pdo = db_connect();
$fid = current_family_id();

// Gather stats
$stats = [];
$tables = ['people', 'couples', 'photos', 'comments'];
foreach ($tables as $t) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM `$t` WHERE family_id = ? AND is_online = 1");
    $stmt->execute([$fid]);
    $stats[$t] = $stmt->fetch()['cnt'];
}
?>

<h2><?= h($family['title'] ?? $family['name']) ?></h2>

<?php
// Show info page content if one exists for the welcome location
$stmt = $pdo->prepare("SELECT content FROM infos WHERE family_id = ? AND location = 'welcome' AND is_online = 1 LIMIT 1");
$stmt->execute([$fid]);
$info = $stmt->fetch();
if ($info && $info['content']) {
    echo '<div>' . nl2br(h($info['content'])) . '</div>';
}
?>

<h3>Family at a glance</h3>
<table>
    <tr><td>People</td><td><?= $stats['people'] ?></td></tr>
    <tr><td>Couples</td><td><?= $stats['couples'] ?></td></tr>
    <tr><td>Photos</td><td><?= $stats['photos'] ?></td></tr>
    <tr><td>Events &amp; stories</td><td><?= $stats['comments'] ?></td></tr>
</table>
