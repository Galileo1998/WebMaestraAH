<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$lastActivity = (int)($_SESSION['last_activity'] ?? time());
if ((time() - $lastActivity) > 1800) {
    $_SESSION = [];
    session_destroy();
    header('Location: acceso.php?sesion=expirada'); exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'student') {
    $curso_dest = isset($_GET['curso']) ? $_GET['curso'] : '';
    $lec_dest = isset($_GET['leccion']) ? $_GET['leccion'] : '';
    $_SESSION['redirect_to'] = "aula.php?curso=" . urlencode($curso_dest) . "&leccion=" . urlencode($lec_dest);
    header("Location: acceso.php"); exit;
}

require_once __DIR__ . '/../config/Database.php';

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// ========================================================
// 1. LÓGICA DE GUARDADO DE PROGRESO Y TIEMPO (CRONÓMETRO)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    $lesson_to_mark = (int)$_POST['lesson_id'];
    $course_to_mark = (int)$_POST['course_id'];
    $next_lesson_id = (int)$_POST['next_lesson_id'];
    $curso_slug = $_POST['curso_slug'];
    
    // Capturar el tiempo enviado por el cronómetro oculto en JavaScript
    $time_spent = isset($_POST['time_spent_seconds']) ? (int)$_POST['time_spent_seconds'] : 0;

    $stmt_chk = $db->prepare("SELECT id FROM ah_student_progress WHERE user_id = :u AND lesson_id = :l");
    $stmt_chk->execute(['u' => $user_id, 'l' => $lesson_to_mark]);
    
    if (!$stmt_chk->fetch()) {
        // Inserción por primera vez con el tiempo capturado
        $stmt_ins = $db->prepare("INSERT INTO ah_student_progress (user_id, course_id, lesson_id, time_spent_seconds) VALUES (:u, :c, :l, :ts)");
        $stmt_ins->execute(['u' => $user_id, 'c' => $course_to_mark, 'l' => $lesson_to_mark, 'ts' => $time_spent]);
    } else {
        // Si vuelve a ver la lección, sumamos el tiempo nuevo al acumulado histórico
        $stmt_upd = $db->prepare("UPDATE ah_student_progress SET time_spent_seconds = time_spent_seconds + :ts WHERE user_id = :u AND lesson_id = :l");
        $stmt_upd->execute(['ts' => $time_spent, 'u' => $user_id, 'l' => $lesson_to_mark]);
    }

    if ($next_lesson_id > 0) {
        header("Location: aula.php?curso=" . urlencode($curso_slug) . "&leccion=" . $next_lesson_id);
    } else {
        // INTERCEPTOR EXAMEN FINAL
        $stmt_fe = $db->prepare("SELECT id FROM ah_quizzes WHERE course_id = :cid AND quiz_type = 'final_exam' LIMIT 1");
        $stmt_fe->execute(['cid' => $course_to_mark]);
        $final_exam = $stmt_fe->fetch(PDO::FETCH_ASSOC);

        if ($final_exam) {
            // Verificar si YA aprobó el examen final antes
            $stmt_chk_pass = $db->prepare("SELECT id FROM ah_quiz_attempts WHERE user_id = :uid AND quiz_id = :qid AND passed = 1 LIMIT 1");
            $stmt_chk_pass->execute(['uid' => $user_id, 'qid' => $final_exam['id']]);
            
            if ($stmt_chk_pass->fetch()) {
                header("Location: certificado.php?curso=" . urlencode($curso_slug));
            } else {
                header("Location: evaluacion.php?quiz_id=" . $final_exam['id'] . "&curso=" . urlencode($curso_slug) . "&next=0");
            }
        } else {
            header("Location: certificado.php?curso=" . urlencode($curso_slug));
        }
    }
    exit;
}

$slug = isset($_GET['curso']) ? trim($_GET['curso']) : '';
if (empty($slug)) { header("Location: academia.php"); exit; }

$stmt_c = $db->prepare("SELECT * FROM ah_courses WHERE slug = :slug AND status = 'published' LIMIT 1");
$stmt_c->execute(['slug' => $slug]); $curso = $stmt_c->fetch(PDO::FETCH_ASSOC);
if (!$curso) { header("Location: academia.php"); exit; }

$stmt_m = $db->prepare("SELECT * FROM ah_modules WHERE course_id = :cid ORDER BY sort_order ASC, id ASC");
$stmt_m->execute(['cid' => $curso['id']]); $modulos = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

$lecciones_por_modulo = []; $todas_las_lecciones = []; $lista_ordenada_ids = [];

