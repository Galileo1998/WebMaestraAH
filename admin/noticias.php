<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$csrf_token = Auth::generateCSRF();

// 1. Lógica para eliminar una noticia si se solicita
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    Auth::checkCSRF($_GET['token'] ?? '');
    $del_id = $_GET['delete'];
    $del_stmt = $db->prepare("DELETE FROM news WHERE id = :id");
    $del_stmt->bindParam(':id', $del_id);
    if ($del_stmt->execute()) {
        header("Location: noticias.php?msg=deleted");
        exit;
    }
}

// 2. EXTRAER NOTICIAS (Con el 'slug' incluido para evitar errores en los botones)
$query = "SELECT id, title, slug, status, created_at, cover_image FROM news ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$noticias = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Noticias | Acción Honduras Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg: #f8fafc; --text: #1e293b; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        
        /* Sidebar (Consistente con el resto del panel) */
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-content { padding: 24px; flex-grow: 1; }
        .nav-link { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; gap: 10px; padding: 10px 0; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { color: white; }
        .nav-link.active i { color: #46B094; }
        
        /* Contenido Principal */
        .main { flex-grow: 1; padding: 40px; overflow-y: auto; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .btn-new { background: var(--ah-primary); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; transition: 0.2s; }
        .btn-new:hover { background: #2c7285; }
        
        /* Tabla de Noticias */
        .data-table { width: 100%; background: white; border-radius: 12px; border-collapse: collapse; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .data-table th, .data-table td { padding: 16px 20px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .data-table th { background: #f1f5f9; color: #64748b; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; }
        .data-table tr:hover { background: #f8fafc; }
        
        /* Elementos de la tabla */
        .news-img { width: 60px; height: 40px; border-radius: 4px; object-fit: cover; background: #e2e8f0; }
        .status-badge { padding: 4px 10px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; }
        .status-published { background: #dcfce7; color: #166534; }
        .status-draft { background: #fef08a; color: #854d0e; }
        
        .action-links a { color: #64748b; margin-right: 15px; text-decoration: none; transition: 0.2s; }
        .action-links a:hover { color: var(--ah-primary); }
        .action-links a.delete:hover { color: #ef4444; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <?php include 'sidebar.php'; ?>
    </aside>

    <main class="main">
        <div class="page-header">
            <div>
                <h1 style="margin-bottom: 5px;">Noticias y Actualizaciones</h1>
                <p style="color: #64748b; margin: 0;">Gestiona los artículos del blog institucional.</p>
            </div>
            <!-- Botón que lleva al Redactor -->
            <a href="editar_noticia.php" class="btn-new"><i class="fa-solid fa-plus"></i> Redactar Noticia</a>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fa-solid fa-trash"></i> Noticia eliminada correctamente.
            </div>
        <?php endif; ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 80px;">Portada</th>
                    <th>Título</th>
                    <th>Estado</th>
                    <th>Fecha de Creación</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($noticias)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="fa-solid fa-newspaper" style="font-size: 3rem; margin-bottom: 15px; color: #cbd5e1;"></i>
                            <p>Aún no hay noticias publicadas.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($noticias as $noticia): ?>
                        <tr>
                            <td>
                                <?php if (!empty($noticia['cover_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($noticia['cover_image']); ?>" class="news-img" alt="Portada">
                                <?php else: ?>
                                    <div class="news-img" style="display:flex; align-items:center; justify-content:center; color:#94a3b8;"><i class="fa-solid fa-image"></i></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600;"><?php echo htmlspecialchars($noticia['title']); ?></td>
                            <td>
                                <span class="status-badge <?php echo $noticia['status'] == 'published' ? 'status-published' : 'status-draft'; ?>">
                                    <?php echo $noticia['status'] == 'published' ? 'Publicado' : 'Borrador'; ?>
                                </span>
                            </td>
                            <td style="color: #64748b; font-size: 0.9rem;">
                                <?php echo date('d M, Y', strtotime($noticia['created_at'])); ?>
                            </td>
                            <td style="text-align: right;" class="action-links">
                                <a href="../public/noticia_single.php?slug=<?php echo $noticia['slug']; ?>" target="_blank" title="Ver"><i class="fa-solid fa-eye"></i></a>
                                <a href="editar_noticia.php?id=<?php echo $noticia['id']; ?>" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                                <a href="noticias.php?delete=<?php echo $noticia['id']; ?>&amp;token=<?php echo urlencode($csrf_token); ?>" class="delete" onclick="return confirm('¿Estás seguro de eliminar esta noticia?');" title="Eliminar"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </main>

</body>
</html>
