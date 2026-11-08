<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Parts & upgrades';
$list = rows("SELECT p.*, i.title ititle FROM parts p LEFT JOIN issues i ON i.id=p.issue_id WHERE " . vw('p') . " ORDER BY CASE p.status WHEN 'wanted' THEN 0 WHEN 'ordered' THEN 1 WHEN 'on hand' THEN 2 ELSE 3 END, p.category, p.name");
$edit = (int)($_GET['edit'] ?? 0); $ed = $edit ? row('SELECT * FROM parts WHERE id=? AND ' . vw(), [$edit]) : null;
// Unresolved issues, plus the one this part is already for (even if resolved) so saving the form keeps the link.
$issues = rows("SELECT id, title, status FROM issues WHERE " . vw() . " AND (status<>'resolved' OR id=?) ORDER BY CASE status WHEN 'resolved' THEN 1 ELSE 0 END, severity DESC", [(int)($ed['issue_id'] ?? 0)]);
$statuses = ['wanted', 'ordered', 'on hand', 'installed'];
// Default supplier: the one used most recently for this vehicle.
$lastSupplier = (string)(col("SELECT supplier FROM parts WHERE " . vw() . " AND supplier<>'' ORDER BY id DESC LIMIT 1") ?: '');
$sum = array_sum(array_map(fn($p) => in_array($p['status'], ['wanted', 'ordered']) ? (float)$p['price'] : 0, $list));
?>
<div class="head"><div><h1>Parts & upgrades</h1><p>Parts you want, have ordered or have on hand. Pending items feed the mechanic brief.</p></div><a class="btn" href="https://www.fcpeuro.com/" target="_blank" rel="noopener">FCP Euro ↗</a></div>
<div class="grid g3">
  <div style="grid-column:span 2" class="card">
    <table><thead><tr><th>Part</th><th>Category</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($list as $p): ?><tr><td><a href="<?= h($p['url']) ?>" target="_blank" rel="noopener"><b><?= h($p['name']) ?></b> ↗</a><br><small class="muted"><?= h($p['supplier']) ?><?= $p['part_no'] ? ' · ' . h($p['part_no']) : '' ?></small><?php if ($p['notes']): ?><br><small class="dim"><?= h($p['notes']) ?></small><?php endif; ?><?php if ($p['ititle']): ?><br><small>for: <?= h($p['ititle']) ?></small><?php endif; ?></td><td><small><?= h($p['category']) ?></small></td><td class="num"><?= $p['price'] ? fmt_money((float)$p['price']) : '—' ?></td>
    <td><form method="post" class="row" style="gap:4px"><input type="hidden" name="action" value="part_status"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $p['id'] ?>"><select name="status" class="mini" onchange="this.form.submit()"><?php foreach ($statuses as $s): ?><option <?= $p['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></form></td>
    <td><a class="btn sm ghost" href="<?= url('parts', ['edit' => $p['id']]) ?>#edit">edit</a></td></tr><?php endforeach; ?></tbody>
    <tfoot><tr><td colspan="2" class="right muted">Wanted + ordered (priced items)</td><td class="num"><b><?= fmt_money($sum) ?></b></td><td colspan="2"></td></tr></tfoot></table>
  </div>
  <div class="card" id="edit">
    <h2><?= $ed ? 'Edit part' : 'Add part / upgrade' ?></h2>
    <form method="post"><input type="hidden" name="action" value="part_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ed['id'] ?? '' ?>">
      <label>Name</label><input type="text" name="name" value="<?= h($ed['name'] ?? '') ?>" required>
      <label>URL</label><input type="url" name="url" value="<?= h($ed['url'] ?? '') ?>" placeholder="https://…">
      <div class="form-grid"><div><label>Supplier</label><input type="text" name="supplier" value="<?= h($ed['supplier'] ?? $lastSupplier) ?>"></div><div><label>Part no.</label><input type="text" name="part_no" value="<?= h($ed['part_no'] ?? '') ?>"></div>
      <div><label>Price ($)</label><input type="number" step="0.01" name="price" value="<?= h((string)($ed['price'] ?? '')) ?>"></div><div><label>Status</label><select name="status"><?php foreach ($statuses as $s): ?><option <?= ($ed['status'] ?? 'wanted') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
      <div><label>Category</label><select name="category"><?php foreach (['Repair','Maintenance','Upgrade','Tool','Security','Cosmetic'] as $c): ?><option <?= ($ed['category'] ?? 'Repair') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div><div><label>For issue</label><select name="issue_id"><option value="">—</option><?php foreach ($issues as $i): ?><option value="<?= $i['id'] ?>" <?= (int)($ed['issue_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>><?= h($i['title']) ?><?= $i['status'] === 'resolved' ? ' (resolved)' : '' ?></option><?php endforeach; ?></select></div></div>
      <label>Notes</label><textarea name="notes" style="min-height:60px"><?= h($ed['notes'] ?? '') ?></textarea>
      <div class="row end" style="margin-top:12px"><?php if ($ed): ?><a class="btn ghost" href="<?= url('parts') ?>">Cancel</a><?php endif; ?><button class="primary" type="submit">Save</button></div>
    </form>
    <?php if ($ed): ?><form method="post" class="right" style="margin-top:10px"><input type="hidden" name="action" value="part_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ed['id'] ?>"><button class="sm ghost danger" data-confirm="Remove this part?">Remove</button></form><?php endif; ?>
  </div>
</div>
