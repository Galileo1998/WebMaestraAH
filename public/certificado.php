<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['rol'] !== 'student') { header("Location: acceso.php"); exit; }
require_once __DIR__ . '/../config/Database.php'; 
$database = new Database(); $db = $database->getConnection();

$user_id = $_SESSION['user_id']; $nombre_estudiante = $_SESSION['nombre']; $identidad_estudiante = $_SESSION['identidad'];
$slug = isset($_GET['curso']) ? trim($_GET['curso']) : '';
if (empty($slug)) { die("Error: No se especificó el curso."); }

$stmt_c = $db->prepare("SELECT id, title FROM ah_courses WHERE slug = :slug LIMIT 1");
$stmt_c->execute(['slug' => $slug]); $curso = $stmt_c->fetch(PDO::FETCH_ASSOC);
if (!$curso) { die("Error: Curso no encontrado."); }
$course_id = $curso['id']; $nombre_curso = $curso['title'];

// OBTENER DISEÑO PRO MAX
$stmt_cert = $db->prepare("SELECT * FROM ah_certificates WHERE course_id = :cid LIMIT 1");
$stmt_cert->execute(['cid' => $course_id]); $cert_design = $stmt_cert->fetch(PDO::FETCH_ASSOC);
if (!$cert_design) { die("Error: El administrador aún no ha diseñado el certificado."); }

// PARCHE ANTIBLOQUEO CORS: Convertimos URLs absolutas a rutas locales relativas
function limpiarRutaLocal($url) {
    if (empty($url)) return '';
    if (strpos($url, 'accionhonduras.org') !== false) {
        $partes = explode('accionhonduras.org/', $url);
        return '../' . end($partes);
    }
    return $url;
}

$bg_url = limpiarRutaLocal($cert_design['background_url'] ?? ''); 
$titulo = $cert_design['heading_text'] ?? 'CERTIFICADO DE APROBACIÓN';
$hy = $cert_design['heading_y'] ?? 181; $hx = $cert_design['heading_x'] ?? 0;
$tf = $cert_design['title_font'] ?? 'Inter'; $ts = $cert_design['title_size'] ?? 28; $tc = $cert_design['title_color'] ?? '#0f172a';

$sny = $cert_design['student_name_y'] ?? 231; $snx = $cert_design['student_name_x'] ?? 0;
$nf = $cert_design['name_font'] ?? 'Great Vibes'; $ns = $cert_design['name_size'] ?? 75; $nc = $cert_design['name_color'] ?? '#0f172a';

$leyenda = $cert_design['body_text'] ?? 'Por haber completado...';
$by = $cert_design['body_y'] ?? 423; $bx = $cert_design['body_x'] ?? 0;
$bs = $cert_design['body_size'] ?? 16; $bc = $cert_design['body_color'] ?? '#475569';

$firma_izq_img = limpiarRutaLocal($cert_design['sig_left_img'] ?? ''); 
$firma_izq_seal = limpiarRutaLocal($cert_design['sig_left_seal'] ?? '');
$firma_izq_nombre = $cert_design['sig_left_name'] ?? 'MSc Galileo Garcia'; $firma_izq_cargo = $cert_design['sig_left_role'] ?? 'Oficial';
$sig1_x = $cert_design['sig1_x'] ?? 150; $sig1_y = $cert_design['sig1_y'] ?? 550;
$seal1_x = $cert_design['seal1_x'] ?? 120; $seal1_y = $cert_design['seal1_y'] ?? 520;

$firma_der_img = limpiarRutaLocal($cert_design['sig_right_img'] ?? ''); 
$firma_der_seal = limpiarRutaLocal($cert_design['sig_right_seal'] ?? '');
$firma_der_nombre = $cert_design['sig_right_name'] ?? 'Ing. Orlando Osorto'; $firma_der_cargo = $cert_design['sig_right_role'] ?? 'Coordinador';
$sig2_x = $cert_design['sig2_x'] ?? 600; $sig2_y = $cert_design['sig2_y'] ?? 550;
$seal2_x = $cert_design['seal2_x'] ?? 570; $seal2_y = $cert_design['seal2_y'] ?? 520;

$sig_width = $cert_design['sig_img_width'] ?? 150;
$meses = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

// LÓGICA DE REGISTRO
$stmt_check = $db->prepare("SELECT verification_code, issued_at FROM ah_issued_certificates WHERE user_id = :uid AND course_id = :cid LIMIT 1");
$stmt_check->execute(['uid' => $user_id, 'cid' => $course_id]);
$certificado_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);

