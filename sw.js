/*
 * Service worker de expo.bezares.com
 * - Recursos /assets y /media: cache-first (las URLs llevan ?v=versión).
 * - Páginas: network-first con timeout; si no hay red, se sirve la copia guardada.
 * - Vídeos: peticiones Range servidas desde la caché cuando el fichero ya está guardado.
 * - La página pide guardar cada contenido con el mensaje "ensure" (ver assets/expo.js). Los
 *   vídeos se descargan por trozos que se conservan: si se corta, continúa donde estaba.
 * - Revocación: si la puerta redirige (cookie revocada) se borra toda la caché. Sin conexión, lo
 *   guardado sólo se muestra hasta MAX_OFFLINE_MS desde la última autorización confirmada;
 *   pasado ese plazo NO se borra: se pregunta al servidor (/ping.php) y, si confirma, se usa.
 */
const SW_VERSION = '2'; // versión de las cachés: cambiarla obliga a descargar todo otra vez
const C_ASSETS = 'expo-assets-v' + SW_VERSION;
const C_PAGES = 'expo-pages-v' + SW_VERSION;
const C_PARTS = 'expo-parts-v' + SW_VERSION;
const KEEP = [C_ASSETS, C_PAGES, C_PARTS];
const PAGE_TIMEOUT = 4000;
// Sin conexión, el contenido guardado sólo se muestra durante este tiempo desde la última
// vez que el servidor confirmó que el dispositivo sigue autorizado.
const MAX_OFFLINE_MS = 7 * 24 * 3600 * 1000;
const AUTH_KEY = '/__expo_auth_ok';
const CHUNK = 2 * 1024 * 1024;   // trozos de vídeo
const CHUNK_TIMEOUT = 180000;
const ENSURE_BUDGET = 20000;     // tiempo por mensaje: el navegador detiene los SW que tardan mucho
const jobs = new Map();

async function purgeAll() {
  for (const k of await caches.keys()) await caches.delete(k);
}

async function markAuthorized() {
  const c = await caches.open(C_PAGES);
  await c.put(AUTH_KEY, new Response(String(Date.now())));
}

async function authFresh() {
  const c = await caches.open(C_PAGES);
  const r = await c.match(AUTH_KEY);
  if (!r) return false;
  const t = parseInt(await r.text(), 10);
  return Number.isFinite(t) && Date.now() - t < MAX_OFFLINE_MS;
}

/** Respuesta que indica que el dispositivo ya no está autorizado (redirección de la puerta). */
const isRevoked = (res) => res && (res.type === 'opaqueredirect' || (res.redirected && new URL(res.url).origin !== self.location.origin));

/** fetch con tiempo máximo, incluida la lectura del cuerpo (que se hace dentro de "use"). */
async function timedFetch(url, opts, ms, use) {
  const ac = new AbortController();
  const t = setTimeout(() => ac.abort(), ms);
  try {
    return await use(await fetch(url, { ...opts, signal: ac.signal }));
  } finally {
    clearTimeout(t);
  }
}

/** Pregunta al servidor si el dispositivo sigue autorizado: 'ok' | 'revoked' | 'offline'. */
let checking = null;
let lastOffline = 0;
function verifyAuth(force) {
  if (checking) return checking;
  if (!force && Date.now() - lastOffline < 10000) return Promise.resolve('offline');
  checking = (async () => {
    let state = 'offline';
    try {
      state = await timedFetch('/ping.php', { credentials: 'same-origin', redirect: 'manual', cache: 'no-store' }, 8000, async (res) => {
        if (isRevoked(res)) { await purgeAll(); return 'revoked'; }
        if (res.status === 204) { await markAuthorized(); return 'ok'; }
        return 'offline';
      });
    } catch (_) { /* sin red */ }
    if (state === 'offline') lastOffline = Date.now();
    checking = null;
    return state;
  })();
  return checking;
}

/** ¿Se puede servir lo guardado? Si la autorización ha caducado, lo decide el servidor. */
async function mayServeCached() {
  return (await authFresh()) || (await verifyAuth(false)) === 'ok';
}

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    for (const k of await caches.keys()) if (!KEEP.includes(k)) await caches.delete(k);
    await self.clients.claim();
  })());
});

