/*
 * Conversión de modelos 3D a GLB en el navegador del administrador.
 * - STEP/STP: occt-import-js (OpenCascade en WebAssembly) dentro de un Web Worker
 *   (assets/occt-worker.js), que tiene su propia CSP.
 * - OBJ (+MTL y texturas), glTF (+.bin y texturas): three.js.
 *   · convertFilesToGlb(): desde los ficheros locales seleccionados (subida desde el panel).
 *   · convertToGlb(): desde un fichero ya en el servidor (p. ej. subido por FTP).
 * Los tablets sólo descargan el GLB resultante.
 */
import * as THREE from 'three';
import { GLTFExporter } from '/assets/vendor/three/exporters/GLTFExporter.js';
import { GLTFLoader } from '/assets/vendor/three/loaders/GLTFLoader.js';
import { OBJLoader } from '/assets/vendor/three/loaders/OBJLoader.js';
import { MTLLoader } from '/assets/vendor/three/loaders/MTLLoader.js';

const VERSION_QS = new URL(import.meta.url).search;
const DEFAULT_MATERIAL = () => new THREE.MeshStandardMaterial({ color: 0xb4b9be, metalness: 0.55, roughness: 0.38 });
const TEXTURE_KEYS = ['map', 'normalMap', 'roughnessMap', 'metalnessMap', 'aoMap', 'emissiveMap', 'bumpMap',
  'alphaMap', 'specularMap', 'lightMap', 'displacementMap', 'envMap'];

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------
async function fetchBuffer(url) {
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error('No se pudo descargar el original (' + res.status + ')');
  return res.arrayBuffer();
}

const baseName = (url) => decodeURIComponent(String(url).split('?')[0].split('#')[0].split(/[\\/]/).pop() || '').toLowerCase();

/**
 * LoadingManager que (opcionalmente) resuelve los recursos por nombre de fichero contra
 * los ficheros locales seleccionados y registra los que no se encuentran.
 */
function makeManager(localFiles) {
  const manager = new THREE.LoadingManager();
  const missing = new Set();
  const blobUrls = [];
  let started = false;
  if (localFiles) {
    const map = new Map();
    for (const f of localFiles) {
      const u = URL.createObjectURL(f);
      blobUrls.push(u);
      map.set(f.name.toLowerCase(), u);
    }
    manager.setURLModifier((url) => {
      if (/^(data|blob):/i.test(url)) return url;
      const hit = map.get(baseName(url));
      if (hit) return hit;
      missing.add(baseName(url));
      return url;
    });
  }
  manager.onStart = () => { started = true; };
  manager.onError = (url) => { if (!/^(data|blob):/i.test(url)) missing.add(baseName(url)); };
  /** Espera a que terminen las cargas pendientes (texturas…), con límite de tiempo. */
  const settle = (ms = 30000) => new Promise((resolve) => {
    if (!started) { resolve(); return; }
    const t = setTimeout(resolve, ms);
    manager.onLoad = () => { clearTimeout(t); resolve(); };
  });
  const dispose = () => blobUrls.forEach((u) => URL.revokeObjectURL(u));
  return { manager, missing, settle, dispose };
}

function validImage(img) {
  if (!img) return false;
  if (typeof HTMLImageElement !== 'undefined' && img instanceof HTMLImageElement) return img.complete && img.naturalWidth > 0;
  return (img.width || 0) > 0 && (img.height || 0) > 0;
}

/** Quita las texturas sin imagen válida (evita "No valid image data found" del exportador). */
function dropInvalidTextures(root) {
  const dropped = new Set();
  root.traverse((o) => {
    if (!o.isMesh) return;
    for (const mat of Array.isArray(o.material) ? o.material : [o.material]) {
      if (!mat) continue;
      for (const k of TEXTURE_KEYS) {
        const tex = mat[k];
        if (tex && tex.isTexture && !validImage(tex.image)) {
          dropped.add((tex.name || (tex.image && tex.image.src) || k).toString().split('/').pop());
          mat[k] = null;
          mat.needsUpdate = true;
        }
      }
    }
  });
  return [...dropped];
}

function missingError(missing) {
  const list = [...missing].filter(Boolean);
  return new Error('Faltan ficheros que usa el modelo: ' + list.join(', ')
    + '. Desde el panel, selecciona el modelo junto con TODOS sus ficheros (.bin, .mtl, texturas) a la vez; '
    + 'por FTP, súbelos a la misma carpeta que el modelo.');
}

