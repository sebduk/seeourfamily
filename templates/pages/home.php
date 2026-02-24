<?php

/**
 * Home page template.
 *
 * Replaces Prog/View/intro.asp (the default page loaded in the main frame).
 *
 * Original intro.asp logic:
 *   SELECT * FROM Info WHERE InfoLocation='Intro' AND InfoIsOnline
 *   If content exists, display it; otherwise show the family title as <h1>.
 *
 * Available variables from index.php:
 *   $db, $auth, $router, $family, $familyTitle, $L, $isLoggedIn
 */

if (!$family) {
    if (!$isLoggedIn) {
        // Not logged in and no family context — redirect to login
        header('Location: /login');
        exit;
    }
    // Logged in but no family selected — show family chooser
    $uid = $auth->userId();
    if ($uid !== null) {
        $userFamilies = $auth->userFamilies($uid);
        if (count($userFamilies) === 1) {
            // Single family — auto-select
            $auth->setFamilyById((int)$userFamilies[0]['family_id']);
            header('Location: /home');
            exit;
        }
        ?>
        <h2>Welcome to See Our Family</h2>
        <p>Select a family to view:</p>
        <ul>
            <?php foreach ($userFamilies as $f): ?>
                <li>
                    <form action="/login" method="post" style="display:inline;">
                        <input type="hidden" name="select_family_id" value="<?= (int)$f['family_id'] ?>">
                        <button type="submit" style="background:none; border:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                            <?= h($f['title'] ?? $f['name']) ?>
                        </button>
                        <small style="color:#666;">(<?= h($f['role']) ?>)</small>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return;
    }
    // Fallback: no user_id in session (legacy family-password login without family set)
    header('Location: /login');
    exit;
}

// Family is selected — replicate intro.asp behavior
$fid = $auth->familyId();
?>

<table border="0" width="80%" align="center"><tr><td>

<?php
// Original: SELECT * FROM Info WHERE InfoLocation='Intro' AND InfoIsOnline
$stmt = $db->pdo()->prepare(
    "SELECT content FROM infos WHERE family_id = ? AND location = 'Intro' AND is_online = 1 LIMIT 1"
);
$stmt->execute([$fid]);
$info = $stmt->fetch();

if ($info && $info['content']) {
    echo fix_utf8($info['content']);
} else {
    echo '<h1>' . $familyTitle . '</h1>';
}
?>

</td></tr></table>
