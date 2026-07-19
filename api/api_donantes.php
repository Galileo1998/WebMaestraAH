<?php
// public/api_donantes.php
require_once '../config/Database.php';

// Indicamos al navegador que este archivo devuelve un JSON, no HTML
header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

try {
    $stmt = $db->query("SELECT id, name, logo_url, description FROM partners WHERE status = 'active' ORDER BY name ASC");
    $socios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Imprimimos los datos estructurados en JSON
    echo json_encode($socios);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Fallo al conectar con la base de datos"]);
}