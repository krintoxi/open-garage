<?php
declare(strict_types=1);
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
/** POST handlers. Every form posts `action` and `_csrf`. */
csrf_check();
$action = post('action');
$id = post_int('id');

// Every action that names a record by id must name one of this account's records.
const OWNED_BY_ACTION = [
    'issue_save' => 'issues', 'issue_status' => 'issues', 'issue_delete' => 'issues', 'issue_note_add' => 'issues', 'note_delete' => 'issue_notes',
    'photo_delete' => 'photos', 'photo_caption' => 'photos', 'code_event_status' => 'code_events', 'code_event_delete' => 'code_events',
    'common_status' => 'common_issues', 'common_to_issue' => 'common_issues', 'maint_save' => 'maintenance', 'maint_done' => 'maintenance', 'maint_delete' => 'maintenance',
    'check_save' => 'checks', 'check_toggle' => 'checks', 'inspection_delete' => 'inspections', 'visit_save' => 'visits', 'visit_complete' => 'visits',
    'visit_signoff' => 'visits', 'visit_delete' => 'visits', 'part_save' => 'parts', 'part_status' => 'parts', 'part_delete' => 'parts',
    'mileage_delete' => 'mileage_log', 'recall_status' => 'recalls', 'mechanic_save' => 'mechanics', 'mechanic_delete' => 'mechanics', 'link_save' => 'links', 'link_delete' => 'links',
];
if ($id && isset(OWNED_BY_ACTION[$action]) && !owns(OWNED_BY_ACTION[$action], $id)) { http_response_code(404); exit('Not found'); }
// Everything below works on the current vehicle; a new account has none until it adds one.
if (!vid() && !in_array($action, ['vehicle_add', 'logout', 'set_password', 'mechanic_save', 'mechanic_delete', 'link_save', 'link_delete', 'account_password', 'account_delete', 'remind_set', 'mode_set', 'guest_keep', 'guest_end', 'diag_run', 'diag_delete', 'diag_to_vehicle', 'customer_save', 'customer_delete', 'customer_vehicle_add', 'tool_save', 'tool_preset', 'tool_delete'], true)) { flash('Add a vehicle first.', 'err'); redirect(url('garage') . '#add'); }