// ---------------------------------------------------------------------------
// STEP (en el worker)
// ---------------------------------------------------------------------------
let worker = null;
let reqId = 0;
function stepWorker() {
  if (!worker) worker = new Worker('/assets/occt-worker.js' + VERSION_QS);
  return worker;
}

function stepMeshes(buffer, progress) {
  const w = stepWorker();
  const id = ++reqId;
  return new Promise((resolve, reject) => {
    const onMsg = (e) => {
      const d = e.data || {};
      if (d.id !== id) return;
      if (d.progress) { progress(d.progress); return; }
      w.removeEventListener('message', onMsg);
      w.removeEventListener('error', onErr);
      d.ok ? resolve(d.meshes) : reject(new Error(d.error || 'Error en el conversor STEP'));
    };
    const onErr = (ev) => {
      w.removeEventListener('message', onMsg);
      w.removeEventListener('error', onErr);
      worker = null;
      reject(new Error('El conversor STEP ha fallado: ' + (ev.message || 'error desconocido')));
    };
    w.addEventListener('message', onMsg);
    w.addEventListener('error', onErr);
    w.postMessage({ id, buffer }, [buffer]);
  });
}

function colorKey(c) { return c ? c.map((x) => Math.round(x * 255)).join(',') : 'default'; }
function materialFor(cache, color) {
  const key = colorKey(color);
  if (!cache.has(key)) {
    const m = DEFAULT_MATERIAL();
    if (color) m.color.setRGB(color[0], color[1], color[2], THREE.SRGBColorSpace);
    cache.set(key, m);
  }
  return cache.get(key);
}

async function stepToScene(buffer, progress) {
  progress('cargando conversor STEP…');
  const meshes = await stepMeshes(buffer, progress);
  const scene = new THREE.Group();
  const mats = new Map();
  for (const m of meshes) {
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.BufferAttribute(m.position, 3));
    if (m.normal) g.setAttribute('normal', new THREE.BufferAttribute(m.normal, 3));
    if (m.index) g.setIndex(new THREE.BufferAttribute(m.index, 1));
    if (!m.normal) g.computeVertexNormals();
    let material;
    const faces = m.brep_faces || [];
    if (faces.some((f) => f.color)) {
      const list = [];
      const idx = new Map();
      for (const f of faces) {
        const mat = materialFor(mats, f.color || m.color);
        if (!idx.has(mat)) { idx.set(mat, list.length); list.push(mat); }
        g.addGroup(f.first * 3, (f.last - f.first + 1) * 3, idx.get(mat));
      }
      material = list;
    } else {
      material = materialFor(mats, m.color);
    }
    const mesh = new THREE.Mesh(g, material);
    mesh.name = m.name || 'part';
    scene.add(mesh);
  }
  return scene;
}

// ---------------------------------------------------------------------------
// OBJ
// ---------------------------------------------------------------------------
async function objToScene(objText, mtlText, resourcePath, ctx, progress) {
  const loader = new OBJLoader(ctx.manager);
  let hasMtl = false;
  if (mtlText) {
    const mtl = new MTLLoader(ctx.manager).setResourcePath(resourcePath).parse(mtlText, '');
    mtl.preload();
    loader.setMaterials(mtl);
    hasMtl = true;
    progress('cargando texturas…');
    await ctx.settle();
  }
  progress('procesando OBJ…');
  const obj = loader.parse(objText);
  const fallback = DEFAULT_MATERIAL();
  obj.traverse((o) => {
    if (o.isMesh) {
      if (!hasMtl || !o.material) o.material = fallback;
      if (!o.geometry.attributes.normal) o.geometry.computeVertexNormals();
    }
  });
  return obj;
}

/** Nombre del .mtl declarado en el OBJ (mtllib). */
const mtllibName = (objText) => {
  const m = /^\s*mtllib\s+(.+?)\s*$/m.exec(objText);
  return m ? baseName(m[1]) : '';
};

// ---------------------------------------------------------------------------
// glTF
// ---------------------------------------------------------------------------
function gltfParse(data, path, ctx) {
  return new Promise((resolve, reject) => {
    new GLTFLoader(ctx.manager).parse(data, path, (g) => resolve(g.scene), (err) => {
      reject(ctx.missing.size ? missingError(ctx.missing) : new Error((err && err.message) || 'glTF no válido'));
    });
  });
}

