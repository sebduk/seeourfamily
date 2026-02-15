<?php
/**
 * home.php - Welcome / Home page
 *
 * Replaces Prog/View/intro.asp (the default page loaded in the main frame).
 *
 * Original intro.asp logic:
 *   SELECT * FROM Info WHERE InfoLocation='Intro' AND InfoIsOnline
 *   If content exists, display it; otherwise show the family title as <h1>.
 */

$family = current_family();

if (!$family) {
    // No family selected — show family chooser
    // (Old ASP handled this via domain.asp / frameDom.asp)
    $pdo = db_connect();
    $stmt = $pdo->query('SELECT id, name, title, hash FROM families WHERE is_online = 1 ORDER BY name');
    $families = $stmt->fetchAll();
    ?>
    <h2>Welcome to See Our Family</h2>
    <p>Select a family to view:</p>
    <ul>
        <?php foreach ($families as $f): ?>
            <li>
                <a href="?DomKey=<?= h($f['hash'] ?? '') ?>&amp;page=home">
                    <?= h($f['title'] ?? $f['name']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
    return;
}

// Family is selected — replicate intro.asp behavior
$pdo = db_connect();
$fid = current_family_id();
?>

<table border="0" width="80%" align="center"><tr><td>

<?php
// Original: SELECT * FROM Info WHERE InfoLocation='Intro' AND InfoIsOnline
$stmt = $pdo->prepare(
    "SELECT content FROM infos WHERE family_id = ? AND location = 'Intro' AND is_online = 1 LIMIT 1"
);
$stmt->execute([$fid]);
$info = $stmt->fetch();

if ($info && $info['content']) {
    echo $info['content'];
} else {
    echo '<h1>' . $family_title . '</h1>';
}
?>

</td></tr></table>