if ($certificado_existente) {
    $codigo_autenticidad = $certificado_existente['verification_code'];
    $timestamp_db = strtotime($certificado_existente['issued_at']);
    $fecha_emision = date('d', $timestamp_db) . " de " . $meses[date('n', $timestamp_db) - 1] . " de " . date('Y', $timestamp_db);
} else {
    $codigo_autenticidad = "SHA256-AH-" . date('Y') . "-" . strtoupper(substr(md5($identidad_estudiante . $course_id . time()), 0, 10));
    $fecha_emision = date('d') . " de " . $meses[date('n') - 1] . " de " . date('Y');
    $stmt_insert = $db->prepare("INSERT INTO ah_issued_certificates (user_id, course_id, verification_code, score_obtained) VALUES (:uid, :cid, :code, 100)");
    $stmt_insert->execute(['uid' => $user_id, 'cid' => $course_id, 'code' => $codigo_autenticidad]);
}
$nombre_archivo_pdf = "Diploma_" . preg_replace('/[^A-Za-z0-9\-]/', '_', $nombre_estudiante) . ".pdf";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tu Certificado Oficial</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Alex+Brush&family=Great+Vibes&family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cinzel:wght@400;700&family=Montserrat:wght@400;700&family=Dancing+Script:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script src="https://unpkg.com/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js"></script>

    <style>
        body { background-color: #f1f5f9; display: flex; flex-direction: column; align-items: center; font-family: 'Inter', sans-serif; margin: 0; padding: 20px; }
        
        .action-bar { background: white; padding: 15px 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px; display: flex; gap: 15px; align-items: center; z-index: 10; border: 1px solid #e2e8f0; }
        .btn-download { background: #46B094; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-size: 1.1rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-download:hover { background: #358f77; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(70, 176, 148, 0.2); }
        .btn-download:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-back { background: #e2e8f0; color: #475569; text-decoration: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-back:hover { background: #cbd5e1; color: #0f172a; }

        /* CAMBIO CSS CLAVE: Cambiamos Flexbox por Margin Auto para evitar coordenadas X negativas */
        .diploma-wrapper { width: 100%; overflow-x: auto; padding-bottom: 20px; text-align: center; }

        #diploma-container {
            width: 1264px !important; height: 843px !important; 
            min-width: 1264px !important; min-height: 843px !important;
            margin: 0 auto; /* Centrado clásico sin flexbox */
            background-image: url('<?php echo $bg_url; ?>'); 
            background-size: 1264px 843px !important; 
            background-position: center; background-repeat: no-repeat;
            position: relative; background-color: white; box-shadow: 0 10px 30px rgba(0,0,0,0.15); overflow: hidden;
            text-align: left; /* Restaura la alineación interna */
        }

        .cert-title { position: absolute; width: 100%; top: <?php echo $hy; ?>px; left: <?php echo $hx; ?>px; text-align: center; font-family: '<?php echo $tf; ?>', sans-serif; font-size: <?php echo $ts; ?>px; color: <?php echo $tc; ?>; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; line-height: 1.2; }
        .cert-name { position: absolute; width: 100%; top: <?php echo $sny; ?>px; left: <?php echo $snx; ?>px; text-align: center; font-family: '<?php echo $nf; ?>', cursive; font-size: <?php echo $ns; ?>px; color: <?php echo $nc; ?>; font-weight: 400; line-height: 1.2; }
        .cert-body-wrapper { position: absolute; width: 70%; left: <?php echo 15 + ($bx/10); ?>%; top: <?php echo $by; ?>px; text-align: center; padding-left: <?php echo $bx; ?>px; }
        .cert-body-text { font-size: <?php echo $bs; ?>px; color: <?php echo $bc; ?>; line-height: 1.6; margin-bottom: 15px; }
        .cert-course-name { font-size: 22px; font-weight: 800; color: #34859B; line-height: 1.3; display: inline-block; padding-top: 5px; }

        .free-element { position: absolute; }
        .sig-block { width: 250px; text-align: center; display: flex; flex-direction: column; align-items: center; z-index: 5; }
        .sig-img { max-width: <?php echo $sig_width; ?>px; object-fit: contain; margin-bottom: 5px; }
        .sig-line { width: 100%; border-top: 1px solid #cbd5e1; padding-top: 5px; font-size: 0.8rem; font-weight: 700; color: #1e293b; line-height: 1.2; }
        .sig-role { font-size: 0.7rem; color: #64748b; }
        .sig-seal-img { width: 120px; opacity: 0.85; z-index: 1; pointer-events: none; }

        .security-code { position: absolute; bottom: 30px; width: 100%; text-align: center; font-size: 10px; color: #94a3b8; letter-spacing: 1px; }
    </style>
</head>
<body>
    
    <div class="action-bar">
        <a href="index.php" class="btn-back"><i class="fa-solid fa-house"></i> Volver a mis Cursos</a>
        <button id="btn-descargar" class="btn-download" onclick="generarPDF(true)">
            <i class="fa-solid fa-download"></i> Descargar PDF Original
        </button>
    </div>

    <div class="diploma-wrapper">
        <div id="diploma-container">
            <div class="cert-title"><?php echo htmlspecialchars($titulo); ?></div>
            <div class="cert-name"><?php echo htmlspecialchars($nombre_estudiante); ?></div>
            <div class="cert-body-wrapper">
                <div class="cert-body-text"><?php echo htmlspecialchars($leyenda); ?></div>
                <div class="cert-course-name">"<?php echo htmlspecialchars($nombre_curso); ?>"</div>
            </div>

            <?php if(!empty($firma_izq_seal)): ?><img src="<?php echo htmlspecialchars($firma_izq_seal); ?>" class="free-element sig-seal-img" style="top:<?php echo $seal1_y; ?>px; left:<?php echo $seal1_x; ?>px;"><?php endif; ?>
            <div class="free-element sig-block" style="top:<?php echo $sig1_y; ?>px; left:<?php echo $sig1_x; ?>px;">
                <?php if(!empty($firma_izq_img)): ?><img src="<?php echo htmlspecialchars($firma_izq_img); ?>" class="sig-img" onerror="this.style.display='none'"><?php else: ?><div style="height:65px;"></div><?php endif; ?>
                <div class="sig-line"><?php echo htmlspecialchars($firma_izq_nombre); ?></div>
                <div class="sig-role"><?php echo htmlspecialchars($firma_izq_cargo); ?></div>
            </div>

            <?php if(!empty($firma_der_seal)): ?><img src="<?php echo htmlspecialchars($firma_der_seal); ?>" class="free-element sig-seal-img" style="top:<?php echo $seal2_y; ?>px; left:<?php echo $seal2_x; ?>px;"><?php endif; ?>
            <div class="free-element sig-block" style="top:<?php echo $sig2_y; ?>px; left:<?php echo $sig2_x; ?>px;">
                <?php if(!empty($firma_der_img)): ?><img src="<?php echo htmlspecialchars($firma_der_img); ?>" class="sig-img" onerror="this.style.display='none'"><?php else: ?><div style="height:65px;"></div><?php endif; ?>
                <div class="sig-line"><?php echo htmlspecialchars($firma_der_nombre); ?></div>
                <div class="sig-role"><?php echo htmlspecialchars($firma_der_cargo); ?></div>
            </div>

            <div class="security-code">Emitido: <?php echo $fecha_emision; ?> | Autenticidad: <?php echo $codigo_autenticidad; ?></div>
        </div>
    </div>

    <script>
        function generarPDF(isUserClick = false) {
            const elemento = document.getElementById('diploma-container');
            const wrapper = document.querySelector('.diploma-wrapper');
            const btn = document.getElementById('btn-descargar');
            
            if (isUserClick) {
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Ensamblando PDF...';
                btn.disabled = true;
            }

            // MAGIA ANTI-RECORTE: 
            // Apagamos momentáneamente el desbordamiento oculto para que la foto capte el 100%
            const oldOverflow = wrapper.style.overflowX;
            wrapper.style.overflowX = 'visible';
            document.body.style.overflow = 'visible';

            const opciones = { 
                margin: 0, 
                filename: '<?php echo $nombre_archivo_pdf; ?>', 
                image: { type: 'jpeg', quality: 0.98 }, 
                html2canvas: { 
                    scale: 2, 
                    useCORS: false, 
                    logging: false,
                    scrollX: 0, // Forzamos a que empiece a medir desde el píxel 0
                    scrollY: 0
                }, 
                jsPDF: { unit: 'px', format: [1264, 843], orientation: 'landscape' } 
            };

            const pdfWorker = html2pdf().set(opciones).from(elemento);
            
            // Re-encendemos el desbordamiento después de tomar la foto
            const restoreStyles = () => {
                wrapper.style.overflowX = oldOverflow;
                document.body.style.overflow = '';
            };

            if (isUserClick) {
                pdfWorker.save().then(() => {
                    restoreStyles();
                    btn.innerHTML = '<i class="fa-solid fa-check-circle"></i> ¡Completado!';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="fa-solid fa-download"></i> Descargar PDF Original';
                        btn.disabled = false;
                    }, 2500);
                }).catch(() => restoreStyles());
            } else {
                pdfWorker.save().then(() => restoreStyles()).catch(() => restoreStyles());
            }
        }

        window.onload = function() { 
            document.fonts.ready.then(function () {
                setTimeout(function() {
                    generarPDF(false);
                }, 800);
            });
        };
    </script>
</body>
</html>