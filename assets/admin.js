/* =========================================================================
   Panel de administración – expo.bezares.com
   Sin dependencias. Todo el DOM se construye con h() (sin innerHTML → sin XSS).
   ========================================================================= */
const CSRF = document.querySelector('meta[name="csrf"]').content;
const VERSION_QS = new URL(import.meta.url).search;
const app = document.getElementById('app');
const modal = document.getElementById('modal');

const state = {
  tab: (location.hash.slice(1) || 'tablets'),
  galleryKind: 'photo',
  gallerySearch: '',
  gallerySelected: new Set(),
  events: [],
  settings: {}, languages: [], tablets: [], products: [], media: [], users: [], me: 0,
  tabletDrafts: new Map(),
  productDraft: null,
  productLang: 'en',
};

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------
const PROPS = new Set(['value', 'checked', 'selected', 'disabled', 'multiple', 'hidden', 'open', 'indeterminate']);
function h(tag, props, ...kids) {
  const el = document.createElement(tag);
  if (props) {
    for (const [k, v] of Object.entries(props)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'style' && typeof v === 'object') for (const [sk, sv] of Object.entries(v)) el.style.setProperty(sk, sv);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else if (PROPS.has(k)) el[k] = v;
      else el.setAttribute(k, v === true ? '' : String(v));
    }
  }
  for (const c of kids.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const clone = (o) => JSON.parse(JSON.stringify(o));
const letter = (i) => String.fromCharCode(97 + i);

function fmtSize(b) {
  if (!b) return '0 B';
  const u = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(u.length - 1, Math.floor(Math.log(b) / Math.log(1024)));
  return (b / 1024 ** i).toFixed(i ? 1 : 0) + ' ' + u[i];
}

let toastTimer = 0;
function toast(msg, type = 'ok', ms = 3500) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast toast-' + type;
  t.hidden = false;
  clearTimeout(toastTimer);
  if (ms) toastTimer = setTimeout(() => { t.hidden = true; }, ms);
}

async function api(action, data = {}) {
  let res;
  try {
    res = await fetch('/admin/api.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ action, data }),
    });
  } catch (e) {
    throw new Error('Sin conexión con el servidor');
  }
  if (res.status === 401 || res.redirected) {
    // Sesión caducada (o cookie de dispositivo perdida): recargar lleva al login.
    location.reload();
    throw new Error('Sesión caducada');
  }
  const j = await res.json().catch(() => ({ ok: false, error: 'Respuesta no válida del servidor (' + res.status + ')' }));
  if (!j.ok) throw new Error(j.error || 'Error');
  return j;
}

/** Ejecuta una acción con botón "ocupado" y muestra errores. */
async function busy(btn, fn) {
  const label = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
  try { return await fn(); } catch (e) { toast(e.message, 'error', 6000); return undefined; } finally {
    if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); btn.textContent = label; }
  }
}

const mediaById = (id) => state.media.find((m) => m.id === id) || null;
const productById = (id) => state.products.find((p) => p.id === id) || null;
const langName = (code) => (state.languages.find((l) => l.code === code) || {}).name || code;

function langSelect(value, onChange, allowEmpty = false) {
  return h('select', { onchange: (e) => onChange(e.target.value) },
    allowEmpty ? h('option', { value: '', selected: value === '' }, '—') : null,
    state.languages.map((l) => h('option', { value: l.code, selected: l.code === value }, l.name)));
}

function thumbFor(m, cls = 'thumb') {
  if (!m) return h('span', { class: cls + ' thumb-empty' }, 'Sin imagen');
  if (m.kind === 'photo') return h('img', { class: cls, src: m.thumb || m.url, alt: '', loading: 'lazy' });
  if (m.kind === 'video') return h('video', { class: cls, src: m.url + '#t=1', muted: true, preload: 'metadata', playsinline: true });
  return h('span', { class: cls + ' thumb-model' }, iconCube(), h('b', null, (m.ext || '').toUpperCase()));
}

function iconCube() {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  const p = document.createElementNS(ns, 'path');
  p.setAttribute('d', 'M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 2.2L18.8 8 12 11.8 5.2 8 12 4.2ZM5 9.7l6 3.4v6.6l-6-3.3V9.7Zm8 10v-6.6l6-3.4v6.7l-6 3.3Z');
  p.setAttribute('fill', 'currentColor');
  svg.append(p);
  return svg;
}

// ---------------------------------------------------------------------------
// Modal genérico
// ---------------------------------------------------------------------------
function openModal(content, { wide = false, onClose } = {}) {
  const body = modal.querySelector('.modal-body');
  body.replaceChildren(content);
  modal.classList.toggle('modal-wide', wide);
  modal._onClose = onClose;
  if (!modal.open) modal.showModal();
}
function closeModal() {
  if (modal.open) modal.close();
}
modal.addEventListener('close', () => {
  const fn = modal._onClose;
  modal._onClose = null;
  modal.querySelector('.modal-body').replaceChildren();
  if (fn) fn();
});
modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

function confirmBox(message, okLabel = 'Aceptar', danger = false) {
  return new Promise((resolve) => {
    let result = false;
    openModal(h('div', { class: 'confirm' },
      h('p', null, message),
      h('div', { class: 'row-end' },
        h('button', { class: 'btn', onclick: () => closeModal() }, 'Cancelar'),
        h('button', { class: 'btn ' + (danger ? 'btn-danger' : 'btn-primary'), onclick: () => { result = true; closeModal(); } }, okLabel))),
    { onClose: () => resolve(result) });
  });
}

// ---------------------------------------------------------------------------
// Selector de medios
// ---------------------------------------------------------------------------
function pickMedia(kind, { multiple = false, title } = {}) {
  return new Promise((resolve) => {
    const chosen = new Set();
    let search = '';
    let result = null;
    const grid = h('div', { class: 'media-grid picker-grid' });
    const renderGrid = () => {
      const list = state.media.filter((m) => m.kind === kind && m.status !== 'missing'
        && (!search || m.name.toLowerCase().includes(search)));
      grid.replaceChildren(...(list.length ? list.map((m) => {
        const card = h('button', {
          type: 'button', class: 'media-card pick' + (chosen.has(m.id) ? ' is-chosen' : ''),
          onclick: () => {
            if (!multiple) { result = m.id; closeModal(); return; }
            chosen.has(m.id) ? chosen.delete(m.id) : chosen.add(m.id);
            card.classList.toggle('is-chosen');
          },
        }, h('span', { class: 'media-thumb' }, thumbFor(m)), h('span', { class: 'media-name' }, m.name),
        m.status === 'needs_conversion' ? h('span', { class: 'badge badge-warn' }, 'Sin convertir') : null);
        return card;
      }) : [h('p', { class: 'hint' }, 'No hay elementos. Súbelos desde aquí o desde la pestaña Galería.')]));
    };
    const uploadBtn = uploadButton(kind, async () => renderGrid(), true);
    const content = h('div', { class: 'picker' },
      h('div', { class: 'panel-head' },
        h('h3', null, title || 'Seleccionar ' + ({ photo: 'foto', video: 'vídeo', model: 'modelo 3D' })[kind]),
        h('input', { type: 'search', placeholder: 'Buscar…', oninput: (e) => { search = e.target.value.toLowerCase(); renderGrid(); } }),
        uploadBtn),
      grid,
      h('div', { class: 'row-end' },
        h('button', { class: 'btn', onclick: () => closeModal() }, 'Cancelar'),
        multiple ? h('button', { class: 'btn btn-primary', onclick: () => { result = [...chosen]; closeModal(); } }, 'Añadir seleccionados') : null));
    renderGrid();
    openModal(content, { wide: true, onClose: () => resolve(result) });
  });
}

/** Campo de selección de medio con vista previa. */
function mediaField(kind, value, onChange, { label, allowClear = true } = {}) {
  const wrap = h('div', { class: 'media-field' });
  const render = () => {
    const m = mediaById(value);
    wrap.replaceChildren(
      h('button', { type: 'button', class: 'media-field-btn', title: 'Cambiar', onclick: async () => {
        const id = await pickMedia(kind);
        if (id) { value = id; onChange(id); render(); }
      } }, thumbFor(m), h('span', null, m ? m.name : (label || 'Seleccionar…'))),
      allowClear && m ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Quitar', onclick: () => { value = null; onChange(null); render(); } }, '✕') : null,
      m && m.status === 'needs_conversion' ? h('span', { class: 'badge badge-warn' }, 'Pendiente de convertir a GLB') : null);
  };
  render();
  return wrap;
}

// ---------------------------------------------------------------------------
// Subidas por trozos
// ---------------------------------------------------------------------------
const ACCEPT = {
  video: '.mp4,.m4v,.webm',
  // Incluye los ficheros asociados de glTF/OBJ, que se seleccionan a la vez que el modelo.
  model: '.glb,.gltf,.obj,.stp,.step,.bin,.mtl,.png,.jpg,.jpeg,.webp,.gif,.bmp',
  photo: '.jpg,.jpeg,.png,.webp,.gif,.avif,.svg',
};
const CHUNK = 4 * 1024 * 1024;

async function uploadFile(file, kind, { convertOf = 0, onProgress } = {}) {
  const n = Math.max(1, Math.ceil(file.size / CHUNK));
  const id = crypto.getRandomValues(new Uint8Array(16)).reduce((s, b) => s + b.toString(16).padStart(2, '0'), '');
  for (let i = 0; i < n; i++) {
    const qs = new URLSearchParams({ id, i: String(i), n: String(n), size: String(file.size), name: file.name, kind });
    if (convertOf) qs.set('convert_of', String(convertOf));
    let res = null;
    for (let attempt = 0; attempt < 3; attempt++) {
      try {
        res = await fetch('/admin/upload.php?' + qs, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/octet-stream' },
          body: file.slice(i * CHUNK, (i + 1) * CHUNK),
        });
        if (res.status < 500) break;
      } catch (e) {
        if (attempt === 2) throw new Error('Error de red subiendo ' + file.name);
      }
      await sleep(1500 * (attempt + 1));
    }
    const j = await res.json().catch(() => ({ ok: false, error: 'Respuesta no válida (' + res.status + ')' }));
    if (!j.ok) throw new Error(file.name + ': ' + (j.error || 'error'));
    if (onProgress) onProgress((i + 1) / n);
    if (j.done) return j.media;
  }
  throw new Error('Subida incompleta');
}

