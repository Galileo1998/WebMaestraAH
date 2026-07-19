<?php
// admin/detalle_actividad.php
session_start();
require_once '../config/db.php';

// Validar ID
if (!isset($_GET['id'])) {
    header("Location: periodos.php");
    exit;
}

$actividad_id = intval($_GET['id']);

// 1. OBTENER DATOS DE LA ACTIVIDAD
$sqlAct = "SELECT a.*, p.nombre as nombre_periodo 
           FROM actividades a 
           JOIN periodos p ON a.periodo_id = p.id 
           WHERE a.id = '$actividad_id'";
$actividad = $mysqli->query($sqlAct)->fetch_assoc();

// --- CONFIGURACIÓN DE FILTROS ---
$filtros_sql = " WHERE 1=1 ";

// A. Filtro Municipio
$filtro_municipio = $_GET['municipio'] ?? '';
if($filtro_municipio) {
    $clean_muni = $mysqli->real_escape_string($filtro_municipio);
    $filtros_sql .= " AND e.municipio = '$clean_muni'";
}

// B. Filtro Centro (Combinado: Nombre|Municipio para evitar duplicados)
$filtro_centro_compuesto = $_GET['centro'] ?? '';
if($filtro_centro_compuesto) {
    $partes = explode('|', $filtro_centro_compuesto);
    if(count($partes) == 2) {
        $nombre = $mysqli->real_escape_string($partes[0]);
        $muni   = $mysqli->real_escape_string($partes[1]);
        $filtros_sql .= " AND e.centro_educativo = '$nombre' AND e.municipio = '$muni'";
    }
}

// C. Filtro Grado
$filtro_grado = $_GET['grado'] ?? '';
if($filtro_grado) {
    $clean_grado = $mysqli->real_escape_string($filtro_grado);
    $filtros_sql .= " AND e.grado_actual = '$clean_grado'";
}

// --- PAGINACIÓN ---
$registros_por_pagina = 50;
$pagina_actual = isset($_GET['pag']) ? (int)$_GET['pag'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// 1. Contar Total (con filtros)
$sqlCount = "SELECT COUNT(*) as total FROM estudiantes e $filtros_sql";
$total_registros = $mysqli->query($sqlCount)->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);

// 2. Consulta Maestra
// IMPORTANTE: Usamos 'p.estudiante_id' para el JOIN (según tu base de datos MySQL)
$sql = "
    SELECT 
        e.id_nnaj, e.nombre_completo, e.grado_actual, e.centro_educativo, e.municipio,
        p.id as participacion_id, p.fecha, p.firma, p.coordenadas, p.timestamp_registro
    FROM estudiantes e
    LEFT JOIN participaciones p 
        ON e.id_nnaj = p.estudiante_id AND p.actividad_id = '$actividad_id'
    $filtros_sql
    ORDER BY e.nombre_completo ASC
    LIMIT $offset, $registros_por_pagina
";

$estudiantes = $mysqli->query($sql);

// --- OPCIONES PARA SELECTS ---
$municipios = $mysqli->query("SELECT DISTINCT municipio FROM estudiantes ORDER BY municipio");

$sqlCentros = "SELECT DISTINCT centro_educativo, municipio FROM estudiantes";
if($filtro_municipio) {
    $sqlCentros .= " WHERE municipio = '" . $mysqli->real_escape_string($filtro_municipio) . "'";
}
$sqlCentros .= " ORDER BY centro_educativo";
$centros = $mysqli->query($sqlCentros);

