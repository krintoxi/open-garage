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
<?php $firstRun = !$list && !is_pro() && !(int)col('SELECT COUNT(*) FROM customers WHERE user_id=?', [uid()]); $me = current_user(); ?>
<?php if ($list): ?><div class="head"><div><h1>Garage</h1><p>Every vehicle you look after. Tap one to work on it.</p></div><a class="btn primary" href="#add">+ Add a vehicle</a></div>
<?php else: ?><div class="head welcome"><div><h1>Welcome<?= !empty($me['name']) && !is_guest() ? ', ' . h(strtok((string)$me['name'], ' ')) : '' ?>! 👋</h1><p>Let's add your first vehicle. It takes about a minute.</p></div></div><?php endif; ?>
<?php if ($firstRun): ?>
<div class="card chooser">
  <h2>What brings you here?</h2>
  <div class="chooser-opts">
    <a href="#add" class="chooser-opt" data-chooser-own><span>🚗</span><b>My own vehicles</b><small>Keep track of what's wrong, what's due and what the shop did.</small></a>
    <form method="post"><input type="hidden" name="action" value="mode_set"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= url('jobs') ?>">
      <button name="mode" value="pro" class="chooser-opt"><span>🔧</span><b>I fix vehicles for customers</b><small>Estimates, repair orders, appointments, time clock and invoices.</small></button></form>
  </div>
  <p class="muted" style="font-size:12.5px;margin:10px 0 0">You can switch any time from the menu.</p>
</div>
<?php endif; ?>
<?php if ($list || (int)col('SELECT COUNT(*) FROM diag_reports WHERE user_id=?', [uid()])) require APP_ROOT . '/app/views/_guest_keep.php'; ?>
<?php if (is_pro()): $nc = (int)col('SELECT COUNT(*) FROM customers WHERE user_id=?', [uid()]); ?><div class="callout" style="margin-bottom:14px">Mechanic mode: these are your own vehicles. Customer vehicles live under <a href="<?= url('customers') ?>">Customers</a> (<?= $nc ?>).</div><?php endif; ?>
<div class="garage-grid">
  <?php foreach ($list as $gv): $id = (int)$gv['id']; $st = $stats[$id]; $kind = $gv['kind'] ?: 'car'; ?>
  <a class="vcard <?= $id === (int)($veh['id'] ?? 0) ? 'on' : '' ?>" href="<?= url('dashboard', ['v' => $id]) ?>" data-tilt>
    <div class="vcard-art">
      <?php if ($gv['hero_cutout'] && !$gv['photo_credit']): ?><img class="cut" src="<?= h(photo_url($gv['hero_cutout'])) ?>" alt="" loading="lazy"><?php elseif ($gv['photo']): ?><img src="<?= h(photo_url($gv['photo'])) ?>" alt="" loading="lazy"><?php else: ?><?= vehicle_art_svg($kind, $gv['color']) ?><?php endif; ?>
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
      <?php $gh = vehicle_health($id); ?><div class="vcard-health" title="Health score: <?= h($gh['grade']) ?>"><?= health_ring($gh, 44) ?><span><?= h($gh['grade']) ?></span></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<?php $qAll = quick_issue_templates('car') + quick_issue_templates('scooter'); $carOnly = array_diff_key(quick_issue_templates('car'), quick_issue_templates('scooter')); ?>
