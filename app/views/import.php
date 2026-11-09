<?php
// Bring customers, vehicles and past repair orders over from another system; download everything to leave.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Switch to ' . APP_NAME;
$file = (string)($_SESSION['import_file'] ?? '');
$mapping = ($_GET['step'] ?? '') === 'map' && $file !== '' && str_starts_with($file, DATA_DIR . '/imports/u' . uid() . '-') && is_file($file);
$csrfIn = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$counts = ['customers' => (int)col('SELECT COUNT(*) FROM customers WHERE user_id=?', [uid()]), 'vehicles' => (int)col('SELECT COUNT(*) FROM vehicle WHERE user_id=? AND customer_id IS NOT NULL', [uid()]), 'history' => (int)col('SELECT COUNT(*) FROM legacy_ros WHERE user_id=?', [uid()])];
?>
<div class="head"><div><h1>Switch in, switch out</h1><p>Bring your customers, their vehicles and past repair orders from your old system in a few minutes. And take everything with you any time: your data is yours.</p></div></div>

<?php if ($mapping): $rows = import_rows($file, 6); $head = $rows[0]; $sample = array_slice($rows, 1, 4); $guess = import_guess($head); ?>
<form method="post" class="card"><input type="hidden" name="action" value="import_run"><?= $csrfIn ?>
  <h2>Check the columns</h2>
  <p class="muted" style="margin-top:-6px">We matched your columns by name. Fix anything that's wrong, then import. Columns you leave on "—" are skipped.</p>
  <div class="imp-map"><?php foreach (IMPORT_FIELDS as $k => [$lb]): ?><label class="imp-row"><span><?= h($lb) ?></span><select name="map[<?= $k ?>]"><option value="">—</option><?php foreach ($head as $i => $hcol): ?><option value="<?= $i ?>" <?= isset($guess[$k]) && $guess[$k] === $i ? 'selected' : '' ?>><?= h($hcol !== '' ? $hcol : 'Column ' . ($i + 1)) ?></option><?php endforeach; ?></select></label><?php endforeach; ?></div>
  <h3 style="margin-top:14px">First rows of your file</h3>
  <div style="overflow-x:auto"><table class="imp-prev"><tr><?php foreach ($head as $hcol): ?><th><?= h($hcol) ?></th><?php endforeach; ?></tr><?php foreach ($sample as $r): ?><tr><?php foreach ($head as $i => $_): ?><td><?= h(mb_strimwidth((string)($r[$i] ?? ''), 0, 40, '…')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></table></div>
  <div class="row" style="gap:8px;margin-top:14px"><button class="primary">Import</button><button class="ghost" form="imp-cancel">Cancel</button></div>
  <p class="muted" style="font-size:12.5px;margin-top:10px">Customers with the same email or phone, and vehicles with the same VIN, are matched, not duplicated. Past repair orders are kept as read-only history on the customer and the vehicle.</p>
</form>
<form method="post" id="imp-cancel"><input type="hidden" name="action" value="import_cancel"><?= $csrfIn ?></form>
<?php else: ?>
<div class="grid g3">
  <div style="grid-column:span 2">
    <form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="action" value="import_upload"><?= $csrfIn ?>
      <h2>Import from your old system</h2>
      <ol class="ho-steps" style="margin-bottom:12px"><li><b>Export from your old system as CSV.</b> Customers, vehicles, or repair order / invoice history: one file at a time, in any order.</li><li><b>Upload it here.</b> We match the columns by name; you check them before anything is saved.</li><li><b>Done.</b> Run it again for the next file. Nothing is duplicated.</li></ol>
      <label>Coming from</label><select name="source"><?php foreach (['Tekmetric', 'Shopmonkey', 'Mitchell 1', 'Shop-Ware', 'AutoLeap', 'Protractor', 'RO Writer', 'a spreadsheet', 'another system'] as $s): ?><option><?= h($s) ?></option><?php endforeach; ?></select>
      <label>CSV file</label><input type="file" name="csv" accept=".csv,.txt,.tsv,text/csv" required>
      <div style="margin-top:12px"><button class="primary">Upload and check</button></div>
      <p class="muted" style="font-size:12.5px;margin-top:10px">Where to find the export: Tekmetric and Shopmonkey have it under Reports or Customers (Export to CSV); Mitchell 1 Manager SE under Reports. Excel or Google Sheets files: "Save as / Download as CSV". Need a hand? We can do it with you.</p>
    </form>
  </div>
  <div class="aside">
    <div class="card"><h2>In your account</h2><ul class="plist">
      <li><a href="<?= url('customers') ?>"><span class="grow"><b><?= number_format($counts['customers']) ?> customers</b></span></a></li>
      <li><a href="<?= url('customers') ?>"><span class="grow"><b><?= number_format($counts['vehicles']) ?> customer vehicles</b></span></a></li>
      <li><a><span class="grow"><b><?= number_format($counts['history']) ?> past repair orders</b><small>from your old system</small></span></a></li></ul></div>
    <div class="card" style="margin-top:14px"><h2>Take everything with you</h2>
      <p class="muted" style="font-size:13.5px">One ZIP with every record (JSON), spreadsheets of customers, vehicles and repair orders (CSV), and your photos. No questions asked, no fee.</p>
      <?php if (class_exists('ZipArchive')): ?><a class="btn primary sm" href="<?= url('export', ['zip' => 1]) ?>">⬇ Download everything</a><?php else: ?><a class="btn sm" href="<?= url('export') ?>">⬇ Download my data (JSON)</a><?php endif; ?></div>
  </div>
</div>
<?php endif; ?>
