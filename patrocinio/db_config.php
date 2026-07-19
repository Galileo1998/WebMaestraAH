<?php
$secretsFile = dirname(__DIR__, 2) . '/.accionhonduras_secrets.php';
$secrets = is_file($secretsFile) ? require $secretsFile : [];

$host = (string)(getenv('AH_DB_HOST') ?: ($secrets['AH_DB_HOST'] ?? 'localhost'));
$dbname = 'acciotif_magic_letters_db';
$username = (string)(getenv('AH_DB_USER') ?: ($secrets['AH_DB_USER'] ?? ''));
$password = (string)(getenv('AH_DB_PASS') ?: ($secrets['AH_DB_PASS'] ?? ''));

if ($username === '' || $password === '') {
    throw new RuntimeException('La configuraciÃ³n privada de base de datos no estÃ¡ disponible.');
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Error de conexiÃ³n de patrocinio: ' . $e->getMessage());
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['error' => 'No fue posible conectar con el servicio.']);
    exit;
}
