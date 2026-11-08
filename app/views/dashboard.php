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
  <div class="hint">drag to orbit · click a marker</div>
</div>
<script>window.GARAGE_HOTSPOTS = <?= json_encode(array_values($byLoc), JSON_UNESCAPED_SLASHES) ?>;</script>
<?php else: require APP_ROOT . '/app/views/_stage.php'; endif; ?>

<?php $health = vehicle_health((int)$veh['id']); $recallOpen = rows("SELECT * FROM recalls WHERE " . vw() . " AND status='open' ORDER BY park_it DESC, report_date DESC");
$recallStale = (int)($veh['recalls_checked'] ?? 0) < time() - 7 * 86400; ?>
<div class="health card health-<?= h($health['tone']) ?>"<?= $recallStale ? ' data-recalls-sync' : '' ?>>
  <?= health_ring($health, 76) ?>
  <div class="health-text">
    <small>Health score</small>
    <b><?= h($health['grade']) ?></b>
    <?php if ($health['reasons']): ?>
      <div class="health-why"><?php foreach (array_slice($health['reasons'], 0, 4) as $r): ?><a href="<?= h($r['url']) ?>" class="chip chip-<?= h($r['kind']) ?>"><?= h(mb_strimwidth($r['label'], 0, 46, '…')) ?> <em>−<?= rtrim(rtrim(number_format($r['pts'], 1), '0'), '.') ?></em></a><?php endforeach; ?><?= count($health['reasons']) > 4 ? '<span class="muted" style="font-size:12px">+' . (count($health['reasons']) - 4) . ' more</span>' : '' ?></div>
    <?php else: ?><span class="muted">Nothing open, nothing overdue. Keep logging and it stays that way.</span><?php endif; ?>
  </div>
  <a class="btn sm health-share" href="<?= url('share') ?>">⇪ Share record</a>
</div>
<?php if ($recallOpen): ?>
<a class="recall-banner" href="<?= url('recalls') ?>"><span class="rb-ico">⚑</span><span><b><?= count($recallOpen) ?> safety recall<?= count($recallOpen) === 1 ? '' : 's' ?> for this model</b><small><?= h($recallOpen[0]['component']) ?><?= count($recallOpen) > 1 ? ' and more' : '' ?>. The repair is free at a dealer; check whether yours is affected.</small></span><span class="rb-go">Check →</span></a>
<?php endif; ?>
<div class="kpi">
  <div class="card <?= $sevMax >= 4 ? 'hot' : '' ?>"><div class="stat"><small>Most urgent right now</small><b style="font-size:17px;font-family:var(--font);line-height:1.25"><?= $open ? '<a href="' . url('issue', ['id' => $open[0]['id']]) . '">' . h($open[0]['title']) . '</a>' : 'Nothing open' ?></b><small><?= $sevMax ? 'severity ' . $sevMax . '/5 · ' . SEVERITY[$sevMax] . ($sevMax >= 4 ? ' · fix within 2 weeks' : '') : '' ?></small></div></div>
  <div class="card"><div class="stat"><small>Maintenance due</small><b><?= count($due) ?></b><small><?= count($due) ? 'overdue, due soon or missing a mileage' : 'nothing overdue' ?><?= $neverDone ? ' · <a href="' . url('maintenance') . '">' . count($neverDone) . ' never recorded: set last-done dates</a>' : '' ?></small></div></div>
  <div class="card"><div class="stat"><small>Last weekly check</small><b><?= $lastInsp ? $lastInspDays . 'd' : '—' ?></b><small><?= $lastInsp ? 'ago · ' . fmt_date($lastInsp['done_on']) : 'none yet' ?></small></div></div>
  <div class="card"><div class="stat"><small>Spent at mechanic (recorded)</small><b><?= '$' . number_format($spent, 0) ?></b><small><?= (int)col("SELECT COUNT(*) FROM visits WHERE " . vw() . " AND status='done'") ?> completed visits</small></div></div>
</div>

