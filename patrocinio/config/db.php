<?php
$host = "localhost";
$db_name = "magic_letters_db";
$username = "root"; 
$password = "1998";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(["error" => "No se pudo conectar a la base de datos: " . $e->getMessage()]));
}
?>