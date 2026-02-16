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
</div>
