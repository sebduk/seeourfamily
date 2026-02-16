<?php

/**
 * Help / user guide page.
 *
 * Replaces Prog/Help/User.eng.asp, User.fra.asp, etc.
 * Centered wrapper (.page-wrap). The content is language-dependent.
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn, $lang
 */
?>
<div class="page-wrap">

<h1><?= ($lang === 'FRA') ? 'Guide Utilisateur' : 'User Guide' ?></h1>

<?php if ($lang === 'FRA'): ?>
<!-- French help -->
<h2>Visitez le site!</h2>
<p>
En haut de l'&eacute;cran, vous trouverez:<br>
<?= $L['menu_genealogy'] ?> [<?= $L['menu_names'] ?>] [<?= $L['menu_years'] ?>] [<?= $L['menu_calendar'] ?>] [<?= $L['menu_pictures'] ?>]<br>
pour acc&eacute;der &agrave; la liste des personnes enregistr&eacute;es sur le site.
</p>

<p>
<u><?= $L['menu_names'] ?></u>: tri&eacute; par nom de famille et pr&eacute;noms.<br>
<u><?= $L['menu_years'] ?></u>: tri&eacute; par ann&eacute;e de naissance.<br>
<u><?= $L['menu_calendar'] ?></u>: tri&eacute; par date d'anniversaire et pr&eacute;sent&eacute; sous forme de calendrier.<br>
<u><?= $L['menu_pictures'] ?></u>: liste des photos du site par ordre chronologique.<br>
</p>

<hr>

<p>
Cliquez sur un nom pour acc&eacute;der &agrave; l'arbre g&eacute;n&eacute;alogique centr&eacute; sur cette personne,
avec 2 g&eacute;n&eacute;rations au-dessus et 2 en dessous (des grands-parents aux petits-enfants).<br>
Cliquez sur une photo pour la voir en plein format et obtenir les liens vers toutes les personnes pr&eacute;sentes.
</p>

<?php else: ?>
<!-- English help (default) -->
<h2>Visit the site!</h2>
<p>
Find at the top of the screen:<br>
<?= $L['menu_genealogy'] ?> [<?= $L['menu_names'] ?>] [<?= $L['menu_years'] ?>] [<?= $L['menu_calendar'] ?>] [<?= $L['menu_pictures'] ?>]<br>
to access the list of people registered on the site.
</p>

<p>
<u><?= $L['menu_names'] ?></u>: ordered by family names and Christian names.<br>
<u><?= $L['menu_years'] ?></u>: ordered by year of birth.<br>
<u><?= $L['menu_calendar'] ?></u>: ordered by birthday and presented as a calendar
(for people for whom an exact birthday is known).<br>
<u><?= $L['menu_pictures'] ?></u>: list of pictures on the site by chronological order.<br>
</p>

<p>
Click on a name to access a family tree centred on the person, with 2 generations above and 2 below
(from grand-parents to grand-children).<br>
Click on a picture to see it in full format and get links to all the people present on the picture.
</p>

<hr>

<h3>Tree details</h3>
<p>
Click on a name to recentre the tree on a person.<br>
Click on dates to open the person's biography, with comments and pictures.<br>
</p>

<p>
Above each tree you will find links to alternate views:<br>
<b><?= $L['full_ascendance'] ?></b> and <b><?= $L['full_descendance'] ?></b> in several formats:
</p>

<p>
<u><?= $L['horizontal'] ?></u>: an inverted pyramid based on the chosen person.<br>
<u><?= $L['vertical'] ?></u>: a pyramid on its side, similar to the previous.<br>
<u><?= $L['table'] ?></u>: a table presentation of forefathers/descendants.<br>
<u><?= $L['excel'] ?></u>: identical in Excel format.<br>
</p>

<p><b><i><?= $L['heavy_warning'] ?></i></b></p>

<hr>

<h3>Biography details</h3>
<p>
Each person's page contains sections for:<br>
<b><?= $L['biography'] ?></b>, <b><?= $L['comments'] ?></b>, <b><?= $L['pictures'] ?></b>,
and <b><?= $L['documents'] ?></b>.<br>
Click the person's name to centre the tree on them, or click names in <i>italic</i> to visit linked people.
</p>
<?php endif; ?>

</div>
