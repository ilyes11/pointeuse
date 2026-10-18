<?php
date_default_timezone_set('Africa/Tunis');

function cfg($k) { $v = getenv($k); return $v === false ? '' : $v; }

/* Requete REST Supabase. $prefer = valeur du header Prefer (optionnel). */
function sb($method, $path, $body = null, $prefer = null) {
    $url = rtrim(cfg('SB_URL'), '/') . '/rest/v1/' . $path;
    $key = cfg('SB_KEY');
    $h = array('apikey: ' . $key, 'Authorization: Bearer ' . $key, 'Content-Type: application/json');
    if ($prefer) $h[] = 'Prefer: ' . $prefer;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ));
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new Exception('curl: ' . $err);
    if ($code < 200 || $code >= 300) throw new Exception('Supabase HTTP ' . $code . ' : ' . substr($resp, 0, 200));
    return json_decode($resp, true);
}
