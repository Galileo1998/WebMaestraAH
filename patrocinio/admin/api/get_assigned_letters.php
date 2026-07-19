<?php
// UBICACIÓN: carpeta "api/" (en la raíz, NO en admin/api/)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Activar logs para depuración
ini_set('display_errors', 0);
ini_set('log_errors', 1);
function writeLog($msg) {
    file_put_contents('debug_log.txt', "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND);
}

writeLog("--- Nueva solicitud de Sync (Pull) ---");

// 1. CONEXIÓN BASE DE DATOS
$dbPath = '../db_config.php'; // Desde api/ hacia raíz
if (!file_exists($dbPath)) {
    writeLog("ERROR: No se encuentra db_config.php en $dbPath");
    echo json_encode(['error' => 'Configuración DB no encontrada']);
    exit;
}
require $dbPath;

// 2. RECIBIR DATOS (JSON o POST)
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Buscamos 'phone' o 'tech_id'
$identifier = $input['phone'] ?? $input['tech_id'] ?? $_POST['phone'] ?? $_POST['tech_id'] ?? null;

writeLog("Dato recibido: " . ($identifier ? $identifier : "NULO"));

if (!$identifier) {
    echo json_encode([]);
    writeLog("Fin: No se recibió identificador.");
    exit;
}

try {
    $tech_id = null;

    // 3. AUTO-CORRECCIÓN: ¿Es Teléfono o ID?
    // Si tiene más de 6 dígitos, asumimos que es un teléfono y buscamos el ID
    if (strlen((string)$identifier) > 6) {
        $stmtFind = $pdo->prepare("SELECT id FROM technicians WHERE phone = ? LIMIT 1");
        $stmtFind->execute([$identifier]);
        $tech_id = $stmtFind->fetchColumn();
        writeLog("Buscando por teléfono. ID encontrado: " . ($tech_id ? $tech_id : "Ninguno"));
    } else {
        $tech_id = $identifier; // Asumimos que ya es un ID
    }

    if (!$tech_id) {
        echo json_encode([]);
        writeLog("Fin: Técnico no encontrado en BD.");
        exit;
    }

    // 4. BUSCAR CARTAS (ASIGNADAS O DEVUELTAS)
    $sql = "SELECT * FROM letters 
            WHERE tech_id = ? 
            AND status IN ('ASSIGNED', 'RETURNED') 
            ORDER BY due_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tech_id]);
    $letters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    writeLog("Cartas encontradas: " . count($letters));

    // 5. PREPARAR DATOS DE DEVOLUCIÓN
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    // Ajuste de URL base: Sube un nivel desde /api/ para llegar a la raíz donde está uploads/
    $baseUrl = $protocol . $_SERVER['HTTP_HOST'] . str_replace('/api', '', dirname($_SERVER['PHP_SELF'])) . '/';

    $response = [];
    foreach ($letters as $l) {
        $item = $l;
        $item['returned_data'] = null;

        if ($l['status'] === 'RETURNED') {
            writeLog("Procesando devolución para carta ID: " . $l['id']);
            
            // Mensaje previo
            $stmtMsg = $pdo->prepare("SELECT content FROM letter_attachments WHERE letter_id = ? AND type = 'message' ORDER BY id DESC LIMIT 1");
            $stmtMsg->execute([$l['id']]);
            $msg = $stmtMsg->fetchColumn();

            // Dibujo previo
            $stmtDrw = $pdo->prepare("SELECT file_path FROM letter_attachments WHERE letter_id = ? AND type = 'drawing' ORDER BY id DESC LIMIT 1");
            $stmtDrw->execute([$l['id']]);
            $draw = $stmtDrw->fetchColumn();

            // Fotos previas
            $stmtPh = $pdo->prepare("SELECT file_path FROM letter_attachments WHERE letter_id = ? AND type = 'photo'");
            $stmtPh->execute([$l['id']]);
            $photos = $stmtPh->fetchAll(PDO::FETCH_COLUMN);

            $fullPhotos = array_map(fn($p) => $baseUrl . $p, $photos);
            $fullDraw = $draw ? $baseUrl . $draw : null;

            $item['returned_data'] = [
                'message' => $msg ?: '',
                'drawing' => $fullDraw,
                'photos'  => $fullPhotos
            ];
        }
        $response[] = $item;
    }

    echo json_encode($response);
    writeLog("Enviado correctamente.");

} catch (Exception $e) {
    writeLog("ERROR SQL: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>