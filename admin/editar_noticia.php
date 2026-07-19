<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();

// Variables por defecto
$news_id = $_GET['id'] ?? "";
$title = "";
$slug = "";
$excerpt = "";
$content_html = "\n<p>Inicia tu redacción...</p>";
$cover_image = "";
$status = "published";
$meta_title = "";
$meta_description = "";
$galeria_actual = [];

// Si estamos editando, cargamos los datos
if (is_numeric($news_id)) {
    $stmt = $db->prepare("SELECT * FROM news WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $news_id);
    $stmt->execute();
    $n = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($n) {
        $title = $n['title'];
        $slug = $n['slug'];
        $excerpt = $n['excerpt'];
        $content_html = $n['content_html'];
        $cover_image = $n['cover_image'];
        $status = $n['status'];
        // Si ya tienes estos campos en la BD:
        $meta_title = $n['meta_title'] ?? '';
        $meta_description = $n['meta_description'] ?? '';
    }

    // Extraer imágenes de la galería
    $stmt_gal = $db->prepare("SELECT image_path FROM news_gallery WHERE news_id = :id");
    $stmt_gal->bindParam(':id', $news_id);
    $stmt_gal->execute();
    $galeria_actual = $stmt_gal->fetchAll(PDO::FETCH_COLUMN); // Extrae solo un arreglo de URLs
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Redactar Noticia | AH Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Fira+Code&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg: #f8fafc; --border: #e2e8f0; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        
        /* Top Bar */
        .top-bar { background: white; padding: 12px 25px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); z-index: 100; }
        .btn { padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; border: none; font-size: 0.9rem; transition: background 0.2s; }
        .btn-save { background: var(--ah-primary); color: white; }
        .btn-save:hover { background: #2c7285; }
        
        .main-layout { display: flex; flex-grow: 1; overflow: hidden; }
        
        /* Panel Lateral de Ajustes (Formulario) */
        .sidebar-settings { width: 350px; background: white; border-right: 1px solid var(--border); padding: 25px; overflow-y: auto; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.9rem; }
        
        .cover-preview { width: 100%; height: 150px; background: #f1f5f9; border-radius: 8px; margin-top: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; border: 2px dashed #cbd5e1; cursor: pointer; }
        .cover-preview img { width: 100%; height: 100%; object-fit: cover; }

        /* Panel del Editor Visual */
        /* Añadido overflow-y para que la galería se pueda scrollear si el texto es muy largo */
        .editor-container { flex-grow: 1; display: flex; flex-direction: column; background: #e2e8f0; overflow-y: auto; }
        .toolbar-top { background: #f1f5f9; padding: 10px; border-bottom: 1px solid var(--border); display: flex; gap: 10px; position: sticky; top: 0; z-index: 10; }
        /* Añadido min-height para que no colapse al agregar la galería */
        .editor-frame { flex-grow: 1; width: 100%; border: none; background: white; min-height: 500px; } 

        /* Estilos de la nueva Galería */
        .gallery-section { background: white; padding: 25px; border-top: 1px solid var(--border); }
        .gallery-preview-container { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px; }
        .gallery-thumb-wrapper { position: relative; width: 100px; height: 100px; }
        .gallery-thumb-wrapper img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; border: 2px solid #cbd5e1; }
        .gallery-thumb-remove { position: absolute; top: -8px; right: -8px; background: #ef4444; color: white; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; font-size: 12px; font-weight: bold; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    </style>
</head>
<body>

    <div class="top-bar">
        <div style="display:flex; align-items:center; gap:15px;">
            <a href="noticias.php" style="color:#64748b;"><i class="fa-solid fa-arrow-left"></i></a>
            <h2 style="font-size:1.1rem; color:#1e293b;">Redactor de Noticias</h2>
        </div>
        <button class="btn btn-save" onclick="guardarNoticia()" id="btn-save">
            <i class="fa-solid fa-paper-plane"></i> Guardar y Publicar
        </button>
    </div>

    <div class="main-layout">
        <aside class="sidebar-settings">
            <input type="hidden" id="news-id" value="<?php echo $news_id; ?>">
            
            <div class="form-group">
                <label>Título de la Noticia</label>
                <input type="text" id="news-title" class="form-control" value="<?php echo htmlspecialchars($title); ?>" oninput="generateSlug(this.value)">
            </div>

            <div class="form-group">
                <label>Slug (URL)</label>
                <input type="text" id="news-slug" class="form-control" value="<?php echo htmlspecialchars($slug); ?>">
            </div>

            <div class="form-group">
                <label>Resumen Corto (Excerpt)</label>
                <textarea id="news-excerpt" class="form-control" rows="3" style="resize:none;"><?php echo htmlspecialchars($excerpt); ?></textarea>
            </div>

            <div class="form-group">
                <label>Imagen de Portada</label>
                <div class="cover-preview" onclick="document.getElementById('cover-uploader').click()">
                    <?php if($cover_image): ?>
                        <img src="<?php echo $cover_image; ?>" id="img-preview">
                    <?php else: ?>
                        <div id="placeholder"><i class="fa-solid fa-cloud-arrow-up"></i> Subir Portada</div>
                    <?php endif; ?>
                </div>
                <input type="file" id="cover-uploader" style="display:none" accept="image/*" onchange="uploadCover(this)">
                <input type="hidden" id="news-cover" value="<?php echo $cover_image; ?>">
            </div>

            <div class="form-group">
                <label>Estado</label>
                <select id="news-status" class="form-control">
                    <option value="published" <?php if($status=='published') echo 'selected'; ?>>Publicado</option>
                    <option value="draft" <?php if($status=='draft') echo 'selected'; ?>>Borrador</option>
                </select>
            </div>

            <!-- NUEVO MÓDULO SEO -->
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border);">
                <div class="form-group">
                    <label style="color: #0f766e;"><i class="fa-solid fa-magnifying-glass"></i> Meta Título (SEO)</label>
                    <input type="text" id="news-meta-title" class="form-control" placeholder="Título corto para Google" value="<?php echo htmlspecialchars($meta_title); ?>">
                </div>

                <div class="form-group">
                    <label>Meta Descripción (SEO)</label>
                    <textarea id="news-meta-desc" class="form-control" rows="3" placeholder="Resumen atractivo para buscadores..." style="resize:none;"><?php echo htmlspecialchars($meta_description); ?></textarea>
                </div>
            </div>
        </aside>

        <section class="editor-container">
            <div class="toolbar-top">
                <button class="btn" style="padding:5px 10px; font-size:0.75rem; background:#fff; border:1px solid #cbd5e1;" onclick="insertB('text')">+ Párrafo</button>
                <button class="btn" style="padding:5px 10px; font-size:0.75rem; background:#fff; border:1px solid #cbd5e1;" onclick="insertB('img')">+ Imagen</button>
                <div style="flex-grow:1"></div>
                <button class="btn" style="padding:5px 10px; font-size:0.75rem; background:#1e293b; color:white;" onclick="toggleCode()"><i class="fa-solid fa-code"></i> Ver Código</button>
            </div>
            
            <iframe id="editor-visual" class="editor-frame"></iframe>
            <textarea id="code-editor" style="display:none;" class="editor-frame"><?php echo htmlspecialchars($content_html); ?></textarea>
            
            <!-- NUEVA GALERÍA DE FOTOGRAFÍAS -->
            <div class="gallery-section">
                <h3 style="font-size: 1rem; color: #1e293b; margin-bottom: 5px;"><i class="fa-solid fa-images" style="color:#94a3b8;"></i> Galería de Fotografías</h3>
                <p style="font-size: 0.85rem; color: #64748b;">Aparecerá al final del artículo. Puedes seleccionar múltiples imágenes a la vez.</p>
                
                <div class="gallery-preview-container" id="gallery-preview-container">
                    <!-- Las miniaturas se dibujarán aquí -->
                </div>
                
                <button class="btn" style="background: #f1f5f9; color: #1e293b; border: 1px dashed #94a3b8; margin-top: 15px; width: 100%; padding: 15px;" onclick="document.getElementById('gallery-uploader').click()">
                    <i class="fa-solid fa-plus"></i> Añadir Imágenes a la Galería
                </button>
                <input type="file" id="gallery-uploader" multiple accept="image/*" style="display:none" onchange="uploadGallery(this)">
            </div>
        </section>
    </div>

    <input type="file" id="media-uploader" style="display:none" accept="image/*">

    <script>
       // Token CSRF global
        const CSRF_TOKEN = "<?php echo Auth::generateCSRF(); ?>";

        const visual = document.getElementById('editor-visual');
        const code = document.getElementById('code-editor');
        let upId = null;

        // --- ARREGLO GLOBAL PARA LA GALERÍA ---
        // Cargamos las imágenes existentes desde PHP
        let galleryImages = <?php echo json_encode($galeria_actual); ?>;

        // 1. Inyectar motor visual en el iframe
        function updateVisual() {
            const htmlContent = code.value;
            const doc = `
                <html>
                <head>
                    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
                    <style>
                        body { padding: 40px; font-family: 'Inter', sans-serif; line-height: 1.8; color: #334155; }
                        [contenteditable="true"]:hover { outline: 2px dashed #34859B; }
                        img { max-width: 100%; border-radius: 8px; margin: 20px 0; cursor: pointer; }
                    </style>
                </head>
                <body contenteditable="true">
                    ${htmlContent}
                    <script>
                        document.body.oninput = () => { window.parent.updateCode(document.body.innerHTML); };
                        document.querySelectorAll('img').forEach(img => {
                            img.ondblclick = () => {
                                if(!img.id) img.id = 'img-' + Date.now();
                                window.parent.triggerMedia(img.id);
                            };
                        });
                    <\/script>
                </body>
                </html>
            `;
            visual.srcdoc = doc;
        }

        window.updateCode = (h) => { code.value = h; };

        // 2. Subida de Portada
        async function uploadCover(input) {
            if(!input.files[0]) return;
            const fd = new FormData();
            fd.append('file', input.files[0]);
            
            const res = await fetch('../api/upload_media.php', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                document.getElementById('news-cover').value = data.url;
                document.querySelector('.cover-preview').innerHTML = `<img src="${data.url}">`;
            } else {
                alert("Error al subir portada: " + (data.error || 'Desconocido'));
            }
        }

        // 3. Subida de Imágenes dentro del contenido
        window.triggerMedia = (id) => { upId = id; document.getElementById('media-uploader').click(); };
        document.getElementById('media-uploader').onchange = async (e) => {
            if(!e.target.files[0]) return;
            const fd = new FormData(); 
            fd.append('file', e.target.files[0]);
            
            const res = await fetch('../api/upload_media.php', { method: 'POST', body: fd });
            const d = await res.json();
            if(d.success) {
                const img = visual.contentWindow.document.getElementById(upId);
                if(img) img.src = d.url;
                updateCode(visual.contentWindow.document.body.innerHTML);
            } else {
                alert("Error al subir imagen: " + (d.error || 'Desconocido'));
            }
        };

        // --- 4. NUEVA LÓGICA DE GALERÍA ---
        function renderGallery() {
            const container = document.getElementById('gallery-preview-container');
            container.innerHTML = '';
            galleryImages.forEach((url, index) => {
                container.innerHTML += `
                    <div class="gallery-thumb-wrapper">
                        <img src="${url}" title="Imagen de Galería">
                        <button class="gallery-thumb-remove" onclick="removeGalleryImage(${index})" title="Eliminar imagen"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                `;
            });
        }

        async function uploadGallery(input) {
            if(!input.files || input.files.length === 0) return;
            
            // Subir cada imagen asincronamente usando el mismo motor
            for(let i=0; i<input.files.length; i++) {
                const fd = new FormData();
                fd.append('file', input.files[i]);
                
                try {
                    const res = await fetch('../api/upload_media.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if(data.success) {
                        galleryImages.push(data.url);
                    }
                } catch(e) {
                    console.error("Error subiendo imagen de galería", e);
                }
            }
            renderGallery();
            input.value = ''; // Limpiar el input para permitir volver a elegir la misma foto si se desea
        }

        function removeGalleryImage(index) {
            galleryImages.splice(index, 1);
            renderGallery();
        }

        // 5. Utilidades
        function generateSlug(t) {
            const s = t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
            document.getElementById('news-slug').value = s;
        }

        function insertB(t) {
            if(t === 'text') code.value += `\n<h2>Subtítulo</h2><p>Nuevo párrafo para la noticia...</p>`;
            if(t === 'img') code.value += `\n<img src="https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?q=80&w=800">`;
            updateVisual();
        }

        function toggleCode() {
            const isVisible = code.style.display === 'block';
            code.style.display = isVisible ? 'none' : 'block';
            visual.style.display = isVisible ? 'block' : 'none';
            if(isVisible) updateVisual();
        }

        // 6. GUARDAR NOTICIA (API) - ACTUALIZADO CON SEO Y GALERÍA
        async function guardarNoticia() {
            const btn = document.getElementById('btn-save');
            
            const payload = {
                id: document.getElementById('news-id').value,
                title: document.getElementById('news-title').value,
                slug: document.getElementById('news-slug').value,
                excerpt: document.getElementById('news-excerpt').value,
                cover_image: document.getElementById('news-cover').value,
                content_html: code.value,
                status: document.getElementById('news-status').value,
                meta_title: document.getElementById('news-meta-title').value, // NUEVO SEO
                meta_description: document.getElementById('news-meta-desc').value, // NUEVO SEO
                gallery: galleryImages, // NUEVA GALERIA (Arreglo de URLs)
                csrf_token: CSRF_TOKEN 
            };

            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando...';
            
            try {
                const res = await fetch('../api/save_news.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const data = await res.json();
                
                if(data.success) {
                    alert("Noticia guardada con éxito.");
                    window.location.href = 'noticias.php';
                } else {
                    alert("Error: " + data.error);
                    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Guardar y Publicar';
                }
            } catch (error) {
                console.error("Error en la petición:", error);
                alert("Error de conexión. Revisa la consola para más detalles.");
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Guardar y Publicar';
            }
        }

        // Inicializar vistas al cargar
        window.onload = () => {
            updateVisual();
            renderGallery();
        };
    </script>
</body>
</html>