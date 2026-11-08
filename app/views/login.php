<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$authTitle = 'Sign in'; require __DIR__ . '/_authhead.php';
$csrfIn = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
if (!MULTI_USER): ?>
<form method="post" class="card"><input type="hidden" name="action" value="login"><?= $csrfIn ?>
<h1 style="font-size:24px">✦ <?= h(APP_NAME) ?></h1><p class="muted">Private vehicle record.</p>
<?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
<label>Password</label><input type="password" name="password" autofocus autocomplete="current-password" required>
<div class="row end" style="margin-top:14px"><button class="primary" type="submit">Enter</button></div>
</form>
<?php else: ?>
<div class="auth-side">
  <?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
  <?php if (GUESTS || signups_open()): ?><div class="card start-card">
    <h2>Get started</h2>
    <?php if (GUESTS): ?><form method="post"><input type="hidden" name="action" value="guest"><?= $csrfIn ?><input type="hidden" name="t0" value="<?= time() ?>"><button class="primary big">Try it free, no sign-up →</button></form>
      <p class="muted small-c">Nothing to fill in. If you like it, keep it with an email.</p><?php endif; ?>
    <?php if (signups_open()): ?><a class="btn big-ghost" href="<?= url('signup') ?>">Create a free account</a><?php endif; ?>
  </div><?php endif; ?>
  <details class="card signin-card" <?= $f || isset($_GET['signin']) || !(GUESTS || signups_open()) ? 'open' : '' ?>><summary><span>Already have an account?</span> <b>Sign in</b></summary>
    <form method="post"><input type="hidden" name="action" value="login"><?= $csrfIn ?>
      <label>Email</label><input type="email" name="email" autocomplete="email" required>
      <label>Password</label><input type="password" name="password" autocomplete="current-password" required>
      <div class="row end" style="margin-top:14px"><a class="muted" href="<?= url('forgot') ?>" style="margin-right:auto;font-size:13px">Forgot your password?</a><button class="primary" type="submit">Sign in</button></div>
    </form></details>
  <?php if (PUBLIC_LOOKUP): ?><div class="card lookup-card"><b>Check-engine light on?</b><p class="muted small-c" style="text-align:left;margin:2px 0 8px">Type the code to see what it means. No account needed.</p>
    <form method="get" class="row" style="flex-wrap:nowrap"><input type="hidden" name="p" value="lookup"><input type="text" name="codes" placeholder="e.g. P0420" required autocapitalize="characters"><button>Look up</button></form></div><?php endif; ?>
</div>
<?php endif; ?>
</div></body></html>
