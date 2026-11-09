<?php
// "What's this light?" Photo of the dashboard (read on the M4) or pick it from the list; each light gets the
// one-line "can I keep driving?" answer and the first thing to do.
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = "What's this light?";
$res = isset($_GET['scan']) ? ($_SESSION['light_scan'] ?? null) : null;
$one = isset($_GET['l'], DASH_LIGHTS[$_GET['l']]) ? (string)$_GET['l'] : '';
$show = $res && !empty($res['lights']) ? $res['lights'] : ($one ? [$one] : []);
$hasVeh = (bool)vid();
$colors = ['red' => 'Red: act now', 'amber' => 'Amber: get it checked', 'green' => 'Green: just information', 'blue' => 'Blue: just information'];
$lightCard = function (string $k) use ($hasVeh): string {
    [$name, $emo, $col, $v, $mean, $todo] = DASH_LIGHTS[$k];
    $acts = [];
    if ($k === 'check_engine') { $acts[] = '<a class="btn sm primary" href="' . url('diagnose') . '">Look up the code</a>'; $acts[] = '<a class="btn sm" href="' . url('obd') . '">🔌 Read it with a Bluetooth adapter</a>'; }
    if ($hasVeh && $v !== 'watch') { $acts[] = '<a class="btn sm" href="' . url('issue_form', ['prefill' => $name . ' light on']) . '">Note it as a problem</a>'; $acts[] = '<a class="btn sm" href="' . url('send_shop') . '">📤 Send to a shop</a>'; }
    if (in_array($k, ['airbag', 'brake', 'abs'], true) && $hasVeh) $acts[] = '<a class="btn sm ghost" href="' . url('recalls') . '">Check recalls</a>';
    return '<section class="card light-card light-' . h($col) . '"><div class="lc-head"><span class="lc-emo" aria-hidden="true">' . $emo . '</span><div><h2>' . h($name) . '</h2><small>Usually ' . h($col) . '</small></div></div>'
        . verdict_box($v, $mean, $todo, $k === 'check_engine') . ($acts ? '<div class="row" style="gap:8px;flex-wrap:wrap;margin-top:10px">' . implode('', $acts) . '</div>' : '') . '</section>';
};
?>
<div class="head"><div><h1>What's this light?</h1><p>Take a photo of your dashboard with the light on, or find it in the list. You get a straight answer: stop, book a repair, or carry on.</p></div></div>

<?php if ($res && !empty($res['error'])): ?><div class="flash flash-err"><?= h($res['error']) ?></div><?php endif; ?>
<?php if ($res && isset($res['lights']) && !$res['lights']): ?><div class="flash flash-err">No warning lights recognised in that photo. Try again with the engine running, closer and without glare, or pick it from the list below.</div><?php endif; ?>
<?php if ($show): ?>
  <?php if ($res && $res['lights']): ?><p class="muted" style="margin:0 0 10px">Read from your photo: <?= count($show) ?> light<?= count($show) === 1 ? '' : 's' ?>. Check it matches what you see.</p><?php endif; ?>
  <div class="light-list"><?php foreach ($show as $k) echo $lightCard($k); ?></div>
<?php endif; ?>

<div class="grid g3" style="margin-top:<?= $show ? 18 : 0 ?>px">
  <div class="card light-snap" style="grid-column:span 2">
    <h2>📷 Take a photo</h2>
    <?php if (VISION_URL !== ''): ?>
    <form method="post" enctype="multipart/form-data" data-busy="Reading the lights… about 20 seconds"><input type="hidden" name="action" value="light_scan"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
      <label class="btn primary light-file"><input type="file" name="photo" accept="image/*" capture="environment" required data-autosubmit hidden>Photo of my dashboard</label>
      <p class="muted" style="font-size:13px;margin:10px 0 0">Engine running, the lit light in the middle, no glare. Our own reader on our server looks at it; the photo is not kept.</p>
    </form>
    <?php else: ?><p class="muted">The photo reader is off on this server. Pick the light from the list.</p><?php endif; ?>
  </div>
  <div class="card"><h2>Colours</h2><ul class="plist"><?php foreach ($colors as $c => $t): ?><li><a><i class="pdot light-dot-<?= $c ?>"></i><span class="grow"><b><?= h($t) ?></b></span></a></li><?php endforeach; ?></ul></div>
</div>

<section class="card" style="margin-top:18px"><h2>Find it in the list</h2>
  <div class="light-grid"><?php foreach (DASH_LIGHTS as $k => [$name, $emo, $col, $v]): ?><a href="<?= url('lights', ['l' => $k]) ?>" class="light-tile light-<?= h($col) ?><?= $k === $one ? ' on' : '' ?>"><span><?= $emo ?></span><b><?= h($name) ?></b><?= verdict_pill($v) ?></a><?php endforeach; ?></div>
  <p class="muted" style="font-size:12.5px;margin-top:12px">Symbols differ a little between makes. Your owner's manual has the exact list for your car.</p>
</section>
