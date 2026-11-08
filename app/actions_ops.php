<?php
declare(strict_types=1);
// POST handlers for appointments, the time clock, purchasing, card payments and site admin (included from actions.php
// after the CSRF check; $action and $id are set there).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$nf = function (): never { http_response_code(404); exit('Not found'); };
$dt = function (string $d, string $t): ?string { $ts = strtotime(trim("$d $t")); return $ts ? date('Y-m-d H:i', $ts) : null; };
$S = shop();

switch ($action) {

/* ---------- Appointments ---------- */

case 'appt_save':
    if ($id && !owns_row('appointments', (int)$id)) $nf();
    $start = $dt(post('date'), post('time'));
    if (!$start) { flash('Pick a date and time.', 'err'); redirect(url('calendar') . '#new'); }
    $cid = post_int('customer_id') ?? 0; $cid = owns_row('customers', $cid) ? $cid : null;
    $vehId = post_int('vehicle_id') ?? 0; $vehId = $vehId && owns_vehicle($vehId) ? $vehId : null;
    if ($vehId && !$cid) $cid = col('SELECT customer_id FROM vehicle WHERE id=?', [$vehId]) ?: null;
    $tid = post_int('tech_id') ?? 0; $tid = owns_row('techs', $tid) ? $tid : null;
    $jid = post_int('job_id') ?? 0; $jid = owns_row('jobs', $jid) ? $jid : null;
    $d = ['starts_at' => $start, 'minutes' => max(15, min(600, post_int('minutes') ?? (int)$S['slot_min'])), 'customer_id' => $cid, 'vehicle_id' => $vehId, 'tech_id' => $tid, 'job_id' => $jid,
        'title' => mb_substr(post('title'), 0, 120), 'concern' => mb_substr(post('concern'), 0, 2000), 'name' => mb_substr(post('name'), 0, 80), 'phone' => mb_substr(post('phone'), 0, 40),
        'email' => mb_substr(post('email'), 0, 120), 'vehicle_text' => mb_substr(post('vehicle_text'), 0, 120), 'vin' => strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', post('vin')), 0, 17)),
        'plate' => strtoupper(mb_substr(post('plate'), 0, 12)), 'waiting' => post('waiting') === '1' ? 1 : 0, 'notes' => mb_substr(post('notes'), 0, 2000), 'updated_at' => now()];
    if (array_key_exists(post('status'), APPT_STATUS)) $d['status'] = post('status');
    if ($id) { update('appointments', $d, (int)$id); $aid = (int)$id; }
    else $aid = insert('appointments', $d + ['user_id' => uid(), 'status' => $d['status'] ?? 'scheduled', 'source' => 'shop', 'created_at' => now()]);
    flash('Appointment saved: ' . date('D M j, g:i a', strtotime($start)) . '.');
    redirect(url('calendar', ['d' => substr($start, 0, 10), 'a' => $aid]));

case 'appt_status':
    if (!owns_row('appointments', (int)$id) || !array_key_exists(post('to'), APPT_STATUS)) $nf();
    q('UPDATE appointments SET status=?, seen=1, updated_at=? WHERE id=?', [post('to'), now(), $id]);
    $a = row('SELECT * FROM appointments WHERE id=?', [$id]);
    flash(APPT_STATUS[post('to')] . '.');
    redirect(url('calendar', ['d' => substr((string)$a['starts_at'], 0, 10), 'a' => $id]));

case 'appt_checkin':
    if (!owns_row('appointments', (int)$id)) $nf();
    $jid = appt_check_in(row('SELECT * FROM appointments WHERE id=?', [$id]));
    flash('Checked in. The repair order is open: add the services and get the customer\'s approval.');
    redirect(url('job', ['id' => $jid]));

case 'appt_remind':
    if (!owns_row('appointments', (int)$id)) $nf();
    $a = row('SELECT a.*, c.name cname, c.email cemail, v.year vy, v.make vm, v.model vmo FROM appointments a LEFT JOIN customers c ON c.id=a.customer_id LEFT JOIN vehicle v ON v.id=a.vehicle_id WHERE a.id=?', [$id]);
    $ok = post('how') === 'email' ? appt_email($a, $S, 'Your appointment at ' . ($S['name'] ?: APP_NAME)) : true;
    if ($ok) q('UPDATE appointments SET reminded_at=? WHERE id=?', [now(), $id]);
    flash($ok ? (post('how') === 'email' ? 'Reminder emailed.' : 'Marked as reminded.') : 'Could not email it (no valid customer email, or email is not set up here).', $ok ? 'ok' : 'err');
    redirect(url('calendar', ['d' => substr((string)$a['starts_at'], 0, 10), 'a' => $id]));

case 'appt_delete':
    if (!owns_row('appointments', (int)$id)) $nf();
    $day = substr((string)col('SELECT starts_at FROM appointments WHERE id=?', [$id]), 0, 10);
    q('DELETE FROM appointments WHERE id=?', [$id]);
    flash('Appointment deleted.'); redirect(url('calendar', ['d' => $day]));

case 'booking_save':
    $hours = [];
    foreach (WEEKDAYS as $n => $_) {
        $o = (string)($_POST['open'][$n] ?? ''); $c = (string)($_POST['close'][$n] ?? '');
        if (!empty($_POST['day'][$n]) && preg_match('/^\d\d:\d\d$/', $o) && preg_match('/^\d\d:\d\d$/', $c) && $o < $c) $hours[$n] = [$o, $c];
    }
    $d = ['hours' => $hours, 'slot_min' => max(15, min(240, post_int('slot_min') ?? 60)), 'bays' => max(1, min(50, post_int('bays') ?? 1)), 'book_on' => post('book_on') === '1' ? 1 : 0,
        'book_lead_hours' => max(0, min(168, post_int('book_lead_hours') ?? 2)), 'book_days' => max(1, min(120, post_int('book_days') ?? 21)), 'book_note' => mb_substr(post('book_note'), 0, 600)];
    if (($d['book_on'] && !$S['book_token']) || post('new_link') === '1') $d['book_token'] = bin2hex(random_bytes(9));
    shop_save($d);
    flash('Hours and booking saved.' . (post('new_link') === '1' ? ' The old booking link no longer works.' : ''));
    redirect(url('shop') . '#booking');

/* ---------- Technicians and the time clock ---------- */

case 'tech_save':
    if ($id && !owns_row('techs', (int)$id)) $nf();
    if (post('name') === '') { flash('Name the technician.', 'err'); redirect(url('team')); }
    $d = ['name' => mb_substr(post('name'), 0, 60), 'role' => array_key_exists(post('role'), TECH_ROLES) ? post('role') : 'tech', 'pay_type' => array_key_exists(post('pay_type'), PAY_TYPES) ? post('pay_type') : 'hourly',
        'pay_rate' => max(0, post_float('pay_rate') ?? 0), 'active' => post('active') === '1' ? 1 : 0, 'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : TECH_COLORS[(int)col('SELECT COUNT(*) FROM techs WHERE user_id=?', [uid()]) % count(TECH_COLORS)]];
    if (post('pin') !== '') { if (!preg_match('/^\d{4,8}$/', post('pin'))) { flash('A PIN is 4 to 8 digits.', 'err'); redirect(url('team', ['t' => $id])); } $d['pin_hash'] = password_hash(post('pin'), PASSWORD_DEFAULT); }
    if (post('clear_pin') === '1') $d['pin_hash'] = null;
    if ($id) update('techs', $d, (int)$id); else insert('techs', $d + ['user_id' => uid(), 'sort' => 0, 'created_at' => now()]);
    flash('Saved.'); redirect(url('team'));

case 'tech_delete':
    if (!owns_row('techs', (int)$id)) $nf();
    if (col('SELECT 1 FROM time_entries WHERE tech_id=?', [$id]) || col('SELECT 1 FROM job_lines WHERE tech_id=?', [$id])) { q('UPDATE techs SET active=0 WHERE id=?', [$id]); flash('They have time on record, so they were set to inactive (history kept).'); }
    else { q('DELETE FROM techs WHERE id=?', [$id]); flash('Removed.'); }
    redirect(url('team'));

case 'clock_punch':
    $tid = post_int('tech_id') ?? 0;
    if (!owns_row('techs', $tid)) $nf();
    $t = row('SELECT * FROM techs WHERE id=?', [$tid]);
    $back = post('back') === 'job' && post_int('job_id') ? url('job', ['id' => post_int('job_id')]) . '#clock' : url('clock');
    if ($t['pin_hash'] && post('from_owner') !== '1') {
        if (!rate_ok('pin:' . $tid, 8, 900, false)) { flash('Too many wrong PINs. Wait 15 minutes.', 'err'); redirect($back); }
        if (!password_verify(post('pin'), (string)$t['pin_hash'])) { rate_ok('pin:' . $tid, 8, 900); flash('Wrong PIN for ' . $t['name'] . '.', 'err'); redirect($back); }
    }
    $what = post('what');
    if (!in_array($what, ['shift_in', 'shift_out', 'job_start', 'job_stop'], true)) $nf();
    flash(clock_punch($t, $what, post_int('job_id'), post('grp')));
    redirect($back);

case 'time_save':
case 'time_add':
    if ($action === 'time_save' && !owns_row('time_entries', (int)$id)) $nf();
    $tid = $action === 'time_add' ? (post_int('tech_id') ?? 0) : (int)col('SELECT tech_id FROM time_entries WHERE id=?', [$id]);
    if (!owns_row('techs', $tid)) $nf();
    $st = strtotime(post('start')); $en = post('end') !== '' ? strtotime(post('end')) : null;
    if (!$st || ($en !== null && (!$en || $en <= $st)) || ($en ?? $st) - $st > 24 * 3600) { flash('Check the times: the end must be after the start, within 24 hours.', 'err'); redirect(url('team', ['t' => $tid])); }
    $jid = post_int('job_id') ?? 0; $jid = owns_row('jobs', $jid) ? $jid : null;
    $d = ['started_at' => $st, 'ended_at' => $en, 'note' => mb_substr(post('note'), 0, 200), 'edited' => 1];
    if ($action === 'time_add') insert('time_entries', $d + ['user_id' => uid(), 'tech_id' => $tid, 'kind' => post('kind') === 'job' && $jid ? 'job' : 'shift', 'job_id' => $jid, 'grp' => post('grp')]);
    else update('time_entries', $d, (int)$id);
    flash('Time saved (marked as edited).'); redirect(url('team', ['t' => $tid, 'from' => post('from'), 'to' => post('to')]));

case 'time_delete':
    if (!owns_row('time_entries', (int)$id)) $nf();
    $tid = (int)col('SELECT tech_id FROM time_entries WHERE id=?', [$id]);
    q('DELETE FROM time_entries WHERE id=?', [$id]);
    flash('Entry deleted.'); redirect(url('team', ['t' => $tid]));

case 'line_tech':
    $jid = post_int('job_id') ?? 0;
    if (!owns_row('jobs', $jid)) $nf();
    $tid = post_int('tech_id') ?? 0; $tid = owns_row('techs', $tid) ? $tid : null;
    q("UPDATE job_lines SET tech_id=? WHERE job_id=? AND grp=? AND kind='labor'", [$tid, $jid, post('grp')]);
    redirect(url('job', ['id' => $jid]) . '#g-' . rawurlencode(post('grp')));

/* ---------- Vendors and purchase orders ---------- */

case 'vendor_save':
    if ($id && !owns_row('vendors', (int)$id)) $nf();
    if (post('name') === '') { flash('Name the vendor.', 'err'); redirect(url('purchasing') . '#vendors'); }
    $d = []; foreach (['name', 'account_no', 'contact', 'phone', 'email', 'website', 'notes'] as $k) $d[$k] = mb_substr(post($k), 0, 300);
    if ($id) update('vendors', $d, (int)$id); else $id = insert('vendors', $d + ['user_id' => uid()]);
    flash('Vendor saved.'); redirect(post('back') === 'po' && post_int('po_id') ? url('po', ['id' => post_int('po_id')]) : url('purchasing') . '#vendors');

case 'vendor_delete':
    if (!owns_row('vendors', (int)$id)) $nf();
    q('UPDATE purchase_orders SET vendor_id=NULL WHERE vendor_id=? AND user_id=?', [$id, uid()]);
    q('DELETE FROM vendors WHERE id=?', [$id]);
    flash('Vendor removed (their purchase orders are kept).'); redirect(url('purchasing') . '#vendors');

case 'po_new':
    $vid = post_int('vendor_id') ?? 0; $vid = owns_row('vendors', $vid) ? $vid : null;
    if (!$vid && post('new_vendor') !== '') $vid = insert('vendors', ['user_id' => uid(), 'name' => mb_substr(post('new_vendor'), 0, 120)]);
    $poId = insert('purchase_orders', ['user_id' => uid(), 'vendor_id' => $vid, 'po_no' => po_next_number(), 'status' => 'draft', 'notes' => '', 'ref' => '', 'total' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $n = 0;
    foreach ((array)($_POST['lines'] ?? []) as $lid) {
        $lid = (int)$lid; if (!owns_row('job_lines', $lid)) continue;
        $l = row('SELECT * FROM job_lines WHERE id=?', [$lid]);
        insert('po_lines', ['po_id' => $poId, 'job_line_id' => $lid, 'catalog_id' => $l['catalog_id'], 'part_no' => $l['part_no'], 'brand' => $l['brand'], 'description' => $l['description'], 'qty' => (float)$l['qty'], 'qty_received' => 0, 'cost' => (float)$l['cost']]);
        $n++;
    }
    if (post('restock') === '1') foreach (rows('SELECT * FROM parts_catalog WHERE user_id=? AND qty <= 0 ORDER BY name LIMIT 200', [uid()]) as $c) {
        insert('po_lines', ['po_id' => $poId, 'catalog_id' => $c['id'], 'part_no' => $c['part_no'], 'brand' => $c['brand'], 'description' => $c['name'], 'qty' => 1, 'qty_received' => 0, 'cost' => (float)$c['cost']]); $n++;
    }
    po_refresh($poId);
    flash('Purchase order created' . ($n ? ' with ' . $n . ' line' . ($n === 1 ? '' : 's') : '') . '. Check quantities and costs, then mark it ordered.');
    redirect(url('po', ['id' => $poId]));

case 'po_line_add':
    $poId = post_int('po_id') ?? 0;
    if (!owns_row('purchase_orders', $poId)) $nf();
    $cat = owns_row('parts_catalog', post_int('catalog_id') ?? 0) ? row('SELECT * FROM parts_catalog WHERE id=?', [post_int('catalog_id')]) : null;
    if (!$cat && post('description') === '') { flash('Describe the part.', 'err'); redirect(url('po', ['id' => $poId])); }
    insert('po_lines', ['po_id' => $poId, 'catalog_id' => $cat['id'] ?? null, 'part_no' => post('part_no') ?: (string)($cat['part_no'] ?? ''), 'brand' => post('brand') ?: (string)($cat['brand'] ?? ''),
        'description' => post('description') ?: (string)$cat['name'], 'qty' => post_float('qty') ?? 1, 'qty_received' => 0, 'cost' => post_float('cost') ?? (float)($cat['cost'] ?? 0)]);
    po_refresh($poId); redirect(url('po', ['id' => $poId]));

case 'po_line_save':
    if (!owns_row('po_lines', (int)$id)) $nf();
    $poId = (int)col('SELECT po_id FROM po_lines WHERE id=?', [$id]);
    update('po_lines', ['description' => post('description'), 'part_no' => post('part_no'), 'brand' => post('brand'), 'qty' => post_float('qty') ?? 1, 'cost' => post_float('cost') ?? 0], (int)$id);
    // Cost from the vendor goes back onto the repair-order line (sell price stays as quoted).
    if ($jl = col('SELECT job_line_id FROM po_lines WHERE id=?', [$id])) q('UPDATE job_lines SET cost=? WHERE id=?', [post_float('cost') ?? 0, $jl]);
    po_refresh($poId); redirect(url('po', ['id' => $poId]));

case 'po_line_delete':
    if (!owns_row('po_lines', (int)$id)) $nf();
    $pl = row('SELECT * FROM po_lines WHERE id=?', [$id]);
    q('DELETE FROM po_lines WHERE id=?', [$id]);
    if ($pl['job_line_id']) q("UPDATE job_lines SET pstatus='quoted' WHERE id=? AND pstatus='ordered'", [$pl['job_line_id']]);
    po_refresh((int)$pl['po_id']); redirect(url('po', ['id' => $pl['po_id']]));

case 'po_status':
    if (!owns_row('purchase_orders', (int)$id) || !in_array(post('to'), ['draft', 'ordered', 'cancelled'], true)) $nf();
    $to = post('to');
    q('UPDATE purchase_orders SET status=?, ref=COALESCE(NULLIF(?,\'\'), ref), ordered_at=CASE WHEN ?=\'ordered\' THEN ? ELSE ordered_at END, updated_at=? WHERE id=?', [$to, post('ref'), $to, now(), now(), $id]);
    if ($to === 'ordered') {
        q("UPDATE job_lines SET pstatus='ordered', supplier=COALESCE(NULLIF(supplier,''), ?) WHERE id IN (SELECT job_line_id FROM po_lines WHERE po_id=?) AND pstatus='quoted'", [(string)col('SELECT v.name FROM purchase_orders p LEFT JOIN vendors v ON v.id=p.vendor_id WHERE p.id=?', [$id]), $id]);
        // Orders whose parts are now all on order wait for parts.
        foreach (rows('SELECT DISTINCT l.job_id FROM po_lines p JOIN job_lines l ON l.id=p.job_line_id WHERE p.po_id=?', [$id]) as $r)
            q("UPDATE jobs SET stage='waiting_parts', updated_at=? WHERE id=? AND stage='approved'", [now(), $r['job_id']]);
    }
    if ($to === 'cancelled') q("UPDATE job_lines SET pstatus='quoted' WHERE id IN (SELECT job_line_id FROM po_lines WHERE po_id=?) AND pstatus='ordered'", [$id]);
    flash(PO_STATUS[$to] . '.'); redirect(url('po', ['id' => $id]));

case 'po_receive':
    if (!owns_row('purchase_orders', (int)$id)) $nf();
    $ready = po_receive((int)$id, array_map('floatval', (array)($_POST['rq'] ?? [])));
    foreach ($ready as $jid) q("UPDATE jobs SET stage='in_progress', updated_at=? WHERE id=? AND stage='waiting_parts'", [now(), $jid]);
    flash('Received.' . ($ready ? ' ' . count($ready) . ' repair order' . (count($ready) === 1 ? ' has' : 's have') . ' all parts now and went back to In progress.' : ''));
    redirect(url('po', ['id' => $id]));

case 'po_delete':
    if (!owns_row('purchase_orders', (int)$id)) $nf();
    if (!in_array(col('SELECT status FROM purchase_orders WHERE id=?', [$id]), ['draft', 'cancelled'], true)) { flash('Only draft or cancelled orders can be deleted. Cancel it first.', 'err'); redirect(url('po', ['id' => $id])); }
    q('DELETE FROM po_lines WHERE po_id=?', [$id]); q('DELETE FROM purchase_orders WHERE id=?', [$id]);
    flash('Purchase order deleted.'); redirect(url('purchasing'));

case 'parts_paste':
    // A cart or quote copied from a supplier site (PartsTech, Nexpart, RockAuto, a spreadsheet): CSV or tab-separated, header optional.
    $jid = post_int('job_id') ?? 0;
    if (!owns_row('jobs', $jid)) $nf();
    $job = row('SELECT * FROM jobs WHERE id=?', [$jid]);
    $rows = array_values(array_filter(array_map('trim', preg_split('/\R/', post('text'))), fn($l) => $l !== ''));
    $sep = $rows && substr_count($rows[0], "\t") >= substr_count($rows[0], ',') && str_contains($rows[0], "\t") ? "\t" : ',';
    $map = ['part_no' => 0, 'description' => 1, 'brand' => 2, 'qty' => 3, 'cost' => 4, 'supplier' => 5]; $start = 0;
    if ($rows) {
        $hdr = array_map(fn($x) => strtolower(trim($x)), str_getcsv($rows[0], $sep, '"', '\\'));
        $find = function (array $names) use ($hdr) { foreach ($hdr as $i => $hcol) foreach ($names as $nm) if (str_contains($hcol, $nm)) return $i; return null; };
        $pn = $find(['part number', 'part #', 'part no', 'partnumber', 'sku', 'line code']);
        if ($pn !== null || $find(['description', 'cost', 'qty', 'quantity']) !== null) {
            $map = ['part_no' => $pn, 'description' => $find(['description', 'name', 'part name']), 'brand' => $find(['brand', 'manufacturer', 'mfr']), 'qty' => $find(['qty', 'quantity']),
                'cost' => $find(['your cost', 'cost', 'net', 'price']), 'supplier' => $find(['supplier', 'vendor', 'store', 'distributor'])];
            $start = 1;
        }
    }
    $n = 0; $grp = post('grp') ?: 'Parts'; $sort = (int)col('SELECT COALESCE(MAX(sort),0) FROM job_lines WHERE job_id=?', [$jid]);
    foreach (array_slice($rows, $start, 200) as $ln) {
        $c = str_getcsv($ln, $sep, '"', '\\'); $g = fn(string $k) => $map[$k] !== null ? trim((string)($c[$map[$k]] ?? '')) : '';
        $desc = $g('description') ?: $g('part_no'); if ($desc === '') continue;
        $cost = (float)preg_replace('/[^\d.]/', '', $g('cost')); $qty = (float)preg_replace('/[^\d.]/', '', $g('qty')) ?: 1;
        insert('job_lines', ['job_id' => $jid, 'grp' => $grp, 'kind' => 'part', 'description' => mb_substr($desc, 0, 200), 'part_no' => mb_substr($g('part_no'), 0, 60), 'brand' => mb_substr($g('brand'), 0, 60),
            'supplier' => mb_substr($g('supplier') ?: post('supplier'), 0, 60), 'qty' => $qty, 'cost' => $cost, 'price' => $cost > 0 ? matrix_price($cost) : 0, 'taxable' => 1,
            'status' => in_array($job['stage'], ['estimate', 'declined'], true) ? 'pending' : 'approved', 'pstatus' => 'quoted', 'sort' => ++$sort]);
        $n++;
    }
    job_refresh($jid);
    flash($n ? $n . ' part' . ($n === 1 ? '' : 's') . ' added to "' . $grp . '", priced with your matrix. Check them.' : 'No parts found in that text. Paste the cart as rows: part number, description, brand, qty, cost.', $n ? 'ok' : 'err');
    redirect(url('job', ['id' => $jid]) . '#g-' . rawurlencode($grp));

/* ---------- Card payments ---------- */

case 'pay_save':
    $d = ['currency_code' => preg_match('/^[a-z]{3}$/', strtolower(post('currency_code'))) ? strtolower(post('currency_code')) : 'usd'];
    $key = post('stripe_sk');
    if (post('stripe_clear') === '1') $d['stripe_sk'] = '';
    elseif ($key !== '') {
        if (!preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', $key)) { flash('That does not look like a Stripe secret or restricted key (sk_… or rk_…).', 'err'); redirect(url('shop') . '#cards'); }
        [$code, $j] = stripe_req($key, 'GET', '/checkout/sessions', ['limit' => 1]);
        if ($code !== 200) { flash('Stripe did not accept that key: ' . ($j['error']['message'] ?? 'no answer') . '. Nothing saved.', 'err'); redirect(url('shop') . '#cards'); }
        $d['stripe_sk'] = $key;
    }
    shop_save($d);
    flash(isset($d['stripe_sk']) && $d['stripe_sk'] !== '' ? 'Card payments are on' . (str_contains($d['stripe_sk'], '_test_') ? ' (TEST mode: no real money moves)' : '') . '. Customers get a Pay button on their invoice link.' : 'Saved.');
    redirect(url('shop') . '#cards');

case 'pay_link':
    if (!owns_row('jobs', (int)$id)) $nf();
    if (!col('SELECT token FROM jobs WHERE id=?', [$id])) q('UPDATE jobs SET token=? WHERE id=?', [bin2hex(random_bytes(16)), $id]);
    redirect(url('job', ['id' => $id]) . '#pay');

/* ---------- Site admin ---------- */

case 'admin_toggle':
case 'admin_delete':
case 'admin_signups':
case 'admin_purge_guests':
    if (!MULTI_USER || !is_admin()) $nf();
    if ($action === 'admin_signups') { setting('signup_closed', post('open') === '1' ? '0' : '1'); flash(post('open') === '1' ? 'Sign-ups are open.' : 'Sign-ups are closed (existing accounts and guests still work).'); redirect(url('admin')); }
    if ($action === 'admin_purge_guests') { $n = 0; foreach (rows('SELECT id FROM users WHERE is_guest=1 AND guest_expires < ?', [time()]) as $g) { account_wipe((int)$g['id']); $n++; } flash($n . ' expired guest garage' . ($n === 1 ? '' : 's') . ' removed.'); redirect(url('admin')); }
    $u = $id ? row('SELECT * FROM users WHERE id=?', [$id]) : null;
    if (!$u) $nf();
    if ((int)$u['id'] === uid()) { flash('You cannot change or delete your own admin account here.', 'err'); redirect(url('admin')); }
    if ($action === 'admin_toggle') { q('UPDATE users SET is_admin=? WHERE id=?', [(int)$u['is_admin'] ? 0 : 1, $u['id']]); flash($u['email'] . ((int)$u['is_admin'] ? ' is no longer an admin.' : ' is now an admin.')); redirect(url('admin')); }
    if (mb_strtolower(post('confirm')) !== mb_strtolower((string)$u['email'])) { flash('Type the account\'s email exactly to delete it. Nothing was deleted.', 'err'); redirect(url('admin')); }
    account_wipe((int)$u['id']);
    flash('Account ' . $u['email'] . ' and everything in it were deleted.'); redirect(url('admin'));
}
http_response_code(400); echo 'Unknown action';
