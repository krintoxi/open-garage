<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); exit('Forbidden'); }

/** True when the visitor reached us over HTTPS (directly or via a reverse proxy / Cloudflare). */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return str_contains(strtolower((string)($_SERVER['HTTP_CF_VISITOR'] ?? '')), '"https"');
}
/** A request made on this machine itself (not through Cloudflare / a proxy). */
function is_local_request(): bool {
    if (PHP_SAPI === 'cli') return true;
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) return false;
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_CF_RAY'] as $hdr) {
        if (!empty($_SERVER[$hdr])) return false;
    }
    return true;
}
/** Value stored in the session at login; tied to the current hash so a password change logs out every other session. */
function auth_tag(string $pwHash): string { return hash('sha256', 'garage-auth|' . $pwHash); }

if (PHP_SAPI !== 'cli') {
    // Session hardening: never adopt a client-chosen id, cookie not readable by JS, 30-day cookie to match gc_maxlifetime.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '2592000');
    // Over HTTPS use a __Host- cookie: browsers refuse it unless Secure, Path=/ and host-only,
    // so a sibling site on the same parent domain can't plant or overwrite it.
    if (is_https()) session_name('__Host-garage');
    session_set_cookie_params(['lifetime' => 2592000, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
date_default_timezone_set('America/New_York');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));
// Optional host-specific overrides (not synced): may define DATA_DIR / UPLOAD_DIR.
$cfgFile = (string)(getenv('GARAGE_CONFIG') ?: APP_ROOT . '/app/config.local.php');
if (is_file($cfgFile)) require $cfgFile;
// Instance settings (override in app/config.local.php).
foreach ([
    'MULTI_USER' => false,         // false: one owner, one password (settings table). true: email sign-up, each account sees only its own vehicles.
    'APP_NAME' => 'Garage',
    'SIGNUP_OPEN' => true,         // multi-user: allow new accounts
    'BASE_URL' => '',              // e.g. https://garage.example.com (links in emails)
    'MAIL_KEY' => '',              // Resend API key for verification / reset emails
    'MAIL_FROM' => '',             // e.g. Garage <hello@example.com>
    'VISION_URL' => '',            // garage-vision service (cut-out, depth, 3D); empty = off
    'VISION_TOKEN' => '',
    'VISION_MESH' => false,        // experimental single-photo 3D (needs a GPU host with several GB free)
    'LIMIT_VEHICLES' => 10,        // per account (multi-user)
    'LIMIT_PHOTOS' => 400,
    'LIMIT_VISION_PER_DAY' => 25,
] as $k => $v) if (!defined($k)) define($k, $v);
if (!defined('DATA_DIR')) define('DATA_DIR', APP_ROOT . '/data');
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL', 'uploads');
require APP_ROOT . '/app/vehicles.php';
require APP_ROOT . '/app/consumer.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    protect_dir(DATA_DIR);
    $fresh = !file_exists(DATA_DIR . '/garage.sqlite');
    $pdo = new PDO('sqlite:' . DATA_DIR . '/garage.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec(file_get_contents(APP_ROOT . '/app/schema.sql'));
    // Login throttle state (added after first deploy; IF NOT EXISTS so it also applies to the live DB).
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT PRIMARY KEY, fails INT NOT NULL DEFAULT 0, first_at INT NOT NULL DEFAULT 0, locked_until INT NOT NULL DEFAULT 0)');
    migrate_multi_vehicle($pdo);
    migrate_accounts($pdo);
    migrate_consumer($pdo);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM vehicle')->fetchColumn();
    if ($count === 0 && !$pdo->query("SELECT 1 FROM settings WHERE key='seeded'")->fetchColumn()) {
        $pdo->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('seeded', datetime('now'))");
        require APP_ROOT . '/app/seed/seed.php';
        seed_all($pdo);
    }
    // maintenance.due_date: an explicit "next due" date (e.g. read off the dash), cleared when the item is marked done.
    // Added after first deploy; guarded so it applies once to the live DB too.
    $mcols = array_column($pdo->query('PRAGMA table_info(maintenance)')->fetchAll(), 'name');
    if (!in_array('due_date', $mcols, true)) {
        $pdo->exec('ALTER TABLE maintenance ADD COLUMN due_date TEXT');
        // One-time: the Service B date the dash showed on 2026-10-07 ("due in 30 days"), only if it was never done.
        $pdo->exec("UPDATE maintenance SET due_date='2026-11-06' WHERE item LIKE 'Service B%' AND last_date IS NULL AND last_miles IS NULL");
    }
    // One-time text/data corrections (2026-10-12) for databases seeded before them, the live one included.
    // A settings flag makes it run once; on a fresh seed it matches nothing.
    if (is_file(APP_ROOT . '/app/seed/data_fixes.php') && !$pdo->query("SELECT 1 FROM settings WHERE key='fix_2026_10_12'")->fetchColumn()) {
        require_once APP_ROOT . '/app/seed/data_fixes.php';
        data_fixes_2026_10_12($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('fix_2026_10_12', ?)")->execute([date('Y-m-d H:i:s')]);
    }
    return $pdo;
}

/** Tables whose rows belong to one vehicle (vehicle_id). Child tables (issue_notes, inspection_results, visit_issues) follow their parent. */
const SCOPED = ['mileage_log','issues','code_events','maintenance','checks','inspections','visits','photos','parts','specs','common_issues','manual_pages','manual_facts','recalls','shares'];

/**
 * Multi-vehicle support (2026-11-07). Adds vehicle_id to every per-vehicle table (existing rows go to the first vehicle)
 * and the extra vehicle columns. Guarded per column, so it runs once on the live DB and is a no-op afterwards.
 */
function migrate_multi_vehicle(PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    $vc = $cols('vehicle');
    $add = ['kind' => "TEXT DEFAULT 'car'", 'nickname' => 'TEXT', 'model3d' => 'TEXT', 'photo_credit' => 'TEXT', 'sort' => 'INT DEFAULT 0'];
    $first = !in_array('model3d', $vc, true);
    foreach ($add as $c => $type) if (!in_array($c, $vc, true)) $pdo->exec("ALTER TABLE vehicle ADD COLUMN $c $type");
    // The original vehicle is the W204 that has the 3D model.
    if ($first) $pdo->exec("UPDATE vehicle SET model3d='car.glb', kind='car' WHERE id=(SELECT MIN(id) FROM vehicle) AND make LIKE 'Mercedes%'");
    foreach (SCOPED as $t) {
        if (in_array('vehicle_id', $cols($t), true)) continue;
        $pdo->exec("ALTER TABLE $t ADD COLUMN vehicle_id INTEGER NOT NULL DEFAULT 1");
        $pdo->exec("UPDATE $t SET vehicle_id=(SELECT MIN(id) FROM vehicle) WHERE EXISTS (SELECT 1 FROM vehicle)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$t}_vehicle ON $t (vehicle_id)");
    }
}

/** Deny direct web access to a folder on Apache (the database and photos must only go through index.php). */
function protect_dir(string $dir): void {
    $f = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($f)) @file_put_contents($f, "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\nOptions -Indexes\n");
}