/**
 * Modelos glTF/OBJ: se convierten a GLB aquí mismo con sus ficheros asociados (.bin, .mtl,
 * texturas) seleccionados a la vez, y se sube sólo el GLB. STEP y GLB se suben tal cual.
 */
async function prepareModelFiles(files) {
  const isMain = (f) => /\.(gltf|obj)$/i.test(f.name);
  const isDirect = (f) => /\.(glb|stp|step)$/i.test(f.name);
  const mains = files.filter(isMain);
  const companions = files.filter((f) => !isMain(f) && !isDirect(f));
  const out = files.filter(isDirect);
  if (!mains.length && companions.length) {
    toast('Los ficheros .bin, .mtl y las texturas se seleccionan junto con su .gltf u .obj.', 'error', 8000);
  }
  if (!mains.length) return out;
  if (!converterModule) converterModule = await import('./admin-convert.js' + VERSION_QS);
  for (const main of mains) {
    try {
      toast(`Convirtiendo ${main.name} a GLB…`, 'info', 0);
      const blob = await converterModule.convertFilesToGlb(main, companions, (msg) => toast(`${main.name}: ${msg}`, 'info', 0));
      out.push(new File([blob], main.name.replace(/\.[^.]+$/, '') + '.glb', { type: 'model/gltf-binary' }));
    } catch (e) {
      toast(`${main.name}: ${e.message}`, 'error', 12000);
      await sleep(3000);
    }
  }
  return out;
}

async function uploadFiles(files, kind) {
  if (kind === 'model') files = await prepareModelFiles(files);
  const added = [];
  let k = 0;
  for (const f of files) {
    k++;
    try {
      const m = await uploadFile(f, kind, {
        onProgress: (p) => toast(`Subiendo ${k}/${files.length}: ${f.name} – ${Math.round(p * 100)}%`, 'info', 0),
      });
      const i = state.media.findIndex((x) => x.id === m.id);
      if (i >= 0) state.media[i] = m; else state.media.push(m);
      added.push(m);
    } catch (e) {
      toast(e.message, 'error', 8000);
      await sleep(1500);
    }
  }
  if (added.length) toast(`${added.length} fichero(s) subido(s)`);
  const pending = added.filter((m) => m.status === 'needs_conversion');
  if (pending.length) await convertMany(pending);
  return added;
}

function uploadButton(kind, after, small = false) {
  const input = h('input', { type: 'file', accept: ACCEPT[kind], multiple: true, hidden: true });
  const btn = h('button', { type: 'button', class: 'btn' + (small ? ' btn-sm' : ' btn-primary'), onclick: () => input.click() }, '⬆ Subir');
  input.addEventListener('change', async () => {
    const files = [...input.files];
    input.value = '';
    if (!files.length) return;
    btn.disabled = true;
    try { await uploadFiles(files, kind); } finally { btn.disabled = false; }
    if (after) after();
  });
  return h('span', null, btn, input);
}

// ---------------------------------------------------------------------------
// Conversión STEP / OBJ / glTF -> GLB (en el navegador del administrador)
// ---------------------------------------------------------------------------
let converterModule = null;
async function convertOne(m) {
  if (!converterModule) converterModule = await import('./admin-convert.js' + VERSION_QS);
  const srcUrl = m.sourceUrl || m.url;
  toast(`Convirtiendo ${m.name} a GLB…`, 'info', 0);
  const blob = await converterModule.convertToGlb(srcUrl, m.ext, (msg) => toast(`${m.name}: ${msg}`, 'info', 0));
  const file = new File([blob], m.name.replace(/\.[^.]+$/, '') + '.glb', { type: 'model/gltf-binary' });
  const updated = await uploadFile(file, 'model', {
    convertOf: m.id,
    onProgress: (p) => toast(`Guardando ${m.name}.glb – ${Math.round(p * 100)}%`, 'info', 0),
  });
  const i = state.media.findIndex((x) => x.id === m.id);
  if (i >= 0) state.media[i] = updated;
  return updated;
}

async function convertMany(list) {
  let ok = 0;
  for (const m of list) {
    try { await convertOne(m); ok++; } catch (e) { toast(`Error convirtiendo ${m.name}: ${e.message}`, 'error', 8000); await sleep(2000); }
  }
  if (ok) toast(`${ok} modelo(s) convertido(s) a GLB`);
  render();
}

// ---------------------------------------------------------------------------
// Pestaña TABLETS
// ---------------------------------------------------------------------------
function tabletDraft(t) {
  const key = t ? t.id : 'new';
  if (!state.tabletDrafts.has(key)) {
    const next = Math.max(0, ...state.tablets.map((x) => x.number)) + 1;
    state.tabletDrafts.set(key, t ? clone(t) : { id: null, number: next, label: '', active: true, items: [] });
  }
  return state.tabletDrafts.get(key);
}

function renderTablets() {
  const list = h('div', { class: 'tablet-list' });
  const drafts = state.tablets.map((t) => tabletDraft(t));
  if (state.tabletDrafts.has('new')) drafts.unshift(state.tabletDrafts.get('new'));
  for (const d of drafts) list.append(tabletCard(d));
  return h('section', { class: 'panel' },
    h('div', { class: 'panel-head' },
      h('h2', null, 'Tablets'),
      h('span', { class: 'hint' }, 'Cada tablet activo muestra de 2 a 5 productos. La flecha indica dónde está el producto físico respecto al tablet.'),
      h('button', { class: 'btn btn-primary', disabled: state.tabletDrafts.has('new'), onclick: () => { tabletDraft(null); render(); } }, '+ Nuevo tablet')),
    eventSettingsCard(),
    !state.products.length ? h('p', { class: 'flash flash-info' }, 'Primero crea los productos en la pestaña Productos.') : null,
    list);
}

/** Títulos y prefijos de URL del evento (se guardan y restauran con cada evento). */
function eventSettingsCard() {
  const s = state.settings;
  const v = { event_title: s.event_title || '', site_title: s.site_title || '', url_prefix: s.url_prefix || '', display_prefix: s.display_prefix || '' };
  const exUrl = h('small', null);
  const exShow = h('small', null);
  const examples = () => {
    exUrl.textContent = ` (ej. ${v.url_prefix}1a)`;
    exShow.textContent = ` (ej. ${v.display_prefix}1a)`;
  };
  examples();
  const field = (key, label, hint, attrs = {}) => h('label', { class: 'field' },
    h('span', null, label, hint),
    h('input', { value: v[key], ...attrs, oninput: (e) => { v[key] = e.target.value; examples(); } }));
  return h('div', { class: 'card' },
    h('div', { class: 'panel-head' },
      h('h3', null, 'Evento'),
      h('span', { class: 'hint grow' }, 'Se guardan junto con los tablets en la pestaña Eventos.'),
      h('button', { class: 'btn btn-primary btn-sm', onclick: (e) => busy(e.currentTarget, async () => {
        const changedPrefix = v.url_prefix.trim().toLowerCase() !== (s.url_prefix || '');
        if (changedPrefix && !(await confirmBox(`Al cambiar el prefijo de URL cambian las direcciones de todos los tablets (p. ej. /${v.url_prefix.trim().toLowerCase()}1a/). Los tablets que tengan abierta la dirección antigua dejarán de funcionar hasta que se abra la nueva. ¿Continuar?`, 'Cambiar'))) return;
        const r = await api('tablets.settings', v);
        Object.assign(state.settings, r.settings);
        state.tablets = r.tablets;
        state.tabletDrafts.clear();
        toast('Ajustes del evento guardados');
        render();
      }) }, 'Guardar')),
    h('div', { class: 'row2' },
      field('event_title', 'Título del evento', h('small', null, ' (página principal)'), { maxlength: 120 }),
      field('site_title', 'Título de la web', h('small', null, ' (pestaña del navegador)'), { maxlength: 150 })),
    h('div', { class: 'row2' },
      field('url_prefix', 'Prefijo de URL', exUrl, { maxlength: 30, pattern: '[a-z][a-z0-9-]*[a-z-]', autocapitalize: 'off', spellcheck: 'false' }),
      field('display_prefix', 'Prefijo visible', exShow, { maxlength: 30 })));
}

function tabletCard(d) {
  const key = d.id || 'new';
  const card = h('article', { class: 'card tablet-card' + (d.active ? '' : ' is-off') });
  const refresh = () => card.replaceWith(tabletCard(d));
  const saved = d.id ? state.tablets.find((t) => t.id === d.id) : null;

  const items = h('div', { class: 'items' });
  d.items.forEach((it, i) => items.append(itemRow(d, it, i, refresh)));
  if (!d.items.length) items.append(h('p', { class: 'hint' }, 'Sin productos.'));

  const warn = d.active && d.items.length < 2 ? h('span', { class: 'badge badge-warn' }, 'Necesita al menos 2 productos') : null;

  card.append(
    h('header', { class: 'card-head' },
      h('label', { class: 'tablet-no' }, h('span', null, 'Tablet'),
        h('input', { type: 'number', min: 1, max: 999, value: d.number, oninput: (e) => { d.number = parseInt(e.target.value, 10) || 0; } })),
      h('input', { class: 'grow', placeholder: 'Etiqueta interna (opcional)', value: d.label, maxlength: 80, oninput: (e) => { d.label = e.target.value; } }),
      h('label', { class: 'switch' }, h('input', { type: 'checkbox', checked: d.active, onchange: (e) => { d.active = e.target.checked; card.classList.toggle('is-off', !d.active); refresh(); } }), h('span', null, 'Activo')),
      saved ? h('a', { class: 'btn btn-ghost btn-sm', href: saved.url, target: '_blank', rel: 'noopener' }, 'Ver ↗') : null),
    items,
    h('footer', { class: 'card-foot' },
      h('button', { class: 'btn btn-sm', disabled: d.items.length >= 5 || !state.products.length, onclick: () => {
        const last = d.items[d.items.length - 1];
        d.items.push({ product_id: state.products[0].id, arrow: 'none', photo_media_id: null, lang1: last ? last.lang1 : 'en', lang2: last ? last.lang2 : 'es', is_default: d.items.length === 0 });
        refresh();
      } }, '+ Añadir producto'),
      warn,
      h('span', { class: 'grow' }),
      h('button', { class: 'btn btn-danger btn-ghost btn-sm', onclick: async () => {
        if (!d.id) { state.tabletDrafts.delete('new'); render(); return; }
        if (!(await confirmBox(`¿Eliminar el tablet ${d.number}?`, 'Eliminar', true))) return;
        const r = await busy(null, () => api('tablet.delete', { id: d.id }));
        if (r) { state.tablets = r.tablets; state.tabletDrafts.delete(d.id); toast('Tablet eliminado'); render(); }
      } }, d.id ? 'Eliminar' : 'Descartar'),
      d.id ? h('button', { class: 'btn btn-sm', onclick: () => { state.tabletDrafts.delete(d.id); render(); } }, 'Deshacer cambios') : null,
      h('button', { class: 'btn btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
        const r = await api('tablet.save', d);
        state.tablets = r.tablets;
        state.tabletDrafts.delete(key);
        toast(`Tablet ${d.number} guardado`);
        render();
      }) }, 'Guardar')));
  return card;
}

