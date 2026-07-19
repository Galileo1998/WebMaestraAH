<?php
// Configuración de errores para diagnóstico y tiempo límite para generación pesada
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);

session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$msg = "";

// =========================================================
// 1. GUARDAR LA EVALUACIÓN (VERSIÓN SEGURA CON NOTA MÍNIMA)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_quiz') {
    try {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $course_id     = (int)$_POST['course_id'];
        $quiz_type     = $_POST['quiz_type'];
        $title         = trim($_POST['quiz_title']);
        $passing_score = (int)$_POST['passing_score']; // Campo nuevo capturado
        $lesson_id     = ($quiz_type === 'lesson_quiz' && !empty($_POST['lesson_id'])) ? (int)$_POST['lesson_id'] : null;

        $db->beginTransaction();
        
        // Insertamos con el passing_score
        $stmt_q = $db->prepare("INSERT INTO ah_quizzes (course_id, lesson_id, quiz_type, title, passing_score) VALUES (:cid, :lid, :qt, :tit, :ps)");
        $stmt_q->execute(['cid' => $course_id, 'lid' => $lesson_id, 'qt' => $quiz_type, 'tit' => $title, 'ps' => $passing_score]);
        $quiz_id = $db->lastInsertId();

        if (isset($_POST['questions']) && is_array($_POST['questions'])) {
            foreach ($_POST['questions'] as $q_data) {
                if (empty($q_data['text'])) continue;
                $stmt_ques = $db->prepare("INSERT INTO ah_questions (quiz_id, question_text, feedback_text) VALUES (:qid, :qtxt, :fb)");
                $stmt_ques->execute(['qid' => $quiz_id, 'qtxt' => $q_data['text'], 'fb' => $q_data['feedback']]);
                $question_id = $db->lastInsertId();

                if (isset($q_data['options'])) {
                    foreach ($q_data['options'] as $o_index => $o_text) {
                        $is_correct = (isset($q_data['correct']) && $q_data['correct'] == $o_index) ? 1 : 0;
                        $stmt_opt = $db->prepare("INSERT INTO ah_options (question_id, option_text, is_correct) VALUES (:qid, :otxt, :isc)");
                        $stmt_opt->execute(['qid' => $question_id, 'otxt' => $o_text, 'isc' => $is_correct]);
                    }
                }
            }
        }
        $db->commit();
        $msg = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> Evaluación guardada con nota mínima de $passing_score%.</div>";
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $msg = "<div class='alert error'>Error al guardar: " . $e->getMessage() . "</div>";
    }
}

// ==========================================
// 2. OBTENER CURSOS Y LECCIONES
// ==========================================
$stmt_courses = $db->query("SELECT id, title FROM ah_courses WHERE status = 'published' ORDER BY title ASC");
$cursos = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

