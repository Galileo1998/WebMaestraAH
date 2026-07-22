<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
$db = (new Database())->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$auth->checkAccess('monitoreo.php', $db);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Monitoreo Operativo V2 | Acción Honduras</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/monitoreo-v2.css?v=2">
</head>
<body class="monitoreo-v2">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="v2-main">
  <header class="v2-header"><div><h1><i class="fa-solid fa-gauge-high"></i> Monitoreo Operativo <span>V2</span></h1><p>Carga modular independiente</p></div><a class="v2-link" href="monitoreo.php"><i class="fa-solid fa-arrow-left"></i> Versión operativa</a></header>
  <section class="v2-filters"><input id="v2-search" type="search" placeholder="Buscar actividad o código"><select id="v2-program"><option value="">Todos los programas</option><option>CRECER</option><option>REDES</option><option>TEJIENDO MI FUTURO</option></select><button id="v2-filter"><i class="fa-solid fa-filter"></i> Filtrar</button></section>
  <section id="v2-status" class="v2-status">Cargando actividades...</section>
  <section id="v2-list" class="v2-list"></section>
  <nav id="v2-pages" class="v2-pages"></nav>
</main>
<div id="v2-modal" class="v2-modal" hidden><div class="v2-dialog"><header><div><small id="v2-code"></small><h2 id="v2-title">Actividad</h2></div><button id="v2-close" aria-label="Cerrar">×</button></header><div id="v2-detail" class="v2-detail"></div></div></div>
<script src="../assets/js/monitoreo-v2.js?v=2" defer></script>
</body>
</html>
