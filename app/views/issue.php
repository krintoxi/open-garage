<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$i = row('SELECT * FROM issues WHERE id=?', [(int)($_GET['id'] ?? 0)]);
if (!$i) { echo '<div class="card">Issue not found. <a href="' . url('issues') . '">Back</a></div>'; return; }
$title = $i['title'];
$notes = rows('SELECT * FROM issue_notes WHERE issue_id=? ORDER BY noted_at DESC, id DESC', [$i['id']]);
$photos = photos_for('issue', (int)$i['id']);
$visits = rows('SELECT v.* FROM visits v JOIN visit_issues vi ON vi.visit_id=v.id WHERE vi.issue_id=? ORDER BY scheduled_on DESC', [$i['id']]);
$cause = $i['caused_by_visit_id'] ? row('SELECT * FROM visits WHERE id=?', [$i['caused_by_visit_id']]) : null;
$parts = rows('SELECT * FROM parts WHERE issue_id=?', [$i['id']]);
$back = url('issue', ['id' => $i['id']]);
?>
<div class="head"><div><div class="row" style="margin-bottom:6px"><?= sev_badge((int)$i['severity']) ?><?= status_badge($i['status']) ?><span class="muted"><?= h($i['system']) ?> · <?= h(LOCATIONS[$i['location']] ?? '') ?></span></div><h1><?= h($i['title']) ?></h1><p>Opened <?= fmt_date($i['opened_on']) ?><?= $i['resolved_on'] ? ' · resolved ' . fmt_date($i['resolved_on']) : '' ?><?= $i['est_cost'] ? ' · est. ' . h($i['est_cost']) : '' ?></p></div>
<div class="row"><a class="btn" href="<?= url('issue_form', ['id' => $i['id']]) ?>">Edit</a>
<form method="post" class="row"><input type="hidden" name="action" value="issue_status"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $i['id'] ?>"><select name="status" style="width:150px"><?php foreach (ISSUE_STATUSES as $s): ?><option value="<?= $s ?>" <?= $i['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><button type="submit">Set</button></form></div></div>

<?php if ($cause): ?><div class="callout"><b>Linked to a service visit:</b> <a href="<?= url('visit', ['id' => $cause['id']]) ?>"><?= h($cause['title']) ?></a> (<?= fmt_date($cause['scheduled_on']) ?>). This issue is recorded as appearing after that work. Keep before/after photos on the visit for the record.</div><?php endif; ?>

<div class="grid g3">
  <div style="grid-column:span 2">
    <div class="card prose">
      <h2>What is going on</h2><?= nl2p($i['summary']) ?: '<div class="empty">No summary.</div>' ?>
      <?php if (trim((string)$i['evidence'])): ?><h3>Evidence</h3><?= nl2p($i['evidence']) ?><?php endif; ?>
      <?php if (trim((string)$i['likely_causes'])): ?><h3>Likely causes</h3><?= nl2p($i['likely_causes']) ?><?php endif; ?>
      <?php if (trim((string)$i['links'])): ?><h3>Links</h3><?= nl2p($i['links']) ?><?php endif; ?>
    </div>
    <div class="card prose" style="margin-top:18px">
      <div class="tabs"><button type="button" class="active" data-tab="owner">For me</button><button type="button" data-tab="mech">For the mechanic</button></div>
      <div class="tabpane active" data-pane="owner"><?= nl2p($i['owner_guidance']) ?: '<div class="empty">No owner guidance yet.</div>' ?></div>
      <div class="tabpane" data-pane="mech"><?= nl2p($i['mechanic_guidance']) ?: '<div class="empty">No mechanic guidance yet.</div>' ?><p class="muted" style="font-size:13px">Printable with the visit brief when this issue is attached to a visit.</p></div>
    </div>
    <div class="card" style="margin-top:18px">
      <h2>Photos</h2>
      <?php if ($photos): ?><div class="photos"><?php foreach ($photos as $ph): ?><figure><img src="<?= h(photo_url($ph['path'])) ?>" alt=""><figcaption><span class="stage"><?= h($ph['stage']) ?></span> <?= h($ph['caption']) ?> · <?= fmt_date($ph['taken_on']) ?><form method="post" style="display:inline"><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ph['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="sm ghost danger" data-confirm="Delete this photo?" style="padding:0 6px;border:0">×</button></form></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="row" style="margin-top:10px"><input type="hidden" name="action" value="photo_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="kind" value="issue"><input type="hidden" name="ref_id" value="<?= $i['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
        <input type="file" name="photos[]" multiple accept="image/*,.heic,.pdf"><select name="stage" style="width:130px"><option value="evidence">Evidence</option><option value="before">Before</option><option value="after">After</option><option value="general">General</option></select><input type="text" name="caption" placeholder="caption" style="width:200px"><button type="submit">Upload</button></form>
    </div>
  </div>
  <div>
    <div class="card">
      <h2>Timeline</h2>
      <form method="post" style="margin-bottom:12px"><input type="hidden" name="action" value="issue_note_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $i['id'] ?>">
        <textarea name="body" placeholder="Add a note: what you observed, what changed, what the mechanic said…" style="min-height:70px"></textarea>
        <div class="row" style="margin-top:6px"><input type="date" name="noted_at" value="<?= today() ?>" style="width:150px"><input type="number" name="miles" value="<?= $miles ?>" style="width:120px"><button class="sm primary" type="submit">Add note</button></div></form>
      <ul class="timeline"><?php foreach ($notes as $n): ?><li><div class="when"><?= fmt_date($n['noted_at']) ?><?= $n['miles'] ? ' · ' . number_format((int)$n['miles']) . ' mi' : '' ?></div><div><?= nl2br(h($n['body'])) ?></div><form method="post" style="display:inline"><input type="hidden" name="action" value="note_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $n['id'] ?>"><button class="sm ghost dim" style="border:0;padding:0;font-size:11px" data-confirm="Delete note?">remove</button></form></li><?php endforeach; ?>
      <li><div class="when"><?= fmt_date($i['opened_on']) ?></div><div class="muted">Issue opened.</div></li></ul>
    </div>
    <div class="card" style="margin-top:18px">
      <h2>Visits</h2>
      <?php if (!$visits): ?><div class="empty">Not attached to any visit.</div><?php endif; ?>
      <ul class="list"><?php foreach ($visits as $v): ?><li><div class="grow"><a class="t" href="<?= url('visit', ['id' => $v['id']]) ?>"><?= h($v['title']) ?></a><small><?= fmt_date($v['scheduled_on']) ?></small></div><?= status_badge($v['status']) ?></li><?php endforeach; ?></ul>
      <a class="btn sm" href="<?= url('visit_form', ['issue' => $i['id']]) ?>" style="margin-top:8px">Schedule a visit for this</a>
    </div>
    <?php if ($parts): ?><div class="card" style="margin-top:18px"><h2>Parts</h2><ul class="list"><?php foreach ($parts as $p): ?><li><div class="grow"><a class="t" href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><?= h($p['name']) ?> ↗</a></div><?= status_badge($p['status']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" style="margin-top:18px" class="right"><input type="hidden" name="action" value="issue_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $i['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this issue and its notes? Photos stay in the gallery.">Delete issue</button></form>
  </div>
</div>
