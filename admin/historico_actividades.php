<?php
/**
 * Módulo de Histórico de Actividades - Acción Honduras
 * Permite consultar la programación y logros de meses anteriores.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once dirname(__DIR__) . '/config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$auth = new Auth($db);
$auth->requireLogin();

// Array de meses
$mesesMap = [
    'jan' => 'Enero', 'feb' => 'Febrero', 'mar' => 'Marzo', 'apr' => 'Abril',
    'may' => 'Mayo', 'jun' => 'Junio', 'jul' => 'Julio', 'aug' => 'Agosto',
    'sep' => 'Septiembre', 'oct' => 'Octubre', 'nov' => 'Noviembre', 'dec' => 'Diciembre'
];

$mes_actual_sistema = strtolower(date('M'));
$mes_seleccionado = $_GET['mes'] ?? $mes_actual_sistema;

if (!array_key_exists($mes_seleccionado, $mesesMap)) {
    $mes_seleccionado = 'jan';
}

$nombre_mes_seleccionado = $mesesMap[$mes_seleccionado];

// Extraer POAs y Asignaciones del mes seleccionado
$actividades_mes = [];
$resumen_prog = 0.0;
$resumen_logro = 0.0;
$resumen_alertas = 0;

try {
    // Traer todas las actividades
    $q_poa = "SELECT id, codigo_maestro, marco_logico, descripcion_actividad, ext, programa, sector, operativo_estado, op_part_{$mes_seleccionado} AS meta_actividad_mes 
              FROM ah_poa 
              ORDER BY programa ASC, id ASC";
    $poas = $db->query($q_poa)->fetchAll(PDO::FETCH_ASSOC);

    // Traer las asignaciones del mes que tengan Meta o Logro mayor a 0
    $q_asig = "SELECT id_poa, tecnico, base_asignada, meta_{$mes_seleccionado} AS meta_mes, logro_{$mes_seleccionado} AS logro_mes 
               FROM ah_poa_asignaciones 
               WHERE meta_{$mes_seleccionado} > 0 OR logro_{$mes_seleccionado} > 0";
    $asignaciones_raw = $db->query($q_asig)->fetchAll(PDO::FETCH_ASSOC);

    // Mapear asignaciones por actividad
    $asig_map = [];
    foreach ($asignaciones_raw as $a) {
        $asig_map[$a['id_poa']][] = $a;
    }

    // Filtrar y calcular
    foreach ($poas as $poa) {
        $id = (int)$poa['id'];
        $asigs = $asig_map[$id] ?? [];
        
        $prog_equipo = 0.0;
        $logro_equipo = 0.0;
        
        foreach ($asigs as $a) {
            $prog_equipo += (float)$a['meta_mes'];
            $logro_equipo += (float)$a['logro_mes'];
        }

        $meta_poa = (float)$poa['meta_actividad_mes'];

        // Solo mostramos la actividad si tuvo movimiento en este mes específico
        if ($prog_equipo > 0 || $logro_equipo > 0 || $meta_poa > 0) {
            $pct = $prog_equipo > 0 ? min(100, round(($logro_equipo / $prog_equipo) * 100, 1)) : 0;
            
            $titulo = trim((string)($poa['descripcion_actividad'] ?? $poa['marco_logico'] ?? 'Actividad'));
            $codigo = trim((string)($poa['codigo_maestro'] ?? ''));
            if ($codigo === '') {
                $parts = preg_split('/\s+/', trim((string)($poa['marco_logico'] ?? '')));
                $codigo = $parts[0] ?? '';
            }

            if ($prog_equipo > 0 && $pct < 50) {
                $resumen_alertas++;
            }

            $actividades_mes[] = [
                'id' => $id,
                'codigo' => $codigo,
                'ext' => trim((string)($poa['ext'] ?? '')),
                'titulo' => $titulo,
                'programa' => trim((string)($poa['programa'] ?? 'General')),
                'sector' => trim((string)($poa['sector'] ?? '')),
                'estado_global' => trim((string)($poa['operativo_estado'] ?? 'Pendiente')),
                'meta_poa' => $meta_poa,
                'prog_equipo' => $prog_equipo,
                'logro_equipo' => $logro_equipo,
                'pct' => $pct,
                'equipo' => $asigs
            ];

            $resumen_prog += $prog_equipo;
            $resumen_logro += $logro_equipo;
        }
    }
} catch (Throwable $e) {
    $error_msg = "Error al cargar datos: " . $e->getMessage();
}

$resumen_pct = $resumen_prog > 0 ? min(100, round(($resumen_logro / $resumen_prog) * 100, 1)) : 0;

function esc($val) { return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'); }
function num($val) { $n = (float)$val; return fmod($n, 1) !== 0.0 ? number_format($n, 1, '.', ',') : number_format($n, 0, '.', ','); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Actividades | Acción Honduras</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/jquery@3.7.0/dist/jquery.min.js"></script>
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
:root{
    --primary:#34859b;--primary-dark:#246779;--green:#16a34a;--green-soft:#dcfce7;
    --blue-soft:#e0f2fe;--amber:#d97706;--amber-soft:#fef3c7;--red:#dc2626;
    --red-soft:#fee2e2;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--canvas:#f8fafc;
}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;background:var(--canvas);font-family:'Inter',sans-serif;color:var(--ink)}
.main-wrapper{flex:1;min-width:0;padding:30px 40px;overflow:auto}

.header-flex { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
.header-flex h1 { margin: 0; font-size: 2rem; font-weight: 900; color: var(--ink); }
.header-flex p { margin: 6px 0 0; color: var(--muted); font-size: 0.95rem; }

/* CINTILLO DE MESES */
.month-ribbon { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 15px; margin-bottom: 20px; scrollbar-width: none; }
.month-ribbon::-webkit-scrollbar { display: none; }
.month-ribbon-btn { padding: 10px 22px; border-radius: 999px; background: #fff; border: 1px solid var(--line); color: var(--muted); font-size: 0.88rem; font-weight: 800; cursor: pointer; text-decoration: none; white-space: nowrap; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); }
.month-ribbon-btn:hover { border-color: #bae6fd; color: #0284c7; background: #f0f9ff; transform: translateY(-1px); }
.month-ribbon-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); box-shadow: 0 6px 16px rgba(52,133,155,0.25); }

