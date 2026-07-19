<?php
session_start();

// VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['tech_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';

$tech_id = $_SESSION['tech_id'];
$tech_name = $_SESSION['tech_name'];

// --- FILTROS INTELIGENTES ---
$search = $_GET['search'] ?? '';
$date_start = $_GET['date_start'] ?? '';
$date_end = $_GET['date_end'] ?? '';

// Construir condición base
$where_common = "tech_id = ?";
$params_common = [$tech_id];

if (!empty($search)) {
    $where_common .= " AND (child_name LIKE ? OR child_nbr LIKE ? OR slip_id LIKE ?)";
    $params_common[] = "%$search%";
    $params_common[] = "%$search%";
    $params_common[] = "%$search%";
}

// --- CONSULTA 1: PENDIENTES (Incluye Devueltas) ---
$sql_pending = "SELECT * FROM letters 
                WHERE $where_common 
                AND status IN ('PENDIENTE', 'ASSIGNED', 'RETURNED')
                ORDER BY 
                    CASE WHEN status = 'RETURNED' THEN 1 ELSE 2 END ASC,
                    STR_TO_DATE(due_date, '%d-%b-%Y') ASC";

$stmt = $pdo->prepare($sql_pending);
$stmt->execute($params_common);
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- CONSULTA 2: HISTORIAL (Completadas/Sync) ---
$where_history = $where_common . " AND status IN ('COMPLETADO', 'SYNCED')";
$params_history = $params_common;

if (!empty($date_start)) {
    $where_history .= " AND DATE(updated_at) >= ?";
    $params_history[] = $date_start;
}
if (!empty($date_end)) {
    $where_history .= " AND DATE(updated_at) <= ?";
    $params_history[] = $date_end;
}

$sql_history = "SELECT * FROM letters 
                WHERE $where_history 
                ORDER BY updated_at DESC";

