<?php
// upload_letter_data.php
include 'db_config.php';

header('Content-Type: application/json');

// Recibir el cuerpo de la petición (JSON)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['local_id'])) {
    echo json_encode(["error" => "Datos inválidos"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Actualizar sentimientos y estado en la tabla 'letters'
    $stmt = $pdo->prepare("UPDATE letters SET text_feelings = ?, status = 'COMPLETADA', updated_at = NOW() WHERE local_id = ?");
    $stmt->execute([$data['text_feelings'], $data['local_id']]);

    // 2. Insertar o actualizar el dibujo
    if (isset($data['drawing'])) {
        $stmtDraw = $pdo->prepare("INSERT INTO drawings (letter_id, svg_xml) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE svg_xml = VALUES(svg_xml)");
        $stmtDraw->execute([$data['local_id'], $data['drawing']]);
    }

    // 3. Insertar mensajes (si existen)
    if (isset($data['messages']) && is_array($data['messages'])) {
        foreach ($data['messages'] as $msg) {
            $stmtMsg = $pdo->prepare("INSERT INTO messages (letter_id, text_message) VALUES (?, ?)");
            $stmtMsg->execute([$data['local_id'], $msg]);
        }
    }

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Carta subida correctamente"]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(["error" => $e->getMessage()]);
}
?>