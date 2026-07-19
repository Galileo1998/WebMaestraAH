<?php
session_start();
require_once 'config/Database.php';
require_once 'classes/Auth.php';

$msg = "";

// Si el estudiante ya inició sesión, enviarlo al aula
if (isset($_SESSION['user_id']) && $_SESSION['rol'] == 'student') {
    if (isset($_SESSION['redirect_to'])) {
        $url = $_SESSION['redirect_to'];
        unset($_SESSION['redirect_to']);
        header("Location: " . $url);
    } else {
        // Redirigir a una página principal de cursos si existe, o dejarlo en espera
        header("Location: index.php"); 
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $identidad = trim($_POST['identidad']);
    $password = trim($_POST['password']);

    if (!empty($identidad) && !empty($password)) {
        $database = new Database();
        $db = $database->getConnection();
        $auth = new Auth($db);

        if ($auth->isIpBlocked()) {
            $msg = "<div class='alert error'>Demasiados intentos fallidos. Intente nuevamente en 15 minutos.</div>";
        } else {

        // Solo buscamos usuarios que tengan el rol de 'student'
        $stmt = $db->prepare("SELECT id, nombre, password, rol FROM ah_users WHERE identidad = :id AND rol = 'student' LIMIT 1");
        $stmt->execute(['id' => $identidad]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nombre'] = $user['nombre'];
            $_SESSION['rol'] = $user['rol'];
            $_SESSION['identidad'] = $identidad;
            $_SESSION['last_activity'] = time();
            $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
            $auth->resetLoginAttempts();

            if (isset($_SESSION['redirect_to'])) {
                $url = $_SESSION['redirect_to'];
                unset($_SESSION['redirect_to']); 
                header("Location: " . $url);
            } else {
                header("Location: index.php"); 
            }
            exit;
        } else {
            $auth->recordFailedLogin($identidad);
            $msg = "<div class='alert error'>Identidad o contraseña incorrectos.</div>";
        }
        }
    } else {
        $msg = "<div class='alert error'>Por favor, completa todos los campos.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Estudiantes | Academia Virtual AH</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; }
        body { margin: 0; font-family: 'Inter', sans-serif; background: #0f172a; display: flex; justify-content: center; align-items: center; min-height: 100vh; color: #334155; }
        .login-container { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); width: 100%; max-width: 400px; box-sizing: border-box; }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo img { max-width: 150px; }
        h2 { text-align: center; margin-top: 0; color: #0f172a; font-weight: 800; font-size: 1.5rem; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 0.9rem; color: #475569; }
        .input-icon-wrapper { position: relative; }
        .input-icon-wrapper i { position: absolute; left: 15px; top: 15px; color: #94a3b8; }
        input { width: 100%; padding: 12px 12px 12px 40px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 1rem; box-sizing: border-box; outline: none; transition: 0.2s; background: #f8fafc; }
        input:focus { border-color: var(--ah-primary); background: white; box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.15); }
        .btn-login { width: 100%; background: var(--ah-primary); color: white; border: none; padding: 14px; border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .btn-login:hover { background: #2c7285; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(52,133,155,0.2); }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; text-align: center; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .footer-text { text-align: center; margin-top: 25px; font-size: 0.8rem; color: #94a3b8; }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="logo">
            <i class="fa-solid fa-graduation-cap" style="font-size: 3rem; color: var(--ah-primary);"></i>
        </div>
        <h2>Portal Académico</h2>
        
        <?php echo $msg; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>Número de Identidad</label>
                <div class="input-icon-wrapper">
                    <i class="fa-solid fa-id-card"></i>
                    <input type="text" name="identidad" placeholder="Ej: 0801199012345" required autocomplete="off">
                </div>
            </div>
            
            <div class="form-group">
                <label>Contraseña</label>
                <div class="input-icon-wrapper">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="btn-login">Ingresar al Aula <i class="fa-solid fa-arrow-right-to-bracket" style="margin-left: 5px;"></i></button>
        </form>
        
        <div class="footer-text">
            © <?php echo date('Y'); ?> Acción Honduras.<br>Todos los derechos reservados.
        </div>
    </div>

</body>
</html>
