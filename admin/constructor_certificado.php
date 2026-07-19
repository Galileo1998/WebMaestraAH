<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$course_id = isset($_GET['course_id']) && is_numeric($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if ($course_id === 0) { header("Location: cursos.php"); exit; }

$stmt_c = $db->prepare("SELECT title FROM ah_courses WHERE id = :id LIMIT 1");
$stmt_c->execute(['id' => $course_id]);
$curso_title = $stmt_c->fetchColumn();

// LÓGICA DE GUARDADO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_cert_config') {
    
    $bg = trim($_POST['bg_url']);
    $ht = trim($_POST['title_text']); $hy = (int)$_POST['title_top']; $hx = (int)$_POST['title_left'];
    $tf = trim($_POST['title_font']); $ts = (int)$_POST['title_size']; $tc = trim($_POST['title_color']);
    
    $sny = (int)$_POST['name_top']; $snx = (int)$_POST['name_left'];
    $nf = trim($_POST['name_font']); $ns = (int)$_POST['name_size']; $nc = trim($_POST['name_color']);
    
    $bt = trim($_POST['body_text']); $by = (int)$_POST['body_top']; $bx = (int)$_POST['body_left'];
    $bs = (int)$_POST['body_size']; $bc = trim($_POST['body_color']);
    
    $sli = trim($_POST['signature1_img']); $sln = trim($_POST['signature1_name']); $slr = trim($_POST['signature1_role']);
    $sls = trim($_POST['signature1_seal']);
    
    $sri = trim($_POST['signature2_img']); $srn = trim($_POST['signature2_name']); $srr = trim($_POST['signature2_role']);
    $srs = trim($_POST['signature2_seal']);
    
    $sw = (int)$_POST['sig_width'];
    
    // Nuevas Coordenadas Independientes
    $s1x = (int)$_POST['sig1_x']; $s1y = (int)$_POST['sig1_y'];
    $s2x = (int)$_POST['sig2_x']; $s2y = (int)$_POST['sig2_y'];
    $sl1x = (int)$_POST['seal1_x']; $sl1y = (int)$_POST['seal1_y'];
    $sl2x = (int)$_POST['seal2_x']; $sl2y = (int)$_POST['seal2_y'];

    $sql = "INSERT INTO ah_certificates (
                course_id, background_url, heading_text, heading_y, heading_x, title_font, title_size, title_color, 
                student_name_y, student_name_x, name_font, name_size, name_color, 
                body_text, body_y, body_x, body_size, body_color, 
                sig_left_img, sig_left_seal, sig_left_name, sig_left_role, 
                sig_right_img, sig_right_seal, sig_right_name, sig_right_role, sig_img_width,
                sig1_x, sig1_y, sig2_x, sig2_y, seal1_x, seal1_y, seal2_x, seal2_y
            ) VALUES (
                :cid, :bg, :ht, :hy, :hx, :tf, :ts, :tc,
                :sny, :snx, :nf, :ns, :nc,
                :bt, :by, :bx, :bs, :bc,
                :sli, :sls, :sln, :slr,
                :sri, :srs, :srn, :srr, :sw,
                :s1x, :s1y, :s2x, :s2y, :sl1x, :sl1y, :sl2x, :sl2y
            ) ON DUPLICATE KEY UPDATE 
                background_url = VALUES(background_url), heading_text = VALUES(heading_text), heading_y = VALUES(heading_y), heading_x = VALUES(heading_x),
                title_font = VALUES(title_font), title_size = VALUES(title_size), title_color = VALUES(title_color),
                student_name_y = VALUES(student_name_y), student_name_x = VALUES(student_name_x), name_font = VALUES(name_font), name_size = VALUES(name_size), name_color = VALUES(name_color),
                body_text = VALUES(body_text), body_y = VALUES(body_y), body_x = VALUES(body_x), body_size = VALUES(body_size), body_color = VALUES(body_color),
                sig_left_img = VALUES(sig_left_img), sig_left_seal = VALUES(sig_left_seal), sig_left_name = VALUES(sig_left_name), sig_left_role = VALUES(sig_left_role),
                sig_right_img = VALUES(sig_right_img), sig_right_seal = VALUES(sig_right_seal), sig_right_name = VALUES(sig_right_name), sig_right_role = VALUES(sig_right_role),
                sig_img_width = VALUES(sig_img_width),
                sig1_x = VALUES(sig1_x), sig1_y = VALUES(sig1_y), sig2_x = VALUES(sig2_x), sig2_y = VALUES(sig2_y), 
                seal1_x = VALUES(seal1_x), seal1_y = VALUES(seal1_y), seal2_x = VALUES(seal2_x), seal2_y = VALUES(seal2_y)";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        'cid' => $course_id, 'bg' => $bg, 'ht' => $ht, 'hy' => $hy, 'hx' => $hx, 'tf' => $tf, 'ts' => $ts, 'tc' => $tc,
        'sny' => $sny, 'snx' => $snx, 'nf' => $nf, 'ns' => $ns, 'nc' => $nc,
        'bt' => $bt, 'by' => $by, 'bx' => $bx, 'bs' => $bs, 'bc' => $bc,
        'sli' => $sli, 'sls' => $sls, 'sln' => $sln, 'slr' => $slr,
        'sri' => $sri, 'srs' => $srs, 'srn' => $srn, 'srr' => $srr, 'sw' => $sw,
        's1x' => $s1x, 's1y' => $s1y, 's2x' => $s2x, 's2y' => $s2y, 'sl1x' => $sl1x, 'sl1y' => $sl1y, 'sl2x' => $sl2x, 'sl2y' => $sl2y
    ]);

    $msg = "Configuración visual con Movimiento Libre guardada con éxito.";
}

