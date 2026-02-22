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

$totaleClick = $pdo->prepare("SELECT COUNT(*) FROM clicks WHERE link_id = ?");
$totaleClick->execute([$id]);
$totaleClick = $totaleClick->fetchColumn();

$perDevice = $pdo->prepare("SELECT device, COUNT(*) as totale FROM clicks WHERE link_id = ? GROUP BY device ORDER BY totale DESC");
$perDevice->execute([$id]);
$perDevice = $perDevice->fetchAll();

$perBrowser = $pdo->prepare("SELECT browser, COUNT(*) as totale FROM clicks WHERE link_id = ? GROUP BY browser ORDER BY totale DESC");
$perBrowser->execute([$id]);
$perBrowser = $perBrowser->fetchAll();

$perGiorno = $pdo->prepare("SELECT DATE(timestamp) as giorno, COUNT(*) as totale FROM clicks WHERE link_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(timestamp) ORDER BY giorno ASC");
$perGiorno->execute([$id]);
$perGiorno = $perGiorno->fetchAll();

$perOra = $pdo->prepare("SELECT HOUR(timestamp) as ora, COUNT(*) as totale FROM clicks WHERE link_id = ? GROUP BY HOUR(timestamp) ORDER BY ora ASC");
$perOra->execute([$id]);
$perOraRaw = $perOra->fetchAll();

$perOraCompleto = array_fill(0, 24, 0);
foreach ($perOraRaw as $row) { $perOraCompleto[(int)$row['ora']] = (int)$row['totale']; }

$perPaese = $pdo->prepare("SELECT paese, COUNT(*) as totale FROM clicks WHERE link_id = ? AND paese IS NOT NULL AND paese != '' GROUP BY paese ORDER BY totale DESC LIMIT 10");
$perPaese->execute([$id]);
$perPaese = $perPaese->fetchAll();

// Sorgenti UTM
$perSorgente = $pdo->prepare("
    SELECT
        COALESCE(sorgente, 'Diretto') as sorgente,
        COUNT(*) as totale
    FROM clicks
    WHERE link_id = ?
    GROUP BY sorgente
    ORDER BY totale DESC
");
$perSorgente->execute([$id]);
$perSorgente = $perSorgente->fetchAll();
$totaleSorgente = array_sum(array_column($perSorgente, 'totale'));

$ultimiClick = $pdo->prepare("SELECT * FROM clicks WHERE link_id = ? ORDER BY timestamp DESC LIMIT 20");
$ultimiClick->execute([$id]);
$ultimiClick = $ultimiClick->fetchAll();

$giorniLabel  = array_map(fn($r) => date('d/m', strtotime($r['giorno'])), $perGiorno);
$giorniData   = array_map(fn($r) => (int)$r['totale'], $perGiorno);
$oreLabel     = array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23));
$totaleDevice = array_sum(array_column($perDevice, 'totale'));

// Dati grafico sorgenti
$sorgenteLabels = array_map(fn($r) => ucfirst($r['sorgente']), $perSorgente);
$sorgenteData   = array_map(fn($r) => (int)$r['totale'], $perSorgente);
$sorgenteColori = ['#d20a10','#000000','#555555','#888888','#bbbbbb','#e0e0e0','#f4f4f4'];

