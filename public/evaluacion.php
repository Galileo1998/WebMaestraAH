<?php
session_start();
$lastActivity = (int)($_SESSION['last_activity'] ?? time());
if ((time() - $lastActivity) > 1800) {
    $_SESSION = [];
    session_destroy();
    header('Location: acceso.php?sesion=expirada'); exit;
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'student') {
    header("Location: acceso.php"); exit;
}
require_once __DIR__ . '/../config/Database.php';

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$quiz_id = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
$curso_slug = isset($_GET['curso']) ? $_GET['curso'] : '';
$next_lesson_id = isset($_GET['next']) ? (int)$_GET['next'] : 0;

if ($quiz_id === 0) { die("Evaluación no válida."); }

// 1. Cargar datos del examen
$stmt_q = $db->prepare("SELECT * FROM ah_quizzes WHERE id = :qid LIMIT 1");
$stmt_q->execute(['qid' => $quiz_id]);
$quiz = $stmt_q->fetch(PDO::FETCH_ASSOC);

if (!$quiz) { die("Evaluación no encontrada."); }

// 2. Cargar preguntas y opciones mezcladas
$stmt_ques = $db->prepare("SELECT * FROM ah_questions WHERE quiz_id = :qid");
$stmt_ques->execute(['qid' => $quiz_id]);
$preguntas = $stmt_ques->fetchAll(PDO::FETCH_ASSOC);

$opciones_por_pregunta = [];
$stmt_opt = $db->prepare("SELECT * FROM ah_options WHERE question_id IN (SELECT id FROM ah_questions WHERE quiz_id = :qid)");
$stmt_opt->execute(['qid' => $quiz_id]);
while ($opt = $stmt_opt->fetch(PDO::FETCH_ASSOC)) {
    $opciones_por_pregunta[$opt['question_id']][] = $opt;
}

$mostrar_resultados = false;
$score = 0;
$aprobado = false;
$feedback_html = "";

