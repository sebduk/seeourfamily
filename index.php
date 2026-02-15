<?php
/**
 * index.php - Main entry point for See Our Family
 *
 * Replaces the old p.frame.asp frameset with a single-page CSS layout.
 *
 * ACTUAL old ASP frameset structure (p.frame.asp):
 *   <frameset rows="20,*,16">
 *     <frame name=menu src=/Prog/p.menu.asp scrolling=no>   -- 20px horizontal menu bar
 *     <frame name=main src=/Prog/View/intro.asp>             -- main content area
 *     <frame name=lang src=/Prog/p.lang.asp>                 -- 16px language selector
 *   </frameset>
 *
 * The menu bar (p.menu.asp) showed bracket-style links:
 *   [home] > genealogy by [names] [years] [birthdays] [pictures] [documents] [messages]
 *   Right side: [help] [admin]
 *   When not logged in: [home] ... [help] [login]
 *
 * The admin section (Prog/Admin/frame.asp) had its own sub-frameset:
 *   <frameset rows="20,*">
 *     <frame src=menu.asp>       -- admin menu bar
 *     <frame src=adminHome.asp>  -- admin content
 *   </frameset>
 *
 * The tree view (Prog/View/frame.asp) also had a sub-frameset:
 *   <frameset rows="40,*">
 *     <frame src=arbre.out.asp>  -- tree navigation/controls
 *     <frame src=arbre.asp>      -- tree display
 *   </frameset>
 *
 * Old ASP pages -> new PHP pages (English names):
 *
 *   View pages (loaded in main frame):
 *     Prog/View/intro.asp           -> pages/home.php          (welcome / home)
 *     Prog/View/frame.asp           -> pages/tree.php          (family tree wrapper)
 *     Prog/View/arbre.asp           -> pages/tree.php          (tree display)
 *     Prog/View/arbre.out.asp       -> (inline in tree.php)    (tree navigation bar)
 *     Prog/View/arbre.asc.h.asp     -> pages/tree.php?dir=asc&style=horizontal
 *     Prog/View/arbre.asc.v.asp     -> pages/tree.php?dir=asc&style=vertical
 *     Prog/View/arbre.asc.tab.asp   -> pages/tree.php?dir=asc&style=table
 *     Prog/View/arbre.asc.excel.asp -> pages/tree.php?dir=asc&style=excel
 *     Prog/View/arbre.desc.h.asp    -> pages/tree.php?dir=desc&style=horizontal
 *     Prog/View/arbre.desc.v.asp    -> pages/tree.php?dir=desc&style=vertical
 *     Prog/View/arbre.desc.tab.asp  -> pages/tree.php?dir=desc&style=table
 *     Prog/View/arbre.desc.excel.asp-> pages/tree.php?dir=desc&style=excel
 *     Prog/View/bio.asp             -> pages/person.php        (person biography/detail)
 *     Prog/View/lstNomDate.asp      -> pages/list-names.php    (list by name or date)
 *     Prog/View/lstCalendrier.asp   -> pages/birthdays.php     (birthday calendar)
 *     Prog/View/lstPhotos.asp       -> pages/photos.php        (photo gallery)
 *     Prog/View/lstPhotosAll.asp    -> pages/photos.php?all=1  (all photos)
 *     Prog/View/photo.asp           -> pages/photo.php         (single photo detail)
 *     Prog/View/lstDocs.asp         -> pages/documents.php     (document list)
 *     Prog/View/message.asp         -> pages/messages.php      (messaging, Platinum)
 *
 *   Auth pages:
 *     p.login.asp                   -> pages/login.php         (login form)
 *
 *   Admin pages (Prog/Admin/):
 *     frame.asp + menu.asp          -> pages/admin.php         (admin home + nav)
 *     persIndex/List/Page/Wizard    -> pages/admin-people.php  (people CRUD)
 *     coupIndex/List/Page           -> pages/admin-couples.php (couples CRUD)
 *     commIndex/List/Page           -> pages/admin-comments.php(comments CRUD)
 *     photoIndex/List/Page/Upload   -> pages/admin-photos.php  (photos CRUD)
 *     docIndex/List/Page/Upload     -> pages/admin-documents.php(documents CRUD)
 *     infoIndex/List/Page           -> pages/admin-info.php    (info pages CRUD)
 *     messIndex/List/Page           -> pages/admin-messages.php(messages CRUD)
 *
 *   Help pages:
 *     Prog/Help/User.eng.asp        -> pages/help.php          (user help)
 *     Prog/Help/Tech.eng.asp        -> pages/help.php?type=tech(tech help)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/labels.php';

// =========================================================================
// ROUTING
// =========================================================================

// Allowed pages (whitelist to prevent path traversal)
$allowed_pages = [
    'home',                             // Prog/View/intro.asp
    'tree',                             // Prog/View/frame.asp + arbre.asp
    'person',                           // Prog/View/bio.asp
    'list-names',                       // Prog/View/lstNomDate.asp
    'birthdays',                        // Prog/View/lstCalendrier.asp
    'photos',                           // Prog/View/lstPhotos.asp
    'photo',                            // Prog/View/photo.asp
    'documents',                        // Prog/View/lstDocs.asp
    'messages',                         // Prog/View/message.asp
    'login',                            // p.login.asp
    'help',                             // Prog/Help/*.asp
    'admin',                            // Prog/Admin/frame.asp
    'admin-people',                     // Prog/Admin/pers*.asp
    'admin-couples',                    // Prog/Admin/coup*.asp
    'admin-comments',                   // Prog/Admin/comm*.asp
    'admin-photos',                     // Prog/Admin/photo*.asp
    'admin-documents',                  // Prog/Admin/doc*.asp
    'admin-info',                       // Prog/Admin/info*.asp
    'admin-messages',                   // Prog/Admin/mess*.asp
];

$page = $_GET['page'] ?? 'home';
if (!in_array($page, $allowed_pages, true)) {
    $page = 'home';
}

// =========================================================================
// FAMILY CONTEXT
// =========================================================================

// Old ASP used: p.frame.asp?DomKey=xxx to identify the family.
// We support both ?DomKey= (backwards compat) and ?h= (new short form).
$dom_key = $_GET['DomKey'] ?? $_GET['h'] ?? null;
if ($dom_key !== null) {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT id FROM families WHERE hash = ? AND is_online = 1');
    $stmt->execute([$dom_key]);
    $row = $stmt->fetch();
    if ($row) {
        $_SESSION['family_id'] = (int)$row['id'];
    }
}

// Handle language switch (old ASP: p.frame.asp?Language=FRA)
if (isset($_GET['Language'])) {
    $_SESSION['language'] = $_GET['Language'];
}

$family = current_family();
$family_title = $family ? h($family['title'] ?? $family['name']) : 'See Our Family';
$lang = $_SESSION['language'] ?? $family['language'] ?? 'ENG';
$is_logged_in = current_user_id() !== null;

// Load labels for the active language
$L = get_labels($lang);

?>
<!DOCTYPE html>
<html lang="<?= h(strtolower(substr($lang, 0, 2))) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $family_title ?></title>
    <style>
        /* ============================================================
         * Single-page layout replacing the old 3-frame frameset.
         *
         * Old p.frame.asp:
         *   rows="20,*,16"
         *     frame menu (20px) = silver horizontal menu bar
         *     frame main (*)    = white content area
         *     frame lang (16px) = language selector at bottom
         *
         * New layout: CSS Grid with top menu bar + content + footer.
         * Matches the original geometry and Verdana 8pt style.
         * ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Verdana, Arial, Helvetica, sans-serif;
            font-size: 8pt;
            color: #000;
            background: #fff;
        }

        .layout {
            display: grid;
            grid-template-areas:
                "menubar"
                "content"
                "langbar";
            grid-template-rows: 20px 1fr 16px;
            min-height: 100vh;
        }

        /* ------- TOP MENU BAR (was p.menu.asp, 20px silver bar) ------- */
        .menubar {
            grid-area: menubar;
            background: silver;
            display: flex;
            align-items: center;
            padding: 0;
            border-bottom: 1px solid #999;
            overflow: hidden;
        }

        .menubar .logo {
            width: 35px;
            height: 20px;
            flex-shrink: 0;
        }

        .menubar .logo img {
            width: 35px;
            height: 20px;
            border: 0;
        }

        .menubar .menu-links {
            flex: 1;
            padding: 0 4px;
            white-space: nowrap;
        }

        .menubar .menu-right {
            text-align: right;
            padding: 0 4px;
            white-space: nowrap;
        }

        .menubar a {
            color: #000;
            text-decoration: none;
        }

        .menubar a:hover {
            text-decoration: underline;
        }

        /* ------- MAIN CONTENT (was the "main" frame) ------- */
        .content {
            grid-area: content;
            padding: 20px;
            overflow-y: auto;
        }

        /* ------- BOTTOM LANGUAGE BAR (was p.lang.asp, 16px) ------- */
        .langbar {
            grid-area: langbar;
            background: silver;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 7pt;
            border-top: 1px solid #999;
        }

        .langbar a {
            color: #000;
            text-decoration: none;
            margin: 0 3px;
        }

        .langbar a:hover {
            text-decoration: underline;
        }

        /* ------- TYPOGRAPHY matching old style.css ------- */
        h1 { font: bold 18pt "Arial Narrow", "Helvetica Condensed", Swiss, sans-serif; }
        h2 { font: bold 16pt "Arial Narrow", "Helvetica Condensed", Swiss, sans-serif; }
        h3 { font: bold 14pt "Arial Narrow", "Helvetica Condensed", Swiss, sans-serif; }
        a { color: #000; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .box { border: 1px solid #666; font: 8pt Verdana, Arial, Helvetica, sans-serif; }

        /* ------- RESPONSIVE ------- */
        @media (max-width: 768px) {
            .layout {
                grid-template-rows: auto 1fr 20px;
            }
            .menubar {
                flex-wrap: wrap;
                height: auto;
                padding: 4px 0;
            }
            .menubar .menu-links,
            .menubar .menu-right {
                white-space: normal;
            }
        }
    </style>
</head>
<body>

<div class="layout">

    <!-- ============================================================
         TOP MENU BAR - replaces Prog/p.menu.asp (20px silver bar)

         Original: [home] > genealogy by [names][years][birthdays]
                   [pictures][documents][messages] ... [help][admin]
         ============================================================ -->
    <nav class="menubar">
        <div class="logo">
            <a href="?page=home"><img src="Image/menuTree.jpg" alt="Home"></a>
        </div>
        <div class="menu-links">
            <?php if ($is_logged_in): ?>
                [<a href="?page=home"><?= $L['menu_home'] ?></a>] &gt;
                <?= $L['menu_genealogy'] ?>
                [<a href="?page=list-names&amp;sort=name"><?= $L['menu_names'] ?></a>]
                [<a href="?page=list-names&amp;sort=year"><?= $L['menu_years'] ?></a>]
                [<a href="?page=birthdays"><?= $L['menu_calendar'] ?></a>]
                [<a href="?page=photos"><?= $L['menu_pictures'] ?></a>]
                [<a href="?page=documents"><?= $L['menu_documents'] ?></a>]
                <?php if (($family['package'] ?? '') === 'Platinum'): ?>
                    [<a href="?page=messages"><?= $L['menu_messages'] ?></a>]
                <?php endif; ?>
            <?php else: ?>
                [<a href="?page=home"><?= $L['menu_home'] ?></a>]
            <?php endif; ?>
        </div>
        <div class="menu-right">
            [<a href="?page=help"><?= $L['menu_help'] ?></a>]
            <?php if ($is_logged_in): ?>
                [<a href="?page=admin"><?= $L['menu_admin'] ?></a>]
            <?php else: ?>
                [<a href="?page=login"><?= $L['menu_login'] ?></a>]
            <?php endif; ?>
        </div>
    </nav>

    <!-- ============================================================
         MAIN CONTENT - replaces the "main" frame
         Default page was Prog/View/intro.asp (welcome/home)
         ============================================================ -->
    <main class="content">
        <?php
        $page_file = __DIR__ . '/pages/' . $page . '.php';
        if (file_exists($page_file)) {
            require $page_file;
        } else {
            echo '<h2>' . h($page) . '</h2>';
            echo '<p>This page is not yet implemented.</p>';
        }
        ?>
    </main>

    <!-- ============================================================
         BOTTOM LANGUAGE BAR - replaces Prog/p.lang.asp (16px)

         Original: English | Français | Español | Italiano |
                   Português | Deutsch | Nederlands
         ============================================================ -->
    <footer class="langbar">
        <a href="?page=<?= h($page) ?>&amp;Language=ENG">English</a> |
        <a href="?page=<?= h($page) ?>&amp;Language=FRA">Fran&ccedil;ais</a> |
        <a href="?page=<?= h($page) ?>&amp;Language=ESP">Espa&ntilde;ol</a> |
        <a href="?page=<?= h($page) ?>&amp;Language=ITA">Italiano</a> |
        <a href="?page=<?= h($page) ?>&amp;Language=POR">Portugu&ecirc;s</a> |
        <a href="?page=<?= h($page) ?>&amp;Language=DEU">Deutsch</a> |
        <a href="?page=<?= h($page) ?>&amp;Language=NLD">Nederlands</a>
    </footer>

</div>

</body>
</html>
