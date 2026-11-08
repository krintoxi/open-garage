<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Safety recalls';
$recalls = rows("SELECT * FROM recalls WHERE " . vw() . " ORDER BY CASE status WHEN 'open' THEN 0 ELSE 1 END, report_date DESC");
$checked = (int)($veh['recalls_checked'] ?? 0);
$open = count(array_filter($recalls, fn($r) => $r['status'] === 'open'));
?>
<div class="head"><div><h1>Safety recalls</h1>
  <p>Recalls the US safety agency (NHTSA) lists for every <?= h(vehicle_name($veh)) ?>. A recall repair is free at a dealer. Not every car of a model is affected, so check yours by VIN, then mark each one here.</p></div>
  <form method="post"><input type="hidden" name="action" value="recalls_check"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button type="submit" class="sm">Check again now</button></form>
</div>
<div class="grid g3">
  <div class="card" style="grid-column:span 2">
    <?php if (!$recalls): ?>
      <div class="empty"><?= $checked > 0 ? 'NHTSA has no recalls on record for this year, make and model (checked ' . h(date('M j, Y', $checked)) . ').' : 'Not checked yet. Press "Check again now".' ?>
        <?= is_two_wheeler($veh) ? ' Many scooter brands sold outside the US are not in the NHTSA database.' : '' ?></div>
    <?php endif; ?>
    <?php foreach ($recalls as $r): ?>
      <article class="recall <?= $r['status'] === 'open' ? 'is-open' : '' ?>" id="r<?= (int)$r['id'] ?>">
        <header><b><?= h($r['component'] ?: 'Recall') ?></b><span class="st st-<?= $r['status'] === 'open' ? 'open' : 'done' ?>"><?= h(RECALL_STATUSES[$r['status']] ?? $r['status']) ?></span></header>
        <small class="muted">Campaign <?= h($r['campaign']) ?><?= $r['report_date'] ? ' · ' . fmt_date($r['report_date']) : '' ?></small>
        <?php if ($r['park_it']): ?><p class="recall-park">NHTSA says: park it outside and do not drive it until it is repaired.</p><?php endif; ?>
        <p><?= h($r['summary']) ?></p>
        <?php if ($r['consequence']): ?><p><b>Why it matters:</b> <?= h($r['consequence']) ?></p><?php endif; ?>
        <?php if ($r['remedy']): ?><p><b>The fix:</b> <?= h($r['remedy']) ?></p><?php endif; ?>
        <form method="post" class="row" style="gap:6px"><input type="hidden" name="action" value="recall_status"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <?php foreach (RECALL_STATUSES as $k => $lbl): if ($k === $r['status']) continue; ?><button class="sm <?= $k === 'fixed' ? 'primary' : 'ghost' ?>" name="status" value="<?= h($k) ?>"><?= $k === 'open' ? 'Mark as still to check' : h($lbl) ?></button><?php endforeach; ?>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
  <div class="card aside">
    <h2>Is mine affected?</h2>
    <ol style="padding-left:1.2em;margin:0 0 10px">
      <li>Copy your VIN<?= $veh['vin'] ? ':' : ' (on the windshield corner, the driver door frame or your registration).' ?>
        <?php if ($veh['vin']): ?><br><code class="vin-copy" data-copy="<?= h($veh['vin']) ?>" title="Tap to copy"><?= h($veh['vin']) ?></code><?php endif; ?></li>
      <li>Enter it at <a href="https://www.nhtsa.gov/recalls" target="_blank" rel="noopener">nhtsa.gov/recalls ↗</a>.</li>
      <li>Any open recall: call a <?= h($veh['make'] ?: 'brand') ?> dealer and book the free repair. Then mark it Fixed here.</li>
    </ol>
    <p class="muted" style="font-size:13px"><?= $open ? $open . ' still to check.' : 'Nothing left to check.' ?> The app looks for new recalls once a week<?= $checked ? ' (last ' . h(date('M j', $checked)) . ')' : '' ?>.</p>
  </div>
</div>
