<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth = new Auth($db);
$auth->requireLogin();
$auth->checkAccess(basename($_SERVER['PHP_SELF']), $db);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function num($value): float {
    return is_numeric($value) ? (float)$value : 0.0;
}
function safeJson($value, $default = []) {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return $default;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}
function tableExists(PDO $db, string $table): bool {
    try {
        $st = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        return $st && $st->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}
function columnExists(PDO $db, string $table, string $column): bool {
    try {
        $st = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($column));
        return $st && $st->rowCount() > 0;
    } catch (Throwable $e) { return false; }
}
function normalizedProgram(array $row): string {
    $program = trim((string)($row['programa'] ?? ''));
    $sector = trim((string)($row['sector'] ?? ''));
    if ($program !== '') {
        $p = preg_replace('/[_\-]+HND$/i', '', $program);
        $p = str_replace('_', ' ', $p);
        return trim($p);
    }
    if ($sector !== '') {
        $first = explode('_', $sector)[0] ?? $sector;
        $map = ['D'=>'CRECER','E'=>'REDES','F'=>'TEJIENDO MI FUTURO','Z'=>'ADMINISTRACIÓN','X'=>'PATROCINIO','ML'=>'MONITOREO'];
        return $map[$first] ?? str_replace('_', ' ', $sector);
    }
    return 'SIN PROGRAMA';
}
function cleanSector(array $row): string {
    $sector = trim((string)($row['sector'] ?? ''));
    return $sector !== '' ? str_replace('_', ' ', $sector) : 'Sin sector';
}
function pct(float $done, float $planned, float $max = 150): float {
    if ($planned <= 0) return $done > 0 ? 100.0 : 0.0;
    return max(0, min($max, ($done / $planned) * 100));
}
function qualityScore(float $planned, float $done, float $onTime, float $inForm): float {
    $quantity = min(100, pct($done, $planned, 100));
    $quality = max(0, min(100, ($onTime + $inForm) / 2));
    if ($onTime <= 0 && $inForm <= 0) return $quantity;
    return round($quantity * ($quality / 100), 2);
}
function scoreBand(float $score): string {
    if ($score <= 25) return 'red';
    if ($score <= 50) return 'orange';
    if ($score <= 75) return 'softgreen';
    return 'green';
}
function fiscalPeriodLabel(array $months, array $labels): string {
    if (count($months) === 1) return $labels[$months[0]] ?? strtoupper($months[0]);
    $first = $labels[$months[0]] ?? strtoupper($months[0]);
    $last = $labels[$months[count($months)-1]] ?? strtoupper($months[count($months)-1]);
    return $first . ' – ' . $last;
}
function getMonthKey(): string {
    $map = ['01'=>'jan','02'=>'feb','03'=>'mar','04'=>'apr','05'=>'may','06'=>'jun','07'=>'jul','08'=>'aug','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dec'];
    return $map[date('m')] ?? 'jul';
}
function csvOut(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

$months = ['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];
$monthLabels = ['jul'=>'Jul','aug'=>'Ago','sep'=>'Sep','oct'=>'Oct','nov'=>'Nov','dec'=>'Dic','jan'=>'Ene','feb'=>'Feb','mar'=>'Mar','apr'=>'Abr','may'=>'May','jun'=>'Jun'];
$currentMonth = getMonthKey();
$currentMonthIndex = array_search($currentMonth, $months, true);
if ($currentMonthIndex === false) $currentMonthIndex = 0;

$errors = [];
$allPoa = [];
$allAssignments = [];
$allStages = [];
$allCenters = [];
$allTechnicians = [];
$allBases = [];

try { if (tableExists($db, 'ah_poa')) $allPoa = $db->query("SELECT * FROM ah_poa")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $errors[] = 'POA: ' . $e->getMessage(); }
try { if (tableExists($db, 'ah_poa_asignaciones')) $allAssignments = $db->query("SELECT * FROM ah_poa_asignaciones")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $errors[] = 'Asignaciones: ' . $e->getMessage(); }
try { if (tableExists($db, 'ah_poa_etapas')) $allStages = $db->query("SELECT * FROM ah_poa_etapas")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $errors[] = 'Etapas: ' . $e->getMessage(); }
try { if (tableExists($db, 'ah_centros')) $allCenters = $db->query("SELECT * FROM ah_centros")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $errors[] = 'Centros: ' . $e->getMessage(); }
try { if (tableExists($db, 'ah_tecnicos')) $allTechnicians = $db->query("SELECT * FROM ah_tecnicos")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $errors[] = 'Técnicos: ' . $e->getMessage(); }
try { if (tableExists($db, 'ah_bases_geograficas')) $allBases = $db->query("SELECT * FROM ah_bases_geograficas")->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $errors[] = 'Bases: ' . $e->getMessage(); }

$poaNames = [];
$activePoa = '';
foreach ($allPoa as $r) {
    $name = trim((string)($r['nombre_poa'] ?? ''));
    if ($name !== '') $poaNames[$name] = true;
    if ((int)($r['is_active'] ?? 0) === 1 && $name !== '') $activePoa = $name;
}
$poaNames = array_keys($poaNames);
rsort($poaNames, SORT_NATURAL | SORT_FLAG_CASE);
$selectedPoa = trim((string)($_GET['poa'] ?? ''));
if ($selectedPoa === '') $selectedPoa = $activePoa !== '' ? $activePoa : ($poaNames[0] ?? '');

$periodMode = (string)($_GET['periodo'] ?? 'ytd');
$selectedMonths = [];
if ($periodMode === 'actual') {
    $selectedMonths = [$currentMonth];
} elseif ($periodMode === 'fiscal') {
    $selectedMonths = $months;
} elseif ($periodMode === 'custom') {
    $from = (string)($_GET['desde'] ?? 'jul');
    $to = (string)($_GET['hasta'] ?? $currentMonth);
    $i1 = array_search($from, $months, true); $i2 = array_search($to, $months, true);
    if ($i1 === false) $i1 = 0; if ($i2 === false) $i2 = $currentMonthIndex;
    if ($i1 <= $i2) $selectedMonths = array_slice($months, $i1, $i2 - $i1 + 1);
    else $selectedMonths = array_merge(array_slice($months, $i1), array_slice($months, 0, $i2 + 1));
} else {
    $selectedMonths = array_slice($months, 0, $currentMonthIndex + 1);
    $periodMode = 'ytd';
}

$selectedProgram = trim((string)($_GET['programa'] ?? ''));
$selectedSector = trim((string)($_GET['sector'] ?? ''));
$selectedTech = trim((string)($_GET['tecnico'] ?? ''));
$selectedBase = trim((string)($_GET['base'] ?? ''));
$selectedCenterType = trim((string)($_GET['tipo_centro'] ?? ''));

$poaRows = [];
$programOptions = [];
$sectorOptions = [];
foreach ($allPoa as $row) {
    if ($selectedPoa !== '' && trim((string)($row['nombre_poa'] ?? '')) !== $selectedPoa) continue;
    $program = normalizedProgram($row);
    $sector = cleanSector($row);
    $programOptions[$program] = true;
    $sectorOptions[$sector] = true;
    if ($selectedProgram !== '' && $program !== $selectedProgram) continue;
    if ($selectedSector !== '' && $sector !== $selectedSector) continue;
    $poaRows[(int)$row['id']] = $row;
}
$programOptions = array_keys($programOptions); sort($programOptions, SORT_NATURAL | SORT_FLAG_CASE);
$sectorOptions = array_keys($sectorOptions); sort($sectorOptions, SORT_NATURAL | SORT_FLAG_CASE);
$poaIds = array_fill_keys(array_keys($poaRows), true);

$techOptions = [];
$baseOptions = [];
foreach ($allAssignments as $a) {
    if (!isset($poaIds[(int)($a['id_poa'] ?? 0)])) continue;
    $t = trim((string)($a['tecnico'] ?? '')); $b = trim((string)($a['base_asignada'] ?? ''));
    if ($t !== '') $techOptions[$t] = true;
    if ($b !== '') $baseOptions[$b] = true;
}
foreach ($allTechnicians as $t) {
    $name = trim((string)($t['nombre'] ?? ''));
    if ($name !== '') $techOptions[$name] = true;
}
foreach ($allBases as $b) {
    $name = trim((string)($b['nombre_base'] ?? ''));
    if ($name !== '') $baseOptions[$name] = true;
}
$techOptions = array_keys($techOptions); sort($techOptions, SORT_NATURAL | SORT_FLAG_CASE);
$baseOptions = array_keys($baseOptions); sort($baseOptions, SORT_NATURAL | SORT_FLAG_CASE);

$centerCatalog = [];
$centerTypeOptions = [];
foreach ($allCenters as $c) {
    $id = (string)($c['id'] ?? '');
    if ($id === '') continue;
    $centerCatalog[$id] = $c;
    $type = trim((string)($c['tipo'] ?? ''));
    if ($type !== '') $centerTypeOptions[$type] = true;
}
$centerTypeOptions = array_keys($centerTypeOptions); sort($centerTypeOptions, SORT_NATURAL | SORT_FLAG_CASE);

$monthly = [];
foreach ($months as $m) $monthly[$m] = ['tech_plan'=>0,'tech_done'=>0,'act_plan'=>0,'part_plan'=>0,'budget'=>0,'spent'=>0,'cent_plan'=>0,'cent_done'=>0];

$programStats = [];
$activityStats = [];
$totalBudget = $totalSpent = $totalPlanActivities = $totalPlanParticipants = 0;
foreach ($poaRows as $id => $row) {
    $program = normalizedProgram($row);
    if (!isset($programStats[$program])) $programStats[$program] = ['activities'=>0,'act_plan'=>0,'part_plan'=>0,'tech_plan'=>0,'tech_done'=>0,'budget'=>0,'spent'=>0,'scores'=>[]];
    $programStats[$program]['activities']++;
    foreach ($selectedMonths as $m) {
        $ap = num($row['op_act_'.$m] ?? 0); $pp = num($row['op_part_'.$m] ?? 0);
        $bp = num($row['pto_'.$m] ?? 0); $sp = num($row['eje_'.$m] ?? 0);
        $programStats[$program]['act_plan'] += $ap;
        $programStats[$program]['part_plan'] += $pp;
        $programStats[$program]['budget'] += $bp;
        $programStats[$program]['spent'] += $sp;
        $monthly[$m]['act_plan'] += $ap; $monthly[$m]['part_plan'] += $pp;
        $monthly[$m]['budget'] += $bp; $monthly[$m]['spent'] += $sp;
        $totalPlanActivities += $ap; $totalPlanParticipants += $pp; $totalBudget += $bp; $totalSpent += $sp;
    }
    $activityStats[$id] = [
        'id'=>$id,
        'program'=>$program,
        'sector'=>cleanSector($row),
        'code'=>trim((string)($row['marco_logico'] ?? $row['codigo_maestro'] ?? '')),
        'ext'=>trim((string)($row['ext'] ?? '')),
        'description'=>trim((string)($row['descripcion_actividad'] ?? $row['marco_logico'] ?? 'Sin descripción')),
        'stage_scores'=>['E-1'=>0,'E-2'=>0,'E-3'=>0,'E-4'=>0],
        'stage_present'=>[],
        'score'=>0,
        'plan'=>0,
        'done'=>0,
        'on_time'=>0,
        'in_form'=>0,
        'assigned'=>0
    ];
}

$techStats = [];
$assignmentRows = [];
foreach ($allAssignments as $a) {
    $idPoa = (int)($a['id_poa'] ?? 0);
    if (!isset($poaIds[$idPoa])) continue;
    $tech = trim((string)($a['tecnico'] ?? ''));
    $base = trim((string)($a['base_asignada'] ?? ''));
    if ($tech === '') continue;
    if ($selectedTech !== '' && $tech !== $selectedTech) continue;
    if ($selectedBase !== '' && $base !== $selectedBase) continue;
    if (!isset($techStats[$tech])) {
        $techStats[$tech] = ['name'=>$tech,'bases'=>[],'programs'=>[],'plan'=>0,'done'=>0,'on_time_sum'=>0,'in_form_sum'=>0,'quality_weight'=>0,'activities'=>[],'monthly'=>[],'score'=>0,'qty_pct'=>0,'on_time'=>0,'in_form'=>0,'alerts'=>0];
        foreach ($months as $m) $techStats[$tech]['monthly'][$m] = ['plan'=>0,'done'=>0,'on_time_sum'=>0,'in_form_sum'=>0,'quality_weight'=>0,'on_time'=>0,'in_form'=>0,'score'=>0];
    }
    if ($base !== '') $techStats[$tech]['bases'][$base] = true;
    $program = normalizedProgram($poaRows[$idPoa]);
    $techStats[$tech]['programs'][$program] = true;
    $techStats[$tech]['activities'][$idPoa] = true;
    $activityStats[$idPoa]['assigned']++;
    foreach ($months as $m) {
        $p = num($a['meta_'.$m] ?? 0); $d = num($a['logro_'.$m] ?? 0);
        $techStats[$tech]['monthly'][$m]['plan'] += $p;
        $techStats[$tech]['monthly'][$m]['done'] += $d;
        if (in_array($m, $selectedMonths, true)) {
            $techStats[$tech]['plan'] += $p; $techStats[$tech]['done'] += $d;
            $activityStats[$idPoa]['plan'] += $p; $activityStats[$idPoa]['done'] += $d;
            $programStats[$program]['tech_plan'] += $p; $programStats[$program]['tech_done'] += $d;
            $monthly[$m]['tech_plan'] += $p; $monthly[$m]['tech_done'] += $d;
        }
    }
    $assignmentRows[] = $a;
}

$stageGlobal = ['E-1'=>['sum'=>0,'weight'=>0,'count'=>0], 'E-2'=>['sum'=>0,'weight'=>0,'count'=>0], 'E-3'=>['sum'=>0,'weight'=>0,'count'=>0], 'E-4'=>['sum'=>0,'weight'=>0,'count'=>0]];
$centerStats = [];
$centerRowsByMonth = [];
foreach ($months as $m) $centerRowsByMonth[$m] = [];

foreach ($allStages as $stage) {
    $idPoa = (int)($stage['id_poa'] ?? 0);
    if (!isset($poaIds[$idPoa])) continue;
    $code = strtoupper(trim((string)($stage['codigo_etapa'] ?? '')));
    if ($code === '') $code = 'E-' . ((int)($stage['orden'] ?? 0));
    if (!isset($stageGlobal[$code])) continue;
    $involved = safeJson($stage['involucrados_json'] ?? '', []);
    $rowScores = [];
    foreach ($involved as $key => $row) {
        if (!is_array($row) || !empty($row['deleted'])) continue;
        $rowMonth = strtolower(trim((string)($row['mes'] ?? $currentMonth)));
        if (!isset($monthLabels[$rowMonth])) $rowMonth = $currentMonth;
        $person = trim((string)($row['persona'] ?? ''));
        $baseString = trim((string)($row['base'] ?? ''));
        $rowBases = array_values(array_filter(array_map('trim', preg_split('/[|,;]/', $baseString))));
        $prog = num($row['a_lograr'] ?? 0); $done = num($row['cumplido'] ?? 0);
        $at = max(0, min(100, num($row['a_tiempo'] ?? 0)));
        $ef = max(0, min(100, num($row['en_forma'] ?? 0)));
        $score = qualityScore($prog, $done, $at, $ef);
        $weight = max($prog, 1);
        if ($selectedTech !== '' && $person !== '' && $person !== $selectedTech) continue;
        if ($person !== '' && isset($techStats[$person])) {
            $techStats[$person]['monthly'][$rowMonth]['on_time_sum'] += $at * $weight;
            $techStats[$person]['monthly'][$rowMonth]['in_form_sum'] += $ef * $weight;
            $techStats[$person]['monthly'][$rowMonth]['quality_weight'] += $weight;
        }
        if (in_array($rowMonth, $selectedMonths, true) || trim((string)($row['mes'] ?? '')) === '') {
            $rowScores[] = ['score'=>$score,'weight'=>$weight];
            if ($person !== '' && isset($techStats[$person])) {
                $techStats[$person]['on_time_sum'] += $at * $weight;
                $techStats[$person]['in_form_sum'] += $ef * $weight;
                $techStats[$person]['quality_weight'] += $weight;
            }
        }
        if ($code === 'E-3') {
            $centers = isset($row['centros']) && is_array($row['centros']) ? $row['centros'] : [];
            foreach ($centers as $centerId => $center) {
                if (!is_array($center)) continue;
                $cid = (string)($center['id'] ?? $centerId);
                $catalog = $centerCatalog[$cid] ?? [];
                $type = trim((string)($center['tipo'] ?? $catalog['tipo'] ?? 'Sin tipo'));
                if ($selectedCenterType !== '' && $type !== $selectedCenterType) continue;
                $community = trim((string)($center['comunidad_base'] ?? $catalog['comunidad_base'] ?? ($rowBases[0] ?? '')));
                if ($selectedBase !== '' && $community !== $selectedBase) continue;
                $cp = num($center['a_lograr'] ?? $center['matricula'] ?? $catalog['pob_total'] ?? 0);
                $cd = num($center['cumplido'] ?? 0);
                $cat = max(0, min(100, num($center['a_tiempo'] ?? 0)));
                $cef = max(0, min(100, num($center['en_forma'] ?? 0)));
                if (!isset($centerStats[$cid])) {
                    $centerStats[$cid] = [
                        'id'=>$cid,'name'=>trim((string)($center['nombre'] ?? $catalog['nombre'] ?? ('Centro '.$cid))),
                        'type'=>$type,'base'=>$community,'caserio'=>trim((string)($center['caserio'] ?? $catalog['caserio'] ?? '')),
                        'matricula'=>num($catalog['pob_total'] ?? $cp),'female'=>num($catalog['pob_fem'] ?? 0),'male'=>num($catalog['pob_masc'] ?? 0),
                        'age_0_5'=>num($catalog['pob_0_5'] ?? $center['pob_0_5'] ?? 0),'age_6_17'=>num($catalog['pob_6_17'] ?? $center['pob_6_17'] ?? 0),'age_18_24'=>num($catalog['pob_18_24'] ?? $center['pob_18_24'] ?? 0),
                        'plan'=>0,'done'=>0,'on_time_sum'=>0,'in_form_sum'=>0,'weight'=>0,'score'=>0,'activities'=>[],'technicians'=>[],'monthly'=>[]
                    ];
                    foreach ($months as $m) $centerStats[$cid]['monthly'][$m] = ['plan'=>0,'done'=>0,'on_time'=>0,'in_form'=>0,'weight'=>0,'score'=>0];
                }
                $centerStats[$cid]['activities'][$idPoa] = true;
                if ($person !== '') $centerStats[$cid]['technicians'][$person] = true;
                $w = max($cp, 1);
                $centerStats[$cid]['monthly'][$rowMonth]['plan'] += $cp;
                $centerStats[$cid]['monthly'][$rowMonth]['done'] += $cd;
                $centerStats[$cid]['monthly'][$rowMonth]['on_time'] += $cat * $w;
                $centerStats[$cid]['monthly'][$rowMonth]['in_form'] += $cef * $w;
                $centerStats[$cid]['monthly'][$rowMonth]['weight'] += $w;
                if (in_array($rowMonth, $selectedMonths, true) || trim((string)($row['mes'] ?? '')) === '') {
                    $centerStats[$cid]['plan'] += $cp; $centerStats[$cid]['done'] += $cd;
                    $centerStats[$cid]['on_time_sum'] += $cat * $w; $centerStats[$cid]['in_form_sum'] += $cef * $w; $centerStats[$cid]['weight'] += $w;
                    $monthly[$rowMonth]['cent_plan'] += $cp; $monthly[$rowMonth]['cent_done'] += $cd;
                }
            }
        }
    }
    if ($rowScores) {
        $sum = $weightSum = 0;
        foreach ($rowScores as $s) { $sum += $s['score'] * $s['weight']; $weightSum += $s['weight']; }
        $stageScore = $weightSum > 0 ? $sum / $weightSum : 0;
        $activityStats[$idPoa]['stage_scores'][$code] = round($stageScore, 2);
        $activityStats[$idPoa]['stage_present'][$code] = true;
        $stageGlobal[$code]['sum'] += $stageScore;
        $stageGlobal[$code]['count']++;
    }
}

foreach ($activityStats as $id => &$a) {
    $a['score'] = round(array_sum($a['stage_scores']) / 4, 2);
    $a['qty_pct'] = round(pct($a['done'], $a['plan'], 150), 2);
    $programStats[$a['program']]['scores'][] = $a['score'];
}
unset($a);
foreach ($techStats as &$t) {
    $t['qty_pct'] = round(pct($t['done'], $t['plan'], 150), 2);
    if ($t['quality_weight'] > 0) {
        $t['on_time'] = round($t['on_time_sum'] / $t['quality_weight'], 2);
        $t['in_form'] = round($t['in_form_sum'] / $t['quality_weight'], 2);
        $t['score'] = qualityScore($t['plan'], $t['done'], $t['on_time'], $t['in_form']);
    } else {
        $t['score'] = min(100, $t['qty_pct']);
    }
    foreach ($months as $m) {
        $mw = $t['monthly'][$m]['quality_weight'];
        $mot = $mw > 0 ? $t['monthly'][$m]['on_time_sum'] / $mw : 0;
        $mif = $mw > 0 ? $t['monthly'][$m]['in_form_sum'] / $mw : 0;
        $t['monthly'][$m]['on_time'] = round($mot, 2);
        $t['monthly'][$m]['in_form'] = round($mif, 2);
        $t['monthly'][$m]['score'] = $mw > 0
            ? qualityScore($t['monthly'][$m]['plan'], $t['monthly'][$m]['done'], $mot, $mif)
            : round(min(100, pct($t['monthly'][$m]['done'], $t['monthly'][$m]['plan'], 100)), 2);
    }
    if ($t['plan'] > 0 && $t['score'] < 50) $t['alerts']++;
    $t['bases'] = array_keys($t['bases']); $t['programs'] = array_keys($t['programs']); $t['activities'] = array_keys($t['activities']);
}
unset($t);
foreach ($centerStats as &$c) {
    $c['on_time'] = $c['weight'] > 0 ? round($c['on_time_sum'] / $c['weight'], 2) : 0;
    $c['in_form'] = $c['weight'] > 0 ? round($c['in_form_sum'] / $c['weight'], 2) : 0;
    $c['qty_pct'] = round(pct($c['done'], $c['plan'], 150), 2);
    $c['score'] = qualityScore($c['plan'], $c['done'], $c['on_time'], $c['in_form']);
    foreach ($months as $m) {
        $mm = &$c['monthly'][$m];
        $ot = $mm['weight'] > 0 ? $mm['on_time'] / $mm['weight'] : 0;
        $if = $mm['weight'] > 0 ? $mm['in_form'] / $mm['weight'] : 0;
        $mm['score'] = qualityScore($mm['plan'], $mm['done'], $ot, $if);
        $mm['on_time'] = round($ot, 2); $mm['in_form'] = round($if, 2);
        unset($mm);
    }
    $c['activities'] = array_keys($c['activities']); $c['technicians'] = array_keys($c['technicians']);
}
unset($c);
foreach ($programStats as &$p) $p['score'] = $p['scores'] ? round(array_sum($p['scores']) / count($p['scores']), 2) : round(min(100, pct($p['tech_done'], $p['tech_plan'], 100)), 2);
unset($p);

// Historial mensual: conserva una fotografía por entidad y mes para futuras líneas de tiempo.
// Solo captura el corte cuando el panel no está filtrado, para no reemplazar el histórico con subconjuntos.
$canSnapshot = $selectedProgram === '' && $selectedSector === '' && $selectedTech === '' && $selectedBase === '' && $selectedCenterType === '';
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_panel_historial (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(30) NOT NULL,
        entity_hash CHAR(40) NOT NULL,
        entity_key VARCHAR(190) NOT NULL,
        entity_label VARCHAR(190) NOT NULL,
        periodo CHAR(7) NOT NULL,
        poa_hash CHAR(40) NOT NULL,
        poa_name VARCHAR(190) NOT NULL,
        programado DECIMAL(15,2) DEFAULT 0,
        cumplido DECIMAL(15,2) DEFAULT 0,
        a_tiempo DECIMAL(7,2) DEFAULT 0,
        en_forma DECIMAL(7,2) DEFAULT 0,
        desempeno DECIMAL(7,2) DEFAULT 0,
        metadata_json LONGTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_panel_hist (entity_type, entity_hash, periodo, poa_hash),
        INDEX idx_panel_periodo (periodo),
        INDEX idx_panel_poa (poa_hash)
    )");
    if ($canSnapshot && $selectedPoa !== '') {
        $periodoSnapshot = date('Y-m');
        $up = $db->prepare("INSERT INTO ah_panel_historial
            (entity_type,entity_hash,entity_key,entity_label,periodo,poa_hash,poa_name,programado,cumplido,a_tiempo,en_forma,desempeno,metadata_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE entity_label=VALUES(entity_label),programado=VALUES(programado),cumplido=VALUES(cumplido),a_tiempo=VALUES(a_tiempo),en_forma=VALUES(en_forma),desempeno=VALUES(desempeno),metadata_json=VALUES(metadata_json)");
        $poaHash = sha1($selectedPoa);
        foreach ($techStats as $t) {
            $key = $t['name'];
            $mm = $t['monthly'][$currentMonth];
            $up->execute(['tecnico',sha1($key),$key,$key,$periodoSnapshot,$poaHash,$selectedPoa,$mm['plan'],$mm['done'],$mm['on_time'],$mm['in_form'],$mm['score'],json_encode(['bases'=>$t['bases'],'programas'=>$t['programs']],JSON_UNESCAPED_UNICODE)]);
        }
        foreach ($centerStats as $c) {
            $key = (string)$c['id'];
            $mm = $c['monthly'][$currentMonth];
            $up->execute(['centro',sha1($key),$key,$c['name'],$periodoSnapshot,$poaHash,$selectedPoa,$mm['plan'],$mm['done'],$mm['on_time'],$mm['in_form'],$mm['score'],json_encode(['tipo'=>$c['type'],'base'=>$c['base'],'caserio'=>$c['caserio']],JSON_UNESCAPED_UNICODE)]);
        }
    }
} catch (Throwable $e) { $errors[] = 'Historial: ' . $e->getMessage(); }

$history = [];
try {
    if (tableExists($db, 'ah_panel_historial')) {
        $st = $db->prepare("SELECT * FROM ah_panel_historial WHERE poa_hash=?");
        $st->execute([sha1($selectedPoa)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $history[$r['entity_type']][$r['entity_key']][] = $r;
    }
} catch (Throwable $e) { }

usort($techStats, function($a,$b){ return $b['score'] <=> $a['score']; });
usort($centerStats, function($a,$b){ return $b['score'] <=> $a['score']; });
usort($activityStats, function($a,$b){ return $a['score'] <=> $b['score']; });

$totalTechPlan = array_sum(array_column($techStats, 'plan'));
$totalTechDone = array_sum(array_column($techStats, 'done'));
$totalCentersMonitored = count($centerStats);
$centersReached = 0; foreach ($centerStats as $c) if ($c['done'] > 0) $centersReached++;
$avgTechScore = $techStats ? array_sum(array_column($techStats, 'score')) / count($techStats) : 0;
$avgCenterScore = $centerStats ? array_sum(array_column($centerStats, 'score')) / count($centerStats) : 0;
$avgActivityScore = $activityStats ? array_sum(array_column($activityStats, 'score')) / count($activityStats) : 0;
$avgOnTime = $techStats ? array_sum(array_column($techStats, 'on_time')) / count($techStats) : 0;
$avgInForm = $techStats ? array_sum(array_column($techStats, 'in_form')) / count($techStats) : 0;
$financialPct = pct($totalSpent, $totalBudget, 150);
$stagePresentCount = 0; foreach ($activityStats as $a) $stagePresentCount += count($a['stage_present']);
$dataCompleteness = count($activityStats) > 0 ? ($stagePresentCount / (count($activityStats) * 4)) * 100 : 0;

$alerts = [];
foreach ($activityStats as $a) if ($a['score'] < 50) $alerts[] = ['type'=>'Actividad','name'=>$a['description'],'context'=>$a['program'],'score'=>$a['score']];
foreach ($techStats as $t) if ($t['plan'] > 0 && $t['score'] < 50) $alerts[] = ['type'=>'Técnico','name'=>$t['name'],'context'=>implode(', ', $t['bases']),'score'=>$t['score']];
foreach ($centerStats as $c) if ($c['plan'] > 0 && $c['score'] < 50) $alerts[] = ['type'=>'Centro','name'=>$c['name'],'context'=>$c['base'],'score'=>$c['score']];
usort($alerts, function($a,$b){ return $a['score'] <=> $b['score']; });
$alerts = array_slice($alerts, 0, 20);

if (isset($_GET['export'])) {
    $what = (string)$_GET['export'];
    if ($what === 'tecnicos') {
        $rows = [];
        foreach ($techStats as $t) $rows[] = [$t['name'],implode(' | ',$t['bases']),implode(' | ',$t['programs']),$t['plan'],$t['done'],$t['qty_pct'],$t['on_time'],$t['in_form'],$t['score'],count($t['activities'])];
        csvOut('desempeno_tecnicos.csv',['Técnico','Bases','Programas','Programado','Cumplido','Cumplimiento %','A tiempo %','En forma %','Desempeño %','Actividades'],$rows);
    }
    if ($what === 'centros') {
        $rows = [];
        foreach ($centerStats as $c) $rows[] = [$c['name'],$c['type'],$c['base'],$c['caserio'],$c['matricula'],$c['plan'],$c['done'],$c['qty_pct'],$c['on_time'],$c['in_form'],$c['score'],implode(' | ',$c['technicians'])];
        csvOut('desempeno_centros.csv',['Centro','Tipo','Base','Caserío','Matrícula','Programado','Cumplido','Cumplimiento %','A tiempo %','En forma %','Desempeño %','Técnicos'],$rows);
    }
    if ($what === 'actividades') {
        $rows = [];
        foreach ($activityStats as $a) $rows[] = [$a['code'],$a['ext'],$a['description'],$a['program'],$a['sector'],$a['plan'],$a['done'],$a['score'],$a['stage_scores']['E-1'],$a['stage_scores']['E-2'],$a['stage_scores']['E-3'],$a['stage_scores']['E-4']];
        csvOut('desempeno_actividades.csv',['Código','Ext','Actividad','Programa','Sector','Programado','Cumplido','Desempeño','E-1','E-2','E-3','E-4'],$rows);
    }
}

$stageChart = [];
foreach ($stageGlobal as $code => $s) $stageChart[$code] = $s['count'] > 0 ? round($s['sum'] / $s['count'], 2) : 0;
$programChartLabels = array_keys($programStats);
$programChartScores = array_map(function($p){ return $p['score']; }, array_values($programStats));
$centerTypeSummary = [];
foreach ($centerStats as $c) {
    $type = $c['type'];
    if (!isset($centerTypeSummary[$type])) $centerTypeSummary[$type] = ['count'=>0,'plan'=>0,'done'=>0,'scores'=>[]];
    $centerTypeSummary[$type]['count']++;
    $centerTypeSummary[$type]['plan'] += $c['plan']; $centerTypeSummary[$type]['done'] += $c['done']; $centerTypeSummary[$type]['scores'][] = $c['score'];
}
foreach ($centerTypeSummary as &$x) $x['score'] = $x['scores'] ? round(array_sum($x['scores']) / count($x['scores']), 2) : 0;
unset($x);

$jsTech = [];
foreach ($techStats as $t) {
    $hist = $history['tecnico'][$t['name']] ?? [];
    usort($hist, function($a,$b){ return strcmp($a['periodo'],$b['periodo']); });
    $jsTech[$t['name']] = array_merge($t, ['history'=>$hist]);
}
$jsCenters = [];
foreach ($centerStats as $c) {
    $hist = $history['centro'][(string)$c['id']] ?? [];
    usort($hist, function($a,$b){ return strcmp($a['periodo'],$b['periodo']); });
    $jsCenters[(string)$c['id']] = array_merge($c, ['history'=>$hist]);
}

$periodLabel = fiscalPeriodLabel($selectedMonths, $monthLabels);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel Gerencial Avanzado | Acción Honduras</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/chart.js@4.4.7/dist/chart.umd.js"></script>
<style>
:root{--primary:#34859b;--primary2:#46b094;--ink:#10233d;--muted:#64748b;--bg:#f4f7fb;--card:#fff;--border:#dfe7f1;--red:#dc2626;--orange:#ea580c;--green:#15803d;--softgreen:#65a30d;--blue:#0284c7;--shadow:0 12px 35px rgba(15,35,61,.08)}
*{box-sizing:border-box}body{margin:0;display:flex;min-height:100vh;background:var(--bg);font-family:Inter,sans-serif;color:var(--ink)}.main{flex:1;min-width:0;padding:26px 34px 60px}.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:20px}.title h1{margin:0;font-size:1.85rem;font-weight:900}.title p{margin:6px 0 0;color:var(--muted);font-size:.9rem}.actions{display:flex;gap:9px;flex-wrap:wrap}.btn{border:1px solid var(--border);background:#fff;color:var(--ink);padding:10px 14px;border-radius:10px;font:inherit;font-size:.83rem;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px}.btn:hover{border-color:#9fcbd6;color:#09677c}.btn.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.filter-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:var(--shadow);margin-bottom:20px}.filters{display:grid;grid-template-columns:1.2fr repeat(6,minmax(130px,1fr)) auto;gap:10px;align-items:end}.field label{display:block;font-size:.69rem;font-weight:900;color:#64748b;text-transform:uppercase;margin-bottom:5px}.control{width:100%;height:40px;border:1px solid #cfd9e6;border-radius:9px;background:#fff;padding:0 10px;font:inherit;font-size:.8rem;color:var(--ink)}.control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(52,133,155,.12)}
.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:13px;margin-bottom:20px}.kpi{background:#fff;border:1px solid var(--border);border-radius:15px;padding:16px;box-shadow:var(--shadow);position:relative;overflow:hidden}.kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--primary)}.kpi.green:before{background:var(--green)}.kpi.orange:before{background:var(--orange)}.kpi.red:before{background:var(--red)}.kpi.blue:before{background:var(--blue)}.kpi .label{font-size:.7rem;text-transform:uppercase;color:var(--muted);font-weight:900}.kpi .value{font-size:1.65rem;font-weight:900;margin-top:6px;line-height:1}.kpi .sub{font-size:.72rem;color:var(--muted);margin-top:8px}.kpi .icon{position:absolute;right:13px;top:13px;font-size:1.15rem;color:#b7c6d7}
.tabs{display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--border);overflow:auto}.tab{border:0;background:transparent;padding:11px 15px;font:inherit;font-size:.82rem;font-weight:900;color:#64748b;border-bottom:3px solid transparent;cursor:pointer;white-space:nowrap}.tab.active{color:var(--primary);border-bottom-color:var(--primary)}.tab-panel{display:none}.tab-panel.active{display:block}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}.card{background:#fff;border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:17px;min-width:0}.card h3{margin:0;font-size:.93rem}.card .hint{color:var(--muted);font-size:.72rem;margin-top:4px}.span-3{grid-column:span 3}.span-4{grid-column:span 4}.span-5{grid-column:span 5}.span-6{grid-column:span 6}.span-7{grid-column:span 7}.span-8{grid-column:span 8}.span-9{grid-column:span 9}.span-12{grid-column:span 12}.chart-box{height:310px;margin-top:10px}.chart-box.small{height:245px}.chart-box.tall{height:380px}
.table-wrap{overflow:auto;max-height:570px;border:1px solid #e5ebf3;border-radius:12px;margin-top:12px}.table{width:100%;border-collapse:separate;border-spacing:0;font-size:.78rem}.table th{position:sticky;top:0;z-index:2;background:#f2f6fa;color:#4b5f77;text-transform:uppercase;font-size:.66rem;letter-spacing:.3px;padding:10px;text-align:left;border-bottom:1px solid #dce5ef}.table td{padding:10px;border-bottom:1px solid #edf1f6;vertical-align:middle}.table tbody tr:hover td{background:#f8fbfd}.click-row{cursor:pointer}.name{font-weight:850}.mini{font-size:.68rem;color:var(--muted);margin-top:3px}.chips{display:flex;gap:4px;flex-wrap:wrap}.chip{display:inline-flex;padding:3px 7px;border-radius:999px;background:#eff8fb;color:#09677c;border:1px solid #c8e7ee;font-size:.64rem;font-weight:800}.score{display:inline-flex;min-width:58px;justify-content:center;padding:5px 8px;border-radius:999px;font-weight:900;font-size:.72rem}.score.red{background:#fee2e2;color:#991b1b}.score.orange{background:#ffedd5;color:#9a3412}.score.softgreen{background:#ecfccb;color:#3f6212}.score.green{background:#dcfce7;color:#166534}.bar{height:7px;border-radius:999px;background:#e9eff6;overflow:hidden;min-width:90px}.bar>span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--primary),var(--primary2))}.metric-line{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:10px 0}.metric-line strong{font-size:.78rem}.metric-line span{font-size:.72rem;color:var(--muted)}
.alert-list{display:flex;flex-direction:column;gap:8px;margin-top:12px;max-height:310px;overflow:auto}.alert-item{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;border:1px solid #fee2e2;background:#fff7f7;border-radius:11px;padding:9px}.alert-icon{width:30px;height:30px;border-radius:9px;background:#fee2e2;color:#b91c1c;display:flex;align-items:center;justify-content:center}.alert-name{font-size:.75rem;font-weight:850}.alert-context{font-size:.66rem;color:var(--muted);margin-top:2px}.empty{padding:35px;text-align:center;color:var(--muted)}
.heatmap{overflow:auto;margin-top:12px}.heatmap table{border-collapse:separate;border-spacing:4px;width:100%;font-size:.69rem}.heatmap th{font-size:.63rem;color:var(--muted);text-align:center;white-space:nowrap}.heatmap td{padding:8px;text-align:center;border-radius:7px;font-weight:850;min-width:48px}.hm0{background:#f1f5f9;color:#94a3b8}.hm1{background:#fee2e2;color:#991b1b}.hm2{background:#ffedd5;color:#9a3412}.hm3{background:#ecfccb;color:#3f6212}.hm4{background:#dcfce7;color:#166534}
.summary-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px}.summary-box{border:1px solid #e3eaf2;background:#f8fafc;border-radius:11px;padding:11px}.summary-box span{display:block;font-size:.65rem;color:var(--muted);font-weight:800;text-transform:uppercase}.summary-box strong{display:block;font-size:1.05rem;margin-top:4px}.modal{position:fixed;inset:0;background:rgba(15,35,61,.62);display:none;align-items:center;justify-content:center;padding:22px;z-index:9999;backdrop-filter:blur(4px)}.modal.open{display:flex}.modal-box{width:min(1120px,96vw);max-height:92vh;overflow:hidden;background:#fff;border-radius:18px;box-shadow:0 30px 70px rgba(0,0,0,.25);display:flex;flex-direction:column}.modal-head{padding:17px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start;gap:15px}.modal-head h2{margin:0;font-size:1.12rem}.modal-head p{margin:4px 0 0;font-size:.74rem;color:var(--muted)}.modal-close{border:0;background:#f1f5f9;width:38px;height:38px;border-radius:10px;cursor:pointer;color:#475569}.modal-body{padding:18px 20px 25px;overflow:auto}.modal-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}.detail-card{border:1px solid var(--border);background:#f8fafc;border-radius:12px;padding:12px}.detail-card .label{font-size:.65rem;color:var(--muted);font-weight:900;text-transform:uppercase}.detail-card .value{font-size:1.15rem;font-weight:900;margin-top:4px}
.error-box{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:12px;padding:12px 15px;margin-bottom:15px;font-size:.78rem}@media(max-width:1450px){.kpis{grid-template-columns:repeat(3,1fr)}.filters{grid-template-columns:repeat(4,1fr)}}@media(max-width:1000px){.main{padding:20px}.kpis{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:repeat(2,1fr)}.span-3,.span-4,.span-5,.span-6,.span-7,.span-8,.span-9{grid-column:span 12}}@media(max-width:640px){.kpis,.summary-strip{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.topbar{flex-direction:column}}@media print{body{background:white}.actions,.filter-card,.tabs,aside{display:none!important}.main{padding:0}.card,.kpi{box-shadow:none}.tab-panel{display:block!important;page-break-inside:avoid}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main">
    <div class="topbar">
        <div class="title">
            <h1><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> Panel Gerencial Programático</h1>
            <p>Monitoreo integral de ejecución, desempeño técnico, calidad, centros, programas y presupuesto · <?=h($selectedPoa ?: 'Sin POA')?> · <?=h($periodLabel)?></p>
        </div>
        <div class="actions">
            <button class="btn" onclick="location.reload()"><i class="fa-solid fa-rotate"></i> Actualizar</button>
            <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir</button>
            <a class="btn" href="?<?=h(http_build_query(array_merge($_GET,['export'=>'tecnicos'])))?>"><i class="fa-solid fa-file-csv"></i> Técnicos</a>
            <a class="btn" href="?<?=h(http_build_query(array_merge($_GET,['export'=>'centros'])))?>"><i class="fa-solid fa-file-csv"></i> Centros</a>
            <a class="btn primary" href="monitoreo.php"><i class="fa-solid fa-list-check"></i> Ir a Monitoreo</a>
        </div>
    </div>

    <?php if ($errors): ?><div class="error-box"><b>El panel cargó con observaciones:</b> <?=h(implode(' · ', $errors))?></div><?php endif; ?>

    <form class="filter-card" method="get">
        <div class="filters">
            <div class="field"><label>POA</label><select class="control" name="poa"><?php foreach($poaNames as $v):?><option value="<?=h($v)?>" <?=$v===$selectedPoa?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div>
            <div class="field"><label>Período</label><select class="control" name="periodo" onchange="toggleCustom(this.value)"><option value="actual" <?=$periodMode==='actual'?'selected':''?>>Mes actual</option><option value="ytd" <?=$periodMode==='ytd'?'selected':''?>>Acumulado fiscal</option><option value="fiscal" <?=$periodMode==='fiscal'?'selected':''?>>Todo el año fiscal</option><option value="custom" <?=$periodMode==='custom'?'selected':''?>>Personalizado</option></select></div>
            <div class="field custom-period"><label>Desde</label><select class="control" name="desde"><?php foreach($months as $m):?><option value="<?=$m?>" <?=(($_GET['desde']??'jul')===$m)?'selected':''?>><?=$monthLabels[$m]?></option><?php endforeach;?></select></div>
            <div class="field custom-period"><label>Hasta</label><select class="control" name="hasta"><?php foreach($months as $m):?><option value="<?=$m?>" <?=(($_GET['hasta']??$currentMonth)===$m)?'selected':''?>><?=$monthLabels[$m]?></option><?php endforeach;?></select></div>
            <div class="field"><label>Programa</label><select class="control" name="programa"><option value="">Todos</option><?php foreach($programOptions as $v):?><option value="<?=h($v)?>" <?=$v===$selectedProgram?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div>
            <div class="field"><label>Sector</label><select class="control" name="sector"><option value="">Todos</option><?php foreach($sectorOptions as $v):?><option value="<?=h($v)?>" <?=$v===$selectedSector?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div>
            <div class="field"><label>Técnico</label><select class="control" name="tecnico"><option value="">Todos</option><?php foreach($techOptions as $v):?><option value="<?=h($v)?>" <?=$v===$selectedTech?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div>
            <div class="field"><label>Base</label><select class="control" name="base"><option value="">Todas</option><?php foreach($baseOptions as $v):?><option value="<?=h($v)?>" <?=$v===$selectedBase?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div>
            <div class="field"><label>Tipo de centro</label><select class="control" name="tipo_centro"><option value="">Todos</option><?php foreach($centerTypeOptions as $v):?><option value="<?=h($v)?>" <?=$v===$selectedCenterType?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></div>
            <button class="btn primary" type="submit"><i class="fa-solid fa-filter"></i> Aplicar</button>
        </div>
    </form>

    <section class="kpis">
        <div class="kpi blue"><i class="icon fa-solid fa-bullseye"></i><div class="label">Programado</div><div class="value"><?=number_format($totalTechPlan,0)?></div><div class="sub">Meta distribuida al equipo</div></div>
        <div class="kpi green"><i class="icon fa-solid fa-circle-check"></i><div class="label">Cumplido</div><div class="value"><?=number_format($totalTechDone,0)?></div><div class="sub"><?=number_format(pct($totalTechDone,$totalTechPlan,150),1)?>% de cumplimiento</div></div>
        <div class="kpi <?=scoreBand($avgActivityScore)?>"><i class="icon fa-solid fa-list-check"></i><div class="label">Avance programático</div><div class="value"><?=number_format($avgActivityScore,1)?>%</div><div class="sub"><?=number_format(count($activityStats))?> actividades analizadas</div></div>
        <div class="kpi <?=scoreBand($avgTechScore)?>"><i class="icon fa-solid fa-people-group"></i><div class="label">Desempeño técnico</div><div class="value"><?=number_format($avgTechScore,1)?>%</div><div class="sub"><?=number_format(count($techStats))?> técnicos con asignación</div></div>
        <div class="kpi <?=scoreBand($avgCenterScore)?>"><i class="icon fa-solid fa-school"></i><div class="label">Centros alcanzados</div><div class="value"><?=number_format($centersReached)?> / <?=number_format($totalCentersMonitored)?></div><div class="sub">Centros con cumplimiento registrado</div></div>
        <div class="kpi orange"><i class="icon fa-solid fa-coins"></i><div class="label">Ejecución financiera</div><div class="value"><?=number_format($financialPct,1)?>%</div><div class="sub">L <?=number_format($totalSpent,2)?> de L <?=number_format($totalBudget,2)?></div></div>
    </section>

    <div class="tabs">
        <button class="tab active" data-tab="resumen"><i class="fa-solid fa-gauge-high"></i> Resumen ejecutivo</button>
        <button class="tab" data-tab="tecnicos"><i class="fa-solid fa-users-gear"></i> Técnicos</button>
        <button class="tab" data-tab="centros"><i class="fa-solid fa-building-circle-check"></i> Centros</button>
        <button class="tab" data-tab="programas"><i class="fa-solid fa-layer-group"></i> Programas y actividades</button>
        <button class="tab" data-tab="calidad"><i class="fa-solid fa-shield-heart"></i> Calidad y finanzas</button>
    </div>

    <section id="tab-resumen" class="tab-panel active">
        <div class="grid">
            <div class="card span-8"><h3>Evolución mensual del equipo</h3><div class="hint">Programado y cumplido por mes fiscal</div><div class="chart-box"><canvas id="chartMonthly"></canvas></div></div>
            <div class="card span-4"><h3>Avance por etapa</h3><div class="hint">Promedio de las cuatro fases del ciclo programático</div><div class="chart-box"><canvas id="chartStages"></canvas></div></div>
            <div class="card span-4"><h3>Desempeño por programa</h3><div class="hint">Promedio calculado desde las actividades</div><div class="chart-box small"><canvas id="chartPrograms"></canvas></div></div>
            <div class="card span-4"><h3>Calidad de ejecución</h3><div class="summary-strip" style="grid-template-columns:1fr 1fr"><div class="summary-box"><span>A tiempo</span><strong><?=number_format($avgOnTime,1)?>%</strong></div><div class="summary-box"><span>En forma</span><strong><?=number_format($avgInForm,1)?>%</strong></div><div class="summary-box"><span>Integridad de datos</span><strong><?=number_format($dataCompleteness,1)?>%</strong></div><div class="summary-box"><span>Alertas críticas</span><strong><?=count($alerts)?></strong></div></div><div class="chart-box small"><canvas id="chartQuality"></canvas></div></div>
            <div class="card span-4"><h3>Alertas de atención</h3><div class="hint">Entidades con desempeño menor de 50%</div><div class="alert-list"><?php if(!$alerts):?><div class="empty">No hay alertas críticas.</div><?php else: foreach($alerts as $a):?><div class="alert-item"><div class="alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="alert-name"><?=h($a['name'])?></div><div class="alert-context"><?=h($a['type'].' · '.$a['context'])?></div></div><span class="score <?=scoreBand($a['score'])?>"><?=number_format($a['score'],1)?>%</span></div><?php endforeach; endif;?></div></div>
            <div class="card span-12"><h3>Matriz mensual de desempeño técnico</h3><div class="hint">Permite identificar meses sin programación, rezagos y sobrecumplimientos</div><div class="heatmap"><table><thead><tr><th style="text-align:left">Técnico</th><?php foreach($months as $m):?><th><?=$monthLabels[$m]?></th><?php endforeach;?><th>Prom.</th></tr></thead><tbody><?php foreach($techStats as $t):?><tr><td style="text-align:left;font-weight:800"><?=h($t['name'])?></td><?php foreach($months as $m):$s=$t['monthly'][$m]['score'];$cl=$s<=0?'hm0':($s<=25?'hm1':($s<=50?'hm2':($s<=75?'hm3':'hm4')));?><td class="<?=$cl?>"><?=number_format($s,0)?>%</td><?php endforeach;?><td class="<?=($t['score']<=25?'hm1':($t['score']<=50?'hm2':($t['score']<=75?'hm3':'hm4')))?>"><?=number_format($t['score'],0)?>%</td></tr><?php endforeach;?></tbody></table></div></div>
        </div>
    </section>

    <section id="tab-tecnicos" class="tab-panel">
        <div class="grid">
            <div class="card span-8"><h3>Ranking de desempeño técnico</h3><div class="hint">Haz clic en un técnico para ver su línea de tiempo, bases y carga de trabajo</div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Técnico</th><th>Bases / programas</th><th>Prog.</th><th>Cumpl.</th><th>Cumplimiento</th><th>A tiempo</th><th>En forma</th><th>Desempeño</th></tr></thead><tbody><?php $rank=1;foreach($techStats as $t):?><tr class="click-row" onclick='openTech(<?=json_encode($t['name'],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><td><?=$rank++?></td><td><div class="name"><?=h($t['name'])?></div><div class="mini"><?=count($t['activities'])?> actividades</div></td><td><div class="chips"><?php foreach(array_slice($t['bases'],0,4) as $b):?><span class="chip"><?=h($b)?></span><?php endforeach;?></div><div class="mini"><?=h(implode(', ',$t['programs']))?></div></td><td><?=number_format($t['plan'],0)?></td><td><?=number_format($t['done'],0)?></td><td><div class="bar"><span style="width:<?=min(100,$t['qty_pct'])?>%"></span></div><div class="mini"><?=number_format($t['qty_pct'],1)?>%</div></td><td><?=number_format($t['on_time'],1)?>%</td><td><?=number_format($t['in_form'],1)?>%</td><td><span class="score <?=scoreBand($t['score'])?>"><?=number_format($t['score'],1)?>%</span></td></tr><?php endforeach;?></tbody></table></div></div>
            <div class="card span-4"><h3>Top 10 técnicos</h3><div class="hint">Comparación visual de desempeño</div><div class="chart-box tall"><canvas id="chartTechRanking"></canvas></div></div>
            <div class="card span-12"><h3>Carga de trabajo y cobertura territorial</h3><div class="chart-box"><canvas id="chartWorkload"></canvas></div></div>
        </div>
    </section>

    <section id="tab-centros" class="tab-panel">
        <div class="grid">
            <div class="card span-8"><h3>Desempeño por centro</h3><div class="hint">Haz clic para consultar matrícula, técnicos, actividades y tendencia histórica</div><div class="table-wrap"><table class="table"><thead><tr><th>Centro</th><th>Tipo / base</th><th>Matrícula</th><th>Prog.</th><th>Cumpl.</th><th>A tiempo</th><th>En forma</th><th>Desempeño</th></tr></thead><tbody><?php foreach($centerStats as $c):?><tr class="click-row" onclick='openCenter(<?=json_encode((string)$c['id'],JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><td><div class="name"><?=h($c['name'])?></div><div class="mini"><?=h($c['caserio'])?></div></td><td><span class="chip"><?=h($c['type'])?></span><div class="mini"><?=h($c['base'])?></div></td><td><?=number_format($c['matricula'],0)?></td><td><?=number_format($c['plan'],0)?></td><td><?=number_format($c['done'],0)?></td><td><?=number_format($c['on_time'],1)?>%</td><td><?=number_format($c['in_form'],1)?>%</td><td><span class="score <?=scoreBand($c['score'])?>"><?=number_format($c['score'],1)?>%</span></td></tr><?php endforeach;?></tbody></table></div></div>
            <div class="card span-4"><h3>Centros por tipo</h3><div class="hint">Cantidad y desempeño promedio</div><div class="chart-box"><canvas id="chartCenterTypes"></canvas></div></div>
            <div class="card span-6"><h3>Programado vs. cumplido por centro</h3><div class="chart-box tall"><canvas id="chartCenterPlan"></canvas></div></div>
            <div class="card span-6"><h3>Cobertura de matrícula</h3><div class="summary-strip"><div class="summary-box"><span>Catálogo total</span><strong><?=number_format(count($allCenters))?></strong></div><div class="summary-box"><span>Monitoreados</span><strong><?=number_format($totalCentersMonitored)?></strong></div><div class="summary-box"><span>Con logro</span><strong><?=number_format($centersReached)?></strong></div><div class="summary-box"><span>Matrícula monitoreada</span><strong><?=number_format(array_sum(array_column($centerStats,'matricula')))?></strong></div></div><div class="chart-box small"><canvas id="chartCenterCoverage"></canvas></div></div>
        </div>
    </section>

    <section id="tab-programas" class="tab-panel">
        <div class="grid">
            <div class="card span-5"><h3>Resumen por programa</h3><div class="table-wrap"><table class="table"><thead><tr><th>Programa</th><th>Actividades</th><th>Meta</th><th>Logro</th><th>Desempeño</th></tr></thead><tbody><?php foreach($programStats as $name=>$p):?><tr><td class="name"><?=h($name)?></td><td><?=number_format($p['activities'])?></td><td><?=number_format($p['tech_plan'])?></td><td><?=number_format($p['tech_done'])?></td><td><span class="score <?=scoreBand($p['score'])?>"><?=number_format($p['score'],1)?>%</span></td></tr><?php endforeach;?></tbody></table></div></div>
            <div class="card span-7"><h3>Programación, logro y presupuesto por programa</h3><div class="chart-box tall"><canvas id="chartProgramCombo"></canvas></div></div>
            <div class="card span-12"><h3>Actividades con mayor necesidad de seguimiento</h3><div class="hint">Ordenadas desde menor avance programático</div><div class="table-wrap"><table class="table"><thead><tr><th>Código</th><th>Actividad</th><th>Programa</th><th>Prog.</th><th>Cumpl.</th><th>E-1</th><th>E-2</th><th>E-3</th><th>E-4</th><th>Avance</th></tr></thead><tbody><?php foreach($activityStats as $a):?><tr><td><span class="chip"><?=h($a['code'])?></span><div class="mini"><?=h($a['ext'])?></div></td><td><div class="name"><?=h($a['description'])?></div><div class="mini"><?=h($a['sector'])?></div></td><td><?=h($a['program'])?></td><td><?=number_format($a['plan'])?></td><td><?=number_format($a['done'])?></td><?php foreach(['E-1','E-2','E-3','E-4'] as $e):?><td><?=number_format($a['stage_scores'][$e],1)?>%</td><?php endforeach;?><td><span class="score <?=scoreBand($a['score'])?>"><?=number_format($a['score'],1)?>%</span></td></tr><?php endforeach;?></tbody></table></div></div>
        </div>
    </section>

    <section id="tab-calidad" class="tab-panel">
        <div class="grid">
            <div class="card span-7"><h3>Ejecución financiera mensual</h3><div class="hint">Presupuesto frente a ejecución real</div><div class="chart-box tall"><canvas id="chartFinance"></canvas></div></div>
            <div class="card span-5"><h3>Salud del sistema de monitoreo</h3><div class="metric-line"><div><strong>Integridad de etapas</strong><span>Actividades con las cuatro fases registradas</span></div><b><?=number_format($dataCompleteness,1)?>%</b></div><div class="bar"><span style="width:<?=min(100,$dataCompleteness)?>%"></span></div><div class="metric-line"><div><strong>Cobertura de asignación</strong><span>Actividades con personal asignado</span></div><b><?php $assignedActs=count(array_filter($activityStats,function($x){return $x['assigned']>0;}));$coverage=count($activityStats)?$assignedActs/count($activityStats)*100:0;echo number_format($coverage,1);?>%</b></div><div class="bar"><span style="width:<?=min(100,$coverage)?>%"></span></div><div class="metric-line"><div><strong>Centros con datos</strong><span>Catálogo vinculado al monitoreo</span></div><b><?=count($allCenters)?number_format($totalCentersMonitored/count($allCenters)*100,1):'0.0'?>%</b></div><div class="bar"><span style="width:<?=count($allCenters)?min(100,$totalCentersMonitored/count($allCenters)*100):0?>%"></span></div><div class="summary-strip" style="grid-template-columns:1fr 1fr"><div class="summary-box"><span>Presupuesto</span><strong>L <?=number_format($totalBudget,2)?></strong></div><div class="summary-box"><span>Ejecutado</span><strong>L <?=number_format($totalSpent,2)?></strong></div><div class="summary-box"><span>Saldo</span><strong>L <?=number_format($totalBudget-$totalSpent,2)?></strong></div><div class="summary-box"><span>Meta participantes</span><strong><?=number_format($totalPlanParticipants)?></strong></div></div></div>
            <div class="card span-6"><h3>Calidad por etapa</h3><div class="chart-box"><canvas id="chartStageRadar"></canvas></div></div>
            <div class="card span-6"><h3>Relación programática-financiera</h3><div class="hint">Compara avance programático con ejecución presupuestaria</div><div class="chart-box"><canvas id="chartProgramFinance"></canvas></div></div>
        </div>
    </section>
</main>

<div id="detailModal" class="modal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-head"><div><h2 id="modalTitle">Detalle</h2><p id="modalSubtitle"></p></div><button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button></div>
        <div id="modalBody" class="modal-body"></div>
    </div>
</div>
<script>
const months = <?=json_encode($months)?>;
const monthLabels = <?=json_encode(array_values(array_intersect_key($monthLabels,array_flip($months))),JSON_UNESCAPED_UNICODE)?>;
const monthly = <?=json_encode(array_values($monthly),JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
const stageData = <?=json_encode($stageChart,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
const programStats = <?=json_encode($programStats,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
const techData = <?=json_encode($jsTech,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
const centerData = <?=json_encode($jsCenters,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
const centerTypeData = <?=json_encode($centerTypeSummary,JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK)?>;
const selectedMonths = <?=json_encode($selectedMonths)?>;
const colors = {primary:'#34859b',primary2:'#46b094',blue:'#0284c7',green:'#15803d',orange:'#ea580c',red:'#dc2626',gray:'#94a3b8',yellow:'#ca8a04'};
Chart.defaults.font.family='Inter'; Chart.defaults.color='#52657b'; Chart.defaults.plugins.legend.labels.usePointStyle=true;
function chart(id,config){const el=document.getElementById(id);if(el&&window.Chart)return new Chart(el,config);}
function fmt(n){return new Intl.NumberFormat('es-HN',{maximumFractionDigits:1}).format(Number(n||0))}
function esc(s){return String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}
function band(v){return v<=25?'red':v<=50?'orange':v<=75?'softgreen':'green'}
function toggleCustom(v){document.querySelectorAll('.custom-period').forEach(e=>e.style.opacity=v==='custom'?'1':'.45')}
toggleCustom(<?=json_encode($periodMode)?>);
document.querySelectorAll('.tab').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.tab-panel').forEach(x=>x.classList.remove('active'));btn.classList.add('active');document.getElementById('tab-'+btn.dataset.tab).classList.add('active');window.dispatchEvent(new Event('resize'));}));

chart('chartMonthly',{type:'line',data:{labels:monthLabels,datasets:[{label:'Programado',data:monthly.map(x=>x.tech_plan),borderColor:colors.primary,backgroundColor:'rgba(52,133,155,.12)',fill:true,tension:.35},{label:'Cumplido',data:monthly.map(x=>x.tech_done),borderColor:colors.green,backgroundColor:'rgba(21,128,61,.08)',fill:true,tension:.35}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{beginAtZero:true}},plugins:{tooltip:{callbacks:{label:c=>c.dataset.label+': '+fmt(c.raw)}}}}});
chart('chartStages',{type:'bar',data:{labels:Object.keys(stageData),datasets:[{label:'Avance %',data:Object.values(stageData),backgroundColor:[colors.blue,colors.primary,colors.primary2,colors.green],borderRadius:8}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{beginAtZero:true,max:100}},plugins:{legend:{display:false}}}});
chart('chartPrograms',{type:'doughnut',data:{labels:Object.keys(programStats),datasets:[{data:Object.values(programStats).map(x=>x.score),backgroundColor:['#34859b','#46b094','#0284c7','#65a30d','#ea580c','#8b5cf6','#64748b']}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{tooltip:{callbacks:{label:c=>c.label+': '+fmt(c.raw)+'%'}}}}});
chart('chartQuality',{type:'radar',data:{labels:['A tiempo','En forma','Actividades','Técnicos','Centros','Datos'],datasets:[{label:'Índice',data:[<?=round($avgOnTime,2)?>,<?=round($avgInForm,2)?>,<?=round($avgActivityScore,2)?>,<?=round($avgTechScore,2)?>,<?=round($avgCenterScore,2)?>,<?=round($dataCompleteness,2)?>],backgroundColor:'rgba(52,133,155,.16)',borderColor:colors.primary,pointBackgroundColor:colors.primary}]},options:{responsive:true,maintainAspectRatio:false,scales:{r:{beginAtZero:true,max:100,ticks:{display:false}}},plugins:{legend:{display:false}}}});
const topTech=Object.values(techData).slice(0,10);
chart('chartTechRanking',{type:'bar',data:{labels:topTech.map(x=>x.name),datasets:[{label:'Desempeño',data:topTech.map(x=>x.score),backgroundColor:topTech.map(x=>x.score<=25?'#dc2626':x.score<=50?'#ea580c':x.score<=75?'#84cc16':'#16a34a'),borderRadius:7}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{beginAtZero:true,max:100}},plugins:{legend:{display:false}}}});
chart('chartWorkload',{type:'bubble',data:{datasets:Object.values(techData).map((t,i)=>({label:t.name,data:[{x:t.activities.length,y:t.plan,r:Math.max(5,Math.min(22,t.done?Math.sqrt(t.done):5))}],backgroundColor:`hsla(${(i*43)%360},60%,48%,.6)`}))},options:{responsive:true,maintainAspectRatio:false,scales:{x:{title:{display:true,text:'Actividades asignadas'},beginAtZero:true},y:{title:{display:true,text:'Programado'},beginAtZero:true}},plugins:{tooltip:{callbacks:{label:c=>c.dataset.label+' · '+c.raw.x+' actividades · '+fmt(c.raw.y)+' programado'}}}}});
chart('chartCenterTypes',{type:'bar',data:{labels:Object.keys(centerTypeData),datasets:[{label:'Centros',data:Object.values(centerTypeData).map(x=>x.count),backgroundColor:colors.primary,borderRadius:7},{label:'Desempeño %',data:Object.values(centerTypeData).map(x=>x.score),backgroundColor:colors.primary2,borderRadius:7}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}},plugins:{tooltip:{callbacks:{label:c=>c.dataset.label+': '+fmt(c.raw)}}}}});
const topCenters=Object.values(centerData).sort((a,b)=>b.plan-a.plan).slice(0,15);
chart('chartCenterPlan',{type:'bar',data:{labels:topCenters.map(x=>x.name),datasets:[{label:'Programado',data:topCenters.map(x=>x.plan),backgroundColor:colors.primary,borderRadius:5},{label:'Cumplido',data:topCenters.map(x=>x.done),backgroundColor:colors.green,borderRadius:5}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{beginAtZero:true}}}});
chart('chartCenterCoverage',{type:'doughnut',data:{labels:['Con logro','Monitoreados sin logro','Sin monitoreo'],datasets:[{data:[<?=$centersReached?>,<?=max(0,$totalCentersMonitored-$centersReached)?>,<?=max(0,count($allCenters)-$totalCentersMonitored)?>],backgroundColor:[colors.green,colors.orange,'#dbe4ee']}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%'}});
chart('chartProgramCombo',{type:'bar',data:{labels:Object.keys(programStats),datasets:[{label:'Programado',data:Object.values(programStats).map(x=>x.tech_plan),backgroundColor:colors.primary,borderRadius:5},{label:'Cumplido',data:Object.values(programStats).map(x=>x.tech_done),backgroundColor:colors.green,borderRadius:5},{label:'Avance %',data:Object.values(programStats).map(x=>x.score),type:'line',borderColor:colors.orange,backgroundColor:colors.orange,yAxisID:'y1',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true},y1:{beginAtZero:true,max:100,position:'right',grid:{drawOnChartArea:false}}}}});
chart('chartFinance',{type:'bar',data:{labels:monthLabels,datasets:[{label:'Presupuesto',data:monthly.map(x=>x.budget),backgroundColor:colors.primary,borderRadius:5},{label:'Ejecutado',data:monthly.map(x=>x.spent),backgroundColor:colors.orange,borderRadius:5}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}},plugins:{tooltip:{callbacks:{label:c=>c.dataset.label+': L '+fmt(c.raw)}}}}});
chart('chartStageRadar',{type:'radar',data:{labels:Object.keys(stageData),datasets:[{label:'Avance',data:Object.values(stageData),backgroundColor:'rgba(70,176,148,.2)',borderColor:colors.primary2,pointBackgroundColor:colors.primary2}]},options:{responsive:true,maintainAspectRatio:false,scales:{r:{beginAtZero:true,max:100}}}});
chart('chartProgramFinance',{type:'scatter',data:{datasets:Object.entries(programStats).map(([name,p],i)=>({label:name,data:[{x:p.budget?Math.min(150,p.spent/p.budget*100):0,y:p.score,r:8}],backgroundColor:`hsla(${(i*63)%360},65%,45%,.7)`,pointRadius:8}))},options:{responsive:true,maintainAspectRatio:false,scales:{x:{title:{display:true,text:'Ejecución financiera %'},beginAtZero:true},y:{title:{display:true,text:'Avance programático %'},beginAtZero:true,max:100}},plugins:{tooltip:{callbacks:{label:c=>c.dataset.label+' · Financiero '+fmt(c.raw.x)+'% · Programático '+fmt(c.raw.y)+'%'}}}}});

let detailChart=null;
function closeModal(){document.getElementById('detailModal').classList.remove('open');if(detailChart){detailChart.destroy();detailChart=null}}
function openTech(name){const t=techData[name];if(!t)return;document.getElementById('modalTitle').textContent=t.name;document.getElementById('modalSubtitle').textContent=(t.programs||[]).join(' · ')+' | '+(t.bases||[]).join(', ');const rows=months.map((m,i)=>`<tr><td>${monthLabels[i]}</td><td>${fmt(t.monthly[m].plan)}</td><td>${fmt(t.monthly[m].done)}</td><td><span class="score ${band(t.monthly[m].score)}">${fmt(t.monthly[m].score)}%</span></td></tr>`).join('');document.getElementById('modalBody').innerHTML=`<div class="modal-grid"><div class="detail-card span-3"><div class="label">Programado</div><div class="value">${fmt(t.plan)}</div></div><div class="detail-card span-3"><div class="label">Cumplido</div><div class="value">${fmt(t.done)}</div></div><div class="detail-card span-3"><div class="label">A tiempo</div><div class="value">${fmt(t.on_time)}%</div></div><div class="detail-card span-3"><div class="label">Desempeño</div><div class="value">${fmt(t.score)}%</div></div><div class="card span-8" style="box-shadow:none"><h3>Línea de tiempo</h3><div class="chart-box"><canvas id="modalChart"></canvas></div></div><div class="card span-4" style="box-shadow:none"><h3>Detalle mensual</h3><div class="table-wrap" style="max-height:330px"><table class="table"><thead><tr><th>Mes</th><th>Prog.</th><th>Cumpl.</th><th>%</th></tr></thead><tbody>${rows}</tbody></table></div></div></div>`;document.getElementById('detailModal').classList.add('open');setTimeout(()=>{detailChart=chart('modalChart',{type:'line',data:{labels:monthLabels,datasets:[{label:'Programado',data:months.map(m=>t.monthly[m].plan),borderColor:colors.primary,tension:.3},{label:'Cumplido',data:months.map(m=>t.monthly[m].done),borderColor:colors.green,tension:.3},{label:'Desempeño %',data:months.map(m=>t.monthly[m].score),borderColor:colors.orange,yAxisID:'y1',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true},y1:{position:'right',beginAtZero:true,max:100,grid:{drawOnChartArea:false}}}}})},30)}
function openCenter(id){const c=centerData[id];if(!c)return;document.getElementById('modalTitle').textContent=c.name;document.getElementById('modalSubtitle').textContent=c.type+' · '+c.base+' · '+c.caserio;const rows=months.map((m,i)=>`<tr><td>${monthLabels[i]}</td><td>${fmt(c.monthly[m].plan)}</td><td>${fmt(c.monthly[m].done)}</td><td>${fmt(c.monthly[m].on_time)}%</td><td>${fmt(c.monthly[m].in_form)}%</td><td><span class="score ${band(c.monthly[m].score)}">${fmt(c.monthly[m].score)}%</span></td></tr>`).join('');document.getElementById('modalBody').innerHTML=`<div class="modal-grid"><div class="detail-card span-3"><div class="label">Matrícula</div><div class="value">${fmt(c.matricula)}</div></div><div class="detail-card span-3"><div class="label">Programado</div><div class="value">${fmt(c.plan)}</div></div><div class="detail-card span-3"><div class="label">Cumplido</div><div class="value">${fmt(c.done)}</div></div><div class="detail-card span-3"><div class="label">Desempeño</div><div class="value">${fmt(c.score)}%</div></div><div class="card span-7" style="box-shadow:none"><h3>Tendencia del centro</h3><div class="chart-box"><canvas id="modalChart"></canvas></div></div><div class="card span-5" style="box-shadow:none"><h3>Detalle mensual</h3><div class="table-wrap" style="max-height:330px"><table class="table"><thead><tr><th>Mes</th><th>Prog.</th><th>Cumpl.</th><th>A tiempo</th><th>En forma</th><th>%</th></tr></thead><tbody>${rows}</tbody></table></div></div><div class="detail-card span-4"><div class="label">Técnicos vinculados</div><div class="value" style="font-size:.83rem">${esc((c.technicians||[]).join(', ')||'Sin registrar')}</div></div><div class="detail-card span-4"><div class="label">Actividades vinculadas</div><div class="value">${(c.activities||[]).length}</div></div><div class="detail-card span-4"><div class="label">Población por edad</div><div class="value" style="font-size:.8rem">0–5: ${fmt(c.age_0_5)} · 6–17: ${fmt(c.age_6_17)} · 18–24: ${fmt(c.age_18_24)}</div></div></div>`;document.getElementById('detailModal').classList.add('open');setTimeout(()=>{detailChart=chart('modalChart',{type:'line',data:{labels:monthLabels,datasets:[{label:'Programado',data:months.map(m=>c.monthly[m].plan),borderColor:colors.primary,tension:.3},{label:'Cumplido',data:months.map(m=>c.monthly[m].done),borderColor:colors.green,tension:.3},{label:'Desempeño %',data:months.map(m=>c.monthly[m].score),borderColor:colors.orange,yAxisID:'y1',tension:.3}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true},y1:{position:'right',beginAtZero:true,max:100,grid:{drawOnChartArea:false}}}}})},30)}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal()});
</script>
</body>
</html>
