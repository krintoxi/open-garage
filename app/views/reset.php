<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$authTitle = 'Choose a new password'; require __DIR__ . '/_authhead.php'; ?>
<form method="post" class="card"><input type="hidden" name="action" value="reset"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="t" value="<?= h((string)($_GET['t'] ?? '')) ?>">
<h1 style="font-size:24px">Choose a new password</h1>
<?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
<label>New password (8+ characters)</label><input type="password" name="password" required minlength="8" autofocus autocomplete="new-password">
<div class="row end" style="margin-top:14px"><button class="primary" type="submit">Save and sign in</button></div>
</form></div></body></html>
