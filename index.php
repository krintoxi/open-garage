<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

// Password gate. The hash lives in settings.password_hash. With no hash the app is only usable from this
// machine itself (local dev); from anywhere else it fails closed and shows a "locked" page, so a fresh or
// reset database never comes up open to the internet. Set the password with app/tools/set-password.php.
$pwHash = (string)(col("SELECT value FROM settings WHERE key='password_hash'") ?: '');
if (!MULTI_USER && $pwHash === '' && !is_local_request()) {
    http_response_code(503);
    header('Retry-After: 3600');
    require APP_ROOT . '/app/views/locked.php';
    exit;
}
$authed = MULTI_USER ? (uid() > 0 && current_user() !== null) : ($pwHash === '' || hash_equals(auth_tag($pwHash), (string)($_SESSION['authed'] ?? '')));
const PUBLIC_PAGES = ['login', 'signup', 'forgot', 'reset', 'verify'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A body larger than post_max_size arrives with $_POST and $_FILES empty: explain it instead of "Bad token".
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes('post_max_size')) {
        flash('That upload was too large (over ' . ini_get('post_max_size') . 'B in one go), so nothing was saved. Upload fewer photos at a time.', 'err');
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        redirect($host !== '' && parse_url($ref, PHP_URL_HOST) === explode(':', $host)[0] ? $ref : url($authed ? 'dashboard' : 'login'));
    }
    $action = post('action');
    if (MULTI_USER && in_array($action, ['login', 'signup', 'forgot', 'reset', 'logout'], true)) { require APP_ROOT . '/app/auth.php'; exit; }
    if ($action === 'login') {
        csrf_check();
        $ip = login_ip();
        if ($wait = login_locked_for($ip)) { flash('Too many wrong passwords. Try again in ' . max(1, (int)ceil($wait / 60)) . ' min.', 'err'); redirect(url('login')); }
        if ($pwHash !== '' && password_verify(post('password'), $pwHash)) { login_succeeded($ip); sign_in($pwHash); redirect(url('dashboard')); }
        login_failed($ip);
        flash(login_locked_for($ip) ? 'Too many wrong passwords. Try again in 15 min.' : 'Wrong password.', 'err'); redirect(url('login'));
    }
    if (!$authed) redirect(url('login'));
    require __DIR__ . '/app/actions.php';
    exit;
}

$page = preg_replace('/[^a-z_]/', '', $_GET['p'] ?? 'dashboard') ?: 'dashboard';
if (!MULTI_USER && in_array($page, ['signup', 'forgot', 'reset', 'verify'], true)) $page = 'login';
if (!$authed && !in_array($page, PUBLIC_PAGES, true)) redirect(url('login'));
if ($authed && in_array($page, ['login', 'signup', 'forgot'], true)) redirect(url('dashboard'));
if (MULTI_USER && $page === 'verify') { require APP_ROOT . '/app/auth.php'; exit; }
if (in_array($page, ['signup', 'forgot', 'reset'], true)) { require APP_ROOT . '/app/views/' . $page . '.php'; exit; }

// Photos and receipts: streamed from UPLOAD_DIR only to a signed-in browser (never served as static files).
if ($page === 'file') {
    $name = basename((string)($_GET['f'] ?? ''));
    $base = realpath(UPLOAD_DIR);
    // Stored names are plain (letters, digits, . _ -); anything else (including NUL bytes, which make realpath() throw) is a 404.
    $path = ($base && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/D', $name)) ? realpath($base . DIRECTORY_SEPARATOR . $name) : false;
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf'];
    $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
    $types += ['png' => 'image/png', 'glb' => 'model/gltf-binary'];
    if (!$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path) || !isset($types[$ext])) { http_response_code(404); exit('Not found'); }
    // Only the owner's files: a gallery photo of one of their vehicles, or one of the vehicle's showroom images.
    $stored = UPLOAD_URL . '/' . $name;
    $mine = col('SELECT 1 FROM photos p JOIN vehicle v ON v.id=p.vehicle_id WHERE p.path=? AND v.user_id=?', [$stored, uid()])
        || col('SELECT 1 FROM vehicle WHERE user_id=? AND ? IN (photo, hero_cutout, hero_depth, mesh_glb)', [uid(), $stored]);
    if (!$mine) { http_response_code(404); exit('Not found'); }
    session_write_close();
    header_remove('Pragma'); header_remove('Expires');
    $mtime = (int)filemtime($path);
    header('Cache-Control: private, max-age=86400');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');
    $ims = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($ims && $ims >= $mtime) { http_response_code(304); exit; }
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $name . '"');
    readfile($path);
    exit;
}

// Year / make / model lists for the Add-vehicle wizard (JSON; signed-in only; cached).
if ($page === 'vpic') {
    session_write_close();
    header('Content-Type: application/json');
    echo json_encode(vpic_list((string)($_GET['what'] ?? ''), (string)($_GET['kind'] ?? 'car'), (string)($_GET['make'] ?? ''), (int)($_GET['year'] ?? 0)), JSON_UNESCAPED_SLASHES);
    exit;
}
// Showroom image / 3D job status for the dashboard (JSON).
if ($page === 'vision') {
    header('Content-Type: application/json');
    echo json_encode(vision_status_for(vid()), JSON_UNESCAPED_SLASHES);
    exit;
}

// VIN lookup for the Add-vehicle form (JSON; signed-in only).
if ($page === 'vin') {
    session_write_close();
    header('Content-Type: application/json');
    echo json_encode(vin_decode((string)($_GET['vin'] ?? '')), JSON_UNESCAPED_SLASHES);
    exit;
}

$pages = ['dashboard','issues','issue','issue_form','codes','common','maintenance','inspections','inspection_new','inspection',
          'visits','visit','visit_form','parts','photos','vehicle','brief','mileage','links','login','export','specs','manual','garage'];
if (!in_array($page, $pages, true)) { http_response_code(404); $page = 'dashboard'; }

if ($page === 'export' && MULTI_USER) {
    // Shared database: export only this account's records, as JSON.
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="garage-' . date('Ymd') . '.json"');
    echo json_encode(account_export(uid()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if ($page === 'export') {
    $f = DATA_DIR . '/garage.sqlite';
    db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="garage-' . date('Ymd') . '.sqlite"');
    readfile($f); exit;
}

// A page about one record (an issue, a visit...) shows that record's vehicle, even if another vehicle was selected.
// No vehicle yet (fresh install): everything starts at the Garage page's "Add a vehicle" form.
if (!vid() && !in_array($page, ['garage', 'login', 'export', 'links'], true)) redirect(url('garage') . '#add');
$follow = ['issue' => 'issues', 'issue_form' => 'issues', 'visit' => 'visits', 'visit_form' => 'visits', 'inspection' => 'inspections', 'brief' => 'visits'];
if (isset($follow[$page]) && ($rid = (int)($_GET['id'] ?? 0))) {
    if (!owns($follow[$page], $rid)) { http_response_code(404); $page = 'garage'; }
    else vid((int)col("SELECT vehicle_id FROM {$follow[$page]} WHERE id=?", [$rid]));
}
$veh = vehicle();
$miles = current_miles();
$title = 'Garage';
$use3d = false;
ob_start();
require APP_ROOT . '/app/views/' . $page . '.php';
$content = ob_get_clean();
if ($page === 'brief' || $page === 'login') { echo $content; exit; }
require APP_ROOT . '/app/views/layout.php';
