<?php
// Declined work follow-ups: who to remind about work they said "not now" to, with one-tap call / text from the shop's
// own phone, "Book it" (new estimate) and the recovery rate.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Follow-ups';
followups_sync();
$S = shop();
$all = followups_open();
$due = array_values(array_filter($all, fn($g) => $g['next'] <= today()));
$later = array_values(array_filter($all, fn($g) => $g['next'] > today()));
$st = followup_stats(90);
$csrfIn = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$digits = fn(?string $p) => preg_replace('/[^\d+]/', '', (string)$p);
$card = function (array $g) use ($S, $csrfIn, $digits): string {
    $ids = implode(',', $g['ids']); $ph = $digits($g['cphone']); $txt = followup_text($g, $S);
    $form = fn(string $a, string $label, string $cls = 'sm', string $extra = '') => '<form method="post"><input type="hidden" name="action" value="' . $a . '">' . $csrfIn . '<input type="hidden" name="ids" value="' . h($ids) . '"><input type="hidden" name="back" value="index.php?p=followups">' . $extra . '<button class="' . $cls . '">' . $label . '</button></form>';
    $age = $g['since'] ? max(0, -days_until((string)$g['since'])) : 0;
    $tries = ['Not contacted yet', 'Contacted once', 'Contacted twice', 'Contacted 3 times'][min(3, $g['count'])];
    return '<div class="fu-card' . ($g['rating'] === 'red' ? ' fu-red' : '') . '">'
        . '<div class="fu-top"><div class="grow"><b>' . h($g['cname'] ?: 'Walk-in') . '</b><small>' . h($g['car']) . ' · <a href="' . url('job', ['id' => $g['jid']]) . '">' . h($g['est_no']) . '</a> · ' . ($age === 0 ? 'today' : $age . ' day' . ($age === 1 ? '' : 's') . ' ago') . '</small></div>'
        . '<span class="fu-amt">' . ($g['amount'] > 0 ? money($g['amount']) : '') . '</span></div>'
        . '<div class="fu-what"><b>' . h($g['grp']) . '</b>' . ($g['rating'] ? ' <i class="tag ' . ($g['rating'] === 'red' ? 'tag-red' : 'tag-soon') . '">' . ($g['rating'] === 'red' ? 'Needs attention' : 'Watch') . '</i>' : '')
        . (!empty($g['finding']) ? '<small>Inspection: ' . h($g['finding']) . '</small>' : '') . '<small>' . h(implode(' · ', array_slice($g['lines'], 0, 3))) . '</small></div>'
        . '<div class="fu-acts">'
        . ($ph !== '' ? '<a class="btn sm" href="tel:' . h($ph) . '" data-fu-contact="' . h($ids) . '">📞 Call</a><a class="btn sm" href="sms:' . h($ph) . '?&amp;body=' . h(rawurlencode($txt)) . '" data-fu-contact="' . h($ids) . '">💬 Text</a>' : '')
        . ($g['cemail'] ? '<a class="btn sm" href="mailto:' . h($g['cemail']) . '?subject=' . h(rawurlencode('Your ' . ($g['model'] ?: 'car') . ': recommended work')) . '&amp;body=' . h(rawurlencode($txt)) . '" data-fu-contact="' . h($ids) . '">✉️ Email</a>' : '')
        . $form('fu_book', 'Book it', 'sm primary')
        . '<details class="menu-pop"><summary class="btn sm ghost">⋯</summary><div>' . $form('fu_contact', 'Mark as contacted', '') . $form('fu_snooze', 'Remind me next month', '') . $form('fu_dismiss', 'Not interested: remove', 'danger') . '</div></details>'
        . '</div><small class="fu-tries">' . h($tries) . ($g['last'] ? ' · last ' . h(date('M j', strtotime((string)$g['last']))) : '') . '</small></div>';
};
?>
<div class="head"><div><h1>Follow-ups</h1><p>Work customers said "not now" to. A friendly reminder brings a good share of it back: 3 days after, then a month, then two. It also shows on the estimate the next time the car comes in.</p></div>
  <a class="btn" href="<?= url('jobs') ?>">← Shop board</a></div>
<div class="kb-sum">
  <div class="card"><b><?= count($due) ?></b><small>to contact today</small></div>
  <div class="card"><b><?= money((float)array_sum(array_column($all, 'amount'))) ?></b><small>recommended, not yet booked</small></div>
  <div class="card"><b><?= money($st['won']) ?></b><small>won back in 90 days</small></div>
  <div class="card"><b><?= $st['rate'] === null ? '—' : $st['rate'] . '%' ?></b><small>recovery rate (shops typically get 10–25%)</small></div>
</div>
<section class="card"><h2>Contact today <span class="muted"><?= count($due) ?></span></h2>
  <?php if (!$due): ?><div class="all-good"><span>👌</span><div><b>Nobody to call today.</b><small>Declined work shows up here a few days after the customer says "not now".</small></div></div><?php endif; ?>
  <div class="fu-list"><?php foreach ($due as $g) echo $card($g); ?></div>
</section>
<?php if ($later): ?>
<section class="card" style="margin-top:16px"><h2>Coming up <span class="muted"><?= count($later) ?></span></h2>
  <div class="fu-list"><?php foreach ($later as $g): ?><div class="fu-later"><span class="grow"><b><?= h($g['cname'] ?: 'Walk-in') ?></b> · <?= h($g['grp']) ?><small><?= h($g['car']) ?> · <?= $g['amount'] > 0 ? money($g['amount']) . ' · ' : '' ?>next reminder <?= h(date('M j', strtotime((string)$g['next']))) ?></small></span><a class="btn sm ghost" href="<?= url('job', ['id' => $g['jid']]) ?>"><?= h($g['est_no']) ?></a></div><?php endforeach; ?></div>
</section>
<?php endif; ?>
<p class="muted" style="font-size:12.5px;margin-top:12px">Call, Text and Email open your own phone or mail app with a message ready; nothing is sent by <?= h(APP_NAME) ?>. Tapping one marks the customer as contacted.</p>
