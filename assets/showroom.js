// Showroom: the owner's cut-out photo rendered as a "3D photo". A depth map (near = bright) shifts each pixel as the
// phone tilts or the pointer moves, so the vehicle moves in real parallax. Also runs the "build a 3D model" flow and
// shows the finished model in an orbit viewer. No libraries for the photo; three.js only for the 3D model.

const stage = document.querySelector('[data-stage]');
if (stage) init(stage);

function init(stage) {
  const canvas = stage.querySelector('canvas[data-showroom]');
  if (canvas) photo3d(stage, canvas);
  meshFlow(stage);
  visionPoll(stage);
}

function loadImage(src) {
  return new Promise((ok, bad) => { const i = new Image(); i.onload = () => ok(i); i.onerror = bad; i.src = src; });
}

async function photo3d(stage, canvas) {
  const fallback = stage.querySelector('[data-showroom-fallback]');
  let color, depth;
  try { [color, depth] = await Promise.all([loadImage(canvas.dataset.cutout), loadImage(canvas.dataset.depth)]); } catch (e) { return; }
  const gl = canvas.getContext('webgl', { premultipliedAlpha: false, alpha: true, antialias: true });
  if (!gl) return;
  const vs = 'attribute vec2 p;varying vec2 uv;void main(){uv=vec2(p.x*.5+.5,.5-p.y*.5);gl_Position=vec4(p,0.,1.);}';
  const fs = `precision mediump float;varying vec2 uv;uniform sampler2D c;uniform sampler2D d;uniform vec2 t;uniform float s;
    void main(){
      vec2 o=vec2(0.);
      for(int i=0;i<4;i++){ float z=texture2D(d,uv-o).r; o=t*s*(z-.45); }
      vec2 q=uv-o;
      if(q.x<0.||q.y<0.||q.x>1.||q.y>1.){gl_FragColor=vec4(0.);return;}
      vec4 col=texture2D(c,q);
      float z=texture2D(d,q).r;
      col.rgb*=.86+.22*z;                 // nearer parts catch a little more light
      gl_FragColor=col;
    }`;
  const sh = (type, src) => { const s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); return s; };
  const prog = gl.createProgram();
  gl.attachShader(prog, sh(gl.VERTEX_SHADER, vs)); gl.attachShader(prog, sh(gl.FRAGMENT_SHADER, fs)); gl.linkProgram(prog);
  if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) return;
  gl.useProgram(prog);
  const buf = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, buf);
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
  const loc = gl.getAttribLocation(prog, 'p'); gl.enableVertexAttribArray(loc); gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);
  const tex = (img, unit) => {
    const t = gl.createTexture(); gl.activeTexture(gl.TEXTURE0 + unit); gl.bindTexture(gl.TEXTURE_2D, t);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img);
  };
  tex(color, 0); tex(depth, 1);
  gl.uniform1i(gl.getUniformLocation(prog, 'c'), 0); gl.uniform1i(gl.getUniformLocation(prog, 'd'), 1);
  const uT = gl.getUniformLocation(prog, 't'), uS = gl.getUniformLocation(prog, 's');
  gl.uniform1f(uS, 0.035);
  gl.enable(gl.BLEND); gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

  const aspect = color.naturalWidth / color.naturalHeight;
  canvas.style.aspectRatio = aspect;
  const size = () => {
    // Fit inside the stage: at most ~62% of its height and 84% of its width.
    const maxW = stage.clientWidth * (stage.clientWidth < 600 ? 0.94 : 0.84), maxH = stage.clientHeight * (stage.clientWidth < 600 ? 0.5 : 0.62);
    const cssW = Math.round(Math.min(maxW, maxH * aspect));
    canvas.style.width = cssW + 'px';
    stage.querySelectorAll('.stage-reflect img').forEach(i => { i.style.width = cssW + 'px'; });
    const w = cssW, h = Math.round(w / aspect), r = Math.min(devicePixelRatio || 1, 2);
    canvas.width = Math.round(w * r); canvas.height = Math.round(h * r); gl.viewport(0, 0, canvas.width, canvas.height);
  };
  new ResizeObserver(size).observe(stage); size();
  canvas.hidden = false; if (fallback) fallback.hidden = true;
  stage.classList.add('has-3dphoto');

  const target = { x: 0, y: 0 }, now = { x: 0, y: 0 };
  let lastInput = 0;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  stage.addEventListener('pointermove', e => {
    const r = stage.getBoundingClientRect();
    target.x = ((e.clientX - r.left) / r.width) * 2 - 1; target.y = ((e.clientY - r.top) / r.height) * 2 - 1; lastInput = performance.now();
  });
  stage.addEventListener('pointerleave', () => { target.x = 0; target.y = 0; });
  addEventListener('deviceorientation', e => {
    if (e.gamma == null) return;
    target.x = Math.max(-1, Math.min(1, e.gamma / 25)); target.y = Math.max(-1, Math.min(1, (e.beta - 45) / 35)); lastInput = performance.now();
  });
  (function frame(t) {
    if (!reduce && performance.now() - lastInput > 2500) { target.x = Math.sin(t / 1600) * 0.6; target.y = Math.sin(t / 2300) * 0.25; }
    now.x += (target.x - now.x) * 0.08; now.y += (target.y - now.y) * 0.08;
    gl.clearColor(0, 0, 0, 0); gl.clear(gl.COLOR_BUFFER_BIT);
    gl.uniform2f(uT, now.x, now.y * 0.6);
    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
    requestAnimationFrame(frame);
  })(0);
}