function itemRow(d, it, i, refresh) {
  const p = productById(it.product_id);
  const photo = mediaById(it.photo_media_id) || (p ? mediaById(p.photo_media_id) : null);
  const arrowBtn = (dir, label, sym) => h('button', {
    type: 'button', class: 'seg' + (it.arrow === dir ? ' is-on' : ''), title: label, 'aria-pressed': it.arrow === dir ? 'true' : 'false',
    onclick: () => { it.arrow = dir; refresh(); },
  }, sym);
  const move = (delta) => {
    const j = i + delta;
    if (j < 0 || j >= d.items.length) return;
    [d.items[i], d.items[j]] = [d.items[j], d.items[i]];
    refresh();
  };
  return h('div', { class: 'item-row' },
    h('span', { class: 'item-pos', title: 'Posición / letra de la URL' }, letter(i)),
    h('button', { type: 'button', class: 'item-photo', title: 'Foto del índice (por defecto la del producto)', onclick: async () => {
      const id = await pickMedia('photo', { title: 'Foto para el índice del tablet' });
      if (id) { it.photo_media_id = id; refresh(); }
    } }, thumbFor(photo)),
    h('div', { class: 'item-main' },
      h('select', { class: 'item-product', onchange: (e) => { it.product_id = parseInt(e.target.value, 10); refresh(); } },
        state.products.map((pp) => h('option', { value: pp.id, selected: pp.id === it.product_id }, pp.short_name))),
      it.photo_media_id ? h('button', { type: 'button', class: 'link-btn', onclick: () => { it.photo_media_id = null; refresh(); } }, 'Usar foto del producto') : null),
    h('div', { class: 'seg-group', role: 'group', 'aria-label': 'Posición del producto' },
      arrowBtn('none', 'Sin flecha', '–'), arrowBtn('left', 'Izquierda', '←'), arrowBtn('up', 'Arriba', '↑'), arrowBtn('right', 'Derecha', '→')),
    h('label', { class: 'mini' }, 'Idioma 1', langSelect(it.lang1, (v) => { it.lang1 = v; })),
    h('label', { class: 'mini' }, 'Idioma 2', langSelect(it.lang2, (v) => { it.lang2 = v; }, true)),
    h('label', { class: 'mini radio', title: 'Producto que se abre por defecto' },
      h('input', { type: 'radio', name: 'def-' + (d.id || 'new'), checked: !!it.is_default, onchange: () => { d.items.forEach((x, k) => { x.is_default = k === i; }); } }), 'Por defecto'),
    h('div', { class: 'item-actions' },
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Subir', disabled: i === 0, onclick: () => move(-1) }, '▲'),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm', title: 'Bajar', disabled: i === d.items.length - 1, onclick: () => move(1) }, '▼'),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-danger', title: 'Quitar', onclick: () => {
        const wasDefault = it.is_default;
        d.items.splice(i, 1);
        if (wasDefault && d.items[0]) d.items[0].is_default = true;
        refresh();
      } }, '✕')));
}

// ---------------------------------------------------------------------------
// Pestaña PRODUCTOS
// ---------------------------------------------------------------------------
function renderProducts() {
  if (state.productDraft) return productEditor();
  const rows = state.products.map((p) => h('tr', null,
    h('td', { class: 'td-thumb' }, thumbFor(mediaById(p.photo_media_id))),
    h('td', null, h('strong', null, p.short_name)),
    h('td', null, p.langs.map((l) => h('span', { class: 'badge' }, l.toUpperCase()))),
    h('td', null, p.model_media_id ? (mediaById(p.model_media_id)?.status === 'ready' ? '✔ 3D' : '⚠ 3D sin convertir') : 'Foto'),
    h('td', { class: 'td-actions' },
      h('button', { class: 'btn btn-sm', onclick: (e) => openProduct(p.id, e.currentTarget) }, 'Editar'),
      h('button', { class: 'btn btn-sm btn-ghost btn-danger', onclick: async () => {
        const used = state.tablets.filter((t) => t.items.some((it) => it.product_id === p.id)).map((t) => t.number);
        const msg = `¿Eliminar el producto "${p.short_name}"?` + (used.length ? ` Se quitará de los tablets: ${used.join(', ')}.` : '');
        if (!(await confirmBox(msg, 'Eliminar', true))) return;
        const r = await busy(null, () => api('product.delete', { id: p.id }));
        if (r) { state.products = r.products; state.tablets = r.tablets; state.tabletDrafts.clear(); toast('Producto eliminado'); render(); }
      } }, 'Eliminar'))));
  return h('section', { class: 'panel' },
    h('div', { class: 'panel-head' },
      h('h2', null, 'Productos'),
      h('button', { class: 'btn btn-primary', onclick: () => {
        state.productDraft = { id: null, short_name: '', video_media_id: null, video_url: '', model_media_id: null, photo_media_id: null, i18n: { en: { title: '', description: '', features: '' } }, photos: [] };
        state.productLang = 'en';
        render();
      } }, '+ Nuevo producto')),
    state.products.length
      ? h('table', { class: 'table' }, h('thead', null, h('tr', null, h('th', null, ''), h('th', null, 'Nombre reducido'), h('th', null, 'Idiomas'), h('th', null, 'Visual'), h('th', null, ''))), h('tbody', null, rows))
      : h('p', { class: 'hint' }, 'Aún no hay productos.'));
}

async function openProduct(id, btn) {
  const r = await busy(btn, () => api('product.get', { id }));
  if (!r) return;
  const p = r.product;
  if (!p.i18n.en) p.i18n.en = { title: '', description: '', features: '' };
  state.productDraft = p;
  state.productLang = 'en';
  render();
}

function productEditor() {
  const d = state.productDraft;
  const langs = Object.keys(d.i18n).sort((a, b) => (a === 'en' ? -1 : b === 'en' ? 1 : langName(a).localeCompare(langName(b))));
  if (!d.i18n[state.productLang]) state.productLang = 'en';
  const cur = state.productLang;
  const t = d.i18n[cur];

  const missing = state.languages.filter((l) => !d.i18n[l.code]);
  const addLang = h('select', { class: 'add-lang', onchange: async (e) => {
    const code = e.target.value;
    e.target.value = '';
    if (!code) return;
    await translateInto(code, e.target);
  } }, h('option', { value: '' }, '+ Añadir idioma (traducción automática)…'), missing.map((l) => h('option', { value: l.code }, l.name)));

  const langTabs = h('div', { class: 'lang-tabs', role: 'tablist' },
    langs.map((code) => h('button', {
      type: 'button', role: 'tab', class: 'lang-tab' + (code === cur ? ' is-on' : ''), 'aria-selected': code === cur ? 'true' : 'false',
      onclick: () => { state.productLang = code; render(); },
    }, langName(code), code === 'en' ? ' (base)' : '')),
    missing.length ? addLang : null);

  const field = (label, key, multiline, hint, rows = 6) => h('label', { class: 'field' },
    h('span', null, label, hint ? h('small', null, ' ' + hint) : null),
    multiline
      ? h('textarea', { rows, lang: cur, value: t[key] || '', oninput: (e) => { t[key] = e.target.value; } })
      : h('input', { lang: cur, value: t[key] || '', maxlength: 300, oninput: (e) => { t[key] = e.target.value; } }));

  const langActions = cur === 'en' ? null : h('div', { class: 'row-end' },
    h('button', { type: 'button', class: 'btn btn-sm', onclick: (e) => translateInto(cur, e.currentTarget, true) }, '↻ Volver a traducir desde inglés'),
    h('button', { type: 'button', class: 'btn btn-sm btn-ghost btn-danger', onclick: () => { delete d.i18n[cur]; state.productLang = 'en'; render(); } }, 'Quitar idioma'));

  const photosList = h('div', { class: 'photo-list' },
    d.photos.length ? d.photos.map((ph, i) => h('div', { class: 'photo-row' },
      thumbFor(mediaById(ph.media_id)),
      h('span', { class: 'grow' }, mediaById(ph.media_id)?.name || '¿?'),
      h('select', { onchange: (e) => { ph.size = e.target.value; } },
        [['small', 'Pequeña (1/3)'], ['medium', 'Mediana (1/2)'], ['large', 'Grande (ancho completo)']].map(([v, l]) => h('option', { value: v, selected: ph.size === v }, l))),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm', disabled: i === 0, onclick: () => { [d.photos[i - 1], d.photos[i]] = [d.photos[i], d.photos[i - 1]]; render(); } }, '▲'),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm', disabled: i === d.photos.length - 1, onclick: () => { [d.photos[i + 1], d.photos[i]] = [d.photos[i], d.photos[i + 1]]; render(); } }, '▼'),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm btn-danger', onclick: () => { d.photos.splice(i, 1); render(); } }, '✕')))
      : h('p', { class: 'hint' }, 'Sin fotos adicionales.'));

  return h('section', { class: 'panel editor' },
    h('div', { class: 'panel-head' },
      h('button', { class: 'btn btn-ghost', onclick: () => { state.productDraft = null; render(); } }, '← Volver'),
      h('h2', null, d.id ? 'Editar producto' : 'Nuevo producto'),
      h('span', { class: 'grow' }),
      h('button', { class: 'btn btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
        const r = await api('product.save', d);
        state.products = r.products;
        d.id = r.id;
        toast('Producto guardado');
        render();
      }) }, 'Guardar')),
    h('div', { class: 'editor-grid' },
      h('div', { class: 'col' },
        h('label', { class: 'field' }, h('span', null, 'Nombre reducido', h('small', null, ' (aparece en los índices)')),
          h('input', { value: d.short_name, maxlength: 60, required: true, oninput: (e) => { d.short_name = e.target.value; } })),
        h('div', { class: 'card' },
          langTabs,
          field('Título', 'title', false),
          field('Descripción', 'description', true, '(línea en blanco = nuevo párrafo)', 8),
          field('Características principales', 'features', true, '(opcional; una por línea)', 6),
          langActions)),
      h('div', { class: 'col' },
        h('div', { class: 'card' },
          h('h3', null, 'Modelo 3D'),
          mediaField('model', d.model_media_id, (v) => { d.model_media_id = v; }, { label: 'Seleccionar modelo 3D…' }),
          h('h3', null, 'Foto principal', h('small', null, ' (se muestra si no hay modelo 3D y en los índices)')),
          mediaField('photo', d.photo_media_id, (v) => { d.photo_media_id = v; }, { label: 'Seleccionar foto…' }),
          h('h3', null, 'Vídeo', h('small', null, ' (opcional)')),
          mediaField('video', d.video_media_id, (v) => { d.video_media_id = v; }, { label: 'Seleccionar vídeo de la galería…' }),
          h('label', { class: 'field' }, h('span', null, 'o URL de YouTube', h('small', null, ' (consume más datos; mejor subir el vídeo)')),
            h('input', { type: 'url', value: d.video_url || '', placeholder: 'https://www.youtube.com/watch?v=…', oninput: (e) => { d.video_url = e.target.value; } }))),
        h('div', { class: 'card' },
          h('div', { class: 'panel-head' }, h('h3', null, 'Fotos adicionales'),
            h('button', { type: 'button', class: 'btn btn-sm', onclick: async () => {
              const ids = await pickMedia('photo', { multiple: true, title: 'Añadir fotos adicionales' });
              if (ids && ids.length) { ids.forEach((id) => d.photos.push({ media_id: id, size: 'medium' })); render(); }
            } }, '+ Añadir fotos')),
          photosList))));
}

