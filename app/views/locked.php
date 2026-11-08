<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Garage · Locked</title>
<link rel="stylesheet" href="assets/app.css?v=7"></head>
<body><div class="login"><div class="card">
<h1 style="font-size:24px">✦ Garage</h1>
<p><b>This garage is locked: no password has been set.</b></p>
<p class="muted">For safety the app will not open to the internet without a password, and a password can't be set from a browser. Whoever runs the server sets it from the site folder:</p>
<pre class="mono" style="white-space:pre-wrap;font-size:13px">php app/tools/set-password.php</pre>
<p class="muted">It asks for the new password without showing it. Then reload this page and sign in.</p>
</div></div></body></html>
