<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

// AUTO-MIGRACIÓN: Crea la columna de permisos si no existe
try { $db->exec("ALTER TABLE users ADD COLUMN permissions TEXT NULL"); } catch(Exception $e) {}

$csrf_token = Auth::generateCSRF();
$msg = "";

// Catálogo compartido por la navegación y la matriz de permisos.
$module_groups = require __DIR__ . '/module_catalog.php';
$modulos_sistema = [];
foreach ($module_groups as $group) {
    foreach ($group['items'] as $permission => $item) {
        $modulos_sistema[$permission] = $item['label'];
    }
}
$valid_permissions = array_keys($modulos_sistema);


// ==========================================
// 1. PROCESAR GUARDADO DE CONFIGURACIONES
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_settings') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');
    unset($_POST['action']);
    unset($_POST['csrf_token']); 
    
    foreach ($_POST as $key => $value) {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
        $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
    }
    $msg = "<div class='alert success'><i class='fa-solid fa-shield-check'></i> Configuraciones actualizadas de forma segura.</div>";
}

// ==========================================
// 2. PROCESAR CREACIÓN DE USUARIOS CON PERMISOS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_user') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $selected_permissions = array_values(array_intersect((array)($_POST['permissions'] ?? []), $valid_permissions));
    $permissions = json_encode($selected_permissions);

    $check = $db->prepare("SELECT id FROM users WHERE email = :email");
    $check->execute(['email' => $email]);
    if ($check->rowCount() > 0) {
        $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> El correo ya está registrado.</div>";
    } else {
        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, permissions) VALUES (:n, :e, :p, :r, :perm)");
        $stmt->execute(['n' => $name, 'e' => $email, 'p' => $password, 'r' => $role, 'perm' => $permissions]);
        $msg = "<div class='alert success'><i class='fa-solid fa-user-plus'></i> Usuario creado exitosamente.</div>";
    }
}

// ==========================================
// 3. PROCESAR EDICIÓN DE USUARIOS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'edit_user') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    $user_id = $_POST['user_id'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $selected_permissions = array_values(array_intersect((array)($_POST['permissions'] ?? []), $valid_permissions));
    $permissions = json_encode($selected_permissions);

    // Comprobar si el correo existe (y no es él mismo)
    $check = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
    $check->execute(['email' => $email, 'id' => $user_id]);
    
    if ($check->rowCount() > 0) {
        $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> El correo ingresado ya pertenece a otro usuario.</div>";
    } else {
        $sql = "UPDATE users SET name = :n, email = :e, role = :r, permissions = :perm WHERE id = :id";
        $params = ['n' => $name, 'e' => $email, 'r' => $role, 'perm' => $permissions, 'id' => $user_id];
        
        if (!empty($_POST['password'])) {
            $sql = "UPDATE users SET name = :n, email = :e, password = :p, role = :r, permissions = :perm WHERE id = :id";
            $params['p'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $msg = "<div class='alert success'><i class='fa-solid fa-user-pen'></i> Perfil y permisos del usuario actualizados correctamente.</div>";
    }
}

// ==========================================
// 4. PROCESAR ELIMINACIÓN DE USUARIOS
// ==========================================
if (isset($_GET['delete_user']) && is_numeric($_GET['delete_user'])) {
    Auth::checkCSRF($_GET['token'] ?? '');
    if ($_GET['delete_user'] == $_SESSION['user_id']) {
        $msg = "<div class='alert error'><i class='fa-solid fa-ban'></i> No puedes eliminar tu propia cuenta.</div>";
    } else {
        $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $_GET['delete_user']]);
        $msg = "<div class='alert success'><i class='fa-solid fa-trash'></i> Usuario eliminado.</div>";
    }
}

// ==========================================
// OBTENER DATOS PARA MOSTRAR
// ==========================================
$stmt = $db->query("SELECT * FROM settings");
$settings_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settings_raw as $s) { $settings[$s['setting_key']] = $s['setting_value']; }

$stmt = $db->query("SELECT id, name, email, role, permissions FROM users ORDER BY id ASC");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$usuarios_json = json_encode($usuarios);

