<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Shop settings';
$S = shop();
$canned = canned_jobs();
$cq = trim((string)($_GET['cq'] ?? ''));
$catalog = rows('SELECT * FROM parts_catalog WHERE user_id=?' . ($cq !== '' ? ' AND (name LIKE ? OR part_no LIKE ? OR brand LIKE ?)' : '') . ' ORDER BY name LIMIT 300', $cq !== '' ? [uid(), "%$cq%", "%$cq%", "%$cq%"] : [uid()]);
$editC = (int)($_GET['canned'] ?? 0); $ec = $editC && owns_row('canned_jobs', $editC) ? row('SELECT * FROM canned_jobs WHERE id=?', [$editC]) : null;
$editP = (int)($_GET['part'] ?? 0); $ep = $editP && owns_row('parts_catalog', $editP) ? row('SELECT * FROM parts_catalog WHERE id=?', [$editP]) : null;
$matrixTxt = implode("\n", array_map(fn($r) => rtrim(rtrim(number_format((float)$r[0], 2, '.', ''), '0'), '.') . ': ' . rtrim(rtrim(number_format((float)$r[1], 1, '.', ''), '0'), '.') . '%', $S['matrix']));
$i = fn(string $k) => h((string)$S[$k]);
?>
<div class="head"><div><h1>Shop settings</h1><p>Your shop's details, rates, taxes and pricing rules. New repair orders copy these; each order can still be adjusted.</p></div><a class="btn" href="<?= url('jobs') ?>">← Shop board</a></div>
<div class="grid g3">
  <form method="post" class="card" style="grid-column:span 2"><input type="hidden" name="action" value="shop_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <h2>Shop</h2>
    <div class="form-grid">
      <div><label>Shop name</label><input type="text" name="name" value="<?= $i('name') ?>"></div><div><label>Phone</label><input type="tel" name="phone" value="<?= $i('phone') ?>"></div>
      <div class="full"><label>Address</label><input type="text" name="address" value="<?= $i('address') ?>"></div>
      <div><label>Email</label><input type="email" name="email" value="<?= $i('email') ?>"></div><div><label>Website</label><input type="text" name="website" value="<?= $i('website') ?>"></div>
      <div><label>Repair dealer / license no.</label><input type="text" name="license" value="<?= $i('license') ?>" placeholder="e.g. BAR ARD #"></div><div><label>Tax ID</label><input type="text" name="tax_id" value="<?= $i('tax_id') ?>"></div>
    </div>
    <h2 style="margin-top:18px">Rates and taxes</h2>
    <div class="form-grid">
      <div><label>Labor rate per hour</label><input type="number" step="0.01" name="labor_rate" value="<?= $i('labor_rate') ?>"></div><div><label>Currency symbol</label><input type="text" name="currency" value="<?= $i('currency') ?>" maxlength="4"></div>
      <div><label>Parts tax %</label><input type="number" step="0.001" name="tax_parts" value="<?= $i('tax_parts') ?>"></div><div><label>Labor tax %</label><input type="number" step="0.001" name="tax_labor" value="<?= $i('tax_labor') ?>"></div>
      <div><label>Fees tax %</label><input type="number" step="0.001" name="tax_fees" value="<?= $i('tax_fees') ?>"></div><div></div>
      <div><label>Shop supplies (% of labor)</label><input type="number" step="0.01" name="supplies_pct" value="<?= $i('supplies_pct') ?>"></div><div><label>Shop supplies cap</label><input type="number" step="0.01" name="supplies_cap" value="<?= $i('supplies_cap') ?>"></div>
    </div>
    <h2 style="margin-top:18px">Parts pricing matrix</h2>
    <p class="muted" style="font-size:13px">Markup by the part's cost, one tier per line: <code>cost from: markup %</code>. A $10 part with 100% markup sells for $20. Typical shops mark cheap parts up more.</p>
    <textarea name="matrix" rows="7" class="mono"><?= h($matrixTxt) ?></textarea>
    <p class="muted" style="font-size:13px">Examples now: <?php foreach ([4, 20, 60, 120, 250, 600, 1200] as $c): ?>$<?= $c ?> → <?= money(matrix_price((float)$c)) ?> &nbsp; <?php endforeach; ?></p>
    <h2 style="margin-top:18px">Estimates and invoices</h2>
    <div class="form-grid">
      <div><label>Estimate / RO prefix</label><input type="text" name="est_prefix" value="<?= $i('est_prefix') ?>"></div><div><label>Next estimate number</label><input type="number" name="next_est" value="<?= $i('next_est') ?>"></div>
      <div><label>Invoice prefix</label><input type="text" name="inv_prefix" value="<?= $i('inv_prefix') ?>"></div><div><label>Next invoice number</label><input type="number" name="next_inv" value="<?= $i('next_inv') ?>"></div>
      <div class="full"><label class="inline"><input type="checkbox" name="show_rates" value="1" <?= $S['show_rates'] ? 'checked' : '' ?>> Show labor hours and rate to customers (otherwise one labor amount per service)</label></div>
      <div class="full"><label class="inline"><input type="checkbox" name="show_part_numbers" value="1" <?= $S['show_part_numbers'] ? 'checked' : '' ?>> Show part numbers and brands to customers</label></div>
      <div><label>Extra authorization needed above (%)</label><input type="number" step="0.1" name="auth_threshold_pct" value="<?= $i('auth_threshold_pct') ?>"></div><div><label>…or above (amount)</label><input type="number" step="0.01" name="auth_threshold_amt" value="<?= $i('auth_threshold_amt') ?>"></div>
      <div class="full"><small class="muted">The repair order warns when the total goes over what the customer authorized (California, for example: more than 10% or $100 over the written estimate needs documented additional authorization). Check your local rules.</small></div>
      <div class="full"><label>Warranty statement</label><textarea name="warranty" rows="2"><?= h($S['warranty']) ?></textarea></div>
      <div class="full"><label>Estimate terms</label><textarea name="est_terms" rows="2"><?= h($S['est_terms']) ?></textarea></div>
      <div class="full"><label>Invoice terms</label><textarea name="inv_terms" rows="2"><?= h($S['inv_terms']) ?></textarea></div>
    </div>
    <div class="row end" style="margin-top:12px"><button class="primary">Save settings</button></div>
  </form>
  <div class="aside">
    <div class="card" id="canned">
      <h2>Canned jobs (<?= count($canned) ?>)</h2>
      <ul class="list canned-list"><?php $lc = null; foreach ($canned as $cj): if ($cj['category'] !== $lc) { $lc = $cj['category']; echo '<li class="cat">' . h($lc) . '</li>'; } $hrs = array_sum(array_map(fn($x) => $x[0] === 'labor' ? (float)$x[2] : 0, json_decode((string)$cj['lines'], true) ?: [])); ?>
        <li><div class="grow"><a class="t" href="<?= url('shop', ['canned' => $cj['id']]) ?>#cform"><?= h($cj['name']) ?></a><small><?= $hrs ?> h · <?= count(json_decode((string)$cj['lines'], true) ?: []) ?> lines</small></div></li><?php endforeach; ?></ul>
    </div>
    <div class="card" id="cform" style="margin-top:14px">
      <h2><?= $ec ? 'Edit canned job' : 'New canned job' ?></h2>
      <form method="post"><input type="hidden" name="action" value="canned_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ec ? (int)$ec['id'] : '' ?>">
        <label>Name</label><input type="text" name="name" value="<?= h($ec['name'] ?? '') ?>" required>
        <label>Category</label><input type="text" name="category" value="<?= h($ec['category'] ?? 'My jobs') ?>">
        <label>Lines: <code>type | description | qty or hours | cost</code></label>
        <textarea name="lines" rows="6" class="mono" placeholder="labor | Replace thermostat, refill and bleed | 1.2&#10;part | Thermostat | 1 | 18.50&#10;fee | Coolant disposal | 1"><?php $ecTxt = $ec ? implode("\n", array_map(fn($x) => implode(' | ', array_filter([$x[0], $x[1], $x[2] ?? 1, !empty($x[3]) ? $x[3] : null], fn($v) => $v !== null)), json_decode((string)$ec['lines'], true) ?: [])) : ''; echo h($ecTxt); ?></textarea>
        <div class="row" style="margin-top:8px;gap:8px"><button class="primary sm">Save</button><?php if ($ec): ?><a class="btn sm ghost" href="<?= url('shop') ?>#cform">New</a><?php endif; ?></div></form>
      <?php if ($ec): ?><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="canned_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$ec['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this canned job?">Delete</button></form><?php endif; ?>
    </div>
  </div>
