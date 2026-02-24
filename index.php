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
use SeeOurFamily\Media;
use SeeOurFamily\Router;

$db     = new Database();
$auth   = new Auth($db);
$media  = new Media($db);
$router = new Router();

// =========================================================================
// REQUEST HANDLING
// =========================================================================

// Exit: clear entire session
if (($_GET['action'] ?? '') === 'exit') {
    session_destroy();
    header('Location: /home');
    exit;
}

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

// Logout (before login handling so ?action=logout clears state first)
if (($_GET['action'] ?? '') === 'logout') {
    $auth->logout();
    header('Location: /login');
    exit;
}

// =========================================================================
// MEDIA SERVING (special route: /media/{uuid})
// =========================================================================

if ($router->specialRoute() === 'media') {
    $mediaUuid = $router->param('id');
    if (!$mediaUuid || !$auth->isLoggedIn()) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $familyId   = $auth->familyId();
    $familyName = $auth->family()['name'] ?? '';
    $thumbnail  = isset($_GET['tn']);
    if (!$media->serve($mediaUuid, $familyId, $familyName, $thumbnail)) {
        http_response_code(404);
        echo 'Not found';
    }
    exit;
}

// Login form submission (POST)
$loginError = '';
$loginFamilies = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $router->page() === 'login') {
    $devLogin = $_POST['dev_login'] ?? '';
    if ($devLogin !== '' && ($_ENV['APP_DEV'] ?? '') === '1') {
        // Dev mode: bypass password, set role directly
        $_SESSION['role'] = $devLogin;
        header('Location: /home');
        exit;
    }

    $loginField = trim($_POST['login'] ?? '');
    $passwordField = $_POST['password'] ?? '';

    if ($loginField !== '') {
        // User-table login (username + password)
        $families = $auth->loginUser($loginField, $passwordField);
        if ($families === null) {
            $loginError = 'invalid';
        } elseif (count($families) === 1) {
            // Single family — set it and redirect
            $auth->setFamilyById((int)$families[0]['family_id']);
            header('Location: /home');
            exit;
        } elseif (count($families) > 1) {
            // Multiple families — show chooser (handled in login template)
            $loginFamilies = $families;
        } else {
            // User exists but has no family access
            $loginError = 'no_family';
        }
    } elseif ($passwordField !== '') {
        // Legacy family-password login (password only, family must be set)
        $role = $auth->loginFamilyPassword($passwordField);
        if ($role !== null) {
            header('Location: /home');
            exit;
        }
        $loginError = 'invalid';
    }
}

// Family selection after login (user picks from their list)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($router->page() === 'login') && isset($_POST['select_family_id'])) {
    $selFamilyId = (int)$_POST['select_family_id'];
    $userId = $auth->userId();
    if ($userId !== null) {
        $auth->setFamilyById($selFamilyId);
        header('Location: /home');
        exit;
    }
}

// Forgot password (POST)
$resetMessage = '';
$resetError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $router->page() === 'forgot-password') {
    $email = trim($_POST['email'] ?? '');
    if ($email !== '') {
        $result = $auth->createPasswordReset($email);
        if ($result) {
            $baseUrl = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $resetUrl = $baseUrl . '/reset-password?token=' . $result['token'];
            // In production, email the link. For now, display it.
            $resetMessage = 'A password reset link has been generated.<br>'
                . '<b>Reset link:</b> <a href="' . h($resetUrl) . '">' . h($resetUrl) . '</a><br>'
                . '<small>This link expires in 1 hour.</small>';
        } else {
            // Don't reveal whether the email exists (security best practice)
            $resetMessage = 'If an account with that email exists, a reset link has been generated.';
        }
    }
}

// Reset password (POST)
$resetTokenUser = null;
$resetTokenError = '';
$resetFormError = '';
$resetSuccess = false;
if ($router->page() === 'reset-password') {
    $token = $_GET['token'] ?? '';
    if ($token !== '') {
        $resetTokenUser = $auth->validateResetToken($token);
        if (!$resetTokenUser) {
            $resetTokenError = 'This reset link is invalid or has expired.';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '' && $resetTokenUser) {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        if (strlen($newPass) < 6) {
            $resetFormError = 'Password must be at least 6 characters.';
        } elseif ($newPass !== $confirmPass) {
            $resetFormError = 'Passwords do not match.';
        } else {
            if ($auth->resetPassword($token, $newPass)) {
                $resetSuccess = true;
                $resetTokenUser = null; // Hide the form
            } else {
                $resetTokenError = 'This reset link is invalid or has expired.';
                $resetTokenUser = null;
            }
        }
    }
}

// Register via invitation (POST + GET)
$inviteData = null;
$inviteError = '';
$registerError = '';
$registerSuccess = false;
if ($router->page() === 'register') {
    $inviteToken = $_GET['invite'] ?? '';
    if ($inviteToken !== '') {
        $inviteData = $auth->validateInvitation($inviteToken);
        if (!$inviteData) {
            $inviteError = 'This invitation link is invalid, expired, or has already been used.';
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inviteToken !== '' && $inviteData) {
        $regLogin   = trim($_POST['login'] ?? '');
        $regName    = trim($_POST['name'] ?? '');
        $regEmail   = trim($_POST['email'] ?? '');
        $regPass    = $_POST['password'] ?? '';
        $regConfirm = $_POST['confirm_password'] ?? '';

        if (strlen($regLogin) < 4) {
            $registerError = 'Username must be at least 4 characters.';
        } elseif (strlen($regPass) < 6) {
            $registerError = 'Password must be at least 6 characters.';
        } elseif ($regPass !== $regConfirm) {
            $registerError = 'Passwords do not match.';
        } else {
            $newUserId = $auth->acceptInvitation($inviteToken, $regLogin, $regPass, $regName, $regEmail);
            if ($newUserId !== null) {
                $registerSuccess = true;
                $inviteData = null; // Hide the form
            } else {
                $registerError = 'Username is already taken. Please choose a different one.';
            }
        }
    }
}

// Route protection: redirect to login if accessing protected pages without auth
$publicPages = ['login', 'home', 'blog', 'blog-post', 'forgot-password', 'reset-password', 'register'];
$page = $router->page();
if (!$auth->isLoggedIn() && !in_array($page, $publicPages, true)) {
    header('Location: /login');
    exit;
}

// Admin route protection
if (str_starts_with($page, 'admin') && !$auth->isAdmin()) {
    header('Location: /login');
    exit;
}

// System-admin route protection (superadmin only)
if (str_starts_with($page, 'system-admin') && !$auth->isSuperAdmin()) {
    header('Location: /login');
    exit;
}

// =========================================================================
// HELPER FUNCTIONS (must be defined before template variables use them)
// =========================================================================

/** HTML-escape shorthand — available in all templates.
 *  Also repairs double/triple-encoded UTF-8 from the Access migration. */
function h(?string $s): string
{
    return htmlspecialchars(fix_utf8($s ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
// TEMPLATE VARIABLES
// =========================================================================

$family      = $auth->family();
$familyTitle = $family
    ? h($family['title'] ?? $family['name'])
    : 'See Our Family';
$lang        = $auth->language();
$labels      = new Labels($lang);
$L           = $labels->all();
$isLoggedIn    = $auth->isLoggedIn();
$isAdmin       = $auth->isAdmin();
$isSuperAdmin  = $auth->isSuperAdmin();
$userName      = $auth->userName();

// =========================================================================
// RENDER
// =========================================================================

require __DIR__ . '/templates/layout.php';
