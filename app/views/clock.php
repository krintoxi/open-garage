<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Time clock';
$techList = techs();
$open = rows("SELECT j.id, j.est_no, j.title, j.stage, c.name cname, v.year vy, v.make vm, v.model vmo FROM jobs j LEFT JOIN customers c ON c.id=j.customer_id LEFT JOIN vehicle v ON v.id=j.vehicle_id
  WHERE j.user_id=? AND j.stage IN ('approved','in_progress','waiting_parts','done') ORDER BY j.stage='in_progress' DESC, j.updated_at DESC LIMIT 60", [uid()]);
$groups = [];
foreach ($open as $o) $groups[$o['id']] = array_column(rows("SELECT DISTINCT grp FROM job_lines WHERE job_id=? AND status='approved' AND kind IN ('labor','note') ORDER BY sort", [$o['id']]), 'grp');
$csrf = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$dayStart = strtotime('today');
?>
<div class="head"><div><h1>Time clock</h1><p>Technicians clock in for the shift, then start and stop each job. Billed hours vs. clocked hours become efficiency and payroll under <a href="<?= url('team') ?>">Team &amp; payroll</a>.</p></div>
  <div class="row" style="gap:6px"><span class="mono muted" data-clock-now><?= h(date('g:i a')) ?></span><a class="btn sm" href="<?= url('team') ?>">Team &amp; payroll</a></div></div>
<?php if (!$techList): ?>
  <div class="card empty">No technicians yet. <a href="<?= url('team') ?>#tform"><b>Add your team →</b></a> (you can add yourself too).</div>
<?php endif; ?>
<div class="clock-grid" data-autorefresh="90">
  <?php foreach ($techList as $t): $sh = tech_open((int)$t['id'], 'shift'); $jb = tech_open((int)$t['id'], 'job');
    $todayH = array_sum(array_map(fn($e) => entry_hours($e, $dayStart), rows("SELECT * FROM time_entries WHERE tech_id=? AND kind='shift' AND (ended_at IS NULL OR ended_at > ?)", [$t['id'], $dayStart])));
    $jobRow = $jb ? row('SELECT * FROM jobs WHERE id=?', [$jb['job_id']]) : null; ?>
  <section class="card clock-card <?= $jb ? 'working' : ($sh ? 'onshift' : 'off') ?>" style="--tc:<?= h($t['color'] ?: '#5e667a') ?>">
    <header><span class="dot"></span><b><?= h($t['name']) ?></b><small><?= h(TECH_ROLES[$t['role']] ?? '') ?></small></header>
    <p class="clock-state">
      <?php if ($jb && $jobRow): ?>Working on <a href="<?= url('job', ['id' => $jobRow['id']]) ?>"><?= h(job_label($jobRow)) ?></a><?= $jb['grp'] ? ' · ' . h((string)$jb['grp']) : '' ?><br><b class="mono" data-since="<?= (int)$jb['started_at'] ?>"><?= hm((int)((time() - (int)$jb['started_at']) / 60)) ?></b> on this job
      <?php elseif ($sh): ?>On shift since <?= h(date('g:i a', (int)$sh['started_at'])) ?> · not on a job
      <?php else: ?>Off the clock<?php endif; ?>
      <small class="muted">Today <?= hrs($todayH) ?></small></p>
    <form method="post" class="clock-form"><input type="hidden" name="action" value="clock_punch"><?= $csrf ?><input type="hidden" name="tech_id" value="<?= (int)$t['id'] ?>">
      <?php if ($t['pin_hash']): ?><input type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" placeholder="PIN" required autocomplete="off" class="pin"><?php endif; ?>
      <?php if ($open): ?><select name="job_pick" data-job-pick><option value="">Pick a repair order to start…</option><?php foreach ($open as $o): ?><optgroup label="<?= h($o['est_no'] . ' · ' . ($o['cname'] ?: 'Walk-in') . ' · ' . trim($o['vy'] . ' ' . $o['vm'] . ' ' . $o['vmo'])) ?>">
        <?php foreach (($groups[$o['id']] ?: ['']) as $g): ?><option value="<?= (int)$o['id'] ?>|<?= h((string)$g) ?>" <?= $jb && (int)$jb['job_id'] === (int)$o['id'] && (string)$jb['grp'] === (string)$g ? 'selected' : '' ?>><?= h($g !== '' ? $g : ($o['title'] ?: 'Whole order')) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
        <input type="hidden" name="job_id"><input type="hidden" name="grp"><?php endif; ?>
      <div class="clock-btns">
        <?php if (!$sh): ?><button class="primary" name="what" value="shift_in">Clock in</button><?php endif; ?>
        <?php if ($open): ?><button name="what" value="job_start" class="<?= $sh && !$jb ? 'primary' : '' ?>" data-needs-job><?= $jb ? 'Switch job' : 'Start job' ?></button><?php endif; ?>
        <?php if ($jb): ?><button name="what" value="job_stop">Stop job</button><?php endif; ?>
        <?php if ($sh): ?><button class="ghost" name="what" value="shift_out" data-confirm="Clock <?= h($t['name']) ?> out for the day?">Clock out</button><?php endif; ?>
      </div>
    </form>
  </section>
  <?php endforeach; ?>
</div>
<?php if (!$open && $techList): ?><p class="muted">No approved repair orders to work on right now. Approve an estimate on the <a href="<?= url('jobs') ?>">Shop board</a> first.</p><?php endif; ?>
<p class="muted" style="font-size:12.5px;margin-top:14px">Tip: put this page on a shop tablet's home screen as the punch clock. Give each tech a PIN under Team &amp; payroll so nobody punches for someone else. Edits made later are marked as edited.</p>
