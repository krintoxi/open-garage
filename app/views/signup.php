<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$authTitle = 'Create an account'; require __DIR__ . '/_authhead.php'; ?>
<form method="post" class="card"><input type="hidden" name="action" value="signup"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="t0" value="<?= time() ?>">
<h1 style="font-size:24px">Create your free account</h1><p class="muted">Takes about 20 seconds. No card, no catch.</p>
<?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
<?php if (!signups_open()): ?><p>Sign-ups are closed right now.</p><?php else: ?>
<label>What should we call you? (optional)</label><input type="text" name="name" autocomplete="given-name" maxlength="60">
<label>Email</label><input type="email" name="email" required autocomplete="email" maxlength="190">
<label>Password (8+ characters)</label><input type="password" name="password" required minlength="8" autocomplete="new-password">
<?php if (reminders_available()): ?><label class="inline" style="margin-top:10px"><input type="checkbox" name="remind" value="1" checked> Email me once a week, only when something is due</label><?php endif; ?>
<div class="hp" aria-hidden="true"><label>Website</label><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
<div class="row end" style="margin-top:14px"><button class="primary" type="submit">Create my account</button></div>
<?php endif; ?>
<p class="muted" style="margin:16px 0 0;font-size:14px">Already have one? <a href="<?= url('login') ?>">Sign in</a></p>
</form></div></body></html>
