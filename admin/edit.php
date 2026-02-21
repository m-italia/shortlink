<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: dashboard.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM links WHERE id = ?");
$stmt->execute([$id]);
$link = $stmt->fetch();

if (!$link) {
    header('Location: dashboard.php');
    exit;
}

$errore = '';
$successo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    $titolo = trim($_POST['titolo'] ?? '');
    $attivo = isset($_POST['attivo']) ? 1 : 0;
    $scade_il = $_POST['scade_il'] ?? null;

    if (!validaURL($url)) {
        $errore = 'URL non valido. Assicurati di includere http:// o https://';
    } else {
        $stmt = $pdo->prepare("
            UPDATE links 
            SET url_destinazione = ?, titolo = ?, attivo = ?, scade_il = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $url,
            $titolo ?: null,
            $attivo,
            $scade_il ?: null,
            $id
        ]);
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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }
        header {
            background: #1a1a2e;
            color: white;
            padding: 16px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        header h1 { font-size: 20px; }
        header a { color: #a5b4fc; text-decoration: none; font-size: 14px; }
        .container { padding: 30px; max-width: 700px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 30px;
            margin-bottom: 20px;
        }
        h2 { font-size: 20px; color: #1a1a2e; margin-bottom: 24px; }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }
        .hint { font-size: 12px; color: #999; font-weight: 400; margin-left: 6px; }
        input[type="text"], input[type="url"], input[type="datetime-local"] {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 20px;
            outline: none;
            transition: border 0.2s;
        }
        input:focus { border-color: #4f46e5; }
        .toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .toggle input { width: auto; margin: 0; }
        .toggle label { margin: 0; font-size: 14px; }
        .btn {
            padding: 12px 24px;
            background: #4f46e5;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #4338ca; }
        .btn-secondary {
            background: #f0f2f5;
            color: #333;
            margin-left: 10px;
        }
        .btn-secondary:hover { background: #e0e2e5; }
        .errore {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .successo {
            background: #d1fae5;
            color: #065f46;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .info-box {
            background: #f0f2f5;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #555;
            line-height: 1.8;
        }
        .info-box strong { color: #1a1a2e; }
        .short-url { color: #4f46e5; font-weight: 600; }
        .qr-box { text-align: center; padding: 20px; }
        .qr-box img {
            width: 180px;
            height: 180px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 8px;
        }
        .download-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 12px;
        }
        .download-links a {
            color: #4f46e5;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }
        .download-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <header>
        <h1>🔗 ShortLink</h1>
        <a href="dashboard.php">← Dashboard</a>
    </header>

    <div class="container">
        <div class="card">
            <h2>Modifica link</h2>

            <div class="info-box">
                <strong>Short URL:</strong>
                <span class="short-url">http://localhost:8888/shortlink/<?= htmlspecialchars($link['codice']) ?></span><br>
                <strong>Creato il:</strong> <?= formattaData($link['creato_il']) ?>
            </div>

            <?php if ($errore): ?>
                <div class="errore"><?= htmlspecialchars($errore) ?></div>
            <?php endif; ?>

            <?php if ($successo): ?>
                <div class="successo">✅ <?= htmlspecialchars($successo) ?></div>
            <?php endif; ?>

            <form method="POST">
                <label>URL di destinazione *</label>
                <input type="url" name="url" value="<?= htmlspecialchars($link['url_destinazione']) ?>" required>

                <label>Titolo <span class="hint">(opzionale)</span></label>
                <input type="text" name="titolo" value="<?= htmlspecialchars($link['titolo'] ?? '') ?>" placeholder="Es. Promozione estate 2025">

                <label>Data di scadenza <span class="hint">(opzionale)</span></label>
                <input type="datetime-local" name="scade_il" value="<?= $link['scade_il'] ? date('Y-m-d\TH:i', strtotime($link['scade_il'])) : '' ?>">

                <div class="toggle">
                    <input type="checkbox" name="attivo" id="attivo" <?= $link['attivo'] ? 'checked' : '' ?>>
                    <label for="attivo">Link attivo</label>
                </div>

                <button type="submit" class="btn">Salva modifiche</button>
                <a href="dashboard.php" class="btn btn-secondary">Annulla</a>
            </form>
        </div>

        <?php if ($link['qr_path']): ?>
        <div class="card">
            <h2>QR Code</h2>
            <div class="qr-box">
                <img src="../<?= htmlspecialchars($link['qr_path']) ?>" alt="QR Code">
                <div class="download-links">
                    <a href="../<?= htmlspecialchars($link['qr_path']) ?>" download>⬇ Scarica PNG</a>
                    <a href="../qrcodes/<?= htmlspecialchars($link['codice']) ?>.svg" download="<?= htmlspecialchars($link['codice']) ?>.svg">⬇ Scarica SVG</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>