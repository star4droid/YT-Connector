/* =========================================================
   YT Connector — dashboard SPA
   ========================================================= */
(() => {
'use strict';

/* ---------------- state & helpers ---------------- */
const S = { boot: null, channels: [], route: '', categories: null };
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
const view = $('#view');
const t = (k, vars, fb) => I18N.t(k, vars, fb);
const h = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const num = (n) => Charts.fmt(Number(n) || 0);
const full = (n) => (Number(n) || 0).toLocaleString('en');
const pct = (n) => (n > 0 ? '+' : '') + (Math.round(n * 10) / 10) + '%';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const dur = (s) => { s = Number(s) || 0; const hh = Math.floor(s / 3600), mm = Math.floor(s % 3600 / 60), ss = s % 60; return (hh ? hh + ':' + String(mm).padStart(2, '0') : mm) + ':' + String(ss).padStart(2, '0'); };
const ago = (ts) => {
  if (!ts) return '—';
  const d = typeof ts === 'number' ? ts * 1000 : Date.parse(ts);
  const s = Math.round((Date.now() - d) / 1000);
  const rtf = new Intl.RelativeTimeFormat(I18N.lang, { numeric: 'auto' });
  const abs = Math.abs(s);
  if (abs < 60) return rtf.format(-s, 'second');
  if (abs < 3600) return rtf.format(-Math.round(s / 60), 'minute');
  if (abs < 86400) return rtf.format(-Math.round(s / 3600), 'hour');
  if (abs < 86400 * 30) return rtf.format(-Math.round(s / 86400), 'day');
  if (abs < 86400 * 365) return rtf.format(-Math.round(s / 86400 / 30), 'month');
  return rtf.format(-Math.round(s / 86400 / 365), 'year');
};
const dateStr = (ts) => { const d = new Date(typeof ts === 'number' ? ts * 1000 : ts); return isNaN(d) ? '—' : d.toLocaleString(I18N.lang === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }); };
const delta = (cur, prev) => { cur = Number(cur) || 0; prev = Number(prev) || 0; if (!prev) return null; return (cur - prev) / prev * 100; };
const deltaHtml = (dd, suffix = '') => dd === null ? '<span class="delta">—</span>' : `<span class="delta ${dd >= 0 ? 'up' : 'down'}">${ic(dd >= 0 ? 'up' : 'down')}<span class="ltr">${pct(dd)}</span>${suffix ? ` <span class="muted">${suffix}</span>` : ''}</span>`;

async function api(a, data = {}, isForm = false) {
  const opts = { method: 'POST', headers: { 'X-CSRF-Token': YTC.csrf } };
  if (isForm) opts.body = data;
  else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
  const r = await fetch('api.php?a=' + encodeURIComponent(a), opts);
  if (r.status === 401) { location.href = 'login.php?next=' + encodeURIComponent(location.pathname + location.hash); throw new Error('unauthorized'); }
  let j; try { j = await r.json(); } catch (e) { throw new Error('HTTP ' + r.status); }
  if (!r.ok) throw new Error(j.error || ('HTTP ' + r.status));
  return j;
}
const tool = (name, args = {}) => api('tool', { name, args }).then(r => r.result);

function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.innerHTML = ic(type === 'ok' ? 'check' : type === 'err' ? 'alert' : 'info') + '<div></div>';
  el.querySelector('div').textContent = msg;
  $('#toasts').appendChild(el);
  setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 260); }, type === 'err' ? 6000 : 3200);
}

function modal({ title, icon, body, foot = '', wide = false, onOpen }) {
  const back = document.createElement('div');
  back.className = 'modal-back';
  back.innerHTML = `<div class="modal ${wide ? 'wide' : ''}" role="dialog" aria-modal="true"><div class="modal-head"><h2>${icon ? ic(icon) : ''}<span>${title}</span></h2><button class="icon-btn ghost" data-close aria-label="close">${ic('x')}</button></div><div class="modal-body">${body}</div>${foot ? `<div class="modal-foot">${foot}</div>` : ''}</div>`;
  const close = () => { back.remove(); document.removeEventListener('keydown', esc); };
  const esc = (e) => e.key === 'Escape' && close();
  back.addEventListener('mousedown', e => { if (e.target === back) close(); });
  back.addEventListener('click', e => { if (e.target.closest('[data-close]')) close(); });
  document.addEventListener('keydown', esc);
  $('#modalRoot').appendChild(back);
  const m = { el: back, close, $: (s) => back.querySelector(s) };
  onOpen && onOpen(m);
  return m;
}

function confirmDlg(title, text, { danger = true, okText } = {}) {
  return new Promise(res => {
    const m = modal({
      title: h(title), icon: danger ? 'alert' : 'info',
      body: `<p class="soft" style="margin:0">${text}</p>`,
      foot: `<button class="btn ghost" data-close>${t('cancel')}</button><button class="btn ${danger ? 'danger' : 'primary'}" data-ok>${okText || t('confirm')}</button>`,
    });
    m.$('[data-ok]').onclick = () => { m.close(); res(true); };
    m.el.addEventListener('click', e => { if (e.target.closest('[data-close]')) res(false); });
  });
}

async function busy(btn, fn) {
  if (!btn) return fn();
  const old = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spin"></span>';
  try { return await fn(); } finally { btn.disabled = false; btn.innerHTML = old; }
}

function copy(text) {
  (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(() => toast(t('copied'))).catch(() => {
    const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); toast(t('copied'));
  });
}
const copyField = (text) => `<div class="copy-field"><code>${h(text)}</code><button type="button" data-copy="${h(text)}">${ic('copy', 'sm')}<span>${t('copy')}</span></button></div>`;
document.addEventListener('click', e => { const b = e.target.closest('[data-copy]'); if (b) copy(b.dataset.copy); });

const chAvatar = (c, cls = 'ch-avatar') => c.thumbnail ? `<img class="${cls}" src="${h(c.thumbnail)}" alt="" referrerpolicy="no-referrer">` : `<div class="${cls}">${ic('youtube', 'lg')}</div>`;
const statusBadge = (c) => c.status === 'connected' ? `<span class="badge ok"><span class="dot"></span>${t('connected')}</span>` : c.status === 'error' ? `<span class="badge bad">${ic('alert')}${t('error')}</span>` : `<span class="badge warn">${ic('key')}${t('pending')}</span>`;
const accessBadge = (a) => `<span class="badge ${a === 'full' ? 'ai' : a === 'read' ? '' : 'bad'}">${ic(a === 'none' ? 'ban' : a === 'read' ? 'eye' : 'bot')}${t('access_' + a)}</span>`;
const privBadge = (p, publishAt, cls = '') => publishAt && p === 'private'
  ? `<span class="badge warn ${cls}">${ic('clock')}${t('scheduled')}</span>`
  : `<span class="badge ${cls || (p === 'public' ? 'ok' : p === 'unlisted' ? 'warn' : '')}">${ic(p === 'public' ? 'globe' : p === 'unlisted' ? 'link' : 'lock')}${t('priv_' + (p || 'private'))}</span>`;
const empty = (icon, title, sub = '', action = '') => `<div class="empty"><div class="ibox lg">${ic(icon)}</div><h3>${title}</h3>${sub ? `<p>${sub}</p>` : ''}${action}</div>`;
const loading = () => '<div class="loader"><span></span></div>';
const errBox = (e) => `<div class="alert error">${ic('alert')}<div>${h(e.message || e)}</div></div>`;
const alertBox = (type, html) => `<div class="alert ${type}">${ic(type === 'warn' || type === 'error' ? 'alert' : type === 'success' ? 'check' : 'info')}<div>${html}</div></div>`;

