<?php

/**
 * Home page template.
 *
 * Two modes:
 *   1. Public landing (no family selected) — shows welcome + global blog posts
 *   2. Family home (family selected) — shows family-specific blog posts
 *
 * Blog posts are filtered by the current interface language:
 *   - Posts whose language matches the current language, OR
 *   - Posts with no language set (shown to everyone)
 *
 * Replaces Prog/View/intro.asp (the default page loaded in the main frame).
 *
 * Available variables from index.php:
 *   $db, $auth, $router, $family, $familyTitle, $L, $isLoggedIn, $lang
 */

// ---- Mode 1: No family selected ----
if (!$family) {
    // Logged-in user with no family → show family chooser
    if ($isLoggedIn) {
        $uid = $auth->userId();
        if ($uid !== null) {
            $userFamilies = $isSuperAdmin ? $auth->allFamilies() : $auth->userFamilies($uid);
            if (count($userFamilies) === 1) {
                $auth->setFamilyById((int)$userFamilies[0]['family_id']);
                header('Location: /home');
                exit;
            }
            ?>
            <div class="page-wrap">
                <h2>Welcome, <?= h($userName ?? 'user') ?></h2>
                <p>Select a family to view:</p>
                <ul>
                    <?php foreach ($userFamilies as $f): ?>
                        <li>
                            <form action="/login" method="post" style="display:inline;">
                                <input type="hidden" name="select_family_id" value="<?= (int)$f['family_id'] ?>">
                                <button type="submit" style="background:none; border:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                                    <?= h($f['title'] ?? $f['name']) ?>
                                </button>
                                <small style="color:#666;">(<?= h($f['role']) ?>)</small>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
            return;
        }
    }

    // Public landing page — welcome + global blog posts filtered by language
    $stmt = $db->pdo()->prepare(
        "SELECT uuid, title, body, published_at
         FROM blog_posts
         WHERE family_id IS NULL
           AND is_published = 1 AND is_hidden = 0 AND is_online = 1
           AND (language = ? OR language IS NULL)
         ORDER BY published_at DESC
         LIMIT 5"
    );
    $stmt->execute([$lang]);
    $recentPosts = $stmt->fetchAll();
    ?>

    <div class="landing">
        <div class="landing-hero">
            <h1>See Our Family</h1>
            <p class="landing-tagline"><?= $L['landing_tagline'] ?></p>
        </div>

        <hr class="landing-sep">

        <?php if ($recentPosts):
            $latestPost = array_shift($recentPosts);
        ?>
        <article class="landing-feature">
            <h2><a href="/blog/<?= h($latestPost['uuid']) ?>"><?= h($latestPost['title']) ?></a></h2>
            <?php if ($latestPost['published_at']): ?>
                <time class="blog-date"><?= date('F j, Y', strtotime($latestPost['published_at'])) ?></time>
            <?php endif; ?>
            <div class="blog-body">
                <?= \SeeOurFamily\Html::sanitize($latestPost['body']) ?>
            </div>
        </article>

        <?php if ($recentPosts): ?>
        <hr class="landing-sep">

        <div class="landing-blog">
            <h2><?= $L['news'] ?></h2>
            <?php foreach ($recentPosts as $post): ?>
            <article class="blog-card">
                <h3><a href="/blog/<?= h($post['uuid']) ?>"><?= h($post['title']) ?></a></h3>
                <?php if ($post['published_at']): ?>
                    <time class="blog-date"><?= date('F j, Y', strtotime($post['published_at'])) ?></time>
                <?php endif; ?>
                <p class="blog-excerpt"><?= h(mb_strimwidth(html_entity_decode(strip_tags($post['body']), ENT_QUOTES, 'UTF-8'), 0, 200, '...')) ?></p>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <?php
    return;
}

// ---- Mode 2: Family selected — show family blog posts ----
$fid = $auth->familyId();

$stmt = $db->pdo()->prepare(
    "SELECT uuid, title, body, published_at
     FROM blog_posts
     WHERE family_id = ?
       AND is_published = 1 AND is_hidden = 0 AND is_online = 1
       AND (language = ? OR language IS NULL)
     ORDER BY published_at DESC
     LIMIT 20"
);
$stmt->execute([$fid, $lang]);
$familyPosts = $stmt->fetchAll();
?>

<table border="0" width="80%" align="center"><tr><td>

<?php if ($familyPosts): ?>
    <?php foreach ($familyPosts as $post): ?>
    <article style="margin-bottom: 16px;">
        <?php if ($post['published_at']): ?>
            <time class="blog-date" style="color:#666; font-size:9pt;"><?= date('F j, Y', strtotime($post['published_at'])) ?></time>
        <?php endif; ?>
        <h2><?= h($post['title']) ?></h2>
        <div class="blog-body">
            <?= fix_utf8(\SeeOurFamily\Html::sanitize($post['body'])) ?>
        </div>
    </article>
    <?php endforeach; ?>
<?php else: ?>
    <h1><?= $familyTitle ?></h1>
<?php endif; ?>

</td></tr></table>
