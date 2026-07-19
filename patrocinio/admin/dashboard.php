<?php
session_start();

// 1. ZONA HORARIA HONDURAS (Corrección de cálculo)
date_default_timezone_set('America/Tegucigalpa');

// VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';

// --- 1. CONFIGURACIÓN DE FILTROS ---
$where_clauses = ["1=1"];
$params = [];

if (!empty($_GET['village'])) { $where_clauses[] = "l.village = ?"; $params[] = $_GET['village']; }
if (!empty($_GET['tech_id'])) { $where_clauses[] = "l.tech_id = ?"; $params[] = $_GET['tech_id']; }
if (!empty($_GET['status']))  { $where_clauses[] = "l.status = ?";  $params[] = $_GET['status']; }

if (!empty($_GET['start_date'])) { 
    $where_clauses[] = "STR_TO_DATE(l.technician_due_date, '%d-%b-%Y') >= ?"; 
    $params[] = $_GET['start_date']; 
}
if (!empty($_GET['end_date'])) { 
    $where_clauses[] = "STR_TO_DATE(l.technician_due_date, '%d-%b-%Y') <= ?"; 
    $params[] = $_GET['end_date']; 
}

$sql_where = implode(" AND ", $where_clauses);

// --- 2. KPI GLOBALES ---
$sql_kpi = "SELECT 
    COUNT(*) as total, 
    SUM(CASE WHEN l.status IN ('PENDIENTE', 'ASSIGNED') THEN 1 ELSE 0 END) as pendientes, 
    SUM(CASE WHEN l.status IN ('COMPLETADO', 'SYNCED') THEN 1 ELSE 0 END) as completadas,
    SUM(CASE WHEN l.status = 'RETURNED' THEN 1 ELSE 0 END) as devueltas
    FROM letters l WHERE $sql_where";
$stmt = $pdo->prepare($sql_kpi); $stmt->execute($params); $stats = $stmt->fetch(PDO::FETCH_ASSOC);

$total = $stats['total'] > 0 ? $stats['total'] : 1;
$tasa_exito = round(($stats['completadas'] / $total) * 100, 1);

// --- 3. DATOS PARA GRÁFICOS ---

// A. Productividad por Técnico
$sql_chart_tech = "SELECT t.full_name, 
    COUNT(CASE WHEN l.status IN ('COMPLETADO', 'SYNCED') THEN 1 END) as terminadas,
    COUNT(CASE WHEN l.status IN ('PENDIENTE', 'ASSIGNED') THEN 1 END) as pendientes
    FROM technicians t 
    JOIN letters l ON t.id = l.tech_id 
    WHERE $sql_where 
    GROUP BY t.id, t.full_name 
    ORDER BY terminadas DESC LIMIT 10";
$stmt = $pdo->prepare($sql_chart_tech); $stmt->execute($params); $tech_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// B. Tipos de Carta
$sql_chart_type = "SELECT letter_type, COUNT(*) as cantidad 
    FROM letters l 
    WHERE $sql_where 
    GROUP BY letter_type";
$stmt = $pdo->prepare($sql_chart_type); $stmt->execute($params); $type_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// C. Cronograma
$sql_timeline = "SELECT l.technician_due_date as fecha, COUNT(*) as cantidad 
    FROM letters l 
    WHERE $sql_where AND l.technician_due_date IS NOT NULL 
    GROUP BY l.technician_due_date 
    ORDER BY STR_TO_DATE(l.technician_due_date, '%d-%b-%Y') ASC LIMIT 15";
$stmt = $pdo->prepare($sql_timeline); $stmt->execute($params); $timeline_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 4. TABLA DE URGENCIA (Top 20 más próximas a vencer) ---
$sql_urgency = "SELECT l.slip_id, l.child_name, l.letter_type, l.technician_due_date, t.full_name, l.village, l.community_id
    FROM letters l
    LEFT JOIN technicians t ON l.tech_id = t.id
    WHERE $sql_where AND l.status IN ('PENDIENTE', 'ASSIGNED')
    ORDER BY STR_TO_DATE(l.technician_due_date, '%d-%b-%Y') ASC
    LIMIT 20";
$stmt = $pdo->prepare($sql_urgency); $stmt->execute($params); $urgency_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// JSONs para JS
$js_tech_labels = json_encode(array_column($tech_data, 'full_name'));
$js_tech_done   = json_encode(array_column($tech_data, 'terminadas'));
$js_tech_pend   = json_encode(array_column($tech_data, 'pendientes'));
$js_type_labels = json_encode(array_column($type_data, 'letter_type'));
$js_type_data   = json_encode(array_column($type_data, 'cantidad'));
$js_time_labels = json_encode(array_column($timeline_data, 'fecha'));
$js_time_data   = json_encode(array_column($timeline_data, 'cantidad'));

