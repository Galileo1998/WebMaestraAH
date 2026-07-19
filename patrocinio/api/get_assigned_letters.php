<?php
// UBICACIÓN: public_html/patrocinio/api/get_assigned_letters.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

date_default_timezone_set('America/Tegucigalpa');

function writeLog($msg) {
    $file = __DIR__ . '/debug_log.txt';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[$time] $msg" . PHP_EOL, FILE_APPEND);
}

writeLog(">>> INICIO Sync (Pull v5 - Fix Tipos) <<<");

try {
    $dbPath = __DIR__ . '/../db_config.php';
    if (!file_exists($dbPath)) throw new Exception("No se encuentra db_config.php");
    require $dbPath;

    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    
    $identifier = $input['phone'] ?? $input['tech_id'] ?? $_POST['phone'] ?? $_POST['tech_id'] ?? null;

    if (!$identifier) { echo json_encode([]); exit; }

    // Buscar ID Técnico
    $stmtPhone = $pdo->prepare("SELECT id FROM technicians WHERE phone = ? LIMIT 1");
    $stmtPhone->execute([$identifier]);
    $idFromPhone = $stmtPhone->fetchColumn();
    $tech_id_real = $idFromPhone ?: $identifier;

    writeLog("Usuario identificado: $tech_id_real");

    $sql = "SELECT * FROM letters 
            WHERE tech_id = ? 
            AND status IN ('ASSIGNED', 'RETURNED') 
            ORDER BY due_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tech_id_real]);
    $letters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $baseUrl = $protocol . $_SERVER['HTTP_HOST'] . str_replace('/api', '', dirname($_SERVER['PHP_SELF'])) . '/';
    $today = new DateTime('today'); 

    $response = [];
    
    foreach ($letters as $l) {
        $item = $l;
        $item['returned_data'] = null;

        // --- CORRECCIÓN DE TIPO DE CARTA ---
        // Buscamos en varias columnas posibles
        $rawType = $l['letter_type'] ?? $l['type'] ?? $l['template'] ?? 'Standard';
        $item['letter_type'] = $rawType; // Forzamos el nombre correcto para la App

        // --- CÁLCULO DE DÍAS ---
        $deadlineStr = $l['technician_due_date'];
        $deadline = DateTime::createFromFormat('d-M-Y', $deadlineStr);
        
        if (!$deadline) {
            $created = new DateTime($l['created_at']);
            $daysToAdd = 7; 
            // Usamos el tipo detectado para calcular
            if (stripos($rawType, 'Welcome') !== false) $daysToAdd = 5;
            elseif (stripos($rawType, 'Reply') !== false) $daysToAdd = 14;
            elseif (stripos($rawType, 'Thank') !== false) $daysToAdd = 20;
            
            $created->modify("+$daysToAdd days");
            $deadline = $created;
            $item['technician_due_date'] = $deadline->format('d-M-Y');
        }

        $deadline->setTime(0,0,0); 
        $diff = $today->diff($deadline);
        $daysRemaining = (int)$diff->format('%r%a');

        $item['days_remaining'] = $daysRemaining;
        $item['due_date_iso'] = $deadline->format('Y-m-d');

        // --- DEVOLUCIONES ---
        if ($l['status'] === 'RETURNED') {
            $stmtAtt = $pdo->prepare("SELECT file_path FROM letter_attachments WHERE letter_id = ?");
            $stmtAtt->execute([$l['id']]);
            $atts = $stmtAtt->fetchAll(PDO::FETCH_COLUMN);

            $msgContent = ""; $drawUrl = null; $photos = [];
            foreach ($atts as $path) {
                $fname = strtolower(basename($path));
                if (strpos($fname, 'message') !== false || substr($fname, -4) === '.txt') {
                    $fullPath = __DIR__ . '/../' . $path;
                    if(file_exists($fullPath)) $msgContent = file_get_contents($fullPath);
                } elseif (strpos($fname, 'draw') !== false) {
                    $drawUrl = $baseUrl . $path;
                } else {
                    $photos[] = $baseUrl . $path;
                }
            }
            $item['returned_data'] = ['message' => $msgContent, 'drawing' => $drawUrl, 'photos' => $photos];
        }

        $response[] = $item;
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>