/**
 * Accounts and showroom imagery (2026-11-08). users: multi-user instances only. vehicle/mechanics/links get user_id
 * (existing rows belong to user 1, the single owner). Vehicle gets the cut-out / depth / 3D outputs of garage-vision.
 */
function migrate_accounts(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, email TEXT UNIQUE COLLATE NOCASE, pw_hash TEXT, name TEXT, created_at TEXT, last_login TEXT,
        verified_at TEXT, verify_token TEXT, reset_token TEXT, reset_expires INT, is_admin INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, n INT NOT NULL DEFAULT 0, window_start INT NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS vpic_cache (key TEXT PRIMARY KEY, body TEXT, fetched_at INT)');
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    foreach (['vehicle', 'mechanics', 'links'] as $t) if (!in_array('user_id', $cols($t), true)) {
        $pdo->exec("ALTER TABLE $t ADD COLUMN user_id INTEGER NOT NULL DEFAULT 1");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$t}_user ON $t (user_id)");
    }
    $vc = $cols('vehicle');
    foreach (['hero_cutout' => 'TEXT', 'hero_depth' => 'TEXT', 'vision_status' => 'TEXT', 'mesh_glb' => 'TEXT', 'mesh_job' => 'TEXT', 'mesh_status' => 'TEXT'] as $c => $t)
        if (!in_array($c, $vc, true)) $pdo->exec("ALTER TABLE vehicle ADD COLUMN $c $t");
}

