<?php
// Shop operations (2026-11-08, part 2): appointments and online booking, technicians and the time clock (shift and
// job punches, flagged hours, efficiency, payroll hours), vendors and purchase orders, card payments through the
// shop's own Stripe account, the shop's own labor history, VIN / plate scanning, and the site admin.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const APPT_STATUS = ['requested' => 'Requested online', 'scheduled' => 'Scheduled', 'confirmed' => 'Confirmed', 'arrived' => 'Checked in', 'done' => 'Done', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];
const TECH_ROLES = ['tech' => 'Technician', 'advisor' => 'Service advisor', 'apprentice' => 'Apprentice / lube tech', 'owner' => 'Owner / manager'];
const PAY_TYPES = ['hourly' => 'Hourly (shift hours)', 'flat' => 'Flat rate (billed labor hours)', 'salary' => 'Salary (hours tracked only)'];
const PO_STATUS = ['draft' => 'Draft', 'ordered' => 'Ordered', 'partial' => 'Partly received', 'received' => 'Received', 'cancelled' => 'Cancelled'];
const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
const TECH_COLORS = ['#3b82f6', '#22c55e', '#f59e0b', '#a855f7', '#ef4444', '#14b8a6', '#ec4899', '#84cc16'];

const OPS_ACTIONS = ['appt_save', 'appt_status', 'appt_checkin', 'appt_remind', 'appt_delete', 'booking_save', 'tech_save', 'tech_delete', 'clock_punch', 'time_save', 'time_add', 'time_delete',
    'line_tech', 'vendor_save', 'vendor_delete', 'po_new', 'po_line_add', 'po_line_save', 'po_line_delete', 'po_status', 'po_receive', 'po_delete', 'parts_paste', 'pay_save', 'pay_link',
    'admin_toggle', 'admin_delete', 'admin_signups', 'admin_purge_guests'];

function migrate_ops(PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('tech_id', $cols('job_lines'), true)) $pdo->exec('ALTER TABLE job_lines ADD COLUMN tech_id INT');
    if (!in_array('ext_ref', $cols('job_payments'), true)) $pdo->exec('ALTER TABLE job_payments ADD COLUMN ext_ref TEXT');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pay_ext ON job_payments (ext_ref) WHERE ext_ref IS NOT NULL');
}

function setting(string $k, ?string $set = null): string {
    if ($set !== null) { q('INSERT OR REPLACE INTO settings (key, value) VALUES (?,?)', [$k, $set]); return $set; }
    return (string)(col('SELECT value FROM settings WHERE key=?', [$k]) ?: '');
}
function signups_open(): bool { return SIGNUP_OPEN && setting('signup_closed') !== '1'; }
function hm(int $min): string { return sprintf('%d:%02d', intdiv($min, 60), $min % 60); }
function hrs(float $h): string { return rtrim(rtrim(number_format($h, 2), '0'), '.') . ' h'; }

/* ---------- Appointments ---------- */

