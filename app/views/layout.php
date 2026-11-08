<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$veh += ['id' => 0, 'year' => '', 'make' => '', 'model' => '', 'trim' => '', 'kind' => 'car', 'mileage_updated' => null];
$two = is_two_wheeler($veh);
$has = fn(string $t) => (int)col("SELECT COUNT(*) FROM $t WHERE " . vw()) > 0;
$nav = array_values(array_filter([
    ['dashboard', 'Home', '◈'],
    ['issues', 'Issues', '⚠'],
    ['diagnose', 'Diagnose codes', '⌁'],
    ['maintenance', 'Maintenance', '⟳'],
    ['garage', 'Garage', '⌂'],
    is_pro() ? ['customers', 'Customers', '☺'] : null,
    ['visits', 'Mechanic visits', '🔧'],
    ['tools', 'My tools', '⧉'],
    ['recalls', 'Safety recalls', '⚑'],
    ['share', 'Share record', '⇪'],
    ['inspections', 'Weekly checks', '☑'],
    $two ? null : ['codes', 'Code library', '≡'],
    $has('common_issues') ? ['common', 'Known issues', '📘'] : null,
    ['parts', 'Parts & upgrades', '⚙'],
    $has('specs') ? ['specs', 'Specs & torque', '⊞'] : null,
    $has('manual_pages') ? ['manual', "Owner's manual", '📖'] : null,
    ['photos', 'Photo record', '▣'],
    ['mileage', 'Mileage log', '⏱'],
    ['links', 'Links', '↗'],
    ['vehicle', 'Vehicle & settings', '▤'],
]));
$primary = array_slice($nav, 0, 5); $more = array_slice($nav, 5);
$allVehicles = vehicles();
$openCount = (int)col("SELECT COUNT(*) FROM issues WHERE " . vw() . " AND status IN ('open','monitoring','scheduled')");
$recallCount = $veh['id'] ? (int)col("SELECT COUNT(*) FROM recalls WHERE " . vw() . " AND status='open'") : 0;
$hasVeh = (bool)$veh['id'];
$f = flash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?> · <?= h(trim(($veh['year'] ?? '') . ' ' . ($veh['model'] ?? '')) ?: 'Garage') ?></title>
<link rel="manifest" href="index.php?p=manifest">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="icon" type="image/png" href="assets/favicon.png">
<meta name="theme-color" content="#07080a">
<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=13">
<?php if ($use3d || !empty($useStage)): ?>
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"}}</script>
<?php endif; ?>
</head>
<?php $ul = upload_limits(); ?>
<body class="<?= $hasVeh ? 'has-tabbar' : '' ?>" data-max-files="<?= $ul['files'] ?>" data-max-file-bytes="<?= $ul['file_bytes'] ?>" data-max-total-bytes="<?= $ul['total_bytes'] ?>">
<?php if (is_guest()): $gu = current_user(); ?><div class="guest-bar">Guest garage: kept until <?= h(date('M j', (int)$gu['guest_expires'])) ?>. <a href="<?= url('garage') ?>#keep"><b>Keep it free →</b></a></div><?php endif; ?>
<div class="shell">
  <aside class="side">
    <a class="brand" href="<?= url('dashboard') ?>">
      <span class="star"><?= $two ? '🛵' : '✦' ?></span>
      <span><b><?= h(($veh['year'] ?? '') . ' ' . ($veh['model'] ?? '')) ?></b><small><?= h($veh['trim'] ?: ($veh['make'] ?? '')) ?></small></span>
    </a>
    <?php if (count($allVehicles) > 1): ?>
    <div class="vswitch" role="navigation" aria-label="Switch vehicle">
      <?php foreach ($allVehicles as $sv): $on = (int)$sv['id'] === (int)$veh['id']; ?>
        <a href="<?= url($on ? 'garage' : 'dashboard', ['v' => $sv['id']]) ?>" class="<?= $on ? 'on' : '' ?>" title="<?= h(vehicle_name($sv)) ?>"><?= in_array($sv['kind'], ['scooter', 'motorcycle'], true) ? '🛵' : '🚗' ?> <?= h(($sv['nickname'] ?: $sv['model']) ?: $sv['make']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <nav>
      <?php if ($hasVeh): ?><button type="button" class="quick-btn" data-sheet-open="quick">＋ Quick add</button><?php endif; ?>
      <?php $navLink = function ($p, $label, $ico) use ($page, $openCount, $recallCount) {
          $active = $page === $p || ($p === 'issues' && in_array($page, ['issue','issue_form'])) || ($p === 'visits' && in_array($page, ['visit','visit_form'])) || ($p === 'inspections' && in_array($page, ['inspection','inspection_new']));
          return '<a href="' . url($p) . '" class="' . ($active ? 'active' : '') . '"><span class="ico">' . $ico . '</span>' . h($label) . ($p === 'issues' && $openCount ? '<span class="pill">' . $openCount . '</span>' : '') . ($p === 'recalls' && $recallCount ? '<span class="pill">' . $recallCount . '</span>' : '') . '</a>';
      };
      foreach ($primary as [$p, $label, $ico]) echo $navLink($p, $label, $ico);
      $moreActive = in_array($page, array_merge(array_column($more, 0), ['inspection', 'inspection_new']), true); ?>
      <details class="nav-more" <?= $moreActive ? 'open' : '' ?>><summary><span class="ico">⋯</span>More</summary>
        <?php foreach ($more as [$p, $label, $ico]) echo $navLink($p, $label, $ico); ?>
      </details>
    </nav>
    <form method="post" class="mode-switch side-mode"><input type="hidden" name="action" value="mode_set"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
      <button name="mode" value="diy" class="<?= is_pro() ? '' : 'on' ?>">DIY</button><button name="mode" value="pro" class="<?= is_pro() ? 'on' : '' ?>">Mechanic</button></form>
    <div class="side-foot">
      <div class="odo"><small>Odometer</small><b><?= number_format($miles) ?></b><small>updated <?= fmt_date($veh['mileage_updated'] ?? null) ?></small></div>
      <form method="post" class="quick-miles">
        <input type="hidden" name="action" value="update_miles"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
        <input type="number" name="miles" placeholder="new miles" min="0" inputmode="numeric" required>
        <button type="submit" title="Update mileage">↑</button>
      </form>
      <?php if (MULTI_USER || (string)(col("SELECT value FROM settings WHERE key='password_hash'") ?: '') !== ''): ?>
      <form method="post" class="side-logout"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button type="submit" class="sm ghost">Log out</button></form>
      <?php endif; ?>
    </div>
  </aside>
  <main class="main">
    <?php if ($f): ?><div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div><?php endif; ?>
    <?= $content ?>
  </main>
</div>
<?php if ($hasVeh): ?>
<header class="mtop">
  <a class="mtop-brand" href="<?= url('garage') ?>" aria-label="Your garage: switch vehicle"><span class="star"><?= $two ? '🛵' : '✦' ?></span><span><b><?= h(($veh['nickname'] ?: trim($veh['year'] . ' ' . $veh['model'])) ?: 'Garage') ?></b><small><?= number_format($miles) ?> mi<?= count($allVehicles) > 1 ? ' · ' . count($allVehicles) . ' vehicles ▾' : '' ?></small></span></a>
  <button type="button" class="mtop-miles" data-sheet-open="quick" aria-label="Quick add">＋</button>
</header>
<nav class="tabbar" aria-label="Main">
  <?php $tab = fn($p, $ico, $label, $on, $badge = 0) => '<a href="' . url($p) . '" class="' . ($on ? 'on' : '') . '"><span class="ti">' . $ico . ($badge ? '<i>' . $badge . '</i>' : '') . '</span>' . $label . '</a>'; ?>
  <?= $tab('dashboard', '◈', 'Home', $page === 'dashboard') ?>
  <?= $tab('diagnose', '⌁', 'Diagnose', in_array($page, ['diagnose', 'codes'], true)) ?>
  <button type="button" class="tab-add" data-sheet-open="quick" aria-label="Quick add"><span>＋</span></button>
  <?= $tab('maintenance', '⟳', 'Service', in_array($page, ['maintenance', 'visits', 'visit', 'visit_form'], true)) ?>
  <button type="button" class="<?= in_array($page, array_merge(array_column($more, 0), ['garage', 'vehicle']), true) ? 'on' : '' ?>" data-sheet-open="menu"><span class="ti">☰<?= $recallCount ? '<i>' . $recallCount . '</i>' : '' ?></span>Menu</button>
</nav>
<dialog class="sheet" id="sheet-quick" aria-label="Quick add">
  <div class="sheet-grip"></div>
  <h3>Quick add</h3>
  <div class="quick-grid">
    <a href="<?= url('diagnose') ?>"><span>⌁</span>Enter / scan codes</a>
    <a href="<?= url('issue_form') ?>"><span>⚠</span>Report a problem</a>
    <a href="<?= url('maintenance') ?>"><span>⟳</span>Service done</a>
    <a href="<?= url('photos') ?>"><span>▣</span>Add photos</a>
    <a href="<?= url('visit_form') ?>"><span>🔧</span>Book a mechanic</a>
    <a href="<?= url('inspection_new') ?>"><span>☑</span>Weekly check</a>
    <a href="<?= url('parts') ?>"><span>⚙</span>Part to buy</a>
    <a href="<?= url('mileage') ?>"><span>⏱</span>Mileage log</a>
  </div>
  <form method="post" class="sheet-miles"><input type="hidden" name="action" value="update_miles"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
    <label for="qm">Odometer now</label><div class="row"><input id="qm" type="number" name="miles" placeholder="<?= number_format($miles) ?>" min="0" inputmode="numeric" required><button class="primary" type="submit">Update</button></div></form>
  <button type="button" class="sheet-close ghost" data-sheet-close>Close</button>
</dialog>
<dialog class="sheet" id="sheet-menu" aria-label="Menu">
  <div class="sheet-grip"></div>
  <div class="sheet-vehicles">
    <?php foreach ($allVehicles as $sv): $on = (int)$sv['id'] === (int)$veh['id']; ?>
      <a href="<?= url('dashboard', ['v' => $sv['id']]) ?>" class="<?= $on ? 'on' : '' ?>"><?= in_array($sv['kind'], ['scooter', 'motorcycle'], true) ? '🛵' : '🚗' ?> <?= h(($sv['nickname'] ?: $sv['model']) ?: $sv['make']) ?></a>
    <?php endforeach; ?>
    <a href="<?= url('garage') ?>#add" class="add">＋ Add</a>
  </div>
  <div class="menu-list">
    <?php foreach (array_merge([['issues', 'Issues', '⚠'], ['garage', 'Garage', '⌂']], array_filter($more, fn($x) => $x[0] !== 'issues')) as [$p, $label, $ico]): ?>
      <a href="<?= url($p) ?>" class="<?= $page === $p ? 'on' : '' ?>"><span><?= $ico ?></span><?= h($label) ?><?= $p === 'recalls' && $recallCount ? ' <i class="pill">' . $recallCount . '</i>' : '' ?><?= $p === 'issues' && $openCount ? ' <i class="pill">' . $openCount . '</i>' : '' ?></a>
    <?php endforeach; ?>
  </div>
  <form method="post" class="mode-switch" style="margin-bottom:12px"><input type="hidden" name="action" value="mode_set"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
    <button name="mode" value="diy" class="<?= is_pro() ? '' : 'on' ?>">DIY mode</button><button name="mode" value="pro" class="<?= is_pro() ? 'on' : '' ?>">Mechanic mode</button></form>
  <div class="install-tip" data-install-tip hidden>
    <b>Put it on your home screen</b>
    <span data-ios-tip hidden>Tap <b>Share</b> <span aria-hidden="true">⎋</span> then <b>Add to Home Screen</b>. It opens like an app.</span>
    <button type="button" class="primary sm" data-install hidden>Install the app</button>
  </div>
  <?php if (MULTI_USER || (string)(col("SELECT value FROM settings WHERE key='password_hash'") ?: '') !== ''): ?>
  <form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><button type="submit" class="sm ghost" style="width:100%">Log out</button></form>
  <?php endif; ?>
  <button type="button" class="sheet-close ghost" data-sheet-close>Close</button>
</dialog>
<?php endif; ?>
<div id="lightbox" class="lightbox" hidden><img alt=""><button type="button" aria-label="Close">×</button></div>
<script src="assets/app.js?v=6"></script>
<?php if ($use3d): ?><script type="module" src="assets/car3d.js?v=9"></script><?php endif; ?>
<?php if (!empty($useStage)): ?><script type="module" src="assets/showroom.js?v=4"></script><?php endif; ?>
</body>
</html>
