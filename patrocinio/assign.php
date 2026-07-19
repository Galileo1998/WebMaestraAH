<?php
// assign.php
include 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['user_id'])) {
    $local_id = $_POST['local_id'];
    $user_id = $_POST['user_id'];

    $stmt = $pdo->prepare("UPDATE letters SET assigned_to_user_id = ?, status = 'ASIGNADA', updated_at = NOW() WHERE local_id = ?");
    
    if ($stmt->execute([$user_id, $local_id])) {
        header("Location: admin.php?success=1");
    } else {
        echo "Error al asignar.";
    }
}
?>