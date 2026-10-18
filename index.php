<?php
/* Dashboard présences (même logique que pointe_elev.php). Les pointages viennent des
   notifications "attendance" de Supabase (créées par adms.php) + table students. */
require __DIR__ . '/lib.php';

define('START_AM', '08:00');   // entrée matin
define('END_AM',   '12:00');   // sortie midi
define('START_PM', '14:00');   // entrée après-midi
define('END_PM',   '18:00');   // sortie soir
define('TOLERANCE', 10);       // minutes de tolérance
// IDs pointeuse (students.id_pointeuse) à afficher, séparés par des virgules. '' = tout le monde.
define('ONLY_IDS', '101,102,103,104,105,106,107,108,1');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function e($s) { echo h($s); }

/* Lit toutes les lignes (Supabase limite à 1000 par requête). */
function sbAll($path) {
    $out = array(); $off = 0;
    while (true) {
        $r = sb('GET', $path . '&limit=1000&offset=' . $off);
        if (!is_array($r) || !$r) break;
        $out = array_merge($out, $r);
        if (count($r) < 1000) break;
        $off += 1000;
        if ($off > 100000) break;
    }
    return $out;
}

/* Sépare les pointages d'une journée en matin (avant 13:00) et après-midi.
   Les pointages séparés de moins de 2 minutes sont comptés une seule fois.
   Un pointage unique tardif dans une demi-journée est considéré comme une sortie. */
function analyse($t) {
    sort($t);
    $clean = array(); $prev = null;
    foreach ($t as $x) {
        if ($prev === null || (strtotime($x) - strtotime($prev)) >= 120) { $clean[] = $x; $prev = $x; }
    }
    $t = $clean;
    $am = array(); $pm = array();
    foreach ($t as $x) { if ($x < '13:00') $am[] = $x; else $pm[] = $x; }
    $r = array('in1' => null, 'out1' => null, 'in2' => null, 'out2' => null);
    if ($am) {
        if (count($am) == 1 && $am[0] >= '11:00') { $r['out1'] = $am[0]; }
        else { $r['in1'] = $am[0]; if (count($am) > 1) $r['out1'] = $am[count($am) - 1]; }
    }
    if ($pm) {
        if (count($pm) == 1 && $pm[0] >= '17:00') { $r['out2'] = $pm[0]; }
        else { $r['in2'] = $pm[0]; if (count($pm) > 1) $r['out2'] = $pm[count($pm) - 1]; }
    }
    return $r;
}

/* Convertit une ligne notification en array(pin, 'Y-m-d', 'H:i') (heure de Tunis). */
function punch($l, $pinOf) {
    $sid = (string)$l['student_id'];
    if (!isset($pinOf[$sid])) return null;
    $d = new DateTime($l['created_at']);
    $d->setTimezone(new DateTimeZone('Africa/Tunis'));
    return array($pinOf[$sid], $d->format('Y-m-d'), $d->format('H:i'));
}

$error = null; $names = array(); $pinOf = array(); $logs = array(); $logsToday = array();
$today = date('Y-m-d');
$from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-01');
$to   = isset($_GET['to'])   ? $_GET['to']   : $today;
$emp  = isset($_GET['emp'])  ? $_GET['emp']  : '';
$only = array_filter(array_map('trim', explode(',', ONLY_IDS)), 'strlen');

try {
    foreach (sbAll('students?id_pointeuse=not.is.null&select=id,id_pointeuse,first_name,last_name&order=id.asc') as $s) {
        $pin = (string)intval($s['id_pointeuse']);
        if ($only && !in_array($pin, $only, true)) continue;      // filtre département
        $names[$pin] = trim($s['first_name'] . ' ' . $s['last_name']);
        $pinOf[(string)$s['id']] = $pin;
    }
    $a = rawurlencode($from . 'T00:00:00+01:00'); $b = rawurlencode($to . 'T23:59:59+01:00');
    $logs = sbAll('notifications?type=eq.attendance&created_at=gte.' . $a . '&created_at=lte.' . $b . '&select=student_id,created_at&order=created_at.asc');
    $t0 = rawurlencode($today . 'T00:00:00+01:00');
    $logsToday = sbAll('notifications?type=eq.attendance&created_at=gte.' . $t0 . '&select=student_id,created_at&order=created_at.asc');
} catch (Exception $ex) { $error = $ex->getMessage(); }

$lim1 = strtotime(START_AM) + TOLERANCE * 60;
$lim2 = strtotime(START_PM) + TOLERANCE * 60;

