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

$course_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($course_id === 0) { header("Location: cursos.php"); exit; }

// ==========================================
// PROCESAR FORMULARIOS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token'])) {
    Auth::checkCSRF($_POST['csrf_token']);

    if ($_POST['action'] == 'add_module') {
        $title = trim($_POST['title']);
        if (!empty($title)) {
            $stmt = $db->prepare("INSERT INTO ah_modules (course_id, title) VALUES (:cid, :t)");
            $stmt->execute(['cid' => $course_id, 't' => $title]);
            $msg = "<div class='alert success'><i class='fa-solid fa-check'></i> Módulo agregado con éxito.</div>";
        }
    }
    
    elseif ($_POST['action'] == 'save_lesson') {
        $lesson_id = isset($_POST['lesson_id']) && is_numeric($_POST['lesson_id']) ? (int)$_POST['lesson_id'] : 0;
        $module_id = (int)$_POST['module_id'];
        $title = trim($_POST['title']);
        $type = $_POST['content_type']; 
        $content_json = isset($_POST['content_html']) ? $_POST['content_html'] : '[]';
        
        if (!empty($title) && $module_id > 0) {
            if ($lesson_id > 0) {
                $query = "UPDATE ah_lessons SET title = :t, content_type = :ctype, content_html = :ch WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $lesson_id);
            } else {
                $query = "INSERT INTO ah_lessons (module_id, title, content_type, content_html) VALUES (:mid, :t, :ctype, :ch)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':mid', $module_id);
            }
            
            $stmt->bindParam(':t', $title);
            $stmt->bindParam(':ctype', $type);
            $stmt->bindParam(':ch', $content_json);
            
            if ($stmt->execute()) {
                $msg = "<div class='alert success'><i class='fa-solid fa-floppy-disk'></i> Lección guardada correctamente con el Editor de Bloques.</div>";
            }
        }
    }
    
    elseif ($_POST['action'] == 'delete_module') {
        $stmt = $db->prepare("DELETE FROM ah_modules WHERE id = :id AND course_id = :cid");
        $stmt->execute(['id' => $_POST['delete_id'], 'cid' => $course_id]);
        $msg = "<div class='alert success'>Módulo eliminado.</div>";
    }
    elseif ($_POST['action'] == 'delete_lesson') {
        $stmt = $db->prepare("DELETE FROM ah_lessons WHERE id = :id");
        $stmt->execute(['id' => $_POST['delete_id']]);
        $msg = "<div class='alert success'>Lección eliminada.</div>";
    }
}

