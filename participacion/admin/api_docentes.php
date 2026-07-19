<?php
// admin/api_docentes.php
require_once '../config/db.php';

header('Content-Type: application/json');

$busqueda = isset($_GET['q']) ? $mysqli->real_escape_string($_GET['q']) : '';

if (strlen($busqueda) > 0) {
    // Buscamos docentes únicos que coincidan con lo escrito
    // Usamos DISTINCT para que no salga "Juan Perez" 20 veces si tiene 20 grados
    $sql = "SELECT DISTINCT nombre_docente, identidad_docente 
            FROM asignaciones_docente 
            WHERE nombre_docente LIKE '%$busqueda%' 
            LIMIT 5";

    $resultado = $mysqli->query($sql);
    
    $docentes = [];
    while ($row = $resultado->fetch_assoc()) {
        $docentes[] = $row;
    }
    
    echo json_encode($docentes);
} else {
    echo json_encode([]);
}
?>