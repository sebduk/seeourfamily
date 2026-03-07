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

require __DIR__ . '/../_admin-nav.php';
?>
<div class="section-title">Administer your family</div>