<?php

/**
 * Admin home/dashboard.
 *
 * Replaces Prog/Admin/frame.asp + adminHome.asp.
 * The old admin used a frameset (menu + content). Now it's a single page
 * with navigation links. Each admin section is a separate route.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn, $isAdmin
 */

if (!$isAdmin) {
    echo '<p>Admin access required. <a href="/login">' . $L['menu_login'] . '</a></p>';
    return;
}
?>

<div class="page-wrap">
    <h2>Administration</h2>
    <p>
        <?= $L['menu_update'] ?>
        [<a href="/admin/people"><b><?= $L['menu_people'] ?></b></a>]
        [<a href="/admin/couples"><b><?= $L['menu_couples'] ?></b></a>]
        [<a href="/admin/comments"><b><?= $L['menu_comments'] ?></b></a>]
        [<a href="/admin/documents"><b><?= $L['menu_documents'] ?></b></a>]
        [<a href="/admin/folders"><b><?= $L['menu_folders'] ?? 'folders' ?></b></a>]
        [<a href="/admin/info"><b><?= $L['news'] ?></b></a>]
        <?php if (($family['package'] ?? '') === 'Platinum'): ?>
            [<a href="/admin/messages"><b><?= $L['menu_messages'] ?></b></a>]
        <?php endif; ?>
    </p>
    <hr>
    <p>Administer your family tree.</p>
</div>
