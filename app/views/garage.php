<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Garage';
$list = vehicles();
$stats = [];
foreach ($list as $gv) {
    $id = (int)$gv['id'];
    $stats[$id] = [
        'open' => (int)col("SELECT COUNT(*) FROM issues WHERE vehicle_id=? AND status IN ('open','monitoring','scheduled')", [$id]),
        'sev' => (int)col("SELECT COALESCE(MAX(severity),0) FROM issues WHERE vehicle_id=? AND status IN ('open','monitoring','scheduled')", [$id]),
        'due' => count(array_filter(rows('SELECT * FROM maintenance WHERE vehicle_id=?', [$id]), fn($m) => in_array(maintenance_status($m, (int)$gv['mileage'])[0], ['overdue', 'soon'], true))),
    ];
}
?>
<div class="head"><div><h1>Garage</h1><p><?= $list ? 'Every vehicle you look after. Tap one to work on it.' : 'Welcome. Add your first vehicle below to get started.' ?></p></div><a class="btn primary" href="#add">+ Add a vehicle</a></div>
<div class="garage-grid">
  <?php foreach ($list as $gv): $id = (int)$gv['id']; $st = $stats[$id]; $kind = $gv['kind'] ?: 'car'; ?>
  <a class="vcard <?= $id === (int)($veh['id'] ?? 0) ? 'on' : '' ?>" href="<?= url('dashboard', ['v' => $id]) ?>" data-tilt>
    <div class="vcard-art">
      <?php if ($gv['photo']): ?><img src="<?= h(photo_url($gv['photo'])) ?>" alt="" loading="lazy"><?php else: ?><?= vehicle_art_svg($kind, $gv['color']) ?><?php endif; ?>
      <?php if ($gv['model3d']): ?><span class="badge3d">3D</span><?php endif; ?>
    </div>
    <div class="vcard-body">
      <small><?= h(VEHICLE_KINDS[$kind] ?? 'Vehicle') ?><?= $gv['nickname'] ? ' · ' . h($gv['nickname']) : '' ?></small>
      <b><?= h(vehicle_name($gv)) ?></b>
      <div class="vcard-stats">
        <span><?= number_format((int)$gv['mileage']) ?> mi</span>
        <span class="<?= $st['sev'] >= 4 ? 'hot' : '' ?>"><?= $st['open'] ?> open issue<?= $st['open'] === 1 ? '' : 's' ?></span>
        <span class="<?= $st['due'] ? 'warn' : '' ?>"><?= $st['due'] ?> due</span>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<div class="card" id="add" style="margin-top:22px">
  <h2>Add a vehicle</h2>
  <p class="muted" style="margin-top:0">Fastest: type the 17-character VIN and tap <b>Look up VIN</b>. It fills in the year, make, model and engine from the U.S. government (NHTSA) database. Or fill the boxes yourself. Add a photo now or later. Without one the app tries to find a matching photo of the same model, and otherwise draws a stand-in in your colours.</p>
  <form method="post" enctype="multipart/form-data" data-vehicle-add>
    <input type="hidden" name="action" value="vehicle_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <div class="vin-row"><div class="grow"><label>VIN (optional)</label><input type="text" name="vin" maxlength="17" autocapitalize="characters" autocomplete="off" placeholder="e.g. 1HGCM82633A004352" class="mono" data-vin></div><button type="button" class="btn" data-vin-lookup>Look up VIN</button></div>
    <div class="vin-result muted" data-vin-result></div>
    <div class="form-grid">
      <div><label>Type</label><select name="kind"><?php foreach (VEHICLE_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Year</label><input type="number" name="year" min="1900" max="2100" inputmode="numeric"></div>
      <div><label>Make</label><input type="text" name="make" required placeholder="Honda, Italika, Mercedes-Benz"></div>
      <div><label>Model</label><input type="text" name="model" required placeholder="Civic, Spektre 50, C300"></div>
      <div><label>Trim / version</label><input type="text" name="trim"></div>
      <div><label>Engine</label><input type="text" name="engine" placeholder="50cc 4-stroke, 3.0 L V6"></div>
      <div><label>Color</label><input type="text" name="color" placeholder="White and black"></div>
      <div><label>Odometer (miles)</label><input type="number" name="miles" min="0" inputmode="numeric"></div>
      <div><label>Nickname (optional)</label><input type="text" name="nickname"></div>
      <div><label>Plate (optional)</label><input type="text" name="plate"></div>
      <div class="full"><label>Photo (optional, side or 3/4 view looks best)</label><input type="file" name="photo" accept="image/*,.heic"></div>
      <input type="hidden" name="transmission">
    </div>
    <div class="row end" style="margin-top:14px"><button class="primary" type="submit">Add to garage</button></div>
  </form>
</div>