/** The signed-in account. Single-owner instances are always user 1. */
function uid(): int { return MULTI_USER ? (int)($_SESSION['uid'] ?? 0) : 1; }
function current_user(): ?array { return MULTI_USER && uid() ? row('SELECT * FROM users WHERE id=?', [uid()]) : null; }
function is_admin(): bool { return !MULTI_USER || (bool)(current_user()['is_admin'] ?? false); }
function owns_vehicle(int $vehicleId): bool { return $vehicleId > 0 && (bool)col('SELECT 1 FROM vehicle WHERE id=? AND user_id=?', [$vehicleId, uid()]); }
/** Does the signed-in account own row $id of $table? */
function owns(string $table, int $id): bool {
    if ($id <= 0) return false;
    return match (true) {
        $table === 'issue_notes' => (bool)col('SELECT 1 FROM issue_notes n JOIN issues i ON i.id=n.issue_id JOIN vehicle v ON v.id=i.vehicle_id WHERE n.id=? AND v.user_id=?', [$id, uid()]),
        in_array($table, ['mechanics', 'links'], true) => (bool)col("SELECT 1 FROM $table WHERE id=? AND user_id=?", [$id, uid()]),
        in_array($table, SCOPED, true) => (bool)col("SELECT 1 FROM $table t JOIN vehicle v ON v.id=t.vehicle_id WHERE t.id=? AND v.user_id=?", [$id, uid()]),
        default => false,
    };
}
/** Keep only the ids (from a form) that belong to the current vehicle. */
function own_ids(string $table, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return array_values(array_filter($ids, fn($i) => (bool)col("SELECT 1 FROM $table WHERE id=? AND " . vw(), [$i])));
}

/** Simple fixed-window counter: true while under $max events per $window seconds for $key. */
function rate_ok(string $key, int $max, int $window, bool $count = true): bool {
    $t = time(); $r = row('SELECT * FROM rate_limits WHERE key=?', [$key]);
    if (!$r || (int)$r['window_start'] < $t - $window) { $n = 0; $start = $t; } else { $n = (int)$r['n']; $start = (int)$r['window_start']; }
    if ($n >= $max) return false;
    if ($count) q('INSERT OR REPLACE INTO rate_limits (key, n, window_start) VALUES (?,?,?)', [$key, $n + 1, $start]);
    return true;
}

/** Send an email through Resend. Returns false (never throws) when mail is not configured or fails. */
function send_mail(string $to, string $subject, string $html): bool {
    if (MAIL_KEY === '' || MAIL_FROM === '' || !function_exists('curl_init')) return false;
    $c = curl_init('https://api.resend.com/emails');
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . MAIL_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['from' => MAIL_FROM, 'to' => [$to], 'subject' => $subject, 'html' => $html])]);
    $ok = curl_exec($c) !== false && curl_getinfo($c, CURLINFO_RESPONSE_CODE) < 300;
    return $ok;
}
function base_url(): string {
    if (BASE_URL !== '') return rtrim(BASE_URL, '/');
    return (is_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function row(string $sql, array $params = []): ?array {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function col(string $sql, array $params = []) { return q($sql, $params)->fetchColumn(); }
function insert(string $table, array $data): int {
    if (in_array($table, SCOPED, true) && !array_key_exists('vehicle_id', $data)) $data['vehicle_id'] = vid() ?: 1;
    if (in_array($table, ['vehicle', 'mechanics', 'links'], true) && !array_key_exists('user_id', $data)) $data['user_id'] = uid() ?: 1;
    $cols = implode(',', array_keys($data));
    $ph = implode(',', array_fill(0, count($data), '?'));
    q("INSERT INTO $table ($cols) VALUES ($ph)", array_values($data));
    return (int)db()->lastInsertId();
}
function update(string $table, array $data, int $id): void {
    $set = implode(',', array_map(fn($k) => "$k=?", array_keys($data)));
    q("UPDATE $table SET $set WHERE id=?", [...array_values($data), $id]);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function url(string $p, array $qs = []): string {
    $qs = ['p' => $p] + $qs;
    return 'index.php?' . http_build_query($qs);
}
function redirect(string $to): never {
    // Only ever redirect within this site (a form's "back" field must not send people elsewhere).
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|\\\\)~i', $to)) {
        $host = parse_url($to, PHP_URL_HOST);
        if (!$host || strcasecmp($host, explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]) !== 0) $to = 'index.php';
    }
    header('Location: ' . $to); exit;
}
function flash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if (!hash_equals(csrf(), $_POST['_csrf'] ?? '')) { http_response_code(400); exit('Bad token'); }
}
function post(string $k, $default = ''): string { return trim((string)($_POST[$k] ?? $default)); }
function post_int(string $k): ?int { $v = post($k); return $v === '' ? null : (int)preg_replace('/[^\d-]/', '', $v); }
function post_float(string $k): ?float { $v = post($k); return $v === '' ? null : (float)preg_replace('/[^\d.\-]/', '', $v); }
function today(): string { return date('Y-m-d'); }
function now(): string { return date('Y-m-d H:i:s'); }

