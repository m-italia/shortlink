<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Prendi il codice dall'URL
$codice = trim($_GET['c'] ?? '');

if (empty($codice)) {
    // Nessun codice, vai al pannello admin
    header('Location: /shortlink/admin/dashboard.php');
    exit;
}

// Cerca il link nel database
$stmt = $pdo->prepare("
    SELECT * FROM links 
    WHERE codice = ? AND attivo = 1
");
$stmt->execute([$codice]);
$link = $stmt->fetch();

if (!$link) {
    // Link non trovato
    http_response_code(404);
    die('Link non trovato o non più attivo.');
}

// Controlla scadenza
if ($link['scade_il'] && strtotime($link['scade_il']) < time()) {
    http_response_code(410);
    die('Questo link è scaduto.');
}

// Leggi sorgente UTM se presente
$sorgente = null;
if (!empty($_GET['utm_source'])) {
    $sorgenteRaw = strtolower(trim($_GET['utm_source']));
    $sorgentiValide = ['instagram', 'facebook', 'linkedin', 'youtube', 'whatsapp', 'newsletter'];
    if (in_array($sorgenteRaw, $sorgentiValide)) {
        $sorgente = $sorgenteRaw;
    } else {
        // Accetta anche sorgenti non previste (es. custom) fino a 50 caratteri
        $sorgente = substr($sorgenteRaw, 0, 50);
    }
}

// Registra il click
$stmt = $pdo->prepare("
    INSERT INTO clicks (link_id, ip, device, browser, sorgente) 
    VALUES (?, ?, ?, ?, ?)
");
$stmt->execute([
    $link['id'],
    $_SERVER['REMOTE_ADDR'] ?? null,
    rilevaDevice($_SERVER['HTTP_USER_AGENT'] ?? ''),
    rilevaBrowser($_SERVER['HTTP_USER_AGENT'] ?? ''),
    $sorgente,
]);

// Redirect!
header('Location: ' . $link['url_destinazione'], true, 302);
exit;
