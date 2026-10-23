<?php
/* Endpoint ADMS : la pointeuse ZKTeco envoie ici ses pointages.
   Chaque pointage => une notification au parent (table notifications existante). */
require __DIR__ . '/lib.php';
header('Content-Type: text/plain');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$sn = isset($_GET['SN']) ? $_GET['SN'] : '';

// Log de CHAQUE requete de la pointeuse (visible dans les logs Render)
error_log('ADMS REQ: ' . $_SERVER['REQUEST_METHOD'] . ' ' . $path . ' SN=' . $sn
    . ' table=' . (isset($_GET['table']) ? $_GET['table'] : '-')
    . ' ip=' . (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']));

if (strpos($path, 'getrequest') !== false) { echo 'OK'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {            // handshake / options
    echo "GET OPTION FROM: $sn\nStamp=9999\nOpStamp=9999\nErrorDelay=30\nDelay=10\nTimeZone=1\n"
       . "TransTimes=00:00;14:00\nTransInterval=1\nTransFlag=TransData AttLog\nRealtime=1\nEncrypt=0\n";
    exit;
}

$table = isset($_GET['table']) ? $_GET['table'] : '';
if ($table !== 'ATTLOG') { echo 'OK'; exit; }

/* ---- Logique identique a pointe_elev.php (notifyNew), adaptee au mode "push" ---- */
define('MAX_DELAY_MIN', 60);   // pointage plus vieux que 60 min : notification annulee
define('SEEN_FILE', sys_get_temp_dir() . '/adms_seen.json');
define('LAST_FILE', sys_get_temp_dir() . '/adms_last.json');   // dernier envoi par eleve (anti-doublon entre requetes)
define('NOTIF_COOLDOWN_SEC', 600);   // un eleve : au plus 1 notification toutes les 10 min
/* Plages de sortie (heure de Tunis, heure du SERVEUR : l'horloge de la pointeuse est decalee).
   Hors de ces plages, un pointage d'eleve n'envoie aucune notification. A ajuster selon l'ecole. */
$LEAVE_WINDOWS = array(array('11:00', '19:00'));
function inLeaveWindow($windows) {
    $hm = date('H:i');   // lib.php fixe Africa/Tunis
    foreach ($windows as $w) { if ($hm >= $w[0] && $hm <= $w[1]) return true; }
    return false;
}

$now = time();
$fp = fopen(SEEN_FILE . '.lock', 'c');
if ($fp) flock($fp, LOCK_EX);                                   // evite les doublons si la pointeuse reessaie en parallele
$seen = array();
if (file_exists(SEEN_FILE)) { $j = json_decode(file_get_contents(SEEN_FILE), true); if (is_array($j)) $seen = $j; }

$todo = array();
foreach (preg_split('/\r?\n/', file_get_contents('php://input')) as $line) {
    if (trim($line) === '') continue;
    $f = explode("\t", trim($line));
    if (count($f) < 2 || !ctype_digit($f[0])) {
        error_log('ADMS PUNCH ignore (ligne invalide): ' . trim($line));
        continue;
    }
    error_log('ADMS PUNCH: ID=' . $f[0] . ' time=' . $f[1] . ' SN=' . $sn);   // ID recu, meme absent de la base
    $ts = strtotime($f[1]);
    if ($ts === false || $ts < $now - 86400) continue;           // ignore > 24 h
    $k =$f[0] . '|' . $f[1];
    if (isset($seen[$k])) continue;
    if (!inLeaveWindow($LEAVE_WINDOWS)) {                        // hors plage de sortie : pas de notification
        $seen[$k] = 1;
        error_log('ADMS HORS PLAGE ID ' . $f[0] . ' ' . $f[1] . ' (heure serveur ' . date('H:i') . ') : aucune notification');
        continue;
    }
    if ($now - $ts > MAX_DELAY_MIN * 60) {                       // pointage trop ancien : on n'envoie rien
        $seen[$k] = 1;
        error_log('ADMS ANNULE (pointage vieux de ' . round(($now - $ts) / 60) . ' min) ID ' . $f[0] . ' ' . $f[1]);
        continue;
    }
    $todo[$k] = array('id' => $f[0], 'timestamp' => $f[1]);
}

$failed = false; $count = 0;
if ($todo) {
    try {
        /* Par paquets de 100 : 1 requete pour trouver les eleves + 1 requete pour inserer toutes les notifications.
           Un paquet n'est marque "vu" qu'une fois insere => pas de doublons, pas de perte. */
        $last = array();
        if (file_exists(LAST_FILE)) { $j = json_decode(file_get_contents(LAST_FILE), true); if (is_array($j)) $last = $j; }
        foreach (array_chunk(array_keys($todo), 100) as $chunk) {
            $pins = array();
            foreach ($chunk as $k) { $p = intval($todo[$k]['id']); if ($p > 0) $pins[$p] = 1; }
            $stu = array();
            if ($pins) {
                $rows = sb('GET', 'students?id_pointeuse=in.(' . implode(',', array_keys($pins)) . ')&select=id,id_pointeuse,first_name,last_name,parent_id');
                if (is_array($rows)) foreach ($rows as $r) $stu[intval($r['id_pointeuse'])] = $r;
            }
            $batch = array();
            foreach ($chunk as $k) {
                $a = $todo[$k];
                $p = intval($a['id']);
                if (!isset($stu[$p])) {
                    error_log('ADMS PUNCH ID=' . $p . ' introuvable dans la base (students.id_pointeuse)');
                    continue;
                }
                if (isset($last[$p]) && $now - $last[$p] < NOTIF_COOLDOWN_SEC) {   // deja notifie il y a < 10 min (meme entre requetes)
                    error_log('ADMS DOUBLON ID ' . $p . ' ignore (derniere notif il y a ' . ($now - $last[$p]) . ' s)');
                    continue;
                }
                $last[$p] = $now;
                $r = $stu[$p];
                // Eleve : un pointage = il quitte l'etablissement -> on previent le parent
                $batch[] = array(
                    'id'         => 'notif_' . substr(md5(uniqid(mt_rand(), true)), 0, 12),
                    'parent_id'  => $r['parent_id'],
                    'student_id' => $r['id'],
                    'type'       => 'attendance',
                    'title'      => 'مغادرة التلميذ',
                    'body'       => 'التلميذ(ة) ' . $r['first_name'] . ' ' . $r['last_name'] . ' بصدد المغادرة، الرجاء الالتحاق به.',
                    'is_read'    => false,
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                );
            }
            if ($batch) {
                sb('POST', 'notifications', $batch, 'return=minimal');
                file_put_contents(LAST_FILE, json_encode($last));    // enregistre seulement apres insertion reussie
            }
            foreach ($chunk as $k) $seen[$k] = 1;
            $count += count($batch);
            if ($batch) error_log('ADMS OK ' . count($batch) . ' notification(s) envoyee(s)');
        }
    } catch (Exception $e) {
        error_log('ADMS ERREUR: ' . $e->getMessage());
        $failed = true;                                           // les paquets non inseres ne sont pas marques "vus"
    }
}

foreach ($seen as $k => $v) {                                     // nettoyage > 48 h
    $p = strpos($k, '|');
    if ($p !== false && strtotime(substr($k, $p + 1)) < $now - 172800) unset($seen[$k]);
}
file_put_contents(SEEN_FILE, json_encode($seen));
if ($fp) { flock($fp, LOCK_UN); fclose($fp); }

if ($failed) { http_response_code(500); echo 'ERROR'; exit; }     // la pointeuse reessaiera
echo 'OK: ' . $count;
