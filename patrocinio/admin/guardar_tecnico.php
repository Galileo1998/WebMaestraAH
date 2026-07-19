<?php
require '../db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'];
    $name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $comm = trim($_POST['community_assigned']);
    $status = $_POST['status'];

    if (empty($id)) {
        // CREAR NUEVO
        $sql = "INSERT INTO technicians (full_name, phone, community_assigned, status) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $phone, $comm, $status]);
    } else {
        // EDITAR EXISTENTE
        $sql = "UPDATE technicians SET full_name=?, phone=?, community_assigned=?, status=? WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $phone, $comm, $status, $id]);
    }
}
header("Location: tecnicos.php");
exit;