function skip(url) {
  return url.origin !== self.location.origin
    || /^\/(admin|cookie\.|install\.php|ping\.php|\.well-known|sw\.js)/.test(url.pathname);
}
const isAsset = (url) => url.pathname.startsWith('/assets/') || url.pathname.startsWith('/media/');
const isVideo = (url) => /\.(mp4|m4v|webm)$/i.test(url.pathname);
const cacheable = (res) => res && res.ok && res.status === 200 && res.type === 'basic' && !res.redirected;

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (skip(url)) return;
  if (isAsset(url)) {
    e.respondWith(req.headers.has('range') ? rangeResponse(req) : cacheFirst(req));
  } else if (req.mode === 'navigate' && req.destination !== 'iframe') {
    // Las navegaciones dentro de un iframe van directas a la red (el servidor las lleva al panel).
    e.respondWith(networkFirst(req));
  }
});

/** Guarda en caché y elimina versiones antiguas (?v= distinta) del mismo fichero. */
async function putAsset(cache, url, res) {
  const u = new URL(url);
  for (const k of await cache.keys()) {
    const ku = new URL(k.url);
    if (ku.pathname === u.pathname && ku.search !== u.search) await cache.delete(k);
  }
  await cache.put(url, res);
}

async function cacheFirst(req) {
  const cache = await caches.open(C_ASSETS);
  const hit = await cache.match(req.url);
  if (hit && await mayServeCached()) return hit;
  const res = await fetch(req);
  if (cacheable(res)) putAsset(cache, req.url, res.clone()).catch(() => {});
  return res;
}

async function rangeResponse(req) {
  const cache = await caches.open(C_ASSETS);
  const hit = await cache.match(req.url);
  // Sin guardar todavía: directo de la red. No se descarga una segunda copia desde aquí; de
  // guardarlo se encarga "ensure", y la página espera a que termine para reproducirlo.
  if (!hit || !(await mayServeCached())) return fetch(req);
  const blob = await hit.blob();
  const m = /bytes=(\d*)-(\d*)/.exec(req.headers.get('range') || '');
  let start = m && m[1] ? parseInt(m[1], 10) : 0;
  let end = m && m[2] ? parseInt(m[2], 10) : blob.size - 1;
  if (m && !m[1] && m[2]) { start = Math.max(0, blob.size - parseInt(m[2], 10)); end = blob.size - 1; }
  if (start >= blob.size) return new Response(null, { status: 416, headers: { 'Content-Range': `bytes */${blob.size}` } });
  end = Math.min(end, blob.size - 1);
  return new Response(blob.slice(start, end + 1), {
    status: 206,
    headers: {
      'Content-Type': hit.headers.get('Content-Type') || 'application/octet-stream',
      'Content-Range': `bytes ${start}-${end}/${blob.size}`,
      'Content-Length': String(end - start + 1),
      'Accept-Ranges': 'bytes',
    },
  });
}

async function networkFirst(req) {
  const cache = await caches.open(C_PAGES);
  try {
    const res = await Promise.race([
      fetch(req),
      new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), PAGE_TIMEOUT)),
    ]);
    if (isRevoked(res)) {
      // La puerta ha redirigido: cookie revocada o caducada → borrar todo lo guardado.
      await purgeAll();
      return res;
    }
    if (cacheable(res)) {
      // Se espera a la marca antes de responder: los recursos de la página la consultan.
      await markAuthorized().catch(() => {});
      cache.put(req.url, res.clone()).catch(() => {});
    }
    return res;
  } catch (err) {
    const hit = (await authFresh()) ? await cache.match(req.url) : null;
    if (hit) return hit;
    return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Sin conexión</title>'
      + '<body style="font-family:system-ui;text-align:center;padding:4rem 1rem"><h1>Sin conexión</h1><p>Esta página aún no está guardada en el dispositivo.</p>'
      + '<p><a href="/">Volver al inicio</a></p>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }
}

// ---------------------------------------------------------------------------
// Guardado de contenido a petición de la página
// Estados: saved | partial {got,total} | revoked | quota | error
// ---------------------------------------------------------------------------
async function savePage(abs) {
  return timedFetch(abs, { credentials: 'same-origin', redirect: 'manual', cache: 'no-cache' }, 30000, async (res) => {
    if (isRevoked(res)) { await purgeAll(); return { state: 'revoked' }; }
    if (!cacheable(res)) return { state: 'error' };
    await (await caches.open(C_PAGES)).put(abs, res);
    await markAuthorized();
    return { state: 'saved' };
  });
}

async function saveAsset(abs) {
  const cache = await caches.open(C_ASSETS);
  if (await cache.match(abs)) return { state: 'saved' };
  return timedFetch(abs, { credentials: 'same-origin', redirect: 'manual' }, CHUNK_TIMEOUT, async (res) => {
    if (isRevoked(res)) { await purgeAll(); return { state: 'revoked' }; }
    if (!cacheable(res)) return { state: 'error' };
    await putAsset(cache, abs, res);
    return { state: 'saved' };
  });
}

