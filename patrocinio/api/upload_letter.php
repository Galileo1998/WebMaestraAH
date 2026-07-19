<?php
// api/upload_letter.php
header("Content-Type: application/json");
require '../db_config.php';

// --- DEBUG: GUARDAR LOG ---
$logData = "--- NUEVO INTENTO " . date('Y-m-d H:i:s') . " ---\n";
$logData .= "POST: " . print_r($_POST, true) . "\n";
$logData .= "FILES: " . print_r($_FILES, true) . "\n";
file_put_contents("debug_log.txt", $logData, FILE_APPEND);
// --------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["error" => "Metodo no permitido"]);
    exit;
}

$letter_id = $_POST['server_id'] ?? null;
$message   = $_POST['message'] ?? '';

if (!$letter_id) {
    echo json_encode(["error" => "Falta server_id"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Actualizar Mensaje
    $sql = "UPDATE letters SET final_message = ?, status = 'COMPLETADO', updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$message, $letter_id]);

    // 2. Crear carpeta si no existe
    $target_dir = "../uploads/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    // 3. Procesar DIBUJO
    if (isset($_FILES['drawing']) && $_FILES['drawing']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['drawing']['name'], PATHINFO_EXTENSION);
        $filename = "draw_" . $letter_id . "_" . time() . ".png"; // Forzamos png o usas $ext
        $target_file = $target_dir . $filename;
        $db_path = "uploads/" . $filename;

        if (move_uploaded_file($_FILES['drawing']['tmp_name'], $target_file)) {
            $stmt = $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_type, file_path) VALUES (?, 'DRAWING', ?)");
            $stmt->execute([$letter_id, $db_path]);
        }
    }

    // 4. Procesar FOTOS
    foreach ($_FILES as $key => $file) {
        if (strpos($key, 'photo_') === 0 && $file['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "photo_" . $letter_id . "_" . uniqid() . "." . $ext;
            $target_file = $target_dir . $filename;
            $db_path = "uploads/" . $filename;

            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                $stmt = $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_type, file_path) VALUES (?, 'PHOTO', ?)");
                $stmt->execute([$letter_id, $db_path]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Subida correcta"]);

} catch (Exception $e) {
    $pdo->rollBack();
    // Guardar error en el log tambien
    file_put_contents("debug_log.txt", "ERROR SQL: " . $e->getMessage() . "\n", FILE_APPEND);
    
    http_response_code(500);
    echo json_encode(["error" => "Error servidor: " . $e->getMessage()]);
}
?>