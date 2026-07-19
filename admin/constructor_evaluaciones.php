<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$msg = "";

// ==========================================
// 1. GUARDAR LA EVALUACIÓN EN LA BASE DE DATOS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_quiz') {
    $course_id = (int)$_POST['course_id'];
    $quiz_type = $_POST['quiz_type']; // 'pre_test', 'lesson_quiz', 'final_exam'
    $lesson_id = ($quiz_type === 'lesson_quiz' && isset($_POST['lesson_id'])) ? (int)$_POST['lesson_id'] : null;
    $title = trim($_POST['quiz_title']);
    $passing_score = (int)$_POST['passing_score'];

    try {
        $db->beginTransaction();

        // A. Insertar el Cuestionario
        $stmt_q = $db->prepare("INSERT INTO ah_quizzes (course_id, lesson_id, quiz_type, title, passing_score) VALUES (:cid, :lid, :qt, :tit, :ps)");
        $stmt_q->execute(['cid' => $course_id, 'lid' => $lesson_id, 'qt' => $quiz_type, 'tit' => $title, 'ps' => $passing_score]);
        $quiz_id = $db->lastInsertId();

        // B. Insertar Preguntas y Opciones
        if (isset($_POST['questions']) && is_array($_POST['questions'])) {
            foreach ($_POST['questions'] as $q_index => $q_data) {
                $q_text = trim($q_data['text']);
                $q_feedback = trim($q_data['feedback']);
                
                if (empty($q_text)) continue;

                $stmt_ques = $db->prepare("INSERT INTO ah_questions (quiz_id, question_text, feedback_text) VALUES (:qid, :qtxt, :fb)");
                $stmt_ques->execute(['qid' => $quiz_id, 'qtxt' => $q_text, 'fb' => $q_feedback]);
                $question_id = $db->lastInsertId();

                // Insertar las 3 o 4 opciones
                if (isset($q_data['options']) && is_array($q_data['options'])) {
                    foreach ($q_data['options'] as $o_index => $o_text) {
                        $o_text = trim($o_text);
                        if (empty($o_text)) continue;
                        
                        // Verificar cuál es la correcta
                        $is_correct = (isset($q_data['correct']) && $q_data['correct'] == $o_index) ? 1 : 0;

                        $stmt_opt = $db->prepare("INSERT INTO ah_options (question_id, option_text, is_correct) VALUES (:qid, :otxt, :isc)");
                        $stmt_opt->execute(['qid' => $question_id, 'otxt' => $o_text, 'isc' => $is_correct]);
                    }
                }
            }
        }

        $db->commit();
        $msg = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> Evaluación generada y guardada con éxito.</div>";
    } catch (Exception $e) {
        $db->rollBack();
        $msg = "<div class='alert error'>Error al guardar: " . $e->getMessage() . "</div>";
    }
}

// ==========================================
// 2. OBTENER CURSOS Y LECCIONES PARA EL MENÚ
// ==========================================
$stmt_courses = $db->query("SELECT id, title FROM ah_courses WHERE status = 'published' ORDER BY title ASC");
$cursos = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