switch ($action) {

case 'logout':
    // Throw the whole session away and continue on a fresh id, so the old id and CSRF token are dead.
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Signed out.');
    redirect(url('login'));

case 'account_password':
    // Multi-user: change this account's password.
    $u = current_user();
    if (!$u || !password_verify(post('current_password'), $u['pw_hash'])) { flash('Current password is wrong. Nothing changed.', 'err'); redirect(url('vehicle') . '#access'); }
    if (mb_strlen(post('password')) < 8 || post('password') !== post('password2')) { flash('New passwords must match and be at least 8 characters. Nothing changed.', 'err'); redirect(url('vehicle') . '#access'); }
    q('UPDATE users SET pw_hash=? WHERE id=?', [password_hash(post('password'), PASSWORD_DEFAULT), $u['id']]);
    flash('Password changed.'); redirect(url('vehicle'));

case 'account_delete':
    // Multi-user: delete the account and everything in it.
    $u = current_user();
    if (!$u || !password_verify(post('current_password'), $u['pw_hash'])) { flash('Password is wrong. Nothing was deleted.', 'err'); redirect(url('vehicle') . '#account'); }
    account_wipe((int)$u['id']);
    $_SESSION = []; session_regenerate_id(true);
    flash('Your account and everything in it were deleted.'); redirect(url('login'));

case 'set_password':
    if (MULTI_USER) { http_response_code(400); exit('Unknown action'); }
    // Change only. An empty field never removes protection (the live site would then be locked anyway);
    // a forgotten password is reset from the server with app/tools/set-password.php.
    $cur = (string)(col("SELECT value FROM settings WHERE key='password_hash'") ?: '');
    $pw = post('password');
    if ($cur !== '' && !password_verify(post('current_password'), $cur)) { flash('Current password is wrong. Nothing changed.', 'err'); redirect(url('vehicle') . '#access'); }
    if ($pw === '') { flash('Type a new password. Nothing changed.', 'err'); redirect(url('vehicle') . '#access'); }
    if (mb_strlen($pw) < 8) { flash('Use at least 8 characters. Nothing changed.', 'err'); redirect(url('vehicle') . '#access'); }
    if ($pw !== post('password2')) { flash('The two new passwords do not match. Nothing changed.', 'err'); redirect(url('vehicle') . '#access'); }
    $newHash = password_hash($pw, PASSWORD_DEFAULT);
    q("INSERT OR REPLACE INTO settings (key,value) VALUES ('password_hash',?)", [$newHash]);
    q('DELETE FROM login_attempts');
    sign_in($newHash); // this browser stays signed in; every other session is signed out
    flash($cur === '' ? 'Password set.' : 'Password changed. Other devices will need the new password.');
    redirect(url('vehicle'));

case 'update_miles':
    $m = post_int('miles');
    if ($m === null || $m <= 0) { flash('Enter a mileage.', 'err'); redirect(url('dashboard')); }
    $v = vehicle();
    $when = post('logged_at') ?: today();
    // A lower-or-equal reading dated before the current odometer reading is history: log it, but leave the odometer alone.
    // A higher reading always moves the odometer up (its date never moves "last updated" backwards).
    $historic = $v['mileage_updated'] && $when < (string)$v['mileage_updated'] && $m <= (int)$v['mileage'];
    if (!$historic && $m < (int)$v['mileage'] && post('force') !== '1') { flash('That is lower than the current ' . number_format((int)$v['mileage']) . ' mi. If the old number was wrong, tick "Allow a lower number" on this page and save again.', 'err'); redirect(url('mileage')); }
    insert('mileage_log', ['logged_at' => $when, 'miles' => $m, 'note' => post('note')]);
    if ($historic) { flash('Logged ' . number_format($m) . ' mi on ' . fmt_date($when) . ' (an older reading, so the current odometer stays ' . number_format((int)$v['mileage']) . ' mi).'); redirect(post('back') ?: url('dashboard')); }
    update('vehicle', ['mileage' => $m, 'mileage_updated' => max($when, (string)$v['mileage_updated'])], (int)$v['id']);
    flash('Mileage updated to ' . number_format($m) . ' mi.' . ($m < (int)$v['mileage'] ? ' If the old ' . number_format((int)$v['mileage']) . ' mi reading was a typo, remove it from the list with ×.' : ''));
    redirect(post('back') ?: url('dashboard'));

case 'mileage_delete':
    q('DELETE FROM mileage_log WHERE id=?', [$id]);
    // The newest reading (by date, then entry order) is the odometer, so a forced correction is not undone.
    $last = row('SELECT * FROM mileage_log WHERE ' . vw() . ' ORDER BY logged_at DESC, id DESC LIMIT 1');
    if ($last) update('vehicle', ['mileage' => (int)$last['miles'], 'mileage_updated' => $last['logged_at']], (int)vehicle()['id']);
    flash('Entry removed.'); redirect(url('mileage'));

case 'vehicle_save':
    $v = vehicle();
    $data = [];
    foreach (['year','make','model','trim','engine','transmission','vin','color','plate','notes','mods','nickname'] as $k) $data[$k] = post($k);
    $data['year'] = post_int('year');
    if (array_key_exists(post('kind'), VEHICLE_KINDS)) $data['kind'] = post('kind');
    update('vehicle', $data, (int)$v['id']);
    if (!empty($_FILES['photo']['name'])) {
        $pid = save_upload($_FILES['photo'], 'vehicle', (int)$v['id'], 'hero', 'Hero photo');
        if ($pid) { $p = row('SELECT path FROM photos WHERE id=?', [$pid]); update('vehicle', ['photo' => $p['path'], 'photo_credit' => null, 'hero_cutout' => null, 'hero_depth' => null, 'mesh_glb' => null, 'mesh_status' => null], (int)$v['id']); vision_process((int)$v['id']); }
        elseif ($e = flash()) { flash('Vehicle saved, but the photo was not: ' . $e['msg'], 'err'); redirect(url('vehicle')); }
    }
    flash('Vehicle saved.'); redirect(url('vehicle'));

case 'vehicle_add':
    if (MULTI_USER && count(vehicles()) >= (is_guest() ? 3 : LIMIT_VEHICLES)) { flash(is_guest() ? 'A guest garage holds up to 3 vehicles. Keep your garage (free) to add more.' : 'This account can hold up to ' . LIMIT_VEHICLES . ' vehicles.', 'err'); redirect(url('garage')); }
    $kind = array_key_exists(post('kind'), VEHICLE_KINDS) ? post('kind') : 'car';
    if (post('make') === '' || post('model') === '') { flash('Make and model are required.', 'err'); redirect(url('garage') . '#add'); }
    $miles = post_int('miles') ?? 0;
    $data = ['kind' => $kind, 'year' => post_int('year'), 'make' => post('make'), 'model' => post('model'), 'trim' => post('trim'), 'engine' => post('engine'),
        'transmission' => post('transmission'), 'vin' => strtoupper(post('vin')), 'color' => post('color'), 'plate' => post('plate'), 'nickname' => post('nickname'),
        'mileage' => $miles, 'mileage_updated' => today(), 'notes' => '', 'mods' => '', 'sort' => (int)col('SELECT COALESCE(MAX(sort),0)+1 FROM vehicle')];
    $nid = insert('vehicle', $data);
    vid($nid); // everything below (and the next page) belongs to the new vehicle
    if ($miles > 0) insert('mileage_log', ['logged_at' => today(), 'miles' => $miles, 'note' => 'Added to the garage']);
    seed_vehicle_templates($nid, $kind);
    $quick = add_quick_issues($nid, $kind, (array)($_POST['quick'] ?? []), $miles);
    $msg = vehicle_name($data) . ' added. A starter maintenance schedule and weekly checklist are in place; adjust them to the owner\'s manual.';
    if (!empty($_FILES['photo']['name'])) {
        $pid = save_upload($_FILES['photo'], 'vehicle', $nid, 'hero', 'Hero photo');
        if ($pid) { update('vehicle', ['photo' => col('SELECT path FROM photos WHERE id=?', [$pid])], $nid); vision_process($nid); }
        elseif ($e = flash()) $msg .= ' The photo was not saved: ' . $e['msg'];
    } elseif ($stock = find_stock_photo($data)) {
        insert('photos', ['kind' => 'vehicle', 'ref_id' => $nid, 'stage' => 'stock', 'path' => $stock['path'], 'caption' => $stock['credit'], 'taken_on' => today(), 'created_at' => now()]);
        update('vehicle', ['photo' => $stock['path'], 'photo_credit' => $stock['credit']], $nid);
        $msg .= ' Found a stand-in photo of the same model; replace it with yours any time.';
    } else {
        $msg .= ' No matching photo online, so it shows a drawing in its colours until you add a photo.';
    }
    if ($quick) $msg .= ' ' . $quick . ' issue' . ($quick === 1 ? '' : 's') . ' added from your answers.';
    flash($msg); redirect(url('dashboard', ['v' => $nid]));

case 'vision_redo':
    // (Re)make the cut-out + depth showroom image from the main photo.
    $v = vehicle();
    if (!$v['photo'] || $v['photo_credit']) { flash('Add your own photo first.', 'err'); redirect(url('vehicle') . '#photo'); }
    $r = vision_process((int)$v['id'], true);
    flash($r === 'ready' ? 'Showroom image updated.' : ($r === 'limit' ? 'Daily limit for image processing reached; try again tomorrow.' : 'The image service is busy or offline; it will retry automatically.'), $r === 'ready' ? 'ok' : 'err');
    redirect(url('dashboard'));

case 'vision_original':
    // Go back to showing the plain photo (keeps the cut-out files for "redo").
    update('vehicle', ['hero_cutout' => null, 'hero_depth' => null, 'vision_status' => 'off'], vid());
    flash('Showing your original photo.'); redirect(url('dashboard'));

case 'mesh_start':
    $r = mesh_start(vid());
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['status' => $r]); exit; }
    flash($r === 'running' ? 'Building a 3D model from your photo. This takes about a minute; you can keep using the app.' : ($r === 'limit' ? 'Daily limit for image processing reached.' : 'The 3D service is busy or offline right now. Try again later.'), $r === 'running' ? 'ok' : 'err');
    redirect(url('dashboard'));

