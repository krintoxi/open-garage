<?php
// Known problems for this year/make/model from NHTSA: warranty extensions, service bulletins, owner complaints and
// defect investigations. Drivers see their own vehicle; a shop can open it for a customer's vehicle (?vehicle=<id>).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$vehId = (int)($_GET['vehicle'] ?? 0);
$V = $vehId && owns_vehicle($vehId) ? row('SELECT * FROM vehicle WHERE id=?', [$vehId]) : $veh;
if (!$V) { echo '<div class="empty">Add a vehicle first.</div>'; return; }
$name = vehicle_name($V); $title = 'Bulletins · ' . $name;
$have = nhtsa_db() !== null;
$tsb = $have ? bulletins_for($V) : [];
$inv = $have ? investigations_for($V) : [];
$cmpl = complaints_for($V);
$warranty = array_values(array_filter($tsb, fn($x) => stripos((string)$x['type'], 'warranty') !== false));
$service = array_values(array_filter($tsb, fn($x) => stripos((string)$x['type'], 'warranty') === false));
$byComp = []; foreach ($service as $b) $byComp[nhtsa_comp((string)($b['comps'] ?: 'Other'))][] = $b;
uksort($byComp, fn($a, $b) => count($byComp[$b]) <=> count($byComp[$a]));
$cByComp = []; foreach ((array)$cmpl as $c) foreach (array_unique(array_map('nhtsa_comp', explode(',', $c['comps'] ?: 'Other'))) as $k) $cByComp[$k][] = $c;
uksort($cByComp, fn($a, $b) => count($cByComp[$b]) <=> count($cByComp[$a]));
$maxC = $cByComp ? max(array_map('count', $cByComp)) : 1;
$fmtD = fn(?string $d) => $d && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m) ? date('M j, Y', strtotime("$m[1]-$m[2]-$m[3]")) : ($d ? fmt_date($d) : '');
$serious = $cmpl ? count(array_filter($cmpl, fn($c) => $c['crash'] || $c['fire'] || $c['injuries'] > 0)) : 0;
?>
<div class="head"><div><h1>Bulletins &amp; complaints</h1><p><?= h($name) ?>: what the manufacturer told dealers, what other owners reported to NHTSA, and any government investigations. Free public data from NHTSA.</p></div>
  <?php if ($vehId): ?><a class="btn" href="javascript:history.back()">← Back</a><?php endif; ?></div>

<div class="kb-sum">
  <div class="card"><b><?= count($warranty) ?></b><small>warranty extension<?= count($warranty) === 1 ? '' : 's' ?></small></div>
  <div class="card"><b><?= count($service) ?></b><small>service bulletin<?= count($service) === 1 ? '' : 's' ?></small></div>
  <div class="card"><b><?= $cmpl === null ? '—' : count($cmpl) ?></b><small>owner complaints<?= $serious ? ' · ' . $serious . ' with a crash, fire or injury' : '' ?></small></div>
  <div class="card"><b><?= count($inv) ?></b><small>investigation<?= count($inv) === 1 ? '' : 's' ?></small></div>
</div>
<?php if (!$have): ?><div class="callout">Service bulletins are not loaded on this server yet. Owner complaints below come straight from NHTSA.</div><?php endif; ?>

<?php if ($warranty): ?>
<section class="card kb-warranty"><h2>🎁 Warranty extensions</h2>
  <p class="muted" style="margin-top:-6px">The manufacturer extended the warranty on these parts, often past the normal warranty. If yours has the problem, a dealer may fix it free. Ask with the number shown.</p>
  <?php foreach ($warranty as $b): ?><div class="kb-item"><b><?= h(trim(preg_replace('/^(EW|WE|LW)\s*-\s*/i', '', (string)$b['doc'])) ?: 'Warranty extension') ?></b><small><?= h($fmtD($b['date'])) ?><?= $b['comps'] ? ' · ' . h(nhtsa_comp((string)$b['comps'])) : '' ?> · ask for no. <?= h($b['doc']) ?></small>
    <details><summary>What it covers</summary><p><?= h((string)$b['summary']) ?></p></details></div><?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($cByComp): ?>
