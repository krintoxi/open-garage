<?php
// Read codes with a Bluetooth OBD adapter, in the browser (assets/obd.js), then open the full diagnosis.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Read codes (Bluetooth)';
$pro = is_pro();
$own = vehicles();
$cust = $pro ? rows('SELECT v.*, c.name cname FROM vehicle v JOIN customers c ON c.id=v.customer_id WHERE v.user_id=? ORDER BY c.name, v.year', [uid()]) : [];
$pre = (int)($_GET['vehicle'] ?? ($veh['id'] ?? 0));
?>
<div class="head"><div><h1>Read codes with a Bluetooth adapter</h1><p>Plug a Bluetooth OBD adapter into the port under the dashboard, turn the ignition on, and tap Connect. We read the check-engine light, the stored and pending codes, the VIN and (on cars that report it) the mileage, then explain what they mean.</p></div></div>
<div class="grid g3" data-obd>
  <div style="grid-column:span 2">
    <div class="card">
      <div class="callout warn" data-obd-nobt hidden>This browser can't use Bluetooth. It works in <b>Chrome on Android</b> and in Chrome or Edge on a computer. On an iPhone, open this page in the free <b>Bluefy</b> browser app. Or type the codes on <a href="<?= url('diagnose') ?>">Diagnose</a>.</div>
      <form method="post" data-obd-form><input type="hidden" name="action" value="diag_run"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="source" value="bluetooth">
        <input type="hidden" name="codes"><input type="hidden" name="freeze"><input type="hidden" name="vin_read"><input type="hidden" name="odo_read">
        <label>Which vehicle?</label>
        <select name="vehicle_id"><?php if ($own): ?><optgroup label="<?= $pro ? 'My own' : 'My garage' ?>"><?php foreach ($own as $ov): ?><option value="<?= (int)$ov['id'] ?>" <?= (int)$ov['id'] === $pre ? 'selected' : '' ?>><?= h(vehicle_name($ov)) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
          <?php if ($cust): ?><optgroup label="Customers"><?php foreach ($cust as $cv): ?><option value="<?= (int)$cv['id'] ?>" <?= (int)$cv['id'] === $pre ? 'selected' : '' ?>><?= h(vehicle_name($cv) . ' · ' . $cv['cname']) ?></option><?php endforeach; ?></optgroup><?php endif; ?></select>
        <div style="margin-top:14px"><button type="button" class="primary obd-go" data-obd-go>🔌 Connect adapter</button></div>
        <ol class="obd-log" data-obd-log hidden></ol>
        <div class="callout warn" data-obd-err hidden></div>
        <section class="obd-result" data-obd-result hidden>
          <div class="obd-mil" data-obd-mil></div>
          <div data-obd-codes></div>
          <p class="muted" data-obd-extra></p>
          <div class="row" style="gap:8px;flex-wrap:wrap"><button class="primary" data-obd-see>See what it means →</button><button type="button" class="ghost" data-obd-clear hidden>Clear codes…</button></div>
        </section>
      </form>
    </div>
  </div>
  <div>
    <div class="card"><h2>Which adapters work?</h2>
      <p class="muted" style="font-size:13.5px">Bluetooth Low Energy (BLE, "Bluetooth 4.0") ELM327 adapters, about $20-40: for example Vgate iCar Pro BLE, vLinker MC+ / FS BLE, OBDLink CX, Veepeak OBDCheck BLE, Konnwei BLE.</p>
      <p class="muted" style="font-size:13.5px">Not usable from a browser: older "classic" Bluetooth adapters and Wi-Fi adapters. For those, use the scanner's own app and <a href="<?= url('diagnose') ?>">upload a screenshot</a>.</p></div>
    <div class="card" style="margin-top:14px"><h2>Tips</h2><ul class="fp-ask"><li>Engine running gives the best results; ignition on is enough for most cars.</li><li>Pick the adapter from the list your phone shows (OBDII, OBDBLE, vLinker, IOS-Vlink, Vgate…).</li><li>Unplug the adapter when you're done; some drain the battery.</li></ul></div>
  </div>
</div>
<script src="assets/obd.js?v=1"></script>
