<?php
// admin/importar.php
// Aquí podrías agregar: session_start(); y verificar si hay admin logueado
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carga de Docentes/Estudiantes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="card text-center shadow">
        <div class="card-header bg-dark text-white">
            <h3>📂 Carga de Estudiantes y Asignaciones</h3>
        </div>
        <div class="card-body">
            <h5 class="card-title">Subir archivo Maestro (.csv)</h5>
            <p class="card-text text-muted">
                El archivo debe contener: ID NNAJ, SACE, Nombre, Género, Fecha Nac., Edad, Grado, Centro, Municipio, Caserío.
            </p>

            <form action="procesar_csv.php" method="POST" enctype="multipart/form-data" class="d-flex justify-content-center flex-column align-items-center gap-3">
                <div class="mb-3 w-50">
                    <input type="file" class="form-control" name="archivo_csv" accept=".csv" required>
                </div>
                
                <button type="submit" name="btn_importar" class="btn btn-primary btn-lg">
                    Ir a Importar
                </button>
            </form>
        </div>
        <div class="card-footer text-muted">
            <a href="dashboard.php" class="btn btn-link">Volver al Dashboard</a>
        </div>
    </div>
</div>

</body>
</html>