<section class="card" style="margin-top:16px"><h2>What other owners reported</h2>
  <p class="muted" style="margin-top:-6px">Complaints filed with NHTSA by owners of the same year, make and model. Many complaints about one part is a pattern worth mentioning to your mechanic.</p>
  <div class="kb-bars"><?php foreach (array_slice($cByComp, 0, 10, true) as $k => $list): ?>
    <details><summary><span class="kb-bar"><i style="width:<?= max(4, round(count($list) / $maxC * 100)) ?>%"></i></span><b><?= h($k) ?></b><em><?= count($list) ?></em></summary>
      <?php usort($list, fn($a, $b) => (nhtsa_comp(explode(',', $b['comps'])[0]) === $k) <=> (nhtsa_comp(explode(',', $a['comps'])[0]) === $k)); foreach (array_slice($list, 0, 4) as $c): ?><p class="kb-quote">“<?= h(mb_strimwidth(ucfirst(strtolower($c['summary'])), 0, 420, '…')) ?>”<?php if ($c['crash'] || $c['fire'] || $c['injuries']): ?> <span class="tag tag-soon"><?= h(implode(', ', array_filter([$c['crash'] ? 'crash' : '', $c['fire'] ? 'fire' : '', $c['injuries'] ? $c['injuries'] . ' injured' : '']))) ?></span><?php endif; ?></p><?php endforeach; ?>
    </details><?php endforeach; ?></div>
</section>
<?php elseif ($cmpl === null): ?><div class="callout" style="margin-top:16px">Could not reach NHTSA for owner complaints. Try again later.</div><?php endif; ?>

<?php if ($service): ?>
<section class="card" style="margin-top:16px"><h2>Service bulletins (<?= count($service) ?>)</h2>
  <p class="muted" style="margin-top:-6px">Repair instructions the manufacturer sent dealers for known problems. Summaries only: the full bulletin is in repair databases (ALLDATA, Mitchell) or at a dealer. Give your shop the number.</p>
  <?php foreach ($byComp as $k => $list): ?><details class="kb-group"><summary><b><?= h($k) ?></b> <span class="muted"><?= count($list) ?></span></summary>
    <?php foreach (array_slice($list, 0, 40) as $b): ?><div class="kb-item"><b><?= h(mb_strimwidth((string)$b['summary'], 0, 200, '…')) ?></b><small><?= h($b['type'] ?: 'Bulletin') ?> · No. <?= h($b['doc']) ?> · <?= h($fmtD($b['date'])) ?></small>
      <?php if (mb_strlen((string)$b['summary']) > 200): ?><details><summary>More</summary><p><?= h((string)$b['summary']) ?></p></details><?php endif; ?></div><?php endforeach; ?>
  </details><?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($inv): ?>
<section class="card" style="margin-top:16px"><h2>Government investigations (<?= count($inv) ?>)</h2>
  <?php foreach ($inv as $i): ?><div class="kb-item"><b><?= h(ucfirst(strtolower((string)$i['subject']))) ?></b><small><?= h($i['id']) ?> · opened <?= h($fmtD($i['opened'])) ?><?= $i['closed'] ? ' · closed ' . h($fmtD($i['closed'])) : ' · open' ?><?= $i['campaign'] ? ' · led to recall ' . h($i['campaign']) : '' ?></small>
    <?php if (trim((string)$i['summary']) !== ''): ?><details><summary>More</summary><p><?= h(mb_strimwidth((string)$i['summary'], 0, 2000, '…')) ?></p></details><?php endif; ?></div><?php endforeach; ?>
</section>
<?php endif; ?>
<?php if (!$tsb && !$inv && !$cmpl && $cmpl !== null): ?><div class="empty" style="margin-top:16px">Nothing on file with NHTSA for this year, make and model.</div><?php endif; ?>
<p class="muted" style="font-size:12.5px;margin-top:14px">Source: NHTSA Office of Defects Investigation. Bulletins and investigations cover the model year; check the details against your VIN before paying for a repair. Safety recalls are on the Recalls page.</p>
