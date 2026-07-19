<?php
require '../db_config.php';

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("DELETE FROM technicians WHERE id = ?");
    $stmt->execute([$_GET['id']]);
}
header("Location: tecnicos.php");
exit;