// CARGAR VALORES
$stmt_load = $db->prepare("SELECT * FROM ah_certificates WHERE course_id = :cid LIMIT 1");
$stmt_load->execute(['cid' => $course_id]);
$c = $stmt_load->fetch(PDO::FETCH_ASSOC);

$bg_url = $c['background_url'] ?? '';
$title_text = $c['heading_text'] ?? 'CERTIFICADO DE APROBACIÓN';
$title_top = $c['heading_y'] ?? 181; $title_left = $c['heading_x'] ?? 0;
$title_font = $c['title_font'] ?? 'Inter'; $title_size = $c['title_size'] ?? 28; $title_color = $c['title_color'] ?? '#0f172a';

$name_top = $c['student_name_y'] ?? 231; $name_left = $c['student_name_x'] ?? 0;
$name_font = $c['name_font'] ?? 'Great Vibes'; $name_size = $c['name_size'] ?? 75; $name_color = $c['name_color'] ?? '#0f172a';

$body_text = $c['body_text'] ?? 'Por haber completado y aprobado satisfactoriamente todos los módulos de capacitación, evaluaciones avanzadas y talleres prácticos correspondientes al programa técnico dictado por esta organización:';
$body_top = $c['body_y'] ?? 423; $body_left = $c['body_x'] ?? 0;
$body_size = $c['body_size'] ?? 16; $body_color = $c['body_color'] ?? '#475569';

$sig1_img = $c['sig_left_img'] ?? ''; $sig1_seal = $c['sig_left_seal'] ?? '';
$sig1_name = $c['sig_left_name'] ?? 'MSc Galileo Garcia'; $sig1_role = $c['sig_left_role'] ?? 'Oficial de Comunicaciones';

$sig2_img = $c['sig_right_img'] ?? ''; $sig2_seal = $c['sig_right_seal'] ?? '';
$sig2_name = $c['sig_right_name'] ?? 'Ing. Orlando Osorto'; $sig2_role = $c['sig_right_role'] ?? 'Coordinador de Programas';

$sig_width = $c['sig_img_width'] ?? 150;

