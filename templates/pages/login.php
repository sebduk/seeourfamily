<?php

/**
 * Login page.
 *
 * Replaces Prog/Admin/login.asp (and p.login.asp reference).
 *
 * Two login modes:
 *   1. User login: username + password (resolves family via user_family_link)
 *   2. Legacy family-password login: password only (requires family already set via DomKey)
 *
 * Available from index.php:
 *   $db, $auth, $router, $family, $L, $isLoggedIn, $isAdmin
 *   $loginError      - 'invalid' | 'no_family' | ''
 *   $loginFamilies   - array of families if user has multiple, null otherwise
 */

// If visiting /login?invite=..., redirect to the registration page
$inviteParam = $_GET['invite'] ?? '';
if ($inviteParam !== '') {
    header('Location: /register?invite=' . urlencode($inviteParam));
    exit;
}

// If already logged in with a family set, redirect to home
if ($isLoggedIn && $family) {
    header('Location: /home');
    exit;
}

// If user just logged in and needs to pick a family (multi-family)
if ($loginFamilies !== null && count($loginFamilies) > 1): ?>

<div class="login-form">
    <h2>Select a family</h2>
    <p>Your account has access to multiple families. Please select one:</p>
    <?php foreach ($loginFamilies as $f): ?>
        <form action="/login" method="post" style="margin: 6px 0;">
            <input type="hidden" name="select_family_id" value="<?= (int)$f['family_id'] ?>">
            <button type="submit" class="box" style="min-width: 200px; text-align: left; padding: 6px 12px;">
                <?= h($f['title'] ?? $f['name']) ?>
                <small style="color: #666;">(<?= h($f['role']) ?>)</small>
            </button>
        </form>
    <?php endforeach; ?>
</div>

<?php return; endif; ?>

<div class="login-form">

    <?php if ($loginError === 'invalid'): ?>
        <div style="color: #c00; margin-bottom: 12px; padding: 8px; border: 1px solid #c00; background: #fff0f0;">
            Invalid username or password. Please try again.
        </div>
    <?php elseif ($loginError === 'no_family'): ?>
        <div style="color: #c00; margin-bottom: 12px; padding: 8px; border: 1px solid #c00; background: #fff0f0;">
            Your account does not have access to any family. Please contact an administrator.
        </div>
    <?php endif; ?>

    <h2><?= $L['login_message'] ?></h2>

    <form action="/login" method="post">
        <div style="margin-bottom: 8px;">
            <input type="text" name="login" value="" placeholder="Username" class="box" autocomplete="username" autofocus>
        </div>
        <div style="margin-bottom: 8px;">
            <input type="password" name="password" value="" placeholder="<?= h($L['password']) ?>" class="box" autocomplete="current-password">
        </div>
        <input type="submit" value="<?= h($L['btn_login'] ?? 'Login') ?>" class="box">
    </form>

    <p style="margin-top:8px; font-size:9pt;">
        <a href="/forgot-password">Forgot your password?</a>
    </p>

    <?php if ($family): ?>
        <br>
        <div style="border-top: 1px solid #ccc; padding-top: 12px; margin-top: 12px;">
            <small style="color: #666;">Or enter the family password to continue as a guest:</small>
            <form action="/login" method="post" style="margin-top: 6px;">
                <input type="password" name="password" value="" placeholder="<?= h($L['password']) ?>" class="box">
                <input type="submit" value="ok" class="box">
            </form>
        </div>
    <?php endif; ?>

    <?php if (($_ENV['APP_DEV'] ?? '') === '1'): ?>
        <br>
        <div style="border:1px dashed #999; padding:8px; background:#ffe; font-size:9pt;">
            <strong>Dev login</strong> (APP_DEV=1):<br>
            <form action="/login" method="post" style="display:inline">
                <input type="hidden" name="dev_login" value="Guest">
                <button type="submit" class="box">Guest</button>
            </form>
            <form action="/login" method="post" style="display:inline">
                <input type="hidden" name="dev_login" value="Admin">
                <button type="submit" class="box">Admin</button>
            </form>
        </div>
    <?php endif; ?>
</div>
