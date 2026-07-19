<?php
// acceso_rapido.php
session_start();
require_once dirname(__DIR__) . '/config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$msg_acceso = "";
$csrf_token = Auth::generateCSRF();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'login_rapido') {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');
    $dni_ingresado = trim($_POST['dni_acceso']);
    $dni_ingresado = preg_replace('/[^0-9]/', '', $dni_ingresado); // Limpiar guiones o espacios
    $password = (string)($_POST['password_acceso'] ?? '');

    if (!empty($dni_ingresado)) {
        // Buscamos si el DNI existe en la tabla de usuarios del portal
        $stmt = $db->prepare("SELECT id, nombre, rol, email, password FROM ah_users WHERE REPLACE(identidad, '-', '') = ? LIMIT 1");
        $stmt->execute([$dni_ingresado]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // ¡El usuario existe! Iniciamos una sesión mágica
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nombre'];
            $_SESSION['user_role'] = $user['rol'];
            $_SESSION['nombre'] = $user['nombre'];
            $_SESSION['rol'] = $user['rol'];
            $_SESSION['logged_in'] = true;
            $_SESSION['auth_source'] = 'portal';
            
            // Recargar la página para que se vea el panel logueado
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $msg_acceso = "<div style='color: #dc2626; font-size: 0.8rem; margin-top: 5px; text-align: center; font-weight: bold;'><i class='fa-solid fa-circle-xmark'></i> Identidad no registrada.</div>";
        }
    }
}

// Lógica de Cerrar Sesión Rápida
if (isset($_GET['action']) && $_GET['action'] == 'logout_rapido') {
    session_unset();
    session_destroy();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?')); // Redirigir a la misma página sin el parámetro GET
    exit;
}
?>

<div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); max-width: 300px;">
    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
        <div style="text-align: center;">
            <div style="width: 50px; height: 50px; background: #e0f2fe; color: #0284c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: bold; margin: 0 auto 10px auto;">
                <?php echo strtoupper(substr($_SESSION['user_name'], 0, 2)); ?>
            </div>
            <h4 style="margin: 0 0 5px 0; color: #0f172a; font-family: 'Inter', sans-serif; font-size: 1rem;">
                Hola, <?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]); ?>
            </h4>
            <p style="margin: 0 0 15px 0; color: #64748b; font-size: 0.8rem; font-family: 'Inter', sans-serif;">
                <?php echo $_SESSION['user_role'] == 'tecnico' ? 'Perfil Técnico' : 'Estudiante'; ?>
            </p>
            
            <a href="admin/panel_tecnico.php" style="display: block; background: #34859B; color: white; text-decoration: none; padding: 10px; border-radius: 6px; font-weight: bold; font-family: 'Inter', sans-serif; font-size: 0.9rem; margin-bottom: 10px; transition: 0.2s;">
                <i class="fa-solid fa-table-columns"></i> Ir a mi Panel
            </a>
            
            <a href="?action=logout_rapido" style="display: block; color: #ef4444; text-decoration: none; font-size: 0.8rem; font-family: 'Inter', sans-serif; font-weight: bold;">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Cerrar sesión
            </a>
        </div>

    <?php else: ?>
        <div style="text-align: center; margin-bottom: 15px;">
            <h4 style="margin: 0 0 5px 0; color: #0f172a; font-family: 'Inter', sans-serif; font-size: 1.1rem;">Tu Espacio Personal</h4>
            <p style="margin: 0; color: #64748b; font-size: 0.8rem; font-family: 'Inter', sans-serif;">Ingresa tu identidad para ver tus metas o cursos.</p>
        </div>
        
        <form method="POST" action="" style="display: flex; flex-direction: column; gap: 10px;">
                <input type="hidden" name="action" value="login_rapido">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="text" name="dni_acceso" placeholder="Ej: 0801199012345" required style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 0.9rem; box-sizing: border-box; text-align: center;">
                <input type="password" name="password_acceso" placeholder="Contraseña" required autocomplete="current-password" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 0.9rem; box-sizing: border-box; text-align: center;">
            <button type="submit" style="background: #10b981; color: white; border: none; padding: 10px; border-radius: 6px; font-weight: bold; font-family: 'Inter', sans-serif; cursor: pointer; transition: 0.2s;">
                Ingresar <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
        <?php echo $msg_acceso; ?>
    <?php endif; ?>
</div>
