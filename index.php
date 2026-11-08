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
const PUBLIC_PAGES = ['login', 'signup', 'forgot', 'reset', 'verify', 'record', 'manifest', 'unsub', 'lookup', 'yt', 'quote', 'book'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A body larger than post_max_size arrives with $_POST and $_FILES empty: explain it instead of "Bad token".
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes('post_max_size')) {
        flash('That upload was too large (over ' . ini_get('post_max_size') . 'B in one go), so nothing was saved. Upload fewer photos at a time.', 'err');
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        redirect($host !== '' && parse_url($ref, PHP_URL_HOST) === explode(':', $host)[0] ? $ref : url($authed ? 'dashboard' : 'login'));
    }
    $action = post('action');
    if (MULTI_USER && in_array($action, ['login', 'signup', 'forgot', 'reset', 'logout', 'guest'], true)) { require APP_ROOT . '/app/auth.php'; exit; }
    if ($action === 'login') {
        csrf_check();
        $ip = login_ip();
        if ($wait = login_locked_for($ip)) { flash('Too many wrong passwords. Try again in ' . max(1, (int)ceil($wait / 60)) . ' min.', 'err'); redirect(url('login')); }
        if ($pwHash !== '' && password_verify(post('password'), $pwHash)) { login_succeeded($ip); sign_in($pwHash); redirect(url('dashboard')); }
        login_failed($ip);
        flash(login_locked_for($ip) ? 'Too many wrong passwords. Try again in 15 min.' : 'Wrong password.', 'err'); redirect(url('login'));
    }
    if ($action === 'unsub') { require APP_ROOT . '/app/views/unsub.php'; exit; }
    // Customer approving an estimate from their link (the token is the authorization; no account).
    if (($_GET['p'] ?? '') === 'quote' && $action === '') { require APP_ROOT . '/app/views/quote.php'; exit; }
    // Online booking form (public, by the shop's booking link).
    if (($_GET['p'] ?? '') === 'book' && $action === '') { require APP_ROOT . '/app/views/book.php'; exit; }
    // VIN / plate scan from a photo or a barcode decoded on the phone (JSON). Signed in, or on a shop's booking page.
    if (($_GET['p'] ?? '') === 'scan_id') {
        csrf_check();
        header('Content-Type: application/json');
        $bk = (string)($_POST['book'] ?? '');
        $okPublic = !$authed && preg_match('/^[a-f0-9]{18}$/D', $bk) && col("SELECT 1 FROM shop_profile WHERE json_extract(data, '$.book_token')=? AND json_extract(data, '$.book_on')=1", [$bk]);
        if (!$authed && !$okPublic) { http_response_code(403); echo json_encode(['error' => 'Sign in first.']); exit; }
        session_write_close();
        echo json_encode(id_scan(post('kind') === 'plate' ? 'plate' : 'vin', $_FILES['photo'] ?? null, mb_substr(post('barcode'), 0, 200), $authed ? '' : 'ip:' . login_ip()), JSON_UNESCAPED_SLASHES);
        exit;
    }
    // Phone share sheet ("Share -> this app" from a scanner app): the installed web app receives the report here.
    if (($_GET['p'] ?? '') === 'share_in') {
        if (!$authed) redirect(url('login'));
        $text = trim(post('title') . "\n" . post('text') . "\n" . post('url')); $src = 'shared';
        if (!empty($_FILES['scan']['name'])) { $up = scan_upload_text($_FILES['scan']); if (!empty($up['error'])) flash($up['error'], 'err'); $text .= "\n" . $up['text']; $src = 'shared ' . ($up['via'] ?? 'file'); }
        $rid = insert('diag_reports', ['user_id' => uid(), 'vehicle_id' => vid() ?: null, 'codes' => json_encode(obd_extract_codes($text)), 'freeze' => mb_substr($text, 0, 60000), 'symptoms' => '[]', 'notes' => '', 'source' => $src, 'created_at' => now()]);
        redirect(url('diagnose', ['r' => $rid]));
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
if ($page === 'login' && MULTI_USER) guests_purge();
// Public code lookup and video search (hosted instances): no account needed, rate-limited per network.
if (in_array($page, ['lookup', 'yt'], true) && !$authed && !(MULTI_USER && PUBLIC_LOOKUP)) redirect(url('login'));
if ($page === 'lookup') { require APP_ROOT . '/app/views/lookup.php'; exit; }
if ($page === 'yt') {
    session_write_close();
    header('Content-Type: application/json');
    $ok = $authed ? rate_ok('yt:' . uid(), 80, 86400) : rate_ok('yt-ip:' . login_ip(), 30, 86400);
    $q = (string)($_GET['q'] ?? '');
    echo json_encode($ok ? youtube_search($q) : ['items' => [], 'fallback' => 'https://www.youtube.com/results?search_query=' . urlencode($q)], JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($page, ['signup', 'forgot', 'reset', 'record', 'unsub', 'quote', 'book'], true)) { require APP_ROOT . '/app/views/' . $page . '.php'; exit; }
// Web app manifest (install to the home screen), named after this instance.
if ($page === 'manifest') {
    session_write_close();
    header('Content-Type: application/manifest+json');
    header('Cache-Control: public, max-age=3600');
    echo json_encode(['name' => APP_NAME, 'short_name' => APP_NAME, 'id' => './', 'start_url' => './index.php?p=dashboard', 'scope' => './', 'display' => 'standalone',
        'background_color' => '#07080a', 'theme_color' => '#07080a', 'description' => 'Your vehicles: problems, service, mechanic visits and photos in one place.',
        'icons' => [['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'], ['src' => 'assets/apple-touch-icon.png', 'sizes' => '180x180', 'type' => 'image/png']],
        'share_target' => ['action' => './index.php?p=share_in', 'method' => 'POST', 'enctype' => 'multipart/form-data',
            'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url', 'files' => [['name' => 'scan', 'accept' => ['image/*', 'application/pdf', 'text/plain', 'text/csv', '.csv', '.pdf']]]]],
        'shortcuts' => [['name' => 'Diagnose a code', 'url' => './index.php?p=diagnose'], ['name' => 'Report a problem', 'url' => './index.php?p=issue_form'], ['name' => 'Update mileage', 'url' => './index.php?p=mileage'], ['name' => 'Maintenance', 'url' => './index.php?p=maintenance']]],
        JSON_UNESCAPED_SLASHES);
    exit;
}

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

// Weekly recall refresh for the vehicle on screen (called in the background by the dashboard).
if ($page === 'recalls_sync') {
    $rv = vid();
    session_write_close();
    header('Content-Type: application/json');
    echo json_encode(['new' => $rv ? recalls_refresh($rv) : 0]);
    exit;
}

// Parts catalog search for the repair-order "Find part" panel (JSON).
if ($page === 'parts_q') {
    session_write_close();
    header('Content-Type: application/json');
    $pq = trim((string)($_GET['q'] ?? ''));
    $res = $pq === '' ? [] : rows('SELECT id, part_no, name, brand, supplier, cost, price, qty, location FROM parts_catalog WHERE user_id=? AND (name LIKE ? OR part_no LIKE ? OR brand LIKE ?) ORDER BY qty > 0 DESC, name LIMIT 15', [uid(), "%$pq%", "%$pq%", "%$pq%"]);
    foreach ($res as &$r) { $r['sell'] = $r['price'] !== null ? (float)$r['price'] : matrix_price((float)$r['cost']); $r['sell_fmt'] = money($r['sell']); $r['cost_fmt'] = money((float)$r['cost']); } unset($r);
    echo json_encode(['items' => $res]);
    exit;
}

// Payroll export for a period (CSV; signed in).
if ($page === 'team_csv') {
    $_GET['period'] ??= 'week';
    $monday = strtotime('monday this week'); $period = (string)$_GET['period'];
    [$from, $to] = match ($period) { 'last' => [$monday - 7 * 86400, $monday], 'month' => [strtotime(date('Y-m-01')), strtotime(date('Y-m-01') . ' +1 month')],
        'lastmonth' => [strtotime(date('Y-m-01') . ' -1 month'), strtotime(date('Y-m-01'))], 'custom' => [strtotime((string)($_GET['from'] ?? '')) ?: $monday, (strtotime((string)($_GET['to'] ?? '')) ?: time()) + 86400], default => [$monday, $monday + 7 * 86400] };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll-' . date('Ymd', $from) . '-' . date('Ymd', $to - 86400) . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Technician', 'Pay type', 'Rate', 'Shift hours', 'Hours on jobs', 'Flagged hours', 'Efficiency %', 'Productivity %', 'Labor sold', 'Gross pay (est.)', 'From', 'To'], ',', '"', '');
    foreach (time_report($from, $to) as $r) fputcsv($o, [$r['tech']['name'], $r['tech']['pay_type'], $r['tech']['pay_rate'], round($r['shift'], 2), round($r['job'], 2), round($r['flagged'], 2),
        $r['eff'] === null ? '' : round($r['eff']), $r['prod'] === null ? '' : round($r['prod']), round($r['sold'], 2), $r['pay'], date('Y-m-d', $from), date('Y-m-d', $to - 86400)], ',', '"', '');
    exit;
}

// VIN lookup for the Add-vehicle form (JSON; signed-in only).
if ($page === 'vin') {
    session_write_close();
    header('Content-Type: application/json');
    echo json_encode(vin_decode_world((string)($_GET['vin'] ?? '')), JSON_UNESCAPED_SLASHES);
    exit;
}

$pages = ['dashboard','issues','issue','issue_form','codes','common','maintenance','inspections','inspection_new','inspection',
          'visits','visit','visit_form','parts','photos','vehicle','brief','mileage','links','login','export','specs','manual','garage','recalls','share','diagnose','customers','tools','jobs','job','shop','job_print','calendar','clock','team','purchasing','po','po_print','admin'];
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
if (!vid() && is_pro() && $page === 'dashboard') redirect(url('jobs'));
if (!vid() && !in_array($page, ['garage', 'login', 'export', 'links', 'diagnose', 'customers', 'tools', 'jobs', 'job', 'shop', 'job_print', 'calendar', 'clock', 'team', 'purchasing', 'po', 'po_print', 'admin'], true)) redirect(url('garage') . '#add');
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
if (in_array($page, ['brief', 'login', 'job_print', 'po_print'], true)) { echo $content; exit; }
require APP_ROOT . '/app/views/layout.php';
