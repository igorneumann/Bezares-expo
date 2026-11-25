/*
 * Worker de conversión STEP → mallas (occt-import-js / OpenCascade en WebAssembly).
 * Se ejecuta aparte porque la librería genera funciones en tiempo de ejecución
 * (embind usa el constructor Function), algo que la CSP del panel no permite.
 * Este fichero se sirve con su propia CSP (ver app/htaccess.template).
 */
/* global occtimportjs */
let occtPromise = null;

function loadOcct() {
  if (!occtPromise) {
    importScripts('/assets/vendor/occt/occt-import-js.js');
    occtPromise = occtimportjs({ locateFile: (f) => '/assets/vendor/occt/' + f });
  }
  return occtPromise;
}

self.onmessage = async (e) => {
  const { id, buffer } = e.data || {};
  try {
    const occt = await loadOcct();
    self.postMessage({ id, progress: 'triangulando geometría (puede tardar)…' });
    const res = occt.ReadStepFile(new Uint8Array(buffer), {
      linearUnit: 'meter', linearDeflectionType: 'bounding_box_ratio', linearDeflection: 0.001, angularDeflection: 0.5,
    });
    if (!res || !res.success || !res.meshes || !res.meshes.length) {
      throw new Error('El fichero STEP no contiene geometría legible');
    }
    const transfer = [];
    const meshes = res.meshes.map((m) => {
      const position = new Float32Array(m.attributes.position.array);
      const normal = m.attributes.normal ? new Float32Array(m.attributes.normal.array) : null;
      const index = m.index ? new Uint32Array(m.index.array) : null;
      transfer.push(position.buffer);
      if (normal) transfer.push(normal.buffer);
      if (index) transfer.push(index.buffer);
      return { name: m.name || '', color: m.color || null, brep_faces: m.brep_faces || [], position, normal, index };
    });
    self.postMessage({ id, ok: true, meshes }, transfer);
  } catch (err) {
    self.postMessage({ id, ok: false, error: (err && err.message) || String(err) });
  }
};
