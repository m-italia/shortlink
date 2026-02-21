<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';

requireLogin();

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: dashboard.php');
    exit;
}

// Prendi il link per eliminare anche il file QR
$stmt = $pdo->prepare("SELECT * FROM links WHERE id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

if ($link) {
    // Elimina i file QR (png e svg se esistono)
    $qrPng = '../qrcodes/' . $link['codice'] . '.png';
    $qrSvg = '../qrcodes/' . $link['codice'] . '.svg';
    if (file_exists($qrPng)) unlink($qrPng);
    if (file_exists($qrSvg)) unlink($qrSvg);

    // Elimina dal database (i click vengono eliminati in cascade)
    $stmt = $pdo->prepare("DELETE FROM links WHERE id = ?");
    $stmt->execute([$id]);
}

header('Location: dashboard.php?eliminato=1');
exit;