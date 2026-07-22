<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
$db = (new Database())->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$auth->checkAccess('monitoreo.php', $db);
if (empty($_SESSION['monitoreo_v2_csrf'])) $_SESSION['monitoreo_v2_csrf'] = bin2hex(random_bytes(32));
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="monitoreo-v2-csrf" content="<?php echo htmlspecialchars((string)$_SESSION['monitoreo_v2_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
<title>Monitoreo Operativo V2 | Acción Honduras</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/monitoreo-v2.css?v=5">
</head>
<body class="monitoreo-v2">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="v2-main">
  <header class="v2-header"><div><h1><i class="fa-solid fa-gauge-high"></i> Monitoreo Operativo <span>V2</span></h1><p>Carga modular independiente</p></div><a class="v2-link" href="monitoreo.php"><i class="fa-solid fa-arrow-left"></i> Versión operativa</a></header>
  <section class="v2-filters"><input id="v2-search" type="search" placeholder="Buscar actividad o código"><select id="v2-program"><option value="">Todos los programas</option><option>CRECER</option><option>REDES</option><option>TEJIENDO MI FUTURO</option></select><select id="v2-sector"><option value="">Todos los sectores</option></select><select id="v2-technician"><option value="">Todos los líderes</option></select><select id="v2-activity-status"><option value="">Todos los estados</option><option>Pendiente</option><option>En Proceso</option><option>Completado</option><option>Reprogramado</option><option>Cancelado</option></select><select id="v2-month"><option value="">Todos los meses</option><option value="jul">Jul</option><option value="aug">Ago</option><option value="sep">Sep</option><option value="oct">Oct</option><option value="nov">Nov</option><option value="dec">Dic</option><option value="jan">Ene</option><option value="feb">Feb</option><option value="mar">Mar</option><option value="apr">Abr</option><option value="may">May</option><option value="jun">Jun</option></select><select id="v2-execution"><option value="">Toda ejecución</option><option value="under">Subejecución</option><option value="over">Sobreejecución</option><option value="none">Sin ejecución</option></select><label class="v2-hidden-filter"><input id="v2-hidden" type="checkbox"> Actividades ocultas</label><button id="v2-filter"><i class="fa-solid fa-filter"></i> Filtrar</button><button id="v2-clear" type="button"><i class="fa-solid fa-eraser"></i> Limpiar</button></section>
  <section id="v2-metrics" class="v2-metrics"></section>
  <section id="v2-status" class="v2-status">Cargando actividades...</section>
  <section id="v2-list" class="v2-list"></section>
  <nav id="v2-pages" class="v2-pages"></nav>
</main>
<div id="v2-modal" class="v2-modal" hidden><div class="v2-dialog"><header><div><small id="v2-code"></small><h2 id="v2-title">Actividad</h2></div><button id="v2-close" aria-label="Cerrar">×</button></header><div id="v2-detail" class="v2-detail"></div></div></div>
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="../assets/js/monitoreo-v2.js?v=5" defer></script>
</body>
</html>
