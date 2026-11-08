<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Share service record';
$sh = share_for((int)$veh['id']);
$link = $sh ? share_url($sh['token']) : '';
$visits = (int)col("SELECT COUNT(*) FROM visits WHERE " . vw() . " AND status='done'");
$maintDone = (int)col("SELECT COUNT(*) FROM maintenance WHERE " . vw() . " AND (last_date IS NOT NULL OR last_miles IS NOT NULL)");
?>
<div class="head"><div><h1>Share service record</h1>
  <p>One link with this vehicle's history: service done, mechanic visits, mileage readings and recalls. Send it to a mechanic before a visit, or to a buyer when you sell. It is read-only and you can turn it off any time.</p></div></div>
<div class="grid g3">
  <div class="card" style="grid-column:span 2">
    <?php if ($sh): ?>
      <h2>Your link is on</h2>
      <div class="share-link"><input type="text" readonly value="<?= h($link) ?>" aria-label="Share link" data-select-all><button type="button" class="primary" data-copy="<?= h($link) ?>">Copy link</button></div>
      <div class="row" style="margin-top:10px;gap:8px">
        <a class="btn" href="<?= h($link) ?>" target="_blank" rel="noopener">Open it ↗</a>
        <button type="button" class="btn" data-share-url="<?= h($link) ?>" data-share-title="<?= h(vehicle_name($veh)) ?> service record" hidden>Send…</button>
        <a class="btn" href="<?= h($link) ?>&amp;print=1" target="_blank" rel="noopener">Print / save as PDF</a>
      </div>
      <p class="muted" style="font-size:13px;margin-top:12px">Made <?= fmt_date($sh['created_at']) ?> · opened <?= (int)$sh['views'] ?> time<?= (int)$sh['views'] === 1 ? '' : 's' ?><?= $sh['last_view'] ? ', last ' . fmt_date($sh['last_view']) : '' ?>.
        Shows: service history<?= $sh['include_costs'] ? ' with costs' : ' (no costs)' ?><?= $sh['include_issues'] ? ', open problems' : '' ?><?= $sh['include_vin'] ? ', full VIN' : ', VIN hidden except the last 6' ?>.</p>
      <form method="post" style="margin-top:6px"><input type="hidden" name="action" value="share_revoke"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost danger" data-confirm="Turn the link off? Anyone who has it will no longer see the record.">Turn the link off</button></form>
      <hr style="border:0;border-top:1px solid var(--border);margin:18px 0">
      <h3>Change what it shows</h3>
    <?php else: ?>
      <h2>Make a link</h2>
    <?php endif; ?>
    <form method="post"><input type="hidden" name="action" value="share_create"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
      <label class="inline"><input type="checkbox" name="costs" value="1" <?= !$sh || $sh['include_costs'] ? 'checked' : '' ?>> Show what each visit cost</label>
      <label class="inline"><input type="checkbox" name="issues" value="1" <?= !$sh || $sh['include_issues'] ? 'checked' : '' ?>> Show open problems (useful for a mechanic; a buyer should see them too)</label>
      <label class="inline"><input type="checkbox" name="vin" value="1" <?= $sh && $sh['include_vin'] ? 'checked' : '' ?>> Show the full VIN (a buyer will want it for a history report)</label>
      <div class="row" style="margin-top:12px"><button class="primary" type="submit"><?= $sh ? 'Make a new link with these settings' : 'Make my link' ?></button></div>
      <?php if ($sh): ?><small class="muted">The old link stops working when you make a new one.</small><?php endif; ?>
    </form>
  </div>
  <div class="card">
    <h2>What's in it</h2>
    <ul class="list">
      <li><div class="grow"><span class="t"><?= $visits ?> completed mechanic visit<?= $visits === 1 ? '' : 's' ?></span><small>with the work done and sign-off</small></div></li>
      <li><div class="grow"><span class="t"><?= $maintDone ?> service item<?= $maintDone === 1 ? '' : 's' ?> with a last-done date</span><small>oil, brakes, tires…</small></div></li>
      <li><div class="grow"><span class="t">Mileage readings</span><small>shows the odometer went up steadily</small></div></li>
      <li><div class="grow"><span class="t">Recalls and their status</span><small>from NHTSA</small></div></li>
    </ul>
    <p class="muted" style="font-size:13px">Never included: your name, email, phone numbers, receipts, your own photos (only the cut-out of the vehicle), or notes on problems. Open the link to see exactly what others see.</p>
  </div>
</div>
