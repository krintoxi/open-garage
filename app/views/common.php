<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Known issues';
$openIds = [];
foreach (rows("SELECT id, title FROM issues WHERE " . vw() . " AND status IN ('open','monitoring','scheduled') ORDER BY id DESC") as $r) $openIds[$r['title']] = (int)$r['id'];
$list = rows("SELECT * FROM common_issues WHERE " . vw() . " ORDER BY CASE status WHEN 'affected' THEN 0 WHEN 'watch' THEN 1 WHEN 'done' THEN 2 ELSE 3 END, severity DESC, title");
?>
<div class="head"><div><h1>Known issues: <?= h(trim(($veh['make'] ?? '') . ' ' . ($veh['model'] ?? ''))) ?></h1><p>The failures this model is known for, with what to look for and what it costs. Mark each one as it applies to this vehicle.</p></div></div>
<div class="grid g2">
<?php foreach ($list as $c): ?>
  <div class="card <?= $c['status'] === 'affected' ? 'hot' : '' ?>" id="ci<?= $c['id'] ?>">
    <div class="row" style="margin-bottom:6px"><?= sev_badge((int)$c['severity']) ?><?= status_badge($c['status']) ?><small class="muted"><?= h($c['system']) ?> · <?= h($c['mileage_window']) ?></small></div>
    <h2 style="font-size:18px"><?= h($c['title']) ?></h2>
    <?php if (trim((string)$c['this_car_note'])): ?><div class="callout" style="font-size:13.5px"><?= h($c['this_car_note']) ?></div><?php endif; ?>
    <details><summary>Symptoms, diagnosis, fix, cost</summary><div class="prose" style="font-size:14px"><p><b class="muted">Symptoms:</b> <?= h($c['symptoms']) ?></p><p><b class="muted">How to confirm:</b> <?= h($c['diagnosis']) ?></p><p><b class="muted">Fix:</b> <?= h($c['fix']) ?></p><p><b class="muted">Typical cost:</b> <?= h($c['typical_cost']) ?><?php if ($c['parts_link']): ?> · <a href="<?= h($c['parts_link']) ?>" target="_blank" rel="noopener">parts ↗</a><?php endif; ?></p></div></details>
    <form method="post" class="row" style="margin-top:8px"><input type="hidden" name="action" value="common_status"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $c['id'] ?>">
      <select name="status" style="width:140px"><?php foreach (['watch' => 'Watch', 'affected' => 'Affected now', 'done' => 'Already fixed', 'na' => 'Not applicable'] as $k => $v): ?><option value="<?= $k ?>" <?= $c['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <input type="text" name="this_car_note" value="<?= h($c['this_car_note']) ?>" placeholder="note for this car" style="flex:1;min-width:160px"><button class="sm" type="submit">Save</button></form>
    <?php if (isset($openIds[$c['title']])): ?><a class="btn sm ghost" style="margin-top:6px" href="<?= url('issue', ['id' => $openIds[$c['title']]]) ?>">View the open issue on this car →</a>
    <?php else: ?><form method="post" style="margin-top:6px"><input type="hidden" name="action" value="common_to_issue"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="sm ghost" type="submit">Open as an issue on this car →</button></form><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