// ---------------------------------------------------------------------------
// Exportación común
// ---------------------------------------------------------------------------
/** Centra el modelo en el origen y lo apoya en el suelo (y=0). */
function normalize(root) {
  const box = new THREE.Box3().setFromObject(root);
  if (box.isEmpty()) throw new Error('Modelo vacío');
  const center = box.getCenter(new THREE.Vector3());
  const wrapper = new THREE.Group();
  root.position.sub(new THREE.Vector3(center.x, box.min.y, center.z));
  wrapper.add(root);
  return wrapper;
}

async function exportGlb(root, progress) {
  const dropped = dropInvalidTextures(root);
  if (dropped.length) progress('aviso: texturas no disponibles omitidas (' + dropped.join(', ') + ')');
  const scene = normalize(root);
  progress('generando GLB…');
  const glb = await new GLTFExporter().parseAsync(scene, { binary: true, onlyVisible: true, maxTextureSize: 2048 });
  return new Blob([glb], { type: 'model/gltf-binary' });
}

// ---------------------------------------------------------------------------
// API pública
// ---------------------------------------------------------------------------

/** Convierte un modelo que ya está en el servidor (URL). */
export async function convertToGlb(url, ext, progress = () => {}) {
  ext = (ext || '').toLowerCase();
  const clean = url.split('?')[0];
  const dir = clean.slice(0, clean.lastIndexOf('/') + 1);
  if (ext === 'stp' || ext === 'step') {
    progress('descargando STEP…');
    return exportGlb(await stepToScene(await fetchBuffer(url), progress), progress);
  }
  const ctx = makeManager(null);
  try {
    if (ext === 'obj') {
      progress('descargando OBJ…');
      const text = new TextDecoder().decode(await fetchBuffer(url));
      let mtlText = '';
      const declared = mtllibName(text);
      for (const cand of [declared, baseName(clean).replace(/\.obj$/i, '.mtl')].filter(Boolean)) {
        try {
          const r = await fetch(dir + encodeURIComponent(cand), { credentials: 'same-origin' });
          if (r.ok) { mtlText = await r.text(); break; }
        } catch (_) { /* sin MTL */ }
      }
      const root = await objToScene(text, mtlText, dir, ctx, progress);
      return exportGlb(root, progress);
    }
    if (ext === 'gltf' || ext === 'glb') {
      progress('cargando glTF…');
      const data = ext === 'glb' ? await fetchBuffer(url) : new TextDecoder().decode(await fetchBuffer(url));
      const root = await gltfParse(data, dir, ctx);
      return exportGlb(root, progress);
    }
    throw new Error('Formato no soportado: ' + ext);
  } finally {
    ctx.dispose();
  }
}

/**
 * Convierte desde ficheros locales: el modelo principal (.gltf/.obj/.stp/.glb) y sus
 * ficheros asociados seleccionados a la vez (.bin, .mtl, texturas).
 */
export async function convertFilesToGlb(mainFile, companions = [], progress = () => {}) {
  const ext = mainFile.name.split('.').pop().toLowerCase();
  if (ext === 'stp' || ext === 'step') {
    return exportGlb(await stepToScene(await mainFile.arrayBuffer(), progress), progress);
  }
  const ctx = makeManager(companions);
  try {
    if (ext === 'obj') {
      const text = await mainFile.text();
      const want = [mtllibName(text), mainFile.name.replace(/\.obj$/i, '.mtl').toLowerCase()].filter(Boolean);
      const mtlFile = companions.find((f) => want.includes(f.name.toLowerCase()))
        || companions.find((f) => /\.mtl$/i.test(f.name));
      const root = await objToScene(text, mtlFile ? await mtlFile.text() : '', '', ctx, progress);
      if (ctx.missing.size) progress('aviso: faltan ' + [...ctx.missing].join(', '));
      return exportGlb(root, progress);
    }
    if (ext === 'gltf' || ext === 'glb') {
      progress('procesando glTF…');
      const data = ext === 'glb' ? await mainFile.arrayBuffer() : await mainFile.text();
      const root = await gltfParse(data, '', ctx);
      return exportGlb(root, progress);
    }
    throw new Error('Formato no soportado: ' + ext);
  } finally {
    ctx.dispose();
  }
}