$grados = $mysqli->query("SELECT DISTINCT grado_actual FROM estudiantes ORDER BY grado_actual");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle: <?= $actividad['nombre_actividad'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* ESTILO PARA FIRMAS VISIBLES */
        .firma-thumb {
            width: 120px;       /* Ancho generoso */
            height: 50px;       /* Alto fijo */
            object-fit: contain; /* Ajustar imagen completa (ya recortada) */
            
            background-color: #fff; 
            border: 1px solid #ced4da;
            border-radius: 4px;
            
            /* Filtros para oscurecer la tinta gris y hacerla negra */
            filter: contrast(150%) brightness(95%) grayscale(100%);
            
            cursor: zoom-in;
            transition: transform 0.2s;
        }

        .firma-thumb:hover {
            transform: scale(2.5); /* Zoom grande al pasar mouse */
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            border-color: #0d6efd;
            background-color: #fff;
        }
        
        .status-badge { width: 110px; text-align: center; }
        .table td { vertical-align: middle; }
    </style>
</head>
<body class="bg-light">

<?php include '../includes/navbar.php'; ?>

<div class="container py-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="actividades.php?periodo=<?= $actividad['periodo_id'] ?>" class="text-decoration-none text-secondary mb-2 d-inline-block">
                <i class="bi bi-arrow-left"></i> Volver a Actividades
            </a>
            <h2 class="fw-bold text-primary"><?= $actividad['nombre_actividad'] ?></h2>
            <span class="badge bg-secondary"><?= $actividad['nombre_periodo'] ?></span>
            <span class="badge bg-info text-dark"><?= $actividad['tipo_actividad'] ?></span>
        </div>
        <div class="text-end">
            <h2 class="fw-bold mb-0 text-primary"><?= number_format($total_registros) ?></h2>
            <small class="text-muted">Estudiantes encontrados</small>
        </div>
    </div>

    <div class="card shadow-sm mb-4 border-start border-4 border-primary">
        <div class="card-body py-3">
            <form method="GET" id="formFiltros" class="row g-3 align-items-end">
                <input type="hidden" name="id" value="<?= $actividad_id ?>">
                
                <div class="col-md-3">
                    <label class="form-label fw-bold small">Municipio</label>
                    <select name="municipio" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">-- Todos --</option>
                        <?php 
                        $municipios->data_seek(0);
                        while($row = $municipios->fetch_assoc()): 
                            $sel = ($filtro_municipio == $row['municipio']) ? 'selected' : '';
                        ?>
                            <option value="<?= $row['municipio'] ?>" <?= $sel ?>><?= $row['municipio'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-bold small">Centro Educativo</label>
                    <select name="centro" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">-- Todos los Centros --</option>
                        <?php 
                        $centros->data_seek(0);
                        while($row = $centros->fetch_assoc()): 
                            $valorCompuesto = $row['centro_educativo'] . '|' . $row['municipio'];
                            $sel = ($filtro_centro_compuesto == $valorCompuesto) ? 'selected' : '';
                            
                            $texto = $row['centro_educativo'];
                            if(!$filtro_municipio) $texto .= ' (' . $row['municipio'] . ')';
                        ?>
                            <option value="<?= $valorCompuesto ?>" <?= $sel ?>><?= $texto ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-bold small">Grado</label>
                    <select name="grado" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">-- Todos --</option>
                        <?php 
                        $grados->data_seek(0);
                        while($row = $grados->fetch_assoc()): 
                            $sel = ($filtro_grado == $row['grado_actual']) ? 'selected' : '';
                        ?>
                            <option value="<?= $row['grado_actual'] ?>" <?= $sel ?>><?= $row['grado_actual'] ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="col-md-2 text-end">
                    <?php if($filtro_municipio || $filtro_centro_compuesto || $filtro_grado): ?>
                        <a href="detalle_actividad.php?id=<?= $actividad_id ?>" class="btn btn-sm btn-outline-danger w-100">
                            <i class="bi bi-x-lg"></i> Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Estudiante</th>
                            <th>Ubicación</th>
                            <th>Estado</th>
                            <th>Firma (Auto-Zoom)</th>
                            <th>Info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($estudiantes->num_rows > 0): ?>
                            <?php while($est = $estudiantes->fetch_assoc()): ?>
                                <?php $firmo = !empty($est['firma']); ?>
                                <tr class="<?= $firmo ? '' : 'text-muted bg-light bg-opacity-25' ?>">
                                    
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark"><?= $est['nombre_completo'] ?></div>
                                        <small class="text-secondary" style="font-size: 0.75em;">ID: <?= $est['id_nnaj'] ?></small>
                                    </td>
                                    
                                    <td>
                                        <div class="small fw-bold"><?= $est['centro_educativo'] ?></div>
                                        <div class="small text-muted"><?= $est['municipio'] ?> - <?= $est['grado_actual'] ?></div>
                                    </td>

                                    <td>
                                        <?php if($firmo): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success status-badge">
                                                <i class="bi bi-check-lg"></i> Asistió
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border status-badge">
                                                <i class="bi bi-dash"></i> Pendiente
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if($firmo): ?>
                                            <img src="<?= $est['firma'] ?>" 
                                                 class="firma-thumb autocrop" 
                                                 crossorigin="anonymous"
                                                 loading="lazy"
                                                 alt="Firma"
                                                 data-bs-toggle="modal" 
                                                 data-bs-target="#modal<?= $est['id_nnaj'] ?>">

                                            <div class="modal fade" id="modal<?= $est['id_nnaj'] ?>" tabindex="-1">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content">
                                                        <div class="modal-body text-center p-4">
                                                            <h5 class="mb-3"><?= $est['nombre_completo'] ?></h5>
                                                            <div class="border rounded p-3 bg-white">
                                                                <img src="<?= $est['firma'] ?>" class="img-fluid" style="filter: contrast(150%);">
                                                            </div>
                                                            <div class="mt-3 text-muted small">
                                                                Registrado: <?= date('d/m/Y H:i', strtotime($est['timestamp_registro'] ?? $est['fecha'])) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if($firmo && $est['coordenadas'] && $est['coordenadas'] != 'Sin GPS'): ?>
                                            <a href="https://www.google.com/maps?q=<?= $est['coordenadas'] ?>" target="_blank" class="text-danger" title="Ver Mapa">
                                                <i class="bi bi-geo-alt-fill fs-5"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">No se encontraron estudiantes.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card-footer bg-white py-3">
            <nav>
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?= ($pagina_actual <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?id=<?= $actividad_id ?>&municipio=<?= urlencode($filtro_municipio) ?>&centro=<?= urlencode($filtro_centro_compuesto) ?>&grado=<?= urlencode($filtro_grado) ?>&pag=<?= $pagina_actual - 1 ?>">Anterior</a>
                    </li>
                    <li class="page-item disabled">
                        <span class="page-link text-dark">Pág <?= $pagina_actual ?> de <?= $total_paginas ?: 1 ?></span>
                    </li>
                    <li class="page-item <?= ($pagina_actual >= $total_paginas) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?id=<?= $actividad_id ?>&municipio=<?= urlencode($filtro_municipio) ?>&centro=<?= urlencode($filtro_centro_compuesto) ?>&grado=<?= urlencode($filtro_grado) ?>&pag=<?= $pagina_actual + 1 ?>">Siguiente</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const images = document.querySelectorAll('img.autocrop');
    images.forEach(img => {
        if (img.complete) {
            recortarEspaciosVacios(img);
        } else {
            img.onload = () => recortarEspaciosVacios(img);
        }
    });
});

function recortarEspaciosVacios(imgElement) {
    // Evitamos procesar si ya fue procesada o no tiene fuente
    if (!imgElement.src || imgElement.dataset.processed) return;
    
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    canvas.width = imgElement.naturalWidth;
    canvas.height = imgElement.naturalHeight;
    ctx.drawImage(imgElement, 0, 0);
    
    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imgData.data;
    
    let minX = canvas.width, minY = canvas.height, maxX = 0, maxY = 0;
    let hayFirma = false;

    // Escanear píxeles
    for (let y = 0; y < canvas.height; y++) {
        for (let x = 0; x < canvas.width; x++) {
            const i = (y * canvas.width + x) * 4;
            const r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
            
            // Si es oscuro (tinta) y no transparente
            if (a > 0 && (r < 240 || g < 240 || b < 240)) {
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
                hayFirma = true;
            }
        }
    }

    if (hayFirma) {
        // Margen de seguridad
        const padding = 15;
        minX = Math.max(0, minX - padding);
        minY = Math.max(0, minY - padding);
        maxX = Math.min(canvas.width, maxX + padding);
        maxY = Math.min(canvas.height, maxY + padding);
        
        const w = maxX - minX;
        const h = maxY - minY;

        if (w > 0 && h > 0) {
            const cutCanvas = document.createElement('canvas');
            cutCanvas.width = w;
            cutCanvas.height = h;
            const cutCtx = cutCanvas.getContext('2d');
            cutCtx.drawImage(canvas, minX, minY, w, h, 0, 0, w, h);
            
            // Reemplazar la imagen
            imgElement.src = cutCanvas.toDataURL();
            imgElement.dataset.processed = "true"; // Marcar como lista
        }
    }
}
</script>

</body>
</html>