$days = array();
foreach ($logs as $l) {
    $p = punch($l, $pinOf);
    if (!$p) continue;
    list($id, $d, $t) = $p;
    if ($emp !== '' && $emp !== $id) continue;
    $days[$d][$id][] = $t;
}
krsort($days);

$rows = array(); $totalLate = 0;
foreach ($days as $d => $list) {
    foreach ($list as $id => $t) {
        sort($t);
        $a = analyse($t);
        $late1 = $a['in1'] && strtotime($a['in1']) > $lim1;
        $late2 = $a['in2'] && strtotime($a['in2']) > $lim2;
        $mins = 0;
        if ($a['in1'] && $a['out1']) $mins += (strtotime($a['out1']) - strtotime($a['in1'])) / 60;
        if ($a['in2'] && $a['out2']) $mins += (strtotime($a['out2']) - strtotime($a['in2'])) / 60;

        $tags = array();
        if ($late1) $tags[] = array('Retard matin', true);
        if ($late2) $tags[] = array('Retard après-midi', true);
        if ($d < $today) {
            if (!$a['in1'] && !$a['out1']) $tags[] = array('Absent matin', true);
            if (!$a['in2'] && !$a['out2']) $tags[] = array('Absent après-midi', true);
        }
        if (!$tags) $tags[] = array("À l'heure", false);
        if ($late1 || $late2) $totalLate++;

        $rows[] = array('date' => $d, 'id' => $id, 'name' => isset($names[$id]) ? $names[$id] : "ID $id",
            'in1' => $a['in1'], 'out1' => $a['out1'], 'in2' => $a['in2'], 'out2' => $a['out2'],
            'hours' => $mins > 0 ? sprintf('%dh%02d', floor($mins / 60), $mins % 60) : '—',
            'tags' => $tags, 'n' => count($t), 'all' => implode(' · ', $t));
    }
}

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pointage_' . $from . '_' . $to . '.csv"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    fputcsv($o, array('Date', 'ID', 'Nom', 'Entrée matin', 'Sortie midi', 'Entrée après-midi', 'Sortie soir', 'Durée', 'Statut', 'Tous les pointages'), ';');
    foreach ($rows as $r) {
        $st = array();
        foreach ($r['tags'] as $tg) $st[] = $tg[0];
        fputcsv($o, array($r['date'], $r['id'], $r['name'], $r['in1'], $r['out1'], $r['in2'], $r['out2'], $r['hours'], implode(' / ', $st), $r['all']), ';');
    }
    exit;
}

