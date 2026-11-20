/* Tiny dependency-free SVG charts: line/area, bar, donut, sparkline, horizontal bars. */
(function () {
  const PALETTE = ['#ff3d5a', '#7c5cff', '#36c5ff', '#22c58b', '#ffb020', '#ff8a3d', '#e05cff', '#8d94ad'];
  const NS = 'http://www.w3.org/2000/svg';
  const registry = new Set();

  const fmt = (n) => {
    if (n == null || isNaN(n)) return '–';
    const a = Math.abs(n);
    if (a >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
    if (a >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (a >= 1e4) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return (Math.round(n * 100) / 100).toLocaleString('en');
  };
  const niceMax = (v) => {
    if (v <= 0) return 1;
    const p = Math.pow(10, Math.floor(Math.log10(v)));
    const m = v / p;
    return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p;
  };
  const el = (tag, attrs = {}, parent) => {
    const e = document.createElementNS(NS, tag);
    for (const k in attrs) e.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(e);
    return e;
  };
  const uid = () => 'g' + Math.random().toString(36).slice(2, 8);

  function prepare(host, height) {
    host.innerHTML = '';
    host.classList.add('chart');
    const w = Math.max(240, host.clientWidth || host.parentElement?.clientWidth || 600);
    const svg = el('svg', { viewBox: `0 0 ${w} ${height}`, height });
    host.appendChild(svg);
    const tip = document.createElement('div');
    tip.className = 'tip';
    host.appendChild(tip);
    return { svg, tip, w, h: height };
  }

  function legend(host, series) {
    if (series.length < 2) return;
    const lg = document.createElement('div');
    lg.className = 'legend';
    series.forEach((s, i) => {
      const sp = document.createElement('span');
      sp.innerHTML = `<i style="background:${s.color || PALETTE[i % PALETTE.length]}"></i>`;
      sp.appendChild(document.createTextNode(s.name || ''));
      lg.appendChild(sp);
    });
    host.appendChild(lg);
  }

  function axes(svg, w, h, pad, max, labels) {
    const steps = 4;
    for (let i = 0; i <= steps; i++) {
      const y = pad.t + (h - pad.t - pad.b) * (i / steps);
      el('line', { x1: pad.l, x2: w - pad.r, y1: y, y2: y, class: 'grid-l' }, svg);
      const t = el('text', { x: pad.l - 8, y: y + 4, 'text-anchor': 'end', class: 'axis-t' }, svg);
      t.textContent = fmt(max * (1 - i / steps));
    }
    const n = labels.length;
    const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor((w - pad.l - pad.r) / 70))));
    labels.forEach((lb, i) => {
      if (i % every !== 0 && i !== n - 1) return;
      const x = n === 1 ? (pad.l + w - pad.r) / 2 : pad.l + (w - pad.l - pad.r) * (i / (n - 1));
      const t = el('text', { x, y: h - 6, 'text-anchor': 'middle', class: 'axis-t' }, svg);
      t.textContent = String(lb).length > 10 ? String(lb).slice(5) : lb;
    });
  }

  function showTip(tip, host, x, y, label, rows) {
    tip.innerHTML = `<b>${escapeHtml(label)}</b>` + rows.map(r => `<div><i style="background:${r.c}"></i>${escapeHtml(r.n)}: <b style="display:inline">${fmt(r.v)}</b></div>`).join('');
    const rect = host.getBoundingClientRect();
    const sx = rect.width / host.querySelector('svg').viewBox.baseVal.width;
    tip.style.left = Math.min(Math.max(x * sx, 70), rect.width - 70) + 'px';
    tip.style.top = (y * sx) + 'px';
    tip.style.opacity = 1;
  }
  const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function line(host, o) {
    const height = o.height || 240;
    const { svg, tip, w, h } = prepare(host, height);
    const labels = o.labels || [];
    const series = (o.series || []).map((s, i) => ({ ...s, color: s.color || PALETTE[i % PALETTE.length] }));
    const all = series.flatMap(s => s.data.map(Number));
    const max = niceMax(Math.max(0, ...all));
    const pad = { l: 46, r: 12, t: 12, b: 26 };
    axes(svg, w, h, pad, max, labels);
    const n = labels.length;
    const X = i => n === 1 ? (pad.l + w - pad.r) / 2 : pad.l + (w - pad.l - pad.r) * (i / (n - 1));
    const Y = v => pad.t + (h - pad.t - pad.b) * (1 - (Number(v) || 0) / max);
    series.forEach((s) => {
      const pts = s.data.map((v, i) => [X(i), Y(v)]);
      if (!pts.length) return;
      const d = smooth(pts);
      if (o.area !== false) {
        const id = uid();
        const g = el('linearGradient', { id, x1: 0, y1: 0, x2: 0, y2: 1 }, el('defs', {}, svg));
        el('stop', { offset: '0%', 'stop-color': s.color, 'stop-opacity': series.length > 1 ? .18 : .32 }, g);
        el('stop', { offset: '100%', 'stop-color': s.color, 'stop-opacity': 0 }, g);
        el('path', { d: d + ` L${pts[pts.length - 1][0]},${h - pad.b} L${pts[0][0]},${h - pad.b} Z`, fill: `url(#${id})` }, svg);
      }
      const p = el('path', { d, fill: 'none', stroke: s.color, 'stroke-width': 2.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, svg);
      const len = p.getTotalLength ? p.getTotalLength() : 0;
      if (len) { p.style.strokeDasharray = len; p.style.strokeDashoffset = len; p.getBoundingClientRect(); p.style.transition = 'stroke-dashoffset 1s cubic-bezier(.2,.8,.2,1)'; p.style.strokeDashoffset = 0; }
    });
    // hover
    const guide = el('line', { y1: pad.t, y2: h - pad.b, stroke: 'currentColor', 'stroke-opacity': .25, 'stroke-dasharray': '3 3', opacity: 0 }, svg);
    const dots = series.map(s => el('circle', { r: 4.5, fill: s.color, stroke: 'var(--panel)', 'stroke-width': 2, opacity: 0 }, svg));
    const hit = el('rect', { x: pad.l, y: 0, width: w - pad.l - pad.r, height: h, fill: 'transparent' }, svg);
    hit.addEventListener('mousemove', (e) => {
      const r = svg.getBoundingClientRect();
      const x = (e.clientX - r.left) * (w / r.width);
      const i = Math.max(0, Math.min(n - 1, Math.round((x - pad.l) / ((w - pad.l - pad.r) / Math.max(1, n - 1)))));
      guide.setAttribute('x1', X(i)); guide.setAttribute('x2', X(i)); guide.setAttribute('opacity', 1);
      let topY = h;
      series.forEach((s, k) => { const y = Y(s.data[i]); topY = Math.min(topY, y); dots[k].setAttribute('cx', X(i)); dots[k].setAttribute('cy', y); dots[k].setAttribute('opacity', 1); });
      showTip(tip, host, X(i), topY - 6, labels[i], series.map(s => ({ n: s.name, v: s.data[i], c: s.color })));
    });
    hit.addEventListener('mouseleave', () => { tip.style.opacity = 0; guide.setAttribute('opacity', 0); dots.forEach(d => d.setAttribute('opacity', 0)); });
    legend(host, series);
    track(host, line, o);
  }

  function smooth(pts) {
    if (pts.length < 3) return 'M' + pts.map(p => p.join(',')).join(' L');
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 0; i < pts.length - 1; i++) {
      const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
      const t = .18;
      const c1 = [p1[0] + (p2[0] - p0[0]) * t, p1[1] + (p2[1] - p0[1]) * t];
      const c2 = [p2[0] - (p3[0] - p1[0]) * t, p2[1] - (p3[1] - p1[1]) * t];
      d += ` C${c1[0]},${c1[1]} ${c2[0]},${c2[1]} ${p2[0]},${p2[1]}`;
    }
    return d;
  }

  function bar(host, o) {
    const height = o.height || 240;
    const { svg, tip, w, h } = prepare(host, height);
    const labels = o.labels || [];
    const series = (o.series || []).map((s, i) => ({ ...s, color: s.color || PALETTE[i % PALETTE.length] }));
    const stacked = !!o.stacked;
    const sums = labels.map((_, i) => stacked ? series.reduce((a, s) => a + Math.max(0, Number(s.data[i]) || 0), 0) : Math.max(0, ...series.map(s => Number(s.data[i]) || 0)));
    const max = niceMax(Math.max(0, ...sums));
    const pad = { l: 46, r: 12, t: 12, b: 26 };
    const n = Math.max(1, labels.length);
    // axis labels centered on bars
    for (let i = 0; i <= 4; i++) {
      const y = pad.t + (h - pad.t - pad.b) * (i / 4);
      el('line', { x1: pad.l, x2: w - pad.r, y1: y, y2: y, class: 'grid-l' }, svg);
      el('text', { x: pad.l - 8, y: y + 4, 'text-anchor': 'end', class: 'axis-t' }, svg).textContent = fmt(max * (1 - i / 4));
    }
    const slot = (w - pad.l - pad.r) / n;
    const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor((w - pad.l - pad.r) / 60))));
    const Y = v => (h - pad.t - pad.b) * ((Number(v) || 0) / max);
    labels.forEach((lb, i) => {
      const x0 = pad.l + slot * i;
      const bw = Math.max(2, Math.min(36, slot * .7 / (stacked ? 1 : series.length)));
      let acc = 0;
      series.forEach((s, k) => {
        const v = Math.max(0, Number(s.data[i]) || 0);
        const bh = Y(v);
        const x = stacked ? x0 + (slot - bw) / 2 : x0 + (slot - bw * series.length) / 2 + bw * k;
        const y = h - pad.b - bh - (stacked ? acc : 0);
        const r = el('rect', { x, y: h - pad.b, width: bw, height: 0, rx: Math.min(5, bw / 2.5), fill: s.color }, svg);
        requestAnimationFrame(() => { r.style.transition = 'all .7s cubic-bezier(.2,.8,.2,1)'; r.setAttribute('y', y); r.setAttribute('height', bh); });
        if (stacked) acc += bh;
      });
      if (i % every === 0) el('text', { x: x0 + slot / 2, y: h - 6, 'text-anchor': 'middle', class: 'axis-t' }, svg).textContent = String(lb).length > 10 ? String(lb).slice(5) : lb;
      const hit = el('rect', { x: x0, y: pad.t, width: slot, height: h - pad.t - pad.b, fill: 'transparent' }, svg);
      hit.addEventListener('mousemove', () => showTip(tip, host, x0 + slot / 2, h - pad.b - (stacked ? Y(sums[i]) : Y(sums[i])) - 6, lb, series.map(s => ({ n: s.name, v: s.data[i], c: s.color }))));
      hit.addEventListener('mouseleave', () => tip.style.opacity = 0);
    });
    legend(host, series);
    track(host, bar, o);
  }

  function donut(host, o) {
    host.innerHTML = '';
    const values = (o.values || []).map(Number);
    const labels = o.labels || [];
    const total = values.reduce((a, b) => a + b, 0) || 1;
    const wrap = document.createElement('div');
    wrap.className = 'donut-wrap';
    const svg = el('svg', { viewBox: '0 0 120 120' });
    const R = 46, C = 2 * Math.PI * R;
    let off = 0;
    el('circle', { cx: 60, cy: 60, r: R, fill: 'none', stroke: 'var(--bg2)', 'stroke-width': 16 }, svg);
    values.forEach((v, i) => {
      const len = (v / total) * C;
      const c = el('circle', { cx: 60, cy: 60, r: R, fill: 'none', stroke: PALETTE[i % PALETTE.length], 'stroke-width': 16, 'stroke-dasharray': `0 ${C}`, 'stroke-dashoffset': -off, transform: 'rotate(-90 60 60)' }, svg);
      requestAnimationFrame(() => { c.style.transition = 'stroke-dasharray .9s cubic-bezier(.2,.8,.2,1)'; c.setAttribute('stroke-dasharray', `${Math.max(0, len - 1.5)} ${C}`); });
      off += len;
    });
    const t1 = el('text', { x: 60, y: 58, 'text-anchor': 'middle', fill: 'var(--text)', 'font-size': 17, 'font-weight': 700, 'font-family': 'Space Grotesk' }, svg);
    t1.textContent = fmt(o.center ?? total);
    const t2 = el('text', { x: 60, y: 74, 'text-anchor': 'middle', fill: 'var(--muted)', 'font-size': 9 }, svg);
    t2.textContent = o.centerLabel || '';
    wrap.appendChild(svg);
    const lg = document.createElement('div');
    lg.className = 'donut-legend';
    values.forEach((v, i) => {
      const d = document.createElement('div');
      d.innerHTML = `<i style="background:${PALETTE[i % PALETTE.length]}"></i><span></span><b>${(v / total * 100).toFixed(1)}%</b>`;
      d.querySelector('span').textContent = labels[i] ?? '';
      d.title = fmt(v);
      lg.appendChild(d);
    });
    wrap.appendChild(lg);
    host.appendChild(wrap);
  }

  function spark(host, data, color) {
    host.innerHTML = '';
    const w = Math.max(80, host.clientWidth || 160), h = host.clientHeight || 38;
    const svg = el('svg', { viewBox: `0 0 ${w} ${h}`, preserveAspectRatio: 'none', width: '100%', height: h });
    const d = (data || []).map(Number);
    if (d.length < 2) { host.appendChild(svg); return; }
    const mn = Math.min(...d), mx = Math.max(...d), rg = mx - mn || 1;
    const pts = d.map((v, i) => [i / (d.length - 1) * w, h - 3 - (v - mn) / rg * (h - 6)]);
    const id = uid();
    const g = el('linearGradient', { id, x1: 0, y1: 0, x2: 0, y2: 1 }, el('defs', {}, svg));
    el('stop', { offset: '0%', 'stop-color': color || PALETTE[0], 'stop-opacity': .35 }, g);
    el('stop', { offset: '100%', 'stop-color': color || PALETTE[0], 'stop-opacity': 0 }, g);
    const line = smooth(pts);
    el('path', { d: line + ` L${w},${h} L0,${h} Z`, fill: `url(#${id})` }, svg);
    el('path', { d: line, fill: 'none', stroke: color || PALETTE[0], 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke' }, svg);
    host.appendChild(svg);
  }

  function hbars(host, items, opts = {}) {
    host.innerHTML = '';
    host.className = 'hbars';
    const max = Math.max(1, ...items.map(i => Number(i.value) || 0));
    items.forEach(it => {
      const row = document.createElement('div');
      row.className = 'hbar';
      row.innerHTML = `<span></span><div class="track"><div style="width:0"></div></div><b>${fmt(it.value)}</b>`;
      row.querySelector('span').textContent = it.label;
      row.querySelector('span').title = it.label;
      row.querySelector('span').style.cssText = 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
      host.appendChild(row);
      requestAnimationFrame(() => row.querySelector('.track div').style.width = ((Number(it.value) || 0) / max * 100) + '%');
    });
    if (!items.length) host.innerHTML = `<div class="muted small">${opts.empty || '—'}</div>`;
  }

  /** Render a notebook / generic chart spec {type, labels, series} */
  function render(host, spec) {
    const t = spec.type || 'line';
    if (t === 'donut') {
      const s = (spec.series && spec.series[0]) || { data: [] };
      return donut(host, { labels: spec.labels, values: s.data });
    }
    if (t === 'bar') return bar(host, { labels: spec.labels, series: spec.series, height: 200 });
    return line(host, { labels: spec.labels, series: spec.series, area: t === 'area' || (spec.series || []).length === 1, height: 200 });
  }

  function track(host, fn, o) { host._chart = { fn, o }; registry.add(host); }
  let rt;
  window.addEventListener('resize', () => {
    clearTimeout(rt);
    rt = setTimeout(() => registry.forEach(h => { if (!document.body.contains(h)) return registry.delete(h); h._chart.fn(h, h._chart.o); }), 200);
  });

  window.Charts = { line, bar, donut, spark, hbars, render, fmt, PALETTE };
})();
