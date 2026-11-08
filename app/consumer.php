<?php
// Owner-facing extras (2026-11-08): health score, safety recalls (NHTSA), a shareable service record, weekly reminder emails.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

/** Columns these features need on databases created before them (guarded, so it runs once). Tables are in schema.sql. */
function migrate_consumer(PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('recalls_checked', $cols('vehicle'), true)) $pdo->exec('ALTER TABLE vehicle ADD COLUMN recalls_checked INT DEFAULT 0');
    $uc = $cols('users');
    foreach (['remind_weekly' => 'INT DEFAULT 0', 'remind_last' => 'TEXT', 'unsub_token' => 'TEXT'] as $c => $t)
        if (!in_array($c, $uc, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $c $t");
}

/* ---------- Health score ---------- */

/**
 * A 0-100 score for one vehicle, with the reasons behind it, so an owner sees at a glance how it is doing.
 * Open issues weigh by severity; overdue service, open recalls, active warning codes and a stale weekly check count too.
 */
function vehicle_health(int $vehId): array {
    $v = row('SELECT * FROM vehicle WHERE id=?', [$vehId]) ?? [];
    $miles = (int)($v['mileage'] ?? 0);
    $why = [];
    $issuePen = [1 => 2, 2 => 4, 3 => 10, 4 => 20, 5 => 35];
    foreach (rows("SELECT id, title, severity, status FROM issues WHERE vehicle_id=? AND status IN ('open','monitoring','scheduled')", [$vehId]) as $i) {
        $p = $issuePen[max(1, min(5, (int)$i['severity']))] * ($i['status'] === 'monitoring' ? 0.5 : 1);
        $why[] = ['label' => $i['title'], 'pts' => $p, 'url' => url('issue', ['id' => $i['id']]), 'kind' => 'issue'];
    }
    $over = $soon = 0;
    foreach (rows('SELECT * FROM maintenance WHERE vehicle_id=?', [$vehId]) as $m) {
        [$state] = maintenance_status($m, $miles);
        if ($state === 'overdue' && $over < 4) { $over++; $why[] = ['label' => $m['item'] . ' is overdue', 'pts' => 8, 'url' => url('maintenance', ['v' => $vehId]), 'kind' => 'service']; }
        elseif ($state === 'soon' && $soon < 3) { $soon++; $why[] = ['label' => $m['item'] . ' is due soon', 'pts' => 3, 'url' => url('maintenance', ['v' => $vehId]), 'kind' => 'service']; }
    }
    $rc = (int)col("SELECT COUNT(*) FROM recalls WHERE vehicle_id=? AND status='open'", [$vehId]);
    if ($rc) $why[] = ['label' => $rc . ' safety recall' . ($rc === 1 ? '' : 's') . ' to check', 'pts' => min(15, 5 * $rc), 'url' => url('recalls', ['v' => $vehId]), 'kind' => 'recall'];
    $codes = (int)col("SELECT COUNT(*) FROM code_events WHERE vehicle_id=? AND status='active'", [$vehId]);
    if ($codes) $why[] = ['label' => $codes . ' warning code' . ($codes === 1 ? '' : 's') . ' active', 'pts' => min(18, 6 * $codes), 'url' => url('codes', ['v' => $vehId]), 'kind' => 'code'];
    if ((int)col('SELECT COUNT(*) FROM checks WHERE vehicle_id=? AND active=1', [$vehId])) {
        $last = col('SELECT MAX(done_on) FROM inspections WHERE vehicle_id=?', [$vehId]);
        $age = $last ? -days_until((string)$last) : null;
        if ($age === null || $age > 14) $why[] = ['label' => $age === null ? 'No weekly check yet' : 'Weekly check is ' . $age . ' days old', 'pts' => 4, 'url' => url('inspection_new', ['v' => $vehId]), 'kind' => 'check'];
    }
    usort($why, fn($a, $b) => $b['pts'] <=> $a['pts']);
    $score = (int)max(0, min(100, round(100 - array_sum(array_column($why, 'pts')))));
    [$grade, $tone] = match (true) {
        $score >= 85 => ['Great shape', 'good'],
        $score >= 70 => ['Doing fine', 'ok'],
        $score >= 50 => ['Needs attention', 'warn'],
        default => ['Needs care now', 'bad'],
    };
    return ['score' => $score, 'grade' => $grade, 'tone' => $tone, 'reasons' => $why];
}

/** The score as an SVG ring. */
function health_ring(array $h, int $size = 64): string {
    $r = 26; $c = 2 * M_PI * $r; $off = $c * (1 - $h['score'] / 100);
    return '<svg class="hring hring-' . h($h['tone']) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" role="img" aria-label="Health ' . $h['score'] . ' out of 100: ' . h($h['grade']) . '">'
        . '<circle cx="32" cy="32" r="' . $r . '" class="hr-bg"/><circle cx="32" cy="32" r="' . $r . '" class="hr-fg" stroke-dasharray="' . round($c, 2) . '" stroke-dashoffset="' . round($off, 2) . '" transform="rotate(-90 32 32)"/>'
        . '<text x="32" y="37" text-anchor="middle">' . $h['score'] . '</text></svg>';
}

/* ---------- Safety recalls (NHTSA, US) ---------- */

/** NHTSA's recall list for a year/make/model. null when the service could not be reached. NHTSA spells some makes
 *  differently from vPIC (e.g. "MERCEDES BENZ"), so a few spellings are tried. */
function recall_lookup(array $v): ?array {
    $year = (int)($v['year'] ?? 0); $make = strtoupper(trim((string)($v['make'] ?? ''))); $model = trim((string)($v['model'] ?? ''));
    if ($year < 1950 || $make === '' || $model === '') return [];
    $makes = array_values(array_unique([$make, str_replace('-', ' ', $make), str_replace(' ', '-', $make)]));
    $models = array_values(array_unique(array_filter([$model, preg_replace('/\s+/', '', $model), strtok($model, ' ')])));
    $reached = false;
    $pairs = [];
    foreach ($makes as $mk) foreach ($models as $md) $pairs[] = [$mk, $md];
    if (function_exists('nhtsa_models')) $pairs = array_merge($pairs, nhtsa_models($year, $make, $model, 'r'));
    foreach ($pairs as [$mk, $md]) {
        $b = http_get('https://api.nhtsa.gov/recalls/recallsByVehicle?' . http_build_query(['make' => $mk, 'model' => $md, 'modelYear' => $year]), 6);
        $j = $b ? json_decode($b, true) : null;
        if (!is_array($j)) continue;
        $reached = true;
        if (!empty($j['results'])) return $j['results'];
    }
    return $reached ? [] : null;
}

/** Refresh a vehicle's recalls at most once a week (or now with $force). Returns the number of new recalls found. */
function recalls_refresh(int $vehId, bool $force = false): int {
    $v = row('SELECT * FROM vehicle WHERE id=?', [$vehId]);
    if (!$v || (!$force && (int)$v['recalls_checked'] > time() - 7 * 86400)) return 0;
    $list = recall_lookup($v);
    // Unreachable: try again in a day rather than on every page view.
    q('UPDATE vehicle SET recalls_checked=? WHERE id=?', [$list === null ? time() - 6 * 86400 : time(), $vehId]);
    if (!$list) return 0;
    $new = 0;
    foreach ($list as $r) {
        $camp = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($r['NHTSACampaignNumber'] ?? '')));
        if ($camp === '' || col('SELECT 1 FROM recalls WHERE vehicle_id=? AND campaign=?', [$vehId, $camp])) continue;
        $d = DateTime::createFromFormat('d/m/Y', (string)($r['ReportReceivedDate'] ?? ''));
        insert('recalls', ['vehicle_id' => $vehId, 'campaign' => $camp, 'component' => recall_component((string)($r['Component'] ?? '')),
            'summary' => (string)($r['Summary'] ?? ''), 'consequence' => (string)($r['Consequence'] ?? ''), 'remedy' => (string)($r['Remedy'] ?? ''),
            'report_date' => $d ? $d->format('Y-m-d') : null, 'park_it' => !empty($r['parkIt']) ? 1 : 0, 'status' => 'open', 'found_at' => now()]);
        $new++;
    }
    return $new;
}
/** "AIR BAGS:FRONTAL:PASSENGER SIDE:INFLATOR MODULE" -> "Air bags › Frontal › Passenger side › Inflator module". */
function recall_component(string $c): string {
    $parts = array_filter(array_map(fn($p) => ucfirst(strtolower(trim($p))), explode(':', $c)));
    return implode(' › ', $parts);
}
const RECALL_STATUSES = ['open' => 'To check', 'fixed' => 'Fixed', 'na' => "Doesn't apply to mine"];

