<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
/** Shared <head> and opening markup for the signed-out pages. Expects $authTitle. */
$f = flash(); ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= h(APP_NAME) ?> · <?= h($authTitle) ?></title>
<meta name="description" content="A free garage for your car, motorcycle or scooter: issues, maintenance, mechanic visits, and a showroom view of your own photo.">
<link rel="manifest" href="index.php?p=manifest"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png"><link rel="icon" type="image/png" href="assets/favicon.png">
<meta name="theme-color" content="#07080a"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css?v=22"></head>
<body class="auth"><div class="login">
<?php if (MULTI_USER): ?><div class="auth-pitch">
  <span class="brand-chip">✦ <?= h(APP_NAME) ?></span>
  <h1>Your car, sorted.</h1>
  <p class="lead">Know what's wrong, what's due and roughly what it should cost, for every car, truck, motorcycle and scooter you look after. Free.</p>
  <div class="aud">
    <div class="aud-card"><b>🚗 For drivers</b><ul><li>Snap your VIN and we fill in the rest</li><li>Check-engine light explained in plain words</li><li>Reminders, recalls and a service history you can share</li></ul></div>
    <div class="aud-card"><b>🔧 For shops</b><ul><li>Estimates customers approve from their phone</li><li>Appointments, time clock and parts ordering</li><li>Invoices and card payments</li></ul></div>
  </div>
</div><?php endif; ?>