async function translateInto(code, btn, overwrite = false) {
  const d = state.productDraft;
  const en = d.i18n.en;
  if (!en.title.trim()) { toast('Escribe primero el título en inglés', 'error'); return; }
  if (overwrite && !(await confirmBox(`¿Sustituir el texto en ${langName(code)} por una nueva traducción?`, 'Traducir'))) return;
  toast(`Traduciendo a ${langName(code)} con ChatGPT…`, 'info', 0);
  const r = await busy(btn, () => api('product.translate', { lang: code, fields: en }));
  if (!r) return;
  d.i18n[code] = r.translation;
  state.productLang = code;
  toast(`Traducción a ${langName(code)} lista. Revísala y pulsa Guardar.`);
  render();
}

// ---------------------------------------------------------------------------
// Pestaña GALERÍA
// ---------------------------------------------------------------------------
function renderGallery() {
  const kinds = [['video', 'Vídeos'], ['model', 'Modelos 3D'], ['photo', 'Fotos']];
  const k = state.galleryKind;
  const list = state.media.filter((m) => m.kind === k && (!state.gallerySearch || m.name.toLowerCase().includes(state.gallerySearch)));
  const pending = state.media.filter((m) => m.kind === 'model' && m.status === 'needs_conversion');

  const visible = () => state.media.filter((m) => m.kind === k && (!state.gallerySearch || m.name.toLowerCase().includes(state.gallerySearch)));
  // Selección múltiple: sólo cuenta lo que existe en la pestaña actual.
  const sel = state.gallerySelected;
  for (const id of [...sel]) if (!state.media.some((m) => m.id === id && m.kind === k)) sel.delete(id);

  const grid = h('div', { class: 'media-grid' }, list.length ? list.map(mediaCard) : h('p', { class: 'hint' }, 'No hay elementos.'));
  const selAll = h('input', { type: 'checkbox', 'aria-label': 'Seleccionar todo' });
  const selCount = h('span', { class: 'hint' });
  const delSel = h('button', { class: 'btn btn-sm btn-danger', onclick: () => deleteMany([...sel]) }, 'Eliminar seleccionados');
  galleryRefreshBar = () => {
    const vis = visible();
    const n = vis.filter((m) => sel.has(m.id)).length;
    selAll.checked = vis.length > 0 && n === vis.length;
    selAll.indeterminate = n > 0 && n < vis.length;
    selCount.textContent = sel.size ? `${sel.size} seleccionado(s)` : '';
    delSel.disabled = sel.size === 0;
    grid.querySelectorAll('.media-card').forEach((c) => {
      const on = sel.has(Number(c.dataset.id));
      c.classList.toggle('is-selected', on);
      const cb = c.querySelector('.media-select input');
      if (cb) cb.checked = on;
    });
  };
  selAll.addEventListener('change', () => {
    const vis = visible();
    if (selAll.checked) vis.forEach((m) => sel.add(m.id)); else vis.forEach((m) => sel.delete(m.id));
    galleryRefreshBar();
  });
  const bulkBar = h('div', { class: 'bulk-bar' },
    h('label', { class: 'check' }, selAll, 'Seleccionar todo'), selCount, h('span', { class: 'grow' }), delSel);

  const search = h('input', { type: 'search', placeholder: 'Buscar…', value: state.gallerySearch, oninput: (e) => {
    state.gallerySearch = e.target.value.toLowerCase();
    const items = visible();
    grid.replaceChildren(...(items.length ? items.map(mediaCard) : [h('p', { class: 'hint' }, 'No hay elementos.')]));
    galleryRefreshBar();
  } });
  queueMicrotask(() => galleryRefreshBar());

  return h('section', { class: 'panel' },
    h('div', { class: 'panel-head' },
      h('h2', null, 'Galería'),
      h('div', { class: 'subtabs', role: 'tablist' }, kinds.map(([v, l]) => h('button', {
        role: 'tab', class: 'subtab' + (k === v ? ' is-on' : ''), 'aria-selected': k === v ? 'true' : 'false',
        onclick: () => { state.galleryKind = v; state.gallerySelected.clear(); render(); },
      }, l, ' ', h('span', { class: 'count' }, state.media.filter((m) => m.kind === v).length)))),
      h('span', { class: 'grow' }),
      search,
      uploadButton(k, () => render()),
      h('button', { class: 'btn', title: 'Busca ficheros nuevos subidos por FTP a /media y sus subcarpetas, y mueve todos los medios a su carpeta (videos, photos, models). Las carpetas de importación vacías se eliminan.', onclick: (e) => busy(e.currentTarget, async () => {
        toast('Escaneando carpeta /media…', 'info', 0);
        const r = await api('media.scan');
        state.media = r.media;
        const x = r.result;
        const summary = `${x.added} nuevos, ${x.updated} actualizados, ${x.moved + x.bundled} movidos a su carpeta`
          + (x.removed_dirs ? `, ${x.removed_dirs} carpetas vacías eliminadas` : '') + (x.missing ? `, ${x.missing} sin fichero` : '');
        toast('Escaneo terminado: ' + summary, 'ok', 8000);
        render();
        // Informe detallado si quedan cosas por revisar en las carpetas de importación.
        if (x.leftover.length || x.organize_errors.length || x.skipped.length) {
          await new Promise((resolve) => openModal(h('div', { class: 'confirm scan-report' },
            h('h3', null, 'Escaneo y organización de carpetas'),
            h('p', null, summary + '.'),
            x.organize_errors.length ? [h('h4', null, 'Avisos'), h('ul', null, x.organize_errors.map((t) => h('li', null, t)))] : null,
            x.skipped.length ? [h('h4', null, 'Ficheros no válidos (no importados)'), h('ul', null, x.skipped.map((t) => h('li', null, h('code', null, t))))] : null,
            x.leftover.length
              ? [h('h4', null, 'Quedan en carpetas de importación'),
                h('p', { class: 'hint' }, 'No son medios de la galería (formato no admitido o no referenciados por ningún modelo). Revísalos y bórralos por FTP; después podrás eliminar esas carpetas.'),
                h('ul', null, x.leftover.map((t) => h('li', null, h('code', null, t))))]
              : h('p', { class: 'flash flash-ok' }, 'Las carpetas de importación han quedado vacías y se han eliminado.'),
            h('div', { class: 'row-end' }, h('button', { class: 'btn btn-primary', onclick: () => closeModal() }, 'Entendido'))),
          { wide: true, onClose: resolve }));
        }
        const toConvert = state.media.filter((m) => m.kind === 'model' && m.status === 'needs_conversion');
        if (toConvert.length && await confirmBox(`Hay ${toConvert.length} modelo(s) STEP/OBJ/glTF pendientes de convertir a GLB (formato ligero para los tablets). ¿Convertir ahora en este navegador?`, 'Convertir')) {
          await convertMany(toConvert);
        }
      }) }, '⟳ Escanear carpeta FTP')),
    k === 'model' && pending.length ? h('div', { class: 'flash flash-info row' },
      h('span', { class: 'grow' }, `${pending.length} modelo(s) pendientes de convertir a GLB. Los tablets sólo descargan GLB (más ligero).`),
      h('button', { class: 'btn btn-sm btn-primary', onclick: () => convertMany(pending) }, 'Convertir todos')) : null,
    k === 'model' ? h('p', { class: 'hint' }, 'Formatos: GLB (recomendado), glTF, OBJ y STEP/STP; todos se convierten a GLB en el navegador. '
      + 'Para glTF u OBJ selecciona a la vez el modelo y TODOS sus ficheros (.bin, .mtl, texturas). Por FTP, súbelos juntos en la misma carpeta.') : null,
    k === 'photo' && !state.settings.gd_webp ? h('p', { class: 'hint' }, 'Aviso: el servidor no genera WebP; las miniaturas se crearán en JPEG.') : null,
    list.length ? bulkBar : null,
    grid);
}

