/* ChartLite 1.0 - renderer local, sin dependencias externas.
 * Compatible con las configuraciones usadas por respuestas_formulario.php.
 */
(function (global) {
  'use strict';

  const DEFAULT_PALETTE = ['#34859B','#46b094','#7aaec0','#a8d8d4','#f3bd5d','#ec8f6a','#8b9dc3','#9b8fc2','#7db48f','#d98373','#6da5d1','#b5a46c'];
  const instances = new Set();

  function n(value) {
    const v = Number(value);
    return Number.isFinite(v) ? v : 0;
  }

  function colorAt(value, index, fallback) {
    if (Array.isArray(value)) return value[index % value.length] || fallback;
    return value || fallback;
  }

  function truncate(ctx, text, maxWidth) {
    text = String(text ?? '');
    if (ctx.measureText(text).width <= maxWidth) return text;
    let out = text;
    while (out.length > 2 && ctx.measureText(out + '…').width > maxWidth) out = out.slice(0, -1);
    return out + '…';
  }

  function roundedRect(ctx, x, y, w, h, r) {
    r = Math.max(0, Math.min(r || 0, Math.abs(w) / 2, Math.abs(h) / 2));
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function niceMax(max) {
    if (!Number.isFinite(max) || max <= 0) return 1;
    const power = Math.pow(10, Math.floor(Math.log10(max)));
    const ratio = max / power;
    const nice = ratio <= 1 ? 1 : ratio <= 2 ? 2 : ratio <= 5 ? 5 : 10;
    return nice * power;
  }

  class ChartLite {
    constructor(canvas, config) {
      if (!(canvas instanceof HTMLCanvasElement)) throw new Error('Canvas inválido');
      this.canvas = canvas;
      this.config = config || {};
      this.ctx = canvas.getContext('2d');
      this._raf = 0;
      this._resizeObserver = null;
      this._boundResize = () => this.resize();
      instances.add(this);

      if (typeof ResizeObserver !== 'undefined') {
        this._resizeObserver = new ResizeObserver(() => this.resize());
        if (canvas.parentElement) this._resizeObserver.observe(canvas.parentElement);
      }
      global.addEventListener('resize', this._boundResize, { passive: true });
      this.resize();
    }

    destroy() {
      cancelAnimationFrame(this._raf);
      instances.delete(this);
      global.removeEventListener('resize', this._boundResize);
      if (this._resizeObserver) this._resizeObserver.disconnect();
      this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    }

    update() { this.resize(); }

    resize() {
      cancelAnimationFrame(this._raf);
      this._raf = requestAnimationFrame(() => {
        const parent = this.canvas.parentElement;
        let cssWidth = Math.floor((parent && parent.clientWidth) || this.canvas.clientWidth || 640);
        let cssHeight = Math.floor((parent && parent.clientHeight) || this.canvas.clientHeight || 300);
        if (cssWidth < 60 || cssHeight < 60) return;
        const dpr = Math.min(global.devicePixelRatio || 1, 2);
        if (this.canvas.width !== Math.round(cssWidth * dpr) || this.canvas.height !== Math.round(cssHeight * dpr)) {
          this.canvas.width = Math.round(cssWidth * dpr);
          this.canvas.height = Math.round(cssHeight * dpr);
          this.canvas.style.width = cssWidth + 'px';
          this.canvas.style.height = cssHeight + 'px';
        }
        this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        this._draw(cssWidth, cssHeight);
      });
    }

    _draw(width, height) {
      const ctx = this.ctx;
      ctx.clearRect(0, 0, width, height);
      ctx.save();
      ctx.font = '12px Inter, Arial, sans-serif';
      ctx.fillStyle = '#64748b';
      ctx.strokeStyle = '#e2e8f0';
      ctx.lineWidth = 1;

      const type = String(this.config.type || 'bar').toLowerCase();
      if (type === 'doughnut' || type === 'pie') this._drawDoughnut(width, height);
      else if (type === 'line') this._drawLine(width, height);
      else this._drawBar(width, height);
      ctx.restore();
    }

    _empty(width, height, message) {
      const ctx = this.ctx;
      ctx.save();
      ctx.fillStyle = '#94a3b8';
      ctx.font = '600 13px Inter, Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(message || 'Sin datos para mostrar', width / 2, height / 2);
      ctx.restore();
    }

    _legend(items, width, height, position) {
      if (!items.length) return 0;
      const ctx = this.ctx;
      const lineHeight = 20;
      const maxRows = Math.max(1, Math.floor((height * 0.30) / lineHeight));
      const shown = items.slice(0, Math.max(4, maxRows * 3));
      let x = 12, y = height - 14 - (Math.ceil(shown.length / 3) - 1) * lineHeight;
      const colWidth = Math.max(130, width / 3);
      ctx.save();
      ctx.font = '11px Inter, Arial, sans-serif';
      shown.forEach((item, i) => {
        const col = i % 3, row = Math.floor(i / 3);
        const px = x + col * colWidth, py = y + row * lineHeight;
        ctx.fillStyle = item.color;
        ctx.fillRect(px, py - 8, 10, 10);
        ctx.fillStyle = '#475569';
        ctx.fillText(truncate(ctx, item.label, colWidth - 24), px + 15, py);
      });
      ctx.restore();
      return Math.ceil(shown.length / 3) * lineHeight + 6;
    }

    _drawDoughnut(width, height) {
      const labels = (this.config.data && this.config.data.labels) || [];
      const dataset = ((this.config.data && this.config.data.datasets) || [])[0] || {};
      const values = (dataset.data || []).map(n);
      const total = values.reduce((a, b) => a + Math.max(0, b), 0);
      if (!labels.length || total <= 0) return this._empty(width, height);

      const showLegend = this.config.options?.plugins?.legend?.display !== false;
      const legendSpace = showLegend ? Math.min(86, Math.ceil(labels.length / 3) * 20 + 8) : 0;
      const drawH = height - legendSpace;
      const cx = width / 2, cy = drawH / 2;
      const radius = Math.max(28, Math.min(width, drawH) * 0.32);
      const inner = radius * 0.58;
      let angle = -Math.PI / 2;
      const ctx = this.ctx;
      values.forEach((value, i) => {
        const portion = Math.max(0, value) / total;
        if (portion <= 0) return;
        const next = angle + portion * Math.PI * 2;
        ctx.beginPath();
        ctx.arc(cx, cy, radius, angle, next);
        ctx.arc(cx, cy, inner, next, angle, true);
        ctx.closePath();
        ctx.fillStyle = colorAt(dataset.backgroundColor, i, DEFAULT_PALETTE[i % DEFAULT_PALETTE.length]);
        ctx.fill();
        angle = next;
      });
      ctx.fillStyle = '#0f172a';
      ctx.font = '800 22px Inter, Arial, sans-serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(String(total), cx, cy - 4);
      ctx.fillStyle = '#64748b';
      ctx.font = '11px Inter, Arial, sans-serif';
      ctx.fillText('Total', cx, cy + 17);

      if (showLegend) {
        this._legend(labels.map((label, i) => ({ label: `${label} (${values[i] || 0})`, color: colorAt(dataset.backgroundColor, i, DEFAULT_PALETTE[i % DEFAULT_PALETTE.length]) })), width, height, 'bottom');
      }
    }

    _axes(plot, max, horizontal) {
      const ctx = this.ctx;
      ctx.save();
      ctx.strokeStyle = '#e2e8f0';
      ctx.fillStyle = '#64748b';
      ctx.font = '10px Inter, Arial, sans-serif';
      const steps = 4;
      if (!horizontal) {
        for (let i = 0; i <= steps; i++) {
          const y = plot.y + plot.h - (plot.h * i / steps);
          ctx.beginPath(); ctx.moveTo(plot.x, y); ctx.lineTo(plot.x + plot.w, y); ctx.stroke();
          ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
          ctx.fillText(String(Math.round(max * i / steps)), plot.x - 7, y);
        }
      } else {
        for (let i = 0; i <= steps; i++) {
          const x = plot.x + plot.w * i / steps;
          ctx.beginPath(); ctx.moveTo(x, plot.y); ctx.lineTo(x, plot.y + plot.h); ctx.stroke();
          ctx.textAlign = 'center'; ctx.textBaseline = 'top';
          ctx.fillText(String(Math.round(max * i / steps)), x, plot.y + plot.h + 6);
        }
      }
      ctx.restore();
    }

    _drawBar(width, height) {
      const labels = (this.config.data && this.config.data.labels) || [];
      const datasets = (this.config.data && this.config.data.datasets) || [];
      if (!labels.length || !datasets.length) return this._empty(width, height);
      const horizontal = this.config.options?.indexAxis === 'y';
      const all = datasets.flatMap(ds => (ds.data || []).map(n));
      const max = niceMax(Math.max(0, ...all));
      const ctx = this.ctx;
      const plot = horizontal
        ? { x: Math.min(190, Math.max(90, width * .27)), y: 12, w: width - Math.min(190, Math.max(90, width * .27)) - 18, h: height - 42 }
        : { x: 44, y: 12, w: width - 58, h: height - 52 };
      this._axes(plot, max, horizontal);

      if (horizontal) {
        const groupH = plot.h / labels.length;
        const barH = Math.max(5, Math.min(24, groupH * .62 / Math.max(1, datasets.length)));
        labels.forEach((label, i) => {
          ctx.fillStyle = '#475569'; ctx.font = '11px Inter, Arial, sans-serif'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
          ctx.fillText(truncate(ctx, label, plot.x - 14), plot.x - 8, plot.y + groupH * (i + .5));
          datasets.forEach((ds, d) => {
            const value = n(ds.data?.[i]);
            const w = max ? plot.w * value / max : 0;
            const y = plot.y + groupH * i + (groupH - barH * datasets.length) / 2 + d * barH;
            ctx.fillStyle = colorAt(ds.backgroundColor, i, DEFAULT_PALETTE[(i + d) % DEFAULT_PALETTE.length]);
            roundedRect(ctx, plot.x, y, Math.max(0, w), Math.max(3, barH - 2), Number(ds.borderRadius || 5)); ctx.fill();
            if (w > 25) { ctx.fillStyle = '#fff'; ctx.font = '700 10px Inter, Arial, sans-serif'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle'; ctx.fillText(String(value), plot.x + w - 5, y + (barH - 2) / 2); }
          });
        });
      } else {
        const groupW = plot.w / labels.length;
        const barW = Math.max(3, Math.min(34, groupW * .72 / Math.max(1, datasets.length)));
        labels.forEach((label, i) => {
          datasets.forEach((ds, d) => {
            const value = n(ds.data?.[i]);
            const h = max ? plot.h * value / max : 0;
            const x = plot.x + groupW * i + (groupW - barW * datasets.length) / 2 + d * barW;
            const y = plot.y + plot.h - h;
            ctx.fillStyle = colorAt(ds.backgroundColor, i, DEFAULT_PALETTE[(i + d) % DEFAULT_PALETTE.length]);
            roundedRect(ctx, x, y, Math.max(2, barW - 2), h, Number(ds.borderRadius || 5)); ctx.fill();
          });
          const labelEvery = labels.length > 15 ? Math.ceil(labels.length / 10) : 1;
          if (i % labelEvery === 0) {
            ctx.save(); ctx.translate(plot.x + groupW * (i + .5), plot.y + plot.h + 8); ctx.rotate(labels.length > 8 ? -0.45 : 0);
            ctx.fillStyle = '#64748b'; ctx.font = '10px Inter, Arial, sans-serif'; ctx.textAlign = labels.length > 8 ? 'right' : 'center'; ctx.textBaseline = 'top';
            ctx.fillText(truncate(ctx, label, Math.max(45, groupW * 1.8)), 0, 0); ctx.restore();
          }
        });
      }
    }

    _drawLine(width, height) {
      const labels = (this.config.data && this.config.data.labels) || [];
      const datasets = (this.config.data && this.config.data.datasets) || [];
      if (!labels.length || !datasets.length) return this._empty(width, height);
      const all = datasets.flatMap(ds => (ds.data || []).map(n));
      const max = niceMax(Math.max(0, ...all));
      const ctx = this.ctx;
      const showLegend = this.config.options?.plugins?.legend?.display !== false && datasets.some(ds => ds.label);
      const legendH = showLegend ? 30 : 0;
      const plot = { x: 44, y: 12, w: width - 58, h: height - 48 - legendH };
      this._axes(plot, max, false);
      const denominator = Math.max(1, labels.length - 1);

      datasets.forEach((ds, d) => {
        const values = (ds.data || []).map(n);
        const points = values.map((value, i) => ({ x: plot.x + plot.w * i / denominator, y: plot.y + plot.h - (max ? plot.h * value / max : 0) }));
        if (!points.length) return;
        if (ds.fill) {
          ctx.beginPath(); ctx.moveTo(points[0].x, plot.y + plot.h); points.forEach(p => ctx.lineTo(p.x, p.y)); ctx.lineTo(points[points.length - 1].x, plot.y + plot.h); ctx.closePath();
          ctx.fillStyle = ds.backgroundColor || 'rgba(52,133,155,.12)'; ctx.fill();
        }
        ctx.beginPath(); points.forEach((p, i) => i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y));
        ctx.strokeStyle = ds.borderColor || DEFAULT_PALETTE[d % DEFAULT_PALETTE.length]; ctx.lineWidth = 2.5; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.stroke();
        ctx.fillStyle = ds.borderColor || DEFAULT_PALETTE[d % DEFAULT_PALETTE.length];
        points.forEach(p => { ctx.beginPath(); ctx.arc(p.x, p.y, 2.7, 0, Math.PI * 2); ctx.fill(); });
      });

      ctx.fillStyle = '#64748b'; ctx.font = '10px Inter, Arial, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
      const every = labels.length > 12 ? Math.ceil(labels.length / 8) : 1;
      labels.forEach((label, i) => { if (i % every === 0 || i === labels.length - 1) ctx.fillText(truncate(ctx, label, 72), plot.x + plot.w * i / denominator, plot.y + plot.h + 7); });

      if (showLegend) {
        this._legend(datasets.filter(ds => ds.label).map((ds, i) => ({ label: ds.label, color: ds.borderColor || DEFAULT_PALETTE[i % DEFAULT_PALETTE.length] })), width, height, 'bottom');
      }
    }
  }

  ChartLite.instances = instances;
  global.Chart = ChartLite;
})(window);
