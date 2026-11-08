<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'New inspection';
$kind = $_GET['kind'] ?? 'weekly';
$checks = rows('SELECT * FROM checks WHERE active=1 AND ' . vw() . ' ORDER BY sort, id');
$groups = [];
foreach ($checks as $c) $groups[$c['category']][] = $c;
?>
<div class="head"><div><h1>Weekly check</h1><p>Mark each item. Anything "fail" shows up in the summary so you can open an issue.</p></div></div>
<form method="post" class="card"><input type="hidden" name="action" value="inspection_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
  <div class="row"><div><label>Date</label><input type="date" name="done_on" value="<?= today() ?>" style="width:160px"></div><div><label>Odometer</label><input type="number" name="miles" value="<?= $miles ?>" style="width:140px"></div><div><label>Type</label><select name="kind" style="width:160px"><?php foreach (['weekly','monthly','pre-trip','post-service','underbody'] as $k): ?><option <?= $kind === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select></div></div>
  <?php foreach ($groups as $cat => $items): ?>
    <h3 style="margin-top:22px"><?= h($cat) ?></h3>
    <?php foreach ($items as $c): ?>
      <div class="check-row">
        <div><b><?= h($c['item']) ?></b> <small class="dim"><?= h($c['frequency']) ?></small><div class="howto"><?= h($c['how_to']) ?></div><input type="text" name="note[<?= $c['id'] ?>]" placeholder="note (optional)"></div>
        <div class="opts"><?php foreach (['ok' => 'OK', 'warn' => 'Warn', 'fail' => 'Fail', 'skip' => 'Skip'] as $v => $l): ?><label><input type="radio" name="result[<?= $c['id'] ?>]" value="<?= $v ?>" <?= $v === 'skip' ? 'checked' : '' ?>><span><?= $l ?></span></label><?php endforeach; ?></div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <label style="margin-top:20px">Summary / anything else noticed</label><textarea name="summary"></textarea>
  <div class="row end" style="margin-top:14px"><a class="btn ghost" href="<?= url('inspections') ?>">Cancel</a><button class="primary" type="submit">Save inspection</button></div>
</form>
