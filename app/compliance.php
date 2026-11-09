<?php
// State rules for repair paperwork (2026-11-08, roadmap #6). The shop picks its state once; estimates, work orders and
// invoices then carry what that state asks for, and settings warn about what it does not allow. California's
// "Write It Right" is the strictest model and covers most others. Not legal advice: the shop's own state agency has the
// final word, so every rule is shown in plain words with what the app does about it.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }

const PART_COND = ['new' => 'New', 'oem' => 'New (OEM)', 'aftermarket' => 'New (aftermarket)', 'used' => 'Used', 'rebuilt' => 'Rebuilt', 'reconditioned' => 'Reconditioned'];

/**
 * Per state: [registration label, rules shown to the shop, settings the app applies].
 * labor_tax: false = labor exempt when listed separately. supplies: false = no general "shop supplies" charge.
 * cond: part condition printed on every part. cert: mechanic name + certification number on invoices.
 * fl150: Florida's written-estimate choice block over $150. parts_back: offer the replaced parts.
 */
const STATE_RULES = [
    'CA' => ['ARD number (BAR)', ['Written estimate before any work; any increase needs documented approval first.', 'Every approval keeps how, when, who and the phone/email (done automatically).', 'Part condition on every part: new, used, rebuilt or reconditioned.', 'No general "shop supplies" charge: itemize hazardous-waste disposal fees with your EPA ID.', 'Labor is not taxed when listed separately.', 'Offer the replaced parts back (unless returned for core or warranty).', 'No abbreviations on customer documents.', 'Keep records 3 years, including texts and emails.'],
        ['labor_tax' => false, 'supplies' => false, 'cond' => true, 'cert' => false, 'fl150' => false, 'parts_back' => true]],
    'FL' => ['Motor vehicle repair registration number', ['A written estimate is required over $150 (diagnosis included); printed estimates carry the customer\'s three-option choice block (check the wording against FDACS guidance).', 'Storage charges may not start until 3 working days after you tell the customer the work is done.', 'Labor is taxable.'],
        ['labor_tax' => true, 'supplies' => true, 'cond' => true, 'cert' => false, 'fl150' => true, 'parts_back' => true]],
    'MI' => ['Facility registration number', ['Written estimate for repairs over $20 (some sources say $50: check with the Secretary of State).', 'The invoice lists each part as new, used, rebuilt or reconditioned.', "Each mechanic's name and certification number goes on the invoice.", 'The customer is entitled to the replaced parts.'],
        ['labor_tax' => false, 'supplies' => true, 'cond' => true, 'cert' => true, 'fl150' => false, 'parts_back' => true]],
    'NY' => ['DMV repair shop registration number', ['Written estimate on request; used parts must be disclosed.', 'Show the shop registration number on documents.'],
        ['labor_tax' => true, 'supplies' => true, 'cond' => true, 'cert' => false, 'fl150' => false, 'parts_back' => true]],
    'MD' => ['', ['You may not charge more than 10% over the estimate without the customer\'s consent.'], ['labor_tax' => false, 'supplies' => true, 'cond' => false, 'cert' => false, 'fl150' => false, 'parts_back' => true, 'auth_pct' => 10]],
    'OH' => ['', ['Some counties cap charges at 10% over the estimate without consent.'], ['labor_tax' => true, 'supplies' => true, 'cond' => false, 'cert' => false, 'fl150' => false, 'parts_back' => true, 'auth_pct' => 10]],
    'IL' => ['', ['The invoice itemizes parts and labor and shows the odometer and any warranty.'], ['labor_tax' => false, 'supplies' => true, 'cond' => false, 'cert' => false, 'fl150' => false, 'parts_back' => true]],
    'TX' => ['', ['Labor is not taxed when listed separately from parts; a lump-sum invoice can be taxed in full.'], ['labor_tax' => false, 'supplies' => true, 'cond' => false, 'cert' => false, 'fl150' => false, 'parts_back' => false]],
    'PA' => ['', ['Labor on vehicle repairs is taxable.'], ['labor_tax' => true, 'supplies' => true, 'cond' => false, 'cert' => false, 'fl150' => false, 'parts_back' => false]],
];
const STATE_GENERIC = ['Keep parts and labor on separate lines (some states tax a lump sum in full).', 'Get approval before doing more than the estimate; the app records each approval.', 'State tire fees are a separate line from your own disposal fee.'];

function migrate_compliance(PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (!in_array('cond', $cols('job_lines'), true)) $pdo->exec("ALTER TABLE job_lines ADD COLUMN cond TEXT DEFAULT 'new'");
    if (!in_array('parts_return', $cols('jobs'), true)) $pdo->exec("ALTER TABLE jobs ADD COLUMN parts_return TEXT");
    if (!in_array('cert_no', $cols('techs'), true)) $pdo->exec('ALTER TABLE techs ADD COLUMN cert_no TEXT');
}

/** The rules that apply to this shop: [rules text, flags]. Unknown / unset state: the generic list and California-safe defaults off. */
function shop_rules(array $S): array {
    $st = (string)($S['state'] ?? '');
    if (isset(STATE_RULES[$st])) return [STATE_RULES[$st][1], STATE_RULES[$st][2] + ['auth_pct' => null]];
    return [STATE_GENERIC, ['labor_tax' => null, 'supplies' => true, 'cond' => (bool)($S['show_cond'] ?? 0), 'cert' => false, 'fl150' => false, 'parts_back' => true, 'auth_pct' => null]];
}
/** What a setting is called in this state ("ARD number (BAR)" in California). */
function shop_reg_label(array $S): string { return (STATE_RULES[(string)($S['state'] ?? '')][0] ?? '') ?: 'Repair dealer / license no.'; }