case 'vehicle_stock_photo':
    $v = vehicle();
    if ($stock = find_stock_photo($v)) {
        insert('photos', ['kind' => 'vehicle', 'ref_id' => (int)$v['id'], 'stage' => 'stock', 'path' => $stock['path'], 'caption' => $stock['credit'], 'taken_on' => today(), 'created_at' => now()]);
        update('vehicle', ['photo' => $stock['path'], 'photo_credit' => $stock['credit']], (int)$v['id']);
        flash('Found a stand-in photo of the same model.');
    } else flash('No photo of this exact make and model was found online. It keeps the drawing until you add a photo.', 'err');
    redirect(url('vehicle'));

case 'vehicle_delete':
    $v = vehicle();
    if (!MULTI_USER && count(vehicles()) < 2) { flash('This is the only vehicle; add another before deleting it.', 'err'); redirect(url('vehicle')); }
    if (mb_strtolower(trim(post('confirm_name'))) !== mb_strtolower(trim((string)$v['model']))) { flash('Type the model name exactly to confirm. Nothing was deleted.', 'err'); redirect(url('vehicle') . '#danger'); }
    delete_vehicle((int)$v['id']);
    unset($_SESSION['vid']);
    flash(vehicle_name($v) . ' and all its records were deleted.'); redirect(url('garage'));

case 'vehicle_photo_clear':
    update('vehicle', ['photo' => null, 'photo_credit' => null], (int)vehicle()['id']); flash('Hero photo cleared (file kept in gallery).'); redirect(url('vehicle'));

case 'mechanic_save':
    $data = ['name' => post('name'), 'phone' => post('phone'), 'email' => post('email'), 'maps_url' => post('maps_url'), 'address' => post('address'), 'notes' => post('notes'), 'is_primary' => post('is_primary') === '1' ? 1 : 0];
    if ($data['is_primary']) q('UPDATE mechanics SET is_primary=0');
    if ($id) update('mechanics', $data, $id); else insert('mechanics', $data);
    flash('Mechanic saved.'); redirect(url('vehicle'));

case 'mechanic_delete':
    q('DELETE FROM mechanics WHERE id=?', [$id]); flash('Mechanic removed.'); redirect(url('vehicle'));

case 'issue_save':
    $data = [];
    foreach (['title','system','location','summary','evidence','likely_causes','owner_guidance','mechanic_guidance','est_cost','links','status'] as $k) $data[$k] = post($k);
    $data['severity'] = max(1, min(5, (int)post('severity', '2')));
    $data['opened_on'] = post('opened_on') ?: today();
    $data['resolved_on'] = post('resolved_on') ?: null;
    $data['caused_by_visit_id'] = post_int('caused_by_visit_id');
    if ($data['caused_by_visit_id'] && !owns('visits', $data['caused_by_visit_id'])) $data['caused_by_visit_id'] = null;
    if (!in_array($data['status'], ISSUE_STATUSES, true)) $data['status'] = 'open';
    if ($data['status'] === 'resolved' && !$data['resolved_on']) $data['resolved_on'] = today();
    if ($data['status'] !== 'resolved') $data['resolved_on'] = null; // reopening clears the old fix date
    $data['updated_at'] = now();
    if ($data['title'] === '') { flash('Title is required.', 'err'); redirect(url('issue_form', $id ? ['id' => $id] : [])); }
    if ($id) { update('issues', $data, $id); } else { $data['created_at'] = now(); $id = insert('issues', $data); }
    flash('Issue saved.'); redirect(url('issue', ['id' => $id]));

case 'issue_status':
    $s = post('status'); if (!in_array($s, ISSUE_STATUSES, true)) $s = 'open';
    $d = ['status' => $s, 'updated_at' => now()];
    if ($s === 'resolved') $d['resolved_on'] = today(); else $d['resolved_on'] = null;
    update('issues', $d, $id);
    insert('issue_notes', ['issue_id' => $id, 'noted_at' => today(), 'miles' => current_miles(), 'body' => 'Status changed to ' . $s . '.']);
    flash('Status updated.'); redirect(url('issue', ['id' => $id]));

case 'issue_delete':
    q('DELETE FROM issues WHERE id=?', [$id]); q('DELETE FROM issue_notes WHERE issue_id=?', [$id]); q('DELETE FROM visit_issues WHERE issue_id=?', [$id]);
    q('UPDATE parts SET issue_id=NULL WHERE issue_id=?', [$id]);
    q("UPDATE photos SET kind='vehicle', ref_id=? WHERE kind='issue' AND ref_id=?", [(int)vehicle()['id'], $id]); // photos stay in the gallery
    flash('Issue deleted. Its photos are kept under Photo record > Vehicle.'); redirect(url('issues'));

case 'issue_note_add':
    if (post('body') !== '') insert('issue_notes', ['issue_id' => $id, 'noted_at' => post('noted_at') ?: today(), 'miles' => post_int('miles') ?? current_miles(), 'body' => post('body')]);
    update('issues', ['updated_at' => now()], $id);
    redirect(url('issue', ['id' => $id]));

case 'note_delete':
    $n = row('SELECT issue_id FROM issue_notes WHERE id=?', [$id]); q('DELETE FROM issue_notes WHERE id=?', [$id]);
    redirect(url('issue', ['id' => (int)($n['issue_id'] ?? 0)]));

