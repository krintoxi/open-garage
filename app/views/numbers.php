<?php
// Shop numbers with fixed definitions, next to the industry benchmarks owners already use (roadmap #8).
// Benchmarks: Tekmetric Shop Index (10k+ shops, through Sep 2026), AutoZone KPI handbook, Ratchet+Wrench; each says so.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'How you compare';
$S = shop();
$per = in_array($_GET['per'] ?? '', ['month', 'last', '90'], true) ? $_GET['per'] : 'month';
[$from, $to, $label, $months] = match ($per) {
    'last' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-01'), date('F Y', strtotime('first day of last month')), 1.0],
    '90' => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d', strtotime('+1 day')), 'Last 90 days', 3.0],
    default => [date('Y-m-01'), date('Y-m-d', strtotime('+1 day')), date('F Y') . ' so far', max(0.1, (int)date('j') / (int)date('t'))],
};
$jobs = rows("SELECT * FROM jobs WHERE user_id=? AND stage IN ('invoiced','paid') AND invoiced_at >= ? AND invoiced_at < ?", [uid(), $from, $to]);
$sales = 0; $labor = 0; $hours = 0; $parts = 0; $partsCost = 0; $profit = 0;
foreach ($jobs as $j) {
    $ls = job_lines((int)$j['id']); $t = job_totals($j, $ls, 'approved');
    $sales += $t['total'] - $t['tax']; $labor += $t['labor']; $hours += $t['hours']; $parts += $t['parts']; $profit += $t['profit'];
    foreach ($ls as $l) if ($l['kind'] === 'part' && $l['status'] === 'approved') $partsCost += (float)$l['qty'] * (float)$l['cost'];
}
$n = count($jobs);
// Closing ratio: of the work shown to customers on estimates in the period, how much (in $) they approved.
$cl = row("SELECT SUM(CASE WHEN l.status='approved' THEN l.qty*l.price ELSE 0 END) yes, SUM(CASE WHEN l.status IN ('approved','declined') THEN l.qty*l.price ELSE 0 END) shown
    FROM job_lines l JOIN jobs j ON j.id=l.job_id WHERE j.user_id=? AND j.sent_at >= ? AND j.sent_at < ? AND l.kind<>'note'", [uid(), $from, $to]) ?? [];
$close = (float)($cl['shown'] ?? 0) > 0 ? (float)$cl['yes'] / (float)$cl['shown'] * 100 : null;
// Technicians (only if they use the time clock).
$tr = time_report(strtotime($from), strtotime($to));
$worked = array_sum(array_column($tr, 'job')); $shift = array_sum(array_column($tr, 'shift')); $flag = array_sum(array_column($tr, 'flagged'));
$fs = followup_stats(max(30, (int)round((strtotime($to) - strtotime($from)) / 86400)));
$comeback = function_exists('comeback_rate') ? comeback_rate($from, $to) : null;
// [label, value text, numeric value, average, top, higher is better, definition, benchmark source]
$monthly = fn(float $v) => $v / $months;
$K = [
    ['Car count', number_format($n) . ($per === 'month' ? ' so far (' . number_format($monthly($n)) . ' a month at this pace)' : ($per === '90' ? ' (' . number_format($monthly($n)) . ' a month)' : '')), $monthly($n), 117, 177, true, 'Repair orders invoiced, per month.', 'Tekmetric Shop Index', 'n'],
    ['Average repair order', money($n ? $sales / $n : 0), $n ? $sales / $n : null, 713, 887, true, 'Sales (before tax) ÷ repair orders.', 'Tekmetric Shop Index', '$'],
    ['Effective labor rate', money($hours ? $labor / $hours : 0) . '/h', $hours ? $labor / $hours : null, 137, 155, true, 'Labor sold ÷ labor hours billed. Your posted rate is ' . money((float)$S['labor_rate']) . '/h.', 'Tekmetric Shop Index', '$'],
    ['Parts margin', $parts > 0 ? round(($parts - $partsCost) / $parts * 100) . '%' : '—', $parts > 0 ? ($parts - $partsCost) / $parts * 100 : null, 58, 65, true, '(Parts sold − parts cost) ÷ parts sold.', 'Tekmetric Shop Index', '%'],
    ['Hours per repair order', $n ? number_format($hours / $n, 1) : '—', $n ? $hours / $n : null, 2.9, 3.5, true, 'Labor hours billed ÷ repair orders.', 'AutoZone KPI handbook (2.5–3.25 average, 3.5+ target)', 'h'],
    ['Closing ratio', $close === null ? '—' : round($close) . '%', $close, 50, 60, true, 'Of the work you put on estimates, the share (in $) customers approved.', 'Ratchet+Wrench (50% good, 60%+ great)', '%'],
    ['Technician efficiency', $worked > 0 ? round($flag / $worked * 100) . '%' : '—', $worked > 0 ? $flag / $worked * 100 : null, 90, 100, true, 'Hours billed for a tech ÷ hours they were clocked on jobs (time clock).', 'AutoZone KPI handbook (80–99% average, 100%+ target)', '%'],
    ['Technician productivity', $shift > 0 ? round($worked / $shift * 100) . '%' : '—', $shift > 0 ? $worked / $shift * 100 : null, 85, 90, true, 'Hours clocked on jobs ÷ hours clocked in.', 'AutoZone KPI handbook (80–90% average, 90%+ target)', '%'],
    ['Declined work won back', $fs['rate'] === null ? '—' : $fs['rate'] . '%', $fs['rate'], 10, 25, true, 'Of the work customers declined, the share (in $) later booked.', 'Vendor figures (10–25% within 60–90 days)', '%'],
];
if ($comeback !== null) $K[] = ['Comebacks', $comeback['rate'] === null ? '—' : number_format($comeback['rate'], 1) . '%', $comeback['rate'], 3, 1, false, 'Repair orders that came back for the same problem within 30 days, as a share of repair orders.', 'Industry rule of thumb (under 3% good, over 6% poor)', '%'];
$status = function (?float $v, float $avg, float $top, bool $up): array {
    if ($v === null) return ['na', 'No data yet'];
    if ($up) return $v >= $top ? ['top', 'Top 25%'] : ($v >= $avg * 0.95 ? ['avg', 'Around average'] : ['low', 'Below average']);
    return $v <= $top ? ['top', 'Excellent'] : ($v <= $avg ? ['avg', 'Good'] : ($v <= $avg * 2 ? ['low', 'Watch it'] : ['low', 'Too high']));
};
$elrGap = $hours && (float)$S['labor_rate'] > 0 ? (1 - ($labor / $hours) / (float)$S['labor_rate']) * 100 : null;
?>
<div class="head"><div><h1>How you compare</h1><p>Your numbers next to what independent shops typically do. Same definitions the industry uses, so you can compare with your coach or your old system.</p></div>
  <div class="row" style="gap:6px"><?php foreach (['month' => 'This month', 'last' => 'Last month', '90' => '90 days'] as $k => $lb): ?><a class="btn sm <?= $per === $k ? 'primary' : '' ?>" href="<?= url('numbers', ['per' => $k]) ?>"><?= h($lb) ?></a><?php endforeach; ?></div></div>
<p class="muted" style="margin:-6px 0 14px"><?= h($label) ?> · <?= $n ?> invoiced repair order<?= $n === 1 ? '' : 's' ?> · <?= money($sales) ?> sales before tax · <?= money($profit) ?> gross profit on parts and sublet</p>
<?php if ($elrGap !== null && $elrGap > 15): ?><div class="callout" style="margin-bottom:14px">⚠️ Your effective labor rate is <?= round($elrGap) ?>% below your posted <?= money((float)$S['labor_rate']) ?>/h. More than 10–15% usually means labor is being discounted or billed short of book time.</div><?php endif; ?>
<div class="kpi-cmp">
<?php $fmtB = fn(float $x, string $f) => match ($f) { '$' => '$' . number_format($x), '%' => number_format($x) . '%', 'h' => number_format($x, 1), default => number_format($x) };
foreach ($K as [$lb, $txt, $v, $avg, $top, $up, $def, $src, $fmt]): [$cls, $word] = $status($v === null ? null : (float)$v, (float)$avg, (float)$top, $up); ?>
  <div class="card kc kc-<?= $cls ?>"><small><?= h($lb) ?></small><b><?= h($txt) ?></b>
    <span class="kc-tag"><?= h($word) ?></span>
    <p><?= $up ? 'Average ' . h($fmtB((float)$avg, $fmt)) . ' · top 25% ' . h($fmtB((float)$top, $fmt)) : 'Good: under ' . h($fmtB((float)$avg, $fmt)) ?></p>
    <small class="kc-def"><?= h($def) ?> <em><?= h($src) ?>.</em></small></div>
<?php endforeach; ?>
</div>
<p class="muted" style="font-size:12.5px;margin-top:12px">Benchmarks are US averages for independent shops (Tekmetric Shop Index covers 10,000+ shops through September 2026). Your mix of work, region and shop size matters more than any single number. Technician numbers need the time clock.</p>
<section class="card" style="margin-top:18px" id="accounting"><h2>Send to your bookkeeper</h2>
  <p class="muted" style="margin-top:-6px">Files QuickBooks Online and Xero can import, for <?= h(period_range($per === '90' ? '90' : ($per === 'last' ? 'last' : 'month'))[2]) ?>. <a href="<?= url('numbers', ['per' => $per]) ?>#accounting">Change the period</a> with the buttons at the top.</p>
  <div class="acct-btns"><?php foreach (ACCT_KINDS as $k => $lb): ?><a class="btn sm" href="<?= url('acct_csv', ['kind' => $k, 'per' => $per]) ?>">⬇ <?= h($lb) ?></a><?php endforeach; ?></div>
  <details style="margin-top:10px"><summary class="muted">How to import</summary><ul class="fp-ask">
    <li><b>QuickBooks Online:</b> create four products/services once (Labor, Parts, Fees, Sublet; add Core charge and Discount if you use them). Then ⚙ Settings → Import data → Customers (first time), then Invoices. Check the column matches when QuickBooks asks. QuickBooks works out sales tax itself from TAX / NON on each line; compare a few totals the first time.</li>
    <li><b>Xero:</b> Business → Invoices → Import, upload the file. Lines go to account 200 (Sales) with "Tax on Sales" or "Tax Exempt"; change those in the file if your chart of accounts differs.</li>
    <li><b>Payments</b> help match bank deposits; the <b>sales summary</b> has one line per invoice with labor, parts, tax, cost and profit for any accountant.</li></ul></details>
</section>

