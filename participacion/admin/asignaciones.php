<?php
// admin/asignaciones.php
require_once '../config/db.php';

// ... (TU CÓDIGO PHP DE GUARDAR Y BORRAR SE MANTIENE IGUAL ARRIBA) ...
// Copia tu bloque PHP del inicio tal cual lo tenías, no cambia nada en la lógica del servidor.
// Solo pegaré aquí la parte visual modificada.

// 1. GUARDAR ASIGNACIÓN
if (isset($_POST['btn_asignar'])) {
    $nombre    = $mysqli->real_escape_string($_POST['nombre_docente']);
    $identidad = $mysqli->real_escape_string($_POST['identidad_docente']);
    $muni      = $mysqli->real_escape_string($_POST['municipio']);
    $centro    = $mysqli->real_escape_string($_POST['centro']);
    $grado     = $mysqli->real_escape_string($_POST['grado']);
    
    // Verificamos si ya existe
    $check = $mysqli->query("SELECT id FROM asignaciones_docente WHERE identidad_docente='$identidad' AND centro_educativo='$centro' AND grado='$grado'");
    
    if($check->num_rows == 0){
        $sql = "INSERT INTO asignaciones_docente (nombre_docente, identidad_docente, municipio, centro_educativo, grado) 
                VALUES ('$nombre', '$identidad', '$muni', '$centro', '$grado')";
        if($mysqli->query($sql)) $msg = "✅ Asignación guardada.";
        else $error = "❌ Error SQL: " . $mysqli->error;
    } else {
        $error = "⚠️ Docente ya asignado a ese grado.";
    }
}
// 2. ELIMINAR
if (isset($_GET['borrar'])) {
    $mysqli->query("DELETE FROM asignaciones_docente WHERE id=".$_GET['borrar']);
    header("Location: asignaciones.php"); exit;
}
$asignaciones = $mysqli->query("SELECT * FROM asignaciones_docente ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Asignaciones</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Estilo para la lista de sugerencias flotante */
        #lista_sugerencias {
            position: absolute;
            z-index: 1000;
            width: 100%;
            display: none; /* Oculto por defecto */
        }
        .sugerencia-item { cursor: pointer; }
        .sugerencia-item:hover { background-color: #f0f2f5; }
    </style>
</head>
<body class="bg-light">
<?php include '../includes/navbar.php'; ?>

<div class="container mt-4">
    <h3 class="mb-4">👨‍🏫 Asignación de Carga Académica</h3>
    
    <?php if(isset($msg)) echo "<div class='alert alert-success'>$msg</div>"; ?>
    <?php if(isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow p-3">
                <h5 class="card-title text-primary">Nueva Asignación</h5>
                <hr>
                <form method="POST" autocomplete="off"> <div class="mb-3 position-relative">
                        <label class="form-label fw-bold">Nombre del Docente:</label>
                        <input type="text" id="nombre_docente" name="nombre_docente" class="form-control" required placeholder="Escribe para buscar...">
                        
                        <ul id="lista_sugerencias" class="list-group shadow"></ul>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Identidad:</label>
                        <input type="text" id="identidad_docente" name="identidad_docente" class="form-control" required placeholder="Se llenará automático...">
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label">Municipio:</label>
                        <select id="municipio" name="municipio" class="form-select" required></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Centro Educativo:</label>
                        <select id="centro" name="centro" class="form-select" required disabled></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Grado:</label>
                        <select id="grado" name="grado" class="form-select" required disabled></select>
                    </div>
                    
                    <button type="submit" name="btn_asignar" class="btn btn-primary w-100">💾 Guardar y Asignar</button>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow p-3">
                <h5 class="card-title">Asignaciones Vigentes</h5>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Docente</th>
                                <th>Identidad</th>
                                <th>Ubicación</th> <th>Grado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $asignaciones->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold"><?= $row['nombre_docente'] ?></td>
                                <td><?= $row['identidad_docente'] ?></td>
                                <td>
                                    <small class="text-muted"><?= $row['municipio'] ?></small><br>
                                    <?= $row['centro_educativo'] ?>
                                </td>
                                <td><span class="badge bg-success"><?= $row['grado'] ?></span></td>
                                <td>
                                    <a href="asignaciones.php?borrar=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">X</a>
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

<script>
    // --- LÓGICA DEL BUSCADOR GOOGLE (AUTOCOMPLETAR) ---
    const inputNombre = document.getElementById('nombre_docente');
    const inputIdentidad = document.getElementById('identidad_docente');
    const lista = document.getElementById('lista_sugerencias');

    inputNombre.addEventListener('keyup', function() {
        let busqueda = this.value;

        if (busqueda.length > 2) { // Buscar solo si escribe más de 2 letras
            fetch(`api_docentes.php?q=${encodeURIComponent(busqueda)}`)
                .then(response => response.json())
                .then(data => {
                    lista.innerHTML = ''; // Limpiar lista anterior
                    
                    if (data.length > 0) {
                        lista.style.display = 'block';
                        data.forEach(docente => {
                            // Crear elemento de la lista
                            let li = document.createElement('li');
                            li.className = 'list-group-item sugerencia-item d-flex justify-content-between align-items-center';
                            li.innerHTML = `
                                <span>${docente.nombre_docente}</span>
                                <small class="text-muted">${docente.identidad_docente}</small>
                            `;
                            
                            // Evento Click: Rellenar campos
                            li.onclick = function() {
                                inputNombre.value = docente.nombre_docente;
                                inputIdentidad.value = docente.identidad_docente;
                                lista.style.display = 'none'; // Ocultar lista
                            };
                            
                            lista.appendChild(li);
                        });
                    } else {
                        lista.style.display = 'none';
                    }
                });
        } else {
            lista.style.display = 'none';
        }
    });

    // Ocultar lista si hacen clic fuera
    document.addEventListener('click', function(e) {
        if (e.target !== inputNombre) {
            lista.style.display = 'none';
        }
    });

    // --- LÓGICA DE SELECTS ANIDADOS (TU CÓDIGO ANTERIOR) ---
    const selMuni = document.getElementById('municipio');
    const selCentro = document.getElementById('centro');
    const selGrado = document.getElementById('grado');

    fetch('api_lugares.php?accion=municipios')
        .then(r => r.json()).then(data => llenar(selMuni, data));

    selMuni.addEventListener('change', function() {
        selCentro.innerHTML = ''; selCentro.disabled = true;
        selGrado.innerHTML = ''; selGrado.disabled = true;
        if(this.value) {
            fetch(`api_lugares.php?accion=centros&municipio=${encodeURIComponent(this.value)}`)
                .then(r => r.json()).then(data => { llenar(selCentro, data); selCentro.disabled = false; });
        }
    });

    selCentro.addEventListener('change', function() {
        selGrado.innerHTML = ''; selGrado.disabled = true;
        if(this.value) {
            fetch(`api_lugares.php?accion=grados&centro=${encodeURIComponent(this.value)}`)
                .then(r => r.json()).then(data => { llenar(selGrado, data); selGrado.disabled = false; });
        }
    });

    function llenar(select, data) {
        select.innerHTML = '<option value="">-- Seleccionar --</option>';
        data.forEach(txt => {
            let opt = document.createElement('option');
            opt.value = txt; opt.text = txt; select.add(opt);
        });
    }
</script>
</body>
</html>