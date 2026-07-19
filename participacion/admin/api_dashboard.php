<?php
// admin/api_dashboard.php
require_once '../config/db.php';

header('Content-Type: application/json');

try {
    // --- 1. KPIs ---
    
    // Total Estudiantes
    $totalEstudiantes = $mysqli->query("SELECT COUNT(*) as total FROM estudiantes")->fetch_assoc()['total'];

    // Total Municipios
    $totalMunicipios = $mysqli->query("SELECT COUNT(DISTINCT municipio) as total FROM estudiantes")->fetch_assoc()['total'];

    // Total Centros (Tu lógica exacta)
    $sqlCentros = "SELECT COUNT(DISTINCT codigo_sace) as total FROM estudiantes WHERE codigo_sace != '' AND codigo_sace != '0'";
    $totalCentros = $mysqli->query($sqlCentros)->fetch_assoc()['total'];
    if ($totalCentros < 10) { 
        $totalCentros = $mysqli->query("SELECT COUNT(*) as total FROM (SELECT DISTINCT centro_educativo, municipio, caserio FROM estudiantes) as t")->fetch_assoc()['total'];
    }

    // Participación Hoy
    $hoy = date('Y-m-d');
    $totalParticipacionHoy = 0;
    $checkTable = $mysqli->query("SHOW TABLES LIKE 'participaciones'");
    if($checkTable->num_rows > 0) {
        $totalParticipacionHoy = $mysqli->query("SELECT COUNT(*) as total FROM participaciones WHERE fecha = '$hoy'")->fetch_assoc()['total'] ?? 0;
    }

    // --- 2. DATOS PARA GRÁFICOS ---

    // A. Distribución por Género
    $qGenero = $mysqli->query("SELECT genero, COUNT(*) as cant FROM estudiantes GROUP BY genero");
    $labelGen = []; $dataGen = [];
    while($r = $qGenero->fetch_assoc()) {
        $labelGen[] = ($r['genero'] == 'F') ? 'Femenino' : 'Masculino';
        $dataGen[] = $r['cant'];
    }

    // B. Top 5 Centros (Tu lógica exacta con Caserío)
    $qTop = $mysqli->query("
        SELECT MAX(centro_educativo) as nombre, MAX(caserio) as ubicacion, COUNT(*) as cant 
        FROM estudiantes 
        GROUP BY codigo_sace 
        ORDER BY cant DESC LIMIT 5
    ");
    $labelTop = []; $dataTop = [];
    while($r = $qTop->fetch_assoc()) {
        $labelTop[] = $r['nombre'] . ' (' . $r['ubicacion'] . ')'; 
        $dataTop[] = $r['cant'];
    }

    // C. Participación Mensual
    $dataMeses = array_fill(0, 12, 0);
    if($checkTable->num_rows > 0) {
        $anioActual = date('Y');
        $qMes = $mysqli->query("SELECT MONTH(fecha) as mes_num, COUNT(*) as total FROM participaciones WHERE YEAR(fecha) = '$anioActual' GROUP BY MONTH(fecha)");
        while($r = $qMes->fetch_assoc()) {
            $indice = $r['mes_num'] - 1; 
            if(isset($dataMeses[$indice])) $dataMeses[$indice] = $r['total'];
        }
    }

    // --- RESPUESTA JSON ---
    echo json_encode([
        'status' => 'success',
        'kpis' => [
            'estudiantes' => number_format($totalEstudiantes),
            'centros' => number_format($totalCentros),
            'municipios' => number_format($totalMunicipios),
            'hoy' => number_format($totalParticipacionHoy)
        ],
        'charts' => [
            'genero' => ['labels' => $labelGen, 'data' => $dataGen],
            'top' => ['labels' => $labelTop, 'data' => $dataTop],
            'mensual' => ['data' => $dataMeses]
        ],
        'timestamp' => date('H:i:s')
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>