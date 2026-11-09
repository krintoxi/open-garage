<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Customers';
$cid = (int)($_GET['c'] ?? 0);
$cu = $cid && owns_row('customers', $cid) ? row('SELECT * FROM customers WHERE id=?', [$cid]) : null;
$qq = trim((string)($_GET['q'] ?? ''));
$list = rows("SELECT c.*, (SELECT COUNT(*) FROM vehicle v WHERE v.customer_id=c.id) nveh, (SELECT MAX(created_at) FROM diag_reports r WHERE r.customer_id=c.id) last_diag
  FROM customers c WHERE c.user_id=?" . ($qq !== '' ? ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR EXISTS (SELECT 1 FROM vehicle v WHERE v.customer_id=c.id AND (v.plate LIKE ? OR v.vin LIKE ? OR v.model LIKE ?)))' : '') . ' ORDER BY c.name',
  $qq !== '' ? [uid(), ...array_fill(0, 6, "%$qq%")] : [uid()]);
?>
<div class="head"><div><h1>Customers</h1><p>Mechanic mode: each customer's vehicles, diagnoses and history in one place. Customer details stay in your account only.</p></div>
  <?php if (!is_pro()): ?><form method="post"><input type="hidden" name="action" value="mode_set"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= url('customers') ?>"><button name="mode" value="pro" class="primary sm">Switch to Mechanic mode</button></form><?php endif; ?></div>
