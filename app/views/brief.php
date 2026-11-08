<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$v = row('SELECT v.*, m.name mech FROM visits v LEFT JOIN mechanics m ON m.id=v.mechanic_id WHERE v.id=?', [(int)($_GET['id'] ?? 0)]);
if (!$v) { echo 'Visit not found.'; return; }
$issues = rows('SELECT i.* FROM issues i JOIN visit_issues vi ON vi.issue_id=i.id WHERE vi.visit_id=? ORDER BY severity DESC', [$v['id']]);
$parts = rows("SELECT * FROM parts WHERE " . vw() . " AND status IN ('wanted','ordered','on hand') ORDER BY category, name");
// Specs for the systems this visit touches (from the Specs & torque page), not the maintenance reminders.
// Engine specs always go in: engine oil and the M272 numbers come up on almost any job.
$specCats = ['Engine' => ['Engine'], 'Transmission' => ['Transmission'], 'Driveline' => ['Driveline'], 'Exhaust' => ['Engine'],
    'Emissions / EVAP' => ['Fuel'], 'Cooling' => ['Cooling'], 'A/C & HVAC' => ['A/C', 'Cooling'], 'Electrical' => ['Electrical'],
    'Suspension' => ['Wheels'], 'Brakes' => ['Brakes', 'Wheels'], 'Wheels / Tires' => ['Wheels'], 'Fluids' => ['Engine', 'Transmission', 'Cooling', 'Brakes', 'Steering', 'Driveline']];
$want = ['Engine'];
foreach ($issues as $i) foreach ($specCats[$i['system']] ?? [] as $c) $want[] = $c;
$want = array_values(array_unique($want));
$allSpecs = rows('SELECT * FROM specs WHERE ' . vw() . ' ORDER BY sort, id');
$specs = $issues ? array_values(array_filter($allSpecs, function ($s) use ($want) { foreach ($want as $c) if (stripos((string)$s['category'], $c) === 0) return true; return false; })) : $allSpecs;
$conf = ['high' => 'verified', 'medium' => 'verify in WIS', 'low' => 'unverified'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>Brief · <?= h($v['title']) ?></title>
<style>body{font:14px/1.5 -apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111;max-width:820px;margin:30px auto;padding:0 20px;background:#fff;overflow-wrap:anywhere}@media(max-width:600px){body{margin:16px auto;padding:0 16px}}h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:22px 0 6px;border-bottom:2px solid #111;padding-bottom:3px;text-transform:uppercase;letter-spacing:.04em}h3{font-size:14px;margin:14px 0 4px}p,ul{margin:4px 0 8px}ul{padding-left:20px}.meta{color:#555;font-size:13px}.box{border:1px solid #bbb;border-radius:6px;padding:10px 14px;margin:8px 0}.sev{display:inline-block;border:1px solid #333;border-radius:10px;padding:0 8px;font-size:12px}.sign{margin-top:30px;display:flex;flex-wrap:wrap;gap:20px 40px}.sign div{min-width:200px}.sign div{flex:1;border-top:1px solid #333;padding-top:4px;font-size:12px;color:#555}@media print{button{display:none}}</style></head><body>
<button onclick="print()">Print</button>
<h1><?= h($veh['year'] . ' ' . $veh['make'] . ' ' . $veh['model'] . ' ' . $veh['trim']) ?> — Work brief</h1>
<p class="meta"><?= h($veh['engine']) ?> · <?= h($veh['transmission']) ?> · <?= number_format($miles) ?> mi<?= $veh['vin'] ? ' · VIN ' . h($veh['vin']) : '' ?><br><b><?= h($v['title']) ?></b> · <?= $v['scheduled_on'] ? fmt_date($v['scheduled_on']) : 'date TBD' ?> · <?= h($v['mech'] ?: '') ?></p>
<h2>Requested work</h2><div class="box"><?= nl2p($v['planned_work']) ?: '<p>(none)</p>' ?></div>
<?php foreach ($issues as $i): ?>
<h2>Issue: <?= h($i['title']) ?> <span class="sev">severity <?= (int)$i['severity'] ?>/5</span></h2>
<p><?= nl2br(h($i['summary'])) ?></p>
<?php if (trim((string)$i['likely_causes'])): ?><h3>Suspected causes (owner's notes, most likely first)</h3><?= nl2p($i['likely_causes']) ?><?php endif; ?>
<?php if (trim((string)$i['mechanic_guidance'])): ?><h3>Please</h3><?= nl2p($i['mechanic_guidance']) ?><?php endif; ?>
<?php endforeach; ?>
<h2>Fluids, specs and torque for this job</h2>
<?php if ($specs): ?><p class="meta">"Verify in WIS" = widely cited but not from an official document: please confirm before torquing.</p>
<?php $byCat = []; foreach ($specs as $s) $byCat[$s['category']][] = $s; foreach ($byCat as $cat => $list): ?><h3><?= h($cat) ?></h3><ul>
<?php foreach ($list as $s): ?><li><b><?= h($s['item']) ?>:</b> <?= h($s['value']) ?> <span class="sev"><?= h($conf[$s['confidence']] ?? (string)$s['confidence']) ?></span><?php if (trim((string)$s['notes'])): ?><br><small class="meta"><?= h($s['notes']) ?></small><?php endif; ?></li>
<?php endforeach; ?></ul><?php endforeach; ?><?php else: ?><p>(no specs recorded)</p><?php endif; ?>
<?php if ($parts): ?><h2>Parts on hand / ordered / wanted</h2><ul><?php foreach ($parts as $p): ?><li><?= h($p['name']) ?><?= $p['part_no'] ? ' (' . h($p['part_no']) . ')' : '' ?> — <?= h($p['status']) ?><?= $p['supplier'] ? ', ' . h($p['supplier']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
<h2>Owner requests</h2><ul><li>Please keep the old parts for me to see.</li><li>Note the ATF/oil quantity actually used on the invoice.</li><li>Before and after photos of anything opened up are welcome.</li><li>Call before doing anything not on this list.</li></ul>
<div class="sign"><div>Mechanic signature / date</div><div>Owner signature / date</div></div>
</body></html>
