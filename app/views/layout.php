<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$veh += ['id' => 0, 'year' => '', 'make' => '', 'model' => '', 'trim' => '', 'kind' => 'car', 'mileage_updated' => null];
$two = is_two_wheeler($veh);
$has = fn(string $t) => (int)col("SELECT COUNT(*) FROM $t WHERE " . vw()) > 0;
$nav = array_values(array_filter([
    ['dashboard', 'Home', '◈'],
    ['issues', 'Issues', '⚠'],
    ['maintenance', 'Maintenance', '⟳'],
    ['visits', 'Mechanic visits', '🔧'],
    ['garage', 'Garage', '⌂'],
    ['inspections', 'Weekly checks', '☑'],
    $two ? null : ['codes', 'OBD codes', '⌁'],
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
$f = flash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h(trim(($veh['year'] ?? '') . ' ' . ($veh['model'] ?? '')) ?: 'Garage') ?></title>
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="icon" type="image/png" href="assets/favicon.png">
<meta name="theme-color" content="#07080a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=11">
<?php if ($use3d || !empty($useStage)): ?>
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"}}</script>
<?php endif; ?>
</head>
<?php $ul = upload_limits(); ?>
<body data-max-files="<?= $ul['files'] ?>" data-max-file-bytes="<?= $ul['file_bytes'] ?>" data-max-total-bytes="<?= $ul['total_bytes'] ?>">
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
      <?php $navLink = function ($p, $label, $ico) use ($page, $openCount) {
          $active = $page === $p || ($p === 'issues' && in_array($page, ['issue','issue_form'])) || ($p === 'visits' && in_array($page, ['visit','visit_form'])) || ($p === 'inspections' && in_array($page, ['inspection','inspection_new']));
          return '<a href="' . url($p) . '" class="' . ($active ? 'active' : '') . '"><span class="ico">' . $ico . '</span>' . h($label) . ($p === 'issues' && $openCount ? '<span class="pill">' . $openCount . '</span>' : '') . '</a>';
      };
      foreach ($primary as [$p, $label, $ico]) echo $navLink($p, $label, $ico);
      $moreActive = in_array($page, array_merge(array_column($more, 0), ['inspection', 'inspection_new']), true); ?>
      <details class="nav-more" <?= $moreActive ? 'open' : '' ?>><summary><span class="ico">⋯</span>More</summary>
        <?php foreach ($more as [$p, $label, $ico]) echo $navLink($p, $label, $ico); ?>
      </details>
    </nav>
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
<div id="lightbox" class="lightbox" hidden><img alt=""><button type="button" aria-label="Close">×</button></div>
<script src="assets/app.js?v=4"></script>
<?php if ($use3d): ?><script type="module" src="assets/car3d.js?v=9"></script><?php endif; ?>
<?php if (!empty($useStage)): ?><script type="module" src="assets/showroom.js?v=2"></script><?php endif; ?>
</body>
</html>
