<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$msg = "";

// ==========================================
// 1. PROCESAR ACTUALIZACIÓN DE NOTA MANUAL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_score') {
    try {
        $attempt_id = (int)$_POST['attempt_id'];
        $new_score = (float)$_POST['new_score'];
        $passing_score = (int)$_POST['passing_score'];
        
        // Recalcular si aprobó o reprobó con la nueva nota
        $passed = ($new_score >= $passing_score) ? 1 : 0;

        $stmt_upd = $db->prepare("UPDATE ah_quiz_attempts SET score = :score, passed = :passed WHERE id = :id");
        $stmt_upd->execute(['score' => $new_score, 'passed' => $passed, 'id' => $attempt_id]);
        
        $msg = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> Calificación actualizada correctamente a $new_score%.</div>";
    } catch (Exception $e) {
        $msg = "<div class='alert error'>Error al actualizar: " . $e->getMessage() . "</div>";
    }
}

// ==========================================
// 2. OBTENER DATOS Y FILTROS
// ==========================================
$filter_course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

$stmt_courses = $db->query("SELECT id, title FROM ah_courses WHERE status = 'published' ORDER BY title ASC");
$cursos = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT 
            qa.id AS attempt_id,
            u.id AS user_id,
            u.nombre, 
            c.id AS course_id,
            c.title AS curso_titulo, 
            q.title AS quiz_titulo, 
            q.quiz_type, 
            q.passing_score,
            qa.score, 
            qa.passed, 
            qa.attempted_at
        FROM ah_quiz_attempts qa
        INNER JOIN ah_users u ON qa.user_id = u.id
        INNER JOIN ah_quizzes q ON qa.quiz_id = q.id
        INNER JOIN ah_courses c ON q.course_id = c.id";

if ($filter_course_id > 0) { $sql .= " WHERE c.id = :cid"; }
$sql .= " ORDER BY qa.attempted_at DESC";

$stmt = $db->prepare($sql);
if ($filter_course_id > 0) { $stmt->bindParam(':cid', $filter_course_id, PDO::PARAM_INT); }
$stmt->execute();
$intentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Extraer todo el currículo para el acordeón de progreso
$stmt_mod = $db->query("SELECT * FROM ah_modules ORDER BY sort_order ASC");
$todos_modulos = $stmt_mod->fetchAll(PDO::FETCH_ASSOC);

$stmt_les = $db->query("SELECT * FROM ah_lessons ORDER BY sort_order ASC");
$todas_lecciones = [];
while ($row = $stmt_les->fetch(PDO::FETCH_ASSOC)) {
    $todas_lecciones[$row['module_id']][] = $row;
}

// CORRECCIÓN DEFINITIVA: Eliminada la columna created_at
try {
    $stmt_prog = $db->query("SELECT user_id, lesson_id, time_spent_seconds FROM ah_student_progress");
} catch(Exception $e) {
    // Salvavidas por si la columna time_spent_seconds no se creó en la DB aún
    $stmt_prog = $db->query("SELECT user_id, lesson_id, 0 as time_spent_seconds FROM ah_student_progress");
}

