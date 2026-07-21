(function () {
    'use strict';

    const XLSX_SOURCES = [
        'https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js',
        'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js'
    ];
    const SKIP_CLASSES = ['dynamic-table', 'no-border', 'thick-outer', 'receipt-head', 'receipt-body', 'receipt-signature'];
    const SUM_HINTS = /cantidad|monto|total|saldo|meta|logro|program|cumpl|matr[ií]cula|poblaci[oó]n|archivos|hallazgos|actividades|estudiantes|beneficiarios|participantes|hombres|mujeres|femen|mascul/i;
    const AVERAGE_HINTS = /%|porcentaje|promedio|avance|desempeño|a tiempo|en forma/i;
    const NEVER_TOTAL = /(^|\b)(id|n[.º°]?|#|año|periodo|fecha|tel[eé]fono|identidad|c[oó]digo|folio)(\b|$)/i;
    let xlsxPromise = null;

    function isVisible(row) {
        return !row.hidden && row.style.display !== 'none' && getComputedStyle(row).display !== 'none';
    }

    function cleanText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function parseNumber(value) {
        let text = cleanText(value)
            .replace(/L\.?\s*/gi, '')
            .replace(/%/g, '')
            .replace(/\s/g, '');
        if (!text || /[a-záéíóúñ]/i.test(text)) return null;
        if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(text)) text = text.replace(/,/g, '');
        else if (/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(text)) text = text.replace(/\./g, '').replace(',', '.');
        else if (/^-?\d+,\d+$/.test(text)) text = text.replace(',', '.');
        if (!/^-?\d+(\.\d+)?$/.test(text)) return null;
        const number = Number(text);
        return Number.isFinite(number) ? number : null;
    }

    function formatNumber(number, decimals) {
        return new Intl.NumberFormat('es-HN', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    }

    function columnHeader(table, index) {
        const rows = table.tHead ? Array.from(table.tHead.rows) : [];
        const last = rows[rows.length - 1];
        return last && last.cells[index] ? cleanText(last.cells[index].textContent) : '';
    }

    function visibleRows(table) {
        return Array.from(table.tBodies).flatMap(body => Array.from(body.rows)).filter(isVisible);
    }

    function totalColumns(table, rows) {
        const width = Math.max(0, ...rows.map(row => row.cells.length));
        const columns = [];
        for (let index = 0; index < width; index += 1) {
            const header = columnHeader(table, index);
            if (NEVER_TOTAL.test(header)) continue;
            const values = rows.map(row => row.cells[index] ? parseNumber(row.cells[index].textContent) : null);
            const numbers = values.filter(value => value !== null);
            if (!numbers.length || numbers.length / Math.max(rows.length, 1) < .7) continue;
            if (!SUM_HINTS.test(header) && !AVERAGE_HINTS.test(header)) continue;
            columns.push({ index, header, numbers, average: AVERAGE_HINTS.test(header) });
        }
        return { width, columns };
    }

    function updateTotals(table, countLabel) {
        const rows = visibleRows(table);
        countLabel.textContent = `${rows.length} fila${rows.length === 1 ? '' : 's'} visible${rows.length === 1 ? '' : 's'}`;
        let foot = table.querySelector('tfoot[data-ah-generated="true"]');
        const analysis = totalColumns(table, rows);
        if (!analysis.columns.length) {
            if (foot) foot.remove();
            return;
        }
        if (!foot) {
            foot = document.createElement('tfoot');
            foot.dataset.ahGenerated = 'true';
            table.appendChild(foot);
        }
        const totalMap = new Map(analysis.columns.map(column => [column.index, column]));
        const cells = [];
        for (let index = 0; index < analysis.width; index += 1) {
            if (index === 0) {
                cells.push('<th scope="row" class="ah-table-total-label">Total / promedio</th>');
                continue;
            }
            const column = totalMap.get(index);
            if (!column) {
                cells.push('<td></td>');
                continue;
            }
            const value = column.average
                ? column.numbers.reduce((sum, number) => sum + number, 0) / column.numbers.length
                : column.numbers.reduce((sum, number) => sum + number, 0);
            const decimals = column.numbers.some(number => !Number.isInteger(number)) || column.average ? 2 : 0;
            cells.push(`<td>${formatNumber(value, decimals)}${column.average && /%|porcentaje/i.test(column.header) ? '%' : ''}</td>`);
        }
        const markup = `<tr class="ah-table-total-row">${cells.join('')}</tr>`;
        if (foot.innerHTML !== markup) foot.innerHTML = markup;
    }

    function loadXlsx() {
        if (window.XLSX) return Promise.resolve(window.XLSX);
        if (xlsxPromise) return xlsxPromise;
        xlsxPromise = new Promise((resolve, reject) => {
            let sourceIndex = 0;
            const tryNext = () => {
                if (sourceIndex >= XLSX_SOURCES.length) {
                    reject(new Error('No fue posible cargar el componente XLSX.'));
                    return;
                }
                const script = document.createElement('script');
                script.src = XLSX_SOURCES[sourceIndex++];
                const timeout = window.setTimeout(() => {
                    script.remove();
                    tryNext();
                }, 8000);
                script.onload = () => {
                    window.clearTimeout(timeout);
                    window.XLSX ? resolve(window.XLSX) : tryNext();
                };
                script.onerror = () => {
                    window.clearTimeout(timeout);
                    tryNext();
                };
                document.head.appendChild(script);
            };
            tryNext();
        });
        return xlsxPromise;
    }

    function safeFilename(table) {
        const heading = table.closest('section, article, .card, .panel, main')?.querySelector('h1, h2, h3, h4');
        const base = cleanText(heading?.textContent || document.title || 'tabla');
        return base.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/gi, '_').replace(/^_+|_+$/g, '').toLowerCase() || 'tabla';
    }

    function hasExistingExport(table) {
        const parent = table.parentElement;
        const previous = parent?.previousElementSibling;
        const selector = '.btn-xlsx, [onclick*="export" i], [href*="export" i], [download]';
        return !!parent?.querySelector(selector) || !!previous?.matches(selector) || !!previous?.querySelector(selector);
    }

    async function exportTable(table, button) {
        const original = button.innerHTML;
        button.disabled = true;
        button.textContent = 'Preparando...';
        try {
            const XLSX = await loadXlsx();
            const clone = table.cloneNode(true);
            Array.from(clone.tBodies).forEach(body => Array.from(body.rows).forEach((row, index) => {
                const sourceRow = table.tBodies[Array.from(clone.tBodies).indexOf(body)]?.rows[index];
                if (sourceRow && !isVisible(sourceRow)) row.remove();
            }));
            clone.querySelectorAll('[data-ah-generated="true"]').forEach(node => node.remove());
            const sheet = XLSX.utils.table_to_sheet(clone, { raw: true });
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, sheet, 'Datos');
            XLSX.writeFile(workbook, `${safeFilename(table)}.xlsx`);
        } catch (error) {
            window.alert(error.message || 'No fue posible exportar la tabla.');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    }

    function qualifies(table) {
        if (table.dataset.tableUx === 'off' || table.dataset.ahEnhanced === 'true') return false;
        if (!table.tBodies.length || !table.tHead || table.rows[0]?.cells.length < 2) return false;
        if (SKIP_CLASSES.some(className => table.classList.contains(className))) return false;
        if (table.querySelector('tbody input, tbody select, tbody textarea, tbody [contenteditable="true"]')) return false;
        const semanticClass = /data-table|styled-table|centers-table|center-table|base-table|audit-table|table/.test(table.className);
        const context = table.closest('main, section, article, .card, .panel, .content, .main-content') || document;
        const hasFilters = !!context.querySelector('.filters, .filter-bar, [id*="filter" i], [id*="filtro" i], [id*="search" i], [id*="buscar" i], form[method="get" i]');
        return semanticClass || hasFilters || table.dataset.tableUx === 'on';
    }

    function enhance(table) {
        if (!qualifies(table)) return;
        table.dataset.ahEnhanced = 'true';
        const existingExport = hasExistingExport(table);
        const existingParent = table.parentElement;
        const body = existingParent && /table-wrap|table-responsive|overflow|scroll/.test(existingParent.className)
            ? existingParent
            : document.createElement('div');
        if (body !== existingParent) {
            body.className = 'ah-table-scroll-body';
            table.before(body);
            body.appendChild(table);
        } else {
            body.classList.add('ah-table-scroll-body');
        }
        const shell = document.createElement('div');
        shell.className = 'ah-table-shell';
        body.before(shell);
        shell.appendChild(body);

        const tools = document.createElement('div');
        tools.className = 'ah-table-tools';
        const count = document.createElement('span');
        count.className = 'ah-table-count';
        count.setAttribute('aria-live', 'polite');
        const exportButton = document.createElement('button');
        exportButton.type = 'button';
        exportButton.className = 'ah-table-export';
        exportButton.innerHTML = '<i class="fa-solid fa-file-excel" aria-hidden="true"></i> Exportar XLSX';
        exportButton.addEventListener('click', () => exportTable(table, exportButton));
        tools.append(count);
        if (!existingExport) tools.append(exportButton);
        shell.before(tools);

        const topScroll = document.createElement('div');
        topScroll.className = 'ah-table-scroll-top';
        topScroll.setAttribute('aria-label', 'Desplazamiento horizontal superior de la tabla');
        topScroll.innerHTML = '<div class="ah-table-scroll-spacer"></div>';
        shell.before(topScroll);
        let syncing = false;
        topScroll.addEventListener('scroll', () => {
            if (syncing) return;
            syncing = true;
            body.scrollLeft = topScroll.scrollLeft;
            syncing = false;
        });
        body.addEventListener('scroll', () => {
            if (syncing) return;
            syncing = true;
            topScroll.scrollLeft = body.scrollLeft;
            syncing = false;
        });

        const refresh = () => {
            const width = Math.max(table.scrollWidth, table.offsetWidth);
            topScroll.firstElementChild.style.width = `${width}px`;
            topScroll.hidden = width <= body.clientWidth + 1;
            updateTotals(table, count);
        };
        const scheduleRefresh = () => window.requestAnimationFrame(refresh);
        new MutationObserver(scheduleRefresh).observe(table, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });
        if (window.ResizeObserver) new ResizeObserver(scheduleRefresh).observe(body);
        document.addEventListener('input', scheduleRefresh);
        document.addEventListener('change', scheduleRefresh);
        refresh();
    }

    function scan(root) {
        if (root.matches?.('table')) enhance(root);
        root.querySelectorAll?.('table').forEach(enhance);
    }

    function init() {
        scan(document);
        new MutationObserver(mutations => mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
            if (node.nodeType === 1) scan(node);
        }))).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
