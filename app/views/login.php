<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$f = flash(); ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Garage · Sign in</title>
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="icon" type="image/png" href="assets/favicon.png">
<meta name="theme-color" content="#07080a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css?v=7"></head>
<body><div class="login"><form method="post" class="card"><input type="hidden" name="action" value="login"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
<h1 style="font-size:24px">✦ Garage</h1><p class="muted">Private vehicle record.</p>
<?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
<label>Password</label><input type="password" name="password" autofocus autocomplete="current-password">
<div class="row end" style="margin-top:14px"><button class="primary" type="submit">Enter</button></div></form></div></body></html>
