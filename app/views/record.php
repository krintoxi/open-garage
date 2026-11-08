<?php
// Public, read-only service record behind an unguessable share link (?p=record&t=<token>). No sign-in.
// Everything here is read by the share's vehicle_id only; never vid()/uid(), which belong to whoever is signed in.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
session_write_close();
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
$ip = login_ip();
$sh = share_by_token((string)($_GET['t'] ?? ''));
if (!$sh) {
    rate_ok('share-miss:' . $ip, 1000, 3600); // count misses; enough of them and even a right token is refused for an hour
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not available</title><body style="font:16px system-ui;background:#0b0d11;color:#e9ebf1;display:grid;place-items:center;min-height:90vh;margin:0;padding:16px;text-align:center"><div><h1 style="font-size:22px">This service record is not available</h1><p style="color:#8d95a9">The owner may have turned the link off. Ask them for a new one.</p></div>';
    exit;
}
if (!rate_ok('share-miss:' . $ip, 40, 3600, false) || !rate_ok('share-view:' . $ip, 300, 3600)) { http_response_code(429); exit('Too many requests. Try again later.'); }
$vehId = (int)$sh['vehicle_id'];
$v = row('SELECT * FROM vehicle WHERE id=?', [$vehId]);
if (!$v) { http_response_code(404); exit('Not available'); }

// The vehicle picture (cut-out, else the photo), streamed only for this share.
if (isset($_GET['img'])) {
    $p = basename((string)($v['hero_cutout'] ?: ($v['photo_credit'] ? $v['photo'] : '')));
    $f = $p !== '' ? realpath(UPLOAD_DIR . '/' . $p) : false;
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = $f ? strtolower(pathinfo($f, PATHINFO_EXTENSION)) : '';
    if (!$f || !str_starts_with($f, realpath(UPLOAD_DIR) . DIRECTORY_SEPARATOR) || !isset($types[$ext])) { http_response_code(404); exit; }
    header('Content-Type: ' . $types[$ext]); header('Content-Length: ' . filesize($f)); header('X-Content-Type-Options: nosniff');
    readfile($f); exit;
}
q('UPDATE shares SET views=views+1, last_view=? WHERE id=?', [now(), $sh['id']]);

