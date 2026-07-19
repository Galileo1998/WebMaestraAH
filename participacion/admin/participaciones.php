<?php
// admin/participaciones.php
session_start();
require_once '../config/db.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// --- 1. OBTENER OPCIONES PARA LOS FILTROS ---
$periodos = $mysqli->query("SELECT id, nombre FROM periodos ORDER BY id DESC");
$actividades = $mysqli->query("SELECT id, nombre_actividad FROM actividades ORDER BY nombre_actividad");
$municipios = $mysqli->query("SELECT DISTINCT municipio FROM estudiantes ORDER BY municipio");
$centros = $mysqli->query("SELECT DISTINCT centro_educativo FROM estudiantes ORDER BY centro_educativo");
$grados = $mysqli->query("SELECT DISTINCT grado_actual FROM estudiantes ORDER BY grado_actual");

// --- 2. CONSTRUIR LA CONSULTA CON FILTROS ---
$where = "WHERE 1=1"; // Base para concatenar ANDs

// Capturamos los filtros del GET
$f_periodo = $_GET['periodo'] ?? '';
$f_actividad = $_GET['actividad'] ?? '';
$f_muni = $_GET['municipio'] ?? '';
$f_centro = $_GET['centro'] ?? '';
$f_grado = $_GET['grado'] ?? '';
$f_busqueda = $_GET['busqueda'] ?? '';

if($f_periodo) $where .= " AND act.periodo_id = '$f_periodo'";
if($f_actividad) $where .= " AND p.actividad_id = '$f_actividad'";
if($f_muni) $where .= " AND e.municipio = '$f_muni'";
if($f_centro) $where .= " AND e.centro_educativo = '$f_centro'";
if($f_grado) $where .= " AND e.grado_actual = '$f_grado'";
if($f_busqueda) $where .= " AND e.nombre_completo LIKE '%$f_busqueda%'";

// Consulta Maestra (JOIN entre Participaciones, Estudiantes y Actividades)
$sql = "
    SELECT 
        p.id, p.fecha, p.timestamp_registro, p.firma, p.coordenadas, p.ip_origen,
        e.nombre_completo, e.centro_educativo, e.grado_actual, e.municipio,
        act.nombre_actividad,
        per.nombre as nombre_periodo
    FROM participaciones p
    JOIN estudiantes e ON p.estudiante_id = e.id_nnaj
    JOIN actividades act ON p.actividad_id = act.id
    JOIN periodos per ON act.periodo_id = per.id
    $where
    ORDER BY p.timestamp_registro DESC, p.fecha DESC
    LIMIT 200
";

$resultados = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Participaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .firma-img { width: 80px; height: 40px; border: 1px solid #ddd; background: #fff; object-fit: contain; }
        .table-hover tbody tr:hover { background-color: #f1f8ff; }
        .filters-card { background-color: #f8f9fa; border-left: 5px solid #0d6efd; }
    </style>
</head>
<body class="bg-light">

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-primary"><i class="bi bi-check2-all"></i> Registro de Participaciones</h2>
        <span class="badge bg-secondary fs-6">Mostrando últimos 200 registros</span>
    </div>

    <div class="card filters-card shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Buscar Estudiante</label>
                    <input type="text" name="busqueda" class="form-control" placeholder="Nombre..." value="<?= htmlspecialchars($f_busqueda) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold small">Periodo</label>
                    <select name="periodo" class="form-select">
                        <option value="">Todos</option>
                        <?php while($r = $periodos->fetch_assoc()): ?>
                            <option value="<?= $r['id'] ?>" <?= $f_periodo == $r['id'] ? 'selected' : '' ?>><?= $r['nombre'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold small">Actividad</label>
                    <select name="actividad" class="form-select">
                        <option value="">Todas</option>
                        <?php while($r = $actividades->fetch_assoc()): ?>
                            <option value="<?= $r['id'] ?>" <?= $f_actividad == $r['id'] ? 'selected' : '' ?>><?= $r['nombre_actividad'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold small">Centro Educativo</label>
                    <select name="centro" class="form-select">
                        <option value="">Todos</option>
                        <?php while($r = $centros->fetch_assoc()): ?>
                            <option value="<?= $r['centro_educativo'] ?>" <?= $f_centro == $r['centro_educativo'] ? 'selected' : '' ?>><?= $r['centro_educativo'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-bold small">Grado</label>
                    <select name="grado" class="form-select">
                        <option value="">Todos</option>
                        <?php while($r = $grados->fetch_assoc()): ?>
                            <option value="<?= $r['grado_actual'] ?>" <?= $f_grado == $r['grado_actual'] ? 'selected' : '' ?>><?= $r['grado_actual'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="col-12 text-end">
                    <a href="participaciones.php" class="btn btn-outline-secondary me-2">Limpiar</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-filter"></i> Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4">Fecha / Hora</th>
                            <th scope="col">Estudiante</th>
                            <th scope="col">Centro / Grado</th>
                            <th scope="col">Actividad</th>
                            <th scope="col">Ubicación</th>
                            <th scope="col">Firma</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($resultados->num_rows > 0): ?>
                            <?php while($row = $resultados->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= date('d/m/Y', strtotime($row['fecha'])) ?></div>
                                        <small class="text-muted">
                                            <?= $row['timestamp_registro'] ? date('H:i A', strtotime($row['timestamp_registro'])) : '--:--' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= $row['nombre_completo'] ?></div>
                                        <small class="text-muted"><?= $row['municipio'] ?></small>
                                    </td>
                                    <td>
                                        <div><?= $row['centro_educativo'] ?></div>
                                        <span class="badge bg-info text-dark bg-opacity-10 border border-info px-2"><?= $row['grado_actual'] ?></span>
                                    </td>
                                    <td>
                                        <div class="text-primary fw-bold"><?= $row['nombre_actividad'] ?></div>
                                        <small class="text-secondary"><?= $row['nombre_periodo'] ?></small>
                                    </td>
                                    <td>
                                        <?php if($row['coordenadas'] && $row['coordenadas'] != 'Sin GPS'): ?>
                                            <a href="https://www.google.com/maps?q=<?= $row['coordenadas'] ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-geo-alt-fill"></i> Ver Mapa
                                            </a>
                                            <div class="small text-muted mt-1" style="font-size: 0.7em;">IP: <?= $row['ip_origen'] ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="bi bi-wifi-off"></i> Sin GPS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['firma'])): ?>
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#modalFirma<?= $row['id'] ?>">
                                                <img src="<?= $row['firma'] ?>" class="firma-img rounded" alt="Firma">
                                            </a>

                                            <div class="modal fade" id="modalFirma<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Firma de <?= $row['nombre_completo'] ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body text-center bg-light">
                                                            <img src="<?= $row['firma'] ?>" class="img-fluid" style="max-height: 300px;">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No se encontraron participaciones con estos filtros.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white py-3">
            <small class="text-muted">Nota: Las firmas offline aparecerán aquí en cuanto el docente sincronice.</small>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>