<?php
$todo = [];
if ($open) { $top = $open[0]; $first = ''; foreach (explode("\n", (string)$top['owner_guidance']) as $ln) { $ln = trim($ln); if ($ln !== '' && !str_ends_with($ln, ':')) { $first = preg_replace('/^(\d+\.|[-•*])\s*/', '', $ln); break; } } $todo[] = ['Do', $first ?: 'Open the top issue and follow the "For me" steps.', url('issue', ['id' => $top['id']])]; }
if ($recallOpen) $todo[] = ['Recall', 'Check ' . (count($recallOpen) === 1 ? 'the safety recall' : 'the ' . count($recallOpen) . ' safety recalls') . ' against your VIN; a recall repair is free.', url('recalls')];
if (!$lastInsp || $lastInspDays >= 7) $todo[] = ['Check', 'Do the 5-minute weekly check' . (is_two_wheeler($veh) ? ' (tires, brakes, oil level, lights).' : ' (cardboard under the car, fluid levels, fan, lights).') . '', url('inspection_new')];
foreach (array_slice($due, 0, 2) as [$m, $state, $lbl]) $todo[] = ['Service', $m['item'] . ': ' . $lbl . '.', url('maintenance')];
if ($neverDone) $todo[] = ['Record', count($neverDone) . ' maintenance item' . (count($neverDone) === 1 ? ' has' : 's have') . ' never been recorded (' . implode(', ', array_map(fn($m) => $m['item'], array_slice($neverDone, 0, 3))) . (count($neverDone) > 3 ? ', …' : '') . '). Enter when each was last done, or have it checked at the next visit.', url('maintenance')];
// Explicit due dates (e.g. the dash service reminder) further out than "soon": a heads-up to plan for it.
foreach ($maint as $m) if (!empty($m['due_date']) && ($dd = days_until($m['due_date'])) !== null && $dd > 30 && $dd <= 90) $todo[] = ['Plan', $m['item'] . ' is due by ' . fmt_date($m['due_date']) . '. Book it or combine it with the next visit.', url('maintenance')];
if ($nextVisit && !$nextVisit['scheduled_on']) $todo[] = ['Book', 'Set a date for "' . $nextVisit['title'] . '".', url('visit', ['id' => $nextVisit['id']])];
// Parts still to buy: those for the next visit's issues first; parts tied to resolved/dismissed issues never count.
$forVisit = $nextVisit ? (int)col("SELECT COUNT(*) FROM parts WHERE status='wanted' AND issue_id IN (SELECT issue_id FROM visit_issues WHERE visit_id=?)", [$nextVisit['id']]) : 0;
$otherWanted = (int)col("SELECT COUNT(*) FROM parts p LEFT JOIN issues i ON i.id=p.issue_id WHERE " . vw('p') . " AND p.status='wanted' AND (i.id IS NULL OR i.status NOT IN ('resolved','dismissed'))" . ($nextVisit ? ' AND (p.issue_id IS NULL OR p.issue_id NOT IN (SELECT issue_id FROM visit_issues WHERE visit_id=' . (int)$nextVisit['id'] . '))' : ''));
if ($forVisit) $todo[] = ['Order', $forVisit . ' part' . ($forVisit === 1 ? '' : 's') . ' for the next visit ' . ($forVisit === 1 ? 'is' : 'are') . ' not bought yet.' . ($otherWanted ? ' (' . $otherWanted . ' more on the wanted list.)' : ''), url('parts')];
elseif ($otherWanted) $todo[] = ['Order', $otherWanted . ' part' . ($otherWanted === 1 ? ' is' : 's are') . ' on the wanted list.', url('parts')];
?>
<div class="card hot" style="margin-bottom:18px">
  <h2>What to do this week</h2>
  <ol style="margin:0;padding-left:1.2em;font-size:15px;line-height:1.6"><?php foreach (array_slice($todo, 0, 7) as [$k, $t, $u]): ?><li><b style="color:var(--silver)"><?= h($k) ?>:</b> <?= h($t) ?> <a href="<?= $u ?>" class="muted" style="font-size:13px">open →</a></li><?php endforeach; ?></ol>
