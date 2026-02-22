<?php

// Genera un codice casuale univoco per lo short link
function generaCodice($lunghezza = 6) {
    $caratteri = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $codice = '';
    for ($i = 0; $i < $lunghezza; $i++) {
        $codice .= $caratteri[random_int(0, strlen($caratteri) - 1)];
    }
    return $codice;
}

// Genera un codice univoco controllando che non esista già nel DB
function generaCodiceUnico($pdo) {
    do {
        $codice = generaCodice();
        $stmt = $pdo->prepare("SELECT id FROM links WHERE codice = ?");
        $stmt->execute([$codice]);
    } while ($stmt->fetch());
    return $codice;
}

// Rileva il tipo di device dall'user agent
function rilevaDevice($userAgent) {
    if (preg_match('/mobile/i', $userAgent)) return 'Mobile';
    if (preg_match('/tablet|ipad/i', $userAgent)) return 'Tablet';
    return 'Desktop';
}

// Rileva il browser dall'user agent
function rilevaBrowser($userAgent) {
    if (preg_match('/chrome/i', $userAgent)) return 'Chrome';
    if (preg_match('/firefox/i', $userAgent)) return 'Firefox';
    if (preg_match('/safari/i', $userAgent)) return 'Safari';
    if (preg_match('/edge/i', $userAgent)) return 'Edge';
    return 'Altro';
}

// Valida un URL
function validaURL($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// Formatta una data in italiano
function formattaData($data) {
    if (!$data) return '—';
    return date('d/m/Y H:i', strtotime($data));
}
