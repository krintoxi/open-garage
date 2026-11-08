<?php
// Public code lookup (multi-user instances): no account needed. Nothing is saved.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$codesIn = (string)($_GET['codes'] ?? '');
$codes = array_slice(obd_extract_codes($codesIn), 0, 12);
$V = ['year' => (int)($_GET['year'] ?? 0) ?: '', 'make' => mb_substr(trim((string)($_GET['make'] ?? '')), 0, 40), 'model' => mb_substr(trim((string)($_GET['model'] ?? '')), 0, 40), 'engine' => mb_substr(trim((string)($_GET['engine'] ?? '')), 0, 40), 'country' => ''];
$vinIn = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['vin'] ?? '')));
$ip = login_ip();
$limited = false; $vinNote = '';
if ($vinIn !== '') {
    if (rate_ok('lookup-vin:' . $ip, 30, 3600)) { $d = vin_decode_world($vinIn); foreach (['year', 'make', 'model', 'engine', 'country'] as $k) if (!empty($d[$k]) && empty($V[$k])) $V[$k] = $d[$k]; $vinNote = (string)($d['error'] ?? ''); }
    else $limited = true;
}
$online = $codes && rate_ok('lookup:' . $ip, 40, 3600);
if ($codes && !$online) $limited = true;
$A = $codes ? diag_analyze($V, $codes, '', [], $online && $V['make'] !== '') : null;
$authTitle = $codes ? implode(' ', $codes) . ' · code lookup' : 'Free OBD code lookup';
$f = null;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= h($authTitle) ?> · <?= h(APP_NAME) ?></title>
<meta name="description" content="<?= $A && $A['items'] ? h($A['items'][0]['code'] . ': ' . $A['items'][0]['title'] . '. What it means, if it is safe to drive, likely causes, DIY checks, mechanic test plan and repair videos.') : 'Free check-engine code lookup for any car: meaning, causes, DIY fixes, mechanic test plans, recalls and repair videos.' ?>">
<link rel="manifest" href="index.php?p=manifest"><link rel="icon" type="image/png" href="assets/favicon.png"><meta name="theme-color" content="#07080a">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css?v=20"></head>
<body class="public-lookup"><div class="main" style="max-width:1100px;margin:0 auto">
<header class="pl-head"><a href="<?= url('login') ?>" class="pl-brand">✦ <?= h(APP_NAME) ?></a><span class="muted">Free code lookup</span>
  <span style="margin-left:auto" class="row"><?php if (GUESTS): ?><form method="post" action="index.php"><input type="hidden" name="action" value="guest"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="t0" value="<?= time() ?>"><button class="sm">Try the app</button></form><?php endif; ?><a class="btn sm primary" href="<?= url('signup') ?>">Free account</a></span></header>
<form method="get" class="card diag-form" style="margin:16px 0">
  <input type="hidden" name="p" value="lookup">
  <div class="form-grid"><div class="full"><label>Codes</label><input type="text" name="codes" value="<?= h(implode(' ', $codes) ?: $codesIn) ?>" placeholder="P0171 P0300 U0100" required></div>
    <div class="full"><label>VIN (optional, any country) — or fill in the vehicle</label><input type="text" name="vin" value="<?= h($vinIn) ?>" maxlength="20" autocapitalize="characters"></div>
    <div><label>Year</label><input type="number" name="year" value="<?= h((string)$V['year']) ?>"></div><div><label>Make</label><input type="text" name="make" value="<?= h($V['make']) ?>"></div><div><label>Model</label><input type="text" name="model" value="<?= h($V['model']) ?>"></div><div><label>Engine</label><input type="text" name="engine" value="<?= h($V['engine']) ?>"></div></div>
  <div class="row" style="margin-top:12px"><label class="inline"><input type="radio" name="mode" value="diy" <?= ($_GET['mode'] ?? '') !== 'pro' ? 'checked' : '' ?>> DIY view</label><label class="inline"><input type="radio" name="mode" value="pro" <?= ($_GET['mode'] ?? '') === 'pro' ? 'checked' : '' ?>> Mechanic view</label><button class="primary" style="margin-left:auto">Look up</button></div>
  <?php if ($vinNote): ?><p class="muted" style="font-size:13px"><?= h($vinNote) ?></p><?php endif; ?>
  <?php if ($limited): ?><p class="flash flash-err" style="margin-top:10px">Lots of lookups from your network: vehicle data is paused for an hour, code info still works. A free account has no such limit.</p><?php endif; ?>
</form>
<?php if ($A): $public = true; $report = null; require APP_ROOT . '/app/views/_diag_result.php'; ?>
<div class="card" style="margin-top:18px;text-align:center"><h2>Keep track of it</h2><p class="muted">Save codes to your vehicle, get reminders, recalls, a health score and a service record you can share. Free.</p><a class="btn primary" href="<?= url('signup') ?>">Create a free account</a></div>
<?php else: ?>
<div class="card"><h2>Look up any check-engine code</h2><p class="muted">Works for any car, truck or motorcycle with OBD-II / EOBD (US 1996+, EU 2001+ petrol and 2004+ diesel, and most others since). You get what it means, whether it is safe to drive, likely causes ranked, DIY checks, a mechanic test plan, known problems for your vehicle, recalls, owner complaints and repair videos.</p>
  <p>Popular: <?php foreach (['P0420', 'P0171', 'P0300', 'P0442', 'P0128', 'P0455', 'P0174', 'P0016', 'P0700', 'U0100'] as $pc): ?><a class="code" href="<?= url('lookup', ['codes' => $pc]) ?>"><?= $pc ?></a> <?php endforeach; ?></p></div>
<?php endif; ?>
</div><script src="assets/app.js?v=12"></script></body></html>
