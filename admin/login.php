<?php
session_start();

// Si ya tiene sesión, lo enviamos directo al panel (evita que vea el login de nuevo)
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: index.php");
    exit;
}

require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

$msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Verificamos si el Firewall Anti-Fuerza Bruta lo bloqueó
    if ($auth->isIpBlocked()) {
        $msg = "<div style='background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px; text-align:center; font-weight:600; border: 1px solid #f87171;'>Has fallado demasiadas veces. Por seguridad, tu acceso está bloqueado por 15 minutos.</div>";
    } else {
        $email = trim($_POST['email']);
        $password = $_POST['password'];

        // 2. Intentamos iniciar sesión con la clase Auth que arreglamos
        if ($auth->login($email, $password)) {
            // ¡ÉXITO! Lo mandamos al panel principal
            header("Location: index.php");
            exit;
        } else {
            // FALLO: Credenciales incorrectas
            $msg = "<div style='background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px; text-align:center; font-weight:600; border: 1px solid #f87171;'>Identidad/Correo o contraseña incorrectos.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 350px; border: 1px solid #e2e8f0; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #475569; font-weight: 600; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-family: inherit; font-size: 1rem; box-sizing: border-box; transition: 0.3s; }
        .form-control:focus { outline: none; border-color: #34859B; box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.2); }
        .btn-login { width: 100%; background: #34859B; color: white; padding: 14px; border: none; border-radius: 6px; font-weight: bold; font-size: 1rem; cursor: pointer; transition: 0.2s; }
        .btn-login:hover { background: #2c7285; transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="login-card">
        <div style="text-align: center; margin-bottom: 30px;">
            <h2 style="color: #1e293b; margin: 0; font-size: 1.8rem;">AH Admin Pro</h2>
            <p style="color: #64748b; margin-top: 5px; font-size: 0.9rem;">Acceso seguro al sistema</p>
        </div>
        
        <?php echo $msg; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>Identidad o Correo Electrónico:</label>
                <!-- EL CAMBIO ESTÁ AQUÍ: type="text" -->
                <input type="text" name="email" class="form-control" placeholder="Ej: 0801199012345 o usuario@ah.org" required autocomplete="username">
            </div>
            <div class="form-group">
                <label>Contraseña:</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-login">Ingresar de forma segura</button>
        </form>
    </div>
</body>
</html>