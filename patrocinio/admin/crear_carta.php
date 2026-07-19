<?php
// admin/crear_carta.php
include '../db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $local_id = "L" . time() . "_" . rand(1000, 9999);
    
    $sql = "INSERT INTO letters (
        local_id, slip_id, community_id, child_code, child_nbr, 
        child_name, birthdate, sex, village, contact_id, 
        ia_id, contact_name, status, created_at, due_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', NOW(), ?)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $local_id, 
        $_POST['slip_id'], 
        $_POST['community_id'],
        $_POST['child_code'], // Slip ID usualmente
        $_POST['child_nbr'],
        $_POST['child_name'],
        $_POST['birthdate'],
        $_POST['sex'],
        $_POST['village'],
        $_POST['contact_id'],
        $_POST['ia_id'],
        $_POST['contact_name'],
        $_POST['due_date']
    ]);

    header("Location: admin_panel.php");
}
?>