<?php
// Printable purchase order (signed in).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$pid = (int)($_GET['id'] ?? 0);
if (!owns_row('purchase_orders', $pid)) { http_response_code(404); exit('Not found'); }
$po = row('SELECT * FROM purchase_orders WHERE id=?', [$pid]);
$V = $po['vendor_id'] ? row('SELECT * FROM vendors WHERE id=?', [$po['vendor_id']]) : null;
$lines = rows('SELECT * FROM po_lines WHERE po_id=? ORDER BY id', [$pid]);
$S = shop();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Purchase order <?= h($po['po_no']) ?></title><link rel="stylesheet" href="assets/doc.css?v=4"></head><body>
<div class="bar"><a href="<?= url('po', ['id' => $pid]) ?>">← Back</a><button type="button" class="primary" onclick="print()">Print / save as PDF</button></div>
<div class="page">
  <header class="doc-head"><div><b class="shop-name"><?= h($S['name'] ?: APP_NAME) ?></b><p><?= nl2br(h(implode("\n", array_filter([$S['address'], $S['phone'], $S['email']])))) ?></p></div>
    <div class="doc-id"><b>Purchase order</b><span><?= h($po['po_no']) ?></span><small><?= h(date('M j, Y', strtotime((string)($po['ordered_at'] ?: $po['created_at'])))) ?><?= $po['ref'] ? ' · vendor ref ' . h($po['ref']) : '' ?></small></div></header>
  <section class="doc-parties"><div><small>Vendor</small><p><b><?= h($V['name'] ?? '—') ?></b><?= $V ? '<br>' . nl2br(h(implode("\n", array_filter([$V['account_no'] ? 'Account ' . $V['account_no'] : '', $V['contact'], $V['phone'], $V['email']])))) : '' ?></p></div>
    <div><small>Ship to</small><p><b><?= h($S['name'] ?: '') ?></b><br><?= h($S['address']) ?></p></div></section>
  <table class="items"><thead><tr><th>Qty</th><th>Part</th><th>Part number</th><th class="num">Cost</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($lines as $l): ?><tr><td><?= rtrim(rtrim(number_format((float)$l['qty'], 2), '0'), '.') ?></td><td><?= h($l['description']) ?></td><td><?= h(trim($l['brand'] . ' ' . $l['part_no'])) ?></td><td class="num"><?= money((float)$l['cost']) ?></td><td class="num"><?= money((float)$l['qty'] * (float)$l['cost']) ?></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><td colspan="4" class="num"><b>Total</b></td><td class="num"><b><?= money((float)$po['total']) ?></b></td></tr></tfoot></table>
  <?php if ($po['notes']): ?><p><?= nl2br(h((string)$po['notes'])) ?></p><?php endif; ?>
</div></body></html>
