(() => {
  'use strict';
  const state={page:1,total:0,perPage:20,q:'',programa:''};
  const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const list=document.getElementById('v2-list'),status=document.getElementById('v2-status'),pages=document.getElementById('v2-pages');

  async function loadList(page=1){
    state.page=page;status.textContent='Cargando actividades...';list.innerHTML='';pages.innerHTML='';
    const query=new URLSearchParams({action:'task_list',page:String(page),per_page:String(state.perPage),q:state.q,programa:state.programa});
    const response=await fetch(`monitoreo_api.php?${query}`,{headers:{Accept:'application/json'}}),data=await response.json();
    if(!response.ok||data.status!=='ok')throw new Error(data.msg||'No se pudo cargar el listado.');
    state.total=data.total;status.textContent=`${data.total} actividades encontradas`;
    list.innerHTML=data.rows.map(row=>`<article class="v2-card"><div><small>${esc(row.codigo_maestro||`#${row.id}`)}</small><h3>${esc(row.descripcion_actividad||row.marco_logico||'Actividad')}</h3><div class="v2-meta">${esc(row.sector||'')} · ${esc(row.operativo_tecnico||'Trabajo en Equipo')}</div></div><div class="v2-program">${esc(row.programa||'GENERAL')}</div><div class="v2-number"><strong>${Number(row.operativo_meta_alc||0).toLocaleString('es-HN')}</strong><small>de ${Number(row.operativo_meta_obj||0).toLocaleString('es-HN')}</small></div><button class="v2-open" data-id="${row.id}">Detallar</button></article>`).join('');
    renderPages();
  }

  function renderPages(){const totalPages=Math.max(1,Math.ceil(state.total/state.perPage)),from=Math.max(1,state.page-2),to=Math.min(totalPages,state.page+2);let html='';for(let p=from;p<=to;p++)html+=`<button data-page="${p}" class="${p===state.page?'active':''}">${p}</button>`;pages.innerHTML=html;}
  function parseRows(value){try{const parsed=JSON.parse(value||'{}');return parsed&&typeof parsed==='object'?Object.values(parsed):[];}catch(_){return[];}}
  async function openDetail(id){
    const modal=document.getElementById('v2-modal'),detail=document.getElementById('v2-detail');modal.hidden=false;detail.innerHTML='<div class="v2-empty">Cargando detalle...</div>';
    const response=await fetch(`monitoreo_api.php?action=task_detail&id=${encodeURIComponent(id)}`,{headers:{Accept:'application/json'}}),data=await response.json();
    if(!response.ok||data.status!=='ok')throw new Error(data.msg||'No se pudo cargar la actividad.');
    const task=data.task;document.getElementById('v2-code').textContent=task.codigo||`#${task.id}`;document.getElementById('v2-title').textContent=task.actividad;
    const stages=(task.etapas||[]).map((stage,index)=>{const rows=parseRows(stage.involucrados_json).filter(row=>!row.deleted).slice(0,20);return `<section class="v2-stage"><header><h3>${esc(stage.codigo_etapa||`E-${index+1}`)} · ${esc(stage.nombre_etapa||'Etapa')}</h3><p>${esc(stage.descripcion_etapa||'')}</p></header>${rows.length?`<table class="v2-stage-table"><thead><tr><th>Responsable</th><th>Unidad</th><th>Programado</th><th>Cumplido</th><th>A tiempo</th><th>En forma</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.persona||'')}</td><td>${esc(row.unidad||'')}</td><td>${esc(row.a_lograr||0)}</td><td>${esc(row.cumplido||0)}</td><td>${esc(row.a_tiempo??100)}%</td><td>${esc(row.en_forma??100)}%</td></tr>`).join('')}</tbody></table>`:'<div class="v2-empty">Sin líneas registradas en esta etapa.</div>'}</section>`;}).join('');
    detail.innerHTML=`<div class="v2-summary"><div><small>Programa</small><strong>${esc(task.programa)}</strong></div><div><small>Sector</small><strong>${esc(task.sector)}</strong></div><div><small>Meta global</small><strong>${esc(task.m_part_obj)}</strong></div><div><small>Alcanzado</small><strong>${esc(task.m_part_alc)}</strong></div></div>${stages}`;
  }

  document.getElementById('v2-filter').addEventListener('click',()=>{state.q=document.getElementById('v2-search').value.trim();state.programa=document.getElementById('v2-program').value;loadList(1).catch(showError);});
  document.getElementById('v2-search').addEventListener('keydown',event=>{if(event.key==='Enter')document.getElementById('v2-filter').click();});
  list.addEventListener('click',event=>{const button=event.target.closest('.v2-open');if(button)openDetail(button.dataset.id).catch(showError);});
  pages.addEventListener('click',event=>{const button=event.target.closest('button[data-page]');if(button)loadList(Number(button.dataset.page)).catch(showError);});
  document.getElementById('v2-close').addEventListener('click',()=>document.getElementById('v2-modal').hidden=true);
  function showError(error){status.textContent=error.message;status.style.color='#b91c1c';}
  loadList().catch(showError);
})();