$progreso_global = [];
while ($row = $stmt_prog->fetch(PDO::FETCH_ASSOC)) {
    $progreso_global[$row['user_id']][$row['lesson_id']] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuadro de Calificaciones | Admin Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; padding: 0; color: #1e293b; }
        
        .header-bar { background: white; padding: 20px 40px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .header-title { margin: 0; font-size: 1.4rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        
        .container { max-width: 1200px; margin: 40px auto; background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid var(--border); overflow: hidden; padding: 30px; }
        
        .filter-section { display: flex; gap: 15px; margin-bottom: 20px; background: #f1f5f9; padding: 20px; border-radius: 8px; align-items: flex-end; }
        .form-group { flex-grow: 1; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 8px; text-transform: uppercase; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 0.95rem; }
        .btn-filter { background: var(--ah-primary); color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; height: 45px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-size: 0.85rem; text-transform: uppercase; color: #64748b; border-bottom: 2px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); font-size: 0.95rem; vertical-align: middle; }
        
        .badge { padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
        .badge-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .badge-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .badge-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        .grade-editor { display: flex; align-items: center; gap: 8px; }
        .grade-input { width: 70px; padding: 8px; border: 2px solid var(--border); border-radius: 6px; font-weight: bold; text-align: center; font-size: 1rem; }
        .grade-input:focus { border-color: var(--ah-primary); outline: none; }
        .btn-save-grade { background: var(--ah-accent); color: white; border: none; width: 35px; height: 35px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .btn-save-grade:hover { background: #358f77; }

        .btn-expand { background: none; border: 1px solid #cbd5e1; color: #475569; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 0.85rem; transition: 0.2s; }
        .btn-expand:hover { background: #f1f5f9; color: var(--ah-primary); }
        
        .progress-container { padding: 20px 40px; border-left: 4px solid var(--ah-primary); }
        .module-block { background: white; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 15px; overflow: hidden; }
        .module-header { background: #f1f5f9; padding: 12px 20px; font-weight: 800; color: #0f172a; border-bottom: 1px solid var(--border); }
        .lesson-list { list-style: none; padding: 0; margin: 0; }
        .lesson-item { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; }
        .lesson-item:last-child { border-bottom: none; }
        .lesson-time { color: #64748b; font-size: 0.85rem; background: #e2e8f0; padding: 4px 10px; border-radius: 4px; font-weight:600; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

    <div class="header-bar">
        <h1 class="header-title"><i class="fa-solid fa-chart-line" style="color: var(--ah-accent);"></i> Calificaciones y Progreso</h1>
        <a href="cursos.php" style="color: #64748b; text-decoration: none; font-weight: bold;"><i class="fa-solid fa-arrow-left"></i> Volver al Panel</a>
    </div>

    <div class="container">
        <?php echo $msg; ?>

        <form method="GET" class="filter-section">
            <div class="form-group">
                <label>Filtrar por Curso Objetivo</label>
                <select name="course_id" class="form-control">
                    <option value="0">-- Mostrar todos los cursos y evaluaciones --</option>
                    <?php foreach($cursos as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $filter_course_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Aplicar Filtro</button>
            <?php if($filter_course_id > 0): ?>
                <a href="calificaciones.php" style="margin-left:10px; color:#ef4444; text-decoration:none; font-weight:bold; padding: 12px;">Limpiar</a>
            <?php endif; ?>
        </form>

        <?php if (empty($intentos)): ?>
            <div style="text-align: center; padding: 50px; color: #94a3b8;">
                <i class="fa-solid fa-folder-open" style="font-size: 3rem; margin-bottom: 15px;"></i>
                <h3>No hay intentos registrados aún.</h3>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Estudiante</th>
                        <th>Curso y Evaluación</th>
                        <th>Fecha de Intento</th>
                        <th>Modificar Nota</th>
                        <th>Estado</th>
                        <th>Historial</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($intentos as $intento): 
                        $uid = $intento['user_id'];
                        $cid = $intento['course_id'];
                        $row_id = "prog_" . $intento['attempt_id'];
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($intento['nombre'] ?? 'ID: '.$uid); ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600; color: var(--ah-primary);"><?php echo htmlspecialchars($intento['curso_titulo']); ?></div>
                                <div style="font-size: 0.85rem; color: #64748b;"><?php echo htmlspecialchars($intento['quiz_titulo']); ?></div>
                            </td>
                            <td style="color: #475569; font-size: 0.85rem;"><i class="fa-regular fa-clock"></i> <?php echo date("d/m/Y h:i A", strtotime($intento['attempted_at'])); ?></td>
                            
                            <td>
                                <form method="POST" action="calificaciones.php<?php echo $filter_course_id > 0 ? '?course_id='.$filter_course_id : ''; ?>" class="grade-editor">
                                    <input type="hidden" name="action" value="update_score">
                                    <input type="hidden" name="attempt_id" value="<?php echo $intento['attempt_id']; ?>">
                                    <input type="hidden" name="passing_score" value="<?php echo $intento['passing_score']; ?>">
                                    <input type="number" name="new_score" value="<?php echo round($intento['score']); ?>" class="grade-input" min="0" max="100" step="0.1" required>
                                    <button type="submit" class="btn-save-grade" title="Guardar nueva nota"><i class="fa-solid fa-floppy-disk"></i></button>
                                </form>
                            </td>

                            <td>
                                <?php if ($intento['passed']): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check"></i> Aprobado</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fa-solid fa-xmark"></i> Reprobado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn-expand" onclick="toggleProgress('<?php echo $row_id; ?>')">
                                    <i class="fa-solid fa-chevron-down"></i> Ver Progreso
                                </button>
                            </td>
                        </tr>

                        <tr class="progress-row" id="<?php echo $row_id; ?>" style="display: none; background: #f8fafc;">
                            <td colspan="6">
                                <div class="progress-container">
                                    <h4 style="margin-top:0; color:var(--ah-primary);">Desglose de Lecciones (<?php echo htmlspecialchars($intento['curso_titulo']); ?>)</h4>
                                    
                                    <?php 
                                    $modulos_curso = array_filter($todos_modulos, function($m) use ($cid) { return $m['course_id'] == $cid; });
                                    foreach ($modulos_curso as $mod): 
                                    ?>
                                        <div class="module-block">
                                            <div class="module-header"><i class="fa-solid fa-folder-open" style="color:var(--ah-accent);"></i> <?php echo htmlspecialchars($mod['title']); ?></div>
                                            <ul class="lesson-list">
                                                <?php 
                                                $lecciones = isset($todas_lecciones[$mod['id']]) ? $todas_lecciones[$mod['id']] : [];
                                                foreach ($lecciones as $lec): 
                                                    $completada = isset($progreso_global[$uid][$lec['id']]);
                                                    
                                                    if ($completada) {
                                                        $segundos_totales = isset($progreso_global[$uid][$lec['id']]['time_spent_seconds']) ? (int)$progreso_global[$uid][$lec['id']]['time_spent_seconds'] : 0;
                                                        $minutos = floor($segundos_totales / 60);
                                                        $segundos_restantes = $segundos_totales % 60;
                                                        $tiempo_formateado = sprintf("%02d:%02d", $minutos, $segundos_restantes);
                                                        
                                                        // CORRECCIÓN: Quitamos la referencia a la fecha (created_at)
                                                        $tiempo_txt = "Tiempo: " . $tiempo_formateado . " min";
                                                    } else {
                                                        $tiempo_txt = "Pendiente";
                                                    }
                                                ?>
                                                    <li class="lesson-item">
                                                        <span>
                                                            <?php echo $completada ? '<i class="fa-solid fa-circle-check" style="color:#166534;"></i>' : '<i class="fa-regular fa-circle" style="color:#cbd5e1;"></i>'; ?> 
                                                            <?php echo htmlspecialchars($lec['title']); ?>
                                                        </span>
                                                        <span class="lesson-time"><i class="fa-regular fa-clock"></i> <?php echo $tiempo_txt; ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        function toggleProgress(rowId) {
            const row = document.getElementById(rowId);
            if (row) {
                if (row.style.display === 'none' || row.style.display === '') {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>