if (!empty($modulos)) {
    $ids_modulos = implode(',', array_column($modulos, 'id'));
    $stmt_l = $db->query("SELECT * FROM ah_lessons WHERE module_id IN ($ids_modulos) ORDER BY sort_order ASC, id ASC");
    while ($row = $stmt_l->fetch(PDO::FETCH_ASSOC)) {
        $lecciones_por_modulo[$row['module_id']][] = $row;
        $todas_las_lecciones[$row['id']] = $row;
    }
    foreach ($modulos as $mod) {
        if (!empty($lecciones_por_modulo[$mod['id']])) {
            foreach ($lecciones_por_modulo[$mod['id']] as $l) { $lista_ordenada_ids[] = $l['id']; }
        }
    }
}

$current_lesson_id = isset($_GET['leccion']) ? (int)$_GET['leccion'] : 0;
$leccion_actual = null; $siguiente_lesson_id = 0;

if ($current_lesson_id > 0 && isset($todas_las_lecciones[$current_lesson_id])) {
    $leccion_actual = $todas_las_lecciones[$current_lesson_id];
} else {
    if (!empty($lista_ordenada_ids)) { $leccion_actual = $todas_las_lecciones[$lista_ordenada_ids[0]]; $current_lesson_id = $leccion_actual['id']; }
}

$current_index = array_search($current_lesson_id, $lista_ordenada_ids);
if ($current_index !== false && isset($lista_ordenada_ids[$current_index + 1])) {
    $siguiente_lesson_id = $lista_ordenada_ids[$current_index + 1];
}

$stmt_prog = $db->prepare("SELECT lesson_id FROM ah_student_progress WHERE user_id = :uid AND course_id = :cid");
$stmt_prog->execute(['uid' => $user_id, 'cid' => $curso['id']]);
$lecciones_completadas = $stmt_prog->fetchAll(PDO::FETCH_COLUMN);

$total_lecciones = count($lista_ordenada_ids);
$total_completadas = count($lecciones_completadas);
$porcentaje_progreso = $total_lecciones > 0 ? round(($total_completadas / $total_lecciones) * 100) : 0;

// ========================================================
// 2. OBTENER ESTADO DE EVALUACIONES (PARA MOSTRAR BOTONES)
// ========================================================
$quiz_leccion = null;
$ya_aprobo_quiz = false;
if ($leccion_actual) {
    $stmt_quiz = $db->prepare("SELECT id, title FROM ah_quizzes WHERE lesson_id = :lid LIMIT 1");
    $stmt_quiz->execute(['lid' => $leccion_actual['id']]);
    $quiz_leccion = $stmt_quiz->fetch(PDO::FETCH_ASSOC);
    
    if ($quiz_leccion) {
        $stmt_chk_q = $db->prepare("SELECT id FROM ah_quiz_attempts WHERE user_id = :u AND quiz_id = :q AND passed = 1");
        $stmt_chk_q->execute(['u' => $user_id, 'q' => $quiz_leccion['id']]);
        if ($stmt_chk_q->fetch()) { $ya_aprobo_quiz = true; }
    }
}

$examen_final = null;
$ya_aprobo_final = false;
if ($siguiente_lesson_id == 0) { 
    $stmt_fe_btn = $db->prepare("SELECT id FROM ah_quizzes WHERE course_id = :cid AND quiz_type = 'final_exam' LIMIT 1");
    $stmt_fe_btn->execute(['cid' => $curso['id']]);
    $examen_final = $stmt_fe_btn->fetch(PDO::FETCH_ASSOC);
    
    if ($examen_final) {
        $stmt_chk_f = $db->prepare("SELECT id FROM ah_quiz_attempts WHERE user_id = :u AND quiz_id = :q AND passed = 1");
        $stmt_chk_f->execute(['u' => $user_id, 'q' => $examen_final['id']]);
        if ($stmt_chk_f->fetch()) { $ya_aprobo_final = true; }
    }
}

function getYoutubeEmbedUrl($url) {
    $shortUrlRegex = '/youtu.be\/([a-zA-Z0-9_-]+)\??/i';
    $longUrlRegex = '/youtube.com\/((?:embed)|(?:watch))((?:\?v\=)|(?:\/))([a-zA-Z0-9_-]+)/i';
    if (preg_match($longUrlRegex, $url, $matches)) { $youtube_id = $matches[count($matches) - 1]; }
    if (preg_match($shortUrlRegex, $url, $matches)) { $youtube_id = $matches[count($matches) - 1]; }
    return isset($youtube_id) ? 'https://www.youtube.com/embed/' . $youtube_id : $url;
}

$social_meta = [
    'title' => 'Aula Virtual | Acción Honduras',
    'description' => 'Espacio privado de formación para estudiantes de Acción Honduras.',
    'image' => '/uploads/images/social_accion_honduras_1200x630.jpg',
    'url' => 'https://accionhonduras.org/aula.php',
    'type' => 'website',
    'breadcrumb' => 'Aula virtual'
];
$robots_meta = 'noindex, nofollow';
ob_start();
?>

