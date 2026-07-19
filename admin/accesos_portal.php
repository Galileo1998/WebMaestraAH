<?php
session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$auth->checkAccess(basename($_SERVER['PHP_SELF']), $db);

$csrf_token = Auth::generateCSRF();
$msg = "";

// ==========================================
// 1. CREACIÓN MANUAL (ESTUDIANTE O TÉCNICO)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_user') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    $identidad = trim($_POST['identidad']);
    $nombre = trim($_POST['nombre']);
    $password = trim($_POST['password']);
    $rol = in_array($_POST['rol'], ['student', 'tecnico']) ? $_POST['rol'] : 'student';

    if (!empty($identidad) && !empty($nombre) && !empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            $db->beginTransaction();
            
            // Insertar en la tabla de usuarios del portal
            $stmt = $db->prepare("INSERT INTO ah_users (identidad, nombre, password, rol) VALUES (:id, :nom, :pass, :rol)");
            $stmt->execute(['id' => $identidad, 'nom' => $nombre, 'pass' => $hashed_password, 'rol' => $rol]);
            
            // AUTOMATIZACIÓN: Si es técnico, lo agregamos a la tabla de POA también
            if ($rol === 'tecnico') {
                $stmt_tec = $db->prepare("INSERT IGNORE INTO ah_tecnicos (nombre, identidad) VALUES (?, ?)");
                $stmt_tec->execute([$nombre, $identidad]);
            }
            
            $db->commit();
            $msg = "<div class='alert success'><i class='fa-solid fa-check'></i> Usuario registrado exitosamente.</div>";
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() == 23000) {
                $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> El número de identidad ya está registrado.</div>";
            } else {
                $msg = "<div class='alert error'>Error de base de datos: " . $e->getMessage() . "</div>";
            }
        }
    } else {
        $msg = "<div class='alert error'>Todos los campos son obligatorios.</div>";
    }
}

// ==========================================
// 2. CARGA MASIVA MEDIANTE EXCEL (CSV)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'upload_csv') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');

    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $rol_masivo = in_array($_POST['rol_masivo'], ['student', 'tecnico']) ? $_POST['rol_masivo'] : 'student';

        if ($file_ext === 'csv') {
            $handle = fopen($file_tmp, "r");
            $exitosos = 0;
            $errores = 0;

            // Saltar la primera línea de encabezados
            fgetcsv($handle, 1000, ","); 

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO ah_users (identidad, nombre, password, rol) VALUES (:id, :nom, :pass, :rol)");
                $stmt_tec = $db->prepare("INSERT IGNORE INTO ah_tecnicos (nombre, identidad) VALUES (?, ?)");
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) >= 3) {
                        $identidad = trim($data[0]);
                        $nombre = trim($data[1]);
                        $password_plana = trim($data[2]);
                        
                        if(!empty($identidad) && !empty($nombre) && !empty($password_plana)) {
                            $hashed_pass = password_hash($password_plana, PASSWORD_DEFAULT);
                            try {
                                $stmt->execute(['id' => $identidad, 'nom' => $nombre, 'pass' => $hashed_pass, 'rol' => $rol_masivo]);
                                
                                // Si el lote es de técnicos, sincronizamos con el POA
                                if($rol_masivo === 'tecnico') {
                                    $stmt_tec->execute([$nombre, $identidad]);
                                }
                                
                                $exitosos++;
                            } catch (PDOException $e) {
                                $errores++; // Identidad duplicada
                            }
                        }
                    }
                }
                $db->commit();
                $msg = "<div class='alert success'><i class='fa-solid fa-users'></i> Carga masiva ($rol_masivo) completada: $exitosos registrados, $errores omitidos.</div>";
            } catch (Exception $e) {
                $db->rollBack();
                $msg = "<div class='alert error'>Error fatal en la carga masiva. Se deshicieron los cambios.</div>";
            }
            fclose($handle);
        } else {
            $msg = "<div class='alert error'>Formato inválido. Por favor, sube un archivo .csv</div>";
        }
    } else {
        $msg = "<div class='alert error'>Error al subir el archivo.</div>";
    }
}

// ==========================================
// ELIMINAR USUARIO
// ==========================================
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && isset($_GET['token'])) {
    if (hash_equals($_SESSION['csrf_token'], $_GET['token'])) {
        $stmt_del = $db->prepare("DELETE FROM ah_users WHERE id = :id");
        $stmt_del->execute(['id' => $_GET['delete']]);
        header("Location: accesos_portal.php?msg=deleted"); // Asegúrate de que el nombre del archivo actual sea este
        exit;
    }
}
if(isset($_GET['msg']) && $_GET['msg'] == 'deleted') {
    $msg = "<div class='alert success'><i class='fa-solid fa-trash'></i> Acceso revocado del sistema.</div>";
}

