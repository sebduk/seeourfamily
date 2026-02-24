<?php

/**
 * Single blog post view.
 *
 * Public page — shows a single published blog post by UUID.
 * URL: /blog/{uuid}
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

$uuid = $router->param('id');

if (!$uuid) {
    header('Location: /blog');
    exit;
}

$stmt = $db->pdo()->prepare(
    "SELECT id, uuid, title, body, published_at, created_at, updated_at
     FROM blog_posts
     WHERE uuid = ? AND is_published = 1 AND is_hidden = 0 AND is_online = 1
     LIMIT 1"
);
$stmt->execute([$uuid]);
$post = $stmt->fetch();

if (!$post) {
    echo '<div class="page-wrap"><h2>Not Found</h2><p>This post does not exist or has been removed.</p><p><a href="/blog">&larr; Back to blog</a></p></div>';
    return;
}

// Fetch adjacent posts for navigation
$prevPost = $db->pdo()->prepare(
    "SELECT uuid, title FROM blog_posts
     WHERE is_published = 1 AND is_hidden = 0 AND is_online = 1
       AND published_at > ?
     ORDER BY published_at ASC LIMIT 1"
);
$prevPost->execute([$post['published_at']]);
$prevPost = $prevPost->fetch();

$nextPost = $db->pdo()->prepare(
    "SELECT uuid, title FROM blog_posts
     WHERE is_published = 1 AND is_hidden = 0 AND is_online = 1
       AND published_at < ?
     ORDER BY published_at DESC LIMIT 1"
);
$nextPost->execute([$post['published_at']]);
$nextPost = $nextPost->fetch();
?>

<div class="page-wrap blog-post-view">
    <p class="blog-back"><a href="/blog">&larr; All posts</a></p>

    <article>
        <h1><?= h($post['title']) ?></h1>
        <?php if ($post['published_at']): ?>
            <time class="blog-date"><?= date('F j, Y', strtotime($post['published_at'])) ?></time>
        <?php endif; ?>
        <div class="blog-body">
            <?= $post['body'] ?>
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
