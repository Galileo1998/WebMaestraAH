<?php
session_start();

// 1. SEGURIDAD
if (!isset($_SESSION['tech_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';

// 2. VALIDAR ID
if (!isset($_GET['id'])) {
    header("Location: panel.php");
    exit;
}

$letter_id = $_GET['id'];
$tech_id = $_SESSION['tech_id'];

// 3. CONSULTA PRINCIPAL
$sql = "SELECT * FROM letters WHERE id = ? AND tech_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$letter_id, $tech_id]);
$carta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$carta) {
    die("Carta no encontrada o acceso denegado.");
}

// 4. BUSCAR ADJUNTOS (Soporte para MÚLTIPLES fotos)
$sql_att = "SELECT * FROM letter_attachments WHERE letter_id = ?";
$stmt_att = $pdo->prepare($sql_att);
$stmt_att->execute([$letter_id]);
$adjuntos = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

$dibujo_path = '';
$fotos = []; // Array para guardar varias fotos

foreach ($adjuntos as $adj) {
    if ($adj['file_type'] == 'DRAWING') {
        $dibujo_path = $adj['file_path'];
    }
    if ($adj['file_type'] == 'PHOTO') {
        $fotos[] = $adj['file_path']; // Agregamos cada foto al array
    }
}

// 5. OBTENER EL MENSAJE REAL
$mensaje_real = $carta['final_message'] ?? ''; 

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Detalle Digital - MagicLetter</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --color-primary: #46B094;
            --color-support: #34859B;
            --color-bg: #f4f7f6;
            --color-text: #444;
        }

        body { font-family: 'Segoe UI', sans-serif; background: var(--color-bg); margin: 0; padding: 20px; color: var(--color-text); }
        .container { max-width: 1100px; margin: auto; }

        .header-nav { display: flex; align-items: center; margin-bottom: 20px; }
        .btn-back { 
            text-decoration: none; color: var(--color-support); font-weight: bold; 
            display: flex; align-items: center; gap: 8px; font-size: 14px;
            background: white; padding: 8px 15px; border-radius: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .split-layout { display: grid; grid-template-columns: 1fr; gap: 25px; }
        @media (min-width: 850px) { .split-layout { grid-template-columns: 400px 1fr; align-items: start; } }

        .card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); position: relative; }
        .card-header { padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-weight: 800; color: var(--color-primary); font-size: 13px; letter-spacing: 0.5px; }

        .left-card { border-top: 5px solid var(--color-support); }
        .info-group { margin-bottom: 18px; padding: 0 20px; }
        .label { font-size: 11px; color: #888; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 4px; }
        .value { font-size: 15px; color: #333; font-weight: 600; display: block; border-bottom: 1px solid #f4f4f4; padding-bottom: 4px; }
        .scan-area { text-align: center; margin: 30px 20px 20px; padding-top: 20px; border-top: 2px dashed #eee; }
        .read-badge { background: #e2e6ea; color: #666; padding: 4px 10px; border-radius: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase; }

        .right-card { border-top: 5px solid var(--color-primary); min-height: 500px; }
        .content-body { padding: 25px; }
        
        .message-box {
            background: #fffcf5; border: 1px solid #f0e6d2; border-left: 4px solid #f0ad4e;
            padding: 20px; border-radius: 8px; margin-bottom: 25px;
            font-family: 'Georgia', serif; font-size: 16px; color: #555; line-height: 1.6;
            font-style: italic; position: relative;
        }
        .quote-icon { position: absolute; top: -10px; left: 10px; background: #f0ad4e; color: white; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; }

        .gallery-title { font-size: 14px; font-weight: 700; color: var(--color-support); margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        
        /* Grid dinámico para acomodar 4 elementos (1 dibujo + 3 fotos) */
        .gallery-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); 
            gap: 15px; 
        }
        
        .media-container {
            background: #f8f9fa; border-radius: 8px; overflow: hidden; aspect-ratio: 1/1; /* Cuadrado perfecto */
            position: relative; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center;
        }
        .media-container img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s; cursor: zoom-in; }
        .media-container:hover img { transform: scale(1.05); }
        .media-label { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.6); color: white; font-size: 11px; padding: 5px; text-align: center; }
        .media-placeholder { color: #ccc; text-align: center; }

        .watermark { position: absolute; top: 15px; right: 15px; font-size: 10px; color: #ccc; border: 1px solid #ccc; padding: 2px 6px; border-radius: 4px; pointer-events: none; }
        @media print { body { display: none; } }
    </style>
</head>
<body>

    <div class="container">
        <div class="header-nav">
            <a href="panel.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Volver</a>
        </div>

        <div class="split-layout">
            
            <div class="card left-card">
                <div class="card-header" style="background:#fcfcfc;">
                    <span class="brand">BOLETA TÉCNICA</span>
                    <span class="read-badge">Solo Lectura</span>
                </div>
                <div style="padding-top:20px;">
                    <div class="info-group">
                        <span class="label">Slip ID</span>
                        <span class="value" style="font-family:monospace; color:var(--color-support);">#<?= htmlspecialchars($carta['slip_id']) ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Estado Actual</span>
                        <span class="value" style="color:var(--color-primary);"><i class="fa-solid fa-check-circle"></i> <?= htmlspecialchars($carta['status']) ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Niño</span>
                        <span class="value"><?= htmlspecialchars($carta['child_name']) ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">ID Niño</span>
                        <span class="value"><?= htmlspecialchars($carta['child_nbr'] ?: $carta['child_code']) ?></span>
                    </div>
                    <div class="info-group">
                        <span class="label">Comunidad</span>
                        <span class="value"><?= htmlspecialchars($carta['village']) ?></span>
                    </div>
                    <div class="scan-area">
                        <svg id="barcode"></svg>
                        <p style="font-size:10px; color:#aaa; margin-top:5px;">CONTROL INTERNO</p>
                    </div>
                </div>
            </div>

            <div class="card right-card">
                <div class="watermark">COPIA DIGITAL</div>
                <div class="card-header">
                    <span class="brand"><i class="fa-solid fa-envelope-open-text"></i> CONTENIDO DE LA CARTA</span>
                </div>

                <div class="content-body">
                    
                    <div class="gallery-title"><i class="fa-solid fa-pen-nib"></i> Mensaje</div>
                    <div class="message-box">
                        <div class="quote-icon"><i class="fa-solid fa-quote-left"></i></div>
                        <?php if(!empty($mensaje_real)): ?>
                            <?= nl2br(htmlspecialchars($mensaje_real)) ?>
                        <?php else: ?>
                            <p style="color:#aaa; font-style:normal; font-size:14px; text-align:center;">
                                <i class="fa-regular fa-file-lines" style="font-size:20px; display:block; margin-bottom:5px;"></i>
                                No hay texto disponible.
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="gallery-title"><i class="fa-solid fa-images"></i> Galería de Adjuntos</div>
                    
                    <div class="gallery-grid">
                        
                        <div class="media-container" onclick="verImagenFull(this)">
                            <?php if(!empty($dibujo_path)): ?>
                                <img src="../<?= htmlspecialchars($dibujo_path) ?>" alt="Dibujo">
                            <?php else: ?>
                                <div class="media-placeholder">
                                    <i class="fa-solid fa-palette" style="font-size:30px;"></i>
                                    <span style="font-size:11px; margin-top:5px; display:block;">Sin Dibujo</span>
                                </div>
                            <?php endif; ?>
                            <div class="media-label">Dibujo / Arte</div>
                        </div>

                        <?php if (!empty($fotos)): ?>
                            <?php foreach($fotos as $index => $foto): ?>
                                <div class="media-container" onclick="verImagenFull(this)">
                                    <img src="../<?= htmlspecialchars($foto) ?>" alt="Foto <?= $index + 1 ?>">
                                    <div class="media-label">Foto <?= $index + 1 ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="media-container">
                                <div class="media-placeholder">
                                    <i class="fa-solid fa-camera" style="font-size:30px;"></i>
                                    <span style="font-size:11px; margin-top:5px; display:block;">Sin Fotos</span>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>

                </div>
            </div>
        </div>
    </div>

    <div id="imgModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.9); align-items:center; justify-content:center; cursor:pointer;" onclick="this.style.display='none'">
        <img id="imgFull" src="" style="max-width:90%; max-height:90%; border-radius:5px; box-shadow:0 0 20px rgba(255,255,255,0.2);">
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            JsBarcode("#barcode", "<?= htmlspecialchars($carta['slip_id']) ?>", {
                format: "CODE128", lineColor: "#333", width: 2, height: 40, displayValue: true, fontSize: 14
            });
        });

        function verImagenFull(container) {
            const img = container.querySelector('img');
            if (img && img.src) {
                document.getElementById('imgFull').src = img.src;
                document.getElementById('imgModal').style.display = 'flex';
            }
        }

        document.addEventListener('keydown', function(e) {
            if((e.ctrlKey || e.metaKey) && (e.key == 'p' || e.keyCode == 80) ){
                e.cancelBubble = true; e.preventDefault(); e.stopImmediatePropagation();
            }
        });
    </script>

</body>
</html>