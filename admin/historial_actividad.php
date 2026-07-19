<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/*
 * Diagnóstico visible: evita páginas completamente en blanco cuando el
 * servidor oculta errores fatales en producción.
 */
set_exception_handler(function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Error del histórico</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;padding:30px;color:#1e293b}.err{max-width:900px;margin:auto;background:#fff;border:1px solid #fecaca;border-left:6px solid #dc2626;border-radius:12px;padding:22px;box-shadow:0 10px 25px rgba(15,23,42,.08)}h1{margin-top:0;color:#991b1b}pre{white-space:pre-wrap;background:#fef2f2;padding:12px;border-radius:8px;overflow:auto}</style></head><body>';
    echo '<div class="err"><h1>No se pudo cargar el histórico</h1><p>El servidor devolvió el siguiente error:</p><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre><p><a href="monitoreo.php">Volver a monitoreo</a></p></div></body></html>';
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Error fatal</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;padding:30px;color:#1e293b}.err{max-width:900px;margin:auto;background:#fff;border:1px solid #fecaca;border-left:6px solid #dc2626;border-radius:12px;padding:22px}h1{color:#991b1b}pre{white-space:pre-wrap;background:#fef2f2;padding:12px;border-radius:8px}</style></head><body>';
    echo '<div class="err"><h1>Error fatal en historial_actividad.php</h1><pre>' . htmlspecialchars($error['message'] . ' en línea ' . $error['line'], ENT_QUOTES, 'UTF-8') . '</pre></div></body></html>';
});

if (PHP_VERSION_ID < 70000) {
    exit('Este visor requiere PHP 7.0 o superior.');
}

session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$auth = new Auth($db);
$auth->requireLogin();
$auth->checkAccess('monitoreo.php', $db);

$idPoa = (int)($_GET['id'] ?? 0);
if ($idPoa <= 0) {
    http_response_code(400);
    exit('Actividad inválida.');
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jdecode($value, $default = []) {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return $default;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}

function n($value): float {
    return is_numeric($value) ? (float)$value : 0.0;
}

function qualityValue(array $row, string $field): float {
    $initialized = !empty($row['quality_initialized']);
    if (!$initialized) return 100.0;
    return max(0, min(100, n($row[$field] ?? 100)));
}

function rowProgress(array $row): float {
    $programado = n($row['a_lograr'] ?? 0);
    $cumplido = n($row['cumplido'] ?? 0);
    if ($programado <= 0 || $cumplido <= 0) return 0.0;
    $cantidad = min(100, ($cumplido / $programado) * 100);
    $calidad = (qualityValue($row, 'a_tiempo') + qualityValue($row, 'en_forma')) / 2;
    return round(max(0, min(100, $cantidad * ($calidad / 100))), 2);
}

function stageSummary(array $stage): array {
    $rows = jdecode($stage['involucrados_json'] ?? '{}', []);
    $programado = 0.0;
    $cumplido = 0.0;
    $atSum = 0.0;
    $efSum = 0.0;
    $progressSum = 0.0;
    $count = 0;
    $centers = 0;

    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['deleted'])) continue;
        $programado += n($row['a_lograr'] ?? 0);
        $cumplido += n($row['cumplido'] ?? 0);
        $atSum += qualityValue($row, 'a_tiempo');
        $efSum += qualityValue($row, 'en_forma');
        $progressSum += rowProgress($row);
        $centers += is_array($row['centros'] ?? null) ? count($row['centros']) : 0;
        $count++;
    }

    return [
        'rows' => $count,
        'programado' => $programado,
        'cumplido' => $cumplido,
        'a_tiempo' => $count ? round($atSum / $count, 2) : 100,
        'en_forma' => $count ? round($efSum / $count, 2) : 100,
        'progreso' => $count ? round($progressSum / $count, 2) : 0,
        'centros' => $centers
    ];
}

function activityProgress(array $stages): float {
    $byOrder = [];
    foreach ($stages as $stage) {
        $order = (int)($stage['orden'] ?? 0);
        if ($order >= 1 && $order <= 4) $byOrder[$order] = stageSummary($stage)['progreso'];
    }
    $total = 0.0;
    for ($i = 1; $i <= 4; $i++) $total += $byOrder[$i] ?? 0;
    return round($total / 4, 2);
}