$lecciones_por_curso = [];
$stmt_lessons = $db->query("SELECT l.id, m.course_id, l.title, l.content_html FROM ah_lessons l JOIN ah_modules m ON l.module_id = m.id ORDER BY l.sort_order ASC");
while ($row = $stmt_lessons->fetch(PDO::FETCH_ASSOC)) {
    $row['plain_text'] = trim(strip_tags($row['content_html']));
    $lecciones_por_curso[$row['course_id']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generador IA | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; margin-top: 5px; }
        .btn-ai { background: var(--ah-primary); color: white; padding: 12px; border: none; border-radius: 6px; cursor: pointer; width: 100%; font-weight: bold; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 6px; }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }
        .question-card { border: 1px solid #e2e8f0; padding: 20px; margin-top: 20px; border-radius: 8px; background: #fdfdfd; }
        .option-row { display: flex; align-items: center; gap: 10px; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fa-solid fa-brain"></i> Generador de Evaluaciones IA</h2>
        <?php echo $msg; ?>
        <div id="config-panel">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                <select id="sel-course" class="form-control" onchange="updateLessonDropdown()">
                    <option value="">Selecciona Curso</option>
                    <?php foreach($cursos as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['title']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="sel-type" class="form-control" onchange="toggleLessonField()">
                    <option value="lesson_quiz">Quiz de Lección</option>
                    <option value="pre_test">Prueba Diagnóstica</option>
                    <option value="final_exam">Examen Final</option>
                </select>
            </div>
            <select id="sel-lesson" class="form-control" style="margin-top:15px; display:none;">
                <option value="">Selecciona Lección</option>
            </select>
            <button class="btn-ai" id="btn-generate" onclick="generateWithAI()" style="margin-top:15px;">Generar con IA (10 Preguntas)</button>
        </div>

        <form method="POST" id="editor-panel" style="display:none; margin-top: 30px;">
            <input type="hidden" name="action" value="save_quiz">
            <input type="hidden" name="course_id" id="post-course-id">
            <input type="hidden" name="quiz_type" id="post-quiz-type">
            <input type="hidden" name="lesson_id" id="post-lesson-id">
            
            <label>Título de la Evaluación:</label>
            <input type="text" name="quiz_title" id="post-title" class="form-control" style="font-size:1.2rem; font-weight:bold;">
            
            <div style="margin-top: 15px; background: #f1f5f9; padding: 15px; border-radius: 8px; border-left: 4px solid var(--ah-accent);">
                <label style="font-weight: bold; color: #0f172a; font-size: 0.95rem;">
                    <i class="fa-solid fa-bullseye"></i> Calificación mínima para aprobar (%):
                </label>
                <input type="number" name="passing_score" class="form-control" value="70" min="1" max="100" required style="width: 100px; display: inline-block; margin-left: 10px; font-weight: bold; text-align: center;">
            </div>

            <div id="questions-container"></div>
            <button type="submit" class="btn-ai" style="background:var(--ah-accent); margin-top:20px;">Guardar Evaluación</button>
        </form>
    </div>

    <script>
        const lessonsData = <?php echo json_encode($lecciones_por_curso); ?>;
        function updateLessonDropdown() {
            const courseId = document.getElementById('sel-course').value;
            const selLesson = document.getElementById('sel-lesson');
            selLesson.innerHTML = '<option value="">Selecciona Lección</option>';
            if(courseId && lessonsData[courseId]) {
                lessonsData[courseId].forEach(l => {
                    let opt = document.createElement('option'); opt.value = l.id; opt.text = l.title;
                    opt.dataset.text = l.plain_text; selLesson.appendChild(opt);
                });
            }
        }
        function toggleLessonField() {
            const type = document.getElementById('sel-type').value;
            document.getElementById('sel-lesson').style.display = (type === 'lesson_quiz') ? 'block' : 'none';
        }

        function generateWithAI() {
            const courseId = document.getElementById('sel-course').value;
            const type = document.getElementById('sel-type').value;
            const lesson = document.getElementById('sel-lesson');
            
            if(!courseId) { alert("Selecciona un curso primero"); return; }
            
            let context = "";
            if(type === 'lesson_quiz' && lesson.value) {
                context = lesson.options[lesson.selectedIndex].dataset.text;
                document.getElementById('post-title').value = "Quiz: " + lesson.options[lesson.selectedIndex].text;
            } else {
                lessonsData[courseId]?.forEach(l => { context += l.plain_text + "\n"; });
                document.getElementById('post-title').value = (type === 'final_exam') ? "Examen Final" : "Prueba Diagnóstica";
            }

            document.getElementById('btn-generate').innerText = "Generando preguntas con IA...";
            
            const prompt = `Genera 10 preguntas de opción múltiple (JSON puro) sobre este contenido: ${context.substring(0, 3000)}. 
            Estructura exacta: [{"pregunta":"...","feedback":"...","opciones":[{"texto":"...","correcta":1/0},...]}]. 
            Cada pregunta debe tener 1 correcta y 3 incorrectas. No añadas texto antes ni después.`;

            fetch('../api/ia_chat.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ message: prompt })
            })
            .then(res => res.json())
            .then(data => {
                let jsonStr = data.choices[0].message.content;
                const start = jsonStr.indexOf('[');
                const end = jsonStr.lastIndexOf(']');
                jsonStr = jsonStr.substring(start, end + 1);
                
                const questions = JSON.parse(jsonStr);
                buildUI(questions);
                document.getElementById('btn-generate').innerText = "Generar con IA (10 Preguntas)";
            })
            .catch(e => { alert("Error IA: " + e); document.getElementById('btn-generate').innerText = "Generar con IA (10 Preguntas)"; });
        }

        function buildUI(questions) {
            document.getElementById('editor-panel').style.display = 'block';
            document.getElementById('post-course-id').value = document.getElementById('sel-course').value;
            document.getElementById('post-quiz-type').value = document.getElementById('sel-type').value;
            document.getElementById('post-lesson-id').value = document.getElementById('sel-lesson').value;
            
            const container = document.getElementById('questions-container');
            container.innerHTML = '<h3>Preguntas Generadas</h3>';
            questions.forEach((q, i) => {
                let opts = '';
                q.opciones.forEach((o, j) => {
                    opts += `<div class="option-row"><input type="radio" name="questions[${i}][correct]" value="${j}" ${o.correcta?'checked':''}> <input type="text" name="questions[${i}][options][${j}]" value="${o.texto}" class="form-control"></div>`;
                });
                container.innerHTML += `<div class="question-card"><input type="text" name="questions[${i}][text]" value="${q.pregunta}" class="form-control">${opts}<input type="text" name="questions[${i}][feedback]" value="${q.feedback}" class="form-control" placeholder="Feedback" style="margin-top:10px;"></div>`;
            });
        }
    </script>
</body>
</html>