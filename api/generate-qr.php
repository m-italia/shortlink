<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';

requireLogin();

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$data   = $_POST['data'] ?? 'https://esempio.com';
$colore = $_POST['colore'] ?? '#000000';
$sfondo = $_POST['sfondo'] ?? '#ffffff';
$stile  = $_POST['stile'] ?? 'quadrati';

$options = new QROptions([
    'outputType'          => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'            => QRCode::ECC_H,
    'scale'               => 10,
    'imageBase64'         => false,
    'drawCircularModules' => $stile === 'pallini',
    'circleRadius'        => 0.45,
    'imageTransparent'    => false,
    'addLogoSpace'        => true,
    'logoSpaceWidth'      => 13,
    'logoSpaceHeight'     => 13,
]);

try {
    $qr = new QRCode($options);
    $imgData = $qr->render($data);

    // Inserisci logo se caricato dall'utente
    $logoPath = null;

    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $logoPath = $_FILES['logo_file']['tmp_name'];
    }

    if ($logoPath && file_exists($logoPath)) {
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        $logo = null;
        if ($ext === 'png') {
            $logo = imagecreatefrompng($logoPath);
        } elseif (in_array($ext, ['jpg', 'jpeg'])) {
            $logo = imagecreatefromjpeg($logoPath);
        } elseif ($ext === 'gif') {
            $logo = imagecreatefromgif($logoPath);
        }

        if ($logo) {
            $qrIm     = imagecreatefromstring($imgData);
            $qrW      = imagesx($qrIm);
            $qrH      = imagesy($qrIm);
            $logoW    = imagesx($logo);
            $logoH    = imagesy($logo);
            $spazio   = intval($qrW * 0.22);
            $logoNewW = $spazio;
            $logoNewH = intval($spazio * $logoH / $logoW);
            $logoX    = intval(($qrW - $logoNewW) / 2);
            $logoY    = intval(($qrH - $logoNewH) / 2);
            imagecopyresampled($qrIm, $logo, $logoX, $logoY, 0, 0, $logoNewW, $logoNewH, $logoW, $logoH);
            ob_start();
            imagepng($qrIm);
            $imgData = ob_get_clean();
            imagedestroy($logo);
            imagedestroy($qrIm);
        }
    }

    echo 'data:image/png;base64,' . base64_encode($imgData);

} catch (\Exception $e) {
    http_response_code(500);
    echo 'Errore: ' . $e->getMessage();
}
