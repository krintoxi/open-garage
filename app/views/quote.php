<?php
// Customer view of a repair order (?p=quote&t=<token>): approve or decline each service and sign with a name.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
session_write_close();
header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer'); header('Cache-Control: private, no-store');
$ip = login_ip();
$t = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$job = preg_match('/^[a-f0-9]{32}$/D', $t) && rate_ok('quote-miss:' . $ip, 40, 3600, false) ? row("SELECT * FROM jobs WHERE token=? AND stage<>'void'", [$t]) : null;
if (!$job) { rate_ok('quote-miss:' . $ip, 1000, 3600); http_response_code(404); exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not available</title><body style="font:16px system-ui;display:grid;place-items:center;min-height:90vh;text-align:center;padding:16px"><div><h1 style="font-size:22px">This estimate is not available</h1><p style="color:#666">Ask the shop for a new link.</p></div>'); }
$S = shop((int)$job['user_id']);
$done = '';
// Card payment (the shop's Stripe account): start Checkout, and record it when Stripe sends the customer back.
$payable = stripe_on($S) && in_array($job['stage'], ['done', 'invoiced'], true) && (float)$job['total'] - (float)$job['paid'] >= 0.5;
$paidNow = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['pay'])) {
    if ($payable && rate_ok('quote-pay:' . $ip, 10, 3600)) {
        [$cu, $perr] = stripe_checkout($job);
        if ($cu && str_starts_with($cu, 'https://checkout.stripe.com/')) { header('Location: ' . $cu, true, 303); exit; }
        $done = 'payerr';
    } else $done = 'payerr';
}
if (isset($_GET['paid']) && is_string($_GET['paid'])) {
    $paidNow = stripe_confirm($job, (string)$_GET['paid']);
    $job = row('SELECT * FROM jobs WHERE id=?', [$job['id']]);
    $payable = stripe_on($S) && in_array($job['stage'], ['done', 'invoiced'], true) && (float)$job['total'] - (float)$job['paid'] >= 0.5;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['pay']) && in_array($job['stage'], ['estimate', 'declined', 'approved', 'in_progress', 'waiting_parts'], true) && rate_ok('quote-post:' . $ip, 20, 3600)) {
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
    $pend = array_unique(array_column(rows("SELECT grp FROM job_lines WHERE job_id=? AND status='pending'", [$job['id']]), 'grp'));
    if ($name === '' || empty($_POST['agree'])) $done = 'err';
    elseif ($pend) {
        $yes = array_values(array_intersect((array)($_POST['g'] ?? []), $pend));
        foreach ($pend as $g) q('UPDATE job_lines SET status=? WHERE job_id=? AND grp=? AND status=\'pending\'', [in_array($g, $yes, true) ? 'approved' : 'declined', $job['id'], $g]);
        $tot = job_totals($job, job_lines((int)$job['id']), 'approved')['total'];
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        if ($yes) {
            $cu = $job['customer_id'] ? row('SELECT email, phone FROM customers WHERE id=?', [$job['customer_id']]) : null;
            $ver = job_snapshot((int)$job['id'], 'Approved online');
            insert('job_auth', ['job_id' => $job['id'], 'amount' => $tot, 'method' => 'link', 'by_name' => $name, 'contact' => trim(implode(' · ', array_filter([$cu['email'] ?? '', $cu['phone'] ?? '']))) ?: 'online link',
                'ip' => $ip, 'ver' => $ver, 'lines' => mb_substr(implode(', ', $yes), 0, 500), 'msg' => $note, 'note' => 'Declined: ' . (implode(', ', array_diff($pend, $yes)) ?: 'none'), 'at' => now()]);
        }
        $stage = $yes ? ($job['stage'] === 'estimate' || $job['stage'] === 'declined' ? 'approved' : $job['stage']) : ($job['stage'] === 'estimate' ? 'declined' : $job['stage']);
        q('UPDATE jobs SET stage=?, approved_at=?, approved_by=?, approve_note=?, seen_approval=0 WHERE id=?', [$stage, now(), $name, $note, $job['id']]);
        if ($yes && shop_rules($S)[1]['parts_back']) q('UPDATE jobs SET parts_return=? WHERE id=?', [!empty($_POST['parts_back']) ? 'yes' : 'no', $job['id']]);
        job_refresh((int)$job['id']);
        $done = $yes ? 'yes' : 'no';
        $job = row('SELECT * FROM jobs WHERE id=?', [$job['id']]);
    }
}
$lines = job_lines((int)$job['id']);
$pending = (bool)array_filter($lines, fn($l) => $l['status'] === 'pending');
$doc = in_array($job['stage'], ['invoiced', 'paid'], true) ? 'invoice' : 'estimate';
$insp = rows('SELECT * FROM job_inspect WHERE job_id=? ORDER BY sort', [$job['id']]);
$interactive = $pending && $doc === 'estimate';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= h(($S['name'] ?: APP_NAME) . ' · ' . ($doc === 'invoice' ? 'Invoice ' . ($job['inv_no'] ?: $job['est_no']) : 'Estimate ' . $job['est_no'])) ?></title><link rel="stylesheet" href="assets/doc.css?v=7"></head><body>
<div class="bar"><button type="button" onclick="print()">Print / save as PDF</button></div>
<div class="page">
  <?php if ($done === 'yes'): ?><div class="notice">Thank you, <?= h((string)$job['approved_by']) ?>. Your approval was sent to <?= h($S['name'] ?: 'the shop') ?>.</div>
  <?php elseif ($done === 'no'): ?><div class="notice warn">Got it: nothing was approved. The shop will contact you.</div>
  <?php elseif ($done === 'payerr'): ?><div class="notice warn">Card payment could not start right now. Please try again, or pay at the shop.</div>
  <?php elseif ($done === 'err'): ?><div class="notice warn">Please type your name and tick the box to approve.</div><?php endif; ?>
  <?php if (!empty($S['review_url']) && ($job['stage'] === 'paid' || $paidNow !== null)): ?><div class="notice review-ask">Thanks for choosing <?= h($S['name'] ?: 'us') ?>! If you have a minute, a quick review helps a small shop a lot. <a class="btn primary" href="<?= h($S['review_url']) ?>" target="_blank" rel="noopener">⭐ Leave a review</a></div><?php endif; ?>
  <?php if ($paidNow !== null): ?><div class="notice"><b>Payment received<?= $paidNow > 0 ? ': ' . money($paidNow, $S) : '' ?>.</b> Thank you! A receipt comes from Stripe by email.</div><?php endif; ?>
  <?php if ($payable): ?><form method="post" class="pay-box"><input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="pay" value="1"><div><small class="mut">Balance due</small><br><b><?= money((float)$job['total'] - (float)$job['paid'], $S) ?></b></div><button class="primary">Pay by card</button></form><?php endif; ?>
  <?php if ($interactive): ?><p><b>Please review:</b> untick anything you do not want done, then type your name and press Approve.</p><form method="post"><input type="hidden" name="t" value="<?= h($t) ?>"><?php endif; ?>
  <?php require APP_ROOT . '/app/views/_jobdoc.php'; ?>
  <?php if ($interactive): ?>
    <div class="sign"><label><b>Your name</b> (this is your signature)</label><input type="text" name="name" required maxlength="80" autocomplete="name">
      <label>Message to the shop (optional)</label><textarea name="note" rows="2" maxlength="500"></textarea>
      <?php if (shop_rules($S)[1]['parts_back']): ?><label style="display:flex;gap:8px;align-items:center;font-weight:500"><input type="checkbox" name="parts_back" value="1"> I'd like my replaced parts back (except parts returned for core credit or warranty)</label><?php endif; ?>
      <label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="agree" value="1" required style="margin-top:4px"> <span>I authorize the ticked work at the prices shown, including applicable tax and fees. Any additional work needs my further approval.</span></label>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap"><button class="primary">Approve selected work</button></div></div></form>
  <?php elseif ($doc === 'estimate'): ?><p class="mut">This estimate has been answered<?= $job['approved_by'] ? ' by ' . h($job['approved_by']) : '' ?>. Questions? Call <?= h($S['phone'] ?: 'the shop') ?>.</p><?php endif; ?>
</div></body></html>
