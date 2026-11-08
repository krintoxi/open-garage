#!/usr/bin/env python3
"""
garage-vision: image jobs for the Garage app (garage.edgeventure.uk and the public Open Garage instance).

  POST /showroom      body: a photo        -> JSON {cutout: base64 PNG (RGBA, cropped), depth: base64 PNG (grey)}
  POST /mesh/start    body: a photo/cutout -> JSON {job}         (single-photo 3D, experimental, ~1 min)
  GET  /mesh/status?job=ID                 -> JSON {status: queued|running|done|error|missing, progress}
  GET  /mesh/result?job=ID                 -> model/gltf-binary
  POST /read-scan     body: a photo of a scan tool screen -> JSON {text}   (codes + data lines, read by a local vision LLM)
  POST /read-id?kind=vin|plate  body: a photo of a VIN (door sticker, windshield plate, registration) or a licence plate -> JSON {text}
  GET  /health                             -> JSON {ok, busy, gaming, queue}

Same rules as edge-compute/animate-server.py: binds to the Tailscale address only (or loopback with --loopback),
a shared token on every route (Authorization: Bearer ...), clamped inputs, one GPU job at a time, models load lazily
and unload after IDLE_UNLOAD seconds so the Node 1 VM on this machine keeps its memory, and gaming mode
(~/edge-compute/gaming-mode) refuses work and frees the models.

Models (all open licences): BiRefNet-lite (MIT) for the cut-out, Depth Anything V2 Small (Apache-2.0)
for depth, TripoSR (MIT) for 3D.
"""
import argparse, base64, hmac, io, json, os, queue, subprocess, sys, threading, time, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

HERE = os.path.dirname(os.path.abspath(__file__))
os.environ.setdefault('U2NET_HOME', os.path.join(HERE, 'models', 'rembg'))
os.environ.setdefault('HF_HOME', os.path.join(HERE, 'models', 'hf'))
os.environ.setdefault('PYTORCH_ENABLE_MPS_FALLBACK', '1')
sys.path.insert(0, os.path.join(HERE, 'shims'))
sys.path.insert(1, os.path.join(HERE, 'TripoSR'))

GAMING_FLAG = os.path.expanduser('~/edge-compute/gaming-mode')
JOBS = os.path.join(HERE, 'jobs')
MAX_BODY = 25 * 1024 * 1024
MAX_SIDE = 1600
IDLE_UNLOAD = 600
GPU = threading.Lock()
STATE = {'rembg': None, 'depth': None, 'tsr': None, 'last_used': 0.0, 'busy': False}
MESH_Q = queue.Queue(maxsize=4)
MESH = {}  # job -> {status, progress, created, path, error}
# This machine also hosts the Node 1 VM (every live site) and is used for gaming: single-photo 3D (TripoSR, several GB)
# only starts when plenty of memory is free, and its model is dropped straight after each job.
MESH_MIN_FREE_PCT = int(os.environ.get('GARAGE_VISION_MESH_MIN_FREE', '45'))
# Reading scanner screenshots uses a local Ollama vision model (several GB while loaded; unloaded right after each read).
OCR_MODEL = os.environ.get('GARAGE_VISION_OCR_MODEL', 'gemma4:12b')
OCR_URL = os.environ.get('GARAGE_VISION_OLLAMA', 'http://127.0.0.1:11434')
OCR_MIN_FREE_PCT = int(os.environ.get('GARAGE_VISION_OCR_MIN_FREE', '50'))
OCR_PROMPT = ("This is a photo or screenshot of a car diagnostic scan tool or phone app. Transcribe it as plain text. "
              "First list every diagnostic trouble code exactly as shown (format like P0171, C0035, U0100), one per line, each followed by its description if shown. "
              "Then list every live-data or freeze-frame value shown as 'Name: value unit', one per line. "
              "Output only the transcription, no commentary. If there are no codes or values, output NONE.")


