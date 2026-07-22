(() => {
    'use strict';
    document.body.classList.add('monitoreo-v2');
    const title=document.querySelector('.main-wrapper h1');
    if(title&&!title.querySelector('.monitoreo-v2-badge'))title.insertAdjacentHTML('beforeend','<span class="monitoreo-v2-badge"><i class="fa-solid fa-gauge-high"></i> V2 de prueba</span>');
    if('PerformanceObserver'in window){try{const observer=new PerformanceObserver(list=>{for(const entry of list.getEntries()){if(entry.duration>=80)document.dispatchEvent(new CustomEvent('ah:monitor-long-task',{detail:{duration:Math.round(entry.duration)}}));}});observer.observe({type:'longtask',buffered:true});}catch(_){}}
})();
