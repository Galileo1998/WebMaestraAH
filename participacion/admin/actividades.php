<?php
// admin/actividades.php
require_once '../config/db.php';

// Validamos que venga el ID del periodo
$periodo_id = intval($_GET['periodo'] ?? 0);
if ($periodo_id == 0) {
    header("Location: periodos.php");
    exit;
}

// Obtener información del periodo (Nombre, fechas...)
$periodo = $mysqli->query("SELECT * FROM periodos WHERE id = $periodo_id")->fetch_assoc();

// 1. GUARDAR NUEVA ACTIVIDAD
if (isset($_POST['btn_agregar'])) {
    // Limpiamos los textos para evitar problemas con comillas
    $nombre = $mysqli->real_escape_string($_POST['nombre']);
    $sector = $mysqli->real_escape_string($_POST['sector']);
    $subsector = $mysqli->real_escape_string($_POST['subsector']);
    $tipo = $mysqli->real_escape_string($_POST['tipo']);
    $marco = $mysqli->real_escape_string($_POST['marco']); 
    $ext = $mysqli->real_escape_string($_POST['ext']);
    $camel = $mysqli->real_escape_string($_POST['camel']); // Agregado campo Camel

    // Insertamos
    $sql = "INSERT INTO actividades (periodo_id, nombre_actividad, sector, subsector, tipo_actividad, marco_logico, extension, camel_id) 
            VALUES ($periodo_id, '$nombre', '$sector', '$subsector', '$tipo', '$marco', '$ext', '$camel')";
    
    if($mysqli->query($sql)) {
        $msg = "✅ Actividad agregada correctamente.";
    } else {
        $error = "❌ Error: " . $mysqli->error;
    }
}

// 2. BORRAR ACTIVIDAD
if (isset($_GET['borrar'])) {
    $id_borrar = intval($_GET['borrar']);
    $mysqli->query("DELETE FROM actividades WHERE id = $id_borrar");
    header("Location: actividades.php?periodo=$periodo_id");
    exit;
}

// Listamos las actividades de este periodo
// LEFT JOIN para contar cuántas firmas hay registradas
$sqlList = "SELECT a.*, COUNT(p.id) as total_firmas 
            FROM actividades a 
            LEFT JOIN participaciones p ON a.id = p.actividad_id
            WHERE a.periodo_id = $periodo_id 
            GROUP BY a.id 
            ORDER BY a.id DESC";
$res = $mysqli->query($sqlList);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actividades - <?= $periodo['nombre'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<?php include '../includes/navbar.php'; ?>

<div class="container mt-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-0">📂 <?= $periodo['nombre'] ?></h3>
            <p class="text-muted mb-0">Gestión de la Matriz Lógica de Actividades</p>
        </div>
        <a href="periodos.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Volver a Reuniones</a>
    </div>

    <?php if(isset($msg)) echo "<div class='alert alert-success'>$msg</div>"; ?>
    <?php if(isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>

    <div class="card shadow mb-4 border-0">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 fw-bold text-dark"><i class="bi bi-plus-circle"></i> Agregar Nueva Actividad</h6>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Nombre de la Actividad</label>
                    <textarea name="nombre" class="form-control" rows="2" required placeholder="Copiar tal cual del Excel..."></textarea>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary">Sector</label>
                        <input type="text" name="sector" class="form-control form-control-sm" placeholder="Ej: U_Educación">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary">Subsector</label>
                        <input type="text" name="subsector" class="form-control form-control-sm" placeholder="Ej: UD_Acceso...">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary">Tipo Actividad</label>
                        <input type="text" name="tipo" class="form-control form-control-sm" placeholder="Ej: Campañas locales">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-secondary">Act. Agregada (Camel)</label>
                        <input type="text" name="camel" class="form-control form-control-sm" placeholder="Opcional">
                    </div>
                </div>

                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Marco Lógico</label>
                        <input type="text" name="marco" class="form-control" placeholder="Ej: UD172-01" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold text-secondary">Extensión</label>
                        <input type="text" name="ext" class="form-control" placeholder="Ej: 1">
                    </div>
                    <div class="col-md-6">
                        <button type="submit" name="btn_agregar" class="btn btn-success w-100 fw-bold">
                            <i class="bi bi-save"></i> Guardar Actividad
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-dark small text-uppercase">
                        <tr>
                            <th>ID</th>
                            <th style="width: 40%;">Actividad</th>
                            <th>Clasificación</th>
                            <th>Firmas</th>
                            <th class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($res->num_rows == 0): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No hay actividades cargadas para este periodo.</td></tr>
                        <?php endif; ?>

                        <?php while($row = $res->fetch_assoc()): ?>
                        <tr>
                            <td><span class="text-muted">#<?= $row['id'] ?></span></td>
                            <td>
                                <a href="detalle_actividad.php?id=<?= $row['id'] ?>" class="fw-bold text-decoration-none text-dark d-block">
                                    <?= $row['nombre_actividad'] ?>
                                </a>
                                <?php if($row['camel_id']): ?>
                                    <span class="badge bg-light text-dark border mt-1">Camel: <?= $row['camel_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <div class="text-primary fw-bold"><?= $row['sector'] ?></div>
                                <div class="text-muted"><?= $row['subsector'] ?></div>
                                <div class="fst-italic"><?= $row['tipo_actividad'] ?></div>
                                <span class="badge bg-warning text-dark border border-warning mt-1">ML: <?= $row['marco_logico'] ?></span>
                            </td>
                            <td>
                                <?php if($row['total_firmas'] > 0): ?>
                                    <span class="badge bg-success fs-6">
                                        <i class="bi bi-pen"></i> <?= $row['total_firmas'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary opacity-25">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="detalle_actividad.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Ver Asistencia">
                                    <i class="bi bi-eye"></i>
                                </a>

                                <a href="actividades.php?periodo=<?= $periodo_id ?>&borrar=<?= $row['id'] ?>" 
                                   class="btn btn-sm btn-outline-danger" 
                                   onclick="return confirm('¿Estás seguro de eliminar esta actividad? Se borrarán las asistencias asociadas.')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</body>
</html>