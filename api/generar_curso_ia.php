<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
if (($_SESSION['logged_in'] ?? false) !== true || ($_SESSION['auth_source'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado.']);
    exit;
}
Auth::enforceSameOriginRequest();
$auth->checkAccess('cursos.php', $db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'MÃ©todo no permitido.']);
    exit;
}
Auth::checkCSRF($_POST['csrf_token'] ?? '');

set_time_limit(300); // 5 minutos de paciencia para PDFs pesados

// =========================================================================
// 1. PEGA AQUÍ TU LLAVE DE GOOGLE AI STUDIO (Empieza con AIza)
// =========================================================================

$api_key = trim((string)getenv('GEMINI_API_KEY'));
$secrets_file = dirname(__DIR__, 2) . '/.accionhonduras_secrets.php';
if ($api_key === '' && is_file($secrets_file)) {
    $secrets = require $secrets_file;
    if (is_array($secrets)) {
        $api_key = trim((string)($secrets['GEMINI_API_KEY'] ?? ''));
    }
}
if ($api_key === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'El generador de cursos no estÃ¡ configurado.']);
    exit;
}

// 2. CAPTURAR DATOS
$tema = $_POST['tema'] ?? 'Tema general';
$prompt_personalizado = $_POST['prompt'] ?? "Actúa como un educador experto. Crea un curso sobre: $tema";

// 3. EL PROMPT MAESTRO OCULTO
$instrucciones_json = "
INSTRUCCIÓN CRÍTICA: Si te he adjuntado un documento, basa todo el contenido estrictamente en él.
Devuelve ÚNICAMENTE un objeto JSON válido con esta estructura exacta, SIN markdown extra:
{
  \"titulo_curso\": \"Título del curso\",
  \"resumen\": \"Resumen detallado de al menos 3 líneas\",
  \"modulos\": [
    {
      \"titulo_modulo\": \"Nombre del módulo\",
      \"lecciones\": [
        {
          \"titulo_leccion\": \"Nombre de la lección\",
          \"contenido_html\": \"Redacta la lección en HTML puro. INSTRUCCIONES VISUALES: 1. Usa <p style='line-height:1.8; color:#334155; font-size:1.05rem; margin-bottom:20px;'> para párrafos. 2. Usa <div style='background:#ffffff; padding:25px; border-left:6px solid #0ea5e9; border-radius:8px; box-shadow:0 4px 10px rgba(0,0,0,0.06); margin: 30px 0;'><h3 style='color:#0ea5e9; margin-top:0;'><i class='fa-solid fa-chart-pie'></i> Título</h3><ul style='color:#475569; padding-left:20px;'><li>Dato</li></ul></div> para infografías. 3. Usa <div style='background:#fefce8; padding:20px; border:1px solid #fef08a; border-radius:8px; margin:25px 0; color:#854d0e;'><h4 style='margin-top:0; color:#ca8a04;'><i class='fa-solid fa-lightbulb'></i> Consejo Práctico</h4><p>Texto</p></div> para tips. 4. Usa <div style='background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:white; padding:30px; text-align:center; border-radius:12px; margin: 35px 0;'><i class='fa-brands fa-youtube' style='color:#ef4444; font-size:3rem; margin-bottom:15px;'></i><h3 style='color:white; margin-top:0;'>🎥 Video</h3><blockquote style='background:rgba(255,255,255,0.05); padding:20px; border-radius:8px; border-left: 4px solid #3b82f6; font-style:italic; font-size: 1rem; margin:0;'>Guion</blockquote></div> para videos.\"
        }
      ]
    }
  ]
}";

$prompt_final = $prompt_personalizado . "\n\n" . $instrucciones_json;
$parts = [];

