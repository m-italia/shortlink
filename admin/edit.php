<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: dashboard.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM links WHERE id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();
if (!$link) { header('Location: dashboard.php'); exit; }

$errore   = '';
$successo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url      = trim($_POST['url'] ?? '');
    $titolo   = trim($_POST['titolo'] ?? '');
    $attivo   = isset($_POST['attivo']) ? 1 : 0;
    $scade_il = $_POST['scade_il'] ?? null;

    if (!validaURL($url)) {
        $errore = 'URL non valido. Assicurati di includere http:// o https://';
    } else {
        $stmt = $pdo->prepare("UPDATE links SET url_destinazione = ?, titolo = ?, attivo = ?, scade_il = ? WHERE id = ?");
        $stmt->execute([$url, $titolo ?: null, $attivo, $scade_il ?: null, $id]);
        $successo = 'Link aggiornato con successo!';
        $stmt = $pdo->prepare("SELECT * FROM links WHERE id = ?");
        $stmt->execute([$id]);
        $link = $stmt->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica Link — ShortLink</title>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Titillium Web', sans-serif; background: #f4f4f4; min-height: 100vh; }
        header {
            background: #000; padding: 0 40px; display: flex; align-items: center;
            justify-content: space-between; height: 64px; position: sticky; top: 0; z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .header-logo a { display: block; line-height: 0; }
        .header-logo img { height: 32px; display: block; }
        header a { color: #fff; text-decoration: none; font-size: 14px; font-weight: 600; padding: 8px 16px; border-radius: 6px; transition: background 0.2s; }
        header a:hover { background: #222; }

        .container { padding: 32px 40px; max-width: 800px; margin: 0 auto; }
        .page-title { font-size: 24px; font-weight: 700; color: #000; margin-bottom: 24px; }
        .page-title span { color: #d20a10; }

        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 28px; margin-bottom: 20px; }
        .card h2 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #000; border-left: 3px solid #d20a10; padding-left: 10px; margin-bottom: 24px; }

        .info-box {
            background: #000; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;
            font-size: 14px; color: #aaa; line-height: 2;
        }
        .info-box strong { color: #fff; }
        .info-box .short-url { color: #d20a10; font-weight: 700; }

        label { display: block; font-size: 12px; font-weight: 700; color: #555; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .hint { font-size: 11px; color: #aaa; font-weight: 400; margin-left: 6px; text-transform: none; }
        input[type="text"], input[type="url"], input[type="datetime-local"] {
            width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 6px;
            font-size: 14px; font-family: 'Titillium Web', sans-serif; margin-bottom: 18px;
            outline: none; transition: border 0.2s; background: #fafafa;
        }
        input:focus { border-color: #d20a10; background: #fff; }

        .toggle { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; padding: 14px 16px; background: #f9f9f9; border-radius: 8px; border: 1px solid #eee; }
        .toggle input[type="checkbox"] { width: 18px; height: 18px; accent-color: #d20a10; cursor: pointer; margin: 0; }
        .toggle label { font-size: 14px; font-weight: 600; color: #333; margin: 0; text-transform: none; letter-spacing: 0; cursor: pointer; }

        .btn-group { display: flex; gap: 12px; }
        .btn { padding: 12px 24px; background: #d20a10; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; font-family: 'Titillium Web', sans-serif; cursor: pointer; text-decoration: none; display: inline-block; transition: background 0.2s; letter-spacing: 0.5px; }
        .btn:hover { background: #b00008; }
        .btn-secondary { background: #f0f0f0; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }

        .errore { background: #fee2e2; color: #dc2626; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
        .successo { background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }

        .qr-box { text-align: center; padding: 10px; }
        .qr-box img { width: 180px; height: 180px; border: 1px solid #eee; border-radius: 8px; padding: 8px; margin-bottom: 16px; }
        .download-links { display: flex; justify-content: center; gap: 16px; }
        .download-links a { color: #d20a10; font-size: 14px; font-weight: 700; text-decoration: none; }
        .download-links a:hover { text-decoration: underline; }

        .nota { font-size: 12px; color: #aaa; margin-top: -14px; margin-bottom: 18px; }
    </style>
</head>
<body>
    <header>
        <div class="header-logo"><a href="dashboard.php"><img src="../assets/logo.svg" alt="Logo"></a></div>
        <a href="dashboard.php">← Dashboard</a>
    </header>

    <div class="container">
        <div class="page-title">Modifica <span>link</span></div>

        <div class="card">
            <h2>Informazioni link</h2>

            <div class="info-box">
                <strong>Short URL:</strong> <span class="short-url">http://localhost:8888/shortlink/<?= htmlspecialchars($link['codice']) ?></span><br>
                <strong>Creato il:</strong> <?= formattaData($link['creato_il']) ?>
            </div>

            <?php if ($errore): ?><div class="errore"><?= htmlspecialchars($errore) ?></div><?php endif; ?>
            <?php if ($successo): ?><div class="successo"><?= htmlspecialchars($successo) ?></div><?php endif; ?>

            <form method="POST">
                <label>URL di destinazione *</label>
                <input type="url" name="url" value="<?= htmlspecialchars($link['url_destinazione']) ?>" required>
                <p class="nota">Modifica l'URL senza rigenerare il QR code — il codice fisico rimane lo stesso.</p>

                <label>Titolo <span class="hint">(opzionale)</span></label>
                <input type="text" name="titolo" value="<?= htmlspecialchars($link['titolo'] ?? '') ?>" placeholder="Es. Promozione estate 2025">

                <label>Data di scadenza <span class="hint">(opzionale)</span></label>
                <input type="datetime-local" name="scade_il" value="<?= $link['scade_il'] ? date('Y-m-d\TH:i', strtotime($link['scade_il'])) : '' ?>">

                <div class="toggle">
                    <input type="checkbox" name="attivo" id="attivo" <?= $link['attivo'] ? 'checked' : '' ?>>
                    <label for="attivo">Link attivo</label>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn">Salva modifiche</button>
                    <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
                </div>
            </form>
        </div>

        <?php if ($link['qr_path']): ?>
        <div class="card">
            <h2>QR Code</h2>
            <div class="qr-box">
                <img src="../<?= htmlspecialchars($link['qr_path']) ?>" alt="QR Code">
                <div class="download-links">
                    <a href="../<?= htmlspecialchars($link['qr_path']) ?>" download>Scarica PNG</a>
                    <a href="../qrcodes/<?= htmlspecialchars($link['codice']) ?>.svg" download="<?= htmlspecialchars($link['codice']) ?>.svg">Scarica SVG</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