/**
 * The vehicle being viewed. Chosen by ?v=<id> (remembered in the session), else the session, else the first vehicle.
 * vid($id) switches to another vehicle (pages for a specific issue/visit follow that record's vehicle).
 */
function vid(?int $set = null): int {
    static $cur = null;
    if ($set !== null) {
        $cur = $set;
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['vid'] = $set;
        return $set;
    }
    if ($cur !== null) return $cur;
    $want = (int)($_GET['v'] ?? ($_SESSION['vid'] ?? 0));
    if ($want > 0 && owns_vehicle($want)) return vid($want);
    $firstId = (int)col('SELECT id FROM vehicle WHERE user_id=? ORDER BY sort, id LIMIT 1', [uid()]);
    return $firstId ? vid($firstId) : 0;
}
/** SQL condition for "belongs to the current vehicle", e.g. "WHERE " . vw('i') . " AND ...". */
function vw(string $alias = ''): string { return ($alias !== '' ? "$alias." : '') . 'vehicle_id=' . vid(); }
function vehicle(): array { return row('SELECT * FROM vehicle WHERE id=?', [vid()]) ?? []; }
function vehicles(): array { return rows('SELECT * FROM vehicle WHERE user_id=? ORDER BY sort, id', [uid()]); }
function vehicle_name(array $v): string { return trim(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? '')); }
const VEHICLE_KINDS = ['car' => 'Car', 'suv' => 'SUV / truck', 'scooter' => 'Scooter / moped', 'motorcycle' => 'Motorcycle'];
/** Two-wheelers have no OBD-II port and use the simpler checklists. */
function is_two_wheeler(?array $v = null): bool { return in_array(($v ?? vehicle())['kind'] ?? 'car', ['scooter', 'motorcycle'], true); }
function current_miles(): int { return (int)(vehicle()['mileage'] ?? 0); }
function fmt_miles(?int $m): string { return $m === null ? '—' : number_format($m) . ' mi'; }
function fmt_date(?string $d): string {
    if (!$d) return '—';
    $t = strtotime($d); return $t ? date('M j, Y', $t) : $d;
}
function fmt_money(?float $v): string { return $v === null ? '—' : '$' . number_format($v, 2); }
function days_until(?string $d): ?int {
    if (!$d) return null; $t = strtotime($d); if (!$t) return null;
    // Calendar-day difference (not seconds / 86400), so a DST change inside the span can't drop a day.
    $diff = (new DateTimeImmutable(today()))->diff(new DateTimeImmutable(date('Y-m-d', $t)));
    return $diff->invert ? -(int)$diff->days : (int)$diff->days;
}
function nl2p(?string $s): string {
    $s = trim((string)$s); if ($s === '') return '';
    $parts = preg_split('/\n{2,}/', $s);
    $out = '';
    foreach ($parts as $p) {
        $lines = explode("\n", trim($p));
        $isList = count($lines) > 0 && count(array_filter($lines, fn($l) => preg_match('/^\s*[-•*]\s+/', $l))) === count($lines);
        if ($isList) {
            $out .= '<ul>' . implode('', array_map(fn($l) => '<li>' . linkify(h(preg_replace('/^\s*[-•*]\s+/', '', $l))) . '</li>', $lines)) . '</ul>';
        } else {
            $out .= '<p>' . nl2br(linkify(h($p))) . '</p>';
        }
    }
    return $out;
}
function linkify(string $escaped): string {
    return preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $escaped);
}

