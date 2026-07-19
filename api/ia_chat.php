<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../classes/Auth.php';

$isAdmin = ($_SESSION['logged_in'] ?? false) === true && ($_SESSION['auth_source'] ?? '') === 'admin';
$isStudent = isset($_SESSION['user_id']) && ($_SESSION['rol'] ?? '') === 'student';
if (!$isAdmin && !$isStudent) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado.']);
    exit;
}
Auth::enforceSameOriginRequest();

$now = time();
$window = array_values(array_filter(
    (array)($_SESSION['ia_request_times'] ?? []),
    static fn($timestamp) => is_int($timestamp) && ($now - $timestamp) < 60
));
if (count($window) >= 20) {
    http_response_code(429);
    echo json_encode(['error' => 'Demasiadas solicitudes. Espere un minuto.']);
    exit;
}
$window[] = $now;
$_SESSION['ia_request_times'] = $window;
// Ocultamos errores de PHP para que no arruinen el formato JSON de respuesta
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

// Capturar payload
$input = json_decode(file_get_contents('php://input'), true);
$user_message = $input['message'] ?? '';
$lesson_context = $input['context'] ?? '';
$user_message = mb_substr(trim((string)$user_message), 0, 4000, 'UTF-8');
$lesson_context = mb_substr((string)$lesson_context, 0, 20000, 'UTF-8');

if (empty($user_message)) {
    echo json_encode(['error' => 'El mensaje está vacío']);
    exit;
}

// ==========================================
// CONFIGURACIÓN GROQ (En la nube & Gratis)
// ==========================================
$api_key = trim((string) getenv('GROQ_API_KEY'));
$secrets_file = dirname(__DIR__, 2) . '/.accionhonduras_secrets.php';
if ($api_key === '' && is_file($secrets_file)) {
    $secrets = require $secrets_file;
    if (is_array($secrets)) {
        $api_key = trim((string) ($secrets['GROQ_API_KEY'] ?? ''));
    }
}

if ($api_key === '') {
    http_response_code(503);
    echo json_encode(['error' => 'El tutor virtual no está configurado temporalmente.']);
    exit;
}
$url = 'https://api.groq.com/openai/v1/chat/completions'; 

$payload = [
    'model' => 'llama-3.1-8b-instant', 
    'messages' => [
        [
            'role' => 'system',
            'content' => "Eres un Tutor Virtual e Inteligente para la organización Acción Honduras. Tu objetivo es resolver dudas de los estudiantes de forma clara, empática y pedagógica. Responde de forma directa y concisa. Contexto de la lección actual: \n\n" . $lesson_context
        ],
        [
            'role' => 'user',
            'content' => $user_message
        ]
    ],
    'temperature' => 0.7
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

// ========================================================
// ESCUDOS PARA EL SERVIDOR (CRÍTICOS PARA HOSTING EN LA NUBE)
// ========================================================
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Evita el choque de red (Failed to connect)
curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Groq es rápido, 30s es suficiente
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'); // Camuflaje
// ========================================================

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    echo json_encode(['error' => 'Fallo en cURL al conectar con Groq: ' . $error_msg]);
    curl_close($ch);
    exit;
}

curl_close($ch);

// Retornamos la respuesta, Groq usa exactamente la misma estructura que OpenAI
echo $response;
