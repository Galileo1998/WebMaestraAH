<?php
session_start();
require_once 'db_config.php'; // Usa require_once para mayor seguridad

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Verificamos si la variable $pdo existe
    if (!isset($pdo)) {
        die("Error: La conexión a la base de datos no está definida. Revisa db_config.php");
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['password'] === $password) {
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['nombre'] = $user['nombre'];
            $_SESSION['rol'] = $user['rol'];

            if ($user['rol'] === 'ADMIN') {
                header("Location: admin/admin_panel.php");
                exit;
            } else {
                echo "Acceso denegado: Tu rol es " . $user['rol'];
            }
        } else {
            header("Location: index.php?error=1");
            exit;
        }
    } catch (PDOException $e) {
        die("Error en la consulta: " . $e->getMessage());
    }
}