<?php

/**
 * index.php - Front controller for See Our Family.
 *
 * Replaces p.frame.asp (the frameset entry point).
 *
 * All requests are routed through this file via .htaccess.
 * It bootstraps the application, resolves the route, and renders
 * the layout with the appropriate page template.
 *
 * Old ASP flow:
 *   Browser -> p.frame.asp?DomKey=abc123
 *     -> reads Domain from user.mdb, sets Session vars
 *     -> renders <frameset rows="20,*,16">
 *          frame menu -> Prog/p.menu.asp
 *          frame main -> Prog/View/intro.asp
 *          frame lang -> Prog/p.lang.asp
 *
 * New PHP flow:
 *   Browser -> /home  (or /?DomKey=abc123 for backwards compat)
 *     -> Composer autoload + .env config
 *     -> Router resolves URL to page name
 *     -> Auth resolves family context + login state
 *     -> Labels loads language strings
 *     -> templates/layout.php renders the single-page layout
 *       -> templates/pages/{page}.php renders the content
 *
 * Old ASP pages -> new PHP pages:
 *   Prog/View/intro.asp            -> /home              (templates/pages/home.php)
 *   Prog/View/frame.asp+arbre.asp  -> /tree, /tree/123   (templates/pages/tree.php)
 *   Prog/View/bio.asp              -> /person/456         (templates/pages/person.php)
 *   Prog/View/lstNomDate.asp       -> /list-names         (templates/pages/list-names.php)
 *   Prog/View/lstCalendrier.asp    -> /birthdays          (templates/pages/birthdays.php)
 *   Prog/View/lstPhotos.asp        -> /photos             (templates/pages/photos.php)
 *   Prog/View/photo.asp            -> /photo/789          (templates/pages/photo.php)
 *   Prog/View/lstDocs.asp          -> /documents          (templates/pages/documents.php)
 *   Prog/View/message.asp          -> /messages           (templates/pages/messages.php)
 *   p.login.asp                    -> /login              (templates/pages/login.php)
 *   Prog/Help/*.asp                -> /help               (templates/pages/help.php)
 *   Prog/Admin/frame.asp           -> /admin              (templates/pages/admin.php)
 *   Prog/Admin/pers*.asp           -> /admin/people       (templates/pages/admin-people.php)
 *   Prog/Admin/coup*.asp           -> /admin/couples      (templates/pages/admin-couples.php)
 *   Prog/Admin/comm*.asp           -> /admin/comments     (templates/pages/admin-comments.php)
 *   Prog/Admin/photo*.asp          -> /admin/photos       (templates/pages/admin-photos.php)
 *   Prog/Admin/doc*.asp            -> /admin/documents    (templates/pages/admin-documents.php)
 *   Prog/Admin/info*.asp           -> /admin/info         (templates/pages/admin-info.php)
 *   Prog/Admin/mess*.asp           -> /admin/messages     (templates/pages/admin-messages.php)
 */

declare(strict_types=1);

// =========================================================================
// BOOTSTRAP
// =========================================================================

// Composer autoload (PSR-4: SeeOurFamily\ -> src/)
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
} else {
    // Fallback: manual autoload if composer install hasn't been run yet
    spl_autoload_register(function (string $class): void {
        $prefix = 'SeeOurFamily\\';
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        }
    });
}

// Load .env configuration
$envFile = __DIR__ . '/.env';
if (class_exists('Dotenv\\Dotenv') && file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} elseif (file_exists($envFile)) {
    // Simple fallback .env parser (works without composer)
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// =========================================================================
// APPLICATION OBJECTS
// =========================================================================

use SeeOurFamily\Database;
use SeeOurFamily\Auth;
use SeeOurFamily\Labels;
use SeeOurFamily\Router;

$db     = new Database();
$auth   = new Auth($db);
$router = new Router();

// =========================================================================
// REQUEST HANDLING
// =========================================================================

// Family context: ?DomKey=abc123 or ?h=abc123
$domKey = $_GET['DomKey'] ?? $_GET['h'] ?? null;
if ($domKey !== null) {
    if ($domKey === '') {
        // Empty DomKey clears family selection (back to family chooser)
        unset($_SESSION['family_id'], $_SESSION['role'], $_SESSION['user_id']);
    } else {
        $auth->setFamilyByHash($domKey);
    }
}

// Language switch: ?Language=FRA
if (isset($_GET['Language'])) {
    $auth->setLanguage($_GET['Language']);
}

// Login form submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $router->page() === 'login') {
    $devLogin = $_POST['dev_login'] ?? '';
    if ($devLogin !== '' && ($_ENV['APP_DEV'] ?? '') === '1') {
        // Dev mode: bypass password, set role directly
        $_SESSION['role'] = $devLogin;
    } else {
        $password = $_POST['password'] ?? '';
        $auth->login($password);
    }
}

// Logout
if ($router->page() === 'login' && ($_GET['action'] ?? '') === 'logout') {
    $auth->logout();
}

// =========================================================================
// TEMPLATE VARIABLES
// =========================================================================

$family      = $auth->family();
$familyTitle = $family
    ? h($family['title'] ?? $family['name'])
    : 'See Our Family';
$lang        = $auth->language();
$labels      = new Labels($lang);
$L           = $labels->all();
$page        = $router->page();
$isLoggedIn  = $auth->isLoggedIn();
$isAdmin     = $auth->isAdmin();

/** HTML-escape shorthand — available in all templates.
 *  Also repairs double/triple-encoded UTF-8 from the Access migration. */
function h(string $s): string
{
    return htmlspecialchars(fix_utf8($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Repair double/triple-encoded UTF-8 (mojibake).
 *
 * Data migrated from Access .mdb files sometimes ends up double-encoded:
 * "Généalogie" stored as the bytes for "GÃ©nÃ©alogie".
 * This recursively reduces encoding levels until the string is clean.
 */
function fix_utf8(string $s): string
{
    // Nothing to fix if the string is pure ASCII
    if (!preg_match('/[\x80-\xFF]/', $s)) {
        return $s;
    }
    // Try converting from UTF-8 to latin1 (ISO-8859-1).
    // If the original was double-encoded, this "unwraps" one layer,
    // yielding raw bytes that form valid UTF-8.
    $decoded = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    if ($decoded !== false && $decoded !== $s && mb_check_encoding($decoded, 'UTF-8')) {
        return fix_utf8($decoded);
    }
    return $s;
}

// =========================================================================
// RENDER
// =========================================================================

require __DIR__ . '/templates/layout.php';
