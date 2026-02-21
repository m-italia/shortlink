<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

// Statistiche generali
$totaleLink = $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn();
$totaleClick = $pdo->query("SELECT COUNT(*) FROM clicks")->fetchColumn();
$linkAttivi = $pdo->query("SELECT COUNT(*) FROM links WHERE attivo = 1")->fetchColumn();

// Click oggi
$clickOggi = $pdo->query("SELECT COUNT(*) FROM clicks WHERE DATE(timestamp) = CURDATE()")->fetchColumn();

// Click questo mese
$clickMese = $pdo->query("SELECT COUNT(*) FROM clicks WHERE MONTH(timestamp) = MONTH(NOW()) AND YEAR(timestamp) = YEAR(NOW())")->fetchColumn();

// Link più cliccato
$linkTop = $pdo->query("
    SELECT l.titolo, l.codice, COUNT(c.id) as totale
    FROM links l
    LEFT JOIN clicks c ON l.id = c.link_id
    GROUP BY l.id
    ORDER BY totale DESC
    LIMIT 1
")->fetch();

// Andamento click ultimi 30 giorni
$perGiorno = $pdo->query("
    SELECT DATE(timestamp) as giorno, COUNT(*) as totale
    FROM clicks
    WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(timestamp)
    ORDER BY giorno ASC
")->fetchAll();

// Device
$perDevice = $pdo->query("
    SELECT device, COUNT(*) as totale
    FROM clicks
    GROUP BY device
    ORDER BY totale DESC
")->fetchAll();
$totaleClickDevice = array_sum(array_column($perDevice, 'totale'));

// Ultimi link creati
$links = $pdo->query("
    SELECT l.*, COUNT(c.id) as click_count
    FROM links l
    LEFT JOIN clicks c ON l.id = c.link_id
    GROUP BY l.id
    ORDER BY l.creato_il DESC
    LIMIT 10
")->fetchAll();

// Prepara dati per Chart.js
$giorniLabel = array_map(fn($r) => date('d/m', strtotime($r['giorno'])), $perGiorno);
$giorniData  = array_map(fn($r) => (int)$r['totale'], $perGiorno);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — ShortLink</title>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Titillium Web', sans-serif;
            background: #f4f4f4;
            min-height: 100vh;
            color: #1a1a1a;
        }

        /* HEADER */
        header {
            background: #000000;
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
        .header-logo img {
            height: 32px;
            display: block;
        }
        header nav {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        header nav span {
            color: #888;
            font-size: 14px;
        }
        header nav a {
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.5px;
            padding: 8px 16px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        header nav a:hover { background: #222; }
        header nav a.btn-nuovo {
            background: #d20a10;
            color: white;
        }
        header nav a.btn-nuovo:hover { background: #b00008; }

        /* CONTAINER */
        .container { padding: 32px 40px; max-width: 1300px; margin: 0 auto; }

        /* PAGE TITLE */
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #000;
            margin-bottom: 24px;
            letter-spacing: 0.5px;
        }
        .page-title span {
            color: #d20a10;
        }

        /* ALERT */
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 600;
        }

        /* HERO STATS */
        .hero-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: white;
            padding: 22px 18px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #d20a10;
            text-align: center;
        }
        .stat-box.top { border-left-color: #000; }
        .stat-box .numero {
            font-size: 34px;
            font-weight: 900;
            color: #d20a10;
            line-height: 1;
        }
        .stat-box.top .numero {
            font-size: 16px;
            color: #000;
            font-weight: 700;
        }
        .stat-box .label {
            font-size: 11px;
            color: #888;
            margin-top: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* GRAFICI */
        .grid-charts {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 24px;
        }
        .card h2 {
            font-size: 14px;
            color: #000;
            margin-bottom: 20px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-left: 3px solid #d20a10;
            padding-left: 10px;
        }

        /* DEVICE BARS */
        .bar-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .bar-label { font-size: 13px; color: #333; width: 70px; font-weight: 600; }
        .bar-wrap { flex: 1; background: #f0f0f0; border-radius: 20px; height: 8px; }
        .bar-fill { height: 8px; border-radius: 20px; background: #d20a10; }
        .bar-count { font-size: 13px; color: #888; width: 30px; text-align: right; font-weight: 600; }

        /* TABELLA */
        .card-full { margin-bottom: 24px; }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .card-header h2 { margin: 0; }
        .btn {
            padding: 8px 18px;
            background: #d20a10;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            transition: background 0.2s;
        }
        .btn:hover { background: #b00008; }
        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left;
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid #f0f0f0;
        }
        td {
            padding: 13px 16px;
            font-size: 14px;
            border-bottom: 1px solid #f5f5f5;
            color: #333;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge.attivo { background: #000; color: #fff; }
        .badge.inattivo { background: #f0f0f0; color: #888; }
        .short-url { color: #d20a10; font-weight: 700; text-decoration: none; }
        .short-url:hover { text-decoration: underline; }
        .actions a {
            color: #333;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            margin-right: 12px;
            transition: color 0.2s;
        }
        .actions a:hover { color: #d20a10; }
        .actions a.del { color: #ccc; }
        .actions a.del:hover { color: #d20a10; }
        .empty { text-align: center; padding: 40px; color: #bbb; font-size: 14px; }
    </style>
</head>
<body>
    <header>
        <div class="header-logo">
            <img src="../assets/logo.svg" alt="Logo">
        </div>
        <nav>
            <span>Ciao, <?= htmlspecialchars($_SESSION['username']) ?>!</span>
            <a href="create.php" class="btn-nuovo">+ Nuovo link</a>
            <a href="logout.php">Esci</a>
        </nav>
    </header>

    <div class="container">

        <div class="page-title">Dashboard <span>·</span> ShortLink</div>

        <?php if (isset($_GET['eliminato'])): ?>
        <div class="alert-success">✅ Link eliminato con successo.</div>
        <?php endif; ?>

        <!-- Hero Stats -->
        <div class="hero-stats">
            <div class="stat-box">
                <div class="numero"><?= $totaleLink ?></div>
                <div class="label">Link totali</div>
            </div>
            <div class="stat-box">
                <div class="numero"><?= $linkAttivi ?></div>
                <div class="label">Link attivi</div>
            </div>
            <div class="stat-box">
                <div class="numero"><?= $clickOggi ?></div>
                <div class="label">Click oggi</div>
            </div>
            <div class="stat-box">
                <div class="numero"><?= $clickMese ?></div>
                <div class="label">Click questo mese</div>
            </div>
            <div class="stat-box top">
                <div class="numero"><?= $linkTop ? htmlspecialchars($linkTop['titolo'] ?: '/' . $linkTop['codice']) : '—' ?></div>
                <div class="label">🏆 Link più cliccato</div>
            </div>
        </div>

        <!-- Grafici -->
        <div class="grid-charts">
            <div class="card">
                <h2>📈 Andamento click — ultimi 30 giorni</h2>
                <?php if (empty($perGiorno)): ?>
                    <div class="empty">Nessun click ancora</div>
                <?php else: ?>
                    <canvas id="chartLinea" height="120"></canvas>
                <?php endif; ?>
            </div>
            <div class="card">
                <h2>📱 Device</h2>
                <?php if (empty($perDevice)): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                    <?php foreach ($perDevice as $d): ?>
                    <div class="bar-item">
                        <div class="bar-label"><?= htmlspecialchars($d['device']) ?></div>
                        <div class="bar-wrap">
                            <div class="bar-fill" style="width: <?= $totaleClickDevice > 0 ? ($d['totale'] / $totaleClickDevice) * 100 : 0 ?>%"></div>
                        </div>
                        <div class="bar-count"><?= $d['totale'] ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($perGiorno)): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h2>📊 Click per giorno — ultimi 30 giorni</h2>
            <canvas id="chartBarre" height="80"></canvas>
        </div>
        <?php endif; ?>

        <!-- Tabella link -->
        <div class="card card-full">
            <div class="card-header">
                <h2>Link recenti</h2>
                <a href="create.php" class="btn">+ Nuovo link</a>
            </div>
            <?php if (empty($links)): ?>
                <div class="empty">Nessun link ancora. <a href="create.php" style="color:#d20a10;">Crea il primo!</a></div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Short URL</th>
                        <th>Click</th>
                        <th>Stato</th>
                        <th>Creato il</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $link): ?>
                    <tr>
                        <td><?= htmlspecialchars($link['titolo'] ?? '—') ?></td>
                        <td>
                            <a class="short-url" href="http://localhost:8888/shortlink/<?= $link['codice'] ?>" target="_blank">
                                /<?= $link['codice'] ?>
                            </a>
                        </td>
                        <td><strong><?= $link['click_count'] ?></strong></td>
                        <td>
                            <span class="badge <?= $link['attivo'] ? 'attivo' : 'inattivo' ?>">
                                <?= $link['attivo'] ? 'Attivo' : 'Inattivo' ?>
                            </span>
                        </td>
                        <td><?= formattaData($link['creato_il']) ?></td>
                        <td class="actions">
                            <a href="edit.php?id=<?= $link['id'] ?>">Modifica</a>
                            <a href="stats.php?id=<?= $link['id'] ?>">Statistiche</a>
                            <a href="delete.php?id=<?= $link['id'] ?>" class="del" onclick="return confirm('Sei sicuro di voler eliminare questo link?')">Elimina</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>

    <script>
    Chart.defaults.font.family = "'Titillium Web', sans-serif";
    Chart.defaults.color = '#888';

    <?php if (!empty($perGiorno)): ?>
    new Chart(document.getElementById('chartLinea'), {
        type: 'line',
        data: {
            labels: <?= json_encode($giorniLabel) ?>,
            datasets: [{
                label: 'Click',
                data: <?= json_encode($giorniData) ?>,
                borderColor: '#d20a10',
                backgroundColor: 'rgba(210, 10, 16, 0.06)',
                borderWidth: 3,
                pointBackgroundColor: '#d20a10',
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#000',
                    titleColor: '#d20a10',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 6
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f5f5f5' } }
            }
        }
    });

    new Chart(document.getElementById('chartBarre'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($giorniLabel) ?>,
            datasets: [{
                label: 'Click',
                data: <?= json_encode($giorniData) ?>,
                backgroundColor: 'rgba(210, 10, 16, 0.12)',
                borderColor: '#d20a10',
                borderWidth: 2,
                borderRadius: 4,
                hoverBackgroundColor: 'rgba(210, 10, 16, 0.3)'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#000',
                    titleColor: '#d20a10',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 6
                }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f5f5f5' } }
            }
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>