<?php
// api/get_news.php
header('Content-Type: application/json');
require_once '../config/Database.php';

$database = new Database();
$db = $database->getConnection();

$por_pagina = 6;
$pagina_actual = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $por_pagina;

try {
    // Total de páginas
    $count_stmt = $db->prepare("SELECT COUNT(id) FROM news WHERE status = 'published'");
    $count_stmt->execute();
    $total = $count_stmt->fetchColumn();
    $total_paginas = ceil($total / $por_pagina);

    // Obtener las noticias
    $query = "SELECT title, slug, excerpt, cover_image, created_at FROM news WHERE status = 'published' ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', $por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $noticias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear fechas y fotos por defecto
    foreach ($noticias as &$n) {
        $n['fecha_formateada'] = date('d M Y', strtotime($n['created_at']));
        if (empty($n['cover_image'])) {
            $n['cover_image'] = 'https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?q=80&w=800'; // Imagen por defecto
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $noticias,
        'pagination' => [
            'current' => $pagina_actual,
            'total_pages' => $total_paginas
        ]
    ]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error de BD']);
}