$lecciones_por_curso = [];
$stmt_lessons = $db->query("SELECT id, course_id, title, content_html FROM ah_lessons ORDER BY sort_order ASC");
while ($row = $stmt_lessons->fetch(PDO::FETCH_ASSOC)) {
    // Limpiamos el HTML para mandarle solo texto puro a la IA
    $row['plain_text'] = trim(strip_tags($row['content_html']));
    $lecciones_por_curso[$row['course_id']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generador de Evaluaciones IA | Admin Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; padding: 0; color: #1e293b; }
        
        .header-bar { background: white; padding: 20px 40px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
        .header-title { margin: 0; font-size: 1.4rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        
        .container { max-width: 1000px; margin: 40px auto; background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid var(--border); overflow: hidden; }
        
        .config-panel { padding: 30px; background: #fdfefe; border-bottom: 1px solid var(--border); }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 8px; text-transform: uppercase; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-family: inherit; font-size: 0.95rem; background: #f8fafc; }
        .form-control:focus { border-color: var(--ah-primary); outline: none; background: white; }
        
        .btn-ai { background: linear-gradient(135deg, var(--ah-primary) 0%, #2c7285 100%); color: white; border: none; padding: 14px 25px; border-radius: 8px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; transition: 0.2s; font-size: 1rem; width: 100%; justify-content: center; margin-top: 15px; }
        .btn-ai:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(52, 133, 155, 0.2); }
        .btn-ai:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }

        .editor-panel { padding: 30px; background: #f1f5f9; display: none; }
        .question-card { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .question-header { font-weight: 800; color: var(--ah-primary); margin-bottom: 15px; display: flex; justify-content: space-between; border-bottom: 2px solid var(--border); padding-bottom: 10px; }
        
        .option-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .option-radio { width: 20px; height: 20px; accent-color: var(--ah-accent); cursor: pointer; }
        
        .btn-save { background: var(--ah-accent); color: white; border: none; padding: 15px 30px; border-radius: 8px; font-size: 1.1rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; margin-top: 20px; width: 100%; justify-content: center; }
        .btn-save:hover { background: #358f77; }

        .alert { padding: 15px; border-radius: 8px; margin: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Loader de IA */
        .ai-loader { display: none; text-align: center; padding: 40px; color: var(--ah-primary); }
        .ai-loader i { font-size: 3rem; margin-bottom: 15px; }
    </style>
</head>
<body>

    <div class="header-bar">
        <h1 class="header-title"><i class="fa-solid fa-brain" style="color: var(--ah-accent);"></i> Generador de Evaluaciones IA</h1>
        <a href="cursos.php" style="color: #64748b; text-decoration: none; font-weight: bold;"><i class="fa-solid fa-arrow-left"></i> Volver a Cursos</a>
    </div>

    <?php echo $msg; ?>

    <div class="container">
        <div class="config-panel">
            <div class="grid-3">
                <div class="form-group">
                    <label>Curso Objetivo</label>
                    <select id="sel-course" class="form-control" onchange="updateLessonDropdown()">
                        <option value="">-- Selecciona un curso --</option>
                        <?php foreach($cursos as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Tipo de Evaluación</label>
                    <select id="sel-type" class="form-control" onchange="toggleLessonField()">
                        <option value="lesson_quiz">Quiz de Lección (Formativa)</option>
                        <option value="pre_test">Prueba Diagnóstica (Pre-Test)</option>
                        <option value="final_exam">Examen Final (Sumativa)</option>
                    </select>
                </div>

                <div class="form-group" id="grp-lesson">
                    <label>Lección Específica</label>
                    <select id="sel-lesson" class="form-control">
                        <option value="">-- Selecciona curso primero --</option>
                    </select>
                </div>
            </div>

            <button type="button" class="btn-ai" id="btn-generate" onclick="generateWithAI()">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Analizar Contenido y Generar Preguntas
            </button>
        </div>

        <div class="ai-loader" id="ai-loader">
            <i class="fa-solid fa-microchip fa-fade"></i>
            <h3>La IA está analizando el material pedagógico...</h3>
            <p style="color:#64748b;">Diseñando preguntas, opciones y retroalimentación educativa.</p>
        </div>

        <form method="POST" class="editor-panel" id="editor-panel">
            <input type="hidden" name="action" value="save_quiz">
            <input type="hidden" name="course_id" id="post-course-id">
            <input type="hidden" name="quiz_type" id="post-quiz-type">
            <input type="hidden" name="lesson_id" id="post-lesson-id">

            <div class="grid-3" style="margin-bottom: 30px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label>Título Visible para el Alumno</label>
                    <input type="text" name="quiz_title" id="post-title" class="form-control" required placeholder="Ej: Comprobación de Saberes - Módulo 1">
                </div>
                <div class="form-group">
                    <label>Nota Mínima para Aprobar (%)</label>
                    <input type="number" name="passing_score" class="form-control" value="70" required min="1" max="100">
                </div>
            </div>

            <div id="questions-container">
                </div>

            <button type="submit" class="btn-save"><i class="fa-solid fa-database"></i> Aprobar y Guardar Evaluación en la Academia</button>
        </form>
    </div>

    <script>
        const lessonsData = <?php echo json_encode($lecciones_por_curso); ?>;
        
        function updateLessonDropdown() {
            const courseId = document.getElementById('sel-course').value;
            const selLesson = document.getElementById('sel-lesson');
            selLesson.innerHTML = '<option value="">-- Selecciona una lección --</option>';
            
            if(courseId && lessonsData[courseId]) {
                lessonsData[courseId].forEach(l => {
                    const opt = document.createElement('option');
                    opt.value = l.id;
                    opt.text = l.title;
                    opt.dataset.text = l.plain_text; // Guardamos el texto puro en el HTML
                    selLesson.appendChild(opt);
                });
            }
        }

        function toggleLessonField() {
            const type = document.getElementById('sel-type').value;
            document.getElementById('grp-lesson').style.display = (type === 'lesson_quiz') ? 'block' : 'none';
        }

        // ==============================================================
        // EL CEREBRO IA: Conexión con tu script existente ia_chat.php
        // ==============================================================
        function generateWithAI() {
            const courseId = document.getElementById('sel-course').value;
            const type = document.getElementById('sel-type').value;
            const lessonSelect = document.getElementById('sel-lesson');
            
            if(!courseId) { alert("Selecciona un curso."); return; }
            if(type === 'lesson_quiz' && !lessonSelect.value) { alert("Selecciona una lección para analizar."); return; }

            // Extraer el texto que la IA debe leer
            let materialContext = "";
            if(type === 'lesson_quiz') {
                materialContext = lessonSelect.options[lessonSelect.selectedIndex].dataset.text;
                document.getElementById('post-title').value = "Evaluación: " + lessonSelect.options[lessonSelect.selectedIndex].text;
            } else {
                // Si es un Pre-Test o Examen Final, le mandamos el texto de TODAS las lecciones del curso
                if(lessonsData[courseId]) {
                    lessonsData[courseId].forEach(l => { materialContext += l.plain_text + "\n\n"; });
                }
                document.getElementById('post-title').value = type === 'pre_test' ? "Prueba Diagnóstica del Curso" : "Examen Final de Certificación";
            }

            if(materialContext.trim().length < 50) {
                alert("La lección no tiene suficiente texto para generar preguntas automáticamente.");
                return;
            }

            // Ocultar panel, mostrar loader
            document.getElementById('editor-panel').style.display = 'none';
            document.getElementById('ai-loader').style.display = 'block';
            document.getElementById('btn-generate').disabled = true;

            // Prompt Estricto de Ingeniería (Para obligar a la IA a devolver un JSON exacto)
            const promptIA = `Eres un diseñador instruccional experto. Lee el siguiente contenido educativo y genera un cuestionario de 10 preguntas de opción múltiple (1 correcta, 2 incorrectas). 
            DEVUELVE ÚNICA Y EXCLUSIVAMENTE UN ARRAY JSON VÁLIDO con la siguiente estructura, sin texto adicional, sin formato markdown (\`\`\`json):
            [
              {
                "pregunta": "¿Pregunta aquí?",
                "feedback": "Retroalimentación educativa explicando por qué la correcta es la correcta.",
                "opciones": [
                  {"texto": "Opcion 1", "correcta": 1},
                  {"texto": "Opcion 2", "correcta": 0},
                  {"texto": "Opcion 3", "correcta": 0}
                ]
              }
            ]
            
            CONTENIDO A ANALIZAR: 
            ` + materialContext.substring(0, 3000); // Limitamos a 3000 caracteres por seguridad de tokens

            // Llamada a TU propio archivo de IA
            fetch('../api/ia_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: promptIA, context: "Generación estricta de JSON" })
            })
            .then(res => res.json())
            .then(data => {
                if (data.choices && data.choices[0] && data.choices[0].message) {
                    let jsonText = data.choices[0].message.content;
                    // Limpieza de seguridad por si la IA responde con ```json ... ```
                    jsonText = jsonText.replace(/```json/gi, '').replace(/```/g, '').trim();
                    
                    try {
                        const questionsArray = JSON.parse(jsonText);
                        buildEditorUI(questionsArray);
                    } catch(e) {
                        alert("Error al procesar el formato de la IA. Intenta de nuevo.");
                        console.error("JSON Roto:", jsonText);
                        resetUI();
                    }
                } else {
                    alert("Error en la respuesta de la IA.");
                    resetUI();
                }
            })
            .catch(err => {
                alert("Error de red al conectar con la IA.");
                resetUI();
            });
        }

        // ==============================================================
        // DIBUJAR EL BORRADOR EDITABLE
        // ==============================================================
        function buildEditorUI(questions) {
            document.getElementById('ai-loader').style.display = 'none';
            document.getElementById('editor-panel').style.display = 'block';
            document.getElementById('btn-generate').disabled = false;

            // Rellenar datos ocultos
            document.getElementById('post-course-id').value = document.getElementById('sel-course').value;
            document.getElementById('post-quiz-type').value = document.getElementById('sel-type').value;
            document.getElementById('post-lesson-id').value = document.getElementById('sel-lesson').value;

            const container = document.getElementById('questions-container');
            container.innerHTML = '';

            questions.forEach((q, qIndex) => {
                let optionsHtml = '';
                q.opciones.forEach((opt, oIndex) => {
                    let isChecked = opt.correcta === 1 ? 'checked' : '';
                    optionsHtml += `
                        <div class="option-row">
                            <input type="radio" name="questions[${qIndex}][correct]" value="${oIndex}" class="option-radio" required ${isChecked} title="Marcar como respuesta correcta">
                            <input type="text" name="questions[${qIndex}][options][${oIndex}]" class="form-control" value="${opt.texto.replace(/"/g, '&quot;')}" required>
                        </div>
                    `;
                });

                container.innerHTML += `
                    <div class="question-card">
                        <div class="question-header">Pregunta ${qIndex + 1}</div>
                        <div class="form-group">
                            <label>Enunciado de la Pregunta</label>
                            <input type="text" name="questions[${qIndex}][text]" class="form-control" style="font-weight:bold; color:#0f172a;" value="${q.pregunta.replace(/"/g, '&quot;')}" required>
                        </div>
                        <div class="form-group">
                            <label>Opciones (Selecciona el círculo de la respuesta correcta)</label>
                            ${optionsHtml}
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label><i class="fa-solid fa-comment-dots"></i> Retroalimentación de la IA (Se mostrará si el alumno falla)</label>
                            <input type="text" name="questions[${qIndex}][feedback]" class="form-control" style="font-size:0.85rem; color:#475569;" value="${q.feedback.replace(/"/g, '&quot;')}">
                        </div>
                    </div>
                `;
            });
        }

        function resetUI() {
            document.getElementById('ai-loader').style.display = 'none';
            document.getElementById('btn-generate').disabled = false;
        }
    </script>
</body>
</html>