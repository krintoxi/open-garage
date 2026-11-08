<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
/** Shared <head> and opening markup for the signed-out pages. Expects $authTitle. */
$f = flash(); ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= h(APP_NAME) ?> · <?= h($authTitle) ?></title>
<meta name="description" content="A free garage for your car, motorcycle or scooter: issues, maintenance, mechanic visits, and a showroom view of your own photo.">
<link rel="manifest" href="index.php?p=manifest"><link rel="apple-touch-icon" href="assets/apple-touch-icon.png"><link rel="icon" type="image/png" href="assets/favicon.png">
<meta name="theme-color" content="#07080a"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css?v=12"></head>
<body class="auth"><div class="login">
<?php if (MULTI_USER): ?><div class="auth-pitch"><h1>✦ <?= h(APP_NAME) ?></h1><p>Every car, motorcycle and scooter you own, in one place: what's wrong, what's due, what the shop did, and a showroom view of your own photo.</p><ul><li>Add a vehicle in a minute: type the VIN or pick it from a list</li><li>Snap a photo: we cut it out and put it on a lit stage, even in 3D</li><li>A health score and a plain to-do list: what to fix, what's due, this week</li><li>Free safety-recall check for every vehicle</li><li>Share one link with your mechanic, or with a buyer when you sell</li><li>Install it on your phone like an app; reminders by email</li></ul></div><?php endif; ?>
