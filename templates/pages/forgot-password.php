<?php

/**
 * Forgot password page.
 *
 * User enters their email address. If an account exists with that email,
 * a password reset token is generated and displayed (in production, this
 * would be emailed). The token is valid for 1 hour.
 *
 * Available from index.php: $db, $auth, $router, $L, $isLoggedIn
 * Also: $resetMessage (set by index.php POST handler)
 */

if ($isLoggedIn) {
    header('Location: /home');
    exit;
}
?>

<div class="login-form">
    <h2>Forgot Password</h2>

    <?php if (!empty($resetMessage)): ?>
        <div style="margin-bottom:12px; padding:10px; border:1px solid #090; background:#f0fff0;">
            <?= $resetMessage ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($resetError)): ?>
        <div style="margin-bottom:12px; padding:10px; border:1px solid #c00; background:#fff0f0; color:#c00;">
            <?= h($resetError) ?>
        </div>
    <?php endif; ?>

    <p>Enter your email address and we'll send you a link to reset your password.</p>

    <form action="/forgot-password" method="post">
        <div style="margin-bottom:8px;">
            <input type="email" name="email" value="" placeholder="Email address" class="box" required autofocus>
        </div>
        <input type="submit" value="Send Reset Link" class="box">
    </form>

    <p style="margin-top:16px; font-size:9pt;">
        <a href="/login">&larr; Back to login</a>
    </p>
</div>
