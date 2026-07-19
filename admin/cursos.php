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

// ==========================================
// ELIMINAR CURSO
// ==========================================
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
        try {
            $stmt_del = $db->prepare("DELETE FROM ah_courses WHERE id = :id");
            $stmt_del->execute(['id' => $_GET['delete']]);
            $msg = "<div class='alert success'><i class='fa-solid fa-trash-can'></i> Curso eliminado exitosamente.</div>";
        } catch (Exception $e) {
            $msg = "<div class='alert error'>Error al eliminar: Es posible que el curso tenga módulos dependientes.</div>";
        }
    }
}
// ==========================================
// CAMBIAR ESTADO (PUBLICAR / OCULTAR)
// ==========================================
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
        // Obtener estado actual
        $stmt_st = $db->prepare("SELECT status FROM ah_courses WHERE id = :id");
        $stmt_st->execute(['id' => $_GET['toggle_status']]);
        $curr_status = $stmt_st->fetchColumn();

        if ($curr_status) {
            $new_status = ($curr_status === 'published') ? 'draft' : 'published';
            $stmt_upd = $db->prepare("UPDATE ah_courses SET status = :st WHERE id = :id");
            $stmt_upd->execute(['st' => $new_status, 'id' => $_GET['toggle_status']]);
            
            $estado_txt = $new_status == 'published' ? 'Publicado (Visible)' : 'Borrador (Oculto)';
            $msg = "<div class='alert success'><i class='fa-solid fa-rotate'></i> Estado del curso actualizado a: <b>$estado_txt</b>.</div>";
            
            // Refrescar para evitar reenvío de formulario
            header("Location: cursos.php?msg=status_updated");
            exit;
        }
    }
}
if(isset($_GET['msg']) && $_GET['msg'] == 'status_updated') {
    $msg = "<div class='alert success'><i class='fa-solid fa-check'></i> Visibilidad del curso actualizada.</div>";
}
// ==========================================
// AGREGAR NUEVO CURSO (MANUAL)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_course') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    $title = trim($_POST['title']);
    // Generar un slug automático a partir del título si no se proporciona uno
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title))); 
    $description = trim($_POST['description']);
    $status = $_POST['status'];

    if (!empty($title)) {
        try {
            $stmt = $db->prepare("INSERT INTO ah_courses (title, slug, description, status) VALUES (:t, :s, :d, :st)");
            $stmt->execute([
                't' => $title, 
                's' => $slug, 
                'd' => $description, 
                'st' => $status
            ]);
            $msg = "<div class='alert success'><i class='fa-solid fa-circle-check'></i> Curso creado correctamente.</div>";
        } catch (PDOException $e) {
            // Manejar error si el slug ya existe
            if ($e->getCode() == 23000) {
                $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Ya existe un curso con un nombre similar. Cambia el título.</div>";
            } else {
                $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error al guardar en la base de datos.</div>";
            }
        }
    } else {
        $msg = "<div class='alert error'>El título del curso es obligatorio.</div>";
    }
}

