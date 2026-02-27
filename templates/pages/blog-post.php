<?php

/**
 * Single blog post view.
 *
 * Public page — shows a single published blog post by UUID.
 * Works for both global posts (family_id IS NULL) and family posts.
 * URL: /blog/{uuid}
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

$uuid = $router->param('id');

if (!$uuid) {
    header('Location: /home');
    exit;
}

$stmt = $db->pdo()->prepare(
    "SELECT id, uuid, family_id, title, body, published_at, created_at, updated_at
     FROM blog_posts
     WHERE uuid = ? AND is_published = 1 AND is_hidden = 0 AND is_online = 1
     LIMIT 1"
);
$stmt->execute([$uuid]);
$post = $stmt->fetch();

if (!$post) {
    echo '<div class="page-wrap"><h2>Not Found</h2><p>This post does not exist or has been removed.</p><p><a href="/home">&larr; ' . $L['menu_home'] . '</a></p></div>';
    return;
}

// Fetch adjacent posts for navigation (scoped to same context: global or same family)
if ($post['family_id']) {
    $scopeWhere = 'AND family_id = ?';
    $scopeParam = [(int)$post['family_id']];
} else {
    $scopeWhere = 'AND family_id IS NULL';
    $scopeParam = [];
}

$prevPost = $db->pdo()->prepare(
    "SELECT uuid, title FROM blog_posts
     WHERE is_published = 1 AND is_hidden = 0 AND is_online = 1
       AND published_at > ?
       $scopeWhere
     ORDER BY published_at ASC LIMIT 1"
);
$prevPost->execute(array_merge([$post['published_at']], $scopeParam));
$prevPost = $prevPost->fetch();

$nextPost = $db->pdo()->prepare(
    "SELECT uuid, title FROM blog_posts
     WHERE is_published = 1 AND is_hidden = 0 AND is_online = 1
       AND published_at < ?
       $scopeWhere
     ORDER BY published_at DESC LIMIT 1"
);
$nextPost->execute(array_merge([$post['published_at']], $scopeParam));
$nextPost = $nextPost->fetch();
?>

<div class="page-wrap blog-post-view">
    <p class="blog-back"><a href="/home">&larr; <?= $L['menu_home'] ?></a></p>

    <article>
        <h1><?= h($post['title']) ?></h1>
        <?php if ($post['published_at']): ?>
            <time class="blog-date"><?= date('F j, Y', strtotime($post['published_at'])) ?></time>
        <?php endif; ?>
        <div class="blog-body">
            <?= fix_utf8(\SeeOurFamily\Html::sanitize($post['body'])) ?>
        </div>
    </article>

    <nav class="blog-post-nav">
        <?php if ($prevPost): ?>
            <a href="/blog/<?= h($prevPost['uuid']) ?>" class="blog-nav-prev">&larr; <?= h($prevPost['title']) ?></a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <?php if ($nextPost): ?>
            <a href="/blog/<?= h($nextPost['uuid']) ?>" class="blog-nav-next"><?= h($nextPost['title']) ?> &rarr;</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </nav>
</div>