<div class="grid g3">
  <div class="card">
    <form method="get" class="row" style="flex-wrap:nowrap;margin-bottom:10px"><input type="hidden" name="p" value="customers"><input type="search" name="q" value="<?= h($qq) ?>" placeholder="Name, phone, plate, VIN…"><button class="sm">Find</button></form>
    <a class="btn sm primary" href="<?= url('customers', ['c' => 'new']) ?>#cform" style="margin-bottom:10px">+ New customer</a>
    <?php if (!$list): ?><div class="empty"><?= $qq !== '' ? 'No match.' : 'No customers yet.' ?></div><?php endif; ?>
    <ul class="list"><?php foreach ($list as $c): ?><li class="<?= $cu && (int)$cu['id'] === (int)$c['id'] ? 'on' : '' ?>"><div class="grow"><a class="t" href="<?= url('customers', ['c' => $c['id']]) ?>"><?= h($c['name']) ?></a><small><?= (int)$c['nveh'] ?> vehicle<?= (int)$c['nveh'] === 1 ? '' : 's' ?><?= $c['phone'] ? ' · ' . h($c['phone']) : '' ?><?= $c['last_diag'] ? ' · last diag ' . fmt_date($c['last_diag']) : '' ?></small></div></li><?php endforeach; ?></ul>
  </div>
  <div style="grid-column:span 2">
    <?php if ($cu): $cveh = rows('SELECT * FROM vehicle WHERE customer_id=? AND user_id=? ORDER BY id', [$cu['id'], uid()]); $cdiag = rows('SELECT * FROM diag_reports WHERE customer_id=? AND user_id=? ORDER BY id DESC LIMIT 30', [$cu['id'], uid()]); ?>
      <div class="card">
        <h2><?= h($cu['name']) ?></h2>
        <p class="muted"><?= $cu['phone'] ? '<a href="tel:' . h(preg_replace('/[^\d+]/', '', $cu['phone'])) . '">' . h($cu['phone']) . '</a>' : '' ?><?= $cu['email'] ? ' · <a href="mailto:' . h($cu['email']) . '">' . h($cu['email']) . '</a>' : '' ?></p>
        <?php if ($cu['notes']): ?><p><?= nl2br(h($cu['notes'])) ?></p><?php endif; ?>
        <h3>Vehicles</h3>
        <?php if (!$cveh): ?><div class="empty">No vehicles yet. Add one below (a VIN fills in the rest).</div><?php endif; ?>
        <ul class="list"><?php foreach ($cveh as $v): $hl = vehicle_health((int)$v['id']); ?><li><?= health_ring($hl, 40) ?><div class="grow"><a class="t" href="<?= url('dashboard', ['v' => $v['id']]) ?>"><?= h(vehicle_name($v)) ?></a><small><?= $v['plate'] ? h($v['plate']) . ' · ' : '' ?><?= $v['vin'] ? 'VIN ' . h($v['vin']) . ' · ' : '' ?><?= number_format((int)$v['mileage']) ?> mi</small></div><a class="btn sm" href="<?= url('diagnose', ['vehicle' => $v['id']]) ?>">Diagnose</a><a class="btn sm" href="<?= url('jobs', ['new' => 1, 'customer' => $cu['id'], 'vehicle' => $v['id']]) ?>">Estimate</a><a class="btn sm" href="<?= url('calendar', ['new' => 1, 'customer' => $cu['id'], 'vehicle' => $v['id']]) ?>">Book</a></li><?php endforeach; ?></ul>
        <details style="margin-top:12px"><summary><b>+ Add a vehicle for <?= h($cu['name']) ?></b></summary>
          <form method="post"><input type="hidden" name="action" value="customer_vehicle_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="customer_id" value="<?= (int)$cu['id'] ?>">
            <?= idscan_widget() ?>
            <label>VIN (fills in year/make/model)</label><input type="text" name="vin" maxlength="20" autocapitalize="characters">
            <div class="form-grid"><div><label>Year</label><input type="number" name="year"></div><div><label>Make</label><input type="text" name="make"></div><div><label>Model</label><input type="text" name="model"></div><div><label>Engine</label><input type="text" name="engine"></div>
              <div><label>Plate</label><input type="text" name="plate"></div><div><label>Odometer</label><input type="number" name="miles"></div></div>
            <select name="kind" style="margin-top:8px"><?php foreach (VEHICLE_KINDS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
            <div class="row end" style="margin-top:10px"><button class="primary sm">Add vehicle</button></div></form></details>
        <?php $cjobs = rows('SELECT * FROM jobs WHERE customer_id=? AND user_id=? ORDER BY id DESC LIMIT 50', [$cu['id'], uid()]); $owed = array_sum(array_map(fn($j) => $j['stage'] === 'invoiced' ? $j['total'] - $j['paid'] : 0, $cjobs)); ?>
        <h3 style="margin-top:16px">Repair orders <?= $owed > 0 ? '<span class="st st-open">' . money($owed) . ' owed</span>' : '' ?></h3>
        <a class="btn sm primary" href="<?= url('jobs', ['new' => 1, 'customer' => $cu['id']]) ?>">+ New estimate</a> <a class="btn sm" href="<?= url('calendar', ['new' => 1, 'customer' => $cu['id']]) ?>">+ Appointment</a>
        <ul class="list"><?php foreach ($cjobs as $j): ?><li><div class="grow"><a class="t" href="<?= url('job', ['id' => $j['id']]) ?>"><?= h(job_label($j)) ?> · <?= h($j['title']) ?></a><small><?= fmt_date($j['created_at']) ?> · <?= h(JOB_STAGES[$j['stage']]) ?></small></div><b><?= money((float)$j['total']) ?></b></li><?php endforeach; ?></ul>
<?php $LG = rows('SELECT l.*, v.year vy, v.make vm, v.model vmo FROM legacy_ros l LEFT JOIN vehicle v ON v.id=l.vehicle_id WHERE l.user_id=? AND l.customer_id=? ORDER BY l.ro_date DESC, l.id DESC LIMIT 200', [uid(), $cu['id']]); if ($LG): ?>
<details class="legacy"><summary><b>Past work from your old system</b> <span class="muted"><?= count($LG) ?></span></summary>
  <ul class="list"><?php foreach ($LG as $x): ?><li><div class="grow"><span class="t"><?= h($x['ro_date'] ? date('M j, Y', strtotime((string)$x['ro_date'])) : 'No date') ?><?= $x['ro_no'] ? ' · #' . h($x['ro_no']) : '' ?><?= $x['vm'] ? ' · ' . h(trim($x['vy'] . ' ' . $x['vm'] . ' ' . $x['vmo'])) : '' ?></span><small><?= h(mb_strimwidth((string)$x['description'], 0, 220, '…')) ?><?= $x['miles'] ? ' · ' . number_format((int)$x['miles']) . ' mi' : '' ?></small></div><?= $x['total'] !== null ? '<b>' . money((float)$x['total']) . '</b>' : '' ?></li><?php endforeach; ?></ul>
  <small class="muted">Imported from <?= h((string)$LG[0]['source']) ?>. Read-only.</small></details>
<?php endif; ?>
        <h3 style="margin-top:16px">Diagnoses</h3>
        <?php if (!$cdiag): ?><div class="empty">None yet.</div><?php endif; ?>
        <ul class="list"><?php foreach ($cdiag as $r): ?><li><div class="grow"><a class="t" href="<?= url('diagnose', ['r' => $r['id']]) ?>"><?= h(implode(' ', json_decode((string)$r['codes'], true) ?: []) ?: 'Symptoms only') ?></a><small><?= fmt_date($r['created_at']) ?><?= $r['notes'] ? ' · ' . h(mb_strimwidth((string)$r['notes'], 0, 80, '…')) : '' ?></small></div></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <div class="card" id="cform" style="margin-top:<?= $cu ? '18px' : '0' ?>">
      <h2><?= $cu ? 'Edit customer' : 'New customer' ?></h2>
      <form method="post"><input type="hidden" name="action" value="customer_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $cu ? (int)$cu['id'] : '' ?>">
        <div class="form-grid"><div><label>Name</label><input type="text" name="name" value="<?= h($cu['name'] ?? '') ?>" required></div><div><label>Phone</label><input type="tel" name="phone" value="<?= h($cu['phone'] ?? '') ?>"></div>
          <div class="full"><label>Email</label><input type="email" name="email" value="<?= h($cu['email'] ?? '') ?>"></div></div>
        <label>Notes</label><textarea name="notes" style="min-height:60px"><?= h($cu['notes'] ?? '') ?></textarea>
        <div class="row end" style="margin-top:10px"><button class="primary">Save</button></div></form>
      <?php if ($cu): ?><form method="post" style="margin-top:10px"><input type="hidden" name="action" value="customer_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$cu['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this customer, their vehicles and their diagnoses? This cannot be undone.">Delete customer</button></form><?php endif; ?>
    </div>
  </div>
</div>
