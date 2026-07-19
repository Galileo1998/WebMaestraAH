<?php
// Habilitar el control de sesiones si lo requieres para proteger la API
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Declarar explícitamente que este archivo devuelve JSON estructurado y no código HTML
header('Content-Type: application/json; charset=utf-8');

// Habilitar CORS por si tu HTML corre en un puerto diferente (ej: desarrollo local)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

// 1. IMPORTAR LA CONFIGURACIÓN DE TU BASE DE DATOS
require_once __DIR__ . '/../config/Database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    // 2. EXTRAER LOS CURSOS PUBLICADOS
// 2. EXTRAER LOS CURSOS PUBLICADOS (Con los nombres reales de tu DB)
    $query = "SELECT id, title, slug, description, cover_image 
              FROM ah_courses 
              WHERE status = 'published' 
              ORDER BY created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. RESPUESTA EXITOSA EN FORMATO JSON
    echo json_encode($cursos, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // Si la base de datos falla, devolvemos un código HTTP 500 y el error en JSON
    http_response_code(500);
    echo json_encode([
        'error' => 'Fallo interno en el servidor de datos',
        'details' => $e->getMessage()
    ]);
}
exit;