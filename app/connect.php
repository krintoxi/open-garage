<?php
// Drivers and shops, connected (2026-11-08, roadmap #1).
// A driver sends a "shop report" link (problems, warning codes, diagnosis, photos, history). A shop that uses this app
// turns it into an estimate in one tap; any other mechanic can read and print it. When the job is invoiced the finished
// work goes back into the driver's garage as a visit marked "verified by <shop>", and work the shop recommended but the
// customer declined becomes an open problem there. The driver chooses what is shared, and can turn the link off.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const CONNECT_ACTIONS = ['handoff_create', 'handoff_revoke', 'handoff_import', 'handoff_return', 'light_scan'];
const SEV_WORDS = [5 => 'Fix now', 4 => 'Fix soon', 3 => 'When you can', 2 => 'Keep an eye on it', 1 => 'Minor'];

function migrate_connect(PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('handoff_id', $cols('jobs'), true)) $pdo->exec('ALTER TABLE jobs ADD COLUMN handoff_id INT');
    $vc = $cols('visits');
    foreach (['verified_by' => 'TEXT', 'job_ref' => 'TEXT'] as $c => $t) if (!in_array($c, $vc, true)) $pdo->exec("ALTER TABLE visits ADD COLUMN $c $t");
    if (!in_array('from_shop', $cols('issues'), true)) $pdo->exec('ALTER TABLE issues ADD COLUMN from_shop TEXT');
}

function handoff_by_token(string $t): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $t)) return null;
    return row('SELECT * FROM handoffs WHERE token=? AND revoked=0', [$t]);
}
function handoff_url(string $token): string { return base_url() . '/' . url('handoff', ['t' => $token]); }
/** Integer ids stored as JSON. */
function handoff_ids(?string $json): array { return array_values(array_filter(array_map('intval', json_decode((string)$json, true) ?: []))); }

/** Everything the report shows, read by the handoff's own vehicle and the ids the driver picked (never the viewer's session). */
function handoff_data(array $ho): array {
    $vid = (int)$ho['vehicle_id'];
    $v = row('SELECT * FROM vehicle WHERE id=? AND user_id=?', [$vid, $ho['user_id']]) ?? [];
    $issueIds = handoff_ids($ho['issue_ids']); $photoIds = handoff_ids($ho['photo_ids']);
    $in = fn(array $ids) => $ids ? implode(',', $ids) : '0';
    $issues = rows('SELECT * FROM issues WHERE vehicle_id=? AND id IN (' . $in($issueIds) . ') ORDER BY severity DESC, opened_on DESC', [$vid]);
    $codes = array_values(array_filter((array)(json_decode((string)$ho['codes'], true) ?: []), fn($c) => is_string($c) && preg_match('/^[PBCU][0-9A-F]{4}$/', $c)));
    $diag = $ho['diag_id'] ? row('SELECT * FROM diag_reports WHERE id=? AND vehicle_id=? AND user_id=?', [$ho['diag_id'], $vid, $ho['user_id']]) : null;
    $photos = rows('SELECT * FROM photos WHERE vehicle_id=? AND id IN (' . $in($photoIds) . ') ORDER BY id', [$vid]);
    $visits = $ho['show_history'] ? rows("SELECT v.*, m.name mech FROM visits v LEFT JOIN mechanics m ON m.id=v.mechanic_id WHERE v.vehicle_id=? AND v.status='done' ORDER BY COALESCE(v.scheduled_on, v.created_at) DESC LIMIT 12", [$vid]) : [];
    $maint = [];
    if ($ho['show_history']) foreach (rows('SELECT * FROM maintenance WHERE vehicle_id=? ORDER BY priority, item', [$vid]) as $m) {
        if (!$m['last_date'] && $m['last_miles'] === null) continue;
        [$state, , , $lbl] = maintenance_status($m, (int)($v['mileage'] ?? 0));
        $maint[] = $m + ['state' => $state, 'lbl' => $lbl];
    }
    $recalls = rows("SELECT * FROM recalls WHERE vehicle_id=? AND status='open' ORDER BY park_it DESC, report_date DESC", [$vid]);
    return compact('v', 'issues', 'codes', 'diag', 'photos', 'visits', 'maint', 'recalls');
}