try {
    $stmt_pages = $db->query("SELECT id, title FROM pages ORDER BY title ASC");
    $paginas_creadas = $stmt_pages ? $stmt_pages->fetchAll(PDO::FETCH_ASSOC) : [];
} catch(Exception $e) { $paginas_creadas = []; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración | Acción Honduras Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; }
        .main { flex-grow: 1; padding: 40px; overflow-y: auto; }
        .page-header { margin-bottom: 30px; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;}
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;}

        .tabs-header { display: flex; border-bottom: 2px solid var(--border); margin-bottom: 30px; }
        .tab-btn { background: none; border: none; padding: 15px 30px; font-size: 1rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: 0.2s; }
        .tab-btn:hover, .tab-btn.active { color: var(--ah-primary); }
        .tab-btn.active { border-bottom-color: var(--ah-primary); }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        .card { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); margin-bottom: 30px; border: 1px solid var(--border);}
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box;}
        .form-control:focus { outline: none; border-color: var(--ah-primary); }
        
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 12px 25px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-save:hover { background: #2c7285; }
        .btn-edit { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; padding: 6px 12px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-size: 0.85rem;}
        .btn-edit:hover { background: #bae6fd; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 15px; text-align: left; border-bottom: 1px solid var(--border); }
        .data-table th { color: #64748b; text-transform: uppercase; font-size: 0.8rem; }
        
        .role-badge { padding: 5px 12px; border-radius: 50px; font-size: 0.8rem; font-weight: bold; display: inline-block;}
        .role-admin { background: #fee2e2; color: #991b1b; }
        .role-editor { background: #e0f2fe; color: #0369a1; }
        
        .permissions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; background: white; padding: 15px; border-radius: 8px; border: 1px solid var(--border);}
        .permission-groups { display: grid; gap: 16px; margin-top: 10px; }
        .permission-group { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
        .permission-group-title { color: #0f172a; font-weight: 800; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .permission-group .permissions-grid { border: 0; padding: 0; }
        .perm-label { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 500 !important; cursor: pointer; color: #334155 !important; margin: 0 !important;}
        .perm-badge { background: #f1f5f9; color: #475569; font-size: 0.75rem; padding: 4px 8px; border-radius: 4px; display: inline-block; margin: 2px;}

        /* MODAL DE EDICIÓN DE USUARIO */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; width: 90%; max-width: 700px; border-radius: 12px; padding: 30px; position: relative; animation: slideUp 0.3s ease; max-height: 90vh; overflow-y: auto;}
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border); }
        .modal-title { font-size: 1.2rem; font-weight: bold; color: var(--text-main); margin: 0; }
        .btn-close-modal { background: #f1f5f9; border: none; color: #64748b; width: 35px; height: 35px; border-radius: 50%; cursor: pointer; font-size: 1.1rem; transition: 0.2s; display: flex; align-items: center; justify-content: center; }
        .btn-close-modal:hover { background: #fee2e2; color: #ef4444; }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>
    <aside class="sidebar">
        <?php include 'sidebar.php'; ?>
    </aside>

    <main class="main">
        <div class="page-header">
            <h1 style="margin-bottom: 5px;">Configuración del Sistema</h1>
            <p style="color: #64748b; margin: 0;">Administra enlaces globales, estructura principal y usuarios.</p>
        </div>
        <?php echo $msg; ?>

        <div class="tabs-header">
            <button class="tab-btn active" onclick="openTab('tab-general')"><i class="fa-solid fa-globe"></i> General</button>
            <button class="tab-btn" onclick="openTab('tab-users')"><i class="fa-solid fa-users-gear"></i> Usuarios y Permisos</button>
        </div>

        <div id="tab-general" class="tab-content active">
            <div class="card">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <h3 style="margin-top:0; margin-bottom: 20px; color: var(--ah-primary);"><i class="fa-solid fa-house"></i> Estructura del Sitio</h3>
                    <div class="form-group">
                        <label>Página Principal (Home)</label>
                        <select name="home_page_id" class="form-control">
                            <option value="">-- Seleccionar Página --</option>
                            <?php foreach($paginas_creadas as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo (isset($settings['home_page_id']) && $settings['home_page_id'] == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <h3 style="margin-top: 40px; margin-bottom: 20px; color: var(--ah-primary);"><i class="fa-solid fa-building"></i> Información de la Organización</h3>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        <div class="form-group" style="margin:0;"><label>Nombre del Sitio</label><input type="text" name="site_name" class="form-control" value="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>"></div>
                        <div class="form-group" style="margin:0;"><label>Correo Electrónico</label><input type="email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($settings['contact_email'] ?? ''); ?>"></div>
                    </div>

                    <h3 style="margin-top: 40px; margin-bottom: 20px; color: var(--ah-primary);"><i class="fa-solid fa-share-nodes"></i> Redes Sociales</h3>
                    <div class="form-group"><label><i class="fa-brands fa-facebook" style="color:#1877F2;"></i> Enlace de Facebook</label><input type="url" name="social_facebook" class="form-control" value="<?php echo htmlspecialchars($settings['social_facebook'] ?? ''); ?>"></div>
                    <div class="form-group"><label><i class="fa-brands fa-instagram" style="color:#E4405F;"></i> Enlace de Instagram</label><input type="url" name="social_instagram" class="form-control" value="<?php echo htmlspecialchars($settings['social_instagram'] ?? ''); ?>"></div>
                    <div class="form-group"><label><i class="fa-brands fa-x-twitter"></i> Enlace de Twitter / X</label><input type="url" name="social_twitter" class="form-control" value="<?php echo htmlspecialchars($settings['social_twitter'] ?? ''); ?>"></div>

                    <div style="margin-top:30px;">
                        <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> Guardar Cambios Generales</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="tab-users" class="tab-content">
            <div class="card" style="background: #f8fafc;">
                <h3 style="margin-top:0; margin-bottom: 20px; color:#0f172a;"><i class="fa-solid fa-user-shield"></i> Registrar Nuevo Administrador/Compañero</h3>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_user">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 15px; align-items: end; margin-bottom: 20px;">
                        <div class="form-group" style="margin:0;"><label>Nombre Completo</label><input type="text" name="name" class="form-control" required></div>
                        <div class="form-group" style="margin:0;"><label>Correo</label><input type="email" name="email" class="form-control" required></div>
                        <div class="form-group" style="margin:0;"><label>Contraseña Temporal</label><input type="password" name="password" class="form-control" required></div>
                        <div class="form-group" style="margin:0;">
                            <label>Rol Base</label>
                            <select name="role" id="roleSelectNew" class="form-control" onchange="togglePermissions('New')">
                                <option value="editor">Compañero (Acceso a módulos específicos)</option>
                                <option value="admin">Super Administrador (Total)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="permissionsBoxNew">
                        <label><i class="fa-solid fa-list-check"></i> Asignar Permisos a Módulos (Detectados automáticamente)</label>
                        <div class="permission-groups">
                            <?php foreach($module_groups as $group_name => $group): ?>
                                <section class="permission-group">
                                    <div class="permission-group-title">
                                        <i class="fa-solid <?php echo htmlspecialchars($group['icon']); ?>"></i>
                                        <?php echo htmlspecialchars($group_name); ?>
                                    </div>
                                    <div class="permissions-grid">
                                        <?php foreach($group['items'] as $file => $item): ?>
                                            <label class="perm-label">
                                                <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($file); ?>" checked>
                                                <?php echo htmlspecialchars($item['label']); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn-save" style="background: #16a34a;"><i class="fa-solid fa-plus"></i> Crear Usuario</button>
                </form>
            </div>

            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Correo / Rol</th>
                            <th>Módulos Permitidos</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($usuarios as $u): 
                            $perms = json_decode($u['permissions'], true) ?? [];
                        ?>
                        <tr>
                            <td style="font-weight: 600; color:#0f172a;"><?php echo htmlspecialchars($u['name']); ?></td>
                            <td>
                                <div style="margin-bottom:5px; color:#475569;"><i class="fa-regular fa-envelope"></i> <?php echo htmlspecialchars($u['email']); ?></div>
                                <?php if($u['role'] == 'admin'): ?>
                                    <span class="role-badge role-admin">Super Administrador</span>
                                <?php else: ?>
                                    <span class="role-badge role-editor">Compañero Restringido</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; flex-wrap:wrap; gap:5px;">
                                <?php 
                                if($u['role'] == 'admin') {
                                    echo "<span class='perm-badge' style='background:#dcfce7; color:#166534; border:1px solid #bbf7d0;'><i class='fa-solid fa-star'></i> Acceso Total al Sistema</span>";
                                } else {
                                    if (empty($perms)) echo "<span class='perm-badge' style='background:#fee2e2; color:#991b1b; border:1px solid #fecaca;'><i class='fa-solid fa-ban'></i> Cuenta bloqueada sin accesos</span>";
                                    foreach($perms as $p) {
                                        echo "<span class='perm-badge'>" . htmlspecialchars($modulos_sistema[$p] ?? $p) . "</span>";
                                    }
                                }
                                ?>
                                </div>
                            </td>
                            <td style="text-align: right; white-space:nowrap;">
                                <button type="button" class="btn-edit" onclick="openEditModal(<?php echo $u['id']; ?>)">
                                    <i class="fa-solid fa-pen"></i> Editar
                                </button>
                                
                                <?php if($u['id'] != $_SESSION['user_id']): ?>
                                    <a href="?delete_user=<?php echo $u['id']; ?>&token=<?php echo $csrf_token; ?>" onclick="return confirm('¿Estás seguro de eliminar este usuario permanentemente?')" style="color: #ef4444; text-decoration: none; margin-left:15px; font-size:0.9rem;"><i class="fa-solid fa-trash"></i></a>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 0.8rem; margin-left:15px;">(Tú)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="editUserModal" class="modal-overlay" onclick="closeEditModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa-solid fa-user-pen"></i> Editar Usuario y Permisos</h3>
                <button class="btn-close-modal" onclick="closeEditModal(true)"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="user_id" id="edit_user_id">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group" style="margin:0;">
                        <label>Nombre Completo</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Correo Electrónico</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group" style="margin:0;">
                        <label>Rol Base</label>
                        <select name="role" id="roleSelectEdit" class="form-control" onchange="togglePermissions('Edit')">
                            <option value="editor">Compañero (Acceso a módulos específicos)</option>
                            <option value="admin">Super Administrador (Total)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Nueva Contraseña <small style="color:#94a3b8; font-weight:normal;">(Opcional)</small></label>
                        <input type="password" name="password" class="form-control" placeholder="Dejar en blanco para mantener actual">
                    </div>
                </div>

                <div class="form-group" id="permissionsBoxEdit" style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid var(--border);">
                    <label style="color:#0f172a;"><i class="fa-solid fa-check-double"></i> Módulos Autorizados (Detectados automáticamente)</label>
                    <div class="permission-groups">
                        <?php foreach($module_groups as $group_name => $group): ?>
                            <section class="permission-group">
                                <div class="permission-group-title">
                                    <i class="fa-solid <?php echo htmlspecialchars($group['icon']); ?>"></i>
                                    <?php echo htmlspecialchars($group_name); ?>
                                </div>
                                <div class="permissions-grid">
                                    <?php foreach($group['items'] as $file => $item): ?>
                                        <label class="perm-label">
                                            <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($file); ?>" id="edit_perm_<?php echo str_replace(['.', '/'], '_', $file); ?>">
                                            <?php echo htmlspecialchars($item['label']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="button" class="btn-save" style="background: white; color: #475569; border: 1px solid #cbd5e1;" onclick="closeEditModal(true)">Cancelar</button>
                    <button type="submit" class="btn-save" style="margin-left: 10px;"><i class="fa-solid fa-check"></i> Actualizar Usuario</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const usuariosData = <?php echo $usuarios_json; ?>;

        function openTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        function togglePermissions(context) {
            let role = document.getElementById('roleSelect' + context).value;
            let box = document.getElementById('permissionsBox' + context);
            if(role === 'admin') {
                box.style.opacity = '0.5';
                box.style.pointerEvents = 'none';
            } else {
                box.style.opacity = '1';
                box.style.pointerEvents = 'auto';
            }
        }

        function openEditModal(userId) {
            let user = usuariosData.find(u => u.id == userId);
            if(!user) return;

            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_name').value = user.name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('roleSelectEdit').value = user.role;
            
            document.querySelectorAll('input[id^="edit_perm_"]').forEach(cb => cb.checked = false);
            
            let userPerms = [];
            try { userPerms = JSON.parse(user.permissions) || []; } catch(e) {}
            
            userPerms.forEach(perm => {
                let idEscaped = 'edit_perm_' + perm.replaceAll('.', '_').replaceAll('/', '_');
                let checkbox = document.getElementById(idEscaped);
                if(checkbox) checkbox.checked = true;
            });

            togglePermissions('Edit');
            document.getElementById('editUserModal').style.display = 'flex';
        }

        function closeEditModal(force = false) {
            if(force || event.target.id === 'editUserModal') {
                document.getElementById('editUserModal').style.display = 'none';
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") { closeEditModal(true); }
        });
    </script>
</body>
</html>
