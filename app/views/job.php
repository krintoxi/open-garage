<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$jid = (int)($_GET['id'] ?? 0);
if (!owns_row('jobs', $jid)) { http_response_code(404); echo '<div class="empty">Repair order not found.</div>'; return; }
$job = row('SELECT * FROM jobs WHERE id=?', [$jid]);
if (!(int)$job['seen_approval']) q('UPDATE jobs SET seen_approval=1 WHERE id=?', [$jid]);
$S = shop();
$lines = job_lines($jid);
$est = in_array($job['stage'], ['estimate', 'declined'], true);
$tq = job_totals($job, $lines, 'quoted'); $ta = job_totals($job, $lines, 'approved');
$T = $est ? $tq : $ta;
$auth = job_auth_state($job, $lines);
$auths = rows('SELECT * FROM job_auth WHERE job_id=? ORDER BY id', [$jid]);
$pays = rows('SELECT * FROM job_payments WHERE job_id=? ORDER BY paid_on, id', [$jid]);
$insp = rows('SELECT * FROM job_inspect WHERE job_id=? ORDER BY sort, id', [$jid]);
$V = job_vehicle($job); $C = job_customer($job);
$groups = []; foreach ($lines as $l) $groups[$l['grp'] ?: 'General'][] = $l;
$customers = rows('SELECT id, name FROM customers WHERE user_id=? ORDER BY name', [uid()]);
$allVeh = rows('SELECT v.*, c.name cname FROM vehicle v LEFT JOIN customers c ON c.id=v.customer_id WHERE v.user_id=? ORDER BY c.name IS NULL DESC, c.name, v.year', [uid()]);
$canned = canned_jobs(); $cats = []; foreach ($canned as $cj) $cats[$cj['category']][] = $cj;
$title = job_label($job);
$link = $job['token'] ? base_url() . '/' . url('quote', ['t' => $job['token']]) : '';
$balance = round($job['total'] - $job['paid'], 2);
$ico = ['labor' => '🔧', 'part' => '⚙', 'fee' => '＄', 'sublet' => '⇄', 'core' => '♻', 'note' => '✎'];
$next = match ($job['stage']) {
    'estimate' => [['approved', 'Mark approved', 'primary'], ['declined', 'Declined', 'ghost']],
    'approved' => [['in_progress', 'Start work', 'primary'], ['waiting_parts', 'Waiting for parts', '']],
    'waiting_parts' => [['in_progress', 'Parts here: start work', 'primary']],
    'in_progress' => [['done', 'Work done', 'primary'], ['waiting_parts', 'Waiting for parts', '']],
    'done' => [['invoiced', 'Create invoice', 'primary'], ['in_progress', 'Reopen work', 'ghost']],
    'invoiced' => [['done', 'Undo invoice', 'ghost']],
    'paid' => [], 'declined' => [['estimate', 'Back to estimate', '']], default => [['estimate', 'Restore as estimate', '']],
};
$csrf = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
?>
<div class="head ro-head">
  <div><h1><?= h($title) ?> <span class="st stage-<?= h($job['stage']) ?>"><?= h(JOB_STAGES[$job['stage']]) ?></span></h1>
    <p><?= h($C['name'] ?? 'No customer') ?><?= $V ? ' · ' . h(vehicle_name($V)) . ($V['plate'] ? ' · ' . h($V['plate']) : '') : '' ?> · opened <?= fmt_date($job['created_at']) ?><?= $job['inv_no'] ? ' · invoice ' . h($job['inv_no']) : '' ?></p></div>
  <div class="row" style="gap:6px;flex-wrap:wrap">
    <?php foreach ($next as [$to, $lbl, $cls]): ?><form method="post"><input type="hidden" name="action" value="job_stage"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>"><input type="hidden" name="to" value="<?= $to ?>"><button class="<?= $cls ?>" <?= $to === 'invoiced' && $auth['over'] ? 'data-confirm="The total is over the amount the customer authorized. Record additional authorization first unless you already have it. Create the invoice anyway?"' : '' ?>><?= h($lbl) ?></button></form><?php endforeach; ?>
    <details class="menu-pop"><summary class="btn">Print ▾</summary><div>
      <a href="<?= url('job_print', ['id' => $jid, 'doc' => 'estimate']) ?>" target="_blank">Estimate</a><a href="<?= url('job_print', ['id' => $jid, 'doc' => 'workorder']) ?>" target="_blank">Work order (tech copy)</a><a href="<?= url('job_print', ['id' => $jid, 'doc' => 'invoice']) ?>" target="_blank">Invoice</a></div></details>
  </div>