// =========================================================================
// 4. NUEVO SISTEMA: GOOGLE FILE API (PARA ARCHIVOS PESADOS)
// =========================================================================
if (isset($_FILES['archivo_base']) && $_FILES['archivo_base']['error'] === UPLOAD_ERR_OK) {
    $file_tmp = $_FILES['archivo_base']['tmp_name'];
    $file_name = $_FILES['archivo_base']['name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    if ($file_ext === 'txt') {
        $texto_archivo = file_get_contents($file_tmp);
        $prompt_final .= "\n\n--- DOCUMENTO BASE ---\n" . $texto_archivo;
    } else if ($file_ext === 'pdf') {
        // PASO 1: Subir el PDF pesado a los servidores de Google
        $upload_url = 'https://generativelanguage.googleapis.com/upload/v1beta/files?key=' . $api_key;
        $file_content = file_get_contents($file_tmp);
        
        $ch_up = curl_init($upload_url);
        curl_setopt($ch_up, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_up, CURLOPT_POST, true);
        curl_setopt($ch_up, CURLOPT_HTTPHEADER, [
            'X-Goog-Upload-Protocol: raw',
            'X-Goog-Upload-File-Name: ' . basename($file_name),
            'Content-Type: application/pdf',
            'Content-Length: ' . strlen($file_content)
        ]);
        curl_setopt($ch_up, CURLOPT_POSTFIELDS, $file_content);
        curl_setopt($ch_up, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch_up, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch_up, CURLOPT_TIMEOUT, 300);

        $up_res = curl_exec($ch_up);
        $up_code = curl_getinfo($ch_up, CURLINFO_HTTP_CODE);
        curl_close($ch_up);

        if ($up_code == 200 && $up_res) {
            $up_data = json_decode($up_res, true);
            if (isset($up_data['file']['uri'])) {
                // PASO 2: Entregar el enlace del archivo subido a Gemini
                $parts[] = [
                    "fileData" => [
                        "mimeType" => $up_data['file']['mimeType'],
                        "fileUri" => $up_data['file']['uri']
                    ]
                ];
            }
        } else {
            echo json_encode(['success' => false, 'error' => "Google rechazó la subida del PDF (HTTP $up_code). Asegúrate de que no esté encriptado."]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Formato no soportado. Por favor sube un PDF o TXT.']);
        exit;
    }
}

// Agregamos el texto final al paquete
$parts[] = ["text" => $prompt_final];

// =========================================================================
// 5. GENERAR EL CURSO CON GEMINI
// =========================================================================
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
$payload = json_encode([
    "contents" => [["parts" => $parts]],
    "generationConfig" => ["responseMimeType" => "application/json"]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 200); 
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

$respuesta = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($respuesta === false || $http_code !== 200) {
    echo json_encode(['success' => false, 'error' => "Error de API (HTTP $http_code). Detalle: " . $respuesta]);
    exit;
}

$gemini_response = json_decode($respuesta, true);
$curso_json = $gemini_response['candidates'][0]['content']['parts'][0]['text'] ?? '';
$curso_data = json_decode($curso_json, true);

if (!$curso_data) {
    echo json_encode(['success' => false, 'error' => 'La IA no devolvió un JSON válido.']);
    exit;
}

// =========================================================================
// 6. GUARDADO BLINDADO EN BASE DE DATOS
// =========================================================================
try {
    $database = new Database();
    $db = $database->getConnection();
    $db->beginTransaction();

    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $curso_data['titulo_curso'])));

    $stmt_curso = $db->prepare("INSERT INTO ah_courses (title, slug, description, status) VALUES (:title, :slug, :description, 'draft')");
    $stmt_curso->execute([':title' => $curso_data['titulo_curso'], ':slug' => $slug, ':description' => $curso_data['resumen']]);
    $course_id = $db->lastInsertId();

    $orden_modulo = 0;
    foreach ($curso_data['modulos'] as $modulo) {
        $stmt_mod = $db->prepare("INSERT INTO ah_modules (course_id, title, sort_order) VALUES (:cid, :title, :ord)");
        $stmt_mod->execute([':cid' => $course_id, ':title' => $modulo['titulo_modulo'], ':ord' => $orden_modulo]);
        $module_id = $db->lastInsertId();
        $orden_modulo++;

        $orden_leccion = 0;
        foreach ($modulo['lecciones'] as $leccion) {
            
            // LIMPIADOR DE TEXTO NIVEL EXPERTO: Traduce "dobles escapes" de la IA a español puro
            $html_limpio = $leccion['contenido_html'];
            $html_limpio = str_replace(['\\\\', '\\/'], ['\\', '/'], $html_limpio);
            $html_limpio = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
                return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
            }, $html_limpio);

            $builder_payload = json_encode([
                [
                    "type" => "text",
                    "value" => $html_limpio
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt_less = $db->prepare("INSERT INTO ah_lessons (module_id, title, content_type, content_html, sort_order) VALUES (:mid, :title, 'builder', :html, :ord)");
            $stmt_less->execute([
                ':mid' => $module_id,
                ':title' => $leccion['titulo_leccion'],
                ':html' => $builder_payload,
                ':ord' => $orden_leccion
            ]);
            $orden_leccion++;
        }
    }

    $db->commit();
    echo json_encode(['success' => true, 'mensaje' => 'Curso interactivo generado exitosamente.', 'course_id' => $course_id]);

} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error de Base de Datos: ' . $e->getMessage()]);
}
?>
