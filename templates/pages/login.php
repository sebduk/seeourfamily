<?php

/**
 * Login page.
 *
 * Replaces Prog/Admin/login.asp (and p.login.asp reference).
 * Simple centered form.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

// If already logged in as admin, redirect to admin
if ($isAdmin) {
    header('Location: /admin');
    exit;
}

// If a login was just attempted (POST handled in index.php), and we're now logged in
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: /home');
    exit;
}
?>

<div class="login-form">
    <?= $L['login_message'] ?>
    <br><br>

    <form action="/login" method="post">
        <input type="password" name="password" value="" placeholder="<?= h($L['password']) ?>" class="box" autofocus>
        <input type="submit" value="ok" class="box">
    </form>

    <?php if (($_ENV['APP_DEV'] ?? '') === '1' && $family): ?>
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