function currentUserLabel(): string {
    $values = [
        $_SESSION['email'] ?? null,
        $_SESSION['user_email'] ?? null,
        $_SESSION['correo'] ?? null,
        $_SESSION['name'] ?? null,
        $_SESSION['nombre'] ?? null,
        is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? ($_SESSION['user']['nombre'] ?? null)) : null
    ];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') return $value;
    }
    return 'Usuario autenticado';
}

$historyAvailable = true;
$historyWarning = '';
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_monitoreo_historial (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        id_poa INT NOT NULL,
        periodo VARCHAR(7) NOT NULL,
        evento VARCHAR(80) NOT NULL,
        estado_json LONGTEXT NOT NULL,
        estado_hash CHAR(64) NOT NULL,
        usuario VARCHAR(190) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hist_poa_fecha (id_poa, created_at),
        INDEX idx_hist_poa_periodo (id_poa, periodo)
    )");
} catch (Throwable $e) {
    // El visor actual sigue funcionando aunque el usuario SQL no tenga permiso CREATE.
    $historyAvailable = false;
    $historyWarning = 'No se pudo habilitar la línea de tiempo histórica: ' . $e->getMessage();
}

$st = $db->prepare("SELECT * FROM ah_poa WHERE id = ? LIMIT 1");
$st->execute([$idPoa]);
$activity = $st->fetch(PDO::FETCH_ASSOC);
if (!$activity) {
    http_response_code(404);
    exit('La actividad no existe.');
}

$st = $db->prepare("SELECT * FROM ah_poa_asignaciones WHERE id_poa = ? ORDER BY tecnico ASC, base_asignada ASC, id ASC");
$st->execute([$idPoa]);
$assignments = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id_poa = ? ORDER BY orden ASC, id ASC");
$st->execute([$idPoa]);
$stages = $st->fetchAll(PDO::FETCH_ASSOC);

$currentState = [
    'poa' => $activity,
    'asignaciones' => $assignments,
    'etapas' => $stages
];

$historyAsc = [];
$historyDesc = [];
if ($historyAvailable) {
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM ah_monitoreo_historial WHERE id_poa = ?");
        $st->execute([$idPoa]);
        if ((int)$st->fetchColumn() === 0) {
            $json = json_encode($currentState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $ins = $db->prepare("INSERT INTO ah_monitoreo_historial
                    (id_poa, periodo, evento, estado_json, estado_hash, usuario)
                    VALUES (?, ?, 'apertura_historial', ?, ?, ?)");
                $ins->execute([$idPoa, date('Y-m'), $json, hash('sha256', $json), currentUserLabel()]);
            }
        }

        $st = $db->prepare("SELECT * FROM ah_monitoreo_historial WHERE id_poa = ? ORDER BY id ASC LIMIT 500");
        $st->execute([$idPoa]);
        $historyAsc = $st->fetchAll(PDO::FETCH_ASSOC);
        $historyDesc = array_reverse($historyAsc);
    } catch (Throwable $e) {
        $historyAvailable = false;
        $historyWarning = 'No se pudo leer la línea de tiempo histórica: ' . $e->getMessage();
        $historyAsc = [];
        $historyDesc = [];
    }
}

$months = [
    'jul' => 'Jul', 'aug' => 'Ago', 'sep' => 'Sep', 'oct' => 'Oct',
    'nov' => 'Nov', 'dec' => 'Dic', 'jan' => 'Ene', 'feb' => 'Feb',
    'mar' => 'Mar', 'apr' => 'Abr', 'may' => 'May', 'jun' => 'Jun'
];

$monthly = [];
foreach ($months as $key => $label) {
    $teamProgramado = 0.0;
    $teamLogrado = 0.0;
    foreach ($assignments as $assignment) {
        $teamProgramado += n($assignment['meta_' . $key] ?? 0);
        $teamLogrado += n($assignment['logro_' . $key] ?? 0);
    }
    $monthly[$key] = [
        'label' => $label,
        'act' => n($activity['op_act_' . $key] ?? 0),
        'part' => n($activity['op_part_' . $key] ?? 0),
        'programado' => $teamProgramado,
        'logrado' => $teamLogrado,
        'pct' => $teamProgramado > 0 ? min(150, ($teamLogrado / $teamProgramado) * 100) : 0
    ];
}

$stageSummaries = [];
foreach ($stages as $stage) {
    $stageSummaries[] = [
        'stage' => $stage,
        'summary' => stageSummary($stage)
    ];
}