/** Shop side: customer + vehicle + estimate from a driver's report, in the signed-in shop's account. Returns the job id. */
function handoff_import(array $ho): int {
    if ($ho['job_id'] && (int)$ho['shop_user_id'] === uid() && owns_row('jobs', (int)$ho['job_id'])) return (int)$ho['job_id'];
    $d = handoff_data($ho); $v = $d['v']; $s = shop();
    $digits = fn($p) => preg_replace('/\D/', '', (string)$p);
    // The customer: the same email or phone number already on file, else a new one.
    $cid = 0;
    if ($ho['contact_email'] !== '') $cid = (int)col('SELECT id FROM customers WHERE user_id=? AND lower(email)=lower(?) LIMIT 1', [uid(), $ho['contact_email']]);
    if (!$cid && strlen($digits($ho['contact_phone'])) >= 7) foreach (rows('SELECT id, phone FROM customers WHERE user_id=?', [uid()]) as $c) if ($digits($c['phone']) !== '' && str_ends_with($digits($c['phone']), substr($digits($ho['contact_phone']), -10))) { $cid = (int)$c['id']; break; }
    if (!$cid) $cid = insert('customers', ['user_id' => uid(), 'name' => $ho['contact_name'] ?: 'Customer', 'phone' => $ho['contact_phone'], 'email' => $ho['contact_email'], 'notes' => 'Added from their ' . APP_NAME . ' report.', 'created_at' => now()]);
    // The vehicle: same VIN in this shop's records, else a copy of the driver's details.
    $vehId = $v['vin'] ? (int)col('SELECT id FROM vehicle WHERE user_id=? AND vin=? LIMIT 1', [uid(), $v['vin']]) : 0;
    if ($vehId) update('vehicle', ['customer_id' => (int)(col('SELECT customer_id FROM vehicle WHERE id=?', [$vehId]) ?: $cid), 'mileage' => max((int)$v['mileage'], (int)col('SELECT mileage FROM vehicle WHERE id=?', [$vehId]))], $vehId);
    else {
        $vehId = insert('vehicle', ['user_id' => uid(), 'customer_id' => $cid, 'kind' => $v['kind'] ?: 'car', 'year' => $v['year'], 'make' => $v['make'], 'model' => $v['model'], 'trim' => $v['trim'],
            'engine' => $v['engine'], 'transmission' => $v['transmission'], 'vin' => $v['vin'], 'color' => $v['color'], 'plate' => $v['plate'], 'mileage' => (int)$v['mileage'],
            'mileage_updated' => $v['mileage_updated'] ?: today(), 'notes' => '', 'mods' => '', 'sort' => (int)col('SELECT COALESCE(MAX(sort),0)+1 FROM vehicle')]);
        seed_vehicle_templates($vehId, $v['kind'] ?: 'car');
    }
    // The estimate, in the customer's words first, then what their app recorded.
    $lines = [];
    foreach ($d['issues'] as $i) $lines[] = '• ' . $i['title'] . ' (' . (SEV_WORDS[max(1, min(5, (int)$i['severity']))] ?? '') . ($i['opened_on'] ? ', since ' . date('M j', strtotime((string)$i['opened_on'])) : '') . ')';
    if ($d['codes']) $lines[] = '• Warning codes: ' . implode(', ', array_map(fn($c) => $c . ' (' . obd_lookup($c)['title'] . ')', $d['codes']));
    if ($d['diag'] && trim((string)$d['diag']['notes']) !== '') $lines[] = '• Their notes: ' . trim((string)$d['diag']['notes']);
    $complaint = trim((string)$ho['concern']) . ($lines ? "\n\nFrom the customer's " . APP_NAME . " report:\n" . implode("\n", $lines) : '');
    $title = $ho['concern'] !== '' ? mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$ho['concern'])), 0, 60, '…') : ($d['issues'][0]['title'] ?? 'Estimate');
    $jid = insert('jobs', ['user_id' => uid(), 'customer_id' => $cid, 'vehicle_id' => $vehId, 'stage' => 'estimate', 'est_no' => next_number('est'), 'title' => $title, 'complaint' => $complaint,
        'odometer_in' => (int)$v['mileage'] ?: null, 'notes_internal' => "Customer's report: " . handoff_url($ho['token']),
        'labor_rate' => $s['labor_rate'], 'tax_parts' => $s['tax_parts'], 'tax_labor' => $s['tax_labor'], 'supplies_pct' => $s['supplies_pct'], 'supplies_cap' => $s['supplies_cap'],
        'discount' => 0, 'handoff_id' => $ho['id'], 'created_at' => now(), 'updated_at' => now()]);
    if ($d['codes']) { $all = []; foreach (canned_jobs() as $cj) $all[$cj['name']] = $cj; if (isset($all['Check engine light diagnosis'])) job_add_canned(row('SELECT * FROM jobs WHERE id=?', [$jid]), $all['Check engine light diagnosis'], 'Diagnosis'); }
    job_refresh($jid);
    update('handoffs', ['shop_user_id' => uid(), 'shop_name' => $s['name'] ?: 'Your shop', 'job_id' => $jid, 'imported_at' => now()], (int)$ho['id']);
    if (!$ho['imported_at']) push_send((int)$ho['user_id'], ($s['name'] ?: 'The shop') . ' got your report', 'They started an estimate for your ' . vehicle_name($v) . '.', url('send_shop'), false, 'handoff-' . $ho['id']);
    return $jid;
}