// CARGAR DATOS
$stmt = $db->prepare("SELECT * FROM ah_courses WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $course_id]);
$curso = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt_mods = $db->prepare("SELECT * FROM ah_modules WHERE course_id = :cid ORDER BY sort_order ASC, id ASC");
$stmt_mods->execute(['cid' => $course_id]);
$modulos = $stmt_mods->fetchAll(PDO::FETCH_ASSOC);

$lecciones_por_modulo = [];
if (!empty($modulos)) {
    $stmt_less = $db->prepare("SELECT * FROM ah_lessons WHERE module_id IN (" . implode(',', array_column($modulos, 'id')) . ") ORDER BY sort_order ASC, id ASC");
    $stmt_less->execute();
    while ($row = $stmt_less->fetch(PDO::FETCH_ASSOC)) {
        $lecciones_por_modulo[$row['module_id']][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Notion Builder Académico | <?php echo htmlspecialchars($curso['title']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        .main { flex-grow: 1; padding: 40px; box-sizing: border-box; width: 100%; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; }
        .btn { padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; display: inline-flex; align-items: center; gap: 8px; font-size: 0.9rem; transition: 0.2s; }
        .btn-primary { background: var(--ah-primary); color: white; }
        .btn-accent { background: var(--ah-accent); color: white; padding: 6px 12px; font-size: 0.8rem; }
        .btn-danger { background: transparent; color: #ef4444; border: 1px solid #fca5a5; padding: 4px 8px; font-size: 0.8rem;}
        
        .module-card { background: white; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .module-header { background: #f8fafc; padding: 15px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; border-radius: 10px 10px 0 0; }
        .module-title { font-weight: 700; color: #0f172a; font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }
        .module-body { padding: 20px; }
        
        .lesson-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border: 1px solid var(--border); border-radius: 6px; margin-bottom: 10px; background: #fff; }
        .lesson-info { display: flex; align-items: center; gap: 12px; cursor: pointer; flex-grow: 1; }
        .lesson-icon { width: 30px; height: 30px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: var(--ah-primary); }
        
        /* WORKSPACE DEL COMPONENTE NOTION-BUILDER */
        .lesson-form-box { display: none; background: #fff; padding: 25px; border-radius: 8px; margin-top: 15px; border: 2px solid var(--ah-primary); }
        .lesson-form-box.active { display: block; }
        
        .block-hub { display: flex; gap: 10px; background: #f1f5f9; padding: 12px; border-radius: 8px; margin: 15px 0; }
        .btn-block-trigger { background: white; border: 1px solid var(--border); padding: 8px 12px; border-radius: 6px; font-size: 0.8rem; cursor: pointer; font-weight: bold; color: #475569; }
        .btn-block-trigger:hover { border-color: var(--ah-primary); color: var(--ah-primary); }
        
        .blocks-container { display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px; background: #fafafa; padding: 20px; border-radius: 8px; border: 1px dashed #cbd5e1; min-height: 100px; }
        .visual-block { background: white; border: 1px solid var(--border); border-radius: 6px; padding: 15px; display: flex; gap: 15px; align-items: flex-start; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .block-handle { color: #94a3b8; cursor: grab; padding-top: 10px; }
        .block-main { flex-grow: 1; overflow: hidden; }
        .block-badge { display: inline-block; font-size: 0.7rem; font-weight: bold; text-transform: uppercase; padding: 2px 6px; border-radius: 4px; margin-bottom: 8px; background: #e2e8f0; color: #475569; }
        
        /* NUEVO ESTILO: Editor visual en línea */
        .block-input[contenteditable="true"] { background: white; min-height: 80px; outline: none; border-color: #cbd5e1; }
        .block-input[contenteditable="true"]:focus { border-color: var(--ah-primary); box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.15); }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <main class="main">
        <div style="margin-bottom: 20px;">
            <a href="cursos.php" style="color: #64748b; text-decoration: none; font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Volver a Cursos</a>
        </div>

        <h1 style="margin-top: 0; color: #0f172a; font-weight: 800;">Maquetador de Lecciones Modular: <span style="color: var(--ah-primary);"><?php echo htmlspecialchars($curso['title']); ?></span></h1>
        
        <?php echo $msg; ?>

        <div style="background: white; padding: 20px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 30px;">
            <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_module">
                <div style="flex-grow: 1;">
                    <label style="font-weight: bold; font-size: 0.9rem; color: #475569; display: block; margin-bottom: 8px;">Añadir Nuevo Módulo</label>
                    <input type="text" name="title" class="form-control" placeholder="Ej: Módulo 1: Fundamentos Básicos" required>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-folder-plus"></i> Crear Sección</button>
            </form>
        </div>

        <?php foreach($modulos as $mod): ?>
            <div class="module-card">
                <div class="module-header">
                    <div class="module-title"><i class="fa-solid fa-folder-open" style="color: var(--ah-accent);"></i> <?php echo htmlspecialchars($mod['title']); ?></div>
                    <button type="button" class="btn btn-accent" onclick="launchBlockBuilder(<?php echo $mod['id']; ?>, 0, '', '[]')"><i class="fa-solid fa-plus"></i> Añadir Lección</button>
                </div>

                <div class="module-body">
                    <?php $lecciones = $lecciones_por_modulo[$mod['id']] ?? []; ?>
                    <?php foreach($lecciones as $lec): ?>
                        <div class="lesson-item">
                            <div class="lesson-info" 
                                 data-title="<?php echo htmlspecialchars($lec['title'], ENT_QUOTES, 'UTF-8'); ?>" 
                                 data-json="<?php echo htmlspecialchars($lec['content_html'], ENT_QUOTES, 'UTF-8'); ?>"
                                 onclick="launchBlockBuilder(<?php echo $mod['id']; ?>, <?php echo $lec['id']; ?>, this.getAttribute('data-title'), this.getAttribute('data-json'))">
                                
                                <div class="lesson-icon"><i class="fa-solid fa-cubes" style="color:var(--ah-primary);"></i></div>
                                <div>
                                    <span style="font-weight: 700; color: #334155;"><?php echo htmlspecialchars($lec['title']); ?></span>
                                    <span style="font-size:0.75rem; color:#94a3b8; margin-left:10px;">[Editor Visual] - Clic para modificar</span>
                                </div>
                            </div>
                            
                            <form method="POST" onsubmit="return confirm('¿Borrar lección?');" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="delete_lesson">
                                <input type="hidden" name="delete_id" value="<?php echo $lec['id']; ?>">
                                <button type="submit" class="btn btn-danger" style="border:none;"><i class="fa-solid fa-xmark"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>

                    <div class="lesson-form-box" id="block-builder-<?php echo $mod['id']; ?>">
                        <h3 style="font-size: 1.1rem; margin-bottom: 15px; color: var(--ah-primary); font-weight:800;"><i class="fa-solid fa-cubes"></i> Constructor de Contenido en Bloques</h3>
                        
                        <form method="POST" id="form-sender-<?php echo $mod['id']; ?>" onsubmit="compileBlocksToJSON(<?php echo $mod['id']; ?>)">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="save_lesson">
                            <input type="hidden" name="module_id" value="<?php echo $mod['id']; ?>">
                            <input type="hidden" name="content_type" value="builder"> 
                            <input type="hidden" name="lesson_id" id="meta-lesson-id-<?php echo $mod['id']; ?>" value="0">
                            <input type="hidden" name="content_html" id="meta-json-output-<?php echo $mod['id']; ?>" value="[]">

                            <div style="margin-bottom: 15px;">
                                <label style="font-size: 0.85rem; font-weight: bold; color: #475569;">Título de la Lección</label>
                                <input type="text" name="title" id="meta-title-<?php echo $mod['id']; ?>" class="form-control" required placeholder="Ej: Introducción Práctica">
                            </div>

                            <label style="font-size: 0.85rem; font-weight: bold; color: #475569;">Línea de Tiempo del Contenido (Haz clic en el texto para editarlo visualmente)</label>
                            
                            <div class="block-hub">
                                <button type="button" class="btn-block-trigger" onclick="appendNewBlock(<?php echo $mod['id']; ?>, 'text', '')"><i class="fa-solid fa-align-left" style="color:#3b82f6;"></i> + Texto / Lectura</button>
                                <button type="button" class="btn-block-trigger" onclick="appendNewBlock(<?php echo $mod['id']; ?>, 'video', '')"><i class="fa-brands fa-youtube" style="color:#ef4444;"></i> + Video YouTube</button>
                                <button type="button" class="btn-block-trigger" onclick="appendNewBlock(<?php echo $mod['id']; ?>, 'image', '')"><i class="fa-solid fa-image" style="color:#10b981;"></i> + Imagen URL</button>
                                <button type="button" class="btn-block-trigger" onclick="appendNewBlock(<?php echo $mod['id']; ?>, 'pdf', '')"><i class="fa-solid fa-file-pdf" style="color:#f43f5e;"></i> + Visor PDF</button>
                            </div>

                            <div class="blocks-container" id="blocks-anchor-<?php echo $mod['id']; ?>"></div>

                            <div style="display:flex; justify-content: flex-end; gap:10px;">
                                <button type="button" class="btn" style="background:#e2e8f0;" onclick="document.getElementById('block-builder-<?php echo $mod['id']; ?>').classList.remove('active')">Cancelar</button>
                                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Empaquetar y Guardar Lección</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </main>

    <script>
        // LANZAR Y RESETEAR EL MAQUETADOR MODULAR
        function launchBlockBuilder(modId, lessonId, title, jsonString) {
            document.querySelectorAll('.lesson-form-box').forEach(el => el.classList.remove('active'));
            
            const box = document.getElementById('block-builder-' + modId);
            document.getElementById('meta-lesson-id-' + modId).value = lessonId;
            document.getElementById('meta-title-' + modId).value = title;
            
            const anchor = document.getElementById('blocks-anchor-' + modId);
            anchor.innerHTML = ''; // Limpiar bloques viejos
            
            box.classList.add('active');
            
            // Parsear de forma segura la data proveniente de los atributos HTML
            try {
                const blocksArray = JSON.parse(jsonString || '[]');
                blocksArray.forEach(b => appendNewBlock(modId, b.type, b.value));
            } catch(e) {
                console.log("Error de parseo JSON o lección nueva. Renderizando vacío.");
            }
            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // AGREGAR UN NUEVO COMPONENTE AL WORKSPACE
        function appendNewBlock(modId, type, value) {
            const anchor = document.getElementById('blocks-anchor-' + modId);
            const blockId = 'b-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
            
            const div = document.createElement('div');
            div.className = 'visual-block';
            div.id = blockId;
            div.setAttribute('data-type', type);
            
            let placeholder = "";
            let inputField = "";
            
            if (type === 'text') {
                // AQUÍ ESTÁ LA MAGIA: Pasamos de Textarea a Editor Visual (contenteditable)
                inputField = `<div class="form-control block-input" contenteditable="true">${value}</div>`;
            } else if (type === 'video') {
                placeholder = "Pega el enlace de YouTube (ej: https://www.youtube.com/watch?v=...)";
                inputField = `<input type="text" class="form-control block-input" value="${value}" placeholder="${placeholder}">`;
            } else if (type === 'image') {
                placeholder = "Pega la URL completa de la imagen o recurso gráfico";
                inputField = `<input type="text" class="form-control block-input" value="${value}" placeholder="${placeholder}">`;
            } else if (type === 'pdf') {
                placeholder = "Pega el enlace al archivo digital PDF";
                inputField = `<input type="text" class="form-control block-input" value="${value}" placeholder="${placeholder}">`;
            }

            div.innerHTML = `
                <div class="block-handle"><i class="fa-solid fa-grip-vertical"></i></div>
                <div class="block-main">
                    <span class="block-badge" style="background:${getBlockColor(type)}; color:white;">${type}</span>
                    ${inputField}
                </div>
                <button type="button" class="btn btn-danger" style="border:none; margin-top:22px;" onclick="document.getElementById('${blockId}').remove()"><i class="fa-solid fa-trash"></i></button>
            `;
            anchor.appendChild(div);
        }

        function getBlockColor(type) {
            if(type === 'text') return '#3b82f6';
            if(type === 'video') return '#ef4444';
            if(type === 'image') return '#10b981';
            return '#f43f5e';
        }

        // SERIALIZADOR MEJORADO: Captura el HTML del editor visual y lo empaqueta
        function compileBlocksToJSON(modId) {
            const anchor = document.getElementById('blocks-anchor-' + modId);
            const blocks = anchor.querySelectorAll('.visual-block');
            const dataPackage = [];
            
            blocks.forEach(b => {
                const type = b.getAttribute('data-type');
                const inputEl = b.querySelector('.block-input');
                
                // Si es un div editable extraemos el HTML, si es un input de enlace extraemos el valor
                const val = (inputEl.tagName === 'DIV') ? inputEl.innerHTML.trim() : inputEl.value.trim();
                
                dataPackage.push({ type: type, value: val });
            });
            
            document.getElementById('meta-json-output-' + modId).value = JSON.stringify(dataPackage);
        }
    </script>
</body>
</html>