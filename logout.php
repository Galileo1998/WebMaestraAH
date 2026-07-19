<?php
// =================================================================
// ARCHIVO: logout.php (RAÍZ DEL PROYECTO)
// DESTRUCTOR SEGURO DE SESIONES
// =================================================================

session_start();

// 1. Vaciamos todas las variables de sesión actuales
$_SESSION = array();

// 2. Destruimos la cookie de sesión del navegador por seguridad extrema
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destruimos la sesión en el servidor
session_destroy();

// 4. Redirigimos al usuario a la página principal limpia
header("Location: /");
exit;
?>