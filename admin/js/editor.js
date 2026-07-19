// El estado global de nuestra página
let pageBlocks = [];
const workspace = document.getElementById('editor-workspace');

// 1. CARGA INICIAL: Si venimos de la base de datos, cargamos los bloques
if (typeof initialBlocks !== 'undefined' && initialBlocks.length > 0) {
    pageBlocks = initialBlocks;
    renderizarWorkspace();
} else {
    // Si es una página nueva, mostramos el estado vacío
    renderizarWorkspace();
}

// 2. AÑADIR NUEVO BLOQUE
function agregarBloque(tipo) {
    const nuevoBloque = {
        id: Date.now(),
        type: tipo,
        content: {}
    };

    if (tipo === 'hero_banner') {
        nuevoBloque.content = { title: '', subtitle: '', background_image: '' };
    } else if (tipo === 'text_columns') {
        nuevoBloque.content = { column_1: '', column_2: '' };
    } else if (tipo === 'pdf_3d') {
        nuevoBloque.content = { pdf_url: '', button_text: 'Leer Documento' };
    }

    pageBlocks.push(nuevoBloque);
    renderizarWorkspace();
}

// 3. RENDERIZAR LA INTERFAZ
function renderizarWorkspace() {
    workspace.innerHTML = ''; 

    if(pageBlocks.length === 0) {
        workspace.innerHTML = `
            <div class="empty-state" id="empty-state">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <h3>Tu lienzo está en blanco</h3>
                <p>Haz clic en los componentes de la barra lateral para empezar a construir.</p>
            </div>
        `;
        return;
    }

    pageBlocks.forEach((block, index) => {
        const div = document.createElement('div');
        div.className = 'canvas-block';
        
        let icon = 'fa-cube';
        let friendlyName = 'Bloque';
        
        if(block.type === 'hero_banner') { icon = 'fa-image'; friendlyName = 'Banner Principal'; }
        if(block.type === 'text_columns') { icon = 'fa-align-left'; friendlyName = 'Columnas de Texto'; }
        if(block.type === 'pdf_3d') { icon = 'fa-file-pdf'; friendlyName = 'Lector PDF 3D'; }

        let htmlContent = `
            <div class="block-header">
                <div><i class="fa-solid ${icon}"></i> ${friendlyName}</div>
                <div>
                    <span class="block-order" onclick="eliminarBloque(${index})" style="background: #fee2e2; color: #ef4444; margin-right: 10px;"><i class="fa-solid fa-trash"></i> Eliminar</span>
                    <span class="block-order">Orden: ${index + 1}</span>
                </div>
            </div>
            <div class="block-body">
        `;

        if (block.type === 'hero_banner') {
            htmlContent += `
                <div class="form-group">
                    <label>Título Principal</label>
                    <input type="text" placeholder="Ej: Transformando Comunidades" value="${block.content.title}" onkeyup="actualizarContenido(${index}, 'title', this.value)">
                </div>
                <div class="form-group">
                    <label>Subtítulo o descripción corta</label>
                    <input type="text" placeholder="Ej: Conoce nuestro impacto..." value="${block.content.subtitle}" onkeyup="actualizarContenido(${index}, 'subtitle', this.value)">
                </div>
                <div class="form-group">
                    <label>Imagen de Fondo</label>
                    <input type="text" placeholder="/uploads/images/banner.jpg" value="${block.content.background_image}" onkeyup="actualizarContenido(${index}, 'background_image', this.value)">
                    <div class="file-upload-wrapper">
                        <input type="file" accept="image/*" onchange="subirArchivo(this, ${index}, 'background_image')" style="font-size: 0.85rem;">
                        <small style="color: #64748b;">(Opcional: Sube una imagen directamente)</small>
                    </div>
                </div>
            `;
        } else if (block.type === 'pdf_3d') {
            htmlContent += `
                <div class="form-group">
                    <label>Archivo PDF (Ruta Relativa)</label>
                    <input type="text" placeholder="../uploads/pdfs/memoria.pdf" value="${block.content.pdf_url}" onkeyup="actualizarContenido(${index}, 'pdf_url', this.value)">
                    <div class="file-upload-wrapper">
                        <input type="file" accept=".pdf" onchange="subirArchivo(this, ${index}, 'pdf_url')" style="font-size: 0.85rem;">
                        <small style="color: #64748b;">(Opcional: Sube un PDF directamente)</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Texto del Botón de Descarga</label>
                    <input type="text" placeholder="Ej: Descargar Memoria" value="${block.content.button_text}" onkeyup="actualizarContenido(${index}, 'button_text', this.value)">
                </div>
            `;
        } else if (block.type === 'text_columns') {
            htmlContent += `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label>Columna Izquierda (Soporta HTML)</label>
                        <textarea rows="6" placeholder="<p>Texto aquí...</p>" onkeyup="actualizarContenido(${index}, 'column_1', this.value)">${block.content.column_1 || ''}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Columna Derecha (Soporta HTML)</label>
                        <textarea rows="6" placeholder="<p>Texto aquí...</p>" onkeyup="actualizarContenido(${index}, 'column_2', this.value)">${block.content.column_2 || ''}</textarea>
                    </div>
                </div>
            `;
        }

        htmlContent += `</div>`;
        div.innerHTML = htmlContent;
        workspace.appendChild(div);
    });
}

