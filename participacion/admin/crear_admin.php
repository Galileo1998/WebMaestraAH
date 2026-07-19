<?php
// admin/crear_admin.php
require '../config/db.php';

// Configuración del nuevo admin
$usuario = "Galileo";
$password = "123456"; // <--- Esta será tu contraseña
$identidad = "0809199800030"; // Identidad ficticia para el admin

// Encriptamos la contraseña
$pass_hash = password_hash($password, PASSWORD_DEFAULT);

// 1. Borramos si ya existía para evitar duplicados
$conn->query("DELETE FROM usuarios WHERE usuario = '$usuario'");

// 2. Insertamos el admin nuevo
$sql = "INSERT INTO usuarios (identidad, nombre_completo, usuario, password_hash, rol, activo) 
        VALUES (?, 'Administrador Principal', ?, ?, 'admin', 1)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $identidad, $usuario, $pass_hash);

if ($stmt->execute()) {
    echo "<h1>¡Listo! ✅</h1>";
    echo "<p>Usuario creado correctamente.</p>";
    echo "<ul><li>Usuario: <b>$usuario</b></li><li>Contraseña: <b>$password</b></li></ul>";
    echo "<a href='login.php'>Ir al Login</a>";
} else {
    echo "Error: " . $conn->error;
}
?>