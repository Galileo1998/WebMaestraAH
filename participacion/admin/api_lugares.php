<?php
// admin/api_lugares.php

// 1. INICIAR BUFFER (La aspiradora)
ob_start();

require_once '../config/db.php';

// Ocultar errores de PHP para que no ensucien el JSON
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

$accion = $_GET['accion'] ?? '';
$data = [];

try {
    // --- LÓGICA DE BASES ---
    if ($accion == 'bases') {
        $nombresBases = [
            1 => "Lepaterique C", 2 => "La Brea", 3 => "Ocote Hueco", 4 => "Mulhuaca",
            5 => "Culguaque", 6 => "Turturupe", 7 => "Estancia", 8 => "Naranjo",
            9 => "Oropule", 10 => "Alubaren", 11 => "El Llano", 12 => "Los Tablones",
            13 => "El Chaparral", 14 => "San Marcos", 15 => "Emituca", 16 => "Malagua",
            17 => "Reitoca"
        ];

        $sql = "SELECT DISTINCT CAST(caserio AS UNSIGNED) as num_base 
                FROM estudiantes 
                WHERE caserio REGEXP '^[0-9]' 
                ORDER BY num_base ASC";
        $res = $mysqli->query($sql);
        while($row = $res->fetch_assoc()) {
            $num = $row['num_base'];
            $nombre = $nombresBases[$num] ?? "Base Desconocida";
            $data[] = ['numero' => $num, 'etiqueta' => "$num - $nombre"];
        }
    }

    // --- LÓGICA DE CASERÍOS (La que nos interesa) ---
    elseif ($accion == 'caserios') {
        $sql = "SELECT DISTINCT caserio FROM estudiantes WHERE caserio != ''";
        
        if (!empty($_GET['base'])) {
            $b = $mysqli->real_escape_string($_GET['base']);
            // Regex para buscar "12" al inicio, seguido de algo que no sea número
            $sql .= " AND caserio REGEXP '^0*$b([^0-9]|$)'";
        }
        
        $sql .= " ORDER BY caserio ASC";
        $res = $mysqli->query($sql);
        
        if($res) {
            while($row = $res->fetch_assoc()) {
                // Convertimos a UTF-8 por si acaso
                $data[] = mb_convert_encoding($row['caserio'], 'UTF-8', 'UTF-8');
            }
        }
    }

    // --- OTROS (Municipios, Centros, Grados) ---
    elseif ($accion == 'municipios') {
        $res = $mysqli->query("SELECT DISTINCT municipio FROM estudiantes ORDER BY municipio");
        while($r = $res->fetch_assoc()) $data[] = mb_convert_encoding($r['municipio'], 'UTF-8', 'UTF-8');
    }
    elseif ($accion == 'centros') {
        $m = $mysqli->real_escape_string($_GET['municipio'] ?? '');
        $res = $mysqli->query("SELECT DISTINCT centro_educativo FROM estudiantes WHERE municipio='$m' ORDER BY centro_educativo");
        while($r = $res->fetch_assoc()) $data[] = mb_convert_encoding($r['centro_educativo'], 'UTF-8', 'UTF-8');
    }
    elseif ($accion == 'grados') {
        $c = $mysqli->real_escape_string($_GET['centro'] ?? '');
        $res = $mysqli->query("SELECT DISTINCT grado_actual FROM estudiantes WHERE centro_educativo='$c' ORDER BY grado_actual");
        while($r = $res->fetch_assoc()) $data[] = mb_convert_encoding($r['grado_actual'], 'UTF-8', 'UTF-8');
    }

} catch (Exception $e) {
    // Nada
}

// 2. LIMPIEZA FINAL (Aquí ocurre la magia)
// Borramos cualquier cosa que se haya impreso antes (espacios, enters, warnings)
ob_end_clean(); 

// 3. Enviamos SOLO el JSON puro
echo json_encode($data);
exit;
?>