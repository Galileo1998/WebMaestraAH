/**
 * MOTOR DE CONSTRUCCIÓN VISUAL - ACCIÓN HONDURAS
 * Maneja la sincronización bidireccional y la gestión de archivos.
 */

const editor = document.getElementById('code-editor');
const preview = document.getElementById('live-preview');
const uploader = document.getElementById('media-uploader');
let isUpdatingFromVisual = false;
let activeElementId = null;
let activeUploadType = null;

// --- 1. GESTIÓN DE BLOQUES (COMPONENTES) ---
function insertarBloque(tipo) {
    const templates = {
        hero: `
<section class="home-hero" style="background-image: url('https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?q=80&w=2000'); padding: 120px 20px; text-align: center; color: white; background-size: cover; background-position: center;">
  <div style="max-width: 800px; margin: 0 auto;">
    <h1 style="font-size: 3.5rem; font-weight: 800; margin-bottom: 20px;">Título del Gran Impacto</h1>
    <p style="font-size: 1.2rem; opacity: 0.9;">Describe aquí la misión de este nuevo bloque para la comunidad.</p>
    <a href="#" class="ah-btn ah-btn-primary" style="margin-top: 20px; text-decoration:none;">Saber más</a>
  </div>
</section>\n`,
        columns: `
<section style="padding: 80px 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 50px; max-width: 1200px; margin: 0 auto; align-items: center;">
  <div class="col-img">
    <img src="https://images.unsplash.com/photo-1529390079861-591de354faf5?q=80&w=800" style="width:100%; border-radius:15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1);">
  </div>
  <div class="col-text">
    <h2 style="color: #34859B; font-size: 2rem; margin-bottom: 20px;">Nuestra Iniciativa</h2>
    <p style="color: #475569; line-height: 1.7;">Detalla aquí el proyecto. Puedes editar este texto directamente en la vista previa.</p>
  </div>
</section>\n`,
        text: `
<section style="padding: 60px 20px; max-width: 800px; margin: 0 auto; text-align: center;">
  <h2 style="color: #1e293b; margin-bottom: 20px;">Mensaje Institucional</h2>
  <p style="font-size: 1.1rem; color: #64748b;">Escribe aquí un párrafo informativo o una cita importante para la organización.</p>
</section>\n`
    };

    // Insertar en la posición actual del cursor o al final
    const startPos = editor.selectionStart;
    const endPos = editor.selectionEnd;
    const content = editor.value;
    
    editor.value = content.substring(0, startPos) + templates[tipo] + content.substring(endPos);
    updatePreview();
}

// --- 2. COMUNICACIÓN CON EL IFRAME ---

// Recibe actualizaciones desde el motor visual dentro del iframe
window.fromVisual = (cleanHtml) => {
    isUpdatingFromVisual = true;
    editor.value = cleanHtml;
    // Pequeño retardo para evitar feedback loop
    setTimeout(() => { isUpdatingFromVisual = false; }, 10);
};

// Abre el selector de archivos del sistema
window.openUploader = (id, type) => {
    activeElementId = id;
    activeUploadType = type;
    uploader.click();
};

// Maneja la subida del archivo seleccionado
uploader.onchange = async (e) => {
    const file = e.target.files[0];
    if(!file) return;

    const fd = new FormData();
    fd.append('file', file);
    
    // Indicador visual en el botón de guardar
    const btn = document.querySelector('.btn-save');
    const originalBtnHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...';

    try {
        const res = await fetch('../api/upload_media.php', { method: 'POST', body: fd });
        const data = await res.json();
        
        if(data.success) {
            // Envía la nueva URL al iframe para que actualice el elemento
            preview.contentWindow.updateImg(activeElementId, data.url, activeUploadType);
        } else {
            alert("Error al subir: " + data.error);
        }
    } catch(err) {
        alert("Error de conexión con el servidor de medios.");
    } finally {
        btn.innerHTML = originalBtnHtml;
        e.target.value = ''; // Resetear input para permitir subir el mismo archivo
    }
};

// --- 3. PERSISTENCIA (GUARDADO EN BD) ---
async function guardarPagina() {
    const payload = {
        page_id: document.getElementById('page-id').value || null,
        title: document.getElementById('page-title').value,
        slug: document.getElementById('page-slug').value,
        content_html: editor.value
    };

    if(!payload.title || !payload.slug) {
        alert("El título y el slug son obligatorios.");
        return;
    }

    const btn = document.querySelector('.btn-save');
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Guardando...';
    btn.disabled = true;

    try {
        const res = await fetch('../api/save_page.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if(data.success) {
            btn.innerHTML = '<i class="fa-solid fa-check"></i> ¡Publicado!';
            btn.style.background = '#46B094';
            if(data.page_id) document.getElementById('page-id').value = data.page_id;
        } else {
            alert("Error: " + data.error);
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Reintentar';
        }
    } catch (error) {
        alert("Error crítico de comunicación.");
        btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Error';
    } finally {
        setTimeout(() => {
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Guardar Código';
            btn.style.background = '';
            btn.disabled = false;
        }, 2000);
    }
}

// Inicialización de eventos
editor.addEventListener('input', updatePreview);
window.onload = updatePreview;