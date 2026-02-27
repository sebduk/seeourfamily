<?php

/**
 * System Admin: Global blog post management.
 *
 * Create, edit, publish, unpublish, and delete global blog posts (family_id IS NULL).
 * Global posts appear on the public landing page, filtered by language.
 * The super-admin can designate a language for each post.
 *
 * Available from index.php: $db, $auth, $router, $L, $isLoggedIn
 */

if (!$auth->isSuperAdmin()) {
    echo '<p>System admin access required. <a href="/login">Login</a></p>';
    return;
}

$pdo = $db->pdo();
$message = '';
$action = $_GET['action'] ?? '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

$languages = ['ENG', 'FRA', 'ESP', 'ITA', 'POR', 'DEU', 'NLD'];

// ---- Handle POST actions ----

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['form_action'] ?? '';

    if ($postAction === 'create_post') {
        $title    = trim($_POST['title'] ?? '');
        $body     = \SeeOurFamily\Html::clean($_POST['body'] ?? '');
        $postLang = $_POST['language'] ?? '';
        $publish  = !empty($_POST['publish']);

        if ($postLang === '') $postLang = null;

        if ($title === '') {
            $message = '<span style="color:#c00;">Title is required.</span>';
        } else {
            $uuid = sprintf('%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            );
            $stmt = $pdo->prepare(
                'INSERT INTO blog_posts (uuid, family_id, language, title, body, is_published, published_at, updated_by, created_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $uuid,
                $postLang,
                $title,
                $body,
                $publish ? 1 : 0,
                $publish ? date('Y-m-d H:i:s') : null,
                $auth->userId(),
            ]);
            $newId = $pdo->lastInsertId();
            $message = '<span style="color:#060;">Post "' . h($title) . '" created (ID ' . $newId . ').</span>';
            if ($publish) {
                $message .= ' <a href="/blog/' . h($uuid) . '" target="_blank">View &rarr;</a>';
            }
        }
    }

    if ($postAction === 'update_post') {
        $postId   = (int)($_POST['post_id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $body     = \SeeOurFamily\Html::clean($_POST['body'] ?? '');
        $postLang = $_POST['language'] ?? '';

        if ($postLang === '') $postLang = null;

        if ($postId && $title !== '') {
            $stmt = $pdo->prepare(
                'UPDATE blog_posts SET title = ?, body = ?, language = ?, updated_by = ? WHERE id = ? AND family_id IS NULL'
            );
            $stmt->execute([$title, $body, $postLang, $auth->userId(), $postId]);
            $message = '<span style="color:#060;">Post #' . $postId . ' updated.</span>';
        }
    }

    if ($postAction === 'toggle_publish') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $current = $pdo->prepare('SELECT is_published FROM blog_posts WHERE id = ? AND family_id IS NULL');
        $current->execute([$postId]);
        $current = $current->fetch();
        if ($current) {
            $newState = $current['is_published'] ? 0 : 1;
            $publishedAt = $newState ? date('Y-m-d H:i:s') : null;
            $pdo->prepare(
                'UPDATE blog_posts SET is_published = ?, published_at = COALESCE(published_at, ?), updated_by = ? WHERE id = ? AND family_id IS NULL'
            )->execute([$newState, $publishedAt, $auth->userId(), $postId]);
            $message = '<span style="color:#060;">Post #' . $postId . ($newState ? ' published.' : ' unpublished.') . '</span>';
        }
    }

    if ($postAction === 'toggle_hidden') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare(
            'UPDATE blog_posts SET is_hidden = NOT is_hidden, updated_by = ? WHERE id = ? AND family_id IS NULL'
        )->execute([$auth->userId(), $postId]);
        $message = '<span style="color:#060;">Visibility toggled for post #' . $postId . '.</span>';
    }

    if ($postAction === 'delete_post') {
        $postId = (int)($_POST['post_id'] ?? 0);
        $pdo->prepare(
            'UPDATE blog_posts SET is_online = 0, updated_by = ? WHERE id = ? AND family_id IS NULL'
        )->execute([$auth->userId(), $postId]);
        $message = '<span style="color:#060;">Post #' . $postId . ' deleted.</span>';
    }
}

// ---- Fetch global posts only ----

$posts = $pdo->query(
    'SELECT id, uuid, title, language, is_published, is_hidden, published_at, created_at, updated_at
     FROM blog_posts
     WHERE family_id IS NULL AND is_online = 1
     ORDER BY COALESCE(published_at, created_at) DESC'
)->fetchAll();

?>

