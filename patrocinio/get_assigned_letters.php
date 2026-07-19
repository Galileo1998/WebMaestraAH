<?php
// api/get_assigned_letters.php
header("Content-Type: application/json");
require '../db_config.php';

// 1. Recibir el JSON enviado desde la App
$input = json_decode(file_get_contents("php://input"), true);
$phone = $input['phone'] ?? '';

// Validación básica
if (empty($phone)) {
    echo json_encode(["error" => "El servidor no recibió el teléfono."]);
    exit;
}

try {
    // 2. BUSCAR EL ID DEL TÉCNICO USANDO EL TELÉFONO
    // Asumimos que tu tabla se llama 'technicians' y tiene columna 'phone'
    $stmt = $pdo->prepare("SELECT id, full_name FROM technicians WHERE phone = ? LIMIT 1");
    $stmt->execute([$phone]);
    $tech = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tech) {
        // Si el teléfono no existe en la base de datos del servidor
        echo json_encode(["error" => "No se encontró un técnico con el teléfono: " . $phone]);
        exit;
    }

    $tech_id = $tech['id']; // ¡Aquí obtenemos el ID que faltaba!

    // 3. BUSCAR CARTAS ASIGNADAS A ESE ID
    // Buscamos cartas que NO estén completadas (PENDIENTE, etc.)
    $sql_letters = "SELECT * FROM letters 
                    WHERE tech_id = ? 
                    AND status != 'COMPLETADO' 
                    ORDER BY due_date ASC";
    
    $stmt = $pdo->prepare($sql_letters);
    $stmt->execute([$tech_id]);
    $letters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Devolver las cartas en formato JSON
    echo json_encode($letters);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => "Error del Servidor: " . $e->getMessage()]);
}
?>