// OBTENER LISTADO DE USUARIOS (Técnicos y Estudiantes)
$stmt = $db->query("SELECT * FROM ah_users WHERE rol IN ('student', 'tecnico') ORDER BY rol ASC, id DESC");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Accesos | AH Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); display: flex; margin: 0; min-height: 100vh; }
        
        .main { flex-grow: 1; padding: 40px; box-sizing: border-box; overflow-y: auto; }
        .card { background: white; border-radius: 12px; padding: 35px; box-shadow: 0 4px 15px rgba(0,0,0,0.01); border: 1px solid var(--border); margin-bottom: 30px;}
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 700; color: #475569; margin-bottom: 8px; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: var(--ah-primary); box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.15); }
        
        .btn-save { background: var(--ah-primary); color: white; border: none; padding: 12px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.95rem; }
        .btn-save:hover { background: #2c7285; transform: translateY(-1px); }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f1f5f9; padding: 15px; text-align: left; color: #475569; font-size: 0.9rem; border-bottom: 2px solid var(--border); }
        td { padding: 15px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }

        .badge-rol { padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;}
        .rol-student { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
        .rol-tecnico { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <main class="main">
        <h1 style="margin-top: 0; margin-bottom: 5px; font-size: 2rem; color: #0f172a;">
            Portal Unificado: <span style="color: var(--ah-primary);">Accesos y Cuentas</span>
        </h1>
        <p style="color: #64748b; margin-bottom: 30px;">Crea credenciales para estudiantes (Academia) y técnicos (Academia + Metas POA).</p>

        <?php echo $msg; ?>

        <div class="grid-2">
            <!-- FORMULARIO MANUAL -->
            <div class="card">
                <h2 style="margin-top: 0; color: #1e293b; font-size: 1.3rem;"><i class="fa-solid fa-user-plus" style="color: var(--ah-accent);"></i> Nuevo Acceso (Manual)</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_user">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label>Rol en el Portal</label>
                        <select name="rol" class="form-control" required>
                            <option value="student">Estudiante (Solo Academia Virtual)</option>
                            <option value="tecnico">Técnico de Campo (Academia + Metas POA)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Número de Identidad (DNI sin guiones)</label>
                        <input type="text" name="identidad" class="form-control" placeholder="Ej: 0801199012345" required>
                    </div>
                    <div class="form-group">
                        <label>Nombre Completo</label>
                        <input type="text" name="nombre" class="form-control" placeholder="Nombre que aparecerá en diplomas y asignaciones" required>
                    </div>
                    <div class="form-group">
                        <label>Contraseña Provisional</label>
                        <input type="text" name="password" class="form-control" placeholder="Ej: Accion2026" required>
                    </div>
                    <button type="submit" class="btn-save"><i class="fa-solid fa-check"></i> Registrar Acceso</button>
                </form>
            </div>

            <!-- CARGA MASIVA EXCEL / CSV -->
            <div class="card" style="background: #f8fafc;">
                <h2 style="margin-top: 0; color: #1e293b; font-size: 1.3rem;"><i class="fa-solid fa-file-excel" style="color: #10b981;"></i> Carga Masiva (Excel / CSV)</h2>
                <p style="color: #64748b; font-size: 0.9rem;">Sube un archivo <b>.csv</b> con 3 columnas exactas: <b>Identidad, Nombre, Contraseña</b>.</p>
                
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_csv">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label>Asignar rol al lote subido:</label>
                        <select name="rol_masivo" class="form-control" style="background: white;" required>
                            <option value="student">Estudiantes</option>
                            <option value="tecnico">Técnicos de Campo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required style="background: white;">
                    </div>
                    <button type="submit" class="btn-save" style="background: #10b981;"><i class="fa-solid fa-upload"></i> Subir e Importar Lote</button>
                </form>
            </div>
        </div>

        <!-- TABLA DE USUARIOS -->
        <div class="card">
            <h2 style="margin-top: 0; color: #1e293b; font-size: 1.3rem;"><i class="fa-solid fa-users-gear"></i> Directorio de Usuarios del Portal</h2>
            
            <?php if(empty($usuarios)): ?>
                <div style="background: #f8fafc; padding: 20px; border-radius: 8px; color: #64748b; text-align: center; margin-top: 20px;">
                    No hay usuarios registrados con acceso al portal.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Identidad</th>
                            <th>Nombre Completo</th>
                            <th>Rol</th>
                            <th>Fecha de Ingreso</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($usuarios as $usr): ?>
                            <tr>
                                <td style="font-weight: 600; color: var(--ah-primary);"><?php echo htmlspecialchars($usr['identidad']); ?></td>
                                <td style="font-weight: 600; color: var(--text);"><?php echo htmlspecialchars($usr['nombre']); ?></td>
                                <td>
                                    <?php if($usr['rol'] == 'tecnico'): ?>
                                        <span class="badge-rol rol-tecnico"><i class="fa-solid fa-helmet-safety"></i> Técnico</span>
                                    <?php else: ?>
                                        <span class="badge-rol rol-student"><i class="fa-solid fa-graduation-cap"></i> Estudiante</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#64748b; font-size:0.9rem;"><?php echo date('d/m/Y', strtotime($usr['fecha_creacion'])); ?></td>
                                <td style="text-align: right;">
                                    <a href="?delete=<?php echo $usr['id']; ?>&token=<?php echo $csrf_token; ?>" onclick="return confirm('¿Revocar permanentemente el acceso a esta persona?')" class="btn-save" style="background: #fee2e2; color: #ef4444; padding: 6px 12px; font-size: 0.8rem;">
                                        <i class="fa-solid fa-trash"></i> Revocar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>