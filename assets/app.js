// Small UI helpers: tabs, lightbox, confirm buttons, auto-sum costs.
document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-tab]');
  if (t) {
    const group = t.closest('.tabs');
    group.querySelectorAll('button').forEach(b => b.classList.toggle('active', b === t));
    const root = group.parentElement;
    root.querySelectorAll(':scope > .tabpane').forEach(p => p.classList.toggle('active', p.dataset.pane === t.dataset.tab));
    return;
  }
  const img = e.target.closest('.photos img, img[data-zoom]');
  if (img) {
    const lb = document.getElementById('lightbox');
    lb.querySelector('img').src = img.dataset.full || img.src;
    lb.hidden = false;
    return;
  }
  if (e.target.closest('#lightbox')) { document.getElementById('lightbox').hidden = true; return; }
  const c = e.target.closest('[data-confirm]');
  if (c && !confirm(c.dataset.confirm)) { e.preventDefault(); }
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { const lb = document.getElementById('lightbox'); if (lb) lb.hidden = true; } });

// parts + labor -> total
document.querySelectorAll('form[data-costsum]').forEach(f => {
  const p = f.querySelector('[name=parts_cost]'), l = f.querySelector('[name=labor_cost]'), t = f.querySelector('[name=cost]');
  if (!p || !l || !t) return;
  const sum = () => { const a = parseFloat(p.value) || 0, b = parseFloat(l.value) || 0; if (a || b) t.value = (a + b).toFixed(2); };
  p.addEventListener('input', sum); l.addEventListener('input', sum);
});

// Pre-fill "today" on empty date inputs marked data-today
document.querySelectorAll('input[type=date][data-today]').forEach(i => { if (!i.value) i.value = new Date().toISOString().slice(0, 10); });

// Upload limits (set on <body> by layout.php from the server's PHP settings). Over the limit, PHP would
// silently drop files past the count, or reject the whole form when it is too big, so stop it here with a reason.
(() => {
  const d = document.body.dataset, maxFiles = +d.maxFiles || 0, maxFile = +d.maxFileBytes || 0, maxTotal = +d.maxTotalBytes || 0;
  const mb = (b) => Math.round(b / 1048576) + ' MB';
  const problem = (files) => {
    if (maxFiles && files.length > maxFiles) return 'You picked ' + files.length + ' files, but at most ' + maxFiles + ' can be uploaded at once. Pick ' + maxFiles + ' or fewer and upload the rest in another batch.';
    const big = maxFile ? files.find(f => f.size > maxFile) : null;
    if (big) return big.name + ' is ' + mb(big.size) + '; the limit is ' + mb(maxFile) + ' per file.';
    const total = files.reduce((s, f) => s + f.size, 0);
    if (maxTotal && total > maxTotal) return 'These files add up to ' + mb(total) + ', but at most ' + mb(maxTotal) + ' can be uploaded at once. Upload them in smaller batches.';
    return '';
  };
  document.addEventListener('change', (e) => {
    const i = e.target;
    if (!(i instanceof HTMLInputElement) || i.type !== 'file' || !i.files) return;
    const msg = problem(Array.from(i.files));
    if (msg) { alert(msg); i.value = ''; }
  });
  document.addEventListener('submit', (e) => {
    const files = [];
    e.target.querySelectorAll('input[type=file]').forEach(i => files.push(...Array.from(i.files || [])));
    const msg = files.length ? problem(files) : '';
    if (msg) { alert(msg); e.preventDefault(); }
  });
})();

// Phones: tables stack into one block per row (app.css), so give each cell its column name to show as a label.
document.querySelectorAll('table').forEach(t => {
  const heads = Array.from(t.querySelectorAll('thead th')).map(th => th.textContent.trim());
  if (!heads.length) return;
  t.querySelectorAll('tbody tr').forEach(tr => {
    let col = 0;
    Array.from(tr.children).forEach(td => { if (heads[col] && !td.hasAttribute('data-label')) td.setAttribute('data-label', heads[col]); col += td.colSpan || 1; });
  });
});

// Phones: the nav is one sideways-scrolling row; bring the current page's link into view (without moving the page).
(() => {
  const nav = document.querySelector('.side nav'), a = nav && nav.querySelector('a.active');
  if (!a || nav.scrollWidth <= nav.clientWidth) return;
  nav.scrollLeft += a.getBoundingClientRect().left - nav.getBoundingClientRect().left - (nav.clientWidth - a.offsetWidth) / 2;
})();

// Tilt: the showroom vehicle and garage cards lean toward the pointer (or the phone's tilt).
(() => {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const stage = document.querySelector('[data-stage]'), car = stage && stage.querySelector('[data-tilt]');
  const lean = (el, x, y, max, base) => { el.style.transform = `${base} rotateY(${(x * max).toFixed(2)}deg) rotateX(${(-y * max * 0.6).toFixed(2)}deg)`; };
  if (car) {
    const base = 'translate(-50%,-50%)';
    stage.addEventListener('pointermove', e => { const r = stage.getBoundingClientRect(); lean(car, (e.clientX - r.left) / r.width * 2 - 1, (e.clientY - r.top) / r.height * 2 - 1, 12, base); });
    stage.addEventListener('pointerleave', () => { car.style.transform = base; });
    addEventListener('deviceorientation', e => { if (e.gamma == null) return; lean(car, Math.max(-1, Math.min(1, e.gamma / 30)), Math.max(-1, Math.min(1, (e.beta - 45) / 40)), 10, base); });
  }
  document.querySelectorAll('.vcard[data-tilt]').forEach(c => {
    c.addEventListener('pointermove', e => { const r = c.getBoundingClientRect(); lean(c, (e.clientX - r.left) / r.width * 2 - 1, (e.clientY - r.top) / r.height * 2 - 1, 7, 'translateY(-3px)'); });
    c.addEventListener('pointerleave', () => { c.style.transform = ''; });
  });
})();

// Add-vehicle form: decode the VIN (NHTSA) and fill the boxes.
(() => {
  const form = document.querySelector('[data-vehicle-add]'); if (!form) return;
  const vin = form.querySelector('[data-vin]'), btn = form.querySelector('[data-vin-lookup]'), out = form.querySelector('[data-vin-result]');
  const set = (n, v) => { const f = form.elements[n]; if (f && v) f.value = v; };
  btn.addEventListener('click', async () => {
    const v = vin.value.trim().toUpperCase(); out.className = 'vin-result muted'; out.textContent = 'Looking up…';
    try {
      const r = await (await fetch('index.php?p=vin&vin=' + encodeURIComponent(v), { credentials: 'same-origin' })).json();
      if (r.error && !r.make) { out.className = 'vin-result err'; out.textContent = r.error; return; }
      ['year', 'make', 'model', 'trim', 'engine', 'transmission', 'kind'].forEach(k => set(k, r[k]));
      out.className = 'vin-result ok';
      out.textContent = 'Found: ' + [r.year, r.make, r.model, r.trim].filter(Boolean).join(' ') + (r.body ? ' · ' + r.body : '') + (r.engine ? ' · ' + r.engine : '') + (r.plant ? ' · built in ' + r.plant : '') + '. Check the boxes below, then add.';
    } catch (e) { out.className = 'vin-result err'; out.textContent = 'Could not reach the VIN service. Fill the boxes in yourself.'; }
  });
})();
