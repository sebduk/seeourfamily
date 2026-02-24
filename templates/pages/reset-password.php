<?php

/**
 * Password reset page.
 *
 * Accessed via /reset-password?token=abc123. Validates the token and
 * allows the user to set a new password.
 *
 * Available from index.php: $db, $auth, $router, $L, $isLoggedIn
 * Also: $resetTokenUser, $resetTokenError, $resetSuccess (set by index.php)
 */

if ($isLoggedIn) {
    header('Location: /home');
    exit;
}

$token = $_GET['token'] ?? '';
?>

<div class="login-form">
    <h2>Reset Password</h2>

    <?php if (!empty($resetSuccess)): ?>
        <div style="margin-bottom:12px; padding:10px; border:1px solid #090; background:#f0fff0;">
            Your password has been reset. You can now <a href="/login">log in</a> with your new password.
        </div>
    <?php elseif (!empty($resetTokenError)): ?>
        <div style="margin-bottom:12px; padding:10px; border:1px solid #c00; background:#fff0f0; color:#c00;">
            <?= h($resetTokenError) ?>
        </div>
        <p><a href="/forgot-password">Request a new reset link &rarr;</a></p>
    <?php elseif ($resetTokenUser): ?>
        <p>Setting new password for <b><?= h($resetTokenUser['login']) ?></b></p>

        <?php if (!empty($resetFormError)): ?>
            <div style="margin-bottom:12px; padding:10px; border:1px solid #c00; background:#fff0f0; color:#c00;">
                <?= h($resetFormError) ?>
            </div>
        <?php endif; ?>

        <form action="/reset-password?token=<?= h($token) ?>" method="post">
            <div style="margin-bottom:8px;">
                <input type="password" name="new_password" placeholder="New password (min 6 characters)" class="box" required minlength="6" autofocus>
            </div>
            <div style="margin-bottom:8px;">
                <input type="password" name="confirm_password" placeholder="Confirm new password" class="box" required minlength="6">
            </div>
            <input type="submit" value="Reset Password" class="box">
        </form>
    <?php else: ?>
        <p style="color:#c00;">Invalid or missing reset token.</p>
        <p><a href="/forgot-password">Request a new reset link &rarr;</a></p>
    <?php endif; ?>

    <p style="margin-top:16px; font-size:9pt;">
        <a href="/login">&larr; Back to login</a>
    </p>
</div>
