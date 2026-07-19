<?php
session_start();

// VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    // Si no hay sesión de admin, mandar al login principal
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $letter_id = $_POST['letter_id'];
    $tech_id = $_POST['tech_id'];

    if (!empty($letter_id) && !empty($tech_id)) {
        try {
            // Actualizamos la carta con el ID del técnico y la fecha actual
            $sql = "UPDATE letters SET tech_id = ?, assigned_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tech_id, $letter_id]);
            
            // (Opcional) Mensaje de éxito o log
        } catch (Exception $e) {
            die("Error al asignar: " . $e->getMessage());
        }
    }
}

// Volver a la lista
header("Location: asignaciones.php");
exit;