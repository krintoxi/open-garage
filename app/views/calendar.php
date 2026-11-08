<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Appointments';
$S = shop();
$day = preg_match('/^\d{4}-\d\d-\d\d$/', (string)($_GET['d'] ?? '')) ? (string)$_GET['d'] : today();
$view = ($_GET['view'] ?? '') === 'day' ? 'day' : 'week';
// Week view runs seven days from the chosen day (today by default), so the coming days are always on screen.
$monday = $day;
[$from, $to] = $view === 'day' ? [$day, date('Y-m-d', strtotime("$day +1 day"))] : [$monday, date('Y-m-d', strtotime("$monday +7 days"))];
$list = appts($from . ' 00:00', $to . ' 00:00');
$byDay = []; foreach ($list as $a) $byDay[substr((string)$a['starts_at'], 0, 10)][] = $a;
$requests = rows("SELECT a.* FROM appointments a WHERE a.user_id=? AND a.status='requested' ORDER BY a.starts_at", [uid()]);
if ($requests) q("UPDATE appointments SET seen=1 WHERE user_id=? AND status='requested' AND seen=0", [uid()]);
$hours = shop_hours($S);
$techList = techs();
$customers = rows('SELECT id, name, phone, email FROM customers WHERE user_id=? ORDER BY name', [uid()]);
$allVeh = rows('SELECT v.*, c.name cname FROM vehicle v LEFT JOIN customers c ON c.id=v.customer_id WHERE v.user_id=? ORDER BY c.name IS NULL DESC, c.name, v.year', [uid()]);
$sel = ($aid = (int)($_GET['a'] ?? 0)) && owns_row('appointments', $aid) ? row('SELECT a.*, c.name cname, c.phone cphone, c.email cemail, v.year vy, v.make vm, v.model vmo, v.plate vplate, t.name tname FROM appointments a LEFT JOIN customers c ON c.id=a.customer_id LEFT JOIN vehicle v ON v.id=a.vehicle_id LEFT JOIN techs t ON t.id=a.tech_id WHERE a.id=?', [$aid]) : null;
// Prefill from a repair order or customer ("Schedule" buttons elsewhere).
$pre = ['job_id' => 0, 'customer_id' => (int)($_GET['customer'] ?? 0), 'vehicle_id' => (int)($_GET['vehicle'] ?? 0), 'title' => '', 'concern' => ''];
if (($pj = (int)($_GET['job'] ?? 0)) && owns_row('jobs', $pj)) { $jj = row('SELECT * FROM jobs WHERE id=?', [$pj]); $pre = ['job_id' => $pj, 'customer_id' => (int)$jj['customer_id'], 'vehicle_id' => (int)$jj['vehicle_id'], 'title' => (string)$jj['title'], 'concern' => (string)$jj['complaint']]; }
$csrf = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$nav = fn(string $d, string $v = '') => url('calendar', array_filter(['d' => $d, 'view' => $v ?: ($view === 'day' ? 'day' : '')]));
$stepDays = $view === 'day' ? 1 : 7;
$bookLink = $S['book_on'] ? book_url($S) : '';
$apptForm = function (?array $a) use ($csrf, $customers, $allVeh, $techList, $S, $pre, $day): void {
    $v = $a ?? ['id' => 0, 'starts_at' => $day . ' ' . (shop_hours($S)[(int)date('N', strtotime($day))][0] ?? '09:00'), 'minutes' => (int)$S['slot_min'], 'status' => 'scheduled', 'name' => '', 'phone' => '', 'email' => '',
        'vehicle_text' => '', 'vin' => '', 'plate' => '', 'waiting' => 0, 'notes' => '', 'tech_id' => null] + $pre;
    ?>
    <form method="post" class="form-grid appt-form"><input type="hidden" name="action" value="appt_save"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="job_id" value="<?= (int)($v['job_id'] ?? 0) ?>">
      <div><label>Date</label><input type="date" name="date" value="<?= h(substr((string)$v['starts_at'], 0, 10)) ?>" required></div>
      <div><label>Time</label><input type="time" name="time" step="900" value="<?= h(substr((string)$v['starts_at'], 11, 5)) ?>" required></div>
      <div><label>Length</label><select name="minutes"><?php foreach ([15, 30, 45, 60, 90, 120, 180, 240, 360, 480] as $m): ?><option value="<?= $m ?>" <?= (int)$v['minutes'] === $m ? 'selected' : '' ?>><?= $m < 60 ? $m . ' min' : hm($m) . ' h' ?></option><?php endforeach; ?></select></div>
      <div><label>Technician</label><select name="tech_id"><option value="">Anyone</option><?php foreach ($techList as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$v['tech_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Customer</label><select name="customer_id" data-cust-pick><option value="">— new / walk-in (fill in below) —</option><?php foreach ($customers as $cu): ?><option value="<?= (int)$cu['id'] ?>" <?= (int)$v['customer_id'] === (int)$cu['id'] ? 'selected' : '' ?>><?= h($cu['name']) ?><?= $cu['phone'] ? ' · ' . h($cu['phone']) : '' ?></option><?php endforeach; ?></select></div>
      <div><label>Vehicle on file</label><select name="vehicle_id"><option value="">— new (scan or type below) —</option><?php foreach ($allVeh as $av): ?><option value="<?= (int)$av['id'] ?>" data-cust="<?= (int)$av['customer_id'] ?>" <?= (int)$v['vehicle_id'] === (int)$av['id'] ? 'selected' : '' ?>><?= h(vehicle_name($av)) ?><?= $av['cname'] ? ' · ' . h($av['cname']) : ' · mine' ?><?= $av['plate'] ? ' · ' . h($av['plate']) : '' ?></option><?php endforeach; ?></select></div>
      <details class="full" <?= !$v['customer_id'] && ($v['name'] || !$a) ? 'open' : '' ?>><summary class="muted">New customer and vehicle</summary>
        <div class="form-grid">
          <div><label>Name</label><input type="text" name="name" value="<?= h((string)$v['name']) ?>" autocomplete="off"></div><div><label>Phone</label><input type="tel" name="phone" value="<?= h((string)$v['phone']) ?>"></div>
          <div class="full"><label>Email</label><input type="email" name="email" value="<?= h((string)$v['email']) ?>"></div>
          <div class="full"><?= idscan_widget() ?></div>
          <div class="full"><label>Vehicle (year make model)</label><input type="text" name="vehicle_text" value="<?= h((string)$v['vehicle_text']) ?>" placeholder="filled in by the scan, or type it"></div>
          <div><label>VIN</label><input type="text" name="vin" value="<?= h((string)$v['vin']) ?>" maxlength="17" class="mono" autocapitalize="characters"></div><div><label>Plate</label><input type="text" name="plate" value="<?= h((string)$v['plate']) ?>" autocapitalize="characters"></div>
          <input type="hidden" name="year"><input type="hidden" name="make"><input type="hidden" name="model">
        </div></details>
      <div class="full"><label>Service / reason</label><input type="text" name="title" value="<?= h((string)$v['title']) ?>" placeholder="e.g. Oil change and brake check" list="appt-titles"></div>
      <div class="full"><label>Concern (customer's words)</label><textarea name="concern" rows="2"><?= h((string)$v['concern']) ?></textarea></div>
      <div class="full"><label class="inline"><input type="checkbox" name="waiting" value="1" <?= $v['waiting'] ? 'checked' : '' ?>> Customer waits at the shop</label></div>
      <div class="full"><label>Internal note</label><input type="text" name="notes" value="<?= h((string)$v['notes']) ?>"></div>
      <?php if ($a): ?><div><label>Status</label><select name="status"><?php foreach (APPT_STATUS as $k => $l): ?><option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="full row end"><button class="primary"><?= $a ? 'Save changes' : 'Book it' ?></button></div>
    </form>
<?php };
?>
<datalist id="appt-titles"><?php foreach (array_unique(array_column(canned_jobs(), 'name')) as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
<div class="head"><div><h1>Appointments</h1><p><?= $view === 'day' ? h(date('l, F j', strtotime($day))) : h(date('M j', strtotime($monday))) . ' – ' . h(date('M j', strtotime("$monday +6 days"))) ?> · <?= count(array_filter($list, fn($a) => !in_array($a['status'], ['cancelled', 'no_show'], true))) ?> booked<?= $S['bays'] ? ' · ' . (int)$S['bays'] . ' bay' . ((int)$S['bays'] === 1 ? '' : 's') : '' ?></p></div>
  <div class="row" style="gap:6px;flex-wrap:wrap">
    <a class="btn sm" href="<?= $nav(date('Y-m-d', strtotime("$day -$stepDays days"))) ?>" aria-label="Previous">‹</a><a class="btn sm" href="<?= $nav(today()) ?>">Today</a><a class="btn sm" href="<?= $nav(date('Y-m-d', strtotime("$day +$stepDays days"))) ?>" aria-label="Next">›</a>
    <a class="btn sm <?= $view === 'week' ? 'on' : 'ghost' ?>" href="<?= url('calendar', ['d' => $day]) ?>">Week</a><a class="btn sm <?= $view === 'day' ? 'on' : 'ghost' ?>" href="<?= url('calendar', ['d' => $day, 'view' => 'day']) ?>">Day</a>
    <button type="button" class="primary sm" data-sheet-open="appt">＋ Appointment</button></div></div>

<?php if ($requests): ?><div class="card req-card"><h2>Online requests <span class="st st-open"><?= count($requests) ?></span></h2>
  <ul class="list"><?php foreach ($requests as $r): ?><li><div class="grow"><a class="t" href="<?= url('calendar', ['d' => substr((string)$r['starts_at'], 0, 10), 'a' => $r['id']]) ?>"><?= h(date('D M j, g:i a', strtotime((string)$r['starts_at']))) ?> · <?= h($r['name'] ?: 'Customer') ?></a>
    <small><?= h($r['vehicle_text'] ?: $r['vin']) ?><?= $r['title'] ? ' · ' . h($r['title']) : '' ?><?= $r['phone'] ? ' · ' . h($r['phone']) : '' ?></small></div>
    <form method="post" class="row" style="gap:4px"><input type="hidden" name="action" value="appt_status"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="sm primary" name="to" value="confirmed">Accept</button><button class="sm ghost" name="to" value="cancelled" data-confirm="Decline this request? Let the customer know (call or text) — the app does not message them.">Decline</button></form></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="grid g3 cal-wrap">
  <div style="grid-column:span <?= $sel ? 2 : 3 ?>">
    <div class="cal <?= $view === 'day' ? 'cal-day' : '' ?>">
      <?php for ($i = 0, $n = $view === 'day' ? 1 : 7; $i < $n; $i++): $d = date('Y-m-d', strtotime(($view === 'day' ? $day : $monday) . " +$i days")); $items = $byDay[$d] ?? []; $h = $hours[(int)date('N', strtotime($d))];
        $cap = $h ? (int)floor((strtotime("$d {$h[1]}") - strtotime("$d {$h[0]}")) / 60 / max(15, (int)$S['slot_min'])) * max(1, (int)$S['bays']) : 0;
        $busy = count(array_filter($items, fn($a) => !in_array($a['status'], ['cancelled', 'no_show'], true))); ?>
        <section class="cal-col <?= $d === today() ? 'today' : ($d < today() ? 'past' : '') ?> <?= !$h ? 'closed' : '' ?>">
          <header><a href="<?= url('calendar', ['d' => $d, 'view' => 'day']) ?>"><b><?= h(date('D', strtotime($d))) ?></b> <?= h(date('M j', strtotime($d))) ?></a>
            <small><?= $h ? h(date('g:ia', strtotime($h[0])) . '–' . date('g:ia', strtotime($h[1]))) : 'Closed' ?><?= $cap ? ' · ' . $busy . '/' . $cap : '' ?></small></header>
          <?php foreach ($items as $a): ?>
            <a class="appt st-<?= h($a['status']) ?> <?= $sel && (int)$sel['id'] === (int)$a['id'] ? 'on' : '' ?>" href="<?= url('calendar', ['d' => $d, 'a' => $a['id']] + ($view === 'day' ? ['view' => 'day'] : [])) ?>" style="--tc:<?= h($a['tcolor'] ?: '#5e667a') ?>">
              <span class="appt-time"><?= h(date('g:i', strtotime((string)$a['starts_at']))) ?><small><?= h(date('a', strtotime((string)$a['starts_at']))) ?> · <?= $a['minutes'] < 60 ? (int)$a['minutes'] . 'm' : hm((int)$a['minutes']) ?></small></span>
              <b><?= h(appt_who($a)) ?><?= $a['waiting'] ? ' <i title="Waiting at the shop">⌛</i>' : '' ?></b>
              <small><?= h(appt_vehicle($a)) ?></small>
              <?php if ($a['title']): ?><small class="appt-title"><?= h(mb_strimwidth((string)$a['title'], 0, 50, '…')) ?></small><?php endif; ?>
              <span class="appt-foot"><?= $a['tname'] ? h($a['tname']) : '' ?><?php if ($a['status'] !== 'scheduled'): ?> <i class="st st-<?= h($a['status']) ?>"><?= h(APPT_STATUS[$a['status']] ?? '') ?></i><?php endif; ?></span>
            </a>
          <?php endforeach; ?>
          <?php if ($h): ?><button type="button" class="cal-add ghost sm" data-sheet-open="appt" data-appt-date="<?= h($d) ?>">＋</button><?php endif; ?>
        </section>
      <?php endfor; ?>
    </div>
    <?php if ($bookLink): ?><div class="card" style="margin-top:14px"><h3>Online booking link</h3><div class="share-link"><input type="text" readonly value="<?= h($bookLink) ?>" data-select-all><button type="button" class="sm primary" data-copy="<?= h($bookLink) ?>">Copy</button></div>
      <p class="muted" style="font-size:12.5px">Put it on your website, Google Business profile or social pages. Requests land here for you to accept. <a href="<?= url('shop') ?>#booking">Hours and booking settings</a></p></div>
    <?php else: ?><p class="muted" style="font-size:13px;margin-top:12px">Let customers book online: turn it on under <a href="<?= url('shop') ?>#booking">Shop settings → Hours and booking</a>.</p><?php endif; ?>
  </div>

  <?php if ($sel): $msg = appt_message($sel, $S); $ph = preg_replace('/[^\d+]/', '', (string)($sel['cphone'] ?: $sel['phone'])); $em = (string)($sel['cemail'] ?: $sel['email']); ?>
  <div class="aside">
    <div class="card appt-detail">
      <div class="row" style="justify-content:space-between"><h2 style="margin:0"><?= h(appt_who($sel)) ?></h2><a class="btn sm ghost" href="<?= url('calendar', ['d' => $day] + ($view === 'day' ? ['view' => 'day'] : [])) ?>" aria-label="Close">×</a></div>
      <p class="muted" style="margin:4px 0 10px"><?= h(date('l M j, g:i a', strtotime((string)$sel['starts_at']))) ?> · <?= hm((int)$sel['minutes']) ?> h · <span class="st st-<?= h($sel['status']) ?>"><?= h(APPT_STATUS[$sel['status']]) ?></span><?= $sel['source'] === 'online' ? ' · booked online' : '' ?></p>
      <p><?= h(appt_vehicle($sel)) ?><?= $sel['vplate'] || $sel['plate'] ? ' · ' . h((string)($sel['vplate'] ?: $sel['plate'])) : '' ?><?= $sel['vin'] ? '<br><small class="mono">' . h($sel['vin']) . '</small>' : '' ?></p>
      <?php if ($sel['title'] || $sel['concern']): ?><p><b><?= h((string)$sel['title']) ?></b><?= $sel['concern'] ? '<br>' . nl2br(h((string)$sel['concern'])) : '' ?></p><?php endif; ?>
      <div class="row" style="gap:6px;flex-wrap:wrap;margin:10px 0">
        <?php if (in_array($sel['status'], ['requested', 'scheduled', 'confirmed'], true)): ?>
          <form method="post"><input type="hidden" name="action" value="appt_checkin"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="primary sm">Check in → repair order</button></form>
          <?php if ($sel['status'] !== 'confirmed'): ?><form method="post"><input type="hidden" name="action" value="appt_status"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="sm" name="to" value="confirmed">Confirmed</button></form><?php endif; ?>
          <form method="post"><input type="hidden" name="action" value="appt_status"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="sm ghost" name="to" value="no_show">No-show</button><button class="sm ghost" name="to" value="cancelled">Cancel</button></form>
        <?php elseif ($sel['job_id']): ?><a class="btn sm primary" href="<?= url('job', ['id' => $sel['job_id']]) ?>">Open repair order</a><?php endif; ?>
      </div>
      <h3>Remind the customer</h3>
      <div class="row" style="gap:6px;flex-wrap:wrap">
        <?php if ($ph): ?><a class="btn sm" href="sms:<?= h($ph) ?>?&amp;body=<?= h(rawurlencode($msg)) ?>">Text</a><a class="btn sm" href="tel:<?= h($ph) ?>">Call</a><?php endif; ?>
        <?php if ($em): ?><a class="btn sm" href="mailto:<?= h($em) ?>?subject=<?= h(rawurlencode('Your appointment at ' . ($S['name'] ?: 'the shop'))) ?>&amp;body=<?= h(rawurlencode($msg)) ?>">Email from my app</a>
          <?php if (MAIL_KEY !== ''): ?><form method="post"><input type="hidden" name="action" value="appt_remind"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="sm" name="how" value="email">Send email now</button></form><?php endif; ?><?php endif; ?>
        <form method="post"><input type="hidden" name="action" value="appt_remind"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="sm ghost" name="how" value="mark">Mark reminded</button></form>
      </div>
      <p class="muted" style="font-size:12.5px"><?= $sel['reminded_at'] ? 'Reminded ' . h(date('M j, g:i a', strtotime((string)$sel['reminded_at']))) . '.' : 'Not reminded yet.' ?> Texts go from your own phone.</p>
      <details style="margin-top:10px"><summary><b>Edit</b></summary><?php $apptForm($sel); ?></details>
      <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="appt_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$sel['id'] ?>"><button class="sm ghost danger" data-confirm="Delete this appointment? Cancelling keeps a record instead.">Delete</button></form>
    </div>
  </div>
  <?php endif; ?>
</div>

<dialog class="sheet appt-dlg" id="sheet-appt" aria-label="New appointment" <?= isset($_GET['new']) ? 'data-open-now' : '' ?>>
  <div class="sheet-grip"></div>
  <h3>New appointment</h3>
  <?php $apptForm(null); ?>
  <button type="button" class="sheet-close ghost" data-sheet-close>Close</button>
</dialog>
