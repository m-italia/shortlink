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

// Totale click
$totaleClick = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE link_id = ?");
$totaleClick->execute([$id]);
$totaleClick = $totaleClick->fetchColumn();

// Click per device
$perDevice = $pdo->prepare("SELECT device, COUNT(*) as totale FROM clicks WHERE link_id = ? GROUP BY device ORDER BY totale DESC");
$perDevice->execute([$id]);
$perDevice = $perDevice->fetchAll();

// Click per browser
$perBrowser = $pdo->prepare("SELECT browser, COUNT(*) as totale FROM clicks WHERE link_id = ? GROUP BY browser ORDER BY totale DESC");
$perBrowser->execute([$id]);
$perBrowser = $perBrowser->fetchAll();

// Click per giorno (ultimi 30 giorni)
$perGiorno = $pdo->prepare("
    SELECT DATE(timestamp) as giorno, COUNT(*) as totale 
    FROM clicks WHERE link_id = ? 
    AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(timestamp) ORDER BY giorno ASC
");
$perGiorno->execute([$id]);
$perGiorno = $perGiorno->fetchAll();

// Click per ora del giorno
$perOra = $pdo->prepare("
    SELECT HOUR(timestamp) as ora, COUNT(*) as totale 
    FROM clicks WHERE link_id = ?
    GROUP BY HOUR(timestamp) ORDER BY ora ASC
");
$perOra->execute([$id]);
$perOraRaw = $perOra->fetchAll();

// Riempi tutte le 24 ore (anche quelle senza click)
$perOraCompleto = array_fill(0, 24, 0);
foreach ($perOraRaw as $row) {
    $perOraCompleto[(int)$row['ora']] = (int)$row['totale'];
}

// Ultimi 20 click
$ultimiClick = $pdo->prepare("SELECT * FROM clicks WHERE link_id = ? ORDER BY timestamp DESC LIMIT 20");
$ultimiClick->execute([$id]);
$ultimiClick = $ultimiClick->fetchAll();

// Prepara dati JSON per Chart.js
$giorniLabel = array_map(fn($r) => date('d/m', strtotime($r['giorno'])), $perGiorno);
$giorniData  = array_map(fn($r) => (int)$r['totale'], $perGiorno);

$deviceLabels = array_map(fn($r) => $r['device'], $perDevice);
$deviceData   = array_map(fn($r) => (int)$r['totale'], $perDevice);

$oreLabel = array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiche — ShortLink</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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
        .container { padding: 30px; max-width: 1100px; margin: 0 auto; }

        .info-card {
            background: linear-gradient(135deg, #1a1a2e 0%, #4f46e5 100%);
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
            color: white;
        }
        .info-card h2 { font-size: 20px; margin-bottom: 12px; }
        .info-card .meta { font-size: 14px; opacity: 0.8; line-height: 2; }
        .info-card .short-url { color: #a5b4fc; font-weight: 600; }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            text-align: center;
            border-top: 4px solid #4f46e5;
        }
        .stat-box .numero {
            font-size: 40px;
            font-weight: 800;
            background: linear-gradient(135deg, #4f46e5, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .stat-box .label { font-size: 13px; color: #888; margin-top: 6px; font-weight: 500; }

        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 28px;
            margin-bottom: 24px;
        }
        .card h2 { font-size: 16px; color: #1a1a2e; margin-bottom: 20px; font-weight: 700; }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .chart-container { position: relative; }

        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left;
            padding: 10px 16px;
            font-size: 12px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #f0f0f0;
        }
        td {
            padding: 12px 16px;
            font-size: 14px;
            border-bottom: 1px solid #f9f9f9;
            color: #333;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #ede9fe;
            color: #4f46e5;
        }
        .empty { text-align: center; padding: 40px; color: #bbb; font-size: 14px; }
    </style>
</head>
<body>
    <header>
        <h1>🔗 ShortLink</h1>
        <a href="dashboard.php">← Dashboard</a>
    </header>

    <div class="container">

        <div class="info-card">
            <h2><?= htmlspecialchars($link['titolo'] ?? 'Link senza titolo') ?></h2>
            <div class="meta">
                <strong>Short URL:</strong> 
                <span class="short-url">http://localhost:8888/shortlink/<?= htmlspecialchars($link['codice']) ?></span><br>
                <strong>Destinazione:</strong> <?= htmlspecialchars($link['url_destinazione']) ?><br>
                <strong>Creato il:</strong> <?= formattaData($link['creato_il']) ?>
            </div>
        </div>

        <div class="stats">
            <div class="stat-box">
                <div class="numero"><?= $totaleClick ?></div>
                <div class="label">Click totali</div>
            </div>
            <div class="stat-box">
                <div class="numero"><?= count($perDevice) ?></div>
                <div class="label">Tipi di device</div>
            </div>
            <div class="stat-box">
                <div class="numero"><?= count($perBrowser) ?></div>
                <div class="label">Browser diversi</div>
            </div>
        </div>

        <!-- Grafico andamento click nel tempo -->
        <div class="card">
            <h2>📈 Andamento click — ultimi 30 giorni</h2>
            <?php if (empty($perGiorno)): ?>
                <div class="empty">Nessun click ancora — condividi il link!</div>
            <?php else: ?>
            <div class="chart-container">
                <canvas id="chartTempo" height="80"></canvas>
            </div>
            <?php endif; ?>
        </div>

        <div class="grid-2">
            <!-- Grafico device donut -->
            <div class="card">
                <h2>📱 Device</h2>
                <?php if (empty($perDevice)): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                <div class="chart-container">
                    <canvas id="chartDevice" height="200"></canvas>
                </div>
                <?php endif; ?>
            </div>

            <!-- Grafico ora del giorno -->
            <div class="card">
                <h2>🕐 Click per ora del giorno</h2>
                <?php if ($totaleClick == 0): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                <div class="chart-container">
                    <canvas id="chartOre" height="200"></canvas>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabella ultimi click -->
        <div class="card">
            <h2>🕐 Ultimi click</h2>
            <?php if (empty($ultimiClick)): ?>
                <div class="empty">Nessun click ancora — condividi il link!</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Data e ora</th>
                        <th>Device</th>
                        <th>Browser</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimiClick as $click): ?>
                    <tr>
                        <td><?= formattaData($click['timestamp']) ?></td>
                        <td><span class="badge"><?= htmlspecialchars($click['device'] ?? '—') ?></span></td>
                        <td><?= htmlspecialchars($click['browser'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($click['ip'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>

    <script>
    const violet = '#4f46e5';
    const violetLight = '#818cf8';
    const violetUltraLight = '#ede9fe';

    Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    Chart.defaults.color = '#888';

    <?php if (!empty($perGiorno)): ?>
    // Grafico andamento nel tempo
    new Chart(document.getElementById('chartTempo'), {
        type: 'line',
        data: {
            labels: <?= json_encode($giorniLabel) ?>,
            datasets: [{
                label: 'Click',
                data: <?= json_encode($giorniData) ?>,
                borderColor: violet,
                backgroundColor: 'rgba(79, 70, 229, 0.08)',
                borderWidth: 3,
                pointBackgroundColor: violet,
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a1a2e',
                    titleColor: '#a5b4fc',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 8
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                    grid: { color: '#f0f0f0' }
                }
            }
        }
    });
    <?php endif; ?>

    <?php if (!empty($perDevice)): ?>
    // Grafico device donut
    new Chart(document.getElementById('chartDevice'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($deviceLabels) ?>,
            datasets: [{
                data: <?= json_encode($deviceData) ?>,
                backgroundColor: ['#4f46e5', '#818cf8', '#c7d2fe'],
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { padding: 16, font: { size: 13 } }
                },
                tooltip: {
                    backgroundColor: '#1a1a2e',
                    titleColor: '#a5b4fc',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 8
                }
            }
        }
    });
    <?php endif; ?>

    <?php if ($totaleClick > 0): ?>
    // Grafico ore del giorno
    new Chart(document.getElementById('chartOre'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($oreLabel) ?>,
            datasets: [{
                label: 'Click',
                data: <?= json_encode(array_values($perOraCompleto)) ?>,
                backgroundColor: 'rgba(79, 70, 229, 0.15)',
                borderColor: violet,
                borderWidth: 2,
                borderRadius: 6,
                hoverBackgroundColor: 'rgba(79, 70, 229, 0.4)'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1a1a2e',
                    titleColor: '#a5b4fc',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 8
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { maxRotation: 45, font: { size: 10 } } },
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } }
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>