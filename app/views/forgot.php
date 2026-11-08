<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$authTitle = 'Reset password'; require __DIR__ . '/_authhead.php'; ?>
<form method="post" class="card"><input type="hidden" name="action" value="forgot"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
<h1 style="font-size:24px">Forgot your password?</h1><p class="muted">We'll email you a link to choose a new one.</p>
<?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
<label>Email</label><input type="email" name="email" required autofocus autocomplete="email">
<div class="row end" style="margin-top:14px"><a class="muted" href="<?= url('login') ?>" style="margin-right:auto;font-size:13px">Back to sign in</a><button class="primary" type="submit">Send link</button></div>
</form></div></body></html>
