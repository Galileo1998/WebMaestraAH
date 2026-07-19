<?php
header("Content-Type: application/json");
require '../db_config.php';

$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'] ?? null;
$reason = $data['reason'] ?? '';

if (!$id || empty($reason)) {
    echo json_encode(["success" => false, "error" => "Faltan datos"]);
    exit;
}

try {
    // Cambiamos estado a RETURNED y guardamos la razón
    // OJO: Agrega la columna return_reason a tu tabla letters en MySQL si no existe:
    // ALTER TABLE letters ADD COLUMN return_reason TEXT NULL;
    
    $sql = "UPDATE letters SET status = 'RETURNED', return_reason = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reason, $id]);

    echo json_encode(["success" => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>