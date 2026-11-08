<?php
// Public online booking (?p=book&s=<shop token>): pick a day and a free time, describe the vehicle (scan the VIN or
// plate, or type it) and the problem. Lands on the shop's calendar as a request for the shop to accept.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
header('X-Robots-Tag: noindex'); header('Referrer-Policy: no-referrer');
$tok = (string)($_POST['s'] ?? $_GET['s'] ?? '');
$ip = login_ip();
$uidShop = preg_match('/^[a-f0-9]{18}$/D', $tok) && rate_ok('book-miss:' . $ip, 30, 3600, false) ? (int)col("SELECT user_id FROM shop_profile WHERE json_extract(data, '$.book_token')=? AND json_extract(data, '$.book_on')=1", [$tok]) : 0;
if (!$uidShop) { rate_ok('book-miss:' . $ip, 1000, 3600); http_response_code(404); exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not available</title><body style="font:16px system-ui;display:grid;place-items:center;min-height:90vh;text-align:center;padding:16px"><div><h1 style="font-size:22px">Online booking is not available</h1><p style="color:#666">Call the shop to book.</p></div>'); }
$S = shop($uidShop);
$days = [];
for ($i = 0; $i <= (int)$S['book_days'] && count($days) < 31; $i++) { $d = date('Y-m-d', strtotime("+$i days")); if (shop_hours($S)[(int)date('N', strtotime($d))]) $days[$d] = appt_slots($uidShop, $d); }
$day = isset($_REQUEST['date']) && isset($days[(string)$_REQUEST['date']]) ? (string)$_REQUEST['date'] : (array_key_first(array_filter($days)) ?? '');
$done = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $time = $f('time', 5);
    if ($f('website', 10) !== '' || (int)($_POST['t0'] ?? 0) > time() - 4) $err = 'Please try again.';
    elseif (!rate_ok('book:' . $ip, 5, 86400)) $err = 'Too many bookings from this network today. Please call the shop.';
    elseif ($f('name', 80) === '' || ($f('phone', 40) === '' && !filter_var($f('email', 120), FILTER_VALIDATE_EMAIL))) $err = 'Please give your name and a phone number or email.';
    elseif (!$day || !in_array($time, $days[$day] ?? [], true)) $err = 'That time is no longer free. Pick another.';
    else {
        $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $f('vin', 20)));
        insert('appointments', ['user_id' => $uidShop, 'starts_at' => "$day $time", 'minutes' => (int)$S['slot_min'], 'title' => $f('title', 120), 'concern' => $f('concern', 2000),
            'status' => 'requested', 'source' => 'online', 'name' => $f('name', 80), 'phone' => $f('phone', 40), 'email' => filter_var($f('email', 120), FILTER_VALIDATE_EMAIL) ? $f('email', 120) : '',
            'vehicle_text' => $f('vehicle_text', 120), 'vin' => preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) ? $vin : '', 'plate' => strtoupper($f('plate', 12)), 'waiting' => !empty($_POST['waiting']) ? 1 : 0,
            'notes' => '', 'seen' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $when = date('l, F j \a\t g:i a', strtotime("$day $time"));
        // Let the shop know (if it has an email on file) and confirm receipt to the customer.
        if (filter_var((string)$S['email'], FILTER_VALIDATE_EMAIL)) send_mail((string)$S['email'], 'New booking request: ' . $when, '<p><b>' . h($f('name', 80)) . '</b> asked for ' . h($when) . '.</p><p>' . h($f('vehicle_text', 120)) . ' · ' . h($f('title', 120)) . '</p><p>' . nl2br(h($f('concern', 2000))) . '</p><p>Open Appointments in ' . h(APP_NAME) . ' to accept it.</p>');
        if (filter_var($f('email', 120), FILTER_VALIDATE_EMAIL)) send_mail($f('email', 120), 'We received your request: ' . ($S['name'] ?: 'appointment'), '<p>Thanks, ' . h($f('name', 80)) . '. ' . h($S['name'] ?: 'The shop') . ' received your request for ' . h($when) . ' and will confirm it.</p><p>' . h($S['phone']) . '</p>');
        $done = $when;
    }
}
$fmt = fn(string $t) => date('g:i a', strtotime("2000-01-01 $t"));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Book with <?= h($S['name'] ?: APP_NAME) ?></title><link rel="stylesheet" href="assets/doc.css?v=4"></head><body>
<div class="page book">
  <header class="doc-head"><div><b class="shop-name"><?= h($S['name'] ?: 'Book a service') ?></b><p class="mut" style="margin:4px 0 0"><?= h(implode(' · ', array_filter([$S['address'], $S['phone']]))) ?></p></div>
    <div class="doc-id"><b>Book online</b><small><?= h(implode(', ', array_map(fn($d, $hh) => WEEKDAYS[$d] . ' ' . date('g', strtotime($hh[0])) . '–' . date('ga', strtotime($hh[1])), array_keys(array_filter(shop_hours($S))), array_filter(shop_hours($S))))) ?></small></div></header>
  <?php if ($done): ?>
    <div class="notice" style="margin-top:16px"><b>Request sent.</b> <?= h($S['name'] ?: 'The shop') ?> will confirm <?= h($done) ?><?= $S['phone'] ? ' — or call ' . h($S['phone']) : '' ?>.</div>
  <?php else: ?>
  <?php if ($S['book_note']): ?><p><?= nl2br(h((string)$S['book_note'])) ?></p><?php endif; ?>
  <?php if ($err): ?><div class="notice warn" style="margin-top:14px"><?= h($err) ?></div><?php endif; ?>
  <form method="post"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="s" value="<?= h($tok) ?>"><input type="hidden" name="t0" value="<?= time() ?>"><input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label>Day</label>
    <div class="days"><?php foreach ($days as $d => $sl): ?><a href="<?= url('book', ['s' => $tok, 'date' => $d]) ?>" class="<?= $d === $day ? 'on' : '' ?> <?= $sl ? '' : 'full' ?>"><small><?= h(date('D', strtotime($d))) ?></small><?= h(date('M j', strtotime($d))) ?></a><?php endforeach; ?></div>
    <input type="hidden" name="date" value="<?= h($day) ?>">
    <label>Time on <?= $day ? h(date('l, M j', strtotime($day))) : '—' ?></label>
    <?php if ($day && $days[$day]): ?><div class="slots"><?php foreach ($days[$day] as $i => $t): ?><label><input type="radio" name="time" value="<?= h($t) ?>" <?= ($_POST['time'] ?? '') === $t ? 'checked' : '' ?> required><span><?= h($fmt($t)) ?></span></label><?php endforeach; ?></div>
    <?php else: ?><p class="mut">No free times on this day. Pick another day<?= $S['phone'] ? ' or call ' . h($S['phone']) : '' ?>.</p><?php endif; ?>
    <label>What do you need?</label><input type="text" name="title" maxlength="120" placeholder="e.g. Oil change, brakes squeal, check engine light" value="<?= h((string)($_POST['title'] ?? '')) ?>">
    <label>Tell us more (optional)</label><textarea name="concern" rows="3" maxlength="2000"><?= h((string)($_POST['concern'] ?? '')) ?></textarea>
    <label>Your vehicle</label>
    <?= idscan_widget($tok) ?>
    <input type="text" name="vehicle_text" maxlength="120" placeholder="Year, make and model (filled in by the scan, or type it)" value="<?= h((string)($_POST['vehicle_text'] ?? '')) ?>">
    <div class="two"><div><label>VIN (optional)</label><input type="text" name="vin" maxlength="17" autocapitalize="characters" value="<?= h((string)($_POST['vin'] ?? '')) ?>"></div><div><label>Plate (optional)</label><input type="text" name="plate" maxlength="12" autocapitalize="characters" value="<?= h((string)($_POST['plate'] ?? '')) ?>"></div></div>
    <input type="hidden" name="year"><input type="hidden" name="make"><input type="hidden" name="model">
    <div class="two"><div><label>Your name</label><input type="text" name="name" maxlength="80" autocomplete="name" required value="<?= h((string)($_POST['name'] ?? '')) ?>"></div><div><label>Phone</label><input type="tel" name="phone" maxlength="40" autocomplete="tel" value="<?= h((string)($_POST['phone'] ?? '')) ?>"></div></div>
    <label>Email (for the confirmation)</label><input type="email" name="email" maxlength="120" autocomplete="email" value="<?= h((string)($_POST['email'] ?? '')) ?>">
    <label style="display:flex;gap:8px;align-items:center;font-weight:500"><input type="checkbox" name="waiting" value="1"> I'll wait at the shop</label>
    <div style="margin-top:16px"><button class="primary" <?= $day && $days[$day] ? '' : 'disabled' ?>>Request this time</button></div>
    <p class="mut" style="font-size:12.5px;margin-top:12px">Your details go only to <?= h($S['name'] ?: 'this shop') ?> to handle the booking.</p>
  </form>
  <?php endif; ?>
</div>
<script src="assets/app.js?v=14"></script></body></html>
