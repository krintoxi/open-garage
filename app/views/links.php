<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Links';
$list = rows('SELECT * FROM links ORDER BY category, title');
$groups = []; foreach ($list as $l) $groups[$l['category']][] = $l;
$edit = (int)($_GET['edit'] ?? 0); $ed = $edit ? row('SELECT * FROM links WHERE id=?', [$edit]) : null;
?>
<div class="head"><div><h1>Links</h1><p>Suppliers, references, forums, the mechanic.</p></div></div>
<div class="grid g3">
  <div style="grid-column:span 2">
    <?php foreach ($groups as $cat => $items): ?><div class="card" style="margin-bottom:14px"><h2><?= h($cat) ?></h2><ul class="list"><?php foreach ($items as $l): ?><li><div class="grow"><a class="t" href="<?= h($l['url']) ?>" target="_blank" rel="noopener"><?= h($l['title']) ?> ↗</a><small><?= h($l['notes']) ?></small></div><a class="btn sm ghost" href="<?= url('links', ['edit' => $l['id']]) ?>#edit">edit</a></li><?php endforeach; ?></ul></div><?php endforeach; ?>
  </div>
  <div class="card" id="edit">
    <h2><?= $ed ? 'Edit link' : 'Add link' ?></h2>
    <form method="post"><input type="hidden" name="action" value="link_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ed['id'] ?? '' ?>">
      <label>Title</label><input type="text" name="title" value="<?= h($ed['title'] ?? '') ?>" required>
      <label>URL</label><input type="url" name="url" value="<?= h($ed['url'] ?? '') ?>" required>
      <label>Category</label><input type="text" name="category" value="<?= h($ed['category'] ?? 'Reference') ?>" list="cats"><datalist id="cats"><?php foreach (array_keys($groups) as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
      <label>Notes</label><input type="text" name="notes" value="<?= h($ed['notes'] ?? '') ?>">
      <div class="row end" style="margin-top:12px"><?php if ($ed): ?><a class="btn ghost" href="<?= url('links') ?>">Cancel</a><?php endif; ?><button class="primary" type="submit">Save</button></div>
    </form>
    <?php if ($ed): ?><form method="post" class="right" style="margin-top:8px"><input type="hidden" name="action" value="link_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ed['id'] ?>"><button class="sm ghost danger" data-confirm="Remove link?">Remove</button></form><?php endif; ?>
  </div>
</div>
