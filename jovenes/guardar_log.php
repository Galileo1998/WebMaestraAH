<?php
// Opcional: mostrar errores mientras pruebas
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json; charset=utf-8");

// Carpeta donde se guardan los logs locales
$logDir   = __DIR__ . "/logs";
$jsonFile = $logDir . "/participacion_chaside.json";
$csvFile  = $logDir . "/consultas_chaside.csv";

// URL del Flow de Power Automate (HTTP trigger)
$flowUrl = "https://default119eb90fa7a14dcf8a49e17b515a31.a0.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/6e77bdf1888e43dbb0f08fdb7d601a3e/triggers/manual/paths/invoke?api-version=1"; // <-- pega aquí la URL del trigger HTTP

// Si llaman con ?descargar=1 => devolver el JSON acumulado
if (isset($_GET['descargar'])) {
    if (!file_exists($jsonFile)) {
        echo json_encode([]);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="participacion_chaside.json"');
    readfile($jsonFile);
    exit;
}

// Leer datos enviados por fetch (POST JSON)
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

// Validar JSON recibido
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "ok"   => false,
        "error"=> "JSON inválido o vacío",
        "raw"  => $raw
    ]);
    exit;
}

// IP vista por el servidor
$ip_servidor = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$data["ip_servidor"] = $ip_servidor;

// Asegurar carpeta logs
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

/* ========== 1) GUARDAR EN JSON LOCAL ACUMULADO (opcional) ========== */
if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, "[]");
}
$contenido = file_get_contents($jsonFile);
$lista = json_decode($contenido, true);
if (!is_array($lista)) {
    $lista = [];
}
$lista[] = $data;
file_put_contents(
    $jsonFile,
    json_encode($lista, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

/* ========== 2) ACTUALIZAR CSV LOCAL (hoja compatible Excel, opcional) ========== */
$csvHeaders = [
    "fecha_hora",
    "valor_buscado",
    "nombre",
    "dni",
    "nnaj",
    "comunidad_base",
    "sexo",
    "edad",
    "area_principal",
    "resumen_resultado1",
    "ip_cliente",
    "ip_servidor",
    "url_acceso"
];

if (!file_exists($csvFile)) {
    $fh = fopen($csvFile, "w");
    fputcsv($fh, $csvHeaders, ";");
    fclose($fh);
}

$fh = fopen($csvFile, "a");
$fila = [];
foreach ($csvHeaders as $campo) {
    $fila[] = isset($data[$campo]) ? $data[$campo] : "";
}
fputcsv($fh, $fila, ";");
fclose($fh);

/* ========== 3) ENVIAR A POWER AUTOMATE PARA ESCRIBIR EN EXCEL O365 ========== */
if (!empty($flowUrl)) {
    $ch = curl_init($flowUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    $http_code = 0;
    $response  = null;
}

// Responder al navegador (JS)
echo json_encode([
    "ok"      => true,
    "conteo"  => count($lista),
    "flow_ok" => ($http_code >= 200 && $http_code < 300),
    "flow_code" => $http_code
]);
