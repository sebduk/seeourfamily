<?php

/**
 * Messages / forum page.
 *
 * Replaces Prog/View/message.asp.
 * Centered wrapper (.page-wrap) + forum styling replaces table+bgcolor layout.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$months = $L['months'] ?? [];

$activeForum = $_GET['forum'] ?? $_POST['forum'] ?? null;
$viewAll = isset($_GET['v']);
$maxItems = $viewAll ? 1000 : 4;

// Handle POST: add message to forum
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['todo'] ?? '') === 'add') {
    $stmt = $pdo->prepare(
        'INSERT INTO forum_items (forum_id, title, author_name, author_email, body, is_online)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([
        (int)($_POST['forum'] ?? 0),
        $_POST['title'] ?? null,
        $_POST['author'] ?? null,
        $_POST['email'] ?? null,
        $_POST['body'] ?? null,
    ]);
}

// Load forums
$stmt = $pdo->prepare(
    'SELECT * FROM forums WHERE family_id = ? AND is_online = 1 ORDER BY sort_order, title'
);
$stmt->execute([$fid]);
$forums = $stmt->fetchAll();

// Personal messages section
$isPersonal = ($activeForum === 'perso') || empty($forums);

// People with email (for personal message recipient list)
$emailPeople = [];
if ($isPersonal) {
    $stmt = $pdo->prepare(
        'SELECT id, uuid, first_name, last_name,
                IFNULL(DATE_FORMAT(birth_date, "%Y"), "") AS birth
         FROM people WHERE family_id = ? AND email IS NOT NULL
         ORDER BY last_name, first_name'
    );
    $stmt->execute([$fid]);
    $emailPeople = $stmt->fetchAll();
}

$selectedPerson = $_GET['person'] ?? '';
?>

<div class="page-wrap">

<!-- Personal Messages -->
<div class="forum-section">
    <div class="forum-header-personal">
        <a href="/messages?forum=perso"><b><?= $L['msg_personal'] ?></b></a>
    </div>

    <?php if ($isPersonal): ?>
    <hr>
    <form action="/messages" method="post" name="SendForm">
        <input type="hidden" name="todo" value="send">
        <input type="hidden" name="forum" value="perso">
        <div class="forum-form">
            <label><?= $L['msg_subject'] ?></label>
            <input type="text" name="title" size="40">
            <label><?= $L['msg_from'] ?></label>
            <input type="text" name="author" size="20">
            <label><?= $L['msg_email'] ?? 'Email' ?></label>
            <input type="text" name="email" size="20">
            <input type="submit" value="<?= h($L['msg_send']) ?>" class="box"><br><br>

            <textarea name="body" rows="20" cols="80"></textarea>

            <?php if ($emailPeople): ?>
            <div style="margin-top:8px">
                <label><?= $L['msg_to'] ?></label><br>
                <select name="to[]" multiple size="20" class="text">
                    <?php foreach ($emailPeople as $ep): ?>
                        <option value="<?= h($ep['uuid']) ?>"<?= ($ep['uuid'] === $selectedPerson) ? ' selected' : '' ?>>
                            <?= h($ep['last_name']) ?>, <?= h($ep['first_name']) ?> (<?= h($ep['birth']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <hr>
    <?php endif; ?>
</div>

<!-- Public Forums -->
<?php
$isFirst = true;
foreach ($forums as $forum):
    $isActive = ($activeForum == $forum['id']) || ($activeForum === null && $isFirst);
?>
<div class="forum-section">
    <div class="forum-header">
        <a href="/messages?forum=<?= $forum['id'] ?>"><b><?= h($forum['title']) ?></b></a>
        <?php if ($isActive): ?>
            | <a href="/messages?forum=<?= $forum['id'] ?>&amp;v=a"><?= $L['msg_all'] ?></a>
        <?php endif; ?>
    </div>

    <?php if ($isActive):
        $stmt = $pdo->prepare(
            'SELECT * FROM forum_items WHERE forum_id = ? AND is_online = 1 ORDER BY posted_at DESC LIMIT ?'
        );
        $stmt->execute([$forum['id'], $maxItems]);
        $items = $stmt->fetchAll();

        foreach ($items as $item):
            $postedAt = $item['posted_at'] ? date('j M Y, H:i', strtotime($item['posted_at'])) : '';
    ?>
        <div class="forum-item-header">
            <?= h($item['title'] ?? '') ?> | <?= h($item['author_name'] ?? '') ?> | <?= $postedAt ?>
        </div>
        <div class="forum-item">
            <i><?= nl2br(h($item['body'] ?? '')) ?></i>
        </div>
    <?php endforeach; ?>

    <hr>
    <!-- Reply form -->
    <form action="/messages" method="post">
        <input type="hidden" name="todo" value="add">
        <input type="hidden" name="forum" value="<?= $forum['id'] ?>">
        <div class="forum-form">
            <label><?= $L['msg_subject'] ?></label>
            <input type="text" name="title" size="40">
            <label><?= $L['msg_from'] ?></label>
            <input type="text" name="author" size="20">
            <label><?= $L['msg_email'] ?? 'Email' ?></label>
            <input type="text" name="email" size="20">
            <input type="submit" value="<?= h($L['msg_send']) ?>" class="box"><br>
            <textarea name="body" rows="5" cols="120"></textarea>
        </div>
    </form>
    <hr>
    <?php endif; ?>
</div>
<?php
    $isFirst = false;
endforeach;
?>

</div>
