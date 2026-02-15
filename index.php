<?php
/**
 * index.php - Main entry point for See Our Family
 *
 * Replaces the old p.frame.asp frameset with a single-page CSS layout.
 *
 * Old ASP frameset structure (p.frame.asp):
 *   <frameset rows="70,*">
 *     <frame src="p.top.asp">           -- header banner
 *     <frameset cols="200,*">
 *       <frame src="p.menu.asp">        -- left navigation
 *       <frame src="p.accueil.asp">     -- main content (welcome page)
 *     </frameset>
 *   </frameset>
 *
 * This file replaces all three frames with a single-page layout using
 * CSS Grid. Content is loaded via the ?page= query parameter.
 *
 * Old ASP pages -> new PHP pages:
 *   p.top.asp        -> (inline header in this file)
 *   p.menu.asp       -> (inline nav in this file)
 *   p.accueil.asp    -> pages/accueil.php      (welcome / home)
 *   p.arbre.asp      -> pages/arbre.php        (family tree view)
 *   p.personne.asp   -> pages/personne.php     (person detail)
 *   p.couple.asp     -> pages/couple.php       (couple detail)
 *   p.photo.asp      -> pages/photo.php        (photo gallery)
 *   p.photodet.asp   -> pages/photodet.php     (single photo detail)
 *   p.commentaire.asp-> pages/commentaire.php  (comments / events)
 *   p.forum.asp      -> pages/forum.php        (forum list)
 *   p.forumitem.asp  -> pages/forumitem.php    (forum thread)
 *   p.info.asp       -> pages/info.php         (info pages)
 *   p.recherche.asp  -> pages/recherche.php    (search)
 *   p.admin.asp      -> pages/admin.php        (admin panel)
 *   p.login.asp      -> pages/login.php        (login form)
 */

require_once __DIR__ . '/db.php';

// =========================================================================
// ROUTING
// =========================================================================

// Allowed pages (whitelist to prevent path traversal)
$allowed_pages = [
    'accueil', 'arbre', 'personne', 'couple',
    'photo', 'photodet', 'commentaire',
    'forum', 'forumitem', 'info',
    'recherche', 'admin', 'login',
];

$page = $_GET['page'] ?? 'accueil';
if (!in_array($page, $allowed_pages, true)) {
    $page = 'accueil';
}

// =========================================================================
// FAMILY CONTEXT
// =========================================================================

// If a family hash is in the URL, resolve it and store in session
if (isset($_GET['h'])) {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT id FROM families WHERE hash = ? AND is_online = 1');
    $stmt->execute([$_GET['h']]);
    $row = $stmt->fetch();
    if ($row) {
        $_SESSION['family_id'] = (int)$row['id'];
    }
}

$family = current_family();
$family_title = $family ? h($family['title'] ?? $family['name']) : 'See Our Family';
$lang = $family['language'] ?? 'ENG';

?>
<!DOCTYPE html>
<html lang="<?= $lang === 'FRA' ? 'fr' : ($lang === 'ESP' ? 'es' : 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $family_title ?></title>
    <style>
        /* ============================================================
         * Single-page layout replacing the old 3-frame frameset.
         *
         * Old frameset:
         *   rows="70,*"   -> header is 70px, rest fills viewport
         *   cols="200,*"  -> left nav is 200px, main fills rest
         *
         * New layout uses CSS Grid to replicate the same geometry.
         * ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Verdana, Geneva, Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #333;
            background: #f5f5f0;
        }

        .layout {
            display: grid;
            grid-template-areas:
                "header header"
                "nav    main";
            grid-template-rows: 70px 1fr;
            grid-template-columns: 200px 1fr;
            min-height: 100vh;
        }

        /* ------- HEADER (was p.top.asp / top frame) ------- */
        .header {
            grid-area: header;
            background: #336;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            border-bottom: 2px solid #224;
        }

        .header h1 {
            font-size: 18px;
            font-weight: bold;
        }

        .header .user-info {
            font-size: 12px;
        }

        .header .user-info a {
            color: #cdf;
            text-decoration: none;
        }

        /* ------- LEFT NAV (was p.menu.asp / left frame) ------- */
        .nav {
            grid-area: nav;
            background: #eee;
            border-right: 1px solid #ccc;
            padding: 10px 0;
            overflow-y: auto;
        }

        .nav a {
            display: block;
            padding: 8px 15px;
            color: #336;
            text-decoration: none;
            font-size: 12px;
            border-bottom: 1px solid #ddd;
        }

        .nav a:hover,
        .nav a.active {
            background: #dde;
            font-weight: bold;
        }

        .nav .nav-section {
            padding: 10px 15px 4px;
            font-size: 10px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ------- MAIN CONTENT (was the right frame) ------- */
        .main {
            grid-area: main;
            padding: 20px;
            overflow-y: auto;
        }

        /* ------- RESPONSIVE: collapse nav on small screens ------- */
        @media (max-width: 768px) {
            .layout {
                grid-template-areas:
                    "header"
                    "nav"
                    "main";
                grid-template-rows: 60px auto 1fr;
                grid-template-columns: 1fr;
            }

            .nav {
                border-right: none;
                border-bottom: 1px solid #ccc;
                display: flex;
                flex-wrap: wrap;
                padding: 5px;
            }

            .nav a {
                border-bottom: none;
                padding: 5px 10px;
            }

            .nav .nav-section {
                width: 100%;
                padding: 5px 10px 2px;
            }
        }
    </style>
</head>
<body>

<div class="layout">

    <!-- ============================================================
         HEADER - replaces p.top.asp
         ============================================================ -->
    <header class="header">
        <h1><?= $family_title ?></h1>
        <div class="user-info">
            <?php if (current_user_id()): ?>
                <a href="?page=admin">Admin</a> |
                <a href="?page=login&amp;action=logout">Logout</a>
            <?php else: ?>
                <a href="?page=login">Login</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- ============================================================
         LEFT NAV - replaces p.menu.asp
         ============================================================ -->
    <nav class="nav">
        <div class="nav-section">Family</div>
        <a href="?page=accueil"   class="<?= $page === 'accueil' ? 'active' : '' ?>">Home</a>
        <a href="?page=arbre"     class="<?= $page === 'arbre' ? 'active' : '' ?>">Family Tree</a>
        <a href="?page=recherche" class="<?= $page === 'recherche' ? 'active' : '' ?>">Search</a>

        <div class="nav-section">Media</div>
        <a href="?page=photo"        class="<?= $page === 'photo' ? 'active' : '' ?>">Photos</a>
        <a href="?page=commentaire"  class="<?= $page === 'commentaire' ? 'active' : '' ?>">Events</a>

        <div class="nav-section">Community</div>
        <a href="?page=forum" class="<?= $page === 'forum' ? 'active' : '' ?>">Forum</a>
        <a href="?page=info"  class="<?= $page === 'info' ? 'active' : '' ?>">Info</a>
    </nav>

    <!-- ============================================================
         MAIN CONTENT - replaces the right content frame
         Default page was p.accueil.asp (welcome/home)
         ============================================================ -->
    <main class="main">
        <?php
        $page_file = __DIR__ . '/pages/' . $page . '.php';
        if (file_exists($page_file)) {
            require $page_file;
        } else {
            echo '<h2>Page not yet implemented</h2>';
            echo '<p>The page <strong>' . h($page) . '</strong> is coming soon.</p>';
        }
        ?>
    </main>

</div>

</body>
</html>
