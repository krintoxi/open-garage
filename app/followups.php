<?php
// Declined work follow-ups (2026-11-08, roadmap #4). Work a customer said "not now" to stays on a follow-up list with
// its price and inspection notes: a reminder 3 days later, then 30, then 60, then it goes quiet. It comes back on its
// own when the vehicle is in again, and "Book it" turns it into a new estimate. Recovery rate = how much came back.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const FU_ACTIONS = ['fu_contact', 'fu_book', 'fu_dismiss', 'fu_snooze'];
const FU_STEPS = [0 => 3, 1 => 30, 2 => 60];   // contacts so far => days until the next reminder; after the last one it goes quiet

function migrate_followups(PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(job_lines)')->fetchAll(), 'name');
    foreach (['fu_state' => 'TEXT', 'fu_next' => 'TEXT', 'fu_count' => 'INT DEFAULT 0', 'fu_last' => 'TEXT', 'fu_job' => 'INT', 'fu_since' => 'TEXT'] as $c => $t)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE job_lines ADD COLUMN $c $t");
}

/** Pick up newly declined lines (and ones approved after all). Cheap; run when the list or the board is shown. */
function followups_sync(): void {
    $u = uid();
    // Declined on a job that has moved on from the estimate: start the follow-up clock from the job's last change.
    foreach (rows("SELECT l.id, j.updated_at FROM job_lines l JOIN jobs j ON j.id=l.job_id WHERE j.user_id=? AND l.status='declined' AND l.kind<>'note' AND l.fu_state IS NULL AND j.stage NOT IN ('estimate','void')", [$u]) as $r) {
        $since = substr((string)$r['updated_at'], 0, 10) ?: today();
        q("UPDATE job_lines SET fu_state='open', fu_since=?, fu_next=?, fu_count=0 WHERE id=?", [$since, max(today(), date('Y-m-d', strtotime($since . ' +3 days'))), $r['id']]);
    }
    // Approved after all on the same job: that counts as recovered.
    q("UPDATE job_lines SET fu_state='won', fu_job=job_id WHERE fu_state='open' AND status='approved' AND job_id IN (SELECT id FROM jobs WHERE user_id=?)", [$u]);
}