/** Problems with the current settings for this state, in plain words (shown on Shop settings). */
function shop_rule_warnings(array $S): array {
    [, $f] = shop_rules($S); $w = [];
    if ($f['supplies'] === false && (float)$S['supplies_pct'] > 0) $w[] = 'Your state does not allow a general "shop supplies" charge. Set it to 0 and add disposal fees as their own line instead.';
    if ($f['labor_tax'] === false && (float)$S['tax_labor'] > 0) $w[] = 'Labor is normally not taxed in your state when it is listed separately. Check your labor tax rate.';
    if ($f['labor_tax'] === true && (float)$S['tax_labor'] <= 0) $w[] = 'Labor on repairs is normally taxable in your state. Check your labor tax rate.';
    if (in_array((string)($S['state'] ?? ''), ['CA', 'FL', 'MI', 'NY'], true) && trim((string)$S['license']) === '') $w[] = 'Add your ' . shop_reg_label($S) . ': it has to be on your estimates and invoices.';
    if (in_array((string)($S['state'] ?? ''), ['CA'], true) && trim((string)($S['epa_id'] ?? '')) === '') $w[] = 'Add your EPA ID number: it goes next to disposal fees.';
    return $w;
}

/* ---------- Comebacks (roadmap #9): rework linked to the original job ---------- */

const COMEBACK_REASONS = ['not_fixed' => 'Same problem, not fixed', 'part_failed' => 'Part failed (warranty)', 'workmanship' => 'Workmanship (something not done right)', 'related' => 'New problem related to the work', 'other' => 'Other'];
const COMEBACK_PAY = ['unpaid' => 'Not paid again (flat-rate rework is on the tech)', 'flagged' => 'Paid like any job (flagged hours count)'];

function migrate_comebacks(PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(jobs)')->fetchAll(), 'name');
    foreach (['comeback_of' => 'INT', 'comeback_reason' => 'TEXT'] as $c => $t) if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE jobs ADD COLUMN $c $t");
}
/** Comebacks opened in a period as a share of repair orders invoiced in it: ['n', 'ros', 'rate']. */
function comeback_rate(string $from, string $to): array {
    $n = (int)col('SELECT COUNT(*) FROM jobs WHERE user_id=? AND comeback_of IS NOT NULL AND created_at >= ? AND created_at < ?', [uid(), $from, $to]);
    $ros = (int)col("SELECT COUNT(*) FROM jobs WHERE user_id=? AND stage IN ('invoiced','paid') AND invoiced_at >= ? AND invoiced_at < ? AND comeback_of IS NULL", [uid(), $from, $to]);
    return ['n' => $n, 'ros' => $ros, 'rate' => $ros > 0 ? $n / $ros * 100 : null];
}

/* ---------- Cores and returns (roadmap #10): credit the shop is owed by its suppliers ---------- */

const RETURN_KINDS = ['core' => 'Core return', 'return' => 'Part return (wrong / not needed)', 'warranty' => 'Warranty return'];
const RETURN_STATUS = ['open' => 'To send back', 'sent' => 'Sent, waiting for credit', 'credited' => 'Credited', 'lost' => 'Written off'];
const RET_ACTIONS = ['ret_add', 'ret_status', 'ret_delete'];

/** Core charges on approved work become "send the old part back" items, once each. */
function returns_sync(): void {
    foreach (rows("SELECT l.*, j.id jid FROM job_lines l JOIN jobs j ON j.id=l.job_id WHERE j.user_id=? AND l.kind='core' AND l.status='approved' AND j.stage IN ('in_progress','waiting_parts','done','invoiced','paid')
        AND NOT EXISTS (SELECT 1 FROM part_returns r WHERE r.job_line_id=l.id)", [uid()]) as $l) {
        $vid = $l['supplier'] !== '' ? (int)col('SELECT id FROM vendors WHERE user_id=? AND name=? LIMIT 1', [uid(), $l['supplier']]) : 0;
        insert('part_returns', ['user_id' => uid(), 'kind' => 'core', 'description' => preg_replace('/\s*core charge\s*$/i', '', (string)$l['description']) . ' (old part)', 'part_no' => $l['part_no'],
            'vendor_id' => $vid ?: null, 'vendor' => $l['supplier'], 'job_id' => $l['jid'], 'job_line_id' => $l['id'], 'amount' => (float)$l['cost'] > 0 ? (float)$l['cost'] * (float)$l['qty'] : (float)$l['price'] * (float)$l['qty'],
            'status' => 'open', 'due_on' => date('Y-m-d', strtotime('+30 days')), 'created_at' => now()]);
    }
}
/** Open items and money still owed: ['items' => rows, 'owed' => $, 'overdue' => n]. */
function returns_open(): array {
    $items = rows("SELECT r.*, j.est_no, j.inv_no, COALESCE(v.name, r.vendor) vname FROM part_returns r LEFT JOIN jobs j ON j.id=r.job_id LEFT JOIN vendors v ON v.id=r.vendor_id
        WHERE r.user_id=? AND r.status IN ('open','sent') ORDER BY r.status, r.due_on", [uid()]);
    return ['items' => $items, 'owed' => array_sum(array_map(fn($r) => (float)$r['amount'], $items)), 'overdue' => count(array_filter($items, fn($r) => $r['due_on'] && $r['due_on'] < today()))];
}
