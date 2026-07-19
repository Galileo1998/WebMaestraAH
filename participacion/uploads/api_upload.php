<?php
// admin/api_upload.php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

// Incluir conexión a BD (Ajusta la ruta si tu config.php está en otro lado)
require_once '../config/db.php'; 

// 1. Recibir el JSON crudo desde la App
$json_str = file_get_contents('php://input');
$data = json_decode($json_str, true);

// 2. Validar que llegó algo
if (!$data || !is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'No se recibieron datos JSON válidos']);
    exit;
}

$guardados = 0;
$errores = 0;

// 3. Procesar cada participación
foreach ($data as $row) {
    $id_nnaj = $mysqli->real_escape_string($row['id_nnaj']);
    $actividad_id = $mysqli->real_escape_string($row['actividad_id']);
    $fecha = $mysqli->real_escape_string($row['fecha']);
    $mes_nombre = $mysqli->real_escape_string($row['mes_nombre'] ?? '');
    $timestamp = $mysqli->real_escape_string($row['timestamp'] ?? date('Y-m-d H:i:s'));
    $coordenadas = $mysqli->real_escape_string($row['coordenadas'] ?? '');
    
    // 4. Manejo de la Firma (Base64 a Archivo) 
    $firma_path = '';
    if (!empty($row['firma'])) {
        // Limpiamos el encabezado "data:image/webp;base64," si viene
        $firma_base64 = preg_replace('#^data:image/\w+;base64,#i', '', $row['firma']);
        $firma_binaria = base64_decode($firma_base64);

        if ($firma_binaria) {
            // Nombre único para la imagen: firma_IDNNAJ_ACTIVIDAD_FECHA.webp
            $nombre_archivo = "firma_{$id_nnaj}_{$actividad_id}_" . time() . ".webp";
            
            // Asegúrate de crear la carpeta 'firmas' en tu servidor
            $ruta_destino = "../uploads/firmas/" . $nombre_archivo; 
            
            if (file_put_contents($ruta_destino, $firma_binaria)) {
                $firma_path = "uploads/firmas/" . $nombre_archivo;
            }
        }
    }

    // 5. Insertar en MySQL (Usamos INSERT IGNORE o ON DUPLICATE KEY UPDATE para evitar duplicados)
    $sql = "INSERT INTO participaciones 
            (id_nnaj, actividad_id, fecha, mes_nombre, firma_path, fecha_registro, coordenadas)
            VALUES 
            ('$id_nnaj', '$actividad_id', '$fecha', '$mes_nombre', '$firma_path', '$timestamp', '$coordenadas')
            ON DUPLICATE KEY UPDATE 
            firma_path = VALUES(firma_path), 
            fecha_registro = VALUES(fecha_registro)";

    if ($mysqli->query($sql)) {
        $guardados++;
    } else {
        $errores++;
        // Opcional: guardar log de error mysql
    }
}

// 6. Respuesta final a la App
echo json_encode([
    'status' => 'success',
    'message' => "Proceso completado. Guardados: $guardados. Errores: $errores",
    'guardados' => $guardados
]);
?>