case 'photo_add':
    $kind = post('kind'); $ref = post_int('ref_id') ?? 0; $stage = post('stage') ?: 'general';
    $refTable = ['issue' => 'issues', 'visit' => 'visits', 'inspection' => 'inspections'][$kind] ?? null;
    if ($refTable ? !owns($refTable, $ref) : !owns_vehicle($ref)) { $kind = 'vehicle'; $ref = vid(); }
    if (MULTI_USER && (int)col('SELECT COUNT(*) FROM photos p JOIN vehicle v ON v.id=p.vehicle_id WHERE v.user_id=?', [uid()]) >= LIMIT_PHOTOS) { flash('This account has reached its photo limit (' . LIMIT_PHOTOS . '). Delete some to add more.', 'err'); redirect(post('back') ?: url('photos')); }
    $n = 0; $errs = []; $capNote = '';
    // PHP silently drops every file past max_file_uploads; if the batch is exactly at the limit, say so.
    $maxFiles = (int)ini_get('max_file_uploads');
    if ($maxFiles > 0 && count((array)($_FILES['photos']['name'] ?? [])) >= $maxFiles) $capNote = " At most $maxFiles files go up at once: if you picked more than $maxFiles, the rest were skipped, so upload them in another batch.";
    if (!empty($_FILES['photos']['name'][0])) {
        foreach ($_FILES['photos']['name'] as $i => $nm) {
            $f = ['name' => $nm, 'type' => $_FILES['photos']['type'][$i], 'tmp_name' => $_FILES['photos']['tmp_name'][$i], 'error' => $_FILES['photos']['error'][$i], 'size' => $_FILES['photos']['size'][$i]];
            if (save_upload($f, $kind, $ref, $stage, post('caption'), post('taken_on') ?: null)) $n++;
            elseif ($e = flash()) $errs[] = $e['msg']; // keep every failure, not just the last one
        }
    }
    if ($errs) flash(($n ? "$n photo(s) added. Not added: " : '') . implode(' ', $errs) . $capNote, 'err');
    elseif ($capNote) flash("$n photo(s) added." . $capNote, 'err');
    elseif ($n) flash("$n photo(s) added.");
    redirect(post('back') ?: url('photos'));

case 'photo_delete':
    $p = row('SELECT * FROM photos WHERE id=?', [$id]);
    if ($p) {
        q('DELETE FROM photos WHERE id=?', [$id]);
        // Only remove the file once nothing else points at it (the hero photo is also a gallery row).
        if (!(int)col('SELECT COUNT(*) FROM photos WHERE path=?', [$p['path']])) {
            q('UPDATE vehicle SET photo=NULL WHERE photo=?', [$p['path']]);
            $f = UPLOAD_DIR . '/' . basename((string)$p['path']);
            if (is_file($f)) @unlink($f);
        }
    }
    flash('Photo deleted.'); redirect(post('back') ?: url('photos'));

case 'photo_caption':
    update('photos', ['caption' => post('caption'), 'stage' => post('stage') ?: 'general'], $id);
    redirect(post('back') ?: url('photos'));

case 'code_event_add':
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('code')));
    if ($code === '') { flash('Enter a code.', 'err'); redirect(url('codes')); }
    insert('code_events', ['code' => $code, 'seen_on' => post('seen_on') ?: today(), 'miles' => post_int('miles') ?? current_miles(), 'status' => post('status') ?: 'active', 'note' => post('note')]);
    flash("Logged $code."); redirect(url('codes', ['q' => $code]));

case 'code_event_status':
    update('code_events', ['status' => post('status')], $id); redirect(url('codes'));

case 'code_event_delete':
    q('DELETE FROM code_events WHERE id=?', [$id]); redirect(url('codes'));

case 'code_save':
    if (!is_admin()) { flash('Only the site admin can change the shared code library. Log the code against your vehicle instead.', 'err'); redirect(url('codes')); }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post('code')));
    $data = ['code' => $code, 'title' => post('title'), 'system' => post('system'), 'severity' => max(1, min(5, (int)post('severity', '2'))), 'description' => post('description'), 'causes' => post('causes'), 'c300_note' => post('c300_note')];
    if ($id) update('obd_codes', $data, $id); else q('INSERT OR REPLACE INTO obd_codes (code,title,system,severity,description,causes,c300_note) VALUES (?,?,?,?,?,?,?)', array_values($data));
    flash("Saved $code to the library."); redirect(url('codes', ['q' => $code]));

case 'common_status':
    update('common_issues', ['status' => post('status'), 'this_car_note' => post('this_car_note')], $id);
    flash('Updated.'); redirect(url('common') . '#ci' . $id);

case 'common_to_issue':
    $c = row('SELECT * FROM common_issues WHERE id=?', [$id]);
    if ($c && ($ex = col("SELECT id FROM issues WHERE " . vw() . " AND title=? AND status IN ('open','monitoring','scheduled') ORDER BY id LIMIT 1", [$c['title']]))) {
        flash('This is already open as an issue on this car.'); redirect(url('issue', ['id' => (int)$ex]));
    }
    if ($c) {
        $nid = insert('issues', ['title' => $c['title'], 'system' => $c['system'], 'location' => $c['location'], 'severity' => $c['severity'], 'status' => 'open',
            'summary' => $c['symptoms'], 'evidence' => '', 'likely_causes' => $c['diagnosis'], 'owner_guidance' => '', 'mechanic_guidance' => $c['fix'],
            'opened_on' => today(), 'resolved_on' => null, 'caused_by_visit_id' => null, 'est_cost' => $c['typical_cost'], 'links' => $c['parts_link'], 'created_at' => now(), 'updated_at' => now()]);
        update('common_issues', ['status' => 'affected'], $id);
        flash('Issue created from the common-issue entry.'); redirect(url('issue', ['id' => $nid]));
    }
    redirect(url('common'));

case 'maint_save':
    $data = ['item' => post('item'), 'category' => post('category'), 'interval_miles' => post_int('interval_miles') ?? 0, 'interval_months' => post_int('interval_months') ?? 0,
        'last_miles' => post_int('last_miles'), 'last_date' => post('last_date') ?: null, 'spec' => post('spec'), 'parts_link' => post('parts_link'), 'notes' => post('notes'), 'priority' => post_int('priority') ?? 3, 'due_date' => post('due_date') ?: null];
    if ($data['item'] === '') { flash('Item name required.', 'err'); redirect(url('maintenance')); }
    if ($id) update('maintenance', $data, $id); else insert('maintenance', $data);
    flash('Maintenance item saved.'); redirect(url('maintenance'));

