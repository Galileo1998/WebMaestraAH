<?php
// index.php
session_start();

// Verificamos si existe la variable de sesión que creamos en el login
if (isset($_SESSION['user_id'])) {
    // CASO 1: Si YA está logueado -> Lo mandamos al Dashboard
    header("Location: admin/dashboard.php");
    exit();
} else {
    // CASO 2: Si NO está logueado -> Lo mandamos al Login
    header("Location: admin/login.php");
    exit();
}
?>