</div>

<div class="card" id="catalog" style="margin-top:18px">
  <h2>Parts catalog &amp; inventory</h2>
  <p class="muted" style="font-size:13px">Parts you stock or buy often. They show first in "Find part" on a repair order, and stock goes down when an invoice is created.</p>
  <div class="grid g3">
    <div style="grid-column:span 2">
      <form method="get" class="row" style="flex-wrap:nowrap;margin-bottom:10px"><input type="hidden" name="p" value="shop"><input type="search" name="cq" value="<?= h($cq) ?>" placeholder="Search name, part number, brand"><button class="sm">Find</button></form>
      <?php if (!$catalog): ?><div class="empty">No parts yet.</div><?php else: ?>
      <table class="cat-table"><thead><tr><th>Part</th><th>Cost</th><th>Price</th><th>Stock</th><th></th></tr></thead><tbody>
        <?php foreach ($catalog as $p): ?><tr class="<?= (float)$p['qty'] <= 0 ? 'oos' : '' ?>"><td><b><?= h($p['name']) ?></b><br><small class="muted"><?= h(trim($p['brand'] . ' ' . $p['part_no'])) ?><?= $p['supplier'] ? ' · ' . h($p['supplier']) : '' ?><?= $p['location'] ? ' · bin ' . h($p['location']) : '' ?></small></td>
          <td class="num"><?= money((float)$p['cost']) ?></td><td class="num"><?= money($p['price'] !== null ? (float)$p['price'] : matrix_price((float)$p['cost'])) ?><?= $p['price'] === null ? '<br><small class="muted">matrix</small>' : '' ?></td><td class="num"><?= rtrim(rtrim(number_format((float)$p['qty'], 2), '0'), '.') ?></td>
          <td><a class="btn sm ghost" href="<?= url('shop', ['part' => $p['id']]) ?>#pform">edit</a></td></tr><?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </div>
    <div>
      <form method="post" id="pform"><input type="hidden" name="action" value="catalog_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $ep ? (int)$ep['id'] : '' ?>">
        <h3><?= $ep ? 'Edit part' : 'Add a part' ?></h3>
        <label>Name</label><input type="text" name="name" value="<?= h($ep['name'] ?? '') ?>" required>
        <div class="form-grid"><div><label>Part number</label><input type="text" name="part_no" value="<?= h($ep['part_no'] ?? '') ?>"></div><div><label>Brand</label><input type="text" name="brand" value="<?= h($ep['brand'] ?? '') ?>"></div>
          <div><label>Cost</label><input type="number" step="0.01" name="cost" value="<?= h((string)($ep['cost'] ?? '')) ?>"></div><div><label>Price (blank = matrix)</label><input type="number" step="0.01" name="price" value="<?= h((string)($ep['price'] ?? '')) ?>"></div>
          <div><label>In stock</label><input type="number" step="0.01" name="qty" value="<?= h((string)($ep['qty'] ?? '0')) ?>"></div><div><label>Bin / location</label><input type="text" name="location" value="<?= h($ep['location'] ?? '') ?>"></div>
          <div class="full"><label>Supplier</label><input type="text" name="supplier" value="<?= h($ep['supplier'] ?? '') ?>"></div></div>
        <div class="row" style="margin-top:8px;gap:8px"><button class="primary sm">Save part</button><?php if ($ep): ?><a class="btn sm ghost" href="<?= url('shop') ?>#pform">New</a><?php endif; ?></div></form>
      <?php if ($ep): ?><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="catalog_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$ep['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this part?">Delete part</button></form><?php endif; ?>
      <form method="post" enctype="multipart/form-data" style="margin-top:16px"><input type="hidden" name="action" value="catalog_import"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <h3>Import CSV</h3><small class="muted">Columns: part number, name, brand, cost, price, qty, supplier, location</small>
        <input type="file" name="csv" accept=".csv,text/csv" required style="margin-top:6px"><button class="sm" style="margin-top:6px">Import</button></form>
    </div>
  </div>
</div>
