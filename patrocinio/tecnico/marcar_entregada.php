<?php
session_start();

// VERIFICACIÓN DE SEGURIDAD TÉCNICO
if (!isset($_SESSION['tech_id'])) {
    // Si no hay sesión de técnico, mandar al login de técnico
    header("Location: index.php");
    exit;
}

require '../db_config.php';


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_SESSION['tech_id'])) {
    $letter_id = $_POST['letter_id'];

    // Actualizamos el estado a COMPLETADO
    // (Opcional: Podríamos guardar la fecha exacta de entrega en otro campo 'delivered_at')
    $sql = "UPDATE letters SET status = 'COMPLETADO' WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$letter_id]);
}

header("Location: panel.php");
exit;