let galleryRefreshBar = () => {};

/** Productos que usan un medio (foto principal, modelo, vídeo o fotos adicionales no se conocen aquí: sólo principales). */
function mediaUsedBy(id) {
  return state.products.filter((p) => p.photo_media_id === id || p.model_media_id === id).map((p) => p.short_name);
}

function deleteMany(ids) {
  if (!ids.length) return;
  const used = ids.filter((id) => mediaUsedBy(id).length);
  let mode = null;
  openModal(h('div', { class: 'confirm' },
    h('p', null, `¿Quitar ${ids.length} elemento(s) de la galería?`
      + (used.length ? ` ${used.length} se usan en productos y se quitarán de ellos.` : '')),
    h('div', { class: 'row-end' },
      h('button', { class: 'btn', onclick: () => closeModal() }, 'Cancelar'),
      h('button', { class: 'btn', onclick: () => { mode = 'db'; closeModal(); } }, 'Quitar (mantener ficheros)'),
      h('button', { class: 'btn btn-danger', onclick: () => { mode = 'files'; closeModal(); } }, 'Eliminar también los ficheros'))),
  { onClose: async () => {
    if (!mode) return;
    toast(`Eliminando ${ids.length} elemento(s)…`, 'info', 0);
    const r = await busy(null, () => api('media.delete_many', { ids, files: mode === 'files' }));
    if (r) {
      state.media = r.media;
      state.products = r.products;
      ids.forEach((id) => state.gallerySelected.delete(id));
      toast(`${r.deleted} elemento(s) eliminado(s)`);
      render();
    }
  } });
}

function mediaCard(m) {
  const usedBy = mediaUsedBy(m.id);
  const sel = state.gallerySelected;
  return h('article', { class: 'media-card' + (m.status === 'missing' ? ' is-missing' : '') + (sel.has(m.id) ? ' is-selected' : ''), dataset: { id: String(m.id) } },
    h('div', { class: 'media-thumb' }, thumbFor(m),
      h('label', { class: 'media-select', title: 'Seleccionar' },
        h('input', { type: 'checkbox', checked: sel.has(m.id), 'aria-label': 'Seleccionar ' + m.name, onchange: (e) => {
          if (e.target.checked) sel.add(m.id); else sel.delete(m.id);
          galleryRefreshBar();
        } })),
      m.status === 'needs_conversion' ? h('span', { class: 'badge badge-warn corner' }, 'Sin convertir') : null,
      m.status === 'missing' ? h('span', { class: 'badge badge-err corner' }, 'Fichero no encontrado') : null),
    h('div', { class: 'media-info' },
      h('input', { class: 'media-name-input', value: m.name, title: 'Nombre (pulsa Enter para guardar)', onchange: async (e) => {
        const r = await busy(null, () => api('media.update', { id: m.id, name: e.target.value }));
        if (r) { state.media = r.media; toast('Nombre guardado'); }
      } }),
      h('small', null, [m.ext.toUpperCase(), fmtSize(m.size), m.width ? `${m.width}×${m.height}` : null].filter(Boolean).join(' · ')),
      h('small', { class: 'path', title: m.path }, m.source ? m.source : m.path),
      usedBy.length ? h('small', { class: 'used' }, 'Usado en: ' + usedBy.join(', ')) : null),
    h('div', { class: 'media-actions' },
      m.kind === 'model' && m.status === 'ready' ? h('button', { class: 'btn btn-sm btn-primary', onclick: () => openModelEditor(m) }, 'Configurar') : null,
      m.kind === 'model' && m.status === 'needs_conversion' ? h('button', { class: 'btn btn-sm btn-primary', onclick: (e) => busy(e.currentTarget, async () => { await convertOne(m); toast('Convertido'); render(); }) }, 'Convertir a GLB') : null,
      m.kind === 'model' && m.source && m.status === 'ready' ? h('button', { class: 'btn btn-sm', title: 'Volver a generar el GLB desde el original', onclick: (e) => busy(e.currentTarget, async () => { await convertOne(m); toast('Reconvertido'); render(); }) }, '↻') : null,
      h('a', { class: 'btn btn-sm btn-ghost', href: m.url, target: '_blank', rel: 'noopener' }, 'Abrir'),
      h('button', { class: 'btn btn-sm btn-ghost btn-danger', onclick: async () => {
        const content = h('div', { class: 'confirm' });
        let mode = null;
        content.append(
          h('p', null, `¿Quitar "${m.name}" de la galería?` + (usedBy.length ? ` Se usa en: ${usedBy.join(', ')}.` : '')),
          h('div', { class: 'row-end' },
            h('button', { class: 'btn', onclick: () => closeModal() }, 'Cancelar'),
            h('button', { class: 'btn', onclick: () => { mode = 'db'; closeModal(); } }, 'Quitar (mantener fichero)'),
            h('button', { class: 'btn btn-danger', onclick: () => { mode = 'files'; closeModal(); } }, 'Eliminar también el fichero')));
        openModal(content, { onClose: async () => {
          if (!mode) return;
          const r = await busy(null, () => api('media.delete', { id: m.id, files: mode === 'files' }));
          if (r) { state.media = r.media; state.products = r.products; toast('Eliminado'); render(); }
        } });
      } }, 'Eliminar')));
}

function modelViewerAttrs(mv, s) {
  const radius = Math.max(20, Math.min(400, Math.round(10000 / Math.max(10, s.size))));
  mv.setAttribute('orientation', `${s.rz}deg ${s.rx}deg ${s.ry}deg`);
  mv.setAttribute('camera-orbit', `0deg ${s.elevation}deg ${radius}%`);
  mv.setAttribute('exposure', String(s.exposure));
  mv.setAttribute('shadow-intensity', String(s.shadow));
  mv.setAttribute('rotation-per-second', `${s.speed}deg`);
  if (mv.jumpCameraToGoal) mv.jumpCameraToGoal();
}

function openModelEditor(m) {
  const s = { ...m.settings };
  const mv = h('model-viewer', {
    src: m.url, alt: m.name, 'auto-rotate': true, 'auto-rotate-delay': '0', 'camera-controls': true,
    'disable-zoom': true, 'disable-pan': true, 'interaction-prompt': 'none', 'environment-image': 'neutral',
  });
  modelViewerAttrs(mv, s);
  const slider = (key, label, min, max, step, unit = '') => {
    const out = h('output', null, s[key] + unit);
    return h('label', { class: 'slider' },
      h('span', null, label), out,
      h('input', { type: 'range', min, max, step, value: s[key], oninput: (e) => {
        s[key] = parseFloat(e.target.value);
        out.textContent = s[key] + unit;
        modelViewerAttrs(mv, s);
      } }));
  };
  const stage = h('div', { class: 'model-stage', style: { '--logo': `url("${state.settings.logo_url}")` } }, mv);
  openModal(h('div', { class: 'model-editor' },
    h('div', { class: 'panel-head' }, h('h3', null, 'Configurar modelo: ' + m.name)),
    h('div', { class: 'model-editor-grid' },
      stage,
      h('div', { class: 'sliders' },
        h('h4', null, 'Posición'),
        slider('rx', 'Rotación X', -180, 180, 1, '°'),
        slider('ry', 'Rotación Y', -180, 180, 1, '°'),
        slider('rz', 'Rotación Z', -180, 180, 1, '°'),
        slider('elevation', 'Ángulo de cámara', 0, 180, 1, '°'),
        h('h4', null, 'Tamaño y luz'),
        slider('size', 'Tamaño', 10, 400, 1, '%'),
        slider('exposure', 'Luminosidad', 0, 4, 0.05),
        slider('shadow', 'Sombra', 0, 2, 0.05),
        slider('speed', 'Velocidad de giro', 0, 180, 1, '°/s'))),
    h('div', { class: 'row-end' },
      h('button', { class: 'btn', onclick: () => closeModal() }, 'Cancelar'),
      h('button', { class: 'btn btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
        const r = await api('media.update', { id: m.id, settings: s });
        state.media = r.media;
        toast('Configuración del modelo guardada');
        closeModal();
      }) }, 'Guardar'))), { wide: true });
}

// ---------------------------------------------------------------------------
// Pestaña CONFIGURACIÓN
// ---------------------------------------------------------------------------
function passwordCard() {
  const pw = { current: '', new: '' };
  return h('div', { class: 'card' },
    h('h3', null, 'Mi contraseña'),
    h('label', { class: 'field' }, h('span', null, 'Contraseña actual'), h('input', { type: 'password', autocomplete: 'current-password', oninput: (e) => { pw.current = e.target.value; } })),
    h('label', { class: 'field' }, h('span', null, 'Nueva contraseña (mín. 10)'), h('input', { type: 'password', autocomplete: 'new-password', minlength: 10, oninput: (e) => { pw.new = e.target.value; } })),
    h('p', { class: 'hint' }, 'Al cambiarla se cierran tus sesiones abiertas en otros dispositivos.'),
    h('button', { class: 'btn btn-sm btn-primary', onclick: (e) => busy(e.currentTarget, async () => { await api('user.password', pw); toast('Contraseña cambiada'); }) }, 'Cambiar contraseña'));
}

const ROLE_LABEL = { admin: 'Administrador', editor: 'Editor de contenido' };

