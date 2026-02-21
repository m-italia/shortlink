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

function inserisciLogo($imgData, $logoPath, $ext) {
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
        $result = ob_get_clean();
        imagedestroy($logo);
        imagedestroy($qrIm);
        return $result;
    }
    return $imgData;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url        = trim($_POST['url'] ?? '');
    $titolo     = trim($_POST['titolo'] ?? '');
    $codice     = trim($_POST['codice'] ?? '');
    $scade_il   = $_POST['scade_il'] ?? null;
    $stile      = $_POST['stile'] ?? 'quadrati';
    $logoUpload = $_FILES['logo'] ?? null;

    if (!validaURL($url)) {
        $errore = 'URL non valido. Assicurati di includere http:// o https://';
    } else {
        if (empty($codice)) {
            $codice = generaCodiceUnico($pdo);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM links WHERE codice = ?");
            $stmt->execute([$codice]);
            if ($stmt->fetch()) {
                $errore = 'Questo codice è già in uso. Scegline un altro.';
            }
        }

        if (!$errore) {
            $shortUrl  = 'http://localhost:8888/shortlink/' . $codice;
            $qrPngPath = '../qrcodes/' . $codice . '.png';
            $qrSvgPath = '../qrcodes/' . $codice . '.svg';
            $logoPath  = null;
            $logoExt   = null;

            // Gestisci upload logo
            if ($logoUpload && $logoUpload['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($logoUpload['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'])) {
                    $logoPath = '../qrcodes/logo_' . $codice . '.' . $ext;
                    $logoExt  = $ext;
                    move_uploaded_file($logoUpload['tmp_name'], $logoPath);
                }
            }

            try {
                $haLogo = $logoPath && file_exists($logoPath);

                // Opzioni PNG
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

                // Inserisci logo se presente
                if ($haLogo) {
                    $imgData = inserisciLogo($imgData, $logoPath, $logoExt);
                }

                file_put_contents($qrPngPath, $imgData);

                // Opzioni SVG
                $optionsSvg = new QROptions([
                    'outputType'          => QRCode::OUTPUT_MARKUP_SVG,
                    'eccLevel'            => QRCode::ECC_H,
                    'scale'               => 10,
                    'imageBase64'         => false,
                    'drawCircularModules' => $stile === 'pallini',
                    'circleRadius'        => 0.45,
                ]);

                $qrSvg   = new QRCode($optionsSvg);
                $svgData = $qrSvg->render($shortUrl);
                file_put_contents($qrSvgPath, $svgData);

            } catch (\Exception $e) {
                $errore = 'Errore generazione QR: ' . $e->getMessage();
            }

            if (!$errore) {
                $stmt = $pdo->prepare("
                    INSERT INTO links (codice, url_destinazione, titolo, qr_path, scade_il)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $codice,
                    $url,
                    $titolo ?: null,
                    'qrcodes/' . $codice . '.png',
                    $scade_il ?: null
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
        body {
            font-family: 'Titillium Web', sans-serif;
            background: #f4f4f4;
            min-height: 100vh;
        }
        header {
            background: #000;
            padding: 0 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .header-logo img { height: 32px; display: block; }
        header a {
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        header a:hover { background: #222; }
        .container { padding: 32px 40px; max-width: 1100px; margin: 0 auto; }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #000;
            margin-bottom: 24px;
        }
        .page-title span { color: #d20a10; }
        .layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 28px;
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #000;
            border-left: 3px solid #d20a10;
            padding-left: 10px;
            margin-bottom: 24px;
        }
        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #555;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        input[type="text"],
        input[type="url"],
        input[type="datetime-local"],
        input[type="file"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Titillium Web', sans-serif;
            margin-bottom: 18px;
            outline: none;
            transition: border 0.2s;
            background: #fafafa;
        }
        input:focus { border-color: #d20a10; background: #fff; }
        .hint { font-size: 11px; color: #aaa; font-weight: 400; margin-left: 6px; text-transform: none; }

        /* Stile QR */
        .stile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 18px;
        }
        .stile-opzione {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 14px 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .stile-opzione:hover { border-color: #d20a10; }
        .stile-opzione.selected { border-color: #d20a10; background: #fff5f5; }
        .stile-opzione svg { display: block; margin: 0 auto 8px; }
        .stile-opzione span { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #555; }
        .stile-opzione input[type="radio"] { position: absolute; opacity: 0; }

        /* Anteprima */
        .anteprima-box {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 20px;
            min-height: 220px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .anteprima-box img { width: 180px; height: 180px; border-radius: 4px; }
        .anteprima-box .placeholder { color: #ccc; font-size: 13px; font-weight: 600; line-height: 1.8; }
        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid #f0f0f0;
            border-top-color: #d20a10;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .btn {
            width: 100%;
            padding: 14px;
            background: #d20a10;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Titillium Web', sans-serif;
            cursor: pointer;
            letter-spacing: 0.5px;
            transition: background 0.2s;
            text-align: center;
            display: block;
            text-decoration: none;
        }
        .btn:hover { background: #b00008; }
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            margin-top: 10px;
        }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-anteprima {
            width: 100%;
            padding: 10px;
            background: #000;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Titillium Web', sans-serif;
            cursor: pointer;
            letter-spacing: 0.5px;
            transition: background 0.2s;
            margin-bottom: 16px;
        }
        .btn-anteprima:hover { background: #333; }
        .errore {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .successo-box {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 28px;
            text-align: center;
            margin-bottom: 24px;
        }
        .successo-box h3 { font-size: 20px; font-weight: 700; color: #000; margin-bottom: 8px; }
        .successo-box .short-url {
            font-size: 18px;
            font-weight: 700;
            color: #d20a10;
            word-break: break-all;
            margin-bottom: 20px;
            display: block;
        }
        .successo-box img {
            width: 200px;
            height: 200px;
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 16px;
        }
        .download-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .download-links a {
            color: #d20a10;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
        }
        .download-links a:hover { text-decoration: underline; }
        .logo-preview {
            width: 60px;
            height: 60px;
            object-fit: contain;
            border: 1px solid #eee;
            border-radius: 6px;
            margin-top: 8px;
            display: none;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-logo">
            <img src="../assets/logo.svg" alt="Logo">
        </div>
        <a href="dashboard.php">← Dashboard</a>
    </header>

    <div class="container">
        <div class="page-title">Nuovo <span>link</span></div>

        <?php if ($errore): ?>
            <div class="errore"><?= htmlspecialchars($errore) ?></div>
        <?php endif; ?>

        <?php if ($successo): ?>
        <div class="successo-box">
            <h3>✅ Link creato con successo!</h3>
            <span class="short-url">http://localhost:8888/shortlink/<?= htmlspecialchars($successo) ?></span>
            <br>
            <img src="../qrcodes/<?= htmlspecialchars($successo) ?>.png" alt="QR Code">
            <div class="download-links">
                <a href="../qrcodes/<?= htmlspecialchars($successo) ?>.png" download="<?= htmlspecialchars($successo) ?>.png">⬇ Scarica PNG</a>
                <a href="../qrcodes/<?= htmlspecialchars($successo) ?>.svg" download="<?= htmlspecialchars($successo) ?>.svg">⬇ Scarica SVG</a>
            </div>
            <a href="create.php" class="btn" style="width:auto; padding:10px 24px; display:inline-block;">+ Crea un altro link</a>
        </div>

        <?php else: ?>

        <form method="POST" enctype="multipart/form-data">
        <div class="layout">

            <!-- Colonna sinistra -->
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
                    <h2>Personalizza QR Code</h2>

                    <label>Stile moduli</label>
                    <div class="stile-grid">
                        <label class="stile-opzione selected" id="opt-quadrati">
                            <input type="radio" name="stile" value="quadrati" checked>
                            <svg width="40" height="40" viewBox="0 0 40 40">
                                <rect x="2" y="2" width="8" height="8" fill="#000"/>
                                <rect x="14" y="2" width="8" height="8" fill="#000"/>
                                <rect x="26" y="2" width="8" height="8" fill="#000"/>
                                <rect x="2" y="14" width="8" height="8" fill="#000"/>
                                <rect x="14" y="14" width="8" height="8" fill="#000"/>
                                <rect x="26" y="14" width="8" height="8" fill="#000"/>
                                <rect x="2" y="26" width="8" height="8" fill="#000"/>
                                <rect x="14" y="26" width="8" height="8" fill="#000"/>
                                <rect x="26" y="26" width="8" height="8" fill="#000"/>
                            </svg>
                            <span>Quadrati</span>
                        </label>
                        <label class="stile-opzione" id="opt-pallini">
                            <input type="radio" name="stile" value="pallini">
                            <svg width="40" height="40" viewBox="0 0 40 40">
                                <circle cx="6" cy="6" r="4" fill="#000"/>
                                <circle cx="18" cy="6" r="4" fill="#000"/>
                                <circle cx="30" cy="6" r="4" fill="#000"/>
                                <circle cx="6" cy="18" r="4" fill="#000"/>
                                <circle cx="18" cy="18" r="4" fill="#000"/>
                                <circle cx="30" cy="18" r="4" fill="#000"/>
                                <circle cx="6" cy="30" r="4" fill="#000"/>
                                <circle cx="18" cy="30" r="4" fill="#000"/>
                                <circle cx="30" cy="30" r="4" fill="#000"/>
                            </svg>
                            <span>Pallini</span>
                        </label>
                    </div>

                    <label>Logo al centro <span class="hint">(opzionale — PNG/JPG)</span></label>
                    <input type="file" name="logo" id="logoInput" accept=".png,.jpg,.jpeg,.gif">
                    <img id="logoPreview" class="logo-preview" src="" alt="Anteprima logo">
                </div>
            </div>

            <!-- Colonna destra -->
            <div>
                <div class="card">
                    <h2>Anteprima QR Code</h2>
                    <div class="anteprima-box" id="anteprimaBox">
                        <div class="placeholder">Inserisci un URL e clicca<br>"Aggiorna anteprima"</div>
                    </div>
                    <button type="button" class="btn-anteprima" onclick="aggiornaAnteprima()">🔄 Aggiorna anteprima</button>
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

    // Gestione selezione stile
    document.querySelectorAll('.stile-opzione').forEach(el => {
        el.addEventListener('click', () => {
            document.querySelectorAll('.stile-opzione').forEach(e => e.classList.remove('selected'));
            el.classList.add('selected');
            clearTimeout(anteprimaTimeout);
            anteprimaTimeout = setTimeout(aggiornaAnteprima, 300);
        });
    });

    // Anteprima logo caricato
    document.getElementById('logoInput').addEventListener('change', function() {
        const preview = document.getElementById('logoPreview');
        if (this.files[0]) {
            const reader = new FileReader();
            reader.onload = e => {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(this.files[0]);
            clearTimeout(anteprimaTimeout);
            anteprimaTimeout = setTimeout(aggiornaAnteprima, 300);
        } else {
            preview.style.display = 'none';
        }
    });

    function aggiornaAnteprima() {
        const url = document.getElementById('urlInput').value;
        if (!url) {
            document.getElementById('anteprimaBox').innerHTML = '<div class="placeholder">Inserisci prima un URL valido</div>';
            return;
        }

        const stile     = document.querySelector('input[name="stile"]:checked').value;
        const logoInput = document.getElementById('logoInput');

        document.getElementById('anteprimaBox').innerHTML = '<div class="spinner"></div>';

        const formData = new FormData();
        formData.append('data', url);
        formData.append('stile', stile);

        if (logoInput.files[0]) {
            formData.append('logo_file', logoInput.files[0]);
        }

        fetch('../api/generate-qr.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(data => {
            if (data.startsWith('data:image')) {
                document.getElementById('anteprimaBox').innerHTML =
                    '<img src="' + data + '" alt="Anteprima QR">';
            } else {
                document.getElementById('anteprimaBox').innerHTML =
                    '<div class="placeholder" style="color:#d20a10;">' + data + '</div>';
            }
        })
        .catch(() => {
            document.getElementById('anteprimaBox').innerHTML =
                '<div class="placeholder" style="color:#d20a10;">Errore di connessione</div>';
        });
    }

    document.getElementById('urlInput').addEventListener('input', () => {
        clearTimeout(anteprimaTimeout);
        anteprimaTimeout = setTimeout(aggiornaAnteprima, 800);
    });
    </script>
</body>
</html>
