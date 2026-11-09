<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
if (!MULTI_USER || !is_admin()) { http_response_code(404); echo '<div class="empty">Not found.</div>'; return; }
$title = 'Site admin';
$qq = trim((string)($_GET['q'] ?? ''));
$users = admin_users($qq);
$csrf = '<input type="hidden" name="_csrf" value="' . csrf() . '">';
$st = [
    'accounts' => (int)col('SELECT COUNT(*) FROM users WHERE is_guest=0'),
    'new7' => (int)col('SELECT COUNT(*) FROM users WHERE is_guest=0 AND created_at >= ?', [date('Y-m-d', strtotime('-7 days'))]),
    'active7' => (int)col('SELECT COUNT(*) FROM users WHERE last_login >= ?', [date('Y-m-d', strtotime('-7 days'))]),
    'guests' => (int)col('SELECT COUNT(*) FROM users WHERE is_guest=1 AND guest_expires >= ?', [time()]),
    'expired' => (int)col('SELECT COUNT(*) FROM users WHERE is_guest=1 AND guest_expires < ?', [time()]),
    'vehicles' => (int)col('SELECT COUNT(*) FROM vehicle'),
    'shops' => (int)col("SELECT COUNT(*) FROM users WHERE mode='pro' AND is_guest=0"),
    'jobs' => (int)col('SELECT COUNT(*) FROM jobs'),
    'diag' => (int)col('SELECT COUNT(*) FROM diag_reports WHERE created_at >= ?', [date('Y-m-d', strtotime('-7 days'))]),
];
$open = signups_open();
?>
<div class="head"><div><h1>Site admin</h1><p><?= h(base_url()) ?> · accounts, guests and sign-ups. Account contents stay private: this page shows counts only.</p></div></div>
<div class="kpi">
  <div class="card"><div class="stat"><small>Accounts</small><b><?= $st['accounts'] ?></b><small><?= $st['new7'] ?> new · <?= $st['active7'] ?> signed in this week</small></div></div>
  <div class="card"><div class="stat"><small>Guest garages</small><b><?= $st['guests'] ?></b><small><?= $st['expired'] ?> expired, waiting to be removed</small></div></div>
  <div class="card"><div class="stat"><small>Vehicles</small><b><?= $st['vehicles'] ?></b><small><?= $st['diag'] ?> diagnoses this week</small></div></div>
  <div class="card"><div class="stat"><small>Shops (mechanic mode)</small><b><?= $st['shops'] ?></b><small><?= $st['jobs'] ?> repair orders</small></div></div>
</div>
<div class="grid g3">
  <div class="card" style="grid-column:span 2;overflow-x:auto">
    <form method="get" class="row" style="flex-wrap:nowrap;margin-bottom:10px"><input type="hidden" name="p" value="admin"><input type="search" name="q" value="<?= h($qq) ?>" placeholder="Find by email or name"><button class="sm">Find</button></form>
    <table><thead><tr><th>Account</th><th>Joined</th><th>Last sign-in</th><th class="num">Own</th><th class="num">Customers'</th><th class="num">ROs</th><th></th></tr></thead><tbody>
    <?php foreach ($users as $u): ?><tr>
      <td><b><?= h((string)$u['email']) ?></b><?= $u['is_admin'] ? ' <span class="st st-active">admin</span>' : '' ?><?= $u['mode'] === 'pro' ? ' <span class="st">shop</span>' : '' ?><?= !$u['verified_at'] ? ' <span class="st st-na">unverified</span>' : '' ?><br><small class="muted">#<?= (int)$u['id'] ?><?= $u['name'] ? ' · ' . h((string)$u['name']) : '' ?></small></td>
      <td><?= fmt_date($u['created_at']) ?></td><td><?= $u['last_login'] ? fmt_date($u['last_login']) : '—' ?></td>
      <td class="num"><?= (int)$u['nveh'] ?></td><td class="num"><?= (int)$u['ncust'] ?></td><td class="num"><?= (int)$u['njobs'] ?></td>
      <td><?php if ((int)$u['id'] !== uid()): ?><details class="menu-pop"><summary class="btn sm ghost">⋯</summary><div>
        <form method="post"><input type="hidden" name="action" value="admin_toggle"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button><?= $u['is_admin'] ? 'Remove admin' : 'Make admin' ?></button></form>
        <form method="post" class="admin-del"><input type="hidden" name="action" value="admin_delete"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="text" name="confirm" placeholder="type the email to delete" autocomplete="off"><button class="danger">Delete account</button></form>
      </div></details><?php else: ?><small class="muted">you</small><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="aside">
    <div class="card"><h2>Sign-ups</h2>
      <p><?= $open ? 'Open: anyone can create a free account.' : 'Closed: existing accounts still sign in.' ?> Guests: <?= GUESTS ? 'on' : 'off' ?>. Public code lookup: <?= PUBLIC_LOOKUP ? 'on' : 'off' ?>.</p>
      <form method="post"><input type="hidden" name="action" value="admin_signups"><?= $csrf ?><button class="<?= $open ? 'ghost' : 'primary' ?> sm" name="open" value="<?= $open ? '0' : '1' ?>"><?= $open ? 'Close sign-ups' : 'Open sign-ups' ?></button></form></div>
    <div class="card" style="margin-top:14px"><h2>Guest garages</h2><p class="muted" style="font-size:13px">Kept <?= GUEST_DAYS ?> days unless the guest adds an email. Expired ones are also removed automatically when someone opens the sign-in page.</p>
      <form method="post"><input type="hidden" name="action" value="admin_purge_guests"><?= $csrf ?><button class="sm" <?= $st['expired'] ? '' : 'disabled' ?>>Remove <?= $st['expired'] ?> expired now</button></form></div>
    <div class="card" style="margin-top:14px"><h2>Services</h2><ul class="list">
      <li><div class="grow">Email (Resend)</div><span class="st <?= MAIL_KEY !== '' ? 'st-ok' : 'st-na' ?>"><?= MAIL_KEY !== '' ? 'on' : 'off' ?></span></li>
      <li><div class="grow">Photo reader / showroom (M4 vision)</div><span class="st <?= VISION_URL !== '' ? 'st-ok' : 'st-na' ?>"><?= VISION_URL !== '' ? 'on' : 'off' ?></span></li>
      <li><div class="grow">YouTube in-app videos</div><span class="st <?= YOUTUBE_KEY !== '' ? 'st-ok' : 'st-na' ?>"><?= YOUTUBE_KEY !== '' ? 'on' : 'off (needs a key)' ?></span></li>
      <li><div class="grow">PartsTech parts ordering</div><span class="st <?= partstech_ready() ? 'st-ok' : 'st-na' ?>"><?= partstech_ready() ? 'on' : 'applied 2026-11-09, waiting for PartsTech' ?></span></li>
      <li><div class="grow">Plate → VIN lookups</div><span class="st <?= plate_lookup_available() ? 'st-ok' : 'st-na' ?>"><?= plate_lookup_available() ? h(PLATE_PROVIDER) : 'uses each account\'s own records (free)' ?></span></li></ul></div>
  </div>
</div>
