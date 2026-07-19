<?php
session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$auth->checkAccess(basename($_SERVER['PHP_SELF']), $db);

function addColIfNotExists($db, $table, $col, $def) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col));
        if ($stmt && $stmt->rowCount() == 0) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    } catch(Exception $e) { }
}

// ========================================================
// 1. AUTO-MIGRACIÓN: TABLA DE ZONAS (18 BASES EXACTAS)
// ========================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_bases_geograficas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        municipio VARCHAR(100) NOT NULL,
        nombre_base VARCHAR(150) NOT NULL,
        identidad_tecnico VARCHAR(50) NULL,
        INDEX (identidad_tecnico)
    )");
    
    // Adaptar tabla si venimos de la versión anterior
    addColIfNotExists($db, 'ah_bases_geograficas', 'identidad_tecnico', 'VARCHAR(50) NULL');

    // Limpieza de datos genéricos si quedaron de la versión anterior
    $check_old = $db->query("SELECT COUNT(*) FROM ah_bases_geograficas WHERE nombre_base LIKE '%Centro y Alrededores%' OR nombre_base LIKE 'Base 1%'")->fetchColumn();
    if ($check_old > 0) { $db->exec("TRUNCATE TABLE ah_bases_geograficas"); }

    // Cargar las 18 bases exactas
    $check_bases = $db->query("SELECT COUNT(*) FROM ah_bases_geograficas")->fetchColumn();
    if ($check_bases == 0) {
        $bases_reales = [
            ['Lepaterique', 'Lepaterique Centro'], ['Lepaterique', 'Mulhuaca'], ['Lepaterique', 'Turturupe'], 
            ['Lepaterique', 'La Estancia'], ['Lepaterique', 'Culguaque'], ['Lepaterique', 'Oropule'], 
            ['Lepaterique', 'Ocote Hueco'], ['Lepaterique', 'La Brea'], ['Lepaterique', 'El Naranjo'],
            ['Reitoca', 'Reitoca'],
            ['La Venta', 'El Llano'],
            ['Curarén', 'San Marcos'], ['Curarén', 'Emituca'], ['Curarén', 'Malagua'], ['Curarén', 'El Chaparral'],
            ['Alubarén', 'Alubarén'], ['Alubarén', 'El Hatillo'], ['Alubarén', 'Los Tablones']
        ];
        $stmt_in = $db->prepare("INSERT INTO ah_bases_geograficas (municipio, nombre_base) VALUES (?, ?)");
        foreach ($bases_reales as $b) { $stmt_in->execute($b); }
    }
} catch(Exception $e) {}

$csrf_token = Auth::generateCSRF();
$msg = "";

// ========================================================
// 2. PROCESAR ASIGNACIONES
// ========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'asignar_bases') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');
    try {
        $db->beginTransaction();
        $stmt_base = $db->prepare("UPDATE ah_bases_geograficas SET identidad_tecnico = ? WHERE id = ?");
        
        foreach ($_POST['base_id'] as $i => $id_b) {
            $id_tec = !empty($_POST['tecnico_base'][$i]) ? $_POST['tecnico_base'][$i] : null;
            $stmt_base->execute([$id_tec, $id_b]);
        }
        $db->commit();
        $msg = "<div class='alert success'><i class='fa-solid fa-map-location-dot'></i> Asignación territorial guardada con éxito.</div>";
    } catch(Exception $e) {
        $db->rollBack();
        $msg = "<div class='alert error'>Error al asignar bases: " . $e->getMessage() . "</div>";
    }
}

