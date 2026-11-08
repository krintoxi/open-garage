<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Team & payroll';
$all = techs(false);
$period = (string)($_GET['period'] ?? 'week');
$monday = strtotime('monday this week');
[$from, $to] = match ($period) {
    'last' => [$monday - 7 * 86400, $monday],
    'month' => [strtotime(date('Y-m-01')), strtotime(date('Y-m-01') . ' +1 month')],
    'lastmonth' => [strtotime(date('Y-m-01') . ' -1 month'), strtotime(date('Y-m-01'))],
    'custom' => [strtotime((string)($_GET['from'] ?? '')) ?: $monday, (strtotime((string)($_GET['to'] ?? '')) ?: time()) + 86400],
    default => [$monday, $monday + 7 * 86400],
};
$rep = time_report($from, $to);
$tot = ['shift' => 0, 'job' => 0, 'flagged' => 0, 'sold' => 0, 'pay' => 0]; foreach ($rep as $r) foreach ($tot as $k => $_) $tot[$k] += $r[$k];
$sel = ($tid = (int)($_GET['t'] ?? 0)) && owns_row('techs', $tid) ? row('SELECT * FROM techs WHERE id=?', [$tid]) : null;
$entries = $sel ? rows('SELECT e.*, j.est_no FROM time_entries e LEFT JOIN jobs j ON j.id=e.job_id WHERE e.tech_id=? AND e.started_at < ? AND (e.ended_at IS NULL OR e.ended_at > ?) ORDER BY e.started_at DESC LIMIT 300', [$tid, $to, $from]) : [];
$csrf = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$pct = fn(?float $v) => $v === null ? '—' : round($v) . '%';
$qs = ['period' => $period] + ($period === 'custom' ? ['from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to - 86400)] : []);
$dl = fn(int $ts) => date('Y-m-d\TH:i', $ts);
?>
<div class="head"><div><h1>Team &amp; payroll</h1><p><?= h(date('M j', $from)) ?> – <?= h(date('M j, Y', $to - 86400)) ?>. Flagged hours are billed labor on each tech's lines of orders invoiced in the period.</p></div>
  <div class="row" style="gap:6px;flex-wrap:wrap"><a class="btn sm" href="<?= url('clock') ?>">⏱ Time clock</a><a class="btn sm" href="<?= url('team_csv', $qs) ?>">Payroll CSV</a></div></div>
<form method="get" class="row period-pick" style="gap:6px;flex-wrap:wrap;margin-bottom:14px"><input type="hidden" name="p" value="team">
  <?php foreach (['week' => 'This week', 'last' => 'Last week', 'month' => 'This month', 'lastmonth' => 'Last month'] as $k => $l): ?><a class="btn sm <?= $period === $k ? 'on' : 'ghost' ?>" href="<?= url('team', ['period' => $k]) ?>"><?= $l ?></a><?php endforeach; ?>
  <input type="hidden" name="period" value="custom"><input type="date" name="from" value="<?= h(date('Y-m-d', $from)) ?>" style="width:auto"><input type="date" name="to" value="<?= h(date('Y-m-d', $to - 86400)) ?>" style="width:auto"><button class="sm">Show</button></form>

<div class="kpi">
  <div class="card"><div class="stat"><small>Clocked (shift)</small><b><?= hrs($tot['shift']) ?></b><small><?= hrs($tot['job']) ?> on jobs</small></div></div>
  <div class="card"><div class="stat"><small>Flagged (billed)</small><b><?= hrs($tot['flagged']) ?></b><small>labor sold <?= money($tot['sold']) ?></small></div></div>
  <div class="card"><div class="stat"><small>Shop efficiency</small><b><?= $pct($tot['job'] > 0 ? $tot['flagged'] / $tot['job'] * 100 : null) ?></b><small>billed ÷ hours on jobs</small></div></div>
  <div class="card"><div class="stat"><small>Gross pay (est.)</small><b><?= money($tot['pay']) ?></b><small>productivity <?= $pct($tot['shift'] > 0 ? $tot['job'] / $tot['shift'] * 100 : null) ?></small></div></div>
</div>

<div class="card" style="overflow-x:auto">
  <table class="team-table"><thead><tr><th>Technician</th><th class="num">Shift</th><th class="num">On jobs</th><th class="num">Flagged</th><th class="num">Efficiency</th><th class="num">Productivity</th><th class="num">Labor sold</th><th class="num">Pay</th></tr></thead><tbody>
    <?php foreach ($rep as $r): $t = $r['tech']; ?><tr class="<?= $sel && (int)$sel['id'] === (int)$t['id'] ? 'on' : '' ?> <?= $t['active'] ? '' : 'dim' ?>">
      <td><a href="<?= url('team', $qs + ['t' => $t['id']]) ?>#entries"><span class="dot" style="background:<?= h($t['color'] ?: '#5e667a') ?>"></span><b><?= h($t['name']) ?></b></a><br><small class="muted"><?= h(PAY_TYPES[$t['pay_type']] ?? '') ?><?= (float)$t['pay_rate'] ? ' · ' . money((float)$t['pay_rate']) . '/h' : '' ?></small></td>
      <td class="num"><?= hrs($r['shift']) ?></td><td class="num"><?= hrs($r['job']) ?></td><td class="num"><?= hrs($r['flagged']) ?></td>
      <td class="num"><?= $pct($r['eff']) ?></td><td class="num"><?= $pct($r['prod']) ?></td><td class="num"><?= money($r['sold']) ?></td><td class="num"><b><?= money($r['pay']) ?></b></td></tr><?php endforeach; ?>
    <?php if (!$rep): ?><tr><td colspan="8" class="muted">No technicians yet. Add them below.</td></tr><?php endif; ?>
  </tbody></table>
  <p class="muted" style="font-size:12px">Efficiency over 100% means the work was done faster than the hours billed (normal with flat rate). Pay is an estimate before overtime, taxes and deductions: hourly techs from shift hours, flat-rate techs from flagged hours.</p>
</div>

<div class="grid g3" style="margin-top:18px">
  <div style="grid-column:span 2" id="entries">
    <?php if ($sel): ?>
    <div class="card">
      <h2><?= h($sel['name']) ?>: time entries</h2>
      <?php if (!$entries): ?><div class="empty">No time in this period.</div><?php endif; ?>
      <?php foreach ($entries as $e): ?>
        <details class="time-row"><summary><span class="st <?= $e['kind'] === 'job' ? 'st-planned' : 'st-done' ?>"><?= $e['kind'] === 'job' ? 'Job' : 'Shift' ?></span>
          <span><?= h(date('D M j, g:i a', (int)$e['started_at'])) ?> – <?= $e['ended_at'] ? h(date((date('Ymd', (int)$e['ended_at']) === date('Ymd', (int)$e['started_at']) ? 'g:i a' : 'D g:i a'), (int)$e['ended_at'])) : '<b>running</b>' ?></span>
          <b class="mono"><?= hrs(entry_hours($e)) ?></b><small class="muted"><?= $e['est_no'] ? h($e['est_no']) . ($e['grp'] ? ' · ' . h((string)$e['grp']) : '') : '' ?><?= $e['edited'] ? ' · edited' : '' ?><?= $e['note'] ? ' · ' . h((string)$e['note']) : '' ?></small></summary>
          <form method="post" class="form-grid"><input type="hidden" name="action" value="time_save"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
            <div><label>Start</label><input type="datetime-local" name="start" value="<?= h($dl((int)$e['started_at'])) ?>" required></div><div><label>End (blank = still running)</label><input type="datetime-local" name="end" value="<?= $e['ended_at'] ? h($dl((int)$e['ended_at'])) : '' ?>"></div>
            <div class="full"><label>Reason for the change</label><input type="text" name="note" value="<?= h((string)$e['note']) ?>" placeholder="e.g. forgot to clock out"></div>
            <div class="full row" style="gap:8px"><button class="sm primary">Save</button></div></form>
          <form method="post"><input type="hidden" name="action" value="time_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this time entry?">Delete entry</button></form>
        </details>
      <?php endforeach; ?>
      <details style="margin-top:12px"><summary><b>＋ Add time by hand</b></summary>
        <form method="post" class="form-grid"><input type="hidden" name="action" value="time_add"><?= $csrf ?><input type="hidden" name="tech_id" value="<?= (int)$sel['id'] ?>">
          <div><label>Type</label><select name="kind"><option value="shift">Shift</option><option value="job">Job</option></select></div>
          <div><label>Repair order (for a job)</label><select name="job_id"><option value="">—</option><?php foreach (rows("SELECT id, est_no, title FROM jobs WHERE user_id=? AND stage NOT IN ('void','declined') ORDER BY id DESC LIMIT 80", [uid()]) as $j): ?><option value="<?= (int)$j['id'] ?>"><?= h($j['est_no'] . ' · ' . $j['title']) ?></option><?php endforeach; ?></select></div>
          <div><label>Start</label><input type="datetime-local" name="start" required></div><div><label>End</label><input type="datetime-local" name="end" required></div>
          <div class="full"><label>Service (optional)</label><input type="text" name="grp"></div>
          <div class="full"><label>Reason</label><input type="text" name="note" placeholder="e.g. clock was down"></div>
          <div class="full"><button class="sm primary">Add</button></div></form></details>
    </div>
    <?php else: ?><div class="card muted">Pick a technician above to see and correct their punches.</div><?php endif; ?>
  </div>
  <div class="aside">
    <div class="card" id="tform">
      <?php $et = $sel ?? null; ?>
      <h2><?= $et ? 'Edit ' . h($et['name']) : 'Add a technician' ?></h2>
      <form method="post"><input type="hidden" name="action" value="tech_save"><?= $csrf ?><input type="hidden" name="id" value="<?= $et ? (int)$et['id'] : '' ?>">
        <label>Name</label><input type="text" name="name" value="<?= h($et['name'] ?? '') ?>" required>
        <div class="form-grid"><div><label>Role</label><select name="role"><?php foreach (TECH_ROLES as $k => $l): ?><option value="<?= $k ?>" <?= ($et['role'] ?? 'tech') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
          <div><label>Color</label><input type="color" name="color" value="<?= h($et['color'] ?? TECH_COLORS[count($all) % count(TECH_COLORS)]) ?>"></div>
          <div class="full"><label>Pay</label><select name="pay_type"><?php foreach (PAY_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($et['pay_type'] ?? 'hourly') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
          <div><label>Rate per hour</label><input type="number" step="0.01" name="pay_rate" value="<?= h((string)($et['pay_rate'] ?? '')) ?>"></div>
          <div><label><?= !empty($et['pin_hash']) ? 'New PIN' : 'Clock PIN' ?></label><input type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" placeholder="4–8 digits" autocomplete="new-password"></div></div>
        <?php if (!empty($et['pin_hash'])): ?><label class="inline"><input type="checkbox" name="clear_pin" value="1"> Remove PIN</label><?php endif; ?>
        <input type="hidden" name="active" value="0"><label class="inline"><input type="checkbox" name="active" value="1" <?= ($et['active'] ?? 1) ? 'checked' : '' ?>> Active</label>
        <div class="row" style="gap:8px;margin-top:10px"><button class="primary sm">Save</button><?php if ($et): ?><a class="btn sm ghost" href="<?= url('team', $qs) ?>#tform">New</a><?php endif; ?></div></form>
      <?php if ($et): ?><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="tech_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$et['id'] ?>"><button class="sm ghost danger" data-confirm="Remove <?= h($et['name']) ?>? With time on record they are set to inactive instead.">Remove</button></form><?php endif; ?>
    </div>
    <div class="card" style="margin-top:14px"><h3>Team</h3><ul class="list"><?php foreach ($all as $t): ?><li><span class="dot" style="background:<?= h($t['color'] ?: '#5e667a') ?>"></span><div class="grow"><a class="t" href="<?= url('team', $qs + ['t' => $t['id']]) ?>#tform"><?= h($t['name']) ?></a><small><?= h(TECH_ROLES[$t['role']] ?? '') ?><?= $t['active'] ? '' : ' · inactive' ?><?= $t['pin_hash'] ? ' · PIN set' : '' ?></small></div></li><?php endforeach; ?></ul></div>
  </div>
</div>
