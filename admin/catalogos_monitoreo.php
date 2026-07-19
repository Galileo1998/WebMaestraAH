<?php
// BUILD: CATALOGOS_MONITOREO_SEGMENTADOS_V6_DESBLOQUEADO
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

$CATALOG_PROGRAMS = [
    'GENERAL' => 'GENERAL / TODOS',
    'CRECER' => 'CRECER',
    'REDES' => 'REDES',
    'TEJIENDO_MI_FUTURO' => 'TEJIENDO MI FUTURO',
    'ML_MONITOREO' => 'ML_MONITOREO',
    'X_PATROCINIO' => 'X_PATROCINIO',
    'Z_ADMINISTRACION' => 'Z_ADMINISTRACION',
];

$CATALOG_STAGES = [
    'TODAS' => 'TODAS LAS ETAPAS',
    'E-1' => 'E-1 · Diseñar actividad',
    'E-2' => 'E-2 · Organizar y socializar',
    'E-3' => 'E-3 · Desarrollar y reportar',
    'E-4' => 'E-4 · Asistencia y monitoreo',
];

function catalogTable($type) {
    if ($type === 'unidad') return 'ah_cat_unidades';
    if ($type === 'verificacion') return 'ah_cat_verificaciones';
    throw new InvalidArgumentException('Tipo de catálogo no válido.');
}

function normalizeProgram($value) {
    $value = strtoupper(trim((string)$value));
    $map = [
        'TEJIENDO MI FUTURO' => 'TEJIENDO_MI_FUTURO',
        'TEJIENDO_MI_FUTURO' => 'TEJIENDO_MI_FUTURO',
        'TEJIENDO' => 'TEJIENDO_MI_FUTURO',
        'ML MONITOREO' => 'ML_MONITOREO',
        'X PATROCINIO' => 'X_PATROCINIO',
        'Z ADMINISTRACION' => 'Z_ADMINISTRACION',
        'Z ADMINISTRACIÓN' => 'Z_ADMINISTRACION',
    ];
    if (isset($map[$value])) $value = $map[$value];
    $value = str_replace(['Á','É','Í','Ó','Ú','Ñ'], ['A','E','I','O','U','N'], $value);
    $value = preg_replace('/[^A-Z0-9]+/', '_', $value);
    if ($value === null) $value = '';
    $value = trim($value, '_');
    if (strpos($value, 'CRECER') !== false) return 'CRECER';
    if (strpos($value, 'REDES') !== false) return 'REDES';
    if (strpos($value, 'TEJIENDO') !== false) return 'TEJIENDO_MI_FUTURO';
    if (strpos($value, 'ML_MONITOREO') !== false) return 'ML_MONITOREO';
    if (strpos($value, 'X_PATROCINIO') !== false) return 'X_PATROCINIO';
    if (strpos($value, 'Z_ADMINISTRACION') !== false) return 'Z_ADMINISTRACION';
    return $value === 'GENERAL' ? 'GENERAL' : 'GENERAL';
}

function normalizeStage($value) {
    $value = strtoupper(trim((string)$value));
    $value = str_replace(['ETAPA', ' '], ['', ''], $value);
    if (in_array($value, ['TODAS', 'TODOS', 'GENERAL', ''], true)) return 'TODAS';
    if (preg_match('/^E-?([1-4])$/', $value, $m)) return 'E-' . $m[1];
    if (preg_match('/^([1-4])$/', $value, $m)) return 'E-' . $m[1];
    return 'TODAS';
}

