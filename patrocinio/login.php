<?php
// login.php
include 'db_config.php';

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['email']) && isset($input['password'])) {
    $stmt = $pdo->prepare("SELECT id, nombre, password, rol FROM usuarios WHERE email = ?");
    $stmt->execute([$input['email']]);
    $user = $stmt->fetch();

    // Nota: En producción usar password_verify($input['password'], $user['password'])
    if ($user && $input['password'] === $user['password']) {
        echo json_encode([
            "success" => true,
            "user" => [
                "id" => $user['id'],
                "nombre" => $user['nombre'],
                "rol" => $user['rol']
            ]
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Credenciales incorrectas"]);
    }
}
?>