// 3. PROCESAR LAS RESPUESTAS AL ENVIAR EL FORMULARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $respuestas_usuario = isset($_POST['answers']) ? $_POST['answers'] : [];
    $correctas = 0;
    $total_preguntas = count($preguntas);

    foreach ($preguntas as $index => $p) {
        $q_id = $p['id'];
        $respuesta_elegida = isset($respuestas_usuario[$q_id]) ? (int)$respuestas_usuario[$q_id] : -1;
        
        $es_correcta = false;
        foreach ($opciones_por_pregunta[$q_id] as $opcion) {
            if ($opcion['id'] == $respuesta_elegida && $opcion['is_correct'] == 1) {
                $es_correcta = true;
                $correctas++;
                break;
            }
        }

        // Si falló, preparamos la retroalimentación inteligente
        if (!$es_correcta) {
            $feedback_html .= "<div class='feedback-item'><i class='fa-solid fa-circle-exclamation'></i> <strong>En la pregunta " . ($index + 1) . ":</strong> " . htmlspecialchars($p['feedback_text']) . "</div>";
        }
    }

    $score = $total_preguntas > 0 ? round(($correctas / $total_preguntas) * 100) : 0;
    $aprobado = $score >= $quiz['passing_score'];

    // Guardar el intento en el registro histórico
    $stmt_attempt = $db->prepare("INSERT INTO ah_quiz_attempts (user_id, quiz_id, score, passed) VALUES (:uid, :qid, :score, :passed)");
    $stmt_attempt->execute(['uid' => $user_id, 'qid' => $quiz_id, 'score' => $score, 'passed' => $aprobado ? 1 : 0]);

    // Si aprobó y era un quiz formativo (de lección), marcamos la lección como completada
    if ($aprobado && $quiz['quiz_type'] == 'lesson_quiz' && !empty($quiz['lesson_id'])) {
        $stmt_chk = $db->prepare("SELECT id FROM ah_student_progress WHERE user_id = :u AND lesson_id = :l");
        $stmt_chk->execute(['u' => $user_id, 'l' => $quiz['lesson_id']]);
        if (!$stmt_chk->fetch()) {
            $stmt_ins = $db->prepare("INSERT INTO ah_student_progress (user_id, course_id, lesson_id) VALUES (:u, :c, :l)");
            $stmt_ins->execute(['u' => $user_id, 'c' => $quiz['course_id'], 'l' => $quiz['lesson_id']]);
        }
    }

    $mostrar_resultados = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluación | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; }
        body { background: #0f172a; font-family: 'Inter', sans-serif; margin: 0; padding: 0; overflow: hidden; }
        
        /* Layout Principal dividido */
        /* 1. Bloqueamos el contenedor padre para que jamás pase del 100% de la pantalla */
        .eval-layout { display: grid; grid-template-columns: 1fr 380px; height: 100vh; max-height: 100vh; width: 100vw; background: #f8fafc; overflow: hidden; }
        
        /* 2. La columna izquierda (examen) scrollea de forma independiente */
        .eval-content { height: 100vh; overflow-y: auto; padding: 40px; display: flex; flex-direction: column; align-items: center; box-sizing: border-box; }
        
        /* 3. La columna derecha (Tutor) se ancla rígidamente a la pantalla */
        .eval-sidebar-right { height: 100vh; max-height: 100vh; background: #ffffff; display: flex; flex-direction: column; box-shadow: -5px 0 25px rgba(15, 23, 42, 0.03); border-left: 1px solid #e2e8f0; overflow: hidden; box-sizing: border-box; }

        /* 4. LA MAGIA: Forzamos al chat a calcular su espacio desde 0, obligándolo a hacer scroll */
        .ai-chat-space { flex: 1 1 0; height: 0; padding: 25px 20px; display: flex; flex-direction: column; gap: 20px; background: #fdfefe; overflow-y: auto; scroll-behavior: smooth; }
        
        .quiz-container { width: 100%; max-width: 800px; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .quiz-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; }
        .quiz-header h1 { color: #0f172a; margin: 0 0 10px 0; font-weight: 800; }
        .quiz-meta { color: #64748b; font-size: 0.9rem; font-weight: 600; }
        
        .question-block { margin-bottom: 30px; background: #f1f5f9; padding: 25px; border-radius: 8px; border-left: 5px solid var(--ah-primary); }
        .question-text { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin-bottom: 20px; }
        
        .option-label { display: block; padding: 15px; background: white; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 12px; cursor: pointer; transition: 0.2s; font-size: 1.05rem; color: #334155;}
        .option-label:hover { border-color: var(--ah-primary); background: #f0fdfa; transform: translateX(5px); }
        .option-label input { margin-right: 12px; accent-color: var(--ah-accent); transform: scale(1.2); }
        
        .btn-submit { background: var(--ah-primary); color: white; border: none; padding: 16px 30px; font-size: 1.1rem; font-weight: bold; border-radius: 8px; cursor: pointer; width: 100%; transition: 0.2s; box-shadow: 0 4px 15px rgba(52,133,155,0.2); }
        .btn-submit:hover { background: #2c7285; transform: translateY(-2px); }
        
        .result-box { text-align: center; padding: 20px; }
        .score-circle { width: 160px; height: 160px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 3.5rem; font-weight: 800; margin: 0 auto 25px auto; color: white; }
        .score-pass { background: var(--ah-accent); box-shadow: 0 0 25px rgba(70,176,148,0.4); }
        .score-fail { background: #ef4444; box-shadow: 0 0 25px rgba(239,68,68,0.4); }
        
        .btn-action { display: inline-flex; align-items: center; justify-content: center; gap: 10px; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; color: white; margin-top: 30px; transition: 0.2s; font-size: 1.1rem; width: 100%; box-sizing: border-box; }
        .btn-action:hover { transform: translateY(-2px); opacity: 0.9; }

        /* Estilos del Chat IA (CORREGIDOS PARA EVITAR DESBORDAMIENTO) */
        .eval-sidebar-right { background: #ffffff; display: flex; flex-direction: column; box-shadow: -5px 0 25px rgba(15, 23, 42, 0.03); border-left: 1px solid #e2e8f0; height: 100%; }
        .ai-header { padding: 22px 20px; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #0f172a 0%, var(--ah-primary) 100%); color: #ffffff; flex-shrink: 0; }
        .ai-header h3 { margin: 0; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .ai-header p { margin: 5px 0 0 0; font-size: 0.8rem; color: #cbd5e1; }
        
        /* CORRECCIÓN: min-height: 0; permite que el chat scrollee en lugar de estirar la pantalla infinítamente */
        .ai-chat-space { flex-grow: 1; min-height: 0; padding: 25px 20px; display: flex; flex-direction: column; gap: 20px; background: #fdfefe; overflow-y: auto; scroll-behavior: smooth; }
        
        /* CORRECCIÓN: overflow-wrap: anywhere; evita que palabras largas se salgan de la burbuja */
        .chat-bubble { max-width: 88%; padding: 14px 18px; border-radius: 16px; font-size: 0.92rem; line-height: 1.5; word-wrap: break-word; overflow-wrap: anywhere; }
        
        .chat-bubble.user { background: linear-gradient(135deg, var(--ah-primary) 0%, #2c7285 100%); color: white; align-self: flex-end; border-bottom-right-radius: 3px; }
        .chat-bubble.bot { background: #ffffff; color: #1e293b; align-self: flex-start; border-bottom-left-radius: 3px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.02); }
        
        .ai-input-area { padding: 20px; border-top: 1px solid #e2e8f0; background: white; display: flex; gap: 12px; align-items: center; flex-shrink: 0; }
        .ai-input-wrapper { position: relative; flex-grow: 1; }
        .ai-input-wrapper input { width: 100%; padding: 14px 16px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.92rem; box-sizing: border-box; outline: none; background: #f8fafc; }
        .ai-input-wrapper input:focus { border-color: var(--ah-primary); background: white; box-shadow: 0 0 0 4px rgba(52, 133, 155, 0.15); }
        .btn-send { background: var(--ah-primary); color: white; border: none; width: 48px; height: 48px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1rem; }

        @media (max-width: 1000px) {
            .eval-layout { grid-template-columns: 1fr; overflow-y: auto; }
            .eval-content { height: auto; padding: 20px; }
            .eval-sidebar-right { border-left: none; border-top: 2px solid var(--ah-primary); height: 500px; }
        }
    </style>
</head>
<body>

<div class="eval-layout">
    
    <main class="eval-content">
        <div class="quiz-container">
            
            <?php if (!$mostrar_resultados): ?>
                <div class="quiz-header">
                    <h1><?php echo htmlspecialchars($quiz['title']); ?></h1>
                    <div class="quiz-meta"><i class="fa-solid fa-bullseye"></i> Calificación mínima para aprobar: <?php echo $quiz['passing_score']; ?>%</div>
                </div>

                <form method="POST">
                    <?php foreach ($preguntas as $i => $p): ?>
                        <div class="question-block">
                            <div class="question-text"><?php echo ($i+1) . ". " . htmlspecialchars($p['question_text']); ?></div>
                            
                            <?php 
                            // Desordenar opciones para que no siempre sea la misma letra
                            $opciones = $opciones_por_pregunta[$p['id']];
                            shuffle($opciones); 
                            foreach ($opciones as $opt): 
                            ?>
                                <label class="option-label">
                                    <input type="radio" name="answers[<?php echo $p['id']; ?>]" value="<?php echo $opt['id']; ?>" required>
                                    <?php echo htmlspecialchars($opt['option_text']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <button type="submit" name="submit_quiz" class="btn-submit"><i class="fa-solid fa-paper-plane"></i> Enviar mis Respuestas</button>
                </form>

            <?php else: ?>
                <div class="result-box">
                    <div class="score-circle <?php echo $aprobado ? 'score-pass' : 'score-fail'; ?>">
                        <?php echo $score; ?>%
                    </div>
                    
                    <h2 style="color: #0f172a;"><?php echo $aprobado ? '¡Felicidades! Evaluación Superada' : 'Calificación insuficiente'; ?></h2>
                    <p style="color: #64748b; margin-bottom: 30px;">
                        <?php echo $aprobado ? "Has demostrado dominio sobre este tema." : "No te preocupes, el aprendizaje es un proceso. Revisa tus respuestas a continuación."; ?>
                    </p>

                    <div class="review-section" style="text-align: left; margin-top: 40px;">
                        <h3 style="border-bottom: 2px solid var(--ah-primary); padding-bottom: 10px;">Revisión de Respuestas:</h3>
                        
                        <?php 
                        $respuestas_usuario = isset($_POST['answers']) ? $_POST['answers'] : [];
                        foreach ($preguntas as $i => $p): 
                            $q_id = $p['id'];
                            $usuario_elegido = isset($respuestas_usuario[$q_id]) ? (int)$respuestas_usuario[$q_id] : -1;
                        ?>
                            <div class="question-block" style="border-left: 5px solid #e2e8f0;">
                                <div class="question-text" style="margin-bottom: 15px;"><?php echo ($i+1) . ". " . htmlspecialchars($p['question_text']); ?></div>
                                
                                <?php foreach ($opciones_por_pregunta[$q_id] as $opt): 
                                    $es_opcion_correcta = ($opt['is_correct'] == 1);
                                    $es_opcion_elegida = ($opt['id'] == $usuario_elegido);
                                    
                                    // Estilos dinámicos
                                    $estilo = "background: white;";
                                    if ($es_opcion_correcta) { $estilo = "background: #dcfce7; border-color: #46B094;"; } 
                                    elseif ($es_opcion_elegida && !$es_opcion_correcta) { $estilo = "background: #fee2e2; border-color: #ef4444;"; }
                                ?>
                                    <div class="option-label" style="<?php echo $estilo; ?> margin-bottom: 5px;">
                                        <?php echo htmlspecialchars($opt['option_text']); ?>
                                        <?php if($es_opcion_elegida) echo ' <i class="fa-solid fa-user-check"></i>'; ?>
                                        <?php if($es_opcion_correcta) echo ' <i class="fa-solid fa-check" style="color:#166534;"></i>'; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php 
                                    // Verificamos si falló para mostrar el feedback
                                    $usuario_acerto = false;
                                    foreach($opciones_por_pregunta[$q_id] as $opt) {
                                        if($opt['id'] == $usuario_elegido && $opt['is_correct'] == 1) $usuario_acerto = true;
                                    }
                                    if (!$usuario_acerto): 
                                ?>
                                    <div style="margin-top: 15px; padding: 10px; background: #fef2f2; border-radius: 6px; font-size: 0.9rem; color: #991b1b;">
                                        <strong><i class="fa-solid fa-robot"></i> Explicación:</strong> <?php echo htmlspecialchars($p['feedback_text']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($aprobado): ?>
                        <?php if ($next_lesson_id > 0): ?>
                            <a href="aula.php?curso=<?php echo urlencode($curso_slug); ?>&leccion=<?php echo $next_lesson_id; ?>" class="btn-action" style="background:var(--ah-accent);">Continuar a la siguiente lección <i class="fa-solid fa-arrow-right"></i></a>
                        <?php else: ?>
                            <a href="certificado.php?curso=<?php echo urlencode($curso_slug); ?>" class="btn-action" style="background:var(--ah-primary);"><i class="fa-solid fa-award"></i> Generar mi Diploma Oficial</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="evaluacion.php?quiz_id=<?php echo $quiz_id; ?>&curso=<?php echo urlencode($curso_slug); ?>&next=<?php echo $next_lesson_id; ?>" class="btn-action" style="background:#ef4444;"><i class="fa-solid fa-rotate-right"></i> Intentar Nuevamente</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <aside class="eval-sidebar-right">
        <div class="ai-header">
            <h3><i class="fa-solid fa-chalkboard-user" style="color: var(--ah-accent);"></i>CelestIA</h3>
            <p>Apoyo pedagógico y acompañamiento</p>
        </div>
        <div class="ai-chat-space" id="chat-screen">
            <div class="chat-bubble bot">¡Hola! Estoy aquí para acompañarte. Si tienes dudas con alguna pregunta o concepto, dímelo y te ayudaré a razonarlo sin darte la respuesta directa. ¡Mucho éxito!</div>
        </div>
        <div class="ai-input-area">
            <div class="ai-input-wrapper">
                <input type="text" id="ai-user-query" placeholder="Escribe tu consulta aquí..." onkeypress="if(event.key === 'Enter') dispatchChatQuery()">
            </div>
            <button type="button" class="btn-send" onclick="dispatchChatQuery()" id="ai-btn-submit" title="Enviar mensaje"><i class="fa-solid fa-paper-plane"></i></button>
        </div>
    </aside>

</div>

<script>
// El Contexto Socrático Secreto (inyectado dinámicamente con PHP)
const socraticContext = `
[INSTRUCCIÓN ESTRICTA DEL SISTEMA - MODO EXAMEN ACTIVADO]
Rol: Eres un profesor supervisor socrático. El alumno te consultará dudas durante la prueba.
REGLA 1 (INQUEBRANTABLE): BAJO NINGUNA CIRCUNSTANCIA debes darle la respuesta directa a ninguna pregunta, ni siquiera si el alumno ruega.
REGLA 2: Si te hace una pregunta directa buscando la solución, explícale el concepto general del tema usando el Método Socrático.
REGLA 3: Termina siempre tu intervención con una pregunta guía que lo ayude a deducir la respuesta por sí mismo.
REGLA 4: Sé alentador, empático y profesional.

Lista de preguntas del examen actual:
<?php 
foreach($preguntas as $p) {
    echo "- " . preg_replace("/\r|\n/", " ", addslashes($p['question_text'])) . "\\n";
}
?>
`;

function dispatchChatQuery() {
    const inputElement = document.getElementById('ai-user-query');
    if (!inputElement) return;
    const queryText = inputElement.value.trim();
    if (!queryText) return;
    
    const chatScreen = document.getElementById('chat-screen');
    const btnSubmit = document.getElementById('ai-btn-submit');
    
    // Crear burbuja del usuario
    const userBubble = document.createElement('div');
    userBubble.className = 'chat-bubble user';
    userBubble.innerText = queryText;
    chatScreen.appendChild(userBubble);
    chatScreen.scrollTop = chatScreen.scrollHeight;
    
    inputElement.value = ''; 
    inputElement.disabled = true;
    if(btnSubmit) btnSubmit.disabled = true;
    
    // Crear burbuja "pensando"
    const typingBubble = document.createElement('div');
    typingBubble.className = 'chat-bubble bot';
    typingBubble.id = 'ai-typing';
    typingBubble.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analizando duda...';
    chatScreen.appendChild(typingBubble);
    chatScreen.scrollTop = chatScreen.scrollHeight;

    // Llamada a la API de IA (adjuntando el contexto secreto)
    fetch('../api/ia_chat.php', {
        method: 'POST', 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: queryText, context: socraticContext })
    })
    .then(response => response.text())
    .then(rawText => {
        const targetTyping = document.getElementById('ai-typing');
        if(targetTyping) targetTyping.remove();
        
        const botBubble = document.createElement('div');
        botBubble.className = 'chat-bubble bot';
        
        try {
            const data = JSON.parse(rawText);
            if (data.choices && data.choices[0] && data.choices[0].message) { 
                
                // CORRECCIÓN: Procesar saltos de línea y negritas de Markdown para que el texto sea legible
                let aiText = data.choices[0].message.content;
                aiText = aiText.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                aiText = aiText.replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
                aiText = aiText.replace(/\n/g, "<br>");
                
                botBubble.innerHTML = aiText; 
                
            } else if (data.error) { 
                botBubble.style.color = '#ef4444'; 
                botBubble.innerText = "Error: " + (typeof data.error === 'object' ? JSON.stringify(data.error) : data.error); 
            }
        } catch(e) { 
            botBubble.style.color = '#ef4444'; 
            botBubble.innerText = "Respuesta del servidor no válida."; 
        }
        
        chatScreen.appendChild(botBubble);
        chatScreen.scrollTop = chatScreen.scrollHeight;
    })
    .catch(error => {
        const targetTyping = document.getElementById('ai-typing');
        if(targetTyping) targetTyping.remove();
        
        const errorBubble = document.createElement('div');
        errorBubble.className = 'chat-bubble bot';
        errorBubble.style.color = '#ef4444';
        errorBubble.innerText = "Fallo de red: " + error.message;
        chatScreen.appendChild(errorBubble);
        chatScreen.scrollTop = chatScreen.scrollHeight;
    })
    .finally(() => {
        inputElement.disabled = false;
        if(btnSubmit) btnSubmit.disabled = false;
        inputElement.focus();
    });
}
</script>

</body>
</html>
