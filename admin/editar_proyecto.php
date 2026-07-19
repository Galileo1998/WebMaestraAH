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

$project_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$partner_id = isset($_GET['socio_id']) && is_numeric($_GET['socio_id']) ? (int)$_GET['socio_id'] : 0;

if ($project_id === 0 || $partner_id === 0) {
    header("Location: socios.php");
    exit;
}

// ==========================================
// PROCESAR ACTUALIZACIÓN (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_project') {
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

            // 1. Actualizar metadatos del proyecto
            $stmt = $db->prepare("UPDATE projects SET title = :t, fiscal_period = :fp, what_we_did = :wwd, achievements_html = :ah, status = :s WHERE id = :id AND partner_id = :pid");
            $stmt->execute([
                't' => $title, 'fp' => $fiscal_period, 'wwd' => $what_we_did, 'ah' => $achievements_html, 
                's' => $status, 'id' => $project_id, 'pid' => $partner_id
            ]);

            // 2. Saneamiento geográfico: Limpiar registros antiguos de ubicación
            $db->prepare("DELETE FROM project_locations WHERE project_id = :id")->execute(['id' => $project_id]);
            
            // 3. Insertar la nueva matriz de intervención
            if (!empty($municipios)) {
                $stmt_loc = $db->prepare("INSERT INTO project_locations (project_id, department, municipality) VALUES (:pid, 'Asignado', :mun)");
                foreach ($municipios as $mun) {
                    $stmt_loc->execute(['pid' => $project_id, 'mun' => $mun]);
                }
            }

            $db->commit();
            $msg = "<div class='alert success'><i class='fa-solid fa-circle-check'></i> Cambios almacenados y mapa de cobertura actualizado correctamente.</div>";
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Error al editar proyecto: " . $e->getMessage());
            $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Fallo crítico en la transacción de base de datos.</div>";
        }
    }
}

// ==========================================
// CARGAR DATASETS ACTUALES
// ==========================================
$stmt = $db->prepare("SELECT * FROM projects WHERE id = :id AND partner_id = :pid LIMIT 1");
$stmt->execute(['id' => $project_id, 'pid' => $partner_id]);
$proyecto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proyecto) {
    header("Location: socios.php");
    exit;
}

// Obtener nombres de municipios actualmente guardados para marcarlos como 'checked'
$stmt_loc = $db->prepare("SELECT municipality FROM project_locations WHERE project_id = :id");
$stmt_loc->execute(['id' => $project_id]);
$saved_muns = $stmt_loc->fetchAll(PDO::FETCH_COLUMN);