/** Color de letra legible (blanco u oscuro) para un fondo, igual que en el servidor. */
function contrastText(hex) {
  const c = [1, 3, 5].map((i) => {
    const v = parseInt(hex.substr(i, 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2] > 0.4 ? '#1d2a33' : '#ffffff';
}

/** Colores del apartado de texto (titular, descripción y características) con vista previa. */
function infoColorsField(s) {
  const DEF = { info_bg_top: '#00567d', info_bg: '#6b7479', info_fg_top: 'auto', info_fg: 'auto' };
  // Color efectivo del texto: el elegido o el automático según el fondo.
  const textColor = (fgKey, bgKey) => (s[fgKey] && s[fgKey] !== 'auto' ? s[fgKey] : contrastText(s[bgKey]));
  const preview = h('div', { class: 'info-preview' },
    h('div', { class: 'info-preview-top' }, h('strong', null, 'TITULAR'), h('small', null, ' Subtítulo')),
    h('div', { class: 'info-preview-body' }, 'Descripción del producto…', h('ul', null, h('li', null, 'Característica principal'))));
  const wrap = h('div', { class: 'field' });
  const paint = () => {
    preview.querySelector('.info-preview-top').style.cssText = `background:${s.info_bg_top};color:${textColor('info_fg_top', 'info_bg_top')}`;
    preview.querySelector('.info-preview-body').style.cssText = `background:${s.info_bg};color:${textColor('info_fg', 'info_bg')}`;
  };
  const bgPicker = (key, label) => h('label', { class: 'color-field' },
    h('input', { type: 'color', value: s[key], oninput: (e) => { s[key] = e.target.value; paint(); refresh(); } }), h('span', null, label));
  // Selector de texto con casilla "Automático" (blanco u oscuro según el fondo).
  const fgPicker = (key, bgKey, label) => {
    const auto = !s[key] || s[key] === 'auto';
    const color = h('input', { type: 'color', value: auto ? contrastText(s[bgKey]) : s[key], disabled: auto,
      oninput: (e) => { s[key] = e.target.value; paint(); } });
    return h('div', { class: 'color-field' }, color, h('span', null, label),
      h('label', { class: 'check inline' }, h('input', { type: 'checkbox', checked: auto, onchange: (e) => {
        s[key] = e.target.checked ? 'auto' : color.value;
        color.disabled = e.target.checked;
        if (e.target.checked) color.value = contrastText(s[bgKey]);
        paint();
      } }), 'Automático'));
  };
  const refresh = () => {
    // Actualiza los selectores de texto en modo automático cuando cambia el fondo.
    wrap.querySelectorAll('[data-fg]').forEach((el) => {
      const key = el.dataset.fg;
      const bgKey = key === 'info_fg_top' ? 'info_bg_top' : 'info_bg';
      if (!s[key] || s[key] === 'auto') el.querySelector('input[type=color]').value = contrastText(s[bgKey]);
    });
  };
  const fgTop = fgPicker('info_fg_top', 'info_bg_top', 'Texto del titular');
  fgTop.dataset.fg = 'info_fg_top';
  const fgBody = fgPicker('info_fg', 'info_bg', 'Texto');
  fgBody.dataset.fg = 'info_fg';
  queueMicrotask(paint);
  wrap.append(
    h('span', null, 'Colores del apartado de texto', h('small', null, ' (titular, descripción y características)')),
    h('div', { class: 'color-grid' },
      h('strong', null, 'Fondo'), bgPicker('info_bg_top', 'Franja del titular'), bgPicker('info_bg', 'Fondo del texto'),
      h('strong', null, 'Texto'), fgTop, fgBody),
    h('div', { class: 'row-end' }, h('button', { type: 'button', class: 'btn btn-sm btn-ghost', onclick: () => {
      Object.assign(s, DEF);
      const fresh = infoColorsField(s);
      wrap.replaceWith(fresh);
    } }, 'Restablecer colores')),
    preview);
  return wrap;
}

/** Sitios de confianza que pueden incrustar las páginas públicas en un iframe. */
function embedCard(s) {
  const example = (o) => `<iframe src="https://expo.bezares.com/" style="width:100%;height:100vh;border:0" allow="fullscreen; autoplay; screen-wake-lock"></iframe>`;
  return h('div', { class: 'card' },
    h('h3', null, 'Incrustación en otras webs (iframe)'),
    h('p', { class: 'hint' }, 'Webs de confianza que pueden mostrar expo.bezares.com dentro de un iframe (portada, páginas de tablet y panel de administración). '
      + 'Una por línea, con el formato https://dominio.com (sin ruta). Se admite https://*.dominio.com para todos sus subdominios. '
      + 'Vacío = no se permite incrustar. Añade sólo webs propias: cualquier web de la lista puede mostrar el panel.'),
    h('textarea', { rows: 4, class: 'mono', spellcheck: 'false', placeholder: 'https://cloud.bezares.com', value: s.frame_origins || '',
      oninput: (e) => { s.frame_origins = e.target.value; } }),
    h('p', { class: 'hint' }, 'El dispositivo que muestra el iframe también necesita la cookie de autorización (abre /cookie.html en él). Ejemplo de código para la web que incrusta:'),
    h('pre', null, example()),
    h('p', { class: 'hint' }, 'Pulsa "Guardar configuración" (arriba) para aplicar los cambios.'));
}

/** Botón flotante "Newsletter" de las páginas de tablet. Se guarda con "Guardar configuración". */
function newsletterCard(s) {
  const area = h('textarea', { rows: 6, class: 'mono', spellcheck: 'false', value: s.newsletter_html, oninput: (e) => { s.newsletter_html = e.target.value; } });
  return h('div', { class: 'card' },
    h('h3', null, 'Newsletter'),
    h('p', { class: 'hint' }, 'Botón rojo flotante abajo al centro en las páginas de tablet; al pulsarlo abre un popup con este contenido.'),
    h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: !!s.newsletter_enabled, onchange: (e) => { s.newsletter_enabled = e.target.checked; } }), 'Mostrar el botón Newsletter'),
    h('label', { class: 'field' }, h('span', null, 'Texto del botón'),
      h('input', { value: s.newsletter_label, maxlength: 40, placeholder: 'Newsletter', oninput: (e) => { s.newsletter_label = e.target.value; } })),
    h('label', { class: 'field' }, h('span', null, 'Contenido del popup (HTML)',
      h('small', null, ' Se permiten iframes y enlaces https, imágenes y texto con formato; scripts y eventos se eliminan al guardar.')), area),
    h('div', { class: 'row-end' },
      h('button', { type: 'button', class: 'btn btn-sm btn-ghost', onclick: () => { s.newsletter_html = s.newsletter_default; area.value = s.newsletter_default; } }, 'Restablecer contenido por defecto')),
    h('p', { class: 'hint' }, 'Pulsa "Guardar configuración" (arriba) para aplicar los cambios.'));
}

/** Clave de OpenAI: se guarda con su propio botón y se prueba contra la API al guardarla. */
function openaiBlock(s) {
  const status = h('p', { class: 'hint' }, s.openai_key_set
    ? [h('span', { class: 'badge badge-ok' }, 'Clave guardada'), ' termina en ', h('code', null, s.openai_key_hint)]
    : h('span', { class: 'badge badge-warn' }, 'Sin clave: las traducciones automáticas no funcionarán'));
  // Campo de texto (no "password") con autocompletado desactivado: evita que el navegador
  // lo rellene con la contraseña del panel.
  const keyInput = h('input', { type: 'text', name: 'openai-api-key', autocomplete: 'off', spellcheck: 'false', 'data-lpignore': 'true',
    class: 'mono', placeholder: s.openai_key_set ? 'Pega una clave nueva para sustituirla' : 'sk-…' });
  const modelInput = h('input', { name: 'openai-model', value: s.openai_model || 'gpt-4o-mini', maxlength: 60, autocomplete: 'off' });
  const showTest = (t) => {
    if (!t) return;
    status.append(h('br'), h('span', { class: 'badge ' + (t.ok ? 'badge-ok' : 'badge-err') }, t.ok ? '✔' : '✖'), ' ', t.message);
  };
  return h('div', null,
    h('label', { class: 'field' }, h('span', null, 'Clave API de OpenAI'), keyInput),
    h('label', { class: 'field' }, h('span', null, 'Modelo'), modelInput),
    status,
    h('div', { class: 'row-end' },
      s.openai_key_set ? h('button', { class: 'btn btn-sm btn-ghost btn-danger', onclick: async (e) => {
        if (!(await confirmBox('¿Borrar la clave de OpenAI guardada?', 'Borrar', true))) return;
        const r = await busy(e.currentTarget, () => api('openai.save', { key: '' }));
        if (r) { state.settings = r.settings; toast('Clave borrada'); render(); }
      } }, 'Borrar clave') : null,
      s.openai_key_set ? h('button', { class: 'btn btn-sm', onclick: (e) => busy(e.currentTarget, async () => {
        const r = await api('openai.test');
        toast(r.test.message, r.test.ok ? 'ok' : 'error', 7000);
      }) }, 'Probar clave') : null,
      h('button', { class: 'btn btn-sm btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
        const key = keyInput.value.trim();
        if (!key && !s.openai_key_set) { toast('Pega la clave de OpenAI', 'error'); return; }
        if (!key) {
          // Sólo cambia el modelo
          const r = await api('settings.save', { ...state.settings, openai_model: modelInput.value });
          state.settings = r.settings;
          toast('Modelo guardado');
          render();
          return;
        }
        const r = await api('openai.save', { key, model: modelInput.value });
        state.settings = r.settings;
        render();
        toast(r.test.ok ? 'Clave guardada y verificada' : 'Clave guardada, pero la prueba ha fallado: ' + r.test.message, r.test.ok ? 'ok' : 'error', 9000);
      }) }, 'Guardar clave')));
}

