<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Shop';
$S = shop();
$qq = trim((string)($_GET['q'] ?? ''));
$where = 'j.user_id=?'; $params = [uid()];
if ($qq !== '') { $where .= ' AND (j.est_no LIKE ? OR j.inv_no LIKE ? OR j.title LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR v.plate LIKE ? OR v.vin LIKE ? OR v.model LIKE ?)'; $params = array_merge($params, array_fill(0, 8, "%$qq%")); }
$all = rows("SELECT j.*, c.name cname, c.phone cphone, v.year vy, v.make vm, v.model vmo, v.plate vplate FROM jobs j LEFT JOIN customers c ON c.id=j.customer_id LEFT JOIN vehicle v ON v.id=j.vehicle_id
  WHERE $where AND (j.stage NOT IN ('paid','void','declined') OR j.updated_at > ?) ORDER BY j.updated_at DESC LIMIT 400", array_merge($params, [date('Y-m-d H:i:s', strtotime('-30 days'))]));
// Board columns: [label, stages, hint shown when the column is empty].
$cols = [
    'estimate' => ['Estimates', ['estimate'], 'New estimates show up here.'],
    'approved' => ['Approved', ['approved'], 'Jobs the customer said yes to, ready to start.'],
    'shop' => ['In the shop', ['in_progress', 'waiting_parts'], 'Cars being worked on.'],
    'done' => ['Ready for pickup', ['done'], 'Finished work waiting for the customer.'],
    'invoiced' => ['Waiting for payment', ['invoiced'], 'Invoices not paid yet.'],
    'paid' => ['Paid', ['paid'], 'Paid in the last 30 days.'],
];
$by = []; foreach ($all as $j) foreach ($cols as $k => [, $st]) if (in_array($j['stage'], $st, true)) $by[$k][] = $j;
// This month at a glance (industry KPIs: sales, car count, average repair order, gross profit).
$month = rows("SELECT * FROM jobs WHERE user_id=? AND stage IN ('invoiced','paid') AND invoiced_at >= ?", [uid(), date('Y-m-01')]);
$sales = 0; $gp = 0; $hours = 0; $labor = 0;
foreach ($month as $mj) { $t = job_totals($mj, job_lines((int)$mj['id']), 'approved'); $sales += $t['total'] - $t['tax']; $gp += $t['profit']; $hours += $t['hours']; $labor += $t['labor']; }
$cars = count($month); $ar = (float)col("SELECT COALESCE(SUM(total-paid),0) FROM jobs WHERE user_id=? AND stage='invoiced'", [uid()]);
$sent = (int)col("SELECT COUNT(*) FROM jobs WHERE user_id=? AND sent_at >= ?", [uid(), date('Y-m-01')]); $won = (int)col("SELECT COUNT(*) FROM jobs WHERE user_id=? AND sent_at >= ? AND approved_at IS NOT NULL", [uid(), date('Y-m-01')]);
$news = rows("SELECT j.id, j.est_no, j.approved_by, j.stage, j.title, j.total, c.name cname FROM jobs j LEFT JOIN customers c ON c.id=j.customer_id WHERE j.user_id=? AND j.seen_approval=0 ORDER BY j.updated_at DESC", [uid()]);
$customers = rows('SELECT id, name FROM customers WHERE user_id=? ORDER BY name', [uid()]);
$anyJobs = (bool)col('SELECT 1 FROM jobs WHERE user_id=?', [uid()]);
$nReq = (int)col("SELECT COUNT(*) FROM appointments WHERE user_id=? AND status='requested'", [uid()]);

$plural = fn(int $n, string $one, string $many = '') => $n . ' ' . ($n === 1 ? $one : ($many ?: $one . 's'));
$onClock = rows('SELECT t.id tid, t.name, t.color, e.kind, e.job_id FROM time_entries e JOIN techs t ON t.id=e.tech_id WHERE e.user_id=? AND e.ended_at IS NULL ORDER BY e.kind DESC', [uid()]);
// Who is assigned to / clocked on each job on the board, for the little avatars on the cards.
$techById = []; foreach (techs(false) as $t) $techById[(int)$t['id']] = $t;
$jobIds = array_map(fn($j) => (int)$j['id'], $all);
$assigned = []; $onIt = [];
if ($jobIds) foreach (rows('SELECT DISTINCT job_id, tech_id FROM job_lines WHERE tech_id IS NOT NULL AND job_id IN (' . implode(',', $jobIds) . ')') as $r) $assigned[(int)$r['job_id']][(int)$r['tech_id']] = 1;
foreach ($onClock as $oc) if ($oc['kind'] === 'job' && $oc['job_id']) { $onIt[(int)$oc['job_id']][] = $oc['name']; $assigned[(int)$oc['job_id']][(int)$oc['tid']] = 1; }
$initials = fn(string $n) => mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($n)) ?: [], 0, 2))));
// Texting / calling the customer from the phone the shop already uses (nothing is sent by the app).
$digits = fn(?string $p) => preg_replace('/[^\d+]/', '', (string)$p);
$readyText = fn(array $j) => 'Hi' . ($j['cname'] ? ' ' . strtok((string)$j['cname'], ' ') : '') . ', this is ' . ($S['name'] ?: 'the shop') . '. Your ' . trim($j['vy'] . ' ' . $j['vm'] . ' ' . $j['vmo']) . ' is ready for pickup' . ((float)$j['total'] > 0 ? '. Total: ' . money((float)$j['total']) : '') . '.' . ($S['phone'] ? ' ' . $S['phone'] : '');
$contact = fn(array $j, bool $text) => $digits($j['cphone']) === '' ? '' : '<a class="btn sm" href="tel:' . h($digits($j['cphone'])) . '">📞 Call</a>' . ($text ? '<a class="btn sm" href="sms:' . h($digits($j['cphone'])) . '?&amp;body=' . h(rawurlencode($readyText($j))) . '">💬 Text</a>' : '');
$car = fn(array $j) => trim($j['vy'] . ' ' . $j['vm'] . ' ' . $j['vmo']) ?: 'vehicle';
$who = fn(array $j) => $j['cname'] ?: 'the walk-in';
$ago = function (?string $t): string {
    if (!$t || !($s = strtotime($t))) return ''; $d = time() - $s;
    return $d < 3600 ? max(1, (int)($d / 60)) . ' min ago' : ($d < 86400 ? (int)($d / 3600) . ' h ago' : ($d < 172800 ? 'yesterday' : (int)($d / 86400) . ' days ago'));
};
$hr = (int)date('G');
$hello = $hr < 5 ? 'Hi' : ($hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening'));
$me = current_user(); if (!empty($me['name']) && !is_guest()) $hello .= ', ' . strtok((string)$me['name'], ' ');

// One sentence about the board.
$nShop = count($by['shop'] ?? []); $nReady = count($by['done'] ?? []); $nEst = count($by['estimate'] ?? []); $nAppr = count($by['approved'] ?? []);
$bits = array_values(array_filter([
    $nShop ? $plural($nShop, 'car') . ' in the shop' : '',
    $nReady ? $nReady . ' ready for pickup' : '',
    $nAppr ? $nAppr . ' approved and waiting to start' : '',
    $nEst ? $plural($nEst, 'estimate') . ' out' : '',
]));
$sentence = $bits ? ucfirst(count($bits) > 1 ? implode(', ', array_slice($bits, 0, -1)) . ' and ' . end($bits) : $bits[0]) . '.' : ($anyJobs ? 'A quiet board right now.' : 'Your board is empty. Write your first estimate below.');
if ($ar > 0) $sentence .= ' ' . money($ar) . ' is owed to you.';

// "Needs you": things only the front desk can move along, most urgent first.
$todo = [];
foreach ($news as $n) {
    $nm = $n['approved_by'] ?: ($n['cname'] ?: 'The customer');
    if ($n['stage'] === 'declined') $todo[] = ['✋', $nm . ' declined ' . $n['est_no'], ($n['title'] ?: 'The estimate') . '. Ask if they want part of it, or close it out.', url('job', ['id' => $n['id']]), 'Open', ''];
    elseif (in_array($n['stage'], ['approved', 'in_progress', 'waiting_parts'], true)) $todo[] = ['🎉', $nm . ' approved ' . $n['est_no'], ($n['title'] ?: 'The estimate') . ($n['total'] > 0 ? ' · ' . money((float)$n['total']) : '') . '. Order the parts and get it started.', url('job', ['id' => $n['id']]), 'Open', 'ok'];
    else $todo[] = ['💬', $nm . ' replied to ' . $n['est_no'], $n['title'] ?: 'Open it to see their answer.', url('job', ['id' => $n['id']]), 'Open', 'ok'];
}
followups_sync(); $fuDue = followups_open(null, true);
if ($fuDue) $todo[] = ['↻', 'Follow up with ' . $plural(count(array_unique(array_column($fuDue, 'customer_id'))), 'customer') . ' on declined work', money((float)array_sum(array_column($fuDue, 'amount'))) . ' of recommended work they said "not now" to. A quick call or text brings some back.', url('followups'), 'Follow up', ''];
foreach (array_merge($by['approved'] ?? [], $by['shop'] ?? []) as $j) if ($ap = job_added_pending($j)) $todo[] = ['✋', 'Get ' . $who($j) . "'s OK for added work", $j['est_no'] . ': ' . implode(', ', $ap) . '. It cannot be finished or invoiced until they approve it.', url('job', ['id' => $j['id']]) . '#auth', 'Get approval', 'warn'];
if ($nReq) $todo[] = ['📅', 'Answer ' . ($nReq === 1 ? 'a booking request' : $nReq . ' booking requests'), 'Someone asked for a time online. Accept it, or call to suggest another.', url('calendar'), 'Answer', 'warn'];
foreach (array_slice($by['done'] ?? [], 0, 3) as $j) $todo[] = ['📞', 'Tell ' . $who($j) . ' the ' . $j['vmo'] . ' is ready', $j['est_no'] . ' · ' . ($j['title'] ?: 'work done') . ($j['cphone'] ? ' · ' . $j['cphone'] : ' · no phone on file') . '. Invoice it at pickup.', url('job', ['id' => $j['id']]), 'Open', '', $contact($j, true)];
$toOrder = count(parts_to_order());
if ($toOrder) $todo[] = ['🛒', 'Order ' . $plural($toOrder, 'part'), 'Approved jobs are waiting on them.', url('purchasing'), 'Order', ''];
foreach ($by['estimate'] ?? [] as $j) {
    if (!$j['sent_at']) $todo[] = ['✉️', 'Send ' . $who($j) . ' the estimate', $j['est_no'] . ' · ' . ($j['title'] ?: 'no services yet') . ((float)$j['total'] <= 0 ? '. Add services and prices first.' : ' · ' . money((float)$j['total']) . '.'), url('job', ['id' => $j['id']]), (float)$j['total'] <= 0 ? 'Finish' : 'Send', ''];
    elseif (strtotime((string)$j['sent_at']) < time() - 2 * 86400) $todo[] = ['📞', 'Follow up with ' . $who($j), $j['est_no'] . ' was sent ' . $ago($j['sent_at']) . ' and has no answer yet.', url('job', ['id' => $j['id']]), 'Open', ''];
}
foreach (array_slice($by['invoiced'] ?? [], 0, 3) as $j) $todo[] = ['💵', 'Collect ' . money((float)$j['total'] - (float)$j['paid']) . ' from ' . $who($j), ($j['inv_no'] ? 'Invoice ' . $j['inv_no'] : $j['est_no']) . ' · ' . $ago($j['invoiced_at'] ?: $j['updated_at']) . '.', url('job', ['id' => $j['id']]) . '#pay', 'Take payment', ''];
$todoShow = 4;
$taskLi = fn(array $t) => '<li class="task ' . h($t[5]) . '"><a href="' . $t[3] . '"><span class="task-ico" aria-hidden="true">' . h($t[0]) . '</span><span class="task-txt"><b>' . h($t[1]) . '</b><small>' . h($t[2]) . '</small></span><span class="task-go">' . h($t[4]) . ' <i aria-hidden="true">›</i></span></a>' . (!empty($t[6]) ? '<div class="task-acts">' . $t[6] . '</div>' : '') . '</li>';
// Which column a phone opens on: the busiest part of the day first.
$tab = isset($_GET['tab'], $cols[$_GET['tab']]) ? (string)$_GET['tab'] : 'shop';
if (!isset($_GET['tab'])) foreach (['shop', 'approved', 'done', 'estimate', 'invoiced', 'paid'] as $k) if (!empty($by[$k])) { $tab = $k; break; }
$stageNote = ['waiting_parts' => ['Waiting for parts', 'soon'], 'in_progress' => ['Working on it', 'ok']];
?>
<div class="shopb">
<div class="sb-head">
  <div class="sb-hello"><small><?= h($hello) ?> 👋</small><h1><?= h($S['name'] ?: 'Your shop') ?></h1><p><?= h($sentence) ?></p></div>
  <form method="get" class="sb-find" role="search"><input type="hidden" name="p" value="jobs"><input type="search" name="q" value="<?= h($qq) ?>" placeholder="Find a job: name, plate, RO #, VIN" aria-label="Find a job"><button class="sm">Find</button></form>
</div>
<?php if ($qq !== ''): ?><p class="sb-found"><?= $plural(count($all), 'job') ?> matching “<?= h($qq) ?>”. <a href="<?= url('jobs') ?>">Show everything</a></p><?php endif; ?>

<?php $steps = shop_setup_steps(); $req = array_filter($steps, fn($x) => !$x[3]); $doneN = count(array_filter($req, fn($x) => $x[1]));
if ($doneN < count($req) && empty($S['setup_hidden'])): ?>
<div class="card setup-card">
  <div class="row" style="justify-content:space-between;gap:10px"><h2 style="margin:0">Let's get your shop ready</h2><span class="muted"><?= $doneN ?> of <?= count($req) ?> done</span></div>
  <div class="setup-bar"><i style="width:<?= round($doneN / max(1, count($req)) * 100) ?>%"></i></div>
  <ol class="setup-steps"><?php foreach ($steps as [$lbl, $ok, $href, $opt]): ?><li class="<?= $ok ? 'done' : '' ?>"><a href="<?= h($href) ?>"><span class="tick"><?= $ok ? '✓' : '' ?></span><?= h($lbl) ?><?= $opt ? ' <small class="muted">(optional)</small>' : '' ?><?= $ok ? '' : ' <b>→</b>' ?></a></li><?php endforeach; ?></ol>
  <form method="post" style="margin-top:6px"><input type="hidden" name="action" value="setup_hide"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button class="sm ghost">Hide this</button></form>
</div>
<?php endif; ?>

<?php if ($anyJobs && $qq === ''): ?>
<section class="card sb-needs">
  <h2>Needs you</h2>
  <?php if (!$todo): ?>
    <div class="all-good"><span>👌</span><div><b>Nothing waiting on you.</b><small>No replies, no cars ready and no unpaid invoices. Nice.</small></div></div>
  <?php else: ?>
    <ul class="tasks"><?php foreach (array_slice($todo, 0, $todoShow) as $t) echo $taskLi($t); ?></ul>
    <?php if (count($todo) > $todoShow): ?><details class="more-tasks"><summary>Show <?= count($todo) - $todoShow ?> more</summary><ul class="tasks"><?php foreach (array_slice($todo, $todoShow, 12) as $t) echo $taskLi($t); ?></ul></details><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php $todayA = appts(today() . ' 00:00', date('Y-m-d', strtotime('+1 day')) . ' 00:00'); $todayA = array_values(array_filter($todayA, fn($x) => !in_array($x['status'], ['cancelled', 'no_show'], true)));
 ?>
<?php if ($qq === ''): ?>
<div class="card today-strip">
  <div><h3>Today's appointments <a class="muted" href="<?= url('calendar') ?>">Calendar ›</a></h3>
    <?php if (!$todayA): ?><p class="muted" style="margin:0;font-size:13.5px">Nobody booked today. <a href="<?= url('calendar', ['new' => 1]) ?>">Add an appointment</a>.</p><?php endif; ?>
    <div class="today-list"><?php foreach ($todayA as $ta): ?><a href="<?= $ta['job_id'] ? url('job', ['id' => $ta['job_id']]) : url('calendar', ['a' => $ta['id']]) ?>" style="--tc:<?= h($ta['tcolor'] ?: '#5e667a') ?>"><b><?= h(date('g:i', strtotime((string)$ta['starts_at']))) ?></b> <?= h(appt_who($ta)) ?><small><?= h(appt_vehicle($ta)) ?><?= $ta['status'] === 'arrived' ? ' · here now' : '' ?></small></a><?php endforeach; ?></div></div>
  <div><h3>Who's working <a class="muted" href="<?= url('clock') ?>">Time clock ›</a></h3>
    <?php $seen = []; $any = false; foreach ($onClock as $oc): if (isset($seen[$oc['name']])) continue; $seen[$oc['name']] = 1; $any = true; ?><span class="clock-chip" style="--tc:<?= h($oc['color'] ?: '#5e667a') ?>"><?= h($oc['name']) ?><?= $oc['kind'] === 'job' ? ' · on ' . h((string)col('SELECT est_no FROM jobs WHERE id=?', [$oc['job_id']])) : ' · free' ?></span><?php endforeach; ?>
    <?php if (!$any): ?><p class="muted" style="margin:0;font-size:13.5px">Nobody clocked in yet.</p><?php endif; ?></div>
</div>
<?php endif; ?>

<details class="card new-ro" <?= isset($_GET['new']) || !$anyJobs ? 'open' : '' ?>><summary><span class="nr-plus">＋</span><span><b>New estimate</b><small>For a customer at the counter, on the phone or a walk-in</small></span></summary>
  <form method="post" class="form-grid" style="margin-top:12px"><input type="hidden" name="action" value="job_new"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <div><label>Customer</label><select name="customer_id" data-cust-pick><option value="">— walk-in / none —</option><?php foreach ($customers as $cu): ?><option value="<?= (int)$cu['id'] ?>" <?= (int)($_GET['customer'] ?? 0) === (int)$cu['id'] ? 'selected' : '' ?>><?= h($cu['name']) ?></option><?php endforeach; ?></select><small><a href="<?= url('customers', ['c' => 'new']) ?>#cform">+ new customer</a></small></div>
    <div><label>Vehicle</label><select name="vehicle_id"><option value="">—</option><?php foreach (rows('SELECT v.*, c.name cname FROM vehicle v LEFT JOIN customers c ON c.id=v.customer_id WHERE v.user_id=? ORDER BY c.name, v.year', [uid()]) as $av): ?><option value="<?= (int)$av['id'] ?>" data-cust="<?= (int)$av['customer_id'] ?>" <?= (int)($_GET['vehicle'] ?? 0) === (int)$av['id'] ? 'selected' : '' ?>><?= h(vehicle_name($av)) ?><?= $av['cname'] ? ' · ' . h($av['cname']) : ' · mine' ?><?= $av['plate'] ? ' · ' . h($av['plate']) : '' ?></option><?php endforeach; ?></select></div>
    <div class="full"><label>What's the problem? (in the customer's words)</label><input type="text" name="complaint" placeholder="e.g. Grinding noise when braking, check engine light on"></div>
    <div><label>Odometer in</label><input type="number" name="odometer_in" inputmode="numeric"></div>
    <div><label>Start from a common job (optional)</label><select name="canned_id"><option value="">—</option><?php $lastc = null; foreach (canned_jobs() as $cj): if ($cj['category'] !== $lastc) { if ($lastc !== null) echo '</optgroup>'; $lastc = $cj['category']; echo '<optgroup label="' . h($lastc) . '">'; } ?><option value="<?= (int)$cj['id'] ?>"><?= h($cj['name']) ?></option><?php endforeach; echo $lastc !== null ? '</optgroup>' : ''; ?></select></div>
    <div class="full"><button class="primary">Create estimate</button></div>
  </form></details>

