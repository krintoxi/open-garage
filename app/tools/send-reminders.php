<?php
// Weekly "what's due" email for accounts that turned reminders on (multi-user instances with mail configured). CLI only.
//   php app/tools/send-reminders.php            send to everyone due one (at most once every ~7 days each)
//   php app/tools/send-reminders.php --dry-run  print who would get what; sends nothing
// Run it daily from cron; each account still gets at most one email a week, and none when nothing is due.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
require dirname(__DIR__) . '/bootstrap.php';
db();
$dry = in_array('--dry-run', $argv, true);
if (!MULTI_USER) { fwrite(STDERR, "Reminders are for multi-user instances.\n"); exit(1); }
if (!reminders_available() && !$dry) { fwrite(STDERR, "Mail is not configured (MAIL_KEY / MAIL_FROM).\n"); exit(1); }
$cutoff = date('Y-m-d H:i:s', time() - 6 * 86400 - 12 * 3600);
$users = rows("SELECT * FROM users WHERE remind_weekly=1 AND verified_at IS NOT NULL AND (remind_last IS NULL OR remind_last < ?)", [$cutoff]);
$sent = $skipped = 0;
foreach ($users as $u) {
    $d = reminder_digest((int)$u['id']);
    if (!$d) { $skipped++; continue; }
    $n = array_sum(array_map(fn($x) => count($x[1]), $d));
    $subject = $n . ' thing' . ($n === 1 ? '' : 's') . ' due for your ' . (count($d) === 1 ? $d[0][0] : count($d) . ' vehicles');
    if ($dry) { echo "{$u['email']}: $subject\n"; foreach ($d as [$name, $lines]) { echo "  $name\n"; foreach ($lines as $l) echo "    - $l\n"; } continue; }
    $unsub = base_url() . '/' . url('unsub', ['t' => unsub_token_for((int)$u['id'])]);
    if (send_mail((string)$u['email'], $subject, reminder_html($d, $unsub))) { q('UPDATE users SET remind_last=? WHERE id=?', [now(), $u['id']]); $sent++; }
    else fwrite(STDERR, "send failed for user {$u['id']}\n");
    usleep(300000); // stay well under the mail provider's rate limit
}
echo ($dry ? 'dry run: ' : '') . count($users) . " eligible, $sent sent, $skipped had nothing due\n";