/** Opening hours by ISO weekday: [open, close] or null when closed. */
function shop_hours(?array $s = null): array {
    $s ??= shop(); $hrs = $s['hours'] ?? [];
    $out = [];
    foreach (WEEKDAYS as $d => $_) $out[$d] = isset($hrs[$d]) && is_array($hrs[$d]) && count($hrs[$d]) === 2 ? $hrs[$d] : null;
    return $out;
}
function appts(string $from, string $to, ?int $userId = null): array {
    return rows("SELECT a.*, c.name cname, c.phone cphone, c.email cemail, v.year vy, v.make vm, v.model vmo, v.plate vplate, t.name tname, t.color tcolor
        FROM appointments a LEFT JOIN customers c ON c.id=a.customer_id LEFT JOIN vehicle v ON v.id=a.vehicle_id LEFT JOIN techs t ON t.id=a.tech_id
        WHERE a.user_id=? AND a.starts_at >= ? AND a.starts_at < ? ORDER BY a.starts_at", [$userId ?? uid(), $from, $to]);
}
function appt_who(array $a): string { return (string)($a['cname'] ?? '') ?: (string)$a['name'] ?: 'Walk-in'; }
function appt_vehicle(array $a): string { return trim(($a['vy'] ?? '') . ' ' . ($a['vm'] ?? '') . ' ' . ($a['vmo'] ?? '')) ?: (string)$a['vehicle_text']; }
/** Free start times on a day: within opening hours, fewer overlapping bookings than bays, and not in the past. */
function appt_slots(int $userId, string $date, int $minutes = 0): array {
    $s = shop($userId); $h = shop_hours($s)[(int)date('N', strtotime($date))] ?? null;
    if (!$h) return [];
    $step = max(15, (int)$s['slot_min']); $len = $minutes ?: $step; $bays = max(1, (int)$s['bays']);
    $open = strtotime("$date {$h[0]}"); $close = strtotime("$date {$h[1]}");
    $earliest = time() + (int)$s['book_lead_hours'] * 3600;
    $busy = rows("SELECT starts_at, minutes FROM appointments WHERE user_id=? AND starts_at LIKE ? AND status NOT IN ('cancelled','no_show')", [$userId, "$date%"]);
    $out = [];
    for ($t = $open; $t + $len * 60 <= $close; $t += $step * 60) {
        if ($t < $earliest) continue;
        $n = 0; foreach ($busy as $b) { $bs = strtotime((string)$b['starts_at']); if ($bs < $t + $len * 60 && $bs + (int)$b['minutes'] * 60 > $t) $n++; }
        if ($n < $bays) $out[] = date('H:i', $t);
    }
    return $out;
}
/** Create a repair order (estimate) and return its id. */
function job_create(?int $cid, ?int $vehId, string $complaint, string $title = ''): int {
    $s = shop();
    $jid = insert('jobs', ['user_id' => uid(), 'customer_id' => $cid, 'vehicle_id' => $vehId, 'stage' => 'estimate', 'est_no' => next_number('est'), 'title' => $title ?: 'Estimate',
        'complaint' => $complaint, 'odometer_in' => $vehId ? ((int)col('SELECT mileage FROM vehicle WHERE id=?', [$vehId]) ?: null) : null,
        'labor_rate' => $s['labor_rate'], 'tax_parts' => $s['tax_parts'], 'tax_labor' => $s['tax_labor'], 'supplies_pct' => $s['supplies_pct'], 'supplies_cap' => $s['supplies_cap'],
        'discount' => 0, 'created_at' => now(), 'updated_at' => now()]);
    return $jid;
}
/** Check-in: make sure the appointment has a customer and vehicle on file, open its repair order, mark it arrived. */
function appt_check_in(array $a): int {
    $cid = $a['customer_id'] ? (int)$a['customer_id'] : null;
    if (!$cid && trim((string)$a['name']) !== '') {
        $cid = (int)(col('SELECT id FROM customers WHERE user_id=? AND ((phone<>\'\' AND phone=?) OR (email<>\'\' AND email=?)) LIMIT 1', [uid(), (string)$a['phone'], (string)$a['email']]) ?: 0) ?: null;
        $cid ??= insert('customers', ['user_id' => uid(), 'name' => $a['name'], 'phone' => (string)$a['phone'], 'email' => (string)$a['email'], 'notes' => 'From appointment ' . date('M j', strtotime((string)$a['starts_at'])), 'created_at' => now()]);
    }
    $vehId = $a['vehicle_id'] ? (int)$a['vehicle_id'] : null;
    if (!$vehId && ($a['vin'] || $a['vehicle_text'])) {
        $d = $a['vin'] ? vin_decode_world((string)$a['vin']) : [];
        $parts = preg_split('/\s+/', trim((string)$a['vehicle_text']), 3) + ['', '', ''];
        $year = (int)($d['year'] ?? 0) ?: (preg_match('/^(19|20)\d\d$/', $parts[0]) ? (int)$parts[0] : 0);
        [$make, $model] = !empty($d['make']) ? [$d['make'], (string)($d['model'] ?? '')] : ($year ? [$parts[1], $parts[2]] : [$parts[0], trim($parts[1] . ' ' . $parts[2])]);
        $kind = array_key_exists((string)($d['kind'] ?? ''), VEHICLE_KINDS) ? (string)$d['kind'] : 'car';
        $vehId = insert('vehicle', ['user_id' => uid(), 'customer_id' => $cid, 'year' => $year ?: null, 'make' => $make, 'model' => $model, 'engine' => (string)($d['engine'] ?? ''), 'trim' => (string)($d['trim'] ?? ''),
            'vin' => (string)$a['vin'], 'plate' => (string)$a['plate'], 'mileage' => 0, 'mileage_updated' => today(), 'notes' => '', 'mods' => '', 'kind' => $kind]);
        seed_vehicle_templates($vehId, $kind);
    }
    $jid = $a['job_id'] && owns_row('jobs', (int)$a['job_id']) ? (int)$a['job_id'] : job_create($cid, $vehId, (string)$a['concern'], (string)$a['title'] ?: 'Appointment ' . date('M j', strtotime((string)$a['starts_at'])));
    q("UPDATE appointments SET status='arrived', customer_id=?, vehicle_id=?, job_id=?, updated_at=? WHERE id=?", [$cid, $vehId, $jid, now(), $a['id']]);
    if ($a['tech_id']) q('UPDATE job_lines SET tech_id=? WHERE job_id=? AND tech_id IS NULL', [$a['tech_id'], $jid]);
    job_refresh($jid);
    return $jid;
}
function appt_message(array $a, array $s): string {
    $when = date('l, M j \a\t g:i a', strtotime((string)$a['starts_at']));
    return 'Hi' . (appt_who($a) !== 'Walk-in' ? ' ' . strtok(appt_who($a), ' ') : '') . ', this is ' . ($s['name'] ?: 'the shop') . ': your appointment' . (appt_vehicle($a) !== '' ? ' for the ' . appt_vehicle($a) : '') . ' is ' . $when . '.'
        . ($s['address'] ? ' ' . $s['address'] . '.' : '') . ' Reply or call ' . ($s['phone'] ?: 'us') . ' if you need to change it.';
}
function appt_email(array $a, array $s, string $subject): bool {
    $to = (string)($a['cemail'] ?? '') ?: (string)$a['email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !rate_ok('apptmail:' . uid(), 60, 86400)) return false;
    return send_mail($to, $subject, '<p>' . h(appt_message($a, $s)) . '</p><p style="color:#666;font-size:13px">' . h($s['name'] ?: APP_NAME) . ($s['phone'] ? ' · ' . h($s['phone']) : '') . '</p>');
}
function book_url(array $s): string { return $s['book_token'] ? base_url() . '/' . url('book', ['s' => $s['book_token']]) : ''; }

/* ---------- Technicians and the time clock ---------- */

function techs(bool $activeOnly = true): array {
    return rows('SELECT * FROM techs WHERE user_id=?' . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY active DESC, sort, name', [uid()]);
}
function tech_open(int $techId, string $kind): ?array {
    return row('SELECT * FROM time_entries WHERE tech_id=? AND kind=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$techId, $kind]);
}
/** Punch: shift_in, shift_out (also stops a running job), job_start (one job at a time; clocks in the shift too), job_stop. */
function clock_punch(array $tech, string $what, ?int $jobId = null, string $grp = ''): string {
    $t = time(); $tid = (int)$tech['id'];
    $close = function (string $kind) use ($tid, $t) { q('UPDATE time_entries SET ended_at=? WHERE tech_id=? AND kind=? AND ended_at IS NULL', [$t, $tid, $kind]); };
    switch ($what) {
        case 'shift_in':
            if (!tech_open($tid, 'shift')) insert('time_entries', ['user_id' => uid(), 'tech_id' => $tid, 'kind' => 'shift', 'started_at' => $t]);
            return $tech['name'] . ' clocked in at ' . date('g:i a', $t) . '.';
        case 'shift_out':
            $close('job'); $close('shift');
            return $tech['name'] . ' clocked out at ' . date('g:i a', $t) . '.';
        case 'job_start':
            if (!$jobId || !owns_row('jobs', $jobId)) return 'Pick a repair order.';
            if (!tech_open($tid, 'shift')) insert('time_entries', ['user_id' => uid(), 'tech_id' => $tid, 'kind' => 'shift', 'started_at' => $t]);
            $close('job');
            insert('time_entries', ['user_id' => uid(), 'tech_id' => $tid, 'kind' => 'job', 'job_id' => $jobId, 'grp' => $grp, 'started_at' => $t]);
            // The tech takes the service's labor lines (if nobody has them yet), and the order shows as in progress.
            q("UPDATE job_lines SET tech_id=? WHERE job_id=? AND kind='labor' AND tech_id IS NULL" . ($grp !== '' ? ' AND grp=?' : ''), $grp !== '' ? [$tid, $jobId, $grp] : [$tid, $jobId]);
            q("UPDATE jobs SET stage='in_progress', updated_at=? WHERE id=? AND stage='approved'", [now(), $jobId]);
            return $tech['name'] . ' started ' . job_label(row('SELECT * FROM jobs WHERE id=?', [$jobId])) . ($grp !== '' ? ' · ' . $grp : '') . '.';
        case 'job_stop':
            $close('job');
            return $tech['name'] . ' stopped work at ' . date('g:i a', $t) . '.';
    }
    return '';
}
/** Hours of time entries overlapping [from, to) (open entries count up to now). */
function entry_hours(array $e, int $from = 0, int $to = PHP_INT_MAX): float {
    $s = max((int)$e['started_at'], $from); $end = min($e['ended_at'] !== null ? (int)$e['ended_at'] : time(), $to);
    return $end > $s ? ($end - $s) / 3600 : 0.0;
}
/**
 * Per technician for a period: shift hours, hours clocked on jobs, flagged hours (billed labor on their lines of orders
 * invoiced in the period), labor sold, efficiency (flagged / on-job), productivity (on-job / shift), and gross pay.
 */
function time_report(int $from, int $to): array {
    $out = [];
    foreach (techs(false) as $t) {
        $es = rows('SELECT * FROM time_entries WHERE tech_id=? AND started_at < ? AND (ended_at IS NULL OR ended_at > ?)', [$t['id'], $to, $from]);
        $shift = 0.0; $job = 0.0;
        foreach ($es as $e) { $hh = entry_hours($e, $from, $to); if ($e['kind'] === 'shift') $shift += $hh; else $job += $hh; }
        $fl = row("SELECT COALESCE(SUM(l.qty),0) h, COALESCE(SUM(l.qty*l.price),0) amt FROM job_lines l JOIN jobs j ON j.id=l.job_id WHERE l.tech_id=? AND l.kind='labor' AND l.status='approved'
            AND j.stage IN ('invoiced','paid') AND j.invoiced_at >= ? AND j.invoiced_at < ?", [$t['id'], date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)]);
        $flag = (float)$fl['h'];
        $pay = match ($t['pay_type']) { 'flat' => $flag * (float)$t['pay_rate'], 'hourly' => $shift * (float)$t['pay_rate'], default => 0.0 };
        if ($shift || $job || $flag || $t['active']) $out[] = ['tech' => $t, 'shift' => $shift, 'job' => $job, 'flagged' => $flag, 'sold' => (float)$fl['amt'],
            'eff' => $job > 0 ? $flag / $job * 100 : null, 'prod' => $shift > 0 ? $job / $shift * 100 : null, 'pay' => round($pay, 2), 'entries' => count($es)];
    }
    return $out;
}
/** Hours clocked on a repair order, by service. */
function job_clocked(int $jobId): array {
    $out = [];
    foreach (rows("SELECT * FROM time_entries WHERE job_id=? AND kind='job'", [$jobId]) as $e) $out[(string)$e['grp']] = ($out[(string)$e['grp']] ?? 0) + entry_hours($e);
    return $out;
}
/** The shop's own labor guide: actual clocked hours on a service name across its finished orders (same make first). */
function labor_history(string $grp, string $make = ''): ?array {
    if ($grp === '' || $grp === 'General') return null;
    $r = rows("SELECT e.job_id, SUM(COALESCE(e.ended_at, e.started_at) - e.started_at) secs, v.make FROM time_entries e JOIN jobs j ON j.id=e.job_id LEFT JOIN vehicle v ON v.id=j.vehicle_id
        WHERE e.user_id=? AND e.kind='job' AND e.grp=? AND e.ended_at IS NOT NULL AND j.stage IN ('done','invoiced','paid') GROUP BY e.job_id", [uid(), $grp]);
    if (!$r) return null;
    $same = array_values(array_filter($r, fn($x) => $make !== '' && strcasecmp((string)$x['make'], $make) === 0));
    $use = count($same) >= 2 ? $same : $r;
    $h = array_map(fn($x) => (int)$x['secs'] / 3600, $use);
    sort($h);
    return ['avg' => array_sum($h) / count($h), 'median' => $h[intdiv(count($h), 2)], 'n' => count($h), 'make' => $use === $same ? $make : ''];
}

/* ---------- Vendors and purchase orders ---------- */

function vendors(): array { return rows('SELECT * FROM vendors WHERE user_id=? ORDER BY name', [uid()]); }
function po_refresh(int $poId): void {
    $ls = rows('SELECT * FROM po_lines WHERE po_id=?', [$poId]);
    $tot = array_sum(array_map(fn($l) => (float)$l['qty'] * (float)$l['cost'], $ls));
    q('UPDATE purchase_orders SET total=?, updated_at=? WHERE id=?', [round($tot, 2), now(), $poId]);
}
function po_next_number(): string { $s = shop(); $n = (int)$s['next_po']; shop_save(['next_po' => $n + 1]); return $s['po_prefix'] . $n; }
/** Parts on approved work that still need ordering, by the supplier named on the line. */
function parts_to_order(?int $jobId = null): array {
    return rows("SELECT l.*, j.est_no, j.inv_no, j.stage jstage, j.id jid, c.name cname FROM job_lines l JOIN jobs j ON j.id=l.job_id LEFT JOIN customers c ON c.id=j.customer_id
        WHERE j.user_id=? AND l.kind='part' AND l.status='approved' AND l.pstatus='quoted'
        AND NOT EXISTS (SELECT 1 FROM po_lines pl JOIN purchase_orders po ON po.id=pl.po_id WHERE pl.job_line_id=l.id AND po.status<>'cancelled') AND (l.catalog_id IS NULL OR (SELECT qty FROM parts_catalog WHERE id=l.catalog_id) < l.qty)
        AND j.stage IN ('approved','in_progress','waiting_parts')" . ($jobId ? ' AND j.id=' . (int)$jobId : '') . ' ORDER BY l.supplier, j.id', [uid()]);
}
function po_receive(int $poId, array $qtys): array {
    $done = 0; $jobs = [];
    foreach (rows('SELECT * FROM po_lines WHERE po_id=?', [$poId]) as $l) {
        $rec = isset($qtys[$l['id']]) ? max(0, (float)$qtys[$l['id']]) : (float)$l['qty'];
        $add = $rec - (float)$l['qty_received']; if (abs($add) < 0.0001) { if ($rec >= (float)$l['qty']) $done++; continue; }
        q('UPDATE po_lines SET qty_received=? WHERE id=?', [$rec, $l['id']]);
        if ($l['job_line_id']) {
            if ($rec >= (float)$l['qty']) q("UPDATE job_lines SET pstatus='received', cost=CASE WHEN cost=0 THEN ? ELSE cost END WHERE id=? AND pstatus IN ('quoted','ordered')", [(float)$l['cost'], $l['job_line_id']]);
            $jobs[(int)col('SELECT job_id FROM job_lines WHERE id=?', [$l['job_line_id']])] = true;
        } elseif ($l['catalog_id']) {
            q('UPDATE parts_catalog SET qty=qty+?, cost=?, updated_at=? WHERE id=? AND user_id=?', [$add, (float)$l['cost'], now(), $l['catalog_id'], uid()]);
        }
        if ($rec >= (float)$l['qty']) $done++;
    }
    $n = (int)col('SELECT COUNT(*) FROM po_lines WHERE po_id=?', [$poId]);
    q('UPDATE purchase_orders SET status=?, received_at=CASE WHEN ?=1 THEN ? ELSE received_at END, updated_at=? WHERE id=?', [$done >= $n ? 'received' : 'partial', $done >= $n ? 1 : 0, now(), now(), $poId]);
    // Orders waiting only on these parts can go back to work.
    $ready = [];
    foreach (array_keys($jobs) as $jid) if (!col("SELECT 1 FROM job_lines WHERE job_id=? AND kind='part' AND status='approved' AND pstatus IN ('quoted','ordered')", [$jid]) && col("SELECT stage FROM jobs WHERE id=?", [$jid]) === 'waiting_parts') $ready[] = $jid;
    return $ready;
}

/* ---------- Card payments: Stripe Checkout on the shop's own Stripe account ---------- */

function stripe_on(array $s): bool { return (bool)preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', (string)$s['stripe_sk']); }
function stripe_req(string $key, string $method, string $path, array $params = []): array {
    if (!function_exists('curl_init')) return [0, []];
    $c = curl_init('https://api.stripe.com/v1' . $path . ($method === 'GET' && $params ? '?' . http_build_query($params) : ''));
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERPWD => $key . ':', CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20']] + ($method === 'POST' ? [CURLOPT_POSTFIELDS => http_build_query($params)] : []));
    $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    return [$code, is_string($b) ? (json_decode($b, true) ?: []) : []];
}
/** A Checkout page for the balance due. Returns [url|null, error]. */
function stripe_checkout(array $job): array {
    $s = shop((int)$job['user_id']);
    if (!stripe_on($s)) return [null, 'Card payments are not set up.'];
    $due = round((float)$job['total'] - (float)$job['paid'], 2);
    if ($due < 0.5) return [null, 'Nothing is due.'];
    $back = base_url() . '/' . url('quote', ['t' => $job['token']]);
    [$code, $j] = stripe_req((string)$s['stripe_sk'], 'POST', '/checkout/sessions', [
        'mode' => 'payment', 'client_reference_id' => 'job-' . $job['id'],
        'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower((string)$s['currency_code'] ?: 'usd'), 'unit_amount' => (int)round($due * 100),
            'product_data' => ['name' => job_label($job) . ' · ' . ($s['name'] ?: APP_NAME), 'description' => mb_substr((string)$job['title'], 0, 200) ?: 'Vehicle repair']]]],
        'metadata' => ['job_id' => (string)$job['id'], 'app' => 'garage'], 'payment_intent_data' => ['metadata' => ['job_id' => (string)$job['id']], 'description' => job_label($job)],
        'success_url' => $back . '&paid={CHECKOUT_SESSION_ID}', 'cancel_url' => $back,
    ]);
    if ($code !== 200 || empty($j['url'])) return [null, (string)($j['error']['message'] ?? 'The card processor did not answer.')];
    return [(string)$j['url'], ''];
}
/** After Checkout: ask Stripe whether the session was paid, and record it once. */
function stripe_confirm(array $job, string $sessionId): ?float {
    $s = shop((int)$job['user_id']);
    if (!stripe_on($s) || !preg_match('/^cs_(test|live)_[A-Za-z0-9]{10,200}$/', $sessionId)) return null;
    if (col('SELECT 1 FROM job_payments WHERE ext_ref=?', [$sessionId])) return 0.0;
    [$code, $j] = stripe_req((string)$s['stripe_sk'], 'GET', '/checkout/sessions/' . $sessionId);
    if ($code !== 200 || ($j['payment_status'] ?? '') !== 'paid' || (string)($j['metadata']['job_id'] ?? '') !== (string)$job['id']) return null;
    $amt = round((int)$j['amount_total'] / 100, 2);
    try {
        insert('job_payments', ['job_id' => $job['id'], 'amount' => $amt, 'method' => 'card', 'paid_on' => today(), 'note' => 'Paid online (Stripe ' . substr((string)($j['payment_intent'] ?? $sessionId), -8) . ')', 'ext_ref' => $sessionId, 'created_at' => now()]);
    } catch (PDOException) { return 0.0; }
    q('UPDATE jobs SET seen_approval=0 WHERE id=?', [$job['id']]);
    job_refresh((int)$job['id']);
    return $amt;
}

/* ---------- VIN and plate scanning ---------- */

/** ISO 3779 check digit (position 9). Mandatory in North America; most other makers fill it too. */
function vin_check_ok(string $v): bool {
    if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $v)) return false;
    $map = array_combine(str_split('ABCDEFGHJKLMNPRSTUVWXYZ'), [1, 2, 3, 4, 5, 6, 7, 8, 1, 2, 3, 4, 5, 7, 9, 2, 3, 4, 5, 6, 7, 8, 9]);
    $w = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2]; $sum = 0;
    foreach (str_split($v) as $i => $ch) $sum += (ctype_digit($ch) ? (int)$ch : $map[$ch]) * $w[$i];
    $cd = $sum % 11; return $v[8] === ($cd === 10 ? 'X' : (string)$cd);
}
/** 17-character VINs in OCR or barcode text, fixing the usual misreads (O/Q to 0, I to 1); check-digit-valid ones first. */
function vin_candidates(string $text): array {
    $out = [];
    foreach (preg_split('/[\r\n,;]+/', strtoupper($text)) as $ln) {
        $flat = preg_replace('/[^A-Z0-9]/', '', preg_replace('/^\s*(VIN|V\.I\.N\.?)\s*[:#]?/', '', $ln));
        foreach (array_unique([$flat, preg_replace('/^I(?=[A-HJ-NPR-Z0-9]{17}$)/', '', $flat)]) as $cand) {
            for ($i = 0; $i + 17 <= strlen($cand); $i++) {
                $v = strtr(substr($cand, $i, 17), ['O' => '0', 'Q' => '0', 'I' => '1']);
                if (preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $v) && preg_match('/\d/', $v) && preg_match('/[A-Z]/', $v)) $out[$v] = vin_check_ok($v) ? 2 : (isset($out[$v]) ? $out[$v] : 1);
            }
        }
    }
    arsort($out, SORT_NUMERIC);
    return array_keys($out);
}
const US_STATES = ['AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS', 'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE', 'DC' => 'DISTRICT OF COLUMBIA',
    'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII', 'ID' => 'IDAHO', 'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA', 'KS' => 'KANSAS', 'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE',
    'MD' => 'MARYLAND', 'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI', 'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA', 'NH' => 'NEW HAMPSHIRE',
    'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK', 'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO', 'OK' => 'OKLAHOMA', 'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND',
    'SC' => 'SOUTH CAROLINA', 'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH', 'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA', 'WI' => 'WISCONSIN', 'WY' => 'WYOMING', 'PR' => 'PUERTO RICO'];
function us_state_code(string $s): string {
    $s = strtoupper(trim(preg_replace('/[^A-Za-z ]/', ' ', $s)));
    if (isset(US_STATES[$s])) return $s;
    foreach (US_STATES as $k => $n) if ($s !== '' && str_contains($s, $n)) return $k;
    return '';
}
function plate_lookup_available(): bool { return PLATE_KEY !== '' && in_array(PLATE_PROVIDER, ['autodev', 'carsxe', 'vinaudit'], true); }
/** Plate + US state to VIN through the configured provider (paid; registration data). Cached 90 days. */
function plate_to_vin(string $plate, string $state): ?string {
    $plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate)); $state = us_state_code($state);
    if (!plate_lookup_available() || $plate === '' || $state === '') return null;
    $key = "plate:$state:$plate";
    $c = row('SELECT body, fetched_at FROM vpic_cache WHERE key=?', [$key]);
    if ($c && (int)$c['fetched_at'] > time() - 90 * 86400) return (string)$c['body'] ?: null;
    $u = match (PLATE_PROVIDER) {
        'autodev' => 'https://api.auto.dev/plate/' . rawurlencode($plate) . '?' . http_build_query(['state' => $state]),
        'carsxe' => 'https://api.carsxe.com/platedecoder?' . http_build_query(['key' => PLATE_KEY, 'plate' => $plate, 'state' => $state, 'format' => 'json']),
        'vinaudit' => 'https://plate2vin.vinaudit.com/v1/licenselookup?' . http_build_query(['key' => PLATE_KEY, 'plate' => $plate, 'state' => $state, 'format' => 'json']),
    };
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15] + (PLATE_PROVIDER === 'autodev' ? [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . PLATE_KEY]] : []));
    $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($b) || $code >= 500 || $code === 0) return null;
    $vin = null;
    if (preg_match_all('/"[A-Za-z_]*vin[A-Za-z_]*"\s*:\s*"([A-HJ-NPR-Z0-9]{17})"/i', $b, $m)) $vin = strtoupper($m[1][0]);
    q('INSERT OR REPLACE INTO vpic_cache (key, body, fetched_at) VALUES (?,?,?)', [$key, (string)$vin, time()]);
    return $vin;
}
/**
 * Read a VIN or plate from an uploaded photo (the vision service) or from barcode text decoded on the phone.
 * Returns what the vehicle forms need: vin + decoded fields, or plate/state (+ VIN when a plate service is set up).
 */
