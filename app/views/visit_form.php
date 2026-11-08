<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$id = (int)($_GET['id'] ?? 0);
$v = $id ? row('SELECT * FROM visits WHERE id=?', [$id]) : null;
$title = $v ? 'Edit visit' : 'Schedule a visit';
$mechs = rows('SELECT * FROM mechanics ORDER BY is_primary DESC, name');
$issues = rows("SELECT id, title, severity, status FROM issues WHERE " . vw() . " AND status IN ('open','monitoring','scheduled') ORDER BY severity DESC");
$linked = $id ? array_column(rows('SELECT issue_id FROM visit_issues WHERE visit_id=?', [$id]), 'issue_id') : [(int)($_GET['issue'] ?? 0)];
// Already-attached issues that are no longer active (resolved/dismissed): not editable here, and kept on save.
$kept = $id ? rows("SELECT i.id, i.title, i.severity, i.status FROM issues i JOIN visit_issues vi ON vi.issue_id=i.id WHERE vi.visit_id=? AND i.status NOT IN ('open','monitoring','scheduled') ORDER BY i.severity DESC", [$id]) : [];
$d = $v ?: ['mechanic_id' => $mechs[0]['id'] ?? null, 'scheduled_on' => '', 'status' => 'planned', 'title' => '', 'planned_work' => '', 'miles' => $miles];
?>
<div class="head"><div><h1><?= h($title) ?></h1><p>Attach the issues it should address; their mechanic guidance goes into the printable brief.</p></div></div>
<form method="post" class="card"><input type="hidden" name="action" value="visit_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $id ?>">
<div class="form-grid">
  <div class="full"><label>Title</label><input type="text" name="title" value="<?= h($d['title']) ?>" placeholder="e.g. Transmission leak fix + oil change" required></div>
  <div><label>Mechanic</label><select name="mechanic_id"><option value="">Unassigned</option><?php foreach ($mechs as $m): ?><option value="<?= $m['id'] ?>" <?= (int)$d['mechanic_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Date (leave blank if TBD)</label><input type="date" name="scheduled_on" value="<?= h((string)$d['scheduled_on']) ?>"></div>
  <div><label>Status</label><select name="status"><?php foreach (['planned','done','cancelled'] as $s): ?><option <?= $d['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
  <div><label>Odometer at visit</label><input type="number" name="miles" value="<?= h((string)$d['miles']) ?>"></div>
  <div class="full"><label>Planned work (numbered steps work best)</label><textarea name="planned_work" style="min-height:160px"><?= h($d['planned_work']) ?></textarea></div>
  <div class="full"><label>Issues this visit addresses</label>
    <?php foreach ($issues as $i): ?><input type="hidden" name="offered_ids[]" value="<?= $i['id'] ?>"><label class="inline"><input type="checkbox" name="issue_ids[]" value="<?= $i['id'] ?>" <?= in_array((int)$i['id'], array_map('intval', $linked), true) ? 'checked' : '' ?>> <?= sev_badge((int)$i['severity']) ?> <?= h($i['title']) ?></label><br><?php endforeach; ?>
    <?php if ($kept): ?><p class="muted" style="font-size:13px;margin:8px 0 4px">Also attached (no longer active, stays attached):</p><?php foreach ($kept as $i): ?><span class="inline"><?= sev_badge((int)$i['severity']) ?> <?= h($i['title']) ?> <?= status_badge($i['status']) ?></span><br><?php endforeach; endif; ?>
    <label class="inline" style="margin-top:8px"><input type="checkbox" name="mark_scheduled" value="1" checked> Mark attached issues as "scheduled"</label>
  </div>
</div>
<div class="row end" style="margin-top:14px"><a class="btn ghost" href="<?= $id ? url('visit', ['id' => $id]) : url('visits') ?>">Cancel</a><button class="primary" type="submit">Save visit</button></div>
</form>