</div>
<?php if ($job['approved_at'] && $job['approved_by'] && strtotime((string)$job['approved_at']) > time() - 86400 * 2): ?><div class="flash">Approved by <?= h($job['approved_by']) ?> on <?= h(date('M j, g:i a', strtotime((string)$job['approved_at']))) ?><?= $job['approve_note'] ? ': “' . h($job['approve_note']) . '”' : '' ?>.</div><?php endif; ?>
<?php if ($auth['over'] && !$est): ?><div class="flash flash-err">Current total <?= money($auth['current']) ?> is over the authorized <?= money($auth['authorized']) ?><?= $auth['over_limit'] ? ' by more than your limit' : '' ?>. Get and record additional authorization before doing the extra work.</div><?php endif; ?>

<div class="grid g3 ro">
  <div style="grid-column:span 2">
    <form method="post" id="jobform" class="card"><input type="hidden" name="action" value="job_save"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>">
      <div class="form-grid">
        <div><label>Customer</label><select name="customer_id"><option value="">—</option><?php foreach ($customers as $cu): ?><option value="<?= (int)$cu['id'] ?>" <?= (int)$job['customer_id'] === (int)$cu['id'] ? 'selected' : '' ?>><?= h($cu['name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Vehicle</label><select name="vehicle_id"><option value="">—</option><?php foreach ($allVeh as $av): ?><option value="<?= (int)$av['id'] ?>" <?= (int)$job['vehicle_id'] === (int)$av['id'] ? 'selected' : '' ?>><?= h(vehicle_name($av)) ?><?= $av['cname'] ? ' · ' . h($av['cname']) : ' · mine' ?><?= $av['plate'] ? ' · ' . h($av['plate']) : '' ?></option><?php endforeach; ?></select></div>
        <div class="full"><label>Title</label><input type="text" name="title" value="<?= h($job['title']) ?>"></div>
        <div><label>Odometer in</label><input type="number" name="odometer_in" value="<?= h((string)$job['odometer_in']) ?>"></div><div><label>Odometer out</label><input type="number" name="odometer_out" value="<?= h((string)$job['odometer_out']) ?>"></div>
        <div class="full"><label>Concern (customer's words)</label><textarea name="complaint" rows="2"><?= h($job['complaint']) ?></textarea></div>
      </div>
      <details class="ro-more" <?= ($job['cause'] || $job['correction'] || $job['notes_customer'] || $job['notes_internal'] || $job['promised']) ? 'open' : '' ?>><summary class="muted">Cause, correction, promised time and notes</summary>
      <div class="form-grid">
        <div class="full"><label>Cause (what you found)</label><textarea name="cause" rows="2"><?= h($job['cause']) ?></textarea></div>
        <div class="full"><label>Correction (what was done)</label><textarea name="correction" rows="2"><?= h($job['correction']) ?></textarea></div>
        <div><label>Promised</label><input type="text" name="promised" value="<?= h($job['promised']) ?>" placeholder="e.g. Fri 5 pm"></div><div></div>
        <div class="full"><label>Note to customer (prints on estimate/invoice)</label><textarea name="notes_customer" rows="2"><?= h($job['notes_customer']) ?></textarea></div>
        <div class="full"><label>Internal note (never shown)</label><textarea name="notes_internal" rows="2"><?= h($job['notes_internal']) ?></textarea></div>
      </div></details>
      <div class="row end" style="margin-top:10px"><button class="primary">Save</button></div>
    </form>

    <?php if (!$groups): ?><div class="card empty" style="margin-top:14px">No services yet. Add a canned job or a blank service below.</div><?php endif; ?>
    <?php foreach ($groups as $g => $gl): $gst = array_unique(array_column($gl, 'status')); $gt = job_totals($job + ['discount' => 0, 'supplies_pct' => 0, 'tax_parts' => 0, 'tax_labor' => 0], $gl, 'quoted'); ?>
    <section class="card svc <?= count($gst) === 1 ? 'svc-' . h($gst[0]) : '' ?>" id="g-<?= h(rawurlencode($g)) ?>">
      <header>
        <h3><?= h($g) ?></h3>
        <span class="st st-<?= count($gst) === 1 ? h($gst[0]) : 'mixed' ?>"><?= count($gst) === 1 ? h(['pending' => 'Awaiting approval', 'approved' => 'Approved', 'declined' => 'Declined'][$gst[0]] ?? $gst[0]) : 'Mixed' ?></span>
        <b class="svc-total"><?= money($gt['subtotal']) ?></b>
        <form method="post" class="row svc-actions"><input type="hidden" name="action" value="group_action"><?= $csrf ?><input type="hidden" name="job_id" value="<?= $jid ?>"><input type="hidden" name="grp" value="<?= h($g) ?>">
          <?php if (!in_array('approved', $gst, true) || count($gst) > 1): ?><button class="sm" name="do" value="approved">Approve</button><?php endif; ?>
          <?php if (!in_array('declined', $gst, true) || count($gst) > 1): ?><button class="sm ghost" name="do" value="declined">Decline</button><?php endif; ?>
          <details class="menu-pop"><summary class="btn sm ghost">⋯</summary><div>
            <button name="do" value="pending">Mark awaiting approval</button><button name="do" value="save_canned">Save as canned job</button>
            <button name="do" value="rename" data-prompt="New name for this service" data-prompt-field="to">Rename</button><button name="do" value="delete" data-confirm="Remove this service and its lines?">Remove service</button></div></details>
          <input type="hidden" name="to" value=""></form>
      </header>
      <div class="lines">
        <?php foreach ($gl as $l): $amt = round($l['qty'] * $l['price'], 2); ?>
        <details class="line line-<?= h($l['kind']) ?> ls-<?= h($l['status']) ?>" id="l<?= (int)$l['id'] ?>">
          <summary><span class="lk"><?= $ico[$l['kind']] ?? '' ?></span><span class="ld"><?= h($l['description']) ?><?php if ($l['part_no'] || $l['brand']): ?><small><?= h(trim($l['brand'] . ' ' . $l['part_no'])) ?><?= $l['supplier'] ? ' · ' . h($l['supplier']) : '' ?></small><?php endif; ?>
              <?php if ($l['kind'] === 'part' && $l['pstatus'] !== 'quoted'): ?><small class="pst"><?= h(PART_STATUS[$l['pstatus']] ?? '') ?></small><?php endif; ?><?php if ($l['kind'] === 'part' && (float)$l['price'] <= 0): ?><small class="warn-t">price needed</small><?php endif; ?></span>
            <span class="lq"><?= $l['kind'] === 'note' ? '' : rtrim(rtrim(number_format((float)$l['qty'], 2), '0'), '.') . ($l['kind'] === 'labor' ? ' h' : ' ×') ?></span>
            <span class="lp"><?= $l['kind'] === 'note' ? '' : money((float)$l['price']) ?></span><span class="lt"><?= $l['kind'] === 'note' ? '' : money($amt) ?></span></summary>
          <form method="post" class="line-edit"><input type="hidden" name="action" value="line_save"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <div class="form-grid">
              <div class="full"><label>Description</label><input type="text" name="description" value="<?= h($l['description']) ?>"></div>
              <?php if (in_array($l['kind'], ['part', 'core', 'sublet'], true)): ?><div><label>Part number</label><input type="text" name="part_no" value="<?= h($l['part_no']) ?>"></div><div><label>Brand</label><input type="text" name="brand" value="<?= h($l['brand']) ?>"></div><div><label>Supplier / vendor</label><input type="text" name="supplier" value="<?= h($l['supplier']) ?>"></div>
                <div><label>Status</label><select name="pstatus"><?php foreach (PART_STATUS as $k => $lb): ?><option value="<?= $k ?>" <?= $l['pstatus'] === $k ? 'selected' : '' ?>><?= h($lb) ?></option><?php endforeach; ?></select></div><?php else: ?><input type="hidden" name="part_no" value=""><input type="hidden" name="brand" value=""><input type="hidden" name="supplier" value=""><?php endif; ?>
              <div><label><?= $l['kind'] === 'labor' ? 'Hours' : 'Quantity' ?></label><input type="number" step="0.01" name="qty" value="<?= h((string)(float)$l['qty']) ?>"></div>
              <?php if ($l['kind'] !== 'labor'): ?><div><label>Your cost (each)</label><input type="number" step="0.01" name="cost" value="<?= h((string)(float)$l['cost']) ?>"></div><?php else: ?><input type="hidden" name="cost" value="0"><?php endif; ?>
              <div><label><?= $l['kind'] === 'labor' ? 'Rate per hour' : 'Price (each)' ?></label><input type="number" step="0.01" name="price" value="<?= h((string)(float)$l['price']) ?>"></div>
              <div><label class="inline" style="margin-top:26px"><input type="checkbox" name="taxable" value="1" <?= $l['taxable'] ? 'checked' : '' ?>> Taxable</label></div>
            </div>
            <div class="row" style="margin-top:8px;gap:8px"><button class="primary sm">Save line</button><?php if ($l['kind'] === 'part'): ?><button class="sm" name="reprice" value="1" title="Price = cost marked up by your matrix">Price from cost (matrix)</button><button type="button" class="sm" data-parts-open data-q="<?= h($l['description']) ?>" data-grp="<?= h($g) ?>">Find part</button><?php endif; ?></div>
          </form>
          <form method="post" class="line-del"><input type="hidden" name="action" value="line_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="sm ghost danger">Remove line</button></form>
        </details>
        <?php endforeach; ?>
      </div>
      <details class="add-line"><summary>＋ Add labor, part or fee</summary>
        <form method="post" class="form-grid"><input type="hidden" name="action" value="line_add"><?= $csrf ?><input type="hidden" name="job_id" value="<?= $jid ?>"><input type="hidden" name="grp" value="<?= h($g) ?>">
          <div><label>Type</label><select name="kind" data-kind-pick><?php foreach (LINE_KINDS as $k => $lb): ?><option value="<?= $k ?>"><?= h($lb) ?></option><?php endforeach; ?></select></div>
          <div><label>Quantity / hours</label><input type="number" step="0.01" name="qty" value="1"></div>
          <div class="full"><label>Description</label><input type="text" name="description" required></div>
          <div><label>Part number</label><input type="text" name="part_no"></div><div><label>Brand / supplier</label><input type="text" name="brand"></div>
          <div><label>Your cost (each)</label><input type="number" step="0.01" name="cost"></div><div><label>Price (blank = <span data-auto-lbl>matrix</span>)</label><input type="number" step="0.01" name="price"></div>
          <div class="full row" style="gap:8px"><button class="primary sm">Add</button><button type="button" class="sm" data-parts-open data-q="" data-grp="<?= h($g) ?>">Find part…</button></div>
        </form></details>
    </section>
    <?php endforeach; ?>

    <div class="card add-svc" id="add">
      <h2>Add a service</h2>
      <form method="post" class="row" style="flex-wrap:wrap;gap:8px"><input type="hidden" name="action" value="canned_add"><?= $csrf ?><input type="hidden" name="job_id" value="<?= $jid ?>">
        <select name="canned_id" required style="flex:1;min-width:220px"><option value="">Canned job (typical labor time + parts list)…</option><?php foreach ($cats as $cat => $items): ?><optgroup label="<?= h($cat) ?>"><?php foreach ($items as $cj): $hrs = array_sum(array_map(fn($x) => $x[0] === 'labor' ? (float)$x[2] : 0, json_decode((string)$cj['lines'], true) ?: [])); ?><option value="<?= (int)$cj['id'] ?>"><?= h($cj['name']) ?><?= $hrs ? ' · ' . $hrs . ' h' : '' ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
        <button class="primary">Add</button></form>
      <form method="post" class="row" style="margin-top:10px;gap:8px;flex-wrap:nowrap"><input type="hidden" name="action" value="line_add"><?= $csrf ?><input type="hidden" name="job_id" value="<?= $jid ?>"><input type="hidden" name="kind" value="labor"><input type="hidden" name="qty" value="1">
        <input type="text" name="grp" placeholder="Or a blank service, e.g. Replace front control arm" required data-copy-to="description"><input type="hidden" name="description"><button>Add blank</button></form>
      <p class="muted" style="font-size:12.5px;margin-top:8px">Canned labor hours are typical book times for common vehicles; check your labor guide (ALLDATA, ProDemand, MOTOR) for this vehicle. Manage canned jobs under <a href="<?= url('shop') ?>#canned">Shop settings</a>.</p>
    </div>

    <div class="card" id="inspect">
      <h2>Vehicle inspection</h2>
      <?php if (!$insp): ?>
        <p class="muted">A multi-point inspection: rate each item green / yellow / red. Findings can go straight onto the estimate, and the customer sees them on their link.</p>
        <form method="post"><input type="hidden" name="action" value="inspect_start"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>"><button class="primary sm">Start inspection</button></form>
      <?php else: $sec = null; $cnt = array_count_values(array_column($insp, 'rating')); ?>
        <p class="muted"><?= (int)($cnt['good'] ?? 0) ?> OK · <span style="color:#fcd34d"><?= (int)($cnt['soon'] ?? 0) ?> soon</span> · <span style="color:#fca5a5"><?= (int)($cnt['now'] ?? 0) ?> now</span> · <?= (int)($cnt['na'] ?? 0) ?> not checked</p>
        <form method="post"><input type="hidden" name="action" value="inspect_save"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>">
          <?php foreach ($insp as $it): if ($it['section'] !== $sec): $sec = $it['section']; ?><h3 style="margin-top:12px"><?= h($sec) ?></h3><?php endif; ?>
            <div class="insp-row"><span><?= h($it['item']) ?></span><span class="rate"><?php foreach (['good' => '●', 'soon' => '●', 'now' => '●', 'na' => '–'] as $k => $sym): ?><label class="r-<?= $k ?>" title="<?= h(INSPECT_RATINGS[$k]) ?>"><input type="radio" name="r[<?= (int)$it['id'] ?>]" value="<?= $k ?>" <?= $it['rating'] === $k ? 'checked' : '' ?>><i><?= $sym ?></i></label><?php endforeach; ?></span>
              <input type="text" name="n[<?= (int)$it['id'] ?>]" value="<?= h($it['note']) ?>" placeholder="note / measurement"></div>
          <?php endforeach; ?>
          <div class="row" style="margin-top:12px;gap:8px"><button class="primary sm">Save inspection</button><button class="sm" name="to_estimate" value="1">Save and add yellow/red items to the estimate</button></div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="aside">
    <div class="card totals">
      <h2><?= $est ? 'Estimate total' : (in_array($job['stage'], ['invoiced', 'paid'], true) ? 'Invoice total' : 'Approved total') ?></h2>
      <table>
        <tr><td>Labor<?= $T['hours'] ? ' (' . rtrim(rtrim(number_format($T['hours'], 2), '0'), '.') . ' h)' : '' ?></td><td><?= money($T['labor']) ?></td></tr>
        <tr><td>Parts</td><td><?= money($T['parts']) ?></td></tr>
        <?php if ($T['core']): ?><tr><td>Core charges</td><td><?= money($T['core']) ?></td></tr><?php endif; ?>
        <?php if ($T['sublet']): ?><tr><td>Sublet</td><td><?= money($T['sublet']) ?></td></tr><?php endif; ?>
        <?php if ($T['fees']): ?><tr><td>Fees</td><td><?= money($T['fees']) ?></td></tr><?php endif; ?>
        <?php if ($T['supplies']): ?><tr><td>Shop supplies</td><td><?= money($T['supplies']) ?></td></tr><?php endif; ?>
        <?php if ($T['discount']): ?><tr><td>Discount</td><td>−<?= money($T['discount']) ?></td></tr><?php endif; ?>
        <tr><td>Tax</td><td><?= money($T['tax']) ?></td></tr>
        <tr class="tot"><td>Total</td><td><?= money($T['total']) ?></td></tr>
        <?php if ($est && $ta['total'] > 0 && abs($ta['total'] - $tq['total']) > 0.004): ?><tr><td class="muted">Approved so far</td><td class="muted"><?= money($ta['total']) ?></td></tr><?php endif; ?>
        <?php if ($job['paid'] > 0 || in_array($job['stage'], ['invoiced', 'paid'], true)): ?><tr><td>Paid</td><td><?= money((float)$job['paid']) ?></td></tr><tr class="tot"><td>Balance</td><td><?= money($balance) ?></td></tr><?php endif; ?>
      </table>
      <div class="profit"><span>Cost <?= money($T['cost']) ?></span><span>Gross profit <?= money($T['profit']) ?> (<?= $T['margin'] ?>%)</span><?php if ($T['hours']): ?><span>Effective labor rate <?= money($T['elr']) ?>/h</span><?php endif; ?></div>
      <details style="margin-top:10px"><summary class="muted">Rates, tax, discount</summary>
        <div class="form-grid">
          <div><label>Labor rate /h</label><input form="jobform" type="number" step="0.01" name="labor_rate" value="<?= h((string)(float)$job['labor_rate']) ?>"></div>
          <div><label>Discount</label><input form="jobform" type="number" step="0.01" name="discount" value="<?= h((string)(float)$job['discount']) ?>"></div>
          <div><label>Parts tax %</label><input form="jobform" type="number" step="0.001" name="tax_parts" value="<?= h((string)(float)$job['tax_parts']) ?>"></div>
          <div><label>Labor tax %</label><input form="jobform" type="number" step="0.001" name="tax_labor" value="<?= h((string)(float)$job['tax_labor']) ?>"></div>
          <div><label>Shop supplies % of labor</label><input form="jobform" type="number" step="0.01" name="supplies_pct" value="<?= h((string)(float)$job['supplies_pct']) ?>"></div>
          <div><label>Supplies cap</label><input form="jobform" type="number" step="0.01" name="supplies_cap" value="<?= h((string)(float)$job['supplies_cap']) ?>"></div>
        </div>
        <label class="inline"><input form="jobform" type="checkbox" name="rate_to_lines" value="1"> Apply the labor rate to every labor line</label>
        <button form="jobform" class="sm" style="margin-top:8px">Save rates</button><p class="muted" style="font-size:12px">Tax exempt (fleet/resale)? Set both tax rates to 0.</p>
      </details>
    </div>

    <div class="card" id="send" style="margin-top:14px">
      <h2>Customer approval</h2>
      <?php if ($link): ?>
        <div class="share-link"><input type="text" readonly value="<?= h($link) ?>" data-select-all><button type="button" class="sm primary" data-copy="<?= h($link) ?>">Copy</button></div>
        <?php $msg = 'Hi' . (!empty($C['name']) ? ' ' . strtok((string)$C['name'], ' ') : '') . ', here is your ' . ($est ? 'estimate' : 'repair order') . ' for the ' . vehicle_name($V) . ' from ' . ($S['name'] ?: APP_NAME) . ': ' . $link; ?>
        <div class="row" style="gap:6px;margin-top:8px;flex-wrap:wrap">
          <?php if (!empty($C['phone'])): ?><a class="btn sm" href="sms:<?= h(preg_replace('/[^\d+]/', '', (string)$C['phone'])) ?>?&amp;body=<?= h(rawurlencode($msg)) ?>">Text</a><?php endif; ?>
          <a class="btn sm" href="mailto:<?= h((string)($C['email'] ?? '')) ?>?subject=<?= h(rawurlencode(($est ? 'Estimate ' : 'Repair order ') . $job['est_no'])) ?>&amp;body=<?= h(rawurlencode($msg)) ?>">Email</a>
          <button type="button" class="btn sm" data-share-url="<?= h($link) ?>" data-share-title="<?= h($job['est_no']) ?>" hidden>Share…</button>
          <a class="btn sm ghost" href="<?= h($link) ?>" target="_blank" rel="noopener">Preview</a></div>
        <p class="muted" style="font-size:12.5px">They can approve or decline each service and sign with their name. Sent from your own phone or email.</p>
      <?php else: ?>
        <form method="post"><input type="hidden" name="action" value="job_link"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>"><button class="primary sm">Make customer link</button></form>
      <?php endif; ?>
      <h3 style="margin-top:14px">Authorizations</h3>
      <?php if (!$auths): ?><p class="muted" style="font-size:13px">None recorded yet.</p><?php endif; ?>
      <ul class="list"><?php foreach ($auths as $a): ?><li><div class="grow"><span class="t"><?= money((float)$a['amount']) ?> · <?= h(AUTH_METHODS[$a['method']] ?? $a['method']) ?></span><small><?= h($a['by_name'] ?: '—') ?> · <?= h(date('M j, Y g:i a', strtotime((string)$a['at']))) ?><?= $a['note'] ? ' · ' . h($a['note']) : '' ?></small></div></li><?php endforeach; ?></ul>
      <details><summary class="muted">Record an authorization (phone, in person…)</summary>
        <form method="post"><input type="hidden" name="action" value="job_auth"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>">
          <label>How</label><select name="method"><?php foreach (AUTH_METHODS as $k => $lb): if ($k === 'link') continue; ?><option value="<?= $k ?>"><?= h($lb) ?></option><?php endforeach; ?></select>
          <label>Authorized by</label><input type="text" name="by_name" value="<?= h((string)($C['name'] ?? '')) ?>" required>
          <label>Amount authorized</label><input type="number" step="0.01" name="amount" value="<?= h((string)$tq['total']) ?>">
          <label>Note (e.g. phone number called, time)</label><input type="text" name="note">
          <label class="inline"><input type="checkbox" name="approve_pending" value="1" checked> Approve all services awaiting approval</label>
          <button class="primary sm" style="margin-top:8px">Record</button></form></details>
    </div>

    <div class="card" id="pay" style="margin-top:14px">
      <h2>Payments</h2>
      <?php if (!$pays): ?><p class="muted" style="font-size:13px">No payments yet<?= in_array($job['stage'], ['estimate', 'approved'], true) ? ' (deposits can be recorded here)' : '' ?>.</p><?php endif; ?>
      <ul class="list"><?php foreach ($pays as $p): ?><li><div class="grow"><span class="t"><?= money((float)$p['amount']) ?> · <?= h(PAY_METHODS[$p['method']] ?? $p['method']) ?></span><small><?= fmt_date($p['paid_on']) ?><?= $p['note'] ? ' · ' . h($p['note']) : '' ?></small></div>
        <form method="post"><input type="hidden" name="action" value="payment_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="sm ghost dim" data-confirm="Remove this payment?">×</button></form></li><?php endforeach; ?></ul>
      <form method="post" class="form-grid"><input type="hidden" name="action" value="payment_add"><?= $csrf ?><input type="hidden" name="job_id" value="<?= $jid ?>">
        <div><label>Amount</label><input type="number" step="0.01" name="amount" value="<?= $balance > 0 && in_array($job['stage'], ['invoiced', 'done'], true) ? h((string)$balance) : '' ?>" required></div>
        <div><label>Method</label><select name="method"><?php foreach (PAY_METHODS as $k => $lb): ?><option value="<?= $k ?>"><?= h($lb) ?></option><?php endforeach; ?></select></div>
        <div><label>Date</label><input type="date" name="paid_on" value="<?= today() ?>"></div><div><label>Reference</label><input type="text" name="note" placeholder="last 4, check #"></div>
        <div class="full"><button class="sm primary">Record payment</button></div></form>
      <p class="muted" style="font-size:12px">Records payments taken on your own terminal or in cash; the app does not process cards.</p>
    </div>

    <div class="card" style="margin-top:14px">
      <div class="row" style="gap:6px;flex-wrap:wrap">
        <?php if ($job['vehicle_id']): ?><a class="btn sm" href="<?= url('diagnose', ['vehicle' => $job['vehicle_id']]) ?>">Diagnose this vehicle</a><?php endif; ?>
        <?php if ($job['diag_report_id']): ?><a class="btn sm" href="<?= url('diagnose', ['r' => $job['diag_report_id']]) ?>">Diagnosis #<?= (int)$job['diag_report_id'] ?></a><?php endif; ?>
        <form method="post"><input type="hidden" name="action" value="job_copy"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>"><button class="sm">Copy as new estimate</button></form>
        <?php if (!in_array($job['stage'], ['void', 'paid'], true)): ?><form method="post"><input type="hidden" name="action" value="job_stage"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>"><input type="hidden" name="to" value="void"><button class="sm ghost" data-confirm="Void this repair order? It stays on record as void.">Void</button></form><?php endif; ?>
        <form method="post"><input type="hidden" name="action" value="job_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= $jid ?>"><button class="sm ghost danger" data-confirm="Delete this repair order completely, with its lines and payments? Voiding keeps a record instead.">Delete</button></form>
      </div>
    </div>
  </div>
</div>

<dialog class="sheet parts-dlg" id="parts-dlg" data-vehicle="<?= h(vehicle_name($V)) ?>">
  <div class="sheet-grip"></div>
  <h3>Find a part<?= $V ? ' for ' . h(vehicle_name($V)) : '' ?></h3>
  <form class="row" data-parts-form style="flex-wrap:nowrap"><input type="search" data-parts-q placeholder="e.g. front brake pads, thermostat, 06E145821"><button class="sm">Search</button></form>
  <div data-parts-catalog><p class="muted" style="font-size:13px">Your catalog matches show here.</p></div>
  <div data-parts-links></div>
  <form method="post" class="form-grid parts-manual"><input type="hidden" name="action" value="line_add"><?= $csrf ?><input type="hidden" name="job_id" value="<?= $jid ?>"><input type="hidden" name="grp" data-parts-grp><input type="hidden" name="kind" value="part"><input type="hidden" name="catalog_id" data-parts-cat>
    <div class="full"><label>Found it? Add it here</label><input type="text" name="description" data-parts-desc placeholder="Description" required></div>
    <div><label>Part number</label><input type="text" name="part_no"></div><div><label>Brand</label><input type="text" name="brand"></div>
    <div><label>Supplier</label><input type="text" name="supplier" placeholder="RockAuto, AutoZone…"></div><div><label>Qty</label><input type="number" step="0.01" name="qty" value="1"></div>
    <div><label>Your cost</label><input type="number" step="0.01" name="cost"></div><div><label>Price (blank = matrix)</label><input type="number" step="0.01" name="price"></div>
    <div class="full row" style="gap:8px"><button class="primary sm">Add to estimate</button><button type="button" class="sm ghost" data-sheet-close>Close</button></div>
  </form>
  <script type="application/json" id="parts-links-json"><?= json_encode(parts_links('__Q__', $V), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</dialog>
