<?php
declare(strict_types=1);
// POST handlers for driver -> shop reports (included from actions.php after the CSRF check; $action and $id are set there).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$nf = function (): never { http_response_code(404); exit('Not found'); };
$ids = fn(string $k) => array_values(array_unique(array_filter(array_map('intval', (array)($_POST[$k] ?? [])))));

switch ($action) {

case 'handoff_create':
    $veh = vehicle(); if (!$veh || (int)$veh['user_id'] !== uid()) $nf();
    if (!rate_ok('handoff:' . uid(), 40, 86400)) { flash('Too many reports today. Try again tomorrow.', 'err'); redirect(url('send_shop')); }
    $issueIds = array_values(array_filter($ids('issue_ids'), fn($i) => (bool)col('SELECT 1 FROM issues WHERE id=? AND ' . vw(), [$i])));
    $photoIds = array_values(array_filter($ids('photo_ids'), fn($i) => (bool)col('SELECT 1 FROM photos WHERE id=? AND ' . vw(), [$i])));
    $active = array_column(rows("SELECT DISTINCT code FROM code_events WHERE " . vw() . " AND status='active'"), 'code');
    $codes = array_values(array_intersect(array_map('strval', (array)($_POST['codes'] ?? [])), $active));
    $diag = post_int('diag_id') ?? 0; $diag = $diag && col('SELECT 1 FROM diag_reports WHERE id=? AND user_id=? AND vehicle_id=?', [$diag, uid(), vid()]) ? $diag : null;
    $concern = mb_substr(post('concern'), 0, 2000);
    if ($concern === '' && !$issueIds && !$codes) { flash('Say what is going on, or pick at least one problem or code to send.', 'err'); redirect(url('send_shop')); }
    $email = filter_var(post('contact_email'), FILTER_VALIDATE_EMAIL) ? mb_substr(post('contact_email'), 0, 120) : '';
    $t = bin2hex(random_bytes(16));
    $hid = insert('handoffs', ['user_id' => uid(), 'vehicle_id' => vid(), 'token' => $t, 'concern' => $concern, 'contact_name' => mb_substr(post('contact_name'), 0, 80),
        'contact_phone' => mb_substr(post('contact_phone'), 0, 40), 'contact_email' => $email, 'issue_ids' => json_encode($issueIds), 'photo_ids' => json_encode($photoIds),
        'codes' => json_encode($codes), 'diag_id' => $diag, 'show_history' => post('show_history') === '1' ? 1 : 0, 'allow_return' => post('allow_return') === '1' ? 1 : 0, 'created_at' => now()]);
    flash('Your report is ready. Send the link to the shop.');
    redirect(url('send_shop', ['h' => $hid]));

case 'handoff_revoke':
    if (!col('SELECT 1 FROM handoffs WHERE id=? AND user_id=?', [(int)$id, uid()])) $nf();
    q('UPDATE handoffs SET revoked=1 WHERE id=?', [(int)$id]);
    flash('Link turned off. The shop can no longer open it.');
    redirect(url('send_shop'));

case 'handoff_import':
    $ho = handoff_by_token(post('t'));
    if (!$ho) { flash('That report is no longer available.', 'err'); redirect(url('jobs')); }
    if (!rate_ok('handoff-import:' . uid(), 60, 86400)) { flash('Too many imports today.', 'err'); redirect(url('jobs')); }
    if (!is_pro()) ui_mode('pro');
    $jid = handoff_import($ho);
    flash('Estimate created from ' . ($ho['contact_name'] ?: "the customer") . "'s report. Add the services and send it for approval.");
    redirect(url('job', ['id' => $jid]));

case 'light_scan':
    $_SESSION['light_scan'] = light_scan($_FILES['photo'] ?? null, is_guest() ? 'g:' . uid() : '');
    redirect(url('lights', ['scan' => 1]));

case 'handoff_return':
    if (!owns_row('jobs', (int)$id)) $nf();
    $ok = handoff_return((int)$id);
    flash($ok ? "Sent to the customer's " . APP_NAME . ': the work, the cost and any recommendations.' : 'Nothing to send: this job did not come from a customer report, or they turned that off.', $ok ? 'ok' : 'err');
    redirect(url('job', ['id' => (int)$id]));
}
$nf();
