<?php
session_start();

// VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';

if (isset($_FILES['pdf_file']['error']) && $_FILES['pdf_file']['error'] !== 0) {
    die("Error de PHP al subir: " . $_FILES['pdf_file']['error']);
}
require '../vendor/autoload.php';

if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    die("Error: No se seleccionó ningún archivo o hubo un problema en la subida.");
}

$parser = new \Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($_FILES['pdf_file']['tmp_name']);
$text = $pdf->getText();

// --- NUEVA LÓGICA DE PARSEO ---

// 1. Separamos el texto buscando CUALQUIERA de los 3 tipos de carta
// Usamos PREG_SPLIT_DELIM_CAPTURE para guardar qué tipo de carta es
$pattern = '/(Child Welcome Letter|Child Reply Letter|Thank You Letter)/i';
$parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

$data_extracted = [];
$current_type = 'Unknown';

foreach ($parts as $block) {
    $clean_block = trim($block);
    
    // Si el bloque es solo el título, actualizamos el "tipo actual" y pasamos al siguiente
    if (preg_match('/^(Child Welcome Letter|Child Reply Letter|Thank You Letter)$/i', $clean_block)) {
        $current_type = $clean_block;
        continue;
    }

    // Si no es título, buscamos el contenido
    preg_match('/Slip Id:\s*(\d+)/', $block, $slip);
    
    if (isset($slip[1])) {
        // Extraer datos usando tus patrones originales + Community ID
        preg_match('/Community Id:\s*(\d+)/', $block, $comm);
        preg_match('/Child Nbr:\s*(\d+)/', $block, $childNbr);
        preg_match('/Child Name:\s*(.*?)\s*Contact Id/s', $block, $name);
        preg_match('/Village:\s*(.*)/', $block, $village);
        preg_match('/Due Date:\s*([\d\-\w]+)/', $block, $dueDateOfficial); // Fecha del Admin (PDF)

        // 2. Calcular Fecha Límite Técnico (Lógica de Negocio)
        $daysToAdd = 7; // Default
        if (stripos($current_type, 'Child Welcome Letter') !== false) {
            $daysToAdd = 5;
        } elseif (stripos($current_type, 'Child Reply Letter') !== false) {
            $daysToAdd = 14;
        } elseif (stripos($current_type, 'Thank You Letter') !== false) {
            $daysToAdd = 20;
        }

        // Calculamos la fecha sumando días a la fecha de HOY (fecha de carga)
        $uploadDate = new DateTime();
        $techDeadline = clone $uploadDate;
        $techDeadline->modify("+$daysToAdd days");

        $data_extracted[] = [
            'letter_type'   => $current_type,
            'slip_id'       => $slip[1],
            'community_id'  => $comm[1] ?? '',
            'child_nbr'     => $childNbr[1] ?? '',
            'child_name'    => trim($name[1] ?? 'Sin nombre'),
            'village'       => trim($village[1] ?? ''),
            'due_date'      => $dueDateOfficial[1] ?? '', // Fecha Admin (PDF)
            'tech_date'     => $techDeadline->format('d-M-Y') // Fecha Técnico (Calculada)
        ];
    }
}

$_SESSION['temp_data'] = $data_extracted;
header("Location: revisar_carga.php"); // Redirige a revisar antes de guardar
exit;
?>