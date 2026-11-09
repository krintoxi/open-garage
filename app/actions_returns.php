<?php
declare(strict_types=1);
// POST handlers for cores and part returns (included from actions.php after the CSRF check).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$own = fn(int $rid) => $rid > 0 && (bool)col('SELECT 1 FROM part_returns WHERE id=? AND user_id=?', [$rid, uid()]);
switch ($action) {
case 'ret_add':
    if (post('description') === '') { flash('Describe the part.', 'err'); redirect(url('purchasing') . '#returns'); }
    $vid = post_int('vendor_id') ?? 0; $vid = owns_row('vendors', $vid) ? $vid : null;
    $jid = post_int('job_id') ?? 0; $jid = owns_row('jobs', $jid) ? $jid : null;
    insert('part_returns', ['user_id' => uid(), 'kind' => array_key_exists(post('kind'), RETURN_KINDS) ? post('kind') : 'return', 'description' => mb_substr(post('description'), 0, 200), 'part_no' => mb_substr(post('part_no'), 0, 60),
        'vendor_id' => $vid, 'vendor' => $vid ? (string)col('SELECT name FROM vendors WHERE id=?', [$vid]) : '', 'job_id' => $jid, 'amount' => max(0, post_float('amount') ?? 0), 'status' => 'open',
        'due_on' => date('Y-m-d', strtotime('+' . max(1, min(120, post_int('days') ?? 30)) . ' days')), 'note' => mb_substr(post('note'), 0, 300), 'created_at' => now()]);
    flash('Added to returns.'); redirect(url('purchasing') . '#returns');
case 'ret_status':
    if (!$own((int)$id)) { http_response_code(404); exit('Not found'); }
    $to = array_key_exists(post('to'), RETURN_STATUS) ? post('to') : 'open';
    $d = ['status' => $to];
    if ($to === 'sent') $d['sent_on'] = today();
    if ($to === 'credited') { $d['credited_on'] = today(); $d['credit_ref'] = mb_substr(post('credit_ref'), 0, 60); if (post_float('amount') !== null) $d['amount'] = max(0, post_float('amount')); }
    update('part_returns', $d, (int)$id);
    flash(['sent' => 'Marked as sent back.', 'credited' => 'Credit recorded.', 'lost' => 'Written off.', 'open' => 'Reopened.'][$to]); redirect(url('purchasing') . '#returns');
case 'ret_delete':
    if (!$own((int)$id)) { http_response_code(404); exit('Not found'); }
    q('DELETE FROM part_returns WHERE id=?', [(int)$id]); redirect(url('purchasing') . '#returns');
}
http_response_code(404); exit('Not found');
