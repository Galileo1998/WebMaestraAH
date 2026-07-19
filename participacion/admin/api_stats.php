<?php
// admin/api_stats.php
require_once '../config/db.php'; // Tu conexión (asegúrate de que usa $mysqli)

header('Content-Type: application/json');

try {
    // 1. DATO: Total de Participaciones (Contador grande)
    $totalQuery = $mysqli->query("SELECT COUNT(*) as total FROM participaciones");
    $total = $totalQuery->fetch_assoc()['total'];

    // 2. DATO: Gráfico de Barras (Participaciones por Actividad)
    // Agrupamos por nombre de actividad para ver cuál tiene más asistencia
    $sqlGrafico = "
        SELECT a.nombre_actividad, COUNT(p.id) as cantidad 
        FROM participaciones p
        JOIN actividades a ON p.actividad_id = a.id
        GROUP BY p.actividad_id
    ";
    $resGrafico = $mysqli->query($sqlGrafico);

    $labels = [];
    $data = [];

    while($row = $resGrafico->fetch_assoc()) {
        $labels[] = $row['nombre_actividad']; // Eje X (Nombres)
        $data[] = $row['cantidad'];           // Eje Y (Barras)
    }

    // Devolvemos todo en un paquete JSON
    echo json_encode([
        'status' => 'success',
        'total_firmas' => $total,
        'labels' => $labels,
        'values' => $data,
        'hora' => date('H:i:s') // Para ver cuándo se actualizó
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>