// ==========================================
// OBTENER LISTADO DE CURSOS
// ==========================================
$stmt = $db->query("SELECT * FROM ah_courses ORDER BY id DESC");
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Academia Virtual | AH Admin Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        
        .main { flex-grow: 1; padding: 40px; box-sizing: border-box; overflow-y: auto; }
        .card { background: white; border-radius: 12px; padding: 35px; box-shadow: 0 4px 15px rgba(0,0,0,0.01); border: 1px solid var(--border); margin-bottom: 30px;}
        
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: var(--ah-primary); box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.15); }
        
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 14px 30px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem; }
        .btn-save:hover { background: #2c7285; transform: translateY(-1px); }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f1f5f9; padding: 15px; text-align: left; color: #475569; font-size: 0.9rem; border-bottom: 2px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
        .badge-draft { background: #f1f5f9; color: #64748b; }
        .badge-published { background: #d1fae5; color: #059669; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

<div id="loading-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.9); z-index: 9999; flex-direction: column; justify-content: center; align-items: center; color: white; backdrop-filter: blur(5px);">
    <i class="fa-solid fa-wand-magic-sparkles" style="font-size: 3rem; color: #46B094; margin-bottom: 20px; animation: pulse 1.5s infinite;"></i>
    <h2 style="font-family: 'Inter', sans-serif; margin: 0 0 10px 0;">La IA está analizando y redactando tu curso...</h2>
    <p style="color: #cbd5e1; font-family: 'Inter', sans-serif; font-size: 0.95rem; margin-bottom: 30px;">Procesando documentos, creando módulos y formateando el diseño visual.</p>
    
    <div style="width: 400px; height: 8px; background: #334155; border-radius: 10px; overflow: hidden;">
        <div id="loading-bar" style="width: 0%; height: 100%; background: #46B094; border-radius: 10px; transition: width 0.5s ease-out;"></div>
    </div>
    <p id="loading-text" style="margin-top: 15px; font-weight: bold; color: #46B094;">0%</p>
    
    <style>
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>
</div>

    <main class="main">
        <h1 style="margin-top: 0; margin-bottom: 30px; font-size: 2rem; color: #0f172a;">
            Academia Virtual: <span style="color: var(--ah-primary);">Gestión de Cursos</span>
        </h1>

        <?php echo $msg; ?>

        <div class="card" style="background: #f0fdfa; border-color: #ccfbf1;">
            <h2 style="margin-top: 0; color: #0f766e; font-size: 1.3rem;"><i class="fa-solid fa-wand-magic-sparkles"></i> Asistente Copiloto de Inteligencia Artificial</h2>
            <p style="color: #0f766e; font-size: 0.9rem; margin-bottom: 20px;">Diseña cursos automáticamente. Define el tema, ajusta las instrucciones a tu gusto y apóyate en documentos base.</p>
            
            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <input type="text" id="tema-ia" class="form-control" placeholder="Ej: Educación Financiera para jóvenes (18-24 años)..." style="flex-grow: 1; border-color: #99f6e4;">
                <button type="button" onclick="prepararPrompt()" class="btn-save" style="background: #0f766e; white-space: nowrap;">
                    <i class="fa-solid fa-sliders"></i> Configurar Prompt
                </button>
            </div>

            <div id="panel-config-ia" style="display: none; margin-top: 25px; padding-top: 25px; border-top: 1px dashed #5eead4;">
                <div class="form-group">
                    <label style="color: #0f766e;"><i class="fa-solid fa-terminal"></i> Instrucciones para la IA (Prompt Editable)</label>
                    <textarea id="prompt-ia" class="form-control" rows="7" style="border-color: #99f6e4; background: white; font-family: monospace; font-size: 0.9rem; line-height: 1.5;"></textarea>
                    <small style="color: #0d9488; display: block; margin-top: 5px;">Agrega detalles como "Usa un tono empático", "Enfócate en madres solteras", etc.</small>
                </div>

                <div class="form-group">
                    <label style="color: #0f766e;"><i class="fa-solid fa-file-pdf"></i> Archivo Base de Conocimiento (Opcional)</label>
                    <input type="file" id="archivo-ia" class="form-control" accept=".txt, .pdf, .docx" style="background: white; border-color: #99f6e4;">
                    <small style="color: #0d9488; display: block; margin-top: 5px;">Si subes un manual o folleto, la IA basará el curso estrictamente en ese contenido.</small>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="document.getElementById('panel-config-ia').style.display='none'" class="btn-save" style="background: #ccfbf1; color: #0f766e;">Cancelar</button>
                    <button type="button" onclick="lanzarGeneracionIA()" class="btn-save" style="background: #0f766e;">
                        ✨ ¡Generar Curso Definitivo!
                    </button>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top: 0; color: #1e293b; font-size: 1.3rem;"><i class="fa-solid fa-graduation-cap" style="color: var(--ah-accent);"></i> Diseñar Nuevo Curso (Manual)</h2>
            <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">Crea la estructura base. Más adelante podrás agregarle módulos, videos y evaluaciones.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_course">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Título del Curso / Programa</label>
                        <input type="text" name="title" class="form-control" placeholder="Ej: Plan de Vida y Liderazgo Juvenil" required>
                    </div>
                    <div class="form-group">
                        <label>Estado Inicial</label>
                        <select name="status" class="form-control">
                            <option value="draft">Borrador (Oculto)</option>
                            <option value="published">Publicado (Visible)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Breve Descripción Pedagógica</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="¿Qué aprenderán los estudiantes en este curso?..."></textarea>
                </div>

                <button type="submit" class="btn-save"><i class="fa-solid fa-plus"></i> Registrar Curso</button>
            </form>
        </div>

        <div class="card">
            <h2 style="margin-top: 0; color: #1e293b; font-size: 1.3rem;"><i class="fa-solid fa-list"></i> Catálogo Formativo</h2>
            
            <?php if(empty($cursos)): ?>
                <div style="background: #f8fafc; padding: 20px; border-radius: 8px; color: #64748b; text-align: center; margin-top: 20px;">
                    Aún no hay cursos creados en la plataforma.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Curso</th>
                            <th>Descripción</th>
                            <th>Estado</th>
                            <th style="text-align: right;">Arquitectura</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($cursos as $c): ?>
                            <tr>
                                <td style="font-weight: 700; color: var(--ah-primary);">
                                    <?php echo htmlspecialchars($c['title']); ?><br>
                                    <span style="font-weight: normal; font-size: 0.8rem; color: #94a3b8;">/curso/<?php echo htmlspecialchars($c['slug']); ?></span>
                                </td>
                                <td style="font-size: 0.9rem; color: #475569;">
                                    <?php echo htmlspecialchars(substr($c['description'], 0, 60)) . '...'; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $c['status']; ?>">
                                        <?php echo $c['status'] == 'published' ? 'Publicado' : 'Borrador'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    
                                    <?php if($c['status'] == 'draft'): ?>
                                        <a href="?toggle_status=<?php echo $c['id']; ?>&token=<?php echo $csrf_token; ?>" class="btn-save" style="padding: 8px 15px; font-size: 0.85rem; margin-right: 5px; background: var(--ah-accent);" title="Publicar Curso">
                                            <i class="fa-solid fa-eye"></i> Publicar
                                        </a>
                                    <?php else: ?>
                                        <a href="?toggle_status=<?php echo $c['id']; ?>&token=<?php echo $csrf_token; ?>" class="btn-save" style="padding: 8px 15px; font-size: 0.85rem; margin-right: 5px; background: #64748b;" title="Ocultar Curso">
                                            <i class="fa-solid fa-eye-slash"></i> Ocultar
                                        </a>
                                    <?php endif; ?>

                                    <a href="constructor_curso.php?id=<?php echo $c['id']; ?>" class="btn-save" style="padding: 8px 15px; font-size: 0.85rem; margin-right: 10px; background: #0f172a;">
                                        <i class="fa-solid fa-network-wired"></i> Temario
                                    </a>
                                                                        <a href="constructor_quiz.php?course_id=<?php echo $c['id']; ?>" class="btn-save" style="padding: 8px 15px; font-size: 0.85rem; margin-right: 5px; background: #eab308;">
                                        <i class="fa-solid fa-circle-question"></i> Examen
                                    </a>

                                    <a href="constructor_certificado.php?course_id=<?php echo $c['id']; ?>" class="btn-save" style="padding: 8px 15px; font-size: 0.85rem; margin-right: 5px; background: #a855f7;">
                                        <i class="fa-solid fa-stamp"></i> Diploma
                                    </a>
                                    
                                    <a href="?delete=<?php echo $c['id']; ?>&token=<?php echo $csrf_token; ?>" onclick="return confirm('¿Eliminar este curso definitivamente?')" style="color: #ef4444; text-decoration: none;" title="Eliminar">
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
let loadingInterval;

// PASO 1: Generar el Prompt Editable y mostrar el panel
function prepararPrompt() {
    const tema = document.getElementById('tema-ia').value.trim();
    if(!tema) {
        alert("Por favor, escribe un tema primero para poder configurar el prompt.");
        return;
    }

    const basePrompt = `Actúa como un educador experto y diseñador instruccional en Honduras.
Crea un curso exhaustivo y estructurado sobre el tema: "${tema}".

INSTRUCCIONES ESTRUCTURALES ESTRICTAS:
1. Debes generar EXACTAMENTE 4 módulos.
2. Cada módulo debe tener EXACTAMENTE 3 lecciones.
3. Escribe contenido ABUNDANTE. (Al menos 3 o 4 párrafos largos por cada lección).
4. Usa contexto local (menciona lempiras, pulperías, remesas, según aplique).`;

    document.getElementById('prompt-ia').value = basePrompt;
    document.getElementById('panel-config-ia').style.display = 'block';
}

// PASO 2: Enviar los datos configurados al Backend
async function lanzarGeneracionIA() {
    const tema = document.getElementById('tema-ia').value.trim();
    const promptPersonalizado = document.getElementById('prompt-ia').value.trim();
    const archivoInput = document.getElementById('archivo-ia').files[0];

    if(!promptPersonalizado) {
        alert("El prompt no puede estar vacío.");
        return;
    }

    // Usamos FormData para poder enviar texto + archivos al mismo tiempo
    const formData = new FormData();
    formData.append('csrf_token', <?= json_encode($csrf_token) ?>);
    formData.append('tema', tema);
    formData.append('prompt', promptPersonalizado);
    if (archivoInput) {
        formData.append('archivo_base', archivoInput);
    }

    // Mostrar pantalla de carga
    const overlay = document.getElementById('loading-overlay');
    const bar = document.getElementById('loading-bar');
    const text = document.getElementById('loading-text');
    
    document.getElementById('panel-config-ia').style.display = 'none';
    overlay.style.display = 'flex';
    bar.style.width = '0%';
    text.innerText = '0%';
    let progress = 0;

    loadingInterval = setInterval(() => {
        if(progress < 95) {
            progress += Math.random() * 2 + 1; 
            if(progress > 95) progress = 95;
            bar.style.width = progress + '%';
            text.innerText = Math.floor(progress) + '%';
        }
    }, 1000);

    try {
        const res = await fetch('../api/generar_curso_ia.php', {
            method: 'POST',
            body: formData // No seteamos Content-Type, Fetch lo hace automáticamente
        });
        const data = await res.json();

        clearInterval(loadingInterval);

        if(data.success) {
            bar.style.width = '100%';
            text.innerText = '100% ¡Completado!';
            
            setTimeout(() => {
                alert("¡Magia hecha! El curso se ha generado exitosamente.");
                window.location.reload();
            }, 800);
        } else {
            overlay.style.display = 'none';
            alert("Error al generar: " + (data.error || "Detalle desconocido."));
        }
    } catch (e) {
        clearInterval(loadingInterval);
        overlay.style.display = 'none';
        console.error(e);
        alert("Error de red. Verifica la conexión o el tamaño del archivo subido.");
    }
}
    </script>
</body>
</html>
