<?php
session_start();
session_destroy();
// Redirige a la raíz del proyecto
header("Location: ../index.php");
exit;
?>