<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Mechanic visits';
$list = rows("SELECT v.*, m.name mech, (SELECT COUNT(*) FROM photos p WHERE p.kind='visit' AND p.ref_id=v.id) nphotos, (SELECT COUNT(*) FROM issues i WHERE i.caused_by_visit_id=v.id) nblame FROM visits v LEFT JOIN mechanics m ON m.id=v.mechanic_id WHERE " . vw('v') . " ORDER BY CASE status WHEN 'planned' THEN 0 ELSE 1 END, COALESCE(scheduled_on,'9999') DESC, id DESC");
$total = (float)col("SELECT COALESCE(SUM(cost),0) FROM visits WHERE " . vw() . " AND status='done'");
?>
<div class="head"><div><h1>Mechanic visits</h1><p>Plan the work, hand the mechanic a brief, then sign off what was actually done, what it cost, and photograph before and after.</p></div><a class="btn primary" href="<?= url('visit_form') ?>">+ Schedule visit</a></div>
<div class="card">
<table><thead><tr><th>Date</th><th>Visit</th><th>Mechanic</th><th>Status</th><th>Cost</th><th>Record</th></tr></thead><tbody>
<?php if (!$list): ?><tr><td colspan="6" class="empty">No visits yet.</td></tr><?php endif; ?>
<?php foreach ($list as $v): ?>
<tr><td><?= $v['scheduled_on'] ? fmt_date($v['scheduled_on']) : '<span class="dim">TBD</span>' ?><?= $v['miles'] ? '<br><small>' . number_format((int)$v['miles']) . ' mi</small>' : '' ?></td>
<td><a href="<?= url('visit', ['id' => $v['id']]) ?>"><b><?= h($v['title']) ?></b></a><?php if ($v['nblame']): ?><br><small style="color:#fda4af">⚠ <?= $v['nblame'] ?> issue<?= $v['nblame'] > 1 ? 's' : '' ?> linked to this work</small><?php endif; ?></td>
<td><?= h($v['mech'] ?: '—') ?></td><td><?= status_badge($v['status']) ?><?= $v['signed_off'] ? '<br><small style="color:#86efac">✓ signed off</small>' : '' ?></td>
<td class="num"><?= fmt_money($v['cost'] !== null ? (float)$v['cost'] : null) ?></td><td><small><?= $v['nphotos'] ?> photo<?= $v['nphotos'] == 1 ? '' : 's' ?></small></td></tr>
<?php endforeach; ?></tbody>
<tfoot><tr><td colspan="4" class="right muted">Recorded total</td><td class="num"><b><?= fmt_money($total) ?></b></td><td></td></tr></tfoot></table>
</div>
