<?php
/**
 * Shared admin navigation bar.
 * Included by all admin-*.php pages via require __DIR__ . '/../_admin-nav.php'.
 */
?>
<div class="admin-nav">
    <a href="/admin">Admin</a> |
    <a href="/admin/people"><?= $L['menu_people'] ?></a> |
    <a href="/admin/couples"><?= $L['menu_couples'] ?></a> |
    <a href="/admin/comments"><?= $L['menu_comments'] ?></a> |
    <a href="/admin/documents"><?= $L['menu_documents'] ?></a> |
    <a href="/admin/folders"><?= $L['menu_folders'] ?? 'Folders' ?></a> |
    <a href="/admin/info"><?= $L['news'] ?></a>
    <?php if (($family['package'] ?? '') === 'Platinum'): ?>
    | <a href="/admin/messages"><?= $L['menu_messages'] ?></a>
    <?php endif; ?>
</div>
