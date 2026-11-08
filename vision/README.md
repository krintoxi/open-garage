# garage-vision (optional)

A small HTTP service that gives Open Garage its "showroom" photos: it cuts the vehicle out of the owner's photo
(BiRefNet-lite, MIT) and estimates depth (Depth Anything V2 Small, Apache-2.0), which the app renders as a tilting
3D photo. It can also build an experimental 3D model from one photo with TripoSR (MIT). Run it on a machine with a GPU
(Apple Silicon or NVIDIA); the web app itself stays plain PHP and only calls it over your private network.

```bash
python3 -m venv venv && ./venv/bin/pip install -r requirements.txt
git clone https://github.com/VAST-AI-Research/TripoSR.git   # only for the 3D beta
python3 -c "import secrets;print(secrets.token_hex(32))" > token && chmod 600 token
./venv/bin/python garage-vision.py --port 8773            # binds the Tailscale IPv4 address (or --loopback / --bind)
```

Then in the app's `app/config.local.php`:

```php
define('VISION_URL', 'http://<that-address>:8773');
define('VISION_TOKEN', '<the token>');
define('VISION_MESH', false);   // true to show the experimental "3D model" button
```

- `POST /showroom` (photo in the body) returns `{cutout, depth}` as base64 PNGs, in about a second once warm.
- `POST /mesh/start`, `GET /mesh/status?job=`, `GET /mesh/result?job=` run TripoSR (about a minute, several GB of memory;
  it refuses to start below `GARAGE_VISION_MESH_MIN_FREE` percent free memory, default 45).
- Every route needs `Authorization: Bearer <token>`. One GPU job at a time. Models unload after 10 idle minutes.
- `shims/torchmcubes.py` replaces TripoSR's CUDA marching-cubes build with PyMCubes so it runs on a Mac.
- Model weights download from Hugging Face on first use (about 2 GB with TripoSR).
