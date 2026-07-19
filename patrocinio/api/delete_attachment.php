<?php
// api/delete_attachment.php
header("Content-Type: application/json");
require '../db_config.php';

// 1. Recibir ID
$input = json_decode(file_get_contents("php://input"), true);
$id = $input['id'] ?? null;

if (!$id) {
    echo json_encode(["success" => false, "error" => "ID no recibido"]);
    exit;
}

try {
    // 2. Obtener la ruta para borrar el archivo físico
    $stmt = $pdo->prepare("SELECT file_path FROM letter_attachments WHERE id = ?");
    $stmt->execute([$id]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($att) {
        $filePath = "../" . $att['file_path']; // Ajustar ruta relativa

        // 3. Borrar archivo del disco
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // 4. Borrar registro de la BD
        $del = $pdo->prepare("DELETE FROM letter_attachments WHERE id = ?");
        $del->execute([$id]);

        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "Archivo no encontrado"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>