// 4. ACTUALIZAR EL ESTADO MIENTRAS SE ESCRIBE
function actualizarContenido(index, campo, valor) {
    pageBlocks[index].content[campo] = valor;
}

// 5. ELIMINAR UN BLOQUE
function eliminarBloque(index) {
    if(confirm("¿Estás seguro de eliminar este bloque?")) {
        pageBlocks.splice(index, 1);
        renderizarWorkspace();
    }
}

// 6. SUBIDA DE ARCHIVOS VÍA AJAX
async function subirArchivo(inputElement, blockIndex, campo) {
    const file = inputElement.files[0];
    if (!file) return;

    // Indicador visual de carga
    const wrapper = inputElement.parentElement;
    const statusSpan = document.createElement('span');
    statusSpan.id = 'upload-status';
    statusSpan.style.color = '#34859B';
    statusSpan.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Subiendo...';
    wrapper.appendChild(statusSpan);

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await fetch('../api/upload_media.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        document.getElementById('upload-status').remove();

        if (response.ok) {
            // Modificamos la URL para que sea relativa desde /public/ si es necesario
            let finalUrl = data.url;
            if(finalUrl.startsWith('/')) { finalUrl = '..' + finalUrl; } // Ajuste vital para entorno local
            
            actualizarContenido(blockIndex, campo, finalUrl);
            renderizarWorkspace(); // Refresca la vista para mostrar la URL en el input
        } else {
            alert("Error: " + data.error);
        }
    } catch (error) {
        document.getElementById('upload-status').remove();
        alert("Error de conexión al subir el archivo.");
    }
}

// 7. ENVIAR EL JSON AL SERVIDOR (GUARDAR)
function guardarPagina() {
    const pageId = document.getElementById('page-id').value;
    const title = document.getElementById('page-title').value;
    const slug = document.getElementById('page-slug').value;

    const payload = {
        page_id: pageId ? parseInt(pageId) : null,
        title: title,
        slug: slug,
        status: 'published',
        blocks: pageBlocks.map((block, index) => ({
            type: block.type,
            order: index,
            content: block.content
        }))
    };

    const saveBtn = document.querySelector('.btn-save');
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Guardando...';

    fetch('../api/save_page.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> ¡Publicado!';
        // Si era página nueva, actualizamos el ID oculto para que el siguiente clic sea un "Update"
        if(data.page_id) {
            document.getElementById('page-id').value = data.page_id;
        }
        setTimeout(() => saveBtn.innerHTML = originalText, 2000);
    })
    .catch(error => {
        alert("Error al guardar: " + error);
        saveBtn.innerHTML = originalText;
    });
}