<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$csrf_token = Auth::generateCSRF();
$msg = "";

// 1. Validar el Socio (Donante)
$partner_id = isset($_GET['socio_id']) && is_numeric($_GET['socio_id']) ? (int)$_GET['socio_id'] : 0;

if ($partner_id === 0) {
    header("Location: socios.php");
    exit;
}

// Obtener datos del socio
$stmt_partner = $db->prepare("SELECT name FROM partners WHERE id = :id LIMIT 1");
$stmt_partner->execute(['id' => $partner_id]);
$partner = $stmt_partner->fetch(PDO::FETCH_ASSOC);

if (!$partner) {
    header("Location: socios.php");
    exit;
}

// Matriz completa
$division_politica = [
    "Atlántida" => [
        "La Ceiba", "El Porvenir (Atlántida)", "Esparta", "Jutiapa",
        "La Masica", "San Francisco (Atlántida)", "Tela", "Arizona"
    ],

    "Choluteca" => [
        "Choluteca", "Apacilagua", "Concepción de María", "Duyure",
        "El Corpus", "El Triunfo", "Marcovia", "Morolica", "Namasigüe",
        "Orocuina", "Pespire", "San Antonio de Flores (Choluteca)",
        "San Isidro (Choluteca)", "San José (Choluteca)",
        "San Marcos de Colón", "Santa Ana de Yusguare"
    ],

    "Colón" => [
        "Trujillo", "Balfate", "Iriona", "Limón", "Bonito Oriental",
        "Sabá", "Santa Fe (Colón)", "Santa Rosa de Aguán",
        "Sonaguera", "Tocoa"
    ],

    "Comayagua" => [
        "Comayagua", "Ajuterique", "El Rosario (Comayagua)", "Esquías",
        "Humuya", "Lamaní", "La Libertad (Comayagua)", "La Trinidad",
        "Lejamaní", "Meámbar", "Minas de Oro", "Ojo de Agua",
        "San Jerónimo (Comayagua)", "San José de Comayagua",
        "San José del Potrero", "San Luis (Comayagua)",
        "San Sebastián (Comayagua)", "Siguatepeque",
        "Villa de San Antonio", "Las Lajas", "Taulabé"
    ],

    "Copán" => [
        "Santa Rosa de Copán", "Cabañas (Copán)",
        "Concepción (Copán)", "Copán Ruinas", "Corquín",
        "Cucuyagua", "Dolores (Copán)", "Dulce Nombre",
        "El Paraíso (Copán)", "Florida", "La Jigua",
        "La Unión (Copán)", "Nueva Arcadia",
        "San Antonio (Copán)", "San Jerónimo (Copán)",
        "San José (Copán)", "San Juan de Opoa",
        "San Nicolás (Copán)", "San Pedro (Copán)",
        "Santa Rita (Copán)", "Trinidad de Copán"
    ],

    "Cortés" => [
        "San Pedro Sula", "Choloma", "Omoa", "Pimienta",
        "Potrerillos (Cortés)", "Puerto Cortés",
        "San Antonio de Cortés", "San Francisco de Yojoa",
        "San Manuel", "Santa Cruz de Yojoa",
        "Villanueva", "La Lima"
    ],

    "El Paraíso" => [
        "Yuscarán", "Alauca", "Danlí", "El Paraíso (El Paraíso)",
        "Güinope", "Jacaleapa", "Liure", "Morocelí",
        "Oropolí", "Potrerillos (El Paraíso)",
        "San Antonio de Flores (El Paraíso)",
        "San Lucas", "San Matías", "Soledad",
        "Teupasenti", "Texiguat", "Vado Ancho", "Trojes"
    ],

    "Francisco Morazán" => [
        "Distrito Central", "Alubarén", "Cedros", "Curarén",
        "El Porvenir (Francisco Morazán)", "Guaimaca",
        "La Libertad (Francisco Morazán)", "La Venta",
        "Lepaterique", "Marale", "Nueva Armenia",
        "Ojojona", "Orica", "Reitoca", "Sabanagrande",
        "San Antonio de Oriente", "San Buenaventura",
        "San Ignacio", "San Juan de Flores",
        "San Miguelito (Francisco Morazán)",
        "Santa Ana (Francisco Morazán)",
        "Santa Lucía (Francisco Morazán)",
        "Talanga", "Tatumbla", "Valle de Ángeles",
        "Villa de San Francisco", "Vallecillo",
        "Las Delicias"
    ],

    "Gracias a Dios" => [
        "Puerto Lempira", "Brus Laguna", "Ahuas",
        "Juan Francisco Bulnes", "Villeda Morales",
        "Wampusirpi"
    ],

    "Intibucá" => [
        "La Esperanza", "Camasca", "Colomoncagua",
        "Concepción (Intibucá)", "Dolores (Intibucá)",
        "Intibucá", "Jesús de Otoro", "Magdalena",
        "Masaguara", "San Antonio (Intibucá)",
        "San Isidro (Intibucá)", "San Juan (Intibucá)",
        "San Marcos de Sierra",
        "San Miguelito (Intibucá)",
        "Santa Lucía (Intibucá)",
        "Yamaranguila", "San Francisco de Opalaca"
    ],

    "Islas de la Bahía" => [
        "Roatán", "Guanaja",
        "José Santos Guardiola", "Utila"
    ],

    "La Paz" => [
        "La Paz", "Aguanqueterique",
        "Cabañas (La Paz)", "Cane", "Chinacla",
        "Guajiquiro", "Lauterique", "Marcala",
        "Mercedes de Oriente", "Opatoro",
        "San Antonio del Norte",
        "San José (La Paz)", "San Juan (La Paz)",
        "San Pedro de Tutule",
        "Santa Ana (La Paz)",
        "Santa Elena (La Paz)",
        "Santa María (La Paz)",
        "Santiago de Puringla", "Yarula"
    ],

    "Lempira" => [
        "Gracias", "Belén (Lempira)",
        "Candelaria (Lempira)", "Cololaca",
        "Erandique", "Gualcince", "Guarita",
        "La Campa", "La Iguala",
        "Las Flores (Lempira)",
        "La Unión (Lempira)", "Mapulaca",
        "Piraera", "San Andrés",
        "San Francisco (Lempira)",
        "San Juan Guarita",
        "San Manuel Colohete",
        "San Marcos de Caiquín",
        "San Rafael (Lempira)",
        "San Sebastián (Lempira)",
        "Santa Cruz (Lempira)",
        "Talgua", "Tambla", "Tomalá",
        "Valladolid", "Virginia"
    ],

    "Ocotepeque" => [
        "Ocotepeque", "Belén Gualcho",
        "Concepción (Ocotepeque)",
        "Dolores Merendón", "Fraternidad",
        "La Labor", "Lucerna", "Mercedes",
        "San Fernando",
        "San Francisco del Valle",
        "San Jorge",
        "San Marcos (Ocotepeque)",
        "Santa Fe (Ocotepeque)",
        "Sensenti", "Sinuapa"
    ],

    "Olancho" => [
        "Juticalpa", "Campamento", "Catacamas",
        "Concordia", "Dulce Nombre de Culmí",
        "El Rosario (Olancho)",
        "Esquipulas del Norte", "Gualaco",
        "Guarizama", "Guata", "Guayape",
        "Jano", "Mangulile", "Manto",
        "Salamá", "San Esteban",
        "San Francisco de Becerra",
        "San Francisco de la Paz",
        "Santa María del Real",
        "Silca", "Yocón", "Patuca"
    ],

    "Santa Bárbara" => [
        "Santa Bárbara", "Arada", "Atima",
        "Azacualpa", "Ceguaca",
        "San José de Colinas",
        "Concepción del Norte",
        "Concepción del Sur",
        "Chinda", "El Naranjito",
        "Gualala", "Ilama",
        "Las Vegas", "Macuelizo",
        "Nueva Celilac", "Nueva Frontera",
        "Quimistán",
        "San Francisco de Ojuera",
        "San Luis (Santa Bárbara)",
        "San Marcos (Santa Bárbara)",
        "San Nicolás (Santa Bárbara)",
        "San Pedro Zacapa",
        "Santa Rita (Santa Bárbara)",
        "San Vicente Centenario",
        "Trinidad (Santa Bárbara)"
    ],

    "Valle" => [
        "Nacaome", "Alianza", "Amapala",
        "Caridad", "Goascorán", "Langue",
        "San Francisco de Coray",
        "San Lorenzo", "Aramecina"
    ],

    "Yoro" => [
        "Yoro", "Arenal", "El Negrito",
        "El Progreso", "Jocón",
        "Morazán", "Olanchito",
        "Santa Rita (Yoro)",
        "Sulaco", "Victoria", "Yorito"
    ]
];
// ==========================================
// ELIMINAR PROYECTO
// ==========================================
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
        try {
            $db->beginTransaction();
            $stmt_del_loc = $db->prepare("DELETE FROM project_locations WHERE project_id = :id");
            $stmt_del_loc->execute(['id' => $_GET['delete']]);
            $stmt_del = $db->prepare("DELETE FROM projects WHERE id = :id AND partner_id = :pid");
            $stmt_del->execute(['id' => $_GET['delete'], 'pid' => $partner_id]);
            $db->commit();
            $msg = "<div class='alert success'><i class='fa-solid fa-trash-can'></i> Proyecto eliminado exitosamente.</div>";
        } catch (Exception $e) {
            $db->rollBack();
            $msg = "<div class='alert error'>Error al eliminar el proyecto.</div>";
        }
    }
}