case 'maint_done':
    $m = row('SELECT * FROM maintenance WHERE id=?', [$id]);
    $doneOn = post('date') ?: today();
    // No mileage given for a past date: leave it unknown rather than stamping today's odometer on it.
    update('maintenance', ['last_miles' => post_int('miles') ?? ($doneOn < today() ? null : current_miles()), 'last_date' => $doneOn, 'due_date' => null], $id);
    flash('Marked done: ' . ($m['item'] ?? '')); redirect(url('maintenance'));

case 'maint_delete':
    q('DELETE FROM maintenance WHERE id=?', [$id]); flash('Removed.'); redirect(url('maintenance'));

case 'check_save':
    $data = ['category' => post('category') ?: 'General', 'item' => post('item'), 'how_to' => post('how_to'), 'frequency' => post('frequency') ?: 'weekly', 'sort' => post_int('sort') ?? 99, 'active' => 1];
    if ($data['item'] === '') { flash('Check name required.', 'err'); redirect(url('inspections')); }
    if ($id) update('checks', $data, $id); else insert('checks', $data);
    flash('Check saved.'); redirect(url('inspections'));

case 'check_toggle':
    q('UPDATE checks SET active = 1 - active WHERE id=?', [$id]); redirect(url('inspections'));

case 'inspection_save':
    $iid = insert('inspections', ['done_on' => post('done_on') ?: today(), 'miles' => post_int('miles') ?? current_miles(), 'kind' => post('kind') ?: 'weekly', 'summary' => post('summary')]);
    $results = $_POST['result'] ?? []; $notes = $_POST['note'] ?? [];
    $fails = 0;
    $ownChecks = array_flip(own_ids('checks', array_keys((array)$results)));
    foreach ($results as $cid => $r) {
        if (!isset($ownChecks[(int)$cid])) continue;
        $r = in_array($r, ['ok','warn','fail','skip'], true) ? $r : 'skip';
        if ($r === 'fail') $fails++;
        insert('inspection_results', ['inspection_id' => $iid, 'check_id' => (int)$cid, 'result' => $r, 'note' => trim((string)($notes[$cid] ?? ''))]);
    }
    $m = post_int('miles');
    if ($m && $m > current_miles()) { update('vehicle', ['mileage' => $m, 'mileage_updated' => post('done_on') ?: today()], (int)vehicle()['id']); insert('mileage_log', ['logged_at' => post('done_on') ?: today(), 'miles' => $m, 'note' => 'From inspection #' . $iid]); }
    flash('Inspection saved' . ($fails ? " with $fails failed check(s). Consider opening an issue." : '.'));
    redirect(url('inspection', ['id' => $iid]));

case 'inspection_delete':
    q('DELETE FROM inspections WHERE id=?', [$id]); q('DELETE FROM inspection_results WHERE inspection_id=?', [$id]);
    q("UPDATE photos SET kind='vehicle', ref_id=? WHERE kind='inspection' AND ref_id=?", [(int)vehicle()['id'], $id]); // photos stay in the gallery
    flash('Inspection deleted.'); redirect(url('inspections'));

case 'visit_save':
    $mid = post_int('mechanic_id'); if ($mid && !owns('mechanics', $mid)) $mid = null;
    $data = ['mechanic_id' => $mid, 'scheduled_on' => post('scheduled_on') ?: null, 'status' => post('status') ?: 'planned', 'title' => post('title'), 'planned_work' => post('planned_work'), 'miles' => post_int('miles')];
    if ($data['title'] === '') { flash('Give the visit a title.', 'err'); redirect(url('visit_form', $id ? ['id' => $id] : [])); }
    if ($id) update('visits', $data, $id); else { $data['created_at'] = now(); $id = insert('visits', $data); }
    // The form only lists active issues, so only those links are replaced: resolved/dismissed issues already
    // attached (e.g. fixed at this visit) stay attached.
    $offered = isset($_POST['offered_ids']) ? array_map('intval', (array)$_POST['offered_ids'])
        : array_map('intval', array_column(rows("SELECT id FROM issues WHERE " . vw() . " AND status IN ('open','monitoring','scheduled')"), 'id')); // form from before this change
    $checked = own_ids('issues', (array)($_POST['issue_ids'] ?? []));
    $offered = own_ids('issues', $offered);
    foreach ($offered as $iid) if (!in_array($iid, $checked, true)) q('DELETE FROM visit_issues WHERE visit_id=? AND issue_id=?', [$id, $iid]);
    foreach ($checked as $iid) if ($iid > 0 && !col('SELECT 1 FROM visit_issues WHERE visit_id=? AND issue_id=?', [$id, $iid])) q('INSERT INTO visit_issues (visit_id, issue_id) VALUES (?,?)', [$id, $iid]);
    if (($_POST['mark_scheduled'] ?? '') === '1') q("UPDATE issues SET status='scheduled', updated_at=? WHERE id IN (SELECT issue_id FROM visit_issues WHERE visit_id=?) AND status IN ('open','monitoring')", [now(), $id]);
    flash('Visit saved.'); redirect(url('visit', ['id' => $id]));

case 'visit_complete':
    $data = ['completed_work' => post('completed_work'), 'cost' => post_float('cost'), 'parts_cost' => post_float('parts_cost'), 'labor_cost' => post_float('labor_cost'), 'miles' => post_int('miles'), 'outcome' => post('outcome'), 'status' => post('status') ?: 'done', 'rating' => post_int('rating')];
    if ($data['cost'] === null && ($data['parts_cost'] !== null || $data['labor_cost'] !== null)) $data['cost'] = (float)($data['parts_cost'] ?? 0) + (float)($data['labor_cost'] ?? 0);
    if (post('completed_on')) $data['scheduled_on'] = post('completed_on');
    update('visits', $data, $id);
    if ($data['miles'] && $data['miles'] > current_miles()) { update('vehicle', ['mileage' => $data['miles'], 'mileage_updated' => post('completed_on') ?: today()], (int)vehicle()['id']); insert('mileage_log', ['logged_at' => post('completed_on') ?: today(), 'miles' => $data['miles'], 'note' => 'From visit #' . $id]); }
    // maintenance items ticked as done during the visit
    $doneOn = post('completed_on') ?: today();
    foreach (own_ids('maintenance', (array)($_POST['maint_done'] ?? [])) as $mid) update('maintenance', ['last_miles' => $data['miles'] ?? ($doneOn < today() ? null : current_miles()), 'last_date' => $doneOn, 'due_date' => null], (int)$mid);
    flash('Visit record updated.'); redirect(url('visit', ['id' => $id]));