$stmt = $pdo->prepare($sql_history);
$stmt->execute($params_history);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_pendientes = count($pendientes);
$total_historial = count($historial);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Panel Técnico - MagicLetter</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --color-primary: #46B094;
            --color-support: #34859B;
            --color-bg: #f4f7f6;
            --color-card: #ffffff;
            --color-text: #444;
            --color-error: #dc3545;
            --color-success: #28a745;
        }

        body { font-family: 'Segoe UI', sans-serif; background: var(--color-bg); margin: 0; padding-bottom: 80px; color: var(--color-text); }
        
        /* Navbar */
        .navbar { 
            background: var(--color-primary); color: white; padding: 15px 20px; 
            display: flex; justify-content: space-between; align-items: center; 
            position: sticky; top: 0; z-index: 100; box-shadow: 0 4px 10px rgba(0,0,0,0.15); 
        }
        .navbar h1 { margin: 0; font-size: 18px; font-weight: 700; }
        .logout { color: white; font-size: 18px; text-decoration: none; }

        .container { padding: 15px; max-width: 600px; margin: auto; }

        /* --- TABS (Modificado) --- */
        .tabs { 
            display: flex; background: white; 
            margin: 20px -15px 20px -15px; /* Margen superior aumentado a 20px */
            padding: 0 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); 
        }
        .tab-btn {
            flex: 1; text-align: center; padding: 15px; font-weight: 600; color: #888;
            border-bottom: 3px solid transparent; cursor: pointer; transition: 0.3s;
        }
        .tab-btn.active { color: var(--color-primary); border-bottom-color: var(--color-primary); }
        .tab-count { background: #eee; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 5px; }
        .tab-btn.active .tab-count { background: var(--color-primary); color: white; }

        /* --- FILTROS --- */
        .search-bar { position: relative; margin-bottom: 15px; }
        .search-input { 
            width: 100%; padding: 12px 15px 12px 40px; border-radius: 25px; border: 1px solid #ddd;
            font-size: 14px; box-sizing: border-box; outline: none; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .search-icon { position: absolute; left: 15px; top: 13px; color: #999; }
        
        .filter-toggle { 
            text-align: right; margin-bottom: 10px; font-size: 13px; color: var(--color-support); 
            cursor: pointer; font-weight: 600;
        }
        .filter-box { 
            background: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; 
            display: none; animation: slideDown 0.3s ease;
        }
        .date-input { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .btn-filter { width: 100%; background: var(--color-support); color: white; border: none; padding: 10px; border-radius: 6px; font-weight: bold; }

        /* --- TARJETAS --- */
        .card { background: var(--color-card); border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; position: relative; }
        
        /* Estilo Pendiente */
        .card-pending { border-left: 5px solid var(--color-primary); }
        .card-pending::before { 
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; 
            background: repeating-linear-gradient(45deg, var(--color-primary), var(--color-primary) 10px, #fff 10px, #fff 20px); 
        }

        /* Estilo Historial (Link) */
        a.card-history {
            display: block; text-decoration: none; color: inherit; /* Hacer que el enlace parezca una tarjeta normal */
            border-left: 5px solid #ccc; opacity: 0.9; transition: transform 0.2s, box-shadow 0.2s;
        }
        a.card-history:hover {
            transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }
        .card-history .slip-header { background: #f9f9f9; border-bottom: 1px solid #eee; }
        .card-history .child-name { color: #555; }
        
        /* Contenido Tarjeta */
        .slip-header { display: flex; justify-content: space-between; padding: 15px; }
        .slip-id { font-family: monospace; font-weight: bold; color: var(--color-support); background: #eefcf8; padding: 3px 8px; border-radius: 4px; }
        .slip-body { padding: 15px; }
        .child-name { margin: 0; font-size: 1.2em; font-weight: 700; color: #333; }
        .child-nbr { font-size: 0.9em; color: #888; display: block; margin-bottom: 10px; }
        
        .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.9em; }
        .info-label { color: #888; }
        .info-val { font-weight: 600; color: #444; }

        /* Badges */
        .badge-returned { 
            background: #ffe6e6; color: var(--color-error); padding: 8px; border-radius: 6px; 
            font-size: 12px; font-weight: bold; text-align: center; margin-bottom: 15px; 
            border: 1px dashed var(--color-error); cursor: pointer;
        }
        .stamp-sent {
            border: 2px solid var(--color-success); color: var(--color-success);
            padding: 5px 15px; border-radius: 4px; font-weight: 800; text-transform: uppercase;
            display: inline-block; transform: rotate(-5deg); margin-top: 10px;
            font-size: 14px; letter-spacing: 1px;
        }

        /* --- MODAL --- */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(2px); }
        .modal-content { background: white; margin: 20% auto; width: 85%; max-width: 350px; padding: 25px; border-radius: 12px; text-align: center; animation: popIn 0.3s ease; }
        .btn-close-modal { background: var(--color-error); color: white; border: none; padding: 10px 30px; border-radius: 20px; font-weight: bold; width: 100%; margin-top: 20px; }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        @keyframes slideDown { from { height: 0; opacity: 0; } to { height: auto; opacity: 1; } }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    </style>
</head>
<body>

    <div class="navbar">
        <div style="display:flex; align-items:center; gap:10px;">
            <div style="background:rgba(255,255,255,0.2); width:35px; height:35px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                <i class="fa-solid fa-user"></i>
            </div>
            <h1><?= htmlspecialchars(explode(' ', $tech_name)[0]) ?></h1>
        </div>
        <a href="logout.php" class="logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>

    <div class="tabs">
        <div class="tab-btn active" onclick="switchTab('pending')">
            Pendientes <span class="tab-count"><?= $total_pendientes ?></span>
        </div>
        <div class="tab-btn" onclick="switchTab('history')">
            Historial <span class="tab-count"><?= $total_historial ?></span>
        </div>
    </div>

    <div class="container">

        <form method="GET" action="panel.php">
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" name="search" class="search-input" placeholder="Buscar niño, ID o Slip..." value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <div class="filter-toggle" onclick="toggleFilters()">
                <i class="fa-solid fa-filter"></i> Filtros Avanzados <?= ($date_start || $date_end) ? '(Activos)' : '' ?>
            </div>

            <div id="filterBox" class="filter-box" style="<?= ($date_start || $date_end) ? 'display:block;' : '' ?>">
                <label style="font-size:12px; font-weight:bold; color:#666;">Fecha (Historial):</label>
                <div style="display:flex; gap:10px; margin-top:5px;">
                    <input type="date" name="date_start" class="date-input" value="<?= $date_start ?>">
                    <input type="date" name="date_end" class="date-input" value="<?= $date_end ?>">
                </div>
                <button type="submit" class="btn-filter">Aplicar Filtros</button>
                <?php if($search || $date_start): ?>
                    <a href="panel.php" style="display:block; text-align:center; margin-top:10px; color:#888; font-size:12px; text-decoration:none;">Limpiar todo</a>
                <?php endif; ?>
            </div>
        </form>

        <div id="tab-pending" class="tab-content active">
            <?php if(empty($pendientes)): ?>
                <div style="text-align:center; padding:50px 20px; color:#aaa;">
                    <i class="fa-solid fa-clipboard-check" style="font-size:50px; margin-bottom:15px;"></i>
                    <p>¡Estás al día! No tienes cartas pendientes.</p>
                </div>
            <?php else: ?>
                <?php foreach($pendientes as $c): ?>
                    <div class="card card-pending">
                        <div class="slip-header">
                            <span style="font-weight:800; color:var(--color-primary); font-size:12px;">ASIGNACIÓN</span>
                            <span class="slip-id">#<?= $c['slip_id'] ?></span>
                        </div>
                        <div class="slip-body">
                            <?php if($c['status'] == 'RETURNED'): ?>
                                <div class="badge-returned" onclick="verMotivo('<?= htmlspecialchars($c['return_reason'], ENT_QUOTES) ?>')">
                                    <i class="fa-solid fa-triangle-exclamation"></i> DEVUELTA - TOCAR PARA VER MOTIVO
                                </div>
                            <?php endif; ?>

                            <h3 class="child-name"><?= htmlspecialchars($c['child_name']) ?></h3>
                            <span class="child-nbr"><?= htmlspecialchars($c['child_nbr'] ?: $c['child_code']) ?></span>

                            <div class="info-row">
                                <span class="info-label">Comunidad:</span>
                                <span class="info-val"><?= htmlspecialchars($c['village']) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Vence:</span>
                                <span class="info-val" style="color:#d9534f;"><?= htmlspecialchars($c['due_date']) ?></span>
                            </div>

                            <div style="margin-top:15px; text-align:center;">
                                <svg class="barcode"
                                     jsbarcode-value="<?= htmlspecialchars($c['slip_id']) ?>"
                                     jsbarcode-format="CODE128"
                                     jsbarcode-width="2" jsbarcode-height="40" jsbarcode-fontSize="12">
                                </svg>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="tab-history" class="tab-content">
            <?php if(empty($historial)): ?>
                <div style="text-align:center; padding:50px 20px; color:#aaa;">
                    <i class="fa-solid fa-box-open" style="font-size:50px; margin-bottom:15px;"></i>
                    <p>No hay cartas en el historial con estos filtros.</p>
                </div>
            <?php else: ?>
                <?php foreach($historial as $h): ?>
                    <a href="ver_carta.php?id=<?= $h['id'] ?>" class="card card-history">
                        <div class="slip-header">
                            <span style="font-weight:700; color:#888; font-size:12px;">RESPALDO DE ENVÍO</span>
                            <span class="slip-id" style="background:#eee; color:#666;">#<?= $h['slip_id'] ?></span>
                        </div>
                        <div class="slip-body">
                            <h3 class="child-name" style="font-size:1.1em;"><?= htmlspecialchars($h['child_name']) ?></h3>
                            <span class="child-nbr"><?= htmlspecialchars($h['village']) ?></span>

                            <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:11px; color:#888;">Fecha Entrega:</div>
                                    <div style="font-weight:bold; color:#444;">
                                        <?= date('d/m/Y H:i', strtotime($h['updated_at'])) ?>
                                    </div>
                                </div>
                                <div class="stamp-sent">
                                    <i class="fa-solid fa-eye"></i> VER
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <div id="modalMotivo" class="modal">
        <div class="modal-content">
            <div style="font-size: 40px; color: #dc3545; margin-bottom: 10px;">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h3 style="margin:0; color:#333;">Carta Devuelta</h3>
            <p style="color:#888; font-size:14px;">Motivo indicado por admin:</p>
            <div id="texto_motivo" style="background:#f9f9f9; padding:15px; border-radius:8px; color:#555; margin-bottom:20px;"></div>
            <button onclick="cerrarModal()" class="btn-close-modal">Entendido</button>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            JsBarcode(".barcode").init();
        });

        // Tabs Logic
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            event.currentTarget.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById('tab-' + tabName).classList.add('active');
        }

        // Filters Logic
        function toggleFilters() {
            var box = document.getElementById('filterBox');
            box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
        }

        // Modal Logic
        const modal = document.getElementById('modalMotivo');
        const textoMotivo = document.getElementById('texto_motivo');
        function verMotivo(razon) {
            textoMotivo.innerText = razon;
            modal.style.display = "block";
        }
        function cerrarModal() { modal.style.display = "none"; }
        window.onclick = function(e) { if(e.target == modal) cerrarModal(); }
    </script>

</body>
</html>