<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$csrf_token = Auth::generateCSRF();

$msg = "";

// 1. Crear la tabla si no existe (Protección a prueba de fallos)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS menu_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(100) NOT NULL,
        url VARCHAR(255) NOT NULL,
        position INT DEFAULT 0
    )");
} catch(PDOException $e) {}

// 2. Procesar la adición de un nuevo enlace
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_link') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');
    $label = trim($_POST['label']);
    $url = trim($_POST['url']);
    $position = (int)$_POST['position'];

    if (!empty($label) && !empty($url)) {
        $stmt = $db->prepare("INSERT INTO menu_items (label, url, position) VALUES (:l, :u, :p)");
        $stmt->execute(['l' => $label, 'u' => $url, 'p' => $position]);
        $msg = "<div class='alert success'><i class='fa-solid fa-check'></i> Enlace agregado al menú.</div>";
    } else {
        $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Debes llenar todos los campos.</div>";
    }
}

// 3. Procesar la eliminación de un enlace
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    Auth::checkCSRF($_GET['token'] ?? '');
    $stmt = $db->prepare("DELETE FROM menu_items WHERE id = :id");
    $stmt->execute(['id' => $_GET['delete']]);
    header("Location: apariencia.php?msg=deleted");
    exit;
}

if (isset($_GET['msg']) && $_GET['msg'] == 'deleted') {
    $msg = "<div class='alert success'><i class='fa-solid fa-trash'></i> Enlace eliminado del menú.</div>";
}

