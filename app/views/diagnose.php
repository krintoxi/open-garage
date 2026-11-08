<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Diagnose';
$rid = (int)($_GET['r'] ?? 0);
$report = $rid && owns_row('diag_reports', $rid) ? row('SELECT * FROM diag_reports WHERE id=?', [$rid]) : null;
if ($rid && !$report) { http_response_code(404); echo '<div class="empty">Report not found.</div>'; return; }
$pro = is_pro();
$own = vehicles();
$custVeh = $pro ? rows('SELECT v.*, c.name cname FROM vehicle v JOIN customers c ON c.id=v.customer_id WHERE v.user_id=? ORDER BY c.name, v.year', [uid()]) : [];
$customers = $pro ? rows('SELECT id, name FROM customers WHERE user_id=? ORDER BY name', [uid()]) : [];
$history = rows('SELECT r.*, v.year vy, v.make vm, v.model vmo, c.name cname FROM diag_reports r LEFT JOIN vehicle v ON v.id=r.vehicle_id LEFT JOIN customers c ON c.id=r.customer_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 15', [uid()]);
$preCodes = implode(' ', obd_extract_codes((string)($_GET['codes'] ?? '')));
$preV = (int)($_GET['vehicle'] ?? ($veh['id'] ?? 0));
$canPhoto = VISION_URL !== '';
?>
<div class="head"><div><h1>Diagnose</h1><p><?= $pro ? 'Codes, freeze frame and symptoms in; ranked causes, test plans, known patterns and owner data out.' : 'Type the codes your scanner showed (or upload the scan, or a photo of the screen). You get what it means, if it is safe to drive, what to check yourself and what to tell the shop.' ?></p></div>
  <form method="post" class="mode-switch"><input type="hidden" name="action" value="mode_set"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
    <button name="mode" value="diy" class="<?= $pro ? '' : 'on' ?>">DIY</button><button name="mode" value="pro" class="<?= $pro ? 'on' : '' ?>">Mechanic</button></form>
</div>

