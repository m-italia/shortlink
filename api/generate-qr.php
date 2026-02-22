<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';

requireLogin();

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$data      = $_POST['data'] ?? 'https://esempio.com';
$stile     = $_POST['stile'] ?? 'quadrati';
$coloreHex = $_POST['colore'] ?? '#000000';
$sfondoHex = $_POST['sfondo'] ?? '#ffffff';
$haLogo    = isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK;

function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [ hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)) ];
}

function svgToPng($svgPath, $size = 200) {
    $svgContent = file_get_contents($svgPath);
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setBackgroundColor(new ImagickPixel('transparent'));
            $im->setResolution(150, 150);
            $im->readImageBlob($svgContent);
            $im->setImageFormat('png32');
            $im->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1, true);
            $png = $im->getImageBlob();
            $im->destroy();
            return imagecreatefromstring($png);
        } catch (Exception $e) { return null; }
    }
    return null;
}

function caricaLogo($path, $ext) {
    if ($ext === 'png') return @imagecreatefrompng($path);
    if (in_array($ext, ['jpg','jpeg'])) return @imagecreatefromjpeg($path);
    if ($ext === 'gif') return @imagecreatefromgif($path);
    if ($ext === 'svg') return svgToPng($path);
    return null;
}

$coloreRgb = hexToRgb($coloreHex);
$sfondoRgb = hexToRgb($sfondoHex);

$options = new QROptions([
    'outputType'          => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'            => QRCode::ECC_H,
    'scale'               => 10,
    'imageBase64'         => false,
    'drawCircularModules' => $stile === 'pallini',
    'circleRadius'        => 0.45,
    'imageTransparent'    => false,
    'addLogoSpace'        => $haLogo,
    'logoSpaceWidth'      => $haLogo ? 13 : 0,
    'logoSpaceHeight'     => $haLogo ? 13 : 0,
]);

try {
    // Step 1: genera QR base
    $qr      = new QRCode($options);
    $imgData = $qr->render($data);

    // Step 2: applica colori personalizzati
    $im      = imagecreatefromstring($imgData);
    $w       = imagesx($im);
    $h       = imagesy($im);
    $output  = imagecreatetruecolor($w, $h);
    imageAlphaBlending($output, false);
    imageSaveAlpha($output, true);
    $fgColor = imagecolorallocate($output, $coloreRgb[0], $coloreRgb[1], $coloreRgb[2]);
    $bgColor = imagecolorallocate($output, $sfondoRgb[0], $sfondoRgb[1], $sfondoRgb[2]);
    imagefill($output, 0, 0, $bgColor);
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            $pixel = imagecolorat($im, $x, $y);
            if ((($pixel >> 16) & 0xFF) < 128) imagesetpixel($output, $x, $y, $fgColor);
        }
    }
    imagedestroy($im);

    // Step 3: inserisci logo sopra (se presente)
    if ($haLogo) {
        $logoTmp = $_FILES['logo_file']['tmp_name'];
        $ext     = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        $logo    = caricaLogo($logoTmp, $ext);

        if ($logo) {
            imageAlphaBlending($output, true);
            $logoW    = imagesx($logo); $logoH = imagesy($logo);
            $spazio   = intval($w * 0.22);
            $logoNewW = $spazio;
            $logoNewH = intval($spazio * $logoH / $logoW);
            $logoX    = intval(($w - $logoNewW) / 2);
            $logoY    = intval(($h - $logoNewH) / 2);
            imagecopyresampled($output, $logo, $logoX, $logoY, 0, 0, $logoNewW, $logoNewH, $logoW, $logoH);
            imagedestroy($logo);
        }
    }

    ob_start();
    imagepng($output);
    $imgData = ob_get_clean();
    imagedestroy($output);

    echo 'data:image/png;base64,' . base64_encode($imgData);

} catch (\Exception $e) {
    http_response_code(500);
    echo 'Errore: ' . $e->getMessage();
}
