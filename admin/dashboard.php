<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

// ========================================================
// 1. OBTENER DATOS DEL POA ACTIVO
// ========================================================
$poa_vigente = "Ningún POA Activo";
$total_pto = 0;
$total_ejec = 0;
$sectores_data = [];

try {
    // Buscar el nombre del POA activo
    $stmt_v = $db->query("SELECT nombre_poa FROM ah_poa WHERE is_active = 1 LIMIT 1");
    if ($stmt_v && $row = $stmt_v->fetch(PDO::FETCH_ASSOC)) {
        $poa_vigente = $row['nombre_poa'];
        
        // Totales globales
        $stmt_totales = $db->prepare("SELECT SUM(presupuesto_anual) as pto, SUM(ejecutado) as ejec FROM ah_poa WHERE nombre_poa = ?");
        $stmt_totales->execute([$poa_vigente]);
        $totales = $stmt_totales->fetch(PDO::FETCH_ASSOC);
        $total_pto = $totales['pto'] ?? 0;
        $total_ejec = $totales['ejec'] ?? 0;

        // Agrupación por Sector para la gráfica de barras
        $stmt_sectores = $db->prepare("
            SELECT 
                sector, 
                SUM(presupuesto_anual) as pto, 
                SUM(ejecutado) as ejec 
            FROM ah_poa 
            WHERE nombre_poa = ? 
            GROUP BY sector 
            ORDER BY pto DESC
        ");
        $stmt_sectores->execute([$poa_vigente]);
        $sectores_data = $stmt_sectores->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(Exception $e) {
    // Manejo silencioso en caso de que la tabla aún no exista
}

$saldo_disponible = $total_pto - $total_ejec;
$porcentaje_ejecucion = $total_pto > 0 ? round(($total_ejec / $total_pto) * 100, 1) : 0;

// Preparar arrays para JavaScript (Chart.js)
$lbl_sectores = [];
$pto_sectores = [];
$ejec_sectores = [];

foreach($sectores_data as $sd) {
    // Limpiar el nombre del sector para que la gráfica no se vea amontonada
    $nombre_corto = explode('_', $sd['sector'])[0] ?? 'Varios';
    $lbl_sectores[] = $nombre_corto;
    $pto_sectores[] = $sd['pto'];
    $ejec_sectores[] = $sd['ejec'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Financiero | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Librería para Gráficos -->
    <script src="https://unpkg.com/chart.js/dist/chart.umd.js"></script>
    
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg-canvas: #f8fafc; --text-main: #1e293b; --border: #cbd5e1; }
        body { font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; background: var(--bg-canvas); margin: 0; }
        .main-wrapper { flex-grow: 1; padding: 40px; overflow-y: auto; width: 100%; box-sizing: border-box; }
        .page-header { margin-bottom: 25px; }
        .page-header h1 { font-size: 2rem; margin: 0; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .page-header p { margin: 5px 0 0 0; color: #64748b; font-size: 1.1rem; }

        .dashboard-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: white; padding: 25px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 6px rgba(0,0,0,0.02); display: flex; flex-direction: column; justify-content: center; position: relative; overflow: hidden;}
        .kpi-card h3 { margin: 0 0 10px 0; color: #64748b; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2;}
        .kpi-card .value { font-size: 1.8rem; font-weight: 800; color: #0f172a; margin: 0; z-index: 2;}
        .kpi-card .icon-bg { position: absolute; right: -10px; bottom: -15px; font-size: 6rem; opacity: 0.05; z-index: 1; }
        
        .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .chart-card { background: white; padding: 25px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .chart-card h3 { margin: 0 0 20px 0; font-size: 1.1rem; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
        
        .progress-bar-bg { background: #e2e8f0; height: 10px; border-radius: 5px; width: 100%; margin-top: 15px; overflow: hidden;}
        .progress-bar-fill { background: var(--ah-accent); height: 100%; border-radius: 5px; transition: width 1s ease-in-out; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <h1><i class="fa-solid fa-chart-pie" style="color: var(--ah-primary);"></i> Panel Gerencial</h1>
            <p>Monitoreo de ejecución en tiempo real. Mostrando datos de: <strong id="dashboard-poa-name"><?php echo htmlspecialchars($poa_vigente); ?></strong></p>
            <p id="dashboard-sync-status" style="font-size:.84rem;color:#64748b;"><i class="fa-solid fa-rotate fa-spin"></i> Sincronizando con la matriz POA…</p>
        </div>

        <!-- Indicadores Clave de Rendimiento (KPIs) -->
        <div class="dashboard-grid">
            <div class="kpi-card" style="border-top: 4px solid var(--ah-primary);">
                <i class="fa-solid fa-sack-dollar icon-bg" style="color: var(--ah-primary);"></i>
                <h3>Techo Programado (POA)</h3>
                <p class="value" id="dashboard-budget">L. <?php echo number_format($total_pto, 2); ?></p>
            </div>
            
            <div class="kpi-card" style="border-top: 4px solid #b45309;">
                <i class="fa-solid fa-money-bill-transfer icon-bg" style="color: #b45309;"></i>
                <h3>Ejecutado y comprometido</h3>
                <p class="value" id="dashboard-executed" style="color: #b45309;">L. <?php echo number_format($total_ejec, 2); ?></p>
            </div>

            <div class="kpi-card" style="border-top: 4px solid #166534;">
                <i class="fa-solid fa-vault icon-bg" style="color: #166534;"></i>
                <h3>Saldo Disponible</h3>
                <p class="value" id="dashboard-available" style="color: #166534;">L. <?php echo number_format($saldo_disponible, 2); ?></p>
            </div>

            <div class="kpi-card" style="border-top: 4px solid #6366f1;">
                <i class="fa-solid fa-gauge-high icon-bg" style="color: #6366f1;"></i>
                <h3>Nivel de Ejecución</h3>
                <p class="value" id="dashboard-percentage" style="color: #4f46e5; font-size: 2.2rem;"><?php echo $porcentaje_ejecucion; ?>%</p>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" id="dashboard-progress" style="width: <?php echo min(100, max(0, $porcentaje_ejecucion)); ?>%; background: #4f46e5;"></div>
                </div>
            </div>
        </div>

        <!-- Zona de Gráficos -->
        <div class="charts-grid">
            <!-- Gráfico de Barras: Comparativa por Sector -->
            <div class="chart-card">
                <h3><i class="fa-solid fa-chart-column"></i> Ejecución vs Presupuesto por Sector Estratégico</h3>
                <canvas id="barChart" height="120"></canvas>
            </div>

            <!-- Gráfico de Dona: Composición del Gasto -->
            <div class="chart-card">
                <h3><i class="fa-solid fa-chart-donut"></i> Distribución del Gasto Global</h3>
                <canvas id="doughnutChart" height="250"></canvas>
            </div>
        </div>
    </main>

    <script>
        // Datos enviados desde PHP a JS
        const labelsSectores = <?php echo json_encode($lbl_sectores); ?>;
        const dataPtoSectores = <?php echo json_encode($pto_sectores); ?>;
        const dataEjecSectores = <?php echo json_encode($ejec_sectores); ?>;

        // Configuración Gráfico de Barras (Sectores)
        const ctxBar = document.getElementById('barChart').getContext('2d');
        const barChart = new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: labelsSectores,
                datasets: [
                    {
                        label: 'Presupuesto Programado',
                        data: dataPtoSectores,
                        backgroundColor: '#cbd5e1',
                        borderRadius: 4
                    },
                    {
                        label: 'Ejecutado / comprometido',
                        data: dataEjecSectores,
                        backgroundColor: '#34859B',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += 'L. ' + context.parsed.y.toLocaleString('es-HN', {minimumFractionDigits: 2});
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(value) { return 'L. ' + value.toLocaleString('es-HN'); } } },
                    x: { ticks: { maxRotation: 45, minRotation: 45 } }
                }
            }
        });

        // Configuración Gráfico de Dona (Gasto Global)
        const ctxDoughnut = document.getElementById('doughnutChart').getContext('2d');
        const doughnutChart = new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Ejecutado / comprometido', 'Disponible'],
                datasets: [{
                    data: [<?php echo $total_ejec; ?>, <?php echo $saldo_disponible; ?>],
                    backgroundColor: ['#b45309', '#166534'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                cutout: '70%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed !== null) {
                                    label += 'L. ' + context.parsed.toLocaleString('es-HN', {minimumFractionDigits: 2});
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });

        const money = value => 'L. ' + Number(value || 0).toLocaleString('es-HN', {minimumFractionDigits:2, maximumFractionDigits:2});
        async function syncDashboardWithPoa() {
            const status = document.getElementById('dashboard-sync-status');
            try {
                const response = await fetch('poa.php?dashboard_summary=1', {
                    credentials:'same-origin',
                    headers:{'Accept':'application/json'}
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const summary = await response.json();
                if (!summary.ok) throw new Error(summary.message || 'Resumen no disponible');

                document.getElementById('dashboard-poa-name').textContent = summary.poa || 'Ningún POA activo';
                document.getElementById('dashboard-budget').textContent = money(summary.presupuesto);
                document.getElementById('dashboard-executed').textContent = money(summary.ejecutado);
                document.getElementById('dashboard-available').textContent = money(summary.disponible);
                document.getElementById('dashboard-percentage').textContent = Number(summary.porcentaje || 0).toLocaleString('es-HN', {maximumFractionDigits:1}) + '%';
                document.getElementById('dashboard-progress').style.width = Math.min(100, Math.max(0, Number(summary.porcentaje || 0))) + '%';

                const sectors = Array.isArray(summary.sectores) ? summary.sectores : [];
                barChart.data.labels = sectors.map(item => String(item.sector || 'Varios').split('_')[0]);
                barChart.data.datasets[0].data = sectors.map(item => Number(item.presupuesto || 0));
                barChart.data.datasets[1].data = sectors.map(item => Number(item.ejecutado || 0));
                barChart.update();

                doughnutChart.data.datasets[0].data = [
                    Math.max(0, Number(summary.ejecutado || 0)),
                    Math.max(0, Number(summary.disponible || 0))
                ];
                doughnutChart.update();

                status.innerHTML = '<i class="fa-solid fa-circle-check"></i> Sincronizado con POA: manual L. '
                    + Number(summary.ejecucion_manual || 0).toLocaleString('es-HN', {minimumFractionDigits:2})
                    + ' · compras autorizadas L. ' + Number(summary.compras_autorizadas || 0).toLocaleString('es-HN', {minimumFractionDigits:2})
                    + ' · compras pendientes L. ' + Number(summary.compras_pendientes || 0).toLocaleString('es-HN', {minimumFractionDigits:2});
                status.style.color = '#166534';
            } catch (error) {
                console.error('No fue posible sincronizar el dashboard con POA.', error);
                status.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> No fue posible actualizar las cifras desde POA; se muestran los valores almacenados.';
                status.style.color = '#b45309';
            }
        }
        syncDashboardWithPoa();
    </script>
</body>
</html>
