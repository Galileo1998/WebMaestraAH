<?php
session_start();

// 1. ZONA HORARIA
date_default_timezone_set('America/Tegucigalpa');

// VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';
ini_set('display_errors', 0); 
error_reporting(E_ALL);
require '../vendor/autoload.php';

$mensaje_error = "";
$mostrar_formulario = true;
$rows = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['pdf_file'])) {
    
    if ($_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($_FILES['pdf_file']['tmp_name']);
            $cleanText = preg_replace('/\s+/', ' ', $pdf->getText());

            // --- REGEX ORIGINALES ---
            preg_match_all('/Slip Id:\s*(\d+)/i', $cleanText, $matches_slip);
            preg_match_all('/Child Name:\s*(.*?)(?=\s*Contact Id:|\s*Age:|\s*Gender:|$)/i', $cleanText, $matches_name);
            preg_match_all('/Child Nbr:\s*(\d+)/i', $cleanText, $matches_nbr);
            preg_match_all('/Village:\s*(.*?)(?=\s*Child Name:|\s*Case:|$)/i', $cleanText, $matches_village);
            preg_match_all('/Due Date:\s*([\d\-A-Za-z]+)/i', $cleanText, $matches_date);
            
            preg_match_all('/Sex:\s*([MF])/i', $cleanText, $matches_sex);
            preg_match_all('/Birthdate:\s*([\d\-A-Za-z]+)/i', $cleanText, $matches_birth);
            preg_match_all('/Contact Id:\s*(\d+)/i', $cleanText, $matches_cid);
            preg_match_all('/Contact Name:\s*(.*?)(?=\s*Case:|\s*Child Nbr:|$)/i', $cleanText, $matches_cname);
            preg_match_all('/IA ID:\s*(.*?)(?=\s*Contact Name:|\s*-\s*USA|$)/i', $cleanText, $matches_ia);

            // --- REGEX NUEVOS ---
            preg_match_all('/(Child Welcome Letter|Child Reply Letter|Thank You Letter)/i', $cleanText, $matches_type);
            preg_match_all('/Community Id:\s*(\d+)/i', $cleanText, $matches_comm);
            preg_match_all('/Date Request:\s*([\d\-A-Za-z]+)/i', $cleanText, $matches_req);

            $slips = $matches_slip[1];
            $count = count($slips);

            // ---------------------------------------------------------
            // NUEVO: VERIFICAR DUPLICADOS EN BASE DE DATOS
            // ---------------------------------------------------------
            $existing_slips = [];
            if ($count > 0) {
                // Creamos placeholders (?,?,?) según la cantidad de slips
                $placeholders = implode(',', array_fill(0, $count, '?'));
                $sqlCheck = "SELECT slip_id FROM letters WHERE slip_id IN ($placeholders)";
                $stmtCheck = $pdo->prepare($sqlCheck);
                $stmtCheck->execute($slips); // Pasamos el array de IDs extraídos
                $existing_slips = $stmtCheck->fetchAll(PDO::FETCH_COLUMN); // Array simple con los IDs que ya existen
            }

            for ($i = 0; $i < $count; $i++) {
                
                $slipId = trim($slips[$i]);
                
                // Verificar si este ID específico ya existe
                $isDuplicate = in_array($slipId, $existing_slips);

                // Obtener Datos Nuevos
                $lType = trim($matches_type[1][$i] ?? 'Unknown');
                $commId = trim($matches_comm[1][$i] ?? '');
                $reqDate = trim($matches_req[1][$i] ?? '');
                
                // Calcular Fecha Técnico (Zona Horaria Local y Medianoche)
                $daysToAdd = 7; 
                if (stripos($lType, 'Welcome') !== false) $daysToAdd = 5;
                elseif (stripos($lType, 'Reply') !== false) $daysToAdd = 14;
                elseif (stripos($lType, 'Thank') !== false) $daysToAdd = 20;

                // Usamos DateTime con hora fija para evitar errores de cálculo
                $uploadDate = new DateTime('now');
                $techDeadline = clone $uploadDate;
                $techDeadline->modify("+$daysToAdd days");
                $techDate = $techDeadline->format('d-M-Y');

                $rows[] = [
                    'slip_id'      => $slipId,
                    'is_duplicate' => $isDuplicate, // Bandera de duplicado
                    'child_nbr'    => trim($matches_nbr[1][$i] ?? ''),
                    'child_name'   => trim($matches_name[1][$i] ?? ''),
                    'village'      => trim($matches_village[1][$i] ?? ''),
                    'due_date'     => trim($matches_date[1][$i] ?? ''),
                    'sex'          => trim($matches_sex[1][$i] ?? ''),
                    'birthdate'    => trim($matches_birth[1][$i] ?? ''),
                    'contact_id'   => trim($matches_cid[1][$i] ?? ''),
                    'contact_name' => trim($matches_cname[1][$i] ?? ''),
                    'ia_id'        => trim($matches_ia[1][$i] ?? ''),
                    'letter_type'  => $lType,
                    'community_id' => $commId,
                    'request_date' => $reqDate,
                    'tech_date'    => $techDate
                ];
            }
            $mostrar_formulario = false;

        } catch (Exception $e) {
            $mensaje_error = "Error al leer el PDF: " . $e->getMessage();
        }
    } elseif ($_FILES['pdf_file']['error'] === UPLOAD_ERR_INI_SIZE) {
        $mensaje_error = "⚠️ El archivo es demasiado grande.";
    } else {
        $mensaje_error = "⚠️ Error en la subida.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cargar y Editar - MagicLetter</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --color-primary: #46B094; --color-support: #34859B; --color-accent: #B4D6E0; --color-bg: #f4f7f6; --color-error: #dc3545; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--color-bg); margin: 0; color: #444; }
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .upload-card { background: white; padding: 60px 40px; border-radius: 12px; box-shadow: 0 4px 25px rgba(0,0,0,0.08); text-align: center; max-width: 550px; margin: 0 auto; border-top: 6px solid var(--color-primary); }
        .file-input-container input[type="file"] { border: 2px dashed var(--color-accent); padding: 30px; width: 100%; border-radius: 8px; background: #fafafa; cursor: pointer; }
        .btn-upload { background: var(--color-primary); color: white; padding: 14px 40px; border: none; border-radius: 30px; cursor: pointer; font-size: 16px; font-weight: bold; width: 100%; margin-top: 20px; }
        .review-card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border-top: 5px solid var(--color-support); }
        .review-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .btn-cancel { color: #888; text-decoration: none; font-weight: 600; font-size: 14px; }
        .table-wrapper { overflow-x: auto; max-height: 65vh; margin-top: 10px; border-radius: 8px; border: 1px solid #eee; }
        table { width: 100%; border-collapse: collapse; min-width: 1100px; font-size: 13px; }
        th { background: var(--color-primary); color: white; padding: 15px; text-align: left; position: sticky; top: 0; z-index: 10; }
        td { padding: 8px 10px; border-bottom: 1px solid #eee; background: white; vertical-align: middle; }
        input[type="text"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        
        /* Estilos Filas */
        .btn-row-action { background: transparent; border: none; font-size: 16px; cursor: pointer; padding: 5px; color: #888; }
        .btn-delete:hover { color: var(--color-error); }
        .btn-add-row { background: var(--color-accent); color: var(--color-support); border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-confirm { background: var(--color-primary); color: white; padding: 12px 30px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; float: right; margin-top: 15px; }
        
        /* ESTILOS DE DUPLICADO */
        .duplicate-row td { background-color: #fff5f5 !important; opacity: 0.7; }
        .duplicate-row input { background-color: #eee; color: #999; }
        .duplicate-badge { background: #dc3545; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; margin-bottom: 3px; display: inline-block; }
    </style>
</head>
<body>

    <?php include 'navbar.php'; ?>

    <div class="container">
        
        <?php if ($mensaje_error): ?>
            <div style="background:#fff5f5; color:#dc3545; padding:15px; border-radius:8px; margin-bottom:20px; text-align:center; border:1px solid #ffc9c9;">
                <?= $mensaje_error ?>
            </div>
        <?php endif; ?>

        <?php if ($mostrar_formulario): ?>
            <div class="upload-card">
                <h2 style="color:var(--color-support);">Cargar Lote PDF</h2>
                <p style="color:#888;">Sube el PDF para extraer las cartas.</p>
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="file-input-container">
                        <input type="file" name="pdf_file" accept=".pdf" required>
                    </div>
                    <button type="submit" class="btn-upload">Analizar PDF</button>
                </form>
            </div>

        <?php else: ?>
            <div class="review-card">
                <div class="review-header">
                    <div>
                        <h2 style="margin:0; color:var(--color-primary);">Revisión de Datos</h2>
                        <p style="margin-top:5px;">
                            Registros: <strong id="count-badge" style="color:var(--color-support);"><?= count($rows) ?></strong>
                        </p>
                    </div>
                    <a href="revisar_carga.php" class="btn-cancel">❌ Descartar</a>
                </div>

                <form id="mainForm" action="confirmar_carga.php" method="POST">
                    <input type="hidden" name="json_paquete" id="json_paquete">

                    <div class="table-wrapper">
                        <table id="dataTable">
                            <thead>
                                <tr>
                                    <th width="50"></th> <th width="10%">Slip ID</th>
                                    <th width="10%">N° Niño</th>
                                    <th width="20%">Nombre / Tipo</th>
                                    <th width="20%">Comunidad (ID)</th>
                                    <th width="15%">Patrocinador</th>
                                    <th width="15%">Fechas (PDF vs Téc)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r): 
                                    $rowClass = $r['is_duplicate'] ? 'duplicate-row' : '';
                                    $disabled = $r['is_duplicate'] ? 'disabled' : '';
                                ?>
                                <tr class="data-row <?= $rowClass ?>">
                                    <td style="text-align:center;">
                                        <button type="button" class="btn-row-action btn-delete" onclick="eliminarFila(this)" title="Borrar fila">🗑️</button>
                                    </td>
                                    
                                    <td>
                                        <?php if($r['is_duplicate']): ?>
                                            <div class="duplicate-badge">DUPLICADO</div>
                                        <?php endif; ?>
                                        <input type="text" class="d-slip" value="<?= htmlspecialchars($r['slip_id']) ?>" style="font-weight:bold;" <?= $disabled ?>>
                                    </td>
                                    
                                    <td><input type="text" class="d-nbr" value="<?= htmlspecialchars($r['child_nbr']) ?>" <?= $disabled ?>></td>
                                    
                                    <td>
                                        <input type="text" class="d-name" value="<?= htmlspecialchars($r['child_name']) ?>" style="margin-bottom:2px;" <?= $disabled ?>>
                                        <input type="text" class="d-type" value="<?= htmlspecialchars($r['letter_type']) ?>" style="font-size:11px; background:#f0f0f0;" readonly>
                                    </td>
                                    
                                    <td>
                                        <input type="text" class="d-village" value="<?= htmlspecialchars($r['village']) ?>" style="margin-bottom:2px;" <?= $disabled ?>>
                                        <input type="text" class="d-comm" value="<?= htmlspecialchars($r['community_id']) ?>" placeholder="ID" style="width:60px; font-size:11px; background:#f0f0f0;" <?= $disabled ?>>
                                    </td>

                                    <td><input type="text" class="d-cname" value="<?= htmlspecialchars($r['contact_name']) ?>" <?= $disabled ?>></td>
                                    
                                    <td>
                                        <div style="display:flex; flex-direction:column; gap:2px;">
                                            <input type="text" class="d-date" value="<?= htmlspecialchars($r['due_date']) ?>" style="font-size:11px;" title="PDF" <?= $disabled ?>>
                                            <input type="text" class="d-tech" value="<?= htmlspecialchars($r['tech_date']) ?>" style="font-size:11px; font-weight:bold; color:#d9534f;" title="Tec" <?= $disabled ?>>
                                        </div>
                                    </td>
                                    
                                    <input type="hidden" class="d-dup" value="<?= $r['is_duplicate'] ? '1' : '0' ?>">
                                    <input type="hidden" class="d-req" value="<?= htmlspecialchars($r['request_date']) ?>">
                                    <input type="hidden" class="d-sex" value="<?= htmlspecialchars($r['sex']) ?>">
                                    <input type="hidden" class="d-birth" value="<?= htmlspecialchars($r['birthdate']) ?>">
                                    <input type="hidden" class="d-cid" value="<?= htmlspecialchars($r['contact_id']) ?>">
                                    <input type="hidden" class="d-ia" value="<?= htmlspecialchars($r['ia_id']) ?>">
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <button type="button" class="btn-add-row" onclick="agregarFila()">
                            <i class="fa-solid fa-plus-circle"></i> Agregar Manual
                        </button>

                        <div style="text-align:right;">
                            <span id="warning-dups" style="color:#dc3545; font-size:12px; font-weight:bold; margin-right:10px; display:none;">
                                ⚠️ Se ignorarán las cartas duplicadas.
                            </span>
                            <button type="button" onclick="enviarDatos()" class="btn-confirm">
                                <i class="fa-solid fa-check"></i> Guardar Nuevas
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <script>
                // Mostrar advertencia si hay duplicados al cargar
                window.onload = function() {
                    if(document.querySelectorAll('.duplicate-row').length > 0) {
                        document.getElementById('warning-dups').style.display = 'inline';
                    }
                };

                function eliminarFila(btn) {
                    if(confirm('¿Borrar esta fila?')) {
                        btn.closest('tr').remove();
                        actualizarContador();
                    }
                }

                function agregarFila() {
                    const tbody = document.querySelector('#dataTable tbody');
                    const nuevaFila = `
                        <tr class="data-row">
                            <td style="text-align:center;"><button type="button" class="btn-row-action btn-delete" onclick="eliminarFila(this)">🗑️</button></td>
                            <td><input type="text" class="d-slip" placeholder="Slip ID"></td>
                            <td><input type="text" class="d-nbr" placeholder="N°"></td>
                            <td><input type="text" class="d-name" placeholder="Nombre"><input type="text" class="d-type" placeholder="Tipo" style="font-size:11px; margin-top:2px;"></td>
                            <td><input type="text" class="d-village" placeholder="Comunidad"><input type="text" class="d-comm" placeholder="ID" style="width:60px; font-size:11px; margin-top:2px;"></td>
                            <td><input type="text" class="d-cname" placeholder="Patrocinador"></td>
                            <td><input type="text" class="d-date" placeholder="PDF" style="font-size:11px;"><input type="text" class="d-tech" placeholder="Tec" style="font-size:11px; margin-top:2px;"></td>
                            
                            <input type="hidden" class="d-dup" value="0">
                            <input type="hidden" class="d-req" value=""><input type="hidden" class="d-sex" value=""><input type="hidden" class="d-birth" value=""><input type="hidden" class="d-cid" value=""><input type="hidden" class="d-ia" value="">
                        </tr>`;
                    tbody.insertAdjacentHTML('beforeend', nuevaFila);
                    actualizarContador();
                    document.querySelector('.table-wrapper').scrollTop = document.querySelector('.table-wrapper').scrollHeight;
                }

                function actualizarContador() {
                    document.getElementById('count-badge').innerText = document.querySelectorAll('.data-row').length;
                }

                function enviarDatos() {
                    let filas = document.querySelectorAll('.data-row');
                    let datos = [];
                    let ignorados = 0;
                    
                    filas.forEach(row => {
                        // VERIFICAR SI ES DUPLICADO
                        let isDuplicate = row.querySelector('.d-dup').value === '1';
                        let slip = row.querySelector('.d-slip').value.trim();

                        if (isDuplicate) {
                            ignorados++;
                            return; // SALTAR ESTA ITERACIÓN (No se agrega al array)
                        }

                        if(slip) {
                            datos.push({
                                slip_id:      slip,
                                child_nbr:    row.querySelector('.d-nbr').value,
                                child_name:   row.querySelector('.d-name').value,
                                letter_type:  row.querySelector('.d-type').value,
                                village:      row.querySelector('.d-village').value,
                                community_id: row.querySelector('.d-comm').value,
                                contact_name: row.querySelector('.d-cname').value,
                                due_date:     row.querySelector('.d-date').value,
                                tech_date:    row.querySelector('.d-tech').value,
                                request_date: row.querySelector('.d-req').value,
                                sex:          row.querySelector('.d-sex').value,
                                birthdate:    row.querySelector('.d-birth').value,
                                contact_id:   row.querySelector('.d-cid').value,
                                ia_id:        row.querySelector('.d-ia').value
                            });
                        }
                    });
                    
                    if(datos.length === 0) {
                        alert("No hay cartas nuevas para guardar." + (ignorados > 0 ? "\n(" + ignorados + " duplicados ignorados)" : ""));
                        return;
                    }

                    let msg = "¿Confirmas guardar " + datos.length + " cartas nuevas?";
                    if (ignorados > 0) msg += "\n(Se ignorarán " + ignorados + " cartas duplicadas)";

                    if(confirm(msg)) {
                        document.getElementById('json_paquete').value = JSON.stringify(datos);
                        document.getElementById('mainForm').submit();
                    }
                }
            </script>
        <?php endif; ?>

    </div>
</body>
</html>