$sig1_x = $c['sig1_x'] ?? 150; $sig1_y = $c['sig1_y'] ?? 550;
$sig2_x = $c['sig2_x'] ?? 600; $sig2_y = $c['sig2_y'] ?? 550;
$seal1_x = $c['seal1_x'] ?? 120; $seal1_y = $c['seal1_y'] ?? 520;
$seal2_x = $c['seal2_x'] ?? 570; $seal2_y = $c['seal2_y'] ?? 520;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Diseñador Libre de Diplomas | AH Admin Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Alex+Brush&family=Great+Vibes&family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Cinzel:wght@400;700&family=Montserrat:wght@400;700&family=Dancing+Script:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg: #f8fafc; --border: #e2e8f0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); display: flex; margin: 0; min-height: 100vh; }
        
        .panel-lateral { width: 450px; background: white; border-right: 1px solid var(--border); padding: 30px; box-sizing: border-box; overflow-y: auto; height: 100vh; flex-shrink: 0; }
        .workspace-view { flex-grow: 1; padding: 40px; box-sizing: border-box; overflow-y: auto; height: 100vh; display: flex; flex-direction: column; align-items: center; overflow-x: auto; }
        
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 0.75rem; font-weight: 700; color: #475569; margin-bottom: 5px; text-transform: uppercase; }
        .form-control { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; box-sizing: border-box; font-family: inherit; font-size:0.85rem;}
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .grid-4 { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 6px; margin-top: 8px; }
        .grid-5 { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 6px; margin-top: 8px; }

        .cert-canvas { width: 1000px; height: 707px; background: white; border: 1px solid #94a3b8; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15); position: relative; background-size: cover; background-position: center; flex-shrink: 0; overflow: hidden; }
        .cert-layer { position: absolute; text-align: center; width: 100%; padding: 0 80px; box-sizing: border-box; }
        .layer-course { font-weight: 800; color: var(--ah-primary); font-size: 1.35rem; display: block; margin-top: 12px; }
        
        /* ESTILOS DE FIRMAS Y SELLOS LIBRES */
        .free-element { position: absolute; }
        .sig-block { width: 250px; text-align: center; display: flex; flex-direction: column; align-items: center; z-index: 5; }
        .sig-img { object-fit: contain; margin-bottom: 5px; }
        .sig-line { width: 100%; border-top: 1px solid #cbd5e1; padding-top: 5px; font-size: 0.8rem; font-weight: 700; color: #1e293b; }
        .sig-role { font-size: 0.7rem; color: #64748b; }
        .sig-seal-img { width: 120px; opacity: 0.85; z-index: 1; pointer-events: none; }
        
        .meta-verification { position: absolute; bottom: 30px; width: 100%; text-align: center; font-size: 0.7rem; color: #94a3b8; font-family: monospace; }
        .btn-primary { background: var(--ah-primary); color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s; padding:12px;}
        .btn-primary:hover { background: #2c7285; }
        .section-box { background:#f8fafc; padding:15px; border-radius:8px; border:1px solid var(--border); margin-bottom:15px; }
        .coord-label { font-size:0.65rem; font-weight:bold; color:#64748b; margin-bottom:3px; }
    </style>
</head>
<body>

    <aside class="panel-lateral">
        <a href="cursos.php" style="color:#64748b; text-decoration:none; font-size:0.9rem; font-weight:bold;"><i class="fa-solid fa-arrow-left"></i> Volver</a>
        <h2 style="font-size:1.3rem; margin-top:15px; margin-bottom:20px; color:#0f172a; font-weight:800;"><i class="fa-solid fa-arrows-up-down-left-right"></i> Diseñador Libre</h2>
        
        <?php if(!empty($msg)) echo "<div style='background:#dcfce7; color:#166534; padding:12px; border-radius:6px; font-size:0.85rem; margin-bottom:15px; font-weight:600;'>$msg</div>"; ?>

        <form method="POST">
            <input type="hidden" name="action" value="save_cert_config">

            <div class="form-group">
                <label>Fondo del Diploma (URL)</label>
                <input type="text" name="bg_url" class="form-control" value="<?php echo htmlspecialchars($bg_url); ?>" oninput="updatePreview()" id="in-bg">
            </div>

            <div class="section-box">
                <label style="color:var(--ah-primary);"><i class="fa-solid fa-heading"></i> Título del Diploma</label>
                <input type="text" name="title_text" class="form-control" value="<?php echo htmlspecialchars($title_text); ?>" oninput="updatePreview()" id="in-title-text" style="margin-top:8px;">
                <div class="grid-5">
                    <select name="title_font" class="form-control" onchange="updatePreview()" id="in-title-font" style="grid-column: span 2;">
                        <option value="Inter" <?php if($title_font=='Inter') echo 'selected';?>>Inter</option>
                        <option value="Montserrat" <?php if($title_font=='Montserrat') echo 'selected';?>>Montserrat</option>
                        <option value="Cinzel" <?php if($title_font=='Cinzel') echo 'selected';?>>Cinzel</option>
                        <option value="Playfair Display" <?php if($title_font=='Playfair Display') echo 'selected';?>>Playfair</option>
                    </select>
                    <input type="color" name="title_color" class="form-control" value="<?php echo $title_color; ?>" oninput="updatePreview()" id="in-title-color" style="padding:2px; height:36px;" title="Color">
                    <input type="number" name="title_size" class="form-control" value="<?php echo $title_size; ?>" oninput="updatePreview()" id="in-title-size" title="Tamaño (Px)">
                    <input type="number" name="title_top" class="form-control" value="<?php echo $title_top; ?>" oninput="updatePreview()" id="in-title-top" title="Y ↕">
                    <input type="number" name="title_left" class="form-control" value="<?php echo $title_left; ?>" oninput="updatePreview()" id="in-title-left" title="X ↔">
                </div>
            </div>

            <div class="section-box">
                <label style="color:var(--ah-primary);"><i class="fa-solid fa-user-graduate"></i> Nombre del Estudiante</label>
                <div class="grid-5">
                    <select name="name_font" class="form-control" onchange="updatePreview()" id="in-name-font" style="grid-column: span 2;">
                        <option value="Great Vibes" <?php if($name_font=='Great Vibes') echo 'selected';?>>Great Vibes</option>
                        <option value="Alex Brush" <?php if($name_font=='Alex Brush') echo 'selected';?>>Alex Brush</option>
                        <option value="Dancing Script" <?php if($name_font=='Dancing Script') echo 'selected';?>>Dancing Script</option>
                        <option value="Montserrat" <?php if($name_font=='Montserrat') echo 'selected';?>>Montserrat</option>
                    </select>
                    <input type="color" name="name_color" class="form-control" value="<?php echo $name_color; ?>" oninput="updatePreview()" id="in-name-color" style="padding:2px; height:36px;">
                    <input type="number" name="name_size" class="form-control" value="<?php echo $name_size; ?>" oninput="updatePreview()" id="in-name-size">
                    <input type="number" name="name_top" class="form-control" value="<?php echo $name_top; ?>" oninput="updatePreview()" id="in-name-top">
                    <input type="number" name="name_left" class="form-control" value="<?php echo $name_left; ?>" oninput="updatePreview()" id="in-name-left">
                </div>
            </div>

            <div class="section-box">
                <label style="color:var(--ah-primary);"><i class="fa-solid fa-align-center"></i> Leyenda / Cuerpo</label>
                <textarea name="body_text" class="form-control" rows="3" oninput="updatePreview()" id="in-body-text" style="margin-top:8px;"><?php echo htmlspecialchars($body_text); ?></textarea>
                <div class="grid-4">
                    <input type="color" name="body_color" class="form-control" value="<?php echo $body_color; ?>" oninput="updatePreview()" id="in-body-color" style="padding:2px; height:36px; width:100%;">
                    <input type="number" name="body_size" class="form-control" value="<?php echo $body_size; ?>" oninput="updatePreview()" id="in-body-size" placeholder="Pts">
                    <input type="number" name="body_top" class="form-control" value="<?php echo $body_top; ?>" oninput="updatePreview()" id="in-body-top" placeholder="Y ↕">
                    <input type="number" name="body_left" class="form-control" value="<?php echo $body_left; ?>" oninput="updatePreview()" id="in-body-left" placeholder="X ↔">
                </div>
            </div>

            <div class="section-box">
                <label style="color:var(--ah-primary); margin-bottom:10px;"><i class="fa-solid fa-pen-nib"></i> Elementos Libres (Firmas y Sellos)</label>
                
                <div style="font-size:0.65rem; font-weight:bold;">Ancho Max Firmas (Px)</div>
                <input type="number" name="sig_width" class="form-control" value="<?php echo $sig_width; ?>" oninput="updatePreview()" id="in-sig-width" style="margin-bottom:15px; width:100px;">

                <!-- AUTORIDAD 1 -->
                <div style="padding:10px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:10px; background:white;">
                    <div style="font-size:0.75rem; font-weight:bold; margin-bottom:5px; color:var(--ah-primary);">Autoridad 1 (Izquierda)</div>
                    <input type="text" name="signature1_name" class="form-control" value="<?php echo htmlspecialchars($sig1_name); ?>" placeholder="Nombre" oninput="updatePreview()" id="in-sig1-name" style="margin-bottom:5px;">
                    <input type="text" name="signature1_role" class="form-control" value="<?php echo htmlspecialchars($sig1_role); ?>" placeholder="Cargo" oninput="updatePreview()" id="in-sig1-role" style="margin-bottom:5px;">
                    <input type="text" name="signature1_img" class="form-control" value="<?php echo htmlspecialchars($sig1_img); ?>" placeholder="URL Firma PNG" oninput="updatePreview()" id="in-sig1-img">
                    <div class="grid-2">
                        <div><div class="coord-label">Firma X ↔</div><input type="number" name="sig1_x" class="form-control" value="<?php echo $sig1_x; ?>" oninput="updatePreview()" id="in-sig1-x"></div>
                        <div><div class="coord-label">Firma Y ↕</div><input type="number" name="sig1_y" class="form-control" value="<?php echo $sig1_y; ?>" oninput="updatePreview()" id="in-sig1-y"></div>
                    </div>
                    
                    <hr style="margin:10px 0; border:none; border-top:1px dashed #cbd5e1;">
                    <input type="text" name="signature1_seal" class="form-control" value="<?php echo htmlspecialchars($sig1_seal); ?>" placeholder="URL Sello PNG" oninput="updatePreview()" id="in-seal1-img">
                    <div class="grid-2">
                        <div><div class="coord-label">Sello X ↔</div><input type="number" name="seal1_x" class="form-control" value="<?php echo $seal1_x; ?>" oninput="updatePreview()" id="in-seal1-x"></div>
                        <div><div class="coord-label">Sello Y ↕</div><input type="number" name="seal1_y" class="form-control" value="<?php echo $seal1_y; ?>" oninput="updatePreview()" id="in-seal1-y"></div>
                    </div>
                </div>

                <!-- AUTORIDAD 2 -->
                <div style="padding:10px; border:1px solid #cbd5e1; border-radius:6px; background:white;">
                    <div style="font-size:0.75rem; font-weight:bold; margin-bottom:5px; color:var(--ah-primary);">Autoridad 2 (Derecha)</div>
                    <input type="text" name="signature2_name" class="form-control" value="<?php echo htmlspecialchars($sig2_name); ?>" placeholder="Nombre" oninput="updatePreview()" id="in-sig2-name" style="margin-bottom:5px;">
                    <input type="text" name="signature2_role" class="form-control" value="<?php echo htmlspecialchars($sig2_role); ?>" placeholder="Cargo" oninput="updatePreview()" id="in-sig2-role" style="margin-bottom:5px;">
                    <input type="text" name="signature2_img" class="form-control" value="<?php echo htmlspecialchars($sig2_img); ?>" placeholder="URL Firma PNG" oninput="updatePreview()" id="in-sig2-img">
                    <div class="grid-2">
                        <div><div class="coord-label">Firma X ↔</div><input type="number" name="sig2_x" class="form-control" value="<?php echo $sig2_x; ?>" oninput="updatePreview()" id="in-sig2-x"></div>
                        <div><div class="coord-label">Firma Y ↕</div><input type="number" name="sig2_y" class="form-control" value="<?php echo $sig2_y; ?>" oninput="updatePreview()" id="in-sig2-y"></div>
                    </div>
                    
                    <hr style="margin:10px 0; border:none; border-top:1px dashed #cbd5e1;">
                    <input type="text" name="signature2_seal" class="form-control" value="<?php echo htmlspecialchars($sig2_seal); ?>" placeholder="URL Sello PNG" oninput="updatePreview()" id="in-seal2-img">
                    <div class="grid-2">
                        <div><div class="coord-label">Sello X ↔</div><input type="number" name="seal2_x" class="form-control" value="<?php echo $seal2_x; ?>" oninput="updatePreview()" id="in-seal2-x"></div>
                        <div><div class="coord-label">Sello Y ↕</div><input type="number" name="seal2_y" class="form-control" value="<?php echo $seal2_y; ?>" oninput="updatePreview()" id="in-seal2-y"></div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="width:100%; justify-content:center;"><i class="fa-solid fa-cloud-arrow-up"></i> Guardar Diseño PRO</button>
        </form>
    </aside>

    <main class="workspace-view">
        <h3 style="margin-top:0; color:#475569;"><i class="fa-solid fa-display"></i> Monitor de Impresión Vectorial (Escala real)</h3>
        
        <div class="cert-canvas" id="canvas">
            <!-- Textos -->
            <div class="cert-layer layer-title" id="lbl-title"></div>
            <div class="cert-layer layer-name" id="lbl-name">Nombre del Alumno Registrado</div>
            <div class="cert-layer layer-body" id="lbl-body">
                <span id="lbl-body-txt"></span>
                <span class="layer-course">"<?php echo htmlspecialchars($curso_title); ?>"</span>
            </div>

            <!-- ELEMENTOS FLOTANTES LIBRES -->
            <img src="" id="lbl-seal1" class="free-element sig-seal-img">
            <div class="free-element sig-block" id="block-sig1">
                <img src="" id="lbl-sig1-img" class="sig-img">
                <div class="sig-line" id="lbl-sig1-name"></div>
                <div class="sig-role" id="lbl-sig1-role"></div>
            </div>

            <img src="" id="lbl-seal2" class="free-element sig-seal-img">
            <div class="free-element sig-block" id="block-sig2">
                <img src="" id="lbl-sig2-img" class="sig-img">
                <div class="sig-line" id="lbl-sig2-name"></div>
                <div class="sig-role" id="lbl-sig2-role"></div>
            </div>

            <div class="meta-verification">Código Único de Autenticidad: SHA256-AH-<?php echo strtoupper(substr(md5($course_id),0,12)); ?>-2026</div>
        </div>
    </main>

    <script>
        function updatePreview() {
            const bg = document.getElementById('in-bg').value;
            document.getElementById('canvas').style.backgroundImage = bg ? `url('${bg}')` : 'none';

            // Textos
            const title = document.getElementById('lbl-title');
            title.innerText = document.getElementById('in-title-text').value;
            title.style.top = document.getElementById('in-title-top').value + 'px';
            title.style.left = document.getElementById('in-title-left').value + 'px'; 
            title.style.fontSize = document.getElementById('in-title-size').value + 'px';
            title.style.fontFamily = "'" + document.getElementById('in-title-font').value + "', sans-serif";
            title.style.color = document.getElementById('in-title-color').value;

            const name = document.getElementById('lbl-name');
            name.style.top = document.getElementById('in-name-top').value + 'px';
            name.style.left = document.getElementById('in-name-left').value + 'px'; 
            name.style.fontSize = document.getElementById('in-name-size').value + 'px';
            name.style.fontFamily = "'" + document.getElementById('in-name-font').value + "', cursive";
            name.style.color = document.getElementById('in-name-color').value;

            const body = document.getElementById('lbl-body');
            document.getElementById('lbl-body-txt').innerText = document.getElementById('in-body-text').value;
            body.style.top = document.getElementById('in-body-top').value + 'px';
            body.style.left = document.getElementById('in-body-left').value + 'px';
            body.style.fontSize = document.getElementById('in-body-size').value + 'px';
            body.style.color = document.getElementById('in-body-color').value;

            const sigWidth = document.getElementById('in-sig-width').value + 'px';

            // Elementos Libres 1
            const block1 = document.getElementById('block-sig1');
            block1.style.left = document.getElementById('in-sig1-x').value + 'px';
            block1.style.top = document.getElementById('in-sig1-y').value + 'px';
            const s1Img = document.getElementById('in-sig1-img').value;
            document.getElementById('lbl-sig1-img').src = s1Img;
            document.getElementById('lbl-sig1-img').style.display = s1Img ? 'block' : 'none';
            document.getElementById('lbl-sig1-img').style.maxWidth = sigWidth;
            document.getElementById('lbl-sig1-name').innerText = document.getElementById('in-sig1-name').value;
            document.getElementById('lbl-sig1-role').innerText = document.getElementById('in-sig1-role').value;

            const seal1 = document.getElementById('lbl-seal1');
            seal1.src = document.getElementById('in-seal1-img').value;
            seal1.style.display = seal1.src.includes('http') ? 'block' : 'none';
            seal1.style.left = document.getElementById('in-seal1-x').value + 'px';
            seal1.style.top = document.getElementById('in-seal1-y').value + 'px';

            // Elementos Libres 2
            const block2 = document.getElementById('block-sig2');
            block2.style.left = document.getElementById('in-sig2-x').value + 'px';
            block2.style.top = document.getElementById('in-sig2-y').value + 'px';
            const s2Img = document.getElementById('in-sig2-img').value;
            document.getElementById('lbl-sig2-img').src = s2Img;
            document.getElementById('lbl-sig2-img').style.display = s2Img ? 'block' : 'none';
            document.getElementById('lbl-sig2-img').style.maxWidth = sigWidth;
            document.getElementById('lbl-sig2-name').innerText = document.getElementById('in-sig2-name').value;
            document.getElementById('lbl-sig2-role').innerText = document.getElementById('in-sig2-role').value;

            const seal2 = document.getElementById('lbl-seal2');
            seal2.src = document.getElementById('in-seal2-img').value;
            seal2.style.display = seal2.src.includes('http') ? 'block' : 'none';
            seal2.style.left = document.getElementById('in-seal2-x').value + 'px';
            seal2.style.top = document.getElementById('in-seal2-y').value + 'px';
        }

        updatePreview();
    </script>
</body>
</html>