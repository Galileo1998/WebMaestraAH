<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

function assertPrintPurchaseAccess(PDO $db, int $purchaseId): void {
    if ((string)($_SESSION['user_role'] ?? '') === 'admin') return;
    try {
        $st=$db->prepare('SELECT usuario_id FROM ah_compras_propiedad WHERE compra_id=? LIMIT 1');
        $st->execute([$purchaseId]);
        if ((int)$st->fetchColumn()===(int)($_SESSION['user_id']??0)) return;
    } catch (Throwable $e) {}
    http_response_code(403);
    die('No tiene permiso para imprimir este expediente de compra.');
}

$compra_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($compra_id === 0) { die("Identificador de solicitud no válido."); }
assertPrintPurchaseAccess($db,$compra_id);

$stmt = $db->prepare("SELECT * FROM ah_compras WHERE id = ?");
$stmt->execute([$compra_id]);
$compra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$compra) { die("La solicitud de compra no existe."); }

// =========================================================================
// FUNCIONES DE RESPALDO (FALLBACK) PARA HOSTING COMPARTIDO
// =========================================================================
function normalizeCompraFormato($format): string {
    $format = strtoupper(trim((string)$format));
    return in_array($format, ['A','B','C'], true) ? $format : 'A';
}

function comprasFormatoFilePath(): string {
    $base = dirname(__DIR__);
    $dirs = [$base . '/storage', $base . '/data', __DIR__];
    foreach ($dirs as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            return rtrim($dir, '/\\') . '/compras_formatos_store.json';
        }
    }
    return '';
}