case 'visit_signoff':
    $v = row('SELECT * FROM visits WHERE id=?', [$id]);
    if ($v['signed_off']) { update('visits', ['signed_off' => 0, 'signed_at' => null], $id); flash('Sign-off removed.'); }
    else {
        update('visits', ['signed_off' => 1, 'signed_at' => now(), 'status' => 'done'], $id);
        foreach (own_ids('issues', (array)($_POST['resolve_issue'] ?? [])) as $iid) { update('issues', ['status' => 'resolved', 'resolved_on' => today(), 'updated_at' => now()], (int)$iid); insert('issue_notes', ['issue_id' => (int)$iid, 'noted_at' => today(), 'miles' => $v['miles'] ?? current_miles(), 'body' => 'Resolved at visit #' . $id . ' (' . $v['title'] . ').']); }
        flash('Signed off.');
    }
    redirect(url('visit', ['id' => $id]));

case 'visit_delete':
    // Issues scheduled for this visit go back to open (unless another planned visit still covers them).
    q("UPDATE issues SET status='open', updated_at=? WHERE status='scheduled' AND id IN (SELECT issue_id FROM visit_issues WHERE visit_id=?)
       AND id NOT IN (SELECT vi.issue_id FROM visit_issues vi JOIN visits v ON v.id=vi.visit_id WHERE v.status='planned' AND v.id<>?)", [now(), $id, $id]);
    q('UPDATE issues SET caused_by_visit_id=NULL WHERE caused_by_visit_id=?', [$id]);
    q("UPDATE photos SET kind='vehicle', ref_id=? WHERE kind='visit' AND ref_id=?", [(int)vehicle()['id'], $id]); // photos/receipts stay in the gallery
    q('DELETE FROM visits WHERE id=?', [$id]); q('DELETE FROM visit_issues WHERE visit_id=?', [$id]);
    flash('Visit deleted. Its photos are kept under Photo record > Vehicle.'); redirect(url('visits'));

case 'part_save':
    $data = ['name' => post('name'), 'supplier' => post('supplier'), 'url' => post('url'), 'part_no' => post('part_no'), 'price' => post_float('price'), 'status' => post('status') ?: 'wanted', 'category' => post('category'), 'notes' => post('notes'), 'issue_id' => post_int('issue_id')];
    if ($data['issue_id'] && !owns('issues', $data['issue_id'])) $data['issue_id'] = null;
    if ($data['name'] === '') { flash('Part name required.', 'err'); redirect(url('parts')); }
    if ($id) update('parts', $data, $id); else { $data['created_at'] = now(); insert('parts', $data); }
    flash('Part saved.'); redirect(url('parts'));

case 'part_status':
    update('parts', ['status' => post('status')], $id); redirect(url('parts'));

case 'part_delete':
    q('DELETE FROM parts WHERE id=?', [$id]); flash('Removed.'); redirect(url('parts'));

case 'link_save':
    if (post('title') === '' || post('url') === '') { flash('Title and URL required.', 'err'); redirect(url('links')); }
    if (!preg_match('~^https?://~i', post('url'))) { flash('Links must start with http:// or https://', 'err'); redirect(url('links')); }
    $data = ['title' => post('title'), 'url' => post('url'), 'category' => post('category') ?: 'Reference', 'notes' => post('notes')];
    if ($id) update('links', $data, $id); else insert('links', $data);
    flash('Link saved.'); redirect(url('links'));

case 'link_delete':
    q('DELETE FROM links WHERE id=?', [$id]); redirect(url('links'));

case 'mode_set':
    ui_mode(post('mode') === 'pro' ? 'pro' : 'diy');
    redirect(post('back') ?: url('dashboard'));

case 'guest_keep':
    // Turn a guest garage into a normal account: everything already entered stays.
    if (!is_guest()) redirect(url('vehicle'));
    $email = mb_strtolower(post('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(post('password')) < 8) { flash('Enter a valid email and a password of at least 8 characters.', 'err'); redirect(url('vehicle') . '#access'); }
    if (col('SELECT 1 FROM users WHERE email=?', [$email])) { flash('That email already has an account. Use another email, or sign in to that account.', 'err'); redirect(url('vehicle') . '#access'); }
    q('UPDATE users SET email=?, pw_hash=?, is_guest=0, guest_expires=NULL, name=?, remind_weekly=? WHERE id=?', [$email, password_hash(post('password'), PASSWORD_DEFAULT), post('name'), post('remind') === '1' && reminders_available() ? 1 : 0, uid()]);
    $u = current_user();
    $sent = mail_verify($u);
    flash('Your garage is now a full account.' . ($sent ? ' Check your email to confirm the address.' : ''));
    redirect(url('dashboard'));

case 'guest_end':
    if (!is_guest()) redirect(url('vehicle'));
    account_wipe(uid());
    $_SESSION = []; session_regenerate_id(true);
    flash('Your guest garage was deleted.'); redirect(url('login'));

case 'diag_run':
    // Vehicle: one of the account's vehicles (own or customer), or typed in / decoded from a VIN for a one-off lookup.
    $vehId = post_int('vehicle_id') ?? 0;
    $vj = null;
    if ($vehId > 0) { if (!owns_vehicle($vehId)) { http_response_code(404); exit('Not found'); } }
    else {
        $vj = ['year' => post_int('year'), 'make' => post('make'), 'model' => post('model'), 'engine' => post('engine'), 'vin' => strtoupper(post('vin')), 'trim' => post('trim'), 'country' => post('country')];
        $vehId = null;
    }
    $text = post('codes') . "\n" . post('freeze'); $src = 'typed';
    if (!empty($_FILES['scan']['name'])) {
        $up = scan_upload_text($_FILES['scan']);
        if (!empty($up['error'])) flash($up['error'], 'err');
        $text .= "\n" . $up['text']; $src = $up['via'] ?? 'upload';
    }
    $codes = obd_extract_codes($text);
    $sym = array_values(array_intersect((array)($_POST['sym'] ?? []), array_keys(DIAG_SYMPTOMS)));
    if (!$codes && !$sym && trim(post('freeze')) === '' && empty($up['text'])) { flash('Enter at least one code (like P0171), upload a scan, or pick a symptom.', 'err'); redirect(url('diagnose')); }
    $rid = insert('diag_reports', ['user_id' => uid(), 'vehicle_id' => $vehId, 'customer_id' => $vehId ? col('SELECT customer_id FROM vehicle WHERE id=?', [$vehId]) : (owns_row('customers', post_int('customer_id') ?? 0) ? post_int('customer_id') : null),
        'vehicle_json' => $vj ? json_encode($vj) : null, 'codes' => json_encode($codes), 'freeze' => mb_substr(trim(post('freeze') . "\n" . (($src !== 'typed' && !empty($up['text'])) ? $up['text'] : '')), 0, 60000),
        'symptoms' => json_encode($sym), 'notes' => post('notes'), 'source' => $src, 'created_at' => now()]);
    redirect(url('diagnose', ['r' => $rid]));

case 'diag_delete':
    if (!owns_row('diag_reports', (int)$id)) { http_response_code(404); exit('Not found'); }
    q('DELETE FROM diag_reports WHERE id=?', [$id]);
    flash('Report deleted.'); redirect(url('diagnose'));

case 'diag_apply':
    // Put a report's codes on the vehicle's code history, and (optionally) open an issue per code with the guidance.
    $r = owns_row('diag_reports', (int)$id) ? row('SELECT * FROM diag_reports WHERE id=?', [$id]) : null;
    if (!$r || !$r['vehicle_id']) { http_response_code(404); exit('Not found'); }
    vid((int)$r['vehicle_id']); $v = vehicle();
    $added = 0; $iss = 0;
    foreach (json_decode((string)$r['codes'], true) ?: [] as $c) {
        if (!col("SELECT 1 FROM code_events WHERE vehicle_id=? AND code=? AND status='active'", [$v['id'], $c])) { insert('code_events', ['code' => $c, 'seen_on' => substr((string)$r['created_at'], 0, 10), 'miles' => (int)$v['mileage'], 'status' => 'active', 'note' => 'From diagnosis #' . $r['id']]); $added++; }
        if (post('issues') === '1' && !col("SELECT 1 FROM issues WHERE vehicle_id=? AND title LIKE ? AND status IN ('open','monitoring','scheduled')", [$v['id'], $c . '%'])) {
            $L = obd_lookup($c, $v); $k = $L['kb'] ?? [];
            insert('issues', ['title' => $c . ' · ' . $L['title'], 'system' => 'Engine', 'location' => 'engine', 'severity' => $L['sev'], 'status' => 'open',
                'summary' => (string)($k['plain'] ?? ''), 'evidence' => 'Code ' . $c . ' read on ' . fmt_date($r['created_at']) . ' at ' . number_format((int)$v['mileage']) . ' mi.',
                'likely_causes' => implode("\n", array_map(fn($x) => '- ' . $x[0], $k['causes'] ?? [])), 'owner_guidance' => implode("\n", array_map(fn($x) => '- ' . $x, $k['diy'] ?? [])),
                'mechanic_guidance' => implode("\n", array_map(fn($x) => '- ' . $x, array_merge($k['tests'] ?? [], $L['patterns']))),
                'opened_on' => today(), 'created_at' => now(), 'updated_at' => now(), 'est_cost' => isset($k['cost']) ? '$' . $k['cost'][0] . '-' . $k['cost'][1] : '']);
            $iss++;
        }
    }
    flash($added . ' code' . ($added === 1 ? '' : 's') . ' added to the code history' . ($iss ? ' and ' . $iss . ' issue' . ($iss === 1 ? '' : 's') . ' opened with the guidance' : '') . '.');
    redirect(url('diagnose', ['r' => $r['id']]));

case 'diag_to_vehicle':
    // A one-off lookup becomes a vehicle in the garage (own, or a customer's in Mechanic mode).
    $r = owns_row('diag_reports', (int)$id) ? row('SELECT * FROM diag_reports WHERE id=?', [$id]) : null;
    if (!$r || $r['vehicle_id'] || !$r['vehicle_json']) { http_response_code(404); exit('Not found'); }
    $vj = json_decode((string)$r['vehicle_json'], true) ?: [];
    $cust = owns_row('customers', post_int('customer_id') ?? 0) ? post_int('customer_id') : ($r['customer_id'] ?: null);
    if ($cust ? (int)col('SELECT COUNT(*) FROM vehicle WHERE user_id=? AND customer_id IS NOT NULL', [uid()]) >= LIMIT_CUSTOMER_VEHICLES : (MULTI_USER && count(vehicles()) >= LIMIT_VEHICLES)) { flash('Vehicle limit reached for this account.', 'err'); redirect(url('diagnose', ['r' => $id])); }
    $kind = 'car';
    $nid = insert('vehicle', ['kind' => $kind, 'year' => $vj['year'] ?? null, 'make' => $vj['make'] ?? '', 'model' => $vj['model'] ?? '', 'trim' => $vj['trim'] ?? '', 'engine' => $vj['engine'] ?? '', 'vin' => $vj['vin'] ?? '',
        'mileage' => post_int('miles') ?? 0, 'mileage_updated' => today(), 'notes' => '', 'mods' => '', 'customer_id' => $cust, 'sort' => (int)col('SELECT COALESCE(MAX(sort),0)+1 FROM vehicle')]);
    seed_vehicle_templates($nid, $kind);
    q('UPDATE diag_reports SET vehicle_id=?, customer_id=? WHERE id=?', [$nid, $cust, $id]);
    flash(trim(($vj['year'] ?? '') . ' ' . ($vj['make'] ?? '') . ' ' . ($vj['model'] ?? '')) . ' added' . ($cust ? ' to the customer.' : ' to your garage.'));
    redirect(url('diagnose', ['r' => $id]));

case 'customer_save':
    $cid = post_int('id') ?? 0;
    if ($cid && !owns_row('customers', $cid)) { http_response_code(404); exit('Not found'); }
    if (post('name') === '') { flash('Name is required.', 'err'); redirect(url('customers')); }
    $data = ['name' => post('name'), 'phone' => post('phone'), 'email' => post('email'), 'notes' => post('notes')];
    if ($cid) update('customers', $data, $cid); else $cid = insert('customers', $data + ['user_id' => uid(), 'created_at' => now()]);
    flash('Customer saved.'); redirect(url('customers', ['c' => $cid]));

case 'customer_delete':
    $cid = post_int('id') ?? 0;
    if (!owns_row('customers', $cid)) { http_response_code(404); exit('Not found'); }
    foreach (rows('SELECT id FROM vehicle WHERE customer_id=? AND user_id=?', [$cid, uid()]) as $cv) delete_vehicle((int)$cv['id']);
    q('DELETE FROM diag_reports WHERE customer_id=? AND user_id=?', [$cid, uid()]);
    q('DELETE FROM customers WHERE id=?', [$cid]);
    flash('Customer, their vehicles and reports deleted.'); redirect(url('customers'));

case 'customer_vehicle_add':
    $cid = post_int('customer_id') ?? 0;
    if (!owns_row('customers', $cid)) { http_response_code(404); exit('Not found'); }
    if ((int)col('SELECT COUNT(*) FROM vehicle WHERE user_id=? AND customer_id IS NOT NULL', [uid()]) >= LIMIT_CUSTOMER_VEHICLES) { flash('Customer vehicle limit reached.', 'err'); redirect(url('customers', ['c' => $cid])); }
    if (post('make') === '' && post('vin') === '') { flash('Enter a VIN or at least the make and model.', 'err'); redirect(url('customers', ['c' => $cid])); }
    $d = ['year' => post_int('year'), 'make' => post('make'), 'model' => post('model'), 'engine' => post('engine'), 'vin' => strtoupper(post('vin')), 'trim' => post('trim')];
    if ($d['vin'] !== '' && $d['make'] === '') { $dec = vin_decode_world($d['vin']); foreach (['year', 'make', 'model', 'engine', 'trim'] as $k) if (!empty($dec[$k])) $d[$k] = $dec[$k]; }
    $kind = array_key_exists(post('kind'), VEHICLE_KINDS) ? post('kind') : 'car';
    $nid = insert('vehicle', $d + ['kind' => $kind, 'plate' => post('plate'), 'color' => post('color'), 'mileage' => post_int('miles') ?? 0, 'mileage_updated' => today(), 'notes' => '', 'mods' => '', 'customer_id' => $cid, 'sort' => (int)col('SELECT COALESCE(MAX(sort),0)+1 FROM vehicle')]);
    seed_vehicle_templates($nid, $kind);
    flash('Vehicle added to the customer.'); redirect(url('customers', ['c' => $cid]));

case 'tool_save':
    $tid = post_int('id') ?? 0;
    if ($tid && !owns_row('tool_links', $tid)) { http_response_code(404); exit('Not found'); }
    if (!preg_match('~^https://~i', post('url')) || post('name') === '') { flash('Give the tool a name and an https:// address.', 'err'); redirect(url('tools')); }
    $data = ['grp' => post('grp') ?: 'My tools', 'name' => post('name'), 'url' => post('url'), 'notes' => post('notes')];
    if ($tid) update('tool_links', $data, $tid); else insert('tool_links', $data + ['user_id' => uid(), 'sort' => (int)col('SELECT COALESCE(MAX(sort),0)+1 FROM tool_links WHERE user_id=?', [uid()])]);
    flash('Saved.'); redirect(url('tools'));

case 'tool_preset':
    foreach ((array)($_POST['pick'] ?? []) as $pick) {
        [$g, $i] = array_pad(explode('|', (string)$pick, 2), 2, '');
        $p = TOOL_PRESETS[$g][(int)$i] ?? null;
        if ($p && !col('SELECT 1 FROM tool_links WHERE user_id=? AND url=?', [uid(), $p[1]])) insert('tool_links', ['user_id' => uid(), 'grp' => $g, 'name' => $p[0], 'url' => $p[1], 'notes' => '', 'sort' => 0]);
    }
    redirect(url('tools'));

case 'tool_delete':
    if (owns_row('tool_links', (int)$id)) q('DELETE FROM tool_links WHERE id=?', [$id]);
    redirect(url('tools'));

case 'recalls_check':
    $n = recalls_refresh(vid(), true);
    $total = (int)col("SELECT COUNT(*) FROM recalls WHERE " . vw());
    flash($n ? $n . ' new recall' . ($n === 1 ? '' : 's') . ' found. Check each one below.' : ((int)vehicle()['recalls_checked'] >= time() - 60 ? 'Checked with NHTSA just now: ' . ($total ? 'no new recalls.' : 'no recalls on record for this model.') : 'Could not reach NHTSA right now. Try again later.'), $n ? 'err' : 'ok');
    redirect(url('recalls'));

case 'recall_status':
    if (array_key_exists(post('status'), RECALL_STATUSES)) q('UPDATE recalls SET status=? WHERE id=?', [post('status'), $id]);
    redirect(url('recalls') . '#r' . $id);

case 'share_create':
    share_create(vid(), post('issues') === '1', post('costs') === '1', post('vin') === '1');
    flash('Your service record link is ready. Anyone with the link can view it (read-only); you can turn it off any time.');
    redirect(url('share'));

case 'share_revoke':
    q('UPDATE shares SET revoked=1 WHERE ' . vw());
    flash('Link turned off. Anyone who had it now sees "not available".');
    redirect(url('share'));

case 'remind_set':
    if (!MULTI_USER || !uid()) { http_response_code(400); exit('Unknown action'); }
    q('UPDATE users SET remind_weekly=? WHERE id=?', [post('on') === '1' ? 1 : 0, uid()]);
    flash(post('on') === '1' ? 'Weekly reminders on. You get an email only when something is due.' : 'Weekly reminders off.');
    redirect(url('vehicle') . '#account');

default:
    http_response_code(400); echo 'Unknown action';
}
