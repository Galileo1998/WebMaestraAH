<?php
// =================================================================
// ARCHIVO: api/save_news.php
// CEREBRO DE GUARDADO (Actualizado para SEO y Galería)
// =================================================================

header("Content-Type: application/json; charset=UTF-8");
require_once '../config/Database.php';

// Opcional: Validar si el usuario está logueado
// require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();

// Capturar el paquete JSON que envía el frontend (fetch)
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "error" => "No se recibieron datos válidos."]);
    exit;
}

// Extraer variables
$id = isset($data['id']) ? (int)$data['id'] : 0;
$title = $data['title'] ?? '';
$slug = $data['slug'] ?? '';
$excerpt = $data['excerpt'] ?? '';
$cover_image = $data['cover_image'] ?? '';
$content_html = $data['content_html'] ?? '';
$status = $data['status'] ?? 'draft';

// NUEVOS CAMPOS: SEO y Galería
$meta_title = $data['meta_title'] ?? '';
$meta_description = $data['meta_description'] ?? '';
$gallery = $data['gallery'] ?? []; // Esto es un arreglo (Array) de URLs

try {
    // 1. GUARDAR O ACTUALIZAR LA NOTICIA PRINCIPAL
    if ($id > 0) {
        // Modo Edición
        $query = "UPDATE news 
                  SET title = :title, slug = :slug, excerpt = :excerpt, 
                      cover_image = :cover_image, content_html = :content_html, 
                      status = :status, meta_title = :meta_title, meta_description = :meta_description 
                  WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':title' => $title, ':slug' => $slug, ':excerpt' => $excerpt, 
            ':cover_image' => $cover_image, ':content_html' => $content_html, 
            ':status' => $status, ':meta_title' => $meta_title, 
            ':meta_description' => $meta_description, ':id' => $id
        ]);
        $news_id = $id;
    } else {
        // Modo Creación Nueva
        $query = "INSERT INTO news (title, slug, excerpt, cover_image, content_html, status, meta_title, meta_description, created_at) 
                  VALUES (:title, :slug, :excerpt, :cover_image, :content_html, :status, :meta_title, :meta_description, NOW())";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':title' => $title, ':slug' => $slug, ':excerpt' => $excerpt, 
            ':cover_image' => $cover_image, ':content_html' => $content_html, 
            ':status' => $status, ':meta_title' => $meta_title, 
            ':meta_description' => $meta_description
        ]);
        $news_id = $db->lastInsertId();
    }

    // ==========================================
    // 2. LÓGICA DE GUARDADO DE LA GALERÍA
    // ==========================================
    
    // Primero: Borramos la galería actual de esta noticia para evitar duplicados si se está editando
    $stmt_del = $db->prepare("DELETE FROM news_gallery WHERE news_id = :id");
    $stmt_del->execute([':id' => $news_id]);

    // Segundo: Insertamos las imágenes que el usuario acaba de enviar
    if (!empty($gallery) && is_array($gallery)) {
        $stmt_img = $db->prepare("INSERT INTO news_gallery (news_id, image_path) VALUES (:nid, :path)");
        foreach ($gallery as $img_path) {
            $stmt_img->execute([
                ':nid' => $news_id, 
                ':path' => $img_path
            ]);
        }
    }

    // Enviar respuesta de éxito al editor visual
    echo json_encode(["success" => true, "id" => $news_id]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error de Base de Datos: " . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Error General: " . $e->getMessage()]);
}
exit;