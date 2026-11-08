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
if (!vid() && !in_array($action, ['vehicle_add', 'logout', 'set_password', 'mechanic_save', 'mechanic_delete', 'link_save', 'link_delete', 'account_password', 'account_delete', 'remind_set'], true)) { flash('Add a vehicle first.', 'err'); redirect(url('garage') . '#add'); }

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
    foreach (rows('SELECT id FROM vehicle WHERE user_id=?', [$u['id']]) as $dv) delete_vehicle((int)$dv['id']);
    q('DELETE FROM mechanics WHERE user_id=?', [$u['id']]); q('DELETE FROM links WHERE user_id=?', [$u['id']]); q('DELETE FROM users WHERE id=?', [$u['id']]);
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
    if (MULTI_USER && count(vehicles()) >= LIMIT_VEHICLES) { flash('This account can hold up to ' . LIMIT_VEHICLES . ' vehicles.', 'err'); redirect(url('garage')); }
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
