<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$id = (int)($_GET['id'] ?? 0);
$i = $id ? row('SELECT * FROM issues WHERE id=?', [$id]) : null;
$title = $i ? 'Edit issue' : 'New issue';
$d = $i ?: ['title' => (string)($_GET['prefill'] ?? ''), 'system' => 'Engine', 'location' => 'engine', 'severity' => 2, 'status' => 'open', 'summary' => '', 'evidence' => '', 'likely_causes' => '', 'owner_guidance' => '', 'mechanic_guidance' => '', 'opened_on' => today(), 'resolved_on' => '', 'caused_by_visit_id' => (int)($_GET['visit'] ?? 0) ?: null, 'est_cost' => '', 'links' => ''];
$visits = rows('SELECT id, title, scheduled_on FROM visits WHERE ' . vw() . ' ORDER BY scheduled_on DESC');
?>
<div class="head"><div><h1><?= h($title) ?></h1><p>Be specific. Symptoms, what you saw, when it started.</p></div></div>
<form method="post" class="card"><input type="hidden" name="action" value="issue_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $id ?>">
<div class="form-grid">
  <div class="full"><label>Title</label><input type="text" name="title" value="<?= h($d['title']) ?>" required></div>
  <div><label>System</label><select name="system"><?php foreach (SYSTEMS as $s): ?><option <?= $d['system'] === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></div>
  <div><label>Location on the car (3D marker)</label><select name="location"><?php foreach (LOCATIONS as $k => $v): ?><option value="<?= $k ?>" <?= $d['location'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
  <div><label>Severity (1 minor – 5 critical)</label><select name="severity"><?php foreach (SEVERITY as $k => $v): ?><option value="<?= $k ?>" <?= (int)$d['severity'] === $k ? 'selected' : '' ?>><?= $k ?> · <?= $v ?></option><?php endforeach; ?></select></div>
  <div><label>Status</label><select name="status"><?php foreach (ISSUE_STATUSES as $s): ?><option value="<?= $s ?>" <?= $d['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
  <div><label>Opened on</label><input type="date" name="opened_on" value="<?= h($d['opened_on']) ?>"></div>
  <div><label>Resolved on</label><input type="date" name="resolved_on" value="<?= h((string)$d['resolved_on']) ?>"></div>
  <div class="full"><label>Caused by / appeared after a mechanic visit?</label><select name="caused_by_visit_id"><option value="">No / unknown</option><?php foreach ($visits as $v): ?><option value="<?= $v['id'] ?>" <?= (int)$d['caused_by_visit_id'] === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['title']) ?> (<?= fmt_date($v['scheduled_on']) ?>)</option><?php endforeach; ?></select></div>
  <div class="full"><label>Summary (what is wrong)</label><textarea name="summary"><?= h($d['summary']) ?></textarea></div>
  <div class="full"><label>Evidence (what you saw, photos, videos, dates)</label><textarea name="evidence"><?= h($d['evidence']) ?></textarea></div>
  <div class="full"><label>Likely causes (one per line, most likely first)</label><textarea name="likely_causes"><?= h($d['likely_causes']) ?></textarea></div>
  <div><label>Guidance for me</label><textarea name="owner_guidance" style="min-height:140px"><?= h($d['owner_guidance']) ?></textarea></div>
  <div><label>Guidance for the mechanic</label><textarea name="mechanic_guidance" style="min-height:140px"><?= h($d['mechanic_guidance']) ?></textarea></div>
  <div><label>Estimated cost</label><input type="text" name="est_cost" value="<?= h($d['est_cost']) ?>"></div>
  <div><label>Links (one per line)</label><input type="text" name="links" value="<?= h($d['links']) ?>"></div>
</div>
<div class="row end" style="margin-top:14px"><a class="btn ghost" href="<?= $id ? url('issue', ['id' => $id]) : url('issues') ?>">Cancel</a><button class="primary" type="submit">Save issue</button></div>
</form>
