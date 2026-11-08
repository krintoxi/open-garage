<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$v = row('SELECT v.*, m.name mech, m.phone, m.maps_url FROM visits v LEFT JOIN mechanics m ON m.id=v.mechanic_id WHERE v.id=?', [(int)($_GET['id'] ?? 0)]);
if (!$v) { echo '<div class="card">Visit not found.</div>'; return; }
$title = $v['title'];
$issues = rows('SELECT i.* FROM issues i JOIN visit_issues vi ON vi.issue_id=i.id WHERE vi.visit_id=? ORDER BY severity DESC', [$v['id']]);
$blamed = rows('SELECT * FROM issues WHERE caused_by_visit_id=? ORDER BY severity DESC', [$v['id']]);
$photos = photos_for('visit', (int)$v['id']);
$byStage = ['before' => [], 'after' => [], 'receipt' => [], 'general' => []];
foreach ($photos as $p) $byStage[$p['stage']][] = $p;
$maint = rows('SELECT * FROM maintenance WHERE ' . vw() . ' ORDER BY priority, item');
$back = url('visit', ['id' => $v['id']]);
?>
<div class="head"><div><div class="row" style="margin-bottom:6px"><?= status_badge($v['status']) ?><?= !empty($v['verified_by']) ? '<span class="st st-done">✓ verified by ' . h($v['verified_by']) . ($v['job_ref'] ? ' · ' . h($v['job_ref']) : '') . '</span>' : ($v['signed_off'] ? '<span class="st st-done">✓ signed off ' . fmt_date($v['signed_at']) . '</span>' : '') ?></div><h1><?= h($v['title']) ?></h1><p><?= $v['scheduled_on'] ? fmt_date($v['scheduled_on']) : 'Date TBD' ?> · <?= h($v['mech'] ?: 'mechanic unassigned') ?><?= $v['maps_url'] ? ' · <a href="' . h($v['maps_url']) . '" target="_blank" rel="noopener">map ↗</a>' : '' ?><?= $v['phone'] ? ' · ' . h($v['phone']) : '' ?><?= $v['miles'] ? ' · ' . number_format((int)$v['miles']) . ' mi' : '' ?></p></div>
<div class="row"><a class="btn" href="<?= url('brief', ['id' => $v['id']]) ?>" target="_blank">Print brief for mechanic</a><a class="btn" href="<?= url('visit_form', ['id' => $v['id']]) ?>">Edit plan</a></div></div>

<?php if ($blamed): ?><div class="callout"><b>Problems linked to this work:</b> <?php foreach ($blamed as $b): ?><a href="<?= url('issue', ['id' => $b['id']]) ?>"><?= h($b['title']) ?></a> <?= sev_badge((int)$b['severity']) ?> <?php endforeach; ?></div><?php endif; ?>

