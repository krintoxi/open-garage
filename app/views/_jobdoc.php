<?php
// One printable repair document. Expects $job, $lines, $doc ('estimate' | 'workorder' | 'invoice'), $S (shop profile),
// and optionally $interactive (customer approval checkboxes, inside a form) and $insp (inspection rows).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$V = job_vehicle($job); $C = job_customer($job);
$wo = $doc === 'workorder'; $inv = $doc === 'invoice';
$scope = $inv || $wo ? 'approved' : 'quoted';
$T = job_totals($job, $lines, $scope);
$groups = []; foreach ($lines as $l) { if ($scope === 'approved' && $l['status'] !== 'approved') continue; $groups[$l['grp'] ?: 'General'][] = $l; }
$declined = $inv ? array_unique(array_column(array_filter($lines, fn($l) => $l['status'] === 'declined'), 'grp')) : [];
$m = fn($v) => money((float)$v, $S);
$docTitle = ['estimate' => 'Estimate', 'workorder' => 'Work order', 'invoice' => 'Invoice'][$doc];
$num = $inv ? ($job['inv_no'] ?: $job['est_no']) : $job['est_no'];
$pays = $inv ? rows('SELECT * FROM job_payments WHERE job_id=? ORDER BY paid_on', [$job['id']]) : [];
$auths = rows('SELECT * FROM job_auth WHERE job_id=? ORDER BY id', [$job['id']]);
[, $RF] = shop_rules($S); $showCond = $RF['cond'] || !empty($S['show_cond']);
?>
<div class="doc">
  <header class="doc-head">
    <div><b class="shop-name"><?= h($S['name'] ?: APP_NAME) ?></b><div class="mut"><?= h($S['address']) ?><?= $S['phone'] ? '<br>' . h($S['phone']) : '' ?><?= $S['email'] ? ' · ' . h($S['email']) : '' ?><?= $S['license'] ? '<br>' . h(shop_reg_label($S)) . ' ' . h($S['license']) . (!empty($S['epa_id']) ? ' · EPA ID ' . h($S['epa_id']) : '') : '' ?></div></div>
    <div class="doc-id"><b><?= h($docTitle) ?></b><span><?= h($num) ?></span><small><?= fmt_date($inv && $job['invoiced_at'] ? $job['invoiced_at'] : $job['created_at']) ?></small></div>
  </header>
  <div class="doc-parties">
    <div><small>Customer</small><b><?= h($C['name'] ?? '—') ?></b><?= !empty($C['phone']) ? '<br>' . h($C['phone']) : '' ?><?= !empty($C['email']) && !$wo ? '<br>' . h($C['email']) : '' ?></div>
    <div><small>Vehicle</small><b><?= h($V ? vehicle_name($V) : '—') ?></b><?= !empty($V['engine']) ? '<br>' . h($V['engine']) : '' ?><?= !empty($V['vin']) ? '<br>VIN ' . h($V['vin']) : '' ?><?= !empty($V['plate']) ? '<br>Plate ' . h($V['plate']) : '' ?>
      <br>Odometer in <?= $job['odometer_in'] ? number_format((int)$job['odometer_in']) : '—' ?><?= $inv && $job['odometer_out'] ? ' · out ' . number_format((int)$job['odometer_out']) : '' ?></div>
  </div>
  <?php if ($job['complaint'] || $job['cause'] || $job['correction']): ?><div class="doc-3c">
    <?php if ($job['complaint']): ?><p><b>Concern:</b> <?= nl2br(h($job['complaint'])) ?></p><?php endif; ?>
    <?php if ($job['cause']): ?><p><b>Cause:</b> <?= nl2br(h($job['cause'])) ?></p><?php endif; ?>
    <?php if ($job['correction'] && $doc !== 'estimate'): ?><p><b>Correction:</b> <?= nl2br(h($job['correction'])) ?></p><?php endif; ?>
  </div><?php endif; ?>

  <?php foreach ($groups as $g => $gl): $gt = job_totals(['discount' => 0, 'supplies_pct' => 0, 'tax_parts' => 0, 'tax_labor' => 0, 'user_id' => $job['user_id']] + $job, $gl, 'quoted');
    $st = array_unique(array_column($gl, 'status')); $isDecl = $st === ['declined']; ?>
  <section class="doc-svc <?= $isDecl ? 'is-declined' : '' ?>">
    <div class="svc-h">
      <?php if (!empty($interactive)): ?><label class="approve"><input type="checkbox" name="g[]" value="<?= h($g) ?>" <?= $isDecl ? '' : 'checked' ?> <?= in_array('approved', $st, true) && count($st) === 1 ? 'checked disabled' : '' ?>><span><?= in_array('approved', $st, true) && count($st) === 1 ? 'Approved' : 'Approve' ?></span></label><?php endif; ?>
      <b><?= h($g) ?></b><?= $isDecl ? ' <small>(declined)</small>' : '' ?><?php if (!$wo): ?><span class="svc-amt"><?= $m($gt['subtotal']) ?></span><?php endif; ?><?php if (!empty($interactive) && !$isDecl && ($fr = fair_range($g, $V)) && fair_verdict((float)$gt['subtotal'], $fr)[0] === 'fair'): ?><small class="fair-ok">✓ within the usual price range for this job</small><?php endif; ?></div>
    <table>
      <?php foreach ($gl as $l): if ($l['kind'] === 'labor' && !$S['show_rates'] && !$wo) continue; ?>
        <tr class="k-<?= h($l['kind']) ?>"><td><?= $wo ? '☐ ' : '' ?><?= h($l['description']) ?><?php if ($l['kind'] === 'part' && ($showCond || (($l['cond'] ?? 'new') !== 'new'))): ?> <span class="cond">(<?= h(PART_COND[$l['cond'] ?: 'new'] ?? 'New') ?>)</span><?php endif; ?><?php if (($S['show_part_numbers'] || $wo) && ($l['part_no'] || $l['brand'])): ?> <span class="mut"><?= h(trim($l['brand'] . ' ' . $l['part_no'])) ?></span><?php endif; ?><?= $wo && $l['kind'] === 'part' ? ' <span class="mut">[' . h(PART_STATUS[$l['pstatus']] ?? '') . ']</span>' : '' ?></td>
          <td class="n"><?= $l['kind'] === 'note' ? '' : rtrim(rtrim(number_format((float)$l['qty'], 2), '0'), '.') . ($l['kind'] === 'labor' ? ' h' : '') ?></td>
          <?php if (!$wo): ?><td class="n"><?= $l['kind'] === 'note' ? '' : $m($l['price']) ?></td><td class="n"><?= $l['kind'] === 'note' ? '' : $m($l['qty'] * $l['price']) ?></td><?php endif; ?></tr>
      <?php endforeach; ?>
      <?php if (!$S['show_rates'] && !$wo && $gt['labor'] > 0): ?><tr class="k-labor"><td>Labor</td><td></td><td></td><td class="n"><?= $m($gt['labor']) ?></td></tr><?php endif; ?>
    </table>
  </section>
  <?php endforeach; ?>
  <?php if (!$groups): ?><p class="mut">No approved work yet.</p><?php endif; ?>

  <?php if (!$wo): ?>
  <table class="doc-tot">
    <tr><td>Labor</td><td><?= $m($T['labor']) ?></td></tr><tr><td>Parts</td><td><?= $m($T['parts']) ?></td></tr>
    <?php if ($T['core']): ?><tr><td>Core charges (refunded when the old part is returned)</td><td><?= $m($T['core']) ?></td></tr><?php endif; ?>
    <?php if ($T['sublet']): ?><tr><td>Sublet</td><td><?= $m($T['sublet']) ?></td></tr><?php endif; ?>
    <?php if ($T['fees']): ?><tr><td>Fees<?= !empty($S['epa_id']) ? ' (disposal fees: EPA ID ' . h($S['epa_id']) . ')' : '' ?></td><td><?= $m($T['fees']) ?></td></tr><?php endif; ?>
    <?php if ($T['supplies']): ?><tr><td>Shop supplies</td><td><?= $m($T['supplies']) ?></td></tr><?php endif; ?>
    <?php if ($T['discount']): ?><tr><td>Discount</td><td>−<?= $m($T['discount']) ?></td></tr><?php endif; ?>
    <tr><td>Tax</td><td><?= $m($T['tax']) ?></td></tr>
    <tr class="grand"><td><?= $inv ? 'Total' : 'Estimated total' ?></td><td><?= $m($T['total']) ?></td></tr>
    <?php foreach ($pays as $p): ?><tr><td>Paid <?= fmt_date($p['paid_on']) ?> · <?= h(PAY_METHODS[$p['method']] ?? $p['method']) ?></td><td>−<?= $m($p['amount']) ?></td></tr><?php endforeach; ?>
    <?php if ($inv): ?><tr class="grand"><td>Balance due</td><td><?= $m(max(0, $T['total'] - array_sum(array_column($pays, 'amount')))) ?></td></tr><?php endif; ?>
  </table>
  <?php endif; ?>

  <?php if (!empty($insp) && !$wo): $finds = array_filter($insp, fn($x) => in_array($x['rating'], ['now', 'soon'], true)); if ($finds): ?>
  <section class="doc-insp"><b>Inspection findings</b>
    <ul><?php foreach ($finds as $f): ?><li class="r-<?= h($f['rating']) ?>"><span><?= $f['rating'] === 'now' ? 'Needs attention now' : 'Recommended soon' ?>:</span> <?= h($f['item']) ?><?= $f['note'] ? ' — ' . h($f['note']) : '' ?></li><?php endforeach; ?></ul>
    <small class="mut"><?= count(array_filter($insp, fn($x) => $x['rating'] === 'good')) ?> items checked OK.</small></section>
  <?php endif; endif; ?>
  <?php if (!$wo || $RF['cert']): $techIds = array_unique(array_filter(array_column($lines, 'tech_id'))); if ($RF['cert'] && $techIds && $doc !== 'estimate'): ?><p class="mut"><b>Work done by:</b> <?= h(implode('; ', array_map(fn($t) => $t['name'] . ($t['cert_no'] ? ', cert. ' . $t['cert_no'] : ''), rows('SELECT name, cert_no FROM techs WHERE id IN (' . implode(',', array_map('intval', $techIds)) . ')')))) ?></p><?php endif; endif; ?>
  <?php if ($RF['parts_back'] && $doc !== 'estimate'): ?><p class="mut"><b>Replaced parts:</b> <?= h(['yes' => 'returned to the customer, as requested (except parts returned for core credit or warranty).', 'no' => 'offered to the customer, who did not want them.'][$job['parts_return'] ?? ''] ?? 'available to the customer on request, except parts returned for core credit or warranty.') ?></p><?php endif; ?>
  <?php if ($RF['fl150'] && $doc === 'estimate' && !$interactive): ?><section class="fl-choice"><b>PLEASE READ CAREFULLY, CHECK ONE OF THE STATEMENTS BELOW, AND SIGN:</b>
    <p>I UNDERSTAND THAT, UNDER STATE LAW, I AM ENTITLED TO A WRITTEN ESTIMATE IF MY FINAL BILL WILL EXCEED $150.</p>
    <p>☐ I REQUEST A WRITTEN ESTIMATE.</p><p>☐ I DO NOT REQUEST A WRITTEN ESTIMATE AS LONG AS THE REPAIR COSTS DO NOT EXCEED $______. THE SHOP MAY NOT EXCEED THIS AMOUNT WITHOUT MY WRITTEN OR ORAL CONSENT.</p>
    <p>☐ I DO NOT REQUEST A WRITTEN ESTIMATE.</p><p>SIGNED ____________________________ DATE ____________</p></section><?php endif; ?>
  <?php $sub = array_filter($lines, fn($l) => $l['kind'] === 'sublet' && $l['status'] !== 'declined'); if ($sub && $doc !== 'workorder'): ?><p class="mut"><b>Work done by another shop (sublet):</b> <?= h(implode('; ', array_map(fn($l) => $l['description'] . ($l['supplier'] ? ' (' . $l['supplier'] . ')' : ''), $sub))) ?>.<?= $doc === 'estimate' ? ' Approving this estimate includes your consent to this.' : '' ?></p><?php endif; ?>
  <?php if ($inv && !empty($S['mm_line'])): ?><p class="mut">Having this work done at an independent shop, with parts of equal quality, does not void your vehicle's factory warranty (Magnuson-Moss Warranty Act).</p><?php endif; ?>
  <?php if ($declined): ?><p class="mut"><b>Recommended but declined:</b> <?= h(implode(', ', $declined)) ?>.</p><?php endif; ?>
  <?php if ($job['notes_customer'] && !$wo): ?><p><?= nl2br(h($job['notes_customer'])) ?></p><?php endif; ?>
  <?php if ($wo && $job['notes_internal']): ?><p><b>Shop note:</b> <?= nl2br(h($job['notes_internal'])) ?></p><?php endif; ?>
  <?php if ($wo): ?><div class="tech-box"><b>Technician notes / measurements</b><div></div><div></div><div></div></div><?php endif; ?>
  <?php if ($auths): ?><div class="mut auth-rec"><b>Customer approval<?= count($auths) > 1 ? 's' : '' ?>:</b><?php foreach ($auths as $a): ?><div><?= h(auth_line($a, $S)) ?></div><?php endforeach; ?><?php if ($inv && abs((float)$T['total'] - (float)$auths[count($auths) - 1]['amount']) > 0.005): ?><div>Final total <?= h($m($T['total'])) ?> vs last approved <?= h($m((float)$auths[count($auths) - 1]['amount'])) ?>.</div><?php endif; ?></div><?php endif; ?>
  <footer class="doc-foot"><?= $inv ? nl2br(h($S['inv_terms'])) . ($S['warranty'] ? '<br><b>Warranty:</b> ' . h($S['warranty']) : '') : ($wo ? '' : nl2br(h($S['est_terms']))) ?></footer>
</div>