<div class="page-wrap">
    <h2><a href="/system-admin" style="color:inherit;">System Admin</a> &gt; Blog</h2>

    <?php if ($message): ?>
        <p><?= $message ?></p>
    <?php endif; ?>

    <?php if ($action === 'new'): ?>
    <!-- Create new post form -->
    <div style="border:1px solid #ccc; padding:16px; margin:16px 0; background:#f9f9f9;">
        <h3>New Blog Post</h3>
        <form method="post">
            <input type="hidden" name="form_action" value="create_post">
            <div style="margin-bottom:8px;">
                <label>Title:<br>
                    <input type="text" name="title" class="box" style="width:100%; max-width:500px;" required>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Language:<br>
                    <select name="language" class="box">
                        <option value="">(All languages)</option>
                        <?php foreach ($languages as $lc): ?>
                            <option value="<?= $lc ?>"><?= $lc ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Body:<br>
                    <textarea name="body" class="box" rows="15" style="width:100%; max-width:700px;" data-richtext></textarea>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label><input type="checkbox" name="publish" value="1"> Publish immediately</label>
            </div>
            <button type="submit" class="box">Create</button>
            <a href="/system-admin/blog" style="margin-left:12px;">Cancel</a>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($editId):
        $editPost = $pdo->prepare('SELECT * FROM blog_posts WHERE id = ? AND family_id IS NULL AND is_online = 1');
        $editPost->execute([$editId]);
        $editPost = $editPost->fetch();
        if ($editPost):
    ?>
    <!-- Edit post form -->
    <div style="border:1px solid #ccc; padding:16px; margin:16px 0; background:#f9f9f9;">
        <h3>Edit: <?= h($editPost['title']) ?></h3>
        <form method="post">
            <input type="hidden" name="form_action" value="update_post">
            <input type="hidden" name="post_id" value="<?= (int)$editId ?>">
            <div style="margin-bottom:8px;">
                <label>Title:<br>
                    <input type="text" name="title" value="<?= h($editPost['title']) ?>" class="box" style="width:100%; max-width:500px;" required>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Language:<br>
                    <select name="language" class="box">
                        <option value="">(All languages)</option>
                        <?php foreach ($languages as $lc): ?>
                            <option value="<?= $lc ?>" <?= ($editPost['language'] === $lc) ? 'selected' : '' ?>><?= $lc ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label>Body:<br>
                    <textarea name="body" class="box" rows="15" style="width:100%; max-width:700px;" data-richtext><?= h($editPost['body']) ?></textarea>
                </label>
            </div>
            <button type="submit" class="box">Save</button>
            <a href="/system-admin/blog" style="margin-left:12px;">Cancel</a>
            <?php if ($editPost['is_published']): ?>
                | <a href="/blog/<?= h($editPost['uuid']) ?>" target="_blank">View live &rarr;</a>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; endif; ?>

    <!-- Post list -->
    <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse; width:100%;">
        <thead style="background:#eee;">
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Language</th>
                <th>Status</th>
                <th>Published</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts as $p):
                $status = [];
                if ($p['is_published']) $status[] = '<span style="color:#060;">Published</span>';
                else $status[] = '<span style="color:#999;">Draft</span>';
                if ($p['is_hidden']) $status[] = '<span style="color:#c60;">Hidden</span>';
            ?>
            <tr>
                <td><?= (int)$p['id'] ?></td>
                <td><?= h($p['title']) ?></td>
                <td><?= $p['language'] ? h($p['language']) : '<span style="color:#999;">All</span>' ?></td>
                <td><?= implode(' / ', $status) ?></td>
                <td><?= $p['published_at'] ? h($p['published_at']) : '&mdash;' ?></td>
                <td style="white-space:nowrap;">
                    <a href="/system-admin/blog?edit=<?= (int)$p['id'] ?>">Edit</a>
                    |
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="form_action" value="toggle_publish">
                        <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" style="border:none; background:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                            <?= $p['is_published'] ? 'Unpublish' : 'Publish' ?>
                        </button>
                    </form>
                    |
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="form_action" value="toggle_hidden">
                        <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" style="border:none; background:none; color:#00c; cursor:pointer; text-decoration:underline; font-size:inherit;">
                            <?= $p['is_hidden'] ? 'Unhide' : 'Hide' ?>
                        </button>
                    </form>
                    |
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this post?')">
                        <input type="hidden" name="form_action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" style="border:none; background:none; color:#c00; cursor:pointer; text-decoration:underline; font-size:inherit;">Delete</button>
                    </form>
                    <?php if ($p['is_published'] && !$p['is_hidden']): ?>
                        | <a href="/blog/<?= h($p['uuid']) ?>" target="_blank">View</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:12px;"><a href="/system-admin/blog?action=new">+ New blog post</a></p>
</div>
