/*
 * Service worker de expo.bezares.com
 * - Recursos /assets y /media: cache-first (las URLs llevan ?v=versión).
 * - Páginas: network-first con timeout; si no hay red, se sirve la copia guardada.
 * - Vídeos: peticiones Range servidas desde la caché cuando el fichero ya está guardado.
 * - Precarga de todas las páginas y medios del tablet al recibir el mensaje "prefetch".
 * - Revocación: si la puerta redirige (cookie revocada) se borra toda la caché; sin conexión,
 *   lo guardado sólo se muestra hasta MAX_OFFLINE_MS desde la última autorización confirmada.
 */
const SW_VERSION = '2';
const C_ASSETS = 'expo-assets-v' + SW_VERSION;
const C_PAGES = 'expo-pages-v' + SW_VERSION;
const KEEP = [C_ASSETS, C_PAGES];
const PAGE_TIMEOUT = 4000;
// Sin conexión, el contenido guardado sólo se muestra durante este tiempo desde la última
// vez que el servidor confirmó que el dispositivo sigue autorizado.
const MAX_OFFLINE_MS = 7 * 24 * 3600 * 1000;
const AUTH_KEY = '/__expo_auth_ok';
const inFlight = new Set();

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

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    for (const k of await caches.keys()) if (!KEEP.includes(k)) await caches.delete(k);
    await self.clients.claim();
  })());
});

function skip(url) {
  return url.origin !== self.location.origin
    || /^\/(admin|cookie\.|install\.php|\.well-known|sw\.js)/.test(url.pathname);
}
const isAsset = (url) => url.pathname.startsWith('/assets/') || url.pathname.startsWith('/media/');
const cacheable = (res) => res && res.ok && res.status === 200 && res.type === 'basic' && !res.redirected;

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (skip(url)) return;
  if (isAsset(url)) {
    e.respondWith(req.headers.has('range') ? rangeResponse(req, e) : cacheFirst(req));
  } else if (req.mode === 'navigate') {
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
  if (hit) {
    if (await authFresh()) return hit;
    // Autorización caducada: no servir lo guardado; ir a la red (la puerta decidirá).
    await purgeAll();
  }
  const res = await fetch(req);
  if (cacheable(res)) putAsset(cache, req.url, res.clone()).catch(() => {});
  return res;
}

async function rangeResponse(req, event) {
  const cache = await caches.open(C_ASSETS);
  let hit = await cache.match(req.url);
  if (hit && !(await authFresh())) { await purgeAll(); hit = null; }
  if (!hit) {
    // Primera reproducción: se sirve desde red y se guarda el fichero completo en segundo plano.
    event.waitUntil(cacheInBackground(req.url));
    return fetch(req);
  }
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

async function cacheInBackground(url) {
  if (inFlight.has(url)) return;
  inFlight.add(url);
  try {
    const cache = await caches.open(C_ASSETS);
    if (await cache.match(url)) return;
    const res = await fetch(url, { credentials: 'same-origin' });
    if (cacheable(res)) await putAsset(cache, url, res);
  } catch (_) { /* sin red: se reintentará en otra visita */ } finally {
    inFlight.delete(url);
  }
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

async function prefetch(urls) {
  const assets = await caches.open(C_ASSETS);
  const pages = await caches.open(C_PAGES);
  const queue = urls.filter((u) => typeof u === 'string' && u.startsWith('/'));
  const worker = async () => {
    while (queue.length) {
      const path = queue.shift();
      const abs = new URL(path, self.location.origin).href;
      const url = new URL(abs);
      if (skip(url)) continue;
      try {
        if (isAsset(url)) {
          if (await assets.match(abs)) continue;
          await cacheInBackground(abs);
        } else {
          const res = await fetch(abs, { credentials: 'same-origin', redirect: 'manual' });
          if (isRevoked(res)) { await purgeAll(); return; }
          if (cacheable(res)) await pages.put(abs, res);
        }
      } catch (_) { /* ignorar */ }
    }
  };
  await Promise.all([worker(), worker()]);
}

self.addEventListener('message', (e) => {
  const d = e.data || {};
  if (d.type === 'prefetch' && Array.isArray(d.urls)) e.waitUntil(prefetch(d.urls.slice(0, 200)));
  if (d.type === 'clear') e.waitUntil((async () => { for (const k of await caches.keys()) await caches.delete(k); })());
});