ID_PROMPTS = {
    'vin': ("Find the vehicle identification number (VIN) in this photo: on a door-jamb sticker, the plate under the windshield, a registration card, "
            "a title, an insurance card or a frame stamping. A VIN is 17 characters, digits and capital letters, never I, O or Q. "
            "Output each VIN you can read on its own line, exactly as printed, best reading first. Output only VINs. If there is none, output NONE."),
    'plate': ("Read the vehicle licence (number) plate in this photo. Output exactly two lines: 'PLATE: <the characters, no spaces>' and "
              "'REGION: <US state or country printed on the plate, or UNKNOWN>'. If there is no readable plate, output NONE."),
    'light': ("This photo shows a vehicle's instrument cluster or dashboard. List every warning or indicator light that is LIT (glowing), "
              "using only these names: oil_pressure, coolant_temp, brake, charging, airbag, check_engine, reduced_power, transmission, abs, traction, "
              "esp_off, tpms, power_steering, brake_wear, oil_level, coolant_level, glow_plug, dpf, adblue, hybrid_system, service, low_fuel, "
              "washer_fluid, seatbelt, door_open, key, lane_assist, high_beam, cold_engine, cruise. "
              "Output one per line as name:colour (red, amber, green or blue). Only list symbols you can clearly see lit; do not guess. "
              "If no lights are lit, or this is not a dashboard, output NONE."),
}


