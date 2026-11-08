<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$i = row('SELECT * FROM inspections WHERE id=?', [(int)($_GET['id'] ?? 0)]);
if (!$i) { echo '<div class="card">Not found.</div>'; return; }
$title = 'Inspection ' . fmt_date($i['done_on']);
$res = rows('SELECT r.*, c.item, c.category FROM inspection_results r JOIN checks c ON c.id=r.check_id WHERE r.inspection_id=? ORDER BY CASE r.result WHEN \'fail\' THEN 0 WHEN \'warn\' THEN 1 WHEN \'ok\' THEN 2 ELSE 3 END, c.category, c.sort', [$i['id']]);
$photos = photos_for('inspection', (int)$i['id']);
$back = url('inspection', ['id' => $i['id']]);
?>
<div class="head"><div><h1>Inspection · <?= fmt_date($i['done_on']) ?></h1><p><?= h($i['kind']) ?> · <?= number_format((int)$i['miles']) ?> mi</p></div><div class="row"><a class="btn" href="<?= url('inspections') ?>">All inspections</a><form method="post"><input type="hidden" name="action" value="inspection_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $i['id'] ?>"><button class="ghost danger" data-confirm="Delete this inspection?">Delete</button></form></div></div>
<div class="grid g3">
  <div class="card" style="grid-column:span 2">
    <?php if ($i['summary']): ?><div class="callout"><?= nl2br(h($i['summary'])) ?></div><?php endif; ?>
    <table><thead><tr><th>Result</th><th>Check</th><th>Note</th><th></th></tr></thead><tbody>
    <?php foreach ($res as $r): ?><tr><td><span class="res res-<?= h($r['result']) ?>"><?= h($r['result']) ?></span></td><td><b><?= h($r['item']) ?></b><br><small class="muted"><?= h($r['category']) ?></small></td><td><?= h($r['note']) ?></td><td><?php if (in_array($r['result'], ['fail','warn'])): ?><a class="btn sm" href="<?= url('issue_form') ?>&prefill=<?= urlencode($r['item'] . ($r['note'] ? ': ' . $r['note'] : '')) ?>">Open issue</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <div class="card">
    <h2>Photos</h2>
    <?php if ($photos): ?><div class="photos" style="grid-template-columns:repeat(2,1fr)"><?php foreach ($photos as $ph): ?><figure><img src="<?= h(photo_url($ph['path'])) ?>" alt=""><figcaption><?= h($ph['caption']) ?></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:10px"><input type="hidden" name="action" value="photo_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="kind" value="inspection"><input type="hidden" name="ref_id" value="<?= $i['id'] ?>"><input type="hidden" name="stage" value="inspection"><input type="hidden" name="back" value="<?= h($back) ?>"><input type="hidden" name="taken_on" value="<?= h($i['done_on']) ?>"><input type="file" name="photos[]" multiple accept="image/*,.heic"><input type="text" name="caption" placeholder="caption (e.g. cardboard under pan)" style="margin-top:6px"><div class="row end" style="margin-top:6px"><button class="sm" type="submit">Upload</button></div></form>
  </div>
</div>
