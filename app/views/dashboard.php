<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Dashboard'; $use3d = !empty($veh['model3d']);
$open = rows("SELECT i.*, v.title vtitle FROM issues i LEFT JOIN visits v ON v.id=i.caused_by_visit_id WHERE " . vw('i') . " AND i.status IN ('open','monitoring','scheduled') ORDER BY i.severity DESC, i.opened_on DESC");
$byLoc = [];
foreach ($open as $i) {
    $k = $i['location'] ?: 'other';
    $byLoc[$k]['loc'] = $k; $byLoc[$k]['label'] = LOCATIONS[$k] ?? 'Other';
    $byLoc[$k]['sev'] = max($byLoc[$k]['sev'] ?? 0, (int)$i['severity']);
    $byLoc[$k]['count'] = ($byLoc[$k]['count'] ?? 0) + 1;
    $byLoc[$k]['items'][] = ['id' => (int)$i['id'], 'title' => $i['title'], 'sev' => (int)$i['severity'], 'status' => $i['status']];
}
$maint = rows('SELECT * FROM maintenance WHERE ' . vw() . ' ORDER BY priority, item');
$due = [];
$needMiles = [];
$neverDone = [];
foreach ($maint as $m) {
    [$state, , , $lbl] = maintenance_status($m, $miles);
    if (in_array($state, ['overdue', 'soon'], true)) $due[] = [$m, $state, $lbl];
    elseif ($state === 'unknown' && $m['interval_miles'] && $m['last_miles'] === null && $m['last_date']) $needMiles[] = [$m, $state, $lbl]; // done, but at an unknown mileage
    elseif ($state === 'unknown' && ($m['interval_miles'] || $m['interval_months']) && $m['last_miles'] === null && !$m['last_date']) $neverDone[] = $m; // on a schedule but never recorded
}
$due = array_merge($due, $needMiles);
$nextVisit = row("SELECT v.*, m.name mech FROM visits v LEFT JOIN mechanics m ON m.id=v.mechanic_id WHERE " . vw('v') . " AND v.status='planned' ORDER BY CASE WHEN scheduled_on IS NULL THEN 1 ELSE 0 END, scheduled_on LIMIT 1");
$lastInsp = row('SELECT * FROM inspections WHERE ' . vw() . ' ORDER BY done_on DESC, id DESC LIMIT 1');
$lastInspDays = $lastInsp ? -days_until($lastInsp['done_on']) : null;
$partsWanted = rows("SELECT * FROM parts WHERE " . vw() . " AND status IN ('wanted','ordered') ORDER BY created_at DESC LIMIT 5");
$recentPhotos = rows('SELECT * FROM photos WHERE ' . vw() . ' ORDER BY id DESC LIMIT 6');
$log = rows('SELECT logged_at, miles FROM mileage_log WHERE ' . vw() . ' ORDER BY logged_at, id');
$activeCodes = rows("SELECT * FROM code_events WHERE " . vw() . " AND status='active' ORDER BY seen_on DESC");
$spent = (float)col("SELECT COALESCE(SUM(cost),0) FROM visits WHERE " . vw() . " AND status='done'");
$sevMax = $open ? max(array_map(fn($i) => (int)$i['severity'], $open)) : 0;
$mods = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/^\s*[-•*]\s*/', '', $l)), explode("\n", (string)$veh['mods']))));
?>
<?php if ($use3d): ?>
<div class="hero <?= $veh['photo'] ? '' : '' ?>">
  <div id="car3d" data-model="<?= h('models/' . basename((string)$veh['model3d'])) ?>"></div>
  <?php if ($veh['photo']): ?><div class="photo" style="background-image:url('<?= h(photo_url($veh['photo'])) ?>')"></div><?php endif; ?>
  <div class="overlay">
    <h1><?= h($veh['year'] . ' ' . $veh['make'] . ' ' . $veh['model']) ?></h1>
    <p><?= h($veh['trim']) ?> · <?= number_format($miles) ?> mi · <?= count($open) ?> open issue<?= count($open) === 1 ? '' : 's' ?></p>
  </div>
  <div class="controls no-print">
    <button type="button" data-xray>X-ray</button>
    <?php if ($veh['photo']): ?><button type="button" data-photo>Photo</button><?php else: ?><a class="btn" href="<?= url('vehicle') ?>" style="padding:6px 10px;font-size:12px;background:rgba(10,12,16,.7)">Add photo</a><?php endif; ?>
    <button type="button" data-reset>Reset view</button>
  </div>
  <div class="hotspot-panel"><button type="button" class="close">×</button><h3></h3><ul class="list"></ul></div>
  <div class="hs-label"></div>
  <div class="legend"><span><i style="background:var(--sev5)"></i>Critical</span><span><i style="background:var(--sev4)"></i>High</span><span><i style="background:var(--sev3)"></i>Moderate</span><span><i style="background:var(--sev2)"></i>Low</span><span><i style="background:var(--sev1)"></i>Minor</span></div>
  <div class="hint">drag to spin · tap a dot to see the problem</div>
