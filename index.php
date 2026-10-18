<?php
/* Dashboard presences : lit les notifications "attendance" et la table students (deja existantes). */
require __DIR__ . '/lib.php';

define('START_AM', '08:00'); define('START_PM', '14:00'); define('TOLERANCE', 10);
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function analyse($t) {
    sort($t);
    $clean = array(); $prev = null;
    foreach ($t as $x) {
        if ($prev === null || (strtotime($x) - strtotime($prev)) >= 120) { $clean[] = $x; $prev = $x; }
    }
    $am = array(); $pm = array();
    foreach ($clean as $x) { if ($x < '13:00') $am[] = $x; else $pm[] = $x; }
    $r = array('in1' => null, 'out1' => null, 'in2' => null, 'out2' => null);
    if ($am) {
        if (count($am) == 1 && $am[0] >= '11:00') $r['out1'] = $am[0];
        else { $r['in1'] = $am[0]; if (count($am) > 1) $r['out1'] = end($am); }
    }
    if ($pm) {
        if (count($pm) == 1 && $pm[0] >= '17:00') $r['out2'] = $pm[0];
        else { $r['in2'] = $pm[0]; if (count($pm) > 1) $r['out2'] = end($pm); }
    }
    return $r;
}

$error = null; $names = array(); $logs = array();
$today = date('Y-m-d');
$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-01');
$to   = isset($_GET['to'])   ? $_GET['to']   : $today;
$emp  = isset($_GET['emp'])  ? $_GET['emp']  : '';
try {
    foreach ((array)sb('GET', 'students?id_pointeuse=not.is.null&select=id,first_name,last_name&limit=2000') as $s)
        $names[(string)$s['id']] = trim($s['first_name'] . ' ' . $s['last_name']);
    $a = rawurlencode($from . 'T00:00:00+01:00'); $b = rawurlencode($to . 'T23:59:59+01:00');
    $logs = (array)sb('GET', 'notifications?type=eq.attendance&created_at=gte.' . $a . '&created_at=lte.' . $b . '&select=student_id,created_at&order=created_at.asc&limit=20000');
} catch (Exception $e) { $error = $e->getMessage(); }

$days = array();
foreach ($logs as $l) {
    $d = new DateTime($l['created_at']); $d->setTimezone(new DateTimeZone('Africa/Tunis'));
    $id = (string)$l['student_id'];
    if ($emp !== '' && $emp !== $id) continue;
    $days[$d->format('Y-m-d')][$id][] = $d->format('H:i');
}
krsort($days);

$lim1 = strtotime(START_AM) + TOLERANCE * 60; $lim2 = strtotime(START_PM) + TOLERANCE * 60;
$rows = array(); $late = 0;
foreach ($days as $d => $list) foreach ($list as $id => $t) {
    $a = analyse($t);
    $l1 = $a['in1'] && strtotime($a['in1']) > $lim1; $l2 = $a['in2'] && strtotime($a['in2']) > $lim2;
    $tags = array();
    if ($l1) $tags[] = array('Retard matin', 1);
    if ($l2) $tags[] = array('Retard après-midi', 1);
    if (!$tags) $tags[] = array("À l'heure", 0);
    if ($l1 || $l2) $late++;
    $rows[] = array('d' => $d, 'name' => isset($names[$id]) ? $names[$id] : "ID $id", 'a' => $a, 'tags' => $tags, 'n' => count($t));
}

$presentToday = isset($days[$today]) ? count($days[$today]) : 0;
$lateToday = 0;
if (isset($days[$today])) foreach ($days[$today] as $t) {
    $a = analyse($t);
    if (($a['in1'] && strtotime($a['in1']) > $lim1) || ($a['in2'] && strtotime($a['in2']) > $lim2)) $lateToday++;
}
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Suivi des présences</title>
<style>
:root{--ink:#14213d;--muted:#6b7688;--line:#d8dee6;--ok:#1f7a5a;--late:#c2410c}
body{margin:0;background:#eef1f4;color:var(--ink);font:15px/1.5 "Segoe UI",system-ui,sans-serif}
header{background:var(--ink);color:#fff;padding:16px 24px}header h1{margin:0;font-size:20px}
main{max-width:1200px;margin:20px auto;padding:0 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
.stat{background:#fff;border-left:5px solid var(--ink);padding:12px 16px;border-radius:4px}.stat b{display:block;font-size:28px}
form{background:#fff;padding:12px 16px;border-radius:4px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
label{display:flex;flex-direction:column;font-size:13px;color:var(--muted)}
input,select{padding:7px;border:1px solid var(--line);border-radius:4px;font:inherit}
button{padding:8px 14px;border:0;border-radius:4px;background:var(--ink);color:#fff;font:inherit;cursor:pointer}
.panel{background:#fff;border-radius:4px;overflow-x:auto}table{width:100%;border-collapse:collapse}
th,td{padding:9px 12px;text-align:left;border-bottom:1px solid var(--line);white-space:nowrap}th{background:#f6f8fa}
.tag{padding:2px 8px;border-radius:10px;font-size:12px;background:#dcf1e8;color:var(--ok);margin-right:4px}.tag.l{background:#fde7d9;color:var(--late)}
.err{background:#fde7d9;color:var(--late);padding:12px 16px;border-radius:4px;margin-bottom:14px}
</style></head><body>
<header><h1>Suivi des présences</h1></header>
<main>
<?php if ($error) { ?><div class="err"><?php echo h($error); ?></div><?php } ?>
<section class="stats">
  <div class="stat"><b><?php echo $presentToday; ?></b>Présents aujourd'hui</div>
  <div class="stat"><b><?php echo $lateToday; ?></b>En retard aujourd'hui</div>
  <div class="stat"><b><?php echo count($names); ?></b>Élèves enregistrés</div>
</section>
<form method="get">
  <label>Du <input type="date" name="from" value="<?php echo h($from); ?>"></label>
  <label>Au <input type="date" name="to" value="<?php echo h($to); ?>"></label>
  <label>Élève <select name="emp"><option value="">Tous</option>
    <?php foreach ($names as $id => $n) { ?><option value="<?php echo h($id); ?>"<?php if ($emp === (string)$id) echo ' selected'; ?>><?php echo h($n); ?></option><?php } ?>
  </select></label>
  <button type="submit">Filtrer</button>
</form>
<div class="panel"><table>
<thead><tr><th>Date</th><th>Élève</th><th>Entrée matin</th><th>Sortie midi</th><th>Entrée ap-midi</th><th>Sortie soir</th><th>Pointages</th><th>Statut</th></tr></thead>
<tbody>
<?php foreach ($rows as $r) { $a = $r['a']; ?>
<tr><td><?php echo h($r['d']); ?></td><td><?php echo h($r['name']); ?></td>
<td><?php echo h($a['in1'] ?: '—'); ?></td><td><?php echo h($a['out1'] ?: '—'); ?></td>
<td><?php echo h($a['in2'] ?: '—'); ?></td><td><?php echo h($a['out2'] ?: '—'); ?></td>
<td><?php echo $r['n']; ?></td>
<td><?php foreach ($r['tags'] as $tg) { ?><span class="tag<?php if ($tg[1]) echo ' l'; ?>"><?php echo h($tg[0]); ?></span><?php } ?></td></tr>
<?php } if (!$rows) { ?><tr><td colspan="8">Aucun pointage sur cette période.</td></tr><?php } ?>
</tbody></table></div>
<p style="color:var(--muted)"><?php echo count($rows); ?> lignes · <?php echo $late; ?> retards sur la période</p>
</main></body></html>
