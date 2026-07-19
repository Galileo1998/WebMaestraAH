<?php
session_start();
session_destroy();

// Los dos puntos ".." le dicen al sistema: 
// "Sal de la carpeta actual (admin) y busca el archivo afuera"
header("Location: ../index.php");
exit;
?>