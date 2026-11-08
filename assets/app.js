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

// Add-vehicle wizard: 1 what you drive (pick or VIN), 2 photo, 3 what's going on.
(() => {
  const form = document.querySelector('[data-vehicle-add]'); if (!form) return;
  const $ = s => form.querySelector(s), $$ = s => [...form.querySelectorAll(s)];
  const steps = $$('.wz-step'), dots = [...document.querySelectorAll('.wz-steps li')];
  let cur = 1;
  const show = (n, scroll = true) => {
    cur = n;
    steps.forEach(s => { s.hidden = +s.dataset.step !== n; });
    dots.forEach(d => d.classList.toggle('on', +d.dataset.dot <= n));
    if (scroll) form.closest('.wizard').scrollIntoView({ block: 'start', behavior: 'smooth' });
  };
  const kind = () => (form.querySelector('[name=kind]:checked') || {}).value || 'car';
  const two = () => ['scooter', 'motorcycle'].includes(kind());
  const makeSel = $('[data-make]'), makeTxt = $('[data-make-text]'), modelSel = $('[data-model]'), modelTxt = $('[data-model-text]'), yearSel = $('[data-year]');
  const opt = (v, t) => { const o = document.createElement('option'); o.value = v; o.textContent = t ?? v; return o; };
  const getJSON = u => fetch(u, { credentials: 'same-origin' }).then(r => r.json());

  function resetModels(msg) {
    modelSel.replaceChildren(opt('', msg || 'Pick year and make first'));
    modelSel.disabled = true; modelTxt.hidden = true; modelTxt.value = '';
  }
  async function loadMakes() {
    const r = await getJSON('index.php?p=vpic&what=makes&kind=' + kind()).catch(() => ({ makes: [] }));
    makeSel.replaceChildren(opt('', 'Make'), ...r.makes.map(m => opt(m)), opt('__other', 'Other / not listed…'));
    makeTxt.hidden = true; makeTxt.value = ''; resetModels();
  }
  async function loadModels() {
    const make = makeSel.value === '__other' ? makeTxt.value.trim() : makeSel.value, year = yearSel.value;
    if (!make || !year) return resetModels();
    resetModels('Loading models…');
    const r = await getJSON(`index.php?p=vpic&what=models&kind=${kind()}&make=${encodeURIComponent(make)}&year=${year}`).catch(() => ({ models: [] }));
    modelSel.replaceChildren(opt('', r.models.length ? 'Model' : 'No list for this one; type it'), ...r.models.map(m => opt(m)), opt('__other', 'Other / not listed…'));
    modelSel.disabled = false;
    if (!r.models.length) { modelSel.value = '__other'; modelTxt.hidden = false; }
  }
  makeSel.addEventListener('change', () => {
    makeTxt.hidden = makeSel.value !== '__other';
    if (makeSel.value !== '__other') makeTxt.value = makeSel.value; else { makeTxt.value = ''; makeTxt.focus(); }
    loadModels();
  });
  makeTxt.addEventListener('change', loadModels);
  yearSel.addEventListener('change', loadModels);
  modelSel.addEventListener('change', () => {
    modelTxt.hidden = modelSel.value !== '__other';
    if (modelSel.value !== '__other') modelTxt.value = modelSel.value; else { modelTxt.value = ''; modelTxt.focus(); }
  });
  const syncKind = () => {
    $$('.quick-pick [data-car-only="1"]').forEach(l => { l.hidden = two(); if (two()) l.querySelector('input').checked = false; });
  };
  $$('[name=kind]').forEach(r => r.addEventListener('change', () => { loadMakes(); syncKind(); }));

  // Tabs: pick from a list, or the VIN
  $$('.wz-tabs button').forEach(b => b.addEventListener('click', () => {
    $$('.wz-tabs button').forEach(x => x.classList.toggle('on', x === b));
    $$('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== b.dataset.tab; });
  }));

  // VIN lookup fills everything it can (typed, or from the scan buttons)
  const vin = $('[data-vin]'), out = $('[data-vin-result]');
  form.dataset.idscanCustom = '1';
  form.addEventListener('idscan', e => {
    const r = e.detail;
    if (r.plate && form.elements.plate) form.elements.plate.value = r.plate;
    if (!r.vin) return;
    vin.value = r.vin;
    $$('.wz-tabs button').forEach(x => x.classList.toggle('on', x.dataset.tab === 'vin'));
    $$('[data-pane]').forEach(p => { p.hidden = p.dataset.pane !== 'vin'; });
    applyVin(r);
  });
  $('[data-vin-lookup]').addEventListener('click', async () => {
    out.className = 'vin-result muted'; out.textContent = 'Looking up…';
    try { applyVin(await getJSON('index.php?p=vin&vin=' + encodeURIComponent(vin.value.trim().toUpperCase()))); }
    catch (e) { out.className = 'vin-result err'; out.textContent = 'Could not reach the VIN service. Pick it from the list instead.'; }
  });
  function applyVin(r) {
    {
      if (r.error && !r.make) { out.className = 'vin-result err'; out.textContent = r.error; return; }
      if (r.kind) { const k = form.querySelector(`[name=kind][value="${r.kind}"]`); if (k) { k.checked = true; syncKind(); } }
      ['trim', 'engine', 'transmission'].forEach(k => { if (r[k]) form.elements[k].value = r[k]; });
      if (r.year) yearSel.value = r.year;
      makeSel.replaceChildren(opt('__other', r.make || 'Make')); makeTxt.hidden = false; makeTxt.value = r.make || '';
      modelSel.replaceChildren(opt('__other', r.model || 'Model')); modelSel.disabled = false; modelTxt.hidden = false; modelTxt.value = r.model || '';
      out.className = 'vin-result ok';
      out.textContent = 'Found: ' + [r.year, r.make, r.model, r.trim].filter(Boolean).join(' ') + (r.engine ? ' · ' + r.engine : '') + (r.error ? '. ' + r.error : '') + '. Tap Next.';
    }
  }

  // Photo preview
  const photo = $('[data-photo]'), prev = $('[data-preview]');
  photo.addEventListener('change', () => {
    const f = photo.files[0]; if (!f) return;
    if (/^image\/(jpeg|png|webp|gif)$/.test(f.type)) { prev.src = URL.createObjectURL(f); prev.hidden = false; form.querySelector('.snap-empty').hidden = true; }
    else form.querySelector('.snap-empty b').textContent = '📷 ' + f.name + ' (ready)';
    $('[data-skip]').hidden = true; $('[data-photo-next]').hidden = false;
  });

  // Navigation, with a light check on step 1
  $$('[data-next]').forEach(b => b.addEventListener('click', () => {
    if (cur === 1) {
      if (makeSel.value && makeSel.value !== '__other') makeTxt.value = makeSel.value;
      if (modelSel.value && modelSel.value !== '__other') modelTxt.value = modelSel.value;
      if (!makeTxt.value.trim() || !modelTxt.value.trim()) {
        if (!makeTxt.value.trim() && makeSel.value === '__other') makeTxt.hidden = false;
        if (!modelSel.disabled) modelTxt.hidden = modelSel.value !== '__other' && !!modelSel.value;
        alert('Pick (or type) the make and model first.');
        return;
      }
    }
    show(cur + 1);
  }));
  $$('[data-back]').forEach(b => b.addEventListener('click', () => show(cur - 1)));
  form.addEventListener('submit', () => {
    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    btn.textContent = photo.files.length ? 'Adding… making your showroom photo' : 'Adding…';
  });
  show(1, false); loadMakes(); syncKind();
})();

// Bottom sheets (Quick add, Menu): open, close on backdrop tap / Close / Escape.
(() => {
  document.querySelectorAll('[data-sheet-open]').forEach(b => b.addEventListener('click', () => {
    const d = document.getElementById('sheet-' + b.dataset.sheetOpen);
    if (d && typeof d.showModal === 'function') d.showModal();
  }));
  document.querySelectorAll('dialog.sheet').forEach(d => {
    d.addEventListener('click', e => { if (e.target === d || e.target.closest('[data-sheet-close]')) d.close(); });
  });
})();

// Copy-to-clipboard buttons and the phone's share menu.
(() => {
  document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); const t = b.textContent; b.textContent = 'Copied ✓'; setTimeout(() => { b.textContent = t; }, 1600); }
    catch { const i = b.parentElement.querySelector('input'); if (i) { i.select(); document.execCommand('copy'); } }
  }));
  document.querySelectorAll('[data-select-all]').forEach(i => i.addEventListener('focus', () => i.select()));
  document.querySelectorAll('[data-share-url]').forEach(b => {
    if (!navigator.share) return;
    b.hidden = false;
    b.addEventListener('click', () => navigator.share({ title: b.dataset.shareTitle, url: b.dataset.shareUrl }).catch(() => {}));
  });
})();

