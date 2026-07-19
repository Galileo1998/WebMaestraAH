<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$csrf_token = Auth::generateCSRF();
$msg = "";

$page_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

if ($page_id === 0) {
    header("Location: paginas.php");
    exit;
}

// ==========================================
// PROCESAR ACTUALIZACIÓN DE LA PÁGINA
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_page') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $meta_description = trim($_POST['meta_description']);
    $status = $_POST['status'];
    
    // Mantener la imagen actual por defecto
    $meta_image_path = $_POST['current_meta_image'] ?? '';

    // Procesar la subida de imagen si el usuario seleccionó una nueva
    if (isset($_FILES['meta_image']) && $_FILES['meta_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/images/';
        
        // Creamos el directorio si no existe por seguridad
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $upload_result = Auth::secureImageUpload($_FILES['meta_image'], $upload_dir);
        
        if ($upload_result['success']) {
            // Guardamos la ruta relativa adecuada para el frontend
            $meta_image_path = 'uploads/images/' . $upload_result['filename'];
        } else {
            $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> " . $upload_result['error'] . "</div>";
        }
    }

    if (!empty($title) && empty($msg)) {
        try {
            $query = "UPDATE pages SET 
                        title = :title, 
                        content_html = :content, 
                        meta_description = :meta_description, 
                        meta_image = :meta_image, 
                        status = :status 
                      WHERE id = :id";
                      
            $stmt = $db->prepare($query);
            $stmt->execute([
                'title' => $title,
                'content' => $content,
                'meta_description' => $meta_description,
                'meta_image' => $meta_image_path,
                'status' => $status,
                'id' => $page_id
            ]);

            $msg = "<div class='alert success'><i class='fa-solid fa-circle-check'></i> Página y metadatos guardados con éxito.</div>";
        } catch (PDOException $e) {
            $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error al guardar en la base de datos.</div>";
        }
    }
}

// ==========================================
// CARGAR DATOS ACTUALES DE LA PÁGINA
// ==========================================
$stmt = $db->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $page_id]);
$pagina = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pagina) {
    header("Location: paginas.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configurar Página | AH Admin Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; flex-shrink: 0; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-content { padding: 24px; flex-grow: 1; }
        .nav-link { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; gap: 10px; padding: 10px 0; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { color: white; }
        .nav-link.active i { color: var(--ah-accent); }
        
        .main { flex-grow: 1; padding: 40px; box-sizing: border-box; overflow-y: auto; }
        .card { background: white; border-radius: 12px; padding: 35px; box-shadow: 0 4px 15px rgba(0,0,0,0.01); border: 1px solid var(--border); }
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: var(--ah-primary); box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.15); }
        
        .seo-box { background: #f8fafc; padding: 25px; border-radius: 10px; border: 1px solid var(--border); margin-top: 15px; }
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 14px 30px; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; font-size: 1rem; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>

    <aside class="sidebar">

        <?php include 'sidebar.php'; ?>

    </aside>


    <main class="main">
        <div class="card">
            <div style="margin-bottom: 20px;">
                <a href="index.php" style="color: #64748b; text-decoration: none; font-weight: 600;"><i class="fa-solid fa-arrow-left"></i> Volver a Páginas</a>
            </div>

            <h1 style="margin-top: 0; color: #0f172a;"><i class="fa-solid fa-file-pen" style="color: var(--ah-primary);"></i> Configurar Contenido y SEO de Página</h1>
            <p style="color: #64748b; margin-top: -10px; margin-bottom: 30px;">Modifica el diseño del maquetador y optimiza los gráficos de previsualización social.</p>

            <?php echo $msg; ?>

            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_page">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="current_meta_image" value="<?php echo htmlspecialchars($pagina['meta_image'] ?? ''); ?>">

                <div class="form-group">
                    <label>Título de la Página (H1)</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($pagina['title']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Contenido del Maquetador (HTML del Builder)</label>
                    <textarea name="content" class="form-control" rows="8" style="font-family: monospace; font-size: 0.9rem;"><?php echo htmlspecialchars($pagina['content_html']); ?></textarea>
                </div>

                <div class="form-group">
                    <label><i class="fa-solid fa-share-nodes" style="color: var(--ah-primary);"></i> Optimización para Redes Sociales (Meta Tags / Open Graph)</label>
                    <div class="seo-box">
                        
                        <div class="form-group">
                            <label>Descripción para Redes Sociales (WhatsApp / Facebook)</label>
                            <textarea name="meta_description" class="form-control" rows="2" placeholder="Escribe un extracto atractivo para captar clics..."><?php echo htmlspecialchars($pagina['meta_description'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Imagen de Portada Compartida (Recomendado: 1200 x 630 px)</label>
                            <input type="file" name="meta_image" class="form-control" accept="image/*">
                            
                            <?php if (!empty($pagina['meta_image'])): ?>
                                <div style="margin-top: 15px; display: flex; align-items: center; gap: 15px;">
                                    <img src="../<?php echo $pagina['meta_image']; ?>" style="max-height: 80px; border-radius: 6px; border: 1px solid var(--border);">
                                    <span style="font-size: 0.85rem; color: #64748b;"><i class="fa-solid fa-image"></i> Imagen actual guardada en el servidor.</span>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

                <div class="form-group" style="max-width: 200px;">
                    <label>Estado</label>
                    <select name="status" class="form-control">
                        <option value="published" <?php echo $pagina['status'] == 'published' ? 'selected' : ''; ?>>Publicada</option>
                        <option value="draft" <?php echo $pagina['status'] == 'draft' ? 'selected' : ''; ?>>Borrador</option>
                    </select>
                </div>

                <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Guardar Todo</button>
            </form>
        </div>
    </main>

</body>
</html>