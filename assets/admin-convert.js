/*
 * Conversión de modelos 3D a GLB en el navegador del administrador.
 * - STEP/STP: occt-import-js (OpenCascade compilado a WebAssembly, ~7,7 MB, sólo se descarga en el panel)
 * - OBJ (+MTL con el mismo nombre si existe), glTF: three.js
 * Los tablets sólo descargan el GLB resultante.
 */
import * as THREE from 'three';
import { GLTFExporter } from '/assets/vendor/three/exporters/GLTFExporter.js';
import { GLTFLoader } from '/assets/vendor/three/loaders/GLTFLoader.js';
import { OBJLoader } from '/assets/vendor/three/loaders/OBJLoader.js';
import { MTLLoader } from '/assets/vendor/three/loaders/MTLLoader.js';

const DEFAULT_MATERIAL = () => new THREE.MeshStandardMaterial({ color: 0xb4b9be, metalness: 0.55, roughness: 0.38 });

let occtPromise = null;
function loadOcct() {
  if (!occtPromise) {
    occtPromise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = '/assets/vendor/occt/occt-import-js.js';
      s.onload = () => {
        // eslint-disable-next-line no-undef
        if (typeof occtimportjs !== 'function') { reject(new Error('occt-import-js no disponible')); return; }
        // eslint-disable-next-line no-undef
        occtimportjs({ locateFile: (f) => '/assets/vendor/occt/' + f }).then(resolve, reject);
      };
      s.onerror = () => reject(new Error('No se pudo cargar el conversor STEP'));
      document.head.append(s);
    });
  }
  return occtPromise;
}

async function fetchBuffer(url) {
  const res = await fetch(url, { credentials: 'same-origin' });
  if (!res.ok) throw new Error('No se pudo descargar el original (' + res.status + ')');
  return res.arrayBuffer();
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

async function stepToScene(url, progress) {
  progress('cargando conversor STEP…');
  const occt = await loadOcct();
  progress('descargando STEP…');
  const buf = new Uint8Array(await fetchBuffer(url));
  progress('triangulando geometría (puede tardar)…');
  await new Promise((r) => setTimeout(r, 30));
  const res = occt.ReadStepFile(buf, { linearUnit: 'meter', linearDeflectionType: 'bounding_box_ratio', linearDeflection: 0.001, angularDeflection: 0.5 });
  if (!res || !res.success || !res.meshes || !res.meshes.length) throw new Error('El fichero STEP no contiene geometría legible');
  const scene = new THREE.Group();
  const mats = new Map();
  for (const m of res.meshes) {
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(m.attributes.position.array, 3));
    if (m.attributes.normal) g.setAttribute('normal', new THREE.Float32BufferAttribute(m.attributes.normal.array, 3));
    if (m.index) g.setIndex(new THREE.Uint32BufferAttribute(m.index.array, 1));
    if (!m.attributes.normal) g.computeVertexNormals();
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

async function objToScene(url, progress) {
  progress('descargando OBJ…');
  const text = new TextDecoder().decode(await fetchBuffer(url));
  const loader = new OBJLoader();
  const base = url.split('?')[0];
  const mtlUrl = base.replace(/\.obj$/i, '.mtl');
  let hasMtl = false;
  try {
    const r = await fetch(mtlUrl, { credentials: 'same-origin' });
    if (r.ok) {
      const mtl = new MTLLoader().setResourcePath(base.slice(0, base.lastIndexOf('/') + 1)).parse(await r.text(), '');
      mtl.preload();
      loader.setMaterials(mtl);
      hasMtl = true;
    }
  } catch (_) { /* sin MTL */ }
  progress('procesando OBJ…');
  const obj = loader.parse(text);
  const fallback = DEFAULT_MATERIAL();
  obj.traverse((o) => {
    if (o.isMesh) {
      if (!hasMtl || !o.material) o.material = fallback;
      if (!o.geometry.attributes.normal) o.geometry.computeVertexNormals();
    }
  });
  return obj;
}

async function gltfToScene(url, progress) {
  progress('cargando glTF…');
  const gltf = await new GLTFLoader().loadAsync(url.split('?')[0]);
  return gltf.scene;
}

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

export async function convertToGlb(url, ext, progress = () => {}) {
  ext = (ext || '').toLowerCase();
  let root;
  if (ext === 'stp' || ext === 'step') root = await stepToScene(url, progress);
  else if (ext === 'obj') root = await objToScene(url, progress);
  else if (ext === 'gltf' || ext === 'glb') root = await gltfToScene(url, progress);
  else throw new Error('Formato no soportado: ' + ext);
  const scene = normalize(root);
  progress('generando GLB…');
  const exporter = new GLTFExporter();
  const glb = await exporter.parseAsync(scene, { binary: true, onlyVisible: true, maxTextureSize: 2048 });
  return new Blob([glb], { type: 'model/gltf-binary' });
}