/* KPI DASHBOARD */
.summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 25px; }
.summary-card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 22px; box-shadow: 0 4px 15px rgba(15,23,42,0.03); display: flex; align-items: center; gap: 18px; }
.summary-icon { width: 56px; height: 56px; border-radius: 14px; display: grid; place-items: center; font-size: 1.6rem; flex-shrink: 0; }
.summary-icon.blue { background: #e0f2fe; color: #0284c7; }
.summary-icon.purple { background: #f3e8ff; color: #7e22ce; }
.summary-icon.green { background: #dcfce7; color: #16a34a; }
.summary-icon.orange { background: #ffedd5; color: #c2410c; }
.summary-info span { display: block; font-size: 0.8rem; font-weight: 850; text-transform: uppercase; color: var(--muted); letter-spacing: 0.5px; }
.summary-info strong { display: block; margin-top: 6px; font-size: 1.8rem; line-height: 1; color: var(--ink); }

/* TABLA HISTÓRICA */
.table-card { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: 0 4px 20px rgba(15,23,42,0.04); overflow: hidden; }
.table-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid var(--line); background: #f8fafc; }
.search-box { position: relative; width: 320px; }
.search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.search-box input { width: 100%; border: 1px solid #cbd5e1; border-radius: 999px; padding: 9px 15px 9px 40px; font-family: 'Inter', sans-serif; font-size: 0.88rem; outline: none; transition: 0.2s; }
.search-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(52,133,155,0.15); }
.btn-action { background: #fff; border: 1px solid #cbd5e1; color: #334155; padding: 9px 16px; border-radius: 9px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 0.88rem; transition: 0.2s; }
.btn-action:hover { background: #f1f5f9; border-color: #94a3b8; }
.btn-excel { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.btn-excel:hover { background: #16a34a; color: #fff; border-color: #16a34a; }

.styled-table { width: 100%; border-collapse: collapse; }
.styled-table th { background: #fff; color: #475569; font-size: 0.78rem; font-weight: 900; text-transform: uppercase; padding: 14px 24px; text-align: left; border-bottom: 2px solid var(--line); white-space: nowrap; }
.styled-table td { padding: 16px 24px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #1e293b; font-size: 0.9rem; }
.styled-table tbody tr.main-row:hover { background: #f8fafc; }

.prog-badge { display: inline-block; background: #f1f5f9; color: #334155; padding: 4px 8px; border-radius: 6px; font-size: 0.72rem; font-weight: 800; border: 1px solid #e2e8f0; margin-top: 4px; }
.code-pill { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; border-radius: 999px; padding: 4px 10px; font-size: 0.75rem; font-weight: 900; display: inline-block; margin-bottom: 5px; }

.metric-cell { font-variant-numeric: tabular-nums; font-weight: 900; font-size: 1rem; }
.pct-pill { display: inline-flex; min-width: 62px; justify-content: center; padding: 6px 10px; border-radius: 999px; font-weight: 900; font-size: 0.82rem; }
.pct-red { background: #fee2e2; color: #991b1b; }
.pct-yellow { background: #fef3c7; color: #92400e; }
.pct-green { background: #dcfce7; color: #166534; }
.pct-gray { background: #f1f5f9; color: #64748b; }

.btn-toggle-row { background: #eff6ff; color: #0284c7; border: 1px solid #bae6fd; padding: 6px 12px; border-radius: 8px; font-weight: 800; font-size: 0.8rem; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
.btn-toggle-row:hover, .btn-toggle-row.open { background: #0284c7; color: #fff; border-color: #0284c7; }

/* SUBTABLA EQUIPO */
.sub-row { display: none; background: #f8fafc; }
.sub-row-content { padding: 20px 24px 24px 60px; border-bottom: 1px solid var(--line); }
.team-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; border: 1px solid #bae6fd; }
.team-table th { background: #e0f2fe; color: #075985; padding: 10px 14px; font-size: 0.75rem; border-bottom: 1px solid #bae6fd; }
.team-table td { padding: 10px 14px; border-bottom: 1px solid #e0f2fe; font-size: 0.85rem; }
.team-table tr:last-child td { border-bottom: none; }
.empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
.empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 15px; }

@media(max-width: 1200px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } }
@media(max-width: 768px) { .summary-grid { grid-template-columns: 1fr; } .main-wrapper { padding: 20px; } }
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<main class="main-wrapper">
    <div class="header-flex">
        <div>
            <h1><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary)"></i> Histórico de Ejecución</h1>
            <p>Consulta el rendimiento de actividades y equipos en meses cerrados o pasados.</p>
        </div>
        <div style="text-align:right">
            <span style="display:inline-block;background:#fff;padding:8px 16px;border-radius:10px;border:1px solid var(--line);font-weight:800;color:var(--ink);box-shadow:0 2px 8px rgba(0,0,0,0.02)">
                <i class="fa-solid fa-calendar-days" style="color:var(--primary)"></i> Filtro activo: <span style="color:var(--primary)"><?php echo esc($nombre_mes_seleccionado); ?></span>
            </span>
        </div>
    </div>

    <!-- CINTILLO DE MESES -->
    <div class="month-ribbon">
        <?php foreach ($mesesMap as $k => $nombre): ?>
            <a href="?mes=<?php echo $k; ?>" class="month-ribbon-btn <?php echo $k === $mes_seleccionado ? 'active' : ''; ?>">
                <?php echo esc($nombre); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (isset($error_msg)): ?>
        <div style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:10px;margin-bottom:20px;font-weight:700;">
            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo esc($error_msg); ?>
        </div>
    <?php endif; ?>

    <!-- KPI DASHBOARD -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-icon blue"><i class="fa-solid fa-list-check"></i></div>
            <div class="summary-info"><span>Actividades en <?php echo esc($nombre_mes_seleccionado); ?></span><strong id="kpi-count"><?php echo count($actividades_mes); ?></strong></div>
        </div>
        <div class="summary-card">
            <div class="summary-icon purple"><i class="fa-solid fa-bullseye"></i></div>
            <div class="summary-info"><span>Total Programado</span><strong id="kpi-prog"><?php echo num($resumen_prog); ?></strong></div>
        </div>
        <div class="summary-card">
            <div class="summary-icon green"><i class="fa-solid fa-check-double"></i></div>
            <div class="summary-info"><span>Total Logrado</span><strong id="kpi-logro"><?php echo num($resumen_logro); ?></strong></div>
        </div>
        <div class="summary-card">
            <div class="summary-icon orange"><i class="fa-solid fa-chart-pie"></i></div>
            <div class="summary-info"><span>Efectividad Mensual</span><strong id="kpi-pct"><?php echo $resumen_pct; ?>%</strong></div>
        </div>
    </div>

    <!-- TABLA DE DATOS HISTÓRICOS -->
    <div class="table-card">
        <div class="table-toolbar">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="searchInput" placeholder="Buscar actividad, código o programa...">
            </div>
            <div>
                <button type="button" class="btn-action btn-excel" onclick="exportTableToExcel()"><i class="fa-solid fa-file-excel"></i> Exportar Mes</button>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="styled-table" id="historicoTable">
                <thead>
                    <tr>
                        <th style="width:35%">Actividad y Código</th>
                        <th style="width:20%">Programa / Sector</th>
                        <th style="width:12%;text-align:right">Programado</th>
                        <th style="width:12%;text-align:right">Logrado</th>
                        <th style="width:10%;text-align:center">% Mes</th>
                        <th style="width:11%;text-align:center">Desglose Equipo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($actividades_mes)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-folder-open"></i>
                                    <h3>No hay registros en <?php echo esc($nombre_mes_seleccionado); ?></h3>
                                    <p>No se encontraron actividades con programación o logros en este mes.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($actividades_mes as $act): 
                        $pctClass = $act['pct'] >= 85 ? 'pct-green' : ($act['pct'] >= 50 ? 'pct-yellow' : ($act['prog_equipo'] == 0 ? 'pct-gray' : 'pct-red'));
                    ?>
                        <tr class="main-row">
                            <td>
                                <?php if ($act['codigo']): ?><div class="code-pill"><i class="fa-solid fa-hashtag"></i> <?php echo esc($act['codigo']); ?></div><?php endif; ?>
                                <div style="font-weight:800;color:#0f172a;line-height:1.4" class="search-title"><?php echo esc($act['titulo']); ?></div>
                                <?php if ($act['ext']): ?><span class="prog-badge" style="background:#fffbeb;color:#92400e;border-color:#fde68a"><i class="fa-solid fa-code-branch"></i> EXT <?php echo esc($act['ext']); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:800;color:#0284c7" class="search-prog"><i class="fa-solid fa-layer-group"></i> <?php echo esc($act['programa']); ?></div>
                                <?php if ($act['sector']): ?><div class="prog-badge search-sec"><i class="fa-solid fa-tag"></i> <?php echo esc($act['sector']); ?></div><?php endif; ?>
                            </td>
                            <td class="metric-cell" style="text-align:right;color:#0284c7"><?php echo num($act['prog_equipo']); ?></td>
                            <td class="metric-cell" style="text-align:right;color:#16a34a"><?php echo num($act['logro_equipo']); ?></td>
                            <td style="text-align:center"><span class="pct-pill <?php echo $pctClass; ?>"><?php echo $act['pct']; ?>%</span></td>
                            <td style="text-align:center">
                                <?php if (!empty($act['equipo'])): ?>
                                    <button type="button" class="btn-toggle-row" onclick="toggleSubRow(<?php echo $act['id']; ?>, this)">
                                        <i class="fa-solid fa-chevron-down"></i> Ver (<?php echo count($act['equipo']); ?>)
                                    </button>
                                <?php else: ?>
                                    <span style="font-size:0.8rem;color:#94a3b8;font-weight:700">Sin equipo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- FILA DESPLEGABLE CON EL DETALLE DEL EQUIPO -->
                        <?php if (!empty($act['equipo'])): ?>
                        <tr class="sub-row" id="sub-<?php echo $act['id']; ?>">
                            <td colspan="6" style="padding:0">
                                <div class="sub-row-content">
                                    <table class="team-table">
                                        <thead>
                                            <tr>
                                                <th style="width:40%">Responsable / Técnico</th>
                                                <th style="width:30%">Base Geográfica</th>
                                                <th style="width:10%;text-align:right">Programado</th>
                                                <th style="width:10%;text-align:right">Logrado</th>
                                                <th style="width:10%;text-align:center">% Avance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($act['equipo'] as $miembro): 
                                                $m_prog = (float)$miembro['meta_mes'];
                                                $m_logro = (float)$miembro['logro_mes'];
                                                $m_pct = $m_prog > 0 ? min(100, round(($m_logro / $m_prog) * 100, 1)) : 0;
                                                $m_pctClass = $m_pct >= 85 ? 'pct-green' : ($m_pct >= 50 ? 'pct-yellow' : ($m_prog == 0 ? 'pct-gray' : 'pct-red'));
                                            ?>
                                            <tr>
                                                <td style="font-weight:800;color:#0f172a"><i class="fa-solid fa-user-check" style="color:var(--primary)"></i> <?php echo esc($miembro['tecnico']); ?></td>
                                                <td><?php echo esc($miembro['base_asignada'] ?: 'General / Sin base'); ?></td>
                                                <td class="metric-cell" style="text-align:right"><?php echo num($m_prog); ?></td>
                                                <td class="metric-cell" style="text-align:right;color:#16a34a"><?php echo num($m_logro); ?></td>
                                                <td style="text-align:center"><span style="font-weight:900;font-size:0.8rem;padding:3px 8px;border-radius:6px" class="<?php echo $m_pctClass; ?>"><?php echo $m_pct; ?>%</span></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
// Desplegar Sub-filas de Equipo
function toggleSubRow(id, btn) {
    const subRow = $('#sub-' + id);
    const icon = $(btn).find('i');
    
    if (subRow.is(':visible')) {
        subRow.hide();
        $(btn).removeClass('open');
        icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
    } else {
        subRow.show();
        $(btn).addClass('open');
        icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
    }
}

// Buscador en la tabla
$(document).ready(function(){
    $('#searchInput').on('keyup', function() {
        let value = $(this).val().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        
        $('#historicoTable tbody tr.main-row').each(function() {
            let row = $(this);
            let text = row.text().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
            
            if (text.indexOf(value) > -1) {
                row.show();
            } else {
                row.hide();
                // Si la fila principal se oculta, también ocultamos su detalle si estaba abierto
                row.next('.sub-row').hide();
                row.find('.btn-toggle-row').removeClass('open').find('i').removeClass('fa-chevron-up').addClass('fa-chevron-down');
            }
        });
    });
});

// Exportar a Excel
function exportTableToExcel() {
    if (typeof XLSX === 'undefined') {
        alert('La librería XLSX no está cargada.');
        return;
    }

    // Creamos una tabla limpia sin los botones de "Ver" ni detalles ocultos complejos
    let cloneTable = document.createElement('table');
    let thead = document.createElement('thead');
    thead.innerHTML = '<tr><th>Código</th><th>Actividad</th><th>Programa</th><th>Programado</th><th>Logrado</th><th>% Avance</th></tr>';
    cloneTable.appendChild(thead);
    
    let tbody = document.createElement('tbody');
    $('#historicoTable tbody tr.main-row').each(function() {
        if($(this).is(':visible')) {
            let tr = document.createElement('tr');
            
            // Extraer datos limpios
            let code = $(this).find('.code-pill').text().trim() || '';
            let title = $(this).find('.search-title').text().trim();
            let prog = $(this).find('.search-prog').text().trim();
            let programado = $(this).find('td:nth-child(3)').text().trim();
            let logrado = $(this).find('td:nth-child(4)').text().trim();
            let pct = $(this).find('.pct-pill').text().trim();
            
            tr.innerHTML = `<td>${code}</td><td>${title}</td><td>${prog}</td><td>${programado}</td><td>${logrado}</td><td>${pct}</td>`;
            tbody.appendChild(tr);
        }
    });
    cloneTable.appendChild(tbody);

    let wb = XLSX.utils.table_to_book(cloneTable, {sheet: "Histórico <?php echo esc($nombre_mes_seleccionado); ?>"});
    XLSX.writeFile(wb, "Historico_Actividades_<?php echo esc($nombre_mes_seleccionado); ?>.xlsx");
}
</script>
</body>
</html>