/* ---------- Shareable service record ---------- */

function share_for(int $vehId): ?array { return row('SELECT * FROM shares WHERE vehicle_id=? AND revoked=0 ORDER BY id DESC LIMIT 1', [$vehId]); }
function share_create(int $vehId, bool $issues, bool $costs, bool $vin): string {
    q('UPDATE shares SET revoked=1 WHERE vehicle_id=?', [$vehId]);
    $t = bin2hex(random_bytes(16));
    insert('shares', ['vehicle_id' => $vehId, 'token' => $t, 'created_at' => now(), 'include_issues' => (int)$issues, 'include_costs' => (int)$costs, 'include_vin' => (int)$vin]);
    return $t;
}
function share_by_token(string $t): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $t)) return null;
    return row('SELECT * FROM shares WHERE token=? AND revoked=0', [$t]);
}
function share_url(string $token): string { return base_url() . '/' . url('record', ['t' => $token]); }

/* ---------- Weekly reminder email (multi-user instances with mail set up) ---------- */

function reminders_available(): bool { return MULTI_USER && MAIL_KEY !== '' && MAIL_FROM !== ''; }

/** What is due across one account's vehicles: [[vehicle name, [lines...]], ...]; empty when nothing needs doing. */
function reminder_digest(int $userId): array {
    $out = [];
    foreach (rows('SELECT * FROM vehicle WHERE user_id=? AND customer_id IS NULL ORDER BY sort, id', [$userId]) as $v) {
        $vid = (int)$v['id']; $lines = [];
        foreach (rows('SELECT * FROM maintenance WHERE vehicle_id=? ORDER BY priority, item', [$vid]) as $m) {
            [$state, , , $lbl] = maintenance_status($m, (int)$v['mileage']);
            if (in_array($state, ['overdue', 'soon'], true)) $lines[] = $m['item'] . ': ' . $lbl;
        }
        foreach (rows("SELECT title, severity FROM issues WHERE vehicle_id=? AND status IN ('open','scheduled') AND severity>=4 ORDER BY severity DESC", [$vid]) as $i)
            $lines[] = 'Open problem (' . SEVERITY[(int)$i['severity']] . '): ' . $i['title'];
        $rc = (int)col("SELECT COUNT(*) FROM recalls WHERE vehicle_id=? AND status='open'", [$vid]);
        if ($rc) $lines[] = $rc . ' safety recall' . ($rc === 1 ? '' : 's') . ' to check (free repair at a dealer)';
        foreach (rows("SELECT title, scheduled_on FROM visits WHERE vehicle_id=? AND status='planned' AND scheduled_on BETWEEN ? AND ?", [$vid, today(), date('Y-m-d', strtotime('+7 days'))]) as $vs)
            $lines[] = 'Mechanic visit ' . fmt_date($vs['scheduled_on']) . ': ' . $vs['title'];
        if ($v['mileage_updated'] && -days_until((string)$v['mileage_updated']) > 30) $lines[] = 'Mileage not updated since ' . fmt_date($v['mileage_updated']) . ' (countdowns may be off)';
        if ($lines) $out[] = [trim(($v['nickname'] ? $v['nickname'] . ' · ' : '') . vehicle_name($v)), $lines];
    }
    return $out;
}
function reminder_html(array $digest, string $unsubUrl): string {
    $b = base_url() . '/' . url('dashboard');
    $html = '<div style="font:15px/1.5 -apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111;max-width:560px">'
        . '<h2 style="margin:0 0 6px">What your vehicles need this week</h2>';
    foreach ($digest as [$name, $lines]) {
        $html .= '<h3 style="margin:18px 0 4px;font-size:16px">' . h($name) . '</h3><ul style="margin:0;padding-left:20px">';
        foreach ($lines as $l) $html .= '<li>' . h($l) . '</li>';
        $html .= '</ul>';
    }
    return $html . '<p style="margin-top:22px"><a href="' . h($b) . '" style="background:#e10600;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Open my garage</a></p>'
        . '<p style="color:#666;font-size:12px;margin-top:22px">You get this because weekly reminders are on in your ' . h(APP_NAME) . ' account. '
        . '<a href="' . h($unsubUrl) . '" style="color:#666">Turn them off</a>.</p></div>';
}
function unsub_token_for(int $userId): string {
    $t = (string)col('SELECT unsub_token FROM users WHERE id=?', [$userId]);
    if (!preg_match('/^[a-f0-9]{32}$/D', $t)) { $t = bin2hex(random_bytes(16)); q('UPDATE users SET unsub_token=? WHERE id=?', [$t, $userId]); }
    return $t;
}
