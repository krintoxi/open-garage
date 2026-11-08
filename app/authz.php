<?php
// Customer authorization records to California BAR "Write It Right" standard (2026-11-08, roadmap #5), which also
// covers most other states: for every approval keep how it was given (signed, phone, text, email, online link), the
// date and time, the person's name and the phone number called or the email / texting number, the supporting message,
// what was approved, and which version of the estimate it was. Added work gets a new version and needs a new approval
// before the job can be finished or invoiced.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

function migrate_authz(PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(job_auth)')->fetchAll(), 'name');
    foreach (['contact' => 'TEXT', 'ver' => 'INT', 'lines' => 'TEXT', 'ip' => 'TEXT', 'msg' => 'TEXT'] as $c => $t) if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE job_auth ADD COLUMN $c $t");
    $pdo->exec('CREATE TABLE IF NOT EXISTS job_versions (id INTEGER PRIMARY KEY, job_id INT NOT NULL, ver INT NOT NULL, total REAL, lines TEXT, reason TEXT, created_at TEXT)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobver ON job_versions (job_id, ver)');
}

/** What each method must record. */
const AUTH_NEEDS = [
    'in_person' => ['Signed in person', 'Name of the person who signed', ''],
    'phone' => ['Phone call', 'Name of the person you spoke to', 'Phone number you called'],
    'text' => ['Text message', 'Name of the person', 'Their mobile number'],
    'email' => ['Email', 'Name of the person', 'Their email address'],
];

/**
 * Save the estimate as it stands as a numbered version (only when it changed since the last one). Returns the version.
 * The lines are kept as they were, so a later dispute can be checked against exactly what was approved.
 */
function job_snapshot(int $jobId, string $reason): int {
    $job = row('SELECT * FROM jobs WHERE id=?', [$jobId]);
    $lines = array_map(fn($l) => ['id' => (int)$l['id'], 'grp' => $l['grp'], 'kind' => $l['kind'], 'description' => $l['description'], 'qty' => (float)$l['qty'], 'price' => (float)$l['price'], 'status' => $l['status']],
        array_values(array_filter(job_lines($jobId), fn($l) => $l['status'] !== 'declined' && $l['kind'] !== 'note')));
    $total = round((float)job_totals($job, job_lines($jobId), 'quoted')['total'], 2);
    $last = row('SELECT * FROM job_versions WHERE job_id=? ORDER BY ver DESC LIMIT 1', [$jobId]);
    $sig = fn(array $ls) => md5(json_encode(array_map(fn($l) => [$l['grp'], $l['description'], $l['qty'], $l['price']], $ls)));
    if ($last && abs((float)$last['total'] - $total) < 0.005 && $sig(json_decode((string)$last['lines'], true) ?: []) === $sig($lines)) return (int)$last['ver'];
    $ver = $last ? (int)$last['ver'] + 1 : 1;
    insert('job_versions', ['job_id' => $jobId, 'ver' => $ver, 'total' => $total, 'lines' => json_encode($lines), 'reason' => $reason, 'created_at' => now()]);
    return $ver;
}

/** Services added after the customer approved the job that still need their OK. */
function job_added_pending(array $job): array {
    if (in_array($job['stage'], ['estimate', 'declined', 'void'], true)) return [];
    return array_values(array_unique(array_column(rows("SELECT grp FROM job_lines WHERE job_id=? AND status='pending' AND kind<>'note'", [$job['id']]), 'grp')));
}

/** One authorization as a line of text for the invoice / work order (everything BAR asks to be on record). */
function auth_line(array $a, ?array $S = null): string {
    $how = AUTH_NEEDS[$a['method']][0] ?? (AUTH_METHODS[$a['method']] ?? $a['method']);
    if ($a['method'] === 'link') $how = 'Approved online';
    return money((float)$a['amount'], $S) . ' approved by ' . ($a['by_name'] ?: '—') . ' · ' . $how . ' · ' . date('M j, Y g:i a', strtotime((string)$a['at']))
        . (!empty($a['contact']) ? ' · ' . $a['contact'] : '') . (!empty($a['ver']) ? ' · estimate v' . (int)$a['ver'] : '') . (!empty($a['lines']) ? ' · for: ' . $a['lines'] : '');
}
