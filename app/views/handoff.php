<?php
// Public report a driver sent to a shop (?p=handoff&t=<token>). Readable by anyone with the link (any mechanic can
// print it); a signed-in shop on this app turns it into an estimate in one tap. Everything is read by the report's own
// vehicle and the items the driver picked, never by the viewer's session.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
$ip = login_ip();
$ho = handoff_by_token((string)($_GET['t'] ?? ''));
if (!$ho) {
    rate_ok('share-miss:' . $ip, 1000, 3600);
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not available</title><body style="font:16px system-ui;background:#fff;color:#111;display:grid;place-items:center;min-height:90vh;margin:0;padding:16px;text-align:center"><div><h1 style="font-size:22px">This report is not available</h1><p style="color:#666">The owner may have turned the link off. Ask them to send a new one.</p></div>');
}
if (!rate_ok('share-miss:' . $ip, 40, 3600, false) || !rate_ok('share-view:' . $ip, 300, 3600)) { http_response_code(429); exit('Too many requests. Try again later.'); }
$D = handoff_data($ho); $v = $D['v'];
if (!$v) { http_response_code(404); exit('Not available'); }

// A photo the driver chose to include, streamed only for this report.
if (isset($_GET['img'])) {
    session_write_close();
    $pid = (int)$_GET['img']; $ph = null; foreach ($D['photos'] as $p) if ((int)$p['id'] === $pid) $ph = $p;
    $f = $ph ? realpath(UPLOAD_DIR . '/' . basename((string)$ph['path'])) : false;
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $ext = $f ? strtolower(pathinfo($f, PATHINFO_EXTENSION)) : '';
    if (!$f || !str_starts_with($f, realpath(UPLOAD_DIR) . DIRECTORY_SEPARATOR) || !isset($types[$ext])) { http_response_code(404); exit; }
    header('Content-Type: ' . $types[$ext]); header('Content-Length: ' . filesize($f)); header('X-Content-Type-Options: nosniff');
    readfile($f); exit;
}
$mine = $authed && (int)$ho['user_id'] === uid();
if (!$mine) q('UPDATE handoffs SET views=views+1, opened_at=COALESCE(opened_at, ?) WHERE id=?', [now(), $ho['id']]);
$isShop = $authed && !$mine && !is_guest();
$imported = $isShop && $ho['job_id'] && (int)$ho['shop_user_id'] === uid() && owns_row('jobs', (int)$ho['job_id']) ? row('SELECT id, est_no, stage FROM jobs WHERE id=?', [$ho['job_id']]) : null;
$csrfTok = csrf();
session_write_close();
$name = vehicle_name($v);
$miles = (int)$v['mileage'];
$digits = preg_replace('/[^\d+]/', '', (string)$ho['contact_phone']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= h($name) ?> · Report for the shop</title>
<style>
:root{--ink:#0f1115;--mut:#5b6170;--line:#e4e6ec;--soft:#f5f6f8;--acc:#e10600}
*{box-sizing:border-box}body{margin:0;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:var(--ink);background:#fff;overflow-wrap:anywhere}
.wrap{max-width:880px;margin:0 auto;padding:24px 20px 60px}
.cta{display:flex;gap:14px;align-items:center;flex-wrap:wrap;background:#fff7f6;border:1px solid #f5c6c2;border-radius:14px;padding:14px 16px;margin-bottom:20px}
.cta p{margin:0;flex:1 1 280px;font-size:14px}.cta b{display:block;font-size:15px}
.btn{display:inline-block;font:inherit;font-weight:600;padding:10px 16px;border-radius:10px;border:1px solid var(--line);background:#fff;color:var(--ink);text-decoration:none;cursor:pointer}
.btn.primary{background:var(--acc);border-color:var(--acc);color:#fff}
.top{border-bottom:2px solid var(--ink);padding-bottom:16px}
.eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--mut);font-weight:600}
h1{font-size:28px;line-height:1.1;margin:4px 0 8px}.facts{display:flex;flex-wrap:wrap;gap:6px 18px;color:var(--mut);font-size:14px}.facts b{color:var(--ink);font-weight:600}
.concern{font-size:17px;background:var(--soft);border-radius:12px;padding:14px 16px;margin:18px 0 0;white-space:pre-line}
.who{display:flex;flex-wrap:wrap;gap:8px 18px;margin-top:12px;font-size:14px}.who a{color:var(--ink)}
h2{font-size:17px;margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.item{padding:10px 0;border-bottom:1px solid var(--line)}.item b{font-size:15px}.item p{margin:4px 0 0;color:var(--mut);font-size:14px;white-space:pre-line}
.tag{display:inline-block;font-size:12px;font-weight:600;padding:1px 9px;border-radius:999px;border:1px solid;margin-left:6px;vertical-align:1px}
.s5{color:#b91c1c}.s4{color:#c2410c}.s3{color:#b45309}.s2{color:#1d4ed8}.s1{color:#4b5563}
.code{font-family:ui-monospace,Menlo,monospace;font-weight:700;background:var(--soft);border:1px solid var(--line);border-radius:6px;padding:1px 6px;margin-right:6px}
.photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}.photos a{display:block}.photos img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:10px;border:1px solid var(--line)}.photos small{color:var(--mut);font-size:12.5px}
table{width:100%;border-collapse:collapse;font-size:14px}td,th{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
td.n{white-space:nowrap}.mut{color:var(--mut)}.ok{color:#15803d}.due{color:#b45309}.over{color:#b91c1c}
.bar{display:flex;justify-content:flex-end;margin-bottom:12px}
footer{margin-top:36px;color:var(--mut);font-size:12px;border-top:1px solid var(--line);padding-top:12px}
@media(max-width:640px){.wrap{padding:16px 16px 40px}h1{font-size:23px}table{font-size:13px}}
@media print{.cta,.bar{display:none}.wrap{padding:0}h2{break-after:avoid}.item,tr{break-inside:avoid}}
</style></head><body><div class="wrap">
<?php if ($mine): ?>
  <div class="cta"><p><b>This is what the shop sees.</b>You can turn the link off from Send to a shop in your garage.</p><a class="btn" href="<?= h(url('send_shop')) ?>">Back to my garage</a></div>
<?php elseif ($imported): ?>
  <div class="cta"><p><b>Already on your board as <?= h($imported['est_no']) ?>.</b>Open it to add services and send the estimate.</p><a class="btn primary" href="<?= h(url('job', ['id' => $imported['id']])) ?>">Open <?= h($imported['est_no']) ?></a></div>
<?php elseif ($isShop): ?>
  <div class="cta"><p><b>Turn this into an estimate</b>Adds the customer, the vehicle and their concern to your shop board<?= $ho['allow_return'] ? ". When you invoice, the finished work goes back to their app, marked as verified by your shop" : '' ?>.</p>
    <form method="post" action="index.php"><input type="hidden" name="action" value="handoff_import"><input type="hidden" name="_csrf" value="<?= h($csrfTok) ?>"><input type="hidden" name="t" value="<?= h($ho['token']) ?>"><button class="btn primary">Create estimate</button></form></div>
<?php else: ?>
  <div class="cta"><p><b>Run a repair shop?</b><?= h(APP_NAME) ?> is free for shops: estimates, approvals, inspections, scheduling and invoices. Sign in or create a shop account, then open this link again to add it to your board in one tap.</p><a class="btn" href="<?= h(url('signup')) ?>">Free shop account</a></div>
<?php endif; ?>
<div class="bar"><button type="button" class="btn" onclick="print()">Print</button></div>

<div class="top">
  <div class="eyebrow">Customer report for the shop · <?= h(date('M j, Y', strtotime((string)$ho['created_at']))) ?></div>
  <h1><?= h($name) ?></h1>
  <div class="facts">
    <?php if ($v['trim']): ?><span><?= h($v['trim']) ?></span><?php endif; ?>
    <?php if ($v['engine']): ?><span><?= h($v['engine']) ?></span><?php endif; ?>
    <span><b><?= number_format($miles) ?></b> mi<?= $v['mileage_updated'] ? ' (' . h(date('M j', strtotime((string)$v['mileage_updated']))) . ')' : '' ?></span>
    <?php if ($v['vin']): ?><span>VIN <b style="font-family:ui-monospace,monospace"><?= h($v['vin']) ?></b></span><?php endif; ?>
    <?php if ($v['plate']): ?><span>Plate <b><?= h($v['plate']) ?></b></span><?php endif; ?>
  </div>
  <?php if (trim((string)$ho['concern']) !== ''): ?><div class="concern">“<?= h(trim((string)$ho['concern'])) ?>”</div><?php endif; ?>
  <div class="who"><b><?= h($ho['contact_name'] ?: 'The owner') ?></b><?php if ($digits !== ''): ?><a href="tel:<?= h($digits) ?>"><?= h($ho['contact_phone']) ?></a><?php endif; ?><?php if ($ho['contact_email']): ?><a href="mailto:<?= h($ho['contact_email']) ?>"><?= h($ho['contact_email']) ?></a><?php endif; ?></div>
</div>

<?php if ($D['issues']): ?>
<h2>Problems they noticed (<?= count($D['issues']) ?>)</h2>
<?php foreach ($D['issues'] as $i): $s = max(1, min(5, (int)$i['severity'])); ?>
  <div class="item"><b><?= h($i['title']) ?></b><span class="tag s<?= $s ?>"><?= h(SEV_WORDS[$s]) ?></span><?php if ($i['opened_on']): ?> <span class="mut" style="font-size:13px">since <?= h(fmt_date($i['opened_on'])) ?></span><?php endif; ?>
    <?php if (trim((string)$i['summary']) !== ''): ?><p><?= h(mb_strimwidth(trim((string)$i['summary']), 0, 600, '…')) ?></p><?php endif; ?>
    <?php if (trim((string)$i['evidence']) !== ''): ?><p><b style="font-size:13px;color:var(--ink)">What they saw:</b> <?= h(mb_strimwidth(trim((string)$i['evidence']), 0, 600, '…')) ?></p><?php endif; ?></div>
<?php endforeach; endif; ?>

<?php if ($D['codes']): ?>
<h2>Warning codes</h2>
<?php foreach ($D['codes'] as $c): $lib = obd_lookup($c, $v); ?><div class="item"><span class="code"><?= h($c) ?></span><b><?= h($lib['title']) ?></b><?php if (!empty($lib['kb']['causes'])): ?><p>Common causes: <?= h(implode('; ', array_map(fn($x) => is_array($x) ? $x[0] : (string)$x, array_slice($lib['kb']['causes'], 0, 4)))) ?></p><?php elseif (!empty($lib['library']['causes'])): ?><p>Common causes: <?= h(mb_strimwidth((string)$lib['library']['causes'], 0, 300, '…')) ?></p><?php endif; ?></div><?php endforeach; ?>
<p class="mut" style="font-size:12.5px">Codes read by the owner. Codes point to a system, not a part; confirm with your own tests.</p>
<?php endif; ?>

<?php if ($D['diag']): $dc = json_decode((string)$D['diag']['codes'], true) ?: []; $sy = json_decode((string)$D['diag']['symptoms'], true) ?: []; ?>
<h2>Their diagnosis notes (<?= h(fmt_date($D['diag']['created_at'])) ?>)</h2>
<div class="item"><?php if ($dc): ?><p>Codes in that scan: <?= h(implode(', ', $dc)) ?></p><?php endif; ?><?php if ($sy): ?><p>Symptoms: <?= h(implode(', ', array_map('strval', $sy))) ?></p><?php endif; ?><?php if (trim((string)$D['diag']['notes']) !== ''): ?><p><?= h(trim((string)$D['diag']['notes'])) ?></p><?php endif; ?></div>
<?php endif; ?>

<?php if ($D['photos']): ?>
<h2>Photos</h2>
<div class="photos"><?php foreach ($D['photos'] as $p): $src = url('handoff', ['t' => $ho['token'], 'img' => $p['id']]); ?><a href="<?= h($src) ?>" target="_blank" rel="noopener"><img src="<?= h($src) ?>" alt="<?= h($p['caption']) ?>" loading="lazy"><?php if ($p['caption']): ?><small><?= h($p['caption']) ?></small><?php endif; ?></a><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($D['recalls']): ?>
<h2>Open safety recalls (<?= count($D['recalls']) ?>)</h2>
<table><?php foreach ($D['recalls'] as $r): ?><tr><td><b><?= h($r['component']) ?></b><br><span class="mut">Campaign <?= h($r['campaign']) ?><?= $r['park_it'] ? ' · <b class="over">Do not drive</b>' : '' ?></span></td></tr><?php endforeach; ?></table>
<p class="mut" style="font-size:12.5px">From NHTSA for this year, make and model; not yet confirmed against this VIN.</p>
<?php endif; ?>

<?php if ($D['visits'] || $D['maint']): ?>
<h2>Service history</h2>
<?php if ($D['maint']): ?><table><tr><th>Item</th><th>Last done</th><th>Status</th></tr>
<?php foreach ($D['maint'] as $m): $cls = ['ok' => 'ok', 'soon' => 'due', 'overdue' => 'over'][$m['state']] ?? 'mut'; ?><tr><td><?= h($m['item']) ?></td><td class="n"><?= $m['last_date'] ? h(fmt_date($m['last_date'])) : '—' ?><?= $m['last_miles'] !== null ? '<br><span class="mut">' . number_format((int)$m['last_miles']) . ' mi</span>' : '' ?></td><td class="<?= $cls ?>"><?= h(['ok' => 'Up to date', 'soon' => 'Due soon', 'overdue' => 'Overdue'][$m['state']] ?? '—') ?></td></tr><?php endforeach; ?>
</table><?php endif; ?>
<?php if ($D['visits']): ?><table style="margin-top:12px"><tr><th>Date</th><th>Past visits</th><th>Miles</th></tr>
<?php foreach ($D['visits'] as $x): ?><tr><td class="n"><?= h(fmt_date($x['scheduled_on'] ?: $x['created_at'])) ?></td><td><b><?= h($x['title']) ?></b><?= $x['mech'] ? ' <span class="mut">· ' . h($x['mech']) . '</span>' : '' ?><?= !empty($x['verified_by']) ? ' <span class="tag ok">✓ verified</span>' : '' ?><?php if ($x['completed_work']): ?><br><span class="mut"><?= nl2br(h(mb_strimwidth((string)$x['completed_work'], 0, 300, '…'))) ?></span><?php endif; ?></td><td class="n"><?= $x['miles'] ? number_format((int)$x['miles']) : '—' ?></td></tr><?php endforeach; ?>
</table><?php endif; ?>
<?php endif; ?>

<footer>Sent by the owner from <?= h(APP_NAME) ?>. Problems, codes and history are recorded by the owner; visits marked verified were sent by the shop that did the work.</footer>
</div></body></html>
