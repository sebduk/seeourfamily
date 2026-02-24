<?php

/**
 * Birthday calendar page.
 *
 * Replaces Prog/View/lstCalendrier.asp.
 * Keeps <table> (genuine tabular data) but uses modern CSS styling (.cal-table).
 *
 * Available from index.php: $db, $auth, $router, $family, $L, $isLoggedIn
 */

if (!$isLoggedIn) { echo '<p><a href="/login">' . $L['menu_login'] . '</a></p>'; return; }

$fid = $auth->familyId();
$pdo = $db->pdo();
$months = $L['months'] ?? [];

$daysInMonth = [1=>31,2=>29,3=>31,4=>30,5=>31,6=>30,7=>31,8=>31,9=>30,10=>31,11=>30,12=>31];

// Load people with a known full birthdate (day+month), ordered by month/day/year.
// Exclude year-only records (birth_precision='y', stored as YYYY-01-01 placeholders).
// If birth_precision is NULL (data imported without migration), include the record.
$stmt = $pdo->prepare(
    "SELECT id, uuid, first_name, last_name,
            DAY(birth_date)  AS bday,
            MONTH(birth_date) AS bmonth,
            YEAR(birth_date) AS byear,
            death_date, email
     FROM people
     WHERE birth_date IS NOT NULL
       AND (birth_precision IS NULL OR birth_precision <> 'y')
       AND family_id = ?
     ORDER BY MONTH(birth_date), DAY(birth_date), YEAR(birth_date)"
);
$stmt->execute([$fid]);
$people = $stmt->fetchAll();

// Build a lookup: $cal[month][day] = [ [person], ... ]
$cal = [];
foreach ($people as $p) {
    $cal[(int)$p['bmonth']][(int)$p['bday']][] = $p;
}

$today = new DateTime();
$todayMonth = (int)$today->format('n');
$todayDay   = (int)$today->format('j');
?>

<table class="cal-table">
<?php for ($startMonth = 1; $startMonth <= 12; $startMonth += 3): ?>
    <!-- Month header row -->
    <tr>
        <th class="day-num">&nbsp;</th>
        <?php for ($m = $startMonth; $m < $startMonth + 3; $m++): ?>
            <th><?= $months[$m] ?? '' ?></th>
        <?php endfor; ?>
        <th class="day-num">&nbsp;</th>
    </tr>

    <!-- Day rows (1-31) -->
    <?php for ($day = 1; $day <= 31; $day++): ?>
    <tr>
        <td class="day-num"><?= $day ?></td>
        <?php for ($m = $startMonth; $m < $startMonth + 3; $m++):
            $isToday = ($m === $todayMonth && $day === $todayDay);
            $class = $isToday ? ' class="today"' : '';
        ?>
            <td<?= $class ?>>
            <?php if ($day <= $daysInMonth[$m] && !empty($cal[$m][$day])):
                foreach ($cal[$m][$day] as $k => $p):
                    if ($k > 0) echo '<br>';
            ?>
                <a href="/tree/<?= h($p['uuid']) ?>"><?= h($p['last_name']) ?>&nbsp;<?= h($p['first_name']) ?>&nbsp;(<?= h($p['byear']) ?>)</a><?php
                    if ($p['email'] && !$p['death_date']):
                ?> <a href="/messages?IDForum=perso&amp;IDPerso=<?= h($p['uuid']) ?>"><b>&#64;</b></a><?php
                    endif;
                endforeach;
            else:
                echo '&nbsp;';
            endif; ?>
            </td>
        <?php endfor; ?>
        <td class="day-num"><?= $day ?></td>
    </tr>
    <?php endfor; ?>
<?php endfor; ?>
</table>

<p class="cal-note">[<?= $L['calendar_warn'] ?>]</p>
