<?php
// api/delete_photo.php
header("Content-Type: application/json");
require '../db_config.php'; // Asegúrate de que la ruta sea correcta

// 1. Recibir el ID de la foto
$input = json_decode(file_get_contents("php://input"), true);
$photoId = $input['id'] ?? null;

if (!$photoId) {
    echo json_encode(["success" => false, "error" => "ID no recibido"]);
    exit;
}

try {
    // 2. Obtener la ruta del archivo antes de borrar el registro
    $stmt = $pdo->prepare("SELECT file_path FROM photos WHERE id = ?");
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($photo) {
        // La ruta en la BD suele guardarse como "uploads/foto.jpg"
        // Ajustamos la ruta relativa desde la carpeta 'api' hacia la carpeta raíz
        $filePath = "../" . $photo['file_path']; 

        // 3. Borrar el archivo físico si existe
        if (file_exists($filePath)) {
            unlink($filePath); 
        }

        // 4. Borrar el registro de la Base de Datos
        $delStmt = $pdo->prepare("DELETE FROM photos WHERE id = ?");
        $delStmt->execute([$photoId]);

        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "Foto no encontrada"]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>