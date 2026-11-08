// Interactive 3D hero: the real W204 (2008-2011 pre-facelift) C-Class sedan mesh, blacked out with
// tinted glass, AMG Sport front bumper, black sport grille with the big star, AMG twin-spoke wheels.
// Model: "Mercedes-Benz C-Class (W204)" by ZapupaNekra, CC BY 4.0 (credit shown in the hero), converted
// with model-src/ (see assets/dev/README-model.md). Issue hotspots + X-ray view of the real drivetrain parts.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';

const MODEL_URL = new URL((document.getElementById('car3d')?.dataset.model || 'models/car.glb') + '?v=2', import.meta.url).href;
const MODEL_LEN = 4.795, REAL_LEN = 4.58; // GLB length -> real W204 sedan length (m)
const CREDIT = '3D model: <a href="https://sketchfab.com/3d-models/mercedes-benz-c-class-w204-eto-pizdec-335b937fe4a049fe9887598a95f92d0e" target="_blank" rel="noopener">W204 C-Class</a> by <a href="https://sketchfab.com/ZapupaNekra" target="_blank" rel="noopener">ZapupaNekra</a>, <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a>';

const host = document.getElementById('car3d');
if (host) init(host);

function init(host) {
  const hero = host.closest('.hero');
  const label = hero.querySelector('.hs-label');
  const panel = hero.querySelector('.hotspot-panel');
  const data = window.GARAGE_HOTSPOTS || [];
  const SEV = { 1: 0x6b7280, 2: 0x3b82f6, 3: 0xf59e0b, 4: 0xf97316, 5: 0xef4444 };

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.toneMapping = THREE.ACESFilmicToneMapping; renderer.toneMappingExposure = 1.15;
  host.appendChild(renderer.domElement);

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(0x07080a, 10, 22);
  const pmrem = new THREE.PMREMGenerator(renderer);
  scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture; pmrem.dispose();

  const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 100);
  const HOME = new THREE.Vector3(4.3, 1.45, 4.7);
  camera.position.copy(HOME);
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.target.set(0, 0.55, 0);
  controls.enableDamping = true; controls.dampingFactor = 0.06;
  controls.minDistance = 3.6; controls.maxDistance = 12;
  controls.maxPolarAngle = Math.PI / 2 - 0.03; controls.minPolarAngle = 0.2;
  controls.autoRotate = !window.CAR3D_STATIC; controls.autoRotateSpeed = 0.6; controls.enablePan = false;
  // On phones let vertical swipes scroll the page; horizontal drags still orbit.
  renderer.domElement.style.touchAction = 'pan-y';
  // A plain mouse wheel scrolls the page past the car; hold Ctrl/⌘ (or pinch on a trackpad) to zoom.
  renderer.domElement.parentElement.addEventListener('wheel', (e) => { if (!e.ctrlKey && !e.metaKey) e.stopPropagation(); }, { capture: true });
  // Harness / deep-link camera presets (window.CAR3D_VIEW). +X is the front of the car, -Z the driver side.
  const VIEWS = { front34: [5.0, 1.5, 4.4], side: [0.01, 1.0, 8.6], rear34: [-5.0, 1.6, 4.6], front: [8.2, 1.2, 0.01], rear: [-8.2, 1.3, 0.01], top: [0.01, 9.5, 0.01], hero: [4.3, 1.45, 4.7], low: [4.2, 0.45, 3.6], driver34: [4.6, 1.5, -4.6], nose: [5.2, 0.75, 0.01] };
  if (window.CAR3D_VIEW && VIEWS[window.CAR3D_VIEW]) { camera.position.set(...VIEWS[window.CAR3D_VIEW]); if (window.CAR3D_VIEW === 'nose') { controls.minDistance = 1; controls.target.set(2.0, 0.55, 0); } controls.update(); }
  renderer.domElement.addEventListener('pointerdown', () => { controls.autoRotate = false; });

  scene.add(new THREE.HemisphereLight(0xb7c6e6, 0x07080c, 0.6));
  const key = new THREE.DirectionalLight(0xffffff, 2.6); key.position.set(4, 7, 3); scene.add(key);
  const rim = new THREE.DirectionalLight(0x8fb5ff, 2.0); rim.position.set(-6, 3, -4); scene.add(rim);
  const side = new THREE.DirectionalLight(0xffffff, 1.1); side.position.set(1, 2.5, 7); scene.add(side);
  const redGlow = new THREE.PointLight(0xe10600, 4, 6, 2); redGlow.position.set(-3.4, 0.5, 0); scene.add(redGlow);

  const floor = new THREE.Mesh(new THREE.CircleGeometry(9, 64), new THREE.MeshStandardMaterial({ color: 0x0b0d12, roughness: 0.78, metalness: 0.1 }));
  floor.rotation.x = -Math.PI / 2; scene.add(floor);
  const grid = new THREE.GridHelper(18, 36, 0x1b2130, 0x141821); grid.position.y = 0.002; grid.material.transparent = true; grid.material.opacity = 0.45; scene.add(grid);
  const ring = new THREE.Mesh(new THREE.RingGeometry(3.4, 3.46, 96), new THREE.MeshBasicMaterial({ color: 0xe10600, transparent: true, opacity: 0.35, side: THREE.DoubleSide }));
  ring.rotation.x = -Math.PI / 2; ring.position.y = 0.004; scene.add(ring);
  // Soft contact shadow (cheap, works on phones; no shadow maps)
  const sc = document.createElement('canvas'); sc.width = sc.height = 128; const sg = sc.getContext('2d');
  const grad = sg.createRadialGradient(64, 64, 4, 64, 64, 64); grad.addColorStop(0, 'rgba(0,0,0,0.85)'); grad.addColorStop(0.6, 'rgba(0,0,0,0.45)'); grad.addColorStop(1, 'rgba(0,0,0,0)'); sg.fillStyle = grad; sg.fillRect(0, 0, 128, 128);
  const shadow = new THREE.Mesh(new THREE.PlaneGeometry(5.4, 2.5), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(sc), transparent: true, depthWrite: false }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = 0.006; scene.add(shadow);

  // Everything car-related lives in model coordinates inside this scaled group.
  const car = new THREE.Group(); car.scale.setScalar(REAL_LEN / MODEL_LEN); scene.add(car);
  const status = document.createElement('div'); status.className = 'car3d-status'; status.textContent = 'Loading the 3D car…';
  status.style.cssText = 'position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);color:#8d95a9;font-size:12px;letter-spacing:.12em;text-transform:uppercase;pointer-events:none';
  hero.appendChild(status);
  const credit = document.createElement('div'); credit.className = 'car3d-credit'; credit.innerHTML = CREDIT;
  credit.style.cssText = 'position:absolute;left:50%;bottom:4px;transform:translateX(-50%);font-size:10px;color:#5b6270;white-space:nowrap;opacity:.8';
  credit.querySelectorAll('a').forEach(a => { a.style.color = 'inherit'; });
  hero.appendChild(credit);

  // X-ray: real drivetrain parts from the model, plus a transmission (the model has none) in orange.
  const XCOL = { mech_engine: 0x3b82f6, mech_cooling: 0x06b6d4, mech_exhaust: 0xfbbf24, mech_driveline: 0xa78bfa, mech_susp_f: 0x94a3b8, mech_susp_r: 0x94a3b8, mech_fuel: 0x22c55e, underbody: 0x334155 };
  const xmat = c => new THREE.MeshStandardMaterial({ color: c, emissive: c, emissiveIntensity: 0.45, roughness: 0.55, metalness: 0.2 });
  const xray = new THREE.Group(); xray.visible = false; car.add(xray);
  const trans = new THREE.Mesh(new THREE.CylinderGeometry(0.17, 0.23, 0.78, 20), xmat(0xf97316)); trans.rotation.z = Math.PI / 2; trans.position.set(0.86, 0.42, 0); xray.add(trans);
  const pan = new THREE.Mesh(new THREE.BoxGeometry(0.5, 0.05, 0.36), xmat(0xef4444)); pan.position.set(0.82, 0.24, 0); xray.add(pan); // the leaking pan
  const battery = new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.18, 0.18), xmat(0xe5e7eb)); battery.position.set(1.25, 0.86, 0.55); xray.add(battery);

  // Honeycomb mesh behind the grille slats and in the lower intake (the owner's car has both).
  const hc = document.createElement('canvas'); hc.width = 128; hc.height = 74; const hg = hc.getContext('2d');
  hg.strokeStyle = '#fff'; hg.lineWidth = 7; const hex = (cx, cy, r) => { hg.beginPath(); for (let i = 0; i < 6; i++) { const a = Math.PI / 3 * i + Math.PI / 6; hg[i ? 'lineTo' : 'moveTo'](cx + r * Math.cos(a), cy + r * Math.sin(a)); } hg.closePath(); hg.stroke(); };
  for (const [cx, cy] of [[32, 0], [96, 0], [0, 37], [64, 37], [128, 37], [32, 74], [96, 74]]) hex(cx, cy, 21.3);
  const honey = (w, h, cells) => { const t = new THREE.CanvasTexture(hc); t.wrapS = t.wrapT = THREE.RepeatWrapping; t.repeat.set(cells, cells * (h / w) * (128 / 74)); t.anisotropy = 4;
    const mt = new THREE.MeshStandardMaterial({ color: 0x4a4f58, metalness: 0.75, roughness: 0.3, alphaMap: t, transparent: true, alphaTest: 0.5, side: THREE.DoubleSide });
    const back = new THREE.MeshBasicMaterial({ color: 0x020203, side: THREE.DoubleSide });
    const g = new THREE.Group(); const m = new THREE.Mesh(new THREE.PlaneGeometry(w, h), mt); const b = new THREE.Mesh(new THREE.PlaneGeometry(w, h), back); b.position.z = -0.03; g.add(b, m); g.rotation.y = Math.PI / 2; return g; };
  const MESH = { grille: { x: 2.275, y: 0.69, w: 0.86, h: 0.2, cells: 20 }, intake: { x: 2.315, y: 0.37, w: 0.8, h: 0.17, cells: 19 } };
  // Black front splitter lip (aftermarket, on the owner's car)
  const lipShape = new THREE.Shape(); lipShape.moveTo(-0.66, -0.1); lipShape.lineTo(0.66, -0.1); lipShape.lineTo(0.66, -0.03); lipShape.quadraticCurveTo(0, 0.1, -0.66, -0.03); lipShape.closePath();
  const lipGeo = new THREE.ExtrudeGeometry(lipShape, { depth: 0.022, bevelEnabled: true, bevelThickness: 0.006, bevelSize: 0.006, bevelSegments: 2 });
  const lipM = new THREE.Mesh(lipGeo, new THREE.MeshStandardMaterial({ color: 0x0a0a0c, metalness: 0.5, roughness: 0.25 }));
  lipGeo.rotateX(-Math.PI / 2); lipGeo.rotateY(-Math.PI / 2); lipM.position.set(2.3, 0.19, 0); lipM.name = 'front_lip'; car.add(lipM);
  for (const k of Object.keys(MESH)) { const d = MESH[k]; const g = honey(d.w, d.h, d.cells); g.position.set(d.x, d.y, 0); g.name = 'honeycomb_' + k; car.add(g); }
  let body = [], mech = [], paintMat = null;
  new GLTFLoader().load(MODEL_URL, g => {
    const m = g.scene; car.add(m); status.remove();
    m.traverse(o => {
      if (!o.isMesh) return;
      const group = (o.name.split('|')[0] || '').replace(/_\d+$/, '');
      if (XCOL[group] !== undefined) { o.userData.xmat = xmat(XCOL[group]); o.userData.base = o.material; o.visible = false; mech.push(o); return; }
      const mats = Array.isArray(o.material) ? o.material : [o.material];
      mats.forEach(mt => {
        if (mt.name === 'paint' && !paintMat) { paintMat = new THREE.MeshPhysicalMaterial({ color: 0x07070a, metalness: 0.55, roughness: 0.28, clearcoat: 1, clearcoatRoughness: 0.04, envMapIntensity: 1.5 }); paintMat.name = 'paint'; }
        mt.userData.op = mt.opacity; mt.userData.tr = mt.transparent;
      });
      if (Array.isArray(o.material)) o.material = o.material.map(mt => mt.name === 'paint' ? paintMat : mt); else if (o.material.name === 'paint') o.material = paintMat;
      body.push(o);
    });
    if (paintMat) { paintMat.userData.op = 1; paintMat.userData.tr = false; }
    setXray(!!window.CAR3D_XRAY);
  }, undefined, err => { status.textContent = '3D model failed to load'; console.error(err); });

  // Hotspots, in model coordinates (front +X, driver side -Z, passenger side +Z).
  const HS = {
    engine: [1.62, 1.12, -0.12], intake: [1.55, 1.12, 0.26], transmission: [0.86, 0.3, 0], driveline: [-0.35, 0.34, 0], exhaust: [-0.6, 0.28, 0.42],
    evap: [-1.15, 0.36, -0.5], cooling: [2.0, 0.86, -0.38], fan: [1.95, 0.84, 0.36], ac: [2.25, 0.55, 0.0], electrical: [1.25, 1.06, 0.55],
    cabin: [-0.25, 1.32, 0], doors: [0.2, 0.86, 0.95], suspension_front: [1.58, 0.66, 0.78], suspension_rear: [-1.24, 0.66, 0.78],
    brakes: [1.58, 0.32, -0.98], wheels: [-1.24, 0.32, -0.98], body: [0.7, 0.98, -0.96], other: [0, 1.72, 0],
  };
  const spots = []; const ray = new THREE.Raycaster(); const mouse = new THREE.Vector2();
  data.forEach(d => {
    const pos = HS[d.loc] || HS.other; const color = SEV[d.sev] || SEV[2];
    const m = new THREE.Mesh(new THREE.SphereGeometry(0.075, 18, 14), new THREE.MeshStandardMaterial({ color, emissive: color, emissiveIntensity: 1.6, depthTest: false }));
    m.renderOrder = 10; m.position.set(...pos); m.userData = d; car.add(m); spots.push(m);
    const halo = new THREE.Mesh(new THREE.SphereGeometry(0.13, 18, 14), new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.22, depthTest: false })); halo.renderOrder = 10; m.add(halo); m.userData.halo = halo;
  });
  function pickSpot(ev) {
    const r = renderer.domElement.getBoundingClientRect();
    mouse.x = ((ev.clientX - r.left) / r.width) * 2 - 1; mouse.y = -((ev.clientY - r.top) / r.height) * 2 + 1;
    ray.setFromCamera(mouse, camera); const hit = ray.intersectObjects(spots, false)[0]; return hit ? hit.object : null;
  }
  renderer.domElement.addEventListener('pointermove', ev => {
    const s = pickSpot(ev); renderer.domElement.style.cursor = s ? 'pointer' : 'grab';
    if (s) { const v = s.getWorldPosition(new THREE.Vector3()).project(camera); const r = renderer.domElement.getBoundingClientRect(); label.style.left = ((v.x + 1) / 2 * r.width) + 'px'; label.style.top = ((1 - v.y) / 2 * r.height) + 'px'; label.textContent = s.userData.label + ' · ' + s.userData.count + (s.userData.count === 1 ? ' issue' : ' issues'); label.style.display = 'block'; }
    else label.style.display = 'none';
  });
  renderer.domElement.addEventListener('click', ev => {
    const s = pickSpot(ev); if (!s) return; const d = s.userData;
    panel.querySelector('h3').textContent = d.label;
    panel.querySelector('.list').innerHTML = d.items.map(i => `<li><span class="sev sev-${i.sev}">${i.sev}</span><a class="grow" href="index.php?p=issue&id=${i.id}">${esc(i.title)}</a><span class="st st-${esc(i.status)}">${esc(i.status)}</span></li>`).join('');
    panel.classList.add('open');
  });
  panel.querySelector('.close').addEventListener('click', () => panel.classList.remove('open'));

  const btnX = hero.querySelector('[data-xray]'); const btnP = hero.querySelector('[data-photo]'); const btnR = hero.querySelector('[data-reset]');
  function setXray(on) {
    xray.visible = on; btnX?.classList.toggle('active', on);
    const seen = new Set();
    body.forEach(o => (Array.isArray(o.material) ? o.material : [o.material]).forEach(mt => {
      if (seen.has(mt)) return; seen.add(mt);
      mt.transparent = on ? true : mt.userData.tr; mt.opacity = on ? 0.1 : mt.userData.op; mt.depthWrite = !on; mt.needsUpdate = true;
    }));
    mech.forEach(o => { o.visible = on; o.material = on ? o.userData.xmat : o.userData.base; });
  }
  btnX?.addEventListener('click', () => setXray(!xray.visible));
  btnP?.addEventListener('click', () => { hero.classList.toggle('show-photo'); btnP.classList.toggle('active', hero.classList.contains('show-photo')); });
  btnR?.addEventListener('click', () => { camera.position.copy(HOME); controls.target.set(0, 0.55, 0); controls.autoRotate = true; });

  function resize() { const w = host.clientWidth, h = host.clientHeight || 460; renderer.setSize(w, h, false); camera.aspect = w / h; camera.fov = w < 600 ? 40 : 30; camera.updateProjectionMatrix(); }
  new ResizeObserver(resize).observe(host); resize();

  const clock = new THREE.Clock();
  (function loop() {
    const t = clock.getElapsedTime();
    spots.forEach((s, i) => { const k = 1 + Math.sin(t * 3 + i) * 0.18; s.scale.setScalar(k); s.userData.halo.material.opacity = 0.12 + (Math.sin(t * 3 + i) + 1) * 0.1; });
    controls.update(); renderer.render(scene, camera); requestAnimationFrame(loop);
  })();
}
function esc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
