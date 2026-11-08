<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$pid = (int)($_GET['id'] ?? 0);
if (!owns_row('purchase_orders', $pid)) { http_response_code(404); echo '<div class="empty">Purchase order not found.</div>'; return; }
$po = row('SELECT * FROM purchase_orders WHERE id=?', [$pid]);
$V = $po['vendor_id'] ? row('SELECT * FROM vendors WHERE id=?', [$po['vendor_id']]) : null;
$lines = rows('SELECT p.*, l.job_id, j.est_no FROM po_lines p LEFT JOIN job_lines l ON l.id=p.job_line_id LEFT JOIN jobs j ON j.id=l.job_id WHERE p.po_id=? ORDER BY p.id', [$pid]);
$S = shop(); $title = $po['po_no'];
$csrf = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$edit = in_array($po['status'], ['draft', 'ordered', 'partial'], true);
$body = 'Purchase order ' . $po['po_no'] . ' from ' . ($S['name'] ?: 'our shop') . ($V && $V['account_no'] ? ' (account ' . $V['account_no'] . ')' : '') . ":\n\n"
    . implode("\n", array_map(fn($l) => rtrim(rtrim(number_format((float)$l['qty'], 2), '0'), '.') . ' x ' . trim($l['brand'] . ' ' . $l['part_no']) . ' ' . $l['description'], $lines))
    . "\n\nPlease confirm price and availability. Thank you.\n" . ($S['phone'] ?: '');
?>
<div class="head"><div><h1><?= h($po['po_no']) ?> <span class="st"><?= h(PO_STATUS[$po['status']]) ?></span></h1><p><?= h($V['name'] ?? 'No vendor') ?> · created <?= fmt_date($po['created_at']) ?><?= $po['ordered_at'] ? ' · ordered ' . fmt_date($po['ordered_at']) : '' ?><?= $po['ref'] ? ' · vendor order # ' . h($po['ref']) : '' ?></p></div>
  <div class="row" style="gap:6px;flex-wrap:wrap"><a class="btn sm" href="<?= url('purchasing') ?>">← Parts ordering</a><a class="btn sm" href="<?= url('po_print', ['id' => $pid]) ?>" target="_blank">Print</a>
    <?php if ($V && $V['email']): ?><a class="btn sm" href="mailto:<?= h($V['email']) ?>?subject=<?= h(rawurlencode('PO ' . $po['po_no'])) ?>&amp;body=<?= h(rawurlencode($body)) ?>">Email vendor</a><?php endif; ?>
    <?php if ($V && $V['phone']): ?><a class="btn sm" href="tel:<?= h(preg_replace('/[^\d+]/', '', (string)$V['phone'])) ?>">Call</a><?php endif; ?>
    <?php if ($V && $V['website']): ?><a class="btn sm" href="<?= h(preg_match('~^https?://~', (string)$V['website']) ? $V['website'] : 'https://' . $V['website']) ?>" target="_blank" rel="noopener">Order site ↗</a><?php endif; ?></div></div>

