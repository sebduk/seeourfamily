<?php

/**
 * Help / user guide page.
 *
 * Replaces Prog/Help/User.eng.asp, User.fra.asp, etc.
 * Includes a per-language HTML file from templates/help/ for full layout flexibility.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn, $lang
 */

// Map language codes to help file names (same as Labels $langMap)
$helpLangMap = [
    'ENG' => 'en', 'FRA' => 'fr', 'ESP' => 'es', 'ITA' => 'it',
    'POR' => 'pt', 'DEU' => 'de', 'NLD' => 'nl',
];

$helpFile = $helpLangMap[$lang] ?? 'en';
$helpPath = __DIR__ . '/../help/' . $helpFile . '.php';

// Fall back to English if the language-specific file doesn't exist
if (!file_exists($helpPath)) {
    $helpPath = __DIR__ . '/../help/en.php';
}
?>
<div class="page-wrap">

<h1><?= $L['help_user_guide'] ?></h1>

<?php require $helpPath; ?>

</div>