function id_scan(string $kind, ?array $file, string $barcode = '', string $rateKey = ''): array {
    $text = $barcode;
    if ($text === '' && $file) {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return ['error' => 'No photo received.'];
        if (VISION_URL === '') return ['error' => 'Reading photos is not set up on this server. Type it instead.'];
        if (!rate_ok('idscan:' . ($rateKey ?: (string)uid()), str_starts_with($rateKey, 'ip:') ? 8 : (is_guest() ? 10 : 60), 86400)) return ['error' => 'Daily limit for reading photos reached. Type it instead.'];
        $r = vision_call('POST', '/read-id?kind=' . $kind, (string)file_get_contents($file['tmp_name']), 120);
        if (!$r || $r['code'] !== 200) return ['error' => $r && $r['code'] === 503 ? 'The photo reader is busy. Try again in a minute, or type it.' : 'Could not read that photo. Try closer, in focus, without glare.'];
        $text = (string)(json_decode($r['body'], true)['text'] ?? '');
    }
    if ($kind === 'vin') {
        $c = vin_candidates($text);
        if (!$c) return ['error' => 'No VIN found in that photo. Aim at the door-frame sticker or the plate at the bottom of the windshield, close and in focus.', 'raw' => mb_substr($text, 0, 200)];
        $d = vin_decode_world($c[0]);
        return $d + ['vin' => $c[0], 'checked' => vin_check_ok($c[0]), 'others' => array_slice($c, 1, 3), 'via' => $barcode !== '' ? 'barcode' : 'photo'];
    }
    $plate = preg_match('/PLATE:\s*([A-Z0-9 \-·]{2,12})/i', $text, $m) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $m[1])) : '';
    $region = preg_match('/REGION:\s*([^\r\n]{2,40})/i', $text, $m) ? trim($m[1]) : '';
    if ($plate === '') return ['error' => 'No plate found in that photo. Get the whole plate in the frame, straight on.'];
    $state = us_state_code($region);
    $out = ['plate' => $plate, 'state' => $state, 'region' => strtoupper($region) === 'UNKNOWN' ? '' : $region];
    if ($vin = plate_to_vin($plate, $state)) $out += vin_decode_world($vin) + ['vin' => $vin];
    elseif (!plate_lookup_available()) $out['hint'] = 'Plate read. Scan the VIN too to fill in the vehicle (plate-to-vehicle lookups need a paid registration-data service, which is not switched on here).';
    else $out['hint'] = 'Plate read, but the registration lookup found no vehicle for it. Scan the VIN instead.';
    return $out;
}

