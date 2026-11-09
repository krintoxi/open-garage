<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Parts ordering';
$onlyJob = (int)($_GET['job'] ?? 0); $onlyJob = owns_row('jobs', $onlyJob) ? $onlyJob : 0;
$need = parts_to_order($onlyJob ?: null);
$bySup = []; foreach ($need as $l) $bySup[trim((string)$l['supplier']) ?: 'No supplier yet'][] = $l;
$vlist = vendors();
$pos = rows("SELECT p.*, v.name vname, (SELECT COUNT(*) FROM po_lines WHERE po_id=p.id) n FROM purchase_orders p LEFT JOIN vendors v ON v.id=p.vendor_id WHERE p.user_id=? ORDER BY p.status IN ('received','cancelled'), p.id DESC LIMIT 100", [uid()]);
$oos = (int)col('SELECT COUNT(*) FROM parts_catalog WHERE user_id=? AND qty <= 0', [uid()]);
$ev = ($evid = (int)($_GET['vendor'] ?? 0)) && owns_row('vendors', $evid) ? row('SELECT * FROM vendors WHERE id=?', [$evid]) : null;
$csrf = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$vendorPick = function (string $sup) use ($vlist): string {
    $o = '<option value="">Vendor…</option>'; $hit = false;
    foreach ($vlist as $v) { $m = !$hit && $sup !== '' && stripos($v['name'], $sup) !== false; $hit = $hit || $m; $o .= '<option value="' . (int)$v['id'] . '"' . ($m ? ' selected' : '') . '>' . h($v['name']) . '</option>'; }
    return '<select name="vendor_id">' . $o . '</select>' . (!$hit ? '<input type="text" name="new_vendor" value="' . h($sup === 'No supplier yet' ? '' : $sup) . '" placeholder="or a new vendor name">' : '');
};
?>
<div class="head"><div><h1>Parts ordering</h1><p>Parts approved on repair orders that still need ordering, purchase orders to your vendors, and receiving. Received parts update the repair order (and stock for stock orders).</p></div>
  <a class="btn" href="<?= url('jobs') ?>">← Shop board</a></div>
