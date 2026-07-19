<?php
// upload_photo.php
include 'db_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['photo'])) {
    $local_id = $_POST['letter_id'];
    $target_dir = "uploads/photos/";
    
    // Crear directorio si no existe
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_extension = pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION);
    $new_filename = $local_id . "_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO photos (letter_id, photo_url) VALUES (?, ?) 
                                   ON DUPLICATE KEY UPDATE photo_url = VALUES(photo_url)");
            $stmt->execute([$local_id, $target_file]);
            echo json_encode(["success" => true, "url" => $target_file]);
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
    } else {
        echo json_encode(["success" => false, "error" => "Error al mover el archivo"]);
    }
}
?>