$centerRows = [];
foreach ($stages as $stage) {
    if ((int)($stage['orden'] ?? 0) !== 3 && strtoupper(trim((string)($stage['codigo_etapa'] ?? ''))) !== 'E-3') continue;
    $involved = jdecode($stage['involucrados_json'] ?? '{}', []);
    foreach ($involved as $row) {
        if (!is_array($row) || !empty($row['deleted'])) continue;
        $centers = is_array($row['centros'] ?? null) ? $row['centros'] : [];
        foreach ($centers as $center) {
            if (!is_array($center)) continue;
            $centerRows[] = [
                'responsable' => $row['persona'] ?? '',
                'base' => $row['base'] ?? '',
                'lugares' => is_array($row['lugar'] ?? null) ? implode(', ', $row['lugar']) : (string)($row['lugar'] ?? ''),
                'unidad' => $row['unidad'] ?? '',
                'id' => $center['id'] ?? '',
                'tipo' => $center['tipo'] ?? '',
                'nombre' => $center['nombre'] ?? '',
                'comunidad' => $center['comunidad_base'] ?? '',
                'caserio' => $center['caserio'] ?? '',
                'programado' => n($center['a_lograr'] ?? 0),
                'cumplido' => n($center['cumplido'] ?? 0),
                'a_tiempo' => qualityValue($center, 'a_tiempo'),
                'en_forma' => qualityValue($center, 'en_forma'),
                'pct' => rowProgress($center)
            ];
        }
    }
}

$currentProgress = activityProgress($stages);
$teamTotalProgramado = array_sum(array_column($monthly, 'programado'));
$teamTotalLogrado = array_sum(array_column($monthly, 'logrado'));
$teamPct = $teamTotalProgramado > 0 ? min(150, ($teamTotalLogrado / $teamTotalProgramado) * 100) : 0;

$historyChart = [];
foreach ($historyAsc as $item) {
    $state = jdecode($item['estado_json'] ?? '{}', []);
    $snapshotStages = is_array($state['etapas'] ?? null) ? $state['etapas'] : [];
    $snapshotAssignments = is_array($state['asignaciones'] ?? null) ? $state['asignaciones'] : [];
    $p = activityProgress($snapshotStages);
    $programado = 0.0;
    $logrado = 0.0;
    foreach ($snapshotAssignments as $a) {
        foreach (array_keys($months) as $m) {
            $programado += n($a['meta_' . $m] ?? 0);
            $logrado += n($a['logro_' . $m] ?? 0);
        }
    }
    $historyChart[] = [
        'label' => date('d/m/Y H:i', strtotime((string)($item['updated_at'] ?: $item['created_at']))),
        'progress' => $p,
        'programado' => $programado,
        'logrado' => $logrado
    ];
}

$stageChartData = [];
foreach ($stageSummaries as $entry) {
    $stageInfo = isset($entry['stage']) && is_array($entry['stage']) ? $entry['stage'] : [];
    $summaryInfo = isset($entry['summary']) && is_array($entry['summary']) ? $entry['summary'] : [];
    $stageChartData[] = [
        'label' => trim((string)($stageInfo['codigo_etapa'] ?? '') . ' ' . (string)($stageInfo['nombre_etapa'] ?? '')),
        'progress' => n($summaryInfo['progreso'] ?? 0),
        'ontime' => n($summaryInfo['a_tiempo'] ?? 100),
        'inform' => n($summaryInfo['en_forma'] ?? 100)
    ];
}

