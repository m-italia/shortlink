<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

requireLogin();

$errore   = '';
$successo = '';

// ─── Helper: hex → rgb array ────────────────────────────────────────────────
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [ hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)) ];
}

// ─── Helper: carica immagine GD da path + ext ────────────────────────────────
function svgToPng($svgPath, $size = 200) {
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setBackgroundColor(new ImagickPixel('transparent'));
            $im->setResolution(150, 150);
            $im->readImageBlob(file_get_contents($svgPath));
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
    if ($ext === 'png')              return @imagecreatefrompng($path);
    if (in_array($ext, ['jpg','jpeg'])) return @imagecreatefromjpeg($path);
    if ($ext === 'gif')              return @imagecreatefromgif($path);
    if ($ext === 'svg')              return svgToPng($path);
    return null;
}

// ─── Genera PNG ─────────────────────────────────────────────────────────────
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
        $logo = caricaLogo($logoPath, $logoExt);
        if ($logo) {
            imageAlphaBlending($output, true);
            $logoW    = imagesx($logo); $logoH = imagesy($logo);
            $spazio   = intval($w * 0.22);
            $logoNewW = $spazio; $logoNewH = intval($spazio * $logoH / $logoW);
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

// ─── Genera SVG ─────────────────────────────────────────────────────────────
function generaQrSvg($shortUrl, $stile, $coloreHex, $sfondoHex, $haLogo, $logoPath = null, $logoExt = null) {
    $options = new QROptions([
        'outputType'          => QRCode::OUTPUT_MARKUP_SVG,
        'eccLevel'            => QRCode::ECC_H,
        'scale'               => 10,
        'imageBase64'         => false,
        'drawCircularModules' => $stile === 'pallini',
        'circleRadius'        => 0.45,
        'svgDefs'             => '',
    ]);
    $qr      = new QRCode($options);
    $svgData = $qr->render($shortUrl);

    $xml = simplexml_load_string($svgData);
    $viewBox  = (string)$xml['viewBox'];
    $vbParts  = explode(' ', $viewBox);
    $svgW     = isset($vbParts[2]) ? (float)$vbParts[2] : 290;
    $svgH     = isset($vbParts[3]) ? (float)$vbParts[3] : 290;

    $rootChildren = $xml->children();
    foreach ($rootChildren as $child) {
        if ($child->getName() === 'rect') {
            $child['fill'] = $sfondoHex;
            break;
        }
    }

    $svgData = $xml->asXML();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($svgData);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');
    $moduli = $xpath->query('//*[@fill="#000" or @fill="currentColor" or @fill="#000000"]');
    foreach ($moduli as $nodo) {
        $nodo->setAttribute('fill', $coloreHex);
    }

    if ($haLogo && $logoPath && file_exists($logoPath)) {
        $logoAreaW = round($svgW * 0.22);
        $logoAreaH = round($svgH * 0.22);
        $logoRatio = 1.0;
        if ($logoExt === 'svg') {
            $logoXml = @simplexml_load_file($logoPath);
            if ($logoXml) {
                $lVB = (string)$logoXml['viewBox'];
                if ($lVB) {
                    $lp = explode(' ', preg_replace('/,/', ' ', $lVB));
                    if (count($lp) >= 4 && (float)$lp[3] > 0) $logoRatio = (float)$lp[2] / (float)$lp[3];
                } elseif ((float)$logoXml['width'] > 0 && (float)$logoXml['height'] > 0) {
                    $logoRatio = (float)$logoXml['width'] / (float)$logoXml['height'];
                }
            }
        } else {
            $info = @getimagesize($logoPath);
            if ($info && $info[1] > 0) $logoRatio = $info[0] / $info[1];
        }

        if ($logoRatio >= 1) { $lW = $logoAreaW; $lH = round($logoAreaW / $logoRatio); }
        else { $lH = $logoAreaH; $lW = round($logoAreaH * $logoRatio); }

        $lX = round(($svgW - $lW) / 2);
        $lY = round(($svgH - $lH) / 2);
        $pad  = max(4, round($svgW * 0.015));
        $rectX = $lX - $pad; $rectY = $lY - $pad;
        $rectW = $lW + $pad * 2; $rectH = $lH + $pad * 2;

        $logoData    = file_get_contents($logoPath);
        $logoB64     = base64_encode($logoData);
        $mimeTypes   = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml'];
        $mime        = $mimeTypes[$logoExt] ?? 'image/png';
        $logoDataUri = "data:{$mime};base64,{$logoB64}";

        $svgRoot = $dom->documentElement;
        $rect = $dom->createElementNS('http://www.w3.org/2000/svg', 'rect');
        $rect->setAttribute('x', $rectX); $rect->setAttribute('y', $rectY);
        $rect->setAttribute('width', $rectW); $rect->setAttribute('height', $rectH);
        $rect->setAttribute('fill', $sfondoHex);
        $svgRoot->appendChild($rect);

        $img = $dom->createElementNS('http://www.w3.org/2000/svg', 'image');
        $img->setAttribute('x', $lX); $img->setAttribute('y', $lY);
        $img->setAttribute('width', $lW); $img->setAttribute('height', $lH);
        $img->setAttribute('preserveAspectRatio', 'xMidYMid meet');
        $img->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', $logoDataUri);
        $img->setAttribute('href', $logoDataUri);
        $svgRoot->appendChild($img);
    }

    return $dom->saveXML();
}

// ─── Canali disponibili ──────────────────────────────────────────────────────
$canaliDisponibili = [
    'instagram'  => 'Instagram',
    'facebook'   => 'Facebook',
    'linkedin'   => 'LinkedIn',
    'youtube'    => 'YouTube',
    'whatsapp'   => 'WhatsApp',
    'newsletter' => 'Newsletter',
];

// ─── Gestione POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url        = trim($_POST['url'] ?? '');
    $titolo     = trim($_POST['titolo'] ?? '');
    $codice     = trim($_POST['codice'] ?? '');
    $scade_il   = $_POST['scade_il'] ?? null;
    $stile      = $_POST['stile'] ?? 'quadrati';
    $coloreHex  = $_POST['colore'] ?? '#000000';
    $sfondoHex  = $_POST['sfondo'] ?? '#ffffff';
    $logoUpload = $_FILES['logo'] ?? null;

    // Canali UTM selezionati
    $canaliSelezionati = [];
    foreach (array_keys($canaliDisponibili) as $canale) {
        $canaliSelezionati[$canale] = isset($_POST['canale_' . $canale]) ? 1 : 0;
    }

    if (!validaURL($url)) {
        $errore = 'URL non valido. Assicurati di includere http:// o https://';
    } else {
        if (empty($codice)) {
            $codice = generaCodiceUnico($pdo);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM links WHERE codice = ?");
            $stmt->execute([$codice]);
            if ($stmt->fetch()) $errore = 'Questo codice è già in uso. Scegline un altro.';
        }

        if (!$errore) {
            $shortUrl  = 'http://localhost:8888/shortlink/' . $codice;
            $qrPngPath = '../qrcodes/' . $codice . '.png';
            $qrSvgPath = '../qrcodes/' . $codice . '.svg';
            $logoPath  = null; $logoExt = null; $haLogo = false;

            if ($logoUpload && $logoUpload['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($logoUpload['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png','jpg','jpeg','gif','svg'])) {
                    $logoPath = '../qrcodes/logo_' . $codice . '.' . $ext;
                    $logoExt  = $ext;
                    move_uploaded_file($logoUpload['tmp_name'], $logoPath);
                    $haLogo = true;
                }
            }

            try {
                $coloreRgb = hexToRgb($coloreHex);
                $sfondoRgb = hexToRgb($sfondoHex);
                $imgData = generaQrPng($shortUrl, $stile, $coloreRgb, $sfondoRgb, $haLogo, $logoPath, $logoExt);
                file_put_contents($qrPngPath, $imgData);
                $svgData = generaQrSvg($shortUrl, $stile, $coloreHex, $sfondoHex, $haLogo, $logoPath, $logoExt);
                file_put_contents($qrSvgPath, $svgData);
            } catch (\Exception $e) {
                $errore = 'Errore generazione QR: ' . $e->getMessage();
            }

            if (!$errore) {
                $stmt = $pdo->prepare("
                    INSERT INTO links 
                    (codice, url_destinazione, titolo, qr_path, scade_il,
                     canale_instagram, canale_facebook, canale_linkedin,
                     canale_youtube, canale_whatsapp, canale_newsletter)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $codice, $url, $titolo ?: null,
                    'qrcodes/' . $codice . '.png',
                    $scade_il ?: null,
                    $canaliSelezionati['instagram'],
                    $canaliSelezionati['facebook'],
                    $canaliSelezionati['linkedin'],
                    $canaliSelezionati['youtube'],
                    $canaliSelezionati['whatsapp'],
                    $canaliSelezionati['newsletter'],
                ]);
                $successo = $codice;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuovo Link — ShortLink</title>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Titillium Web', sans-serif; background: #f4f4f4; min-height: 100vh; }
        header { background: #000; padding: 0 40px; display: flex; align-items: center; justify-content: space-between; height: 64px; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 10px rgba(0,0,0,0.3); }
        .header-logo a { display: block; line-height: 0; }
        .header-logo img { height: 32px; display: block; }
        header a { color: #fff; text-decoration: none; font-size: 14px; font-weight: 600; padding: 8px 16px; border-radius: 6px; transition: background 0.2s; }
        header a:hover { background: #222; }
        .container { padding: 32px 40px; max-width: 1100px; margin: 0 auto; }
        .page-title { font-size: 24px; font-weight: 700; color: #000; margin-bottom: 24px; }
        .page-title span { color: #d20a10; }
        .layout { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 28px; margin-bottom: 20px; }
        .card h2 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #000; border-left: 3px solid #d20a10; padding-left: 10px; margin-bottom: 24px; }
        label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        input[type="text"], input[type="url"], input[type="datetime-local"], input[type="file"] { width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 14px; font-family: 'Titillium Web', sans-serif; margin-bottom: 18px; outline: none; transition: border 0.2s; background: #fafafa; }
        input:focus { border-color: #d20a10; background: #fff; }
        .hint { font-size: 11px; color: #aaa; font-weight: 400; margin-left: 6px; text-transform: none; }
        .sezione-label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        .stile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px; }
        .stile-opzione { border: 2px solid #e0e0e0; border-radius: 8px; padding: 12px 8px; text-align: center; cursor: pointer; transition: all 0.2s; position: relative; }
        .stile-opzione:hover { border-color: #d20a10; }
        .stile-opzione.selected { border-color: #d20a10; background: #fff5f5; }
        .stile-opzione svg { display: block; margin: 0 auto 6px; }
        .stile-opzione span { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #555; }
        .stile-opzione input[type="radio"] { position: absolute; opacity: 0; }
        .colori-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px; }
        .colore-box { display: flex; flex-direction: column; gap: 6px; }
        input[type="color"] { width: 100%; height: 42px; border: 1px solid #e0e0e0; border-radius: 6px; padding: 2px; cursor: pointer; background: #fafafa; margin-bottom: 0; }
        .anteprima-box { text-align: center; padding: 20px; background: #f9f9f9; border-radius: 8px; margin-bottom: 20px; min-height: 220px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .anteprima-box img { width: 180px; height: 180px; border-radius: 4px; }
        .anteprima-box .placeholder { color: #ccc; font-size: 13px; font-weight: 600; line-height: 1.8; }
        .spinner { width: 32px; height: 32px; border: 3px solid #f0f0f0; border-top-color: #d20a10; border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn { width: 100%; padding: 14px; background: #d20a10; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 700; font-family: 'Titillium Web', sans-serif; cursor: pointer; letter-spacing: 0.5px; transition: background 0.2s; text-align: center; display: block; text-decoration: none; }
        .btn:hover { background: #b00008; }
        .btn-secondary { background: #f0f0f0; color: #333; margin-top: 10px; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-anteprima { width: 100%; padding: 10px; background: #000; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; font-family: 'Titillium Web', sans-serif; cursor: pointer; letter-spacing: 0.5px; transition: background 0.2s; margin-bottom: 16px; }
        .btn-anteprima:hover { background: #333; }
        .errore { background: #fee2e2; color: #dc2626; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }

        /* Canali UTM */
        .canali-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px; }
        .canale-check { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .canale-check:hover { border-color: #d20a10; background: #fff5f5; }
        .canale-check.checked { border-color: #d20a10; background: #fff5f5; }
        .canale-check input[type="checkbox"] { width: 16px; height: 16px; accent-color: #d20a10; margin: 0; cursor: pointer; flex-shrink: 0; }
        .canale-dot { width: 8px; height: 8px; border-radius: 50%; background: #d20a10; flex-shrink: 0; }
        .canale-check .canale-nome { font-size: 13px; font-weight: 700; color: #333; text-transform: none; letter-spacing: 0; }
        .utm-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #d20a10; margin-right: 8px; flex-shrink: 0; }

        /* Schermata successo */
        .successo-box { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 28px; text-align: center; margin-bottom: 24px; }
        .successo-box h3 { font-size: 20px; font-weight: 700; color: #000; margin-bottom: 8px; }
        .successo-box .short-url { font-size: 18px; font-weight: 700; color: #d20a10; word-break: break-all; margin-bottom: 20px; display: block; }
        .successo-box img { width: 200px; height: 200px; border: 1px solid #eee; border-radius: 8px; padding: 8px; margin-bottom: 16px; }
        .download-links { display: flex; justify-content: center; gap: 16px; margin-bottom: 20px; }
        .download-links a { color: #d20a10; font-size: 14px; font-weight: 700; text-decoration: none; }
        .download-links a:hover { text-decoration: underline; }

        /* Link UTM pronti */
        .utm-section { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 28px; margin-bottom: 24px; text-align: left; }
        .utm-section h3 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #000; border-left: 3px solid #d20a10; padding-left: 10px; margin-bottom: 20px; }
        .utm-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; padding: 12px 16px; background: #f9f9f9; border-radius: 8px; border: 1px solid #eee; }
        .utm-row .utm-label { font-size: 13px; font-weight: 700; color: #333; width: 100px; flex-shrink: 0; }
        .utm-row .utm-url { font-size: 12px; color: #666; flex: 1; word-break: break-all; font-family: monospace; }
        .btn-copia { padding: 6px 14px; background: #000; color: #fff; border: none; border-radius: 5px; font-size: 12px; font-weight: 700; font-family: 'Titillium Web', sans-serif; cursor: pointer; letter-spacing: 0.5px; transition: background 0.2s; flex-shrink: 0; }
        .btn-copia:hover { background: #333; }
        .btn-copia.copiato { background: #16a34a; }

        /* Logo upload */
        .logo-upload-area { position: relative; }
        .logo-preview-box { display: none; align-items: center; gap: 12px; padding: 10px 14px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 18px; }
        .logo-preview-box img { width: 48px; height: 48px; object-fit: contain; border-radius: 4px; }
        .logo-preview-box .logo-nome { font-size: 13px; color: #333; font-weight: 600; flex: 1; }
        .logo-preview-box .btn-rimuovi { background: none; border: none; color: #d20a10; font-size: 13px; font-weight: 700; cursor: pointer; padding: 4px 8px; border-radius: 4px; transition: background 0.2s; font-family: 'Titillium Web', sans-serif; }
        .logo-preview-box .btn-rimuovi:hover { background: #fee2e2; }
    </style>
</head>
<body>
    <header>
        <div class="header-logo"><a href="dashboard.php"><img src="../assets/logo.svg" alt="Logo"></a></div>
        <a href="dashboard.php">← Dashboard</a>
    </header>

    <div class="container">
        <div class="page-title">Nuovo <span>link</span></div>

        <?php if ($errore): ?><div class="errore"><?= htmlspecialchars($errore) ?></div><?php endif; ?>

        <?php if ($successo):
            $baseUrl = 'http://localhost:8888/shortlink/' . htmlspecialchars($successo);
            // Recupera i canali salvati
            $stmtLink = $pdo->prepare("SELECT * FROM links WHERE codice = ?");
            $stmtLink->execute([$successo]);
            $linkCreato = $stmtLink->fetch();
            ?>
        <div class="successo-box">
            <h3>Link creato con successo!</h3>
            <span class="short-url"><?= $baseUrl ?></span>
            <br>
            <img src="../qrcodes/<?= htmlspecialchars($successo) ?>.png" alt="QR Code">
            <div class="download-links">
                <a href="../qrcodes/<?= htmlspecialchars($successo) ?>.png" download="<?= htmlspecialchars($successo) ?>.png">Scarica PNG</a>
                <a href="../qrcodes/<?= htmlspecialchars($successo) ?>.svg" download="<?= htmlspecialchars($successo) ?>.svg">Scarica SVG</a>
            </div>
            <a href="create.php" class="btn" style="width:auto;padding:10px 24px;display:inline-block;">+ Crea un altro link</a>
        </div>

        <?php
        // Mostra i link UTM solo se almeno un canale è stato selezionato
        $canaliAttivi = array_filter($canaliDisponibili, fn($k) => !empty($linkCreato['canale_' . $k]), ARRAY_FILTER_USE_KEY);
        if (!empty($canaliAttivi)):
        ?>
        <div class="utm-section">
            <h3>Link pronti per canale</h3>
            <?php foreach ($canaliAttivi as $chiave => $nome): ?>
            <?php $utmUrl = $baseUrl . '?utm_source=' . $chiave; ?>
            <div class="utm-row">
                <div class="utm-label"><span class="utm-dot"></span><?= $nome ?></div>
                <div class="utm-url" id="utm-<?= $chiave ?>"><?= $utmUrl ?></div>
                <button class="btn-copia" onclick="copiaUtm('<?= $chiave ?>', '<?= $utmUrl ?>')">Copia</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <form method="POST" enctype="multipart/form-data">
        <div class="layout">
            <div>
                <div class="card">
                    <h2>Dati del link</h2>
                    <label>URL di destinazione *</label>
                    <input type="url" name="url" id="urlInput" placeholder="https://esempio.com/pagina-lunga" required>
                    <label>Titolo <span class="hint">(opzionale)</span></label>
                    <input type="text" name="titolo" placeholder="Es. Promozione estate 2025">
                    <label>Codice personalizzato <span class="hint">(opzionale)</span></label>
                    <input type="text" name="codice" placeholder="Es. promo25" maxlength="20">
                    <label>Data di scadenza <span class="hint">(opzionale)</span></label>
                    <input type="datetime-local" name="scade_il">
                </div>

                <div class="card">
                    <h2>Canali di condivisione</h2>
                    <div class="sezione-label" style="margin-bottom:14px;">Seleziona i canali dove condividerai il link <span class="hint" style="text-transform:none;">(opzionale)</span></div>
                    <div class="canali-grid">
                        <?php
                        $iconeCanali = [];
                        foreach ($canaliDisponibili as $chiave => $nome):
                        ?>
                        <label class="canale-check" id="label-<?= $chiave ?>">
                            <input type="checkbox" name="canale_<?= $chiave ?>" value="1"
                                   onchange="toggleCanale('<?= $chiave ?>', this.checked)">
                            <span class="canale-dot"></span>
                            <span class="canale-nome"><?= $nome ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <h2>Personalizza QR Code</h2>

                    <div class="sezione-label">Stile moduli</div>
                    <div class="stile-grid">
                        <label class="stile-opzione selected" id="opt-quadrati">
                            <input type="radio" name="stile" value="quadrati" checked>
                            <svg width="36" height="36" viewBox="0 0 36 36">
                                <rect x="1" y="1" width="9" height="9" fill="#000"/>
                                <rect x="13" y="1" width="9" height="9" fill="#000"/>
                                <rect x="25" y="1" width="9" height="9" fill="#000"/>
                                <rect x="1" y="13" width="9" height="9" fill="#000"/>
                                <rect x="13" y="13" width="9" height="9" fill="#000"/>
                                <rect x="25" y="13" width="9" height="9" fill="#000"/>
                                <rect x="1" y="25" width="9" height="9" fill="#000"/>
                                <rect x="13" y="25" width="9" height="9" fill="#000"/>
                                <rect x="25" y="25" width="9" height="9" fill="#000"/>
                            </svg>
                            <span>Quadrati</span>
                        </label>
                        <label class="stile-opzione" id="opt-pallini">
                            <input type="radio" name="stile" value="pallini">
                            <svg width="36" height="36" viewBox="0 0 36 36">
                                <circle cx="5" cy="5" r="4" fill="#000"/>
                                <circle cx="17" cy="5" r="4" fill="#000"/>
                                <circle cx="29" cy="5" r="4" fill="#000"/>
                                <circle cx="5" cy="17" r="4" fill="#000"/>
                                <circle cx="17" cy="17" r="4" fill="#000"/>
                                <circle cx="29" cy="17" r="4" fill="#000"/>
                                <circle cx="5" cy="29" r="4" fill="#000"/>
                                <circle cx="17" cy="29" r="4" fill="#000"/>
                                <circle cx="29" cy="29" r="4" fill="#000"/>
                            </svg>
                            <span>Pallini</span>
                        </label>
                    </div>

                    <div class="sezione-label">Colori</div>
                    <div class="colori-grid">
                        <div class="colore-box">
                            <label>Moduli</label>
                            <input type="color" name="colore" id="colore" value="#000000">
                        </div>
                        <div class="colore-box">
                            <label>Sfondo</label>
                            <input type="color" name="sfondo" id="sfondo" value="#ffffff">
                        </div>
                    </div>

                    <div class="sezione-label">Logo al centro <span class="hint" style="text-transform:none;">(opzionale — PNG, JPG, GIF o SVG)</span></div>
                    <div class="logo-upload-area">
                        <div class="logo-preview-box" id="logoPreviewBox">
                            <img id="logoPreviewImg" src="" alt="">
                            <span class="logo-nome" id="logoNome"></span>
                            <button type="button" class="btn-rimuovi" onclick="rimuoviLogo()">Rimuovi</button>
                        </div>
                        <input type="file" name="logo" id="logoInput" accept=".png,.jpg,.jpeg,.gif,.svg">
                    </div>
                </div>
            </div>

            <div>
                <div class="card">
                    <h2>Anteprima QR Code</h2>
                    <div class="anteprima-box" id="anteprimaBox">
                        <div class="placeholder">Inserisci un URL e clicca<br>"Aggiorna anteprima"</div>
                    </div>
                    <button type="button" class="btn-anteprima" onclick="aggiornaAnteprima()">Aggiorna anteprima</button>
                    <button type="submit" class="btn">Crea link e genera QR</button>
                    <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
                </div>
            </div>
        </div>
        </form>
        <?php endif; ?>
    </div>

    <script>
    let anteprimaTimeout = null;

    function toggleCanale(chiave, checked) {
        const label = document.getElementById('label-' + chiave);
        if (checked) label.classList.add('checked');
        else label.classList.remove('checked');
    }

    document.querySelectorAll('.stile-opzione').forEach(el => {
        el.addEventListener('click', () => {
            document.querySelectorAll('.stile-opzione').forEach(e => e.classList.remove('selected'));
            el.classList.add('selected');
            clearTimeout(anteprimaTimeout);
            anteprimaTimeout = setTimeout(aggiornaAnteprima, 300);
        });
    });

    document.getElementById('logoInput').addEventListener('change', function() {
        if (this.files[0]) {
            const file = this.files[0];
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('logoPreviewImg').src = e.target.result;
                document.getElementById('logoNome').textContent = file.name;
                document.getElementById('logoPreviewBox').style.display = 'flex';
                document.getElementById('logoInput').style.display = 'none';
            };
            reader.readAsDataURL(file);
            clearTimeout(anteprimaTimeout);
            anteprimaTimeout = setTimeout(aggiornaAnteprima, 300);
        }
    });

    function rimuoviLogo() {
        const input = document.getElementById('logoInput');
        input.value = '';
        document.getElementById('logoPreviewBox').style.display = 'none';
        document.getElementById('logoInput').style.display = 'block';
        clearTimeout(anteprimaTimeout);
        anteprimaTimeout = setTimeout(aggiornaAnteprima, 300);
    }

    function aggiornaAnteprima() {
        const url = document.getElementById('urlInput').value;
        if (!url) {
            document.getElementById('anteprimaBox').innerHTML = '<div class="placeholder">Inserisci prima un URL valido</div>';
            return;
        }
        const stile     = document.querySelector('input[name="stile"]:checked').value;
        const colore    = document.getElementById('colore').value;
        const sfondo    = document.getElementById('sfondo').value;
        const logoInput = document.getElementById('logoInput');
        document.getElementById('anteprimaBox').innerHTML = '<div class="spinner"></div>';
        const formData = new FormData();
        formData.append('data', url);
        formData.append('stile', stile);
        formData.append('colore', colore);
        formData.append('sfondo', sfondo);
        if (logoInput.files && logoInput.files[0]) formData.append('logo_file', logoInput.files[0]);
        fetch('../api/generate-qr.php', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(data => {
            if (data.startsWith('data:image')) {
                document.getElementById('anteprimaBox').innerHTML = '<img src="' + data + '" alt="Anteprima QR">';
            } else {
                document.getElementById('anteprimaBox').innerHTML = '<div class="placeholder" style="color:#d20a10;">' + data + '</div>';
            }
        })
        .catch(() => {
            document.getElementById('anteprimaBox').innerHTML = '<div class="placeholder" style="color:#d20a10;">Errore di connessione</div>';
        });
    }

    function copiaUtm(chiave, url) {
        navigator.clipboard.writeText(url).then(() => {
            const btn = event.target;
            btn.textContent = 'Copiato!';
            btn.classList.add('copiato');
            setTimeout(() => { btn.textContent = 'Copia'; btn.classList.remove('copiato'); }, 2000);
        });
    }

    document.getElementById('urlInput').addEventListener('input', () => { clearTimeout(anteprimaTimeout); anteprimaTimeout = setTimeout(aggiornaAnteprima, 800); });
    document.getElementById('colore').addEventListener('input', () => { clearTimeout(anteprimaTimeout); anteprimaTimeout = setTimeout(aggiornaAnteprima, 500); });
    document.getElementById('sfondo').addEventListener('input', () => { clearTimeout(anteprimaTimeout); anteprimaTimeout = setTimeout(aggiornaAnteprima, 500); });
    </script>
</body>
</html>