const SEVERITY = [1 => 'Minor', 2 => 'Low', 3 => 'Moderate', 4 => 'High', 5 => 'Critical'];
function sev_badge(?int $s): string {
    $s = max(1, min(5, (int)$s));
    return '<span class="sev sev-' . $s . '" title="Severity ' . $s . '/5">' . $s . ' · ' . SEVERITY[$s] . '</span>';
}
function status_badge(string $s): string {
    $cls = preg_replace('/[^a-z]/', '', strtolower($s));
    return '<span class="st st-' . $cls . '">' . h($s) . '</span>';
}

const LOCATIONS = [
    'engine' => 'Engine bay', 'intake' => 'Intake / top of engine', 'transmission' => 'Transmission',
    'driveline' => 'Driveshaft / diff', 'exhaust' => 'Exhaust / catalysts', 'evap' => 'EVAP / fuel tank',
    'cooling' => 'Cooling system', 'fan' => 'Fan / A/C condenser', 'ac' => 'A/C system',
    'electrical' => 'Electrical / modules', 'cabin' => 'Cabin / keys / security', 'doors' => 'Doors / windows',
    'suspension_front' => 'Front suspension', 'suspension_rear' => 'Rear suspension', 'brakes' => 'Brakes',
    'wheels' => 'Wheels / tires', 'body' => 'Body / exterior', 'other' => 'Other',
];
const SYSTEMS = ['Engine','Transmission','Driveline','Exhaust','Emissions / EVAP','Cooling','A/C & HVAC','Electrical','Security / Keys','Body / Doors','Suspension','Brakes','Wheels / Tires','Fluids','Other'];
const ISSUE_STATUSES = ['open','monitoring','scheduled','resolved','dismissed'];

function maintenance_status(array $m, int $miles): array {
    // returns [state, due_miles, due_date, label]
    $dueM = ($m['interval_miles'] && $m['last_miles'] !== null) ? (int)$m['last_miles'] + (int)$m['interval_miles'] : null;
    $dueD = ($m['interval_months'] && $m['last_date']) ? date('Y-m-d', strtotime($m['last_date'] . ' +' . (int)$m['interval_months'] . ' months')) : null;
    // An explicit next-due date (e.g. from the dash service reminder) counts too; the earlier date wins.
    if (!empty($m['due_date'])) $dueD = $dueD !== null ? min($dueD, (string)$m['due_date']) : (string)$m['due_date'];
    // Done on a known date but at an unknown mileage: the mileage interval can't be checked, so never call it "ok".
    $needMiles = $m['interval_miles'] && $m['last_miles'] === null && $m['last_date'];
    // "On condition" items (no interval, no due date) never count down: show when they were last done instead.
    if (!$m['interval_miles'] && !$m['interval_months'] && empty($m['due_date'])) {
        if ($m['last_date'] || $m['last_miles'] !== null) {
            $when = array_filter([$m['last_date'] ? fmt_date($m['last_date']) : '', $m['last_miles'] !== null ? number_format((int)$m['last_miles']) . ' mi' : '']);
            return ['ok', null, null, 'On condition · last done ' . implode(', ', $when)];
        }
        return ['unknown', null, null, 'On condition · nothing logged yet'];
    }
    if ($dueM === null && $dueD === null) return ['unknown', null, null, $needMiles ? 'Set the mileage it was last done at' : 'Never recorded: set when it was last done'];
    $state = 'ok'; $label = [];
    if ($dueM !== null) {
        $left = $dueM - $miles;
        if ($left < 0) { $state = 'overdue'; $label[] = number_format(-$left) . ' mi overdue'; }
        elseif ($left <= 500) { $state = $state === 'overdue' ? $state : 'soon'; $label[] = number_format($left) . ' mi left'; }
        else $label[] = number_format($left) . ' mi left';
    }
    if ($dueD !== null) {
        $d = days_until($dueD);
        if ($d < 0) { $state = 'overdue'; $label[] = (-$d) . ' days overdue'; }
        elseif ($d <= 30) { if ($state !== 'overdue') $state = 'soon'; $label[] = $d === 0 ? 'due today' : ($d === 1 ? 'due tomorrow' : $d . ' days left'); }
        else $label[] = $d . ' days left';
    }
    if ($needMiles) {
        if ($state === 'ok') $state = 'unknown';
        $label[] = 'miles since last done unknown: set the mileage it was done at';
    }
    return [$state, $dueM, $dueD, implode(' · ', $label)];
}

