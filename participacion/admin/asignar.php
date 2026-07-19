<?php
// admin/asignar.php
require_once '../config/db.php';

// PROCESAR FORMULARIO AL GUARDAR
if (isset($_POST['btn_asignar'])) {
    $docente = $_POST['docente_id'];
    $muni    = $_POST['municipio'];
    $centro  = $_POST['centro'];
    $grado   = $_POST['grado'];

    $sql = "INSERT INTO asignaciones_docente (docente_id, municipio, centro_educativo, grado) VALUES (?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("isss", $docente, $muni, $centro, $grado);
    
    if($stmt->execute()){
        echo "<script>alert('✅ Asignación Guardada Correctamente');</script>";
    } else {
        echo "<script>alert('❌ Error al guardar');</script>";
    }
}

// OBTENER LISTA DE DOCENTES (USUARIOS)
$sqlDocentes = "SELECT id, nombre_completo FROM usuarios"; // Ajusta 'nombre_completo' a tu tabla real
$resDocentes = $mysqli->query($sqlDocentes);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignar Docente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h4>👨‍🏫 Asignación de Carga Académica</h4>
        </div>
        <div class="card-body">
            <form method="POST">
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Seleccionar Docente:</label>
                    <select name="docente_id" class="form-select" required>
                        <option value="">-- Elige un Docente --</option>
                        <?php while($d = $resDocentes->fetch_assoc()): ?>
                            <option value="<?= $d['id'] ?>"><?= $d['nombre_completo'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <hr>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Municipio:</label>
                        <select name="municipio" id="municipio" class="form-select" required disabled>
                            <option value="">Cargando...</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Centro Educativo:</label>
                        <select name="centro" id="centro" class="form-select" required disabled>
                            <option value="">-- Primero elige Municipio --</option>
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label">Grado:</label>
                        <select name="grado" id="grado" class="form-select" required disabled>
                            <option value="">-- Primero elige Centro --</option>
                        </select>
                    </div>
                </div>

                <button type="submit" name="btn_asignar" class="btn btn-success w-100 mt-3">💾 Guardar Asignación</button>
            </form>
        </div>
    </div>
</div>

<script>
    // Referencias a los selects
    const selMuni = document.getElementById('municipio');
    const selCentro = document.getElementById('centro');
    const selGrado = document.getElementById('grado');

    // 1. Cargar Municipios al iniciar
    fetch('api_lugares.php?accion=municipios')
        .then(res => res.json())
        .then(data => {
            llenarSelect(selMuni, data);
            selMuni.disabled = false;
        });

    // 2. Cuando cambia Municipio -> Cargar Centros
    selMuni.addEventListener('change', function() {
        selCentro.innerHTML = '<option value="">Cargando...</option>';
        selGrado.innerHTML = '<option value="">-- Primero elige Centro --</option>';
        selCentro.disabled = true;
        selGrado.disabled = true;

        if(this.value) {
            fetch(`api_lugares.php?accion=centros&municipio=${encodeURIComponent(this.value)}`)
                .then(res => res.json())
                .then(data => {
                    llenarSelect(selCentro, data);
                    selCentro.disabled = false;
                });
        }
    });

    // 3. Cuando cambia Centro -> Cargar Grados
    selCentro.addEventListener('change', function() {
        selGrado.innerHTML = '<option value="">Cargando...</option>';
        selGrado.disabled = true;

        if(this.value) {
            fetch(`api_lugares.php?accion=grados&centro=${encodeURIComponent(this.value)}`)
                .then(res => res.json())
                .then(data => {
                    llenarSelect(selGrado, data);
                    selGrado.disabled = false;
                });
        }
    });

    // Función auxiliar para llenar opciones
    function llenarSelect(selectElement, dataArray) {
        selectElement.innerHTML = '<option value="">-- Seleccionar --</option>';
        dataArray.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item;
            opt.textContent = item;
            selectElement.appendChild(opt);
        });
    }
</script>

</body>
</html>