<?php if (!$anyJobs): ?><div class="card empty-hero"><span>🧾</span><h2>No jobs yet</h2><p class="muted">Every job moves across your board: estimate, approved, in the shop, ready, paid. Start one above, or check in a customer from <a href="<?= url('calendar') ?>">Appointments</a>.</p></div>
<?php else: ?>
<section class="sb-board">
  <div class="board-tabs" role="tablist" aria-label="Board columns"><?php foreach ($cols as $k => [$label]): $n = count($by[$k] ?? []); ?><a href="<?= url('jobs', array_filter(['tab' => $k, 'q' => $qq])) ?>" role="tab" data-tab="<?= $k ?>" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>" class="<?= $n ? '' : 'none' ?>"><?= h($label) ?><b><?= $n ?></b></a><?php endforeach; ?></div>
  <div class="board" data-board data-show="<?= h($tab) ?>" data-ver="<?= h(board_version()) ?>" data-ver-url="<?= url('board_ver') ?>">
  <?php foreach ($cols as $k => [$label, , $hint]): $items = $by[$k] ?? []; ?>
  <div class="bcol bcol-<?= $k ?>" data-col="<?= $k ?>" data-col-label="<?= h($label) ?>"><h3><?= h($label) ?> <span class="muted"><?= count($items) ?></span></h3>
    <?php foreach ($items as $j): $jid = (int)$j['id']; $owed = (float)$j['total'] - (float)$j['paid'];
      // The one obvious next step for this card, and the columns it may be dragged to (the same moves the RO page offers).
      [$nxLabel, $nxTo, $nxUrl] = match ($j['stage']) {
          'estimate' => (float)$j['total'] <= 0 ? ['Add services', null, url('job', ['id' => $jid])] : (!$j['sent_at'] ? ['Send to customer', null, url('job', ['id' => $jid]) . '#send'] : ['Mark approved', 'approved', null]),
          'approved' => ['Start work', 'in_progress', null],
          'waiting_parts' => ['Parts are here', 'in_progress', null],
          'in_progress' => ['Work done', 'done', null],
          'done' => ['Create invoice', 'invoiced', null],
          'invoiced' => ['Take payment', null, url('job', ['id' => $jid]) . '#pay'],
          default => [null, null, null],
      };
      $addedP = job_added_pending($j);
      if ($addedP) [$nxLabel, $nxTo, $nxUrl] = ['Get approval', null, url('job', ['id' => $jid]) . '#auth'];
      $moves = $addedP ? [] : match ($j['stage']) { 'estimate' => ['approved' => 'approved'], 'approved' => ['shop' => 'in_progress'], 'in_progress' => ['done' => 'done'], 'waiting_parts' => [], 'done' => ['invoiced' => 'invoiced', 'shop' => 'in_progress'], default => [] };
      $over = '';
      if ($j['stage'] === 'done') { $as = job_auth_state($j, job_lines($jid)); if ($as['over']) $over = 'The total is over what the customer authorized. Record the extra authorization on the RO first unless you already have it. Create the invoice anyway?'; }
      $hrs = null; if ($k === 'shop') { $sold = (float)job_totals($j, job_lines($jid), 'approved')['hours']; $clk = array_sum(job_clocked($jid)); if ($sold > 0 || $clk > 0) $hrs = [$clk, $sold]; }
      $techs = array_values(array_filter(array_map(fn($tid) => $techById[$tid] ?? null, array_keys($assigned[$jid] ?? []))));
    ?>
      <div class="ro-card" data-job="<?= $jid ?>"<?= $moves ? ' draggable="true" data-moves="' . h(json_encode($moves)) . '"' : '' ?><?= $over ? ' data-over="' . h($over) . '"' : '' ?>>
        <a class="ro-link" draggable="false" href="<?= url('job', ['id' => $jid]) ?>" aria-label="Open <?= h(($j['cname'] ?: 'walk-in') . ' ' . $j['est_no']) ?>"></a>
        <span class="ro-top"><span class="ro-no"><?= h(in_array($j['stage'], ['invoiced', 'paid'], true) && $j['inv_no'] ? 'Invoice ' . $j['inv_no'] : $j['est_no']) ?></span><span class="ro-when"><?= h($ago($j['updated_at'])) ?></span></span>
        <b><?= h($j['cname'] ?: 'Walk-in') ?></b>
        <small><?= h($car($j)) ?><?= $j['vplate'] ? ' · ' . h($j['vplate']) : '' ?></small>
        <?php if ($j['title']): ?><small class="ro-title"><?= h(mb_strimwidth((string)$j['title'], 0, 70, '…')) ?></small><?php endif; ?>
        <span class="ro-tags">
          <?php if (!(int)$j['seen_approval']): ?><i class="tag tag-ok">New reply</i><?php endif; ?>
          <?php if ($addedP): ?><i class="tag tag-red">✋ Added work needs OK</i><?php endif; ?>
          <?php if (!empty($onIt[$jid])): ?><i class="tag tag-live">● <?= h(implode(' & ', array_unique($onIt[$jid]))) ?> on it</i><?php elseif ($k === 'shop' && isset($stageNote[$j['stage']])): ?><i class="tag tag-<?= $stageNote[$j['stage']][1] ?>"><?= $stageNote[$j['stage']][0] ?></i><?php endif; ?>
          <?php if ($k === 'estimate'): ?><i class="tag"><?= $j['sent_at'] ? 'Sent ' . h($ago($j['sent_at'])) : 'Not sent yet' ?></i><?php endif; ?>
          <?php if ($j['promised'] && !in_array($k, ['invoiced', 'paid'], true)): ?><i class="tag tag-soon">⏰ <?= h($j['promised']) ?></i><?php endif; ?>
        </span>
        <?php if ($hrs): [$clk, $sold] = $hrs; $pct = $sold > 0 ? min(100, round($clk / $sold * 100)) : 100; ?>
          <span class="ro-hrs" title="Clocked on this job vs labor hours sold"><span class="ro-bar<?= $sold > 0 && $clk > $sold ? ' over' : '' ?>"><i style="width:<?= $pct ?>%"></i></span><small><?= round($clk, 1) ?> of <?= round($sold, 1) ?> h</small></span>
        <?php endif; ?>
        <span class="ro-foot">
          <span class="ro-amt"><?= (float)$j['total'] > 0 ? money((float)$j['total']) : '<span class="muted">No price yet</span>' ?><?= $k === 'invoiced' && $j['paid'] > 0 ? ' <small>(' . money($owed) . ' left)</small>' : '' ?></span>
          <?php if ($techs): ?><span class="ro-techs"><?php foreach (array_slice($techs, 0, 3) as $t): ?><i style="--tc:<?= h($t['color'] ?: '#5e667a') ?>" title="<?= h($t['name']) ?>"><?= h($initials((string)$t['name'])) ?></i><?php endforeach; ?></span><?php endif; ?>
        </span>
        <?php if ($nxLabel || ($k === 'done' && $digits($j['cphone']) !== '')): ?>
        <span class="ro-acts">
          <?php if ($nxTo): ?><form method="post"><input type="hidden" name="action" value="job_stage"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $jid ?>"><input type="hidden" name="to" value="<?= h($nxTo) ?>"><input type="hidden" name="back" value="<?= h(url('jobs', array_filter(['tab' => $k, 'q' => $qq]))) ?>"><button class="sm primary"<?= $over && $nxTo === 'invoiced' ? ' data-confirm="' . h($over) . '"' : '' ?>><?= h($nxLabel) ?></button></form>
          <?php elseif ($nxUrl): ?><a class="btn sm primary" href="<?= $nxUrl ?>"><?= h($nxLabel) ?></a><?php endif; ?>
          <?php if ($k === 'done') echo $contact($j, true); ?>
        </span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="bcol-empty"><?= h($qq !== '' ? 'No matches here.' : $hint) ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
  <form method="post" hidden data-move-form><input type="hidden" name="action" value="job_stage"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id"><input type="hidden" name="to"><input type="hidden" name="back"></form>
  <p class="board-hint">Tip: drag a card to the next column to move it along. The board updates by itself when a customer replies.</p>
