<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Specs & torque';
$list = rows('SELECT * FROM specs WHERE ' . vw() . ' ORDER BY sort, id');
$groups = []; foreach ($list as $s) $groups[$s['category']][] = $s;
$conf = ['high' => ['st-ok', 'verified'], 'medium' => ['st-soon', 'verify in WIS'], 'low' => ['st-overdue', 'unverified']];
?>
<div class="head"><div><h1>Specs, capacities & torque</h1><p>Numbers the mechanic will ask for. Confidence tells you which ones come from the owner's manual and which still need a workshop-manual check.</p></div><a class="btn" href="<?= url('manual') ?>">Search the owner's manual →</a></div>
<div class="callout">Green = from the owner's manual or a Mercedes spec sheet. Amber = widely cited but not from an official document: have the shop confirm in WIS before torquing. Red = best guess.</div>
<?php foreach ($groups as $cat => $items): ?>
<div class="card" style="margin-bottom:16px"><h2><?= h($cat) ?></h2>
<table><thead><tr><th style="width:24%">Item</th><th>Value</th><th style="width:26%">Source</th><th style="width:10%"></th></tr></thead><tbody>
<?php foreach ($items as $s): [$cls, $lbl] = $conf[$s['confidence']] ?? ['st-unknown', $s['confidence']]; ?>
<tr><td><b><?= h($s['item']) ?></b></td><td><?= h($s['value']) ?><?php if ($s['notes']): ?><br><small class="muted"><?= h($s['notes']) ?></small><?php endif; ?></td><td><small class="muted"><?= h($s['source']) ?></small></td><td><span class="st <?= $cls ?>"><?= h($lbl) ?></span></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endforeach; ?>
