<!DOCTYPE html>
<html lang="<?= h(strtolower(substr($lang, 0, 2))) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $familyTitle ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        /* Layout: 3-row grid replacing the old frameset (menu / content / langbar) */
        .layout {
            display: grid;
            grid-template-areas: "menubar" "content" "langbar";
            grid-template-rows: 20px 1fr 16px;
            min-height: 100vh;
        }
        .menubar {
            grid-area: menubar; background: silver;
            display: flex; align-items: center;
            border-bottom: 1px solid #999; overflow: hidden;
        }
        .menubar .logo { width: 35px; height: 20px; flex-shrink: 0; }
        .menubar .logo img { width: 35px; height: 20px; border: 0; }
        .menubar .menu-links { flex: 1; padding: 0 4px; white-space: nowrap; }
        .menubar .menu-right { text-align: right; padding: 0 4px; white-space: nowrap; }
        .menubar a { color: #000; text-decoration: none; }
        .menubar a:hover { text-decoration: underline; }
        .content { grid-area: content; padding: 20px; overflow-y: auto; }
        .langbar {
            grid-area: langbar; background: silver;
            display: flex; align-items: center; justify-content: center;
            font-size: 7pt; border-top: 1px solid #999;
        }
        .langbar a { color: #000; text-decoration: none; margin: 0 3px; }
        .langbar a:hover { text-decoration: underline; }
        @media (max-width: 768px) {
            .layout { grid-template-rows: auto 1fr 20px; }
            .menubar { flex-wrap: wrap; height: auto; padding: 4px 0; }
            .menubar .menu-links, .menubar .menu-right { white-space: normal; }
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
            <a href="/home"><img src="/Image/menuTree.jpg" alt="Home"></a>
        </div>
        <div class="menu-links">
            <?php if ($isLoggedIn): ?>
                [<a href="/home"><?= $L['menu_home'] ?></a>] &gt;
                <?= $L['menu_genealogy'] ?>
                [<a href="/list-names?sort=name"><?= $L['menu_names'] ?></a>]
                [<a href="/list-names?sort=year"><?= $L['menu_years'] ?></a>]
                [<a href="/birthdays"><?= $L['menu_calendar'] ?></a>]
                [<a href="/photos"><?= $L['menu_pictures'] ?></a>]
                [<a href="/documents"><?= $L['menu_documents'] ?></a>]
                <?php if (($family['package'] ?? '') === 'Platinum'): ?>
                    [<a href="/messages"><?= $L['menu_messages'] ?></a>]
                <?php endif; ?>
            <?php else: ?>
                [<a href="/home"><?= $L['menu_home'] ?></a>]
            <?php endif; ?>
        </div>
        <div class="menu-right">
            [<a href="/help"><?= $L['menu_help'] ?></a>]
            <?php if ($isLoggedIn): ?>
                [<a href="/admin"><?= $L['menu_admin'] ?></a>]
            <?php else: ?>
                [<a href="/login"><?= $L['menu_login'] ?></a>]
            <?php endif; ?>
        </div>
    </nav>

    <!-- ============================================================
         MAIN CONTENT - replaces the "main" frame
         Default: Prog/View/intro.asp -> /home
         ============================================================ -->
    <main class="content">
        <?php
        $pageFile = __DIR__ . '/pages/' . $page . '.php';
        if (file_exists($pageFile)) {
            require $pageFile;
        } else {
            echo '<h2>' . h($page) . '</h2>';
            echo '<p>This page is not yet implemented.</p>';
        }
        ?>
    </main>

    <!-- ============================================================
         BOTTOM LANGUAGE BAR - replaces Prog/p.lang.asp (16px)
         ============================================================ -->
    <footer class="langbar">
        <a href="/<?= h($page) ?>?Language=ENG">English</a> |
        <a href="/<?= h($page) ?>?Language=FRA">Fran&ccedil;ais</a> |
        <a href="/<?= h($page) ?>?Language=ESP">Espa&ntilde;ol</a> |
        <a href="/<?= h($page) ?>?Language=ITA">Italiano</a> |
        <a href="/<?= h($page) ?>?Language=POR">Portugu&ecirc;s</a> |
        <a href="/<?= h($page) ?>?Language=DEU">Deutsch</a> |
        <a href="/<?= h($page) ?>?Language=NLD">Nederlands</a>
    </footer>

</div>

</body>
</html>
