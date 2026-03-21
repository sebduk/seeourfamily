<?php
/**
 * Shared tree navigation bar.
 * Included by all tree page templates via:
 *   $treeNavUuid    = h($personUuid) or h($person['uuid'])
 *   $treeNavCurrent = 'classic' | 'family_chart' | 'timeline' | 'treant'
 *                   | 'full_tree' | 'donut' | 'donut_desc'
 *                   | 'asc_horizontal' | 'desc_horizontal' | 'asc_table' | 'desc_table'
 *   require __DIR__ . '/../_tree-nav.php';
 */

// All nav links in a single line, in the requested order
$treeNavLinks = [
    'classic'         => ['url' => "/tree/{$treeNavUuid}",                              'label' => $L['classic']],
    'family_chart'    => ['url' => "/treeFC/{$treeNavUuid}",                            'label' => $L['tree_fc'] ?? 'Family Chart'],
    'timeline'        => ['url' => "/treeT/{$treeNavUuid}",                             'label' => $L['tree_timeline'] ?? 'Timeline'],
    'treant'          => ['url' => "/treeTr/{$treeNavUuid}",                            'label' => $L['tree_treant'] ?? 'Treant'],
    'donut'           => ['url' => "/treeDo/{$treeNavUuid}",                            'label' => $L['tree_donut'] ?? 'Donut'],
    'donut_desc'      => ['url' => "/treeDoDesc/{$treeNavUuid}",                        'label' => $L['tree_donut_desc'] ?? 'Descendant Fan'],
    'asc_horizontal'  => ['url' => "/tree/{$treeNavUuid}?dir=asc&style=horizontal",     'label' => ($L['full_ascendance'] ?? 'Asc') . ' ' . ($L['horizontal'] ?? 'Horizontal')],
    'desc_horizontal' => ['url' => "/tree/{$treeNavUuid}?dir=desc&style=horizontal",    'label' => ($L['full_descendance'] ?? 'Desc') . ' ' . ($L['horizontal'] ?? 'Horizontal')],
    'asc_table'       => ['url' => "/tree/{$treeNavUuid}?dir=asc&style=table",          'label' => ($L['full_ascendance'] ?? 'Asc') . ' ' . ($L['table'] ?? 'Table')],
    'desc_table'      => ['url' => "/tree/{$treeNavUuid}?dir=desc&style=table",         'label' => ($L['full_descendance'] ?? 'Desc') . ' ' . ($L['table'] ?? 'Table')],
    'full_tree'       => ['url' => "/treeP/{$treeNavUuid}",                             'label' => $L['tree_person'] ?? 'Full Tree'],
];
?>
<div class="tree-nav">
    <strong><?= h($personName) ?></strong>
    &nbsp;&mdash;&nbsp;
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
</div>
