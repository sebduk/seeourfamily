<?php

/**
 * Registration page (invitation-based).
 *
 * Accessed via /register?invite=abc123. Validates the invitation token
 * and allows the user to create an account.
 *
 * Available from index.php: $db, $auth, $router, $L, $isLoggedIn
 * Also: $inviteData, $inviteError, $registerError, $registerSuccess (set by index.php)
 */

if ($isLoggedIn) {
    header('Location: /home');
    exit;
}

$inviteToken = $_GET['invite'] ?? '';
?>

<div class="login-form">
    <h2>Create Your Account</h2>

    <?php if (!empty($registerSuccess)): ?>
        <div style="margin-bottom:12px; padding:10px; border:1px solid #090; background:#f0fff0;">
            Your account has been created! You can now <a href="/login">log in</a>.
        </div>
    <?php elseif (!empty($inviteError)): ?>
        <div style="margin-bottom:12px; padding:10px; border:1px solid #c00; background:#fff0f0; color:#c00;">
            <?= h($inviteError) ?>
        </div>
    <?php elseif ($inviteData): ?>
        <p>
            You've been invited to join
            <b><?= h($inviteData['family_title'] ?? $inviteData['family_name']) ?></b>
            as <b><?= h($inviteData['role']) ?></b>.
        </p>

        <?php if (!empty($registerError)): ?>
            <div style="margin-bottom:12px; padding:10px; border:1px solid #c00; background:#fff0f0; color:#c00;">
                <?= h($registerError) ?>
            </div>
        <?php endif; ?>

        <form action="/register?invite=<?= h($inviteToken) ?>" method="post">
            <div style="margin-bottom:8px;">
                <input type="text" name="login" value="<?= h($_POST['login'] ?? '') ?>" placeholder="Username (min 4 characters)" class="box" required minlength="4" autofocus autocomplete="username">
            </div>
            <div style="margin-bottom:8px;">
                <input type="text" name="name" value="<?= h($_POST['name'] ?? '') ?>" placeholder="Display name" class="box" autocomplete="name">
            </div>
            <div style="margin-bottom:8px;">
                <input type="email" name="email" value="<?= h($_POST['email'] ?? $inviteData['email'] ?? '') ?>" placeholder="Email address" class="box" autocomplete="email">
            </div>
            <div style="margin-bottom:8px;">
                <input type="password" name="password" placeholder="Password (min 6 characters)" class="box" required minlength="6" autocomplete="new-password">
            </div>
            <div style="margin-bottom:8px;">
                <input type="password" name="confirm_password" placeholder="Confirm password" class="box" required minlength="6" autocomplete="new-password">
            </div>
            <input type="submit" value="Create Account" class="box">
        </form>
    <?php else: ?>
        <p style="color:#c00;">Invalid or missing invitation.</p>
        <p>You need a valid invitation link to create an account. Contact the family administrator.</p>
    <?php endif; ?>

    <p style="margin-top:16px; font-size:9pt;">
        Already have an account? <a href="/login">Log in</a>
    </p>
</div>
