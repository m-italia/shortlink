<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../vendor/autoload.php';

requireLogin();

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$data      = $_POST['data']   ?? 'https://esempio.com';
$stile     = $_POST['stile']  ?? 'quadrati';
$coloreHex = $_POST['colore'] ?? '#000000';
$sfondoHex = $_POST['sfondo'] ?? '#ffffff';
$haLogo    = isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK;

// ─── Helper: hex → rgb ───────────────────────────────────────────────────────
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [ hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)) ];
}

// ─── Helper: carica logo GD (per PNG) ───────────────────────────────────────
function svgToPng($svgContent, $size = 200) {
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

function caricaLogoGd($path, $ext) {
    if ($ext === 'png')                  return @imagecreatefrompng($path);
    if (in_array($ext, ['jpg','jpeg']))  return @imagecreatefromjpeg($path);
    if ($ext === 'gif')                  return @imagecreatefromgif($path);
    if ($ext === 'svg')                  return svgToPng(file_get_contents($path));
    return null;
}

// ─── Genera PNG per l'anteprima (via GD, identico a create.php) ─────────────
function generaQrPng($shortUrl, $stile, $coloreRgb, $sfondoRgb, $haLogo, $logoPath = null, $logoExt = null) {
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

    $qr      = new QRCode($options);
    $imgData = $qr->render($shortUrl);

    $im      = imagecreatefromstring($imgData);
    $w       = imagesx($im); $h = imagesy($im);
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

    if ($haLogo && $logoPath && file_exists($logoPath)) {
        $logo = caricaLogoGd($logoPath, $logoExt);
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

    ob_start(); imagepng($output); $result = ob_get_clean();
    imagedestroy($output);
    return $result;
}

// ─── Esecuzione ─────────────────────────────────────────────────────────────
try {
    $coloreRgb = hexToRgb($coloreHex);
    $sfondoRgb = hexToRgb($sfondoHex);

    $logoPath = null;
    $logoExt  = null;

    // Se c'è un logo, salvalo in un tmp dedicato e stabile
    if ($haLogo) {
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','gif','svg'])) {
            // Usa sys_get_temp_dir() per un path temporaneo pulito
            $logoPath = sys_get_temp_dir() . '/qr_logo_preview_' . session_id() . '.' . $ext;
            move_uploaded_file($_FILES['logo_file']['tmp_name'], $logoPath);
            $logoExt = $ext;
        } else {
            $haLogo = false;
        }
    }

    // L'anteprima usa sempre il PNG (veloce, non richiede Imagick per la preview)
    $imgData = generaQrPng($data, $stile, $coloreRgb, $sfondoRgb, $haLogo, $logoPath, $logoExt);

    // Pulizia tmp logo
    if ($logoPath && file_exists($logoPath)) {
        @unlink($logoPath);
    }

    echo 'data:image/png;base64,' . base64_encode($imgData);

} catch (\Exception $e) {
    http_response_code(500);
    echo 'Errore: ' . $e->getMessage();
}