<?php if ($report):
    $V = $report['vehicle_id'] ? (row('SELECT * FROM vehicle WHERE id=?', [(int)$report['vehicle_id']]) ?? []) : (json_decode((string)$report['vehicle_json'], true) ?: []);
    $A = diag_analyze($V, json_decode((string)$report['codes'], true) ?: [], (string)$report['freeze'], json_decode((string)$report['symptoms'], true) ?: []);
    $public = false; ?>
  <div class="row no-print diag-actions">
    <?php if ($report['vehicle_id'] && $A['codes']): ?>
      <form method="post" class="row"><input type="hidden" name="action" value="diag_apply"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$report['id'] ?>">
        <label class="inline"><input type="checkbox" name="issues" value="1" checked> also open an issue per code</label><button class="primary sm" type="submit">Save codes to <?= h(vehicle_name($V)) ?></button></form>
    <?php elseif (!$report['vehicle_id']): ?>
      <form method="post" class="row"><input type="hidden" name="action" value="diag_to_vehicle"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$report['id'] ?>">
        <?php if ($pro && $customers): ?><select name="customer_id" class="mini"><option value="">My own vehicle</option><?php foreach ($customers as $cu): ?><option value="<?= (int)$cu['id'] ?>" <?= (int)$report['customer_id'] === (int)$cu['id'] ? 'selected' : '' ?>>Customer: <?= h($cu['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <input type="number" name="miles" placeholder="Odometer" class="mini" style="width:110px"><button class="sm" type="submit">Add this vehicle to <?= $pro ? 'the garage' : 'my garage' ?></button></form>
    <?php endif; ?>
    <?php if ($pro): ?><form method="post"><input type="hidden" name="action" value="job_new"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="diag_report_id" value="<?= (int)$report['id'] ?>"><button class="sm primary">Create estimate from this</button></form><?php endif; ?>
    <button type="button" class="sm" onclick="print()">Print / PDF report</button>
    <a class="btn sm ghost" href="<?= url('diagnose') ?>">New diagnosis</a>
    <form method="post" style="margin-left:auto"><input type="hidden" name="action" value="diag_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$report['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this report?">Delete</button></form>
  </div>
  <?php if (str_contains((string)$report['source'], 'photo')): ?><div class="callout photo-read"><b>Read from your photo by our vision model.</b> Check the codes against your screen<?= $A['codes'] ? ': ' . h(implode(', ', $A['codes'])) : '' ?>. Wrong? <a href="<?= url('diagnose', ['codes' => implode(' ', $A['codes']), 'vehicle' => (int)$report['vehicle_id']]) ?>">Correct them and re-run →</a></div><?php endif; ?>
  <?php if ($report['notes']): ?><div class="callout"><b>Notes:</b> <?= h($report['notes']) ?></div><?php endif; ?>
  <?php require APP_ROOT . '/app/views/_diag_result.php'; ?>
<?php else: ?>
<div class="grid g3">
  <form method="post" enctype="multipart/form-data" class="card diag-form" style="grid-column:span 2" data-diag-form>
    <input type="hidden" name="action" value="diag_run"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <h2>1 · Vehicle</h2>
    <select name="vehicle_id" data-veh-pick>
      <?php if ($own): ?><optgroup label="My garage"><?php foreach ($own as $ov): ?><option value="<?= (int)$ov['id'] ?>" <?= (int)$ov['id'] === $preV ? 'selected' : '' ?>><?= h(vehicle_name($ov)) ?><?= $ov['nickname'] ? ' · ' . h($ov['nickname']) : '' ?></option><?php endforeach; ?></optgroup><?php endif; ?>
      <?php if ($custVeh): $last = null; foreach ($custVeh as $cv): if ($cv['cname'] !== $last): if ($last !== null) echo '</optgroup>'; $last = $cv['cname']; ?><optgroup label="Customer: <?= h($cv['cname']) ?>"><?php endif; ?>
        <option value="<?= (int)$cv['id'] ?>" <?= (int)$cv['id'] === $preV ? 'selected' : '' ?>><?= h(vehicle_name($cv)) ?><?= $cv['plate'] ? ' · ' . h($cv['plate']) : '' ?></option><?php endforeach; echo '</optgroup>'; endif; ?>
      <option value="0" <?= !$own && !$custVeh ? 'selected' : '' ?>>Another vehicle (VIN or year / make / model)…</option>
    </select>
    <div class="other-veh" data-other-veh hidden>
      <div class="row" style="flex-wrap:nowrap"><input type="text" name="vin" placeholder="VIN (17 characters), any country" maxlength="20" autocapitalize="characters" data-dvin><button type="button" class="sm" data-dvin-go>Decode</button></div>
      <small class="muted" data-dvin-out></small>
      <div class="form-grid"><div><label>Year</label><input type="number" name="year" min="1950" max="<?= (int)date('Y') + 1 ?>"></div><div><label>Make</label><input type="text" name="make" placeholder="Toyota, Peugeot, Tata, BYD…"></div>
        <div><label>Model</label><input type="text" name="model"></div><div><label>Engine (optional)</label><input type="text" name="engine" placeholder="2.0 L, 1.6 HDi, M272…"></div></div>
      <input type="hidden" name="trim"><input type="hidden" name="country">
      <?php if ($pro && $customers): ?><label>For customer (optional)</label><select name="customer_id"><option value="">—</option><?php foreach ($customers as $cu): ?><option value="<?= (int)$cu['id'] ?>"><?= h($cu['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    </div>

    <h2 style="margin-top:18px">2 · Codes</h2>
    <textarea name="codes" rows="3" placeholder="P0171 P0174 P0300 — or paste the whole report from your scanner app" data-codes-in><?= h($preCodes) ?></textarea>
    <div class="code-chips" data-code-chips></div>
    <label class="snap snap-sm"><input type="file" name="scan" accept="image/*,application/pdf,.pdf,.txt,.csv,text/plain,text/csv" data-scan-file>
      <span class="snap-empty"><b>⇪ Upload a scan report<?= $canPhoto ? ' or a photo of the scanner screen' : '' ?></b><small>PDF or text/CSV export from your scanner or app (BlueDriver, Torque, OBD Fusion, Car Scanner, Autel, Launch…)<?= $canPhoto ? '. Photos are read by our own private vision model.' : '' ?></small></span></label>

    <details style="margin-top:12px" <?= $pro ? 'open' : '' ?>><summary><b>Freeze frame / live data</b> <span class="muted">(optional, makes the analysis much sharper)</span></summary>
      <textarea name="freeze" rows="5" placeholder="Paste lines like:&#10;Engine RPM: 720&#10;Short term fuel trim bank 1: 6.3 %&#10;Long term fuel trim bank 1: 18.0 %&#10;Coolant temperature: 88 °C&#10;MAF: 2.1 g/s&#10;Control module voltage: 13.9 V"></textarea>
    </details>

    <h2 style="margin-top:18px">3 · What you notice <span class="muted" style="font-size:13px;text-transform:none">(optional)</span></h2>
    <div class="quick-pick"><?php foreach (DIAG_SYMPTOMS as $k => $lbl): ?><label><input type="checkbox" name="sym[]" value="<?= h($k) ?>"><span><?= h($lbl) ?></span></label><?php endforeach; ?></div>
    <?php if ($pro): ?><label>Notes (complaint, customer's words, work order)</label><input type="text" name="notes" maxlength="500"><?php endif; ?>
    <div class="row end" style="margin-top:14px"><button class="primary" type="submit" data-diag-go>Diagnose</button></div>
  </form>
  <div class="aside">
    <div class="card">
      <h2>Repair videos</h2>
      <form class="row" data-yt-search style="flex-wrap:nowrap"><input type="search" placeholder="e.g. <?= h(trim(($veh['year'] ?? '') . ' ' . ($veh['model'] ?? '') . ' spark plugs')) ?>" data-yt-q value="<?= h(trim(($veh['year'] ?? '') . ' ' . ($veh['make'] ?? '') . ' ' . ($veh['model'] ?? ''))) ?> "><button class="sm" type="submit">Search</button></form>
      <div class="yt-grid yt-side" data-yt-out></div>
    </div>
    <div class="card" style="margin-top:18px">
      <h2>Past diagnoses</h2>
      <?php if (!$history): ?><div class="empty">None yet.</div><?php endif; ?>
      <ul class="list"><?php foreach ($history as $hr): $hv = $hr['vehicle_id'] ? trim($hr['vy'] . ' ' . $hr['vm'] . ' ' . $hr['vmo']) : trim(implode(' ', array_slice(json_decode((string)$hr['vehicle_json'], true) ?: [], 0, 3))); ?>
        <li><div class="grow"><a class="t" href="<?= url('diagnose', ['r' => $hr['id']]) ?>"><?= h(implode(' ', array_slice(json_decode((string)$hr['codes'], true) ?: [], 0, 4)) ?: 'Symptoms only') ?></a><small><?= h($hv ?: 'vehicle not set') ?><?= $hr['cname'] ? ' · ' . h($hr['cname']) : '' ?> · <?= fmt_date($hr['created_at']) ?></small></div></li>
      <?php endforeach; ?></ul>
    </div>
    <div class="card" style="margin-top:18px">
      <h3>No scanner?</h3>
      <p class="muted" style="font-size:13px">Any car sold since about 1996 (US), 2001 (EU petrol) or 2004 (EU diesel) has an OBD-II/EOBD port under the dashboard. A $15-30 Bluetooth adapter with a free phone app reads the engine codes; most auto-parts stores read them free. Transmission, ABS and airbag codes need a brand-capable scanner.</p>
    </div>
  </div>
</div>
<div id="ytplayer" class="ytplayer" hidden><div><button type="button" aria-label="Close">×</button><iframe title="Video" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div></div>
<?php endif; ?>
