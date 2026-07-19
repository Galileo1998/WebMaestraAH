const colors = {
    palette: ['#003366', '#00A0A8', '#F38A00', '#10B981', '#F59E0B'],
    excellent: '#10B981',
    improve: '#EF4444'
};

let allData = {};
let charts = {};

async function loadData() {
    try {
        const response = await fetch('patrocinio_data.json');
        allData = await response.json();
        initializeDashboard();
    } catch (error) {
        console.error('Error al cargar JSON:', error);
    }
}

function initializeDashboard() {
    populateFilters();
    updateDashboard();
}

function populateFilters() {
    const tecnicos = new Set();
    const tipos = new Set();

    Object.values(allData).forEach(trimestre => {
        trimestre.forEach(r => {
            if (r.Tecnico) tecnicos.add(r.Tecnico.trim());
            if (r['Tipo de carta']) tipos.add(r['Tipo de carta']);
        });
    });

    const tecnicoFilter = document.getElementById('tecnicoFilter');
    Array.from(tecnicos).sort().forEach(t => tecnicoFilter.add(new Option(t, t)));
    tecnicoFilter.onchange = updateDashboard;

    const tipoOptions = document.getElementById('tipoCartaOptions');
    Array.from(tipos).sort().forEach(tipo => {
        const div = document.createElement('div');
        div.className = 'multi-select-option';
        div.innerHTML = `<input type="checkbox" value="${tipo}" checked> <label>${tipo}</label>`;
        div.querySelector('input').onchange = updateDashboard;
        tipoOptions.appendChild(div);
    });

    document.getElementById('tipoCartaToggle').onclick = () => 
        document.getElementById('tipoCartaDropdown').classList.toggle('active');
    
    document.getElementById('clearFiltersBtn').onclick = () => {
        tecnicoFilter.value = 'todos';
        document.querySelectorAll('#tipoCartaOptions input').forEach(i => i.checked = true);
        updateDashboard();
    };
}

function calculateMetrics(data) {
    let t = 0, et = 0, ft = 0, se = 0;
    data.forEach(r => {
        t += parseInt(r.Total) || 0;
        et += parseInt(r['Enviadas en tiempo']) || 0;
        ft += parseInt(r['Enviadas Fuera de tiempo']) || 0;
        se += (parseInt(r['Sin Enviar Retrasado']) || 0) + (parseInt(r['No se Enviaran']) || 0);
    });
    return { total: t, enTiempo: et, fuera: ft, sinEnviar: se, pct: t > 0 ? (et / t * 100) : 0 };
}

function updateDashboard() {
    const tecnico = document.getElementById('tecnicoFilter').value;
    const tiposChecked = Array.from(document.querySelectorAll('#tipoCartaOptions input:checked')).map(i => i.value);
    
    const filtered = {};
    const kpiContainer = document.getElementById('kpiContainer');
    kpiContainer.innerHTML = '';

    Object.keys(allData).forEach((tri, index) => {
        filtered[tri] = allData[tri].filter(r => 
            (tecnico === 'todos' || r.Tecnico.trim() === tecnico) && 
            tiposChecked.includes(r['Tipo de carta'])
        );

        const m = calculateMetrics(filtered[tri]);
        
        // Renderizar KPIs dinámicamente
        const card = document.createElement('div');
        card.className = `kpi-card ${m.pct >= 95 ? 'status-excellent' : ''}`;
        card.innerHTML = `
            <div class="kpi-label">${tri}</div>
            <div class="kpi-value">${m.total}</div>
            <div class="kpi-pct" style="color: ${m.pct >= 95 ? colors.excellent : colors.improve}">
                ${m.pct.toFixed(1)}%
            </div>
        `;
        kpiContainer.appendChild(card);
    });

    renderCharts(filtered);
    renderTable(filtered);
}

function renderCharts(filtered) {
    const ctx = document.getElementById('comparisonChart');
    const datasets = Object.keys(filtered).map((tri, i) => {
        const m = calculateMetrics(filtered[tri]);
        return {
            label: tri,
            data: [m.enTiempo, m.fuera, m.sinEnviar],
            backgroundColor: colors.palette[i % colors.palette.length]
        };
    });

    if (charts.comp) charts.comp.destroy();
    charts.comp = new Chart(ctx, {
        type: 'bar',
        data: { labels: ['En Tiempo', 'Fuera de Tiempo', 'Sin Enviar'], datasets },
        options: { responsive: true, maintainAspectRatio: false }
    });
}

function renderTable(filtered) {
    const tbody = document.getElementById('techniciansTableBody');
    tbody.innerHTML = '';
    
    Object.keys(filtered).forEach(tri => {
        const tecnicosEnTri = [...new Set(filtered[tri].map(r => r.Tecnico.trim()))];
        tecnicosEnTri.forEach(tec => {
            const data = filtered[tri].filter(r => r.Tecnico.trim() === tec);
            const m = calculateMetrics(data);
            const row = tbody.insertRow();
            row.innerHTML = `
                <td>${tec}</td><td>${tri}</td><td>${m.total}</td>
                <td>${m.enTiempo}</td><td>${m.fuera}</td>
                <td>${m.pct.toFixed(1)}%</td>
                <td><span class="status-badge ${m.pct >= 95 ? 'excellent' : 'improve'}">${m.pct >= 95 ? 'OK' : 'REVISAR'}</span></td>
            `;
        });
    });
}

document.addEventListener('DOMContentLoaded', loadData);