</div>
<script>window.GARAGE_HOTSPOTS = <?= json_encode(array_values($byLoc), JSON_UNESCAPED_SLASHES) ?>;</script>
<?php else: require APP_ROOT . '/app/views/_stage.php'; endif; ?>

<?php
$health = vehicle_health((int)$veh['id']);
$recallOpen = rows("SELECT * FROM recalls WHERE " . vw() . " AND status='open' ORDER BY park_it DESC, report_date DESC");
$recallStale = (int)($veh['recalls_checked'] ?? 0) < time() - 7 * 86400;
$csrfIn = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$short = trim((string)($veh['nickname'] ?: $veh['model'])) ?: 'your vehicle';
$me = current_user();
$first = !empty($me['name']) && !is_guest() ? strtok((string)$me['name'], ' ') : '';
$hr = (int)date('G');
$hello = ($hr < 5 ? 'Hi' : ($hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening'))) . ($first ? ', ' . $first : '');
// Plain words instead of "severity 4/5".
$sevWord = [5 => 'Fix now', 4 => 'Fix soon', 3 => 'When you can', 2 => 'Keep an eye on it', 1 => 'Minor'];
$stWord = ['open' => '', 'monitoring' => 'Watching', 'scheduled' => 'Booked'];
$fixSoon = count(array_filter($open, fn($i) => (int)$i['severity'] >= 4 && $i['status'] !== 'monitoring'));
$overdue = count(array_filter($due, fn($d) => $d[1] === 'overdue'));
$soonN = count(array_filter($due, fn($d) => $d[1] === 'soon'));
$plural = fn(int $n, string $one, string $many = '') => $n . ' ' . ($n === 1 ? $one : ($many ?: $one . 's'));
$svcN = $overdue + $soonN;
$bits = array_values(array_filter([
    $fixSoon ? $plural($fixSoon, 'problem') . ' to fix soon' : (count($open) ? $plural(count($open), 'small thing') . ' to keep an eye on' : ''),
    !$svcN ? '' : ($overdue === $svcN ? $plural($svcN, 'service') . ' overdue' : $plural($svcN, 'service') . ' due' . ($overdue ? ' (' . $overdue . ' overdue)' : ' soon')),
    $recallOpen ? $plural(count($recallOpen), 'recall') . ' to check' : '',
    $activeCodes ? $plural(count($activeCodes), 'warning code') : '',
]));
$extra = count($bits) > 3; $bits = array_slice($bits, 0, 3);
$sentence = $bits ? ucfirst(count($bits) > 1 ? implode(', ', array_slice($bits, 0, -1)) . ($extra ? ', ' : ' and ') . end($bits) : reset($bits)) . ($extra ? ' and a bit more.' : '.') : 'Nothing open and nothing overdue. Keep logging and it stays that way.';
$headline = [
    'good' => ucfirst($short) . ' is in great shape 🎉', 'ok' => ucfirst($short) . ' is doing fine 👍',
    'warn' => ucfirst($short) . ' needs a little attention', 'bad' => ucfirst($short) . ' needs some care',
][$health['tone']] ?? ucfirst($short);
$milesAgo = $veh['mileage_updated'] ? -days_until((string)$veh['mileage_updated']) : null;
$milesWhen = $milesAgo === null ? 'not logged yet' : ($milesAgo <= 0 ? 'updated today' : ($milesAgo === 1 ? 'updated yesterday' : 'updated ' . $milesAgo . ' days ago'));

// "Up next": the few things worth doing now, each with one clear button.
$todo = [];
if ($open) {
    $top = $open[0]; $step = '';
    foreach (explode("\n", (string)$top['owner_guidance']) as $ln) { $ln = trim($ln); if ($ln !== '' && !str_ends_with($ln, ':')) { $step = preg_replace('/^(\d+\.|[-•*])\s*/', '', $ln); break; } }
    $todo[] = ['🔧', $top['title'], $step ?: 'Open it to see what you can do yourself and what to tell the mechanic.', url('issue', ['id' => $top['id']]), 'See what to do', (int)$top['severity'] >= 4 ? 'hot' : ''];
}
if ($recallOpen) $todo[] = ['⚑', 'Check ' . (count($recallOpen) === 1 ? 'a safety recall' : count($recallOpen) . ' safety recalls'), 'Recall repairs are free at a dealer. It takes a minute to see if yours is affected.', url('recalls'), 'Check', 'warn'];
if (!$lastInsp || $lastInspDays >= 7) $todo[] = ['✅', 'Do the 5-minute weekly check', is_two_wheeler($veh) ? 'Tires, brakes, oil level and lights.' : 'A quick look under the car, the fluid levels, the fan and the lights.', url('inspection_new'), 'Start', ''];
foreach (array_slice($due, 0, 2) as [$m, $state, $lbl]) $todo[] = ['🛠', $m['item'], ucfirst($lbl) . '.', url('maintenance'), 'Open', $state === 'overdue' ? 'warn' : ''];
if ($neverDone) $todo[] = ['📝', 'Fill in your service history', $plural(count($neverDone), 'item') . ' have no last-done date yet (' . implode(', ', array_map(fn($m) => $m['item'], array_slice($neverDone, 0, 3))) . (count($neverDone) > 3 ? ', …' : '') . '). Add what you know; skip the rest.', url('maintenance'), 'Fill in', ''];
foreach ($maint as $m) if (!empty($m['due_date']) && ($dd = days_until($m['due_date'])) !== null && $dd > 30 && $dd <= 90) $todo[] = ['📅', $m['item'] . ' by ' . date('M j', strtotime($m['due_date'])), 'Book it, or do it at your next visit.', url('maintenance'), 'Plan', ''];
if ($nextVisit && !$nextVisit['scheduled_on']) $todo[] = ['📅', 'Pick a date for your next visit', '“' . $nextVisit['title'] . '”' . ($nextVisit['mech'] ? ' with ' . $nextVisit['mech'] : '') . '.', url('visit', ['id' => $nextVisit['id']]), 'Set date', ''];
$forVisit = $nextVisit ? (int)col("SELECT COUNT(*) FROM parts WHERE status='wanted' AND issue_id IN (SELECT issue_id FROM visit_issues WHERE visit_id=?)", [$nextVisit['id']]) : 0;
$otherWanted = (int)col("SELECT COUNT(*) FROM parts p LEFT JOIN issues i ON i.id=p.issue_id WHERE " . vw('p') . " AND p.status='wanted' AND (i.id IS NULL OR i.status NOT IN ('resolved','dismissed'))" . ($nextVisit ? ' AND (p.issue_id IS NULL OR p.issue_id NOT IN (SELECT issue_id FROM visit_issues WHERE visit_id=' . (int)$nextVisit['id'] . '))' : ''));
if ($forVisit) $todo[] = ['🛒', 'Buy ' . $plural($forVisit, 'part') . ' for your next visit', $otherWanted ? $otherWanted . ' more on your wish list.' : 'So the mechanic can start right away.', url('parts'), 'Parts', ''];
elseif ($otherWanted) $todo[] = ['🛒', $plural($otherWanted, 'part') . ' on your wish list', 'Order them when you are ready.', url('parts'), 'Parts', ''];
$todoShow = 3;
$visits = (int)col("SELECT COUNT(*) FROM visits WHERE " . vw() . " AND status='done'");
$taskLi = fn(array $t) => '<li class="task ' . h($t[5]) . '"><a href="' . $t[3] . '"><span class="task-ico" aria-hidden="true">' . h($t[0]) . '</span><span class="task-txt"><b>' . h($t[1]) . '</b><small>' . h($t[2]) . '</small></span><span class="task-go">' . h($t[4]) . ' <i aria-hidden="true">›</i></span></a></li>';
?>
<div class="dash">

<section class="card dash-status tone-<?= h($health['tone']) ?>"<?= $recallStale ? ' data-recalls-sync' : '' ?>>
  <div class="ds-top">
    <?= health_ring($health, 84) ?>
    <div class="ds-text">
      <small><?= h($hello) ?> 👋</small>
      <h2><?= h($headline) ?></h2>
      <p><?= h($sentence) ?></p>
    </div>
  </div>
  <div class="ds-foot">
    <details class="ds-odo">
      <summary><span><?= is_two_wheeler($veh) ? '🛵' : '🚗' ?> <b><?= number_format($miles) ?></b> mi</span><small><?= h($milesWhen) ?></small><em>Update</em></summary>
      <form method="post" class="row"><input type="hidden" name="action" value="update_miles"><?= $csrfIn ?><input type="number" name="miles" inputmode="numeric" placeholder="What does the odometer say?" required><button class="primary sm" type="submit">Save</button></form>
    </details>
    <?php if ($health['reasons']): ?>
    <details class="ds-why"><summary>Why <?= (int)$health['score'] ?>/100?</summary>
      <div class="health-why"><?php foreach ($health['reasons'] as $r): ?><a href="<?= h($r['url']) ?>" class="chip chip-<?= h($r['kind']) ?>"><?= h(mb_strimwidth($r['label'], 0, 46, '…')) ?> <em>−<?= rtrim(rtrim(number_format($r['pts'], 1), '0'), '.') ?></em></a><?php endforeach; ?></div>
      <p class="muted">Every open problem, overdue service, recall and warning code takes points off. Sort them out and the score climbs back up.</p>
    </details>
    <?php endif; ?>
    <a class="ds-share" href="<?= url('share') ?>">⇪ Share</a>
  </div>
</section>

<div class="dash-quick" role="navigation" aria-label="Quick actions">
  <a href="<?= url('diagnose') ?>"><span>🩺</span>Something's wrong</a>
  <a href="<?= url('inspection_new') ?>"><span>✅</span>Weekly check</a>
  <a href="<?= url('visit_form') ?>"><span>🛠</span>Log a service</a>
  <a href="<?= url('issue_form') ?>"><span>➕</span>Note a problem</a>
  <a href="<?= url('photos') ?>"><span>📷</span>Photos</a>
</div>

<?php if ($recallOpen): ?>
<a class="recall-banner" href="<?= url('recalls') ?>"><span class="rb-ico">⚑</span><span><b><?= count($recallOpen) ?> safety recall<?= count($recallOpen) === 1 ? '' : 's' ?> for this model</b><small><?= h($recallOpen[0]['component']) ?><?= count($recallOpen) > 1 ? ' and more' : '' ?>. The repair is free at a dealer; check whether yours is affected.</small></span><span class="rb-go">Check →</span></a>
<?php endif; ?>

<section class="card dash-next">
  <h2>Up next</h2>
  <?php if (!$todo): ?>
    <div class="all-good"><span>✨</span><div><b>You're all caught up.</b><small>Nothing to do this week. Log the mileage now and then and we'll tell you when a service comes up.</small></div></div>
  <?php else: ?>
    <ul class="tasks"><?php foreach (array_slice($todo, 0, $todoShow) as $t) echo $taskLi($t); ?></ul>
    <?php if (count($todo) > $todoShow): ?><details class="more-tasks"><summary>Show <?= count($todo) - $todoShow ?> more</summary><ul class="tasks"><?php foreach (array_slice($todo, $todoShow, 6) as $t) echo $taskLi($t); ?></ul></details><?php endif; ?>
  <?php endif; ?>
</section>

<div class="grid g3 dash-grid">
  <section class="card" style="grid-column:span 2">
    <a class="more" href="<?= url('issues') ?>">See all ›</a>
    <h2>Problems</h2>
    <?php if (!$open): ?><div class="all-good"><span>👌</span><div><b>No problems noted.</b><small>Noticed a noise, a leak or a light? <a href="<?= url('issue_form') ?>">Write it down</a> or <a href="<?= url('diagnose') ?>">ask the diagnosis helper</a>.</small></div></div><?php endif; ?>
    <ul class="plist">
      <?php foreach (array_slice($open, 0, 6) as $i): $s = max(1, min(5, (int)$i['severity'])); ?>
        <li><a href="<?= url('issue', ['id' => $i['id']]) ?>"><i class="pdot sev-<?= $s ?>" aria-hidden="true"></i><span class="grow"><b><?= h($i['title']) ?></b><small><?= h(implode(' · ', array_unique(array_filter([$i['system'], LOCATIONS[$i['location']] ?? ''])))) ?> · since <?= h(date('M j', strtotime((string)$i['opened_on']))) ?></small></span><span class="ptag sev-<?= $s ?>"><?= h($stWord[$i['status']] ?: $sevWord[$s]) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($open) > 6): ?><a class="muted" href="<?= url('issues') ?>" style="font-size:13px">+<?= count($open) - 6 ?> more</a><?php endif; ?>
  </section>
  <section class="card">
    <a class="more" href="<?= url('visits') ?>">History ›</a>
    <h2>Mechanic</h2>
    <?php if ($nextVisit): $du = $nextVisit['scheduled_on'] ? days_until($nextVisit['scheduled_on']) : null; ?>
      <p style="margin:0 0 2px"><a href="<?= url('visit', ['id' => $nextVisit['id']]) ?>"><b><?= h($nextVisit['title']) ?></b></a></p>
      <p class="muted" style="margin:0 0 10px"><?= $du === null ? 'No date yet' : ($du === 0 ? 'Today' : ($du === 1 ? 'Tomorrow' : ($du > 0 ? 'In ' . $du . ' days' : abs($du) . ' days ago'))) . ($nextVisit['scheduled_on'] ? ' · ' . h(date('D, M j', strtotime($nextVisit['scheduled_on'])))  : '') ?><?= $nextVisit['mech'] ? ' · ' . h($nextVisit['mech']) : '' ?></p>
      <div class="row"><a class="btn sm" href="<?= url('visit', ['id' => $nextVisit['id']]) ?>"><?= $nextVisit['scheduled_on'] ? 'Open' : 'Set a date' ?></a><a class="btn sm ghost" href="<?= url('brief', ['id' => $nextVisit['id']]) ?>" target="_blank">Print for the mechanic</a></div>
    <?php else: ?>
      <p class="muted" style="margin:0 0 10px">No visit planned.</p><a class="btn sm" href="<?= url('visit_form') ?>">Plan a visit</a>
    <?php endif; ?>
    <p class="muted ds-mini"><?= $visits ? $plural($visits, 'visit') . ' on record' . ($spent > 0 ? ' · $' . number_format($spent, 0) . ' spent' : '') : 'Log your services here and keep the receipts in one place.' ?></p>
  </section>
</div>

<div class="grid g3 dash-grid">
  <section class="card">
    <a class="more" href="<?= url('maintenance') ?>">Schedule ›</a>
    <h2>Service</h2>
    <?php if (!$due): ?><div class="all-good"><span>🗓</span><div><b>Nothing due right now.</b><?php if ($neverDone): ?><small><?= $plural(count($neverDone), 'item') ?> on the schedule <?= count($neverDone) === 1 ? 'has' : 'have' ?> no last-done date yet, so we can't tell when <?= count($neverDone) === 1 ? "it's" : "they're" ?> due. <a href="<?= url('maintenance') ?>">Fill in what you know</a>.</small><?php endif; ?></div></div><?php endif; ?>
    <ul class="plist"><?php foreach (array_slice($due, 0, 5) as [$m, $state, $lbl]): ?><li><a href="<?= url('maintenance') ?>"><i class="pdot <?= $state === 'overdue' ? 'sev-5' : ($state === 'soon' ? 'sev-3' : 'sev-1') ?>" aria-hidden="true"></i><span class="grow"><b><?= h($m['item']) ?></b><small><?= h(ucfirst($lbl)) ?></small></span><span class="ptag <?= $state === 'overdue' ? 'sev-5' : ($state === 'soon' ? 'sev-3' : 'sev-1') ?>"><?= ['overdue' => 'Overdue', 'soon' => 'Soon'][$state] ?? 'Needs mileage' ?></span></a></li><?php endforeach; ?></ul>
  </section>
  <section class="card" id="miles">
    <a class="more" href="<?= url('mileage') ?>">Log ›</a>
    <h2>Mileage</h2>
    <div class="stat"><b><?= number_format($miles) ?> <small style="display:inline;font-size:13px">mi</small></b><small><?= h($milesWhen) ?></small></div>
    <?php if (count($log) >= 2): $xs = array_map(fn($r) => strtotime($r['logged_at']), $log); $ys = array_map(fn($r) => (int)$r['miles'], $log); $minX = min($xs); $maxX = max($xs) ?: 1; $minY = min($ys); $maxY = max($ys); $pts = []; foreach ($log as $k => $r) { $x = $maxX > $minX ? ($xs[$k] - $minX) / ($maxX - $minX) * 300 : 150; $y = $maxY > $minY ? 60 - ($ys[$k] - $minY) / ($maxY - $minY) * 50 : 30; $pts[] = round($x, 1) . ',' . round($y + 5, 1); } ?>
      <svg class="spark" viewBox="0 0 300 70" preserveAspectRatio="none"><polyline fill="none" stroke="#c9cfdb" stroke-width="2" points="<?= implode(' ', $pts) ?>"/></svg>
      <?php $firstL = $log[0]; $lastL = end($log); $days = max(1, (strtotime($lastL['logged_at']) - strtotime($firstL['logged_at'])) / 86400); $rate = ((int)$lastL['miles'] - (int)$firstL['miles']) / $days * 7; ?>
      <small class="muted">You drive about <?= number_format($rate) ?> mi a week.</small>
    <?php else: ?><small class="muted">Update it every week or two and we'll work out when services are due.</small><?php endif; ?>
    <form method="post" class="row" style="margin-top:10px;flex-wrap:nowrap"><input type="hidden" name="action" value="update_miles"><?= $csrfIn ?><input type="number" name="miles" inputmode="numeric" placeholder="Odometer now" required><button class="primary sm" type="submit">Save</button></form>
  </section>
  <?php if (is_two_wheeler($veh)): ?>
  <section class="card">
    <a class="more" href="<?= url('garage') ?>">Garage ›</a>
    <h2>Your garage</h2>
    <ul class="plist"><?php foreach (vehicles() as $gv): ?><li><a href="<?= url('dashboard', ['v' => $gv['id']]) ?>"><span class="grow"><b><?= h(vehicle_name($gv)) ?></b><small><?= number_format((int)$gv['mileage']) ?> mi · <?= h(VEHICLE_KINDS[$gv['kind'] ?: 'car'] ?? '') ?></small></span><?= (int)$gv['id'] === (int)$veh['id'] ? '<span class="ptag sev-1">Viewing</span>' : '' ?></a></li><?php endforeach; ?></ul>
    <a class="btn sm" href="<?= url('garage') ?>#add" style="margin-top:10px">+ Add a vehicle</a>
  </section>
  <?php else: ?>
  <section class="card">
    <a class="more" href="<?= url('codes') ?>">Codes ›</a>
    <h2>Warning lights</h2>
    <?php if (!$activeCodes): ?><div class="all-good"><span>💡</span><div><b>No warning codes.</b><small>Check-engine light on? Type the code below, or <a href="<?= url('diagnose') ?>">let us help figure it out</a>.</small></div></div><?php endif; ?>
    <ul class="plist"><?php foreach ($activeCodes as $c): $lib = row('SELECT title FROM obd_codes WHERE code=?', [$c['code']]); ?><li><a href="<?= url('codes') ?>"><span class="code"><?= h($c['code']) ?></span><span class="grow"><b><?= h($lib['title'] ?? 'Not in our library yet') ?></b><small>since <?= h(date('M j', strtotime((string)$c['seen_on']))) ?></small></span></a></li><?php endforeach; ?></ul>
    <form method="post" class="row" style="margin-top:10px;flex-wrap:nowrap"><input type="hidden" name="action" value="code_event_add"><?= $csrfIn ?><input type="text" name="code" placeholder="Code, e.g. P0442" autocapitalize="characters" required><button class="sm" type="submit">Add</button></form>
  </section>
  <?php endif; ?>
</div>

<?php if ($recentPhotos || $partsWanted || $mods || $veh['engine']): ?>
<div class="grid g3 dash-grid">
  <?php if ($partsWanted): ?>
  <section class="card">
    <a class="more" href="<?= url('parts') ?>">Parts ›</a>
    <h2>Wish list</h2>
    <ul class="plist"><?php foreach ($partsWanted as $p): ?><li><a href="<?= h($p['url'] ?: url('parts')) ?>"<?= $p['url'] ? ' target="_blank" rel="noopener"' : '' ?>><span class="grow"><b><?= h($p['name']) ?><?= $p['url'] ? ' ↗' : '' ?></b><small><?= h(implode(' · ', array_filter([$p['supplier'], $p['price'] ? fmt_money((float)$p['price']) : '']))) ?></small></span><span class="ptag sev-1"><?= $p['status'] === 'ordered' ? 'Ordered' : 'Wanted' ?></span></a></li><?php endforeach; ?></ul>
  </section>
  <?php endif; ?>
  <section class="card"<?= $partsWanted ? '' : ' style="grid-column:span 2"' ?>>
    <a class="more" href="<?= url('photos') ?>">Gallery ›</a>
    <h2>Photos</h2>
    <?php if (!$recentPhotos): ?><div class="all-good"><span>📷</span><div><b>No photos yet.</b><small>Snap a leak, a dashboard light or a receipt; it helps the mechanic and the next owner. <a href="<?= url('photos') ?>">Add one</a>.</small></div></div><?php endif; ?>
    <div class="photos" style="grid-template-columns:repeat(<?= $partsWanted ? 3 : 4 ?>,1fr)"><?php foreach (array_slice($recentPhotos, 0, $partsWanted ? 6 : 8) as $ph): ?><figure><img src="<?= h(photo_url($ph['path'])) ?>" alt="<?= h($ph['caption']) ?>" loading="lazy"></figure><?php endforeach; ?></div>
  </section>
  <section class="card">
    <a class="more" href="<?= url('vehicle') ?>">Edit ›</a>
    <h2>About <?= h($short) ?></h2>
    <?php if ($veh['engine'] || $veh['transmission']): ?><p class="muted" style="margin:0 0 10px;font-size:13.5px"><?= h(implode(' · ', array_filter([$veh['engine'], $veh['transmission']]))) ?></p><?php endif; ?>
    <?php if ($mods): ?><div class="mods"><?php foreach ($mods as $m): ?><span><?= h($m) ?></span><?php endforeach; ?></div>
    <?php else: ?><p class="muted" style="font-size:13.5px;margin:0">Add your upgrades and extras on <a href="<?= url('vehicle') ?>">Vehicle &amp; settings</a>.</p><?php endif; ?>
  </section>
</div>
<?php endif; ?>

</div>
