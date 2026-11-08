<?php
// Turn off weekly reminder emails from the link in the email (no sign-in). GET shows a button; POST does it,
// so a mail scanner that prefetches links cannot unsubscribe anyone.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
header('X-Robots-Tag: noindex');
$t = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$u = MULTI_USER && preg_match('/^[a-f0-9]{32}$/D', $t) ? row('SELECT id, email, remind_weekly FROM users WHERE unsub_token=?', [$t]) : null;
$done = false;
if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') { q('UPDATE users SET remind_weekly=0 WHERE id=?', [$u['id']]); $done = true; }
$authTitle = 'Reminder emails';
require APP_ROOT . '/app/views/_authhead.php';
?>
<div class="auth-card">
  <h1>Reminder emails</h1>
  <?php if (!$u): ?>
    <p>This link is not valid any more. You can turn reminders off under Vehicle &amp; settings after signing in.</p>
  <?php elseif ($done || !(int)$u['remind_weekly']): ?>
    <p>Weekly reminders are off for this account. You can turn them back on under Vehicle &amp; settings.</p>
  <?php else: ?>
    <p>Stop the weekly "what's due" email?</p>
    <form method="post"><input type="hidden" name="action" value="unsub"><input type="hidden" name="t" value="<?= h($t) ?>"><button class="primary" type="submit">Turn reminders off</button></form>
  <?php endif; ?>
  <p style="margin-top:14px"><a href="<?= url('login') ?>">Go to <?= h(APP_NAME) ?> →</a></p>
</div></body></html>
