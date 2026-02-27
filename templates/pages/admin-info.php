<?php

/**
 * Admin: Family Blog management.
 *
 * Replaces the old Info CRUD (Prog/Admin/infoIndex.asp + infoList.asp + infoPage.asp).
 * Manages family-specific blog posts displayed on the family home page.
 * Posts are tagged with the family's language by default.
 * Uses the same blog_posts table as global (super-admin) posts but scoped by family_id.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn, $isAdmin, $lang
 */

if (!$isAdmin) { echo '<p>Admin access required.</p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$msg = '';

// Family language for default tagging
$familyLang = $family['language'] ?? 'ENG';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';
    $postId = (int)($_POST['post_id'] ?? 0);

    if ($action === 'create_post') {
        $title   = trim($_POST['title'] ?? '');
        $body    = \SeeOurFamily\Html::clean($_POST['body'] ?? '');
        $publish = !empty($_POST['publish']);

        if ($title !== '') {
            $uuid = sprintf('%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            );
            $stmt = $pdo->prepare(
                'INSERT INTO blog_posts (uuid, family_id, language, title, body, is_published, published_at, updated_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $uuid,
                $fid,
                $familyLang,
                $title,
                $body,
                $publish ? 1 : 0,
                $publish ? date('Y-m-d H:i:s') : null,
                $auth->userId(),
            ]);
            $msg = 'Post created.';
        }
    }

    if ($action === 'update_post' && $postId) {
        $title = trim($_POST['title'] ?? '');
        $body  = \SeeOurFamily\Html::clean($_POST['body'] ?? '');

        if ($title !== '') {
            $pdo->prepare(
                'UPDATE blog_posts SET title = ?, body = ?, updated_by = ? WHERE id = ? AND family_id = ?'
            )->execute([$title, $body, $auth->userId(), $postId, $fid]);
            $msg = 'Post updated.';
        }
    }

    if ($action === 'toggle_publish' && $postId) {
        $current = $pdo->prepare('SELECT is_published FROM blog_posts WHERE id = ? AND family_id = ?');
        $current->execute([$postId, $fid]);
        $current = $current->fetch();
        if ($current) {
            $newState = $current['is_published'] ? 0 : 1;
            $pdo->prepare(
                'UPDATE blog_posts SET is_published = ?, published_at = COALESCE(published_at, ?), updated_by = ? WHERE id = ? AND family_id = ?'
            )->execute([$newState, $newState ? date('Y-m-d H:i:s') : null, $auth->userId(), $postId, $fid]);
            $msg = $newState ? 'Post published.' : 'Post unpublished.';
        }
    }

    if ($action === 'delete_post' && $postId) {
        $pdo->prepare(
            'UPDATE blog_posts SET is_online = 0, updated_by = ? WHERE id = ? AND family_id = ?'
        )->execute([$auth->userId(), $postId, $fid]);
        $msg = 'Post deleted.';
    }
}

$showNew = ($_GET['action'] ?? '') === 'new';
$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

// Fetch family blog posts
$posts = $pdo->prepare(
    'SELECT id, uuid, title, is_published, published_at, created_at
     FROM blog_posts
     WHERE family_id = ? AND is_online = 1
     ORDER BY COALESCE(published_at, created_at) DESC'
);
$posts->execute([$fid]);
$posts = $posts->fetchAll();
?>

<?php require __DIR__ . '/../_admin-nav.php'; ?>
<?php if ($msg): ?><div class="admin-msg"><?= h($msg) ?></div><?php endif; ?>

<div class="admin-layout">
    <div class="admin-sidebar">
        <div class="section-title"><?= $L['news'] ?></div>
        <div class="sidebar-links"><a href="/admin/info?action=new">+ New post</a></div>
        <hr>
        <?php foreach ($posts as $p): ?>
            <a href="/admin/info?edit=<?= (int)$p['id'] ?>" style="<?= !$p['is_published'] ? 'color:#999;' : '' ?>">
                <?= h($p['title']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="admin-main">
        <?php if ($showNew): ?>
        <!-- Create new post -->
        <form method="post" action="/admin/info" class="admin-form">
            <input type="hidden" name="form_action" value="create_post">
            <h3>New Post</h3>
            <div class="form-row"><label>Title</label><input type="text" name="title" class="box" style="width:100%; max-width:500px;" required></div>
            <div class="form-row"><label>Content</label><textarea name="body" class="box" rows="12" style="width:100%; max-width:700px;" data-richtext></textarea></div>
            <div class="form-row"><label><input type="checkbox" name="publish" value="1"> Publish immediately</label></div>
            <div class="form-actions">
                <input type="submit" value="Create" class="box">
                <a href="/admin/info" style="margin-left:12px;">Cancel</a>
            </div>
        </form>

        <?php elseif ($editId):
            $editPost = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ? AND family_id = ? AND is_online = 1');
            $editPost->execute([$editId, $fid]);
            $editPost = $editPost->fetch();
            if ($editPost):
        ?>
        <!-- Edit post -->
        <form method="post" action="/admin/info" class="admin-form">
            <input type="hidden" name="form_action" value="update_post">
            <input type="hidden" name="post_id" value="<?= (int)$editPost['id'] ?>">
            <h3>Edit: <?= h($editPost['title']) ?></h3>
            <div class="form-row"><label>Title</label><input type="text" name="title" value="<?= h($editPost['title']) ?>" class="box" style="width:100%; max-width:500px;" required></div>
            <div class="form-row"><label>Content</label><textarea name="body" class="box" rows="12" style="width:100%; max-width:700px;" data-richtext><?= h($editPost['body']) ?></textarea></div>
            <div class="form-actions">
                <input type="submit" value="Save" class="box">
                <a href="/admin/info" style="margin-left:12px;">Cancel</a>
                &nbsp;|&nbsp;
                <form method="post" action="/admin/info" style="display:inline;">
                    <input type="hidden" name="form_action" value="toggle_publish">
                    <input type="hidden" name="post_id" value="<?= (int)$editPost['id'] ?>">
                    <button type="submit" style="border:none; background:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                        <?= $editPost['is_published'] ? 'Unpublish' : 'Publish' ?>
                    </button>
                </form>
                &nbsp;|&nbsp;
                <form method="post" action="/admin/info" style="display:inline;" onsubmit="return confirm('Delete this post?')">
                    <input type="hidden" name="form_action" value="delete_post">
                    <input type="hidden" name="post_id" value="<?= (int)$editPost['id'] ?>">
                    <button type="submit" style="border:none; background:none; color:#c00; cursor:pointer; text-decoration:underline; font-size:inherit;">Delete</button>
                </form>
            </div>
        </form>
        <?php endif; endif; ?>

        <?php if (!$showNew && !$editId): ?>
        <p>Select a post to edit, or <a href="/admin/info?action=new">create a new post</a>.</p>
        <?php endif; ?>
    </div>
</div>
