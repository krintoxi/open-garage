// Service worker: makes the app installable and gives it an offline screen.
// Only static files (styles, scripts, icons) are cached. Pages with your records are never stored on the device.
const CACHE = 'garage-shell-v2';
const SHELL = ['offline.html', 'assets/app.css?v=14', 'assets/app.js?v=7', 'assets/favicon.png', 'assets/icon-512.png', 'assets/apple-touch-icon.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  // Styles, scripts and icons: serve from the cache, refresh it in the background.
  if (url.pathname.includes('/assets/') && !url.pathname.includes('/assets/models/')) {
    e.respondWith(caches.open(CACHE).then(async c => {
      const hit = await c.match(req);
      const net = fetch(req).then(r => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => hit);
      return hit || net;
    }));
  }
});
