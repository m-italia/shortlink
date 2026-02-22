<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();

// Ricerca
$search = trim($_GET['q'] ?? '');
$searchParam = '%' . $search . '%';

// Paginazione
$perPagina = 10;
$paginaCorrente = max(1, (int)($_GET['p'] ?? 1));
$offset = ($paginaCorrente - 1) * $perPagina;

$totaleLink  = $pdo->query("SELECT COUNT(*) FROM links")->fetchColumn();
$totaleClick = $pdo->query("SELECT COUNT(*) FROM clicks")->fetchColumn();
$linkAttivi  = $pdo->query("SELECT COUNT(*) FROM links WHERE attivo = 1")->fetchColumn();
$clickOggi   = $pdo->query("SELECT COUNT(*) FROM clicks WHERE DATE(timestamp) = CURDATE()")->fetchColumn();
$clickMese   = $pdo->query("SELECT COUNT(*) FROM clicks WHERE MONTH(timestamp) = MONTH(NOW()) AND YEAR(timestamp) = YEAR(NOW())")->fetchColumn();

$linkTop = $pdo->query("
    SELECT l.titolo, l.codice, COUNT(c.id) as totale
    FROM links l LEFT JOIN clicks c ON l.id = c.link_id
    GROUP BY l.id ORDER BY totale DESC LIMIT 1
")->fetch();

$perGiorno = $pdo->query("
    SELECT DATE(timestamp) as giorno, COUNT(*) as totale
    FROM clicks WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(timestamp) ORDER BY giorno ASC
")->fetchAll();

$perDevice = $pdo->query("
    SELECT device, COUNT(*) as totale FROM clicks GROUP BY device ORDER BY totale DESC
")->fetchAll();
$totaleClickDevice = array_sum(array_column($perDevice, 'totale'));

// Sorgenti totali
$perSorgente = $pdo->query("
    SELECT
        COALESCE(sorgente, 'Diretto') as sorgente,
        COUNT(*) as totale
    FROM clicks
    GROUP BY sorgente
    ORDER BY totale DESC
")->fetchAll();
$totaleSorgente = array_sum(array_column($perSorgente, 'totale'));

if ($search !== '') {
    $stmtCount = $pdo->prepare("
        SELECT COUNT(DISTINCT l.id)
        FROM links l
        WHERE l.titolo LIKE :q OR l.codice LIKE :q2
    ");
    $stmtCount->execute([':q' => $searchParam, ':q2' => $searchParam]);
    $totaleRisultati = (int)$stmtCount->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT l.*, COUNT(c.id) as click_count
        FROM links l LEFT JOIN clicks c ON l.id = c.link_id
        WHERE l.titolo LIKE :q OR l.codice LIKE :q2
        GROUP BY l.id ORDER BY l.creato_il DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':q', $searchParam);
    $stmt->bindValue(':q2', $searchParam);
    $stmt->bindValue(':limit', $perPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $links = $stmt->fetchAll();
} else {
    $totaleRisultati = (int)$totaleLink;

    $stmt = $pdo->prepare("
        SELECT l.*, COUNT(c.id) as click_count
        FROM links l LEFT JOIN clicks c ON l.id = c.link_id
        GROUP BY l.id ORDER BY l.creato_il DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $links = $stmt->fetchAll();
}

$totalePagine = (int)ceil($totaleRisultati / $perPagina);

function buildPaginaUrl(int $p, string $q): string {
    $params = ['p' => $p];
    if ($q !== '') $params['q'] = $q;
    return 'dashboard.php?' . http_build_query($params);
}

$giorniLabel    = array_map(fn($r) => date('d/m', strtotime($r['giorno'])), $perGiorno);
$giorniData     = array_map(fn($r) => (int)$r['totale'], $perGiorno);
$sorgenteLabels = array_map(fn($r) => ucfirst($r['sorgente']), $perSorgente);
$sorgenteData   = array_map(fn($r) => (int)$r['totale'], $perSorgente);
$sorgenteColori = ['#d20a10','#000000','#555555','#888888','#bbbbbb','#e0e0e0'];
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
        body { font-family: 'Titillium Web', sans-serif; background: #f4f4f4; min-height: 100vh; color: #1a1a1a; }

        header {
            background: #000; padding: 0 40px; display: flex; align-items: center;
            justify-content: space-between; height: 64px; position: sticky; top: 0; z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .header-logo a { display: block; line-height: 0; }
        .header-logo img { height: 32px; display: block; }
        header nav { display: flex; align-items: center; gap: 24px; }
        header nav span { color: #888; font-size: 14px; }
        header nav a { color: #fff; text-decoration: none; font-size: 14px; font-weight: 600; letter-spacing: 0.5px; padding: 8px 16px; border-radius: 6px; transition: background 0.2s; }
        header nav a:hover { background: #222; }
        header nav a.btn-nuovo { background: #d20a10; color: white; }
        header nav a.btn-nuovo:hover { background: #b00008; }

        .container { padding: 32px 40px; max-width: 1300px; margin: 0 auto; }
        .page-title { font-size: 24px; font-weight: 700; color: #000; margin-bottom: 24px; letter-spacing: 0.5px; }
        .page-title span { color: #d20a10; }

        .alert-success { background: #d1fae5; color: #065f46; padding: 12px 20px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; font-weight: 600; }

        .hero-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-box { background: white; padding: 22px 18px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border-left: 4px solid #d20a10; text-align: center; }
        .stat-box.top { border-left-color: #000; }
        .stat-box .numero { font-size: 34px; font-weight: 900; color: #d20a10; line-height: 1; }
        .stat-box.top .numero { font-size: 16px; color: #000; font-weight: 700; }
        .stat-box .label { font-size: 11px; color: #888; margin-top: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }

        /* Griglia grafici: linea grande | device | sorgenti */
        .grid-charts { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px; }
        .grid-charts-bottom { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }

        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 24px; }
        .card h2 { font-size: 13px; color: #000; margin-bottom: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; border-left: 3px solid #d20a10; padding-left: 10px; }

        .bar-item { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .bar-label { font-size: 13px; color: #333; width: 70px; font-weight: 600; }
        .bar-wrap { flex: 1; background: #f0f0f0; border-radius: 20px; height: 8px; }
        .bar-fill { height: 8px; border-radius: 20px; background: #d20a10; }
        .bar-count { font-size: 13px; color: #888; width: 30px; text-align: right; font-weight: 600; }

        /* Sorgenti */
        .chart-wrap { display: flex; align-items: center; gap: 20px; }
        .chart-wrap canvas { flex-shrink: 0; }
        .sorgente-legenda { flex: 1; }
        .sorgente-item { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .sorgente-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .sorgente-nome { font-size: 13px; font-weight: 700; color: #333; flex: 1; }
        .sorgente-count { font-size: 13px; color: #888; font-weight: 600; }
        .sorgente-perc { font-size: 11px; color: #bbb; font-weight: 600; margin-left: 4px; }

        .card-full { margin-bottom: 24px; }
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .card-header h2 { margin: 0; }
        .btn { padding: 8px 18px; background: #d20a10; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 700; letter-spacing: 0.5px; transition: background 0.2s; }
        .btn:hover { background: #b00008; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px 16px; font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #f0f0f0; }
        td { padding: 13px 16px; font-size: 14px; border-bottom: 1px solid #f5f5f5; color: #333; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge.attivo { background: #000; color: #fff; }
        .badge.inattivo { background: #f0f0f0; color: #888; }
        .short-url { color: #d20a10; font-weight: 700; text-decoration: none; }
        .short-url:hover { text-decoration: underline; }
        .actions a { color: #333; text-decoration: none; font-size: 13px; font-weight: 600; margin-right: 12px; transition: color 0.2s; }
        .actions a:hover { color: #d20a10; }
        .actions a.del { color: #ccc; }
        .actions a.del:hover { color: #d20a10; }
        .empty { text-align: center; padding: 40px; color: #bbb; font-size: 14px; }

        /* Paginazione */
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 18px 16px 4px; border-top: 1px solid #f0f0f0; margin-top: 4px; }
        .pagination-info { font-size: 13px; color: #888; font-weight: 600; }
        .pagination-info strong { color: #333; }
        .pagination-nav { display: flex; align-items: center; gap: 6px; }
        .pag-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 10px; border-radius: 6px; font-family: 'Titillium Web', sans-serif; font-size: 13px; font-weight: 700; text-decoration: none; transition: background 0.2s, color 0.2s; color: #333; background: #f0f0f0; letter-spacing: 0.3px; }
        .pag-btn:hover { background: #e0e0e0; color: #000; }
        .pag-btn.attiva { background: #d20a10; color: #fff; cursor: default; }
        .pag-btn.disabilitato { opacity: 0.35; pointer-events: none; }
        .pag-btn.prev-next { background: #000; color: #fff; padding: 0 14px; }
        .pag-btn.prev-next:hover { background: #222; }
        .pag-ellipsis { font-size: 13px; color: #aaa; padding: 0 4px; font-weight: 700; }
    </style>
</head>
<body>
    <header>
        <div class="header-logo">
            <a href="dashboard.php"><img src="../assets/logo.svg" alt="Logo"></a>
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
        <div class="alert-success">Link eliminato con successo.</div>
        <?php endif; ?>

        <div class="hero-stats">
            <div class="stat-box"><div class="numero"><?= $totaleLink ?></div><div class="label">Link totali</div></div>
            <div class="stat-box"><div class="numero"><?= $linkAttivi ?></div><div class="label">Link attivi</div></div>
            <div class="stat-box"><div class="numero"><?= $clickOggi ?></div><div class="label">Click oggi</div></div>
            <div class="stat-box"><div class="numero"><?= $clickMese ?></div><div class="label">Click questo mese</div></div>
            <div class="stat-box top">
                <div class="numero"><?= $linkTop ? htmlspecialchars($linkTop['titolo'] ?: '/' . $linkTop['codice']) : '—' ?></div>
                <div class="label">Link più cliccato</div>
            </div>
        </div>

        <!-- Riga 1: andamento + device -->
        <div class="grid-charts">
            <div class="card">
                <h2>Andamento click — ultimi 30 giorni</h2>
                <?php if (empty($perGiorno)): ?>
                    <div class="empty">Nessun click ancora</div>
                <?php else: ?>
                    <canvas id="chartLinea" height="120"></canvas>
                <?php endif; ?>
            </div>
            <div class="card">
                <h2>Device</h2>
                <?php if (empty($perDevice)): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                    <?php foreach ($perDevice as $d): ?>
                    <div class="bar-item">
                        <div class="bar-label"><?= htmlspecialchars($d['device']) ?></div>
                        <div class="bar-wrap"><div class="bar-fill" style="width:<?= $totaleClickDevice > 0 ? ($d['totale']/$totaleClickDevice)*100 : 0 ?>%"></div></div>
                        <div class="bar-count"><?= $d['totale'] ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Riga 2: barre + sorgenti -->
        <div class="grid-charts-bottom">
            <?php if (!empty($perGiorno)): ?>
            <div class="card">
                <h2>Click per giorno — ultimi 30 giorni</h2>
                <canvas id="chartBarre" height="120"></canvas>
            </div>
            <?php endif; ?>

            <div class="card">
                <h2>Sorgenti di traffico — totale</h2>
                <?php if (empty($perSorgente) || $totaleSorgente == 0): ?>
                    <div class="empty">Nessun dato ancora</div>
                <?php else: ?>
                    <div class="chart-wrap">
                        <canvas id="chartSorgenti" width="160" height="160"></canvas>
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
        </div>

        <!-- Ricerca -->
        <form method="get" action="" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
            <input
                type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                placeholder="Cerca per nome o slug..."
                style="flex:1; padding:10px 14px; border:1px solid #ddd; border-radius:8px; font-family:'Titillium Web',sans-serif; font-size:14px; outline:none; transition:border-color .2s;"
                onfocus="this.style.borderColor='#d20a10'" onblur="this.style.borderColor='#ddd'"
            >
            <button type="submit" style="padding:10px 20px; background:#d20a10; color:#fff; border:none; border-radius:8px; font-family:'Titillium Web',sans-serif; font-size:14px; font-weight:600; cursor:pointer; letter-spacing:.5px;">Cerca</button>
            <?php if ($search !== ''): ?>
                <a href="dashboard.php" style="padding:10px 16px; background:#000; color:#fff; border-radius:8px; font-family:'Titillium Web',sans-serif; font-size:13px; text-decoration:none; font-weight:600;">Reset</a>
            <?php endif; ?>
        </form>
        <?php if ($search !== ''): ?>
            <p style="font-size:13px; color:#666; margin-bottom:12px;"><?= $totaleRisultati ?> risultati per "<strong><?= htmlspecialchars($search) ?></strong>"</p>
        <?php endif; ?>

        <div class="card card-full">
            <div class="card-header">
                <h2><?= $search !== '' ? 'Risultati ricerca' : 'Tutti i link' ?></h2>
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
                        <td><a class="short-url" href="http://localhost:8888/shortlink/<?= $link['codice'] ?>" target="_blank">/<?= $link['codice'] ?></a></td>
                        <td><strong><?= $link['click_count'] ?></strong></td>
                        <td><span class="badge <?= $link['attivo'] ? 'attivo' : 'inattivo' ?>"><?= $link['attivo'] ? 'Attivo' : 'Inattivo' ?></span></td>
                        <td><?= formattaData($link['creato_il']) ?></td>
                        <td class="actions">
                            <a href="edit.php?id=<?= $link['id'] ?>">Modifica</a>
                            <a href="stats.php?id=<?= $link['id'] ?>">Statistiche</a>
                            <a href="delete.php?id=<?= $link['id'] ?>" class="del" onclick="return confirm('Eliminare questo link?')">Elimina</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalePagine > 1): ?>
            <div class="pagination">
                <div class="pagination-info">
                    Pagina <strong><?= $paginaCorrente ?></strong> di <strong><?= $totalePagine ?></strong>
                    &nbsp;&mdash;&nbsp; <?= $totaleRisultati ?> link totali
                </div>
                <div class="pagination-nav">
                    <?php if ($paginaCorrente > 1): ?>
                        <a href="<?= buildPaginaUrl($paginaCorrente - 1, $search) ?>" class="pag-btn prev-next">&larr; Prec.</a>
                    <?php else: ?>
                        <span class="pag-btn prev-next disabilitato">&larr; Prec.</span>
                    <?php endif; ?>
                    <?php
                    $finestra = 2;
                    $mostrate = [];
                    for ($i = 1; $i <= $totalePagine; $i++) {
                        if ($i === 1 || $i === $totalePagine || ($i >= $paginaCorrente - $finestra && $i <= $paginaCorrente + $finestra)) {
                            $mostrate[] = $i;
                        }
                    }
                    $precedente = null;
                    foreach ($mostrate as $num):
                        if ($precedente !== null && $num - $precedente > 1): ?>
                            <span class="pag-ellipsis">…</span>
                        <?php endif;
                        if ($num === $paginaCorrente): ?>
                            <span class="pag-btn attiva"><?= $num ?></span>
                        <?php else: ?>
                            <a href="<?= buildPaginaUrl($num, $search) ?>" class="pag-btn"><?= $num ?></a>
                        <?php endif;
                        $precedente = $num;
                    endforeach; ?>
                    <?php if ($paginaCorrente < $totalePagine): ?>
                        <a href="<?= buildPaginaUrl($paginaCorrente + 1, $search) ?>" class="pag-btn prev-next">Succ. &rarr;</a>
                    <?php else: ?>
                        <span class="pag-btn prev-next disabilitato">Succ. &rarr;</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    Chart.defaults.font.family = "'Titillium Web', sans-serif";
    Chart.defaults.color = '#888';

    <?php if (!empty($perGiorno)): ?>
    new Chart(document.getElementById('chartLinea'), {
        type: 'line',
        data: { labels: <?= json_encode($giorniLabel) ?>, datasets: [{ label: 'Click', data: <?= json_encode($giorniData) ?>, borderColor: '#d20a10', backgroundColor: 'rgba(210,10,16,0.06)', borderWidth: 3, pointBackgroundColor: '#d20a10', pointRadius: 4, pointHoverRadius: 6, fill: true, tension: 0.4 }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#000', titleColor: '#d20a10', bodyColor: '#fff', padding: 12, cornerRadius: 6 } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f5f5f5' } } } }
    });
    new Chart(document.getElementById('chartBarre'), {
        type: 'bar',
        data: { labels: <?= json_encode($giorniLabel) ?>, datasets: [{ label: 'Click', data: <?= json_encode($giorniData) ?>, backgroundColor: 'rgba(210,10,16,0.12)', borderColor: '#d20a10', borderWidth: 2, borderRadius: 4, hoverBackgroundColor: 'rgba(210,10,16,0.3)' }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#000', titleColor: '#d20a10', bodyColor: '#fff', padding: 12, cornerRadius: 6 } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f5f5f5' } } } }
    });
    <?php endif; ?>

    <?php if (!empty($perSorgente) && $totaleSorgente > 0): ?>
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
    </script>
</body>
</html>