/* ---------------- markdown (safe subset) ---------------- */
function md(src) {
  let s = h(src || '');
  const blocks = [];
  s = s.replace(/```([\s\S]*?)```/g, (_, c) => { blocks.push('<pre><code>' + c.replace(/^\w*\n/, '') + '</code></pre>'); return `\u0000${blocks.length - 1}\u0000`; });
  const lines = s.split('\n');
  let out = '', list = null, table = [];
  const inline = (x) => x
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<i>$2</i>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
    .replace(/(^|\s)(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
  const flushList = () => { if (list) { out += `</${list}>`; list = null; } };
  const flushTable = () => {
    if (!table.length) return;
    const rows = table.filter(r => !/^\|?\s*:?-{2,}/.test(r));
    out += '<div class="tbl"><table>' + rows.map((r, i) => '<tr>' + r.replace(/^\||\|$/g, '').split('|').map(c => i === 0 ? `<th>${inline(c.trim())}</th>` : `<td>${inline(c.trim())}</td>`).join('') + '</tr>').join('') + '</table></div>';
    table = [];
  };
  for (const raw of lines) {
    const l = raw.trimEnd();
    if (/^\|.*\|$/.test(l.trim())) { flushList(); table.push(l.trim()); continue; } else flushTable();
    let m;
    if ((m = l.match(/^(#{1,4})\s+(.*)/))) { flushList(); out += `<h${m[1].length + 1}>${inline(m[2])}</h${m[1].length + 1}>`; }
    else if ((m = l.match(/^\s*[-*•]\s+(.*)/))) {
      if (list !== 'ul') { flushList(); out += '<ul>'; list = 'ul'; }
      out += `<li>${inline(m[1].replace(/^\[ \]\s*/, '\u0001').replace(/^\[x\]\s*/i, '\u0002')).replace('\u0001', ic('square', 'sm') + ' ').replace('\u0002', ic('checkSquare', 'sm') + ' ')}</li>`;
    }
    else if ((m = l.match(/^\s*\d+[.)]\s+(.*)/))) { if (list !== 'ol') { flushList(); out += '<ol>'; list = 'ol'; } out += `<li>${inline(m[1])}</li>`; }
    else if ((m = l.match(/^&gt;\s?(.*)/))) { flushList(); out += `<blockquote>${inline(m[1])}</blockquote>`; }
    else if (/^-{3,}$/.test(l.trim())) { flushList(); out += '<hr>'; }
    else if (!l.trim()) { flushList(); }
    else { flushList(); out += `<p>${inline(l)}</p>`; }
  }
  flushList(); flushTable();
  return out.replace(/\u0000(\d+)\u0000/g, (_, i) => blocks[i]);
}

/* ---------------- theme ---------------- */
const mq = window.matchMedia ? matchMedia('(prefers-color-scheme: dark)') : null;
function themePref() { try { return localStorage.getItem('ytc_theme') || 'auto'; } catch (e) { return 'auto'; } }
function applyTheme(pref, rerender = true) {
  try { localStorage.setItem('ytc_theme', pref); } catch (e) {}
  const dark = pref === 'dark' || (pref === 'auto' && mq && mq.matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  document.documentElement.dataset.themePref = pref;
  const meta = $('#themeColor'); if (meta) meta.content = dark ? '#0c0e15' : '#f4f5f9';
  paintChrome();
  if (rerender) route();
}
mq && mq.addEventListener && mq.addEventListener('change', () => themePref() === 'auto' && applyTheme('auto'));
const themeIcon = () => ({ light: 'sun', dark: 'moon', auto: 'monitor' }[themePref()] || 'monitor');
function cycleTheme() { const order = ['light', 'dark', 'auto']; applyTheme(order[(order.indexOf(themePref()) + 1) % 3]); toast(t('theme') + ': ' + t('theme_' + themePref()), 'info'); }

/* ---------------- layout: nav, drawer, bottom bar ---------------- */
const NAV = [
  ['overview', 'dashboard', 'nav_overview'],
  ['channels', 'youtube', 'nav_channels'],
  ['upload', 'upload', 'nav_upload'],
  ['notebook', 'book', 'nav_notebook'],
  ['media', 'image', 'nav_media'],
  ['telegram', 'send', 'nav_telegram'],
  ['connector', 'zap', 'nav_connector'],
  ['activity', 'activity', 'nav_activity'],
  ['settings', 'settings', 'nav_settings'],
];
const openNav = () => document.body.classList.add('nav-open');
const closeNav = () => document.body.classList.remove('nav-open');
$('#menuBtn').onclick = openNav;
$('#backdrop').onclick = closeNav;
$('#sideClose').onclick = closeNav;
$('#sidebar').addEventListener('click', e => { if (e.target.closest('a[href^="#"]')) closeNav(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });
$('#themeBtn').onclick = cycleTheme;
$('#themeBtn2').onclick = cycleTheme;
$('#langBtn').onclick = () => setLang(I18N.lang === 'ar' ? 'en' : 'ar');

function paintChrome() {
  $('#menuBtn').innerHTML = ic('menu');
  $('#sideClose').innerHTML = ic('x');
  $('#themeBtn').innerHTML = ic(themeIcon());
  $('#themeBtn2').innerHTML = ic(themeIcon());
  $('#langBtn').innerHTML = ic('languages');
  $('#logoutBtn').innerHTML = ic('logout', 'flip');
  $('#topUpload').innerHTML = ic('upload', 'sm') + `<span>${t('upload')}</span>`;
  $('#themeBtn').title = $('#themeBtn2').title = t('theme') + ': ' + t('theme_' + themePref());
  $('#langBtn').title = t('language');
  $('#logoutBtn').title = t('logout');
}
function renderNav() {
  const cur = S.route.split('/')[0];
  const isActive = (r) => cur === r || (r === 'channels' && (cur === 'channel' || cur === 'video'));
  $('#nav').innerHTML = NAV.map(([r, icon, k]) => `<a href="#/${r}" class="${isActive(r) ? 'active' : ''}">${ic(icon)}<span>${t(k)}</span></a>`).join('');
  const parts = S.route.split('/');
  $('#sideChannels').innerHTML = S.channels.length ? `<div class="side-title">${t('your_channels')}</div>` + S.channels.map(c =>
    `<a class="side-ch ${(parts[0] === 'channel' || parts[0] === 'video') && parts[1] == c.id ? 'active' : ''}" href="#/channel/${c.id}">${c.thumbnail ? `<img src="${h(c.thumbnail)}" alt="" referrerpolicy="no-referrer">` : '<span class="ph"></span>'}<span>${h(c.title || '—')}</span></a>`).join('') : '';
  $('#bottombar').innerHTML = `
    <a href="#/overview" class="${isActive('overview') || cur === 'welcome' ? 'active' : ''}">${ic('dashboard')}<span>${t('nav_overview')}</span></a>
    <a href="#/channels" class="${isActive('channels') ? 'active' : ''}">${ic('youtube')}<span>${t('nav_channels')}</span></a>
    <a href="#/upload" aria-label="${t('upload')}"><span class="fab">${ic('plus')}</span></a>
    <a href="#/notebook" class="${isActive('notebook') ? 'active' : ''}">${ic('book')}<span>${t('nb_short')}</span></a>
    <button type="button" id="bbMore" class="${['media', 'telegram', 'connector', 'activity', 'settings'].includes(cur) ? 'active' : ''}">${ic('menu')}<span>${t('more')}</span></button>`;
  $('#bbMore').onclick = openNav;
  $$('[data-t]').forEach(e => e.textContent = t(e.dataset.t));
  paintChrome();
}
function crumbs(items) {
  const sep = `<span class="sep">${ic('chevronR', 'sm flip')}</span>`;
  $('#crumbs').innerHTML = items.map((x, i) => i === items.length - 1 ? `<b>${x}</b>` : `<span>${x}</span>${sep}`).join('');
}
function setLang(l) {
  I18N.set(l);
  document.cookie = 'ytc_lang=' + l + ';path=/;max-age=31536000;samesite=lax';
  document.documentElement.lang = l;
  document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
  route();
}

async function refreshBoot() {
  S.boot = await api('boot');
  S.channels = S.boot.channels;
  YTC.csrf = S.boot.csrf;
}

/* ---------------- router ---------------- */
const routes = {
  overview: vOverview, welcome: vWelcome, channels: vChannels, channel: vChannel, video: vVideo,
  upload: vUpload, notebook: vNotebook, media: vMedia, telegram: vTelegram, connector: vConnector, activity: vActivity, settings: vSettings,
};
let routeToken = 0;
async function route() {
  const hash = location.hash.replace(/^#\/?/, '') || 'overview';
  const [path, qs] = hash.split('?');
  S.route = path;
  S.query = new URLSearchParams(qs || '');
  const [name, ...args] = path.split('/');
  closeNav();
  renderNav();
  const fn = routes[name] || vOverview;
  const my = ++routeToken;
  view.innerHTML = loading();
  view.style.animation = 'none'; view.offsetHeight; view.style.animation = '';
  try { await fn(...args); } catch (e) { if (my === routeToken) view.innerHTML = errBox(e); console.error(e); }
  window.scrollTo({ top: 0 });
}
const stale = (tok) => tok !== routeToken;
window.addEventListener('hashchange', route);

/* =========================================================
   VIEWS
   ========================================================= */

/* ---------- Overview ---------- */
async function vOverview() {
  crumbs([t('nav_overview')]);
  const tok = routeToken;
  const d = await api('overview');
  if (stale(tok)) return;
  if (!d.channels.length) return vWelcome();
  const sumHist = (key) => {
    const days = {};
    d.channels.forEach(c => c.history.forEach(hh => { days[hh.day] = (days[hh.day] || 0) + Number(hh[key]); }));
    return Object.keys(days).sort().map(k => days[k]);
  };
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('hello')}</h1><div class="sub">${t('overview_sub')}</div></div>
      <div class="actions"><button class="btn" id="syncAll">${ic('refresh')}<span>${t('sync_all')}</span></button><a class="btn ai" href="#/connector">${ic('zap')}<span>${t('connect_claude')}</span></a></div></div>
    <div class="grid g4">
      ${kpi('users', 'brand', t('subscribers'), d.totals.subscribers, sumHist('subscribers'), '#f0304f')}
      ${kpi('eye', 'ai', t('total_views'), d.totals.views, sumHist('views'), '#6d5dfc')}
      ${kpi('film', 'info', t('videos'), d.totals.videos, sumHist('videos'), '#2fb6f0')}
      ${kpi('bot', 'ok', t('ai_calls_24h'), d.ai_calls_24h, null, '#16a974', `${d.clients} ${t('active_sessions')}`)}
    </div>
    <div class="section-title"><h2>${t('your_channels')}</h2><a class="btn sm ghost" href="#/channels">${t('view_all')}${ic('chevronR', 'sm flip')}</a></div>
    <div class="grid g3">${d.channels.map(channelCard).join('')}<a class="card add-card" href="#/channels?add=1"><div><div class="ibox brand lg">${ic('plus')}</div><b>${t('add_channel')}</b></div></a></div>
    <div class="grid g-main mt2">
      <div class="card"><div class="card-head"><h3>${ic('book')}${t('notebook_latest')}</h3><a class="btn sm ghost" href="#/notebook">${t('view_all')}</a></div>
        ${d.notes.length ? d.notes.map(n => `<div class="act"><div class="ibox sm ${n.author === 'ai' ? 'ai' : 'brand'}">${ic(n.author === 'ai' ? 'bot' : 'user')}</div><div class="a-main"><div class="a-title"><b>${h(n.title)}</b></div><div class="muted small">${t('kind_' + n.kind)} · ${h(n.channel_title || t('general'))}</div></div><time>${ago(n.created_at)}</time></div>`).join('') : empty('book', t('notebook_empty_short'))}
      </div>
      <div class="card"><div class="card-head"><h3>${ic('activity')}${t('recent_activity')}</h3><a class="btn sm ghost" href="#/activity">${t('view_all')}</a></div>
        ${d.activity.length ? d.activity.map(actRow).join('') : `<p class="muted">${t('no_activity')}</p>`}
      </div>
    </div>
    ${d.uploads.length ? `<div class="card mt2"><div class="card-head"><h3>${ic('upload')}${t('recent_uploads')}</h3><a class="btn sm ghost" href="#/upload">${t('view_all')}</a></div>${d.uploads.map(uploadRow).join('')}</div>` : ''}`;
  $$('.kpi .spark').forEach(e => Charts.spark(e, JSON.parse(e.dataset.v), e.dataset.c));
  $$('.ch-spark').forEach(e => Charts.spark(e, JSON.parse(e.dataset.v), '#f0304f'));
  bindChannelCardActions();
  $('#syncAll').onclick = (e) => busy(e.currentTarget, async () => {
    try { await tool('record_snapshot', {}); await refreshBoot(); toast(t('synced')); route(); } catch (err) { toast(err.message, 'err'); }
  });
}
function kpi(icon, tone, label, val, hist, color, sub = '') {
  let dl = '';
  if (hist && hist.length > 1) dl = deltaHtml(delta(hist[hist.length - 1], hist[0]), hist.length + ' ' + t('days'));
  return `<div class="card kpi"><div class="kpi-top"><span class="lbl">${label}</span><span class="ibox ${tone}">${ic(icon)}</span></div>
    <div class="val" title="${full(val)}">${num(val)}</div>${dl || (sub ? `<span class="delta">${h(sub)}</span>` : '')}
    ${hist && hist.length > 1 ? `<div class="spark" data-v="${h(JSON.stringify(hist))}" data-c="${color}"></div>` : ''}</div>`;
}
function channelCard(c) {
  const st = c.stats || {};
  const hist = (c.history || []).map(x => Number(x.subscribers));
  return `<div class="card ch-card">
    <div class="ch-banner" style="${st.banner ? `background-image:url('${h(st.banner)}=w800')` : ''}"></div>
    <div class="ch-body">
      ${chAvatar(c)}
      <div class="ch-title"><h3>${h(c.title || t('new_channel'))}</h3></div>
      <div class="ch-badges">${statusBadge(c)} ${accessBadge(c.ai_access)} ${c.handle ? `<span class="badge ltr">${h(c.handle)}</span>` : ''}</div>
      <div class="ch-stats"><div><b>${num(st.subscribers)}</b><small>${t('subscribers')}</small></div><div><b>${num(st.views)}</b><small>${t('views')}</small></div><div><b>${num(st.videos)}</b><small>${t('videos')}</small></div></div>
      ${hist.length > 1 ? `<div class="ch-spark" style="height:34px;margin-top:10px" data-v="${h(JSON.stringify(hist))}"></div>` : ''}
      ${c.last_error && c.status === 'error' ? `<div class="alert error small">${ic('alert', 'sm')}<div>${h(c.last_error)}</div></div>` : ''}
      <div class="ch-foot">
        ${c.has_refresh_token ? `<a class="btn sm primary" href="#/channel/${c.id}">${t('open')}${ic('chevronR', 'sm flip')}</a>` : `<a class="btn sm primary" href="google.php?channel=${c.id}">${ic('key', 'sm')}${t('authorize_google')}</a>`}
        <span class="spacer"></span>
        <button class="icon-btn sm" data-edit="${c.id}" title="${t('edit')}">${ic('edit')}</button>
        <button class="icon-btn sm" data-sync="${c.id}" title="${t('sync')}">${ic('refresh')}</button>
        <button class="icon-btn sm danger" data-del="${c.id}" title="${t('delete')}">${ic('trash')}</button>
      </div>
    </div></div>`;
}
function actRow(a) {
  let detail = '';
  try { const d = JSON.parse(a.detail || 'null'); detail = d ? (d.error ? d.error : JSON.stringify(d.args || d)) : ''; } catch (e) { detail = a.detail || ''; }
  const ch = S.channels.find(c => c.id == a.channel_id);
  const fail = a.ok == 0;
  const tone = fail ? 'bad' : a.source === 'mcp' ? 'ai' : a.source === 'web' ? 'brand' : a.source === 'oauth' ? 'ok' : '';
  const icon = fail ? 'alert' : { mcp: 'bot', web: 'user', oauth: 'shield', system: 'settings' }[a.source] || 'circle';
  return `<div class="act"><div class="ibox sm ${tone}">${ic(icon)}</div>
    <div class="a-main"><div class="a-title"><b class="mono">${h(a.action)}</b>${ch ? `<span class="badge">${h(ch.title)}</span>` : ''}</div><div class="a-detail">${h(detail)}</div></div><time title="${dateStr(Number(a.created_at))}">${ago(Number(a.created_at))}</time></div>`;
}
function uploadRow(u) {
  const ch = S.channels.find(c => c.id == u.channel_id);
  const st = { done: 'ok', error: 'bad', uploading: 'ai', downloading: 'ai', receiving: 'ai', ready: 'warn', waiting_file: 'warn' }[u.status] || '';
  return `<div class="act"><div class="ibox sm">${ic('film')}</div><div class="a-main"><div class="a-title"><span class="t">${h(u.title || u.filename || '—')}</span><span class="badge ${st}">${t('up_' + u.status, null, u.status)}</span></div>
    <div class="progress ${u.status === 'done' ? '' : 'ai'}" style="margin-top:6px;height:6px"><div style="width:${u.progress_percent}%"></div></div>
    <div class="muted small">${h(ch ? ch.title : '')}${u.error ? ' · ' + h(u.error) : ''}</div></div>
    <div class="row gap-s nowrap">${u.video_id ? `<a class="icon-btn sm" title="${t('edit')}" href="#/video/${u.channel_id}/${u.video_id}">${ic('edit')}</a>` : ''}${['ready', 'uploading', 'downloading', 'error'].includes(u.status) && u.source !== 'mcp' ? `<button class="icon-btn sm" title="${t('resume')}" data-resume="${u.upload_id}">${ic('play')}</button>` : ''}${!['done', 'cancelled', 'expired'].includes(u.status) ? `<button class="icon-btn sm danger" title="${t('cancel')}" data-cancel="${u.upload_id}">${ic('x')}</button>` : ''}</div></div>`;
}

/* ---------- Welcome / setup ---------- */
async function vWelcome() {
  crumbs([t('welcome')]);
  const b = S.boot;
  const hasCh = S.channels.length > 0, hasTok = S.channels.some(c => c.has_refresh_token);
  const step = (done, n, k) => `<div class="step ${done ? 'done' : ''}"><div class="n">${done ? ic('check', 'sm') : n}</div><div><b>${t(k + '_t')}</b><span class="muted">${t(k + '_d')}</span></div></div>`;
  view.innerHTML = `
  <div class="hero">
    <span class="badge ai">${ic('sparkles')}YT Connector v${h(b.version)}</span>
    <h1>${t('welcome_title')}</h1>
    <p>${t('welcome_sub')}</p>
    ${!b.https ? alertBox('warn', t('https_warning')) : ''}
    <div class="steps">${step(hasCh, 1, 'step1')}${step(hasCh, 2, 'step2')}${step(hasTok, 3, 'step3')}${step(false, 4, 'step4')}</div>
    <div class="row mt"><a class="btn primary" href="#/channels?add=1">${ic('plus')}${t('add_channel')}</a><a class="btn ai" href="#/connector">${ic('zap')}${t('connect_claude')}</a></div>
  </div>
  <div class="grid g2 mt">
    <div class="card"><div class="card-head"><h3>${ic('cloud')}${t('gc_title')}</h3></div>${googleHowto()}</div>
    <div class="card"><div class="card-head"><h3>${ic('zap')}${t('mcp_url')}</h3></div>${copyField(b.mcp_url)}<p class="muted small" style="margin-top:8px">${t('mcp_url_hint')}</p>
      <div class="card-head mt2"><h3>${ic('reply')}${t('redirect_uri')}</h3></div>${copyField(b.google_redirect)}<p class="muted small" style="margin-top:8px">${t('redirect_uri_hint')}</p></div>
  </div>`;
}
function googleHowto() {
  return `<ol class="howto">
    <li>${t('gc1')}</li><li>${t('gc2')}</li><li>${t('gc3')}</li><li>${t('gc4')}</li>
    <li>${t('gc5')}<div style="margin-top:6px">${copyField(S.boot.google_redirect)}</div></li>
    <li>${t('gc6')}</li></ol>`;
}

/* ---------- Channels ---------- */
async function vChannels() {
  crumbs([t('nav_channels')]);
  await refreshBoot();
  renderNav();
  const err = S.query.get('google_error');
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('nav_channels')}</h1><div class="sub">${t('channels_sub')}</div></div>
      <div class="actions"><button class="btn primary" id="addCh">${ic('plus')}${t('add_channel')}</button></div></div>
    ${err ? alertBox('error', 'Google: ' + h(err)) : ''}
    ${S.channels.length ? `<div class="grid g3">${S.channels.map(c => channelCard({ ...c, history: [] })).join('')}<div class="card add-card" id="addCh2"><div><div class="ibox brand lg">${ic('plus')}</div><b>${t('add_channel')}</b><p class="muted small">${t('add_channel_hint')}</p></div></div></div>`
      : `<div class="card">${empty('youtube', t('no_channels'), t('no_channels_sub'), `<button class="btn primary" id="addCh3">${ic('plus')}${t('add_channel')}</button>`)}</div>`}`;
  ['#addCh', '#addCh2', '#addCh3'].forEach(s => $(s) && ($(s).onclick = () => channelModal()));
  bindChannelCardActions();
  if (S.query.get('add')) channelModal();
}
function bindChannelCardActions() {
  $$('[data-edit]').forEach(b => b.onclick = () => channelModal(S.channels.find(c => c.id == b.dataset.edit)));
  $$('[data-sync]').forEach(b => b.onclick = () => busy(b, async () => {
    try { await api('channel.sync', { id: b.dataset.sync }); toast(t('synced')); await refreshBoot(); route(); } catch (e) { toast(e.message, 'err'); }
  }));
  $$('[data-del]').forEach(b => b.onclick = () => deleteChannel(S.channels.find(c => c.id == b.dataset.del)));
  $$('[data-resume]').forEach(b => b.onclick = () => { location.hash = '#/upload?resume=' + b.dataset.resume; });
  $$('[data-cancel]').forEach(b => b.onclick = async () => { await api('upload.cancel', { id: b.dataset.cancel }); route(); });
}
async function deleteChannel(c) {
  const m = modal({
    title: t('delete_channel'), icon: 'trash',
    body: `<p class="soft">${t('delete_channel_q', { name: h(c.title) })}</p>
      <div class="stack" style="gap:10px;margin-top:12px"><label class="check-row"><input type="checkbox" id="dRevoke" checked>${t('revoke_google')}</label>
      <label class="check-row"><input type="checkbox" id="dNotes">${t('delete_notes_too')}</label></div>`,
    foot: `<button class="btn ghost" data-close>${t('cancel')}</button><button class="btn danger" id="dOk">${ic('trash', 'sm')}${t('delete')}</button>`,
  });
  m.$('#dOk').onclick = () => busy(m.$('#dOk'), async () => {
    try {
      await api('channel.delete', { id: c.id, revoke: m.$('#dRevoke').checked, delete_notes: m.$('#dNotes').checked });
      m.close(); toast(t('deleted')); await refreshBoot(); location.hash = '#/channels'; route();
    } catch (e) { toast(e.message, 'err'); }
  });
}
function channelModal(c = null) {
  const edit = !!c;
  const m = modal({
    title: edit ? h(c.title) : t('add_channel'), icon: edit ? 'edit' : 'plus',
    wide: true,
    body: `
      <div class="grid g2" style="align-items:start">
        <form class="form" id="chForm" autocomplete="off">
          <label class="field"><span>Client ID</span><input name="client_id" dir="ltr" placeholder="${edit ? h(c.client_id_hint || '') + ' — ' + t('keep_current') : 'xxxx.apps.googleusercontent.com'}" ${edit ? '' : 'required'}></label>
          <label class="field"><span>Client Secret</span><input name="client_secret" type="password" dir="ltr" placeholder="${edit ? t('keep_current') : 'GOCSPX-…'}" ${edit ? '' : 'required'}></label>
          <label class="field"><span>Refresh Token <span class="badge">${t('optional')}</span></span><textarea name="refresh_token" dir="ltr" rows="2" style="min-height:64px" placeholder="${edit && c.has_refresh_token ? t('keep_current') : '1//0g…'}"></textarea>
            <small>${t('refresh_hint')}</small></label>
          <div class="field"><span>${t('ai_access')}</span>
            <div class="seg">${['full', 'read', 'none'].map(a => `<label><input type="radio" name="ai_access" value="${a}" ${(edit ? c.ai_access : 'full') === a ? 'checked' : ''}>${t('access_' + a)}</label>`).join('')}</div>
            <small>${t('ai_access_hint')}</small></div>
          <label class="field"><span>${t('notes')}</span><input name="notes" value="${h(edit ? c.notes || '' : '')}" placeholder="${t('notes_ph')}"></label>
        </form>
        <div class="card tint">
          <div class="card-head"><h3>${ic('cloud')}${t('gc_title')}</h3></div>${googleHowto()}
          ${alertBox('info', `<span class="small">${t('scopes_hint')}</span><br><code style="font-size:11px">youtube, youtube.upload, youtube.force-ssl, yt-analytics.readonly, yt-analytics-monetary.readonly</code>`)}
        </div>
      </div>`,
    foot: `<button class="btn ghost" data-close>${t('cancel')}</button>
      <button class="btn" id="chSave">${ic('save', 'sm')}${t('save')}</button>
      <button class="btn primary" id="chSaveAuth">${ic('key', 'sm')}${t('save_and_authorize')}</button>`,
  });
  const submit = async (authorize, btn) => {
    const data = Object.fromEntries(new FormData(m.$('#chForm')).entries());
    if (edit) { data.id = c.id; for (const k of ['client_id', 'client_secret', 'refresh_token']) if (!data[k]) delete data[k]; }
    if (!edit && (!data.client_id || !data.client_secret)) { toast(t('need_client'), 'err'); return; }
    await busy(btn, async () => {
      try {
        const r = await api('channel.save', data);
        if (r.sync_error) toast(r.sync_error, 'err'); else toast(t('saved'));
        await refreshBoot();
        if (authorize && !data.refresh_token) { location.href = 'google.php?channel=' + r.channel.id; return; }
        m.close();
        location.hash = r.channel.status === 'connected' ? '#/channel/' + r.channel.id : '#/channels';
        route();
      } catch (e) { toast(e.message, 'err'); }
    });
  };
  m.$('#chSave').onclick = (e) => submit(false, e.currentTarget);
  m.$('#chSaveAuth').onclick = (e) => submit(true, e.currentTarget);
}

/* ---------- Channel page ---------- */
const TABS = [['videos', 'film'], ['analytics', 'bars'], ['playlists', 'playlist'], ['comments', 'message'], ['settings', 'settings']];
async function vChannel(id, tab = 'videos') {
  if (!S.boot) await refreshBoot();
  let c = S.channels.find(x => x.id == id);
  if (!c) { await refreshBoot(); c = S.channels.find(x => x.id == id); }
  if (!c) throw new Error(t('channel_not_found'));
  crumbs([t('nav_channels'), h(c.title)]);
  const st = c.stats || {};
  view.innerHTML = `
    <div class="ch-hero">
      <div class="ch-banner" style="${st.banner ? `background-image:url('${h(st.banner)}=w1600')` : ''}"></div>
      <div class="ch-hero-body">
        ${chAvatar(c)}
        <div class="ch-hero-meta">
          <h1>${h(c.title)}</h1>
          <div class="ch-badges">${statusBadge(c)} ${accessBadge(c.ai_access)} ${c.handle ? `<span class="badge ltr">${h(c.handle)}</span>` : ''}</div>
          <div class="ch-hero-stats"><span><b>${num(st.subscribers)}</b>${t('subscribers')}</span><span><b>${num(st.views)}</b>${t('views')}</span><span><b>${num(st.videos)}</b>${t('videos')}</span></div>
        </div>
        <div class="row gap-s">
          ${c.youtube_channel_id ? `<a class="btn sm" target="_blank" href="https://www.youtube.com/channel/${h(c.youtube_channel_id)}">${ic('youtube', 'sm')}YouTube</a><a class="btn sm" target="_blank" href="https://studio.youtube.com/channel/${h(c.youtube_channel_id)}">${ic('sliders', 'sm')}Studio</a>` : ''}
          <a class="btn sm primary" href="#/upload?channel=${c.id}">${ic('upload', 'sm')}${t('upload')}</a>
        </div>
      </div>
    </div>
    ${!c.has_refresh_token ? alertBox('warn', `${t('needs_auth')} <a class="btn sm primary" style="margin-inline-start:8px" href="google.php?channel=${c.id}">${ic('key', 'sm')}${t('authorize_google')}</a>`) : ''}
    ${c.status === 'error' && c.last_error ? alertBox('error', h(c.last_error)) : ''}
    <div class="tabs">${TABS.map(([x, icon]) => `<button data-tab="${x}" class="${x === tab ? 'on' : ''}">${ic(icon, 'sm')}${t('tab_' + x)}</button>`).join('')}</div>
    <div id="tabBody">${loading()}</div>`;
  $$('[data-tab]').forEach(b => b.onclick = () => {
    history.replaceState(null, '', '#/channel/' + id + '/' + b.dataset.tab);
    S.route = 'channel/' + id + '/' + b.dataset.tab;
    $$('[data-tab]').forEach(x => x.classList.toggle('on', x === b));
    b.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
    loadTab(c, b.dataset.tab);
  });
  loadTab(c, tab);
}
function loadTab(c, tab) {
  const body = $('#tabBody');
  body.innerHTML = loading();
  const fn = { videos: tVideos, analytics: tAnalytics, playlists: tPlaylists, comments: tComments, settings: tChSettings }[tab] || tVideos;
  fn(c, body).catch(e => body.innerHTML = errBox(e));
}

async function tVideos(c, body) {
  let token = null, all = [], filter = 'all', layout = localStorage.getItem('ytc_layout') || 'grid', query = '';
  body.innerHTML = `
    <div class="toolbar">
      <div class="input-icon">${ic('search')}<input type="search" id="vq" placeholder="${t('search_videos')}"></div>
      <span class="spacer"></span>
      <div class="seg fit"><button data-l="grid" class="${layout === 'grid' ? 'on' : ''}" title="grid">${ic('grid', 'sm')}</button><button data-l="table" class="${layout === 'table' ? 'on' : ''}" title="list">${ic('list', 'sm')}</button></div>
    </div>
    <div class="filters" style="margin-bottom:16px">${['all', 'public', 'unlisted', 'private', 'scheduled', 'shorts'].map(f => `<button class="fchip ${f === 'all' ? 'on' : ''}" data-f="${f}">${t('f_' + f)}</button>`).join('')}</div>
    <div id="vlist"></div>
    <div class="row" style="justify-content:center;margin-top:20px"><button class="btn" id="vmore" hidden>${t('load_more')}</button></div>`;
  const list = $('#vlist', body);
  const apply = () => {
    const vs = all.filter(v => filter === 'all' || (filter === 'scheduled' ? v.publish_at && v.privacy === 'private' : filter === 'shorts' ? v.is_short : v.privacy === filter && !(filter === 'private' && v.publish_at)));
    if (!vs.length) { list.innerHTML = `<div class="card flat">${empty('film', t('no_videos'))}</div>`; return; }
    if (layout === 'table') {
      list.innerHTML = `<div class="card pad0 flat"><div class="table-wrap"><table class="list-table"><thead><tr><th></th><th>${t('title')}</th><th>${t('privacy')}</th><th class="num">${t('views')}</th><th class="num">${t('likes')}</th><th class="num">${t('comments')}</th><th>${t('date')}</th></tr></thead><tbody>
        ${vs.map(v => `<tr class="click" data-v="${v.id}"><td><img class="thumb-sm" src="${h(v.thumbnail)}" alt="" loading="lazy"></td><td><b>${h(v.title)}</b><div class="muted small">${dur(v.duration_seconds)}${v.is_short ? ' · Shorts' : ''}</div></td><td>${privBadge(v.privacy, v.publish_at)}</td><td class="num">${full(v.views)}</td><td class="num">${full(v.likes)}</td><td class="num">${full(v.comments)}</td><td class="muted small" style="white-space:nowrap">${dateStr(v.publish_at || v.published_at)}</td></tr>`).join('')}
        </tbody></table></div></div>`;
    } else {
      list.innerHTML = `<div class="video-grid">${vs.map(v => `
        <div class="vcard" data-v="${v.id}">
          <div class="vthumb"><img src="${h(v.thumbnail)}" alt="" loading="lazy"><span class="badge dark dur">${dur(v.duration_seconds)}</span><span class="priv">${privBadge(v.privacy, v.publish_at, 'dark')}</span>${v.is_short ? '<span class="badge short">Shorts</span>' : ''}</div>
          <div class="vbody"><div class="vtitle">${h(v.title)}</div>
            <div class="muted small">${v.publish_at && v.privacy === 'private' ? dateStr(v.publish_at) : ago(v.published_at)}</div>
            <div class="vstats"><span>${ic('eye')}${num(v.views)}</span><span>${ic('thumb')}${num(v.likes)}</span><span>${ic('message')}${num(v.comments)}</span></div></div>
        </div>`).join('')}</div>`;
    }
    $$('[data-v]', list).forEach(e => e.onclick = () => location.hash = `#/video/${c.id}/${e.dataset.v}`);
  };
  const load = async (reset) => {
    if (reset) { token = null; all = []; list.innerHTML = `<div class="video-grid">${'<div class="skeleton" style="aspect-ratio:16/13"></div>'.repeat(8)}</div>`; }
    const r = await tool('list_videos', { channel: c.id, max_results: 24, page_token: token, query: query || undefined });
    all = all.concat(r.videos);
    token = r.next_page_token;
    $('#vmore', body).hidden = !token;
    apply();
  };
  $$('[data-f]', body).forEach(b => b.onclick = () => { filter = b.dataset.f; $$('[data-f]', body).forEach(x => x.classList.toggle('on', x === b)); apply(); });
  $$('[data-l]', body).forEach(b => b.onclick = () => { layout = b.dataset.l; localStorage.setItem('ytc_layout', layout); $$('[data-l]', body).forEach(x => x.classList.toggle('on', x === b)); apply(); });
  $('#vmore', body).onclick = (e) => busy(e.currentTarget, () => load(false));
  let qt; $('#vq', body).oninput = (e) => { clearTimeout(qt); qt = setTimeout(() => { query = e.target.value.trim(); load(true).catch(err => list.innerHTML = errBox(err)); }, 500); };
  await load(true);
}

async function tAnalytics(c, body, days = 28) {
  body.innerHTML = `<div class="row between" style="margin-bottom:16px"><div class="seg fit">${[7, 28, 90, 365].map(d => `<button data-d="${d}" class="${d === days ? 'on' : ''}">${d} ${t('days')}</button>`).join('')}</div><span class="muted small ltr" id="aPeriod"></span></div><div id="aBody">${loading()}</div>`;
  $$('[data-d]', body).forEach(b => b.onclick = () => tAnalytics(c, body, Number(b.dataset.d)));
  const [a, snaps] = await Promise.all([tool('analytics_summary', { channel: c.id, days }), api('snapshots', { channel: c.id, days: 90 }).catch(() => ({ history: [] }))]);
  const ab = $('#aBody', body);
  if (!ab) return;
  $('#aPeriod', body).textContent = `${a.period.start} → ${a.period.end}`;
  const T = a.totals || {}, P = a.previous_totals || {};
  const k = (icon, tone, label, key, fmtFn = num) => `<div class="card kpi"><div class="kpi-top"><span class="lbl">${label}</span><span class="ibox ${tone}">${ic(icon)}</span></div><div class="val">${fmtFn(T[key] || 0)}</div>${deltaHtml(delta(T[key], P[key]), t('vs_prev'))}</div>`;
  const daily = a.daily || [];
  const chartCard = (icon, title, id, extra = '') => `<div class="card"><div class="card-head"><h3>${ic(icon)}${title}</h3>${extra}</div><div id="${id}"></div></div>`;
  ab.innerHTML = `
    ${a.errors ? alertBox('warn', h(Object.values(a.errors).join(' · '))) : ''}
    <div class="grid g4">
      ${k('eye', 'brand', t('views'), 'views')}
      ${k('clock', 'ai', t('watch_hours'), 'estimatedMinutesWatched', v => num(v / 60))}
      ${k('users', 'ok', t('net_subs'), 'subscribersGained', v => '+' + num(v))}
      ${k('bars', 'info', t('avg_view_pct'), 'averageViewPercentage', v => (Math.round(v * 10) / 10) + '%')}
    </div>
    <div class="grid g-main mt">${chartCard('trending', t('views_per_day'), 'cViews')}${chartCard('compass', t('traffic_sources'), 'cTraffic')}</div>
    <div class="grid g2 mt">${chartCard('clock', t('watch_time_day'), 'cWatch')}${chartCard('users', t('subs_gained_lost'), 'cSubs')}</div>
    <div class="grid g3 mt">${chartCard('globe', t('top_countries'), 'cGeo')}${chartCard('phone', t('devices'), 'cDev')}${chartCard('user', t('audience'), 'cDemo')}</div>
    <div class="card pad0 mt"><div class="card-head" style="padding:16px 18px 0"><h3>${ic('trophy')}${t('top_videos')}</h3></div><div class="table-wrap" style="margin-top:12px"><table class="list-table"><thead><tr><th></th><th>${t('title')}</th><th class="num">${t('views')}</th><th class="num">${t('watch_hours')}</th><th class="num">${t('avg_view_pct')}</th><th class="num">${t('subs')}</th></tr></thead><tbody>
      ${(a.top_videos || []).map(v => `<tr class="click" data-v="${h(v.video)}"><td><img class="thumb-sm" src="${h(v.thumbnail || '')}" alt=""></td><td>${h(v.title || v.video)}</td><td class="num">${full(v.views)}</td><td class="num">${num(v.estimatedMinutesWatched / 60)}</td><td class="num">${Math.round(v.averageViewPercentage * 10) / 10}%</td><td class="num">+${full(v.subscribersGained)}</td></tr>`).join('') || `<tr><td colspan="6" class="muted">${t('no_data')}</td></tr>`}
    </tbody></table></div></div>
    ${snaps.history.length > 1 ? `<div class="mt">${chartCard('camera', t('snapshot_growth'), 'cSnap', `<span class="muted small">${t('snapshot_hint')}</span>`)}</div>` : ''}`;
  $$('tr[data-v]', ab).forEach(r => r.onclick = () => location.hash = `#/video/${c.id}/${r.dataset.v}`);
  const noData = (s) => $(s).innerHTML = `<p class="muted">${t('no_data')}</p>`;
  const labels = daily.map(r => r.day);
  if (daily.length) {
    Charts.line($('#cViews'), { labels, series: [{ name: t('views'), data: daily.map(r => r.views) }], height: 260 });
    Charts.bar($('#cWatch'), { labels, series: [{ name: t('watch_hours'), data: daily.map(r => Math.round(r.estimatedMinutesWatched / 6) / 10), color: '#6d5dfc' }] });
    Charts.bar($('#cSubs'), { labels, series: [{ name: t('gained'), data: daily.map(r => r.subscribersGained), color: '#16a974' }, { name: t('lost'), data: daily.map(r => r.subscribersLost), color: '#e5384f' }] });
  } else ['#cViews', '#cWatch', '#cSubs'].forEach(noData);
  const tr = a.traffic_sources || [];
  if (tr.length) Charts.donut($('#cTraffic'), { labels: tr.map(r => t('ts_' + r.insightTrafficSourceType, null, r.insightTrafficSourceType)), values: tr.map(r => r.views), centerLabel: t('views') }); else noData('#cTraffic');
  Charts.hbars($('#cGeo'), (a.top_countries || []).map(r => ({ label: countryName(r.country), value: r.views })), { empty: t('no_data') });
  const dv = a.devices || [];
  if (dv.length) Charts.donut($('#cDev'), { labels: dv.map(r => r.deviceType), values: dv.map(r => r.views), centerLabel: t('views') }); else noData('#cDev');
  const demo = {};
  (a.demographics || []).forEach(r => { const k2 = r.ageGroup.replace('age', ''); demo[k2] = (demo[k2] || 0) + Number(r.viewerPercentage); });
  Charts.hbars($('#cDemo'), Object.entries(demo).map(([label, value]) => ({ label, value: Math.round(value * 10) / 10 })), { empty: t('no_data') });
  if ($('#cSnap')) Charts.line($('#cSnap'), { labels: snaps.history.map(x => x.day), series: [{ name: t('subscribers'), data: snaps.history.map(x => x.subscribers) }] });
}
function countryName(cc) {
  try { return new Intl.DisplayNames([I18N.lang], { type: 'region' }).of(cc) || cc; } catch (e) { return cc; }
}

async function tPlaylists(c, body) {
  const r = await tool('list_playlists', { channel: c.id });
  body.innerHTML = `<div class="toolbar"><span class="muted">${r.playlists.length} ${t('playlists')}</span><span class="spacer"></span><button class="btn primary sm" id="plNew">${ic('plus', 'sm')}${t('new_playlist')}</button></div>
    ${r.playlists.length ? `<div class="video-grid">${r.playlists.map(p => `
      <div class="vcard" data-pl="${h(p.id)}"><div class="vthumb">${p.thumbnail ? `<img src="${h(p.thumbnail)}" alt="">` : ''}<span class="badge dark dur">${ic('playlist')} ${p.item_count}</span><span class="priv">${privBadge(p.privacy, null, 'dark')}</span></div>
      <div class="vbody"><div class="vtitle">${h(p.title)}</div><div class="muted small">${h((p.description || '').slice(0, 90))}</div></div></div>`).join('')}</div>` : `<div class="card flat">${empty('playlist', t('no_playlists'))}</div>`}`;
  $('#plNew', body).onclick = () => playlistModal(c, null, () => tPlaylists(c, body));
  $$('[data-pl]', body).forEach(e => e.onclick = () => playlistModal(c, r.playlists.find(p => p.id === e.dataset.pl), () => tPlaylists(c, body)));
}
function playlistModal(c, p, done) {
  const m = modal({
    title: p ? h(p.title) : t('new_playlist'), icon: 'playlist', wide: !!p,
    body: `<form class="form" id="plf"><label class="field"><span>${t('title')}</span><input name="title" value="${h(p?.title || '')}" required maxlength="150"></label>
      <label class="field"><span>${t('description')}</span><textarea name="description" rows="3">${h(p?.description || '')}</textarea></label>
      <div class="field"><span>${t('privacy')}</span><div class="seg">${['public', 'unlisted', 'private'].map(x => `<label><input type="radio" name="privacy" value="${x}" ${(p?.privacy || 'public') === x ? 'checked' : ''}>${t('priv_' + x)}</label>`).join('')}</div></div></form>
      ${p ? `<hr><div class="row between"><h3>${t('videos')}</h3><div class="row nowrap" style="flex:1 1 260px;max-width:360px"><input id="plAdd" placeholder="${t('video_id_or_url')}"><button class="icon-btn" id="plAddBtn">${ic('plus')}</button></div></div><div id="plItems" style="margin-top:10px">${loading()}</div>` : ''}`,
    foot: `${p ? `<button class="btn danger" id="plDel">${ic('trash', 'sm')}${t('delete')}</button><span class="spacer"></span>` : ''}<button class="btn ghost" data-close>${t('cancel')}</button><button class="btn primary" id="plSave">${t('save')}</button>`,
  });
  const loadItems = async () => {
    const r = await tool('list_playlists', { channel: c.id, playlist_id: p.id });
    m.$('#plItems').innerHTML = r.items.length ? r.items.map(i => `<div class="act"><img class="thumb-sm" src="${h(i.thumbnail || '')}" alt=""><div class="a-main"><div class="a-title"><span class="t">${h(i.title)}</span></div><div class="muted small">#${(i.position ?? 0) + 1}</div></div><button class="icon-btn sm danger" data-rm="${h(i.item_id)}">${ic('x')}</button></div>`).join('') : `<p class="muted">${t('empty_playlist')}</p>`;
    $$('[data-rm]', m.el).forEach(b => b.onclick = () => busy(b, async () => { await tool('manage_playlist', { channel: c.id, action: 'remove_item', item_id: b.dataset.rm }); loadItems(); }));
  };
  if (p) {
    loadItems().catch(e => m.$('#plItems').innerHTML = errBox(e));
    m.$('#plAddBtn').onclick = (e) => busy(e.currentTarget, async () => { try { await tool('manage_playlist', { channel: c.id, action: 'add_video', playlist_id: p.id, video_id: m.$('#plAdd').value }); m.$('#plAdd').value = ''; loadItems(); } catch (er) { toast(er.message, 'err'); } });
    m.$('#plDel').onclick = async () => { if (await confirmDlg(t('delete'), t('delete_playlist_q'))) { await tool('manage_playlist', { channel: c.id, action: 'delete', playlist_id: p.id, confirm: true }); m.close(); toast(t('deleted')); done(); } };
  }
  m.$('#plSave').onclick = (e) => busy(e.currentTarget, async () => {
    const f = Object.fromEntries(new FormData(m.$('#plf')).entries());
    try { await tool('manage_playlist', { channel: c.id, action: p ? 'update' : 'create', playlist_id: p?.id, ...f }); toast(t('saved')); m.close(); done(); } catch (er) { toast(er.message, 'err'); }
  });
}

async function tComments(c, body, status = 'published', search = '') {
  body.innerHTML = `<div class="toolbar"><div class="seg fit">${['published', 'heldForReview', 'likelySpam'].map(s => `<button data-s="${s}" class="${s === status ? 'on' : ''}">${t('cm_' + s)}</button>`).join('')}</div>
    <div class="input-icon">${ic('search')}<input type="search" id="cq" placeholder="${t('search_comments')}" value="${h(search)}"></div></div>
    <div class="card"><div id="clist">${loading()}</div></div><div class="row" style="justify-content:center;margin-top:16px"><button class="btn" id="cmore" hidden>${t('load_more')}</button></div>`;
  $$('[data-s]', body).forEach(b => b.onclick = () => tComments(c, body, b.dataset.s, $('#cq', body).value));
  let qt; $('#cq', body).oninput = (e) => { clearTimeout(qt); qt = setTimeout(() => tComments(c, body, status, e.target.value), 600); };
  let token = null;
  const list = $('#clist', body);
  const one = (x, isReply) => `<div class="comment" data-cid="${h(x.comment_id)}">
      <img src="${h(x.author_avatar || '')}" alt="" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
      <div class="cbody"><div class="row gap-s"><b>${h(x.author)}</b><span class="muted small">${ago(x.published_at)}</span>${x.likes ? `<span class="muted small">${ic('thumb', 'sm')} ${x.likes}</span>` : ''}${!isReply && x.video_id ? `<a class="badge" href="#/video/${c.id}/${h(x.video_id)}">${ic('film')}${t('video')}</a>` : ''}</div>
      <div class="ctext">${h(x.text)}</div>
      <div class="row gap-s">${!isReply ? `<button class="btn sm ghost" data-reply>${ic('reply', 'sm')}${t('reply')}</button>` : ''}
        ${status !== 'published' ? `<button class="btn sm ghost" data-st="published">${ic('check', 'sm')}${t('approve')}</button>` : `<button class="btn sm ghost" data-st="heldForReview">${ic('pause', 'sm')}${t('hold')}</button>`}
        <button class="btn sm ghost" data-st="rejected">${ic('ban', 'sm')}${t('reject')}</button><button class="icon-btn sm ghost danger" data-delc title="${t('delete')}">${ic('trash')}</button></div>
      ${(x.replies || []).length ? `<div class="replies">${x.replies.map(r => one(r, true)).join('')}</div>` : ''}</div></div>`;
  const load = async (reset) => {
    const r = await tool('list_comments', { channel: c.id, moderation_status: status, search: search || undefined, page_token: token, max_results: 30 });
    token = r.next_page_token;
    const html = r.threads.map(x => one(x, false)).join('');
    if (reset) list.innerHTML = html || empty('message', t('no_comments')); else list.insertAdjacentHTML('beforeend', html);
    $('#cmore', body).hidden = !token;
  };
  list.addEventListener('click', async (e) => {
    const cm = e.target.closest('.comment'); if (!cm) return;
    const id = cm.dataset.cid;
    if (e.target.closest('[data-reply]')) {
      if ($('.reply-box', cm)) return;
      const box = document.createElement('div'); box.className = 'reply-box';
      box.innerHTML = `<input placeholder="${t('write_reply')}"><button class="btn sm primary">${ic('send', 'sm flip')}${t('send')}</button>`;
      $('.cbody', cm).appendChild(box); $('input', box).focus();
      $('button', box).onclick = (ev) => busy(ev.currentTarget, async () => { try { await tool('manage_comment', { channel: c.id, action: 'reply', comment_id: id, text: $('input', box).value }); toast(t('reply_sent')); box.remove(); } catch (er) { toast(er.message, 'err'); } });
    } else if (e.target.closest('[data-st]')) {
      const b = e.target.closest('[data-st]');
      busy(b, async () => { try { await tool('manage_comment', { channel: c.id, action: 'set_status', comment_id: id, status: b.dataset.st }); toast(t('done')); cm.remove(); } catch (er) { toast(er.message, 'err'); } });
    } else if (e.target.closest('[data-delc]')) {
      if (!await confirmDlg(t('delete'), t('delete_comment_q'))) return;
      try { await tool('manage_comment', { channel: c.id, action: 'delete', comment_id: id }); cm.remove(); toast(t('deleted')); } catch (er) { toast(er.message, 'err'); }
    }
  });
  $('#cmore', body).onclick = (e) => busy(e.currentTarget, () => load(false));
  await load(true);
}

async function tChSettings(c, body) {
  const r = await tool('get_channel', { channel: c.id });
  const y = r.youtube;
  body.innerHTML = `<div class="grid g-main" style="align-items:start">
    <div class="card"><div class="card-head"><h3>${ic('palette')}${t('branding')}</h3></div>
      <form class="form" id="brand">
        <label class="field"><span>${t('description')} <span class="counter" id="bdc"></span></span><textarea name="description" rows="7" maxlength="1000">${h(y.description)}</textarea></label>
        <label class="field"><span>${t('keywords')}</span><input name="keywords" value="${h(y.keywords)}"><small>${t('keywords_hint')}</small></label>
        <div class="grid-2"><label class="field"><span>${t('country')}</span><input name="country" value="${h(y.country || '')}" maxlength="2" dir="ltr" placeholder="US"></label>
        <label class="field"><span>${t('default_language')}</span><input name="default_language" value="${h(y.default_language || '')}" dir="ltr" placeholder="ar"></label></div>
        <div><button class="btn primary">${ic('save', 'sm')}${t('save')}</button></div>
      </form></div>
    <div class="stack">
      <div class="card"><div class="card-head"><h3>${ic('bot')}${t('ai_access')}</h3></div>
        <div class="seg">${['full', 'read', 'none'].map(a => `<button data-a="${a}" class="${c.ai_access === a ? 'on' : ''}">${t('access_' + a)}</button>`).join('')}</div>
        <p class="muted small" style="margin:10px 0 0">${t('ai_access_hint')}</p></div>
      <div class="card"><div class="card-head"><h3>${ic('key')}${t('credentials')}</h3></div>
        <p class="muted small">Client ID: <code>${h(c.client_id_hint || '—')}</code><br>Refresh token: ${c.has_refresh_token ? `<span class="badge ok">${ic('check')}OK</span>` : `<span class="badge bad">${ic('x')}—</span>`}</p>
        <div class="row gap-s"><button class="btn sm" id="editCreds">${ic('edit', 'sm')}${t('edit')}</button><a class="btn sm" href="google.php?channel=${c.id}">${ic('key', 'sm')}${t('reauthorize')}</a></div></div>
      <div class="card"><div class="card-head"><h3>${ic('info')}${t('info')}</h3></div>
        <div class="muted small" style="line-height:2">ID: <code>${h(y.id)}</code><br>${t('created')}: ${dateStr(y.published_at)}<br>${t('topics')}: ${(y.topics || []).map(u => h(decodeURIComponent(u.split('/').pop()).replace(/_/g, ' '))).join(', ') || '—'}</div></div>
      <div class="card"><div class="card-head"><h3>${ic('alert')}${t('danger_zone')}</h3></div><button class="btn danger" id="delCh">${ic('trash', 'sm')}${t('delete_channel')}</button></div>
    </div></div>`;
  const ta = $('textarea', body), cnt = $('#bdc', body);
  const upd = () => { cnt.textContent = ta.value.length + '/1000'; }; ta.oninput = upd; upd();
  $('#brand', body).onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { try { await tool('update_channel', { channel: c.id, ...Object.fromEntries(new FormData(e.target).entries()) }); toast(t('saved')); } catch (er) { toast(er.message, 'err'); } }); };
  $$('[data-a]', body).forEach(b => b.onclick = async () => { await api('channel.save', { id: c.id, ai_access: b.dataset.a }); $$('[data-a]', body).forEach(x => x.classList.toggle('on', x === b)); toast(t('saved')); await refreshBoot(); });
  $('#editCreds', body).onclick = () => channelModal(c);
  $('#delCh', body).onclick = () => deleteChannel(c);
}

/* ---------- Video editor ---------- */
async function vVideo(chId, vid) {
  if (!S.boot) await refreshBoot();
  const c = S.channels.find(x => x.id == chId);
  if (!c) throw new Error(t('channel_not_found'));
  const tok = routeToken;
  const [v, cats] = await Promise.all([
    tool('get_video', { channel: c.id, video_id: vid }),
    S.categories ? Promise.resolve(S.categories) : tool('list_categories', { channel: c.id, region: (c.stats && c.stats.country) || 'US', language: I18N.lang }).then(r => (S.categories = r.categories)).catch(() => []),
  ]);
  if (stale(tok)) return;
  const sn = v.snippet || {}, stt = v.status || {}, sts = v.statistics || {};
  crumbs([t('nav_channels'), h(c.title), h(sn.title)]);
  const localDT = (iso) => { if (!iso) return ''; const d = new Date(iso); const p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
  const state = { title: sn.title || '', description: sn.description || '', tags: [...(sn.tags || [])], category_id: sn.categoryId, privacy: stt.privacyStatus, publish_at: localDT(stt.publishAt), made_for_kids: !!stt.madeForKids, embeddable: stt.embeddable !== false, contains_synthetic_media: !!stt.containsSyntheticMedia, license: stt.license || 'youtube' };
  const orig = JSON.stringify(state);
  const thumbs = sn.thumbnails || {};
  const thumb = (thumbs.maxres || thumbs.standard || thumbs.high || thumbs.medium || {}).url || '';
  view.innerHTML = `
    <div class="page-head"><div><h1 style="overflow-wrap:anywhere">${h(sn.title)}</h1><div class="sub row gap-s">${privBadge(stt.privacyStatus, stt.publishAt)}<span>${dateStr(sn.publishedAt)}</span><span class="ltr">${dur(v.duration_seconds)}</span>${v.processingDetails ? `<span class="badge">${h(v.processingDetails.processingStatus)}</span>` : ''}</div></div>
      <div class="actions"><a class="btn sm" target="_blank" href="${h(v.url)}">${ic('youtube', 'sm')}YouTube</a><a class="btn sm" target="_blank" href="${h(v.studio_url)}">${ic('sliders', 'sm')}Studio</a><button class="btn sm danger" id="vDel">${ic('trash', 'sm')}${t('delete')}</button></div></div>
    <div class="editor">
      <div class="stack">
        <div class="card"><div class="card-head"><h3>${ic('edit')}${t('details')}</h3></div><form class="form" id="vf">
          <label class="field"><span>${t('title')} <span class="counter" id="ctTitle"></span></span><input name="title" maxlength="100" value="${h(state.title)}"></label>
          <label class="field"><span>${t('description')} <span class="counter" id="ctDesc"></span></span><textarea name="description" rows="12" maxlength="5000">${h(state.description)}</textarea></label>
          <div class="field"><span>${t('tags')} <span class="counter" id="ctTags"></span></span><div class="tags-input" id="tagsBox"><input id="tagIn" placeholder="${t('add_tag')}"></div><small>${t('tags_hint')}</small></div>
          <div class="grid-2">
            <label class="field"><span>${t('category')}</span><select name="category_id">${cats.map(x => `<option value="${h(x.id)}" ${x.id == state.category_id ? 'selected' : ''}>${h(x.title)}</option>`).join('') || `<option value="${h(state.category_id)}">${h(state.category_id)}</option>`}</select></label>
            <label class="field"><span>${t('license')}</span><select name="license"><option value="youtube" ${state.license === 'youtube' ? 'selected' : ''}>Standard YouTube</option><option value="creativeCommon" ${state.license === 'creativeCommon' ? 'selected' : ''}>Creative Commons</option></select></label>
          </div>
          <div class="field"><span>${t('privacy')}</span><div class="seg">${['public', 'unlisted', 'private'].map(p => `<label><input type="radio" name="privacy" value="${p}" ${state.privacy === p ? 'checked' : ''}>${ic(p === 'public' ? 'globe' : p === 'unlisted' ? 'link' : 'lock', 'sm')}${t('priv_' + p)}</label>`).join('')}</div></div>
          <label class="field"><span>${ic('clock', 'sm')}${t('schedule')}</span><input type="datetime-local" name="publish_at" value="${h(state.publish_at)}"><small>${t('schedule_hint')}</small></label>
          <div class="grid-2">
            ${switchRow('made_for_kids', t('made_for_kids'), state.made_for_kids)}
            ${switchRow('embeddable', t('embeddable'), state.embeddable)}
            ${switchRow('contains_synthetic_media', t('synthetic'), state.contains_synthetic_media)}
          </div>
        </form></div>
        <div class="card"><div class="card-head"><h3>${ic('trending')}${t('performance_28')}</h3></div><div id="vAna">${loading()}</div></div>
        <div class="card"><div class="card-head"><h3>${ic('captions')}${t('captions')}</h3></div><div id="vCaps">${loading()}</div></div>
      </div>
      <div class="stack sticky">
        <div class="player"><iframe src="https://www.youtube-nocookie.com/embed/${h(v.id)}" allowfullscreen loading="lazy"></iframe></div>
        <div class="statline"><div><b>${num(sts.viewCount)}</b><small>${t('views')}</small></div><div><b>${num(sts.likeCount)}</b><small>${t('likes')}</small></div><div><b>${num(sts.commentCount)}</b><small>${t('comments')}</small></div><div><b>${h(v.contentDetails?.definition?.toUpperCase() || '—')}</b><small>${t('quality')}</small></div></div>
        <div class="card"><div class="card-head"><h3>${ic('image')}${t('thumbnail')}</h3></div>
          <label class="thumb-drop" id="thDrop"><input type="file" accept="image/png,image/jpeg" hidden id="thFile"><img src="${h(thumb)}" id="thImg" alt=""><div class="ov">${ic('upload', 'lg')}<span>${t('change_thumbnail')}</span><small>1280×720 · JPG/PNG · ≤2MB</small></div></label>
          <div class="muted small" style="margin:12px 0 6px">${t('auto_frames')}</div>
          <div class="frames">${[1, 2, 3].map(n => `<img src="https://i.ytimg.com/vi/${h(v.id)}/hq${n}.jpg" alt="" loading="lazy">`).join('')}</div></div>
        ${v.suggestions ? `<div class="card"><div class="card-head"><h3>${ic('bulb')}${t('yt_suggestions')}</h3></div><pre class="mono small muted" style="white-space:pre-wrap;margin:0;direction:ltr">${h(JSON.stringify(v.suggestions, null, 1))}</pre></div>` : ''}
      </div>
    </div>
    <div class="savebar" id="saveBar"><b>${t('unsaved')}</b><span class="spacer"></span><button class="btn ghost" id="vReset">${t('discard')}</button><button class="btn primary" id="vSave">${ic('save', 'sm')}${t('save_changes')}</button></div>`;

  const form = $('#vf');
  const tagsBox = $('#tagsBox'), tagIn = $('#tagIn');
  const renderTags = () => {
    $$('.chip', tagsBox).forEach(x => x.remove());
    state.tags.forEach((tg, i) => { const ch = document.createElement('span'); ch.className = 'chip'; ch.innerHTML = `<span></span><button type="button" aria-label="remove">${ic('x')}</button>`; ch.firstChild.textContent = tg; ch.lastChild.onclick = () => { state.tags.splice(i, 1); renderTags(); dirty(); }; tagsBox.insertBefore(ch, tagIn); });
    const len = state.tags.reduce((a, x) => a + x.length + (x.includes(' ') ? 2 : 0) + 1, 0);
    $('#ctTags').textContent = len + '/500'; $('#ctTags').classList.toggle('over', len > 500);
  };
  const addTags = () => { if (!tagIn.value.trim()) return; tagIn.value.split(/[,،]/).map(x => x.trim()).filter(Boolean).forEach(x => !state.tags.includes(x) && state.tags.push(x)); tagIn.value = ''; renderTags(); dirty(); };
  tagsBox.onclick = () => tagIn.focus();
  tagIn.onkeydown = (e) => {
    if ((e.key === 'Enter' || e.key === ',' || e.key === '،') && tagIn.value.trim()) { e.preventDefault(); addTags(); }
    else if (e.key === 'Backspace' && !tagIn.value && state.tags.length) { state.tags.pop(); renderTags(); dirty(); }
  };
  tagIn.oninput = () => { if (/[,،]/.test(tagIn.value)) addTags(); };
  tagIn.onblur = addTags;
  const counters = () => { $('#ctTitle').textContent = form.title.value.length + '/100'; $('#ctDesc').textContent = form.description.value.length + '/5000'; };
  const read = () => {
    const f = new FormData(form);
    state.title = f.get('title'); state.description = f.get('description'); state.category_id = f.get('category_id'); state.privacy = f.get('privacy'); state.publish_at = f.get('publish_at'); state.license = f.get('license');
    ['made_for_kids', 'embeddable', 'contains_synthetic_media'].forEach(k => state[k] = form[k].checked);
  };
  const dirty = () => { read(); counters(); $('#saveBar').classList.toggle('show', JSON.stringify(state) !== orig); };
  form.addEventListener('input', dirty); form.addEventListener('change', dirty);
  renderTags(); counters();
  $('#vReset').onclick = () => route();
  $('#vSave').onclick = (e) => busy(e.currentTarget, async () => {
    read();
    const o = JSON.parse(orig);
    const args = { channel: c.id, video_id: v.id };
    for (const k of Object.keys(state)) if (JSON.stringify(state[k]) !== JSON.stringify(o[k])) args[k] = state[k];
    if ('publish_at' in args) args.publish_at = state.publish_at ? new Date(state.publish_at).toISOString() : '';
    try { await tool('update_video', args); toast(t('saved')); route(); } catch (er) { toast(er.message, 'err'); }
  });
  $('#vDel').onclick = async () => {
    if (!await confirmDlg(t('delete_video'), t('delete_video_q', { title: h(sn.title) }))) return;
    try { await tool('delete_video', { channel: c.id, video_id: v.id, confirm: true }); toast(t('deleted')); location.hash = '#/channel/' + c.id; } catch (er) { toast(er.message, 'err'); }
  };
  const drop = $('#thDrop'), fi = $('#thFile');
  const sendThumb = async (file) => {
    if (file.size > 2 * 1024 * 1024) return toast(t('thumb_too_big'), 'err');
    const fd = new FormData(); fd.append('file', file); fd.append('channel', c.id); fd.append('video_id', v.id);
    drop.style.opacity = .5;
    try { await api('thumbnail', fd, true); $('#thImg').src = URL.createObjectURL(file); toast(t('thumb_updated')); } catch (er) { toast(er.message, 'err'); } finally { drop.style.opacity = 1; }
  };
  fi.onchange = () => fi.files[0] && sendThumb(fi.files[0]);
  ['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => e.dataTransfer.files[0] && sendThumb(e.dataTransfer.files[0]));
  tool('analytics_summary', { channel: c.id, video_id: v.id, days: 28 }).then(a => {
    const el = $('#vAna'); if (!el) return;
    const T = a.totals || {};
    el.innerHTML = `<div class="statline" style="margin-bottom:14px"><div><b>${num(T.views)}</b><small>${t('views')}</small></div><div><b>${num((T.estimatedMinutesWatched || 0) / 60)}</b><small>${t('watch_hours')}</small></div><div><b>${dur(Math.round(T.averageViewDuration || 0))}</b><small>${t('avg_duration')}</small></div><div><b>${Math.round((T.averageViewPercentage || 0) * 10) / 10}%</b><small>${t('avg_view_pct')}</small></div></div>
      <div class="grid g2"><div><div class="muted small">${t('views_per_day')}</div><div id="vaD"></div></div><div><div class="muted small">${t('retention')}</div><div id="vaR"></div></div></div>`;
    if ((a.daily || []).length) Charts.line($('#vaD'), { labels: a.daily.map(r => r.day), series: [{ name: t('views'), data: a.daily.map(r => r.views) }], height: 180 }); else $('#vaD').innerHTML = `<p class="muted">${t('no_data')}</p>`;
    if ((a.retention || []).length) Charts.line($('#vaR'), { labels: a.retention.map(r => Math.round(r.elapsedVideoTimeRatio * 100) + '%'), series: [{ name: t('retention'), data: a.retention.map(r => Math.round(r.audienceWatchRatio * 1000) / 10), color: '#6d5dfc' }], height: 180 }); else $('#vaR').innerHTML = `<p class="muted">${t('no_data')}</p>`;
  }).catch(e => { const el = $('#vAna'); if (el) el.innerHTML = errBox(e); });
  tool('list_captions', { channel: c.id, video_id: v.id }).then(r => {
    const el = $('#vCaps'); if (!el) return;
    el.innerHTML = r.captions.length ? r.captions.map(cp => `<div class="act"><div class="ibox sm">${ic('captions')}</div><div class="a-main"><div class="a-title"><b>${h(cp.language)}</b>${cp.is_auto ? '<span class="badge">auto</span>' : ''}<span class="t muted">${h(cp.name)}</span></div><div class="muted small">${dateStr(cp.last_updated)}</div></div><button class="btn sm" data-cap="${h(cp.caption_id)}">${t('view_transcript')}</button></div>`).join('') : `<p class="muted">${t('no_captions')}</p>`;
    $$('[data-cap]', el).forEach(b => b.onclick = () => busy(b, async () => {
      try { const tr = await tool('get_transcript', { channel: c.id, video_id: v.id, caption_id: b.dataset.cap, format: 'plain' }); modal({ title: h(tr.caption.language || ''), icon: 'captions', wide: true, body: `<pre style="white-space:pre-wrap;margin:0;max-height:60vh;overflow:auto;font-family:inherit">${h(tr.text)}</pre>`, foot: `<button class="btn" data-copy="${h(tr.text)}">${ic('copy', 'sm')}${t('copy')}</button>` }); } catch (er) { toast(er.message, 'err'); }
    }));
  }).catch(e => { const el = $('#vCaps'); if (el) el.innerHTML = errBox(e); });
}
const switchRow = (name, label, on) => `<label class="toggle-row"><span>${label}</span><span class="switch"><input type="checkbox" name="${name}" ${on ? 'checked' : ''}><i></i></span></label>`;

/* ---------- Upload ---------- */
async function vUpload() {
  crumbs([t('nav_upload')]);
  await refreshBoot();
  const pre = S.query.get('channel');
  const resume = S.query.get('resume');
  const usable = S.channels.filter(c => c.has_refresh_token);
  if (!usable.length) { view.innerHTML = `<div class="card">${empty('key', t('no_ready_channels'), t('no_ready_channels_sub'), `<a class="btn primary" href="#/channels">${t('nav_channels')}</a>`)}</div>`; return; }
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('upload_title')}</h1><div class="sub">${t('upload_sub')}</div></div></div>
    <div class="grid g-main" style="align-items:start">
      <div class="card">
        <div id="upForm">
          <label class="field"><span>${t('channel')}</span><select id="upCh">${usable.map(c => `<option value="${c.id}" ${pre == c.id ? 'selected' : ''}>${h(c.title)}</option>`).join('')}</select></label>
          <label class="dropzone" id="upDrop" style="margin:16px 0"><input type="file" accept="video/*" hidden id="upFile"><div class="ibox brand lg">${ic('upload')}</div><div class="dz-text" id="upFileName">${t('drop_video')}</div><div class="muted small">MP4 · MOV · WEBM · MKV · AVI — ${t('any_size')}</div></label>
          <form class="form" id="upMeta">
            <label class="field"><span>${t('title')} <span class="counter" id="uct">0/100</span></span><input name="title" maxlength="100" required></label>
            <label class="field"><span>${t('description')}</span><textarea name="description" rows="6" maxlength="5000"></textarea></label>
            <label class="field"><span>${t('tags')}</span><input name="tags" placeholder="${t('comma_separated')}"></label>
            <div class="grid-2">
              <label class="field"><span>${t('category')}</span><select name="category_id" id="upCat"><option value="22">People & Blogs</option></select></label>
              <label class="field"><span>${t('schedule')}</span><input type="datetime-local" name="publish_at"></label>
            </div>
            <div class="field"><span>${t('privacy')}</span><div class="seg">${['private', 'unlisted', 'public'].map(p => `<label><input type="radio" name="privacy" value="${p}" ${p === 'private' ? 'checked' : ''}>${ic(p === 'public' ? 'globe' : p === 'unlisted' ? 'link' : 'lock', 'sm')}${t('priv_' + p)}</label>`).join('')}</div></div>
            <div class="grid-2">${switchRow('made_for_kids', t('made_for_kids'), false)}${switchRow('contains_synthetic_media', t('synthetic'), false)}</div>
            <label class="field"><span>${t('thumbnail')} <span class="badge">${t('optional')}</span></span><input type="file" name="thumb" accept="image/png,image/jpeg"></label>
            <button class="btn primary block" id="upGo">${ic('upload', 'sm')}${t('start_upload')}</button>
          </form>
        </div>
        <div id="upProg" hidden>
          <div class="stack" style="gap:10px">
            <div class="phase" id="ph1"><div class="ibox">${ic('cloud')}</div><div><b>${t('phase_server')}</b><div class="progress"><div></div></div><span class="muted small" data-txt></span></div></div>
            <div class="phase" id="ph2"><div class="ibox">${ic('youtube')}</div><div><b>${t('phase_youtube')}</b><div class="progress"><div></div></div><span class="muted small" data-txt></span></div></div>
            <div class="phase" id="ph3"><div class="ibox">${ic('check')}</div><div><b>${t('phase_done')}</b><span class="muted small" data-txt></span></div></div>
          </div>
          ${alertBox('warn', `<span class="small">${t('keep_tab_open')}</span>`)}
        </div>
      </div>
      <div class="stack">
        <div class="card"><div class="card-head"><h3>${ic('bot')}${t('upload_via_claude')}</h3></div><p class="muted small">${t('upload_via_claude_d')}</p>
          <button class="prompt" data-copy="${h(t('upload_prompt_example'))}">${ic('message')}<span>${t('upload_prompt_example')}</span></button></div>
        <div class="card"><div class="card-head"><h3>${ic('upload')}${t('recent_uploads')}</h3><button class="icon-btn sm" id="upRef" title="${t('sync')}">${ic('refresh')}</button></div><div id="upList">${loading()}</div></div>
      </div>
    </div>`;
  const chSel = $('#upCh');
  const loadCats = () => tool('list_categories', { channel: chSel.value, language: I18N.lang }).then(r => { $('#upCat').innerHTML = r.categories.map(x => `<option value="${h(x.id)}" ${x.id === '22' ? 'selected' : ''}>${h(x.title)}</option>`).join(''); }).catch(() => {});
  loadCats(); chSel.onchange = loadCats;
  let file = null;
  const drop = $('#upDrop'), fi = $('#upFile'), meta = $('#upMeta');
  const pick = (f) => { file = f; $('#upFileName').textContent = `${f.name} · ${(f.size / 1048576).toFixed(1)} MB`; if (!meta.title.value) { meta.title.value = f.name.replace(/\.[^.]+$/, '').slice(0, 100); meta.title.dispatchEvent(new Event('input')); } };
  fi.onchange = () => fi.files[0] && pick(fi.files[0]);
  ['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => e.dataTransfer.files[0] && pick(e.dataTransfer.files[0]));
  meta.title.oninput = () => $('#uct').textContent = meta.title.value.length + '/100';
  const loadList = async () => {
    const r = await api('upload.list');
    $('#upList').innerHTML = r.uploads.length ? r.uploads.map(uploadRow).join('') : `<p class="muted" style="margin:0">${t('no_uploads')}</p>`;
    bindChannelCardActions();
    $$('#upList [data-resume]').forEach(b => b.onclick = () => runSteps(Number(b.dataset.resume)));
  };
  $('#upRef').onclick = loadList;
  loadList();
  const phase = (n, p, txt, state) => { const el = $('#ph' + n); el.className = 'phase ' + (state || 'active'); const bar = $('.progress div', el); if (bar) bar.style.width = p + '%'; $('[data-txt]', el).innerHTML = txt || ''; };

  async function runSteps(id) {
    $('#upForm').hidden = true; $('#upProg').hidden = false;
    phase(1, 100, '', 'done');
    for (;;) {
      const r = await api('upload.step', { id });
      const u = r.upload;
      if (u.status === 'error') { phase(2, u.progress_percent, h(u.error)); toast(u.error, 'err'); return; }
      if (u.status === 'done') {
        phase(2, 100, '', 'done');
        phase(3, 100, `<div class="row gap-s" style="margin-top:4px"><a class="btn sm primary" href="#/video/${u.channel_id}/${u.video_id}">${ic('edit', 'sm')}${t('edit_video')}</a><a class="btn sm" target="_blank" href="${h(u.video_url)}">${ic('external', 'sm')}YouTube</a></div>${u.notes.length ? '<div style="margin-top:6px">' + h(u.notes.join(' · ')) + '</div>' : ''}`, 'done');
        toast(t('upload_done')); loadList(); return;
      }
      phase(2, u.progress_percent, `<span class="ltr">${u.progress_percent}% · ${(u.sent_to_youtube / 1048576).toFixed(1)} / ${(u.size / 1048576).toFixed(1)} MB</span>${u.error ? ' · ' + h(u.error) : ''}`);
      if (u.status === 'ready' && u.awaiting_metadata) { toast(t('awaiting_meta'), 'info'); return; }
    }
  }
  if (resume) runSteps(Number(resume)).catch(e => toast(e.message, 'err'));

  meta.onsubmit = async (e) => {
    e.preventDefault();
    if (!file) return toast(t('choose_file'), 'err');
    const f = new FormData(meta);
    const m = { title: f.get('title'), description: f.get('description'), tags: String(f.get('tags') || '').split(/[,،]/).map(x => x.trim()).filter(Boolean), category_id: f.get('category_id'), privacy: f.get('privacy'), made_for_kids: meta.made_for_kids.checked, contains_synthetic_media: meta.contains_synthetic_media.checked };
    if (f.get('publish_at')) m.publish_at = new Date(f.get('publish_at')).toISOString();
    try {
      const { upload } = await api('upload.init', { channel: chSel.value, filename: file.name, size: file.size, mime: file.type || 'video/*', meta: m });
      const thumb = meta.thumb.files[0];
      if (thumb) { const tf = new FormData(); tf.append('id', upload.upload_id); tf.append('file', thumb); await api('upload.thumb', tf, true); }
      $('#upForm').hidden = true; $('#upProg').hidden = false;
      const CH = S.boot.chunk_size;
      let off = 0; const started = Date.now();
      phase(1, 0, '');
      while (off < file.size) {
        const fd = new FormData(); fd.append('id', upload.upload_id); fd.append('offset', off); fd.append('chunk', file.slice(off, off + CH), 'chunk');
        let r, tries = 0;
        for (;;) { try { r = await api('upload.chunk', fd, true); break; } catch (er) { if (++tries > 5) throw er; await sleep(1500 * tries); } }
        off = r.upload.received;
        const sp = off / Math.max(1, (Date.now() - started) / 1000);
        phase(1, Math.floor(off / file.size * 100), `<span class="ltr">${(off / 1048576).toFixed(1)} / ${(file.size / 1048576).toFixed(1)} MB · ${(sp / 1048576).toFixed(1)} MB/s</span>`);
      }
      await runSteps(upload.upload_id);
    } catch (er) { toast(er.message, 'err'); $('#upForm').hidden = false; $('#upProg').hidden = true; }
  };
}

/* ---------- Notebook ---------- */
async function vNotebook() {
  crumbs([t('nav_notebook')]);
  if (!S.boot) await refreshBoot();
  const f = { channel_id: S.query.get('channel') || '', kind: S.query.get('kind') || '', search: '' };
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('notebook_title')}</h1><div class="sub">${t('notebook_sub')}</div></div>
      <div class="actions"><button class="btn" id="nbSnap">${ic('camera', 'sm')}${t('record_snapshot')}</button><button class="btn ai" id="nbNew">${ic('plus', 'sm')}${t('new_entry')}</button></div></div>
    <div class="grid g-main" style="align-items:start">
      <div>
        <div class="toolbar">
          <select id="nbCh" style="flex:1 1 180px;max-width:240px"><option value="">${t('all_channels')}</option>${S.channels.map(c => `<option value="${c.id}" ${f.channel_id == c.id ? 'selected' : ''}>${h(c.title)}</option>`).join('')}</select>
          <div class="input-icon">${ic('search')}<input type="search" id="nbQ" placeholder="${t('search')}"></div>
        </div>
        <div class="filters" style="margin-bottom:18px">${['', 'daily', 'weekly', 'monthly', 'report', 'insight', 'plan', 'task', 'log', 'note'].map(k => `<button class="fchip ${f.kind === k ? 'on' : ''}" data-k="${k}">${k ? t('kind_' + k) : t('all')}</button>`).join('')}</div>
        <div id="nbList">${loading()}</div>
      </div>
      <div class="stack sticky">
        <div class="card"><div class="card-head"><h3>${ic('trending')}${t('growth')}</h3></div><div id="nbGrowth">${loading()}</div></div>
        <div class="card"><div class="card-head"><h3>${ic('bulb')}${t('ask_claude')}</h3></div>
          <div class="prompt-list">${['nb_p1', 'nb_p2', 'nb_p3', 'nb_p4'].map(k => `<button class="prompt" data-copy="${h(t(k))}">${ic('message')}<span>${t(k)}</span></button>`).join('')}</div>
          <p class="muted small" style="margin:10px 0 0">${t('click_to_copy')}</p></div>
      </div>
    </div>`;
  const list = $('#nbList');
  const load = async () => {
    const r = await api('notes', { ...f, limit: 100 });
    list.innerHTML = r.entries.length ? `<div class="timeline">${r.entries.map(noteCard).join('')}</div>` : `<div class="card">${empty('book', t('notebook_empty'), t('notebook_empty_sub'))}</div>`;
    r.entries.forEach(n => (n.data?.charts || []).forEach((cs, i) => { const host = $(`#nc-${n.id}-${i}`); if (host) Charts.render(host, cs); }));
    $$('[data-nedit]', list).forEach(b => b.onclick = () => noteModal(r.entries.find(n => n.id == b.dataset.nedit), load));
    $$('[data-npin]', list).forEach(b => b.onclick = async () => { const n = r.entries.find(x => x.id == b.dataset.npin); await api('note.save', { id: n.id, pinned: !n.pinned }); load(); });
    $$('[data-ndel]', list).forEach(b => b.onclick = async () => { if (await confirmDlg(t('delete'), t('delete_note_q'))) { await api('note.delete', { id: b.dataset.ndel }); toast(t('deleted')); load(); } });
  };
  $('#nbCh').onchange = (e) => { f.channel_id = e.target.value; load(); };
  let qt; $('#nbQ').oninput = (e) => { clearTimeout(qt); qt = setTimeout(() => { f.search = e.target.value; load(); }, 400); };
  $$('[data-k]').forEach(b => b.onclick = () => { f.kind = b.dataset.k; $$('[data-k]').forEach(x => x.classList.toggle('on', x === b)); load(); });
  $('#nbNew').onclick = () => noteModal(null, load);
  const growth = async () => {
    const g = $('#nbGrowth');
    const res = await Promise.all(S.channels.map(c => api('snapshots', { channel: c.id, days: 60 }).then(r => ({ c, h: r.history }))));
    const withData = res.filter(x => x.h.length > 1);
    if (!withData.length) { g.innerHTML = `<p class="muted small" style="margin:0">${t('growth_empty')}</p>`; return; }
    g.innerHTML = withData.map((x, i) => `<div style="margin-bottom:14px"><div class="row between"><b class="small">${h(x.c.title)}</b><span class="muted small ltr">${num(x.h[x.h.length - 1].subscribers)}</span></div><div id="gr${i}"></div></div>`).join('');
    withData.forEach((x, i) => Charts.line($('#gr' + i), { labels: x.h.map(d => d.day), series: [{ name: t('subscribers'), data: x.h.map(d => d.subscribers), color: Charts.PALETTE[i % 8] }], height: 120 }));
  };
  $('#nbSnap').onclick = (e) => busy(e.currentTarget, async () => { try { await tool('record_snapshot', {}); toast(t('synced')); growth(); } catch (er) { toast(er.message, 'err'); } });
  load().catch(e => list.innerHTML = errBox(e));
  growth().catch(e => $('#nbGrowth').innerHTML = errBox(e));
}
function noteCard(n) {
  const d = n.data || {};
  return `<div class="note k-${h(n.kind)}"><div class="card">
    <div class="note-head"><h3>${n.pinned ? ic('pin', 'sm') : ''}<span>${h(n.title)}</span></h3>
      <div class="row gap-s nowrap"><button class="icon-btn sm ghost" data-npin="${n.id}" title="${t('pin')}">${ic('pin')}</button><button class="icon-btn sm ghost" data-nedit="${n.id}" title="${t('edit')}">${ic('edit')}</button><button class="icon-btn sm ghost danger" data-ndel="${n.id}" title="${t('delete')}">${ic('trash')}</button></div></div>
    <div class="note-meta"><span class="badge ${n.author === 'ai' ? 'ai' : 'brand'}">${ic(n.author === 'ai' ? 'bot' : 'user')}${n.author === 'ai' ? 'AI' : t('you')}</span><span class="badge">${t('kind_' + n.kind)}</span>${n.channel_title ? `<span class="badge">${ic('youtube')}${h(n.channel_title)}</span>` : ''}${n.period_start ? `<span class="row gap-s">${ic('calendar', 'sm')}<span class="ltr">${h(n.period_start)}${n.period_end ? ' → ' + h(n.period_end) : ''}</span></span>` : ''}<span title="${dateStr(n.created_at)}">${ago(n.created_at)}</span>${(n.tags || []).map(x => `<span>#${h(x)}</span>`).join(' ')}</div>
    ${(d.metrics || []).length ? `<div class="note-metrics">${d.metrics.map(m => `<div><small>${h(m.label)}</small><b>${typeof m.value === 'number' ? num(m.value) : h(m.value)}${h(m.unit || '')}</b>${m.delta != null ? deltaHtml(m.delta) : ''}</div>`).join('')}</div>` : ''}
    <div class="md">${md(n.body)}</div>
    ${(d.charts || []).length ? `<div class="note-charts">${d.charts.map((c, i) => `<div class="note-chart"><h4>${h(c.title)}</h4><div id="nc-${n.id}-${i}"></div></div>`).join('')}</div>` : ''}
  </div></div>`;
}
function noteModal(n, done) {
  const m = modal({
    title: n ? t('edit_entry') : t('new_entry'), icon: n ? 'edit' : 'plus', wide: true,
    body: `<form class="form" id="nf">
      <div class="grid-2"><label class="field"><span>${t('channel')}</span><select name="channel_id"><option value="">${t('general')}</option>${S.channels.map(c => `<option value="${c.id}" ${n?.channel_id == c.id ? 'selected' : ''}>${h(c.title)}</option>`).join('')}</select></label>
      <label class="field"><span>${t('kind')}</span><select name="kind">${['note', 'daily', 'weekly', 'monthly', 'report', 'insight', 'plan', 'task', 'log'].map(k => `<option value="${k}" ${(n?.kind || 'note') === k ? 'selected' : ''}>${t('kind_' + k)}</option>`).join('')}</select></label></div>
      <label class="field"><span>${t('title')}</span><input name="title" required value="${h(n?.title || '')}"></label>
      <label class="field"><span>${t('content')} <span class="badge">Markdown</span></span><textarea name="body" rows="10">${h(n?.body || '')}</textarea></label>
      <label class="field"><span>${t('tags')}</span><input name="tags" value="${h((n?.tags || []).join(', '))}" placeholder="${t('comma_separated')}"></label>
      <label class="check-row"><input type="checkbox" name="pinned" ${n?.pinned ? 'checked' : ''}>${t('pin')}</label></form>`,
    foot: `<button class="btn ghost" data-close>${t('cancel')}</button><button class="btn ai" id="nSave">${ic('save', 'sm')}${t('save')}</button>`,
  });
  m.$('#nSave').onclick = (e) => busy(e.currentTarget, async () => {
    const f = Object.fromEntries(new FormData(m.$('#nf')).entries());
    f.pinned = !!m.$('[name=pinned]').checked;
    if (n) f.id = n.id;
    try { await api('note.save', f); m.close(); toast(t('saved')); done(); } catch (er) { toast(er.message, 'err'); }
  });
}

/* ---------- Media sources (Pixabay / Wikimedia) ---------- */
async function vMedia() {
  crumbs([t('nav_media')]);
  if (!S.boot) await refreshBoot();
  const ms = await api('media.settings');
  const sw = (id, on) => `<span class="switch"><input type="checkbox" id="${id}" ${on ? 'checked' : ''}><i></i></span>`;
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('nav_media')}</h1><div class="sub">${t('media_sub')}</div></div></div>
    ${!ms.gd ? alertBox('warn', t('media_no_gd')) : ''}
    <div class="grid g2" style="align-items:start">
      <div class="card">
        <div class="card-head"><span class="ibox ok">${ic('image')}</span><h3>Pixabay</h3>${ms.pixabay.has_key ? `<span class="badge ok">${ic('check')}${t('media_key_ok')}</span>` : `<span class="badge warn">${ic('key')}${t('media_no_key')}</span>`}</div>
        <p class="muted small">${t('pixabay_d')}</p>
        <div class="stack" style="gap:12px">
          <label class="toggle-row"><span>${t('media_enable')}</span>${sw('pxOn', ms.pixabay.enabled)}</label>
          <label class="toggle-row"><span>${t('safesearch')}</span>${sw('pxSafe', ms.pixabay.safesearch)}</label>
          <form class="field" id="pxForm"><span>API key ${ms.pixabay.key_hint ? `<span class="badge ltr">${h(ms.pixabay.key_hint)}</span>` : ''}</span>
            <div class="row nowrap"><input type="password" name="key" dir="ltr" autocomplete="off" placeholder="${ms.pixabay.has_key ? t('keep_current') : '12345678-abcdef0123456789…'}"><button class="btn primary">${ic('save', 'sm')}${t('save')}</button></div>
            <small>${t('pixabay_key_hint')}</small></form>
          ${ms.pixabay.has_key ? `<div><button class="btn sm danger" id="pxDel">${ic('trash', 'sm')}${t('remove_key')}</button></div>` : ''}
        </div>
      </div>
      <div class="card">
        <div class="card-head"><span class="ibox info">${ic('globe')}</span><h3>Wikimedia Commons</h3><span class="badge ok">${ic('check')}${t('no_key_needed')}</span></div>
        <p class="muted small">${t('wikimedia_d')}</p>
        <label class="toggle-row"><span>${t('media_enable')}</span>${sw('wmOn', ms.wikimedia.enabled)}</label>
        <div class="card-head mt"><h3>${ic('bot')}${t('ask_claude')}</h3></div>
        <div class="prompt-list">${['mp1', 'mp2', 'mp3'].map(k => `<button class="prompt" data-copy="${h(t(k))}">${ic('message')}<span>${t(k)}</span></button>`).join('')}</div>
      </div>
    </div>
    <div class="card mt">
      <div class="card-head"><h3>${ic('search')}${t('media_try')}</h3></div>
      <form class="toolbar" id="msForm">
        <div class="seg fit" id="msSrc">
          <button type="button" data-src="px_image" class="on">${ic('image', 'sm')}${t('px_images')}</button>
          <button type="button" data-src="px_video">${ic('film', 'sm')}${t('px_videos')}</button>
          <button type="button" data-src="wm">${ic('globe', 'sm')}Wikimedia</button>
        </div>
        <div class="input-icon">${ic('search')}<input type="search" name="q" placeholder="${t('media_q_ph')}" required></div>
        <select name="opt" id="msOpt" style="flex:0 1 180px"></select>
        <button class="btn primary">${t('search_btn')}</button>
      </form>
      <div id="msRes"><div class="empty">${t('media_try_d')}</div></div>
    </div>`;
  const save = async (data) => { try { await api('media.save', data); toast(t('saved')); } catch (e) { toast(e.message, 'err'); route(); } };
  $('#pxOn').onchange = e => save({ pixabay_enabled: e.target.checked });
  $('#pxSafe').onchange = e => save({ pixabay_safesearch: e.target.checked });
  $('#wmOn').onchange = e => save({ wikimedia_enabled: e.target.checked });
  $('#pxForm').onsubmit = (e) => {
    e.preventDefault();
    const key = e.target.key.value.trim(); if (!key) return;
    busy($('button', e.target), async () => { try { await api('media.save', { pixabay_key: key }); toast(t('media_key_saved')); route(); } catch (er) { toast(er.message, 'err'); } });
  };
  $('#pxDel') && ($('#pxDel').onclick = async () => { if (await confirmDlg(t('remove_key'), t('remove_key_q'))) { await api('media.save', { remove_pixabay_key: 1 }); route(); } });

  let src = 'px_image', results = [];
  const opts = {
    px_image: [['', t('any_orientation')], ['horizontal', t('horizontal')], ['vertical', t('vertical')]],
    px_video: [['', t('all')], ['film', t('film')], ['animation', t('animation')]],
    wm: [['all', t('any_license')], ['no_restrictions', t('pd_only')]],
  };
  const fillOpts = () => { $('#msOpt').innerHTML = opts[src].map(([v, l]) => `<option value="${v}">${l}</option>`).join(''); };
  fillOpts();
  $$('#msSrc [data-src]').forEach(b => b.onclick = () => { src = b.dataset.src; $$('#msSrc [data-src]').forEach(x => x.classList.toggle('on', x === b)); fillOpts(); });
  const box = $('#msRes');
  $('#msForm').onsubmit = async (e) => {
    e.preventDefault();
    const q = e.target.q.value.trim(), opt = $('#msOpt').value;
    const args = src === 'wm' ? { query: q, limit: 24, license: opt } : { query: q, per_page: 24, type: src === 'px_video' ? 'video' : 'image', ...(src === 'px_video' ? { video_type: opt } : { orientation: opt }) };
    box.innerHTML = `<div class="video-grid">${'<div class="skeleton" style="aspect-ratio:4/3"></div>'.repeat(8)}</div>`;
    try {
      const r = (await api('media.search', { source: src === 'wm' ? 'wikimedia' : 'pixabay', args })).result;
      results = r.results;
      if (!results.length) { box.innerHTML = empty('search', t('no_results')); return; }
      box.innerHTML = `<div class="muted small" style="margin-bottom:10px">${r.total != null ? full(r.total) + ' · ' : ''}${t('media_source')}: <b>${r.source === 'wikimedia' ? 'Wikimedia Commons' : 'Pixabay'}</b></div>
        <div class="media-grid">${results.map((x, i) => `<div class="vcard" data-i="${i}"><div class="vthumb"><img src="${h(x.thumbnail || x.preview_url || '')}" alt="" loading="lazy" referrerpolicy="no-referrer">${x.duration ? `<span class="badge dark dur">${dur(Math.round(x.duration))}</span>` : ''}<span class="priv"><span class="badge dark">${i + 1}</span></span></div>
          <div class="vbody"><div class="vtitle" style="min-height:0;-webkit-line-clamp:1">${h(x.title || x.tags || '')}</div><div class="muted small" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(x.author || x.user || '')}${x.license && x.source === 'wikimedia' ? ' · ' + h(x.license) : ''}</div>
          ${x.width ? `<div class="vstats"><span>${x.width}×${x.height}</span></div>` : ''}</div></div>`).join('')}</div>`;
      $$('[data-i]', box).forEach(el => el.onclick = () => mediaModal(results[Number(el.dataset.i)]));
    } catch (er) { box.innerHTML = errBox(er); }
  };
}
function mediaModal(x) {
  const isVideo = !!x.videos || /video/.test(x.mime || '');
  const urls = [];
  if (x.videos) Object.entries(x.videos).forEach(([k, v]) => urls.push([`${k} · ${v.width}×${v.height}`, v.url]));
  else if (x.source === 'pixabay') [['web 640', x.web_url], ['large 1280', x.large_url], ['full HD', x.full_hd_url]].forEach(([l, u]) => u && urls.push([l, u]));
  else { urls.push([t('original') + (x.width ? ` · ${x.width}×${x.height}` : ''), x.url]); if (x.best_url && x.best_url !== x.url) urls.push(['1920px', x.best_url]); }
  modal({
    title: h(x.title || x.tags || 'Media'), icon: isVideo ? 'film' : 'image', wide: true,
    body: `<div class="grid g2" style="align-items:start">
      <div>${isVideo ? `<video src="${h(x.best_url || x.url)}" controls playsinline style="width:100%;border-radius:12px;background:#000" poster="${h(x.thumbnail || '')}"></video>` : `<img src="${h(x.large_url || x.best_url || x.thumbnail)}" alt="" style="width:100%;border-radius:12px;display:block" referrerpolicy="no-referrer">`}</div>
      <div class="stack" style="gap:12px">
        <div class="row gap-s"><span class="badge ${x.source === 'wikimedia' ? 'info' : 'ok'}">${x.source === 'wikimedia' ? 'Wikimedia Commons' : 'Pixabay'}</span>${x.license ? `<span class="badge">${h(x.license.length > 40 ? x.license.slice(0, 40) + '…' : x.license)}</span>` : ''}${x.duration ? `<span class="badge">${dur(Math.round(x.duration))}</span>` : ''}</div>
        ${x.description ? `<p class="muted small" style="margin:0">${h(x.description)}</p>` : ''}
        <div class="field"><span>${t('attribution')}</span>${copyField(x.attribution)}</div>
        ${urls.map(([l, u]) => `<div class="field"><span>${h(l)}</span>${copyField(u)}</div>`).join('')}
        ${x.page_url ? `<a class="btn sm" target="_blank" rel="noopener" href="${h(x.page_url)}">${ic('external', 'sm')}${t('open_source_page')}</a>` : ''}
      </div></div>`,
  });
}

/* ---------- Telegram bot ---------- */
async function vTelegram() {
  crumbs([t('nav_telegram')]);
  if (!S.boot) await refreshBoot();
  const st = await api('tg.settings');
  const bot = st.bot;
  const chatName = (c) => c.title || (c.username ? '@' + c.username : String(c.id));
  const typeBadge = (tp) => `<span class="badge ${tp === 'channel' ? 'brand' : tp === 'private' ? 'ok' : 'ai'}">${t('tg_' + (tp || 'private'), null, tp)}</span>`;
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('nav_telegram')}</h1><div class="sub">${t('tg_sub')}</div></div></div>
    <div class="grid g2" style="align-items:start">
      <div class="stack">
        <div class="card">
          <div class="card-head"><span class="ibox info">${ic('send')}</span><h3>${t('tg_bot')}</h3>${st.has_token ? `<span class="badge ok">${ic('check')}${t('connected')}</span>` : `<span class="badge warn">${ic('key')}${t('tg_no_token')}</span>`}</div>
          ${bot ? `<div class="act" style="padding-top:0"><div class="ibox lg info">${ic('bot')}</div><div class="a-main"><div class="a-title"><b>${h(bot.name)}</b></div><a class="muted small ltr" target="_blank" rel="noopener" href="https://t.me/${h(bot.username)}">@${h(bot.username)}</a></div><a class="btn sm" target="_blank" rel="noopener" href="https://t.me/${h(bot.username)}">${ic('external', 'sm')}${t('open')}</a></div>` : ''}
          <div class="stack" style="gap:12px">
            ${st.has_token ? `<label class="toggle-row"><span>${t('media_enable')}</span><span class="switch"><input type="checkbox" id="tgOn" ${st.enabled ? 'checked' : ''}><i></i></span></label>` : ''}
            <form class="field" id="tgTok"><span>Bot token</span>
              <div class="row nowrap"><input type="password" name="token" dir="ltr" autocomplete="off" placeholder="${st.has_token ? t('keep_current') : '123456789:AAH…'}"><button class="btn primary">${ic('save', 'sm')}${t('save')}</button></div>
              <small>${t('tg_token_hint')}</small></form>
            ${st.has_token ? `<div><button class="btn sm danger" id="tgDel">${ic('trash', 'sm')}${t('remove_key')}</button></div>` : ''}
          </div>
        </div>
        <div class="card"><div class="card-head"><h3>${ic('info')}${t('tg_howto')}</h3></div>
          <ol class="howto"><li>${t('tg_h1')}</li><li>${t('tg_h2')}</li><li>${t('tg_h3')}</li><li>${t('tg_h4')}</li></ol>
        </div>
      </div>
      <div class="stack">
        <div class="card">
          <div class="card-head"><h3>${ic('users')}${t('tg_chats')}</h3>${st.has_token ? `<button class="btn sm" id="tgRef">${ic('refresh', 'sm')}${t('tg_refresh')}</button>` : ''}</div>
          <div id="tgChats">${st.chats.length ? st.chats.map(c => `<div class="act"><div class="ibox sm ${String(c.id) === st.default_chat ? 'brand' : ''}">${ic(c.type === 'channel' ? 'zap' : c.type === 'private' ? 'user' : 'users')}</div>
              <div class="a-main"><div class="a-title"><b>${h(chatName(c))}</b>${typeBadge(c.type)}${String(c.id) === st.default_chat ? `<span class="badge brand">${t('tg_default')}</span>` : ''}</div><div class="muted small ltr">${h(c.id)}${c.username ? ' · @' + h(c.username) : ''}</div></div>
              ${String(c.id) !== st.default_chat ? `<button class="btn sm ghost" data-def="${h(c.id)}">${t('tg_make_default')}</button>` : ''}</div>`).join('') : `<p class="muted small" style="margin:0">${t('tg_no_chats')}</p>`}</div>
          <hr>
          <form class="form" id="tgCfg">
            <label class="field"><span>${t('tg_default_chat')}</span><input name="default_chat" dir="ltr" value="${h(st.default_chat)}" placeholder="123456789 / @mychannel"><small>${t('tg_default_hint')}</small></label>
            <label class="field"><span>${t('tg_allowed')}</span><textarea name="allowed" dir="ltr" rows="2" style="min-height:60px" placeholder="123456789, -1001234567890, @mychannel">${h(st.allowed)}</textarea><small>${t('tg_allowed_hint')}</small></label>
            <div><button class="btn">${ic('save', 'sm')}${t('save')}</button></div>
          </form>
        </div>
        ${st.has_token ? `<div class="card"><div class="card-head"><h3>${ic('send')}${t('tg_test')}</h3></div>
          <form class="form" id="tgTest">
            <label class="field"><span>${t('tg_chat')}</span><select name="chat">${st.chats.map(c => `<option value="${h(c.id)}" ${String(c.id) === st.default_chat ? 'selected' : ''}>${h(chatName(c))}</option>`).join('')}<option value="">${t('tg_default')}</option></select></label>
            <label class="field"><span>${t('content')}</span><textarea name="text" rows="3" dir="auto">${h(t('tg_test_text'))}</textarea></label>
            <div><button class="btn primary">${ic('send', 'sm flip')}${t('send')}</button></div>
          </form></div>` : ''}
        <div class="card"><div class="card-head"><h3>${ic('bot')}${t('ask_claude')}</h3></div>
          <div class="prompt-list">${['tgp1', 'tgp2', 'tgp3', 'tgp4'].map(k => `<button class="prompt" data-copy="${h(t(k))}">${ic('message')}<span>${t(k)}</span></button>`).join('')}</div></div>
      </div>
    </div>`;
  const save = async (data, msg = t('saved')) => { try { await api('tg.save', data); toast(msg); route(); } catch (e) { toast(e.message, 'err'); } };
  $('#tgTok').onsubmit = (e) => { e.preventDefault(); const v = e.target.token.value.trim(); if (!v) return; busy($('button', e.target), () => save({ token: v }, t('tg_token_saved'))); };
  $('#tgOn') && ($('#tgOn').onchange = (e) => api('tg.save', { enabled: e.target.checked }).then(() => toast(t('saved'))));
  $('#tgDel') && ($('#tgDel').onclick = async () => { if (await confirmDlg(t('remove_key'), t('tg_remove_q'))) save({ remove_token: 1 }); });
  $('#tgCfg').onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), () => save(Object.fromEntries(new FormData(e.target).entries()))); };
  $$('[data-def]').forEach(b => b.onclick = () => save({ default_chat: b.dataset.def }));
  $('#tgRef') && ($('#tgRef').onclick = (e) => busy(e.currentTarget, async () => { try { const r = await api('tg.refresh'); toast(t('tg_found', { n: r.chats.length })); route(); } catch (er) { toast(er.message, 'err'); } }));
  $('#tgTest') && ($('#tgTest').onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { try { await api('tg.test', Object.fromEntries(new FormData(e.target).entries())); toast(t('tg_sent')); } catch (er) { toast(er.message, 'err'); } }); });
}

/* ---------- Connector ---------- */
async function vConnector() {
  crumbs([t('nav_connector')]);
  if (!S.boot) await refreshBoot();
  const d = await api('connector');
  const b = S.boot;
  const cstep = (n, k) => `<div class="step"><div class="n">${n}</div><div><b>${t(k + '_t')}</b><span class="muted">${t(k + '_d')}</span></div></div>`;
  view.innerHTML = `
    <div class="hero">
      <div class="row between"><span class="badge ok"><span class="pulse"></span>MCP · Streamable HTTP · OAuth 2.1</span><span class="badge">${ic('wrench')}${d.tools.length} ${t('tools')}</span></div>
      <h1>${t('connector_title')}</h1>
      <p>${t('connector_sub')}</p>
      <div style="max-width:640px;margin-top:14px">${copyField(d.mcp_url)}</div>
      ${!b.https ? alertBox('warn', t('https_warning')) : ''}
      <div class="connect-flow">${cstep(1, 'cs1')}${cstep(2, 'cs2')}${cstep(3, 'cs3')}${cstep(4, 'cs4')}</div>
    </div>
    <div class="grid g2 mt" style="align-items:start">
      <div class="card"><div class="card-head"><h3>${ic('bot')}${t('connected_sessions')}</h3>${d.sessions.length ? `<button class="btn sm danger" id="revAll">${t('revoke_all')}</button>` : ''}</div>
        ${d.sessions.length ? d.sessions.map(s => `<div class="act"><div class="ibox sm ai">${ic('bot')}</div><div class="a-main"><div class="a-title"><b>${h(s.client_name)}</b></div><div class="muted small">${t('since')} ${ago(Number(s.created_at))} · ${t('last_used')} ${ago(Number(s.last_used))}</div></div><button class="btn sm" data-rev="${h(s.family)}">${t('revoke')}</button></div>`).join('') : `<p class="muted" style="margin:0">${t('no_sessions')}</p>`}
      </div>
      <div class="card"><div class="card-head"><h3>${ic('shield')}${t('security')}</h3></div>
        <div class="stack">
          <label class="toggle-row"><span><b>${t('allow_dcr')}</b><br><span class="muted small">${t('allow_dcr_d')}</span></span><span class="switch"><input type="checkbox" id="dcr" ${b.settings.allow_dcr ? 'checked' : ''}><i></i></span></label>
          <label class="field"><span>${t('token_ttl')}</span><select id="ttl">${[[3600, '1h'], [21600, '6h'], [86400, '24h'], [604800, '7d'], [2592000, '30d']].map(([v, l]) => `<option value="${v}" ${b.settings.access_token_ttl == v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
          <div><b>${t('api_key')}</b><p class="muted small" style="margin:4px 0 8px">${t('api_key_d')}</p><div class="row gap-s"><button class="btn sm" id="genKey">${ic('key', 'sm')}${b.settings.has_api_key ? t('regenerate') : t('generate')}</button>${b.settings.has_api_key ? `<button class="btn sm danger" id="revKey">${t('revoke')}</button>` : ''}</div><div id="keyOut"></div></div>
        </div>
      </div>
    </div>
    <div class="card pad0 mt"><div class="card-head" style="padding:16px 18px 0"><h3>${ic('box')}${t('oauth_clients')}</h3><button class="btn sm" id="newClient">${ic('plus', 'sm')}${t('manual_client')}</button></div>
      <p class="muted small" style="padding:0 18px">${t('oauth_clients_d')}</p>
      ${d.clients.length ? `<div class="table-wrap"><table class="list-table"><thead><tr><th>${t('name')}</th><th>Client ID</th><th>${t('type')}</th><th>${t('last_used')}</th><th></th></tr></thead><tbody>${d.clients.map(c => `<tr><td><b>${h(c.name)}</b><div class="muted small ltr">${(c.redirect_uris || []).map(h).join('<br>')}</div></td><td><code>${h(c.client_id)}</code></td><td>${c.dynamic ? '<span class="badge">DCR</span>' : '<span class="badge ai">manual</span>'} ${c.confidential ? '<span class="badge">secret</span>' : ''}</td><td class="muted small" style="white-space:nowrap">${c.last_used ? ago(c.last_used) : '—'}</td><td><button class="icon-btn sm danger" data-delc="${c.id}">${ic('trash')}</button></td></tr>`).join('')}</tbody></table></div>` : `<p class="muted" style="padding:0 18px 18px">${t('no_clients')}</p>`}
    </div>
    <div class="card mt"><div class="card-head"><h3>${ic('wrench')}${t('available_tools')}</h3></div>
      <div class="tool-grid">${d.tools.map(x => `<div class="tool"><div class="row between nowrap"><span class="tname">${h(x.name)}</span>${x.destructive ? `<span class="badge bad">${ic('alert')}</span>` : x.read_only ? `<span class="badge">${t('read')}</span>` : `<span class="badge ai">${t('write')}</span>`}</div><p title="${h(x.description)}">${h(x.description)}</p></div>`).join('')}</div>
    </div>
    <div class="card mt"><div class="card-head"><h3>${ic('message')}${t('example_prompts')}</h3><span class="muted small">${t('click_to_copy')}</span></div>
      <div class="grid g2">${['ex1', 'ex2', 'ex3', 'ex4', 'ex5', 'ex6', 'ex7'].map(k => `<button class="prompt" data-copy="${h(t(k))}">${ic('message')}<span>${t(k)}</span></button>`).join('')}</div>
    </div>`;
  $$('[data-rev]').forEach(x => x.onclick = async () => { await api('session.revoke', { family: x.dataset.rev }); toast(t('revoked')); route(); });
  $('#revAll') && ($('#revAll').onclick = async () => { if (await confirmDlg(t('revoke_all'), t('revoke_all_q'))) { await api('session.revoke', { all: 1 }); toast(t('revoked')); route(); } });
  $('#dcr').onchange = async (e) => { await api('settings.save', { allow_dcr: e.target.checked }); await refreshBoot(); toast(t('saved')); };
  $('#ttl').onchange = async (e) => { await api('settings.save', { access_token_ttl: e.target.value }); await refreshBoot(); toast(t('saved')); };
  $('#genKey').onclick = async () => {
    if (b.settings.has_api_key && !await confirmDlg(t('regenerate'), t('regen_key_q'), { danger: false })) return;
    const r = await api('apikey', {});
    $('#keyOut').innerHTML = `<div class="secret-box"><b>${ic('alert', 'sm')}${t('copy_now')}</b>${copyField(r.key)}<p class="muted small" style="margin:0">${t('api_key_usage')}<br><code>Authorization: Bearer …</code> · <code>${h(d.mcp_url)}?key=…</code></p></div>`;
    await refreshBoot();
  };
  $('#revKey') && ($('#revKey').onclick = async () => { await api('apikey', { revoke: 1 }); await refreshBoot(); toast(t('revoked')); route(); });
  $$('[data-delc]').forEach(x => x.onclick = async () => { if (await confirmDlg(t('delete'), t('delete_client_q'))) { await api('client.delete', { id: x.dataset.delc }); route(); } });
  $('#newClient').onclick = () => {
    const m = modal({
      title: t('manual_client'), icon: 'box',
      body: `<p class="muted small">${t('manual_client_d')}</p><form class="form" id="mc"><label class="field"><span>${t('name')}</span><input name="name" value="Claude"></label>
        <label class="field"><span>Redirect URIs</span><textarea name="redirect_uris" rows="3" dir="ltr">https://claude.ai/api/mcp/auth_callback\nhttps://claude.com/api/mcp/auth_callback</textarea></label>
        <label class="check-row"><input type="checkbox" name="confidential" checked>${t('with_secret')}</label></form><div id="mcOut"></div>`,
      foot: `<button class="btn ghost" data-close>${t('close')}</button><button class="btn primary" id="mcGo">${t('create')}</button>`,
    });
    m.$('#mcGo').onclick = async (e) => {
      const btn = e.currentTarget;
      const f = Object.fromEntries(new FormData(m.$('#mc')).entries());
      const r = await api('client.create', { ...f, confidential: !!m.$('[name=confidential]').checked });
      btn.remove();
      m.$('#mc').hidden = true;
      m.$('#mcOut').innerHTML = `<div class="secret-box"><b>${ic('alert', 'sm')}${t('copy_now')}</b><div class="field"><span>Client ID</span>${copyField(r.client_id)}</div>${r.client_secret ? `<div class="field"><span>Client Secret</span>${copyField(r.client_secret)}</div>` : ''}</div>`;
    };
    m.el.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) route(); });
  };
}

/* ---------- Activity ---------- */
async function vActivity() {
  crumbs([t('nav_activity')]);
  if (!S.boot) await refreshBoot();
  const f = { source: '', ok: '', q: '', offset: 0, limit: 50 };
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('nav_activity')}</h1><div class="sub">${t('activity_sub')}</div></div><div class="actions"><button class="btn danger sm" id="clr">${ic('trash', 'sm')}${t('clear_log')}</button></div></div>
    <div class="card"><div class="card-head"><h3>${ic('bars')}${t('last_14_days')}</h3></div><div id="aHist"></div></div>
    <div class="card mt">
      <div class="toolbar"><div class="seg fit">${[['', t('all')], ['mcp', 'AI'], ['web', t('you')], ['oauth', 'OAuth'], ['system', t('system')]].map(([v, l]) => `<button data-src="${v}" class="${v === '' ? 'on' : ''}">${l}</button>`).join('')}</div>
        <select id="aOk" style="flex:0 1 170px"><option value="">${t('all_results')}</option><option value="1">${t('success')}</option><option value="0">${t('failed')}</option></select>
        <div class="input-icon">${ic('search')}<input type="search" id="aQ" placeholder="${t('search')}"></div><span class="spacer"></span><span class="muted small ltr" id="aTotal"></span></div>
      <div id="aList">${loading()}</div>
      <div class="row" style="justify-content:center;margin-top:14px"><button class="btn" id="aMore" hidden>${t('load_more')}</button></div>
    </div>`;
  const load = async (reset) => {
    if (reset) f.offset = 0;
    const r = await api('activity', f);
    if (reset && r.histogram) {
      const days = Object.keys(r.histogram);
      Charts.bar($('#aHist'), { labels: days, stacked: true, height: 180, series: [{ name: 'AI (MCP)', data: days.map(k => r.histogram[k].mcp), color: '#6d5dfc' }, { name: t('you') + ' / ' + t('system'), data: days.map(k => r.histogram[k].web), color: '#f0304f' }] });
    }
    const html = r.rows.map(a => `<details class="act-d"><summary>${actRow(a)}</summary><pre>${h(prettyJson(a.detail))}</pre></details>`).join('');
    if (reset) $('#aList').innerHTML = html || `<p class="muted">${t('no_activity')}</p>`; else $('#aList').insertAdjacentHTML('beforeend', html);
    f.offset += r.rows.length;
    $('#aTotal').textContent = `${f.offset} / ${r.total}`;
    $('#aMore').hidden = f.offset >= r.total;
  };
  $$('[data-src]').forEach(b => b.onclick = () => { f.source = b.dataset.src; $$('[data-src]').forEach(x => x.classList.toggle('on', x === b)); load(true); });
  $('#aOk').onchange = (e) => { f.ok = e.target.value; load(true); };
  let qt; $('#aQ').oninput = (e) => { clearTimeout(qt); qt = setTimeout(() => { f.q = e.target.value; load(true); }, 400); };
  $('#aMore').onclick = (e) => busy(e.currentTarget, () => load(false));
  $('#clr').onclick = async () => { if (await confirmDlg(t('clear_log'), t('clear_log_q'))) { await api('activity.clear'); load(true); } };
  await load(true);
}
const prettyJson = (s) => { try { return JSON.stringify(JSON.parse(s), null, 2); } catch (e) { return s || ''; } };

/* ---------- Settings ---------- */
async function vSettings() {
  crumbs([t('nav_settings')]);
  await refreshBoot();
  const b = S.boot;
  const pref = themePref();
  view.innerHTML = `
    <div class="page-head"><div><h1>${t('nav_settings')}</h1></div></div>
    <div class="grid g2" style="align-items:start">
      <div class="card"><div class="card-head"><h3>${ic('palette')}${t('appearance')}</h3></div>
        <div class="field"><span>${t('language')}</span><div class="seg"><button data-lang="ar" class="${I18N.lang === 'ar' ? 'on' : ''}">العربية</button><button data-lang="en" class="${I18N.lang === 'en' ? 'on' : ''}">English</button></div></div>
        <div class="field" style="margin-top:16px"><span>${t('theme')}</span><div class="seg">${[['light', 'sun'], ['dark', 'moon'], ['auto', 'monitor']].map(([k, icon]) => `<button data-th="${k}" class="${pref === k ? 'on' : ''}">${ic(icon, 'sm')}${t('theme_' + k)}</button>`).join('')}</div><small>${t('theme_hint')}</small></div>
      </div>
      <div class="card"><div class="card-head"><h3>${ic('lock')}${t('change_password')}</h3></div>
        <form class="form" id="pw"><label class="field"><span>${t('current_password')}</span><input type="password" name="current" required autocomplete="current-password"></label>
        <label class="field"><span>${t('new_password')}</span><input type="password" name="new" minlength="8" required autocomplete="new-password"></label><div><button class="btn primary">${ic('save', 'sm')}${t('save')}</button></div></form></div>
      <div class="card"><div class="card-head"><h3>${ic('globe')}${t('site')}</h3></div>
        <form class="form" id="site"><label class="field"><span>${t('site_url')}</span><input name="base_url" dir="ltr" value="${h(b.base_url)}"><small>${t('site_url_d')}</small></label><div><button class="btn">${t('save')}</button></div></form>
        <hr><div class="field"><span>MCP URL</span>${copyField(b.mcp_url)}</div>
        <div class="field" style="margin-top:12px"><span>Google redirect URI</span>${copyField(b.google_redirect)}</div></div>
      <div class="card"><div class="card-head"><h3>${ic('info')}${t('system')}</h3></div>
        <div class="stack" style="gap:10px">
          <div class="row between"><span class="muted">YT Connector</span><b class="ltr">v${h(b.version)}</b></div>
          <div class="row between"><span class="muted">PHP</span><b class="ltr">${h(b.php)}</b></div>
          <div class="row between"><span class="muted">HTTPS</span>${b.https ? `<span class="badge ok">${ic('check')}OK</span>` : `<span class="badge warn">${ic('alert')}—</span>`}</div>
          <div class="row between"><span class="muted">${t('upload_chunk')}</span><b class="ltr">${(b.chunk_size / 1048576).toFixed(1)} MB</b></div>
          <a class="btn sm" target="_blank" href="https://github.com/star4droid/YT-Connector">${ic('external', 'sm')}GitHub</a>
        </div></div>
    </div>`;
  $$('[data-lang]').forEach(x => x.onclick = () => setLang(x.dataset.lang));
  $$('[data-th]').forEach(x => x.onclick = () => applyTheme(x.dataset.th));
  $('#pw').onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { try { await api('password', Object.fromEntries(new FormData(e.target).entries())); toast(t('saved')); e.target.reset(); } catch (er) { toast(er.message, 'err'); } }); };
  $('#site').onsubmit = (e) => { e.preventDefault(); busy($('button', e.target), async () => { try { await api('settings.save', Object.fromEntries(new FormData(e.target).entries())); toast(t('saved')); route(); } catch (er) { toast(er.message, 'err'); } }); };
}

/* ---------------- start ---------------- */
I18N.set(YTC.lang);
applyTheme(themePref(), false);
refreshBoot().then(route).catch(e => { view.innerHTML = errBox(e); });
})();