// Matriz completa indexada de los 18 Departamentos y sus 298 Municipios oficiales de Honduras
$division_politica = [
    "Atlántida" => ["La Ceiba", "El Porvenir", "Esparta", "Jutiapa", "La Masica", "San Francisco", "Tela", "Arizona"],
    "Choloteca" => ["Choloteca", "Apacilagua", "Concepción de María", "Duyure", "El Corpus", "El Triunfo", "Marcovia", "Morolica", "Namasigüe", "Orocuina", "Pespire", "San Antonio de Flores", "San Isidro", "San José", "San Marcos de Colón", "Santa Ana de Yusguare"],
    "Colón" => ["Trujillo", "Balfate", "Iriona", "Limón", "Bonito Oriental", "Sabá", "Santa Fe", "Santa Rosa de Aguán", "Sonaguera", "Tocoa"],
    "Comayagua" => ["Comayagua", "Ajuterique", "El Rosario", "Esquías", "Humuya", "Lamaní", "La Libertad", "La Trinidad", "Lejamaní", "Meámbar", "Minas de Oro", "Ojo de Agua", "San Jerónimo", "San José de Comayagua", "San José del Potrero", "San Luis", "San Sebastián", "Siguatepeque", "Villa de San Antonio", "Las Lajas", "Taulabé"],
    "Copán" => ["Santa Rosa de Copán", "Cabañas", "Concepción", "Copán Ruinas", "Corquín", "Cucuyagua", "Dolores", "Dulce Nombre", "El Paraíso", "Florida", "La Jigua", "La Unión", "Nueva Arcadia", "San Antonio", "San Jerónimo", "San José", "San Juan de Opoa", "San Nicolás", "San Pedro", "Santa Rita", "Trinidad de Copán"],
    "Cortés" => ["San Pedro Sula", "Choloma", "Omoa", "Pimienta", "Potrerillos", "Puerto Cortés", "San Antonio de Cortés", "San Francisco de Yojoa", "San Manuel", "Santa Cruz de Yojoa", "Villanueva", "La Lima"],
    "El Paraíso" => ["Yuscarán", "Alauca", "Danlí", "El Paraíso", "Güinope", "Jacaleapa", "Liure", "Morocelí", "Oropolí", "Potrerillos", "San Antonio de Flores", "San Lucas", "San Matías", "Soledad", "Teupasenti", "Texiguat", "Vedia", "Trojes"],
    "Francisco Morazán" => ["Distrito Central", "Alubarén", "Cedros", "Curarén", "El Porvenir", "Guaimaca", "La Libertad", "La Venta", "Lepaterique", "Marale", "Nueva Armenia", "Ojojona", "Orica", "Reitoca", "Sabanagrande", "San Antonio de Oriente", "San Buenaventura", "San Ignacio", "San Juan de Flores", "San Miguelito", "Santa Ana", "Santa Lucía", "Talanga", "Tatumbla", "Valle de Ángeles", "Villa de San Francisco", "Vallecillo", "Las Delicias"],
    "Gracias a Dios" => ["Puerto Lempira", "Brus Laguna", "Ahuas", "Juan Francisco Bulnes", "Villeda Morales", "Wampusirpi"],
    "Intibucá" => ["La Esperanza", "Camasca", "Colomoncagua", "Concepción", "Dolores", "Intibucá", "Jesús de Otoro", "Magdalena", "Masaguara", "San Antonio", "San Isidro", "San Juan", "San Marcos de Sierra", "San Miguelito", "Santa Lucía", "Yamaranguila", "San Francisco de Opalaca"],
    "Islas de la Bahía" => ["Roatán", "Guanaja", "José Santos Guardiola", "Utila"],
    "La Paz" => ["La Paz", "Aguanqueterique", "Cabañas", "Cane", "Chinacla", "Guajiquiro", "Lauterique", "Marcala", "Mercedes de Oriente", "Opatoro", "San Antonio del Norte", "San José", "San Juan", "San Pedro de Tutule", "Santa Ana", "Santa Elena", "Santa María", "Santiago de Puringla", "Yarula"],
    "Lempira" => ["Gracias", "Belén", "Candelaria", "Cololaca", "Erandique", "Gualcince", "Guarita", "La Campa", "La Iguala", "Las Flores", "La Unión", "Mapulaca", "Piraera", "San Andrés", "San Francisco", "San Juan Guarita", "San Manuel Colohete", "San Marcos de Caiquín", "San Rafael", "San Sebastián", "Santa Cruz", "Talgua", "Tambla", "Tomalá", "Valladolid", "Virginia"],
    "Ocotepeque" => ["Ocotepeque", "Belén Gualcho", "Concepción", "Dolores Merendón", "Fraternidad", "La Labor", "Lucerna", "Mercedes", "San Fernando", "San Francisco del Valle", "San Jorge", "San Marcos", "Santa Fe", "Sensenti", "Sinuapa"],
    "Olancho" => ["Juticalpa", "Campamento", "Catacamas", "Concordia", "Dulce Nombre de Culmí", "El Rosario", "Esquipulas del Norte", "Gualaco", "Guarizama", "Guata", "Guayape", "Jano", "Mangulile", "Manto", "Salamá", "San Esteban", "San Francisco de Becerra", "San Francisco de la Paz", "Santa María del Real", "Silca", "Yocón", "Patuca"],
    "Santa Bárbara" => ["Santa Bárbara", "Arada", "Atima", "Azacualpa", "Ceguaca", "San José de Colinas", "Concepción del Norte", "Concepción del Sur", "Chinda", "El Naranjito", "Gualala", "Ilama", "Las Vegas", "Macuelizo", "Nueva Celilac", "Nueva Frontera", "Quimistán", "San Francisco de Ojuera", "San Luis", "San Marcos", "San Nicolás", "San Pedro Zacapa", "Santa Rita", "San Vicente Centenario", "Trinidad"],
    "Valle" => ["Nacaome", "Alianza", "Amapala", "Caridad", "Goascorán", "Langue", "San Francisco de Coray", "San Lorenzo", "Aramecina"],
    "Yoro" => ["Yoro", "Arenal", "El Negrito", "El Progreso", "Jocón", "Morazán", "Olanchito", "Santa Rita", "Sulaco", "Victoria", "Yorito"]
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Proyecto | AH Admin Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        
        /* Sidebar Estructural Global */
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; flex-shrink: 0; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-content { padding: 24px; flex-grow: 1; }
        .nav-link { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; gap: 10px; padding: 10px 0; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { color: white; }
        .nav-link.active i { color: var(--ah-accent); }
        
        /* Contenedor Principal */
        .main { flex-grow: 1; padding: 40px; overflow-y: auto; box-sizing: border-box; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .card { background: white; border-radius: 12px; padding: 35px; box-shadow: 0 4px 15px rgba(0,0,0,0.01); border: 1px solid var(--border); }
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; transition: 0.2s; }
        .form-control:focus { outline: none; border-color: var(--ah-primary); box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.15); }
        
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 14px 30px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem; }
        .btn-save:hover { background: #2c7285; transform: translateY(-1px); }

        /* Matriz de Localización Geográfica Inteligente */
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
        <div class="card">
            <div style="margin-bottom: 25px;">
                <a href="proyectos.php?socio_id=<?php echo $partner_id; ?>" style="color: #64748b; text-decoration: none; font-weight: 600; font-size: 0.9rem;"><i class="fa-solid fa-arrow-left"></i> Regresar a Proyectos</a>
            </div>
            
            <h1 style="margin-top: 0; margin-bottom: 5px; font-size: 1.8rem; color: #0f172a;"><i class="fa-solid fa-pen-to-square" style="color: var(--ah-primary);"></i> Modificar Parámetros del Proyecto</h1>
            <p style="color: #64748b; margin-top: 0; margin-bottom: 30px;">Edita el marco lógico, metas alcanzadas y asignación territorial multinivel.</p>
            
            <?php echo $msg; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_project">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px;">
                    <div class="form-group">
                        <label>Título Institucional del Proyecto / Fase Operativa</label>
                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($proyecto['title']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Periodo Fiscal Transcurrido (Año)</label>
                        <input type="text" name="fiscal_period" class="form-control" value="<?php echo htmlspecialchars($proyecto['fiscal_period']); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Estrategia Implementada (¿Qué se hizo?)</label>
                    <textarea name="what_we_did" class="form-control" rows="3" style="height: 300px;"><?php echo htmlspecialchars($proyecto['what_we_did']); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Logros Estructurados e Indicadores Alcanzados (Estructura HTML)</label>
                    <textarea name="achievements_html" class="form-control" rows="4"><?php echo htmlspecialchars($proyecto['achievements_html']); ?></textarea>
                    <small style="color:#64748b; display:block; margin-top:5px;">*Puedes utilizar etiquetas `<ul>` y `<li>` para renderizar viñetas directamente en el visualizador frontal.</small>
                </div>

                <div class="form-group">
                    <label style="font-size: 1rem;"><i class="fa-solid fa-map-location-dot" style="color: var(--ah-primary);"></i> Matriz de Cobertura Geográfica Integral (298 Municipios)</label>
                    
                    <div class="geo-box">
                        <div class="search-wrapper">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" id="munSearch" class="search-input" placeholder="Filtrar municipios instantáneamente (ej: Reitoca, Marcala, Nacaome)...">
                        </div>

                        <div id="departments-wrapper">
                            <?php foreach ($division_politica as $departamento => $municipios_lista): ?>
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
                                        <?php foreach ($municipios_lista as $muni): 
                                            $isChecked = in_array($muni, $saved_muns) ? 'checked' : '';
                                        ?>
                                            <label class="checkbox-label" data-searchname="<?php echo strtolower($muni); ?>">
                                                <input type="checkbox" name="municipios[]" value="<?php echo $muni; ?>" <?php echo $isChecked; ?> onchange="updateCheckedCount(this)">
                                                <span><?php echo $muni; ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="max-width: 250px;">
                    <label>Estado de Ejecución Financiera/Técnica</label>
                    <select name="status" class="form-control">
                        <option value="ongoing" <?php echo $proyecto['status'] == 'ongoing' ? 'selected' : ''; ?>>En Ejecución Pasiva</option>
                        <option value="completed" <?php echo $proyecto['status'] == 'completed' ? 'selected' : ''; ?>>Fase Completada</option>
                    </select>
                </div>

                <div style="margin-top: 35px; border-top: 1px solid var(--border); padding-top: 25px;">
                    <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Confirmar y Aplicar Cambios</button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Función para abrir/cerrar los bloques de departamentos manualmente
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

        // Seleccionar o deseleccionar todos los elementos de un solo departamento
        function checkAllDept(deptId, checkState) {
            const box = document.getElementById('dept-box-' + deptId);
            const checkboxes = box.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(cb => {
                if (cb.parentElement.style.display !== 'none') { // Solo afectar los visibles si está filtrado
                    cb.checked = checkState;
                }
            });
            recalculateCounters();
        }

        // Recalcular dinámicamente cuántos municipios hay activos por zona
        function recalculateCounters() {
            document.querySelectorAll('.dept-accordion').forEach(accordion => {
                const totalChecked = accordion.querySelectorAll('.muns-container input[type="checkbox"]:checked').length;
                accordion.querySelector('.checked-count').innerText = totalChecked;
            });
        }

        function updateCheckedCount(checkbox) {
            recalculateCounters();
        }

        // Motor de búsqueda predictiva en tiempo real
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

                // Comportamiento del contenedor según el filtro
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
                    // Si el buscador se vacía, colapsar por defecto para mantener orden estético
                    accordion.style.display = 'block';
                    container.style.display = 'none';
                    arrow.style.transform = "rotate(-90deg)";
                }
            });
        });

        // Inicialización del DOM
        document.addEventListener('DOMContentLoaded', function() {
            // Mantener colapsados todos los acordeones inicialmente para un diseño limpio
            document.querySelectorAll('.muns-container').forEach(c => {
                c.style.display = "none";
            });
            document.querySelectorAll('.arrow-icon').forEach(a => {
                a.style.transform = "rotate(-90deg)";
            });
            
            // Forzar recuento inicial basado en registros recuperados de la base de datos
            recalculateCounters();
        });
    </script>
</body>
</html>