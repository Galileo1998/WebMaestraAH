<?php
// admin/estudiantes.php
require_once '../config/db.php';

// Cargamos Municipios desde PHP para el filtro inicial (opcional, también se podría por AJAX)
$munis = $mysqli->query("SELECT DISTINCT municipio FROM estudiantes ORDER BY municipio ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Directorio de Estudiantes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        .filtro-box { background: #fff; padding: 20px; border-radius: 10px; border-left: 5px solid #0d6efd; }
        .badge-genero-m { background-color: #36b9cc; color: white; }
        .badge-genero-f { background-color: #e74a3b; color: white; }
    </style>
</head>
<body class="bg-light">

<?php include '../includes/navbar.php'; ?>

<div class="container-fluid px-4 mt-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark">🎓 Base de Datos de Estudiantes</h3>
            <p class="text-muted mb-0">Gestión y filtrado de los <?= number_format(9101) ?> registros</p>
        </div>
        <a href="importar.php" class="btn btn-success shadow-sm"><i class="bi bi-file-earmark-spreadsheet"></i> Importar Nuevos</a>
    </div>

<div class="filtro-box mb-4 shadow-sm">
        <div class="row g-3">
            
            <div class="col-md-2">
                <label class="form-label fw-bold text-success">Base #</label>
                <select id="filtroBase" class="form-select">
                    <option value="">Todas</option>
                    </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold text-success">Caserío</label>
                <select id="filtroCaserio" class="form-select">
                    <option value="">Todos los Caseríos</option>
                    </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold text-primary">Municipio</label>
                <select id="filtroMuni" class="form-select">
                    <option value="">Todos</option>
                    <?php while($m = $munis->fetch_assoc()): ?>
                        <option value="<?= $m['municipio'] ?>"><?= $m['municipio'] ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label fw-bold text-primary">Centro Educativo</label>
                <select id="filtroCentro" class="form-select" disabled>
                    <option value="">-- Selecciona Muni --</option>
                </select>
            </div>

            <div class="col-md-1 d-flex align-items-end">
                <button id="btnLimpiar" class="btn btn-secondary w-100"><i class="bi bi-arrow-counterclockwise"></i></button>
            </div>
        </div>
    </div>

    <div class="card shadow border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table id="tablaEstudiantes" class="table table-striped table-hover align-middle" style="width:100%">
                    <thead class="table-dark">
                        <tr>
                            <th>ID NNAJ</th>
                            <th>Nombre Completo / SACE</th>
                            <th>Género</th>
                            <th>Edad</th>
                            <th>Grado</th>
                            <th>Centro Educativo</th>
                            <th>Ubicación (Muni/Base)</th> <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

<script>
    $(document).ready(function() {
        
        // ===============================================
        // 1. CARGA INICIAL: BASES (Números 1-17)
        // ===============================================
        $.ajax({
            url: 'api_lugares.php?accion=bases&nocache=' + new Date().getTime(), // Truco anti-caché
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Llenamos el combo de Bases
                data.forEach(item => {
                    $('#filtroBase').append(`<option value="${item.numero}">${item.etiqueta}</option>`);
                });
            },
            error: function(e) { console.error("Error cargando bases", e); }
        });

        // ===============================================
        // 2. CONFIGURACIÓN DE LA TABLA (DATATABLES)
        // ===============================================
        var table = $('#tablaEstudiantes').DataTable({
            "processing": true,
            "serverSide": true,
            "ajax": {
                "url": "api_estudiantes.php",
                "type": "POST",
                "data": function(d) {
                    d.filtro_base      = $('#filtroBase').val();
                    d.filtro_caserio   = $('#filtroCaserio').val();
                    d.filtro_municipio = $('#filtroMuni').val();
                    d.filtro_centro    = $('#filtroCentro').val();
                    d.filtro_grado     = $('#filtroGrado').val();
                }
            },
            "columns": [
                { "data": 0 }, { "data": 1 }, { "data": 2 }, { "data": 3 },
                { "data": 4 }, { "data": 5 }, { "data": 6 }, { "data": 7, "orderable": false }
            ],
            language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json' },
            pageLength: 25
        });

        // ===============================================
        // 3. EVENTO CLAVE: CAMBIO DE BASE
        // ===============================================
        $('#filtroBase').change(function() {
            var baseSeleccionada = $(this).val();
            
            // Recargamos la tabla principal
            table.draw();
            
            // Si eligió una base, cargamos los caseríos
            cargarCaserios(baseSeleccionada);
        });

        // Evento cambio de Caserío
        $('#filtroCaserio').change(function() { table.draw(); });

        // Botón Limpiar
        $('#btnLimpiar').click(function() {
            $('select').val('');
            $('#filtroCaserio').html('<option value="">Todos los Caseríos</option>');
            table.search('').draw();
        });

        // ===============================================
        // 4. FUNCIÓN BLINDADA PARA CARGAR CASERÍOS
        // ===============================================
        function cargarCaserios(baseID) {
            var $select = $('#filtroCaserio');
            
            // Si no hay base, limpiamos y salimos
            if (!baseID) {
                $select.html('<option value="">Todos los Caseríos</option>');
                return;
            }

            $select.html('<option value="">Cargando datos...</option>');

            // URL con "timestamp" para evitar que el navegador use memoria vieja
            var urlApi = 'api_lugares.php?accion=caserios&base=' + baseID + '&nocache=' + new Date().getTime();

            // Usamos AJAX explícito
            $.ajax({
                url: urlApi,
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    // Verificación de datos
                    if (Array.isArray(data) && data.length > 0) {
                        var html = '<option value="">Todos los Caseríos</option>';
                        data.forEach(function(nombreCaserio) {
                            html += `<option value="${nombreCaserio}">${nombreCaserio}</option>`;
                        });
                        $select.html(html);
                        
                        // DEBUG VISUAL: Si esto sale, funcionó
                        console.log("✅ Caseríos cargados: " + data.length);
                    } else {
                        $select.html('<option value="">-- Sin resultados --</option>');
                        console.warn("⚠️ La API devolvió lista vacía para base " + baseID);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("❌ Error AJAX:", error);
                    console.log("Respuesta cruda:", xhr.responseText);
                    $select.html('<option value="">Error de conexión</option>');
                    alert("Error al conectar con la base de datos.");
                }
            });
        }
        
        // Lógica antigua de Municipio/Centro (se mantiene igual)
        $('#filtroMuni').change(function() { table.draw(); cargarCentros($(this).val()); });
        $('#filtroCentro').change(function() { table.draw(); cargarGrados($(this).val()); });
        $('#filtroGrado').change(function() { table.draw(); });
        
        function cargarCentros(muni) {
            if(!muni) return;
            $.get('api_lugares.php?accion=centros&municipio=' + encodeURIComponent(muni), function(d) {
                var h='<option value="">Todos</option>'; d.forEach(c=>{h+=`<option value="${c}">${c}</option>`});
                $('#filtroCentro').html(h).prop('disabled',false);
            });
        }
        function cargarGrados(centro) {
             if(!centro) return;
             $.get('api_lugares.php?accion=grados&centro=' + encodeURIComponent(centro), function(d) {
                var h='<option value="">Todos</option>'; d.forEach(c=>{h+=`<option value="${c}">${c}</option>`});
                $('#filtroGrado').html(h).prop('disabled',false);
            });
        }
    });
</script>

</body>
</html>