<?php
// =================================================================
// ARCHIVO: admin/logout.php
// DESTRUCTOR DE SESIÓN EXCLUSIVO PARA EL PANEL DE ADMINISTRACIÓN
// =================================================================

session_start();

// 1. Vaciamos las variables de sesión del administrador
$_SESSION = array();

// 2. Destruimos la cookie de sesión por seguridad
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Aniquilamos la sesión del servidor
session_destroy();

// 4. Redirigimos al Login del Administrador (no al portal público)
header("Location: /");
exit;
?>