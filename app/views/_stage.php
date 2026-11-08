<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
/**
 * "Showroom" hero for a vehicle without a 3D model: the owner's photo (or a stand-in photo / drawing) on a lit stage
 * that tilts with the pointer or the phone, a floor reflection, spec chips and issue markers.
 * Expects $veh, $miles, $open, $byLoc from dashboard.php.
 */
$kind = $veh['kind'] ?: 'car';
$hasPhoto = !empty($veh['photo']);
$stock = $hasPhoto && !empty($veh['photo_credit']);
$cut = !$stock && !empty($veh['hero_cutout']) && !empty($veh['hero_depth']);
$visionOn = VISION_URL !== '';
$vstate = str_starts_with((string)$veh['vision_status'], 'pending') ? 'pending' : (string)$veh['vision_status'];
$useStage = true;
$pts = art_points($kind);
$chips = array_filter([
    $veh['engine'] ?? '', $veh['transmission'] ?? '', number_format($miles) . ' mi', $veh['color'] ?? '',
]);
?>
<div class="hero stage-hero kind-<?= h($kind) ?><?= $cut ? ' is-cutout' : '' ?>" data-stage data-csrf="<?= csrf() ?>" data-vision="<?= h($vstate) ?>" data-mesh="<?= h((string)$veh['mesh_status']) ?>" data-glb="<?= $veh['mesh_glb'] ? h(photo_url($veh['mesh_glb'])) : '' ?>">
  <div class="stage-bg"<?= $hasPhoto ? ' style="background-image:url(\'' . h(photo_url($veh['photo'])) . '\')"' : '' ?>></div>
  <div class="stage-sweep"></div>
  <div class="stage-floor"></div>
  <div class="stage-ring"></div>
  <div class="stage-car" data-tilt>
    <div class="stage-inner">
      <?php if ($cut): ?>
        <canvas class="stage-3dphoto" data-showroom data-cutout="<?= h(photo_url($veh['hero_cutout'])) ?>" data-depth="<?= h(photo_url($veh['hero_depth'])) ?>" hidden></canvas>
        <img class="stage-cutout" data-showroom-fallback src="<?= h(photo_url($veh['hero_cutout'])) ?>" alt="<?= h(vehicle_name($veh)) ?>">
      <?php elseif ($hasPhoto): ?>
        <img class="stage-photo" src="<?= h(photo_url($veh['photo'])) ?>" alt="<?= h(vehicle_name($veh)) ?>">
      <?php else: ?>
        <?= vehicle_art_svg($kind, $veh['color'] ?? '') ?>
        <?php foreach ($byLoc as $b): $pt = $pts[$b['loc']] ?? $pts['other']; ?>
          <a class="stage-pin sev-<?= (int)$b['sev'] ?>" style="left:<?= $pt[0] ?>%;top:<?= $pt[1] ?>%" href="<?= count($b['items']) === 1 ? url('issue', ['id' => $b['items'][0]['id']]) : url('issues', ['loc' => $b['loc']]) ?>" title="<?= h($b['label'] . ': ' . implode(' · ', array_column($b['items'], 'title'))) ?>"><span><?= (int)$b['count'] ?></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="stage-reflect" aria-hidden="true"><?= $cut ? '<img class="cut" src="' . h(photo_url($veh['hero_cutout'])) . '" alt="">' : ($hasPhoto ? '<img src="' . h(photo_url($veh['photo'])) . '" alt="">' : vehicle_art_svg($kind, $veh['color'] ?? '')) ?></div>
  </div>
  <div class="overlay">
    <h1><?= h(vehicle_name($veh)) ?></h1>
    <p><?= h($veh['trim'] ?: (VEHICLE_KINDS[$kind] ?? '')) ?> · <?= number_format($miles) ?> mi · <?= count($open) ?> open issue<?= count($open) === 1 ? '' : 's' ?></p>
    <div class="stage-chips"><?php foreach ($chips as $c): ?><span><?= h($c) ?></span><?php endforeach; ?></div>
  </div>
  <div class="controls no-print">
    <?php if ($visionOn && VISION_MESH && $hasPhoto && !$stock): ?><button type="button" class="btn" data-mesh><?= $veh['mesh_status'] === 'ready' ? 'View in 3D (beta)' : '3D model (beta)' ?></button><?php endif; ?>
    <a class="btn" href="<?= url('vehicle') ?>#photo"><?= $hasPhoto && !$stock ? 'Change photo' : 'Add your photo' ?></a>
    <a class="btn" href="<?= url('garage') ?>">Garage</a>
  </div>
  <?php if ($hasPhoto && $open): ?>
  <div class="stage-issues"><?php foreach (array_slice($open, 0, 4) as $i): ?><a href="<?= url('issue', ['id' => $i['id']]) ?>"><i class="dot sev-<?= (int)$i['severity'] ?>"></i><?= h($i['title']) ?></a><?php endforeach; ?></div>
  <?php endif; ?>
  <div class="stage-note" data-vision-note><?= $stock ? h($veh['photo_credit']) : ($vstate === 'pending' ? 'Making your showroom photo…' : ($hasPhoto ? ($visionOn && !$cut ? 'Tip: Vehicle &amp; settings → "Make showroom photo" cuts your vehicle out of the background' : '') : 'Drawing stand-in · add a photo of yours on Vehicle &amp; settings')) ?></div>
</div>