function renderSettings() {
  if (state.role !== 'admin') {
    return h('section', { class: 'panel settings' },
      h('div', { class: 'panel-head' }, h('h2', null, 'Mi cuenta')),
      h('p', { class: 'hint' }, 'Tu usuario es editor de contenido: puedes gestionar tablets, productos y galería. La configuración y los usuarios los gestiona un administrador.'),
      h('div', { class: 'settings-grid' }, passwordCard()));
  }
  const s = clone(state.settings);
  s.openai_key = '';
  const inp = (key, label, attrs = {}, hint) => h('label', { class: 'field' },
    h('span', null, label, hint ? h('small', null, ' ' + hint) : null),
    h('input', { value: s[key] ?? '', ...attrs, oninput: (e) => { s[key] = e.target.value; } }));

  const saveBtn = h('button', { class: 'btn btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
    const r = await api('settings.save', s);
    state.settings = r.settings;
    toast('Configuración guardada');
    render();
  }) }, 'Guardar configuración');

  // Idiomas
  const langs = clone(state.languages);
  const langList = h('div', { class: 'lang-list' });
  const renderLangs = () => langList.replaceChildren(...langs.map((l, i) => h('div', { class: 'row' },
    h('input', { class: 'code', value: l.code, maxlength: 6, disabled: l.code === 'en', placeholder: 'código', oninput: (e) => { l.code = e.target.value.trim().toLowerCase(); } }),
    h('input', { class: 'grow', value: l.name, maxlength: 40, placeholder: 'Nombre', oninput: (e) => { l.name = e.target.value; } }),
    l.code === 'en' ? h('span', { class: 'hint' }, 'base') : h('button', { class: 'btn btn-ghost btn-sm btn-danger', onclick: () => { langs.splice(i, 1); renderLangs(); } }, '✕'))));
  renderLangs();

  // Usuarios
  const nu = { username: '', email: '', password: '', role: 'editor' };

  return h('section', { class: 'panel settings' },
    h('div', { class: 'panel-head' }, h('h2', null, 'Configuración'), h('span', { class: 'grow' }), saveBtn),
    securityCard(),
    h('div', { class: 'settings-grid' },
      h('div', { class: 'card' },
        h('h3', null, 'Encabezado y páginas'),
        h('p', { class: 'hint' }, 'Los títulos y los prefijos de URL están en la pestaña Tablets (se guardan con cada evento).'),
        h('div', { class: 'field' }, h('span', null, 'Imagen de encabezado', h('small', null, ' (vacío = la actual de Bezares)')),
          mediaField('photo', s.header_media_id, (v) => { s.header_media_id = v; }, { label: 'Imagen por defecto' })),
        h('img', { class: 'preview-header', src: s.header_url, alt: '' }),
        h('div', { class: 'field' }, h('span', null, 'Logo de fondo del modelo 3D', h('small', null, ' (vacío = logo Bezares)')),
          mediaField('photo', s.logo_media_id, (v) => { s.logo_media_id = v; }, { label: 'Logo por defecto' })),
        infoColorsField(s)),

      newsletterCard(s),

      embedCard(s),

      h('div', { class: 'card' },
        h('h3', null, 'Traducción automática (ChatGPT)'),
        openaiBlock(s),
        h('h3', null, 'Idiomas disponibles'),
        langList,
        h('div', { class: 'row-end' },
          h('button', { class: 'btn btn-sm', onclick: () => { langs.push({ code: '', name: '' }); renderLangs(); } }, '+ Idioma'),
          h('button', { class: 'btn btn-sm btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
            const r = await api('languages.save', { languages: langs });
            state.languages = r.languages;
            toast('Idiomas guardados');
            render();
          }) }, 'Guardar idiomas'))),

      h('div', { class: 'card' },
        h('h3', null, 'Acceso de dispositivos (cookie)'),
        h('p', { class: 'hint' }, 'Sin la cookie, cualquier visita se redirige a ', h('code', null, s.redirect_url), '. Para autorizar un tablet basta con abrir ',
          h('code', null, '/cookie.html'), ' en él: añade la cookie y lleva a la página principal automáticamente.'),
        h('p', { class: 'hint' }, 'Cualquiera que conozca esa dirección puede autorizarse: no la publiques. Si se difunde, usa "Revocar todos los dispositivos".'),
        h('p', null, 'Estado del .htaccess: ', s.htaccess_ok ? h('span', { class: 'badge badge-ok' }, 'Correcto') : h('span', { class: 'badge badge-err' }, 'No actualizado'),
          ' ', h('button', { class: 'btn btn-sm', onclick: (e) => busy(e.currentTarget, async () => {
            const r = await api('gate.write');
            state.settings = r.settings;
            toast(r.written ? '.htaccess actualizado' : 'No se pudo escribir el .htaccess', r.written ? 'ok' : 'error');
            render();
          }) }, 'Regenerar .htaccess')),
        h('button', { class: 'btn btn-danger btn-sm', onclick: async (e) => {
          if (!(await confirmBox('Se invalidarán las cookies de TODOS los tablets: habrá que volver a autorizarlos en /cookie.html (los tablets conectados borrarán además su contenido guardado). Este dispositivo se reautoriza automáticamente. ¿Continuar?', 'Revocar', true))) return;
          toast('Revocando y verificando la puerta…', 'info', 0);
          const r = await busy(e.currentTarget, () => api('gate.rotate'));
          if (r) {
            state.settings = r.settings;
            lastSecurityReport = r.report;
            state.settings.security_last = { at: Math.floor(Date.now() / 1000), summary: r.report.summary };
            const bad = !r.written || r.report.summary === 'fail';
            toast(!r.written ? 'Token regenerado, pero NO se pudo escribir el .htaccess' : (bad ? 'Token regenerado, pero la verificación ha encontrado fallos: revisa el informe' : 'Dispositivos revocados y puerta verificada'), bad ? 'error' : 'ok', 9000);
            render();
            window.scrollTo(0, 0);
          }
        } }, 'Revocar todos los dispositivos'),
        h('details', null, h('summary', null, 'Directivas nginx para Plesk (recomendado)'),
          h('p', { class: 'hint' }, 'Pega esto en Plesk > Configuración de Apache y nginx > Directivas nginx adicionales. No contiene el token, así que no hay que tocarlo al revocar dispositivos. '
            + 'La puerta de cookie la aplica Apache: "Servir ficheros estáticos directamente mediante nginx" debe estar desactivado (la verificación lo comprueba).'),
          h('pre', null, s.nginx_snippet))),

      h('div', { class: 'card' },
        h('h3', null, 'Email'),
        inp('mail_from', 'Remitente de los emails de recuperación', { type: 'email', maxlength: 150 }),
        h('h3', null, 'Caché'),
        h('p', { class: 'hint' }, 'Las páginas se revalidan automáticamente tras cada cambio. Usa esto sólo si un tablet no muestra un cambio.'),
        h('button', { class: 'btn btn-sm', onclick: (e) => busy(e.currentTarget, async () => { await api('cache.purge'); toast('Los tablets descargarán de nuevo las páginas'); }) }, 'Forzar actualización de páginas')),

      passwordCard(),
      h('div', { class: 'card' },
        h('h3', null, 'Usuarios'),
        h('p', { class: 'hint' }, 'Administrador: todo. Editor de contenido: tablets, productos y galería (sin configuración, seguridad ni usuarios).'),
        h('ul', { class: 'user-list' }, state.users.map((u) => h('li', null,
          h('span', { class: 'grow' }, h('strong', null, u.username), ' ', h('small', null, u.email)),
          u.id === state.me
            ? [h('span', { class: 'badge' }, ROLE_LABEL[u.role] || u.role), h('span', { class: 'badge' }, 'tú')]
            : [h('select', { class: 'role-select', 'aria-label': 'Rol de ' + u.username, onchange: async (e) => {
                const r = await busy(null, () => api('user.role', { id: u.id, role: e.target.value }));
                if (r) { state.users = r.users; toast('Rol actualizado; sus sesiones se han cerrado'); }
                render();
              } }, Object.entries(ROLE_LABEL).map(([v, l]) => h('option', { value: v, selected: u.role === v }, l))),
              h('button', { class: 'btn btn-ghost btn-sm btn-danger', title: 'Eliminar', onclick: async () => {
                if (!(await confirmBox(`¿Eliminar el usuario ${u.username}?`, 'Eliminar', true))) return;
                const r = await busy(null, () => api('user.delete', { id: u.id }));
                if (r) { state.users = r.users; render(); }
              } }, '✕')]))),
        h('details', null, h('summary', null, 'Añadir usuario'),
          h('label', { class: 'field' }, h('span', null, 'Usuario', h('small', null, ' (puede ser su email)')), h('input', { autocomplete: 'off', maxlength: 150, oninput: (e) => { nu.username = e.target.value; } })),
          h('label', { class: 'field' }, h('span', null, 'Email', h('small', null, ' (vacío = el del usuario)')), h('input', { type: 'email', autocomplete: 'off', oninput: (e) => { nu.email = e.target.value; } })),
          h('label', { class: 'field' }, h('span', null, 'Rol'), h('select', { onchange: (e) => { nu.role = e.target.value; } },
            h('option', { value: 'editor', selected: true }, ROLE_LABEL.editor), h('option', { value: 'admin' }, ROLE_LABEL.admin))),
          h('label', { class: 'field' }, h('span', null, 'Contraseña (mín. 10)'), h('input', { type: 'password', autocomplete: 'new-password', oninput: (e) => { nu.password = e.target.value; } })),
          h('button', { class: 'btn btn-sm btn-primary', onclick: (e) => busy(e.currentTarget, async () => { const r = await api('user.add', nu); state.users = r.users; toast('Usuario creado'); render(); }) }, 'Crear usuario')))));
}

// ---------------------------------------------------------------------------
// Verificación de seguridad de la carpeta (el servidor se pide a sí mismo las rutas internas)
// ---------------------------------------------------------------------------
const RESULT_LABEL = { ok: '✔ Correcto', warn: '⚠ Revisar', fail: '✖ Expuesto' };
let lastSecurityReport = null;

