<?php
// Archivo: admin/test_db.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Prueba de Conexión a Base de Datos</h1>";

// 1. Verificar si el archivo existe
if (!file_exists('../db_config.php')) {
    die("<h3 style='color:red'>❌ Error Fatal: No encuentro el archivo '../db_config.php'. Verifica la ruta.</h3>");
} else {
    echo "<p>✅ Archivo db_config.php encontrado.</p>";
}

// 2. Intentar conectar
try {
    require '../db_config.php';
    
    if (isset($pdo)) {
        echo "<p>✅ Variable \$pdo detectada.</p>";
        
        // 3. Intentar una consulta simple
        $stmt = $pdo->query("SELECT count(*) FROM letters"); // Cambia 'letters' por tu tabla real si es distinta
        $count = $stmt->fetchColumn();
        echo "<h3 style='color:green'>✅ ¡CONEXIÓN EXITOSA!</h3>";
        echo "<p>Actualmente hay <strong>$count</strong> cartas en la tabla.</p>";
    } else {
        echo "<h3 style='color:red'>❌ Error: El archivo db_config.php cargó, pero la variable \$pdo no existe.</h3>";
    }

} catch (Exception $e) {
    echo "<h3 style='color:red'>❌ Error de Conexión SQL:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>