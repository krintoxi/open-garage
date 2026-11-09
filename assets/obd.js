// Read trouble codes from a Bluetooth Low Energy (BLE) ELM327 OBD-II adapter, straight from the browser (Web Bluetooth:
// Chrome on Android and on computers; on iPhone the Bluefy browser). Reads: check-engine light and code count (01 01),
// stored codes (03), pending codes (07), VIN (09 02) and, on cars that report it, the odometer (01 A6).
// The parsing below is plain functions (GarageOBDParse) so it can be tested without an adapter.
(() => {
  'use strict';
  const HEX = '0123456789ABCDEF';

  /* ---------- Parsing ELM327 replies ---------- */
  const hexBytes = s => {
    const t = String(s).replace(/\s+/g, '');
    if (!t || !/^[0-9A-F]+$/i.test(t) || t.length % 2) return [];
    const out = []; for (let i = 0; i < t.length; i += 2) out.push(parseInt(t.substr(i, 2), 16)); return out;
  };
  /** Reply text -> useful lines (no prompt, echo, "SEARCHING...", bus init chatter). */
  const lines = (raw, cmd = '') => String(raw).replace(/>/g, '').split(/[\r\n]+/).map(s => s.trim())
    .filter(s => s && !/^SEARCHING/i.test(s) && !/^BUS INIT/i.test(s) && s.replace(/\s+/g, '').toUpperCase() !== String(cmd).replace(/\s+/g, '').toUpperCase());
  /** Lines -> messages (one per ECU reply). CAN multi-frame replies ("00A", "0: ..", "1: ..") are joined and trimmed. */
  const messages = ls => {
    const out = []; let cur = null;
    for (const ln of ls) {
      if (/^[0-9A-F]{3}$/i.test(ln)) { cur = { multi: true, len: parseInt(ln, 16), bytes: [] }; out.push(cur); continue; }
      const m = ln.match(/^([0-9A-F]):\s*(.*)$/i);
      if (m) { if (!cur || !cur.multi) { cur = { multi: true, len: 0, bytes: [] }; out.push(cur); } cur.bytes.push(...hexBytes(m[2])); continue; }
      const b = hexBytes(ln);
      if (b.length) { cur = null; out.push({ multi: false, len: 0, bytes: b }); }
    }
    for (const m of out) if (m.len && m.bytes.length > m.len) m.bytes = m.bytes.slice(0, m.len);
    return out;
  };
  const errorOf = raw => { const m = String(raw).match(/NO DATA|UNABLE TO CONNECT|CAN ERROR|BUS ERROR|STOPPED|BUFFER FULL|ERROR|\?/i); return m ? m[0].toUpperCase() : ''; };
  const dtc = (hi, lo) => 'PCBU'[hi >> 6] + ((hi >> 4) & 3) + HEX[hi & 15] + HEX[lo >> 4] + HEX[lo & 15];
  /** Codes from a mode 03 / 07 / 0A reply. isCan: protocol 6-9 (a count byte follows the mode byte). */
  const codes = (raw, modeByte, isCan) => {
    const out = new Set();
    for (const m of messages(lines(raw))) {
      const b = m.bytes; if (b[0] !== modeByte) continue;
      const can = isCan === undefined ? (m.multi || b[1] * 2 === b.length - 2) : isCan;
      const data = can ? b.slice(2, 2 + 2 * b[1]) : b.slice(1);
      for (let i = 0; i + 1 < data.length; i += 2) if (data[i] || data[i + 1]) out.add(dtc(data[i], data[i + 1]));
    }
    return [...out];
  };
  /** 01 01: check-engine light on/off and how many codes are stored. */
  const mil = raw => { for (const m of messages(lines(raw))) { const b = m.bytes; if (b[0] === 0x41 && b[1] === 0x01 && b.length >= 3) return { on: !!(b[2] & 0x80), count: b[2] & 0x7f }; } return null; };
  /** 09 02: the VIN (17 characters, never I, O or Q). */
  const vin = raw => {
    let s = '';
    for (const m of messages(lines(raw))) { const b = m.bytes; if (b[0] === 0x49 && b[1] === 0x02) s += b.slice(3).map(c => (c >= 0x30 && c <= 0x5a ? String.fromCharCode(c) : '')).join(''); }
    const v = s.toUpperCase().replace(/[^A-HJ-NPR-Z0-9]/g, '').match(/[A-HJ-NPR-Z0-9]{17}/);
    return v ? v[0] : '';
  };
  /** 01 A6: odometer in km / 10 (only newer cars report it). Returns miles. */
  const odometer = raw => { for (const m of messages(lines(raw))) { const b = m.bytes; if (b[0] === 0x41 && b[1] === 0xa6 && b.length >= 6) { const km = ((b[2] << 24 >>> 0) + (b[3] << 16) + (b[4] << 8) + b[5]) / 10; return km > 0 ? Math.round(km * 0.621371) : null; } } return null; };
  /** ATDPN answer ("A6" = automatic, protocol 6): protocols 6-9 and A-C are CAN. */
  const isCanProtocol = dpn => { let p = String(dpn).replace(/[^0-9A-F]/gi, '').toUpperCase(); if (p.length === 2 && p[0] === 'A') p = p[1]; return /^[6-9ABC]$/.test(p); };
  globalThis.GarageOBDParse = { hexBytes, lines, messages, codes, mil, vin, odometer, dtc, errorOf, isCanProtocol };

  /* ---------- Talking to the adapter ---------- */
  // Service UUIDs used by the common BLE OBD adapters (HM-10 style FFE0, FFF0, vLinker 18F0, Nordic UART, and others).
  const SERVICES = [0xffe0, 0xfff0, 0x18f0, 0xffb0, 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', '6e400001-b5a3-f393-e0a9-e50e24dcca9e', '0000ae30-0000-1000-8000-00805f9b34fb', 0xbeef];
  class Elm {
    constructor(rx, tx) { this.rx = rx; this.tx = tx; this.buf = ''; this.wait = null; this.dec = new TextDecoder(); this.enc = new TextEncoder();
      rx.addEventListener('characteristicvaluechanged', e => { this.buf += this.dec.decode(e.target.value); if (this.wait && this.buf.includes('>')) { const w = this.wait; this.wait = null; w(this.buf); } }); }
    async send(cmd, ms = 6000) {
      this.buf = '';
      const done = new Promise(ok => { this.wait = ok; setTimeout(() => { if (this.wait) { this.wait = null; ok(this.buf + '>'); } }, ms); });
      const data = this.enc.encode(cmd + '\r');
      for (let i = 0; i < data.length; i += 20) { const part = data.slice(i, i + 20); if (this.tx.properties.writeWithoutResponse) await this.tx.writeValueWithoutResponse(part); else await this.tx.writeValue(part); }
      return done;
    }
  }
  async function connect(log) {
    const dev = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES });
    log('Connecting to ' + (dev.name || 'the adapter') + '…');
    const gatt = await dev.gatt.connect();
    let rx = null, tx = null;
    for (const s of await gatt.getPrimaryServices()) {
      let chars = []; try { chars = await s.getCharacteristics(); } catch (_) { continue; }
      const n = chars.find(c => c.properties.notify || c.properties.indicate), w = chars.find(c => c.properties.write || c.properties.writeWithoutResponse);
      if (n && w) { rx = n; tx = w; break; }
    }
    if (!rx) throw new Error('This adapter does not offer a serial port over Bluetooth Low Energy. It may be a "classic" Bluetooth or Wi-Fi adapter, which browsers cannot use.');
    await rx.startNotifications();
    return { dev, elm: new Elm(rx, tx) };
  }
  async function readCar(elm, log) {
    log('Waking the adapter…');
    const id = await elm.send('ATZ', 4000);
    for (const c of ['ATE0', 'ATL0', 'ATS1', 'ATH0', 'ATSP0']) await elm.send(c, 3000);
    log((id.match(/ELM\d+\s*v[\d.]+/i) || ['Adapter'])[0] + ' ready. Talking to the car (turn the ignition on)…');
    const r0101 = await elm.send('0101', 12000);
    const err = errorOf(r0101);
    if (!mil(r0101)) throw new Error(err === 'UNABLE TO CONNECT' || err === 'NO DATA' ? 'The car did not answer. Turn the ignition on (engine running is best), check the adapter is pushed fully into the port under the dashboard, and try again.' : 'No answer from the car (' + (err || 'no reply') + ').');
    const dpn = (await elm.send('ATDPN', 3000)).replace(/[^0-9A-F]/gi, '');
    const can = isCanProtocol(dpn);
    log('Reading codes…');
    const stored = codes(await elm.send('03', 8000), 0x43, can);
    const pending = codes(await elm.send('07', 8000), 0x47, can);
    log('Reading the VIN and odometer…');
    const v = vin(await elm.send('0902', 8000));
    const odo = odometer(await elm.send('01A6', 5000));
    return { mil: mil(r0101), stored, pending, vin: v, miles: odo, protocol: dpn };
  }
  globalThis.GarageOBD = { connect, readCar, Elm, SERVICES };

  /* ---------- The page (?p=obd) ---------- */
  const root = document.querySelector('[data-obd]'); if (!root) return;
  const $ = s => root.querySelector(s);
  const logEl = $('[data-obd-log]');
  const log = t => { const li = document.createElement('li'); li.textContent = t; logEl.appendChild(li); logEl.hidden = false; };
  if (!navigator.bluetooth) { $('[data-obd-nobt]').hidden = false; $('[data-obd-go]').disabled = true; return; }
  let session = null;
  $('[data-obd-go]').addEventListener('click', async () => {
    const btn = $('[data-obd-go]'); btn.disabled = true; logEl.innerHTML = ''; $('[data-obd-result]').hidden = true; $('[data-obd-err]').hidden = true;
    try {
      session = session && session.dev.gatt.connected ? session : await connect(log);
      const r = await readCar(session.elm, log);
      log('Done.');
      const f = $('[data-obd-form]');
      const all = [...new Set([...r.stored, ...r.pending])];
      f.elements.codes.value = all.join(' ');
      f.elements.freeze.value = ['Read with a Bluetooth OBD adapter (' + new Date().toLocaleString() + ').', 'Check-engine light: ' + (r.mil.on ? 'ON' : 'off') + ', ' + r.mil.count + ' stored code(s) reported.',
        'Stored: ' + (r.stored.join(' ') || 'none') + '.', 'Pending: ' + (r.pending.join(' ') || 'none') + '.', r.vin ? 'VIN: ' + r.vin + '.' : '', r.miles ? 'Odometer: ' + r.miles.toLocaleString() + ' mi.' : ''].filter(Boolean).join('\n');
      if (f.elements.vin_read) f.elements.vin_read.value = r.vin || '';
      if (f.elements.odo_read) f.elements.odo_read.value = r.miles || '';
      $('[data-obd-mil]').textContent = r.mil.on ? 'Check-engine light is ON' : 'Check-engine light is off';
      $('[data-obd-mil]').className = 'obd-mil ' + (r.mil.on ? 'on' : 'off');
      const chips = (list, cls) => list.map(c => '<span class="code ' + cls + '">' + c + '</span>').join(' ');
      $('[data-obd-codes]').innerHTML = all.length ? (r.stored.length ? '<p><b>Stored:</b> ' + chips(r.stored, '') + '</p>' : '') + (r.pending.length ? '<p><b>Pending</b> (seen once, not confirmed yet): ' + chips(r.pending, 'pending') + '</p>' : '') : '<p>No trouble codes stored. 👍</p>';
      $('[data-obd-extra]').textContent = [r.vin ? 'VIN ' + r.vin : '', r.miles ? 'Odometer ' + r.miles.toLocaleString() + ' mi' : ''].filter(Boolean).join(' · ');
      $('[data-obd-see]').hidden = !all.length && !r.mil.on;
      $('[data-obd-clear]').hidden = !all.length;
      $('[data-obd-result]').hidden = false;
    } catch (e) {
      const msg = e && e.name === 'NotFoundError' ? 'No adapter chosen. Plug it in, turn the ignition on, and pick it from the list (often called OBDII, OBDBLE, vLinker, IOS-Vlink or Vgate).' : (e && e.message) || 'Something went wrong talking to the adapter.';
      const el = $('[data-obd-err]'); el.textContent = msg; el.hidden = false;
    } finally { btn.disabled = false; btn.textContent = 'Read again'; }
  });
  $('[data-obd-clear]').addEventListener('click', async () => {
    if (!session || !confirm('Clear the codes? This turns the check-engine light off but does not fix anything. Readiness monitors reset too: the car needs about 50-100 miles of mixed driving before an emissions test. Note the codes first.')) return;
    const r = await session.elm.send('04', 8000);
    alert(/44/.test(r) ? 'Codes cleared. If the problem is still there, the light will come back.' : 'The car did not confirm. Some cars only clear with the engine off and the ignition on.');
  });
})();
