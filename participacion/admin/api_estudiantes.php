<?php
// admin/api_estudiantes.php
require_once '../config/db.php';

// Encabezado para devolver JSON puro
header('Content-Type: application/json');

// Definir columnas para el ordenamiento (Mapping: Índice DataTables => Columna SQL)
// Deben coincidir con el orden visual de la tabla HTML
$columns = [
    0 => 'id_nnaj',
    1 => 'nombre_completo',
    2 => 'genero',
    3 => 'edad',
    4 => 'grado_actual',
    5 => 'centro_educativo',
    6 => 'municipio', // Aunque mostremos muni+base, ordenamos por municipio
    7 => 'id'
];

// ---------------------------------------------------------
// 1. CONSTRUCCIÓN DE LA CONSULTA (FILTROS)
// ---------------------------------------------------------

$sql = "SELECT * FROM estudiantes WHERE 1=1";

// A. Filtro por BASE / CASERÍO (Independiente)
// admin/api_estudiantes.php


// A. Filtro por BASE (Número inicial)
if (!empty($_POST['filtro_base'])) {
    $b = $mysqli->real_escape_string($_POST['filtro_base']);
    // Usamos el mismo REGEX para consistencia
    $sql .= " AND caserio REGEXP '^0*$b([^0-9]|$)'";
}

// B. Filtro por CASERÍO (Nombre exacto)
if (!empty($_POST['filtro_caserio'])) {
    $c = $mysqli->real_escape_string($_POST['filtro_caserio']);
    $sql .= " AND caserio = '$c'";
}

// ... (sigue con filtro_municipio, etc.)

// B. Filtro por Municipio
if (!empty($_POST['filtro_municipio'])) {
    $m = $mysqli->real_escape_string($_POST['filtro_municipio']);
    $sql .= " AND municipio = '$m'";
}

// C. Filtro por Centro
if (!empty($_POST['filtro_centro'])) {
    $c = $mysqli->real_escape_string($_POST['filtro_centro']);
    $sql .= " AND centro_educativo = '$c'";
}

// D. Filtro por Grado
if (!empty($_POST['filtro_grado'])) {
    $g = $mysqli->real_escape_string($_POST['filtro_grado']);
    $sql .= " AND grado_actual = '$g'";
}

// E. Búsqueda Global (Caja de texto de DataTables)
if (!empty($_POST['search']['value'])) {
    $search_value = $mysqli->real_escape_string($_POST['search']['value']);
    $sql .= " AND (
        nombre_completo LIKE '%$search_value%' OR 
        id_nnaj LIKE '%$search_value%' OR
        codigo_sace LIKE '%$search_value%'
    )";
}

// ---------------------------------------------------------
// 2. CONTEOS PARA PAGINACIÓN
// ---------------------------------------------------------

// Total filtrado (según búsqueda y filtros actuales)
$sqlCount = str_replace("SELECT *", "SELECT COUNT(*) as total", $sql);
$queryCount = $mysqli->query($sqlCount);
$recordsFiltered = $queryCount->fetch_assoc()['total'];

// Total absoluto en la tabla (sin filtros)
$queryTotal = $mysqli->query("SELECT COUNT(*) as total FROM estudiantes");
$totalRecords = $queryTotal->fetch_assoc()['total'];

// ---------------------------------------------------------
// 3. ORDENAMIENTO Y LIMITES
// ---------------------------------------------------------

// Ordenamiento
if (isset($_POST['order'])) {
    $columnIndex = $_POST['order'][0]['column'];
    $columnName = $columns[$columnIndex] ?? 'id'; // Fallback a id
    $direction = $_POST['order'][0]['dir'];
    $sql .= " ORDER BY $columnName $direction";
} else {
    $sql .= " ORDER BY id DESC";
}

// Paginación
if (isset($_POST['length']) && $_POST['length'] != -1) {
    $start = intval($_POST['start']);
    $length = intval($_POST['length']);
    $sql .= " LIMIT $start, $length";
}

// ---------------------------------------------------------
// 4. EJECUCIÓN Y FORMATO DE DATOS
// ---------------------------------------------------------

$query = $mysqli->query($sql);
$data = [];

while ($row = $query->fetch_assoc()) {
    
    // BADGE DE GÉNERO
    // Detectamos si es F, FEMENINO, M o MASCULINO
    $g = strtoupper($row['genero']);
    if (strpos($g, 'F') === 0) {
        $generoHtml = '<span class="badge bg-danger rounded-pill">F</span>';
    } else {
        $generoHtml = '<span class="badge bg-info text-dark rounded-pill">M</span>';
    }

    // BOTONES DE ACCIÓN
    $acciones = '
        <button class="btn btn-sm btn-outline-primary" title="Ver Detalles" onclick="verEstudiante(\''.$row['id'].'\')">
            <i class="bi bi-eye"></i>
        </button>
    ';

    // ARMADO DE FILA (ARRAY INDEXADO)
    $data[] = [
        $row['id_nnaj'], // Col 0: ID
        
        // Col 1: Nombre + SACE
        '<div class="fw-bold text-dark">'.$row['nombre_completo'].'</div>
         <div class="small text-muted"><i class="bi bi-upc-scan"></i> SACE: '.$row['codigo_sace'].'</div>',
        
        $generoHtml, // Col 2: Género
        $row['edad'], // Col 3: Edad
        
        '<span class="badge bg-light text-dark border">'.$row['grado_actual'].'</span>', // Col 4: Grado
        
        $row['centro_educativo'], // Col 5: Centro
        
        // Col 6: Municipio + Base (Caserío)
        '<div>'.$row['municipio'].'</div>
         <div class="small text-success fw-bold"><i class="bi bi-geo-alt"></i> Base: '.$row['caserio'].'</div>',
        
        $acciones // Col 7: Acciones
    ];
}

// ---------------------------------------------------------
// 5. RESPUESTA JSON
// ---------------------------------------------------------

$response = [
    "draw" => intval($_POST['draw'] ?? 0),
    "recordsTotal" => intval($totalRecords),
    "recordsFiltered" => intval($recordsFiltered),
    "data" => $data
];

echo json_encode($response);
?>