</div>
<div class="grid g3">
  <div class="card" style="grid-column:span 2">
    <a class="more" href="<?= url('issues') ?>">all issues →</a>
    <h2>Open issues</h2>
    <?php if (!$open): ?><div class="empty">No open issues. Nice.</div><?php endif; ?>
    <ul class="list">
      <?php foreach (array_slice($open, 0, 7) as $i): ?>
        <li><?= sev_badge((int)$i['severity']) ?><div class="grow"><a class="t" href="<?= url('issue', ['id' => $i['id']]) ?>"><?= h($i['title']) ?></a><small><?= h($i['system']) ?> · <?= h(LOCATIONS[$i['location']] ?? '') ?> · opened <?= fmt_date($i['opened_on']) ?><?= $i['vtitle'] !== null ? ' · <span style="color:#fda4af">linked to a service visit</span>' : '' ?></small></div><?= status_badge($i['status']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card <?= $nextVisit ? '' : '' ?>">
    <a class="more" href="<?= url('visits') ?>">visits →</a>
    <h2>Next mechanic visit</h2>
    <?php if ($nextVisit): ?>
      <p><a class="t" href="<?= url('visit', ['id' => $nextVisit['id']]) ?>"><b><?= h($nextVisit['title']) ?></b></a></p>
      <p class="muted"><?= $nextVisit['scheduled_on'] ? fmt_date($nextVisit['scheduled_on']) . ' (' . (days_until($nextVisit['scheduled_on']) >= 0 ? 'in ' . days_until($nextVisit['scheduled_on']) . ' days' : abs(days_until($nextVisit['scheduled_on'])) . ' days ago') . ')' : 'Date not set' ?><br><?= h($nextVisit['mech'] ?: 'Mechanic not assigned') ?></p>
      <div class="row"><a class="btn sm" href="<?= url('visit', ['id' => $nextVisit['id']]) ?>">Open</a><a class="btn sm" href="<?= url('brief', ['id' => $nextVisit['id']]) ?>" target="_blank">Print brief</a></div>
    <?php else: ?>
      <div class="empty">Nothing scheduled.</div><a class="btn sm primary" href="<?= url('visit_form') ?>">Schedule a visit</a>
    <?php endif; ?>
    <hr style="border:0;border-top:1px solid var(--border);margin:14px 0">
    <h3>Weekly check</h3>
    <p class="muted" style="margin:0 0 8px"><?= $lastInsp ? 'Last done ' . fmt_date($lastInsp['done_on']) . ($lastInspDays >= 7 ? ' — due.' : '.') : 'Never done.' ?></p>
    <a class="btn sm <?= (!$lastInsp || $lastInspDays >= 7) ? 'primary' : '' ?>" href="<?= url('inspection_new') ?>">Start weekly check</a>
  </div>
</div>

<div class="grid g3" style="margin-top:18px">
  <div class="card">
    <a class="more" href="<?= url('maintenance') ?>">schedule →</a>
    <h2>Maintenance due</h2>
    <?php if (!$due): ?><div class="empty">Nothing overdue or due soon.<?= $neverDone ? ' ' . count($neverDone) . ' scheduled item' . (count($neverDone) === 1 ? ' has' : 's have') . ' never been recorded, so ' . (count($neverDone) === 1 ? 'it cannot' : 'they cannot') . ' be checked: set when each was last done on the Maintenance page.' : '' ?></div><?php endif; ?>
    <ul class="list"><?php foreach (array_slice($due, 0, 6) as [$m, $state, $lbl]): ?><li><div class="grow"><span class="t"><?= h($m['item']) ?></span><small><?= h($lbl) ?></small></div><?= status_badge($state) ?></li><?php endforeach; ?></ul>
  </div>
  <div class="card">
    <a class="more" href="<?= url('mileage') ?>">log →</a>
    <h2>Mileage</h2>
    <div class="stat"><b><?= number_format($miles) ?></b><small>updated <?= fmt_date($veh['mileage_updated']) ?></small></div>
    <?php if (count($log) >= 2): $xs = array_map(fn($r) => strtotime($r['logged_at']), $log); $ys = array_map(fn($r) => (int)$r['miles'], $log); $minX = min($xs); $maxX = max($xs) ?: 1; $minY = min($ys); $maxY = max($ys); $pts = []; foreach ($log as $k => $r) { $x = $maxX > $minX ? ($xs[$k] - $minX) / ($maxX - $minX) * 300 : 150; $y = $maxY > $minY ? 60 - ($ys[$k] - $minY) / ($maxY - $minY) * 50 : 30; $pts[] = round($x, 1) . ',' . round($y + 5, 1); } ?>
      <svg class="spark" viewBox="0 0 300 70" preserveAspectRatio="none"><polyline fill="none" stroke="#c9cfdb" stroke-width="2" points="<?= implode(' ', $pts) ?>"/></svg>
      <?php $first = $log[0]; $last = end($log); $days = max(1, (strtotime($last['logged_at']) - strtotime($first['logged_at'])) / 86400); $rate = ((int)$last['miles'] - (int)$first['miles']) / $days * 7; ?>
      <small class="muted">≈ <?= number_format($rate) ?> mi/week over the log</small>
    <?php else: ?><small class="muted">Log mileage weekly to see a trend.</small><?php endif; ?>
    <form method="post" class="row" style="margin-top:10px"><input type="hidden" name="action" value="update_miles"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="number" name="miles" placeholder="Current odometer" style="width:170px" required><button class="primary sm" type="submit">Update</button></form>
  </div>
  <?php if (is_two_wheeler($veh)): ?>
  <div class="card">
    <a class="more" href="<?= url('garage') ?>">garage →</a>
    <h2>Your garage</h2>
    <ul class="list"><?php foreach (vehicles() as $gv): ?><li><div class="grow"><a class="t" href="<?= url('dashboard', ['v' => $gv['id']]) ?>"><?= h(vehicle_name($gv)) ?></a><small><?= number_format((int)$gv['mileage']) ?> mi · <?= h(VEHICLE_KINDS[$gv['kind'] ?: 'car'] ?? '') ?></small></div><?= (int)$gv['id'] === (int)$veh['id'] ? '<span class="st st-done">viewing</span>' : '' ?></li><?php endforeach; ?></ul>
    <a class="btn sm" href="<?= url('garage') ?>#add" style="margin-top:10px">+ Add a vehicle</a>
  </div>
  <?php else: ?>
  <div class="card">
    <a class="more" href="<?= url('codes') ?>">codes →</a>
    <h2>Active codes</h2>
    <?php if (!$activeCodes): ?><div class="empty">No active codes logged.</div><?php endif; ?>
    <ul class="list"><?php foreach ($activeCodes as $c): $lib = row('SELECT title FROM obd_codes WHERE code=?', [$c['code']]); ?><li><span class="code"><?= h($c['code']) ?></span><div class="grow"><span class="t"><?= h($lib['title'] ?? 'Not in library') ?></span><small>seen <?= fmt_date($c['seen_on']) ?></small></div></li><?php endforeach; ?></ul>
    <form method="post" class="row" style="margin-top:8px"><input type="hidden" name="action" value="code_event_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="text" name="code" placeholder="Log a code, e.g. P0442" style="width:190px" required><button class="sm" type="submit">Log</button></form>
  </div>
  <?php endif; ?>
</div>

<div class="grid g3" style="margin-top:18px">
  <div class="card">
    <a class="more" href="<?= url('parts') ?>">parts →</a>
    <h2>Parts & upgrades wanted</h2>
    <ul class="list"><?php foreach ($partsWanted as $p): ?><li><div class="grow"><a class="t" href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><?= h($p['name']) ?> ↗</a><small><?= h($p['supplier']) ?><?= $p['price'] ? ' · ' . fmt_money((float)$p['price']) : '' ?></small></div><?= status_badge($p['status']) ?></li><?php endforeach; ?></ul>
  </div>
  <div class="card">
    <a class="more" href="<?= url('photos') ?>">gallery →</a>
    <h2>Latest photos</h2>
    <?php if (!$recentPhotos): ?><div class="empty">No photos yet. Add before/after shots from a visit or issue.</div><?php endif; ?>
    <div class="photos" style="grid-template-columns:repeat(3,1fr)"><?php foreach ($recentPhotos as $ph): ?><figure><img src="<?= h(photo_url($ph['path'])) ?>" alt=""><figcaption><span class="stage"><?= h($ph['stage']) ?></span> <?= h($ph['caption']) ?></figcaption></figure><?php endforeach; ?></div>
  </div>
  <div class="card">
    <a class="more" href="<?= url('vehicle') ?>">edit →</a>
    <h2>The build</h2>
    <div class="mods"><?php foreach ($mods as $m): ?><span><?= h($m) ?></span><?php endforeach; ?></div>
    <p class="muted" style="margin-top:12px;font-size:13px"><?= h($veh['engine']) ?><br><?= h($veh['transmission']) ?></p>
  </div>
</div>
