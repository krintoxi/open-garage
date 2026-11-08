<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Issues';
$st = $_GET['status'] ?? 'active'; $loc = $_GET['loc'] ?? '';
$where = [vw('i')]; $params = [];
if ($st === 'active') $where[] = "i.status IN ('open','monitoring','scheduled')";
elseif ($st !== 'all') { $where[] = 'i.status=?'; $params[] = $st; }
if ($loc) { $where[] = 'i.location=?'; $params[] = $loc; }
$sql = 'SELECT i.*, v.title vtitle FROM issues i LEFT JOIN visits v ON v.id=i.caused_by_visit_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY CASE i.status WHEN \'resolved\' THEN 1 WHEN \'dismissed\' THEN 2 ELSE 0 END, i.severity DESC, i.opened_on DESC';
$list = rows($sql, $params);
?>
<div class="head"><div><h1>Issues</h1><p>Everything wrong, suspected, or being watched. Severity 1 (minor) to 5 (critical).</p></div><a class="btn primary" href="<?= url('issue_form') ?>">+ New issue</a></div>
<form class="row" method="get" style="margin-bottom:14px"><input type="hidden" name="p" value="issues">
  <select name="status" style="width:180px"><option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active (open, monitoring, scheduled)</option><?php foreach (ISSUE_STATUSES as $s): ?><option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?><option value="all" <?= $st === 'all' ? 'selected' : '' ?>>All</option></select>
  <select name="loc" style="width:200px"><option value="">Any location</option><?php foreach (LOCATIONS as $k => $v): ?><option value="<?= $k ?>" <?= $loc === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
  <button type="submit">Filter</button>
</form>
<div class="card">
<table><thead><tr><th>Sev</th><th>Issue</th><th>System / location</th><th>Status</th><th>Opened</th><th>Est. cost</th></tr></thead><tbody>
<?php if (!$list): ?><tr><td colspan="6" class="empty">Nothing here.</td></tr><?php endif; ?>
<?php foreach ($list as $i): ?>
<tr><td><?= sev_badge((int)$i['severity']) ?></td>
<td><a href="<?= url('issue', ['id' => $i['id']]) ?>"><b><?= h($i['title']) ?></b></a><?php if ($i['vtitle']): ?><br><small style="color:#fda4af">⚠ linked to visit: <?= h($i['vtitle']) ?></small><?php endif; ?></td>
<td><?= h($i['system']) ?><br><small><?= h(LOCATIONS[$i['location']] ?? '') ?></small></td>
<td><?= status_badge($i['status']) ?></td><td><?= fmt_date($i['opened_on']) ?><?= $i['resolved_on'] ? '<br><small>fixed ' . fmt_date($i['resolved_on']) . '</small>' : '' ?></td><td><small><?= h($i['est_cost']) ?></small></td></tr>
<?php endforeach; ?></tbody></table>
</div>