function securityCard() {
  const s = state.settings;
  const out = h('div', { class: 'sec-report' });
  const show = (rep) => {
    const title = { ok: 'La carpeta está protegida.', warn: 'Protegida, pero hay puntos a revisar.', fail: '¡Hay ficheros internos accesibles desde Internet!' }[rep.summary];
    out.replaceChildren(
      h('div', { class: 'flash flash-' + ({ ok: 'ok', warn: 'info', fail: 'error' })[rep.summary] }, title,
        rep.summary !== 'ok' ? ' Consulta la sección "Asegurar la carpeta en Plesk" del README.' : ''),
      h('table', { class: 'table sec-table' },
        h('thead', null, h('tr', null, h('th', null, 'Comprobación'), h('th', null, 'URL'), h('th', null, 'HTTP'), h('th', null, 'Resultado'))),
        h('tbody', null, rep.checks.map((c) => h('tr', { class: 'sec-' + c.result },
          h('td', null, c.label, h('br'), h('small', null, c.detail)),
          h('td', null, h('code', null, c.url)),
          h('td', null, c.status || '—'),
          h('td', null, h('span', { class: 'badge badge-' + ({ ok: 'ok', warn: 'warn', fail: 'err' })[c.result] }, RESULT_LABEL[c.result])))))));
  };
  if (lastSecurityReport) show(lastSecurityReport);
  const last = s.security_last;
  return h('div', { class: 'card' },
    h('div', { class: 'panel-head' },
      h('h3', null, 'Seguridad de la carpeta'),
      h('span', { class: 'grow' }),
      last ? h('small', { class: 'hint' }, 'Última verificación: ' + new Date(last.at * 1000).toLocaleString('es-ES') + ' – ',
        h('span', { class: 'badge badge-' + ({ ok: 'ok', warn: 'warn', fail: 'err' })[last.summary] }, RESULT_LABEL[last.summary])) : null,
      h('button', { class: 'btn btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
        toast('Verificando… el servidor está comprobando sus propias URLs', 'info', 0);
        const r = await api('security.check');
        lastSecurityReport = r.report;
        state.settings.security_last = { at: Math.floor(Date.now() / 1000), summary: r.report.summary };
        state.settings.htaccess_ok = r.report.htaccess_ok;
        toast('Verificación terminada', r.report.summary === 'fail' ? 'error' : 'ok');
        render();
      }) }, '🛡 Verificar seguridad')),
    h('p', { class: 'hint' }, 'Comprueba desde el propio servidor que app/, la base de datos, .htaccess, .git y README no se pueden descargar, '
      + 'que la puerta de cookie protege también los ficheros estáticos (nginx) y que /.well-known sigue accesible para el certificado SSL. '
      + 'Repítela después de cada cambio en Plesk.'),
    h('p', { class: 'hint' }, 'Carpeta de datos: ', s.data_in_webroot
      ? h('span', { class: 'badge badge-warn' }, 'dentro de httpdocs (app/data, protegida por .htaccess)')
      : h('span', { class: 'badge badge-ok' }, 'fuera de la web (carpeta private de Plesk)')),
    out);
}

// ---------------------------------------------------------------------------
// Render principal / navegación
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Pestaña EVENTOS: guardar / restaurar la configuración de tablets
// ---------------------------------------------------------------------------
function renderEvents() {
  const fmt = (t) => new Date(t * 1000).toLocaleString('es-ES', { dateStyle: 'short', timeStyle: 'short' });
  const hasDrafts = [...state.tabletDrafts.values()].some((d) => {
    const t = state.tablets.find((x) => x.id === d.id);
    return !t || JSON.stringify(t) !== JSON.stringify(d);
  });
  const nameInput = h('input', { placeholder: 'Nombre del evento (p. ej. BBM 2026)', maxlength: 120, value: state.settings.event_title || '' });
  const current = state.tablets.filter((t) => t.active);

  const saveNew = h('button', { class: 'btn btn-primary', onclick: (e) => busy(e.currentTarget, async () => {
    const r = await api('events.save', { name: nameInput.value });
    state.events = r.events;
    toast('Configuración guardada como evento');
    render();
  }) }, 'Guardar configuración actual');

  const card = (ev) => {
    const s = ev.summary;
    return h('article', { class: 'card event-card' },
      h('div', { class: 'panel-head' },
        h('input', { class: 'event-name', value: ev.name, maxlength: 120, title: 'Nombre (Enter para guardar)', onchange: async (e) => {
          const r = await busy(null, () => api('events.rename', { id: ev.id, name: e.target.value }));
          if (r) { state.events = r.events; toast('Nombre guardado'); }
        } }),
        h('span', { class: 'grow' }),
        h('small', { class: 'hint' }, 'Guardado ' + fmt(ev.updated_at) + (ev.created_by ? ' por ' + ev.created_by : ''))),
      h('p', { class: 'hint' }, `Título: “${s.event_title || '—'}”` + (s.url_prefix ? ` · URLs /${s.url_prefix}1a/` : '') + ` · ${s.active} tablet(s) activo(s) de ${s.tablets} · ${s.products} producto(s)`),
      h('ul', { class: 'event-detail' }, s.detail.filter((t) => t.active).map((t) =>
        h('li', null, h('strong', null, 'Tablet ' + t.number), ': ', t.products.join(' · ') || '—'))),
      h('div', { class: 'row-end' },
        h('button', { class: 'btn btn-sm btn-ghost btn-danger', onclick: async () => {
          if (!(await confirmBox(`¿Eliminar el evento “${ev.name}”? La configuración actual de los tablets no cambia.`, 'Eliminar', true))) return;
          const r = await busy(null, () => api('events.delete', { id: ev.id }));
          if (r) { state.events = r.events; toast('Evento eliminado'); render(); }
        } }, 'Eliminar'),
        h('button', { class: 'btn btn-sm', title: 'Sustituye lo guardado en este evento por la configuración actual', onclick: async (e) => {
          const btn = e.currentTarget;
          if (!(await confirmBox(`¿Sobrescribir “${ev.name}” con la configuración actual de los tablets?`, 'Sobrescribir'))) return;
          const r = await busy(btn, () => api('events.save', { id: ev.id, name: ev.name }));
          if (r) { state.events = r.events; toast('Evento actualizado'); render(); }
        } }, 'Sobrescribir con la actual'),
        h('button', { class: 'btn btn-sm btn-primary', onclick: async (e) => {
          const btn = e.currentTarget;
          let backup = true;
          let ok = false;
          openModal(h('div', { class: 'confirm' },
            h('p', null, `Se sustituirá la configuración de TODOS los tablets (y el título del evento) por la de “${ev.name}”. Los tablets mostrarán el cambio al recargar.`),
            h('label', { class: 'check' }, h('input', { type: 'checkbox', checked: true, onchange: (x) => { backup = x.target.checked; } }),
              'Guardar antes la configuración actual como copia automática'),
            h('div', { class: 'row-end' },
              h('button', { class: 'btn', onclick: () => closeModal() }, 'Cancelar'),
              h('button', { class: 'btn btn-primary', onclick: () => { ok = true; closeModal(); } }, 'Restaurar'))),
          { onClose: async () => {
            if (!ok) return;
            const r = await busy(btn, () => api('events.restore', { id: ev.id, backup }));
            if (!r) return;
            state.events = r.events;
            state.tablets = r.tablets;
            Object.assign(state.settings, r.event_settings);
            state.tabletDrafts.clear();
            if (r.warnings.length) {
              openModal(h('div', { class: 'confirm' },
                h('h3', null, 'Evento restaurado con avisos'),
                h('ul', null, r.warnings.map((w) => h('li', null, w))),
                h('div', { class: 'row-end' }, h('button', { class: 'btn btn-primary', onclick: () => closeModal() }, 'Entendido'))));
            } else {
              toast(`Evento “${ev.name}” restaurado`);
            }
            render();
          } });
        } }, 'Restaurar')));
  };

  return h('section', { class: 'panel' },
    h('div', { class: 'panel-head' }, h('h2', null, 'Eventos'),
      h('span', { class: 'hint' }, 'Guarda la configuración de los tablets de cada feria (tablets activos, productos, orden, flechas, fotos, idiomas, títulos y prefijos de URL) para restaurarla más adelante.')),
    h('div', { class: 'card' },
      h('h3', null, 'Configuración actual'),
      h('p', { class: 'hint' }, `${current.length} tablet(s) activo(s): ` + (current.map((t) => 'Tablet ' + t.number).join(', ') || 'ninguno')),
      hasDrafts ? h('p', { class: 'flash flash-info' }, 'Hay cambios sin guardar en la pestaña Tablets: sólo se incluye lo ya guardado.') : null,
      h('div', { class: 'row' }, nameInput, saveNew)),
    state.events.length ? h('div', { class: 'event-list' }, state.events.map(card)) : h('p', { class: 'hint' }, 'Todavía no hay eventos guardados.'));
}

const VIEWS = { tablets: renderTablets, products: renderProducts, gallery: renderGallery, events: renderEvents, settings: renderSettings };

function render() {
  if (!VIEWS[state.tab]) state.tab = 'tablets';
  document.querySelectorAll('.tabs [data-tab]').forEach((b) => b.setAttribute('aria-selected', b.dataset.tab === state.tab ? 'true' : 'false'));
  const y = window.scrollY;
  app.replaceChildren(VIEWS[state.tab]());
  window.scrollTo(0, y);
}

document.querySelectorAll('.tabs [data-tab]').forEach((b) => b.addEventListener('click', () => {
  state.tab = b.dataset.tab;
  history.replaceState(null, '', '#' + state.tab);
  window.scrollTo(0, 0);
  render();
}));

window.addEventListener('beforeunload', (e) => {
  if (state.productDraft || [...state.tabletDrafts.keys()].some((k) => {
    const d = state.tabletDrafts.get(k);
    const t = state.tablets.find((x) => x.id === d.id);
    return !t || JSON.stringify(t) !== JSON.stringify(d);
  })) {
    e.preventDefault();
  }
});

(async function init() {
  try {
    const r = await api('bootstrap');
    Object.assign(state, { settings: r.settings, languages: r.languages, tablets: r.tablets, products: r.products, media: r.media, users: r.users, me: r.me, role: r.role, events: r.events || [] });
    render();
  } catch (e) {
    app.replaceChildren(h('p', { class: 'flash flash-error' }, 'No se pudo cargar el panel: ' + e.message));
  }
})();