/* ---------- Site admin ---------- */

function admin_users(string $qq = ''): array {
    return rows("SELECT u.id, u.email, u.name, u.created_at, u.last_login, u.is_admin, u.is_guest, u.mode, u.verified_at,
        (SELECT COUNT(*) FROM vehicle v WHERE v.user_id=u.id AND v.customer_id IS NULL) nveh, (SELECT COUNT(*) FROM vehicle v WHERE v.user_id=u.id AND v.customer_id IS NOT NULL) ncust,
        (SELECT COUNT(*) FROM jobs j WHERE j.user_id=u.id) njobs FROM users u WHERE u.is_guest=0" . ($qq !== '' ? ' AND (u.email LIKE ? OR u.name LIKE ?)' : '') . ' ORDER BY u.id LIMIT 500', $qq !== '' ? ["%$qq%", "%$qq%"] : []);
}

/**
 * "Scan VIN / Scan plate" buttons for any vehicle form. The phone camera opens; door-frame barcodes are decoded on the
 * phone, anything else goes to the photo reader. Results fill the form's vin/year/make/model/engine/trim/plate/state
 * fields (and vehicle_text), so typing is only the fallback.
 */
function idscan_widget(string $bookToken = '', bool $plate = true): string {
    return '<div class="idscan" data-idscan data-csrf="' . h(csrf()) . '"' . ($bookToken !== '' ? ' data-book="' . h($bookToken) . '"' : '') . '>'
        . '<label class="btn primary idscan-btn"><input type="file" accept="image/*" capture="environment" data-idscan-file="vin" hidden>📷 Scan the VIN</label>'
        . ($plate ? '<label class="btn idscan-btn"><input type="file" accept="image/*" capture="environment" data-idscan-file="plate" hidden>🔢 Scan the plate</label>' : '')
        . '<small class="idscan-hint muted">Snap the sticker on the driver\'s door frame, the VIN plate at the bottom of the windshield, or the registration card.</small>'
        . '<div class="idscan-out" data-idscan-out aria-live="polite"></div></div>';
}
