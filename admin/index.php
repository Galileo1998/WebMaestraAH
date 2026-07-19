<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

// Obtener todas las páginas de la base de datos
$query = "SELECT id, title, slug, status, created_at FROM pages ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Páginas | Acción Honduras CMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg-canvas: #f8fafc; --text-main: #1e293b; --white: #ffffff; }
        
        /* El body debe ser flex para que el sidebar y el contenido se pongan lado a lado */
        body { font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; background: var(--bg-canvas); margin: 0; overflow-x: hidden; }
        
        /* El contenedor principal ocupa el resto del espacio */
        .main-wrapper { flex-grow: 1; padding: 40px; overflow-y: auto; width: 100%; transition: all 0.3s ease; }
        
        .page-header { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .page-header h1 { font-size: 1.8rem; color: var(--text-main); margin: 0; }
        .btn-primary { background: linear-gradient(135deg, var(--ah-primary), var(--ah-accent)); color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
        
        /* Tabla de páginas */
        .table-container { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f1f5f9; padding: 16px 20px; color: #64748b; font-size: 0.85rem; text-transform: uppercase; }
        td { padding: 16px 20px; border-bottom: 1px solid #e2e8f0; color: var(--text-main); }
        tr:last-child td { border-bottom: none; }
        .action-btn { background: #e2e8f0; color: var(--ah-primary); padding: 8px 12px; border-radius: 6px; text-decoration: none; font-size: 0.9rem; transition: 0.2s; margin-right: 5px; display: inline-block;}
        .action-btn:hover { background: var(--ah-primary); color: white; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <main class="main-wrapper">
        <div class="page-header">
            <h1>Gestor de Páginas</h1>
            <a href="builder.php?action=new" class="btn-primary"><i class="fa-solid fa-plus"></i> Crear Nueva Página</a>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Título de la Página</th>
                        <th>URL (Slug)</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pages as $p): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($p['title']); ?></strong></td>
                        <td>/<?php echo htmlspecialchars($p['slug']); ?></td>
                        <td><span style="background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 20px; font-size: 0.8rem; font-weight: bold;">Publicada</span></td>
                        <td>
                            <a href="builder.php?id=<?php echo $p['id']; ?>" class="action-btn"><i class="fa-solid fa-pen-ruler"></i> Editar Diseño</a>
                            <a href="../public/<?php echo $p['slug']; ?>" target="_blank" class="action-btn" style="background: #f1f5f9; color: #64748b;"><i class="fa-solid fa-eye"></i> Ver</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>