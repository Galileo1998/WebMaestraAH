(() => {
  const boot = window.FORM_BUILDER_BOOT;
  let schema = boot.schema;
  let selected = null;
  let saveTimer = null;
  let isSaving = false;
  let dirty = false;
  let changeVersion = 0;

  const typeLabels = {
    short_text:'Respuesta corta',paragraph:'Párrafo',email:'Correo',number:'Número',phone:'Teléfono',multiple_choice:'Opción múltiple',checkboxes:'Casillas',dropdown:'Lista desplegable',linear_scale:'Escala lineal',rating:'Calificación',date:'Fecha',time:'Hora',datetime:'Fecha y hora',file_upload:'Subir archivo',multiple_choice_grid:'Cuadrícula opción',checkbox_grid:'Cuadrícula casillas',geo_cascade:'Cascada geográfica',center_selector:'Selector de centro',consent:'Consentimiento',title_description:'Título y descripción',image:'Imagen',video:'Video'
  };
  const choiceTypes = ['multiple_choice','checkboxes','dropdown'];
  const gridTypes = ['multiple_choice_grid','checkbox_grid'];

  const $ = (s,root=document) => root.querySelector(s);
  const $$ = (s,root=document) => [...root.querySelectorAll(s)];
  const esc = v => String(v ?? '').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const uid = p => `${p}_${Date.now()}_${Math.random().toString(36).slice(2,8)}`;

  function enableSortable(element, options){
    if (!element || typeof window.Sortable !== 'function') return null;
    return new window.Sortable(element, options);
  }

  function normalizeSchema(){
    schema.form.configuracion = {
      confirmation_title:'Respuesta registrada',
      confirmation_image:'',
      confirmation_image_alt:'Gracias por completar el formulario',
      confirmation_image_max_width:520,
      ...(schema.form.configuracion || {})
    };
    schema.sections = schema.sections || [];
    if (!schema.sections.length) schema.sections.push(newSection());
    schema.sections.forEach((s,si)=>{
      s._key = s._key || uid('s');
      s.orden = si+1;
      s.questions = s.questions || [];
      s.questions.forEach((q,qi)=>{
        q._key = q._key || uid('q');
        q.opciones = q.opciones || defaultOptions(q.tipo);
        q.validacion = q.validacion || {};
        q.logica = {enabled:false,source_key:'',operator:'equals',value:'',...(q.logica || {})};
        q.config = q.config || {};
        q.config.logic_key = q.config.logic_key || uid('logic');
        q.orden = qi+1;
      });
    });
  }

  function newSection(){ return {id:null,_key:uid('s'),titulo:'Nueva sección',descripcion:'',config:{},questions:[]}; }
  function defaultOptions(type){
    if(choiceTypes.includes(type)) return ['Opción 1','Opción 2'];
    if(gridTypes.includes(type)) return {rows:['Fila 1','Fila 2'],columns:['Columna 1','Columna 2']};
    if(type==='linear_scale') return {min:1,max:5,min_label:'',max_label:''};
    if(type==='rating') return {max:5,icon:'star'};
    if(type==='geo_cascade') return {levels:['municipio','base','caserio','centro'],center_types:[]};
    if(type==='center_selector') return {center_types:[]};
    return [];
  }
  function newQuestion(type){
    const titles={email:'Correo electrónico',number:'Número',phone:'Número de teléfono',date:'Fecha',time:'Hora',datetime:'Fecha y hora',file_upload:'Adjuntar archivo',geo_cascade:'Ubicación y centro',center_selector:'Centro',consent:'Acepto los términos',title_description:'Título informativo',image:'Imagen',video:'Video'};
    return {id:null,_key:uid('q'),tipo:type,titulo:titles[type]||'Pregunta sin título',descripcion:'',requerido:false,opciones:defaultOptions(type),validacion:{},logica:{enabled:false,source_key:'',operator:'equals',value:''},config:{logic_key:uid('logic')},puntos:0};
  }

  function markDirty(){
    dirty=true;
    changeVersion++;
    $('#saveState').innerHTML='<i class="fa-solid fa-ellipsis"></i> Cambios pendientes';
    clearTimeout(saveTimer);
    saveTimer=setTimeout(saveSchema,650);
  }

  function render(){
    normalizeSchema();
    $('#topTitle').value=schema.form.titulo || '';
    $('#coverTitle').value=schema.form.titulo || '';
    $('#coverDescription').value=schema.form.descripcion || '';
    $('#formStatus').value=schema.form.estado || 'borrador';
    $('#formCover').style.borderTopColor=schema.form.tema_color || '#34859B';
    const wrap=$('#sectionsContainer'); wrap.innerHTML='';
    schema.sections.forEach((section,si)=>{
      const card=document.createElement('section');
      card.className='section-card'; card.dataset.sectionKey=section._key;
      card.innerHTML=`<div class="section-head"><div class="drag"><i class="fa-solid fa-grip-vertical"></i></div><div class="section-head-main"><input class="section-title" value="${esc(section.titulo)}" placeholder="Título de sección"><textarea class="section-desc" rows="1" placeholder="Descripción de sección">${esc(section.descripcion)}</textarea></div><button class="icon-action delete-section" title="Eliminar sección"><i class="fa-solid fa-trash"></i></button></div><div class="section-body"></div>`;
      const body=$('.section-body',card);
      section.questions.forEach(q=>body.appendChild(questionNode(q,section)));
      wrap.appendChild(card);
      $('.section-title',card).addEventListener('input',e=>{section.titulo=e.target.value;markDirty();});
      $('.section-desc',card).addEventListener('input',e=>{section.descripcion=e.target.value;markDirty();});
      $('.delete-section',card).addEventListener('click',()=>{
        if(schema.sections.length===1){alert('El formulario debe conservar al menos una sección.');return;}
        if(!confirm('¿Eliminar esta sección y sus preguntas?'))return;
        schema.sections.splice(si,1); if(selected?.sectionKey===section._key) selected=null; render();markDirty();
      });
      enableSortable(body,{group:'questions',animation:150,handle:'.drag',onEnd(){syncOrderFromDom();markDirty();}});
    });
    enableSortable(wrap,{animation:150,handle:'.section-head .drag',onEnd(){syncOrderFromDom();markDirty();}});
    renderProperties();
  }

  function questionNode(q,section){
    const node=document.createElement('div'); node.className='question-card'+(selected?.questionKey===q._key?' selected':''); node.dataset.questionKey=q._key;
    const qIndex=section.questions.findIndex(x=>x._key===q._key);
    const canUp=qIndex>0;
    const canDown=qIndex>=0 && qIndex<section.questions.length-1;
    node.innerHTML=`<div class="question-top"><span class="drag" title="Arrastrar pregunta"><i class="fa-solid fa-grip-vertical"></i></span><div class="question-title">${esc(q.titulo)}</div><span class="question-type">${esc(typeLabels[q.tipo]||q.tipo)}${q.logica?.enabled?' · Condicional':''}</span></div><div class="question-preview">${preview(q)}</div><div class="question-actions"><button class="icon-action move-q-up" title="Subir pregunta" ${canUp?'':'disabled'}><i class="fa-solid fa-arrow-up"></i></button><button class="icon-action move-q-down" title="Bajar pregunta" ${canDown?'':'disabled'}><i class="fa-solid fa-arrow-down"></i></button><button class="icon-action duplicate-q" title="Duplicar"><i class="fa-solid fa-copy"></i></button><button class="icon-action delete-q" title="Eliminar"><i class="fa-solid fa-trash"></i></button></div>`;
    node.addEventListener('click',e=>{if(e.target.closest('button'))return;selected={sectionKey:section._key,questionKey:q._key};render();});
    $('.move-q-up',node).addEventListener('click',e=>{e.stopPropagation();moveQuestion(section,q,-1);});
    $('.move-q-down',node).addEventListener('click',e=>{e.stopPropagation();moveQuestion(section,q,1);});
    $('.duplicate-q',node).addEventListener('click',e=>{e.stopPropagation();const idx=section.questions.findIndex(x=>x._key===q._key);const copy=JSON.parse(JSON.stringify(q));copy.id=null;copy._key=uid('q');copy.config=copy.config||{};copy.config.logic_key=uid('logic');copy.titulo+=' - Copia';section.questions.splice(idx+1,0,copy);selected={sectionKey:section._key,questionKey:copy._key};render();markDirty();});
    $('.delete-q',node).addEventListener('click',e=>{e.stopPropagation();if(!confirm('¿Eliminar esta pregunta?'))return;section.questions=section.questions.filter(x=>x._key!==q._key);if(selected?.questionKey===q._key)selected=null;render();markDirty();});
    return node;
  }

  function moveQuestion(section,q,direction){
    const index=section.questions.findIndex(x=>x._key===q._key);
    const target=index+direction;
    if(index<0 || target<0 || target>=section.questions.length)return;
    [section.questions[index],section.questions[target]]=[section.questions[target],section.questions[index]];
    selected={sectionKey:section._key,questionKey:q._key};
    render();
    markDirty();
    setTimeout(()=>document.querySelector(`[data-question-key="${q._key}"]`)?.scrollIntoView({behavior:'smooth',block:'center'}),20);
  }

  function preview(q){
    if(choiceTypes.includes(q.tipo)) return (q.opciones||[]).map(x=>`○ ${esc(x)}`).join('<br>');
    if(gridTypes.includes(q.tipo)) return `${(q.opciones?.rows||[]).length} filas × ${(q.opciones?.columns||[]).length} columnas`;
    if(q.tipo==='linear_scale') return `${q.opciones?.min||1} — ${q.opciones?.max||5}`;
    if(q.tipo==='rating') return '★ '.repeat(Math.min(5,q.opciones?.max||5));
    if(q.tipo==='file_upload') return '<i class="fa-solid fa-cloud-arrow-up"></i> El participante podrá adjuntar archivos';
    if(q.tipo==='geo_cascade') return '<i class="fa-solid fa-map-location-dot"></i> Municipio → Comunidad base → Caserío → Centro';
    if(q.tipo==='center_selector') return '<i class="fa-solid fa-building"></i> Selección filtrada desde Gestión de Centros';
    if(q.tipo==='title_description') return esc(q.descripcion||'Texto informativo sin respuesta');
    if(q.tipo==='image') return '<i class="fa-solid fa-image"></i> '+esc(q.config?.url||'Configura la URL de la imagen');
    if(q.tipo==='video') return '<i class="fa-solid fa-video"></i> '+esc(q.config?.url||'Configura la URL del video');
    return '____________________________';
  }

  function getSelected(){
    if(!selected)return null;
    const section=schema.sections.find(s=>s._key===selected.sectionKey); if(!section)return null;
    const q=section.questions.find(q=>q._key===selected.questionKey); if(!q)return null;
    return {section,q};
  }

  function renderProperties(){
    const content=$('#propertiesContent');
    const sel=getSelected();
    if(!sel){
      content.className='';
      const formConfig=schema.form.configuracion||{};
      const confirmationImage=formConfig.confirmation_image||'';
      content.innerHTML=`<h4>Configuración del formulario</h4>
        <div class="field"><label>Color institucional</label><input id="propTheme" type="color" class="input" value="${esc(schema.form.tema_color||'#34859B')}"></div>
        <h4>Pantalla de agradecimiento</h4>
        <div class="field"><label>Título</label><input id="propConfirmTitle" class="input" value="${esc(formConfig.confirmation_title||'Respuesta registrada')}"></div>
        <div class="field"><label>Mensaje de confirmación</label><textarea id="propConfirm" class="textarea" rows="3">${esc(schema.form.mensaje_confirmacion||'')}</textarea></div>
        <div class="confirmation-image-editor">
          <div id="confirmationImagePreview" class="confirmation-image-preview ${confirmationImage?'has-image':''}">
            ${confirmationImage?`<img src="${esc(assetUrl(confirmationImage))}" alt="Vista previa">`:`<i class="fa-regular fa-image"></i><span>Sin imagen de agradecimiento</span>`}
          </div>
          <div class="confirmation-image-actions">
            <label class="btn btn-sm" for="propConfirmImageFile"><i class="fa-solid fa-image"></i> Subir imagen</label>
            <input id="propConfirmImageFile" type="file" accept="image/*" hidden>
            ${confirmationImage?`<button id="removeConfirmImage" type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Quitar</button>`:''}
          </div>
          <div id="confirmationImageState" class="upload-state">JPG, PNG, WEBP, GIF, AVIF, HEIC y otros formatos de imagen. Máximo 12 MB.</div>
        </div>
        <div class="field"><label>O usar una URL externa</label><input id="propConfirmImageUrl" class="input" value="${esc(confirmationImage)}" placeholder="https://... o uploads/formularios/..."></div>
        <div class="field"><label>Texto alternativo</label><input id="propConfirmImageAlt" class="input" value="${esc(formConfig.confirmation_image_alt||'Gracias por completar el formulario')}"></div>
        <div class="field"><label>Ancho máximo de la imagen (px)</label><input id="propConfirmImageWidth" type="number" min="160" max="1000" class="input" value="${esc(formConfig.confirmation_image_max_width||520)}"></div>
        <h4>Disponibilidad y acceso</h4>
        <div class="field"><label>Fecha de apertura</label><input id="propOpen" type="datetime-local" class="input" value="${dateLocal(schema.form.fecha_apertura)}"></div>
        <div class="field"><label>Fecha de cierre</label><input id="propClose" type="datetime-local" class="input" value="${dateLocal(schema.form.fecha_cierre)}"></div>
        <div class="field"><label>Límite de respuestas</label><input id="propLimit" type="number" min="1" class="input" value="${esc(schema.form.limite_respuestas||'')}"></div>
        ${switchHtml('propLogin','Requerir inicio de sesión',schema.form.requiere_login)}${switchHtml('propOne','Una respuesta por usuario',schema.form.una_respuesta)}${switchHtml('propEdit','Permitir editar respuesta',schema.form.permitir_edicion)}${switchHtml('propEmail','Recopilar correo',schema.form.recopilar_correo)}${switchHtml('propProgress','Mostrar barra de progreso',schema.form.mostrar_progreso)}
        <div class="field"><label>Notificar nuevas respuestas a</label><input id="propNotify" type="email" class="input" value="${esc(schema.form.notificar_correo||'')}"></div>`;
      bindFormProps(); return;
    }
    content.className='';
    const q=sel.q;
    content.innerHTML=`<div class="field"><label>Tipo de pregunta</label><select id="pType" class="select">${Object.entries(typeLabels).map(([v,l])=>`<option value="${v}" ${q.tipo===v?'selected':''}>${esc(l)}</option>`).join('')}</select></div><div class="field"><label>Pregunta</label><textarea id="pTitle" class="textarea" rows="2">${esc(q.titulo)}</textarea></div><div class="field"><label>Descripción / ayuda</label><textarea id="pDesc" class="textarea" rows="2">${esc(q.descripcion||'')}</textarea></div>${!['title_description','image','video'].includes(q.tipo)?switchHtml('pReq','Obligatoria',q.requerido):''}<div class="inline-grid"><div class="field"><label>Puntos</label><input id="pPoints" type="number" min="0" step="0.5" class="input" value="${esc(q.puntos||0)}"></div><div class="field"><label>Ancho</label><select id="pWidth" class="select"><option value="full">Completo</option><option value="half" ${q.config?.width==='half'?'selected':''}>Mitad</option></select></div></div><div id="typeSpecific"></div><h4>Validación</h4><div class="field"><label>Expresión regular</label><input id="pRegex" class="input" placeholder="Ej. ^[0-9]{13}$" value="${esc(q.validacion?.regex||'')}"></div><div class="inline-grid"><div class="field"><label>Mínimo</label><input id="pMin" class="input" value="${esc(q.validacion?.min??'')}"></div><div class="field"><label>Máximo</label><input id="pMax" class="input" value="${esc(q.validacion?.max??'')}"></div></div><div class="field"><label>Mensaje de error</label><input id="pError" class="input" value="${esc(q.validacion?.message||'')}"></div><div id="logicSpecific"></div>`;
    renderTypeSpecific(q);
    renderLogicSpecific(q);
    bindQuestionProps(q);
  }

  function switchHtml(id,label,value){return `<label class="switch-line"><span>${esc(label)}</span><input id="${id}" type="checkbox" ${value?'checked':''}></label>`;}
  function dateLocal(v){if(!v)return '';return String(v).replace(' ','T').slice(0,16);}
  function assetUrl(path){
    const value=String(path||'').trim();
    if(!value)return '';
    if(/^(https?:|data:image\/|blob:|\/)/i.test(value))return value;
    return '../'+value.replace(/^\.\.\//,'').replace(/^\//,'');
  }
  function fileAsDataUrl(file){return new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result||''));reader.onerror=()=>reject(new Error('No fue posible leer la imagen.'));reader.readAsDataURL(file);});}
  async function uploadConfirmationImage(file){
    if(!file)return;
    if(file.size>12*1024*1024)throw new Error('La imagen supera el máximo de 12 MB.');
    const state=$('#confirmationImageState');
    if(state)state.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Subiendo imagen...';
    const dataUrl=await fileAsDataUrl(file);
    const fd=new FormData();
    fd.append('action','upload_confirmation_image');fd.append('csrf',boot.csrf);fd.append('id',boot.id);
    fd.append('data_url',dataUrl);fd.append('file_name',file.name||'imagen');
    fd.append('old_path',schema.form.configuracion?.confirmation_image||'');
    const response=await fetch(boot.api,{method:'POST',body:fd});
    const json=await response.json();
    if(!response.ok||json.status!=='ok')throw new Error(json.msg||'No fue posible subir la imagen.');
    schema.form.configuracion.confirmation_image=json.path;
    markDirty();
    renderProperties();
    await saveSchema(true);
  }
  async function deleteConfirmationImage(){
    const current=String(schema.form.configuracion?.confirmation_image||'');
    if(current && current.startsWith('uploads/formularios/confirmaciones/')){
      const fd=new FormData();fd.append('action','delete_confirmation_image');fd.append('csrf',boot.csrf);fd.append('id',boot.id);fd.append('path',current);
      try{await fetch(boot.api,{method:'POST',body:fd});}catch(_){/* el esquema igualmente se limpia */}
    }
    schema.form.configuracion.confirmation_image='';
    markDirty();renderProperties();await saveSchema(true);
  }

  function bindFormProps(){
    const bind=(id,fn,event='input')=>{const el=$('#'+id);if(el)el.addEventListener(event,e=>{fn(e.target);markDirty();});};
    bind('propTheme',el=>{schema.form.tema_color=el.value;$('#formCover').style.borderTopColor=el.value;});
    bind('propConfirmTitle',el=>schema.form.configuracion.confirmation_title=el.value);
    bind('propConfirm',el=>schema.form.mensaje_confirmacion=el.value);
    bind('propConfirmImageUrl',el=>{schema.form.configuracion.confirmation_image=el.value;const preview=$('#confirmationImagePreview');if(preview){preview.classList.toggle('has-image',!!el.value);preview.innerHTML=el.value?`<img src="${esc(assetUrl(el.value))}" alt="Vista previa">`:'<i class="fa-regular fa-image"></i><span>Sin imagen de agradecimiento</span>';}});
    bind('propConfirmImageAlt',el=>schema.form.configuracion.confirmation_image_alt=el.value);
    bind('propConfirmImageWidth',el=>schema.form.configuracion.confirmation_image_max_width=Math.max(160,Math.min(1000,Number(el.value)||520)));
    const imageInput=$('#propConfirmImageFile');
    if(imageInput)imageInput.addEventListener('change',async e=>{const file=e.target.files?.[0];e.target.value='';if(!file)return;try{await uploadConfirmationImage(file);}catch(error){const state=$('#confirmationImageState');if(state)state.textContent=error.message||'No fue posible subir la imagen.';alert(error.message||error);}});
    const remove=$('#removeConfirmImage');if(remove)remove.addEventListener('click',()=>deleteConfirmationImage());
    bind('propOpen',el=>schema.form.fecha_apertura=el.value);
    bind('propClose',el=>schema.form.fecha_cierre=el.value);
    bind('propLimit',el=>schema.form.limite_respuestas=el.value);
    bind('propLogin',el=>schema.form.requiere_login=el.checked,'change');
    bind('propOne',el=>schema.form.una_respuesta=el.checked,'change');
    bind('propEdit',el=>schema.form.permitir_edicion=el.checked,'change');
    bind('propEmail',el=>schema.form.recopilar_correo=el.checked,'change');
    bind('propProgress',el=>schema.form.mostrar_progreso=el.checked,'change');
    bind('propNotify',el=>schema.form.notificar_correo=el.value);
  }

  function questionsBefore(target){
    const result=[];
    let found=false;
    for(const section of schema.sections){
      for(const q of section.questions){
        if(q._key===target._key){found=true;break;}
        if(!['title_description','image','video'].includes(q.tipo)) result.push(q);
      }
      if(found) break;
    }
    return result;
  }

  function renderLogicSpecific(q){
    const box=$('#logicSpecific');
    if(!box || ['title_description','image','video'].includes(q.tipo)) return;
    q.logica=q.logica||{enabled:false,source_key:'',operator:'equals',value:''};
    const candidates=questionsBefore(q);
    if(!candidates.length){
      box.innerHTML='<h4>Lógica condicional</h4><div class="logic-box">Agrega una pregunta anterior para poder mostrar esta pregunta según una respuesta.</div>';
      q.logica.enabled=false;
      return;
    }
    const ops=[
      ['equals','Es igual a'],
      ['not_equals','No es igual a'],
      ['contains','Contiene'],
      ['not_contains','No contiene'],
      ['is_empty','Está vacía'],
      ['not_empty','No está vacía'],
      ['greater_than','Es mayor que'],
      ['less_than','Es menor que']
    ];
    box.innerHTML=`<h4>Lógica condicional</h4>
      ${switchHtml('logicEnabled','Mostrar esta pregunta solo cuando se cumpla una condición',!!q.logica.enabled)}
      <div id="logicFields" class="${q.logica.enabled?'':'d-none'}">
        <div class="field"><label>Pregunta anterior</label><select id="logicSource" class="select">
          <option value="">Seleccione...</option>
          ${candidates.map(c=>`<option value="${esc(c.config?.logic_key||'')}" ${q.logica.source_key===(c.config?.logic_key||'')?'selected':''}>${esc(c.titulo)}</option>`).join('')}
        </select></div>
        <div class="field"><label>Condición</label><select id="logicOperator" class="select">
          ${ops.map(([v,l])=>`<option value="${v}" ${q.logica.operator===v?'selected':''}>${l}</option>`).join('')}
        </select></div>
        <div class="field" id="logicValueField"><label>Valor esperado</label><input id="logicValue" class="input" value="${esc(q.logica.value??'')}" placeholder="Ej. Sí"></div>
        <div class="logic-box">La pregunta se oculta hasta que la respuesta anterior cumpla esta regla. Si está marcada como obligatoria, solo será obligatoria cuando esté visible.</div>
      </div>`;
    const enabled=$('#logicEnabled');
    const fields=$('#logicFields');
    const operator=$('#logicOperator');
    const valueField=$('#logicValueField');
    const refreshValue=()=>{if(valueField)valueField.classList.toggle('d-none',['is_empty','not_empty'].includes(operator?.value||''));};
    enabled.onchange=e=>{q.logica.enabled=e.target.checked;fields.classList.toggle('d-none',!e.target.checked);markDirty();};
    $('#logicSource').onchange=e=>{q.logica.source_key=e.target.value;markDirty();};
    operator.onchange=e=>{q.logica.operator=e.target.value;refreshValue();markDirty();};
    $('#logicValue').oninput=e=>{q.logica.value=e.target.value;markDirty();};
    refreshValue();
  }

  function renderTypeSpecific(q){
    const box=$('#typeSpecific');
    if(choiceTypes.includes(q.tipo)){
      box.innerHTML=`<h4>Opciones</h4><div id="optionsEditor" class="options-editor"></div><button id="addOption" class="btn btn-sm"><i class="fa-solid fa-plus"></i> Opción</button>`;
      const ed=$('#optionsEditor');(q.opciones||[]).forEach((v,i)=>ed.appendChild(optionRow(q.opciones,i,v)));
      $('#addOption').onclick=()=>{q.opciones.push(`Opción ${q.opciones.length+1}`);renderProperties();markDirty();};
    } else if(gridTypes.includes(q.tipo)){
      box.innerHTML=`<h4>Filas</h4><div id="rowsEd" class="options-editor"></div><button id="addRow" class="btn btn-sm">Añadir fila</button><h4>Columnas</h4><div id="colsEd" class="options-editor"></div><button id="addCol" class="btn btn-sm">Añadir columna</button>`;
      ['rows','columns'].forEach(key=>{const ed=$('#'+(key==='rows'?'rowsEd':'colsEd'));(q.opciones[key]||[]).forEach((v,i)=>ed.appendChild(optionRow(q.opciones[key],i,v)));});
      $('#addRow').onclick=()=>{q.opciones.rows.push(`Fila ${q.opciones.rows.length+1}`);renderProperties();markDirty();};
      $('#addCol').onclick=()=>{q.opciones.columns.push(`Columna ${q.opciones.columns.length+1}`);renderProperties();markDirty();};
    } else if(q.tipo==='linear_scale'){
      box.innerHTML=`<h4>Escala</h4><div class="inline-grid"><div class="field"><label>Desde</label><input id="scaleMin" class="input" type="number" min="0" max="10" value="${esc(q.opciones.min||1)}"></div><div class="field"><label>Hasta</label><input id="scaleMax" class="input" type="number" min="2" max="10" value="${esc(q.opciones.max||5)}"></div></div><div class="inline-grid"><input id="scaleMinLabel" class="input" placeholder="Etiqueta mínima" value="${esc(q.opciones.min_label||'')}"><input id="scaleMaxLabel" class="input" placeholder="Etiqueta máxima" value="${esc(q.opciones.max_label||'')}"></div>`;
      ['Min','Max','MinLabel','MaxLabel'].forEach(k=>$('#scale'+k).addEventListener('input',e=>{q.opciones[k==='Min'?'min':k==='Max'?'max':k==='MinLabel'?'min_label':'max_label']=e.target.value;markDirty();}));
    } else if(q.tipo==='rating'){
      box.innerHTML=`<h4>Calificación</h4><div class="field"><label>Máximo</label><select id="ratingMax" class="select">${[3,4,5,7,10].map(n=>`<option ${Number(q.opciones.max||5)===n?'selected':''}>${n}</option>`).join('')}</select></div>`;
      $('#ratingMax').onchange=e=>{q.opciones.max=Number(e.target.value);markDirty();};
    } else if(q.tipo==='file_upload'){
      box.innerHTML=`<h4>Archivos</h4><div class="field"><label>Tipos permitidos</label><input id="fileTypes" class="input" value="${esc((q.config.allowed_types||['image/*']).join(','))}"></div><div class="field"><label>Tamaño máximo (MB)</label><input id="fileSize" type="number" class="input" value="${esc(q.config.max_mb||20)}"></div>`;
      $('#fileTypes').oninput=e=>{q.config.allowed_types=e.target.value.split(',').map(x=>x.trim()).filter(Boolean);markDirty();};$('#fileSize').oninput=e=>{q.config.max_mb=Number(e.target.value);markDirty();};
    } else if(['geo_cascade','center_selector'].includes(q.tipo)){
      const levels=q.tipo==='geo_cascade'?(q.opciones.levels||['municipio','base','caserio','centro']):['centro'];
      const allTypes=['Básica','Media','Preescolar','Centro ADN','UAPS/CIS'];
      box.innerHTML=`<h4>Geografía</h4>${q.tipo==='geo_cascade'?`<div class="field"><label>Niveles visibles</label>${['municipio','base','caserio','centro'].map(l=>`<label class="switch-line"><span>${l==='base'?'Comunidad base':l[0].toUpperCase()+l.slice(1)}</span><input class="geo-level" value="${l}" type="checkbox" ${levels.includes(l)?'checked':''}></label>`).join('')}</div>`:''}<div class="field"><label>Tipos de centro</label>${allTypes.map(t=>`<label class="switch-line"><span>${t}</span><input class="center-type" value="${esc(t)}" type="checkbox" ${(q.opciones.center_types||[]).includes(t)?'checked':''}></label>`).join('')}</div><div class="logic-box">Los datos se consultan de <b>ah_bases_geograficas</b> y <b>ah_centros</b>. Municipio filtra comunidad base; comunidad base filtra caserío; caserío filtra centro.</div>`;
      $$('.geo-level').forEach(el=>el.onchange=()=>{q.opciones.levels=$$('.geo-level:checked').map(x=>x.value);markDirty();});
      $$('.center-type').forEach(el=>el.onchange=()=>{q.opciones.center_types=$$('.center-type:checked').map(x=>x.value);markDirty();});
    } else if(q.tipo==='consent'){
      box.innerHTML=`<h4>Consentimiento</h4><div class="field"><label>Texto que debe aceptar</label><textarea id="consentText" class="textarea" rows="3">${esc(q.config.consent_text||'Declaro que la información proporcionada es correcta.')}</textarea></div>`;
      $('#consentText').oninput=e=>{q.config.consent_text=e.target.value;markDirty();};
    } else if(['image','video'].includes(q.tipo)){
      box.innerHTML=`<h4>Multimedia</h4><div class="field"><label>URL ${q.tipo==='image'?'de la imagen':'del video'} </label><input id="mediaUrl" class="input" value="${esc(q.config.url||'')}" placeholder="https://..."></div><div class="field"><label>Texto alternativo / pie</label><input id="mediaCaption" class="input" value="${esc(q.config.caption||'')}"></div>`;
      $('#mediaUrl').oninput=e=>{q.config.url=e.target.value;markDirty();};$('#mediaCaption').oninput=e=>{q.config.caption=e.target.value;markDirty();};
    }
  }

  function optionRow(arr,i,value){
    const row=document.createElement('div');row.className='option-row';row.innerHTML=`<input class="input" value="${esc(value)}"><button class="btn btn-icon btn-danger"><i class="fa-solid fa-xmark"></i></button>`;
    $('input',row).oninput=e=>{arr[i]=e.target.value;markDirty();};$('button',row).onclick=()=>{arr.splice(i,1);renderProperties();markDirty();};return row;
  }

  function bindQuestionProps(q){
    $('#pType').onchange=e=>{const logicKey=q.config?.logic_key||uid('logic');q.tipo=e.target.value;q.opciones=defaultOptions(q.tipo);q.config={logic_key:logicKey};render();markDirty();};
    $('#pTitle').oninput=e=>{q.titulo=e.target.value;renderQuestionText(q);markDirty();};
    $('#pDesc').oninput=e=>{q.descripcion=e.target.value;markDirty();};
    if($('#pReq'))$('#pReq').onchange=e=>{q.requerido=e.target.checked;markDirty();};
    $('#pPoints').oninput=e=>{q.puntos=Number(e.target.value)||0;markDirty();};
    $('#pWidth').onchange=e=>{q.config.width=e.target.value;markDirty();};
    $('#pRegex').oninput=e=>{q.validacion.regex=e.target.value;markDirty();};
    $('#pMin').oninput=e=>{q.validacion.min=e.target.value;markDirty();};
    $('#pMax').oninput=e=>{q.validacion.max=e.target.value;markDirty();};
    $('#pError').oninput=e=>{q.validacion.message=e.target.value;markDirty();};
  }
  function renderQuestionText(q){const node=document.querySelector(`[data-question-key="${q._key}"] .question-title`);if(node)node.textContent=q.titulo;}

  function syncOrderFromDom(){
    const sectionMap=new Map(schema.sections.map(s=>[s._key,s]));
    // Mapa global para permitir arrastrar una pregunta entre secciones sin perderla.
    const questionMap=new Map();
    schema.sections.forEach(s=>s.questions.forEach(q=>questionMap.set(q._key,q)));
    schema.sections=$$('#sectionsContainer .section-card').map(card=>{
      const s=sectionMap.get(card.dataset.sectionKey);
      if(!s)return null;
      s.questions=$$('.question-card',$('.section-body',card))
        .map(n=>questionMap.get(n.dataset.questionKey))
        .filter(Boolean);
      return s;
    }).filter(Boolean);
    normalizeSchema();
  }

  function repairLogicReferences(){
    const keys=new Set();
    schema.sections.forEach(section=>section.questions.forEach(q=>{
      q.config=q.config||{};
      if(!q.config.logic_key || keys.has(q.config.logic_key)) q.config.logic_key=uid('logic');
      keys.add(q.config.logic_key);
      q.logica={enabled:false,source_key:'',operator:'equals',value:'',...(q.logica||{})};
    }));
  }

  async function saveSchema(force=false){
    clearTimeout(saveTimer);
    if(isSaving){ if(force) dirty=true; return; }
    if(!dirty && !force)return;
    repairLogicReferences();
    const versionAtStart=changeVersion;
    const payload=JSON.parse(JSON.stringify(schema));
    isSaving=true;
    $('#saveState').innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Guardando';
    try{
      const fd=new FormData();
      fd.append('action','save_schema');fd.append('csrf',boot.csrf);fd.append('id',boot.id);fd.append('payload',JSON.stringify(payload));
      const r=await fetch(boot.api,{method:'POST',body:fd});
      const j=await r.json();
      if(!r.ok||j.status!=='ok')throw new Error(j.msg||'No fue posible guardar.');
      if(changeVersion===versionAtStart){
        schema=j.data;
        normalizeSchema();
        dirty=false;
        $('#saveState').innerHTML=`<i class="fa-solid fa-cloud-arrow-up"></i> Guardado ${j.saved_at}`;
        setTimeout(()=>{if(!dirty)$('#saveState').innerHTML='<i class="fa-solid fa-cloud"></i> Guardado';},1400);
      }else{
        // Hubo cambios mientras el servidor guardaba. No reemplazar el esquema local.
        dirty=true;
        $('#saveState').innerHTML='<i class="fa-solid fa-ellipsis"></i> Guardando cambios recientes';
      }
    }catch(e){
      dirty=true;
      $('#saveState').innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> Error al guardar';
      alert(e.message);
    }finally{
      isSaving=false;
      if(dirty)saveTimer=setTimeout(()=>saveSchema(false),350);
    }
  }

  function cleanImportedSchema(raw){
    const source = raw && raw.schema ? raw.schema : raw;
    if (!source || typeof source !== 'object' || !source.form || !Array.isArray(source.sections)) {
      throw new Error('El archivo no contiene un formulario válido.');
    }
    if (!source.sections.length) throw new Error('El formulario importado no contiene secciones.');

    const keep = {
      id: schema.form.id,
      slug: schema.form.slug,
      creado_por: schema.form.creado_por,
      created_at: schema.form.created_at
    };
    const imported = JSON.parse(JSON.stringify(source));
    imported.form = {...schema.form, ...imported.form, ...keep};
    imported.sections = imported.sections.map((section, si) => ({
      ...section,
      id: null,
      _key: uid('s'),
      orden: si + 1,
      questions: (section.questions || []).map((q, qi) => ({
        ...q,
        id: null,
        section_id: null,
        _key: uid('q'),
        orden: qi + 1,
        opciones: q.opciones ?? defaultOptions(q.tipo || 'short_text'),
        validacion: q.validacion || {},
        logica: q.logica || {},
        config: {...(q.config || {}), logic_key:(q.config?.logic_key || uid('logic'))}
      }))
    }));
    return imported;
  }

  function exportForm(){
    syncOrderFromDom();
    const payload = {
      format: 'AH_FORM_SCHEMA',
      version: 1,
      exported_at: new Date().toISOString(),
      schema: JSON.parse(JSON.stringify(schema))
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], {type:'application/json;charset=utf-8'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const safeName = String(schema.form.titulo || 'formulario').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9_-]+/g,'_').replace(/^_+|_+$/g,'') || 'formulario';
    a.href = url;
    a.download = `${safeName}.formulario.json`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(()=>URL.revokeObjectURL(url), 1000);
  }

  async function importFormFile(file){
    if (!file) return;
    const text = await file.text();
    let parsed;
    try { parsed = JSON.parse(text); } catch (_) { throw new Error('El archivo JSON no es válido.'); }
    if (!confirm('La importación reemplazará las secciones y preguntas del formulario actual. ¿Continuar?')) return;
    schema = cleanImportedSchema(parsed);
    selected = null;
    normalizeSchema();
    render();
    markDirty();
    await saveSchema();
  }

  function addQuestion(type){
    const section = selected
      ? schema.sections.find(s=>s._key===selected.sectionKey)
      : schema.sections[schema.sections.length - 1];
    if (!section) return;
    const q = newQuestion(type);
    section.questions.push(q);
    selected = {sectionKey:section._key, questionKey:q._key};
    render();
    markDirty();
    setTimeout(()=>document.querySelector(`[data-question-key="${q._key}"]`)?.scrollIntoView({behavior:'smooth',block:'center'}),20);
  }

  function initBuilder(){
    try {
      normalizeSchema();
      render();

      const toolbox = document.querySelector('.toolbox');
      toolbox?.addEventListener('click', e=>{
        const tool = e.target.closest('.question-tool');
        if (!tool || !toolbox.contains(tool)) return;
        e.preventDefault();
        addQuestion(tool.dataset.type);
      });

      $('#addSection').onclick=()=>{const s=newSection();schema.sections.push(s);selected=null;render();markDirty();setTimeout(()=>document.querySelector(`[data-section-key="${s._key}"]`)?.scrollIntoView({behavior:'smooth'}),20);};
      $('#topTitle').oninput=e=>{schema.form.titulo=e.target.value;$('#coverTitle').value=e.target.value;markDirty();};
      $('#coverTitle').oninput=e=>{schema.form.titulo=e.target.value;$('#topTitle').value=e.target.value;markDirty();};
      $('#coverDescription').oninput=e=>{schema.form.descripcion=e.target.value;markDirty();};
      $('#formStatus').onchange=e=>{schema.form.estado=e.target.value;markDirty();};
      $('#saveNow').onclick=()=>saveSchema(true);
      $('#exportForm')?.addEventListener('click', exportForm);
      $('#importForm')?.addEventListener('click', ()=>$('#importFormFile')?.click());
      $('#importFormFile')?.addEventListener('change', async e=>{
        const file=e.target.files?.[0];
        e.target.value='';
        try { await importFormFile(file); } catch(err) { alert(err.message || 'No fue posible importar el formulario.'); }
      });

      if (typeof window.Sortable !== 'function') {
        const state=$('#saveState');
        if(state) state.title='El ordenamiento por arrastre no está disponible, pero puedes crear y editar preguntas normalmente.';
      }
      window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
    } catch (err) {
      console.error('No se pudo inicializar el constructor:', err);
      alert('No se pudo inicializar el constructor: ' + (err.message || err));
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initBuilder);
  else initBuilder();
})();