/** URL for a stored photo path ('uploads/<name>'): served through the password-gated index.php?p=file route. */
function photo_url(?string $path): string {
    $name = basename((string)$path);
    return $name === '' ? '' : url('file', ['f' => $name]);
}

/** A php.ini size ("40M", "100M", "1G") in bytes. */
function ini_bytes(string $key): int {
    $v = trim((string)ini_get($key));
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/**
 * Upload limits the browser checks before sending (assets/app.js reads them from <body data-*>):
 * file count = max_file_uploads; total size = post_max_size, capped at 95 MB because Cloudflare
 * rejects request bodies over 100 MB before they reach the server.
 */
function upload_limits(): array {
    $post = ini_bytes('post_max_size');
    return [
        'files' => max(1, (int)ini_get('max_file_uploads')),
        'file_bytes' => ini_bytes('upload_max_filesize'),
        'total_bytes' => min($post > 0 ? $post : PHP_INT_MAX, 95 * 1048576),
    ];
}

/** Find an executable on PATH (plus the usual Homebrew / system dirs). */
function find_bin(string $name): ?string {
    foreach (array_unique(array_merge(explode(PATH_SEPARATOR, (string)getenv('PATH')), ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/bin'])) as $dir) {
        if ($dir !== '' && is_file("$dir/$name") && is_executable("$dir/$name")) return "$dir/$name";
    }
    return null;
}

/**
 * Convert a HEIC/HEIF file to a temporary JPEG. Tries every converter that exists, in order:
 * libheif's heif-convert, ImageMagick (magick / convert), then macOS sips. Returns the temp path or null.
 */
function convert_heic(string $src): ?string {
    if (!function_exists('exec')) return null;
    $base = tempnam(sys_get_temp_dir(), 'heic');
    if ($base === false) return null;
    @unlink($base);
    $out = $base . '.jpg';
    $cmds = [];
    if ($b = find_bin('heif-convert')) $cmds[] = escapeshellarg($b) . ' ' . escapeshellarg($src) . ' ' . escapeshellarg($out);
    foreach (['magick', 'convert'] as $im) {
        if ($b = find_bin($im)) $cmds[] = escapeshellarg($b) . ' ' . escapeshellarg('heic:' . $src . '[0]') . ' -auto-orient -strip ' . escapeshellarg($out);
    }
    if ($b = find_bin('sips')) $cmds[] = escapeshellarg($b) . ' -s format jpeg ' . escapeshellarg($src) . ' --out ' . escapeshellarg($out);
    foreach ($cmds as $cmd) {
        $o = []; $rc = 1;
        @exec($cmd . ' 2>&1', $o, $rc);
        if ($rc === 0 && is_file($out) && filesize($out) > 0 && @getimagesize($out)) return $out;
        @unlink($out);
    }
    return null;
}

/**
 * Decode an image with GD and write it back out as a fresh JPEG (quality 85, longest side <= $max).
 * Applies the EXIF orientation first, and because GD writes no metadata the result carries no EXIF/GPS.
 * Returns false if the file is not a readable JPEG/PNG/WebP/GIF image.
 */
function reencode_image(string $src, string $dest, int $max = 2000): bool {
    if (!function_exists('imagecreatetruecolor')) return false;
    $info = @getimagesize($src);
    if (!$info || $info[0] < 1 || $info[1] < 1) return false;
    if ($info[0] * $info[1] > 80_000_000) return false; // beyond what this server can decode in memory
    @ini_set('memory_limit', '768M');
    @ini_set('gd.jpeg_ignore_warning', '1');
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG => @imagecreatefrompng($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($src),
        default => false,
    };
    if (!$img) return false;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $o = (int)($exif['Orientation'] ?? 1);
        // EXIF orientations 2-8: rotate (counter-clockwise degrees for imagerotate) then mirror where needed.
        $rot = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) $img = $r; }
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($img, IMG_FLIP_HORIZONTAL);
    }
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNG/GIF areas become white, not black
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $tmp = $dest . '.part';
    $ok = imagejpeg($out, $tmp, 85) && filesize($tmp) > 0 && rename($tmp, $dest);
    if (!$ok) @unlink($tmp);
    return $ok;
}