$lista_comunidades = $pdo->query("SELECT DISTINCT village FROM letters ORDER BY village")->fetchAll(PDO::FETCH_COLUMN);
$lista_tecnicos = $pdo->query("SELECT id, full_name FROM technicians ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - MagicLetter</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --color-primary: #46B094;
            --color-support: #34859B;
            --color-bg: #f4f7f6;
            --color-danger: #d9534f;
            --color-warning: #f0ad4e;
            --color-success: #5cb85c;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--color-bg); margin: 0; color: #444; padding-bottom: 60px; }
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        
        /* HEADER */
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        h1 { color: var(--color-primary); margin: 0; font-weight: 800; }

        /* FILTROS */
        .filter-container { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; border-left: 5px solid var(--color-primary); }
        .filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; align-items: end; }
        .form-control { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; }
        .btn-filter { background: var(--color-support); color: white; border: none; padding: 9px; border-radius: 6px; cursor: pointer; font-weight: bold; width: 100%; }
        .btn-reset { background: #eee; color: #555; text-decoration: none; padding: 9px; border-radius: 6px; text-align: center; display: block; font-weight: bold; font-size: 13px;}

        /* KPI */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-align: center; }
        .kpi-value { font-size: 28px; font-weight: 800; margin: 5px 0; color: #333; }
        .kpi-title { font-size: 12px; color: #888; font-weight: 700; text-transform: uppercase; }

        /* GRÁFICOS */
        .charts-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 25px; margin-bottom: 30px; }
        .chart-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .chart-title { font-weight: 700; color: #444; margin: 0 0 15px 0; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px; }

        /* TABLA */
        .table-card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .custom-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .custom-table th { background: #f8f9fa; color: #666; padding: 15px; text-align: left; }
        .custom-table td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; }
        
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .bg-welcome { background: #e3f2fd; color: #0d47a1; }
        .bg-reply { background: #e8f5e9; color: #1b5e20; }
        .bg-thank { background: #f3e5f5; color: #4a148c; }
        .bg-unknown { background: #eee; color: #666; }

        .countdown-box { font-weight: 800; font-size: 13px; display: inline-flex; align-items: center; gap: 5px; }
        .cd-danger { color: var(--color-danger); }
        .cd-warning { color: var(--color-warning); }
        .cd-success { color: var(--color-success); }

        @media(max-width: 1000px) { .charts-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="dashboard-header">
        <div>
            <h1>Dashboard Operativo</h1>
            <small>Fecha Servidor: <?= date('d-M-Y H:i') ?></small> </div>
    </div>

    <div class="filter-container">
        <form class="filter-form" method="GET">
            <div class="form-group"><label>Desde</label><input type="date" name="start_date" class="form-control" value="<?= $_GET['start_date'] ?? '' ?>"></div>
            <div class="form-group"><label>Hasta</label><input type="date" name="end_date" class="form-control" value="<?= $_GET['end_date'] ?? '' ?>"></div>
            <div class="form-group"><label>Comunidad</label>
                <select name="village" class="form-control"><option value="">-- Todas --</option>
                    <?php foreach($lista_comunidades as $v) echo "<option value='$v'".(($_GET['village']??'')==$v?'selected':'').">$v</option>"; ?>
                </select>
            </div>
            <div class="form-group"><label>Técnico</label>
                <select name="tech_id" class="form-control"><option value="">-- Todos --</option>
                    <?php foreach($lista_tecnicos as $t) echo "<option value='{$t['id']}'".(($_GET['tech_id']??'')==$t['id']?'selected':'').">{$t['full_name']}</option>"; ?>
                </select>
            </div>
            <div class="form-group"><button type="submit" class="btn-filter">Filtrar</button></div>
            <?php if(!empty($_GET)): ?><div class="form-group"><a href="dashboard.php" class="btn-reset">Limpiar</a></div><?php endif; ?>
        </form>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card"><div class="kpi-title">Total Cartas</div><div class="kpi-value"><?= number_format($stats['total']) ?></div></div>
        <div class="kpi-card"><div class="kpi-title">Avance</div><div class="kpi-value" style="color:var(--color-primary);"><?= $tasa_exito ?>%</div></div>
        <div class="kpi-card"><div class="kpi-title">Pendientes</div><div class="kpi-value" style="color:var(--color-warning);"><?= number_format($stats['pendientes']) ?></div></div>
        <div class="kpi-card"><div class="kpi-title">Devueltas</div><div class="kpi-value" style="color:var(--color-danger);"><?= $stats['devueltas'] ?></div></div>
    </div>

    <div class="charts-grid">
        <div class="chart-card">
            <h3 class="chart-title">📊 Avance por Técnico</h3>
            <div style="height:250px;"><canvas id="chartTecnicos"></canvas></div>
        </div>
        <div class="chart-card">
            <h3 class="chart-title">🍩 Por Tipo</h3>
            <div style="height:250px; display:flex; justify-content:center;"><canvas id="chartTypes"></canvas></div>
        </div>
    </div>

    <div class="charts-grid" style="grid-template-columns: 1fr;">
        <div class="chart-card">
            <h3 class="chart-title">📅 Vencimientos (Carga de Trabajo)</h3>
            <div style="height:300px;"><canvas id="chartTimeline"></canvas></div>
        </div>
    </div>

    <div class="table-card">
        <div style="padding:20px; border-bottom:1px solid #eee;">
            <h3 style="margin:0; color:var(--color-danger); font-size:16px;">
                <i class="fa-solid fa-fire"></i> Alertas de Vencimiento (Top 20 Urgentes)
            </h3>
        </div>
        <div style="overflow-x:auto;">
            <table class="custom-table">
                <thead>
                    <tr><th>Tiempo Restante</th><th>Vence</th><th>Tipo</th><th>Niño</th><th>Comunidad</th><th>Técnico</th></tr>
                </thead>
                <tbody>
                    <?php 
                    // CALCULO EXACTO DE DÍAS (Medianoche a Medianoche)
                    $today = new DateTime('today'); // Hoy 00:00:00

                    if(empty($urgency_data)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">Todo al día.</td></tr>
                    <?php else:
                        foreach($urgency_data as $row): 
                            // Convertir fecha DB a Objeto y poner a medianoche
                            $deadline = DateTime::createFromFormat('d-M-Y', $row['technician_due_date']);
                            
                            if($deadline) {
                                $deadline->setTime(0,0,0); // Forzar medianoche para comparar días enteros
                                $diff = $today->diff($deadline); // Diferencia real
                                $days = (int)$diff->format('%r%a'); // %r (signo) %a (días totales) -> Entero con signo
                            } else {
                                $days = 0; // Fallback
                            }

                            // Colores según días restantes
                            $classCD = 'cd-success';
                            $iconCD = 'fa-check-circle';
                            $textCD = $days . " días";

                            if($days < 0) {
                                $classCD = 'cd-danger'; $iconCD = 'fa-triangle-exclamation'; $textCD = "VENCIDA (" . abs($days) . " días)";
                            } elseif ($days == 0) {
                                $classCD = 'cd-danger'; $iconCD = 'fa-fire'; $textCD = "¡Vence HOY!";
                            } elseif ($days <= 3) {
                                $classCD = 'cd-warning'; $iconCD = 'fa-hourglass-half'; $textCD = $days . " días";
                            }

                            // Badge Tipo
                            $bgBadge = 'bg-unknown';
                            if(stripos($row['letter_type'], 'Welcome') !== false) $bgBadge = 'bg-welcome';
                            elseif(stripos($row['letter_type'], 'Reply') !== false) $bgBadge = 'bg-reply';
                            elseif(stripos($row['letter_type'], 'Thank') !== false) $bgBadge = 'bg-thank';
                        ?>
                        <tr>
                            <td><div class="countdown-box <?= $classCD ?>"><i class="fa-solid <?= $iconCD ?>"></i> <?= $textCD ?></div></td>
                            <td style="font-weight:bold;"><?= $row['technician_due_date'] ?></td>
                            <td><span class="badge <?= $bgBadge ?>"><?= $row['letter_type'] ?></span></td>
                            <td><?= $row['child_name'] ?></td>
                            <td><?= $row['village'] ?></td>
                            <td><?= $row['full_name'] ?: '<span style="color:#999;">--</span>' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    Chart.defaults.font.family = "'Segoe UI', sans-serif";
    const colors = { p: '#46B094', s: '#B4D6E0', w: '#17a2b8', r: '#28a745', t: '#6f42c1' };

    new Chart(document.getElementById('chartTecnicos'), {
        type: 'bar',
        data: { labels: <?= $js_tech_labels ?>, datasets: [{ label: 'Listas', data: <?= $js_tech_done ?>, backgroundColor: colors.p }, { label: 'Pendientes', data: <?= $js_tech_pend ?>, backgroundColor: colors.s }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true } } }
    });

    new Chart(document.getElementById('chartTypes'), {
        type: 'doughnut',
        data: { labels: <?= $js_type_labels ?>, datasets: [{ data: <?= $js_type_data ?>, backgroundColor: [colors.w, colors.r, colors.t, '#999'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '65%' }
    });

    new Chart(document.getElementById('chartTimeline'), {
        type: 'line',
        data: { labels: <?= $js_time_labels ?>, datasets: [{ label: 'Vencimientos', data: <?= $js_time_data ?>, borderColor: '#34859B', backgroundColor: 'rgba(52, 133, 155, 0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, maintainAspectRatio: false }
    });
</script>
</body>
</html>