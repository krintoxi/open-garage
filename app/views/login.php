<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$authTitle = 'Sign in'; require __DIR__ . '/_authhead.php'; ?>
<form method="post" class="card"><input type="hidden" name="action" value="login"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
<h1 style="font-size:24px"><?= MULTI_USER ? 'Sign in' : '✦ ' . h(APP_NAME) ?></h1><?php if (!MULTI_USER): ?><p class="muted">Private vehicle record.</p><?php endif; ?>
<?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
<?php if (MULTI_USER): ?><label>Email</label><input type="email" name="email" autofocus autocomplete="email" required><?php endif; ?>
<label>Password</label><input type="password" name="password" <?= MULTI_USER ? '' : 'autofocus' ?> autocomplete="current-password" required>
<div class="row end" style="margin-top:14px"><?php if (MULTI_USER): ?><a class="muted" href="<?= url('forgot') ?>" style="margin-right:auto;font-size:13px">Forgot password?</a><?php endif; ?><button class="primary" type="submit"><?= MULTI_USER ? 'Sign in' : 'Enter' ?></button></div>
<?php if (MULTI_USER && SIGNUP_OPEN): ?><p class="muted" style="margin:16px 0 0;font-size:14px">New here? <a href="<?= url('signup') ?>"><b>Create a free account →</b></a></p><?php endif; ?>
</form></div></body></html>