// Weekly recall check runs in the background; reload only when something new turns up.
(() => {
  if (!document.querySelector('[data-recalls-sync]')) return;
  fetch('index.php?p=recalls_sync', { credentials: 'same-origin' }).then(r => r.json()).then(j => { if (j && j.new > 0) location.reload(); }).catch(() => {});
})();

// Installable app: service worker, the browser's install prompt, and the iPhone "Add to Home Screen" tip.
(() => {
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  const tip = document.querySelector('[data-install-tip]');
  if (!tip || standalone) return;
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !/crios|fxios/i.test(navigator.userAgent);
  if (ios) { tip.hidden = false; tip.querySelector('[data-ios-tip]').hidden = false; }
  let deferred = null;
  addEventListener('beforeinstallprompt', e => {
    e.preventDefault(); deferred = e;
    tip.hidden = false; const b = tip.querySelector('[data-install]'); b.hidden = false;
    b.onclick = async () => { deferred.prompt(); await deferred.userChoice.catch(() => {}); deferred = null; tip.hidden = true; };
  });
})();

// Diagnosis: per-code tabs (Overview / DIY / Mechanic / Videos) and in-app YouTube results + player.
(() => {
  const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const player = () => document.getElementById('ytplayer');
  const play = id => {
    const p = player(); if (!p) return;
    p.querySelector('iframe').src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
    p.hidden = false;
  };
  document.addEventListener('click', e => {
    const p = player();
    if (p && !p.hidden && (e.target === p || e.target.closest('#ytplayer button'))) { p.hidden = true; p.querySelector('iframe').src = 'about:blank'; }
    const v = e.target.closest('[data-yt-id]');
    if (v) { e.preventDefault(); play(v.dataset.ytId); }
  });
  document.addEventListener('keydown', e => { const p = player(); if (e.key === 'Escape' && p && !p.hidden) { p.hidden = true; p.querySelector('iframe').src = 'about:blank'; } });
  async function loadVideos(q, out) {
    out.innerHTML = '<p class="muted">Loading videos…</p>';
    try {
      const r = await fetch('index.php?p=yt&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(r => r.json());
      if (!r.items || !r.items.length) {
        out.innerHTML = '<p class="muted">' + (r.nokey ? 'In-app video search is not switched on here yet.' : 'No videos found in the app.') + '</p><a class="btn sm" target="_blank" rel="noopener" href="' + esc(r.fallback) + '">Search YouTube for “' + esc(q) + '” ↗</a>';
        return;
      }
      out.innerHTML = r.items.map(v => '<a class="yt" href="https://www.youtube.com/watch?v=' + esc(v.id) + '" data-yt-id="' + esc(v.id) + '"><img loading="lazy" src="' + esc(v.thumb) + '" alt=""><b>' + esc(v.title) + '</b><small>' + esc(v.channel) + '</small></a>').join('')
        + '<a class="btn sm ghost yt-more" target="_blank" rel="noopener" href="' + esc(r.fallback) + '">More on YouTube ↗</a>';
    } catch { out.innerHTML = '<a class="btn sm" target="_blank" rel="noopener" href="https://www.youtube.com/results?search_query=' + encodeURIComponent(q) + '">Search YouTube ↗</a>'; }
  }
  document.querySelectorAll('.dtabs').forEach(tabs => {
    const card = tabs.closest('.dcode');
    tabs.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
      tabs.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
      card.querySelectorAll('.dpane').forEach(p => { p.hidden = p.dataset.pane !== b.dataset.dtab; });
      if (b.dataset.yt && !b.dataset.loaded) { b.dataset.loaded = 1; loadVideos(b.dataset.yt, card.querySelector('[data-yt-out]')); }
    }));
  });
  document.querySelectorAll('[data-yt-search]').forEach(f => f.addEventListener('submit', e => {
    e.preventDefault(); const q = f.querySelector('[data-yt-q]').value.trim(); if (q) loadVideos(q, f.parentElement.querySelector('[data-yt-out]'));
  }));
  document.querySelectorAll('[data-copy-text]').forEach(p => { p.title = 'Tap to copy'; p.addEventListener('click', () => navigator.clipboard?.writeText(p.textContent.trim()).then(() => { p.classList.add('copied'); setTimeout(() => p.classList.remove('copied'), 1400); })); });
})();

