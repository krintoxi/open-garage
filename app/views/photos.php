<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Photo record';
$kind = $_GET['kind'] ?? '';
$list = rows('SELECT * FROM photos WHERE ' . vw() . ($kind ? ' AND kind=?' : '') . ' ORDER BY taken_on DESC, id DESC', $kind ? [$kind] : []);
$links = [];
foreach ($list as $p) {
    $links[$p['id']] = match ($p['kind']) {
        'visit' => ['Visit', url('visit', ['id' => $p['ref_id']]), col('SELECT title FROM visits WHERE id=?', [$p['ref_id']])],
        'issue' => ['Issue', url('issue', ['id' => $p['ref_id']]), col('SELECT title FROM issues WHERE id=?', [$p['ref_id']])],
        'inspection' => ['Inspection', url('inspection', ['id' => $p['ref_id']]), col('SELECT done_on FROM inspections WHERE id=?', [$p['ref_id']])],
        default => ['Vehicle', url('vehicle'), ''],
    };
}
?>
<div class="head"><div><h1>Photo record</h1><p>Every photo, newest first. Before/after pairs live on their visit.</p></div>
<form method="get" class="row"><input type="hidden" name="p" value="photos"><select name="kind" onchange="this.form.submit()" style="width:160px"><option value="">All</option><?php foreach (['visit' => 'Visits', 'issue' => 'Issues', 'inspection' => 'Inspections', 'vehicle' => 'Vehicle'] as $k => $l): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form></div>
<div class="card">
  <form method="post" enctype="multipart/form-data" class="row" style="margin-bottom:14px"><input type="hidden" name="action" value="photo_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="kind" value="vehicle"><input type="hidden" name="ref_id" value="<?= (int)$veh['id'] ?>"><input type="hidden" name="back" value="<?= url('photos') ?>"><input type="file" name="photos[]" multiple accept="image/*,.heic"><select name="stage" style="width:130px"><option value="general">General</option><option value="exterior">Exterior</option><option value="interior">Interior</option><option value="underbody">Underbody</option></select><input type="text" name="caption" placeholder="caption" style="width:220px"><input type="date" name="taken_on" value="<?= today() ?>" style="width:150px"><button type="submit">Upload general photos</button></form>
  <?php if (!$list): ?><div class="empty">No photos yet.</div><?php endif; ?>
  <div class="photos" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
  <?php foreach ($list as $p): [$kl, $ku, $kt] = $links[$p['id']]; ?>
    <figure><?php if (str_ends_with(strtolower($p['path']), '.pdf')): ?><a href="<?= h(photo_url($p['path'])) ?>" target="_blank" style="display:block;aspect-ratio:4/3;display:grid;place-items:center;background:#0a0c10">PDF ↗</a><?php else: ?><img src="<?= h(photo_url($p['path'])) ?>" alt=""><?php endif; ?>
      <figcaption><span class="stage"><?= h($p['stage']) ?></span> <?= h($p['caption']) ?><br><small><?= fmt_date($p['taken_on']) ?> · <a href="<?= $ku ?>"><?= $kl ?><?= $kt ? ': ' . h(mb_strimwidth((string)$kt, 0, 30, '…')) : '' ?></a></small>
      <form method="post" style="display:inline"><input type="hidden" name="action" value="photo_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="back" value="<?= url('photos', $kind ? ['kind' => $kind] : []) ?>"><button class="sm ghost danger" data-confirm="Delete this photo?" style="padding:0 6px;border:0">×</button></form></figcaption></figure>
  <?php endforeach; ?>
  </div>
</div>
