<?php
// admin/api_sync.php
require_once '../config/db.php';

ob_start();
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$response = [];

try {
    // 1. GET: Descarga de datos (Igual que antes)
    if ($method == 'GET') {
        // ... (Copia aquí todo el código GET que ya tenías funcionando para descargar docentes/estudiantes) ...
        // (Por brevedad no lo repito todo, pero asegúrate de dejar la parte del GET igual)
        
        $identidad = $_GET['identidad'] ?? '';
        // ... Lógica del GET ...
        // Si necesitas que te pase el bloque GET completo dímelo, pero es el mismo de antes.
        // Solo asegúrate de que al final devuelves $response con los datos.
        
        // CÓDIGO RESUMIDO DEL GET (Pega aquí tu GET anterior)
        if (empty($identidad)) throw new Exception("Identidad no proporcionada");
        $sqlAsign = "SELECT nombre_docente, municipio, centro_educativo, grado FROM asignaciones_docente WHERE identidad_docente = '$identidad'";
        $resAsign = $mysqli->query($sqlAsign);
        if ($resAsign->num_rows == 0) throw new Exception("No tienes carga académica.");
        
        $asignaciones = []; $nombreDocente = ""; $estudiantes = [];
        while($fila = $resAsign->fetch_assoc()) {
            if (empty($nombreDocente)) $nombreDocente = $fila['nombre_docente'];
            $asignaciones[] = ['municipio' => $fila['municipio'], 'centro' => $fila['centro_educativo'], 'grado' => $fila['grado']];
            
            $stmt = $mysqli->prepare("SELECT id_nnaj, nombre_completo, genero, grado_actual, centro_educativo, municipio FROM estudiantes WHERE municipio = ? AND centro_educativo = ? AND grado_actual = ?");
            $stmt->bind_param("sss", $fila['municipio'], $fila['centro_educativo'], $fila['grado']);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $estudiantes[] = array_map(function($v){ return mb_convert_encoding($v, 'UTF-8', 'UTF-8'); }, $row);
        }
        
        $periodos = [];
        $resPer = $mysqli->query("SELECT id, nombre, fecha_inicio, fecha_fin FROM periodos WHERE estado = 1 ORDER BY fecha_inicio DESC");
        while ($p = $resPer->fetch_assoc()) {
            $acts = [];
            $resAct = $mysqli->query("SELECT id, nombre_actividad, tipo_actividad, marco_logico FROM actividades WHERE periodo_id = " . $p['id']);
            while($a = $resAct->fetch_assoc()) $acts[] = array_map(function($v){ return mb_convert_encoding($v, 'UTF-8', 'UTF-8'); }, $a);
            $p['lista_actividades'] = $acts;
            $periodos[] = $p;
        }

        $response = ['status' => 'success', 'docente' => ['nombre' => $nombreDocente], 'asignaciones' => $asignaciones, 'estudiantes' => $estudiantes, 'periodos' => $periodos];
    }
    
    // 2. POST: Subida de Asistencias (ACTUALIZADO)
    elseif ($method == 'POST') {
        $inputJSON = file_get_contents('php://input');
        $datos = json_decode($inputJSON, true);

        if (!$datos || !isset($datos['participaciones'])) {
            throw new Exception("Datos inválidos");
        }

        $guardados = 0;
        // Agregamos columnas: timestamp_registro, coordenadas, ip_origen
        // Asegúrate de crear estas columnas en tu tabla MySQL si no existen, o el INSERT fallará.
        // ALTER TABLE participaciones ADD COLUMN timestamp_registro DATETIME, ADD COLUMN coordenadas TEXT, ADD COLUMN ip_origen TEXT;
        $stmt = $mysqli->prepare("INSERT INTO participaciones (estudiante_id, actividad_id, fecha, mes, anio, firma, timestamp_registro, coordenadas, ip_origen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $ip_cliente = $_SERVER['REMOTE_ADDR']; // Capturamos la IP automáticamente

        foreach ($datos['participaciones'] as $p) {
            $anio = date('Y', strtotime($p['fecha']));
            
            // Datos nuevos
            $ts_registro = $p['timestamp'] ?? date('Y-m-d H:i:s'); // Hora exacta del celular
            $coords      = $p['coordenadas'] ?? '';                // Lat, Long

            // Evitamos duplicados
            $check = $mysqli->query("SELECT id FROM participaciones WHERE estudiante_id = '{$p['id_nnaj']}' AND actividad_id = '{$p['actividad_id']}' AND fecha = '{$p['fecha']}'");
            
            if ($check->num_rows == 0) {
                $stmt->bind_param("sisssssss", 
                    $p['id_nnaj'], 
                    $p['actividad_id'], 
                    $p['fecha'], 
                    $p['mes_nombre'], 
                    $anio, 
                    $p['firma'],
                    $ts_registro,
                    $coords,
                    $ip_cliente
                );
                if ($stmt->execute()) {
                    $guardados++;
                }
            }
        }

        $response = [
            'status' => 'success',
            'mensaje' => "Sincronización exitosa: $guardados registros guardados."
        ];
    }

} catch (Exception $e) {
    http_response_code(500);
    $response = ['status' => 'error', 'mensaje' => $e->getMessage()];
}

ob_end_clean();
echo json_encode($response);
?>