// Diagnose form: "another vehicle" fields, world VIN decode, live code chips, scan file name, busy state.
(() => {
  const f = document.querySelector('[data-diag-form]'); if (!f) return;
  const pick = f.querySelector('[data-veh-pick]'), other = f.querySelector('[data-other-veh]');
  const sync = () => { other.hidden = pick.value !== '0'; };
  pick.addEventListener('change', sync); sync();
  const vin = f.querySelector('[data-dvin]'), out = f.querySelector('[data-dvin-out]');
  f.querySelector('[data-dvin-go]').addEventListener('click', async () => {
    out.textContent = 'Looking up…';
    try {
      const r = await fetch('index.php?p=vin&vin=' + encodeURIComponent(vin.value.trim()), { credentials: 'same-origin' }).then(r => r.json());
      ['year', 'make', 'model', 'engine', 'trim', 'country'].forEach(k => { if (r[k] && f.elements[k]) f.elements[k].value = r[k]; });
      out.textContent = (r.make ? 'Found: ' + [r.year, r.make, r.model, r.engine].filter(Boolean).join(' ') + (r.country ? ' · built in ' + r.country : '') + '. ' : '') + (r.error || '');
    } catch { out.textContent = 'VIN service not reachable: fill in the vehicle yourself.'; }
  });
  const ta = f.querySelector('[data-codes-in]'), chips = f.querySelector('[data-code-chips]');
  const draw = () => {
    const m = [...new Set((ta.value.toUpperCase().match(/(?<![A-Z0-9])[PBCU][0-3][0-9A-F]{3}(?![0-9A-Z])/g) || []))];
    chips.innerHTML = m.map(c => '<span class="code">' + c + '</span>').join(' ') + (m.length ? ' <small class="muted">' + m.length + ' code' + (m.length > 1 ? 's' : '') + ' found</small>' : '');
  };
  ta.addEventListener('input', draw); draw();
  const file = f.querySelector('[data-scan-file]');
  file.addEventListener('change', () => { const n = file.files[0]?.name; if (n) f.querySelector('.snap-sm .snap-empty b').textContent = '📄 ' + n + ' (ready)'; });
  f.addEventListener('submit', () => { const b = f.querySelector('[data-diag-go]'); b.disabled = true; b.textContent = file.files.length ? 'Reading the scan…' : 'Analyzing…'; });
})();

