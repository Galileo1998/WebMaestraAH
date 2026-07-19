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

// ==========================================
// 1. PROCESAR CREACIÓN DE SOCIO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_partner') {
    
    // 🛡️ BARRERA CSRF
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $status = $_POST['status'];
    $logo_url = '';

    // Manejo seguro de la subida del logo
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] == UPLOAD_ERR_OK) {
        // Reutilizamos la función de seguridad que creamos antes
        $upload = Auth::secureImageUpload($_FILES['logo'], '../uploads/images/');
        if ($upload['success']) {
            $logo_url = '/uploads/images/' . $upload['filename'];
        } else {
            $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> " . $upload['error'] . "</div>";
        }
    }

    if (empty($msg) && !empty($name)) {
        try {
            $stmt = $db->prepare("INSERT INTO partners (name, logo_url, description, status) VALUES (:n, :l, :d, :s)");
            $stmt->execute([
                'n' => $name,
                'l' => $logo_url,
                'd' => $description,
                's' => $status
            ]);
            $msg = "<div class='alert success'><i class='fa-solid fa-handshake'></i> Socio registrado exitosamente.</div>";
        } catch (PDOException $e) {
            error_log("Error al crear socio: " . $e->getMessage());
            $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error interno al guardar.</div>";
        }
    }
}

// ==========================================
// 2. PROCESAR ELIMINACIÓN DE SOCIO
// ==========================================
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    Auth::checkCSRF($_GET['token'] ?? '');
    
    try {
        $stmt = $db->prepare("DELETE FROM partners WHERE id = :id");
        $stmt->execute(['id' => $_GET['delete']]);
        $msg = "<div class='alert success'><i class='fa-solid fa-trash'></i> Socio eliminado (y sus proyectos vinculados).</div>";
    } catch (PDOException $e) {
        $msg = "<div class='alert error'>Error al eliminar.</div>";
    }
}

// ==========================================
// OBTENER LISTA DE SOCIOS
// ==========================================
$stmt = $db->query("SELECT * FROM partners ORDER BY created_at DESC");
$socios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Socios y Cooperantes | Acción Honduras Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        
        /* Sidebar (Reutilizado) */
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; }
        .sidebar-header { padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-content { padding: 24px; flex-grow: 1; }
        .nav-link { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; gap: 10px; padding: 10px 0; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { color: white; }
        .nav-link.active i { color: #46B094; }
        
        .main { flex-grow: 1; padding: 40px; overflow-y: auto; }
        .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; }
        .error { background: #fee2e2; color: #991b1b; }

        .card { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 30px; border: 1px solid var(--border); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; }
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 12px 25px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-save:hover { background: #2c7285; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .data-table th { color: #64748b; text-transform: uppercase; font-size: 0.8rem; }
        .partner-logo { width: 50px; height: 50px; object-fit: contain; background: #f1f5f9; border-radius: 6px; padding: 5px; border: 1px solid #e2e8f0; }
        .badge { padding: 5px 10px; border-radius: 50px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #f1f5f9; color: #64748b; }
    </style>
</head>
<body>

    <aside class="sidebar">

        <?php include 'sidebar.php'; ?>

    </aside>

    <main class="main">
        <div class="page-header">
            <div>
                <h1 style="margin-top: 0; margin-bottom: 5px;">Socios y Cooperantes</h1>
                <p style="color: #64748b; margin: 0;">Administra las organizaciones aliadas de Acción Honduras.</p>
            </div>
            <button onclick="document.getElementById('form-add').style.display='block'" class="btn-save" style="background: #46B094;"><i class="fa-solid fa-plus"></i> Nuevo Socio</button>
        </div>

        <?php echo $msg; ?>

        <div id="form-add" class="card" style="display: none; background: #f8fafc;">
            <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--ah-primary);"><i class="fa-solid fa-building-ngo"></i> Registrar Organización</h3>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_partner">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Nombre del Socio / Donante</label>
                        <input type="text" name="name" class="form-control" placeholder="Ej: ChildFund International" required>
                    </div>
                    <div class="form-group">
                        <label>Logo de la Organización</label>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Breve Descripción o Rol</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Agencia de cooperación..."></textarea>
                </div>

                <div class="form-group" style="max-width: 200px;">
                    <label>Estado</label>
                    <select name="status" class="form-control">
                        <option value="active">Socio Activo</option>
                        <option value="inactive">Socio Inactivo</option>
                    </select>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Guardar Socio</button>
                    <button type="button" onclick="document.getElementById('form-add').style.display='none'" class="btn-save" style="background: #cbd5e1; color: #334155;">Cancelar</button>
                </div>
            </form>
        </div>

        <div class="card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70px;">Logo</th>
                        <th>Organización</th>
                        <th>Estado</th>
                        <th>Proyectos</th>
                        <th style="text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($socios)): ?>
                        <tr><td colspan="5" style="text-align: center; color: #64748b; padding: 30px;">No hay socios registrados aún.</td></tr>
                    <?php else: ?>
                        <?php foreach($socios as $s): ?>
                        <tr>
                            <td>
                                <?php if($s['logo_url']): ?>
                                    <img src="<?php echo htmlspecialchars($s['logo_url']); ?>" class="partner-logo">
                                <?php else: ?>
                                    <div class="partner-logo" style="display:flex; align-items:center; justify-content:center; color:#94a3b8;"><i class="fa-solid fa-image"></i></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="font-size: 1.05rem;"><?php echo htmlspecialchars($s['name']); ?></strong>
                                <div style="color: #64748b; font-size: 0.85rem; margin-top: 4px;"><?php echo htmlspecialchars($s['description']); ?></div>
                            </td>
                            <td>
                                <span class="badge <?php echo $s['status'] == 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $s['status'] == 'active' ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="proyectos.php?socio_id=<?php echo $s['id']; ?>" style="color: var(--ah-primary); text-decoration: none; font-weight: bold; font-size: 0.9rem;">
                                    <i class="fa-solid fa-folder-open"></i> Gestionar Proyectos
                                </a>
                            </td>
                            <td style="text-align: right;">
                                <a href="?delete=<?php echo $s['id']; ?>&token=<?php echo $csrf_token; ?>" onclick="return confirm('¿Estás seguro de eliminar este socio? Se borrarán todos sus proyectos vinculados.')" style="color: #ef4444; text-decoration: none; padding: 8px; border-radius: 6px; transition: 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='transparent'">
                                    <i class="fa-solid fa-trash"></i> Eliminar
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
