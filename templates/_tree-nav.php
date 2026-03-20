<?php
/**
 * Shared tree navigation bar.
 * Included by all tree page templates via:
 *   $treeNavUuid    = h($personUuid) or h($person['uuid'])
 *   $treeNavCurrent = 'classic' | 'ascendants' | 'descendants' | 'donut' | 'donut_desc'
 *                   | 'family_chart' | 'full_tree' | 'hybrid' | 'timeline' | 'treant'
 *   $treeNavExtra   = (optional) extra HTML to display after the person name line
 *   require __DIR__ . '/../_tree-nav.php';
 */

$treeNavExtra  = $treeNavExtra ?? '';

$treeNavLinks = [
    'classic'      => ['url' => "/tree/{$treeNavUuid}",      'label' => $L['classic']],
    'family_chart' => ['url' => "/treeFC/{$treeNavUuid}",    'label' => $L['tree_fc'] ?? 'Family Chart'],
    'donut'        => ['url' => "/treeDo/{$treeNavUuid}",    'label' => $L['tree_donut'] ?? 'Donut'],
    'donut_desc'   => ['url' => "/treeDoDesc/{$treeNavUuid}",'label' => $L['tree_donut_desc'] ?? 'Descendant Fan'],
    'ascendants'   => ['url' => "/ascendants/{$treeNavUuid}",'label' => $L['full_ascendance']],
    'descendants'  => ['url' => "/descendants/{$treeNavUuid}",'label' => $L['full_descendance']],
    'full_tree'    => ['url' => "/treeP/{$treeNavUuid}",     'label' => $L['tree_person'] ?? 'Full Tree'],
    'hybrid'       => ['url' => "/treePT/{$treeNavUuid}",    'label' => $L['tree_hybrid'] ?? 'Hybrid'],
    'timeline'     => ['url' => "/treeT/{$treeNavUuid}",     'label' => $L['tree_timeline'] ?? 'Timeline'],
    'treant'       => ['url' => "/treeTr/{$treeNavUuid}",    'label' => $L['tree_treant'] ?? 'Treant'],
];

// Current view label
$currentLabel = $treeNavLinks[$treeNavCurrent]['label'] ?? '';
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    <?= $currentLabel ?>
    <?= $treeNavExtra ?>
    <span class="nav-links">|
<?php
$first = true;
foreach ($treeNavLinks as $key => $link):
    if ($key === $treeNavCurrent) continue;
    if (!$first) echo ' . ';
    $first = false;
?>
        <a href="<?= $link['url'] ?>"><?= $link['label'] ?></a>
<?php endforeach; ?>
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
<?php if ($treeNavCurrent === 'hybrid'): ?>
    <span class="nav-links" style="margin-left:12px">
        | <?= $L['tree_priority'] ?? 'Priority' ?>:
        <a href="#" onclick="setTreePriority('family',event)" id="treept-btn-family"><?= $L['tree_priority_family'] ?? 'Family' ?></a> .
        <a href="#" onclick="setTreePriority('patriarchal',event)" id="treept-btn-patriarchal"><?= $L['tree_priority_patriarchal'] ?? 'Patriarchal' ?></a>
    </span>
<?php endif; ?>
</div>