</section>
<div class="live-pill" data-live-pill hidden><button type="button" onclick="location.reload()">New activity · Refresh</button></div>

<section class="card sb-month">
  <h2>This month</h2>
  <div class="sbm-grid">
    <div><small>Sales</small><b><?= money($sales) ?></b><span><?= $plural($cars, 'car') ?> invoiced, before tax</span></div>
    <div><small>Average ticket</small><b><?= money($cars ? $sales / $cars : 0) ?></b><span><?= money($gp) ?> profit (<?= $sales > 0 ? round($gp / $sales * 100) : 0 ?>%)</span></div>
    <div><small>Owed to you</small><b><?= money($ar) ?></b><span><?= $plural(count($by['invoiced'] ?? []), 'unpaid invoice') ?></span></div>
    <div><small>Estimates approved</small><b><?= $sent ? round($won / $sent * 100) . '%' : '—' ?></b><span><?= $sent ? $won . ' of ' . $sent . ' sent' : 'none sent yet' ?></span></div>
  </div>
  <?php $fs = followup_stats(90); if ($fs['total'] > 0): ?><p class="muted sbm-note">Declined work: <?= money($fs['won']) ?> of <?= money($fs['total']) ?> won back in the last 90 days<?= $fs['rate'] !== null ? ' (' . $fs['rate'] . '%)' : '' ?>. <a href="<?= url('followups') ?>">Follow-ups ›</a></p><?php endif; ?>
  <p class="muted sbm-note">You billed <?= round($hours, 1) ?> labor hours at an average <?= money($hours ? $labor / $hours : 0) ?>/h (your posted rate is <?= money((float)$S['labor_rate']) ?>/h). <a href="<?= url('team') ?>">Team &amp; payroll ›</a></p>
</section>
<?php endif; ?>
</div>
