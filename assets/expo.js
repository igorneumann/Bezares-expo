/* Script público: registra el service worker, precarga el contenido del tablet y mantiene la pantalla encendida. */
(function () {
  'use strict';

  function prefetchList() {
    var el = document.getElementById('expo-prefetch');
    if (!el) return [];
    try { return JSON.parse(el.textContent || '[]'); } catch (e) { return []; }
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function () {
        return navigator.serviceWorker.ready;
      }).then(function (reg) {
        var urls = prefetchList();
        if (!urls.length || !reg.active) return;
        var send = function () { reg.active.postMessage({ type: 'prefetch', urls: urls }); };
        // Esperar a que la página termine de cargar sus propios recursos.
        if ('requestIdleCallback' in window) requestIdleCallback(send, { timeout: 5000 }); else setTimeout(send, 2000);
      }).catch(function () {});
    });
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

  // Reanudar vídeo si el navegador lo pausa al volver a la pestaña.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') return;
    var v = document.querySelector('.video video');
    if (v && v.paused) v.play().catch(function () {});
  });
})();
