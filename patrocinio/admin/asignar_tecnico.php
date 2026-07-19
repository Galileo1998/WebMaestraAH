<?php
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $local_id = $_POST['local_id'];
    $user_id = $_POST['user_id'];

    $stmt = $pdo->prepare("UPDATE letters SET assigned_to_user_id = ?, status = 'ASIGNADA' WHERE local_id = ?");
    $stmt->execute([$user_id, $local_id]);

    header("Location: admin_panel.php");
}
?>