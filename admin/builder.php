<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

$page_id = $_GET['id'] ?? "";
$page_title = "Nueva Página";
$page_slug = "nueva-pagina";
$content_html = "";
// Nuevas variables para SEO
$meta_description = "";
$meta_image = "";

if (is_numeric($page_id)) {
    // 1. Añadimos la lectura de los campos meta
    $stmt = $db->prepare("SELECT title, slug, content_html, meta_description, meta_image FROM pages WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $page_id);
    $stmt->execute();
    $page_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($page_data) {
        $page_title = $page_data['title'];
        $page_slug = $page_data['slug'];
        $content_html = $page_data['content_html'];
        $meta_description = $page_data['meta_description'];
        $meta_image = $page_data['meta_image'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Builder Pro | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --ah-accent: #46B094; --bg-dark: #0f172a; --border: #e2e8f0; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; display: flex; flex-direction: column; height: 100vh; background: #f8fafc; overflow: hidden; }
        
        /* Layout Superior */
        .top-bar { background: white; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); z-index: 100; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .workspace { display: flex; flex-grow: 1; overflow: hidden; }
        
        /* Controles Meta */
        .title-input { font-size: 1.1rem; font-weight: 700; border: none; outline: none; width: 180px; }
        .slug-input { font-size: 0.8rem; border: none; outline: none; color: #64748b; width: 180px; }
        
        /* Botones de Inserción */
        .btn-group { display: flex; gap: 5px; flex-wrap: wrap; max-width: 600px; }
        .btn { padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.8rem; border: 1px solid var(--border); background: white; transition: 0.2s; color: #334155; }
        .btn:hover { background: #f1f5f9; border-color: var(--ah-primary); color: var(--ah-primary); }
        .btn-primary { background: var(--ah-primary); color: white; border: none; font-size: 0.9rem; padding: 10px 20px; }
        .btn-primary:hover { background: #2c7285; color: white; }
        
        /* Panel SEO Flotante */
        .seo-panel { position: absolute; top: 65px; right: 20px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: 1px solid var(--border); width: 320px; display: none; flex-direction: column; gap: 15px; z-index: 200; }
        .seo-panel.active { display: flex; }
        .seo-panel label { font-size: 0.8rem; font-weight: 700; color: #475569; display: block; margin-bottom: 5px; }
        .seo-panel textarea { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; resize: none; font-family: inherit; font-size: 0.85rem; }
        .seo-img-preview { width: 100%; height: 140px; object-fit: cover; border-radius: 6px; background: #f1f5f9; border: 1px dashed #cbd5e1; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #94a3b8; font-size: 0.85rem; transition: 0.2s; background-position: center; }
        .seo-img-preview:hover { border-color: var(--ah-primary); color: var(--ah-primary); background-color: #f8fafc; }

        /* Paneles */
        .editor-panel { width: 35%; display: flex; flex-direction: column; background: var(--bg-dark); border-right: 1px solid #334155; }
        .code-area { flex-grow: 1; background: var(--bg-dark); color: #e2e8f0; font-family: 'Fira Code', monospace; font-size: 13px; padding: 15px; border: none; resize: none; outline: none; line-height: 1.5; }
        
        .preview-panel { width: 65%; background: #e2e8f0; position: relative; }
        .preview-frame { width: 100%; height: 100%; border: none; background: white; }
    </style>
</head>
<body>

    <div class="top-bar">
        <div style="display: flex; align-items: center; gap: 15px;">
            <a href="index.php" style="color: #64748b; font-size: 1.2rem;"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="page-meta">
                <input type="hidden" id="page-id" value="<?php echo $page_id; ?>">
                <input type="text" id="page-title" class="title-input" value="<?php echo htmlspecialchars($page_title); ?>">
                <input type="text" id="page-slug" class="slug-input" value="<?php echo htmlspecialchars($page_slug); ?>">
            </div>
            <div class="btn-group">
                <button class="btn" onclick="addB('hero')"><i class="fa-solid fa-heading"></i> Hero</button>
                <button class="btn" onclick="addB('slider')"><i class="fa-solid fa-images"></i> Carrusel</button>
                <button class="btn" onclick="addB('cols')"><i class="fa-solid fa-columns"></i> Rejilla</button>
                <button class="btn" onclick="addB('gallery')"><i class="fa-solid fa-border-all"></i> Galería</button>
                <button class="btn" onclick="addB('video')"><i class="fa-brands fa-youtube"></i> Video</button>
            </div>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button class="btn" onclick="document.getElementById('seo-modal').classList.toggle('active')"><i class="fa-solid fa-share-nodes" style="color: var(--ah-primary);"></i> SEO Social</button>
            <button class="btn btn-primary" onclick="saveP()" id="save-btn"><i class="fa-solid fa-save"></i> Guardar</button>
        </div>
    </div>

    <div class="seo-panel" id="seo-modal">
        <h3 style="font-size: 0.95rem; color: #0f172a; margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 10px;"><i class="fa-solid fa-earth-americas" style="color: var(--ah-accent);"></i> Meta y Open Graph</h3>
        
        <div>
            <label>Descripción para Enlaces (WhatsApp/Facebook)</label>
            <textarea id="meta-desc" rows="3" placeholder="Escribe un resumen atractivo..."><?php echo htmlspecialchars($meta_description); ?></textarea>
        </div>
        
        <div>
            <label>Imagen de Portada (Clic para cambiar)</label>
            <input type="hidden" id="meta-img-val" value="<?php echo htmlspecialchars($meta_image); ?>">
            <input type="file" id="seo-uploader-file" style="display:none" accept="image/*">
            
            <?php 
                $bg_style = !empty($meta_image) ? "background-image: url('".htmlspecialchars($meta_image)."');" : ""; 
            ?>
            <div class="seo-img-preview" id="seo-img-preview" style="<?php echo $bg_style; ?>">
                <?php echo empty($meta_image) ? '<i class="fa-solid fa-cloud-arrow-up" style="margin-right:8px;"></i> Subir portada' : ''; ?>
            </div>
        </div>
    </div>

    <div class="workspace">
        <div class="editor-panel">
            <div style="background: #1e293b; color: #94a3b8; padding: 5px 15px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase;">HTML Puro</div>
            <textarea id="code-editor" class="code-area" spellcheck="false"><?php echo htmlspecialchars($content_html); ?></textarea>
        </div>
        <div class="preview-panel">
            <iframe id="live-preview" class="preview-frame"></iframe>
        </div>
    </div>

    <input type="file" id="uploader" style="display:none" accept="image/*">

    <script>
        const editor = document.getElementById('code-editor');
        const preview = document.getElementById('live-preview');
        const uploader = document.getElementById('uploader');
        let isVisual = false;
        let upId = null;
        let upType = null;

        // ===============================================
        // 1. LÓGICA DE SUBIDA PARA LA IMAGEN SEO
        // ===============================================
        const seoImgBtn = document.getElementById('seo-img-preview');
        const seoFileInp = document.getElementById('seo-uploader-file');
        const metaImgVal = document.getElementById('meta-img-val');

        seoImgBtn.onclick = () => seoFileInp.click();

        seoFileInp.onchange = async (e) => {
            const f = e.target.files[0];
            if(!f) return;
            const fd = new FormData(); fd.append('file', f);
            seoImgBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...';
            seoImgBtn.style.backgroundImage = 'none';
            
            const r = await fetch('../api/upload_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            
            if(d.success) {
                // Quitamos el '../' si la API lo devuelve, para guardar la ruta limpia en BD
                const cleanUrl = d.url.replace('../', '');
                metaImgVal.value = cleanUrl; 
                seoImgBtn.style.backgroundImage = "url('" + d.url + "')";
                seoImgBtn.innerHTML = '';
            } else {
                seoImgBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Error';
            }
            e.target.value = '';
        };

        // ===============================================
        // PLANTILLAS Y MOTOR DEL MAQUETADOR
        // ===============================================
        function addB(t) {
            const temps = {
                hero: `<section class="layout-block" style="background-image:url('https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?q=80&w=2000'); padding:120px 20px; text-align:center; color:white; background-size:cover; background-position:center;">\n  <h1 style="font-size:3.5rem; font-weight:800; margin-bottom:20px;">Título de Impacto</h1>\n  <p style="font-size:1.2rem; opacity:0.9;">Descripción heroica para la organización.</p>\n</section>\n`,
                slider: `<section class="layout-block ah-slider-container" style="position:relative; height:600px; overflow:hidden; background:#1e293b;">\n  <div class="ah-slides" style="display:flex; height:100%; transition: transform 0.5s ease;">\n    <div class="layout-block ah-slide" style="min-width:100%; background:linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?q=80&w=2000') center/cover; display:flex; flex-direction:column; align-items:center; justify-content:center; color:white;">\n      <h2 style="font-size:4rem; font-weight:bold;">Visión Sostenible</h2>\n      <p style="font-size:1.2rem;">Acción climática en Francisco Morazán</p>\n    </div>\n    <div class="layout-block ah-slide" style="min-width:100%; background:linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('https://images.unsplash.com/photo-1529390079861-591de354faf5?q=80&w=2000') center/cover; display:flex; flex-direction:column; align-items:center; justify-content:center; color:white;">\n      <h2 style="font-size:4rem; font-weight:bold;">Liderazgo Juvenil</h2>\n      <p style="font-size:1.2rem;">Formando a las nuevas generaciones</p>\n    </div>\n  </div>\n  <button class="slider-btn" onclick="this.parentElement.querySelector('.ah-slides').style.transform='translateX(0%)'" style="position:absolute; left:20px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.2); border:none; color:white; width:50px; height:50px; cursor:pointer; border-radius:50%; backdrop-filter:blur(5px);"><i class="fa-solid fa-chevron-left"></i></button>\n  <button class="slider-btn" onclick="this.parentElement.querySelector('.ah-slides').style.transform='translateX(-100%)'" style="position:absolute; right:20px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.2); border:none; color:white; width:50px; height:50px; cursor:pointer; border-radius:50%; backdrop-filter:blur(5px);"><i class="fa-solid fa-chevron-right"></i></button>\n</section>\n`,
                cols: `<section class="layout-block" style="padding:80px 20px; display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:40px; max-width:1200px; margin:0 auto; align-items:center;">\n  <div class="layout-block"><img src="https://images.unsplash.com/photo-1529390079861-591de354faf5?q=80&w=800" style="width:100%; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.1);" class="editable-img"></div>\n  <div class="layout-block"><h2 style="color:#34859B; font-size:2rem; margin-bottom:15px;">Nuestra Misión</h2><p style="color:#475569; line-height:1.7;">Describe aquí los detalles del proyecto de forma extensa.</p></div>\n</section>\n`,
                gallery: `<section class="layout-block" style="padding:60px 20px; max-width:1200px; margin:0 auto;">\n  <h2 style="text-align:center; color:#1e293b; margin-bottom:40px; font-size:2rem;">Galería de Impacto</h2>\n  <div class="layout-block" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px;">\n    <img src="https://images.unsplash.com/photo-1529390079861-591de354faf5?q=80&w=800" style="width:100%; height:250px; object-fit:cover; border-radius:10px;" class="editable-img">\n    <img src="https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?q=80&w=800" style="width:100%; height:250px; object-fit:cover; border-radius:10px;" class="editable-img">\n    <img src="https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?q=80&w=800" style="width:100%; height:250px; object-fit:cover; border-radius:10px;" class="editable-img">\n  </div>\n</section>\n`,
                video: `<section class="layout-block" style="padding:80px 20px; max-width:900px; margin:0 auto;">\n  <h2 style="text-align:center; color:#34859B; margin-bottom:30px;">Conoce el Proyecto</h2>\n  <div class="layout-block" style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; border-radius:16px; box-shadow:0 20px 40px rgba(0,0,0,0.15);">\n    <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" style="position:absolute; top:0; left:0; width:100%; height:100%; border:none;" allowfullscreen></iframe>\n  </div>\n</section>\n`
            };
            editor.value += temps[t];
            upPrev();
        }

        function upPrev() {
            if (isVisual) return;
            const html = editor.value;
            const doc = `
    <html>
    <head>
        <link rel="stylesheet" href="../public/css/style.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { margin: 0; padding-bottom: 400px; font-family: 'Inter', sans-serif; overflow-x: hidden; }
            [contenteditable="true"]:hover, .layout-block:hover { outline: 2px dashed #34859B; outline-offset: -2px; background: rgba(52,133,155,0.03); }
            .editable-img:hover { outline: 3px dashed #46B094; outline-offset: -3px; cursor: pointer; }
            
            #ah-tools { 
                position: absolute; display: none; background: #0f172a; padding: 8px; 
                border-radius: 8px; z-index: 10000; gap: 8px; align-items: center; 
                box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid #334155;
                flex-wrap: wrap; width: max-content; max-width: 650px;
            }
            .t-group { display: flex; align-items: center; gap: 4px; border-right: 1px solid #334155; padding-right: 8px; }
            .t-group:last-child { border: none; padding-right: 0; }
            .t-btn { background: #1e293b; border: 1px solid #334155; color: #cbd5e1; cursor: pointer; padding: 6px 10px; border-radius: 4px; font-size: 12px; transition: 0.2s; }
            .t-btn:hover { background: #34859B; color: white; border-color: #34859B; }
            .t-input { width: 50px; background: #1e293b; border: 1px solid #334155; color: white; font-size: 11px; text-align: center; border-radius: 4px; padding: 4px; }
            .t-color { width: 25px; height: 25px; border: none; background: none; cursor: pointer; padding: 0; }
            .t-label { color: #94a3b8; font-size: 9px; font-weight: bold; text-transform: uppercase; display: flex; flex-direction: column; align-items: center; gap: 2px; }
            input[type=range] { width: 60px; accent-color: #46B094; }
        </style>
    </head>
    <body>
        ${html}
        
        <div id="ah-tools">
            <div class="t-group" id="grp-text">
                <button class="t-btn" data-c="bold" title="Negrita"><i class="fa-solid fa-bold"></i></button>
                <button class="t-btn" data-c="italic"><i class="fa-solid fa-italic"></i></button>
                <button class="t-btn" data-c="justifyCenter" title="Centrar"><i class="fa-solid fa-align-center"></i></button>
                <input type="color" id="i-col" class="t-color" title="Color de Texto">
            </div>

            <div class="t-group" id="grp-img" style="display:none; background: rgba(70, 176, 148, 0.15); padding: 4px 8px; border-radius: 6px;">
                <label class="t-label"><i class="fa-solid fa-sun"></i><input type="range" id="i-bri" min="10" max="200" value="100"></label>
                <label class="t-label"><i class="fa-solid fa-arrows-up-down"></i><input type="range" id="i-posy" min="0" max="100" value="50"></label>
                <button class="t-btn" id="i-fill" title="Expandir/Ajustar"><i class="fa-solid fa-expand"></i></button>
            </div>

            <div class="t-group">
                <label class="t-label">Size <input type="text" id="i-size" class="t-input"></label>
                <label class="t-label">Pad <input type="text" id="i-pad" class="t-input"></label>
                <label class="t-label">Mar <input type="text" id="i-mar" class="t-input"></label>
                <label class="t-label">Rad <input type="text" id="i-rad" class="t-input"></label>
            </div>

            <div class="t-group" style="background: rgba(52, 133, 155, 0.1); padding: 4px 8px; border-radius: 6px;">
                <button class="t-btn" id="i-add-grid" title="Anidar Columnas"><i class="fa-solid fa-table-columns"></i></button>
                <button class="t-btn" id="i-dup" title="Duplicar Bloque/Imagen"><i class="fa-solid fa-copy"></i></button>
                <div style="display:flex; flex-direction:column; gap:2px; margin-left:5px;">
                    <button class="t-btn" id="i-up" title="Mover Arriba" style="padding:2px 8px; font-size:10px; background:#334155;"><i class="fa-solid fa-arrow-up"></i></button>
                    <button class="t-btn" id="i-down" title="Mover Abajo" style="padding:2px 8px; font-size:10px; background:#334155;"><i class="fa-solid fa-arrow-down"></i></button>
                </div>
            </div>

            <div class="t-group">
                <button id="i-link" class="t-btn" title="Enlace"><i class="fa-solid fa-link"></i></button>
                <button id="i-del" class="t-btn" style="color:#ef4444; border-color:#ef4444;" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </div>
        </div>

        <script>
            let el = null;
            const tools = document.getElementById('ah-tools');

            document.querySelectorAll('h1, h2, h3, h4, p, span, a').forEach(t => t.contentEditable = true);
            document.querySelectorAll('section, div, article').forEach(d => d.classList.add('layout-block'));

            document.querySelectorAll('*').forEach(i => {
                if(window.getComputedStyle(i).backgroundImage !== 'none' || i.tagName === 'IMG') {
                    i.classList.add('editable-img');
                    i.ondblclick = e => {
                        e.stopPropagation();
                        if(!i.id) i.id = 'ah-' + Date.now();
                        window.parent.openUp(i.id, i.tagName === 'IMG' ? 'src' : 'bg');
                    };
                }
            });

            document.body.onclick = e => {
                if(e.target.closest('#ah-tools') || e.target === document.body) return;
                e.stopPropagation();
                el = e.target;
                
                const r = el.getBoundingClientRect();
                tools.style.display = 'flex';
                tools.style.top = Math.max(10, r.top + window.scrollY - 90) + 'px';
                tools.style.left = Math.max(10, r.left) + 'px';

                const isImg = el.tagName === 'IMG' || window.getComputedStyle(el).backgroundImage !== 'none';
                document.getElementById('grp-img').style.display = isImg ? 'flex' : 'none';
                document.getElementById('grp-text').style.display = (isImg && el.tagName === 'IMG') ? 'none' : 'flex';

                if(el.tagName === 'IFRAME') {
                    const newUrl = prompt("Pega la URL de Embed de YouTube:", el.src);
                    if(newUrl) { el.src = newUrl; sync(); }
                }

                const s = window.getComputedStyle(el);
                document.getElementById('i-pad').value = el.style.padding || s.padding;
                document.getElementById('i-mar').value = el.style.margin || s.margin;
                document.getElementById('i-size').value = el.style.fontSize || s.fontSize;
                document.getElementById('i-rad').value = el.style.borderRadius || s.borderRadius;
            };

            document.querySelectorAll('.t-btn[data-c]').forEach(b => {
                b.onclick = () => { document.execCommand(b.dataset.c, false, null); sync(); };
            });

            document.getElementById('i-bri').oninput = e => { el.style.filter = \`brightness(\${e.target.value}%)\`; sync(); };
            document.getElementById('i-posy').oninput = e => { 
                if(el.tagName === 'IMG') el.style.objectPosition = \`center \${e.target.value}%\`;
                else el.style.backgroundPosition = \`center \${e.target.value}%\`;
                sync();
            };
            document.getElementById('i-fill').onclick = () => {
                if(el.tagName === 'IMG') el.style.objectFit = el.style.objectFit === 'cover' ? 'contain' : 'cover';
                else el.style.backgroundSize = window.getComputedStyle(el).backgroundSize === 'cover' ? 'contain' : 'cover';
                sync();
            };

            const repo = () => {
                const r = el.getBoundingClientRect();
                tools.style.top = Math.max(10, r.top + window.scrollY - 90) + 'px';
                tools.style.left = Math.max(10, r.left) + 'px';
            };

            document.getElementById('i-up').onclick = (e) => {
                e.stopPropagation();
                if(el && el.previousElementSibling) { el.parentNode.insertBefore(el, el.previousElementSibling); repo(); sync(); }
            };
            document.getElementById('i-down').onclick = (e) => {
                e.stopPropagation();
                if(el && el.nextElementSibling) { el.parentNode.insertBefore(el, el.nextElementSibling.nextElementSibling); repo(); sync(); }
            };

            document.getElementById('i-dup').onclick = (e) => {
                e.stopPropagation();
                const clone = el.cloneNode(true);
                el.parentNode.insertBefore(clone, el.nextSibling);
                sync();
            };
            document.getElementById('i-add-grid').onclick = (e) => {
                e.stopPropagation();
                el.innerHTML += \`<div class="layout-block" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; padding:20px; border:1px dashed #cbd5e1; margin-top:20px;"><div style="padding:10px;">Bloque 1</div><div style="padding:10px;">Bloque 2</div></div>\`;
                sync();
            };

            const uS = (id, p) => { document.getElementById(id).oninput = e => { if(el) { el.style[p] = e.target.value; sync(); } }; };
            uS('i-col', 'color'); uS('i-size', 'fontSize'); uS('i-pad', 'padding'); uS('i-mar', 'margin'); uS('i-rad', 'borderRadius');

            document.getElementById('i-link').onclick = () => { const u = prompt("URL:", "https://"); if(u) { document.execCommand("createLink", false, u); sync(); } };
            document.getElementById('i-del').onclick = () => { if(confirm("¿Borrar esto?")) { el.remove(); tools.style.display='none'; sync(); } };

            document.body.oninput = () => sync();

            function sync() {
                const c = document.body.cloneNode(true);
                const t = c.querySelector('#ah-tools'); if(t) t.remove();
                c.querySelectorAll('[contenteditable]').forEach(x => x.removeAttribute('contenteditable'));
                c.querySelectorAll('.layout-block').forEach(x => x.classList.remove('layout-block'));
                c.querySelectorAll('.editable-img').forEach(x => { x.classList.remove('editable-img'); if(x.style.border === '1px dashed #cbd5e1') x.style.border = 'none'; });
                c.querySelectorAll('script').forEach(s => s.remove());
                c.querySelectorAll('*').forEach(x => { if(!x.className) x.removeAttribute('class'); });
                
                window.parent.fV(c.innerHTML.trim());
            }

            window.upImg = (id, url, type) => {
                const target = document.getElementById(id);
                if(type === 'src') target.src = url; else target.style.backgroundImage = "url('" + url + "')";
                sync();
            };
        <\/script>
    </body>
    </html>
`;
            preview.srcdoc = doc;
        }

        window.fV = (c) => { isVisual = true; editor.value = c; isVisual = false; };
        window.openUp = (id, t) => { upId = id; upType = t; uploader.click(); };

        uploader.onchange = async (e) => {
            const f = e.target.files[0];
            const fd = new FormData(); fd.append('file', f);
            document.getElementById('save-btn').innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...';
            
            const r = await fetch('../api/upload_media.php', { method: 'POST', body: fd });
            const d = await r.json();
            if(d.success) preview.contentWindow.upImg(upId, d.url, upType);
            
            document.getElementById('save-btn').innerHTML = '<i class="fa-solid fa-save"></i> Guardar Código';
            e.target.value = '';
        };

        // ===============================================
        // 2. ACTUALIZACIÓN DEL PAYLOAD PARA GUARDAR EL SEO
        // ===============================================
        async function saveP() {
            const btn = document.getElementById('save-btn');
            
            const payload = {
                page_id: document.getElementById('page-id').value,
                title: document.getElementById('page-title').value,
                slug: document.getElementById('page-slug').value,
                content_html: editor.value,
                
                // Nuevos campos agregados al paquete de envío JSON
                meta_description: document.getElementById('meta-desc').value,
                meta_image: document.getElementById('meta-img-val').value
            };
            
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
            
            const res = await fetch('../api/save_page.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
            const d = await res.json();
            
            if(d.success) {
                btn.innerHTML = '<i class="fa-solid fa-check"></i> ¡Guardado!';
                btn.style.background = '#46B094';
                if(d.page_id) document.getElementById('page-id').value = d.page_id;
            } else {
                alert("Error: " + d.error);
            }
            setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-save"></i> Guardar'; btn.style.background = ''; }, 2000);
        }

        editor.oninput = upPrev;
        window.onload = upPrev;
    </script>
</body>
</html>