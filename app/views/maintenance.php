<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Maintenance';
$items = rows('SELECT * FROM maintenance WHERE ' . vw() . ' ORDER BY priority, item');
$edit = (int)($_GET['edit'] ?? 0);
$ed = $edit ? row('SELECT * FROM maintenance WHERE id=? AND ' . vw(), [$edit]) : null;
$order = ['overdue' => 0, 'soon' => 1, 'unknown' => 2, 'ok' => 3, 'muted' => 4];
$severe = !empty($veh['severe']);
$computed = array_map(fn($m) => [$m, ...maintenance_status($m, $miles)], $items);
usort($computed, fn($a, $b) => ($order[$a[1]] <=> $order[$b[1]]) ?: ($a[0]['priority'] <=> $b[0]['priority']));
?>
<div class="head"><div><h1>Maintenance schedule</h1><p>Intervals follow your preferences (3,000 mi oil). Items with an interval count down from when they were last done: "Never recorded" means enter the last-done date and mileage (edit, or press Done if you just did it). "On condition" items have no schedule; Done just records when.</p></div></div>
<form method="post" class="drive-style card"><input type="hidden" name="action" value="severe_set"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
  <div class="grow"><b>How do you drive?</b><small>Mostly short trips, towing, dusty or dirt roads, very hot or cold weather, or stop-and-go traffic counts as <b>severe</b>: services come about 40% sooner.</small></div>
  <div class="seg"><button name="severe" value="0" class="<?= $severe ? '' : 'on' ?>">Normal</button><button name="severe" value="1" class="<?= $severe ? 'on' : '' ?>">Severe</button></div></form>
<div class="grid g3">
  <div style="grid-column:span 2" class="card">
    <table><thead><tr><th>Item</th><th>Interval</th><th>Last done</th><th>Due</th><th></th></tr></thead><tbody>
    <?php foreach ($computed as [$m, $state, $dueM, $dueD, $lbl]): ?>
      <tr><td><b><?= h($m['item']) ?></b><br><small class="muted"><?= h($m['category']) ?><?= $m['spec'] ? ' · ' . h($m['spec']) : '' ?></small><?php if ($m['notes']): ?><br><small class="dim"><?= h($m['notes']) ?></small><?php endif; ?><?php if ($m['parts_link']): ?><br><a style="font-size:12px" href="<?= h($m['parts_link']) ?>" target="_blank" rel="noopener">parts ↗</a><?php endif; ?><?php if (!empty($m['source'])): ?><br><small class="dim">Interval: <?= h($m['source']) ?>. Check your owner's manual.</small><?php endif; ?></td>
      <td class="mono" style="font-size:13px"><?= $m['interval_miles'] ? number_format((int)$m['interval_miles']) . ' mi' : '' ?><?= $m['interval_miles'] && $m['interval_months'] ? ' / ' : '' ?><?= $m['interval_months'] ? (int)$m['interval_months'] . ' mo' : '' ?><?= !$m['interval_miles'] && !$m['interval_months'] ? ($m['due_date'] ? 'one-off date' : 'on condition') : '' ?><?= $severe && ($m['interval_miles'] || $m['interval_months']) ? '<br><small class="dim">severe: sooner</small>' : '' ?></td>
      <td style="font-size:13px"><?= $m['last_miles'] !== null ? fmt_miles((int)$m['last_miles']) : '—' ?><br><small><?= fmt_date($m['last_date']) ?></small></td>
      <td><?= status_badge($state) ?><br><small><?= h($lbl) ?></small><?php if ($dueM): ?><br><small class="dim">at <?= number_format($dueM) ?> mi</small><?php endif; ?><?php if ($dueD): ?><br><small class="dim">by <?= fmt_date($dueD) ?></small><?php endif; ?></td>
      <td class="nowrap"><form method="post" class="row done-form" style="gap:4px"><input type="hidden" name="action" value="maint_done"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $m['id'] ?>"><input type="number" name="miles" value="<?= $miles ?>" class="mini" inputmode="numeric" aria-label="Miles when done"><input type="date" name="date" value="<?= today() ?>" class="mini" aria-label="Date done"><button class="sm" type="submit">Done</button></form><a class="btn sm ghost" href="<?= url('maintenance', ['edit' => $m['id']]) ?>#edit" style="margin-top:4px">edit</a>
        <details class="menu-pop" style="display:inline-block"><summary class="btn sm ghost">⋯</summary><div><?php foreach ($m['muted'] ? ['unmute' => 'Turn reminders back on'] : ['snooze' => 'Remind me in a month', 'mute' => 'Turn off reminders for this'] as $w => $lb): ?><form method="post"><input type="hidden" name="action" value="maint_flag"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button name="what" value="<?= $w ?>"><?= h($lb) ?></button></form><?php endforeach; ?></div></details></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <div class="card" id="edit">
    <h2><?= $ed ? 'Edit item' : 'Add item' ?></h2>
    <form method="post"><input type="hidden" name="action" value="maint_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ed['id'] ?? '' ?>">
      <label>Item</label><input type="text" name="item" value="<?= h($ed['item'] ?? '') ?>" required>
      <label>Category</label><input type="text" name="category" value="<?= h($ed['category'] ?? 'Engine') ?>">
      <div class="form-grid"><div><label>Every (miles)</label><input type="number" name="interval_miles" value="<?= h((string)($ed['interval_miles'] ?? '')) ?>"></div><div><label>Every (months)</label><input type="number" name="interval_months" value="<?= h((string)($ed['interval_months'] ?? '')) ?>"></div>
      <div><label>Last done (miles)</label><input type="number" name="last_miles" value="<?= h((string)($ed['last_miles'] ?? '')) ?>"></div><div><label>Last done (date)</label><input type="date" name="last_date" value="<?= h((string)($ed['last_date'] ?? '')) ?>"></div></div>
      <label>Next due (date, e.g. from the dash reminder; optional)</label><input type="date" name="due_date" value="<?= h((string)($ed['due_date'] ?? '')) ?>"><small class="muted">Cleared automatically when you mark the item done.</small>
      <label>Spec / fluid / part</label><input type="text" name="spec" value="<?= h($ed['spec'] ?? '') ?>">
      <label>Parts link</label><input type="url" name="parts_link" value="<?= h($ed['parts_link'] ?? '') ?>">
      <label>Notes</label><textarea name="notes" style="min-height:60px"><?= h($ed['notes'] ?? '') ?></textarea>
      <label>Priority (1 high – 5 low)</label><input type="number" name="priority" min="1" max="5" value="<?= h((string)($ed['priority'] ?? 3)) ?>">
      <div class="row end" style="margin-top:12px"><?php if ($ed): ?><a class="btn ghost" href="<?= url('maintenance') ?>">Cancel</a><?php endif; ?><button class="primary" type="submit">Save</button></div>
    </form>
    <?php if ($ed): ?><form method="post" class="right" style="margin-top:10px"><input type="hidden" name="action" value="maint_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ed['id'] ?>"><button class="sm ghost danger" data-confirm="Remove this maintenance item?">Remove item</button></form><?php endif; ?>
  </div>
</div>
