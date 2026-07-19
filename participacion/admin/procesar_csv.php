<?php
// admin/procesar_csv.php
require_once '../config/db.php';

set_time_limit(300); // 5 minutos de tiempo límite para archivos grandes

if (isset($_POST['btn_importar']) && isset($_FILES['archivo_csv'])) {

    $archivoTmp = $_FILES['archivo_csv']['tmp_name'];
    $nombreArchivo = $_FILES['archivo_csv']['name'];
    
    $ext = pathinfo($nombreArchivo, PATHINFO_EXTENSION);
    if(strtolower($ext) !== 'csv') {
        die("<script>alert('❌ Error: Sube un archivo .csv'); window.history.back();</script>");
    }

    if (($gestor = fopen($archivoTmp, "r")) !== FALSE) {
        
        // 1. Detección de Separador
        $primeraLinea = fgets($gestor);
        rewind($gestor); 
        $delimitador = (substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',')) ? ';' : ',';
        
        // Saltar encabezados
        fgetcsv($gestor, 0, $delimitador);

        // SQL
        $sql = "INSERT INTO estudiantes 
                (id_nnaj, codigo_sace, nombre_completo, genero, fecha_nacimiento, edad, grado_actual, centro_educativo, municipio, caserio) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($sql);

        $contador = 0;
        $errores = 0;
        $primerError = "";

        // 2. Recorrido
        $fila = 1;
        while (($datos = fgetcsv($gestor, 2000, $delimitador)) !== FALSE) {
            $fila++;

            if (count($datos) < 10) continue; 

            // MAPEO (Igual que antes, que ya funcionó)
            $id_nnaj    = trim($datos[0] ?? '');
            $nombre     = trim($datos[1] ?? ''); 
            $genero     = trim($datos[4] ?? ''); 
            $fechaRaw   = trim($datos[5] ?? ''); // Leemos la fecha cruda (29/11/2018)
            $edad       = trim($datos[6] ?? '');
            $sace       = trim($datos[9] ?? ''); 
            $centro     = trim($datos[10] ?? '');
            $grado      = trim($datos[11] ?? '');
            $municipio  = trim($datos[12] ?? '');
            $caserio    = trim($datos[14] ?? '');

            // --- CORRECCIÓN DE FECHA (La magia nueva) ---
            // Convertimos de DD/MM/YYYY a YYYY-MM-DD
            $fecha = NULL;
            if (!empty($fechaRaw)) {
                // Si tiene barras /, la volteamos
                if (strpos($fechaRaw, '/') !== false) {
                    $partes = explode('/', $fechaRaw);
                    if (count($partes) == 3) {
                        // $partes[2] es Año, [1] es Mes, [0] es Día
                        $fecha = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
                    }
                } else {
                    // Si ya viene bien o es otro formato, lo dejamos pasar
                    $fecha = $fechaRaw;
                }
            }
            // ---------------------------------------------

            // Codificación UTF-8
            $nombre = mb_convert_encoding($nombre, 'UTF-8', 'auto');
            $centro = mb_convert_encoding($centro, 'UTF-8', 'auto');
            $caserio = mb_convert_encoding($caserio, 'UTF-8', 'auto');
            $municipio = mb_convert_encoding($municipio, 'UTF-8', 'auto');

            $stmt->bind_param("ssssssssss", 
                $id_nnaj, $sace, $nombre, $genero, $fecha, $edad, $grado, $centro, $municipio, $caserio
            );

            try {
                if ($stmt->execute()) {
                    $contador++;
                } else {
                    $errores++;
                    if ($primerError == "") $primerError = "Error SQL fila $fila: " . $stmt->error;
                }
            } catch (Exception $e) {
                $errores++;
                if ($primerError == "") {
                    $primerError = "Fila $fila: " . $e->getMessage() . 
                                   "<br>Dato problemático: Fecha convertida='$fecha' (Original: '$fechaRaw')";
                }
            }
        }

        fclose($gestor);
        
        echo "<div style='font-family: sans-serif; padding: 20px;'>";
        if ($contador > 0) {
            echo "<h2 style='color: green;'>✅ ¡Éxito Total!</h2>";
            echo "<p>Se importaron <b>$contador</b> estudiantes.</p>";
            echo "<p>Ya puedes ir a la App Móvil o Web y ver los datos.</p>";
        } else {
            echo "<h2 style='color: red;'>❌ Sin importaciones</h2>";
        }
        
        if ($errores > 0) {
            echo "<div style='background: #fff3cd; padding: 15px;'>";
            echo "<b>⚠️ Hubo $errores errores. Primer error:</b><br>$primerError";
            echo "</div>";
        }
        echo "<br><a href='importar.php' class='btn'>Volver</a>";
        echo "</div>";

    } else {
        echo "Error al abrir archivo.";
    }
}
?>