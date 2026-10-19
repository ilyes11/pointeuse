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
    echo "GET OPTION FROM: $sn\nStamp=9999\nOpStamp=9999\nErrorDelay=30\nDelay=10\n"
       . "TransTimes=00:00;14:00\nTransInterval=1\nTransFlag=TransData AttLog\nRealtime=1\nEncrypt=0\n";
    exit;
}

$table = isset($_GET['table']) ? $_GET['table'] : '';
if ($table !== 'ATTLOG') { echo 'OK'; exit; }

$cache = array(); $failed = false; $count = 0;
foreach (preg_split('/\r?\n/', file_get_contents('php://input')) as $line) {
    if (trim($line) === '') continue;
    $f = explode("\t", trim($line));
    if (count($f) < 2 || !ctype_digit($f[0])) {
        error_log('ADMS PUNCH ignore (ligne invalide): ' . trim($line));
        continue;
    }
    // ID recu de la pointeuse, logue meme s'il n'existe pas dans la base
    error_log('ADMS PUNCH: ID=' . $f[0] . ' time=' . $f[1] . ' SN=' . $sn);
    $ts = strtotime($f[1]);
    if ($ts === false || $ts < time() - 86400) {               // ignore les pointages de plus de 24 h
        error_log('ADMS PUNCH ignore (date invalide ou > 24h): ID=' . $f[0] . ' time=' . $f[1]);
        continue;
    }
    try {
        $pin = (int)$f[0];
        if (!isset($cache[$pin])) {
            $r = sb('GET', 'students?id_pointeuse=eq.' . $pin . '&select=id,first_name,last_name,parent_id&limit=1');
            $cache[$pin] = (is_array($r) && count($r)) ? $r[0] : false;
        }
        $s = $cache[$pin];
        if (!$s) {
            error_log('ADMS PUNCH ID=' . $pin . ' introuvable dans la base (students.id_pointeuse)');
            continue;
        }
        sb('POST', 'notifications', array(
            'id'         => 'notif_' . substr(md5(uniqid(mt_rand(), true)), 0, 12),
            'parent_id'  => $s['parent_id'],
            'student_id' => $s['id'],
            'type'       => 'attendance',
            'title'      => 'تسجيل الحضور',
            'body'       => 'ابنك/ابنتك ' . $s['first_name'] . ' ' . $s['last_name'] . ' قام/ت بتسجيل الدخول بنجاح.',
            'is_read'    => false,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ), 'return=minimal');
        $count++;
    } catch (Exception $e) {
        error_log('ADMS: ' . $e->getMessage());
        $failed = true;
    }
}
if ($failed) { http_response_code(500); echo 'ERROR'; exit; }   // la pointeuse reessaiera
echo 'OK: ' . $count;
