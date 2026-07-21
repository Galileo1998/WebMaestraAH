<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$poa_vigente = 'Ningún POA activo';
$total_pto = 0;
$total_ejec = 0;
$sectores_data = [];
try {
    $stmt = $db->query("SELECT nombre_poa FROM ah_poa WHERE is_active=1 LIMIT 1");
    $poa_vigente = trim((string)($stmt ? $stmt->fetchColumn() : '')) ?: $poa_vigente;
} catch (Throwable $ignored) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Financiero | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/chart.js/dist/chart.umd.js"></script>
    <style>
        :root { --ah-primary:#34859B; --ah-accent:#46B094; --canvas:#f8fafc; --text:#172033; --muted:#64748b; --border:#dbe4ee; --danger:#b42318; --warning:#b45309; --success:#16784b; }
        * { box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; display:flex; min-height:100vh; background:var(--canvas); margin:0; color:var(--text); }
        .main-wrapper { flex:1; padding:32px; min-width:0; overflow-y:auto; }
        .page-header { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; margin-bottom:22px; }
        .page-header h1 { font-size:1.85rem; margin:0; display:flex; align-items:center; gap:10px; }
        .page-header p { margin:6px 0 0; color:var(--muted); }
        .sync-status { max-width:570px; font-size:.82rem!important; text-align:right; }
        .dashboard-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:16px; }
        .kpi-card,.chart-card,.detail-card,.alert-card { background:#fff; border:1px solid var(--border); border-radius:14px; box-shadow:0 5px 16px rgba(15,23,42,.035); }
        .kpi-card { padding:20px; position:relative; overflow:hidden; border-top:4px solid var(--card-color,var(--ah-primary)); }
        .kpi-card h3 { margin:0 0 9px; color:var(--muted); font-size:.76rem; text-transform:uppercase; letter-spacing:.55px; }
        .kpi-card .value { font-size:1.55rem; font-weight:800; margin:0; color:var(--card-color,var(--text)); }
        .kpi-card .hint { margin:8px 0 0; color:var(--muted); font-size:.74rem; }
        .icon-bg { position:absolute; right:-7px; bottom:-12px; font-size:5rem; opacity:.055; color:var(--card-color,var(--ah-primary)); }
        .progress-bar-bg { background:#e8eef4; height:9px; border-radius:9px; margin-top:12px; overflow:hidden; }
        .progress-bar-fill { height:100%; border-radius:9px; background:var(--card-color,#4f46e5); transition:width .6s ease; }
        .alerts-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; margin-bottom:16px; }
        .alert-card { padding:14px 18px; display:flex; align-items:center; gap:13px; }
        .alert-card .alert-icon { width:38px; height:38px; border-radius:10px; display:grid; place-items:center; font-size:1rem; }
        .alert-card strong { display:block; font-size:1.25rem; }
        .alert-card span { color:var(--muted); font-size:.78rem; }
        .charts-grid { display:grid; grid-template-columns:minmax(0,2fr) minmax(280px,1fr); gap:16px; margin-bottom:16px; }
        .chart-card,.detail-card { padding:20px; min-width:0; }
        .card-title { margin:0 0 16px; font-size:1rem; display:flex; align-items:center; gap:8px; }
        .card-title small { margin-left:auto; color:var(--muted); font-weight:500; }
        .chart-wrap { position:relative; min-height:310px; }
        .detail-card { margin-bottom:16px; }
        .filters { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
        .filters input,.filters select { height:40px; border:1px solid #cbd5e1; border-radius:9px; padding:0 12px; background:#fff; color:var(--text); font:inherit; font-size:.82rem; }
        .filters input { flex:1; min-width:220px; }
        .table-shell { overflow:auto; max-height:520px; border:1px solid var(--border); border-radius:10px; }
        .finance-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.78rem; min-width:980px; }
        .finance-table th { position:sticky; top:0; z-index:2; background:#edf3f7; color:#334155; text-transform:uppercase; font-size:.68rem; letter-spacing:.3px; text-align:left; padding:11px 10px; border-bottom:1px solid var(--border); }
        .finance-table td { padding:10px; border-bottom:1px solid #edf1f5; vertical-align:top; }
        .finance-table tbody tr:hover { background:#f8fbfc; }
        .finance-table .num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .finance-table .activity { min-width:260px; max-width:390px; }
        .finance-table tfoot td { position:sticky; bottom:0; background:#e9f2f5; font-weight:800; border-top:2px solid #cbd5e1; }
        .status { display:inline-flex; align-items:center; border-radius:999px; padding:4px 8px; font-size:.68rem; font-weight:700; white-space:nowrap; }
        .status.over { background:#fee4e2; color:var(--danger); }
        .status.under { background:#fff0d5; color:#9a4c00; }
        .status.ok { background:#dcfae6; color:#087443; }
        .execution-cell { min-width:145px; }
        .mini-track { height:6px; background:#e7edf3; border-radius:9px; overflow:hidden; margin-top:5px; }
        .mini-fill { height:100%; border-radius:9px; }
        .empty-state { padding:30px!important; color:var(--muted); text-align:center!important; }
        .negative { color:var(--danger); font-weight:700; }
        @media(max-width:1200px){ .dashboard-grid{grid-template-columns:repeat(2,1fr)} }
        @media(max-width:900px){ .main-wrapper{padding:20px}.page-header{display:block}.sync-status{text-align:left}.charts-grid{grid-template-columns:1fr}.alerts-grid{grid-template-columns:1fr} }
        @media(max-width:600px){ .dashboard-grid{grid-template-columns:1fr}.main-wrapper{padding:14px}.kpi-card .value{font-size:1.35rem} }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <main class="main-wrapper">
        <header class="page-header">
            <div>
                <h1><i class="fa-solid fa-chart-line" style="color:var(--ah-primary)"></i> Panel gerencial</h1>
                <p>Seguimiento financiero de <strong id="dashboard-poa-name"><?php echo htmlspecialchars($poa_vigente, ENT_QUOTES, 'UTF-8'); ?></strong></p>
            </div>
            <p id="dashboard-sync-status" class="sync-status"><i class="fa-solid fa-rotate fa-spin"></i> Sincronizando con la matriz POA…</p>
        </header>

        <section class="dashboard-grid" aria-label="Indicadores financieros">
            <article class="kpi-card" style="--card-color:#34859B"><i class="fa-solid fa-sack-dollar icon-bg"></i><h3>Techo programado</h3><p class="value" id="dashboard-budget">L. 0.00</p><p class="hint">Presupuesto anual del POA</p></article>
            <article class="kpi-card" style="--card-color:#b45309"><i class="fa-solid fa-money-bill-transfer icon-bg"></i><h3>Ejecutado y comprometido</h3><p class="value" id="dashboard-executed">L. 0.00</p><p class="hint">Manual, compras autorizadas y pendientes</p></article>
            <article class="kpi-card" id="available-card" style="--card-color:#166534"><i class="fa-solid fa-vault icon-bg"></i><h3>Saldo disponible</h3><p class="value" id="dashboard-available">L. 0.00</p><p class="hint" id="available-hint">Presupuesto aún disponible</p></article>
            <article class="kpi-card" style="--card-color:#4f46e5"><i class="fa-solid fa-gauge-high icon-bg"></i><h3>Nivel de ejecución</h3><p class="value" id="dashboard-percentage">0%</p><div class="progress-bar-bg"><div class="progress-bar-fill" id="dashboard-progress" style="width:0"></div></div></article>
        </section>

        <section class="alerts-grid" aria-label="Alertas de ejecución">
            <article class="alert-card"><div class="alert-icon" style="background:#fee4e2;color:#b42318"><i class="fa-solid fa-arrow-trend-up"></i></div><div><strong id="over-count">0</strong><span>Líneas sobreejecutadas</span></div></article>
            <article class="alert-card"><div class="alert-icon" style="background:#fff0d5;color:#b45309"><i class="fa-solid fa-arrow-trend-down"></i></div><div><strong id="under-count">0</strong><span>Líneas subejecutadas</span></div></article>
            <article class="alert-card"><div class="alert-icon" style="background:#f2e8ff;color:#7e22ce"><i class="fa-solid fa-circle-exclamation"></i></div><div><strong id="unbudgeted-count">0</strong><span>Con ejecución sin presupuesto</span></div></article>
        </section>

        <section class="charts-grid">
            <article class="chart-card"><h2 class="card-title"><i class="fa-solid fa-chart-column"></i> Presupuesto y ejecución por sector</h2><div class="chart-wrap"><canvas id="barChart"></canvas></div></article>
            <article class="chart-card"><h2 class="card-title"><i class="fa-solid fa-chart-pie"></i> Composición financiera</h2><div class="chart-wrap"><canvas id="doughnutChart"></canvas></div></article>
        </section>

        <section class="charts-grid">
            <article class="chart-card"><h2 class="card-title"><i class="fa-solid fa-scale-balanced"></i> Desviación por línea <small>Mayores saldos y excesos</small></h2><div class="chart-wrap"><canvas id="deviationChart"></canvas></div></article>
            <article class="detail-card">
                <h2 class="card-title"><i class="fa-solid fa-layer-group"></i> Resumen por subsector</h2>
                <div class="filters"><select id="subsector-sector"><option value="">Todos los sectores</option></select></div>
                <div class="table-shell" style="max-height:310px"><table class="finance-table" style="min-width:650px" data-table-ux="on" data-table-search="off"><thead><tr><th>Subsector</th><th class="num">Presupuesto</th><th class="num">Ejecución</th><th class="num">%</th></tr></thead><tbody id="subsector-body"></tbody></table></div>
            </article>
        </section>

        <section class="detail-card">
            <h2 class="card-title"><i class="fa-solid fa-list-check"></i> Ejecución por línea del POA <small id="line-result-count">0 líneas</small></h2>
            <div class="filters">
                <input id="line-search" type="search" placeholder="Buscar actividad, marco lógico, cuenta o subsector…">
                <select id="line-sector"><option value="">Todos los sectores</option></select>
                <select id="line-status"><option value="">Todos los estados</option><option value="Sobreejecución">Sobreejecución</option><option value="Subejecución">Subejecución</option><option value="Equilibrado">Equilibrado</option></select>
            </div>
            <div class="table-shell">
                <table class="finance-table" data-table-ux="on" data-table-search="off">
                    <thead><tr><th>Sector / subsector</th><th>Marco / actividad</th><th>Cuenta</th><th class="num">Presupuesto</th><th class="num">Ejecutado</th><th class="num">Saldo</th><th>Ejecución</th><th>Estado</th></tr></thead>
                    <tbody id="line-body"></tbody>
                    <tfoot id="line-foot"></tfoot>
                </table>
            </div>
        </section>
    </main>

    <script>
        const money = value => 'L. ' + Number(value || 0).toLocaleString('es-HN',{minimumFractionDigits:2,maximumFractionDigits:2});
        const number = value => Number(value || 0).toLocaleString('es-HN',{minimumFractionDigits:2,maximumFractionDigits:2});
        const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
        let dashboardLines = [];
        let dashboardSubsectors = [];

        const commonOptions = {responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:ctx => `${ctx.dataset.label || ctx.label}: ${money(ctx.parsed.y ?? ctx.parsed)}`}}}};
        const barChart = new Chart(document.getElementById('barChart'),{type:'bar',data:{labels:[],datasets:[{label:'Presupuesto',data:[],backgroundColor:'#cbd5e1',borderRadius:5},{label:'Ejecutado / comprometido',data:[],backgroundColor:'#34859B',borderRadius:5}]},options:{...commonOptions,scales:{y:{beginAtZero:true,ticks:{callback:value=>'L. '+Number(value).toLocaleString('es-HN')}},x:{ticks:{maxRotation:35,minRotation:0}}}}});
        const doughnutChart = new Chart(document.getElementById('doughnutChart'),{type:'doughnut',data:{labels:['Ejecución manual','Compras autorizadas','Compras pendientes','Disponible'],datasets:[{data:[0,0,0,0],backgroundColor:['#34859B','#46B094','#f59e0b','#d8e1e8'],borderWidth:0,hoverOffset:4}]},options:{...commonOptions,cutout:'66%'}});
        const deviationChart = new Chart(document.getElementById('deviationChart'),{type:'bar',data:{labels:[],datasets:[{label:'Saldo (+ disponible / − exceso)',data:[],backgroundColor:ctx => Number(ctx.raw)<0?'#d92d20':'#46B094',borderRadius:4}]},options:{...commonOptions,indexAxis:'y',scales:{x:{ticks:{callback:value=>'L. '+Number(value).toLocaleString('es-HN')}},y:{ticks:{autoSkip:false}}}}});

        function shortLabel(line) {
            const label = line.actividad || line.marco_logico || line.cuenta || 'Línea sin descripción';
            return label.length > 38 ? label.slice(0,35) + '…' : label;
        }
        function statusClass(status) { return status==='Sobreejecución'?'over':status==='Subejecución'?'under':'ok'; }
        function fillClass(line) { return line.estado==='Sobreejecución'?'#d92d20':Number(line.porcentaje)>=85?'#f59e0b':'#46B094'; }
        function populateSectorFilters(sectors) {
            const options = sectors.map(item=>`<option value="${escapeHtml(item.sector)}">${escapeHtml(item.sector)}</option>`).join('');
            document.getElementById('line-sector').insertAdjacentHTML('beforeend',options);
            document.getElementById('subsector-sector').insertAdjacentHTML('beforeend',options);
        }
        function renderSubsectors() {
            const sector = document.getElementById('subsector-sector').value;
            const rows = dashboardSubsectors.filter(item=>!sector || item.sector===sector);
            document.getElementById('subsector-body').innerHTML = rows.length ? rows.map(item=>`<tr><td><strong>${escapeHtml(item.subsector)}</strong><br><small>${escapeHtml(item.sector)} · ${Number(item.lineas||0)} líneas</small></td><td class="num">${number(item.presupuesto)}</td><td class="num">${number(item.ejecutado)}</td><td class="num ${Number(item.porcentaje)>100?'negative':''}">${Number(item.porcentaje||0).toLocaleString('es-HN',{maximumFractionDigits:1})}%</td></tr>`).join('') : '<tr><td colspan="4" class="empty-state">No hay subsectores para este filtro.</td></tr>';
        }
        function filteredLines() {
            const query = document.getElementById('line-search').value.trim().toLocaleLowerCase('es');
            const sector = document.getElementById('line-sector').value;
            const status = document.getElementById('line-status').value;
            return dashboardLines.filter(line => {
                const haystack = [line.sector,line.subsector,line.marco_logico,line.actividad,line.cuenta].join(' ').toLocaleLowerCase('es');
                return (!query || haystack.includes(query)) && (!sector || line.sector===sector) && (!status || line.estado===status);
            });
        }
        function renderLines() {
            const rows = filteredLines();
            document.getElementById('line-result-count').textContent = `${rows.length} ${rows.length===1?'línea':'líneas'}`;
            document.getElementById('line-body').innerHTML = rows.length ? rows.map(line=>`<tr><td><strong>${escapeHtml(line.sector)}</strong><br><small>${escapeHtml(line.subsector)}</small></td><td class="activity"><strong>${escapeHtml(line.marco_logico||'Sin marco')}</strong><br><small>${escapeHtml(line.actividad||'Sin descripción de actividad')}</small></td><td>${escapeHtml(line.cuenta||'—')}</td><td class="num">${number(line.presupuesto)}</td><td class="num">${number(line.ejecutado)}</td><td class="num ${Number(line.disponible)<0?'negative':''}">${number(line.disponible)}</td><td class="execution-cell"><strong>${Number(line.porcentaje||0).toLocaleString('es-HN',{maximumFractionDigits:1})}%</strong><div class="mini-track"><div class="mini-fill" style="width:${Math.min(100,Math.max(0,Number(line.porcentaje||0)))}%;background:${fillClass(line)}"></div></div></td><td><span class="status ${statusClass(line.estado)}">${escapeHtml(line.estado)}</span></td></tr>`).join('') : '<tr><td colspan="8" class="empty-state">No hay líneas que coincidan con los filtros.</td></tr>';
            const totals = rows.reduce((acc,line)=>({budget:acc.budget+Number(line.presupuesto||0),executed:acc.executed+Number(line.ejecutado||0),available:acc.available+Number(line.disponible||0)}),{budget:0,executed:0,available:0});
            const pct = totals.budget>0 ? totals.executed/totals.budget*100 : 0;
            document.getElementById('line-foot').innerHTML = `<tr><td colspan="3">Totales visibles</td><td class="num">${number(totals.budget)}</td><td class="num">${number(totals.executed)}</td><td class="num ${totals.available<0?'negative':''}">${number(totals.available)}</td><td>${pct.toLocaleString('es-HN',{maximumFractionDigits:1})}%</td><td></td></tr>`;
        }
        function updateCharts(summary) {
            const sectors = Array.isArray(summary.sectores)?summary.sectores:[];
            barChart.data.labels = sectors.map(item=>item.sector);
            barChart.data.datasets[0].data = sectors.map(item=>Number(item.presupuesto||0));
            barChart.data.datasets[1].data = sectors.map(item=>Number(item.ejecutado||0));
            barChart.update();
            doughnutChart.data.datasets[0].data = [Math.max(0,Number(summary.ejecucion_manual||0)),Math.max(0,Number(summary.compras_autorizadas||0)),Math.max(0,Number(summary.compras_pendientes||0)),Math.max(0,Number(summary.disponible||0))];
            doughnutChart.update();
            const deviations = [...dashboardLines].sort((a,b)=>Math.abs(Number(b.disponible))-Math.abs(Number(a.disponible))).slice(0,12);
            deviationChart.data.labels = deviations.map(shortLabel);
            deviationChart.data.datasets[0].data = deviations.map(item=>Number(item.disponible||0));
            deviationChart.update();
        }
        async function syncDashboardWithPoa() {
            const status = document.getElementById('dashboard-sync-status');
            try {
                const response = await fetch('poa.php?dashboard_summary=1',{credentials:'same-origin',headers:{Accept:'application/json'}});
                if(!response.ok) throw new Error('HTTP '+response.status);
                const summary = await response.json();
                if(!summary.ok) throw new Error(summary.message||'Resumen no disponible');
                dashboardLines = Array.isArray(summary.lineas)?summary.lineas:[];
                dashboardSubsectors = Array.isArray(summary.subsectores)?summary.subsectores:[];
                document.getElementById('dashboard-poa-name').textContent = summary.poa||'Ningún POA activo';
                document.getElementById('dashboard-budget').textContent = money(summary.presupuesto);
                document.getElementById('dashboard-executed').textContent = money(summary.ejecutado);
                document.getElementById('dashboard-available').textContent = money(summary.disponible);
                document.getElementById('dashboard-percentage').textContent = Number(summary.porcentaje||0).toLocaleString('es-HN',{maximumFractionDigits:1})+'%';
                document.getElementById('dashboard-progress').style.width = Math.min(100,Math.max(0,Number(summary.porcentaje||0)))+'%';
                if(Number(summary.disponible)<0){document.getElementById('available-card').style.setProperty('--card-color','#b42318');document.getElementById('available-hint').textContent='Exceso sobre el presupuesto programado';}
                const alerts = summary.alertas||{};
                document.getElementById('over-count').textContent = Number(alerts.sobreejecutadas||0).toLocaleString('es-HN');
                document.getElementById('under-count').textContent = Number(alerts.subejecutadas||0).toLocaleString('es-HN');
                document.getElementById('unbudgeted-count').textContent = Number(alerts.sin_presupuesto_con_ejecucion||0).toLocaleString('es-HN');
                populateSectorFilters(Array.isArray(summary.sectores)?summary.sectores:[]);
                renderSubsectors(); renderLines(); updateCharts(summary);
                status.innerHTML = `<i class="fa-solid fa-circle-check"></i> Sincronizado · Manual ${money(summary.ejecucion_manual)} · Autorizadas ${money(summary.compras_autorizadas)} · Pendientes ${money(summary.compras_pendientes)}`;
                status.style.color='#166534';
            } catch(error) {
                console.error('No fue posible sincronizar el dashboard con POA.',error);
                status.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> No fue posible cargar el detalle financiero del POA.';
                status.style.color='#b45309';
            }
        }
        document.getElementById('line-search').addEventListener('input',renderLines);
        document.getElementById('line-sector').addEventListener('change',renderLines);
        document.getElementById('line-status').addEventListener('change',renderLines);
        document.getElementById('subsector-sector').addEventListener('change',renderSubsectors);
        syncDashboardWithPoa();
    </script>
</body>
</html>
