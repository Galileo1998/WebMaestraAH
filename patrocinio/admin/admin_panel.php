<?php
session_start();

// 1. CORRECCIÓN DE ZONA HORARIA Y FECHA (CRUCIAL)
date_default_timezone_set('America/Tegucigalpa');

// VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';

// --- LÓGICA DE BORRADO SEGURO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_letter') {
    $id_borrar = $_POST['delete_id'];
    $password_confirm = $_POST['password_confirm'];
    $admin_id = $_SESSION['usuario_id'];

    $stmt_user = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
    $stmt_user->execute([$admin_id]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($user && $password_confirm === $user['password']) {
        $pdo->prepare("DELETE FROM letter_attachments WHERE letter_id = ?")->execute([$id_borrar]);
        $stmt_del = $pdo->prepare("DELETE FROM letters WHERE id = ?");
        
        if ($stmt_del->execute([$id_borrar])) {
            echo "<script>alert('Carta eliminada correctamente.'); window.location.href='admin_panel.php';</script>";
        } else {
            echo "<script>alert('Error al intentar borrar la carta de la base de datos.');</script>";
        }
    } else {
        echo "<script>alert('ERROR: La contraseña es incorrecta.'); window.location.href='admin_panel.php';</script>";
    }
}

// --- DATOS MAESTROS ---
$tecnicos_lista = $pdo->query("SELECT id, full_name FROM technicians ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$estados_lista  = ['PENDIENTE', 'ASSIGNED', 'COMPLETADO', 'SYNCED', 'RETURNED']; 
$tipos_lista    = ['Child Welcome Letter', 'Child Reply Letter', 'Thank You Letter'];

// --- RECUPERAR FILTROS ---
$f_upload_start = $_GET['upload_start'] ?? '';
$f_upload_end   = $_GET['upload_end'] ?? '';
$f_tech_ids     = $_GET['tech_ids'] ?? []; 
$f_statuses     = $_GET['statuses'] ?? [];
$f_type         = $_GET['letter_type'] ?? ''; 
$f_urgency      = $_GET['urgency'] ?? '';     

$hay_filtros = !empty(array_filter($_GET));

// --- CONSTRUCCIÓN SQL ---
$where_clauses = ["1=1"]; 
$params = [];

// 1. Filtro Urgencia (Usando STR_TO_DATE para comparar fechas reales)
if ($f_urgency === 'expired') {
    $where_clauses[] = "STR_TO_DATE(l.technician_due_date, '%d-%b-%Y') < CURDATE()";
    $where_clauses[] = "l.status IN ('PENDIENTE', 'ASSIGNED')"; 
} elseif ($f_urgency === 'risk') {
    $where_clauses[] = "STR_TO_DATE(l.technician_due_date, '%d-%b-%Y') BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
    $where_clauses[] = "l.status IN ('PENDIENTE', 'ASSIGNED')";
}

// 2. Filtro Tipo
if (!empty($f_type)) { 
    $where_clauses[] = "l.letter_type LIKE ?"; 
    $params[] = "%$f_type%"; 
}

// 3. Filtro Fechas Carga
if (!empty($f_upload_start)) { $where_clauses[] = "DATE(l.created_at) >= ?"; $params[] = $f_upload_start; }
if (!empty($f_upload_end)) { $where_clauses[] = "DATE(l.created_at) <= ?"; $params[] = $f_upload_end; }

// 4. Filtro Técnicos
if (!empty($f_tech_ids)) {
    $tech_conditions = [];
    $ids_limpios = [];
    $incluir_sin_asignar = false;
    foreach ($f_tech_ids as $val) {
        if ($val === 'unassigned') $incluir_sin_asignar = true;
        else $ids_limpios[] = $val;
    }
    if (!empty($ids_limpios)) {
        $placeholders = implode(',', array_fill(0, count($ids_limpios), '?'));
        $tech_conditions[] = "l.tech_id IN ($placeholders)";
        foreach ($ids_limpios as $id) $params[] = $id;
    }
    if ($incluir_sin_asignar) {
        $tech_conditions[] = "(l.tech_id IS NULL OR l.tech_id = 0)";
    }
    if (!empty($tech_conditions)) {
        $where_clauses[] = "(" . implode(' OR ', $tech_conditions) . ")";
    }
}

// 5. Filtro Estados
if (!empty($f_statuses)) {
    $placeholders = implode(',', array_fill(0, count($f_statuses), '?'));
    $where_clauses[] = "l.status IN ($placeholders)";
    foreach ($f_statuses as $st) $params[] = $st;
}

$sql_where = implode(" AND ", $where_clauses);

$sql = "SELECT l.*, t.full_name as tech_name 
        FROM letters l 
        LEFT JOIN technicians t ON l.tech_id = t.id 
        WHERE $sql_where
        ORDER BY l.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cartas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contadores Totales (Globales)
$stats_res = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status IN ('PENDIENTE','ASSIGNED') THEN 1 ELSE 0 END) as pendientes,
    SUM(CASE WHEN status IN ('COMPLETADO','SYNCED') THEN 1 ELSE 0 END) as completadas
    FROM letters")->fetch(PDO::FETCH_ASSOC);

