<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
if (!is_guest()) return;
$gu = current_user(); ?>
<div class="card guest-keep" id="keep">
  <h2>Keep your garage</h2>
  <p class="muted">You are using a guest garage. It is deleted on <b><?= h(date('M j, Y', (int)$gu['guest_expires'])) ?></b> unless you keep it. Add an email and password: everything you entered stays. Free.</p>
  <form method="post"><input type="hidden" name="action" value="guest_keep"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <div class="form-grid"><div><label>Email</label><input type="email" name="email" required autocomplete="email"></div><div><label>Password (8+ characters)</label><input type="password" name="password" minlength="8" required autocomplete="new-password"></div>
      <div class="full"><label>Your name (optional)</label><input type="text" name="name" maxlength="60"></div></div>
    <?php if (reminders_available()): ?><label class="inline" style="margin-top:8px"><input type="checkbox" name="remind" value="1" checked> Email me once a week, only when something is due</label><?php endif; ?>
    <div class="row" style="margin-top:12px"><button class="primary">Keep my garage</button></div></form>
  <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="guest_end"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost danger" data-confirm="Delete this guest garage now?">Delete my guest garage now</button></form>
</div>
