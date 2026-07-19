<?php
$secretsFile = dirname(__DIR__, 3) . '/.accionhonduras_secrets.php';
$secrets = is_file($secretsFile) ? require $secretsFile : [];

$host = (string)(getenv('AH_DB_HOST') ?: ($secrets['AH_DB_HOST'] ?? 'localhost'));
$usuario = (string)(getenv('AH_DB_USER') ?: ($secrets['AH_DB_USER'] ?? ''));
$password = (string)(getenv('AH_DB_PASS') ?: ($secrets['AH_DB_PASS'] ?? ''));
$base_datos = 'acciotif_participacion_af26';

if ($usuario === '' || $password === '') {
    throw new RuntimeException('La configuraciÃ³n privada de base de datos no estÃ¡ disponible.');
}

$mysqli = new mysqli($host, $usuario, $password, $base_datos);
if ($mysqli->connect_error) {
    error_log('Error de conexiÃ³n de participaciÃ³n: ' . $mysqli->connect_error);
    die('No fue posible conectar con el servicio.');
}
$mysqli->set_charset('utf8mb4');