<style>
    :root { --ah-primary: #34859B; --ah-accent: #46B094; }
    body { background: #0f172a; margin: 0; padding: 0; overflow: hidden !important; font-family: system-ui, -apple-system, sans-serif; }
    
    .aula-mobile-tabs { display: none; background: #0f172a; border-bottom: 1px solid #1e293b; height: 55px; justify-content: space-around; align-items: center; flex-shrink: 0; z-index: 100; position: relative; }
    .mobile-tab-btn { background: none; border: none; color: #94a3b8; font-size: 0.85rem; font-weight: 700; padding: 8px 16px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 4px; transition: all 0.2s ease; border-bottom: 3px solid transparent; }
    .mobile-tab-btn.active { color: var(--ah-primary); border-bottom-color: var(--ah-primary); }

    .aula-layout { display: grid !important; grid-template-columns: 1fr 360px !important; height: calc(100vh - 80px) !important; width: 100vw !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-sizing: border-box !important; background: #f8fafc; overflow: hidden !important; transition: grid-template-columns 0.3s ease; }
    .aula-layout.sidebar-open { grid-template-columns: 320px 1fr 360px !important; }
    .aula-sidebar-left, .aula-content, .aula-sidebar-right { height: 100% !important; box-sizing: border-box !important; }
    
    .aula-sidebar-left { display: none; background: white; border-right: 1px solid #e2e8f0; flex-direction: column; overflow-y: auto !important; }
    .aula-layout.sidebar-open .aula-sidebar-left { display: flex; }

    .btn-toggle-sidebar { background: white; border: 1px solid #cbd5e1; color: #475569; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 1.1rem; transition: 0.2s; display: flex; align-items: center; justify-content: center; }
    .btn-toggle-sidebar:hover { background: #f1f5f9; color: var(--ah-primary); border-color: var(--ah-primary); }
    .header-action-row { display: flex; align-items: center; gap: 15px; margin-bottom: 5px; }

    .aula-content { padding: 40px; background: #f1f5f9; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; align-items: center; overflow-y: hidden !important; }
    .lesson-title-area { width: 100%; max-width: 900px; margin-bottom: 20px; flex-shrink: 0; }
    .lesson-title { font-size: 1.8rem; color: #0f172a; font-weight: 800; margin: 0; }
    .course-badge { display: inline-block; background: #e2e8f0; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: 700; margin-top: 10px; }

    .presentation-wrapper { position: relative; width: 100%; max-width: 900px; background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; overflow: hidden; display: flex; flex-direction: column; flex-grow: 1; min-height: 0; }
    .presentation-viewport { overflow: hidden; width: 100%; flex-grow: 1; position: relative; }
    .presentation-track { display: flex; flex-wrap: nowrap; transition: transform 0.4s cubic-bezier(0.25, 0.8, 0.25, 1); width: 100%; height: 100%; }
    
    .presentation-slide { flex: 0 0 100%; width: 100%; max-width: 100%; height: 100%; padding: 50px; box-sizing: border-box; overflow-y: auto; position: relative; border-top: 5px solid var(--ah-primary); background-color: #ffffff; background-image: radial-gradient(#e2e8f0 1.5px, transparent 1.5px); background-size: 25px 25px; }
    .slide-watermark { position: absolute; top: 20px; right: 30px; height: 35px; opacity: 0.9; z-index: 10; }

    .slide-content-text p { font-size: 1.1rem; line-height: 1.8; color: #334155; margin-bottom: 1.5rem; background: rgba(255,255,255,0.8); padding: 10px; border-radius: 6px; }
    .slide-content-text h2 { color: var(--ah-primary); font-size: 1.6rem; margin-top: 0; padding-bottom: 10px; border-bottom: 2px solid var(--ah-accent); }
    .slide-content-text h3 { color: #0f172a; font-size: 1.3rem; display: flex; align-items: center; gap: 10px; margin-top: 25px; }
    .slide-content-text ul { list-style: none; padding-left: 0; background: rgba(255,255,255,0.8); padding: 15px; border-radius: 8px; }
    .slide-content-text ul li { position: relative; padding-left: 35px; margin-bottom: 12px; font-size: 1.05rem; color: #475569; }
    .slide-content-text ul li::before { content: "\f058"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; left: 0; top: 2px; color: var(--ah-accent); font-size: 1.2rem; }
    .slide-content-text strong, .slide-content-text b { color: var(--ah-primary); font-weight: 800; }
    
    .presentation-slide::-webkit-scrollbar { width: 6px; }
    .presentation-slide::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
    .presentation-slide::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    .presentation-controls { flex-shrink: 0; display: flex; justify-content: space-between; align-items: center; padding: 15px 30px; background: #f8fafc; border-top: 1px solid #e2e8f0; z-index: 5; }
    .btn-slide { background: var(--ah-primary); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 700; transition: 0.2s; display: flex; align-items: center; gap: 8px; font-size: 0.95rem; }
    .btn-slide:hover:not(:disabled) { background: #2c7285; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(52,133,155,0.2); }
    .btn-slide:disabled { background: #cbd5e1; color: #94a3b8; cursor: not-allowed; box-shadow: none; transform: none; }
    .btn-success { background: var(--ah-accent) !important; }
    .btn-success:hover { background: #358f77 !important; }
    .slide-counter { font-size: 0.95rem; font-weight: 800; color: #475569; background: #e2e8f0; padding: 6px 15px; border-radius: 20px; }

    .video-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 12px; background: #0f172a; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
    .video-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
    
    .pdf-container { border-radius: 8px; overflow: hidden; border: 1px solid #cbd5e1; width: 100%; display: flex; flex-direction: column; height: calc(100vh - 250px); min-height: 450px; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .pdf-header { background: #f8fafc; padding: 12px 20px; font-weight: 600; font-size: 0.9rem; border-bottom: 1px solid #cbd5e1; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-shrink: 0; }
    .pdf-body { flex-grow: 1; width: 100%; height: 100%; border: none; }
    .btn-action-text { background: none; border: none; color: var(--ah-primary); cursor: pointer; font-weight: 800; font-family: inherit; font-size: 0.85rem; display: flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 6px; transition: 0.2s; }
    .btn-action-text:hover { background: #e2e8f0; color: #0f172a; }

    .sidebar-logo-area { padding: 25px 20px; text-align: center; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    .sidebar-progress-container { padding: 15px 20px; background: white; border-bottom: 1px solid #e2e8f0; }
    .progress-text { display: flex; justify-content: space-between; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 8px; }
    .progress-bar-bg { width: 100%; height: 8px; background: #e2e8f0; border-radius: 10px; overflow: hidden; }
    .progress-bar-fill { height: 100%; background: var(--ah-accent); border-radius: 10px; transition: width 0.5s ease; }

    .sidebar-header { padding: 15px 20px; background: white; border-bottom: 1px solid #e2e8f0; font-weight: 800; font-size: 0.9rem; color: #0f172a; position: sticky; top: 0; z-index: 10; text-transform: uppercase; letter-spacing: 0.5px; }
    .module-group { border-bottom: 1px solid #e2e8f0; }
    .module-title { padding: 12px 20px; font-weight: 700; color: #475569; font-size: 0.85rem; background: #f8fafc; display: flex; align-items: center; gap: 8px; }
    .lesson-link { display: flex; align-items: center; gap: 10px; padding: 12px 20px 12px 25px; text-decoration: none; color: #64748b; font-size: 0.85rem; transition: 0.2s; border-left: 3px solid transparent; }
    .lesson-link:hover, .lesson-link.active { background: #f0fdfa; color: var(--ah-primary); }
    .lesson-link.active { border-left-color: var(--ah-primary); font-weight: 700; }

    .aula-sidebar-right { background: #ffffff; display: flex; flex-direction: column; box-shadow: -5px 0 25px rgba(15, 23, 42, 0.03); overflow: hidden; }
    .ai-header { padding: 22px 20px; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #0f172a 0%, var(--ah-primary) 100%); color: #ffffff; flex-shrink: 0; }
    .ai-header h3 { margin: 0; font-size: 1.05rem; font-weight: 800; display: flex; align-items: center; gap: 8px; color: #ffffff; }
    .ai-chat-space { flex-grow: 1; min-height: 0; padding: 25px 20px; display: flex; flex-direction: column; gap: 20px; background: #fdfefe; overflow-y: auto; scroll-behavior: smooth; }
    .chat-bubble { max-width: 88%; padding: 14px 18px; border-radius: 16px; font-size: 0.92rem; line-height: 1.5; word-wrap: break-word; box-shadow: 0 2px 8px rgba(0,0,0,0.01); }
    .chat-bubble.user { background: linear-gradient(135deg, var(--ah-primary) 0%, #2c7285 100%); color: white; align-self: flex-end; border-bottom-right-radius: 3px; }
    .chat-bubble.bot { background: #ffffff; color: #1e293b; align-self: flex-start; border-bottom-left-radius: 3px; border: 1px solid #e2e8f0; }
    .ai-input-area { padding: 20px; border-top: 1px solid #e2e8f0; background: white; display: flex; gap: 12px; align-items: center; flex-shrink: 0 !important; }
    .ai-input-wrapper { position: relative; flex-grow: 1; }
    .ai-input-wrapper input { width: 100%; padding: 14px 16px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 0.92rem; box-sizing: border-box; outline: none; background: #f8fafc; color: #1e293b; }
    .ai-input-wrapper input:focus { border-color: var(--ah-primary); background: white; box-shadow: 0 0 0 4px rgba(52, 133, 155, 0.15); }
    .btn-send { background: var(--ah-primary); color: white; border: none; width: 48px; height: 48px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0 !important; }

    @media (max-width: 1100px) {
        .aula-mobile-tabs { display: flex; }
        .aula-layout { grid-template-columns: 1fr !important; height: calc(100vh - 135px) !important; height: calc(100dvh - 135px) !important; }
        .aula-layout.sidebar-open { grid-template-columns: 1fr !important; } 
        .aula-sidebar-left, .aula-content, .aula-sidebar-right { display: none !important; width: 100% !important; max-width: 100% !important; }
        .aula-layout.view-content .aula-content { display: flex !important; padding: 12px; height: 100%; }
        .aula-layout.view-menu .aula-sidebar-left { display: flex !important; }
        .aula-layout.view-ai .aula-sidebar-right { display: flex !important; height: 100% !important; flex-direction: column !important; justify-content: space-between !important; }
        .btn-toggle-sidebar { display: none !important; } 
        .presentation-slide { padding: 30px 15px 20px 15px; }
        .pdf-container { height: calc(100vh - 200px); }
    }
</style>
<?php
$page_head_html = ob_get_clean();
require_once __DIR__ . '/header.php';
?>

<div class="aula-mobile-tabs">
    <button class="mobile-tab-btn active" id="tab-content" onclick="toggleAulaView('content')"><i class="fa-solid fa-book-open"></i> Lección</button>
    <button class="mobile-tab-btn" id="tab-menu" onclick="toggleAulaView('menu')"><i class="fa-solid fa-list-ol"></i> Temario</button>
    <button class="mobile-tab-btn" id="tab-ai" onclick="toggleAulaView('ai')"><i class="fa-solid fa-robot"></i> CelestIA</button>
</div>

<div class="aula-layout view-content" id="main-aula-layout">
    
    <aside class="aula-sidebar-left">
        <div class="sidebar-logo-area">
            <img src="logo.png" alt="Acción Honduras" style="max-width: 160px; height: auto;" onerror="this.style.display='none'">
        </div>
        
        <div class="sidebar-progress-container">
            <div class="progress-text">
                <span>Progreso del Curso</span>
                <span><?php echo $porcentaje_progreso; ?>%</span>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" style="width: <?php echo $porcentaje_progreso; ?>%;"></div>
            </div>
        </div>

        <div class="sidebar-header"><i class="fa-solid fa-map" style="color:var(--ah-primary)"></i> Mapa del Curso</div>
        <?php foreach($modulos as $mod): ?>
            <div class="module-group">
                <div class="module-title"><i class="fa-solid fa-folder-open" style="color: var(--ah-accent);"></i> <?php echo htmlspecialchars($mod['title']); ?></div>
                <?php if(!empty($lecciones_por_modulo[$mod['id']])): ?>
                    <?php foreach($lecciones_por_modulo[$mod['id']] as $lec): ?>
                        <?php 
                            $is_active = ($current_lesson_id == $lec['id']) ? 'active' : ''; 
                            $is_completed = in_array($lec['id'], $lecciones_completadas);
                        ?>
                        <a href="aula.php?curso=<?php echo urlencode($slug); ?>&leccion=<?php echo $lec['id']; ?>" class="lesson-link <?php echo $is_active; ?>">
                            <?php if($is_completed): ?>
                                <i class="fa-solid fa-circle-check" style="font-size:0.8rem; color: var(--ah-accent);"></i> 
                            <?php else: ?>
                                <i class="fa-solid fa-circle-play" style="font-size:0.7rem;"></i> 
                            <?php endif; ?>
                            <?php echo htmlspecialchars($lec['title']); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </aside>

    <main class="aula-content">
        <?php if(!$leccion_actual): ?>
            <div style="text-align:center; padding: 100px 20px; color: #94a3b8;"><i class="fa-solid fa-cubes" style="font-size: 4rem; margin-bottom: 20px;"></i><h2>Curso en construcción</h2></div>
        <?php else: ?>
            
            <div class="lesson-title-area">
                <div class="header-action-row">
                    <button class="btn-toggle-sidebar" onclick="toggleDesktopSidebar()" title="Mostrar/Ocultar Temario"><i class="fa-solid fa-bars"></i></button>
                    <h1 class="lesson-title"><?php echo htmlspecialchars($leccion_actual['title']); ?></h1>
                </div>
                <div class="course-badge"><?php echo htmlspecialchars($curso['title']); ?></div>
            </div>

            <div class="presentation-wrapper">
                <div class="presentation-viewport">
                    <div class="presentation-track" id="slider-track">
                        <?php 
                        $bloques = json_decode($leccion_actual['content_html'], true);
                        if (!is_array($bloques)) {
                            $bloques = [];
                            if ($leccion_actual['content_type'] == 'video') { $bloques[] = ['type' => 'video', 'value' => $leccion_actual['media_url']]; } 
                            else { $bloques[] = ['type' => 'text', 'value' => $leccion_actual['content_html']]; }
                        }

                        $slide_count = 0;
                        foreach ($bloques as $b):
                            if (empty($b['value'])) continue;
                            
                            if ($b['type'] === 'text'): 
                                $texto_limpio = trim($b['value']);
                                $diapositivas_html = preg_split('/(?=<h2\b|<div\b)/i', $texto_limpio, -1, PREG_SPLIT_NO_EMPTY);
                                foreach($diapositivas_html as $slide_html):
                                    if(trim(strip_tags($slide_html)) === '' && strpos($slide_html, '<img') === false && strpos($slide_html, '<iframe') === false) continue;
                                    $slide_count++;
                        ?>
                                    <div class="presentation-slide"><img src="logo.png" alt="Acción Honduras" class="slide-watermark" onerror="this.style.display='none'"><div class="slide-content-text"><?php echo $slide_html; ?></div></div>
                        <?php 
                                endforeach;
                            elseif ($b['type'] === 'video'): 
                                $slide_count++;
                                $url_video = trim($b['value']);
                                $es_mp4 = preg_match('/\.mp4$/i', $url_video) || preg_match('/capacitateparaelempleo\.org/i', $url_video);
                        ?>
                                <div class="presentation-slide" style="display:flex; flex-direction:column; justify-content:center;">
                                    <img src="logo.png" alt="Acción Honduras" class="slide-watermark" onerror="this.style.display='none'">
                                    <div class="slide-content-text"><h2><i class="fa-solid fa-play"></i> Material Audiovisual</h2></div>
                                    <?php if($es_mp4): ?>
                                        <div style="width: 100%; text-align: center; background: #0f172a; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); padding: 20px 0;">
                                            <video controls controlsList="nodownload" style="width: 90%; max-height: 450px; outline: none; border-radius: 8px;"><source src="<?php echo htmlspecialchars($url_video); ?>" type="video/mp4">Tu navegador no soporta la reproducción.</video>
                                        </div>
                                    <?php else: ?>
                                        <div class="video-container"><iframe src="<?php echo getYoutubeEmbedUrl($url_video); ?>" allowfullscreen></iframe></div>
                                    <?php endif; ?>
                                </div>
                        <?php elseif ($b['type'] === 'image'): $slide_count++; ?>
                                <div class="presentation-slide" style="text-align: center; display:flex; flex-direction:column; justify-content:center;"><img src="logo.png" alt="Acción Honduras" class="slide-watermark" onerror="this.style.display='none'"><img src="<?php echo htmlspecialchars($b['value']); ?>" style="max-width: 100%; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);" alt="Imagen formativa"></div>
                        
                        <?php elseif ($b['type'] === 'pdf'): $slide_count++; ?>
                                <div class="presentation-slide" style="display:flex; flex-direction:column; padding: 30px 40px;">
                                    <img src="logo.png" alt="Acción Honduras" class="slide-watermark" onerror="this.style.display='none'">
                                    <div class="pdf-container" id="pdf-viewer-<?php echo $slide_count; ?>">
                                        <div class="pdf-header">
                                            <span style="display:flex; align-items:center; gap:8px; color:#334155;">
                                                <i class="fa-solid fa-file-pdf" style="color:#f43f5e; font-size:1.2rem;"></i> Material de Lectura
                                            </span>
                                            <div style="display:flex; gap:15px; align-items:center;">
                                                <button type="button" class="btn-action-text" onclick="toggleFullscreen('pdf-viewer-<?php echo $slide_count; ?>')" title="Ver en pantalla completa">
                                                    <i class="fa-solid fa-expand"></i> Pantalla Completa
                                                </button>
                                                <a href="<?php echo htmlspecialchars($b['value']); ?>" target="_blank" class="btn-action-text" style="text-decoration:none;" title="Descargar PDF">
                                                    <i class="fa-solid fa-download"></i> Descargar
                                                </a>
                                            </div>
                                        </div>
                                        <embed class="pdf-body" src="<?php echo htmlspecialchars($b['value']); ?>#view=FitH" type="application/pdf" />
                                    </div>
                                </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>

                <?php if($slide_count > 0): ?>
                <div class="presentation-controls">
                    <button class="btn-slide" id="btn-prev" onclick="moveSlide(-1)" disabled><i class="fa-solid fa-chevron-left"></i> Anterior</button>
                    <div class="slide-counter"><span id="current-slide-num">1</span> / <?php echo $slide_count; ?></div>
                    <button class="btn-slide" id="btn-next" onclick="moveSlide(1)" <?php if($slide_count == 1) echo 'style="display:none;"'; ?>>Siguiente <i class="fa-solid fa-chevron-right"></i></button>

                    <div id="form-complete" style="margin:0; width:100%; <?php echo ($slide_count > 1) ? 'display:none;' : ''; ?>">
                        <?php if ($quiz_leccion && !$ya_aprobo_quiz): ?>
                            <a href="evaluacion.php?quiz_id=<?php echo $quiz_leccion['id']; ?>&curso=<?php echo urlencode($slug); ?>&next=<?php echo $siguiente_lesson_id; ?>" class="btn-slide btn-success" style="text-decoration:none; justify-content:center; width:100%;">
                                Tomar Evaluación Obligatoria <i class="fa-solid fa-clipboard-question"></i>
                            </a>
                        <?php else: ?>
                            <!-- FORMULARIO ACTUALIZADO CON CAMPO DE TIEMPO -->
                            <form method="POST" id="form-mark-completed" style="margin:0; width:100%;">
                                <input type="hidden" name="mark_completed" value="1">
                                <input type="hidden" name="lesson_id" value="<?php echo $leccion_actual['id']; ?>">
                                <input type="hidden" name="course_id" value="<?php echo $curso['id']; ?>">
                                <input type="hidden" name="next_lesson_id" value="<?php echo $siguiente_lesson_id; ?>">
                                <input type="hidden" name="curso_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                
                                <!-- CAMPO OCULTO PARA EL CRONÓMETRO -->
                                <input type="hidden" name="time_spent_seconds" id="time_spent_input" value="0">
                                
                                <button type="submit" class="btn-slide btn-success" style="width:100%; justify-content:center;">
                                    <?php echo ($siguiente_lesson_id > 0) ? 'Completar y Continuar <i class="fa-solid fa-check-circle"></i>' : 'Finalizar y Obtener Diploma <i class="fa-solid fa-award"></i>'; ?>
                                </button>
                            </form>
                            
                            <?php if ($quiz_leccion && $ya_aprobo_quiz): ?>
                                <div style="text-align:center; margin-top:12px;">
                                    <a href="evaluacion.php?quiz_id=<?php echo $quiz_leccion['id']; ?>&curso=<?php echo urlencode($slug); ?>&next=<?php echo $siguiente_lesson_id; ?>" style="color:var(--ah-primary); font-size:0.85rem; font-weight:bold; text-decoration:none;">
                                        <i class="fa-solid fa-rotate-right"></i> Retomar Quiz para mejorar nota
                                    </a>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($siguiente_lesson_id == 0 && $examen_final && $ya_aprobo_final): ?>
                                <div style="text-align:center; margin-top:12px;">
                                    <a href="evaluacion.php?quiz_id=<?php echo $examen_final['id']; ?>&curso=<?php echo urlencode($slug); ?>&next=0" style="color:var(--ah-primary); font-size:0.85rem; font-weight:bold; text-decoration:none;">
                                        <i class="fa-solid fa-rotate-right"></i> Retomar Examen Final para mejorar nota
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        <?php endif; ?>
    </main>

    <aside class="aula-sidebar-right">
        <div class="ai-header"><h3><i class="fa-solid fa-robot" style="color: var(--ah-accent);"></i>CelestIA</h3><span>Consultas en tiempo real sobre esta temática</span></div>
        <div class="ai-chat-space" id="chat-screen"><div class="chat-bubble bot">¡Hola! Soy tu asistente de aprendizaje para **Acción Honduras**. Estoy listo para resolver cualquier duda. ¿En qué puedo apoyarte?</div></div>
        <div class="ai-input-area"><div class="ai-input-wrapper"><input type="text" id="ai-user-query" placeholder="Escribe tu consulta aquí..." onkeypress="if(event.key === 'Enter') dispatchChatQuery()"></div><button type="button" class="btn-send" onclick="dispatchChatQuery()" id="ai-btn-submit" title="Enviar mensaje"><i class="fa-solid fa-paper-plane"></i></button></div>
    </aside>

</div>

<script>
function toggleDesktopSidebar() { const layout = document.getElementById('main-aula-layout'); if(layout) layout.classList.toggle('sidebar-open'); }

function toggleFullscreen(elemId) {
    let elem = document.getElementById(elemId);
    if (!document.fullscreenElement) {
        if (elem.requestFullscreen) { elem.requestFullscreen(); }
        else if (elem.webkitRequestFullscreen) { elem.webkitRequestFullscreen(); }
        else if (elem.msRequestFullscreen) { elem.msRequestFullscreen(); }
    } else {
        if (document.exitFullscreen) { document.exitFullscreen(); }
        else if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
        else if (document.msExitFullscreen) { document.msExitFullscreen(); }
    }
}

let currentSlide = 0;
const totalSlides = <?php echo isset($slide_count) ? $slide_count : 0; ?>;
const track = document.getElementById('slider-track');
const btnPrev = document.getElementById('btn-prev');
const btnNext = document.getElementById('btn-next');
const formComplete = document.getElementById('form-complete');
const slideNumText = document.getElementById('current-slide-num');

function moveSlide(direction) {
    if (!track) return;
    currentSlide += direction;
    if (currentSlide < 0) currentSlide = 0;
    if (currentSlide >= totalSlides) currentSlide = totalSlides - 1;
    track.style.transform = `translateX(-${currentSlide * 100}%)`;
    slideNumText.innerText = currentSlide + 1;
    btnPrev.disabled = currentSlide === 0;
    
    if(currentSlide === totalSlides - 1) {
        if(btnNext) btnNext.style.display = 'none';
        if(formComplete) formComplete.style.display = 'block';
    } else {
        if(btnNext) btnNext.style.display = 'flex';
        if(formComplete) formComplete.style.display = 'none';
    }
    const slides = document.querySelectorAll('.presentation-slide');
    if(slides[currentSlide]) slides[currentSlide].scrollTop = 0;
}

function toggleAulaView(targetView) {
    const layout = document.querySelector('.aula-layout');
    if (!layout) return;
    layout.classList.remove('view-content', 'view-menu', 'view-ai');
    layout.classList.add('view-' + targetView);
    document.querySelectorAll('.mobile-tab-btn').forEach(btn => btn.classList.remove('active'));
    if (targetView === 'content') document.getElementById('tab-content').classList.add('active');
    if (targetView === 'menu') document.getElementById('tab-menu').classList.add('active');
    if (targetView === 'ai') document.getElementById('tab-ai').classList.add('active');
    if (targetView === 'ai') { const chatScreen = document.getElementById('chat-screen'); if(chatScreen) chatScreen.scrollTop = chatScreen.scrollHeight; }
}

function dispatchChatQuery() {
    const inputElement = document.getElementById('ai-user-query');
    if (!inputElement) return;
    const queryText = inputElement.value.trim();
    if (!queryText) return;
    const chatScreen = document.getElementById('chat-screen');
    const btnSubmit = document.getElementById('ai-btn-submit');
    const userBubble = document.createElement('div');
    userBubble.className = 'chat-bubble user';
    userBubble.innerText = queryText;
    chatScreen.appendChild(userBubble);
    chatScreen.scrollTop = chatScreen.scrollHeight;
    inputElement.value = ''; 
    inputElement.disabled = true;
    if(btnSubmit) btnSubmit.disabled = true;
    let textContext = "";
    document.querySelectorAll('.slide-content-text').forEach(el => { textContext += el.innerText + "\n\n"; });
    const typingBubble = document.createElement('div');
    typingBubble.className = 'chat-bubble bot';
    typingBubble.id = 'ai-typing';
    typingBubble.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analizando el contenido...';
    chatScreen.appendChild(typingBubble);
    chatScreen.scrollTop = chatScreen.scrollHeight;

    fetch('../api/ia_chat.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: queryText, context: textContext })
    })
    .then(response => response.text())
    .then(rawText => {
        const targetTyping = document.getElementById('ai-typing');
        if(targetTyping) targetTyping.remove();
        const botBubble = document.createElement('div');
        botBubble.className = 'chat-bubble bot';
        try {
            const data = JSON.parse(rawText);
            if (data.choices && data.choices[0] && data.choices[0].message) { botBubble.innerText = data.choices[0].message.content; } 
            else if (data.error) { botBubble.style.color = '#ef4444'; botBubble.innerText = "Error: " + (typeof data.error === 'object' ? JSON.stringify(data.error) : data.error); }
        } catch(e) { botBubble.style.color = '#ef4444'; botBubble.innerText = "Respuesta del servidor no válida."; }
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

// =========================================
// NUEVO: CRONÓMETRO INTELIGENTE DE LECCIÓN
// =========================================
let lessonStartTime = Math.floor(Date.now() / 1000);
const formCompletedTrigger = document.getElementById('form-mark-completed');

if(formCompletedTrigger) {
    formCompletedTrigger.addEventListener('submit', function() {
        let lessonEndTime = Math.floor(Date.now() / 1000);
        let timeSpent = lessonEndTime - lessonStartTime;
        document.getElementById('time_spent_input').value = timeSpent;
    });
}
</script>
</body>
</html>