<div class="grid g3">
  <div style="grid-column:span 2">
    <div class="card prose">
      <h2>Planned work</h2><?= nl2p($v['planned_work']) ?: '<div class="empty">Nothing planned yet.</div>' ?>
      <?php if ($issues): ?><h3>Issues attached</h3><ul class="list"><?php foreach ($issues as $i): ?><li><?= sev_badge((int)$i['severity']) ?><div class="grow"><a class="t" href="<?= url('issue', ['id' => $i['id']]) ?>"><?= h($i['title']) ?></a></div><?= status_badge($i['status']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>

    <div class="card" style="margin-top:18px">
      <h2>What was actually done</h2>
      <form method="post" data-costsum><input type="hidden" name="action" value="visit_complete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $v['id'] ?>">
        <label>Completed work (as the mechanic describes it, plus what you saw)</label><textarea name="completed_work" style="min-height:120px"><?= h($v['completed_work']) ?></textarea>
        <div class="form-grid">
          <div><label>Completed on</label><input type="date" name="completed_on" value="<?= h((string)$v['scheduled_on']) ?>" <?= $v['scheduled_on'] ? '' : 'data-today' ?>></div>
          <?php $pastVisit = $v['scheduled_on'] && $v['scheduled_on'] < today(); /* don't stamp today's odometer on an old visit */ ?>
          <div><label>Odometer</label><input type="number" name="miles" value="<?= h((string)($v['miles'] ?: ($pastVisit ? '' : $miles))) ?>" placeholder="<?= $pastVisit ? 'unknown' : '' ?>"></div>
          <div><label>Parts cost ($)</label><input type="number" step="0.01" name="parts_cost" value="<?= h((string)$v['parts_cost']) ?>"></div>
          <div><label>Labor cost ($)</label><input type="number" step="0.01" name="labor_cost" value="<?= h((string)$v['labor_cost']) ?>"></div>
          <div><label>Total paid ($)</label><input type="number" step="0.01" name="cost" value="<?= h((string)$v['cost']) ?>"></div>
          <div><label>Status</label><select name="status"><?php foreach (['planned','done','cancelled'] as $s): ?><option <?= $v['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
          <div class="full"><label>Outcome / follow-up (did it fix it? anything new after?)</label><textarea name="outcome" style="min-height:80px"><?= h($v['outcome']) ?></textarea></div>
          <div><label>Rating (1–5)</label><input type="number" name="rating" min="1" max="5" value="<?= h((string)$v['rating']) ?>"></div>
          <div class="full"><label>Maintenance items completed at this visit (sets their "last done")</label><?php foreach ($maint as $m): ?><label class="inline"><input type="checkbox" name="maint_done[]" value="<?= $m['id'] ?>"> <?= h($m['item']) ?></label><?php endforeach; ?></div>
        </div>
        <div class="row end" style="margin-top:12px"><button class="primary" type="submit">Save record</button></div>
      </form>
    </div>

    <?php foreach (['before' => 'Before photos', 'after' => 'After photos', 'receipt' => 'Receipts / invoices', 'general' => 'Other photos'] as $stage => $label): ?>
    <div class="card" style="margin-top:18px">
      <h2><?= $label ?></h2>
      <?php if ($byStage[$stage]): ?><div class="photos"><?php foreach ($byStage[$stage] as $ph): ?><figure><img src="<?= h(photo_url($ph['path'])) ?>" alt=""><figcaption><?= h($ph['caption']) ?> · <?= fmt_date($ph['taken_on']) ?> <form method="post" style="display:inline"><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ph['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><button class="sm ghost danger" data-confirm="Delete this photo?" style="padding:0 6px;border:0">×</button></form></figcaption></figure><?php endforeach; ?></div><?php else: ?><div class="empty"><?= $stage === 'before' ? 'Photograph the area before the mechanic touches it: pan, connector, bracket, fluid level.' : ($stage === 'after' ? 'Same angles after the work. This is your proof either way.' : 'None yet.') ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="row" style="margin-top:10px"><input type="hidden" name="action" value="photo_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="kind" value="visit"><input type="hidden" name="ref_id" value="<?= $v['id'] ?>"><input type="hidden" name="stage" value="<?= $stage ?>"><input type="hidden" name="back" value="<?= h($back) ?>"><input type="file" name="photos[]" multiple accept="image/*,.heic,.pdf"><input type="text" name="caption" placeholder="caption" style="width:220px"><input type="date" name="taken_on" value="<?= today() ?>" style="width:150px"><button type="submit">Upload</button></form>
    </div>
    <?php endforeach; ?>
  </div>
  <div>
    <div class="signoff <?= $v['signed_off'] ? 'done' : '' ?>">
      <h2><?= $v['signed_off'] ? 'Signed off' : 'Sign off' ?></h2>
      <?php if ($v['signed_off']): ?><p class="muted">Signed <?= fmt_date($v['signed_at']) ?>. Total <?= fmt_money($v['cost'] !== null ? (float)$v['cost'] : null) ?>.</p><?php else: ?><p class="muted" style="font-size:13.5px">Only sign off after you have checked the work yourself: look under the car, verify the fluid level, drive it. Ticking an issue marks it resolved.</p><?php endif; ?>
      <form method="post"><input type="hidden" name="action" value="visit_signoff"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $v['id'] ?>">
        <?php if (!$v['signed_off']): foreach ($issues as $i): if ($i['status'] === 'resolved') continue; ?><label class="inline"><input type="checkbox" name="resolve_issue[]" value="<?= $i['id'] ?>"> Resolved: <?= h($i['title']) ?></label><br><?php endforeach; endif; ?>
        <button class="<?= $v['signed_off'] ? '' : 'primary' ?>" type="submit" style="margin-top:8px"><?= $v['signed_off'] ? 'Undo sign-off' : '✓ Sign off this visit' ?></button>
      </form>
    </div>
    <div class="card" style="margin-top:18px">
      <h3>Something went wrong after this visit?</h3>
      <p class="muted" style="font-size:13.5px">Open an issue linked to this visit. It shows a warning on the visit and in the issue list, so the history is clear if you need to go back to the shop.</p>
      <a class="btn sm" href="<?= url('issue_form', ['visit' => $v['id']]) ?>">Report a problem from this visit</a>
    </div>
    <div class="card" style="margin-top:18px">
      <h3>Checklist for the day</h3>
      <ul style="font-size:13.5px;color:var(--muted)"><li>Before photos taken (see above)</li><li>Brief printed or shown on the phone</li><li>Ask for old parts back</li><li>Invoice photographed under Receipts</li><li>After photos, same angles</li><li>Drive it, check under it, then sign off</li></ul>
    </div>
    <form method="post" class="right" style="margin-top:18px"><input type="hidden" name="action" value="visit_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $v['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this visit record?">Delete visit</button></form>
  </div>
</div>
