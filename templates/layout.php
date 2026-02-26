<?php
// Current request path (preserves /tree/21 etc.) for language-switch links
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Read font/size preferences from cookies (avoids flash of unstyled content)
$cookieFont = isset($_COOKIE['sof_font']) ? $_COOKIE['sof_font'] : '';
$cookieSize = isset($_COOKIE['sof_fontsize']) ? (int)$_COOKIE['sof_fontsize'] : 0;
$bodyStyle = '';
if ($cookieFont) $bodyStyle .= 'font-family:' . htmlspecialchars($cookieFont, ENT_QUOTES) . ';';
if ($cookieSize >= 8 && $cookieSize <= 16) $bodyStyle .= 'font-size:' . $cookieSize . 'pt;';
?>
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
            grid-template-areas: "menubar" "content";
            grid-template-rows: auto 1fr;
            height: 100vh;
        }
        .menubar {
            grid-area: menubar; background: silver;
            display: flex; align-items: center;
            border-bottom: 1px solid #999;
            position: sticky; top: 0; z-index: 100;
            padding: 2px 0;
        }
        .menubar .menu-links { flex: 1; padding: 0 4px; white-space: nowrap; }
        .menubar .menu-right { text-align: right; padding: 0 4px; white-space: nowrap; }
        .menubar a { color: #000; text-decoration: none; }
        .menubar a:hover { text-decoration: underline; }
        .content { grid-area: content; padding: 20px; overflow-y: auto; }

        /* Settings dropdown */
        .settings-wrap { position: relative; display: inline-block; }
        .settings-toggle { cursor: pointer; font-size: 14pt; }
        .help-icon { font-size: 13pt; }
        .settings-panel {
            display: none; position: absolute; right: 0; top: 100%;
            background: #fff; border: 1px solid #999; padding: 8px;
            min-width: 220px; z-index: 200;
            box-shadow: 2px 2px 6px rgba(0,0,0,0.2);
        }
        .settings-panel.open { display: block; }
        .settings-section { padding: 6px 0; border-bottom: 1px solid #ddd; }
        .settings-section:last-child { border-bottom: none; }
        .settings-section-title { font-weight: bold; margin-bottom: 4px; }
        .font-btns button {
            cursor: pointer; border: 1px solid #999; background: #f5f5f5;
            padding: 2px 6px; margin: 1px;
        }
        .font-btns button:hover { background: #ddd; }
        .font-btns button.active { background: #ccc; font-weight: bold; }
        .size-btns { display: inline; margin-left: 8px; }
        .size-btns button {
            cursor: pointer; border: 1px solid #999; background: #f5f5f5;
            padding: 2px 8px; margin: 1px; font-weight: bold;
        }
        .size-btns button:hover { background: #ddd; }
        .settings-panel a { color: #000; }
        .settings-panel a:hover { text-decoration: underline; }

        @media (max-width: 768px) {
            .layout { grid-template-rows: auto 1fr; }
            .menubar { flex-wrap: wrap; padding: 4px 0; }
            .menubar .menu-links, .menubar .menu-right { white-space: normal; }
        }
    </style>
</head>
<body<?= $bodyStyle ? ' style="' . $bodyStyle . '"' : '' ?>>

<div class="layout">

    <!-- ============================================================
         TOP MENU BAR - replaces Prog/p.menu.asp
         Sticky: stays visible when content scrolls (position: sticky)
         ============================================================ -->
    <nav class="menubar">
        <div class="menu-links">
            [<a href="/home"><?= $L['menu_home'] ?></a>]
            [<a href="/blog">Blog</a>]
            <?php if ($family): ?>
                &gt; <?= $L['menu_genealogy'] ?>
                [<a href="/list-names?sort=name"><?= $L['menu_names'] ?></a>]
                [<a href="/list-names?sort=year"><?= $L['menu_years'] ?></a>]
                [<a href="/birthdays"><?= $L['menu_calendar'] ?></a>]
                [<a href="/photos"><?= $L['menu_pictures'] ?></a>]
                [<a href="/documents"><?= $L['menu_documents'] ?></a>]
                [<a href="/messages"><?= $L['menu_messages'] ?></a>]
            <?php endif; ?>
        </div>
        <div class="menu-right">
            <a href="/help" class="help-icon" title="Help">&#x2753;</a>
            <div class="settings-wrap">
                <a href="#" class="settings-toggle" onclick="toggleSettings(event)" title="Settings">&#9881;</a>
                <div class="settings-panel" id="settingsPanel">
                    <div class="settings-section">
                        <div class="settings-section-title">Font</div>
                        <div class="font-btns">
                            <button onclick="setFont('Verdana,sans-serif')" style="font-family:Verdana">Verdana</button>
                            <button onclick="setFont('Arial,sans-serif')" style="font-family:Arial">Arial</button>
                            <button onclick="setFont('Georgia,serif')" style="font-family:Georgia">Georgia</button>
                            <button onclick="setFont('&quot;Times New Roman&quot;,serif')" style="font-family:'Times New Roman'">Times</button>
                            <button onclick="setFont('&quot;Courier New&quot;,monospace')" style="font-family:'Courier New'">Mono</button>
                        </div>
                        <div class="settings-section-title" style="margin-top:4px">Size</div>
                        <div class="size-btns">
                            <button onclick="changeSize(-1)">A&minus;</button>
                            <button onclick="resetSize()">A</button>
                            <button onclick="changeSize(1)">A+</button>
                        </div>
                    </div>
                    <div class="settings-section">
                        <div class="settings-section-title">Language</div>
                        <a href="<?= h($currentPath) ?>?lang=ENG">English</a> |
                        <a href="<?= h($currentPath) ?>?lang=FRA">Fran&ccedil;ais</a> |
                        <a href="<?= h($currentPath) ?>?lang=ESP">Espa&ntilde;ol</a><br>
                        <a href="<?= h($currentPath) ?>?lang=ITA">Italiano</a> |
                        <a href="<?= h($currentPath) ?>?lang=POR">Portugu&ecirc;s</a> |
                        <a href="<?= h($currentPath) ?>?lang=DEU">Deutsch</a> |
                        <a href="<?= h($currentPath) ?>?lang=NLD">Nederlands</a>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="settings-section">
                        <a href="/admin">&#x2699; <?= $L['menu_admin'] ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($isSuperAdmin): ?>
                    <div class="settings-section">
                        <a href="/system-admin">&#x1F6E1; System Admin</a>
                    </div>
                    <?php endif; ?>
                    <?php if ($isLoggedIn): ?>
                    <div class="settings-section">
                        <?php if ($userName): ?>
                            <small style="color:#666;"><?= h($userName) ?></small><br>
                        <?php endif; ?>
                        <a href="/?action=logout">&#x2716; Logout</a>
                    </div>
                    <?php else: ?>
                    <div class="settings-section">
                        <a href="/login">&#x1F511; <?= $L['menu_login'] ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- ============================================================
         MAIN CONTENT - replaces the "main" frame
         ============================================================ -->
    <main class="content">
        <?php
        $pageFile = __DIR__ . '/pages/' . $page . '.php';
        if (file_exists($pageFile)) {
            require $pageFile;
        } else {
            http_response_code(404);
            echo '<h2>Page Not Found</h2>';
            echo '<p>The requested page could not be found.</p>';
            echo '<p><a href="/home">Return to home page</a></p>';
        }
        ?>
    </main>

</div>

<script>
function setCookie(n, v) {
    document.cookie = n + '=' + encodeURIComponent(v) + ';path=/;max-age=31536000';
}
function getCookie(n) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
}

/* --- Settings panel toggle --- */
function toggleSettings(e) {
    e.preventDefault();
    document.getElementById('settingsPanel').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    var p = document.getElementById('settingsPanel');
    if (p && p.classList.contains('open') && !e.target.closest('.settings-wrap')) {
        p.classList.remove('open');
    }
});

/* --- Font picker --- */
function setFont(f) {
    document.body.style.fontFamily = f;
    setCookie('sof_font', f);
    highlightActiveFont();
}
function highlightActiveFont() {
    var current = getComputedStyle(document.body).fontFamily.split(',')[0].replace(/['"]/g, '').trim().toLowerCase();
    document.querySelectorAll('.font-btns button').forEach(function(btn) {
        var btnFont = btn.style.fontFamily.split(',')[0].replace(/['"]/g, '').trim().toLowerCase();
        btn.classList.toggle('active', btnFont === current);
    });
}

/* --- Font size --- */
var DEFAULT_SIZE = 10;
function changeSize(delta) {
    var size = parseInt(getCookie('sof_fontsize')) || DEFAULT_SIZE;
    size = Math.max(8, Math.min(16, size + delta));
    document.body.style.fontSize = size + 'pt';
    setCookie('sof_fontsize', size);
}
function resetSize() {
    document.body.style.fontSize = DEFAULT_SIZE + 'pt';
    setCookie('sof_fontsize', DEFAULT_SIZE);
}

/* Highlight active font on load */
highlightActiveFont();
</script>

<script src="/js/richtext.js"></script>

</body>
</html>