/**
 * The finished work back into the driver's garage (only when the driver allowed it): a "verified" visit with the work
 * and the cost, the odometer reading, and each declined recommendation as an open problem. Safe to run again: the
 * visit is updated, recommendations are not duplicated. Returns false when there is nothing to send to.
 */
function handoff_return(int $jobId): bool {
    $job = row('SELECT * FROM jobs WHERE id=?', [$jobId]);
    $ho = $job && $job['handoff_id'] ? row('SELECT * FROM handoffs WHERE id=? AND revoked=0 AND allow_return=1', [$job['handoff_id']]) : null;
    if (!$ho || (int)$job['user_id'] !== (int)$ho['shop_user_id']) return false;
    $vid = (int)$ho['vehicle_id']; $driver = (int)$ho['user_id'];
    $veh = row('SELECT * FROM vehicle WHERE id=? AND user_id=?', [$vid, $driver]);
    if (!$veh) return false;
    $S = shop((int)$job['user_id']); $shopName = $S['name'] ?: 'The shop';
    $lines = job_lines($jobId);
    $t = job_totals($job, $lines, 'approved');
    $done = []; $declined = [];
    foreach ($lines as $l) {
        if ($l['kind'] === 'note') continue;
        if ($l['status'] === 'approved') $done[$l['grp'] ?: 'Work'][] = $l;
        elseif ($l['status'] === 'declined') $declined[$l['grp'] ?: 'Recommended work'][] = $l;
    }
    $work = [];
    foreach ($done as $g => $ls) $work[] = $g . ': ' . implode('; ', array_map(fn($l) => trim($l['description'] . ($l['kind'] === 'part' && $l['brand'] . $l['part_no'] !== '' ? ' (' . trim($l['brand'] . ' ' . $l['part_no']) . ')' : '')), $ls));
    $insp = rows("SELECT * FROM job_inspect WHERE job_id=? AND rating IN ('now','soon') ORDER BY sort, id", [$jobId]);
    if ($insp) $work[] = 'Inspection notes: ' . implode('; ', array_map(fn($r) => $r['item'] . ' (' . ($r['rating'] === 'now' ? 'needs attention now' : 'recommended soon') . ($r['note'] ? ': ' . $r['note'] : '') . ')', $insp));
    if ($job['correction']) $work[] = 'Shop notes: ' . $job['correction'];
    // The shop as one of the driver's mechanics, so the next visit can be booked with them.
    $mech = (int)col('SELECT id FROM mechanics WHERE user_id=? AND name=? LIMIT 1', [$driver, $shopName]);
    if (!$mech) $mech = insert('mechanics', ['user_id' => $driver, 'name' => $shopName, 'phone' => $S['phone'] ?? '', 'email' => $S['email'] ?? '', 'address' => $S['address'] ?? '', 'notes' => 'Added when ' . $shopName . ' sent your service record.', 'is_primary' => 0]);
    $miles = (int)($job['odometer_out'] ?: $job['odometer_in']);
    $visit = ['vehicle_id' => $vid, 'mechanic_id' => $mech, 'scheduled_on' => substr((string)($job['invoiced_at'] ?: now()), 0, 10), 'status' => 'done', 'title' => $job['title'] ?: 'Service',
        'planned_work' => (string)$job['complaint'], 'completed_work' => implode("\n", $work), 'cost' => round((float)$t['total'], 2), 'parts_cost' => round((float)$t['parts'], 2), 'labor_cost' => round((float)$t['labor'], 2),
        'miles' => $miles ?: null, 'outcome' => (string)$job['cause'], 'signed_off' => 1, 'signed_at' => now(), 'verified_by' => $shopName, 'job_ref' => ($job['inv_no'] ? 'Invoice ' . $job['inv_no'] : $job['est_no'])];
    $visitId = $ho['visit_id'] && col('SELECT 1 FROM visits WHERE id=? AND vehicle_id=?', [$ho['visit_id'], $vid]) ? (int)$ho['visit_id'] : 0;
    if ($visitId) update('visits', $visit, $visitId); else $visitId = insert('visits', $visit + ['created_at' => now()]);
    foreach (handoff_ids($ho['issue_ids']) as $iid) if (col('SELECT 1 FROM issues WHERE id=? AND vehicle_id=?', [$iid, $vid]) && !col('SELECT 1 FROM visit_issues WHERE visit_id=? AND issue_id=?', [$visitId, $iid])) insert('visit_issues', ['visit_id' => $visitId, 'issue_id' => $iid]);
    if ($miles > (int)$veh['mileage']) { update('vehicle', ['mileage' => $miles, 'mileage_updated' => today()], $vid); insert('mileage_log', ['vehicle_id' => $vid, 'logged_at' => today(), 'miles' => $miles, 'note' => $shopName]); }
    // Declined recommendations become open problems, so the driver can come back for them (or get a second opinion).
    foreach ($declined as $g => $ls) {
        $title = 'Recommended by ' . $shopName . ': ' . $g;
        if (col("SELECT 1 FROM issues WHERE vehicle_id=? AND title=? AND status IN ('open','monitoring','scheduled')", [$vid, $title])) continue;
        $price = array_sum(array_map(fn($l) => (float)$l['qty'] * (float)$l['price'], $ls));
        $red = (bool)array_filter($insp, fn($r) => $r['rating'] === 'now' && (stripos($g, (string)$r['section']) !== false || stripos($g, (string)$r['item']) !== false));
        insert('issues', ['vehicle_id' => $vid, 'title' => $title, 'system' => 'Other', 'location' => 'other', 'severity' => $red ? 4 : 3, 'status' => 'open',
            'summary' => $shopName . ' recommended this on ' . fmt_date((string)($job['invoiced_at'] ?: now())) . ($price > 0 ? ' and quoted ' . money($price, $S) : '') . '. You said not now.',
            'evidence' => implode("\n", array_map(fn($l) => '- ' . $l['description'], $ls)), 'owner_guidance' => "Ask " . $shopName . " how soon it should be done, or get a second opinion.\nWhen it's done, mark this problem resolved.",
            'opened_on' => today(), 'from_shop' => $shopName, 'created_at' => now(), 'updated_at' => now()]);
    }
    update('handoffs', ['returned_at' => now(), 'visit_id' => $visitId], (int)$ho['id']);
    if (!$ho['returned_at']) push_send($driver, $shopName . ' sent your service record', ($job['title'] ?: 'Your service') . ' is in your history, marked verified.' . ($declined ? ' They also recommended: ' . implode(', ', array_keys($declined)) . '.' : ''), url('visits', ['v' => $vid]), false, 'handoff-' . $ho['id']);
    // A short note to the driver (only an address they gave the shop, only once).
    if (!$ho['returned_at'] && filter_var((string)$ho['contact_email'], FILTER_VALIDATE_EMAIL))
        send_mail((string)$ho['contact_email'], $shopName . ' sent your service record', '<div style="font:15px/1.5 -apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:520px">'
            . '<p>' . h($shopName) . ' finished <b>' . h($job['title'] ?: 'your service') . '</b> on your ' . h(vehicle_name($veh)) . ' and added it to your ' . h(APP_NAME) . ' service history, marked verified.</p>'
            . ($declined ? '<p>They also recommended: ' . h(implode(', ', array_keys($declined))) . '. It is on your list of open problems.</p>' : '')
            . '<p><a href="' . h(base_url() . '/' . url('visits')) . '" style="background:#e10600;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">See it in my garage</a></p></div>');
    return true;
}