/** Open follow-ups grouped by job + service: one entry per thing to ask the customer about. */
function followups_open(?int $vehicleId = null, bool $dueOnly = false): array {
    $p = [uid()]; $w = '';
    if ($vehicleId) { $w .= ' AND j.vehicle_id=?'; $p[] = $vehicleId; }
    if ($dueOnly) { $w .= ' AND l.fu_next<=?'; $p[] = today(); }
    $out = [];
    foreach (rows("SELECT l.*, j.id jid, j.est_no, j.inv_no, j.vehicle_id, j.customer_id, c.name cname, c.phone cphone, c.email cemail, v.year vy, v.make vm, v.model vmo
        FROM job_lines l JOIN jobs j ON j.id=l.job_id LEFT JOIN customers c ON c.id=j.customer_id LEFT JOIN vehicle v ON v.id=j.vehicle_id
        WHERE j.user_id=? AND l.fu_state='open'$w ORDER BY l.fu_next, j.id, l.sort, l.id", $p) as $l) {
        $k = $l['jid'] . '|' . ($l['grp'] ?: $l['description']);
        if (!isset($out[$k])) $out[$k] = ['key' => $k, 'jid' => (int)$l['jid'], 'grp' => $l['grp'] ?: $l['description'], 'est_no' => $l['inv_no'] ? 'Invoice ' . $l['inv_no'] : $l['est_no'],
            'vehicle_id' => (int)$l['vehicle_id'], 'customer_id' => (int)$l['customer_id'], 'cname' => $l['cname'], 'cphone' => $l['cphone'], 'cemail' => $l['cemail'],
            'car' => trim($l['vy'] . ' ' . $l['vm'] . ' ' . $l['vmo']), 'model' => (string)$l['vmo'], 'since' => $l['fu_since'], 'next' => $l['fu_next'], 'count' => (int)$l['fu_count'], 'last' => $l['fu_last'], 'amount' => 0.0, 'ids' => [], 'lines' => []];
        $out[$k]['amount'] += (float)$l['qty'] * (float)$l['price'];
        $out[$k]['ids'][] = (int)$l['id']; $out[$k]['lines'][] = $l['description'];
        if ($l['fu_next'] < $out[$k]['next']) $out[$k]['next'] = $l['fu_next'];
    }
    // The inspection finding behind it (red / yellow), when the service name matches an inspection section or item.
    foreach ($out as &$g) {
        $g['rating'] = '';
        foreach (rows("SELECT rating, section, item, note FROM job_inspect WHERE job_id=? AND rating IN ('now','soon')", [$g['jid']]) as $r)
            if (stripos($g['grp'], (string)$r['section']) !== false || stripos($g['grp'], (string)$r['item']) !== false || stripos((string)$r['item'], $g['grp']) !== false) { $g['rating'] = $r['rating'] === 'now' ? 'red' : 'yellow'; $g['finding'] = trim($r['item'] . ($r['note'] ? ': ' . $r['note'] : '')); if ($r['rating'] === 'now') break; }
    }
    return array_values($out);
}

/** Line ids from a posted group, limited to this shop's open follow-ups. */
function followup_ids(string $csv): array {
    $ids = array_values(array_filter(array_map('intval', explode(',', $csv))));
    if (!$ids) return [];
    return array_map('intval', array_column(rows('SELECT l.id FROM job_lines l JOIN jobs j ON j.id=l.job_id WHERE j.user_id=? AND l.fu_state=\'open\' AND l.id IN (' . implode(',', $ids) . ')', [uid()]), 'id'));
}

/** Book it: the declined lines as a new estimate for the same customer and vehicle (or onto an existing one). */
function followup_book(array $ids, ?int $intoJob = null): int {
    $lines = rows('SELECT * FROM job_lines WHERE id IN (' . implode(',', $ids) . ') ORDER BY sort, id');
    $src = row('SELECT * FROM jobs WHERE id=?', [$lines[0]['job_id']]);
    if ($intoJob) $jid = $intoJob;
    else {
        $s = shop();
        $jid = insert('jobs', ['user_id' => uid(), 'customer_id' => $src['customer_id'], 'vehicle_id' => $src['vehicle_id'], 'stage' => 'estimate', 'est_no' => next_number('est'),
            'title' => ($lines[0]['grp'] ?: 'Recommended work'), 'complaint' => 'Recommended on ' . ($src['inv_no'] ? 'invoice ' . $src['inv_no'] : $src['est_no']) . ' (' . fmt_date(substr((string)$src['updated_at'], 0, 10)) . ').',
            'odometer_in' => (int)col('SELECT mileage FROM vehicle WHERE id=?', [$src['vehicle_id']]) ?: null, 'labor_rate' => $s['labor_rate'], 'tax_parts' => $s['tax_parts'], 'tax_labor' => $s['tax_labor'],
            'supplies_pct' => $s['supplies_pct'], 'supplies_cap' => $s['supplies_cap'], 'discount' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }
    $sort = (int)col('SELECT COALESCE(MAX(sort),0) FROM job_lines WHERE job_id=?', [$jid]);
    $keep = ['grp', 'kind', 'description', 'part_no', 'brand', 'supplier', 'qty', 'cost', 'price', 'taxable', 'catalog_id'];
    foreach ($lines as $l) insert('job_lines', array_intersect_key($l, array_flip($keep)) + ['job_id' => $jid, 'status' => 'pending', 'pstatus' => 'quoted', 'sort' => ++$sort]);
    q("UPDATE job_lines SET fu_state='won', fu_job=? WHERE id IN (" . implode(',', $ids) . ')', [$jid]);
    job_refresh($jid);
    return $jid;
}

/** Recovery over the last N days: $ declined vs $ booked again. */
function followup_stats(int $days = 90): array {
    $since = date('Y-m-d', strtotime("-$days days"));
    $r = row("SELECT SUM(l.qty*l.price) total, SUM(CASE WHEN l.fu_state='won' THEN l.qty*l.price ELSE 0 END) won, SUM(CASE WHEN l.fu_state='open' THEN l.qty*l.price ELSE 0 END) open
        FROM job_lines l JOIN jobs j ON j.id=l.job_id WHERE j.user_id=? AND l.fu_state IS NOT NULL AND l.fu_since>=?", [uid(), $since]) ?? [];
    $t = (float)($r['total'] ?? 0); $w = (float)($r['won'] ?? 0);
    return ['total' => $t, 'won' => $w, 'open' => (float)($r['open'] ?? 0), 'rate' => $t > 0 ? round($w / $t * 100) : null];
}

/** The reminder text the shop sends from its own phone. */
function followup_text(array $g, array $S): string {
    $first = $g['cname'] ? strtok((string)$g['cname'], ' ') : '';
    return 'Hi' . ($first ? ' ' . $first : '') . ', it\'s ' . ($S['name'] ?: 'the shop') . '. When we had your ' . ($g['model'] ?: 'car') . ' in' . ($g['since'] ? ' on ' . date('M j', strtotime((string)$g['since'])) : '')
        . ', we recommended: ' . mb_strtolower($g['grp']) . ($g['amount'] > 0 ? ' (' . money($g['amount'], $S) . ')' : '') . '. Want us to book it?' . ($S['phone'] ? ' ' . $S['phone'] : '');
}
