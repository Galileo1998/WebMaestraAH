<?php
// admin/periodos.php
require_once '../config/db.php';

// 1. CREAR PERIODO
if (isset($_POST['btn_guardar'])) {
    $nombre = $mysqli->real_escape_string($_POST['nombre']);
    $inicio = $_POST['inicio'];
    $fin    = $_POST['fin'];
    $mysqli->query("INSERT INTO periodos (nombre, estado, fecha_inicio, fecha_fin) VALUES ('$nombre', 1, '$inicio', '$fin')");
}

// 2. TOGGLE ESTADO
if (isset($_GET['toggle'])) {
    $id = intval($_GET['toggle']);
    $mysqli->query("UPDATE periodos SET estado = NOT estado WHERE id = $id");
    header("Location: periodos.php"); exit;
}

$res = $mysqli->query("SELECT p.*, (SELECT COUNT(*) FROM actividades WHERE periodo_id = p.id) as cant_actividades FROM periodos p ORDER BY fecha_inicio DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Reuniones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php include '../includes/navbar.php'; ?>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white"><h5>📅 Nueva Reunión/Mes</h5></div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3"><label>Nombre</label><input type="text" name="nombre" class="form-control" placeholder="Ej: Febrero 2026" required></div>
                        <div class="row mb-3">
                            <div class="col"><label>Inicio</label><input type="date" name="inicio" class="form-control" required></div>
                            <div class="col"><label>Fin</label><input type="date" name="fin" class="form-control" required></div>
                        </div>
                        <button type="submit" name="btn_guardar" class="btn btn-success w-100">Crear</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Periodo</th>
                                <th>Actividades</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $res->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?= $row['nombre'] ?></td>
                                <td><span class="badge bg-info text-dark"><?= $row['cant_actividades'] ?> Actividades</span></td>
                                <td>
                                    <?= ($row['estado']==1) ? '<span class="badge bg-success">Abierto</span>' : '<span class="badge bg-secondary">Cerrado</span>' ?>
                                </td>
                                <td>
                                    <a href="actividades.php?periodo=<?= $row['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-list-task"></i> Gestionar</a>
                                    
                                    <a href="periodos.php?toggle=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <?= ($row['estado']==1) ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>' ?>
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
</div>
</body>
</html>