// My tools: open each subscription in its own named window, side by side with the app.
(() => {
  document.querySelectorAll('[data-tool-url]').forEach(b => b.addEventListener('click', () => {
    const w = Math.min(1200, Math.round(screen.availWidth * 0.6)), h = Math.round(screen.availHeight * 0.9);
    const win = window.open(b.dataset.toolUrl, 'tool' + b.dataset.toolId, `popup,width=${w},height=${h},left=${screen.availWidth - w},top=0`);
    if (win) { try { win.opener = null; } catch {} } else location.href = b.dataset.toolUrl;
  }));
})();

// Shop: parts finder, rename prompts, blank-service helper, customer → vehicle filter.
(() => {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dlg = document.getElementById('parts-dlg');
  if (dlg) {
    const links = JSON.parse(document.getElementById('parts-links-json').textContent);
    const qIn = dlg.querySelector('[data-parts-q]'), cat = dlg.querySelector('[data-parts-catalog]'), lk = dlg.querySelector('[data-parts-links]');
    const run = async q => {
      const enc = encodeURIComponent(q);
      lk.innerHTML = Object.entries(links).map(([g, items]) => '<div class="pl-grp"><small>' + esc(g) + '</small><div>' + items.map(([n, u]) => '<a class="btn sm" target="_blank" rel="noopener" href="' + esc(u.replace(/__Q__/gi, enc)) + '">' + esc(n) + ' ↗</a>').join('') + '</div></div>').join('');
      cat.innerHTML = q ? '<p class="muted">Searching your catalog…</p>' : '';
      if (!q) return;
      try {
        const r = await fetch('index.php?p=parts_q&q=' + enc, { credentials: 'same-origin' }).then(r => r.json());
        cat.innerHTML = r.items.length ? '<small class="muted">In your catalog</small>' + r.items.map(p => '<button type="button" class="pc-item" data-pick=\'' + esc(JSON.stringify(p)) + '\'><b>' + esc(p.name) + '</b><span>' + esc([p.brand, p.part_no].filter(Boolean).join(' ')) + '</span><span>' + esc(p.sell_fmt) + ' · cost ' + esc(p.cost_fmt) + ' · stock ' + esc(p.qty) + '</span></button>').join('') : '<p class="muted" style="font-size:13px">Not in your catalog. Check a supplier below, then fill in the form.</p>';
      } catch { cat.innerHTML = ''; }
    };
    document.querySelectorAll('[data-parts-open]').forEach(b => b.addEventListener('click', () => {
      dlg.querySelector('[data-parts-grp]').value = b.dataset.grp || 'General';
      dlg.querySelector('[data-parts-cat]').value = '';
      qIn.value = b.dataset.q || ''; dlg.querySelector('[data-parts-desc]').value = b.dataset.q || '';
      dlg.showModal(); run(qIn.value.trim()); qIn.focus();
    }));
    dlg.querySelector('[data-parts-form]').addEventListener('submit', e => { e.preventDefault(); run(qIn.value.trim()); });
    cat.addEventListener('click', e => {
      const b = e.target.closest('[data-pick]'); if (!b) return;
      const p = JSON.parse(b.dataset.pick), f = dlg.querySelector('.parts-manual');
      f.elements.catalog_id.value = p.id; f.elements.description.value = p.name; f.elements.part_no.value = p.part_no || ''; f.elements.brand.value = p.brand || '';
      f.elements.supplier.value = p.supplier || 'Stock'; f.elements.cost.value = p.cost; f.elements.price.value = p.sell;
      f.elements.description.focus();
    });
  }
  document.querySelectorAll('[data-prompt]').forEach(b => b.addEventListener('click', e => {
    const v = prompt(b.dataset.prompt); if (!v) { e.preventDefault(); return; }
    b.form.querySelector('[name="' + b.dataset.promptField + '"]').value = v;
  }));
  document.querySelectorAll('[data-copy-to]').forEach(i => i.form.addEventListener('submit', () => { i.form.elements[i.dataset.copyTo].value = i.value; }));
  document.querySelectorAll('[data-kind-pick]').forEach(s => s.addEventListener('change', () => {
    const l = s.form.querySelector('[data-auto-lbl]'); if (l) l.textContent = s.value === 'labor' ? 'shop labor rate' : (s.value === 'part' ? 'matrix' : 'cost');
  }));
  document.querySelectorAll('[data-cust-pick]').forEach(cp => { const vs = cp.form?.elements.vehicle_id; if (!vs) return; const sync = () => { [...vs.options].forEach(o => { if (o.value) o.hidden = cp.value !== '' && o.dataset.cust !== cp.value; }); }; cp.addEventListener('change', sync); sync(); });
  document.querySelectorAll('[data-autosubmit]').forEach(s => s.addEventListener('change', () => s.form.requestSubmit ? s.form.requestSubmit() : s.form.submit()));
})();