// 4. Obtener los enlaces actuales
$stmt = $db->query("SELECT * FROM menu_items ORDER BY position ASC");
$menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Obtener las páginas creadas para ayudar al usuario
$stmt_pages = $db->query("SELECT title, slug FROM pages ORDER BY title ASC");
$paginas_creadas = $stmt_pages->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Menú | Acción Honduras Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; position: relative; }
        
        /* SIDEBAR BASE REFACTORIZADO */
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; flex-shrink: 0; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); z-index: 100; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-content { padding: 24px; flex-grow: 1; }
        .nav-link { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; gap: 10px; padding: 10px 0; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { color: white; }
        .nav-link.active i { color: #46B094; }
        
        /* CONTENEDOR PRINCIPAL */
        .main { flex-grow: 1; padding: 40px; overflow-y: auto; width: 100%; box-sizing: border-box; }
        .page-header { margin-bottom: 30px; }
        
        /* BOTÓN HAMBURGUESA MÓVIL (Oculto en Escritorio) */
        .mobile-nav-toggle { display: none; background: #0f172a; color: white; border: none; padding: 12px 16px; border-radius: 8px; font-size: 1.2rem; cursor: pointer; position: fixed; bottom: 20px; right: 20px; z-index: 110; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: all 0.2s; }
        .mobile-nav-toggle:hover { background: var(--ah-primary); }

        /* OVERLAY / SOMBRA DE FONDO MÓVIL */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(2px); z-index: 90; }
        .sidebar-overlay.active { display: block; }

        /* COMPONENTES DE INTERFAZ */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }

        .card { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; }
        .btn-save { background: #46B094; color: white; border: none; padding: 12px 25px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn-save:hover { background: #3eb092; }

        /* CONTENEDOR EN REJILLA ADAPTATIVO */
        .layout-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 30px; }

        /* DISEÑO DE TABLAS */
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
        .data-table th { color: #64748b; text-transform: uppercase; font-size: 0.8rem; }
        .badge-url { background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-family: monospace; font-size: 0.85rem; color: #34859B; display: inline-block; max-width: 200px; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }

        /* === MEDIA QUERIES PARA DISPOSITIVOS MÓVILES === */
        @media (max-width: 992px) {
            .layout-grid { grid-template-columns: 1fr; } /* Colapsa el formulario sobre la tabla */
        }

        @media (max-width: 768px) {
            body { padding-bottom: 60px; } /* Deja espacio para el botón flotante inferior */
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .mobile-nav-toggle { display: block; } /* Mostrar botón hamburguesa */
            .main { padding: 20px; }
            .page-header h1 { font-size: 1.8rem; }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>

    <button type="button" class="mobile-nav-toggle" id="menuToggleBtn" aria-label="Abrir navegación">
        <i class="fa-solid fa-bars" id="menuIcon"></i>
    </button>

    <div class="sidebar-overlay" id="menuOverlay"></div>

    <aside class="sidebar" id="adminSidebar">
        <?php include 'sidebar.php'; ?>
    </aside>

    <main class="main">
        <div class="page-header">
            <h1 style="margin-bottom: 5px;">Gestor de Menú de Navegación</h1>
            <p style="color: #64748b; margin: 0;">Agrega y organiza los enlaces que aparecen en la cabecera de tu sitio web.</p>
        </div>

        <?php echo $msg; ?>

        <div class="layout-grid">
            
            <div class="card" style="background: #f8fafc; border: 1px solid var(--border); height: fit-content;">
                <h3 style="margin-bottom: 20px; color: var(--ah-primary); margin-top:0;"><i class="fa-solid fa-plus"></i> Nuevo Enlace</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="add_link">
                    
                    <div class="form-group">
                        <label>Texto del Enlace</label>
                        <input type="text" name="label" class="form-control" placeholder="Ej: Nuestra Historia" required>
                    </div>
                    
                    <div class="form-group">
                        <label>URL / Enlace</label>
                        <input type="text" name="url" id="url_input" class="form-control" placeholder="Ej: index.php?slug=nuestra-historia" required>
                        
                        <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 6px; border: 1px solid var(--border); max-height: 200px; overflow-y: auto;">
                            <span style="font-size: 0.8rem; color: #64748b; font-weight: bold; display: block; margin-bottom: 5px;">Rutas Rápidas (Clic para copiar):</span>
                            <span style="font-size: 0.8rem; cursor: pointer; color: #34859B; display: block; margin-bottom: 5px;" onclick="document.getElementById('url_input').value = 'index.php'">+ Inicio</span>
                            <span style="font-size: 0.8rem; cursor: pointer; color: #34859B; display: block; margin-bottom: 5px;" onclick="document.getElementById('url_input').value = 'index.php?slug=noticias'">+ Noticias (Blog)</span>
                            
                            <?php if(!empty($paginas_creadas)): ?>
                                <hr style="border: 0; border-top: 1px solid var(--border); margin: 5px 0;">
                                <?php foreach($paginas_creadas as $p): ?>
                                    <span style="font-size: 0.8rem; cursor: pointer; color: #46B094; display: block; margin-bottom: 5px;" onclick="document.getElementById('url_input').value = 'index.php?slug=<?php echo $p['slug']; ?>'">+ Página: <?php echo htmlspecialchars($p['title']); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Posición (Orden)</label>
                        <input type="number" name="position" class="form-control" value="0" style="width: 100px;">
                    </div>
                    
                    <button type="submit" class="btn-save" style="width: 100%;">Agregar al Menú</button>
                </form>
            </div>

            <div class="card">
                <h3 style="margin-bottom: 20px; margin-top:0;"><i class="fa-solid fa-list"></i> Estructura Actual</h3>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Orden</th>
                                <th>Etiqueta</th>
                                <th>Ruta (URL)</th>
                                <th style="text-align: right;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($menu_items)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #94a3b8; padding: 30px;">Aún no has agregado enlaces a tu menú.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($menu_items as $item): ?>
                                <tr>
                                    <td style="font-weight: bold; color: #94a3b8;"><?php echo $item['position']; ?></td>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($item['label']); ?></td>
                                    <td><span class="badge-url" title="<?php echo htmlspecialchars($item['url']); ?>"><?php echo htmlspecialchars($item['url']); ?></span></td>
                                    <td style="text-align: right;">
                                        <a href="?delete=<?php echo $item['id']; ?>&amp;token=<?php echo urlencode($csrf_token); ?>" style="color: #ef4444; text-decoration: none; padding: 5px; display: inline-block;" onclick="return confirm('¿Quitar este enlace del menú?')">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.getElementById('adminSidebar');
            const toggleBtn = document.getElementById('menuToggleBtn');
            const overlay = document.getElementById('menuOverlay');
            const menuIcon = document.getElementById('menuIcon');

            function toggleMenu() {
                const isOpen = sidebar.classList.toggle('open');
                overlay.classList.toggle('active', isOpen);
                
                // Cambiar dinámicamente el ícono entre barras y X
                if (isOpen) {
                    menuIcon.className = 'fa-solid fa-xmark';
                } else {
                    menuIcon.className = 'fa-solid fa-bars';
                }
            }

            // Eventos de gatillo
            toggleBtn.addEventListener('click', toggleMenu);
            overlay.addEventListener('click', toggleMenu);
        });
    </script>
</body>
</html>
