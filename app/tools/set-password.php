<?php
// Set (or reset) the Garage password from the server console. Command line only.
//   cd <site folder> && php app/tools/set-password.php
// It asks for the password without showing it. (Passing it as an argument also works, but leaves it in shell history and `ps`.)
// Run it as the same user that runs the web server so the database files keep the right owner.
// Leading/trailing spaces are ignored, as on the login form. Minimum 8 characters.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }
$pw = trim((string)($argv[1] ?? ''));
if ($pw === '') {
    $tty = function_exists('stream_isatty') && stream_isatty(STDIN) && function_exists('shell_exec');
    fwrite(STDOUT, 'New password: ');
    if ($tty) @shell_exec('stty -echo 2>/dev/null');
    $pw = trim((string)fgets(STDIN));
    if ($tty) { @shell_exec('stty echo 2>/dev/null'); fwrite(STDOUT, "\n"); }
}
if (mb_strlen($pw) < 8) { fwrite(STDERR, "Password must be at least 8 characters. Nothing changed.\n"); exit(1); }
require dirname(__DIR__) . '/bootstrap.php';
q("INSERT OR REPLACE INTO settings (key,value) VALUES ('password_hash',?)", [password_hash($pw, PASSWORD_DEFAULT)]);
q('DELETE FROM login_attempts');
echo 'Password set for the database in ' . DATA_DIR . ". Every existing browser session is signed out.\n";