// ==========================================
// AGREGAR NUEVO PROYECTO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_project') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    $title = trim($_POST['title']);
    $fiscal_period = trim($_POST['fiscal_period']);
    $what_we_did = trim($_POST['what_we_did']);
    $achievements_html = trim($_POST['achievements_html']);
    $status = $_POST['status'];
    $municipios = $_POST['municipios'] ?? [];

    if (!empty($title) && !empty($fiscal_period)) {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("INSERT INTO projects (partner_id, title, fiscal_period, what_we_did, achievements_html, status) 
                                  VALUES (:pid, :t, :fp, :wwd, :ah, :s)");
            
            $stmt->execute([
                'pid' => $partner_id, 't' => $title, 'fp' => $fiscal_period, 
                'wwd' => $what_we_did, 'ah' => $achievements_html, 's' => $status
            ]);
            
            $new_project_id = $db->lastInsertId();
            
            if (!empty($municipios)) {
                $stmt_loc = $db->prepare("INSERT INTO project_locations (project_id, department, municipality) VALUES (:pid, :dept, :mun)");
                foreach ($municipios as $item) {
                    $parts = explode('|', $item);
                    if (count($parts) === 2) {
                        $stmt_loc->execute([
                            'pid' => $new_project_id, 
                            'dept' => $parts[0], 
                            'mun' => $parts[1]
                        ]);
                    }
                }
            }

            $db->commit();
            $msg = "<div class='alert success'><i class='fa-solid fa-circle-check'></i> Proyecto creado y enlazado al mapa correctamente.</div>";
        } catch (PDOException $e) {
            $db->rollBack();
            $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error al guardar el proyecto.</div>";
        }
    } else {
        $msg = "<div class='alert error'>El título y el periodo fiscal son obligatorios.</div>";
    }
}

