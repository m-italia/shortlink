<?php
require_once 'vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$options = new QROptions([
    'outputType'          => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'            => QRCode::ECC_H,
    'scale'               => 10,
    'imageBase64'         => false,
    'drawCircularModules' => true,
    'circleRadius'        => 0.45,
    'imageTransparent'    => false,
    'addLogoSpace'        => true,
    'logoSpaceWidth'      => 13,
    'logoSpaceHeight'     => 13,
]);

$qr = new QRCode($options);
$imgData = $qr->render('https://esempio.com');

// Inserisci logo al centro
$logoPath = 'assets/logo-nero.png';
if (file_exists($logoPath)) {
    $qrIm  = imagecreatefromstring($imgData);
    $logo  = imagecreatefrompng($logoPath);
    $qrW   = imagesx($qrIm);
    $qrH   = imagesy($qrIm);
    $logoW = imagesx($logo);
    $logoH = imagesy($logo);
    $spazio   = intval($qrW * 0.22);
    $logoNewW = $spazio;
    $logoNewH = intval($spazio * $logoH / $logoW);
    $logoX = intval(($qrW - $logoNewW) / 2);
    $logoY = intval(($qrH - $logoNewH) / 2);
    imagecopyresampled($qrIm, $logo, $logoX, $logoY, 0, 0, $logoNewW, $logoNewH, $logoW, $logoH);
    ob_start();
    imagepng($qrIm);
    $imgData = ob_get_clean();
}

header('Content-Type: image/png');
echo $imgData;