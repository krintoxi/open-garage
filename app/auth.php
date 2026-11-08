<?php
declare(strict_types=1);
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
/** Multi-user accounts: sign up, sign in, sign out, password reset, email verification. */

function mu_sign_in(array $u): void {
    session_regenerate_id(true);
    unset($_SESSION['csrf'], $_SESSION['vid']);
    $_SESSION['uid'] = (int)$u['id'];
    q('UPDATE users SET last_login=? WHERE id=?', [now(), $u['id']]);
}
function mail_verify(array $u): bool {
    $tok = bin2hex(random_bytes(20));
    q('UPDATE users SET verify_token=? WHERE id=?', [hash('sha256', $tok), $u['id']]);
    $link = base_url() . '/' . url('verify', ['t' => $tok]);
    return send_mail($u['email'], 'Confirm your ' . APP_NAME . ' account',
        '<p>Welcome to ' . h(APP_NAME) . '.</p><p><a href="' . h($link) . '">Confirm your email</a> so you can reset your password if you ever forget it.</p><p>If you did not sign up, ignore this email.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ?p=verify&t=<token>
    $tok = (string)($_GET['t'] ?? '');
    $u = $tok !== '' ? row('SELECT * FROM users WHERE verify_token=?', [hash('sha256', $tok)]) : null;
    if ($u) { q('UPDATE users SET verified_at=?, verify_token=NULL WHERE id=?', [now(), $u['id']]); flash('Email confirmed. Thanks!'); }
    else flash('That confirmation link is not valid any more.', 'err');
    redirect(url(uid() ? 'dashboard' : 'login'));
}

csrf_check();
$action = post('action');
$ip = login_ip();

switch ($action) {
case 'logout':
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Signed out.'); redirect(url('login'));

case 'login':
    if ($wait = login_locked_for($ip)) { flash('Too many wrong passwords. Try again in ' . max(1, (int)ceil($wait / 60)) . ' min.', 'err'); redirect(url('login')); }
    $u = row('SELECT * FROM users WHERE email=?', [mb_strtolower(post('email'))]);
    // Same work and message whether or not the account exists.
    $ok = password_verify(post('password'), $u['pw_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuDFAKEHASHxxxxxxxxxxxxxxxxxxxxxx');
    if ($u && $ok) {
        login_succeeded($ip);
        if (password_needs_rehash($u['pw_hash'], PASSWORD_DEFAULT)) q('UPDATE users SET pw_hash=? WHERE id=?', [password_hash(post('password'), PASSWORD_DEFAULT), $u['id']]);
        mu_sign_in($u); redirect(url('dashboard'));
    }
    login_failed($ip);
    flash(login_locked_for($ip) ? 'Too many wrong passwords. Try again in 15 min.' : 'Wrong email or password.', 'err'); redirect(url('login'));

case 'signup':
    if (!SIGNUP_OPEN) { flash('Sign-ups are closed right now.', 'err'); redirect(url('login')); }
    if (post('website') !== '') redirect(url('login')); // honeypot: bots fill every field
    if ((int)post('t0') > 0 && time() - (int)post('t0') < 3) redirect(url('signup')); // submitted faster than a human can type
    $email = mb_strtolower(post('email')); $pw = post('password');
    $back = fn(string $m) => [flash($m, 'err'), redirect(url('signup'))];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) $back('Enter a valid email address.');
    if (mb_strlen($pw) < 8) $back('Use a password of at least 8 characters.');
    if (!rate_ok('signup:' . $ip, 4, 3600)) $back('Too many sign-ups from this network. Try again in an hour.');
    if (col('SELECT 1 FROM users WHERE email=?', [$email])) $back('There is already an account with that email. Sign in, or reset the password.');
    $first = !(int)col('SELECT COUNT(*) FROM users');
    $id = insert('users', ['email' => $email, 'pw_hash' => password_hash($pw, PASSWORD_DEFAULT), 'name' => post('name'), 'created_at' => now(), 'is_admin' => $first ? 1 : 0, 'remind_weekly' => post('remind') === '1' && reminders_available() ? 1 : 0]);
    $u = row('SELECT * FROM users WHERE id=?', [$id]);
    mu_sign_in($u);
    $sent = mail_verify($u);
    flash('Welcome! Let\'s add your first vehicle.' . ($sent ? ' We also sent you an email to confirm your address.' : ''));
    redirect(url('garage') . '#add');

case 'forgot':
    $email = mb_strtolower(post('email'));
    if (rate_ok('forgot:' . $ip, 5, 3600) && ($u = row('SELECT * FROM users WHERE email=?', [$email]))) {
        $tok = bin2hex(random_bytes(24));
        q('UPDATE users SET reset_token=?, reset_expires=? WHERE id=?', [hash('sha256', $tok), time() + 3600, $u['id']]);
        $link = base_url() . '/' . url('reset', ['t' => $tok]);
        send_mail($u['email'], 'Reset your ' . APP_NAME . ' password', '<p>Someone asked to reset the password for this email.</p><p><a href="' . h($link) . '">Choose a new password</a> (the link works for one hour).</p><p>If it was not you, ignore this email: nothing changes.</p>');
    }
    flash('If that email has an account, a reset link is on its way. Check spam too.');
    redirect(url('login'));

case 'reset':
    $tok = post('t');
    $u = $tok !== '' ? row('SELECT * FROM users WHERE reset_token=? AND reset_expires>?', [hash('sha256', $tok), time()]) : null;
    if (!$u) { flash('That reset link has expired. Ask for a new one.', 'err'); redirect(url('forgot')); }
    if (mb_strlen(post('password')) < 8) { flash('Use at least 8 characters.', 'err'); redirect(url('reset', ['t' => $tok])); }
    // Resetting also proves the email works.
    q('UPDATE users SET pw_hash=?, reset_token=NULL, reset_expires=NULL, verified_at=COALESCE(verified_at, ?) WHERE id=?', [password_hash(post('password'), PASSWORD_DEFAULT), now(), $u['id']]);
    mu_sign_in($u);
    flash('Password changed. You are signed in.'); redirect(url('dashboard'));
}
http_response_code(400); echo 'Unknown action';