// While the cut-out is being made (the image service was busy), check back and reload when it's ready.
function visionPoll(stage) {
  if (stage.dataset.vision !== 'pending') return;
  const note = stage.querySelector('[data-vision-note]');
  const tick = async () => {
    const r = await fetch('index.php?p=vision', { credentials: 'same-origin' }).then(r => r.json()).catch(() => null);
    if (r && r.vision === 'ready') return location.reload();
    if (note) note.textContent = 'Making your showroom photo… (the image service is busy; this page updates by itself)';
    setTimeout(tick, 20000);
  };
  setTimeout(tick, 5000);
}

// "3D model (beta)": start the job, show progress, then open an orbit viewer.
function meshFlow(stage) {
  const btn = stage.querySelector('[data-mesh]');
  if (!btn) return;
  const label = btn.textContent;
  const csrf = stage.dataset.csrf;
  const poll = async () => {
    const r = await fetch('index.php?p=vision', { credentials: 'same-origin' }).then(r => r.json()).catch(() => null);
    if (!r) return setTimeout(poll, 8000);
    if (r.mesh === 'ready' && r.glb) { btn.textContent = 'View in 3D (beta)'; btn.disabled = false; btn.dataset.glb = r.glb; stage.dataset.mesh = 'ready'; return; }
    if (r.mesh === 'error') { btn.textContent = '3D failed, try again'; btn.disabled = false; stage.dataset.mesh = 'error'; return; }
    btn.textContent = 'Building 3D… ' + Math.round((r.progress || 0.05) * 100) + '%';
    setTimeout(poll, 4000);
  };
  if (stage.dataset.mesh === 'running') { btn.disabled = true; poll(); }
  if (stage.dataset.mesh === 'ready') btn.dataset.glb = stage.dataset.glb;
  btn.addEventListener('click', async () => {
    if (btn.dataset.glb) return openViewer(stage, btn.dataset.glb);
    btn.disabled = true; btn.textContent = 'Starting…';
    const body = new URLSearchParams({ action: 'mesh_start', _csrf: csrf });
    const r = await fetch('index.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } }).then(r => r.json()).catch(() => null);
    if (!r || r.status !== 'running') { btn.disabled = false; btn.textContent = r && r.status === 'limit' ? 'Daily limit reached' : '3D service offline, try later'; setTimeout(() => { btn.textContent = label; }, 5000); return; }
    poll();
  });
}

async function openViewer(stage, glbUrl) {
  const THREE = await import('three');
  const { OrbitControls } = await import('three/addons/controls/OrbitControls.js');
  const { GLTFLoader } = await import('three/addons/loaders/GLTFLoader.js');
  const { RoomEnvironment } = await import('three/addons/environments/RoomEnvironment.js');
  const wrap = document.createElement('div'); wrap.className = 'mesh-viewer';
  wrap.innerHTML = '<button type="button" class="btn sm" data-close>Close 3D</button><small>Experimental: built from one photo, so the far side is a guess.</small>';
  stage.appendChild(wrap);
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(devicePixelRatio, 2)); renderer.toneMapping = THREE.ACESFilmicToneMapping;
  wrap.prepend(renderer.domElement);
  const scene = new THREE.Scene();
  const pm = new THREE.PMREMGenerator(renderer); scene.environment = pm.fromScene(new RoomEnvironment(), 0.04).texture;
  scene.add(new THREE.HemisphereLight(0xffffff, 0x223344, 1.2));
  const key = new THREE.DirectionalLight(0xffffff, 2.2); key.position.set(3, 5, 4); scene.add(key);
  const camera = new THREE.PerspectiveCamera(35, 1, 0.01, 100);
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true; controls.autoRotate = true; controls.autoRotateSpeed = 1.2; controls.enablePan = false;
  renderer.domElement.style.touchAction = 'pan-y';
  const g = await new GLTFLoader().loadAsync(glbUrl);
  const m = g.scene;
  m.traverse(o => {
    if (!o.isMesh) return;
    if (!o.geometry.attributes.normal) o.geometry.computeVertexNormals(); // single-photo meshes may come without normals
    const col = o.geometry.attributes.color; // TripoSR writes sRGB vertex colours; three.js expects linear
    if (col && !o.geometry.userData.linearised) { for (let i = 0; i < col.count; i++) for (let c = 0; c < 3; c++) { const v = col.array[i * col.itemSize + c] / (col.normalized ? 255 : 1); const lin = Math.pow(v, 2.2); col.array[i * col.itemSize + c] = col.normalized ? Math.round(lin * 255) : lin; } col.needsUpdate = true; o.geometry.userData.linearised = true; }
    o.material = new THREE.MeshStandardMaterial({ vertexColors: !!o.geometry.attributes.color, roughness: 0.55, metalness: 0.15, side: THREE.DoubleSide });
  });
  const box = new THREE.Box3().setFromObject(m), c = box.getCenter(new THREE.Vector3()), s = box.getSize(new THREE.Vector3());
  m.position.sub(c); m.position.y += s.y / 2;
  scene.add(m);
  const R = Math.max(s.x, s.y, s.z);
  const floor = new THREE.Mesh(new THREE.CircleGeometry(R * 1.4, 64), new THREE.MeshStandardMaterial({ color: 0x0d1016, roughness: 0.6 }));
  floor.rotation.x = -Math.PI / 2; scene.add(floor);
  camera.position.set(R * 1.4, R * 0.7, R * 1.6); controls.target.set(0, s.y * 0.45, 0); controls.update();
  const fit = () => { const w = wrap.clientWidth, h = wrap.clientHeight; renderer.setSize(w, h, false); camera.aspect = w / h; camera.updateProjectionMatrix(); };
  new ResizeObserver(fit).observe(wrap); fit();
  let alive = true;
  wrap.querySelector('[data-close]').addEventListener('click', () => { alive = false; renderer.dispose(); wrap.remove(); });
  (function loop() { if (!alive) return; controls.update(); renderer.render(scene, camera); requestAnimationFrame(loop); })();
}