function save_upload(array $file, string $kind, int $refId, string $stage, string $caption = '', ?string $takenOn = null): ?int {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { flash('Upload failed (code ' . $file['error'] . '). Check file size.', 'err'); return null; }
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
    protect_dir(UPLOAD_DIR);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) { flash('Could not read the upload.', 'err'); return null; }

    if ($ext === 'pdf') {
        // PDFs (invoices, receipts) are stored as uploaded: they are not images, so no metadata is stripped.
        if ((string)file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') { flash($file['name'] . ' is not a valid PDF.', 'err'); return null; }
        $dest = UPLOAD_DIR . "/$name.pdf";
        if (!move_uploaded_file($tmp, $dest)) { flash('Could not save upload.', 'err'); return null; }
    } elseif (in_array($ext, ['heic', 'heif', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        if (!function_exists('imagecreatetruecolor')) { flash('This server has no image library (PHP GD), so photos cannot be stored safely. Ask for GD to be installed.', 'err'); return null; }
        $src = $tmp; $conv = null;
        if ($ext === 'heic' || $ext === 'heif') {
            $conv = convert_heic($tmp);
            if (!$conv) { flash($file['name'] . ': this HEIC photo could not be converted on the server. On the iPhone choose Settings > Camera > Formats > Most Compatible (or share the photo as JPEG) and upload again.', 'err'); return null; }
            $src = $conv;
        }
        // Always re-encode: strips EXIF/GPS, fixes rotation, downsizes, and proves the file really is an image.
        $dest = UPLOAD_DIR . "/$name.jpg";
        $ok = reencode_image($src, $dest);
        if ($conv) @unlink($conv);
        if (!$ok) { @unlink($dest); flash($file['name'] . ' is not a readable photo (or is too large to process).', 'err'); return null; }
    } else {
        flash('Unsupported file type .' . $ext, 'err'); return null;
    }
    return insert('photos', [
        'kind' => $kind, 'ref_id' => $refId, 'stage' => $stage, 'path' => UPLOAD_URL . '/' . basename($dest),
        'caption' => $caption, 'taken_on' => $takenOn ?: today(), 'created_at' => now(),
    ]);
}
function photos_for(string $kind, int $refId): array {
    return rows('SELECT * FROM photos WHERE kind=? AND ref_id=? ORDER BY stage, taken_on, id', [$kind, $refId]);
}

/* ---- Login throttle: 5 wrong passwords from one IP (one IPv6 /64) within 15 minutes locks it out for 15 minutes. ---- */
const LOGIN_MAX_FAILS = 5;
const LOGIN_WINDOW = 900;
const LOGIN_LOCK = 900;
/** Throttle key for this client: the IPv4 address, or the /64 prefix for IPv6 (one home or phone line usually gets a whole /64). */
function login_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) return (string)inet_ntop(substr($bin, 12)); // IPv4-mapped
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
    return substr($ip, 0, 64);
}
/** Seconds this IP must still wait, 0 if it may try. */
function login_locked_for(string $ip): int {
    $until = (int)col('SELECT locked_until FROM login_attempts WHERE ip=?', [$ip]);
    return max(0, $until - time());
}
function login_failed(string $ip): void {
    $t = time();
    q('DELETE FROM login_attempts WHERE locked_until < ? AND first_at < ?', [$t - 86400, $t - 86400]);
    $r = row('SELECT * FROM login_attempts WHERE ip=?', [$ip]);
    if (!$r || (int)$r['first_at'] < $t - LOGIN_WINDOW) { $fails = 1; $first = $t; }
    else { $fails = (int)$r['fails'] + 1; $first = (int)$r['first_at']; }
    $until = 0;
    if ($fails >= LOGIN_MAX_FAILS) { $until = $t + LOGIN_LOCK; $fails = 0; $first = $t; }
    q('INSERT OR REPLACE INTO login_attempts (ip, fails, first_at, locked_until) VALUES (?,?,?,?)', [$ip, $fails, $first, $until]);
}
function login_succeeded(string $ip): void { q('DELETE FROM login_attempts WHERE ip=?', [$ip]); }
/** Mark this browser as signed in with a brand-new session id (defeats session fixation). */
function sign_in(string $pwHash): void {
    session_regenerate_id(true);
    unset($_SESSION['csrf']);
    $_SESSION['authed'] = auth_tag($pwHash);
}