const partKey = (abs, i) => abs + (abs.includes('?') ? '&' : '?') + '__part=' + i;

async function dropParts(parts, abs, all) {
  const u = new URL(abs);
  for (const k of await parts.keys()) {
    const ku = new URL(k.url);
    if (ku.pathname !== u.pathname) continue;
    if (all || ku.searchParams.get('v') !== u.searchParams.get('v')) await parts.delete(k);
  }
}

async function saveVideo(abs) {
  const cache = await caches.open(C_ASSETS);
  const parts = await caches.open(C_PARTS);
  if (await cache.match(abs)) return { state: 'saved' };
  const deadline = Date.now() + ENSURE_BUDGET;
  await dropParts(parts, abs, false); // trozos de versiones anteriores del fichero

  let n = 0;
  let total = 0;
  let tag = '';
  let type = '';
  for (;;) {
    const p = await parts.match(partKey(abs, n));
    if (!p) break;
    total = parseInt(p.headers.get('X-Total'), 10) || 0;
    tag = p.headers.get('X-Tag') || '';
    type = p.headers.get('Content-Type') || '';
    n++;
  }
  while (!total || n * CHUNK < total) {
    const from = n * CHUNK;
    const stop = await timedFetch(abs, {
      credentials: 'same-origin', redirect: 'manual', cache: 'no-store',
      headers: { Range: `bytes=${from}-${from + CHUNK - 1}` },
    }, CHUNK_TIMEOUT, async (res) => {
      if (isRevoked(res)) { await purgeAll(); return { state: 'revoked' }; }
      if (cacheable(res)) { // el servidor no admite Range: ha enviado el fichero completo
        await putAsset(cache, abs, res);
        await dropParts(parts, abs, true);
        return { state: 'saved' };
      }
      if (res.status >= 500) return { state: 'error' };
      const m = /^bytes (\d+)-(\d+)\/(\d+)$/.exec(res.headers.get('Content-Range') || '');
      const t = m ? parseInt(m[3], 10) : 0;
      const newTag = res.headers.get('ETag') || res.headers.get('Last-Modified') || '';
      if (res.status !== 206 || !m || parseInt(m[1], 10) !== from || !t || (total && (t !== total || newTag !== tag))) {
        await dropParts(parts, abs, true); // ha cambiado en el servidor: empezar de nuevo
        return { state: 'error' };
      }
      const blob = await res.blob();
      if (blob.size !== Math.min(CHUNK, t - from)) return { state: 'error' };
      total = t;
      tag = newTag;
      type = res.headers.get('Content-Type') || 'video/mp4';
      await parts.put(partKey(abs, n), new Response(blob, {
        headers: { 'Content-Type': type, 'X-Total': String(total), 'X-Tag': tag },
      }));
      return null;
    });
    if (stop) return stop;
    n++;
    if (n * CHUNK < total && Date.now() > deadline) return { state: 'partial', got: n * CHUNK, total };
  }

  const blobs = [];
  for (let i = 0; i < n; i++) {
    const p = await parts.match(partKey(abs, i));
    if (!p) return { state: 'error' };
    blobs.push(await p.blob());
  }
  const full = new Blob(blobs, { type });
  if (full.size !== total) { await dropParts(parts, abs, true); return { state: 'error' }; }
  await putAsset(cache, abs, new Response(full, { headers: { 'Content-Type': type, 'Content-Length': String(total) } }));
  await dropParts(parts, abs, true);
  return { state: 'saved' };
}

/** Guarda un contenido. Varias peticiones de lo mismo comparten una sola descarga. */
function ensure(path) {
  const abs = new URL(path, self.location.origin).href;
  const url = new URL(abs);
  if (skip(url)) return Promise.resolve({ state: 'error' });
  if (!jobs.has(abs)) {
    jobs.set(abs, (async () => {
      try {
        if (!isAsset(url)) return await savePage(abs);
        return await (isVideo(url) ? saveVideo(abs) : saveAsset(abs));
      } catch (err) {
        return { state: err && err.name === 'QuotaExceededError' ? 'quota' : 'error' };
      }
    })().finally(() => jobs.delete(abs)));
  }
  return jobs.get(abs);
}

self.addEventListener('message', (e) => {
  const d = e.data || {};
  const reply = (m) => { if (e.ports && e.ports[0]) e.ports[0].postMessage(m); };
  if (d.type === 'hello') reply({ state: 'ok' });
  if (d.type === 'check') e.waitUntil(verifyAuth(true).then((state) => reply({ state })));
  if (d.type === 'ensure' && typeof d.url === 'string') e.waitUntil(ensure(d.url).then(reply));
  if (d.type === 'clear') e.waitUntil(purgeAll());
});
