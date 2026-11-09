<?php
// Renders one diagnosis. Expects $A (diag_analyze result), $V (vehicle array), optional $report (saved row),
// $public (bool: public lookup page, no saving), and the current mode (is_pro()).
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$pro = !empty($public) ? (($_GET['mode'] ?? '') === 'pro') : is_pro();
$vname = trim(($V['year'] ?? '') . ' ' . ($V['make'] ?? '') . ' ' . ($V['model'] ?? '')) ?: 'Vehicle not set';
[$dIco, $dLabel] = VERDICTS[verdict_from_drive((string)$A['drive'])]; $dLabel = $dIco . ' ' . $dLabel; $dText = OBD_DRIVE[$A['drive']][1];
$diffLbl = [1 => 'Easy DIY', 2 => 'DIY with basic tools', 3 => 'Experienced DIY / shop', 4 => 'Shop job', 5 => 'Specialist'];
$money = fn(array $c) => $c[1] <= 0 ? 'No cost' : '$' . number_format($c[0]) . ' – $' . number_format($c[1]);
$recalls = [];
if (!empty($V['id'])) $recalls = rows('SELECT * FROM recalls WHERE vehicle_id=? ORDER BY status, report_date DESC', [(int)$V['id']]);
elseif (!empty($V['make']) && !empty($V['year'])) {
    $ck = 'recalls:' . (int)$V['year'] . ':' . strtoupper((string)$V['make']) . ':' . strtoupper((string)$V['model']);
    $c = row('SELECT body, fetched_at FROM vpic_cache WHERE key=?', [$ck]);
    if ($c && (int)$c['fetched_at'] > time() - 7 * 86400) $list = json_decode((string)$c['body'], true);
    else { $list = recall_lookup($V); if ($list !== null) q('INSERT OR REPLACE INTO vpic_cache (key, body, fetched_at) VALUES (?,?,?)', [$ck, json_encode($list), time()]); }
    foreach ((array)$list as $r) $recalls[] = ['campaign' => $r['NHTSACampaignNumber'] ?? '', 'component' => recall_component((string)($r['Component'] ?? '')), 'summary' => $r['Summary'] ?? '', 'status' => 'open', 'remedy' => $r['Remedy'] ?? ''];
}
$usMarket = empty($V['country']) || in_array($V['country'], ['United States', 'Canada', 'Mexico'], true);
?>
<div class="diag" data-mode="<?= $pro ? 'pro' : 'diy' ?>">
  <section class="card diag-sum drive-<?= h($A['drive']) ?>">
    <div class="diag-veh"><small><?= !empty($report) ? 'Diagnosis #' . (int)$report['id'] . ' · ' . fmt_date($report['created_at']) : 'Diagnosis' ?></small><b><?= h($vname) ?></b>
      <?php if (!empty($V['engine'])): ?><span class="muted"><?= h($V['engine']) ?></span><?php endif; ?></div>
    <div class="diag-codes"><?php foreach ($A['codes'] as $c): ?><a class="code" href="#c-<?= h($c) ?>"><?= h($c) ?></a><?php endforeach; ?><?= !$A['codes'] ? '<span class="muted">No codes, symptoms only</span>' : '' ?></div>
    <div class="diag-verdict">
      <div><small>Can I drive it?</small><b class="dv"><?= h($dLabel) ?></b><span><?= h($dText) ?></span></div>
      <?php if ($A['cost']): ?><div><small>Typical repair cost (US, parts + labour)</small><b><?= h($money($A['cost'])) ?></b><span>Rough range across the likely causes. Cheapest checks first.</span></div><?php endif; ?>
    </div>
  </section>

  <?php if ($A['combos'] || $A['symptom_notes']): ?>
  <section class="card">
    <h2>What the codes say together</h2>
    <ul class="insights">
      <?php foreach ($A['symptom_notes'] as [$lvl, $t]): ?><li class="ins-<?= h($lvl) ?>"><?= h($t) ?></li><?php endforeach; ?>
      <?php foreach ($A['combos'] as [$kind, $head, $t]): ?><li class="ins-<?= $kind === 'first' ? 'warn' : 'info' ?>"><b><?= $kind === 'first' ? 'Do first: ' : '' ?><?= h($head) ?>.</b> <?= h($t) ?></li><?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php foreach ($A['items'] as $it): $k = $it['kb'] ?? []; $c = $it['code']; $dr = $k['drive'] ?? 'soon'; $yq = trim($c . ' ' . $vname . ' ' . ($pro ? 'diagnosis' : 'fix')); ?>
  <article class="card dcode" id="c-<?= h($c) ?>">
    <header>
      <span class="code big"><?= h($c) ?></span>
      <div class="grow"><h3><?= h($it['title']) ?></h3>
        <div class="badges"><?= sev_badge($it['sev']) ?> <span class="st drive-pill drive-<?= h($dr) ?>"><?= h(OBD_DRIVE[$dr][0]) ?></span>
          <span class="st"><?= $it['structure']['generic'] ? 'Generic (all makes)' : 'Manufacturer-specific' ?></span><?php if ($it['structure']['area']): ?> <span class="muted" style="font-size:12px"><?= h($it['structure']['area']) ?></span><?php endif; ?></div></div>
    </header>
    <?php foreach ($it['patterns'] as $p): ?><div class="callout pattern"><b>Known on this vehicle:</b> <?= h($p) ?></div><?php endforeach; ?>
    <div class="dtabs" role="tablist">
      <button type="button" class="<?= $pro ? '' : 'on' ?>" data-dtab="ov">Overview</button>
      <button type="button" data-dtab="diy">Fix it yourself</button>
      <button type="button" class="<?= $pro ? 'on' : '' ?>" data-dtab="pro">Mechanic</button>
      <button type="button" data-dtab="yt" data-yt="<?= h($yq) ?>">▶ Videos</button>
    </div>
    <div class="dpane" data-pane="ov" <?= $pro ? 'hidden' : '' ?>>
      <?php if ($k): ?>
        <p class="lead"><?= h($k['plain']) ?></p>
        <p><b><?= h(OBD_DRIVE[$dr][0]) ?>:</b> <?= h(OBD_DRIVE[$dr][1]) ?></p>
        <div class="dfacts"><span><small>Typical cost</small><?= h($money($k['cost'])) ?></span><span><small>Difficulty</small><?= str_repeat('●', $k['diff']) . str_repeat('○', 5 - $k['diff']) ?> <?= h($diffLbl[$k['diff']]) ?></span></div>
        <h4>Most likely causes</h4>
        <ol class="causes"><?php foreach ($k['causes'] as [$t, $w]): ?><li><?= h($t) ?> <i class="lk lk-<?= (int)$w ?>"><?= ['', 'less likely', 'likely', 'most likely'][$w] ?></i></li><?php endforeach; ?></ol>
      <?php elseif ($it['library']): ?>
        <p class="lead"><?= h($it['library']['description']) ?></p><p><b>Common causes:</b> <?= h($it['library']['causes']) ?></p>
      <?php else: ?>
        <p class="lead">This code is not in the library yet. <?= h($it['structure']['system']) ?><?= $it['structure']['area'] ? ', ' . h(mb_strtolower($it['structure']['area'])) : '' ?>. <?= $it['structure']['generic'] ? 'It is a standard code, so the definition is the same on every make.' : 'Its meaning depends on the manufacturer.' ?> Use the links and videos below.</p>
      <?php endif; ?>
      <?php if (!empty($it['library']['c300_note']) && trim((string)$it['library']['c300_note']) !== ''): ?><div class="callout"><b>Your note:</b> <?= h($it['library']['c300_note']) ?></div><?php endif; ?>
    </div>
    <div class="dpane" data-pane="diy" hidden>
      <?php if ($k): ?>
        <h4>Try these first</h4><ol><?php foreach ($k['diy'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ol>
        <h4>What to tell the shop</h4>
        <p class="tell" data-copy-text>"My <?= h($vname) ?> has code <?= h($c) ?> (<?= h(mb_strtolower($it['title'])) ?>). <?= !empty($A['freeze']) ? 'I have the freeze-frame data. ' : '' ?>Please check <?= h(mb_strtolower(implode(', then ', array_slice(array_map(fn($x) => trim(preg_replace('/[:(].*$/', '', $x[0])), $k['causes']), 0, 3)))) ?> before replacing parts, and tell me what you found."</p>
        <p class="muted" style="font-size:13px">Clearing the code does not fix anything; it comes back if the fault is still there. A free code read is available at most auto-parts stores.</p>
      <?php else: ?><p class="muted">No DIY guide for this code yet: the videos tab and the links below usually have one.</p><?php endif; ?>
    </div>
    <div class="dpane" data-pane="pro" <?= $pro ? '' : 'hidden' ?>>
      <?php if ($k): ?>
        <?php if (!empty($k['set'])): ?><p><b>Set criteria (typical):</b> <?= h($k['set']) ?></p><?php endif; ?>
        <h4>Causes, ranked</h4>
        <ul class="rank"><?php foreach ($k['causes'] as [$t, $w]): ?><li><span class="bar"><i style="width:<?= $w * 33 ?>%"></i></span><?= h($t) ?></li><?php endforeach; ?></ul>
        <h4>Test plan</h4><ol><?php foreach ($k['tests'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?></ol>
        <?php if (!empty($k['related'])): ?><p class="muted" style="font-size:13px">Often seen with: <?php foreach ($k['related'] as $rc): if ($rc === $c) continue; ?><a class="code" href="<?= h(!empty($public) ? url('lookup', ['codes' => $rc] + array_filter(['year' => $V['year'] ?? '', 'make' => $V['make'] ?? '', 'model' => $V['model'] ?? ''])) : url('diagnose', ['codes' => $rc])) ?>"><?= h($rc) ?></a> <?php endforeach; ?></p><?php endif; ?>
      <?php elseif ($it['library']): ?><p><?= h($it['library']['description']) ?></p><p><b>Causes:</b> <?= h($it['library']['causes']) ?></p>
      <?php else: ?><p class="muted">Use the manufacturer's service information for the exact definition and test plan (see My tools).</p><?php endif; ?>
    </div>
    <div class="dpane" data-pane="yt" hidden><div class="yt-grid" data-yt-out><p class="muted">Loading videos…</p></div></div>
    <div class="dlinks"><?php foreach (obd_links($c, $V) as [$lbl, $u]): ?><a href="<?= h($u) ?>" target="_blank" rel="noopener"><?= h($lbl) ?> ↗</a><?php endforeach; ?></div>
  </article>
  <?php endforeach; ?>

  <?php if ($A['freeze_notes']): ?>
  <section class="card">
    <h2>Freeze frame / live data</h2>
    <ul class="insights"><?php foreach ($A['freeze_notes'] as [$lvl, $t]): ?><li class="ins-<?= h($lvl) ?>"><?= h($t) ?></li><?php endforeach; ?></ul>
    <?php if ($pro && $A['freeze']): ?><details><summary class="muted">Values read (<?= count($A['freeze']) ?>)</summary><table class="mini-table"><?php foreach ($A['freeze'] as $kk => $vv): ?><tr><td><?= h($kk) ?></td><td class="mono"><?= h((string)$vv) ?></td></tr><?php endforeach; ?></table></details><?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="grid g2 diag-intel">
    <div class="card">
      <h2>Safety recalls</h2>
      <?php if (!$usMarket): ?><p class="muted">NHTSA lists US-market vehicles. For a vehicle sold in <?= h($V['country']) ?>, check the official recall service:</p><?php endif; ?>
      <?php if ($recalls): $open = array_filter($recalls, fn($r) => $r['status'] === 'open'); ?>
        <p><b><?= count($recalls) ?></b> recall<?= count($recalls) === 1 ? '' : 's' ?> listed for this model<?= $open ? ', ' . count($open) . ' not confirmed fixed' : '' ?>. Repairs are free at a dealer.</p>
        <ul class="list"><?php foreach (array_slice($recalls, 0, 6) as $r): ?><li><div class="grow"><span class="t"><?= h($r['component']) ?></span><small><?= h($r['campaign']) ?><?= $r['status'] !== 'open' ? ' · ' . h(RECALL_STATUSES[$r['status']] ?? '') : '' ?></small></div></li><?php endforeach; ?></ul>
      <?php elseif ($usMarket): ?><p class="muted">No recalls on record for this year, make and model (or the vehicle is not in the US database).</p><?php endif; ?>
      <p class="dlinks"><?php foreach (recall_links_world() as [$lbl, $u]): ?><a href="<?= h($u) ?>" target="_blank" rel="noopener"><?= h($lbl) ?> ↗</a><?php endforeach; ?></p>
    </div>
    <div class="card">
      <h2>What owners report</h2>
      <?php $cp = $A['complaints']; if ($cp && $cp['total']): $focus = $A['complaint_focus']; $comps = $cp['components']; uksort($comps, fn($a, $b) => (int)in_array($b, $focus, true) <=> (int)in_array($a, $focus, true) ?: $comps[$b]['n'] <=> $comps[$a]['n']); ?>
        <p class="muted"><?= number_format($cp['total']) ?> complaints filed with NHTSA for this model year. Most reported:</p>
        <?php foreach (array_slice($comps, 0, 6, true) as $name => $x): ?>
          <details class="cmp"><summary><span class="cmp-bar" style="--w:<?= min(100, round($x['n'] / max(1, reset($cp['components'])['n']) * 100)) ?>%"></span><b><?= h(ucwords(strtolower($name))) ?></b> <span class="muted"><?= (int)$x['n'] ?><?= !empty($x['serious']) ? ' · ' . (int)$x['serious'] . ' crash/fire' : '' ?></span><?= in_array($name, $focus, true) ? ' <span class="st st-soon">related</span>' : '' ?></summary>
            <?php foreach ($x['samples'] ?? [] as $s): ?><p class="quote">“<?= h($s) ?>”</p><?php endforeach; ?></details>
        <?php endforeach; ?>
      <?php else: ?><p class="muted"><?= $usMarket ? 'No owner complaints on file for this model (or it is not in the US database).' : 'Owner complaint data is only available for US-market vehicles.' ?></p><?php endif; ?>
      <p class="dlinks"><a href="https://www.nhtsa.gov/recalls" target="_blank" rel="noopener">Service bulletins (TSBs) at NHTSA ↗</a><a href="https://www.youtube.com/results?search_query=<?= urlencode($vname . ' common problems') ?>" target="_blank" rel="noopener">Common problems videos ↗</a></p>
    </div>
  </section>
</div>
<div id="ytplayer" class="ytplayer" hidden><div><button type="button" aria-label="Close">×</button><iframe title="Video" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div></div>
