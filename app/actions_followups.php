<?php
declare(strict_types=1);
// POST handlers for declined-work follow-ups (included from actions.php after the CSRF check).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$ids = followup_ids(post('ids'));
$back = str_starts_with(post('back'), 'index.php?p=') ? post('back') : url('followups');
if (!$ids) { if (post('ajax') === '1') { http_response_code(204); exit; } flash('That follow-up is already closed.', 'err'); redirect($back); }
$in = implode(',', $ids);

switch ($action) {
case 'fu_contact':
    $n = (int)col("SELECT MAX(fu_count) FROM job_lines WHERE id IN ($in)") + 1;
    if (isset(FU_STEPS[$n])) q("UPDATE job_lines SET fu_count=?, fu_last=?, fu_next=? WHERE id IN ($in)", [$n, now(), date('Y-m-d', strtotime('+' . FU_STEPS[$n] . ' days'))]);
    else q("UPDATE job_lines SET fu_count=?, fu_last=?, fu_state='cold' WHERE id IN ($in)", [$n, now()]);
    if (post('ajax') === '1') { http_response_code(204); exit; }
    flash(isset(FU_STEPS[$n]) ? 'Marked as contacted. Next reminder in ' . FU_STEPS[$n] . ' days.' : 'Marked as contacted. That was the last reminder; it will come up again when the car is in.');
    redirect($back);
case 'fu_snooze':
    q("UPDATE job_lines SET fu_next=? WHERE id IN ($in)", [date('Y-m-d', strtotime('+30 days'))]);
    flash('Moved to next month.'); redirect($back);
case 'fu_dismiss':
    q("UPDATE job_lines SET fu_state='dismissed' WHERE id IN ($in)");
    flash('Removed from follow-ups.'); redirect($back);
case 'fu_book':
    $into = post_int('into') ?? 0; $into = $into && owns_row('jobs', $into) ? $into : null;
    $jid = followup_book($ids, $into);
    flash($into ? 'Added to this estimate.' : 'New estimate made from the recommended work. Send it to the customer for approval.');
    redirect(url('job', ['id' => $jid]));
}
http_response_code(404); exit('Not found');
