<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Weekly checks';
$insps = rows('SELECT i.*, (SELECT COUNT(*) FROM inspection_results r WHERE r.inspection_id=i.id AND r.result=\'fail\') fails, (SELECT COUNT(*) FROM inspection_results r WHERE r.inspection_id=i.id AND r.result=\'warn\') warns, (SELECT COUNT(*) FROM inspection_results r WHERE r.inspection_id=i.id AND r.result=\'ok\') oks FROM inspections i WHERE ' . vw('i') . ' ORDER BY done_on DESC, id DESC');
$checks = rows('SELECT * FROM checks WHERE ' . vw() . ' ORDER BY active DESC, category, sort, id');
$edit = (int)($_GET['edit'] ?? 0); $ed = $edit ? row('SELECT * FROM checks WHERE id=?', [$edit]) : null;
?>
<div class="head"><div><h1>Weekly checks & inspections</h1><p>A five-minute walk-around with the hood up. Log it, and failed checks become issues.</p></div><a class="btn primary" href="<?= url('inspection_new') ?>">Start a check</a></div>
<div class="grid g3">
  <div style="grid-column:span 2">
    <div class="card">
      <h2>History</h2>
      <?php if (!$insps): ?><div class="empty">No inspections logged yet.</div><?php endif; ?>
      <table><thead><tr><th>Date</th><th>Miles</th><th>Type</th><th>Results</th><th>Summary</th></tr></thead><tbody>
      <?php foreach ($insps as $i): ?><tr><td><a href="<?= url('inspection', ['id' => $i['id']]) ?>"><?= fmt_date($i['done_on']) ?></a></td><td class="num"><?= number_format((int)$i['miles']) ?></td><td><?= h($i['kind']) ?></td><td><span class="res res-ok"><?= $i['oks'] ?> ok</span> <span class="res res-warn"><?= $i['warns'] ?> warn</span> <span class="res res-fail"><?= $i['fails'] ?> fail</span></td><td><small><?= h($i['summary']) ?></small></td></tr><?php endforeach; ?></tbody></table>
    </div>
    <div class="card" style="margin-top:18px">
      <h2>Checklist items</h2>
      <table><thead><tr><th>Category</th><th>Check</th><th>Freq.</th><th></th></tr></thead><tbody>
      <?php foreach ($checks as $c): ?><tr style="<?= $c['active'] ? '' : 'opacity:.45' ?>"><td><small><?= h($c['category']) ?></small></td><td><b><?= h($c['item']) ?></b><br><small class="muted"><?= h($c['how_to']) ?></small></td><td><small><?= h($c['frequency']) ?></small></td><td class="nowrap"><a class="btn sm ghost" href="<?= url('inspections', ['edit' => $c['id']]) ?>#edit">edit</a><form method="post" style="display:inline"><input type="hidden" name="action" value="check_toggle"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="sm ghost" type="submit"><?= $c['active'] ? 'hide' : 'show' ?></button></form></td></tr><?php endforeach; ?></tbody></table>
    </div>
  </div>
  <div class="card" id="edit">
    <h2><?= $ed ? 'Edit check' : 'Add a check' ?></h2>
    <form method="post"><input type="hidden" name="action" value="check_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ed['id'] ?? '' ?>">
      <label>Category</label><input type="text" name="category" value="<?= h($ed['category'] ?? 'Leak watch') ?>">
      <label>Check</label><input type="text" name="item" value="<?= h($ed['item'] ?? '') ?>" required>
      <label>How to do it</label><textarea name="how_to"><?= h($ed['how_to'] ?? '') ?></textarea>
      <label>Frequency</label><select name="frequency"><?php foreach (['weekly','monthly','before trips','after service'] as $f): ?><option <?= ($ed['frequency'] ?? 'weekly') === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select>
      <label>Sort order</label><input type="number" name="sort" value="<?= h((string)($ed['sort'] ?? 99)) ?>">
      <div class="row end" style="margin-top:12px"><?php if ($ed): ?><a class="btn ghost" href="<?= url('inspections') ?>">Cancel</a><?php endif; ?><button class="primary" type="submit">Save</button></div>
    </form>
  </div>
</div>
