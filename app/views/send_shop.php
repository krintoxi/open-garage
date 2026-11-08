<?php
// Driver: send a report about this vehicle to a shop (any shop: the link opens for anyone, and shops on this app
// turn it into an estimate in one tap). Also lists earlier reports with what the shop did with them.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Send to a shop';
$csrfIn = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$open = rows("SELECT * FROM issues WHERE " . vw() . " AND status IN ('open','monitoring','scheduled') ORDER BY severity DESC, opened_on DESC");
$codes = rows("SELECT code, MAX(seen_on) seen FROM code_events WHERE " . vw() . " AND status='active' GROUP BY code ORDER BY seen DESC");
$diag = row('SELECT * FROM diag_reports WHERE user_id=? AND vehicle_id=? AND created_at >= ? ORDER BY id DESC LIMIT 1', [uid(), vid(), date('Y-m-d', strtotime('-90 days'))]);
$photos = rows('SELECT * FROM photos WHERE ' . vw() . ' ORDER BY id DESC LIMIT 24');
$issueIds = array_map(fn($i) => (int)$i['id'], $open);
$me = current_user();
$prev = rows('SELECT * FROM handoffs WHERE user_id=? AND vehicle_id=? ORDER BY id DESC LIMIT 20', [uid(), vid()]);
$just = isset($_GET['h']) ? row('SELECT * FROM handoffs WHERE id=? AND user_id=? AND revoked=0', [(int)$_GET['h'], uid()]) : null;
$short = trim((string)($veh['nickname'] ?: $veh['model'])) ?: 'your vehicle';
$suggest = $open ? $open[0]['title'] : '';
?>
<div class="head"><div><h1>Send to a shop</h1><p>One link with what's going on with <?= h($short) ?>: the problems you noted, warning codes, photos and service history. The shop sees it before you arrive, and you don't have to explain it all again at the counter.</p></div></div>

<?php if ($just): $link = handoff_url($just['token']);
  $sms = 'Hi, here are the details on my ' . vehicle_name($veh) . ' before I come in: ' . $link; ?>