$total = $stats_res['total'];
$pendientes = $stats_res['pendientes'];
$completadas = $stats_res['completadas'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Administración</title>
    
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

    <style>
        :root {
            --color-primary: #46B094;
            --color-support: #34859B;
            --color-accent: #B4D6E0;
            --color-bg: #f4f7f6;
            --color-danger: #dc3545;
            --color-warning: #f0ad4e;
            --color-success: #28a745;
        }

        body { font-family: 'Segoe UI', sans-serif; background: var(--color-bg); padding: 20px; color: #444; }
        .container { max-width: 1550px; margin: auto; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 15px; border-radius: 12px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border-bottom: 4px solid var(--color-accent); }
        .stat-number { font-size: 2em; font-weight: 800; color: var(--color-support); }
        .stat-label { font-weight: 600; color: #888; text-transform: uppercase; font-size: 0.8em; }
        .stat-card.pending { border-color: #f0ad4e; }
        .stat-card.done { border-color: var(--color-primary); }

        /* Main Card */
        .main-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border-top: 5px solid var(--color-primary); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        
        /* Filtros */
        .filter-panel { background: #f1f4f6; border: 1px solid #dce1e6; padding: 20px; border-radius: 10px; margin-bottom: 25px; }
        .filter-title { font-size: 14px; font-weight: 700; color: var(--color-support); margin-bottom: 15px; display:flex; align-items:center; gap:8px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; align-items: start; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
        .input-control { padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 13px; color: #555; width: 100%; box-sizing: border-box; background: white; }
        select[multiple] { height: 100px; padding: 5px; overflow-y: auto; background-image: linear-gradient(to bottom, #fff 0%, #f9f9f9 100%); }
        .filter-actions { display: flex; gap: 10px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e0e0; justify-content: flex-end; }
        .btn-filter { background: var(--color-primary); color: white; border: none; padding: 10px 30px; border-radius: 6px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .btn-reset { background: white; border: 1px solid #d9534f; color: #d9534f; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        
        /* Botones */
        .menu-btn { text-decoration: none; padding: 8px 16px; border-radius: 5px; font-weight: bold; font-size: 13px; display: inline-block; margin-left: 5px; }
        .btn-blue { background: var(--color-primary); color: white; }
        .btn-outline { border: 1px solid var(--color-primary); color: var(--color-primary); background: white; }
        
        table.dataTable thead th { background-color: var(--color-accent); color: var(--color-support); font-weight: 700; border-bottom: 2px solid white; padding: 12px; }
        
        /* BADGES */
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 10px; font-weight: 800; display: inline-block; text-transform: uppercase;}
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-done { background: #d4edda; color: #155724; }
        .badge-return { background: #f8d7da; color: #721c24; }

        /* BADGES TIPOS */
        .type-welcome { background: #e3f2fd; color: #0d47a1; }
        .type-reply { background: #e8f5e9; color: #1b5e20; }
        .type-thank { background: #f3e5f5; color: #4a148c; }
        .type-unknown { background: #eee; color: #666; }

        /* CUENTA REGRESIVA */
        .countdown { font-weight: bold; font-size: 12px; }
        .cd-red { color: var(--color-danger); }
        .cd-orange { color: var(--color-warning); }
        .cd-green { color: var(--color-success); }

        /* Acciones */
        .btn-icon { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: transform 0.2s; text-decoration: none; font-size: 13px; }
        .btn-icon:hover { transform: scale(1.15); }
        .btn-delete { background: #ffe6e6; color: var(--color-danger); }
        .btn-delete:hover { background: var(--color-danger); color: white; }

        /* Modales */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(52, 133, 155, 0.6); backdrop-filter: blur(3px); }
        .modal-content { background-color: transparent; margin: 5% auto; width: 380px; position: relative; animation: slideDown 0.4s ease; }
        .delete-modal-content { background: white; padding: 25px; border-radius: 12px; width: 400px; margin: 15% auto; border-left: 5px solid var(--color-danger); box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: slideDown 0.3s ease; position: relative; }
        .delete-input { width: 100%; padding: 10px; margin: 15px 0; border: 1px solid #ccc; border-radius: 5px; }
        .btn-confirm-delete { background: var(--color-danger); color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; width: 100%; font-weight: bold; }
        .close-modal { position: absolute; right: -40px; top: 0; color: white; font-size: 30px; cursor: pointer; font-weight: bold; }
        .slip-replica { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 15px 40px rgba(0,0,0,0.25); }
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h1 style="color:var(--color-primary); margin:0; font-weight:800;">Panel de Administración</h1>
        <small style="color:#666; font-size:12px;">Hoy: <?= date('d-M-Y') ?></small>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $total ?></div>
            <div class="stat-label">Total en Sistema</div>
        </div>
        <div class="stat-card pending">
            <div class="stat-number" style="color:#f0ad4e;"><?= $pendientes ?></div>
            <div class="stat-label">En Proceso</div>
        </div>
        <div class="stat-card done">
            <div class="stat-number" style="color:#46B094;"><?= $completadas ?></div>
            <div class="stat-label">Finalizadas</div>
        </div>
    </div>

    <div class="main-card">
        
        <div class="header">
            <h3 style="margin:0;">📜 Maestro de Cartas</h3>
            <div>
                <a href="tecnicos.php" class="menu-btn btn-outline"><i class="fa-solid fa-users-gear"></i> Técnicos</a>
                <a href="asignaciones.php" class="menu-btn btn-blue"><i class="fa-solid fa-clipboard-list"></i> Asignar</a>
                <a href="revisar_carga.php" class="menu-btn btn-blue" style="background:var(--color-support);"><i class="fa-solid fa-cloud-arrow-up"></i> Cargar PDF</a>
            </div>
        </div>

        <form class="filter-panel" method="GET">
            <div class="filter-title"><i class="fa-solid fa-filter"></i> Filtros de Búsqueda</div>
            <div class="filter-grid">
                
                <div class="filter-group">
                    <span class="filter-label"><i class="fa-solid fa-triangle-exclamation"></i> Urgencia</span>
                    <select name="urgency" class="input-control" style="font-weight:bold;">
                        <option value="">-- Todas --</option>
                        <option value="expired" <?= $f_urgency == 'expired' ? 'selected' : '' ?> style="color:#dc3545;">🚨 Vencidas</option>
                        <option value="risk" <?= $f_urgency == 'risk' ? 'selected' : '' ?> style="color:#fd7e14;">⚠️ Próximas (3 días)</option>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-label"><i class="fa-regular fa-file-lines"></i> Tipo de Carta</span>
                    <select name="letter_type" class="input-control">
                        <option value="">-- Todos los tipos --</option>
                        <?php foreach($tipos_lista as $t): ?>
                            <option value="<?= $t ?>" <?= $f_type == $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-label"><i class="fa-solid fa-users"></i> Técnicos</span>
                    <select name="tech_ids[]" class="input-control" multiple>
                        <option value="unassigned" <?= (in_array('unassigned', $f_tech_ids)) ? 'selected' : '' ?> style="color:#d9534f; font-weight:bold;">🚫 Sin Asignar</option>
                        <?php foreach($tecnicos_lista as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= (in_array($t['id'], $f_tech_ids)) ? 'selected' : '' ?>><?= htmlspecialchars($t['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-label"><i class="fa-solid fa-list-check"></i> Estado</span>
                    <select name="statuses[]" class="input-control" multiple>
                        <?php foreach($estados_lista as $st): ?>
                            <option value="<?= $st ?>" <?= (in_array($st, $f_statuses)) ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <span class="filter-label"><i class="fa-regular fa-calendar"></i> Fecha Carga</span>
                    <div style="display:flex; gap:5px; align-items:center;">
                        <input type="date" name="upload_start" class="input-control" value="<?= $f_upload_start ?>">
                        <input type="date" name="upload_end" class="input-control" value="<?= $f_upload_end ?>">
                    </div>
                </div>

            </div>
            <div class="filter-actions">
                <a href="admin_panel.php" class="btn-reset <?= !$hay_filtros ? 'disabled' : '' ?>"><i class="fa-solid fa-trash-can"></i> Borrar</a>
                <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass"></i> Aplicar</button>
            </div>
        </form>

        <table id="tablaCartas" class="display" style="width:100%">
            <thead>
                <tr>
                    <th>Slip ID</th>
                    <th>Tipo</th>
                    <th>Niño</th>
                    <th>Comunidad</th>
                    <th>Técnico</th>
                    <th>Estado</th>
                    <th>Vencimiento</th> <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // FECHA HOY (00:00:00) PARA COMPARACIÓN EXACTA
                $today = new DateTime('today');

                foreach($cartas as $c): 
                    // 1. Color Badge Tipo
                    $typeClass = 'type-unknown';
                    $typeShort = 'Unknown';
                    if (stripos($c['letter_type'], 'Welcome') !== false) { $typeClass = 'type-welcome'; $typeShort = 'Welcome'; }
                    elseif (stripos($c['letter_type'], 'Reply') !== false) { $typeClass = 'type-reply'; $typeShort = 'Reply'; }
                    elseif (stripos($c['letter_type'], 'Thank') !== false) { $typeClass = 'type-thank'; $typeShort = 'Thank You'; }

                    // 2. Color Badge Status
                    $statusClass = 'badge-pending';
                    if($c['status'] == 'COMPLETADO' || $c['status'] == 'SYNCED') $statusClass = 'badge-done';
                    if($c['status'] == 'RETURNED') $statusClass = 'badge-return';

                    // 3. Lógica Cuenta Regresiva (Igual que Dashboard)
                    $deadline = DateTime::createFromFormat('d-M-Y', $c['technician_due_date']);
                    
                    $countdownText = "-";
                    $countdownClass = "";
                    $days = 0;

                    if ($deadline) {
                        $deadline->setTime(0,0,0); // Forzar medianoche
                        $diff = $today->diff($deadline);
                        $days = (int)$diff->format('%r%a'); // Entero con signo
                    }

                    if (in_array($c['status'], ['PENDIENTE', 'ASSIGNED'])) {
                        if ($days < 0) { 
                            $countdownText = "⚠️ Vencida (" . abs($days) . "d)"; 
                            $countdownClass = "cd-red"; 
                        } elseif ($days == 0) { 
                            $countdownText = "🔥 ¡HOY!"; 
                            $countdownClass = "cd-red"; 
                        } elseif ($days <= 3) { 
                            $countdownText = "⏳ " . $days . " días"; 
                            $countdownClass = "cd-orange"; 
                        } else { 
                            $countdownText = $days . " días"; 
                            $countdownClass = "cd-green"; 
                        }
                    } elseif (in_array($c['status'], ['COMPLETADO', 'SYNCED'])) {
                        $countdownText = "✔️ Entregada";
                        $countdownClass = "cd-green";
                    }
                ?>
                <tr>
                    <td><strong>#<?= htmlspecialchars($c['slip_id']) ?></strong></td>
                    
                    <td><span class="badge <?= $typeClass ?>"><?= $typeShort ?></span></td>

                    <td><?= htmlspecialchars($c['child_name']) ?></td>
                    <td><?= htmlspecialchars($c['village']) ?> <small style="color:#999;">(<?= $c['community_id'] ?>)</small></td>
                    <td style="color:#666;"><?= $c['tech_name'] ? '👤 '.$c['tech_name'] : '<span style="color:#d9534f; font-weight:bold; font-size:11px;">🚫 SIN ASIGNAR</span>' ?></td>
                    
                    <td><span class="badge <?= $statusClass ?>"><?= $c['status'] ?></span></td>

                    <td>
                        <div style="font-size:11px; font-weight:bold; color:#555;"><?= $c['technician_due_date'] ?></div>
                        <div class="countdown <?= $countdownClass ?>"><?= $countdownText ?></div>
                    </td>

                    <td>
                        <div style="display:flex; gap:8px;">
                            <button class="btn-icon" style="background:var(--color-accent); color:var(--color-support);" onclick='verTarjeta(<?= json_encode($c) ?>)' title="Ver Ticket">🎫</button>

                            <?php if(in_array($c['status'], ['COMPLETADO', 'SYNCED', 'EN_REVISION', 'RETURNED'])): ?>
                                <a href="ver_carta.php?id=<?= $c['id'] ?>" class="btn-icon" style="background:var(--color-primary); color:white;" title="Revisar Carta">👁️</a>
                            <?php endif; ?>

                            <button class="btn-icon btn-delete" onclick="borrarCarta(<?= $c['id'] ?>)" title="Eliminar Carta">🗑️</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="modalTarjeta" class="modal">
    <span class="close-modal" onclick="cerrarModal()">&times;</span>
    <div class="modal-content">
        <div class="slip-replica">
            <div class="slip-header">
                <div class="slip-brand">MCS</div>
                <div style="background:#f4f7f6; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:12px; color:#555;">ID: <span id="m_slip_display"></span></div>
            </div>
            <div style="padding:20px;">
                <h3 class="child-name" id="m_child_name" style="margin:0 0 5px 0;"></h3>
                <span id="m_child_nbr" style="color:#888; font-size:0.9em; display:block; margin-bottom:15px;"></span>
                <div style="background:#f8f9fa; padding:12px; border-radius:6px; font-size:0.9em;">
                    <div style="display:flex; margin-bottom:5px;"><strong style="width:100px; color:#888;">Comunidad:</strong> <span id="m_village"></span></div>
                    <div style="display:flex; margin-bottom:5px;"><strong style="width:100px; color:#888;">Tipo:</strong> <span id="m_type" style="color:var(--color-support); font-weight:bold;"></span></div>
                    <div style="display:flex;"><strong style="width:100px; color:#888;">Técnico:</strong> <span id="m_tech" style="color:var(--color-primary); font-weight:bold;"></span></div>
                </div>
            </div>
            <div style="padding:15px; text-align:center; border-top:1px solid #eee;">
                <svg id="barcode"></svg>
                <div style="margin-top:10px;">
                    <span style="background:#fff3cd; color:#856404; padding:4px 12px; border-radius:12px; font-size:11px; font-weight:bold;">📅 Vence: <span id="m_date"></span></span>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modalBorrar" class="modal">
    <div class="delete-modal-content">
        <span onclick="cerrarModalBorrar()" style="position:absolute; right:15px; top:10px; cursor:pointer; font-size:20px;">&times;</span>
        <h3 style="margin-top:0; color:var(--color-danger);"><i class="fa-solid fa-triangle-exclamation"></i> Eliminar Carta</h3>
        <p style="font-size:13px; color:#555; line-height:1.5;">Esta acción es irreversible. Se eliminará la carta y todos sus archivos adjuntos.</p>
        
        <form method="POST">
            <input type="hidden" name="action" value="delete_letter">
            <input type="hidden" id="delete_id" name="delete_id" value="">
            
            <label style="font-size:12px; font-weight:bold;">Confirma tu contraseña de Admin:</label>
            <input type="password" name="password_confirm" class="delete-input" placeholder="Tu contraseña..." required>
            
            <div style="display:flex; gap:10px;">
                <button type="button" onclick="cerrarModalBorrar()" style="flex:1; padding:10px; border:1px solid #ccc; background:white; border-radius:5px; cursor:pointer;">Cancelar</button>
                <button type="submit" class="btn-confirm-delete" style="flex:1;">Confirmar Borrado</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<script>
    $(document).ready(function() {
        $('#tablaCartas').DataTable({
            dom: 'Bfrtip',
            buttons: [ 'excelHtml5', 'csvHtml5' ],
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
            order: [[ 0, "desc" ]] // Ordenar por ID descendente
        });
        
        $('.dt-button').css({
            'background': '#34859B', 'color': 'white', 'border': 'none', 
            'border-radius': '5px', 'padding':'6px 12px', 'margin-right':'5px', 'font-size':'13px'
        });
    });

    var modal = document.getElementById("modalTarjeta");
    function verTarjeta(data) {
        document.getElementById('m_slip_display').innerText = data.slip_id;
        document.getElementById('m_child_name').innerText = data.child_name;
        document.getElementById('m_child_nbr').innerText = "N° " + data.child_code;
        document.getElementById('m_village').innerText = data.village;
        document.getElementById('m_type').innerText = data.letter_type; 
        document.getElementById('m_date').innerText = data.technician_due_date; 
        document.getElementById('m_tech').innerText = data.tech_name ? data.tech_name : "Sin asignar";
        JsBarcode("#barcode", data.slip_id, { format: "CODE128", lineColor: "#333", width: 2, height: 40, displayValue: true, fontSize: 14 });
        modal.style.display = "block";
    }
    function cerrarModal() { modal.style.display = "none"; }

    var modalDel = document.getElementById("modalBorrar");
    function borrarCarta(id) {
        document.getElementById('delete_id').value = id;
        modalDel.style.display = "block";
    }
    function cerrarModalBorrar() {
        modalDel.style.display = "none";
    }

    window.onclick = function(event) { 
        if (event.target == modal) cerrarModal();
        if (event.target == modalDel) cerrarModalBorrar();
    }
</script>

</body>
</html>