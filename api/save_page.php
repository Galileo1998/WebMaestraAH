<?php
session_start();
require_once '../config/Database.php';

header('Content-Type: application/json');

// 1. Verificación de Seguridad
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Sesión no iniciada o no autorizada']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 2. Captura del Input
$raw_input = file_get_contents("php://input");
$data = json_decode($raw_input);

// Verificación de que el JSON es válido
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'JSON inválido recibido', 'raw' => $raw_input]);
    exit;
}

// 3. Validación de campos obligatorios
if (!empty($data->title) && !empty($data->slug)) {
    try {
        // Aseguramos que los campos tengan valores por defecto si no se envían
        $content_html = isset($data->content_html) ? $data->content_html : '';
        $meta_description = isset($data->meta_description) ? $data->meta_description : '';
        $meta_image = isset($data->meta_image) ? $data->meta_image : '';
        
        if (!empty($data->page_id) && is_numeric($data->page_id)) {
            // ACTUALIZAR PÁGINA EXISTENTE
            $query = "UPDATE pages SET 
                        title = :title, 
                        slug = :slug, 
                        content_html = :content_html,
                        meta_description = :meta_description,
                        meta_image = :meta_image
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $data->page_id);
        } else {
            // CREAR NUEVA PÁGINA
            $query = "INSERT INTO pages (title, slug, status, content_html, meta_description, meta_image) 
                      VALUES (:title, :slug, 'published', :content_html, :meta_description, :meta_image)";
            $stmt = $db->prepare($query);
        }

        // Vincular todos los parámetros de forma segura
        $stmt->bindParam(':title', $data->title);
        $stmt->bindParam(':slug', $data->slug);
        $stmt->bindParam(':content_html', $content_html);
        $stmt->bindParam(':meta_description', $meta_description);
        $stmt->bindParam(':meta_image', $meta_image);
        
        if ($stmt->execute()) {
            $current_id = !empty($data->page_id) ? $data->page_id : $db->lastInsertId();
            echo json_encode([
                'success' => true, 
                'page_id' => $current_id,
                'message' => 'Datos guardados correctamente'
            ]);
        } else {
            // Capturamos el error específico del Statement
            $errorInfo = $stmt->errorInfo();
            echo json_encode(['error' => 'Error SQL: ' . $errorInfo[2]]);
        }
    } catch(PDOException $e) {
        // Error de BD (ej: slug duplicado)
        echo json_encode(['error' => 'Excepción de BD: ' . $e->getMessage()]);
    }
} else {
    // Si entra aquí, es que JS no envió el título o el slug
    echo json_encode([
        'error' => 'Datos incompletos desde el cliente', 
        'recibido' => [
            'title' => $data->title ?? 'VACÍO',
            'slug' => $data->slug ?? 'VACÍO'
        ]
    ]);
}
?>