def ollama_read(img, prompt, num_predict):
    import urllib.request
    from PIL import Image
    if img.width < 1100:
        f = min(3.0, 1300 / img.width)
        img = img.resize((int(img.width * f), int(img.height * f)), Image.LANCZOS)
    buf = io.BytesIO(); img.save(buf, 'PNG')
    req = {'model': OCR_MODEL, 'messages': [{'role': 'user', 'content': prompt, 'images': [base64.b64encode(buf.getvalue()).decode()]}], 'stream': False, 'think': False,
           'keep_alive': 0, 'options': {'temperature': 0, 'num_ctx': 6144, 'num_predict': num_predict}}
    r = urllib.request.Request(OCR_URL.rstrip('/') + '/api/chat', data=json.dumps(req).encode(), headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(r, timeout=110) as resp:
        out = json.loads(resp.read().decode())
    text = ((out.get('message') or {}).get('content') or '').strip()
    return '' if text.upper() == 'NONE' else text[:4000]


def read_id(data, kind):
    return {'text': ollama_read(load_image(data).convert('RGB'), ID_PROMPTS[kind], 120)}


def read_scan(data):
    import urllib.request
    from PIL import Image
    img = load_image(data).convert('RGB')
    # Small screenshots: enlarge so small characters (P0171 vs P0137) are legible to the model.
    if img.width < 1100:
        f = min(3.0, 1300 / img.width)
        img = img.resize((int(img.width * f), int(img.height * f)), Image.LANCZOS)
    buf = io.BytesIO(); img.save(buf, 'PNG')
    req = {'model': OCR_MODEL, 'messages': [{'role': 'user', 'content': OCR_PROMPT, 'images': [base64.b64encode(buf.getvalue()).decode()]}], 'stream': False, 'think': False,
           'keep_alive': 0, 'options': {'temperature': 0, 'num_ctx': 6144, 'num_predict': 900}}
    r = urllib.request.Request(OCR_URL.rstrip('/') + '/api/chat', data=json.dumps(req).encode(), headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(r, timeout=110) as resp:
        out = json.loads(resp.read().decode())
    text = ((out.get('message') or {}).get('content') or '').strip()
    return {'text': '' if text.upper() == 'NONE' else text[:20000]}


def free_memory_pct():
    try:
        out = subprocess.run(['/usr/bin/memory_pressure', '-Q'], capture_output=True, text=True, timeout=10).stdout
        for line in out.splitlines():
            if 'free percentage' in line:
                return int(line.rsplit(':', 1)[1].strip().rstrip('%'))
    except Exception:
        pass
    return 0


def gaming_on():
    return os.path.exists(GAMING_FLAG)


def release_models():
    for k in ('rembg', 'depth', 'tsr'):
        STATE[k] = None
    try:
        import torch, gc
        gc.collect()
        if torch.backends.mps.is_available():
            torch.mps.empty_cache()
    except Exception:
        pass


def device():
    import torch
    return 'mps' if torch.backends.mps.is_available() else 'cpu'


def load_image(data):
    from PIL import Image, ImageOps
    img = Image.open(io.BytesIO(data))
    img = ImageOps.exif_transpose(img)
    if max(img.size) > MAX_SIDE:
        img.thumbnail((MAX_SIDE, MAX_SIDE))
    return img


def birefnet():
    if STATE['rembg'] is None:
        import torch
        from transformers import AutoModelForImageSegmentation
        m = AutoModelForImageSegmentation.from_pretrained('ZhengPeng7/BiRefNet_lite', trust_remote_code=True)
        m.to(device()).eval()
        if device() == 'mps':
            m.half()
        STATE['rembg'] = m
    return STATE['rembg']


def cutout(img):
    """RGBA image of just the vehicle (BiRefNet-lite, MIT), cropped to it with a small margin."""
    import torch
    from torchvision import transforms
    if img.mode == 'RGBA' and img.getextrema()[3][0] < 250:
        rgba = img  # already cut out (the app sends the cut-out to /mesh/start)
    else:
        rgb = img.convert('RGB')
        m = birefnet()
        tf = transforms.Compose([transforms.Resize((1024, 1024)), transforms.ToTensor(), transforms.Normalize([0.485, 0.456, 0.406], [0.229, 0.224, 0.225])])
        x = tf(rgb).unsqueeze(0).to(device())
        if device() == 'mps':
            x = x.half()
        with torch.no_grad():
            pred = m(x)[-1].sigmoid().float().cpu()[0].squeeze()
        mask = transforms.ToPILImage()(pred).resize(rgb.size)
        rgba = rgb.copy(); rgba.putalpha(mask)
    bbox = rgba.getchannel('A').point(lambda a: 255 if a > 24 else 0).getbbox()
    if bbox:
        w, h = rgba.size
        pad = int(max(bbox[2] - bbox[0], bbox[3] - bbox[1]) * 0.04)
        rgba = rgba.crop((max(0, bbox[0] - pad), max(0, bbox[1] - pad), min(w, bbox[2] + pad), min(h, bbox[3] + pad)))
    return rgba


def depth_map(rgba):
    """Grey PNG: near = bright. Background (transparent) = black."""
    import numpy as np
    from PIL import Image
    from transformers import pipeline
    if STATE['depth'] is None:
        STATE['depth'] = pipeline('depth-estimation', model='depth-anything/Depth-Anything-V2-Small-hf', device=device())
    bg = Image.new('RGB', rgba.size, (128, 128, 128))
    bg.paste(rgba, mask=rgba.getchannel('A'))
    d = STATE['depth'](bg)['predicted_depth']
    d = d.squeeze().float().cpu().numpy()
    d = np.array(Image.fromarray(d).resize(rgba.size, Image.BILINEAR))
    a = np.asarray(rgba.getchannel('A')) > 24
    if a.any():
        lo, hi = np.percentile(d[a], 2), np.percentile(d[a], 98)
        d = np.clip((d - lo) / max(hi - lo, 1e-6), 0, 1)
    d = (d * 255 * a).astype('uint8')
    return Image.fromarray(d, 'L')


def png_b64(img):
    buf = io.BytesIO(); img.save(buf, 'PNG', optimize=True)
    return base64.b64encode(buf.getvalue()).decode()


def showroom(data):
    img = load_image(data)
    with GPU:
        STATE['busy'] = True
        try:
            rgba = cutout(img)
            dep = depth_map(rgba)
        finally:
            STATE['busy'] = False; STATE['last_used'] = time.time()
    if max(rgba.size) > 1400:
        rgba.thumbnail((1400, 1400)); dep = dep.resize(rgba.size)
    return {'cutout': png_b64(rgba), 'depth': png_b64(dep), 'size': rgba.size}


def remap_vit_keys(ckpt):
    """TripoSR's checkpoint uses the transformers 4.x ViT layout (encoder.layer.N.attention.attention.query ...).
    transformers 5.x names the same weights layers.N.attention.q_proj ...; rename so the checkpoint loads unchanged."""
    import re
    rules = [
        (r'image_tokenizer\.model\.encoder\.layer\.(\d+)\.attention\.attention\.query\.', r'image_tokenizer.model.layers.\1.attention.q_proj.'),
        (r'image_tokenizer\.model\.encoder\.layer\.(\d+)\.attention\.attention\.key\.', r'image_tokenizer.model.layers.\1.attention.k_proj.'),
        (r'image_tokenizer\.model\.encoder\.layer\.(\d+)\.attention\.attention\.value\.', r'image_tokenizer.model.layers.\1.attention.v_proj.'),
        (r'image_tokenizer\.model\.encoder\.layer\.(\d+)\.attention\.output\.dense\.', r'image_tokenizer.model.layers.\1.attention.o_proj.'),
        (r'image_tokenizer\.model\.encoder\.layer\.(\d+)\.intermediate\.dense\.', r'image_tokenizer.model.layers.\1.mlp.fc1.'),
        (r'image_tokenizer\.model\.encoder\.layer\.(\d+)\.output\.dense\.', r'image_tokenizer.model.layers.\1.mlp.fc2.'),
        (r'image_tokenizer\.model\.encoder\.layer\.(\d+)\.', r'image_tokenizer.model.layers.\1.'),
    ]
    out = {}
    for k, v in ckpt.items():
        for pat, rep in rules:
            k2 = re.sub(pat, rep, k)
            if k2 != k:
                k = k2
                break
        out[k] = v
    return out


def tsr_model():
    if STATE['tsr'] is None:
        import torch
        from huggingface_hub import hf_hub_download
        from omegaconf import OmegaConf
        from tsr.system import TSR
        cfg = OmegaConf.load(hf_hub_download('stabilityai/TripoSR', 'config.yaml'))
        OmegaConf.resolve(cfg)
        m = TSR(cfg)
        ckpt = torch.load(hf_hub_download('stabilityai/TripoSR', 'model.ckpt'), map_location='cpu')
        try:
            m.load_state_dict(ckpt)
        except RuntimeError:
            m.load_state_dict(remap_vit_keys(ckpt))
        m.renderer.set_chunk_size(4096)
        m.to(device())
        STATE['tsr'] = m
    return STATE['tsr']


def mesh_job(job, data):
    import numpy as np, torch
    from PIL import Image
    MESH[job]['status'] = 'running'; MESH[job]['progress'] = 0.1
    with GPU:
        STATE['busy'] = True
        try:
            rgba = cutout(load_image(data))
            MESH[job]['progress'] = 0.3
            # TripoSR wants the object centred on grey, filling ~85% of a square.
            side = int(max(rgba.size) / 0.85)
            sq = Image.new('RGBA', (side, side), (0, 0, 0, 0))
            sq.paste(rgba, ((side - rgba.width) // 2, (side - rgba.height) // 2), rgba)
            arr = np.asarray(sq).astype(np.float32) / 255.0
            rgb = arr[:, :, :3] * arr[:, :, 3:4] + 0.5 * (1 - arr[:, :, 3:4])
            image = Image.fromarray((rgb * 255).astype(np.uint8)).resize((512, 512))
            model = tsr_model()
            MESH[job]['progress'] = 0.5
            with torch.no_grad():
                codes = model([image], device=device())
                MESH[job]['progress'] = 0.75
                mesh = model.extract_mesh(codes, True, resolution=192)[0]
            # TripoSR is Z-up with the camera looking along +X; three.js is Y-up.
            import trimesh
            mesh.apply_transform(trimesh.transformations.rotation_matrix(-np.pi / 2, [1, 0, 0]))
            out = os.path.join(JOBS, job + '.glb')
            mesh.fix_normals()
            from trimesh.exchange.gltf import export_glb
            with open(out, 'wb') as f:
                f.write(export_glb(mesh, include_normals=True))
            MESH[job].update(status='done', progress=1.0, path=out)
        except Exception as e:  # report, never crash the worker
            MESH[job].update(status='error', error=str(e)[:300])
            print('mesh job failed:', repr(e), flush=True)
        finally:
            STATE['tsr'] = None  # several GB: never keep it resident
            release_models()
            STATE['busy'] = False; STATE['last_used'] = time.time()


def mesh_worker():
    while True:
        job, data = MESH_Q.get()
        if gaming_on():
            MESH[job].update(status='error', error='gaming mode'); continue
        if free_memory_pct() < MESH_MIN_FREE_PCT:
            MESH[job].update(status='error', error='not enough free memory on the compute host right now'); continue
        mesh_job(job, data)


def housekeeping():
    while True:
        time.sleep(60)
        if STATE['last_used'] and time.time() - STATE['last_used'] > IDLE_UNLOAD and not STATE['busy'] and any(STATE[k] for k in ('rembg', 'depth', 'tsr')):
            with GPU:
                release_models(); print('models unloaded (idle)', flush=True)
        if gaming_on() and any(STATE[k] for k in ('rembg', 'depth', 'tsr')):
            with GPU:
                release_models()
        for f in os.listdir(JOBS):
            p = os.path.join(JOBS, f)
            if time.time() - os.path.getmtime(p) > 86400:
                try: os.remove(p)
                except OSError: pass
        for j in [j for j, m in MESH.items() if time.time() - m['created'] > 86400]:
            MESH.pop(j, None)


class Handler(BaseHTTPRequestHandler):
    server_version = 'garage-vision'
    protocol_version = 'HTTP/1.1'

    def log_message(self, fmt, *args):
        sys.stdout.write('%s %s\n' % (self.address_string(), fmt % args)); sys.stdout.flush()

    def _send(self, code, obj=None, body=None, ctype='application/json'):
        data = body if body is not None else json.dumps(obj).encode()
        self.send_response(code)
        self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store'); self.end_headers(); self.wfile.write(data)

    def _authed(self):
        got = self.headers.get('Authorization', '')
        ok = got.startswith('Bearer ') and hmac.compare_digest(got[7:].strip().encode(), self.server.token.encode())
        if not ok:
            self._send(401, {'error': 'unauthorized'})
        return ok

    def _body(self):
        n = int(self.headers.get('Content-Length') or 0)
        if n <= 0 or n > MAX_BODY:
            self._send(413 if n > MAX_BODY else 400, {'error': 'send an image of at most 25 MB'}); return None
        return self.rfile.read(n)

    def do_GET(self):
        if not self._authed(): return
        u = urlparse(self.path); qs = parse_qs(u.query); job = (qs.get('job') or [''])[0]
        if u.path == '/health':
            return self._send(200, {'ok': True, 'busy': STATE['busy'], 'gaming': gaming_on(), 'queue': MESH_Q.qsize(), 'free_pct': free_memory_pct(), 'mesh_min_free_pct': MESH_MIN_FREE_PCT})
        if u.path == '/mesh/status':
            m = MESH.get(job)
            return self._send(200, {'status': 'missing'} if not m else {'status': m['status'], 'progress': m.get('progress'), 'error': m.get('error')})
        if u.path == '/mesh/result':
            m = MESH.get(job)
            if not m or m['status'] != 'done' or not os.path.exists(m.get('path', '')):
                return self._send(404, {'error': 'not ready'})
            with open(m['path'], 'rb') as f:
                return self._send(200, body=f.read(), ctype='model/gltf-binary')
        self._send(404, {'error': 'no such route'})

    def do_POST(self):
        if not self._authed(): return
        if gaming_on():
            return self._send(503, {'error': 'gaming mode is on'})
        path = urlparse(self.path).path
        if path not in ('/showroom', '/mesh/start', '/read-scan', '/read-id'):
            return self._send(404, {'error': 'no such route'})
        data = self._body()
        if data is None: return
        if path == '/read-id':
            kind = (parse_qs(urlparse(self.path).query).get('kind') or [''])[0]
            if kind not in ID_PROMPTS:
                return self._send(400, {'error': 'kind must be vin, plate or light'})
            if free_memory_pct() < OCR_MIN_FREE_PCT:
                return self._send(503, {'error': 'busy: not enough free memory right now'})
            if not GPU.acquire(timeout=60):
                return self._send(503, {'error': 'busy'})
            try:
                return self._send(200, read_id(data, kind))
            except Exception as e:
                print('read-id failed:', repr(e), flush=True)
                return self._send(422, {'error': 'could not read that image'})
            finally:
                GPU.release()
        if path == '/read-scan':
            if free_memory_pct() < OCR_MIN_FREE_PCT:
                return self._send(503, {'error': 'busy: not enough free memory right now'})
            if not GPU.acquire(timeout=60):
                return self._send(503, {'error': 'busy'})
            try:
                return self._send(200, read_scan(data))
            except Exception as e:
                print('read-scan failed:', repr(e), flush=True)
                return self._send(422, {'error': 'could not read that image'})
            finally:
                GPU.release()
        if path == '/showroom':
            if not GPU.acquire(timeout=30):
                return self._send(503, {'error': 'busy'})
            GPU.release()
            try:
                return self._send(200, showroom(data))
            except Exception as e:
                print('showroom failed:', repr(e), flush=True)
                return self._send(422, {'error': 'could not process that image'})
        if free_memory_pct() < MESH_MIN_FREE_PCT:
            return self._send(503, {'error': 'busy: not enough free memory for 3D right now'})
        job = uuid.uuid4().hex
        MESH[job] = {'status': 'queued', 'progress': 0.0, 'created': time.time()}
        try:
            MESH_Q.put_nowait((job, data))
        except queue.Full:
            MESH.pop(job, None)
            return self._send(503, {'error': 'busy'})
        self._send(200, {'job': job})

    do_PUT = do_DELETE = do_PATCH = lambda self: (self._authed() and self._send(405, {'error': 'method not allowed'}))


def tailscale_ip():
    for exe in ('/Applications/Tailscale.app/Contents/MacOS/Tailscale', '/usr/local/bin/tailscale', '/opt/homebrew/bin/tailscale'):
        if os.path.exists(exe):
            try:
                out = subprocess.run([exe, 'ip', '-4'], capture_output=True, text=True, timeout=10).stdout.split()
                if out: return out[0]
            except Exception:
                pass
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--port', type=int, default=8773)
    ap.add_argument('--loopback', action='store_true', help='bind 127.0.0.1 (testing)')
    ap.add_argument('--bind', default='', help='explicit address (default: this machine\'s Tailscale IPv4)')
    a = ap.parse_args()
    tok_file = os.path.join(HERE, 'token')
    token = os.environ.get('GARAGE_VISION_TOKEN', '').strip() or (open(tok_file).read().strip() if os.path.exists(tok_file) else '')
    if len(token) < 32:
        sys.exit('No token: put at least 32 characters in ' + tok_file)
    bind = '127.0.0.1' if a.loopback else (a.bind or None)
    for _ in range(60):
        if bind: break
        bind = tailscale_ip()
        if not bind: time.sleep(5)
    if not bind:
        sys.exit('No Tailscale address; refusing to bind anything wider.')
    os.makedirs(JOBS, exist_ok=True)
    threading.Thread(target=mesh_worker, daemon=True).start()
    threading.Thread(target=housekeeping, daemon=True).start()
    srv = ThreadingHTTPServer((bind, a.port), Handler); srv.token = token
    print(f'garage-vision ready on http://{bind}:{a.port}', flush=True)
    srv.serve_forever()


if __name__ == '__main__':
    main()