<div class="grid g3">
  <div style="grid-column:span 2">
    <div class="card">
      <h2>To order<?= $onlyJob ? ' for ' . h(job_label(row('SELECT * FROM jobs WHERE id=?', [$onlyJob]))) : '' ?> <span class="muted"><?= count($need) ?></span></h2>
      <?php if (!$need): ?><div class="empty">Nothing waiting to be ordered<?= $onlyJob ? ' on this order' : '' ?>. Parts show here once their service is approved (and not covered by stock).</div><?php endif; ?>
      <?php foreach ($bySup as $sup => $ls): ?>
        <form method="post" class="po-group"><input type="hidden" name="action" value="po_new"><?= $csrf ?>
          <h3><?= h($sup) ?></h3>
          <ul class="list"><?php foreach ($ls as $l): ?><li><label class="inline" style="margin:0"><input type="checkbox" name="lines[]" value="<?= (int)$l['id'] ?>" checked></label>
            <div class="grow"><span class="t"><?= rtrim(rtrim(number_format((float)$l['qty'], 2), '0'), '.') ?> × <?= h($l['description']) ?></span><small><?= h(trim($l['brand'] . ' ' . $l['part_no'])) ?> · <a href="<?= url('job', ['id' => $l['jid']]) ?>#l<?= (int)$l['id'] ?>"><?= h($l['est_no']) ?></a><?= $l['cname'] ? ' · ' . h($l['cname']) : '' ?><?= $l['jstage'] === 'waiting_parts' ? ' · waiting' : '' ?></small></div>
            <b><?= (float)$l['cost'] > 0 ? money((float)$l['cost'] * (float)$l['qty']) : '<small class="warn-t">cost?</small>' ?></b></li><?php endforeach; ?></ul>
          <div class="row" style="gap:8px;flex-wrap:wrap;margin-top:8px"><?= $vendorPick($sup) ?><button class="primary sm">Create purchase order</button></div>
        </form>
      <?php endforeach; ?>
      <?php if ($oos): ?><form method="post" style="margin-top:14px"><input type="hidden" name="action" value="po_new"><?= $csrf ?><input type="hidden" name="restock" value="1">
        <p class="muted" style="font-size:13px"><?= $oos ?> catalog part<?= $oos === 1 ? ' is' : 's are' ?> out of stock.</p><div class="row" style="gap:8px;flex-wrap:wrap"><?= $vendorPick('') ?><button class="sm">Restock order</button></div></form><?php endif; ?>
    </div>
    <?php returns_sync(); $RT = returns_open(); $credited = (float)col("SELECT COALESCE(SUM(amount),0) FROM part_returns WHERE user_id=? AND status='credited' AND credited_on >= ?", [uid(), date('Y-m-d', strtotime('-90 days'))]); ?>
    <div class="card" id="returns" style="margin-top:18px">
      <h2>Cores &amp; returns <span class="muted"><?= count($RT['items']) ?></span></h2>
      <p class="muted" style="font-size:13px;margin-top:-6px">Old parts to send back for core credit, and wrong or unneeded parts to return. <?= money($RT['owed']) ?> still owed to you<?= $RT['overdue'] ? ' · <b class="warn-t">' . $RT['overdue'] . ' overdue</b>' : '' ?> · <?= money($credited) ?> credited in the last 90 days.</p>
      <?php if (!$RT['items']): ?><div class="empty">Nothing waiting. Core charges on approved jobs show up here by themselves.</div><?php endif; ?>
      <ul class="list"><?php foreach ($RT['items'] as $r): $late = $r['due_on'] && $r['due_on'] < today(); ?><li class="ret-row">
        <div class="grow"><span class="t"><?= h($r['description']) ?><?= $r['part_no'] ? ' <small class="muted">' . h($r['part_no']) . '</small>' : '' ?></span>
          <small><?= h(RETURN_KINDS[$r['kind']] ?? $r['kind']) ?> · <?= h($r['vname'] ?: 'vendor?') ?><?= $r['job_id'] ? ' · <a href="' . url('job', ['id' => $r['job_id']]) . '">' . h($r['inv_no'] ? 'Invoice ' . $r['inv_no'] : $r['est_no']) . '</a>' : '' ?> · <?= $r['status'] === 'sent' ? 'sent ' . h(date('M j', strtotime((string)$r['sent_on']))) : ($late ? '<b class="warn-t">return by ' . h(date('M j', strtotime((string)$r['due_on']))) . '</b>' : 'return by ' . h(date('M j', strtotime((string)$r['due_on'])))) ?></small></div>
        <b><?= money((float)$r['amount']) ?></b>
        <?php if ($r['status'] === 'open'): ?><form method="post"><input type="hidden" name="action" value="ret_status"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="sm" name="to" value="sent">Sent back</button></form><?php endif; ?>
        <details class="menu-pop"><summary class="btn sm <?= $r['status'] === 'sent' ? 'primary' : 'ghost' ?>"><?= $r['status'] === 'sent' ? 'Credit received' : '⋯' ?></summary><div>
          <form method="post" class="ret-credit"><input type="hidden" name="action" value="ret_status"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="credited"><input type="text" name="credit_ref" placeholder="Credit memo #"><input type="number" step="0.01" name="amount" value="<?= h((string)(float)$r['amount']) ?>"><button class="primary">Credit received</button></form>
          <form method="post"><input type="hidden" name="action" value="ret_status"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="to" value="lost" class="danger" data-confirm="Write this off? It stops counting as money owed.">Write off</button></form>
        </div></details></li><?php endforeach; ?></ul>
      <details style="margin-top:10px"><summary><b>＋ Add a return</b></summary>
        <form method="post" class="form-grid" style="margin-top:8px"><input type="hidden" name="action" value="ret_add"><?= $csrf ?>
          <div><label>Type</label><select name="kind"><?php foreach (RETURN_KINDS as $k => $lb): ?><option value="<?= $k ?>" <?= $k === 'return' ? 'selected' : '' ?>><?= h($lb) ?></option><?php endforeach; ?></select></div>
          <div><label>Vendor</label><select name="vendor_id"><option value="">—</option><?php foreach ($vlist as $v): ?><option value="<?= (int)$v['id'] ?>"><?= h($v['name']) ?></option><?php endforeach; ?></select></div>
          <div class="full"><label>Part</label><input type="text" name="description" placeholder="e.g. Front pads (wrong fitment)" required></div>
          <div><label>Part number</label><input type="text" name="part_no"></div><div><label>Credit expected</label><input type="number" step="0.01" name="amount"></div>
          <div><label>Return within (days)</label><input type="number" name="days" value="30"></div><div></div>
          <div class="full"><button class="sm primary">Add</button></div></form></details>
    </div>
    <div class="card" style="margin-top:18px">
      <h2>Purchase orders</h2>
      <?php if (!$pos): ?><div class="empty">None yet.</div><?php endif; ?>
      <ul class="list"><?php foreach ($pos as $p): ?><li><div class="grow"><a class="t" href="<?= url('po', ['id' => $p['id']]) ?>"><?= h($p['po_no']) ?> · <?= h($p['vname'] ?: 'No vendor') ?></a><small><?= fmt_date($p['created_at']) ?> · <?= (int)$p['n'] ?> line<?= (int)$p['n'] === 1 ? '' : 's' ?><?= $p['ref'] ? ' · vendor # ' . h($p['ref']) : '' ?></small></div>
        <span class="st st-<?= h(['draft' => 'planned', 'ordered' => 'ordered', 'partial' => 'soon', 'received' => 'done', 'cancelled' => 'cancelled'][$p['status']] ?? '') ?>"><?= h(PO_STATUS[$p['status']] ?? $p['status']) ?></span><b><?= money((float)$p['total']) ?></b></li><?php endforeach; ?></ul>
      <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="po_new"><?= $csrf ?><div class="row" style="gap:8px;flex-wrap:wrap"><?= $vendorPick('') ?><button class="sm">＋ Blank purchase order</button></div></form>
    </div>
  </div>
  <div class="aside">
    <div class="card" id="vendors"><h2>Vendors</h2>
      <ul class="list"><?php foreach ($vlist as $v): ?><li><div class="grow"><a class="t" href="<?= url('purchasing', ['vendor' => $v['id']]) ?>#vform"><?= h($v['name']) ?></a><small><?= h(implode(' · ', array_filter([$v['account_no'] ? 'acct ' . $v['account_no'] : '', $v['phone'], $v['contact']]))) ?></small></div>
        <?php if ($v['website']): ?><a class="btn sm ghost" href="<?= h(preg_match('~^https?://~', (string)$v['website']) ? $v['website'] : 'https://' . $v['website']) ?>" target="_blank" rel="noopener">Site ↗</a><?php endif; ?></li><?php endforeach; ?></ul>
      <?php if (!$vlist): ?><p class="muted" style="font-size:13px">Add the stores and distributors you buy from: your account number prints on the PO.</p><?php endif; ?>
    </div>
    <div class="card" id="vform" style="margin-top:14px"><h3><?= $ev ? 'Edit vendor' : 'Add a vendor' ?></h3>
      <form method="post"><input type="hidden" name="action" value="vendor_save"><?= $csrf ?><input type="hidden" name="id" value="<?= $ev ? (int)$ev['id'] : '' ?>">
        <label>Name</label><input type="text" name="name" value="<?= h($ev['name'] ?? '') ?>" required placeholder="e.g. O'Reilly #1234, WorldPac">
        <div class="form-grid"><div><label>Account #</label><input type="text" name="account_no" value="<?= h($ev['account_no'] ?? '') ?>"></div><div><label>Phone</label><input type="tel" name="phone" value="<?= h($ev['phone'] ?? '') ?>"></div>
          <div><label>Contact</label><input type="text" name="contact" value="<?= h($ev['contact'] ?? '') ?>"></div><div><label>Email</label><input type="email" name="email" value="<?= h($ev['email'] ?? '') ?>"></div>
          <div class="full"><label>Ordering website</label><input type="text" name="website" value="<?= h($ev['website'] ?? '') ?>" placeholder="e.g. app.partstech.com"></div>
          <div class="full"><label>Notes</label><input type="text" name="notes" value="<?= h($ev['notes'] ?? '') ?>" placeholder="delivery runs, cutoff times"></div></div>
        <div class="row" style="gap:8px;margin-top:8px"><button class="primary sm">Save</button><?php if ($ev): ?><a class="btn sm ghost" href="<?= url('purchasing') ?>#vform">New</a><?php endif; ?></div></form>
      <?php if ($ev): ?><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="vendor_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>"><button class="sm ghost danger" data-confirm="Remove this vendor?">Remove</button></form><?php endif; ?>
    </div>
    <div class="card" style="margin-top:14px"><h3>Ordering online</h3><p class="muted" style="font-size:13px">Order on the supplier's site (PartsTech, Nexpart, WorldPac, your parts store), then copy the cart or quote and use <b>Paste a supplier cart</b> on the repair order: part numbers, brands, quantities and your cost come in, priced with your matrix. Direct ordering inside the app needs a partner agreement with those networks.</p></div>
  </div>
</div>
