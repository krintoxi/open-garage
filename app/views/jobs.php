<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Shop';
$S = shop();
$qq = trim((string)($_GET['q'] ?? ''));
$where = 'j.user_id=?'; $params = [uid()];
if ($qq !== '') { $where .= ' AND (j.est_no LIKE ? OR j.inv_no LIKE ? OR j.title LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR v.plate LIKE ? OR v.vin LIKE ? OR v.model LIKE ?)'; $params = array_merge($params, array_fill(0, 8, "%$qq%")); }
$all = rows("SELECT j.*, c.name cname, c.phone cphone, v.year vy, v.make vm, v.model vmo, v.plate vplate FROM jobs j LEFT JOIN customers c ON c.id=j.customer_id LEFT JOIN vehicle v ON v.id=j.vehicle_id
  WHERE $where AND (j.stage NOT IN ('paid','void','declined') OR j.updated_at > ?) ORDER BY j.updated_at DESC LIMIT 400", array_merge($params, [date('Y-m-d H:i:s', strtotime('-30 days'))]));
$cols = ['estimate' => ['Estimates', ['estimate']], 'approved' => ['Approved', ['approved']], 'shop' => ['In the shop', ['in_progress', 'waiting_parts']], 'done' => ['Ready', ['done']], 'invoiced' => ['Unpaid invoices', ['invoiced']], 'paid' => ['Paid (30 days)', ['paid']]];
$by = []; foreach ($all as $j) foreach ($cols as $k => [, $st]) if (in_array($j['stage'], $st, true)) $by[$k][] = $j;
// This month at a glance (industry KPIs: sales, car count, average repair order, gross profit).
$month = rows("SELECT * FROM jobs WHERE user_id=? AND stage IN ('invoiced','paid') AND invoiced_at >= ?", [uid(), date('Y-m-01')]);
$sales = 0; $gp = 0; $hours = 0; $labor = 0;
foreach ($month as $mj) { $t = job_totals($mj, job_lines((int)$mj['id']), 'approved'); $sales += $t['total'] - $t['tax']; $gp += $t['profit']; $hours += $t['hours']; $labor += $t['labor']; }
$cars = count($month); $ar = (float)col("SELECT COALESCE(SUM(total-paid),0) FROM jobs WHERE user_id=? AND stage='invoiced'", [uid()]);
$sent = (int)col("SELECT COUNT(*) FROM jobs WHERE user_id=? AND sent_at >= ?", [uid(), date('Y-m-01')]); $won = (int)col("SELECT COUNT(*) FROM jobs WHERE user_id=? AND sent_at >= ? AND approved_at IS NOT NULL", [uid(), date('Y-m-01')]);
$news = rows("SELECT id, est_no, approved_by FROM jobs WHERE user_id=? AND seen_approval=0", [uid()]);
$customers = rows('SELECT id, name FROM customers WHERE user_id=? ORDER BY name', [uid()]);
?>
<div class="head"><div><h1><?= h($S['name'] ?: 'Your shop') ?></h1><p>Every job from estimate to paid.</p></div>
  <div class="row" style="gap:8px"><form method="get" class="row" style="flex-wrap:nowrap"><input type="hidden" name="p" value="jobs"><input type="search" name="q" value="<?= h($qq) ?>" placeholder="RO #, name, plate, VIN…"><button class="sm">Find</button></form>
    <a class="btn" href="<?= url('shop') ?>">Shop settings</a></div></div>
<?php foreach ($news as $n): ?><a class="flash" style="display:block" href="<?= url('job', ['id' => $n['id']]) ?>">✓ <?= h($n['approved_by'] ?: 'Customer') ?> responded to <?= h($n['est_no']) ?>. Open it →</a><?php endforeach; ?>
<?php $steps = shop_setup_steps(); $req = array_filter($steps, fn($x) => !$x[3]); $doneN = count(array_filter($req, fn($x) => $x[1]));
if ($doneN < count($req) && empty($S['setup_hidden'])): ?>
<div class="card setup-card">
  <div class="row" style="justify-content:space-between;gap:10px"><h2 style="margin:0">Let's get your shop ready</h2><span class="muted"><?= $doneN ?> of <?= count($req) ?> done</span></div>
  <div class="setup-bar"><i style="width:<?= round($doneN / max(1, count($req)) * 100) ?>%"></i></div>
  <ol class="setup-steps"><?php foreach ($steps as [$lbl, $ok, $href, $opt]): ?><li class="<?= $ok ? 'done' : '' ?>"><a href="<?= h($href) ?>"><span class="tick"><?= $ok ? '✓' : '' ?></span><?= h($lbl) ?><?= $opt ? ' <small class="muted">(optional)</small>' : '' ?><?= $ok ? '' : ' <b>→</b>' ?></a></li><?php endforeach; ?></ol>
  <form method="post" style="margin-top:6px"><input type="hidden" name="action" value="setup_hide"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost">Hide this</button></form>
</div>
<?php endif; ?>
<?php $anyJobs = (bool)col('SELECT 1 FROM jobs WHERE user_id=?', [uid()]); if ($anyJobs): ?>
<div class="kpi">
  <div class="card"><div class="stat"><small>Sales this month</small><b><?= money($sales) ?></b><small><?= $cars ?> car<?= $cars === 1 ? '' : 's' ?> invoiced · excl. tax</small></div></div>
  <div class="card"><div class="stat"><small>Average repair order</small><b><?= money($cars ? $sales / $cars : 0) ?></b><small>gross profit <?= money($gp) ?> (<?= $sales > 0 ? round($gp / $sales * 100) : 0 ?>%)</small></div></div>
  <div class="card"><div class="stat"><small>Effective labor rate</small><b><?= money($hours ? $labor / $hours : 0) ?></b><small><?= round($hours, 1) ?> hours billed · posted rate <?= money((float)$S['labor_rate']) ?></small></div></div>
  <div class="card"><div class="stat"><small>Owed to you</small><b><?= money($ar) ?></b><small>closing ratio <?= $sent ? round($won / $sent * 100) : 0 ?>% of estimates sent this month</small></div></div>
</div>
<?php endif; ?>
<?php $todayA = appts(today() . ' 00:00', date('Y-m-d', strtotime('+1 day')) . ' 00:00'); $todayA = array_values(array_filter($todayA, fn($x) => !in_array($x['status'], ['cancelled', 'no_show'], true)));
  $onClock = rows('SELECT t.name, t.color, e.kind, e.job_id FROM time_entries e JOIN techs t ON t.id=e.tech_id WHERE e.user_id=? AND e.ended_at IS NULL ORDER BY e.kind DESC', [uid()]);
  $nReq = (int)col("SELECT COUNT(*) FROM appointments WHERE user_id=? AND status='requested'", [uid()]); ?>
<div class="card today-strip">
  <div><h3>Today <a class="muted" href="<?= url('calendar') ?>" style="font-size:13px;font-weight:500">calendar →</a></h3>
    <?php if (!$todayA): ?><p class="muted" style="margin:0;font-size:13.5px">No appointments today.</p><?php endif; ?>
    <div class="today-list"><?php foreach ($todayA as $ta): ?><a href="<?= $ta['job_id'] ? url('job', ['id' => $ta['job_id']]) : url('calendar', ['a' => $ta['id']]) ?>" style="--tc:<?= h($ta['tcolor'] ?: '#5e667a') ?>"><b><?= h(date('g:i', strtotime((string)$ta['starts_at']))) ?></b> <?= h(appt_who($ta)) ?><small><?= h(appt_vehicle($ta)) ?><?= $ta['status'] === 'arrived' ? ' · here' : '' ?></small></a><?php endforeach; ?></div>
    <?php if ($nReq): ?><a class="st st-open" href="<?= url('calendar') ?>"><?= $nReq ?> online request<?= $nReq === 1 ? '' : 's' ?> to answer</a><?php endif; ?></div>
  <div><h3>On the clock <a class="muted" href="<?= url('clock') ?>" style="font-size:13px;font-weight:500">time clock →</a></h3>
    <?php $seen = []; $any = false; foreach ($onClock as $oc): if (isset($seen[$oc['name']])) continue; $seen[$oc['name']] = 1; $any = true; ?><span class="clock-chip" style="--tc:<?= h($oc['color'] ?: '#5e667a') ?>"><?= h($oc['name']) ?><?= $oc['kind'] === 'job' ? ' · on ' . h((string)col('SELECT est_no FROM jobs WHERE id=?', [$oc['job_id']])) : ' · idle' ?></span><?php endforeach; ?>
    <?php if (!$any): ?><p class="muted" style="margin:0;font-size:13.5px">Nobody clocked in.</p><?php endif; ?></div>
</div>
<details class="card new-ro" <?= isset($_GET['new']) || !$anyJobs ? 'open' : '' ?>><summary><b>＋ New estimate / repair order</b></summary>
  <form method="post" class="form-grid" style="margin-top:10px"><input type="hidden" name="action" value="job_new"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <div><label>Customer</label><select name="customer_id" data-cust-pick><option value="">— walk-in / none —</option><?php foreach ($customers as $cu): ?><option value="<?= (int)$cu['id'] ?>" <?= (int)($_GET['customer'] ?? 0) === (int)$cu['id'] ? 'selected' : '' ?>><?= h($cu['name']) ?></option><?php endforeach; ?></select><small><a href="<?= url('customers', ['c' => 'new']) ?>#cform">+ new customer</a></small></div>
    <div><label>Vehicle</label><select name="vehicle_id"><option value="">—</option><?php foreach (rows('SELECT v.*, c.name cname FROM vehicle v LEFT JOIN customers c ON c.id=v.customer_id WHERE v.user_id=? ORDER BY c.name, v.year', [uid()]) as $av): ?><option value="<?= (int)$av['id'] ?>" data-cust="<?= (int)$av['customer_id'] ?>" <?= (int)($_GET['vehicle'] ?? 0) === (int)$av['id'] ? 'selected' : '' ?>><?= h(vehicle_name($av)) ?><?= $av['cname'] ? ' · ' . h($av['cname']) : ' · mine' ?><?= $av['plate'] ? ' · ' . h($av['plate']) : '' ?></option><?php endforeach; ?></select></div>
    <div class="full"><label>Concern (customer's words)</label><input type="text" name="complaint" placeholder="e.g. Grinding noise when braking, check engine light on"></div>
    <div><label>Odometer in</label><input type="number" name="odometer_in"></div>
    <div><label>Start with a canned job (optional)</label><select name="canned_id"><option value="">—</option><?php $lastc = null; foreach (canned_jobs() as $cj): if ($cj['category'] !== $lastc) { if ($lastc !== null) echo '</optgroup>'; $lastc = $cj['category']; echo '<optgroup label="' . h($lastc) . '">'; } ?><option value="<?= (int)$cj['id'] ?>"><?= h($cj['name']) ?></option><?php endforeach; echo $lastc !== null ? '</optgroup>' : ''; ?></select></div>
    <div class="full"><button class="primary">Create estimate</button></div>
  </form></details>
<?php if (!$anyJobs): ?><div class="card empty-hero"><span>🧾</span><h2>No jobs yet</h2><p class="muted">Your estimates and repair orders line up here, from estimate to paid. Start one above, or check in a customer from <a href="<?= url('calendar') ?>">Appointments</a>.</p></div><?php else: ?>
<div class="board">
  <?php foreach ($cols as $k => [$label]): $items = $by[$k] ?? []; ?>
  <div class="bcol bcol-<?= $k ?>"><h3><?= h($label) ?> <span class="muted"><?= count($items) ?></span></h3>
    <?php foreach ($items as $j): ?>
      <a class="ro-card" href="<?= url('job', ['id' => $j['id']]) ?>">
        <span class="ro-no"><?= h(in_array($j['stage'], ['invoiced', 'paid'], true) && $j['inv_no'] ? 'INV ' . $j['inv_no'] : $j['est_no']) ?><?= $j['stage'] === 'waiting_parts' ? ' <i class="st st-soon">parts</i>' : '' ?><?= !(int)$j['seen_approval'] ? ' <i class="st st-done">new reply</i>' : '' ?></span>
        <b><?= h($j['cname'] ?: 'Walk-in') ?></b>
        <small><?= h(trim($j['vy'] . ' ' . $j['vm'] . ' ' . $j['vmo'])) ?><?= $j['vplate'] ? ' · ' . h($j['vplate']) : '' ?></small>
        <small class="ro-title"><?= h(mb_strimwidth((string)$j['title'], 0, 60, '…')) ?></small>
        <span class="ro-amt"><?= money((float)$j['total']) ?><?= $k === 'invoiced' && $j['paid'] > 0 ? ' <small>(' . money($j['total'] - $j['paid']) . ' due)</small>' : '' ?></span>
        <?php if ($j['promised'] && !in_array($k, ['invoiced', 'paid'], true)): ?><small>promised <?= h($j['promised']) ?></small><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="muted" style="font-size:12.5px">—</p><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