// Canali attivi per i link UTM pronti
$canaliDisponibili = [
    'instagram'  => 'Instagram',
    'facebook'   => 'Facebook',
    'linkedin'   => 'LinkedIn',
    'youtube'    => 'YouTube',
    'whatsapp'   => 'WhatsApp',
    'newsletter' => 'Newsletter',
];
$baseUrl     = 'http://localhost:8888/shortlink/' . htmlspecialchars($link['codice']);
$canaliAttivi = array_filter($canaliDisponibili, fn($k) => !empty($link['canale_' . $k]), ARRAY_FILTER_USE_KEY);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiche — ShortLink</title>
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@300;400;600;700;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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

        .container { padding: 32px 40px; max-width: 1200px; margin: 0 auto; }
        .page-title { font-size: 24px; font-weight: 700; color: #000; margin-bottom: 24px; }
        .page-title span { color: #d20a10; }

        .info-card {
            background: #000; border-radius: 12px; padding: 28px; margin-bottom: 24px; color: white;
            border-left: 5px solid #d20a10;
        }
        .info-card h2 { font-size: 20px; font-weight: 700; margin-bottom: 12px; }
        .info-card .meta { font-size: 14px; opacity: 0.7; line-height: 2.2; }
        .info-card .short-url { color: #d20a10; font-weight: 700; }

        .hero-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-box { background: white; padding: 22px 18px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid #d20a10; text-align: center; }
        .stat-box .numero { font-size: 40px; font-weight: 900; color: #d20a10; line-height: 1; }
        .stat-box .label { font-size: 11px; color: #888; margin-top: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }

        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 24px; margin-bottom: 24px; }
        .card h2 { font-size: 13px; color: #000; margin-bottom: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; border-left: 3px solid #d20a10; padding-left: 10px; }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-bottom: 24px; }

        .bar-item { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .bar-label { font-size: 13px; color: #333; width: 80px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-wrap { flex: 1; background: #f0f0f0; border-radius: 20px; height: 8px; }
        .bar-fill { height: 8px; border-radius: 20px; background: #d20a10; }
        .bar-count { font-size: 13px; color: #888; width: 30px; text-align: right; font-weight: 600; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 16px; font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #f0f0f0; }
        td { padding: 13px 16px; font-size: 14px; border-bottom: 1px solid #f5f5f5; color: #333; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; background: #000; color: #fff; }
        .badge-sorgente { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; background: #f0f0f0; color: #333; }
        .empty { text-align: center; padding: 40px; color: #bbb; font-size: 14px; }

        /* Grafico sorgenti — legenda custom */
        .sorgente-legenda { margin-top: 20px; }
        .sorgente-item { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .sorgente-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
        .sorgente-nome { font-size: 13px; font-weight: 700; color: #333; flex: 1; }
        .sorgente-count { font-size: 13px; color: #888; font-weight: 600; }
        .sorgente-perc { font-size: 11px; color: #bbb; font-weight: 600; margin-left: 4px; }

        /* Link UTM pronti */
        .utm-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding: 10px 14px; background: #f9f9f9; border-radius: 8px; border: 1px solid #eee; }
        .utm-label { font-size: 13px; font-weight: 700; color: #333; width: 100px; flex-shrink: 0; display: flex; align-items: center; gap: 8px; }
        .utm-dot { width: 8px; height: 8px; border-radius: 50%; background: #d20a10; flex-shrink: 0; }
        .utm-url { font-size: 12px; color: #666; flex: 1; word-break: break-all; font-family: monospace; }
        .btn-copia { padding: 6px 14px; background: #000; color: #fff; border: none; border-radius: 5px; font-size: 12px; font-weight: 700; font-family: 'Titillium Web', sans-serif; cursor: pointer; letter-spacing: 0.5px; transition: background 0.2s; flex-shrink: 0; }
        .btn-copia:hover { background: #333; }
        .btn-copia.copiato { background: #16a34a; }

        .chart-wrap { display: flex; align-items: center; gap: 24px; }
        .chart-wrap canvas { max-width: 180px; max-height: 180px; }
    </style>
</head>
<body>
    <header>
        <div class="header-logo"><a href="dashboard.php"><img src="../assets/logo.svg" alt="Logo"></a></div>
        <a href="dashboard.php">← Dashboard</a>
    </header>

    <div class="container">
        <div class="page-title">Statistiche <span>·</span> <?= htmlspecialchars($link['titolo'] ?? '/' . $link['codice']) ?></div>

        <div class="info-card">
            <h2><?= htmlspecialchars($link['titolo'] ?? 'Link senza titolo') ?></h2>
            <div class="meta">
                <strong>Short URL:</strong> <span class="short-url">http://localhost:8888/shortlink/<?= htmlspecialchars($link['codice']) ?></span><br>
                <strong>Destinazione:</strong> <?= htmlspecialchars($link['url_destinazione']) ?><br>
                <strong>Creato il:</strong> <?= formattaData($link['creato_il']) ?>
            </div>
        </div>

        <div class="hero-stats">
            <div class="stat-box"><div class="numero"><?= $totaleClick ?></div><div class="label">Click totali</div></div>
            <div class="stat-box"><div class="numero"><?= count($perDevice) ?></div><div class="label">Tipi di device</div></div>
            <div class="stat-box"><div class="numero"><?= count($perBrowser) ?></div><div class="label">Browser diversi</div></div>
        </div>

        <div class="card">
            <h2>Andamento click — ultimi 30 giorni</h2>
            <?php if (empty($perGiorno)): ?>
                <div class="empty">Nessun click ancora</div>
            <?php else: ?>
                <canvas id="chartTempo" height="80"></canvas>
            <?php endif; ?>
        </div>

        <div class="grid-2">
            <div class="card">
                <h2>Device</h2>
                <?php if (empty($perDevice)): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                    <?php foreach ($perDevice as $d): ?>
                    <div class="bar-item">
                        <div class="bar-label"><?= htmlspecialchars($d['device']) ?></div>
                        <div class="bar-wrap"><div class="bar-fill" style="width:<?= $totaleDevice > 0 ? ($d['totale']/$totaleDevice)*100 : 0 ?>%"></div></div>
                        <div class="bar-count"><?= $d['totale'] ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card">
                <h2>Click per ora del giorno</h2>
                <?php if ($totaleClick == 0): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                    <canvas id="chartOre" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sorgenti UTM -->
        <div class="grid-2">
            <div class="card">
                <h2>Sorgenti di traffico</h2>
                <?php if (empty($perSorgente) || $totaleClick == 0): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                    <div class="chart-wrap">
                        <canvas id="chartSorgenti" width="180" height="180"></canvas>
                        <div class="sorgente-legenda">
                            <?php foreach ($perSorgente as $i => $s): ?>
                            <div class="sorgente-item">
                                <div class="sorgente-dot" style="background:<?= $sorgenteColori[$i % count($sorgenteColori)] ?>"></div>
                                <div class="sorgente-nome"><?= htmlspecialchars(ucfirst($s['sorgente'])) ?></div>
                                <div class="sorgente-count"><?= $s['totale'] ?><span class="sorgente-perc"><?= $totaleSorgente > 0 ? ' ' . round($s['totale'] / $totaleSorgente * 100) . '%' : '' ?></span></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($canaliAttivi)): ?>
            <div class="card">
                <h2>Link pronti per canale</h2>
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
        </div>

        <?php if (!empty($perPaese)): ?>
        <div class="card">
            <h2>Paesi</h2>
            <?php
            $totalePaese = array_sum(array_column($perPaese, 'totale'));
            foreach ($perPaese as $p): ?>
            <div class="bar-item">
                <div class="bar-label"><?= htmlspecialchars($p['paese'] ?: 'Sconosciuto') ?></div>
                <div class="bar-wrap"><div class="bar-fill" style="width:<?= $totalePaese > 0 ? ($p['totale']/$totalePaese)*100 : 0 ?>%"></div></div>
                <div class="bar-count"><?= $p['totale'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Ultimi click</h2>
            <?php if (empty($ultimiClick)): ?>
                <div class="empty">Nessun click ancora</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Data e ora</th>
                        <th>Sorgente</th>
                        <th>Device</th>
                        <th>Browser</th>
                        <th>Paese</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimiClick as $click): ?>
                    <tr>
                        <td><?= formattaData($click['timestamp']) ?></td>
                        <td><span class="badge-sorgente"><?= htmlspecialchars(ucfirst($click['sorgente'] ?? 'Diretto')) ?></span></td>
                        <td><span class="badge"><?= htmlspecialchars($click['device'] ?? '—') ?></span></td>
                        <td><?= htmlspecialchars($click['browser'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($click['paese'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($click['ip'] ?? '—') ?></td>
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
    new Chart(document.getElementById('chartTempo'), {
        type: 'line',
        data: { labels: <?= json_encode($giorniLabel) ?>, datasets: [{ label: 'Click', data: <?= json_encode($giorniData) ?>, borderColor: '#d20a10', backgroundColor: 'rgba(210,10,16,0.06)', borderWidth: 3, pointBackgroundColor: '#d20a10', pointRadius: 4, fill: true, tension: 0.4 }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#000', titleColor: '#d20a10', bodyColor: '#fff', padding: 12, cornerRadius: 6 } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f5f5f5' } } } }
    });
    <?php endif; ?>

    <?php if ($totaleClick > 0): ?>
    new Chart(document.getElementById('chartOre'), {
        type: 'bar',
        data: { labels: <?= json_encode($oreLabel) ?>, datasets: [{ label: 'Click', data: <?= json_encode(array_values($perOraCompleto)) ?>, backgroundColor: 'rgba(210,10,16,0.12)', borderColor: '#d20a10', borderWidth: 2, borderRadius: 4 }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#000', titleColor: '#d20a10', bodyColor: '#fff', padding: 12, cornerRadius: 6 } }, scales: { x: { grid: { display: false }, ticks: { maxRotation: 45, font: { size: 9 } } }, y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f5f5f5' } } } }
    });

    <?php if (!empty($perSorgente)): ?>
    new Chart(document.getElementById('chartSorgenti'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($sorgenteLabels) ?>,
            datasets: [{
                data: <?= json_encode($sorgenteData) ?>,
                backgroundColor: <?= json_encode(array_slice($sorgenteColori, 0, count($sorgenteLabels))) ?>,
                borderWidth: 2,
                borderColor: '#fff',
            }]
        },
        options: {
            responsive: false,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: { backgroundColor: '#000', titleColor: '#d20a10', bodyColor: '#fff', padding: 12, cornerRadius: 6 }
            }
        }
    });
    <?php endif; ?>
    <?php endif; ?>

    function copiaUtm(btn, url) {
        navigator.clipboard.writeText(url).then(() => {
            btn.textContent = 'Copiato!';
            btn.classList.add('copiato');
            setTimeout(() => { btn.textContent = 'Copia'; btn.classList.remove('copiato'); }, 2000);
        });
    }
    </script>
</body>
</html>
