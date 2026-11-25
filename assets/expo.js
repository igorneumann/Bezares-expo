/* Script público: registra el service worker, precarga el contenido del tablet y mantiene la pantalla encendida. */
(function () {
  'use strict';

  // Dentro de un iframe la portada lleva al panel de administración (respaldo por si el
  // navegador no envía Sec-Fetch-Dest o la página salió de la caché).
  var framed = false;
  try { framed = window.self !== window.top; } catch (e) { framed = true; }
  if (framed && document.documentElement && document.querySelector('body.page-index')) {
    location.replace('/admin/');
    return;
  }
  // Página principal con varios eventos activos: los botones del titular eligen cuál se ve.
  // Se recuerda el último elegido en este dispositivo.
  (function () {
    var tabs = document.querySelectorAll('[data-event-tab]');
    if (!tabs.length) return;
    function show(id) {
      var found = false;
      tabs.forEach(function (b) { if (b.getAttribute('data-event-tab') === id) found = true; });
      if (!found) return;
      tabs.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-event-tab') === id ? 'true' : 'false'); });
      document.querySelectorAll('[data-event-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-event-panel') !== id; });
    }
    tabs.forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-event-tab');
        show(id);
        try { localStorage.setItem('expo_event', id); } catch (e) { /* sin almacenamiento */ }
      });
    });
    try { show(localStorage.getItem('expo_event')); } catch (e) { /* sin almacenamiento */ }
  })();

  // Vista previa en el panel (pestaña Tablets): sin estadísticas ni modo reposo.
  var preview = false;
  try { preview = framed && window.parent.location.pathname.indexOf('/admin') === 0; } catch (e) { /* otro dominio */ }

  // ---------------------------------------------------------------------------
  // Tema según la orientación: la página trae los dos diseños en <template> y se coloca el que
  // corresponde. Al girar el tablet se recarga (desde lo guardado, si no hay red) con el otro.
  // ---------------------------------------------------------------------------
  function isPortrait() {
    // En tablets se usa la orientación de la pantalla: la de la ventana cambia al abrirse el teclado.
    var o = window.screen && screen.orientation && screen.orientation.type;
    if (o && !framed && window.matchMedia('(pointer: coarse)').matches) return o.indexOf('portrait') === 0;
    return window.matchMedia('(orientation: portrait)').matches;
  }
  if (document.querySelector('template[data-orient]')) initOrientation();

  function initOrientation() {
    var b = document.body;
    var portrait = isPortrait();
    var want = portrait ? 'port' : 'land';
    b.classList.remove('orient-wait');
    b.className += ' ' + b.getAttribute('data-theme-' + want);
    [].forEach.call(document.querySelectorAll('template[data-orient]'), function (t) {
      if (t.getAttribute('data-orient') === want) t.parentNode.insertBefore(document.importNode(t.content, true), t);
      t.remove();
    });

    var timer = 0;
    function changed() {
      clearTimeout(timer);
      timer = setTimeout(function () {
        if (isPortrait() === portrait) return;
        // Sin interrumpir la pantalla completa ni el formulario de la newsletter: al cerrarlos
        var dlg = document.getElementById('nl-dialog');
        if (document.fullscreenElement || document.webkitFullscreenElement || (dlg && dlg.open)) return;
        try { sessionStorage.setItem(b.classList.contains('is-idle') ? 'expo_idle' : 'expo_rot', '1'); } catch (e) { /* sin almacenamiento */ }
        location.reload();
      }, 300);
    }
    window.addEventListener('resize', changed);
    if (window.screen && screen.orientation && screen.orientation.addEventListener) screen.orientation.addEventListener('change', changed);
    document.addEventListener('fullscreenchange', changed);
    document.addEventListener('webkitfullscreenchange', changed);
    document.addEventListener('close', changed, true);
  }

  function prefetchList() {
    var el = document.getElementById('expo-prefetch');
    if (!el) return [];
    try { return JSON.parse(el.textContent || '[]'); } catch (e) { return []; }
  }

  // ---------------------------------------------------------------------------
  // Contenido sin conexión. La página pide al service worker que guarde, uno a uno, todo el
  // contenido del tablet (primero el vídeo que se está viendo). El vídeo no empieza hasta que
  // está guardado (mientras, su imagen), así se descarga una sola vez.
  // ---------------------------------------------------------------------------
  initOffline();

  function initOffline() {
    var video = document.querySelector('video[data-src]');
    var vurl = video ? video.getAttribute('data-src') : '';
    var box = video ? video.parentNode : null;
    var sw = 'serviceWorker' in navigator ? navigator.serviceWorker : null;

    function play() {
      if (!video || video.getAttribute('src')) return;
      box.classList.remove('is-saving');
      video.muted = true; // el atributo no siempre vale en vídeos colocados desde <template>
      video.src = vurl;
      var p = video.play();
      if (p && p.catch) p.catch(function () {});
    }
    function progress(r) { if (box) box.style.setProperty('--saved', Math.round(r.got / r.total * 100) + '%'); }

    if (!sw || preview) {
      play();
      if (!sw) setInd('bad', 'Este navegador no guarda contenido');
      return;
    }

    function wait(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

    /** Mensaje al service worker con respuesta (null si no contesta a tiempo). */
    function ask(msg, ms) {
      return new Promise(function (ok) {
        sw.getRegistration('/').then(function (reg) {
          var target = reg && reg.active;
          if (!target) { ok(null); return; }
          var ch = new MessageChannel();
          var t = setTimeout(function () { ok(null); }, ms);
          ch.port1.onmessage = function (ev) { clearTimeout(t); ok(ev.data); };
          target.postMessage(msg, [ch.port2]);
        }).catch(function () { ok(null); });
      });
    }

    // Tras una actualización puede responder todavía el service worker anterior (no conoce estos
    // mensajes): se espera al nuevo y a que controle la página.
    function ready(tries) {
      return ask({ type: 'hello' }, 2000).then(function (r) {
        if (r && sw.controller) return true;
        if (tries <= 0) return !!r;
        return wait(1000).then(function () { return ready(tries - 1); });
      });
    }

    function revoked() {
      // La recarga lleva a la puerta, que redirige. Como mucho una vez cada 10 minutos.
      try {
        var last = parseInt(sessionStorage.getItem('expo_revoked') || '0', 10);
        if (Date.now() - last < 600000) return;
        sessionStorage.setItem('expo_revoked', String(Date.now()));
      } catch (e) { return; }
      location.reload();
    }

    function save(url, onPart) {
      return ask({ type: 'ensure', url: url }, 240000).then(function (r) {
        if (r && r.state === 'partial') {
          if (onPart) onPart(r);
          return save(url, onPart);
        }
        return r ? r.state : 'error';
      });
    }

    var urls = prefetchList();
    if (vurl) urls = [vurl].concat(urls.filter(function (u) { return u !== vurl; }));
    var saved = {};
    var full = false, stopped = false, running = false, retry = 0, videoFails = 0;
    function count() { return Object.keys(saved).length; }
    function showInd() {
      if (!urls.length) return;
      var yt = parseInt(document.body.getAttribute('data-yt') || '0', 10);
      var extra = yt ? ' · ' + yt + ' vídeo(s) de YouTube no se pueden guardar' : '';
      var n = count() + ' de ' + urls.length;
      if (count() === urls.length) setInd(yt ? 'part' : 'ok', 'Contenido guardado: ' + n + extra);
      else if (full) setInd('bad', 'Sin espacio en el tablet: guardado ' + n + extra);
      else if (running) setInd('busy', 'Guardando contenido: ' + n + extra);
      else setInd('bad', 'Falta contenido por guardar: ' + n + ' (se reintentará)' + extra);
    }

    function sync() {
      if (running || stopped || !urls.length) return;
      running = true;
      clearTimeout(retry);
      showInd();
      var i = 0;
      (function next() {
        if (i >= urls.length || stopped) return Promise.resolve();
        var u = urls[i++];
        if (saved[u]) return next();
        return save(u, u === vurl ? progress : null).then(function (st) {
          if (st === 'saved') saved[u] = 1;
          else if (st === 'quota') full = true;
          else if (st === 'revoked') { stopped = true; revoked(); return null; }
          if (u === vurl) {
            if (st === 'saved' || st === 'quota') play();
            // Si no se puede guardar aunque haya red, al menos se ve directo del servidor.
            else if (navigator.onLine && ++videoFails >= 3) play();
          }
          showInd();
          return next();
        });
      })().then(function () {
        running = false;
        showInd();
        if (!stopped && count() < urls.length) retry = setTimeout(sync, full ? 600000 : 60000);
      });
    }

    // Páginas abiertas mucho tiempo: renovar (o retirar) la autorización sin recargar.
    function check() {
      return ask({ type: 'check' }, 12000).then(function (r) {
        if (r && r.state === 'revoked') { stopped = true; revoked(); return false; }
        return true;
      });
    }

    if (video) box.classList.add('is-saving');
    window.addEventListener('load', function () {
      sw.register('/sw.js', { scope: '/' }).then(function () { return ready(15); }, function () { return false; }).then(function (ok) {
        if (!ok) { play(); setInd('bad', 'No se puede guardar contenido en este navegador'); return; }
        check().then(function (go) { if (go) sync(); });
        setInterval(check, 1800000);
        window.addEventListener('online', function () { check().then(function (go) { if (go) sync(); }); });
      });
    });
  }

  /** Indicador discreto de contenido guardado (para el montaje): punto arriba; al tocarlo, detalle. */
  function setInd(state, text) {
    if (preview || !document.body.hasAttribute('data-tablet')) return;
    var el = document.getElementById('save-ind');
    if (!el) {
      el = document.createElement('button');
      el.type = 'button';
      el.id = 'save-ind';
      el.appendChild(document.createElement('i'));
      el.appendChild(document.createElement('span'));
      var t = 0;
      el.addEventListener('click', function () {
        el.classList.add('open');
        clearTimeout(t);
        t = setTimeout(function () { el.classList.remove('open'); }, 5000);
      });
      document.body.appendChild(el);
    }
    el.setAttribute('data-state', state);
    el.setAttribute('aria-label', text);
    el.lastChild.textContent = text;
  }

  if (navigator.storage && navigator.storage.persist) {
    navigator.storage.persisted().then(function (p) { if (!p) navigator.storage.persist(); }).catch(function () {});
  }

  // Mantener la pantalla encendida en los tablets de la feria (requiere un toque del usuario).
  var lock = null;
  function wake() {
    if (!('wakeLock' in navigator) || lock) return;
    navigator.wakeLock.request('screen').then(function (l) {
      lock = l;
      l.addEventListener('release', function () { lock = null; });
    }).catch(function () {});
  }
  document.addEventListener('pointerdown', wake, { passive: true });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') wake(); });

  // Botones de pantalla completa (vídeo y modelo 3D). Se pone en pantalla completa la
  // sección entera para conservar el fondo (logo) y el propio botón para salir.
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest && ev.target.closest('[data-fullscreen]');
    if (!btn) return;
    var target = btn.closest('.fs-target');
    if (!target) return;
    var doc = document;
    var current = doc.fullscreenElement || doc.webkitFullscreenElement;
    if (current) {
      (doc.exitFullscreen || doc.webkitExitFullscreen).call(doc);
      return;
    }
    track(target.classList.contains('model3d') ? 'model_fs' : 'video_fs');
    var req = target.requestFullscreen || target.webkitRequestFullscreen;
    if (req) {
      var p = req.call(target);
      if (p && p.catch) p.catch(function () {});
      return;
    }
    // iPhone/iPad antiguos: sólo el vídeo nativo puede ir a pantalla completa.
    var v = target.querySelector('video');
    if (v && v.webkitEnterFullscreen) v.webkitEnterFullscreen();
  });

  // Newsletter: el contenido (iframe) se inserta al abrir el popup, así no se descarga
  // en cada visita, y se retira al cerrar.
  document.addEventListener('click', function (ev) {
    var dlg = document.getElementById('nl-dialog');
    var tpl = document.getElementById('nl-content');
    if (!dlg || !tpl || !ev.target.closest) return;
    if (ev.target.closest('[data-newsletter-open]')) {
      track('newsletter');
      var body = dlg.querySelector('.nl-body');
      body.replaceChildren(tpl.content.cloneNode(true));
      if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    } else if (ev.target.closest('[data-newsletter-close]') || ev.target === dlg) {
      dlg.close ? dlg.close() : dlg.removeAttribute('open');
    }
  });
  document.addEventListener('close', function (ev) {
    if (ev.target && ev.target.id === 'nl-dialog') ev.target.querySelector('.nl-body').replaceChildren();
  }, true);

  // Reanudar vídeo si el navegador lo pausa al volver a la pestaña.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') return;
    var v = document.querySelector('.pip video, .cvideo video');
    if (v && v.getAttribute('src') && v.paused) v.play().catch(function () {});
  });

  // ---------------------------------------------------------------------------
  // Estadísticas: cada interacción se guarda en una cola local (sobrevive a recargas y a
  // la falta de conexión) y se envía por lotes a /track.php. Cada registro lleva un id
  // único, así un reenvío nunca duplica.
  // ---------------------------------------------------------------------------
  var trackImpl = null;
  function track(type) { if (trackImpl) trackImpl(type); }

  function initStats(body) {
    var KEY = 'expo_stats_q';
    var MAX = 2000;
    var sending = false;
    function load() { try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; } }
    function save(q) { try { localStorage.setItem(KEY, JSON.stringify(q.slice(-MAX))); } catch (e) { /* lleno o bloqueado */ } }
    function rid() {
      var a = new Uint8Array(12);
      (window.crypto || window.msCrypto).getRandomValues(a);
      return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    function flush() {
      if (sending || !navigator.onLine) return;
      var q = load();
      if (!q.length) return;
      var batch = q.slice(0, 200);
      sending = true;
      fetch('/track.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ events: batch }),
      }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
        if (!j || !j.ok) return;
        var done = {};
        (j.ids || []).forEach(function (id) { done[id] = 1; });
        // Los rechazados por datos no válidos también se descartan para no reintentarlos siempre
        batch.forEach(function (e) { done[e.id] = 1; });
        save(load().filter(function (e) { return !done[e.id]; }));
        if (load().length) setTimeout(flush, 1000);
      }).catch(function () { /* sin conexión: se reintentará */ }).then(function () { sending = false; });
    }
    trackImpl = function (type) {
      var q = load();
      q.push({
        id: rid(), t: type,
        tab: parseInt(body.getAttribute('data-tablet') || '0', 10),
        p: parseInt(body.getAttribute('data-product') || '0', 10),
        ev: parseInt(body.getAttribute('data-event') || '0', 10) || null,
        l: body.getAttribute('data-lang') || '', ts: Date.now(),
      });
      save(q);
      clearTimeout(trackImpl.t);
      trackImpl.t = setTimeout(flush, 4000);
    };
    window.addEventListener('online', flush);
    setInterval(flush, 30000);
    setTimeout(flush, 1500);
  }

  var body = document.body;
  if (body && (body.classList.contains('showcase') || body.classList.contains('cards'))) {
    if (!preview) initStats(body);
    showcase(body);
  }

  // ---------------------------------------------------------------------------
  // Páginas de tablet (escaparate 3D y tarjetas): idioma, Más información, deslizar y reposo
  // ---------------------------------------------------------------------------
  function showcase(body) {
    var defLang = body.getAttribute('data-default-lang') || 'en';
    var panel = document.getElementById('info-panel');
    var more = document.querySelector('[data-more]');
    var bar = document.querySelector('.pbar');

    // Idioma: se guarda en una cookie para que el servidor sirva la página ya en ese
    // idioma al cambiar de producto (sin parpadeo). El idioma por defecto no se guarda.
    function setLangCookie(l) {
      var secure = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = 'expo_lang=' + (l || '') + '; path=/; SameSite=Lax' + secure + (l ? '; max-age=86400' : '; max-age=0');
    }
    function setLang(l) {
      if (!l) return;
      body.setAttribute('data-lang', l);
      document.documentElement.lang = l;
      setLangCookie(l === defLang ? '' : l);
    }
    document.documentElement.lang = body.getAttribute('data-lang') || defLang;

    // Altura de la barra de productos (para colocar encima Newsletter y, en Tarjetas, la bandera)
    function measure() { if (bar) body.style.setProperty('--bar-h', bar.offsetHeight + 'px'); }
    measure();
    window.addEventListener('resize', measure);

    // Panel "Más información"
    function openPanel() {
      if (!panel) return;
      panel.hidden = false;
      panel.scrollTop = 0;
      if (more) more.setAttribute('aria-expanded', 'true');
    }
    function closePanel() {
      if (!panel || panel.hidden) return;
      panel.hidden = true;
      if (more) more.setAttribute('aria-expanded', 'false');
    }
    document.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t.closest) return;
      var flag = t.closest('.flag-btn');
      if (flag) { setLang(flag.getAttribute('data-switch')); measure(); track('lang'); return; }
      if (t.closest('.pbar a, .sc-dots a')) { track('product'); return; }
      if (t.closest('[data-more]')) { openPanel(); track('more_info'); return; }
      if (t.closest('[data-more-close]')) { closePanel(); return; }
      // Tocar fuera del panel lo cierra
      if (panel && !panel.hidden && !panel.contains(t)) closePanel();
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') closePanel();
      if (panel && !panel.hidden) return;
      if (ev.key === 'ArrowRight') go(body.getAttribute('data-next'));
      if (ev.key === 'ArrowLeft') go(body.getAttribute('data-prev'));
    });

    // Deslizar el dedo: cambia al producto anterior / siguiente. Sobre el modelo 3D el
    // gesto lo gira; en vertical, deslizar el panel hacia abajo lo cierra.
    function go(url) { if (url) { track('swipe'); location.href = url; } }
    var sx = 0, sy = 0, st = 0, from = null;
    function start(x, y, target) {
      if (body.classList.contains('is-idle')) { from = null; return; }
      sx = x; sy = y; st = Date.now();
      from = target.closest('model-viewer, .nl-dialog') ? null : (target.closest('.info-panel') ? 'panel' : 'page');
    }
    function end(x, y) {
      if (!from) return;
      var dx = x - sx, dy = y - sy, quick = Date.now() - st < 900;
      if (from === 'panel') {
        if (quick && dy > 90 && Math.abs(dy) > Math.abs(dx) * 1.5 && panel.scrollTop <= 0) closePanel();
      } else if (quick && Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.5 && (!panel || panel.hidden)) {
        go(dx < 0 ? body.getAttribute('data-next') : body.getAttribute('data-prev'));
      }
      from = null;
    }
    document.addEventListener('touchstart', function (ev) {
      if (ev.touches.length === 1) start(ev.touches[0].clientX, ev.touches[0].clientY, ev.target); else from = null;
    }, { passive: true });
    document.addEventListener('touchend', function (ev) {
      var t = ev.changedTouches[0];
      if (t) end(t.clientX, t.clientY);
    }, { passive: true });
    document.addEventListener('pointerdown', function (ev) { if (ev.pointerType === 'mouse') start(ev.clientX, ev.clientY, ev.target); });
    document.addEventListener('pointerup', function (ev) { if (ev.pointerType === 'mouse') end(ev.clientX, ev.clientY); });

    // Modo reposo: sin tocar la pantalla durante "data-idle" segundos se vuelve al producto
    // por defecto, en el idioma por defecto, sólo con el 3D girando.
    var idleSec = preview ? 0 : parseInt(body.getAttribute('data-idle') || '0', 10);
    var timer = 0;
    function enterIdle() {
      closePanel();
      var dlg = document.getElementById('nl-dialog');
      if (dlg && dlg.open && dlg.close) dlg.close();
      setLang(defLang);
      body.classList.add('is-idle');
      window.scrollTo(0, 0); // diseño Tarjetas: volver arriba
    }
    function exitIdle() {
      track('wake');
      body.classList.remove('is-idle');
      measure();
    }
    function fire() {
      // No interrumpir un vídeo que alguien está viendo a pantalla completa
      if (document.fullscreenElement || document.webkitFullscreenElement) { reset(); return; }
      if (body.getAttribute('data-is-home') === '1') {
        enterIdle();
      } else {
        try { sessionStorage.setItem('expo_idle', '1'); } catch (e) { /* sin almacenamiento */ }
        setLangCookie('');
        location.href = body.getAttribute('data-home');
      }
    }
    function reset() {
      clearTimeout(timer);
      if (idleSec > 0) timer = setTimeout(fire, idleSec * 1000);
    }
    ['pointerdown', 'touchstart', 'keydown', 'wheel'].forEach(function (type) {
      document.addEventListener(type, function () {
        if (body.classList.contains('is-idle')) exitIdle();
        reset();
      }, { passive: true, capture: true });
    });
    var enteredIdle = false, rotated = false;
    try {
      rotated = sessionStorage.getItem('expo_rot') === '1';
      sessionStorage.removeItem('expo_rot');
      if (sessionStorage.getItem('expo_idle') === '1') {
        sessionStorage.removeItem('expo_idle');
        enterIdle();
        enteredIdle = true;
      }
    } catch (e) { /* sin almacenamiento */ }
    // Vista del producto (no cuentan la vuelta automática del modo reposo ni girar el tablet)
    if (!enteredIdle && !rotated) track('view');
    // Primer toque al modelo 3D en esta página (para girarlo)
    var mv = document.querySelector('.model3d model-viewer');
    if (mv) {
      mv.addEventListener('pointerdown', function once() {
        mv.removeEventListener('pointerdown', once);
        track('model_touch');
      });
    }
    reset();
  }
})();
