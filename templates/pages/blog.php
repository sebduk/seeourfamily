<?php

/**
 * Blog listing page.
 *
 * Public page — shows all published blog posts, newest first.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset  = ($pageNum - 1) * $perPage;

// Count total published posts
$totalPosts = (int)$db->pdo()->query(
    "SELECT COUNT(*) FROM blog_posts
     WHERE is_published = 1 AND is_hidden = 0 AND is_online = 1"
)->fetchColumn();

$totalPages = max(1, (int)ceil($totalPosts / $perPage));

// Fetch current page of posts
$stmt = $db->pdo()->prepare(
    "SELECT uuid, title, body, published_at
     FROM blog_posts
     WHERE is_published = 1 AND is_hidden = 0 AND is_online = 1
     ORDER BY published_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([$perPage, $offset]);
$posts = $stmt->fetchAll();
?>

<div class="page-wrap blog-listing">
    <h1><?= $L['news'] ?></h1>

    <?php if (empty($posts)): ?>
        <p>No posts yet.</p>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
        <article class="blog-card">
            <h2><a href="/blog/<?= h($post['uuid']) ?>"><?= h($post['title']) ?></a></h2>
            <?php if ($post['published_at']): ?>
                <time class="blog-date"><?= date('F j, Y', strtotime($post['published_at'])) ?></time>
            <?php endif; ?>
            <p class="blog-excerpt"><?= h(mb_strimwidth(html_entity_decode(strip_tags($post['body']), ENT_QUOTES, 'UTF-8'), 0, 300, '...')) ?></p>
            <p><a href="/blog/<?= h($post['uuid']) ?>">Read more &rarr;</a></p>
        </article>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
        <nav class="blog-pagination">
            <?php if ($pageNum > 1): ?>
                <a href="/blog?p=<?= $pageNum - 1 ?>">&larr; Newer</a>
            <?php endif; ?>
            <span>Page <?= $pageNum ?> of <?= $totalPages ?></span>
            <?php if ($pageNum < $totalPages): ?>
                <a href="/blog?p=<?= $pageNum + 1 ?>">Older &rarr;</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