/* Présents / retards / absents aujourd'hui */
$todayPunch = array();
foreach ($logsToday as $l) {
    $p = punch($l, $pinOf);
    if (!$p || $p[1] !== $today) continue;
    $todayPunch[$p[0]][] = $p[2];
}
$present = $todayPunch; $lateToday = 0;
foreach ($todayPunch as $id => $t) {
    $a = analyse($t);
    if (($a['in1'] && strtotime($a['in1']) > $lim1) || ($a['in2'] && strtotime($a['in2']) > $lim2)) $lateToday++;
}
$absent = array_diff_key($names, $present);
$qs = array('from' => $from, 'to' => $to, 'emp' => $emp);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Suivi des présences</title>
<style>
:root{--bg:#eef1f4;--card:#fff;--ink:#14213d;--muted:#6b7688;--line:#d8dee6;--ok:#1f7a5a;--late:#c2410c}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 "Segoe UI",system-ui,sans-serif}
header{background:var(--ink);color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
header h1{margin:0;font-size:20px;font-weight:600}
header small{color:#aeb8cc}
main{max-width:1200px;margin:22px auto;padding:0 16px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px}
.stat{background:var(--card);border-left:5px solid var(--ink);padding:14px 18px;border-radius:4px}
.stat b{display:block;font-size:30px;line-height:1.1}
.stat span{color:var(--muted)}
.stat.ok{border-color:var(--ok)}.stat.late{border-color:var(--late)}.stat.abs{border-color:#94a3b8}
form.f{background:var(--card);padding:14px 18px;border-radius:4px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
label{display:flex;flex-direction:column;font-size:13px;color:var(--muted);gap:3px}
input,select{padding:7px 9px;border:1px solid var(--line);border-radius:4px;font:inherit;color:var(--ink)}
button,.btn{padding:8px 14px;border:0;border-radius:4px;background:var(--ink);color:#fff;font:inherit;cursor:pointer;text-decoration:none;display:inline-block}
.btn.alt{background:#fff;color:var(--ink);border:1px solid var(--line)}
.tools{display:flex;gap:8px;margin-left:auto;flex-wrap:wrap}
.panel{background:var(--card);border-radius:4px;overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 14px;text-align:left;border-bottom:1px solid var(--line);white-space:nowrap}
th{background:#f6f8fa;font-weight:600}
td[title]{cursor:help}
.tag{padding:2px 8px;border-radius:10px;font-size:12px;background:#dcf1e8;color:var(--ok);margin-right:4px;display:inline-block}
.tag.l{background:#fde7d9;color:var(--late)}
.err{background:#fde7d9;color:var(--late);padding:12px 16px;border-radius:4px;margin-bottom:14px}
.abs{margin:16px 0;color:var(--muted)}
.print-title{display:none}
@media print{
 body{background:#fff}header,form.f,.stats,.abs,.noprint{display:none}
 .print-title{display:block;margin:0 0 10px}
 th,td{padding:6px 8px;font-size:12px}
}
</style>
</head>
<body>
<header>
  <h1>Suivi des présences</h1>
  <small>Horaires <?php e(START_AM . '-' . END_AM . ' / ' . START_PM . '-' . END_PM); ?> · Mis à jour : <?php e(date('H:i:s')); ?> · Rafraîchissement auto chaque minute</small>
</header>
<main>
<?php if ($error) { ?><div class="err"><?php e($error); ?></div><?php } ?>

<section class="stats">
  <div class="stat ok"><b><?php echo count($present); ?></b><span>Présents aujourd'hui</span></div>
  <div class="stat late"><b><?php echo $lateToday; ?></b><span>En retard aujourd'hui</span></div>
  <div class="stat abs"><b><?php echo count($absent); ?></b><span>Absents aujourd'hui</span></div>
  <div class="stat"><b><?php echo count($names); ?></b><span>Élèves enregistrés</span></div>
</section>

<form class="f" method="get">
  <label>Du <input type="date" name="from" value="<?php e($from); ?>"></label>
  <label>Au <input type="date" name="to" value="<?php e($to); ?>"></label>
  <label>Élève
    <select name="emp"><option value="">Tous</option>
      <?php foreach ($names as $id => $n) { ?><option value="<?php e($id); ?>"<?php if ($emp === (string)$id) echo ' selected'; ?>><?php e($n); ?></option><?php } ?>
    </select>
  </label>
  <button type="submit">Filtrer</button>
  <div class="tools">
    <a class="btn alt" href="?<?php e(http_build_query(array_merge($qs, array('csv' => 1)))); ?>">Exporter CSV</a>
    <button type="button" onclick="window.print()">Imprimer</button>
  </div>
</form>

<?php if ($absent) { ?><p class="abs"><b>Absents aujourd'hui :</b> <?php e(implode(', ', $absent)); ?></p><?php } ?>

<h2 class="print-title">Rapport de présence du <?php e($from); ?> au <?php e($to); ?><?php if ($emp !== '') { echo ' — '; e(isset($names[$emp]) ? $names[$emp] : $emp); } ?></h2>
<div class="panel">
<table>
  <thead><tr><th>Date</th><th>Élève</th><th>Entrée matin</th><th>Sortie midi</th><th>Entrée ap-midi</th><th>Sortie soir</th><th>Durée</th><th>Pointages</th><th>Statut</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r) { ?>
    <tr>
      <td><?php e($r['date']); ?></td><td><?php e($r['name']); ?></td>
      <td><?php e($r['in1'] ? $r['in1'] : '—'); ?></td>
      <td><?php e($r['out1'] ? $r['out1'] : '—'); ?></td>
      <td><?php e($r['in2'] ? $r['in2'] : '—'); ?></td>
      <td><?php e($r['out2'] ? $r['out2'] : '—'); ?></td>
      <td><?php e($r['hours']); ?></td>
      <td title="<?php e($r['all']); ?>"><?php echo $r['n']; ?></td>
      <td><?php foreach ($r['tags'] as $tg) { ?><span class="tag<?php if ($tg[1]) echo ' l'; ?>"><?php e($tg[0]); ?></span><?php } ?></td>
    </tr>
  <?php } ?>
  <?php if (!$rows) { ?><tr><td colspan="9">Aucun pointage sur cette période. Élargis les dates.</td></tr><?php } ?>
  </tbody>
</table>
</div>
<p class="noprint" style="color:var(--muted)"><?php echo count($rows); ?> lignes · <?php echo $totalLate; ?> retards sur la période</p>
</main>
<script>
setInterval(function () {
  var t = document.activeElement ? document.activeElement.tagName : '';
  if (t === 'INPUT' || t === 'SELECT') return;
  location.reload();
}, 60000);
</script>
</body>
</html>
