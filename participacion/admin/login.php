<?php
// admin/login.php
session_start();
require '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usuario = $_POST['usuario'];
    $password = $_POST['password'];

    // CORRECCIÓN: Usamos $mysqli en lugar de $conn
    // (Si tu db.php usa otro nombre, cambia $mysqli por ese nombre aquí)
    if (isset($mysqli)) {
        $stmt = $mysqli->prepare("SELECT id, nombre_completo, password_hash, rol FROM usuarios WHERE usuario = ? AND rol IN ('admin', 'tecnico') AND activo = 1");
    } elseif (isset($conn)) {
        // Por si acaso en db.php sí se llama $conn pero no se estaba leyendo bien
        $stmt = $conn->prepare("SELECT id, nombre_completo, password_hash, rol FROM usuarios WHERE usuario = ? AND rol IN ('admin', 'tecnico') AND activo = 1");
    } else {
        die("Error: No se encuentra la variable de conexión en db.php");
    }

    if ($stmt) {
        $stmt->bind_param("s", $usuario);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['nombre'] = $row['nombre_completo'];
                $_SESSION['rol'] = $row['rol'];
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Contraseña incorrecta";
            }
        } else {
            $error = "Usuario no autorizado";
        }
    } else {
        // Esto ayuda a ver si falló la consulta SQL
        $error = "Error SQL: " . ($mysqli->error ?? "Error desconocido");
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Acceso Admin AF-26</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="card p-4 shadow" style="width: 350px;">
        <h4 class="text-center mb-4">Administración</h4>
        <?php if(isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>
        <form method="post">
            <div class="mb-3"><input type="text" name="usuario" class="form-control" placeholder="Usuario" required></div>
            <div class="mb-3"><input type="password" name="password" class="form-control" placeholder="Contraseña" required></div>
            <button type="submit" class="btn btn-primary w-100">Ingresar</button>
        </form>
    </div>
</body>
</html>