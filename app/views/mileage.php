<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'Mileage log';
$log = rows('SELECT * FROM mileage_log WHERE ' . vw() . ' ORDER BY logged_at DESC, id DESC');
$asc = array_reverse($log);
?>
<div class="head"><div><h1>Mileage log</h1><p>Update weekly. Maintenance countdowns and the dashboard rate use this.</p></div></div>
<div class="grid g3">
  <div class="card" style="grid-column:span 2">
    <?php if (count($asc) >= 2): $xs = array_map(fn($r) => strtotime($r['logged_at']), $asc); $ys = array_map(fn($r) => (int)$r['miles'], $asc); $minX = min($xs); $maxX = max($xs); $minY = min($ys); $maxY = max($ys); $pts = []; foreach ($asc as $k => $r) { $x = $maxX > $minX ? ($xs[$k] - $minX) / ($maxX - $minX) * 580 + 10 : 300; $y = $maxY > $minY ? 150 - ($ys[$k] - $minY) / ($maxY - $minY) * 130 : 85; $pts[] = [round($x, 1), round($y, 1)]; } ?>
      <svg viewBox="0 0 600 170" style="width:100%;height:200px"><polyline fill="none" stroke="#e10600" stroke-width="2" points="<?= implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts)) ?>"/><?php foreach ($pts as $p): ?><circle cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="3" fill="#fff"/><?php endforeach; ?><text x="10" y="165" fill="#8d95a9" font-size="10"><?= fmt_date($asc[0]['logged_at']) ?> · <?= number_format($minY) ?></text><text x="590" y="12" fill="#8d95a9" font-size="10" text-anchor="end"><?= fmt_date(end($asc)['logged_at']) ?> · <?= number_format($maxY) ?></text></svg>
    <?php else: ?><div class="empty">Add a second reading to see the trend.</div><?php endif; ?>
    <table><thead><tr><th>Date</th><th>Miles</th><th>Δ</th><th>Note</th><th></th></tr></thead><tbody>
    <?php foreach ($log as $k => $r): $prev = $log[$k + 1] ?? null; ?><tr><td><?= fmt_date($r['logged_at']) ?></td><td class="num"><?= number_format((int)$r['miles']) ?></td><td class="num muted"><?= $prev ? '+' . number_format((int)$r['miles'] - (int)$prev['miles']) : '' ?></td><td><small><?= h($r['note']) ?></small></td><td><form method="post"><input type="hidden" name="action" value="mileage_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="sm ghost dim" style="border:0" data-confirm="Remove this reading?">×</button></form></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <div class="card">
    <h2>Add reading</h2>
    <form method="post"><input type="hidden" name="action" value="update_miles"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="back" value="<?= url('mileage') ?>">
      <label>Odometer</label><input type="number" name="miles" value="<?= $miles ?>" required>
      <label>Date</label><input type="date" name="logged_at" value="<?= today() ?>">
      <label>Note</label><input type="text" name="note" placeholder="optional">
      <label class="inline" style="margin-top:8px"><input type="checkbox" name="force" value="1"> Allow a lower number (correcting a mistake)</label>
      <div class="row end" style="margin-top:10px"><button class="primary" type="submit">Save</button></div></form>
  </div>
</div>
