<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'My tools';
$tools = rows('SELECT * FROM tool_links WHERE user_id=? ORDER BY grp, sort, name', [uid()]);
$groups = [];
foreach ($tools as $t) $groups[$t['grp']][] = $t;
$have = array_column($tools, 'url');
?>
<div class="head"><div><h1>My tools</h1><p>The scanners and repair-information services you already pay for, one tap away and grouped by brand. Each opens in its own window next to this app; you sign in there as usual (your browser or password manager remembers you). We never see or store those passwords.</p></div></div>
<div class="grid g3">
  <div style="grid-column:span 2">
    <?php if (!$groups): ?><div class="card empty">No tools yet. Pick the ones you use on the right, or add your own.</div><?php endif; ?>
    <?php foreach ($groups as $g => $items): ?>
      <div class="card" style="margin-bottom:14px"><h2><?= h($g) ?></h2>
        <div class="tool-grid"><?php foreach ($items as $t): ?>
          <div class="tool"><button type="button" class="tool-open" data-tool-url="<?= h($t['url']) ?>" data-tool-id="<?= (int)$t['id'] ?>"><b><?= h($t['name']) ?></b><small><?= h(parse_url($t['url'], PHP_URL_HOST) ?: '') ?></small></button>
            <form method="post"><input type="hidden" name="action" value="tool_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="sm ghost dim" title="Remove" data-confirm="Remove <?= h($t['name']) ?> from your tools?">×</button></form></div>
        <?php endforeach; ?></div></div>
    <?php endforeach; ?>
    <div class="card">
      <h2>Bring results back here</h2>
      <ol style="padding-left:1.2em">
        <li><b>Report or screenshot:</b> export the scan as PDF/CSV, or screenshot the codes/live-data screen, then upload it on <a href="<?= url('diagnose') ?>">Diagnose</a>. Codes and freeze-frame values are read automatically.</li>
        <li><b>On a phone:</b> install this app to the home screen, then in your scanner app use <b>Share → <?= h(APP_NAME) ?></b> to send the report straight in.</li>
        <li><b>Copy &amp; paste:</b> select the codes or data in the other tool, paste into Diagnose.</li>
      </ol>
      <p class="muted" style="font-size:13px">Why not show them inside this page? Those services block being embedded in other sites, and browsers stop one site from reading another's pages. Opening them side by side keeps your logins safe with the provider.</p>
    </div>
  </div>
  <div class="aside">
    <div class="card">
      <h2>Add popular tools</h2>
      <form method="post"><input type="hidden" name="action" value="tool_preset"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <?php foreach (TOOL_PRESETS as $g => $items): ?><h3 style="margin-top:10px"><?= h($g) ?></h3>
          <?php foreach ($items as $i => [$n, $u]): ?><label class="inline"><input type="checkbox" name="pick[]" value="<?= h($g . '|' . $i) ?>" <?= in_array($u, $have, true) ? 'checked disabled' : '' ?>> <?= h($n) ?></label><?php endforeach; ?>
        <?php endforeach; ?>
        <div class="row end" style="margin-top:10px"><button class="primary sm">Add selected</button></div></form>
    </div>
    <div class="card" style="margin-top:18px">
      <h2>Add your own</h2>
      <form method="post"><input type="hidden" name="action" value="tool_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <label>Name</label><input type="text" name="name" required placeholder="e.g. My Autel cloud">
        <label>Web address</label><input type="url" name="url" required placeholder="https://">
        <label>Group / brand</label><input type="text" name="grp" placeholder="e.g. Toyota, Scanners" list="tool-groups"><datalist id="tool-groups"><?php foreach (array_unique(array_merge(array_keys(TOOL_PRESETS), array_keys($groups))) as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist>
        <div class="row end" style="margin-top:10px"><button class="sm">Save</button></div></form>
    </div>
  </div>
</div>