// ========================================================
// 3. OBTENER DATOS PARA LAS VISTAS
// ========================================================
$tecnicos = $db->query("SELECT identidad, nombre FROM ah_tecnicos WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

$bases_raw = $db->query("SELECT b.*, t.nombre as nombre_tecnico FROM ah_bases_geograficas b LEFT JOIN ah_tecnicos t ON b.identidad_tecnico = t.identidad ORDER BY b.id ASC")->fetchAll(PDO::FETCH_ASSOC);

$bases_agrupadas = [];
$conteo_tecnicos = [];

foreach ($bases_raw as $b) {
    $bases_agrupadas[$b['municipio']][] = $b;
    if (!empty($b['identidad_tecnico'])) {
        $conteo_tecnicos[$b['identidad_tecnico']] = ($conteo_tecnicos[$b['identidad_tecnico']] ?? 0) + 1;
    }
}

$colores_muni = [
    'Lepaterique' => '#0284c7', // Azul
    'Curarén' => '#b45309',    // Naranja
    'Reitoca' => '#16a34a',    // Verde
    'Alubarén' => '#7c3aed',   // Morado
    'La Venta' => '#be123c'    // Rojo
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignación de Bases | AH Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        .main { flex-grow: 1; padding: 40px; overflow-y: auto; max-width: 1400px; margin: 0 auto; box-sizing: border-box;}
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;}
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;}

        .card { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 30px; border: 1px solid var(--border);}
        
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-size:0.95rem; }
        .btn-save:hover { background: #2c7285; transform: translateY(-1px);}

        .grid-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 30px; align-items: start; }
        
        /* Lista de Técnicos */
        .tech-list { display: flex; flex-direction: column; gap: 10px; }
        .tech-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; transition: 0.2s; }
        .tech-item:hover { border-color: #cbd5e1; background: white; }
        .tech-name { font-weight: 600; color: #334155; font-size: 0.95rem; display: flex; align-items: center; gap: 10px;}
        .tech-avatar { width: 30px; height: 30px; background: #e0f2fe; color: #0284c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: bold;}
        .base-count { background: #fefce8; color: #a16207; border: 1px solid #fde047; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; }
        .base-count.zero { background: #f1f5f9; color: #94a3b8; border-color: #e2e8f0; }

        /* Tabla de Zonas */
        .styled-table { width: 100%; border-collapse: collapse; }
        .styled-table th { background: #f1f5f9; padding: 12px 15px; text-align: left; color: #475569; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;}
        .styled-table td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle;}
        
        .table-input { border: 1px solid transparent; background: transparent; padding: 8px; width: 100%; box-sizing: border-box; font-family: inherit; font-size: 0.9rem; border-radius: 6px; transition: 0.2s;}
        .table-input:hover { border-color: #cbd5e1; background: white;}
        .table-input:focus { border-color: var(--ah-primary); background: white; outline: none;}

        .muni-header { background: #f8fafc; padding: 12px 15px; font-weight: 800; font-size: 1.05rem; color: #0f172a; border-top: 3px solid var(--border); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; position: sticky; top: 0; z-index: 5;}
        
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="main">
        <h1 style="margin: 0 0 5px 0; font-size: 2.2rem; color: #0f172a;"><i class="fa-solid fa-map-location-dot" style="color: var(--ah-primary);"></i> Mapeo Territorial</h1>
        <p style="color: #64748b; margin: 0 0 30px 0; font-size: 1.05rem;">Asigna a los técnicos responsables de forma directa a las 18 bases operativas.</p>

        <?php echo $msg; ?>

        <div class="grid-layout">
            <div class="card">
                <h2 style="margin-top:0; font-size:1.2rem; display:flex; justify-content:space-between; align-items:center;">
                    <span><i class="fa-solid fa-users" style="color: var(--ah-primary);"></i> Equipo Técnico</span>
                    <span style="font-size:0.8rem; background:#f1f5f9; padding:4px 8px; border-radius:20px; color:#475569;"><?php echo count($tecnicos); ?></span>
                </h2>
                <div class="tech-list">
                    <?php foreach($tecnicos as $t): 
                        $asignadas = $conteo_tecnicos[$t['identidad']] ?? 0;
                        $badge_class = ($asignadas > 0) ? 'base-count' : 'base-count zero';
                        $iniciales = strtoupper(substr($t['nombre'], 0, 2));
                    ?>
                        <div class="tech-item">
                            <div class="tech-name">
                                <div class="tech-avatar"><?php echo $iniciales; ?></div>
                                <?php echo htmlspecialchars(explode(' ', trim($t['nombre']))[0] . ' ' . (explode(' ', trim($t['nombre']))[1] ?? '')); ?>
                            </div>
                            <span class="<?php echo $badge_class; ?>">
                                <?php echo $asignadas; ?> <?php echo ($asignadas == 1) ? 'Base' : 'Bases'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card" style="padding: 0; overflow:hidden;">
                <div style="padding: 20px 25px; background: white; display:flex; justify-content:space-between; align-items:center;">
                    <h2 style="margin:0; font-size:1.2rem;"><i class="fa-solid fa-map" style="color:var(--ah-primary);"></i> Asignación de Zonas (18 Bases)</h2>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="asignar_bases">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div style="max-height: 75vh; overflow-y:auto;">
                        <table class="styled-table">
                            <?php foreach($bases_agrupadas as $muni => $bases_muni): 
                                $color_m = $colores_muni[$muni] ?? '#334155';
                            ?>
                                <tr>
                                    <td colspan="3" class="muni-header" style="border-top-color: <?php echo $color_m; ?>;">
                                        <i class="fa-solid fa-location-dot" style="color: <?php echo $color_m; ?>;"></i> 
                                        <?php echo htmlspecialchars($muni); ?> 
                                    </td>
                                </tr>
                                
                                <?php foreach($bases_muni as $b): ?>
                                    <tr>
                                        <td style="width: 5%; text-align:center;">
                                            <input type="hidden" name="base_id[]" value="<?php echo $b['id']; ?>">
                                            <i class="fa-solid fa-thumbtack" style="color: #cbd5e1; font-size: 0.9rem;"></i>
                                        </td>
                                        <td style="width: 45%; font-weight:600; color:#334155;">
                                            <?php echo htmlspecialchars($b['nombre_base']); ?>
                                        </td>
                                        <td style="width: 50%;">
                                            <select name="tecnico_base[]" class="table-input" style="background:#f8fafc; border:1px solid #e2e8f0; font-weight:600; color:var(--ah-primary);">
                                                <option value="" style="color:#94a3b8; font-weight:normal;">-- Seleccionar Técnico --</option>
                                                <?php foreach($tecnicos as $t): ?>
                                                    <option value="<?php echo htmlspecialchars($t['identidad']); ?>" <?php echo ($b['identidad_tecnico'] == $t['identidad']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($t['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    
                    <div style="padding: 20px; background: white; border-top: 1px solid var(--border); text-align: right;">
                        <button type="submit" class="btn-save" style="background: #16a34a;"><i class="fa-solid fa-floppy-disk"></i> Guardar Distribución</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>
</html>