// =========================================================================
// MIGRACIÓN SILENCIOSA
// =========================================================================
function ensureCatalogSchema($db, $table) {
    try { $db->exec("CREATE TABLE IF NOT EXISTS `$table` (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE `$table` ADD COLUMN programa VARCHAR(60) NOT NULL DEFAULT 'GENERAL'"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE `$table` ADD COLUMN etapa VARCHAR(10) NOT NULL DEFAULT 'TODAS'"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE `$table` ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE `$table` ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE `$table` ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (Throwable $e) {}
    try {
        $db->exec("UPDATE `$table` SET programa = 'GENERAL' WHERE programa IS NULL OR TRIM(programa) = ''");
        $db->exec("UPDATE `$table` SET etapa = 'TODAS' WHERE etapa IS NULL OR TRIM(etapa) = ''");
    } catch (Throwable $e) {}
}

ensureCatalogSchema($db, 'ah_cat_unidades');
ensureCatalogSchema($db, 'ah_cat_verificaciones');

function jsonResponse($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =========================================================================
// ACCIONES AJAX POST
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = (string)$_POST['action'];
        $type = (string)($_POST['catalog_type'] ?? 'unidad');
        $table = catalogTable($type);

        if (in_array($action, ['create','update'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $program = normalizeProgram($_POST['programa'] ?? 'GENERAL');
            $stage = normalizeStage($_POST['etapa'] ?? 'TODAS');
            $name = trim((string)($_POST['nombre'] ?? ''));
            if ($name === '') throw new RuntimeException('Ingrese el nombre del elemento.');
            if (mb_strlen($name, 'UTF-8') > 150) throw new RuntimeException('El nombre no puede superar 150 caracteres.');

            if ($action === 'update' && $id > 0) {
                $st = $db->prepare("UPDATE `$table` SET programa=?, etapa=?, nombre=?, activo=1 WHERE id=?");
                $st->execute([$program, $stage, $name, $id]);
            } else {
                $st = $db->prepare("INSERT INTO `$table` (programa, etapa, nombre, activo) VALUES (?, ?, ?, 1)");
                $st->execute([$program, $stage, $name]);
            }
            jsonResponse(['status'=>'ok','msg'=>'Elemento guardado.']);
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['activo'] ?? 0) === 1 ? 1 : 0;
            if ($id <= 0) throw new RuntimeException('Registro inválido.');
            $db->prepare("UPDATE `$table` SET activo=? WHERE id=?")->execute([$active, $id]);
            jsonResponse(['status'=>'ok','msg'=>$active ? 'Elemento activado.' : 'Elemento desactivado.']);
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Registro inválido.');
            $db->prepare("DELETE FROM `$table` WHERE id=?")->execute([$id]);
            jsonResponse(['status'=>'ok','msg'=>'Elemento eliminado.']);
        }

        if ($action === 'import') {
            $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
            if (!is_array($rows) || !$rows) throw new RuntimeException('No se recibieron filas para importar.');
            if (count($rows) > 500) throw new RuntimeException('Cada lote puede contener como máximo 500 filas.');

            $insert = $db->prepare("INSERT INTO `$table` (programa, etapa, nombre, activo) VALUES (?, ?, ?, 1)");
            $ok = 0; $omitted = 0; $errors = [];
            $db->beginTransaction();
            foreach ($rows as $i => $row) {
                if (!is_array($row)) { $omitted++; continue; }
                $program = normalizeProgram($row['PROGRAMA'] ?? $row['programa'] ?? 'GENERAL');
                $stage = normalizeStage($row['ETAPA'] ?? $row['etapa'] ?? 'TODAS');
                $name = trim((string)($row['NOMBRE'] ?? $row['nombre'] ?? ''));
                if ($name === '') { $omitted++; $errors[] = 'Fila ' . ($i + 2) . ': NOMBRE vacío.'; continue; }
                if (mb_strlen($name, 'UTF-8') > 150) { $omitted++; $errors[] = 'Fila ' . ($i + 2) . ': nombre demasiado largo.'; continue; }
                $insert->execute([$program, $stage, $name]);
                $ok++;
            }
            $db->commit();
            jsonResponse(['status'=>'ok','inserted'=>$ok,'omitted'=>$omitted,'errors'=>array_slice($errors,0,20)]);
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jsonResponse(['status'=>'error','msg'=>$e->getMessage()], 400);
    }
}

// =========================================================================
// OBTENER DATOS DE LA BASE DE DATOS DE FORMA SEGURA
// =========================================================================
$units = [];
$verifications = [];

try {
    $stmtU = $db->query("SELECT id, programa, etapa, nombre, activo FROM ah_cat_unidades ORDER BY programa, etapa, nombre");
    if ($stmtU) $units = $stmtU->fetchAll(PDO::FETCH_ASSOC);

    $stmtV = $db->query("SELECT id, programa, etapa, nombre, activo FROM ah_cat_verificaciones ORDER BY programa, etapa, nombre");
    if ($stmtV) $verifications = $stmtV->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$activeUnits = 0;
foreach ($units as $u) { if ((int)$u['activo'] === 1) $activeUnits++; }
$activeVerifications = 0;
foreach ($verifications as $v) { if ((int)$v['activo'] === 1) $activeVerifications++; }

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Catálogos de Monitoreo | Acción Honduras</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
:root{--primary:#34859b;--primary-dark:#075985;--mint:#46b094;--bg:#f5f8fb;--border:#dbe4ee;--text:#172033;--muted:#64748b;--danger:#dc2626;--warning:#d97706;--green:#15803d}
*{box-sizing:border-box}body{margin:0;font-family:Inter,sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh}.main{flex:1;padding:30px 40px;min-width:0}.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}.title h1{margin:0;font-size:2rem}.title p{margin:7px 0 0;color:var(--muted)}.btn{border:1px solid var(--border);background:#fff;color:var(--text);border-radius:10px;padding:10px 15px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none}.btn:hover{border-color:#8ecddd;background:#f0fbff}.btn.primary{background:var(--primary);color:#fff;border-color:var(--primary)}.btn.green{background:#15803d;color:#fff;border-color:#15803d}.btn.danger{color:#b91c1c;background:#fff5f5;border-color:#fecaca}.btn.small{padding:7px 10px;font-size:.78rem}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:20px}.metric{background:#fff;border:1px solid var(--border);border-radius:15px;padding:17px;display:flex;align-items:center;gap:13px}.metric i{width:45px;height:45px;border-radius:12px;display:grid;place-items:center;background:#e0f2fe;color:#0284c7;font-size:1.25rem}.metric span{display:block;color:var(--muted);font-size:.75rem;font-weight:900;text-transform:uppercase}.metric strong{display:block;font-size:1.55rem;margin-top:3px}.layout{display:grid;grid-template-columns:390px minmax(0,1fr);gap:18px}.panel{background:#fff;border:1px solid var(--border);border-radius:16px;overflow:hidden}.panel-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:10px}.panel-head h2{font-size:1rem;margin:0}.panel-body{padding:18px}.field{margin-bottom:14px}.field label{display:block;font-size:.76rem;font-weight:900;text-transform:uppercase;color:#475569;margin-bottom:6px}.input{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:10px 12px;font:inherit;background:#fff}.input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(52,133,155,.12)}.form-actions{display:flex;gap:9px;justify-content:flex-end}.divider{height:1px;background:var(--border);margin:20px 0}.drop{border:2px dashed #b8c7d8;border-radius:13px;padding:22px;text-align:center;background:#f8fafc;cursor:pointer}.drop.drag{border-color:var(--mint);background:#effcf8}.drop i{font-size:2rem;color:#16a34a}.drop strong{display:block;margin-top:8px}.drop small{color:var(--muted)}.import-status{font-size:.82rem;line-height:1.5;margin-top:10px;color:var(--muted)}.toolbar{display:grid;grid-template-columns:180px 220px 180px minmax(180px,1fr);gap:10px;padding:15px;border-bottom:1px solid var(--border);background:#f8fafc}.tabs{display:flex;gap:8px}.tab{border:1px solid var(--border);background:#fff;border-radius:999px;padding:8px 13px;font-weight:900;cursor:pointer;color:#64748b}.tab.active{background:#e0f2fe;border-color:#7dd3fc;color:#075985}.table-wrap{overflow:auto;max-height:70vh}.table{width:100%;border-collapse:collapse;min-width:920px}.table th{position:sticky;top:0;background:#132139;color:#fff;text-align:left;padding:12px;font-size:.74rem;text-transform:uppercase;z-index:2}.table td{padding:11px 12px;border-bottom:1px solid #e5edf5;font-size:.86rem;vertical-align:middle}.table tr:hover td{background:#f8fcfe}.chip{display:inline-flex;padding:4px 8px;border-radius:999px;background:#e0f2fe;color:#075985;font-size:.72rem;font-weight:900}.chip.stage{background:#fef3c7;color:#92400e}.status{font-size:.72rem;font-weight:900;padding:5px 8px;border-radius:999px}.status.on{background:#dcfce7;color:#166534}.status.off{background:#fee2e2;color:#991b1b}.actions{display:flex;gap:6px;white-space:nowrap}.empty{text-align:center;padding:50px;color:#94a3b8}.toast{position:fixed;right:24px;bottom:24px;background:#10233c;color:#fff;padding:13px 17px;border-radius:11px;box-shadow:0 15px 35px rgba(15,23,42,.25);display:none;z-index:9999}.toast.error{background:#991b1b}@media(max-width:1150px){.layout{grid-template-columns:1fr}.metrics{grid-template-columns:1fr 1fr}.toolbar{grid-template-columns:1fr 1fr}}@media(max-width:680px){.main{padding:20px 14px}.metrics,.toolbar{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main">
    <div class="topbar">
        <div class="title"><h1><i class="fa-solid fa-layer-group" style="color:var(--primary)"></i> Catálogos de Monitoreo</h1><p>Unidades y medios de verificación segmentados por programa y etapa.</p></div>
        <div style="display:flex;gap:9px;flex-wrap:wrap"><a class="btn" href="monitoreo.php"><i class="fa-solid fa-arrow-left"></i> Volver a Monitoreo</a><button class="btn green" type="button" onclick="downloadTemplate()"><i class="fa-solid fa-file-excel"></i> Descargar plantilla</button></div>
    </div>

    <div class="metrics">
        <div class="metric"><i class="fa-solid fa-ruler-combined"></i><div><span>Unidades activas</span><strong><?= number_format($activeUnits) ?></strong></div></div>
        <div class="metric"><i class="fa-solid fa-camera-retro"></i><div><span>Verificaciones activas</span><strong><?= number_format($activeVerifications) ?></strong></div></div>
        <div class="metric"><i class="fa-solid fa-diagram-project"></i><div><span>Etapas</span><strong>4</strong></div></div>
        <div class="metric"><i class="fa-solid fa-shapes"></i><div><span>Programas / sectores</span><strong>6</strong></div></div>
    </div>

    <div class="layout">
        <section class="panel">
            <div class="panel-head"><h2><i class="fa-solid fa-circle-plus"></i> Crear o editar</h2><button class="btn small" type="button" onclick="resetForm()"><i class="fa-solid fa-rotate-left"></i> Limpiar</button></div>
            <div class="panel-body">
                <form id="catalog-form">
                    <input type="hidden" name="id" id="item-id" value="0">
                    <div class="field"><label>Catálogo</label><select class="input" name="catalog_type" id="catalog-type"><option value="unidad">Unidad</option><option value="verificacion">Medio de verificación</option></select></div>
                    <div class="field"><label>Programa / sector</label><select class="input" name="programa" id="item-program"><?php foreach($CATALOG_PROGRAMS as $k=>$v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Etapa</label><select class="input" name="etapa" id="item-stage"><?php foreach($CATALOG_STAGES as $k=>$v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Nombre</label><input class="input" name="nombre" id="item-name" maxlength="150" placeholder="Ej.: Listado de participación" required></div>
                    <div class="form-actions"><button type="submit" class="btn primary"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div>
                </form>

                <div class="divider"></div>
                <h3 style="font-size:1rem;margin:0 0 12px"><i class="fa-solid fa-file-import" style="color:#15803d"></i> Importar desde Excel</h3>
                <div class="field"><label>Importar como</label><select class="input" id="import-type"><option value="unidad">Unidades</option><option value="verificacion">Medios de verificación</option></select></div>
                <input type="file" id="excel-file" accept=".xlsx,.xls,.csv" hidden>
                <div class="drop" id="excel-drop" onclick="document.getElementById('excel-file').click()"><i class="fa-solid fa-file-excel"></i><strong>Seleccione o arrastre el Excel</strong><small>Columnas: PROGRAMA, ETAPA, NOMBRE</small></div>
                <div class="import-status" id="import-status">La importación crea registros nuevos y reactiva los que ya existan.</div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><div class="tabs"><button class="tab active" data-tab="unidad" type="button">Unidades</button><button class="tab" data-tab="verificacion" type="button">Medios de verificación</button></div><span id="visible-count" style="font-weight:900;color:var(--primary)">0 registros</span></div>
            <div class="toolbar"><select class="input" id="filter-program"><option value="">Todos los programas</option><?php foreach($CATALOG_PROGRAMS as $k=>$v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select><select class="input" id="filter-stage"><option value="">Todas las etapas</option><?php foreach($CATALOG_STAGES as $k=>$v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select><select class="input" id="filter-active"><option value="">Activos e inactivos</option><option value="1">Activos</option><option value="0">Inactivos</option></select><input class="input" id="filter-search" placeholder="Buscar por nombre..."></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Programa</th><th>Etapa</th><th>Nombre</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="catalog-body"></tbody></table><div class="empty" id="empty-state" style="display:none"><i class="fa-solid fa-inbox" style="font-size:2rem"></i><p>No hay elementos con estos filtros.</p></div></div>
        </section>
    </div>
</main>
<div class="toast" id="toast"></div>
<script>
const dataSets={unidad:<?= json_encode($units, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,verificacion:<?= json_encode($verifications, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>};
let activeTab='unidad';
const programLabels=<?= json_encode($CATALOG_PROGRAMS, JSON_UNESCAPED_UNICODE) ?>;
const stageLabels=<?= json_encode($CATALOG_STAGES, JSON_UNESCAPED_UNICODE) ?>;
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function norm(v){return String(v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');}
function toast(msg,error=false){const el=document.getElementById('toast');el.textContent=msg;el.className='toast'+(error?' error':'');el.style.display='block';clearTimeout(window.toastTimer);window.toastTimer=setTimeout(()=>el.style.display='none',2600);}
async function post(params){const response=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(params)});const text=await response.text();let json;try{json=JSON.parse(text)}catch(e){throw new Error(text||'Respuesta inválida del servidor.')}if(!response.ok||json.status!=='ok')throw new Error(json.msg||'No se pudo completar la operación.');return json;}
function render(){const p=document.getElementById('filter-program').value,s=document.getElementById('filter-stage').value,a=document.getElementById('filter-active').value,q=norm(document.getElementById('filter-search').value);const rows=(dataSets[activeTab]||[]).filter(r=>(!p||r.programa===p)&&(!s||r.etapa===s)&&(a===''||String(r.activo)===a)&&(!q||norm(r.nombre).includes(q)));const body=document.getElementById('catalog-body');body.innerHTML=rows.map(r=>`<tr><td><span class="chip">${esc(programLabels[r.programa]||r.programa)}</span></td><td><span class="chip stage">${esc(stageLabels[r.etapa]||r.etapa)}</span></td><td><strong>${esc(r.nombre)}</strong></td><td><span class="status ${Number(r.activo)===1?'on':'off'}">${Number(r.activo)===1?'Activo':'Inactivo'}</span></td><td><div class="actions"><button class="btn small" onclick="editItem(${Number(r.id)})"><i class="fa-solid fa-pen"></i></button><button class="btn small" onclick="toggleItem(${Number(r.id)},${Number(r.activo)===1?0:1})"><i class="fa-solid ${Number(r.activo)===1?'fa-eye-slash':'fa-eye'}"></i></button><button class="btn small danger" onclick="deleteItem(${Number(r.id)})"><i class="fa-solid fa-trash"></i></button></div></td></tr>`).join('');document.getElementById('empty-state').style.display=rows.length?'none':'block';document.getElementById('visible-count').textContent=`${rows.length} registros`;}
function resetForm(){document.getElementById('catalog-form').reset();document.getElementById('item-id').value='0';document.getElementById('catalog-type').value=activeTab;}
function editItem(id){const r=(dataSets[activeTab]||[]).find(x=>Number(x.id)===Number(id));if(!r)return;document.getElementById('item-id').value=r.id;document.getElementById('catalog-type').value=activeTab;document.getElementById('item-program').value=r.programa;document.getElementById('item-stage').value=r.etapa;document.getElementById('item-name').value=r.nombre;document.getElementById('item-name').focus();window.scrollTo({top:0,behavior:'smooth'});}
async function toggleItem(id,activo){try{await post({action:'toggle',catalog_type:activeTab,id,activo});const r=dataSets[activeTab].find(x=>Number(x.id)===Number(id));if(r)r.activo=activo;render();toast(activo?'Elemento activado.':'Elemento desactivado.');}catch(e){toast(e.message,true)}}
async function deleteItem(id){if(!confirm('¿Eliminar definitivamente este elemento?'))return;try{await post({action:'delete',catalog_type:activeTab,id});dataSets[activeTab]=dataSets[activeTab].filter(x=>Number(x.id)!==Number(id));render();toast('Elemento eliminado.');}catch(e){toast(e.message,true)}}
document.getElementById('catalog-form').addEventListener('submit',async e=>{e.preventDefault();const fd=new FormData(e.currentTarget),id=Number(fd.get('id')||0),type=String(fd.get('catalog_type'));try{await post({action:id?'update':'create',catalog_type:type,id,programa:fd.get('programa'),etapa:fd.get('etapa'),nombre:fd.get('nombre')});toast('Elemento guardado.');setTimeout(()=>location.reload(),400);}catch(err){toast(err.message,true)}});
document.querySelectorAll('.tab').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.remove('active'));btn.classList.add('active');activeTab=btn.dataset.tab;document.getElementById('catalog-type').value=activeTab;render()}));['filter-program','filter-stage','filter-active','filter-search'].forEach(id=>document.getElementById(id).addEventListener(id==='filter-search'?'input':'change',render));
function downloadTemplate(){if(typeof XLSX==='undefined'){toast('No se cargó el componente XLSX.',true);return;}const data=[{PROGRAMA:'CRECER',ETAPA:'E-1',NOMBRE:'Ejemplo de elemento'},{PROGRAMA:'REDES',ETAPA:'E-3',NOMBRE:'Ejemplo de elemento'},{PROGRAMA:'ML_MONITOREO',ETAPA:'TODAS',NOMBRE:'Elemento aplicable a todas las etapas'}];const ws=XLSX.utils.json_to_sheet(data,{header:['PROGRAMA','ETAPA','NOMBRE']});ws['!cols']=[{wch:25},{wch:15},{wch:55}];const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'CATALOGO');XLSX.writeFile(wb,'plantilla_catalogo_monitoreo.xlsx');}
async function parseExcel(file){if(typeof XLSX==='undefined')throw new Error('No se cargó el lector XLSX.');const buf=await file.arrayBuffer();const wb=XLSX.read(buf,{type:'array'});const ws=wb.Sheets[wb.SheetNames[0]];if(!ws)throw new Error('El archivo no contiene hojas.');const rows=XLSX.utils.sheet_to_json(ws,{defval:'',raw:false});if(!rows.length)throw new Error('El archivo no contiene datos.');const keys=Object.keys(rows[0]).map(k=>String(k).trim().toUpperCase());for(const req of ['PROGRAMA','ETAPA','NOMBRE'])if(!keys.includes(req))throw new Error(`Falta la columna ${req}.`);return rows.map(r=>{const out={};Object.entries(r).forEach(([k,v])=>out[String(k).trim().toUpperCase()]=v);return out;});}
async function importExcel(file){const status=document.getElementById('import-status');try{status.textContent='Leyendo el archivo...';const rows=await parseExcel(file);const type=document.getElementById('import-type').value;let inserted=0,omitted=0,errors=[];for(let i=0;i<rows.length;i+=100){status.textContent=`Importando filas ${i+1}-${Math.min(i+100,rows.length)} de ${rows.length}...`;const res=await post({action:'import',catalog_type:type,rows:JSON.stringify(rows.slice(i,i+100))});inserted+=Number(res.inserted||0);omitted+=Number(res.omitted||0);errors=errors.concat(res.errors||[]);}status.innerHTML=`<b>Importación completada:</b> ${inserted} procesadas, ${omitted} omitidas.${errors.length?'<br>'+errors.slice(0,5).map(esc).join('<br>'):''}`;toast('Importación completada.');setTimeout(()=>location.reload(),1000);}catch(e){status.textContent=e.message;toast(e.message,true)}}
const input=document.getElementById('excel-file'),drop=document.getElementById('excel-drop');input.addEventListener('change',()=>{if(input.files[0])importExcel(input.files[0])});['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('drag')}));['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('drag')}));drop.addEventListener('drop',e=>{const f=e.dataTransfer.files[0];if(f)importExcel(f)});resetForm();render();
</script>
</body>
</html>