$miles = (int)$v['mileage'];
$health = vehicle_health($vehId);
$visits = rows("SELECT v.*, m.name mech FROM visits v LEFT JOIN mechanics m ON m.id=v.mechanic_id WHERE v.vehicle_id=? AND v.status='done' ORDER BY COALESCE(v.scheduled_on, v.created_at) DESC", [$vehId]);
$maint = rows('SELECT * FROM maintenance WHERE vehicle_id=? ORDER BY priority, item', [$vehId]);
$issues = $sh['include_issues'] ? rows("SELECT * FROM issues WHERE vehicle_id=? AND status IN ('open','monitoring','scheduled') ORDER BY severity DESC", [$vehId]) : [];
$fixed = rows("SELECT title, resolved_on FROM issues WHERE vehicle_id=? AND status='resolved' ORDER BY resolved_on DESC LIMIT 15", [$vehId]);
$recalls = rows('SELECT * FROM recalls WHERE vehicle_id=? ORDER BY report_date DESC', [$vehId]);
$log = rows('SELECT logged_at, miles FROM mileage_log WHERE vehicle_id=? ORDER BY logged_at DESC, id DESC LIMIT 24', [$vehId]);
$spent = array_sum(array_map(fn($x) => (float)$x['cost'], $visits));
$vin = (string)$v['vin'];
$vinShown = $vin === '' ? '' : ($sh['include_vin'] ? $vin : str_repeat('•', max(0, strlen($vin) - 6)) . substr($vin, -6));
// Only the cut-out vehicle or a credited stock photo: the owner's own photos can show people, places and plates.
$hasImg = (string)($v['hero_cutout'] ?: ($v['photo_credit'] ? $v['photo'] : '')) !== '';
$name = vehicle_name($v);
$tone = ['good' => '#16a34a', 'ok' => '#65a30d', 'warn' => '#d97706', 'bad' => '#dc2626'][$health['tone']];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= h($name) ?> · Service record</title>
<style>
:root{--ink:#0f1115;--mut:#5b6170;--line:#e4e6ec;--soft:#f5f6f8;--acc:#e10600}
*{box-sizing:border-box}body{margin:0;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:var(--ink);background:#fff;overflow-wrap:anywhere}
.wrap{max-width:880px;margin:0 auto;padding:28px 20px 60px}
.top{display:flex;gap:20px;align-items:center;flex-wrap:wrap;border-bottom:2px solid var(--ink);padding-bottom:18px}
.top img{width:220px;max-width:100%;max-height:150px;object-fit:contain;border-radius:10px;background:radial-gradient(closest-side,#eef0f4,#fff)}
.top h1{font-size:28px;line-height:1.1;margin:2px 0 6px}.eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--mut);font-weight:600}
.facts{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--mut);font-size:14px}.facts b{color:var(--ink);font-weight:600}
.score{margin-left:auto;text-align:center;min-width:110px}.score b{display:block;font-size:40px;line-height:1;color:<?= $tone ?>}.score small{color:var(--mut)}
h2{font-size:17px;margin:30px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--line)}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);vertical-align:top}th{white-space:nowrap;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
td.n{white-space:nowrap;font-variant-numeric:tabular-nums}.pill{display:inline-block;font-size:12px;padding:1px 8px;border-radius:999px;background:var(--soft);border:1px solid var(--line)}
.ok{color:#15803d}.due{color:#b45309}.over{color:#b91c1c}.mut{color:var(--mut)}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:18px}.stats div{background:var(--soft);border-radius:10px;padding:10px 12px}.stats b{display:block;font-size:20px}.stats small{color:var(--mut)}
.bar{display:flex;gap:8px;justify-content:flex-end;margin-bottom:14px}.bar button{font:inherit;padding:8px 14px;border-radius:8px;border:1px solid var(--line);background:#fff;cursor:pointer}
footer{margin-top:36px;color:var(--mut);font-size:12px;border-top:1px solid var(--line);padding-top:12px}
@media(max-width:640px){.wrap{padding:18px 16px 40px}.top h1{font-size:23px}.score{margin-left:0}.stats{grid-template-columns:repeat(2,1fr)}.hide-sm{display:none}table{font-size:13px}}
@media print{.bar{display:none}.wrap{padding:0}h2{break-after:avoid}tr{break-inside:avoid}}
</style></head><body><div class="wrap">
<div class="bar"><button type="button" onclick="print()">Print / save as PDF</button></div>
<div class="top">
  <?php if ($hasImg): ?><img src="<?= h(url('record', ['t' => $sh['token'], 'img' => 1])) ?>" alt=""><?php endif; ?>
  <div>
    <div class="eyebrow">Service record</div>
    <h1><?= h($name) ?></h1>
    <div class="facts">
      <?php if ($v['trim']): ?><span><?= h($v['trim']) ?></span><?php endif; ?>
      <span><b><?= number_format($miles) ?></b> mi<?= $v['mileage_updated'] ? ' (' . fmt_date($v['mileage_updated']) . ')' : '' ?></span>
      <?php if ($v['color']): ?><span><?= h($v['color']) ?></span><?php endif; ?>
      <?php if ($vinShown): ?><span>VIN <b style="font-family:ui-monospace,monospace"><?= h($vinShown) ?></b></span><?php endif; ?>
    </div>
  </div>
  <div class="score"><b><?= $health['score'] ?></b><small>health score<br><?= h($health['grade']) ?></small></div>
</div>
<div class="stats">
  <div><b><?= count($visits) ?></b><small>mechanic visits</small></div>
  <div><b><?= count(array_filter($maint, fn($m) => $m['last_date'] || $m['last_miles'] !== null)) ?></b><small>service items logged</small></div>
  <div><b><?= count($fixed) ?></b><small>problems fixed</small></div>
  <div><b><?= $sh['include_costs'] ? '$' . number_format($spent, 0) : count($log) ?></b><small><?= $sh['include_costs'] ? 'spent on repairs' : 'mileage readings' ?></small></div>
</div>

<?php if ($issues): ?>
<h2>Open problems (<?= count($issues) ?>)</h2>
<table><tr><th>Problem</th><th>Severity</th><th class="hide-sm">Since</th></tr>
<?php foreach ($issues as $i): ?><tr><td><b><?= h($i['title']) ?></b><?php if ($i['summary']): ?><br><span class="mut"><?= h(mb_strimwidth((string)$i['summary'], 0, 240, '…')) ?></span><?php endif; ?></td><td class="n"><?= (int)$i['severity'] ?>/5 · <?= h(SEVERITY[max(1, min(5, (int)$i['severity']))]) ?></td><td class="n hide-sm"><?= fmt_date($i['opened_on']) ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Mechanic visits</h2>
<?php if (!$visits): ?><p class="mut">No completed visits recorded yet.</p><?php else: ?>
<table><tr><th>Date</th><th>Work done</th><th class="hide-sm">Miles</th><?php if ($sh['include_costs']): ?><th>Cost</th><?php endif; ?></tr>
<?php foreach ($visits as $x): ?><tr><td class="n"><?= fmt_date($x['scheduled_on'] ?: $x['created_at']) ?></td>
  <td><b><?= h($x['title']) ?></b><?= $x['mech'] ? ' <span class="mut">· ' . h($x['mech']) . '</span>' : '' ?><?= $x['signed_off'] ? ' <span class="pill">signed off</span>' : '' ?>
    <?php if ($x['completed_work']): ?><br><span class="mut"><?= nl2br(h(mb_strimwidth((string)$x['completed_work'], 0, 600, '…'))) ?></span><?php endif; ?></td>
  <td class="n hide-sm"><?= $x['miles'] ? number_format((int)$x['miles']) : '—' ?></td><?php if ($sh['include_costs']): ?><td class="n"><?= $x['cost'] !== null ? '$' . number_format((float)$x['cost'], 0) : '—' ?></td><?php endif; ?></tr><?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Maintenance</h2>
<table><tr><th>Item</th><th>Last done</th><th>Status</th></tr>
<?php foreach ($maint as $m): [$st, , , $lbl] = maintenance_status($m, $miles); $cls = ['ok' => 'ok', 'soon' => 'due', 'overdue' => 'over'][$st] ?? 'mut'; ?>
<tr><td><b><?= h($m['item']) ?></b></td><td class="n"><?= $m['last_date'] ? fmt_date($m['last_date']) : '—' ?><?= $m['last_miles'] !== null ? '<br><span class="mut">' . number_format((int)$m['last_miles']) . ' mi</span>' : '' ?></td>
<td class="<?= $cls ?>"><?= h(['ok' => 'Up to date', 'soon' => 'Due soon', 'overdue' => 'Overdue'][$st] ?? 'Not recorded') ?></td></tr><?php endforeach; ?>
</table>

<?php if ($fixed): ?>
<h2>Problems fixed</h2>
<table><?php foreach ($fixed as $f): ?><tr><td><?= h($f['title']) ?></td><td class="n mut"><?= fmt_date($f['resolved_on']) ?></td></tr><?php endforeach; ?></table>
<?php endif; ?>

<h2>Safety recalls</h2>
<?php if (!$recalls): ?><p class="mut"><?= (int)$v['recalls_checked'] > 0 ? 'NHTSA lists no recalls for this year, make and model.' : 'Not checked yet.' ?></p><?php else: ?>
<table><?php foreach ($recalls as $r): ?><tr><td><b><?= h($r['component']) ?></b><br><span class="mut">Campaign <?= h($r['campaign']) ?><?= $r['report_date'] ? ' · ' . fmt_date($r['report_date']) : '' ?></span></td>
<td class="n <?= $r['status'] === 'fixed' ? 'ok' : ($r['status'] === 'na' ? 'mut' : 'due') ?>"><?= h(['fixed' => 'Repaired', 'na' => 'Does not apply', 'open' => 'Not confirmed'][$r['status']] ?? $r['status']) ?></td></tr><?php endforeach; ?></table>
<?php endif; ?>

<?php if ($log): ?>
<h2>Odometer readings</h2>
<table><tr><th>Date</th><th>Miles</th></tr><?php foreach ($log as $l): ?><tr><td class="n"><?= fmt_date($l['logged_at']) ?></td><td class="n"><?= number_format((int)$l['miles']) ?></td></tr><?php endforeach; ?></table>
<?php endif; ?>

<footer>Shared by the owner from <?= h(APP_NAME) ?> · generated <?= h(date('M j, Y')) ?>. Records are entered by the owner and their mechanics; they are not verified by <?= h(APP_NAME) ?>.</footer>
</div><?php if (!empty($_GET['print'])): ?><script>addEventListener('load', () => setTimeout(() => print(), 400));</script><?php endif; ?></body></html>
