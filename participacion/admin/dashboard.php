<?php
// admin/dashboard.php
require_once '../config/db.php';
// Nota: Ya no hacemos consultas PHP aquí arriba, JS se encargará de llenarlo.
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Ejecutivo</title>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .card-kpi { border: none; border-radius: 12px; color: white; transition: all 0.3s ease; }
        .card-kpi:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.2) !important; }
        .bg-grad-1 { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-grad-2 { background: linear-gradient(135deg, #2af598 0%, #009efd 100%); }
        .bg-grad-4 { background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); color: #fff; }
        .chart-container { position: relative; height: 320px; width: 100%; }
    </style>
</head>
<body class="bg-light">

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4">
    
    <div class="d-flex justify-content-between align-items-center my-4">
        <div>
            <h2 class="fw-bold text-dark">📊 Tablero de Control</h2>
            <p class="text-muted">Actualización en vivo: <span id="last-update">Cargando...</span></p>
        </div>
        <button class="btn btn-primary shadow-sm"><i class="bi bi-download"></i> Reporte PDF</button>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="card card-kpi bg-grad-1 shadow h-100 py-2">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase mb-1 opacity-75">Estudiantes</h6>
                        <h2 class="mb-0 fw-bold" id="val-estudiantes">--</h2>
                    </div>
                    <i class="bi bi-mortarboard-fill fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-kpi bg-grad-2 shadow h-100 py-2">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase mb-1 opacity-75">Centros Educativos</h6>
                        <h2 class="mb-0 fw-bold" id="val-centros">--</h2>
                    </div>
                    <i class="bi bi-building fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-kpi bg-primary shadow h-100 py-2" style="background: #4e73df;">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase mb-1 opacity-75">Municipios</h6>
                        <h2 class="mb-0 fw-bold" id="val-municipios">--</h2>
                    </div>
                    <i class="bi bi-map-fill fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card card-kpi bg-grad-4 shadow h-100 py-2">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <h6 class="text-uppercase mb-1 opacity-75">Firmas Hoy</h6>
                        <h2 class="mb-0 fw-bold" id="val-hoy">--</h2>
                    </div>
                    <i class="bi bi-pen-fill fs-1 opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card shadow border-0 h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-graph-up"></i> Dinámica de Participación Mensual</h6>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="chartMensual"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow border-0 h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-gender-ambiguous"></i> Población por Género</h6>
                </div>
                <div class="card-body position-relative">
                    <div class="chart-container" style="height: 250px;">
                        <canvas id="chartGenero"></canvas>
                    </div>
                    <div class="mt-4 text-center small text-muted">
                        Total en sistema
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-5">
        <div class="col-12">
            <div class="card shadow border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-award"></i> Top 5 Centros con Mayor Matrícula</h6>
                </div>
                <div class="card-body">
                    <div class="chart-container" style="height: 280px;">
                        <canvas id="chartTop"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
    Chart.defaults.color = '#858796';

    // 1. INICIALIZAR GRÁFICOS VACÍOS (Se llenarán con AJAX)
    
    // Chart Mensual
    let chartMensual = new Chart(document.getElementById('chartMensual'), {
        type: 'line',
        data: {
            labels: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
            datasets: [{
                label: 'Firmas Recibidas',
                data: [], // Vacío inicial
                backgroundColor: 'rgba(78, 115, 223, 0.05)',
                borderColor: 'rgba(78, 115, 223, 1)',
                pointRadius: 4,
                pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                pointBorderColor: '#fff',
                fill: true,
                tension: 0.3
            }]
        },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true }, x: { grid: { display: false } } }, plugins: { legend: { display: false } } }
    });

    // Chart Género
    let chartGenero = new Chart(document.getElementById('chartGenero'), {
        type: 'doughnut',
        data: {
            labels: [], // Vacío inicial
            datasets: [{
                data: [],
                backgroundColor: ['#36b9cc', '#e74a3b'],
                borderWidth: 0,
                hoverOffset: 5
            }]
        },
        options: { maintainAspectRatio: false, cutout: '75%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } } } }
    });

    // Chart Top Centros
    let chartTop = new Chart(document.getElementById('chartTop'), {
        type: 'bar',
        data: {
            labels: [], // Vacío inicial
            datasets: [{
                label: 'Estudiantes',
                data: [],
                backgroundColor: '#1cc88a',
                borderRadius: 5,
                barPercentage: 0.6
            }]
        },
        options: { indexAxis: 'y', maintainAspectRatio: false, scales: { x: { beginAtZero: true } }, plugins: { legend: { display: false } } }
    });

    // 2. FUNCIÓN DE ACTUALIZACIÓN
    async function updateDashboard() {
        try {
            const response = await fetch('api_dashboard.php');
            const data = await response.json();

            if (data.status === 'success') {
                // A. Actualizar Tarjetas KPI
                document.getElementById('val-estudiantes').innerText = data.kpis.estudiantes;
                document.getElementById('val-centros').innerText = data.kpis.centros;
                document.getElementById('val-municipios').innerText = data.kpis.municipios;
                document.getElementById('val-hoy').innerText = data.kpis.hoy;
                document.getElementById('last-update').innerText = data.timestamp;

                // B. Actualizar Chart Mensual
                chartMensual.data.datasets[0].data = data.charts.mensual.data;
                chartMensual.update('none'); // 'none' para evitar animación brusca

                // C. Actualizar Chart Género
                chartGenero.data.labels = data.charts.genero.labels;
                chartGenero.data.datasets[0].data = data.charts.genero.data;
                chartGenero.update('none');

                // D. Actualizar Chart Top
                chartTop.data.labels = data.charts.top.labels;
                chartTop.data.datasets[0].data = data.charts.top.data;
                chartTop.update('none');
            }
        } catch (error) {
            console.error("Error actualizando dashboard:", error);
        }
    }

    // 3. EJECUTAR
    updateDashboard(); // Primera carga inmediata
    setInterval(updateDashboard, 5000); // Repetir cada 5 segundos

</script>

</body>
</html>