<div class="card ho-ready">
  <div class="ho-ready-top">
    <div class="ho-qr" data-qr="<?= h($link) ?>" aria-label="QR code for the link"></div>
    <div class="ho-ready-txt">
      <h2>Your report is ready ✅</h2>
      <p class="muted">Send this link to the shop, or let them scan the code at the counter. Anyone with the link can read it until you turn it off.</p>
      <div class="share-link"><input type="text" readonly value="<?= h($link) ?>" aria-label="Report link" data-select-all><button type="button" class="primary" data-copy="<?= h($link) ?>">Copy link</button></div>
      <div class="row" style="gap:8px;margin-top:10px;flex-wrap:wrap">
        <button type="button" class="btn" data-share-url="<?= h($link) ?>" data-share-title="<?= h(vehicle_name($veh)) ?>: details for the shop" hidden>Send…</button>
        <a class="btn" href="sms:?&amp;body=<?= h(rawurlencode($sms)) ?>">💬 Text it</a>
        <a class="btn" href="mailto:?subject=<?= h(rawurlencode(vehicle_name($veh) . ': details before my visit')) ?>&amp;body=<?= h(rawurlencode($sms)) ?>">✉️ Email it</a>
        <a class="btn ghost" href="<?= h($link) ?>" target="_blank" rel="noopener">See what they see ↗</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid g3">
  <div class="card" style="grid-column:span 2">
    <h2><?= $just ? 'Make another report' : 'What should the shop know?' ?></h2>
    <form method="post" class="ho-form"><input type="hidden" name="action" value="handoff_create"><?= $csrfIn ?>
      <label>What's going on? (your words)</label>
      <textarea name="concern" rows="3" maxlength="2000" placeholder="e.g. Grinding noise from the front when I brake, started last week. Check engine light came on yesterday."><?= h($just ? '' : $suggest) ?></textarea>

      <?php if ($open): ?><label>Problems to include</label>
        <div class="ho-picks"><?php foreach ($open as $i): $s = max(1, min(5, (int)$i['severity'])); ?><label class="ho-pick"><input type="checkbox" name="issue_ids[]" value="<?= (int)$i['id'] ?>" checked><span><i class="pdot sev-<?= $s ?>"></i><b><?= h($i['title']) ?></b><small><?= h(SEV_WORDS[$s]) ?><?= $i['opened_on'] ? ' · since ' . h(date('M j', strtotime((string)$i['opened_on']))) : '' ?></small></span></label><?php endforeach; ?></div><?php endif; ?>

      <?php if ($codes): ?><label>Warning codes</label>
        <div class="ho-picks"><?php foreach ($codes as $c): $lib = obd_lookup($c['code']); ?><label class="ho-pick"><input type="checkbox" name="codes[]" value="<?= h($c['code']) ?>" checked><span><b><span class="code"><?= h($c['code']) ?></span> <?= h($lib['title']) ?></b><small>seen <?= h(date('M j', strtotime((string)$c['seen']))) ?></small></span></label><?php endforeach; ?></div><?php endif; ?>

      <?php if ($diag): ?><label class="inline" style="margin-top:12px"><input type="checkbox" name="diag_id" value="<?= (int)$diag['id'] ?>" checked> Include my latest diagnosis (<?= h(fmt_date($diag['created_at'])) ?>)</label><?php endif; ?>

      <?php if ($photos): ?><label>Photos (tap to include)</label>
        <div class="ho-photos"><?php foreach ($photos as $p): $onIssue = $p['kind'] === 'issue' && in_array((int)$p['ref_id'], $issueIds, true); ?><label><input type="checkbox" name="photo_ids[]" value="<?= (int)$p['id'] ?>" <?= $onIssue ? 'checked' : '' ?>><img src="<?= h(photo_url($p['path'])) ?>" alt="<?= h($p['caption']) ?>" loading="lazy"></label><?php endforeach; ?></div><?php endif; ?>

      <label>How the shop can reach you</label>
      <div class="form-grid" style="margin-top:0">
        <div><input type="text" name="contact_name" maxlength="80" placeholder="Your name" value="<?= h((string)($me['name'] ?? '')) ?>" autocomplete="name"></div>
        <div><input type="tel" name="contact_phone" maxlength="40" placeholder="Phone" autocomplete="tel"></div>
        <div class="full"><input type="email" name="contact_email" maxlength="120" placeholder="Email (optional)" value="<?= h(is_guest() ? '' : (string)($me['email'] ?? '')) ?>" autocomplete="email"></div>
      </div>
      <label class="inline" style="margin-top:12px"><input type="checkbox" name="show_history" value="1" checked> Include my service history (past visits, last oil change and so on)</label>
      <label class="inline"><input type="checkbox" name="allow_return" value="1" checked> Let the shop send the finished work back into my garage, marked verified</label>
      <div style="margin-top:14px"><button class="primary">Make my link</button></div>
      <p class="muted" style="font-size:12.5px;margin-top:10px">Only what you tick is shared, plus the vehicle (year, make, model, VIN and mileage). You can turn a link off any time. We never sell your data or share it with insurers.</p>
    </form>
  </div>
  <div class="aside">
    <div class="card"><h2>How it works</h2>
      <ol class="ho-steps">
        <li><b>You send the link</b> by text or email, or the shop scans the code.</li>
        <li><b>The shop reads it</b> before you arrive. Shops using <?= h(APP_NAME) ?> turn it into an estimate in one tap.</li>
        <li><b>The work comes back to you.</b> When they finish, the visit lands in your history marked verified, with anything they recommended for later.</li>
      </ol></div>
    <?php if ($prev): ?>
    <div class="card" style="margin-top:14px"><h2>Sent before</h2>
      <ul class="plist"><?php foreach ($prev as $p): ?>
        <li><a href="<?= h(handoff_url($p['token'])) ?>" target="_blank" rel="noopener"><span class="grow"><b><?= h(mb_strimwidth((string)($p['concern'] ?: 'Report'), 0, 50, '…')) ?></b>
          <small><?= h(date('M j', strtotime((string)$p['created_at']))) ?> · <?= $p['revoked'] ? 'link off' : ($p['returned_at'] ? '✓ work received from ' . h((string)$p['shop_name']) : ($p['imported_at'] ? h((string)$p['shop_name']) . ' made an estimate' : ((int)$p['views'] ? 'opened ' . (int)$p['views'] . '×' : 'not opened yet'))) ?></small></span></a>
          <?php if (!$p['revoked']): ?><form method="post" class="ho-off"><input type="hidden" name="action" value="handoff_revoke"><?= $csrfIn ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="sm ghost" data-confirm="Turn this link off? The shop will no longer be able to open it.">Turn off</button></form><?php endif; ?></li>
      <?php endforeach; ?></ul></div>
    <?php endif; ?>
  </div>
</div>
