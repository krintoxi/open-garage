<?php
// Turn a single-owner Garage into a multi-user one without losing anything, or make an existing account an admin.
// Command line only:
//   php app/tools/make-admin.php you@example.com            (converts: account #1 gets the owner's records and current password)
//   php app/tools/make-admin.php someone@example.com --admin (an existing account becomes an admin)
// Run it as the web-server user, then set define('MULTI_USER', true) in app/config.local.php.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
$email = mb_strtolower(trim((string)($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "Usage: php app/tools/make-admin.php you@example.com [--admin]\n"); exit(1); }
require dirname(__DIR__) . '/bootstrap.php';
db();
if (in_array('--admin', $argv, true)) {
    $n = q('UPDATE users SET is_admin=1 WHERE email=?', [$email])->rowCount();
    echo $n ? "$email is now an admin.\n" : "No account with that email.\n";
    exit($n ? 0 : 1);
}
if ((int)col('SELECT COUNT(*) FROM users WHERE id=1')) { fwrite(STDERR, "Account #1 already exists (" . col('SELECT email FROM users WHERE id=1') . "). Nothing changed.\n"); exit(1); }
if (col('SELECT 1 FROM users WHERE email=?', [$email])) { fwrite(STDERR, "That email already has an account. Nothing changed.\n"); exit(1); }
$hash = (string)(col("SELECT value FROM settings WHERE key='password_hash'") ?: '');
if ($hash === '') { fwrite(STDERR, "This instance has no owner password yet. Set one first: php app/tools/set-password.php\n"); exit(1); }
$mode = (string)(col("SELECT value FROM settings WHERE key='mode'") ?: 'diy');
// Every owner record already belongs to user_id 1 (single-owner installs write user 1), so the account simply takes id 1.
q('INSERT INTO users (id, email, pw_hash, name, created_at, verified_at, is_admin, mode) VALUES (1, ?, ?, ?, ?, ?, 1, ?)', [$email, $hash, '', now(), now(), $mode]);
$counts = [];
foreach (['vehicle', 'customers', 'jobs', 'diag_reports'] as $t) $counts[] = $t . ' ' . (int)col("SELECT COUNT(*) FROM $t WHERE user_id=1");
echo "Admin account #1 created for $email (same password as before). Records: " . implode(', ', $counts) . ".\n";
echo "Now set define('MULTI_USER', true) in app/config.local.php.\n";
