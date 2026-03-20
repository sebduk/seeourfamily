<?php
/**
 * Shared tree navigation bar.
 * Included by all tree page templates via:
 *   $treeNavUuid    = h($personUuid) or h($person['uuid'])
 *   $treeNavCurrent = 'classic' | 'family_chart' | 'timeline' | 'treant'
 *                   | 'full_tree' | 'donut' | 'donut_desc'
 *   $treeNavExtra   = (optional) extra HTML to display after the person name line
 *   require __DIR__ . '/../_tree-nav.php';
 */

$treeNavExtra  = $treeNavExtra ?? '';

// Fixed order: tree, treeFC, treeT, treeTr, treeP, treeDo, treeDoDesc
$treeNavLinks = [
    'classic'      => ['url' => "/tree/{$treeNavUuid}",       'label' => $L['classic']],
    'family_chart' => ['url' => "/treeFC/{$treeNavUuid}",     'label' => $L['tree_fc'] ?? 'Family Chart'],
    'timeline'     => ['url' => "/treeT/{$treeNavUuid}",      'label' => $L['tree_timeline'] ?? 'Timeline'],
    'treant'       => ['url' => "/treeTr/{$treeNavUuid}",     'label' => $L['tree_treant'] ?? 'Treant'],
    'full_tree'    => ['url' => "/treeP/{$treeNavUuid}",      'label' => $L['tree_person'] ?? 'Full Tree'],
    'donut'        => ['url' => "/treeDo/{$treeNavUuid}",     'label' => $L['tree_donut'] ?? 'Donut'],
    'donut_desc'   => ['url' => "/treeDoDesc/{$treeNavUuid}", 'label' => $L['tree_donut_desc'] ?? 'Descendant Fan'],
];
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $treeNavExtra ?>
    <span class="nav-links">
<?php
$first = true;
foreach ($treeNavLinks as $key => $link):
    if (!$first) echo ' . ';
    $first = false;
    if ($key === $treeNavCurrent):
?>
        <strong><?= $link['label'] ?></strong>
<?php else: ?>
        <a href="<?= $link['url'] ?>"><?= $link['label'] ?></a>
<?php
    endif;
endforeach;
?>
    </span>
<?php if ($treeNavCurrent === 'classic' && !empty($hasChildren)): ?>
    <br>
    <strong>&nbsp;</strong>
    <?= $L['full_ascendance'] ?>
    <span class="nav-links">|
        <a href="/tree/<?= $treeNavUuid ?>?dir=asc&amp;style=horizontal"><?= $L['horizontal'] ?></a> .
        <a href="/tree/<?= $treeNavUuid ?>?dir=asc&amp;style=table"><?= $L['table'] ?></a> .
        <a href="/tree/<?= $treeNavUuid ?>?dir=asc&amp;style=excel"><?= $L['excel'] ?></a>
    </span>
    <br>
    <strong>&nbsp;</strong>
    <?= $L['full_descendance'] ?>
    <span class="nav-links">|
        <a href="/tree/<?= $treeNavUuid ?>?dir=desc&amp;style=horizontal"><?= $L['horizontal'] ?></a> .
        <a href="/tree/<?= $treeNavUuid ?>?dir=desc&amp;style=table"><?= $L['table'] ?></a> .
        <a href="/tree/<?= $treeNavUuid ?>?dir=desc&amp;style=excel"><?= $L['excel'] ?></a>
    </span>
<?php elseif ($treeNavCurrent === 'classic'): ?>
    <br>
    <strong>&nbsp;</strong>
    <?= $L['full_ascendance'] ?>
    <span class="nav-links">|
        <a href="/tree/<?= $treeNavUuid ?>?dir=asc&amp;style=horizontal"><?= $L['horizontal'] ?></a> .
        <a href="/tree/<?= $treeNavUuid ?>?dir=asc&amp;style=table"><?= $L['table'] ?></a> .
        <a href="/tree/<?= $treeNavUuid ?>?dir=asc&amp;style=excel"><?= $L['excel'] ?></a>
    </span>
<?php endif; ?>
</div>
