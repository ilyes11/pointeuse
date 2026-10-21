<?php
/* Employés suivis (ne sont PAS dans la base Supabase) : leurs pointages sont écrits dans un
   fichier texte (une ligne par pointage). Rien n'est créé dans Supabase. */

// IDs de la pointeuse des employés
define('EMP_IDS', '450,451,452,453,454,455,456,457,1,458,459,460,461,462,463');

// Noms affichés (optionnel). Mets le nom à côté de l'ID, sinon la page affiche "ID 450".
$EMP_NAMES = array(
    // '450' => 'Nom Prénom',
    // '451' => 'Nom Prénom',
);

// Jours travaillés : 1 = lundi ... 6 = samedi, 7 = dimanche
$WORK_DAYS = array(1, 2, 3, 4, 5, 6);

function empIdList() {
    return array_filter(array_map('trim', explode(',', EMP_IDS)), 'strlen');
}

/* Fichier journal (hors du dossier web). Format, séparé par tabulation :
   heure_reception(Tunis) <TAB> ID <TAB> heure_envoyée_par_la_pointeuse */
function empLogPath() {
    $p = getenv('EMP_LOG_FILE');
    return ($p !== false && $p !== '') ? $p : '/var/www/data/employee_punches.txt';
}

/* Ajoute les pointages (array de array('pin'=>, 'device_time'=>)). Ignore ceux déjà présents
   (la pointeuse peut renvoyer le même pointage). Retourne le nombre de lignes ajoutées. */
function empLogAppend($punches) {
    $file = empLogPath();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new Exception('dossier impossible a creer: ' . $dir);
    $fp = fopen($file, 'c+');
    if (!$fp) throw new Exception('fichier impossible a ouvrir: ' . $file);
    flock($fp, LOCK_EX);
    $size = fstat($fp)['size'];
    $tail = '';
    if ($size > 0) {                                   // on lit la fin du fichier pour detecter les doublons
        $len = min($size, 200000);
        fseek($fp, -$len, SEEK_END);
        $tail = fread($fp, $len);
    }
    $existing = array();
    foreach (explode("\n", $tail) as $l) {
        $c = explode("\t", $l);
        if (count($c) >= 3) $existing[$c[1] . '|' . $c[2]] = 1;
    }
    $out = ''; $n = 0;
    foreach ($punches as $p) {
        $k = $p['pin'] . '|' . $p['device_time'];
        if (isset($existing[$k])) continue;
        $existing[$k] = 1;
        $out .= date('Y-m-d H:i:s') . "\t" . $p['pin'] . "\t" . $p['device_time'] . "\n";
        $n++;
    }
    if ($out !== '') { fseek($fp, 0, SEEK_END); fwrite($fp, $out); fflush($fp); }
    flock($fp, LOCK_UN); fclose($fp);
    return $n;
}

/* Lit le journal : array de array('pin'=>'450', 'date'=>'Y-m-d', 'time'=>'H:i') entre deux dates. */
function empLogRead($from, $to) {
    $file = empLogPath();
    $res = array();
    if (!is_file($file)) return $res;
    $fp = fopen($file, 'r');
    if (!$fp) return $res;
    while (($l = fgets($fp)) !== false) {
        $c = explode("\t", rtrim($l, "\r\n"));
        if (count($c) < 2 || strlen($c[0]) < 16) continue;
        $d = substr($c[0], 0, 10);
        if ($d < $from || $d > $to) continue;
        $res[] = array('pin' => (string)intval($c[1]), 'date' => $d, 'time' => substr($c[0], 11, 5));
    }
    fclose($fp);
    return $res;
}
