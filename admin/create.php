<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$errore   = '';
$successo = '';

$canaliDisponibili = [
    'instagram'  => 'Instagram',
    'facebook'   => 'Facebook',
    'linkedin'   => 'LinkedIn',
    'youtube'    => 'YouTube',
    'whatsapp'   => 'WhatsApp',
    'newsletter' => 'Newsletter',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url      = trim($_POST['url'] ?? '');
    $titolo   = trim($_POST['titolo'] ?? '');
    $codice   = trim($_POST['codice'] ?? '');
    $scade_il = $_POST['scade_il'] ?? null;

    $canaliSelezionati = [];
    foreach (array_keys($canaliDisponibili) as $canale) {
        $canaliSelezionati[$canale] = isset($_POST['canale_' . $canale]) ? 1 : 0;
    }

    $qrPngBase64 = $_POST['qr_png_base64'] ?? '';
    $qrSvgBase64 = $_POST['qr_svg_base64'] ?? '';

    if (!validaURL($url)) {
        $errore = 'URL non valido. Assicurati di includere http:// o https://';
    } elseif (empty($qrPngBase64)) {
        $errore = 'Genera prima l\'anteprima del QR Code prima di salvare.';
    } else {
        if (empty($codice)) {
            $codice = generaCodiceUnico($pdo);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM links WHERE codice = ?");
            $stmt->execute([$codice]);
            if ($stmt->fetch()) $errore = 'Questo codice è già in uso. Scegline un altro.';
        }

        if (!$errore) {
            $qrPngData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $qrPngBase64));
            file_put_contents('../qrcodes/' . $codice . '.png', $qrPngData);

            if (!empty($qrSvgBase64)) {
                $qrSvgData = base64_decode(preg_replace('#^data:(image/svg\+xml|text/xml);base64,#i', '', $qrSvgBase64));
                file_put_contents('../qrcodes/' . $codice . '.svg', $qrSvgData);
            }

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
        .hint { font-size: 11px; color: #aaa; font-weight: 400; margin-left: 6px; text-transform: none; }
        input[type="text"], input[type="url"], input[type="datetime-local"] { width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 14px; font-family: 'Titillium Web', sans-serif; margin-bottom: 18px; outline: none; transition: border 0.2s; background: #fafafa; }
        input[type="text"]:focus, input[type="url"]:focus, input[type="datetime-local"]:focus { border-color: #d20a10; background: #fff; }

        .sezione-label { font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }

        /* Griglie opzioni QR */
        .opzioni-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 20px; }
        .opzioni-grid-6 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 20px; }
        .opzione { border: 2px solid #e0e0e0; border-radius: 8px; padding: 10px 6px; text-align: center; cursor: pointer; transition: border-color 0.2s, background 0.2s; user-select: none; }
        .opzione:hover { border-color: #d20a10; }
        .opzione.selected { border-color: #d20a10; background: #fff5f5; }
        .opzione svg { display: block; margin: 0 auto 6px; }
        .opzione span { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #555; }

        /* Colori */
        .colori-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 4px; }
        .colore-box { display: flex; flex-direction: column; gap: 6px; }
        input[type="color"] { width: 100%; height: 42px; border: 1px solid #e0e0e0; border-radius: 6px; padding: 2px; cursor: pointer; background: #fafafa; }

        /* Canali */
        .canali-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px; }
        .canale-check { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; transition: border-color 0.2s, background 0.2s; }
        .canale-check:hover { border-color: #d20a10; background: #fff5f5; }
        .canale-check.checked { border-color: #d20a10; background: #fff5f5; }
        .canale-check input[type="checkbox"] { width: 16px; height: 16px; accent-color: #d20a10; margin: 0; cursor: pointer; flex-shrink: 0; }
        .canale-dot { width: 8px; height: 8px; border-radius: 50%; background: #d20a10; flex-shrink: 0; }
        .canale-nome { font-size: 13px; font-weight: 700; color: #333; }

        /* Anteprima */
        .anteprima-box { background: #f9f9f9; border-radius: 8px; margin-bottom: 20px; min-height: 280px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .placeholder-text { color: #ccc; font-size: 13px; font-weight: 600; line-height: 1.8; text-align: center; }

        /* Bottoni */
        .btn { width: 100%; padding: 14px; background: #d20a10; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 700; font-family: 'Titillium Web', sans-serif; cursor: pointer; letter-spacing: 0.5px; transition: background 0.2s; text-align: center; display: block; text-decoration: none; margin-bottom: 10px; }
        .btn:hover { background: #b00008; }
        .btn-nero { background: #000; }
        .btn-nero:hover { background: #333; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }

        .errore { background: #fee2e2; color: #dc2626; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }

        /* Successo */
        .successo-box { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 28px; text-align: center; margin-bottom: 24px; }
        .successo-box h3 { font-size: 20px; font-weight: 700; color: #000; margin-bottom: 8px; }
        .successo-box .short-url { font-size: 18px; font-weight: 700; color: #d20a10; word-break: break-all; margin-bottom: 20px; display: block; }
        .successo-box img { width: 200px; height: 200px; border: 1px solid #eee; border-radius: 8px; padding: 8px; margin-bottom: 16px; }
        .download-links { display: flex; justify-content: center; gap: 16px; margin-bottom: 20px; }
        .download-links a { color: #d20a10; font-size: 14px; font-weight: 700; text-decoration: none; }
        .download-links a:hover { text-decoration: underline; }

        /* UTM */
        .utm-section { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 28px; margin-bottom: 24px; }
        .utm-section h3 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #000; border-left: 3px solid #d20a10; padding-left: 10px; margin-bottom: 20px; }
        .utm-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; padding: 12px 16px; background: #f9f9f9; border-radius: 8px; border: 1px solid #eee; }
        .utm-label { font-size: 13px; font-weight: 700; color: #333; width: 100px; flex-shrink: 0; display: flex; align-items: center; gap: 8px; }
        .utm-dot { width: 8px; height: 8px; border-radius: 50%; background: #d20a10; flex-shrink: 0; }
        .utm-url { font-size: 12px; color: #666; flex: 1; word-break: break-all; font-family: monospace; }
        .btn-copia { padding: 6px 14px; background: #000; color: #fff; border: none; border-radius: 5px; font-size: 12px; font-weight: 700; font-family: 'Titillium Web', sans-serif; cursor: pointer; transition: background 0.2s; flex-shrink: 0; }
        .btn-copia:hover { background: #333; }
        .btn-copia.copiato { background: #16a34a; }
    </style>
</head>
<body>
    <header>
        <div class="header-logo"><a href="dashboard.php"><img src="../assets/logo.svg" alt="Logo"></a></div>
        <a href="dashboard.php">← Dashboard</a>
    </header>

    <div class="container">
        <div class="page-title">Nuovo <span>link</span></div>

        <?php if ($errore): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
        <?php endif; ?>

        <?php if ($successo):
            $baseUrl  = 'http://localhost:8888/shortlink/' . htmlspecialchars($successo);
            $stmtLink = $pdo->prepare("SELECT * FROM links WHERE codice = ?");
            $stmtLink->execute([$successo]);
            $linkCreato  = $stmtLink->fetch();
            $canaliAttivi = array_filter($canaliDisponibili, fn($k) => !empty($linkCreato['canale_' . $k]), ARRAY_FILTER_USE_KEY);
        ?>
        <div class="successo-box">
            <h3>Link creato con successo!</h3>
            <span class="short-url"><?= $baseUrl ?></span>
            <img src="../qrcodes/<?= htmlspecialchars($successo) ?>.png" alt="QR Code">
            <div class="download-links">
                <a href="../qrcodes/<?= htmlspecialchars($successo) ?>.png" download="<?= htmlspecialchars($successo) ?>.png">Scarica PNG</a>
                <a href="../qrcodes/<?= htmlspecialchars($successo) ?>.svg" download="<?= htmlspecialchars($successo) ?>.svg">Scarica SVG</a>
            </div>
            <a href="create.php" class="btn" style="width:auto;padding:10px 24px;display:inline-block;">+ Crea un altro link</a>
        </div>

        <?php if (!empty($canaliAttivi)): ?>
        <div class="utm-section">
            <h3>Link pronti per canale</h3>
            <?php foreach ($canaliAttivi as $chiave => $nome):
                $utmUrl = $baseUrl . '?utm_source=' . $chiave;
            ?>
            <div class="utm-row">
                <div class="utm-label"><span class="utm-dot"></span><?= $nome ?></div>
                <div class="utm-url"><?= $utmUrl ?></div>
                <button class="btn-copia" onclick="copiaUtm(this, '<?= $utmUrl ?>')">Copia</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>

        <form method="POST" id="mainForm">
            <input type="hidden" name="qr_png_base64" id="qrPngBase64">
            <input type="hidden" name="qr_svg_base64" id="qrSvgBase64">

            <div class="layout">
                <!-- COLONNA SINISTRA -->
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
                            <?php foreach ($canaliDisponibili as $chiave => $nome): ?>
                            <label class="canale-check" id="label-<?= $chiave ?>">
                                <input type="checkbox" name="canale_<?= $chiave ?>" value="1" onchange="toggleCanale('<?= $chiave ?>', this.checked)">
                                <span class="canale-dot"></span>
                                <span class="canale-nome"><?= $nome ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card">
                        <h2>Personalizza QR Code</h2>

                        <div class="sezione-label">Stile moduli</div>
                        <div class="opzioni-grid-6" id="gridDot">
                            <div class="opzione selected" data-group="dot" data-value="square">
                                <svg width="38" height="38" viewBox="0 0 38 38">
                                    <rect x="1" y="1" width="10" height="10" fill="#000"/><rect x="14" y="1" width="10" height="10" fill="#000"/><rect x="27" y="1" width="10" height="10" fill="#000"/>
                                    <rect x="1" y="14" width="10" height="10" fill="#000"/><rect x="14" y="14" width="10" height="10" fill="#000"/><rect x="27" y="14" width="10" height="10" fill="#000"/>
                                    <rect x="1" y="27" width="10" height="10" fill="#000"/><rect x="14" y="27" width="10" height="10" fill="#000"/><rect x="27" y="27" width="10" height="10" fill="#000"/>
                                </svg>
                                <span>Quadrati</span>
                            </div>
                            <div class="opzione" data-group="dot" data-value="dots">
                                <svg width="38" height="38" viewBox="0 0 38 38">
                                    <circle cx="6" cy="6" r="5" fill="#000"/><circle cx="19" cy="6" r="5" fill="#000"/><circle cx="32" cy="6" r="5" fill="#000"/>
                                    <circle cx="6" cy="19" r="5" fill="#000"/><circle cx="19" cy="19" r="5" fill="#000"/><circle cx="32" cy="19" r="5" fill="#000"/>
                                    <circle cx="6" cy="32" r="5" fill="#000"/><circle cx="19" cy="32" r="5" fill="#000"/><circle cx="32" cy="32" r="5" fill="#000"/>
                                </svg>
                                <span>Punti</span>
                            </div>
                            <div class="opzione" data-group="dot" data-value="rounded">
                                <svg width="38" height="38" viewBox="0 0 38 38">
                                    <rect x="1" y="1" width="10" height="10" rx="3" fill="#000"/><rect x="14" y="1" width="10" height="10" rx="3" fill="#000"/><rect x="27" y="1" width="10" height="10" rx="3" fill="#000"/>
                                    <rect x="1" y="14" width="10" height="10" rx="3" fill="#000"/><rect x="14" y="14" width="10" height="10" rx="3" fill="#000"/><rect x="27" y="14" width="10" height="10" rx="3" fill="#000"/>
                                    <rect x="1" y="27" width="10" height="10" rx="3" fill="#000"/><rect x="14" y="27" width="10" height="10" rx="3" fill="#000"/><rect x="27" y="27" width="10" height="10" rx="3" fill="#000"/>
                                </svg>
                                <span>Arrotondati</span>
                            </div>
                            <div class="opzione" data-group="dot" data-value="extra-rounded">
                                <svg width="38" height="38" viewBox="0 0 38 38">
                                    <rect x="1" y="1" width="10" height="10" rx="6" fill="#000"/><rect x="14" y="1" width="10" height="10" rx="6" fill="#000"/><rect x="27" y="1" width="10" height="10" rx="6" fill="#000"/>
                                    <rect x="1" y="14" width="10" height="10" rx="6" fill="#000"/><rect x="14" y="14" width="10" height="10" rx="6" fill="#000"/><rect x="27" y="14" width="10" height="10" rx="6" fill="#000"/>
                                    <rect x="1" y="27" width="10" height="10" rx="6" fill="#000"/><rect x="14" y="27" width="10" height="10" rx="6" fill="#000"/><rect x="27" y="27" width="10" height="10" rx="6" fill="#000"/>
                                </svg>
                                <span>Extra Round</span>
                            </div>
                            <div class="opzione" data-group="dot" data-value="classy">
                                <svg width="38" height="38" viewBox="0 0 38 38">
                                    <rect x="1" y="3" width="10" height="7" rx="4" fill="#000"/><rect x="14" y="3" width="10" height="7" rx="4" fill="#000"/><rect x="27" y="3" width="10" height="7" rx="4" fill="#000"/>
                                    <rect x="1" y="16" width="10" height="7" rx="4" fill="#000"/><rect x="14" y="16" width="10" height="7" rx="4" fill="#000"/><rect x="27" y="16" width="10" height="7" rx="4" fill="#000"/>
                                    <rect x="1" y="29" width="10" height="7" rx="4" fill="#000"/><rect x="14" y="29" width="10" height="7" rx="4" fill="#000"/><rect x="27" y="29" width="10" height="7" rx="4" fill="#000"/>
                                </svg>
                                <span>Classy</span>
                            </div>
                            <div class="opzione" data-group="dot" data-value="classy-rounded">
                                <svg width="38" height="38" viewBox="0 0 38 38">
                                    <rect x="1" y="2" width="10" height="9" rx="5" fill="#000"/><rect x="14" y="2" width="10" height="9" rx="5" fill="#000"/><rect x="27" y="2" width="10" height="9" rx="5" fill="#000"/>
                                    <rect x="1" y="15" width="10" height="9" rx="5" fill="#000"/><rect x="14" y="15" width="10" height="9" rx="5" fill="#000"/><rect x="27" y="15" width="10" height="9" rx="5" fill="#000"/>
                                    <rect x="1" y="28" width="10" height="9" rx="5" fill="#000"/><rect x="14" y="28" width="10" height="9" rx="5" fill="#000"/><rect x="27" y="28" width="10" height="9" rx="5" fill="#000"/>
                                </svg>
                                <span>Cl. Rounded</span>
                            </div>
                        </div>

                        <div class="sezione-label">Stile angoli (cornice)</div>
                        <div class="opzioni-grid-3" id="gridCorner">
                            <div class="opzione selected" data-group="corner" data-value="square">
                                <svg width="38" height="38" viewBox="0 0 38 38"><rect x="3" y="3" width="32" height="32" fill="none" stroke="#000" stroke-width="6"/></svg>
                                <span>Quadrato</span>
                            </div>
                            <div class="opzione" data-group="corner" data-value="extra-rounded">
                                <svg width="38" height="38" viewBox="0 0 38 38"><rect x="3" y="3" width="32" height="32" rx="12" fill="none" stroke="#000" stroke-width="6"/></svg>
                                <span>Arrotondato</span>
                            </div>
                            <div class="opzione" data-group="corner" data-value="dot">
                                <svg width="38" height="38" viewBox="0 0 38 38"><circle cx="19" cy="19" r="16" fill="none" stroke="#000" stroke-width="6"/></svg>
                                <span>Cerchio</span>
                            </div>
                        </div>

                        <div class="sezione-label">Punto interno angoli</div>
                        <div class="opzioni-grid-3" id="gridCornerDot">
                            <div class="opzione selected" data-group="cornerDot" data-value="square">
                                <svg width="38" height="38" viewBox="0 0 38 38"><rect x="9" y="9" width="20" height="20" fill="#000"/></svg>
                                <span>Quadrato</span>
                            </div>
                            <div class="opzione" data-group="cornerDot" data-value="dot">
                                <svg width="38" height="38" viewBox="0 0 38 38"><circle cx="19" cy="19" r="10" fill="#000"/></svg>
                                <span>Cerchio</span>
                            </div>
                        </div>

                        <div class="sezione-label" style="margin-top:4px;">Colori</div>
                        <div class="colori-grid">
                            <div class="colore-box">
                                <label>Moduli</label>
                                <input type="color" id="coloreFg" value="#000000">
                            </div>
                            <div class="colore-box">
                                <label>Sfondo</label>
                                <input type="color" id="coloreBg" value="#ffffff">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- COLONNA DESTRA -->
                <div>
                    <div class="card" style="position:sticky;top:84px;">
                        <h2>Anteprima QR Code</h2>
                        <div class="anteprima-box" id="anteprimaBox">
                            <div class="placeholder-text" id="placeholderText">Inserisci un URL per<br>generare l'anteprima</div>
                        </div>
                        <button type="button" class="btn btn-nero" onclick="generaQr()">Aggiorna anteprima</button>
                        <button type="button" class="btn" onclick="salva()">Crea link e genera QR</button>
                        <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
                    </div>
                </div>
            </div>
        </form>

        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/qr-code-styling@1.6.0-rc.1/lib/qr-code-styling.js"></script>
    <script>
    // Stato corrente
    var stato = {
        dot:       'square',
        corner:    'square',
        cornerDot: 'square'
    };
    var qrCode = null;
    var refreshTimer = null;

    // Selezione opzioni
    document.querySelectorAll('.opzione').forEach(function(el) {
        el.addEventListener('click', function() {
            var group = this.dataset.group;
            var value = this.dataset.value;
            // Deseleziona tutti dello stesso gruppo
            document.querySelectorAll('.opzione[data-group="' + group + '"]').forEach(function(o) {
                o.classList.remove('selected');
            });
            this.classList.add('selected');
            stato[group] = value;
            scheduleRefresh();
        });
    });

    // Colori
    document.getElementById('coloreFg').addEventListener('input', scheduleRefresh);
    document.getElementById('coloreBg').addEventListener('input', scheduleRefresh);

    // URL
    document.getElementById('urlInput').addEventListener('input', scheduleRefresh);

    function scheduleRefresh() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(generaQr, 700);
    }

    function getOptions() {
        return {
            width: 400,
            height: 400,
            type: 'canvas',
            data: document.getElementById('urlInput').value.trim() || 'https://esempio.com',
            qrOptions: { errorCorrectionLevel: 'H' },
            dotsOptions: {
                type: stato.dot,
                color: document.getElementById('coloreFg').value
            },
            backgroundOptions: {
                color: document.getElementById('coloreBg').value
            },
            cornersSquareOptions: {
                type: stato.corner,
                color: document.getElementById('coloreFg').value
            },
            cornersDotOptions: {
                type: stato.cornerDot,
                color: document.getElementById('coloreFg').value
            }
        };
    }

    function generaQr() {
        var url = document.getElementById('urlInput').value.trim();
        var box = document.getElementById('anteprimaBox');
        var ph  = document.getElementById('placeholderText');

        if (!url) {
            if (ph) ph.style.display = 'block';
            return;
        }
        if (ph) ph.style.display = 'none';

        var opts = getOptions();

        if (!qrCode) {
            qrCode = new QRCodeStyling(opts);
            qrCode.append(box);
        } else {
            qrCode.update(opts);
        }
    }

    async function salva() {
        var url = document.getElementById('urlInput').value.trim();
        if (!url) {
            alert('Inserisci un URL di destinazione.');
            return;
        }
        if (!qrCode) {
            alert('Clicca prima "Aggiorna anteprima" per generare il QR Code.');
            return;
        }
        try {
            var pngBlob = await qrCode.getRawData('png');
            var svgBlob = await qrCode.getRawData('svg');
            document.getElementById('qrPngBase64').value = await blobToBase64(pngBlob);
            document.getElementById('qrSvgBase64').value = await blobToBase64(svgBlob);
            document.getElementById('mainForm').submit();
        } catch(e) {
            alert('Errore nella generazione del QR. Riprova.');
            console.error(e);
        }
    }

    function blobToBase64(blob) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() { resolve(reader.result); };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }

    function toggleCanale(chiave, checked) {
        document.getElementById('label-' + chiave).classList.toggle('checked', checked);
    }

    function copiaUtm(btn, url) {
        navigator.clipboard.writeText(url).then(function() {
            btn.textContent = 'Copiato!';
            btn.classList.add('copiato');
            setTimeout(function() { btn.textContent = 'Copia'; btn.classList.remove('copiato'); }, 2000);
        });
    }
    </script>
</body>
</html>
