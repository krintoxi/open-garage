<?php
if (!defined('APP_ROOT')) { http_response_code(403); exit('Forbidden'); }
$title = 'OBD codes';
$qs = strtoupper(trim((string)($_GET['q'] ?? ''))); $sys = $_GET['sys'] ?? '';
$where = []; $params = [];
if ($qs !== '') { $where[] = '(code LIKE ? OR title LIKE ? OR c300_note LIKE ? OR causes LIKE ?)'; $params = ["%$qs%", "%$qs%", "%$qs%", "%$qs%"]; }
if ($sys) { $where[] = 'system=?'; $params[] = $sys; }
$codes = rows('SELECT * FROM obd_codes' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY code', $params);
$systems = array_column(rows('SELECT DISTINCT system FROM obd_codes ORDER BY system'), 'system');
$events = rows('SELECT e.*, c.title FROM code_events e LEFT JOIN obd_codes c ON c.code=e.code WHERE ' . vw('e') . ' ORDER BY seen_on DESC, e.id DESC');
$exact = $qs !== '' ? row('SELECT * FROM obd_codes WHERE code=?', [$qs]) : null;
$total = (int)col('SELECT COUNT(*) FROM obd_codes');
?>
<div class="head"><div><h1>OBD codes</h1><p><?= $total ?> codes in the local library, some with model-specific notes. Anything else: look it up and add it.</p></div></div>
<div class="grid g3">
  <div style="grid-column:span 2">
    <form method="get" class="search"><input type="hidden" name="p" value="codes"><input type="search" name="q" value="<?= h($qs) ?>" placeholder="Search code or words: P0442, misfire, fan, catalyst…"><select name="sys" style="width:170px"><option value="">All systems</option><?php foreach ($systems as $s): ?><option <?= $sys === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select><button type="submit">Search</button></form>
    <?php if ($qs !== '' && !$exact && preg_match('/^[PBCU]\d{4}$/', $qs)): ?>
      <div class="callout"><b><?= h($qs) ?></b> is not in the local library. <a href="https://www.obd-codes.com/<?= strtolower(h($qs)) ?>" target="_blank" rel="noopener">Look it up on OBD-Codes.com ↗</a> or <a href="https://www.google.com/search?q=<?= urlencode(trim($qs . ' ' . vehicle_name($veh))) ?>" target="_blank" rel="noopener">search with the car ↗</a>, then add it below so it is here next time.</div>
      <details><summary>Add <?= h($qs) ?> to the library</summary><form method="post"><input type="hidden" name="action" value="code_save"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><div class="form-grid"><div><label>Code</label><input type="text" name="code" value="<?= h($qs) ?>"></div><div><label>Title</label><input type="text" name="title" required></div><div><label>System</label><input type="text" name="system" value="Engine"></div><div><label>Severity</label><select name="severity"><?php foreach (SEVERITY as $k => $v): ?><option value="<?= $k ?>"><?= $k ?> · <?= $v ?></option><?php endforeach; ?></select></div><div class="full"><label>Description</label><textarea name="description" style="min-height:60px"></textarea></div><div class="full"><label>Common causes</label><textarea name="causes" style="min-height:60px"></textarea></div><div class="full"><label>Note for this car</label><input type="text" name="c300_note"></div></div><div class="row end" style="margin-top:8px"><button class="primary" type="submit">Save to library</button></div></form></details>
    <?php endif; ?>
    <div class="card">
      <?php if (!$codes): ?><div class="empty">No matches.</div><?php endif; ?>
      <?php foreach ($codes as $c): ?>
        <details id="<?= h($c['code']) ?>" <?= ($exact && $exact['code'] === $c['code']) || count($codes) <= 3 ? 'open' : '' ?>>
          <summary><span class="code"><?= h($c['code']) ?></span> &nbsp;<?= h($c['title']) ?> &nbsp;<small class="muted"><?= h($c['system']) ?></small> &nbsp;<?= sev_badge((int)$c['severity']) ?><?php if (trim((string)$c['c300_note'])): ?> <span class="st st-active">note</span><?php endif; ?></summary>
          <div class="prose" style="margin-top:8px"><p><?= h($c['description']) ?></p><p><b class="muted">Common causes:</b> <?= h($c['causes']) ?></p><?php if (trim((string)$c['c300_note'])): ?><div class="callout"><b>Model note:</b> <?= h($c['c300_note']) ?></div><?php endif; ?>
          <form method="post" class="row"><input type="hidden" name="action" value="code_event_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="code" value="<?= h($c['code']) ?>"><input type="date" name="seen_on" value="<?= today() ?>" style="width:150px"><input type="number" name="miles" value="<?= $miles ?>" style="width:120px"><input type="text" name="note" placeholder="note" style="width:220px"><button class="sm" type="submit">Log as seen today</button> <a class="btn sm ghost" href="https://www.obd-codes.com/<?= strtolower(h($c['code'])) ?>" target="_blank" rel="noopener">more ↗</a></form></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="aside">
    <div class="card">
      <h2>Code history (this car)</h2>
      <form method="post" style="margin-bottom:12px"><input type="hidden" name="action" value="code_event_add"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><div class="row"><input type="text" name="code" placeholder="P0442" style="width:100px" required><input type="date" name="seen_on" value="<?= today() ?>" style="width:140px"><select name="status" style="width:120px"><option value="active">Active</option><option value="monitoring">Monitoring</option><option value="resolved">Resolved</option></select></div><input type="text" name="note" placeholder="Freeze frame, what was happening, what was done" style="margin-top:6px"><div class="row end" style="margin-top:6px"><button class="sm primary" type="submit">Log code</button></div></form>
      <ul class="timeline"><?php foreach ($events as $e): ?><li><div class="when"><?= fmt_date($e['seen_on']) ?><?= $e['miles'] ? ' · ' . number_format((int)$e['miles']) . ' mi' : '' ?></div><div><a class="code" href="<?= url('codes', ['q' => $e['code']]) ?>"><?= h($e['code']) ?></a> <?= h($e['title'] ?? '') ?> <?= status_badge($e['status']) ?></div><?php if ($e['note']): ?><div class="muted" style="font-size:13px"><?= h($e['note']) ?></div><?php endif; ?>
        <form method="post" class="row" style="gap:4px;margin-top:4px"><input type="hidden" name="action" value="code_event_status"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $e['id'] ?>"><select name="status" class="mini"><?php foreach (['active','monitoring','resolved'] as $s): ?><option <?= $e['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><button class="sm" style="padding:2px 8px;font-size:12px">set</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="action" value="code_event_delete"><input type="hidden" name="_csrf" value="<?= csrf() ?>"><input type="hidden" name="id" value="<?= $e['id'] ?>"><button class="sm ghost dim" style="border:0;padding:0;font-size:11px" data-confirm="Remove this code event?">remove</button></form></li><?php endforeach; ?></ul>
    </div>
    <div class="card" style="margin-top:18px"><h3>Reading codes</h3><p class="muted" style="font-size:13px">A $20 Bluetooth OBD-II dongle reads the standard P-codes on any 1996+ car. Transmission, body and climate modules often need a brand-capable scan tool (e.g. iCarsoft, Autel, or the dealer tool at a shop) to show the real fault behind a generic code like P0700.</p></div>
  </div>
</div>