$stmt = $db->prepare("SELECT * FROM projects WHERE partner_id = :pid ORDER BY id DESC");
$stmt->execute(['pid' => $partner_id]);
$proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proyectos de <?php echo htmlspecialchars($partner['name']); ?> | AH Admin Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; flex-shrink: 0; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-content { padding: 24px; flex-grow: 1; }
        .nav-link { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; gap: 10px; padding: 10px 0; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { color: white; }
        .nav-link.active i { color: var(--ah-accent); }
        .main { flex-grow: 1; padding: 40px; overflow-y: auto; box-sizing: border-box; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .card { background: white; border-radius: 12px; padding: 35px; box-shadow: 0 4px 15px rgba(0,0,0,0.01); border: 1px solid var(--border); margin-bottom: 30px;}
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; transition: 0.2s; }
        .form-control:focus { outline: none; border-color: var(--ah-primary); box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.15); }
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 14px 30px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem; }
        .btn-save:hover { background: #2c7285; transform: translateY(-1px); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f1f5f9; padding: 15px; text-align: left; color: #475569; font-size: 0.9rem; border-bottom: 2px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .geo-box { background: #f8fafc; padding: 25px; border-radius: 10px; border: 1px solid var(--border); margin-top: 10px; }
        .search-wrapper { position: relative; margin-bottom: 20px; }
        .search-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .search-input { width: 100%; padding: 12px 12px 12px 45px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; box-sizing: border-box; }
        .dept-accordion { background: white; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 12px; overflow: hidden; }
        .dept-header { background: #fff; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none; border-bottom: 1px solid transparent; transition: background 0.2s; }
        .dept-header:hover { background: #f1f5f9; }
        .dept-title { font-weight: 700; color: #334155; display: flex; align-items: center; gap: 10px; font-size: 0.95rem; }
        .dept-controls { display: flex; align-items: center; gap: 15px; }
        .btn-toggle-all { background: #e2e8f0; color: #475569; border: none; padding: 4px 10px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; cursor: pointer; }
        .btn-toggle-all:hover { background: #cbd5e1; }
        .muns-container { padding: 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; background: #fafafa; border-top: 1px solid #f1f5f9; }
        .checkbox-label { display: flex; align-items: center; gap: 10px; background: white; padding: 10px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; border: 1px solid #e2e8f0; transition: 0.2s; user-select: none; }
        .checkbox-label:hover { border-color: var(--ah-primary); background: #f0f9ff; }
        .checkbox-label input { width: 16px; height: 16px; accent-color: var(--ah-primary); cursor: pointer; margin: 0; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <?php include 'sidebar.php'; ?>
    </aside>

    <main class="main">
        <div style="margin-bottom: 25px;">
            <a href="socios.php" style="color: #64748b; text-decoration: none; font-weight: 600; font-size: 0.9rem;"><i class="fa-solid fa-arrow-left"></i> Volver a Socios</a>
        </div>

        <h1 style="margin-top: 0; margin-bottom: 30px; font-size: 2rem; color: #0f172a;">
            Proyectos: <span style="color: var(--ah-primary);"><?php echo htmlspecialchars($partner['name']); ?></span>
        </h1>

        <?php echo $msg; ?>

        <div class="card">
            <h2 style="margin-top: 0; color: #1e293b; font-size: 1.3rem;"><i class="fa-solid fa-folder-plus" style="color: var(--ah-accent);"></i> Registrar Nuevo Proyecto</h2>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_project">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Título del Proyecto / Intervención</label>
                        <input type="text" name="title" class="form-control" placeholder="Ej: Programa de Empoderamiento Juvenil" required>
                    </div>
                    <div class="form-group">
                        <label>Periodo Fiscal / Año</label>
                        <input type="text" name="fiscal_period" class="form-control" placeholder="Ej: FY25 o 2024-2025" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Estrategia Implementada (¿Qué hicimos?)</label>
                    <textarea name="what_we_did" class="form-control" rows="3" placeholder="Describe brevemente el objetivo y las acciones principales..."></textarea>
                </div>

                <div class="form-group">
                    <label>Logros Alcanzados (HTML / Viñetas)</label>
                    <textarea name="achievements_html" class="form-control" rows="4" placeholder="<ul><li>Logro 1</li><li>Logro 2</li></ul>"></textarea>
                </div>

                <div class="form-group">
                    <label style="font-size: 1rem;"><i class="fa-solid fa-map-location-dot" style="color: var(--ah-primary);"></i> Matriz de Cobertura Geográfica Integral (298 Municipios)</label>
                    
                    <div class="geo-box">
                        <div class="search-wrapper">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="munSearch" class="search-input" placeholder="Filtrar municipios instantáneamente (ej: Reitoca, Nacaome)...">
                        </div>

                        <div id="departments-wrapper">
                            <?php 
                            $dept_index = 1; // Contador para generar ID único de departamento
                            foreach ($division_politica as $departamento => $municipios_lista): ?>
                                <div class="dept-accordion" data-dept="<?php echo strtolower($departamento); ?>">
                                    <div class="dept-header" onclick="toggleAccordion(this)">
                                        <div class="dept-title">
                                            <i class="fa-solid fa-chevron-down arrow-icon" style="transition:transform 0.2s; font-size:0.8rem;"></i>
                                            <span><?php echo $departamento; ?></span>
                                            <span style="font-size:0.8rem; color:#94a3b8; font-weight:normal;">(<span class="checked-count">0</span> seleccionados)</span>
                                        </div>
                                        <div class="dept-controls" onclick="event.stopPropagation();">
                                            <button type="button" class="btn-toggle-all" onclick="checkAllDept('<?php echo $departamento; ?>', true)">Todo</button>
                                            <button type="button" class="btn-toggle-all" style="background:#f1f5f9;" onclick="checkAllDept('<?php echo $departamento; ?>', false)">Ninguno</button>
                                        </div>
                                    </div>
                                    
                                    <div class="muns-container" id="dept-box-<?php echo $departamento; ?>">
                                        <?php 
                                        $mun_index = 1; // Contador interno para municipio
                                        foreach ($municipios_lista as $muni): 
                                            // Generamos un ID matemático único (Ej: 101, 102, 512, etc.)
                                            $id_unico = $dept_index . sprintf('%02d', $mun_index);
                                        ?>
                                            <label class="checkbox-label" data-searchname="<?php echo strtolower($muni); ?>" id="muni_<?php echo $id_unico; ?>">
                                                <input type="checkbox" name="municipios[]" value="<?php echo htmlspecialchars($departamento . '|' . $muni); ?>" onchange="updateCheckedCount(this)" data-id="<?php echo $id_unico; ?>">
                                                <span><?php echo $muni; ?></span>
                                            </label>
                                        <?php 
                                            $mun_index++;
                                        endforeach; ?>
                                    </div>
                                </div>
                            <?php 
                            $dept_index++;
                            endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="max-width: 250px;">
                    <label>Estado de Ejecución</label>
                    <select name="status" class="form-control">
                        <option value="ongoing">En Ejecución</option>
                        <option value="completed">Completado</option>
                    </select>
                </div>

                <button type="submit" class="btn-save"><i class="fa-solid fa-plus"></i> Crear Proyecto</button>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top: 0; color: #1e293b; font-size: 1.3rem;"><i class="fa-solid fa-list-check"></i> Proyectos Registrados</h2>
            
            <?php if(empty($proyectos)): ?>
                <div style="background: #f8fafc; padding: 20px; border-radius: 8px; color: #64748b; text-align: center;">
                    No hay proyectos registrados para este socio.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Periodo</th>
                            <th>Estado</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($proyectos as $p): ?>
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($p['title']); ?></td>
                                <td><span style="background: #e0f2fe; color: #0284c7; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: bold;"><?php echo htmlspecialchars($p['fiscal_period']); ?></span></td>
                                <td>
                                    <?php if($p['status'] == 'ongoing'): ?>
                                        <span style="color: #059669; background: #d1fae5; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">En Ejecución</span>
                                    <?php else: ?>
                                        <span style="color: #64748b; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">Completado</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="editar_proyecto.php?id=<?php echo $p['id']; ?>&socio_id=<?php echo $partner_id; ?>" style="color: var(--ah-primary); text-decoration: none; margin-right: 15px; font-weight: bold;">
                                        <i class="fa-solid fa-pen-to-square"></i> Editar
                                    </a>
                                    <a href="?socio_id=<?php echo $partner_id; ?>&delete=<?php echo $p['id']; ?>&token=<?php echo $csrf_token; ?>" onclick="return confirm('¿Eliminar este proyecto y sus coberturas geográficas de forma permanente?')" style="color: #ef4444; text-decoration: none;">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function toggleAccordion(header) {
            const container = header.nextElementSibling;
            const arrow = header.querySelector('.arrow-icon');
            if (container.style.display === "none") {
                container.style.display = "grid";
                arrow.style.transform = "rotate(0deg)";
            } else {
                container.style.display = "none";
                arrow.style.transform = "rotate(-90deg)";
            }
        }

        function checkAllDept(deptId, checkState) {
            const box = document.getElementById('dept-box-' + deptId);
            const checkboxes = box.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => {
                if (cb.parentElement.style.display !== 'none') {
                    cb.checked = checkState;
                }
            });
            recalculateCounters();
        }

        function recalculateCounters() {
            document.querySelectorAll('.dept-accordion').forEach(accordion => {
                const totalChecked = accordion.querySelectorAll('.muns-container input[type="checkbox"]:checked').length;
                accordion.querySelector('.checked-count').innerText = totalChecked;
            });
        }

        function updateCheckedCount(checkbox) {
            recalculateCounters();
        }

        document.getElementById('munSearch').addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            
            document.querySelectorAll('.dept-accordion').forEach(accordion => {
                const labels = accordion.querySelectorAll('.checkbox-label');
                let matchInDept = false;

                labels.forEach(label => {
                    const munName = label.getAttribute('data-searchname');
                    if (munName.includes(query)) {
                        label.style.display = 'flex';
                        matchInDept = true;
                    } else {
                        label.style.display = 'none';
                    }
                });

                const container = accordion.querySelector('.muns-container');
                const arrow = accordion.querySelector('.arrow-icon');
                if (query !== "") {
                    if (matchInDept) {
                        accordion.style.display = 'block';
                        container.style.display = 'grid';
                        arrow.style.transform = "rotate(0deg)";
                    } else {
                        accordion.style.display = 'none';
                    }
                } else {
                    accordion.style.display = 'block';
                    container.style.display = 'none';
                    arrow.style.transform = "rotate(-90deg)";
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.muns-container').forEach(c => {
                c.style.display = "none";
            });
            document.querySelectorAll('.arrow-icon').forEach(a => {
                a.style.transform = "rotate(-90deg)";
            });
            recalculateCounters();
        });
    </script>
</body>
</html>