function readCompraFormatoFileStore(): array {
    $path = comprasFormatoFilePath();
    if ($path === '' || !is_file($path)) return [];
    $handle = @fopen($path, 'rb');
    if (!$handle) return [];
    @flock($handle, LOCK_SH);
    $json = stream_get_contents($handle);
    @flock($handle, LOCK_UN);
    fclose($handle);
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function getCompraFormato(PDO $db, int $compraId, array $purchase = []): string {
    if (array_key_exists('formato_compra', $purchase) && trim((string)$purchase['formato_compra']) !== '') {
        return normalizeCompraFormato($purchase['formato_compra']);
    }
    if (isset($_SESSION['compras_formatos'][(string)$compraId])) {
        return normalizeCompraFormato($_SESSION['compras_formatos'][(string)$compraId]);
    }
    try {
        $st = $db->prepare('SELECT formato FROM ah_compras_formatos WHERE compra_id=? LIMIT 1');
        $st->execute([$compraId]);
        $value = $st->fetchColumn();
        if ($value !== false) return normalizeCompraFormato($value);
    } catch (Throwable $e) {}
    
    $fileData = readCompraFormatoFileStore();
    if (isset($fileData[(string)$compraId])) {
        $entry = $fileData[(string)$compraId];
        $value = is_array($entry) ? ($entry['formato'] ?? 'A') : $entry;
        return normalizeCompraFormato($value);
    }
    return 'A';
}

function comprasCotizacionesFilePath(): string {
    $base = dirname(__DIR__);
    foreach ([$base . '/storage', $base . '/data', __DIR__] as $dir) {
        if (is_dir($dir) && is_writable($dir)) return rtrim($dir, '/\\') . '/compras_cotizaciones_store.json';
    }
    return '';
}

function readComprasCotizacionesStore(): array {
    $path = comprasCotizacionesFilePath();
    if ($path === '' || !is_file($path)) return [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    @flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function getCompraCotizacionesFallback(int $compraId): array {
    $default = ['quotes' => [], 'rows' => [], 'prices' => [], 'observacion_cotizacion' => '', 'fecha_analisis_cotizacion' => ''];
    $all = readComprasCotizacionesStore();
    $row = isset($all[(string)$compraId]) && is_array($all[(string)$compraId]) ? $all[(string)$compraId] : [];
    if (isset($_SESSION['compras_cotizaciones_fallback'][(string)$compraId]) && is_array($_SESSION['compras_cotizaciones_fallback'][(string)$compraId])) {
        $row = array_replace_recursive($row, $_SESSION['compras_cotizaciones_fallback'][(string)$compraId]);
    }
    return array_replace($default, $row);
}

// Asignar Formato de Compra real (Usando Base de Datos o Fallback JSON)
$compra['formato_compra'] = getCompraFormato($db, $compra_id, $compra);
$is_format_b = $compra['formato_compra'] === 'B';
$is_format_c = $compra['formato_compra'] === 'C';

// =========================================================================
// 1. CARGA DE DETALLES Y AUTO-AJUSTE PARA MUCHAS LÍNEAS
// =========================================================================
$stmt_items = $db->prepare("SELECT * FROM ah_compras_detalles WHERE compra_id = ?");
$stmt_items->execute([$compra_id]);
$items_raw = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

$num_items = max(8, count($items_raw)); 
$items = [];
for ($i = 0; $i < $num_items; $i++) {
    if (isset($items_raw[$i])) {
        $items[] = $items_raw[$i];
    } else {
        $items[] = ['cantidad' => '', 'presentacion' => '', 'articulo' => '', 'caracteristicas' => '', 'precio_unitario' => '', 'precio_total' => '', 'tipo_impuesto' => ''];
    }
}

// =========================================================================
// 2. HALAR DISTRIBUCIÓN POA + PRESUPUESTO ANUAL Y EJECUTADO
// =========================================================================
$stmt_dist = $db->prepare("
    SELECT cp.*,
           p.programa AS programa_poa,
           p.marco_logico AS marco_logico_poa,
           p.presupuesto_anual,
           p.ejecutado
    FROM ah_compras_poa cp
    LEFT JOIN ah_poa p ON cp.poa_hash = p.hash_id
    WHERE cp.compra_id = ?
");
$stmt_dist->execute([$compra_id]);
$distribucion_poa = $stmt_dist->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// 3. LOGICA PARA FORMATOS B Y C CON FALLBACK INCORPORADO
// =========================================================================
$quotes = [];
$resumen_filas = [];
$precios = [];

if ($is_format_b) {
    try {
        $stmt_q = $db->prepare("SELECT * FROM ah_compras_cotizaciones WHERE compra_id = ? ORDER BY posicion");
        $stmt_q->execute([$compra_id]);
        $quotes = $stmt_q->fetchAll(PDO::FETCH_ASSOC);
        $stmt_r = $db->prepare("SELECT * FROM ah_compras_resumen_filas WHERE compra_id = ? ORDER BY orden, id");
        $stmt_r->execute([$compra_id]);
        $resumen_filas = $stmt_r->fetchAll(PDO::FETCH_ASSOC);
        $stmt_p = $db->prepare("SELECT p.* FROM ah_compras_cotizacion_precios p JOIN ah_compras_cotizaciones q ON q.id = p.cotizacion_id WHERE q.compra_id = ?");
        $stmt_p->execute([$compra_id]);
        foreach($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $precios[$p['resumen_fila_id']][$p['cotizacion_id']] = $p;
        }
    } catch(Throwable $e) {}

    $fallback = getCompraCotizacionesFallback($compra_id);
    if (!$quotes && !empty($fallback['quotes'])) $quotes = array_values((array)$fallback['quotes']);
    if (!$resumen_filas && !empty($fallback['rows'])) $resumen_filas = array_values((array)$fallback['rows']);
    foreach ((array)($fallback['prices'] ?? []) as $rowId => $providerPrices) {
        foreach ((array)$providerPrices as $quoteId => $price) {
            if (!isset($precios[$rowId][$quoteId])) $precios[$rowId][$quoteId] = $price;
        }
    }
    if (!$resumen_filas) {
        foreach ($items_raw as $order => $item) {
            $resumen_filas[] = [
                'id' => (int)($item['id'] ?? ($order + 1)), 'orden' => $order + 1,
                'cantidad' => $item['cantidad'] ?? 0, 'presentacion' => $item['presentacion'] ?? '',
                'articulo' => $item['articulo'] ?? '', 'caracteristicas' => $item['caracteristicas'] ?? '',
            ];
        }
    }
    if (empty($compra['observacion_cotizacion']) && !empty($fallback['observacion_cotizacion'])) $compra['observacion_cotizacion'] = $fallback['observacion_cotizacion'];
    if (empty($compra['fecha_analisis_cotizacion']) && !empty($fallback['fecha_analisis_cotizacion'])) $compra['fecha_analisis_cotizacion'] = $fallback['fecha_analisis_cotizacion'];
}

$planillas = [];
$planilla_options = [];
$selected_planilla_id = 0;
if ($is_format_c) {
    try {
        $stmt_plan = $db->prepare("SELECT * FROM ah_compras_planillas WHERE compra_id = ? ORDER BY orden, id");
        $stmt_plan->execute([$compra_id]);
        $planillas = $stmt_plan->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt_pd = $db->prepare("SELECT * FROM ah_compras_planilla_detalles WHERE planilla_id = ? ORDER BY orden, id");
        foreach ($planillas as &$plan) {
            $stmt_pd->execute([$plan['id']]);
            $plan['detalles'] = $stmt_pd->fetchAll(PDO::FETCH_ASSOC);
            $firstPoa = $distribucion_poa[0] ?? [];
            $poaProgram = trim((string)($firstPoa['programa_poa'] ?? '')) ?: trim((string)($firstPoa['sector'] ?? ''));
            $poaFramework = trim((string)($firstPoa['marco_logico_poa'] ?? '')) ?: trim((string)($firstPoa['marco_logico'] ?? ''));
            if ($poaProgram !== '') $plan['programa'] = $poaProgram;
            if ($poaFramework !== '') $plan['marco_logico'] = $poaFramework;
        }
        unset($plan);
    } catch (Exception $e) {}

    $planilla_options = $planillas;
    $requested_planilla_id = isset($_GET['planilla']) ? (int)$_GET['planilla'] : 0;
    $available_planilla_ids = array_map(static fn(array $plan): int => (int)($plan['id'] ?? 0), $planilla_options);
    if ($requested_planilla_id > 0 && in_array($requested_planilla_id, $available_planilla_ids, true)) {
        $selected_planilla_id = $requested_planilla_id;
    } elseif ($available_planilla_ids) {
        $selected_planilla_id = $available_planilla_ids[0];
    }
    if ($selected_planilla_id > 0) {
        $planillas = array_values(array_filter(
            $planilla_options,
            static fn(array $plan): bool => (int)($plan['id'] ?? 0) === $selected_planilla_id
        ));
    }
}

function numeroALetras($numero) {
    if (class_exists('NumberFormatter')) {
        $formatter = new NumberFormatter('es', NumberFormatter::SPELLOUT);
        return mb_strtoupper($formatter->format($numero));
    }
    return "*** " . number_format($numero, 2) . " ***";
}

// =========================================================================
// 4. FECHAS Y TEXTOS
// =========================================================================
setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'Spanish_Spain');
$fecha_obj = time(); 

$meses = ["enero","febrero","marzo","abril","mayo","junio","julio","agosto","septiembre","octubre","noviembre","diciembre"];
$dias = ["domingo","lunes","martes","miércoles","jueves","viernes","sábado"];

$dia_semana = $dias[date('w', $fecha_obj)];
$dia_num = date('d', $fecha_obj);
$mes_nombre = $meses[date('n', $fecha_obj)-1];
$anio_actual = date('Y', $fecha_obj);
$anio_corto = date('y', $fecha_obj);
$mes_corto = substr($mes_nombre, 0, 3);

$fecha_larga = "$dia_semana $dia_num de $mes_nombre de $anio_actual";
$fecha_corta = "$dia_num-$mes_corto-$anio_corto";
$fecha_numerica = date('d/m/Y', $fecha_obj);

$expediente_code = "AH-C-" . sprintf('%04d', $compra_id);

$proveedor = !empty($compra['proveedor']) ? htmlspecialchars($compra['proveedor']) : '';
$rtn = !empty($compra['rtn']) ? htmlspecialchars($compra['rtn']) : '';
$banco = !empty($compra['banco']) ? htmlspecialchars($compra['banco']) : '';
$tipo_cuenta = !empty($compra['tipo_cuenta']) ? htmlspecialchars($compra['tipo_cuenta']) : '';
$cuenta_bancaria = !empty($compra['cuenta_bancaria']) ? htmlspecialchars($compra['cuenta_bancaria']) : '';
$tipo_transferencia = !empty($compra['tipo_transferencia']) ? htmlspecialchars($compra['tipo_transferencia']) : '';

$x_ach = ($tipo_transferencia === 'ACH') ? '<span style="color:red; font-size:15px; font-weight:bold;">X</span>' : '&nbsp;';
$x_terceros = ($tipo_transferencia === 'Transferencia a terceros') ? '<span style="color:red; font-size:15px; font-weight:bold;">X</span>' : '&nbsp;';
$x_otros = ($tipo_transferencia === 'Otros Servicios') ? '<span style="color:red; font-size:15px; font-weight:bold;">X</span>' : '&nbsp;';

$monto_total = number_format($compra['monto_total'] ?? 0, 2);
$subtotal = number_format($compra['subtotal'] ?? 0, 2);
$isv = number_format($compra['isv_total'] ?? 0, 2);
$descuento = number_format($compra['descuento_total'] ?? 0, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Expediente de Compra</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; margin: 0; padding: 0; background: #525659; color: #000; }
        
        .page {
            background: #fff; width: 21.59cm; min-height: 27.94cm; height: auto; margin: 1cm auto; padding: 1.2cm 1.5cm;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative; overflow: visible; page-break-after: always; page: portrait;
        }

        .landscape-page {
            width: 27.94cm; min-height: 21.59cm; padding: 1cm 1.5cm; page: landscape;
        }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 8px; }
        th, td { 
            border: 1px solid #000; 
            padding: 5px 4px; 
            vertical-align: middle; 
            word-wrap: break-word; 
            overflow-wrap: break-word; 
            overflow: hidden; 
        }
        
        .thick-outer { border: 2px solid #000; }
        .thick-outer th, .thick-outer td { border: 1px solid #000; }
        
        .bg-light { background-color: #f2f2f2; font-weight: bold; }
        
        .text-center { text-align: center; } 
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .text-bold { font-weight: bold; } 
        .text-top { vertical-align: top; }
        .no-wrap { white-space: nowrap; } 
        .no-border { border: none !important; }
        
        .break-all {
            word-break: break-all;
            white-space: normal;
        }

        .editable-cell { cursor: text; transition: background 0.2s; outline: none; }
        .editable-cell:hover { background: #fef08a; } 
        .editable-cell:focus { background: #fff; border: 2px solid #34859B; box-shadow: inset 0 0 5px rgba(0,0,0,0.1); }
        
        .separator-black { border-top: 5px solid #000; margin: 5px 0; }
        .header-title-main { font-size: 18px; font-weight: bold; margin: 0; }
        .header-title-sub { font-size: 20px; font-weight: bold; margin: 0; }
        .forma-a { font-size: 32px; font-weight: bold; margin: 0; }
        .logo-img { max-height: 55px; max-width: 100%; object-fit: contain; }
        .expediente-box { border: 2px solid #000; border-radius: 6px; padding: 4px; margin-top: 5px; text-align: center; font-weight: bold; font-size: 13px; }
        .planilla-print-table thead th { background: #000; color: #fff; font-style: italic; }
        .receipt-page { padding: 1cm 1.1cm; }
        .receipt-head { margin: 0 0 12px; }
        .receipt-head td { border: 0; }
        .receipt-title { font-size: 20px; font-weight: 800; letter-spacing: .2px; text-align: center; }
        .receipt-amount { font-size: 14px; font-weight: 800; margin: 15px 0 10px; }
        .receipt-rule { border-top: 6px solid #000; margin: 4px 0 0; }
        .receipt-body { margin: 0; font-size: 12px; }
        .receipt-body td { border: 0; padding: 11px 3px; }
        .receipt-body .receipt-label { width: 24%; font-weight: 800; vertical-align: top; }
        .receipt-body .receipt-value { text-align: center; vertical-align: top; }
        .receipt-body .concept-row td { height: 105px; padding-top: 45px; }
        .receipt-body .place-row td { height: 125px; padding-top: 28px; }
        .receipt-signature { margin: 0; font-size: 12px; }
        .receipt-signature td { border: 1px solid #000; padding: 7px 4px; }
        .receipt-signature .signature-label { width: 24%; font-weight: 800; }
        .receipt-signature .signature-value { text-align: center; font-weight: 800; }

        .print-controls { background: #fff; padding: 15px; text-align: center; position: sticky; top: 0; z-index: 100; border-bottom: 1px solid #ccc; }
        .print-controls form { display: inline-flex; align-items: center; gap: 8px; margin-right: 12px; }
        .print-controls label { font-weight: bold; }
        .print-controls select { min-width: 240px; padding: 9px 11px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; }
        .btn-print { background: #0f172a; color: white; border: none; padding: 10px 20px; font-size: 14px; font-weight: bold; cursor: pointer; border-radius: 4px; }
        .instruccion-edit { font-size:12px; color:#64748b; margin-top:5px; }

        @media print {
            body { background: white; }
            .print-controls { display: none; }
            .editable-cell { border: 1px solid #000 !important; background: transparent !important; box-shadow: none !important; }
            @page portrait { size: letter portrait; margin: 0.5cm; }
            @page landscape { size: letter landscape; margin: 0.5cm; }
            .page { margin: 0; padding: 0.5cm 1cm; box-shadow: none; width: 100%; min-height: 100%; height: auto; page-break-after: always; }
            table, tr, td { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

    <div class="print-controls">
        <?php if ($is_format_c && count($planilla_options) > 1): ?>
        <form method="get" action="imprimir_compra.php">
            <input type="hidden" name="id" value="<?php echo $compra_id; ?>">
            <label for="planilla-print">Planilla a incluir:</label>
            <select id="planilla-print" name="planilla" onchange="this.form.submit()">
                <?php foreach ($planilla_options as $option): ?>
                <option value="<?php echo (int)$option['id']; ?>" <?php echo (int)$option['id'] === $selected_planilla_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($option['nombre_cooperativa'] ?: ($option['titulo'] ?: 'Planilla'), ENT_QUOTES, 'UTF-8'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir Expediente</button>
        <div class="instruccion-edit">💡 Tip: Puedes dar clic y editar <strong>CUALQUIER FECHA</strong> en el documento. Al cambiar las fechas de firmas en Transferencia, se actualizarán en Autorización automáticamente.</div>
    </div>

    <!-- ========================================== -->
    <!-- 1. SOLICITUD DE COMPRA                     -->
    <!-- ========================================== -->
    <?php if (!$is_format_c): ?>
    <div class="page">
        <table class="no-border" style="margin-bottom:2px;">
            <tr>
                <td class="no-border text-left" style="width:25%; vertical-align:bottom;"><div class="forma-a">Forma <?php echo htmlspecialchars($compra['formato_compra'] ?? 'A'); ?></div></td>
                <td class="no-border text-center" style="width:50%;">
                    <div class="header-title-main">ACCION HONDURAS</div>
                    <div class="header-title-sub">Solicitud de Compra-AF-2027</div>
                </td>
                <td class="no-border text-right" style="width:25%; vertical-align:bottom;">
                    <img src="../uploads/images/logo.png" alt="Acción Honduras" class="logo-img">
                    <div class="expediente-box"><?php echo $expediente_code; ?></div>
                </td>
            </tr>
        </table>
        
        <div class="separator-black"></div>

        <table class="thick-outer">
            <colgroup>
                <col style="width: 14%;">
                <col style="width: 24%;">
                <col style="width: 12%;">
                <col style="width: 16%;">
                <col style="width: 14%;">
                <col style="width: 5%;">
                <col style="width: 15%;">
            </colgroup>
            <tr>
                <td class="bg-light text-center text-bold">Fecha</td>
                <td class="text-center text-bold editable-cell" contenteditable="true" title="Modificar Fecha"><?php echo $fecha_larga; ?></td>
                <td colspan="5" class="bg-light text-center text-bold" style="font-size:13px;">Información del POA</td>
            </tr>
            
            <?php 
            $num_poa_p1 = max(3, count($distribucion_poa));
            $rowspan_solicitante = $num_poa_p1 + 1; 
            ?>

            <tr>
                <td rowspan="<?php echo $rowspan_solicitante; ?>" class="bg-light text-center text-bold text-top" style="padding-top:10px;">Nombre del<br>solicitante</td>
                <td rowspan="<?php echo $rowspan_solicitante; ?>" class="text-center text-top" style="padding:10px;"><?php echo htmlspecialchars($compra['solicitante']); ?></td>
                <td class="bg-light text-center text-bold">Sector</td>
                <td class="bg-light text-center text-bold">Sub Sector</td>
                <td class="bg-light text-center text-bold">Marco<br>Lógico</td>
                <td class="bg-light text-center text-bold">Ext</td>
                <td class="bg-light text-center text-bold">Pto.<br>Disponible</td>
            </tr>
            
            <?php 
            for($k = 0; $k < $num_poa_p1; $k++): 
                if(isset($distribucion_poa[$k])): 
                    $dp = $distribucion_poa[$k]; 
                    $pto_anual = isset($dp['presupuesto_anual']) ? (float)$dp['presupuesto_anual'] : 0;
                    $ejecutado = isset($dp['ejecutado']) ? (float)$dp['ejecutado'] : 0;
                    $pto_disp = $pto_anual - $ejecutado;
                    $pto_display = number_format($pto_disp, 2);

                    $ml_parts = preg_split('/[\s\x{00A0}]+/u', trim($dp['marco_logico'] ?? ''));
                    $ml_corto = $ml_parts[0] ?? '';
                    if(strlen($ml_corto) > 15) { $ml_corto = substr($ml_corto, 0, 15); }
            ?>
                <tr>
                    <td class="text-center break-all" style="font-size:8.5px;"><?php echo htmlspecialchars($dp['sector'] ?? ''); ?></td>
                    <td class="text-center break-all" style="font-size:8.5px;"><?php echo htmlspecialchars($dp['sub_sector'] ?? ''); ?></td>
                    <td class="text-center text-bold break-all" style="font-size:9px;"><?php echo htmlspecialchars($ml_corto); ?></td>
                    <td class="text-center text-bold"><?php echo htmlspecialchars($dp['ext'] ?? ''); ?></td>
                    <td class="text-right text-bold editable-cell no-wrap" style="padding-right: 8px;" contenteditable="true"><?php echo $pto_display; ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td style="height:15px;"></td>
                    <td></td>
                    <td></td>
                    <td class="text-center text-bold">0</td>
                    <td class="editable-cell text-right" contenteditable="true"></td>
                </tr>
                <?php endif; ?>
            <?php endfor; ?>
            
            <tr>
                <td rowspan="4" class="bg-light text-center text-bold text-top" style="padding-top:10px;">Descripción<br>de la actividad</td>
                <td rowspan="4" class="text-top" style="padding:10px; text-align:justify;"><?php echo htmlspecialchars($compra['descripcion_actividad']); ?></td>
                <td style="height:15px;"></td><td></td><td></td><td></td><td class="editable-cell" contenteditable="true"></td>
            </tr>
            <tr><td style="height:15px;"></td><td></td><td></td><td></td><td class="editable-cell" contenteditable="true"></td></tr>
            <tr><td style="height:15px;"></td><td></td><td></td><td></td><td class="editable-cell" contenteditable="true"></td></tr>
            <tr><td style="height:15px;"></td><td></td><td></td><td></td><td class="editable-cell" contenteditable="true"></td></tr>
        </table>

        <div class="separator-black"></div>

        <table class="thick-outer">
            <colgroup>
                <col style="width: 4%;">
                <col style="width: 7%;">
                <col style="width: 12%;">
                <col style="width: 38%;">
                <col style="width: 39%;">
            </colgroup>
            <tr>
                <td rowspan="2" class="bg-light text-center text-bold">#</td>
                <td rowspan="2" class="bg-light text-center text-bold">Cantidad</td>
                <td rowspan="2" class="bg-light text-center text-bold">Presentación</td>
                <td colspan="2" class="bg-light text-center text-bold">Descripción de la compra</td>
            </tr>
            <tr>
                <td class="bg-light text-center text-bold">Articulo / Insumo</td>
                <td class="bg-light text-center text-bold">Características requeridas</td>
            </tr>
            
            <?php $i=1; foreach($items as $it): ?>
            <tr>
                <td class="text-center" style="height:22px;"><?php echo !empty($it['cantidad']) ? $i++ : '&nbsp;'; ?></td>
                <td class="text-center text-bold"><?php echo htmlspecialchars($it['cantidad']); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($it['presentacion']); ?></td>
                <td style="padding-left:10px;"><?php echo htmlspecialchars($it['articulo']); ?></td>
                <td style="padding-left:10px;"><?php echo htmlspecialchars($it['caracteristicas']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <!-- Firmas Solicitud (Líneas limpias) -->
                <table class="thick-outer" style="margin-top: 10px;">
            <tr>
                <td class="bg-light text-bold text-top" style="width:16%; padding:15px 5px;">Solicitado por:</td>
                <td style="width:64%; padding:15px 5px;"><?php echo htmlspecialchars($compra['solicitante']); ?></td>
                <td class="bg-light text-top text-bold" style="width:20%; padding:15px 5px;">Firma:</td>
            </tr>
            <tr>
                <td class="bg-light text-bold" style="padding:10px 5px;">Firma y fecha:</td>
                <td colspan="2" style="padding:10px 5px;" class="editable-cell" contenteditable="true" title="Modificar Fecha"><?php echo $fecha_corta; ?></td>
            </tr>
        </table>
        <table class="thick-outer" style="margin-top: 10px;">
            <tr>
                <td class="bg-light text-bold text-top" style="width:16%; padding:15px 5px;">Visto Bueno:</td>
                <td style="width:64%; padding:15px 5px;"><?php echo htmlspecialchars($compra['visto_bueno'] ?? 'Edwing Armando Lopez - Coordinador de Programas'); ?></td>
                <td class="bg-light text-top text-bold" style="width:20%; padding:15px 5px;">Firma:</td>
            </tr>
            <tr>
                <td class="bg-light text-bold" style="padding:10px 5px;">Firma y fecha:</td>
                <td colspan="2" style="padding:10px 5px;" class="editable-cell" contenteditable="true" title="Modificar Fecha"><?php echo $fecha_corta; ?></td>
            </tr>
        </table>
    </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- 1.5 FORMATO B: RESUMEN DE COTIZACION       -->
    <!-- ========================================== -->
    <?php if ($is_format_b): ?>
    <div class="page landscape-page">
        <table class="no-border" style="margin-bottom:2px;">
            <tr>
                <td class="no-border text-left" style="width:25%; vertical-align:bottom;"><div class="forma-a">Forma B</div></td>
                <td class="no-border text-center" style="width:50%;">
                    <div class="header-title-main">ACCION HONDURAS</div>
                    <div class="header-title-sub">Resumen de Cotización-AF-2027</div>
                </td>
                <td class="no-border text-right" style="width:25%; vertical-align:bottom;">
                    <img src="../uploads/images/logo.png" alt="Acción Honduras" class="logo-img">
                    <div class="expediente-box"><?php echo $expediente_code; ?></div>
                </td>
            </tr>
        </table>
        
        <div class="separator-black"></div>

        <table class="thick-outer" style="font-size: 10px; margin-top: 5px;">
            <thead>
                <tr class="bg-light text-center">
                    <th rowspan="2" style="width: 2%;">#</th>
                    <th rowspan="2" style="width: 5%;">Cantidad</th>
                    <th rowspan="2" style="width: 7%;">Presentación</th>
                    <th colspan="2">Descripción de la compra</th>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <th colspan="3">Proveedor <?php echo $p; ?><br><?php echo htmlspecialchars($quotes[$p-1]['proveedor'] ?? '---'); ?></th>
                    <?php endfor; ?>
                </tr>
                <tr class="bg-light text-center">
                    <th style="width: 14%;">Artículo / Insumo</th>
                    <th style="width: 14%;">Características requeridas</th>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <th style="width: 6%;">Valor unitario</th>
                        <th style="width: 6%;">Valor Total</th>
                        <th style="width: 2%;">G/E</th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach($resumen_filas as $idx => $fila): ?>
                <tr>
                    <td class="text-center"><?php echo $idx + 1; ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($fila['cantidad']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($fila['presentacion']); ?></td>
                    <td style="padding-left: 5px;"><?php echo htmlspecialchars($fila['articulo']); ?></td>
                    <td style="padding-left: 5px;"><?php echo htmlspecialchars($fila['caracteristicas']); ?></td>
                    <?php for($p=1; $p<=3; $p++): 
                        $quoteId = $quotes[$p-1]['id'] ?? $p; 
                        $pr = $precios[$fila['id']][$quoteId] ?? $precios[$fila['id']][$p] ?? null;
                    ?>
                        <td class="text-right" style="padding-right: 8px;"><?php echo $pr ? number_format((float)$pr['precio_unitario'], 2) : '-'; ?></td>
                        <td class="text-right" style="padding-right: 8px;"><?php echo $pr ? number_format((float)$pr['precio_total'], 2) : '-'; ?></td>
                        <td class="text-center"><?php echo $pr ? htmlspecialchars($pr['tipo_impuesto']) : 'E'; ?></td>
                    <?php endfor; ?>
                </tr>
                <?php endforeach; ?>
                
                <tr>
                    <td colspan="5" class="bg-light text-center text-bold">Ultima línea</td>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <td colspan="3" class="bg-light text-center"></td>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <td colspan="5" class="bg-light text-right text-bold" style="padding-right: 8px;">Subtotal</td>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <td colspan="2" class="text-right text-bold" style="padding-right: 8px;">L. <?php echo number_format((float)($quotes[$p-1]['subtotal'] ?? 0), 2); ?></td>
                        <td class="bg-light text-center">-</td>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <td colspan="5" class="bg-light text-right text-bold" style="padding-right: 8px;">Gravado (15%)</td>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <td colspan="2" class="text-right text-bold" style="padding-right: 8px;">L. <?php echo number_format((float)(($quotes[$p-1]['subtotal'] ?? 0) * 0.15), 2); ?></td>
                        <td class="bg-light text-center">-</td>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <td colspan="5" class="bg-light text-right text-bold" style="padding-right: 8px;">Descuento</td>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <td colspan="2" class="text-right text-bold" style="padding-right: 8px;">L. <?php echo number_format((float)($quotes[$p-1]['descuento'] ?? 0), 2); ?></td>
                        <td class="bg-light text-center">-</td>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <td colspan="5" class="bg-light text-right text-bold" style="padding-right: 8px;">Mas 15% ISV</td>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <td colspan="2" class="text-right text-bold" style="padding-right: 8px;">L. <?php echo number_format((float)($quotes[$p-1]['isv'] ?? 0), 2); ?></td>
                        <td class="bg-light text-center">-</td>
                    <?php endfor; ?>
                </tr>
                <tr>
                    <td colspan="5" class="bg-light text-right text-bold" style="padding-right: 8px;">Total General</td>
                    <?php for($p=1; $p<=3; $p++): ?>
                        <td colspan="2" class="text-right text-bold" style="padding-right: 8px;">L. <?php echo number_format((float)($quotes[$p-1]['total'] ?? 0), 2); ?></td>
                        <td class="bg-light text-center">-</td>
                    <?php endfor; ?>
                </tr>
            </tbody>
        </table>

        <table class="thick-outer" style="margin-top: 10px;">
            <tr>
                <td class="bg-light text-bold text-center" style="width: 15%; padding: 10px;">Observaciones:</td>
                <td style="width: 65%; padding: 10px; text-align: justify;"><?php echo nl2br(htmlspecialchars($compra['observacion_cotizacion'] ?? '')); ?></td>
                <td class="bg-light text-center" style="width: 20%;">
                    <?php echo htmlspecialchars($compra['fecha_analisis_cotizacion'] ?? ''); ?><br><br>
                    <strong>Fecha de análisis</strong>
                </td>
            </tr>
        </table>

        <!-- Firmas Forma B (Líneas limpias) -->
        <table class="text-center" style="margin-top: 80px; width: 100%; border-collapse: collapse;">
    <tr>
        <td style="width: 30%; padding: 0 10px; border: none !important;">
            <div style="border-top: 1px solid #000; padding-top: 5px; font-weight: bold;">
                <?php echo htmlspecialchars(
                    $compra['revisado_por'] ?? 'Eduardo Antonio Hernández'
                ); ?>
                <br>
                Revisado por Unidad de Administración y RRHH
            </div>
        </td>

        <td style="width: 5%; border: none !important;"></td>

        <td style="width: 30%; padding: 0 10px; border: none !important;">
            <div style="border-top: 1px solid #000; padding-top: 5px; font-weight: bold;">
                <?php echo htmlspecialchars(
                    $compra['visto_bueno'] ?? 'Edwing Armando Lopez'
                ); ?>
                <br>
                VoBo. Coordinación
            </div>
        </td>

        <td style="width: 5%; border: none !important;"></td>

        <td style="width: 30%; padding: 0 10px; border: none !important;">
            <div style="border-top: 1px solid #000; padding-top: 5px; font-weight: bold;">
                <?php echo htmlspecialchars(
                    $compra['firma_autoriza'] ?? 'José Orlando Osorto Pérez'
                ); ?>
                <br>
                Aprobado por Dirección Ejecutiva / Junta Directiva
            </div>
        </td>
    </tr>
</table>
    </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- 2. ORDEN DE COMPRA                         -->
    <!-- ========================================== -->
    <?php if (!$is_format_c): ?>
    <div class="page">
        <table class="no-border" style="margin-bottom:2px;">
            <tr>
                <td class="no-border text-left" style="width:25%; vertical-align:bottom;"><div class="forma-a">Forma <?php echo htmlspecialchars($compra['formato_compra'] ?? 'A'); ?></div></td>
                <td class="no-border text-center" style="width:50%;">
                    <div class="header-title-main">ACCION HONDURAS</div>
                    <div class="header-title-sub">Orden de Compra - AF-2027</div>
                </td>
                <td class="no-border text-right" style="width:25%; vertical-align:bottom;">
                    <img src="../uploads/images/logo.png" alt="Acción Honduras" class="logo-img">
                    <div class="expediente-box"><?php echo $expediente_code; ?></div>
                </td>
            </tr>
        </table>
        
        <div class="separator-black"></div>

        <table class="thick-outer">
            <colgroup>
                <col style="width: 15%;">
                <col style="width: 20%;">
                <col style="width: 12%;">
                <col style="width: 13%;">
                <col style="width: 10%;">
                <col style="width: 4%;">
                <col style="width: 6%;">
                <col style="width: 7%;">
                <col style="width: 13%;">
            </colgroup>
            <tr>
                <td class="bg-light text-bold text-center">Fecha de la orden</td>
                <td class="text-center no-wrap editable-cell" contenteditable="true" title="Modificar Fecha de Orden"><?php echo $fecha_corta; ?></td>
                <td class="bg-light text-center text-bold">Sector</td>
                <td class="bg-light text-center text-bold">Sub Sector</td>
                <td class="bg-light text-center text-bold">Marco</td>
                <td class="bg-light text-center text-bold">Ext.</td>
                <td class="bg-light text-center text-bold">F.F</td>
                <td class="bg-light text-center text-bold">Cuenta</td>
                <td class="bg-light text-center text-bold">Monto</td>
            </tr>
            
            <?php 
            $num_poa_p2 = max(4, count($distribucion_poa));
            for($k = 0; $k < $num_poa_p2; $k++): 
            ?>
                <tr>
                    <?php if($k == 0): ?>
                        <td colspan="2" class="bg-light text-center text-bold">Dirección de entrega</td>
                    <?php elseif($k == 1): ?>
                        <td colspan="2" rowspan="<?php echo $num_poa_p2 - 1; ?>" class="text-top" style="padding:6px;">Oficina: Colonia Ricardo Maduro, a 2 km del desvío a Mulhuaca, Lepaterique. Francisco Morazán. Tel. 3385-6384 / 3197-5432</td>
                    <?php endif; ?>
                    
                    <?php 
                    if(isset($distribucion_poa[$k])): 
                        $dp = $distribucion_poa[$k]; 
                        
                        $ml_parts = preg_split('/[\s\x{00A0}]+/u', trim($dp['marco_logico'] ?? ''));
                        $ml_corto = $ml_parts[0] ?? '';
                        if(strlen($ml_corto) > 15) { $ml_corto = substr($ml_corto, 0, 15); }

                        $ff_corto = trim(explode('-', $dp['fuente_financiamiento'] ?? '')[0]);
                        $cta_solo_num = trim(explode('-', $dp['cuenta_contable'] ?? '')[0]); 
                    ?>
                        <td class="text-center break-all" style="font-size:8.5px;"><?php echo htmlspecialchars($dp['sector'] ?? ''); ?></td>
                        <td class="text-center break-all" style="font-size:8.5px;"><?php echo htmlspecialchars($dp['sub_sector'] ?? ''); ?></td>
                        <td class="text-center text-bold break-all" style="font-size:9px;"><?php echo htmlspecialchars($ml_corto); ?></td>
                        <td class="text-center text-bold no-wrap"><?php echo htmlspecialchars($dp['ext'] ?? ''); ?></td>
                        <td class="text-center text-bold no-wrap"><?php echo htmlspecialchars($ff_corto); ?></td>
                        <td class="text-center text-bold no-wrap"><?php echo htmlspecialchars($cta_solo_num); ?></td>
                        <td class="text-right text-bold no-wrap" style="padding-right: 8px;"><?php echo isset($dp['monto']) ? 'L. ' . number_format($dp['monto'], 2) : ''; ?></td>
                    <?php else: ?>
                        <td style="height:16px;"></td>
                        <td></td>
                        <td></td>
                        <td class="text-center text-bold">0</td>
                        <td></td>
                        <td></td>
                        <td></td>
                    <?php endif; ?>
                </tr>
            <?php endfor; ?>
            <tr>
                <td class="bg-light text-bold">Entregar a:</td>
                <td class="text-center">Eduardo Antonio Hernández - Oficial Administrativo</td>
                <td></td><td></td><td></td><td class="text-center text-bold">0</td><td></td><td></td><td></td>
            </tr>
            <tr>
                <td rowspan="2" class="bg-light text-bold">Proveedor:</td>
                <td rowspan="2" class="text-center text-bold"><?php echo $proveedor; ?></td>
                <td></td><td></td><td></td><td class="text-center text-bold">0</td><td></td><td></td><td></td>
            </tr>
            <tr><td style="height:16px;"></td><td></td><td></td><td class="text-center text-bold">0</td><td></td><td></td><td></td></tr>
            <tr>
                <td class="bg-light text-bold">Identidad /RTN:</td>
                <td class="text-center text-bold no-wrap"><?php echo $rtn; ?></td>
                <td></td><td></td><td></td><td class="text-center text-bold">0</td><td></td><td></td><td></td>
            </tr>
            <tr>
                <td class="bg-light text-bold">Tipo y # Cta<br>Bancaria:</td>
                <td class="text-center"><?php echo "$banco - $tipo_cuenta - $cuenta_bancaria"; ?></td>
                <td colspan="6" class="bg-light text-center text-bold">Total</td>
                <td class="text-right text-bold no-wrap" style="padding-right: 8px;">L. <?php echo $monto_total; ?></td>
            </tr>
        </table>

        <table class="thick-outer" style="margin-top: 5px;">
            <colgroup>
                <col style="width: 4%;">
                <col style="width: 7%;">
                <col style="width: 12%;">
                <col style="width: 24%;">
                <col style="width: 21%;">
                <col style="width: 5%;">
                <col style="width: 13%;">
                <col style="width: 14%;">
            </colgroup>
            <tr>
                <td rowspan="2" class="bg-light text-center text-bold">#</td>
                <td rowspan="2" class="bg-light text-center text-bold">Cant.</td>
                <td rowspan="2" class="bg-light text-center text-bold">Presentación</td>
                <td colspan="2" class="bg-light text-center text-bold">Descripción de la compra</td>
                <td rowspan="2" class="bg-light text-center text-bold">G/E</td>
                <td rowspan="2" class="bg-light text-center text-bold">Precio<br>Unitario.</td>
                <td rowspan="2" class="bg-light text-center text-bold">Total</td>
            </tr>
            <tr>
                <td class="bg-light text-center text-bold">Articulo / Insumo</td>
                <td class="bg-light text-center text-bold">Características requeridas</td>
            </tr>
            
            <?php $i=1; foreach($items as $it): ?>
            <tr>
                <td class="text-center" style="height:22px;"><?php echo !empty($it['cantidad']) ? $i++ : '&nbsp;'; ?></td>
                <td class="text-center text-bold"><?php echo htmlspecialchars($it['cantidad']); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($it['presentacion']); ?></td>
                <td style="padding-left:8px;"><?php echo htmlspecialchars($it['articulo']); ?></td>
                <td style="padding-left:8px;"><?php echo htmlspecialchars($it['caracteristicas']); ?></td>
                <td class="text-center text-bold"><?php echo !empty($it['cantidad']) ? htmlspecialchars($it['tipo_impuesto'] ?? 'G') : ''; ?></td>
                <td class="text-right no-wrap" style="padding-right: 8px;"><?php echo !empty($it['precio_unitario']) ? 'L. ' . number_format($it['precio_unitario'], 2) : ''; ?></td>
                <td class="text-right text-bold no-wrap" style="padding-right: 8px;"><?php echo !empty($it['precio_total']) ? 'L. ' . number_format($it['precio_total'], 2) : ''; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="8" class="bg-light text-center text-bold" style="padding:6px;">Ultima línea</td>
            </tr>
        </table>

        <table class="no-border" style="margin-top:5px; width:100%;">
            <tr>
                <td class="no-border" style="width:65%; vertical-align:top; padding-top:10px;">
                    <div style="margin-bottom:15px;">
                        Hay fondos disponibles en presupuesto? &nbsp;&nbsp;&nbsp;&nbsp;
                        <span style="font-size:16px;">☑</span> Si &nbsp;&nbsp;&nbsp; <span style="font-size:16px;">☐</span> No
                    </div>
                    <div>
                        Análisis de cotizacion completo y adjunto? &nbsp;&nbsp;
                        <?php if($is_format_b): ?>
                            <span style="font-size:16px;">☑</span> Si &nbsp;&nbsp;&nbsp; <span style="font-size:16px;">☐</span> No
                        <?php else: ?>
                            <span style="font-size:16px;">☐</span> Si &nbsp;&nbsp;&nbsp; <span style="font-size:16px;">☑</span> No (N/A)
                        <?php endif; ?>
                    </div>
                </td>
                <td class="no-border" style="width:35%;">
                    <table class="thick-outer">
                        <tr>
                            <td class="bg-light text-bold" style="width:45%; padding:4px 6px;">Sub Total</td>
                            <td class="text-right text-bold no-wrap" style="padding-right: 8px;">L. <?php echo $subtotal; ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light text-bold" style="padding:4px 6px;">Descuento</td>
                            <td class="text-right text-bold no-wrap" style="padding-right: 8px;">L. <?php echo $descuento; ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light text-bold" style="padding:4px 6px;">15% ISV</td>
                            <td class="text-right text-bold no-wrap" style="padding-right: 8px;">L. <?php echo $isv; ?></td>
                        </tr>
                        <tr>
                            <td class="bg-light text-bold" style="padding:4px 6px;">Total Lps</td>
                            <td class="text-right text-bold no-wrap" style="padding-right: 8px;">L. <?php echo $monto_total; ?></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Firmas Orden (Línea limpia) -->
        <table class="no-border" style="margin-top:15px; width:100%;">
            <tr>
                <td class="no-border" style="width:35%; font-size:10px; vertical-align:bottom;">
                    ESTA ORDEN DE COMPRA ES VALIDA<br>
                    UNICAMENTE SI LLEVA LA FIRMA DE<br>
                    LA PERSONA AUTORIZADA<br><br><br>
                    Medio de Transporte : Terrestre
                </td>
                <td class="no-border text-right text-bold" style="width:25%; vertical-align:bottom; padding-bottom:5px;">
                    Aprobado Por: &nbsp;
                </td>
                <td class="no-border text-center" style="width:40%; vertical-align:bottom;">
                    <?php echo htmlspecialchars($compra['firma_autoriza'] ?? 'José Orlando Osorto Pérez - Director Ejecutivo'); ?><br>
                    <div style="border-bottom:1px dotted #000; width:100%; margin-top:5px;"></div>
                </td>
            </tr>
        </table>

    </div>
    <?php endif; ?>

    <!-- ========================================== -->
    <!-- 3. SOLICITUD DE TRANSFERENCIA              -->
    <!-- ========================================== -->
    <div class="page">
        <table class="no-border" style="margin-bottom:2px;">
            <tr>
                <td class="no-border text-left" style="width:25%; vertical-align:bottom;"><div class="forma-a">Forma <?php echo htmlspecialchars($compra['formato_compra'] ?? 'A'); ?></div></td>
                <td class="no-border text-center" style="width:50%;">
                    <div class="header-title-main">ACCION HONDURAS</div>
                    <div class="header-title-sub">Solicitud de Transferencia - AF-2027</div>
                </td>
                <td class="no-border text-right" style="width:25%; vertical-align:bottom;">
                    <img src="../uploads/images/logo.png" alt="Acción Honduras" class="logo-img">
                    <div class="expediente-box"><?php echo $expediente_code; ?></div>
                </td>
            </tr>
        </table>
        
        <div class="separator-black"></div>

        <table class="thick-outer">
            <tr>
                <td class="bg-light text-bold" style="width:22%; padding:10px;">Solicitado por:</td>
                <td class="text-center" style="width:58%; padding:10px;"><?php echo htmlspecialchars($compra['solicitante']); ?></td>
                <td rowspan="4" class="text-center text-bold border-left-thick no-wrap" style="width:20%; font-size:18px;">
                    L &nbsp;&nbsp;&nbsp;&nbsp; <?php echo $monto_total; ?>
                </td>
            </tr>
            <tr>
                <td class="bg-light text-bold" style="padding:10px;">Emitir<br>transferencia /<br>cheque a nombre<br>de:</td>
                <td class="text-center text-bold" style="font-size:14px; padding:10px;"><?php echo $proveedor; ?></td>
            </tr>
            <tr>
                <td rowspan="2" class="bg-light text-bold" style="padding:10px;">ID o RTN / Cta.<br>Bancaria</td>
                <td class="text-center text-bold" style="padding:8px;"><?php echo "$banco- $tipo_cuenta- $cuenta_bancaria"; ?></td>
            </tr>
            <tr>
                <td class="text-center text-bold no-wrap" style="padding:8px;"><?php echo $rtn; ?></td>
            </tr>
        </table>

        <table class="thick-outer" style="margin-top: 5px;">
            <tr>
                <td class="bg-light text-bold" style="width:22%; padding:20px 10px;">Razón de la<br>solicitud</td>
                <td style="padding:15px 10px; text-align:justify;"><?php echo htmlspecialchars($compra['descripcion_actividad']); ?></td>
            </tr>
        </table>

        <table class="thick-outer" style="margin-top: 5px;">
            <colgroup>
                <col style="width: 18%;">
                <col style="width: 17%;">
                <col style="width: 12%;">
                <col style="width: 5%;">
                <col style="width: 30%;">
                <col style="width: 18%;">
            </colgroup>
            <tr>
                <td class="bg-light text-center text-bold" style="padding:8px;">SECTOR</td>
                <td class="bg-light text-center text-bold" style="padding:8px;">SUB SECTOR</td>
                <td class="bg-light text-center text-bold" style="padding:8px;">Marco<br>Lógico</td>
                <td class="bg-light text-center text-bold" style="padding:8px;">EXT</td>
                <td class="bg-light text-center text-bold" style="padding:8px;">Cuenta</td>
                <td class="bg-light text-center text-bold" style="padding:8px;">Valor</td>
            </tr>
            
            <?php 
            $count3 = 0;
            foreach($distribucion_poa as $dp): 
                $count3++;
                $ml_parts = preg_split('/[\s\x{00A0}]+/u', trim($dp['marco_logico'] ?? ''));
                $ml_corto = $ml_parts[0] ?? '';
                if(strlen($ml_corto) > 15) { $ml_corto = substr($ml_corto, 0, 15); }
                
                $cta_corta = explode(' - ', $dp['cuenta_contable'] ?? '')[0];
            ?>
            <tr>
                <td class="text-center break-all" style="font-size:8.5px;"><?php echo htmlspecialchars($dp['sector'] ?? ''); ?></td>
                <td class="text-center break-all" style="font-size:8.5px;"><?php echo htmlspecialchars($dp['sub_sector'] ?? ''); ?></td>
                <td class="text-center text-bold break-all" style="font-size:9px;"><?php echo htmlspecialchars($ml_corto); ?></td>
                <td class="text-center text-bold no-wrap"><?php echo htmlspecialchars($dp['ext'] ?? ''); ?></td>
                <td class="text-center break-all" style="font-size:10px;"><?php echo htmlspecialchars($cta_corta); ?></td>
                <td class="text-right no-wrap text-bold" style="padding-right: 8px;"><?php echo number_format($dp['monto'] ?? 0, 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php for($j = $count3; $j < 5; $j++): ?>
            <tr>
                <td style="height:20px;"></td>
                <td></td>
                <td></td>
                <td class="text-center text-bold">0</td>
                <td></td>
                <td></td>
            </tr>
            <?php endfor; ?>
            
            <tr>
                <td colspan="5" class="bg-light text-center text-bold" style="padding:8px;">Total</td>
                <td class="text-right text-bold no-wrap" style="padding-right: 8px;">L. &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <?php echo $monto_total; ?></td>
            </tr>
        </table>

        <table class="no-border text-bold" style="margin-top:10px; width:100%;">
            <tr>
                <td class="no-border" style="width:25%;">Lepaterique F.M.</td>
                <td class="no-border editable-cell" contenteditable="true"><?php echo $fecha_corta; ?></td>
            </tr>
        </table>

        <!-- Firmas Transferencia (Líneas limpias) -->
        <table class="thick-outer" style="margin-top: 15px;">
            <tr>
                <td class="bg-light text-bold" style="width:25%; padding:15px 10px;">Firma de Solicitante</td>
                <td style="width:60%; padding:15px 10px;"><?php echo htmlspecialchars($compra['solicitante']); ?></td>
                <td class="bg-light text-bold" style="width:15%; padding:15px 10px; border-left:none;">Firma:</td>
            </tr>
            <tr>
                <td class="bg-light text-bold" style="padding:15px 10px;">Firma Autorizada 1</td>
                <td style="padding:15px 10px;"><?php echo htmlspecialchars($compra['visto_bueno'] ?? 'Edwing Armando Lopez - Coordinador de Programas'); ?></td>
                <td class="bg-light text-bold" style="padding:15px 10px; border-left:none;">Firma:</td>
            </tr>
        </table>
    </div>

    <!-- ========================================== -->
    <!-- 4. AUTORIZACION DE TRANSFERENCIA           -->
    <!-- ========================================== -->
    <div class="page">
        <table class="no-border" style="margin-bottom:2px;">
            <tr>
                <td class="no-border text-left" style="width:25%; vertical-align:bottom;"><div class="forma-a">Forma <?php echo htmlspecialchars($compra['formato_compra'] ?? 'A'); ?></div></td>
                <td class="no-border text-center" style="width:50%;">
                    <div class="header-title-main">ACCION HONDURAS</div>
                    <div class="header-title-sub">Autorizacion para pago de<br>Transferencia Electronica AF-2027</div>
                </td>
                <td class="no-border text-right" style="width:25%; vertical-align:bottom;">
                    <img src="../uploads/images/logo.png" alt="Acción Honduras" class="logo-img">
                    <div class="expediente-box"><?php echo $expediente_code; ?></div>
                </td>
            </tr>
        </table>
        
        <div class="separator-black"></div>

        <table class="thick-outer">
            <tr><td colspan="4" class="bg-light text-center text-bold" style="font-size:13px; padding:6px;">Información del Banco</td></tr>
            <tr>
                <td class="text-bold" style="width:25%; padding:6px 10px;">Nombre del Banco</td>
                <td colspan="3" class="text-center text-bold"><?php echo $banco; ?></td>
            </tr>
            <tr>
                <td class="text-bold" style="padding:6px 10px;">Numero de Cuenta</td>
                <td colspan="3" class="text-center text-bold no-wrap" style="font-size:16px;"><?php echo $cuenta_bancaria; ?></td>
            </tr>
            <tr>
                <td class="text-bold" style="padding:6px 10px;">Tipo de Cuenta</td>
                <td colspan="3" class="text-center text-bold"><?php echo $tipo_cuenta; ?></td>
            </tr>
            <tr>
                <td class="text-bold" style="padding:6px 10px;">Nombre Beneficiario</td>
                <td colspan="3" class="text-center text-bold" style="font-size:13px;"><?php echo $proveedor; ?></td>
            </tr>
            <tr>
                <td class="text-bold" style="padding:6px 10px;">Tipo de Transferencia</td>
                <td class="text-center" style="width:25%;">ACH &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <?php echo $x_ach; ?></td>
                <td class="text-center" style="width:25%;">Transferencia a terceros &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <?php echo $x_terceros; ?></td>
                <td class="text-center" style="width:25%;">Otros Servicios &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <?php echo $x_otros; ?></td>
            </tr>
            <tr>
                <td class="text-bold" style="padding:10px 10px;">N° Transferencia/ Fecha</td>
                <td colspan="3"></td>
            </tr>
        </table>

        <table class="thick-outer" style="margin-top: 15px;">
            <tr><td colspan="2" class="bg-light text-center text-bold" style="font-size:13px; padding:6px;">Información del Proveedor</td></tr>
            <tr>
                <td class="text-bold" style="width:25%; padding:6px 10px;">Nombre del Proveedor</td>
                <td class="text-center text-bold" style="font-size:12px;"><?php echo $proveedor; ?></td>
            </tr>
            <tr>
                <td class="text-bold" style="padding:6px 10px;"># de Identidad / RTN</td>
                <td class="text-center no-wrap text-bold"><?php echo $rtn; ?></td>
            </tr>
            <tr>
                <td class="text-bold" style="padding:6px 10px;">Domicilio</td>
                <td class="text-center">Tegucigalpa, M.D.C</td>
            </tr>
        </table>

        <table class="thick-outer" style="margin-top: 15px;">
            <colgroup>
                <col style="width: 50%;">
                <col style="width: 30%;">
                <col style="width: 20%;">
            </colgroup>
            <tr>
                <td class="bg-light text-center text-bold" style="padding:8px;">Descripción de la actividad</td>
                <td class="bg-light text-center text-bold" style="padding:8px;">Fuente de<br>Financiamiento</td>
                <td class="bg-light text-center text-bold" style="padding:8px;">Total Lps</td>
            </tr>
            
            <?php 
            $num_filas_fuente = max(9, count($distribucion_poa));
            for($j = 0; $j < $num_filas_fuente; $j++): 
            ?>
            <tr>
                <?php if($j == 0): ?>
                <td rowspan="<?php echo $num_filas_fuente; ?>" style="padding:15px; text-align:justify; vertical-align:top;">
                    <?php echo htmlspecialchars($compra['descripcion_actividad']); ?>
                </td>
                <?php endif; ?>
                
                <?php if(isset($distribucion_poa[$j])): ?>
                    <td class="text-center text-bold break-all" style="font-size:10px;"><?php echo htmlspecialchars($distribucion_poa[$j]['fuente_financiamiento'] ?? ''); ?></td>
                    <td class="text-right no-wrap text-bold" style="padding-right: 8px;"><?php echo number_format($distribucion_poa[$j]['monto'], 2); ?></td>
                <?php else: ?>
                    <td class="text-center text-bold" style="font-size:10px;"></td>
                    <td class="text-right no-wrap text-bold"></td>
                <?php endif; ?>
            </tr>
            <?php endfor; ?>
            <tr>
                <td colspan="2" class="bg-light text-center text-bold" style="padding:8px;">Total de Transferencia</td>
                <td class="text-right text-bold no-wrap" style="padding-right: 8px;"><?php echo $monto_total; ?></td>
            </tr>
        </table>

        <!-- Firmas Autorizacion (Cuadrícula limpia) -->
        <table class="thick-outer" style="margin-top: 15px;">
            <tr>
                <td class="bg-light text-center text-bold" style="width:20%; padding:8px;">Nombre</td>
                <td class="bg-light text-center text-bold" style="width:40%; padding:8px;">Nombre</td>
                <td class="bg-light text-center text-bold" style="width:15%; padding:8px;">Fecha</td>
                <td class="bg-light text-center text-bold" style="width:25%; padding:8px;">Firma</td>
            </tr>
            <tr>
                <td style="padding:15px 6px;">Revisado</td>
                <td class="text-center"><?php echo htmlspecialchars($compra['revisado_por'] ?? 'Eduardo Antonio Hernández - Oficial Administrativo'); ?></td>
                <td class="text-center no-wrap editable-cell trans-date" contenteditable="true" title="Modificar (Se sincronizará con Autorización)"><?php echo $fecha_numerica; ?></td>
                <td></td>
            </tr>
            <tr>
                <td style="padding:15px 6px;">Realizada por:</td>
                <td class="text-center">Patricia Carolina Vásquez Martínez - Contador General</td>
                <td class="text-center no-wrap editable-cell trans-date" contenteditable="true" title="Modificar (Se sincronizará con Autorización)"><?php echo $fecha_numerica; ?></td>
                <td></td>
            </tr>
            <tr>
                <td style="padding:15px 6px;">Titular de cuenta nivel 1</td>
                <td class="text-center"><?php echo htmlspecialchars($compra['titular_1'] ?? 'José Orlando Osorto Pérez - Director Ejecutivo'); ?></td>
                <td class="text-center no-wrap editable-cell trans-date" contenteditable="true" title="Modificar (Se sincronizará con Autorización)"><?php echo $fecha_numerica; ?></td>
                <td></td>
            </tr>
            <tr>
                <td style="padding:15px 6px;">Titular de cuenta nivel 2</td>
                <td class="text-center"><?php echo htmlspecialchars($compra['titular_2'] ?? 'Edwing Armando Lopez - Coordinador de Programas'); ?></td>
                <td class="text-center no-wrap editable-cell trans-date" contenteditable="true" title="Modificar (Se sincronizará con Autorización)"><?php echo $fecha_numerica; ?></td>
                <td></td>
            </tr>
        </table>
    </div>

    <!-- ========================================== -->
    <!-- 5. FORMATO C: PLANILLAS Y RECIBOS          -->
    <!-- ========================================== -->
    <?php if ($is_format_c): ?>
        <?php foreach($planillas as $plan): ?>
            <!-- Pagina Principal de Planilla -->
            <div class="page landscape-page">
                <table class="no-border" style="margin-bottom:2px;">
                    <tr>
                        <td class="no-border text-left" style="width:25%; vertical-align:bottom;"><div class="forma-a">Forma C</div></td>
                        <td class="no-border text-center" style="width:50%;">
                            <div class="header-title-main">ONG - ACCION HONDURAS</div>
                            <div class="header-title-sub"><?php echo htmlspecialchars($plan['titulo'] ?? 'PLANILLA DE INCENTIVOS'); ?> - AF-2027</div>
                        </td>
                        <td class="no-border text-right" style="width:25%; vertical-align:bottom;">
                            <img src="../uploads/images/logo.png" alt="Acción Honduras" class="logo-img">
                            <div class="expediente-box"><?php echo $expediente_code; ?></div>
                        </td>
                    </tr>
                </table>
                
                <div class="separator-black"></div>
                
                <table class="no-border" style="margin-bottom: 15px; margin-top: 15px; font-size: 13px;">
                    <tr>
                        <td class="text-bold" style="width: 25%;">COOPERATIVA / ENTIDAD:</td>
                        <td><?php echo htmlspecialchars($plan['nombre_cooperativa'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td class="text-bold">NOMBRE DEL PROGRAMA:</td>
                        <td><?php echo htmlspecialchars($plan['programa'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td class="text-bold">MARCO LOGICO DEL PROGRAMA:</td>
                        <td><?php echo htmlspecialchars($plan['marco_logico'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td class="text-bold">FECHA DE PREPARADO:</td>
                        <td><?php echo htmlspecialchars($plan['fecha_preparado'] ?? ''); ?></td>
                        <td class="text-bold text-right" style="width: 20%; padding-right:8px;">Fecha de Pago:</td>
                        <td style="border-bottom: 1px solid #000; width: 25%;" class="editable-cell text-center" contenteditable="true"><?php echo htmlspecialchars($plan['fecha_pago'] ?? ''); ?></td>
                    </tr>
                </table>
                
                <table class="thick-outer planilla-print-table" style="margin-top: 20px;">
                    <thead>
                        <tr class="bg-light text-center">
                            <th style="padding: 10px;">No</th>
                            <th style="padding: 10px;">NOMBRE</th>
                            <th style="padding: 10px;">COMUNIDAD</th>
                            <th style="padding: 10px;">N° IDENTIDAD</th>
                            <th style="padding: 10px;">MONTO BASE</th>
                            <th style="padding: 10px;">COMISIÓN</th>
                            <th style="padding: 10px;">TOTAL TRANSFERENCIA</th>
                            <th style="padding: 10px;">INSTRUCCIÓN DE PAGO</th>
                            <th style="padding: 10px;">FIRMA BENEFICIARIO FINAL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $tot_base = 0; $tot_com = 0; $tot_trans = 0;
                        foreach($plan['detalles'] as $idx => $det): 
                            $tot_base += (float)$det['monto_base'];
                            $tot_com += (float)$det['comision'];
                            $tot_trans += (float)$det['total_transferencia'];
                        ?>
                        <tr>
                            <td class="text-center" style="padding: 8px;"><?php echo $idx + 1; ?></td>
                            <td style="padding: 8px;"><?php echo htmlspecialchars($det['nombre']); ?></td>
                            <td style="padding: 8px;"><?php echo htmlspecialchars($det['comunidad'] ?? ''); ?></td>
                            <td class="text-center" style="padding: 8px;"><?php echo htmlspecialchars($det['identidad']); ?></td>
                            <td class="text-right" style="padding-right: 8px;"><?php echo number_format((float)$det['monto_base'], 2); ?></td>
                            <td class="text-right" style="padding-right: 8px;"><?php echo number_format((float)$det['comision'], 2); ?></td>
                            <td class="text-right text-bold" style="padding-right: 8px;"><?php echo number_format((float)$det['total_transferencia'], 2); ?></td>
                            <td style="padding: 8px;"><?php echo htmlspecialchars($det['instruccion_pago']); ?></td>
                            <td></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-light text-bold">
                            <td colspan="4" class="text-right" style="padding-right: 10px;">TOTAL</td>
                            <td class="text-right" style="padding-right: 8px;"><?php echo number_format($tot_base, 2); ?></td>
                            <td class="text-right" style="padding-right: 8px;"><?php echo number_format($tot_com, 2); ?></td>
                            <td class="text-right" style="padding-right: 8px;"><?php echo number_format($tot_trans, 2); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                
                <table class="no-border text-center" style="margin-top: 50px; width: 100%;">
                    <tr>
                        <td style="width: 15%;"></td>
                        <td style="width: 30%; border-bottom: 1px solid #000; height: 40px;"></td>
                        <td style="width: 10%;"></td>
                        <td style="width: 30%; border-bottom: 1px solid #000; height: 40px;"></td>
                        <td style="width: 15%;"></td>
                    </tr>
                    <tr>
                        <td></td>
                        <td style="padding-top: 5px; font-weight: bold;"><?php echo htmlspecialchars($plan['preparado_por'] ?? ''); ?><br>Preparado por</td>
                        <td></td>
                        <td style="padding-top: 5px; font-weight: bold;"><br>Revisado / Aprobado</td>
                        <td></td>
                    </tr>
                </table>
            </div>
            
            <!-- Recibos Individuales -->
            <?php foreach($plan['detalles'] as $det): ?>
            <div class="page receipt-page">
                <table class="receipt-head no-border">
                    <tr>
                        <td style="width:18%;"></td>
                        <td class="receipt-title">RECIBO:&nbsp;&nbsp; ACCION HONDURAS</td>
                        <td class="text-right" style="width:18%;">
                            <img src="../uploads/images/logo.png" alt="Acción Honduras" class="logo-img">
                        </td>
                    </tr>
                </table>
                <table class="receipt-amount no-border">
                        <tr>
                            <td style="width:20%;">Recibo por:</td>
                            <td style="width:8%;">L</td>
                            <td class="text-right"><?php echo number_format((float)$det['total_transferencia'], 2); ?></td>
                        </tr>
                    </table>
                    <div class="receipt-rule"></div>
                    <table class="receipt-body no-border">
                        <tr>
                            <td class="receipt-label">LA SUMA DE:</td>
                            <td class="receipt-value"><?php $receipt_amount = (float)$det['total_transferencia']; echo numeroALetras(floor($receipt_amount)); ?> LEMPIRAS EXACTOS <?php echo sprintf('%02d', (int)round(($receipt_amount - floor($receipt_amount)) * 100)); ?>/100</td>
                        </tr>
                        <tr class="concept-row">
                            <td class="receipt-label">POR CONCEPTO<br>DE:</td>
                            <td class="receipt-value"><?php echo htmlspecialchars($compra['descripcion_actividad']); ?></td>
                        </tr>
                        <tr>
                            <td class="receipt-label">REALIZADO EL:</td>
                            <td class="receipt-value editable-cell" contenteditable="true"><?php echo $fecha_larga; ?></td>
                        </tr>
                        <tr class="place-row">
                            <td class="receipt-label">LUGAR:</td>
                            <td class="receipt-value editable-cell" contenteditable="true"><?php echo htmlspecialchars($plan['lugar'] ?? 'Lepaterique Francisco Morazan'); ?></td>
                        </tr>
                    </table>
                    <table class="receipt-signature">
                        <tr>
                            <td class="signature-label">NOMBRE Y<br>FIRMA:<br>DNI</td>
                            <td class="signature-value"><?php echo htmlspecialchars($det['nombre']); ?><br><br><?php echo htmlspecialchars($det['identidad']); ?></td>
                        </tr>
                    </table>
            </div>
            <?php endforeach; ?>
            
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sincronizar fechas entre campos con .trans-date y .auth-date
            const transDates = document.querySelectorAll('.trans-date');
            const authDates = document.querySelectorAll('.auth-date');
            
            transDates.forEach((sourceEl, index) => {
                sourceEl.addEventListener('input', function() {
                    const newValue = this.innerText;
                    
                    // Si cambian la primera, cambiar todas las demas auth y trans
                    authDates.forEach(targetEl => {
                        targetEl.innerText = newValue;
                    });
                    
                    // Y sincronizar la otra firma en la misma pagina si existe
                    transDates.forEach((otherSource, otherIndex) => {
                        if (index !== otherIndex) {
                            otherSource.innerText = newValue;
                        }
                    });
                });
            });
        });
    </script>

</body>
</html>