<div class="grid g3">
  <div class="card" style="grid-column:span 2">
    <?php if (in_array($po['status'], ['ordered', 'partial'], true)): ?>
      <form method="post" id="recv"><input type="hidden" name="action" value="po_receive"><?= $csrf ?><input type="hidden" name="id" value="<?= $pid ?>"></form>
    <?php endif; ?>
    <table class="po-table"><thead><tr><th>Part</th><th class="num">Qty</th><th class="num">Cost</th><th class="num">Total</th><?= in_array($po['status'], ['ordered', 'partial'], true) ? '<th class="num">Received</th>' : '' ?></tr></thead><tbody>
      <?php foreach ($lines as $l): ?><tr>
        <td><?php if ($edit): ?><details><summary><b><?= h($l['description']) ?></b></summary>
            <form method="post" class="form-grid"><input type="hidden" name="action" value="po_line_save"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <div class="full"><label>Description</label><input type="text" name="description" value="<?= h($l['description']) ?>"></div>
              <div><label>Part number</label><input type="text" name="part_no" value="<?= h($l['part_no']) ?>"></div><div><label>Brand</label><input type="text" name="brand" value="<?= h($l['brand']) ?>"></div>
              <div><label>Qty</label><input type="number" step="0.01" name="qty" value="<?= h((string)(float)$l['qty']) ?>"></div><div><label>Cost each</label><input type="number" step="0.01" name="cost" value="<?= h((string)(float)$l['cost']) ?>"></div>
              <div class="full row" style="gap:8px"><button class="sm primary">Save</button></div></form>
            <form method="post"><input type="hidden" name="action" value="po_line_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="sm ghost danger">Remove line</button></form></details>
          <?php else: ?><b><?= h($l['description']) ?></b><?php endif; ?>
          <small class="muted"><?= h(trim($l['brand'] . ' ' . $l['part_no'])) ?><?= $l['est_no'] ? ' · for <a href="' . url('job', ['id' => $l['job_id']]) . '">' . h($l['est_no']) . '</a>' : ($l['catalog_id'] ? ' · stock' : '') ?></small></td>
        <td class="num"><?= rtrim(rtrim(number_format((float)$l['qty'], 2), '0'), '.') ?></td><td class="num"><?= money((float)$l['cost']) ?></td><td class="num"><?= money((float)$l['qty'] * (float)$l['cost']) ?></td>
        <?php if (in_array($po['status'], ['ordered', 'partial'], true)): ?><td class="num"><input form="recv" type="number" step="0.01" min="0" name="rq[<?= (int)$l['id'] ?>]" value="<?= h((string)((float)$l['qty_received'] ?: (float)$l['qty'])) ?>" style="width:80px"></td><?php endif; ?>
      </tr><?php endforeach; ?>
      <?php if (!$lines): ?><tr><td colspan="5" class="muted">No lines yet.</td></tr><?php endif; ?>
      <tr class="tot"><td colspan="3">Total</td><td class="num"><b><?= money((float)$po['total']) ?></b></td><?= in_array($po['status'], ['ordered', 'partial'], true) ? '<td></td>' : '' ?></tr>
    </tbody></table>
    <?php if (in_array($po['status'], ['ordered', 'partial'], true)): ?><div class="row end" style="margin-top:10px"><button form="recv" class="primary">Receive</button></div><?php endif; ?>
    <?php if ($edit): ?>
    <details style="margin-top:14px"><summary><b>＋ Add a line</b></summary>
      <form method="post" class="form-grid"><input type="hidden" name="action" value="po_line_add"><?= $csrf ?><input type="hidden" name="po_id" value="<?= $pid ?>">
        <div class="full"><label>From your catalog (stock)</label><select name="catalog_id"><option value="">—</option><?php foreach (rows('SELECT id, name, part_no, qty FROM parts_catalog WHERE user_id=? ORDER BY name LIMIT 500', [uid()]) as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name'] . ($c['part_no'] ? ' · ' . $c['part_no'] : '') . ' · ' . (float)$c['qty'] . ' in stock') ?></option><?php endforeach; ?></select></div>
        <div class="full"><label>…or describe it</label><input type="text" name="description"></div>
        <div><label>Part number</label><input type="text" name="part_no"></div><div><label>Brand</label><input type="text" name="brand"></div>
        <div><label>Qty</label><input type="number" step="0.01" name="qty" value="1"></div><div><label>Cost each</label><input type="number" step="0.01" name="cost"></div>
        <div class="full"><button class="sm primary">Add</button></div></form></details>
    <?php endif; ?>
  </div>
  <div class="aside">
    <div class="card">
      <?php if ($po['status'] === 'draft'): ?>
        <form method="post"><input type="hidden" name="action" value="po_status"><?= $csrf ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="to" value="ordered">
          <label>Vendor's order / confirmation # (optional)</label><input type="text" name="ref"><button class="primary" style="margin-top:8px">Mark as ordered</button></form>
        <p class="muted" style="font-size:12.5px">Ordering marks the parts "Ordered" on their repair orders, and approved orders move to Waiting for parts.</p>
      <?php elseif (in_array($po['status'], ['ordered', 'partial'], true)): ?>
        <p>Set the received quantities and press <b>Receive</b>. Repair orders whose parts are all in go back to In progress.</p>
      <?php endif; ?>
      <div class="row" style="gap:6px;flex-wrap:wrap;margin-top:10px">
        <?php if ($po['status'] !== 'cancelled' && $po['status'] !== 'received'): ?><form method="post"><input type="hidden" name="action" value="po_status"><?= $csrf ?><input type="hidden" name="id" value="<?= $pid ?>"><button class="sm ghost" name="to" value="cancelled" data-confirm="Cancel this purchase order? Its parts go back to the to-order list.">Cancel PO</button></form><?php endif; ?>
        <?php if (in_array($po['status'], ['draft', 'cancelled'], true)): ?><form method="post"><input type="hidden" name="action" value="po_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= $pid ?>"><button class="sm ghost danger" data-confirm="Delete this purchase order?">Delete</button></form><?php endif; ?>
      </div>
    </div>
    <div class="card" style="margin-top:14px"><h3>Vendor</h3>
      <div class="po-vendor">
        <?php if ($V): ?><p><b><?= h($V['name']) ?></b><br><small class="muted"><?= h(implode(' · ', array_filter([$V['account_no'] ? 'acct ' . $V['account_no'] : '', $V['phone'], $V['email']]))) ?></small></p><a class="btn sm ghost" href="<?= url('purchasing', ['vendor' => $V['id']]) ?>#vform">Edit vendor</a>
        <?php else: ?><p class="muted">No vendor on this order.</p><a class="btn sm" href="<?= url('purchasing') ?>#vform">Add vendors</a><?php endif; ?></div>
    </div>
  </div>
</div>