// Scan the VIN or plate: photo from the phone camera. Door-frame barcodes (Code 39 / Data Matrix / QR) are decoded on the
// phone; anything else (printed VIN, windshield plate, registration, licence plate) goes to the server's photo reader.
(() => {
  const widgets = document.querySelectorAll('[data-idscan]'); if (!widgets.length) return;
  const vinOf = t => { const m = String(t || '').toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/^I(?=[A-HJ-NPR-Z0-9]{17}$)/, '').match(/[A-HJ-NPR-Z0-9]{17}/); return m ? m[0] : ''; };
  let zx = null;
  const loadZXing = () => zx || (zx = new Promise((ok, no) => {
    const sc = document.createElement('script');
    sc.src = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';
    sc.integrity = 'sha384-BzBxP10ZE72aitqj5UMmUsbKFliP/DZqA8Wq+BNNhlIJDGoEd1tpkMYXOg9+n6sB'; sc.crossOrigin = 'anonymous';
    sc.onload = () => window.ZXing ? ok(window.ZXing) : no(); sc.onerror = no; document.head.appendChild(sc);
  }));
  const shrink = (file, max) => new Promise(ok => {
    const img = new Image(), u = URL.createObjectURL(file);
    img.onload = () => { const k = Math.min(1, max / Math.max(img.width, img.height)), c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(u); c.toBlob(b => ok({ blob: b || file, url: c.toDataURL('image/jpeg', .9) }), 'image/jpeg', .88); };
    img.onerror = () => { URL.revokeObjectURL(u); ok({ blob: file, url: '' }); };
    img.src = u;
  });
  async function barcode(file, small) {
    try {
      if ('BarcodeDetector' in window) {
        const sup = await BarcodeDetector.getSupportedFormats();
        const want = ['code_39', 'code_128', 'data_matrix', 'qr_code', 'pdf417'].filter(f => sup.includes(f));
        if (want.length) { const res = await new BarcodeDetector({ formats: want }).detect(await createImageBitmap(file)); for (const x of res) { const v = vinOf(x.rawValue); if (v) return v; } }
      }
    } catch (e) {}
    try {
      if (!small.url) return '';
      const Z = await loadZXing(), hints = new Map();
      hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.DATA_MATRIX, Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.PDF_417]);
      hints.set(Z.DecodeHintType.TRY_HARDER, true);
      const r = await new Z.BrowserMultiFormatReader(hints).decodeFromImageUrl(small.url);
      return vinOf(r.getText());
    } catch (e) { return ''; }
  }
  const fill = (form, r) => {
    if (!form || form.dataset.idscanCustom) return;
    const set = (n, v) => { const el = form.elements[n]; if (el && v !== undefined && v !== null && v !== '' && !(el instanceof RadioNodeList)) el.value = v; };
    ['vin', 'year', 'make', 'model', 'engine', 'trim', 'plate', 'country'].forEach(k => set(k, r[k]));
    if (r.state) set('state', r.state);
    const vt = [r.year, r.make, r.model].filter(Boolean).join(' '); if (vt) set('vehicle_text', vt + (r.trim ? ' ' + r.trim : ''));
    if (r.kind && form.elements.kind) { const k = form.querySelector(`[name=kind][value="${r.kind}"]`); if (k) k.checked = true; else if (form.elements.kind.tagName === 'SELECT') form.elements.kind.value = r.kind; }
  };
  widgets.forEach(w => w.querySelectorAll('[data-idscan-file]').forEach(inp => inp.addEventListener('change', async () => {
    const file = inp.files[0]; if (!file) return;
    const kind = inp.dataset.idscanFile, out = w.querySelector('[data-idscan-out]'), form = w.closest('form');
    const say = (t, cls) => { out.className = 'idscan-out ' + (cls || 'muted'); out.textContent = t; };
    w.classList.add('busy'); say(kind === 'vin' ? 'Looking for the VIN barcode…' : 'Reading the plate…');
    try {
      const small = await shrink(file, 1800);
      const fd = new FormData(); fd.append('_csrf', w.dataset.csrf); fd.append('kind', kind); if (w.dataset.book) fd.append('book', w.dataset.book);
      const code = kind === 'vin' ? await barcode(file, small) : '';
      if (code) fd.append('barcode', code); else { say(kind === 'vin' ? 'Reading the VIN from the photo (about 15 seconds)…' : 'Reading the plate (about 15 seconds)…'); fd.append('photo', small.blob, 'scan.jpg'); }
      const r = await fetch('index.php?p=scan_id', { method: 'POST', body: fd, credentials: 'same-origin' }).then(x => x.json());
      if (r.error && !r.vin && !r.plate) { say(r.error, 'err'); return; }
      fill(form, r);
      const veh = [r.year, r.make, r.model, r.trim].filter(Boolean).join(' ');
      say((r.vin ? (veh ? 'Found: ' + veh + ' · ' : 'VIN ') + r.vin + (r.via === 'barcode' ? ' (barcode)' : '') : '') + (r.plate ? (r.vin ? ' · ' : '') + 'Plate ' + r.plate + (r.state ? ' (' + r.state + ')' : r.region ? ' (' + r.region + ')' : '') : '')
        + (r.vin && r.checked === false ? '. Check the VIN against the sticker: one character may be misread.' : '') + (r.hint ? ' ' + r.hint : '') + (r.error && r.vin ? ' ' + r.error : ''), 'ok');
      w.dispatchEvent(new CustomEvent('idscan', { detail: r, bubbles: true }));
    } catch (e) { say('Could not read it right now. Type it in instead.', 'err'); }
    finally { w.classList.remove('busy'); inp.value = ''; }
  })));
})();