$title = trim((string)($activity['descripcion_actividad'] ?? ''));
if ($title === '') $title = trim((string)($activity['marco_logico'] ?? 'Actividad'));
$code = trim((string)($activity['codigo_maestro'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de actividad | Acción Honduras</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/chart.js@4.4.3/dist/chart.umd.js"></script>
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
:root{--primary:#34859B;--dark:#0f172a;--muted:#64748b;--border:#dbe4ee;--canvas:#f8fafc;--green:#166534;--orange:#c2410c;--red:#991b1b}
*{box-sizing:border-box}body{margin:0;background:var(--canvas);font-family:'Inter',sans-serif;color:#1e293b;display:flex;min-height:100vh}
.main{flex:1;padding:28px 34px;min-width:0}.topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:20px}
.title h1{margin:0;font-size:1.8rem;color:var(--dark)}.title p{margin:7px 0 0;color:var(--muted);line-height:1.45}.actions{display:flex;gap:9px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border-radius:9px;border:1px solid var(--border);background:#fff;color:#334155;font-weight:800;text-decoration:none;cursor:pointer}.btn:hover{border-color:var(--primary);color:var(--primary)}.btn-xlsx{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.chip{display:inline-flex;gap:6px;align-items:center;padding:5px 9px;border-radius:999px;font-size:.78rem;font-weight:800;border:1px solid #bae6fd;background:#e0f2fe;color:#075985}.chip.ext{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
.metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin-bottom:18px}.metric{background:#fff;border:1px solid var(--border);border-radius:14px;padding:16px;border-left:5px solid var(--primary)}.metric span{display:block;color:var(--muted);font-size:.74rem;font-weight:900;text-transform:uppercase}.metric strong{display:block;font-size:1.45rem;margin-top:6px;color:var(--dark)}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:0 5px 18px rgba(15,23,42,.035)}.card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:13px}.card h2,.card h3{margin:0;color:var(--dark)}.chart-wrap{height:300px}
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:10px}.data-table{width:100%;border-collapse:collapse;min-width:900px;background:#fff}.data-table th{position:sticky;top:0;background:#eef6fb;color:#075985;font-size:.76rem;text-transform:uppercase;letter-spacing:.3px;padding:10px;border-bottom:1px solid #bae6fd;text-align:left;z-index:2}.data-table td{padding:10px;border-bottom:1px solid #edf2f7;font-size:.84rem;vertical-align:top}.data-table tr:nth-child(even) td{background:#fafcff}.num{text-align:right;font-variant-numeric:tabular-nums}.pct{display:inline-block;min-width:58px;text-align:center;padding:5px 8px;border-radius:999px;font-weight:900}.pct.red{background:#fee2e2;color:#991b1b}.pct.orange{background:#ffedd5;color:#9a3412}.pct.soft{background:#dcfce7;color:#166534}.pct.green{background:#14532d;color:#fff}
.stage-card{border:1px solid var(--border);border-radius:12px;margin-bottom:12px;overflow:hidden}.stage-head{background:#f0f9ff;padding:12px 14px;display:flex;justify-content:space-between;gap:12px;align-items:center}.stage-head strong{color:#075985}.stage-body{padding:13px}
.timeline{position:relative;padding-left:23px}.timeline:before{content:'';position:absolute;left:8px;top:5px;bottom:5px;width:2px;background:#cbd5e1}.timeline-item{position:relative;margin-bottom:13px;background:#fff;border:1px solid var(--border);border-radius:11px;padding:13px}.timeline-item:before{content:'';position:absolute;left:-21px;top:18px;width:10px;height:10px;border-radius:50%;background:var(--primary);border:3px solid #e0f2fe}.timeline-meta{display:flex;gap:10px;flex-wrap:wrap;color:var(--muted);font-size:.76rem;margin-bottom:7px}.timeline-title{font-weight:900;color:var(--dark)}details{margin-top:8px}summary{cursor:pointer;color:#075985;font-weight:800}
.empty{padding:28px;text-align:center;color:var(--muted)}
@media(max-width:1200px){.metrics{grid-template-columns:repeat(3,1fr)}.grid-2{grid-template-columns:1fr}}
@media(max-width:760px){.main{padding:18px}.metrics{grid-template-columns:1fr 1fr}.topbar{flex-direction:column}}
@media print{body{display:block}.main{padding:0}.actions,.btn{display:none!important}.card{break-inside:avoid;box-shadow:none}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main">
    <div class="topbar">
        <div class="title">
            <h1><i class="fa-solid fa-clock-rotate-left" style="color:var(--primary)"></i> Histórico de la actividad</h1>
            <div class="chips">
                <?php if ($code !== ''): ?><span class="chip"><i class="fa-solid fa-hashtag"></i><?= h($code) ?></span><?php endif; ?>
                <?php if (trim((string)($activity['ext'] ?? '')) !== ''): ?><span class="chip ext"><i class="fa-solid fa-code-branch"></i>EXT <?= h($activity['ext']) ?></span><?php endif; ?>
                <span class="chip"><i class="fa-solid fa-layer-group"></i><?= h($activity['programa'] ?? '') ?></span>
                <span class="chip"><i class="fa-solid fa-tag"></i><?= h($activity['sector'] ?? '') ?></span>
            </div>
            <p><strong><?= h($title) ?></strong></p>
        </div>
        <div class="actions">
            <a class="btn" href="monitoreo.php"><i class="fa-solid fa-arrow-left"></i> Volver a monitoreo</a>
            <button class="btn btn-xlsx" onclick="exportAll()"><i class="fa-solid fa-file-excel"></i> Exportar todo</button>
            <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir</button>
        </div>
    </div>

    <?php if ($historyWarning !== ''): ?>
    <div style="margin-bottom:16px;padding:13px 15px;border:1px solid #fde68a;border-left:5px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:10px;font-weight:700;">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= h($historyWarning) ?>
    </div>
    <?php endif; ?>

    <section class="metrics">
        <div class="metric"><span>Estado programático</span><strong><?= number_format($currentProgress, 1) ?>%</strong></div>
        <div class="metric" style="border-left-color:#16a34a"><span>Programado equipo</span><strong><?= number_format($teamTotalProgramado, 0) ?></strong></div>
        <div class="metric" style="border-left-color:#0ea5e9"><span>Logrado equipo</span><strong><?= number_format($teamTotalLogrado, 0) ?></strong></div>
        <div class="metric" style="border-left-color:#f59e0b"><span>Cumplimiento equipo</span><strong><?= number_format($teamPct, 1) ?>%</strong></div>
        <div class="metric" style="border-left-color:#8b5cf6"><span>Cortes históricos</span><strong><?= count($historyAsc) ?></strong></div>
    </section>

    <div class="grid-2">
        <section class="card">
            <div class="card-head"><h2>Evolución mensual</h2></div>
            <div class="chart-wrap"><canvas id="monthlyChart"></canvas></div>
        </section>
        <section class="card">
            <div class="card-head"><h2>Evolución de cambios</h2></div>
            <div class="chart-wrap"><canvas id="historyChart"></canvas></div>
        </section>
    </div>

    <section class="card">
        <div class="card-head">
            <h2>Metas y ejecución mensual</h2>
            <button class="btn btn-xlsx" onclick="exportTable('#monthly-table','historico_mensual')"><i class="fa-solid fa-file-excel"></i> XLSX</button>
        </div>
        <div class="table-wrap">
            <table id="monthly-table" class="data-table">
                <thead><tr><th>Mes</th><th class="num">Actividades POA</th><th class="num">Participantes POA</th><th class="num">Programado equipo</th><th class="num">Logrado equipo</th><th class="num">Diferencia</th><th class="num">%</th></tr></thead>
                <tbody>
                <?php foreach ($monthly as $row): ?>
                    <tr>
                        <td><strong><?= h($row['label']) ?></strong></td>
                        <td class="num"><?= number_format($row['act'], 1) ?></td>
                        <td class="num"><?= number_format($row['part'], 1) ?></td>
                        <td class="num"><?= number_format($row['programado'], 1) ?></td>
                        <td class="num"><?= number_format($row['logrado'], 1) ?></td>
                        <td class="num"><?= number_format($row['programado'] - $row['logrado'], 1) ?></td>
                        <td class="num"><?= number_format($row['pct'], 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Equipo asignado</h2>
            <button class="btn btn-xlsx" onclick="exportTable('#team-table','historico_equipo')"><i class="fa-solid fa-file-excel"></i> XLSX</button>
        </div>
        <div class="table-wrap">
            <table id="team-table" class="data-table">
                <thead><tr><th>Técnico</th><th>Base</th><th>Lugares</th><?php foreach ($months as $label): ?><th class="num"><?= h($label) ?> Prog.</th><th class="num"><?= h($label) ?> Logr.</th><?php endforeach; ?><th class="num">Total Prog.</th><th class="num">Total Logr.</th><th class="num">%</th></tr></thead>
                <tbody>
                <?php foreach ($assignments as $a):
                    $tp = 0; $tl = 0;
                    foreach (array_keys($months) as $m) { $tp += n($a['meta_'.$m] ?? 0); $tl += n($a['logro_'.$m] ?? 0); }
                    $ap = $tp > 0 ? ($tl / $tp) * 100 : 0;
                    $places = jdecode($a['lugares_json'] ?? '[]', []);
                ?>
                    <tr>
                        <td><strong><?= h($a['tecnico'] ?? '') ?></strong></td>
                        <td><?= h($a['base_asignada'] ?: 'Sin base') ?></td>
                        <td><?= h(implode(', ', $places)) ?></td>
                        <?php foreach (array_keys($months) as $m): ?>
                            <td class="num"><?= number_format(n($a['meta_'.$m] ?? 0), 1) ?></td>
                            <td class="num"><?= number_format(n($a['logro_'.$m] ?? 0), 1) ?></td>
                        <?php endforeach; ?>
                        <td class="num"><strong><?= number_format($tp, 1) ?></strong></td>
                        <td class="num"><strong><?= number_format($tl, 1) ?></strong></td>
                        <td class="num"><?= number_format($ap, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$assignments): ?><tr><td colspan="30" class="empty">No hay técnicos asignados.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Desempeño por etapa</h2>
            <button class="btn btn-xlsx" onclick="exportTable('#stages-table','historico_etapas')"><i class="fa-solid fa-file-excel"></i> XLSX</button>
        </div>
        <div class="chart-wrap" style="height:260px;margin-bottom:14px"><canvas id="stagesChart"></canvas></div>
        <div class="table-wrap">
            <table id="stages-table" class="data-table">
                <thead><tr><th>Etapa</th><th>Descripción</th><th>Unidades</th><th>Responsables</th><th>Fecha máxima</th><th class="num">Prog.</th><th class="num">Cumpl.</th><th class="num">A tiempo</th><th class="num">En forma</th><th class="num">%</th><th class="num">Centros</th></tr></thead>
                <tbody>
                <?php foreach ($stageSummaries as $entry): $stage=$entry['stage']; $s=$entry['summary']; ?>
                    <tr>
                        <td><strong><?= h(($stage['codigo_etapa'] ?? '').' '.($stage['nombre_etapa'] ?? '')) ?></strong></td>
                        <td><?= h($stage['descripcion_etapa'] ?? '') ?></td>
                        <td><?= h(implode(', ', jdecode($stage['unidad_medida'] ?? '[]', []))) ?></td>
                        <td><?= h(implode(', ', jdecode($stage['responsable'] ?? '[]', []))) ?></td>
                        <td><?= h($stage['fecha_recepcion'] ?? '') ?></td>
                        <td class="num"><?= number_format($s['programado'],1) ?></td>
                        <td class="num"><?= number_format($s['cumplido'],1) ?></td>
                        <td class="num"><?= number_format($s['a_tiempo'],1) ?>%</td>
                        <td class="num"><?= number_format($s['en_forma'],1) ?>%</td>
                        <td class="num"><?= number_format($s['progreso'],1) ?>%</td>
                        <td class="num"><?= number_format($s['centros']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Detalle por responsable y etapa</h2>
            <button class="btn btn-xlsx" onclick="exportTable('#responsibles-table','historico_responsables')"><i class="fa-solid fa-file-excel"></i> XLSX</button>
        </div>
        <div class="table-wrap">
            <table id="responsibles-table" class="data-table">
                <thead><tr><th>Etapa</th><th>Responsable</th><th>Bases</th><th>Unidad</th><th>Lugares</th><th>Medios de verificación</th><th class="num">Prog.</th><th class="num">Cumpl.</th><th class="num">A tiempo</th><th class="num">En forma</th><th class="num">%</th></tr></thead>
                <tbody>
                <?php foreach ($stages as $stage):
                    $rows=jdecode($stage['involucrados_json'] ?? '{}', []);
                    foreach ($rows as $row):
                        if (!is_array($row) || !empty($row['deleted'])) continue;
                ?>
                    <tr>
                        <td><?= h(($stage['codigo_etapa'] ?? '').' '.($stage['nombre_etapa'] ?? '')) ?></td>
                        <td><strong><?= h($row['persona'] ?? '') ?></strong></td>
                        <td><?= h(str_replace('|', ', ', (string)($row['base'] ?? ''))) ?></td>
                        <td><?= h($row['unidad'] ?? '') ?></td>
                        <td><?= h(implode(', ', is_array($row['lugar'] ?? null) ? $row['lugar'] : [])) ?></td>
                        <td><?= h(implode(', ', is_array($row['verifics'] ?? null) ? $row['verifics'] : [])) ?></td>
                        <td class="num"><?= number_format(n($row['a_lograr'] ?? 0),1) ?></td>
                        <td class="num"><?= number_format(n($row['cumplido'] ?? 0),1) ?></td>
                        <td class="num"><?= number_format(qualityValue($row,'a_tiempo'),1) ?>%</td>
                        <td class="num"><?= number_format(qualityValue($row,'en_forma'),1) ?>%</td>
                        <td class="num"><?= number_format(rowProgress($row),1) ?>%</td>
                    </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Centros vinculados en la etapa 3</h2>
            <button class="btn btn-xlsx" onclick="exportTable('#centers-table','historico_centros')"><i class="fa-solid fa-file-excel"></i> XLSX</button>
        </div>
        <div class="table-wrap">
            <table id="centers-table" class="data-table">
                <thead><tr><th>Responsable</th><th>Bases</th><th>Lugar</th><th>Tipo</th><th>Centro</th><th>Comunidad</th><th>Caserío</th><th class="num">Prog.</th><th class="num">Cumpl.</th><th class="num">A tiempo</th><th class="num">En forma</th><th class="num">%</th></tr></thead>
                <tbody>
                <?php foreach ($centerRows as $center): ?>
                    <tr>
                        <td><strong><?= h($center['responsable']) ?></strong></td>
                        <td><?= h(str_replace('|', ', ', $center['base'])) ?></td>
                        <td><?= h($center['lugares']) ?></td>
                        <td><?= h($center['tipo']) ?></td>
                        <td><strong><?= h($center['nombre']) ?></strong><br><small>ID <?= h($center['id']) ?></small></td>
                        <td><?= h($center['comunidad']) ?></td>
                        <td><?= h($center['caserio']) ?></td>
                        <td class="num"><?= number_format($center['programado'],1) ?></td>
                        <td class="num"><?= number_format($center['cumplido'],1) ?></td>
                        <td class="num"><?= number_format($center['a_tiempo'],1) ?>%</td>
                        <td class="num"><?= number_format($center['en_forma'],1) ?>%</td>
                        <td class="num"><?= number_format($center['pct'],1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$centerRows): ?><tr><td colspan="12" class="empty">La etapa 3 todavía no contiene detalles de centros.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Línea de tiempo de cambios</h2>
            <button class="btn btn-xlsx" onclick="exportTable('#timeline-table','linea_tiempo_actividad')"><i class="fa-solid fa-file-excel"></i> XLSX</button>
        </div>
        <div class="table-wrap" style="margin-bottom:16px">
            <table id="timeline-table" class="data-table">
                <thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th class="num">Avance actividad</th><th class="num">Programado equipo</th><th class="num">Logrado equipo</th></tr></thead>
                <tbody>
                <?php foreach ($historyDesc as $item):
                    $state=jdecode($item['estado_json'] ?? '{}', []);
                    $snapshotStages=is_array($state['etapas'] ?? null)?$state['etapas']:[];
                    $snapshotAssignments=is_array($state['asignaciones'] ?? null)?$state['asignaciones']:[];
                    $sp=activityProgress($snapshotStages);
                    $smeta=0;$slogro=0;
                    foreach($snapshotAssignments as $a){foreach(array_keys($months) as $m){$smeta+=n($a['meta_'.$m]??0);$slogro+=n($a['logro_'.$m]??0);}}
                ?>
                    <tr><td><?= h(date('d/m/Y H:i:s',strtotime((string)($item['updated_at'] ?: $item['created_at'])))) ?></td><td><?= h(str_replace('_',' ',ucfirst((string)$item['evento']))) ?></td><td><?= h($item['usuario'] ?? '') ?></td><td class="num"><?= number_format($sp,1) ?>%</td><td class="num"><?= number_format($smeta,1) ?></td><td class="num"><?= number_format($slogro,1) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="timeline">
        <?php foreach (array_slice($historyDesc,0,100) as $item):
            $state=jdecode($item['estado_json'] ?? '{}', []);
            $snapshotStages=is_array($state['etapas'] ?? null)?$state['etapas']:[];
            $snapshotAssignments=is_array($state['asignaciones'] ?? null)?$state['asignaciones']:[];
            $sp=activityProgress($snapshotStages);
        ?>
            <article class="timeline-item">
                <div class="timeline-meta">
                    <span><i class="fa-solid fa-calendar"></i> <?= h(date('d/m/Y H:i:s',strtotime((string)($item['updated_at'] ?: $item['created_at'])))) ?></span>
                    <span><i class="fa-solid fa-user"></i> <?= h($item['usuario'] ?? '') ?></span>
                    <span><i class="fa-solid fa-chart-line"></i> <?= number_format($sp,1) ?>%</span>
                </div>
                <div class="timeline-title"><?= h(str_replace('_',' ',ucfirst((string)$item['evento']))) ?></div>
                <details>
                    <summary>Ver detalle del corte</summary>
                    <div style="margin-top:10px">
                        <strong>Asignaciones:</strong> <?= count($snapshotAssignments) ?> ·
                        <strong>Etapas:</strong> <?= count($snapshotStages) ?>
                        <pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;max-height:320px;overflow:auto;font-size:.72rem"><?= h(json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre>
                    </div>
                </details>
            </article>
        <?php endforeach; ?>
        </div>
    </section>

    <?php if (trim((string)($activity['operativo_info_adicional'] ?? '')) !== ''): ?>
    <section class="card">
        <div class="card-head"><h2>Notas y materiales actuales</h2></div>
        <div><?= $activity['operativo_info_adicional'] ?></div>
    </section>
    <?php endif; ?>
</main>

<script>
const monthlyData=<?= json_encode(array_values($monthly),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const historyData=<?= json_encode($historyChart,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const stageData=<?= json_encode($stageChartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

if (typeof Chart !== 'undefined') {
new Chart(document.getElementById('monthlyChart'),{
    type:'bar',
    data:{labels:monthlyData.map(x=>x.label),datasets:[
        {label:'Programado equipo',data:monthlyData.map(x=>x.programado)},
        {label:'Logrado equipo',data:monthlyData.map(x=>x.logrado)},
        {label:'Participantes POA',data:monthlyData.map(x=>x.part),type:'line',tension:.25}
    ]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true}}}
});
new Chart(document.getElementById('historyChart'),{
    type:'line',
    data:{labels:historyData.map(x=>x.label),datasets:[
        {label:'Avance actividad %',data:historyData.map(x=>x.progress),tension:.25},
        {label:'Cumplimiento equipo %',data:historyData.map(x=>x.programado>0?Math.min(150,(x.logrado/x.programado)*100):0),tension:.25}
    ]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,suggestedMax:100}}}
});
new Chart(document.getElementById('stagesChart'),{
    type:'bar',
    data:{labels:stageData.map(x=>x.label),datasets:[
        {label:'Avance %',data:stageData.map(x=>x.progress)},
        {label:'A tiempo %',data:stageData.map(x=>x.ontime)},
        {label:'En forma %',data:stageData.map(x=>x.inform)}
    ]},
    options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,max:100}}}
});
} else {
    document.querySelectorAll('.chart-wrap').forEach(function(el){
        el.innerHTML = '<div class="empty">No se pudo cargar la librería de gráficos. Las tablas siguen disponibles.</div>';
    });
}

function cleanTable(table){
    const clone=table.cloneNode(true);
    clone.querySelectorAll('button,script,style').forEach(el=>el.remove());
    return clone;
}
function exportTable(selector,file){
    if(typeof XLSX==='undefined'){alert('No se pudo cargar XLSX.');return;}
    const table=document.querySelector(selector);
    if(!table)return;
    const wb=XLSX.utils.table_to_book(cleanTable(table),{sheet:'Datos'});
    XLSX.writeFile(wb,file+'.xlsx');
}
function exportAll(){
    if(typeof XLSX==='undefined'){alert('No se pudo cargar XLSX.');return;}
    const wb=XLSX.utils.book_new();
    [
        ['Mensual','#monthly-table'],
        ['Equipo','#team-table'],
        ['Etapas','#stages-table'],
        ['Responsables','#responsibles-table'],
        ['Centros','#centers-table'],
        ['Linea_tiempo','#timeline-table']
    ].forEach(([name,selector])=>{
        const table=document.querySelector(selector);
        if(!table)return;
        const ws=XLSX.utils.table_to_sheet(cleanTable(table));
        XLSX.utils.book_append_sheet(wb,ws,name.slice(0,31));
    });
    XLSX.writeFile(wb,'historico_actividad_<?= h($code ?: $idPoa) ?>.xlsx');
}
</script>
</body>
</html>