<div class="card wizard" id="add">
  <h2><?= $list ? 'Add a vehicle' : 'Your first vehicle' ?></h2>
  <ol class="wz-steps"><li class="on" data-dot="1">What you drive</li><li data-dot="2">Photo</li><li data-dot="3">What's going on</li></ol>
  <form method="post" enctype="multipart/form-data" data-vehicle-add>
    <input type="hidden" name="action" value="vehicle_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <input type="hidden" name="trim"><input type="hidden" name="engine"><input type="hidden" name="transmission">

    <section class="wz-step" data-step="1">
      <div class="kind-pick"><?php foreach (VEHICLE_KINDS as $k => $l): ?><label><input type="radio" name="kind" value="<?= $k ?>" <?= $k === 'car' ? 'checked' : '' ?>><span><?= ['car' => '🚗', 'suv' => '🛻', 'scooter' => '🛵', 'motorcycle' => '🏍️'][$k] ?> <?= h($l) ?></span></label><?php endforeach; ?></div>
      <div class="wz-tabs"><button type="button" class="on" data-tab="scan">📷 Scan it</button><button type="button" data-tab="vin">Type the VIN</button><button type="button" data-tab="pick">Pick from a list</button></div>
      <div data-pane="scan">
        <p style="margin-top:0">The quickest way: take a photo of your VIN and we'll fill in the year, make, model and engine for you.</p>
        <div class="vin-where"><span><b>🚪</b>Driver's door frame</span><span><b>🪟</b>Bottom of the windshield</span><span><b>📄</b>Registration or insurance card</span></div>
        <?= idscan_widget('', true, false) ?>
        <p class="muted" style="font-size:12.5px;margin:0">No VIN handy? Use <b>Pick from a list</b> above instead.</p>
      </div>
      <div data-pane="pick" hidden>
        <div class="form-grid">
          <div><label>Year</label><select name="year" data-year><option value="">Year</option><?php for ($y = (int)date('Y') + 1; $y >= 1981; $y--): ?><option><?= $y ?></option><?php endfor; ?></select></div>
          <div><label>Make</label><select data-make><option value="">Make</option></select><input type="text" name="make" data-make-text placeholder="Type the make" hidden></div>
          <div class="full"><label>Model</label><select data-model disabled><option value="">Pick year and make first</option></select><input type="text" name="model" data-model-text placeholder="Type the model" hidden></div>
        </div>
      </div>
      <div data-pane="vin" hidden>
        <p class="muted" style="margin-top:0">The 17-character VIN is on the driver's door frame, the dashboard by the windshield, or your registration. For scooters and motorcycles it's usually on the frame by the steering head.</p>
        <div class="vin-row"><div class="grow"><label>VIN</label><input type="text" name="vin" maxlength="17" autocapitalize="characters" autocomplete="off" class="mono" data-vin></div><button type="button" class="btn" data-vin-lookup>Look up</button></div>
        <div class="vin-result muted" data-vin-result></div>
      </div>
      <div class="row end wz-nav"><button type="button" class="primary" data-next>Next</button></div>
    </section>

    <section class="wz-step" data-step="2">
      <label class="snap" data-snap><input type="file" name="photo" accept="image/*,.heic" capture="environment" data-photo><span class="snap-empty"><b>📷 Snap or choose a photo</b><small>Side or three-quarter view, the whole vehicle in frame. We'll cut it out and put it on a lit showroom stage.</small></span><img alt="" hidden data-preview></label>
      <div class="row end wz-nav"><button type="button" class="ghost" data-back>Back</button><button type="button" class="ghost" data-next data-skip>Skip for now</button><button type="button" class="primary" data-next hidden data-photo-next>Next</button></div>
    </section>

    <section class="wz-step" data-step="3">
      <p class="muted" style="margin-top:0">Tap anything that applies. Each one becomes an issue with plain-language next steps.</p>
      <div class="quick-pick"><?php foreach ($qAll as $k => [$t]): ?><label data-car-only="<?= isset($carOnly[$k]) ? '1' : '0' ?>"><input type="checkbox" name="quick[]" value="<?= h($k) ?>"><span><?= h($t) ?></span></label><?php endforeach; ?></div>
      <div class="form-grid" style="margin-top:12px">
        <div><label>Odometer (miles)</label><input type="number" name="miles" min="0" inputmode="numeric" placeholder="e.g. 61200"></div>
        <div><label>Color</label><input type="text" name="color" placeholder="e.g. White and black"></div>
        <div><label>Nickname (optional)</label><input type="text" name="nickname"></div>
        <div><label>Plate (optional)</label><input type="text" name="plate"></div>
      </div>
      <div class="row end wz-nav"><button type="button" class="ghost" data-back>Back</button><button class="primary" type="submit">Add to my garage</button></div>
    </section>
  </form>
</div>