// Time clock: pick a job+service, live timers, gentle auto-refresh; calendar sheet open/prefill.
(() => {
  document.querySelectorAll('[data-job-pick]').forEach(sel => {
    const f = sel.form;
    f.addEventListener('submit', e => {
      const btn = e.submitter, [jid, grp] = (sel.value || '|').split('|');
      f.elements.job_id.value = jid || ''; f.elements.grp.value = grp || '';
      if (btn && btn.hasAttribute('data-needs-job') && !jid) { e.preventDefault(); alert('Pick the repair order (and service) to start first.'); sel.focus(); }
    });
  });
  const since = document.querySelectorAll('[data-since]');
  if (since.length) setInterval(() => since.forEach(el => { const m = Math.floor((Date.now() / 1000 - +el.dataset.since) / 60); el.textContent = Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0'); }), 20000);
  const now = document.querySelector('[data-clock-now]');
  if (now) setInterval(() => { now.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }, 15000);
  const ar = document.querySelector('[data-autorefresh]');
  if (ar) { let touched = false; document.addEventListener('input', () => { touched = true; }); document.addEventListener('focusin', () => { touched = true; });
    setInterval(() => { if (!touched && document.visibilityState === 'visible') location.reload(); }, +ar.dataset.autorefresh * 1000); }
  document.querySelectorAll('dialog[data-open-now]').forEach(d => d.showModal && d.showModal());
  document.querySelectorAll('[data-appt-date]').forEach(b => b.addEventListener('click', () => { const d = document.querySelector('#sheet-appt [name=date]'); if (d) d.value = b.dataset.apptDate; }));
})();
